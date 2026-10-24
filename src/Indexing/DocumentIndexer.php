<?php
/**
 * Indexação do conteúdo dos documentos dos itens (PDF, OCR, texto)
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Indexing;

use Oraculo_Tainacan\AI\AIProviderFactory;
use Oraculo_Tainacan\Vector\ChunkStore;
use WP_Error;

/**
 * Junta o texto do documento e dos anexos de um item, divide em trechos por
 * página e mantém a tabela de trechos em dia (só embeda o que mudou)
 *
 * Fontes de texto, em ordem de preferência para cada arquivo:
 * 1. `_tainacan_ocr_text` do anexo (Tainacan OCR Search; páginas por "\f");
 * 2. extração própria de PDF com camada de texto (pdftotext, em cache no anexo);
 * 3. `document_content_index` do item (core Tainacan / tainacan-ai), só para o
 *    documento principal e sem número de página.
 * Imagens e PDFs escaneados sem OCR ficam contados em `needs_ocr`.
 */
class DocumentIndexer {

	/** Meta do texto do OCR gravado pelo Tainacan OCR Search. */
	public const META_OCR_TEXT = '_tainacan_ocr_text';

	/** Cache da extração própria (texto com "\f") e assinatura do arquivo. */
	private const META_PDF_TEXT = '_oraculo_pdf_text';
	private const META_PDF_SIG  = '_oraculo_pdf_sig';

	/**
	 * Fila própria dos documentos (no item): valor = quando tentar, em
	 * timestamp. Separada da fila de metadados porque um PDF grande leva
	 * várias passadas e uma falha da API não pode tirar o item da busca.
	 */
	public const META_PENDING  = '_oraculo_docs_pending';
	private const META_ATTEMPTS = '_oraculo_docs_attempts';

	/** Trechos novos embedados por item a cada passada (o resto fica para a próxima). */
	private const MAX_NEW_PER_PASS = 256;

	/** Tamanho alvo do trecho, em caracteres (~400 tokens), e sobreposição. */
	private const CHUNK_CHARS   = 1600;
	private const CHUNK_OVERLAP = 200;

	/**
	 * Trechos.
	 *
	 * @var ChunkStore
	 */
	private ChunkStore $store;

	/**
	 * collect_documents() pode extrair PDFs sem cache?
	 *
	 * @var bool
	 */
	private bool $extract = true;

	/**
	 * PDFs pulados por falta de cache quando $extract = false.
	 *
	 * @var int
	 */
	private int $to_extract = 0;

	/**
	 * Construtor
	 *
	 * @param ChunkStore|null $store Armazenamento (injeção para testes).
	 */
	public function __construct( ?ChunkStore $store = null ) {
		$this->store = $store ?? new ChunkStore();
	}

