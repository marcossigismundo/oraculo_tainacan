<?php
/**
 * Página de dashboard do Oráculo Tainacan
 *
 * @package Oraculo_Tainacan
 */

// Impede o acesso direto
if (!defined('WPINC')) {
    die;
}

// Inicializa o Vector DB para obter histórico e estatísticas
require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-vector-db.php';
$vector_db = new Oraculo_Tainacan_Vector_DB();

// Obtém as estatísticas
$stats = $vector_db->get_search_stats('day');

// Filtros
$start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');
$search_term = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$collection = isset($_GET['collection']) ? sanitize_text_field($_GET['collection']) : '';

// Obtém o histórico de buscas
$history = $vector_db->get_search_history(array(
    'start_date' => $start_date,
    'end_date' => $end_date,
    'search' => $search_term,
    'collection' => $collection,
    'limit' => 100
));
?>

<div class="wrap oraculo-admin-page">
    <h1><?php _e('Dashboard do Oráculo Tainacan', 'oraculo-tainacan'); ?></h1>
    
    <div class="oraculo-dashboard-container">
        <div class="oraculo-dashboard-stats">
            <div class="oraculo-admin-card">
                <h2><?php _e('Visão Geral', 'oraculo-tainacan'); ?></h2>
                
                <div class="oraculo-stats-grid">
                    <div class="oraculo-stat-box">
                        <span class="oraculo-stat-value"><?php echo count($history); ?></span>
                        <span class="oraculo-stat-label"><?php _e('Buscas no período', 'oraculo-tainacan'); ?></span>
                    </div>
                    
                    <div class="oraculo-stat-box">
                        <span class="oraculo-stat-value">
                            <?php 
                            // Calcula a média de buscas por dia
                            $days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
                            echo round(count($history) / $days, 1);
                            ?>
                        </span>
                        <span class="oraculo-stat-label"><?php _e('Média por dia', 'oraculo-tainacan'); ?></span>
                    </div>
                    
                    <div class="oraculo-stat-box">
                        <span class="oraculo-stat-value">
                            <?php 
                            // Calcula o percentual de buscas com feedback positivo
                            $positive_feedback = 0;
                            foreach ($history as $item) {
                                if (isset($item['feedback']) && $item['feedback'] == 1) {
                                    $positive_feedback++;
                                }
                            }
                            echo count($history) > 0 ? round(($positive_feedback / count($history)) * 100) . '%' : '0%';
                            ?>
                        </span>
                        <span class="oraculo-stat-label"><?php _e('Feedback positivo', 'oraculo-tainacan'); ?></span>
                    </div>
                </div>
                
                <div class="oraculo-chart-container">
                    <canvas id="searchesChart"></canvas>
                </div>
            </div>
            
            <div class="oraculo-admin-card">
                <h2><?php _e('Coleções mais consultadas', 'oraculo-tainacan'); ?></h2>
                
                <div class="oraculo-chart-container">
                    <canvas id="collectionsChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="oraculo-dashboard-history">
            <div class="oraculo-admin-card">
                <h2><?php _e('Histórico de Buscas', 'oraculo-tainacan'); ?></h2>
                
                <div class="oraculo-filters">
                    <form method="get">
                        <input type="hidden" name="page" value="oraculo-tainacan-dashboard">
                        
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
                                <a href="?page=oraculo-tainacan-dashboard" class="button"><?php _e('Limpar', 'oraculo-tainacan'); ?></a>
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="oraculo-export-options">
                    <button id="export-json" class="button" data-format="json"><?php _e('Exportar JSON', 'oraculo-tainacan'); ?></button>
                    <button id="export-csv" class="button" data-format="csv"><?php _e('Exportar CSV', 'oraculo-tainacan'); ?></button>
                </div>
                
                <?php if (empty($history)): ?>
                    <div class="notice notice-info inline">
                        <p><?php _e('Nenhuma busca encontrada com os filtros aplicados.', 'oraculo-tainacan'); ?></p>
                    </div>
                <?php else: ?>
                    <table class="widefat striped oraculo-history-table">
                        <thead>
                            <tr>
                                <th><?php _e('Data/Hora', 'oraculo-tainacan'); ?></th>
                                <th><?php _e('Pergunta', 'oraculo-tainacan'); ?></th>
                                <th><?php _e('Itens', 'oraculo-tainacan'); ?></th>
                                <th><?php _e('Coleções', 'oraculo-tainacan'); ?></th>
                                <th><?php _e('Feedback', 'oraculo-tainacan'); ?></th>
                                <th><?php _e('Ações', 'oraculo-tainacan'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item['created_at']); ?></td>
                                    <td>
                                        <div class="oraculo-query-text"><?php echo esc_html($item['query']); ?></div>
                                    </td>
                                    <td>
                                        <?php 
                                        if (is_array($item['items_used'])) {
                                            echo count($item['items_used']);
                                        } else {
                                            echo '0';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (is_array($item['collections_used'])) {
                                            echo implode(', ', $item['collections_used']);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        if (isset($item['feedback'])) {
                                            if ($item['feedback'] === 1) {
                                                echo '<span class="oraculo-feedback-positive">✓</span>';
                                            } elseif ($item['feedback'] === 0) {
                                                echo '<span class="oraculo-feedback-negative">✗</span>';
                                            }
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <button class="button view-details" data-id="<?php echo esc_attr($item['id']); ?>">
                                            <?php _e('Detalhes', 'oraculo-tainacan'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal de detalhes -->
    <div id="details-modal" class="oraculo-modal">
        <div class="oraculo-modal-content oraculo-modal-large">
            <span class="oraculo-modal-close">&times;</span>
            <h2 id="details-modal-title"><?php _e('Detalhes da Busca', 'oraculo-tainacan'); ?></h2>
            
            <div class="oraculo-details-content">
                <div class="oraculo-details-section">
                    <h3><?php _e('Pergunta', 'oraculo-tainacan'); ?></h3>
                    <div id="details-query" class="oraculo-details-box"></div>
                </div>
                
                <div class="oraculo-details-section">
                    <h3><?php _e('Resposta', 'oraculo-tainacan'); ?></h3>
                    <div id="details-response" class="oraculo-details-box"></div>
                </div>
                
                <div class="oraculo-details-section">
                    <h3><?php _e('Itens Utilizados', 'oraculo-tainacan'); ?></h3>
                    <div id="details-items" class="oraculo-details-box"></div>
                </div>
                
                <div class="oraculo-details-feedback">
                    <h3><?php _e('Feedback', 'oraculo-tainacan'); ?></h3>
                    <div id="details-feedback" class="oraculo-details-box">
                        <div id="feedback-value"></div>
                        <div id="feedback-notes"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Preparando dados para os gráficos
    const periodData = <?php echo json_encode($stats['period']); ?>;
    const collectionsData = <?php echo json_encode($stats['collections']); ?>;
    
    // Gráfico de buscas por período
    const searchesCtx = document.getElementById('searchesChart').getContext('2d');
    new Chart(searchesCtx, {
        type: 'line',
        data: {
            labels: periodData.map(item => item.period_label),
            datasets: [{
                label: '<?php _e('Buscas', 'oraculo-tainacan'); ?>',
                data: periodData.map(item => item.count),
                backgroundColor: 'rgba(54, 162, 235, 0.2)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1,
                tension: 0.1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });
    
    // Gráfico de coleções mais consultadas
    const collectionsCtx = document.getElementById('collectionsChart').getContext('2d');
    new Chart(collectionsCtx, {
        type: 'bar',
        data: {
            labels: collectionsData.map(item => item.collection),
            datasets: [{
                label: '<?php _e('Consultas', 'oraculo-tainacan'); ?>',
                data: collectionsData.map(item => item.count),
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                borderColor: 'rgba(75, 192, 192, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });
    
    // Exportar dados
    $('#export-json, #export-csv').on('click', function() {
        const format = $(this).data('format');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'oraculo_export_data',
                export_type: 'search_history',
                format: format,
                filters: {
                    start_date: '<?php echo esc_js($start_date); ?>',
                    end_date: '<?php echo esc_js($end_date); ?>',
                    search: '<?php echo esc_js($search_term); ?>',
                    collection: '<?php echo esc_js($collection); ?>'
                },
                nonce: '<?php echo wp_create_nonce('oraculo_admin_nonce'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    // Cria um elemento de download
                    const element = document.createElement('a');
                    
                    if (format === 'json') {
                        element.setAttribute('href', 'data:text/json;charset=utf-8,' + encodeURIComponent(response.data.data));
                    } else {
                        element.setAttribute('href', 'data:text/csv;charset=utf-8,' + encodeURIComponent(response.data.data));
                    }
                    
                    element.setAttribute('download', response.data.filename);
                    element.style.display = 'none';
                    
                    document.body.appendChild(element);
                    element.click();
                    document.body.removeChild(element);
                } else {
                    alert('<?php _e('Erro ao exportar dados.', 'oraculo-tainacan'); ?>');
                }
            }
        });
    });
    
    // Ver detalhes
    $('.view-details').on('click', function() {
        const id = $(this).data('id');
        const row = $(this).closest('tr');
        const query = row.find('.oraculo-query-text').text();
        
        // Encontra o item no histórico
        <?php 
        echo "const historyData = " . json_encode($history) . ";";
        ?>
        
        const item = historyData.find(h => h.id == id);
        
        if (item) {
            $('#details-query').text(item.query);
            $('#details-response').html(item.response.replace(/\n/g, '<br>'));
            
            // Renderiza itens
            let itemsHtml = '<ul class="oraculo-items-list">';
            if (Array.isArray(item.items_used)) {
                item.items_used.forEach(i => {
                    itemsHtml += `<li>
                        <strong>${i.title}</strong>
                        <div class="item-description">${i.description}</div>
                        <div class="item-metadata">
                            <strong><?php _e('Coleção:', 'oraculo-tainacan'); ?></strong> ${i.collection} | 
                            <strong><?php _e('Relevância:', 'oraculo-tainacan'); ?></strong> ${(i.similarity * 100).toFixed(1)}%
                        </div>
                        <a href="${i.permalink}" target="_blank" class="button button-small">
                            <?php _e('Ver no Tainacan', 'oraculo-tainacan'); ?>
                        </a>
                    </li>`;
                });
            }
            itemsHtml += '</ul>';
            $('#details-items').html(itemsHtml);
            
            // Feedback
            if (item.feedback === 1) {
                $('#feedback-value').html('<span class="oraculo-feedback-positive">✓ <?php _e('Feedback Positivo', 'oraculo-tainacan'); ?></span>');
            } else if (item.feedback === 0) {
                $('#feedback-value').html('<span class="oraculo-feedback-negative">✗ <?php _e('Feedback Negativo', 'oraculo-tainacan'); ?></span>');
            } else {
                $('#feedback-value').html('<span><?php _e('Sem feedback', 'oraculo-tainacan'); ?></span>');
            }
            
            if (item.feedback_notes) {
                $('#feedback-notes').html('<p>' + item.feedback_notes + '</p>');
            } else {
                $('#feedback-notes').empty();
            }
            
            // Exibe o modal
            $('#details-modal').css('display', 'block');
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
.oraculo-dashboard-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
    margin-top: 20px;
}

.oraculo-dashboard-stats {
    display: flex;
    gap: 20px;
}

.oraculo-dashboard-stats > div {
    flex: 1;
}

.oraculo-admin-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-bottom: 20px;
    padding: 15px;
    border-radius: 4px;
}

.oraculo-admin-card h2 {
    margin-top: 0;
    padding-bottom: 12px;
    border-bottom: 1px solid #eee;
}

.oraculo-stats-grid {
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

.oraculo-chart-container {
    height: 300px;
    margin-top: 20px;
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

.oraculo-export-options {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.oraculo-history-table {
    border-collapse: collapse;
    width: 100%;
}

.oraculo-history-table th, 
.oraculo-history-table td {
    padding: 10px;
}

.oraculo-query-text {
    max-width: 300px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.oraculo-feedback-positive {
    color: #46b450;
    font-weight: bold;
}

.oraculo-feedback-negative {
    color: #dc3232;
    font-weight: bold;
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
    max-width: 900px;
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

.oraculo-details-content {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.oraculo-details-section {
    margin-bottom: 20px;
}

.oraculo-details-section h3,
.oraculo-details-feedback h3 {
    margin-top: 0;
    margin-bottom: 10px;
    padding-bottom: 5px;
    border-bottom: 1px solid #eee;
}

.oraculo-details-box {
    background: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
    max-height: 300px;
    overflow-y: auto;
}

.oraculo-items-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.oraculo-items-list li {
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.oraculo-items-list li:last-child {
    margin-bottom: 0;
    padding-bottom: 0;
    border-bottom: none;
}

.item-description {
    margin: 5px 0;
    font-size: 14px;
}

.item-metadata {
    margin-bottom: 5px;
    font-size: 13px;
}

@media (max-width: 1024px) {
    .oraculo-dashboard-stats {
        flex-direction: column;
    }
    
    .oraculo-details-content {
        grid-template-columns: 1fr;
    }
}
</style>
