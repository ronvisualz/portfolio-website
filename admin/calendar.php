<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/tasks.php';
require_once __DIR__ . '/inc/undo.php';
$user = require_login();
require_page('tarefas');

// Três vistas no mesmo separador: o mês, as pilhas de tarefas e a equipa.
$view = (string)($_GET['view'] ?? 'mes');
if (!in_array($view, ['mes', 'tarefas', 'equipa'], true)) $view = 'mes';

$allMembers = team_members($pdo);
// Só as pilhas dentro do meu âmbito da aba Tarefas (null = todas).
$visible = task_visible_members($pdo, $allMembers);
$members = $visible === null ? $allMembers : array_intersect_key($allMembers, array_flip($visible));
// ?p=<id> — só esta pessoa (no mês: as tarefas dela; nas tarefas: a pilha dela em grande).
$person = task_member_in($_GET['p'] ?? 0, $members);
/** Restringe uma consulta às tarefas que posso ver. */
$visSql = $visible === null ? '' : ' AND (member_id IS NULL' . ($visible ? ' OR member_id IN (' . implode(',', $visible) . ')' : '') . ')';

// Mês a mostrar. Formato AAAA-MM no endereço; o mês corrente por omissão.
$ym = (string)($_GET['ym'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
[$year, $month] = array_map('intval', explode('-', $ym));
if ($month < 1 || $month > 12) { $year = (int)date('Y'); $month = (int)date('n'); }

$first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
$prev  = $first->modify('-1 month')->format('Y-m');
$next  = $first->modify('+1 month')->format('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do   = $_POST['do'] ?? '';
    $back = '/admin/calendar.php?ym=' . urlencode((string)($_POST['ym'] ?? $ym));

    if ($do === 'add_event') {
        $date = trim((string)($_POST['event_date'] ?? ''));
        $ini  = trim((string)($_POST['start_time'] ?? ''));
        $fim  = trim((string)($_POST['end_time'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            flash('Indique uma data válida.', 'err');
            redirect($back);
        }
        // Fim antes do início quase de certeza é um engano — vale mais
        // avisar do que guardar um intervalo impossível.
        if ($ini !== '' && $fim !== '' && $fim < $ini) {
            flash('A hora de fim é anterior à de início.', 'err');
            redirect($back);
        }

        $pdo->prepare(
            'INSERT INTO events (title, event_date, start_time, end_time, deal_id, location, notes)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            trim((string)($_POST['title'] ?? '')) ?: 'Sem título',
            $date,
            $ini !== '' ? $ini : null,
            $fim !== '' ? $fim : null,
            task_deal_in($_POST['deal_id'] ?? 0, $pdo),
            trim((string)($_POST['location'] ?? '')),
            trim((string)($_POST['notes'] ?? '')),
        ]);
        flash('Evento criado.');
        redirect('/admin/calendar.php?ym=' . substr($date, 0, 7));
    }

    if ($do === 'delete_event') {
        $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Evento eliminado.');
        redirect($back);
    }

    // ── tarefas ─────────────────────────────────────────────
    $pBack = task_member_in($_POST['p'] ?? 0, $members);
    $board = '/admin/calendar.php?view=tarefas' . ($pBack ? '&p=' . $pBack : '');

    // Uma tarefa fora do meu âmbito não se mexe, nem pelo id.
    $guard = function (?array $t) use ($visible, $board) {
        if (!$t || !task_member_visible($t['member_id'] !== null ? (int)$t['member_id'] : null, $visible)) {
            flash('Essa tarefa já não existe.', 'err');
            redirect($board);
        }
        return $t;
    };

    if ($do === 'add_task' || $do === 'update_task') {
        [$v, $err] = task_input($_POST, $members, $pdo);
        $old = $do === 'update_task' ? $guard(task_by_id($pdo, (int)($_POST['id'] ?? 0))) : null;
        $oldMember = $old && $old['member_id'] !== null ? (int)$old['member_id'] : null;
        if (!$err && $v['member_id'] !== $oldMember && !task_can_assign($pdo, $v['member_id'], $allMembers, $visible)) {
            $err = 'Não pode atribuir tarefas a essa pessoa.';
        }
        if ($err) {
            flash($err, 'err');
            redirect($old ? $board . '&edit=' . (int)$old['id'] : $board);
        }
        if ($old) {
            task_update($pdo, $old, $v);
            flash('Tarefa guardada.');
        } else {
            task_create($pdo, $v);
            flash('Tarefa criada.');
        }
        redirect($board);
    }

    $taskSnap = fn(array $t) => ['id' => (int)$t['id'], 'status' => $t['status'], 'done_at' => $t['done_at'], 'member_id' => $t['member_id'], 'position' => $t['position']];

    if ($do === 'move_task') {
        $id = (int)($_POST['id'] ?? 0);
        $mt = $guard(task_by_id($pdo, $id));
        if ((string)($_POST['to'] ?? '') === 'done') {
            $spawned = task_done($pdo, $id);
            flash('Tarefa marcada como feita.', 'ok', undo_push('task', $taskSnap($mt) + ['spawned' => $spawned]));
            redirect($board);
        }
        $to = task_member_in($_POST['to'] ?? 0, $members);
        if (!task_can_assign($pdo, $to, $allMembers, $visible)) {
            flash('Não pode atribuir tarefas a essa pessoa.', 'err');
            redirect($board);
        }
        task_move($pdo, $id, $to);
        flash('Tarefa movida para ' . ($to ? $members[$to]['name'] : 'Por atribuir') . '.', 'ok', undo_push('task', $taskSnap($mt)));
        redirect($board);
    }

    // «✓ Feito» e «Reabrir» — sem JavaScript, formulários normais.
    if ($do === 'done_task' || $do === 'reopen_task') {
        $id = (int)($_POST['id'] ?? 0);
        $t = $guard(task_by_id($pdo, $id));
        if ($do === 'done_task') {
            $spawned = task_done($pdo, $id);
            flash('«' . $t['title'] . '» está feita.', 'ok', undo_push('task', $taskSnap($t) + ['spawned' => $spawned]));
        } else {
            $to = task_member_in($t['member_id'], $members);
            task_reopen($pdo, $id, $to);
            flash('«' . $t['title'] . '» reaberta' . ($to ? ' para ' . $members[$to]['name'] : '') . '.');
        }
        redirect($board);
    }

    if ($do === 'delete_task') {
        $guard(task_by_id($pdo, (int)($_POST['id'] ?? 0)));
        $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Tarefa eliminada.');
        redirect($board);
    }

}

// ── eventos do mês ──────────────────────────────────────────
$eq = $pdo->prepare(
    'SELECT e.*, CASE WHEN d.id IS NULL THEN NULL ELSE ' . (db_is_sqlite($pdo) ? "d.code || ' · ' || d.name" : "CONCAT(d.code, ' · ', d.name)") . ' END AS job_name
       FROM events e
       LEFT JOIN deals d ON d.id = e.deal_id
      WHERE e.event_date BETWEEN ? AND ?
      ORDER BY e.event_date, COALESCE(e.start_time, "23:59"), e.id'
);
$eq->execute([$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')]);
$events = $eq->fetchAll();

$byDay = [];
foreach ($events as $ev) {
    $byDay[(int)substr($ev['event_date'], 8, 2)][] = $ev;
}

$dealOptions = task_deal_options($pdo);

// Evento à espera de confirmação para ser eliminado.
$pendingEvent = (int)($_GET['del'] ?? 0);

/** «14:00 – 16:30», ou «Todo o dia» quando não há horas. */
function event_hours(array $ev): string {
    $i = $ev['start_time'] ? substr($ev['start_time'], 0, 5) : '';
    $f = $ev['end_time']   ? substr($ev['end_time'], 0, 5)   : '';
    if ($i === '' && $f === '') return 'Todo o dia';
    if ($f === '') return $i;
    if ($i === '') return 'até ' . $f;
    return $i . ' – ' . $f;
}

const DIAS_PT = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

render_header('Tarefas', 'calendar');
render_flashes();

/** Endereço desta página com os parâmetros dados (os vazios saem). */
function cal_url(array $q): string {
    $q = array_filter($q, fn($v) => $v !== null && $v !== '' && $v !== 0);
    return '/admin/calendar.php' . ($q ? '?' . http_build_query($q) : '');
}

/** Um cartão de tarefa: arrasta-se pela pega, ou muda-se de pilha pelo «Mover para…». */
function task_card(array $t, array $members, ?int $person): void {
    $tid = (int)$t['id'];
    $mid = $t['member_id'] !== null ? (int)$t['member_id'] : 0;
    $who = $mid && isset($members[$mid]) ? member_color($members[$mid]) : 'var(--faint)';
    $done = $t['status'] === 'done';
    $late = $t['due_date'] && !$done && $t['due_date'] < date('Y-m-d');
    $whoName = $mid && isset($members[$mid]) ? first_name($members[$mid]['name']) : 'Sem dono';
    $hid = function (string $do) use ($tid, $person) {
        return csrf_field() . '<input type="hidden" name="do" value="' . $do . '">'
             . '<input type="hidden" name="id" value="' . $tid . '">'
             . '<input type="hidden" name="p" value="' . (int)$person . '">';
    };
    ?>
    <article class="card st-<?= e($t['status']) ?> prio-<?= e($t['priority']) ?>" data-id="<?= $tid ?>"
             data-status="<?= e($t['status']) ?>" data-mid="<?= $mid ?>" style="--who:<?= e($who) ?>">
      <div class="card-top">
        <span class="grip" aria-hidden="true" title="Arrastar">⠿</span>
        <a class="card-t" href="<?= e(cal_url(['view' => 'tarefas', 'p' => $person, 'edit' => $tid])) ?>"><?= e($t['title']) ?></a>
      </div>
      <div class="card-meta">
        <span class="who-chip" title="Quem fez"><?= e($whoName) ?></span>
        <span class="st"><?= e(TASK_STATUS[$t['status']] ?? $t['status']) ?></span>
        <span class="pr"><?= e(TASK_PRIO[$t['priority']] ?? $t['priority']) ?></span>
        <?php if ($t['due_date']): ?>
          <span class="due<?= $late ? ' late' : '' ?>" title="Prazo"><?= e(task_due_label($t['due_date'])) ?></span>
        <?php endif; ?>
      </div>
      <?php if (!empty($t['deal_code'])): ?>
        <div class="card-job"><a href="/admin/negocio.php?id=<?= (int)$t['deal_id'] ?>"><?= e($t['deal_code'] . ' · ' . $t['deal_name']) ?></a></div>
      <?php elseif (!empty($t['deal_id']) && in_array($t['deal_phase'] ?? '', ['lead', 'archived'], true)): ?>
        <div class="card-job"><a href="/admin/lead.php?id=<?= (int)$t['deal_id'] ?>">Lead · <?= e($t['deal_name']) ?></a></div>
      <?php elseif (!empty($t['contact_name'])): ?>
        <div class="card-job"><a href="/admin/contact.php?id=<?= (int)$t['contact_id'] ?>"><?= ($t['kind'] ?? '') === 'relation' ? 'Relação' : 'Contacto' ?> · <?= e($t['contact_name']) ?></a></div>
      <?php elseif ($t['job_name'] !== ''): ?>
        <div class="card-job"><?= e($t['job_name']) ?></div>
      <?php endif; ?>
      <form method="post" action="/admin/calendar.php" class="card-move">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="move_task">
        <input type="hidden" name="id" value="<?= $tid ?>">
        <input type="hidden" name="p" value="<?= (int)$person ?>">
        <label class="sr" for="mv-<?= $tid ?>">Mover para…</label>
        <select id="mv-<?= $tid ?>" name="to">
          <option value="0"<?= !$done && $mid === 0 ? ' selected' : '' ?>>Por atribuir</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= (int)$m['id'] ?>"<?= !$done && $mid === (int)$m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option>
          <?php endforeach; ?>
          <option value="done"<?= $done ? ' selected' : '' ?>>Feito</option>
        </select>
        <button class="btn ghost sm" type="submit">Mover</button>
      </form>
      <div class="card-acts">
        <form method="post" action="/admin/calendar.php" class="card-done">
          <?= $hid('done_task') ?>
          <button class="btn ghost sm" type="submit" title="Marcar como feita">✓ Feito</button>
        </form>
        <form method="post" action="/admin/calendar.php" class="card-reopen">
          <?= $hid('reopen_task') ?>
          <button class="btn ghost sm" type="submit" title="Voltar a pôr na pilha">Reabrir</button>
        </form>
      </div>
    </article>
    <?php
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
?>

<h1>Tarefas</h1>
<?php
// Resumo (§11.5): as tarefas que posso ver.
$kq = fn(string $where, array $a = []) => (function () use ($pdo, $where, $a, $visSql) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE $where$visSql"); $st->execute($a); return (int)$st->fetchColumn(); })();
$dueDays = max(0, (int)setting($pdo, 'task_due_days', 2));
$kOpen = $kq("status <> 'done'");
$kLate = $kq("status <> 'done' AND due_date < ?", [$today]);
$kSoon = $kq("status <> 'done' AND due_date BETWEEN ? AND ?", [$today, (new DateTimeImmutable($today))->modify("+$dueDays days")->format('Y-m-d')]);
$kDone = $kq("status = 'done' AND done_at >= ?", [(new DateTimeImmutable($today))->modify('monday this week')->format('Y-m-d') . ' 00:00:00']);
?>
<div class="stats">
  <a class="stat hero" href="<?= e(cal_url(['view' => 'tarefas'])) ?>"><div class="k">Por fazer</div><div class="v"><?= $kOpen ?></div><div class="n">tarefas abertas</div></a>
  <div class="stat<?= $kLate ? ' risk' : '' ?>"><div class="k">Atrasadas</div><div class="v<?= $kLate ? ' neg' : '' ?>"><?= $kLate ?></div><div class="n">prazo já passou</div></div>
  <div class="stat"><div class="k">A vencer</div><div class="v"><?= $kSoon ?></div><div class="n">até <?= $dueDays ?> dias</div></div>
  <div class="stat"><div class="k">Feitas esta semana</div><div class="v"><?= $kDone ?></div><div class="n">desde segunda-feira</div></div>
</div>

<div class="tabs">
  <a href="<?= e(cal_url(['ym' => $ym, 'p' => $person])) ?>" class="<?= $view === 'mes' ? 'on' : '' ?>">Mês</a>
  <a href="<?= e(cal_url(['view' => 'tarefas', 'p' => $person])) ?>" class="<?= $view === 'tarefas' ? 'on' : '' ?>">Tarefas</a>
  <a href="<?= e(cal_url(['view' => 'equipa'])) ?>" class="<?= $view === 'equipa' ? 'on' : '' ?>">Equipa</a>
</div>

<?php if ($view !== 'equipa'): ?>
<div class="people" aria-label="Filtrar por pessoa">
  <a href="<?= e(cal_url(['view' => $view === 'mes' ? null : $view, 'ym' => $view === 'mes' ? $ym : null])) ?>"
     class="<?= $person === null ? 'on' : '' ?>">Todos</a>
  <?php foreach ($members as $m): $mid = (int)$m['id']; ?>
    <a href="<?= e(cal_url(['view' => $view === 'mes' ? null : $view, 'ym' => $view === 'mes' ? $ym : null, 'p' => $mid])) ?>"
       class="<?= $person === $mid ? 'on' : '' ?>" style="--who:<?= e(member_color($m)) ?>">
      <i class="sw"></i><?= e(first_name($m['name'])) ?>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($view === 'mes'):
// ── tarefas com prazo neste mês ─────────────────────────────
$tq = $pdo->prepare(
    'SELECT * FROM tasks WHERE due_date BETWEEN ? AND ?' . ($person ? ' AND member_id = ?' : '') . $visSql
  . ' ORDER BY due_date, position, id'
);
$tq->execute(array_merge(
    [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')],
    $person ? [$person] : []
));
$monthTasks = $tq->fetchAll();
$tasksByDay = [];
foreach ($monthTasks as $t) $tasksByDay[(int)substr((string)$t['due_date'], 8, 2)][] = $t;

$taskChip = function (array $t, bool $long = false) use ($members, $person): string {
    $mid  = $t['member_id'] !== null ? (int)$t['member_id'] : 0;
    $name = $mid && isset($members[$mid]) ? first_name($members[$mid]['name']) : 'Por atribuir';
    $who  = $mid && isset($members[$mid]) ? member_color($members[$mid]) : 'var(--paper)';
    return '<a class="ev task' . ($t['status'] === 'done' ? ' done' : '') . '" style="--who:' . e($who) . '"'
         . ' href="' . e(cal_url(['view' => 'tarefas', 'p' => $person, 'edit' => (int)$t['id']])) . '"'
         . ' title="' . e('Tarefa · ' . $t['title'] . ' · ' . $name . ' · ' . (TASK_STATUS[$t['status']] ?? '')) . '">'
         . '<b>' . e($name) . '</b> ' . e($long ? $t['title'] : shorten($t['title'], 22)) . '</a>';
};

// A grelha começa sempre à segunda-feira anterior ao dia 1.
$start   = $first->modify('monday this week');
if ($start > $first) $start = $start->modify('-1 week');
$semanas = (int)ceil(((int)$first->format('N') - 1 + (int)$first->format('t')) / 7);
$pq      = $person ? '&amp;p=' . $person : '';
?>

<div class="sub">
  <a href="?ym=<?= $prev ?><?= $pq ?>">← anterior</a>
  · <b style="color:var(--black)"><?= month_name($month) ?> <?= $year ?></b>
  · <a href="?ym=<?= $next ?><?= $pq ?>">seguinte →</a>
  · <a href="?ym=<?= date('Y-m') ?><?= $pq ?>">hoje</a>
</div>

<div class="cal">
  <?php foreach (DIAS_PT as $d): ?>
    <div class="cal-h"><?= $d ?></div>
  <?php endforeach; ?>

  <?php
  $cursor = $start;
  for ($i = 0; $i < $semanas * 7; $i++):
      $iso  = $cursor->format('Y-m-d');
      $dia  = (int)$cursor->format('j');
      $fora = (int)$cursor->format('n') !== $month;
      $lista = $fora ? [] : ($byDay[$dia] ?? []);
      $tlist = $fora ? [] : ($tasksByDay[$dia] ?? []);
  ?>
    <div class="cal-d<?= $fora ? ' fora' : '' ?><?= $iso === $today ? ' hoje' : '' ?>">
      <div class="n"><?= $dia ?></div>
      <?php foreach ($lista as $ev): ?>
        <a class="ev<?= $ev['deal_id'] ? ' job' : '' ?>"
           href="#ev-<?= (int)$ev['id'] ?>"
           title="<?= e($ev['title'] . ' · ' . event_hours($ev) . ($ev['job_name'] ? ' · ' . $ev['job_name'] : '')) ?>">
          <b><?= e(substr(event_hours($ev), 0, 5)) ?></b> <?= e(shorten($ev['title'], 22)) ?>
        </a>
      <?php endforeach; ?>
      <?php foreach ($tlist as $t) echo $taskChip($t); ?>
    </div>
  <?php $cursor = $cursor->modify('+1 day'); endfor; ?>
</div>

<?php /* No telemóvel a grelha fica apertada: a mesma coisa em lista, dia a dia. */ ?>
<div class="agenda">
  <?php
  $dias = array_unique(array_merge(array_keys($byDay), array_keys($tasksByDay)));
  sort($dias);
  if (!$dias): ?>
    <div class="empty">Nada marcado em <?= month_name($month) ?>.</div>
  <?php endif;
  foreach ($dias as $dia):
      $iso = sprintf('%04d-%02d-%02d', $year, $month, $dia);
      $dow = DIAS_PT[(int)(new DateTimeImmutable($iso))->format('N') - 1];
  ?>
    <div class="ag-day<?= $iso === $today ? ' hoje' : '' ?>">
      <div class="ag-n"><b><?= $dia ?></b> <?= $dow ?></div>
      <div class="ag-items">
        <?php foreach ($byDay[$dia] ?? [] as $ev): ?>
          <a class="ev<?= $ev['deal_id'] ? ' job' : '' ?>" href="#ev-<?= (int)$ev['id'] ?>">
            <b><?= e(event_hours($ev)) ?></b> <?= e($ev['title']) ?>
          </a>
        <?php endforeach; ?>
        <?php foreach ($tasksByDay[$dia] ?? [] as $t) echo $taskChip($t, true); ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="legend">
  <span><i class="sw" style="--who:var(--paper)"></i>Evento</span>
  <span><i class="sw" style="--who:var(--sky)"></i>Evento de um trabalho</span>
  <?php foreach ($members as $m): ?>
    <span style="--who:<?= e(member_color($m)) ?>"><i class="sw"></i>Tarefa · <?= e(first_name($m['name'])) ?></span>
  <?php endforeach; ?>
</div>

<?php // ↓ o que vem a seguir é a lista de eventos de sempre ?>
<h2><?= count($events) ?> <?= count($events) === 1 ? 'evento' : 'eventos' ?> em <?= month_name($month) ?></h2>
<div class="tablewrap">
<table>
  <thead>
    <tr>
      <th>Dia</th>
      <th>Horas</th>
      <th>Evento</th>
      <th>Trabalho</th>
      <th>Onde</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$events): ?>
    <tr><td colspan="6" class="empty">Sem eventos neste mês. Crie o primeiro abaixo.</td></tr>
  <?php endif; ?>
  <?php foreach ($events as $ev): $eid = (int)$ev['id']; ?>
    <tr id="ev-<?= $eid ?>" class="<?= $pendingEvent === $eid ? 'pending' : '' ?>">
      <td class="nowrap b"><?= (int)substr($ev['event_date'], 8, 2) ?> <?= month_name($month) ?></td>
      <td class="nowrap"><?= e(event_hours($ev)) ?></td>
      <td>
        <?= e($ev['title']) ?>
        <?php if ($ev['notes']): ?>
          <div class="muted" style="font-size:0.62rem"><?= e(shorten($ev['notes'], 70)) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($ev['deal_id']): ?>
          <a href="/admin/negocio.php?id=<?= (int)$ev['deal_id'] ?>"><?= e((string)$ev['job_name']) ?></a>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
      <td class="muted" style="font-size:0.72rem"><?= e($ev['location']) ?></td>
      <td class="num nowrap">
        <?php if ($pendingEvent === $eid): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete_event">
            <input type="hidden" name="id" value="<?= $eid ?>">
            <input type="hidden" name="ym" value="<?= e($ym) ?>">
            <button class="btn danger sm" type="submit">Sim, eliminar</button>
          </form>
          <a class="lnk" href="?ym=<?= e($ym) ?>">Cancelar</a>
        <?php else: ?>
          <a class="lnk del" href="?ym=<?= e($ym) ?>&amp;del=<?= $eid ?>#ev-<?= $eid ?>">Eliminar</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<details class="panel" style="margin-top:2rem" <?= $pendingEvent ? '' : 'open' ?>>
  <summary style="cursor:pointer;font-size:0.62rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--gray)">
    Novo evento
  </summary>
  <form method="post" style="margin-top:1.2rem">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add_event">
    <input type="hidden" name="ym" value="<?= e($ym) ?>">
    <div class="grid">
      <div class="field">
        <label for="title">O que é</label>
        <input type="text" id="title" name="title" required placeholder="Reunião com o cliente">
      </div>
      <div class="field">
        <label for="event_date">Dia</label>
        <input type="date" id="event_date" name="event_date" required
               value="<?= e($first->format('Y-m-') . str_pad((string)min((int)date('j'), (int)$first->format('t')), 2, '0', STR_PAD_LEFT)) ?>">
      </div>
      <div class="field">
        <label for="start_time">Começa</label>
        <input type="time" id="start_time" name="start_time">
      </div>
      <div class="field">
        <label for="end_time">Acaba</label>
        <input type="time" id="end_time" name="end_time">
      </div>
      <div class="field">
        <label for="deal_id">Negócio (opcional)</label>
        <select id="deal_id" name="deal_id">
          <option value="0">— nenhum —</option>
          <?php foreach ($dealOptions as $did => $label): ?>
            <option value="<?= (int)$did ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="location">Onde</label>
        <input type="text" id="location" name="location" placeholder="Estúdio / Zoom">
      </div>
      <div class="field wide">
        <label for="notes">Notas</label>
        <input type="text" id="notes" name="notes">
      </div>
    </div>
    <div class="actions">
      <button class="btn" type="submit">Criar evento</button>
      <span class="muted" style="font-size:0.68rem">
        Sem horas, o evento conta como sendo todo o dia.
      </span>
    </div>
  </form>
</details>

<?php elseif ($view === 'tarefas'):
require_once __DIR__ . '/inc/contacts.php';
relation_tick($pdo);   // os follow-ups de relação que chegaram à data (§13.5)
$all = $pdo->query('SELECT t.*, d.code AS deal_code, d.name AS deal_name, d.phase AS deal_phase, c.name AS contact_name FROM tasks t
                      LEFT JOIN deals d ON d.id = t.deal_id LEFT JOIN contacts c ON c.id = t.contact_id
                     WHERE 1=1' . $visSql . ' ORDER BY t.position, t.id')->fetchAll();
$stacks = [0 => []];
foreach ($members as $mid => $m) $stacks[$mid] = [];
foreach ($all as $t) {
    if ($t['status'] === 'done') continue; // essas vão para a coluna «Feito»
    $k = $t['member_id'] !== null && isset($members[(int)$t['member_id']]) ? (int)$t['member_id'] : 0;
    $stacks[$k][] = $t;
}
// Pessoa escolhida: só a pilha dela, em grande, com «Por atribuir» ao lado para ir buscar trabalho.
if ($person) $stacks = [0 => $stacks[0], $person => $stacks[$person]];

// «Feito»: as mais recentes primeiro, TASK_DONE_PAGE de cada vez (?feitas=60 mostra mais).
$doneLimit = max(TASK_DONE_PAGE, min(5000, (int)($_GET['feitas'] ?? 0)));
[$doneList, $doneCount] = task_done_list($pdo, $doneLimit, $person, $visible);
$editing   = isset($_GET['edit']) ? task_by_id($pdo, (int)$_GET['edit']) : null;
if ($editing && !task_member_visible($editing['member_id'] !== null ? (int)$editing['member_id'] : null, $visible)) $editing = null;
$confirmDel = $editing && !empty($_GET['del']);
$dealOptions = task_deal_options($pdo, $editing ? (int)$editing['deal_id'] : null);

$f = $editing ?: ['id' => 0, 'title' => '', 'description' => '', 'member_id' => $person,
                  'job_name' => '', 'deal_id' => null, 'due_date' => '', 'priority' => 'media', 'status' => 'todo'];
$fMember = $f['member_id'] !== null ? (int)$f['member_id'] : 0;
?>

<div class="sub">
  <?= count($all) ?> <?= count($all) === 1 ? 'tarefa' : 'tarefas' ?>
  · arraste um cartão pela pega ⠿ para outra pilha ou para «Feito», ou use «Mover para…»
</div>

<?php if ($editing): ?>
<div class="panel" id="editar" style="margin-bottom:1.6rem">
  <h2 style="margin-top:0">Editar tarefa</h2>
<?php else: ?>
<details class="panel" style="margin-bottom:1.6rem">
  <summary style="cursor:pointer;font-size:0.62rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--gray)">
    Nova tarefa
  </summary>
<?php endif; ?>
  <form method="post" action="/admin/calendar.php" style="margin-top:1.2rem">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="<?= $editing ? 'update_task' : 'add_task' ?>">
    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <input type="hidden" name="p" value="<?= (int)$person ?>">
    <div class="grid">
      <div class="field wide">
        <label for="t_title">Título</label>
        <input type="text" id="t_title" name="title" required maxlength="200" value="<?= e($f['title']) ?>" placeholder="Montar o teaser">
      </div>
      <div class="field">
        <label for="t_member">Responsável</label>
        <select id="t_member" name="member_id">
          <option value="0">Por atribuir</option>
          <?php foreach ($members as $m): ?>
            <option value="<?= (int)$m['id'] ?>"<?= $fMember === (int)$m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="t_deal">Negócio (opcional)</label>
        <select id="t_deal" name="deal_id">
          <option value="0">— interno —</option>
          <?php foreach ($dealOptions as $did => $label): ?>
            <option value="<?= (int)$did ?>"<?= (int)$f['deal_id'] === (int)$did ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($f['job_name'] !== '' && !$f['deal_id']): ?>
          <input type="hidden" name="job_name" value="<?= e($f['job_name']) ?>">
          <div class="muted" style="font-size:0.68rem">Antes: «<?= e($f['job_name']) ?>»</div>
        <?php endif; ?>
      </div>
      <div class="field">
        <label for="t_due">Prazo</label>
        <input type="date" id="t_due" name="due_date" value="<?= e((string)$f['due_date']) ?>">
      </div>
      <div class="field">
        <label for="t_prio">Prioridade</label>
        <select id="t_prio" name="priority">
          <?php foreach (TASK_PRIO as $k => $l): ?>
            <option value="<?= $k ?>"<?= $f['priority'] === $k ? ' selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="t_status">Estado</label>
        <select id="t_status" name="status">
          <?php foreach (TASK_STATUS as $k => $l): ?>
            <option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field wide">
        <label for="t_desc">Descrição (opcional)</label>
        <textarea id="t_desc" name="description" rows="3"><?= e((string)$f['description']) ?></textarea>
      </div>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $editing ? 'Guardar tarefa' : 'Criar tarefa' ?></button>
      <?php if ($editing): ?>
        <a class="lnk" href="<?= e(cal_url(['view' => 'tarefas', 'p' => $person])) ?>">Cancelar</a>
        <span class="spacer"></span>
        <?php if (!$confirmDel): ?>
          <a class="lnk del" href="<?= e(cal_url(['view' => 'tarefas', 'p' => $person, 'edit' => (int)$f['id'], 'del' => 1])) ?>#editar">Eliminar</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </form>
  <?php if ($confirmDel): ?>
    <form method="post" action="/admin/calendar.php" class="actions">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="delete_task">
      <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <input type="hidden" name="p" value="<?= (int)$person ?>">
      <span class="muted" style="font-size:0.72rem">Eliminar «<?= e($f['title']) ?>»?</span>
      <button class="btn danger sm" type="submit">Sim, eliminar</button>
      <a class="lnk" href="<?= e(cal_url(['view' => 'tarefas', 'p' => $person, 'edit' => (int)$f['id']])) ?>#editar">Não</a>
    </form>
  <?php endif; ?>
<?php if ($editing): ?>
</div>
<?php else: ?>
</details>
<?php endif; ?>

<div id="board" class="board<?= $person ? ' solo' : '' ?>"
     data-api="/admin/tasks_api.php" data-csrf="<?= e(csrf_token()) ?>">
  <?php foreach ($stacks as $mid => $list):
      $m = $mid ? $members[$mid] : null;
      $open = count($list);
  ?>
    <section class="stack<?= $mid ? '' : ' none' ?><?= $person && $mid === $person ? ' big' : '' ?>"
             style="--who:<?= e($m ? member_color($m) : 'var(--paper)') ?>">
      <header>
        <?php if ($m): ?>
          <a href="<?= e(cal_url(['view' => 'tarefas', 'p' => $mid])) ?>"><?= e($m['name']) ?></a>
        <?php else: ?>
          <span>Por atribuir</span>
        <?php endif; ?>
        <span class="count" title="Por fazer"><?= $open ?></span>
      </header>
      <div class="stack-list" data-member="<?= (int)$mid ?>">
        <?php foreach ($list as $t) task_card($t, $members, $person); ?>
      </div>
    </section>
  <?php endforeach; ?>
  <section class="stack feito" data-total="<?= $doneCount ?>">
    <header>
      <span>feito</span>
      <span class="count" title="Feitas"><?= $doneCount ?></span>
    </header>
    <div class="stack-list" data-member="done">
      <?php foreach ($doneList as $t) task_card($t, $members, $person); ?>
    </div>
    <?php if ($doneCount > count($doneList)): ?>
      <a class="lnk more" href="<?= e(cal_url(['view' => 'tarefas', 'p' => $person, 'feitas' => $doneLimit + TASK_DONE_PAGE])) ?>#board">
        Ver mais (<?= $doneCount - count($doneList) ?> mais antigas)
      </a>
    <?php endif; ?>
  </section>
</div>

<div class="toast" id="toast" role="alert" aria-live="assertive" hidden></div>
<script src="/admin/assets/tasks.js?v=<?= (int)@filemtime(__DIR__ . '/assets/tasks.js') ?>" defer></script>

<?php else: /* equipa */ ?>

<div class="sub">As pessoas, os nomes e as cores gerem-se na aba <a class="lnk" href="/admin/equipa.php">Equipa</a>.</div>

<div class="tablewrap">
<table>
  <thead><tr><th>Pessoa</th><th class="num">Tarefas por fazer</th></tr></thead>
  <tbody>
  <?php foreach ($members as $m): $mid = (int)$m['id']; ?>
    <tr>
      <td><i class="sw" style="--who:<?= e(member_color($m)) ?>"></i> <?= e($m['name']) ?>
        <?= $m['user_id'] === null || (int)$m['user_active'] !== 1 ? ' <span class="chip">sem conta ativa</span>' : '' ?></td>
      <td class="num"><a href="<?= e(cal_url(['view' => 'tarefas', 'p' => $mid])) ?>"><?= (int)$m['open_tasks'] ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php endif; ?>

<?php render_footer(); ?>
