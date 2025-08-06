<?php
/**
 * Página de debug do Oráculo Tainacan
 *
 * @package Oraculo_Tainacan
 */

// Impede o acesso direto
if (!defined('WPINC')) {
    die;
}

// Inicializa o parser do Tainacan para obter as coleções
require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-tainacan-parser.php';
$parser = new Oraculo_Tainacan_Parser();
$collections = $parser->get_collections();

// Inicializa o Vector DB para obter estatísticas
require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-vector-db.php';
$vector_db = new Oraculo_Tainacan_Vector_DB();
$total_vectors = $vector_db->get_total_vectors();
$vectors_by_collection = $vector_db->get_vectors_by_collection();

// Obtém configurações
$debug_mode = get_option('oraculo_tainacan_debug_mode', false);
$api_key_set = !empty(get_option('oraculo_tainacan_openai_api_key', ''));
$selected_collections = get_option('oraculo_tainacan_collections', array());
?>

<div class="wrap oraculo-admin-page">
    <h1><?php _e('Debug do Oráculo Tainacan', 'oraculo-tainacan'); ?></h1>
    
    <div class="oraculo-debug-container">
        <div class="oraculo-admin-card">
            <h2><?php _e('Teste de Busca RAG', 'oraculo-tainacan'); ?></h2>
            
            <p>
                <?php _e('Use esta ferramenta para testar o sistema de busca RAG e visualizar todas as etapas do processo.', 'oraculo-tainacan'); ?>
            </p>
            
            <div class="oraculo-test-form">
                <div class="oraculo-form-row">
                    <label for="test-query"><?php _e('Consulta de teste:', 'oraculo-tainacan'); ?></label>
                    <input type="text" id="test-query" class="widefat" placeholder="<?php _e('Digite sua pergunta aqui...', 'oraculo-tainacan'); ?>">
                </div>
                
                <div class="oraculo-form-row">
                    <label><?php _e('Coleções para consultar:', 'oraculo-tainacan'); ?></label>
                    
                    <div class="oraculo-collections-selector">
                        <?php if (!is_wp_error($collections) && !empty($collections)): ?>
                            <?php foreach ($collections as $collection): ?>
                                <label class="oraculo-collection-checkbox">
                                    <input type="checkbox" 
                                           name="test_collections[]" 
                                           value="<?php echo esc_attr($collection['id']); ?>" 
                                           <?php checked(in_array($collection['id'], $selected_collections)); ?>>
                                    <?php echo esc_html($collection['name']); ?>
                                    <span class="items-count">(<?php echo esc_html($collection['items_count']); ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="notice notice-warning inline">
                                <?php _e('Nenhuma coleção disponível no Tainacan.', 'oraculo-tainacan'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="oraculo-form-buttons">
                    <button id="run-test-search" class="button button-primary"><?php _e('Executar Busca de Teste', 'oraculo-tainacan'); ?></button>
                </div>
            </div>
        </div>
        
        <div class="oraculo-admin-card" id="test-results-container" style="display: none;">
            <h2><?php _e('Resultados do Teste', 'oraculo-tainacan'); ?></h2>
            
            <div class="oraculo-test-info">
                <div class="oraculo-test-header">
                    <div class="oraculo-test-query"></div>
                    <div class="oraculo-test-timestamp"></div>
                </div>
                
                <div class="oraculo-test-summary">
                    <div class="oraculo-test-stats">
                        <div class="oraculo-test-stat">
                            <span class="oraculo-test-stat-label"><?php _e('Status:', 'oraculo-tainacan'); ?></span>
                            <span class="oraculo-test-stat-value oraculo-test-status"></span>
                        </div>
                        
                        <div class="oraculo-test-stat">
                            <span class="oraculo-test-stat-label"><?php _e('Tempo total:', 'oraculo-tainacan'); ?></span>
                            <span class="oraculo-test-stat-value oraculo-test-time"></span>
                        </div>
                        
                        <div class="oraculo-test-stat">
                            <span class="oraculo-test-stat-label"><?php _e('Itens encontrados:', 'oraculo-tainacan'); ?></span>
                            <span class="oraculo-test-stat-value oraculo-test-items-count"></span>
                        </div>
                    </div>
                </div>
                
                <div class="oraculo-test-steps-container">
                    <h3><?php _e('Etapas do processo', 'oraculo-tainacan'); ?></h3>
                    <div class="oraculo-test-steps"></div>
                </div>
                
                <div class="oraculo-test-final-result">
                    <h3><?php _e('Resposta Final', 'oraculo-tainacan'); ?></h3>
                    <div class="oraculo-test-response"></div>
                </div>
            </div>
        </div>
        
        <div class="oraculo-admin-card">
            <h2><?php _e('Informações de Diagnóstico', 'oraculo-tainacan'); ?></h2>
            
            <div class="oraculo-diagnostics">
                <h3><?php _e('Configuração do Sistema', 'oraculo-tainacan'); ?></h3>
                <table class="widefat striped oraculo-diagnostics-table">
                    <tbody>
                        <tr>
                            <td><strong><?php _e('Versão do WordPress:', 'oraculo-tainacan'); ?></strong></td>
                            <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                            <td class="status">
                                <?php echo version_compare(get_bloginfo('version'), '5.8', '>=') ? 
                                    '<span class="status-ok">OK</span>' : 
                                    '<span class="status-warning">Recomendado 5.8+</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Versão do PHP:', 'oraculo-tainacan'); ?></strong></td>
                            <td><?php echo esc_html(PHP_VERSION); ?></td>
                            <td class="status">
                                <?php echo version_compare(PHP_VERSION, '7.4', '>=') ? 
                                    '<span class="status-ok">OK</span>' : 
                                    '<span class="status-error">Requer 7.4+</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Modo de Debug do WP:', 'oraculo-tainacan'); ?></strong></td>
                            <td><?php echo WP_DEBUG ? __('Ativado', 'oraculo-tainacan') : __('Desativado', 'oraculo-tainacan'); ?></td>
                            <td class="status">
                                <?php echo WP_DEBUG ? 
                                    '<span class="status-ok">OK</span>' : 
                                    '<span class="status-info">Ativar para logs</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Modo de Debug do Oráculo:', 'oraculo-tainacan'); ?></strong></td>
                            <td><?php echo $debug_mode ? __('Ativado', 'oraculo-tainacan') : __('Desativado', 'oraculo-tainacan'); ?></td>
                            <td class="status">
                                <?php echo $debug_mode ? 
                                    '<span class="status-ok">OK</span>' : 
                                    '<span class="status-info">Ativar para logs detalhados</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Chave da API OpenAI:', 'oraculo-tainacan'); ?></strong></td>
                            <td><?php echo $api_key_set ? __('Configurada', 'oraculo-tainacan') : __('Não configurada', 'oraculo-tainacan'); ?></td>
                            <td class="status">
                                <?php echo $api_key_set ? 
                                    '<span class="status-ok">OK</span>' : 
                                    '<span class="status-error">Obrigatória</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Tainacan detectado:', 'oraculo-tainacan'); ?></strong></td>
                            <td><?php echo class_exists('Tainacan\Tainacan') ? __('Sim', 'oraculo-tainacan') : __('Não', 'oraculo-tainacan'); ?></td>
                            <td class="status">
                                <?php echo class_exists('Tainacan\Tainacan') ? 
                                    '<span class="status-ok">OK</span>' : 
                                    '<span class="status-error">Obrigatório</span>'; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <h3><?php _e('Estatísticas do Banco Vetorial', 'oraculo-tainacan'); ?></h3>
                <table class="widefat striped oraculo-diagnostics-table">
                    <tbody>
                        <tr>
                            <td><strong><?php _e('Total de vetores:', 'oraculo-tainacan'); ?></strong></td>
                            <td><?php echo esc_html($total_vectors); ?></td>
                            <td class="status">
                                <?php echo $total_vectors > 0 ? 
                                    '<span class="status-ok">OK</span>' : 
                                    '<span class="status-warning">Nenhum item indexado</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Coleções com vetores:', 'oraculo-tainacan'); ?></strong></td>
                            <td><?php echo count($vectors_by_collection); ?></td>
                            <td class="status">
                                <?php echo count($vectors_by_collection) > 0 ? 
                                    '<span class="status-ok">OK</span>' : 
                                    '<span class="status-warning">Nenhuma coleção indexada</span>'; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Coleções selecionadas:', 'oraculo-tainacan'); ?></strong></td>
                            <td><?php echo count($selected_collections); ?></td>
                            <td class="status">
                                <?php echo count($selected_collections) > 0 ? 
                                    '<span class="status-ok">OK</span>' : 
                                    '<span class="status-warning">Nenhuma coleção selecionada</span>'; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="oraculo-log-viewer">
                    <h3><?php _e('Arquivo de Log', 'oraculo-tainacan'); ?></h3>
                    
                    <?php 
                    $log_file = WP_CONTENT_DIR . '/debug.log';
                    $log_exists = file_exists($log_file);
                    $is_readable = $log_exists && is_readable($log_file);
                    ?>
                    
                    <?php if (!$log_exists): ?>
                        <div class="notice notice-warning inline">
                            <p><?php _e('Arquivo de log não encontrado. Ative WP_DEBUG e WP_DEBUG_LOG em wp-config.php.', 'oraculo-tainacan'); ?></p>
                        </div>
                    <?php elseif (!$is_readable): ?>
                        <div class="notice notice-error inline">
                            <p><?php _e('Arquivo de log não pode ser lido. Verifique as permissões.', 'oraculo-tainacan'); ?></p>
                        </div>
                    <?php else: ?>
                        <?php
                        // Obtém as últimas 50 linhas que contém "Oráculo Tainacan"
                        $cmd = 'tail -n 1000 ' . escapeshellarg($log_file);
                        $output = shell_exec($cmd);
                        
                        if (empty($output)) {
                            echo '<div class="notice notice-info inline"><p>' . __('Nenhuma entrada de log encontrada para o Oráculo Tainacan.', 'oraculo-tainacan') . '</p></div>';
                        } else {
                            // Filtra apenas as linhas com "Oráculo Tainacan"
                            $lines = explode("\n", $output);
                            $filtered_lines = array();
                            
                            foreach ($lines as $line) {
                                if (strpos($line, '[Oráculo Tainacan]') !== false) {
                                    $filtered_lines[] = $line;
                                }
                            }
                            
                            // Exibe as últimas 50 linhas
                            $filtered_lines = array_slice($filtered_lines, -50);
                            
                            if (empty($filtered_lines)) {
                                echo '<div class="notice notice-info inline"><p>' . __('Nenhuma entrada de log encontrada para o Oráculo Tainacan.', 'oraculo-tainacan') . '</p></div>';
                            } else {
                                echo '<div class="oraculo-log-container">';
                                foreach ($filtered_lines as $line) {
                                    // Formata as linhas para melhor legibilidade
                                    $line = esc_html($line);
                                    
                                    // Adiciona classes baseadas no tipo de log
                                    $class = '';
                                    if (strpos($line, '[ERROR]') !== false) {
                                        $class = 'log-error';
                                    } elseif (strpos($line, '[DEBUG]') !== false) {
                                        $class = 'log-debug';
                                    }
                                    
                                    echo '<div class="log-line ' . $class . '">' . $line . '</div>';
                                }
                                echo '</div>';
                            }
                        }
                        ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Executar busca de teste
    $('#run-test-search').on('click', function() {
        const query = $('#test-query').val();
        
        if (!query) {
            alert('<?php _e('Por favor, digite uma consulta de teste.', 'oraculo-tainacan'); ?>');
            return;
        }
        
        const collections = [];
        $('input[name="test_collections[]"]:checked').each(function() {
            collections.push($(this).val());
        });
        
        // Limpa resultados anteriores
        $('#test-results-container').hide();
        $('.oraculo-test-steps').empty();
        $('.oraculo-test-response').empty();
        
        // Mostra um indicador de carregamento
        const button = $(this);
        button.prop('disabled', true);
        button.html('<?php _e('Executando...', 'oraculo-tainacan'); ?>');
        
        // Executa a busca de teste
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'oraculo_test_search',
                query: query,
                collections: collections,
                nonce: '<?php echo wp_create_nonce('oraculo_admin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    // Preenche as informações do teste
                    const debug_info = response.data.debug_info;
                    
                    $('.oraculo-test-query').text(debug_info.query);
                    $('.oraculo-test-timestamp').text(debug_info.timestamp);
                    
                    // Verifica se existe resultado final
                    if (debug_info.final_result) {
                        $('.oraculo-test-status').text('<?php _e('Concluído com sucesso', 'oraculo-tainacan'); ?>').addClass('status-success');
                        $('.oraculo-test-time').text(debug_info.final_result.total_time + ' <?php _e('segundos', 'oraculo-tainacan'); ?>');
                        $('.oraculo-test-items-count').text(debug_info.final_result.items.length);
                        
                        // Exibe a resposta final
                        $('.oraculo-test-response').html(debug_info.final_result.response.replace(/\n/g, '<br>'));
                    } else {
                        $('.oraculo-test-status').text('<?php _e('Falhou em alguma etapa', 'oraculo-tainacan'); ?>').addClass('status-error');
                        $('.oraculo-test-time').text('N/A');
                        $('.oraculo-test-items-count').text('0');
                    }
                    
                    // Exibe as etapas do processo
                    if (debug_info.steps && debug_info.steps.length > 0) {
                        const stepsContainer = $('.oraculo-test-steps');
                        
                        debug_info.steps.forEach((step, index) => {
                            const stepEl = $('<div class="oraculo-test-step"></div>');
                            const stepHeader = $(`
                                <div class="oraculo-step-header">
                                    <div class="oraculo-step-number">${index + 1}</div>
                                    <div class="oraculo-step-title">${step.step}</div>
                                    <div class="oraculo-step-status ${step.success !== false ? 'status-success' : 'status-error'}">
                                        ${step.success !== false ? '✓' : '✗'}
                                    </div>
                                </div>
                            `);
                            
                            const stepDetails = $('<div class="oraculo-step-details"></div>');
                            
                            // Adiciona detalhes específicos de cada etapa
                            if (step.time_seconds) {
                                stepDetails.append(`<div class="step-detail"><strong><?php _e('Tempo:', 'oraculo-tainacan'); ?></strong> ${step.time_seconds} <?php _e('segundos', 'oraculo-tainacan'); ?></div>`);
                            }
                            
                            if (step.message) {
                                stepDetails.append(`<div class="step-detail"><strong><?php _e('Mensagem:', 'oraculo-tainacan'); ?></strong> ${step.message}</div>`);
                            }
                            
                            if (step.found_items) {
                                stepDetails.append(`<div class="step-detail"><strong><?php _e('Itens encontrados:', 'oraculo-tainacan'); ?></strong> ${step.found_items}</div>`);
                            }
                            
                            if (step.vector_size) {
                                stepDetails.append(`<div class="step-detail"><strong><?php _e('Tamanho do vetor:', 'oraculo-tainacan'); ?></strong> ${step.vector_size} <?php _e('dimensões', 'oraculo-tainacan'); ?></div>`);
                            }
                            
                            // Adiciona informações extras para certos tipos de etapas
                            if (step.step === 'Busca por similaridade' && step.items && step.items.length > 0) {
                                const itemsList = $('<div class="step-items-list"></div>');
                                stepDetails.append('<div class="step-detail"><strong><?php _e('Itens similares:', 'oraculo-tainacan'); ?></strong></div>');
                                
                                step.items.forEach(item => {
                                    const similarity = (item.similarity * 100).toFixed(1);
                                    itemsList.append(`
                                        <div class="step-item">
                                            <div class="item-similarity">${similarity}%</div>
                                            <div class="item-info">
                                                <div class="item-collection">${item.collection_name}</div>
                                                <div class="item-content">${item.content.substring(0, 100)}...</div>
                                            </div>
                                        </div>
                                    `);
                                });
                                
                                stepDetails.append(itemsList);
                            }
                            
                            if (step.step === 'Geração de prompt') {
                                stepDetails.append(`
                                    <div class="step-detail">
                                        <strong><?php _e('Prompt do sistema:', 'oraculo-tainacan'); ?></strong>
                                        <pre class="step-code">${step.system_prompt}</pre>
                                    </div>
                                    <div class="step-detail">
                                        <strong><?php _e('Prompt do usuário:', 'oraculo-tainacan'); ?></strong>
                                        <pre class="step-code">${step.user_prompt}</pre>
                                    </div>
                                `);
                            }
                            
                            if (step.step === 'Geração de resposta' && step.response) {
                                stepDetails.append(`
                                    <div class="step-detail">
                                        <strong><?php _e('Resposta gerada:', 'oraculo-tainacan'); ?></strong>
                                        <pre class="step-response">${step.response}</pre>
                                    </div>
                                `);
                            }
                            
                            stepEl.append(stepHeader);
                            stepEl.append(stepDetails);
                            stepsContainer.append(stepEl);
                        });
                    }
                    
                    // Exibe o container de resultados
                    $('#test-results-container').show();
                } else {
                    alert('<?php _e('Erro ao executar busca de teste.', 'oraculo-tainacan'); ?>');
                }
            },
            error: function() {
                alert('<?php _e('Erro de comunicação com o servidor.', 'oraculo-tainacan'); ?>');
            },
            complete: function() {
                button.prop('disabled', false);
                button.html('<?php _e('Executar Busca de Teste', 'oraculo-tainacan'); ?>');
            }
        });
    });
});
</script>

