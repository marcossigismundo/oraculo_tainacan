<?php
/**
 * Página de configurações do Oráculo Tainacan
 *
 * @package Oraculo_Tainacan
 */

if (!defined('WPINC')) {
    die;
}

if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.', 'oraculo-tainacan'));
}

// Processa salvamento
if (isset($_POST['oraculo_settings_submit']) && check_admin_referer('oraculo_settings_action', 'oraculo_settings_nonce')) {
    
    if (isset($_POST['oraculo_tainacan_openai_api_key']) && $_POST['oraculo_tainacan_openai_api_key'] !== '********') {
        $api_key = sanitize_text_field($_POST['oraculo_tainacan_openai_api_key']);
        if (!empty($api_key)) {
            require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-openai-client.php';
            $openai = new Oraculo_Tainacan_OpenAI_Client();
            $openai->save_api_key($api_key);
        }
    }
    
    update_option('oraculo_tainacan_openai_model', sanitize_text_field($_POST['oraculo_tainacan_openai_model'] ?? 'gpt-4o'));
    update_option('oraculo_tainacan_max_items', intval($_POST['oraculo_tainacan_max_items'] ?? 10));
    update_option('oraculo_tainacan_chunk_size', intval($_POST['oraculo_tainacan_chunk_size'] ?? 512));
    update_option('oraculo_tainacan_system_prompt', sanitize_textarea_field($_POST['oraculo_tainacan_system_prompt'] ?? ''));
    update_option('oraculo_tainacan_debug_mode', isset($_POST['oraculo_tainacan_debug_mode']) ? 1 : 0);
    
    $collections = isset($_POST['oraculo_tainacan_collections']) ? array_map('intval', $_POST['oraculo_tainacan_collections']) : array();
    update_option('oraculo_tainacan_collections', $collections);
    
    $index_fields = isset($_POST['oraculo_tainacan_index_fields']) ? array_map('sanitize_text_field', $_POST['oraculo_tainacan_index_fields']) : array();
    update_option('oraculo_tainacan_index_fields', $index_fields);
    
    echo '<div class="notice notice-success"><p>' . __('Configurações salvas com sucesso!', 'oraculo-tainacan') . '</p></div>';
}

// Carrega configurações
$encrypted_api_key = get_option('oraculo_tainacan_openai_api_key_encrypted', '');
$model = get_option('oraculo_tainacan_openai_model', 'gpt-4o');
$max_items = get_option('oraculo_tainacan_max_items', 10);
$selected_collections = get_option('oraculo_tainacan_collections', array());
$system_prompt = get_option('oraculo_tainacan_system_prompt', 'Você é um oráculo digital especializado em acervos museológicos.');
$debug_mode = get_option('oraculo_tainacan_debug_mode', false);
$index_fields = get_option('oraculo_tainacan_index_fields', array('title', 'description', 'metadata'));
$chunk_size = get_option('oraculo_tainacan_chunk_size', 512);

if (!is_array($index_fields)) $index_fields = array('title', 'description', 'metadata');
if (!is_array($selected_collections)) $selected_collections = array();

// Carrega dados
$collections = array();
$total_vectors = 0;
$vectors_by_collection = array();

