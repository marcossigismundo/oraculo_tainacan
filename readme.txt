=== Oraculo Tainacan ===
Contributors: desenvolvido para IBRAM
Tags: tainacan, ia, busca semantica, rag, chatbot
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin de busca semantica com IA para colecoes do Tainacan. Implementa RAG (Retrieval-Augmented Generation) para buscas inteligentes no acervo.

== Descricao ==

O Oraculo Tainacan e um plugin que adiciona capacidades de Inteligencia Artificial ao Tainacan, permitindo:

* **Busca Semantica**: Encontre itens por significado, nao apenas por palavras-chave
* **Chat com IA**: Converse sobre o acervo e obtenha respostas contextualizadas
* **Indexacao Vetorial**: Crie embeddings dos itens para busca por similaridade
* **Multiplos Provedores de IA**: Suporte para OpenAI, Google Gemini, Claude (Anthropic), DeepSeek, Groq e Ollama (local)
* **Analytics**: Acompanhe metricas de uso, buscas e satisfacao

== Requisitos ==

* WordPress 5.9 ou superior
* PHP 7.4 ou superior
* Plugin Tainacan 1.0+ instalado e ativado
* Chave de API de pelo menos um provedor de IA (OpenAI, Gemini, Claude, etc.)

== Instalacao ==

1. Faca upload do arquivo `oraculo-tainacan.zip` pelo menu Plugins > Adicionar Novo > Fazer Upload
2. Ative o plugin atraves do menu 'Plugins' no WordPress
3. Acesse Tainacan > Oraculo IA no menu administrativo
4. Configure sua chave de API em Configuracoes
5. Indexe suas colecoes na aba Indexacao

== Configuracao ==

1. **Escolha o Provedor de IA**: Selecione entre OpenAI, Gemini, Claude, DeepSeek, Groq ou Ollama
2. **Configure a API Key**: Insira sua chave de API do provedor escolhido
3. **Selecione o Modelo**: Escolha o modelo de IA que deseja usar
4. **Ajuste Parametros**: Configure temperatura, max tokens e outras opcoes
5. **Ative Funcionalidades**: Habilite chat, analytics e busca conforme necessario

== Uso ==

**Shortcodes Disponiveis:**

* `[oraculo_search]` - Widget de busca semantica
* `[oraculo_chat]` - Widget de chat com IA

**Parametros do Shortcode de Busca:**

* `collection` - ID da colecao (opcional, usa todas se nao especificado)
* `placeholder` - Texto do placeholder do campo de busca
* `show_suggestions` - Mostrar sugestoes (true/false)

**Exemplo:**
`[oraculo_search collection="123" placeholder="O que voce procura?" show_suggestions="true"]`

== Comandos CLI ==

O plugin inclui comandos WP-CLI para operacoes em lote:

* `wp oraculo index --collection=<id>` - Indexa uma colecao
* `wp oraculo reindex --all` - Reindexa todas as colecoes
* `wp oraculo stats` - Mostra estatisticas de uso
* `wp oraculo clear-cache` - Limpa cache de buscas

== Provedores de IA Suportados ==

1. **OpenAI** - GPT-4, GPT-4o, GPT-3.5-turbo (recomendado para producao)
2. **Google Gemini** - gemini-pro, gemini-1.5-flash, gemini-1.5-pro
3. **Claude (Anthropic)** - claude-3-opus, claude-3-sonnet, claude-3-haiku
4. **DeepSeek** - deepseek-chat, deepseek-coder
5. **Groq** - llama-3.1-70b, mixtral-8x7b, gemma2-9b (rapido e economico)
6. **Ollama** - Qualquer modelo local (privacidade total)

== Changelog ==

= 1.0.0 =
* Versao inicial
* Busca semantica com RAG
* Chat com IA integrado
* Suporte a 6 provedores de IA
* Dashboard com analytics
* Integracao completa com Tainacan

== Upgrade Notice ==

= 1.0.0 =
Primeira versao estavel. Requer Tainacan 1.0+.
