/**
 * Gerenciador de Indexação JavaScript
 * 
 * @package Oraculo_Tainacan
 */

(function($) {
    'use strict';

    window.OraculoIndexingManager = {
        
        // Estados das indexações ativas
        activeIndexing: {},
        
        // Intervalo de atualização (ms)
        updateInterval: 1000,
        
        // Timers ativos
        timers: {},
        
        /**
         * Inicia indexação de uma coleção
         */
        startIndexing: function(collectionId, collectionName, forceUpdate = false) {
            const self = this;
            
            // Desabilita botões da coleção
            self.disableCollectionButtons(collectionId);
            
            // Mostra indicador de progresso
            self.showProgressIndicator(collectionId, collectionName);
            
            // Requisição AJAX para iniciar
            $.ajax({
                url: oraculo_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'oraculo_start_indexing',
                    collection_id: collectionId,
                    force_update: forceUpdate,
                    nonce: oraculo_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.activeIndexing[collectionId] = response.data.state;
                        self.updateProgressDisplay(collectionId, response.data.state);
                        
                        // Inicia processamento do primeiro lote
                        self.processNextBatch(collectionId);
                    } else {
                        self.showError(collectionId, response.data.message);
                        self.enableCollectionButtons(collectionId);
                    }
                },
                error: function(xhr, status, error) {
                    self.showError(collectionId, 'Erro de comunicação: ' + error);
                    self.enableCollectionButtons(collectionId);
                }
            });
        },
        
        /**
         * Processa próximo lote
         */
        processNextBatch: function(collectionId) {
            const self = this;
            
            $.ajax({
                url: oraculo_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'oraculo_process_batch',
                    collection_id: collectionId,
                    nonce: oraculo_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        
                        // Atualiza estado local
                        self.activeIndexing[collectionId] = data.state;
                        
                        // Atualiza display
                        self.updateProgressDisplay(collectionId, data.state);
                        
                        if (data.completed) {
                            // Indexação concluída
                            self.onIndexingComplete(collectionId, data.state);
                        } else {
                            // Continua com próximo lote após pequena pausa
                            setTimeout(function() {
                                self.processNextBatch(collectionId);
                            }, 500);
                        }
                    } else {
                        self.showError(collectionId, response.data.message);
                        self.onIndexingError(collectionId);
                    }
                },
                error: function(xhr, status, error) {
                    // Em caso de erro de rede, tenta novamente após 5 segundos
                    console.error('Erro no lote:', error);
                    setTimeout(function() {
                        self.processNextBatch(collectionId);
                    }, 5000);
                }
            });
        },
        
        /**
         * Mostra indicador de progresso
         */
        showProgressIndicator: function(collectionId, collectionName) {
            const row = $(`tr[data-collection-id="${collectionId}"]`);
            
            // Remove botões e adiciona área de progresso
            const actionsCell = row.find('.actions-cell');
            actionsCell.html(`
                <div class="oraculo-indexing-progress" data-collection-id="${collectionId}">
                    <div class="progress-header">
                        <span class="status-text">Preparando indexação...</span>
                        <button class="button button-small cancel-indexing" data-collection-id="${collectionId}">
                            Cancelar
                        </button>
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar" style="width: 0%"></div>
                    </div>
                    <div class="progress-details">
                        <span class="items-count">0 / 0 itens</span>
                        <span class="percentage">0%</span>
                    </div>
                    <div class="progress-stats" style="display: none;">
                        <span class="indexed">✓ <span class="count">0</span></span>
                        <span class="skipped">↷ <span class="count">0</span></span>
                        <span class="failed">✗ <span class="count">0</span></span>
                    </div>
                </div>
            `);
            
            // Handler para cancelamento
            actionsCell.find('.cancel-indexing').on('click', function() {
                if (confirm('Tem certeza que deseja cancelar a indexação?')) {
                    this.cancelIndexing(collectionId);
                }
            }.bind(this));
        },
        
        /**
         * Atualiza display de progresso
         */
        updateProgressDisplay: function(collectionId, state) {
            const progress = $(`.oraculo-indexing-progress[data-collection-id="${collectionId}"]`);
            
            if (!progress.length) return;
            
            const percentage = state.total_items > 0 
                ? Math.round((state.processed / state.total_items) * 100) 
                : 0;
            
            // Atualiza barra de progresso
            progress.find('.progress-bar').css('width', percentage + '%');
            
            // Atualiza textos
            progress.find('.status-text').text(
                state.status === 'processing' 
                    ? `Indexando ${state.collection_name}...`
                    : state.status === 'completed'
                    ? 'Indexação concluída!'
                    : 'Status: ' + state.status
            );
            
            progress.find('.items-count').text(`${state.processed} / ${state.total_items} itens`);
            progress.find('.percentage').text(percentage + '%');
            
            // Mostra estatísticas se houver
            if (state.indexed > 0 || state.skipped > 0 || state.failed > 0) {
                progress.find('.progress-stats').show();
                progress.find('.indexed .count').text(state.indexed);
                progress.find('.skipped .count').text(state.skipped);
                progress.find('.failed .count').text(state.failed);
            }
            
            // Adiciona classe de status
            progress.removeClass('status-processing status-completed status-error')
                   .addClass('status-' + state.status);
            
            // Atualiza contador na tabela
            const row = $(`tr[data-collection-id="${collectionId}"]`);
            row.find('.indexed-count').text(state.indexed + ' (' + percentage + '%)');
        },
        
        /**
         * Callback quando indexação completa
         */
        onIndexingComplete: function(collectionId, state) {
            const self = this;
            
            // Remove do estado ativo
            delete self.activeIndexing[collectionId];
            
            // Mostra mensagem de sucesso
            const progress = $(`.oraculo-indexing-progress[data-collection-id="${collectionId}"]`);
            progress.addClass('completed');
            
            // Restaura botões após 3 segundos
            setTimeout(function() {
                self.restoreCollectionButtons(collectionId);
                
                // Atualiza contadores na tabela
                const row = $(`tr[data-collection-id="${collectionId}"]`);
                row.find('.indexed-count').text(state.indexed + ' (100%)');
            }, 3000);
            
            // Mostra notificação
            self.showNotification('success', `Indexação de "${state.collection_name}" concluída! ${state.indexed} itens indexados.`);
        },
        
        /**
         * Callback quando ocorre erro na indexação
         */
        onIndexingError: function(collectionId) {
            const self = this;
            
            // Remove do estado ativo
            delete self.activeIndexing[collectionId];
            
            // Restaura botões
            self.restoreCollectionButtons(collectionId);
        },
        
        /**
         * Cancela indexação
         */
        cancelIndexing: function(collectionId) {
            const self = this;
            
            $.ajax({
                url: oraculo_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'oraculo_cancel_indexing',
                    collection_id: collectionId,
                    nonce: oraculo_admin.nonce
                },
                success: function(response) {
                    delete self.activeIndexing[collectionId];
                    self.restoreCollectionButtons(collectionId);
                    self.showNotification('info', 'Indexação cancelada');
                }
            });
        },
        
        /**
         * Desabilita botões da coleção
         */
        disableCollectionButtons: function(collectionId) {
            const row = $(`tr[data-collection-id="${collectionId}"]`);
            row.find('.index-collection, .index-collection-force').prop('disabled', true);
        },
        
        /**
         * Habilita botões da coleção
         */
        enableCollectionButtons: function(collectionId) {
            const row = $(`tr[data-collection-id="${collectionId}"]`);
            row.find('.index-collection, .index-collection-force').prop('disabled', false);
        },
        
        /**
         * Restaura botões originais
         */
        restoreCollectionButtons: function(collectionId) {
            const row = $(`tr[data-collection-id="${collectionId}"]`);
            const actionsCell = row.find('.actions-cell');
            
            actionsCell.html(`
                <button type="button" 
                        class="button index-collection" 
                        data-collection-id="${collectionId}">
                    Index
                </button>
                <button type="button" 
                        class="button index-collection-force" 
                        data-collection-id="${collectionId}">
                    Force Reindex
                </button>
            `);
            
            // Re-anexa handlers
            this.attachButtonHandlers();
        },
        
        /**
         * Mostra erro
         */
        showError: function(collectionId, message) {
            const progress = $(`.oraculo-indexing-progress[data-collection-id="${collectionId}"]`);
            
            if (progress.length) {
                progress.find('.status-text').text('Erro: ' + message).addClass('error');
            } else {
                this.showNotification('error', message);
            }
        },
        
        /**
         * Mostra notificação
         */
        showNotification: function(type, message) {
            const notification = $(`
                <div class="notice notice-${type} is-dismissible oraculo-notification">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss"></button>
                </div>
            `);
            
            $('.wrap h1').after(notification);
            
            // Auto-remove após 5 segundos
            setTimeout(function() {
                notification.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
            
            // Handler para dismiss manual
            notification.find('.notice-dismiss').on('click', function() {
                notification.fadeOut(function() {
                    $(this).remove();
                });
            });
        },
        
        /**
         * Anexa handlers aos botões
         */
        attachButtonHandlers: function() {
            const self = this;
            
            // Handler para indexação normal
            $('.index-collection').off('click').on('click', function() {
                const collectionId = $(this).data('collection-id');
                const collectionName = $(this).closest('tr').find('td:nth-child(2) strong').text();
                self.startIndexing(collectionId, collectionName, false);
            });
            
            // Handler para force reindex
            $('.index-collection-force').off('click').on('click', function() {
                const collectionId = $(this).data('collection-id');
                const collectionName = $(this).closest('tr').find('td:nth-child(2) strong').text();
                
                if (confirm(`Tem certeza que deseja reindexar TODOS os itens de "${collectionName}"? Isso substituirá todos os índices existentes.`)) {
                    self.startIndexing(collectionId, collectionName, true);
                }
            });
            
            // Handler para indexar todas selecionadas
            $('#index-all-selected').off('click').on('click', function() {
                const selected = $('input[name="oraculo_tainacan_collections[]"]:checked');
                
                if (selected.length === 0) {
                    alert('Selecione pelo menos uma coleção para indexar.');
                    return;
                }
                
                if (confirm(`Indexar ${selected.length} coleção(ões) selecionada(s)?`)) {
                    selected.each(function() {
                        const collectionId = $(this).val();
                        const collectionName = $(this).closest('tr').find('td:nth-child(2) strong').text();
                        
                        // Adiciona pequeno delay entre cada início
                        setTimeout(function() {
                            self.startIndexing(collectionId, collectionName, false);
                        }, 500 * $(this).index());
                    });
                }
            });
        },
        
        /**
         * Verifica indexações em andamento ao carregar a página
         */
        checkActiveIndexing: function() {
            const self = this;
            
            $.ajax({
                url: oraculo_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'oraculo_get_all_statuses',
                    nonce: oraculo_admin.nonce
                },
                success: function(response) {
                    if (response.success && response.data.states) {
                        Object.keys(response.data.states).forEach(function(collectionId) {
                            const state = response.data.states[collectionId];
                            
                            if (state.status === 'processing') {
                                self.activeIndexing[collectionId] = state;
                                self.showProgressIndicator(collectionId, state.collection_name);
                                self.updateProgressDisplay(collectionId, state);
                                
                                // Retoma processamento
                                self.processNextBatch(collectionId);
                            }
                        });
                    }
                }
            });
        },
        
        /**
         * Inicialização
         */
        init: function() {
            const self = this;
            
            $(document).ready(function() {
                // Anexa handlers
                self.attachButtonHandlers();
                
                // Verifica indexações ativas
                self.checkActiveIndexing();
                
                // Handler para "Index All Selected"
                $('#index-all-selected').on('click', function() {
                    const selected = $('input[name="oraculo_tainacan_collections[]"]:checked');
                    
                    if (selected.length === 0) {
                        alert('Selecione pelo menos uma coleção para indexar.');
                        return;
                    }
                    
                    if (confirm(`Indexar ${selected.length} coleção(ões) selecionada(s)?`)) {
                        selected.each(function(index) {
                            const checkbox = $(this);
                            const row = checkbox.closest('tr');
                            const collectionId = checkbox.val();
                            const collectionName = row.find('td:nth-child(2) strong').text();
                            
                            // Adiciona delay progressivo para não sobrecarregar
                            setTimeout(function() {
                                self.startIndexing(collectionId, collectionName, false);
                            }, index * 1000);
                        });
                    }
                });
            });
        }
    };

    // Inicializa
    OraculoIndexingManager.init();

})(jQuery);