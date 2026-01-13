<?php
/**
 * Página administrativa do plugin - Integração com Tainacan
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\Admin;

use Oraculo_Tainacan\AI\AIProviderFactory;

/**
 * Gerencia a página administrativa e integração com menu do Tainacan
 */
class AdminPage {

    /**
     * Slug do menu principal do Tainacan
     */
    private const TAINACAN_MENU_SLUG = 'tainacan_admin';

    /**
     * Slug da página do plugin
     */
    private const PAGE_SLUG = 'oraculo-tainacan';

    /**
     * Hook suffix da página
     * @var string
     */
    private string $page_hook = '';

    /**
     * Construtor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu'], 20);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Registra página no menu do Tainacan (chamado via hook tainacan-register-admin-hooks)
     */
    public function register_tainacan_page(): void {
        // Este método é chamado pelo hook do Tainacan para integração mais profunda
        // A integração principal é feita via admin_menu
    }

    /**
     * Registra menu administrativo
     */
    public function register_admin_menu(): void {
        // Verificar se Tainacan está ativo
        $parent_slug = class_exists('\Tainacan\Plugin') ? self::TAINACAN_MENU_SLUG : 'options-general.php';

        // Registrar como submenu do Tainacan ou como menu separado
        if ($parent_slug === self::TAINACAN_MENU_SLUG) {
            $this->page_hook = add_submenu_page(
                $parent_slug,
                __('Oráculo IA', 'oraculo-tainacan'),
                $this->get_menu_title(),
                'manage_options',
                self::PAGE_SLUG,
                [$this, 'render_page'],
                15
            );
        } else {
            // Menu separado se Tainacan não estiver ativo
            $this->page_hook = add_menu_page(
                __('Oráculo Tainacan', 'oraculo-tainacan'),
                __('Oráculo IA', 'oraculo-tainacan'),
                'manage_options',
                self::PAGE_SLUG,
                [$this, 'render_page'],
                $this->get_menu_icon(),
                30
            );
        }

        // Subpáginas
        add_submenu_page(
            self::PAGE_SLUG,
            __('Dashboard', 'oraculo-tainacan'),
            __('Dashboard', 'oraculo-tainacan'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __('Indexação', 'oraculo-tainacan'),
            __('Indexação', 'oraculo-tainacan'),
            'manage_options',
            self::PAGE_SLUG . '-indexing',
            [$this, 'render_indexing_page']
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __('Configurações', 'oraculo-tainacan'),
            __('Configurações', 'oraculo-tainacan'),
            'manage_options',
            self::PAGE_SLUG . '-settings',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __('Analytics', 'oraculo-tainacan'),
            __('Analytics', 'oraculo-tainacan'),
            'manage_options',
            self::PAGE_SLUG . '-analytics',
            [$this, 'render_analytics_page']
        );

        // Página oculta para debug (apenas em modo debug)
        $options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
        if (!empty($options['debug_mode'])) {
            add_submenu_page(
                self::PAGE_SLUG,
                __('Debug', 'oraculo-tainacan'),
                __('Debug', 'oraculo-tainacan'),
                'manage_options',
                self::PAGE_SLUG . '-debug',
                [$this, 'render_debug_page']
            );
        }

        // Enqueue assets na página
        add_action('load-' . $this->page_hook, [$this, 'load_page_assets']);
    }

    /**
     * Obtém título do menu com ícone
     *
     * @return string
     */
    private function get_menu_title(): string {
        $icon = $this->get_menu_icon_svg();
        return '<span class="oraculo-menu-icon">' . $icon . '</span>' .
               '<span class="menu-text">' . __('Oráculo IA', 'oraculo-tainacan') . '</span>';
    }

    /**
     * Obtém ícone SVG para o menu
     *
     * @return string
     */
    private function get_menu_icon_svg(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
            <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/>
            <path d="M12 6v2M12 16v2M6 12h2M16 12h2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
        </svg>';
    }

    /**
     * Obtém ícone base64 para o menu
     *
     * @return string
     */
    private function get_menu_icon(): string {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#a7aaad">
            <circle cx="12" cy="12" r="10" fill="none" stroke="#a7aaad" stroke-width="2"/>
            <circle cx="12" cy="12" r="4" fill="#a7aaad"/>
            <path d="M12 2v4M12 18v4M2 12h4M18 12h4" stroke="#a7aaad" stroke-width="2" stroke-linecap="round"/>
        </svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Carrega assets da página
     */
    public function load_page_assets(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_page_assets']);
    }

    /**
     * Enfileira assets específicos da página
     */
    public function enqueue_page_assets(): void {
        // Chart.js para gráficos
        wp_enqueue_script(
            'chart-js',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
            [],
            '4.4.1',
            true
        );

        // Estilos da página admin
        wp_enqueue_style(
            'oraculo-admin-page',
            ORACULO_TAINACAN_URL . 'assets/css/admin-page.css',
            [],
            ORACULO_TAINACAN_VERSION
        );

        // Scripts da página admin
        wp_enqueue_script(
            'oraculo-admin-page',
            ORACULO_TAINACAN_URL . 'assets/js/admin-page.js',
            ['jquery', 'chart-js', 'wp-util'],
            ORACULO_TAINACAN_VERSION,
            true
        );

        // Dados para JS
        $factory = new AIProviderFactory();

        wp_localize_script('oraculo-admin-page', 'OraculoAdminPage', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('oraculo/v1/'),
            'nonce' => wp_create_nonce('oraculo_admin'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'providers' => $factory->get_available_providers(),
            'currentProvider' => $factory->get_current_provider(),
            'collections' => \Oraculo_Tainacan\get_tainacan_collections(),
            'options' => $this->get_safe_options(),
            'strings' => $this->get_js_strings(),
        ]);
    }

    /**
     * Obtém opções seguras para JS (sem API keys)
     *
     * @return array
     */
    private function get_safe_options(): array {
        $options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

        // Remover chaves sensíveis
        $sensitive_keys = [
            'openai_api_key', 'gemini_api_key', 'deepseek_api_key',
            'groq_api_key', 'claude_api_key'
        ];

        foreach ($sensitive_keys as $key) {
            if (isset($options[$key])) {
                $options[$key] = !empty($options[$key]) ? '••••••••' : '';
            }
        }

        return $options;
    }

    /**
     * Obtém strings para JS
     *
     * @return array
     */
    private function get_js_strings(): array {
        return [
            'confirmDelete' => __('Tem certeza que deseja excluir?', 'oraculo-tainacan'),
            'confirmReindex' => __('Tem certeza que deseja reindexar esta coleção? Isso pode levar alguns minutos.', 'oraculo-tainacan'),
            'indexing' => __('Indexando...', 'oraculo-tainacan'),
            'processing' => __('Processando...', 'oraculo-tainacan'),
            'completed' => __('Concluído!', 'oraculo-tainacan'),
            'error' => __('Erro:', 'oraculo-tainacan'),
            'testing' => __('Testando conexão...', 'oraculo-tainacan'),
            'success' => __('Sucesso!', 'oraculo-tainacan'),
            'saved' => __('Configurações salvas!', 'oraculo-tainacan'),
            'items' => __('itens', 'oraculo-tainacan'),
            'of' => __('de', 'oraculo-tainacan'),
            'cancel' => __('Cancelar', 'oraculo-tainacan'),
            'save' => __('Salvar', 'oraculo-tainacan'),
        ];
    }

    /**
     * Registra configurações
     */
    public function register_settings(): void {
        register_setting('oraculo_tainacan', 'oraculo_tainacan_options', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    /**
     * Sanitiza configurações
     *
     * @param array $input
     * @return array
     */
    public function sanitize_settings(array $input): array {
        $sanitized = [];

        // Provider
        $sanitized['ai_provider'] = sanitize_text_field($input['ai_provider'] ?? 'openai');

        // API Keys - só atualizar se não for placeholder
        $api_keys = [
            'openai_api_key', 'gemini_api_key', 'deepseek_api_key',
            'groq_api_key', 'claude_api_key'
        ];

        $current_options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

        foreach ($api_keys as $key) {
            if (isset($input[$key]) && $input[$key] !== '••••••••' && !empty($input[$key])) {
                $sanitized[$key] = sanitize_text_field($input[$key]);
            } else {
                $sanitized[$key] = $current_options[$key] ?? '';
            }
        }

        // Modelos
        $sanitized['openai_model'] = sanitize_text_field($input['openai_model'] ?? 'gpt-4o-mini');
        $sanitized['openai_embedding_model'] = sanitize_text_field($input['openai_embedding_model'] ?? 'text-embedding-ada-002');
        $sanitized['gemini_model'] = sanitize_text_field($input['gemini_model'] ?? 'gemini-1.5-flash');
        $sanitized['deepseek_model'] = sanitize_text_field($input['deepseek_model'] ?? 'deepseek-chat');
        $sanitized['ollama_url'] = esc_url_raw($input['ollama_url'] ?? 'http://localhost:11434');
        $sanitized['ollama_model'] = sanitize_text_field($input['ollama_model'] ?? 'llama3.2');
        $sanitized['ollama_embedding_model'] = sanitize_text_field($input['ollama_embedding_model'] ?? 'nomic-embed-text');
        $sanitized['groq_model'] = sanitize_text_field($input['groq_model'] ?? 'llama-3.3-70b-versatile');
        $sanitized['claude_model'] = sanitize_text_field($input['claude_model'] ?? 'claude-3-5-sonnet-latest');

        // Parâmetros numéricos
        $sanitized['max_tokens'] = absint($input['max_tokens'] ?? 2000);
        $sanitized['max_tokens'] = max(100, min(16000, $sanitized['max_tokens']));

        $sanitized['temperature'] = floatval($input['temperature'] ?? 0.7);
        $sanitized['temperature'] = max(0, min(2, $sanitized['temperature']));

        $sanitized['similarity_threshold'] = floatval($input['similarity_threshold'] ?? 0.3);
        $sanitized['similarity_threshold'] = max(0, min(1, $sanitized['similarity_threshold']));

        $sanitized['max_results'] = absint($input['max_results'] ?? 10);
        $sanitized['max_results'] = max(1, min(50, $sanitized['max_results']));

        $sanitized['batch_size'] = absint($input['batch_size'] ?? 25);
        $sanitized['batch_size'] = max(5, min(100, $sanitized['batch_size']));

        $sanitized['request_timeout'] = absint($input['request_timeout'] ?? 120);
        $sanitized['cache_duration'] = absint($input['cache_duration'] ?? 3600);

        // Booleanos
        $sanitized['enable_chat'] = !empty($input['enable_chat']);
        $sanitized['enable_search'] = !empty($input['enable_search']);
        $sanitized['enable_analytics'] = !empty($input['enable_analytics']);
        $sanitized['enable_feedback'] = !empty($input['enable_feedback']);
        $sanitized['debug_mode'] = !empty($input['debug_mode']);

        // Coleções
        if (isset($input['default_collections'])) {
            $sanitized['default_collections'] = array_map('absint', (array)$input['default_collections']);
        }

        // Prompts
        $sanitized['system_prompt'] = wp_kses_post($input['system_prompt'] ?? '');
        $sanitized['search_prompt'] = wp_kses_post($input['search_prompt'] ?? '');
        $sanitized['chat_prompt'] = wp_kses_post($input['chat_prompt'] ?? '');
        $sanitized['welcome_message'] = sanitize_textarea_field($input['welcome_message'] ?? '');

        // Perguntas sugeridas
        if (isset($input['suggested_questions'])) {
            $sanitized['suggested_questions'] = array_map('sanitize_text_field', (array)$input['suggested_questions']);
            $sanitized['suggested_questions'] = array_filter($sanitized['suggested_questions']);
        }

        // Campos de indexação
        if (isset($input['index_fields'])) {
            $sanitized['index_fields'] = array_map('sanitize_text_field', (array)$input['index_fields']);
        }

        // Aparência
        if (isset($input['appearance'])) {
            $sanitized['appearance'] = \Oraculo_Tainacan\sanitize_appearance($input['appearance']);
        }

        return $sanitized;
    }

    /**
     * Renderiza página principal (Dashboard)
     */
    public function render_page(): void {
        include ORACULO_TAINACAN_PATH . 'templates/admin/dashboard.php';
    }

    /**
     * Renderiza página de indexação
     */
    public function render_indexing_page(): void {
        include ORACULO_TAINACAN_PATH . 'templates/admin/indexing.php';
    }

    /**
     * Renderiza página de configurações
     */
    public function render_settings_page(): void {
        include ORACULO_TAINACAN_PATH . 'templates/admin/settings.php';
    }

    /**
     * Renderiza página de analytics
     */
    public function render_analytics_page(): void {
        include ORACULO_TAINACAN_PATH . 'templates/admin/analytics.php';
    }

    /**
     * Renderiza página de debug
     */
    public function render_debug_page(): void {
        include ORACULO_TAINACAN_PATH . 'templates/admin/debug.php';
    }

    /**
     * Obtém estatísticas rápidas para o dashboard
     *
     * @return array
     */
    public function get_dashboard_stats(): array {
        global $wpdb;

        $vectors_table = $wpdb->prefix . 'oraculo_vectors';
        $logs_table = $wpdb->prefix . 'oraculo_search_logs';

        // Total de itens indexados
        $total_indexed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$vectors_table}");

        // Coleções indexadas
        $collections_indexed = (int) $wpdb->get_var("SELECT COUNT(DISTINCT collection_id) FROM {$vectors_table}");

        // Buscas hoje
        $searches_today = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$logs_table} WHERE DATE(created_at) = %s",
            current_time('Y-m-d')
        ));

        // Buscas este mês
        $searches_month = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$logs_table} WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d",
            current_time('n'),
            current_time('Y')
        ));

        // Taxa de feedback positivo
        $positive_feedback = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$logs_table} WHERE feedback = 'positive'"
        );
        $total_feedback = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$logs_table} WHERE feedback IS NOT NULL"
        );
        $satisfaction_rate = $total_feedback > 0
            ? round(($positive_feedback / $total_feedback) * 100, 1)
            : 0;

        // Tokens usados este mês
        $tokens_month = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(tokens_used) FROM {$logs_table} WHERE MONTH(created_at) = %d AND YEAR(created_at) = %d",
            current_time('n'),
            current_time('Y')
        ));

        return [
            'total_indexed' => $total_indexed,
            'collections_indexed' => $collections_indexed,
            'searches_today' => $searches_today,
            'searches_month' => $searches_month,
            'satisfaction_rate' => $satisfaction_rate,
            'tokens_month' => $tokens_month,
        ];
    }

    /**
     * Obtém status de indexação de todas as coleções
     *
     * @return array
     */
    public function get_indexing_status(): array {
        global $wpdb;

        $vectors_table = $wpdb->prefix . 'oraculo_vectors';
        $jobs_table = $wpdb->prefix . 'oraculo_indexing_jobs';

        $collections = \Oraculo_Tainacan\get_tainacan_collections();
        $status = [];

        foreach ($collections as $collection) {
            $indexed_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$vectors_table} WHERE collection_id = %d",
                $collection['id']
            ));

            $job = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$jobs_table} WHERE collection_id = %d ORDER BY id DESC LIMIT 1",
                $collection['id']
            ), ARRAY_A);

            $status[] = [
                'collection_id' => $collection['id'],
                'collection_name' => $collection['name'],
                'total_items' => $collection['items_count'],
                'indexed_items' => $indexed_count,
                'percentage' => $collection['items_count'] > 0
                    ? round(($indexed_count / $collection['items_count']) * 100, 1)
                    : 0,
                'job_status' => $job['status'] ?? 'none',
                'last_indexed' => $job['completed_at'] ?? null,
            ];
        }

        return $status;
    }
}
