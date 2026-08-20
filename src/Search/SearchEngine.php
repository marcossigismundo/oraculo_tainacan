<?php
/**
 * Motor de busca RAG (Retrieval-Augmented Generation)
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Search;

use Oraculo_Tainacan\AI\AIProviderFactory;
use Oraculo_Tainacan\Vector\VectorStore;
use Oraculo_Tainacan\Search\QueryParser;
use WP_Error;

/**
 * Motor de busca semântica com RAG
 */
class SearchEngine {

	/**
	 * Factory de provedores de IA
	 *
	 * @var AIProviderFactory
	 */
	private AIProviderFactory $ai_factory;

	/**
	 * Store de vetores
	 *
	 * @var VectorStore
	 */
	private VectorStore $vector_store;

	/**
	 * Opções do plugin
	 *
	 * @var array
	 */
	private array $options;

	/**
	 * Construtor
	 */
	public function __construct() {
		$this->ai_factory   = new AIProviderFactory();
		$this->vector_store = new VectorStore();
		$this->options      = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
	}

	/**
	 * Realiza busca semântica
	 *
	 * @param string $query Pergunta do usuário
	 * @param array  $collection_ids IDs das coleções (vazio = todas)
	 * @param array  $options Opções adicionais
	 * @return array|WP_Error
	 */
	public function search( string $query, array $collection_ids = array(), array $options = array() ) {
		$start_time = microtime( true );

		// Validar query
		$query = trim( $query );
		if ( empty( $query ) ) {
			return new WP_Error( 'empty_query', __( 'A pergunta não pode estar vazia.', 'oraculo-tainacan' ) );
		}

		// Backend CLIP (AI API do IBRAM): busca visual sem LLM — os resultados
		// vêm do espaço conjunto imagem-texto e a "resposta" é determinística.
		if ( $this->use_clip_backend() ) {
			return $this->clip_search( $query, $collection_ids, $options );
		}

		// Verificar cache
		$cache_key = $this->get_cache_key( $query, $collection_ids );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false && empty( $options['no_cache'] ) ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		try {
			// 1. Gerar embedding da query
			$embedding_provider = $this->ai_factory->create_for_embeddings();
			$embedding_result   = $embedding_provider->generate_embedding( $query );

			if ( is_wp_error( $embedding_result ) ) {
				return $embedding_result;
			}

			$query_embedding = $embedding_result['embedding'];

			// 2. Buscar itens similares no vector store
			$max_results = $options['max_results'] ?? $this->options['max_results'] ?? 10;
			$threshold   = $options['similarity_threshold'] ?? $this->options['similarity_threshold'] ?? 0.3;

			$similar_items = $this->vector_store->search(
				$query_embedding,
				$collection_ids,
				$max_results * 2, // Buscar mais para filtrar depois
				$threshold
			);

			if ( is_wp_error( $similar_items ) ) {
				return $similar_items;
			}

			if ( empty( $similar_items ) ) {
				return $this->build_no_results_response( $query );
			}

			// 3. Preparar contexto para o LLM
			$context = $this->prepare_context( $similar_items, $max_results );

			// 4. Gerar resposta com o LLM
			$chat_provider = $this->ai_factory->create_from_options();

			$system_prompt = $this->get_system_prompt();
			$search_prompt = $this->build_search_prompt( $query, $context );

			$ai_response = $chat_provider->generate_response(
				$search_prompt,
				$system_prompt,
				array(
					'max_tokens'  => $options['max_tokens'] ?? $this->options['max_tokens'] ?? 2000,
					'temperature' => $options['temperature'] ?? $this->options['temperature'] ?? 0.7,
				)
			);

			if ( is_wp_error( $ai_response ) ) {
				return $ai_response;
			}

			// 5. Formatar resposta final
			$response_time = round( ( microtime( true ) - $start_time ) * 1000 );

			$result = array(
				'query'            => $query,
				'response'         => $ai_response['response'],
				'items'            => $this->format_items( $similar_items, $query, $max_results ),
				'total_results'    => count( $similar_items ),
				'usage'            => $ai_response['usage'],
				'model'            => $ai_response['model'],
				'cost'             => $ai_response['cost'] ?? 0,
				'response_time_ms' => $response_time,
				'search_id'        => wp_generate_uuid4(),
				'from_cache'       => false,
			);

			// Salvar no cache
			$cache_duration = $this->options['cache_duration'] ?? 3600;
			set_transient( $cache_key, $result, $cache_duration );

			// Registrar log
			$this->log_search( $result, $collection_ids );

			return $result;

		} catch ( \Exception $e ) {
			\Oraculo_Tainacan\debug_log( 'Search error: ' . $e->getMessage(), null, 'error' );
			return new WP_Error( 'search_error', $e->getMessage() );
		}
	}

