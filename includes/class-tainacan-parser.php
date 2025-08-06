<?php
/**
 * Classe para processar dados do Tainacan
 *
 * @package Oraculo_Tainacan
 */

if (!defined('WPINC')) {
    die;
}

class Oraculo_Tainacan_Parser {
    
    private $api_base = null;
    private $metadata_cache = array();
    private $debug_mode;
    
    public function __construct() {
        $this->debug_mode = (bool) get_option('oraculo_tainacan_debug_mode', false);
    }
    
    /**
     * Obtém o endpoint da API (lazy loading)
     */
    private function get_api_base() {
        if (is_null($this->api_base)) {
            $this->api_base = get_rest_url(null, 'tainacan/v2');
        }
        return $this->api_base;
    }
    
    /**
     * Obtém uma coleção do Tainacan
     */
    public function get_collection($collection_id) {
        $response = wp_remote_get(
            $this->get_api_base() . '/collections/' . $collection_id,
            array('timeout' => 15)
        );

        if (is_wp_error($response)) {
            $this->log_error('Erro ao obter coleção: ' . $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error(
                'tainacan_api_error',
                sprintf(__('Erro ao obter coleção (código %d)', 'oraculo-tainacan'), $status_code)
            );
        }

        $collection = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!is_array($collection) || !isset($collection['id'])) {
            return new WP_Error(
                'tainacan_api_error',
                __('Formato de resposta inválido para coleção', 'oraculo-tainacan')
            );
        }

        return array(
            'id' => $collection['id'],
            'name' => $collection['name'] ?? '',
            'description' => $collection['description'] ?? '',
            'url' => $collection['url'] ?? '',
            'items_count' => $collection['items_count'] ?? 0
        );
    }
    
    /**
     * Obtém todas as coleções
     */
    public function get_collections() {
        $response = wp_remote_get(
            $this->get_api_base() . '/collections?perpage=100',
            array('timeout' => 15)
        );

        if (is_wp_error($response)) {
            $this->log_error('Erro ao obter coleções: ' . $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error(
                'tainacan_api_error',
                sprintf(__('Erro ao obter coleções (código %d)', 'oraculo-tainacan'), $status_code)
            );
        }

        $collections = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!is_array($collections)) {
            return new WP_Error(
                'tainacan_api_error',
                __('Formato de resposta inválido para coleções', 'oraculo-tainacan')
            );
        }

        $formatted_collections = array();
        foreach ($collections as $collection) {
            $formatted_collections[] = array(
                'id' => $collection['id'] ?? 0,
                'name' => $collection['name'] ?? '',
                'description' => $collection['description'] ?? '',
                'url' => $collection['url'] ?? '',
                'items_count' => $collection['items_count'] ?? 0
            );
        }

        return $formatted_collections;
    }
    
    /**
     * Obtém itens de uma coleção
     */
    public function get_collection_items($collection_id, $per_page = 50, $page = 1) {
        $metadata = $this->get_collection_metadata($collection_id);
        if (is_wp_error($metadata)) {
            return $metadata;
        }

        $items = array();
        $has_more = true;
        $current_page = $page;

        while ($has_more) {
            $response = wp_remote_get(
                $this->get_api_base() . '/collection/' . $collection_id . '/items?perpage=' . $per_page . '&paged=' . $current_page,
                array('timeout' => 30)
            );

            if (is_wp_error($response)) {
                $this->log_error('Erro ao obter itens: ' . $response->get_error_message());
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                return new WP_Error(
                    'tainacan_api_error',
                    sprintf(__('Erro ao obter itens (código %d)', 'oraculo-tainacan'), $status_code)
                );
            }

            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (!is_array($response_body) || !isset($response_body['items'])) {
                return new WP_Error(
                    'tainacan_api_error',
                    __('Formato de resposta inválido para itens', 'oraculo-tainacan')
                );
            }

            $page_items = $response_body['items'];
            
            if (empty($page_items)) {
                $has_more = false;
            } else {
                foreach ($page_items as $item) {
                    $metadata_values = array();
                    
                    if (isset($item['metadata']) && is_array($item['metadata'])) {
                        foreach ($item['metadata'] as $meta) {
                            if (isset($meta['name']) && isset($meta['value_as_string'])) {
                                $metadata_values[$meta['name']] = $meta['value_as_string'];
                            }
                        }
                    }
                    
                    $items[] = array(
                        'id' => $item['id'] ?? 0,
                        'title' => $item['title'] ?? '',
                        'description' => $item['description'] ?? '',
                        'metadata' => $metadata_values,
                        'url' => $item['url'] ?? ''
                    );
                }
                
                if (count($page_items) < $per_page) {
                    $has_more = false;
                } else {
                    $current_page++;
                }
            }
        }

        return $items;
    }
    
