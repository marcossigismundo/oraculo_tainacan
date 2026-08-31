# Análise: limite de itens da busca vetorial em MariaDB/MySQL

**Data:** 2026-08-20 · **Versão analisada:** 2.6.0 · **Método:** benchmark medido (não estimativa)

Estudo fundamentado sobre até quantos itens do acervo Tainacan a solução atual de
armazenamento de vetores em MariaDB/MySQL suporta, com números medidos, os pontos
de ruptura e o caminho de escala.

---

## 1. O desenho atual e por que ele define o limite

A implementação em `VectorStore::search()` não faz busca vetorial *no banco* — o
MariaDB é apenas um depósito. A cada consulta:

1. `SELECT` traz **todas** as linhas da(s) coleção(ões): o embedding em JSON
   (**19.034 bytes** medidos para 1536 dimensões), mais `content_text` e
   `metadata_json` de cada linha;
2. `$wpdb->get_results()` **materializa tudo em RAM** antes de qualquer cálculo
   (não há modo unbuffered);
3. o PHP decodifica cada JSON e calcula o cosseno **interpretado**, dimensão a
   dimensão, recalculando inclusive a norma do vetor armazenado — que é constante
   e poderia ser pré-computada na indexação;
4. ordena tudo (`usort`) e descarta quase tudo (`array_slice`, top-10).

É **O(N)** com constantes pesadas em três eixos simultâneos: transferência
DB→PHP, memória e CPU. Não existe índice que ajude: o MariaDB 10.x/11.4 — o que
roda em hospedagem compartilhada hoje — **não tem tipo `VECTOR` nem função de
distância**. Isso só existe a partir do **MariaDB 11.7** (GA fev/2025); no MySQL,
apenas no HeatWave (nuvem Oracle).

## 2. Números medidos

Vetores sintéticos realistas, 1536 dimensões (perfil `text-embedding-3-small` /
`ada-002`), coleção isolada, medição em processo próprio por tier, mediana de 3
execuções. Ambiente: PHP 8.0.30 CLI (`memory_limit=512M`), MariaDB 10.4.32.

| Itens | Tabela em disco | Busca (mediana) | Pico de RAM¹ | Transferência por busca² |
|---:|---:|---:|---:|---:|
| 500 | ~14 MB | **198 ms** | 84 MB (+20) | ~12 MB |
| 1.000 | ~29 MB | **441 ms** | 106 MB (+42) | ~25 MB |
| 2.500 | 62 MB | **1.389 ms** | 170 MB (+106) | ~60 MB |
| 3.500 | ~100 MB | **1.540 ms** | 212 MB (+148) | ~85 MB |
| 5.000 | ~145 MB | **2.224 ms** | 276 MB (+212) — **fatal com 256M** | ~120 MB |
| 10.000 | 287 MB | **6.477 ms** | 494 MB (+430) | ~245 MB |

¹ baseline do WordPress carregado: 64 MB. ² dados trafegados do MySQL para o PHP
**em cada requisição de busca**.

As taxas são estáveis e permitem extrapolação segura:

- **~29 KB por item** em disco
- **~43 KB por item** de RAM por busca
- **~0,55–0,65 ms por item** de CPU

## 3. Onde estão os muros

**Muro de memória.** Com `memory_limit=256M` (comum em hospedagem
compartilhada), o estouro ocorre entre 3.500 itens (passou, 212 MB) e 5.000
(precisava de 276 MB): teto real **≈ 4.000 itens** — menos ainda no frontend
real, onde tema e outros plugins elevam o baseline. Com 512M (padrão dos planos
Hostinger, como o do servidor de testes): **≈ 10.000**, e esse tier passou
raspando (494 de 512 MB).

**Muro de experiência de uso.** A busca é apenas a 1ª etapa do RAG: somam-se o
embedding da pergunta (~0,5–1 s de API) e a geração do LLM (2–5 s). Acima de
**~3.000 itens** o retrieval sozinho passa de 1,5 s e a resposta total ultrapassa
5 s; em 10.000 itens são 6,5 s **só de varredura** → 10–12 s por pergunta.

**Muro de concorrência.** Cada busca segura o pico inteiro de memória durante a
varredura. Duas buscas simultâneas dobram a pressão — com 256M e 2 usuários
simultâneos, o teto efetivo cai para ~2.000 itens. O rate-limit global (60
chamadas de IA/min) limita o volume, não a simultaneidade. O `hybrid_search`
soma um **segundo** full scan (LIKE com wildcard à esquerda sobre `content_text`,
sem índice possível).

**Atenuantes que já existem.** O cache por transient (1 h, versionado pelo
índice) absorve perguntas repetidas — mas nunca a primeira. O escopo por coleção
na aba do tema faz o N efetivo ser o **da coleção**, não do acervo inteiro — mas
o chat e o widget buscam em todas as coleções por padrão.