<style>
.oraculo-debug-container {
    margin-top: 20px;
}

.oraculo-admin-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-bottom: 20px;
    padding: 20px;
    border-radius: 4px;
}

.oraculo-admin-card h2 {
    margin-top: 0;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.oraculo-test-form {
    margin-top: 20px;
}

.oraculo-form-row {
    margin-bottom: 15px;
}

.oraculo-form-row label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}

.oraculo-collections-selector {
    max-height: 200px;
    overflow-y: auto;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.oraculo-collection-checkbox {
    display: block;
    margin-bottom: 8px;
}

.items-count {
    color: #777;
    font-size: 0.9em;
}

.oraculo-form-buttons {
    margin-top: 20px;
}

/* Resultados de teste */
.oraculo-test-info {
    margin-top: 15px;
}

.oraculo-test-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 15px;
    align-items: center;
}

.oraculo-test-query {
    font-size: 16px;
    font-weight: bold;
}

.oraculo-test-timestamp {
    color: #777;
}

.oraculo-test-summary {
    margin-bottom: 20px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 4px;
}

.oraculo-test-stats {
    display: flex;
    gap: 20px;
}

.oraculo-test-stat {
    display: flex;
    flex-direction: column;
}

.oraculo-test-stat-label {
    font-weight: bold;
    margin-bottom: 5px;
}

