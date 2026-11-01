# Toppernote — «Escolher mais» Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No iPad sem teclado, escolher vários cartões só com toques (alternativa de um só ponteiro ao toque longo + arrastar — WCAG 2.5.7).

**Architecture:** Um modo de uma só vez, `B.pickMore`, ligado pelo botão «Escolher mais» da barra da seleção. Enquanto está ligado, tocar num cartão junta-o ou tira-o (o mesmo caminho do Shift + clique), tocar no fundo não limpa a seleção, e o 2.º toque numa nota não a edita. Sai com «Concluído», Esc, seleção vazia ou ao abrir outro Topperboard. A barra da seleção passa a aparecer com 1 cartão em ecrãs táteis (`pointer: coarse`), para se poder começar.

**Tech Stack:** JS (`admin/assets/cc.js`), CSS (`admin/assets/cc.css`), Playwright.

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §33.12 (seleção) e §10.31 «Fica para depois».

## Global Constraints
- Textos: «Escolher mais», «Concluído», dica «Toque nos cartões para juntar ou tirar.» (ux-writing).
- Botão com `aria-pressed`; alvo de 44 px em `pointer: coarse`; foco visível.
- O modo é visível enquanto está ligado (botão premido + dica + classe `pick-more` na tela).
- Nada muda no servidor.

## Review Focus
1. Modo ligado e a seleção fica vazia (Apagar, Esc) → o modo desliga-se; não fica «preso».
2. Abrir um sub-Topperboard com o modo ligado → o novo começa com o modo desligado.
3. Arrastar um cartão com o modo ligado → move a seleção toda (como hoje), não junta/tira.
4. 2.º toque numa nota selecionada com o modo ligado → tira-a da seleção, não entra em edição.
5. No computador com ≥ 2 selecionados o botão também aparece e funciona com o rato.

---

### Task 1: «Escolher mais» na barra da seleção

**Files:**
- Modify: `admin/assets/cc.js` (`selBar`, `down`, `upEv`, `keys`, `openBoard`)
- Modify: `admin/assets/cc.css` (`.ccb-selbar [aria-pressed="true"]`, `.sb-hint`, `.ccb-vp.pick-more`)
- Test: `tests/ui/cc_select_test.js`

- [ ] **Step 1: Write the failing test** (contexto tátil 1180×820, `hasTouch`): tocar na nota → barra visível com «Escolher mais»; tocar no botão → `aria-pressed="true"` e dica visível; tocar na lista → 2 selecionados; tocar outra vez na nota → 1 (só a lista); tocar no fundo → continua 1; «Concluído» → `aria-pressed="false"`; tocar na nota → só a nota (substitui). Ligar outra vez e premir Esc → modo desligado. Computador com 2 selecionados → o botão existe.
- [ ] **Step 2: Run** `node tests/ui/cc_select_test.js` — Expected: FAIL («Escolher mais» não existe).
- [ ] **Step 3: Implement** `B.pickMore` como acima; `selBar()` mostra com ≥ 2, ou com 1 em `pointer: coarse` ou com o modo ligado; botão `data-sb="more"`.
- [ ] **Step 4: Run** `node tests/ui/cc_select_test.js` — Expected: PASS; depois a bateria toda.
- [ ] **Step 5: Commit**; §10.31 atualizada (sai de «Fica para depois»).
