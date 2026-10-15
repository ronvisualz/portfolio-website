<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();

const STAGES = [
    'novo'        => 'Novo',
    'contactado'  => 'Contactado',
    'em_conversa' => 'Em conversa',
    'proposta'    => 'Proposta enviada',
    'ganho'       => 'Ganho',
    'perdido'     => 'Perdido',
];
const CATS = ['produtora' => 'Produtora', 'realizador' => 'Realizador',
              'empresa' => 'Empresa', 'outro' => 'Outro'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'create') {
        $cat = $_POST['category'] ?? 'produtora';
        if (!isset(CATS[$cat])) $cat = 'outro';
        $st = $pdo->prepare(
            'INSERT INTO leads (name, category, contact, status, notes, last_touch)
             VALUES (?,?,?,?,?,?)'
        );
        $st->execute([
            trim((string)($_POST['name'] ?? '')) ?: 'Sem nome',
            $cat,
            trim((string)($_POST['contact'] ?? '')),
            isset(STAGES[$_POST['status'] ?? '']) ? $_POST['status'] : 'novo',
            trim((string)($_POST['notes'] ?? '')),
            ($_POST['last_touch'] ?? '') ?: null,
        ]);
        flash('Lead adicionada.');
        redirect('/admin/leads.php');
    }

    if ($do === 'move') {
        $to = $_POST['status'] ?? '';
        if (isset(STAGES[$to])) {
            $pdo->prepare('UPDATE leads SET status = ?, last_touch = CURDATE() WHERE id = ?')
                ->execute([$to, (int)($_POST['id'] ?? 0)]);
        }
        redirect('/admin/leads.php' . (isset($_POST['back']) ? '?status=' . urlencode((string)$_POST['back']) : ''));
    }

    if ($do === 'delete') {
        $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Lead eliminada.');
        redirect('/admin/leads.php');
    }
}

$fStatus = $_GET['status'] ?? '';
$q       = trim((string)($_GET['q'] ?? ''));

$sql = 'SELECT * FROM leads WHERE 1=1';
$par = [];
if (isset(STAGES[$fStatus])) { $sql .= ' AND status = ?'; $par[] = $fStatus; }
if ($q !== '') { $sql .= ' AND (name LIKE ? OR contact LIKE ? OR notes LIKE ?)';
                 $like = '%' . $q . '%'; array_push($par, $like, $like, $like); }
$sql .= " ORDER BY FIELD(status,'proposta','em_conversa','contactado','novo','ganho','perdido'),
          COALESCE(last_touch,'1970-01-01') DESC, name";
$st = $pdo->prepare($sql);
$st->execute($par);
$rows = $st->fetchAll();

$counts = [];
foreach ($pdo->query('SELECT status, COUNT(*) c FROM leads GROUP BY status') as $r) {
    $counts[$r['status']] = (int)$r['c'];
}

// Lead à espera de confirmação para ser eliminada (dois passos, sem diálogos).
$pendingLead = (int)($_GET['del'] ?? 0);

render_header('Leads', 'leads');
render_flashes();
?>

<h1>Leads</h1>
<div class="sub"><?= array_sum($counts) ?> contactos no total</div>

<div class="stats" style="margin-bottom:1.6rem">
  <?php foreach (['novo','contactado','em_conversa','proposta'] as $k): ?>
    <div class="stat">
      <div class="k"><?= STAGES[$k] ?></div>
      <div class="v"><?= $counts[$k] ?? 0 ?></div>
    </div>
  <?php endforeach; ?>
</div>

<form class="bar" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Procurar nome, contacto, nota">
  <select name="status" onchange="this.form.submit()">
    <option value="">Todas as fases</option>
    <?php foreach (STAGES as $k => $label): ?>
      <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>>
        <?= $label ?> (<?= $counts[$k] ?? 0 ?>)
      </option>
    <?php endforeach; ?>
  </select>
  <button class="btn sm" type="submit">Filtrar</button>
  <?php if ($q || $fStatus): ?><a class="lnk" href="/admin/leads.php">Limpar</a><?php endif; ?>
</form>

<div class="tablewrap">
<table>
  <thead>
    <tr><th>Nome</th><th>Categoria</th><th class="hide-sm">Contacto</th>
        <th class="hide-sm">Notas</th><th>Fase</th><th class="hide-sm">Último toque</th><th></th></tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="7" class="empty">Nenhuma lead encontrada.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $l): ?>
    <tr class="<?= $pendingLead === (int)$l['id'] ? 'pending' : '' ?>">
      <td class="b"><?= e($l['name']) ?></td>
      <td><span class="chip"><?= CATS[$l['category']] ?? '—' ?></span></td>
      <td class="hide-sm" style="font-size:0.7rem">
        <?php $c = (string)$l['contact'];
              if (preg_match('~^https?://~i', $c)): ?>
          <a href="<?= e($c) ?>" target="_blank" rel="noopener noreferrer nofollow">
            <?= e(shorten(preg_replace('~^https?://(www\.)?~i', '', $c), 34)) ?>
          </a>
        <?php else: ?><span class="muted"><?= e($c) ?></span><?php endif; ?>
      </td>
      <td class="muted hide-sm" style="font-size:0.7rem"><?= e(shorten($l['notes'], 55)) ?></td>
      <td>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="move">
          <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <input type="hidden" name="back" value="<?= e($fStatus) ?>">
          <select name="status" onchange="this.form.submit()"
                  style="font-size:0.66rem;padding:0.25rem 0.3rem;border-color:var(--hairline)">
            <?php foreach (STAGES as $k => $label): ?>
              <option value="<?= $k ?>" <?= $l['status'] === $k ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td class="muted hide-sm" style="font-size:0.7rem"><?= e($l['last_touch'] ?: '—') ?></td>
      <td class="num nowrap">
        <?php if ($pendingLead === (int)$l['id']): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="btn danger sm" type="submit">Sim, eliminar</button>
          </form>
          <a class="lnk" href="/admin/leads.php">Cancelar</a>
        <?php else: ?>
          <a class="lnk del" href="/admin/leads.php?del=<?= (int)$l['id'] ?>">Eliminar</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2>Adicionar lead</h2>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="create">
  <div class="grid">
    <div class="field">
      <label for="lname">Nome</label>
      <input type="text" id="lname" name="name" required>
    </div>
    <div class="field">
      <label for="category">Categoria</label>
      <select id="category" name="category">
        <?php foreach (CATS as $k => $label): ?><option value="<?= $k ?>"><?= $label ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="contact">Contacto</label>
      <input type="text" id="contact" name="contact" placeholder="instagram.com/… ou email">
    </div>
    <div class="field">
      <label for="status">Fase</label>
      <select id="status" name="status">
        <?php foreach (STAGES as $k => $label): ?><option value="<?= $k ?>"><?= $label ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="last_touch">Último toque</label>
      <input type="date" id="last_touch" name="last_touch">
    </div>
    <div class="field wide">
      <label for="lnotes">Notas</label>
      <input type="text" id="lnotes" name="notes">
    </div>
  </div>
  <div class="actions"><button class="btn" type="submit">Adicionar</button></div>
</form>

<?php render_footer(); ?>
