<?php
/**
 * Template para a página pública de busca do Oráculo Tainacan
 *
 * @package Oraculo_Tainacan
 */

// Impede o acesso direto
if (!defined('WPINC')) {
    die;
}

get_header();

// Enfileira estilos e scripts
wp_enqueue_style('oraculo-tainacan-style');
wp_enqueue_script('oraculo-tainacan-search');

// Inicializa classes necessárias
require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-rag-engine.php';
require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-tainacan-parser.php';

$parser = new Oraculo_Tainacan_Parser();
$collections = $parser->get_collections();
$selected_collections = get_option('oraculo_tainacan_collections', array());

// Garante que seja um array
if (!is_array($selected_collections)) {
    $selected_collections = array();
}

// Verifica se existe uma consulta
$query = isset($_GET['query']) ? sanitize_text_field($_GET['query']) : '';
$result = null;

if (!empty($query)) {
    // Processa coleções selecionadas pelo usuário
    $user_collections = array();
    if (isset($_GET['collections']) && is_array($_GET['collections'])) {
        $user_collections = array_map('intval', $_GET['collections']);
        // Filtra apenas coleções válidas
        $user_collections = array_intersect($user_collections, $selected_collections);
    }
    
    // Se nenhuma coleção foi selecionada pelo usuário, usa as padrão
    $collections_to_search = !empty($user_collections) ? $user_collections : $selected_collections;
    
    $rag_engine = new Oraculo_Tainacan_RAG_Engine();
    $result = $rag_engine->search($query, $collections_to_search);
}
?>

