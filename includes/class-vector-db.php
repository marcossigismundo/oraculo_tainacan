<?php
/**
 * Classe para gerenciamento do banco de dados vetorial
 *
 * @package Oraculo_Tainacan
 */

if (!defined('WPINC')) {
    die;
}

class Oraculo_Tainacan_Vector_DB {
    private string $table_name;
    private string $history_table_name;
    private const DB_VERSION = '2.0';

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'oraculo_vectors';
        $this->history_table_name = $wpdb->prefix . 'oraculo_search_history';
    }

    public function create_table(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            item_id bigint(20) NOT NULL,
            collection_id bigint(20) NOT NULL,
            collection_name varchar(255) NOT NULL,
            vector longtext NOT NULL,
            content longtext NOT NULL,
            permalink varchar(255) NOT NULL,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY item_collection (item_id, collection_id),
            KEY collection_id (collection_id),
            KEY updated_at (updated_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        update_option('oraculo_db_version', self::DB_VERSION);
    }

    public function store_vector(array $data): int|false {
        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE item_id = %d AND collection_id = %d",
            $data['item_id'],
            $data['collection_id']
        ));

        $vector_data = array(
            'item_id' => $data['item_id'],
            'collection_id' => $data['collection_id'],
            'collection_name' => $data['collection_name'],
            'vector' => $this->encode_vector($data['vector']),
            'content' => $data['content'],
            'permalink' => $data['permalink'],
            'last_updated' => current_time('mysql')
        );

        if ($existing) {
            $result = $wpdb->update(
                $this->table_name,
                $vector_data,
                array('id' => $existing),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
            return $result !== false ? (int)$existing : false;
        } else {
            $result = $wpdb->insert(
                $this->table_name,
                $vector_data,
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s')
            );
            return $result ? $wpdb->insert_id : false;
        }
    }

    public function item_exists($item_id, $collection_id): bool {
        global $wpdb;
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} 
             WHERE item_id = %d AND collection_id = %d",
            $item_id,
            $collection_id
        ));
        
        return $exists > 0;
    }

    public function delete_collection_vectors($collection_id): int {
        global $wpdb;
        
        return $wpdb->delete(
            $this->table_name,
            array('collection_id' => $collection_id),
            array('%d')
        );
    }

    public function get_collection_stats($collection_id): array {
        global $wpdb;
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_vectors,
                MIN(last_updated) as first_indexed,
                MAX(last_updated) as last_indexed,
                COUNT(DISTINCT item_id) as unique_items
             FROM {$this->table_name}
             WHERE collection_id = %d",
            $collection_id
        ), ARRAY_A);
        
        return $stats ?: array(
            'total_vectors' => 0,
            'first_indexed' => null,
            'last_indexed' => null,
            'unique_items' => 0
        );
    }

    /**
     * Método principal para armazenar vetores em lote
     */
    public function store_vectors_batch($vectors): array {
        return $this->store_vectors_batch_optimized($vectors);
    }

    /**
     * Armazena múltiplos vetores em lote (otimizado)
     */
    public function store_vectors_batch_optimized($vectors): array {
        global $wpdb;
        
        if (empty($vectors)) {
            return array('success' => 0, 'failed' => 0);
        }
        
        $success = 0;
        $failed = 0;
        
        $chunks = array_chunk($vectors, 50);
        
        foreach ($chunks as $chunk) {
            $values = array();
            $placeholders = array();
            
            foreach ($chunk as $vector_data) {
                $values[] = $vector_data['item_id'];
                $values[] = $vector_data['collection_id'];
                $values[] = $vector_data['collection_name'];
                $values[] = $this->encode_vector($vector_data['vector']);
                $values[] = $vector_data['content'];
                $values[] = $vector_data['permalink'];
                $values[] = current_time('mysql');
                
                $placeholders[] = "(%d, %d, %s, %s, %s, %s, %s)";
            }
            
            $query = "INSERT INTO {$this->table_name} 
                      (item_id, collection_id, collection_name, vector, content, permalink, last_updated) 
                      VALUES " . implode(', ', $placeholders) . "
                      ON DUPLICATE KEY UPDATE 
                      vector = VALUES(vector),
                      content = VALUES(content),
                      permalink = VALUES(permalink),
                      last_updated = VALUES(last_updated)";
            
            $result = $wpdb->query($wpdb->prepare($query, $values));
            
            if ($result !== false) {
                $success += count($chunk);
            } else {
                $failed += count($chunk);
                error_log('[Oráculo] Erro ao inserir vetores em lote: ' . $wpdb->last_error);
            }
        }
        
        return array(
            'success' => $success,
            'failed' => $failed
        );
    }

    public function cleanup_orphaned_vectors($collection_id, $valid_item_ids): int {
        global $wpdb;
        
        if (empty($valid_item_ids)) {
            return $this->delete_collection_vectors($collection_id);
        }
        
        $placeholders = implode(',', array_fill(0, count($valid_item_ids), '%d'));
        
        $query = $wpdb->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE collection_id = %d 
             AND item_id NOT IN ($placeholders)",
            array_merge(array($collection_id), $valid_item_ids)
        );
        
        return $wpdb->query($query);
    }

    public function search(array $query_vector, int $limit = 5, array $collections = array()): array {
        global $wpdb;

        $where_conditions = array('1=1');
        $query_params = array();
        
        if (!empty($collections) && is_array($collections)) {
            $placeholders = array_fill(0, count($collections), '%d');
            $where_conditions[] = 'collection_id IN (' . implode(',', $placeholders) . ')';
            $query_params = array_merge($query_params, $collections);
        }

        $where_clause = implode(' AND ', $where_conditions);

        $query = $wpdb->prepare(
            "SELECT id, item_id, collection_id, collection_name, vector, content, permalink 
             FROM {$this->table_name} 
             WHERE {$where_clause}
             LIMIT %d",
            array_merge($query_params, array($limit * 3))
        );

        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($results)) {
            return array();
        }

        $scored_results = array();
        foreach ($results as $result) {
            $stored_vector = $this->decode_vector($result['vector']);
            $similarity = $this->cosine_similarity($query_vector, $stored_vector);
            
            $result['similarity'] = $similarity;
            $result['score'] = round($similarity * 100, 1);
            unset($result['vector']);
            
            $scored_results[] = $result;
        }

        usort($scored_results, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($scored_results, 0, $limit);
    }

    private function cosine_similarity(array $vec_a, array $vec_b): float {
        $dot_product = 0;
        $mag_a = 0;
        $mag_b = 0;

        foreach ($vec_a as $i => $val_a) {
            $val_b = $vec_b[$i] ?? 0;
            $dot_product += $val_a * $val_b;
            $mag_a += $val_a * $val_a;
            $mag_b += $val_b * $val_b;
        }

        $mag_a = sqrt($mag_a);
        $mag_b = sqrt($mag_b);

        if ($mag_a * $mag_b == 0) {
            return 0;
        }

        return $dot_product / ($mag_a * $mag_b);
    }

    private function encode_vector(array $vector): string {
        return json_encode($vector);
    }

    private function decode_vector(string $encoded_vector): array {
        $decoded = json_decode($encoded_vector, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        
        return unserialize(base64_decode($encoded_vector));
    }

    public function log_search(array $data): int|false {
        global $wpdb;

        $search_data = array(
            'query' => $data['query'],
            'response' => $data['response'],
            'items_used' => maybe_serialize($data['items_used']),
            'collections_used' => maybe_serialize($data['collections_used']),
            'user_id' => get_current_user_id(),
            'user_ip' => $this->get_user_ip()
        );

        $result = $wpdb->insert(
            $this->history_table_name,
            $search_data,
            array('%s', '%s', '%s', '%s', '%d', '%s')
        );

        return $result ? $wpdb->insert_id : false;
    }

    public function get_search_history(array $args = array()): array {
        global $wpdb;

        $defaults = array(
            'limit' => 100,
            'offset' => 0,
            'start_date' => null,
            'end_date' => null,
            'collection' => null,
            'user_id' => null,
            'order' => 'DESC',
            'search' => null,
            'feedback' => null
        );

        $args = wp_parse_args($args, $defaults);
        
        $where = array('1=1');
        $query_params = array();

        if ($args['start_date']) {
            $where[] = 'created_at >= %s';
            $query_params[] = $args['start_date'] . ' 00:00:00';
        }

        if ($args['end_date']) {
            $where[] = 'created_at <= %s';
            $query_params[] = $args['end_date'] . ' 23:59:59';
        }

        if ($args['user_id']) {
            $where[] = 'user_id = %d';
            $query_params[] = $args['user_id'];
        }

        if ($args['collection']) {
            $where[] = 'collections_used LIKE %s';
            $query_params[] = '%' . $wpdb->esc_like($args['collection']) . '%';
        }

        if ($args['search']) {
            $where[] = '(query LIKE %s OR response LIKE %s)';
            $query_params[] = '%' . $wpdb->esc_like($args['search']) . '%';
            $query_params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        if ($args['feedback'] !== null) {
            $where[] = 'feedback = %d';
            $query_params[] = $args['feedback'];
        }

        $where_clause = implode(' AND ', $where);
        $query = $wpdb->prepare(
            "SELECT * FROM {$this->history_table_name} 
             WHERE $where_clause 
             ORDER BY created_at {$args['order']} 
             LIMIT %d OFFSET %d",
            array_merge($query_params, array($args['limit'], $args['offset']))
        );

        $results = $wpdb->get_results($query, ARRAY_A);
        if (!$results) {
            return array();
        }

        foreach ($results as &$result) {
            $result['items_used'] = maybe_unserialize($result['items_used']);
            $result['collections_used'] = maybe_unserialize($result['collections_used']);
        }

        return $results;
    }

    public function get_search_stats(string $period = 'day'): array {
        global $wpdb;

        switch ($period) {
            case 'week':
                $group = "YEARWEEK(created_at)";
                $date_format = "DATE_FORMAT(created_at, '%Y-%u')";
                $date_label = "DATE_FORMAT(created_at, '%Y Week %u')";
                break;
            case 'month':
                $group = "YEAR(created_at), MONTH(created_at)";
                $date_format = "DATE_FORMAT(created_at, '%Y-%m')";
                $date_label = "DATE_FORMAT(created_at, '%Y-%m')";
                break;
            default:
                $group = "DATE(created_at)";
                $date_format = "DATE(created_at)";
                $date_label = "DATE_FORMAT(created_at, '%Y-%m-%d')";
                break;
        }

        $period_stats = $wpdb->get_results(
            "SELECT $date_label as period_label, 
                    $date_format as period_key, 
                    COUNT(*) as count 
             FROM {$this->history_table_name} 
             GROUP BY $group 
             ORDER BY created_at DESC 
             LIMIT 30",
            ARRAY_A
        );

        $collection_stats = $wpdb->get_results(
            "SELECT collections_used, COUNT(*) as count 
             FROM {$this->history_table_name} 
             GROUP BY collections_used 
             ORDER BY count DESC 
             LIMIT 10",
            ARRAY_A
        );

        $collection_data = array();
        foreach ($collection_stats as $stat) {
            $collections = maybe_unserialize($stat['collections_used']);
            if (is_array($collections)) {
                foreach ($collections as $collection) {
                    if (!isset($collection_data[$collection])) {
                        $collection_data[$collection] = 0;
                    }
                    $collection_data[$collection] += $stat['count'];
                }
            }
        }
        
        $collection_results = array();
        foreach ($collection_data as $collection => $count) {
            $collection_results[] = array(
                'collection' => $collection,
                'count' => $count
            );
        }
        
        usort($collection_results, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return array(
            'period' => $period_stats,
            'collections' => array_slice($collection_results, 0, 10)
        );
    }

    public function update_search_feedback(int $search_id, int $feedback, string $notes = ''): bool {
        global $wpdb;

        $result = $wpdb->update(
            $this->history_table_name,
            array(
                'feedback' => $feedback,
                'feedback_notes' => $notes
            ),
            array('id' => $search_id),
            array('%d', '%s'),
            array('%d')
        );

        return $result !== false;
    }

    private function get_user_ip(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field($ip);
    }

    public function get_total_vectors(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }

    public function get_vectors_by_collection(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT collection_id, collection_name, COUNT(*) as count 
             FROM {$this->table_name} 
             GROUP BY collection_id, collection_name 
             ORDER BY count DESC",
            ARRAY_A
        );
    }
}