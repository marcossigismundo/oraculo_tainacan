# Plano: busca no conteúdo de PDFs anexos e no OCR

> Estudo de 2026-10-23, sobre a 2.9.0. Nada implementado ainda.

## Objetivo

A busca com IA e a BIA passam a encontrar itens pelo **conteúdo** dos PDFs do item:
o documento principal e os demais anexos, inclusive PDFs e imagens escaneados (via OCR).
A resposta aponta o trecho e a página de onde veio a informação.

## O que já existe (verificado no código e no darkgreen-yak)

| Fonte de texto | Quem produz | Onde fica | Cobre |
|---|---|---|---|
| `document_content_index` (post meta do item) | Core Tainacan (`Media::index_pdf_content`, Smalot) quando a opção `tainacan_option_index_pdf_content` está ligada | item | só o **documento principal**, só PDF com camada de texto |
| idem, via filtros `tainacan_item_supports_document_content_extraction` / `tainacan_extract_document_content` | tainacan-ai (`DocumentContentIndexHook`, commit d798413) | item | documento principal PDF com texto e documento URL; **não** grava texto de PDF escaneado (modo visual devolve `false`) |
| `_tainacan_ocr_text` (post meta do anexo) | tainacan-ocr-search 1.0.1 (OCRmyPDF + Tesseract, local) | cada anexo | todos os anexos PDF/imagem do item, inclusive escaneados; regrava o PDF com camada de texto e re-salva o item |
| `DocumentProcessor` do Oráculo (pdftotext → Smalot, docx, imagem) | Oráculo | — | código pronto, **não usado** na indexação |

O Oráculo hoje gera **1 vetor por item** com título + descrição + metadados
(`format_item_for_indexing()`). A opção "document" existe na tela de indexação e é
gravada em `index_fields`, mas `format_item_for_indexing()` a ignora.

Estado do darkgreen-yak em 2026-10-23: core Tainacan 1.3.0 (tem
`Item::get_document_content_index()`); `tainacan_option_index_pdf_content` = false;
tainacan-ai-main 0.2.0 instalado e **inativo**; tainacan-ocr-search **não instalado**;
`pdftotext`, `ocrmypdf` e `tesseract` instalados no servidor; **nenhum PDF** anexado a
itens (0 em todas as coleções). É preciso um acervo com PDFs para testar.

## Por que não basta "colar o PDF no texto do item"

- Um PDF de 100 páginas tem dezenas de milhares de tokens: passa do limite de entrada
  do modelo de embedding e, mesmo truncado, o vetor único dilui o assunto do item.
- A resposta precisa citar *onde* está a informação (arquivo e página), não só o item.

Por isso o conteúdo vira **trechos (chunks)** com vetor próprio, e a busca agrega os
trechos por item.

## Desenho proposto

### 1. Coleta do texto (ordem de preferência, sem duplicar)

Para cada item, montar a lista de "documentos de texto":

1. Documento principal: `get_document_content_index()` (core ou tainacan-ai).
2. Cada anexo PDF/imagem (`post_parent = item`): `_tainacan_ocr_text`, se houver.
3. Anexo PDF sem texto ainda: extração própria com `pdftotext -layout`, página por
   página (`-f N -l N`) para guardar o número da página, com Smalot como fallback.
   O resultado fica em cache na meta do anexo (`_oraculo_text` + tamanho/mtime do
   arquivo, igual ao controle de mudança do core).
4. PDF/imagem sem camada de texto (pdftotext vazio): marcar como "precisa de OCR".
   Se o tainacan-ocr-search estiver ativo, enfileirar no processador dele; senão,
   mostrar a contagem na tela de indexação ("N anexos escaneados sem OCR").

O OCR fica com o tainacan-ocr-search, que já é local, livre e sem custo por página,
em vez de duplicar Tesseract no Oráculo.

### 2. Trechos e vetores

