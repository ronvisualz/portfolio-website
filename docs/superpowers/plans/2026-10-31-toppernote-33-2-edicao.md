# Toppernote 2 — Edição no Topperboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Listas, amostras de cor, desenho, balão de comentários, painel redimensionável, editar notas no ponto do clique e Anular/Refazer visíveis e por pessoa.

**Architecture:** Lógica pura num módulo novo `admin/assets/cc_logic.js` (UMD: `window.CCL` no browser, `module.exports` no Node) com testes em `tests/cc_logic_test.js` (`node`, sem dependências). `cc.js` só liga ao DOM. Preferências por pessoa em `users.prefs` (JSON) pela ação nova `cc_api.php?a=pref`.

**Tech Stack:** JS (ES2017), PHP 8, Playwright (Chromium 1.56) para UI.

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §33.1–33.4, §33.7, §33.10

## Global Constraints
- Atalhos: P caneta, E borracha, 1–5 cores da marca, D desenhar (já existe), Ctrl/Cmd Z, Ctrl/Cmd Shift Z (80 passos).
- Cores da marca (ordem 1–5): `#111111`, `#FFC718`, `#E8846D`, `#81CFE3`, `#2F77B1`; mais «livre» com #HEX.
- Painel: mín. 280 px, máx. 60% da janela; duplo clique repõe 340 px; largura por pessoa.
- Últimas 8 cores usadas por pessoa.
- Textos com design-skills:ux-writing; estados/atalhos com design-skills:interaction-design.

## Review Focus
1. Lista com um único item vazio e Backspace → não apaga o último (fica um item vazio editável).
2. Colar 200 linhas numa lista → 200 itens, sem bloquear; linhas vazias ignoradas.
3. #HEX colado com espaços/maiúsculas/sem # («  ffc718 ») → aceite; texto que não é cor → aviso, cor não muda.
4. Anular depois de um colega ter mexido no mesmo cartão → o cartão do colega não volta atrás; aviso «1 alteração não anulada: outra pessoa mudou este cartão».
5. Redimensionar um desenho para 20% e 400% → traços sem cortes nem deformação.

---

### Task 1: `cc_logic.js` + teste de regressão do Topperboard

**Files:** Create `admin/assets/cc_logic.js`, `tests/cc_logic_test.js`, `tests/ui/cc_board_smoke_test.js`, `tests/ui/seed_board.php`; Modify `admin/toppernote.php` (carregar `cc_logic.js` antes de `cc.js`).

**Interfaces — Produces:** `CCL` (objeto) com as funções das tarefas seguintes; `tests/ui/seed_board.php` imprime o id de um Topperboard com nota, lista (3 itens), amostra, desenho, imagem e sub-Topperboard.

- [ ] Teste de fumo (UI): abre o Topperboard semeado, conta os cartões (6), faz N (nota nova), escreve, Esc, Ctrl Z (nota sai), Ctrl Shift Z (volta); sem erros na consola.
- [ ] Corre e passa no estado atual (é a rede de segurança). `cc_logic_test.js` com um teste trivial `CCL.version === 1`. Commit.

### Task 2: Listas (§33.1 + Tab/Ctrl Enter)

**Interfaces — Produces:**
- `CCL.listKey(items, i, key, mods) -> {items, focus, handled}` — `key` ∈ `Enter|Backspace|Tab`; `mods` `{shift, ctrl, atStart, atEnd, empty}`; itens `{t, d, l}` (`l` nível 0–2).
- `CCL.listPaste(items, i, text) -> {items, focus}`; `CCL.listClean(items) -> items` (tira vazios do fim; deixa pelo menos 1).

- [ ] **Debugging primeiro** (systematic-debugging): reproduzir «o primeiro item não deixa escrever» num teste de UI (`tests/ui/cc_list_test.js`: duplo clique no 1.º item, escrever «abc», Esc → item 0 = «abc»). Registar a causa antes de corrigir.
- [ ] Testes `cc_logic_test.js`:
  - `listKey([{t:'a'}],0,'Enter',{atEnd:true})` → 2 itens, `focus:1`, novo `{t:'',d:false,l:0}`;
  - Enter num item vazio logo a seguir a outro vazio → cria outro (vários vazios seguidos permitidos);
  - `Backspace` com `empty:true` em `i=2` → item removido, `focus:1`; com um só item → `handled:false`;
  - `Tab` → `l` +1 (máx. 2); `Shift Tab` → `l` −1; `Enter` com `ctrl:true` → `d` alterna, sem criar item;
  - `listPaste(items,0,'x\n\ny\nz')` → insere `x,y,z` depois do 0; `listClean([{t:'a'},{t:''},{t:''}])` → `[{t:'a'}]`.
- [ ] Ligar em `startEdit` (lista): Enter/Backspace/Tab/Ctrl Enter/paste pelo `CCL`; grava com `listClean` ao sair; sub-itens com `padding-left` por nível; arrastar para reordenar com pega `⋮⋮` (HTML5 drag na lista).
- [ ] UI test passa (inclui: Enter Enter Enter → 3 vazios; Backspace num vazio sobe; colar 3 linhas → 3 itens). Commit.

