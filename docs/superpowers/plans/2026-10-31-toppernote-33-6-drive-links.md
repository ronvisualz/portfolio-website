# Toppernote 6 — Drive e links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enviar «Do dispositivo» ou «Da Drive» (sem copiar; acompanha mover/renomear; avisa ao apagar) e cartões de link com título, miniatura e autor.

**Architecture:**
- Drive: o caminho de hoje (`drive_add` cria a referência com `file_key = 'r2:<chave da Drive>'`; `files_paths_moved` já segue mover/renomear). Novo: migração 41 com `cc_assets.drive_gone DATETIME NULL` e `cc_assets.drive_trash_id INT NULL`; `drive_store_trashed_many` marca as referências; `drive_trash_restore` (restaurar) limpa; apagar de vez mantém marcado. Aviso no Topperboard (`board` devolve `gone:[{card, name, trash_id}]`) e notificação a quem criou o cartão (`cc_notify`, categoria `creative`). Seletor da Drive reutiliza a Drive (`drive_api.php?do=catalog` + pesquisa) num diálogo.
- Links: `cc_link_provider($url) -> ['name','oembed'=>endpoint|null,'video'=>bool]` com lista fixa (YouTube, Vimeo, Instagram, TikTok, Behance, Dribbble, ArtStation, X, Spotify, SoundCloud); `cc_link_meta` passa a pedir primeiro o oEmbed (por `cc_fetch`), depois Open Graph; guarda `author`, `provider`, `duration`, `embed` (iframe só de anfitriões listados na CSP `frame-src`).

**Tech Stack:** PHP 8, MySQL/MariaDB, JS, Playwright.

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §33.8, §33.9

## Global Constraints
- Migração idempotente em `inc/migrate_creative.php` (`migrate_cc_41`), `SCHEMA_VERSION` 41; ao publicar, a migração vai antes de `inc/migrate.php`.
- Pedidos externos só por `cc_fetch` (IP público verificado a cada salto, ≤ 1,5 MB, ≤ 8 s); endpoints oEmbed só da lista fixa; nada de URLs de oEmbed vindos da página.
- Ficheiros da Drive: `cc_file.php` só serve `r2:` de origem Drive a quem `drive_can_see()` (faturação/privados).
- Textos: «Do dispositivo», «Da Drive», «Ficheiro apagado da Drive», «Restaurar do Lixo», «Retirar do Topperboard» (ux-writing).
- security-review: oEmbed/Open Graph e leitura de ficheiros da Drive.

## Review Focus
1. Ficheiro da Drive renomeado com o Topperboard aberto noutro separador → o cartão mostra o nome novo no próximo poll, sem recarregar.
2. Pasta inteira enviada para o Lixo com 3 ficheiros usados em 2 Topperboards → 3 cartões marcados, 1 aviso por Topperboard, 1 notificação por pessoa (não 3).
3. «Restaurar do Lixo» depois de o Lixo ter sido esvaziado → aviso claro e só «Retirar do Topperboard».
4. oEmbed que responde com HTML/JSON inválido ou 10 MB → cai para Open Graph sem erro.
5. Link do X (twitter.com e x.com) e de YouTube curto (youtu.be, shorts) → reconhecidos.

---

### Task 1: Migração 41 e estado «apagado da Drive»

**Interfaces — Produces:** `cc_drive_gone(PDO, array $keys, int $trashId = 0): array` (ids das referências marcadas) e `cc_drive_back(PDO, array $keys): int`; `board` → `gone`.

- [ ] Teste `tests/cc_drive_test.php` (bancada + R2 simulado): `drive_add` de um ficheiro → mover com a API da Drive (`move`, instant + `drive_moves_run`) → `cc_assets.file_key` = nova chave; renomear → idem; `trash` → `drive_gone` preenchido, `board` traz `gone` com o cartão, notificação para quem criou o cartão; `restore` → limpo; `purge` → continua marcado sem `trash_id`.
- [ ] Migração 41 + ganchos em `drive_store_trashed_many` e no restaurar; notificação agrupada por pessoa e Topperboard. Commit.

### Task 2: «Do dispositivo» / «Da Drive» e cartão apagado

- [ ] UI test `tests/ui/cc_drive_pick_test.js`: botão Enviar → menu com as duas opções; «Da Drive» abre o seletor (pastas, pesquisa, recentes, seleção múltipla com Shift/Ctrl e caixas); «Adicionar 2» → 2 cartões que apontam para a Drive (sem novo objeto em `o/` no R2). Ficheiro enviado para o Lixo na Drive → cartão com faixa «Ficheiro apagado da Drive», aviso no topo do Topperboard com «Restaurar do Lixo» e «Retirar do Topperboard».
- [ ] Implementar o menu, o seletor (diálogo próprio, lê `drive_api.php` `catalog`/`search`) e as ações (restaurar usa `drive_api.php?do=restore`; retirar apaga o cartão com Anular). Commit.

### Task 3: Cartões de link (§33.8)

**Interfaces — Produces:** `cc_link_provider(string $url): ?array`; `cc_oembed_parse(string $json): array` (`title, author, thumb, w, h, duration, html`); `cc_link_meta` devolve também `author`, `provider`, `duration`, `embed`.

- [ ] Teste `tests/cc_link_meta_test.php` (sem rede): `cc_link_provider` para os 10 serviços (incl. youtu.be, /shorts/, x.com e twitter.com, open.spotify.com, soundcloud.com, behance.net, dribbble.com, artstation.com, instagram.com/p/, tiktok.com/@x/video/); site genérico → `null`; `cc_oembed_parse` com JSON de exemplo de YouTube e Vimeo; JSON inválido → `[]`.
- [ ] Integração: `cc_fetch` com URL de oEmbed construído só a partir da lista; cartão mostra miniatura, título, autor, ▶ e duração nos vídeos; detalhe toca o `embed` quando o anfitrião está na CSP.
- [ ] security-review às funções novas e a `cc_file.php` (Drive). Commit.