<div class="oraculo-search-page">
    <div class="oraculo-container">
        <div class="oraculo-header">
            <h1 class="oraculo-title">
                <?php echo esc_html(get_option('oraculo_tainacan_page_title', __('Oráculo Tainacan', 'oraculo-tainacan'))); ?>
            </h1>
            <div class="oraculo-subtitle">
                <?php echo esc_html(get_option('oraculo_tainacan_page_subtitle', __('Faça perguntas sobre o acervo em linguagem natural', 'oraculo-tainacan'))); ?>
            </div>
        </div>
        
        <div class="oraculo-search-form">
            <form method="get" action="<?php echo esc_url(home_url('oraculo')); ?>">
                <div class="oraculo-search-input-container">
                    <input type="text" 
                           name="query" 
                           id="oraculo-query" 
                           class="oraculo-search-input" 
                           placeholder="<?php echo esc_attr(get_option('oraculo_tainacan_search_placeholder', __('Faça sua pergunta sobre o acervo...', 'oraculo-tainacan'))); ?>"
                           value="<?php echo esc_attr($query); ?>"
                           required>
                    <button type="submit" class="oraculo-search-button">
                        <span class="oraculo-search-icon">&#128269;</span>
                        <span class="oraculo-search-text"><?php echo esc_html(get_option('oraculo_tainacan_button_text', __('Perguntar', 'oraculo-tainacan'))); ?></span>
                    </button>
                </div>
                
                <?php if (!is_wp_error($collections) && !empty($collections) && !empty($selected_collections)): ?>
                    <div class="oraculo-collections-filter">
                        <div class="oraculo-collections-label">
                            <?php _e('Filtrar por coleções:', 'oraculo-tainacan'); ?>
                        </div>
                        <div class="oraculo-collections-options">
                            <?php foreach ($collections as $collection): ?>
                                <?php if (in_array($collection['id'], $selected_collections)): ?>
                                    <label class="oraculo-collection-option">
                                        <input type="checkbox" 
                                               name="collections[]" 
                                               value="<?php echo esc_attr($collection['id']); ?>"
                                               <?php checked(
                                                   isset($_GET['collections']) && 
                                                   is_array($_GET['collections']) && 
                                                   in_array($collection['id'], $_GET['collections'])
                                               ); ?>>
                                        <?php echo esc_html($collection['name']); ?>
                                    </label>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        
        <?php if ($query): ?>
            <div class="oraculo-results">
                <?php if (is_wp_error($result)): ?>
                    <div class="oraculo-error">
                        <div class="oraculo-error-title">
                            <?php _e('Erro na consulta', 'oraculo-tainacan'); ?>
                        </div>
                        <div class="oraculo-error-message">
                            <?php echo esc_html($result->get_error_message()); ?>
                        </div>
                    </div>
                <?php elseif (!empty($result)): ?>
                    <div class="oraculo-query">
                        <div class="oraculo-query-label">
                            <?php _e('Sua pergunta:', 'oraculo-tainacan'); ?>
                        </div>
                        <div class="oraculo-query-text">
                            <?php echo esc_html($result['query']); ?>
                        </div>
                    </div>
                    
                    <div class="oraculo-response">
                        <?php echo wp_kses_post(nl2br($result['response'])); ?>
                    </div>
                    
                    <?php if (!empty($result['items'])): ?>
                        <div class="oraculo-items">
                            <div class="oraculo-items-heading">
                                <?php _e('Itens referenciados:', 'oraculo-tainacan'); ?>
                            </div>
                            
                            <div class="oraculo-items-list">
                                <?php foreach ($result['items'] as $index => $item): ?>
                                    <div class="oraculo-item">
                                        <div class="oraculo-item-header">
                                            <div class="oraculo-item-number"><?php echo $index + 1; ?></div>
                                            <div class="oraculo-item-title"><?php echo esc_html($item['title']); ?></div>
                                            <div class="oraculo-item-collection"><?php echo esc_html($item['collection']); ?></div>
                                        </div>
                                        
                                        <?php if (!empty($item['description'])): ?>
                                            <div class="oraculo-item-description">
                                                <?php echo esc_html(wp_trim_words($item['description'], 30, '...')); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="oraculo-item-footer">
                                            <a href="<?php echo esc_url($item['permalink']); ?>" target="_blank" class="oraculo-item-link">
                                                <?php _e('Ver item no Tainacan', 'oraculo-tainacan'); ?> &rarr;
                                            </a>
                                            <div class="oraculo-item-relevance">
                                                <?php 
                                                $relevance = isset($item['similarity']) ? round($item['similarity'] * 100) : 0;
                                                echo sprintf(__('Relevância: %d%%', 'oraculo-tainacan'), $relevance);
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php
                    // Obter ID da última busca para feedback
                    global $wpdb;
                    $search_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}oraculo_search_history 
                         WHERE query = %s 
                         ORDER BY created_at DESC 
                         LIMIT 1",
                        $query
                    ));
                    ?>
                    
                    <div class="oraculo-feedback" data-search-id="<?php echo esc_attr($search_id); ?>">
                        <div class="oraculo-feedback-question">
                            <?php _e('Esta resposta foi útil?', 'oraculo-tainacan'); ?>
                        </div>
                        <div class="oraculo-feedback-buttons">
                            <button class="oraculo-feedback-button oraculo-feedback-yes" data-value="1">
                                <?php _e('Sim', 'oraculo-tainacan'); ?>
                            </button>
                            <button class="oraculo-feedback-button oraculo-feedback-no" data-value="0">
                                <?php _e('Não', 'oraculo-tainacan'); ?>
                            </button>
                        </div>
                        <div class="oraculo-feedback-message"></div>
                    </div>
                <?php else: ?>
                    <div class="oraculo-error">
                        <div class="oraculo-error-title">
                            <?php _e('Nenhum resultado encontrado', 'oraculo-tainacan'); ?>
                        </div>
                        <div class="oraculo-error-message">
                            <?php _e('Tente reformular sua pergunta ou verificar se há coleções indexadas.', 'oraculo-tainacan'); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="oraculo-examples">
                <div class="oraculo-examples-heading">
                    <?php echo esc_html(get_option('oraculo_tainacan_examples_title', __('Exemplos de perguntas que você pode fazer:', 'oraculo-tainacan'))); ?>
                </div>
                
                <div class="oraculo-examples-list">
                    <?php
                    $examples = array(
                        get_option('oraculo_tainacan_example_1', 'Quais são as principais obras de arte do movimento modernista no acervo?'),
                        get_option('oraculo_tainacan_example_2', 'Me explique a história e o contexto de criação das principais peças cerâmicas indígenas.'), 
                        get_option('oraculo_tainacan_example_3', 'Quais fotografias do século XIX retratam a vida urbana?'),
                        get_option('oraculo_tainacan_example_4', 'Que artefatos arqueológicos mais antigos estão disponíveis no acervo?')
                    );
                    
                    foreach ($examples as $example) {
                        if (!empty(trim($example))) {
                            echo '<div class="oraculo-example">' . esc_html($example) . '</div>';
                        }
                    }
                    ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php get_footer(); ?>