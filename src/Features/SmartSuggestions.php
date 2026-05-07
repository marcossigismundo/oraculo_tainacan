<?php
/**
 * Sistema de Sugestões Inteligentes
 *
 * Gera sugestões de perguntas baseadas no conteúdo do acervo
 * e no histórico de buscas dos usuários.
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\Features;

use Oraculo_Tainacan\Vector\VectorStore;
use Oraculo_Tainacan\Analytics\AnalyticsManager;

/**
 * Gerencia sugestões inteligentes baseadas em dados
 */
class SmartSuggestions {

    /**
     * Cache key prefix
     * @var string
     */
    private const CACHE_PREFIX = 'oraculo_suggestions_';

    /**
     * Tempo de cache em segundos
     * @var int
     */
    private const CACHE_TTL = 3600;

    /**
     * @var VectorStore
     */
    private VectorStore $vector_store;

    /**
     * @var AnalyticsManager
     */
    private AnalyticsManager $analytics;

    /**
     * Construtor
     */
    public function __construct() {
        $this->vector_store = new VectorStore();
        $this->analytics = new AnalyticsManager();
    }

    /**
     * Obtém sugestões de perguntas personalizadas
     *
     * @param int|null $collection_id Coleção específica
     * @param int $limit Número de sugestões
     * @return array
     */
    public function get_suggestions(?int $collection_id = null, int $limit = 5): array {
        $cache_key = self::CACHE_PREFIX . ($collection_id ?: 'all') . '_' . $limit;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $suggestions = [];

        // 1. Sugestões baseadas em buscas populares bem-sucedidas
        $popular = $this->get_popular_successful_searches($limit);
        foreach ($popular as $search) {
            $suggestions[] = [
                'text' => $search['query_text'],
                'type' => 'popular',
                'score' => $search['count'] * 10,
            ];
        }

        // 2. Sugestões baseadas no conteúdo mais relevante
        $content_based = $this->get_content_based_suggestions($collection_id, $limit);
        foreach ($content_based as $suggestion) {
            $suggestions[] = [
                'text' => $suggestion,
                'type' => 'content',
                'score' => 5,
            ];
        }

        // 3. Sugestões sazonais/contextuais
        $seasonal = $this->get_seasonal_suggestions();
        foreach ($seasonal as $suggestion) {
            $suggestions[] = [
                'text' => $suggestion,
                'type' => 'seasonal',
                'score' => 3,
            ];
        }

        // Ordenar por score e remover duplicatas
        usort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);

        $unique = [];
        $seen = [];
        foreach ($suggestions as $s) {
            $normalized = strtolower(trim($s['text']));
            if (!isset($seen[$normalized])) {
                $seen[$normalized] = true;
                $unique[] = $s['text'];
            }
        }

        $result = array_slice($unique, 0, $limit);

