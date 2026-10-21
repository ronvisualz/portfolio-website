<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/finance.php';
require_once __DIR__ . '/inc/alerts.php';
require_once __DIR__ . '/inc/reach.php';
$user = require_login();
require_page('dashboard');

// Dashboard executivo: só leitura. Saudação, «O teu dia» (o que é da
// pessoa hoje, conforme as permissões), o resumo do ano e os negócios
// que pedem atenção agora (§11.3, §11.4, §11.14).

$rate  = vat_rate($pdo);
$years = finance_years($pdo);
$year  = (int)($_GET['year'] ?? date('Y'));
if (!in_array($year, $years, true)) $year = (int)date('Y');
$t = finance_year($pdo, $year, $rate);

$alerts = my_alerts($pdo);
$lim    = alert_limits($pdo);

// ── «O teu dia» ─────────────────────────────────────────────
$today = [];
if (can('tarefas'))    $today[] = ['Tarefas a vencer', $alerts['task'], '/admin/calendar.php?view=tarefas', 'Sem tarefas a vencer.'];
if (can('leads'))      $today[] = ['Leads em atraso / a aproximar', array_merge($alerts['queue'], $alerts['lead']), '/admin/leads.php?urgente=1', 'Todas as leads dentro do prazo.'];
if (can('pipeline'))   $today[] = ['Negócios sem movimento', $alerts['deal'], '/admin/pipeline.php?vista=risco', 'Tudo a andar.'];
if (can('financeiro')) $today[] = ['Por receber em atraso', $alerts['invoice'], '/admin/financeiro.php?tab=receber', 'Nada em atraso.'];
$todo = array_sum(array_map(fn($c) => count($c[1]), $today));

// ── destaques: negócios que pedem atenção agora ─────────────
$hot = [];
if (can('pipeline')) {
    [$rs, $ra] = reach_sql($pdo, 'pipeline');
    $st = $pdo->prepare("SELECT d.*, s.name AS stage, f.name AS funnel, u.name AS owner
                           FROM deals d
                           LEFT JOIN funnel_stages s ON s.id = d.stage_id
                           LEFT JOIN funnels f ON f.id = d.funnel_id
                           LEFT JOIN users u ON u.id = d.owner_id
                          WHERE d.phase = 'pipeline' AND $rs");
    $st->execute($ra);
    $open = $st->fetchAll();
    foreach ($open as &$d) { $d['idle'] = days_since(deal_last_move($d)); } unset($d);
    $risky = array_filter($open, fn($d) => $d['idle'] > $lim['deal']);
    usort($risky, fn($a, $b) => [$b['idle'], (float)$b['value_estimate']] <=> [$a['idle'], (float)$a['value_estimate']]);
    foreach (array_slice($risky, 0, 3) as $d) {
        $hot[$d['id']] = ['d' => $d, 'risk' => true, 'why' => 'Sem movimento há ' . $d['idle'] . ' dias', 'val' => $d['value_estimate']];
    }
}
if (can('financeiro')) {
    foreach (array_slice(array_filter(receivables($pdo), fn($r) => $r['age'] > $lim['invoice']), 0, 2) as $r) {
        $hot[$r['id']] = ['d' => $r + ['stage' => 'Por receber', 'funnel' => $r['contact_name'] ?? ''], 'risk' => true,
                          'why' => 'Por receber há ' . $r['age'] . ' dias', 'val' => $r['net'], 'url' => '/admin/financeiro.php?tab=receber&doc=' . (int)$r['id']];
    }
}
if (!empty($open)) {
    usort($open, fn($a, $b) => (float)$b['value_estimate'] <=> (float)$a['value_estimate']);
    foreach ($open as $d) {
        if (count($hot) >= 6) break;
        if (isset($hot[$d['id']]) || (float)$d['value_estimate'] <= 0) continue;
        $hot[$d['id']] = ['d' => $d, 'risk' => false, 'why' => 'Maior valor em aberto', 'val' => $d['value_estimate']];
    }
}

const DOW_PT = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon'));
$dateLine = DOW_PT[(int)$now->format('w')] . ', ' . (int)$now->format('j') . ' de ' . mb_strtolower(month_name((int)$now->format('n')));

render_header('Dashboard', 'dashboard');
render_flashes();
?>
<h1 class="hello" data-greet="<?= e(greet_name()) ?>">Olá, <?= e(greet_name()) ?></h1>
<p class="hello-sub"><?= e(ucfirst($dateLine)) ?> · <?= $todo ? ($todo === 1 ? '1 coisa a pedir atenção' : $todo . ' coisas a pedir atenção') : 'nada a pedir atenção' ?></p>

<?php if ($today): ?>
<h2 class="eyebrow" style="margin:0 0 10px">O teu dia</h2>
<section class="today" aria-label="O teu dia">
  <?php foreach ($today as [$title, $items, $more, $none]): ?>
  <div class="today-col">
    <h3><span><?= e($title) ?></span><span class="mono"><?= count($items) ?></span></h3>
    <?php if (!$items): ?><div class="none"><?= e($none) ?></div>
    <?php else: ?>
    <ul>
      <?php foreach (array_slice($items, 0, 5) as $it): ?>
        <li class="<?= $it['sev'] === 'bad' ? 'bad' : '' ?>"><a href="<?= e($it['url']) ?>"><span><?= e(shorten($it['title'], 42)) ?></span><small><?= e($it['sub']) ?></small></a></li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($items) > 5): ?><a class="lnk" href="<?= e($more) ?>">Ver todas (<?= count($items) ?>) →</a><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="bar" style="margin:6px 0 10px">
  <h2 style="margin:0">Resumo de <?= $year ?></h2>
  <?php if (count($years) > 1): ?>
  <form method="get" data-auto style="margin-left:auto">
    <select name="year" class="mini" aria-label="Ano">
      <?php foreach ($years as $y): ?><option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
    </select>
    <noscript><button class="btn sm" type="submit">Ver</button></noscript>
  </form>
  <?php endif; ?>
