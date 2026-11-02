# Toppernote — correções de 2 nov (Apresentar, MP3, barra de ferramentas, conta-gotas, borracha, Drive) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corrigir os seis problemas que a equipa encontrou no Toppernote (e uma fuga de miniaturas da Faturação descoberta na investigação), cada um pela causa confirmada.

**Architecture:** Correções locais em `admin/assets/cc.js` (viewBoard), `cc_logic.js` (lógica pura testável), `cc.css`, `cc_api.php`, `cc_file.php`, `inc/cc.php` e uma migração 42 (só enfileira a reclassificação de ficheiros de áudio gravados como vídeo; o trabalho pesado é um job `media_sniff`). As causas, com provas e reproduções, estão em `.superpowers/sdd/2026-11-02-toppernote-correcoes/rc/<tema>.txt` (relatório do investigador + correções do revisor cético) — **ler o do seu tema antes de começar; as correções do revisor prevalecem sobre o desenho do investigador**.

**Tech Stack:** PHP 8 (sem framework), MariaDB na bancada / MySQL em produção, JS sem dependências, Playwright.

**Spec:** `docs/ESPECIFICACAO-PLATAFORMA.md` §33 (§33.3, §33.9, §33.11, §33.13) e §12.0 (seta do menu); nova §33.14 com o que a equipa pediu a 2 nov.

## Global Constraints
- Textos da interface em português europeu; nunca «§» na interface; sem identificadores de modelos em commits, PRs ou código.
- Permissões sempre no servidor. Ficheiros da Faturação só a quem tem o Financeiro (`drive_can_see`).
- Migração idempotente `migrate_cc_42` em `inc/migrate_creative.php`, `SCHEMA_VERSION` 42 em `inc/migrate.php`; ao publicar, a migração vai antes do `migrate.php`. Nada de leituras ao R2 dentro da migração.
- Testes: PHP simples em `tests/` (`php tests/x.php`), lógica JS em `tests/cc_logic_test.js`, interface em `tests/ui/*.js` (Playwright). Os testes de interface correm **um de cada vez** (partilham a bancada); cada teste novo usa `tests/ui/seed_board.php` ou cria o seu Topperboard.
- TDD: o teste falha primeiro pela razão certa, depois o código. Bateria completa verde antes de cada commit (`bash $S/suite33.sh` + testes novos).
- Commits só no ramo `claude/exciting-goodall-mtlx1a`, com os trailers da sessão. Não publicar no main nem na Hostinger.

## Review Focus
1. Em Apresentar, um cartão de vídeo/áudio longe do anterior (fora da área desenhada) toca com som ao chegar, continua depois de o cursor entrar e sair, sobrevive a um reload/sondagem e a uma re-renderização da IA.
2. Um MP3 com extensão `.mpeg` (é o caso real em produção: «WhatsApp Audio … .mpeg», `video/mpeg`, começa por `ID3`) vira cartão de áudio — novo envio, «Da Drive» e o já gravado (job).
3. Um vídeo verdadeiro que o browser não descodifica (H.264+Opus, ProRes .mov, HEVC) **continua** vídeo: nada de decidir por `videoWidth === 0`.
4. Anular depois da borracha com tempo real ligado: o traço volta e fica (não é escondido pelo marcador de apagado do tempo real).
5. Quem não tem o Financeiro não recebe miniaturas nem nome de um ficheiro da Faturação posto num Topperboard por outra pessoa.

---

### Task 0: Fuga das miniaturas da Faturação (segurança)

**Files:** `admin/cc_file.php`, `admin/inc/cc.php` (`cc_card_public`/`cc_board_cards` ou a ação `board` em `admin/cc_api.php`), `admin/cc_api.php` (`drive_add`), test `tests/cc_drive_billing_thumb_test.php`.

- [ ] Teste (falha hoje): uid 1 (Financeiro) faz `drive_add` de um PNG em `Empresa/Financeiro/…`; uid 3 pede `cc_file.php?a=<id>&s=256` → **404** (hoje 302); o JSON `board` para uid 3 **não contém** o nome do ficheiro nem `thumb`/`orig` desse cartão (mostra-se como «Sem acesso a este ficheiro»). uid 1 continua a ver tudo.
- [ ] Teste (falha hoje): ficheiro movido com `instant:true` (sem `drive_moves_run`) e depois `drive_add` da chave nova → 1 cartão.
- [ ] `cc_file.php`: depois de carregar o asset, verificar `drive_can_see` sobre a **chave do próprio asset** (`file_key` `r2:` fora de `.topper/`) para qualquer tamanho, e 404 na página pública.
- [ ] `board`: para quem não vê o ficheiro da Drive, o cartão sai sem `thumb`, `orig`, `title`, `url` e com `noaccess: true`; o cartão mostra «Sem acesso a este ficheiro» (texto em pt-PT).
- [ ] `drive_add`: `drive_resolve($pdo, $k)` para ler o objeto físico (movimentos instantâneos pendentes), guardando `file_key = 'r2:'.$k`; chaves que não são texto contam como ignoradas.
- [ ] Commit.

