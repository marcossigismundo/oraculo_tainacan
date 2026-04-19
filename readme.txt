=== Oraculo Tainacan ===
Contributors: tainacancommunity
Tags: tainacan, ia, busca semantica, rag, chatbot, openai, gemini, claude, ollama
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Busca semantica em linguagem natural com IA para acervos Tainacan. RAG com multiplos provedores (OpenAI, Gemini, Claude, DeepSeek, Groq, Ollama).

== Descricao ==

O Oraculo Tainacan adiciona capacidades de Inteligencia Artificial ao Tainacan, permitindo:

* **Busca Semantica (RAG)**: Encontre itens por significado, nao apenas por palavras-chave
* **Chat com IA**: Converse sobre o acervo com memoria de conversa e fatos extraidos
* **Indexacao Vetorial**: Gere embeddings dos itens para busca por similaridade
* **Multiplos Provedores**: OpenAI, Google Gemini, Claude (Anthropic), DeepSeek, Groq e Ollama (local)
* **Analytics**: Acompanhe metricas de uso, buscas, feedback e desempenho
* **Prompts por colecao**: System/search/chat prompts customizaveis por colecao
* **Webhooks**: Dispare integracoes externas em eventos do plugin
* **Exportacao**: Exporte conversas e relatorios de analytics
* **Busca multimodal**: Suporte a buscas que combinam texto e outros sinais
* **Sugestoes inteligentes**: Perguntas sugeridas dinamicas por contexto
* **WP-CLI**: Comandos para indexacao em lote, estatisticas e manutencao
* **REST API**: 20 endpoints sob /wp-json/oraculo/v1

== Requisitos ==

* WordPress 6.0 ou superior
* PHP 8.0 ou superior
* Plugin Tainacan 1.0+ instalado e ativado (integracao via API de paginas)
* Chave de API de pelo menos um provedor de IA, ou Ollama rodando localmente

== Instalacao ==

1. Faca upload do plugin pelo menu Plugins > Adicionar Novo > Fazer Upload
2. Ative o plugin atraves do menu 'Plugins' no WordPress
3. Acesse Tainacan > Oraculo IA no menu administrativo
4. Configure sua chave de API na aba Configuracoes
5. Indexe suas colecoes na aba Indexacao

== Configuracao ==

1. **Escolha o Provedor de IA**: OpenAI, Gemini, Claude, DeepSeek, Groq ou Ollama
2. **Configure a API Key**: Insira a chave do provedor escolhido
3. **Selecione o Modelo**: Escolha modelo de chat e de embeddings
4. **Ajuste Parametros**: Temperature, max tokens, similarity threshold, batch size
5. **Ative Funcionalidades**: Chat, busca, analytics e feedback sao ligaveis individualmente

== Uso ==

**Shortcodes:**

* `[oraculo_search]` - Widget de busca semantica
* `[oraculo_chat]` - Widget de chat com IA

**Parametros do shortcode de busca:**

* `collection` - ID da colecao (opcional, usa todas se nao especificado)
* `placeholder` - Texto do placeholder do campo de busca
* `show_suggestions` - Mostrar sugestoes (true/false)

Exemplo: `[oraculo_search collection="123" placeholder="O que voce procura?" show_suggestions="true"]`

== Comandos CLI ==

* `wp oraculo index --collection=<id>` - Indexa uma colecao
* `wp oraculo reindex --all` - Reindexa todas as colecoes
* `wp oraculo stats` - Estatisticas de uso
* `wp oraculo clear-cache` - Limpa cache de buscas

== REST API ==

Namespace: `/wp-json/oraculo/v1`. Principais rotas:

* `POST /search` - Busca semantica
* `POST /chat` - Mensagem de chat
* `GET /conversations` - Listar conversas
* `POST /feedback` - Registrar feedback
* `POST /indexing/start` - Iniciar indexacao
* `GET /indexing/status` - Status da indexacao
* `GET /analytics` - Metricas agregadas
* `GET /providers` - Provedores disponiveis
* `POST /providers/test` - Testar conexao com provedor
* `GET /health` - Healthcheck

== Provedores de IA Suportados ==

1. **OpenAI** - GPT-4o, GPT-4o-mini, GPT-3.5-turbo + text-embedding-ada-002/3
2. **Google Gemini** - gemini-1.5-flash, gemini-1.5-pro
3. **Claude (Anthropic)** - claude-3.5-sonnet, claude-3-opus/haiku
4. **DeepSeek** - deepseek-chat, deepseek-coder
5. **Groq** - llama-3.1-70b, mixtral-8x7b, gemma2-9b
6. **Ollama** - Qualquer modelo local (privacidade total, embeddings via nomic-embed-text)

== Changelog ==

= 2.0.2 =
* Corrige cor do titulo do chat (h3) que era sobrescrito pelo tema do WordPress

= 2.0.1 =
* Corrige contrastes de texto no frontend e no chat widget
* Corrige texto invisivel no input do chat (branco sobre branco)
* Corrige texto invisivel em mensagens do usuario no chat (navy sobre navy)
* Corrige subtitle do header do chat (escuro sobre fundo escuro)
* Melhora contraste de placeholders e textos auxiliares para passar WCAG AA
* Corrige declaracao CSS invalida no hero de busca

= 2.0.0 =
* Reescrita completa com arquitetura orientada a servicos e namespaces PSR-4
* Requer PHP 8.0 e WordPress 6.0
* Integracao com Tainacan 1.0+ via API de paginas (\Tainacan\Pages)
* Novos provedores: Claude (Anthropic) e Groq
* Memoria de conversa com summaries e fatos extraidos por sessao
* Prompts customizaveis por colecao
* Webhooks para integracoes externas
* Exportacao de conversas e analytics
* Busca multimodal e sugestoes inteligentes
* REST API expandida (20 endpoints sob /oraculo/v1)
* Dashboard admin reformulado com abas (Dashboard, Analytics, Indexacao, Configuracoes, Debug)

= 1.0.0 =
* Versao inicial
* Busca semantica com RAG
* Chat com IA integrado
* Suporte a 4 provedores de IA
* Dashboard com analytics
* Integracao inicial com Tainacan

== Upgrade Notice ==

= 2.0.1 =
Corrige contrastes de acessibilidade no frontend e no chat. Recomendado atualizar.

= 2.0.0 =
Grande reescrita. Requer PHP 8.0+, WordPress 6.0+ e Tainacan 1.0+. Tabelas novas sao criadas automaticamente na ativacao; configuracoes existentes sao preservadas.
