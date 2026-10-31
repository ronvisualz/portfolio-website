# Toppernote 1 — Renome (Centro Criativo → Toppernote, quadros → Topperboards) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Toda a interface diz «Toppernote» e «Topperboard(s)»; `criativo.php` redireciona para `toppernote.php`.

**Architecture:** Só textos visíveis (HTML, mensagens da API, notificações, emails, portal, ajuda, prompts da IA). Código interno (`cc_*`, tabelas, chave de permissão `criativo`) fica. `toppernote.php` passa a ser a página; `criativo.php` faz 301 com a query.

**Tech Stack:** PHP 8, JS sem framework.

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §33.0, §33.0.1

## Global Constraints
- «Toppernote» (nunca «TopperNote»); «Topperboard», plural «Topperboards»; «sub-Topperboard»; «novo Topperboard»; «Topperboards livres».
- Não mostrar «§» na interface. Português europeu. Textos novos revistos com design-skills:ux-writing.
- Não tocar em «quadro» fora do Toppernote (Tarefas › Quadro da equipa; kanban do Marketing).

## Review Focus
1. Links antigos guardados (emails, notificações, favoritos do browser) com `criativo.php?b=…&card=…` → abrem o mesmo Topperboard e cartão.
2. Portal do cliente (separador «Quadros») em PT e EN → «Topperboards» nos dois.
3. Notificações já gravadas na base com o texto antigo → continuam legíveis (não se reescrevem), as novas usam o nome novo.
4. Pesquisa ⌘K: procurar «toppernote» ou «topperboard» encontra a aba e os quadros.
5. Menu recolhido (só ícones): tooltip «Toppernote».

---

### Task 1: Teste estático do vocabulário + redirecionamento

**Files:**
- Create: `tests/toppernote_names_test.php`, `admin/toppernote.php` (conteúdo atual de `criativo.php`)
- Modify: `admin/criativo.php` (só o 301), todas as ligações internas `criativo.php` → `toppernote.php`

- [ ] **Step 1: teste** — `tests/toppernote_names_test.php`:
  - para cada ficheiro de interface do Toppernote (`toppernote.php`, `cc_api.php`, `cc_file.php`, `assets/cc*.js`, `inc/cc*.php`, `portal.php`, `assets/portal.js`, `inc/ui.php`, `inc/settings_def.php`, `inc/alerts.php`, `inc/tasks.php`, `negocio.php`, `rv_api.php`, `capture_api.php`, `partilhar.php`, `manifest.php`, `inc/files.php`, `inc/files_reorg.php`, `inc/pipeline_flow.php`): `t_true(!preg_match('/Centro Criativo/u', $stringsOnly))` — só strings e HTML (ignora comentários `//`, `/* */`, `#`);
  - nos ficheiros do Toppernote: nenhuma string com `\b[Qq]uadros?\b` nem `[Ss]ub-quadro` (lista de exceções vazia);
  - HTTP: `GET /admin/criativo.php?b=5&card=9` → 301 com `Location: /admin/toppernote.php?b=5&card=9`;
  - HTTP: `GET /admin/toppernote.php` (sessão uid 1) → 200 e contém `<title>` com «Toppernote».
- [ ] **Step 2:** `php tests/toppernote_names_test.php` → FALHA (muitas ocorrências; 404 em toppernote.php).
- [ ] **Step 3:** criar `toppernote.php` (conteúdo de `criativo.php`, textos novos), `criativo.php` = `header('Location: /admin/toppernote.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301)`; trocar todos os `/admin/criativo.php` do código por `/admin/toppernote.php`.
- [ ] **Step 4:** substituir os textos (menu `inc/ui.php`, títulos, migalhas, Definições, notificações, emails em `inc/cc_flow.php`/`inc/cc_rt.php`, portal PT/EN, prompts da IA em `inc/cc_ai.php`, ajuda/Conhecimento) até o teste passar. Separadores: «Projetos» → «Topperboards»; «Quadros livres» → «Topperboards livres»; «Sub-quadro» → «Sub-Topperboard»; «Novo quadro» → «Novo Topperboard»; «Quadro vazio» → «Topperboard vazio».
- [ ] **Step 5:** `php tests/toppernote_names_test.php` → OK; `node tests/ui/cc_board_smoke_test.js` (plano 2, tarefa 1; se ainda não existir, abrir um Topperboard na bancada e ver as migalhas «Toppernote / …»).
- [ ] **Step 6:** especificação: referências visíveis «Centro Criativo» nas secções vivas passam a «Toppernote (ex-Centro Criativo)» na primeira menção. Commit.
