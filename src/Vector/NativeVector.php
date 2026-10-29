<?php
/**
 * Busca vetorial nativa do MariaDB 11.7+ (tipo VECTOR)
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Vector;

/**
 * Tabelas laterais `<tabela>_native (id, item_id, collection_id, attachment_id, embedding_vec VECTOR(n))`
 *
 * Com elas, o cosseno é calculado dentro do banco (em C) e o PHP só recebe os
 * ids mais próximos: nada de trazer os embeddings para a memória do PHP, que
 * era o limite medido em docs/ANALISE-LIMITE-VETORES.md. A busca é exata
 * (varredura no banco, sem índice).
 *
 * Tabela lateral, não coluna na tabela de vetores: lá cada linha tem ~40 KB
 * (texto, metadados, embedding em JSON) e a tabela passa do buffer do InnoDB;
 * com a coluna dentro dela, a varredura do acervo todo levou 5–8 s lendo o
 * disco. Estreita, medido no MariaDB 11.8 com 38 mil vetores de 1536
 * dimensões: ~120 ms no acervo todo e ~30 ms numa coleção de 8 mil.
 *
 * Sem VECTOR INDEX de propósito: o HNSW do MariaDB filtra depois de buscar, e
 * com filtro por coleção (o caso comum aqui) devolveu 13–46% de recall e
 * consultas de até 30 s; também precisa de ~500 MB de cache para render.
 *
 * Servidor sem VECTOR (MySQL, MariaDB < 11.7): nada muda, a busca segue nas
 * duas fases em PHP. Linhas com outra dimensão (troca de modelo) ficam fora
 * da tabela lateral; enquanto a consulta não tiver a dimensão dela, a busca
 * também usa o caminho antigo, e o cron reavalia a dimensão.
 *
 * Estado por tabela (opção STATE_OPTION_<tabela>): dim (existe ⇔ a tabela
 * lateral existe), cursor e ready do preenchimento, recheck, checked_at,
 * error_at, off. Linhas cuja cópia falhou vão para uma lista de reenvio
 * (RETRY_OPTION_<tabela>), processada pelo cron sem desligar a busca.
 */
final class NativeVector {

	/** Vetores de metadados. */
	public const VECTORS = 'vectors';

	/** Trechos de documentos. */
	public const CHUNKS = 'chunks';

	/** Cron que cria a tabela lateral e a preenche em lotes. */
	public const CRON_HOOK = 'oraculo_native_vectors';

	/** Prefixo da opção de estado (uma por tabela). */
	public const STATE_OPTION = 'oraculo_native_vectors_';

	/** Prefixo da lista de reenvio: id => tentativas. */
	private const RETRY_OPTION = 'oraculo_native_retry_';

	/** Tentativas antes de desistir de uma linha (embedding que o banco recusa). */
	private const MAX_ATTEMPTS = 5;

	/** Lote do preenchimento (linhas por INSERT ... SELECT). */
	private const BATCH = 1000;

	/** Linhas recentes usadas para achar a dimensão do modelo atual. */
	private const DIM_SAMPLE = 2000;

	/**
	 * Origem de cada tabela: tabela de origem, tabela lateral, expressão que
	 * gera o VECTOR, expressão da dimensão e do anexo. Constantes: nada aqui
	 * vem de input.
	 */
	private const SOURCES = array(
		self::VECTORS => array(
			'source'     => 'oraculo_vectors',
			'native'     => 'oraculo_vectors_native',
			'value'      => 'VEC_FromText(embedding_data)',
			'dims'       => 'JSON_LENGTH(embedding_data)',
			'attachment' => '0',
		),
		// O ChunkStore já guarda float32 little-endian, o formato do VECTOR.
		self::CHUNKS  => array(
			'source'     => 'oraculo_chunks',
			'native'     => 'oraculo_chunks_native',
			'value'      => 'embedding',
			'dims'       => 'LENGTH(embedding) / 4',
			'attachment' => 'attachment_id',
		),
	);

	/** Colunas aceitas em forget(). */
	private const FORGET_COLUMNS = array( 'id', 'item_id', 'collection_id', 'attachment_id' );