### Task 1: Apresentar — o vídeo/áudio toca até ao fim

**Files:** `admin/assets/cc.js` (`bindVideoHover`, `present`/`go`/`startMedia`/`toggleMedia`, `cull`, `makeEl`/`rerender`/`renderAll`), test `tests/ui/cc_present_hover_test.js` (+ manter `cc_present_sound_test.js`).

Causas (rc/present-video-hover.txt + rc/mp3-card.txt B): (1) o `pointerleave` da pré-visualização remove qualquer `<video>` do cartão, incluindo o da apresentação; (2) `startMedia` corre antes de o cartão estar desenhado (o `cull` só corre no frame seguinte) e sai sem tocar; (3) qualquer re-renderização (`rerender`, `renderAll` do reload/tempo real, `refreshAssets` da IA, `auNeedPeaks`) desliga o elemento da apresentação.

- [ ] Teste (falha hoje) com um Topperboard próprio: nota (0,0) → vídeo (1600,0) → nota (3200,0) → áudio (4800,0) (longe uns dos outros). Em Apresentar, sem flag de autoplay: ao chegar ao vídeo há 1 `video.pres-media` a tocar sem `muted`; passar o cursor e tirar duas vezes → o mesmo elemento continua a tocar (sem pré-visualização); `cc_board_touch` + esperar a sondagem (10 s) → continua a tocar; Espaço pausa e não muda de diapositivo; ao chegar ao áudio ouve-se (`audio.pres-media` a tocar); numa nota, passar o cursor sobre o vídeo vizinho não cria pré-visualização; Esc → fora de Apresentar a pré-visualização sem som volta a funcionar e pára ao sair.
- [ ] Pré-visualização marcada (`video.hov-prev`), só ela é removida; nada de pré-visualização em Apresentar; ao entrar em Apresentar matar as que existam.
- [ ] `startMedia`: se o cartão ainda não tem elemento, `cull()` síncrono (a vista já foi posta pelo `fitTo`); o cartão apresentado fica isento do `cull`.
- [ ] O elemento da apresentação sobrevive às re-renderizações: o `<audio>` fica fora do cartão (na barra `.cc-pres`); o `<video>` é recolocado no novo `.ccc-media` dentro de `makeEl` quando o cartão é o apresentado.
- [ ] Num diapositivo de media sem elemento, Espaço não salta o diapositivo.
- [ ] Commit.

### Task 2: MP3 (e outros áudios em contentor de vídeo) como cartão de áudio

**Files:** `admin/assets/cc_logic.js` (`CCL.sniffMedia(bytes)`), `admin/assets/cc.js` (`kindOf`/`uploadFile`), `admin/inc/cc.php` (`cc_media_sniff`, `cc_kind_for`, `cc_card_add`, job `media_sniff` em `cc_job_exec`), `admin/cc_api.php` (`upload_done`, `drive_add`), `admin/inc/capture.php`, `admin/inc/migrate_creative.php` (`migrate_cc_42`), `admin/inc/migrate.php` (42); tests `tests/cc_media_sniff_test.php`, `tests/cc_logic_test.js`, `tests/ui/cc_mp3_card_test.js`.

Causa (rc/mp3-card.txt, com a correção do revisor e a prova em produção): o ficheiro real é um MP3 (`ID3…`, Lavf) com extensão `.mpeg`; o browser e o servidor dão-lhe `video/mpeg`, e ambos os classificadores põem o `video/*` à frente; o cartão nasce `video`, sem imagem — o retângulo grande.

- [ ] Testes (falham hoje):
  - `CCL.sniffMedia` / `cc_media_sniff` com bytes feitos à mão: `ID3…` → `audio` (mime `audio/mpeg`); sincronismo MPEG áudio (`FF FB`/`FF F3`/`FF FA`…) → `audio`; MP4/MOV com `hdlr` só `soun` → `audio` (mime `audio/mp4`); com `vide` (incluindo `moov` no fim do ficheiro) → `video`; WebM/Matroska com pista de vídeo → `video`, só áudio → `audio`; desconhecido → `null`.
  - Enviar «WhatsApp Audio x.mpeg» (um MP3 com ID3) pelo dispositivo e «Da Drive» → asset `kind=audio`, `mime=audio/mpeg`, cartão `audio` (`h=0`) com forma de onda; um MP4 H.264+Opus que o browser não descodifica continua `video` (guarda contra regressão).
  - Job: asset `kind=video` sem `width` nem miniaturas cujo objeto é MP3 → passa a `audio` + `audio/mpeg`, cartões `audio` com `h=0`, Topperboards tocados; um vídeo verdadeiro sem miniaturas fica `video`; resultado desconhecido não muda nada.
