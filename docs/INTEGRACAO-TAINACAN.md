# Integração com o Tainacan — Referência Técnica

> Versão do plugin: 2.2.0 · Tainacan alvo: 1.0+ · WordPress 6.0+ · PHP 8.0+

Este documento descreve **como o Oráculo Tainacan se acopla ao Tainacan**: quais classes
do Tainacan são consumidas, em que momento, o que acontece se o Tainacan não estiver
presente, e qual é o contrato das rotas REST que o plugin expõe.

Para o panorama geral da arquitetura (tabelas, opções, estrutura de diretórios), veja
[CONTEXT.md](CONTEXT.md).

---

## 1. Modelo de acoplamento

O plugin **não depende do Tainacan para carregar**. Nenhum arquivo é `require`d a partir
do Tainacan e não há entrada `Requires Plugins` no cabeçalho. O acoplamento é feito
inteiramente por `class_exists()` / `function_exists()` em tempo de execução, e cada ponto
de contato degrada silenciosamente quando a classe não existe.

Consequência prática: com o Tainacan desativado o plugin continua ativo, as tabelas
continuam existindo, as rotas REST continuam registradas — mas a página administrativa
não aparece, a listagem de coleções retorna vazia e a indexação falha com
`invalid_collection`. Nenhum fatal error.

### 1.1 Superfícies do Tainacan consumidas

| Símbolo do Tainacan | Onde é usado | Papel |
|---|---|---|
| `\Tainacan\Pages` (classe abstrata) | `src/Admin/OraculoPage.php` | Classe base da página admin |
| `\Tainacan\Traits\Singleton_Instance` | `src/Admin/OraculoPage.php` | Trait exigido pela API de páginas |
| `\Tainacan\Repositories\Collections` | `src/helpers.php`, `src/Indexing/IndexingManager.php` | Leitura de coleções |
| `\Tainacan\Repositories\Items` | `src/helpers.php`, `src/Indexing/IndexingManager.php` | Leitura de itens |
| `\Tainacan\Entities\Item` | `src/helpers.php`, `src/Indexing/IndexingManager.php` | Type hint e validação de instância |
| `\Tainacan\Theme_Helper` | `src/Frontend/ThemeIntegration.php` | Detecção de contexto de listagem |
| `\Tainacan\Plugin` | `src/API/RestController.php` | Flag `tainacan_active` no `/health` |
| `tainacan_get_collection_id()` | `src/Frontend/ThemeIntegration.php` | ID da coleção da página atual |
| `tainacan_get_the_collection_name()` | `src/Frontend/ThemeIntegration.php` | Rótulo de escopo no painel |

**O plugin não consome a REST API do Tainacan (`/wp-json/tainacan/v2/...`).** Todo o acesso
a dados é feito em processo, pelos repositórios PHP. Isso evita round-trip HTTP e
autenticação interna, mas significa que a indexação só roda no mesmo host do Tainacan.

---

## 2. Ponto de contato 1 — Página administrativa

**Arquivo:** `src/Admin/OraculoPage.php`

### Registro

```php
// oraculo-tainacan.php
add_action( 'plugins_loaded', array( $this, 'init_tainacan_page' ), 20 );

public function init_tainacan_page(): void {
    if ( class_exists( '\Tainacan\Pages' ) && trait_exists( '\Tainacan\Traits\Singleton_Instance' ) ) {
        Admin\OraculoPage::get_instance();
    }
}
```

A prioridade **20** é deliberada: o Tainacan precisa ter registrado suas classes antes.
Os serviços do plugin sobem depois, na prioridade **25**.

### Contrato herdado

`OraculoPage extends \Tainacan\Pages` e implementa:

| Membro | Origem | Função |
|---|---|---|
| `get_page_slug(): string` | sobrescrito | Retorna `oraculo_tainacan_page` |
| `add_admin_menu()` | sobrescrito | Registra o submenu |
| `render_page_content()` | sobrescrito | Renderiza a aba ativa |
| `load_page()` | sobrescrito | Delega a `parent::load_page()` |
| `admin_enqueue_css()` / `admin_enqueue_js()` | sobrescrito | Assets da página |
| `$this->tainacan_other_links_slug` | herdado | Slug do menu "Mais" do Tainacan |
| `render_tabs_navigation()` | herdado | Navegação no padrão visual do Tainacan |

