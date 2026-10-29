<?php
/**
 * Plugin Name: Oráculo Tainacan — modo rápido
 * Description: Nas rotas públicas de busca e chat do Oráculo, carrega só os plugins de que elas precisam. Instalado e removido pelo próprio Oráculo (Oráculo IA → Diagnóstico).
 * Version: 2.13.0
 * Author: Tainacan Community
 * License: GPL-2.0-or-later
 *
 * Cada pedido de busca carregava todos os plugins do site (~0,13 s de CPU na
 * VPS de teste); só com o Oráculo, ~0,05 s. Num pico de 70 pedidos isso tirou
 * ~10 s do tempo total. Rotas de administração, indexação e qualquer outro
 * endereço do site não são tocados.
 *
 * Este arquivo é cópia de mu-plugin/oraculo-tainacan-rest-leve.php do plugin;
 * editar aqui é perdido na próxima atualização.
 *
 * @package Oraculo_Tainacan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Multisite tem plugins ativados na rede (active_sitewide_plugins); fica de fora.
if ( is_multisite() || ! isset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
	return;
}

/*
 * Só as duas rotas que consomem IA, /wp-json/oraculo/v1/search e /chat, mais
 * o teste ao vivo da tela Diagnóstico (diagnostics/probe, só administrador),
 * com ou sem subpasta, e a forma ?rest_route= dos sites sem links permanentes.
 *
 * Duas travas, porque tirar plugins de outro endereço desligaria segurança
 * (2FA, captcha, limite de login em wp-login.php ou xmlrpc.php):
 * 1. o script em execução tem de ser o index.php do WordPress — nunca
 *    wp-login.php, xmlrpc.php ou wp-admin/index.php com PATH_INFO;
 * 2. a rota é conferida no CAMINHO (até o "?"), ancorada no fim; a forma
 *    ?rest_route= só vale com o valor exato do parâmetro.
 * Comparação de texto, sem sanitizar: o valor só decide se o filtro entra.
 */
$oraculo_leve_script = isset( $_SERVER['SCRIPT_FILENAME'] ) ? realpath( (string) wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) : false; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared with realpath() of ABSPATH/index.php only; never stored or printed.
if ( false === $oraculo_leve_script || realpath( ABSPATH . 'index.php' ) !== $oraculo_leve_script ) {
	return;
}

$oraculo_leve_route = '#^/oraculo/v1/(?:search|chat|diagnostics/probe)/?$#';
$oraculo_leve_path  = rawurldecode( (string) strtok( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), '?' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only matched against a fixed, anchored pattern; sanitizing would change the path being matched.
$oraculo_leve_match = (bool) preg_match( '#/wp-json(/oraculo/v1/(?:search|chat|diagnostics/probe)/?)$#', $oraculo_leve_path );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing decision only; the REST route itself checks the nonce.
if ( ! $oraculo_leve_match && isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
	$oraculo_leve_match = (bool) preg_match( $oraculo_leve_route, (string) wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- Same as above: anchored match against a fixed pattern, never stored or printed.
}

if ( ! $oraculo_leve_match ) {
	return;
}

$oraculo_leve_conf = get_option( 'oraculo_tainacan_fast_rest' );
if ( ! is_array( $oraculo_leve_conf ) || empty( $oraculo_leve_conf['enabled'] ) || empty( $oraculo_leve_conf['plugins'] ) || ! is_array( $oraculo_leve_conf['plugins'] ) ) {
	return;
}

add_filter(
	'option_active_plugins',
	static function ( $plugins ) use ( $oraculo_leve_conf ) {
		$plugins = (array) $plugins;
		$keep    = array_values( array_intersect( $plugins, $oraculo_leve_conf['plugins'] ) );

		// O Oráculo desativado ou renomeado: não mexe em nada.
		if ( ! in_array( (string) $oraculo_leve_conf['plugins'][0], $keep, true ) ) {
			return $plugins;
		}

		if ( ! defined( 'ORACULO_TAINACAN_FAST_REST' ) ) {
			define( 'ORACULO_TAINACAN_FAST_REST', true );
		}
		return $keep;
	},
	1
);

// A lista enxuta vale só para este pedido: nunca gravá-la de volta no banco.
add_filter(
	'pre_update_option_active_plugins',
	static function ( $value, $old_value ) {
		return $old_value;
	},
	1,
	2
);
