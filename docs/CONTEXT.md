# Oráculo Tainacan — Contexto do Projeto

> Snapshot em 2026-04-19 (branch `main`, commit `9a78501`)

Plugin WordPress que adiciona busca semântica (RAG) e chat com IA sobre acervos do Tainacan.
Arquitetura orientada a serviços com múltiplos provedores de IA intercambiáveis.

## Metadados

| Campo | Valor |
|---|---|
| Versão declarada (`oraculo-tainacan.php` e `readme.txt`) | **2.0.0** |
| PHP mínimo | **8.0** |
| WordPress mínimo | 6.0 |
| Text domain | `oraculo-tainacan` |
| Namespace raiz | `Oraculo_Tainacan\` |
| Total de linhas (PHP + JS + CSS) | ~17.500 |

## Estrutura de diretórios

```
oraculo-tainacan.php         Bootstrap singleton + hooks + DDL + AJAX
readme.txt                   Descrição WP.org (desatualizado)
src/
├── helpers.php              Funções utilitárias globais
├── AI/
│   ├── AIProviderInterface.php
│   ├── AbstractAIProvider.php
│   ├── AIProviderFactory.php
│   └── Providers/
│       ├── OpenAIProvider.php   (gpt-4o-mini default, text-embedding-ada-002)
│       ├── GeminiProvider.php   (gemini-1.5-pro)
│       ├── ClaudeProvider.php   (Anthropic)
│       ├── DeepSeekProvider.php (deepseek-chat)
│       ├── GroqProvider.php     (llama-3.1-70b / mixtral / gemma2)
│       └── OllamaProvider.php   (local — nomic-embed-text)
├── API/RestController.php   20 rotas REST sob /oraculo/v1
├── Admin/
│   ├── AdminPage.php        Página admin standalone (legado)
│   └── OraculoPage.php      Integração via \Tainacan\Pages (preferencial)
├── Analytics/AnalyticsManager.php
├── CLI/Commands.php         WP-CLI (`wp oraculo ...`)
├── Chat/ChatEngine.php
├── Features/
│   ├── ConversationMemory.php  Summaries + fatos extraídos
│   ├── DocumentProcessor.php
│   ├── ExportManager.php
│   ├── MultimodalSearch.php
│   ├── SmartSuggestions.php
│   └── WebhooksManager.php
├── Frontend/ThemeIntegration.php Aba "Busca com IA" nas listagens do Tainacan (v2.1.0)
├── Indexing/IndexingManager.php  Indexação síncrona + cron em lote
├── Search/SearchEngine.php       RAG (retrieval + generation)
└── Vector/VectorStore.php        Upsert/similaridade sobre MySQL
templates/
├── chat-widget.php
├── search-widget.php
└── admin/
    ├── dashboard.php
    ├── analytics.php
    ├── indexing.php
    ├── settings.php
    └── debug.php
