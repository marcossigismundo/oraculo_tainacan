<?php
/**
 * Sanitização das configurações do plugin
 *
 * Allowlist por chave com limites numéricos; API keys com placeholder
 * (••••••••) preservam o valor já armazenado.
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitiza o array de opções do plugin (allowlist estrita).
 */
class SettingsSanitizer {

	/**
	 * Sanitiza configurações
	 *
	 * @param array $input Opções brutas (REST/form).
	 * @return array Opções sanitizadas.
	 */
	public static function sanitize( array $input ): array {
		$sanitized = array();

		// Provider
		$sanitized['ai_provider'] = sanitize_text_field( $input['ai_provider'] ?? 'openai' );

		// API Keys - só atualizar se não for placeholder
		$api_keys = array(
			'openai_api_key',
			'gemini_api_key',
			'deepseek_api_key',
			'groq_api_key',
			'claude_api_key',
		);

		$current_options = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

		foreach ( $api_keys as $key ) {
			if ( isset( $input[ $key ] ) && $input[ $key ] !== '••••••••' && ! empty( $input[ $key ] ) ) {
				$sanitized[ $key ] = sanitize_text_field( $input[ $key ] );
			} else {
				$sanitized[ $key ] = $current_options[ $key ] ?? '';
			}
		}

		// Modelos
		$sanitized['openai_model']           = sanitize_text_field( $input['openai_model'] ?? 'gpt-4o-mini' );
		$sanitized['openai_embedding_model'] = sanitize_text_field( $input['openai_embedding_model'] ?? 'text-embedding-ada-002' );
		$sanitized['gemini_model']           = sanitize_text_field( $input['gemini_model'] ?? 'gemini-1.5-flash' );
		$sanitized['deepseek_model']         = sanitize_text_field( $input['deepseek_model'] ?? 'deepseek-chat' );
		$sanitized['ollama_url']             = esc_url_raw( $input['ollama_url'] ?? 'http://localhost:11434' );
		$sanitized['ollama_model']           = sanitize_text_field( $input['ollama_model'] ?? 'llama3.2' );
		$sanitized['ollama_embedding_model'] = sanitize_text_field( $input['ollama_embedding_model'] ?? 'nomic-embed-text' );
		$sanitized['groq_model']             = sanitize_text_field( $input['groq_model'] ?? 'llama-3.3-70b-versatile' );
		$sanitized['claude_model']           = sanitize_text_field( $input['claude_model'] ?? 'claude-3-5-sonnet-latest' );

		// Parâmetros numéricos
		$sanitized['max_tokens'] = absint( $input['max_tokens'] ?? 2000 );
		$sanitized['max_tokens'] = max( 100, min( 16000, $sanitized['max_tokens'] ) );

		$sanitized['temperature'] = floatval( $input['temperature'] ?? 0.7 );
		$sanitized['temperature'] = max( 0, min( 2, $sanitized['temperature'] ) );

		$sanitized['similarity_threshold'] = floatval( $input['similarity_threshold'] ?? 0.3 );
		$sanitized['similarity_threshold'] = max( 0, min( 1, $sanitized['similarity_threshold'] ) );

		$sanitized['max_results'] = absint( $input['max_results'] ?? 10 );
		$sanitized['max_results'] = max( 1, min( 50, $sanitized['max_results'] ) );

		$sanitized['batch_size'] = absint( $input['batch_size'] ?? 25 );
		$sanitized['batch_size'] = max( 5, min( 100, $sanitized['batch_size'] ) );

		$sanitized['request_timeout'] = absint( $input['request_timeout'] ?? 120 );
		$sanitized['cache_duration']  = absint( $input['cache_duration'] ?? 3600 );

		// Booleanos
		$sanitized['enable_chat']      = ! empty( $input['enable_chat'] );
		$sanitized['enable_search']    = ! empty( $input['enable_search'] );
		$sanitized['enable_analytics'] = ! empty( $input['enable_analytics'] );
		$sanitized['enable_feedback']  = ! empty( $input['enable_feedback'] );
		$sanitized['debug_mode']       = ! empty( $input['debug_mode'] );

		// Coleções
		if ( isset( $input['default_collections'] ) ) {
			$sanitized['default_collections'] = array_map( 'absint', (array) $input['default_collections'] );
		}

		// Prompts
		$sanitized['system_prompt']   = wp_kses_post( $input['system_prompt'] ?? '' );
		$sanitized['search_prompt']   = wp_kses_post( $input['search_prompt'] ?? '' );
		$sanitized['chat_prompt']     = wp_kses_post( $input['chat_prompt'] ?? '' );
		$sanitized['welcome_message'] = sanitize_textarea_field( $input['welcome_message'] ?? '' );

		// Perguntas sugeridas
		if ( isset( $input['suggested_questions'] ) ) {
			$sanitized['suggested_questions'] = array_map( 'sanitize_text_field', (array) $input['suggested_questions'] );
			$sanitized['suggested_questions'] = array_filter( $sanitized['suggested_questions'] );
		}

		// Campos de indexação
		if ( isset( $input['index_fields'] ) ) {
			$sanitized['index_fields'] = array_map( 'sanitize_text_field', (array) $input['index_fields'] );
		}

		// Aparência
		if ( isset( $input['appearance'] ) ) {
			$sanitized['appearance'] = \Oraculo_Tainacan\sanitize_appearance( $input['appearance'] );
		}

		return $sanitized;
	}
}
