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
 *   ?rv=<peça>                                 a sala de review de uma peça (leitor, comentários, versões)
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/cc.php';
require_once __DIR__ . '/inc/cc_review.php';
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

// ── a sala de review de uma peça ────────────────────────────
$rvId = (int)($_GET['rv'] ?? 0);
if ($rvId && cc_rv_ready($pdo)) {
    $it = cc_rv_item($pdo, $rvId);
    if (!$it) { flash('Esta peça já não existe.', 'err'); redirect('/admin/criativo.php?t=review'); }
    $deal = files_deal_row($pdo, (int)$it['deal_id']);
    render_header($it['title'], 'criativo', '', ['Review', (string)($deal['code'] ?? ''), (string)$it['title']]);
    render_flashes();
    ?>
<link rel="stylesheet" href="<?= asset_url('review.css') ?>">
<div class="page-head rv-back">
  <div class="actions" style="margin:0">
    <a class="btn ghost sm" href="/admin/criativo.php?t=review<?= $deal ? '&amp;deal=' . (int)$deal['id'] : '' ?>"><?= icon('chev-left') ?> Review</a>
    <?php if ($deal && can('negocios')): ?><a class="btn ghost sm" href="/admin/negocio.php?id=<?= (int)$deal['id'] ?>#c-review"><?= icon('negocios') ?> <?= e($deal['code'] . ' · ' . $deal['name']) ?></a><?php endif; ?>
  </div>
  <p class="muted small rv-keys">← → um frame · Shift ±1 s · Espaço · J K L · I O intervalo · C comentar · Esc</p>
</div>
<div id="rv-room" data-item="<?= (int)$it['id'] ?>" data-edit="<?= $canEdit ? 1 : 0 ?>"></div>
<script src="<?= asset_url('review.js') ?>"></script>
<script>
(function () {
  var el = document.getElementById('rv-room'), id = el.dataset.item, API = '/admin/rv_api.php';
  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  function get(a, q) {
    return fetch(API + '?a=' + a + '&' + new URLSearchParams(q || {}), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) throw new Error(j.error || 'Não foi possível abrir.'); return j; });
  }
  function call(a, p) {
    var body = p instanceof FormData ? p : new URLSearchParams(p || {});
    return fetch(API + '?a=' + a, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: body })
      .then(function (r) { return r.json().catch(function () { throw new Error('Não foi possível concluir. Tente outra vez.'); }); }, function () { throw new Error('Sem ligação. Tente outra vez.'); })
      .then(function (j) { if (!j.ok) throw new Error(j.error || 'Não foi possível concluir.'); return j; });
  }
  TopperReview.mount(el, {
    mode: 'team', lang: 'pt', edit: el.dataset.edit === '1', stages: <?= json_encode(CC_RV_STAGES, JSON_UNESCAPED_UNICODE) ?>,
    load: function () { return get('room', { item: id }); },
    poll: function (rev) { return get('poll', { item: id, rev: rev }); },
    call: call,
    exportUrl: function (v, fmt, client) { return API + '?a=export&v=' + v + '&fmt=' + fmt + (client ? '&client=1' : ''); }
  });
})();
</script>
<?php
    render_footer();
    exit;
}

