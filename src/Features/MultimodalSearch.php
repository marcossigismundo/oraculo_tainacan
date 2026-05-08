<?php
/**
 * Busca Multimodal
 *
 * Permite busca por imagens, além de texto.
 * Usa descrição de imagens via IA para indexação.
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\Features;

use Oraculo_Tainacan\AI\AIProviderFactory;
use WP_Error;

/**
 * Gerencia busca e indexação multimodal
 */
class MultimodalSearch {

    /**
     * @var AIProviderFactory
     */
    private AIProviderFactory $factory;

    /**
     * Modelos com suporte a visão
     */
    private const VISION_MODELS = [
        'gpt-4o',
        'gpt-4o-mini',
        'gpt-4-turbo',
        'gemini-1.5-pro',
        'gemini-1.5-flash',
        'claude-3-5-sonnet-latest',
        'claude-opus-4-5-20251101',
    ];

    /**
     * Construtor
     */
    public function __construct() {
        $this->factory = new AIProviderFactory();
    }

    /**
     * Gera descrição de uma imagem
     *
     * @param string $image_path Caminho ou URL da imagem
     * @param string|null $context Contexto adicional
     * @return string|WP_Error
     */
    public function describe_image(string $image_path, ?string $context = null) {
        $provider = $this->factory->create();

        if (is_wp_error($provider)) {
            return $provider;
        }

        // Verificar se o modelo suporta visão
        $model = get_option('oraculo_model', 'gpt-4o-mini');
        if (!$this->supports_vision($model)) {
            return new WP_Error(
                'vision_not_supported',
                __('O modelo atual não suporta análise de imagens.', 'oraculo_tainacan')
            );
        }

        // Converter imagem para base64 se for arquivo local
        $image_data = $this->prepare_image($image_path);
        if (is_wp_error($image_data)) {
            return $image_data;
        }

        // Prompt para descrição
        $prompt = $this->build_description_prompt($context);

        // Chamada à API com imagem
        $result = $this->call_vision_api($provider, $prompt, $image_data, $model);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result['response'];
    }

    /**
     * Indexa uma imagem com descrição gerada por IA
     *
     * @param int $attachment_id ID do anexo WordPress
     * @param array $metadata Metadados adicionais
     * @return array|WP_Error
     */
    public function index_image(int $attachment_id, array $metadata = []) {
        $file_path = get_attached_file($attachment_id);

        if (!$file_path || !file_exists($file_path)) {
            return new WP_Error('file_not_found', __('Arquivo de imagem não encontrado.', 'oraculo_tainacan'));
        }

        // Verificar se é imagem
        $mime_type = get_post_mime_type($attachment_id);
        if (!str_starts_with($mime_type, 'image/')) {
            return new WP_Error('not_image', __('O arquivo não é uma imagem.', 'oraculo_tainacan'));
        }

        // Gerar descrição
        $context = $metadata['title'] ?? get_the_title($attachment_id);
        $description = $this->describe_image($file_path, $context);

        if (is_wp_error($description)) {
            return $description;
        }

        // Combinar com metadados existentes
        $content = $this->build_content_for_indexing($description, $metadata);

        return [
            'description' => $description,
            'content' => $content,
            'tokens' => \Oraculo_Tainacan\estimate_tokens($content),
        ];
    }

    /**
     * Busca por imagem similar
     *
     * @param string $image_path Imagem para buscar similares
     * @param array $options Opções de busca
     * @return array|WP_Error
     */
    public function search_by_image(string $image_path, array $options = []) {
        // Primeiro, descrever a imagem
        $description = $this->describe_image($image_path, 'Descreva esta imagem para buscar itens similares');

        if (is_wp_error($description)) {
            return $description;
        }

        // Usar a descrição para busca semântica
        $search = new \Oraculo_Tainacan\Search\SearchEngine();

        return $search->search($description, $options['collections'] ?? [], [
            'max_results' => $options['limit'] ?? 10,
        ]);
    }

    /**
     * Extrai texto visível de uma imagem (OCR + IA)
     *
     * @param string $image_path
     * @return string|WP_Error
     */
    public function extract_visible_text(string $image_path) {
        $provider = $this->factory->create();

        if (is_wp_error($provider)) {
            return $provider;
        }

        $model = get_option('oraculo_model', 'gpt-4o-mini');
        if (!$this->supports_vision($model)) {
            // Fallback para OCR tradicional
            $processor = new DocumentProcessor();
            return $processor->extract_text($image_path);
        }

        $image_data = $this->prepare_image($image_path);
        if (is_wp_error($image_data)) {
            return $image_data;
        }

        $prompt = "Transcreva todo o texto visível nesta imagem. Se houver texto manuscrito, tente decifrá-lo da melhor forma possível. Mantenha a formatação original quando relevante. Se não houver texto, responda apenas: [Sem texto visível]";

        $result = $this->call_vision_api($provider, $prompt, $image_data, $model);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result['response'];
    }