	/**
	 * A indexação de documentos está ligada? (campo "document" em index_fields)
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
		return ChunkStore::is_ready() && in_array( 'document', (array) ( $options['index_fields'] ?? array() ), true );
	}

	/**
	 * Sincroniza os trechos de um item com o conteúdo atual dos documentos
	 *
	 * @param int    $item_id       Item.
	 * @param int    $collection_id Coleção.
	 * @param string $title         Título (entra no texto embedado como contexto).
	 * @param int    $max_new       Teto de trechos novos nesta chamada (0 = sem teto).
	 * @return array{chunks: int, embedded: int, deleted: int, needs_ocr: int, partial: bool}|WP_Error
	 */
	public function index_item( int $item_id, int $collection_id, string $title = '', int $max_new = 0 ) {
		$documents = $this->collect_documents( $item_id );
		$model     = self::model_signature();

		$desired = array();
		foreach ( $documents['files'] as $file ) {
			foreach ( $this->chunk_pages( $file['pages'] ) as $chunk ) {
				$location   = self::location_label( $file['label'], $chunk['page'] );
				$embed_text = ( '' !== $title ? $title . ' — ' : '' ) . $location . "\n" . $chunk['text'];
				// O modelo entra no hash: trocar de modelo refaz os trechos em
				// vez de comparar vetores de modelos diferentes.
				$hash = md5( $model . '|' . $file['attachment_id'] . '|' . $chunk['page'] . '|' . $embed_text );

				$desired[ $hash ] = array(
					'item_id'       => $item_id,
					'collection_id' => $collection_id,
					'attachment_id' => $file['attachment_id'],
					'source'        => $file['source'],
					'page'          => $chunk['page'],
					'chunk_no'      => $chunk['chunk_no'],
					'chunk_text'    => $chunk['text'],
					'content_hash'  => $hash,
					'embed_text'    => $embed_text,
				);
			}
		}

		/**
		 * Teto de trechos por item (protege custo e tempo em PDFs enormes).
		 *
		 * @param int $max Padrão 1500 (~600 páginas).
		 */
		$max = (int) apply_filters( 'oraculo_tainacan_max_chunks_per_item', 1500 );
		if ( count( $desired ) > $max ) {
			$desired = array_slice( $desired, 0, $max, true );
		}

		$existing = $this->store->get_item_hashes( $item_id );
		$missing  = array_diff_key( $desired, $existing );
		$stale    = array_diff_key( $existing, $desired );
		$partial  = false;
		if ( $max_new > 0 && count( $missing ) > $max_new ) {
			$missing = array_slice( $missing, 0, $max_new, true );
			$partial = true;
		}

		$embedded = 0;
		if ( ! empty( $missing ) ) {
			$factory  = new AIProviderFactory();
			$provider = $factory->create_for_embeddings();
			if ( ! $provider->is_configured() ) {
				return new WP_Error( 'not_configured', __( 'Provedor de embeddings não configurado.', 'oraculo-tainacan' ) );
			}

			foreach ( array_chunk( array_values( $missing ), 64 ) as $batch ) {
				$result = $provider->generate_embeddings_batch( array_column( $batch, 'embed_text' ) );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$embeddings = (array) ( $result['embeddings'] ?? array() );
				if ( count( $embeddings ) !== count( $batch ) ) {
					return new WP_Error( 'embedding_mismatch', __( 'A API devolveu um número de embeddings diferente do número de trechos.', 'oraculo-tainacan' ) );
				}

				$rows = array();
				foreach ( $batch as $index => $chunk ) {
					unset( $chunk['embed_text'] );
					$chunk['embedding']       = $embeddings[ $index ];
					$chunk['embedding_model'] = (string) ( $result['model'] ?? '' );
					$rows[]                   = $chunk;
				}
				$embedded += $this->store->insert_many( $rows );
			}
		}

		$deleted = $this->store->delete_ids( array_values( $stale ) );

		return array(
			'chunks'    => count( $desired ),
			'embedded'  => $embedded,
			'deleted'   => $deleted,
			'needs_ocr' => $documents['needs_ocr'],
			'partial'   => $partial,
		);
	}

	/**
	 * Rótulo "arquivo, p. N" traduzível
	 *
	 * @param string $file Nome do arquivo.
	 * @param int    $page Página (0 = desconhecida).
	 * @return string
	 */
	public static function location_label( string $file, int $page ): string {
		/* translators: 1: file name, 2: page number */
		return $page > 0 ? sprintf( __( '%1$s, p. %2$d', 'oraculo-tainacan' ), $file, $page ) : $file;
	}

	/**
	 * Identifica o modelo de embeddings em uso (provedor + modelos configurados)
	 *
	 * @return string
	 */
	private static function model_signature(): string {
		$options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
		$models  = array();
		foreach ( (array) $options as $key => $value ) {
			if ( is_string( $key ) && '_embedding_model' === substr( $key, -16 ) && is_scalar( $value ) ) {
				$models[ $key ] = (string) $value;
			}
		}
		ksort( $models );
		return (string) get_option( 'oraculo_embedding_provider', (string) ( $options['ai_provider'] ?? '' ) ) . '|' . md5( (string) wp_json_encode( $models ) );
	}

	/**
	 * Põe o item na fila de documentos
	 *
	 * @param int $item_id Item.
	 */
	public static function mark_pending( int $item_id ): void {
		update_post_meta( $item_id, self::META_PENDING, time() );
	}

