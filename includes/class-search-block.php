<?php
/**
 * Search Block Registration and Rendering
 *
 * @package Oraculo_Tainacan
 */

// Impede o acesso direto
if (!defined('WPINC')) {
    die;
}

/**
 * Class for Gutenberg block functionality
 */
class Oraculo_Tainacan_Search_Block {

    /**
     * Initialize the block
     */
    public function init(): void {
        add_action('init', array($this, 'register_block'));
        add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_assets'));
    }

    /**
     * Register the block
     */
    public function register_block(): void {
        if (!function_exists('register_block_type')) {
            return;
        }

        register_block_type('oraculo-tainacan/search', array(
            'attributes' => array(
                'placeholder' => array(
                    'type' => 'string',
                    'default' => __('Ask a question about the collection...', 'oraculo-tainacan')
                ),
                'defaultCollections' => array(
                    'type' => 'array',
                    'default' => array()
                ),
                'showSummary' => array(
                    'type' => 'boolean',
                    'default' => true
                ),
                'showFilters' => array(
                    'type' => 'boolean',
                    'default' => true
                ),
                'maxResults' => array(
                    'type' => 'number',
                    'default' => 10
                )
            ),
            'render_callback' => array($this, 'render_block'),
            'editor_script' => 'oraculo-search-block',
            'editor_style' => 'oraculo-search-block-editor',
            'style' => 'oraculo-search-block'
        ));
    }

    /**
     * Enqueue editor assets
     */
    public function enqueue_editor_assets(): void {
        // Register block script
        wp_register_script(
            'oraculo-search-block',
            ORACULO_TAINACAN_PLUGIN_URL . 'blocks/search-block.js',
            array('wp-blocks', 'wp-element', 'wp-components', 'wp-editor', 'wp-i18n'),
            ORACULO_TAINACAN_VERSION,
            true
        );

        // Get collections for the block
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-tainacan-parser.php';
        $parser = new Oraculo_Tainacan_Parser();
        $collections = $parser->get_collections();
        $selected_collections = get_option('oraculo_tainacan_collections', array());

        // Filter only selected collections
        $available_collections = array();
        if (!is_wp_error($collections)) {
            foreach ($collections as $collection) {
                if (in_array($collection['id'], $selected_collections)) {
                    $available_collections[] = array(
                        'id' => $collection['id'],
                        'name' => $collection['name']
                    );
                }
            }
        }

        // Pass data to JavaScript
        wp_localize_script('oraculo-search-block', 'oraculoBlockData', array(
            'collections' => $available_collections
        ));

        // Editor styles
        wp_register_style(
            'oraculo-search-block-editor',
            ORACULO_TAINACAN_PLUGIN_URL . 'blocks/editor.css',
            array('wp-edit-blocks'),
            ORACULO_TAINACAN_VERSION
        );
    }

