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

        createWidget: function() {
            var position = OraculoFrontend.chatPosition || 'bottom-right';

            // Chat Button
            var buttonHtml = `
                <button class="oraculo-chat-button ${position}" id="oraculo-chat-toggle">
                    <svg class="chat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                    </svg>
                    <svg class="close-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            `;

            // Chat Window
            var windowHtml = `
                <div class="oraculo-chat-window ${position}" id="oraculo-chat-window">
                    <div class="oraculo-chat-header">
                        <div class="oraculo-chat-avatar">🔮</div>
                        <div>
                            <h3 class="oraculo-chat-title">Assistente do Acervo</h3>
                            <p class="oraculo-chat-subtitle">Sempre disponível para ajudar</p>
                        </div>
                        <button class="oraculo-chat-close" id="oraculo-chat-close">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <div class="oraculo-chat-messages" id="oraculo-chat-messages">
                        ${this.getWelcomeHtml()}
                    </div>
                    <div class="oraculo-chat-input-container">
                        <form class="oraculo-chat-input-form" id="oraculo-chat-form">
                            <textarea class="oraculo-chat-input" id="oraculo-chat-input"
                                placeholder="${escapeHtml(OraculoFrontend.strings.placeholder)}"
                                rows="1"></textarea>
                            <button type="submit" class="oraculo-chat-send" id="oraculo-chat-send">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
        },

        getWelcomeHtml: function() {
            var welcomeMessage = OraculoFrontend.welcomeMessage || 'Olá! Como posso ajudá-lo a encontrar informações no acervo?';
            var suggestions = OraculoFrontend.suggestedQuestions || [];

            var html = `
                <div class="oraculo-chat-welcome">
                    <div class="oraculo-chat-welcome-icon">👋</div>
                    <div class="oraculo-chat-welcome-text">${escapeHtml(welcomeMessage)}</div>
                    ${suggestions.length > 0 ? '<div class="oraculo-chat-suggestions">' +
                        suggestions.map(s => `<button type="button" class="oraculo-chat-suggestion">${escapeHtml(s)}</button>`).join('') +
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
            this.button.addClass('open');
            this.isOpen = true;
            this.input.focus();
        },

        close: function() {
            this.window.removeClass('open');
            this.button.removeClass('open');
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

            var html = `
                <div class="oraculo-chat-message ${role}"
                     ${options.messageId ? 'data-message-id="' + escapeHtml(options.messageId) + '"' : ''}>
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
                        <button data-feedback="positive" title="Útil">👍</button>
                        <button data-feedback="negative" title="Não útil">👎</button>
                    </div>
                `;
            }

            html += '</div>';

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

            var html = `
                <div class="oraculo-chat-typing" id="oraculo-typing">
                    <span></span>
                    <span></span>
                    <span></span>
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
