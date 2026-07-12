/**
 * Oráculo Tainacan - Analytics (gráfico por coleção + export)
 *
 * Extraído do inline <script> de templates/admin/analytics.php.
 * Dados via data-attributes; export via REST com nonce.
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		var cfg = window.OraculoAdminPage || {};

		// Collection Chart
		var ctx2 = document.getElementById('collectionChart');
		if (ctx2 && typeof Chart !== 'undefined') {
			var collectionData = [];
			try {
				collectionData = JSON.parse(ctx2.getAttribute('data-collections') || '[]');
			} catch (e) {
				collectionData = [];
			}

			if (Array.isArray(collectionData) && collectionData.length > 0) {
				new Chart(ctx2.getContext('2d'), {
					type: 'doughnut',
					data: {
						labels: collectionData.map(function(d) { return d.collection_name; }),
						datasets: [{
							data: collectionData.map(function(d) { return d.searches; }),
							backgroundColor: [
								'#2271b1', '#46b450', '#ffb900', '#dc3232',
								'#00a0d2', '#9b59b6', '#3498db', '#e74c3c'
							]
						}]
					},
					options: {
						responsive: true,
						animation: false,
						plugins: {
							legend: {
								position: 'bottom',
								labels: { boxWidth: 12 }
							}
						}
					}
				});
			}
		}

		// Export CSV via REST (download com nonce no header)
		$('#oraculo-export-analytics').on('click', function() {
			var btn = $(this);
			var period = btn.data('period') || 'month';

			btn.prop('disabled', true);

			fetch(cfg.restUrl + 'analytics/export?period=' + encodeURIComponent(period) + '&format=csv', {
				headers: { 'X-WP-Nonce': cfg.restNonce }
			})
			.then(function(response) {
				if (!response.ok) { throw new Error('HTTP ' + response.status); }
				return response.blob();
			})
			.then(function(blob) {
				var url = URL.createObjectURL(blob);
				var a = document.createElement('a');
				a.href = url;
				a.download = 'oraculo-analytics.csv';
				document.body.appendChild(a);
				a.click();
				a.remove();
				URL.revokeObjectURL(url);
			})
			.catch(function() {
				window.alert((cfg.strings && cfg.strings.error) || 'Erro');
			})
			.then(function() {
				btn.prop('disabled', false);
			});
		});
	});
})(jQuery);
