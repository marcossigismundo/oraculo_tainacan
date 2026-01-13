<?php
/**
 * Memória de Conversação Avançada
 *
 * Sistema de memória de longo prazo para conversas,
 * incluindo resumos e extração de fatos importantes.
 *
 * @package Oraculo_Tainacan
 */

namespace Oraculo_Tainacan\Features;

use Oraculo_Tainacan\AI\AIProviderFactory;

/**
 * Gerencia memória persistente de conversações
 */
class ConversationMemory {

    /**
     * @var string Tabela de memórias
     */
    private string $table_name;

    /**
     * @var string Tabela de fatos
     */
    private string $facts_table;

    /**
     * @var AIProviderFactory
     */
    private AIProviderFactory $factory;

    /**
     * Número máximo de mensagens antes de resumir
     * @var int
     */
    private int $max_messages = 20;

    /**
     * Construtor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'oraculo_memory';
        $this->facts_table = $wpdb->prefix . 'oraculo_facts';
        $this->factory = new AIProviderFactory();
    }

    /**
     * Cria tabelas de memória
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Tabela de memórias/resumos
        $sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}oraculo_memory (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            memory_type ENUM('summary', 'context', 'preference') NOT NULL,
            content TEXT NOT NULL,
            message_range VARCHAR(50) DEFAULT NULL,
            importance_score FLOAT DEFAULT 0.5,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY user_id (user_id),
            KEY memory_type (memory_type)
        ) {$charset_collate};";

        // Tabela de fatos extraídos
        $sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}oraculo_facts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            fact_type VARCHAR(50) NOT NULL,
            fact_key VARCHAR(100) NOT NULL,
            fact_value TEXT NOT NULL,
            confidence FLOAT DEFAULT 1.0,
            source_message_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_fact (session_id, fact_key),
            KEY user_id (user_id),
            KEY fact_type (fact_type)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql1);
        dbDelta($sql2);
    }

    /**
     * Adiciona memória de uma conversa
     *
     * @param string $session_id
     * @param array $messages Mensagens da conversa
     * @return bool
     */
    public function process_conversation(string $session_id, array $messages): bool {
        if (count($messages) < 3) {
            return false;
        }

        // Verificar se precisa resumir
        if (count($messages) >= $this->max_messages) {
            $this->create_summary($session_id, $messages);
        }

        // Extrair fatos importantes
        $this->extract_facts($session_id, $messages);

        return true;
    }

