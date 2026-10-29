<?php
/**
 * Motor de busca RAG (Retrieval-Augmented Generation)
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Search;

use Oraculo_Tainacan\AI\AIProviderFactory;
use Oraculo_Tainacan\Indexing\DocumentIndexer;
use Oraculo_Tainacan\Vector\ChunkStore;
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
			$embedding_result   = $embedding_provider->generate_embedding( \Oraculo_Tainacan\retrieval_query( $query ) );

			if ( is_wp_error( $embedding_result ) ) {
				return $embedding_result;
			}

			$query_embedding = $embedding_result['embedding'];

			// 2. Buscar itens similares no vector store
			// Cast na origem: o WP-CLI e as opções salvas trazem string, e
			// prepare_context() exige int (strict_types).
			$max_results = (int) ( $options['max_results'] ?? $this->options['max_results'] ?? 10 );
			$threshold   = (float) ( $options['similarity_threshold'] ?? $this->options['similarity_threshold'] ?? 0.3 );

			$similar_items = $this->vector_store->search(
				$query_embedding,
				$collection_ids,
				$max_results * 2, // Buscar mais para filtrar depois
				$threshold
			);

			if ( is_wp_error( $similar_items ) ) {
				return $similar_items;
			}

			$similar_items = $this->attach_passages( $query_embedding, $similar_items, $collection_ids, $max_results * 2, (float) $threshold );

			if ( empty( $similar_items ) ) {
				return $this->build_no_results_response( $query );
			}

			// 3. Preparar contexto para o LLM
			$context = $this->prepare_context( $similar_items, $max_results );

			// 4. Gerar resposta com o LLM
			$chat_provider = $this->ai_factory->create_from_options();

			$system_prompt = $this->get_system_prompt();
			$search_prompt = $this->build_search_prompt( $query, $context );
			// As regras de citação [n] pressupõem os cartões dos itens na tela;
			// com as fontes ocultas, o texto é o único caminho até os itens.
			if ( ! empty( $this->options['appearance']['show_sources'] ?? true ) ) {
				$search_prompt .= "\n\n" . $this->get_answer_format_rules();
			}

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
				'items'            => $this->format_items( $similar_items, $query, $max_results, true ),
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
			$embedding_result   = $embedding_provider->generate_embedding( \Oraculo_Tainacan\retrieval_query( $query ) );

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

			$similar_items = $this->attach_passages( $embedding_result['embedding'], $similar_items, $collection_ids, $limit, (float) ( $this->options['similarity_threshold'] ?? 0.3 ) );

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
			$num = $index + 1;
			// Sem URL: os links ficam nos cartões numerados da interface. Com a URL
			// no contexto, o modelo copiava cada item como ficha (título, autor,
			// link), repetindo os cartões em texto puro.
			$text = preg_replace( '/\s*URL:\s*\S+/', '', (string) $item['content_text'] );
			$text = \Oraculo_Tainacan\truncate_text( (string) $text, 500 );

			// Trechos do documento que bateram com a pergunta, com arquivo e
			// página: é deles que vem a resposta quando o assunto está no PDF.
			foreach ( (array) ( $item['passages'] ?? array() ) as $passage ) {
				$view  = $this->passage_view( $passage, '' );
				$text .= "\n" . sprintf(
					/* translators: 1: file and page label, 2: passage text */
					__( 'Trecho de %1$s: %2$s', 'oraculo-tainacan' ),
					$view['label'],
					$view['context']
				);
			}

			$context_parts[] = sprintf( '[%d] %s', $num, $text );
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

Quando citar itens do acervo, use o número do item entre colchetes, como [1].
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

