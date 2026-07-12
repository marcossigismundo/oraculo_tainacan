<?php
/**
 * Gerenciador de Exportação
 *
 * Permite exportar dados em múltiplos formatos
 * para backup, análise ou migração.
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Features;

use Oraculo_Tainacan\Vector\VectorStore;
use Oraculo_Tainacan\Analytics\AnalyticsManager;

/**
 * Gerencia exportação de dados do plugin
 */
class ExportManager {

	/**
	 * Formatos suportados
	 */
	private const FORMATS = array( 'json', 'csv', 'xml' );

	/**
	 * @var VectorStore
	 */
	private VectorStore $vector_store;

	/**
	 * @var AnalyticsManager
	 */
	private AnalyticsManager $analytics;

	/**
	 * Construtor
	 */
	public function __construct() {
		$this->vector_store = new VectorStore();
		$this->analytics    = new AnalyticsManager();
	}

	/**
	 * Exporta todos os dados do plugin
	 *
	 * @param string $format
	 * @return string Caminho do arquivo ou conteúdo
	 */
	public function export_all( string $format = 'json' ): string {
		$data = array(
			'plugin_version' => ORACULO_TAINACAN_VERSION,
			'export_date'    => current_time( 'mysql' ),
			'settings'       => $this->get_settings(),
			'vectors'        => $this->get_vectors_data(),
			'analytics'      => $this->get_analytics_data(),
			'conversations'  => $this->get_conversations_data(),
		);

		return $this->format_output( $data, $format );
	}

	/**
	 * Exporta configurações
	 *
	 * @param string $format
	 * @return string
	 */
	public function export_settings( string $format = 'json' ): string {
		$data = array(
			'plugin_version' => ORACULO_TAINACAN_VERSION,
			'export_date'    => current_time( 'mysql' ),
			'settings'       => $this->get_settings(),
		);

		return $this->format_output( $data, $format );
	}