	/**
	 * Busca apenas por similaridade (sem LLM)
	 *
	 * @param string $query
	 * @param array  $collection_ids
	 * @param int    $limit
	 * @return array|WP_Error
	 */
	public function semantic_search( string $query, array $collection_ids = array(), int $limit = 10 ) {
		try {
			$embedding_provider = $this->ai_factory->create_for_embeddings();
			$embedding_result   = $embedding_provider->generate_embedding( $query );

			if ( is_wp_error( $embedding_result ) ) {
				return $embedding_result;
			}

			$similar_items = $this->vector_store->search(
				$embedding_result['embedding'],
				$collection_ids,
				$limit,
				$this->options['similarity_threshold'] ?? 0.3
			);

			if ( is_wp_error( $similar_items ) ) {
				return $similar_items;
			}

			return $this->format_items( $similar_items, $query, $limit );

		} catch ( \Exception $e ) {
			return new WP_Error( 'search_error', $e->getMessage() );
		}
	}

	/**
	 * Busca híbrida (semântica + keyword)
	 *
	 * @param string $query
	 * @param array  $collection_ids
	 * @param int    $limit
	 * @return array|WP_Error
	 */
	public function hybrid_search( string $query, array $collection_ids = array(), int $limit = 10 ) {
		// Busca semântica
		$semantic_results = $this->semantic_search( $query, $collection_ids, $limit );

		if ( is_wp_error( $semantic_results ) ) {
			return $semantic_results;
		}

		// Busca por keywords
		$keyword_results = $this->vector_store->keyword_search( $query, $collection_ids, $limit );

		if ( is_wp_error( $keyword_results ) ) {
			$keyword_results = array();
		}

		// Combinar resultados (Reciprocal Rank Fusion)
		$combined = $this->reciprocal_rank_fusion( $semantic_results, $keyword_results );

		return array_slice( $combined, 0, $limit );
	}

	/**
	 * Reciprocal Rank Fusion para combinar resultados
	 *
	 * @param array $list1
	 * @param array $list2
	 * @param int   $k Constante de suavização
	 * @return array
	 */
	private function reciprocal_rank_fusion( array $list1, array $list2, int $k = 60 ): array {
		$scores = array();

		foreach ( $list1 as $rank => $item ) {
			$id            = $item['id'];
			$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + 1 / ( $k + $rank + 1 );
			if ( ! isset( $scores[ $id . '_data' ] ) ) {
				$scores[ $id . '_data' ] = $item;
			}
		}

		foreach ( $list2 as $rank => $item ) {
			$id            = $item['id'];
			$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + 1 / ( $k + $rank + 1 );
			if ( ! isset( $scores[ $id . '_data' ] ) ) {
				$scores[ $id . '_data' ] = $item;
			}
		}

		// Ordenar por score
		$results = array();
		foreach ( $scores as $key => $value ) {
			if ( strpos( $key, '_data' ) !== false ) {
				continue;
			}
			$results[] = array_merge( $scores[ $key . '_data' ], array( 'rrf_score' => $value ) );
		}

		usort( $results, fn( $a, $b ) => $b['rrf_score'] <=> $a['rrf_score'] );

		return $results;
	}

	/**
	 * Prepara contexto para o LLM
	 *
	 * @param array $items
	 * @param int   $max_items
	 * @return string
	 */
	private function prepare_context( array $items, int $max_items ): string {
		$context_parts = array();

		$items = array_slice( $items, 0, $max_items );

		foreach ( $items as $index => $item ) {
			$num  = $index + 1;
			$text = \Oraculo_Tainacan\truncate_text( $item['content_text'], 500 );

			$context_parts[] = sprintf(
				"[%d] %s\nURL: %s\nSimilaridade: %.0f%%",
				$num,
				$text,
				$item['item_url'] ?? '',
				( $item['similarity'] ?? 0 ) * 100
			);
		}

		return implode( "\n\n", $context_parts );
	}

