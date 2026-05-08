<?php
/**
 * Gerenciador de Analytics
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\Analytics;

/**
 * Gerencia métricas e analytics do plugin
 */
class AnalyticsManager {

    /**
     * Suffixes das tabelas (sem prefixo do WordPress).
     * Constantes de classe: garantem que os nomes das tabelas
     * nunca podem vir de input do usuário em tempo de execução.
     */
    private const TABLE_LOGS     = 'oraculo_search_logs';
    private const TABLE_MESSAGES = 'oraculo_messages';

    /**
     * Nome da tabela de logs
     * @var string
     */
    private string $logs_table;

    /**
     * Nome da tabela de mensagens
     * @var string
     */
    private string $messages_table;

    /**
     * Construtor
     */
    public function __construct() {
        global $wpdb;
        $this->logs_table = $wpdb->prefix . self::TABLE_LOGS;
        $this->messages_table = $wpdb->prefix . self::TABLE_MESSAGES;
    }

    /**
     * Registra feedback
     *
     * @param string $search_id
     * @param string $feedback
     * @param int $message_id
     * @return bool
     */
    public function record_feedback(string $search_id, string $feedback, int $message_id = 0): bool {
        global $wpdb;

        $valid_feedback = ['positive', 'negative'];
        if (!in_array($feedback, $valid_feedback)) {
            return false;
        }

        // Atualizar log de busca
        if (!empty($search_id)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_search_logs); UPDATE write operation; caching N/A.
            $wpdb->update(
                $this->logs_table,
                ['feedback' => $feedback],
                ['session_id' => $search_id],
                ['%s'],
                ['%s']
            );
            \Oraculo_Tainacan\oraculo_tainacan_flush_cache();
        }

        // Atualizar mensagem de chat
        if ($message_id > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table (oraculo_messages); UPDATE write operation; caching N/A.
            $wpdb->update(
                $this->messages_table,
                ['feedback' => $feedback],
                ['id' => $message_id],
                ['%s'],
                ['%d']
            );
            \Oraculo_Tainacan\oraculo_tainacan_flush_cache();
        }

