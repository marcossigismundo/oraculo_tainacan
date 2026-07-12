# Diagnóstico — Refatoração para o padrão Tainacan de UI/integração (Passo 0)

> Saída do **Passo 0** do playbook de `tainacan-ai/docs/TAINACAN_UI_PATTERNS.md` (§10).
> Data: 2026-07-11 · Base: branch `feature/ui-patterns-refactor` (a partir de `phpcs-hardening`, v2.0.3).
> Nenhum código foi alterado neste passo — apenas mapeamento.

---

## 1. Escopo do padrão neste plugin

O Oráculo Tainacan **tem tela admin integrada ao Tainacan** (via Pages API) e
**widgets públicos** (shortcodes de busca/chat), mas **não injeta campos nos
formulários de item/coleção/metadado** e **não escreve metadados de itens**.
Portanto, conforme §0 do doc de padrões:

| Camada do padrão | Aplica? | Observação |
|---|---|---|
| §1 Arquitetura em camadas | **Sim** | Bootstrap gordo hoje; ver gap A1 |
| §2 Tela admin (Pages API) | **Sim** | Já usa `\Tainacan\Pages`, com desvios; ver A2 |
| §3 Hooks de formulário (trio) | **Não (N/A)** | Plugin não estende forms de entidade |
| §4 Drawer + handshake Vue | **Parcial** | Não há escrita de metadado; aplicam-se §4.5 (robustez JS) e escapeHtml |
| §5 Tokens CSS | **Sim** | Parcialmente feito |
| §6 REST próprio | **Sim** | Namespace ok; caps e args com gaps graves; ver S1–S3 |
| §7 Persistência | **Sim** | Tabelas custom legítimas (dados não post-centric) |
| §8 Ferramental | **Sim** | Inexistente hoje; ver T1 |

---

## 2. Mapa do estado atual (as 5 perguntas do Passo 0)

### 2.1 Como o plugin registra tela admin?

**Duas implementações coexistem — uma viva, uma morta:**