## 4. Veredito prático

| Faixa de itens (no escopo pesquisado) | Situação |
|---|---|
| **até ~1.500** | Confortável em qualquer hospedagem (≤0,6 s, RAM folgada). |
| **1.500 – 3.500** | Funciona, mas retrieval de 0,7–1,6 s; exige 256M+ com pouca concorrência. |
| **3.500 – 10.000** | Só com 512M+; experiência ruim (2–6,5 s) e 85–245 MB trafegados por busca. Não recomendado para uso público. |
| **acima de 10.000** | Inviável no desenho atual, em qualquer hospedagem compartilhada. |

Contexto Tainacan: acervos institucionais de 10k–100k+ itens são comuns. **A
solução atual atende bem o acervo pequeno/médio; não escala para o acervo
institucional típico.**

## 5. Escada de mitigação

### 5.1 Duas fases + norma pré-computada
Sem migração de dados: buscar apenas `id + embedding` na varredura e os detalhes
somente do top-k; gravar a norma do vetor na indexação. Corta ~20% de
RAM/transferência e ~30% de CPU. Ganho modesto — não muda a classe do problema.

### 5.2 BLOB float32 empacotado
`pack('g*')` → 6 KB por vetor em vez de 19 KB. Ganho de ~3× em transferência e
RAM: teto de 256M vai a ~12k itens, o de 512M a ~30k. A latência continua O(N)
(~9 s em 30k) — a CPU passa a ser o gargalo. Exige migração dos vetores.

### 5.3 Redução de dimensões
`text-embedding-3-small` aceita `dimensions: 512` nativamente (Matryoshka), com
perda mínima de qualidade → 3× em **tudo** (disco, RAM, CPU). Combinado com 5.2:
**~9×** — teto de 256M ≈ 35k itens com ~3,5 s. Exige reindexar o acervo.

### 5.4 MariaDB 11.7+ nativo
Tipo `VECTOR` com índice HNSW e `VEC_DISTANCE_COSINE`: resolve de verdade —
O(log N), sem trazer nada para o PHP. Depende de o host oferecer 11.7+ (raro em
compartilhada hoje, mas é a rota nativa de futuro).

### 5.5 Serviço externo de vetores (recomendada) — detalhada abaixo

**Rota recomendada:** para o horizonte imediato do acervo de testes, nada a
fazer. Se a meta é acervo real de museu/biblioteca (>5k itens), ir direto para a
5.5 — o padrão arquitetural já existe no próprio plugin — usando 5.2+5.3 apenas
como ponte se o serviço externo demorar.

---

## 6. Detalhamento da solução 5.5: serviço externo de vetores

### 6.1 O problema em uma imagem

Hoje, a cada pergunta, o plugin faz o equivalente a:

> Tirar **todos os livros das estantes**, empilhar na mesa, folhear **um a um**
> comparando com o pedido do visitante, escolher os 10 melhores e devolver o
> resto. **A cada pergunta.**

Com 500 livros isso leva um instante. Com 10.000, a mesa quebra (o estouro de
memória medido) e o visitante espera 6 segundos só nessa etapa. Acontece porque o
MySQL/MariaDB é ótimo para guardar textos e números, mas **não sabe comparar
"significados"** (vetores) — então quem compara é o PHP, do jeito braçal.

### 6.2 A ideia

Existem bancos feitos **especialmente** para comparar significados — o mais usado
é o **pgvector** (extensão do PostgreSQL). Ele tem um índice (HNSW) que funciona
como um **catálogo por assunto pré-organizado**:

> Em vez de folhear todos os livros, vai-se direto à seção certa, depois à
> prateleira certa, e pegam-se os 10 melhores. Não importa se a biblioteca tem
> 10 mil ou 10 milhões de livros — o caminho até a prateleira continua curto.

A busca que hoje leva 6,5 s com 10.000 itens levaria **poucos milissegundos**, e
continuaria assim com 1 milhão de itens. O servidor WordPress deixa de carregar
qualquer coisa em memória para isso.

### 6.3 Por que essa solução está "metade pronta" aqui

A **API de IA do IBRAM** (`gitlab.museus.gov.br/tainacan-ia/ai-api`), já
integrada ao plugin para a busca visual CLIP, **é exatamente isso**: um serviço
FastAPI externo com PostgreSQL/pgvector por baixo.

```
Busca visual (CLIP):
WordPress  ->  API do IBRAM  ->  pgvector responde em milissegundos   [OK]

Busca por texto (OpenAI/Gemini/etc.):
WordPress  ->  varre a tabela inteira no MySQL, item por item         [gargalo]
```

A solução é fechar essa assimetria. O trabalho seria:

1. **No serviço do IBRAM** (FastAPI/Python): adicionar endpoint para armazenar e
   buscar vetores de *texto*, análogo ao que já existe para imagens. Pouco
   código, porque pgvector, índice e autenticação já estão lá.
