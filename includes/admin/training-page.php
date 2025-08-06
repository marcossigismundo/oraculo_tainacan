<?php
/**
 * Página de treinamento do Oráculo Tainacan
 *
 * @package Oraculo_Tainacan
 */

// Impede o acesso direto
if (!defined('WPINC')) {
    die;
}

// Inicializa o Vector DB para obter histórico de buscas
require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-vector-db.php';
$vector_db = new Oraculo_Tainacan_Vector_DB();

// Filtros (apenas buscas com feedback positivo)
$start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-90 days'));
$end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');
$search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$collection = isset($_GET['collection']) ? sanitize_text_field($_GET['collection']) : '';

// Obtém o histórico de buscas com feedback positivo
$training_data = $vector_db->get_search_history(array(
    'start_date' => $start_date,
    'end_date' => $end_date,
    'search' => $search_term,
    'collection' => $collection,
    'feedback' => 1,
    'limit' => 100
));
?>

<div class="wrap oraculo-admin-page">
    <h1><?php _e('Treinamento do Oráculo Tainacan', 'oraculo-tainacan'); ?></h1>
    
    <div class="oraculo-admin-card">
        <h2><?php _e('Dados de Treinamento', 'oraculo-tainacan'); ?></h2>
        
        <p>
            <?php _e('Esta página exibe as perguntas/respostas marcadas como úteis pelos usuários. Estes dados podem ser exportados para uso em futuros fine-tuning do modelo.', 'oraculo-tainacan'); ?>
        </p>
        
        <div class="oraculo-filters">
            <form method="get">
                <input type="hidden" name="page" value="oraculo-tainacan-training">
                
                <div class="oraculo-filters-row">
                    <div class="oraculo-filter-field">
                        <label for="start_date"><?php _e('Data inicial:', 'oraculo-tainacan'); ?></label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                    </div>
                    
                    <div class="oraculo-filter-field">
                        <label for="end_date"><?php _e('Data final:', 'oraculo-tainacan'); ?></label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                    </div>
                    
                    <div class="oraculo-filter-field">
                        <label for="search"><?php _e('Buscar:', 'oraculo-tainacan'); ?></label>
                        <input type="text" id="search" name="search" value="<?php echo esc_attr($search_term); ?>" placeholder="<?php _e('Termo de busca...', 'oraculo-tainacan'); ?>">
                    </div>
                    
                    <div class="oraculo-filter-field">
                        <label for="collection"><?php _e('Coleção:', 'oraculo-tainacan'); ?></label>
                        <input type="text" id="collection" name="collection" value="<?php echo esc_attr($collection); ?>" placeholder="<?php _e('Nome da coleção...', 'oraculo-tainacan'); ?>">
                    </div>
                    
                    <div class="oraculo-filter-field oraculo-filter-buttons">
                        <button type="submit" class="button"><?php _e('Filtrar', 'oraculo-tainacan'); ?></button>
                        <a href="?page=oraculo-tainacan-training" class="button"><?php _e('Limpar', 'oraculo-tainacan'); ?></a>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="oraculo-training-actions">
            <button id="export-openai-training" class="button button-primary"><?php _e('Exportar para OpenAI Fine-tuning', 'oraculo-tainacan'); ?></button>
            <button id="export-training-json" class="button"><?php _e('Exportar JSON', 'oraculo-tainacan'); ?></button>
            <button id="export-training-csv" class="button"><?php _e('Exportar CSV', 'oraculo-tainacan'); ?></button>
        </div>
        
        <?php if (empty($training_data)): ?>
            <div class="notice notice-info inline">
                <p><?php _e('Nenhum dado de treinamento encontrado com os filtros aplicados.', 'oraculo-tainacan'); ?></p>
            </div>
        <?php else: ?>
            <div class="oraculo-training-stats">
                <div class="oraculo-stat-box">
                    <span class="oraculo-stat-value"><?php echo count($training_data); ?></span>
                    <span class="oraculo-stat-label"><?php _e('Total de exemplos', 'oraculo-tainacan'); ?></span>
                </div>
                
                <div class="oraculo-stat-box">
                    <span class="oraculo-stat-value">
                        <?php
                        // Calcula a média de tokens por exemplo
                        $total_tokens = 0;
                        foreach ($training_data as $item) {
                            // Estimativa simples: 4 tokens por palavra
                            $query_tokens = str_word_count($item['query']) * 4;
                            $response_tokens = str_word_count($item['response']) * 4;
                            $total_tokens += $query_tokens + $response_tokens;
                        }
                        echo count($training_data) > 0 ? round($total_tokens / count($training_data)) : 0;
                        ?>
                    </span>
                    <span class="oraculo-stat-label"><?php _e('Média de tokens/exemplo', 'oraculo-tainacan'); ?></span>
                </div>
                
                <div class="oraculo-stat-box">
                    <span class="oraculo-stat-value">
                        <?php
                        // Calcula o custo estimado de treinamento
                        // Baseado na documentação da OpenAI: $0.008 por 1K tokens para treinamento (valores de 2024)
                        $estimated_cost = ($total_tokens / 1000) * 0.008;
                        echo '$' . number_format($estimated_cost, 2);
                        ?>
                    </span>
                    <span class="oraculo-stat-label"><?php _e('Custo estimado de treinamento', 'oraculo-tainacan'); ?></span>
                </div>
            </div>
            
            <table class="widefat striped oraculo-training-table">
                <thead>
                    <tr>
                        <th><?php _e('Data/Hora', 'oraculo-tainacan'); ?></th>
                        <th><?php _e('Pergunta', 'oraculo-tainacan'); ?></th>
                        <th><?php _e('Resposta', 'oraculo-tainacan'); ?></th>
                        <th><?php _e('Coleções', 'oraculo-tainacan'); ?></th>
                        <th><?php _e('Ações', 'oraculo-tainacan'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($training_data as $item): ?>
                        <tr>
                            <td><?php echo esc_html($item['created_at']); ?></td>
                            <td>
                                <div class="oraculo-training-text"><?php echo esc_html($item['query']); ?></div>
                            </td>
                            <td>
                                <div class="oraculo-training-text"><?php echo esc_html(wp_trim_words($item['response'], 30, '...')); ?></div>
                            </td>
                            <td>
                                <?php 
                                if (is_array($item['collections_used'])) {
                                    echo implode(', ', $item['collections_used']);
                                }
                                ?>
                            </td>
                            <td>
                                <button class="button view-training-details" data-id="<?php echo esc_attr($item['id']); ?>">
                                    <?php _e('Editar', 'oraculo-tainacan'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- Modal de edição -->
    <div id="training-modal" class="oraculo-modal">
        <div class="oraculo-modal-content oraculo-modal-large">
            <span class="oraculo-modal-close">&times;</span>
            <h2 id="training-modal-title"><?php _e('Editar Exemplo de Treinamento', 'oraculo-tainacan'); ?></h2>
            
            <div class="oraculo-training-edit-form">
                <input type="hidden" id="training-item-id" value="">
                
                <div class="oraculo-form-row">
                    <label for="training-query"><?php _e('Pergunta:', 'oraculo-tainacan'); ?></label>
                    <textarea id="training-query" rows="3" class="widefat"></textarea>
                </div>
                
                <div class="oraculo-form-row">
                    <label for="training-response"><?php _e('Resposta:', 'oraculo-tainacan'); ?></label>
                    <textarea id="training-response" rows="8" class="widefat"></textarea>
                </div>
                
                <div class="oraculo-form-row">
                    <label for="training-notes"><?php _e('Notas/Observações:', 'oraculo-tainacan'); ?></label>
                    <textarea id="training-notes" rows="3" class="widefat"></textarea>
                    <p class="description"><?php _e('Observações internas sobre este exemplo (não afeta o treinamento).', 'oraculo-tainacan'); ?></p>
                </div>
                
                <div class="oraculo-form-buttons">
                    <button id="save-training-item" class="button button-primary"><?php _e('Salvar', 'oraculo-tainacan'); ?></button>
                    <button id="remove-from-training" class="button button-link-delete"><?php _e('Remover dos dados de treinamento', 'oraculo-tainacan'); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Carrega dados de treinamento
    <?php echo "const trainingData = " . json_encode($training_data) . ";"; ?>
    
    // Exportar dados para fine-tuning da OpenAI
    $('#export-openai-training').on('click', function() {
        // Formata os dados para o formato JSONL da OpenAI
        let jsonlContent = '';
        
        trainingData.forEach(item => {
            const example = {
                messages: [
                    {
                        role: "system",
                        content: "Você é um oráculo digital especializado em acervos museológicos. Responda com base apenas nas informações dos documentos fornecidos. Use linguagem clara, confiante e educativa. Nunca invente nada."
                    },
                    {
                        role: "user",
                        content: item.query
                    },
                    {
                        role: "assistant",
                        content: item.response
                    }
                ]
            };
            
            jsonlContent += JSON.stringify(example) + '\n';
        });
        
        // Cria um elemento de download
        downloadData(jsonlContent, 'oraculo-fine-tuning-data.jsonl', 'text/jsonl');
    });
    
    // Exportar JSON
    $('#export-training-json').on('click', function() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'oraculo_export_data',
                export_type: 'training_data',
                format: 'json',
                filters: {
                    start_date: '<?php echo esc_js($start_date); ?>',
                    end_date: '<?php echo esc_js($end_date); ?>',
                    search: '<?php echo esc_js($search_term); ?>',
                    collection: '<?php echo esc_js($collection); ?>',
                    feedback: 1
                },
                nonce: '<?php echo wp_create_nonce('oraculo_admin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    downloadData(response.data.data, response.data.filename, 'text/json');
                } else {
                    alert('<?php _e('Erro ao exportar dados.', 'oraculo-tainacan'); ?>');
                }
            }
        });
    });
    
    // Exportar CSV
    $('#export-training-csv').on('click', function() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'oraculo_export_data',
                export_type: 'training_data',
                format: 'csv',
                filters: {
                    start_date: '<?php echo esc_js($start_date); ?>',
                    end_date: '<?php echo esc_js($end_date); ?>',
                    search: '<?php echo esc_js($search_term); ?>',
                    collection: '<?php echo esc_js($collection); ?>',
                    feedback: 1
                },
                nonce: '<?php echo wp_create_nonce('oraculo_admin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    downloadData(response.data.data, response.data.filename, 'text/csv');
                } else {
                    alert('<?php _e('Erro ao exportar dados.', 'oraculo-tainacan'); ?>');
                }
            }
        });
    });
    
    // Função auxiliar para download
    function downloadData(content, filename, type) {
        const element = document.createElement('a');
        element.setAttribute('href', 'data:' + type + ';charset=utf-8,' + encodeURIComponent(content));
        element.setAttribute('download', filename);
        element.style.display = 'none';
        document.body.appendChild(element);
        element.click();
        document.body.removeChild(element);
    }
    
    // Ver detalhes de treinamento
    $('.view-training-details').on('click', function() {
        const id = $(this).data('id');
        const item = trainingData.find(t => t.id == id);
        
        if (item) {
            $('#training-item-id').val(item.id);
            $('#training-query').val(item.query);
            $('#training-response').val(item.response);
            $('#training-notes').val(item.feedback_notes || '');
            
            // Exibe o modal
            $('#training-modal').css('display', 'block');
        }
    });
    
    // Salvar item de treinamento
    $('#save-training-item').on('click', function() {
        const id = $('#training-item-id').val();
        const query = $('#training-query').val();
        const response = $('#training-response').val();
        const notes = $('#training-notes').val();
        
        if (!query || !response) {
            alert('<?php _e('Pergunta e resposta são obrigatórios.', 'oraculo-tainacan'); ?>');
            return;
        }
        
        // Simula salvamento (na implementação final, faria uma requisição AJAX)
        alert('<?php _e('Funcionalidade de salvamento a ser implementada na versão final.', 'oraculo-tainacan'); ?>');
        
        // Fecha o modal
        $('#training-modal').css('display', 'none');
    });
    
    // Remover dos dados de treinamento
    $('#remove-from-training').on('click', function() {
        const id = $('#training-item-id').val();
        
        if (confirm('<?php _e('Tem certeza que deseja remover este exemplo dos dados de treinamento?', 'oraculo-tainacan'); ?>')) {
            // Simula remoção (na implementação final, faria uma requisição AJAX)
            alert('<?php _e('Funcionalidade de remoção a ser implementada na versão final.', 'oraculo-tainacan'); ?>');
            
            // Fecha o modal
            $('#training-modal').css('display', 'none');
        }
    });
    
    // Fechar modal
    $('.oraculo-modal-close').on('click', function() {
        $('.oraculo-modal').css('display', 'none');
    });
    
    // Fechar modal ao clicar fora
    $(window).on('click', function(event) {
        if ($(event.target).hasClass('oraculo-modal')) {
            $('.oraculo-modal').css('display', 'none');
        }
    });
});
</script>

