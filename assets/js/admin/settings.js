/**
 * Oráculo Tainacan - Configurações (aba settings)
 *
 * Extraído do inline <script> de templates/admin/settings.php.
 * Config/i18n via objeto localizado OraculoAdminPage.
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

		// Tabs
		$('.oraculo-tab-btn').on('click', function() {
			var tab = $(this).data('tab');
			$('.oraculo-tab-btn').removeClass('active');
			$(this).addClass('active');
			$('.oraculo-tab-content').removeClass('active');
			$('#tab-' + tab).addClass('active');
		});

		// Provider selection
		$('.oraculo-provider-card input[type="radio"]').on('change', function() {
			var provider = $(this).val();
			$('.oraculo-provider-card').removeClass('selected');
			$(this).closest('.oraculo-provider-card').addClass('selected');
			$('.oraculo-provider-settings').hide();
			$('#provider-settings-' + provider).show();
		});

		// Sliders
		$('#temperature-slider').on('input', function() {
			$('#temperature-value').text($(this).val());
		});
		$('#similarity-slider').on('input', function() {
			$('#similarity-value').text(Math.round($(this).val() * 100) + '%');
		});

		// Add question
		$('#add-question').on('click', function() {
			var row = '<div class="suggested-question-row">' +
						'<input type="text" name="oraculo_tainacan_options[suggested_questions][]" class="regular-text">' +
						'<button type="button" class="button remove-question">✕</button>' +
						'</div>';
			$('#suggested-questions').append(row);
		});

		// Remove question
		$(document).on('click', '.remove-question', function() {
			$(this).closest('.suggested-question-row').remove();
		});

		// Test connection
		$('.oraculo-test-connection').addClass('has-handler').on('click', function(e) {
			e.preventDefault();
			var button = $(this);
			var provider = button.data('provider');
			button.prop('disabled', true).text(s.testing || '...');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'oraculo_test_connection',
					provider: provider,
					nonce: cfg.nonce
				},
				success: function(response) {
					button.prop('disabled', false).text(s.testConnection || 'Test');
					if (response && response.success) {
						window.alert('✅ ' + (response.data && response.data.message ? response.data.message : s.connectionOk));
					} else {
						window.alert('❌ ' + (response && response.data && response.data.message ? response.data.message : s.connectionFailed));
					}
				},
				error: function(xhr, status, error) {
					button.prop('disabled', false).text(s.testConnection || 'Test');
					window.alert('❌ ' + s.connError + ': ' + error);
				}
			});
		});

		// Clear cache
		$('#clear-cache').on('click', function() {
			var button = $(this);
			button.prop('disabled', true).text(s.clearing || '...');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'oraculo_clear_cache',
					nonce: cfg.nonce
				},
				success: function(response) {
					button.prop('disabled', false).text(s.clearCache || 'Clear');
					if (response && response.success) {
						window.alert('✅ ' + s.cacheCleared);
					} else {
						window.alert('❌ ' + (response && response.data && response.data.message ? response.data.message : s.cacheError));
					}
				},
				error: function(xhr, status, error) {
					button.prop('disabled', false).text(s.clearCache || 'Clear');
					window.alert('❌ ' + s.connError + ': ' + error);
				}
			});
		});

		// Clear vectors
		$('#clear-vectors').on('click', function() {
			if (!window.confirm(s.confirmClearVectors)) {
				return;
			}

			var button = $(this);
			button.prop('disabled', true).text(s.clearing || '...');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'oraculo_clear_all_vectors',
					nonce: cfg.nonce
				},
				success: function(response) {
					button.prop('disabled', false).text(s.clearVectors || 'Clear');
					if (response && response.success) {
						window.alert('✅ ' + s.vectorsCleared);
					} else {
						window.alert('❌ ' + (response && response.data && response.data.message ? response.data.message : s.vectorsError));
					}
				},
				error: function(xhr, status, error) {
					button.prop('disabled', false).text(s.clearVectors || 'Clear');
					window.alert('❌ ' + s.connError + ': ' + error);
				}
			});
		});

		// Salvar configurações via AJAX
		$('#oraculo-settings-form').on('submit', function(e) {
			e.preventDefault();

			var $form = $(this);
			var $button = $form.find('input[type="submit"], button[type="submit"]');
			var $notice = $('.oraculo-notice');
			var originalText = $button.val() || $button.text();

			$button.prop('disabled', true).val(s.saving || '...');

			$.ajax({
				url: cfg.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: $form.serialize() + '&action=oraculo_save_settings&nonce=' + encodeURIComponent(cfg.nonce),
				success: function(response) {
					if (response && response.success) {
						$notice.removeClass('notice-error').addClass('notice-success')
							.html('<p>' + escapeHtml(s.saved) + '</p>').show();
					} else {
						var msg = (response && response.data && response.data.message) ? response.data.message : s.saveError;
						$notice.removeClass('notice-success').addClass('notice-error')
							.html('<p>' + escapeHtml(msg) + '</p>').show();
					}
				},
				error: function(xhr, status, error) {
					$notice.removeClass('notice-success').addClass('notice-error')
						.html('<p>' + escapeHtml(s.connError + ': ' + error) + '</p>').show();
				},
				complete: function() {
					$button.prop('disabled', false).val(originalText);
					setTimeout(function() {
						$notice.fadeOut();
					}, 5000);
				}
			});
		});
	});
})(jQuery);
