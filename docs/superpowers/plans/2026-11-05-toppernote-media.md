# Toppernote — vídeo e MP3 em Apresentar e fora dela (5 nov) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Corrigir o que a equipa encontrou depois da publicação de 5 nov: em Apresentar um vídeo ou MP3 que não é o
diapositivo (no Topperboard real estão dentro de uma coluna) não toca de forma nenhuma; fora de Apresentar o vídeo e o
MP3 não tocam ao mesmo tempo.

**Causa (provada na bancada e no Topperboard 1 de produção — cartões 17 e 18 dentro da coluna 2):**
1. As correções de 2 nov tiraram a pré-visualização ao passar o cursor em Apresentar e só o cartão do diapositivo toca
   (`present()`/`startMedia`); um vídeo dentro de uma coluna nunca é diapositivo → nada o faz tocar.
2. A correção de acessibilidade pôs o ▶ de um cartão de áudio em Apresentar a comandar só o som do diapositivo
   (`presAct` sai cedo noutro cartão) → o MP3 dentro de uma coluna ficou mudo.
3. Fora de Apresentar o vídeo que «toca» é a pré-visualização sem som ao passar o cursor, que pára quando o cursor sai
   (para ir ao ▶ do MP3 o cursor sai do vídeo); e o leitor único de áudio pára quando um vídeo começa (§27).

**Decidido com a equipa (5 nov):** em Apresentar, passar o cursor num vídeo começa-o **com som** e ele toca até ao fim
(clicar pausa/retoma), também dentro de colunas; o ▶ de um MP3 dentro de uma coluna toca. Fora de Apresentar o cartão de
vídeo ganha um **▶ verdadeiro** que toca com som no cartão até ao fim (ou até ❚❚); a pré-visualização sem som ao passar o
cursor continua; **vídeo e MP3 tocam ao mesmo tempo**.

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §33.13, §33.14 (a acrescentar: 5 nov), §27 (leitor único), §10.32.

## Global Constraints
- Textos pt-PT, sem «§» na interface. TDD: o teste falha primeiro. Bateria completa verde antes de cada commit.
- Commits no ramo `claude/exciting-goodall-mtlx1a`; publicar só depois de perguntar.

## Task 1: Leitor único mistura com os vídeos do Toppernote
**Files:** `admin/assets/cc_audio.js` (o ouvinte `play` ignora elementos com `data-mix`).
- [x] Teste (em `tests/ui/cc_media_mix_test.js`): com o MP3 a tocar, um vídeo do cartão começa → o MP3 continua.

## Task 2: ▶ verdadeiro no cartão de vídeo (fora de Apresentar)
**Files:** `admin/assets/cc.js` (cardHtml: `.ccc-play` passa a `<button data-cact="v-play">`; `vAct`; mapa `players`
id → {c, el}; `makeEl` volta a pôr o vídeo no cartão redesenhado; `cull` mantém o cartão — ou a coluna — com um
vídeo a tocar; `openBoard` e entrar em Apresentar param os leitores), `admin/assets/cc.css` (ícone ❚❚ com `.playing`).
- [x] Teste: ▶ do vídeo toca com som (não muted) no cartão; o cursor sair não o pára; ▶ do MP3 → os dois a tocar;
  ▶ outra vez pausa; ao acabar fica parado; a pré-visualização sem som noutro vídeo continua a funcionar.

## Task 3: Apresentar — vídeos e MP3 fora do diapositivo
**Files:** `admin/assets/cc.js` (`bindVideoHover`: em Apresentar começa o leitor com som, que não pára ao sair;
clique no vídeo em Apresentar pausa/retoma; `presAct` só trata o cartão do diapositivo e devolve false nos outros,
que tocam pelo leitor normal; `go()`/`stop()` param os leitores).
- [x] Teste: diapositivo = coluna com vídeo e MP3; cursor no vídeo → toca com som; cursor sai → continua; clique pausa
  e retoma; ▶ do MP3 → toca junto; ‹ › ou Esc → nada a tocar. O cartão do diapositivo continua como estava (sem 2.º
  som no ▶ do cartão de áudio do diapositivo).
- [x] Ajustar `tests/ui/cc_present_hover_test.js` ao decidido (o cursor num vídeo fora do diapositivo passa a tocar).

## Task 4: Especificação, bateria, revisão
- [ ] §33.13/§27/§33.14/§10.32; bateria completa; revisão independente; perguntar antes de publicar.

## Review Focus
1. Tempo real/sondagem redesenha o cartão a meio → o vídeo continua (makeEl) e não fica um 2.º elemento.
2. Vídeo dentro de coluna fora da vista (deslocar) continua a tocar e não é cortado pelo `cull`.
3. iPad (toque): sem cursor; o ▶ do cartão é o caminho (44 px), em Apresentar o toque no vídeo pausa/retoma.
