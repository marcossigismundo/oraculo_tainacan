<?php
/**
 * Gerenciador de Exportação
 *
 * Permite exportar dados em múltiplos formatos
 * para backup, análise ou migração.
 *
 * @package Oraculo_Tainacan
 */

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
    private const FORMATS = ['json', 'csv', 'xml'];

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
        $this->analytics = new AnalyticsManager();
    }

    /**
     * Exporta todos os dados do plugin
     *
     * @param string $format
     * @return string Caminho do arquivo ou conteúdo
     */
    public function export_all(string $format = 'json'): string {
        $data = [
            'plugin_version' => ORACULO_TAINACAN_VERSION,
            'export_date' => current_time('mysql'),
            'settings' => $this->get_settings(),
            'vectors' => $this->get_vectors_data(),
            'analytics' => $this->get_analytics_data(),
            'conversations' => $this->get_conversations_data(),
        ];

        return $this->format_output($data, $format);
    }

    /**
     * Exporta configurações
     *
     * @param string $format
     * @return string
     */
    public function export_settings(string $format = 'json'): string {
        $data = [
            'plugin_version' => ORACULO_TAINACAN_VERSION,
            'export_date' => current_time('mysql'),
            'settings' => $this->get_settings(),
        ];

        return $this->format_output($data, $format);
    }

    /**
     * Exporta vetores de uma coleção
     *
     * @param int $collection_id
     * @param string $format
     * @param bool $include_embeddings
     * @return string
     */
    public function export_vectors(int $collection_id, string $format = 'json', bool $include_embeddings = false): string {
        global $wpdb;

        $table = $wpdb->prefix . 'oraculo_vectors';

        $fields = $include_embeddings
            ? '*'
            : 'id, item_id, collection_id, collection_name, content_text, item_url, item_title, metadata_json, embedding_model, token_count, created_at, updated_at';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; full collection export, no WP API equivalent.
        $data = $wpdb->get_results($wpdb->prepare(
            "SELECT {$fields} FROM {$table} WHERE collection_id = %d",
            $collection_id
        ), ARRAY_A);

        if (!$include_embeddings) {
            foreach ($data as &$row) {
                if (isset($row['metadata_json'])) {
                    $row['metadata'] = json_decode($row['metadata_json'], true);
                    unset($row['metadata_json']);
                }
            }
        }

        $export = [
            'collection_id' => $collection_id,
            'export_date' => current_time('mysql'),
            'total_items' => count($data),
            'items' => $data,
        ];

        return $this->format_output($export, $format);
    }

    /**
     * Exporta analytics
     *
     * @param string $period
     * @param string $format
     * @return string
     */
    public function export_analytics(string $period = 'month', string $format = 'json'): string {
        $data = [
            'period' => $period,
            'export_date' => current_time('mysql'),
            'stats' => $this->analytics->get_stats($period),
            'timeline' => $this->analytics->get_searches_timeline($period),
            'top_searches' => $this->analytics->get_top_searches($period, 100),
            'failed_searches' => $this->analytics->get_failed_searches($period, 100),
            'by_collection' => $this->analytics->get_stats_by_collection($period),
            'model_usage' => $this->analytics->get_model_usage($period),
            'cost_estimate' => $this->analytics->get_cost_estimate($period),
        ];

        return $this->format_output($data, $format);
    }

    /**
     * Exporta conversas
     *
     * @param string $period
     * @param string $format
     * @return string
     */
    public function export_conversations(string $period = 'month', string $format = 'json'): string {
        global $wpdb;

        $date_condition = $this->get_date_condition($period);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned tables; full export query, no WP API equivalent.
        $conversations = $wpdb->get_results(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}oraculo_messages WHERE conversation_id = c.id) as message_count
             FROM {$wpdb->prefix}oraculo_conversations c
             WHERE {$date_condition}
             ORDER BY c.created_at DESC",
            ARRAY_A
        );

        foreach ($conversations as &$conv) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; per-conversation message export.
            $conv['messages'] = $wpdb->get_results($wpdb->prepare(
                "SELECT role, content, tokens_used, created_at
                 FROM {$wpdb->prefix}oraculo_messages
                 WHERE conversation_id = %d
                 ORDER BY created_at",
                $conv['id']
            ), ARRAY_A);
        }

        $data = [
            'period' => $period,
            'export_date' => current_time('mysql'),
            'total_conversations' => count($conversations),
            'conversations' => $conversations,
        ];

        return $this->format_output($data, $format);
    }

    /**
     * Importa configurações
     *
     * @param string $content Conteúdo JSON
     * @return bool|array Sucesso ou erros
     */
    public function import_settings(string $content) {
        $data = json_decode($content, true);

        if (!$data || !isset($data['settings'])) {
            return ['error' => __('Formato de arquivo inválido.', 'oraculo_tainacan')];
        }

        $settings = $data['settings'];
        $imported = 0;
        $errors = [];

        foreach ($settings as $key => $value) {
            // Validar chaves permitidas
            if (!$this->is_valid_setting_key($key)) {
                $errors[] = sprintf(__('Configuração ignorada: %s', 'oraculo_tainacan'), $key);
                continue;
            }

            update_option($key, $value);
            $imported++;
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }

    /**
     * Cria backup completo
     *
     * @return string Caminho do arquivo de backup
     */
    public function create_backup(): string {
        $backup_dir = wp_upload_dir()['basedir'] . '/oraculo-backups';

        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);

            // Proteger diretório
            file_put_contents($backup_dir . '/.htaccess', 'deny from all');
            file_put_contents($backup_dir . '/index.php', '<?php // Silence is golden');
        }

        $filename = 'oraculo-backup-' . date('Y-m-d-His') . '.json';
        $filepath = $backup_dir . '/' . $filename;

        $content = $this->export_all('json');
        file_put_contents($filepath, $content);

        // Limpar backups antigos (manter últimos 5)
        $this->cleanup_old_backups($backup_dir, 5);

        return $filepath;
    }

    /**
     * Restaura backup
     *
     * @param string $filepath
     * @return array
     */
    public function restore_backup(string $filepath): array {
        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => __('Arquivo não encontrado.', 'oraculo_tainacan')];
        }

        $content = file_get_contents($filepath);
        $data = json_decode($content, true);

        if (!$data) {
            return ['success' => false, 'error' => __('Arquivo de backup inválido.', 'oraculo_tainacan')];
        }

        $results = [];

        // Restaurar configurações
        if (isset($data['settings'])) {
            $results['settings'] = $this->import_settings(json_encode(['settings' => $data['settings']]));
        }

        // Restaurar vetores (opcional, pode ser demorado)
        if (isset($data['vectors']) && !empty($data['vectors']['items'])) {
            $results['vectors'] = $this->import_vectors($data['vectors']['items']);
        }

        return ['success' => true, 'results' => $results];
    }

    /**
     * Obtém configurações do plugin
     *
     * @return array
     */
    private function get_settings(): array {
        $settings = [];
        $option_keys = [
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
        ];

        foreach ($option_keys as $key) {
            $value = get_option($key);
            if ($value !== false) {
                $settings[$key] = $value;
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
        return $this->analytics->get_stats('all');
    }

    /**
     * Obtém dados de conversas
     *
     * @return array
     */
    private function get_conversations_data(): array {
        global $wpdb;

        return [
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned tables; summary counts for export metadata.
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}oraculo_conversations"),
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; summary counts for export metadata.
            'total_messages' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}oraculo_messages"),
        ];
    }

    /**
     * Formata saída no formato especificado
     *
     * @param array $data
     * @param string $format
     * @return string
     */
    private function format_output(array $data, string $format): string {
        switch ($format) {
            case 'csv':
                return $this->array_to_csv($data);

            case 'xml':
                return $this->array_to_xml($data);

            case 'json':
            default:
                return wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * Converte array para CSV
     *
     * @param array $data
     * @return string
     */
    private function array_to_csv(array $data): string {
        $output = fopen('php://temp', 'r+');

        // Flatten e escrever
        $this->write_csv_recursive($output, $data, '');

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Escreve CSV recursivamente
     *
     * @param resource $output
     * @param array $data
     * @param string $prefix
     */
    private function write_csv_recursive($output, array $data, string $prefix): void {
        foreach ($data as $key => $value) {
            $current_key = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                if ($this->is_sequential_array($value)) {
                    // Array sequencial - uma linha para cada item
                    foreach ($value as $index => $item) {
                        if (is_array($item)) {
                            $this->write_csv_recursive($output, $item, "{$current_key}[{$index}]");
                        } else {
                            fputcsv($output, ["{$current_key}[{$index}]", $item]);
                        }
                    }
                } else {
                    // Array associativo - recursão
                    $this->write_csv_recursive($output, $value, $current_key);
                }
            } else {
                fputcsv($output, [$current_key, $value]);
            }
        }
    }

    /**
     * Converte array para XML
     *
     * @param array $data
     * @param string $root
     * @return string
     */
    private function array_to_xml(array $data, string $root = 'oraculo_export'): string {
        $xml = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><{$root}></{$root}>");
        $this->array_to_xml_recursive($data, $xml);
        return $xml->asXML();
    }

    /**
     * Converte array para XML recursivamente
     *
     * @param array $data
     * @param \SimpleXMLElement $xml
     */
    private function array_to_xml_recursive(array $data, \SimpleXMLElement $xml): void {
        foreach ($data as $key => $value) {
            // Tratar chaves numéricas
            if (is_numeric($key)) {
                $key = 'item';
            }

            // Sanitizar nome da tag
            $key = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);

            if (is_array($value)) {
                $child = $xml->addChild($key);
                $this->array_to_xml_recursive($value, $child);
            } else {
                $xml->addChild($key, htmlspecialchars((string) $value));
            }
        }
    }

    /**
     * Verifica se array é sequencial
     *
     * @param array $arr
     * @return bool
     */
    private function is_sequential_array(array $arr): bool {
        if (empty($arr)) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

    /**
     * Obtém condição de data
     *
     * @param string $period
     * @return string
     */
    private function get_date_condition(string $period): string {
        switch ($period) {
            case 'today':
                return "DATE(created_at) = CURDATE()";
            case 'week':
                return "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            case 'month':
                return "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            case 'year':
                return "created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
            default:
                return "1=1";
        }
    }

    /**
     * Verifica se chave de configuração é válida
     *
     * @param string $key
     * @return bool
     */
    private function is_valid_setting_key(string $key): bool {
        return str_starts_with($key, 'oraculo_');
    }

    /**
     * Importa vetores
     *
     * @param array $items
     * @return array
     */
    private function import_vectors(array $items): array {
        $imported = 0;
        $errors = [];

        foreach ($items as $item) {
            try {
                $result = $this->vector_store->upsert($item);
                if (!is_wp_error($result)) {
                    $imported++;
                } else {
                    $errors[] = $result->get_error_message();
                }
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        return ['imported' => $imported, 'errors' => $errors];
    }

    /**
     * Limpa backups antigos
     *
     * @param string $dir
     * @param int $keep
     */
    private function cleanup_old_backups(string $dir, int $keep): void {
        $files = glob($dir . '/oraculo-backup-*.json');

        if (count($files) <= $keep) {
            return;
        }

        // Ordenar por data de modificação
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        // Remover excedentes
        foreach (array_slice($files, $keep) as $file) {
            @unlink($file);
        }
    }
}