    /**
     * Cria resumo da conversa
     *
     * @param string $session_id
     * @param array $messages
     * @return string|null
     */
    public function create_summary(string $session_id, array $messages): ?string {
        global $wpdb;

        $provider = $this->factory->create();
        if (is_wp_error($provider)) {
            return null;
        }

        // Formatar mensagens para resumo
        $conversation_text = $this->format_messages_for_summary($messages);

        $prompt = "Analise a seguinte conversa e crie um resumo conciso dos pontos principais discutidos. ";
        $prompt .= "Inclua: tópicos abordados, perguntas feitas, informações fornecidas e preferências demonstradas pelo usuário.\n\n";
        $prompt .= "Conversa:\n" . $conversation_text . "\n\n";
        $prompt .= "Resumo:";

        $result = $provider->generate_response($prompt, '', ['max_tokens' => 500]);

        if (is_wp_error($result)) {
            return null;
        }

        $summary = $result['response'];

        // Salvar resumo
        $wpdb->insert(
            $this->table_name,
            [
                'session_id' => $session_id,
                'user_id' => get_current_user_id(),
                'memory_type' => 'summary',
                'content' => $summary,
                'message_range' => '1-' . count($messages),
                'importance_score' => 0.8,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%f', '%s']
        );

        return $summary;
    }

    /**
     * Extrai fatos importantes da conversa
     *
     * @param string $session_id
     * @param array $messages
     * @return array
     */
    public function extract_facts(string $session_id, array $messages): array {
        global $wpdb;

        $provider = $this->factory->create();
        if (is_wp_error($provider)) {
            return [];
        }

        // Pegar últimas mensagens
        $recent = array_slice($messages, -6);
        $text = $this->format_messages_for_summary($recent);

        $prompt = <<<EOT
Analise a conversa abaixo e extraia fatos importantes em formato JSON.
Tipos de fatos a extrair:
- preference: preferências do usuário (ex: "prefere documentos em PDF")
- interest: áreas de interesse (ex: "interessado em história do Brasil")
- context: contexto relevante (ex: "está pesquisando para TCC")
- feedback: feedback sobre o sistema (ex: "achou a resposta útil")

Responda APENAS com um array JSON válido no formato:
[{"type": "preference", "key": "formato_preferido", "value": "PDF", "confidence": 0.9}]

Se não houver fatos relevantes, responda: []

Conversa:
{$text}
EOT;

        $result = $provider->generate_response($prompt, '', ['max_tokens' => 500]);

        if (is_wp_error($result)) {
            return [];
        }

        // Extrair JSON da resposta
        $response = $result['response'];
        preg_match('/\[.*\]/s', $response, $matches);

        if (empty($matches[0])) {
            return [];
        }

        $facts = json_decode($matches[0], true);

        if (!is_array($facts)) {
            return [];
        }

        // Salvar fatos
        foreach ($facts as $fact) {
            if (empty($fact['key']) || empty($fact['value'])) {
                continue;
            }

            $wpdb->replace(
                $this->facts_table,
                [
                    'session_id' => $session_id,
                    'user_id' => get_current_user_id(),
                    'fact_type' => $fact['type'] ?? 'general',
                    'fact_key' => $fact['key'],
                    'fact_value' => $fact['value'],
                    'confidence' => $fact['confidence'] ?? 1.0,
                    'updated_at' => current_time('mysql'),
                ],
                ['%s', '%d', '%s', '%s', '%s', '%f', '%s']
            );
        }

        return $facts;
    }

    /**
     * Obtém contexto de memória para uma sessão
     *
     * @param string $session_id
     * @return array
     */
    public function get_memory_context(string $session_id): array {
        global $wpdb;

        // Obter resumos
        $summaries = $wpdb->get_col($wpdb->prepare(
            "SELECT content FROM {$this->table_name}
             WHERE session_id = %s AND memory_type = 'summary'
             ORDER BY created_at DESC
             LIMIT 3",
            $session_id
        ));

        // Obter fatos
        $facts = $wpdb->get_results($wpdb->prepare(
            "SELECT fact_type, fact_key, fact_value, confidence
             FROM {$this->facts_table}
             WHERE session_id = %s
             ORDER BY confidence DESC, updated_at DESC
             LIMIT 10",
            $session_id
        ), ARRAY_A);

        return [
            'summaries' => $summaries,
            'facts' => $facts,
        ];
    }

    /**
     * Formata contexto de memória como texto para o prompt
     *
     * @param string $session_id
     * @return string
     */
    public function format_memory_for_prompt(string $session_id): string {
        $context = $this->get_memory_context($session_id);

        if (empty($context['summaries']) && empty($context['facts'])) {
            return '';
        }

        $parts = [];

        if (!empty($context['summaries'])) {
            $parts[] = "Resumo da conversa anterior:\n" . $context['summaries'][0];
        }

        if (!empty($context['facts'])) {
            $facts_text = [];
            foreach ($context['facts'] as $fact) {
                $facts_text[] = "- {$fact['fact_key']}: {$fact['fact_value']}";
            }
            $parts[] = "Informações conhecidas sobre o usuário:\n" . implode("\n", $facts_text);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Obtém preferências do usuário
     *
     * @param string $session_id
     * @return array
     */
    public function get_user_preferences(string $session_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT fact_key, fact_value
             FROM {$this->facts_table}
             WHERE session_id = %s AND fact_type = 'preference'
             ORDER BY confidence DESC",
            $session_id
        ), ARRAY_A) ?: [];
    }

    /**
     * Obtém interesses do usuário
     *
     * @param string $session_id
     * @return array
     */
    public function get_user_interests(string $session_id): array {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare(
            "SELECT fact_value
             FROM {$this->facts_table}
             WHERE session_id = %s AND fact_type = 'interest'
             ORDER BY confidence DESC",
            $session_id
        )) ?: [];
    }

    /**
     * Limpa memórias antigas
     *
     * @param int $days_old
     * @return int
     */
    public function cleanup_old_memories(int $days_old = 30): int {
        global $wpdb;

        $deleted_memories = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name}
             WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        ));

        $deleted_facts = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->facts_table}
             WHERE updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old * 2
        ));

        return $deleted_memories + $deleted_facts;
    }

    /**
     * Formata mensagens para criação de resumo
     *
     * @param array $messages
     * @return string
     */
    private function format_messages_for_summary(array $messages): string {
        $lines = [];

        foreach ($messages as $message) {
            $role = $message['role'] === 'user' ? 'Usuário' : 'Assistente';
            $content = $message['content'];

            // Truncar mensagens muito longas
            if (strlen($content) > 500) {
                $content = substr($content, 0, 500) . '...';
            }

            $lines[] = "{$role}: {$content}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * Mescla memórias de sessão para usuário logado
     *
     * @param string $session_id
     * @param int $user_id
     */
    public function merge_session_to_user(string $session_id, int $user_id): void {
        global $wpdb;

        // Atualizar memórias
        $wpdb->update(
            $this->table_name,
            ['user_id' => $user_id],
            ['session_id' => $session_id],
            ['%d'],
            ['%s']
        );

        // Atualizar fatos
        $wpdb->update(
            $this->facts_table,
            ['user_id' => $user_id],
            ['session_id' => $session_id],
            ['%d'],
            ['%s']
        );
    }
}
