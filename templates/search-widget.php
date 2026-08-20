<?php
/**
 * Template do Widget de Busca - Design Moderno
 * Interface auto-explicativa para busca em linguagem natural
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file; all variables are local to this included template scope.

$options                = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
$collections            = \Oraculo_Tainacan\get_tainacan_collections();
$widget_id              = 'oraculo-search-' . uniqid();
$show_collection_filter = ! empty( $atts['show_collections'] );
$placeholder            = $atts['placeholder'] ?? __( 'Faça uma pergunta sobre o acervo em linguagem natural...', 'oraculo-tainacan' );
$button_text            = $atts['button_text'] ?? __( 'Buscar', 'oraculo-tainacan' );
$suggested_questions    = $options['suggested_questions'] ?? array();

// Exemplos padrão se não houver perguntas configuradas
if ( empty( $suggested_questions ) ) {
	$suggested_questions = array(
		__( 'Quais documentos falam sobre preservação digital?', 'oraculo-tainacan' ),
		__( 'Encontre itens relacionados a arquivos históricos', 'oraculo-tainacan' ),
		__( 'O que há no acervo sobre fotografia do século XX?', 'oraculo-tainacan' ),
		__( 'Liste obras de arte em cerâmica', 'oraculo-tainacan' ),
	);
}
?>

<div class="oraculo-search-page" id="<?php echo esc_attr( $widget_id ); ?>">
	<!-- Hero Section -->
	<section class="oraculo-search-hero">
		<div class="oraculo-hero-content">
			<div class="oraculo-hero-badge">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<path d="M12 2L2 7l10 5 10-5-10-5z"/>
					<path d="M2 17l10 5 10-5"/>
					<path d="M2 12l10 5 10-5"/>
				</svg>
				<span><?php esc_html_e( 'Busca Inteligente com IA', 'oraculo-tainacan' ); ?></span>
			</div>

			<h1 class="oraculo-hero-title">
				<?php esc_html_e( 'Explore o Acervo com Linguagem Natural', 'oraculo-tainacan' ); ?>
			</h1>

			<p class="oraculo-hero-subtitle">
				<?php esc_html_e( 'Faça perguntas como se estivesse conversando com um especialista. Nossa inteligência artificial compreende o contexto e encontra as informações mais relevantes para você.', 'oraculo-tainacan' ); ?>
			</p>
		</div>
	</section>

	<!-- Search Container -->
	<div class="oraculo-search-container">
		<div class="oraculo-search-box">
			<form class="oraculo-search-form" role="search">
				<div class="oraculo-search-input-group">
					<input type="text"
							class="oraculo-search-input"
							name="oraculo_query"
							placeholder="<?php echo esc_attr( $placeholder ); ?>"
							aria-label="<?php esc_attr_e( 'Buscar no acervo', 'oraculo-tainacan' ); ?>"
							autocomplete="off">
					<button type="submit" class="oraculo-search-button">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<circle cx="11" cy="11" r="8"></circle>
							<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
						</svg>
						<span><?php echo esc_html( $button_text ); ?></span>
					</button>
				</div>

				<?php if ( $show_collection_filter && ! empty( $collections ) ) : ?>
					<div class="oraculo-search-filters">
						<label for="oraculo-collection-filter-<?php echo esc_attr( $widget_id ); ?>">
							<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
								<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>
							</svg>
							<?php esc_html_e( 'Filtrar por coleção:', 'oraculo-tainacan' ); ?>
						</label>
						<select name="oraculo_collection" id="oraculo-collection-filter-<?php echo esc_attr( $widget_id ); ?>">
							<option value=""><?php esc_html_e( 'Todas as coleções', 'oraculo-tainacan' ); ?></option>
							<?php foreach ( $collections as $collection ) : ?>
								<option value="<?php echo esc_attr( $collection['id'] ); ?>">
									<?php echo esc_html( $collection['name'] ); ?>
									(<?php echo number_format( $collection['items_count'] ); ?> <?php esc_html_e( 'itens', 'oraculo-tainacan' ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endif; ?>
			</form>
		</div>
	</div>

	<!-- Features Section -->
	<section class="oraculo-features-section">
		<h2 class="oraculo-features-title"><?php esc_html_e( 'Como Funciona', 'oraculo-tainacan' ); ?></h2>
		<p class="oraculo-features-subtitle"><?php esc_html_e( 'Três passos simples para encontrar o que você procura', 'oraculo-tainacan' ); ?></p>

		<div class="oraculo-features-grid">
			<div class="oraculo-feature-card oraculo-animate-in">
				<div class="oraculo-feature-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
					</svg>
				</div>
				<h3 class="oraculo-feature-title"><?php esc_html_e( 'Pergunte Naturalmente', 'oraculo-tainacan' ); ?></h3>
				<p class="oraculo-feature-description">
					<?php esc_html_e( 'Digite sua pergunta como se estivesse conversando. Use suas próprias palavras, sem precisar de termos técnicos ou filtros complexos.', 'oraculo-tainacan' ); ?>
				</p>
			</div>

			<div class="oraculo-feature-card oraculo-animate-in">
				<div class="oraculo-feature-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<circle cx="12" cy="12" r="10"/>
						<path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
						<line x1="12" y1="17" x2="12.01" y2="17"/>
					</svg>
				</div>
				<h3 class="oraculo-feature-title"><?php esc_html_e( 'IA Compreende o Contexto', 'oraculo-tainacan' ); ?></h3>
				<p class="oraculo-feature-description">
					<?php esc_html_e( 'Nossa inteligência artificial analisa sua pergunta, entende o que você realmente procura e busca nos documentos do acervo.', 'oraculo-tainacan' ); ?>
				</p>
			</div>

			<div class="oraculo-feature-card oraculo-animate-in">
				<div class="oraculo-feature-icon">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
						<polyline points="14 2 14 8 20 8"/>
						<line x1="16" y1="13" x2="8" y2="13"/>
						<line x1="16" y1="17" x2="8" y2="17"/>
						<polyline points="10 9 9 9 8 9"/>
					</svg>
				</div>
				<h3 class="oraculo-feature-title"><?php esc_html_e( 'Receba Respostas Completas', 'oraculo-tainacan' ); ?></h3>
				<p class="oraculo-feature-description">
					<?php esc_html_e( 'Você recebe uma resposta elaborada com as informações encontradas, junto com links diretos para os itens do acervo.', 'oraculo-tainacan' ); ?>
				</p>
			</div>
		</div>
	</section>

	<!-- Example Queries Section -->
	<section class="oraculo-examples-section">
		<div class="oraculo-examples-container">
			<h3 class="oraculo-examples-title">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
				</svg>
				<?php esc_html_e( 'Exemplos de Perguntas', 'oraculo-tainacan' ); ?>
			</h3>
			<p class="oraculo-examples-subtitle"><?php esc_html_e( 'Clique em uma sugestão para experimentar', 'oraculo-tainacan' ); ?></p>

			<div class="oraculo-examples-grid">
				<?php
				$icons = array(
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>',
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
				);
				foreach ( $suggested_questions as $index => $question ) :
					$icon = $icons[ $index % count( $icons ) ];
					?>
					<div class="oraculo-example-item" data-query="<?php echo esc_attr( $question ); ?>">
						<div class="oraculo-example-icon">
							<?php
							echo wp_kses(
								$icon,
								array(
									'svg'      => array(
										'xmlns'           => array(),
										'viewBox'         => array(),
										'width'           => array(),
										'height'          => array(),
										'fill'            => array(),
										'stroke'          => array(),
										'stroke-width'    => array(),
										'stroke-linecap'  => array(),
										'stroke-linejoin' => array(),
									),
									'path'     => array(
										'd'            => array(),
										'fill'         => array(),
										'stroke'       => array(),
										'stroke-width' => array(),
									),
									'circle'   => array(
										'cx'     => array(),
										'cy'     => array(),
										'r'      => array(),
										'fill'   => array(),
										'stroke' => array(),
									),
									'polyline' => array( 'points' => array() ),
									'line'     => array(
										'x1'           => array(),
										'y1'           => array(),
										'x2'           => array(),
										'y2'           => array(),
										'stroke'       => array(),
										'stroke-width' => array(),
									),
								)
							);
							?>
						</div>
						<span class="oraculo-example-text"><?php echo esc_html( $question ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<!-- Loading State -->
	<div class="oraculo-search-loading" style="display: none;">
		<div class="oraculo-loading-spinner"></div>
		<span><?php esc_html_e( 'Consultando o acervo com inteligência artificial...', 'oraculo-tainacan' ); ?></span>
		<div class="oraculo-loading-dots">
			<span></span>
			<span></span>
			<span></span>
		</div>
	</div>

	<!-- Results Container -->
	<div class="oraculo-search-results" style="display: none;"></div>

	<!-- Tips Section -->
	<section class="oraculo-tips-section">
		<div class="oraculo-tips-card">
			<div class="oraculo-tips-icon">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
					<circle cx="12" cy="12" r="10"/>
					<line x1="12" y1="16" x2="12" y2="12"/>
					<line x1="12" y1="8" x2="12.01" y2="8"/>
				</svg>
			</div>
			<div class="oraculo-tips-content">
				<h4><?php esc_html_e( 'Dicas para melhores resultados', 'oraculo-tainacan' ); ?></h4>
				<ul class="oraculo-tips-list">
					<li><?php esc_html_e( 'Seja específico sobre o que procura - quanto mais detalhes, melhor a resposta', 'oraculo-tainacan' ); ?></li>
					<li><?php esc_html_e( 'Use palavras-chave relacionadas ao tema como datas, nomes ou lugares', 'oraculo-tainacan' ); ?></li>
					<li><?php esc_html_e( 'Faça perguntas completas como "Quais são..." ou "O que existe sobre..."', 'oraculo-tainacan' ); ?></li>
					<li><?php esc_html_e( 'Se não encontrar resultados, tente reformular sua pergunta com sinônimos', 'oraculo-tainacan' ); ?></li>
				</ul>
			</div>
		</div>
	</section>

	<!-- Powered By -->
	<div class="oraculo-powered-by">
		<span>
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
				<path d="M12 2L2 7l10 5 10-5-10-5z"/>
				<path d="M2 17l10 5 10-5"/>
				<path d="M2 12l10 5 10-5"/>
			</svg>
			<?php esc_html_e( 'Powered by', 'oraculo-tainacan' ); ?> <strong>Oráculo Tainacan</strong> &bull; <?php esc_html_e( 'Busca Inteligente com IA', 'oraculo-tainacan' ); ?>
		</span>
	</div>
</div>

<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
