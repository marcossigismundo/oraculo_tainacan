<?php
/**
 * Cliente HTTP da AI API (CLIP + pgvector) do IBRAM
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Vector;

use WP_Error;

/**
 * Fala com a Museum CLIP Search API (FastAPI + pgvector).
 *
 * Contrato (gitlab.museus.gov.br/tainacan-ia/ai-api):
 * - GET  /health                → {"status":"ok"}
 * - GET  /v1/models             → {"object":"list","data":[{id,dimensions,...}]}
 * - POST /v1/search/text (JSON) → {"object":"search","data":[{id,score,metadata}]}
 * - POST /v1/indexing/index (multipart) → {"status":"success","external_id":...}
 *
 * Particularidades do servidor que este cliente absorve:
 * - Filtro de busca é igualdade de string sobre chaves do meta_data (sem ranges).
 * - Corte de score 0.6 aplicado no servidor.
 * - Indexação é INSERT-only com UNIQUE em external_id: reindexar o mesmo item
 *   devolve 500 de violação de constraint — tratado aqui como 'duplicate',
 *   que os chamadores interpretam como "já indexado".
 */
class ClipApiClient {

	/**
	 * URL base da API, sem barra final. Vazia = integração desligada.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Modelo CLIP usado em indexação e busca (precisam ser o mesmo checkpoint).
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Timeout de requisição em segundos.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Construtor
	 *
	 * @param array|null $options Opções do plugin; null carrega as globais.
	 */
	public function __construct( ?array $options = null ) {
		if ( null === $options ) {
			$options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
		}

		$this->base_url = untrailingslashit( (string) ( $options['clip_api_url'] ?? '' ) );
		$this->model    = (string) ( $options['clip_api_model'] ?? 'ViT-L-14' );
		$this->timeout  = max( 5, (int) ( $options['clip_api_timeout'] ?? 60 ) );
	}

	/**
	 * A integração está configurada?
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->base_url;
	}

	/**
	 * Modelo configurado
	 *
	 * @return string
	 */
	public function get_model(): string {
		return $this->model;
	}

	/**
	 * Checa a saúde da API
	 *
	 * @return true|WP_Error
	 */
	public function health() {
		$response = wp_remote_get( $this->base_url . '/health', array( 'timeout' => 10 ) );

		$body = $this->decode_response( $response );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( ( $body['status'] ?? '' ) !== 'ok' ) {
			return new WP_Error( 'clip_unhealthy', __( 'A AI API respondeu, mas não está saudável.', 'oraculo-tainacan' ) );
		}

		return true;
	}

	/**
	 * Lista os modelos CLIP carregados no servidor
	 *
	 * @return array|WP_Error Lista de ['id' => ..., 'dimensions' => ...].
	 */
	public function list_models() {
		$response = wp_remote_get( $this->base_url . '/v1/models', array( 'timeout' => 15 ) );

		$body = $this->decode_response( $response );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return new WP_Error( 'clip_bad_schema', __( 'Resposta inesperada de /v1/models.', 'oraculo-tainacan' ) );
		}

		return $body['data'];
	}

	/**
	 * Busca semântica por texto
	 *
	 * @param string $query  Texto (já limpo pelo QueryParser).
	 * @param int    $top_k  Máximo de resultados.
	 * @param array  $filter Filtro por igualdade sobre metadados (chave => string).
	 * @return array|WP_Error Lista de ['id' => string, 'score' => float, 'metadata' => array].
	 */
	public function search_text( string $query, int $top_k = 10, array $filter = array() ) {
		$payload = array(
			'query' => $query,
			'model' => $this->model,
			'top_k' => max( 1, min( 100, $top_k ) ),
		);

		if ( ! empty( $filter ) ) {
			$payload['filter'] = (object) $filter;
		}

		$response = wp_remote_post(
			$this->base_url . '/v1/search/text',
			array(
				'timeout' => $this->timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		$body = $this->decode_response( $response );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return new WP_Error( 'clip_bad_schema', __( 'Resposta inesperada de /v1/search/text.', 'oraculo-tainacan' ) );
		}

		$results = array();
		foreach ( $body['data'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['score'] ) ) {
				continue;
			}
			$results[] = array(
				'id'       => (string) $row['id'],
				'score'    => (float) $row['score'],
				'metadata' => is_array( $row['metadata'] ?? null ) ? $row['metadata'] : array(),
			);
		}

		return $results;
	}

	/**
	 * Indexa a imagem de um item no servidor remoto
	 *
	 * @param string $external_id ID externo (ID do item Tainacan como string).
	 * @param array  $image       ['base64' => dataURI] ou ['url' => URL pública].
	 * @param array  $metadata    Metadados a armazenar (valores string; viram filtros).
	 * @return true|WP_Error Código 'clip_duplicate' quando o external_id já existe lá.
	 */
	public function index_item( string $external_id, array $image, array $metadata = array() ) {
		$fields = array(
			'model'       => $this->model,
			'external_id' => $external_id,
			'metadata'    => (string) wp_json_encode( (object) $metadata ),
		);

		if ( isset( $image['base64'] ) ) {
			$fields['image_base64'] = (string) $image['base64'];
		} elseif ( isset( $image['url'] ) ) {
			$fields['url'] = (string) $image['url'];
		} else {
			return new WP_Error( 'clip_no_image', __( 'Nenhuma fonte de imagem informada.', 'oraculo-tainacan' ) );
		}

		// A rota é multipart/form-data (FastAPI Form/File); o WP HTTP API não
		// monta multipart sozinho, então o corpo é construído manualmente.
		$boundary = 'oraculo' . wp_generate_password( 24, false );
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
			$body .= $value . "\r\n";
		}
		$body .= "--{$boundary}--\r\n";

		$response = wp_remote_post(
			$this->base_url . '/v1/indexing/index',
			array(
				'timeout' => $this->timeout,
				'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		// INSERT-only + UNIQUE(external_id): o servidor devolve 500 com o texto
		// da exceção do banco. Duplicidade = item já está lá = sucesso lógico.
		if ( 500 === $code && preg_match( '/duplicate|unique|already exists/i', $raw ) ) {
			return new WP_Error( 'clip_duplicate', __( 'Item já indexado no servidor CLIP.', 'oraculo-tainacan' ) );
		}

		return new WP_Error(
			'clip_index_failed',
			sprintf(
				/* translators: 1: HTTP status code, 2: response body excerpt */
				__( 'Falha ao indexar no servidor CLIP (HTTP %1$d): %2$s', 'oraculo-tainacan' ),
				$code,
				mb_substr( wp_strip_all_tags( $raw ), 0, 200 )
			)
		);
	}

	/**
	 * Decodifica uma resposta do WP HTTP API validando status e JSON
	 *
	 * @param array|WP_Error $response Retorno de wp_remote_*.
	 * @return array|WP_Error
	 */
	private function decode_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			$detail = '';
			$maybe  = json_decode( $raw, true );
			if ( is_array( $maybe ) && isset( $maybe['detail'] ) && is_string( $maybe['detail'] ) ) {
				$detail = $maybe['detail'];
			}

			return new WP_Error(
				'clip_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error detail from the API */
					__( 'AI API retornou HTTP %1$d. %2$s', 'oraculo-tainacan' ),
					$code,
					mb_substr( $detail, 0, 200 )
				)
			);
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'clip_bad_json', __( 'A AI API não retornou JSON válido.', 'oraculo-tainacan' ) );
		}

		return $decoded;
	}
}
