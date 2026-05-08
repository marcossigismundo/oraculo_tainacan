<?php
/**
 * Gerenciador de Webhooks e Notificações
 *
 * Permite integração com serviços externos através de webhooks
 * e envio de notificações por email/Slack/Discord.
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\Features;

/**
 * Gerencia webhooks e notificações do plugin
 */
class WebhooksManager {

    /**
     * Eventos disponíveis
     */
    private const EVENTS = [
        'search.performed' => 'Busca realizada',
        'search.no_results' => 'Busca sem resultados',
        'indexing.started' => 'Indexação iniciada',
        'indexing.completed' => 'Indexação concluída',
        'indexing.failed' => 'Indexação falhou',
        'feedback.positive' => 'Feedback positivo',
        'feedback.negative' => 'Feedback negativo',
        'error.api' => 'Erro de API',
        'daily.report' => 'Relatório diário',
    ];

    /**
     * @var string Tabela de webhooks
     */
    private string $table_name;

    /**
     * Construtor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'oraculo_webhooks';
    }

    /**
     * Cria tabela de webhooks
     */
    public static function create_table(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}oraculo_webhooks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            url VARCHAR(500) NOT NULL,
            events TEXT NOT NULL,
            secret VARCHAR(100) DEFAULT NULL,
            active TINYINT(1) DEFAULT 1,
            headers TEXT DEFAULT NULL,
            retry_count INT DEFAULT 3,
            last_triggered DATETIME DEFAULT NULL,
            last_status INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Registra um novo webhook
     *
     * @param array $data
     * @return int|false
     */
    public function register(array $data) {
        global $wpdb;

        $events = is_array($data['events']) ? $data['events'] : [$data['events']];
        $headers = is_array($data['headers'] ?? null) ? wp_json_encode($data['headers']) : null;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no WP core API available.
        $inserted = $wpdb->insert(
            $this->table_name,
            [
                'name' => $data['name'],
                'url' => $data['url'],
                'events' => wp_json_encode($events),
                'secret' => $data['secret'] ?? null,
                'active' => $data['active'] ?? 1,
                'headers' => $headers,
                'retry_count' => $data['retry_count'] ?? 3,
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s', '%d']
        );
        if ( $inserted ) {
            \Oraculo_Tainacan\oraculo_tainacan_flush_cache();
        }
        return $inserted ? $wpdb->insert_id : false;
    }

    /**
     * Atualiza webhook
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool {
        global $wpdb;

        $update_data = [];
        $formats = [];

        if (isset($data['name'])) {
            $update_data['name'] = $data['name'];
            $formats[] = '%s';
        }

        if (isset($data['url'])) {
            $update_data['url'] = $data['url'];
            $formats[] = '%s';
        }

        if (isset($data['events'])) {
            $update_data['events'] = wp_json_encode(is_array($data['events']) ? $data['events'] : [$data['events']]);
            $formats[] = '%s';
        }

        if (isset($data['active'])) {
            $update_data['active'] = (int) $data['active'];
            $formats[] = '%d';
        }

        if (empty($update_data)) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_webhooks); UPDATE write operation; caching N/A.
        $result = $wpdb->update(
            $this->table_name,
            $update_data,
            ['id' => $id],
            $formats,
            ['%d']
        ) !== false;
        if ( $result ) {
            \Oraculo_Tainacan\oraculo_tainacan_flush_cache();
        }
        return $result;
    }

    /**
     * Remove webhook
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_webhooks); DELETE write operation; caching N/A.
        $result = $wpdb->delete($this->table_name, ['id' => $id], ['%d']) !== false;
        if ( $result ) {
            \Oraculo_Tainacan\oraculo_tainacan_flush_cache();
        }
        return $result;
    }

    /**
     * Obtém webhooks
     *
     * @param array $filters
     * @return array
     */
    public function get_webhooks(array $filters = []): array {
        global $wpdb;

        $where = ['1=1'];
        $values = [];

        if (isset($filters['active'])) {
            $where[] = "active = %d";
            $values[] = (int) $filters['active'];
        }

        if (isset($filters['event'])) {
            $where[] = "events LIKE %s";
            $values[] = '%' . $wpdb->esc_like($filters['event']) . '%';
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $this->table_name is plugin-owned; WHERE clauses use only hardcoded comparisons.
        $sql = "SELECT * FROM {$this->table_name} WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC";

        if (!empty($values)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from plugin-owned table and hardcoded WHERE; LIKE values passed via esc_like().
            $sql = $wpdb->prepare($sql, $values);
        }

        // phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from $this->table_name (plugin-owned, $wpdb->prefix + literal) and hardcoded WHERE; table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; webhook list changes rarely; caching not applied to keep data fresh.
        $webhooks = $wpdb->get_results($sql, ARRAY_A);
        // phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter

        foreach ($webhooks as &$webhook) {
            $webhook['events'] = json_decode($webhook['events'], true);
            $webhook['headers'] = json_decode($webhook['headers'], true);
        }

        return $webhooks;
    }

    /**
     * Dispara webhooks para um evento
     *
     * @param string $event
     * @param array $payload
     * @return array Resultados
     */
    public function trigger(string $event, array $payload = []): array {
        $webhooks = $this->get_webhooks(['active' => 1, 'event' => $event]);

        if (empty($webhooks)) {
            return [];
        }

        $results = [];

        foreach ($webhooks as $webhook) {
            $events = $webhook['events'];

            if (!in_array($event, $events) && !in_array('*', $events)) {
                continue;
            }

            $result = $this->send_webhook($webhook, $event, $payload);
            $results[$webhook['id']] = $result;

            // Atualizar status
            $this->update_status($webhook['id'], $result['status']);
        }

        return $results;
    }

    /**
     * Envia webhook
     *
     * @param array $webhook
     * @param string $event
     * @param array $payload
     * @return array
     */
    private function send_webhook(array $webhook, string $event, array $payload): array {
        $body = [
            'event' => $event,
            'timestamp' => current_time('c'),
            'data' => $payload,
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Oraculo-Tainacan/' . ORACULO_TAINACAN_VERSION,
            'X-Oraculo-Event' => $event,
        ];

        // Adicionar headers customizados
        if (!empty($webhook['headers'])) {
            $headers = array_merge($headers, $webhook['headers']);
        }

        // Adicionar assinatura se secret definido
        if (!empty($webhook['secret'])) {
            $signature = hash_hmac('sha256', wp_json_encode($body), $webhook['secret']);
            $headers['X-Oraculo-Signature'] = $signature;
        }

        $attempt = 0;
        $max_attempts = $webhook['retry_count'] ?? 3;

        while ($attempt < $max_attempts) {
            $attempt++;

            $response = wp_remote_post($webhook['url'], [
                'body' => wp_json_encode($body),
                'headers' => $headers,
                'timeout' => 30,
            ]);

            if (!is_wp_error($response)) {
                $status = wp_remote_retrieve_response_code($response);

                if ($status >= 200 && $status < 300) {
                    return [
                        'success' => true,
                        'status' => $status,
                        'attempts' => $attempt,
                    ];
                }
            }

            // Aguardar antes de retry
            if ($attempt < $max_attempts) {
                usleep(500000 * $attempt); // 0.5s, 1s, 1.5s...
            }
        }

        return [
            'success' => false,
            'status' => is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response),
            'error' => is_wp_error($response) ? $response->get_error_message() : 'HTTP error',
            'attempts' => $attempt,
        ];
    }

    /**
     * Atualiza status do último trigger
     *
     * @param int $id
     * @param int $status
     */
    private function update_status(int $id, int $status): void {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_webhooks); status UPDATE write operation; caching N/A.
        $wpdb->update(
            $this->table_name,
            [
                'last_triggered' => current_time('mysql'),
                'last_status' => $status,
            ],
            ['id' => $id],
            ['%s', '%d'],
            ['%d']
        );
        \Oraculo_Tainacan\oraculo_tainacan_flush_cache();
    }