	/**
	 * Obtém prompt do sistema
	 *
	 * @return string
	 */
	private function get_system_prompt(): string {
		return $this->options['system_prompt'] ?? $this->get_default_system_prompt();
	}

	/**
	 * Prompt do sistema padrão
	 *
	 * @return string
	 */
	private function get_default_system_prompt(): string {
		return __(
			'Você é um assistente especializado em ajudar usuários a encontrar informações no acervo digital.
Suas respostas devem ser:
- Precisas e baseadas apenas nas informações fornecidas do acervo
- Claras e em português brasileiro
- Úteis, indicando sempre os itens relevantes encontrados
- Honestas quando não houver informação suficiente para responder

Quando citar itens do acervo, sempre mencione o título e forneça o link quando disponível.
Se a pergunta não puder ser respondida com as informações disponíveis, informe educadamente e sugira reformular a pergunta.',
			'oraculo-tainacan'
		);
	}

	/**
	 * Constrói prompt de busca
	 *
	 * @param string $query
	 * @param string $context
	 * @return string
	 */
	private function build_search_prompt( string $query, string $context ): string {
		$template = $this->options['search_prompt'] ?? $this->get_default_search_prompt();

		return str_replace(
			array( '{query}', '{context}' ),
			array( $query, $context ),
			$template
		);
	}

	/**
	 * Prompt de busca padrão
	 *
	 * @return string
	 */
	private function get_default_search_prompt(): string {
		return __(
			'Com base nos itens do acervo listados abaixo, responda à pergunta do usuário de forma concisa e informativa.

ITENS DO ACERVO:
{context}

PERGUNTA: {query}

Forneça uma resposta clara, mencionando os itens mais relevantes encontrados. Se houver links, inclua-os na resposta.',
			'oraculo-tainacan'
		);
	}

	/**
	 * Formata itens para resposta
	 *
	 * @param array  $items
	 * @param string $query
	 * @param int    $limit
	 * @return array
	 */
	private function format_items( array $items, string $query, int $limit ): array {
		$formatted = array();

		foreach ( array_slice( $items, 0, $limit ) as $item ) {
			$formatted[] = array(
				'id'              => $item['item_id'],
				'title'           => $item['item_title'] ?? '',
				'snippet'         => \Oraculo_Tainacan\generate_snippet( $item['content_text'], $query ),
				'url'             => $item['item_url'] ?? '',
				'collection_id'   => $item['collection_id'],
				'collection_name' => $item['collection_name'] ?? '',
				'similarity'      => round( ( $item['similarity'] ?? 0 ) * 100, 1 ),
				'metadata'        => (array) json_decode( $item['metadata_json'] ?? '{}', true ),
			);
		}

		return $formatted;
	}

	/**
	 * Resposta quando não há resultados
	 *
	 * @param string $query
	 * @return array
	 */
	private function build_no_results_response( string $query ): array {
		return array(
			'query'            => $query,
			'response'         => __( 'Não encontrei itens no acervo que correspondam à sua busca. Tente reformular sua pergunta ou usar termos diferentes.', 'oraculo-tainacan' ),
			'items'            => array(),
			'total_results'    => 0,
			'usage'            => array(),
			'model'            => '',
			'cost'             => 0,
			'response_time_ms' => 0,
			'search_id'        => wp_generate_uuid4(),
			'from_cache'       => false,
		);
	}

	/**
	 * Gera chave de cache
	 *
	 * @param string $query
	 * @param array  $collection_ids
	 * @return string
	 */
	private function get_cache_key( string $query, array $collection_ids ): string {
		$data = array(
			'query'       => strtolower( trim( $query ) ),
			'collections' => $collection_ids,
			'provider'    => $this->options['ai_provider'] ?? 'openai',
			// Backend e modelo participam da chave: alternar local ↔ CLIP nas
			// configurações não pode servir resultado do outro backend.
			'backend'     => $this->options['search_backend'] ?? 'local',
			'clip_model'  => $this->options['clip_api_model'] ?? '',
			// Versão do índice: qualquer escrita de vetor a incrementa, aposentando
			// as chaves antigas. Sem isso, um item novo só aparecia na busca depois
			// que o transient expirasse (cache_duration, padrão 1h).
			'index'       => \Oraculo_Tainacan\get_index_version(),
		);

		return 'oraculo_search_' . md5( wp_json_encode( $data ) );
	}

