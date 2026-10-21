<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/leads.php';
$user = require_login();
require_page('relatorios');

// Relatórios por segmento (§11.16): o mesmo período visto por funil, por
// origem da lead ou mês a mês. Mudar o agrupamento não sai da página.
// Os valores em euros só aparecem a quem vê o Financeiro.
//
//   Entradas   registos criados no período (leads, incluindo as que já
//              são negócio; as que estão em quarentena não contam)
//   Convertidas  leads convertidas em negócio no período
//   Ganhos     negócios que chegaram a Kickoff no período (os importados
//              da folha contam no mês do trabalho)
//   Perdidos   negócios marcados como perdidos no período

const SEGMENTS = ['funil' => 'Por funil', 'origem' => 'Por origem da lead', 'periodo' => 'Por mês'];

$money = can('financeiro');
$years = array_values(array_unique(array_merge([(int)date('Y')],
    array_map('intval', $pdo->query('SELECT DISTINCT ' . (db_is_sqlite($pdo) ? "CAST(strftime('%Y', created_at) AS INTEGER)" : 'YEAR(created_at)')
                                    . ' FROM deals UNION SELECT DISTINCT year FROM deals WHERE year IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)))));
rsort($years);
$year = (int)($_GET['year'] ?? date('Y'));
if (!in_array($year, $years, true)) $year = (int)date('Y');
$q = (int)($_GET['t'] ?? 0);
if ($q < 0 || $q > 4) $q = 0;
$seg = isset(SEGMENTS[$_GET['por'] ?? '']) ? (string)$_GET['por'] : 'funil';

$from = sprintf('%04d-%02d-01', $year, $q ? ($q - 1) * 3 + 1 : 1);
$to   = $q ? (new DateTimeImmutable($from))->modify('+3 months')->format('Y-m-d') : sprintf('%04d-01-01', $year + 1);
$in   = fn(?string $d) => $d !== null && $d !== '' && substr($d, 0, 10) >= $from && substr($d, 0, 10) < $to;

$funnels = $pdo->query('SELECT id, name FROM funnels ORDER BY position, name')->fetchAll(PDO::FETCH_KEY_PAIR);
$deals = $pdo->query('SELECT id, code, phase, funnel_id, origin_funnel_id, origin, created_at, converted_at, lost_at, budget, value_estimate, year, month, is_quarantined FROM deals')->fetchAll();
$wonAt = $pdo->query("SELECT h.deal_id, MIN(h.created_at) FROM deal_history h JOIN funnel_stages s ON s.id = h.to_stage_id
                       WHERE h.event = 'stage' AND s.kind = 'kickoff' GROUP BY h.deal_id")->fetchAll(PDO::FETCH_KEY_PAIR);

$empty = ['in' => 0, 'conv' => 0, 'won' => 0, 'lost' => 0, 'value' => 0.0];
$groups = ['funil' => [], 'origem' => [], 'periodo' => []];
for ($m = ($q ? ($q - 1) * 3 + 1 : 1); $m <= ($q ? $q * 3 : 12); $m++) $groups['periodo'][$m] = $empty;
foreach ($deals as $d) {
    $won = $d['phase'] === 'production' || $d['phase'] === 'done' || isset($wonAt[$d['id']]);
    $wonDate = $wonAt[$d['id']] ?? ($won && $d['year'] ? sprintf('%04d-%02d-01', (int)$d['year'], (int)($d['month'] ?: 1)) : ($won ? $d['converted_at'] : null));
    $hits = [
        'in'   => !(int)$d['is_quarantined'] && $in($d['created_at']) ? $d['created_at'] : null,
        'conv' => $in($d['converted_at']) ? $d['converted_at'] : null,
        'won'  => $won && in_array($d['phase'], ['production', 'done'], true) && $in($wonDate) ? $wonDate : null,
        'lost' => $d['phase'] === 'lost' && $in($d['lost_at']) ? $d['lost_at'] : null,
    ];
    if (!array_filter($hits)) continue;
    $f = (int)($d['origin_funnel_id'] ?: $d['funnel_id']);
    $keys = [
        'funil'  => $f ? ($funnels[$f] ?? 'Funil removido') : 'Ainda lead (sem funil)',
        'origem' => LEAD_ORIGIN[$d['origin']] ?? 'Manual',
    ];
    foreach ($hits as $metric => $date) {
        if ($date === null) continue;
        foreach ($keys as $g => $k) {
            $groups[$g][$k] ??= $empty;
            $groups[$g][$k][$metric]++;
        }
        $m = (int)substr($date, 5, 2);
        if (isset($groups['periodo'][$m])) $groups['periodo'][$m][$metric]++;
        if ($metric === 'won') {
            $v = (float)($d['budget'] ?: $d['value_estimate']);
            foreach ($keys as $g => $k) $groups[$g][$k]['value'] += $v;
            if (isset($groups['periodo'][$m])) $groups['periodo'][$m]['value'] += $v;
        }
    }
}
foreach (['funil', 'origem'] as $g) uasort($groups[$g], fn($a, $b) => [$b['won'], $b['in']] <=> [$a['won'], $a['in']]);
$tot = $empty;
foreach ($groups['funil'] as $r) foreach ($r as $k => $v) $tot[$k] += $v;

/** O que a barra mede: valor ganho (quem vê o Financeiro) ou n.º de ganhos. */
$metric = $money ? 'value' : 'won';
$fmt = fn($v) => $money ? eur0((float)$v) : (string)(int)$v;
$pct = fn(int $a, int $b) => $b > 0 ? round($a / $b * 100) . '%' : '—';
$url = fn(array $p) => '/admin/relatorios.php?' . http_build_query(array_filter($p + ['year' => $year, 't' => $q ?: null, 'por' => $seg]));

render_header('Relatórios', 'relatorios');
render_flashes();
?>
<h1>Relatórios</h1>
<div class="sub"><?= $q ? 'T' . $q . ' de ' . $year : 'Ano de ' . $year ?> · entradas, conversões, ganhos e perdas<?= $money ? ', com o valor ganho' : '' ?></div>

<form class="bar" method="get" data-auto>
  <input type="hidden" name="por" value="<?= e($seg) ?>">
  <select name="year" aria-label="Ano"><?php foreach ($years as $y): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select>
  <select name="t" aria-label="Trimestre">
    <option value="0">Todo o ano</option>
    <?php for ($i = 1; $i <= 4; $i++): ?><option value="<?= $i ?>" <?= $q === $i ? 'selected' : '' ?>><?= $i ?>.º trimestre</option><?php endfor; ?>
  </select>
  <button class="btn sm apply" type="submit">Ver</button>
</form>

<div class="stats">
  <div class="stat hero"><div class="k"><?= $money ? 'Valor ganho' : 'Ganhos' ?></div><div class="v"><?= $fmt($tot[$metric]) ?></div><div class="n"><?= $tot['won'] ?> negócios chegaram a Kickoff</div></div>
  <div class="stat"><div class="k">Entradas</div><div class="v"><?= $tot['in'] ?></div><div class="n">leads novas</div></div>
  <div class="stat"><div class="k">Conversão</div><div class="v"><?= $pct($tot['conv'], $tot['in']) ?></div><div class="n"><?= $tot['conv'] ?> convertidas em negócio</div></div>
  <div class="stat"><div class="k">Taxa de ganho</div><div class="v"><?= $pct($tot['won'], $tot['won'] + $tot['lost']) ?></div><div class="n"><?= $tot['lost'] ?> perdidos</div></div>
</div>

<nav class="views" aria-label="Agrupar">
  <?php foreach (SEGMENTS as $k => $label): ?>
    <a href="<?= e($url(['por' => $k])) ?>" data-seg="<?= $k ?>" class="<?= $seg === $k ? 'on' : '' ?>"<?= $seg === $k ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php foreach (SEGMENTS as $g => $label):
    $rows = $groups[$g];
    $max = max(1, ...array_values(array_map(fn($r) => (float)$r[$metric], $rows ?: [$empty]))); ?>
<section class="seg-pane" data-pane="<?= $g ?>"<?= $seg === $g ? '' : ' hidden' ?>>
  <div class="panel">
    <h3><?= e($money ? 'Valor ganho' : 'Negócios ganhos') ?> · <?= e(mb_strtolower($label)) ?></h3>
    <?php if (!array_filter($rows, fn($r) => $r['in'] || $r['won'] || $r['lost'] || $r['conv'])): ?>
      <p class="empty">Sem movimento neste período.</p>
    <?php else: foreach ($rows as $k => $r): $name = $g === 'periodo' ? month_name((int)$k) : $k; ?>
      <div class="hbar" data-tip="<?= e($name . ' · ' . $fmt($r[$metric]) . ' · ' . $r['won'] . ' ganhos') ?>" tabindex="0" aria-label="<?= e($name . ': ' . $fmt($r[$metric])) ?>">
        <span><?= e($name) ?></span>
        <span class="track"><i style="width:<?= round((float)$r[$metric] / $max * 100, 1) ?>%"></i></span>
        <span class="num"><?= $fmt($r[$metric]) ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
  <div class="tablewrap" style="margin-top:12px"><table>
    <thead><tr><th><?= $g === 'periodo' ? 'Mês' : ($g === 'funil' ? 'Funil' : 'Origem') ?></th><th class="num">Entradas</th><th class="num">Convertidas</th><th class="num">Conversão</th>
      <th class="num">Ganhos</th><th class="num">Perdidos</th><th class="num">Taxa de ganho</th><?= $money ? '<th class="num">Valor ganho</th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($rows as $k => $r): ?>
      <tr><td><?= e($g === 'periodo' ? month_name((int)$k) : $k) ?></td><td class="num"><?= $r['in'] ?></td><td class="num"><?= $r['conv'] ?></td>
        <td class="num"><?= $pct($r['conv'], $r['in']) ?></td><td class="num"><?= $r['won'] ?></td><td class="num"><?= $r['lost'] ?></td>
        <td class="num"><?= $pct($r['won'], $r['won'] + $r['lost']) ?></td><?= $money ? '<td class="num">' . eur0($r['value']) . '</td>' : '' ?></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td class="num"><?= $tot['in'] ?></td><td class="num"><?= $tot['conv'] ?></td><td class="num"><?= $pct($tot['conv'], $tot['in']) ?></td>
      <td class="num"><?= $tot['won'] ?></td><td class="num"><?= $tot['lost'] ?></td><td class="num"><?= $pct($tot['won'], $tot['won'] + $tot['lost']) ?></td><?= $money ? '<td class="num">' . eur0($tot['value']) . '</td>' : '' ?></tr></tfoot>
  </table></div>
</section>
<?php endforeach; ?>

<?php if ($money && can('orcamentacao')):
    require_once __DIR__ . '/inc/budget_view.php';
    $br = budget_reports($pdo, $year);
    $mt = function (array $rows, string $label) use ($year) {
        if (!$rows) return '<p class="empty">Ainda não há negócios concluídos com Resultado em ' . $year . '.</p>';
        $h = '<table><thead><tr><th>' . e($label) . '</th><th class="num">Concluídos</th><th class="num">Margem orçada</th><th class="num">Margem real</th><th class="num">Lucro/mês real</th></tr></thead><tbody>';
        foreach ($rows as $k => $r) {
            $d = $r['gm_r_avg'] - $r['gm_b_avg'];
            $h .= '<tr><td>' . e((string)$k) . '</td><td class="num">' . $r['n'] . '</td><td class="num">' . e(budget_pct($r['gm_b_avg'])) . '</td>'
                . '<td class="num ' . ($d < -0.05 ? 'neg b' : '') . '">' . e(budget_pct($r['gm_r_avg'])) . ($d < -0.05 ? ' <small>(' . e(budget_n(round($d, 1), 1)) . ' pp)</small>' : '') . '</td>'
                . '<td class="num">' . e(budget_eur($r['pm_r_avg'])) . '</td></tr>';
        }
        return $h . '</tbody></table>';
    }; ?>
<h2 id="orcamentacao">Orçamentação · orçado vs real <?= budget_ii('margin', 'Aqui: médias dos Resultados fechados (negócios concluídos em ' . $year . ').') ?></h2>
<div class="flow-grid">
  <div class="tablewrap"><?= $mt($br['funnel'], 'Funil') ?></div>
  <div class="tablewrap"><?= $mt($br['type'], 'Tipo de trabalho') ?></div>
</div>
<div class="tablewrap" style="margin-top:12px">
  <?php if (!$br['over']): ?><p class="empty">Ainda não há custos ligados a linhas de fornecedores nos Resultados de <?= $year ?>.</p>
  <?php else: ?>
  <table><thead><tr><th>Serviço (linhas de fornecedores)</th><th class="num">Linhas</th><th class="num">Orçado</th><th class="num">Real</th><th class="num">Derrapagem</th><th class="num">Linhas acima</th></tr></thead><tbody>
  <?php foreach ($br['over'] as $k => $o): ?>
    <tr><td><?= e($k) ?></td><td class="num"><?= $o['lines'] ?></td><td class="num"><?= e(budget_eur($o['budget'])) ?></td><td class="num"><?= e(budget_eur($o['real'])) ?></td>
      <td class="num <?= ($o['pct'] ?? 0) > 2 ? 'neg b' : (($o['pct'] ?? 0) < -2 ? 'pos' : '') ?>"><?= $o['pct'] === null ? '—' : (($o['pct'] > 0 ? '+' : '') . e(budget_pct($o['pct']))) ?></td>
      <td class="num"><?= $o['over'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Mudar o agrupamento sem sair da página (sem JavaScript, os separadores são links).
(function () {
  var nav = document.querySelector('.views[aria-label="Agrupar"]');
  nav.addEventListener('click', function (e) {
    var a = e.target.closest('[data-seg]'); if (!a || e.metaKey || e.ctrlKey) return;
    e.preventDefault();
    var k = a.getAttribute('data-seg');
    nav.querySelectorAll('[data-seg]').forEach(function (x) { x.classList.toggle('on', x === a); if (x === a) x.setAttribute('aria-current', 'true'); else x.removeAttribute('aria-current'); });
    document.querySelectorAll('.seg-pane').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== k; });
    document.querySelector('form.bar input[name=por]').value = k;
    history.replaceState(null, '', a.getAttribute('href'));
  });
})();
</script>
<?php render_footer();
