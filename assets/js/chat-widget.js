/**
 * Oráculo Tainacan - Chat Widget
 */

(function($) {
    'use strict';

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

    window.OraculoChat = {
        sessionId: null,
        isOpen: false,
        isTyping: false,

        init: function() {
            if (!OraculoFrontend.enableChat) return;

            this.createWidget();
            this.bindEvents();
            this.loadSession();
        },

        // Avatar da BIA: monograma em círculo com gradiente (inline SVG — sem
        // asset externo). Reutilizado no botão, no header e nas mensagens.
        avatarSvg: function(size) {
            return `
                <svg class="oraculo-bia-avatar" viewBox="0 0 40 40" width="${size}" height="${size}" role="img" aria-hidden="true">
                    <defs>
                        <linearGradient id="oraculo-bia-grad" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="var(--oraculo-primary, #1f2f56)"/>
                            <stop offset="1" stop-color="var(--oraculo-accent, #b5e0e3)"/>
                        </linearGradient>
                    </defs>
                    <circle cx="20" cy="20" r="20" fill="url(#oraculo-bia-grad)"/>
                    <path d="M11 13.5c0-1 .8-1.8 1.8-1.8h4.4c1.5 0 2.8.9 2.8 2.4v11.4c-.7-.9-1.8-1.4-3-1.4h-4.2c-1 0-1.8-.8-1.8-1.8v-8.8zm18 0c0-1-.8-1.8-1.8-1.8h-4.4c-1.5 0-2.8.9-2.8 2.4v11.4c.7-.9 1.8-1.4 3-1.4h4.2c1 0 1.8-.8 1.8-1.8v-8.8z"
                        fill="none" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/>
                    <circle cx="20" cy="30.6" r="1.4" fill="#fff"/>
                </svg>
            `;
        },

        createWidget: function() {
            var position = OraculoFrontend.chatPosition || 'bottom-right';
            var s = OraculoFrontend.strings || {};
            var name = OraculoFrontend.assistantName || 'BIA';
            var role = OraculoFrontend.assistantRole || 'Bibliotecária de IA';

            // Botão flutuante: avatar da BIA (vira X quando aberto)
            var buttonHtml = `
                <button class="oraculo-chat-button ${position}" id="oraculo-chat-toggle"
                        aria-label="${escapeHtml(s.openChat || 'Conversar com a BIA')}" aria-expanded="false">
                    <span class="chat-icon">${this.avatarSvg(44)}</span>
                    <svg class="close-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            `;

            // Janela do chat
            var windowHtml = `
                <div class="oraculo-chat-window ${position}" id="oraculo-chat-window"
                     role="dialog" aria-label="${escapeHtml(name)} — ${escapeHtml(role)}">
                    <div class="oraculo-chat-header">
                        <div class="oraculo-chat-avatar">${this.avatarSvg(38)}</div>
                        <div class="oraculo-chat-identity">
                            <h3 class="oraculo-chat-title">${escapeHtml(name)}</h3>
                            <p class="oraculo-chat-subtitle">
                                <span class="oraculo-chat-status-dot" aria-hidden="true"></span>
                                ${escapeHtml(role)} · ${escapeHtml(s.online || 'online')}
                            </p>
                        </div>
                        <button class="oraculo-chat-new" id="oraculo-chat-new"
                                title="${escapeHtml(s.newConversation || 'Nova conversa')}"
                                aria-label="${escapeHtml(s.newConversation || 'Nova conversa')}">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <polyline points="1 4 1 10 7 10"></polyline>
                                <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                            </svg>
                        </button>
                        <button class="oraculo-chat-close" id="oraculo-chat-close"
                                aria-label="${escapeHtml(s.closeChat || 'Fechar conversa')}">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <div class="oraculo-chat-messages" id="oraculo-chat-messages" role="log" aria-live="polite"></div>
                    <div class="oraculo-chat-input-container">
                        <form class="oraculo-chat-input-form" id="oraculo-chat-form">
                            <textarea class="oraculo-chat-input" id="oraculo-chat-input"
                                placeholder="${escapeHtml(s.placeholder || 'Pergunte à BIA sobre o acervo…')}"
                                aria-label="${escapeHtml(s.placeholder || 'Pergunte à BIA sobre o acervo…')}"
                                rows="1"></textarea>
                            <button type="submit" class="oraculo-chat-send" id="oraculo-chat-send"
                                    aria-label="${escapeHtml(s.send || 'Enviar')}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <line x1="22" y1="2" x2="11" y2="13"/>
                                    <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>
            `;

            $('body').append(buttonHtml).append(windowHtml);

            this.button = $('#oraculo-chat-toggle');
            this.window = $('#oraculo-chat-window');
            this.messages = $('#oraculo-chat-messages');
            this.form = $('#oraculo-chat-form');
            this.input = $('#oraculo-chat-input');
            this.sendBtn = $('#oraculo-chat-send');

            this.messages.html(this.getWelcomeHtml());
        },

        getWelcomeHtml: function() {
            var s = OraculoFrontend.strings || {};
            var name = OraculoFrontend.assistantName || 'BIA';
            var welcomeMessage = OraculoFrontend.welcomeMessage || s.defaultWelcome ||
                'Oi! Eu sou a BIA, a bibliotecária de IA deste acervo. Posso ajudar a encontrar obras, autores e assuntos — é só perguntar.';
            var suggestions = OraculoFrontend.suggestedQuestions || [];

            var html = `
                <div class="oraculo-chat-welcome">
                    <div class="oraculo-chat-welcome-avatar">${this.avatarSvg(64)}</div>
                    <div class="oraculo-chat-welcome-name">${escapeHtml(name)}</div>
                    <div class="oraculo-chat-welcome-text">${escapeHtml(welcomeMessage)}</div>
                    ${suggestions.length > 0 ? '<div class="oraculo-chat-suggestions">' +
                        suggestions.map(s2 => `<button type="button" class="oraculo-chat-suggestion">${escapeHtml(s2)}</button>`).join('') +
                    '</div>' : ''}
                </div>
            `;

            return html;
        },

        bindEvents: function() {
            var self = this;

            // Toggle chat
            this.button.on('click', function() {
                self.toggle();
            });

            $('#oraculo-chat-close').on('click', function() {
                self.close();
            });

            // Nova conversa direto do header
            $('#oraculo-chat-new').on('click', function() {
                self.newConversation();
                self.input.trigger('focus');
            });

            // Esc fecha a janela
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && self.isOpen) {
                    self.close();
                }
            });

            // Form submit
            this.form.on('submit', function(e) {
                e.preventDefault();
                self.sendMessage();
            });

            // Auto-resize textarea
            this.input.on('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });

            // Enter to send (Shift+Enter for new line)
            this.input.on('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });

            // Suggestion click
            $(document).on('click', '.oraculo-chat-suggestion', function() {
                self.input.val($(this).text());
                self.sendMessage();
            });

            // Feedback
            $(document).on('click', '.oraculo-message-feedback button', function() {
                var btn = $(this);
                var feedback = btn.data('feedback');
                var messageId = btn.closest('.oraculo-chat-message').data('message-id');
                self.sendFeedback(messageId, feedback, btn);
            });

            // Click outside to close
            $(document).on('click', function(e) {
                if (self.isOpen &&
                    !$(e.target).closest('.oraculo-chat-window').length &&
                    !$(e.target).closest('.oraculo-chat-button').length) {
                    self.close();
                }
            });
        },

        toggle: function() {
            if (this.isOpen) {
                this.close();
            } else {
                this.open();
            }
        },

        open: function() {
            this.window.addClass('open');
            this.button.addClass('open').attr('aria-expanded', 'true');
            this.isOpen = true;
            this.input.trigger('focus');
        },

        close: function() {
            this.window.removeClass('open');
            this.button.removeClass('open').attr('aria-expanded', 'false');
            this.isOpen = false;
        },

        sendMessage: function() {
            var message = this.input.val().trim();

            if (!message || this.isTyping) return;

            // Clear input
            this.input.val('').css('height', 'auto');

            // Remove welcome if present
            this.messages.find('.oraculo-chat-welcome').remove();

            // Add user message
            this.addMessage(message, 'user');

            // Show typing indicator
            this.showTyping();

            var self = this;

            $.ajax({
                url: OraculoFrontend.restUrl + 'chat',
                type: 'POST',
                contentType: 'application/json',
                headers: { 'X-WP-Nonce': OraculoFrontend.restNonce },
                data: JSON.stringify({
                    message: message,
                    session_id: this.sessionId || ''
                }),
                success: function(response) {
                    self.hideTyping();

                    if (response.success) {
                        self.sessionId = response.data.session_id;
                        self.saveSession();
                        self.addMessage(response.data.response, 'assistant', {
                            messageId: response.data.message_id,
                            sources: response.data.sources
                        });
                    } else {
                        var msg = response.error || (response.data && response.data.message) || OraculoFrontend.strings.error;
                        self.addMessage(msg, 'assistant', { isError: true });
                    }
                },
                error: function(xhr) {
                    self.hideTyping();
                    var msg = OraculoFrontend.strings.error;
                    if (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.error)) {
                        msg = xhr.responseJSON.message || xhr.responseJSON.error;
                    }
                    self.addMessage(msg, 'assistant', { isError: true });
                }
            });
        },

        addMessage: function(content, role, options) {
            options = options || {};

            // Mensagens da BIA levam o avatar ao lado do balão.
            var avatar = role === 'assistant'
                ? '<div class="oraculo-message-avatar">' + this.avatarSvg(26) + '</div>'
                : '';

            var html = `
                <div class="oraculo-chat-message ${role}${options.isError ? ' is-error' : ''}"
                     ${options.messageId ? 'data-message-id="' + escapeHtml(options.messageId) + '"' : ''}>
                    ${avatar}
                    <div class="oraculo-message-body">
                    <div class="oraculo-message-content">${this.formatMessage(content)}</div>
            `;

            // Sources
            if (options.sources && options.sources.length > 0) {
                html += '<div class="oraculo-message-sources">';
                html += '<div class="oraculo-message-sources-title">' + escapeHtml(OraculoFrontend.strings.sources) + '</div>';
                options.sources.forEach(function(source) {
                    html += '<a href="' + escapeHtml(source.url) + '" class="oraculo-message-source" target="_blank" rel="noopener noreferrer">📄 ' + escapeHtml(source.title) + '</a>';
                });
                html += '</div>';
            }

            // Feedback (only for assistant messages)
            if (role === 'assistant' && options.messageId && !options.isError) {
                html += `
                    <div class="oraculo-message-feedback">
                        <button data-feedback="positive" title="Útil" aria-label="Resposta útil">👍</button>
                        <button data-feedback="negative" title="Não útil" aria-label="Resposta não útil">👎</button>
                    </div>
                `;
            }

            html += '</div></div>';

            this.messages.append(html);
            this.scrollToBottom();
        },

        formatMessage: function(text) {
            // Escapar primeiro; a formatação markdown é aplicada sobre texto seguro.
            text = escapeHtml(text);

            // Basic markdown
            text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
            text = text.replace(/\n/g, '<br>');

            // Links — apenas esquemas seguros (http/https, relativo, âncora)
            text = text.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function(match, label, url) {
                if (!/^(https?:\/\/|\/|#)/i.test(url)) {
                    return match;
                }
                return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
            });

            return text;
        },

        showTyping: function() {
            this.isTyping = true;
            this.sendBtn.prop('disabled', true);

            var s = OraculoFrontend.strings || {};
            var html = `
                <div class="oraculo-chat-typing-row" id="oraculo-typing">
                    <div class="oraculo-message-avatar">${this.avatarSvg(26)}</div>
                    <div class="oraculo-chat-typing">
                        <span></span><span></span><span></span>
                    </div>
                    <span class="oraculo-chat-typing-label">${escapeHtml(s.typing || 'BIA está pesquisando no acervo…')}</span>
                </div>
            `;

            this.messages.append(html);
            this.scrollToBottom();
        },

        hideTyping: function() {
            this.isTyping = false;
            this.sendBtn.prop('disabled', false);
            $('#oraculo-typing').remove();
        },

        scrollToBottom: function() {
            this.messages.scrollTop(this.messages[0].scrollHeight);
        },

        sendFeedback: function(messageId, feedback, btn) {
            var container = btn.closest('.oraculo-message-feedback');

            $.ajax({
                url: OraculoFrontend.restUrl + 'feedback',
                type: 'POST',
                contentType: 'application/json',
                headers: { 'X-WP-Nonce': OraculoFrontend.restNonce },
                data: JSON.stringify({
                    message_id: messageId,
                    feedback: feedback
                }),
                success: function() {
                    container.find('button').removeClass('selected');
                    btn.addClass('selected');
                }
            });
        },

        loadSession: function() {
            try {
                this.sessionId = localStorage.getItem('oraculo_session_id');
            } catch (e) {
                // localStorage not available
            }
        },

        saveSession: function() {
            try {
                localStorage.setItem('oraculo_session_id', this.sessionId);
            } catch (e) {
                // localStorage not available
            }
        },

        newConversation: function() {
            this.sessionId = null;
            try {
                localStorage.removeItem('oraculo_session_id');
            } catch (e) {}

            this.messages.html(this.getWelcomeHtml());
        }
    };

    // Initialize
    $(document).ready(function() {
        OraculoChat.init();
    });

})(jQuery);
