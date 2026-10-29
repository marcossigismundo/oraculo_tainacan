<?php
/**
 * Trechos de documentos (PDF, OCR, texto) com vetor próprio
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Vector;

/**
 * Guarda e busca trechos do conteúdo dos documentos dos itens
 *
 * Um item tem um vetor de metadados (VectorStore) e N trechos aqui, cada um
 * com arquivo e página de origem. O embedding fica em float32 empacotado
 * (~6 KB para 1536 dimensões, contra ~29 KB em JSON): um PDF de 100 páginas
 * vira ~100–200 trechos. A busca usa as mesmas duas fases do VectorStore
 * (assinatura binária + cosseno exato nos melhores candidatos).
 */
class ChunkStore {

	/**
	 * Sufixo da tabela (constante: o nome nunca vem de input).
	 */
	private const TABLE = 'oraculo_chunks';

	/**
	 * Opção com a versão de esquema aplicada.
	 */
	private const SCHEMA_OPTION = 'oraculo_chunks_schema';

	/**
	 * Versão atual do esquema.
	 */
	private const SCHEMA_VERSION = 2;

	/**
	 * Nome completo da tabela.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Construtor
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . self::TABLE;
	}

	/**
	 * Cria a tabela em instalações novas e em upgrades (activate() não roda no deploy)
	 */
	public static function maybe_create_table(): void {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		// Sem IF NOT EXISTS: dbDelta só reconhece "CREATE TABLE nome".
		dbDelta(
			"CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT(20) UNSIGNED NOT NULL,
            collection_id BIGINT(20) UNSIGNED NOT NULL,
            attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            source VARCHAR(20) NOT NULL DEFAULT 'document',
            page INT(11) NOT NULL DEFAULT 0,
            chunk_no INT(11) NOT NULL DEFAULT 0,
            chunk_text MEDIUMTEXT NOT NULL,
            content_hash CHAR(32) NOT NULL,
            embedding MEDIUMBLOB NOT NULL,
            embedding_bits VARBINARY(1024) NOT NULL,
            embedding_model VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_item_hash (item_id,content_hash),
            KEY idx_item (item_id),
            KEY idx_collection (collection_id),
            KEY idx_attachment (attachment_id)
        ) {$charset};"
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table name ($wpdb->prefix . class constant); one-time schema check right after dbDelta.
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		}
	}

	/**
	 * A tabela já existe nesta instalação?
	 *
	 * @return bool
	 */
	public static function is_ready(): bool {
		return (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION;
	}

	/**
	 * Empacota um embedding em float32 little-endian.
	 *
	 * @param array $embedding Vetor.
	 * @return string
	 */
	public static function pack_embedding( array $embedding ): string {
		return pack( 'g*', ...array_map( 'floatval', array_values( $embedding ) ) );
	}

	/**
	 * Desempacota um embedding float32.
	 *
	 * @param string $blob Bytes.
	 * @return float[]
	 */
	public static function unpack_embedding( string $blob ): array {
		$values = unpack( 'g*', $blob );
		return false === $values ? array() : array_values( $values );
	}

	/**
	 * Hashes dos trechos já gravados de um item
	 *
	 * @param int $item_id Item.
	 * @return array<string, int> content_hash => id
	 */
	public function get_item_hashes( int $item_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; not available via WP_Query; write path (indexing), caching would return stale hashes.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, content_hash FROM {$this->table} WHERE item_id = %d", $item_id ), ARRAY_A );

		$hashes = array();
		foreach ( (array) $rows as $row ) {
			$hashes[ (string) $row['content_hash'] ] = (int) $row['id'];
		}
		return $hashes;
	}

	/**
	 * Grava trechos novos
	 *
	 * @param array $rows Cada um: item_id, collection_id, attachment_id, source, page, chunk_no, chunk_text, content_hash, embedding (array), embedding_model.
	 * @return int Gravados.
	 */
	public function insert_many( array $rows ): int {
		global $wpdb;

		$saved   = 0;
		$new_ids = array();
		foreach ( $rows as $row ) {
			$embedding = (array) $row['embedding'];
			// INSERT IGNORE + chave única (item_id, content_hash): dois indexadores
			// no mesmo item (worker e CLI) não duplicam trechos.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; $wpdb->insert() has no IGNORE option; every value via placeholders; write path.
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$this->table} (item_id, collection_id, attachment_id, source, page, chunk_no, chunk_text, content_hash, embedding, embedding_bits, embedding_model, created_at) VALUES (%d, %d, %d, %s, %d, %d, %s, %s, %s, %s, %s, %s)",
					(int) $row['item_id'],
					(int) $row['collection_id'],
					(int) $row['attachment_id'],
					(string) $row['source'],
					(int) $row['page'],
					(int) $row['chunk_no'],
					(string) $row['chunk_text'],
					(string) $row['content_hash'],
					self::pack_embedding( $embedding ),
					\Oraculo_Tainacan\embedding_sign_bits( $embedding ),
					(string) ( $row['embedding_model'] ?? '' ),
					current_time( 'mysql' )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $ok ) {
				++$saved;
				$new_ids[] = (int) $wpdb->insert_id;
			}
		}

		if ( $saved ) {
			NativeVector::sync( NativeVector::CHUNKS, $new_ids );
			\Oraculo_Tainacan\invalidate_index_caches();
		}
		return $saved;
	}

	/**
	 * Apaga trechos por id
	 *
	 * @param int[] $ids IDs.
	 * @return int Apagados.
	 */
	public function delete_ids( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$deleted = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; IN-clause built with array_fill of %d placeholders matching $chunk count; write path.
			$deleted += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table} WHERE id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
					$chunk
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		if ( $deleted ) {
			\Oraculo_Tainacan\invalidate_index_caches();
		}
		return $deleted;
	}

	/**
	 * Apaga todos os trechos ("Limpar todos os vetores")
	 *
	 * @return int Apagados.
	 */
	public function truncate(): int {
		global $wpdb;

		if ( ! self::is_ready() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; DELETE without conditions takes no input; write path.
		$deleted = (int) $wpdb->query( "DELETE FROM {$this->table}" );
		\Oraculo_Tainacan\invalidate_index_caches();
		return $deleted;
	}

	/**
	 * Apaga os trechos de um item, de um anexo ou de uma coleção
	 *
	 * @param string $column item_id | attachment_id | collection_id.
	 * @param int    $id     Valor.
	 * @return int Apagados.
	 */
	public function delete_by( string $column, int $id ): int {
		global $wpdb;

		if ( ! self::is_ready() || ! in_array( $column, array( 'item_id', 'attachment_id', 'collection_id' ), true ) || $id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; $column validated against the allowlist above; value via %d; write path.
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE {$column} = %d", $id ) );

		if ( $deleted ) {
			\Oraculo_Tainacan\invalidate_index_caches();
		}
		return $deleted;
	}

	/**
	 * Busca os trechos mais próximos da consulta
	 *
	 * @param array $query_embedding Vetor da consulta.
	 * @param int[] $collection_ids  Coleções (vazio = todas).
	 * @param int   $limit           Máximo de trechos.
	 * @param float $threshold       Similaridade mínima (cosseno exato).
	 * @return array<int, array> Trechos com similarity, do mais próximo ao menos.
	 */
	public function search( array $query_embedding, array $collection_ids, int $limit, float $threshold ): array {
		global $wpdb;

		if ( ! self::is_ready() || $limit < 1 ) {
			return array();
		}

		$collection_ids = array_values( array_filter( array_map( 'intval', $collection_ids ) ) );
		$collection_sql = '';
		if ( ! empty( $collection_ids ) ) {
			$collection_sql = 'collection_id IN (' . implode( ',', array_fill( 0, count( $collection_ids ), '%d' ) ) . ') AND ';
		}

		// MariaDB 11.7+: cosseno no banco, só os ids voltam (ver NativeVector).
		$native = NativeVector::search( NativeVector::CHUNKS, $query_embedding, $collection_ids, $limit, $threshold );
		if ( null !== $native ) {
			return $this->rows_for_scores( $native );
		}

		$query_bits = \Oraculo_Tainacan\embedding_sign_bits( $query_embedding );

		/** Ver oraculo_tainacan_vector_rescore_pool (VectorStore). */
		$pool       = max( $limit, (int) apply_filters( 'oraculo_tainacan_vector_rescore_pool', max( 400, $limit * 20 ) ) );
		$last_id    = 0;
		$batch_size = 5000;
		$candidates = array();

		do {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; IN-clause built with array_fill of %d placeholders matching $collection_ids count, plus $last_id and $batch_size in one replacements array; similarity must be computed in PHP over every row.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, embedding_bits FROM {$this->table} WHERE {$collection_sql}id > %d ORDER BY id ASC LIMIT %d",
					array_merge( $collection_ids, array( $last_id, $batch_size ) )
				),
				ARRAY_N
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last_id  = (int) $row[0];
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

		if ( empty( $candidates ) ) {
			return array();
		}

		asort( $candidates );
		$ids = array_map( 'intval', array_keys( array_slice( $candidates, 0, $pool, true ) ) );

		$scored = array();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; IN-clause built with array_fill of %d placeholders matching $chunk count; result depends on the query embedding.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, item_id, collection_id, attachment_id, source, page, chunk_text, embedding FROM {$this->table} WHERE id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%d' ) ) . ')',
					$chunk
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$similarity = \Oraculo_Tainacan\cosine_similarity( $query_embedding, self::unpack_embedding( (string) $row['embedding'] ) );
				if ( $similarity < $threshold ) {
					continue;
				}
				unset( $row['embedding'] );
				$scored[] = self::hydrate( $row, $similarity );
			}
		}

		usort( $scored, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );
		return array_slice( $scored, 0, $limit );
	}

	/**
	 * Linhas dos trechos já pontuados pelo banco
	 *
	 * @param array<int, float> $scores id => similaridade.
	 * @return array<int, array> Trechos com similarity, do mais próximo ao menos.
	 */
	private function rows_for_scores( array $scores ): array {
		global $wpdb;

		if ( empty( $scores ) ) {
			return array();
		}

		$ids = array_map( 'intval', array_keys( $scores ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; IN-clause built with array_fill of %d placeholders matching $ids count; result depends on the query embedding.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, item_id, collection_id, attachment_id, source, page, chunk_text FROM {$this->table} WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$scored = array();
		foreach ( (array) $rows as $row ) {
			$scored[] = self::hydrate( $row, $scores[ (int) $row['id'] ] );
		}

		usort( $scored, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );
		return $scored;
	}

	/**
	 * Linha de trecho com tipos corretos e similaridade
	 *
	 * @param array $row        Linha do banco (sem embedding).
	 * @param float $similarity Similaridade.
	 * @return array
	 */
	private static function hydrate( array $row, float $similarity ): array {
		// Cast na origem: strict_types + $wpdb devolvendo string.
		$row['id']            = (int) $row['id'];
		$row['item_id']       = (int) $row['item_id'];
		$row['collection_id'] = (int) $row['collection_id'];
		$row['attachment_id'] = (int) $row['attachment_id'];
		$row['page']          = (int) $row['page'];
		$row['similarity']    = $similarity;
		return $row;
	}

	/**
	 * Números para status e CLI
	 *
	 * @param int $collection_id Coleção (0 = todas).
	 * @return array{chunks: int, items: int, attachments: int}
	 */
	public function get_stats( int $collection_id = 0 ): array {
		global $wpdb;

		if ( ! self::is_ready() ) {
			return array(
				'chunks'      => 0,
				'items'       => 0,
				'attachments' => 0,
			);
		}

		$where = $collection_id > 0 ? $wpdb->prepare( 'WHERE collection_id = %d', $collection_id ) : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; $where is either empty or built by $wpdb->prepare() above; aggregate COUNT not expressible via WP_Query.
		$row = $wpdb->get_row( "SELECT COUNT(*) AS chunks, COUNT(DISTINCT item_id) AS items, COUNT(DISTINCT NULLIF(attachment_id, 0)) AS attachments FROM {$this->table} {$where}", ARRAY_A );

		return array(
			'chunks'      => (int) ( $row['chunks'] ?? 0 ),
			'items'       => (int) ( $row['items'] ?? 0 ),
			'attachments' => (int) ( $row['attachments'] ?? 0 ),
		);
	}
}
