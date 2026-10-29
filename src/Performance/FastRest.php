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

		if ( 'clip' === ( $options['search_backend'] ?? 'local' ) ) {
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
	 * @return array{enabled: bool, installed: bool, current: bool, multisite: bool, writable: bool, plugins: string[], target: string}
	 */
	public static function status(): array {
		$installed = self::installed_version();
		$dir       = WPMU_PLUGIN_DIR;

		return array(
			'enabled'   => self::enabled(),
			'installed' => '' !== $installed,
			'current'   => ORACULO_TAINACAN_VERSION === $installed,
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

		update_option(
			self::OPTION,
			array(
				'enabled' => true,
				'plugins' => self::plugins(),
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
	 * Mantém arquivo e lista em dia (admin_init; barato)
	 *
	 * Depois de uma atualização do plugin o arquivo antigo continua lá; depois
	 * de trocar o modo de busca, a lista de plugins muda.
	 */
	public static function maintain(): void {
		if ( ! self::enabled() || is_multisite() ) {
			return;
		}

		if ( ORACULO_TAINACAN_VERSION !== self::installed_version() ) {
			self::copy_file();
		}

		$conf    = (array) get_option( self::OPTION );
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
