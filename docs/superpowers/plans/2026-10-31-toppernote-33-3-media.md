# Toppernote 3 — Media e imagens Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Imagens nítidas logo após o envio, envio otimista, visualizador em ecrã inteiro e conta-gotas na paleta.

**Architecture:** Escolha da miniatura numa função pura (`CCL.thumbPick`); o cartão reavalia ao mudar o zoom e quando a referência ganha miniaturas novas (poll/RT). Envio otimista: cartão temporário local (`id` negativo) trocado pelo do servidor. Visualizador: camada `.cc-viewer` em `cc.js`. Conta-gotas: canvas a partir do original pedido ao mesmo domínio (`cc_file.php?a=…&s=0`), paleta manual guardada em `cc_assets.palette` (JSON) com `m:1`.

**Tech Stack:** JS, PHP 8 + GD, Playwright.

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §33.5, §33.10 (envio otimista, visualizador), §33.11

## Global Constraints
- Miniaturas existentes: 256, 768, 1600 px (`CC_THUMBS`); sem miniaturas → original (`s=0`).
- Conta-gotas: lupa ×8, média 3×3 (Alt = 1 px), atalho I, Shift = várias, Esc sai; leitura de píxeis só do mesmo domínio.
- Visualizador: duplo clique ou Espaço abre; roda/pinça faz zoom; ← → imagem anterior/seguinte do Topperboard; Esc fecha.

## Review Focus
1. Imagem enviada a meio de um zoom de 300% → a miniatura certa aparece sem clicar.
2. Envio que falha (rede cortada) → cartão fica com «Tentar outra vez»; ao tentar e resultar, troca para o do servidor.
3. HEIC/formatos que o browser não lê → cartão otimista com ícone e nome, nunca em branco.
4. Conta-gotas numa imagem de 8000 px → não bloqueia a página (amostra por pedido ao canvas reduzido + leitura pontual no original por `createImageBitmap` com recorte).
5. Clique numa cor da paleta sem permissão de clipboard (Safari) → mostra o #HEX selecionável (como o `TD.linkBox`).

---

### Task 1: Imagens desfocadas (§33.5) — debugging primeiro

**Interfaces — Produces:** `CCL.thumbPick(tw, cssW, zoom, dpr) -> number` (largura da lista ≥ `cssW*zoom*dpr`; 0 = original quando `tw` vazio).

- [ ] Reproduzir num teste de UI (`tests/ui/cc_image_test.js`): enviar uma PNG 2400×1600 para um Topperboard a 200% de zoom; ler o `s=` do `<img>` depois de estabilizar; esperado `1600`. Recarregar e repetir. Registar a causa (systematic-debugging, fase 1) antes de mudar código.
- [ ] Testes `thumbPick`: `([256,768,1600],280,1,2)→768`; `([256,768,1600],280,3,2)→1600`; `([],280,1,1)→0`.
- [ ] Correção na causa encontrada; mais: ao chegar uma versão nova da referência (poll/RT/jobs) com `tw` diferente, `setThumb` corre; `srcset` com as 3 larguras + `sizes` pela largura do cartão × zoom.
- [ ] UI test passa. Commit.

### Task 2: Envio otimista (§33.10)

- [ ] UI test: largar uma imagem → em < 300 ms existe `.ccc.uploading` com `<img src="blob:">` e barra de progresso; quando acaba, o cartão tem `data-id` positivo e o `blob:` foi libertado; com o servidor a devolver 500 (rota interceptada) → `.ccc.failed` com botão «Tentar outra vez» que reenvia.
- [ ] `addFiles` cria `{id:-n, type, x, y, w, local:{url, name, p}}` em `B.cards`; `uploadFile` informa progresso; troca pelo cartão do servidor; nada de `reload()` no fim. Commit.

### Task 3: Visualizador interno (§33.10)

- [ ] UI test: duplo clique numa imagem abre `.cc-viewer` (fundo escuro) com a imagem a 1600/original; → passa à imagem seguinte do Topperboard (ordem de leitura y/x); roda com Ctrl faz zoom; Esc fecha e o foco volta ao cartão. Espaço com uma imagem selecionada também abre.
- [ ] Implementar; vídeo no visualizador com controlos (som só com ▶ — ver plano 5). Commit.

### Task 4: Conta-gotas e paleta (§33.11)

**Interfaces — Produces:** `cc_api.php?a=palette_add {asset, hex, name}` / `palette_edit {asset, hex, name|hide|remove}` (só as manuais se removem; automáticas escondem-se com `h:1`); `CCL.avg3(data, w, x, y, alt) -> '#RRGGBB'`; `CCL.hexTo(hex, 'rgb'|'hsl') -> string`.

- [ ] PHP test `tests/cc_palette_test.php`: adicionar `#7C7774` com nome «Pele» → `palette` tem `{hex, name, m:1, by}`; remover automática → recusa (400); esconder automática → `h:1`; a pesquisa por cor da Biblioteca encontra a manual.
- [ ] Testes `avg3`/`hexTo`.
- [ ] Botão «+ Escolher da imagem» (I) no detalhe; modo só enquanto escolhe; lupa ×8 (canvas 15×15 px → 120×120) com #HEX; clique adiciona (Shift fica no modo); clique numa cor copia (aviso «#7C7774 copiado»); menu ⋯ (RGB, HSL, renomear, remover/esconder, «Criar Amostra de cor»); «Copiar paleta». Vídeo: no fotograma em pausa.
- [ ] UI test: imagem com quadrante vermelho → escolher dá `#DC2828`±; Alt dá o píxel exato. Commit.
