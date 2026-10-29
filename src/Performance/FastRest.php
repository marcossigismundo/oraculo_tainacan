<?php
/**
 * Modo rápido: instala o mu-plugin que enxuga as rotas de busca e chat
 *
 * Um plugin comum não consegue impedir que os outros carreguem — quando ele
 * roda, eles já carregaram. Por isso o trabalho fica num mu-plugin
 * (mu-plugin/oraculo-tainacan-rest-leve.php), que este arquivo copia para
 * wp-content/mu-plugins, mantém atualizado e remove na desativação. A lista de
 * plugins que ficam vai na opção oraculo_tainacan_fast_rest; o primeiro da
 * lista é sempre o próprio Oráculo.
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Performance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instala, atualiza e remove o mu-plugin do modo rápido.
 */
class FastRest {

	/** Opção com enabled + plugins mantidos. */
	public const OPTION = 'oraculo_tainacan_fast_rest';

	/** Nome do arquivo em wp-content/mu-plugins. */
	public const FILE = 'oraculo-tainacan-rest-leve.php';

	/** Marca de cópia que falhou (versão), para não repetir a cada admin_init. */
	private const COPY_FAILED = 'oraculo_fast_rest_copy_failed';

	/**
	 * Caminho do arquivo-fonte dentro do plugin
	 *
	 * @return string
	 */
	private static function source(): string {
		return ORACULO_TAINACAN_PATH . 'mu-plugin/' . self::FILE;
	}

	/**
	 * Caminho do arquivo instalado
	 *
	 * @return string
	 */
	public static function target(): string {
		return trailingslashit( WPMU_PLUGIN_DIR ) . self::FILE;
	}

	/**
	 * Plugins que ficam carregados nas rotas de busca e chat
	 *
	 * O Tainacan só entra no modo de busca visual (CLIP): ali o link do item
	 * sai de get_permalink(), que precisa do tipo de post que ele registra.
	 * O modo local guarda o link no índice e não usa classe do Tainacan.
	 *
	 * @return string[] Basenames; o primeiro é o Oráculo.
	 */
	public static function plugins(): array {
		$keep    = array( ORACULO_TAINACAN_BASENAME );
		$options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

		if ( \Oraculo_Tainacan\Search\SearchEngine::clip_selected( $options ) ) {
			foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
				if ( 'tainacan' === dirname( (string) $plugin ) ) {
					$keep[] = (string) $plugin;
				}
			}
		}

		/**
		 * Plugins mantidos nas rotas de busca e chat no modo rápido.
		 *
		 * Para um plugin que mexe na busca ou na resposta do Oráculo (filtros
		 * oraculo_tainacan_*), acrescente o basename dele aqui.
		 *
		 * @param string[] $keep Basenames; o primeiro precisa ser o Oráculo.
		 */
		$keep = (array) apply_filters( 'oraculo_tainacan_fast_rest_plugins', $keep );
		$keep = array_values( array_unique( array_filter( array_map( 'strval', $keep ) ) ) );

		// O mu-plugin confere o primeiro item para saber se o Oráculo está ativo.
		$keep = array_values( array_diff( $keep, array( ORACULO_TAINACAN_BASENAME ) ) );
		array_unshift( $keep, ORACULO_TAINACAN_BASENAME );

