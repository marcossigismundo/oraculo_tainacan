<?php
/**
 * Diagnóstico: o que deixa a busca e a BIA rápidas sob uso intenso
 *
 * Cada verificação devolve situação (ok, atenção, problema, informação), o
 * que ela significa em linguagem simples e o que fazer. Os números citados nas
 * explicações vêm do teste de carga de 29/10/2026 (40 buscas + 30 conversas
 * ao mesmo tempo, VPS de 1 núcleo); o guia "Uso intenso" do manual tem a
 * tabela completa.
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Admin;

use Oraculo_Tainacan\AI\AIProviderFactory;
use Oraculo_Tainacan\Performance\FastRest;
use Oraculo_Tainacan\Vector\NativeVector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verificações, ações e teste ao vivo da aba Diagnóstico.
 */
class Diagnostics {

	/** Ação do admin-post. */
	public const ACTION = 'oraculo_diagnostics';

	/** Modelo recomendado (medido: ~1,9 s por resposta, texto correto e curto). */
	public const RECOMMENDED_MODEL = 'gpt-4.1-mini';

	/**
	 * Modelos medidos no prompt real da busca (segundos por resposta)
	 *
	 * @var array<string, float>
	 */
	private const MEASURED = array(
		'gpt-4.1-mini' => 1.9,
		'gpt-5-mini'   => 4.4,
		'gpt-4o-mini'  => 4.4,
		'gpt-5-nano'   => 5.9,
	);

	/**
	 * Registra o handler das ações
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_post' ) );
	}

	/**
	 * Todas as verificações, na ordem da tela
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function checks(): array {
		return array(
			self::check_provider(),
			self::check_model(),
			self::check_fast_rest(),
			self::check_pool(),
			self::check_limits(),
			self::check_native_vectors(),
			self::check_cache(),
			self::check_server(),
		);
	}

	/**
	 * Provedor de IA configurado
	 *
	 * @return array<string, mixed>
	 */
	private static function check_provider(): array {
		$factory  = new AIProviderFactory();
		$provider = $factory->get_current_provider();
		$ok       = $factory->has_any_configured();

		return array(
			'id'     => 'provider',
			'status' => $ok ? 'ok' : 'bad',
			'title'  => __( 'Provedor de IA', 'oraculo-tainacan' ),
			'value'  => $ok ? strtoupper( $provider ) : __( 'sem chave', 'oraculo-tainacan' ),
			'text'   => $ok
				? __( 'A busca e a BIA têm um provedor de IA com chave cadastrada.', 'oraculo-tainacan' )
				: __( 'Sem provedor configurado, a busca não encontra nada e a BIA não responde.', 'oraculo-tainacan' ),
			'how'    => $ok ? '' : __( 'Cadastre a chave do provedor na aba Configurações.', 'oraculo-tainacan' ),
			'action' => $ok ? null : self::link_action( 'settings', __( 'Abrir Configurações', 'oraculo-tainacan' ) ),
		);
	}

