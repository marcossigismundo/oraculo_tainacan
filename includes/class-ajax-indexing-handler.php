<?php
/**
 * Handler AJAX para Indexação Assíncrona
 *
 * @package Oraculo_Tainacan
 */

if (!defined('WPINC')) {
    die;
}

class Oraculo_Tainacan_Ajax_Indexing_Handler {
    
    /**
     * Gerenciador de indexação
     */
    private $indexing_manager;
    
    /**
     * Construtor
     */
    public function __construct() {
        $this->indexing_manager = new Oraculo_Tainacan_Indexing_Manager();
        $this->register_ajax_handlers();
    }
    
    /**
     * Registra handlers AJAX
     */
    private function register_ajax_handlers() {
        add_action('wp_ajax_oraculo_start_indexing', array($this, 'ajax_start_indexing'));
        add_action('wp_ajax_oraculo_process_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_oraculo_get_indexing_status', array($this, 'ajax_get_status'));
        add_action('wp_ajax_oraculo_cancel_indexing', array($this, 'ajax_cancel_indexing'));
        add_action('wp_ajax_oraculo_get_all_statuses', array($this, 'ajax_get_all_statuses'));
    }
    
    /**
     * Inicia indexação via AJAX
     */
    public function ajax_start_indexing() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada', 'oraculo-tainacan')));
        }
        
        $collection_id = isset($_POST['collection_id']) ? intval($_POST['collection_id']) : 0;
        $force_update = isset($_POST['force_update']) ? (bool) $_POST['force_update'] : false;
        
        if (!$collection_id) {
            wp_send_json_error(array('message' => __('ID da coleção inválido', 'oraculo-tainacan')));
        }
        
        // Verifica se já existe indexação em andamento
        $current_state = $this->indexing_manager->get_indexing_state($collection_id);
        if ($current_state && $current_state['status'] === 'processing') {
            wp_send_json_error(array(
                'message' => __('Já existe uma indexação em andamento para esta coleção', 'oraculo-tainacan'),
                'state' => $current_state
            ));
        }
        
        // Inicia indexação
        $result = $this->indexing_manager->start_indexing($collection_id, $force_update);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Processa próximo lote via AJAX
     */
    public function ajax_process_batch() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada', 'oraculo-tainacan')));
        }
        
        $collection_id = isset($_POST['collection_id']) ? intval($_POST['collection_id']) : 0;
        
        if (!$collection_id) {
            wp_send_json_error(array('message' => __('ID da coleção inválido', 'oraculo-tainacan')));
        }
        
        // Processa próximo lote
        $result = $this->indexing_manager->process_next_batch($collection_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Obtém status da indexação via AJAX
     */
    public function ajax_get_status() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada', 'oraculo-tainacan')));
        }
        
        $collection_id = isset($_POST['collection_id']) ? intval($_POST['collection_id']) : 0;
        
        if (!$collection_id) {
            wp_send_json_error(array('message' => __('ID da coleção inválido', 'oraculo-tainacan')));
        }
        
        $state = $this->indexing_manager->get_indexing_state($collection_id);
        
        if ($state) {
            wp_send_json_success(array('state' => $state));
        } else {
            wp_send_json_success(array('state' => null));
        }
    }
    
    /**
     * Cancela indexação via AJAX
     */
    public function ajax_cancel_indexing() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada', 'oraculo-tainacan')));
        }
        
        $collection_id = isset($_POST['collection_id']) ? intval($_POST['collection_id']) : 0;
        
        if (!$collection_id) {
            wp_send_json_error(array('message' => __('ID da coleção inválido', 'oraculo-tainacan')));
        }
        
        $result = $this->indexing_manager->cancel_indexing($collection_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Obtém todos os estados de indexação
     */
    public function ajax_get_all_statuses() {
        check_ajax_referer('oraculo_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permissão negada', 'oraculo-tainacan')));
        }
        
        $states = $this->indexing_manager->get_all_indexing_states();
        
        wp_send_json_success(array('states' => $states));
    }
}