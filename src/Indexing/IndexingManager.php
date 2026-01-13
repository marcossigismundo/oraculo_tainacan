<?php
/**
 * Gerenciador de indexação síncrona
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\Indexing;

use Oraculo_Tainacan\AI\AIProviderFactory;
use Oraculo_Tainacan\Vector\VectorStore;
use WP_Error;

/**
 * Gerencia a indexação de coleções do Tainacan
 * Executa indexação síncrona para resposta imediata
 */
class IndexingManager {

    /**
     * Factory de provedores de IA
     * @var AIProviderFactory
     */
    private AIProviderFactory $ai_factory;

    /**
     * Store de vetores
     * @var VectorStore
     */
    private VectorStore $vector_store;

    /**
     * Opções do plugin
     * @var array
     */
    private array $options;

    /**
     * Construtor
     */
    public function __construct() {
        $this->ai_factory = new AIProviderFactory();
        $this->vector_store = new VectorStore();
        $this->options = get_option('oraculo_tainacan_options', []);
    }

    /**
     * Executa indexação completa de uma coleção de forma síncrona
     *
     * @param int $collection_id
     * @param bool $force Força reindexação mesmo se já existir
     * @return array|WP_Error
     */
    public function start_indexing(int $collection_id, bool $force = false) {
        // Aumentar limites de execução
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        // Verificar se coleção existe
        $collection = $this->get_collection($collection_id);
        if (!$collection) {
            return new WP_Error('invalid_collection', __('Coleção não encontrada.', 'oraculo-tainacan'));
        }

        // Limpar vetores existentes se force
        if ($force) {
            $this->vector_store->delete_collection($collection_id);
        }

        // Contar itens
        $total_items = $this->count_collection_items($collection_id);

        if ($total_items === 0) {
            return new WP_Error('empty_collection', __('A coleção não possui itens para indexar.', 'oraculo-tainacan'));
        }

        // Verificar se provedor de embeddings está configurado
        try {
            $embedding_provider = $this->ai_factory->create_for_embeddings();
            if (!$embedding_provider->is_configured()) {
                return new WP_Error('provider_not_configured', __('Provedor de embeddings não configurado. Configure OpenAI ou Ollama.', 'oraculo-tainacan'));
            }
        } catch (\Exception $e) {
            return new WP_Error('provider_error', $e->getMessage());
        }

        // Executar indexação síncrona
        $result = $this->index_collection_sync($collection_id, $total_items);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'collection_id' => $collection_id,
            'collection_name' => $collection['name'],
            'total_items' => $total_items,
            'indexed_items' => $result['success'],
            'failed_items' => $result['failed'],
            'status' => 'completed',
            'percentage' => 100,
            'message' => sprintf(
                __('Indexação concluída! %d de %d itens indexados.', 'oraculo-tainacan'),
                $result['success'],
                $total_items
            ),
            'errors' => $result['errors'],
        ];
    }

    /**
     * Executa indexação síncrona de todos os itens
     *
     * @param int $collection_id
     * @param int $total_items
     * @return array|WP_Error
     */
    private function index_collection_sync(int $collection_id, int $total_items): array {
        $batch_size = $this->get_batch_size();
        $total_pages = ceil($total_items / $batch_size);

        $success = 0;
        $failed = 0;
        $errors = [];

        for ($page = 1; $page <= $total_pages; $page++) {
            $items = $this->get_collection_items($collection_id, $page, $batch_size);

            if (empty($items)) {
                break;
            }

            $result = $this->process_items_batch($items, $collection_id);

            $success += $result['success'];
            $failed += $result['failed'];

            if (!empty($result['errors'])) {
                $errors = array_merge($errors, $result['errors']);
            }

            // Liberar memória
            unset($items);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'errors' => array_slice($errors, 0, 10), // Limitar a 10 erros
        ];
    }

    /**
     * Processa batch de itens
     *
     * @param array $items
     * @param int $collection_id
     * @return array
     */
    private function process_items_batch(array $items, int $collection_id): array {
        $success = 0;
        $failed = 0;
        $errors = [];

        // Preparar textos para batch embedding
        $texts = [];
        $item_data = [];
        $index_fields = $this->options['index_fields'] ?? ['title', 'description'];

        foreach ($items as $item) {
            try {
                // Verificar se é um objeto Item válido
                if (!($item instanceof \Tainacan\Entities\Item)) {
                    $failed++;
                    $errors[] = 'Item inválido - não é uma entidade Tainacan';
                    continue;
                }

                $formatted = \Oraculo_Tainacan\format_tainacan_item($item);

                if (empty($formatted['id'])) {
                    $failed++;
                    $errors[] = 'Item sem ID';
                    continue;
                }

                $text = \Oraculo_Tainacan\format_item_for_indexing($formatted, $index_fields);
                $content_hash = \Oraculo_Tainacan\generate_content_hash($text);

                // Verificar se precisa reindexar
                if (!$this->vector_store->needs_reindex($formatted['id'], $collection_id, $content_hash)) {
                    $success++;
                    continue;
                }

                if (!empty(trim($text))) {
                    $texts[] = $text;
                    $item_data[] = $formatted;
                } else {
                    $failed++;
                    $errors[] = 'Item #' . $formatted['id'] . ' não tem texto para indexar';
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = 'Erro ao processar item: ' . $e->getMessage();
            }
        }

        if (empty($texts)) {
            // Se todos os itens foram pulados (já indexados ou sem texto)
            if ($success === 0 && count($items) > 0) {
                return [
                    'success' => 0,
                    'failed' => count($items),
                    'errors' => ['Nenhum item tem conteúdo para indexar. Verifique se os itens possuem título ou descrição.'],
                ];
            }
            return ['success' => $success, 'failed' => 0, 'errors' => []];
        }

        // Gerar embeddings em batch
        try {
            $embedding_provider = $this->ai_factory->create_for_embeddings();

            // Verificar se o provedor está configurado
            if (!$embedding_provider->is_configured()) {
                return [
                    'success' => $success,
                    'failed' => count($texts),
                    'errors' => ['Provedor de embeddings não está configurado. Verifique a API key nas configurações.'],
                ];
            }

            // Debug: verificar tamanho dos textos
            $total_chars = array_sum(array_map('mb_strlen', $texts));
            error_log('[Oraculo] Enviando ' . count($texts) . ' textos para embedding, total de ' . $total_chars . ' caracteres');

            $embedding_result = $embedding_provider->generate_embeddings_batch($texts);

            if (is_wp_error($embedding_result)) {
                return [
                    'success' => $success,
                    'failed' => count($texts),
                    'errors' => ['Erro ao gerar embeddings: ' . $embedding_result->get_error_message()],
                ];
            }

            // Verificar se recebemos embeddings
            if (empty($embedding_result['embeddings'])) {
                return [
                    'success' => $success,
                    'failed' => count($texts),
                    'errors' => ['A API não retornou embeddings. Verifique a configuração do provedor.'],
                ];
            }

            // Salvar vetores
            foreach ($embedding_result['embeddings'] as $index => $embedding) {
                if (!isset($item_data[$index])) {
                    continue;
                }

                $item = $item_data[$index];
                $content_hash = \Oraculo_Tainacan\generate_content_hash($texts[$index]);

                $result = $this->vector_store->upsert([
                    'item_id' => $item['id'],
                    'collection_id' => $collection_id,
                    'collection_name' => $item['collection_name'] ?? '',
                    'embedding_data' => $embedding,
                    'content_text' => $texts[$index],
                    'content_hash' => $content_hash,
                    'item_url' => $item['url'] ?? '',
                    'item_title' => $item['title'] ?? '',
                    'metadata' => $item['metadata'] ?? [],
                    'embedding_model' => $embedding_result['model'] ?? 'text-embedding-ada-002',
                    'token_count' => \Oraculo_Tainacan\estimate_tokens($texts[$index]),
                ]);

                if (is_wp_error($result)) {
                    $failed++;
                    $errors[] = [
                        'item_id' => $item['id'],
                        'error' => $result->get_error_message(),
                    ];
                } else {
                    $success++;
                }
            }

        } catch (\Exception $e) {
            return [
                'success' => $success,
                'failed' => count($texts),
                'errors' => [$e->getMessage()],
            ];
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Obtém status de indexação de uma coleção
     *
     * @param int|null $collection_id
     * @return array
     */
    public function get_status(?int $collection_id = null): array {
        if (!$collection_id) {
            return [
                'status' => 'idle',
                'indexed_items' => 0,
                'total_items' => 0,
                'percentage' => 0,
            ];
        }

        $indexed = $this->vector_store->count_by_collection($collection_id);
        $total = $this->count_collection_items($collection_id);

        $percentage = $total > 0 ? round(($indexed / $total) * 100, 1) : 0;

        // Determinar status
        $status = 'idle';
        if ($indexed > 0 && $indexed >= $total) {
            $status = 'completed';
        } elseif ($indexed > 0) {
            $status = 'partial';
        }

        return [
            'status' => $status,
            'collection_id' => $collection_id,
            'indexed_items' => $indexed,
            'total_items' => $total,
            'percentage' => $percentage,
        ];
    }

    /**
     * Indexa um único item (para uso em hooks de criação/atualização)
     *
     * @param int $item_id
     * @param int $collection_id
     * @return bool|WP_Error
     */
    public function index_single_item(int $item_id, int $collection_id) {
        $item = \Oraculo_Tainacan\get_tainacan_item($item_id);

        if (!$item) {
            return new WP_Error('item_not_found', __('Item não encontrado.', 'oraculo-tainacan'));
        }

        $index_fields = $this->options['index_fields'] ?? ['title', 'description'];
        $text = \Oraculo_Tainacan\format_item_for_indexing($item, $index_fields);

        if (empty(trim($text))) {
            return new WP_Error('empty_content', __('Item não possui conteúdo para indexar.', 'oraculo-tainacan'));
        }

        try {
            $embedding_provider = $this->ai_factory->create_for_embeddings();
            $embedding_result = $embedding_provider->generate_embedding($text);

            if (is_wp_error($embedding_result)) {
                return $embedding_result;
            }

            $content_hash = \Oraculo_Tainacan\generate_content_hash($text);

            $result = $this->vector_store->upsert([
                'item_id' => $item['id'],
                'collection_id' => $collection_id,
                'collection_name' => $item['collection_name'] ?? '',
                'embedding_data' => $embedding_result['embedding'],
                'content_text' => $text,
                'content_hash' => $content_hash,
                'item_url' => $item['url'] ?? '',
                'item_title' => $item['title'] ?? '',
                'metadata' => $item['metadata'] ?? [],
                'embedding_model' => $embedding_result['model'] ?? 'text-embedding-ada-002',
            ]);

            return !is_wp_error($result);

        } catch (\Exception $e) {
            return new WP_Error('indexing_error', $e->getMessage());
        }
    }

    /**
     * Remove item do índice
     *
     * @param int $item_id
     * @param int $collection_id
     * @return bool
     */
    public function remove_item(int $item_id, int $collection_id): bool {
        return $this->vector_store->delete($item_id, $collection_id);
    }

    /**
     * Obtém tamanho do batch
     *
     * @return int
     */
    private function get_batch_size(): int {
        $configured = $this->options['batch_size'] ?? 10;
        // Batch menor para processamento mais rápido e menos uso de memória
        return min(max(5, (int) $configured), 25);
    }

    /**
     * Obtém coleção do Tainacan
     *
     * @param int $collection_id
     * @return array|null
     */
    private function get_collection(int $collection_id): ?array {
        if (!class_exists('\Tainacan\Repositories\Collections')) {
            return null;
        }

        $repository = \Tainacan\Repositories\Collections::get_instance();
        $collection = $repository->fetch($collection_id);

        if (!$collection || !$collection->get_id()) {
            return null;
        }

        return [
            'id' => $collection->get_id(),
            'name' => $collection->get_name(),
            'description' => $collection->get_description(),
        ];
    }

    /**
     * Conta itens de uma coleção
     *
     * @param int $collection_id
     * @return int
     */
    private function count_collection_items(int $collection_id): int {
        if (!class_exists('\Tainacan\Repositories\Collections')) {
            return 0;
        }

        $collections_repo = \Tainacan\Repositories\Collections::get_instance();
        $collection = $collections_repo->fetch($collection_id);

        if (!$collection || !method_exists($collection, 'get_db_identifier')) {
            return 0;
        }

        $post_type = $collection->get_db_identifier();
        if (empty($post_type)) {
            return 0;
        }

        $counts = wp_count_posts($post_type);
        return isset($counts->publish) ? (int) $counts->publish : 0;
    }

    /**
     * Obtém itens de uma coleção
     *
     * @param int $collection_id
     * @param int $page
     * @param int $per_page
     * @return array
     */
    private function get_collection_items(int $collection_id, int $page, int $per_page): array {
        if (!class_exists('\Tainacan\Repositories\Items')) {
            return [];
        }

        $repository = \Tainacan\Repositories\Items::get_instance();

        $items = $repository->fetch([
            'collection_id' => $collection_id,
            'status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
        ], [], 'OBJECT');

        return is_array($items) ? $items : [];
    }
}
