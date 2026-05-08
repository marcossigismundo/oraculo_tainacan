<?php
/**
 * Template do Widget de Chat (Shortcode)
 *
 * @package Oraculo_Tainacan
 */

defined('ABSPATH') || exit;

$options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
$widget_id = 'oraculo-chat-embedded-' . uniqid();
$height = $atts['height'] ?? '500px';
$title = $atts['title'] ?? __('Assistente do Acervo', 'oraculo-tainacan');
$welcome_message = $options['welcome_message'] ?? __('Olá! Como posso ajudá-lo a encontrar informações no acervo?', 'oraculo-tainacan');
$suggestions = $options['suggested_questions'] ?? [];
?>

<div class="oraculo-chat-embedded" id="<?php echo esc_attr($widget_id); ?>" style="height: <?php echo esc_attr($height); ?>;">
    <div class="oraculo-chat-embedded-header">
        <div class="oraculo-chat-embedded-avatar">🔮</div>
        <div class="oraculo-chat-embedded-title"><?php echo esc_html($title); ?></div>
        <button type="button" class="oraculo-chat-embedded-clear" title="<?php esc_attr_e('Nova conversa', 'oraculo-tainacan'); ?>">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="1 4 1 10 7 10"></polyline>
                <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
            </svg>
        </button>
    </div>

    <div class="oraculo-chat-embedded-messages">
        <div class="oraculo-chat-embedded-welcome">
            <div class="welcome-icon">👋</div>
            <div class="welcome-text"><?php echo esc_html($welcome_message); ?></div>
            <?php if (!empty($suggestions)) : ?>
                <div class="welcome-suggestions">
                    <?php foreach ($suggestions as $suggestion) : ?>
                        <button type="button" class="suggestion-btn"><?php echo esc_html($suggestion); ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="oraculo-chat-embedded-input">
        <form class="oraculo-chat-embedded-form">
            <textarea
                placeholder="<?php esc_attr_e('Digite sua mensagem...', 'oraculo-tainacan'); ?>"
                rows="1"
            ></textarea>
            <button type="submit">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="22" y1="2" x2="11" y2="13"></line>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                </svg>
            </button>
        </form>
    </div>
</div>

<style>
.oraculo-chat-embedded {
    display: flex;
    flex-direction: column;
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.oraculo-chat-embedded-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 15px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
}

.oraculo-chat-embedded-avatar {
    font-size: 24px;
}

.oraculo-chat-embedded-title {
    flex: 1;
    font-weight: 600;
    font-size: 16px;
}

.oraculo-chat-embedded-clear {
    background: rgba(255,255,255,0.2);
    border: none;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    transition: background 0.2s;
}

.oraculo-chat-embedded-clear:hover {
    background: rgba(255,255,255,0.3);
}

.oraculo-chat-embedded-messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    background: #f8f9fa;
}

.oraculo-chat-embedded-welcome {
    text-align: center;
    padding: 30px 20px;
}

.oraculo-chat-embedded-welcome .welcome-icon {
    font-size: 48px;
    margin-bottom: 15px;
}

.oraculo-chat-embedded-welcome .welcome-text {
    color: #555;
    font-size: 15px;
    margin-bottom: 20px;
}

.oraculo-chat-embedded-welcome .welcome-suggestions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px;
}

.oraculo-chat-embedded-welcome .suggestion-btn {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 20px;
    padding: 8px 16px;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
}

.oraculo-chat-embedded-welcome .suggestion-btn:hover {
    background: #667eea;
    color: #fff;
    border-color: #667eea;
}