O item aparece sob o menu **"Mais"** do Tainacan, na posição 3, exigindo `manage_options`:

```php
add_submenu_page(
    $this->tainacan_other_links_slug,   // menu pai, vindo do Tainacan
    __( 'Oráculo IA', 'oraculo-tainacan' ),
    '<span class="icon">…</span><span class="menu-text">Oráculo IA</span>',
    'manage_options',
    $this->get_page_slug(),
    array( $this, 'render_page' ),
    3
);
```

### Abas e carregamento de assets

A aba vem de `?tab=`, validada contra uma allowlist (`dashboard`, `indexing`, `settings`,
`analytics`, `debug`; qualquer outro valor cai em `dashboard`). A aba `debug` só renderiza
se `debug_mode` estiver ligado — caso contrário volta para o dashboard.

Cada aba carrega **apenas o seu JS**:

```
assets/js/admin-page.js        base (tabs, modal, tooltips) — sempre
assets/js/admin/<aba>.js       lógica da aba ativa — só a aba corrente
assets/vendor/chart.umd.min.js Chart.js 4.4.1, embarcado (sem CDN)
```

Os dados vão para o browser via `wp_localize_script( 'oraculo-admin-page', 'OraculoAdminPage', … )`,
sempre **depois** do registro do handle. O objeto expõe `ajaxUrl`, `restUrl`, `nonce`
(ação `oraculo_admin`), `restNonce` (ação `wp_rest`), a lista de provedores, as coleções e
`options`.

As opções passam antes por `get_safe_options()`, que substitui cada chave de
`openai_api_key`, `gemini_api_key`, `deepseek_api_key`, `groq_api_key` e `claude_api_key`
pelo placeholder `••••••••` quando há valor, ou string vazia quando não há. O valor real
nunca chega ao cliente — e é esse mesmo placeholder que `SettingsSanitizer` reconhece na
volta para preservar a chave armazenada.

---

## 3. Ponto de contato 2 — Leitura de coleções e itens

**Arquivos:** `src/helpers.php`, `src/Indexing/IndexingManager.php`

Toda leitura passa pelos repositórios do Tainacan, nunca por `WP_Query` direto sobre os
post types `tnc_col_{id}_item`.

### 3.1 Listagem de coleções

`\Oraculo_Tainacan\get_tainacan_collections( bool $only_published = true ): array`

```php
$repository  = \Tainacan\Repositories\Collections::get_instance();
$collections = $repository->fetch( array( 'status' => 'publish' ), 'OBJECT' );
```

Para cada coleção, a contagem de itens usa `wp_count_posts()` sobre o post type retornado
por `$collection->get_db_identifier()` — o identificador dinâmico do Tainacan.

Retorna: `id`, `name`, `description`, `url`, `items_count`.

Consumidores: aba de indexação, `GET /oraculo/v1/collections`, `AnalyticsManager`, WP-CLI.

### 3.2 Leitura paginada de itens (indexação)

```php
$repository = \Tainacan\Repositories\Items::get_instance();
$items = $repository->fetch(
    array(
        'collection_id'  => $collection_id,
        'status'         => 'publish',
        'posts_per_page' => $batch_size,   // opção batch_size, 5–100, padrão 25
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ),
    array(),
    'OBJECT'
);
```

Cada elemento é validado com `instanceof \Tainacan\Entities\Item` antes de ser processado;
o que não passar é contado como falha e não interrompe o lote.

### 3.3 Normalização de item

`\Oraculo_Tainacan\format_tainacan_item( \Tainacan\Entities\Item $item ): array`

Percorre `$item->get_metadata()` e, para cada metadado com valor não vazio, indexa por
`$metadatum->get_slug()` guardando `name` e `get_value_as_string()`.

Estrutura resultante:

```php
[
  'id', 'title', 'description', 'url',            // url via get_permalink()
  'collection_id', 'collection_name',
  'thumbnail',                                    // get_the_post_thumbnail_url(…, 'medium')
  'metadata' => [ '<slug>' => ['name'=>…, 'value'=>…], … ],
  'created_at', 'modified_at',
]
```

### 3.4 Texto enviado ao provedor de embeddings

`format_item_for_indexing()` monta um bloco rotulado a partir dos campos escolhidos na
opção `index_fields` (padrão: `title`, `description`; `metadata` é opcional):

```
TÍTULO: …
DESCRIÇÃO: …
<Nome do metadado>: <valor>
```