	/**
	 * Modelo de linguagem e tempo médio das respostas
	 *
	 * @return array<string, mixed>
	 */
	private static function check_model(): array {
		$options  = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
		$provider = (string) ( $options['ai_provider'] ?? 'openai' );
		$model    = (string) ( $options[ $provider . '_model' ] ?? '' );
		$avg      = self::average_answer_seconds();

		$avg_text = null === $avg
			? ''
			/* translators: %s: average time in seconds, e.g. "4,2" */
			: ' ' . sprintf( __( 'Nas últimas respostas do site, a média foi de %s s.', 'oraculo-tainacan' ), number_format_i18n( $avg, 1 ) );

		if ( 'ollama' === $provider ) {
			return array(
				'id'     => 'model',
				'status' => 'bad',
				'title'  => __( 'Modelo de linguagem', 'oraculo-tainacan' ),
				'value'  => $model,
				'text'   => __( 'Modelo local (Ollama). Num servidor sem placa de vídeo, cada resposta leva de 1 a 4 minutos e as perguntas entram em fila, uma por vez. Em teste, os modelos pequenos também inventaram itens e links.', 'oraculo-tainacan' ) . $avg_text,
				'how'    => __( 'Para a busca e a BIA ao vivo, use um provedor de API. O modelo local serve para tarefas em segundo plano ou num servidor com placa de vídeo.', 'oraculo-tainacan' ),
				'action' => self::link_action( 'settings', __( 'Trocar o provedor', 'oraculo-tainacan' ) ),
			);
		}

		if ( 'openai' !== $provider ) {
			return array(
				'id'     => 'model',
				'status' => 'info',
				'title'  => __( 'Modelo de linguagem', 'oraculo-tainacan' ),
				'value'  => $model,
				'text'   => __( 'Este modelo não foi medido no teste de carga. O que mais pesa é o tempo de cada resposta: cada segundo a mais prende um processo do servidor por um segundo a mais.', 'oraculo-tainacan' ) . $avg_text,
				'how'    => '',
				'action' => null,
			);
		}

		$measured = self::MEASURED[ $model ] ?? null;
		$is_best  = self::RECOMMENDED_MODEL === $model;

		if ( $is_best ) {
			$text = __( 'É o modelo mais rápido entre os medidos: ~1,9 s por resposta, com texto curto e correto.', 'oraculo-tainacan' );
		} elseif ( null !== $measured ) {
			$text = sprintf(
				/* translators: 1: model name, 2: seconds for this model, 3: recommended model name */
				__( 'O %1$s levou ~%2$s s por resposta no teste; o %3$s, ~1,9 s. No pico de 70 pessoas, trocar o modelo baixou o tempo total de 64 s para 40 s.', 'oraculo-tainacan' ),
				$model,
				number_format_i18n( $measured, 1 ),
				self::RECOMMENDED_MODEL
			);
		} else {
			$text = __( 'Este modelo não foi medido no teste de carga.', 'oraculo-tainacan' );
		}

		return array(
			'id'     => 'model',
			'status' => $is_best ? 'ok' : 'warn',
			'title'  => __( 'Modelo de linguagem', 'oraculo-tainacan' ),
			'value'  => $model,
			'text'   => $text . $avg_text,
			'how'    => $is_best ? '' : sprintf(
				/* translators: %s: recommended model name */
				__( 'Em Configurações → OpenAI, escolha %s. Ele custa mais por pergunta que o gpt-5-nano (cerca de US$ 1 contra US$ 0,30 a cada mil perguntas, pela tabela de preços da OpenAI); confira na sua conta.', 'oraculo-tainacan' ),
				self::RECOMMENDED_MODEL
			),
			'action' => $is_best ? null : self::link_action( 'settings', __( 'Trocar o modelo', 'oraculo-tainacan' ) ),
		);
	}

