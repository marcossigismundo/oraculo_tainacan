<?php
/**
 * Motor de RAG (Recuperação Aumentada por Geração)
 *
 * @package Oraculo_Tainacan
 */

// Impede o acesso direto
if (!defined('WPINC')) {
    die;
}

/**
 * Classe do motor RAG
 */
class Oraculo_Tainacan_RAG_Engine {
    /**
     * Cliente OpenAI
     *
     * @var Oraculo_Tainacan_OpenAI_Client
     */
    private Oraculo_Tainacan_OpenAI_Client $openai;

    /**
     * Banco de dados vetorial
     *
     * @var Oraculo_Tainacan_Vector_DB
     */
    private Oraculo_Tainacan_Vector_DB $vector_db;

    /**
     * Parser do Tainacan
     *
     * @var Oraculo_Tainacan_Parser
     */
    private Oraculo_Tainacan_Parser $parser;

    /**
     * Quantidade de itens a retornar
     *
     * @var int
     */
    private int $max_items;

    /**
     * Sistema de prompt para o ChatGPT
     *
     * @var string
     */
    private string $system_prompt;

    /**
     * Modo de debug
     *
     * @var bool
     */
    private bool $debug_mode;

    /**
     * Fields to index
     *
     * @var array
     */
    private array $index_fields;

    /**
     * Chunk size for text splitting
     *
     * @var int
     */
    private int $chunk_size;

    /**
     * Construtor
     */
    public function __construct() {
        $this->openai = new Oraculo_Tainacan_OpenAI_Client();
        $this->vector_db = new Oraculo_Tainacan_Vector_DB();
        $this->parser = new Oraculo_Tainacan_Parser();
        $this->max_items = (int) get_option('oraculo_tainacan_max_items', 10);
        $this->system_prompt = get_option('oraculo_tainacan_system_prompt', $this->get_default_system_prompt());
        $this->debug_mode = (bool) get_option('oraculo_tainacan_debug_mode', false);
        $this->index_fields = get_option('oraculo_tainacan_index_fields', ['title', 'description', 'metadata']);
        $this->chunk_size = (int) get_option('oraculo_tainacan_chunk_size', 512);
    }

    /**
     * Retorna o prompt padrão do sistema
     * 
     * @return string Prompt do sistema
     */
    private function get_default_system_prompt(): string {
        return "Você é um oráculo digital especializado em acervos museológicos. Responda com base apenas nas informações dos documentos abaixo. Use linguagem clara, confiante e educativa. Nunca invente nada.";
    }

    /**
     * Schedule background indexing for a collection
     *
     * @param int $collection_id Collection ID
     * @param bool $force_update Force update all items
     * @return bool Success
     */
    public function schedule_collection_indexing(int $collection_id, bool $force_update = false): bool {
        if (!function_exists('as_schedule_single_action')) {
            // Fallback to immediate execution if Action Scheduler not available
            return $this->index_collection($collection_id, $force_update) !== false;
        }

        // Schedule the action
        as_schedule_single_action(
            time(),
            'oraculo_index_collection',
            array(
                'collection_id' => $collection_id,
                'force_update' => $force_update
            ),
            'oraculo-tainacan'
        );

        $this->log_debug("Scheduled indexing for collection ID: {$collection_id}");
        return true;
    }

    /**
     * Schedule indexing for all collections
     *
     * @param bool $force_update Force update all items
     * @return bool Success
     */
    public function schedule_all_indexing(bool $force_update = false): bool {
        $collections = get_option('oraculo_tainacan_collections', array());
        
        if (empty($collections)) {
            return false;
        }

        foreach ($collections as $collection_id) {
            $this->schedule_collection_indexing((int)$collection_id, $force_update);
        }

        return true;
    }

