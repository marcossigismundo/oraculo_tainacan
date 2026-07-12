/**
 * Oráculo Tainacan - Debug (aba debug)
 *
 * Extraído do inline <script> de templates/admin/debug.php.
 * Config/i18n via objeto localizado OraculoAdminPage.
 *
 * TODO(oraculo): as ações oraculo_test_provider, oraculo_test_search,
 * oraculo_repair_tables, oraculo_clear_errors e oraculo_reset_plugin
 * não estão registradas no backend (comportamento herdado do inline
 * original) — implementar ou remover os botões correspondentes.
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		var cfg = window.OraculoAdminPage || {};
		var s = cfg.strings || {};

		function escapeHtml(value) {
			if (value === null || value === undefined) {
				return '';
			}
			return String(value).replace(/[&<>"']/g, function(ch) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch];
			});
		}

		// Testar provedor
		$('.oraculo-test-provider').on('click', function() {
			var btn = $(this);
			var provider = btn.data('provider');
			var result = btn.siblings('.test-result');

			btn.prop('disabled', true).text(s.testing || '...');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'oraculo_test_provider',
					nonce: cfg.nonce,
					provider: provider
				},
				success: function(response) {
					btn.prop('disabled', false).text(s.test || 'Test');
					if (response.success) {
						result.html('<span class="status-ok">' + escapeHtml(response.data.message) + '</span>');
					} else {
						result.html('<span class="status-error">' + escapeHtml(response.data.message) + '</span>');
					}
				},
				error: function() {
					btn.prop('disabled', false).text(s.test || 'Test');
					result.html('<span class="status-error">' + escapeHtml(s.connError) + '</span>');
				}
			});
		});

		// Teste de busca
		$('#oraculo-test-search').on('submit', function(e) {
			e.preventDefault();

			var query = $('#test-query').val().trim();
			if (!query) return;

			var results = $('#oraculo-test-results');
			results.html('<p>' + escapeHtml(s.processing2) + '</p>').addClass('show');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'oraculo_test_search',
					nonce: cfg.nonce,
					query: query,
					semantic_only: $('#test-semantic-only').is(':checked') ? 1 : 0
				},
				success: function(response) {
					if (response.success) {
						results.html('<pre>' + escapeHtml(JSON.stringify(response.data, null, 2)) + '</pre>');
					} else {
						results.html('<p style="color: red;">' + escapeHtml(response.data.message) + '</p>');
					}
				},
				error: function() {
					results.html('<p style="color: red;">' + escapeHtml(s.connError) + '</p>');
				}
			});
		});

		// Reparar tabelas
		$('#oraculo-repair-tables').on('click', function() {
			var btn = $(this);
			btn.prop('disabled', true);

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'oraculo_repair_tables',
					nonce: cfg.nonce
				},
				success: function(response) {
					btn.prop('disabled', false);
					if (response.success) {
						window.alert(s.tablesRepaired);
						window.location.reload();
					} else {
						window.alert(response.data.message);
					}
				}
			});
		});

		// Limpar cache
		$('#oraculo-clear-cache').on('click', function() {
			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'oraculo_clear_cache',
					nonce: cfg.nonce
				},
				success: function(response) {
					if (response.success) {
						window.alert(s.cacheClearedShort);
					}
				}
			});
		});

		// Limpar erros
		$('#oraculo-clear-errors').on('click', function() {
			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'oraculo_clear_errors',
					nonce: cfg.nonce
				},
				success: function(response) {
					if (response.success) {
						window.location.reload();
					}
				}
			});
		});

		// Otimizar tabelas
		$('#oraculo-optimize-tables').on('click', function() {
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
					btn.prop('disabled', false).text(s.optimizeTables || 'Optimize');
					if (response.success) {
						window.alert(s.tablesOptimized);
					}
				}
			});
		});

		// Resetar plugin
		$('#oraculo-reset-plugin').on('click', function() {
			if (!window.confirm(s.confirmReset1)) {
				return;
			}
			if (!window.confirm(s.confirmReset2)) {
				return;
			}

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				data: {
					action: 'oraculo_reset_plugin',
					nonce: cfg.nonce
				},
				success: function(response) {
					if (response.success) {
						window.alert(s.resetDone);
						window.location.reload();
					}
				}
			});
		});
	});
})(jQuery);
