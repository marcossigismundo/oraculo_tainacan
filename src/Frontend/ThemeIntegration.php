<?php
/**
 * Integração com o tema Tainacan
 *
 * Insere uma aba de "Busca com IA" junto ao campo de busca padrão das
 * listagens de itens do Tainacan (coleções, repositório, termos de taxonomia
 * e bloco de busca facetada). A listagem de itens do Tainacan é uma instância
 * Vue.js montada pelo próprio plugin Tainacan (tainacan_the_faceted_search),
 * portanto a injeção da aba acontece no client-side, sobre o DOM renderizado,
 * respeitando as variáveis CSS --tainacan-* do tema ativo.
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\Frontend;

// Impedir acesso direto
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gerencia a injeção da busca por IA nas páginas de listagem do Tainacan
 *
 * @since 2.1.0
 */
class ThemeIntegration {

    /**
     * Handle dos assets
     */
    private const SCRIPT_HANDLE = 'oraculo-theme-integration';

    /**
     * Construtor: registra hooks
     */
    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 20);
    }

    /**
     * Configurações padrão da integração
     *
     * @return array
     */
    public static function get_default_settings(): array {
        return [
            'enabled' => true,
            'tab_label' => __('Busca com IA', 'oraculo_tainacan'),
            'placeholder' => __('Pergunte em linguagem natural ao acervo…', 'oraculo_tainacan'),
            'scope' => 'collection',
            'show_suggestions' => true,
        ];
    }

    /**
     * Configurações efetivas (salvas + padrão)
     *
     * @return array
     */
    public function get_settings(): array {
        $options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
        $saved = $options['theme_integration'] ?? [];

        if (!is_array($saved)) {
            $saved = [];
        }

        return wp_parse_args($saved, self::get_default_settings());
    }

    /**
     * Verifica se o Tainacan está ativo
     *
     * @return bool
     */
    private function is_tainacan_available(): bool {
        return class_exists('\Tainacan\Theme_Helper');
    }

    /**
     * Verifica se a página atual renderiza uma listagem de itens do Tainacan
     *
     * Cobre os quatro contextos em que tainacan_the_faceted_search() é usado:
     * arquivo de itens de coleção, arquivo do repositório, arquivo de termo de
     * taxonomia Tainacan e páginas com o bloco/shortcode de busca facetada.
     *
     * @return bool
     */
    private function is_tainacan_items_page(): bool {
        if (!$this->is_tainacan_available()) {
            return false;
        }

        $theme_helper = \Tainacan\Theme_Helper::get_instance();

        // Arquivo de itens de uma coleção (post type tnc_col_{id}_item)
        if (is_post_type_archive() && $theme_helper->is_post_type_a_collection((string) get_post_type())) {
            return true;
        }

        // Arquivo de itens do repositório (todos os itens de todas as coleções)
        if (is_archive() && (int) get_query_var('tainacan_repository_archive') === 1) {
            return true;
        }

        // Arquivo de termo de uma taxonomia do Tainacan
        if (is_tax()) {
            $term = get_queried_object();
            if (isset($term->taxonomy) && $theme_helper->is_taxonomy_a_tainacan_tax($term->taxonomy)) {
                return true;
            }
        }

        // Página/post com o bloco de busca facetada ou shortcode [tainacan-search]
        if (is_singular()) {
            $post = get_post();
            if ($post instanceof \WP_Post) {
                if (has_block('tainacan/faceted-search', $post)) {
                    return true;
                }
                if (has_shortcode((string) $post->post_content, 'tainacan-search')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * ID da coleção atual (0 quando em nível de repositório)
     *
     * @return int
     */
    private function get_current_collection_id(): int {
        if (function_exists('tainacan_get_collection_id')) {
            $collection_id = tainacan_get_collection_id();
            if (!empty($collection_id)) {
                return absint($collection_id);
            }
        }
        return 0;
    }

    /**
     * Enfileira assets somente nas listagens do Tainacan com a integração ativa
     */
    public function enqueue_assets(): void {
        $options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

        // Busca por IA precisa estar habilitada globalmente
        if (empty($options['enable_search'])) {
            return;
        }

        $settings = $this->get_settings();

        if (empty($settings['enabled'])) {
            return;
        }

        if (!$this->is_tainacan_items_page()) {
            return;
        }

        wp_enqueue_style(
            self::SCRIPT_HANDLE,
            ORACULO_TAINACAN_URL . 'assets/css/theme-integration.css',
            [],
            ORACULO_TAINACAN_VERSION
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            ORACULO_TAINACAN_URL . 'assets/js/theme-integration.js',
            [],
            ORACULO_TAINACAN_VERSION,
            true
        );

        // Garante as variáveis de cor do plugin mesmo se o CSS geral mudar
        $appearance = $options['appearance'] ?? [];
        $custom_css = ':root{--oraculo-primary:' . esc_attr($appearance['primary_color'] ?? '#1f2f56') . ';--oraculo-accent:' . esc_attr($appearance['accent_color'] ?? '#b5e0e3') . ';}';
        wp_add_inline_style(self::SCRIPT_HANDLE, $custom_css);

        $suggested_questions = $options['suggested_questions'] ?? [];
        if (!is_array($suggested_questions)) {
            $suggested_questions = [];
        }

        $collection_id = $this->get_current_collection_id();
        $default_collections = array_map('absint', (array) ($options['default_collections'] ?? []));

        // Escopo da busca: coleção atual (quando houver) ou repositório inteiro
        $collections = [];
        if ('collection' === $settings['scope'] && $collection_id > 0) {
            $collections = [$collection_id];
        } elseif (!empty($default_collections)) {
            $collections = $default_collections;
        }

        $collection_name = '';
        if ($collection_id > 0 && function_exists('tainacan_get_the_collection_name')) {
            $collection_name = wp_strip_all_tags((string) tainacan_get_the_collection_name());
        }

        wp_localize_script(self::SCRIPT_HANDLE, 'OraculoTainacanTheme', [
            'restUrl' => esc_url_raw(rest_url('oraculo/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'tabLabel' => $settings['tab_label'],
            'placeholder' => $settings['placeholder'],
            'scope' => $settings['scope'],
            'showSuggestions' => !empty($settings['show_suggestions']),
            'suggestions' => array_values(array_filter(array_map('strval', $suggested_questions))),
            'collections' => $collections,
            'collectionId' => $collection_id,
            'collectionName' => $collection_name,
            'maxResults' => absint($options['max_results'] ?? 10),
            'showSources' => !empty($options['appearance']['show_sources'] ?? true),
            'showSimilarity' => !empty($options['appearance']['show_similarity'] ?? false),
            'enableFeedback' => !empty($options['enable_feedback']),
            'strings' => [
                'nativeTab' => __('Busca', 'oraculo_tainacan'),
                'aiTabAria' => __('Alternar para a busca com inteligência artificial', 'oraculo_tainacan'),
                'nativeTabAria' => __('Alternar para a busca padrão', 'oraculo_tainacan'),
                'searchAria' => __('Buscar com inteligência artificial', 'oraculo_tainacan'),
                'submit' => __('Perguntar', 'oraculo_tainacan'),
                'panelTitle' => __('Resposta do Oráculo', 'oraculo_tainacan'),
                'close' => __('Fechar resposta', 'oraculo_tainacan'),
                'loading1' => __('Interpretando sua pergunta…', 'oraculo_tainacan'),
                'loading2' => __('Buscando itens no acervo…', 'oraculo_tainacan'),
                'loading3' => __('Elaborando a resposta…', 'oraculo_tainacan'),
                'searchingIn' => __('Buscando em:', 'oraculo_tainacan'),
                'wholeRepository' => __('Todo o acervo', 'oraculo_tainacan'),
                'sources' => __('Itens do acervo relacionados', 'oraculo_tainacan'),
                'relevance' => __('relevância', 'oraculo_tainacan'),
                'helpful' => __('Esta resposta foi útil?', 'oraculo_tainacan'),
                'yes' => __('Sim', 'oraculo_tainacan'),
                'no' => __('Não', 'oraculo_tainacan'),
                'thanks' => __('Obrigado pelo feedback!', 'oraculo_tainacan'),
                'error' => __('Não foi possível concluir a busca. Tente novamente.', 'oraculo_tainacan'),
                'noResults' => __('Nenhum item relacionado foi encontrado. Tente reformular a pergunta.', 'oraculo_tainacan'),
                'suggestionsTitle' => __('Experimente perguntar', 'oraculo_tainacan'),
                'useNativeSearch' => __('Refinar na busca tradicional', 'oraculo_tainacan'),
                'newQuestion' => __('Nova pergunta', 'oraculo_tainacan'),
                'poweredBy' => __('Busca inteligente por Oráculo Tainacan', 'oraculo_tainacan'),
                'responseTime' => __('Respondido em %ss', 'oraculo_tainacan'),
            ],
        ]);
    }
}