Esse texto é hasheado (`generate_content_hash`) e o hash guardado em
`oraculo_vectors.content_hash`. Reindexações comparam o hash antes de gastar uma chamada
de embedding — item inalterado é pulado.

---

## 4. Ponto de contato 3 — Aba de busca IA no tema

**Arquivos:** `src/Frontend/ThemeIntegration.php`, `assets/js/theme-integration.js`,
`assets/css/theme-integration.css`

A listagem de itens do Tainacan é uma aplicação **Vue.js** montada pelo próprio Tainacan
(`tainacan_the_faceted_search()`), em `[data-module="faceted-search"]`. Não há filtro PHP
para inserir markup dentro dela, então a injeção acontece **no client-side**, sobre o DOM
já renderizado.

### 4.1 Detecção de contexto (PHP)

`is_tainacan_items_page()` retorna `true` em quatro situações:

| Contexto | Teste |
|---|---|
| Arquivo de itens de coleção | `is_post_type_archive()` **e** `Theme_Helper::is_post_type_a_collection( get_post_type() )` |
| Arquivo do repositório | `is_archive()` **e** `get_query_var( 'tainacan_repository_archive' ) === 1` |
| Arquivo de termo de taxonomia Tainacan | `is_tax()` **e** `Theme_Helper::is_taxonomy_a_tainacan_tax( $term->taxonomy )` |
| Página com bloco/shortcode | `has_block( 'tainacan/faceted-search', $post )` **ou** `has_shortcode( …, 'tainacan-search' )` |

Os assets só são enfileirados (`wp_enqueue_scripts`, prioridade 20) se **todas** estas
condições valerem: `enable_search` ligado, `theme_integration.enabled` ligado, e o contexto
acima confirmado.

### 4.2 Injeção no DOM (JS)

```
[data-module="faceted-search"]              raiz observada
└── .search-control-item--search
    ├── .oraculo-ai-tabs                    inserido como primeiro filho
    └── .oraculo-ai-searchbar               anexado ao final
.search-control
└── .oraculo-ai-panel                       inserido logo após
```

Um `MutationObserver` sobre a raiz reinjeta os nós quando o Vue re-renderiza, com o
trabalho agendado em `requestAnimationFrame` para coalescer mutações em rajada. Os nós do
plugin nunca reposicionam nós gerenciados pelo Vue — só são inseridos ao lado.

O estado da aba ativa persiste em `sessionStorage` sob a chave `oraculoAiTabActive`.

### 4.3 Interoperabilidade com a busca nativa

- Ao ativar a aba de IA, o valor já digitado no campo nativo é copiado para o campo de IA.
- O botão "Refinar na busca tradicional" faz o caminho inverso e dispara
  `new Event('input', { bubbles: true })` no campo nativo — necessário para o `v-model` do
  Vue perceber a mudança.
- `Esc` no campo de IA volta para a busca padrão e devolve o foco a ela.
- As abas seguem o padrão WAI-ARIA `tablist` (`role`, `aria-selected`, `tabIndex`, setas).

### 4.4 Escopo da busca

| `theme_integration.scope` | Coleção da página | `collections` enviado ao `/search` |
|---|---|---|
| `collection` | presente | `[ id_da_coleção ]` |
| `collection` | ausente (repositório/termo) | `default_collections` (máx. 20) |
| `all` | qualquer | `default_collections` (máx. 20) |

O corte em 20 espelha `maxItems` do schema REST — acima disso a requisição seria rejeitada
com `rest_invalid_param`.

### 4.5 Deep link e evento público

`?oraculo_q=<pergunta>` abre a aba de IA e dispara a busca automaticamente.

Ao concluir uma busca, o plugin emite um `CustomEvent` borbulhante na raiz da listagem:

```js
document.addEventListener('oraculo-ai-search-done', function (e) {
    e.detail.query;   // string consultada
    e.detail.result;  // payload de /search (response, items, search_id, …)
});
```

### 4.6 Herança visual do tema

O CSS consome as variáveis `--tainacan-*` do tema ativo, então a aba acompanha
automaticamente a paleta do Tainacan Interface ou de qualquer tema compatível. As cores
próprias do plugin entram por um `wp_add_inline_style` que define `--oraculo-primary` e
`--oraculo-accent` a partir das opções de aparência.

---