	/**
	 * O servidor de banco tem o tipo VECTOR?
	 *
	 * @return bool
	 */
	public static function supported(): bool {
		static $supported = null;

		if ( null === $supported ) {
			global $wpdb;
			$info      = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
			$supported = (bool) preg_match( '/(\d+)\.(\d+)\.\d+-MariaDB/i', $info, $m )
				&& version_compare( $m[1] . '.' . $m[2], '11.7', '>=' );

			/**
			 * Liga ou desliga a busca vetorial nativa do MariaDB.
			 *
			 * @param bool $supported Padrão: true em MariaDB 11.7+.
			 */
			$supported = (bool) apply_filters( 'oraculo_tainacan_native_vectors', $supported );
		}

		return $supported;
	}

	/**
	 * Agenda o cron se alguma tabela tem trabalho pendente (no init; barato)
	 */
	public static function maybe_schedule(): void {
		if ( ! self::supported() ) {
			return;
		}

		foreach ( array_keys( self::SOURCES ) as $kind ) {
			if ( self::has_work( $kind ) ) {
				self::schedule();
				return;
			}
		}
	}

	/**
	 * Agenda uma rodada do cron (no-op se já agendada)
	 *
	 * @param int $delay Segundos.
	 */
	public static function schedule( int $delay = 10 ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}

	/**
	 * Cron: cria/ajusta a tabela lateral e preenche um lote de cada tabela
	 */
	public static function run_cron(): void {
		self::run( 20 );
		foreach ( array_keys( self::SOURCES ) as $kind ) {
			if ( self::has_work( $kind ) ) {
				self::schedule( 5 );
				return;
			}
		}
	}

