=== Oráculo Tainacan ===
Contributors: tainacancommunity
Tags: tainacan, ai, search, rag, openai
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered natural-language search and chat for Tainacan digital archives. RAG with multiple providers (OpenAI, Gemini, Claude, DeepSeek, Groq).

== Description ==

Oráculo Tainacan adds Artificial Intelligence capabilities to Tainacan, enabling:

* **Semantic Search (RAG)**: Find items by meaning, not just keywords
* **AI Chat**: Converse about the archive with conversation memory and extracted facts
* **Vector Indexing**: Generate embeddings for items to enable similarity search
* **Multiple Providers**: OpenAI, Google Gemini, Claude (Anthropic), DeepSeek, Groq and Ollama (local)
* **Analytics**: Track usage metrics, searches, feedback and performance
* **Per-collection Prompts**: Customizable system/search/chat prompts per collection
* **Webhooks**: Trigger external integrations on plugin events
* **Export**: Export conversations and analytics reports
* **Multimodal Search**: Search combining text and other signals
* **Smart Suggestions**: Dynamic suggested questions based on context
* **WP-CLI**: Commands for batch indexing, statistics and maintenance
* **REST API**: 20 endpoints under /wp-json/oraculo/v1

== Requirements ==

* WordPress 6.0 or higher
* PHP 8.0 or higher
* Tainacan plugin 1.0+ installed and active (integration via Pages API)
* API key for at least one AI provider, or Ollama running locally

== Installation ==

1. Upload the plugin via Plugins > Add New > Upload Plugin
2. Activate the plugin through the Plugins menu in WordPress
3. Go to Tainacan > Oráculo AI in the admin menu
4. Configure your API key in the Settings tab
5. Index your collections in the Indexing tab

== Configuration ==

1. **Choose an AI Provider**: OpenAI, Gemini, Claude, DeepSeek, Groq or Ollama
2. **Configure the API Key**: Enter the key for your chosen provider
3. **Select the Model**: Choose chat and embedding models
4. **Adjust Parameters**: Temperature, max tokens, similarity threshold, batch size
5. **Enable Features**: Chat, search, analytics and feedback can each be toggled individually

== Usage ==

**Shortcodes:**

* `[oraculo_search]` - Semantic search widget
* `[oraculo_chat]` - AI chat widget

**Search shortcode parameters:**

* `collection` - Collection ID (optional; uses all collections if omitted)
* `placeholder` - Placeholder text for the search field
* `show_suggestions` - Show suggestions (true/false)

Example: `[oraculo_search collection="123" placeholder="What are you looking for?" show_suggestions="true"]`

== CLI Commands ==

* `wp oraculo index --collection=<id>` - Index a collection
* `wp oraculo reindex --all` - Re-index all collections
* `wp oraculo stats` - Usage statistics
* `wp oraculo clear-cache` - Clear search cache

== REST API ==

Namespace: `/wp-json/oraculo/v1`. Main routes:

* `POST /search` - Semantic search
* `POST /chat` - Chat message
* `GET /conversations` - List conversations
* `POST /feedback` - Record feedback
* `POST /indexing/start` - Start indexing
* `GET /indexing/status` - Indexing status
* `GET /analytics` - Aggregated metrics
* `GET /providers` - Available providers
* `POST /providers/test` - Test provider connection
* `GET /health` - Health check

== Supported AI Providers ==

1. **OpenAI** - GPT-4o, GPT-4o-mini, GPT-3.5-turbo + text-embedding-ada-002/3
2. **Google Gemini** - gemini-1.5-flash, gemini-1.5-pro
3. **Claude (Anthropic)** - claude-3.5-sonnet, claude-3-opus/haiku
4. **DeepSeek** - deepseek-chat, deepseek-coder
5. **Groq** - llama-3.1-70b, mixtral-8x7b, gemma2-9b
6. **Ollama** - Any local model (full privacy; embeddings via nomic-embed-text)

== Frequently Asked Questions ==

= Does this plugin work without Tainacan? =

No. Oráculo Tainacan is an add-on for the Tainacan digital archive plugin and requires Tainacan 1.0 or higher.

= Which AI provider should I use? =

OpenAI is the most tested option. Ollama is recommended for fully local, private deployments.

= Is my data sent to third parties? =

Only the text content of your archived items is sent to the AI provider you configure. When using Ollama, everything stays local.

== Changelog ==

= 2.5.1 =
* Fix: API keys were double-encrypted on save — register_setting() wires the same sanitizer as the option sanitize_callback, so the admin save encrypted once manually and update_option() encrypted the already-encrypted value again; one decryption pass then sent the literal "enc:..." string to the provider ("Incorrect API key provided: enc:..."). Encryption is now idempotent (an enc: value is never re-encrypted) and get_api_key() decrypts in layers, healing keys already stored double-encrypted without requiring re-entry
* Fix: is_configured() now validates the decrypted key — a corrupted enc: value no longer counts as configured (it used to send an empty Bearer to the API instead of saying the key needs reconfiguring)
* Fix: "Testar Conexão" showed a ✅ in front of authentication failures — the AJAX envelope now reflects the actual test result
* Fix: indexing that fails for every item no longer reports "Indexação concluída!" in green — it reports a failure pointing at the embeddings provider key, and the collection status shows an error state