try {
    if (file_exists(ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-tainacan-parser.php')) {
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-tainacan-parser.php';
        $parser = new Oraculo_Tainacan_Parser();
        $collections = $parser->get_collections();
        if (is_wp_error($collections)) {
            $collections = array();
        }
    }
    
    if (file_exists(ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-vector-db.php')) {
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-vector-db.php';
        $vector_db = new Oraculo_Tainacan_Vector_DB();
        $total_vectors = $vector_db->get_total_vectors();
        $vectors_by_collection = $vector_db->get_vectors_by_collection();
    }
} catch (Exception $e) {
    error_log('[Oráculo] Erro: ' . $e->getMessage());
}
?>

<style>
/* Container Principal */
.oraculo-admin-page { 
    margin: 20px 0; 
    max-width: 1400px;
}

/* Tabs */
.oraculo-tabs-wrapper {
    background: #fff;
    margin-top: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.oraculo-tabs {
    display: flex;
    border-bottom: 2px solid #ddd;
    background: #f5f5f5;
}

.oraculo-tab {
    padding: 15px 25px;
    background: none;
    border: none;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    color: #555;
    transition: all 0.3s;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
}

.oraculo-tab:hover {
    background: #fff;
    color: #0073aa;
}

.oraculo-tab.active {
    background: #fff;
    color: #0073aa;
    border-bottom-color: #0073aa;
}

.oraculo-tab-content {
    display: none;
    padding: 30px;
    background: #fff;
}

.oraculo-tab-content.active {
    display: block;
}

/* Cards */
.oraculo-admin-card { 
    background: #fff; 
    border: 1px solid #e5e5e5; 
    box-shadow: 0 1px 1px rgba(0,0,0,.04); 
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.oraculo-admin-card h2, 
.oraculo-admin-card h3 { 
    margin-top: 0; 
    padding-bottom: 10px; 
    border-bottom: 1px solid #eee;
    color: #23282d;
}

/* Grid de Coleções */
.oraculo-collections-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.oraculo-collection-card {
    border: 2px solid #e5e5e5;
    border-radius: 8px;
    padding: 15px;
    transition: all 0.3s;
    background: #fafafa;
}

.oraculo-collection-card:hover {
    border-color: #0073aa;
    box-shadow: 0 2px 8px rgba(0,115,170,0.1);
}

.oraculo-collection-card.selected {
    background: #f0f8ff;
    border-color: #0073aa;
}

.oraculo-collection-header {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}

.oraculo-collection-header input[type="checkbox"] {
    margin-right: 10px;
    transform: scale(1.2);
}

.oraculo-collection-name {
    font-weight: 600;
    font-size: 16px;
    flex: 1;
}

.oraculo-collection-stats {
    display: flex;
    justify-content: space-between;
    margin: 10px 0;
    padding: 10px;
    background: #fff;
    border-radius: 4px;
}

.oraculo-stat-item {
    text-align: center;
}

.oraculo-stat-value {
    display: block;
    font-size: 20px;
    font-weight: 600;
    color: #0073aa;
}

.oraculo-stat-label {
    display: block;
    font-size: 11px;
    color: #666;
    margin-top: 3px;
}

.oraculo-collection-progress {
    margin-top: 10px;
}

.oraculo-progress-bar {
    height: 8px;
    background: #e5e5e5;
    border-radius: 4px;
    overflow: hidden;
}

.oraculo-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #0073aa, #005177);
    transition: width 0.3s;
}

.oraculo-collection-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

.oraculo-collection-actions button {
    flex: 1;
    padding: 8px 12px;
    font-size: 13px;
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}

.status-ok { 
    background: #d4edda;
    color: #155724;
}

.status-warning {
    background: #fff3cd;
    color: #856404;
}

.status-error {
    background: #f8d7da;
    color: #721c24;
}

/* Sidebar */
.oraculo-settings-sidebar {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.oraculo-sidebar-card {
    background: #fff;
    border: 1px solid #e5e5e5;
    padding: 20px;
    border-radius: 4px;
}

.oraculo-sidebar-card h3 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.oraculo-stats-list {
    list-style: none;
    padding: 0;
    margin: 15px 0;
}

.oraculo-stats-list li {
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
}

.oraculo-stats-list li:last-child {
    border-bottom: none;
}

/* Forms */
.form-table td {
    padding: 15px 10px;
}

.form-table input[type="text"],
.form-table input[type="password"],
.form-table input[type="number"],
.form-table select,
.form-table textarea {
    width: 100%;
    max-width: 400px;
}

/* Responsive */
@media (max-width: 1200px) {
    .oraculo-collections-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 782px) {
    .oraculo-tabs {
        flex-direction: column;
    }
    
    .oraculo-tab {
        border-bottom: 1px solid #ddd;
        border-left: 3px solid transparent;
    }
    
    .oraculo-tab.active {
        border-left-color: #0073aa;
        border-bottom-color: transparent;
    }
}

/* Loading State */
.indexing-progress {
    display: none;
    margin-top: 10px;
    padding: 10px;
    background: #f0f8ff;
    border: 1px solid #0073aa;
    border-radius: 4px;
}

.indexing-progress.active {
    display: block;
}

.progress-bar {
    height: 20px;
    background: #e0e0e0;
    border-radius: 10px;
    overflow: hidden;
    margin: 10px 0;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #0073aa, #005177);
    transition: width 0.3s;
}

.progress-status {
    font-size: 13px;
    margin-bottom: 5px;
}

.progress-text {
    font-size: 12px;
    text-align: right;
    margin-top: 5px;
}

.button.indexing {
    position: relative;
    color: transparent;
}

.button.indexing::after {
    content: '';
    position: absolute;
    width: 16px;
    height: 16px;
    top: 50%;
    left: 50%;
    margin: -8px 0 0 -8px;
    border: 2px solid #fff;
    border-radius: 50%;
    border-top-color: transparent;
    animation: spinner 0.6s linear infinite;
}

@keyframes spinner {
    to { transform: rotate(360deg); }
}
</style>

<div class="wrap oraculo-admin-page">
    <h1><?php _e('Oráculo Tainacan', 'oraculo-tainacan'); ?></h1>
    <p class="description"><?php _e('Sistema de busca inteligente com IA para o Tainacan', 'oraculo-tainacan'); ?></p>

    <div class="oraculo-tabs-wrapper">
        <div class="oraculo-tabs">
            <button class="oraculo-tab active" data-tab="api">
                <span class="dashicons dashicons-admin-network"></span>
                <?php _e('API', 'oraculo-tainacan'); ?>
            </button>
            <button class="oraculo-tab" data-tab="collections">
                <span class="dashicons dashicons-category"></span>
                <?php _e('Coleções', 'oraculo-tainacan'); ?>
            </button>
            <button class="oraculo-tab" data-tab="indexing">
                <span class="dashicons dashicons-database"></span>
                <?php _e('Indexação', 'oraculo-tainacan'); ?>
            </button>
            <button class="oraculo-tab" data-tab="advanced">
                <span class="dashicons dashicons-admin-tools"></span>
                <?php _e('Avançado', 'oraculo-tainacan'); ?>
            </button>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('oraculo_settings_action', 'oraculo_settings_nonce'); ?>
            
            <!-- Tab: API -->
            <div class="oraculo-tab-content active" data-content="api">
                <div class="oraculo-admin-card">
                    <h2><?php _e('Configurações da API OpenAI', 'oraculo-tainacan'); ?></h2>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="oraculo_tainacan_openai_api_key"><?php _e('Chave da API', 'oraculo-tainacan'); ?></label>
                            </th>
                            <td>
                                <input type="password" 
                                       id="oraculo_tainacan_openai_api_key" 
                                       name="oraculo_tainacan_openai_api_key" 
                                       value="<?php echo !empty($encrypted_api_key) ? '********' : ''; ?>" 
                                       class="regular-text"
                                       placeholder="<?php _e('sk-...', 'oraculo-tainacan'); ?>" />
                                <p class="description">
                                    <?php _e('Obtenha sua chave em', 'oraculo-tainacan'); ?>
                                    <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>
                                    <?php if (!empty($encrypted_api_key)): ?>
                                        <br><span class="status-badge status-ok">✓ <?php _e('Configurada', 'oraculo-tainacan'); ?></span>
                                    <?php else: ?>
                                        <br><span class="status-badge status-error">✗ <?php _e('Não configurada', 'oraculo-tainacan'); ?></span>
                                    <?php endif; ?>
                                </p>
                                <button type="button" class="button" id="test-api">
                                    <?php _e('Testar Conexão', 'oraculo-tainacan'); ?>
                                </button>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="oraculo_tainacan_openai_model"><?php _e('Modelo', 'oraculo-tainacan'); ?></label>
                            </th>
                            <td>
                                <select id="oraculo_tainacan_openai_model" name="oraculo_tainacan_openai_model">
                                    <option value="gpt-4o" <?php selected($model, 'gpt-4o'); ?>>GPT-4 Optimized (Recomendado)</option>
                                    <option value="gpt-4o-mini" <?php selected($model, 'gpt-4o-mini'); ?>>GPT-4 Mini (Econômico)</option>
                                    <option value="gpt-3.5-turbo" <?php selected($model, 'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo (Rápido)</option>
                                </select>
                                <p class="description"><?php _e('Modelo de IA para gerar respostas', 'oraculo-tainacan'); ?></p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th scope="row">
                                <label for="oraculo_tainacan_max_items"><?php _e('Resultados por busca', 'oraculo-tainacan'); ?></label>
                            </th>
                            <td>
                                <input type="number" 
                                       id="oraculo_tainacan_max_items" 
                                       name="oraculo_tainacan_max_items" 
                                       value="<?php echo esc_attr($max_items); ?>" 
                                       min="1" 
                                       max="50" 
                                       class="small-text" />
                                <p class="description"><?php _e('Número máximo de itens retornados (1-50)', 'oraculo-tainacan'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Tab: Coleções -->
            <div class="oraculo-tab-content" data-content="collections">
                <div class="oraculo-admin-card">
                    <h2><?php _e('Coleções do Tainacan', 'oraculo-tainacan'); ?></h2>
                    <p><?php _e('Selecione as coleções que serão indexadas e disponibilizadas para busca.', 'oraculo-tainacan'); ?></p>
                    
                    <?php if (!empty($collections)): ?>
                        <div style="margin: 15px 0;">
                            <label>
                                <input type="checkbox" id="select-all" />
                                <strong><?php _e('Selecionar todas', 'oraculo-tainacan'); ?></strong>
                            </label>
                        </div>
                        
                        <div class="oraculo-collections-grid">
                            <?php foreach ($collections as $collection): ?>
                                <?php 
                                $indexed_count = 0;
                                foreach ($vectors_by_collection as $vc) {
                                    if ((int)$vc['collection_id'] === (int)$collection['id']) {
                                        $indexed_count = (int)$vc['count'];
                                        break;
                                    }
                                }
                                $percentage = $collection['items_count'] > 0 ? round(($indexed_count / $collection['items_count']) * 100) : 0;
                                $is_selected = in_array($collection['id'], $selected_collections);
                                ?>
                                <div class="oraculo-collection-card <?php echo $is_selected ? 'selected' : ''; ?>" data-collection-id="<?php echo esc_attr($collection['id']); ?>">
                                    <div class="oraculo-collection-header">
                                        <input type="checkbox" 
                                               name="oraculo_tainacan_collections[]" 
                                               value="<?php echo esc_attr($collection['id']); ?>" 
                                               <?php checked($is_selected); ?> />
                                        <span class="oraculo-collection-name"><?php echo esc_html($collection['name']); ?></span>
                                    </div>
                                    
                                    <div class="oraculo-collection-stats">
                                        <div class="oraculo-stat-item">
                                            <span class="oraculo-stat-value"><?php echo esc_html($collection['items_count']); ?></span>
                                            <span class="oraculo-stat-label"><?php _e('Total', 'oraculo-tainacan'); ?></span>
                                        </div>
                                        <div class="oraculo-stat-item">
                                            <span class="oraculo-stat-value"><?php echo esc_html($indexed_count); ?></span>
                                            <span class="oraculo-stat-label"><?php _e('Indexados', 'oraculo-tainacan'); ?></span>
                                        </div>
                                        <div class="oraculo-stat-item">
                                            <span class="oraculo-stat-value"><?php echo $percentage; ?>%</span>
                                            <span class="oraculo-stat-label"><?php _e('Completo', 'oraculo-tainacan'); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="oraculo-collection-progress">
                                        <div class="oraculo-progress-bar">
                                            <div class="oraculo-progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="oraculo-collection-actions">
                                        <button type="button" 
                                                class="button button-primary index-btn" 
                                                data-collection-id="<?php echo esc_attr($collection['id']); ?>"
                                                data-collection-name="<?php echo esc_attr($collection['name']); ?>"
                                                data-collection-size="<?php echo esc_attr($collection['items_count']); ?>">
                                            <?php _e('Indexar', 'oraculo-tainacan'); ?>
                                        </button>
                                        <button type="button" 
                                                class="button index-force-btn" 
                                                data-collection-id="<?php echo esc_attr($collection['id']); ?>"
                                                data-collection-name="<?php echo esc_attr($collection['name']); ?>"
                                                data-collection-size="<?php echo esc_attr($collection['items_count']); ?>">
                                            <?php _e('Reindexar', 'oraculo-tainacan'); ?>
                                        </button>
                                    </div>
                                    
                                    <div class="indexing-progress" id="progress-<?php echo $collection['id']; ?>">
                                        <div class="progress-status"><?php _e('Processando...', 'oraculo-tainacan'); ?></div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: 0%"></div>
                                        </div>
                                        <div class="progress-text">0%</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="notice notice-warning inline">
                            <p><?php _e('Nenhuma coleção encontrada no Tainacan.', 'oraculo-tainacan'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tab: Indexação -->
            <div class="oraculo-tab-content" data-content="indexing">
                <div class="oraculo-admin-card">
                    <h2><?php _e('Configurações de Indexação', 'oraculo-tainacan'); ?></h2>
                    
                    <h3><?php _e('Campos para Indexação', 'oraculo-tainacan'); ?></h3>
                    <p class="description"><?php _e('Selecione quais campos serão incluídos na indexação', 'oraculo-tainacan'); ?></p>
                    
                    <div style="margin: 20px 0;">
                        <label style="display: block; margin-bottom: 10px;">
                            <input type="checkbox" name="oraculo_tainacan_index_fields[]" value="title" 
                                   <?php checked(in_array('title', $index_fields)); ?> /> 
                            <strong><?php _e('Título', 'oraculo-tainacan'); ?></strong>
                        </label>
                        
                        <label style="display: block; margin-bottom: 10px;">
                            <input type="checkbox" name="oraculo_tainacan_index_fields[]" value="description" 
                                   <?php checked(in_array('description', $index_fields)); ?> /> 
                            <strong><?php _e('Descrição', 'oraculo-tainacan'); ?></strong>
                        </label>
                        
                        <label style="display: block; margin-bottom: 10px;">
                            <input type="checkbox" name="oraculo_tainacan_index_fields[]" value="metadata" 
                                   <?php checked(in_array('metadata', $index_fields)); ?> /> 
                            <strong><?php _e('Metadados', 'oraculo-tainacan'); ?></strong>
                        </label>
                    </div>
                    
                    <h3><?php _e('Tamanho do Chunk', 'oraculo-tainacan'); ?></h3>
                    <input type="number" 
                           name="oraculo_tainacan_chunk_size" 
                           value="<?php echo esc_attr($chunk_size); ?>" 
                           min="128" 
                           max="2048" 
                           class="small-text" />
                    <p class="description"><?php _e('Tamanho máximo de cada fragmento de texto (128-2048 tokens)', 'oraculo-tainacan'); ?></p>
                </div>
            </div>

            <!-- Tab: Avançado -->
            <div class="oraculo-tab-content" data-content="advanced">
                <div class="oraculo-admin-card">
                    <h2><?php _e('Configurações Avançadas', 'oraculo-tainacan'); ?></h2>
                    
                    <h3><?php _e('System Prompt', 'oraculo-tainacan'); ?></h3>
                    <textarea id="oraculo_tainacan_system_prompt" 
                             name="oraculo_tainacan_system_prompt" 
                             rows="4" 
                             class="large-text"><?php echo esc_textarea($system_prompt); ?></textarea>
                    <p class="description"><?php _e('Instruções para o modelo de IA sobre como responder', 'oraculo-tainacan'); ?></p>
                    
                    <h3><?php _e('Modo Debug', 'oraculo-tainacan'); ?></h3>
                    <label>
                        <input type="checkbox" name="oraculo_tainacan_debug_mode" value="1" 
                               <?php checked($debug_mode, true); ?> />
                        <?php _e('Ativar logs detalhados para diagnóstico', 'oraculo-tainacan'); ?>
                    </label>
                    
                    <h3><?php _e('Manutenção', 'oraculo-tainacan'); ?></h3>
                    <button type="button" class="button" id="clear-cache">
                        <?php _e('Limpar Cache de Vetores', 'oraculo-tainacan'); ?>
                    </button>
                    <p class="description"><?php _e('Remove todos os vetores indexados. Será necessário reindexar as coleções.', 'oraculo-tainacan'); ?></p>
                </div>
            </div>

            <p class="submit">
                <input type="submit" name="oraculo_settings_submit" class="button-primary" value="<?php _e('Salvar Configurações', 'oraculo-tainacan'); ?>" />
            </p>
        </form>
    </div>

    <!-- Sidebar com estatísticas -->
    <div class="oraculo-settings-sidebar" style="position: fixed; right: 20px; top: 100px; width: 280px;">
        <div class="oraculo-sidebar-card">
            <h3><?php _e('Estatísticas', 'oraculo-tainacan'); ?></h3>
            <ul class="oraculo-stats-list">
                <li>
                    <strong><?php _e('Total indexado:', 'oraculo-tainacan'); ?></strong>
                    <span class="status-badge <?php echo $total_vectors > 0 ? 'status-ok' : 'status-warning'; ?>">
                        <?php echo number_format($total_vectors); ?> itens
                    </span>
                </li>
                <li>
                    <strong><?php _e('Coleções ativas:', 'oraculo-tainacan'); ?></strong>
                    <span><?php echo count($selected_collections); ?> / <?php echo count($collections); ?></span>
                </li>
                <li>
                    <strong><?php _e('Modelo atual:', 'oraculo-tainacan'); ?></strong>
                    <span><?php echo esc_html($model); ?></span>
                </li>
            </ul>
            
            <h3><?php _e('Links Úteis', 'oraculo-tainacan'); ?></h3>
            <ul class="oraculo-stats-list">
                <li>
                    <a href="<?php echo home_url('oraculo'); ?>" target="_blank">
                        <?php _e('Página de busca', 'oraculo-tainacan'); ?> →
                    </a>
                </li>
                <li>
                    <a href="<?php echo admin_url('admin.php?page=oraculo-tainacan-dashboard'); ?>">
                        <?php _e('Ver Dashboard', 'oraculo-tainacan'); ?> →
                    </a>
                </li>
                <li>
                    <a href="<?php echo admin_url('admin.php?page=oraculo-tainacan-debug'); ?>">
                        <?php _e('Debug e Testes', 'oraculo-tainacan'); ?> →
                    </a>
                </li>
            </ul>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Tabs
    $('.oraculo-tab').on('click', function() {
        const tab = $(this).data('tab');
        
        $('.oraculo-tab').removeClass('active');
        $(this).addClass('active');
        
        $('.oraculo-tab-content').removeClass('active');
        $(`.oraculo-tab-content[data-content="${tab}"]`).addClass('active');
    });
    
    // Select all collections
    $('#select-all').on('change', function() {
        $('input[name="oraculo_tainacan_collections[]"]').prop('checked', $(this).prop('checked'));
        $('.oraculo-collection-card').toggleClass('selected', $(this).prop('checked'));
    });
    
    // Collection selection visual feedback
    $('input[name="oraculo_tainacan_collections[]"]').on('change', function() {
        $(this).closest('.oraculo-collection-card').toggleClass('selected', $(this).prop('checked'));
    });
    
    // Indexar coleção com processamento em lote
    $('.index-btn, .index-force-btn').on('click', function() {
        const btn = $(this);
        const collectionId = btn.data('collection-id');
        const collectionName = btn.data('collection-name');
        const collectionSize = btn.data('collection-size') || 100;
        const forceUpdate = btn.hasClass('index-force-btn');
        const progressDiv = $('#progress-' + collectionId);
        const card = btn.closest('.oraculo-collection-card');
        
        // UI feedback
        btn.prop('disabled', true).addClass('indexing');
        card.find('.index-btn, .index-force-btn').prop('disabled', true);
        progressDiv.addClass('active');
        
        // Função para processar lotes
        function processBatch(offset = 0, processed = 0) {
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'oraculo_index_collection_batch',
                    collection_id: collectionId,
                    batch_size: 10,
                    offset: offset,
                    force_update: forceUpdate ? 1 : 0,
                    nonce: '<?php echo wp_create_nonce('oraculo_admin_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        processed += response.data.total;
                        const percentage = Math.min(Math.round((processed / collectionSize) * 100), 100);
                        
                        progressDiv.find('.progress-fill').css('width', percentage + '%');
                        progressDiv.find('.progress-text').text(percentage + '%');
                        progressDiv.find('.progress-status').text(
                            `Processados: ${processed} / ${collectionSize} itens`
                        );
                        
                        if (response.data.has_more) {
                            // Continua processando
                            setTimeout(() => processBatch(response.data.next_offset, processed), 100);
                        } else {
                            // Concluído
                            progressDiv.find('.progress-status').text('✓ Indexação concluída!');
                            setTimeout(() => location.reload(), 2000);
                        }
                    } else {
                        alert('Erro: ' + (response.data?.message || 'Erro desconhecido'));
                        btn.prop('disabled', false).removeClass('indexing');
                        card.find('.index-btn, .index-force-btn').prop('disabled', false);
                        progressDiv.removeClass('active');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Erro na indexação:', error);
                    alert('Erro de comunicação. Por favor, tente novamente.');
                    btn.prop('disabled', false).removeClass('indexing');
                    card.find('.index-btn, .index-force-btn').prop('disabled', false);
                    progressDiv.removeClass('active');
                }
            });
        }
        
        // Inicia o processamento
        processBatch(0, 0);
    });
    
    // Testar API
    $('#test-api').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true).text('Testando...');
        
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'oraculo_test_api_connection',
                nonce: '<?php echo wp_create_nonce('oraculo_admin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert('✓ Conexão bem-sucedida!');
                } else {
                    alert('✗ Erro: ' + (response.data?.message || 'Erro desconhecido'));
                }
            },
            complete: function() {
                btn.prop('disabled', false).text('Testar Conexão');
            }
        });
    });
    
    // Limpar cache
    $('#clear-cache').on('click', function() {
        if (!confirm('Tem certeza? Isso removerá todos os vetores indexados.')) return;
        
        const btn = $(this);
        btn.prop('disabled', true);
        
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'oraculo_clear_cache',
                nonce: '<?php echo wp_create_nonce('oraculo_admin_nonce'); ?>'
            },
            success: function(response) {
                alert(response.success ? 'Cache limpo com sucesso!' : 'Erro ao limpar cache');
                if (response.success) location.reload();
            },
            complete: function() {
                btn.prop('disabled', false);
            }
        });
    });
});
</script>