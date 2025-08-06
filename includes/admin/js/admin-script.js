/**
 * JavaScript para as páginas administrativas do Oráculo Tainacan
 * Versão melhorada com tratamento de erros e retry logic
 */
(function($) {
    'use strict';

    // Gerenciador de Indexação
    const IndexingManager = {
        activeIndexing: {},
        maxRetries: 3,
        retryDelay: 2000,
        
        /**
         * Inicia indexação de uma coleção
         */
        startIndexing: function(collectionId, collectionName, forceUpdate = false, totalItems = 100) {
            const self = this;
            
            // Previne múltiplas indexações simultâneas
            if (self.activeIndexing[collectionId]) {
                console.log('Indexação já em andamento para coleção', collectionId);
                return;
            }
            
            self.activeIndexing[collectionId] = {
                status: 'starting',
                processed: 0,
                total: totalItems,
                errors: 0,
                retries: 0
            };
            
            // UI feedback
            self.updateUI(collectionId, 'starting');
            
            // Inicia processamento em lotes
            self.processBatch(collectionId, collectionName, forceUpdate, 0, 0);
        },
        
        /**
         * Processa um lote de itens
         */
        processBatch: function(collectionId, collectionName, forceUpdate, offset, totalProcessed, retryCount = 0) {
            const self = this;
            const batchSize = this.calculateBatchSize(self.activeIndexing[collectionId].total);
            
            $.ajax({
                url: oraculo_admin.ajax_url,
                type: 'POST',
                timeout: 30000, // 30 segundos timeout
                data: {
                    action: 'oraculo_index_collection_batch',
                    collection_id: collectionId,
                    batch_size: batchSize,
                    offset: offset,
                    force_update: forceUpdate ? 1 : 0,
                    nonce: oraculo_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        totalProcessed += data.total || 0;
                        
                        // Atualiza estado
                        self.activeIndexing[collectionId].processed = totalProcessed;
                        self.activeIndexing[collectionId].status = 'processing';
                        
                        // Atualiza UI
                        self.updateProgress(collectionId, totalProcessed, self.activeIndexing[collectionId].total);
                        
                        if (data.has_more) {
                            // Continua com próximo lote
                            setTimeout(() => {
                                self.processBatch(collectionId, collectionName, forceUpdate, data.next_offset, totalProcessed);
                            }, 100);
                        } else {
                            // Indexação concluída
                            self.onIndexingComplete(collectionId, totalProcessed);
                        }
                    } else {
                        self.handleError(collectionId, response.data?.message || 'Erro desconhecido', () => {
                            self.processBatch(collectionId, collectionName, forceUpdate, offset, totalProcessed, retryCount + 1);
                        });
                    }
                },
                error: function(xhr, status, error) {
                    // Trata diferentes tipos de erro
                    let errorMessage = 'Erro de comunicação';
                    
                    if (status === 'timeout') {
                        errorMessage = 'Tempo limite excedido. A coleção pode ser muito grande.';
                    } else if (xhr.status === 500) {
                        errorMessage = 'Erro interno do servidor. Verifique os logs.';
                    } else if (xhr.status === 0) {
                        errorMessage = 'Conexão perdida. Verifique sua internet.';
                    }
                    
                    // Tenta novamente se não excedeu o limite
                    if (retryCount < self.maxRetries) {
                        console.log(`Tentativa ${retryCount + 1} de ${self.maxRetries} para coleção ${collectionId}`);
                        
                        self.updateStatus(collectionId, `Reconectando... (tentativa ${retryCount + 1})`);
                        
                        setTimeout(() => {
                            self.processBatch(collectionId, collectionName, forceUpdate, offset, totalProcessed, retryCount + 1);
                        }, self.retryDelay * (retryCount + 1));
                    } else {
                        self.handleError(collectionId, errorMessage);
                    }
                }
            });
        },
        
        /**
         * Calcula tamanho ideal do lote baseado no total
         */
        calculateBatchSize: function(total) {
            if (total < 50) return 5;
            if (total < 200) return 10;
            if (total < 1000) return 20;
            return 30;
        },
        
        /**
         * Atualiza UI durante processamento
         */
        updateProgress: function(collectionId, processed, total) {
            const percentage = Math.min(Math.round((processed / total) * 100), 100);
            const progressDiv = $('#progress-' + collectionId);
            
            progressDiv.find('.progress-fill').css('width', percentage + '%');
            progressDiv.find('.progress-text').text(percentage + '%');
            progressDiv.find('.progress-status').text(
                `Processados: ${processed} / ${total} itens`
            );
            
            // Atualiza card
            const card = $(`.oraculo-collection-card[data-collection-id="${collectionId}"]`);
            card.find('.oraculo-stat-value').eq(1).text(processed);
            card.find('.oraculo-progress-fill').css('width', percentage + '%');
        },
        
        /**
         * Atualiza status
         */
        updateStatus: function(collectionId, message) {
            $('#progress-' + collectionId).find('.progress-status').text(message);
        },
        
        /**
         * Atualiza UI
         */
        updateUI: function(collectionId, status) {
            const card = $(`.oraculo-collection-card[data-collection-id="${collectionId}"]`);
            const progressDiv = $('#progress-' + collectionId);
            
            if (status === 'starting') {
                card.find('.index-btn, .index-force-btn').prop('disabled', true);
                progressDiv.addClass('active');
                progressDiv.find('.progress-status').text('Iniciando indexação...');
            }
        },
        
        /**
         * Callback quando indexação completa
         */
        onIndexingComplete: function(collectionId, totalProcessed) {
            const self = this;
            
            delete self.activeIndexing[collectionId];
            
            const progressDiv = $('#progress-' + collectionId);
            progressDiv.find('.progress-status').text('✓ Indexação concluída com sucesso!');
            progressDiv.find('.progress-fill').css('width', '100%');
            progressDiv.find('.progress-text').text('100%');
            
            // Recarrega página após 2 segundos
            setTimeout(() => {
                location.reload();
            }, 2000);
        },
        
        /**
         * Trata erros
         */
        handleError: function(collectionId, errorMessage, retryCallback) {
            const self = this;
            const progressDiv = $('#progress-' + collectionId);
            const card = $(`.oraculo-collection-card[data-collection-id="${collectionId}"]`);
            
            console.error('Erro na indexação:', errorMessage);
            
            if (retryCallback) {
                // Mostra opção de retry
                progressDiv.find('.progress-status').html(
                    `<span style="color: red;">Erro: ${errorMessage}</span>
                     <button class="button button-small retry-indexing" style="margin-left: 10px;">Tentar Novamente</button>`
                );
                
                progressDiv.find('.retry-indexing').on('click', function() {
                    $(this).remove();
                    retryCallback();
                });
            } else {
                // Erro final
                progressDiv.find('.progress-status').html(`<span style="color: red;">✗ Erro: ${errorMessage}</span>`);
                
                // Reabilita botões após 3 segundos
                setTimeout(() => {
                    card.find('.index-btn, .index-force-btn').prop('disabled', false);
                    progressDiv.removeClass('active');
                    delete self.activeIndexing[collectionId];
                }, 3000);
            }
        }
    };

    $(document).ready(function() {
        // Anexa handlers aos botões de indexação
        $(document).on('click', '.index-btn, .index-force-btn', function() {
            const btn = $(this);
            const collectionId = btn.data('collection-id');
            const collectionName = btn.data('collection-name');
            const collectionSize = parseInt(btn.data('collection-size')) || 100;
            const forceUpdate = btn.hasClass('index-force-btn');
            
            if (forceUpdate && !confirm(`Reindexar "${collectionName}"? Isso substituirá todos os índices existentes.`)) {
                return;
            }
            
            IndexingManager.startIndexing(collectionId, collectionName, forceUpdate, collectionSize);
        });
        
        // Seleção de todas as coleções
        $('#select-all-collections, #select-all').on('change', function() {
            $('input[name="oraculo_tainacan_collections[]"]').prop('checked', $(this).prop('checked'));
        });
        
        // Teste de busca na página de debug
        $('#run-test-search').on('click', function() {
            const query = $('#test-query').val();
            
            if (!query) {
                alert('Por favor, digite uma consulta de teste.');
                return;
            }
            
            const collections = [];
            $('input[name="test_collections[]"]:checked').each(function() {
                collections.push($(this).val());
            });
            
            const button = $(this);
            button.prop('disabled', true).html('Executando...');
            
            // Limpa resultados anteriores
            $('#test-results-container').hide();
            $('.oraculo-test-steps').empty();
            $('.oraculo-test-response').empty();
            
            $.ajax({
                url: oraculo_admin.ajax_url,
                type: 'POST',
                timeout: 60000, // 60 segundos para teste
                data: {
                    action: 'oraculo_test_search',
                    query: query,
                    collections: collections,
                    nonce: oraculo_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        renderTestResults(response.data.debug_info);
                        $('#test-results-container').show();
                    } else {
                        alert('Erro: ' + (response.data?.message || 'Erro desconhecido'));
                    }
                },
                error: function(xhr, status, error) {
                    let errorMsg = 'Erro de comunicação';
                    if (status === 'timeout') {
                        errorMsg = 'Tempo limite excedido. A busca pode estar demorando muito.';
                    }
                    alert(errorMsg);
                },
                complete: function() {
                    button.prop('disabled', false).html('Executar Busca de Teste');
                }
            });
        });
        
        // Função para renderizar resultados do teste
        function renderTestResults(debug_info) {
            $('.oraculo-test-query').text(debug_info.query);
            $('.oraculo-test-timestamp').text(debug_info.timestamp);
            
            if (debug_info.final_result) {
                $('.oraculo-test-status').text('Concluído com sucesso').addClass('status-success');
                $('.oraculo-test-time').text(debug_info.final_result.total_time + ' segundos');
                $('.oraculo-test-items-count').text(debug_info.final_result.items.length);
                $('.oraculo-test-response').html(debug_info.final_result.response.replace(/\n/g, '<br>'));
            } else {
                $('.oraculo-test-status').text('Falhou').addClass('status-error');
                $('.oraculo-test-time').text('N/A');
                $('.oraculo-test-items-count').text('0');
            }
            
            // Renderiza etapas do processo
            if (debug_info.steps && debug_info.steps.length > 0) {
                const stepsContainer = $('.oraculo-test-steps');
                
                debug_info.steps.forEach((step, index) => {
                    const stepEl = $('<div class="oraculo-test-step"></div>');
                    const statusClass = step.success !== false ? 'status-success' : 'status-error';
                    const statusIcon = step.success !== false ? '✓' : '✗';
                    
                    stepEl.html(`
                        <div class="oraculo-step-header">
                            <div class="oraculo-step-number">${index + 1}</div>
                            <div class="oraculo-step-title">${step.step}</div>
                            <div class="oraculo-step-status ${statusClass}">${statusIcon}</div>
                        </div>
                        <div class="oraculo-step-details">
                            ${step.time_seconds ? `<div><strong>Tempo:</strong> ${step.time_seconds}s</div>` : ''}
                            ${step.message ? `<div><strong>Mensagem:</strong> ${step.message}</div>` : ''}
                            ${step.error ? `<div style="color: red;"><strong>Erro:</strong> ${step.error}</div>` : ''}
                        </div>
                    `);
                    
                    stepsContainer.append(stepEl);
                });
            }
        }
        
        // Ver detalhes (Dashboard e Training)
        $('.view-details, .view-training-details').on('click', function() {
            const id = $(this).data('id');
            const isTraining = $(this).hasClass('view-training-details');
            
            // Aqui você implementaria a lógica para mostrar detalhes
            // Por enquanto, apenas um placeholder
            console.log('Ver detalhes do item:', id, isTraining ? '(training)' : '(history)');
        });
        
        // Exportar dados
        $('#export-json, #export-csv, #export-training-json, #export-training-csv').on('click', function() {
            const button = $(this);
            const format = button.data('format') || (button.attr('id').includes('json') ? 'json' : 'csv');
            const isTraining = button.attr('id').includes('training');
            
            $.ajax({
                url: oraculo_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'oraculo_export_data',
                    export_type: isTraining ? 'training_data' : 'search_history',
                    format: format,
                    filters: {
                        start_date: $('#start_date').val(),
                        end_date: $('#end_date').val(),
                        search: $('#search').val(),
                        collection: $('#collection').val(),
                        feedback: isTraining ? 1 : null
                    },
                    nonce: oraculo_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        downloadData(response.data.data, response.data.filename, 
                            format === 'json' ? 'text/json' : 'text/csv');
                    } else {
                        alert('Erro ao exportar dados.');
                    }
                }
            });
        });
        
        // Função auxiliar para download
        function downloadData(content, filename, type) {
            const element = document.createElement('a');
            element.setAttribute('href', 'data:' + type + ';charset=utf-8,' + encodeURIComponent(content));
            element.setAttribute('download', filename);
            element.style.display = 'none';
            document.body.appendChild(element);
            element.click();
            document.body.removeChild(element);
        }
        
        // Fechar modais
        $('.oraculo-modal-close').on('click', function() {
            $('.oraculo-modal').css('display', 'none');
        });
        
        $(window).on('click', function(event) {
            if ($(event.target).hasClass('oraculo-modal')) {
                $('.oraculo-modal').css('display', 'none');
            }
        });
        
        // Indexação Modal (compatibilidade com código antigo)
        $('.index-collection, .index-collection-force').on('click', function() {
            var button = $(this);
            var collection_id = button.data('collection-id');
            var collection_name = button.data('collection-name');
            var force_update = button.hasClass('index-collection-force');
            
            // Usa o novo gerenciador
            IndexingManager.startIndexing(collection_id, collection_name, force_update, 100);
        });
    });

})(jQuery);