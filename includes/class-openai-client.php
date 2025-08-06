<?php
/**
 * Classe para comunicação com as APIs da OpenAI
 *
 * @package Oraculo_Tainacan
 */

// Impede o acesso direto
if (!defined('WPINC')) {
    die;
}

/**
 * Classe para comunicação com as APIs da OpenAI
 */
class Oraculo_Tainacan_OpenAI_Client {
    /**
     * API Key da OpenAI (encrypted)
     *
     * @var string
     */
    private string $encrypted_api_key;

    /**
     * Modelo de embedding a ser usado
     *
     * @var string
     */
    private string $embedding_model = 'text-embedding-3-large';

    /**
     * Modelo de chat a ser usado
     *
     * @var string
     */
    private string $chat_model = 'gpt-4o';

    /**
     * Modo de debug
     *
     * @var bool
     */
    private bool $debug_mode = false;

    /**
     * Maximum tokens per batch request
     *
     * @var int
     */
    private const MAX_BATCH_TOKENS = 100000;

    /**
     * Maximum items per batch
     *
     * @var int
     */
    private const MAX_BATCH_ITEMS = 2048;

    /**
     * Available models
     *
     * @var array
     */
    private const AVAILABLE_MODELS = [
        'gpt-4o' => 'GPT-4 Optimized',
        'gpt-4o-mini' => 'GPT-4 Mini',
        'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
        'mistral-large' => 'Mistral Large',
        'mistral-lite-local' => 'Mistral Lite (Local)',
        'custom' => 'Custom Model'
    ];

    /**
     * Construtor
     */
    public function __construct() {
        $this->encrypted_api_key = get_option('oraculo_tainacan_openai_api_key_encrypted', '');
        $this->chat_model = get_option('oraculo_tainacan_openai_model', 'gpt-4o');
        $this->debug_mode = (bool)get_option('oraculo_tainacan_debug_mode', false);
    }

    /**
     * Get decrypted API key
     * 
     * @return string Decrypted API key
     */
    private function get_api_key(): string {
        if (empty($this->encrypted_api_key)) {
            // Check for legacy unencrypted key
            $legacy_key = get_option('oraculo_tainacan_openai_api_key', '');
            if (!empty($legacy_key)) {
                // Encrypt and save
                $this->save_api_key($legacy_key);
                // Delete legacy
                delete_option('oraculo_tainacan_openai_api_key');
                return $legacy_key;
            }
            return '';
        }

        return $this->decrypt_api_key($this->encrypted_api_key);
    }

    /**
     * Save API key encrypted
     * 
     * @param string $api_key Plain API key
     * @return bool Success
     */
    public function save_api_key(string $api_key): bool {
        if (empty($api_key)) {
            return false;
        }

        $encrypted = $this->encrypt_api_key($api_key);
        return update_option('oraculo_tainacan_openai_api_key_encrypted', $encrypted);
    }

    /**
     * Encrypt API key using sodium if available
     * 
     * @param string $api_key Plain API key
     * @return string Encrypted key
     */
    private function encrypt_api_key(string $api_key): string {
        if (!function_exists('sodium_crypto_secretbox')) {
            // Fallback to basic encryption if sodium not available
            return base64_encode($api_key);
        }

        $key = $this->get_encryption_key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($api_key, $nonce, $key);
        
        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt API key
     * 
     * @param string $encrypted Encrypted key
     * @return string Plain API key
     */
    private function decrypt_api_key(string $encrypted): string {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            // Fallback for basic encryption
            return base64_decode($encrypted);
        }

        $decoded = base64_decode($encrypted);
        if ($decoded === false) {
            return '';
        }
        
        $key = $this->get_encryption_key();
        $nonce = mb_substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit');
        $ciphertext = mb_substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit');
        
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        
        return $plaintext !== false ? $plaintext : '';
    }

    /**
     * Get encryption key derived from AUTH_SALT
     * 
     * @return string 32-byte key
     */
    private function get_encryption_key(): string {
        $salt = defined('AUTH_SALT') ? AUTH_SALT : 'default-salt';
        return hash('sha256', $salt, true);
    }

    /**
     * Get available models
     * 
     * @return array Model options
     */
    public static function get_available_models(): array {
        return self::AVAILABLE_MODELS;
    }

