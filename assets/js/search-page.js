/**
 * Oráculo Tainacan - Widget de Busca (página completa via shortcode)
 *
 * Extraído do inline <script> de templates/search-widget.php.
 * Config/i18n via objeto localizado OraculoSearchPage.
 */

(function() {
	'use strict';

	var cfg = window.OraculoSearchPage || {};
	var i18n = cfg.i18n || {};

	/**
	 * Escapa conteúdo dinâmico antes de injetar em HTML (texto e atributos).
	 */
	function escapeHtml(value) {
		if (value === null || value === undefined) {
			return '';
		}
		return String(value).replace(/[&<>"']/g, function(ch) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch];
		});
	}

	// Formata texto com sintaxe markdown básica (sobre texto já escapado).
	function formatText(text) {
		text = escapeHtml(text);
		// Bold
		text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
		// Italic
		text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
		// Line breaks
		text = text.replace(/\n/g, '<br>');
		// Links — apenas esquemas seguros (http/https, relativo, âncora)
		text = text.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function(match, label, url) {
			if (!/^(https?:\/\/|\/|#)/i.test(url)) {
				return match;
			}
			return '<a href="' + url + '" target="_blank" rel="noopener">' + label + '</a>';
		});
		// Wrap in paragraphs
		text = '<p>' + text.replace(/<br><br>/g, '</p><p>') + '</p>';
		return text;
	}

	function initWidget(widget) {
		var form = widget.querySelector('.oraculo-search-form');
		var input = widget.querySelector('.oraculo-search-input');
		var submitBtn = widget.querySelector('.oraculo-search-button');
		var loading = widget.querySelector('.oraculo-search-loading');
		var results = widget.querySelector('.oraculo-search-results');
		var featuresSection = widget.querySelector('.oraculo-features-section');
		var examplesSection = widget.querySelector('.oraculo-examples-section');
		var tipsSection = widget.querySelector('.oraculo-tips-section');
		var exampleItems = widget.querySelectorAll('.oraculo-example-item');
		var isSearching = false;

		if (!form || !input) {
			return;
		}

		// Função para executar a busca
		function executeSearch() {
			var query = input.value.trim();
			if (!query || isSearching) return;

			isSearching = true;
			submitBtn.disabled = true;

			var collectionSelect = form.querySelector('[name="oraculo_collection"]');
			var collections = collectionSelect ? [collectionSelect.value].filter(Boolean) : [];

			// Show loading, hide other sections
			loading.style.display = 'flex';
			results.style.display = 'none';
			results.innerHTML = '';
			if (featuresSection) featuresSection.style.display = 'none';
			if (examplesSection) examplesSection.style.display = 'none';
			if (tipsSection) tipsSection.style.display = 'none';

			// Scroll to loading
			loading.scrollIntoView({ behavior: 'smooth', block: 'center' });

			var startTime = Date.now();

			fetch(cfg.searchUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.restNonce
				},
				body: JSON.stringify({
					query: query,
					collections: collections
				})
			})
			.then(function(response) { return response.json(); })
			.then(function(data) {
				var responseTime = ((Date.now() - startTime) / 1000).toFixed(1);
				loading.style.display = 'none';
				results.style.display = 'block';
				isSearching = false;
				submitBtn.disabled = false;

				// Os dados podem vir diretamente ou dentro de data.data (estrutura da API REST)
				var searchResult = data.data || data;

				if (searchResult.response) {
					var html = '<div class="oraculo-response">';

					// Response Header
					html += '<div class="oraculo-response-header">';
					html += '<div class="oraculo-response-avatar">';
					html += '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>';
					html += '</div>';
					html += '<div class="oraculo-response-meta">';
					html += '<div class="oraculo-response-label">' + escapeHtml(i18n.assistant) + '</div>';
					html += '<div class="oraculo-response-time">' + escapeHtml(i18n.respondedIn) + ' ' + responseTime + 's</div>';
					html += '</div>';
					html += '</div>';

					// Response Body
					html += '<div class="oraculo-response-body">';
					html += '<div class="oraculo-response-text">' + formatText(searchResult.response) + '</div>';

					// Sources
					if (searchResult.items && searchResult.items.length > 0) {
						html += '<div class="oraculo-sources">';
						html += '<h4><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>' + escapeHtml(i18n.sources) + '</h4>';
						html += '<ul>';
						searchResult.items.forEach(function(item, index) {
							html += '<li>';
							html += '<span class="oraculo-source-number">' + (index + 1) + '</span>';
							html += '<a href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener">';
							html += escapeHtml(item.title);
							html += '</a>';
							html += '<svg class="oraculo-source-arrow" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
							html += '</li>';
						});
						html += '</ul>';
						html += '</div>';
					}

					// Feedback section - guarda search_id para associar feedback ao log
					var searchId = searchResult.search_id || '';
					html += '<div class="oraculo-feedback" data-search-id="' + escapeHtml(searchId) + '">';
					html += '<span class="oraculo-feedback-label">' + escapeHtml(i18n.helpful) + '</span>';
					html += '<div class="oraculo-feedback-buttons">';
					html += '<button type="button" class="oraculo-feedback-btn positive" data-feedback="positive">';
					html += '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>';
					html += escapeHtml(i18n.yes);
					html += '</button>';
					html += '<button type="button" class="oraculo-feedback-btn negative" data-feedback="negative">';
					html += '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/></svg>';
					html += escapeHtml(i18n.no);
					html += '</button>';
					html += '</div>';
					html += '</div>';

					html += '</div>'; // response-body
					html += '</div>'; // response

					results.innerHTML = html;
				} else if (searchResult.message || data.error) {
					var errorMsg = searchResult.message || data.error || i18n.unknownError;
					results.innerHTML = '<div class="oraculo-error">' +
						'<div class="oraculo-error-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>' +
						'<div class="oraculo-error-content"><h4>' + escapeHtml(i18n.cantProcess) + '</h4><p>' + escapeHtml(errorMsg) + '</p></div>' +
						'</div>';
				} else {
					results.innerHTML = '<div class="oraculo-no-results">' +
						'<div class="oraculo-no-results-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg></div>' +
						'<h3 class="oraculo-no-results-title">' + escapeHtml(i18n.noResultsTitle) + '</h3>' +
						'<p class="oraculo-no-results-text">' + escapeHtml(i18n.noResultsText) + '</p>' +
						'</div>';
				}

				// Scroll to results
				results.scrollIntoView({ behavior: 'smooth', block: 'start' });

				// Show tips section after results
				if (tipsSection) tipsSection.style.display = 'block';
			})
			.catch(function() {
				loading.style.display = 'none';
				results.style.display = 'block';
				isSearching = false;
				submitBtn.disabled = false;

				results.innerHTML = '<div class="oraculo-error">' +
					'<div class="oraculo-error-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>' +
					'<div class="oraculo-error-content"><h4>' + escapeHtml(i18n.connectionErrorTitle) + '</h4><p>' + escapeHtml(i18n.connectionErrorBody) + '</p></div>' +
					'</div>';

				// Show sections again on error
				if (featuresSection) featuresSection.style.display = 'block';
				if (examplesSection) examplesSection.style.display = 'block';
				if (tipsSection) tipsSection.style.display = 'block';
			});
		}

		// Delegação de eventos: feedback é injetado dinamicamente nos resultados.
		results.addEventListener('click', function(e) {
			var btn = e.target.closest('.oraculo-feedback-btn');
			if (!btn) return;

			var feedback = btn.getAttribute('data-feedback');
			var feedbackContainer = btn.closest('.oraculo-feedback');
			var searchIdFromContainer = feedbackContainer.getAttribute('data-search-id') || '';

			// Send feedback via REST API
			fetch(cfg.feedbackUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.restNonce
				},
				body: JSON.stringify({
					search_id: searchIdFromContainer,
					feedback: feedback
				})
			});

			// Update UI
			feedbackContainer.innerHTML = '<span class="oraculo-feedback-thanks"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>' + escapeHtml(i18n.thanks) + '</span>';
		});

		// Handle example clicks
		exampleItems.forEach(function(item) {
			item.addEventListener('click', function() {
				input.value = this.getAttribute('data-query');
				input.focus();
				executeSearch();
			});
		});

		// Handle form submission
		form.addEventListener('submit', function(e) {
			e.preventDefault();
			executeSearch();
		});

		// Handle Enter key in input
		input.addEventListener('keydown', function(e) {
			if (e.key === 'Enter' || e.keyCode === 13) {
				e.preventDefault();
				executeSearch();
			}
		});

		// Handle button click explicitly
		submitBtn.addEventListener('click', function(e) {
			e.preventDefault();
			executeSearch();
		});

		// Add animation on scroll
		var animatedElements = widget.querySelectorAll('.oraculo-animate-in');
		var observer = new IntersectionObserver(function(entries) {
			entries.forEach(function(entry) {
				if (entry.isIntersecting) {
					entry.target.style.opacity = '1';
					entry.target.style.transform = 'translateY(0)';
				}
			});
		}, { threshold: 0.1 });

		animatedElements.forEach(function(el) {
			el.style.opacity = '0';
			el.style.transform = 'translateY(30px)';
			el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
			observer.observe(el);
		});
	}

	function initAll() {
		document.querySelectorAll('.oraculo-search-page').forEach(initWidget);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
