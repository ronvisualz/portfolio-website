<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/leads.php';
require_once __DIR__ . '/inc/pipeline.php';
require_once __DIR__ . '/inc/pipeline_flow.php';
require_once __DIR__ . '/inc/undo.php';
require_once __DIR__ . '/inc/alerts.php';
require_once __DIR__ . '/inc/interactions.php';
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
                $snap = undo_deal_snapshot($deal);
                $to = (int)($_POST['stage_id'] ?? 0);
                flow_created(null, true);
                $msg = deal_move($pdo, $deal, $to, (string)($_POST['force'] ?? ''));
                flash($msg, 'ok', (int)$deal['stage_id'] !== $to ? undo_push('deal_stage', $snap + ['to_stage' => $to, 'auto_tasks' => flow_created()]) : '');
                break;
            case 'lost':
                $r = (string)($_POST['reason'] ?? '');
                if ($r === 'Outro' && trim((string)($_POST['reason_other'] ?? '')) !== '') $r = 'Outro: ' . trim((string)$_POST['reason_other']);
                deal_mark_lost($pdo, $deal, $r);
                flash($deal['code'] . ' marcado como perdido.', 'ok', undo_push('deal_lost', ['id' => (int)$deal['id'], 'stage_id' => $deal['stage_id']]));
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
// Os concluídos vivem agora na aba Negócios (§15.1): os endereços antigos vão para lá.
if ($view === 'concluidos') redirect('/admin/negocios.php?t=concluidos');
$fid  = (int)($_GET['f'] ?? ($funnels[0]['id'] ?? 0));
if (!in_array($view, ['perdidos'], true)) {
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

/** Vistas guardadas do quadro (§11.12). */
const PIPE_VISTAS = ['' => 'Todos', 'meus' => 'Os meus', 'risco' => 'Em risco', 'mes' => 'Este mês'];
$vista = isset(PIPE_VISTAS[$_GET['vista'] ?? '']) ? (string)($_GET['vista'] ?? '') : '';
$staleLimit = alert_limits($pdo)['deal'];
$monthStart = (new DateTimeImmutable(today_lisbon()))->format('Y-m-01');
$inVista = function (array $d, string $v) use ($me, $staleLimit, $monthStart): bool {
    return match ($v) {
        'meus'  => (int)$d['owner_id'] === (int)$me['id'],
        'risco' => days_since(deal_last_move($d)) > $staleLimit,
        'mes'   => substr((string)$d['converted_at'], 0, 10) >= $monthStart,
        default => true,
    };
};
$canEdit = can('pipeline', PERM_EDIT);
$openId  = (int)($_GET['deal'] ?? 0);
$open    = $openId ? deal_in_reach($pdo, $openId) : null;
// Depois do Kickoff (em produção ou concluído) o negócio vive na aba Negócios (§15.1).
if ($open && in_array($open['phase'], ['production', 'done'], true) && can('negocios')) redirect('/admin/negocio.php?id=' . (int)$open['id']);
$self    = pipe_url(array_filter(['v' => $view === 'funil' ? null : $view, 'f' => $view === 'funil' ? $fid : null, 'vista' => $view === 'funil' && $vista !== '' ? $vista : null]));

render_header('Pipeline', 'pipeline');
render_flashes();
?>
<h1>Pipeline</h1>

<div class="tabs">
  <?php foreach ($funnels as $f): ?>
    <a href="<?= e(pipe_url(['f' => $f['id']])) ?>" class="<?= $view === 'funil' && (int)$f['id'] === $fid ? 'on' : '' ?>"><?= e($f['name']) ?></a>
  <?php endforeach; ?>
  <a href="<?= e(pipe_url(['v' => 'perdidos'])) ?>" class="<?= $view === 'perdidos' ? 'on' : '' ?>">Perdidos (<?= $count('lost') ?>)</a>
</div>
<?php if (can('pipeline', PERM_MANAGE)): ?>
  <div class="sub"><a class="lnk" href="/admin/funis.php">Configurar funis e etapas</a></div>
<?php endif; ?>

<?php if ($view === 'funil' && $fid):
    $stages = commercial_stages(funnel_stages($pdo, $fid));
    $allDeals = $selectDeals("d.phase = 'pipeline' AND d.funnel_id = ?", [$fid]);
    $vistaCount = [];
    foreach (array_keys(PIPE_VISTAS) as $k) $vistaCount[$k] = count(array_filter($allDeals, fn($d) => $inVista($d, $k)));
    $deals  = array_values(array_filter($allDeals, fn($d) => $inVista($d, $vista)));
    $byStage = [];
    foreach ($deals as $d) $byStage[(int)$d['stage_id']][] = $d;
    // Ganhos este mês: chegaram a Kickoff neste funil desde o dia 1.
    $won = $selectDeals("d.phase IN ('production','done') AND d.funnel_id = ? AND d.id IN (
                            SELECT h.deal_id FROM deal_history h JOIN funnel_stages ts ON ts.id = h.to_stage_id
                             WHERE h.event = 'stage' AND ts.kind = 'kickoff' AND h.created_at >= ?)", [$fid, $monthStart . ' 00:00:00']);
    $won = array_values(array_filter($won, fn($d) => $inVista($d + ['converted_at' => $monthStart], $vista === 'risco' ? '' : $vista)));
    $wonValue = array_sum(array_map(fn($d) => (float)($d['budget'] ?: $d['value_estimate']), $won));
    $openValue = array_sum(array_map(fn($d) => (float)$d['value_estimate'], $deals));
    $staleN = count(array_filter($deals, fn($d) => days_since(deal_last_move($d)) > $staleLimit));
?>
<div class="stats">
  <div class="stat hero"><div class="k">Em aberto</div><div class="v" data-kpi="open-value" data-n="<?= $openValue ?>"><?= eur0($openValue) ?></div><div class="n">valor estimado no funil</div></div>
  <div class="stat"><div class="k">Negócios</div><div class="v" data-kpi="open-count" data-n="<?= count($deals) ?>"><?= count($deals) ?></div><div class="n">na fase comercial</div></div>
  <a class="stat<?= $staleN ? ' risk' : '' ?>" href="<?= e(pipe_url(['f' => $fid, 'vista' => 'risco'])) ?>"><div class="k">Sem movimento</div><div class="v" data-kpi="stale" data-n="<?= $staleN ?>"><?= $staleN ?></div><div class="n">há mais de <?= $staleLimit ?> dias</div></a>
  <div class="stat"><div class="k">Ganhos este mês</div><div class="v" data-kpi="won-value" data-n="<?= $wonValue ?>"><?= eur0($wonValue) ?></div><div class="n">chegaram a Kickoff: <span data-kpi="won-count" data-n="<?= count($won) ?>"><?= count($won) ?></span></div></div>
</div>
<?= views_bar(PIPE_VISTAS, $vista, fn($k) => pipe_url(array_filter(['f' => $fid, 'vista' => $k ?: null])), $vistaCount) ?>
<div class="pboard"<?= $canEdit ? ' data-live' : '' ?>>
  <?php foreach ($stages as $s): $sid = (int)$s['id']; $cards = $byStage[$sid] ?? [];
        $total = array_sum(array_map(fn($d) => (float)$d['value_estimate'], $cards)); ?>
    <section class="col <?= $s['kind'] === 'kickoff' ? 'col-win' : '' ?>" data-stage="<?= $sid ?>" aria-label="<?= e($s['name']) ?>">
      <header>
        <b><span><?= e($s['name']) ?></span><?php if ($s['kind'] !== 'kickoff'): ?><span data-cnt data-n="<?= count($cards) ?>"><?= count($cards) ?></span><?php endif; ?></b>
        <?php if ($s['kind'] === 'kickoff'): ?>
          <span class="muted">entra ao validar a aceitação do cliente</span>
        <?php else: ?>
          <span class="sum" data-sum data-n="<?= $total ?>"><?= eur0($total) ?></span>
        <?php endif; ?>
      </header>
      <div class="cards">
      <?php foreach ($cards as $d): $idle = days_since(deal_last_move($d)); $risk = $idle > $staleLimit;
            $gate = $s['kind'] !== 'kickoff' ? deal_gate($pdo, $d, $s) : [];
            $gOk = count(array_filter($gate, fn($g) => $g['ok'])); ?>
        <article class="dcard<?= $risk ? ' risk' : '' ?>" draggable="<?= $canEdit ? 'true' : 'false' ?>" data-id="<?= (int)$d['id'] ?>" data-value="<?= (float)$d['value_estimate'] ?>">
          <div class="id"><span><?= e($d['code']) ?></span><?php if ($gate): ?><span class="chip<?= $gOk === count($gate) ? ' ok' : '' ?>" data-tip="<?= e($gOk === count($gate) ? 'Requisitos cumpridos: pode avançar' : 'Falta: ' . implode(' · ', array_map(fn($g) => $g['label'], array_filter($gate, fn($g) => !$g['ok'])))) ?>" tabindex="0" aria-label="Requisitos <?= $gOk ?> de <?= count($gate) ?>"><?= $gOk ?>/<?= count($gate) ?></span><?php endif; ?><?php if ($risk): ?><span class="chip risk" data-tip="Sem movimento há <?= $idle ?> dias" aria-label="Sem movimento há <?= $idle ?> dias"><?= $idle ?>d</span><?php endif; ?></div>
          <a class="dname" data-panel="deal:<?= (int)$d['id'] ?>" href="<?= e(pipe_url(['f' => $fid, 'deal' => $d['id']]) . '#negocio') ?>"><?= e($d['name']) ?></a>
          <?php if ($d['contact_name'] && $d['contact_name'] !== $d['name']): ?><div class="muted"><?= e($d['contact_name']) ?></div><?php endif; ?>
          <div class="drow">
            <span class="mono"><?= $d['value_estimate'] !== null ? eur0((float)$d['value_estimate']) : '<span class="muted">sem valor</span>' ?></span>
            <span class="owner"><span class="dot" style="background:<?= e($d['owner_color'] ?: '#ccc') ?>"></span> <?= e($d['owner_name'] ?? 'Por atribuir') ?></span>
          </div>
          <?php if ($canEdit): ?>
          <form method="post" class="dmove"><?= csrf_field() ?>
            <input type="hidden" name="do" value="move"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
            <input type="hidden" name="back" value="<?= e($self) ?>">
            <select name="stage_id" class="mini" aria-label="Mover <?= e($d['code']) ?> para">
              <?php foreach ($stages as $o): ?><option value="<?= (int)$o['id'] ?>" <?= (int)$o['id'] === $sid ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
            </select>
            <noscript><button class="btn sm" type="submit">Mover</button></noscript>
          </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<?php if (!$funnels): ?><p class="empty">Ainda não há funis.</p><?php endif; ?>

<?php elseif ($view === 'perdidos'):
    $rows = $selectDeals('d.phase = ?', ['lost']);
    usort($rows, fn($a, $b) => strcmp((string)$b['lost_at'], (string)$a['lost_at']));
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

<?php // ── o negócio (§19.8): o mesmo painel do lado, em página ──
if ($open):
    require_once __DIR__ . '/inc/pipeline_view.php';
    $openBack = pipe_url(array_filter(['v' => $view === 'funil' ? null : $view, 'f' => $view === 'funil' ? $fid : null, 'deal' => $open['id']])) . '#negocio';
?>
<h2 id="negocio" class="sr-only"><?= e($open['code']) ?> · <?= e($open['name']) ?></h2>
<div class="fl-pagewrap"><?= str_replace('lv lv-panel fl', 'lv lv-page fl', flow_panel_html($pdo, $open, $openBack)) ?></div>
<script src="<?= asset_url('tasks.js') ?>" defer></script>
<?php if (in_array($open['phase'], ['pipeline', 'lost'], true) && $canEdit):
    $curStage = stage_get($pdo, (int)$open['stage_id']);
    $otherFunnels = array_filter($funnels, fn($f) => (int)$f['id'] !== (int)$open['funnel_id']);
    $variable = $curStage && in_array($curStage['kind'], ['commercial', 'production'], true);
?>
<h2>Mudar de funil</h2>
<form method="post" class="bar">
  <?= csrf_field() ?><input type="hidden" name="do" value="funnel"><input type="hidden" name="id" value="<?= (int)$open['id'] ?>">
  <input type="hidden" name="back" value="<?= e($openBack) ?>">
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

<?php render_footer();
