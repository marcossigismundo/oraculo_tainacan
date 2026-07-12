<?php
/**
 * Provedor OpenAI (ChatGPT)
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\AI\Providers;

use Oraculo_Tainacan\AI\AbstractAIProvider;
use WP_Error;

/**
 * Implementação do provedor OpenAI
 */
class OpenAIProvider extends AbstractAIProvider {

    /**
     * URL base da API
     */
    private const API_BASE_URL = 'https://api.openai.com/v1';

    /**
     * Modelos disponíveis com informações
     */
    private const MODELS = [
        'gpt-4o' => [
            'name' => 'GPT-4o',
            'context' => 128000,
            'input_price' => 0.0025,
            'output_price' => 0.01,
            'description' => 'Modelo mais capaz, recomendado para tarefas complexas',
        ],
        'gpt-4o-mini' => [
            'name' => 'GPT-4o Mini',
            'context' => 128000,
            'input_price' => 0.00015,
            'output_price' => 0.0006,
            'description' => 'Versão econômica do GPT-4o, ótimo custo-benefício',
        ],
        'gpt-4-turbo' => [
            'name' => 'GPT-4 Turbo',
            'context' => 128000,
            'input_price' => 0.01,
            'output_price' => 0.03,
            'description' => 'GPT-4 com contexto estendido',
        ],
        'gpt-4' => [
            'name' => 'GPT-4',
            'context' => 8192,
            'input_price' => 0.03,
            'output_price' => 0.06,
            'description' => 'Modelo GPT-4 original',
        ],
        'gpt-3.5-turbo' => [
            'name' => 'GPT-3.5 Turbo',
            'context' => 16385,
            'input_price' => 0.0005,
            'output_price' => 0.0015,
            'description' => 'Modelo rápido e econômico',
        ],
        'o1' => [
            'name' => 'o1',
            'context' => 200000,
            'input_price' => 0.015,
            'output_price' => 0.06,
            'description' => 'Modelo de raciocínio avançado',
        ],
        'o1-mini' => [
            'name' => 'o1 Mini',
            'context' => 128000,
            'input_price' => 0.003,
            'output_price' => 0.012,
            'description' => 'Versão menor do modelo de raciocínio',
        ],
    ];