2. **No plugin**: ao indexar um item, além de gravar no MySQL, enviar o vetor ao
   serviço — a fila de indexação automática (`AutoIndexer`) já faz esse passo
   para o CLIP; seria mais uma etapa na mesma fila.
3. **Na busca**: perguntar ao serviço quais os top-k itens mais próximos e
   receber apenas os IDs; o WordPress busca os detalhes desses k itens no MySQL,
   uma consulta trivial por chave primária.

O MySQL continua guardando tudo (títulos, textos, metadados) — apenas deixa de
fazer o serviço para o qual não foi projetado.

### 6.4 Arquitetura resultante

```
+-----------------------------+          +------------------------------+
|  Servidor WordPress         |          |  Serviço de vetores          |
|  (Hostinger, cPanel, etc.)  |  HTTPS   |  (FastAPI + PostgreSQL/      |
|                             | -------> |   pgvector)                  |
|  PHP + MySQL/MariaDB        |          |                              |
|  - Tainacan (itens, mídia)  | <------- |  - só os vetores + IDs       |
|  - Oráculo (config, chat,   |   IDs    |  - índice HNSW               |
|    histórico, cache)        |          |                              |
+-----------------------------+          +------------------------------+
       continua igual                      já existe hoje para o CLIP
```

São dois ambientes — mas o segundo **não precisa estar no seu servidor**, e no
caso do projeto **já está no ar**. Do lado do plugin, toda a "integração com
Postgres" se resume a um campo de texto nas configurações com a URL do serviço
(ver `ClipApiClient::__construct()`, que lê apenas `clip_api_url`). **O WordPress
nunca fala com o PostgreSQL diretamente** — conversa por HTTPS com a API,
exatamente como conversa com a OpenAI.

### 6.5 Opções para hospedar o serviço de vetores

| Opção | Quem mantém | Custo | Observação |
|---|---|---|---|
| **API do IBRAM** (recomendada) | IBRAM | zero | Já funciona para imagens; falta o endpoint de texto. |
| **Serviço gerenciado** (Qdrant Cloud, Pinecone, Supabase) | fornecedor | free tier cobre 100k+ itens | Nada a instalar: cria conta, copia a URL. |
| **Auto-hospedado** (VPS com Docker) | equipe própria | ~R$ 30–60/mês | Só se houver exigência de dados na infraestrutura própria. |

### 6.6 O que NÃO muda

- A hospedagem do WordPress continua PHP + MySQL comum, **sem requisito novo**.
- Hospedagem compartilhada não oferece PostgreSQL — e não precisa, pois o serviço
  fica fora.
- Os dados do acervo (itens, imagens, metadados) **continuam todos no MySQL do
  Tainacan**. Para o serviço externo vão apenas vetores e o ID do item — números,
  sem conteúdo legível.
- Com o serviço fora do ar, o plugin pode cair automaticamente de volta ao modo
  MySQL atual: a busca fica lenta, mas não quebra.

### 6.7 Prós e contras

| | |
|---|---|
| + Escala real | de milhares para **milhões** de itens sem degradar |
| + Alivia o WordPress | memória e CPU do site livres; menos risco sob buscas simultâneas |
| + Caminho já aberto | conexão, autenticação e padrão de código já existem (cliente CLIP) |
| − Dependência externa | queda do serviço derruba a busca por IA (mitigável com fallback) |
| − Não é só o plugin | exige mexer no serviço do IBRAM (ou hospedar uma cópia) |
| − Infraestrutura | alguém precisa manter o serviço no ar (hoje o do IBRAM já está) |

---

## 7. Metodologia

Benchmark executado localmente com dois scripts descartáveis:

- **seed**: insere N linhas sintéticas em `wp_oraculo_vectors` sob uma coleção
  isolada (`collection_id = 999001`, `item_id` a partir de 900000), vetores de
  1536 dimensões com 9 casas decimais (tamanho JSON realista, 19.034 bytes),
  `content_text` de ~900 caracteres, `metadata_json` representativo; INSERTs
  multi-linha em lotes de ~400 KB; idempotente (completa até N).
- **run**: executa `VectorStore::search($query, [999001], 10, 0.0)` três vezes no
  **mesmo processo**, reporta mediana de tempo, `memory_get_peak_usage(true)` e o
  tamanho físico da tabela via `information_schema`.

Cada tier rodou em **processo próprio**, para leitura limpa do pico de memória.
O muro de 256M foi localizado por bissecção (`php -d memory_limit=256M`: 3.500
passa com 212 MB; 5.000 falha). Ao final, as 10.000 linhas sintéticas foram
removidas e a tabela reotimizada (`OPTIMIZE TABLE`), voltando a 0,1 MB — nenhum
resíduo em base de dados.