	/**
	 * Modo rápido (mu-plugin)
	 *
	 * @return array<string, mixed>
	 */
	private static function check_fast_rest(): array {
		$st   = FastRest::status();
		$base = array(
			'id'    => 'fast_rest',
			'title' => __( 'Modo rápido', 'oraculo-tainacan' ),
		);

		$explain = __( 'Nas rotas da busca e da BIA, o WordPress carrega só o Oráculo, em vez de todos os plugins do site. Cada pedido gasta menos da metade da CPU (0,05 s contra 0,13 s no teste) e, no pico de 70 pessoas, o tempo total caiu de 38 s para 26 s. O resto do site não muda.', 'oraculo-tainacan' );

		if ( $st['multisite'] ) {
			return $base + array(
				'status' => 'info',
				'value'  => __( 'indisponível', 'oraculo-tainacan' ),
				'text'   => __( 'Em multisite os plugins também são ativados pela rede, e o modo rápido não sabe separar os que a busca precisa.', 'oraculo-tainacan' ),
				'how'    => '',
				'action' => null,
			);
		}

		if ( $st['enabled'] && $st['current'] ) {
			return $base + array(
				'status' => 'ok',
				'value'  => __( 'ligado', 'oraculo-tainacan' ),
				'text'   => $explain . ' ' . sprintf(
					/* translators: %s: comma-separated list of plugin folders */
					__( 'Plugins mantidos nessas rotas: %s.', 'oraculo-tainacan' ),
					implode( ', ', array_map( 'dirname', $st['plugins'] ) )
				),
				'how'    => __( 'Se outro plugin mexe nas respostas do Oráculo, inclua-o pelo filtro oraculo_tainacan_fast_rest_plugins, ou desligue o modo rápido.', 'oraculo-tainacan' ),
				'action' => self::post_action( 'fast_off', __( 'Desligar', 'oraculo-tainacan' ), 'secondary' ),
			);
		}

		if ( $st['enabled'] && $st['installed'] && ! $st['current'] ) {
			return $base + array(
				'status' => 'warn',
				'value'  => sprintf(
					/* translators: %s: version of the installed fast-mode file */
					__( 'arquivo da %s', 'oraculo-tainacan' ),
					$st['version']
				),
				'text'   => __( 'O modo rápido está ligado, mas o arquivo em mu-plugins é de outra versão do Oráculo e não foi possível trocá-lo (a pasta não aceita gravação). Ele continua funcionando com as regras da versão antiga.', 'oraculo-tainacan' ),
				'how'    => sprintf(
					/* translators: 1: source file path inside the plugin, 2: destination folder */
					__( 'Copie %1$s para %2$s, ou libere a gravação na pasta e clique em Atualizar.', 'oraculo-tainacan' ),
					'wp-content/plugins/' . dirname( ORACULO_TAINACAN_BASENAME ) . '/mu-plugin/' . FastRest::FILE,
					'wp-content/mu-plugins/'
				),
				'action' => self::post_action( 'fast_on', __( 'Atualizar', 'oraculo-tainacan' ) ),
			);
		}

		if ( $st['enabled'] && ! $st['installed'] ) {
			return $base + array(
				'status' => 'bad',
				'value'  => __( 'arquivo ausente', 'oraculo-tainacan' ),
				'text'   => __( 'O modo rápido está ligado, mas o arquivo dele sumiu da pasta mu-plugins (apagado à mão ou por uma restauração).', 'oraculo-tainacan' ),
				'how'    => __( 'Ligue de novo para recolocar o arquivo.', 'oraculo-tainacan' ),
				'action' => self::post_action( 'fast_on', __( 'Ligar de novo', 'oraculo-tainacan' ) ),
			);
		}

		if ( ! $st['writable'] ) {
			return $base + array(
				'status' => 'warn',
				'value'  => __( 'desligado', 'oraculo-tainacan' ),
				'text'   => $explain . ' ' . __( 'O WordPress não tem permissão para gravar na pasta wp-content/mu-plugins.', 'oraculo-tainacan' ),
				'how'    => sprintf(
					/* translators: 1: source file path inside the plugin, 2: destination folder */
					__( 'Peça a quem administra o servidor para copiar %1$s para %2$s e depois clique em Ligar.', 'oraculo-tainacan' ),
					'wp-content/plugins/' . dirname( ORACULO_TAINACAN_BASENAME ) . '/mu-plugin/' . FastRest::FILE,
					'wp-content/mu-plugins/'
				),
				'action' => self::post_action( 'fast_on', __( 'Ligar', 'oraculo-tainacan' ) ),
			);
		}

		return $base + array(
			'status' => 'warn',
			'value'  => __( 'desligado', 'oraculo-tainacan' ),
			'text'   => $explain,
			'how'    => __( 'Ligue com o botão. O plugin grava um arquivo pequeno em wp-content/mu-plugins e o remove se você desligar ou desativar o Oráculo.', 'oraculo-tainacan' ),
			'action' => self::post_action( 'fast_on', __( 'Ligar', 'oraculo-tainacan' ) ),
		);
	}

	/**
	 * Processos PHP próprios: só o teste ao vivo enxerga
	 *
	 * @return array<string, mixed>
	 */
	private static function check_pool(): array {
		return array(
			'id'     => 'pool',
			'status' => 'pending',
			'title'  => __( 'Processos PHP próprios', 'oraculo-tainacan' ),
			'value'  => __( 'clique em Testar agora', 'oraculo-tainacan' ),
			'text'   => __( 'É configuração do servidor, não do plugin. Com um grupo de processos só para o Oráculo, e com prioridade menor, o resto do site continua rápido durante um pico: no teste, a página inicial ficou em 0,8 s, contra 22 s sem ele. Não acelera o Oráculo; protege o site.', 'oraculo-tainacan' ),
			'how'    => __( 'A receita (PHP-FPM + nginx) está no manual, no guia "Uso intenso". Em hospedagem compartilhada isso costuma não ser possível.', 'oraculo-tainacan' ),
			'action' => array( 'type' => 'probe' ),
		);
	}

