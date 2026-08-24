<?php
/**
 * Template do Widget de Chat (Shortcode)
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file; all variables are local to this included template scope.

$options         = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
$widget_id       = 'oraculo-chat-embedded-' . uniqid();
$height          = $atts['height'] ?? '500px';
$title           = $atts['title'] ?? __( 'BIA', 'oraculo-tainacan' );
$assistant_role  = __( 'Bibliotecária de IA', 'oraculo-tainacan' );
$welcome_message = $options['welcome_message'] ?? __( 'Oi! Eu sou a BIA, a bibliotecária de IA deste acervo. Posso ajudar a encontrar obras, autores e assuntos — é só perguntar. 📚', 'oraculo-tainacan' );
$suggestions     = $options['suggested_questions'] ?? array();

// Avatar da BIA: monograma-livro em círculo com gradiente (inline, sem asset externo).
$bia_avatar = static function ( int $size ): string {
	return '<svg viewBox="0 0 40 40" width="' . $size . '" height="' . $size . '" role="img" aria-hidden="true">
		<defs><linearGradient id="oraculo-bia-grad-embedded" x1="0" y1="0" x2="1" y2="1">
			<stop offset="0" stop-color="var(--oraculo-primary, #1f2f56)"/>
			<stop offset="1" stop-color="var(--oraculo-accent, #b5e0e3)"/>
		</linearGradient></defs>
		<circle cx="20" cy="20" r="20" fill="url(#oraculo-bia-grad-embedded)"/>
		<path d="M11 13.5c0-1 .8-1.8 1.8-1.8h4.4c1.5 0 2.8.9 2.8 2.4v11.4c-.7-.9-1.8-1.4-3-1.4h-4.2c-1 0-1.8-.8-1.8-1.8v-8.8zm18 0c0-1-.8-1.8-1.8-1.8h-4.4c-1.5 0-2.8.9-2.8 2.4v11.4c.7-.9 1.8-1.4 3-1.4h4.2c1 0 1.8-.8 1.8-1.8v-8.8z" fill="none" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/>
		<circle cx="20" cy="30.6" r="1.4" fill="#fff"/>
	</svg>';
};
?>

<div class="oraculo-chat-embedded" id="<?php echo esc_attr( $widget_id ); ?>" style="height: <?php echo esc_attr( $height ); ?>;">
	<div class="oraculo-chat-embedded-header">
		<div class="oraculo-chat-embedded-avatar">
			<?php echo $bia_avatar( 38 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG estático montado localmente com $size inteiro; nada de input externo. ?>
		</div>
		<div class="oraculo-chat-embedded-identity">
			<div class="oraculo-chat-embedded-title"><?php echo esc_html( $title ); ?></div>
			<div class="oraculo-chat-embedded-role">
				<span class="oraculo-chat-embedded-status" aria-hidden="true"></span>
				<?php echo esc_html( $assistant_role ); ?> · <?php esc_html_e( 'online', 'oraculo-tainacan' ); ?>
			</div>
		</div>
		<button type="button" class="oraculo-chat-embedded-clear" title="<?php esc_attr_e( 'Nova conversa', 'oraculo-tainacan' ); ?>" aria-label="<?php esc_attr_e( 'Nova conversa', 'oraculo-tainacan' ); ?>">
			<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
				<polyline points="1 4 1 10 7 10"></polyline>
				<path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
			</svg>
		</button>
	</div>

	<div class="oraculo-chat-embedded-messages" role="log" aria-live="polite">
		<div class="oraculo-chat-embedded-welcome">
			<div class="welcome-avatar">
				<?php echo $bia_avatar( 64 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG estático montado localmente com $size inteiro; nada de input externo. ?>
			</div>
			<div class="welcome-name"><?php echo esc_html( $title ); ?></div>
			<div class="welcome-text"><?php echo esc_html( $welcome_message ); ?></div>
			<?php if ( ! empty( $suggestions ) ) : ?>
				<div class="welcome-suggestions">
					<?php foreach ( $suggestions as $suggestion ) : ?>
						<button type="button" class="suggestion-btn"><?php echo esc_html( $suggestion ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="oraculo-chat-embedded-input">
		<form class="oraculo-chat-embedded-form">
			<textarea
				placeholder="<?php esc_attr_e( 'Pergunte à BIA sobre o acervo…', 'oraculo-tainacan' ); ?>"
				aria-label="<?php esc_attr_e( 'Pergunte à BIA sobre o acervo…', 'oraculo-tainacan' ); ?>"
				rows="1"
			></textarea>
			<button type="submit" aria-label="<?php esc_attr_e( 'Enviar', 'oraculo-tainacan' ); ?>">
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
					<line x1="22" y1="2" x2="11" y2="13"></line>
					<polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
				</svg>
			</button>
		</form>
	</div>
</div>

<style>
/* Chat da BIA (shortcode) — mesmo sistema visual do widget flutuante:
   claro, limpo, cores das opções de aparência via --oraculo-primary/accent. */
