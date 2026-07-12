<?php
/**
 * Interface para provedores de IA
 *
 * @package Oraculo_Tainacan
 */

declare( strict_types=1 );

namespace Oraculo_Tainacan\AI;

/**
 * Interface que define o contrato para todos os provedores de IA
 */
interface AIProviderInterface {

	/**
	 * Obtém o ID único do provedor
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Obtém o nome de exibição do provedor
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Obtém a descrição do provedor
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Obtém modelos disponíveis para chat/completion
	 *
	 * @return array
	 */
	public function get_available_models(): array;

	/**
	 * Obtém modelos disponíveis para embeddings
	 *
	 * @return array
	 */
	public function get_embedding_models(): array;

	/**
	 * Verifica se o provedor está configurado corretamente
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Configura o provedor com opções
	 *
	 * @param array $options
	 * @return void
	 */
	public function configure( array $options ): void;

	/**
	 * Testa a conexão com a API
	 *
	 * @return array ['success' => bool, 'message' => string, 'details' => array]
	 */
	public function test_connection(): array;

	/**
	 * Gera embedding para um texto
	 *
	 * @param string      $text
	 * @param string|null $model
	 * @return array|WP_Error Vetor de embedding ou erro
	 */
	public function generate_embedding( string $text, ?string $model = null );

	/**
	 * Gera embeddings para múltiplos textos
	 *
	 * @param array       $texts
	 * @param string|null $model
	 * @return array|WP_Error Array de vetores ou erro
	 */
	public function generate_embeddings_batch( array $texts, ?string $model = null );

	/**
	 * Gera resposta de chat/completion
	 *
	 * @param string $prompt Mensagem do usuário
	 * @param string $system_prompt Prompt do sistema
	 * @param array  $options Opções adicionais (model, temperature, max_tokens, etc)
	 * @return array|WP_Error ['response' => string, 'usage' => array]
	 */
	public function generate_response( string $prompt, string $system_prompt = '', array $options = array() );

	/**
	 * Gera resposta de chat com histórico de conversa
	 *
	 * @param array  $messages Histórico de mensagens [['role' => 'user|assistant', 'content' => '...']]
	 * @param string $system_prompt
	 * @param array  $options
	 * @return array|WP_Error
	 */
	public function chat( array $messages, string $system_prompt = '', array $options = array() );

	/**
	 * Gera resposta em streaming
	 *
	 * @param string   $prompt
	 * @param string   $system_prompt
	 * @param callable $callback Função para processar chunks
	 * @param array    $options
	 * @return void
	 */
	public function stream_response( string $prompt, string $system_prompt, callable $callback, array $options = array() ): void;

	/**
	 * Obtém informações de preço do modelo
	 *
	 * @param string $model
	 * @return array ['input' => float, 'output' => float, 'unit' => string]
	 */
	public function get_pricing( string $model ): array;

	/**
	 * Calcula custo estimado baseado no uso
	 *
	 * @param array  $usage ['prompt_tokens' => int, 'completion_tokens' => int]
	 * @param string $model
	 * @return float Custo em USD
	 */
	public function calculate_cost( array $usage, string $model ): float;

	/**
	 * Obtém limite de tokens do modelo
	 *
	 * @param string $model
	 * @return int
	 */
	public function get_model_limit( string $model ): int;

	/**
	 * Verifica se o provedor suporta embeddings
	 *
	 * @return bool
	 */
	public function supports_embeddings(): bool;

	/**
	 * Verifica se o provedor suporta streaming
	 *
	 * @return bool
	 */
	public function supports_streaming(): bool;

	/**
	 * Obtém o último erro ocorrido
	 *
	 * @return string|null
	 */
	public function get_last_error(): ?string;
}