## 5. Rotas REST expostas pelo plugin

**Namespace:** `oraculo/v1` · **Base:** `/wp-json/oraculo/v1/` · **Arquivo:** `src/API/RestController.php`

19 rotas. Registro em `rest_api_init`.

### 5.1 Públicas — consomem IA ou gravam dados

| Método | Rota | Permission callback |
|---|---|---|
| POST | `/search` | `check_public_search` |
| POST | `/chat` | `check_public_chat` |
| POST | `/feedback` | `check_public_feedback` |

As três passam por `check_public_endpoint()`, que aplica **quatro camadas em ordem**:

1. **Gate de feature** — `enable_search` / `enable_chat` / `enable_feedback`.
   Falha ⇒ `403 oraculo_feature_disabled`.
2. **Nonce** — `X-WP-Nonce` ou `_wpnonce`, ação `wp_rest`.
   Falha ⇒ `401 oraculo_invalid_nonce`.
3. **Rate limit por IP** — janela de 1 minuto via transient `oraculo_rl_<feature>_<md5(ip)>`.
   Padrões: search 10/min, chat 20/min, feedback 30/min.
   Falha ⇒ `429 oraculo_rate_limited`.
4. **Teto global de IA** — só para `search` e `chat`: 60 chamadas/min somando todos os IPs,
   transient `oraculo_rl_global_ai`. Existe porque o limite por IP não contém abuso
   distribuído. Falha ⇒ `503 oraculo_global_rate_limited`.

Visitantes anônimos recebem o nonce por `wp_localize_script` (`restNonce`).

#### `POST /search`

| Parâmetro | Tipo | Obrigatório | Validação |
|---|---|---|---|
| `query` | string | sim | não-vazia após `trim`, ≤ 500 caracteres |
| `collections` | int[] | não | `maxItems: 20`, padrão `[]` (todas) |
| `max_results` | int | não | 1–50, padrão 10 |

Resposta `200`:

```json
{
  "success": true,
  "data": {
    "query": "…",
    "response": "texto gerado pelo LLM",
    "items": [
      {
        "id": 123, "title": "…", "snippet": "…", "url": "…",
        "collection_id": 4, "collection_name": "…",
        "similarity": 87.4,
        "metadata": {}
      }
    ],
    "total_results": 8,
    "usage": {}, "model": "gpt-4o-mini", "cost": 0.0001,
    "response_time_ms": 2140,
    "search_id": "uuid-v4",
    "from_cache": false
  }
}
```

`similarity` já vem em **percentual** (0–100), arredondado a uma casa.
`search_id` é o identificador a devolver em `/feedback`.

Erro do motor de busca ⇒ `400 { "success": false, "error": "…" }`.
Erro de permissão ⇒ formato `WP_Error` padrão (`{code, message, data:{status}}`) —
**não** o envelope `success/data`. Clientes precisam tratar as duas formas.

#### `POST /chat`

| Parâmetro | Tipo | Obrigatório | Validação |
|---|---|---|---|
| `message` | string | sim | não-vazia, ≤ 2000 caracteres |
| `session_id` | string | não | `^[a-zA-Z0-9-]{0,64}$`; vazio gera nova sessão |
| `collections` | int[] | não | `maxItems: 20` |

#### `POST /feedback`

| Parâmetro | Tipo | Obrigatório | Validação |
|---|---|---|---|
| `feedback` | string | sim | enum `positive` \| `negative` |
| `search_id` | string | não | UUID devolvido por `/search` |
| `message_id` | int | não | id de mensagem de chat |

### 5.2 Leitura ligada à busca

| Método | Rota | Permission callback | Gate |
|---|---|---|---|
| GET | `/collections` | `check_search_enabled` | `enable_search` |
| GET | `/suggestions` | `check_search_enabled` | `enable_search` |

Sem nonce e sem rate limit: são leituras leves que não acionam provedor de IA.
`/collections` devolve o retorno de `get_tainacan_collections()`.

### 5.3 Conversas — exigem usuário autenticado

| Método | Rota | Permission callback |
|---|---|---|
| GET | `/conversations` | `check_user_logged_in` |
| GET | `/conversations/(?P<session_id>[a-zA-Z0-9-]+)/messages` | `check_user_logged_in` |
| POST | `/conversations/(?P<session_id>[a-zA-Z0-9-]+)/end` | `check_rest_nonce` |

