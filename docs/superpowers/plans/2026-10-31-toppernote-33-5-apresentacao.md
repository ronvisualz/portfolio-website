# Toppernote 5 — Navegação e apresentação Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Abrir sub-Topperboards sem recarregar (zoom, pré-carregar, migalhas, Voltar/Avançar) e som só em Apresentar.

**Architecture:** `viewBoard(boardId, opts)` passa a poder ser reiniciado na mesma página: `openBoard(id, fromCardEl)` faz `history.pushState({b:id}, '', '/admin/toppernote.php?b=' + id)`, anima o zoom para o cartão, desliga o tempo real do anterior (`RT.close()`), carrega o novo (cache de pré-carregamento) e volta a desenhar. `popstate` reabre. Apresentar: controlador de media pequeno dentro de `present()`.

**Tech Stack:** JS (History API, `requestIdleCallback`), Playwright.

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §33.10 (navegação), §33.13

## Global Constraints
- Transição curta (≤ 320 ms; nenhuma com `prefers-reduced-motion`).
- Migalhas «Projeto › Referências › Luz» clicáveis, também sem recarregar.
- Fora de Apresentar nunca há som sozinho; pré-visualização ao passar o cursor continua muda.
- Em Apresentar: vídeo/áudio toca com som ao chegar; pausa ao avançar/recuar; Espaço pausa/retoma; volume e «Sem som» na barra; no fim fica no último fotograma.

## Review Focus
1. Voltar do browser depois de abrir 3 sub-Topperboards seguidos → volta um a um, com a posição/zoom de cada um como estava.
2. Abrir um sub-Topperboard enquanto há uma edição por gravar (nota aberta) → grava antes de sair.
3. Sub-Topperboard sem permissão de ver → aviso, fica onde estava.
4. Apresentar com o browser a recusar `play()` com som → aparece «Tocar» no cartão; nada fica a tocar escondido.
5. Sair de Apresentar com um vídeo a tocar → pára e fica mudo.

---

### Task 1: Abrir sub-Topperboards sem recarregar

- [ ] UI test `tests/ui/cc_nav_test.js`: duplo clique num sub-Topperboard → URL muda para `?b=<filho>`, sem `load` novo da página (marcador `window.__mark` mantém-se), migalhas mostram o filho; Voltar → pai com a mesma vista; Avançar → filho; ligação nas migalhas abre sem recarregar.
- [ ] Implementar `openBoard`, cache `preload(ids)` (em `requestIdleCallback` para os cartões de sub-Topperboard visíveis), gravar edição aberta antes de sair, guardar `{x,y,s}` por id em `sessionStorage`. Commit.

### Task 2: Som só em Apresentar (§33.13)

- [ ] UI test: em Apresentar, ao chegar a um vídeo → `video.muted === false` e `!paused`; seta → anterior pausa; Espaço alterna; «Sem som» põe `muted`; fora de Apresentar, passar o rato → `muted === true`; sair → nada a tocar.
- [ ] Controlador na barra (`.cc-pres`): volume (`input type=range`), «Sem som», estado do media atual; áudio usa o leitor de `cc_audio.js` (`AU.hub`). Commit.
