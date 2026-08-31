# Oráculo Tainacan — Contexto do Projeto

> Snapshot da versão 2.6.0 (indexação automática, CLIP como provedor, descoberta
> dinâmica de modelos, chaves criptografadas — mergeado na `main`)

Plugin WordPress que adiciona busca semântica (RAG) e chat com IA sobre acervos do Tainacan.
Arquitetura orientada a serviços com múltiplos provedores de IA intercambiáveis.

> Para o detalhamento dos pontos de acoplamento com o Tainacan, o contrato das rotas REST
> e os fluxos de indexação e busca, veja [INTEGRACAO-TAINACAN.md](INTEGRACAO-TAINACAN.md).
>
> Para os limites de escala da busca vetorial em MariaDB/MySQL (benchmark medido,
> pontos de ruptura e caminhos de escala), veja
> [ANALISE-LIMITE-VETORES.md](ANALISE-LIMITE-VETORES.md).

## Metadados

| Campo | Valor |
|---|---|
| Versão declarada (`oraculo-tainacan.php` e `readme.txt`) | **2.6.0** |
| PHP mínimo | **8.0** (`declare(strict_types=1)` em todos os arquivos) |
| WordPress mínimo | 6.0 |
| Text domain | `oraculo-tainacan` |
| Namespace raiz | `Oraculo_Tainacan\` |
| Repositório | `github.com/marcossigismundo/oraculo_tainacan`, branch `main` (feature/auto-indexing-clip mergeada) |
| Deploy de testes | `darkgreen-yak-751687.hostingersite.com/wp-content/plugins/oraculo-tainacan/` (pasta com **hífen**, diferente do diretório local `oraculo_tainacan`) |

## Estrutura de diretórios

```
oraculo-tainacan.php         Bootstrap singleton + hooks + DDL + AJAX admin
readme.txt                   Descrição WP.org
composer.json / phpcs.xml.dist   Tooling de dev (WPCS estrito, não vai para o pacote)
src/
├── helpers.php              Funções utilitárias globais (inclui encrypt_value/decrypt_value)
├── AI/
│   ├── AIProviderInterface.php   inclui list_remote_models() (descoberta dinâmica)
│   ├── AbstractAIProvider.php    get_api_key() descriptografa prefixo `enc:`; normalize_openai_style_models()
│   ├── AIProviderFactory.php
│   └── Providers/  (todos os 7 implementam list_remote_models())
│       ├── OpenAIProvider.php   (gpt-5-mini default, text-embedding-3-small; GPT-5.x + GPT-4o legado)
│       ├── GeminiProvider.php   (gemini-2.5-flash default; filtra por supportedGenerationMethods)
│       ├── ClaudeProvider.php   (claude-sonnet-5 default; opção claude_api_key)
│       ├── DeepSeekProvider.php (deepseek-chat + deepseek-reasoner/R1)
│       ├── GroqProvider.php     (Llama 4 Maverick/Scout + legado 3.x)
│       ├── OllamaProvider.php   (local — nomic-embed-text; lista via /api/tags)
│       └── ClipProvider.php     (fachada da AI API CLIP como card de provedor; sem chat)
├── API/RestController.php   19 rotas REST sob /oraculo/v1
├── Admin/
│   ├── OraculoPage.php      Página admin via \Tainacan\Pages (único caminho)
│   └── SettingsSanitizer.php   Sanitização única das opções (admin + REST); criptografa API keys
├── Analytics/AnalyticsManager.php
├── CLI/Commands.php         WP-CLI (`wp oraculo ...`)
├── Chat/ChatEngine.php      Persona BIA (filtro oraculo_tainacan_chat_persona) prefixada ao
│                            system prompt; contexto: 10 últimas msgs no prompt; retrieval
│                            de follow-up com query aumentada pelas perguntas anteriores
├── Features/
│   ├── ConversationMemory.php  Summaries + fatos extraídos
│   ├── DocumentProcessor.php
│   ├── ExportManager.php
│   ├── MultimodalSearch.php
│   ├── SmartSuggestions.php
│   └── WebhooksManager.php
├── Frontend/ThemeIntegration.php  Aba "Busca com IA" nas listagens do Tainacan
├── Indexing/
│   ├── IndexingManager.php     Indexação síncrona sob demanda (`index_item_batch`, reusado pela fila)
│   ├── AutoIndexer.php         Fila automática (post meta) + worker cron + reconciliação diária
│   └── ClipIndexer.php         Envio de imagens ao backend CLIP (best-effort, após o lote local)
├── Search/
│   ├── SearchEngine.php        RAG local (retrieval + generation) ou roteia pro backend CLIP
│   └── QueryParser.php         Extrai filtros temporais (século/década/ano) do texto da busca
└── Vector/
    ├── VectorStore.php         Upsert/similaridade sobre MySQL (backend local)
    └── ClipApiClient.php       Cliente HTTP da AI API do IBRAM (CLIP + pgvector)
