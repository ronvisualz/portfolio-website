<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/leads.php';
require __DIR__ . '/inc/pipeline.php';
$user = require_login();
require_page('pipeline');

// Pipeline: um separador por funil (kanban da fase comercial), mais
// «Concluídos» e «Perdidos». Os negócios em produção vivem na aba Negócios.

$me = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$me->execute([(int)$user['id']]);
$me = $me->fetch();

function pipe_url(array $q = []): string {
    return '/admin/pipeline.php' . ($q ? '?' . http_build_query($q) : '');
}

/** O negócio, se existir e estiver dentro do meu âmbito. */
function deal_in_reach(PDO $pdo, int $id): ?array {
    $d = deal_get($pdo, $id);
    if (!$d || $d['code'] === null) return null;
    if ($d['owner_id'] === null ? !sees_unassigned(current_user()) : !in_my_scope($pdo, 'pipeline', (int)$d['owner_id'])) return null;
    return $d;
}

// ── ações ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do   = (string)($_POST['do'] ?? '');
    $back = (string)($_POST['back'] ?? '');
    $back = str_starts_with($back, '/admin/pipeline.php') ? $back : pipe_url();

    $deal = deal_in_reach($pdo, (int)($_POST['id'] ?? 0));
    if (!$deal) { flash('Negócio não encontrado.', 'err'); redirect($back); }

    try {
        switch ($do) {
            case 'move':
                flash(deal_move($pdo, $deal, (int)($_POST['stage_id'] ?? 0)));
                break;
            case 'lost':
                $r = (string)($_POST['reason'] ?? '');
                if ($r === 'Outro' && trim((string)($_POST['reason_other'] ?? '')) !== '') $r = 'Outro: ' . trim((string)$_POST['reason_other']);
                deal_mark_lost($pdo, $deal, $r);
                flash($deal['code'] . ' marcado como perdido.');
                break;
            case 'reopen':
                deal_reopen($pdo, $deal);
                flash($deal['code'] . ' reaberto.');
                break;
            case 'funnel':
                deal_change_funnel($pdo, $deal, (int)($_POST['funnel_id'] ?? 0), (int)($_POST['stage_id'] ?? 0));
                flash($deal['code'] . ' mudou de funil.');
                break;
            case 'save':
                $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 160) ?: $deal['name'];
                $raw  = trim((string)($_POST['value_estimate'] ?? ''));
                $val  = $raw === '' ? null : money_in($raw);
                $owner = $deal['owner_id'] !== null ? (int)$deal['owner_id'] : null;
                $rawOwner = (string)($_POST['owner_id'] ?? '');
                if ($rawOwner !== '' && (int)$rawOwner !== $owner) {
                    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                    $st->execute([(int)$rawOwner]);
                    $t = $st->fetch();
                    if ($t && can_assign_to($me, $t)) {
                        $owner = (int)$t['id'];
                        deal_log($pdo, (int)$deal['id'], 'assign', ['note' => 'Responsável: ' . $t['name']]);
                    } else {
                        flash('Não pode atribuir a essa pessoa.', 'err');
                    }
                }
                $pdo->prepare('UPDATE deals SET name = ?, value_estimate = ?, owner_id = ?, notes = ? WHERE id = ?')
                    ->execute([$name, $val, $owner, mb_substr((string)($_POST['notes'] ?? ''), 0, 8000), $deal['id']]);
                flash('Negócio guardado.');
                break;
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect($back);
}

// ── dados ───────────────────────────────────────────────────
$funnels = funnels_active($pdo);
$view = (string)($_GET['v'] ?? '');
$fid  = (int)($_GET['f'] ?? ($funnels[0]['id'] ?? 0));
if (!in_array($view, ['concluidos', 'perdidos'], true)) {
    $view = 'funil';
    if (!array_filter($funnels, fn($f) => (int)$f['id'] === $fid)) $fid = (int)($funnels[0]['id'] ?? 0);
}