		return $keep;
	}

	/**
	 * O modo rápido está pedido pelo administrador?
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		$conf = get_option( self::OPTION );
		return is_array( $conf ) && ! empty( $conf['enabled'] );
	}

	/**
	 * Versão do arquivo instalado ('' quando não há arquivo nosso)
	 *
	 * @return string
	 */
	public static function installed_version(): string {
		$target = self::target();
		if ( ! is_readable( $target ) ) {
			return '';
		}
		$data = get_file_data(
			$target,
			array(
				'name'    => 'Plugin Name',
				'version' => 'Version',
			)
		);
		return false !== strpos( (string) $data['name'], 'Oráculo Tainacan' ) ? (string) $data['version'] : '';
	}

	/**
	 * Situação completa, para o Diagnóstico
	 *
	 * @return array{enabled: bool, installed: bool, current: bool, version: string, multisite: bool, writable: bool, plugins: string[], target: string}
	 */
	public static function status(): array {
		$installed = self::installed_version();
		$dir       = WPMU_PLUGIN_DIR;

		return array(
			'enabled'   => self::enabled(),
			'installed' => '' !== $installed,
			'current'   => ORACULO_TAINACAN_VERSION === $installed,
			'version'   => $installed,
			'multisite' => is_multisite(),
			'writable'  => is_dir( $dir ) ? wp_is_writable( $dir ) : wp_is_writable( dirname( $dir ) ),
			'plugins'   => self::plugins(),
			'target'    => self::target(),
		);
	}

	/**
	 * Liga o modo rápido: grava a lista e copia o arquivo
	 *
	 * @return true|\WP_Error
	 */
	public static function enable() {
		if ( is_multisite() ) {
			return new \WP_Error( 'oraculo_fast_rest_multisite', __( 'O modo rápido não funciona em multisite: lá os plugins também são ativados pela rede.', 'oraculo-tainacan' ) );
		}

		$copied = self::copy_file();
		if ( is_wp_error( $copied ) ) {
			return $copied;
		}
		delete_transient( self::COPY_FAILED );

		update_option(
			self::OPTION,
			array(
				'enabled' => true,
				'plugins' => self::plugins(),
				'version' => ORACULO_TAINACAN_VERSION,
			),
			true
		);

		return true;
	}

	/**
	 * Desliga o modo rápido e remove o arquivo
	 *
	 * @param bool $keep_choice Na desativação do plugin, guarda a escolha para a reativação.
	 */
	public static function disable( bool $keep_choice = false ): void {
		if ( '' !== self::installed_version() ) {
			wp_delete_file( self::target() );
		}

		if ( $keep_choice ) {
			// Reativar recopia o arquivo no primeiro admin_init.
			$conf            = (array) get_option( self::OPTION );
			$conf['version'] = '';
			update_option( self::OPTION, $conf, true );
			return;
		}

		update_option(
			self::OPTION,
			array(
				'enabled' => false,
				'plugins' => array(),
			),
			true
		);
	}

	/**
	 * Mantém arquivo e lista em dia (admin_init)
	 *
	 * Depois de uma atualização do plugin o arquivo antigo continua lá; depois
	 * de trocar o modo de busca, a lista de plugins muda. Barato no caso comum:
	 * a versão copiada fica na opção, sem ler o arquivo a cada pedido do admin;
	 * uma cópia que falhou só é tentada de novo depois de uma hora.
	 */
	public static function maintain(): void {
		if ( ! self::enabled() || is_multisite() ) {
			return;
		}

		$conf = (array) get_option( self::OPTION );

		if ( ORACULO_TAINACAN_VERSION !== ( $conf['version'] ?? '' ) && ORACULO_TAINACAN_VERSION !== get_transient( self::COPY_FAILED ) ) {
			if ( is_wp_error( self::copy_file() ) ) {
				set_transient( self::COPY_FAILED, ORACULO_TAINACAN_VERSION, HOUR_IN_SECONDS );
			} else {
				$conf['version'] = ORACULO_TAINACAN_VERSION;
				update_option( self::OPTION, $conf, true );
			}
		}

		$plugins = self::plugins();
		if ( ( $conf['plugins'] ?? array() ) !== $plugins ) {
			$conf['plugins'] = $plugins;
			update_option( self::OPTION, $conf, true );
		}
	}

	/**
	 * Copia o arquivo-fonte para wp-content/mu-plugins
	 *
	 * @return true|\WP_Error
	 */
	private static function copy_file() {
		$source = self::source();
		$target = self::target();
		$dir    = WPMU_PLUGIN_DIR;

		// Não sobrescreve um arquivo de mesmo nome que não seja o nosso.
		if ( file_exists( $target ) && '' === self::installed_version() ) {
			return new \WP_Error( 'oraculo_fast_rest_foreign', __( 'Já existe em mu-plugins um arquivo com o mesmo nome que não é do Oráculo.', 'oraculo-tainacan' ) );
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'oraculo_fast_rest_dir', __( 'Não foi possível criar a pasta wp-content/mu-plugins.', 'oraculo-tainacan' ) );
		}

		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Só o método direto: sem credenciais de FTP numa tela de diagnóstico.
		if ( 'direct' !== get_filesystem_method( array(), $dir ) || ! WP_Filesystem() ) {
			return new \WP_Error( 'oraculo_fast_rest_write', __( 'O WordPress não tem permissão para gravar em wp-content/mu-plugins.', 'oraculo-tainacan' ) );
		}

		if ( ! $wp_filesystem->copy( $source, $target, true, FS_CHMOD_FILE ) ) {
			return new \WP_Error( 'oraculo_fast_rest_copy', __( 'Não foi possível copiar o arquivo do modo rápido.', 'oraculo-tainacan' ) );
		}

		return true;
	}
}
