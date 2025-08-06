<?php
/**
 * WP-CLI Commands for Oráculo Tainacan
 *
 * @package Oraculo_Tainacan
 */

// Impede o acesso direto
if (!defined('WPINC')) {
    die;
}

/**
 * Manage Oráculo Tainacan via command line
 */
class Oraculo_Tainacan_CLI_Commands {

    /**
     * RAG Engine instance
     *
     * @var Oraculo_Tainacan_RAG_Engine
     */
    private Oraculo_Tainacan_RAG_Engine $rag_engine;

    /**
     * Vector DB instance
     *
     * @var Oraculo_Tainacan_Vector_DB
     */
    private Oraculo_Tainacan_Vector_DB $vector_db;

    /**
     * Constructor
     */
    public function __construct() {
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-rag-engine.php';
        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-vector-db.php';
        
        $this->rag_engine = new Oraculo_Tainacan_RAG_Engine();
        $this->vector_db = new Oraculo_Tainacan_Vector_DB();
    }

    /**
     * Index a specific collection
     *
     * ## OPTIONS
     *
     * [--collection=<id>]
     * : The collection ID to index
     *
     * [--force]
     * : Force reindexing of all items
     *
     * [--batch-size=<size>]
     * : Number of items to process per batch (default: 50)
     *
     * ## EXAMPLES
     *
     *     wp oraculo index --collection=123
     *     wp oraculo index --collection=123 --force
     *
     * @when after_wp_load
     */
    public function index($args, $assoc_args) {
        $collection_id = isset($assoc_args['collection']) ? intval($assoc_args['collection']) : 0;
        $force = isset($assoc_args['force']);
        $batch_size = isset($assoc_args['batch-size']) ? intval($assoc_args['batch-size']) : 50;

        if (empty($collection_id)) {
            WP_CLI::error('Collection ID is required. Use --collection=ID');
        }

        WP_CLI::log("Starting indexing for collection ID: {$collection_id}");
        
        if ($force) {
            WP_CLI::log("Force mode enabled - all items will be reindexed");
        }

        // Show progress bar
        $progress = \WP_CLI\Utils\make_progress_bar('Indexing collection', 100);

        // Index the collection
        $result = $this->rag_engine->index_collection($collection_id, $force);

        if ($result === false) {
            $progress->finish();
            WP_CLI::error('Failed to index collection');
        }

        $progress->finish();

        WP_CLI::success(sprintf(
            'Indexing complete! Processed: %d, Success: %d, Failed: %d',
            $result['total'],
            $result['success'],
            $result['failed']
        ));

        // Show statistics
        $this->show_collection_stats($collection_id);
    }

    /**
     * Index all configured collections
     *
     * ## OPTIONS
     *
     * [--force]
     * : Force reindexing of all items
     *
     * ## EXAMPLES
     *
     *     wp oraculo index --all
     *     wp oraculo index --all --force
     *
     * @subcommand index-all
     * @when after_wp_load
     */
    public function index_all($args, $assoc_args) {
        $force = isset($assoc_args['force']);
        $collections = get_option('oraculo_tainacan_collections', array());

        if (empty($collections)) {
            WP_CLI::error('No collections configured. Please configure collections in the admin panel first.');
        }

        WP_CLI::log(sprintf('Indexing %d collections...', count($collections)));

        $total_stats = array(
            'collections' => 0,
            'total' => 0,
            'success' => 0,
            'failed' => 0
        );

        foreach ($collections as $collection_id) {
            WP_CLI::log("\nProcessing collection ID: {$collection_id}");
            
            $result = $this->rag_engine->index_collection(intval($collection_id), $force);
            
            if ($result !== false) {
                $total_stats['collections']++;
                $total_stats['total'] += $result['total'];
                $total_stats['success'] += $result['success'];
                $total_stats['failed'] += $result['failed'];
                
                WP_CLI::log(sprintf(
                    'Collection %s: %d items (%d success, %d failed)',
                    $result['collection'],
                    $result['total'],
                    $result['success'],
                    $result['failed']
                ));
            } else {
                WP_CLI::warning("Failed to index collection ID: {$collection_id}");
            }
        }

        WP_CLI::success(sprintf(
            'All collections indexed! Collections: %d, Total items: %d, Success: %d, Failed: %d',
            $total_stats['collections'],
            $total_stats['total'],
            $total_stats['success'],
            $total_stats['failed']
        ));
    }

    /**
     * Clear vector cache
     *
     * ## OPTIONS
     *
     * [--collection=<id>]
     * : Clear cache for specific collection only
     *
     * [--confirm]
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *
     *     wp oraculo clear-cache
     *     wp oraculo clear-cache --collection=123
     *     wp oraculo clear-cache --confirm
     *
     * @subcommand clear-cache
     * @when after_wp_load
     */
    public function clear_cache($args, $assoc_args) {
        $collection_id = isset($assoc_args['collection']) ? intval($assoc_args['collection']) : null;
        $confirm = isset($assoc_args['confirm']);

        if (!$confirm) {
            WP_CLI::confirm('Are you sure you want to clear the vector cache? This will require reindexing.', $assoc_args);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'oraculo_vectors';

        if ($collection_id) {
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table_name} WHERE collection_id = %d",
                $collection_id
            ));
            
