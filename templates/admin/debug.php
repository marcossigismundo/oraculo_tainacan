<?php
/**
 * Template de Debug e Diagnóstico
 *
 * @package Oraculo_Tainacan
 */

defined('ABSPATH') || exit;

$factory = new \Oraculo_Tainacan\AI\AIProviderFactory();
$vector_store = new \Oraculo_Tainacan\Vector\VectorStore();

// Informações do ambiente
$env_info = [
    'PHP Version' => PHP_VERSION,
    'WordPress Version' => get_bloginfo('version'),
    'Plugin Version' => ORACULO_TAINACAN_VERSION,
    'Tainacan Active' => defined('TAINACAN_VERSION') ? TAINACAN_VERSION : 'Não',
    'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
    'MySQL Version' => $GLOBALS['wpdb']->db_version(),
    'PHP Memory Limit' => ini_get('memory_limit'),
    'PHP Max Execution Time' => ini_get('max_execution_time') . 's',
    'WP Debug' => WP_DEBUG ? 'Ativado' : 'Desativado',
    'cURL Enabled' => extension_loaded('curl') ? 'Sim' : 'Não',
    'JSON Enabled' => extension_loaded('json') ? 'Sim' : 'Não',
    'mbstring Enabled' => extension_loaded('mbstring') ? 'Sim' : 'Não',
    'Environment' => \Oraculo_Tainacan\detect_environment(),
];

// Status das tabelas
global $wpdb;
$tables = [
    'oraculo_vectors' => $wpdb->prefix . 'oraculo_vectors',
    'oraculo_search_logs' => $wpdb->prefix . 'oraculo_search_logs',
    'oraculo_conversations' => $wpdb->prefix . 'oraculo_conversations',
    'oraculo_messages' => $wpdb->prefix . 'oraculo_messages',
];

$table_status = [];
foreach ($tables as $name => $full_name) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_name}'") === $full_name;
    $count = $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$full_name}") : 0;
    $table_status[$name] = [
        'exists' => $exists,
        'count' => $count,
    ];
}

// Status dos provedores
$providers = $factory->get_available_providers();

// Últimas buscas
$recent_searches = $wpdb->get_results(
    "SELECT query_text, results_count, response_time_ms, feedback, created_at
     FROM {$wpdb->prefix}oraculo_search_logs
     ORDER BY created_at DESC
     LIMIT 10",
    ARRAY_A
);

// Erros recentes
$recent_errors = get_option('oraculo_recent_errors', []);
?>

