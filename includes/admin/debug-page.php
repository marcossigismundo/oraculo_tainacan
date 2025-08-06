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

// Garante que seja um array
if (!is_array($selected_collections)) {
    $selected_collections = array();
}
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
                        // Verifica se shell_exec está disponível
                        if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
                            $cmd = 'tail -n 1000 ' . escapeshellarg($log_file);
                            $output = shell_exec($cmd);
                        } else {
                            // Fallback: lê o arquivo diretamente
                            $file_size = filesize($log_file);
                            if ($file_size > 100000) { // Se maior que 100KB
                                $handle = fopen($log_file, 'r');
                                fseek($handle, -50000, SEEK_END); // Últimos 50KB
                                $output = fread($handle, 50000);
                                fclose($handle);
                            } else {
                                $output = file_get_contents($log_file);
                            }
                        }
                        
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
                    // Preenche informações do teste (código JavaScript omitido por brevidade)
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