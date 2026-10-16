<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();

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

// A grelha começa sempre à segunda-feira anterior ao dia 1.
$start   = $first->modify('monday this week');
if ($start > $first) $start = $start->modify('-1 week');
$today   = (new DateTimeImmutable('today'))->format('Y-m-d');
$semanas = (int)ceil(((int)$first->format('N') - 1 + (int)$first->format('t')) / 7);
?>

<h1>Calendário</h1>
<div class="sub">
  <a href="?ym=<?= $prev ?>">← anterior</a>
  · <b style="color:var(--black)"><?= month_name($month) ?> <?= $year ?></b>
  · <a href="?ym=<?= $next ?>">seguinte →</a>
  · <a href="?ym=<?= date('Y-m') ?>">hoje</a>
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
    </div>
  <?php $cursor = $cursor->modify('+1 day'); endfor; ?>
</div>

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

<?php render_footer(); ?>
