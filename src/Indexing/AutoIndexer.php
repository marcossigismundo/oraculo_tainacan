<?php
/**
 * Indexação automática de itens do Tainacan
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Indexing;

use Oraculo_Tainacan\Vector\VectorStore;

/**
 * Mantém o índice vetorial em dia com o acervo, sem intervenção manual.
 *
 * Desenho em três tempos:
 *
 * 1. Captura — hooks do Tainacan e do WordPress apenas *marcam* o item como
 *    pendente. Nenhuma chamada de IA acontece durante o save: gerar embedding é
 *    uma requisição HTTP externa (timeout padrão de 120s) e bloquearia o editor
 *    de item e, pior, cada linha de uma importação em massa.
 *
 * 2. Fila — a pendência mora numa post meta do próprio item. Isso dispensa
 *    tabela nova, deduplica de graça (dez saves seguidos = uma pendência) e é
 *    consultável por WP_Query. O valor da meta é o timestamp a partir do qual o
 *    item pode ser processado, o que dá debounce na entrada e backoff no retry.
 *
 * 3. Worker — o cron consome a fila em lote, aproveitando a chamada única de
 *    embeddings de IndexingManager::index_item_batch().
 *
 * Remoções não passam pela fila: apagar um vetor é um DELETE local, sem HTTP,
 * então roda direto no hook. É o que garante que uma obra despublicada ou
 * excluída suma da busca no ato.
 */
class AutoIndexer {

	/**
	 * Meta que marca o item como pendente de indexação.
	 * O valor é o timestamp a partir do qual o worker pode processá-lo.
	 */
	private const META_PENDING = '_oraculo_index_pending';

	/**
	 * Meta com o número de tentativas já gastas no item.
	 */
	private const META_ATTEMPTS = '_oraculo_index_attempts';

	/**
	 * Hook do worker.
	 */
	public const CRON_HOOK = 'oraculo_process_index_queue';

	/**
	 * Schedule recorrente de segurança.
	 */
	public const CRON_SCHEDULE = 'oraculo_five_minutes';

	/**
	 * Opção usada como lock do worker (padrão WP_Upgrader::create_lock()).
	 */
	private const LOCK_OPTION = 'oraculo_index_queue_lock';

	/**
	 * Validade do lock, em segundos. Acima disso presume-se worker morto.
	 */
	private const LOCK_TIMEOUT = 600;

	/**
	 * Tentativas antes de desistir de um item.
	 */
	private const MAX_ATTEMPTS = 5;

	/**
	 * Store de vetores (usado apenas no caminho de remoção, que é síncrono).
	 *
	 * @var VectorStore|null
	 */
	private ?VectorStore $vector_store = null;

	/**
	 * Gerenciador de indexação (instanciado só dentro do worker).
	 *
	 * @var IndexingManager|null
	 */
	private ?IndexingManager $indexing_manager = null;

	/**
	 * Registra os hooks.
	 *
	 * Chamado no bootstrap, antes do 'init' do WordPress: aqui só se registram
	 * callbacks, nada toca em repositórios do Tainacan (os post types dos itens
	 * ainda nem existem neste ponto do ciclo).
	 */
	public function register(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The 5-minute schedule is only the safety net for a queue that is normally drained by a single event fired right after the save; a 15-minute floor would be the worst-case latency for a new item to become searchable.
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );

		// Garante o recorrente mesmo em instalações que já estavam ativas antes
		// desta versão (o agendamento da ativação não roda em upgrade).
		add_action( 'init', array( $this, 'ensure_scheduled' ) );

		if ( ! self::is_enabled() ) {
			return;
		}

		// Criação/edição de item pelo repositório (admin SPA, REST, importers
		// que usam a API do Tainacan).
		add_action( 'tainacan-insert-tainacan-item', array( $this, 'on_item_saved' ) );

