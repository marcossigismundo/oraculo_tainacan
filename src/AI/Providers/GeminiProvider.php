<?php
/**
 * Provedor Google Gemini
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\AI\Providers;

use Oraculo_Tainacan\AI\AbstractAIProvider;
use WP_Error;

/**
 * Implementação do provedor Google Gemini
 */
class GeminiProvider extends AbstractAIProvider {

	/**
	 * URL base da API
	 */
	private const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

	/**
	 * Modelos disponíveis
	 */
	private const MODELS = array(
		'gemini-2.0-flash-exp' => array(
			'name'         => 'Gemini 2.0 Flash (Experimental)',
			'context'      => 1048576,
			'input_price'  => 0,
			'output_price' => 0,
			'description'  => 'Modelo experimental mais recente',
		),
		'gemini-1.5-pro'       => array(
			'name'         => 'Gemini 1.5 Pro',
			'context'      => 2097152,
			'input_price'  => 0.00125,
			'output_price' => 0.005,
			'description'  => 'Modelo mais capaz com contexto de 2M tokens',
		),
		'gemini-1.5-flash'     => array(
			'name'         => 'Gemini 1.5 Flash',
			'context'      => 1048576,
			'input_price'  => 0.000075,
			'output_price' => 0.0003,
			'description'  => 'Modelo rápido e econômico',
		),
		'gemini-1.5-flash-8b'  => array(
			'name'         => 'Gemini 1.5 Flash 8B',
			'context'      => 1048576,
			'input_price'  => 0.0000375,
			'output_price' => 0.00015,
			'description'  => 'Versão menor e mais rápida',
		),
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_id(): string {
		return 'gemini';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return 'Google Gemini';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'IA do Google com contexto de até 2 milhões de tokens. Excelente para análise de documentos longos.', 'oraculo-tainacan' );
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
		return array(
			array(
				'id'          => 'text-embedding-004',
				'name'        => 'Text Embedding 004',
				'dimensions'  => 768,
				'description' => 'Modelo de embedding do Gemini',
			),
		);
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

		$model = $this->get_config( 'model', 'gemini-1.5-flash' );
		$url   = self::API_BASE_URL . "/models/{$model}?key=" . $this->get_api_key();

		$response = $this->make_request( $url, array(), array(), 'GET' );

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
				'model'             => $response['name'] ?? $model,
				'input_token_limit' => $response['inputTokenLimit'] ?? 0,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embedding( string $text, ?string $model = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Provedor Gemini não configurado.', 'oraculo-tainacan' ) );
		}

		$text = $this->validate_input( $text );

		if ( empty( $text ) ) {
			return new WP_Error( 'empty_input', __( 'Texto não pode estar vazio.', 'oraculo-tainacan' ) );
		}

		$model = $model ?? 'text-embedding-004';
		$url   = self::API_BASE_URL . "/models/{$model}:embedContent?key=" . $this->get_api_key();

		$response = $this->make_request_with_retry(
			$url,
			array(
				'model'   => "models/{$model}",
				'content' => array(
					'parts' => array(
						array( 'text' => $text ),
					),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['embedding']['values'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Resposta inválida da API.', 'oraculo-tainacan' ) );
		}

		return array(
			'embedding' => $response['embedding']['values'],
			'model'     => $model,
			'usage'     => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function generate_embeddings_batch( array $texts, ?string $model = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', __( 'Provedor Gemini não configurado.', 'oraculo-tainacan' ) );
		}

		$embeddings = array();
		$model      = $model ?? 'text-embedding-004';

		// Gemini não suporta batch nativo, processar um por um
		foreach ( $texts as $text ) {
			$result = $this->generate_embedding( $text, $model );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$embeddings[] = $result['embedding'];

			// Pequena pausa para evitar rate limiting
			usleep( 100000 ); // 100ms
		}

		return array(
			'embeddings' => $embeddings,
			'model'      => $model,
			'usage'      => array(),
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
			return new WP_Error( 'not_configured', __( 'Provedor Gemini não configurado.', 'oraculo-tainacan' ) );
		}

		$options = $this->prepare_options( $options );
		$model   = $options['model'] ?? $this->get_config( 'model', 'gemini-1.5-flash' );

		$url = self::API_BASE_URL . "/models/{$model}:generateContent?key=" . $this->get_api_key();

		// Formatar conteúdos para o formato Gemini
		$contents = array();
		foreach ( $messages as $message ) {
			$role       = $message['role'] === 'assistant' ? 'model' : 'user';
			$contents[] = array(
				'role'  => $role,
				'parts' => array(
					array( 'text' => $message['content'] ),
				),
			);
		}

		$body = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'maxOutputTokens' => $options['max_tokens'],
				'temperature'     => $options['temperature'],
			),
		);

		// Adicionar system instruction se fornecido
		if ( ! empty( $system_prompt ) ) {
			$body['systemInstruction'] = array(
				'parts' => array(
					array( 'text' => $system_prompt ),
				),
			);
		}

		$response = $this->make_request_with_retry( $url, $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			// Verificar se foi bloqueado por segurança
			if ( isset( $response['candidates'][0]['finishReason'] ) &&
				$response['candidates'][0]['finishReason'] === 'SAFETY' ) {
				return new WP_Error( 'safety_blocked', __( 'Resposta bloqueada por filtros de segurança.', 'oraculo-tainacan' ) );
			}
			return new WP_Error( 'invalid_response', __( 'Resposta inválida da API.', 'oraculo-tainacan' ) );
		}

		$usage = array(
			'prompt_tokens'     => $response['usageMetadata']['promptTokenCount'] ?? 0,
			'completion_tokens' => $response['usageMetadata']['candidatesTokenCount'] ?? 0,
			'total_tokens'      => $response['usageMetadata']['totalTokenCount'] ?? 0,
		);

		return array(
			'response'      => $response['candidates'][0]['content']['parts'][0]['text'],
			'model'         => $model,
			'usage'         => $usage,
			'cost'          => $this->calculate_cost( $usage, $model ),
			'finish_reason' => $response['candidates'][0]['finishReason'] ?? 'STOP',
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
		return self::MODELS[ $model ]['context'] ?? 32768;
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports_embeddings(): bool {
		return true;
	}
}