templates/
├── chat-widget.php          Chat da BIA (shortcode) — identidade/estilo espelham o flutuante
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
  `api`, `search`, `chat`, `indexing`, `analytics`, `auto_indexer`, `theme_integration`.

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
`oraculo_list_models`, `oraculo_clear_vectors`, `oraculo_clear_all_vectors`,
`oraculo_optimize_db`, `oraculo_save_indexing_settings`, `oraculo_save_settings`,
`oraculo_clear_cache`, `oraculo_process_queue`.

**Shortcodes**: `[oraculo_search]`, `[oraculo_chat]` — enfileiram seus assets no render.

**WP-CLI** (`src/CLI/Commands.php`): `wp oraculo <index|status|search|test-connection|
clear-vectors|stats|providers|optimize|export|queue|clip>`. `queue <status|process|reconcile>`
opera a fila automática; `clip <health|models|search|index>` testa a integração CLIP.

## Indexação automática (fila + worker + reconciliação)

`src/Indexing/AutoIndexer.php` mantém o índice vetorial em dia sem reindexação manual:

1. **Captura** — hooks do Tainacan (`tainacan-insert-tainacan-item`,
   `tainacan-insert-Item_Metadata_Entity`) e do WordPress (`transition_post_status`,
   `before_delete_post`) apenas marcam o item como pendente; nenhuma chamada de IA
   acontece no save (evita bloquear o editor/importações em massa).
2. **Fila** — pendência vive na post meta `_oraculo_index_pending` (valor = timestamp
   liberado; dá debounce de 15s por padrão, filtrável via `oraculo_tainacan_index_delay`)
   e `_oraculo_index_attempts` (backoff exponencial, desiste em 5 tentativas).
3. **Worker** — cron `oraculo_process_index_queue` (recorrente a cada 5min + disparo
   avulso 15s após um enqueue) drena a fila em lote via `IndexingManager::index_items_by_id()`,
   com lock por `add_option()` e reagendamento em cadeia enquanto sobrar fila.
4. **Reconciliação diária** — cron `oraculo_reconcile_index`: publicado sem vetor local
   entra na fila; vetor de item não mais publicado é removido; publicado sem envio ao
   CLIP entra em backfill. Cobre restauração de backup, import direto via SQL e itens
   que estouraram o teto de tentativas.

Remoção **não** passa pela fila — é um DELETE local síncrono no próprio hook, então
item despublicado/excluído some da busca no ato. Cache de busca/sugestões é versionado
(`get_index_version()`/`bump_index_version()` em `helpers.php`) e invalidado no shutdown
do request; toda escrita de vetor marca o índice como sujo.

Auto-cura em upgrade: `AutoIndexer::ensure_scheduled()` roda em todo `init`, então os
crons se reagendam sozinhos mesmo quando o arquivo é sobrescrito sem reativar o plugin.

## Busca visual CLIP (AI API do IBRAM)

