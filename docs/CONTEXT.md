# Oráculo Tainacan — Contexto do Projeto

> Snapshot da versão 2.2.0 (integração do refactor UI-patterns com a aba de busca IA do tema)

Plugin WordPress que adiciona busca semântica (RAG) e chat com IA sobre acervos do Tainacan.
Arquitetura orientada a serviços com múltiplos provedores de IA intercambiáveis.

> Para o detalhamento dos pontos de acoplamento com o Tainacan, o contrato das rotas REST
> e os fluxos de indexação e busca, veja [INTEGRACAO-TAINACAN.md](INTEGRACAO-TAINACAN.md).

## Metadados

| Campo | Valor |
|---|---|
| Versão declarada (`oraculo-tainacan.php` e `readme.txt`) | **2.2.0** |
| PHP mínimo | **8.0** (`declare(strict_types=1)` em todos os arquivos) |
| WordPress mínimo | 6.0 |
| Text domain | `oraculo-tainacan` |
| Namespace raiz | `Oraculo_Tainacan\` |
| Total de linhas (PHP + JS + CSS, sem `assets/vendor/`) | ~19.300 |

## Estrutura de diretórios

```
oraculo-tainacan.php         Bootstrap singleton + hooks + DDL + AJAX admin
readme.txt                   Descrição WP.org
composer.json / phpcs.xml.dist   Tooling de dev (WPCS estrito, não vai para o pacote)
src/
├── helpers.php              Funções utilitárias globais
├── AI/
│   ├── AIProviderInterface.php
│   ├── AbstractAIProvider.php
│   ├── AIProviderFactory.php
│   └── Providers/
│       ├── OpenAIProvider.php   (gpt-4o-mini default, text-embedding-ada-002)
│       ├── GeminiProvider.php
│       ├── ClaudeProvider.php   (opção claude_api_key)
│       ├── DeepSeekProvider.php
│       ├── GroqProvider.php
│       └── OllamaProvider.php   (local — nomic-embed-text)
├── API/RestController.php   19 rotas REST sob /oraculo/v1
├── Admin/
│   ├── OraculoPage.php      Página admin via \Tainacan\Pages (único caminho)
│   └── SettingsSanitizer.php   Sanitização única das opções (admin + REST)
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
├── Frontend/ThemeIntegration.php  Aba "Busca com IA" nas listagens do Tainacan
├── Indexing/IndexingManager.php   Indexação síncrona + cron em lote
├── Search/SearchEngine.php        RAG (retrieval + generation)
└── Vector/VectorStore.php         Upsert/similaridade sobre MySQL
templates/
├── chat-widget.php
├── search-widget.php
└── admin/ (dashboard, analytics, indexing, settings, debug)
assets/
├── css/ (admin-page, chat-widget, frontend, theme-integration)
├── js/  (admin-page, admin/*.js por aba, chat-widget, chat-embedded,
│         frontend, search-page, theme-integration)
└── vendor/chart.umd.min.js   Chart.js 4.4.1 embarcado (sem CDN externo)
```

## Ponto de entrada

`oraculo-tainacan.php` — classe final `Oraculo_Tainacan` como **singleton**:

- Autoloader próprio PSR-4 (prefixo `Oraculo_Tainacan\` → `src/`).
- `plugins_loaded` prioridade 20 → `init_tainacan_page()` registra a página via API de páginas do Tainacan (`\Tainacan\Pages`).
- `plugins_loaded` prioridade 25 → `init()` instancia os serviços em `$this->services`:
  `api`, `search`, `chat`, `indexing`, `analytics`, `theme_integration`.

Não há mais página admin standalone: `Admin\OraculoPage` é o único caminho, e os assets
do admin são enfileirados por ela (`admin-page.css`, `admin-page.js` e o JS da aba ativa
em `assets/js/admin/<aba>.js`).

## Tabelas do banco

Criadas em `create_tables()` na ativação (prefixo `{wp_prefix}oraculo_`):

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

Retenção: o cron diário `oraculo_cleanup_old_data` remove logs de busca (90 dias),
conversas/mensagens inativas (30 dias) e memória expirada. Ambos os prazos são
filtráveis (`oraculo_tainacan_retention_logs_days`, `oraculo_tainacan_retention_conversations_days`).

## Superfícies de API

**REST** (namespace `oraculo/v1`, 19 rotas em `src/API/RestController.php`) — é a única
superfície pública. Endpoints e suas proteções:

| Grupo | Rotas | Proteção |
|---|---|---|
| Público | `/search`, `/chat`, `/feedback` | gate `enable_<feature>` + nonce `wp_rest` + rate-limit por IP (10/20/30 por min) + teto global de 60 chamadas de IA/min |
| Leitura ligada à busca | `/collections`, `/suggestions` | `enable_search` |
| Usuário logado | `/conversations`, `/conversations/{session}/messages` | `is_user_logged_in()` + checagem de posse |
| Mutação de conversa | `/conversations/{session}/end` | nonce `wp_rest` + posse |
| Admin | indexação, analytics, vetores, provedores, settings, health | `manage_options` |

Caps de entrada no schema: `query` ≤ 500 caracteres, `message` ≤ 2000, `collections` ≤ 20 itens.
Filtros para ajustar limites: `oraculo_tainacan_rest_rate_limit`, `oraculo_tainacan_rest_global_rate_limit`.

**AJAX** — apenas `wp_ajax_` (admin autenticado), sem handlers `nopriv`:
`oraculo_index_collection`, `oraculo_get_indexing_status`, `oraculo_test_connection`,
`oraculo_clear_vectors`, `oraculo_clear_all_vectors`, `oraculo_optimize_db`,
`oraculo_save_indexing_settings`, `oraculo_save_settings`, `oraculo_clear_cache`.

**Shortcodes**: `[oraculo_search]`, `[oraculo_chat]` — enfileiram seus assets no render.

**WP-CLI**: `wp oraculo <index|reindex|stats|clear-cache|...>` (`src/CLI/Commands.php`).

## Integração com o tema Tainacan

`src/Frontend/ThemeIntegration.php` injeta uma aba "Busca com IA" ao lado do campo de busca
padrão das listagens de itens do Tainacan. A listagem é uma aplicação Vue.js montada pelo
próprio Tainacan (`tainacan_the_faceted_search`), então a injeção acontece no client-side
(`assets/js/theme-integration.js`) sobre o DOM renderizado, com `MutationObserver` para
reinjetar após re-renders do Vue preservando o estado.

Contextos cobertos: arquivo de itens de coleção, arquivo do repositório, arquivo de termo de
taxonomia Tainacan e páginas com o bloco/shortcode de busca facetada.

Deep link: `?oraculo_q=pergunta` abre a aba de IA e executa a busca automaticamente.

Configurável na aba **Tema Tainacan** das configurações (`theme_integration`):
`enabled`, `tab_label`, `placeholder`, `scope` (`collection`|`all`), `show_suggestions`.

## Configuração

Uma única opção `oraculo_tainacan_options` (array) — defaults em `set_default_options()`:
- Provedor ativo (`ai_provider`), API keys e modelos por provedor.
- `max_tokens`, `temperature`, `similarity_threshold`, `max_results`, `batch_size`, `request_timeout`, `cache_duration`.
- Flags de feature: `enable_chat`, `enable_search`, `enable_analytics`, `enable_feedback`, `debug_mode`.
- Prompts default (system/search/chat) + welcome message + perguntas sugeridas.
- `appearance` (cores, posição do chat, exibição de fontes/similaridade).
- `theme_integration` (aba de busca IA no tema).

**Sanitização**: ponto único em `Admin\SettingsSanitizer::sanitize()`, usado tanto pelo POST
do admin (`oraculo_save_settings`) quanto pelo endpoint REST `/settings`. Allowlist por chave;
API keys com placeholder `••••••••` preservam o valor salvo; chaves ausentes na entrada caem
para o valor já armazenado (e só então para o padrão), de modo que um formulário parcial não
apague o restante. Checkboxes são a exceção deliberada — ausência significa desmarcado.

## Integração com Tainacan (admin)

Página registrada apenas se `class_exists('\Tainacan\Pages')` e o trait
`\Tainacan\Traits\Singleton_Instance` existir — degrada silenciosamente sem o Tainacan.
Aparece sob o menu "Mais" do Tainacan, exigindo `manage_options`.

## Como rodar localmente

Plugin instalado sob XAMPP em `wp-content/plugins/oraculo_tainacan`.
Requer Tainacan ativo para a página admin integrada e para a aba de busca no tema;
sem Tainacan, o plugin carrega mas expõe apenas REST e shortcodes.

Ferramentas de dev (não empacotadas):

```
composer install          # instala WPCS + PHPCompatibilityWP
composer run lint         # phpcs conforme phpcs.xml.dist
```
