/**
 * Oráculo Tainacan - Integração com o tema Tainacan
 *
 * A listagem de itens do Tainacan é uma aplicação Vue.js montada em
 * [data-module="faceted-search"]. Este script observa o DOM renderizado e
 * injeta uma aba "Busca com IA" junto ao campo de busca padrão
 * (.search-control-item--search), sem reposicionar nós gerenciados pelo Vue.
 * Se o Vue re-renderizar a barra de busca, o MutationObserver reinjeta os
 * elementos preservando o estado (aba ativa, resultados, histórico do painel).
 */
(function () {
    'use strict';

    var config = window.OraculoTainacanTheme || null;
    if (!config || !config.restUrl) {
        return;
    }

    var STORAGE_KEY = 'oraculoAiTabActive';
    var SPARKLE_ICON = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/></svg>';
    var SEARCH_ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
    var CLOSE_ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    var ITEM_ICON = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
    var THUMB_UP = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>';
    var THUMB_DOWN = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/></svg>';

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    // Escapa também aspas: o resultado vai para atributos (title, href).
    function escapeHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function sanitizeUrl(url) {
        url = String(url || '').trim();
        if (/^https?:\/\//i.test(url) || url.indexOf('/') === 0) {
            return url;
        }
        return '';
    }

    // Aplica fn só aos trechos de texto fora de tags e fora de <a>: as etapas
    // seguintes não podem reescrever atributos nem aninhar links.
    function mapText(html, fn) {
        var insideLink = 0;
        return html.split(/(<[^>]*>)/).map(function (part, index) {
            if (index % 2) {
                if (/^<a[\s>]/i.test(part)) {
                    insideLink++;
                } else if (/^<\/a>/i.test(part)) {
                    insideLink = Math.max(0, insideLink - 1);
                }
                return part;
            }
            return insideLink ? part : fn(part);
        }).join('');
    }

    // Citação [n]: aponta para o item (funciona mesmo sem os cartões na tela);
    // com o cartão presente, o clique leva até ele.
    function citeLink(num, items) {
        var item = items[num - 1] || {};
        var title = item.title || '';
        var url = sanitizeUrl(item.url);
        return '<a class="oraculo-ai-cite" data-cite="' + num + '"' +
            (url ? ' href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer"' : ' href="#"') +
            ' title="' + escapeHtml(title) + '"' +
            ' aria-label="' + escapeHtml(sprintfS(config.strings.citeAria || 'Item %s', num) + (title ? ': ' + title : '')) + '">' + num + '</a>';
    }

    // Formata uma linha já escapada: ênfase, links markdown, URLs soltas, citações [n]
    function formatInline(safe, items) {
        safe = safe.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        safe = safe.replace(/(^|[^*])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>');

        // Links [texto](url) — apenas http(s) ou relativos
        safe = mapText(safe, function (text) {
            return text.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (match, label, url) {
                var clean = sanitizeUrl(url);
                if (!clean) {
                    return label;
                }
                return '<a href="' + clean + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
            });
        });

        // URL solta: se for a de um item listado, vira a citação dele; senão, link curto
        safe = mapText(safe, function (text) {
            return text.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, function (match, lead, url) {
                var trimmed = url.replace(/[.,;:]+$/, '');
                var rest = url.slice(trimmed.length);
                for (var i = 0; i < items.length; i++) {
                    if (items[i].url && trimmed.replace(/\/$/, '') === escapeHtml(items[i].url).replace(/\/$/, '')) {
                        return lead + citeLink(i + 1, items) + rest;
                    }
                }
                // trimmed já está escapado; volta ao texto cru antes de reescapar
                var clean = sanitizeUrl(trimmed.replace(/&quot;|&#39;|&lt;|&gt;/g, '').replace(/&amp;/g, '&'));
                if (!clean) {
                    return match;
                }
                return lead + '<a href="' + escapeHtml(clean) + '" target="_blank" rel="noopener noreferrer">' +
                    escapeHtml(config.strings.openLink || 'link') + '</a>' + rest;
            });
        });

        // Citações [1] / [1, 3] / [2][5] — só números de itens existentes
        safe = mapText(safe, function (text) {
            return text.replace(/\[(\d+(?:\s*,\s*\d+)*)\]/g, function (match, list) {
                var out = '';
                list.split(',').forEach(function (n) {
                    var num = parseInt(n, 10);
                    if (num >= 1 && num <= items.length) {
                        out += citeLink(num, items);
                    }
                });
                return out || match;
            });
        });

        return safe;
    }

    // Escapa primeiro, formata depois (markdown-lite seguro contra XSS):
    // parágrafos, listas "- ", ênfase, links e citações [n]. Linhas que começam
    // com número ("1922. A Semana...") ficam como texto: tratá-las como <ol>
    // trocaria o número original pelo contador da lista.
    function formatAnswer(text, items) {
        items = items || [];
        var html = '';
        var inList = false;

        String(text || '').split(/\n/).forEach(function (rawLine) {
            var line = rawLine.trim();
            var bullet = line.match(/^[-*•]\s+(.*)$/);

            if (bullet) {
                if (!inList) {
                    html += '<ul>';
                    inList = true;
                }
                html += '<li>' + formatInline(escapeHtml(bullet[1]), items) + '</li>';
                return;
            }

            if (inList) {
                html += '</ul>';
                inList = false;
            }
            if (line) {
                html += '<p>' + formatInline(escapeHtml(line.replace(/^#+\s*/, '')), items) + '</p>';
            }
        });

        if (inList) {
            html += '</ul>';
        }
        return html;
    }

    function sprintfS(template, value) {
        return String(template || '%s').replace('%s', value);
    }

    function storageGet(key) {
        try {
            return window.sessionStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function storageSet(key, value) {
        try {
            window.sessionStorage.setItem(key, value);
        } catch (e) {
            /* armazenamento indisponível: estado apenas em memória */
        }
    }

    /* ------------------------------------------------------------------ */
    /* Controlador por instância de listagem                               */
    /* ------------------------------------------------------------------ */

    function OraculoAiTab(root) {
        this.root = root;
        this.aiActive = storageGet(STORAGE_KEY) === '1';
        this.isSearching = false;
        this.lastQuery = '';
        this.loadingTimer = null;
        this.collectionId = parseInt(root.getAttribute('data-collection-id') || '0', 10) || config.collectionId || 0;

        this.buildTabs();
        this.buildSearchbar();
        this.buildPanel();

        this.observer = new MutationObserver(this.scheduleEnsure.bind(this));
        this.observer.observe(root, { childList: true, subtree: true });
        this.ensureInjected();
        this.handleDeepLink();
    }

    OraculoAiTab.prototype.scheduleEnsure = function () {
        if (this.ensureScheduled) {
            return;
        }
        this.ensureScheduled = true;
        var self = this;
        window.requestAnimationFrame(function () {
            self.ensureScheduled = false;
            self.ensureInjected();
        });
    };

    /* ----------------------------- Abas ------------------------------- */

    OraculoAiTab.prototype.buildTabs = function () {
        var self = this;

        this.tabs = document.createElement('div');
        this.tabs.className = 'oraculo-ai-tabs';
        this.tabs.setAttribute('role', 'tablist');
        this.tabs.setAttribute('aria-label', config.strings.searchAria);

        this.nativeTab = document.createElement('button');
        this.nativeTab.type = 'button';
        this.nativeTab.className = 'oraculo-ai-tab oraculo-ai-tab--native';
        this.nativeTab.setAttribute('role', 'tab');
        this.nativeTab.setAttribute('aria-label', config.strings.nativeTabAria);
        this.nativeTab.textContent = config.strings.nativeTab;

        this.aiTab = document.createElement('button');
        this.aiTab.type = 'button';
        this.aiTab.className = 'oraculo-ai-tab oraculo-ai-tab--ai';
        this.aiTab.setAttribute('role', 'tab');
        this.aiTab.setAttribute('aria-label', config.strings.aiTabAria);
        this.aiTab.innerHTML = SPARKLE_ICON + '<span>' + escapeHtml(config.tabLabel) + '</span>';

        this.tabs.appendChild(this.nativeTab);
        this.tabs.appendChild(this.aiTab);

        this.nativeTab.addEventListener('click', function () {
            self.setAiMode(false);
        });
        this.aiTab.addEventListener('click', function () {
            self.setAiMode(true);
        });

        // Navegação por setas entre as abas (padrão WAI-ARIA tablist)
        this.tabs.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                e.preventDefault();
                self.setAiMode(!self.aiActive);
                (self.aiActive ? self.aiTab : self.nativeTab).focus();
            }
        });
    };

    /* ------------------------ Campo de busca IA ------------------------ */

    OraculoAiTab.prototype.buildSearchbar = function () {
        var self = this;

        this.searchbar = document.createElement('form');
        this.searchbar.className = 'oraculo-ai-searchbar';
        this.searchbar.setAttribute('role', 'search');
        this.searchbar.setAttribute('aria-label', config.strings.searchAria);

        var control = document.createElement('div');
        control.className = 'oraculo-ai-control';

        this.input = document.createElement('input');
        this.input.type = 'search';
        this.input.className = 'oraculo-ai-input input is-small';
        this.input.placeholder = config.placeholder;
        this.input.autocomplete = 'off';
        // O endpoint /search rejeita queries acima do teto com rest_invalid_param;
        // barrar no campo evita gastar uma requisição para receber um 400.
        this.input.maxLength = config.maxQueryLength || 500;
        this.input.setAttribute('aria-label', config.strings.searchAria);

        this.submitBtn = document.createElement('button');
        this.submitBtn.type = 'submit';
        this.submitBtn.className = 'oraculo-ai-submit';
        this.submitBtn.setAttribute('aria-label', config.strings.submit);
        this.submitBtn.innerHTML = SPARKLE_ICON + '<span>' + escapeHtml(config.strings.submit) + '</span>';

        control.appendChild(this.input);
        control.appendChild(this.submitBtn);
        this.searchbar.appendChild(control);

        this.searchbar.addEventListener('submit', function (e) {
            e.preventDefault();
            self.search(self.input.value);
        });

        // Esc volta para a busca padrão
        this.input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                self.setAiMode(false);
                self.focusNativeInput();
            }
        });
    };

    /* --------------------------- Painel -------------------------------- */

    OraculoAiTab.prototype.buildPanel = function () {
        var self = this;

        this.panel = document.createElement('section');
        this.panel.className = 'oraculo-ai-panel';
        this.panel.hidden = true;
        this.panel.setAttribute('aria-label', config.strings.panelTitle);

        var header = document.createElement('header');
        header.className = 'oraculo-ai-panel-header';
        header.innerHTML =
            '<div class="oraculo-ai-panel-title">' + SPARKLE_ICON +
            '<h3>' + escapeHtml(config.strings.panelTitle) + '</h3>' +
            '<span class="oraculo-ai-panel-scope"></span></div>';

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'oraculo-ai-panel-close';
        closeBtn.setAttribute('aria-label', config.strings.close);
        closeBtn.innerHTML = CLOSE_ICON;
        closeBtn.addEventListener('click', function () {
            self.hidePanel();
        });
        header.appendChild(closeBtn);

        this.panelBody = document.createElement('div');
        this.panelBody.className = 'oraculo-ai-panel-body';
        this.panelBody.setAttribute('aria-live', 'polite');

        var footer = document.createElement('footer');
        footer.className = 'oraculo-ai-panel-footer';
        footer.innerHTML = '<span>' + SPARKLE_ICON + ' ' + escapeHtml(config.strings.poweredBy) + '</span>';

        this.panel.appendChild(header);
        this.panel.appendChild(this.panelBody);
        this.panel.appendChild(footer);

        this.scopeEl = header.querySelector('.oraculo-ai-panel-scope');
        this.updateScopeLabel();
    };

    OraculoAiTab.prototype.updateScopeLabel = function () {
        var label;
        if (this.collectionId && config.scope === 'collection' && config.collectionName) {
            label = config.strings.searchingIn + ' ' + config.collectionName;
        } else {
            label = config.strings.searchingIn + ' ' + config.strings.wholeRepository;
        }
        this.scopeEl.textContent = label;
    };

    /* ------------------------ Injeção no DOM Vue ------------------------ */

    OraculoAiTab.prototype.ensureInjected = function () {
        var searchItem = this.root.querySelector('.search-control-item--search');
        if (!searchItem) {
            return;
        }

        // Aba: primeira posição dentro do item de busca
        if (!this.tabs.isConnected || this.tabs.parentNode !== searchItem) {
            searchItem.insertBefore(this.tabs, searchItem.firstChild);
        }

        // Campo IA: logo após a área de busca nativa
        if (!this.searchbar.isConnected || this.searchbar.parentNode !== searchItem) {
            searchItem.appendChild(this.searchbar);
        }

        // Painel: após a barra de controles, dentro da listagem
        var searchControl = this.root.querySelector('.search-control');
        if (searchControl && searchControl.parentNode) {
            if (!this.panel.isConnected || this.panel.previousElementSibling !== searchControl) {
                searchControl.parentNode.insertBefore(this.panel, searchControl.nextSibling);
            }
        } else if (!this.panel.isConnected) {
            this.root.insertBefore(this.panel, this.root.firstChild);
        }

        this.applyMode();
    };

    OraculoAiTab.prototype.applyMode = function () {
        var searchItem = this.tabs.parentNode;
        if (!searchItem) {
            return;
        }
        searchItem.classList.toggle('oraculo-ai-mode', this.aiActive);
        this.nativeTab.classList.toggle('is-active', !this.aiActive);
        this.aiTab.classList.toggle('is-active', this.aiActive);
        this.nativeTab.setAttribute('aria-selected', this.aiActive ? 'false' : 'true');
        this.aiTab.setAttribute('aria-selected', this.aiActive ? 'true' : 'false');
        this.nativeTab.tabIndex = this.aiActive ? -1 : 0;
        this.aiTab.tabIndex = this.aiActive ? 0 : -1;
    };

    OraculoAiTab.prototype.setAiMode = function (active) {
        var wasActive = this.aiActive;
        this.aiActive = !!active;
        storageSet(STORAGE_KEY, this.aiActive ? '1' : '0');
        this.applyMode();

        if (this.aiActive && !wasActive) {
            // Aproveita o que o usuário já digitou na busca nativa
            var nativeInput = this.getNativeInput();
            if (nativeInput && nativeInput.value && !this.input.value) {
                this.input.value = nativeInput.value;
            }
            if (config.showSuggestions && config.suggestions.length && !this.panelBody.childElementCount) {
                this.renderSuggestions();
                this.showPanel(false);
            }
            this.input.focus();
        }

        if (!this.aiActive) {
            this.hidePanel();
        }
    };

    OraculoAiTab.prototype.getNativeInput = function () {
        var searchItem = this.tabs.parentNode;
        return searchItem ? searchItem.querySelector('.search-area input[type="search"], .search-area input') : null;
    };

    OraculoAiTab.prototype.focusNativeInput = function () {
        var nativeInput = this.getNativeInput();
        if (nativeInput) {
            nativeInput.focus();
        }
    };

    /* -------------------------- Painel: estados ------------------------ */

    OraculoAiTab.prototype.showPanel = function (scroll) {
        this.panel.hidden = false;
        if (scroll !== false) {
            var rect = this.panel.getBoundingClientRect();
            if (rect.top < 0 || rect.bottom > window.innerHeight) {
                this.panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }
    };

    OraculoAiTab.prototype.hidePanel = function () {
        this.panel.hidden = true;
        this.stopLoadingMessages();
    };

    OraculoAiTab.prototype.renderSuggestions = function () {
        if (!config.suggestions.length) {
            return;
        }
        var self = this;
        var wrap = document.createElement('div');
        wrap.className = 'oraculo-ai-suggestions';

        var title = document.createElement('p');
        title.className = 'oraculo-ai-suggestions-title';
        title.textContent = config.strings.suggestionsTitle;
        wrap.appendChild(title);

        var list = document.createElement('div');
        list.className = 'oraculo-ai-suggestions-list';
        config.suggestions.forEach(function (question) {
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'oraculo-ai-chip';
            chip.textContent = question;
            chip.addEventListener('click', function () {
                self.input.value = question;
                self.search(question);
            });
            list.appendChild(chip);
        });
        wrap.appendChild(list);

        this.panelBody.innerHTML = '';
        this.panelBody.appendChild(wrap);
    };

    OraculoAiTab.prototype.renderLoading = function () {
        this.panelBody.innerHTML =
            '<div class="oraculo-ai-loading">' +
            '<div class="oraculo-ai-loading-status">' + SPARKLE_ICON +
            '<span class="oraculo-ai-loading-text">' + escapeHtml(config.strings.loading1) + '</span></div>' +
            '<div class="oraculo-ai-skeleton"><span></span><span></span><span></span></div>' +
            '</div>';

        var textEl = this.panelBody.querySelector('.oraculo-ai-loading-text');
        var messages = [config.strings.loading2, config.strings.loading3];
        var step = 0;
        this.stopLoadingMessages();
        this.loadingTimer = window.setInterval(function () {
            if (step < messages.length) {
                textEl.textContent = messages[step];
                step++;
            }
        }, 1800);
    };

    OraculoAiTab.prototype.stopLoadingMessages = function () {
        if (this.loadingTimer) {
            window.clearInterval(this.loadingTimer);
            this.loadingTimer = null;
        }
    };

    OraculoAiTab.prototype.renderError = function (message) {
        this.panelBody.innerHTML =
            '<div class="oraculo-ai-error"><p>' + escapeHtml(message || config.strings.error) + '</p></div>';
        this.appendActions();
    };

    OraculoAiTab.prototype.renderResult = function (data, elapsedSeconds) {
        var self = this;
        var html = '';
        var items = Array.isArray(data.items) ? data.items : [];

        html += '<div class="oraculo-ai-answer">' + formatAnswer(data.response, items) + '</div>';

        if (config.showSources && items.length) {
            html += '<div class="oraculo-ai-sources"><h4>' + SEARCH_ICON + ' ' +
                escapeHtml(config.strings.sources) + ' <span class="oraculo-ai-sources-count">' + items.length + '</span></h4>';
            html += '<ol class="oraculo-ai-sources-grid">';
            items.forEach(function (item, index) {
                var num = index + 1;
                var url = sanitizeUrl(item.url);
                var thumb = sanitizeUrl(item.thumbnail);
                var tag = url ? 'a' : 'div';

                html += '<li class="oraculo-ai-source-card" data-source="' + num + '">';
                html += '<' + tag + ' class="oraculo-ai-source-link"' +
                    (url ? ' href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer"' : '') + '>';
                html += '<span class="oraculo-ai-source-thumb">' +
                    (thumb ? '<img src="' + escapeHtml(thumb) + '" alt="" loading="lazy">' : ITEM_ICON) +
                    '<span class="oraculo-ai-source-num">' + num + '</span></span>';
                html += '<span class="oraculo-ai-source-info">';
                html += '<span class="oraculo-ai-source-title">' + escapeHtml(item.title || url) + '</span>';
                if (item.collection_name) {
                    html += '<span class="oraculo-ai-source-collection">' + escapeHtml(item.collection_name) + '</span>';
                }
                var details = Array.isArray(item.details) ? item.details : [];
                if (details.length) {
                    html += '<dl class="oraculo-ai-source-details">';
                    details.forEach(function (d) {
                        html += '<div><dt>' + escapeHtml(d.label) + '</dt><dd>' + escapeHtml(d.value) + '</dd></div>';
                    });
                    html += '</dl>';
                } else if (item.snippet) {
                    html += '<span class="oraculo-ai-source-snippet">' + escapeHtml(item.snippet).replace(/&lt;(\/?)mark&gt;/g, '<$1mark>') + '</span>';
                }
                if (config.showSimilarity && item.similarity != null) {
                    var pct = Math.max(0, Math.min(100, parseFloat(item.similarity) || 0));
                    html += '<span class="oraculo-ai-source-similarity"><span class="oraculo-ai-meter"><span style="width:' + pct + '%"></span></span>' + pct + '% ' + escapeHtml(config.strings.relevance) + '</span>';
                }
                html += '</span></' + tag + '></li>';
            });
            html += '</ol></div>';
        }

        if (elapsedSeconds != null) {
            html += '<p class="oraculo-ai-response-time">' + escapeHtml(sprintfS(config.strings.responseTime, elapsedSeconds)) + '</p>';
        }

        this.panelBody.innerHTML = html;

        // Cartões citados no texto ganham destaque; clicar na citação leva ao cartão
        Array.prototype.forEach.call(this.panelBody.querySelectorAll('.oraculo-ai-cite'), function (cite) {
            var card = self.panelBody.querySelector('[data-source="' + cite.getAttribute('data-cite') + '"]');
            if (!card) {
                return;
            }
            card.classList.add('is-cited');
            cite.addEventListener('click', function (e) {
                e.preventDefault();
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                card.classList.remove('is-flash');
                void card.offsetWidth; // reinicia a animação em cliques repetidos
                card.classList.add('is-flash');
                var link = card.querySelector('.oraculo-ai-source-link');
                if (link && link.focus) {
                    link.focus({ preventScroll: true });
                }
            });
        });

        if (config.enableFeedback && data.search_id) {
            this.appendFeedback(String(data.search_id));
        }
        this.appendActions();

        // Evento público para extensões de terceiros
        this.root.dispatchEvent(new CustomEvent('oraculo-ai-search-done', {
            bubbles: true,
            detail: { query: this.lastQuery, result: data }
        }));
    };

    OraculoAiTab.prototype.appendFeedback = function (searchId) {
        var self = this;
        var feedback = document.createElement('div');
        feedback.className = 'oraculo-ai-feedback';
        feedback.innerHTML = '<span>' + escapeHtml(config.strings.helpful) + '</span>';

        [['positive', THUMB_UP, config.strings.yes], ['negative', THUMB_DOWN, config.strings.no]].forEach(function (def) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'oraculo-ai-feedback-btn oraculo-ai-feedback-' + def[0];
            btn.innerHTML = def[1] + '<span>' + escapeHtml(def[2]) + '</span>';
            btn.addEventListener('click', function () {
                self.sendFeedback(searchId, def[0]);
                feedback.innerHTML = '<span class="oraculo-ai-feedback-thanks">' + escapeHtml(config.strings.thanks) + '</span>';
            });
            feedback.appendChild(btn);
        });

        this.panelBody.appendChild(feedback);
    };

    OraculoAiTab.prototype.appendActions = function () {
        var self = this;
        var actions = document.createElement('div');
        actions.className = 'oraculo-ai-actions';

        var newQuestion = document.createElement('button');
        newQuestion.type = 'button';
        newQuestion.className = 'oraculo-ai-action-btn';
        newQuestion.textContent = config.strings.newQuestion;
        newQuestion.addEventListener('click', function () {
            self.input.value = '';
            self.input.focus();
            if (config.showSuggestions && config.suggestions.length) {
                self.renderSuggestions();
            } else {
                self.hidePanel();
            }
        });
        actions.appendChild(newQuestion);

        // Leva a consulta para a busca nativa do Tainacan (refinamento manual)
        var refine = document.createElement('button');
        refine.type = 'button';
        refine.className = 'oraculo-ai-action-btn oraculo-ai-action-secondary';
        refine.textContent = config.strings.useNativeSearch;
        refine.addEventListener('click', function () {
            var nativeInput = self.getNativeInput();
            self.setAiMode(false);
            if (nativeInput) {
                nativeInput.value = self.lastQuery;
                // Notifica o v-model do Vue para sincronizar o estado interno
                nativeInput.dispatchEvent(new Event('input', { bubbles: true }));
                nativeInput.focus();
            }
        });
        actions.appendChild(refine);

        this.panelBody.appendChild(actions);
    };

    /* ----------------------------- Busca -------------------------------- */

    OraculoAiTab.prototype.search = function (query) {
        query = String(query || '').trim();
        // Deep link e sugestões escrevem no campo por código (maxLength não se
        // aplica), então o corte precisa acontecer aqui também.
        query = query.slice(0, config.maxQueryLength || 500);
        if (!query || this.isSearching) {
            if (!query) {
                this.input.focus();
            }
            return;
        }

        var self = this;
        this.isSearching = true;
        this.lastQuery = query;
        this.submitBtn.disabled = true;
        this.searchbar.classList.add('is-loading');
        this.renderLoading();
        this.showPanel();

        var startTime = Date.now();

        // OraculoRest renova o nonce e repete uma vez se a sessão mudou.
        window.OraculoRest.post(config.restUrl + 'search', {
            query: query,
            collections: config.collections,
            max_results: config.maxResults
        })
            .then(function (envelope) {
                self.finishSearch();

                var payload = envelope.payload;

                // WP_Error do permission_callback (nonce 401, rate-limit 429,
                // teto global 503, recurso desativado 403) chega como
                // {code, message, data:{status}} — sem o envelope success/data.
                // Já o handler de busca devolve 400 com {success:false, error} —
                // sem ler `error` aqui, a mensagem real (ex: provedor mal
                // configurado) era descartada e só sobrava o texto genérico.
                if (!envelope.ok || (payload && payload.code && payload.message)) {
                    self.renderError((payload && (payload.message || payload.error)) || config.strings.error);
                    return;
                }

                if (payload && payload.success === false) {
                    self.renderError(payload.error || payload.message);
                    return;
                }

                var result = payload && payload.data ? payload.data : payload;
                if (result && result.response) {
                    var elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
                    self.renderResult(result, elapsed);
                } else {
                    self.renderError(config.strings.noResults);
                }
            })
            .catch(function () {
                self.finishSearch();
                self.renderError(config.strings.error);
            });
    };

    OraculoAiTab.prototype.finishSearch = function () {
        this.isSearching = false;
        this.submitBtn.disabled = false;
        this.searchbar.classList.remove('is-loading');
        this.stopLoadingMessages();
    };

    OraculoAiTab.prototype.sendFeedback = function (searchId, feedback) {
        window.OraculoRest.post(config.restUrl + 'feedback', { search_id: searchId, feedback: feedback })
            .catch(function () { /* feedback é best-effort */ });
    };

    /* --------------------------- Deep link ------------------------------ */

    // ?oraculo_q=pergunta abre a aba IA e executa a busca automaticamente
    OraculoAiTab.prototype.handleDeepLink = function () {
        var params;
        try {
            params = new URLSearchParams(window.location.search);
        } catch (e) {
            return;
        }
        var query = params.get('oraculo_q');
        if (query) {
            this.setAiMode(true);
            this.input.value = query;
            this.search(query);
        }
    };

    /* ------------------------------------------------------------------ */
    /* Inicialização                                                       */
    /* ------------------------------------------------------------------ */

    function init() {
        var roots = document.querySelectorAll('[data-module="faceted-search"]');
        Array.prototype.forEach.call(roots, function (root) {
            if (!root.oraculoAiTab) {
                root.oraculoAiTab = new OraculoAiTab(root);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