[$scopeSql, $scopeArgs] = scope_sql($pdo, 'pipeline', 'd.owner_id');
$reach = sees_unassigned($me) ? "($scopeSql OR d.owner_id IS NULL)" : $scopeSql;
$selectDeals = function (string $where, array $args = []) use ($pdo, $reach, $scopeArgs) {
    $st = $pdo->prepare(
        "SELECT d.*, u.name AS owner_name, u.color AS owner_color, c.name AS contact_name, f.name AS funnel_name,
                s.name AS stage_name, ls.name AS lost_stage_name, of.name AS origin_funnel_name
           FROM deals d
           LEFT JOIN users u ON u.id = d.owner_id
           LEFT JOIN contacts c ON c.id = d.contact_id
           LEFT JOIN funnels f ON f.id = d.funnel_id
           LEFT JOIN funnels of ON of.id = d.origin_funnel_id
           LEFT JOIN funnel_stages s ON s.id = d.stage_id
           LEFT JOIN funnel_stages ls ON ls.id = d.lost_stage_id
          WHERE $where AND $reach"
    );
    $st->execute(array_merge($args, $scopeArgs));
    return $st->fetchAll();
};

$count = fn(string $phase) => count($selectDeals('d.phase = ?', [$phase]));
$canEdit = can('pipeline', PERM_EDIT);
$openId  = (int)($_GET['deal'] ?? 0);
$open    = $openId ? deal_in_reach($pdo, $openId) : null;
$self    = pipe_url(array_filter(['v' => $view === 'funil' ? null : $view, 'f' => $view === 'funil' ? $fid : null]));

render_header('Pipeline', 'pipeline');
render_flashes();
?>
<h1>Pipeline</h1>

<div class="tabs">
  <?php foreach ($funnels as $f): ?>
    <a href="<?= e(pipe_url(['f' => $f['id']])) ?>" class="<?= $view === 'funil' && (int)$f['id'] === $fid ? 'on' : '' ?>"><?= e($f['name']) ?></a>
  <?php endforeach; ?>
  <a href="<?= e(pipe_url(['v' => 'concluidos'])) ?>" class="<?= $view === 'concluidos' ? 'on' : '' ?>">Concluídos (<?= $count('done') ?>)</a>
  <a href="<?= e(pipe_url(['v' => 'perdidos'])) ?>" class="<?= $view === 'perdidos' ? 'on' : '' ?>">Perdidos (<?= $count('lost') ?>)</a>
</div>
<?php if (can('pipeline', PERM_MANAGE)): ?>
  <div class="sub"><a class="lnk" href="/admin/funis.php">Configurar funis e etapas</a></div>
<?php endif; ?>

<?php if ($view === 'funil' && $fid):
    $stages = commercial_stages(funnel_stages($pdo, $fid));
    $deals  = $selectDeals("d.phase = 'pipeline' AND d.funnel_id = ?", [$fid]);
    $byStage = [];
    foreach ($deals as $d) $byStage[(int)$d['stage_id']][] = $d;
