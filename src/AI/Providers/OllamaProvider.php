<?php
/**
 * Provedor Ollama (Local)
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\AI\Providers;

use Oraculo_Tainacan\AI\AbstractAIProvider;
use WP_Error;

/**
 * Implementação do provedor Ollama para execução local de LLMs
 */
class OllamaProvider extends AbstractAIProvider {

    /**
     * Modelos comuns disponíveis
     */
    private const COMMON_MODELS = [
        'llama3.2' => [
            'name' => 'Llama 3.2',
            'context' => 128000,
            'description' => 'Modelo mais recente da Meta, excelente qualidade',
        ],
        'llama3.1' => [
            'name' => 'Llama 3.1',
            'context' => 128000,
            'description' => 'Versão anterior com boa estabilidade',
        ],
        'mistral' => [
            'name' => 'Mistral',
            'context' => 32768,
            'description' => 'Modelo francês leve e eficiente',
        ],
        'mixtral' => [
            'name' => 'Mixtral 8x7B',
            'context' => 32768,
            'description' => 'MoE com qualidade próxima ao GPT-4',
        ],
        'gemma2' => [
            'name' => 'Gemma 2',
            'context' => 8192,
            'description' => 'Modelo do Google, compacto e rápido',
        ],
        'qwen2.5' => [
            'name' => 'Qwen 2.5',
            'context' => 128000,
            'description' => 'Modelo chinês multilingue',
        ],
        'phi3' => [
            'name' => 'Phi 3',
            'context' => 128000,
            'description' => 'Modelo pequeno da Microsoft',
        ],
        'deepseek-r1' => [
            'name' => 'DeepSeek R1',
            'context' => 64000,
            'description' => 'Modelo de raciocínio local',
        ],
    ];