        return true;
    }

    /**
     * Obtém estatísticas gerais
     *
     * @param string $period 'today', 'week', 'month', 'year', 'all'
     * @return array
     */
    public function get_stats(string $period = 'month'): array {
        global $wpdb;

        [$where_frag, $where_args] = $this->get_date_where($period);

        // Helper local: executa SELECT com $where_frag + (opcionalmente) condição extra hardcoded.
        $run_count = function (string $select, string $extra_and = '') use ($wpdb, $where_frag, $where_args) {
            $where = $extra_and === '' ? $where_frag : "{$extra_and} AND {$where_frag}";
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->logs_table is $wpdb->prefix . self::TABLE_LOGS (class constant); $where is built from get_date_where() (whitelist of class-defined fragments) plus a literal $extra_and from the caller (hardcoded); any %d in $where is bound below via prepare(); analytics aggregate, cached by caller.
            $sql = "SELECT {$select} FROM {$this->logs_table} WHERE {$where}";
            if (empty($where_args)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- no %d placeholders in this branch (period maps to fully-literal fragment from get_date_where()); table from class constant.
                return $wpdb->get_var($sql);
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql interpolation is class-constant table + class-defined where fragment; %d args from get_date_where() bound below.
            return $wpdb->get_var($wpdb->prepare($sql, ...$where_args));
        };

        // Total de buscas
        $total_searches = (int) $run_count('COUNT(*)');

        // Buscas únicas (por query_hash)
        $unique_searches = (int) $run_count('COUNT(DISTINCT query_hash)');

        // Taxa de sucesso (buscas com resultados)
        $successful_searches = (int) $run_count('COUNT(*)', 'results_count > 0');
        $success_rate = $total_searches > 0
            ? round(($successful_searches / $total_searches) * 100, 1)
            : 0;

        // Taxa de satisfação
        $positive_feedback = (int) $run_count('COUNT(*)', "feedback = 'positive'");
        $total_feedback = (int) $run_count('COUNT(*)', 'feedback IS NOT NULL');
        $satisfaction_rate = $total_feedback > 0
            ? round(($positive_feedback / $total_feedback) * 100, 1)
            : 0;

        // Tokens utilizados
        $total_tokens = (int) $run_count('SUM(tokens_used)');

        // Tempo médio de resposta
        $avg_response_time = (float) $run_count('AVG(response_time_ms)');

        // Média de resultados por busca
        $avg_results = (float) $run_count('AVG(results_count)');

        // Usuários únicos
        $unique_users = (int) $run_count('COUNT(DISTINCT COALESCE(NULLIF(user_id, 0), ip_address))');

        return [
            'total_searches' => $total_searches,
            'unique_searches' => $unique_searches,
            'success_rate' => $success_rate,
            'satisfaction_rate' => $satisfaction_rate,
            'total_tokens' => $total_tokens,
            'avg_response_time_ms' => round($avg_response_time),
            'avg_results_per_search' => round($avg_results, 1),
            'unique_users' => $unique_users,
            'period' => $period,
        ];
    }

    /**
     * Obtém buscas por período (para gráficos)
     *
     * @param string $period
     * @param string $granularity 'day', 'hour', 'week', 'month'
     * @return array
     */
    public function get_searches_timeline(string $period = 'month', string $granularity = 'day'): array {
        global $wpdb;

        [$where_frag, $where_args] = $this->get_date_where($period);

        switch ($granularity) {
            case 'hour':
                $date_format = '%Y-%m-%d %H:00';
                break;
            case 'week':
                $date_format = '%Y-%u';
                break;
            case 'month':
                $date_format = '%Y-%m';
                break;
            default:
                $date_format = '%Y-%m-%d';
        }

        $sql = "SELECT DATE_FORMAT(created_at, '{$date_format}') as date_bucket,
                    COUNT(*) as searches,
                    SUM(CASE WHEN results_count > 0 THEN 1 ELSE 0 END) as successful,
                    AVG(response_time_ms) as avg_time
             FROM {$this->logs_table}
             WHERE {$where_frag}
             GROUP BY date_bucket
             ORDER BY date_bucket ASC";

        if (empty($where_args)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $date_format is from hardcoded switch; $this->logs_table is $wpdb->prefix . self::TABLE_LOGS (class constant); $where_frag is from get_date_where() literal-only branch (no %d placeholders here); table/format identifiers cannot be parameterized.
            $results = $wpdb->get_results($sql, ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $date_format is from hardcoded switch; table from class constant; $where_frag is from get_date_where() (class-defined whitelist) and any %d args bound via prepare below.
            $results = $wpdb->get_results($wpdb->prepare($sql, ...$where_args), ARRAY_A);
        }

        return array_map(function($row) {
            return [
                'date' => $row['date_bucket'],
                'searches' => (int) $row['searches'],
                'successful' => (int) $row['successful'],
                'avg_time' => round((float) $row['avg_time']),
            ];
        }, $results);
    }

    /**
     * Obtém termos mais buscados
     *
     * @param string $period
     * @param int $limit
     * @return array
     */
    public function get_top_searches(string $period = 'month', int $limit = 10): array {
        global $wpdb;

        [$where_frag, $where_args] = $this->get_date_where($period);

        $sql = "SELECT query_text, COUNT(*) as count,
                    AVG(results_count) as avg_results,
                    SUM(CASE WHEN feedback = 'positive' THEN 1 ELSE 0 END) as positive,
                    SUM(CASE WHEN feedback = 'negative' THEN 1 ELSE 0 END) as negative
             FROM {$this->logs_table}
             WHERE {$where_frag}
             GROUP BY query_hash
             ORDER BY count DESC
             LIMIT %d";

        $args = array_merge($where_args, [$limit]);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->logs_table is $wpdb->prefix . self::TABLE_LOGS (class constant; cannot receive user input); $where_frag is from get_date_where() (class-defined whitelist); all %d args bound via prepare below.
        return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    }

    /**
     * Obtém buscas sem resultados
     *
     * @param string $period
     * @param int $limit
     * @return array
     */
    public function get_failed_searches(string $period = 'month', int $limit = 10): array {
        global $wpdb;

        [$where_frag, $where_args] = $this->get_date_where($period);

        $sql = "SELECT query_text, COUNT(*) as count
             FROM {$this->logs_table}
             WHERE results_count = 0 AND {$where_frag}
             GROUP BY query_hash
             ORDER BY count DESC
             LIMIT %d";

        $args = array_merge($where_args, [$limit]);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->logs_table is $wpdb->prefix . self::TABLE_LOGS (class constant; cannot receive user input); $where_frag from get_date_where() (class-defined whitelist); all %d args bound via prepare below.
        return $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
    }

    /**
     * Obtém estatísticas por coleção
     *
     * @param string $period
     * @return array
     */
    public function get_stats_by_collection(string $period = 'month'): array {
        global $wpdb;

        [$where_frag, $where_args] = $this->get_date_where($period);

        $sql = "SELECT collection_ids, COUNT(*) as searches
             FROM {$this->logs_table}
             WHERE {$where_frag} AND collection_ids IS NOT NULL AND collection_ids != '[]'
             GROUP BY collection_ids
             ORDER BY searches DESC";

        // Buscar logs com collection_ids
        if (empty($where_args)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->logs_table is $wpdb->prefix . self::TABLE_LOGS (class constant; cannot receive user input); $where_frag is from get_date_where() literal-only branch (no %d placeholders here).
            $results = $wpdb->get_results($sql, ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table from class constant; $where_frag from get_date_where() (class-defined whitelist); %d args bound via prepare below.
            $results = $wpdb->get_results($wpdb->prepare($sql, ...$where_args), ARRAY_A);
        }

        // Agregar por coleção individual
        $by_collection = [];
        foreach ($results as $row) {
            $ids = json_decode($row['collection_ids'], true);
            if (!is_array($ids)) continue;

            foreach ($ids as $id) {
                if (!isset($by_collection[$id])) {
                    $by_collection[$id] = 0;
                }
                $by_collection[$id] += (int) $row['searches'];
            }
        }

        // Obter nomes das coleções
        $collections = \Oraculo_Tainacan\get_tainacan_collections();
        $collection_names = array_column($collections, 'name', 'id');

        $formatted = [];
        foreach ($by_collection as $id => $count) {
            $formatted[] = [
                'collection_id' => $id,
                'collection_name' => $collection_names[$id] ?? "Coleção #{$id}",
                'searches' => $count,
            ];
        }

        usort($formatted, fn($a, $b) => $b['searches'] <=> $a['searches']);

        return $formatted;
    }

    /**
     * Obtém estatísticas de modelos usados
     *
     * @param string $period
     * @return array
     */
    public function get_model_usage(string $period = 'month'): array {
        global $wpdb;

        [$where_frag, $where_args] = $this->get_date_where($period);

        $sql = "SELECT model_used, COUNT(*) as count, SUM(tokens_used) as total_tokens
             FROM {$this->logs_table}
             WHERE {$where_frag} AND model_used != ''
             GROUP BY model_used
             ORDER BY count DESC";

        if (empty($where_args)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $this->logs_table is $wpdb->prefix . self::TABLE_LOGS (class constant; cannot receive user input); $where_frag from get_date_where() literal-only branch (no %d placeholders here).
            return $wpdb->get_results($sql, ARRAY_A);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table from class constant; $where_frag from get_date_where() (class-defined whitelist); %d args bound via prepare below.
        return $wpdb->get_results($wpdb->prepare($sql, ...$where_args), ARRAY_A);
    }

    /**
     * Obtém custo estimado
     *
     * @param string $period
     * @return array
     */
    public function get_cost_estimate(string $period = 'month'): array {
        $model_usage = $this->get_model_usage($period);

        // Preços aproximados por 1K tokens
        $prices = [
            'gpt-4o' => ['input' => 0.0025, 'output' => 0.01],
            'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006],
            'gpt-4-turbo' => ['input' => 0.01, 'output' => 0.03],
            'gpt-4' => ['input' => 0.03, 'output' => 0.06],
            'gpt-3.5-turbo' => ['input' => 0.0005, 'output' => 0.0015],
            'gemini-1.5-pro' => ['input' => 0.00125, 'output' => 0.005],
            'gemini-1.5-flash' => ['input' => 0.000075, 'output' => 0.0003],
            'deepseek-chat' => ['input' => 0.00014, 'output' => 0.00028],
            'claude-3-5-sonnet-latest' => ['input' => 0.003, 'output' => 0.015],
        ];

        $total_cost = 0;
        $by_model = [];

        foreach ($model_usage as $usage) {
            $model = $usage['model_used'];
            $tokens = (int) $usage['total_tokens'];

            // Estimar 60% input, 40% output
            $input_tokens = $tokens * 0.6;
            $output_tokens = $tokens * 0.4;

            $price = $prices[$model] ?? ['input' => 0.001, 'output' => 0.002];

            $cost = (($input_tokens / 1000) * $price['input']) +
                    (($output_tokens / 1000) * $price['output']);

            $total_cost += $cost;

            $by_model[] = [
                'model' => $model,
                'tokens' => $tokens,
                'estimated_cost' => round($cost, 4),
            ];
        }

        return [
            'total_cost_usd' => round($total_cost, 4),
            'by_model' => $by_model,
            'period' => $period,
        ];
    }

    /**
     * Constrói o fragmento WHERE de data como SQL preparável.
     *
     * Retorna [string $where_fragment, array $prepare_args]:
     *  - $where_fragment é uma string definida pela classe (whitelist via switch).
     *    Se contiver %d, $prepare_args traz o valor a ser bound.
     *  - $prepare_args é um array de inteiros para passar a $wpdb->prepare().
     *    Vazio quando o fragmento não tem placeholders.
     *
     * Garantia estrutural: nenhum dos valores em $prepare_args ou
     * fragmentos retornados pode vir de input do usuário — todos são
     * literais hardcoded selecionados pelo whitelist do switch.
     *
     * @param string $period 'today'|'yesterday'|'week'|'month'|'year'|'all'
     * @return array{0:string,1:array<int,int>}
     */
    private function get_date_where(string $period): array {
        switch ($period) {
            case 'today':
                return ['DATE(created_at) = CURDATE()', []];
            case 'yesterday':
                return ['DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL %d DAY)', [1]];
            case 'week':
                return ['created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', [7]];
            case 'month':
                return ['created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', [30]];
            case 'year':
                return ['created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)', []];
            case 'all':
            default:
                return ['1=1', []];
        }
    }

    /**
     * Exporta dados de analytics
     *
     * @param string $period
     * @param string $format 'json' ou 'csv'
     * @return string
     */
    public function export(string $period = 'month', string $format = 'json'): string {
        $data = [
            'stats' => $this->get_stats($period),
            'timeline' => $this->get_searches_timeline($period),
            'top_searches' => $this->get_top_searches($period, 50),
            'failed_searches' => $this->get_failed_searches($period, 50),
            'by_collection' => $this->get_stats_by_collection($period),
            'model_usage' => $this->get_model_usage($period),
            'cost_estimate' => $this->get_cost_estimate($period),
            'exported_at' => current_time('mysql'),
        ];

        if ($format === 'csv') {
            // Exportar apenas estatísticas principais como CSV
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp memory stream; WP_Filesystem has no equivalent for in-memory streaming writes.
            $output = fopen('php://temp', 'r+');

            fputcsv($output, ['Métrica', 'Valor']);
            foreach ($data['stats'] as $key => $value) {
                fputcsv($output, [$key, $value]);
            }

            fputcsv($output, []);
            fputcsv($output, ['Top Buscas']);
            fputcsv($output, ['Query', 'Count', 'Avg Results']);
            foreach ($data['top_searches'] as $row) {
                fputcsv($output, [$row['query_text'], $row['count'], $row['avg_results']]);
            }

            rewind($output);
            $csv = stream_get_contents($output);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://temp memory stream; WP_Filesystem has no equivalent for in-memory streaming writes.
            fclose($output);

            return $csv;
        }

        return wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Limpa logs antigos
     *
     * @param int $days_old
     * @return int
     */
    public function cleanup_old_logs(int $days_old = 90): int {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $this->logs_table is $wpdb->prefix . self::TABLE_LOGS (class constant; cannot receive user input); table identifier cannot be parameterized.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DELETE write operation; caching N/A for writes.
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->logs_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        ));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        \Oraculo_Tainacan\oraculo_tainacan_flush_cache();
        return $result;
    }

    /**
     * Obtém relatório diário para email
     *
     * @return array
     */
    public function get_daily_report(): array {
        $today_stats = $this->get_stats('today');
        $yesterday_stats = $this->get_stats('yesterday');

        // Calcular variações
        $search_change = $yesterday_stats['total_searches'] > 0
            ? round((($today_stats['total_searches'] - $yesterday_stats['total_searches']) / $yesterday_stats['total_searches']) * 100, 1)
            : 0;

        return [
            'date' => current_time('Y-m-d'),
            'today' => $today_stats,
            'yesterday' => $yesterday_stats,
            'changes' => [
                'searches' => $search_change,
            ],
            'top_searches_today' => $this->get_top_searches('today', 5),
            'failed_searches_today' => $this->get_failed_searches('today', 5),
        ];
    }
}
