<?php
/**
 * Plugin Name: Oráculo Tainacan
 * Plugin URI: https://github.com/seu-usuario/oraculo-tainacan
 * Description: Sistema de busca inteligente baseado em RAG integrado ao Tainacan.
 * Version: 2.0.0
 * Author: Seu Nome
 * License: GPL v2 or later
 * Text Domain: oraculo-tainacan
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Proteção contra acesso direto
if (!defined('WPINC')) {
    die;
}

// Constantes do plugin
define('ORACULO_TAINACAN_VERSION', '2.0.0');
define('ORACULO_TAINACAN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ORACULO_TAINACAN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ORACULO_TAINACAN_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Classe principal do plugin
 */
class Oraculo_Tainacan {
    
    private static $instance = null;
    private $dependencies_loaded = false;
    private $ajax_handler = null;
    
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Carrega dependências primeiro
        $this->load_core_dependencies();
        
        // Hooks de ativação/desativação
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Inicializa após plugins carregados
        add_action('plugins_loaded', array($this, 'init'), 10);
    }
    
    /**
     * Carrega apenas as dependências essenciais
     */
    private function load_core_dependencies() {
        $required_files = array(
            'includes/class-vector-db.php',
            'includes/class-tainacan-parser.php',
            'includes/class-openai-client.php',
            'includes/class-rag-engine.php',
            'includes/class-indexing-manager.php',
            'includes/class-ajax-indexing-handler.php',
            'includes/class-admin-menu.php' // Adiciona o admin menu aqui
        );
        
        foreach ($required_files as $file) {
            $filepath = ORACULO_TAINACAN_PLUGIN_DIR . $file;
            if (file_exists($filepath)) {
                require_once $filepath;
            } else {
                error_log('[Oráculo] Arquivo não encontrado: ' . $file);
            }
        }
        
        $this->dependencies_loaded = true;
    }
    
    /**
     * Inicialização principal
     */
    public function init() {
        if (!$this->dependencies_loaded) {
            return;
        }
        
        // Carrega traduções
        load_plugin_textdomain('oraculo-tainacan', false, dirname(plugin_basename(__FILE__)) . '/languages/');
        
        // Inicializa componentes
        $this->init_components();
        
        // Hooks do WordPress
        $this->init_hooks();
    }
    
    /**
     * Inicializa componentes do plugin
     */
    private function init_components() {
        // Inicializa o handler AJAX de indexação
        if (class_exists('Oraculo_Tainacan_Ajax_Indexing_Handler')) {
            $this->ajax_handler = new Oraculo_Tainacan_Ajax_Indexing_Handler();
        }
        
        // Admin Menu - carrega sempre no admin
        if (is_admin() && class_exists('Oraculo_Tainacan_Admin_Menu')) {
            $admin_menu = new Oraculo_Tainacan_Admin_Menu();
            $admin_menu->init();
        }
        
        // CLI Commands - apenas se arquivo existir
        if (defined('WP_CLI') && WP_CLI) {
            $cli_file = ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-cli-commands.php';
            if (file_exists($cli_file)) {
                require_once $cli_file;
                WP_CLI::add_command('oraculo', 'Oraculo_Tainacan_CLI_Commands');
            }
        }
    }
    
    /**
     * Registra hooks do WordPress
     */
    private function init_hooks() {
        // Scripts e estilos admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Scripts e estilos frontend
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        
        // API REST
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Hooks do Tainacan (se disponível)
        add_action('tainacan-insert-tainacan-item', array($this, 'on_item_created'), 10, 1);
        add_action('tainacan-update-tainacan-item', array($this, 'on_item_updated'), 10, 1);
        add_action('tainacan-delete-tainacan-item', array($this, 'on_item_deleted'), 10, 1);
    }
    
    /**
     * Scripts e estilos admin
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'oraculo-tainacan') === false) {
            return;
        }
        
        // CSS
        wp_enqueue_style(
            'oraculo-admin',
            ORACULO_TAINACAN_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            ORACULO_TAINACAN_VERSION
        );
        
        wp_enqueue_style(
            'oraculo-indexing',
            ORACULO_TAINACAN_PLUGIN_URL . 'assets/css/indexing-styles.css',
            array(),
            ORACULO_TAINACAN_VERSION
        );
        
        // JavaScript
        wp_enqueue_script(
            'oraculo-admin',
            ORACULO_TAINACAN_PLUGIN_URL . 'assets/js/admin-script.js',
            array('jquery'),
            ORACULO_TAINACAN_VERSION,
            true
        );
        
        wp_enqueue_script(
            'oraculo-indexing-manager',
            ORACULO_TAINACAN_PLUGIN_URL . 'assets/js/indexing-manager.js',
            array('jquery'),
            ORACULO_TAINACAN_VERSION,
            true
        );
        
        // Localização
        wp_localize_script('oraculo-admin', 'oraculo_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oraculo_admin_nonce'),
            'strings' => array(
                'indexing' => __('Indexando...', 'oraculo-tainacan'),
                'error' => __('Erro', 'oraculo-tainacan'),
                'success' => __('Sucesso', 'oraculo-tainacan'),
                'confirm_cancel' => __('Cancelar indexação em andamento?', 'oraculo-tainacan')
            )
        ));
        
        wp_localize_script('oraculo-indexing-manager', 'oraculo_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oraculo_admin_nonce')
        ));
    }
    
    /**
     * Scripts e estilos frontend
     */
    public function enqueue_frontend_assets() {
        if (!is_singular() && !is_archive()) {
            return;
        }
        
        wp_enqueue_style(
            'oraculo-frontend',
            ORACULO_TAINACAN_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            ORACULO_TAINACAN_VERSION
        );
        
        wp_enqueue_script(
            'oraculo-frontend',
            ORACULO_TAINACAN_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            ORACULO_TAINACAN_VERSION,
            true
        );
        
        wp_localize_script('oraculo-frontend', 'oraculo_frontend', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('oraculo_frontend_nonce')
        ));
    }
    
    /**
     * Registra rotas da API REST
     */
    public function register_rest_routes() {
        register_rest_route('oraculo/v1', '/search', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_search'),
            'permission_callback' => '__return_true'
        ));
        
        register_rest_route('oraculo/v1', '/index/(?P<collection_id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_index_collection'),
            'permission_callback' => array($this, 'rest_permission_check')
        ));
    }
    
    /**
     * Endpoint REST de busca
     */
    public function rest_search($request) {
        $query = $request->get_param('query');
        $collections = $request->get_param('collections');
        
        if (empty($query)) {
            return new WP_Error('missing_query', __('Query obrigatória', 'oraculo-tainacan'), array('status' => 400));
        }
        
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-rag-engine.php';
        $rag_engine = new Oraculo_Tainacan_RAG_Engine();
        
        $result = $rag_engine->search($query, $collections);
        
        return rest_ensure_response($result);
    }
    
    /**
     * Endpoint REST para indexação
     */
    public function rest_index_collection($request) {
        $collection_id = $request->get_param('collection_id');
        
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-indexing-manager.php';
        $indexing_manager = new Oraculo_Tainacan_Indexing_Manager();
        
        $result = $indexing_manager->start_indexing($collection_id);
        
        return rest_ensure_response($result);
    }
    
    /**
     * Verifica permissões REST
     */
    public function rest_permission_check() {
        return current_user_can('manage_options');
    }
    
    /**
     * Quando um item é criado
     */
    public function on_item_created($item) {
        $this->index_single_item($item);
    }
    
    /**
     * Quando um item é atualizado
     */
    public function on_item_updated($item) {
        $this->index_single_item($item, true);
    }
    
    /**
     * Quando um item é deletado
     */
    public function on_item_deleted($item) {
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-vector-db.php';
        $vector_db = new Oraculo_Tainacan_Vector_DB();
        $vector_db->delete_item_vectors($item->get_id());
    }
    
    /**
     * Indexa um único item
     */
    private function index_single_item($item, $update = false) {
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-rag-engine.php';
        $rag_engine = new Oraculo_Tainacan_RAG_Engine();
        $rag_engine->index_item($item, $update);
    }
    
    /**
     * Ativação do plugin
     */
    public function activate() {
        // Cria tabelas
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-vector-db.php';
        $vector_db = new Oraculo_Tainacan_Vector_DB();
        $vector_db->create_table();
        
        // Define opções padrão
        add_option('oraculo_tainacan_openai_api_key', '');
        add_option('oraculo_tainacan_model', 'gpt-3.5-turbo');
        add_option('oraculo_tainacan_embedding_model', 'text-embedding-ada-002');
        add_option('oraculo_tainacan_collections', array());
        add_option('oraculo_tainacan_batch_size', 10);
        add_option('oraculo_tainacan_cache_ttl', 3600);
        
        // Limpa cache
        flush_rewrite_rules();
    }
    
    /**
     * Desativação do plugin
     */
    public function deactivate() {
        // Limpa cron jobs
        wp_clear_scheduled_hook('oraculo_tainacan_index_cron');
        
        // Limpa transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_oraculo_%'");
        
        // Limpa cache
        flush_rewrite_rules();
    }
}

// Inicializa o plugin
Oraculo_Tainacan::get_instance();