	/**
	 * Cria/ajusta as tabelas laterais e preenche até o prazo
	 *
	 * @param int $seconds Prazo (0 = sem limite, para o WP-CLI).
	 * @return bool Todas as tabelas prontas.
	 */
	public static function run( int $seconds ): bool {
		if ( ! self::supported() ) {
			return true;
		}

		// Um processo por vez; lock vencido (processo que morreu) sai em 10 min.
		$lock = self::STATE_OPTION . 'lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock, 0 ) > 10 * MINUTE_IN_SECONDS ) {
				delete_option( $lock );
			}
			return false;
		}

		$deadline = $seconds > 0 ? microtime( true ) + $seconds : 0.0;
		$done     = true;
		foreach ( array_keys( self::SOURCES ) as $kind ) {
			$done = self::step( $kind, $deadline ) && $done;
		}

		delete_option( $lock );
		return $done;
	}

	/**
	 * Uma tabela: lateral na dimensão do modelo atual, reenvios e preenchimento
	 *
	 * @param string $kind     self::VECTORS | self::CHUNKS.
	 * @param float  $deadline microtime limite (0 = sem limite).
	 * @return bool Pronta (ou nada a fazer).
	 */
	private static function step( string $kind, float $deadline ): bool {
		global $wpdb;

		$src    = self::SOURCES[ $kind ];
		$source = $wpdb->prefix . $src['source'];
		$native = $wpdb->prefix . $src['native'];
		$cur    = self::state( $kind );

		if ( ! empty( $cur['off'] ) ) {
			return true;
		}
		// CREATE recusado (sem privilégio, servidor sem VECTOR): uma tentativa por hora.
		if ( time() - (int) ( $cur['error_at'] ?? 0 ) < HOUR_IN_SECONDS ) {
			return true;
		}

		if ( ! self::table_exists( $source ) ) {
			// Pronta, sem dimensão: a primeira gravação (sync) agenda de novo.
			self::save( $kind, array( 'ready' => true ) );
			return true;
		}

		$dim = self::column_dim( $native );

		if ( 0 === $dim || ! empty( $cur['recheck'] ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin's own tables ($wpdb->prefix . class constant) and SQL expressions from the SOURCES constant; prepare() cannot bind DDL: VECTOR(n) takes the dimension as part of the column type, an int read from the table itself.
			// Amostra das linhas mais recentes: é a dimensão do modelo em uso,
			// e não lê o embedding da tabela inteira.
			$wanted = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT d FROM (SELECT {$src['dims']} AS d FROM {$source} ORDER BY id DESC LIMIT %d) AS r GROUP BY d ORDER BY COUNT(*) DESC LIMIT 1", self::DIM_SAMPLE )
			);

			if ( $wanted <= 0 ) {
				// Origem vazia: esvazia a lateral, se houver, e guarda a
				// dimensão dela para as próximas gravações (sync) copiarem.
				if ( $dim > 0 ) {
					$wpdb->query( "DELETE FROM {$native}" );
				}
				self::save(
					$kind,
					array(
						'ready'      => true,
						'dim'        => $dim,
						'checked_at' => time(),
					)
				);
				self::clear_retry( $kind );
				return true;
			}

			if ( $dim !== $wanted ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$native}" );
				$wpdb->query(
					sprintf(
						'CREATE TABLE %s (
							id BIGINT UNSIGNED NOT NULL,
							item_id BIGINT UNSIGNED NOT NULL,
							collection_id BIGINT UNSIGNED NOT NULL,
							attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
							embedding_vec VECTOR(%d) NOT NULL,
							PRIMARY KEY (id),
							KEY idx_collection (collection_id),
							KEY idx_item (item_id),
							KEY idx_attachment (attachment_id)
						) %s',
						$native,
						$wanted,
						$wpdb->get_charset_collate()
					)
				);
				$dim = self::column_dim( $native );
				if ( $dim !== $wanted ) {
					self::save( $kind, array( 'error_at' => time() ) );
					return false;
				}
				$cur = array( 'cursor' => 0 );
				self::clear_retry( $kind );
			}
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

			$cur['dim']        = $dim;
			$cur['recheck']    = false;
			$cur['checked_at'] = time();
			$cur['error_at']   = 0;
			$cur['retry']      = ! empty( self::state( $kind )['retry'] );
			// Gravado já: um recheck pedido daqui em diante é novo e vale.
			self::save( $kind, $cur );
		}

		// Sem recheck o bloco acima não roda: a dimensão vem da tabela que existe
		// (estado apagado não pode deixar a busca sem dimensão para sempre).
		$cur['dim'] = $dim;

		self::process_retry( $kind, $dim );

		if ( ! empty( $cur['prune'] ) ) {
			self::prune( $kind );
			$cur['prune'] = false;
			self::save( $kind, $cur ); // Já: save_progress() traria a marca de volta.
		}

		$cursor = (int) ( $cur['cursor'] ?? 0 );
		while ( empty( $cur['ready'] ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; values via placeholders; write path in cron.
			$upto = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT MAX(id) FROM (SELECT id FROM {$source} WHERE id > %d ORDER BY id LIMIT %d) AS r", $cursor, self::BATCH )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $upto <= $cursor ) {
				// Passada completa: o que sobrou na lateral sem origem (remoção
				// feita enquanto ela era refeita) sai agora.
				self::prune( $kind );
				$cur['ready'] = true;
				break;
			}

			if ( false === self::fill( $kind, $dim, 'id > %d AND id <= %d', array( $cursor, $upto ) ) ) {
				// Lote recusado: as linhas vão para o reenvio, uma a uma, e o
				// preenchimento segue.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; values via placeholders; write path in cron.
				self::add_retry( $kind, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$source} WHERE id > %d AND id <= %d", $cursor, $upto ) ) ) );
			}

			$cursor        = $upto;
			$cur['cursor'] = $cursor;
			self::save_progress( $kind, $cur );

			if ( $deadline > 0 && microtime( true ) > $deadline ) {
				break;
			}
		}

		self::save_progress( $kind, $cur );
		return ! empty( $cur['ready'] ) && empty( self::retry( $kind ) );
	}

	/**
	 * Copia para a tabela lateral as linhas gravadas agora (upsert, insert)
	 *
	 * Apaga antes: um item reindexado não pode manter o vetor antigo, nem um
	 * vetor de outra dimensão ficar com o anterior. Fora de transação de
	 * propósito: a lateral é um cache reconstruível, e um deadlock aqui dentro
	 * de uma transação desfaria também a gravação na origem. Falha vai para o
	 * reenvio; remoção que falhar deixa órfã, que a próxima passada apaga.
	 *
	 * @param string $kind self::VECTORS | self::CHUNKS.
	 * @param int[]  $ids  IDs da tabela de origem.
	 */
	public static function sync( string $kind, array $ids ): void {
		$cur = self::active( $kind );
		if ( null === $cur ) {
			return;
		}
		if ( empty( $cur['dim'] ) ) {
			self::schedule(); // Tabela lateral ainda não existe (origem estava vazia).
			return;
		}

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			self::forget( $kind, array( 'id' => $chunk ) );
			if ( false === self::fill( $kind, (int) $cur['dim'], 'id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', $chunk ) ) {
				// Falha passageira (lock, deadlock): o cron reenvia só estas
				// linhas; a busca nativa continua ligada.
				self::add_retry( $kind, $chunk );
				self::schedule();
			}
		}
	}

	/**
	 * Apaga linhas da tabela lateral (espelha as remoções da origem)
	 *
	 * @param string                   $kind  self::VECTORS | self::CHUNKS.
	 * @param array<string, int|int[]> $where Coluna => valor ou lista; vazio = tudo.
	 */
	public static function forget( string $kind, array $where = array() ): void {
		global $wpdb;

		$cur = self::active( $kind );
		if ( null === $cur || empty( $cur['dim'] ) ) {
			return;
		}

		$parts  = array();
		$values = array();
		foreach ( $where as $column => $value ) {
			if ( ! in_array( $column, self::FORGET_COLUMNS, true ) ) {
				return; // Coluna fora da lista: não apaga nada por engano.
			}
			$list = array_values( array_map( 'intval', (array) $value ) );
			if ( empty( $list ) ) {
				return;
			}
			$parts[] = $column . ' IN (' . implode( ',', array_fill( 0, count( $list ), '%d' ) ) . ')';
			$values  = array_merge( $values, $list );
		}

		$native = $wpdb->prefix . self::SOURCES[ $kind ]['native'];
		$sql    = "DELETE FROM {$native}" . ( $parts ? ' WHERE ' . implode( ' AND ', $parts ) : '' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table ($wpdb->prefix . class constant); columns from the FORGET_COLUMNS allowlist, values as %d placeholders built with array_fill; the unconditioned DELETE takes no input; write path.
		$deleted = $wpdb->query( $values ? $wpdb->prepare( $sql, $values ) : $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $deleted ) {
			// Linhas sem origem ocupariam vagas do LIMIT na busca: o cron as
			// apaga na próxima rodada.
			$cur['prune'] = true;
			self::save( $kind, $cur );
			self::schedule();
		}

		if ( empty( $where ) ) {
			self::clear_retry( $kind );
		}
	}

	/**
	 * Apaga da tabela lateral as linhas que não existem mais na origem
	 *
	 * Para remoções em massa sem lista de ids (limpeza por data) e para o fim
	 * de cada preenchimento completo.
	 *
	 * @param string $kind self::VECTORS | self::CHUNKS.
	 */
	public static function prune( string $kind ): void {
		global $wpdb;

		$cur = self::active( $kind );
		if ( null === $cur || empty( $cur['dim'] ) ) {
			return;
		}

		$src = self::SOURCES[ $kind ];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own tables ($wpdb->prefix . class constant); no input; write path.
		$wpdb->query( "DELETE n FROM {$wpdb->prefix}{$src['native']} n LEFT JOIN {$wpdb->prefix}{$src['source']} s ON s.id = n.id WHERE s.id IS NULL" );
	}

	/**
	 * Busca os ids mais próximos dentro do banco
	 *
	 * @param string $kind            self::VECTORS | self::CHUNKS.
	 * @param array  $query_embedding Vetor da consulta.
	 * @param int[]  $collection_ids  Coleções (vazio = todas).
	 * @param int    $limit           Máximo de resultados.
	 * @param float  $threshold       Similaridade mínima.
	 * @return array<int, float>|null id => similaridade, ou null quando a busca nativa não se aplica.
	 */
	public static function search( string $kind, array $query_embedding, array $collection_ids, int $limit, float $threshold ): ?array {
		global $wpdb;

		$state = self::active( $kind );
		if ( null === $state || empty( $state['ready'] ) || empty( $state['dim'] ) ) {
			return null;
		}
		if ( count( $query_embedding ) !== (int) $state['dim'] ) {
			// Modelo trocado: a tabela lateral pode estar na dimensão antiga.
			self::request_recheck( $kind, $state );
			return null;
		}

		$query_text = wp_json_encode( array_map( 'floatval', array_values( $query_embedding ) ) );
		if ( false === $query_text ) {
			return null; // NAN/INF no vetor da consulta.
		}

		$collection_ids = array_values( array_filter( array_map( 'intval', $collection_ids ) ) );
		$collection_sql = empty( $collection_ids )
			? ''
			: 'WHERE collection_id IN (' . implode( ',', array_fill( 0, count( $collection_ids ), '%d' ) ) . ') ';
		$native         = $wpdb->prefix . self::SOURCES[ $kind ]['native'];

		// Erro aqui não vai para a tela: o caminho antigo responde.
		$suppress = $wpdb->suppress_errors( true );

		// O vetor da consulta é convertido uma vez, numa variável de sessão:
		// VEC_FromText() dentro da expressão é reavaliado a cada linha
		// (medido: +1,8 s em 8 mil linhas).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Session variable for the next query; no WP API.
		$ok = false !== $wpdb->query( $wpdb->prepare( 'SET @oraculo_q = VEC_FromText(%s)', $query_text ) );

		$rows = array();
		if ( $ok ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table ($wpdb->prefix . class constant); IN-clause built with array_fill of %d placeholders matching $collection_ids count, merged with the limit into one replacements array; result depends on the query embedding, so there is no stable cache key.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, 1 - VEC_DISTANCE_COSINE(embedding_vec, @oraculo_q) AS s FROM {$native} {$collection_sql}ORDER BY s DESC LIMIT %d",
					array_merge( $collection_ids, array( $limit ) )
				),
				ARRAY_N
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = '' === $wpdb->last_error;
		}

		$wpdb->suppress_errors( $suppress );

		if ( ! $ok ) {
			// Tabela lateral sumiu ou mudou: o cron reavalia.
			self::request_recheck( $kind, $state );
			return null;
		}

		$top = array();
		foreach ( (array) $rows as $row ) {
			$similarity = (float) $row[1];
			if ( $similarity >= $threshold ) {
				$top[ (int) $row[0] ] = $similarity;
			}
		}
		return $top;
	}

	/**
	 * Remove as tabelas laterais (WP-CLI), para levar o banco a MySQL ou MariaDB < 11.7
	 *
	 * Desliga também a busca nativa até o próximo `sync`/`rebuild`.
	 *
	 * @return int Tabelas removidas.
	 */
	public static function drop(): int {
		global $wpdb;

		$dropped = 0;
		foreach ( self::SOURCES as $kind => $src ) {
			$native = $wpdb->prefix . $src['native'];
			if ( self::table_exists( $native ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin's own table ($wpdb->prefix . class constant); DDL takes no placeholders; explicit admin action from WP-CLI.
				if ( false !== $wpdb->query( "DROP TABLE {$native}" ) ) {
					++$dropped;
				}
			}
			// "off": nem o cron nem as gravações recriam a tabela até um
			// `sync`/`rebuild` explícito (reset()).
			self::save(
				$kind,
				array(
					'ready' => true,
					'off'   => true,
				)
			);
			self::clear_retry( $kind );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		return $dropped;
	}

	/**
	 * Refaz do zero (WP-CLI): reavalia a dimensão e copia tudo de novo
	 *
	 * Mantém a dimensão (a tabela lateral continua lá e recebendo as remoções)
	 * até o cron decidir; a busca nativa fica desligada durante a nova cópia.
	 */
	public static function reset(): void {
		foreach ( array_keys( self::SOURCES ) as $kind ) {
			$cur = self::state( $kind );
			self::save(
				$kind,
				array(
					'dim'     => empty( $cur['off'] ) ? (int) ( $cur['dim'] ?? 0 ) : 0,
					'cursor'  => 0,
					'recheck' => true,
				)
			);
		}
	}

	/**
	 * Situação para o WP-CLI
	 *
	 * @return array<string, array{dim: int, ready: bool, cursor: int, off: bool, retry: int}>
	 */
	public static function status(): array {
		$out = array();
		foreach ( array_keys( self::SOURCES ) as $kind ) {
			$cur          = self::state( $kind );
			$out[ $kind ] = array(
				'dim'    => (int) ( $cur['dim'] ?? 0 ),
				'ready'  => ! empty( $cur['ready'] ),
				'cursor' => (int) ( $cur['cursor'] ?? 0 ),
				'off'    => ! empty( $cur['off'] ),
				'retry'  => count( self::retry( $kind ) ),
			);
		}
		return $out;
	}

	/**
	 * Estado da tabela quando a busca nativa está ligada
	 *
	 * @param string $kind Tabela.
	 * @return array|null Null: servidor sem VECTOR ou desligada pelo WP-CLI.
	 */
	private static function active( string $kind ): ?array {
		if ( ! self::supported() ) {
			return null;
		}
		$cur = self::state( $kind );
		return empty( $cur['off'] ) ? $cur : null;
	}

	/**
	 * A tabela tem trabalho para o cron?
	 *
	 * @param string $kind Tabela.
	 * @return bool
	 */
	private static function has_work( string $kind ): bool {
		$cur = self::state( $kind );
		if ( ! empty( $cur['off'] ) || time() - (int) ( $cur['error_at'] ?? 0 ) < HOUR_IN_SECONDS ) {
			return false;
		}
		// "retry" é só a marca (autoload); a lista fica numa opção à parte.
		return empty( $cur['ready'] ) || ! empty( $cur['recheck'] ) || ! empty( $cur['retry'] ) || ! empty( $cur['prune'] );
	}

	/**
	 * Pede ao cron que reavalie a tabela lateral, no máximo uma vez por hora
	 *
	 * Sem o limite, cada busca durante uma troca de modelo agendaria o cron.
	 *
	 * @param string $kind  Tabela.
	 * @param array  $state Estado atual da tabela.
	 */
	private static function request_recheck( string $kind, array $state ): void {
		if ( ! empty( $state['recheck'] ) || time() - (int) ( $state['checked_at'] ?? 0 ) < HOUR_IN_SECONDS ) {
			return;
		}
		$state['recheck'] = true;
		self::save( $kind, $state );
		self::schedule();
	}

	/**
	 * Copia um conjunto de linhas da origem para a tabela lateral
	 *
	 * @param string $kind   Tabela.
	 * @param int    $dim    Dimensão da tabela lateral.
	 * @param string $where  Condição sobre a origem, com placeholders %d.
	 * @param int[]  $values Valores da condição.
	 * @return int|false Linhas afetadas, ou false se o banco recusou.
	 */
	private static function fill( string $kind, int $dim, string $where, array $values ) {
		global $wpdb;

		$src    = self::SOURCES[ $kind ];
		$source = $wpdb->prefix . $src['source'];
		$native = $wpdb->prefix . $src['native'];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own tables and SQL expressions from the SOURCES constant; $where is built by this class with %d placeholders only; write path.
		return $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$native} (id, item_id, collection_id, attachment_id, embedding_vec)
				SELECT id, item_id, collection_id, {$src['attachment']}, {$src['value']} FROM {$source} WHERE {$where} AND {$src['dims']} = %d
				ON DUPLICATE KEY UPDATE item_id = VALUES(item_id), collection_id = VALUES(collection_id), attachment_id = VALUES(attachment_id), embedding_vec = VALUES(embedding_vec)",
				array_merge( $values, array( $dim ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Reenvia, uma a uma, as linhas cuja cópia falhou
	 *
	 * @param string $kind Tabela.
	 * @param int    $dim  Dimensão da tabela lateral.
	 */
	private static function process_retry( string $kind, int $dim ): void {
		$start   = self::retry( $kind );
		$pending = $start;
		if ( empty( $pending ) ) {
			return;
		}

		foreach ( $start as $id => $attempts ) {
			if ( false !== self::fill( $kind, $dim, 'id = %d', array( (int) $id ) ) || $attempts + 1 >= self::MAX_ATTEMPTS ) {
				// Copiada, ou recusada de vez (embedding ilegível): fica de
				// fora da busca nativa, como fica da antiga.
				unset( $pending[ $id ] );
			} else {
				$pending[ $id ] = $attempts + 1;
			}
		}

		// Relê antes de gravar: uma gravação pode ter acrescentado ids agora.
		foreach ( self::retry( $kind ) as $id => $attempts ) {
			if ( ! array_key_exists( $id, $start ) ) {
				$pending[ $id ] = $attempts;
			}
		}
		if ( empty( $pending ) ) {
			self::clear_retry( $kind );
		} else {
			update_option( self::RETRY_OPTION . $kind, $pending, false );
		}
	}

	/**
	 * Lista de reenvio
	 *
	 * @param string $kind Tabela.
	 * @return array<int, int> id => tentativas.
	 */
	private static function retry( string $kind ): array {
		wp_cache_delete( self::RETRY_OPTION . $kind, 'options' );
		$list = get_option( self::RETRY_OPTION . $kind, array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Acrescenta ids à lista de reenvio
	 *
	 * @param string $kind Tabela.
	 * @param int[]  $ids  IDs da origem.
	 */
	private static function add_retry( string $kind, array $ids ): void {
		if ( empty( $ids ) ) {
			return;
		}
		$list = self::retry( $kind );
		foreach ( $ids as $id ) {
			$list[ (int) $id ] = $list[ (int) $id ] ?? 0;
		}
		update_option( self::RETRY_OPTION . $kind, $list, false );
		self::set_retry_flag( $kind, true );
	}

	/**
	 * Esvazia a lista de reenvio
	 *
	 * @param string $kind Tabela.
	 */
	private static function clear_retry( string $kind ): void {
		delete_option( self::RETRY_OPTION . $kind );
		self::set_retry_flag( $kind, false );
	}

	/**
	 * Marca no estado (autoload) se há reenvio pendente
	 *
	 * @param string $kind Tabela.
	 * @param bool   $on   Pendente.
	 */
	private static function set_retry_flag( string $kind, bool $on ): void {
		$cur = self::state( $kind );
		if ( (bool) ( $cur['retry'] ?? false ) !== $on ) {
			$cur['retry'] = $on;
			self::save( $kind, $cur );
		}
	}

	/**
	 * A tabela existe?
	 *
	 * @param string $table Tabela com prefixo.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check in the cron path; no WP API.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Dimensão da coluna embedding_vec da tabela lateral (0 = não existe)
	 *
	 * @param string $table Tabela com prefixo.
	 * @return int
	 */
	private static function column_dim( string $table ): int {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table ($wpdb->prefix . class constant); schema check in the cron path.
		$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'embedding_vec' ), ARRAY_A );

		return ( is_array( $column ) && preg_match( '/^vector\((\d+)\)/i', (string) $column['Type'], $m ) ) ? (int) $m[1] : 0;
	}

	/**
	 * Estado de uma tabela
	 *
	 * @param string $kind Tabela.
	 * @return array
	 */
	private static function state( string $kind ): array {
		$state = get_option( self::STATE_OPTION . $kind, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Grava o estado de uma tabela
	 *
	 * @param string $kind  Tabela.
	 * @param array  $value Estado.
	 */
	private static function save( string $kind, array $value ): void {
		update_option( self::STATE_OPTION . $kind, $value, true );
	}

	/**
	 * Grava o progresso do cron sem apagar o que outro processo pediu
	 *
	 * Um `drop` (off) ou um recheck gravados durante a passada valem mais que
	 * a cópia em memória do cron.
	 *
	 * @param string $kind Tabela.
	 * @param array  $cur  Estado do cron.
	 */
	private static function save_progress( string $kind, array $cur ): void {
		wp_cache_delete( self::STATE_OPTION . $kind, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$fresh = self::state( $kind );
		if ( ! empty( $fresh['off'] ) ) {
			return;
		}
		if ( ! empty( $fresh['recheck'] ) ) {
			$cur['recheck'] = true;
		}
		$cur['retry'] = ! empty( $fresh['retry'] );
		if ( ! empty( $fresh['prune'] ) && ! empty( $cur['ready'] ) ) {
			$cur['prune'] = true; // Pedido durante a passada: fica para a próxima.
		}
		self::save( $kind, $cur );
	}
}
