<?php
/**
 * Provedor CLIP — busca visual via AI API do IBRAM
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\AI\Providers;

use Oraculo_Tainacan\AI\AbstractAIProvider;
use Oraculo_Tainacan\Vector\ClipApiClient;
use WP_Error;

/**
 * Backend de busca visual (CLIP + pgvector), apresentado como provedor de IA.
 *
 * Não é um LLM: a AI API do IBRAM faz busca por proximidade no espaço conjunto
 * imagem-texto — encontra as obras mais próximas da consulta, mas não gera
 * respostas em linguagem natural. Selecioná-lo como provedor ativa o backend
 * CLIP da busca; chat e geração de texto ficam indisponíveis e devolvem um
 * erro explicativo em vez de quebrar silenciosamente.
 *
 * A configuração (URL do serviço, modelo, timeout) continua nas opções
 * clip_api_* — este provedor é a fachada delas no formato dos demais cards.
 */
class ClipProvider extends AbstractAIProvider {

	/**
	 * Mensagem padrão para operações de geração não suportadas.
	 *
	 * @return WP_Error
	 */
	private function not_supported(): WP_Error {
		return new WP_Error(
			'not_supported',
			__( 'CLIP é um backend de busca visual: encontra obras por proximidade, mas não gera respostas. Para chat, selecione um provedor de linguagem (OpenAI, Claude, Gemini...).', 'oraculo-tainacan' )
		);
	}

	/**
	 * Cria o cliente HTTP a partir da configuração da instância.
	 *
	 * @return ClipApiClient
	 */
	private function client(): ClipApiClient {
		return new ClipApiClient(
			array(
				'clip_api_url'     => $this->get_config( 'base_url', '' ),
				'clip_api_model'   => $this->get_config( 'model', 'ViT-L-14' ),
				'clip_api_timeout' => (int) $this->get_config( 'timeout', 60 ),
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'clip';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'Busca Visual (CLIP)', 'oraculo-tainacan' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'AI API do IBRAM (CLIP + pgvector): busca por similaridade visual entre texto e imagens do acervo. Sem resposta gerada por IA — os resultados são as obras mais próximas da consulta.', 'oraculo-tainacan' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_available_models(): array {
		return array(
			array(
				'id'             => 'ViT-L-14',
				'name'           => 'ViT-L-14',
				'context_length' => 0,
				'description'    => __( '768 dimensões — compatível com o schema padrão do servidor', 'oraculo-tainacan' ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_embedding_models(): array {
		// Embeddings de texto para o RAG local não passam por aqui.
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured(): bool {
		return '' !== (string) $this->get_config( 'base_url', '' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection(): array {
		if ( ! $this->is_configured() ) {
			return array(
				'success' => false,
				'message' => __( 'URL da AI API não configurada.', 'oraculo-tainacan' ),
				'details' => array(),
			);
		}

		$client = $this->client();
		$health = $client->health();

		if ( is_wp_error( $health ) ) {
			return array(
				'success' => false,
				'message' => $health->get_error_message(),
				'details' => array(),
			);
		}

		$models = $client->list_models();

		return array(
			'success' => true,
			'message' => __( 'Conexão estabelecida com sucesso!', 'oraculo-tainacan' ),
			'details' => array(
				'models_available' => is_wp_error( $models ) ? 0 : count( $models ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function list_remote_models() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'URL da AI API não configurada.', 'oraculo-tainacan' ) );
		}

		$models = $this->client()->list_models();

		if ( is_wp_error( $models ) ) {
			return $models;
		}

		$normalized = array();
		foreach ( $models as $model ) {
			if ( ! is_array( $model ) || empty( $model['id'] ) ) {
				continue;
			}
			$dims         = isset( $model['dimensions'] ) ? (int) $model['dimensions'] : 0;
			$normalized[] = array(
				'id'   => (string) $model['id'],
				'name' => $dims > 0
					/* translators: 1: CLIP model id, 2: embedding dimensions */
					? sprintf( __( '%1$s (%2$d dimensões)', 'oraculo-tainacan' ), $model['id'], $dims )
					: (string) $model['id'],
			);
		}

		if ( empty( $normalized ) ) {
			return new WP_Error( 'models_empty', __( 'O servidor CLIP não retornou modelos carregados.', 'oraculo-tainacan' ) );
		}

		return $normalized;
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embedding( string $text, ?string $model = null ) {
		return $this->not_supported();
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embeddings_batch( array $texts, ?string $model = null ) {
		return $this->not_supported();
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_response( string $prompt, string $system_prompt = '', array $options = array() ) {
		return $this->not_supported();
	}

	/**
	 * {@inheritdoc}
	 */
	public function chat( array $messages, string $system_prompt = '', array $options = array() ) {
		return $this->not_supported();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_pricing( string $model ): array {
		// Serviço próprio do IBRAM, sem cobrança por token.
		return array(
			'input'  => 0.0,
			'output' => 0.0,
			'unit'   => 'per_1k_tokens',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_limit( string $model ): int {
		// O encoder de texto do CLIP trunca em ~77 tokens; irrelevante aqui
		// porque o fluxo de chat nunca chega a este provedor.
		return 77;
	}
}
