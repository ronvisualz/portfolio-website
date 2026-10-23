<?php
/**
 * topper. admin — Centro Criativo (§23)
 *
 * Separadores Projetos · Biblioteca · Caixa de entrada · Review e o quadro (tela infinita).
 * Esta página só desenha a moldura e entrega os dados iniciais; tudo o que lê e grava passa
 * por cc_api.php (permissões no servidor) e os ficheiros por cc_file.php.
 *
 *   ?t=projetos|biblioteca|entrada|review     os separadores
 *   ?b=<quadro>[&card=<cartão>]                um quadro (ecrã inteiro)
 *   ?print=treatment&b=<quadro>                o tratamento para imprimir / guardar em PDF
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/cc.php';
$user = require_login();
require_page('criativo');

if (!cc_ready($pdo)) {
    render_header('Centro Criativo', 'criativo');
    echo '<h1>Centro Criativo</h1><div class="panel"><p>A base de dados está a atualizar. Recarregue a página daqui a pouco.</p></div>';
    render_footer();
    exit;
}

const CC_TABS = ['projetos' => 'Projetos', 'biblioteca' => 'Biblioteca', 'entrada' => 'Caixa de entrada', 'review' => 'Review'];
$canEdit = can('criativo', PERM_EDIT);
$canManage = can('criativo', PERM_MANAGE);

// ── o tratamento, para imprimir (Quadro → tratamento) ───────
if (($_GET['print'] ?? '') === 'treatment') {
    $b = cc_board_get($pdo, (int)($_GET['b'] ?? 0));
    $doc = $b ? $pdo->query("SELECT data FROM cc_pages WHERE board_id = " . (int)$b['id'] . " AND lane = 'T'")->fetchColumn() : false;
    $doc = $doc ? json_decode((string)$doc, true) : null;
    if (!$b || !is_array($doc)) redirect('/admin/criativo.php' . ($b ? '?b=' . (int)$b['id'] : ''));
    $assets = [];
    foreach ((array)$doc['sections'] as $s) foreach ((array)($s['refs'] ?? []) as $rid) $assets[(int)$rid] = true;
    $rows = [];
    foreach (cc_assets_by_ids($pdo, array_keys($assets)) as $x) $rows[(int)$x['id']] = $x;
    ?><!DOCTYPE html>
<html lang="pt"><head><?php render_head('Tratamento · ' . (string)$doc['title']); ?>
<style>
  body { background: #fff; color: #111; font: 15px/1.6 var(--body); margin: 0; }
  .tr { max-width: 860px; margin: 0 auto; padding: 48px 32px 80px; }
  .tr .eyebrow { font: 600 11px var(--body); letter-spacing: .12em; text-transform: uppercase; color: #5c5c5c; }
  .tr h1 { font: 700 40px/1.1 var(--head); margin: 8px 0 10px; }
  .tr h1::after { content: '.'; color: #FFC718; }
  .tr .log { font-size: 19px; color: #333; margin: 0 0 32px; }
  .tr h2 { font: 700 21px var(--head); margin: 34px 0 8px; break-after: avoid; }
  .tr p { white-space: pre-line; margin: 0 0 12px; }
  .tr .refs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 10px 0 0; break-inside: avoid; }
  .tr .refs img { width: 100%; aspect-ratio: 4/3; object-fit: cover; border-radius: 6px; background: #eee; }
  .tr .bar { display: flex; gap: 10px; margin-bottom: 28px; }
  @media print { .tr .bar { display: none; } .tr { padding: 0; } @page { margin: 18mm; } }
</style></head><body>
<main class="tr">
  <div class="bar"><button class="btn" type="button" onclick="window.print()">Imprimir / guardar em PDF</button>
    <a class="btn ghost" href="/admin/criativo.php?b=<?= (int)$b['id'] ?>">Voltar ao quadro</a></div>
  <div class="eyebrow">Tratamento · <?= e((string)$b['title']) ?></div>
  <h1><?= e((string)$doc['title']) ?></h1>
  <p class="log"><?= e((string)$doc['logline']) ?></p>
  <?php foreach ((array)$doc['sections'] as $s): ?>
    <h2><?= e((string)($s['heading'] ?? '')) ?></h2>
    <p><?= e((string)($s['body'] ?? '')) ?></p>
    <?php $imgs = array_filter(array_map(fn($id) => $rows[(int)$id] ?? null, (array)($s['refs'] ?? [])), fn($x) => $x && $x['thumbs']); ?>
    <?php if ($imgs): ?><div class="refs"><?php foreach (array_slice($imgs, 0, 6) as $x): ?><img src="<?= e(cc_file_url($x, 768)) ?>" alt="<?= e((string)$x['title']) ?>"><?php endforeach; ?></div><?php endif; ?>
  <?php endforeach; ?>
</main></body></html><?php
    exit;
}

// ── um quadro ───────────────────────────────────────────────
$boardId = (int)($_GET['b'] ?? 0);
if ($boardId) {
    $b = cc_board_get($pdo, $boardId);
    if (!$b || $b['status'] === 'deleted') { flash('Este quadro já não existe.', 'err'); redirect('/admin/criativo.php'); }
    $path = cc_board_path($pdo, $b);
    $crumbs = array_map(fn($x) => (string)$x['title'], $path);
    render_header($b['title'], 'criativo', 'cc-full', $crumbs);
    render_flashes();
    ?>
<link rel="stylesheet" href="<?= asset_url('cc.css') ?>">
<div class="cc cc-boardview" id="cc" data-view="board" data-board="<?= (int)$b['id'] ?>" data-card="<?= (int)($_GET['card'] ?? 0) ?>"
     data-edit="<?= $canEdit && $b['status'] === 'active' ? 1 : 0 ?>" data-manage="<?= $canManage ? 1 : 0 ?>">
  <div class="ccb-loading" role="status"><span class="spin" aria-hidden="true"></span> A abrir o quadro…</div>
</div>
<noscript><div class="panel">O quadro precisa de JavaScript.</div></noscript>
<script src="<?= asset_url('cc.js') ?>" defer></script>
<?php
    render_footer();
    exit;
}

// ── os separadores ──────────────────────────────────────────
$tab = isset(CC_TABS[$_GET['t'] ?? '']) ? (string)$_GET['t'] : 'projetos';
$inboxN = (int)$pdo->query('SELECT COUNT(*) FROM cc_assets WHERE inbox = 1 AND deleted_at IS NULL')->fetchColumn();
render_header('Centro Criativo', 'criativo', '', [CC_TABS[$tab]]);
render_flashes();
?>
<link rel="stylesheet" href="<?= asset_url('cc.css') ?>">
<div class="page-head">
  <div><div class="h1-row"><h1>Centro Criativo</h1><?= cfg_link('criativo') ?></div>
    <p class="sub">Quadros por projeto, biblioteca de referências com IA, caixa de entrada e pré-criativo ligado à proposta.</p></div>
  <?php if ($canEdit): ?>
    <div class="actions" style="margin:0">
      <?php if ($tab === 'projetos'): ?><button type="button" class="btn" data-cc-new-board><?= icon('plus') ?> Quadro</button><?php endif; ?>
      <?php if (in_array($tab, ['biblioteca', 'entrada'], true)): ?>
        <button type="button" class="btn ghost" data-cc-link><?= icon('link') ?> Link</button>
        <label class="btn"><?= icon('upload') ?> Carregar<input type="file" multiple accept="image/*,video/*,.pdf" data-cc-upload hidden></label>
      <?php endif; ?>
      <?php if ($tab === 'biblioteca'): ?><button type="button" class="btn ghost" data-cc-import><?= icon('archive') ?> Importar</button><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<nav class="tabs" aria-label="Separadores">
  <?php foreach (CC_TABS as $k => $label): ?>
    <a href="/admin/criativo.php<?= $k !== 'projetos' ? '?t=' . $k : '' ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?><?= $k === 'entrada' && $inboxN ? ' <em class="cc-n">' . $inboxN . '</em>' : '' ?></a>
  <?php endforeach; ?>
</nav>
<div class="cc" id="cc" data-view="<?= e($tab) ?>" data-edit="<?= $canEdit ? 1 : 0 ?>" data-manage="<?= $canManage ? 1 : 0 ?>">
<?php if ($tab === 'review'): ?>
  <div class="panel cc-soon">
    <h2>Review</h2>
    <p>Versões v001, v002… lado a lado, comentários no frame exato (com desenho), <b>Aprovar</b> · <b>Pedir alterações</b>, rondas contadas
       e aviso ao Comercial quando passam das incluídas — e o portal do cliente, sem conta, para dar feedback.</p>
    <p class="muted">Chega na fase seguinte do Centro Criativo. A base de dados já guarda versões, comentários com timecode e o link por negócio.</p>
  </div>
<?php else: ?>
  <div class="ccb-loading" role="status"><span class="spin" aria-hidden="true"></span> A carregar…</div>
<?php endif; ?>
</div>

<div class="mdl" id="mdl-cc-board" role="dialog" aria-modal="true" aria-labelledby="mdl-cc-board-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card" data-cc-board-form autocomplete="off">
    <h3 id="mdl-cc-board-t">Novo quadro</h3>
    <p class="muted">Um quadro livre (moodboard, ideias) ou ligado a um negócio.</p>
    <label class="field"><span>Nome</span><input type="text" name="title" required maxlength="200" placeholder="Ex.: Moodboard verão"></label>
    <label class="field"><span>Negócio (opcional)</span><input type="search" name="dq" placeholder="ID ou nome do negócio" data-cc-deal-q>
      <input type="hidden" name="deal"><span class="cc-deal-res" data-cc-deal-res></span></label>
    <div class="actions"><button class="btn" type="submit">Criar quadro</button><button class="btn ghost" type="button" data-mdl-close>Cancelar</button></div>
  </form>
</div>
<div class="mdl" id="mdl-cc-import" role="dialog" aria-modal="true" aria-labelledby="mdl-cc-import-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card" data-cc-import-form autocomplete="off">
    <h3 id="mdl-cc-import-t">Importar para um quadro novo</h3>
    <p class="muted">ZIP, PDF ou Markdown (as exportações do Milanote) ou uma imagem. Cria um quadro com os cartões em grelha;
       as notas ficam como notas e as imagens entram na biblioteca<?= $canManage ? ' (com a análise da IA, se estiver ligada)' : '' ?>.</p>
    <label class="field"><span>Ficheiro</span><input type="file" name="file" required accept=".zip,.pdf,.md,.markdown,.txt,image/*"></label>
    <label class="field"><span>Nome do quadro (opcional)</span><input type="text" name="title" maxlength="200"></label>
    <p class="muted small">Para uma pasta de imagens: crie um quadro e arraste a pasta para lá.</p>
    <div class="actions"><button class="btn" type="submit">Importar</button><button class="btn ghost" type="button" data-mdl-close>Cancelar</button></div>
  </form>
</div>
<script src="<?= asset_url('cc.js') ?>" defer></script>
<?php render_footer();