- **Viva:** `src/Admin/OraculoPage.php` — estende `\Tainacan\Pages` ✓, registrada
  em `plugins_loaded` prio 20 ([oraculo-tainacan.php:546-552](../oraculo-tainacan.php#L546-L552)).
  Desvios do padrão:
  - Declarada em **namespace `Tainacan`** (do core!) como `\Tainacan\Oraculo_Page`
    ([OraculoPage.php:11](../src/Admin/OraculoPage.php#L11)) — polui o namespace do core;
    o padrão usa namespace próprio do plugin.
  - **Singleton manual** em vez do trait `\Tainacan\Traits\Singleton_Instance`.
  - Submenu sob `$this->tainacan_root_menu_slug` — o padrão usa
    `$this->tainacan_other_links_slug` ([OraculoPage.php:63-64](../src/Admin/OraculoPage.php#L63-L64)).
  - Capability do menu é `'read'` ([OraculoPage.php:68](../src/Admin/OraculoPage.php#L68)) —
    qualquer assinante enxerga o menu; as ações internas exigem `manage_options`,
    mas a tela expõe dashboard/analytics a quem não deveria.
  - Layout próprio de abas (`oraculo-tabs-nav`), sem o padrão de cards
    colapsáveis + sidebar + actions-bar do skeleton.
- **Morta:** `src/Admin/AdminPage.php` — hierarquia legada `add_menu_page`/
  `add_submenu_page` completa (5 subpáginas). **Nunca é instanciada no bootstrap**;
  a única instanciação é dentro de `RestController::update_settings()`
  ([RestController.php:630](../src/API/RestController.php#L630)) só para reusar
  `sanitize_settings()`. Todo o código de menu é peso morto.

### 2.2 Como injeta UI no form do item?

**Não injeta** — não há `tainacan_register_admin_hook`, MutationObserver nem Vue
próprio. A UI de frontend são shortcodes (`[oraculo_search]`, `[oraculo_chat]`)
renderizados por templates + jQuery. O trio de persistência (§3) é **N/A**.

### 2.3 Como grava metadado?

**Não grava metadados de itens** (só lê itens via Repositories para indexação ✓
— [helpers.php:246](../src/helpers.php#L246), [IndexingManager.php:441-495](../src/Indexing/IndexingManager.php#L441-L495)).
O handshake `PUT metadata + TainacanReloadItemMetadataForm` é **N/A**.

### 2.4 Onde há `$wpdb`, `json_decode` sem schema, `unserialize`, heredoc, CDN?

- **`$wpdb`:** uso extensivo, porém em **9 tabelas próprias** (`oraculo_vectors`,
  `oraculo_conversations`, `oraculo_messages`, `oraculo_search_logs`,
  `oraculo_indexing_jobs`, `oraculo_collection_prompts`, `oraculo_memory`,
  `oraculo_facts`, `oraculo_webhooks`) — dados não post-centric, legítimo pelo
  padrão T1/§7. As fases `phpcs-hardening` 1–7 já cobriram `prepare()`/ignores
  justificados. ✓
- **`unserialize` / heredoc / `date()` / `rand()`:** **zero ocorrências**. ✓
- **`json_decode` sem validação de schema:** ~20 pontos
  ([AbstractAIProvider.php:132](../src/AI/AbstractAIProvider.php#L132),
  [WebhooksManager.php:215-216](../src/Features/WebhooksManager.php#L215-L216),
  [ExportManager.php:200,268](../src/Features/ExportManager.php#L200),
  [ConversationMemory.php:228](../src/Features/ConversationMemory.php#L228), etc.)
  — a maioria decodifica JSON de tabela própria ou de API externa e usa `??`
  pontual, mas sem `is_array()`/checagem de chaves antes de iterar.
- **CDN externa:** **zero** — Chart.js bundled em `assets/vendor/` ✓.
- **`file_get_contents(URL remota)`:**
  [MultimodalSearch.php:398](../src/Features/MultimodalSearch.php#L398) baixa
  imagem por URL — deve ser `wp_remote_get`. Os demais `file_get_contents` são
  arquivos locais de upload (DocumentProcessor/ExportManager) — trocar por
  `WP_Filesystem` na passada de base segura.
- **cURL direto (streaming SSE)** nos 4 providers
  ([ClaudeProvider.php:289-329](../src/AI/Providers/ClaudeProvider.php#L289-L329) e análogos)
  — já com `phpcs:ignore` justificado ("WP HTTP API lacks streaming callback");
  justificativa tecnicamente correta, manter.

### 2.5 Qual o estado do `phpcs.xml.dist` e do textdomain?

- **Não existem** `phpcs.xml.dist`, `composer.json`, `package.json`, testes nem CI.
  Autoloader manual via `spl_autoload_register` ([oraculo-tainacan.php:114](../oraculo-tainacan.php#L114)).
  O hardening PHPCS (fases 1–7) foi feito rodando phpcs externo, sem ruleset
  versionado no repo.
- **`declare(strict_types=1)`: zero arquivos.**
- **Textdomain `oraculo_tainacan`** (underscore) consistente em todo o código ✓,
  mas: pasta = `oraculo_tainacan`, arquivo principal = `oraculo-tainacan.php`,
  Plugin URI = `github.com/tainacan/oraculo-tainacan`. Slugs do WordPress.org
  **não aceitam underscore** — se o destino for o diretório oficial, o slug/
  textdomain precisará convergir para `oraculo-tainacan` (mudança disruptiva;
  decisão a tomar antes do Passo 1).

---

## 3. Gaps priorizados vs. checklist §9

### P0 — Segurança (corrigir antes de qualquer refator estético)

| # | Gap | Evidência |
|---|---|---|
| S1 | **XSS no frontend:** `renderResults()` injeta `data.response`, `item.title`, `item.snippet`, `item.url`, `item.collection_name` e `suggestion` via `.html()` **sem escape**; `formatResponse()` converte markdown sem sanitizar HTML primeiro. Título de item Tainacan é conteúdo de terceiros; resposta da IA pode ecoar HTML. Não existe `escapeHtml` em nenhum JS. | [frontend.js:114-167](../assets/js/frontend.js#L114-L167), padrão repetido em [chat-widget.js](../assets/js/chat-widget.js), [admin.js:84-93](../assets/js/admin.js#L84-L93), [admin-page.js:140-142,351-366](../assets/js/admin-page.js#L140-L142) |
| S2 | **REST com `__return_true` em rota de mutação:** `POST /conversations/{session_id}/end` encerra conversa de qualquer pessoa sem auth nem nonce. | [RestController.php:102-108](../src/API/RestController.php#L102-L108) |
| S3 | **Leitura de conversa sem checagem de dono:** `GET /conversations/{sid}/messages` exige apenas `is_user_logged_in()` — qualquer usuário logado lê mensagens de qualquer sessão. | [RestController.php:93-99,369-394](../src/API/RestController.php#L93-L99) |
| S4 | **`POST /search` e `POST /chat` públicos sem nonce nem rate-limit** consomem API paga de IA — vetor de abuso de custo. O caminho AJAX equivalente exige nonce (`check_ajax_referer`); o REST não exige nada. Também não respeitam as opções `enable_search`/`enable_chat`. | [RestController.php:36-81](../src/API/RestController.php#L36-L81) vs [oraculo-tainacan.php:760,781](../oraculo-tainacan.php#L760) |
| S5 | **`POST /settings` sem `args`/schema:** `get_json_params()` cru vai direto ao sanitizador; e o handler instancia a classe morta `AdminPage` só para sanitizar. | [RestController.php:627-639](../src/API/RestController.php#L627-L639) |
| S6 | **Menu com capability `read`** expõe dashboard/analytics a assinantes. | [OraculoPage.php:68](../src/Admin/OraculoPage.php#L68) |
| S7 | **`file_get_contents` em URL remota** → `wp_remote_get`. | [MultimodalSearch.php:398](../src/Features/MultimodalSearch.php#L398) |

### P1 — Arquitetura / conformidade com o padrão

| # | Gap | Evidência |
|---|---|---|
| A1 | **Bootstrap gordo:** `oraculo-tainacan.php` tem ~1.180 linhas com 13 handlers AJAX, criação de 9 tabelas, sanitização e enqueue. Padrão §1: bootstrap magro + `includes/Plugin.php` singleton com wiring no `init` e guarda de Tainacan ativo. | [oraculo-tainacan.php](../oraculo-tainacan.php) |
| A2 | **Tela admin com desvios:** namespace `\Tainacan` indevido, singleton manual (não `Singleton_Instance`), submenu em `tainacan_root_menu_slug` (padrão: `tainacan_other_links_slug`), layout de abas fora do padrão cards+sidebar+actions-bar. | [OraculoPage.php:11,37-49,63-71](../src/Admin/OraculoPage.php#L11) |
| A3 | **Classe `AdminPage` morta** (menu legado nunca registrado) mantida só pelo `sanitize_settings()`; **três caminhos de sanitização duplicados** (`Oraculo_Tainacan::sanitize_options`, `AdminPage::sanitize_settings`, e o `register_setting` callback). | [AdminPage.php:40-136](../src/Admin/AdminPage.php#L40-L136), [RestController.php:630](../src/API/RestController.php#L630) |
| A4 | **Superfície dupla AJAX × REST:** 13 ações `admin-ajax` duplicam rotas REST (search, chat, feedback, indexação, settings, cache). Padrão §6: consolidar no namespace `oraculo/v1` com `args` validados e caps; manter só a superfície REST. | [oraculo-tainacan.php:165-178](../oraculo-tainacan.php#L165-L178) vs [RestController.php](../src/API/RestController.php) |
| A5 | **JS admin fala com `admin-ajax`** (`OraculoAdmin.ajaxUrl`) em vez de `@wordpress/api-fetch` com nonce middleware. | [admin.js](../assets/js/admin.js), [admin-page.js](../assets/js/admin-page.js) |
| A6 | **Inline `<script>` com PHP interpolado em todos os 7 templates** — mover para arquivos enqueued com `wp_localize_script`/`wp_add_inline_script`. | [dashboard.php:285](../templates/admin/dashboard.php#L285), [analytics.php:379](../templates/admin/analytics.php#L379), [settings.php:475](../templates/admin/settings.php#L475), [indexing.php:350](../templates/admin/indexing.php#L350), [debug.php:447](../templates/admin/debug.php#L447), [search-widget.php:230](../templates/search-widget.php#L230), [chat-widget.php:294](../templates/chat-widget.php#L294) |
| A7 | **Enqueue não condicional:** admin usa `strpos($hook, 'tainacan')` — carrega em toda página do Tainacan; frontend enfileira CSS/JS em **todas** as páginas do site, sem detectar shortcode/necessidade. | [oraculo-tainacan.php:620-624,672-711](../oraculo-tainacan.php#L620-L624) |
| A8 | **CSV export com `header()`+`echo`+`exit` dentro de callback REST** — quebra o contrato REST; usar rota dedicada com `WP_REST_Response` ou `admin-post`. | [RestController.php:527-533](../src/API/RestController.php#L527-L533) |
| A9 | **`json_decode` sem validação de schema** antes de iterar (~20 pontos). | ver §2.4 |

### P2 — Ferramental e base

| # | Gap | Evidência |
|---|---|---|
| T1 | Sem `composer.json` (PSR-4 + dev-deps), sem `phpcs.xml.dist` estrito versionado, sem CI, sem testes. | raiz do repo |
| T2 | `declare(strict_types=1)` ausente em 100% dos arquivos. | — |
| T3 | Decisão pendente de slug: `oraculo_tainacan` (underscore) inviável no WP.org. | §2.5 |
| T4 | `json_encode` cru no WP-CLI (3 pontos) → `wp_json_encode`. | [Commands.php:264,405,529](../src/CLI/Commands.php#L264) |

### P3 — Cosmético / menor

| # | Gap |
|---|---|
| C1 | Tokens CSS: `frontend.css` e `admin-page.css` já referenciam `--tainacan-*` (12 usos), mas sem o bloco `:root` completo de espelhamento com fallback do §5; `admin.css` e `chat-widget.css` sem tokens. |
| C2 | i18n JS via objeto localizado (aceitável pelo §11), sem `wp_set_script_translations`. |
| C3 | `get_dashboard_stats()` com queries agregadas em `OraculoPage` — mover para camada de serviço (Analytics) na reorganização. |

### Já conforme (não mexer sem motivo)

- Integração via `\Tainacan\Pages` com guarda `class_exists` e degradação limpa.
- Leitura de acervo exclusivamente via Repositories do Tainacan.
- Tabelas custom prefixadas, `prepare()` + ignores justificados (fases 1–7 do hardening).
- Chart.js bundled local (zero CDN); versão pinada comentada.
- `error_log` consistentemente gated por `WP_DEBUG` + ignore justificado.
- Nonce + `current_user_can('manage_options')` em todos os handlers AJAX admin.
- Zero `unserialize`, heredoc, `date()`, `rand()`.
- `readme.txt` com Stable tag 2.0.3 sincronizado com header/constante.

---

## 4. Plano de migração por camadas (adaptado do playbook §10)

Cada passo = 1+ commits isolados, comportamento preservado, `php -l` + smoke test
antes de avançar.

- **Passo 1 — Base segura:** corrigir P0 (S1 `escapeHtml` nos 4 JS; S2/S3/S4/S5
  caps+nonce+args no REST; S6 capability; S7 `wp_remote_get`); adicionar
  `composer.json` + `phpcs.xml.dist` estrito (§8/T7) e rodar baseline;
  `declare(strict_types=1)` em todos os arquivos; T4.
- **Passo 2 — Tela admin:** mover `Oraculo_Page` para namespace próprio +
  `Singleton_Instance`, submenu em `tainacan_other_links_slug`, capability real;
  adotar layout cards colapsáveis + sidebar + actions-bar do skeleton; completar
  bloco `:root` de tokens (C1); remover classe morta `AdminPage` consolidando a
  sanitização num único serviço.
- **Passo 3 — Hooks de formulário:** **N/A** (registrar no doc e pular).
- **Passo 4 — Frontend:** extrair os 7 inline `<script>` para arquivos enqueued;
  migrar JS admin para `@wordpress/api-fetch` + nonce middleware; delegação de
  eventos + re-cache de refs; enqueue condicional (page hook exato no admin;
  detecção de shortcode no front).
- **Passo 5 — REST e cache:** aposentar os 13 handlers `admin-ajax` em favor de
  `oraculo/v1` com `args`/`validate_callback`/caps completos; rota própria para
  CSV; validação de schema nos `json_decode` (A9); revisar transients.
- **Passo 6 — Verificação:** phpcs limpo, `php -l`, smoke na SPA e nos widgets,
  bump de versão + changelog.

**Decisões que precisam do mantenedor antes do Passo 1:**
1. Slug/textdomain: manter `oraculo_tainacan` (fora do WP.org) ou migrar para `oraculo-tainacan`?
2. `/search` e `/chat` REST devem permanecer públicos (visitantes anônimos)? Se sim, definir proteção mínima (nonce do widget + rate-limit por transient + gate nas opções `enable_*`).
