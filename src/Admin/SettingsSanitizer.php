<?php
/**
 * Sanitização das configurações do plugin
 *
 * Allowlist por chave com limites numéricos; API keys com placeholder
 * (••••••••) preservam o valor já armazenado. Chaves ausentes na entrada
 * caem para o valor já salvo (e só então para o padrão), de modo que um
 * formulário que renderiza apenas parte das opções não apague o restante.
 *
 * Ponto único de sanitização: usado tanto pelo POST do admin
 * (wp_ajax_oraculo_save_settings) quanto pelo endpoint REST /settings.
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
		$current   = \Oraculo_Tainacan\Oraculo_Tainacan::get_options();

		/**
		 * Valor de texto: entrada > valor salvo > padrão.
		 *
		 * @param string $key     Chave da opção.
		 * @param string $default Padrão quando nem entrada nem valor salvo existem.
		 * @return string
		 */
		$text = static function ( string $key, string $default ) use ( $input, $current ): string {
			$value = $input[ $key ] ?? $current[ $key ] ?? $default;
			return sanitize_text_field( (string) $value );
		};

		// Provider
		$sanitized['ai_provider'] = $text( 'ai_provider', 'openai' );

		// API Keys - só atualizar se não for placeholder
		$api_keys = array(
			'openai_api_key',
			'gemini_api_key',
			'deepseek_api_key',
			'groq_api_key',
			'claude_api_key',
		);

		foreach ( $api_keys as $key ) {
			if ( isset( $input[ $key ] ) && $input[ $key ] !== '••••••••' && ! empty( $input[ $key ] ) ) {
				// Prefixo 'enc:' é a convenção que AbstractAIProvider::get_api_key()
				// já reconhece para descriptografar — só faltava alguém gravar
				// nesse formato. Chave nunca fica em texto puro na wp_options.
				$sanitized[ $key ] = 'enc:' . \Oraculo_Tainacan\encrypt_value( sanitize_text_field( (string) $input[ $key ] ) );
			} else {
				// Placeholder ou campo vazio: mantém o valor já salvo (já
				// criptografado, se veio de um save feito após esta mudança).
				$sanitized[ $key ] = $current[ $key ] ?? '';
			}
		}

		// Modelos
		$sanitized['openai_model']           = $text( 'openai_model', 'gpt-5-mini' );
		$sanitized['openai_embedding_model'] = $text( 'openai_embedding_model', 'text-embedding-3-small' );
		$sanitized['gemini_model']           = $text( 'gemini_model', 'gemini-2.5-flash' );
		$sanitized['deepseek_model']         = $text( 'deepseek_model', 'deepseek-chat' );
		$sanitized['ollama_model']           = $text( 'ollama_model', 'llama3.2' );
		$sanitized['ollama_embedding_model'] = $text( 'ollama_embedding_model', 'nomic-embed-text' );
		$sanitized['groq_model']             = $text( 'groq_model', 'llama-3.3-70b-versatile' );
		$sanitized['claude_model']           = $text( 'claude_model', 'claude-sonnet-5' );

		$sanitized['ollama_url'] = esc_url_raw(
			(string) ( $input['ollama_url'] ?? $current['ollama_url'] ?? 'http://localhost:11434' )
		);

		// Parâmetros numéricos
		$sanitized['max_tokens'] = absint( $input['max_tokens'] ?? $current['max_tokens'] ?? 2000 );
		$sanitized['max_tokens'] = max( 100, min( 16000, $sanitized['max_tokens'] ) );

		$sanitized['temperature'] = floatval( $input['temperature'] ?? $current['temperature'] ?? 0.7 );
		$sanitized['temperature'] = max( 0, min( 2, $sanitized['temperature'] ) );

		$sanitized['similarity_threshold'] = floatval( $input['similarity_threshold'] ?? $current['similarity_threshold'] ?? 0.3 );
		$sanitized['similarity_threshold'] = max( 0, min( 1, $sanitized['similarity_threshold'] ) );

		$sanitized['max_results'] = absint( $input['max_results'] ?? $current['max_results'] ?? 10 );
		$sanitized['max_results'] = max( 1, min( 50, $sanitized['max_results'] ) );

		$sanitized['batch_size'] = absint( $input['batch_size'] ?? $current['batch_size'] ?? 25 );
		$sanitized['batch_size'] = max( 5, min( 100, $sanitized['batch_size'] ) );

		$sanitized['request_timeout'] = absint( $input['request_timeout'] ?? $current['request_timeout'] ?? 120 );
		$sanitized['cache_duration']  = absint( $input['cache_duration'] ?? $current['cache_duration'] ?? 3600 );

		// Booleanos: checkbox ausente significa desmarcado, portanto não caem
		// para o valor salvo — do contrário nunca seria possível desativá-los.
		$sanitized['enable_chat']      = ! empty( $input['enable_chat'] );
		$sanitized['enable_search']    = ! empty( $input['enable_search'] );
		$sanitized['enable_analytics'] = ! empty( $input['enable_analytics'] );
		$sanitized['enable_feedback']  = ! empty( $input['enable_feedback'] );
		$sanitized['debug_mode']       = ! empty( $input['debug_mode'] );

		// Coleções
		$collections                      = $input['default_collections'] ?? $current['default_collections'] ?? array();
		$sanitized['default_collections'] = array_values( array_map( 'absint', (array) $collections ) );

		// Prompts
		$sanitized['system_prompt']   = wp_kses_post( (string) ( $input['system_prompt'] ?? $current['system_prompt'] ?? '' ) );
		$sanitized['search_prompt']   = wp_kses_post( (string) ( $input['search_prompt'] ?? $current['search_prompt'] ?? '' ) );
		$sanitized['chat_prompt']     = wp_kses_post( (string) ( $input['chat_prompt'] ?? $current['chat_prompt'] ?? '' ) );
		$sanitized['welcome_message'] = sanitize_textarea_field( (string) ( $input['welcome_message'] ?? $current['welcome_message'] ?? '' ) );

		// Perguntas sugeridas
		$questions                        = $input['suggested_questions'] ?? $current['suggested_questions'] ?? array();
		$sanitized['suggested_questions'] = array_values(
			array_filter( array_map( 'sanitize_text_field', (array) $questions ) )
		);

		// Campos de indexação
		$index_fields              = $input['index_fields'] ?? $current['index_fields'] ?? array( 'title', 'description' );
		$sanitized['index_fields'] = array_values( array_map( 'sanitize_text_field', (array) $index_fields ) );

		// Busca visual (AI API CLIP do IBRAM)
		// O backend de busca é derivado do provedor selecionado: o CLIP virou
		// um card de provedor como os demais (o select "Backend de Busca" da
		// aba Geral foi removido). Derivar aqui também migra configurações
		// antigas: quem tinha search_backend=clip salvo mas escolher outro
		// provedor no card volta ao backend local automaticamente.
		$sanitized['search_backend'] = ( 'clip' === $sanitized['ai_provider'] ) ? 'clip' : 'local';
		$sanitized['clip_api_url']   = esc_url_raw( $input['clip_api_url'] ?? '' );
		$sanitized['clip_api_model'] = sanitize_text_field( $input['clip_api_model'] ?? 'ViT-L-14' );

		$sanitized['clip_api_timeout'] = absint( $input['clip_api_timeout'] ?? 60 );
		$sanitized['clip_api_timeout'] = max( 5, min( 300, $sanitized['clip_api_timeout'] ) );

		// Aparência
		$appearance              = $input['appearance'] ?? $current['appearance'] ?? array();
		$sanitized['appearance'] = \Oraculo_Tainacan\sanitize_appearance( (array) $appearance );

		// Integração com o tema Tainacan
		$sanitized['theme_integration'] = self::sanitize_theme_integration(
			(array) ( $input['theme_integration'] ?? $current['theme_integration'] ?? array() )
		);

		return $sanitized;
	}

	/**
	 * Sanitiza o bloco de integração com o tema Tainacan.
	 *
	 * @param array $input Sub-array theme_integration.
	 * @return array
	 */
	private static function sanitize_theme_integration( array $input ): array {
		$defaults = \Oraculo_Tainacan\Frontend\ThemeIntegration::get_default_settings();

		$tab_label   = sanitize_text_field( (string) ( $input['tab_label'] ?? '' ) );
		$placeholder = sanitize_text_field( (string) ( $input['placeholder'] ?? '' ) );
		$scope       = (string) ( $input['scope'] ?? '' );

		return array(
			'enabled'          => ! empty( $input['enabled'] ),
			'tab_label'        => '' !== $tab_label ? $tab_label : $defaults['tab_label'],
			'placeholder'      => '' !== $placeholder ? $placeholder : $defaults['placeholder'],
			'scope'            => in_array( $scope, array( 'collection', 'all' ), true ) ? $scope : 'collection',
			'show_suggestions' => ! empty( $input['show_suggestions'] ),
		);
	}
}