Integração opcional com a Museum CLIP Search API (FastAPI + pgvector,
`gitlab.museus.gov.br/tainacan-ia/ai-api`) — busca por proximidade visual, sem LLM.

- **`Vector\ClipApiClient`**: `/health`, `/v1/models`, `/v1/search/text` (JSON),
  `/v1/indexing/index` (multipart). Servidor é INSERT-only com `UNIQUE(external_id)` —
  reindexar o mesmo item devolve 500 de duplicidade, absorvido como "já indexado".
  Filtro é igualdade de string sobre o JSON de metadados (sem ranges).
- **`Search\QueryParser`**: separa restrição temporal (século arábico/romano, década,
  ano, intervalo) do texto visual da consulta — "obras do século 21 que são de vidro"
  vira texto `"obras de vidro"` + filtro `{century:"21"}`. Mesmas chaves derivadas
  (`derive_temporal_facets()`) usadas na indexação, para busca e índice falarem o
  mesmo vocabulário.
- **`Indexing\ClipIndexer`**: sobe a thumbnail do item (base64 data URI — funciona
  mesmo se a API rodar isolada sem acesso a URLs locais) com metadados normalizados.
  Estado em post meta `_oraculo_clip_indexed` (`1`/`no-image`/ausente); roda como
  passo best-effort do worker da fila, após o lote local.
- **`SearchEngine`** roteia para o backend CLIP quando `search_backend = clip`:
  parse → busca filtrada → relaxamento se zero resultados com filtro temporal (repete
  sem o filtro e sinaliza `filter_relaxed`) → pós-filtro client-side para intervalo de
  anos e múltiplas coleções (inexpressáveis no servidor) → mapeia `external_id` de
  volta para itens Tainacan publicados. `response` é um resumo determinístico — **sem
  LLM neste modo**, a API não é generativa.
- Limitação conhecida da API (sem endpoint próprio para corrigir): não há
  update/delete — metadado alterado num item já enviado não atualiza remotamente.

## Descoberta dinâmica de modelos por provedor

Cada provedor (`AIProviderInterface::list_remote_models()`) consulta o endpoint de
`/models` real da própria API com a chave configurada, em vez de depender só do
catálogo estático (`const MODELS`) embutido no código — reflete o que a conta
efetivamente libera (plano pago, acesso antecipado, etc.), incluindo modelos lançados
depois desta versão do plugin. Padrão portado de `tainacan-biblio`
(`AI_Service::list_remote_models()`), adaptado à arquitetura OOP por provedor daqui.

Botão "Buscar modelos da conta" em cada painel de configurações chama
`wp_ajax_oraculo_list_models`, que funciona **antes de salvar** — usa a chave
digitada no campo (ou a já salva, se o campo ainda mostra o placeholder mascarado
`••••••••`). Resultado remoto substitui o catálogo estático no `<select>` (mantendo o
modelo já escolhido se a conta ainda o libera); Ollama usa um `<datalist>` sobre o
campo de texto livre, listando o que está baixado localmente (`/api/tags`).

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
- Provedor ativo (`ai_provider`), API keys (criptografadas, ver abaixo) e modelos por provedor.
- `max_tokens`, `temperature`, `similarity_threshold`, `max_results`, `batch_size`, `request_timeout`, `cache_duration`.
- Flags de feature: `enable_chat`, `enable_search`, `enable_analytics`, `enable_feedback`, `debug_mode`.
- Prompts default (system/search/chat) + welcome message + perguntas sugeridas.
- `appearance` (cores, posição do chat, exibição de fontes/similaridade).
- `theme_integration` (aba de busca IA no tema).
- `index_fields` (campos indexados: title/description/metadata/document).
- `search_backend` (`local`|`clip`), `clip_api_url`, `clip_api_model`, `clip_api_timeout`.

Opções soltas (fora do array, lidas por `get_option()` direto): `oraculo_auto_index`
(liga/desliga a fila automática), `oraculo_batch_size`, `oraculo_embedding_provider`
(override do provedor de embeddings, independente do provedor de chat).

