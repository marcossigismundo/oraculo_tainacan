=== Oráculo Tainacan ===
Contributors: tainacancommunity
Tags: tainacan, ai, search, rag, openai
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered natural-language search and chat for Tainacan digital archives. RAG with multiple providers (OpenAI, Gemini, Claude, DeepSeek, Groq).

== Description ==

Oráculo Tainacan adds Artificial Intelligence capabilities to Tainacan, enabling:

* **Semantic Search (RAG)**: Find items by meaning, not just keywords
* **Tainacan Theme Integration**: An "AI Search" tab injected next to the default search field on Tainacan items lists (collections, repository, taxonomy terms and faceted search block)
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
