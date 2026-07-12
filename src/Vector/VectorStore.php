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

		if ( $existing ) {
			$record['updated_at'] = current_time( 'mysql' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_vectors); UPDATE write operation; caching N/A.
			$result = $wpdb->update(
				$this->table_name,
				$record,
				array( 'id' => $existing ),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			\Oraculo_Tainacan\oraculo_tainacan_flush_cache();

			return $result !== false ? (int) $existing : new WP_Error( 'update_failed', __( 'Falha ao atualizar vetor.', 'oraculo-tainacan' ) );
		}

		$record['created_at'] = current_time( 'mysql' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no WP core API available.
		$result = $wpdb->insert(
			$this->table_name,
			$record,
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		\Oraculo_Tainacan\oraculo_tainacan_flush_cache();

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

		// Buscar todos os vetores das coleções especificadas
		$where_clause = '';
		if ( ! empty( $collection_ids ) ) {
			$collection_ids = array_map( 'intval', $collection_ids );
			$placeholders   = implode( ',', array_fill( 0, count( $collection_ids ), '%d' ) );
            // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $collection_ids is array_map('intval', ...)d above; type-safe to interpolate; $placeholders contains only %d entries built from array_fill; count matches spread; passed through $wpdb->prepare.
			$where_clause = $wpdb->prepare( "WHERE collection_id IN ($placeholders)", $collection_ids );
            // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->table_name is $wpdb->prefix . self::TABLE_VECTORS (class constant; cannot receive user input); $where_clause is prepared above; table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; caching not applied here because embedding data is large and similarity computed in PHP.
		$vectors = $wpdb->get_results(
			"SELECT id, item_id, collection_id, collection_name, embedding_data,
                    content_text, item_url, item_title, metadata_json
             FROM {$this->table_name}
             {$where_clause}",
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( empty( $vectors ) ) {
			return array();
		}

		// Calcular similaridades
		$results = array();
		foreach ( $vectors as $vector ) {
			$embedding = json_decode( $vector['embedding_data'], true );

			if ( ! is_array( $embedding ) ) {
				continue;
			}

			$similarity = \Oraculo_Tainacan\cosine_similarity( $query_embedding, $embedding );

			if ( $similarity >= $threshold ) {
				$vector['similarity'] = $similarity;
				unset( $vector['embedding_data'] ); // Não retornar o embedding
				$results[] = $vector;
			}
		}

		// Ordenar por similaridade descendente
		usort( $results, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );

		// Limitar resultados
		return array_slice( $results, 0, $limit );
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
		\Oraculo_Tainacan\oraculo_tainacan_flush_cache();

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
		\Oraculo_Tainacan\oraculo_tainacan_flush_cache();

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
		\Oraculo_Tainacan\oraculo_tainacan_flush_cache();
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