</div>
<div class="stats">
  <div class="stat hero"><div class="k">Faturação s/ IVA</div><div class="v"><?= eur0($t['revenue']) ?></div><div class="n">c/ IVA <?= eur0($t['gross']) ?> · <?= $t['count'] ?> negócios</div></div>
  <div class="stat"><div class="k">Lucro antes de impostos</div><div class="v <?= $t['profit'] < 0 ? 'neg' : '' ?>"><?= eur0($t['profit']) ?></div><div class="n">Faturação menos gastos</div></div>
  <div class="stat"><div class="k">Gastos</div><div class="v"><?= eur0($t['costs']) ?></div><div class="n"><?= $t['revenue'] > 0 ? round($t['costs'] / $t['revenue'] * 100) . '% da faturação' : '—' ?></div></div>
  <div class="stat"><div class="k">Por receber</div><div class="v"><?= eur0($t['unpaid']) ?></div><div class="n">s/ IVA<?= can('financeiro') ? ' · <a href="/admin/financeiro.php?tab=receber">antiguidade</a>' : '' ?></div></div>
</div>

<?php if ($hot): ?>
<h2>Pedem atenção agora</h2>
<div class="hot">
  <?php foreach ($hot as $h): $d = $h['d']; ?>
    <a class="<?= $h['risk'] ? 'risk' : '' ?>" href="<?= e($h['url'] ?? deal_url($d)) ?>">
      <span class="id"><span><?= e($d['code']) ?></span><span><?= e($d['stage'] ?? '') ?></span></span>
      <b><?= e($d['name']) ?></b>
      <span class="row"><span><?= e($h['why']) ?></span><span class="mono"><?= $h['val'] !== null ? eur0((float)$h['val']) : '—' ?></span></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