Forneça uma resposta clara, mencionando os itens mais relevantes encontrados.',
			'oraculo-tainacan'
		);
	}

	/**
	 * Regras fixas de formato da resposta, anexadas a qualquer prompt de busca
	 *
	 * Ficam fora do prompt editável porque a interface depende delas: os itens
	 * aparecem em cartões numerados e as citações [n] do texto viram atalhos
	 * para esses cartões. Prompts salvos de versões antigas pediam o link de
	 * cada item, e a resposta repetia os cartões em texto puro.
	 *
	 * @return string
	 */
	private function get_answer_format_rules(): string {
		return __(
			'FORMATO DA RESPOSTA:
- Escreva uma síntese curta (até 3 parágrafos ou uma lista de no máximo 6 tópicos) que responda à pergunta.
- Cite os itens pelo número entre colchetes, por exemplo [1] ou [2][5], logo após a informação que vem deles.
- Não escreva URLs nem fichas com título, autor e link: os itens já aparecem em cartões numerados abaixo da resposta.
- Quando a informação vier de um trecho de documento, diga a página no texto (por exemplo: "na p. 12 do relatório [3]").
- Pode usar **negrito** para destacar títulos e "- " para listas.',
			'oraculo-tainacan'
		);
	}

	/**
	 * Junta aos itens os trechos de documento mais próximos da consulta
	 *
	 * A nota do item passa a ser a maior entre a do vetor de metadados e a
	 * do melhor trecho (com bônus pequeno quando vários trechos batem), e
	 * itens achados só pelo conteúdo do documento entram na lista.
	 *
	 * @param array $query_embedding Vetor da consulta.
	 * @param array $items           Linhas do VectorStore::search().
	 * @param array $collection_ids  Coleções.
	 * @param int   $limit           Itens desejados.
	 * @param float $threshold       Similaridade mínima.
	 * @return array Itens reordenados, com 'passages' quando houver.
	 */
	private function attach_passages( array $query_embedding, array $items, array $collection_ids, int $limit, float $threshold ): array {
		if ( ! DocumentIndexer::is_enabled() ) {
			return $items;
		}

		$chunks = ( new ChunkStore() )->search( $query_embedding, $collection_ids, max( 30, $limit * 4 ), $threshold );
		if ( empty( $chunks ) ) {
			return $items;
		}

		$by_item = array();
		foreach ( $chunks as $chunk ) {
			$by_item[ $chunk['item_id'] ][] = $chunk;
		}

		$indexed = array();
		foreach ( $items as $item ) {
			$indexed[ (int) $item['item_id'] ] = $item;
		}

		$missing = array_diff( array_keys( $by_item ), array_keys( $indexed ) );
		foreach ( $this->vector_store->get_by_item_ids( $missing ) as $row ) {
			$row['similarity']                = 0.0;
			$indexed[ (int) $row['item_id'] ] = $row;
		}

		foreach ( $by_item as $item_id => $list ) {
			if ( ! isset( $indexed[ $item_id ] ) ) {
				continue; // item sem vetor de metadados (despublicado/removido)
			}
			$score                              = (float) $list[0]['similarity'] + min( 0.03, 0.01 * ( count( $list ) - 1 ) );
			$indexed[ $item_id ]['similarity']  = max( (float) $indexed[ $item_id ]['similarity'], $score );
			$indexed[ $item_id ]['passages']    = array_slice( $list, 0, 2 );
		}

		$merged = array_values( $indexed );
		usort( $merged, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );

		return array_slice( $merged, 0, max( $limit, count( $items ) ) );
	}

	/**
	 * Trecho pronto para exibir: arquivo, página, excerto e link
	 *
	 * @param array  $passage Linha do ChunkStore::search().
	 * @param string $query   Consulta (ancora o excerto; vazio = início).
	 * @return array{file: string, page: int, label: string, text: string, context: string, url: string, similarity: float}
	 */
	private function passage_view( array $passage, string $query ): array {
		$attachment_id = (int) ( $passage['attachment_id'] ?? 0 );
		$page          = (int) ( $passage['page'] ?? 0 );
		$file          = $attachment_id > 0 ? wp_basename( (string) get_attached_file( $attachment_id ) ) : __( 'documento', 'oraculo-tainacan' );
		$url           = $attachment_id > 0 ? (string) wp_get_attachment_url( $attachment_id ) : '';

		// Visualizador de PDF do navegador abre direto na página.
		if ( '' !== $url && $page > 0 && 'application/pdf' === get_post_mime_type( $attachment_id ) ) {
			$url .= '#page=' . $page;
		}

		$text = (string) $passage['chunk_text'];

		return array(
			'file'       => $file,
			'page'       => $page,
			'label'      => DocumentIndexer::location_label( $file, $page ),
			// Excerto curto em volta do termo, para a tela.
			'text'       => '' !== $query ? \Oraculo_Tainacan\generate_snippet( $text, \Oraculo_Tainacan\retrieval_query( $query ), 110 ) : \Oraculo_Tainacan\truncate_text( $text, 300 ),
			// Trecho longo, para o modelo de IA responder (busca e chat).
			'context'    => \Oraculo_Tainacan\truncate_text( $text, 700 ),
			'url'        => $url,
			'similarity' => round( (float) ( $passage['similarity'] ?? 0 ) * 100, 1 ),
		);
	}

	/**
	 * Formata itens para resposta
	 *
	 * @param array  $items
	 * @param string $query
	 * @param int    $limit
	 * @param bool   $for_cards Inclui miniatura e metadados resumidos (cartões da busca).
	 * @return array
	 */
	private function format_items( array $items, string $query, int $limit, bool $for_cards = false ): array {
		$formatted = array();
		$items     = array_slice( $items, 0, $limit );

		if ( $for_cards && ! empty( $items ) ) {
			// Uma consulta para as metas dos itens e outra para os anexos das
			// miniaturas, em vez de 2-3 por item dentro do laço.
			$item_ids = array_map( 'intval', array_column( $items, 'item_id' ) );
			update_meta_cache( 'post', $item_ids );
			$thumb_ids = array_filter( array_map( 'get_post_thumbnail_id', $item_ids ) );
			if ( ! empty( $thumb_ids ) ) {
				_prime_post_caches( array_map( 'intval', $thumb_ids ), false, true );
			}
		}

		foreach ( $items as $item ) {
			$metadata    = json_decode( $item['metadata_json'] ?? '{}', true );
			$metadata    = is_array( $metadata ) ? $metadata : array();
			$title       = (string) ( $item['item_title'] ?? '' );
			$entry       = array(
				'id'              => $item['item_id'],
				'title'           => $title,
				'snippet'         => \Oraculo_Tainacan\generate_snippet( $this->strip_index_labels( (string) $item['content_text'], $title ), \Oraculo_Tainacan\retrieval_query( $query ) ),
				'url'             => $item['item_url'] ?? '',
				'collection_id'   => $item['collection_id'],
				'collection_name' => $item['collection_name'] ?? '',
				'similarity'      => round( ( $item['similarity'] ?? 0 ) * 100, 1 ),
				'metadata'        => $metadata,
				'passages'        => array_map(
					function ( $passage ) use ( $query, $for_cards ) {
						$view = $this->passage_view( $passage, $query );
						if ( $for_cards ) {
							unset( $view['context'] ); // a tela não precisa do trecho longo
						}
						return $view;
					},
					(array) ( $item['passages'] ?? array() )
				),
			);
			if ( $for_cards ) {
				$entry['details']   = $this->summarize_metadata( $metadata, $title );
				$entry['thumbnail'] = (string) get_the_post_thumbnail_url( (int) $item['item_id'], 'medium' );
			}
			$formatted[] = $entry;
		}

		return $formatted;
	}

	/**
	 * Tira do texto indexado os rótulos internos (TÍTULO:, METADADOS:, URL:)
	 *
	 * O texto do vetor é montado para o embedding, não para leitura; sem esta
	 * limpeza o cartão repetia o título e mostrava "METADADOS: Título: ...".
	 *
	 * @param string $text  Texto indexado.
	 * @param string $title Título do item, já exibido no cartão.
	 * @return string
	 */
	private function strip_index_labels( string $text, string $title ): string {
		// Com /u, UTF-8 inválido faz o preg_replace devolver null e o texto sumir.
		$text = wp_check_invalid_utf8( $text, true );
		$text = preg_replace( '/\s*URL:\s*\S+/u', '', $text );
		$text = preg_replace( '/^\s*TÍTULO:[^\n]*\n?/u', '', (string) $text );
		$text = preg_replace( '/\b(DESCRIÇÃO|METADADOS):\s*/u', '', (string) $text );
		if ( '' !== $title ) {
			$text = preg_replace( '/T[íi]tulo:\s*' . preg_quote( $title, '/' ) . '\s*\|?\s*/iu', '', (string) $text );
		}
		return trim( (string) $text );
	}

	/**
	 * Até 3 pares "metadado: valor" curtos para o cartão do item
	 *
	 * @param array  $metadata slug => {name, value}, como gravado na indexação.
	 * @param string $title    Título do item (não se repete no cartão).
	 * @return array<int, array{label: string, value: string}>
	 */
	private function summarize_metadata( array $metadata, string $title ): array {
		$details = array();

		foreach ( $metadata as $meta ) {
			if ( ! is_array( $meta ) || empty( $meta['name'] ) || ! isset( $meta['value'] ) || ! is_scalar( $meta['value'] ) ) {
				continue;
			}
			$value = trim( wp_strip_all_tags( (string) $meta['value'] ) );
			if ( '' === $value || 0 === strcasecmp( $value, $title ) || mb_strlen( $value ) > 120 ) {
				continue;
			}
			// "[100] Autor Principal — Pessoa" vira "Autor Principal": o código
			// MARC e o qualificador só ocupam a linha estreita do cartão.
			$label     = preg_replace( '/^\s*\[[^\]]*\]\s*/u', '', (string) $meta['name'] );
			$label     = trim( (string) preg_split( '/\s+[—–]\s+/u', (string) $label )[0] );
			$details[] = array(
				'label' => '' !== $label ? $label : (string) $meta['name'],
				'value' => $value,
			);
			if ( count( $details ) >= 3 ) {
				break;
			}
		}

		return $details;
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
			// Formato da resposta (citações [n] e cartões): aposenta respostas
			// guardadas no formato anterior, com URLs em texto puro.
			'format'      => 5,
		);

		return 'oraculo_search_' . md5( wp_json_encode( $data ) );
	}

	/**
	 * O backend de busca configurado é o CLIP remoto?
	 *
	 * @return bool
	 */
	private function use_clip_backend(): bool {
		// O CLIP agora é selecionado como provedor de IA (card, igual aos
		// demais). O search_backend antigo é aceito por compatibilidade com
		// configurações salvas pela 2.3.x/2.4.0, onde era um select próprio
		// na aba Geral.
		$is_clip = 'clip' === ( $this->options['ai_provider'] ?? '' )
			|| 'clip' === ( $this->options['search_backend'] ?? '' );

		if ( ! $is_clip ) {
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