// ── um quadro ───────────────────────────────────────────────
$boardId = (int)($_GET['b'] ?? 0);
if ($boardId) {
    $b = cc_board_get($pdo, $boardId);
    if (!$b || $b['status'] === 'deleted') { flash('Este quadro já não existe.', 'err'); redirect('/admin/criativo.php'); }
    require_once __DIR__ . '/inc/cc_rt.php';
    if (!cc_board_access($pdo, $b)) { flash('Este quadro é de um negócio a que a sua conta não tem acesso.', 'err'); redirect('/admin/criativo.php'); }
    $rtCsp = cc_rt_csp($pdo);
    if ($rtCsp !== '') csp_send($rtCsp);   // o browser liga-se ao serviço de tempo real (§26)
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
<?php if ($rtCsp !== ''): ?>
<script src="<?= asset_url('vendor/rt/topper-rt.js') ?>" defer></script>
<?php endif; ?>
<script src="<?= asset_url('drive/core.js') ?>" defer></script>
<script src="<?= asset_url('cc_audio.js') ?>" defer></script>
<script src="<?= asset_url('cc_rt.js') ?>" defer></script>
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
      <?php if ($tab === 'review' && cc_rv_ready($pdo)): ?><a class="btn" href="#" data-new="#mdl-rv-new"><?= icon('plus') ?> Peça</a><?php endif; ?>
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
  <?php
  if (!cc_rv_ready($pdo)) {
      echo '<div class="panel"><p>A base de dados está a atualizar. Recarregue a página daqui a pouco.</p></div>';
  } else {
      $rvQ = trim((string)($_GET['q'] ?? ''));
      $rvF = (string)($_GET['f'] ?? 'open');
      $rvDeal = (int)($_GET['deal'] ?? 0);
      $rows = cc_rv_overview($pdo, ['q' => $rvQ, 'deal' => $rvDeal, 'open' => $rvF === 'open', 'status' => in_array($rvF, ['comments', 'approved', 'sent', 'changes', 'prep'], true) ? $rvF : '']);
      $flt = ['open' => 'Por aprovar', 'comments' => 'Com comentários', 'sent' => 'Enviadas', 'approved' => 'Aprovadas', 'all' => 'Todas'];
      $qs = fn(array $x) => '/admin/criativo.php?' . http_build_query(array_filter(['t' => 'review', 'q' => $rvQ, 'f' => $rvF, 'deal' => $rvDeal ?: null] + $x, fn($v) => $v !== null && $v !== ''));
      ?>
  <link rel="stylesheet" href="<?= asset_url('review.css') ?>">
  <form class="rv-toolbar" method="get" role="search">
    <input type="hidden" name="t" value="review"><input type="hidden" name="f" value="<?= e($rvF) ?>"><?php if ($rvDeal): ?><input type="hidden" name="deal" value="<?= $rvDeal ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($rvQ) ?>" placeholder="Peça, ID ou negócio" aria-label="Procurar peças">
    <?php foreach ($flt as $k => $label): ?><a class="chip-btn<?= $rvF === $k ? ' on' : '' ?>" href="<?= e($qs(['f' => $k])) ?>"<?= $rvF === $k ? ' aria-current="true"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
    <?php if ($rvDeal): ?><a class="chip-btn" href="<?= e($qs(['deal' => null])) ?>"><?= icon('x') ?> Só este negócio</a><?php endif; ?>
  </form>
  <?php if (!$rows): ?>
    <div class="panel"><p><?= $rvQ !== '' || $rvF !== 'open' ? 'Nenhuma peça com este filtro.' : 'Ainda não há peças em review.' ?></p>
      <p class="muted">Uma peça é o que se mostra ao cliente para aprovar (styleframes, animatic, offline, picture lock, finais, lote): v001, v002…, comentários no frame e o portal do cliente.<?= $canEdit ? ' Crie a primeira em «+ Peça».' : '' ?></p></div>
  <?php else:
      $byDeal = [];
      foreach ($rows as $r) $byDeal[(int)$r['deal_id']][] = $r;
      foreach ($byDeal as $did => $list): $f0 = $list[0]; ?>
    <h3 class="rv-group"><a href="<?= e($qs(['deal' => $did])) ?>"><?= e($f0['code']) ?></a> · <?= e($f0['deal_name']) ?><?= $f0['client'] !== $f0['deal_name'] ? ' · ' . e($f0['client']) : '' ?></h3>
    <div class="rv-grid">
      <?php foreach ($list as $r): $st = CC_RV_STATUS[$r['status']] ?? CC_RV_STATUS['prep']; ?>
        <a class="rv-card" href="/admin/criativo.php?rv=<?= (int)$r['id'] ?>">
          <span class="rv-poster"<?= $r['cur_poster'] ? ' style="background-image:url(\'/admin/rv_api.php?a=media&amp;v=' . (int)$r['cur_id'] . '&amp;k=poster\')"' : '' ?>>
            <span class="rv-st c-<?= e($st[2]) ?>"><?= e($st[0]) ?></span><?php if ($r['cur_num']): ?><span class="rv-v"><?= e(cc_rv_vlabel((int)$r['cur_num'])) ?></span><?php endif; ?></span>
          <span class="rv-cb"><b><?= e($r['title']) ?></b>
            <span class="small"><?= e(CC_RV_STAGES[$r['stage_key']] ?? '') ?> · <?= e(date('d/m H:i', strtotime((string)$r['updated_at']))) ?><?= (int)$r['shared'] ? ' · no portal' : '' ?></span>
            <?php if ((int)$r['open_n']): ?><span class="rv-n"><?= icon('bubble') ?> <?= (int)$r['open_n'] ?> por resolver</span><?php endif; ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endforeach; endif; } ?>
<?php else: ?>
  <div class="ccb-loading" role="status"><span class="spin" aria-hidden="true"></span> A carregar…</div>
<?php endif; ?>
</div>

<?php if ($tab === 'review' && cc_rv_ready($pdo) && $canEdit):
    $preDeal = (int)($_GET['deal'] ?? 0) ? files_deal_row($pdo, (int)$_GET['deal']) : null; ?>
<div class="mdl" id="mdl-rv-new" role="dialog" aria-modal="true" aria-labelledby="mdl-rv-new-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card" data-rv-new autocomplete="off">
    <h3 id="mdl-rv-new-t">Nova peça de review</h3>
    <p class="muted">O que se vai mostrar ao cliente para aprovar. As versões (v001, v002…) carregam-se na peça; os originais ficam no Drive, na pasta do negócio.</p>
    <label class="field"><span>Negócio</span><input type="search" name="dq" placeholder="ID ou nome do negócio" value="<?= $preDeal ? e($preDeal['code'] . ' · ' . $preDeal['name']) : '' ?>" data-rv-deal-q required>
      <input type="hidden" name="deal" value="<?= $preDeal ? (int)$preDeal['id'] : '' ?>"><span class="cc-deal-res" data-rv-deal-res></span></label>
    <label class="field" data-rv-line hidden><span>Frente</span><select name="line"></select></label>
    <label class="field"><span>Nome da peça</span><input type="text" name="title" required maxlength="200" placeholder="Ex.: Filme 30s"></label>
    <label class="field"><span>Etapa de aprovação</span><select name="stage"><?php foreach (CC_RV_STAGES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label>
    <div class="actions"><button class="btn" type="submit">Criar peça</button><button class="btn ghost" type="button" data-mdl-close>Cancelar</button></div>
  </form>
</div>
<script>
(function () {
  var f = document.querySelector('[data-rv-new]'); if (!f) return;
  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || '', res = f.querySelector('[data-rv-deal-res]'), q = f.querySelector('[data-rv-deal-q]'), t;
  function lines(id) {
    var box = f.querySelector('[data-rv-line]'), sel = box.querySelector('select');
    box.hidden = true; sel.innerHTML = '';
    if (!id) return;
    fetch('/admin/rv_api.php?a=lines&deal=' + id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok || !j.lines.length) return;
      sel.innerHTML = '<option value="0">Todo o negócio</option>' + j.lines.map(function (l) { var o = document.createElement('option'); o.value = l.id; o.textContent = l.name; return o.outerHTML; }).join('');
      box.hidden = false;
    });
  }
  q.addEventListener('input', function () {
    f.deal.value = ''; clearTimeout(t);
    t = setTimeout(function () {
      fetch('/admin/rv_api.php?a=deals&q=' + encodeURIComponent(q.value), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        res.innerHTML = '';
        (j.deals || []).slice(0, 8).forEach(function (d) {
          var b = document.createElement('button'); b.type = 'button'; b.className = 'chip-btn'; b.textContent = d.label;
          b.addEventListener('click', function () { f.deal.value = d.id; q.value = d.label; res.innerHTML = ''; lines(d.id); f.title.focus(); });
          res.appendChild(b);
        });
      });
    }, 200);
  });
  if (f.deal.value) lines(f.deal.value);
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!f.deal.value) { q.focus(); window.topper && window.topper.toast('Escolha o negócio na lista.', { err: true }); return; }
    var b = f.querySelector('button[type=submit]'); b.disabled = true;
    fetch('/admin/rv_api.php?a=item_new', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF, Accept: 'application/json' },
      body: new URLSearchParams({ deal: f.deal.value, line: f.line ? f.line.value : '', title: f.title.value, stage: f.stage.value }) })
      .then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) throw new Error(j.error); location.href = j.url; })
      .catch(function (er) { b.disabled = false; window.topper && window.topper.toast(er.message || 'Não foi possível criar.', { err: true }); });
  });
})();
</script>
<?php endif; ?>
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
<script src="<?= asset_url('drive/core.js') ?>" defer></script>
<script src="<?= asset_url('cc_audio.js') ?>" defer></script>
<script src="<?= asset_url('cc.js') ?>" defer></script>
<?php render_footer();
