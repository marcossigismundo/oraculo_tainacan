<?php
/**
 * Template de Gerenciamento de Indexação
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template file; all variables are local to this included template scope.

$indexing     = new \Oraculo_Tainacan\Indexing\IndexingManager();
$vector_store = new \Oraculo_Tainacan\Vector\VectorStore();
$auto_indexer = new \Oraculo_Tainacan\Indexing\AutoIndexer();
$collections  = \Oraculo_Tainacan\get_tainacan_collections();
$vector_stats = $vector_store->get_stats();

$plugin_options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
$index_fields   = (array) ( $plugin_options['index_fields'] ?? array( 'title', 'description' ) );
$queue_pending  = $auto_indexer->count_pending();
?>

<div class="oraculo-indexing-content">

	<!-- Estatísticas Gerais -->
	<div class="oraculo-stats-grid">
		<div class="oraculo-stat-card">
			<span class="stat-value"><?php echo number_format( $vector_stats['total_vectors'] ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'Vetores Totais', 'oraculo-tainacan' ); ?></span>
		</div>
		<div class="oraculo-stat-card">
			<span class="stat-value"><?php echo count( $vector_stats['by_collection'] ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'Coleções Indexadas', 'oraculo-tainacan' ); ?></span>
		</div>
		<div class="oraculo-stat-card">
			<span class="stat-value"><?php echo number_format( $vector_stats['total_tokens'] ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'Tokens Armazenados', 'oraculo-tainacan' ); ?></span>
		</div>
		<div class="oraculo-stat-card">
			<span class="stat-value"><?php echo esc_html( implode( ', ', $vector_stats['models_used'] ?: array( '-' ) ) ); ?></span>
			<span class="stat-label"><?php esc_html_e( 'Modelos Utilizados', 'oraculo-tainacan' ); ?></span>
		</div>
	</div>

	<!-- Lista de Coleções -->
	<div class="oraculo-card">
		<h2><?php esc_html_e( 'Coleções Tainacan', 'oraculo-tainacan' ); ?></h2>

		<?php if ( empty( $collections ) ) : ?>
			<p class="oraculo-empty"><?php esc_html_e( 'Nenhuma coleção encontrada no Tainacan.', 'oraculo-tainacan' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" class="column-id"><?php esc_html_e( 'ID', 'oraculo-tainacan' ); ?></th>
						<th scope="col" class="column-name"><?php esc_html_e( 'Coleção', 'oraculo-tainacan' ); ?></th>
						<th scope="col" class="column-total"><?php esc_html_e( 'Total Itens', 'oraculo-tainacan' ); ?></th>
						<th scope="col" class="column-indexed"><?php esc_html_e( 'Indexados', 'oraculo-tainacan' ); ?></th>
						<th scope="col" class="column-progress"><?php esc_html_e( 'Progresso', 'oraculo-tainacan' ); ?></th>
						<th scope="col" class="column-status"><?php esc_html_e( 'Status', 'oraculo-tainacan' ); ?></th>
						<th scope="col" class="column-actions"><?php esc_html_e( 'Ações', 'oraculo-tainacan' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $collections as $collection ) :
						$indexed    = $vector_store->count_by_collection( $collection['id'] );
						$percentage = $collection['items_count'] > 0
							? round( ( $indexed / $collection['items_count'] ) * 100, 1 )
							: 0;
						$status     = $indexing->get_status( $collection['id'] );
						?>
					<tr data-collection-id="<?php echo esc_attr( $collection['id'] ); ?>">
						<td class="column-id"><?php echo esc_html( $collection['id'] ); ?></td>
						<td class="column-name">
							<strong><?php echo esc_html( $collection['name'] ); ?></strong>
							<?php if ( ! empty( $collection['description'] ) ) : ?>
								<br><small><?php echo esc_html( wp_trim_words( $collection['description'], 10 ) ); ?></small>
							<?php endif; ?>
						</td>
						<td class="column-total"><?php echo number_format( $collection['items_count'] ); ?></td>
						<td class="column-indexed indexed-count"><?php echo number_format( $indexed ); ?></td>
						<td class="column-progress">
							<div class="oraculo-progress-bar">
								<div class="oraculo-progress-fill" style="width: <?php echo esc_attr( $percentage ); ?>%"></div>
								<span class="oraculo-progress-text"><?php echo esc_html( $percentage ); ?>%</span>
							</div>
						</td>
						<td class="column-status">
							<?php
							$status_labels = array(
								'idle'       => __( 'Não indexado', 'oraculo-tainacan' ),
								'pending'    => __( 'Pendente', 'oraculo-tainacan' ),
								'processing' => __( 'Processando', 'oraculo-tainacan' ),
								'completed'  => __( 'Concluído', 'oraculo-tainacan' ),
								'error'      => __( 'Erro', 'oraculo-tainacan' ),
							);
							$status_label  = $status_labels[ $status['status'] ] ?? $status['status'];
							?>
							<span class="oraculo-status oraculo-status-<?php echo esc_attr( $status['status'] ); ?>">
								<?php echo esc_html( $status_label ); ?>
							</span>
						</td>
						<td class="column-actions">
							<button type="button" class="button oraculo-btn-index"
									data-collection="<?php echo esc_attr( $collection['id'] ); ?>"
									<?php echo $status['status'] === 'processing' ? 'disabled' : ''; ?>>
								<?php
								if ( $status['status'] === 'processing' ) {
									esc_html_e( 'Indexando...', 'oraculo-tainacan' );
								} elseif ( $indexed > 0 ) {
									esc_html_e( 'Reindexar', 'oraculo-tainacan' );
								} else {
									esc_html_e( 'Indexar', 'oraculo-tainacan' );
								}
								?>
							</button>
							<?php if ( $indexed > 0 ) : ?>
								<button type="button" class="button oraculo-btn-clear"
										data-collection="<?php echo esc_attr( $collection['id'] ); ?>">
									<?php esc_html_e( 'Limpar', 'oraculo-tainacan' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<!-- Fila de Indexação Automática -->
	<div class="oraculo-card">
		<h2><?php esc_html_e( 'Fila de Indexação Automática', 'oraculo-tainacan' ); ?></h2>
		<p>
			<?php esc_html_e( 'Itens aguardando indexação:', 'oraculo-tainacan' ); ?>
			<strong id="oraculo-queue-count"><?php echo (int) $queue_pending; ?></strong>
		</p>
		<p class="description">
			<?php esc_html_e( 'Obras criadas ou editadas entram nesta fila e são indexadas em segundo plano (normalmente em menos de um minuto). A fila também é verificada a cada 5 minutos e reconciliada com o acervo uma vez por dia.', 'oraculo-tainacan' ); ?>
		</p>
		<p>
			<button type="button" class="button" id="oraculo-process-queue">
				<?php esc_html_e( 'Processar fila agora', 'oraculo-tainacan' ); ?>
			</button>
		</p>
	</div>

	<!-- Ações em Massa -->
	<div class="oraculo-card">
		<h2><?php esc_html_e( 'Ações em Massa', 'oraculo-tainacan' ); ?></h2>
		<div class="oraculo-bulk-actions">
			<button type="button" class="button button-primary" id="oraculo-index-all">
				<?php esc_html_e( 'Indexar Todas as Coleções', 'oraculo-tainacan' ); ?>
			</button>
			<button type="button" class="button" id="oraculo-clear-all">
				<?php esc_html_e( 'Limpar Todos os Vetores', 'oraculo-tainacan' ); ?>
			</button>
			<button type="button" class="button" id="oraculo-optimize-db">
				<?php esc_html_e( 'Otimizar Banco de Dados', 'oraculo-tainacan' ); ?>
			</button>
		</div>
	</div>

	<!-- Log de Indexação -->
	<div class="oraculo-card">
		<h2><?php esc_html_e( 'Log de Atividades', 'oraculo-tainacan' ); ?></h2>
		<div id="oraculo-indexing-log" class="oraculo-log">
			<p class="oraculo-log-empty"><?php esc_html_e( 'Nenhuma atividade recente.', 'oraculo-tainacan' ); ?></p>
		</div>
	</div>

	<!-- Configurações de Indexação -->
	<div class="oraculo-card">
		<h2><?php esc_html_e( 'Configurações de Indexação', 'oraculo-tainacan' ); ?></h2>
		<form id="oraculo-indexing-settings">
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Tamanho do Batch', 'oraculo-tainacan' ); ?></th>
					<td>
						<input type="number" name="batch_size"
								value="<?php echo esc_attr( (string) ( $plugin_options['batch_size'] ?? 10 ) ); ?>"
								min="1" max="100" class="small-text">
						<p class="description">
							<?php esc_html_e( 'Número de itens processados por vez. Valores menores são mais seguros para servidores limitados.', 'oraculo-tainacan' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Provedor de Embeddings', 'oraculo-tainacan' ); ?></th>
					<td>
						<?php
						$factory   = new \Oraculo_Tainacan\AI\AIProviderFactory();
						$providers = $factory->get_available_providers();
						$current   = get_option( 'oraculo_embedding_provider', 'openai' );
						?>
						<select name="embedding_provider">
							<?php
							foreach ( $providers as $provider ) :
								if ( ! $provider['supports_embeddings'] ) {
									continue;
								}
								?>
								<option value="<?php echo esc_attr( $provider['id'] ); ?>"
										<?php selected( $current, $provider['id'] ); ?>>
									<?php echo esc_html( $provider['name'] ); ?>
									<?php
									if ( ! $provider['is_configured'] ) {
										echo ' (Não configurado)';}
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Indexação Automática', 'oraculo-tainacan' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="auto_index" value="1"
									<?php checked( get_option( 'oraculo_auto_index', true ) ); ?>>
							<?php esc_html_e( 'Indexar automaticamente quando itens são criados ou atualizados', 'oraculo-tainacan' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Campos para Indexar', 'oraculo-tainacan' ); ?></th>
					<td>
						<?php // Fonte canônica: oraculo_tainacan_options['index_fields'], a mesma lida pelo IndexingManager. ?>
						<label>
							<input type="checkbox" name="index_title" value="1"
									<?php checked( in_array( 'title', $index_fields, true ) ); ?>>
							<?php esc_html_e( 'Título', 'oraculo-tainacan' ); ?>
						</label><br>
						<label>
							<input type="checkbox" name="index_description" value="1"
									<?php checked( in_array( 'description', $index_fields, true ) ); ?>>
							<?php esc_html_e( 'Descrição', 'oraculo-tainacan' ); ?>
						</label><br>
						<label>
							<input type="checkbox" name="index_metadata" value="1"
									<?php checked( in_array( 'metadata', $index_fields, true ) ); ?>>
							<?php esc_html_e( 'Metadados', 'oraculo-tainacan' ); ?>
						</label><br>
						<label>
							<input type="checkbox" name="index_document" value="1"
									<?php checked( in_array( 'document', $index_fields, true ) ); ?>>
							<?php esc_html_e( 'Documento (OCR quando disponível)', 'oraculo-tainacan' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Salvar Configurações', 'oraculo-tainacan' ); ?>
				</button>
			</p>
		</form>
	</div>
</div>

<style>
.oraculo-stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 20px;
	margin: 20px 0;
}

.oraculo-stat-card {
	background: #fff;
	border: 1px solid #ccd0d4;
	border-radius: 4px;
	padding: 20px;
	text-align: center;
}

.oraculo-stat-card .stat-value {
	display: block;
	font-size: 28px;
	font-weight: bold;
	color: #1e1e1e;
}

.oraculo-stat-card .stat-label {
	display: block;
	font-size: 13px;
	color: #757575;
	margin-top: 5px;
}

.oraculo-card {
	background: #fff;
	border: 1px solid #ccd0d4;
	border-radius: 4px;
	padding: 20px;
	margin: 20px 0;
}

.oraculo-card h2 {
	margin-top: 0;
	padding-bottom: 10px;
	border-bottom: 1px solid #eee;
}

.oraculo-progress-bar {
	background: #e0e0e0;
	border-radius: 10px;
	height: 20px;
	position: relative;
	overflow: hidden;
}

.oraculo-progress-fill {
	background: linear-gradient(90deg, #2271b1, #0073aa);
	height: 100%;
	transition: width 0.3s ease;
}

.oraculo-progress-text {
	position: absolute;
	left: 50%;
	top: 50%;
	transform: translate(-50%, -50%);
	font-size: 11px;
	font-weight: bold;
	color: #333;
}

.oraculo-status {
	display: inline-block;
	padding: 3px 8px;
	border-radius: 3px;
	font-size: 11px;
	font-weight: 500;
}

.oraculo-status-idle { background: #f0f0f0; color: #666; }
.oraculo-status-processing { background: #fff3cd; color: #856404; }
.oraculo-status-completed { background: #d4edda; color: #155724; }
.oraculo-status-error { background: #f8d7da; color: #721c24; }

.oraculo-bulk-actions {
	display: flex;
	gap: 10px;
	flex-wrap: wrap;
}

.oraculo-log {
	background: #1e1e1e;
	color: #00ff00;
	font-family: monospace;
	padding: 15px;
	border-radius: 4px;
	max-height: 300px;
	overflow-y: auto;
}

.oraculo-log-empty {
	color: #666;
	font-style: italic;
}

.oraculo-log-entry {
	margin: 5px 0;
	padding: 3px 0;
	border-bottom: 1px solid #333;
}

.oraculo-log-entry.error { color: #ff6b6b; }
.oraculo-log-entry.success { color: #51cf66; }
.oraculo-log-entry.info { color: #74c0fc; }

.column-id { width: 50px; }
.column-progress { width: 150px; }
.column-status { width: 100px; }
.column-actions { width: 180px; }
</style>

<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