<style>
.oraculo-admin-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-bottom: 20px;
    padding: 20px;
    border-radius: 4px;
}

.oraculo-filters {
    margin-bottom: 20px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 4px;
}

.oraculo-filters-row {
	display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.oraculo-filter-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.oraculo-filter-buttons {
    justify-content: flex-end;
    align-items: flex-end;
}

.oraculo-training-actions {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}

.oraculo-training-stats {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
}

.oraculo-stat-box {
    flex: 1;
    padding: 15px;
    border-radius: 4px;
    background: #f9f9f9;
    text-align: center;
}

.oraculo-stat-value {
    display: block;
    font-size: 24px;
    font-weight: bold;
    color: #0073aa;
    margin-bottom: 5px;
}

.oraculo-stat-label {
    display: block;
    font-size: 14px;
    color: #555;
}

.oraculo-training-table {
    border-collapse: collapse;
    width: 100%;
}

.oraculo-training-table th, 
.oraculo-training-table td {
    padding: 10px;
}

.oraculo-training-text {
    max-width: 300px;
    max-height: 100px;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Modal */
.oraculo-modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.4);
}

.oraculo-modal-content {
    background-color: #fefefe;
    margin: 5% auto;
    padding: 20px;
    border: 1px solid #888;
    width: 80%;
    max-width: 800px;
    border-radius: 5px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.oraculo-modal-large {
    width: 80%;
    max-width: 900px;
}

.oraculo-modal-close {
    color: #aaa;
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
}

.oraculo-training-edit-form {
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

.oraculo-form-buttons {
    display: flex;
    justify-content: space-between;
    margin-top: 20px;
}

@media (max-width: 782px) {
    .oraculo-training-stats,
    .oraculo-training-actions {
        flex-direction: column;
    }
}
</style>