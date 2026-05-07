<?php
/**
 * Provedor Groq
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\AI\Providers;

use Oraculo_Tainacan\AI\AbstractAIProvider;
use WP_Error;

/**
 * Implementação do provedor Groq - LLMs ultra-rápidos
 */
class GroqProvider extends AbstractAIProvider {

    /**
     * URL base da API
     */
    private const API_BASE_URL = 'https://api.groq.com/openai/v1';

    /**
     * Modelos disponíveis
     */
    private const MODELS = [
        'llama-3.3-70b-versatile' => [
            'name' => 'Llama 3.3 70B',
            'context' => 128000,
            'input_price' => 0.00059,
            'output_price' => 0.00079,
            'description' => 'Modelo mais capaz, excelente qualidade',
        ],
        'llama-3.1-70b-versatile' => [
            'name' => 'Llama 3.1 70B',
            'context' => 128000,
            'input_price' => 0.00059,
            'output_price' => 0.00079,
            'description' => 'Alta qualidade com contexto longo',
        ],
        'llama-3.1-8b-instant' => [
            'name' => 'Llama 3.1 8B',
            'context' => 128000,
            'input_price' => 0.00005,
            'output_price' => 0.00008,
            'description' => 'Ultra rápido e econômico',
        ],
        'llama3-70b-8192' => [
            'name' => 'Llama 3 70B',
            'context' => 8192,
            'input_price' => 0.00059,
            'output_price' => 0.00079,
            'description' => 'Modelo estável',
        ],
        'mixtral-8x7b-32768' => [
            'name' => 'Mixtral 8x7B',
            'context' => 32768,
            'input_price' => 0.00024,
            'output_price' => 0.00024,
            'description' => 'Mixture of Experts eficiente',
        ],
        'gemma2-9b-it' => [
            'name' => 'Gemma 2 9B',
            'context' => 8192,
            'input_price' => 0.00020,
            'output_price' => 0.00020,
            'description' => 'Modelo compacto do Google',
        ],
    ];

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return 'groq';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return 'Groq';
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __('Inferência ultra-rápida em chips LPU. Até 10x mais rápido que GPUs tradicionais.', 'oraculo_tainacan');
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
        // Groq não oferece API de embeddings
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function is_configured(): bool {
        return $this->has_api_key('api_key');
    }

    /**
     * {@inheritdoc}
     */
    public function test_connection(): array {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => __('Chave de API não configurada.', 'oraculo_tainacan'),
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
            'message' => __('Conexão estabelecida com sucesso!', 'oraculo_tainacan'),
            'details' => [
                'models_available' => count($response['data'] ?? []),
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generate_embedding(string $text, ?string $model = null) {
        return new WP_Error(
            'not_supported',
            __('Groq não oferece API de embeddings. Use OpenAI ou Ollama para embeddings.', 'oraculo_tainacan')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function generate_embeddings_batch(array $texts, ?string $model = null) {
        return new WP_Error(
            'not_supported',
            __('Groq não oferece API de embeddings. Use OpenAI ou Ollama para embeddings.', 'oraculo_tainacan')
        );
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
            return new WP_Error('not_configured', __('Provedor Groq não configurado.', 'oraculo_tainacan'));
        }

        $options = $this->prepare_options($options);
        $model = $options['model'] ?? $this->get_config('model', 'llama-3.3-70b-versatile');

        $formatted_messages = $this->format_messages($messages, $system_prompt);

        $body = [
            'model' => $model,
            'messages' => $formatted_messages,
            'max_tokens' => $options['max_tokens'],
            'temperature' => $options['temperature'],
        ];

        $start_time = microtime(true);

        $response = $this->make_request_with_retry(
            self::API_BASE_URL . '/chat/completions',
            $body,
            $this->get_headers()
        );

        $latency = round((microtime(true) - $start_time) * 1000);

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', __('Resposta inválida da API.', 'oraculo_tainacan'));
        }

        $usage = $this->normalize_usage($response['usage'] ?? []);

        // Groq é conhecido pela velocidade - incluir métricas
        $groq_metrics = [];
        if (isset($response['x_groq'])) {
            $groq_metrics = [
                'queue_time' => $response['x_groq']['usage']['queue_time'] ?? null,
                'prompt_time' => $response['x_groq']['usage']['prompt_time'] ?? null,
                'completion_time' => $response['x_groq']['usage']['completion_time'] ?? null,
            ];
        }

        return [
            'response' => $response['choices'][0]['message']['content'],
            'model' => $model,
            'usage' => $usage,
            'cost' => $this->calculate_cost($usage, $model),
            'finish_reason' => $response['choices'][0]['finish_reason'] ?? 'stop',
            'latency_ms' => $latency,
            'groq_metrics' => $groq_metrics,
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
            $callback('', true, new WP_Error('not_configured', __('Provedor não configurado.', 'oraculo_tainacan')));
            return;
        }

        $options = $this->prepare_options($options);
        $model = $options['model'] ?? $this->get_config('model', 'llama-3.3-70b-versatile');

        $messages = $this->format_messages([['role' => 'user', 'content' => $prompt]], $system_prompt);

        $body = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'],
            'temperature' => $options['temperature'],
            'stream' => true,
        ];

        $ch = curl_init(self::API_BASE_URL . '/chat/completions');

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

        curl_exec($ch);

        if (curl_errno($ch)) {
            $callback('', true, new WP_Error('curl_error', curl_error($ch)));
        }

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

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function get_model_limit(string $model): int {
        return self::MODELS[$model]['context'] ?? 8192;
    }

    /**
     * {@inheritdoc}
     */
    public function supports_embeddings(): bool {
        return false;
    }

    /**
     * Obtém headers para requisição
     *
     * @return array
     */
    private function get_headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->get_api_key(),
        ];
    }
}