		// Valor de metadado gravado depois do item: sem este hook, o texto
		// indexado ficaria só com título e descrição na primeira passagem.
		add_action( 'tainacan-insert-Item_Metadata_Entity', array( $this, 'on_item_metadata_saved' ) );

		// Rede de segurança no nível do WordPress: pega quick edit, bulk edit,
		// publicação agendada (future -> publish), lixeira e importers que
		// chamam wp_insert_post() direto, sem passar pelo repositório.
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );

		// Exclusão permanente: remove o vetor antes de o post sumir do banco.
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ) );
	}

	/**
	 * A indexação automática está ligada?
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		/**
		 * Permite desligar a indexação automática por código.
		 *
		 * @param bool $enabled Valor vindo da tela de indexação.
		 */
		return (bool) apply_filters( 'oraculo_tainacan_auto_index_enabled', (bool) get_option( 'oraculo_auto_index', true ) );
	}

	/**
	 * Registra o schedule de 5 minutos usado pelo worker
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function register_cron_schedule( $schedules ): array {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'A cada 5 minutos (Oráculo)', 'oraculo-tainacan' ),
		);

		return $schedules;
	}

	/**
	 * Garante que o worker recorrente esteja agendado
	 */
	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Item salvo via repositório do Tainacan
	 *
	 * @param mixed $item
	 */
	public function on_item_saved( $item ): void {
		if ( ! ( $item instanceof \Tainacan\Entities\Item ) ) {
			return;
		}

		$this->sync_item( (int) $item->get_id() );
	}

	/**
	 * Valor de metadado gravado
	 *
	 * @param mixed $item_metadata
	 */
	public function on_item_metadata_saved( $item_metadata ): void {
		if ( ! ( $item_metadata instanceof \Tainacan\Entities\Item_Metadata_Entity ) ) {
			return;
		}

		$item = $item_metadata->get_item();

		if ( ! ( $item instanceof \Tainacan\Entities\Item ) ) {
			return;
		}

		$this->sync_item( (int) $item->get_id() );
	}

	/**
	 * Mudança de status de post
	 *
	 * Cobre publicação, despublicação e envio para a lixeira, inclusive por
	 * caminhos que não passam pelo repositório do Tainacan.
	 *
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_Post $post
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( ! ( $post instanceof \WP_Post ) || $new_status === $old_status ) {
			return;
		}

		$collection_id = self::get_collection_id_from_post_type( (string) $post->post_type );

		if ( $collection_id <= 0 ) {
			return;
		}

		$this->sync_item( (int) $post->ID, $collection_id, (string) $new_status );
	}

	/**
	 * Item prestes a ser excluído em definitivo
	 *
	 * @param int $post_id
	 */
	public function on_before_delete_post( $post_id ): void {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! ( $post instanceof \WP_Post ) ) {
			return;
		}

		$collection_id = self::get_collection_id_from_post_type( (string) $post->post_type );

		if ( $collection_id <= 0 ) {
			return;
		}

		$this->remove_from_index( $post_id, $collection_id );
	}

	/**
	 * Decide o destino de um item: entrar na fila ou sair do índice
	 *
	 * @param int         $item_id
	 * @param int|null    $collection_id Resolvido a partir do post quando omitido.
	 * @param string|null $status        Status já conhecido, evita um get_post().
	 */
	private function sync_item( int $item_id, ?int $collection_id = null, ?string $status = null ): void {
		if ( $item_id <= 0 || ! self::is_enabled() ) {
			return;
		}

		if ( null === $status || null === $collection_id ) {
			$post = get_post( $item_id );

			if ( ! ( $post instanceof \WP_Post ) ) {
				return;
			}

			$status        = $status ?? (string) $post->post_status;
			$collection_id = $collection_id ?? self::get_collection_id_from_post_type( (string) $post->post_type );
		}

		if ( $collection_id <= 0 ) {
			return;
		}

		// Só conteúdo publicado entra no índice. Rascunho, revisão pendente,
		// privado e lixeira saem dele — o chat público não deve citar obra que
		// o visitante não pode abrir.
		if ( 'publish' !== $status ) {
			$this->unqueue( $item_id );
			$this->remove_from_index( $item_id, $collection_id );
			return;
		}

		$this->enqueue( $item_id );
	}

	/**
	 * Marca o item como pendente e agenda o worker
	 *
	 * @param int $item_id
	 */
	public function enqueue( int $item_id ): void {
		/**
		 * Filtra o atraso, em segundos, entre salvar o item e indexá-lo.
		 *
		 * O atraso é deliberado: o Tainacan grava o item e só depois os valores
		 * de metadado (repositórios distintos, ações distintas). Indexar no ato
		 * capturaria um texto incompleto. A janela também agrupa os vários saves
		 * de uma edição numa única indexação.
		 *
		 * @param int $delay Padrão 15.
		 */
		$delay = max( 0, (int) apply_filters( 'oraculo_tainacan_index_delay', 15 ) );

		update_post_meta( $item_id, self::META_PENDING, time() + $delay );

		$this->schedule_worker( $delay );
	}

	/**
	 * Remove a pendência do item
	 *
	 * @param int $item_id
	 */
	private function unqueue( int $item_id ): void {
		delete_post_meta( $item_id, self::META_PENDING );
		delete_post_meta( $item_id, self::META_ATTEMPTS );
	}

	/**
	 * Remove o item do índice vetorial
	 *
	 * Caminho síncrono de propósito: é um DELETE na tabela do plugin, sem
	 * chamada externa, e adiar faria a busca continuar citando obra removida.
	 *
	 * @param int $item_id
	 * @param int $collection_id
	 */
	private function remove_from_index( int $item_id, int $collection_id ): void {
		if ( null === $this->vector_store ) {
			$this->vector_store = new VectorStore();
		}

		$this->vector_store->delete( $item_id, $collection_id );
	}

	/**
	 * Agenda uma passada do worker
	 *
	 * Usa argumento próprio ('kick') para não colidir com a checagem de
	 * duplicidade do wp_schedule_single_event() contra o evento recorrente,
	 * que roda sem argumentos no mesmo hook.
	 *
	 * @param int $delay Segundos até a execução.
	 */
	private function schedule_worker( int $delay = 15 ): void {
		$timestamp = time() + max( 0, $delay );

		if ( wp_next_scheduled( self::CRON_HOOK, array( 'kick' ) ) ) {
			return;
		}

		wp_schedule_single_event( $timestamp, self::CRON_HOOK, array( 'kick' ) );
	}

	/**
	 * Processa um lote da fila
	 *
	 * Chamado pelo cron (recorrente e por kick) e pelo botão "Processar agora".
	 *
	 * @return array Resumo do lote.
	 */
	public function process_queue(): array {
		$summary = array(
			'processed' => 0,
			'indexed'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'remaining' => 0,
			'errors'    => array(),
		);

		if ( ! $this->acquire_lock() ) {
			// Outro worker está no lote; sair sem tocar na fila evita gastar
			// embeddings duas vezes pelo mesmo item.
			$summary['locked'] = true;
			return $summary;
		}

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.NoSilencedErrors.Discouraged -- A batch of embedding requests can outlast the default limit; set_time_limit() raises a warning (not a catchable error) when disabled by the host, so there is nothing to check for.
				@set_time_limit( 300 );
			}

			$item_ids = $this->get_due_items( $this->get_batch_size() );

			if ( empty( $item_ids ) ) {
				return $summary;
			}

			if ( null === $this->indexing_manager ) {
				$this->indexing_manager = new IndexingManager();
			}

			$result = $this->indexing_manager->index_items_by_id( $item_ids );

			$done = array_map( 'intval', array_merge( $result['indexed_ids'], $result['skipped_ids'] ) );

			foreach ( $done as $item_id ) {
				$this->unqueue( $item_id );
			}

			// Tudo que não voltou resolvido continua pendente. Inclui os
			// failed_ids e também IDs que a API simplesmente não devolveu —
			// tratar pela ausência evita perder item em resposta truncada.
			foreach ( array_diff( array_map( 'intval', $item_ids ), $done ) as $item_id ) {
				$this->defer( $item_id );
			}

			$summary['processed'] = count( $item_ids );
			$summary['indexed']   = count( $result['indexed_ids'] );
			$summary['skipped']   = count( $result['skipped_ids'] );
			$summary['failed']    = count( $item_ids ) - count( $done );
			$summary['errors']    = array_slice( $result['errors'], 0, 10 );

			// Fecha o lote invalidando o cache de busca mesmo quando o bump por
			// request já tinha sido gasto no primeiro upsert.
			\Oraculo_Tainacan\force_bump_index_version();
		} catch ( \Throwable $e ) {
			$summary['errors'][] = $e->getMessage();

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
				error_log( '[Oraculo] Falha no worker de indexação: ' . $e->getMessage() );
			}
		} finally {
			$this->release_lock();
		}

		$summary['remaining'] = $this->count_pending();

		// Sobrou fila: encadeia a próxima passada em vez de esperar o recorrente.
		if ( $summary['remaining'] > 0 ) {
			$this->schedule_worker( 30 );
		}

		return $summary;
	}

	/**
	 * Adia um item que falhou, com backoff exponencial
	 *
	 * Após MAX_ATTEMPTS o item sai da fila: insistir indefinidamente em um item
	 * problemático travaria o lote e queimaria cota da API a cada passada.
	 *
	 * @param int $item_id
	 */
	private function defer( int $item_id ): void {
		$attempts = (int) get_post_meta( $item_id, self::META_ATTEMPTS, true ) + 1;

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$this->unqueue( $item_id );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
				error_log( '[Oraculo] Item #' . $item_id . ' removido da fila após ' . $attempts . ' tentativas.' );
			}

			return;
		}

		// 1min, 4min, 9min, 16min — dá folga para rate limit e indisponibilidade.
		$backoff = min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * $attempts * $attempts );

		update_post_meta( $item_id, self::META_ATTEMPTS, $attempts );
		update_post_meta( $item_id, self::META_PENDING, time() + $backoff );
	}

	/**
	 * IDs de itens prontos para indexar
	 *
	 * @param int $limit
	 * @return int[]
	 */
	private function get_due_items( int $limit ): array {
		$post_types = self::get_item_post_types();

		if ( empty( $post_types ) ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
				// phpcs:ignore WordPress.DB.SlowMetaQuery.SlowMetaQuery -- Queue lookup by pending flag; the meta is the queue itself and there is no taxonomy equivalent.
				'meta_query'             => array(
					'pending' => array(
						'key'     => self::META_PENDING,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
				// Mais antigo primeiro: a fila é FIFO e o backoff empurra os
				// itens problemáticos para o fim naturalmente.
				'orderby'                => array( 'pending' => 'ASC' ),
			)
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Quantos itens ainda aguardam indexação (incluindo os em backoff)
	 *
	 * @return int
	 */
	public function count_pending(): int {
		$post_types = self::get_item_post_types();

		if ( empty( $post_types ) ) {
			return 0;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowMetaQuery.SlowMetaQuery -- Queue size for the admin screen; the pending flag is the queue itself.
				'meta_query'             => array(
					array(
						'key'     => self::META_PENDING,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Enfileira todos os itens publicados de uma coleção
	 *
	 * Usado pela reconciliação e por reindexações disparadas do admin sem
	 * bloquear a requisição.
	 *
	 * @param int $collection_id
	 * @return int Quantidade enfileirada.
	 */
	public function enqueue_collection( int $collection_id ): int {
		$post_type = self::get_post_type_from_collection_id( $collection_id );

		if ( '' === $post_type ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return 0;
		}

		foreach ( $ids as $item_id ) {
			$this->enqueue( (int) $item_id );
		}

		return count( $ids );
	}

	/**
	 * Tamanho do lote do worker
	 *
	 * @return int
	 */
	private function get_batch_size(): int {
		$options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
		$batch   = (int) get_option( 'oraculo_batch_size', $options['batch_size'] ?? 10 );
		$batch   = $batch > 0 ? $batch : 10;

		/**
		 * Filtra o tamanho do lote processado por passada do worker.
		 *
		 * @param int $batch
		 */
		return max( 1, min( 50, (int) apply_filters( 'oraculo_tainacan_queue_batch_size', $batch ) ) );
	}

	/**
	 * Post types de itens do Tainacan
	 *
	 * @return string[]
	 */
	public static function get_item_post_types(): array {
		if ( ! class_exists( '\Tainacan\Repositories\Collections' ) ) {
			return array();
		}

		$collections = \Tainacan\Repositories\Collections::get_instance()->fetch( array( 'status' => 'publish' ), 'OBJECT' );

		if ( ! is_array( $collections ) ) {
			return array();
		}

		$post_types = array();

		foreach ( $collections as $collection ) {
			$identifier = $collection->get_db_identifier();

			if ( is_string( $identifier ) && '' !== $identifier ) {
				$post_types[] = $identifier;
			}
		}

		return $post_types;
	}

	/**
	 * Post type de itens de uma coleção
	 *
	 * @param int $collection_id
	 * @return string Vazio se a coleção não existir.
	 */
	private static function get_post_type_from_collection_id( int $collection_id ): string {
		if ( ! class_exists( '\Tainacan\Repositories\Collections' ) ) {
			return '';
		}

		$collection = \Tainacan\Repositories\Collections::get_instance()->fetch( $collection_id );

		if ( ! ( $collection instanceof \Tainacan\Entities\Collection ) || ! $collection->get_id() ) {
			return '';
		}

		$identifier = $collection->get_db_identifier();

		return is_string( $identifier ) ? $identifier : '';
	}

	/**
	 * Extrai o ID da coleção a partir do post type do item
	 *
	 * Pré-filtra pelo prefixo antes de chamar o repositório: transition_post_status
	 * dispara para todo post do site, e a esmagadora maioria não é item Tainacan.
	 *
	 * @param string $post_type
	 * @return int 0 quando não é um post type de item.
	 */
	public static function get_collection_id_from_post_type( string $post_type ): int {
		if ( ! class_exists( '\Tainacan\Entities\Collection' ) || ! class_exists( '\Tainacan\Repositories\Collections' ) ) {
			return 0;
		}

		$prefix = \Tainacan\Entities\Collection::$db_identifier_prefix;

		if ( '' === $post_type || 0 !== strpos( $post_type, $prefix ) ) {
			return 0;
		}

		$collection_id = \Tainacan\Repositories\Collections::get_instance()->get_id_by_db_identifier( $post_type );

		return is_numeric( $collection_id ) ? (int) $collection_id : 0;
	}

	/**
	 * Tenta adquirir o lock do worker
	 *
	 * Usa add_option(), que é atômico no MySQL pela chave única de option_name —
	 * o mesmo mecanismo de WP_Upgrader::create_lock(). Um par get/set_transient
	 * não serviria: dois crons simultâneos passariam os dois pelo get.
	 *
	 * @param bool $retry Controla a única retomada após lock expirado.
	 * @return bool
	 */
	private function acquire_lock( bool $retry = true ): bool {
		if ( add_option( self::LOCK_OPTION, (string) time(), '', 'no' ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $locked_at > time() - self::LOCK_TIMEOUT ) {
			return false;
		}

		// Lock velho: worker anterior morreu no meio (timeout de PHP, deploy).
		if ( ! $retry ) {
			return false;
		}

		$this->release_lock();

		return $this->acquire_lock( false );
	}

	/**
	 * Libera o lock do worker
	 */
	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