	/**
	 * Itens com documentos a processar (já vencidos)
	 *
	 * @return int
	 */
	public static function count_pending(): int {
		$query = new \WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Queue lookup by the plugin's own indexed meta key; runs in the cron worker only.
				'meta_query'     => array(
					array(
						'key'     => self::META_PENDING,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Processa a fila de documentos até o tempo acabar
	 *
	 * @param int $budget Segundos (0 = sem limite, para o WP-CLI).
	 * @return array{processed: int, done: int, partial: int, failed: int, embedded: int, errors: string[]}
	 */
	public function process_pending( int $budget = 120 ): array {
		$summary = array(
			'processed' => 0,
			'done'      => 0,
			'partial'   => 0,
			'failed'    => 0,
			'embedded'  => 0,
			'errors'    => array(),
		);
		$started = microtime( true );

		do {
			$ids = get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 20,
					'no_found_rows'  => true,
					'orderby'        => 'meta_value_num',
					'order'          => 'ASC',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Queue lookup by the plugin's own indexed meta key; runs in the cron worker or WP-CLI only.
					'meta_query'     => array(
						array(
							'key'     => self::META_PENDING,
							'value'   => time(),
							'compare' => '<=',
							'type'    => 'NUMERIC',
						),
					),
				)
			);

			foreach ( $ids as $item_id ) {
				if ( $budget > 0 && microtime( true ) - $started > $budget ) {
					return $summary;
				}
				$item_id = (int) $item_id;
				++$summary['processed'];

				$post          = get_post( $item_id );
				$collection_id = $post ? AutoIndexer::get_collection_id_from_post_type( (string) $post->post_type ) : 0;
				if ( ! $post || $collection_id <= 0 || 'publish' !== $post->post_status ) {
					// Não publicado ou não é item: sai da fila (a remoção do
					// índice é feita pelos ganchos de status).
					delete_post_meta( $item_id, self::META_PENDING );
					delete_post_meta( $item_id, self::META_ATTEMPTS );
					continue;
				}

				$result = $this->index_item( $item_id, $collection_id, get_the_title( $post ), self::MAX_NEW_PER_PASS );

				if ( is_wp_error( $result ) ) {
					// Falha da API: espera crescente (5 min, 10, 20… até 1 dia),
					// sem nunca desistir — o vetor de metadados já está na busca.
					$attempts = (int) get_post_meta( $item_id, self::META_ATTEMPTS, true ) + 1;
					update_post_meta( $item_id, self::META_ATTEMPTS, $attempts );
					update_post_meta( $item_id, self::META_PENDING, time() + min( DAY_IN_SECONDS, 300 * ( 2 ** min( 8, $attempts - 1 ) ) ) );
					++$summary['failed'];
					$summary['errors'][] = 'Item #' . $item_id . ': ' . $result->get_error_message();
					continue;
				}

				$summary['embedded'] += $result['embedded'];
				if ( $result['partial'] ) {
					++$summary['partial'];
					update_post_meta( $item_id, self::META_PENDING, time() );
				} else {
					++$summary['done'];
					delete_post_meta( $item_id, self::META_PENDING );
					delete_post_meta( $item_id, self::META_ATTEMPTS );
				}
			}
		} while ( ! empty( $ids ) && ( 0 === $budget || microtime( true ) - $started <= $budget ) );

		return $summary;
	}

	/**
	 * Arquivos do item com texto, página a página
	 *
	 * @param int  $item_id Item.
	 * @param bool $extract Extrair PDFs ainda sem cache (false = só o que já está pronto, para relatórios).
	 * @return array{files: array, needs_ocr: int, to_extract: int}
	 */
	public function collect_documents( int $item_id, bool $extract = true ): array {
		$files            = array();
		$needs_ocr        = 0;
		$this->extract    = $extract;
		$this->to_extract = 0;

		$document      = (string) get_post_meta( $item_id, 'document', true );
		$document_type = (string) get_post_meta( $item_id, 'document_type', true );
		$document_id   = 'attachment' === $document_type ? (int) $document : 0;

		// Documento principal.
		if ( $document_id > 0 ) {
			$pages = $this->attachment_pages( $document_id );
			if ( null === $pages ) {
				$pages = $this->content_index_pages( $item_id );
				if ( empty( $pages ) && $this->is_ocr_candidate( $document_id ) ) {
					++$needs_ocr;
				}
			}
			if ( ! empty( $pages ) ) {
				$files[] = $this->file_entry( $document_id, 'document', $pages );
			}
		} elseif ( in_array( $document_type, array( 'text', 'url' ), true ) ) {
			$pages = 'text' === $document_type
				? $this->split_pages( wp_strip_all_tags( $document ) )
				: $this->content_index_pages( $item_id );
			if ( ! empty( $pages ) ) {
				$files[] = array(
					'attachment_id' => 0,
					'source'        => 'document',
					'label'         => __( 'documento', 'oraculo-tainacan' ),
					'pages'         => $pages,
				);
			}
		}

		// Demais anexos (a miniatura gerada pelo Tainacan fica de fora).
		$thumbnail_id = (int) get_post_thumbnail_id( $item_id );
		$attachments  = get_children(
			array(
				'post_parent' => $item_id,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'orderby'     => 'menu_order ID',
				'order'       => 'ASC',
				'fields'      => 'ids',
			)
		);
		foreach ( (array) $attachments as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id === $document_id || $attachment_id === $thumbnail_id ) {
				continue;
			}
			$pages = $this->attachment_pages( $attachment_id );
			if ( ! empty( $pages ) ) {
				$files[] = $this->file_entry( $attachment_id, 'attachment', $pages );
			} elseif ( $this->is_ocr_candidate( $attachment_id ) ) {
				++$needs_ocr;
			}
		}

		return array(
			'files'      => $files,
			'needs_ocr'  => $needs_ocr,
			'to_extract' => $this->to_extract,
		);
	}

