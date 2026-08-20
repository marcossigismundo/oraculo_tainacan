<?php
/**
 * Provedor Claude (Anthropic)
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\AI\Providers;

use Oraculo_Tainacan\AI\AbstractAIProvider;
use WP_Error;

/**
 * Implementação do provedor Claude da Anthropic
 */
class ClaudeProvider extends AbstractAIProvider {

	/**
	 * URL base da API
	 */
	private const API_BASE_URL = 'https://api.anthropic.com/v1';

	/**
	 * Versão da API
	 */
	private const API_VERSION = '2023-06-01';

	/**
	 * Modelos disponíveis
	 */
	private const MODELS = array(
		"claude-opus-5"             => array(
			"name"         => "Claude Opus 5",
			"context"      => 200000,
			"input_price"  => 0.005,
			"output_price" => 0.025,
			"description"  => "Modelo mais avançado para tarefas complexas",
		),
		"claude-sonnet-5"           => array(
			"name"         => "Claude Sonnet 5",
			"context"      => 200000,
			"input_price"  => 0.003,
			"output_price" => 0.015,
			"description"  => "Equilíbrio ideal entre qualidade e velocidade",
		),
		"claude-haiku-4-5-20251001" => array(
			"name"         => "Claude Haiku 4.5",
			"context"      => 200000,
			"input_price"  => 0.001,
			"output_price" => 0.005,
			"description"  => "Modelo rápido e econômico",
		),
		"claude-opus-4-5-20251101"  => array(
			"name"         => "Claude Opus 4.5 (legado)",
			"context"      => 200000,
			"input_price"  => 0.005,
			"output_price" => 0.025,
			"description"  => "Geração anterior; mantido por compatibilidade",
		),
		"claude-3-5-haiku-latest"   => array(
			"name"         => "Claude 3.5 Haiku (legado)",
			"context"      => 200000,
			"input_price"  => 0.0008,
			"output_price" => 0.004,
			"description"  => "Geração anterior econômica; mantido por compatibilidade",
		),
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'claude';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'Claude (Anthropic)';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'IA da Anthropic conhecida por respostas seguras, precisas e contexto de 200K tokens.', 'oraculo-tainacan' );
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
		// Claude não oferece API de embeddings
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

		// Fazer uma requisição simples para testar
		$response = $this->generate_response(
			'Responda apenas com "ok".',
			'',
			array( 'max_tokens' => 10 )
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
				'model' => $response['model'] ?? 'unknown',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embedding( string $text, ?string $model = null ) {
		return new WP_Error(
			'not_supported',
			__( 'Claude não oferece API de embeddings. Use OpenAI ou Ollama para embeddings.', 'oraculo-tainacan' )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embeddings_batch( array $texts, ?string $model = null ) {
		return new WP_Error(
			'not_supported',
			__( 'Claude não oferece API de embeddings. Use OpenAI ou Ollama para embeddings.', 'oraculo-tainacan' )
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
			return new WP_Error( 'not_configured', __( 'Provedor Claude não configurado.', 'oraculo-tainacan' ) );
		}

		$options = $this->prepare_options( $options );
		$model   = $options['model'] ?? $this->get_config( 'model', 'claude-sonnet-5' );

		// Claude usa formato diferente - sem role 'system' nas mensagens
		$formatted_messages = array();
		foreach ( $messages as $message ) {
			$role = $message['role'];
			if ( $role === 'system' ) {
				continue; // Ignorar, usar no campo 'system'
			}
			$formatted_messages[] = array(
				'role'    => $role === 'assistant' ? 'assistant' : 'user',
				'content' => $message['content'],
			);
		}

		$body = array(
			'model'      => $model,
			'messages'   => $formatted_messages,
			'max_tokens' => $options['max_tokens'],
		);

		// Adicionar system prompt se fornecido
		if ( ! empty( $system_prompt ) ) {
			$body['system'] = $system_prompt;
		}

		// Claude não aceita temperature para alguns modelos
		if ( isset( $options['temperature'] ) && $options['temperature'] > 0 ) {
			$body['temperature'] = $options['temperature'];
		}

		$response = $this->make_request_with_retry(
			self::API_BASE_URL . '/messages',
			$body,
			$this->get_headers()
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['content'][0]['text'] ) ) {
			// Verificar motivo de parada
			if ( isset( $response['stop_reason'] ) && $response['stop_reason'] === 'max_tokens' ) {
				return new WP_Error( 'max_tokens', __( 'Resposta truncada por limite de tokens.', 'oraculo-tainacan' ) );
			}
			return new WP_Error( 'invalid_response', __( 'Resposta inválida da API.', 'oraculo-tainacan' ) );
		}

		$usage = array(
			'prompt_tokens'     => $response['usage']['input_tokens'] ?? 0,
			'completion_tokens' => $response['usage']['output_tokens'] ?? 0,
			'total_tokens'      => ( $response['usage']['input_tokens'] ?? 0 ) + ( $response['usage']['output_tokens'] ?? 0 ),
		);

		return array(
			'response'      => $response['content'][0]['text'],
			'model'         => $response['model'] ?? $model,
			'usage'         => $usage,
			'cost'          => $this->calculate_cost( $usage, $model ),
			'finish_reason' => $response['stop_reason'] ?? 'end_turn',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_streaming(): bool {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function stream_response( string $prompt, string $system_prompt, callable $callback, array $options = array() ): void {
		if ( ! $this->is_configured() ) {
			$callback( '', true, new WP_Error( 'not_configured', __( 'Provedor não configurado.', 'oraculo-tainacan' ) ) );
			return;
		}

		$options = $this->prepare_options( $options );
		$model   = $options['model'] ?? $this->get_config( 'model', 'claude-sonnet-5' );

		$body = array(
			'model'      => $model,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'max_tokens' => $options['max_tokens'],
			'stream'     => true,
		);

		if ( ! empty( $system_prompt ) ) {
			$body['system'] = $system_prompt;
		}

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- WP HTTP API lacks streaming callback support required for SSE.
		$ch = curl_init( self::API_BASE_URL . '/messages' );

		$headers = array(
			'Content-Type: application/json',
			'x-api-key: ' . $this->get_api_key(),
			'anthropic-version: ' . self::API_VERSION,
		);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- WP HTTP API lacks streaming callback support required for SSE.
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( $callback ) {
					$lines = explode( "\n", $data );
					foreach ( $lines as $line ) {
						if ( strpos( $line, 'data: ' ) === 0 ) {
							$json = substr( $line, 6 );

							$decoded = json_decode( $json, true );
							if ( ! $decoded ) {
								continue;
							}

							if ( $decoded['type'] === 'content_block_delta' &&
							isset( $decoded['delta']['text'] ) ) {
								$callback( $decoded['delta']['text'], false );
							}

							if ( $decoded['type'] === 'message_stop' ) {
								$callback( '', true );
							}
						}
					}
					return strlen( $data );
				},
				CURLOPT_TIMEOUT        => $this->timeout,
			)
		);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- WP HTTP API lacks streaming callback support required for SSE.
		curl_exec( $ch );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno -- WP HTTP API lacks streaming callback support required for SSE.
		if ( curl_errno( $ch ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- WP HTTP API lacks streaming callback support required for SSE.
			$callback( '', true, new WP_Error( 'curl_error', curl_error( $ch ) ) );
		}

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- WP HTTP API lacks streaming callback support required for SSE.
		curl_close( $ch );
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
		return self::MODELS[ $model ]['context'] ?? 200000;
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
			'x-api-key'         => $this->get_api_key(),
			'anthropic-version' => self::API_VERSION,
		);
	}
}
