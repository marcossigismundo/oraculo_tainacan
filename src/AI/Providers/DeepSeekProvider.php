<?php
/**
 * Provedor DeepSeek
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\AI\Providers;

use Oraculo_Tainacan\AI\AbstractAIProvider;
use WP_Error;

/**
 * Implementação do provedor DeepSeek
 */
class DeepSeekProvider extends AbstractAIProvider {

	/**
	 * URL base da API
	 */
	private const API_BASE_URL = 'https://api.deepseek.com/v1';

	/**
	 * Modelos disponíveis
	 */
	private const MODELS = array(
		'deepseek-chat'     => array(
			'name'         => 'DeepSeek Chat',
			'context'      => 64000,
			'input_price'  => 0.00014,
			'output_price' => 0.00028,
			'description'  => 'Modelo conversacional otimizado',
		),
		'deepseek-coder'    => array(
			'name'         => 'DeepSeek Coder',
			'context'      => 64000,
			'input_price'  => 0.00014,
			'output_price' => 0.00028,
			'description'  => 'Especializado em programação',
		),
		'deepseek-reasoner' => array(
			'name'         => 'DeepSeek Reasoner (R1)',
			'context'      => 64000,
			'input_price'  => 0.00055,
			'output_price' => 0.00219,
			'description'  => 'Modelo de raciocínio avançado',
		),
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'deepseek';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'DeepSeek';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'IA chinesa de alta qualidade com preços muito competitivos. Compatível com API OpenAI.', 'oraculo-tainacan' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_available_models(): array {
		$models = array();
		foreach ( self::MODELS as $id => $info ) {
			$models[] = array(
				'id'             => $id,
				'name'           => $info['name'],
				'context_length' => $info['context'],
				'description'    => $info['description'],
			);
		}
		return $models;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_embedding_models(): array {
		// DeepSeek não oferece API de embeddings pública
		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_configured(): bool {
		return $this->has_api_key( 'api_key' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function test_connection(): array {
		if ( ! $this->is_configured() ) {
			return array(
				'success' => false,
				'message' => __( 'Chave de API não configurada.', 'oraculo-tainacan' ),
				'details' => array(),
			);
		}

		// DeepSeek usa formato OpenAI, testar com uma requisição simples
		$response = $this->make_request(
			self::API_BASE_URL . '/models',
			array(),
			$this->get_headers(),
			'GET'
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
				'details' => array(),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Conexão estabelecida com sucesso!', 'oraculo-tainacan' ),
			'details' => array(
				'models_available' => count( $response['data'] ?? array() ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embedding( string $text, ?string $model = null ) {
		return new WP_Error(
			'not_supported',
			__( 'DeepSeek não oferece API de embeddings. Use OpenAI ou Ollama para embeddings.', 'oraculo-tainacan' )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embeddings_batch( array $texts, ?string $model = null ) {
		return new WP_Error(
			'not_supported',
			__( 'DeepSeek não oferece API de embeddings. Use OpenAI ou Ollama para embeddings.', 'oraculo-tainacan' )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_response( string $prompt, string $system_prompt = '', array $options = array() ) {
		return $this->chat(
			array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			$system_prompt,
			$options
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function chat( array $messages, string $system_prompt = '', array $options = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Provedor DeepSeek não configurado.', 'oraculo-tainacan' ) );
		}

		$options = $this->prepare_options( $options );
		$model   = $options['model'] ?? $this->get_config( 'model', 'deepseek-chat' );

		$formatted_messages = $this->format_messages( $messages, $system_prompt );

		$body = array(
			'model'       => $model,
			'messages'    => $formatted_messages,
			'max_tokens'  => $options['max_tokens'],
			'temperature' => $options['temperature'],
		);

		$response = $this->make_request_with_retry(
			self::API_BASE_URL . '/chat/completions',
			$body,
			$this->get_headers()
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Resposta inválida da API.', 'oraculo-tainacan' ) );
		}

		$usage = $this->normalize_usage( $response['usage'] ?? array() );

		return array(
			'response'      => $response['choices'][0]['message']['content'],
			'model'         => $model,
			'usage'         => $usage,
			'cost'          => $this->calculate_cost( $usage, $model ),
			'finish_reason' => $response['choices'][0]['finish_reason'] ?? 'stop',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_pricing( string $model ): array {
		$info = self::MODELS[ $model ] ?? null;

		if ( $info ) {
			return array(
				'input'  => $info['input_price'],
				'output' => $info['output_price'],
				'unit'   => '1K tokens',
			);
		}

		return array();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_limit( string $model ): int {
		return self::MODELS[ $model ]['context'] ?? 64000;
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_embeddings(): bool {
		return false;
	}

	/**
	 * Obtém headers para requisição
	 *
	 * @return array
	 */
	private function get_headers(): array {
		return array(
			'Authorization' => 'Bearer ' . $this->get_api_key(),
		);
	}
}