    /**
     * Index a collection with batch processing
     *
     * @param int $collection_id ID da coleção
     * @param bool $force_update Forçar atualização de todos os itens
     * @return array|false Resultado da indexação ou false em erro
     */
    public function index_collection(int $collection_id, bool $force_update = false): array|false {
        // Get collection info
        $collection = $this->parser->get_collection($collection_id);
        if (is_wp_error($collection)) {
            $this->log_error("Failed to get collection {$collection_id}: " . $collection->get_error_message());
            return false;
        }

        $this->log_debug("Starting indexing for collection: {$collection['name']} (ID: {$collection_id})");

        // Get all items
        $items = $this->parser->get_collection_items($collection_id);
        if (is_wp_error($items)) {
            $this->log_error("Failed to get items: " . $items->get_error_message());
            return false;
        }

        // Prepare texts for batch embedding
        $texts_to_embed = array();
        $item_data_map = array();

        foreach ($items as $index => $item) {
            // Check if needs update
            if (!$force_update) {
                // Skip if already indexed and not modified
                // Implementation depends on tracking last modified date
            }

            // Format item for embedding based on selected fields
            $item_text = $this->format_item_for_embedding($item);
            
            // Store for batch processing
            $texts_to_embed[] = $item_text;
            $item_data_map[$index] = array(
                'item' => $item,
                'text' => $item_text,
                'collection_id' => $collection_id,
                'collection_name' => $collection['name']
            );
        }

        if (empty($texts_to_embed)) {
            $this->log_debug("No items to index");
            return array('total' => 0, 'success' => 0, 'failed' => 0);
        }

        // Generate embeddings in batch
        $this->log_debug("Generating embeddings for " . count($texts_to_embed) . " items");
        $embeddings = $this->openai->generate_embeddings_batch($texts_to_embed);
        
        if (is_wp_error($embeddings)) {
            $this->log_error("Failed to generate embeddings: " . $embeddings->get_error_message());
            return false;
        }

        // Prepare batch data for storage
        $vectors_to_store = array();
        foreach ($embeddings as $index => $embedding) {
            if (!isset($item_data_map[$index])) {
                continue;
            }

            $data = $item_data_map[$index];
            $vectors_to_store[] = array(
                'item_id' => $data['item']['id'],
                'collection_id' => $data['collection_id'],
                'collection_name' => $data['collection_name'],
                'vector' => $embedding,
                'content' => $data['text'],
                'permalink' => $data['item']['url']
            );
        }

        // Store vectors in batch
        $this->log_debug("Storing " . count($vectors_to_store) . " vectors");
        $result = $this->vector_db->store_vectors_batch($vectors_to_store);

        $this->log_debug("Indexing complete. Success: {$result['success']}, Failed: {$result['failed']}");

        return array(
            'total' => count($items),
            'success' => $result['success'],
            'failed' => $result['failed'],
            'collection' => $collection['name']
        );
    }

    /**
     * Format item for embedding based on selected fields
     *
     * @param array $item Item data
     * @return string Formatted text
     */
    private function format_item_for_embedding(array $item): string {
        $parts = array();

        // Title
        if (in_array('title', $this->index_fields) && !empty($item['title'])) {
            $parts[] = "TÍTULO: " . $item['title'];
        }

        // Description
        if (in_array('description', $this->index_fields) && !empty($item['description'])) {
            $parts[] = "DESCRIÇÃO: " . strip_tags($item['description']);
        }

        // Metadata
        if (in_array('metadata', $this->index_fields) && !empty($item['metadata'])) {
            $metadata_parts = array();
            foreach ($item['metadata'] as $key => $value) {
                if (!empty($value)) {
                    $metadata_parts[] = $key . ": " . $value;
                }
            }
            if (!empty($metadata_parts)) {
                $parts[] = "METADADOS: " . implode(", ", $metadata_parts);
            }
        }

        // Custom fields
        $custom_fields = array_diff($this->index_fields, ['title', 'description', 'metadata']);
        foreach ($custom_fields as $field) {
            if (!empty($item[$field])) {
                $parts[] = strtoupper($field) . ": " . $item[$field];
            }
        }

        $text = implode("\n\n", $parts);

        // Chunk if too long
        if ($this->chunk_size > 0 && strlen($text) > $this->chunk_size * 4) {
            $text = $this->chunk_text($text, $this->chunk_size);
        }

        return $text;
    }

    /**
     * Chunk text into smaller pieces
     *
     * @param string $text Input text
     * @param int $chunk_size Target chunk size in tokens
     * @return string First chunk
     */
    private function chunk_text(string $text, int $chunk_size): string {
        // Simple implementation - take first N characters
        // In production, use proper tokenization
        $max_chars = $chunk_size * 4; // Rough estimate
        
        if (strlen($text) <= $max_chars) {
            return $text;
        }

        // Try to break at sentence boundary
        $chunk = substr($text, 0, $max_chars);
        $last_period = strrpos($chunk, '.');
        if ($last_period !== false && $last_period > $max_chars * 0.8) {
            $chunk = substr($chunk, 0, $last_period + 1);
        }

        return $chunk;
    }

