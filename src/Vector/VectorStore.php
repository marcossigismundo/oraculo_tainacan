<?php
/**
 * Armazenamento e busca de vetores (embeddings)
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Vector;

use WP_Error;

/**
 * Gerencia armazenamento e busca de vetores no banco de dados
 */
class VectorStore {

	/**
	 * Suffix da tabela de vetores (sem prefixo do WordPress).
	 * Constante de classe: garante que o nome da tabela nunca pode
	 * vir de input do usuário em tempo de execução.
	 */
	private const TABLE_VECTORS = 'oraculo_vectors';

	/**
	 * Versão do esquema da tabela de vetores (2 = coluna embedding_bits).
	 */
	private const SCHEMA_VERSION = 2;

	/**
	 * Opção que guarda a versão de esquema aplicada.
	 */
	private const SCHEMA_OPTION = 'oraculo_vectors_schema';

	/**
	 * Cron que preenche embedding_bits nos vetores antigos.
	 */
	public const CRON_BACKFILL = 'oraculo_backfill_vector_bits';

	/**
	 * Nome da tabela
	 *
	 * @var string
	 */
	private string $table_name;

	/**
	 * Construtor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . self::TABLE_VECTORS;
	}

	/**
	 * Aplica a coluna embedding_bits em instalações anteriores à 2.7.0
	 *
	 * Barato no caminho comum: uma leitura de opção autoload.
	 */
	public static function maybe_upgrade_schema(): void {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_VECTORS;

		// Um request por vez: requests simultâneos logo após o deploy não podem
		// disparar cada um o seu ALTER. Lock vencido (request que morreu no
		// meio) é liberado depois de 10 minutos.
		$lock = self::SCHEMA_OPTION . '_lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock, 0 ) > 10 * MINUTE_IN_SECONDS ) {
				delete_option( $lock );
			}
			return;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin's own table ($wpdb->prefix . class constant); one-time schema migration, dbDelta does not alter tables created with IF NOT EXISTS.
		$has_table  = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$has_column = $has_table && $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'embedding_bits' ) );
		if ( $has_table && ! $has_column ) {
			// Sem AFTER: coluna no fim permite ALGORITHM=INSTANT (MySQL 8.0.12+,
			// MariaDB 10.3+), sem reconstruir a tabela de vetores.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN embedding_bits VARBINARY(1024) DEFAULT NULL" );
			$has_column = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'embedding_bits' ) );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		if ( $has_column ) {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
			self::schedule_backfill();
		}
		// Tabela ausente: activate() a cria já com a coluna. ALTER que falhou:
		// tenta de novo no próximo request.
		delete_option( $lock );
	}

	/**
	 * Agenda o preenchimento das assinaturas (no-op se já agendado)
	 *
	 * Também chamado na ativação: desativar limpa o cron, e uma reativação
	 * com o backfill pela metade não passaria de novo pela migração.
	 */
	public static function schedule_backfill(): void {
		if ( self::has_bits_column() && ! wp_next_scheduled( self::CRON_BACKFILL ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_BACKFILL );
		}
	}

	/**
	 * A coluna embedding_bits já existe nesta instalação?
	 *
	 * @return bool
	 */
	private static function has_bits_column(): bool {
		return (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION;
	}

	/**
	 * Cron: preenche um lote de assinaturas e se reagenda enquanto houver
	 */
	public static function run_backfill_cron(): void {
		$store = new self();
		if ( $store->backfill_bits( 2000 ) > 0 && ! wp_next_scheduled( self::CRON_BACKFILL ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_BACKFILL );
		}
	}

	/**
	 * Grava embedding_bits nos vetores que ainda não têm
	 *
	 * @param int $limit Máximo de linhas neste lote.
	 * @return int Linhas preenchidas.
	 */
	public function backfill_bits( int $limit = 2000 ): int {
		global $wpdb;

		if ( ! self::has_bits_column() ) {
			return 0;
		}

		$done = 0;
		while ( $done < $limit ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table ($wpdb->prefix . class constant); write/cron path, caching is irrelevant.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, embedding_data FROM {$this->table_name} WHERE embedding_bits IS NULL ORDER BY id ASC LIMIT %d",
					min( 200, $limit - $done )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$embedding = json_decode( (string) $row['embedding_data'], true );
				// Embedding ilegível vira assinatura vazia (''), não NULL: sai da
				// fila do backfill e a busca o trata como não comparável.
				$bits = is_array( $embedding ) ? \Oraculo_Tainacan\embedding_sign_bits( $embedding ) : '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write/cron path, caching is irrelevant.
				$updated = $wpdb->update( $this->table_name, array( 'embedding_bits' => $bits ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
				if ( false === $updated && '' !== $bits ) {
					// Assinatura recusada (ex.: modelo com mais de 8192 dimensões):
					// marca como não comparável para a fila não parar nesta linha.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write/cron path, caching is irrelevant.
					$updated = $wpdb->update( $this->table_name, array( 'embedding_bits' => '' ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
				}
				if ( false === $updated ) {
					return $done; // Banco recusando escrita: o próximo cron tenta de novo.
				}
				++$done;
			}
		}

		return $done;
	}

	/**
	 * Insere ou atualiza vetor
	 *
	 * @param array $data
	 * @return int|WP_Error ID do registro ou erro
	 */
	public function upsert( array $data ) {
		global $wpdb;

		$required = array( 'item_id', 'collection_id', 'embedding_data', 'content_text' );
		foreach ( $required as $field ) {
			if ( empty( $data[ $field ] ) ) {
				/* translators: %s: name of the required field that is missing */
				return new WP_Error( 'missing_field', sprintf( __( 'Campo obrigatório ausente: %s', 'oraculo-tainacan' ), $field ) );
			}
		}

		// Verificar se já existe
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->table_name is $wpdb->prefix . self::TABLE_VECTORS (class constant; cannot receive user input); table identifier cannot be parameterized; result cached per-request only.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table_name} WHERE item_id = %d AND collection_id = %d",
				$data['item_id'],
				$data['collection_id']
			)
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$embedding_json = is_array( $data['embedding_data'] )
			? wp_json_encode( $data['embedding_data'] )
			: $data['embedding_data'];
		$embedding_arr  = is_array( $data['embedding_data'] )
			? $data['embedding_data']
			: json_decode( (string) $data['embedding_data'], true );

		$content_hash = \Oraculo_Tainacan\generate_content_hash( $data['content_text'] );

		$record = array(
			'item_id'         => $data['item_id'],
			'collection_id'   => $data['collection_id'],
			'collection_name' => $data['collection_name'] ?? '',
			'embedding_data'  => $embedding_json,
			'content_text'    => $data['content_text'],
			'content_hash'    => $content_hash,
			'item_url'        => $data['item_url'] ?? '',
			'item_title'      => $data['item_title'] ?? '',
			'metadata_json'   => isset( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
			'embedding_model' => $data['embedding_model'] ?? 'text-embedding-ada-002',
			'token_count'     => $data['token_count'] ?? \Oraculo_Tainacan\estimate_tokens( $data['content_text'] ),
		);

		$formats = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' );

		if ( self::has_bits_column() ) {
			$record['embedding_bits'] = is_array( $embedding_arr ) ? \Oraculo_Tainacan\embedding_sign_bits( $embedding_arr ) : '';
			$formats[]                = '%s';
		}
		$formats[] = '%s'; // updated_at / created_at.

		if ( $existing ) {
			$record['updated_at'] = current_time( 'mysql' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_vectors); UPDATE write operation; caching N/A.
			$result = $wpdb->update(
				$this->table_name,
				$record,
				array( 'id' => $existing ),
				$formats,
				array( '%d' )
			);
			if ( false !== $result ) {
				NativeVector::sync( NativeVector::VECTORS, array( (int) $existing ) );
			}
			\Oraculo_Tainacan\invalidate_index_caches();

			return $result !== false ? (int) $existing : new WP_Error( 'update_failed', __( 'Falha ao atualizar vetor.', 'oraculo-tainacan' ) );
		}

		$record['created_at'] = current_time( 'mysql' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no WP core API available.
		$result = $wpdb->insert(
			$this->table_name,
			$record,
			$formats
		);
		if ( false !== $result ) {
			NativeVector::sync( NativeVector::VECTORS, array( (int) $wpdb->insert_id ) );
		}
		\Oraculo_Tainacan\invalidate_index_caches();

		return $result !== false ? $wpdb->insert_id : new WP_Error( 'insert_failed', __( 'Falha ao inserir vetor.', 'oraculo-tainacan' ) );
	}

	/**
	 * Insere múltiplos vetores
	 *
	 * @param array $items Array de dados
	 * @return array ['success' => int, 'failed' => int, 'errors' => array]
	 */
	public function bulk_upsert( array $items ): array {
		$success = 0;
		$failed  = 0;
		$errors  = array();

		foreach ( $items as $index => $item ) {
			$result = $this->upsert( $item );

			if ( is_wp_error( $result ) ) {
				++$failed;
				$errors[] = array(
					'index'   => $index,
					'item_id' => $item['item_id'] ?? null,
					'error'   => $result->get_error_message(),
				);
			} else {
				++$success;
			}
		}

		return array(
			'success' => $success,
			'failed'  => $failed,
			'errors'  => $errors,
		);
	}

	/**
	 * Busca por similaridade de cosseno
	 *
	 * @param array $query_embedding Vetor de busca
	 * @param array $collection_ids IDs das coleções (vazio = todas)
	 * @param int   $limit Máximo de resultados
	 * @param float $threshold Limiar mínimo de similaridade
	 * @return array|WP_Error
	 */
	public function search( array $query_embedding, array $collection_ids = array(), int $limit = 10, float $threshold = 0.3 ) {
		global $wpdb;

		if ( $limit < 1 ) {
			return array();
		}

		// Duas fases (2.7.0): a assinatura binária de cada vetor (1 bit por
		// dimensão, ~192 bytes) pré-seleciona candidatos pela distância de
		// Hamming, calculada em C; só esses candidatos têm o embedding JSON
		// decodificado e o cosseno exato calculado. Antes, decodificar e
		// comparar os 14 mil vetores da coleção 282 levava 15-18 s por busca.
		$collection_ids = array_values( array_filter( array_map( 'intval', $collection_ids ) ) );
		$collection_sql = '';
		if ( ! empty( $collection_ids ) ) {
			$collection_sql = 'collection_id IN (' . implode( ',', array_fill( 0, count( $collection_ids ), '%d' ) ) . ') AND ';
		}

		// MariaDB 11.7+: o cosseno é calculado no banco e só os ids voltam
		// (ver NativeVector). Senão, ou enquanto a coluna é preenchida, as
		// duas fases em PHP.
		$top = NativeVector::search( NativeVector::VECTORS, $query_embedding, $collection_ids, $limit, $threshold );
		if ( null === $top ) {
			$top = self::has_bits_column()
				? $this->scan_with_bits( $query_embedding, $collection_ids, $collection_sql, $limit, $threshold )
				: $this->scan_exact( $query_embedding, $collection_ids, $collection_sql, $limit, $threshold );
		}

		if ( empty( $top ) ) {
			return array();
		}

		arsort( $top );
		$ids = array_map( 'intval', array_keys( $top ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table ($wpdb->prefix . class constant); IN-clause built with array_fill of %d placeholders matching $ids count; result depends on the query embedding, so there is no stable cache key.
		$details = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, item_id, collection_id, collection_name, content_text, item_url, item_title, metadata_json FROM {$this->table_name} WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$results = array();
		foreach ( (array) $details as $vector ) {
			$vector['similarity'] = $top[ (int) $vector['id'] ];
			$results[]            = $vector;
		}

		// Ordenar por similaridade descendente.
		usort( $results, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );

		return $results;
	}

	/**
	 * Fase 1 + 2: pré-seleção por Hamming e reordenação pelo cosseno exato
	 *
	 * @param array  $query_embedding Vetor de busca.
	 * @param array  $collection_ids  IDs inteiros das coleções.
	 * @param string $collection_sql  Fragmento "collection_id IN (...) AND " com %d.
	 * @param int    $limit           Máximo de resultados.
	 * @param float  $threshold       Similaridade mínima (cosseno exato).
	 * @return array<int, float> id => similaridade.
	 */
	private function scan_with_bits( array $query_embedding, array $collection_ids, string $collection_sql, int $limit, float $threshold ): array {
		global $wpdb;

		$query_bits = \Oraculo_Tainacan\embedding_sign_bits( $query_embedding );

		/**
		 * Quantos candidatos da pré-seleção binária vão para o cosseno exato.
		 *
		 * Maior = recall mais próximo da varredura exata, custo ~1 ms por candidato.
		 *
		 * @param int $pool Padrão: 20x o limite, no mínimo 400 (medido na coleção 282: recall@20 de 80% com 100, 98% com 300, 99,4% com 500; ~0,2 s).
		 */
		$pool       = max( $limit, (int) apply_filters( 'oraculo_tainacan_vector_rescore_pool', max( 400, $limit * 20 ) ) );
		$batch_size = 5000; // ~1 MB por lote: só id + assinatura.
		$last_id    = 0;
		$candidates = array(); // id => distância de Hamming.
		$unsigned   = array(); // Vetores ainda sem assinatura (backfill pendente).

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table ($wpdb->prefix . class constant); IN-clause built with array_fill of %d placeholders matching $collection_ids count, and array_merge passes those ids plus $last_id and $batch_size as one replacements array; similarity must be computed in PHP over every row, not expressible via WP_Query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, embedding_bits FROM {$this->table_name} WHERE {$collection_sql}id > %d ORDER BY id ASC LIMIT %d",
					array_merge( $collection_ids, array( $last_id, $batch_size ) )
				),
				ARRAY_N
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row[0];

				if ( null === $row[1] ) {
					$unsigned[] = $last_id;
					continue;
				}

				$distance = \Oraculo_Tainacan\hamming_distance( (string) $row[1], $query_bits );
				if ( PHP_INT_MAX !== $distance ) {
					$candidates[ $last_id ] = $distance;
				}
			}

			if ( count( $candidates ) > 2 * $pool ) {
				asort( $candidates );
				$candidates = array_slice( $candidates, 0, $pool, true );
			}

			$fetched = count( $rows );
			unset( $rows );
		} while ( $fetched === $batch_size );

		asort( $candidates );
		$ids = array_merge( array_keys( array_slice( $candidates, 0, $pool, true ) ), $unsigned );

		return $this->exact_scores( $query_embedding, $ids, $limit, $threshold );
	}

	/**
	 * Cosseno exato para um conjunto de ids, guardando o top-k
	 *
	 * @param array $query_embedding Vetor de busca.
	 * @param int[] $ids             IDs da tabela de vetores.
	 * @param int   $limit           Máximo de resultados.
	 * @param float $threshold       Similaridade mínima.
	 * @return array<int, float> id => similaridade.
	 */
	private function exact_scores( array $query_embedding, array $ids, int $limit, float $threshold ): array {
		global $wpdb;

		$top = array();

		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table ($wpdb->prefix . class constant); IN-clause built with array_fill of %d placeholders matching $chunk count; result depends on the query embedding, so there is no stable cache key.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, embedding_data FROM {$this->table_name} WHERE id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
					$chunk
				),
				ARRAY_N
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$embedding = json_decode( (string) $row[1], true );
				if ( ! is_array( $embedding ) ) {
					continue;
				}
				$similarity = \Oraculo_Tainacan\cosine_similarity( $query_embedding, $embedding );
				if ( $similarity >= $threshold ) {
					$top[ (int) $row[0] ] = $similarity;
				}
			}

			if ( count( $top ) > $limit ) {
				arsort( $top );
				$top = array_slice( $top, 0, $limit, true );
			}
		}

		return $top;
	}

	/**
	 * Varredura exata de toda a coleção (instalações sem embedding_bits)
	 *
	 * @param array  $query_embedding Vetor de busca.
	 * @param array  $collection_ids  IDs inteiros das coleções.
	 * @param string $collection_sql  Fragmento "collection_id IN (...) AND " com %d.
	 * @param int    $limit           Máximo de resultados.
	 * @param float  $threshold       Similaridade mínima.
	 * @return array<int, float> id => similaridade.
	 */
	private function scan_exact( array $query_embedding, array $collection_ids, string $collection_sql, int $limit, float $threshold ): array {
		global $wpdb;

		/**
		 * Tamanho do lote da varredura vetorial exata (linhas por consulta).
		 *
		 * @param int $batch_size Padrão 500 (~10 MB de pico por lote).
		 */
		$batch_size = max( 50, (int) apply_filters( 'oraculo_tainacan_vector_scan_batch', 500 ) );
		$last_id    = 0;
		$top        = array(); // id => similaridade, no máximo $limit entradas.

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table ($wpdb->prefix . class constant); IN-clause built with array_fill of %d placeholders matching $collection_ids count, and array_merge passes those ids plus $last_id and $batch_size as one replacements array; similarity must be computed in PHP over every row, not expressible via WP_Query, and caching raw embeddings would duplicate the table in the object cache.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, embedding_data FROM {$this->table_name} WHERE {$collection_sql}id > %d ORDER BY id ASC LIMIT %d",
					array_merge( $collection_ids, array( $last_id, $batch_size ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id   = (int) $row['id'];
				$embedding = json_decode( (string) $row['embedding_data'], true );

				if ( ! is_array( $embedding ) ) {
					continue;
				}

				$similarity = \Oraculo_Tainacan\cosine_similarity( $query_embedding, $embedding );

				if ( $similarity >= $threshold ) {
					$top[ $last_id ] = $similarity;
				}
			}

			if ( count( $top ) > $limit ) {
				arsort( $top );
				$top = array_slice( $top, 0, $limit, true );
			}

			$fetched = count( $rows );
			unset( $rows );
		} while ( $fetched === $batch_size );

		return $top;
	}

	/**
	 * Busca por keywords (fallback/híbrido)
	 *
	 * @param string $query
	 * @param array  $collection_ids
	 * @param int    $limit
	 * @return array|WP_Error
	 */
	public function keyword_search( string $query, array $collection_ids = array(), int $limit = 10 ) {
		global $wpdb;

		$search_terms = preg_split( '/\s+/', $query );
		$search_terms = array_filter( $search_terms, fn( $t ) => strlen( $t ) > 2 );

		if ( empty( $search_terms ) ) {
			return array();
		}

		// Construir condições LIKE
		$like_conditions = array();
		$like_values     = array();
		foreach ( $search_terms as $term ) {
			$like_conditions[] = '(content_text LIKE %s OR item_title LIKE %s)';
			$like_values[]     = '%' . $wpdb->esc_like( $term ) . '%';
			$like_values[]     = '%' . $wpdb->esc_like( $term ) . '%';
		}

		$where_parts = array( '(' . implode( ' OR ', $like_conditions ) . ')' );

		if ( ! empty( $collection_ids ) ) {
			$collection_ids = array_map( 'intval', $collection_ids );
			$placeholders   = implode( ',', array_fill( 0, count( $collection_ids ), '%d' ) );
			$where_parts[]  = "collection_id IN ($placeholders)";
			$like_values    = array_merge( $like_values, $collection_ids );
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where_parts );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->table_name is $wpdb->prefix . self::TABLE_VECTORS (class constant; cannot receive user input); $where_clause is built from %s/%d placeholders and esc_like values; $collection_ids is array_map('intval', ...)d above (type-safe); table identifier cannot be parameterized.
		$sql = $wpdb->prepare(
			"SELECT id, item_id, collection_id, collection_name,
                    content_text, item_url, item_title, metadata_json
             FROM {$this->table_name}
             {$where_clause}
             LIMIT %d",
			array_merge( $like_values, array( $limit ) )
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is pre-prepared via $wpdb->prepare() at the assignment above (line 234); custom plugin table; keyword searches are query-specific and not worth caching.
		$results = $wpdb->get_results( $sql, ARRAY_A );

		// Adicionar score baseado em matches
		foreach ( $results as &$result ) {
			$score = 0;
			$text  = strtolower( $result['content_text'] . ' ' . $result['item_title'] );
			foreach ( $search_terms as $term ) {
				$score += substr_count( $text, strtolower( $term ) );
			}
			$result['keyword_score'] = $score;
		}

		// Ordenar por score
		usort( $results, fn( $a, $b ) => $b['keyword_score'] <=> $a['keyword_score'] );

		return $results;
	}

	/**
	 * Obtém vetor por item_id
	 *
	 * @param int $item_id
	 * @param int $collection_id
	 * @return array|null
	 */
	public function get_by_item( int $item_id, int $collection_id ): ?array {
		global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->table_name is $wpdb->prefix . self::TABLE_VECTORS (class constant; cannot receive user input); table identifier cannot be parameterized; per-item lookup during indexing.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table_name} WHERE item_id = %d AND collection_id = %d",
				$item_id,
				$collection_id
			),
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row;
	}

	/**
	 * Verifica se item precisa ser reindexado
	 *
	 * @param int    $item_id
	 * @param int    $collection_id
	 * @param string $content_hash Hash do conteúdo atual
	 * @return bool
	 */
	public function needs_reindex( int $item_id, int $collection_id, string $content_hash ): bool {
		global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->table_name is $wpdb->prefix . self::TABLE_VECTORS (class constant; cannot receive user input); table identifier cannot be parameterized; per-item hash check during indexing.
		$existing_hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content_hash FROM {$this->table_name} WHERE item_id = %d AND collection_id = %d",
				$item_id,
				$collection_id
			)
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $existing_hash !== $content_hash;
	}

	/**
	 * Linhas de metadados de vários itens (para itens achados só pelos trechos)
	 *
	 * @param int[] $item_ids Itens.
	 * @return array<int, array>
	 */
	public function get_by_item_ids( array $item_ids ): array {
		global $wpdb;

		$item_ids = array_values( array_filter( array_map( 'intval', $item_ids ) ) );
		if ( empty( $item_ids ) ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table ($wpdb->prefix . class constant); IN-clause built with array_fill of %d placeholders matching $item_ids count; result depends on the query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, item_id, collection_id, collection_name, content_text, item_url, item_title, metadata_json FROM {$this->table_name} WHERE item_id IN (" . implode( ',', array_fill( 0, count( $item_ids ), '%d' ) ) . ')',
				$item_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return (array) $rows;
	}

	/**
	 * Remove vetor
	 *
	 * @param int $item_id
	 * @param int $collection_id
	 * @return bool
	 */
	public function delete( int $item_id, int $collection_id ): bool {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_vectors); DELETE write operation; caching N/A.
		$result = $wpdb->delete(
			$this->table_name,
			array(
				'item_id'       => $item_id,
				'collection_id' => $collection_id,
			),
			array( '%d', '%d' )
		);
		// Trechos dos documentos saem junto: todo caminho de remoção do item
		// (despublicar, excluir, reconciliação) passa por aqui.
		( new ChunkStore() )->delete_by( 'item_id', $item_id );
		\Oraculo_Tainacan\invalidate_index_caches();

		return $result !== false;
	}

	/**
	 * Remove todos os vetores de uma coleção
	 *
	 * @param int $collection_id
	 * @return int Número de registros removidos
	 */
	public function delete_collection( int $collection_id ): int {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_vectors); DELETE write operation; caching N/A.
		$result = $wpdb->delete(
			$this->table_name,
			array( 'collection_id' => $collection_id ),
			array( '%d' )
		);
		( new ChunkStore() )->delete_by( 'collection_id', $collection_id );
		\Oraculo_Tainacan\invalidate_index_caches();

		return (int) $result;
	}

	/**
	 * Verifica se a tabela existe
	 *
	 * @return bool
	 */
	private function table_exists(): bool {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- SHOW TABLES check; no WP API for this; result is small and table existence rarely changes.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name ) ) === $this->table_name;
	}

	/**
	 * Obtém estatísticas do store
	 *
	 * @return array
	 */
	public function get_stats(): array {
		global $wpdb;

		// Verificar se a tabela existe
		if ( ! $this->table_exists() ) {
			return array(
				'total_vectors' => 0,
				'total_tokens'  => 0,
				'by_collection' => array(),
				'models_used'   => array(),
				'oldest_entry'  => null,
				'newest_entry'  => null,
			);
		}

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->table_name is $wpdb->prefix . self::TABLE_VECTORS (class constant; cannot receive user input); custom table; stats are admin-only and cached by caller if needed.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}" );

		$by_collection = $wpdb->get_results(
			"SELECT collection_id, collection_name, COUNT(*) as count,
                    SUM(token_count) as total_tokens
             FROM {$this->table_name}
             GROUP BY collection_id, collection_name
             ORDER BY count DESC",
			ARRAY_A
		) ?: array();

		$total_tokens = (int) $wpdb->get_var( "SELECT SUM(token_count) FROM {$this->table_name}" );

		$models_used = $wpdb->get_col(
			"SELECT DISTINCT embedding_model FROM {$this->table_name}"
		) ?: array();

		$oldest = $wpdb->get_var( "SELECT MIN(created_at) FROM {$this->table_name}" );
		$newest = $wpdb->get_var( "SELECT MAX(updated_at) FROM {$this->table_name}" );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'total_vectors' => $total,
			'total_tokens'  => $total_tokens,
			'by_collection' => $by_collection,
			'models_used'   => $models_used,
			'oldest_entry'  => $oldest,
			'newest_entry'  => $newest,
		);
	}

	/**
	 * Obtém contagem por coleção
	 *
	 * @param int $collection_id
	 * @return int
	 */
	public function count_by_collection( int $collection_id ): int {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->table_name is $wpdb->prefix . self::TABLE_VECTORS (class constant; cannot receive user input); table identifier cannot be parameterized; per-collection count used in admin UI.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name} WHERE collection_id = %d",
				$collection_id
			)
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $count;
	}

	/**
	 * Obtém IDs de itens indexados de uma coleção
	 *
	 * @param int $collection_id
	 * @return array
	 */
	public function get_indexed_item_ids( int $collection_id ): array {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->table_name is $wpdb->prefix . self::TABLE_VECTORS (class constant; cannot receive user input); table identifier cannot be parameterized; ID list used during indexing batch.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT item_id FROM {$this->table_name} WHERE collection_id = %d",
				$collection_id
			)
		) ?: array();
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $ids;
	}

	/**
	 * Limpa vetores antigos
	 *
	 * @param int $days_old
	 * @return int Número de registros removidos
	 */
	public function cleanup_old( int $days_old = 90 ): int {
		global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->table_name is $wpdb->prefix . self::TABLE_VECTORS (class constant; cannot receive user input); DELETE write operation; caching N/A for writes.
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days_old
			)
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		\Oraculo_Tainacan\invalidate_index_caches();
		return $result;
	}

	/**
	 * Otimiza tabela
	 *
	 * @return bool
	 */
	public function optimize(): bool {
		global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->table_name is $wpdb->prefix . self::TABLE_VECTORS (class constant; cannot receive user input); OPTIMIZE TABLE maintenance; write operation, caching N/A.
		$result = $wpdb->query( "OPTIMIZE TABLE {$this->table_name}" );
		return $result !== false;
	}

	/**
	 * Exporta vetores de uma coleção
	 *
	 * @param int    $collection_id
	 * @param string $format 'json' ou 'csv'
	 * @return string
	 */
	public function export( int $collection_id, string $format = 'json' ): string {
		global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->table_name is $wpdb->prefix . self::TABLE_VECTORS (class constant; cannot receive user input); table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- export is an admin-only bulk read; caching export data is impractical.
		$data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item_id, item_title, item_url, content_text, content_hash,
                    embedding_model, token_count, created_at, updated_at
             FROM {$this->table_name}
             WHERE collection_id = %d
             ORDER BY item_id",
				$collection_id
			),
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( $format === 'csv' ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp memory stream; WP_Filesystem has no equivalent for in-memory streaming writes.
			$output = fopen( 'php://temp', 'r+' );
			if ( ! empty( $data ) ) {
				fputcsv( $output, array_keys( $data[0] ) );
				foreach ( $data as $row ) {
					fputcsv( $output, $row );
				}
			}
			rewind( $output );
			$csv = stream_get_contents( $output );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://temp memory stream; WP_Filesystem has no equivalent for in-memory streaming writes.
			fclose( $output );
			return $csv;
		}

		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}
}