    /**
     * Verifica se um modelo suporta visão
     *
     * @param string $model
     * @return bool
     */
    public function supports_vision(string $model): bool {
        foreach (self::VISION_MODELS as $vision_model) {
            if (str_contains($model, $vision_model)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Prepara imagem para envio à API
     *
     * @param string $image_path
     * @return array|WP_Error
     */
    private function prepare_image(string $image_path) {
        // Se for URL
        if (filter_var($image_path, FILTER_VALIDATE_URL)) {
            return [
                'type' => 'url',
                'url' => $image_path,
            ];
        }

        // Se for arquivo local
        if (!file_exists($image_path)) {
            return new WP_Error('file_not_found', __('Arquivo de imagem não encontrado.', 'oraculo_tainacan'));
        }

        // Verificar tamanho
        $size = filesize($image_path);
        if ($size > 20 * 1024 * 1024) { // 20MB
            return new WP_Error('file_too_large', __('Arquivo muito grande. Máximo 20MB.', 'oraculo_tainacan'));
        }

        // Converter para base64
        $content = file_get_contents($image_path);
        $mime_type = mime_content_type($image_path);
        $base64 = base64_encode($content);

        return [
            'type' => 'base64',
            'media_type' => $mime_type,
            'data' => $base64,
        ];
    }

    /**
     * Constrói prompt para descrição de imagem
     *
     * @param string|null $context
     * @return string
     */
    private function build_description_prompt(?string $context): string {
        $prompt = "Analise esta imagem detalhadamente e forneça uma descrição completa que inclua:\n";
        $prompt .= "1. O que está representado na imagem (objetos, pessoas, lugares)\n";
        $prompt .= "2. Características visuais relevantes (cores, estilos, época aparente)\n";
        $prompt .= "3. Qualquer texto visível\n";
        $prompt .= "4. Contexto histórico ou cultural se identificável\n";
        $prompt .= "5. Estado de conservação se for um documento ou artefato\n\n";
        $prompt .= "Forneça uma descrição em português brasileiro, objetiva e informativa.";

        if ($context) {
            $prompt .= "\n\nContexto adicional: " . $context;
        }

        return $prompt;
    }

    /**
     * Chama API com suporte a visão
     *
     * @param object $provider
     * @param string $prompt
     * @param array $image_data
     * @param string $model
     * @return array|WP_Error
     */
    private function call_vision_api($provider, string $prompt, array $image_data, string $model) {
        // Para OpenAI
        if (str_starts_with($model, 'gpt')) {
            return $this->call_openai_vision($prompt, $image_data, $model);
        }

        // Para Gemini
        if (str_starts_with($model, 'gemini')) {
            return $this->call_gemini_vision($prompt, $image_data, $model);
        }

        // Para Claude
        if (str_starts_with($model, 'claude')) {
            return $this->call_claude_vision($prompt, $image_data, $model);
        }

        return new WP_Error('unsupported_model', __('Modelo não suportado para visão.', 'oraculo_tainacan'));
    }

    /**
     * Chama API de visão da OpenAI
     */
    private function call_openai_vision(string $prompt, array $image_data, string $model) {
        $api_key = get_option('oraculo_openai_api_key');

        if ($image_data['type'] === 'url') {
            $image_content = [
                'type' => 'image_url',
                'image_url' => ['url' => $image_data['url']],
            ];
        } else {
            $image_content = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:' . $image_data['media_type'] . ';base64,' . $image_data['data'],
                ],
            ];
        }

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            $image_content,
                        ],
                    ],
                ],
                'max_tokens' => 1000,
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }

        return [
            'response' => $body['choices'][0]['message']['content'] ?? '',
            'usage' => $body['usage'] ?? [],
        ];
    }

    /**
     * Chama API de visão do Gemini
     */
    private function call_gemini_vision(string $prompt, array $image_data, string $model) {
        $api_key = get_option('oraculo_gemini_api_key');

        $parts = [
            ['text' => $prompt],
        ];

        if ($image_data['type'] === 'base64') {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $image_data['media_type'],
                    'data' => $image_data['data'],
                ],
            ];
        }

        $response = wp_remote_post(
            "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$api_key}",
            [
                'timeout' => 60,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode([
                    'contents' => [['parts' => $parts]],
                ]),
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }

        return [
            'response' => $body['candidates'][0]['content']['parts'][0]['text'] ?? '',
        ];
    }

    /**
     * Chama API de visão do Claude
     */
    private function call_claude_vision(string $prompt, array $image_data, string $model) {
        $api_key = get_option('oraculo_claude_api_key');

        if ($image_data['type'] === 'url') {
            // Claude precisa do base64
            $content = file_get_contents($image_data['url']);
            $image_source = [
                'type' => 'base64',
                'media_type' => 'image/jpeg',
                'data' => base64_encode($content),
            ];
        } else {
            $image_source = [
                'type' => 'base64',
                'media_type' => $image_data['media_type'],
                'data' => $image_data['data'],
            ];
        }

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'max_tokens' => 1000,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'image', 'source' => $image_source],
                            ['type' => 'text', 'text' => $prompt],
                        ],
                    ],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message']);
        }

        return [
            'response' => $body['content'][0]['text'] ?? '',
        ];
    }

    /**
     * Constrói conteúdo para indexação
     *
     * @param string $description
     * @param array $metadata
     * @return string
     */
    private function build_content_for_indexing(string $description, array $metadata): string {
        $parts = [];

        if (!empty($metadata['title'])) {
            $parts[] = "Título: " . $metadata['title'];
        }

        $parts[] = "Descrição da imagem: " . $description;

        if (!empty($metadata['alt_text'])) {
            $parts[] = "Texto alternativo: " . $metadata['alt_text'];
        }

        if (!empty($metadata['caption'])) {
            $parts[] = "Legenda: " . $metadata['caption'];
        }

        return implode("\n\n", $parts);
    }
}
