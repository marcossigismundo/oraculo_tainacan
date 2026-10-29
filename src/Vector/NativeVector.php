<?php
/**
 * Busca vetorial nativa do MariaDB 11.7+ (coluna VECTOR)
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Vector;

/**
 * Coluna `embedding_vec VECTOR(n)` nas tabelas de vetores e de trechos
 *
 * Com ela, o cosseno é calculado dentro do banco (em C) e o PHP só recebe os
 * ids mais próximos: nada de trazer os embeddings para a memória do PHP, que
 * era o limite medido em docs/ANALISE-LIMITE-VETORES.md. A busca é exata
 * (varredura no banco, sem índice): medido no MariaDB 11.8 com 38 mil vetores
 * de 1536 dimensões, ~110 ms no acervo todo e ~30 ms numa coleção de 8 mil.
 *
 * Sem VECTOR INDEX de propósito: o HNSW do MariaDB filtra depois de buscar, e
 * com filtro por coleção (o caso comum aqui) devolveu 13–46% de recall e
 * consultas de até 30 s; também precisa de ~500 MB de cache para render.
 *
 * Servidor sem VECTOR (MySQL, MariaDB < 11.7): nada muda, a busca segue nas
 * duas fases em PHP. Linhas com outra dimensão (troca de modelo) ficam com
 * embedding_vec NULL; enquanto a consulta não tiver a dimensão da coluna, a
 * busca também usa o caminho antigo, e o cron reavalia a dimensão dominante.
 */
final class NativeVector {

	/** Tabela de vetores de metadados. */
	public const VECTORS = 'vectors';

	/** Tabela de trechos de documentos. */
	public const CHUNKS = 'chunks';

	/** Cron que cria a coluna e a preenche em lotes. */
	public const CRON_HOOK = 'oraculo_native_vectors';

	/** Estado por tabela: dim, cursor, ready, recheck. */
	public const STATE_OPTION = 'oraculo_native_vectors';

	/** Lote do preenchimento (linhas por UPDATE). */
	private const BATCH = 1000;