assets/
├── css/ (admin-page, admin, chat-widget, frontend, theme-integration)
└── js/  (admin-page, admin, chat-widget, frontend, theme-integration)
```

## Integração com o tema Tainacan (v2.1.0)

[src/Frontend/ThemeIntegration.php](src/Frontend/ThemeIntegration.php) injeta uma aba
"Busca com IA" junto ao campo de busca padrão das listagens de itens do Tainacan.

- A listagem é uma app Vue.js montada pelo Tainacan em `[data-module="faceted-search"]`
  (`tainacan_the_faceted_search()`); não há hook PHP dentro da barra de busca, então a
  injeção acontece no client-side ([theme-integration.js](assets/js/theme-integration.js))
  via `MutationObserver` — os nós injetados sobrevivem a re-renders do Vue e nunca
  reposicionam DOM gerenciado pelo Vue.
- Detecção server-side de página de listagem: arquivo de coleção
  (`Theme_Helper::is_post_type_a_collection`), arquivo de repositório
  (`tainacan_repository_archive`), termo de taxonomia Tainacan e bloco/shortcode de
  busca facetada. Assets só são enfileirados nesses contextos.
- Visual segue o tema ativo pelas variáveis CSS `--tainacan-*` com fallback para
  `--oraculo-*` ([theme-integration.css](assets/css/theme-integration.css)).
- Configurável na aba "Tema Tainacan" de Configurações
  (opção `theme_integration`: enabled, tab_label, placeholder, scope, show_suggestions).
- Deep link: `?oraculo_q=pergunta` abre a aba de IA e executa a busca.
- Evento público: `oraculo-ai-search-done` (CustomEvent, bubbles) após cada busca.

## Ponto de entrada

[oraculo-tainacan.php](oraculo-tainacan.php) — classe final `Oraculo_Tainacan` como **singleton**:

- Autoloader próprio PSR-4 (prefixo `Oraculo_Tainacan\` → `src/`).
- `plugins_loaded` prioridade 20 → [init_tainacan_page()](oraculo-tainacan.php#L553) registra a página via API de páginas do Tainacan (`\Tainacan\Pages`).
- `plugins_loaded` prioridade 25 → [init()](oraculo-tainacan.php#L564) instancia os 5 serviços core em `$this->services`:
  `api`, `search`, `chat`, `indexing`, `analytics`.

A página admin moderna vive em [src/Admin/OraculoPage.php](src/Admin/OraculoPage.php) (namespace `\Tainacan`, estende `\Tainacan\Pages`).
[src/Admin/AdminPage.php](src/Admin/AdminPage.php) parece ser o caminho admin antigo/fallback — ainda presente mas não instanciado em `init()`.

## Tabelas do banco

Criadas em [create_tables()](oraculo-tainacan.php#L236) na ativação (prefixo `{wp_prefix}oraculo_`):

| Tabela | Função |
|---|---|
| `oraculo_vectors` | Embeddings por item (unique `item_id + collection_id`) |
| `oraculo_conversations` | Sessões de chat |
| `oraculo_messages` | Mensagens por conversa |
| `oraculo_search_logs` | Logs de busca/analytics |
| `oraculo_indexing_jobs` | Estado de indexação por coleção |
| `oraculo_collection_prompts` | Prompts customizados por coleção |
| `oraculo_memory` | Memória de conversa (summary/context/preference) |
| `oraculo_facts` | Fatos extraídos por sessão |
| `oraculo_webhooks` | Webhooks registrados |

## Superfícies de API

**REST** (namespace `oraculo/v1`, 20 rotas em [RestController](src/API/RestController.php)):
`/search`, `/chat`, `/conversations`, `/conversations/{session}/messages`, `/conversations/{session}/end`,
`/feedback`, `/indexing/start|status|cancel`, `/analytics`, `/analytics/timeline`, `/analytics/export`,
`/collections`, `/vectors/stats`, `/providers`, `/providers/test`, `/settings`, `/suggestions`, `/health`.

**AJAX** (14 actions registradas em [init_hooks()](oraculo-tainacan.php#L162-L176)):
`oraculo_search`, `oraculo_chat`, `oraculo_index_collection`, `oraculo_get_indexing_status`,
`oraculo_feedback`, `oraculo_test_connection`, `oraculo_clear_vectors`, `oraculo_clear_all_vectors`,
`oraculo_optimize_db`, `oraculo_save_indexing_settings`, `oraculo_save_settings`, `oraculo_clear_cache`.
`search` e `chat` também são expostos a `nopriv_`.

**Shortcodes**: `[oraculo_search]`, `[oraculo_chat]`.

**WP-CLI**: `wp oraculo <index|reindex|stats|clear-cache|...>` ([src/CLI/Commands.php](src/CLI/Commands.php)).

## Configuração

Uma única opção `oraculo_tainacan_options` (array) — defaults em [set_default_options()](oraculo-tainacan.php#L433):
- Provedor ativo (`ai_provider`), API keys e modelos por provedor.
- `max_tokens`, `temperature`, `similarity_threshold`, `max_results`, `batch_size`, `request_timeout`.
- Flags de feature: `enable_chat`, `enable_search`, `enable_analytics`, `enable_feedback`, `debug_mode`.
- Prompts default (system/search/chat) + welcome message + perguntas sugeridas.
- `appearance` (cores, posição do chat).
- Sanitização preserva API keys quando o POST chega com placeholder `••••••••` ([sanitize_options()](oraculo-tainacan.php#L586)).

## Integração com Tainacan

Integração principal via `\Tainacan\Pages` (API de páginas do Tainacan 1.0+).
Página é registrada apenas se `class_exists('\Tainacan\Pages')` — degrada silenciosamente se o Tainacan não estiver presente.

## Pendências / divergências visíveis

1. **Dois caminhos admin**: `AdminPage.php` não é instanciado em `init()` mas permanece no repositório — candidato a remoção ou a fallback documentado.
2. **Provider Anthropic**: `set_default_options()` não inclui defaults para `anthropic_api_key` / modelo do Claude, mas `sanitize_options()` já o trata na lista de `$api_keys`.
3. **Histórico git ruidoso**: últimos commits são todos "Add files via upload" — sem mensagens semânticas, dificulta rastrear mudanças.

## Como rodar localmente

Plugin instalado em `c:\xampp-tainacan\htdocs\wordpress\wp-content\plugins\oraculo_tainacan` sob XAMPP.
Requer Tainacan ativo para a página admin integrada; sem Tainacan, o plugin carrega mas não expõe UI admin nova (apenas REST/AJAX/shortcodes).