- [ ] Classificar pelo conteúdo: no browser, ler os primeiros 64 KB (e os últimos 2 MB nos MP4/MOV) antes de decidir; no servidor, `cc_media_sniff` com leituras por intervalo no R2 (`r2_get($k,[0,…])` + fim) em `upload_done`, `drive_add`, `capture`; o servidor decide (o cliente só pode baixar um contentor de vídeo para áudio se o servidor confirmar). Extensões de áudio à frente de `video/*` em `kindOf` e `cc_kind_for` (`.ogg` com `video/ogg` fica vídeo).
- [ ] `cc_card_add`: com `asset_id`, o tipo do cartão segue o asset (`cc_card_type_for`), `h=0` para áudio/ficheiro.
- [ ] `migrate_cc_42`: só enfileira jobs `media_sniff` para assets `kind='video'` sem `width` e sem miniaturas, e converte já os que têm extensão/mime de áudio óbvios; `SCHEMA_VERSION` 42. O job faz as leituras ao R2.
- [ ] Commit.

### Task 3: Anular depois da borracha (com tempo real)

**Files:** `admin/cc_api.php` (`cards_restore`), `admin/assets/cc.js` (`runPlan`, `delSel` → «Anular» do aviso, `finishErase`, `undo`), `admin/assets/cc_rt.js` (se preciso); tests `tests/cc_restore_test.php`, `tests/ui/cc_erase_undo_rt_test.js`.

Causa (rc/eraser-undo.txt): `cards_restore` só limpa `deleted_at`; o `updated_at` fica antigo; o `reconcile` do tempo real vê o marcador de apagado mais recente e volta a esconder o cartão. A bancada não tem tempo real, por isso escondia o problema.

- [ ] Teste PHP (falha hoje): `updated_at` posto em '2020-01-01 00:00:00', apagar e restaurar → `updated_at` maior; a resposta traz os cartões restaurados (filtrados como `cc_board_cards`).
- [ ] Teste de interface (falha hoje) com tempo real falso (substituir `YProvider`/`IndexeddbPersistence` por `page.addInitScript`, como em rc/eraser-undo.txt correção 1): 2 traços, esperar 2,6 s, borracha num, botão Anular → os ids no DOM são os da base de dados; depois de recarregar também. Anular um «add» não deixa fantasma.
- [ ] `cards_restore` atualiza `updated_at`/`updated_by` e devolve os cartões; `runPlan` e o «Anular» do aviso publicam no tempo real (`RT.added` ao restaurar, `RT.removed` ao apagar).
- [ ] Anular durante o pedido da borracha espera por ele (`B.inflight`).
- [ ] Commit.

### Task 4: Barra de ferramentas estática e expansível

**Files:** `admin/assets/cc.css` (`.ccb-tools`), `admin/assets/cc.js` (`toolsHtml`, `keys`), `admin/cc_api.php` (`pref` k=`tools`), `admin/inc/cc.php` (`cc_prefs`); tests `tests/ui/cc_tools_bar_test.js`, `tests/cc_pref_test.php`.

Causa (rc/tools-bar.txt): com `box-sizing: border-box` a coluna de 48 px tem 35 px úteis para botões de 36 px (44 px no iPad) e `overflow: auto` nos dois eixos → desliza para o lado; com pouca altura a barra vertical come a largura e corta os ícones.

- [ ] Testes (falham hoje), com barras de deslocamento clássicas (`--disable-features=OverlayScrollbar`): a 1918×900, 1366×640 e num iPad (toque), `scrollWidth <= clientWidth` na barra; seta na borda direita da barra («Expandir ferramentas» / «Recolher ferramentas», atalho `]`, também com AltGr), expandida mostra o nome de cada ferramenta («Selecionar», «Anular», «Refazer», «Nota», «Coluna», «Lista», «Amostra de cor», «Desenhar», «Seta», «Enviar», «Link», «Sub-Topperboard», «IA»); o estado fica por pessoa (`pref` k=`tools`) e mantém-se ao recarregar e ao abrir um sub-Topperboard; sem dicas (`data-tip`) com a barra expandida.
- [ ] CSS: largura que cabe os botões (incluindo 44 px no toque), `overflow-x: hidden`, vertical com `scrollbar-gutter`/barra fina sem criar deslocamento lateral; seta como `.rail-edge` do menu (§12.0).
- [ ] Commit.

