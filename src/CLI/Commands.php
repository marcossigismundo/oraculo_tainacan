<?php
/**
 * Comandos WP-CLI do Oráculo Tainacan
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\CLI;

use Oraculo_Tainacan\Indexing\IndexingManager;
use Oraculo_Tainacan\Vector\VectorStore;
use Oraculo_Tainacan\Search\SearchEngine;
use Oraculo_Tainacan\AI\AIProviderFactory;
use Oraculo_Tainacan\Analytics\AnalyticsManager;
use WP_CLI;
use WP_CLI_Command;

/**
 * Gerencia o Oráculo Tainacan via linha de comando.
 *
 * ## EXEMPLOS
 *
 *     # Indexar uma coleção
 *     $ wp oraculo index 123
 *
 *     # Ver status de indexação
 *     $ wp oraculo status
 *
 *     # Fazer uma busca
 *     $ wp oraculo search "documentos sobre educação"
 *
 *     # Testar conexão com provedor
 *     $ wp oraculo test-connection openai
 */
class Commands extends WP_CLI_Command {

	/**
	 * Indexa uma coleção do Tainacan.
	 *
	 * ## OPTIONS
	 *
	 * <collection_id>
	 * : ID da coleção a indexar.
	 *
	 * [--force]
	 * : Força reindexação completa.
	 *
	 * [--batch-size=<size>]
	 * : Número de itens por batch.
	 *
	 * [--dry-run]
	 * : Simula a indexação sem executar.
	 *
	 * ## EXEMPLOS
	 *
	 *     # Indexar coleção 123
	 *     $ wp oraculo index 123
	 *
	 *     # Reindexar forçadamente
	 *     $ wp oraculo index 123 --force
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function index( $args, $assoc_args ) {
		$collection_id = (int) $args[0];
		$force         = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$dry_run       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		if ( $collection_id <= 0 ) {
			WP_CLI::error( 'ID de coleção inválido.' );
		}

		$indexing = new IndexingManager();

		// Verificar se coleção existe
		$collections = \Oraculo_Tainacan\get_tainacan_collections();
		$collection  = array_filter( $collections, fn( $c ) => $c['id'] === $collection_id );

		if ( empty( $collection ) ) {
			WP_CLI::error( "Coleção #{$collection_id} não encontrada." );
		}

		$collection = reset( $collection );

		WP_CLI::log( "Iniciando indexação da coleção: {$collection['name']}" );
		WP_CLI::log( "Total de itens: {$collection['items_count']}" );

		if ( $dry_run ) {
			WP_CLI::success( 'Simulação concluída. Use sem --dry-run para executar.' );
			return;
		}

		if ( $force ) {
			WP_CLI::log( 'Modo forçado: limpando vetores existentes...' );
		}

		$result = $indexing->start_indexing( $collection_id, $force );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( 'Indexação iniciada. Processando...' );

		// Processar batches em loop
		$progress = \WP_CLI\Utils\make_progress_bar( 'Indexando', $collection['items_count'] );

		while ( true ) {
			$status = $indexing->process_next_batch( $collection_id );

			if ( $status['status'] === 'completed' ) {
				$progress->finish();
				WP_CLI::success( "Indexação concluída! {$status['processed']} itens indexados." );
				break;
			}

			if ( $status['status'] === 'error' ) {
				$progress->finish();
				WP_CLI::error( "Erro: {$status['error']}" );
			}

			if ( isset( $status['processed'] ) ) {
				$progress->tick( $status['processed'] );
			}

			// Pequena pausa para não sobrecarregar
			usleep( 100000 );
		}
	}

	/**
	 * Exibe status de indexação.
	 *
	 * ## OPTIONS
	 *
	 * [<collection_id>]
	 * : ID da coleção (opcional).
	 *
	 * [--format=<format>]
	 * : Formato de saída (table, json, csv).
	 *
	 * ## EXEMPLOS
	 *
	 *     # Ver status de todas as coleções
	 *     $ wp oraculo status
	 *
	 *     # Ver status de uma coleção específica
	 *     $ wp oraculo status 123
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function status( $args, $assoc_args ) {
		$collection_id = isset( $args[0] ) ? (int) $args[0] : null;
		$format        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$indexing     = new IndexingManager();
		$vector_store = new VectorStore();

		if ( $collection_id ) {
			$status = $indexing->get_status( $collection_id );

			WP_CLI::log( "Status da coleção #{$collection_id}:" );
			WP_CLI::log( "  Status: {$status['status']}" );
			WP_CLI::log( "  Itens indexados: {$status['indexed_items']}" );
			WP_CLI::log( "  Total de itens: {$status['total_items']}" );
			WP_CLI::log( "  Porcentagem: {$status['percentage']}%" );
			return;
		}

		// Status geral
		$collections = \Oraculo_Tainacan\get_tainacan_collections();
		$data        = array();

		foreach ( $collections as $collection ) {
			$indexed    = $vector_store->count_by_collection( $collection['id'] );
			$percentage = $collection['items_count'] > 0
				? round( ( $indexed / $collection['items_count'] ) * 100, 1 )
				: 0;

			$data[] = array(
				'ID'          => $collection['id'],
				'Nome'        => $collection['name'],
				'Total'       => $collection['items_count'],
				'Indexados'   => $indexed,
				'Porcentagem' => $percentage . '%',
			);
		}

		WP_CLI\Utils\format_items( $format, $data, array( 'ID', 'Nome', 'Total', 'Indexados', 'Porcentagem' ) );
	}

	/**
	 * Realiza uma busca no acervo.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : Pergunta em linguagem natural.
	 *
	 * [--collections=<ids>]
	 * : IDs das coleções separados por vírgula.
	 *
	 * [--limit=<n>]
	 * : Número máximo de resultados.
	 *
	 * [--no-ai]
	 * : Retorna apenas resultados semânticos sem resposta IA.
	 *
	 * [--format=<format>]
	 * : Formato de saída (json, table).
	 *
	 * ## EXEMPLOS
	 *
	 *     # Busca simples
	 *     $ wp oraculo search "documentos sobre educação"
	 *
	 *     # Busca em coleções específicas
	 *     $ wp oraculo search "fotos antigas" --collections=1,2,3
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function search( $args, $assoc_args ) {
		$query       = $args[0];
		$collections = array();
		$limit       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 10 );
		$no_ai       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'no-ai', false );
		$format      = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! empty( $assoc_args['collections'] ) ) {
			$collections = array_map( 'intval', explode( ',', $assoc_args['collections'] ) );
		}

		WP_CLI::log( "Buscando: \"{$query}\"..." );

		$search = new SearchEngine();

		if ( $no_ai ) {
			$result = $search->semantic_search( $query, $collections, $limit );
		} else {
			$result = $search->search( $query, $collections, array( 'max_results' => $limit ) );
		}

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( ! $no_ai && isset( $result['response'] ) ) {
			WP_CLI::log( "\n=== Resposta do Oráculo ===\n" );
			WP_CLI::log( $result['response'] );
			WP_CLI::log( "\n=== Fontes ===\n" );
		}

		$items = $no_ai ? $result : ( $result['items'] ?? array() );

		if ( empty( $items ) ) {
			WP_CLI::warning( 'Nenhum resultado encontrado.' );
			return;
		}

		if ( $format === 'json' ) {
			WP_CLI::log( wp_json_encode( $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			$table_data = array_map(
				function ( $item ) {
					return array(
						'ID'           => $item['id'],
						'Título'       => substr( $item['title'] ?? '', 0, 50 ),
						'Coleção'      => $item['collection_name'] ?? '',
						'Similaridade' => ( $item['similarity'] ?? 0 ) . '%',
					);
				},
				$items
			);

			WP_CLI\Utils\format_items( 'table', $table_data, array( 'ID', 'Título', 'Coleção', 'Similaridade' ) );
		}

		if ( ! $no_ai && isset( $result['usage'] ) ) {
			WP_CLI::log( "\nTokens usados: " . ( $result['usage']['total_tokens'] ?? 0 ) );
			WP_CLI::log( 'Tempo de resposta: ' . ( $result['response_time_ms'] ?? 0 ) . 'ms' );
		}
	}

	/**
	 * Testa conexão com provedor de IA.
	 *
	 * ## OPTIONS
	 *
	 * [<provider>]
	 * : ID do provedor (openai, gemini, deepseek, ollama, groq, claude).
	 *
	 * ## EXEMPLOS
	 *
	 *     # Testar provedor atual
	 *     $ wp oraculo test-connection
	 *
	 *     # Testar provedor específico
	 *     $ wp oraculo test-connection openai
	 *
	 * @subcommand test-connection
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function test_connection( $args, $assoc_args ) {
		$factory = new AIProviderFactory();

		$provider_id = isset( $args[0] ) ? $args[0] : $factory->get_current_provider();

		WP_CLI::log( "Testando conexão com: {$provider_id}..." );

		try {
			$provider = $factory->create( $provider_id );
			$result   = $provider->test_connection();

			if ( $result['success'] ) {
				WP_CLI::success( $result['message'] );

				if ( ! empty( $result['details'] ) ) {
					WP_CLI::log( 'Detalhes:' );
					foreach ( $result['details'] as $key => $value ) {
						if ( is_array( $value ) ) {
							$value = implode( ', ', $value );
						}
						WP_CLI::log( "  {$key}: {$value}" );
					}
				}
			} else {
				WP_CLI::error( $result['message'] );
			}
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Limpa vetores de uma coleção.
	 *
	 * ## OPTIONS
	 *
	 * [<collection_id>]
	 * : ID da coleção. Se não informado, limpa todos.
	 *
	 * [--yes]
	 * : Confirma a operação sem perguntar.
	 *
	 * ## EXEMPLOS
	 *
	 *     # Limpar vetores de uma coleção
	 *     $ wp oraculo clear-vectors 123 --yes
	 *
	 *     # Limpar todos os vetores
	 *     $ wp oraculo clear-vectors --yes
	 *
	 * @subcommand clear-vectors
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function clear_vectors( $args, $assoc_args ) {
		$collection_id = isset( $args[0] ) ? (int) $args[0] : null;

		WP_CLI::confirm( 'Tem certeza que deseja limpar os vetores?', $assoc_args );

		$vector_store = new VectorStore();

		if ( $collection_id ) {
			$count = $vector_store->delete_collection( $collection_id );
			WP_CLI::success( "Removidos {$count} vetores da coleção #{$collection_id}." );
		} else {
			global $wpdb;
			$count = $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}oraculo_vectors" );
			WP_CLI::success( 'Todos os vetores foram removidos.' );
		}
	}

	/**
	 * Exibe estatísticas de uso.
	 *
	 * ## OPTIONS
	 *
	 * [--period=<period>]
	 * : Período (today, week, month, year, all).
	 *
	 * [--format=<format>]
	 * : Formato de saída (table, json).
	 *
	 * ## EXEMPLOS
	 *
	 *     # Ver estatísticas do mês
	 *     $ wp oraculo stats
	 *
	 *     # Ver estatísticas da semana em JSON
	 *     $ wp oraculo stats --period=week --format=json
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function stats( $args, $assoc_args ) {
		$period = \WP_CLI\Utils\get_flag_value( $assoc_args, 'period', 'month' );
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$analytics = new AnalyticsManager();
		$stats     = $analytics->get_stats( $period );

		if ( $format === 'json' ) {
			WP_CLI::log( wp_json_encode( $stats, JSON_PRETTY_PRINT ) );
			return;
		}

		WP_CLI::log( "=== Estatísticas ({$period}) ===\n" );
		WP_CLI::log( 'Total de buscas: ' . number_format( $stats['total_searches'] ) );
		WP_CLI::log( 'Buscas únicas: ' . number_format( $stats['unique_searches'] ) );
		WP_CLI::log( "Taxa de sucesso: {$stats['success_rate']}%" );
		WP_CLI::log( "Taxa de satisfação: {$stats['satisfaction_rate']}%" );
		WP_CLI::log( 'Tokens utilizados: ' . number_format( $stats['total_tokens'] ) );
		WP_CLI::log( "Tempo médio de resposta: {$stats['avg_response_time_ms']}ms" );
		WP_CLI::log( 'Usuários únicos: ' . number_format( $stats['unique_users'] ) );
	}

	/**
	 * Lista provedores de IA disponíveis.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Formato de saída (table, json).
	 *
	 * ## EXEMPLOS
	 *
	 *     $ wp oraculo providers
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function providers( $args, $assoc_args ) {
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$factory   = new AIProviderFactory();
		$providers = $factory->get_available_providers();

		$data = array_map(
			function ( $p ) {
				return array(
					'ID'          => $p['id'],
					'Nome'        => $p['name'],
					'Configurado' => $p['is_configured'] ? '✓' : '✗',
					'Embeddings'  => $p['supports_embeddings'] ? '✓' : '✗',
					'Streaming'   => $p['supports_streaming'] ? '✓' : '✗',
				);
			},
			$providers
		);

		WP_CLI\Utils\format_items( $format, $data, array( 'ID', 'Nome', 'Configurado', 'Embeddings', 'Streaming' ) );
	}

	/**
	 * Otimiza o banco de dados.
	 *
	 * ## EXEMPLOS
	 *
	 *     $ wp oraculo optimize
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function optimize( $args, $assoc_args ) {
		WP_CLI::log( 'Otimizando tabelas...' );

		global $wpdb;
		$tables = array(
			$wpdb->prefix . 'oraculo_vectors',
			$wpdb->prefix . 'oraculo_search_logs',
			$wpdb->prefix . 'oraculo_conversations',
			$wpdb->prefix . 'oraculo_messages',
		);

		foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table; CLI maintenance command with no WP API equivalent.
			$wpdb->query( "OPTIMIZE TABLE {$table}" );
			WP_CLI::log( "  ✓ {$table}" );
		}

		// Limpar cache
		$search  = new SearchEngine();
		$cleared = $search->clear_cache();
		WP_CLI::log( "  ✓ Cache limpo ({$cleared} entradas)" );

		WP_CLI::success( 'Otimização concluída!' );
	}

	/**
	 * Exporta dados para arquivo.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Tipo de dados (analytics, settings, vectors).
	 *
	 * [--output=<file>]
	 * : Arquivo de saída.
	 *
	 * [--period=<period>]
	 * : Período para analytics (today, week, month, year, all).
	 *
	 * [--collection=<id>]
	 * : ID da coleção para vetores.
	 *
	 * ## EXEMPLOS
	 *
	 *     # Exportar analytics
	 *     $ wp oraculo export analytics --output=analytics.json
	 *
	 *     # Exportar vetores de uma coleção
	 *     $ wp oraculo export vectors --collection=123 --output=vectors.json
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function export( $args, $assoc_args ) {
		$type          = $args[0];
		$output        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'output', null );
		$period        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'period', 'month' );
		$collection_id = \WP_CLI\Utils\get_flag_value( $assoc_args, 'collection', null );

		switch ( $type ) {
			case 'analytics':
				$analytics = new AnalyticsManager();
				$data      = $analytics->export( $period, 'json' );
				break;

			case 'settings':
				$data = wp_json_encode( \Oraculo_Tainacan\Oraculo_Tainacan::get_options(), JSON_PRETTY_PRINT );
				break;

			case 'vectors':
				if ( ! $collection_id ) {
					WP_CLI::error( 'Informe o ID da coleção com --collection=<id>' );
				}
				$vector_store = new VectorStore();
				$data         = $vector_store->export( (int) $collection_id, 'json' );
				break;

			default:
				WP_CLI::error( "Tipo inválido: {$type}. Use: analytics, settings, vectors" );
		}

		if ( $output ) {
			file_put_contents( $output, $data );
			WP_CLI::success( "Dados exportados para: {$output}" );
		} else {
			WP_CLI::log( $data );
		}
	}
}
