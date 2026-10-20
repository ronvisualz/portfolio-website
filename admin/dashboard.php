<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/finance.php';
$user = require_login();
require_page('dashboard');

// Dashboard executivo: só leitura. Por agora os mesmos números do Painel
// de sempre, lidos do MySQL e calculados pelo sistema. A Carteira passou
// para Negócios e Financeiro. Os indicadores novos ainda estão por decidir.

$rate  = vat_rate($pdo);
$years = finance_years($pdo);
$year  = (int)($_GET['year'] ?? date('Y'));
if (!in_array($year, $years, true)) $year = (int)date('Y');
$t = finance_year($pdo, $year, $rate);

render_header('Dashboard', 'dashboard');
render_flashes();
?>

<h1 style="margin-bottom:1.8rem">Dashboard</h1>

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
    <div class="n">c/ IVA: <?= eur($t['gross']) ?></div>
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

<p class="muted" style="margin-top:1.6rem">
  <?php if (can('negocios')): ?><a class="lnk" href="/admin/negocios.php">Negócios em produção</a><?php endif; ?>
  <?php if (can('financeiro')): ?> · <a class="lnk" href="/admin/financeiro.php?year=<?= $year ?>">Financeiro de <?= $year ?></a><?php endif; ?>
</p>

<?php render_footer(); ?>