.status-success {
    color: #46b450;
}

.status-error {
    color: #dc3232;
}

.status-warning {
    color: #ffb900;
}

.oraculo-test-steps-container {
    margin-bottom: 30px;
}

.oraculo-test-step {
    margin-bottom: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    overflow: hidden;
}

.oraculo-step-header {
    display: flex;
    align-items: center;
    padding: 10px 15px;
    background: #f5f5f5;
   cursor: pointer;
}

.oraculo-step-number {
    width: 25px;
    height: 25px;
    border-radius: 50%;
    background: #0073aa;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 10px;
    font-weight: bold;
}

.oraculo-step-title {
    flex-grow: 1;
    font-weight: bold;
}

.oraculo-step-status {
    font-weight: bold;
}

.oraculo-step-details {
    padding: 15px;
    border-top: 1px solid #eee;
}

.step-detail {
    margin-bottom: 10px;
}

.step-code, .step-response {
    white-space: pre-wrap;
    background: #f9f9f9;
    padding: 10px;
    border: 1px solid #eee;
    border-radius: 4px;
    margin-top: 5px;
    max-height: 200px;
    overflow-y: auto;
    font-family: monospace;
    font-size: 13px;
}

.step-items-list {
    margin-top: 10px;
    max-height: 300px;
    overflow-y: auto;
}