	/**
	 * Origem de cada tabela: sufixo, expressão que gera o VECTOR e expressão
	 * da dimensão. Constantes: nada aqui vem de input.
	 */
	private const SOURCES = array(
		self::VECTORS => array(
			'table' => 'oraculo_vectors',
			'value' => 'VEC_FromText(embedding_data)',
			'dims'  => 'JSON_LENGTH(embedding_data)',
		),
		// O ChunkStore já guarda float32 little-endian, o formato do VECTOR.
		self::CHUNKS  => array(
			'table' => 'oraculo_chunks',
			'value' => 'embedding',
			'dims'  => 'LENGTH(embedding) / 4',
		),
	);

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
	 * Agenda o cron se alguma tabela ainda não está pronta (no init; barato)
	 */
	public static function maybe_schedule(): void {
		if ( ! self::supported() ) {
			return;
		}

		$state = self::state();
		foreach ( array_keys( self::SOURCES ) as $kind ) {
			if ( empty( $state[ $kind ]['ready'] ) || ! empty( $state[ $kind ]['recheck'] ) ) {
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
	 * Cron: cria/ajusta a coluna e preenche um lote de cada tabela
	 */
	public static function run_cron(): void {
		if ( ! self::run( 20 ) ) {
			self::schedule( 5 );
		}
	}

	/**
	 * Cria/ajusta a coluna e preenche até o prazo
	 *
	 * @param int $seconds Prazo (0 = sem limite, para o WP-CLI).
	 * @return bool Todas as tabelas prontas.
	 */
	public static function run( int $seconds ): bool {
		if ( ! self::supported() ) {
			return true;
		}

		// Um processo por vez; lock vencido (processo que morreu) sai em 10 min.
		$lock = self::STATE_OPTION . '_lock';
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
	 * Uma tabela: coluna na dimensão dominante e preenchimento por faixa de id
	 *
	 * @param string $kind     self::VECTORS | self::CHUNKS.
	 * @param float  $deadline microtime limite (0 = sem limite).
	 * @return bool Pronta (ou nada a fazer).
	 */
	private static function step( string $kind, float $deadline ): bool {
		global $wpdb;

		$src   = self::SOURCES[ $kind ];
		$table = $wpdb->prefix . $src['table'];
		$state = self::state();
		$cur   = $state[ $kind ] ?? array();

		if ( ! empty( $cur['off'] ) ) {
			return true;
		}

		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check in the cron path; no WP API.
			// "Pronta, sem dimensão": o init não reagenda; a primeira gravação
			// (sync) agenda de novo.
			self::save( $kind, array( 'ready' => true ) );
			return true;
		}

		$col_dim = self::column_dim( $table );

		if ( 0 === $col_dim || ! empty( $cur['recheck'] ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin's own tables ($wpdb->prefix . class constant) and SQL expressions from the SOURCES constant; prepare() cannot bind DDL: VECTOR(n) takes the dimension as part of the column type, an int read from the table itself.
			$dominant = (int) $wpdb->get_var( "SELECT {$src['dims']} AS d FROM {$table} GROUP BY d ORDER BY COUNT(*) DESC LIMIT 1" );
			if ( $dominant <= 0 ) {
				// Tabela vazia: como acima.
				self::save( $kind, array( 'ready' => true ) );
				return true;
			}

			if ( $col_dim !== $dominant ) {
				if ( $col_dim > 0 ) {
					$wpdb->query( "ALTER TABLE {$table} DROP COLUMN embedding_vec" );
				}
				// No fim da tabela e anulável: ALGORITHM=INSTANT, sem reconstruir.
				$wpdb->query( sprintf( 'ALTER TABLE %s ADD COLUMN embedding_vec VECTOR(%d) NULL DEFAULT NULL', $table, $dominant ) );
				$col_dim = self::column_dim( $table );
				if ( $col_dim !== $dominant ) {
					return false; // ALTER recusado: tenta no próximo cron.
				}
				$cur = array( 'cursor' => 0 );
			}
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

			// A contagem lê o embedding de todas as linhas: no máximo uma vez
			// por hora (ver search()).
			$cur['checked_at'] = time();
		}

		$cur['dim']     = $col_dim;
		$cur['recheck'] = false;
		$cursor         = (int) ( $cur['cursor'] ?? 0 );

		while ( empty( $cur['ready'] ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; values via placeholders; write path in cron.
			$upto = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT MAX(id) FROM (SELECT id FROM {$table} WHERE id > %d ORDER BY id LIMIT %d) AS r", $cursor, self::BATCH )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $upto <= $cursor ) {
				$cur['ready'] = true;
				break;
			}

			// Faixa de id, não "IS NULL": linha que o banco recusar (embedding
			// ilegível) fica para trás em vez de travar o preenchimento.
			if ( false === self::fill( $kind, $col_dim, 'id > %d AND id <= %d', array( $cursor, $upto ) ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; values via placeholders; write path in cron.
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id > %d AND id <= %d", $cursor, $upto ) );
				foreach ( (array) $ids as $id ) {
					self::fill( $kind, $col_dim, 'id = %d', array( (int) $id ) );
				}
			}

			$cursor        = $upto;
			$cur['cursor'] = $cursor;
			self::save( $kind, $cur );

			if ( $deadline > 0 && microtime( true ) > $deadline ) {
				break;
			}
		}

		self::save( $kind, $cur );
		return ! empty( $cur['ready'] );
	}

	/**
	 * Recalcula embedding_vec das linhas gravadas agora (upsert, insert)
	 *
	 * Sobrescreve sempre: um item reindexado não pode manter o vetor antigo.
	 *
	 * @param string $kind self::VECTORS | self::CHUNKS.
	 * @param int[]  $ids  IDs da tabela.
	 */
	public static function sync( string $kind, array $ids ): void {
		if ( ! self::supported() ) {
			return;
		}

		$state = self::state();
		$dim   = (int) ( $state[ $kind ]['dim'] ?? 0 );
		if ( ! empty( $state[ $kind ]['off'] ) ) {
			return;
		}
		if ( $dim <= 0 ) {
			self::schedule(); // Coluna ainda não existe (tabela estava vazia).
			return;
		}

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			if ( false === self::fill( $kind, $dim, 'id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')', $chunk ) ) {
				// Falha passageira (lock, deadlock): o cron repassa a partir
				// destas linhas, senão elas ficariam fora da busca para sempre.
				$cur           = self::state()[ $kind ] ?? array();
				$cur['cursor'] = min( (int) ( $cur['cursor'] ?? 0 ), min( $chunk ) - 1 );
				$cur['ready']  = false;
				self::save( $kind, $cur );
				self::schedule();
			}
		}
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

		if ( ! self::supported() ) {
			return null;
		}

		$state = self::state()[ $kind ] ?? array();
		if ( empty( $state['ready'] ) || empty( $state['dim'] ) ) {
			return null;
		}
		if ( count( $query_embedding ) !== (int) $state['dim'] ) {
			// Modelo trocado: a coluna pode estar na dimensão antiga.
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
			: 'collection_id IN (' . implode( ',', array_fill( 0, count( $collection_ids ), '%d' ) ) . ') AND ';
		$table          = $wpdb->prefix . self::SOURCES[ $kind ]['table'];

		// Erro aqui não vai para a tela: o caminho antigo responde.
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table ($wpdb->prefix . class constant); IN-clause built with array_fill of %d placeholders matching $collection_ids count, merged with the query vector and limit into one replacements array; result depends on the query embedding, so there is no stable cache key.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, 1 - VEC_DISTANCE_COSINE(embedding_vec, VEC_FromText(%s)) AS s FROM {$table} WHERE {$collection_sql}embedding_vec IS NOT NULL ORDER BY s DESC LIMIT %d",
				array_merge( array( $query_text ), $collection_ids, array( $limit ) )
			),
			ARRAY_N
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$failed = '' !== $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		if ( $failed ) {
			// Coluna sumiu ou mudou (tabela recriada): o cron reavalia.
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
	 * Pede ao cron que reavalie a coluna, no máximo uma vez por hora
	 *
	 * Sem o limite, cada busca durante uma troca de modelo faria o cron ler o
	 * embedding de todas as linhas.
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
	 * Remove a coluna (WP-CLI), para levar o banco a MySQL ou MariaDB < 11.7
	 *
	 * Desliga também a busca nativa até o próximo `sync`/`rebuild`.
	 *
	 * @return int Tabelas alteradas.
	 */
	public static function drop(): int {
		global $wpdb;

		$dropped = 0;
		foreach ( self::SOURCES as $kind => $src ) {
			$table = $wpdb->prefix . $src['table'];
			if ( self::column_dim( $table ) > 0 ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin's own table ($wpdb->prefix . class constant); DDL takes no placeholders; explicit admin action from WP-CLI.
				if ( false !== $wpdb->query( "ALTER TABLE {$table} DROP COLUMN embedding_vec" ) ) {
					++$dropped;
				}
			}
			// "off": nem o cron nem as gravações recriam a coluna até um
			// `sync`/`rebuild` explícito (reset()).
			self::save(
				$kind,
				array(
					'ready' => true,
					'off'   => true,
				)
			);
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
		return $dropped;
	}

	/**
	 * Situação para o WP-CLI
	 *
	 * @return array<string, array{dim: int, ready: bool, cursor: int, off: bool}>
	 */
	public static function status(): array {
		$out = array();
		foreach ( array_keys( self::SOURCES ) as $kind ) {
			$cur          = self::state()[ $kind ] ?? array();
			$out[ $kind ] = array(
				'dim'    => (int) ( $cur['dim'] ?? 0 ),
				'ready'  => ! empty( $cur['ready'] ),
				'cursor' => (int) ( $cur['cursor'] ?? 0 ),
				'off'    => ! empty( $cur['off'] ),
			);
		}
		return $out;
	}

	/**
	 * Refaz a coluna do zero (WP-CLI): reavalia a dimensão e preenche de novo
	 */
	public static function reset(): void {
		foreach ( array_keys( self::SOURCES ) as $kind ) {
			self::save( $kind, array( 'recheck' => true ) );
		}
	}

	/**
	 * UPDATE de embedding_vec num conjunto de linhas
	 *
	 * @param string $kind   Tabela.
	 * @param int    $dim    Dimensão da coluna.
	 * @param string $where  Condição com placeholders %d.
	 * @param int[]  $values Valores da condição.
	 * @return int|false Linhas afetadas, ou false se o banco recusou.
	 */
	private static function fill( string $kind, int $dim, string $where, array $values ) {
		global $wpdb;

		$src   = self::SOURCES[ $kind ];
		$table = $wpdb->prefix . $src['table'];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table and SQL expressions from the SOURCES constant; $where is built by this class with %d placeholders only; write path.
		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET embedding_vec = IF({$src['dims']} = %d, {$src['value']}, NULL) WHERE {$where}",
				array_merge( array( $dim ), $values )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Dimensão da coluna embedding_vec (0 = não existe)
	 *
	 * @param string $table Tabela com prefixo.
	 * @return int
	 */
	private static function column_dim( string $table ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table ($wpdb->prefix . class constant); schema check in the cron path.
		$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'embedding_vec' ), ARRAY_A );

		return ( is_array( $column ) && preg_match( '/^vector\((\d+)\)/i', (string) $column['Type'], $m ) ) ? (int) $m[1] : 0;
	}

	/**
	 * Estado salvo
	 *
	 * @return array<string, array>
	 */
	private static function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Grava o estado de uma tabela
	 *
	 * @param string $kind  Tabela.
	 * @param array  $value Estado.
	 */
	private static function save( string $kind, array $value ): void {
		$state          = self::state();
		$state[ $kind ] = $value;
		update_option( self::STATE_OPTION, $state, true );
	}
}