<div class="wrap oraculo-admin oraculo-debug">
    <h1><?php esc_html_e('Debug e Diagnóstico', 'oraculo_tainacan'); ?></h1>

    <div class="oraculo-debug-grid">
        <!-- Informações do Ambiente -->
        <div class="oraculo-card">
            <h2><?php esc_html_e('Informações do Ambiente', 'oraculo_tainacan'); ?></h2>
            <table class="oraculo-debug-table">
                <?php foreach ($env_info as $key => $value) : ?>
                    <tr>
                        <th><?php echo esc_html($key); ?></th>
                        <td><?php echo esc_html($value); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <!-- Status das Tabelas -->
        <div class="oraculo-card">
            <h2><?php esc_html_e('Tabelas do Banco de Dados', 'oraculo_tainacan'); ?></h2>
            <table class="oraculo-debug-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Tabela', 'oraculo_tainacan'); ?></th>
                        <th><?php esc_html_e('Status', 'oraculo_tainacan'); ?></th>
                        <th><?php esc_html_e('Registros', 'oraculo_tainacan'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table_status as $name => $status) : ?>
                        <tr>
                            <td><?php echo esc_html($name); ?></td>
                            <td>
                                <?php if ($status['exists']) : ?>
                                    <span class="status-ok">OK</span>
                                <?php else : ?>
                                    <span class="status-error">FALTANDO</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo number_format($status['count']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button type="button" class="button" id="oraculo-repair-tables">
                    <?php esc_html_e('Reparar Tabelas', 'oraculo_tainacan'); ?>
                </button>
            </p>
        </div>

        <!-- Status dos Provedores -->
        <div class="oraculo-card full-width">
            <h2><?php esc_html_e('Status dos Provedores de IA', 'oraculo_tainacan'); ?></h2>
            <div class="providers-grid">
                <?php foreach ($providers as $provider) : ?>
                    <div class="provider-status-card">
                        <h4><?php echo esc_html($provider['name']); ?></h4>
                        <p class="provider-id"><?php echo esc_html($provider['id']); ?></p>
                        <ul>
                            <li>
                                <strong><?php esc_html_e('Configurado:', 'oraculo_tainacan'); ?></strong>
                                <?php if ($provider['is_configured']) : ?>
                                    <span class="status-ok">Sim</span>
                                <?php else : ?>
                                    <span class="status-warning">Não</span>
                                <?php endif; ?>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Embeddings:', 'oraculo_tainacan'); ?></strong>
                                <?php echo $provider['supports_embeddings'] ? 'Sim' : 'Não'; ?>
                            </li>
                            <li>
                                <strong><?php esc_html_e('Streaming:', 'oraculo_tainacan'); ?></strong>
                                <?php echo $provider['supports_streaming'] ? 'Sim' : 'Não'; ?>
                            </li>
                        </ul>
                        <?php if ($provider['is_configured']) : ?>
                            <button type="button" class="button button-small oraculo-test-provider"
                                    data-provider="<?php echo esc_attr($provider['id']); ?>">
                                <?php esc_html_e('Testar', 'oraculo_tainacan'); ?>
                            </button>
                        <?php endif; ?>
                        <div class="test-result"></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Buscas Recentes -->
        <div class="oraculo-card">
            <h2><?php esc_html_e('Buscas Recentes', 'oraculo_tainacan'); ?></h2>
            <?php if (empty($recent_searches)) : ?>
                <p class="oraculo-empty"><?php esc_html_e('Nenhuma busca registrada.', 'oraculo_tainacan'); ?></p>
            <?php else : ?>
                <table class="oraculo-debug-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Busca', 'oraculo_tainacan'); ?></th>
                            <th><?php esc_html_e('Resultados', 'oraculo_tainacan'); ?></th>
                            <th><?php esc_html_e('Tempo', 'oraculo_tainacan'); ?></th>
                            <th><?php esc_html_e('Data', 'oraculo_tainacan'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_searches as $search) : ?>
                            <tr>
                                <td><?php echo esc_html(wp_trim_words($search['query_text'], 5)); ?></td>
                                <td><?php echo (int) $search['results_count']; ?></td>
                                <td><?php echo number_format($search['response_time_ms']); ?>ms</td>
                                <td><?php echo esc_html($search['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Erros Recentes -->
        <div class="oraculo-card">
            <h2><?php esc_html_e('Erros Recentes', 'oraculo_tainacan'); ?></h2>
            <?php if (empty($recent_errors)) : ?>
                <p class="oraculo-empty"><?php esc_html_e('Nenhum erro registrado.', 'oraculo_tainacan'); ?></p>
            <?php else : ?>
                <div class="oraculo-errors-log">
                    <?php foreach ($recent_errors as $error) : ?>
                        <div class="error-entry">
                            <span class="error-time"><?php echo esc_html($error['time'] ?? ''); ?></span>
                            <span class="error-type"><?php echo esc_html($error['type'] ?? 'error'); ?></span>
                            <span class="error-message"><?php echo esc_html($error['message'] ?? ''); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p>
                    <button type="button" class="button" id="oraculo-clear-errors">
                        <?php esc_html_e('Limpar Erros', 'oraculo_tainacan'); ?>
                    </button>
                </p>
            <?php endif; ?>
        </div>

        <!-- Teste de Busca -->
        <div class="oraculo-card full-width">
            <h2><?php esc_html_e('Teste de Busca', 'oraculo_tainacan'); ?></h2>
            <form id="oraculo-test-search">
                <p>
                    <label for="test-query"><?php esc_html_e('Pergunta:', 'oraculo_tainacan'); ?></label>
                    <input type="text" id="test-query" class="regular-text"
                           placeholder="<?php esc_attr_e('Digite uma pergunta para testar...', 'oraculo_tainacan'); ?>">
                </p>
                <p>
                    <label>
                        <input type="checkbox" id="test-semantic-only" value="1">
                        <?php esc_html_e('Apenas busca semântica (sem IA)', 'oraculo_tainacan'); ?>
                    </label>
                </p>
                <p>
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Executar Teste', 'oraculo_tainacan'); ?>
                    </button>
                </p>
            </form>
            <div id="oraculo-test-results" class="oraculo-test-results"></div>
        </div>

        <!-- Ações de Manutenção -->
        <div class="oraculo-card full-width">
            <h2><?php esc_html_e('Ações de Manutenção', 'oraculo_tainacan'); ?></h2>
            <div class="maintenance-actions">
                <button type="button" class="button" id="oraculo-clear-cache">
                    <?php esc_html_e('Limpar Cache', 'oraculo_tainacan'); ?>
                </button>
                <button type="button" class="button" id="oraculo-optimize-tables">
                    <?php esc_html_e('Otimizar Tabelas', 'oraculo_tainacan'); ?>
                </button>
                <button type="button" class="button" id="oraculo-cleanup-old">
                    <?php esc_html_e('Limpar Dados Antigos (90 dias)', 'oraculo_tainacan'); ?>
                </button>
                <button type="button" class="button" id="oraculo-export-settings">
                    <?php esc_html_e('Exportar Configurações', 'oraculo_tainacan'); ?>
                </button>
                <button type="button" class="button button-danger" id="oraculo-reset-plugin">
                    <?php esc_html_e('Resetar Plugin', 'oraculo_tainacan'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.oraculo-debug-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-top: 20px;
}

.oraculo-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 8px;
    padding: 20px;
}

.oraculo-card.full-width {
    grid-column: 1 / -1;
}

.oraculo-card h2 {
    margin: 0 0 15px 0;
    font-size: 16px;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}

.oraculo-debug-table {
    width: 100%;
    border-collapse: collapse;
}

.oraculo-debug-table th,
.oraculo-debug-table td {
    padding: 8px;
    text-align: left;
    border-bottom: 1px solid #eee;
    font-size: 13px;
}

.oraculo-debug-table th {
    font-weight: 600;
    color: #555;
    width: 40%;
}

.status-ok {
    color: #fff;
    background: #46b450;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
}

.status-error {
    color: #fff;
    background: #dc3232;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
}

.status-warning {
    color: #fff;
    background: #ffb900;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
}

.providers-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.provider-status-card {
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 15px;
}

.provider-status-card h4 {
    margin: 0 0 5px 0;
}

.provider-status-card .provider-id {
    color: #888;
    font-size: 12px;
    margin: 0 0 10px 0;
}

.provider-status-card ul {
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 12px;
}

.provider-status-card li {
    padding: 3px 0;
}

.provider-status-card .test-result {
    margin-top: 10px;
    font-size: 12px;
}

.oraculo-errors-log {
    max-height: 200px;
    overflow-y: auto;
    background: #1e1e1e;
    padding: 10px;
    border-radius: 4px;
}

.error-entry {
    padding: 5px 0;
    border-bottom: 1px solid #333;
    font-family: monospace;
    font-size: 11px;
}

.error-time {
    color: #888;
    margin-right: 10px;
}

.error-type {
    color: #ff6b6b;
    margin-right: 10px;
}

.error-message {
    color: #fff;
}

.oraculo-test-results {
    margin-top: 15px;
    padding: 15px;
    background: #f9f9f9;
    border-radius: 5px;
    display: none;
}

.oraculo-test-results.show {
    display: block;
}

.oraculo-test-results pre {
    background: #1e1e1e;
    color: #00ff00;
    padding: 15px;
    border-radius: 4px;
    overflow-x: auto;
    font-size: 12px;
}

.maintenance-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.button-danger {
    background: #dc3232 !important;
    border-color: #dc3232 !important;
    color: #fff !important;
}

.button-danger:hover {
    background: #c32121 !important;
}

.oraculo-empty {
    color: #888;
    font-style: italic;
    text-align: center;
    padding: 20px;
}

@media (max-width: 1200px) {
    .oraculo-debug-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Testar provedor
    $('.oraculo-test-provider').on('click', function() {
        var btn = $(this);
        var provider = btn.data('provider');
        var result = btn.siblings('.test-result');

        btn.prop('disabled', true).text('<?php esc_html_e('Testando...', 'oraculo_tainacan'); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'oraculo_test_provider',
                nonce: '<?php echo wp_create_nonce('oraculo_admin'); ?>',
                provider: provider
            },
            success: function(response) {
                btn.prop('disabled', false).text('<?php esc_html_e('Testar', 'oraculo_tainacan'); ?>');
                if (response.success) {
                    result.html('<span class="status-ok">' + response.data.message + '</span>');
                } else {
                    result.html('<span class="status-error">' + response.data.message + '</span>');
                }
            },
            error: function() {
                btn.prop('disabled', false).text('<?php esc_html_e('Testar', 'oraculo_tainacan'); ?>');
                result.html('<span class="status-error"><?php esc_html_e('Erro de conexão', 'oraculo_tainacan'); ?></span>');
            }
        });
    });

    // Teste de busca
    $('#oraculo-test-search').on('submit', function(e) {
        e.preventDefault();

        var query = $('#test-query').val().trim();
        if (!query) return;

        var results = $('#oraculo-test-results');
        results.html('<p><?php esc_html_e('Processando...', 'oraculo_tainacan'); ?></p>').addClass('show');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'oraculo_test_search',
                nonce: '<?php echo wp_create_nonce('oraculo_admin'); ?>',
                query: query,
                semantic_only: $('#test-semantic-only').is(':checked') ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    results.html('<pre>' + JSON.stringify(response.data, null, 2) + '</pre>');
                } else {
                    results.html('<p style="color: red;">' + response.data.message + '</p>');
                }
            },
            error: function() {
                results.html('<p style="color: red;"><?php esc_html_e('Erro de conexão', 'oraculo_tainacan'); ?></p>');
            }
        });
    });

    // Reparar tabelas
    $('#oraculo-repair-tables').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'oraculo_repair_tables',
                nonce: '<?php echo wp_create_nonce('oraculo_admin'); ?>'
            },
            success: function(response) {
                btn.prop('disabled', false);
                if (response.success) {
                    alert('<?php esc_html_e('Tabelas reparadas com sucesso!', 'oraculo_tainacan'); ?>');
                    location.reload();
                } else {
                    alert(response.data.message);
                }
            }
        });
    });

    // Limpar cache
    $('#oraculo-clear-cache').on('click', function() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'oraculo_clear_cache',
                nonce: '<?php echo wp_create_nonce('oraculo_admin'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert('<?php esc_html_e('Cache limpo!', 'oraculo_tainacan'); ?>');
                }
            }
        });
    });

    // Limpar erros
    $('#oraculo-clear-errors').on('click', function() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'oraculo_clear_errors',
                nonce: '<?php echo wp_create_nonce('oraculo_admin'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                }
            }
        });
    });

    // Otimizar tabelas
    $('#oraculo-optimize-tables').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true).text('<?php esc_html_e('Otimizando...', 'oraculo_tainacan'); ?>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'oraculo_optimize_db',
                nonce: '<?php echo wp_create_nonce('oraculo_admin'); ?>'
            },
            success: function(response) {
                btn.prop('disabled', false).text('<?php esc_html_e('Otimizar Tabelas', 'oraculo_tainacan'); ?>');
                if (response.success) {
                    alert('<?php esc_html_e('Tabelas otimizadas!', 'oraculo_tainacan'); ?>');
                }
            }
        });
    });

    // Resetar plugin
    $('#oraculo-reset-plugin').on('click', function() {
        if (!confirm('<?php esc_html_e('ATENÇÃO: Isso removerá TODOS os dados do plugin. Continuar?', 'oraculo_tainacan'); ?>')) {
            return;
        }
        if (!confirm('<?php esc_html_e('Esta ação NÃO pode ser desfeita. Tem certeza absoluta?', 'oraculo_tainacan'); ?>')) {
            return;
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'oraculo_reset_plugin',
                nonce: '<?php echo wp_create_nonce('oraculo_admin'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    alert('<?php esc_html_e('Plugin resetado com sucesso!', 'oraculo_tainacan'); ?>');
                    location.reload();
                }
            }
        });
    });
});
</script>