    /**
     * Render the block on frontend
     *
     * @param array $attributes Block attributes
     * @return string Rendered HTML
     */
    public function render_block(array $attributes): string {
        // Extract attributes
        $placeholder = esc_attr($attributes['placeholder'] ?? __('Ask a question about the collection...', 'oraculo-tainacan'));
        $default_collections = $attributes['defaultCollections'] ?? array();
        $show_summary = $attributes['showSummary'] ?? true;
        $show_filters = $attributes['showFilters'] ?? true;
        $max_results = intval($attributes['maxResults'] ?? 10);

        // Get current query if any
        $query = isset($_GET['oraculo_query']) ? sanitize_text_field($_GET['oraculo_query']) : '';
        $selected_collections = isset($_GET['oraculo_collections']) ? array_map('intval', $_GET['oraculo_collections']) : $default_collections;

        // Enqueue frontend assets
        wp_enqueue_style('oraculo-search-block');
        wp_enqueue_script('oraculo-search-block-frontend');

        // Get available collections
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-tainacan-parser.php';
        $parser = new Oraculo_Tainacan_Parser();
        $all_collections = $parser->get_collections();
        $enabled_collections = get_option('oraculo_tainacan_collections', array());

        $collections = array();
        if (!is_wp_error($all_collections)) {
            foreach ($all_collections as $collection) {
                if (in_array($collection['id'], $enabled_collections)) {
                    $collections[] = $collection;
                }
            }
        }

        // Start output buffering
        ob_start();
        ?>
        <div class="oraculo-search-block-container" data-max-results="<?php echo $max_results; ?>">
            <form class="oraculo-search-form" method="get" action="">
                <div class="oraculo-search-input-wrapper">
                    <input type="text" 
                           name="oraculo_query" 
                           class="oraculo-search-input" 
                           placeholder="<?php echo $placeholder; ?>"
                           value="<?php echo esc_attr($query); ?>"
                           required>
                    <button type="submit" class="oraculo-search-submit">
                        <span class="dashicons dashicons-search"></span>
                        <span class="oraculo-search-text"><?php _e('Search', 'oraculo-tainacan'); ?></span>
                    </button>
                </div>

                <?php if ($show_filters && !empty($collections)): ?>
                    <div class="oraculo-collection-filters">
                        <span class="oraculo-filter-label"><?php _e('Filter by collection:', 'oraculo-tainacan'); ?></span>
                        <div class="oraculo-filter-options">
                            <?php foreach ($collections as $collection): ?>
                                <label class="oraculo-collection-checkbox">
                                    <input type="checkbox" 
                                           name="oraculo_collections[]" 
                                           value="<?php echo esc_attr($collection['id']); ?>"
                                           <?php checked(in_array($collection['id'], $selected_collections)); ?>>
                                    <?php echo esc_html($collection['name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </form>

            <?php if (!empty($query)): ?>
                <div class="oraculo-results-container" id="oraculo-results">
                    <?php if ($show_summary): ?>
                        <div class="oraculo-tabs">
                            <button class="oraculo-tab active" data-tab="results">
                                <?php _e('Results', 'oraculo-tainacan'); ?>
                            </button>
                            <button class="oraculo-tab" data-tab="summary">
                                <?php _e('AI Summary', 'oraculo-tainacan'); ?>
                            </button>
                        </div>
                    <?php endif; ?>

                    <div class="oraculo-tab-content">
                        <div class="oraculo-tab-pane active" data-pane="results">
                            <div class="oraculo-loading">
                                <span class="spinner is-active"></span>
                                <?php _e('Searching...', 'oraculo-tainacan'); ?>
                            </div>
                            <div class="oraculo-results-list"></div>
                        </div>

                        <?php if ($show_summary): ?>
                            <div class="oraculo-tab-pane" data-pane="summary">
                                <div class="oraculo-summary-content"></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="oraculo-feedback-section" style="display: none;">
                        <p><?php _e('Was this helpful?', 'oraculo-tainacan'); ?></p>
                        <button class="oraculo-feedback-btn" data-value="1">
                            <span class="dashicons dashicons-thumbs-up"></span>
                            <?php _e('Yes', 'oraculo-tainacan'); ?>
                        </button>
                        <button class="oraculo-feedback-btn" data-value="0">
                            <span class="dashicons dashicons-thumbs-down"></span>
                            <?php _e('No', 'oraculo-tainacan'); ?>
                        </button>
                    </div>
                </div>

                <script>
                    // Trigger search on page load if query exists
                    jQuery(document).ready(function($) {
                        if ('<?php echo esc_js($query); ?>') {
                            oraculoSearchBlock.performSearch(
                                '<?php echo esc_js($query); ?>',
                                <?php echo json_encode($selected_collections); ?>,
                                <?php echo $max_results; ?>
                            );
                        }
                    });
                </script>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Frontend JavaScript for the block
add_action('wp_enqueue_scripts', function() {
    wp_register_script(
        'oraculo-search-block-frontend',
        ORACULO_TAINACAN_PLUGIN_URL . 'blocks/search-frontend.js',
        array('jquery'),
        ORACULO_TAINACAN_VERSION,
        true
    );

    wp_localize_script('oraculo-search-block-frontend', 'oraculoSearchBlock', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('oraculo_search_nonce'),
        'i18n' => array(
            'error' => __('An error occurred during search', 'oraculo-tainacan'),
            'noResults' => __('No results found', 'oraculo-tainacan'),
            'viewItem' => __('View item', 'oraculo-tainacan')
        )
    ));

    wp_register_style(
        'oraculo-search-block',
        ORACULO_TAINACAN_PLUGIN_URL . 'blocks/search-block.css',
        array(),
        ORACULO_TAINACAN_VERSION
    );
});