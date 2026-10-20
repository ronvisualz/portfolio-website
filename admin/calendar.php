<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/sheet.php';
require __DIR__ . '/inc/tasks.php';
$user = require_login();
require_page('tarefas');

// Três vistas no mesmo separador: o mês, as pilhas de tarefas e a equipa.
$view = (string)($_GET['view'] ?? 'mes');
if (!in_array($view, ['mes', 'tarefas', 'equipa'], true)) $view = 'mes';

$members = team_members($pdo);
// ?p=<id> — só esta pessoa (no mês: as tarefas dela; nas tarefas: a pilha dela em grande).
$person = task_member_in($_GET['p'] ?? 0, $members);

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

        $pid = (int)($_POST['project_id'] ?? 0);
        $pdo->prepare(
            'INSERT INTO events (title, event_date, start_time, end_time, project_id, location, notes)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            trim((string)($_POST['title'] ?? '')) ?: 'Sem título',
            $date,
            $ini !== '' ? $ini : null,
            $fim !== '' ? $fim : null,
            $pid > 0 ? $pid : null,
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

    if ($do === 'add_task' || $do === 'update_task') {
        [$v, $err] = task_input($_POST, $members);
        $old = $do === 'update_task' ? task_by_id($pdo, (int)($_POST['id'] ?? 0)) : null;
        if ($do === 'update_task' && !$old) {
            flash('Essa tarefa já não existe.', 'err');
            redirect($board);
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

    if ($do === 'move_task') {
        $id = (int)($_POST['id'] ?? 0);
        if (!task_by_id($pdo, $id)) {
            flash('Essa tarefa já não existe.', 'err');
            redirect($board);
        }
        if ((string)($_POST['to'] ?? '') === 'done') {
            task_done($pdo, $id);
            flash('Tarefa marcada como feita.');
            redirect($board);
        }
        $to = task_member_in($_POST['to'] ?? 0, $members);
        task_move($pdo, $id, $to);
        flash('Tarefa movida para ' . ($to ? $members[$to]['name'] : 'Por atribuir') . '.');
        redirect($board);
    }

    // «✓ Feito» e «Reabrir» — sem JavaScript, formulários normais.
    if ($do === 'done_task' || $do === 'reopen_task') {
        $id = (int)($_POST['id'] ?? 0);
        $t = task_by_id($pdo, $id);
        if (!$t) {
            flash('Essa tarefa já não existe.', 'err');
            redirect($board);
        }
        if ($do === 'done_task') {
            task_done($pdo, $id);
            flash('«' . $t['title'] . '» está feita.');
        } else {
            $to = task_member_in($t['member_id'], $members);
            task_reopen($pdo, $id, $to);
            flash('«' . $t['title'] . '» reaberta' . ($to ? ' para ' . $members[$to]['name'] : '') . '.');
        }
        redirect($board);
    }

    if ($do === 'delete_task') {
        $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Tarefa eliminada.');
        redirect($board);
    }

    // ── equipa ──────────────────────────────────────────────
    if ($do === 'add_member' || $do === 'update_member') {
        $name  = clip(trim((string)($_POST['name'] ?? '')), 120);
        $color = (string)($_POST['color'] ?? '');
        if ($name === '') {
            flash('Indique o nome.', 'err');
            redirect('/admin/calendar.php?view=equipa');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = TEAM_COLORS[count($members) % count(TEAM_COLORS)];

        if ($do === 'add_member') {
            $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) FROM team_members')->fetchColumn() + 1;
            $pdo->prepare('INSERT INTO team_members (name, color, position) VALUES (?,?,?)')
                ->execute([$name, strtoupper($color), $pos]);
            flash($name . ' entrou na equipa.');
        } else {
            $pdo->prepare('UPDATE team_members SET name = ?, color = ? WHERE id = ?')
                ->execute([$name, strtoupper($color), (int)($_POST['id'] ?? 0)]);
            flash('Equipa guardada.');
        }
        redirect('/admin/calendar.php?view=equipa');
    }
}

// ── eventos do mês ──────────────────────────────────────────
$eq = $pdo->prepare(
    'SELECT e.*, p.name AS job_name
       FROM events e
       LEFT JOIN projects p ON p.id = e.project_id
      WHERE e.event_date BETWEEN ? AND ?
      ORDER BY e.event_date, COALESCE(e.start_time, "23:59"), e.id'
);
$eq->execute([$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')]);
$events = $eq->fetchAll();

$byDay = [];
foreach ($events as $ev) {
    $byDay[(int)substr($ev['event_date'], 8, 2)][] = $ev;
}

$jobs = $pdo->query(
    "SELECT id, name, year FROM projects WHERE kind = 'job' ORDER BY year DESC, name"
)->fetchAll();

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

render_header('Calendário', 'calendar');
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
      <?php if ($t['job_name'] !== ''): ?>
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

<h1>Calendário</h1>

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
    'SELECT * FROM tasks WHERE due_date BETWEEN ? AND ?' . ($person ? ' AND member_id = ?' : '')
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
        <a class="ev<?= $ev['project_id'] ? ' job' : '' ?>"
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
          <a class="ev<?= $ev['project_id'] ? ' job' : '' ?>" href="#ev-<?= (int)$ev['id'] ?>">
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
        <?php if ($ev['project_id']): ?>
          <?php /* Os trabalhos vivem agora na folha Google: encontram-se pelo nome. */ ?>
          <a href="/admin/project.php?n=<?= e(rawurlencode((string)$ev['job_name'])) ?>"><?= e($ev['job_name']) ?></a>
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
        <label for="project_id">Trabalho (opcional)</label>
        <select id="project_id" name="project_id">
          <option value="0">— nenhum —</option>
          <?php foreach ($jobs as $j): ?>
            <option value="<?= (int)$j['id'] ?>"><?= e($j['name']) ?> · <?= (int)$j['year'] ?></option>
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
$all = $pdo->query('SELECT * FROM tasks ORDER BY position, id')->fetchAll();
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
[$doneList, $doneCount] = task_done_list($pdo, $doneLimit, $person);
$editing   = isset($_GET['edit']) ? task_by_id($pdo, (int)$_GET['edit']) : null;
$confirmDel = $editing && !empty($_GET['del']);
$jobNames  = task_job_names();

$f = $editing ?: ['id' => 0, 'title' => '', 'description' => '', 'member_id' => $person,
                  'job_name' => '', 'due_date' => '', 'priority' => 'media', 'status' => 'todo'];
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
        <label for="t_job">Trabalho (opcional)</label>
        <input type="text" id="t_job" name="job_name" maxlength="160" list="t_jobs"
               value="<?= e($f['job_name']) ?>" placeholder="— interno —">
        <datalist id="t_jobs">
          <?php foreach ($jobNames as $n): ?><option value="<?= e($n) ?>"><?php endforeach; ?>
        </datalist>
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

<div class="sub">Um só login para todos — atribuir uma tarefa é só escolher a pessoa.</div>

<div class="tablewrap">
<table>
  <thead><tr><th>Cor</th><th>Nome</th><th class="num">Tarefas por fazer</th><th></th></tr></thead>
  <tbody>
  <?php
  $open = [];
  foreach ($pdo->query("SELECT member_id, COUNT(*) AS n FROM tasks WHERE status <> 'done' AND member_id IS NOT NULL GROUP BY member_id")->fetchAll() as $r) {
      $open[(int)$r['member_id']] = (int)$r['n'];
  }
  foreach ($members as $m): $mid = (int)$m['id']; ?>
    <tr>
      <td colspan="2">
        <form method="post" action="/admin/calendar.php" class="bar" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="update_member">
          <input type="hidden" name="id" value="<?= $mid ?>">
          <label class="sr" for="mc-<?= $mid ?>">Cor</label>
          <input type="color" id="mc-<?= $mid ?>" name="color" value="<?= e(member_color($m)) ?>" style="flex:0 0 44px;padding:2px;height:36px">
          <label class="sr" for="mn-<?= $mid ?>">Nome</label>
          <input type="text" id="mn-<?= $mid ?>" name="name" value="<?= e($m['name']) ?>" required maxlength="120">
          <button class="btn ghost sm" type="submit">Guardar</button>
        </form>
      </td>
      <td class="num"><a href="<?= e(cal_url(['view' => 'tarefas', 'p' => $mid])) ?>"><?= $open[$mid] ?? 0 ?></a></td>
      <td></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="panel" style="margin-top:2rem">
  <h2 style="margin-top:0">Novo membro</h2>
  <form method="post" action="/admin/calendar.php" class="bar" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add_member">
    <label class="sr" for="new_color">Cor</label>
    <input type="color" id="new_color" name="color" value="<?= e(TEAM_COLORS[count($members) % count(TEAM_COLORS)]) ?>" style="flex:0 0 44px;padding:2px;height:36px">
    <label class="sr" for="new_name">Nome</label>
    <input type="text" id="new_name" name="name" required maxlength="120" placeholder="Nome e apelido">
    <button class="btn sm" type="submit">Adicionar</button>
  </form>
</div>

<?php endif; ?>

<?php render_footer(); ?>