.oraculo-chat-embedded-message {
    margin-bottom: 15px;
    max-width: 85%;
    animation: slideIn 0.2s ease;
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.oraculo-chat-embedded-message.user {
    margin-left: auto;
}

.oraculo-chat-embedded-message.user .message-content {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    border-radius: 18px 18px 4px 18px;
}

.oraculo-chat-embedded-message.assistant .message-content {
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 18px 18px 18px 4px;
}

.oraculo-chat-embedded-message .message-content {
    padding: 12px 16px;
    font-size: 14px;
    line-height: 1.5;
}

.oraculo-chat-embedded-message .message-sources {
    margin-top: 10px;
    padding: 10px;
    background: #f0f0f0;
    border-radius: 8px;
    font-size: 12px;
}

.oraculo-chat-embedded-message .message-sources-title {
    font-weight: 600;
    margin-bottom: 5px;
    color: #555;
}

.oraculo-chat-embedded-message .message-source {
    display: block;
    color: #667eea;
    text-decoration: none;
    padding: 3px 0;
}

.oraculo-chat-embedded-message .message-source:hover {
    text-decoration: underline;
}

.oraculo-chat-embedded-typing {
    display: flex;
    gap: 4px;
    padding: 15px;
    background: #fff;
    border: 1px solid #e0e0e0;
    border-radius: 18px 18px 18px 4px;
    max-width: 80px;
}

.oraculo-chat-embedded-typing span {
    width: 8px;
    height: 8px;
    background: #667eea;
    border-radius: 50%;
    animation: bounce 1.4s ease-in-out infinite;
}

.oraculo-chat-embedded-typing span:nth-child(1) { animation-delay: 0s; }
.oraculo-chat-embedded-typing span:nth-child(2) { animation-delay: 0.2s; }
.oraculo-chat-embedded-typing span:nth-child(3) { animation-delay: 0.4s; }

@keyframes bounce {
    0%, 80%, 100% { transform: scale(0); }
    40% { transform: scale(1); }
}

.oraculo-chat-embedded-input {
    padding: 15px;
    background: #fff;
    border-top: 1px solid #e0e0e0;
}

.oraculo-chat-embedded-form {
    display: flex;
    gap: 10px;
    align-items: flex-end;
}

.oraculo-chat-embedded-form textarea {
    flex: 1;
    border: 1px solid #ddd;
    border-radius: 20px;
    padding: 10px 15px;
    resize: none;
    font-size: 14px;
    font-family: inherit;
    max-height: 100px;
    outline: none;
    transition: border-color 0.2s;
}

.oraculo-chat-embedded-form textarea:focus {
    border-color: #667eea;
}

.oraculo-chat-embedded-form button {
    width: 40px;
    height: 40px;
    border: none;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s;
}

.oraculo-chat-embedded-form button:hover {
    transform: scale(1.05);
}

.oraculo-chat-embedded-form button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

<script>
(function() {
    var widget = document.getElementById('<?php echo esc_js($widget_id); ?>');
    var form = widget.querySelector('.oraculo-chat-embedded-form');
    var textarea = form.querySelector('textarea');
    var submitBtn = form.querySelector('button');
    var messages = widget.querySelector('.oraculo-chat-embedded-messages');
    var welcome = widget.querySelector('.oraculo-chat-embedded-welcome');
    var clearBtn = widget.querySelector('.oraculo-chat-embedded-clear');
    var sessionId = null;
    var isTyping = false;

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

    // Suggestion click
    widget.querySelectorAll('.suggestion-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            textarea.value = this.textContent;
            form.dispatchEvent(new Event('submit'));
        });
    });

    // Clear chat
    clearBtn.addEventListener('click', function() {
        sessionId = null;
        messages.innerHTML = welcome.outerHTML;
        try {
            localStorage.removeItem('oraculo_embedded_session');
        } catch(e) {}
    });

    // Load session
    try {
        sessionId = localStorage.getItem('oraculo_embedded_session');
    } catch(e) {}

    // Submit
    form.addEventListener('submit', function(e) {
        e.preventDefault();

        var message = textarea.value.trim();
        if (!message || isTyping) return;

        // Hide welcome
        if (welcome) {
            welcome.remove();
            welcome = null;
        }

        // Add user message
        addMessage(message, 'user');

        // Clear input
        textarea.value = '';
        textarea.style.height = 'auto';

        // Show typing
        showTyping();

        fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                action: 'oraculo_chat',
                nonce: '<?php echo esc_js( wp_create_nonce('oraculo_frontend') ); ?>',
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
                } catch(e) {}

                addMessage(data.data.response, 'assistant', {
                    sources: data.data.sources
                });
            } else {
                addMessage(data.data.message || '<?php esc_html_e('Ocorreu um erro. Tente novamente.', 'oraculo-tainacan'); ?>', 'assistant');
            }
        })
        .catch(function() {
            hideTyping();
            addMessage('<?php esc_html_e('Erro de conexão. Tente novamente.', 'oraculo-tainacan'); ?>', 'assistant');
        });
    });

    function addMessage(content, role, options) {
        options = options || {};

        var div = document.createElement('div');
        div.className = 'oraculo-chat-embedded-message ' + role;

        var html = '<div class="message-content">' + formatText(content) + '</div>';

        if (options.sources && options.sources.length > 0) {
            html += '<div class="message-sources">';
            html += '<div class="message-sources-title"><?php esc_html_e('Fontes:', 'oraculo-tainacan'); ?></div>';
            options.sources.forEach(function(source) {
                html += '<a href="' + escapeHtml(source.url) + '" class="message-source" target="_blank">📄 ' + escapeHtml(source.title) + '</a>';
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
        div.id = 'typing-indicator';
        div.innerHTML = '<span></span><span></span><span></span>';
        messages.appendChild(div);
        scrollToBottom();
    }

    function hideTyping() {
        isTyping = false;
        submitBtn.disabled = false;
        var typing = document.getElementById('typing-indicator');
        if (typing) typing.remove();
    }

    function scrollToBottom() {
        messages.scrollTop = messages.scrollHeight;
    }

    function formatText(text) {
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
        text = text.replace(/\n/g, '<br>');
        text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank">$1</a>');
        return text;
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
</script>