    /**
     * Gera embeddings para múltiplos textos em lote
     *
     * @param array $texts Array de textos para gerar embeddings
     * @return array|WP_Error Array com os vetores de embedding ou erro
     */
    public function generate_embeddings_batch(array $texts): array|WP_Error {
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return new WP_Error('api_key_missing', __('API Key da OpenAI não configurada', 'oraculo-tainacan'));
        }

        // Split into chunks if needed
        $chunks = $this->split_texts_for_batch($texts);
        $all_embeddings = array();

        foreach ($chunks as $chunk) {
            $request_body = json_encode(array(
                'model' => $this->embedding_model,
                'input' => $chunk,
                'encoding_format' => 'float'
            ));

            // CORREÇÃO: Garantir que o body seja sempre string
            if ($request_body === false) {
                $this->log_error('Erro ao codificar JSON para request body');
                return new WP_Error('json_encode_error', 'Erro ao preparar dados da requisição');
            }

            $response = wp_remote_post(
                'https://api.openai.com/v1/embeddings',
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $api_key,
                        'Content-Type' => 'application/json',
                    ),
                    'timeout' => 60,
                    'body' => $request_body // Agora garantidamente string
                )
            );

            if (is_wp_error($response)) {
                $this->log_error('Batch Embedding API error: ' . $response->get_error_message());
                return $response;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $status_code = wp_remote_retrieve_response_code($response);

            if ($status_code !== 200 || empty($body['data'])) {
                $error_message = isset($body['error']['message']) ? $body['error']['message'] : 'Erro desconhecido';
                $this->log_error('OpenAI Batch API error (' . $status_code . '): ' . $error_message);
                return new WP_Error('api_error', 'OpenAI API error: ' . $error_message);
            }

            // Extract embeddings in order
            foreach ($body['data'] as $item) {
                $all_embeddings[$item['index']] = $item['embedding'];
            }

            $this->log_debug('Batch processed: ' . count($chunk) . ' items, ' . count($body['data']) . ' embeddings generated');
        }

        // Sort by index to maintain order
        ksort($all_embeddings);
        
        return array_values($all_embeddings);
    }

    /**
     * Split texts into chunks for batch processing
     * 
     * @param array $texts Input texts
     * @return array Array of chunks
     */
    private function split_texts_for_batch(array $texts): array {
        $chunks = array();
        $current_chunk = array();
        $current_tokens = 0;

        foreach ($texts as $text) {
            // Estimate tokens (rough approximation: 1 token ≈ 4 characters)
            $estimated_tokens = strlen($text) / 4;
            
            if (count($current_chunk) >= self::MAX_BATCH_ITEMS || 
                ($current_tokens + $estimated_tokens) > self::MAX_BATCH_TOKENS) {
                // Start new chunk
                if (!empty($current_chunk)) {
                    $chunks[] = $current_chunk;
                }
                $current_chunk = array($text);
                $current_tokens = $estimated_tokens;
            } else {
                $current_chunk[] = $text;
                $current_tokens += $estimated_tokens;
            }
        }

        // Add last chunk
        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }

        return $chunks;
    }

    /**
     * Gera embeddings para um texto
     *
     * @param string $text Texto para gerar embedding
     * @return array|WP_Error Array com os vetores de embedding ou erro
     */
    public function generate_embedding(string $text): array|WP_Error {
        $result = $this->generate_embeddings_batch(array($text));
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return $result[0] ?? new WP_Error('no_embedding', 'No embedding generated');
    }

    /**
     * Gera uma resposta via ChatGPT baseada em um prompt
     *
     * @param string $system_prompt Instruções do sistema
     * @param string $user_prompt Prompt do usuário
     * @param array $context Contexto adicional (itens do Tainacan)
     * @return string|WP_Error Resposta gerada ou erro
     */
    public function generate_chat_response(string $system_prompt, string $user_prompt, array $context): string|WP_Error {
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return new WP_Error('api_key_missing', __('API Key da OpenAI não configurada', 'oraculo-tainacan'));
        }

        // Format context with snippets
        $context_text = '';
        foreach ($context as $index => $item) {
            $context_text .= "[" . ($index + 1) . "] ";
            $context_text .= "Título: " . $item['title'] . "\n";
            
            // Add snippet if available
            if (!empty($item['snippet'])) {
                $context_text .= "Trecho relevante: " . $item['snippet'] . "\n";
            } elseif (!empty($item['description'])) {
                $context_text .= "Descrição: " . wp_trim_words($item['description'], 50) . "\n";
            }
            
            if (!empty($item['metadata'])) {
                $context_text .= "Metadados:\n";
                foreach ($item['metadata'] as $key => $value) {
                    $context_text .= "- " . $key . ": " . $value . "\n";
                }
            }
            
            $context_text .= "Relevância: " . ($item['score'] ?? '0') . "%\n";
            $context_text .= "Link: " . $item['permalink'] . "\n\n";
        }

        // Build complete prompt
        $full_prompt = $user_prompt . "\n\nDocumentos relevantes:\n" . $context_text;

        // Log prompt in debug mode
        $this->log_debug('Chat model: ' . $this->chat_model);
        $this->log_debug('Prompt length: ' . strlen($full_prompt) . ' chars');

        // Prepare messages
        $messages = array(
            array(
                'role' => 'system',
                'content' => $system_prompt
            ),
            array(
                'role' => 'user',
                'content' => $full_prompt
            )
        );

        // Prepare request body
        $request_data = array(
            'model' => $this->chat_model,
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 2000,
            'stream' => false
        );

        $request_body = json_encode($request_data);

        // CORREÇÃO: Garantir que o body seja sempre string
        if ($request_body === false) {
            $this->log_error('Erro ao codificar JSON para chat request body');
            return new WP_Error('json_encode_error', 'Erro ao preparar dados da requisição de chat');
        }

        // Determine API endpoint based on model
        $endpoint = $this->get_api_endpoint($this->chat_model);
        
        $response = wp_remote_post(
            $endpoint,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 60,
                'body' => $request_body // Agora garantidamente string
            )
        );

        if (is_wp_error($response)) {
            $this->log_error('Chat API error: ' . $response->get_error_message());
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200 || empty($body['choices'][0]['message']['content'])) {
            $error_message = isset($body['error']['message']) ? $body['error']['message'] : 'Erro desconhecido';
            $this->log_error('Chat API error (' . $status_code . '): ' . $error_message);
            return new WP_Error('api_error', 'Chat API error: ' . $error_message);
        }

        $this->log_debug('Response generated. Tokens: ' . 
            (isset($body['usage']['total_tokens']) ? $body['usage']['total_tokens'] : 'N/A'));
        
        return $body['choices'][0]['message']['content'];
    }

    /**
     * Get API endpoint based on model
     * 
     * @param string $model Model name
     * @return string API endpoint
     */
    private function get_api_endpoint(string $model): string {
        if (str_starts_with($model, 'mistral-')) {
            // Mistral API endpoint (placeholder - would need actual Mistral API)
            return 'https://api.mistral.ai/v1/chat/completions';
        }
        
        return 'https://api.openai.com/v1/chat/completions';
    }

    /**
     * Registra mensagens de erro
     *
     * @param string $message Mensagem de erro
     */
    private function log_error(string $message): void {
        if (WP_DEBUG === true) {
            error_log('[Oráculo Tainacan] [ERROR] ' . $message);
        }
    }

    /**
     * Registra mensagens de debug
     *
     * @param string $message Mensagem de debug
     */
    private function log_debug(string $message): void {
        if ($this->debug_mode && WP_DEBUG === true) {
            error_log('[Oráculo Tainacan] [DEBUG] ' . $message);
        }
    }

    /**
     * Testa a conectividade com a API
     *
     * @return bool|WP_Error True se conectado com sucesso, WP_Error se falhar
     */
    public function test_connection(): bool|WP_Error {
        $api_key = $this->get_api_key();
        if (empty($api_key)) {
            return new WP_Error('api_key_missing', __('API Key não configurada', 'oraculo-tainacan'));
        }

        $endpoint = str_starts_with($this->chat_model, 'mistral-') 
            ? 'https://api.mistral.ai/v1/models'
            : 'https://api.openai.com/v1/models';

        $response = wp_remote_get(
            $endpoint,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 10
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error_message = isset($body['error']['message']) ? $body['error']['message'] : 'Erro desconhecido';
            return new WP_Error('api_error', 'API error: ' . $error_message);
        }

        return true;
    }
}