    /**
     * Modelos de embedding
     */
    private const EMBEDDING_MODELS = [
        'text-embedding-ada-002' => [
            'name' => 'Ada 002',
            'dimensions' => 1536,
            'price' => 0.0001,
            'description' => 'Modelo de embedding padrão',
        ],
        'text-embedding-3-small' => [
            'name' => 'Embedding 3 Small',
            'dimensions' => 1536,
            'price' => 0.00002,
            'description' => 'Modelo mais econômico',
        ],
        'text-embedding-3-large' => [
            'name' => 'Embedding 3 Large',
            'dimensions' => 3072,
            'price' => 0.00013,
            'description' => 'Modelo de alta qualidade',
        ],
    ];

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return 'openai';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return 'OpenAI (ChatGPT)';
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __('Provedor oficial da OpenAI. Inclui GPT-4o, GPT-4 e GPT-3.5 Turbo.', 'oraculo-tainacan');
    }

    /**
     * {@inheritdoc}
     */
    public function get_available_models(): array {
        $models = [];
        foreach (self::MODELS as $id => $info) {
            $models[] = [
                'id' => $id,
                'name' => $info['name'],
                'context_length' => $info['context'],
                'description' => $info['description'],
            ];
        }
        return $models;
    }

    /**
     * {@inheritdoc}
     */
    public function get_embedding_models(): array {
        $models = [];
        foreach (self::EMBEDDING_MODELS as $id => $info) {
            $models[] = [
                'id' => $id,
                'name' => $info['name'],
                'dimensions' => $info['dimensions'],
                'description' => $info['description'],
            ];
        }
        return $models;
    }

    /**
     * {@inheritdoc}
     */
    public function is_configured(): bool {
        $has_key = $this->has_api_key('api_key');
        $api_key = $this->get_api_key('api_key');
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
            error_log('[Oraculo OpenAI] is_configured check - has_key: ' . ($has_key ? 'true' : 'false') . ', key_length: ' . strlen($api_key));
        }
        return $has_key;
    }

    /**
     * {@inheritdoc}
     */
    public function test_connection(): array {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => __('Chave de API não configurada.', 'oraculo-tainacan'),
                'details' => [],
            ];
        }

        $response = $this->make_request(
            self::API_BASE_URL . '/models',
            [],
            $this->get_headers(),
            'GET'
        );

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
                'details' => [],
            ];
        }

        return [
            'success' => true,
            'message' => __('Conexão estabelecida com sucesso!', 'oraculo-tainacan'),
            'details' => [
                'models_available' => count($response['data'] ?? []),
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generate_embedding(string $text, ?string $model = null) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Provedor OpenAI não configurado.', 'oraculo-tainacan'));
        }

        $text = $this->validate_input($text, 8191 * 4); // ~8191 tokens máximo para ada-002

        if (empty($text)) {
            return new WP_Error('empty_input', __('Texto não pode estar vazio.', 'oraculo-tainacan'));
        }

        $model = $model ?? $this->get_config('embedding_model', 'text-embedding-ada-002');

        $response = $this->make_request_with_retry(
            self::API_BASE_URL . '/embeddings',
            [
                'model' => $model,
                'input' => $text,
            ],
            $this->get_headers()
        );

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['data'][0]['embedding'])) {
            return new WP_Error('invalid_response', __('Resposta inválida da API.', 'oraculo-tainacan'));
        }

        return [
            'embedding' => $response['data'][0]['embedding'],
            'model' => $model,
            'usage' => $response['usage'] ?? [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generate_embeddings_batch(array $texts, ?string $model = null) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Provedor OpenAI não configurado.', 'oraculo-tainacan'));
        }

        if (empty($texts)) {
            return new WP_Error('empty_input', __('Lista de textos não pode estar vazia.', 'oraculo-tainacan'));
        }

        // OpenAI suporta até 2048 embeddings por requisição
        $batch_size = min(100, count($texts));
        $model = $model ?? $this->get_config('embedding_model', 'text-embedding-ada-002');

        $all_embeddings = [];
        $total_usage = ['prompt_tokens' => 0, 'total_tokens' => 0];

        $batches = array_chunk($texts, $batch_size);

        foreach ($batches as $batch) {
            // Validar e limpar textos
            $clean_texts = [];
            foreach ($batch as $text) {
                $cleaned = $this->validate_input($text, 8191 * 4);
                // Garantir que o texto não está vazio
                if (!empty($cleaned)) {
                    $clean_texts[] = $cleaned;
                }
            }

            // Se todos os textos foram removidos, pular este batch
            if (empty($clean_texts)) {
                continue;
            }

            // Debug
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
                error_log('[Oraculo OpenAI] Embedding request - Model: ' . $model . ', Texts: ' . count($clean_texts));
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
                error_log('[Oraculo OpenAI] Primeiro texto (primeiros 100 chars): ' . mb_substr($clean_texts[0] ?? '', 0, 100));
            }

            $request_body = [
                'model' => $model,
                'input' => $clean_texts,
            ];

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
                error_log('[Oraculo OpenAI] Request body model: ' . $request_body['model']);
            }

            $response = $this->make_request_with_retry(
                self::API_BASE_URL . '/embeddings',
                $request_body,
                $this->get_headers()
            );

            if (is_wp_error($response)) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
                    error_log('[Oraculo OpenAI] Embedding error: ' . $response->get_error_message());
                    $error_data = $response->get_error_data();
                    if ($error_data) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Debug log gated by WP_DEBUG.
                        error_log('[Oraculo OpenAI] Error data: ' . print_r($error_data, true));
                    }
                }
                return $response;
            }

            if (!isset($response['data'])) {
                return new WP_Error('invalid_response', __('Resposta inválida da API.', 'oraculo-tainacan'));
            }

            // Ordenar por índice para manter ordem original
            usort($response['data'], fn($a, $b) => $a['index'] <=> $b['index']);

            foreach ($response['data'] as $item) {
                $all_embeddings[] = $item['embedding'];
            }

            if (isset($response['usage'])) {
                $total_usage['prompt_tokens'] += $response['usage']['prompt_tokens'] ?? 0;
                $total_usage['total_tokens'] += $response['usage']['total_tokens'] ?? 0;
            }

            // Pequena pausa entre batches para evitar rate limiting
            if (count($batches) > 1) {
                usleep(100000); // 100ms
            }
        }

        return [
            'embeddings' => $all_embeddings,
            'model' => $model,
            'usage' => $total_usage,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generate_response(string $prompt, string $system_prompt = '', array $options = []) {
        return $this->chat([['role' => 'user', 'content' => $prompt]], $system_prompt, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function chat(array $messages, string $system_prompt = '', array $options = []) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Provedor OpenAI não configurado.', 'oraculo-tainacan'));
        }

        $options = $this->prepare_options($options);
        $model = $options['model'] ?? $this->get_config('model', 'gpt-4o-mini');

        $formatted_messages = $this->format_messages($messages, $system_prompt);

        $body = [
            'model' => $model,
            'messages' => $formatted_messages,
            'max_tokens' => $options['max_tokens'],
            'temperature' => $options['temperature'],
        ];

        // Adicionar opções extras se fornecidas
        if (isset($options['top_p'])) {
            $body['top_p'] = $options['top_p'];
        }
        if (isset($options['presence_penalty'])) {
            $body['presence_penalty'] = $options['presence_penalty'];
        }
        if (isset($options['frequency_penalty'])) {
            $body['frequency_penalty'] = $options['frequency_penalty'];
        }

        $response = $this->make_request_with_retry(
            self::API_BASE_URL . '/chat/completions',
            $body,
            $this->get_headers()
        );

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', __('Resposta inválida da API.', 'oraculo-tainacan'));
        }

        $usage = $this->normalize_usage($response['usage'] ?? []);

        return [
            'response' => $response['choices'][0]['message']['content'],
            'model' => $model,
            'usage' => $usage,
            'cost' => $this->calculate_cost($usage, $model),
            'finish_reason' => $response['choices'][0]['finish_reason'] ?? 'stop',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supports_streaming(): bool {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function stream_response(string $prompt, string $system_prompt, callable $callback, array $options = []): void {
        if (!$this->is_configured()) {
            $callback('', true, new WP_Error('not_configured', __('Provedor não configurado.', 'oraculo-tainacan')));
            return;
        }

        $options = $this->prepare_options($options);
        $model = $options['model'] ?? $this->get_config('model', 'gpt-4o-mini');

        $messages = $this->format_messages([['role' => 'user', 'content' => $prompt]], $system_prompt);

        $body = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'],
            'temperature' => $options['temperature'],
            'stream' => true,
        ];

        // Usar cURL para streaming
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- WP HTTP API lacks streaming callback support required for SSE.
        $ch = curl_init(self::API_BASE_URL . '/chat/completions');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- WP HTTP API lacks streaming callback support required for SSE.
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => wp_json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->get_api_key(),
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($callback) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    if (strpos($line, 'data: ') === 0) {
                        $json = substr($line, 6);
                        if ($json === '[DONE]') {
                            $callback('', true);
                            return strlen($data);
                        }

                        $decoded = json_decode($json, true);
                        if (isset($decoded['choices'][0]['delta']['content'])) {
                            $callback($decoded['choices'][0]['delta']['content'], false);
                        }
                    }
                }
                return strlen($data);
            },
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec -- WP HTTP API lacks streaming callback support required for SSE.
        curl_exec($ch);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno -- WP HTTP API lacks streaming callback support required for SSE.
        if (curl_errno($ch)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error -- WP HTTP API lacks streaming callback support required for SSE.
            $callback('', true, new WP_Error('curl_error', curl_error($ch)));
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- WP HTTP API lacks streaming callback support required for SSE.
        curl_close($ch);
    }

    /**
     * {@inheritdoc}
     */
    public function get_pricing(string $model): array {
        $info = self::MODELS[$model] ?? null;

        if ($info) {
            return [
                'input' => $info['input_price'],
                'output' => $info['output_price'],
                'unit' => '1K tokens',
            ];
        }

        // Verificar modelos de embedding
        $embedding_info = self::EMBEDDING_MODELS[$model] ?? null;
        if ($embedding_info) {
            return [
                'input' => $embedding_info['price'],
                'output' => 0,
                'unit' => '1K tokens',
            ];
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function get_model_limit(string $model): int {
        return self::MODELS[$model]['context'] ?? 4096;
    }

    /**
     * Obtém headers para requisição
     *
     * @return array
     */
    private function get_headers(): array {
        $api_key = $this->get_api_key();

        // Debug: verificar se a API key foi obtida (não logar a key completa por segurança)
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            if (empty($api_key)) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
                error_log('[Oraculo OpenAI] WARNING: API key está vazia!');
            } else {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug log gated by WP_DEBUG.
                error_log('[Oraculo OpenAI] API key presente (length: ' . strlen($api_key) . ', starts with: ' . substr($api_key, 0, 7) . '...)');
            }
        }

        return [
            'Authorization' => 'Bearer ' . $api_key,
        ];
    }
}
