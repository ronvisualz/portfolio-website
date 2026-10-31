# Toppernote 4 — Seleção e copiar/colar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Alt + arrastar (e toque longo no iPad) seleciona cartões; Ctrl/Cmd C, X, V, D com qualquer seleção, entre Topperboards e separadores, na posição do cursor.

**Architecture:** Geometria e embalagem do que se copia em `cc_logic.js` (`CCL.marqueeHit`, `CCL.clipPack`, `CCL.clipPlace`). Área de transferência: `navigator.clipboard.write` com `text/plain` (resumo legível) + `web text/x-toppernote` quando existir; e sempre `localStorage['cc.clip']` (com `ts`) para colar entre separadores do mesmo browser; o servidor cria as cópias numa ação nova `cc_api.php?a=cards_paste` (copia o conteúdo e a referência — nunca o ficheiro — e refaz as setas entre os copiados).

**Tech Stack:** JS, PHP 8, Playwright (rato e `hasTouch`).

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §33.6, §33.12, §33.10 («colar no mesmo Topperboard desloca ligeiramente a cópia»)

## Global Constraints
- Arrastar no fundo continua a mover o ecrã. Alt/Option + arrastar = retângulo (contorno tracejado, fundo azul translúcido); Shift soma; Shift + clique alterna; Ctrl/Cmd A tudo; iPad: tocar e manter 0,4 s e arrastar; dois dedos movem/zoom.
- Cursor em mira com Alt sobre o fundo. Alt ao arrastar um cartão continua a desligar o encaixe.
- «N selecionados» + barra de ações (mover, copiar, alinhar, apagar com Anular).
- Colar no mesmo Topperboard sem cursor dentro da tela: desloca +24 px em x e y.

## Review Focus
1. Copiar uma coluna com 5 cartões e colar noutro Topperboard → coluna e os 5 cartões, pela mesma ordem.
2. Copiar duas notas ligadas por seta e uma terceira não selecionada → cola as duas e a seta entre elas; a seta para a terceira não vai.
3. Ctrl X e colar → os originais saem só depois de a cópia existir (nunca se perde nada); Ctrl Z repõe.
4. Colar no Topperboard de outro negócio sem permissão de editar → aviso, nada criado.
5. Ctrl V com texto ou imagem de fora (não do Toppernote) → comportamento de hoje (nota, link, imagem).

---

### Task 1: Seleção com Alt e toque longo (§33.12)

**Interfaces — Produces:** `CCL.marqueeHit(boxes, rect) -> ids` (`boxes` `[{id,x,y,w,h}]`; toca = interseção não vazia).

- [ ] Testes: retângulo que toca a borda de um cartão → incluído; que não toca → não; retângulo com largura/altura negativas (arrasto para trás) normalizado.
- [ ] `down()`: `e.altKey` sobre o fundo → `marq` (em vez de `shiftKey`); `pointerType==='touch'` parado 400 ms sem mexer > 8 px → `marq`; classe `vp.alt` com Alt premido (cursor `crosshair`); barra `.ccb-selbar` com «N selecionados», Copiar, Alinhar ▾ (esquerda/topo/distribuir), Apagar.
- [ ] UI test (rato: Alt+arrastar sobre 2 de 3 cartões → 2 selecionados, barra «2 selecionados»; Shift soma o terceiro; arrastar sem Alt move a vista e não seleciona) e (`hasTouch`: toque longo + arrastar → seleciona). Commit.

### Task 2: Copiar, cortar, colar, duplicar (§33.6)

**Interfaces — Produces:**
- `CCL.clipPack(cards, ids, boardId) -> {v:1, board, items:[{ref, type, x, y, w, h, c, asset, col, ord}], arrows:[{from, to, c}]}` — coordenadas relativas ao canto superior esquerdo da seleção; inclui os filhos das colunas selecionadas; setas só se as duas pontas vão.
- `CCL.clipPlace(pack, at, sameBoard) -> items` — `at` = ponto do mundo do cursor, ou `null` → posição original (+24, +24 se `sameBoard`).
- `cc_api.php?a=cards_paste` POST `{board, items: JSON}` → `{rev, cards}` (valida tipos; referências só se existirem e o utilizador puder ver; sub-Topperboard copiado = cartão que aponta para o mesmo `ref`, nunca duplica o Topperboard).

- [ ] Testes `clipPack`/`clipPlace` (setas, colunas, deslocamento +24) e PHP `tests/cc_paste_test.php` (cola em outro Topperboard; asset inexistente → ignorado; sem editar → 403; seta refeita com os ids novos).
- [ ] Teclas: Ctrl/Cmd C copia (aviso «3 cartões copiados»), X copia e apaga com Anular, V cola na posição do cursor (ou desloca), D duplica (usa `cards_paste` no mesmo Topperboard com deslocamento). Paste de fora mantém-se (`pasteText`/`addFiles`) quando a área de transferência não tem o formato do Toppernote.
- [ ] UI test: dois separadores — copiar em A, colar em B; Ctrl X + V no mesmo; Ctrl D. Commit.