    /**
     * Modelos de embedding
     */
    private const EMBEDDING_MODELS = [
        'nomic-embed-text' => [
            'name' => 'Nomic Embed Text',
            'dimensions' => 768,
            'description' => 'Modelo de embedding open-source eficiente',
        ],
        'mxbai-embed-large' => [
            'name' => 'MXBai Embed Large',
            'dimensions' => 1024,
            'description' => 'Embedding de alta qualidade',
        ],
        'all-minilm' => [
            'name' => 'All MiniLM',
            'dimensions' => 384,
            'description' => 'Modelo leve para embeddings',
        ],
        'bge-large' => [
            'name' => 'BGE Large',
            'dimensions' => 1024,
            'description' => 'Embedding multilíngue',
        ],
    ];

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return 'ollama';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return 'Ollama (Local)';
    }

    /**
     * {@inheritdoc}
     */
    public function get_description(): string {
        return __('Execute modelos de IA localmente sem custos. Requer Ollama instalado no servidor.', 'oraculo_tainacan');
    }

    /**
     * {@inheritdoc}
     */
    public function get_available_models(): array {
        // Tentar obter modelos instalados
        $installed = $this->get_installed_models();

        if (!empty($installed)) {
            $models = [];
            foreach ($installed as $model) {
                $name = $model['name'] ?? $model;
                $info = self::COMMON_MODELS[$name] ?? [
                    'name' => ucfirst($name),
                    'context' => 4096,
                    'description' => __('Modelo instalado localmente', 'oraculo_tainacan'),
                ];

                $models[] = [
                    'id' => $name,
                    'name' => $info['name'],
                    'context_length' => $info['context'],
                    'description' => $info['description'],
                    'size' => $model['size'] ?? null,
                ];
            }
            return $models;
        }

        // Retornar lista comum se não conseguir conectar
        $models = [];
        foreach (self::COMMON_MODELS as $id => $info) {
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
     * Obtém modelos instalados no Ollama
     *
     * @return array
     */
    private function get_installed_models(): array {
        $base_url = $this->get_base_url();
        $response = $this->make_request($base_url . '/api/tags', [], [], 'GET');

        if (is_wp_error($response)) {
            return [];
        }

        return $response['models'] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function is_configured(): bool {
        $base_url = $this->get_base_url();
        return !empty($base_url);
    }

    /**
     * {@inheritdoc}
     */
    public function test_connection(): array {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => __('URL do Ollama não configurada.', 'oraculo_tainacan'),
                'details' => [],
            ];
        }

        $base_url = $this->get_base_url();
        $response = $this->make_request($base_url . '/api/tags', [], [], 'GET');

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Não foi possível conectar ao Ollama em %s. Verifique se está rodando.', 'oraculo_tainacan'),
                    $base_url
                ),
                'details' => [
                    'error' => $response->get_error_message(),
                ],
            ];
        }

        $models = $response['models'] ?? [];

        return [
            'success' => true,
            'message' => __('Conexão estabelecida com sucesso!', 'oraculo_tainacan'),
            'details' => [
                'url' => $base_url,
                'models_installed' => count($models),
                'model_names' => array_column($models, 'name'),
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generate_embedding(string $text, ?string $model = null) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Provedor Ollama não configurado.', 'oraculo_tainacan'));
        }

        $text = $this->validate_input($text);

        if (empty($text)) {
            return new WP_Error('empty_input', __('Texto não pode estar vazio.', 'oraculo_tainacan'));
        }

        $model = $model ?? $this->get_config('embedding_model', 'nomic-embed-text');
        $base_url = $this->get_base_url();

        $response = $this->make_request_with_retry($base_url . '/api/embeddings', [
            'model' => $model,
            'prompt' => $text,
        ]);

        if (is_wp_error($response)) {
            // Tentar baixar o modelo se não existir
            if (strpos($response->get_error_message(), 'not found') !== false) {
                $this->pull_model($model);
                // Tentar novamente
                $response = $this->make_request($base_url . '/api/embeddings', [
                    'model' => $model,
                    'prompt' => $text,
                ]);
            }

            if (is_wp_error($response)) {
                return $response;
            }
        }

        if (!isset($response['embedding'])) {
            return new WP_Error('invalid_response', __('Resposta inválida da API.', 'oraculo_tainacan'));
        }

        return [
            'embedding' => $response['embedding'],
            'model' => $model,
            'usage' => [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generate_embeddings_batch(array $texts, ?string $model = null) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', __('Provedor Ollama não configurado.', 'oraculo_tainacan'));
        }

        $embeddings = [];
        $model = $model ?? $this->get_config('embedding_model', 'nomic-embed-text');

        foreach ($texts as $text) {
            $result = $this->generate_embedding($text, $model);

            if (is_wp_error($result)) {
                return $result;
            }

            $embeddings[] = $result['embedding'];
        }

        return [
            'embeddings' => $embeddings,
            'model' => $model,
            'usage' => [],
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
            return new WP_Error('not_configured', __('Provedor Ollama não configurado.', 'oraculo_tainacan'));
        }

        $options = $this->prepare_options($options);
        $model = $options['model'] ?? $this->get_config('model', 'llama3.2');
        $base_url = $this->get_base_url();

        $formatted_messages = $this->format_messages($messages, $system_prompt);

        $body = [
            'model' => $model,
            'messages' => $formatted_messages,
            'stream' => false,
            'options' => [
                'num_predict' => $options['max_tokens'],
                'temperature' => $options['temperature'],
            ],
        ];

        $response = $this->make_request_with_retry(
            $base_url . '/api/chat',
            $body
        );

        if (is_wp_error($response)) {
            // Tentar baixar o modelo se não existir
            if (strpos($response->get_error_message(), 'not found') !== false) {
                return new WP_Error(
                    'model_not_found',
                    sprintf(
                        __('Modelo "%s" não encontrado. Execute "ollama pull %s" para instalá-lo.', 'oraculo_tainacan'),
                        $model,
                        $model
                    )
                );
            }
            return $response;
        }

        if (!isset($response['message']['content'])) {
            return new WP_Error('invalid_response', __('Resposta inválida da API.', 'oraculo_tainacan'));
        }

        $usage = [
            'prompt_tokens' => $response['prompt_eval_count'] ?? 0,
            'completion_tokens' => $response['eval_count'] ?? 0,
            'total_tokens' => ($response['prompt_eval_count'] ?? 0) + ($response['eval_count'] ?? 0),
        ];

        return [
            'response' => $response['message']['content'],
            'model' => $model,
            'usage' => $usage,
            'cost' => 0, // Gratuito localmente
            'finish_reason' => $response['done_reason'] ?? 'stop',
            'eval_duration_ms' => isset($response['eval_duration']) ? $response['eval_duration'] / 1000000 : null,
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
        $model = $options['model'] ?? $this->get_config('model', 'llama3.2');
        $base_url = $this->get_base_url();

        $messages = $this->format_messages([['role' => 'user', 'content' => $prompt]], $system_prompt);

        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'options' => [
                'num_predict' => $options['max_tokens'],
                'temperature' => $options['temperature'],
            ],
        ];

        // Usar cURL para streaming
        $ch = curl_init($base_url . '/api/chat');

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => wp_json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($callback) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;

                    $decoded = json_decode($line, true);
                    if ($decoded && isset($decoded['message']['content'])) {
                        $callback($decoded['message']['content'], $decoded['done'] ?? false);
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
        // Ollama é gratuito
        return [
            'input' => 0,
            'output' => 0,
            'unit' => 'gratuito',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get_model_limit(string $model): int {
        return self::COMMON_MODELS[$model]['context'] ?? 4096;
    }

    /**
     * Obtém URL base do Ollama
     *
     * @return string
     */
    private function get_base_url(): string {
        return rtrim($this->get_config('base_url', 'http://localhost:11434'), '/');
    }

    /**
     * Baixa um modelo
     *
     * @param string $model
     * @return bool
     */
    public function pull_model(string $model): bool {
        $base_url = $this->get_base_url();

        $response = $this->make_request(
            $base_url . '/api/pull',
            ['name' => $model],
            [],
            'POST'
        );

        return !is_wp_error($response);
    }

    /**
     * Lista modelos instalados
     *
     * @return array
     */
    public function list_models(): array {
        return $this->get_installed_models();
    }
}
