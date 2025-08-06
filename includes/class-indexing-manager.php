<?php
/**
 * Gerenciador de Indexação Assíncrona
 *
 * @package Oraculo_Tainacan
 */

if (!defined('WPINC')) {
    die;
}

class Oraculo_Tainacan_Indexing_Manager {
    
    /**
     * Motor RAG
     */
    private $rag_engine;
    
    /**
     * Parser Tainacan
     */
    private $parser;
    
    /**
     * Opção para armazenar estados de indexação
     */
    private const INDEXING_STATES_OPTION = 'oraculo_indexing_states';
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->rag_engine = new Oraculo_Tainacan_RAG_Engine();
        $this->parser = new Oraculo_Tainacan_Parser();
    }
    
    /**
     * Inicia nova indexação
     */
    public function start_indexing($collection_id, $force_update = false) {
        // Verifica se coleção existe
        $collection = $this->parser->get_collection($collection_id);
        
        if (is_wp_error($collection)) {
            return array(
                'success' => false,
                'message' => __('Coleção não encontrada', 'oraculo-tainacan')
            );
        }
        
        $total_items = $this->get_collection_item_count($collection_id);
        
        $state = array(
            'collection_id' => $collection_id,
            'collection_name' => $collection['name'],
            'total_items' => $total_items,
            'processed' => 0,
            'indexed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'current_page' => 1,
            'batch_size' => $this->calculate_optimal_batch_size($total_items),
            'force_update' => $force_update,
            'status' => 'processing',
            'started_at' => current_time('mysql'),
            'last_update' => current_time('mysql'),
            'errors' => array()
        );
        
        $this->save_indexing_state($collection_id, $state);
        
        return array(
            'success' => true,
            'state' => $state,
            'message' => sprintf(
                __('Indexação iniciada para %s (%d itens)', 'oraculo-tainacan'),
                $collection['name'],
                $total_items
            )
        );
    }
    
    /**
     * Processa próximo lote
     */
    public function process_next_batch($collection_id) {
        $start_time = microtime(true);
        
        $state = $this->get_indexing_state($collection_id);
        if (!$state || $state['status'] !== 'processing') {
            return array(
                'success' => false,
                'message' => __('Nenhuma indexação em andamento', 'oraculo-tainacan')
            );
        }
        
        $offset = ($state['current_page'] - 1) * $state['batch_size'];
        
        $items = $this->get_items_batch($collection_id, $state['batch_size'], $offset);
        
        if (empty($items)) {
            $state['status'] = 'completed';
            $state['completed_at'] = current_time('mysql');
            $this->save_indexing_state($collection_id, $state);
            
            return array(
                'success' => true,
                'completed' => true,
                'state' => $state,
                'message' => __('Indexação concluída', 'oraculo-tainacan')
            );
        }
        
        $batch_results = $this->process_items_batch($items, $state);
        
        $state['processed'] += count($items);
        $state['indexed'] += $batch_results['indexed'];
        $state['skipped'] += $batch_results['skipped'];
        $state['failed'] += $batch_results['failed'];
        $state['current_page']++;
        $state['last_update'] = current_time('mysql');
        
        if (!empty($batch_results['errors'])) {
            $state['errors'] = array_merge($state['errors'], $batch_results['errors']);
            $state['errors'] = array_slice($state['errors'], -10);
        }
        
        $this->save_indexing_state($collection_id, $state);
        
        $execution_time = microtime(true) - $start_time;
        
        return array(
            'success' => true,
            'completed' => false,
            'state' => $state,
            'batch_info' => array(
                'items_in_batch' => count($items),
                'indexed' => $batch_results['indexed'],
                'execution_time' => round($execution_time, 2)
            ),
            'message' => sprintf(
                __('Lote processado: %d/%d itens', 'oraculo-tainacan'),
                $state['processed'],
                $state['total_items']
            )
        );
    }
    
    /**
     * Obtém estado da indexação
     */
    public function get_indexing_state($collection_id) {
        $states = get_option(self::INDEXING_STATES_OPTION, array());
        return isset($states[$collection_id]) ? $states[$collection_id] : null;
    }
    
    /**
     * Salva estado da indexação
     */
    private function save_indexing_state($collection_id, $state) {
        $states = get_option(self::INDEXING_STATES_OPTION, array());
        $states[$collection_id] = $state;
        update_option(self::INDEXING_STATES_OPTION, $states);
    }
    
    /**
     * Remove estado da indexação
     */
    private function clear_indexing_state($collection_id) {
        $states = get_option(self::INDEXING_STATES_OPTION, array());
        unset($states[$collection_id]);
        update_option(self::INDEXING_STATES_OPTION, $states);
    }
    
    /**
     * Obtém todos os estados de indexação
     */
    public function get_all_indexing_states() {
        return get_option(self::INDEXING_STATES_OPTION, array());
    }
    
    /**
     * Cancela indexação
     */
    public function cancel_indexing($collection_id) {
        $state = $this->get_indexing_state($collection_id);
        
        if (!$state || $state['status'] !== 'processing') {
            return array(
                'success' => false,
                'message' => __('Nenhuma indexação em andamento para cancelar', 'oraculo-tainacan')
            );
        }
        
        $state['status'] = 'cancelled';
        $state['cancelled_at'] = current_time('mysql');
        $this->save_indexing_state($collection_id, $state);
        
        return array(
            'success' => true,
            'message' => __('Indexação cancelada', 'oraculo-tainacan')
        );
    }
    
    /**
     * Processa lote de itens
     */
    private function process_items_batch($items, $state) {
        $results = array(
            'indexed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => array()
        );
        
        foreach ($items as $item) {
            try {
                // Verifica se deve pular
                if (!$state['force_update'] && $this->rag_engine->is_item_indexed($item['id'], $state['collection_id'])) {
                    $results['skipped']++;
                    continue;
                }
                
                // Indexa item
                $index_result = $this->rag_engine->index_single_item($item, $state['collection_id']);
                
                if ($index_result) {
                    $results['indexed']++;
                } else {
                    $results['failed']++;
                }
                
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = sprintf(
                    __('Erro ao indexar item %d: %s', 'oraculo-tainacan'),
                    $item['id'],
                    $e->getMessage()
                );
            }
        }
        
        return $results;
    }
    
    /**
     * Obtém lote de itens
     */
    private function get_items_batch($collection_id, $batch_size, $offset) {
        $items = $this->parser->get_collection_items($collection_id, $batch_size, 1 + floor($offset / $batch_size));
        
        if (is_wp_error($items)) {
            throw new Exception($items->get_error_message());
        }
        
        // Se o parser retornou todos os itens de uma vez, precisamos paginar manualmente
        if (count($items) > $batch_size) {
            return array_slice($items, $offset % $batch_size, $batch_size);
        }
        
        return $items;
    }
    
    /**
     * Obtém contagem de itens da coleção
     */
    private function get_collection_item_count($collection_id) {
        $collection = $this->parser->get_collection($collection_id);
        
        if (is_wp_error($collection)) {
            return 0;
        }
        
        return isset($collection['items_count']) ? (int) $collection['items_count'] : 0;
    }
    
    /**
     * Calcula tamanho ótimo do lote
     */
    private function calculate_optimal_batch_size($total_items) {
        // Lotes menores para coleções grandes
        if ($total_items > 1000) {
            return 10;
        } elseif ($total_items > 500) {
            return 20;
        } elseif ($total_items > 100) {
            return 30;
        } else {
            return 50;
        }
    }
    
    /**
     * Limpa estados antigos
     */
    public function cleanup_old_states($days = 7) {
        $states = $this->get_all_indexing_states();
        $cutoff_time = strtotime("-{$days} days");
        $cleaned = 0;
        
        foreach ($states as $collection_id => $state) {
            if ($state['status'] !== 'processing') {
                $last_update = strtotime($state['last_update']);
                if ($last_update < $cutoff_time) {
                    $this->clear_indexing_state($collection_id);
                    $cleaned++;
                }
            }
        }
        
        return $cleaned;
    }
}