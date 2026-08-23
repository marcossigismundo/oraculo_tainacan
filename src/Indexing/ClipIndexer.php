<?php
/**
 * Indexação remota de imagens no servidor CLIP (AI API do IBRAM)
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Indexing;

use Oraculo_Tainacan\Search\QueryParser;
use Oraculo_Tainacan\Vector\ClipApiClient;
use WP_Error;

/**
 * Envia a imagem de cada item ao servidor CLIP com metadados normalizados.
 *
 * Papel dos metadados: o filtro de busca do servidor é igualdade de string,
 * então cada item sobe com as chaves derivadas que o QueryParser gera na
 * busca (year/decade/century) mais collection_id — é isso que faz "obras do
 * século 21 que são de vidro" funcionar: "vidro" resolve no espaço visual do
 * CLIP; "século 21" vira filter {"century":"21"} sobre estas chaves.
 *
 * Estado por item em post meta `_oraculo_clip_indexed`:
 * - '1'        → enviado (ou já existia lá — INSERT-only, duplicidade = ok)
 * - 'no-image' → item sem imagem; nada a enviar (CLIP indexa só imagens)
 * - ausente    → pendente; a reconciliação diária re-tenta
 */
class ClipIndexer {

	/**
	 * Meta que marca o estado da indexação remota do item.
	 */
	public const META_INDEXED = '_oraculo_clip_indexed';

	/**
	 * Cliente da AI API.
	 *
	 * @var ClipApiClient
	 */
	private ClipApiClient $client;

	/**
	 * Construtor
	 *
	 * @param ClipApiClient|null $client Cliente customizado (testes).
	 */
	public function __construct( ?ClipApiClient $client = null ) {
		$this->client = $client ?? new ClipApiClient();
	}

	/**
	 * A indexação remota está habilitada?
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->client->is_configured();
	}

	/**
	 * Indexa um lote de itens, pulando os já enviados
	 *
	 * Melhor esforço: falha remota não é propagada como falha da fila local —
	 * o item fica sem a meta e a reconciliação diária re-tenta.
	 *
	 * @param int[] $item_ids IDs de itens Tainacan.
	 * @return array{sent:int, skipped:int, failed:int, errors:array}
	 */
	public function index_items( array $item_ids ): array {
		$summary = array(
			'sent'    => 0,
			'skipped' => 0,
			'failed'  => 0,
			'errors'  => array(),
		);

		if ( ! $this->is_enabled() ) {
			return $summary;
		}

		foreach ( array_unique( array_map( 'intval', $item_ids ) ) as $item_id ) {
			if ( '' !== (string) get_post_meta( $item_id, self::META_INDEXED, true ) ) {
				++$summary['skipped'];
				continue;
			}

			$result = $this->index_item( $item_id );

			if ( is_wp_error( $result ) ) {
				++$summary['failed'];
				$summary['errors'][] = 'Item #' . $item_id . ': ' . $result->get_error_message();
				continue;
			}

			if ( 'no-image' === $result ) {
				++$summary['skipped'];
			} else {
				++$summary['sent'];
			}
		}

		return $summary;
	}

	/**
	 * Indexa um único item no servidor CLIP
	 *
	 * @param int $item_id ID do item Tainacan.
	 * @return string|WP_Error 'indexed', 'already' ou 'no-image'.
	 */
	public function index_item( int $item_id ) {
		$formatted = \Oraculo_Tainacan\get_tainacan_item( $item_id );

		if ( ! $formatted ) {
			return new WP_Error( 'item_not_found', __( 'Item não encontrado.', 'oraculo-tainacan' ) );
		}

		$image = $this->resolve_image( $item_id );

		if ( null === $image ) {
			// Sem imagem não há o que indexar num índice visual. Marcar evita
			// re-tentativas diárias eternas; a marca é limpa se o item ganhar
			// imagem depois (via enqueue normal, que reprocessa o item).
			update_post_meta( $item_id, self::META_INDEXED, 'no-image' );
			return 'no-image';
		}

		$metadata = $this->build_metadata( $formatted );

		$result = $this->client->index_item( (string) $item_id, $image, $metadata );

		if ( is_wp_error( $result ) ) {
			if ( 'clip_duplicate' === $result->get_error_code() ) {
				// Já estava lá (INSERT-only, sem update remoto). Sucesso lógico.
				update_post_meta( $item_id, self::META_INDEXED, '1' );
				return 'already';
			}

			return $result;
		}

		update_post_meta( $item_id, self::META_INDEXED, '1' );

		return 'indexed';
	}