	/**
	 * Exporta vetores de uma coleção
	 *
	 * @param int    $collection_id
	 * @param string $format
	 * @param bool   $include_embeddings
	 * @return string
	 */
	public function export_vectors( int $collection_id, string $format = 'json', bool $include_embeddings = false ): string {
		global $wpdb;

		$table = $wpdb->prefix . 'oraculo_vectors';

		$fields = $include_embeddings
			? '*'
			: 'id, item_id, collection_id, collection_name, content_text, item_url, item_title, metadata_json, embedding_model, token_count, created_at, updated_at';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is built from $wpdb->prefix + literal (oraculo_vectors); $fields is a hardcoded string or literal '*'; neither can be parameterized as identifiers.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; full collection export, no WP API equivalent.
		$data = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$fields} FROM {$table} WHERE collection_id = %d",
				$collection_id
			),
			ARRAY_A
		);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $include_embeddings ) {
			foreach ( $data as &$row ) {
				if ( isset( $row['metadata_json'] ) ) {
					$row['metadata'] = (array) json_decode( (string) $row['metadata_json'], true );
					unset( $row['metadata_json'] );
				}
			}
		}

		$export = array(
			'collection_id' => $collection_id,
			'export_date'   => current_time( 'mysql' ),
			'total_items'   => count( $data ),
			'items'         => $data,
		);

		return $this->format_output( $export, $format );
	}

	/**
	 * Exporta analytics
	 *
	 * @param string $period
	 * @param string $format
	 * @return string
	 */
	public function export_analytics( string $period = 'month', string $format = 'json' ): string {
		$data = array(
			'period'          => $period,
			'export_date'     => current_time( 'mysql' ),
			'stats'           => $this->analytics->get_stats( $period ),
			'timeline'        => $this->analytics->get_searches_timeline( $period ),
			'top_searches'    => $this->analytics->get_top_searches( $period, 100 ),
			'failed_searches' => $this->analytics->get_failed_searches( $period, 100 ),
			'by_collection'   => $this->analytics->get_stats_by_collection( $period ),
			'model_usage'     => $this->analytics->get_model_usage( $period ),
			'cost_estimate'   => $this->analytics->get_cost_estimate( $period ),
		);

		return $this->format_output( $data, $format );
	}

	/**
	 * Exporta conversas
	 *
	 * @param string $period
	 * @param string $format
	 * @return string
	 */
	public function export_conversations( string $period = 'month', string $format = 'json' ): string {
		global $wpdb;

		[$where_frag, $where_args] = $this->get_date_where( $period );

		$sql = "SELECT c.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}oraculo_messages WHERE conversation_id = c.id) as message_count
             FROM {$wpdb->prefix}oraculo_conversations c
             WHERE {$where_frag}
             ORDER BY c.created_at DESC";

		if ( empty( $where_args ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table names are $wpdb->prefix . literal (plugin-owned, no user input); $where_frag is from get_date_where() literal-only branch (no %d placeholders here).
			$conversations = $wpdb->get_results( $sql, ARRAY_A );
		} else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table names are $wpdb->prefix . literal (plugin-owned); $where_frag from get_date_where() (class-defined whitelist); %d args bound via prepare below.
			$conversations = $wpdb->get_results( $wpdb->prepare( $sql, ...$where_args ), ARRAY_A );
		}

		foreach ( $conversations as &$conv ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; per-conversation message export.
			$conv['messages'] = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT role, content, tokens_used, created_at
                 FROM {$wpdb->prefix}oraculo_messages
                 WHERE conversation_id = %d
                 ORDER BY created_at",
					$conv['id']
				),
				ARRAY_A
			);
		}

		$data = array(
			'period'              => $period,
			'export_date'         => current_time( 'mysql' ),
			'total_conversations' => count( $conversations ),
			'conversations'       => $conversations,
		);

		return $this->format_output( $data, $format );
	}

	/**
	 * Importa configurações
	 *
	 * @param string $content Conteúdo JSON
	 * @return bool|array Sucesso ou erros
	 */
	public function import_settings( string $content ) {
		$data = json_decode( $content, true );

		// Schema: objeto com chave settings contendo um mapa chave=>valor.
		if ( ! is_array( $data ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return array( 'error' => __( 'Formato de arquivo inválido.', 'oraculo-tainacan' ) );
		}

		$settings = $data['settings'];
		$imported = 0;
		$errors   = array();

		foreach ( $settings as $key => $value ) {
			// Validar chaves permitidas
			if ( ! $this->is_valid_setting_key( $key ) ) {
				/* translators: %s: configuration key that was ignored */
				$errors[] = sprintf( __( 'Configuração ignorada: %s', 'oraculo-tainacan' ), $key );
				continue;
			}

			update_option( $key, $value );
			++$imported;
		}

		return array(
			'imported' => $imported,
			'errors'   => $errors,
		);
	}

	/**
	 * Cria backup completo
	 *
	 * @return string Caminho do arquivo de backup
	 */
	public function create_backup(): string {
		$backup_dir = wp_upload_dir()['basedir'] . '/oraculo-backups';

		if ( ! file_exists( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );

			// Proteger diretório
			file_put_contents( $backup_dir . '/.htaccess', 'deny from all' );
			file_put_contents( $backup_dir . '/index.php', '<?php // Silence is golden' );
		}

		$filename = 'oraculo-backup-' . gmdate( 'Y-m-d-His' ) . '.json';
		$filepath = $backup_dir . '/' . $filename;

		$content = $this->export_all( 'json' );
		file_put_contents( $filepath, $content );

		// Limpar backups antigos (manter últimos 5)
		$this->cleanup_old_backups( $backup_dir, 5 );

		return $filepath;
	}

	/**
	 * Restaura backup
	 *
	 * @param string $filepath
	 * @return array
	 */
	public function restore_backup( string $filepath ): array {
		if ( ! file_exists( $filepath ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Arquivo não encontrado.', 'oraculo-tainacan' ),
			);
		}

		$content = file_get_contents( $filepath );
		$data    = json_decode( $content, true );

		// Schema: backup é um objeto JSON (settings/vectors/analytics opcionais, validados adiante).
		if ( ! is_array( $data ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Arquivo de backup inválido.', 'oraculo-tainacan' ),
			);
		}

		$results = array();

		// Restaurar configurações
		if ( isset( $data['settings'] ) ) {
			$results['settings'] = $this->import_settings( json_encode( array( 'settings' => $data['settings'] ) ) );
		}

		// Restaurar vetores (opcional, pode ser demorado)
		if ( isset( $data['vectors'] ) && ! empty( $data['vectors']['items'] ) ) {
			$results['vectors'] = $this->import_vectors( $data['vectors']['items'] );
		}

		return array(
			'success' => true,
			'results' => $results,
		);
	}

	/**
	 * Obtém configurações do plugin
	 *
	 * @return array
	 */
	private function get_settings(): array {
		$settings    = array();
		$option_keys = array(
			'oraculo_provider',
			'oraculo_model',
			'oraculo_embedding_provider',
			'oraculo_embedding_model',
			'oraculo_system_prompt',
			'oraculo_welcome_message',
			'oraculo_suggested_questions',
			'oraculo_max_results',
			'oraculo_similarity_threshold',
			'oraculo_batch_size',
			'oraculo_auto_index',
			'oraculo_enable_chat',
			'oraculo_chat_position',
		);

		foreach ( $option_keys as $key ) {
			$value = get_option( $key );
			if ( $value !== false ) {
				$settings[ $key ] = $value;
			}
		}

		return $settings;
	}

	/**
	 * Obtém dados de vetores
	 *
	 * @return array
	 */
	private function get_vectors_data(): array {
		return $this->vector_store->get_stats();
	}

	/**
	 * Obtém dados de analytics
	 *
	 * @return array
	 */
	private function get_analytics_data(): array {
		return $this->analytics->get_stats( 'all' );
	}

	/**
	 * Obtém dados de conversas
	 *
	 * @return array
	 */
	private function get_conversations_data(): array {
		global $wpdb;

		return array(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned tables; summary counts for export metadata.
			'total'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}oraculo_conversations" ),
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; summary counts for export metadata.
			'total_messages' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}oraculo_messages" ),
		);
	}

	/**
	 * Formata saída no formato especificado
	 *
	 * @param array  $data
	 * @param string $format
	 * @return string
	 */
	private function format_output( array $data, string $format ): string {
		switch ( $format ) {
			case 'csv':
				return $this->array_to_csv( $data );

			case 'xml':
				return $this->array_to_xml( $data );

			case 'json':
			default:
				return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		}
	}

	/**
	 * Converte array para CSV
	 *
	 * @param array $data
	 * @return string
	 */
	private function array_to_csv( array $data ): string {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp memory stream; WP_Filesystem has no equivalent for in-memory streaming writes.
		$output = fopen( 'php://temp', 'r+' );

		// Flatten e escrever
		$this->write_csv_recursive( $output, $data, '' );

		rewind( $output );
		$csv = stream_get_contents( $output );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://temp memory stream; WP_Filesystem has no equivalent for in-memory streaming writes.
		fclose( $output );

		return $csv;
	}

	/**
	 * Escreve CSV recursivamente
	 *
	 * @param resource $output
	 * @param array    $data
	 * @param string   $prefix
	 */
	private function write_csv_recursive( $output, array $data, string $prefix ): void {
		foreach ( $data as $key => $value ) {
			$current_key = $prefix ? "{$prefix}.{$key}" : $key;

			if ( is_array( $value ) ) {
				if ( $this->is_sequential_array( $value ) ) {
					// Array sequencial - uma linha para cada item
					foreach ( $value as $index => $item ) {
						if ( is_array( $item ) ) {
							$this->write_csv_recursive( $output, $item, "{$current_key}[{$index}]" );
						} else {
							fputcsv( $output, array( "{$current_key}[{$index}]", $item ) );
						}
					}
				} else {
					// Array associativo - recursão
					$this->write_csv_recursive( $output, $value, $current_key );
				}
			} else {
				fputcsv( $output, array( $current_key, $value ) );
			}
		}
	}

	/**
	 * Converte array para XML
	 *
	 * @param array  $data
	 * @param string $root
	 * @return string
	 */
	private function array_to_xml( array $data, string $root = 'oraculo_export' ): string {
		$xml = new \SimpleXMLElement( "<?xml version=\"1.0\" encoding=\"UTF-8\"?><{$root}></{$root}>" );
		$this->array_to_xml_recursive( $data, $xml );
		return $xml->asXML();
	}

	/**
	 * Converte array para XML recursivamente
	 *
	 * @param array             $data
	 * @param \SimpleXMLElement $xml
	 */
	private function array_to_xml_recursive( array $data, \SimpleXMLElement $xml ): void {
		foreach ( $data as $key => $value ) {
			// Tratar chaves numéricas
			if ( is_numeric( $key ) ) {
				$key = 'item';
			}

			// Sanitizar nome da tag
			$key = preg_replace( '/[^a-zA-Z0-9_]/', '_', $key );

			if ( is_array( $value ) ) {
				$child = $xml->addChild( $key );
				$this->array_to_xml_recursive( $value, $child );
			} else {
				$xml->addChild( $key, htmlspecialchars( (string) $value ) );
			}
		}
	}

	/**
	 * Verifica se array é sequencial
	 *
	 * @param array $arr
	 * @return bool
	 */
	private function is_sequential_array( array $arr ): bool {
		if ( empty( $arr ) ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Constrói o fragmento WHERE de data como SQL preparável.
	 *
	 * Retorna [string $where_fragment, array $prepare_args]: o fragmento
	 * é selecionado por whitelist (switch); valores variáveis (dias)
	 * usam %d para serem bound via $wpdb->prepare(). Nenhum dos valores
	 * vem de input do usuário — todos são literais hardcoded.
	 *
	 * @param string $period 'today'|'week'|'month'|'year'|'all'
	 * @return array{0:string,1:array<int,int>}
	 */
	private function get_date_where( string $period ): array {
		switch ( $period ) {
			case 'today':
				return array( 'DATE(created_at) = CURDATE()', array() );
			case 'week':
				return array( 'created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', array( 7 ) );
			case 'month':
				return array( 'created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', array( 30 ) );
			case 'year':
				return array( 'created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)', array() );
			default:
				return array( '1=1', array() );
		}
	}

	/**
	 * Verifica se chave de configuração é válida
	 *
	 * @param string $key
	 * @return bool
	 */
	private function is_valid_setting_key( string $key ): bool {
		return str_starts_with( $key, 'oraculo_' );
	}

	/**
	 * Importa vetores
	 *
	 * @param array $items
	 * @return array
	 */
	private function import_vectors( array $items ): array {
		$imported = 0;
		$errors   = array();

		foreach ( $items as $item ) {
			try {
				$result = $this->vector_store->upsert( $item );
				if ( ! is_wp_error( $result ) ) {
					++$imported;
				} else {
					$errors[] = $result->get_error_message();
				}
			} catch ( \Exception $e ) {
				$errors[] = $e->getMessage();
			}
		}

		return array(
			'imported' => $imported,
			'errors'   => $errors,
		);
	}

	/**
	 * Limpa backups antigos
	 *
	 * @param string $dir
	 * @param int    $keep
	 */
	private function cleanup_old_backups( string $dir, int $keep ): void {
		$files = glob( $dir . '/oraculo-backup-*.json' );

		if ( count( $files ) <= $keep ) {
			return;
		}

		// Ordenar por data de modificação
		usort( $files, fn( $a, $b ) => filemtime( $b ) - filemtime( $a ) );

		// Remover excedentes
		foreach ( array_slice( $files, $keep ) as $file ) {
			wp_delete_file( $file );
		}
	}
}
