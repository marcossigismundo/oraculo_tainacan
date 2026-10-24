# Memória do projeto (decisões, armadilhas, pendências)

> Para retomar o trabalho em outra máquina ou instância. Complementa o
> [CONTEXT.md](CONTEXT.md), que descreve a arquitetura. Atualizado em 24/10/2026 (versão 2.10.2).
> Endereços, credenciais e comandos de servidor ficam no repositório privado de infraestrutura,
> não aqui (este repositório é público).

## Onde está

- Repositório `marcossigismundo/oraculo_tainacan`, branch `main`. A pasta local se chama
  `oraculo_tainacan`; no site, o plugin fica em `oraculo-tainacan` (com hífen).
- Instalado em dois sites de teste: o do TainacanBiblio (sem busca em documentos) e o
  laboratório de OCR, que tem a coleção "OCR — documentos digitalizados (laboratório)".

## Linha do tempo das decisões

- **2.7.x (22/10/2026), velocidade e layout.** Busca vetorial em duas fases: assinatura binária
  (`embedding_bits`, Hamming) seleciona candidatos e só eles recebem o cosseno exato (20× o limite,
  mínimo 400). Numa coleção de 14,5 mil vetores, a busca foi de 9–18 s para ~0,2 s com recall@20 de
  99%, e o chat de ~20 s para ~5 s (o resto é o modelo). A resposta da "Busca com IA" virou síntese
  com citações [n] ligadas a cartões numerados. Motivo: a resposta antiga misturava URLs soltas no
  texto com cartões repetidos, e o "0,6 s" que parecia rápido era cache.
- **2.8.x (22/10/2026), BIA coerente com a busca.** A BIA usa o mesmo escopo
  (`ThemeIntegration::get_search_scope()`) e a mesma consulta reduzida ao assunto
  (`retrieval_query()`). "livros sobre o nordeste" × "o que tem para nordeste?" passou de 0 para
  10 itens em comum. Painel ocupa a linha inteira da listagem, pode ser recolhido e reaberto sem
  nova busca, tons pastel com cartões brancos (contraste).
- **2.10.x (23–24/10/2026), busca dentro dos documentos.** `DocumentIndexer` + `ChunkStore` +
  fila `_oraculo_docs_pending`: lê o texto do OCR (plugin Tainacan OCR Search), de PDFs com camada
  de texto (pdftotext) e o `document_content_index` do núcleo; trechos por página; cartões e BIA
  citam arquivo e página. Decisão: o Oráculo **não faz OCR**, só consome — o OCR fica no Tainacan
  OCR Search (local, livre, serve também à busca nativa).

## Armadilhas que já custaram tempo

- **Itens do Tainacan têm `exclude_from_search`.** Consulta com `post_type => 'any'` não acha
  nenhum item. Use a lista explícita (`AutoIndexer::get_item_post_types()`).
- **Cache da busca.** A chave leva `format` (5 desde a 2.10.0). Mudou o formato da resposta,
  incremente. Ao medir velocidade, "Respondido em <1 s" costuma ser cache.
- **Prompt salvo antigo.** Sites com prompt de sistema salvo de versões antigas pedem "forneça o
  link"; por isso as regras de formato vão no **fim** do prompt da mensagem, onde prevalecem.
- **Coleção sintética do site do TainacanBiblio.** ~976 títulos "X sobre/para a Y nordestina" com
  metadados aleatórios: em assunto amplo o top-10 é amostra, e respostas "estranhas" (filatelia,
  vidro) vêm dos dados, não do plugin.
- **strict_types + `$wpdb`.** `$wpdb` devolve string; cast na origem (ver CONTEXT.md).
- **Página citada** é a do PDF; livros com numeração impressa própria podem diferir.

## Pendências

- Teste de navegador com usuário logado na "Busca com IA" (só o visitante foi testado).
- A varredura dos trechos é O(nº de trechos) por consulta; acervo com muitos PDFs grandes pede o
  serviço vetorial externo descrito em `ANALISE-LIMITE-VETORES.md`.
- Existe uma 3.0.0 inativa num terceiro site de teste que não está no GitHub (registrada no
  repositório de infraestrutura).
