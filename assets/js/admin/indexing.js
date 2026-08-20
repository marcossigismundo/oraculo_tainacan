/**
 * Oráculo Tainacan - Indexação (aba indexing)
 *
 * Extraído do inline <script> de templates/admin/indexing.php.
 * Config/i18n via objeto localizado OraculoAdminPage.
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		var cfg = window.OraculoAdminPage || {};
		var s = cfg.strings || {};
		var logContainer = $('#oraculo-indexing-log');

		function escapeHtml(value) {
			if (value === null || value === undefined) {
				return '';
			}
			return String(value).replace(/[&<>"']/g, function(ch) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch];
			});
		}

		function addLog(message, type) {
			logContainer.find('.oraculo-log-empty').remove();
			var time = new Date().toLocaleTimeString();
			logContainer.append('<div class="oraculo-log-entry ' + escapeHtml(type || '') + '">[' + escapeHtml(time) + '] ' + escapeHtml(message) + '</div>');
			if (logContainer.length) {
				logContainer.scrollTop(logContainer[0].scrollHeight);
			}
		}

		// Indexar coleção individual - indexação síncrona
		$(document).on('click', '.oraculo-btn-index', function() {
			var btn = $(this);
			var collectionId = btn.data('collection');
			var row = btn.closest('tr');

			btn.prop('disabled', true).text(s.indexing || '...');
			row.find('.oraculo-status').removeClass().addClass('oraculo-status oraculo-status-processing').text(s.processing || '...');

			addLog(s.startingIndex + ' #' + collectionId + '...', 'info');
			addLog(s.syncWait, 'info');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				timeout: 300000, // 5 minutos de timeout
				data: {
					action: 'oraculo_index_collection',
					nonce: cfg.nonce,
					collection_id: collectionId
				},
				success: function(response) {
					if (response.success) {
						var data = response.data;
						var indexedItems = data.indexed_items || 0;
						var percentage = data.percentage || 100;

						// Atualizar UI
						row.find('.indexed-count').text(indexedItems.toLocaleString ? indexedItems.toLocaleString() : indexedItems);
						row.find('.oraculo-progress-fill').css('width', percentage + '%');
						row.find('.oraculo-progress-text').text(percentage + '%');
						row.find('.oraculo-status').removeClass().addClass('oraculo-status oraculo-status-completed').text(s.completed || 'OK');

						addLog('✅ ' + (data.message || s.indexDone), 'success');

						if (data.failed_items > 0) {
							addLog('⚠️ ' + data.failed_items + ' ' + s.itemsFailed, 'error');
							// Mostrar erros detalhados
							if (data.errors && data.errors.length > 0) {
								data.errors.forEach(function(err) {
									if (typeof err === 'string') {
										addLog('   → ' + err, 'error');
									} else if (err.error) {
										addLog('   → Item #' + (err.item_id || '?') + ': ' + err.error, 'error');
									}
								});
							}
						}

						btn.prop('disabled', false).text(s.reindex || 'Reindex');

						// Mostrar botão de limpar se não existir
						if (row.find('.oraculo-btn-clear').length === 0 && indexedItems > 0) {
							btn.after('<button type="button" class="button oraculo-btn-clear" data-collection="' + escapeHtml(collectionId) + '">' + escapeHtml(s.clear || 'Clear') + '</button>');
						}
					} else {
						var errorMsg = response.data ? response.data.message : s.unknownError;
						if (response.data && response.data.file) {
							errorMsg += ' (' + response.data.file + ':' + response.data.line + ')';
						}
						addLog('❌ ' + s.error + ': ' + errorMsg, 'error');
						row.find('.oraculo-status').removeClass().addClass('oraculo-status oraculo-status-error').text(s.error || 'Error');
						btn.prop('disabled', false).text(s.index || 'Index');
					}
				},
				error: function(xhr, status, error) {
					addLog('❌ ' + s.connError + ': ' + error + ' (Status: ' + status + ')', 'error');
					row.find('.oraculo-status').removeClass().addClass('oraculo-status oraculo-status-error').text(s.error || 'Error');
					btn.prop('disabled', false).text(s.index || 'Index');
				}
			});
		});

		// Limpar vetores
		$(document).on('click', '.oraculo-btn-clear', function() {
			if (!window.confirm(s.confirmClearCollection)) {
				return;
			}

			var btn = $(this);
			var collectionId = btn.data('collection');
			var row = btn.closest('tr');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'oraculo_clear_vectors',
					nonce: cfg.nonce,
					collection_id: collectionId
				},
				success: function(response) {
					if (response.success) {
						addLog(s.vectorsRemoved + ' (#' + collectionId + ')', 'success');
						row.find('.indexed-count').text('0');
						row.find('.oraculo-progress-fill').css('width', '0%');
						row.find('.oraculo-progress-text').text('0%');
						row.find('.oraculo-status').removeClass().addClass('oraculo-status oraculo-status-idle').text(s.notIndexed || '-');
						btn.remove();
					} else {
						addLog(s.error + ': ' + response.data.message, 'error');
					}
				}
			});
		});

		// Indexar todas
		$('#oraculo-index-all').on('click', function() {
			if (!window.confirm(s.confirmIndexAll)) {
				return;
			}

			addLog(s.indexingAll, 'info');
			$('.oraculo-btn-index:not(:disabled)').each(function(index) {
				var btn = $(this);
				setTimeout(function() {
					btn.trigger('click');
				}, index * 2000);
			});
		});

		// Limpar todos
		$('#oraculo-clear-all').on('click', function() {
			if (!window.confirm(s.confirmClearAll)) {
				return;
			}

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'oraculo_clear_all_vectors',
					nonce: cfg.nonce
				},
				success: function(response) {
					if (response.success) {
						addLog(s.allVectorsRemoved, 'success');
						window.location.reload();
					} else {
						addLog(s.error + ': ' + response.data.message, 'error');
					}
				}
			});
		});

		// Otimizar banco
		$('#oraculo-optimize-db').on('click', function() {
			var btn = $(this);
			btn.prop('disabled', true).text(s.optimizing || '...');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'oraculo_optimize_db',
					nonce: cfg.nonce
				},
				success: function(response) {
					btn.prop('disabled', false).text(s.optimizeDb || 'Optimize');
					if (response.success) {
						addLog(s.dbOptimized, 'success');
					} else {
						addLog(s.error + ': ' + response.data.message, 'error');
					}
				}
			});
		});

		// Processar fila de indexação automática agora
		$('#oraculo-process-queue').on('click', function() {
			var btn = $(this);

			btn.prop('disabled', true);
			addLog(s.queueProcessing || 'Processando fila...', 'info');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				timeout: 300000,
				data: {
					action: 'oraculo_process_queue',
					nonce: cfg.nonce
				},
				success: function(response) {
					if (response.success) {
						var d = response.data || {};
						$('#oraculo-queue-count').text(d.remaining || 0);
						addLog('✅ ' + (s.queueDone || 'Fila processada') + ': ' +
							(d.indexed || 0) + ' ' + (s.queueIndexed || 'indexado(s)') + ', ' +
							(d.failed || 0) + ' ' + (s.queueFailed || 'falha(s)') + ', ' +
							(d.remaining || 0) + ' ' + (s.queueRemaining || 'restante(s)'), 'success');
						if (typeof d.clip_sent !== 'undefined') {
							addLog('CLIP: ' + d.clip_sent + ' ' + (s.clipSent || 'enviado(s)') + ', ' +
								d.clip_failed + ' ' + (s.queueFailed || 'falha(s)'), d.clip_failed > 0 ? 'error' : 'info');
						}
						(d.errors || []).forEach(function(err) {
							addLog('   → ' + (typeof err === 'string' ? err : JSON.stringify(err)), 'error');
						});
					} else {
						addLog('⚠️ ' + ((response.data || {}).message || s.saveError), 'error');
					}
				},
				error: function() {
					addLog(s.saveError || 'Erro', 'error');
				},
				complete: function() {
					btn.prop('disabled', false);
				}
			});
		});

		// Salvar configurações
		$('#oraculo-indexing-settings').on('submit', function(e) {
			e.preventDefault();

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'oraculo_save_indexing_settings',
					nonce: cfg.nonce,
					settings: $(this).serialize()
				},
				success: function(response) {
					if (response.success) {
						addLog(s.settingsSaved, 'success');
					} else {
						addLog(s.saveError + ': ' + response.data.message, 'error');
					}
				}
			});
		});
	});
})(jQuery);