	/**
	 * Texto de um anexo, página a página (null = sem texto disponível)
	 *
	 * @param int $attachment_id Anexo.
	 * @return array<int, string>|null página (1..N) => texto
	 */
	private function attachment_pages( int $attachment_id ): ?array {
		$ocr = (string) get_post_meta( $attachment_id, self::META_OCR_TEXT, true );
		if ( '' !== trim( $ocr ) ) {
			return $this->split_pages( $ocr );
		}

		if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			return null;
		}

		$text = $this->pdf_text( $attachment_id );
		return '' === $text ? null : $this->split_pages( $text );
	}

	/**
	 * Texto de PDF com camada de texto, em cache por assinatura do arquivo
	 *
	 * PDF escaneado sem OCR devolve '' (e o cache guarda isso, para não
	 * reextrair a cada indexação).
	 *
	 * @param int $attachment_id Anexo PDF.
	 * @return string Texto com "\f" entre páginas.
	 */
	private function pdf_text( int $attachment_id ): string {
		$file = (string) get_attached_file( $attachment_id );
		if ( '' === $file || ! file_exists( $file ) ) {
			return '';
		}

		$signature = filesize( $file ) . '|' . filemtime( $file );
		if ( get_post_meta( $attachment_id, self::META_PDF_SIG, true ) === $signature ) {
			return (string) get_post_meta( $attachment_id, self::META_PDF_TEXT, true );
		}
		if ( ! $this->extract ) {
			++$this->to_extract;
			return '';
		}

		$text = $this->run_pdftotext( $file );
		if ( null === $text && class_exists( '\Smalot\PdfParser\Parser' ) ) {
			try {
				$parser = new \Smalot\PdfParser\Parser();
				$pages  = array();
				foreach ( $parser->parseFile( $file )->getPages() as $page ) {
					$pages[] = (string) $page->getText();
				}
				$text = implode( "\f", $pages );
			} catch ( \Throwable $e ) {
				$text = null;
			}
		}
		if ( null === $text ) {
			// Nenhum extrator funcionou (pdftotext ausente, falha momentânea):
			// não grava cache, para tentar de novo na próxima indexação.
			return '';
		}

		// Média de menos de 40 letras por página = escaneado sem camada útil.
		$pages_count = max( 1, substr_count( rtrim( $text, "\f" ), "\f" ) + 1 );
		$letters     = (int) preg_match_all( '/\p{L}/u', $text );
		if ( $letters / $pages_count < 40 ) {
			$text = '';
		}

		update_post_meta( $attachment_id, self::META_PDF_TEXT, wp_slash( $text ) );
		update_post_meta( $attachment_id, self::META_PDF_SIG, $signature );

		return $text;
	}

	/**
	 * Roda pdftotext (null se indisponível)
	 *
	 * @param string $file PDF.
	 * @return string|null
	 */
	private function run_pdftotext( string $file ): ?string {
		if ( ! function_exists( 'proc_open' ) || ! function_exists( 'wp_tempnam' ) ) {
			if ( ! function_exists( 'wp_tempnam' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! function_exists( 'proc_open' ) ) {
				return null;
			}
		}

		// Saída em arquivo e -q (sem mensagens): com pipes, um PDF danificado
		// que despeja erros no stderr enchia o buffer e travava o worker.
		$out = wp_tempnam( 'oraculo-pdf.txt' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open,WordPress.PHP.NoSilencedErrors.Discouraged -- No PHP API extracts PDF text with page breaks as reliably as poppler's pdftotext; arguments go through escapeshellarg; a missing binary only warns and returns null (Smalot fallback).
		$proc = @proc_open( 'pdftotext -q -layout -enc UTF-8 ' . escapeshellarg( $file ) . ' ' . escapeshellarg( $out ), array(), $pipes );
		if ( ! is_resource( $proc ) ) {
			wp_delete_file( $out );
			return null;
		}
		$code = proc_close( $proc );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temp file written by pdftotext above; not a remote URL.
		$text = 0 === $code && file_exists( $out ) ? (string) file_get_contents( $out ) : null;
		wp_delete_file( $out );
		return $text;
	}

	/**
	 * document_content_index do item como uma "página" sem número
	 *
	 * @param int $item_id Item.
	 * @return array<int, string>
	 */
	private function content_index_pages( int $item_id ): array {
		$text = trim( (string) get_post_meta( $item_id, 'document_content_index', true ) );
		return '' === $text ? array() : array( 0 => $text );
	}

	/**
	 * Separa o texto nas quebras de página "\f" (páginas numeradas a partir de 1)
	 *
	 * Texto sem "\f" vira uma única "página" 0 (sem número de página conhecido).
	 *
	 * @param string $text Texto.
	 * @return array<int, string>
	 */
	private function split_pages( string $text ): array {
		$text = wp_check_invalid_utf8( $text, true );
		if ( false === strpos( $text, "\f" ) ) {
			return '' === trim( $text ) ? array() : array( 0 => $text );
		}
		$pages = array();
		foreach ( explode( "\f", $text ) as $index => $page ) {
			if ( '' !== trim( $page ) ) {
				$pages[ $index + 1 ] = $page;
			}
		}
		return $pages;
	}

	/**
	 * Divide cada página em trechos de ~CHUNK_CHARS com sobreposição
	 *
	 * @param array<int, string> $pages página => texto.
	 * @return array<int, array{page: int, chunk_no: int, text: string}>
	 */
	private function chunk_pages( array $pages ): array {
		$chunks = array();
		$number = 0;

		foreach ( $pages as $page => $raw ) {
			// Junta hifenização de fim de linha e colapsa espaços do layout.
			$text = preg_replace( '/(\p{L})-\s*\n\s*(\p{L})/u', '$1$2', (string) $raw );
			$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
			if ( (int) preg_match_all( '/\p{L}/u', $text ) < 40 ) {
				continue; // página em branco, só número ou ruído de OCR
			}

			$length = mb_strlen( $text );
			$start  = 0;
			while ( $start < $length ) {
				$end = min( $length, $start + self::CHUNK_CHARS );
				if ( $end < $length ) {
					// Corta no último fim de frase (ou espaço) do trecho.
					$window = mb_substr( $text, $start, $end - $start );
					$cut    = max( (int) mb_strrpos( $window, '. ' ), (int) mb_strrpos( $window, '; ' ) );
					if ( $cut < self::CHUNK_CHARS / 2 ) {
						$cut = (int) mb_strrpos( $window, ' ' );
					}
					if ( $cut > 0 ) {
						$end = $start + $cut + 1;
					}
				}

				$piece = trim( mb_substr( $text, $start, $end - $start ) );
				if ( '' !== $piece ) {
					$chunks[] = array(
						'page'     => (int) $page,
						'chunk_no' => $number++,
						'text'     => $piece,
					);
				}

				if ( $end >= $length ) {
					break;
				}
				$start = max( $start + 1, $end - self::CHUNK_OVERLAP );
			}
		}

		return $chunks;
	}

	/**
	 * Entrada de arquivo com rótulo legível
	 *
	 * @param int    $attachment_id Anexo.
	 * @param string $source        document | attachment.
	 * @param array  $pages         Páginas.
	 * @return array
	 */
	private function file_entry( int $attachment_id, string $source, array $pages ): array {
		return array(
			'attachment_id' => $attachment_id,
			'source'        => $source,
			'label'         => wp_basename( (string) get_attached_file( $attachment_id ) ),
			'pages'         => $pages,
		);
	}

	/**
	 * Arquivo que só fica pesquisável com OCR (imagem ou PDF sem texto)
	 *
	 * @param int $attachment_id Anexo.
	 * @return bool
	 */
	private function is_ocr_candidate( int $attachment_id ): bool {
		$mime = (string) get_post_mime_type( $attachment_id );
		return 'application/pdf' === $mime || 0 === strpos( $mime, 'image/' );
	}
}
