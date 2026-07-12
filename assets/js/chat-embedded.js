/**
 * Oráculo Tainacan - Chat embutido (shortcode [oraculo_chat])
 *
 * Extraído do inline <script> de templates/chat-widget.php.
 * Config/i18n via objeto localizado OraculoChatEmbedded.
 * Persiste via REST oraculo/v1/chat com nonce wp_rest.
 */

(function() {
	'use strict';

	var cfg = window.OraculoChatEmbedded || {};
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

	// Formata texto com markdown básico (sobre texto já escapado).
	function formatText(text) {
		text = escapeHtml(text);
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
	}

	function initWidget(widget) {
		var form = widget.querySelector('.oraculo-chat-embedded-form');
		var textarea = form ? form.querySelector('textarea') : null;
		var submitBtn = form ? form.querySelector('button') : null;
		var messages = widget.querySelector('.oraculo-chat-embedded-messages');
		var welcome = widget.querySelector('.oraculo-chat-embedded-welcome');
		var clearBtn = widget.querySelector('.oraculo-chat-embedded-clear');
		var sessionId = null;
		var isTyping = false;

		if (!form || !textarea || !messages) {
			return;
		}

		// Auto-resize textarea
		textarea.addEventListener('input', function() {
			this.style.height = 'auto';
			this.style.height = Math.min(this.scrollHeight, 100) + 'px';
		});

		// Enter to send
		textarea.addEventListener('keydown', function(e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				form.dispatchEvent(new Event('submit'));
			}
		});

		// Suggestion click — delegado em messages: sobrevive à reinjeção do welcome.
		messages.addEventListener('click', function(e) {
			var btn = e.target.closest('.suggestion-btn');
			if (!btn) return;
			textarea.value = btn.textContent;
			form.dispatchEvent(new Event('submit'));
		});

		// Clear chat
		if (clearBtn) {
			clearBtn.addEventListener('click', function() {
				sessionId = null;
				if (welcome) {
					messages.innerHTML = welcome.outerHTML;
				} else {
					messages.innerHTML = '';
				}
				try {
					localStorage.removeItem('oraculo_embedded_session');
				} catch (e) {}
			});
		}

		// Load session
		try {
			sessionId = localStorage.getItem('oraculo_embedded_session');
		} catch (e) {}

		// Submit
		form.addEventListener('submit', function(e) {
			e.preventDefault();

			var message = textarea.value.trim();
			if (!message || isTyping) return;

			// Hide welcome
			var currentWelcome = messages.querySelector('.oraculo-chat-embedded-welcome');
			if (currentWelcome) {
				currentWelcome.remove();
			}

			// Add user message
			addMessage(message, 'user');

			// Clear input
			textarea.value = '';
			textarea.style.height = 'auto';

			// Show typing
			showTyping();

			fetch(cfg.chatUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.restNonce
				},
				body: JSON.stringify({
					message: message,
					session_id: sessionId || ''
				})
			})
			.then(function(response) { return response.json(); })
			.then(function(data) {
				hideTyping();

				if (data.success) {
					sessionId = data.data.session_id;
					try {
						localStorage.setItem('oraculo_embedded_session', sessionId);
					} catch (e) {}

					addMessage(data.data.response, 'assistant', {
						sources: data.data.sources
					});
				} else {
					// REST usa "error"; wp_send_json_error usa data.message.
					var msg = data.error || (data.data && data.data.message) || data.message || i18n.error;
					addMessage(msg, 'assistant');
				}
			})
			.catch(function() {
				hideTyping();
				addMessage(i18n.connectionError, 'assistant');
			});
		});

		function addMessage(content, role, options) {
			options = options || {};

			var div = document.createElement('div');
			div.className = 'oraculo-chat-embedded-message ' + role;

			var html = '<div class="message-content">' + formatText(content) + '</div>';

			if (options.sources && options.sources.length > 0) {
				html += '<div class="message-sources">';
				html += '<div class="message-sources-title">' + escapeHtml(i18n.sources) + '</div>';
				options.sources.forEach(function(source) {
					html += '<a href="' + escapeHtml(source.url) + '" class="message-source" target="_blank" rel="noopener noreferrer">📄 ' + escapeHtml(source.title) + '</a>';
				});
				html += '</div>';
			}

			div.innerHTML = html;
			messages.appendChild(div);
			scrollToBottom();
		}

		function showTyping() {
			isTyping = true;
			submitBtn.disabled = true;

			var div = document.createElement('div');
			div.className = 'oraculo-chat-embedded-typing';
			div.innerHTML = '<span></span><span></span><span></span>';
			messages.appendChild(div);
			scrollToBottom();
		}

		function hideTyping() {
			isTyping = false;
			submitBtn.disabled = false;
			var typing = messages.querySelector('.oraculo-chat-embedded-typing');
			if (typing) typing.remove();
		}

		function scrollToBottom() {
			messages.scrollTop = messages.scrollHeight;
		}
	}

	function initAll() {
		document.querySelectorAll('.oraculo-chat-embedded').forEach(initWidget);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAll);
	} else {
		initAll();
	}
})();
