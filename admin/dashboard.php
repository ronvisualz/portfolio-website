<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();

$years = available_years($pdo);
$year  = (int)($_GET['year'] ?? $years[0]);
if (!in_array($year, $years, true)) $year = $years[0];

$t = year_totals($pdo, $year);
$v = year_vat($pdo, $year);   // só para saber o IVA realmente cobrado

render_header('Painel', 'dashboard');
render_flashes();
?>

<h1 style="margin-bottom:1.8rem">Painel</h1>

<div class="stats paint">
  <div class="stat">
    <div class="k">Faturação (s/ IVA)</div>
    <div class="v"><?= eur($t['revenue']) ?></div>
    <div class="n">c/ IVA: <?= eur($t['revenue'] + $v['out']) ?></div>
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

<?php render_footer(); ?>