	/**
	 * Limites de uso
	 *
	 * @return array<string, mixed>
	 */
	private static function check_limits(): array {
		$o = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
		// Sem valor salvo (atualizado da 2.12 sem passar por aqui) vale o teto antigo, 60.
		$global = (int) ( $o['rate_limit_global'] ?? 60 );
		$search = (int) ( $o['rate_limit_search'] ?? 10 );
		$chat   = (int) ( $o['rate_limit_chat'] ?? 20 );
		$allow  = trim( (string) ( $o['rate_limit_allowlist'] ?? '' ) );

		$low = $global > 0 && $global < 120;

		$text = sprintf(
			/* translators: 1: global limit per minute, 2: searches per minute per visitor, 3: chat messages per minute per visitor */
			__( 'Hoje: %1$s chamadas de IA por minuto no site inteiro; %2$s buscas e %3$s mensagens da BIA por minuto para cada visitante.', 'oraculo-tainacan' ),
			0 === $global ? __( 'sem teto', 'oraculo-tainacan' ) : number_format_i18n( $global ),
			0 === $search ? __( 'sem limite de', 'oraculo-tainacan' ) : number_format_i18n( $search ),
			0 === $chat ? __( 'sem limite de', 'oraculo-tainacan' ) : number_format_i18n( $chat )
		);
		$text .= ' ' . __( 'O limite por visitante conta pelo endereço IP: numa sala de aula ou num museu, todos saem pelo mesmo IP e seriam barrados juntos. Para isso servem as redes liberadas.', 'oraculo-tainacan' );

		if ( $low ) {
			$text .= ' ' . __( 'Com menos de 120 por minuto, um pico de 70 pessoas (busca + resposta) já esbarra no teto e parte delas recebe "alta demanda". Desde a 2.13.0 o padrão das instalações novas é 300.', 'oraculo-tainacan' );
		}

		return array(
			'id'     => 'limits',
			'status' => $low ? 'warn' : 'ok',
			'title'  => __( 'Limites de uso', 'oraculo-tainacan' ),
			'value'  => '' === $allow ? __( 'sem redes liberadas', 'oraculo-tainacan' ) : __( 'com redes liberadas', 'oraculo-tainacan' ),
			'text'   => $text,
			'how'    => __( 'Os limites protegem a sua conta no provedor de IA contra abuso. Suba o teto global se espera muita gente ao mesmo tempo.', 'oraculo-tainacan' ),
			'action' => array( 'type' => 'limits' ),
		);
	}

	/**
	 * Busca vetorial no banco (MariaDB 11.7+)
	 *
	 * @return array<string, mixed>
	 */
	private static function check_native_vectors(): array {
		$base = array(
			'id'    => 'native',
			'title' => __( 'Busca vetorial no banco', 'oraculo-tainacan' ),
		);

		if ( ! NativeVector::supported() ) {
			global $wpdb;
			$info = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
			return $base + array(
				'status' => 'info',
				'value'  => __( 'indisponível', 'oraculo-tainacan' ),
				/* translators: %s: database server version string */
				'text'   => sprintf( __( 'O banco (%s) não tem o tipo VECTOR. A busca funciona, comparando os vetores em PHP: ~0,5 s por busca num acervo de 40 mil itens, contra ~0,12 s no banco.', 'oraculo-tainacan' ), $info ),
				'how'    => __( 'Com MariaDB 11.7 ou mais novo, o Oráculo passa a usar o banco sozinho, sem configuração.', 'oraculo-tainacan' ),
				'action' => null,
			);
		}

		$st    = NativeVector::status();
		$item  = $st['vectors'] ?? array();
		$ready = ! empty( $item['ready'] ) && empty( $item['off'] );

		return $base + array(
			'status' => $ready ? 'ok' : 'warn',
			'value'  => $ready ? __( 'ligada', 'oraculo-tainacan' ) : __( 'preparando', 'oraculo-tainacan' ),
			'text'   => $ready
				? __( 'A comparação entre a pergunta e o acervo acontece dentro do MariaDB. No pico, é a maior parte da CPU que o banco gasta.', 'oraculo-tainacan' )
				: __( 'O servidor tem o tipo VECTOR, mas a cópia dos vetores para a tabela de busca ainda não terminou (ou foi desligada pelo WP-CLI). Enquanto isso, a busca usa o PHP.', 'oraculo-tainacan' ),
			'how'    => $ready ? '' : __( 'Ela termina sozinha pelo cron. Para acompanhar: wp oraculo native status.', 'oraculo-tainacan' ),
			'action' => null,
		);
	}