= 2.5.0 =
* Change: the CLIP visual search backend is now selected as an AI provider card ("Busca Visual (CLIP)"), side by side with OpenAI/Claude/etc., instead of a separate "search backend" select buried in the General tab. Its panel carries the API URL, model (with "fetch models from server") and test connection; picking it routes search through CLIP and disables chat with an explanatory message. Stored configs using the old search_backend option keep working and are migrated on the next save
* Fix: provider cards were unclickable — the decorative ::before overlay (position:absolute, full card) painted above the static content and swallowed every click, so the radio never toggled. The overlay is now pointer-events:none and the whole card selects the provider on click, matching its cursor:pointer affordance
* Fix: the Analytics tab died with a critical error as soon as it had real data — the templates declare strict_types and passed the string counts coming from wpdb straight into number_format(), a TypeError on PHP 8. All number_format() calls in the admin templates now cast explicitly (also latent in the Indexing and Debug tabs)
* Fix: the Debug tab fataled on a call to detect_environment(), a helper that never existed; it now reports the environment via the real is_hostinger()/is_xampp() detectors

= 2.4.1 =
* Fix: chat/search always ran on the first model of the provider catalog, silently ignoring the model chosen in settings — prepare_options() injected catalog[0] unconditionally, so the configured model never won. Exposed when 2.4.0 reordered the OpenAI catalog and every install started calling GPT-5.2 regardless of configuration
* Fix: OpenAI reasoning-family models (GPT-5.x, o1/o3/o4) rejected requests with `max_tokens`/custom `temperature` — the provider now sends `max_completion_tokens` (and omits temperature) for those families, with an adaptive retry that fixes the parameters when the API reports unsupported_parameter for models newer than this version
* Fix: the AI search tab showed the generic "Não foi possível concluir a busca" instead of the real backend error (the 400 payload uses `error`, which the error branch never read)

= 2.4.0 =
* Feature: "Buscar modelos da conta" — every AI provider settings panel can query the provider's own /models endpoint with the key currently typed (even before saving) and list only the models that account's plan actually unlocks, instead of relying solely on the catalog hardcoded in the plugin. Works for OpenAI, Claude, Gemini, Groq, DeepSeek (real API query) and Ollama (installed models via /api/tags); the static catalog remains the fallback until a search is run
* Security: API keys are now encrypted at rest (AES-256-CBC via wp_salt) instead of stored as plain text in wp_options; keys saved before this version keep working unchanged (the decryption path already handled both formats — this release is what starts actually encrypting on save)
* Dev: new AIProviderInterface::list_remote_models() implemented by all six providers; new wp_ajax_oraculo_list_models endpoint

= 2.3.0 =
* Feature: automatic indexing — new and edited items are queued on save and indexed in the background, so they become searchable within minutes instead of waiting for a manual reindex
* Feature: items sent to trash, unpublished or deleted are removed from the index immediately, so search no longer returns items visitors cannot open
* Feature: daily reconciliation cron re-syncs the index with the collection (backup restores, direct imports, items that exhausted retries) and removes orphan vectors
* Feature: CLIP visual search backend — optional integration with the IBRAM AI API (FastAPI + pgvector). Natural language queries run in the CLIP image-text space and temporal constraints ("obras do século 21") are parsed into metadata filters; item images are indexed remotely with normalized facets (year/decade/century/collection). No AI-generated answer in this mode: results are the closest works, with a deterministic summary
* Feature: queue status card and "process now" button on the indexing screen; new WP-CLI commands `wp oraculo queue <status|process|reconcile>` and `wp oraculo clip <health|models|search|index>`
* Fix: search and suggestion caches are now versioned by the index, so a freshly indexed item shows up right away instead of after the cache TTL (up to 1 hour)
* Fix: `wp oraculo index` no longer aborts on a call to a non-existent method; it now reports the result of the (already synchronous) indexing run
* Fix: indexing an item whose collection was deleted no longer raises a fatal error
* Fix: the "Campos para Indexar" checkboxes and batch size on the indexing screen now persist to the options the indexer actually reads (they previously wrote orphan options), and the embeddings-provider select is honored by the provider factory
* Dev: new filters `oraculo_tainacan_auto_index_enabled`, `oraculo_tainacan_index_delay` and `oraculo_tainacan_queue_batch_size`
* New: Tainacan theme integration — an "AI Search" tab injected next to the default search field on all Tainacan items lists, with AI answer panel, suggested questions, related items grid and deep link support (?oraculo_q=question); new settings tab "Tema Tainacan"
* Fix: unchecking "show sources" / "show similarity" now persists; the missing key used to fall back to the default instead of false
* Fix: settings sanitization unified in SettingsSanitizer for both the admin form and the REST /settings endpoint; options absent from the submitted form keep their stored value instead of being wiped
* Cleanup: the legacy admin bundle (assets/js/admin.js, assets/css/admin.css and the OraculoAdmin localized object) is gone