**Sanitização**: ponto único em `Admin\SettingsSanitizer::sanitize()`, usado pelo POST do
admin (`oraculo_save_settings`), pelo endpoint REST `/settings` **e — crucial — pelo
`sanitize_callback` registrado via `register_setting()`, que roda em TODO
`update_option('oraculo_tainacan_options')`**, inclusive nos programáticos. Consequências
de design (aprendidas por regressão em produção, 2.5.1/2.5.2):

- Toda regra do sanitizer precisa ser **idempotente**: o próprio output dele volta como
  input na segunda passada dentro do mesmo save. Foi assim que as API keys chegaram a ser
  criptografadas duas vezes (o header enviava `enc:...` literal ao provedor).
- **Checkboxes decidem pela PRESENÇA da chave, não pela ausência**: o formulário sempre
  envia cada flag (input hidden `value="0"` + checkbox `value="1"`); chave ausente =
  save parcial/programático → preserva o valor salvo, e só então o padrão. Ausência
  tratada como "desmarcado" fazia qualquer gravação parcial desligar chat, busca e a aba
  "Busca com IA" silenciosamente.

**API keys são criptografadas em repouso** (desde 2.4.0; endurecido na 2.5.1):
`SettingsSanitizer` grava `enc:` + AES-256-CBC (chave derivada de `wp_salt('auth')`) —
idempotente: valor já `enc:` nunca é re-criptografado. `AbstractAIProvider::get_api_key()`
descriptografa **em camadas** (laço limitado), o que cura valores que a 2.4.x chegou a
gravar duplamente criptografados; `is_configured()` valida a chave já descriptografada.
Placeholder `••••••••` no campo preserva o valor salvo. Chave antiga em texto puro (sem
prefixo) passa direto.

**Armadilha strict_types + `$wpdb`** (classe de bug recorrente — 3 incidentes em produção):
todos os arquivos declaram `strict_types=1`, e o `$wpdb` devolve **tudo como string**.
String do banco em parâmetro tipado `int`/`float` (ex.: `save_message(int $conversation_id)`
recebendo o id de `get_row()`) ou em função nativa estrita (`number_format()` nos templates)
é `TypeError` fatal — e só se manifesta com dados reais no banco, nunca em instalação vazia.
Regra: **cast na origem**, no ponto único onde a linha sai do banco, não em cada uso.

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

**WP-Cron em execução via CLI**: rodar `wp-load.php` repetidamente por script (`php -r`,
testes) pode disparar o auto-update real do WP-Cron a cada request; se ele travar no meio,
deixa `.maintenance` + `core_updater.lock`/`auto_updater.lock` presos, e o site local passa
a responder "Momentaneamente indisponível". `wp-config.php` local tem
`define('DISABLE_WP_CRON', true)` por isso — não afeta o servidor de produção/testes na
Hostinger. Se ainda assim travar: apagar `.maintenance` na raiz do WP + `delete_option()`
dos dois locks.

**Suítes de teste** (scripts avulsos, não PHPUnit; todas usam `pre_http_request` para
mockar provedores — não batem rede real): `queue_test`, `e2e_test`, `success_test`
(fila/hooks/worker), `clip_test`/`clip_image_test` (mock HTTP local do backend CLIP,
`php -S 127.0.0.1:8999`), `reconcile_test` (reconciliação diária), `model_discovery_test`
(descoberta de modelos), `gpt5_params_test` (parâmetros GPT-5/o-series + modelo configurado),
`ui_fixes_test` (fatais dos templates + CLIP como provedor), `encryption_cycle_test`
(criptografia idempotente + cura de dupla criptografia, com o sanitize_callback do
register_setting ativo — cenário que o CLI puro não reproduz) e `regression_fixes_test`
(chat multi-turno + preservação de flags em saves parciais) e `chat_context_test`
(payloads capturados por turno: histórico chega ao LLM, follow-up herda o assunto na
busca, sem duplicação da mensagem atual, limite de 10). 211 asserções ao todo na main.
