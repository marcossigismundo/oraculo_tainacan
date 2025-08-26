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
        if (file_exists(ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/settings-page.php')) {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/settings-page.php';
        } else {
            echo '<div class="wrap"><h1>Configurações</h1><p>Página de configurações não encontrada.</p></div>';
        }
    }

    public function render_dashboard_page() {
        if (file_exists(ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/dashboard-page.php')) {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/dashboard-page.php';
        } else {
            echo '<div class="wrap"><h1>Dashboard</h1><p>Dashboard não encontrado.</p></div>';
        }
    }

    public function render_training_page() {
        if (file_exists(ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/training-page.php')) {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/training-page.php';
        } else {
            echo '<div class="wrap"><h1>Treinamento</h1><p>Página de treinamento não encontrada.</p></div>';
        }
    }

    public function render_debug_page() {
        if (file_exists(ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/debug-page.php')) {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/admin/debug-page.php';
        } else {
            echo '<div class="wrap"><h1>Debug</h1><p>Página de debug não encontrada.</p></div>';
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
        $force_update = isset($_POST['force_update']) ? filter_var($_POST['force_update'], FILTER_VALIDATE_BOOLEAN) : false;

        if (!$collection_id) {
            wp_send_json_error(array('message' => __('ID da coleção inválido.', 'oraculo-tainacan')));
        }

        if ($this->indexing_manager) {
            $result = $this->indexing_manager->index_collection($collection_id, $force_update);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => __('Indexing Manager não disponível.', 'oraculo-tainacan')));
        }
    }

    /**
     * Indexa uma coleção em lote via AJAX
     */
    public function ajax_index_collection_batch() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada.', 'oraculo-tainacan')));
        }

        $collection_id = isset($_POST['collection_id']) ? (int) $_POST['collection_id'] : 0;
        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 10;

        if (!$collection_id) {
            wp_send_json_error(array('message' => __('ID da coleção inválido.', 'oraculo-tainacan')));
        }

        if ($this->indexing_manager) {
            $result = $this->indexing_manager->index_collection_batch($collection_id, $offset, $limit);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            
            wp_send_json_success($result);
        } else {
            wp_send_json_error(array('message' => __('Indexing Manager não disponível.', 'oraculo-tainacan')));
        }
    }

    /**
     * Testa busca via AJAX
     */
    public function ajax_test_search() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');

        $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
        $collection_id = isset($_POST['collection_id']) ? (int) $_POST['collection_id'] : 0;

        if (empty($query)) {
            wp_send_json_error(array('message' => __('Query não pode estar vazia.', 'oraculo-tainacan')));
        }

        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-rag-engine.php';
        $rag_engine = new Oraculo_Tainacan_RAG_Engine();
        
        $collections = $collection_id ? array($collection_id) : null;
        $result = $rag_engine->search($query, $collections);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    /**
     * Exporta dados via AJAX
     */
    public function ajax_export_data() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada.', 'oraculo-tainacan')));
        }

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'all';

        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-vector-db.php';
        $vector_db = new Oraculo_Tainacan_Vector_DB();
        
        $data = array();
        
        switch ($type) {
            case 'vectors':
                $data = $vector_db->get_all_vectors();
                break;
            case 'config':
                $data = array(
                    'collections' => get_option('oraculo_tainacan_collections', array()),
                    'model' => get_option('oraculo_tainacan_model', 'gpt-3.5-turbo'),
                    'embedding_model' => get_option('oraculo_tainacan_embedding_model', 'text-embedding-ada-002'),
                    'batch_size' => get_option('oraculo_tainacan_batch_size', 10),
                    'cache_ttl' => get_option('oraculo_tainacan_cache_ttl', 3600)
                );
                break;
            default:
                $data = array(
                    'vectors' => $vector_db->get_all_vectors(),
                    'config' => array(
                        'collections' => get_option('oraculo_tainacan_collections', array()),
                        'model' => get_option('oraculo_tainacan_model', 'gpt-3.5-turbo'),
                        'embedding_model' => get_option('oraculo_tainacan_embedding_model', 'text-embedding-ada-002'),
                        'batch_size' => get_option('oraculo_tainacan_batch_size', 10),
                        'cache_ttl' => get_option('oraculo_tainacan_cache_ttl', 3600)
                    )
                );
        }

        wp_send_json_success($data);
    }
}