	/**
	 * Cache de respostas
	 *
	 * @return array<string, mixed>
	 */
	private static function check_cache(): array {
		$o       = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();
		$seconds = (int) ( $o['cache_duration'] ?? 3600 );
		$ext     = wp_using_ext_object_cache();

		$text = $seconds > 0
			? sprintf(
				/* translators: %s: cache duration in minutes */
				__( 'Uma pergunta repetida (na busca ou como primeira mensagem da BIA) volta do cache em menos de 1 s, sem chamar a IA, por %s minutos.', 'oraculo-tainacan' ),
				number_format_i18n( (int) round( $seconds / 60 ) )
			)
			: __( 'O cache de respostas está desligado: toda pergunta repetida chama a IA de novo.', 'oraculo-tainacan' );

		$text .= ' ' . ( $ext
			? __( 'O site tem cache de objetos (Redis ou Memcached): os contadores de limite e o cache não pesam no banco.', 'oraculo-tainacan' )
			: __( 'Sem cache de objetos, os contadores de limite e o cache ficam na tabela de opções; com Redis o banco trabalha menos no pico.', 'oraculo-tainacan' ) );

		return array(
			'id'     => 'cache',
			'status' => $seconds > 0 ? 'ok' : 'warn',
			'title'  => __( 'Cache de respostas', 'oraculo-tainacan' ),
			'value'  => $seconds > 0 ? __( 'ligado', 'oraculo-tainacan' ) : __( 'desligado', 'oraculo-tainacan' ),
			'text'   => $text,
			'how'    => $seconds > 0 ? '' : __( 'Defina a duração do cache em Configurações.', 'oraculo-tainacan' ),
			'action' => $seconds > 0 ? null : self::link_action( 'settings', __( 'Abrir Configurações', 'oraculo-tainacan' ) ),
		);
	}

	/**
	 * Servidor: núcleos de CPU e PHP
	 *
	 * @return array<string, mixed>
	 */
	private static function check_server(): array {
		$cores = self::cpu_cores();

		$text = null === $cores
			? __( 'Não foi possível ler quantos núcleos de CPU o servidor tem.', 'oraculo-tainacan' )
			: sprintf(
				/* translators: %d: number of CPU cores */
				_n( 'O servidor tem %d núcleo de CPU.', 'O servidor tem %d núcleos de CPU.', $cores, 'oraculo-tainacan' ),
				$cores
			);
		$text .= ' ' . __( 'Com as outras medidas em dia, a CPU é o que sobra de limite: no teste, num núcleo só, 70 pessoas chegando aos poucos em 20 s foram atendidas em ~3 s cada; todas no mesmo segundo, em até ~25 s.', 'oraculo-tainacan' );

		return array(
			'id'     => 'server',
			'status' => null !== $cores && $cores < 2 ? 'warn' : 'info',
			'title'  => __( 'Servidor', 'oraculo-tainacan' ),
			/* translators: %s: PHP version */
			'value'  => sprintf( __( 'PHP %s', 'oraculo-tainacan' ), PHP_VERSION ),
			'text'   => $text,
			'how'    => null !== $cores && $cores < 2 ? __( 'Para uso intenso frequente, 2 a 4 núcleos rendem mais que qualquer ajuste.', 'oraculo-tainacan' ) : '',
			'action' => null,
		);
	}

	/**
	 * Tempo médio das respostas com IA nos últimos 7 dias
	 *
	 * @return float|null Segundos; null sem dados.
	 */
	private static function average_answer_seconds(): ?float {
		global $wpdb;
		$table = $wpdb->prefix . 'oraculo_search_logs';

		// Sem prepare(): não há valor variável, só o nome da tabela, e o marcador %i
		// de identificador exige WP 6.2 (o plugin suporta 6.0).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only interpolation is $wpdb->prefix + literal table name; the %i placeholder needs WP 6.2 and the plugin supports 6.0; one aggregate per admin page view.
		$avg = $wpdb->get_var( "SELECT AVG(response_time_ms) FROM (SELECT response_time_ms FROM {$table} WHERE tokens_used > 0 AND response_time_ms > 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 200) t" );

		return null === $avg ? null : round( (float) $avg / 1000, 1 );
	}