    /**
     * Obtém metadados de uma coleção
     */
    public function get_collection_metadata($collection_id) {
        if (isset($this->metadata_cache[$collection_id])) {
            return $this->metadata_cache[$collection_id];
        }

        $response = wp_remote_get(
            $this->get_api_base() . '/collection/' . $collection_id . '/metadata',
            array('timeout' => 15)
        );

        if (is_wp_error($response)) {
            $this->log_error('Erro ao obter metadados: ' . $response->get_error_message());
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error(
                'tainacan_api_error',
                sprintf(__('Erro ao obter metadados (código %d)', 'oraculo-tainacan'), $status_code)
            );
        }

        $metadata = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!is_array($metadata)) {
            return new WP_Error(
                'tainacan_api_error',
                __('Formato de resposta inválido para metadados', 'oraculo-tainacan')
            );
        }

        $formatted_metadata = array();
        foreach ($metadata as $meta) {
            $formatted_metadata[$meta['id'] ?? 0] = array(
                'id' => $meta['id'] ?? 0,
                'name' => $meta['name'] ?? '',
                'type' => $meta['metadata_type'] ?? '',
                'description' => $meta['description'] ?? ''
            );
        }

        $this->metadata_cache[$collection_id] = $formatted_metadata;
        
        return $formatted_metadata;
    }
    
    /**
     * Formata item para embedding
     */
    public function format_item_for_embedding($item) {
        $text = "TÍTULO: " . ($item['title'] ?? '') . "\n\n";
        
        if (!empty($item['description'])) {
            $text .= "DESCRIÇÃO: " . strip_tags($item['description']) . "\n\n";
        }
        
        if (!empty($item['metadata']) && is_array($item['metadata'])) {
            $text .= "METADADOS:\n";
            foreach ($item['metadata'] as $key => $value) {
                if (!empty($value)) {
                    $text .= $key . ": " . $value . "\n";
                }
            }
        }
        
        $text .= "\nURL: " . ($item['url'] ?? '');
        
        return $text;
    }
    
    /**
     * Extrai título do conteúdo
     */
    public function extract_title_from_content($content) {
        if (preg_match('/TÍTULO:\s*(.*?)(\n|$)/s', $content, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
    
    /**
     * Extrai descrição do conteúdo
     */
    public function extract_description_from_content($content) {
        if (preg_match('/DESCRIÇÃO:\s*(.*?)(\n\n|$)/s', $content, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
    
    /**
     * Extrai metadados do conteúdo
     */
    public function extract_metadata_from_content($content) {
        $metadata = array();
        
        if (preg_match('/METADADOS:\n(.*?)(\n\nURL:|$)/s', $content, $matches)) {
            $meta_block = $matches[1];
            $meta_lines = explode("\n", $meta_block);
            
            foreach ($meta_lines as $line) {
                if (empty(trim($line))) continue;
                
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    if (!empty($key) && !empty($value)) {
                        $metadata[$key] = $value;
                    }
                }
            }
        }
        
        return $metadata;
    }
    
    private function log_error($message) {
        if (WP_DEBUG === true) {
            error_log('[Oráculo Tainacan] [PARSER] ' . $message);
        }
    }
    
    private function log_debug($message) {
        if ($this->debug_mode && WP_DEBUG === true) {
            error_log('[Oráculo Tainacan] [PARSER] [DEBUG] ' . $message);
        }
    }
}