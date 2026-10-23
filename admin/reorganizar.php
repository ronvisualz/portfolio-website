<?php
/**
 * topper. admin — «Reorganizar o Drive» (§24, fase 2). Só administradores.
 *
 * GET               a pré-visualização (o que vai mudar, de onde para onde, com caixas para escolher)
 *                   ou, se há uma reorganização a correr, o progresso; no fim, o relatório.
 * POST a=start      começa com os itens escolhidos (o plano é recalculado aqui, no servidor)
 * POST a=run        uma volta (~15 s) — a página chama-a enquanto estiver aberta; o cron também corre
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/files_reorg.php';
$user = require_login();
if (!role_is_admin((string)$user['role'])) {
    http_response_code(403);
    exit('Só os administradores podem reorganizar o Drive.');
}
$ready = files_reorg_ready($pdo) && files_on();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) { flash('Pedido inválido. Recarregue a página.', 'err'); redirect('/admin/reorganizar.php'); }
    $a = (string)($_POST['a'] ?? '');
    if ($a === 'run') {
        session_write_close();
        @set_time_limit(120);
        header('Content-Type: application/json; charset=utf-8');
        $s = $ready ? files_reorg_run($pdo, 15.0) : [];
        echo json_encode(['ok' => true, 'busy' => $ready && ($s['queued'] > 0 || $s['moves'] > 0), 'done' => $s['done'] ?? 0, 'total' => $s['total'] ?? 0, 'moves' => $s['moves'] ?? 0]);
        exit;
    }
    if ($a === 'start' && $ready) {
        if (files_reorg_busy($pdo)) { flash('Já há uma reorganização a correr.', 'err'); redirect('/admin/reorganizar.php'); }
        $chosen = array_values(array_filter(array_map('strval', (array)($_POST['item'] ?? []))));
        $run = $chosen ? files_reorg_start($pdo, files_reorg_plan($pdo), $chosen, (int)$user['id']) : 0;
        flash($run ? 'Reorganização a começar. Pode fechar a página: continua pelo cron.' : 'Escolha pelo menos um item.', $run ? 'ok' : 'err');
        redirect('/admin/reorganizar.php');
    }
    redirect('/admin/reorganizar.php');
}

render_header('Reorganizar o Drive', 'definicoes', '', ['Reorganizar o Drive']);
render_flashes();
?>
<div class="page-head">
  <div><h1>Reorganizar o Drive</h1>
    <p class="sub">Passa o que já existia para a estrutura nova: negócios perdidos e leads arquivadas para o Arquivo, faturas antigas
      para «Faturação», peças de marketing para a pasta do negócio e os originais do Centro Criativo para o Drive. Nada é apagado:
      só muda de sítio, e os links, favoritos e partilhas acompanham.</p></div>
  <div class="actions" style="margin:0"><a class="btn ghost" href="/admin/definicoes.php?t=drive"><?= icon('chev-left') ?> Definições</a></div>
</div>
<?php if (!$ready): ?>
  <div class="panel"><p><?= files_on() ? 'A base de dados está a atualizar. Recarregue a página daqui a pouco.' : 'O Drive não está ligado neste servidor.' ?></p></div>
<?php render_footer(); exit; endif;

$st = files_reorg_status($pdo);
$busy = $st['queued'] > 0 || $st['moves'] > 0;

if ($busy): ?>
  <section class="panel rg-run" data-rg-run data-csrf="<?= e(csrf_token()) ?>">
    <h2>A reorganizar…</h2>
    <p class="muted" data-rg-txt><?= (int)$st['done'] ?> de <?= (int)$st['total'] ?> itens feitos<?= $st['moves'] ? ' · ' . (int)$st['moves'] . ' pastas a mudar' : '' ?>.</p>
    <div class="rg-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int)$st['total'] ?>" aria-valuenow="<?= (int)$st['done'] ?>"><span style="width:<?= $st['total'] ? round(100 * $st['done'] / $st['total']) : 0 ?>%"></span></div>
    <p class="muted small">Pode fechar esta página: continua pelo cron de 5 em 5 minutos. Os ficheiros abrem normalmente durante a mudança.</p>
  </section>
  <script>
  (function () {
    var box = document.querySelector('[data-rg-run]'); if (!box) return;
    var txt = box.querySelector('[data-rg-txt]'), bar = box.querySelector('.rg-bar span');
    function step() {
      var fd = new FormData(); fd.append('a', 'run'); fd.append('csrf', box.dataset.csrf);
      fetch('/admin/reorganizar.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
        txt.textContent = j.done + ' de ' + j.total + ' itens feitos' + (j.moves ? ' · ' + j.moves + ' pastas a mudar' : '') + '.';
        bar.style.width = (j.total ? Math.round(100 * j.done / j.total) : 0) + '%';
        if (j.busy) setTimeout(step, 400); else location.reload();
      }, function () { setTimeout(step, 5000); });
    }
    step();
  })();
  </script>
<?php else:
  // o relatório do último run
  if ($st['run']): ?>
  <details class="panel rg-report"<?= empty($_GET['plano']) ? ' open' : '' ?>>
    <summary><b>Última reorganização</b> <span class="muted">· <?= (int)$st['done'] ?> itens feitos<?= $st['error'] ? ' · <span class="neg">' . (int)$st['error'] . ' com erro</span>' : '' ?></span></summary>
    <ul class="rg-list">
      <?php foreach ($st['jobs'] as $j): ?>
        <li><span class="chip <?= $j['status'] === 'done' ? 'ok' : ($j['status'] === 'error' ? 'no' : 'wait') ?>"><?= e(['done' => 'feito', 'error' => 'erro', 'queued' => 'por fazer'][$j['status']] ?? $j['status']) ?></span>
          <span><b><?= e(FILES_REORG_KINDS[$j['kind']][1] ?? $j['kind']) ?></b> · <?= e((string)$j['label']) ?><br><small class="muted"><?= e((string)$j['result']) ?></small></span></li>
      <?php endforeach; ?>
      <?php foreach ($st['move_errors'] as $m): ?>
        <li><span class="chip no">erro</span><span><span class="mono small"><?= e($m['from_prefix']) ?> → <?= e($m['to_prefix']) ?></span><br><small class="muted"><?= e((string)$m['error']) ?></small></span></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif;

  $plan = files_reorg_plan($pdo);
  if (!$plan): ?>
    <div class="panel"><p><?= icon('check') ?> O Drive já está organizado: não há nada para mudar.</p></div>
  <?php else:
    $by = [];
    foreach ($plan as $it) $by[$it['kind']][] = $it;
    $files = array_sum(array_column($plan, 'files')); ?>
  <form method="post" class="rg-plan" data-rg-plan>
    <?= csrf_field() ?><input type="hidden" name="a" value="start">
    <p class="muted">Pré-visualização: <b><?= count($plan) ?></b> itens, <?= (int)$files ?> ficheiros. Tire o visto ao que não quiser mudar.</p>
    <?php foreach (FILES_REORG_KINDS as $kind => [, $title, $help]): if (empty($by[$kind])) continue; ?>
      <section class="panel rg-sec">
        <h2><label class="check"><input type="checkbox" checked data-rg-all="<?= e($kind) ?>"> <?= e($title) ?></label> <span class="mono muted"><?= count($by[$kind]) ?></span></h2>
        <p class="muted small"><?= e($help) ?></p>
        <ul class="rg-list">
          <?php foreach ($by[$kind] as $it): ?>
            <li><label class="check"><input type="checkbox" name="item[]" value="<?= e($it['id']) ?>" checked data-rg-kind="<?= e($kind) ?>">
              <span><b><?= e($it['label']) ?></b><?= $it['note'] !== '' ? ' <span class="muted">· ' . e($it['note']) . '</span>' : '' ?>
                <br><span class="mono small rg-path"><?= e($it['from']) ?><?= $it['to'] !== '' ? ' <span class="muted">→</span> ' . e($it['to']) : '' ?></span>
                <span class="muted small"><?= $kind !== 'register' ? ' · ' . (int)$it['files'] . ($it['files'] === 1 ? ' ficheiro' : ' ficheiros') . ($it['size'] ? ' · ' . e(drive_size((int)$it['size'])) : '') : '' ?></span></span></label></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endforeach; ?>
    <div class="actions"><button class="btn" type="submit"><?= icon('drive') ?> Reorganizar os itens escolhidos</button>
      <span class="muted small">Corre em segundo plano; os ficheiros continuam a abrir durante a mudança.</span></div>
  </form>
  <script>
  document.addEventListener('change', function (e) {
    var all = e.target.closest('[data-rg-all]');
    if (all) document.querySelectorAll('[data-rg-kind="' + all.dataset.rgAll + '"]').forEach(function (c) { c.checked = all.checked; });
  });
  </script>
  <?php endif;
endif; ?>
<style>
  .rg-bar { height: 10px; border-radius: 6px; background: var(--line); overflow: hidden; margin: 10px 0; }
  .rg-bar span { display: block; height: 100%; background: var(--hi); transition: width .4s ease; }
  .rg-list { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 6px; }
  .rg-list li { display: flex; gap: 10px; align-items: flex-start; padding: 6px 4px; border-top: 1px solid var(--line); }
  .rg-list li:first-child { border-top: 0; }
  .rg-list .check { align-items: flex-start; }
  .rg-list .check input { margin-top: 3px; }
  .rg-path { overflow-wrap: anywhere; }
  .rg-sec h2 { display: flex; gap: 8px; align-items: center; font-size: 16px; margin-top: 0; }
  .rg-sec, .rg-report { margin-bottom: 12px; }
  .rg-sec h2 .check { font-size: 16px; font-weight: 600; }
  .rg-report summary { cursor: pointer; }
</style>
<?php render_footer();
