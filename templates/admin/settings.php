<?php
/**
 * Template de Configurações
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file; all variables are local to this included template scope.

$options     = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
$factory     = new \Oraculo_Tainacan\AI\AIProviderFactory();
$providers   = $factory->get_available_providers();
$collections = \Oraculo_Tainacan\get_tainacan_collections();
?>

<div class="oraculo-settings-content">
	<div class="notice oraculo-notice" style="display:none;"></div>
	<form method="post" id="oraculo-settings-form">
		<?php wp_nonce_field( 'oraculo_admin', 'nonce' ); ?>

		<div class="oraculo-settings-tabs">
			<nav class="oraculo-tabs-nav">
				<button type="button" class="oraculo-tab-btn active" data-tab="providers">
					<?php esc_html_e( 'Provedores de IA', 'oraculo-tainacan' ); ?>
				</button>
				<button type="button" class="oraculo-tab-btn" data-tab="general">
					<?php esc_html_e( 'Geral', 'oraculo-tainacan' ); ?>
				</button>
				<button type="button" class="oraculo-tab-btn" data-tab="prompts">
					<?php esc_html_e( 'Prompts', 'oraculo-tainacan' ); ?>
				</button>
				<button type="button" class="oraculo-tab-btn" data-tab="appearance">
					<?php esc_html_e( 'Aparência', 'oraculo-tainacan' ); ?>
				</button>
				<button type="button" class="oraculo-tab-btn" data-tab="tainacan">
					<?php esc_html_e( 'Tema Tainacan', 'oraculo-tainacan' ); ?>
				</button>
				<button type="button" class="oraculo-tab-btn" data-tab="advanced">
					<?php esc_html_e( 'Avançado', 'oraculo-tainacan' ); ?>
				</button>
			</nav>

			<!-- Tab: Provedores de IA -->
			<div class="oraculo-tab-content active" id="tab-providers">
				<h2><?php esc_html_e( 'Provedores de IA', 'oraculo-tainacan' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Selecione e configure o provedor de IA para geração de respostas.', 'oraculo-tainacan' ); ?>
				</p>

				<div class="oraculo-providers-grid">
					<?php foreach ( $providers as $provider ) : ?>
					<div class="oraculo-provider-card <?php echo $options['ai_provider'] === $provider['id'] ? 'selected' : ''; ?>"
						data-provider="<?php echo esc_attr( $provider['id'] ); ?>">
						<label class="oraculo-provider-label">
							<input type="radio"
									name="oraculo_tainacan_options[ai_provider]"
									value="<?php echo esc_attr( $provider['id'] ); ?>"
									<?php checked( $options['ai_provider'], $provider['id'] ); ?>>
							<span class="oraculo-provider-name"><?php echo esc_html( $provider['name'] ); ?></span>
							<?php if ( $provider['is_configured'] ) : ?>
								<span class="oraculo-provider-badge configured"><?php esc_html_e( 'Configurado', 'oraculo-tainacan' ); ?></span>
							<?php endif; ?>
						</label>
						<p class="oraculo-provider-description"><?php echo esc_html( $provider['description'] ); ?></p>
						<div class="oraculo-provider-features">
							<?php if ( $provider['supports_embeddings'] ) : ?>
								<span class="feature">📊 Embeddings</span>
							<?php endif; ?>
							<?php if ( $provider['supports_streaming'] ) : ?>
								<span class="feature">⚡ Streaming</span>
							<?php endif; ?>
						</div>
					</div>
					<?php endforeach; ?>
				</div>

				<!-- Configurações específicas de cada provedor -->
				<?php
				// Painéis gerados do catálogo de cada provedor (get_available_providers()):
				// um painel por provedor, sempre com campo de API key e select de modelos.
				// Antes só OpenAI/Gemini/Ollama tinham painel — selecionar Claude, Groq ou
				// DeepSeek deixava o formulário sem campo algum para configurá-los.
				$oraculo_key_links = array(
					'openai'   => 'https://platform.openai.com/api-keys',
					'gemini'   => 'https://aistudio.google.com/app/apikey',
					'deepseek' => 'https://platform.deepseek.com/api_keys',
					'groq'     => 'https://console.groq.com/keys',
					'claude'   => 'https://console.anthropic.com/settings/keys',
				);

				foreach ( $providers as $provider ) :
					$pid = $provider['id'];

					// Ollama é local (URL + nomes livres de modelo); painel próprio abaixo.
					if ( 'ollama' === $pid ) {
						continue;
					}

					$key_option    = $pid . '_api_key';
					$model_option  = $pid . '_model';
					$catalog       = (array) ( $provider['models'] ?? array() );
					$catalog_ids   = array_column( $catalog, 'id' );
					$current_model = (string) ( $options[ $model_option ] ?? ( $catalog_ids[0] ?? '' ) );
					?>
				<div class="oraculo-provider-settings" id="provider-settings-<?php echo esc_attr( $pid ); ?>"
						style="<?php echo $options['ai_provider'] === $pid ? '' : 'display:none;'; ?>">
					<h3><?php echo esc_html( $provider['name'] ); ?></h3>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'API Key', 'oraculo-tainacan' ); ?></th>
							<td>
								<input type="password"
										name="oraculo_tainacan_options[<?php echo esc_attr( $key_option ); ?>]"
										value="<?php echo ! empty( $options[ $key_option ] ) ? '••••••••' : ''; ?>"
										class="regular-text"
										autocomplete="new-password">
								<button type="button" class="button oraculo-test-connection" data-provider="<?php echo esc_attr( $pid ); ?>">
									<?php esc_html_e( 'Testar Conexão', 'oraculo-tainacan' ); ?>
								</button>
								<?php if ( isset( $oraculo_key_links[ $pid ] ) ) : ?>
								<p class="description">
									<a href="<?php echo esc_url( $oraculo_key_links[ $pid ] ); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e( 'Obter API Key', 'oraculo-tainacan' ); ?>
									</a>
								</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Modelo de Chat', 'oraculo-tainacan' ); ?></th>
							<td>
								<select name="oraculo_tainacan_options[<?php echo esc_attr( $model_option ); ?>]">
									<?php if ( '' !== $current_model && ! in_array( $current_model, $catalog_ids, true ) ) : ?>
										<?php // Modelo salvo fora do catálogo atual: manter selecionável para não trocar silenciosamente ao salvar. ?>
										<option value="<?php echo esc_attr( $current_model ); ?>" selected>
											<?php
											printf(
												/* translators: %s: model id currently saved in the settings */
												esc_html__( '%s (configurado)', 'oraculo-tainacan' ),
												esc_html( $current_model )
											);
											?>
										</option>
									<?php endif; ?>
									<?php foreach ( $catalog as $model ) : ?>
									<option value="<?php echo esc_attr( $model['id'] ); ?>" <?php selected( $current_model, $model['id'] ); ?>>
										<?php echo esc_html( $model['name'] ); ?>
										<?php if ( ! empty( $model['description'] ) ) : ?>
											— <?php echo esc_html( $model['description'] ); ?>
										<?php endif; ?>
									</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<?php if ( ! empty( $provider['embedding_models'] ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'Modelo de Embedding', 'oraculo-tainacan' ); ?></th>
							<td>
								<select name="oraculo_tainacan_options[<?php echo esc_attr( $pid ); ?>_embedding_model]">
									<?php
									$embedding_current = (string) ( $options[ $pid . '_embedding_model' ] ?? '' );
									foreach ( $provider['embedding_models'] as $model ) :
										?>
									<option value="<?php echo esc_attr( $model['id'] ); ?>" <?php selected( $embedding_current, $model['id'] ); ?>>
										<?php echo esc_html( $model['name'] ); ?>
										<?php if ( ! empty( $model['description'] ) ) : ?>
											— <?php echo esc_html( $model['description'] ); ?>
										<?php endif; ?>
									</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Atenção: trocar o modelo de embedding exige reindexar todo o acervo — vetores de modelos diferentes não são comparáveis.', 'oraculo-tainacan' ); ?>
								</p>
							</td>
						</tr>
						<?php endif; ?>
					</table>
				</div>
				<?php endforeach; ?>

				<div class="oraculo-provider-settings" id="provider-settings-ollama" style="<?php echo $options['ai_provider'] === 'ollama' ? '' : 'display:none;'; ?>">
					<h3>Ollama (Local)</h3>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'URL do Servidor', 'oraculo-tainacan' ); ?></th>
							<td>
								<input type="url"
										name="oraculo_tainacan_options[ollama_url]"
										value="<?php echo esc_attr( $options['ollama_url'] ?? 'http://localhost:11434' ); ?>"
										class="regular-text">
								<button type="button" class="button oraculo-test-connection" data-provider="ollama">
									<?php esc_html_e( 'Testar Conexão', 'oraculo-tainacan' ); ?>
								</button>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Modelo de Chat', 'oraculo-tainacan' ); ?></th>
							<td>
								<input type="text"
										name="oraculo_tainacan_options[ollama_model]"
										value="<?php echo esc_attr( $options['ollama_model'] ?? 'llama3.2' ); ?>"
										class="regular-text"
										placeholder="llama3.2">
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Modelo de Embedding', 'oraculo-tainacan' ); ?></th>
							<td>
								<input type="text"
										name="oraculo_tainacan_options[ollama_embedding_model]"
										value="<?php echo esc_attr( $options['ollama_embedding_model'] ?? 'nomic-embed-text' ); ?>"
										class="regular-text"
										placeholder="nomic-embed-text">
							</td>
						</tr>
					</table>
				</div>

			</div>

			<!-- Tab: Geral -->
			<div class="oraculo-tab-content" id="tab-general">
				<h2><?php esc_html_e( 'Configurações Gerais', 'oraculo-tainacan' ); ?></h2>

				<h3><?php esc_html_e( 'Busca Visual (AI API — CLIP)', 'oraculo-tainacan' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Integração com a AI API do IBRAM (CLIP + pgvector). No modo CLIP, a busca em linguagem natural roda no espaço visual imagem-texto e restrições como "século 21" viram filtros de metadado. Não há resposta gerada por IA: os resultados são as obras mais próximas da consulta.', 'oraculo-tainacan' ); ?>
				</p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Backend de Busca', 'oraculo-tainacan' ); ?></th>
						<td>
							<select name="oraculo_tainacan_options[search_backend]">
								<option value="local" <?php selected( $options['search_backend'] ?? 'local', 'local' ); ?>>
									<?php esc_html_e( 'Local (embeddings de texto + resposta com IA)', 'oraculo-tainacan' ); ?>
								</option>
								<option value="clip" <?php selected( $options['search_backend'] ?? 'local', 'clip' ); ?>>
									<?php esc_html_e( 'AI API CLIP (busca visual, sem resposta gerada)', 'oraculo-tainacan' ); ?>
								</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'URL da AI API', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="url" name="oraculo_tainacan_options[clip_api_url]"
									value="<?php echo esc_attr( $options['clip_api_url'] ?? '' ); ?>"
									class="regular-text" placeholder="http://localhost:8000">
							<p class="description"><?php esc_html_e( 'Endereço do serviço FastAPI (sem barra final). Vazio desliga a integração.', 'oraculo-tainacan' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Modelo CLIP', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="text" name="oraculo_tainacan_options[clip_api_model]"
									value="<?php echo esc_attr( $options['clip_api_model'] ?? 'ViT-L-14' ); ?>"
									class="regular-text">
							<p class="description"><?php esc_html_e( 'Deve ser um dos modelos carregados no servidor (GET /v1/models). Indexação e busca precisam usar o mesmo modelo.', 'oraculo-tainacan' ); ?></p>
						</td>
					</tr>
				</table>

				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Coleções Padrão', 'oraculo-tainacan' ); ?></th>
						<td>
							<select name="oraculo_tainacan_options[default_collections][]" multiple class="oraculo-multiselect">
								<?php foreach ( $collections as $collection ) : ?>
								<option value="<?php echo esc_attr( $collection['id'] ); ?>"
										<?php echo in_array( $collection['id'], $options['default_collections'] ?? array() ) ? 'selected' : ''; ?>>
									<?php echo esc_html( $collection['name'] ); ?>
								</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Coleções usadas por padrão nas buscas.', 'oraculo-tainacan' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Máximo de Tokens', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="number"
									name="oraculo_tainacan_options[max_tokens]"
									value="<?php echo esc_attr( $options['max_tokens'] ?? 2000 ); ?>"
									min="100" max="16000" step="100">
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Temperatura', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="range"
									name="oraculo_tainacan_options[temperature]"
									value="<?php echo esc_attr( $options['temperature'] ?? 0.7 ); ?>"
									min="0" max="2" step="0.1"
									id="temperature-slider">
							<span id="temperature-value"><?php echo esc_html( $options['temperature'] ?? 0.7 ); ?></span>
							<p class="description"><?php esc_html_e( '0 = mais determinístico, 2 = mais criativo', 'oraculo-tainacan' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Limiar de Similaridade', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="range"
									name="oraculo_tainacan_options[similarity_threshold]"
									value="<?php echo esc_attr( $options['similarity_threshold'] ?? 0.3 ); ?>"
									min="0" max="1" step="0.05"
									id="similarity-slider">
							<span id="similarity-value"><?php echo esc_html( ( $options['similarity_threshold'] ?? 0.3 ) * 100 ); ?>%</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Máximo de Resultados', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="number"
									name="oraculo_tainacan_options[max_results]"
									value="<?php echo esc_attr( $options['max_results'] ?? 10 ); ?>"
									min="1" max="50">
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Funcionalidades', 'oraculo-tainacan' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="oraculo_tainacan_options[enable_chat]" value="1"
										<?php checked( $options['enable_chat'] ?? true ); ?>>
								<?php esc_html_e( 'Habilitar Chat', 'oraculo-tainacan' ); ?>
							</label><br>
							<label>
								<input type="checkbox" name="oraculo_tainacan_options[enable_search]" value="1"
										<?php checked( $options['enable_search'] ?? true ); ?>>
								<?php esc_html_e( 'Habilitar Busca', 'oraculo-tainacan' ); ?>
							</label><br>
							<label>
								<input type="checkbox" name="oraculo_tainacan_options[enable_analytics]" value="1"
										<?php checked( $options['enable_analytics'] ?? true ); ?>>
								<?php esc_html_e( 'Habilitar Analytics', 'oraculo-tainacan' ); ?>
							</label><br>
							<label>
								<input type="checkbox" name="oraculo_tainacan_options[enable_feedback]" value="1"
										<?php checked( $options['enable_feedback'] ?? true ); ?>>
								<?php esc_html_e( 'Habilitar Feedback', 'oraculo-tainacan' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<!-- Tab: Prompts -->
			<div class="oraculo-tab-content" id="tab-prompts">
				<h2><?php esc_html_e( 'Prompts Personalizados', 'oraculo-tainacan' ); ?></h2>

				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Prompt do Sistema', 'oraculo-tainacan' ); ?></th>
						<td>
							<textarea name="oraculo_tainacan_options[system_prompt]"
										rows="8" class="large-text code"><?php echo esc_textarea( $options['system_prompt'] ?? '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Instruções gerais para o assistente.', 'oraculo-tainacan' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Mensagem de Boas-vindas', 'oraculo-tainacan' ); ?></th>
						<td>
							<textarea name="oraculo_tainacan_options[welcome_message]"
										rows="3" class="large-text"><?php echo esc_textarea( $options['welcome_message'] ?? '' ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Perguntas Sugeridas', 'oraculo-tainacan' ); ?></th>
						<td>
							<div id="suggested-questions">
								<?php
								$suggested = $options['suggested_questions'] ?? array();
								foreach ( $suggested as $index => $question ) :
									?>
								<div class="suggested-question-row">
									<input type="text"
											name="oraculo_tainacan_options[suggested_questions][]"
											value="<?php echo esc_attr( $question ); ?>"
											class="regular-text">
									<button type="button" class="button remove-question">✕</button>
								</div>
								<?php endforeach; ?>
							</div>
							<button type="button" class="button" id="add-question">
								<?php esc_html_e( 'Adicionar Pergunta', 'oraculo-tainacan' ); ?>
							</button>
						</td>
					</tr>
				</table>
			</div>

			<!-- Tab: Aparência -->
			<div class="oraculo-tab-content" id="tab-appearance">
				<h2><?php esc_html_e( 'Aparência', 'oraculo-tainacan' ); ?></h2>

				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Cor Primária', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="color"
									name="oraculo_tainacan_options[appearance][primary_color]"
									value="<?php echo esc_attr( $options['appearance']['primary_color'] ?? '#1f2f56' ); ?>">
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Cor de Destaque', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="color"
									name="oraculo_tainacan_options[appearance][accent_color]"
									value="<?php echo esc_attr( $options['appearance']['accent_color'] ?? '#b5e0e3' ); ?>">
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Posição do Chat', 'oraculo-tainacan' ); ?></th>
						<td>
							<select name="oraculo_tainacan_options[appearance][chat_position]">
								<option value="bottom-right" <?php selected( $options['appearance']['chat_position'] ?? '', 'bottom-right' ); ?>><?php esc_html_e( 'Inferior Direito', 'oraculo-tainacan' ); ?></option>
								<option value="bottom-left" <?php selected( $options['appearance']['chat_position'] ?? '', 'bottom-left' ); ?>><?php esc_html_e( 'Inferior Esquerdo', 'oraculo-tainacan' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Opções de Exibição', 'oraculo-tainacan' ); ?></th>
						<td>
							<label>
								<?php // Campo oculto garante o valor 0 no POST: sanitize_appearance() assume "true" quando a chave está ausente. ?>
								<input type="hidden" name="oraculo_tainacan_options[appearance][show_sources]" value="0">
								<input type="checkbox" name="oraculo_tainacan_options[appearance][show_sources]" value="1"
										<?php checked( ! empty( $options['appearance']['show_sources'] ?? true ) ); ?>>
								<?php esc_html_e( 'Mostrar fontes nas respostas', 'oraculo-tainacan' ); ?>
							</label><br>
							<label>
								<input type="hidden" name="oraculo_tainacan_options[appearance][show_similarity]" value="0">
								<input type="checkbox" name="oraculo_tainacan_options[appearance][show_similarity]" value="1"
										<?php checked( ! empty( $options['appearance']['show_similarity'] ?? false ) ); ?>>
								<?php esc_html_e( 'Mostrar score de similaridade', 'oraculo-tainacan' ); ?>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<!-- Tab: Tema Tainacan -->
			<div class="oraculo-tab-content" id="tab-tainacan">
				<h2><?php esc_html_e( 'Integração com o tema Tainacan', 'oraculo-tainacan' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Insere uma aba "Busca com IA" junto ao campo de busca padrão das listagens de itens do Tainacan (coleções, repositório, termos de taxonomia e páginas com o bloco de busca facetada). Funciona com qualquer tema compatível com o Tainacan, incluindo o tema Tainacan Interface.', 'oraculo-tainacan' ); ?>
				</p>

				<?php
				$theme_integration = wp_parse_args(
					$options['theme_integration'] ?? array(),
					\Oraculo_Tainacan\Frontend\ThemeIntegration::get_default_settings()
				);
				$tainacan_active   = class_exists( '\Tainacan\Theme_Helper' );
				?>

				<?php if ( ! $tainacan_active ) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e( 'O plugin Tainacan não está ativo. A integração ficará inativa até que o Tainacan seja ativado.', 'oraculo-tainacan' ); ?></p>
					</div>
				<?php endif; ?>

				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Habilitar aba de busca IA', 'oraculo-tainacan' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="oraculo_tainacan_options[theme_integration][enabled]" value="0">
								<input type="checkbox" name="oraculo_tainacan_options[theme_integration][enabled]" value="1"
										<?php checked( ! empty( $theme_integration['enabled'] ) ); ?>>
								<?php esc_html_e( 'Exibir a aba "Busca com IA" nas listagens de itens do Tainacan', 'oraculo-tainacan' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Requer que a funcionalidade "Habilitar Busca" (aba Geral) esteja ativa.', 'oraculo-tainacan' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Rótulo da aba', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="text"
									name="oraculo_tainacan_options[theme_integration][tab_label]"
									value="<?php echo esc_attr( $theme_integration['tab_label'] ); ?>"
									class="regular-text"
									placeholder="<?php esc_attr_e( 'Busca com IA', 'oraculo-tainacan' ); ?>">
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Placeholder do campo', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="text"
									name="oraculo_tainacan_options[theme_integration][placeholder]"
									value="<?php echo esc_attr( $theme_integration['placeholder'] ); ?>"
									class="large-text"
									placeholder="<?php esc_attr_e( 'Pergunte em linguagem natural ao acervo…', 'oraculo-tainacan' ); ?>">
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Escopo da busca', 'oraculo-tainacan' ); ?></th>
						<td>
							<select name="oraculo_tainacan_options[theme_integration][scope]">
								<option value="collection" <?php selected( $theme_integration['scope'], 'collection' ); ?>>
									<?php esc_html_e( 'Coleção atual (quando em uma página de coleção)', 'oraculo-tainacan' ); ?>
								</option>
								<option value="all" <?php selected( $theme_integration['scope'], 'all' ); ?>>
									<?php esc_html_e( 'Todo o acervo (ignora a coleção da página)', 'oraculo-tainacan' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Em páginas de repositório e termos, a busca sempre considera as coleções padrão configuradas na aba Geral (ou todas, se nenhuma for selecionada).', 'oraculo-tainacan' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Sugestões de perguntas', 'oraculo-tainacan' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="oraculo_tainacan_options[theme_integration][show_suggestions]" value="0">
								<input type="checkbox" name="oraculo_tainacan_options[theme_integration][show_suggestions]" value="1"
										<?php checked( ! empty( $theme_integration['show_suggestions'] ) ); ?>>
								<?php esc_html_e( 'Mostrar as perguntas sugeridas (aba Prompts) ao abrir a aba de IA', 'oraculo-tainacan' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Link direto', 'oraculo-tainacan' ); ?></th>
						<td>
							<p class="description">
								<?php
								printf(
									/* translators: %s: example URL query string parameter */
									esc_html__( 'Dica: adicione %s à URL de qualquer listagem do Tainacan para abrir a aba de IA já com a pergunta preenchida e executada.', 'oraculo-tainacan' ),
									'<code>?oraculo_q=sua+pergunta</code>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Tab: Avançado -->
			<div class="oraculo-tab-content" id="tab-advanced">
				<h2><?php esc_html_e( 'Configurações Avançadas', 'oraculo-tainacan' ); ?></h2>

				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Tamanho do Batch', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="number"
									name="oraculo_tainacan_options[batch_size]"
									value="<?php echo esc_attr( $options['batch_size'] ?? 25 ); ?>"
									min="5" max="100">
							<p class="description"><?php esc_html_e( 'Itens processados por vez na indexação.', 'oraculo-tainacan' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Timeout de Requisição', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="number"
									name="oraculo_tainacan_options[request_timeout]"
									value="<?php echo esc_attr( $options['request_timeout'] ?? 120 ); ?>"
									min="30" max="300">
							<span><?php esc_html_e( 'segundos', 'oraculo-tainacan' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Duração do Cache', 'oraculo-tainacan' ); ?></th>
						<td>
							<input type="number"
									name="oraculo_tainacan_options[cache_duration]"
									value="<?php echo esc_attr( $options['cache_duration'] ?? 3600 ); ?>"
									min="0" max="86400">
							<span><?php esc_html_e( 'segundos (0 = desativado)', 'oraculo-tainacan' ); ?></span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Campos para Indexar', 'oraculo-tainacan' ); ?></th>
						<td>
							<?php
							$index_fields     = $options['index_fields'] ?? array( 'title', 'description' );
							$available_fields = array(
								'title'       => 'Título',
								'description' => 'Descrição',
								'metadata'    => 'Metadados',
							);
							foreach ( $available_fields as $field => $label ) :
								?>
							<label>
								<input type="checkbox"
										name="oraculo_tainacan_options[index_fields][]"
										value="<?php echo esc_attr( $field ); ?>"
										<?php checked( in_array( $field, $index_fields ) ); ?>>
								<?php echo esc_html( $label ); ?>
							</label><br>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Modo Debug', 'oraculo-tainacan' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="oraculo_tainacan_options[debug_mode]" value="1"
										<?php checked( $options['debug_mode'] ?? false ); ?>>
								<?php esc_html_e( 'Habilitar logs de debug', 'oraculo-tainacan' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Manutenção', 'oraculo-tainacan' ); ?></h3>
				<p>
					<button type="button" class="button" id="clear-cache">
						<?php esc_html_e( 'Limpar Cache', 'oraculo-tainacan' ); ?>
					</button>
					<button type="button" class="button" id="clear-vectors">
						<?php esc_html_e( 'Limpar Vetores', 'oraculo-tainacan' ); ?>
					</button>
					<button type="button" class="button" id="export-settings">
						<?php esc_html_e( 'Exportar Configurações', 'oraculo-tainacan' ); ?>
					</button>
				</p>
			</div>
		</div>

		<?php submit_button( __( 'Salvar Configurações', 'oraculo-tainacan' ) ); ?>
	</form>
</div>

<!-- Estilos movidos para admin-page.css -->

<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