            WP_CLI::success("Cleared {$result} vectors from collection ID: {$collection_id}");
        } else {
            $result = $wpdb->query("TRUNCATE TABLE {$table_name}");
            
            if ($result !== false) {
                WP_CLI::success('All vector cache cleared successfully');
            } else {
                WP_CLI::error('Failed to clear vector cache');
            }
        }

        // Clear transients
        $this->clear_transients();
    }

    /**
     * Show statistics
     *
     * ## OPTIONS
     *
     * [--collection=<id>]
     * : Show stats for specific collection
     *
     * ## EXAMPLES
     *
     *     wp oraculo stats
     *     wp oraculo stats --collection=123
     *
     * @when after_wp_load
     */
    public function stats($args, $assoc_args) {
        $collection_id = isset($assoc_args['collection']) ? intval($assoc_args['collection']) : null;

        if ($collection_id) {
            $this->show_collection_stats($collection_id);
        } else {
            $this->show_global_stats();
        }
    }

    /**
     * Search the indexed content
     *
     * ## OPTIONS
     *
     * <query>
     * : The search query
     *
     * [--collections=<ids>]
     * : Comma-separated collection IDs to search
     *
     * [--limit=<number>]
     * : Maximum number of results (default: 10)
     *
     * [--format=<format>]
     * : Output format (table, json, csv)
     *
     * ## EXAMPLES
     *
     *     wp oraculo search "modernist art"
     *     wp oraculo search "ceramic pieces" --collections=1,2,3
     *     wp oraculo search "photography" --limit=5 --format=json
     *
     * @when after_wp_load
     */
    public function search($args, $assoc_args) {
        $query = $args[0];
        $collections = isset($assoc_args['collections']) 
            ? array_map('intval', explode(',', $assoc_args['collections'])) 
            : array();
        $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 10;
        $format = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        // Set max items temporarily
        $this->rag_engine->set_max_items($limit);

        WP_CLI::log("Searching for: {$query}");

        $start_time = microtime(true);
        $results = $this->rag_engine->search($query, $collections);
        $search_time = round(microtime(true) - $start_time, 2);

        if (is_wp_error($results)) {
            WP_CLI::error($results->get_error_message());
        }

        WP_CLI::log("\nResponse:");
        WP_CLI::log($results['response']);
        WP_CLI::log("\n" . str_repeat('-', 50) . "\n");

        // Format items for display
        $items_data = array();
        foreach ($results['items'] as $index => $item) {
            $items_data[] = array(
                'Rank' => $index + 1,
                'Title' => $item['title'],
                'Collection' => $item['collection'],
                'Score' => $item['score'] . '%',
                'URL' => $item['permalink']
            );
        }

        WP_CLI\Utils\format_items($format, $items_data, array('Rank', 'Title', 'Collection', 'Score', 'URL'));

        WP_CLI::log("\nSearch completed in {$search_time} seconds");
    }

    /**
     * Test connection to OpenAI
     *
     * ## EXAMPLES
     *
     *     wp oraculo test-connection
     *
     * @subcommand test-connection
     * @when after_wp_load
     */
    public function test_connection($args, $assoc_args) {
        WP_CLI::log('Testing OpenAI connection...');

        require_once ORACULO_TAINACAN_PLUGIN_DIR . 'includes/class-openai-client.php';
        $openai = new Oraculo_Tainacan_OpenAI_Client();

        $result = $openai->test_connection();

        if (is_wp_error($result)) {
            WP_CLI::error('Connection failed: ' . $result->get_error_message());
        }

        WP_CLI::success('Connection successful!');
        
        // Show current model
        $model = get_option('oraculo_tainacan_openai_model', 'gpt-4o');
        WP_CLI::log("Current model: {$model}");
    }

    /**
     * Show collection statistics
     *
     * @param int $collection_id Collection ID
     */
    private function show_collection_stats(int $collection_id): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'oraculo_vectors';

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_vectors,
                MAX(updated_at) as last_update,
                collection_name
             FROM {$table_name}
             WHERE collection_id = %d
             GROUP BY collection_id, collection_name",
            $collection_id
        ));

        if ($stats) {
            WP_CLI::log("\nCollection Statistics:");
            WP_CLI::log("Name: " . $stats->collection_name);
            WP_CLI::log("Indexed items: " . $stats->total_vectors);
            WP_CLI::log("Last update: " . $stats->last_update);
        } else {
            WP_CLI::log("No data found for collection ID: {$collection_id}");
        }
    }

    /**
     * Show global statistics
     */
    private function show_global_stats(): void {
        $total_vectors = $this->vector_db->get_total_vectors();
        $vectors_by_collection = $this->vector_db->get_vectors_by_collection();

        WP_CLI::log("\nGlobal Statistics:");
        WP_CLI::log("Total indexed items: {$total_vectors}");
        WP_CLI::log("\nBy collection:");

        $table_data = array();
        foreach ($vectors_by_collection as $collection) {
            $table_data[] = array(
                'Collection ID' => $collection['collection_id'],
                'Name' => $collection['collection_name'],
                'Items' => $collection['count']
            );
        }

        WP_CLI\Utils\format_items('table', $table_data, array('Collection ID', 'Name', 'Items'));

        // Show search statistics
        $search_stats = $this->vector_db->get_search_stats('day');
        if (!empty($search_stats['period'])) {
            WP_CLI::log("\nRecent searches (last 7 days):");
            $recent_searches = array_slice($search_stats['period'], 0, 7);
            
            $search_table = array();
            foreach ($recent_searches as $day) {
                $search_table[] = array(
                    'Date' => $day['period_label'],
                    'Searches' => $day['count']
                );
            }
            
            WP_CLI\Utils\format_items('table', $search_table, array('Date', 'Searches'));
        }
    }

    /**
     * Clear transients
     */
    private function clear_transients(): void {
        global $wpdb;
        
        $transients = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_oraculo%' 
             OR option_name LIKE '_transient_timeout_oraculo%'"
        );

        foreach ($transients as $transient) {
            delete_option($transient);
        }

        WP_CLI::log(sprintf('Cleared %d transients', count($transients) / 2));
    }
}

// Register commands if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('oraculo', 'Oraculo_Tainacan_CLI_Commands');
}