?>
<div class="board" data-csrf="<?= e(csrf_token()) ?>" data-back="<?= e($self) ?>">
  <?php foreach ($stages as $s): $sid = (int)$s['id']; $cards = $byStage[$sid] ?? [];
        $total = array_sum(array_map(fn($d) => (float)$d['value_estimate'], $cards)); ?>
    <section class="col <?= $s['kind'] === 'kickoff' ? 'col-win' : '' ?>" data-stage="<?= $sid ?>">
      <header>
        <b><?= e($s['name']) ?></b>
        <?php if ($s['kind'] === 'kickoff'): ?>
          <span class="muted">largue aqui para fechar o negócio</span>
        <?php else: ?>
          <span class="muted"><?= count($cards) ?> · <?= eur0($total) ?></span>
        <?php endif; ?>
      </header>
      <div class="cards">
      <?php foreach ($cards as $d): ?>
        <article class="dcard" draggable="<?= $canEdit ? 'true' : 'false' ?>" data-id="<?= (int)$d['id'] ?>">
          <a class="dname" href="<?= e(pipe_url(['f' => $fid, 'deal' => $d['id']])) ?>#negocio"><?= e($d['name']) ?></a>
          <div class="muted"><?= e($d['code']) ?><?= $d['contact_name'] && $d['contact_name'] !== $d['name'] ? ' · ' . e($d['contact_name']) : '' ?></div>
          <div class="drow">
            <span><?= $d['value_estimate'] !== null ? eur0((float)$d['value_estimate']) : '<span class="muted">sem valor</span>' ?></span>
            <span class="owner"><span class="dot" style="background:<?= e($d['owner_color'] ?: '#ccc') ?>"></span> <?= e($d['owner_name'] ?? 'Por atribuir') ?></span>
          </div>
          <?php if ($canEdit): ?>
          <form method="post" class="dmove"><?= csrf_field() ?>
            <input type="hidden" name="do" value="move"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <input type="hidden" name="back" value="<?= e($self) ?>">
            <select name="stage_id" class="mini" onchange="this.form.submit()" aria-label="Mover para">
              <?php foreach ($stages as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $sid ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
            </select>
          </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<?php if (!$funnels): ?><p class="empty">Ainda não há funis.</p><?php endif; ?>

<?php elseif ($view === 'concluidos' || $view === 'perdidos'):
    $rows = $selectDeals('d.phase = ?', [$view === 'concluidos' ? 'done' : 'lost']);
    usort($rows, fn($a, $b) => strcmp((string)($b['done_at'] ?? $b['lost_at']), (string)($a['done_at'] ?? $a['lost_at'])));
?>
<div class="tablewrap">
<table>
  <thead><tr><th>Negócio</th><th>Funil</th><?= $view === 'perdidos' ? '<th>Perdido em</th><th>Motivo</th>' : '<th class="num">Valor</th>' ?><th>Responsável</th><th>Data</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nada aqui.</td></tr><?php endif; ?>
  <?php foreach ($rows as $d): ?>
    <tr>
      <td><span class="b"><?= e($d['name']) ?></span><div class="muted" style="font-size:0.62rem"><?= e($d['code']) ?></div></td>
      <td><?= e($d['funnel_name'] ?? '—') ?>
        <?php if ($d['origin_funnel_id'] && $d['origin_funnel_id'] !== $d['funnel_id']): ?><div class="muted" style="font-size:0.62rem">começou em <?= e($d['origin_funnel_name']) ?></div><?php endif; ?></td>
      <?php if ($view === 'perdidos'): ?>
        <td><?= e($d['lost_stage_name'] ?? '—') ?></td><td><?= e($d['lost_reason']) ?></td>
      <?php else: ?>
        <td class="num"><?= eur0((float)($d['budget'] ?: $d['value_estimate'])) ?></td>
      <?php endif; ?>
      <td><?= e($d['owner_name'] ?? '—') ?></td>
      <td class="nowrap"><?= e(($t = $d['done_at'] ?? $d['lost_at']) ? date('d/m/Y', strtotime($t)) : '—') ?></td>
      <td class="num nowrap">
        <?php if ($view === 'perdidos' && $canEdit): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="do" value="reopen"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <input type="hidden" name="back" value="<?= e($self) ?>">
            <button class="btn ghost sm" type="submit">Reabrir</button></form>
        <?php endif; ?>
        <a class="lnk" href="<?= e(pipe_url(['v' => $view, 'deal' => $d['id']])) ?>#negocio">Abrir</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php // ── painel do negócio ──
if ($open):
    $assignees = assignable_users($pdo, $me);
    $hist = $pdo->prepare(
        'SELECT h.*, u.name AS user_name, fs.name AS from_stage, ts.name AS to_stage, ff.name AS from_funnel, tf.name AS to_funnel
           FROM deal_history h
           LEFT JOIN users u ON u.id = h.user_id
           LEFT JOIN funnel_stages fs ON fs.id = h.from_stage_id
           LEFT JOIN funnel_stages ts ON ts.id = h.to_stage_id
           LEFT JOIN funnels ff ON ff.id = h.from_funnel_id
           LEFT JOIN funnels tf ON tf.id = h.to_funnel_id
          WHERE h.deal_id = ? ORDER BY h.created_at DESC, h.id DESC');
    $hist->execute([$open['id']]);
    $hist = $hist->fetchAll();
    $curStage = stage_get($pdo, (int)$open['stage_id']);
    $eventLabel = ['convert' => 'Convertida em negócio', 'stage' => 'Mudou de etapa', 'funnel' => 'Mudou de funil', 'lost' => 'Perdido',
                   'reopen' => 'Reaberto', 'assign' => 'Responsável', 'archive' => 'Arquivada', 'reactivate' => 'Reativada'];
?>
<h2 id="negocio"><span class="keep"><?= e($open['code']) ?> · <?= e($open['name']) ?></span></h2>
<div class="sub"><?= e(funnel_get($pdo, (int)$open['funnel_id'])['name'] ?? '—') ?> · <?= e($curStage['name'] ?? '—') ?>
  <?= $open['phase'] === 'lost' ? ' · <span class="chip no">Perdido</span>' : '' ?>
  <?= $open['phase'] === 'done' ? ' · <span class="chip ok">Concluído</span>' : '' ?>
  <?= can('negocios') ? ' · <a class="lnk" href="/admin/negocio.php?id=' . (int)$open['id'] . '">Abrir a ficha completa</a>' : '' ?></div>

<?php if ($canEdit): ?>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
  <input type="hidden" name="back" value="<?= e($self . '&deal=' . $open['id'] . '#negocio') ?>">
  <div class="grid">
    <div class="field"><label for="dn">Nome</label><input type="text" id="dn" name="name" maxlength="160" value="<?= e($open['name']) ?>"></div>
    <div class="field"><label for="dv">Valor estimado (€)</label>
      <input type="text" id="dv" name="value_estimate" inputmode="decimal" value="<?= $open['value_estimate'] !== null ? e(number_format((float)$open['value_estimate'], 2, ',', '')) : '' ?>" placeholder="até haver orçamento"></div>
    <div class="field"><label for="do">Responsável</label>
      <select id="do" name="owner_id">
        <?php if ($open['owner_id'] !== null && !in_array((int)$open['owner_id'], array_map(fn($u) => (int)$u['id'], $assignees), true)): ?>
          <option value="<?= (int)$open['owner_id'] ?>" selected><?= e($pdo->query('SELECT name FROM users WHERE id = ' . (int)$open['owner_id'])->fetchColumn()) ?></option>
        <?php endif; ?>
        <?php if ($open['owner_id'] === null): ?><option value="" selected>— Por atribuir —</option><?php endif; ?>
        <?php foreach ($assignees as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (string)$open['owner_id'] === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field wide"><label for="dno">Notas</label><textarea id="dno" name="notes"><?= e((string)$open['notes']) ?></textarea></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar</button>
    <a class="btn ghost" href="<?= e($self) ?>">Fechar</a></div>
</form>

<?php if ($open['phase'] === 'pipeline'): ?>
<h2>Perdido</h2>
<form method="post" class="bar">
  <?= csrf_field() ?><input type="hidden" name="do" value="lost"><input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
  <input type="hidden" name="back" value="<?= e($self) ?>">
  <select name="reason" required aria-label="Motivo"><option value="">Motivo…</option>
    <?php foreach (LOST_REASONS as $r): ?><option><?= e($r) ?></option><?php endforeach; ?></select>
  <input type="text" name="reason_other" maxlength="200" placeholder="Detalhe (opcional)">
  <button class="btn danger sm" type="submit">Marcar como perdido</button>
</form>
<?php endif; ?>

<?php if (in_array($open['phase'], ['pipeline', 'lost'], true)):
    $otherFunnels = array_filter($funnels, fn($f) => (int)$f['id'] !== (int)$open['funnel_id']);
    $variable = $curStage && in_array($curStage['kind'], ['commercial', 'production'], true);
?>
<h2>Mudar de funil</h2>
<form method="post" class="bar">
  <?= csrf_field() ?><input type="hidden" name="do" value="funnel"><input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
  <input type="hidden" name="back" value="<?= e($self . '&deal=' . $open['id'] . '#negocio') ?>">
  <?php if ($variable): ?>
    <select name="stage_id" required aria-label="Etapa de destino"><option value="">Funil e etapa de destino…</option>
      <?php foreach ($otherFunnels as $f): ?>
        <optgroup label="<?= e($f['name']) ?>">
          <?php foreach (commercial_stages(funnel_stages($pdo, (int)$f['id'])) as $s): if ($s['kind'] === 'kickoff') continue; ?>
            <option value="<?= (int)$s['id'] ?>" data-funnel="<?= (int)$f['id'] ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <input type="hidden" name="funnel_id" value="">
    <script>
      (function (f) {
        var sel = f.querySelector('select[name=stage_id]'), hid = f.querySelector('input[name=funnel_id]');
        sel.addEventListener('change', function () { var o = sel.selectedOptions[0]; hid.value = o ? (o.dataset.funnel || '') : ''; });
      })(document.currentScript.parentNode);
    </script>
  <?php else: ?>
    <select name="funnel_id" required aria-label="Funil"><option value="">Funil…</option>
      <?php foreach ($otherFunnels as $f): ?><option value="<?= (int)$f['id'] ?>"><?= e($f['name']) ?></option><?php endforeach; ?>
    </select>
    <span class="muted" style="font-size:0.75rem">fica na mesma etapa (<?= e($curStage['name'] ?? '') ?>)</span>
  <?php endif; ?>
  <button class="btn ghost sm" type="submit">Mudar</button>
</form>
<?php endif; ?>
<?php endif; ?>

<h2>Histórico</h2>
<div class="tablewrap"><table>
  <thead><tr><th>Quando</th><th>O quê</th><th>Detalhe</th><th>Quem</th></tr></thead>
  <tbody>
  <?php foreach ($hist as $h): ?>
    <tr><td class="nowrap"><?= e(date('d/m/Y H:i', strtotime($h['created_at']))) ?></td>
      <td><?= e($eventLabel[$h['event']] ?? $h['event']) ?></td>
      <td class="muted" style="font-size:0.75rem"><?php
        $parts = [];
        if ($h['event'] === 'funnel') $parts[] = e(($h['from_funnel'] ?? '?') . ' › ' . ($h['from_stage'] ?? '?') . ' → ' . ($h['to_funnel'] ?? '?') . ' › ' . ($h['to_stage'] ?? '?'));
        elseif ($h['from_stage'] || $h['to_stage']) $parts[] = e(trim(($h['from_stage'] ?? '') . ' → ' . ($h['to_stage'] ?? ''), ' →'));
        if ($h['note'] !== '') $parts[] = e($h['note']);
        echo implode(' · ', $parts);
      ?></td>
      <td><?= e($h['user_name'] ?? '—') ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<?php if ($canEdit && $view === 'funil'): ?>
<script>
// Arrastar e largar entre colunas. Sem JavaScript, o menu «Mover para» de cada cartão faz o mesmo.
(function () {
  var board = document.querySelector('.board'); if (!board) return;
  var dragged = null;
  board.addEventListener('dragstart', function (e) {
    var c = e.target.closest('.dcard'); if (!c) return;
    dragged = c; c.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', c.dataset.id);
  });
  board.addEventListener('dragend', function () {
    if (dragged) dragged.classList.remove('dragging'); dragged = null;
    board.querySelectorAll('.col.over').forEach(function (c) { c.classList.remove('over'); });
  });
  board.addEventListener('dragover', function (e) {
    var col = e.target.closest('.col'); if (!col || !dragged) return;
    e.preventDefault();
    board.querySelectorAll('.col.over').forEach(function (c) { if (c !== col) c.classList.remove('over'); });
    col.classList.add('over');
  });
  board.addEventListener('drop', function (e) {
    var col = e.target.closest('.col'); if (!col || !dragged) return;
    e.preventDefault();
    var form = dragged.querySelector('form.dmove');
    if (col.contains(dragged)) return;
    form.querySelector('select').value = col.dataset.stage;
    form.submit();   // grava no servidor, que regista o histórico e devolve a página atualizada
  });
})();
</script>
<?php endif; ?>

<?php render_footer();
