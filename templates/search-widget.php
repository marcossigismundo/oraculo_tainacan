<?php
/**
 * Template do Widget de Busca - Design Moderno
 * Interface auto-explicativa para busca em linguagem natural
 *
 * @package Oraculo_Tainacan
 */

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

<script>
(function() {
	var widget = document.getElementById('<?php echo esc_js( $widget_id ); ?>');
	var form = widget.querySelector('.oraculo-search-form');
	var input = widget.querySelector('.oraculo-search-input');
	var submitBtn = widget.querySelector('.oraculo-search-button');
	var loading = widget.querySelector('.oraculo-search-loading');
	var results = widget.querySelector('.oraculo-search-results');
	var featuresSection = widget.querySelector('.oraculo-features-section');
	var examplesSection = widget.querySelector('.oraculo-examples-section');
	var tipsSection = widget.querySelector('.oraculo-tips-section');
	var exampleItems = widget.querySelectorAll('.oraculo-example-item');
	var isSearching = false;

	// Função para executar a busca
	function executeSearch() {
		var query = input.value.trim();
		if (!query || isSearching) return;

		isSearching = true;
		submitBtn.disabled = true;

		var collectionSelect = form.querySelector('[name="oraculo_collection"]');
		var collections = collectionSelect ? [collectionSelect.value].filter(Boolean) : [];

		// Show loading, hide other sections
		loading.style.display = 'flex';
		results.style.display = 'none';
		results.innerHTML = '';
		featuresSection.style.display = 'none';
		examplesSection.style.display = 'none';
		tipsSection.style.display = 'none';

		// Scroll to loading
		loading.scrollIntoView({ behavior: 'smooth', block: 'center' });

		var startTime = Date.now();

		fetch('<?php echo esc_url( rest_url( 'oraculo/v1/search' ) ); ?>', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
			},
			body: JSON.stringify({
				query: query,
				collections: collections
			})
		})
		.then(function(response) { return response.json(); })
		.then(function(data) {
			var responseTime = ((Date.now() - startTime) / 1000).toFixed(1);
			loading.style.display = 'none';
			results.style.display = 'block';
			isSearching = false;
			submitBtn.disabled = false;

			// Os dados podem vir diretamente ou dentro de data.data (estrutura da API REST)
			var searchResult = data.data || data;

			if (searchResult.response) {
				var html = '<div class="oraculo-response">';

				// Response Header
				html += '<div class="oraculo-response-header">';
				html += '<div class="oraculo-response-avatar">';
				html += '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>';
				html += '</div>';
				html += '<div class="oraculo-response-meta">';
				html += '<div class="oraculo-response-label"><?php esc_html_e( 'Assistente Oráculo', 'oraculo-tainacan' ); ?></div>';
				html += '<div class="oraculo-response-time"><?php esc_html_e( 'Respondido em', 'oraculo-tainacan' ); ?> ' + responseTime + 's</div>';
				html += '</div>';
				html += '</div>';

				// Response Body
				html += '<div class="oraculo-response-body">';
				html += '<div class="oraculo-response-text">' + formatText(searchResult.response) + '</div>';

				// Sources
				if (searchResult.items && searchResult.items.length > 0) {
					html += '<div class="oraculo-sources">';
					html += '<h4><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><?php esc_html_e( 'Fontes consultadas no acervo', 'oraculo-tainacan' ); ?></h4>';
					html += '<ul>';
					searchResult.items.forEach(function(item, index) {
						html += '<li>';
						html += '<span class="oraculo-source-number">' + (index + 1) + '</span>';
						html += '<a href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener">';
						html += escapeHtml(item.title);
						html += '</a>';
						html += '<svg class="oraculo-source-arrow" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
						html += '</li>';
					});
					html += '</ul>';
					html += '</div>';
				}

				// Feedback section - guarda search_id para associar feedback ao log
				var searchId = searchResult.search_id || '';
				html += '<div class="oraculo-feedback" data-search-id="' + escapeHtml(searchId) + '">';
				html += '<span class="oraculo-feedback-label"><?php esc_html_e( 'Esta resposta foi útil?', 'oraculo-tainacan' ); ?></span>';
				html += '<div class="oraculo-feedback-buttons">';
				html += '<button type="button" class="oraculo-feedback-btn positive" data-feedback="positive">';
				html += '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/></svg>';
				html += '<?php esc_html_e( 'Sim', 'oraculo-tainacan' ); ?>';
				html += '</button>';
				html += '<button type="button" class="oraculo-feedback-btn negative" data-feedback="negative">';
				html += '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/></svg>';
				html += '<?php esc_html_e( 'Não', 'oraculo-tainacan' ); ?>';
				html += '</button>';
				html += '</div>';
				html += '</div>';

				html += '</div>'; // response-body
				html += '</div>'; // response

				results.innerHTML = html;

				// Handle feedback buttons
				var feedbackBtns = results.querySelectorAll('.oraculo-feedback-btn');
				feedbackBtns.forEach(function(btn) {
					btn.addEventListener('click', function() {
						var feedback = this.getAttribute('data-feedback');
						var feedbackContainer = this.closest('.oraculo-feedback');
						var searchIdFromContainer = feedbackContainer.getAttribute('data-search-id') || '';

						// Send feedback via REST API
						fetch('<?php echo esc_url( rest_url( 'oraculo/v1/feedback' ) ); ?>', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>'
							},
							body: JSON.stringify({
								search_id: searchIdFromContainer,
								feedback: feedback
							})
						});

						// Update UI
						feedbackContainer.innerHTML = '<span class="oraculo-feedback-thanks"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg><?php esc_html_e( 'Obrigado pelo feedback!', 'oraculo-tainacan' ); ?></span>';
					});
				});

			} else if (searchResult.message || data.error) {
				var errorMsg = searchResult.message || data.error || '<?php esc_html_e( 'Erro desconhecido', 'oraculo-tainacan' ); ?>';
				results.innerHTML = '<div class="oraculo-error">' +
					'<div class="oraculo-error-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>' +
					'<div class="oraculo-error-content"><h4><?php esc_html_e( 'Não foi possível processar sua busca', 'oraculo-tainacan' ); ?></h4><p>' + escapeHtml(errorMsg) + '</p></div>' +
					'</div>';
			} else {
				results.innerHTML = '<div class="oraculo-no-results">' +
					'<div class="oraculo-no-results-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg></div>' +
					'<h3 class="oraculo-no-results-title"><?php esc_html_e( 'Nenhum resultado encontrado', 'oraculo-tainacan' ); ?></h3>' +
					'<p class="oraculo-no-results-text"><?php esc_html_e( 'Não encontramos informações relacionadas à sua pergunta. Tente reformular usando outras palavras ou seja mais específico.', 'oraculo-tainacan' ); ?></p>' +
					'</div>';
			}

			// Scroll to results
			results.scrollIntoView({ behavior: 'smooth', block: 'start' });

			// Show tips section after results
			tipsSection.style.display = 'block';
		})
		.catch(function(error) {
			loading.style.display = 'none';
			results.style.display = 'block';
			isSearching = false;
			submitBtn.disabled = false;

			results.innerHTML = '<div class="oraculo-error">' +
				'<div class="oraculo-error-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>' +
				'<div class="oraculo-error-content"><h4><?php esc_html_e( 'Erro de conexão', 'oraculo-tainacan' ); ?></h4><p><?php esc_html_e( 'Ocorreu um erro ao processar sua busca. Por favor, verifique sua conexão e tente novamente.', 'oraculo-tainacan' ); ?></p></div>' +
				'</div>';

			// Show sections again on error
			featuresSection.style.display = 'block';
			examplesSection.style.display = 'block';
			tipsSection.style.display = 'block';
		});
	}

	// Handle example clicks
	exampleItems.forEach(function(item) {
		item.addEventListener('click', function() {
			var query = this.getAttribute('data-query');
			input.value = query;
			input.focus();
			executeSearch();
		});
	});

	// Handle form submission
	form.addEventListener('submit', function(e) {
		e.preventDefault();
		executeSearch();
	});

	// Handle Enter key in input
	input.addEventListener('keydown', function(e) {
		if (e.key === 'Enter' || e.keyCode === 13) {
			e.preventDefault();
			executeSearch();
		}
	});

	// Handle button click explicitly
	submitBtn.addEventListener('click', function(e) {
		e.preventDefault();
		executeSearch();
	});

	// Format text with markdown-like syntax
	function formatText(text) {
		// Bold
		text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
		// Italic
		text = text.replace(/\*(.*?)\*/g, '<em>$1</em>');
		// Line breaks
		text = text.replace(/\n/g, '<br>');
		// Links
		text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
		// Wrap in paragraphs
		text = '<p>' + text.replace(/<br><br>/g, '</p><p>') + '</p>';
		return text;
	}

	// Escape HTML to prevent XSS
	function escapeHtml(text) {
		var div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Add animation on scroll
	var animatedElements = widget.querySelectorAll('.oraculo-animate-in');
	var observer = new IntersectionObserver(function(entries) {
		entries.forEach(function(entry) {
			if (entry.isIntersecting) {
				entry.target.style.opacity = '1';
				entry.target.style.transform = 'translateY(0)';
			}
		});
	}, { threshold: 0.1 });

	animatedElements.forEach(function(el) {
		el.style.opacity = '0';
		el.style.transform = 'translateY(30px)';
		el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
		observer.observe(el);
	});
})();
</script>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