### Task 5: Conta-gotas em ecrã inteiro com cor grande

**Files:** `admin/assets/cc_logic.js` (`fitRect`, `pickPx`, `avg3` com caixa, `ink`), `admin/assets/cc.js` (`startPick`/`stopPick`, `keys`, Biblioteca), `admin/assets/cc.css` (`.cc-pickfs`), `admin/cc_file.php` (`&cors=1`); tests `tests/cc_logic_test.js`, `tests/ui/cc_eyedropper_test.js`, `tests/cc_file_px_test.php`.

Causa/desenho (rc/eyedropper.txt + correções do revisor): o conta-gotas usava a pré-visualização pequena do painel (≈476×317), lupa de 120 px e bolinha de 12 px; a posição falhava com `object-fit: contain` e nas bordas; no vídeo o canvas ficava «sujo» (outro domínio).

- [ ] Testes (falham hoje): `avg3` nas bordas (colunas ≥7 vermelhas, caixa [7,0,15,15] → `#DC2828`; colunas 0..7 `#2F77B1`, caixa [0,0,8,15] → `#2F77B1`); `pickPx` com imagem mais alta que larga; `fitRect`. UI: «+ Escolher da imagem» abre `.cc-pickfs` com a imagem a ocupar quase todo o ecrã (≥ 70% da largura ou altura útil); rectângulo da cor ≥ 56 px de altura com o #HEX ≥ 18 px; clicar no vermelho/azul conhecidos acrescenta a cor certa; Shift continua; Esc e «Concluir» saem e devolvem o foco; as cores aparecem na paleta do painel ao voltar; no iPad um toque acrescenta uma vez só.
- [ ] Sobreposição em ecrã inteiro com zoom (roda, + − 0) e arrastar com zoom; lupa 168 px; barra com amostra 56×56, #HEX 20 px e RGB; vídeo pelo fotograma em pausa com clone `crossorigin` e URL próprio (`&cors=1`, assinatura diferente); teclas do Topperboard bloqueadas enquanto se escolhe; Biblioteca: Esc fecha o conta-gotas primeiro.
- [ ] Corrigir na §10.31 a frase sobre o fotograma de vídeo se continuar a não ser verdade.
- [ ] Commit.

### Task 6: «Juntar da Drive» com pré-visualização e arrastar para o Topperboard

**Files:** `admin/toppernote.php` (carregar o módulo de miniaturas da Drive), `admin/assets/drive/upload.js` ou novo `drive/thumbs.js`, `admin/assets/cc.js` (`drivePick`, `drop` do Topperboard), `admin/assets/cc_logic.js` (`dragKeys`, `gridStep`), `admin/assets/cc.css`, `admin/cc_api.php` (`drive_add` mensagens); tests `tests/ui/cc_drive_pick_test.js`, `tests/cc_logic_test.js`.

Desenho (rc/drive-picker.txt + correções): grelha com miniaturas como na Drive (`catalog.thumbs` + `TD.thumbs.urls`/`ensure`, preguiçoso), ícone e extensão para o resto; arrastar uma miniatura (ou as escolhidas) para o Topperboard põe os cartões no sítio onde se larga (o diálogo fica transparente e deixa passar o arrasto; fecha quando o Topperboard recebe); arrastar da página da Drive noutro separador já é aceite pelo Topperboard (`application/x-td-keys`) — confirmar e cobrir com teste. Textos: «O cartão aponta para o ficheiro: não se faz cópia. Shift ou Ctrl para escolher vários. Também pode arrastá-los para o sítio certo do Topperboard.», «Largue no Topperboard para juntar.», «As pastas não entram: abra a pasta e escolha os ficheiros.»

- [ ] Testes (falham hoje): miniaturas `img` nas imagens com miniatura na Drive; ícone + extensão nos outros; setas movem na grelha; arrastar 2 escolhidos para (x,y) cria 2 cartões perto de (x,y) e fecha o diálogo; arrasto cancelado deixa o diálogo como estava; arrastar uma linha da página da Drive para o Topperboard cria o cartão; os testes de acessibilidade do seletor (Esc, Tab, aria-pressed) continuam verdes.
- [ ] Commit.

### Task 7: Documentação, auditoria e fecho
- [ ] §33.14 (pedidos de 2 nov) e §10.32 na especificação; auditoria de acessibilidade (claro/escuro, computador/iPad) às partes novas; bateria completa; revisão final.