- Tabela nova `{prefix}oraculo_chunks`: `id, item_id, collection_id, source`
  (`metadata` | `document` | `attachment`), `attachment_id, page_from, page_to, chunk_no,
  text, embedding (binário float32), embedding_bits, content_hash, updated_at`.
  Índices: `(item_id)`, `(collection_id)`, `(attachment_id)`.
- Trechos de ~400–600 tokens com sobreposição de ~15%, sem quebrar página quando
  der (a página vai para a citação).
- Embedding em **float32 empacotado** (`pack('g*')`, ~6 KB) em vez de JSON (~29 KB):
  um PDF de 100 páginas vira ~100 trechos; em JSON seriam ~3 MB por PDF.
- O vetor atual do item (metadados) continua existindo e entra como `source=metadata`
  ou fica na tabela antiga durante a transição.
- `content_hash` por trecho: reindexar um item só reembeda o que mudou.

### 3. Busca

- Mesma varredura em duas fases (Hamming → cosseno exato), agora sobre os trechos.
- Agregação por item: nota do item = melhor trecho (com um pequeno bônus quando vários
  trechos do mesmo item batem), e o top-k continua sendo de **itens**.
- Contexto do LLM: para cada item, os 1–2 melhores trechos (com arquivo e página) em
  vez do resumo de metadados.
- Cartão do item: linha "No PDF *relatorio.pdf*, p. 12: …trecho…", com link para o
  anexo (`#page=12` para PDF no navegador).
- Pré-filtro opcional por palavra-chave (FULLTEXT em `text`) para consultas com termos
  raros (nomes, números de processo), onde embedding vai mal.

### 4. Gatilhos (indexação automática)

- Já coberto: salvar item (o tainacan-ocr-search re-salva o item depois do OCR).
- Novos: `add_attachment` / `edit_attachment` / `delete_attachment` com
  `post_parent` = item Tainacan; `updated_post_meta` de `_tainacan_ocr_text` e de
  `document_content_index` → enfileirar o item-pai na fila atual (`AutoIndexer`).
- Remoção de anexo apaga os trechos dele no ato (como a remoção de item já faz).

### 5. OCR do tainacan-ai (complemento, não base)

Hoje o tainacan-ai não produz texto de páginas escaneadas: no modo visual ele manda a
imagem ao modelo para sugerir metadados, e `map_extraction_to_index_text()` devolve
`false`. Para manuscritos ou layouts difíceis, onde o Tesseract erra, daria para
acrescentar ao tainacan-ai um modo "transcrever" (modelo com visão → texto gravado
em `document_content_index`). Isso é mudança no repositório **upstream**
(`tainacan/tainacan-ai`): só com confirmação do usuário, via fork + PR. Custo por
página no provedor; usar como exceção, não como OCR em massa.

## Fases

| Fase | Entrega | Esforço |
|---|---|---|
| F1 | Usar o que já existe: honrar `document` em `index_fields` com `document_content_index` + `_tainacan_ocr_text` (truncado, 1 vetor por item); aviso na tela de indexação quando a opção do core está desligada | pequeno |
| F2 | Tabela de trechos, chunking com página, embedding binário, busca agregada por item, trecho + página no cartão e no contexto do LLM | grande (o centro do trabalho) |
| F3 | Extração própria de anexos sem texto (pdftotext por página, cache) + integração com a fila do tainacan-ocr-search + contagem de "precisa de OCR" | médio |
| F4 | (opcional, upstream) modo "transcrever" do tainacan-ai para escaneados difíceis | médio, depende de PR aceito |

## Decisões pendentes

1. Onde testar: o darkgreen-yak não tem PDFs. Usar outro site com PDFs reais ou
   importar uma amostra (ex.: 20–50 PDFs, metade escaneados).
2. Instalar o tainacan-ocr-search no site de teste como motor de OCR (recomendado).
3. Tamanho do trecho e limite por PDF (ex.: indexar no máximo as primeiras 300 páginas).
4. F4 (tainacan-ai upstream): seguir ou não.