    /**
     * Search with cross-collection support
     *
     * @param string $query User query
     * @param array $collections Collection IDs to search
     * @return array|WP_Error Search results or error
     */
    public function search(string $query, array $collections = array()): array|WP_Error {
        // Check for indexed items
        $total_vectors = $this->vector_db->get_total_vectors();
        if ($total_vectors === 0) {
            return new WP_Error(
                'no_vectors',
                __('Nenhum item indexado. Configure o plugin e indexe as coleções primeiro.', 'oraculo-tainacan')
            );
        }

        // Generate query embedding
        $query_embedding = $this->openai->generate_embedding($query);
        if (is_wp_error($query_embedding)) {
            return $query_embedding;
        }

        // Search across collections
        $similar_items = $this->vector_db->search(
            $query_embedding,
            $this->max_items,
            $collections
        );

        if (empty($similar_items)) {
            return new WP_Error(
                'no_results',
                __('Nenhum item relevante encontrado para a consulta.', 'oraculo-tainacan')
            );
        }

        // Format items with snippets
        $items_context = array();
        foreach ($similar_items as $item) {
            $formatted_item = $this->format_search_result($item, $query);
            $items_context[] = $formatted_item;
        }

        // Generate response
        $response = $this->openai->generate_chat_response(
            $this->system_prompt,
            $query,
            $items_context
        );

        if (is_wp_error($response)) {
            return $response;
        }

        // Log search
        $collections_used = array_unique(array_column($items_context, 'collection'));
        $this->vector_db->log_search(array(
            'query' => $query,
            'response' => $response,
            'items_used' => $items_context,
            'collections_used' => $collections_used
        ));

        return array(
            'query' => $query,
            'response' => $response,
            'items' => $items_context,
            'total_results' => count($similar_items)
        );
    }

    /**
     * Format search result with snippet
     *
     * @param array $item Raw search result
     * @param string $query User query
     * @return array Formatted result
     */
    private function format_search_result(array $item, string $query): array {
        // Extract basic info
        $title = strip_tags($this->parser->extract_title_from_content($item['content']));
        $description = strip_tags($this->parser->extract_description_from_content($item['content']));
        $metadata = $this->parser->extract_metadata_from_content($item['content']);

        // Generate snippet with highlighted terms
        $snippet = $this->generate_snippet($item['content'], $query);

        return array(
            'id' => $item['item_id'],
            'title' => $title,
            'description' => $description,
            'snippet' => $snippet,
            'metadata' => $metadata,
            'permalink' => $item['permalink'],
            'collection' => $item['collection_name'],
            'collection_id' => $item['collection_id'],
            'similarity' => $item['similarity'],
            'score' => $item['score'] ?? round($item['similarity'] * 100, 1)
        );
    }

    /**
     * Generate snippet with query terms highlighted
     *
     * @param string $content Full content
     * @param string $query User query
     * @return string Snippet with <mark> tags
     */
    private function generate_snippet(string $content, string $query): string {
        // Clean content
        $clean_content = strip_tags($content);
        $clean_content = preg_replace('/\s+/', ' ', $clean_content);

        // Find best matching section
        $query_terms = array_filter(explode(' ', strtolower($query)));
        $sentences = preg_split('/[.!?]+/', $clean_content);
        
        $best_sentence = '';
        $best_score = 0;

        foreach ($sentences as $sentence) {
            $sentence_lower = strtolower($sentence);
            $score = 0;
            
            foreach ($query_terms as $term) {
                if (stripos($sentence_lower, $term) !== false) {
                    $score++;
                }
            }
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_sentence = trim($sentence);
            }
        }

        // If no match, use first 150 chars
        if (empty($best_sentence)) {
            $best_sentence = substr($clean_content, 0, 150);
        }

        // Highlight terms
        $snippet = $best_sentence;
        foreach ($query_terms as $term) {
            $snippet = preg_replace(
                '/\b(' . preg_quote($term, '/') . ')\b/i',
                '<mark>$1</mark>',
                $snippet
            );
        }