.oraculo-chat-embedded {
	--bia-primary: var(--oraculo-primary, #1f2f56);
	--bia-accent: var(--oraculo-accent, #b5e0e3);
	--bia-surface: #ffffff;
	--bia-canvas: #f6f8fa;
	--bia-border: #e6e9ee;
	--bia-text: #1c2733;
	--bia-text-soft: #5c6b7a;
	--bia-online: #2fbf71;
	display: flex;
	flex-direction: column;
	border: 1px solid var(--bia-border);
	border-radius: 16px;
	overflow: hidden;
	background: var(--bia-canvas);
	font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.oraculo-chat-embedded-header {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 14px 16px;
	background: var(--bia-surface);
	border-bottom: 1px solid var(--bia-border);
}

.oraculo-chat-embedded-avatar { display: flex; flex-shrink: 0; }

.oraculo-chat-embedded-identity { flex: 1; min-width: 0; }

.oraculo-chat-embedded-title {
	font-weight: 700;
	font-size: 16px;
	letter-spacing: 0.02em;
	color: var(--bia-text);
	line-height: 1.2;
}

.oraculo-chat-embedded-role {
	margin-top: 2px;
	font-size: 12px;
	color: var(--bia-text-soft);
	display: flex;
	align-items: center;
	gap: 6px;
}

.oraculo-chat-embedded-status {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	background: var(--bia-online);
}

.oraculo-chat-embedded-clear {
	background: transparent;
	border: none;
	border-radius: 50%;
	width: 34px;
	height: 34px;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	color: var(--bia-text-soft);
	transition: background 0.15s, color 0.15s;
}

.oraculo-chat-embedded-clear:hover {
	background: var(--bia-canvas);
	color: var(--bia-primary);
}

.oraculo-chat-embedded-messages {
	flex: 1;
	overflow-y: auto;
	padding: 18px 16px;
	background: var(--bia-canvas);
}

.oraculo-chat-embedded-welcome {
	text-align: center;
	padding: 26px 16px 10px;
}

.oraculo-chat-embedded-welcome .welcome-avatar {
	display: flex;
	justify-content: center;
	margin-bottom: 10px;
}

.oraculo-chat-embedded-welcome .welcome-name {
	font-size: 18px;
	font-weight: 700;
	letter-spacing: 0.04em;
	color: var(--bia-text);
	margin-bottom: 6px;
}

.oraculo-chat-embedded-welcome .welcome-text {
	color: var(--bia-text-soft);
	font-size: 14px;
	line-height: 1.55;
	max-width: 320px;
	margin: 0 auto 18px;
}

.oraculo-chat-embedded-welcome .welcome-suggestions {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	gap: 8px;
}

.oraculo-chat-embedded-welcome .suggestion-btn {
	background: var(--bia-surface);
	border: 1px solid var(--bia-border);
	border-radius: 999px;
	padding: 8px 14px;
	font-size: 13px;
	color: var(--bia-text);
	cursor: pointer;
	transition: border-color 0.15s, box-shadow 0.15s, transform 0.15s;
}

.oraculo-chat-embedded-welcome .suggestion-btn:hover {
	border-color: var(--bia-primary);
	box-shadow: 0 2px 8px rgba(16, 24, 40, 0.08);
	transform: translateY(-1px);
}

.oraculo-chat-embedded-message {
	margin-bottom: 12px;
	max-width: 85%;
	animation: biaEmbSlideIn 0.2s ease;
}

@keyframes biaEmbSlideIn {
	from { opacity: 0; transform: translateY(8px); }
	to { opacity: 1; transform: translateY(0); }
}

.oraculo-chat-embedded-message.user {
	margin-left: auto;
}

.oraculo-chat-embedded-message.user .message-content {
	background: var(--bia-primary);
	color: #fff;
	border-radius: 16px 16px 4px 16px;
}

.oraculo-chat-embedded-message.assistant .message-content {
	background: var(--bia-surface);
	color: var(--bia-text);
	border: 1px solid var(--bia-border);
	border-radius: 4px 16px 16px 16px;
}

.oraculo-chat-embedded-message .message-content {
	padding: 10px 14px;
	font-size: 14px;
	line-height: 1.55;
}

.oraculo-chat-embedded-message .message-sources {
	margin-top: 8px;
	padding: 10px 12px;
	background: var(--bia-surface);
	border: 1px solid var(--bia-border);
	border-radius: 12px;
	font-size: 12px;
}

.oraculo-chat-embedded-message .message-sources-title {
	font-weight: 600;
	margin-bottom: 6px;
	color: var(--bia-text-soft);
	text-transform: uppercase;
	font-size: 11px;
	letter-spacing: 0.04em;
}

.oraculo-chat-embedded-message .message-source {
	display: block;
	color: var(--bia-primary);
	text-decoration: none;
	padding: 3px 0;
}

.oraculo-chat-embedded-message .message-source:hover {
	text-decoration: underline;
}

.oraculo-chat-embedded-typing {
	display: flex;
	gap: 4px;
	padding: 12px 14px;
	background: var(--bia-surface);
	border: 1px solid var(--bia-border);
	border-radius: 4px 16px 16px 16px;
	max-width: 80px;
}

.oraculo-chat-embedded-typing span {
	width: 7px;
	height: 7px;
	background: var(--bia-primary);
	border-radius: 50%;
	animation: biaEmbBounce 1.3s ease-in-out infinite;
}

.oraculo-chat-embedded-typing span:nth-child(2) { animation-delay: 0.18s; }
.oraculo-chat-embedded-typing span:nth-child(3) { animation-delay: 0.36s; }

@keyframes biaEmbBounce {
	0%, 80%, 100% { transform: scale(0.4); opacity: 0.4; }
	40% { transform: scale(1); opacity: 1; }
}

.oraculo-chat-embedded-input {
	padding: 12px 14px;
	background: var(--bia-surface);
	border-top: 1px solid var(--bia-border);
}

.oraculo-chat-embedded-form {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.oraculo-chat-embedded-form textarea {
	flex: 1;
	border: 1px solid var(--bia-border);
	border-radius: 20px;
	padding: 10px 16px;
	resize: none;
	font-size: 14px;
	font-family: inherit;
	line-height: 1.4;
	color: var(--bia-text);
	background: var(--bia-canvas);
	max-height: 120px;
	outline: none;
	transition: border-color 0.15s, box-shadow 0.15s;
}

.oraculo-chat-embedded-form textarea:focus {
	border-color: var(--bia-primary);
	background: var(--bia-surface);
}

.oraculo-chat-embedded-form button {
	width: 42px;
	height: 42px;
	border: none;
	border-radius: 50%;
	background: var(--bia-primary);
	color: #fff;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	transition: transform 0.15s, opacity 0.15s;
}

.oraculo-chat-embedded-form button:hover:not(:disabled) {
	transform: scale(1.06);
}

.oraculo-chat-embedded-form button:disabled {
	opacity: 0.45;
	cursor: not-allowed;
}
</style>

<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
