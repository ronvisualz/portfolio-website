<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/sheet.php';
$user = require_login();

// Os números vêm da folha Google — das colunas calculadas dela, para o
// site e a folha mostrarem sempre o mesmo.
$d = null; $sheetErr = null;
try {
    $d = sheet_data();
} catch (SheetException $e) {
    $sheetErr = $e;
}

$years = $d['years'] ?? [(int)date('Y')];
// Por omissão, o ano corrente (sheet_data inclui-o sempre na lista).
$year  = (int)($_GET['year'] ?? date('Y'));
if (!in_array($year, $years, true)) $year = (int)date('Y');

render_header('Painel', 'dashboard');
render_flashes();
echo sheet_status_line($d);
?>

<h1 style="margin-bottom:1.8rem">Painel</h1>

<?php if ($sheetErr): ?>
  <?= sheet_error_box($sheetErr) ?>
<?php else: $t = sheet_year_totals($d, $year); ?>

<?php if (count($years) > 1): ?>
<form class="bar" method="get">
  <select name="year" onchange="this.form.submit()" aria-label="Ano">
    <?php foreach ($years as $y): ?>
      <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="btn sm" type="submit">Ver</button></noscript>
</form>
<?php endif; ?>

<div class="stats paint">
  <div class="stat">
    <div class="k">Faturação (s/ IVA)</div>
    <div class="v"><?= eur($t['revenue']) ?></div>
    <div class="n">c/ IVA: <?= eur($t['revenue'] + $t['vat_out']) ?></div>
  </div>
  <div class="stat">
    <div class="k">Lucro antes de impostos</div>
    <div class="v"><?= eur($t['profit']) ?></div>
    <div class="n">Faturação menos gastos</div>
  </div>
  <div class="stat">
    <div class="k">Gastos totais</div>
    <div class="v"><?= eur($t['costs']) ?></div>
    <div class="n"><?= $t['revenue'] > 0 ? round($t['costs'] / $t['revenue'] * 100) : 0 ?>% da faturação</div>
  </div>
</div>
<?php endif; ?>

<?php render_footer(); ?>