### Task 3: Amostra de cor e seletor (§33.2 + últimas 8)

**Interfaces — Produces:** `CCL.hexParse(s) -> '#RRGGBB'|null`; `CCL.recentColors(list, hex, max=8) -> list`; `cc_api.php?a=pref` POST `{k:'colors'|'side_w', v}` grava `users.prefs.cc[k]`; `board` devolve `prefs:{colors,side_w}`.

- [ ] Testes: `hexParse('  ffc718 ')==='#FFC718'`, `hexParse('#abc')==='#AABBCC'`, `hexParse('azul')===null`; `recentColors(['#111111'],'#ffc718')` → `['#FFC718','#111111']`, sem duplicados, corta a 8. PHP: `tests/cc_pref_test.php` (grava `colors` com 9 → guarda 8; `side_w` fora de 280–4000 → 400).
- [ ] Popover `colorPop(anchorEl, hex, onPick)` em `cc.js`: ancorado ao cartão (abaixo/acima conforme o espaço; reposiciona dentro da janela), cores da marca, últimas 8, `<input type=color>`, campo #HEX editável, «Copiar #HEX», «Colar #HEX». Ctrl V com uma amostra selecionada e texto #HEX na área de transferência → muda a cor (com Anular).
- [ ] UI test `tests/ui/cc_color_test.js`: o popover fica a ≤ 16 px do cartão; escrever «2f77b1» + Enter muda a amostra; Ctrl V com «#E8846D» copiado muda; Ctrl Z repõe. Commit.

### Task 4: Desenhar (§33.3 + P/E/1–5)

**Interfaces — Produces:** `CCL.strokeHit(pts, x, y, r) -> bool` (distância ponto–segmento ≤ r); `CCL.fitStroke(pts) -> {pts, vw, vh, x, y}`.

- [ ] **Debugging primeiro**: teste de UI que desenha um traço diagonal, redimensiona o desenho para 200% e para 50%, e compara a caixa do `<path>` com a do cartão (sem corte; proporção mantida). Causa registada antes da correção (suspeita: `preserveAspectRatio="none"` + `h` recalculado sem `vw/vh`).
- [ ] Testes `strokeHit` (no segmento → true; a r+1 → false) e `fitStroke`.
- [ ] Barra da caneta (aparece com D/P): 5 cores da marca (1–5) + livre (popover da tarefa 3), espessura fina 2 / média 4 / grossa 8, borracha (E: apaga traços tocados, com Anular). Escala: `viewBox` fixo + `preserveAspectRatio="xMidYMid meet"`; opção «Espessura acompanha» (`vector-effect: non-scaling-stroke` quando desligada).
- [ ] UI test passa. Commit.

### Task 5: Balão de comentários (§33.4, só leitura)

**Interfaces — Produces:** `cc_api.php?a=comments&board=&card=&last=3` devolve só os 3 últimos + `total`.

- [ ] PHP test: `last=3` com 5 comentários → 3, os mais recentes, `total:5`.
- [ ] Hover (rato, 250 ms) ou toque no `.ccc-nc` → balão ancorado (autor, data, 120 caracteres), «Ver todos» abre o painel Comentários. Sem caixa de resposta. Teclado: foco no número + Enter abre.
- [ ] UI test (rato e `hasTouch`). Commit.

### Task 6: Painel lateral redimensionável (§33.7)

- [ ] UI test: arrastar a borda para 200 px → fica 280; para 90% → 60%; duplo clique → 340; recarregar → mantém (pref `side_w`).
- [ ] Pega `.ccb-side-grip` (8 px, `cursor: col-resize`, `role=separator`, setas ← → mudam 16 px). Commit.

### Task 7: Editar nota no ponto do clique (§33.10)

- [ ] UI test: duplo clique a meio da palavra «exemplo» de uma nota → escrever «X» insere nesse sítio; segundo clique numa nota já selecionada faz o mesmo.
- [ ] `caretRangeFromPoint`/`caretPositionFromPoint` em `startEdit(c, null, {x, y})`. Commit.

### Task 8: Anular/Refazer visíveis e por pessoa (§33.10)

**Interfaces — Produces:** `CCL.undoPlan(entry, current, redo) -> {apply: states[], skipped: n}` — para `move`/`content`: só repõe itens cujo estado atual (`current.get(id)`) é igual ao `after` gravado (ou `before` ao refazer); `add`/`del`: só ids que ainda existem/não existem.

- [ ] Testes: entrada `move` com 2 itens, um mudado por outro → `apply` 1, `skipped` 1; `content` igual → aplica; ids apagados por outro → saltados.
- [ ] Botões ↶ ↷ na barra (desativados com a pilha vazia; `aria-label` «Anular (Ctrl Z)»/«Refazer (Ctrl Shift Z)»); `undo()` usa `undoPlan`; aviso quando salta. A pilha só recebe ações locais (as do tempo real nunca entram).
- [ ] UI test com dois separadores (dois `page`): A move a nota, B muda o texto da mesma nota, A faz Ctrl Z → posição volta, texto de B fica. Commit.