	/**
	 * O backend de busca configurado é o CLIP remoto?
	 *
	 * @return bool
	 */
	private function use_clip_backend(): bool {
		if ( ( $this->options['search_backend'] ?? 'local' ) !== 'clip' ) {
			return false;
		}

		return ( new \Oraculo_Tainacan\Vector\ClipApiClient( $this->options ) )->is_configured();
	}

	/**
	 * Busca via AI API do IBRAM (CLIP + pgvector), sem LLM
	 *
	 * Fluxo: QueryParser separa o que é visual do que é restrição estruturada
	 * ("obras do século 21 que são de vidro" → texto "obras de vidro" + filtro
	 * {century:"21"}); o texto vai ao encoder CLIP do servidor e o filtro é
	 * aplicado por igualdade sobre os metadados gravados na indexação remota.
	 *
	 * Limites do servidor absorvidos aqui:
	 * - Filtro é igualdade única → várias coleções e intervalos de anos são
	 *   pós-filtrados em PHP (pedindo top_k maior para ter sobra).
	 * - Zero resultados com filtro temporal → repete sem o filtro e sinaliza
	 *   `filter_relaxed`, para a UI explicar em vez de devolver vazio.
	 *
	 * @param string $query          Consulta em linguagem natural.
	 * @param array  $collection_ids Coleções (vazio = todas).
	 * @param array  $options        Opções (max_results, no_cache).
	 * @return array|WP_Error Mesma forma de resposta do backend local.
	 */
	private function clip_search( string $query, array $collection_ids, array $options ) {
		$start_time = microtime( true );

		$cache_key = $this->get_cache_key( $query, $collection_ids );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && empty( $options['no_cache'] ) ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$client      = new \Oraculo_Tainacan\Vector\ClipApiClient( $this->options );
		$parsed      = QueryParser::parse( $query );
		$max_results = (int) ( $options['max_results'] ?? $this->options['max_results'] ?? 10 );

		$filter = $parsed['filter'];

		// Coleção única entra no filtro do servidor; várias exigem pós-filtro
		// (igualdade única do lado de lá).
		$post_filter_collections = array();
		if ( 1 === count( $collection_ids ) ) {
			$filter['collection_id'] = (string) (int) reset( $collection_ids );
		} elseif ( count( $collection_ids ) > 1 ) {
			$post_filter_collections = array_map( 'intval', $collection_ids );
		}

		$needs_post_filter = ! empty( $post_filter_collections ) || null !== $parsed['year_range'];
		$top_k             = $needs_post_filter ? min( 50, $max_results * 5 ) : $max_results;

		$rows = $client->search_text( $parsed['clean_query'], $top_k, $filter );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		// Relaxamento: filtro temporal sem nenhum resultado geralmente indica
		// acervo sem a faceta preenchida, não ausência de obras. Repetir sem o
		// temporal (mantendo coleção) e avisar é mais útil que vazio seco.
		$filter_relaxed = false;
		if ( empty( $rows ) && ! empty( $parsed['filter'] ) ) {
			$relaxed = array_diff_key( $filter, $parsed['filter'] );
			$rows    = $client->search_text( $parsed['clean_query'], $top_k, $relaxed );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			$filter_relaxed = true;
		}

		// Pós-filtros que o servidor não expressa.
		if ( ! empty( $post_filter_collections ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( $row ) => in_array( (int) ( $row['metadata']['collection_id'] ?? 0 ), $post_filter_collections, true )
				)
			);
		}