        return $snippet . '...';
    }

    /**
     * Execute debug search
     *
     * @param string $query Query string
     * @param array $collections Collection IDs
     * @return array Debug information
     */
    public function debug_search(string $query, array $collections = array()): array {
        $debug_info = array(
            'query' => $query,
            'timestamp' => current_time('mysql'),
            'collections_filter' => $collections,
            'model' => get_option('oraculo_tainacan_openai_model', 'gpt-4o'),
            'max_items' => $this->max_items,
            'index_fields' => $this->index_fields,
            'chunk_size' => $this->chunk_size,
            'steps' => array()
        );

        // Step 1: Check vectors
        $start_time = microtime(true);
        $total_vectors = $this->vector_db->get_total_vectors();
        $vectors_by_collection = $this->vector_db->get_vectors_by_collection();
        
        $debug_info['steps'][] = array(
            'step' => 'Vector Database Check',
            'time_seconds' => round(microtime(true) - $start_time, 3),
            'total_vectors' => $total_vectors,
            'collections' => $vectors_by_collection,
            'success' => $total_vectors > 0
        );

        if ($total_vectors === 0) {
            return $debug_info;
        }

        // Step 2: Generate embedding
        $start_time = microtime(true);
        $query_embedding = $this->openai->generate_embedding($query);
        
        $debug_info['steps'][] = array(
            'step' => 'Query Embedding Generation',
            'time_seconds' => round(microtime(true) - $start_time, 3),
            'success' => !is_wp_error($query_embedding),
            'error' => is_wp_error($query_embedding) ? $query_embedding->get_error_message() : null,
            'embedding_size' => !is_wp_error($query_embedding) ? count($query_embedding) : 0
        );

        if (is_wp_error($query_embedding)) {
            return $debug_info;
        }

        // Step 3: Vector search
        $start_time = microtime(true);
        $results = $this->vector_db->search($query_embedding, $this->max_items, $collections);
        
        $debug_info['steps'][] = array(
            'step' => 'Vector Search',
            'time_seconds' => round(microtime(true) - $start_time, 3),
            'results_count' => count($results),
            'results' => array_map(function($r) {
                return array(
                    'item_id' => $r['item_id'],
                    'collection' => $r['collection_name'],
                    'score' => $r['score'] ?? 0,
                    'similarity' => round($r['similarity'], 4)
                );
            }, $results)
        );

        if (empty($results)) {
            return $debug_info;
        }

        // Step 4: Format results
        $items_context = array();
        foreach ($results as $item) {
            $items_context[] = $this->format_search_result($item, $query);
        }

        $debug_info['steps'][] = array(
            'step' => 'Result Formatting',
            'formatted_items' => count($items_context)
        );

        // Step 5: Generate response
        $start_time = microtime(true);
        $response = $this->openai->generate_chat_response(
            $this->system_prompt,
            $query,
            $items_context
        );

        $debug_info['steps'][] = array(
            'step' => 'Response Generation',
            'time_seconds' => round(microtime(true) - $start_time, 3),
            'success' => !is_wp_error($response),
            'error' => is_wp_error($response) ? $response->get_error_message() : null,
            'response_length' => !is_wp_error($response) ? strlen($response) : 0
        );

        // Final result
        $debug_info['final_result'] = array(
            'success' => !is_wp_error($response),
            'response' => !is_wp_error($response) ? $response : null,
            'items' => $items_context,
            'total_time' => array_sum(array_column($debug_info['steps'], 'time_seconds'))
        );

        return $debug_info;
    }

    /**
     * Set system prompt
     *
     * @param string $prompt System prompt
     */
    public function set_system_prompt(string $prompt): void {
        if (!empty($prompt)) {
            $this->system_prompt = $prompt;
        }
    }

    /**
     * Set max items
     *
     * @param int $max_items Maximum items to return
     */
    public function set_max_items(int $max_items): void {
        if ($max_items > 0) {
            $this->max_items = $max_items;
        }
    }

    /**
     * Log error message
     *
     * @param string $message Error message
     */
    private function log_error(string $message): void {
        if (WP_DEBUG === true) {
            error_log('[Oráculo Tainacan] [RAG] [ERROR] ' . $message);
        }
    }

    /**
     * Log debug message
     *
     * @param string $message Debug message
     */
    private function log_debug(string $message): void {
        if ($this->debug_mode && WP_DEBUG === true) {
            error_log('[Oráculo Tainacan] [RAG] [DEBUG] ' . $message);
        }
    }
}