<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/data.php';
require __DIR__ . '/inc/carteira.php';
$user = require_login();

// As ações da Carteira (por baixo dos números) gravam aqui e voltam aqui.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    carteira_handle_post();
}

$d = data_all();
$years = $d['years'];
// Por omissão, o ano corrente (data_all inclui-o sempre na lista).
$year  = (int)($_GET['year'] ?? date('Y'));
if (!in_array($year, $years, true)) $year = (int)date('Y');
$t = data_year_totals($d, $year);

render_header('Painel', 'dashboard');
render_flashes();
echo data_export_line();
echo paginate_css();
?>

<h1 style="margin-bottom:1.8rem">Painel</h1>

<?php if (count($years) > 1): ?>
<form class="bar" method="get">
  <?php foreach (['tab', 'pt', 'pc', 'pi'] as $k): if (isset($_GET[$k]) && $_GET[$k] !== ''): ?>
    <input type="hidden" name="<?= $k ?>" value="<?= e((string)$_GET[$k]) ?>">
  <?php endif; endforeach; ?>
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

<?php carteira_render($d); ?>

<?php render_footer(); ?>
