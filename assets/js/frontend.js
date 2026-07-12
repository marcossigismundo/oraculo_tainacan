/**
 * Oráculo Tainacan - Frontend JavaScript
 */

(function($) {
    'use strict';

    // Namespace
    window.OraculoTainacan = window.OraculoTainacan || {};

    /**
     * Escapa conteúdo dinâmico antes de injetar em HTML (texto e atributos).
     */
    OraculoTainacan.escapeHtml = function(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).replace(/[&<>"']/g, function(ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[ch];
        });
    };

    /**
     * Search Widget
     */
    OraculoTainacan.Search = {
        init: function() {
            // O widget de página completa (.oraculo-search-page) tem seu próprio
            // handler em search-page.js — excluí-lo evita busca disparada em dobro.
            this.form = $('.oraculo-search-form').not('.oraculo-search-page .oraculo-search-form');
            this.input = $('.oraculo-search-input').not('.oraculo-search-page .oraculo-search-input');
            this.button = $('.oraculo-search-button').not('.oraculo-search-page .oraculo-search-button');
            this.results = $('.oraculo-results');
            this.suggestions = $('.oraculo-suggestions');

            if (!this.form.length) return;

            this.bindEvents();
            this.loadSuggestions();
        },

        bindEvents: function() {
            var self = this;

            // Form submit
            this.form.on('submit', function(e) {
                e.preventDefault();
                self.search();
            });

            // Enter key
            this.input.on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    self.search();
                }
            });

            // Collection filters
            $(document).on('click', '.oraculo-collection-filter', function() {
                $(this).toggleClass('active');
            });

            // Suggestions click
            $(document).on('click', '.oraculo-suggestion-item', function() {
                self.input.val($(this).text());
                self.search();
            });

            // Feedback
            $(document).on('click', '.oraculo-feedback-btn', function() {
                var btn = $(this);
                var feedback = btn.hasClass('positive') ? 'positive' : 'negative';
                var searchId = btn.closest('.oraculo-response').data('search-id');
                self.sendFeedback(searchId, feedback, btn);
            });
        },

        search: function() {
            var self = this;
            var query = this.input.val().trim();

            if (!query) {
                this.input.focus();
                return;
            }

            // Get selected collections
            var collections = [];
            $('.oraculo-collection-filter.active').each(function() {
                collections.push($(this).data('collection-id'));
            });

            this.showLoading();

            $.ajax({
                url: OraculoFrontend.restUrl + 'search',
                type: 'POST',
                contentType: 'application/json',
                headers: { 'X-WP-Nonce': OraculoFrontend.restNonce },
                data: JSON.stringify({
                    query: query,
                    collections: collections
                }),
                success: function(response) {
                    if (response.success) {
                        self.renderResults(response.data);
                    } else {
                        self.showError(response.error || (response.data && response.data.message) || OraculoFrontend.strings.error);
                    }
                },
                error: function(xhr) {
                    var msg = OraculoFrontend.strings.error;
                    if (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.error)) {
                        msg = xhr.responseJSON.message || xhr.responseJSON.error;
                    }
                    self.showError(msg);
                }
            });
        },

        showLoading: function() {
            this.button.prop('disabled', true);
            this.results.html(
                '<div class="oraculo-loading">' +
                    '<div class="oraculo-spinner"></div>' +
                    '<span>' + OraculoFrontend.strings.searching + '</span>' +
                '</div>'
            );
        },

        renderResults: function(data) {
            var esc = OraculoTainacan.escapeHtml;
            var html = '';

            // Response
            html += '<div class="oraculo-response" data-search-id="' + esc(data.search_id) + '">';
            html += '  <div class="oraculo-response-header">';
            html += '    <div class="oraculo-response-icon">🔮</div>';
            html += '    <span class="oraculo-response-label">Oráculo</span>';
            html += '  </div>';
            html += '  <div class="oraculo-response-content">' + this.formatResponse(data.response) + '</div>';

            // Sources
            if (data.items && data.items.length > 0) {
                html += '  <div class="oraculo-sources">';
                html += '    <div class="oraculo-sources-title">' + esc(OraculoFrontend.strings.sources) + ' (' + data.items.length + ')</div>';

                data.items.forEach(function(item, index) {
                    html += '<div class="oraculo-source-item">';
                    html += '  <div class="oraculo-source-number">' + (index + 1) + '</div>';
                    html += '  <div class="oraculo-source-content">';
                    html += '    <a href="' + esc(item.url) + '" class="oraculo-source-title" target="_blank" rel="noopener noreferrer">' + esc(item.title) + '</a>';
                    html += '    <div class="oraculo-source-snippet">' + esc(item.snippet) + '</div>';
                    html += '    <div class="oraculo-source-meta">';
                    html += '      <span class="oraculo-source-collection">' + esc(item.collection_name) + '</span>';
                    html += '      <span class="oraculo-source-similarity">' + esc(item.similarity) + '% relevância</span>';
                    html += '    </div>';
                    html += '  </div>';
                    html += '</div>';
                });

                html += '  </div>';
            }

            // Feedback
            if (OraculoFrontend.enableFeedback !== false) {
                html += '  <div class="oraculo-feedback">';
                html += '    <span class="oraculo-feedback-label">' + OraculoFrontend.strings.helpful + '</span>';
                html += '    <button class="oraculo-feedback-btn positive">' + OraculoFrontend.strings.yes + ' 👍</button>';
                html += '    <button class="oraculo-feedback-btn negative">' + OraculoFrontend.strings.no + ' 👎</button>';
                html += '  </div>';
            }

            html += '</div>';

            this.results.html(html);
            this.button.prop('disabled', false);
        },

        formatResponse: function(text) {
            // Escapar primeiro; a formatação markdown é aplicada sobre texto seguro.
            text = OraculoTainacan.escapeHtml(text);
            text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
            text = text.replace(/\n/g, '<br>');
            return '<p>' + text + '</p>';
        },

        showError: function(message) {
            this.results.html(
                '<div class="oraculo-error">' +
                    '<span class="oraculo-error-icon">⚠️</span>' +
                    '<span>' + OraculoTainacan.escapeHtml(message) + '</span>' +
                '</div>'
            );
            this.button.prop('disabled', false);
        },

        sendFeedback: function(searchId, feedback, btn) {
            var container = btn.closest('.oraculo-feedback');

            $.ajax({
                url: OraculoFrontend.restUrl + 'feedback',
                type: 'POST',
                contentType: 'application/json',
                headers: { 'X-WP-Nonce': OraculoFrontend.restNonce },
                data: JSON.stringify({
                    search_id: searchId,
                    feedback: feedback
                }),
                success: function() {
                    container.html('<span class="oraculo-feedback-thanks">✓ Obrigado pelo feedback!</span>');
                }
            });
        },

        loadSuggestions: function() {
            var self = this;

            if (!this.suggestions.length) return;

            var defaultSuggestions = OraculoFrontend.suggestedQuestions || [];

            if (defaultSuggestions.length > 0) {
                var html = '<div class="oraculo-suggestion-list">';
                defaultSuggestions.forEach(function(suggestion) {
                    html += '<button type="button" class="oraculo-suggestion-item">' + OraculoTainacan.escapeHtml(suggestion) + '</button>';
                });
                html += '</div>';
                this.suggestions.html(html);
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        OraculoTainacan.Search.init();
    });

})(jQuery);