	/**
	 * Núcleos de CPU (Linux), sem executar comando
	 *
	 * @return int|null
	 */
	private static function cpu_cores(): ?int {
		$file = '/proc/cpuinfo';
		if ( ! @is_readable( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir makes is_readable warn on /proc; the null return covers it.
			return null;
		}

		$info = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local pseudo-file under /proc; WP_Filesystem would need credentials for a read-only system file.
		$n    = preg_match_all( '/^processor\s*:/m', $info );

		return $n > 0 ? (int) $n : null;
	}

	/**
	 * Teste ao vivo, chamado pela rota REST diagnostics/probe
	 *
	 * A rota fica em /oraculo/, então passa pelo mesmo caminho da busca: o
	 * mu-plugin do modo rápido e o grupo de processos PHP do nginx.
	 *
	 * @param bool $deep Mede também embedding, vetores e uma resposta curta da IA.
	 * @return array<string, mixed>
	 */
	public static function probe( bool $deep ): array {
		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );

		$out = array(
			'bootstrap_ms'   => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'fast_rest'      => defined( 'ORACULO_TAINACAN_FAST_REST' ) && ORACULO_TAINACAN_FAST_REST,
			'plugins_loaded' => count( (array) get_option( 'active_plugins', array() ) ),
			'pool'           => self::pool_name(),
		);

		if ( ! $deep ) {
			return $out;
		}

		$engine = new \Oraculo_Tainacan\Search\SearchEngine();
		$bench  = $engine->benchmark_retrieval( __( 'livros sobre história do Brasil', 'oraculo-tainacan' ) );
		if ( is_wp_error( $bench ) ) {
			$out['retrieval_error'] = $bench->get_error_message();
		} else {
			$out += $bench;
		}

		$factory = new AIProviderFactory();
		$t0      = microtime( true );
		$answer  = $factory->create_from_options()->generate_response(
			__( 'Responda só com a palavra "pronto".', 'oraculo-tainacan' ),
			'',
			array( 'max_tokens' => 50 )
		);

		$out['llm_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $answer ) ) {
			$out['llm_error'] = $answer->get_error_message();
		}
		$out['model'] = is_array( $answer ) ? (string) ( $answer['model'] ?? '' ) : '';

		return $out;
	}

	/**
	 * Nome do grupo de processos, pela variável que a receita do manual define
	 *
	 * @return string '' fora de um grupo próprio.
	 */
	private static function pool_name(): string {
		$name = getenv( 'ORACULO_PHP_POOL' );
		return false === $name ? '' : sanitize_key( $name );
	}

	/**
	 * Ações da tela (admin-post): modo rápido e limites
	 */
	public static function handle_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'oraculo-tainacan' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$op  = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$msg = 'saved';

		switch ( $op ) {
			case 'fast_on':
				$result = FastRest::enable();
				$msg    = is_wp_error( $result ) ? 'fast_error' : 'fast_on';
				if ( is_wp_error( $result ) ) {
					set_transient( 'oraculo_diag_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
				}
				break;

			case 'fast_off':
				FastRest::disable();
				$msg = 'fast_off';
				break;

			case 'limits':
				$input = array(
					'rate_limit_global'    => isset( $_POST['rate_limit_global'] ) ? absint( wp_unslash( $_POST['rate_limit_global'] ) ) : 60,
					'rate_limit_search'    => isset( $_POST['rate_limit_search'] ) ? absint( wp_unslash( $_POST['rate_limit_search'] ) ) : 10,
					'rate_limit_chat'      => isset( $_POST['rate_limit_chat'] ) ? absint( wp_unslash( $_POST['rate_limit_chat'] ) ) : 20,
					'rate_limit_allowlist' => isset( $_POST['rate_limit_allowlist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rate_limit_allowlist'] ) ) : '',
				);
				// O sanitize_callback da opção (SettingsSanitizer) valida e limita os valores.
				update_option( 'oraculo_tainacan_options', array_merge( \Oraculo_Tainacan\Oraculo_Tainacan::get_options(), $input ) );
				$msg = 'limits';
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'         => 'diagnostics',
					'oraculo_msg' => $msg,
				),
				admin_url( 'admin.php?page=oraculo_tainacan_page' )
			)
		);
		exit;
	}

	/**
	 * Ação de formulário (admin-post)
	 *
	 * @param string $op    Operação.
	 * @param string $label Texto do botão.
	 * @param string $style primary|secondary.
	 * @return array<string, string>
	 */
	private static function post_action( string $op, string $label, string $style = 'primary' ): array {
		return array(
			'type'  => 'post',
			'op'    => $op,
			'label' => $label,
			'style' => $style,
		);
	}

	/**
	 * Ação de link para outra aba
	 *
	 * @param string $tab   Aba.
	 * @param string $label Texto.
	 * @return array<string, string>
	 */
	private static function link_action( string $tab, string $label ): array {
		return array(
			'type'  => 'link',
			'url'   => admin_url( 'admin.php?page=oraculo_tainacan_page&tab=' . $tab ),
			'label' => $label,
		);
	}
}
