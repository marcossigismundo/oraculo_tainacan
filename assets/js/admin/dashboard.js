/**
 * Oráculo Tainacan - Dashboard (gráfico de buscas)
 *
 * Extraído do inline <script> de templates/admin/dashboard.php.
 * Dados/labels via data-attributes do canvas.
 */

(function() {
	'use strict';

	document.addEventListener('DOMContentLoaded', function() {
		var canvas = document.getElementById('oraculo-searches-chart');
		if (!canvas || typeof Chart === 'undefined') {
			return;
		}

		var searchData = [];
		try {
			searchData = JSON.parse(canvas.getAttribute('data-searches') || '[]');
		} catch (e) {
			return;
		}
		if (!Array.isArray(searchData)) {
			return;
		}

		var label = canvas.getAttribute('data-label') || '';
		var tooltipSuffix = canvas.getAttribute('data-tooltip-suffix') || '';

		var labels = searchData.map(function(item) { return item.date; });
		var counts = searchData.map(function(item) { return item.count; });

		new Chart(canvas.getContext('2d'), {
			type: 'line',
			data: {
				labels: labels,
				datasets: [{
					label: label,
					data: counts,
					borderColor: '#187181',
					backgroundColor: 'rgba(24, 113, 129, 0.1)',
					borderWidth: 2,
					fill: true,
					tension: 0.3,
					pointBackgroundColor: '#187181',
					pointBorderColor: '#fff',
					pointBorderWidth: 2,
					pointRadius: 3,
					pointHoverRadius: 5
				}]
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: {
						display: false
					},
					tooltip: {
						backgroundColor: '#1f2f56',
						titleColor: '#fff',
						bodyColor: '#fff',
						padding: 12,
						displayColors: false,
						callbacks: {
							label: function(context) {
								return context.parsed.y + ' ' + tooltipSuffix;
							}
						}
					}
				},
				scales: {
					x: {
						grid: { display: false },
						ticks: { maxTicksLimit: 10, color: '#6b7280' }
					},
					y: {
						beginAtZero: true,
						grid: { color: 'rgba(0, 0, 0, 0.05)' },
						ticks: { stepSize: 1, color: '#6b7280' }
					}
				},
				interaction: {
					intersect: false,
					mode: 'index'
				}
			}
		});
	});
})();
