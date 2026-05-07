<?php
/**
 * Provedor DeepSeek
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\AI\Providers;

use Oraculo_Tainacan\AI\AbstractAIProvider;
use WP_Error;

/**
 * Implementação do provedor DeepSeek
 */
class DeepSeekProvider extends AbstractAIProvider {

    /**
     * URL base da API
     */
    private const API_BASE_URL = 'https://api.deepseek.com/v1';

    /**
     * Modelos disponíveis
     */
    private const MODELS = [
        'deepseek-chat' => [
            'name' => 'DeepSeek Chat',
            'context' => 64000,
            'input_price' => 0.00014,
            'output_price' => 0.00028,
            'description' => 'Modelo conversacional otimizado',
        ],
        'deepseek-coder' => [
            'name' => 'DeepSeek Coder',
            'context' => 64000,
            'input_price' => 0.00014,
            'output_price' => 0.00028,
            'description' => 'Especializado em programação',
        ],
        'deepseek-reasoner' => [
            'name' => 'DeepSeek Reasoner (R1)',
            'context' => 64000,
            'input_price' => 0.00055,
            'output_price' => 0.00219,
            'description' => 'Modelo de raciocínio avançado',
        ],
    ];

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return 'deepseek';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return 'DeepSeek';
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __('IA chinesa de alta qualidade com preços muito competitivos. Compatível com API OpenAI.', 'oraculo_tainacan');
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
        // DeepSeek não oferece API de embeddings pública
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

        // DeepSeek usa formato OpenAI, testar com uma requisição simples
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
            __('DeepSeek não oferece API de embeddings. Use OpenAI ou Ollama para embeddings.', 'oraculo_tainacan')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function generate_embeddings_batch(array $texts, ?string $model = null) {
        return new WP_Error(
            'not_supported',
            __('DeepSeek não oferece API de embeddings. Use OpenAI ou Ollama para embeddings.', 'oraculo_tainacan')
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
            return new WP_Error('not_configured', __('Provedor DeepSeek não configurado.', 'oraculo_tainacan'));
        }

        $options = $this->prepare_options($options);
        $model = $options['model'] ?? $this->get_config('model', 'deepseek-chat');

        $formatted_messages = $this->format_messages($messages, $system_prompt);

        $body = [
            'model' => $model,
            'messages' => $formatted_messages,
            'max_tokens' => $options['max_tokens'],
            'temperature' => $options['temperature'],
        ];

        $response = $this->make_request_with_retry(
            self::API_BASE_URL . '/chat/completions',
            $body,
            $this->get_headers()
        );

        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['choices'][0]['message']['content'])) {
            return new WP_Error('invalid_response', __('Resposta inválida da API.', 'oraculo_tainacan'));
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
        return self::MODELS[$model]['context'] ?? 64000;
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