A posse do `session_id` é verificada **no handler**, além do permission callback — o
callback só garante autenticação, não que a sessão pertença a quem pediu.

### 5.4 Administrativas — `manage_options`

| Método | Rota | Função |
|---|---|---|
| POST | `/indexing/start` | Dispara indexação (`collection_id` obrigatório, `force` opcional) |
| GET | `/indexing/status` | Progresso por coleção |
| POST | `/indexing/cancel` | Cancela job |
| GET | `/analytics` | Métricas agregadas |
| GET | `/analytics/timeline` | Série temporal |
| GET | `/analytics/export` | Exportação |
| GET | `/vectors/stats` | Total de vetores e distribuição por coleção |
| GET | `/providers` | Provedores disponíveis e estado de configuração |
| POST | `/providers/test` | Testa credenciais (`provider` obrigatório) |
| GET | `/settings` | Lê configurações |
| POST | `/settings` | Grava configurações (via `SettingsSanitizer`) |
| GET | `/health` | Versões, `tainacan_active`, uso de memória |

`/health` é **restrito a admin** de propósito: expõe versões e estado de configuração,
informação útil para reconhecimento.

---

## 6. AJAX

Todos os handlers são `wp_ajax_` (autenticados). **Não existe nenhum `wp_ajax_nopriv_`** —
a superfície pública vive exclusivamente no REST. Cada handler valida
`check_ajax_referer( 'oraculo_admin', 'nonce' )` **e** `current_user_can( 'manage_options' )`.

```
oraculo_index_collection        oraculo_clear_all_vectors
oraculo_get_indexing_status     oraculo_optimize_db
oraculo_test_connection         oraculo_save_indexing_settings
oraculo_clear_vectors           oraculo_save_settings
                                oraculo_clear_cache
```

---

## 7. Shortcodes

| Shortcode | Atributos | Template |
|---|---|---|
| `[oraculo_search]` | `placeholder`, `button_text`, `collections`, `show_filters`, `results_per_page` | `templates/search-widget.php` |
| `[oraculo_chat]` | `collections`, `title`, `height`, `show_suggestions` | `templates/chat-widget.php` |

Os assets são registrados em `wp_enqueue_scripts` mas **enfileirados apenas no render** do
shortcode, de modo que páginas sem o shortcode não pagam o custo.

---

## 8. WP-CLI

Registrado sob `wp oraculo` quando `WP_CLI` está definido.

| Comando | Função |
|---|---|
| `wp oraculo index` | Indexa uma coleção |
| `wp oraculo status` | Estado da indexação |
| `wp oraculo search` | Busca pela linha de comando |
| `wp oraculo test_connection` | Testa um provedor |
| `wp oraculo clear_vectors` | Limpa vetores |
| `wp oraculo stats` | Estatísticas |
| `wp oraculo providers` | Lista provedores |
| `wp oraculo optimize` | `OPTIMIZE TABLE` nas tabelas do plugin |
| `wp oraculo export` | Exporta dados/configuração |

---

## 9. Fluxos ponta a ponta

### 9.1 Indexação

```
IndexingManager::start_indexing( $collection_id, $force )
  ├─ get_collection()                → Collections::fetch()          [Tainacan]
  ├─ count_collection_items()        → get_db_identifier() + wp_count_posts()
  ├─ create_for_embeddings()->is_configured()
  ├─ se $force: VectorStore::delete_collection()
  └─ loop por página (batch_size):
       get_collection_items()        → Items::fetch()                [Tainacan]
       └─ por item:
            instanceof \Tainacan\Entities\Item ?
            format_tainacan_item()   → get_metadata(), get_value_as_string()
            format_item_for_indexing()
            generate_content_hash()  → hash igual? pula
            generate_embedding()     → provedor de IA (HTTP externo)
            VectorStore::upsert()    → wp_oraculo_vectors
       gc_collect_cycles()
```

Limites elevados durante a operação: `set_time_limit(300)` e `memory_limit=512M`, ambos
condicionais e silenciosos se o host bloquear.

### 9.2 Busca (RAG)

