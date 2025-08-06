<?php
/**
 * Classe para gerenciamento do menu administrativo
 *
 * @package Oraculo_Tainacan
 */

if (!defined('WPINC')) {
    die;
}

class Oraculo_Tainacan_Admin_Menu {
    
    private $indexing_manager;
    
    public function __construct() {
        if (class_exists('Oraculo_Tainacan_Indexing_Manager')) {
            $this->indexing_manager = new Oraculo_Tainacan_Indexing_Manager();
        }
    }
    
    public function init() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('wp_ajax_oraculo_index_collection', array($this, 'ajax_index_collection'));
        add_action('wp_ajax_oraculo_index_collection_batch', array($this, 'ajax_index_collection_batch'));
        add_action('wp_ajax_oraculo_test_search', array($this, 'ajax_test_search'));
        add_action('wp_ajax_oraculo_export_data', array($this, 'ajax_export_data'));
        add_action('wp_ajax_oraculo_test_api_connection', array($this, 'ajax_test_api_connection'));
        add_action('wp_ajax_oraculo_clear_cache', array($this, 'ajax_clear_cache'));
    }

    public function register_admin_menu() {
        add_menu_page(
            __('Oráculo Tainacan', 'oraculo-tainacan'),
            __('Oráculo Tainacan', 'oraculo-tainacan'),
            'manage_options',
            'oraculo-tainacan',
            array($this, 'render_settings_page'),
            'dashicons-search',
            25
        );

        add_submenu_page(
            'oraculo-tainacan',
            __('Configurações', 'oraculo-tainacan'),
            __('Configurações', 'oraculo-tainacan'),
            'manage_options',
            'oraculo-tainacan',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            'oraculo-tainacan',
            __('Dashboard', 'oraculo-tainacan'),
            __('Dashboard', 'oraculo-tainacan'),
            'manage_options',
            'oraculo-tainacan-dashboard',
            array($this, 'render_dashboard_page')
        );

        add_submenu_page(
            'oraculo-tainacan',
            __('Treinamento', 'oraculo-tainacan'),
            __('Treinamento', 'oraculo-tainacan'),
            'manage_options',
            'oraculo-tainacan-training',
            array($this, 'render_training_page')
        );

        add_submenu_page(
            'oraculo-tainacan',
            __('Debug', 'oraculo-tainacan'),
            __('Debug', 'oraculo-tainacan'),
            'manage_options',
            'oraculo-tainacan-debug',
            array($this, 'render_debug_page')
        );
    }

    public function render_settings_page() {
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/settings-page.php';
    }

    public function render_dashboard_page() {
        if (file_exists(ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/dashboard-page.php')) {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/dashboard-page.php';
        } else {
            echo '<div class="wrap"><h1>Dashboard</h1><p>Dashboard page not found.</p></div>';
        }
    }

    public function render_training_page() {
        if (file_exists(ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/training-page.php')) {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/training-page.php';
        } else {
            echo '<div class="wrap"><h1>Treinamento</h1><p>Training page not found.</p></div>';
        }
    }

    public function render_debug_page() {
        if (file_exists(ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/debug-page.php')) {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/debug-page.php';
        } else {
            echo '<div class="wrap"><h1>Debug</h1><p>Debug page not found.</p></div>';
        }
    }

    /**
     * Handler para testar conexão com API
     */
    public function ajax_test_api_connection() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada.', 'oraculo-tainacan')));
        }

        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-openai-client.php';
        $openai = new Oraculo_Tainacan_OpenAI_Client();
        $result = $openai->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Conexão bem-sucedida!', 'oraculo-tainacan')));
    }

    /**
     * Handler para limpar cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada.', 'oraculo-tainacan')));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'oraculo_vectors';
        $wpdb->query("TRUNCATE TABLE $table_name");

        wp_send_json_success(array('message' => __('Cache limpo com sucesso!', 'oraculo-tainacan')));
    }

    /**
     * Indexa uma coleção via AJAX
     */
    public function ajax_index_collection() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada.', 'oraculo-tainacan')));
        }

        $collection_id = isset($_POST['collection_id']) ? (int) $_POST['collection_id'] : 0;
        $force_update = isset($_POST['force_update']) ? (bool) $_POST['force_update'] : false;

        if (empty($collection_id)) {
            wp_send_json_error(array('message' => __('ID de coleção inválido.', 'oraculo-tainacan')));
        }

        // Usa o gerenciador de indexação se disponível
        if ($this->indexing_manager) {
            $result = $this->indexing_manager->start_indexing($collection_id, $force_update);
            
            if ($result['success']) {
                $batch_result = $this->indexing_manager->process_next_batch($collection_id);
                
                wp_send_json_success(array(
                    'message' => sprintf(
                        __('Indexação iniciada. %d itens a processar.', 'oraculo-tainacan'),
                        $result['state']['total_items']
                    ),
                    'state' => $batch_result['state'] ?? $result['state'],
                    'batch_processing' => true,
                    'completed' => $batch_result['completed'] ?? false
                ));
            } else {
                wp_send_json_error(array('message' => $result['message']));
            }
            return;
        }

        // Fallback para indexação simples
        try {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-rag-engine.php';
            $rag_engine = new Oraculo_Tainacan_RAG_Engine();
            
            set_time_limit(300);
            
            $result = $rag_engine->index_collection($collection_id, $force_update);

            if ($result === false) {
                wp_send_json_error(array('message' => __('Falha na indexação.', 'oraculo-tainacan')));
            }

            $result = wp_parse_args($result, array(
                'total' => 0,
                'success' => 0,
                'failed' => 0
            ));

            wp_send_json_success(array(
                'message' => sprintf(
                    __('Indexação concluída. %d itens processados: %d com sucesso, %d falhas.', 'oraculo-tainacan'),
                    $result['total'],
                    $result['success'],
                    $result['failed']
                ),
                'details' => $result
            ));
            
        } catch (Exception $e) {
            error_log('[Oráculo Tainacan] Erro na indexação: ' . $e->getMessage());
            wp_send_json_error(array('message' => __('Erro: ', 'oraculo-tainacan') . $e->getMessage()));
        }
    }

    /**
     * Processa próximo lote de indexação via AJAX
     */
    public function ajax_index_collection_batch() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada.', 'oraculo-tainacan')));
        }

        $collection_id = isset($_POST['collection_id']) ? (int) $_POST['collection_id'] : 0;
        $batch_size = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 10;
        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
        $force_update = isset($_POST['force_update']) ? (bool) $_POST['force_update'] : false;

        if (empty($collection_id)) {
            wp_send_json_error(array('message' => __('ID de coleção inválido.', 'oraculo-tainacan')));
        }

        // Se temos o gerenciador de indexação, usa ele
        if ($this->indexing_manager) {
            $result = $this->indexing_manager->process_next_batch($collection_id);
            
            if ($result['success']) {
                wp_send_json_success(array(
                    'total' => $result['state']['processed'],
                    'success' => $result['state']['indexed'],
                    'failed' => $result['state']['failed'],
                    'skipped' => $result['state']['skipped'],
                    'has_more' => !$result['completed'],
                    'next_offset' => $result['state']['processed'],
                    'message' => $result['message'],
                    'state' => $result['state']
                ));
            } else {
                wp_send_json_error(array('message' => $result['message']));
            }
            return;
        }

        // Fallback para processamento simples em lote
        try {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-rag-engine.php';
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-tainacan-parser.php';
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-openai-client.php';
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-vector-db.php';
            
            $parser = new Oraculo_Tainacan_Parser();
            $openai = new Oraculo_Tainacan_OpenAI_Client();
            $vector_db = new Oraculo_Tainacan_Vector_DB();
            
            // Obtém itens do lote
            $items = $parser->get_collection_items($collection_id, $batch_size, ($offset / $batch_size) + 1);
            
            if (is_wp_error($items)) {
                wp_send_json_error(array('message' => $items->get_error_message()));
            }
            
            $success_count = 0;
            $failed_count = 0;
            $skipped_count = 0;
            
            // Processa cada item
            foreach ($items as $item) {
                try {
                    // Verifica se já existe
                    if (!$force_update && $vector_db->item_exists($item['id'], $collection_id)) {
                        $skipped_count++;
                        continue;
                    }
                    
                    // Formata texto para embedding
                    $text = $parser->format_item_for_embedding($item);
                    
                    // Gera embedding
                    $embedding = $openai->generate_embedding($text);
                    
                    if (is_wp_error($embedding)) {
                        $failed_count++;
                        continue;
                    }
                    
                    // Armazena no banco
                    $stored = $vector_db->store_vector(array(
                        'item_id' => $item['id'],
                        'collection_id' => $collection_id,
                        'collection_name' => '',
                        'vector' => $embedding,
                        'content' => $text,
                        'permalink' => $item['url'] ?? ''
                    ));
                    
                    if ($stored) {
                        $success_count++;
                    } else {
                        $failed_count++;
                    }
                    
                } catch (Exception $e) {
                    $failed_count++;
                    error_log('[Oráculo] Erro ao indexar item ' . $item['id'] . ': ' . $e->getMessage());
                }
            }
            
            $has_more = count($items) >= $batch_size;
            
            wp_send_json_success(array(
                'total' => count($items),
                'success' => $success_count,
                'failed' => $failed_count,
                'skipped' => $skipped_count,
                'has_more' => $has_more,
                'next_offset' => $offset + count($items),
                'message' => sprintf(
                    __('%d itens processados neste lote.', 'oraculo-tainacan'),
                    count($items)
                )
            ));
            
        } catch (Exception $e) {
            error_log('[Oráculo Tainacan] Erro no processamento em lote: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Executa uma busca de teste via AJAX
     */
    public function ajax_test_search() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada.', 'oraculo-tainacan')));
        }

        $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
        $collections = isset($_POST['collections']) ? array_map('intval', $_POST['collections']) : array();

        if (empty($query)) {
            wp_send_json_error(array('message' => __('Consulta vazia.', 'oraculo-tainacan')));
        }

        try {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-rag-engine.php';
            $rag_engine = new Oraculo_Tainacan_RAG_Engine();

            $result = $rag_engine->debug_search($query, $collections);

            wp_send_json_success(array(
                'debug_info' => $result
            ));
            
        } catch (Exception $e) {
            error_log('[Oráculo Tainacan] Erro na busca de teste: ' . $e->getMessage());
            wp_send_json_error(array('message' => __('Erro: ', 'oraculo-tainacan') . $e->getMessage()));
        }
    }

    /**
     * Exporta dados via AJAX
     */
    public function ajax_export_data() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada.', 'oraculo-tainacan')));
        }

        $export_type = isset($_POST['export_type']) ? sanitize_text_field($_POST['export_type']) : '';
        $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'json';
        $filters = isset($_POST['filters']) ? $_POST['filters'] : array();

        if (empty($export_type)) {
            wp_send_json_error(array('message' => __('Tipo de exportação inválido.', 'oraculo-tainacan')));
        }

        try {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-vector-db.php';
            $vector_db = new Oraculo_Tainacan_Vector_DB();

            $data = array();

            if ($export_type === 'search_history') {
                $data = $vector_db->get_search_history($filters);
            } elseif ($export_type === 'training_data') {
                $filters['feedback'] = 1;
                $data = $vector_db->get_search_history($filters);
            }

            if ($format === 'csv') {
                $csv_data = $this->convert_to_csv($data, $export_type);
                wp_send_json_success(array(
                    'format' => 'csv',
                    'data' => $csv_data,
                    'filename' => 'oraculo-' . $export_type . '-' . date('Y-m-d') . '.csv'
                ));
            } else {
                wp_send_json_success(array(
                    'format' => 'json',
                    'data' => json_encode($data, JSON_PRETTY_PRINT),
                    'filename' => 'oraculo-' . $export_type . '-' . date('Y-m-d') . '.json'
                ));
            }
            
        } catch (Exception $e) {
            error_log('[Oráculo Tainacan] Erro na exportação: ' . $e->getMessage());
            wp_send_json_error(array('message' => __('Erro: ', 'oraculo-tainacan') . $e->getMessage()));
        }
    }

    /**
     * Converte dados para formato CSV
     */
    private function convert_to_csv($data, $type) {
        if (empty($data)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        
        if ($type === 'search_history' || $type === 'training_data') {
            fputcsv($output, array('ID', 'Query', 'Response', 'Created At', 'Feedback'));
            
            foreach ($data as $row) {
                fputcsv($output, array(
                    isset($row['id']) ? $row['id'] : '',
                    isset($row['query']) ? $row['query'] : '',
                    isset($row['response']) ? $row['response'] : '',
                    isset($row['created_at']) ? $row['created_at'] : '',
                    isset($row['feedback']) ? $row['feedback'] : ''
                ));
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
}