        set_transient($cache_key, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Obtém buscas populares que tiveram resultados
     *
     * @param int $limit
     * @return array
     */
    private function get_popular_successful_searches(int $limit): array {
        global $wpdb;

        $table = $wpdb->prefix . 'oraculo_search_logs';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is plugin-owned ($wpdb->prefix.'oraculo_search_logs').
        return $wpdb->get_results($wpdb->prepare(
            "SELECT query_text, COUNT(*) as count
             FROM {$table}
             WHERE results_count > 0
               AND feedback != 'negative'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY query_hash
             HAVING count >= 2
             ORDER BY count DESC
             LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }

    /**
     * Gera sugestões baseadas no conteúdo indexado
     *
     * @param int|null $collection_id
     * @param int $limit
     * @return array
     */
    private function get_content_based_suggestions(?int $collection_id, int $limit): array {
        global $wpdb;

        $table = $wpdb->prefix . 'oraculo_vectors';

        $where = $collection_id ? $wpdb->prepare("WHERE collection_id = %d", $collection_id) : "";

        // Obter títulos mais recentes/populares
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is plugin-owned ($wpdb->prefix.'oraculo_vectors'); $where is built with $wpdb->prepare() or empty string.
        $items = $wpdb->get_col(
            "SELECT item_title FROM {$table}
             {$where}
             ORDER BY updated_at DESC
             LIMIT 50"
        );

        if (empty($items)) {
            return [];
        }

        $suggestions = [];

        // Gerar perguntas baseadas nos títulos
        $templates = [
            'O que é %s?',
            'Quais informações existem sobre %s?',
            'Como é descrito %s?',
            'Qual a história de %s?',
        ];

        foreach ($items as $item) {
            if (strlen($item) < 5 || strlen($item) > 60) continue;

            $template = $templates[array_rand($templates)];
            $suggestions[] = sprintf($template, $item);

            if (count($suggestions) >= $limit * 2) break;
        }

        shuffle($suggestions);
        return array_slice($suggestions, 0, $limit);
    }

    /**
     * Gera sugestões sazonais/contextuais
     *
     * @return array
     */
    private function get_seasonal_suggestions(): array {
        $month = (int) date('n');
        $suggestions = [];

        // Datas comemorativas brasileiras
        switch ($month) {
            case 1:
                $suggestions[] = 'Documentos sobre o período colonial';
                break;
            case 2:
                $suggestions[] = 'Fotografias de carnaval histórico';
                break;
            case 3:
                $suggestions[] = 'Registros sobre a mulher na história';
                break;
            case 4:
                $suggestions[] = 'Documentos sobre povos indígenas';
                break;
            case 5:
                $suggestions[] = 'Materiais sobre trabalho e trabalhadores';
                break;
            case 6:
                $suggestions[] = 'Registros de festas juninas tradicionais';
                break;
            case 7:
                $suggestions[] = 'Documentos sobre a independência';
                break;
            case 8:
                $suggestions[] = 'Materiais sobre folclore brasileiro';
                break;
            case 9:
                $suggestions[] = 'Registros históricos da independência';
                break;
            case 10:
                $suggestions[] = 'Documentos sobre a criança na história';
                break;
            case 11:
                $suggestions[] = 'Materiais sobre consciência negra';
                break;
            case 12:
                $suggestions[] = 'Registros de celebrações históricas';
                break;
        }

        return $suggestions;
    }

    /**
     * Obtém sugestões de autocomplete
     *
     * @param string $query Texto parcial
     * @param int $limit
     * @return array
     */
    public function get_autocomplete(string $query, int $limit = 5): array {
        global $wpdb;

        $query = trim($query);
        if (strlen($query) < 2) {
            return [];
        }

        $table = $wpdb->prefix . 'oraculo_search_logs';

        // Buscar queries que começam com o texto
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is plugin-owned ($wpdb->prefix.'oraculo_search_logs').
        $suggestions = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT query_text
             FROM {$table}
             WHERE query_text LIKE %s
               AND results_count > 0
             ORDER BY (
                SELECT COUNT(*) FROM {$table} t2
                WHERE t2.query_hash = {$table}.query_hash
             ) DESC
             LIMIT %d",
            $query . '%',
            $limit
        ));

        return $suggestions ?: [];
    }

    /**
     * Obtém perguntas relacionadas
     *
     * @param string $query Query atual
     * @param int $limit
     * @return array
     */
    public function get_related_questions(string $query, int $limit = 3): array {
        global $wpdb;

        $table = $wpdb->prefix . 'oraculo_search_logs';

        // Extrair palavras-chave
        $words = preg_split('/\s+/', strtolower($query));
        $words = array_filter($words, fn($w) => strlen($w) > 3);

        if (empty($words)) {
            return [];
        }

        // Buscar queries que compartilham palavras
        $like_conditions = [];
        $values = [];
        foreach ($words as $word) {
            $like_conditions[] = "LOWER(query_text) LIKE %s";
            $values[] = '%' . $wpdb->esc_like($word) . '%';
        }

        $values[] = strtolower($query);
        $values[] = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table is plugin-owned; $like_conditions placeholders are %s literals spread via ...$values at runtime.
        $related = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT query_text
             FROM {$table}
             WHERE (" . implode(' OR ', $like_conditions) . ")
               AND LOWER(query_text) != %s
               AND results_count > 0
             ORDER BY RAND()
             LIMIT %d",
            ...$values
        ));

        return $related ?: [];
    }

    /**
     * Invalida cache de sugestões
     *
     * @param int|null $collection_id
     */
    public function invalidate_cache(?int $collection_id = null): void {
        global $wpdb;

        if ($collection_id) {
            delete_transient(self::CACHE_PREFIX . $collection_id . '_5');
            delete_transient(self::CACHE_PREFIX . $collection_id . '_10');
        } else {
            // Limpar todos — $wpdb->options is a WordPress core property; self::CACHE_PREFIX is a class constant.
            $cache_prefix = $wpdb->esc_like( '_transient_' . self::CACHE_PREFIX );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query built with esc_like and prepare; option_name pattern has no user input.
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $cache_prefix . '%'
            ) );
        }
    }
}