.step-item {
    display: flex;
    margin-bottom: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.step-item:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.item-similarity {
    width: 60px;
    font-weight: bold;
    color: #0073aa;
}

.item-info {
    flex-grow: 1;
}

.item-collection {
    font-weight: bold;
    margin-bottom: 5px;
}

.item-content {
    font-size: 0.9em;
    color: #555;
}

.oraculo-test-final-result {
    margin-top: 30px;
}

.oraculo-test-response {
    background: #f9f9f9;
    padding: 20px;
    border-radius: 4px;
    border-left: 4px solid #0073aa;
}

/* Diagnósticos */
.oraculo-diagnostics h3 {
    margin-top: 25px;
    margin-bottom: 15px;
    padding-bottom: 5px;
    border-bottom: 1px solid #eee;
}

.oraculo-diagnostics-table {
    margin-bottom: 25px;
}

.oraculo-diagnostics-table td {
    padding: 10px;
}

.oraculo-diagnostics-table .status {
    width: 150px;
    text-align: right;
}

.status-ok {
    color: #46b450;
    font-weight: bold;
}

.status-warning {
    color: #ffb900;
    font-weight: bold;
}

.status-error {
    color: #dc3232;
    font-weight: bold;
}

.status-info {
    color: #0073aa;
    font-style: italic;
}

.oraculo-log-container {
    background: #23282d;
    color: #eee;
    font-family: monospace;
    padding: 15px;
    border-radius: 4px;
    max-height: 400px;
    overflow-y: auto;
}

.log-line {
    padding: 3px 0;
    white-space: pre-wrap;
    word-wrap: break-word;
}

.log-error {
    color: #f55;
}

.log-debug {
    color: #5af;
}

@media (max-width: 782px) {
    .oraculo-test-stats {
        flex-direction: column;
        gap: 10px;
    }
}
</style>