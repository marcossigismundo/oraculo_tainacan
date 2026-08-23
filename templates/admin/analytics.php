<?php
/**
 * Template de Analytics
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file; all variables are local to this included template scope.

$analytics = new \Oraculo_Tainacan\Analytics\AnalyticsManager();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- period is a read-only filter parameter with no side effects; value is whitelisted below.
$period_raw      = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : 'month';
$allowed_periods = array( 'today', 'week', 'month', 'year', 'all' );
$period          = in_array( $period_raw, $allowed_periods, true ) ? $period_raw : 'month';
$stats           = $analytics->get_stats( $period );
// Timeline removido - causava problemas de performance com Chart.js
$top_searches    = $analytics->get_top_searches( $period, 15 );
$failed_searches = $analytics->get_failed_searches( $period, 10 );
$model_usage     = $analytics->get_model_usage( $period );
$cost_estimate   = $analytics->get_cost_estimate( $period );
$by_collection   = $analytics->get_stats_by_collection( $period );
?>

<div class="oraculo-analytics-content">

	<!-- Filtros -->
	<div class="oraculo-filters">
		<form method="get">
			<input type="hidden" name="page" value="oraculo-analytics">
			<select name="period" onchange="this.form.submit()">
				<option value="today" <?php selected( $period, 'today' ); ?>><?php esc_html_e( 'Hoje', 'oraculo-tainacan' ); ?></option>
				<option value="week" <?php selected( $period, 'week' ); ?>><?php esc_html_e( 'Última Semana', 'oraculo-tainacan' ); ?></option>
				<option value="month" <?php selected( $period, 'month' ); ?>><?php esc_html_e( 'Último Mês', 'oraculo-tainacan' ); ?></option>
				<option value="year" <?php selected( $period, 'year' ); ?>><?php esc_html_e( 'Último Ano', 'oraculo-tainacan' ); ?></option>
				<option value="all" <?php selected( $period, 'all' ); ?>><?php esc_html_e( 'Todo Período', 'oraculo-tainacan' ); ?></option>
			</select>
			<button type="button" class="button" id="oraculo-export-analytics" data-period="<?php echo esc_attr( $period ); ?>">
				<?php esc_html_e( 'Exportar Dados', 'oraculo-tainacan' ); ?>
			</button>
		</form>
	</div>

	<!-- Cards de Estatísticas -->
	<div class="oraculo-stats-grid">
		<div class="oraculo-stat-card">
			<span class="stat-icon">🔍</span>
			<div class="stat-content">
				<span class="stat-value"><?php echo number_format( (int) $stats['total_searches'] ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Total de Buscas', 'oraculo-tainacan' ); ?></span>
			</div>
		</div>
		<div class="oraculo-stat-card">
			<span class="stat-icon">👤</span>
			<div class="stat-content">
				<span class="stat-value"><?php echo number_format( (int) $stats['unique_users'] ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Usuários Únicos', 'oraculo-tainacan' ); ?></span>
			</div>
		</div>
		<div class="oraculo-stat-card success">
			<span class="stat-icon">✓</span>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( $stats['success_rate'] ); ?>%</span>
				<span class="stat-label"><?php esc_html_e( 'Taxa de Sucesso', 'oraculo-tainacan' ); ?></span>
			</div>
		</div>
		<div class="oraculo-stat-card">
			<span class="stat-icon">😊</span>
			<div class="stat-content">
				<span class="stat-value"><?php echo esc_html( $stats['satisfaction_rate'] ); ?>%</span>
				<span class="stat-label"><?php esc_html_e( 'Satisfação', 'oraculo-tainacan' ); ?></span>
			</div>
		</div>
		<div class="oraculo-stat-card">
			<span class="stat-icon">⚡</span>
			<div class="stat-content">
				<span class="stat-value"><?php echo number_format( (float) $stats['avg_response_time_ms'] ); ?>ms</span>
				<span class="stat-label"><?php esc_html_e( 'Tempo Médio', 'oraculo-tainacan' ); ?></span>
			</div>
		</div>
		<div class="oraculo-stat-card">
			<span class="stat-icon">🪙</span>
			<div class="stat-content">
				<span class="stat-value"><?php echo number_format( (int) $stats['total_tokens'] ); ?></span>
				<span class="stat-label"><?php esc_html_e( 'Tokens Usados', 'oraculo-tainacan' ); ?></span>
			</div>
		</div>
	</div>

	<div class="oraculo-analytics-grid">
		<!-- Top Buscas -->
		<div class="oraculo-card">
			<h2><?php esc_html_e( 'Buscas Mais Frequentes', 'oraculo-tainacan' ); ?></h2>
			<?php if ( empty( $top_searches ) ) : ?>
				<p class="oraculo-empty"><?php esc_html_e( 'Sem dados no período.', 'oraculo-tainacan' ); ?></p>
			<?php else : ?>
				<table class="oraculo-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Busca', 'oraculo-tainacan' ); ?></th>
							<th><?php esc_html_e( 'Qtd', 'oraculo-tainacan' ); ?></th>
							<th><?php esc_html_e( 'Feedback', 'oraculo-tainacan' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $top_searches as $search ) : ?>
							<tr>
								<td title="<?php echo esc_attr( $search['query_text'] ); ?>">
									<?php echo esc_html( wp_trim_words( $search['query_text'], 6 ) ); ?>
								</td>
								<td><?php echo number_format( (int) $search['count'] ); ?></td>
								<td>
									<span class="feedback-positive">👍 <?php echo (int) $search['positive']; ?></span>
									<span class="feedback-negative">👎 <?php echo (int) $search['negative']; ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<!-- Buscas Sem Resultado -->
		<div class="oraculo-card">
			<h2><?php esc_html_e( 'Buscas Sem Resultados', 'oraculo-tainacan' ); ?></h2>
			<?php if ( empty( $failed_searches ) ) : ?>
				<p class="oraculo-empty"><?php esc_html_e( 'Nenhuma busca sem resultados.', 'oraculo-tainacan' ); ?></p>
			<?php else : ?>
				<table class="oraculo-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Busca', 'oraculo-tainacan' ); ?></th>
							<th><?php esc_html_e( 'Ocorrências', 'oraculo-tainacan' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $failed_searches as $search ) : ?>
							<tr>
								<td title="<?php echo esc_attr( $search['query_text'] ); ?>">
									<?php echo esc_html( wp_trim_words( $search['query_text'], 8 ) ); ?>
								</td>
								<td><?php echo number_format( (int) $search['count'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="oraculo-hint">
					<?php esc_html_e( 'Considere adicionar conteúdo relacionado a essas buscas.', 'oraculo-tainacan' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<!-- Uso por Coleção -->
		<div class="oraculo-card">
			<h2><?php esc_html_e( 'Buscas por Coleção', 'oraculo-tainacan' ); ?></h2>
			<?php if ( empty( $by_collection ) ) : ?>
				<p class="oraculo-empty"><?php esc_html_e( 'Sem dados no período.', 'oraculo-tainacan' ); ?></p>
			<?php else : ?>
				<canvas id="collectionChart" height="200"
					data-collections="<?php echo esc_attr( wp_json_encode( $by_collection ) ); ?>"></canvas>
			<?php endif; ?>
		</div>

		<!-- Uso de Modelos -->
		<div class="oraculo-card">
			<h2><?php esc_html_e( 'Uso de Modelos IA', 'oraculo-tainacan' ); ?></h2>
			<?php if ( empty( $model_usage ) ) : ?>
				<p class="oraculo-empty"><?php esc_html_e( 'Sem dados no período.', 'oraculo-tainacan' ); ?></p>
			<?php else : ?>
				<table class="oraculo-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Modelo', 'oraculo-tainacan' ); ?></th>
							<th><?php esc_html_e( 'Usos', 'oraculo-tainacan' ); ?></th>
							<th><?php esc_html_e( 'Tokens', 'oraculo-tainacan' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $model_usage as $usage ) : ?>
							<tr>
								<td><?php echo esc_html( $usage['model_used'] ); ?></td>
								<td><?php echo number_format( (int) $usage['count'] ); ?></td>
								<td><?php echo number_format( (int) $usage['total_tokens'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<!-- Estimativa de Custo -->
		<div class="oraculo-card">
			<h2><?php esc_html_e( 'Estimativa de Custos', 'oraculo-tainacan' ); ?></h2>
			<div class="cost-estimate">
				<div class="cost-total">
					<span class="cost-value">$<?php echo number_format( (float) $cost_estimate['total_cost_usd'], 4 ); ?></span>
					<span class="cost-label"><?php esc_html_e( 'Custo Estimado (USD)', 'oraculo-tainacan' ); ?></span>
				</div>
				<?php if ( ! empty( $cost_estimate['by_model'] ) ) : ?>
					<div class="cost-breakdown">
						<h4><?php esc_html_e( 'Por Modelo:', 'oraculo-tainacan' ); ?></h4>
						<ul>
							<?php foreach ( $cost_estimate['by_model'] as $model ) : ?>
								<li>
									<strong><?php echo esc_html( $model['model'] ); ?>:</strong>
									$<?php echo number_format( (float) $model['estimated_cost'], 4 ); ?>
									(<?php echo number_format( (int) $model['tokens'] ); ?> tokens)
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>

<style>
.oraculo-filters {
	margin: 20px 0;
	display: flex;
	gap: 10px;
	align-items: center;
}

.oraculo-stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 15px;
	margin: 20px 0;
}

.oraculo-stat-card {
	background: #fff;
	border: 1px solid #ccd0d4;
	border-radius: 8px;
	padding: 20px;
	display: flex;
	align-items: center;
	gap: 15px;
}

.oraculo-stat-card .stat-icon {
	font-size: 28px;
}

.oraculo-stat-card .stat-content {
	flex: 1;
}

.oraculo-stat-card .stat-value {
	display: block;
	font-size: 24px;
	font-weight: bold;
	color: #1e1e1e;
}

.oraculo-stat-card .stat-label {
	display: block;
	font-size: 12px;
	color: #757575;
}

.oraculo-stat-card.success { border-left: 4px solid #46b450; }

.oraculo-analytics-grid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 20px;
	margin-top: 20px;
}

.oraculo-card {
	background: #fff;
	border: 1px solid #ccd0d4;
	border-radius: 8px;
	padding: 20px;
}

.oraculo-card.full-width {
	grid-column: 1 / -1;
}

.oraculo-card h2 {
	margin: 0 0 15px 0;
	font-size: 16px;
	border-bottom: 1px solid #eee;
	padding-bottom: 10px;
}

.oraculo-table {
	width: 100%;
	border-collapse: collapse;
}

.oraculo-table th,
.oraculo-table td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid #eee;
	font-size: 13px;
}

.oraculo-table th {
	font-weight: 600;
	color: #555;
}

.feedback-positive { color: #46b450; margin-right: 10px; }
.feedback-negative { color: #dc3232; }

.oraculo-empty {
	color: #888;
	font-style: italic;
	text-align: center;
	padding: 20px;
}

.oraculo-hint {
	background: #fff8e5;
	border-left: 4px solid #ffb900;
	padding: 10px;
	margin-top: 15px;
	font-size: 12px;
}

.cost-estimate {
	text-align: center;
}

.cost-total {
	margin-bottom: 20px;
}

.cost-total .cost-value {
	display: block;
	font-size: 36px;
	font-weight: bold;
	color: #2271b1;
}

.cost-total .cost-label {
	display: block;
	color: #666;
	font-size: 12px;
}

.cost-breakdown {
	text-align: left;
	background: #f9f9f9;
	padding: 15px;
	border-radius: 5px;
}

.cost-breakdown h4 {
	margin: 0 0 10px 0;
	font-size: 13px;
}

.cost-breakdown ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.cost-breakdown li {
	padding: 5px 0;
	font-size: 12px;
	border-bottom: 1px solid #eee;
}

@media (max-width: 1200px) {
	.oraculo-analytics-grid {
		grid-template-columns: 1fr;
	}
}
</style>

<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
