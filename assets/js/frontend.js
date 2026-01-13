/**
 * Oráculo Tainacan - Frontend JavaScript
 */

(function($) {
    'use strict';

    // Namespace
    window.OraculoTainacan = window.OraculoTainacan || {};

    /**
     * Search Widget
     */
    OraculoTainacan.Search = {
        init: function() {
            this.form = $('.oraculo-search-form');
            this.input = $('.oraculo-search-input');
            this.button = $('.oraculo-search-button');
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
                url: OraculoFrontend.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'oraculo_search',
                    nonce: OraculoFrontend.nonce,
                    query: query,
                    collections: collections
                },
                success: function(response) {
                    if (response.success) {
                        self.renderResults(response.data);
                    } else {
                        self.showError(response.data.message);
                    }
                },
                error: function() {
                    self.showError(OraculoFrontend.strings.error);
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
            var html = '';

            // Response
            html += '<div class="oraculo-response" data-search-id="' + data.search_id + '">';
            html += '  <div class="oraculo-response-header">';
            html += '    <div class="oraculo-response-icon">🔮</div>';
            html += '    <span class="oraculo-response-label">Oráculo</span>';
            html += '  </div>';
            html += '  <div class="oraculo-response-content">' + this.formatResponse(data.response) + '</div>';

            // Sources
            if (data.items && data.items.length > 0) {
                html += '  <div class="oraculo-sources">';
                html += '    <div class="oraculo-sources-title">' + OraculoFrontend.strings.sources + ' (' + data.items.length + ')</div>';

                data.items.forEach(function(item, index) {
                    html += '<div class="oraculo-source-item">';
                    html += '  <div class="oraculo-source-number">' + (index + 1) + '</div>';
                    html += '  <div class="oraculo-source-content">';
                    html += '    <a href="' + item.url + '" class="oraculo-source-title" target="_blank">' + item.title + '</a>';
                    html += '    <div class="oraculo-source-snippet">' + item.snippet + '</div>';
                    html += '    <div class="oraculo-source-meta">';
                    html += '      <span class="oraculo-source-collection">' + item.collection_name + '</span>';
                    html += '      <span class="oraculo-source-similarity">' + item.similarity + '% relevância</span>';
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
            // Convert markdown-like formatting
            text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
            text = text.replace(/\n/g, '<br>');
            return '<p>' + text + '</p>';
        },

        showError: function(message) {
            this.results.html(
                '<div class="oraculo-error">' +
                    '<span class="oraculo-error-icon">⚠️</span>' +
                    '<span>' + message + '</span>' +
                '</div>'
            );
            this.button.prop('disabled', false);
        },

        sendFeedback: function(searchId, feedback, btn) {
            var container = btn.closest('.oraculo-feedback');

            $.ajax({
                url: OraculoFrontend.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'oraculo_feedback',
                    nonce: OraculoFrontend.nonce,
                    search_id: searchId,
                    feedback: feedback
                },
                success: function(response) {
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
                    html += '<button type="button" class="oraculo-suggestion-item">' + suggestion + '</button>';
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