    /**
     * Envia notificação por email
     *
     * @param string $subject
     * @param string $message
     * @param array $options
     * @return bool
     */
    public function send_email_notification(string $subject, string $message, array $options = []): bool {
        $to = $options['to'] ?? get_option('admin_email');
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        // Template HTML
        $html = $this->get_email_template($subject, $message);

        return wp_mail($to, '[Oráculo Tainacan] ' . $subject, $html, $headers);
    }

    /**
     * Envia notificação para Slack
     *
     * @param string $message
     * @param string $webhook_url
     * @param array $options
     * @return bool
     */
    public function send_slack_notification(string $message, string $webhook_url, array $options = []): bool {
        $payload = [
            'text' => $message,
        ];

        if (!empty($options['channel'])) {
            $payload['channel'] = $options['channel'];
        }

        if (!empty($options['username'])) {
            $payload['username'] = $options['username'];
        }

        if (!empty($options['icon_emoji'])) {
            $payload['icon_emoji'] = $options['icon_emoji'];
        }

        // Adicionar attachments se fornecidos
        if (!empty($options['attachments'])) {
            $payload['attachments'] = $options['attachments'];
        }

        $response = wp_remote_post($webhook_url, [
            'body' => wp_json_encode($payload),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10,
        ]);

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Envia notificação para Discord
     *
     * @param string $message
     * @param string $webhook_url
     * @param array $options
     * @return bool
     */
    public function send_discord_notification(string $message, string $webhook_url, array $options = []): bool {
        $payload = [
            'content' => $message,
        ];

        if (!empty($options['username'])) {
            $payload['username'] = $options['username'];
        }

        if (!empty($options['avatar_url'])) {
            $payload['avatar_url'] = $options['avatar_url'];
        }

        // Adicionar embeds se fornecidos
        if (!empty($options['embeds'])) {
            $payload['embeds'] = $options['embeds'];
        }

        $response = wp_remote_post($webhook_url, [
            'body' => wp_json_encode($payload),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10,
        ]);

        return !is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response), [200, 204]);
    }

    /**
     * Envia relatório diário
     *
     * @return bool
     */
    public function send_daily_report(): bool {
        $analytics = new \Oraculo_Tainacan\Analytics\AnalyticsManager();
        $report = $analytics->get_daily_report();

        $message = $this->format_daily_report($report);

        // Disparar webhook
        $this->trigger('daily.report', $report);

        // Enviar email se configurado
        $email_enabled = get_option('oraculo_daily_report_email', false);
        if ($email_enabled) {
            return $this->send_email_notification(
                /* translators: %s: date of the daily report */
                sprintf(__('Relatório Diário - %s', 'oraculo_tainacan'), $report['date']),
                $message
            );
        }

        return true;
    }

    /**
     * Obtém lista de eventos disponíveis
     *
     * @return array
     */
    public function get_available_events(): array {
        return self::EVENTS;
    }

    /**
     * Testa webhook
     *
     * @param int $id
     * @return array
     */
    public function test_webhook(int $id): array {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->table_name is plugin-owned table built from $wpdb->prefix + literal; table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- test webhook lookup.
        $webhook = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        if (!$webhook) {
            return ['success' => false, 'error' => 'Webhook não encontrado'];
        }

        $webhook['events'] = json_decode($webhook['events'], true);
        $webhook['headers'] = json_decode($webhook['headers'], true);

        return $this->send_webhook($webhook, 'test', [
            'message' => 'Teste de webhook do Oráculo Tainacan',
            'timestamp' => current_time('c'),
        ]);
    }

    /**
     * Formata relatório diário
     *
     * @param array $report
     * @return string
     */
    private function format_daily_report(array $report): string {
        $html = '<h2>Relatório de ' . $report['date'] . '</h2>';

        $html .= '<h3>Resumo do Dia</h3>';
        $html .= '<ul>';
        $html .= '<li><strong>Total de Buscas:</strong> ' . number_format($report['today']['total_searches']) . '</li>';
        $html .= '<li><strong>Taxa de Sucesso:</strong> ' . $report['today']['success_rate'] . '%</li>';
        $html .= '<li><strong>Usuários Únicos:</strong> ' . number_format($report['today']['unique_users']) . '</li>';
        $html .= '<li><strong>Tokens Usados:</strong> ' . number_format($report['today']['total_tokens']) . '</li>';
        $html .= '</ul>';

        if ($report['changes']['searches'] != 0) {
            $direction = $report['changes']['searches'] > 0 ? 'aumento' : 'queda';
            $html .= '<p><em>' . abs($report['changes']['searches']) . '% de ' . $direction . ' em relação a ontem</em></p>';
        }

        if (!empty($report['top_searches_today'])) {
            $html .= '<h3>Buscas Mais Frequentes</h3>';
            $html .= '<ol>';
            foreach ($report['top_searches_today'] as $search) {
                $html .= '<li>' . esc_html($search['query_text']) . ' (' . $search['count'] . 'x)</li>';
            }
            $html .= '</ol>';
        }

        if (!empty($report['failed_searches_today'])) {
            $html .= '<h3>Buscas Sem Resultados</h3>';
            $html .= '<ul>';
            foreach ($report['failed_searches_today'] as $search) {
                $html .= '<li>' . esc_html($search['query_text']) . ' (' . $search['count'] . 'x)</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    /**
     * Obtém template de email
     *
     * @param string $subject
     * @param string $content
     * @return string
     */
    private function get_email_template(string $subject, string $content): string {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #fff; padding: 30px; border: 1px solid #ddd; border-top: none; border-radius: 0 0 8px 8px; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #888; }
        h1 { margin: 0; font-size: 24px; }
        h2 { color: #667eea; }
        h3 { color: #555; }
        ul, ol { padding-left: 20px; }
        li { margin-bottom: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Oráculo Tainacan</h1>
        </div>
        <div class="content">
            ' . $content . '
        </div>
        <div class="footer">
            Este email foi enviado automaticamente pelo plugin Oráculo Tainacan.<br>
            <a href="' . admin_url('admin.php?page=oraculo-settings') . '">Gerenciar configurações</a>
        </div>
    </div>
</body>
</html>';
    }
}