	/**
	 * Remove a marca de indexado (força reenvio na próxima passada)
	 *
	 * @param int $item_id ID do item.
	 */
	public static function reset_item( int $item_id ): void {
		delete_post_meta( $item_id, self::META_INDEXED );
	}

	/**
	 * Monta os metadados normalizados enviados ao servidor
	 *
	 * Todos os valores como string: o filtro remoto compara com as_string().
	 *
	 * @param array $formatted Item no formato de format_tainacan_item().
	 * @return array<string,string>
	 */
	private function build_metadata( array $formatted ): array {
		$metadata = array(
			'item_id'       => (string) ( $formatted['id'] ?? '' ),
			'collection_id' => (string) ( $formatted['collection_id'] ?? '' ),
			'title'         => mb_substr( (string) ( $formatted['title'] ?? '' ), 0, 200 ),
		);

		// Mesmas chaves temporais que o QueryParser extrai da consulta.
		return array_merge( $metadata, QueryParser::derive_temporal_facets( $formatted ) );
	}

	/**
	 * Resolve a imagem do item: arquivo local em base64, ou URL pública
	 *
	 * Preferência pelo base64: em ambientes onde a AI API roda em container
	 * ou host separado, uma URL local (localhost/intranet) não é alcançável
	 * de lá — os bytes embutidos sempre chegam.
	 *
	 * @param int $item_id ID do item.
	 * @return array|null ['base64' => dataURI] ou ['url' => ...], null sem imagem.
	 */
	private function resolve_image( int $item_id ): ?array {
		$attachment_id = (int) get_post_thumbnail_id( $item_id );

		if ( $attachment_id <= 0 ) {
			return null;
		}

		// Versão redimensionada primeiro: originais de acervo podem ter dezenas
		// de MB e o CLIP reduz para ~224px de qualquer forma.
		$intermediate = image_get_intermediate_size( $attachment_id, 'large' );
		$file         = null;

		if ( is_array( $intermediate ) && ! empty( $intermediate['path'] ) ) {
			$uploads = wp_get_upload_dir();
			$path    = trailingslashit( $uploads['basedir'] ) . $intermediate['path'];
			if ( file_exists( $path ) ) {
				$file = $path;
			}
		}

		if ( null === $file ) {
			$original = get_attached_file( $attachment_id );
			if ( $original && file_exists( $original ) ) {
				$file = $original;
			}
		}

		if ( null !== $file ) {
			$mime = get_post_mime_type( $attachment_id );
			if ( ! is_string( $mime ) || 0 !== strpos( $mime, 'image/' ) ) {
				return null;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local attachment file resolved via get_attached_file(); WP_Filesystem is for writes, and wp_remote_get on a local path does not apply.
			$bytes = file_get_contents( $file );
			if ( false !== $bytes ) {
				// data URI: formato que a AI API usa internamente para base64.
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Image bytes embedded in the API payload as a data URI (the transport format the CLIP service consumes); not obfuscation.
				return array( 'base64' => 'data:' . $mime . ';base64,' . base64_encode( $bytes ) );
			}
		}

		$url = get_the_post_thumbnail_url( $item_id, 'large' );

		return $url ? array( 'url' => $url ) : null;
	}
}
