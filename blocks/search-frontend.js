/**
 * Frontend JavaScript for Oráculo Search Block
 *
 * @package Oraculo_Tainacan
 */

(function($) {
    'use strict';

    window.oraculoSearchBlock = {
        /**
         * Perform AJAX search
         */
        performSearch: function(query, collections, limit) {
            const $container = $('.oraculo-results-container');
            const $resultsList = $container.find('.oraculo-results-list');
            const $summaryContent = $container.find('.oraculo-summary-content');
            const $loading = $container.find('.oraculo-loading');
            const $feedback = $container.find('.oraculo-feedback-section');

            // Show loading
            $loading.show();
            $resultsList.empty();
            $summaryContent.empty();
            $feedback.hide();

            // Make AJAX request
            $.ajax({
                url: oraculoSearchBlock.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'oraculo_block_search',
                    query: query,
                    collections: collections,
                    limit: limit,
                    nonce: oraculoSearchBlock.nonce
                },
                success: function(response) {
                    $loading.hide();

                    if (response.success) {
                        const data = response.data;
                        
                        // Store search ID for feedback
                        $feedback.data('search-id', data.search_id);

                        // Render results
                        if (data.items && data.items.length > 0) {
                            oraculoSearchBlock.renderResults(data.items, $resultsList);
                            
                            // Render summary if available
                            if (data.response) {
                                oraculoSearchBlock.renderSummary(data.response, $summaryContent);
                            }

                            $feedback.show();
                        } else {
                            $resultsList.html('<p class="oraculo-no-results">' + oraculoSearchBlock.i18n.noResults + '</p>');
                        }
                    } else {
                        $resultsList.html('<p class="oraculo-error">' + (response.data?.message || oraculoSearchBlock.i18n.error) + '</p>');
                    }
                },
                error: function() {
                    $loading.hide();
                    $resultsList.html('<p class="oraculo-error">' + oraculoSearchBlock.i18n.error + '</p>');
                }
            });
        },

        /**
         * Render search results
         */
        renderResults: function(items, $container) {
            const resultsHtml = items.map((item, index) => {
                const snippet = item.snippet ? 
                    `<div class="oraculo-item-snippet">${item.snippet}</div>` : 
                    `<div class="oraculo-item-description">${item.description || ''}</div>`;

                return `
                    <div class="oraculo-result-item">
                        <div class="oraculo-result-header">
                            <span class="oraculo-result-number">${index + 1}</span>
                            <h3 class="oraculo-result-title">${item.title}</h3>
                            <span class="oraculo-result-score">${item.score}%</span>
                        </div>
                        ${snippet}
                        <div class="oraculo-result-meta">
                            <span class="oraculo-result-collection">${item.collection}</span>
                            ${item.metadata?.year ? `<span class="oraculo-result-year">${item.metadata.year}</span>` : ''}
                            ${item.metadata?.type ? `<span class="oraculo-result-type">${item.metadata.type}</span>` : ''}
                        </div>
                        <a href="${item.permalink}" target="_blank" class="oraculo-result-link">
                            ${oraculoSearchBlock.i18n.viewItem} →
                        </a>
                    </div>
                `;
            }).join('');

            $container.html(resultsHtml);

            // Generate facets if metadata available
            this.generateFacets(items);
        },

        /**
         * Render AI summary
         */
        renderSummary: function(response, $container) {
            // Convert line breaks to paragraphs
            const paragraphs = response.split('\n\n').map(p => `<p>${p}</p>`).join('');
            $container.html(paragraphs);
        },

        /**
         * Generate facets from results
         */
        generateFacets: function(items) {
            const facets = {
                collections: {},
                years: {},
                types: {}
            };

            // Collect facet data
            items.forEach(item => {
                if (item.collection) {
                    facets.collections[item.collection] = (facets.collections[item.collection] || 0) + 1;
                }
                if (item.metadata?.year) {
                    facets.years[item.metadata.year] = (facets.years[item.metadata.year] || 0) + 1;
                }
                if (item.metadata?.type) {
                    facets.types[item.metadata.type] = (facets.types[item.metadata.type] || 0) + 1;
                }
            });

            // Render facets if any
            const $facetsContainer = $('.oraculo-facets');
            if ($facetsContainer.length === 0 && Object.keys(facets.collections).length > 1) {
                const facetsHtml = this.renderFacets(facets);
                $('.oraculo-results-list').before(facetsHtml);
            }
        },

        /**
         * Render facets HTML
         */
        renderFacets: function(facets) {
            let html = '<div class="oraculo-facets">';

            // Collection facets
            if (Object.keys(facets.collections).length > 1) {
                html += '<div class="oraculo-facet-group">';
                html += '<h4>Collections</h4>';
                for (const [name, count] of Object.entries(facets.collections)) {
                    html += `<label><input type="checkbox" value="${name}" data-facet="collection"> ${name} (${count})</label>`;
                }
                html += '</div>';
            }

            // Year facets
            if (Object.keys(facets.years).length > 1) {
                html += '<div class="oraculo-facet-group">';
                html += '<h4>Year</h4>';
                const sortedYears = Object.entries(facets.years).sort((a, b) => b[0] - a[0]);
                for (const [year, count] of sortedYears) {
                    html += `<label><input type="checkbox" value="${year}" data-facet="year"> ${year} (${count})</label>`;
                }
                html += '</div>';
            }

            html += '</div>';
            return html;
        },

        /**
         * Initialize event handlers
         */
        init: function() {
            // Tab switching
            $(document).on('click', '.oraculo-tab', function() {
                const $tab = $(this);
                const tabName = $tab.data('tab');
                
                // Update active states
                $tab.siblings().removeClass('active');
                $tab.addClass('active');
                
                // Show corresponding pane
                const $panes = $tab.closest('.oraculo-results-container').find('.oraculo-tab-pane');
                $panes.removeClass('active');
                $panes.filter(`[data-pane="${tabName}"]`).addClass('active');
            });

            // Feedback buttons
            $(document).on('click', '.oraculo-feedback-btn', function() {
                const $btn = $(this);
                const value = $btn.data('value');
                const searchId = $btn.closest('.oraculo-feedback-section').data('search-id');
                
                // Visual feedback
                $btn.addClass('active').siblings().removeClass('active');
                
                // Send feedback
                $.ajax({
                    url: oraculoSearchBlock.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'oraculo_save_feedback',
                        search_id: searchId,
                        feedback: value,
                        nonce: oraculoSearchBlock.nonce
                    }
                });
            });

            // Facet filtering
            $(document).on('change', '.oraculo-facets input', function() {
                const activeFilters = {
                    collection: [],
                    year: [],
                    type: []
                };

                // Collect active filters
                $('.oraculo-facets input:checked').each(function() {
                    const facet = $(this).data('facet');
                    const value = $(this).val();
                    activeFilters[facet].push(value);
                });

                // Filter results
                $('.oraculo-result-item').each(function() {
                    const $item = $(this);
                    let show = true;

                    // Check collection filter
                    if (activeFilters.collection.length > 0) {
                        const itemCollection = $item.find('.oraculo-result-collection').text();
                        if (!activeFilters.collection.includes(itemCollection)) {
                            show = false;
                        }
                    }

                    // Check year filter
                    if (show && activeFilters.year.length > 0) {
                        const itemYear = $item.find('.oraculo-result-year').text();
                        if (!activeFilters.year.includes(itemYear)) {
                            show = false;
                        }
                    }

                    // Show/hide item
                    $item.toggle(show);
                });

                // Update result count
                const visibleCount = $('.oraculo-result-item:visible').length;
                const totalCount = $('.oraculo-result-item').length;
                
                if (visibleCount < totalCount) {
                    if ($('.oraculo-filter-status').length === 0) {
                        $('.oraculo-facets').after(`<p class="oraculo-filter-status">Showing ${visibleCount} of ${totalCount} results</p>`);
                    } else {
                        $('.oraculo-filter-status').text(`Showing ${visibleCount} of ${totalCount} results`);
                    }
                } else {
                    $('.oraculo-filter-status').remove();
                }
            });

            // Form submission
            $(document).on('submit', '.oraculo-search-form', function(e) {
                // If using AJAX, prevent default
                if ($(this).closest('.oraculo-search-block-container').data('ajax') !== false) {
                    e.preventDefault();
                    
                    const query = $(this).find('input[name="oraculo_query"]').val();
                    const collections = [];
                    $(this).find('input[name="oraculo_collections[]"]:checked').each(function() {
                        collections.push(parseInt($(this).val()));
                    });
                    const maxResults = $(this).closest('.oraculo-search-block-container').data('max-results') || 10;
                    
                    oraculoSearchBlock.performSearch(query, collections, maxResults);
                }
            });

            // Stream AI response (if enabled)
            if (window.EventSource) {
                $(document).on('click', '[data-stream-response]', function() {
                    const query = $(this).data('query');
                    const $container = $('.oraculo-summary-content');
                    
                    $container.empty().append('<p>Generating response...</p>');
                    
                    const source = new EventSource(oraculoSearchBlock.ajaxUrl + '?action=oraculo_stream_response&query=' + encodeURIComponent(query) + '&nonce=' + oraculoSearchBlock.nonce);
                    
                    let content = '';
                    
                    source.onmessage = function(event) {
                        if (event.data === '[DONE]') {
                            source.close();
                        } else {
                            const data = JSON.parse(event.data);
                            if (data.content) {
                                content += data.content;
                                $container.html(content);
                            }
                        }
                    };
                    
                    source.onerror = function() {
                        source.close();
                    };
                });
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        oraculoSearchBlock.init();
    });

})(jQuery);