= 2.1.0 =
* Security: escape all dynamic content injected into HTML by the widgets (AI responses, item titles/snippets, error messages); markdown links restricted to safe URL schemes
* Security: REST endpoints hardened — nonce + per-IP rate limit on public search/chat/feedback, conversation ownership checks, admin-only health endpoint, argument schemas everywhere
* Security: admin page menu requires manage_options; remote image download via WordPress HTTP API
* Refactor: Tainacan Pages integration moved to the plugin's own namespace with the core Singleton_Instance trait, registered under the Tainacan "More" menu; dead legacy admin page removed
* Refactor: all inline template scripts extracted to enqueued files; assets load only where used (plugin admin page, shortcode pages, floating chat)
* Refactor: public widgets now use the oraculo/v1 REST API exclusively; nopriv admin-ajax handlers removed
* Dev: strict phpcs ruleset (WordPress-Core/Docs/Extra + Security + PHPCompatibilityWP) with composer tooling; declare(strict_types=1) across the codebase; text domain migrated to oraculo-tainacan
* Security: input size caps in the REST schema (query ≤ 500 chars, chat message ≤ 2000, collections ≤ 20) and a site-wide ceiling of 60 AI calls/minute across all IPs
* Security: the daily oraculo_cleanup_old_data cron finally has a handler — search logs (90d), conversations/messages (30d) and expired memory are purged instead of growing without bound
* Fix: unchecking "show sources" / "show similarity" now persists; the missing key used to fall back to the default instead of false
* Fix: settings sanitization unified in SettingsSanitizer for both the admin form and the REST /settings endpoint; options absent from the submitted form (search/chat prompts, unused provider models) keep their stored value instead of being wiped
* Fix: the AI search tab now shows the real message on rate limit / disabled feature / invalid nonce instead of reporting "no items found"
* Cleanup: the legacy admin bundle (assets/js/admin.js, assets/css/admin.css and the OraculoAdmin localized object) is gone — every handler it registered was either superseded by the per-tab scripts or bound to selectors no template renders

= 2.1.0 =
* New: Tainacan theme integration — an "AI Search" tab is injected next to the default search field on all Tainacan items lists (collection archives, repository archive, taxonomy term archives and pages using the faceted search block/shortcode)
* New: AI answer panel below the search bar with staged loading feedback, suggested questions, related items grid, feedback buttons and a "refine in traditional search" action
* New: settings tab "Tema Tainacan" (enable/disable, tab label, placeholder, search scope, suggestions)
* New: deep link support — `?oraculo_q=question` opens the AI tab and runs the search automatically
* The integration follows the active theme through the --tainacan-* CSS variables and keeps state across Vue re-renders of the Tainacan items list

= 2.0.3 =
* Harden chat textarea color/background against theme resets

= 2.0.2 =
* Fix chat title color (h3) overridden by WordPress theme

= 2.0.1 =
* Fix text contrast issues in frontend and chat widget
* Fix invisible text in chat input (white on white)
* Fix invisible text in user messages (navy on navy)
* Fix chat header subtitle contrast (dark on dark)
* Improve placeholder and auxiliary text contrast to pass WCAG AA
* Fix invalid CSS declaration in search hero

= 2.0.0 =
* Complete rewrite with service-oriented architecture and PSR-4 namespaces
* Requires PHP 8.0 and WordPress 6.0
* Integration with Tainacan 1.0+ via Pages API (\Tainacan\Pages)
* New providers: Claude (Anthropic) and Groq
* Conversation memory with summaries and facts extracted per session
* Customizable prompts per collection
* Webhooks for external integrations
* Export of conversations and analytics
* Multimodal search and smart suggestions
* Expanded REST API (20 endpoints under /oraculo/v1)
* Redesigned admin dashboard with tabs (Dashboard, Analytics, Indexing, Settings, Debug)

= 1.0.0 =
* Initial release
* Semantic search with RAG
* Integrated AI chat
* Support for 4 AI providers
* Analytics dashboard
* Initial Tainacan integration

== Upgrade Notice ==

= 2.0.1 =
Fixes accessibility contrast issues in frontend and chat. Update recommended.

= 2.0.0 =
Major rewrite. Requires PHP 8.0+, WordPress 6.0+ and Tainacan 1.0+. New tables are created automatically on activation; existing settings are preserved.