```
POST /oraculo/v1/search
  └─ check_public_endpoint()  → feature gate → nonce → rate limit IP → teto global
  └─ SearchEngine::search()
       ├─ cache: transient por (query + coleções); hit devolve com from_cache=true
       ├─ 1. generate_embedding( $query )        → provedor de embeddings
       ├─ 2. VectorStore::search()               → recuperação
       ├─ 3. prepare_context()                   → monta o contexto do prompt
       ├─ 4. generate_response()                 → LLM
       ├─ 5. format_items() + snippet por termo
       ├─ set_transient( cache_duration )
       └─ log_search()                           → wp_oraculo_search_logs
```

### 9.3 Recuperação vetorial — nota de escala

`VectorStore::search()` **carrega todos os vetores das coleções pedidas** e calcula a
similaridade de cosseno **em PHP**:

```php
$vectors = $wpdb->get_results( "SELECT … FROM wp_oraculo_vectors WHERE …" );
foreach ( $vectors as $vector ) {
    $similarity = cosine_similarity( $query_embedding, json_decode( $vector['embedding_data'] ) );
    if ( $similarity >= $threshold ) { … }
}
usort( $results, fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );
return array_slice( $results, 0, $limit );
```

Não há índice ANN nem extensão vetorial no MySQL. O custo é **linear no número de itens
indexados** e os embeddings trafegam inteiros do banco para o PHP a cada busca. É adequado
para acervos na ordem de milhares de itens; acima disso, o gargalo será esta varredura, e a
substituição natural é um índice vetorial externo mantendo a mesma interface `VectorStore`.

Mitigação em produção hoje: `cache_duration` (transient por consulta) e
`similarity_threshold`, que corta candidatos antes da ordenação.

---

## 10. Hooks expostos pelo plugin

| Filtro | Padrão | Efeito |
|---|---|---|
| `oraculo_tainacan_rest_rate_limit` | search 10, chat 20, feedback 30 | Requisições/min por IP. Recebe `(int $max, string $feature)`. `0` desativa |
| `oraculo_tainacan_rest_global_rate_limit` | 60 | Teto global de chamadas de IA/min. `0` desativa |
| `oraculo_tainacan_retention_logs_days` | 90 | Retenção de `oraculo_search_logs` |
| `oraculo_tainacan_retention_conversations_days` | 30 | Retenção de conversas e mensagens |

Exemplo — afrouxar o limite de busca para usuários logados:

```php
add_filter( 'oraculo_tainacan_rest_rate_limit', function ( int $max, string $feature ): int {
    if ( 'search' === $feature && is_user_logged_in() ) {
        return 60;
    }
    return $max;
}, 10, 2 );
```

O plugin **não dispara `do_action()`** próprio. Extensões que precisem reagir a uma busca
no frontend devem usar o `CustomEvent` `oraculo-ai-search-done` (seção 4.5).

---

## 11. Cron

| Hook | Agendamento | Handler |
|---|---|---|
| `oraculo_cleanup_old_data` | diário, agendado na ativação | `Oraculo_Tainacan::cleanup_old_data()` |
| `oraculo_process_indexing_batch` | sob demanda | `Oraculo_Tainacan::process_indexing_batch()` |

`cleanup_old_data()` remove, com os prazos filtráveis da seção 10:

- `oraculo_search_logs` com `created_at` além da retenção;
- mensagens de conversas com `updated_at` além da retenção (via `JOIN`);
- as próprias conversas inativas;
- registros de `oraculo_memory` com `expires_at` no passado.

Os intervalos são vinculados por `%d` em `$wpdb->prepare()` — nenhum valor de retenção é
interpolado direto na SQL.

---

## 12. Comportamento sem o Tainacan

| Superfície | Sem Tainacan ativo |
|---|---|
| Carregamento do plugin | Normal, sem erro |
| Página admin "Oráculo IA" | Não é registrada |
| `get_tainacan_collections()` | `[]` |
| `GET /oraculo/v1/collections` | `200` com lista vazia |
| Indexação | `WP_Error invalid_collection` |
| Aba de busca no tema | Não é injetada (`is_tainacan_available()` falha) |
| Aba "Tema Tainacan" nas configurações | Visível, com aviso de que o Tainacan está inativo |
| `GET /oraculo/v1/health` | `tainacan_active: false` |
| Busca sobre vetores já indexados | Continua funcionando (não depende do Tainacan) |

O último caso é relevante: os vetores vivem em tabela própria e guardam `item_title` e
`item_url` desnormalizados, então uma busca sobre um índice já construído responde mesmo
com o Tainacan desativado — os links apenas podem apontar para URLs que não resolvem mais.
