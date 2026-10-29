<?php
/**
 * Aba Diagnóstico: o que deixa a busca e a BIA rápidas sob uso intenso
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file; all variables are local to this included template scope.

use Oraculo_Tainacan\Admin\Diagnostics;

$checks  = Diagnostics::checks();
$options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

$labels = array(
	'ok'      => __( 'Em ordem', 'oraculo-tainacan' ),
	'warn'    => __( 'Pode melhorar', 'oraculo-tainacan' ),
	'bad'     => __( 'Precisa de atenção', 'oraculo-tainacan' ),
	'info'    => __( 'Informação', 'oraculo-tainacan' ),
	'pending' => __( 'Não testado', 'oraculo-tainacan' ),
);
$icons  = array(
	'ok'      => 'dashicons-yes-alt',
	'warn'    => 'dashicons-warning',
	'bad'     => 'dashicons-dismiss',
	'info'    => 'dashicons-info',
	'pending' => 'dashicons-clock',
);

$scored = array_filter(
	$checks,
	static function ( $c ) {
		return in_array( $c['status'], array( 'ok', 'warn', 'bad' ), true );
	}
);
$ok     = count(
	array_filter(
		$scored,
		static function ( $c ) {
			return 'ok' === $c['status'];
		}
	)
);

// Retorno das ações (admin-post). Só leitura: o valor escolhe uma mensagem fixa.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only message selector after the nonce-checked redirect; whitelisted below.
$msg      = isset( $_GET['oraculo_msg'] ) ? sanitize_key( wp_unslash( $_GET['oraculo_msg'] ) ) : '';
$messages = array(
	'fast_on'  => array( 'success', __( 'Modo rápido ligado. Clique em "Testar agora" para conferir.', 'oraculo-tainacan' ) ),
	'fast_off' => array( 'success', __( 'Modo rápido desligado; o arquivo saiu da pasta mu-plugins.', 'oraculo-tainacan' ) ),
	'limits'   => array( 'success', __( 'Limites de uso salvos.', 'oraculo-tainacan' ) ),
);
if ( 'fast_error' === $msg ) {
	$diag_error       = (string) get_transient( 'oraculo_diag_error_' . get_current_user_id() );
	$messages[ $msg ] = array( 'error', __( 'Não foi possível ligar o modo rápido.', 'oraculo-tainacan' ) . ' ' . $diag_error );
}
?>

<div class="oraculo-diagnostics" data-probe-url="<?php echo esc_url( rest_url( 'oraculo/v1/diagnostics/probe' ) ); ?>">

	<?php if ( isset( $messages[ $msg ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $messages[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $messages[ $msg ][1] ); ?></p></div>
	<?php endif; ?>

	<div class="oraculo-card oraculo-diag-summary">
		<div class="oraculo-card-body">
			<div class="oraculo-diag-score">
				<span class="oraculo-diag-score-num"><?php echo esc_html( $ok . '/' . count( $scored ) ); ?></span>
				<span class="oraculo-diag-score-label"><?php esc_html_e( 'itens em ordem', 'oraculo-tainacan' ); ?></span>
			</div>
			<div class="oraculo-diag-intro">
				<h2><?php esc_html_e( 'O Oráculo aguenta muita gente ao mesmo tempo?', 'oraculo-tainacan' ); ?></h2>
				<p><?php esc_html_e( 'Esta tela confere o que mais pesa quando muitas pessoas usam a busca e a BIA juntas. Cada item diz o que é, como está e o que fazer. Os números vêm de um teste de carga com 40 buscas e 30 conversas simultâneas num servidor de 1 núcleo.', 'oraculo-tainacan' ); ?></p>
				<p>
					<button type="button" class="button button-primary oraculo-diag-probe" data-deep="1">
						<span class="dashicons dashicons-controls-play"></span>
						<?php esc_html_e( 'Testar agora', 'oraculo-tainacan' ); ?>
					</button>
					<span class="oraculo-diag-probe-note"><?php esc_html_e( 'Faz uma busca de teste e uma pergunta curta à IA (custo de uma pergunta) e mostra quanto cada etapa demorou.', 'oraculo-tainacan' ); ?></span>
				</p>
				<div class="oraculo-diag-probe-result" hidden aria-live="polite"></div>
			</div>
		</div>
	</div>

	<div class="oraculo-diag-list">
		<?php foreach ( $checks as $check ) : ?>
			<section class="oraculo-diag-item is-<?php echo esc_attr( $check['status'] ); ?>" data-check="<?php echo esc_attr( $check['id'] ); ?>">
				<header class="oraculo-diag-head">
					<span class="dashicons <?php echo esc_attr( $icons[ $check['status'] ] ); ?>" aria-hidden="true"></span>
					<h3><?php echo esc_html( $check['title'] ); ?></h3>
					<span class="oraculo-diag-badge"><?php echo esc_html( $labels[ $check['status'] ] ); ?></span>
					<span class="oraculo-diag-value"><?php echo esc_html( (string) $check['value'] ); ?></span>
				</header>

				<p class="oraculo-diag-text"><?php echo esc_html( $check['text'] ); ?></p>

				<?php if ( '' !== $check['how'] ) : ?>
					<p class="oraculo-diag-how"><strong><?php esc_html_e( 'O que fazer:', 'oraculo-tainacan' ); ?></strong> <?php echo esc_html( $check['how'] ); ?></p>
				<?php endif; ?>

				<?php $diag_action = $check['action']; ?>
				<?php if ( is_array( $diag_action ) && 'post' === $diag_action['type'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oraculo-diag-actions">
						<input type="hidden" name="action" value="<?php echo esc_attr( Diagnostics::ACTION ); ?>">
						<input type="hidden" name="op" value="<?php echo esc_attr( $diag_action['op'] ); ?>">
						<?php wp_nonce_field( Diagnostics::ACTION ); ?>
						<button type="submit" class="button <?php echo 'primary' === $diag_action['style'] ? 'button-primary' : ''; ?>"><?php echo esc_html( $diag_action['label'] ); ?></button>
					</form>
				<?php elseif ( is_array( $diag_action ) && 'link' === $diag_action['type'] ) : ?>
					<p class="oraculo-diag-actions"><a class="button" href="<?php echo esc_url( $diag_action['url'] ); ?>"><?php echo esc_html( $diag_action['label'] ); ?></a></p>
				<?php elseif ( is_array( $diag_action ) && 'limits' === $diag_action['type'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oraculo-diag-limits">
						<input type="hidden" name="action" value="<?php echo esc_attr( Diagnostics::ACTION ); ?>">
						<input type="hidden" name="op" value="limits">
						<?php wp_nonce_field( Diagnostics::ACTION ); ?>
						<label>
							<span><?php esc_html_e( 'Teto do site (chamadas de IA por minuto, todos somados; 0 = sem teto)', 'oraculo-tainacan' ); ?></span>
							<input type="number" min="0" max="10000" name="rate_limit_global" value="<?php echo esc_attr( (string) ( $options['rate_limit_global'] ?? 60 ) ); ?>">
						</label>
						<label>
							<span><?php esc_html_e( 'Buscas por minuto, por visitante (0 = sem limite)', 'oraculo-tainacan' ); ?></span>
							<input type="number" min="0" max="1000" name="rate_limit_search" value="<?php echo esc_attr( (string) ( $options['rate_limit_search'] ?? 10 ) ); ?>">
						</label>
						<label>
							<span><?php esc_html_e( 'Mensagens da BIA por minuto, por visitante (0 = sem limite)', 'oraculo-tainacan' ); ?></span>
							<input type="number" min="0" max="1000" name="rate_limit_chat" value="<?php echo esc_attr( (string) ( $options['rate_limit_chat'] ?? 20 ) ); ?>">
						</label>
						<label class="is-wide">
							<span><?php esc_html_e( 'Redes liberadas do limite por visitante (um IP ou faixa por linha, ex.: 200.130.10.0/24)', 'oraculo-tainacan' ); ?></span>
							<textarea name="rate_limit_allowlist" rows="3"><?php echo esc_textarea( (string) ( $options['rate_limit_allowlist'] ?? '' ) ); ?></textarea>
						</label>
						<p class="is-wide"><button type="submit" class="button button-primary"><?php esc_html_e( 'Salvar limites', 'oraculo-tainacan' ); ?></button></p>
					</form>
				<?php endif; ?>
			</section>
		<?php endforeach; ?>
	</div>

	<p class="oraculo-diag-footer">
		<?php esc_html_e( 'O guia "Uso intenso" do manual (docs/manual) traz a tabela completa do teste de carga e a receita de servidor para os processos PHP próprios.', 'oraculo-tainacan' ); ?>
	</p>
</div>