		if ( null !== $parsed['year_range'] && ! $filter_relaxed ) {
			list( $min_year, $max_year ) = $parsed['year_range'];

			$in_range = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $min_year, $max_year ) {
						$year = (int) ( $row['metadata']['year'] ?? 0 );
						return $year >= $min_year && $year <= $max_year;
					}
				)
			);

			// Intervalo zerou tudo → mesmo tratamento do filtro de igualdade.
			if ( empty( $in_range ) && ! empty( $rows ) ) {
				$filter_relaxed = true;
			} else {
				$rows = $in_range;
			}
		}

		$items = $this->resolve_clip_items( $rows, $max_results, $query );

		$response_time = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		$result = array(
			'query'            => $query,
			'response'         => $this->build_clip_response_text( $parsed, count( $items ), $filter_relaxed ),
			'items'            => $items,
			'total_results'    => count( $items ),
			'usage'            => array(),
			'model'            => 'clip:' . $client->get_model(),
			'cost'             => 0,
			'response_time_ms' => $response_time,
			'search_id'        => wp_generate_uuid4(),
			'from_cache'       => false,
			'backend'          => 'clip',
			'applied_filter'   => $filter_relaxed ? array() : $parsed['filter'],
			'filter_relaxed'   => $filter_relaxed,
		);

		$cache_duration = $this->options['cache_duration'] ?? 3600;
		set_transient( $cache_key, $result, $cache_duration );

		$this->log_search( $result, $collection_ids );

		return $result;
	}

	/**
	 * Converte resultados remotos (external_id) em itens Tainacan exibíveis
	 *
	 * Valida cada ID contra o WordPress: só itens publicados e que ainda são
	 * itens Tainacan entram — o índice remoto é INSERT-only e pode reter
	 * registros de itens já removidos do acervo.
	 *
	 * @param array  $rows        Linhas de ClipApiClient::search_text().
	 * @param int    $max_results Corte final.
	 * @param string $query       Consulta original (para o snippet).
	 * @return array Itens no mesmo formato de format_items().
	 */
	private function resolve_clip_items( array $rows, int $max_results, string $query ): array {
		$items = array();

		foreach ( $rows as $row ) {
			if ( count( $items ) >= $max_results ) {
				break;
			}

			$item_id = (int) $row['id'];
			$post    = get_post( $item_id );

			if ( ! ( $post instanceof \WP_Post ) || 'publish' !== $post->post_status ) {
				continue;
			}

			$collection_id = \Oraculo_Tainacan\Indexing\AutoIndexer::get_collection_id_from_post_type( (string) $post->post_type );
			if ( $collection_id <= 0 ) {
				continue;
			}

			$collection      = get_post( $collection_id );
			$collection_name = ( $collection instanceof \WP_Post ) ? $collection->post_title : '';

			$description = wp_strip_all_tags( (string) $post->post_content );

			$items[] = array(
				'id'              => $item_id,
				'title'           => get_the_title( $post ),
				'snippet'         => '' !== $description ? \Oraculo_Tainacan\generate_snippet( $description, $query ) : '',
				'url'             => (string) get_permalink( $post ),
				'thumbnail'       => (string) get_the_post_thumbnail_url( $item_id, 'medium' ),
				'collection_id'   => $collection_id,
				'collection_name' => $collection_name,
				'similarity'      => round( ( $row['score'] ?? 0 ) * 100, 1 ),
				'metadata'        => (array) ( $row['metadata'] ?? array() ),
			);
		}

		return $items;
	}

	/**
	 * Texto de resposta determinístico do backend CLIP (sem LLM)
	 *
	 * @param array $parsed         Saída do QueryParser.
	 * @param int   $total          Itens encontrados.
	 * @param bool  $filter_relaxed O filtro temporal foi descartado?
	 * @return string
	 */
	private function build_clip_response_text( array $parsed, int $total, bool $filter_relaxed ): string {
		if ( 0 === $total ) {
			return __( 'Não encontrei obras no acervo que correspondam à sua busca. Tente reformular usando termos visuais (material, cor, tipo de objeto).', 'oraculo-tainacan' );
		}

		$text = sprintf(
			/* translators: 1: number of results found, 2: the visual search terms extracted from the query */
			_n( 'Encontrei %1$d obra no acervo para "%2$s".', 'Encontrei %1$d obras no acervo para "%2$s".', $total, 'oraculo-tainacan' ),
			$total,
			$parsed['clean_query']
		);

		$facet_label = $this->describe_temporal_filter( $parsed );

		if ( '' !== $facet_label && ! $filter_relaxed ) {
			/* translators: %s: human-readable temporal filter, e.g. "século 21" */
			$text .= ' ' . sprintf( __( 'Filtro aplicado: %s.', 'oraculo-tainacan' ), $facet_label );
		}

		if ( $filter_relaxed && '' !== $facet_label ) {
			/* translators: %s: human-readable temporal filter that returned no results */
			$text .= ' ' . sprintf( __( 'Nenhuma obra atendia ao filtro "%s"; exibindo as mais próximas sem esse filtro.', 'oraculo-tainacan' ), $facet_label );
		}

		return $text;
	}

	/**
	 * Rótulo humano do filtro temporal extraído
	 *
	 * @param array $parsed Saída do QueryParser.
	 * @return string Vazio quando não há filtro temporal.
	 */
	private function describe_temporal_filter( array $parsed ): string {
		if ( null !== $parsed['year_range'] ) {
			/* translators: 1: start year, 2: end year */
			return sprintf( __( 'entre %1$d e %2$d', 'oraculo-tainacan' ), $parsed['year_range'][0], $parsed['year_range'][1] );
		}

		if ( isset( $parsed['facets']['year'] ) ) {
			/* translators: %s: a specific year */
			return sprintf( __( 'ano %s', 'oraculo-tainacan' ), $parsed['facets']['year'] );
		}

		if ( isset( $parsed['facets']['decade'] ) ) {
			/* translators: %s: a decade, e.g. 1980 */
			return sprintf( __( 'década de %s', 'oraculo-tainacan' ), $parsed['facets']['decade'] );
		}

		if ( isset( $parsed['facets']['century'] ) ) {
			/* translators: %s: a century number */
			return sprintf( __( 'século %s', 'oraculo-tainacan' ), $parsed['facets']['century'] );
		}

		return '';
	}

	/**
	 * Registra log de busca
	 *
	 * @param array $result
	 * @param array $collection_ids
	 */
	private function log_search( array $result, array $collection_ids ): void {
		if ( empty( $this->options['enable_analytics'] ) ) {
			return;
		}

		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; no WP core API available for search logging.
		$wpdb->insert(
			$wpdb->prefix . 'oraculo_search_logs',
			array(
				'query_text'       => $result['query'],
				'query_hash'       => md5( strtolower( $result['query'] ) ),
				'user_id'          => get_current_user_id(),
				'session_id'       => $result['search_id'],
				'collection_ids'   => wp_json_encode( $collection_ids ),
				'results_count'    => $result['total_results'],
				'response_time_ms' => $result['response_time_ms'],
				'tokens_used'      => $result['usage']['total_tokens'] ?? 0,
				'model_used'       => $result['model'],
				'ip_address'       => $this->get_client_ip(),
				'user_agent'       => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Obtém IP do cliente
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IP address is used only internally for logging; not output or used in SQL.
				$ips = explode( ',', wp_unslash( $_SERVER[ $header ] ) );
				return trim( $ips[0] );
			}
		}

		return '';
	}

	/**
	 * Registra feedback de busca
	 *
	 * @param string $search_id
	 * @param string $feedback 'positive' ou 'negative'
	 * @return bool
	 */
	public function record_feedback( string $search_id, string $feedback ): bool {
		global $wpdb;

		$valid_feedback = array( 'positive', 'negative' );
		if ( ! in_array( $feedback, $valid_feedback ) ) {
			return false;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; no WP core API available.
		$result = $wpdb->update(
			$wpdb->prefix . 'oraculo_search_logs',
			array( 'feedback' => $feedback ),
			array( 'session_id' => $search_id ),
			array( '%s' ),
			array( '%s' )
		);
		\Oraculo_Tainacan\oraculo_tainacan_flush_cache();

		return $result !== false;
	}

	/**
	 * Obtém sugestões de busca baseadas no histórico
	 *
	 * @param int $limit
	 * @return array
	 */
	public function get_popular_searches( int $limit = 5 ): array {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; popular searches are analytics data; not critical to cache.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT query_text, COUNT(*) as count
             FROM {$wpdb->prefix}oraculo_search_logs
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY query_hash
             ORDER BY count DESC
             LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array_column( $results, 'query_text' );
	}

	/**
	 * Limpa cache de buscas
	 *
	 * @return int Número de entradas removidas
	 */
	public function clear_cache(): int {
		global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only plugin's own transient keys from options table; no WP API for bulk transient deletion by prefix.
		$count = $wpdb->query(
			"DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_oraculo_search_%'
             OR option_name LIKE '_transient_timeout_oraculo_search_%'"
		);

		return $count;
	}
}
