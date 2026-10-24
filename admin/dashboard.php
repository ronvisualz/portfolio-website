<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/finance.php';
require_once __DIR__ . '/inc/alerts.php';
require_once __DIR__ . '/inc/reach.php';
$user = require_login();
require_page('dashboard');

// Dashboard Executivo (§30): a leitura macro em 30 segundos. De cima para baixo: saudação e «O teu dia» (contadores
// que abrem o detalhe no painel lateral), leitura do mês (IA, de manhã), saúde da empresa (semáforos), resultados
// (P&L, só Financeiro), crescimento e previsão, hall of fame, Comercial e «Pedem atenção agora». Não tem contas
// próprias: lê a fotografia do resultado (inc/exec.php, refeita pelo cron), a camada de métricas dos Relatórios e o
// Financeiro. Cada número leva ao relatório que o explica. Sem Financeiro, o servidor não manda euros.

$uid = (int)$user['id'];
$money = can('financeiro');
$admin = role_is_admin((string)$user['role']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'today_open' && db_has_column($pdo, 'users', 'prefs')) {   // preferência da pessoa: «O teu dia» aberto ou compacto
        $p = user_prefs($pdo, $uid);
        $p['today_open'] = !empty($_POST['open']) ? 1 : 0;
        $pdo->prepare('UPDATE users SET prefs = ? WHERE id = ?')->execute([json_encode($p, JSON_UNESCAPED_UNICODE), $uid]);
        flash($p['today_open'] ? '«O teu dia» passa a abrir expandido.' : '«O teu dia» passa a abrir compacto.');
    } elseif ($do === 'reading_now' && $admin) {   // escrever já a leitura do mês (normalmente é de manhã, pelo cron)
        require_once __DIR__ . '/inc/exec.php';
        exec_snapshot_refresh($pdo);
        $r = exec_reading_make($pdo, true);
        flash($r !== '' ? $r . '.' : 'A base ainda não está no passo 34.');
    } elseif ($do === 'snap_now' && $money) {
        require_once __DIR__ . '/inc/exec.php';
        exec_snapshot_refresh($pdo);
        flash('Resultado recalculado com os lançamentos de agora.');
    }
    redirect('/admin/dashboard.php' . (isset($_GET['p']) ? '?p=' . urlencode((string)$_GET['p']) : ''));
}

// ── «O teu dia» ─────────────────────────────────────────────
$today = today_columns($pdo);
$todo = array_sum(array_map(fn($c) => count($c[3]), $today));
$todayOpen = !empty(user_prefs($pdo, $uid)['today_open']);
$lim = alert_limits($pdo);

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
if ($money) {
    foreach (array_slice(array_filter(receivables($pdo), fn($r) => $r['age'] > 0), 0, 2) as $r) {
        $hot[$r['id'] ?: 'f' . $r['doc_id']] = ['d' => $r + ['stage' => 'Por receber', 'funnel' => $r['contact_name'] ?? ''], 'risk' => true,
                          'why' => 'Em atraso há ' . $r['age'] . ' dias', 'val' => $r['net'], 'url' => $r['url']];
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

// ── a leitura macro (passo 34 e métricas dos Relatórios) ────
require_once __DIR__ . '/inc/metrics_view.php';
$exec = false;
if (metrics_ready($pdo) && fin_ready_safe($pdo)) {
    require_once __DIR__ . '/inc/exec_view.php';
    $exec = exec_ready($pdo);
}
/** O Financeiro existe (passo 30)? Sem carregar o módulo inteiro só para perguntar. */
function fin_ready_safe(PDO $pdo): bool {
    return db_has_table($pdo, 'fin_docs') && db_has_table($pdo, 'exec_months');
}

render_header('Dashboard', 'dashboard');
render_flashes();
?>
<h1 class="hello" data-greet="<?= e(greet_name()) ?>">Olá, <?= e(greet_name()) ?></h1>
<p class="hello-sub"><?= e(ucfirst($dateLine)) ?> · <?= $todo ? ($todo === 1 ? '1 coisa a pedir atenção' : $todo . ' coisas a pedir atenção') : 'nada a pedir atenção' ?></p>

<?php if ($today): ?>
<?php if ($todayOpen): ?>
<div class="bar" style="margin:0 0 10px"><h2 class="eyebrow" style="margin:0">O teu dia</h2>
  <form method="post" style="margin-left:auto"><?= csrf_field() ?><input type="hidden" name="do" value="today_open"><button class="btn ghost sm" type="submit">Mostrar compacto</button></form></div>
<section class="today" aria-label="O teu dia">
  <?php foreach ($today as [$key, $title, $tip, $items, $more, $none]): ?>
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
<?php else: ?>
<section class="tbar" aria-label="O teu dia">
  <span class="tbar-k">O teu dia</span>
  <?php foreach ($today as $i => [$key, $title, $tip, $items, $more, $none]):
      $bad = (bool)array_filter($items, fn($it) => $it['sev'] === 'bad'); ?>
    <a class="tchip<?= $items ? ($bad ? ' bad' : ' warn') : ' zero' ?>" href="<?= e($more) ?>" data-panel="today:<?= $i ?>" data-tip="<?= e($tip . ' · abre o detalhe aqui ao lado') ?>">
      <span><?= e($title) ?></span><b><?= count($items) ?></b></a>
  <?php endforeach; ?>
  <form method="post" class="tbar-pref"><?= csrf_field() ?><input type="hidden" name="do" value="today_open"><input type="hidden" name="open" value="1">
    <button class="btn ghost sm" type="submit" data-tip="Preferência sua: abrir sempre o detalhe no Dashboard (também no Perfil)">Abrir expandido</button></form>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($exec):
    $per = exec_period((string)($_GET['p'] ?? 'mes'));
    $reading = exec_reading($pdo);
    $health = exec_health($pdo, $money);
    $deals = metrics_deals($pdo);
?>
<?php // ── 2. Leitura do mês ── ?>
<section class="xread" aria-labelledby="h-read">
  <div class="xread-h"><h2 id="h-read" class="eyebrow">Leitura do mês</h2>
    <?php if ($reading): ?><span class="muted small"><?= $reading['day'] === today_lisbon() ? 'escrita hoje às ' . e(date('H:i', strtotime($reading['created_at']))) : 'de ' . e(date('d/m', strtotime($reading['day']))) ?>
      <?= mx_ii(((int)$reading['by_ai'] ? 'Escrita pela IA de manhã' : 'Escrita de manhã (sem IA, com frases fixas)') . ' só com os números da fotografia do dia — nunca inventa números: um número que não esteja na fotografia faz cair o texto da IA. Sem acesso ao Financeiro, sai sem euros.') ?></span><?php endif; ?>
    <?php if ($admin): ?><form method="post" class="xread-now"><?= csrf_field() ?><input type="hidden" name="do" value="reading_now"><button class="btn ghost sm" type="submit">Escrever agora</button></form><?php endif; ?>
  </div>
  <p class="xread-p"><?= $reading ? e($money ? $reading['body_fin'] : $reading['body']) : '<span class="muted">A leitura do mês é escrita todas as manhãs a partir da fotografia do dia.</span>' ?></p>
</section>

<?php // ── 3. Saúde da empresa ── ?>
<section class="xsec" aria-labelledby="h-health">
  <h2 id="h-health" class="xh">Saúde da empresa <?= mx_ii('Quatro semáforos com regras fixas e limites editáveis em Definições › Geral › Dashboard executivo. Cada um diz a razão e abre o relatório que a explica.') ?></h2>
  <div class="xlights"><?php foreach ($health as $h) echo exec_light_html($h); ?></div>
</section>

<?php // ── 4. Resultados (P&L) — só Financeiro ── ?>
<?php if ($money):
    $pl = exec_pl($pdo, $per['months']);
    $pv = exec_pl($pdo, $per['prev']);
    $yo = $per['yoy'] ? exec_pl($pdo, $per['yoy']) : null;
    $sp = fn(string $k) => exec_series($pdo, $k);
    $snapAt = exec_snap_at($pdo);
    $cards = [
        ['k' => 'Revenue', 'info' => 'Faturado sem IVA, pela data da fatura (notas de crédito abatem). Antes das faturas, os negócios contam no mês do trabalho.', 'now' => $pl['revenue'], 'prev' => $pv['revenue'], 'yoy' => $yo['revenue'] ?? null,
         'ids' => $pl['ids']['rev'], 'title' => 'Revenue · ' . $per['label'], 'fallback' => '/admin/financeiro.php?tab=receber', 'spark' => $sp('revenue'), 'class' => 'xk-rev',
         'note' => 'Recebido no período: ' . e(eur0($pl['received']))],
        ['k' => 'Gross Profit', 'info' => 'Revenue − custos diretos dos negócios: despesas com âmbito «Negócio» e das categorias do tipo «Custo direto», custos da equipa da altura da folha, reservas dos sócios e o tempo das pessoas declaradas na Orçamentação (horas × custo/hora interno).',
         'now' => $pl['gp'], 'prev' => $pv['gp'], 'yoy' => $yo['gp'] ?? null, 'ids' => array_merge($pl['ids']['rev'], $pl['ids']['direct']), 'title' => 'Revenue e custos diretos · ' . $per['label'],
         'spark' => $sp('gp'), 'margin' => $pl['gm'], 'margin_prev' => $pv['gm'], 'margin_label' => 'gross margin', 'class' => 'xk-gp',
         'note' => 'Custos diretos ' . e(eur0($pl['direct'])) . ($pl['direct_parts']['team'] > 0 ? ' · equipa interna ' . e(eur0($pl['direct_parts']['team'])) : '')],
        ['k' => 'EBITDA', 'info' => 'Gross Profit − custos operacionais da empresa: despesas das categorias do tipo «Operacional» e salários com encargos (processados ou estimados pela ficha), menos o tempo já imputado aos negócios.',
         'now' => $pl['ebitda'], 'prev' => $pv['ebitda'], 'yoy' => $yo['ebitda'] ?? null, 'ids' => array_merge($pl['ids']['opex'], $pl['ids']['sal']), 'title' => 'Custos operacionais · ' . $per['label'],
         'fallback' => '/admin/financeiro.php?tab=pagar', 'spark' => $sp('ebitda'), 'margin' => $pl['ebitda_m'], 'margin_prev' => $pv['ebitda_m'], 'margin_label' => 'margem EBITDA', 'class' => 'xk-ebitda',
         'note' => 'Operacionais ' . e(eur0($pl['opex'])) . ' · salários ' . e(eur0($pl['salaries'])) . ($pl['imputed'] > 0 ? ' (−' . e(eur0($pl['imputed'])) . ' nos negócios)' : '')],
        ['k' => 'Operating Profit (EBIT)', 'info' => 'EBITDA − amortizações e depreciações: o registo de ativos (valor × taxa anual ÷ 12, a partir do mês de aquisição) e as despesas do tipo «Amortização».',
         'now' => $pl['ebit'], 'prev' => $pv['ebit'], 'yoy' => $yo['ebit'] ?? null, 'ids' => $pl['ids']['da'], 'title' => 'Amortizações · ' . $per['label'], 'fallback' => '/admin/financeiro.php?tab=ativos',
         'spark' => $sp('ebit'), 'margin' => $pl['om'], 'margin_prev' => $pv['om'], 'margin_label' => 'operating margin', 'class' => 'xk-ebit', 'note' => 'Amortizações ' . e(eur0($pl['da']))],
        ['k' => 'Net Profit', 'info' => 'EBIT − encargos financeiros (empréstimos e leasings registados, despesas do tipo «Financeiro») − IRC estimado sobre o resultado do ano (taxa PME, taxa geral e derrama dos parâmetros) − tributação autónoma das viaturas. Estimativa até o contabilista fechar o ano.',
         'now' => $pl['net'], 'prev' => $pv['net'], 'yoy' => $yo['net'] ?? null, 'ids' => $pl['ids']['fin'], 'title' => 'Encargos financeiros · ' . $per['label'], 'fallback' => '/admin/financeiro.php?tab=impostos',
         'spark' => $sp('net'), 'margin' => $pl['nm'], 'margin_prev' => $pv['nm'], 'margin_label' => 'net margin', 'class' => 'xk-net',
         'note' => '<span class="chip wait">estimativa</span> juros ' . e(eur0($pl['interest'])) . ' · IRC ' . e(exec_eur($pl['irc'])) . ' · TA ' . e(eur0($pl['ta']))],
    ];
    // indicadores macro pequenos
    require_once __DIR__ . '/inc/fin_cash.php';
    $rw = fin_runway($pdo);
    $recv = fin_receivables($pdo);
    $late = array_filter($recv, fn($r) => $r['age'] > 0);
    $cl = exec_clients($pdo, $per['from'], $per['to']);
    $team = exec_team_size($pdo);
    $conc = exec_set($pdo, 'exec_conc_alert');
    $rev12 = exec_series($pdo, 'revenue'); $gm12 = exec_series($pdo, 'gm');
?>
<section class="xsec" id="resultados" aria-labelledby="h-pl">
  <div class="xh-bar"><h2 id="h-pl" class="xh">Resultados <span class="muted">· <?= e($per['label']) ?></span></h2>
    <nav class="views" aria-label="Período">
      <?php foreach (EXEC_PERIODS as $k => $l): ?><a class="<?= $per['p'] === $k ? 'on' : '' ?>" href="?p=<?= $k ?>#resultados"><?= e($l) ?></a><?php endforeach; ?>
    </nav>
  </div>
  <div class="small muted xh-sub">Comparado com <?= e($per['prev_label']) ?><?= $per['yoy_label'] !== '' ? ' e com ' . e($per['yoy_label']) : '' ?> (▲▼).
    Fotografia de <?= $snapAt ? e(date('d/m H:i', strtotime($snapAt))) : '—' ?> <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="snap_now"><button class="lnk" type="submit">atualizar agora</button></form></div>
  <div class="stats xkpis"><?php foreach ($cards as $c) echo exec_kpi_card($c, $per); ?></div>
  <?php
  $warn = [];
  if ($pl['est']) $warn[] = 'Salários estimados pela ficha de ' . $pl['est'] . ($pl['est'] === 1 ? ' pessoa' : ' pessoas') . ' (sem processamento no mês) — <a href="/admin/financeiro.php?tab=salarios">Salários</a>.';
  if ($pl['capped']) $warn[] = 'O tempo declarado na Orçamentação passa o custo salarial de algum mês: ficou limitado ao custo real (' . e(eur0($pl['imputed'])) . ' de ' . e(eur0($pl['imputed_raw'])) . ').';
  if ($warn): ?><p class="small xwarn"><?= implode(' ', $warn) ?></p><?php endif; ?>
  <div class="flow-grid xcharts">
    <div class="flow"><h3>Do Revenue ao Net Profit</h3><p class="lg"><span><i class="box" style="background:var(--c-real)"></i>Totais</span><span><i class="box" style="background:var(--c-goal)"></i>Custos</span></p>
      <?= chart_waterfall($pl, 'Cascata do resultado de ' . $per['label']) ?></div>
    <div class="flow"><h3>Revenue por mês e gross margin</h3><p class="lg"><span><i class="box" style="background:var(--c-real)"></i>Revenue</span><span><i style="background:var(--c-goal)"></i>Gross margin % (escala própria, 0–100%)</span></p>
      <?= chart_rev_margin($rev12, $gm12, 'Revenue dos últimos 12 meses com a gross margin') ?></div>
  </div>
  <div class="xminis">
    <a class="xmini" href="/admin/financeiro.php#runway"><span class="k">Runway útil</span><b><?= $rw['useful'] === null ? '—' : e(fin_num(max(0, (float)$rw['useful']), 1)) . ' meses' ?></b><small><?= $rw['useful'] === null ? 'sem custos fixos' : ((float)$rw['useful'] <= 0 ? 'dentro da reserva' : 'reserva de ' . e(fin_num($rw['reserve_months'], 0)) . ' meses à parte') ?></small></a>
    <a class="xmini" href="/admin/financeiro.php?tab=tesouraria"><span class="k">Saldo disponível</span><b><?= e(exec_eur($rw['cash']['available'])) ?></b><small>contas − compromissos do mês − reservas</small></a>
    <a class="xmini<?= $late ? ' bad' : '' ?>" href="/admin/financeiro.php?tab=receber&amp;aging=1-30"><span class="k">Por receber em atraso</span><b><?= e(eur0(array_sum(array_column($late, 'gross')))) ?></b><small><?= count($late) ?> <?= count($late) === 1 ? 'fatura' : 'faturas' ?></small></a>
    <div class="xmini"><span class="k">Receita por pessoa <?= mx_ii('Revenue do período ÷ pessoas com conta ativa na plataforma.') ?></span><b><?= e(eur0($pl['revenue'] / $team)) ?></b><small><?= $team ?> <?= $team === 1 ? 'pessoa' : 'pessoas' ?></small></div>
    <div class="xmini<?= $cl['top3_pct'] !== null && $cl['top3_pct'] > $conc ? ' bad' : '' ?>"><span class="k">Concentração <?= mx_ii('% da receita do período que vem dos 3 maiores clientes. Alerta acima de ' . exec_pct($conc) . ' (Definições).') ?></span><b><?= e(exec_pct($cl['top3_pct'])) ?></b>
      <small><?= $cl['top3'] ? e(implode(' · ', array_map(fn($c) => $c['name'], $cl['top3']))) : 'sem receita no período' ?></small></div>
    <div class="xmini"><span class="k">Clientes recorrentes <?= mx_ii('% da receita do período de clientes que já tinham comprado antes.') ?></span><b><?= e(exec_pct($cl['recurring_pct'])) ?></b><small><?= $cl['n'] ?> <?= $cl['n'] === 1 ? 'cliente' : 'clientes' ?> no período</small></div>
  </div>
</section>
<?php endif; ?>

<?php // ── 5. Crescimento e previsão ── ?>
<section class="xsec" aria-labelledby="h-grow">
  <h2 id="h-grow" class="xh">Crescimento e previsão</h2>
  <?php
  $fromP = $per['from']; $toP = $per['to'];
  $pf = $per['prev'][0] . '-01'; $pt = date('Y-m-d', strtotime(end($per['prev']) . '-01 +1 month'));
  $fin = mx_fin($pdo, $deals, $fromP, $toP, $pf, $pt);
  $wonP = array_filter($deals, fn($d) => $d['end'] === 'won' && mx_in($d['won_at'], $fromP, $toP));
  $fu = []; $sv = [];
  if ($money) {
      foreach ($fin['funnel'] as $k => $r) $fu[$k] = [max(0, $r['gp']), $r['ids']];
      foreach ($fin['service'] as $k => $r) $sv[$k] = [max(0, $r['gp']), $r['ids']];
  } else {
      $fnames = $pdo->query('SELECT id, name FROM funnels')->fetchAll(PDO::FETCH_KEY_PAIR);
      foreach ($wonP as $d) { $k = $fnames[$d['fu']] ?? 'Sem funil'; $fu[$k] ??= [0, []]; $fu[$k][0]++; $fu[$k][1][] = $d['id']; }
      foreach ($fin['service'] as $k => $r) $sv[$k] = [count(array_unique($r['ids'])), $r['ids']];
  }
  ?>
  <div class="flow-grid xcharts">
  <?php if ($money):
      $fc = exec_forecast($pdo);
      $months12 = array_keys($rev12);
      $gp12 = exec_series($pdo, 'gp');
      $ma = fin_ma(array_values($rev12), 3);
      $maS = array_combine($months12, array_map(fn($v) => round($v, 2), $ma)); ?>
    <div class="flow"><h3>Revenue e Gross Profit · 12 meses</h3>
      <p class="lg"><span><i style="background:var(--c-real)"></i>Revenue</span><span><i style="background:var(--coral)"></i>Gross Profit</span><span><i class="dash"></i>Tendência (média de 3 meses)</span></p>
      <?= chart_lines($months12, [['Revenue', $rev12, ''], ['Gross Profit', $gp12, 'gp'], ['Média de 3 meses', $maS, 'prev']], fn($v) => eur_compact((float)$v), 'Revenue e gross profit dos últimos 12 meses') ?></div>
    <div class="flow"><h3>Previsão até ao fim de <?= $fc['year'] ?> <?= mx_ii('Faturado até hoje + ganho por faturar (backlog) + pipeline ponderado (fotografia de ' . ($fc['snap_day'] ? date('d/m', strtotime($fc['snap_day'])) : '—') . ') + tendência (o que a tendência da receita espera para os ' . $fc['months_left'] . ' meses que faltam, para além do que já se conhece). Banda pessimista/otimista com os fatores da tendência dos parâmetros.') ?></h3>
      <p class="xfc-big"><b><?= e(eur0($fc['total'])) ?></b> <span class="muted">entre <?= e(eur0($fc['pess'])) ?> e <?= e(eur0($fc['opt'])) ?></span></p>
      <?= exec_forecast_html($fc) ?>
      <p class="small"><?php if ($fc['goal'] > 0): ?><?= $fc['gap'] > 0 ? 'Faltam <b>' . e(eur0($fc['gap'])) . '</b> para a meta de ' . e(eur0($fc['goal'])) . '.' : 'Acima da meta de ' . e(eur0($fc['goal'])) . ' em <b>' . e(eur0(-$fc['gap'])) . '</b>.' ?>
        <?php else: ?><span class="muted">Sem meta anual<?= $admin ? ' — defina-a em <a href="/admin/definicoes.php?t=geral#set-meta_anual_' . $fc['year'] . '">Definições</a>' : '' ?>.</span><?php endif; ?>
        · <?= exec_drill($fc['backlog_n'] . ($fc['backlog_n'] === 1 ? ' negócio ganho por faturar' : ' negócios ganhos por faturar'), $fc['backlog_ids'], 'Ganhos por faturar') ?></p>
    </div>
  <?php else:
      $y0 = date('Y') . '-01-01';
      $wonY = array_filter($deals, fn($d) => $d['end'] === 'won' && $d['won_at'] >= $y0);
      $kM = array_filter($deals, fn($d) => $d['end'] === 'won' && mx_in($d['won_at'], date('Y-m-01'), date('Y-m-d', strtotime(date('Y-m-01') . ' +1 month'))));
      $openP = array_filter($deals, fn($d) => $d['end'] === 'open' && $d['phase'] === 'pipeline'); ?>
    <div class="stats xcounts">
      <div class="stat"><div class="k">Negócios ganhos em <?= date('Y') ?></div><div class="v"><?= exec_drill((string)count($wonY), array_keys($wonY), 'Ganhos em ' . date('Y')) ?></div></div>
      <div class="stat"><div class="k">Kickoffs este mês</div><div class="v"><?= exec_drill((string)count($kM), array_keys($kM), 'Kickoffs deste mês') ?></div></div>
      <div class="stat"><div class="k">Em pipeline</div><div class="v"><?= exec_drill((string)count($openP), array_keys($openP), 'Em pipeline') ?></div></div>
    </div>
  <?php endif; ?>
    <div class="flow"><h3><?= $money ? 'De onde vem o gross profit' : 'De onde vêm os negócios ganhos' ?> <span class="muted small">· <?= e($per['label']) ?></span></h3>
      <h4 class="xmix-h">Por funil</h4><?= chart_stack100($fu, 'Mix por funil', $money ? fn($v) => eur0($v) : fn($v) => $v . ($v == 1 ? ' negócio' : ' negócios')) ?>
      <h4 class="xmix-h">Por serviço</h4><?= chart_stack100($sv, 'Mix por serviço', $money ? fn($v) => eur0($v) : fn($v) => $v . ($v == 1 ? ' negócio' : ' negócios')) ?></div>
  </div>
</section>

<?php // ── 6. Hall of fame ──
    $rank = exec_ranking($pdo);
    $top = array_slice($rank, 0, 3);
    $cur = exec_ym();
    $mBest = exec_ranking($pdo, $cur)[0] ?? null;
    $lBest = exec_ranking($pdo, exec_ym_add($cur, -1))[0] ?? null;
    $record = $top[0] ?? null;
    $fresh = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM exec_hof WHERE kind IN ('enter','record') AND created_at >= " . $pdo->quote(date('Y-m-d H:i:s', time() - 3 * 86400)))->fetchColumn();
    $seller = $pdo->query("SELECT * FROM exec_hof WHERE kind = 'seller' ORDER BY ym DESC LIMIT 1")->fetch() ?: null;
    $prod = $pdo->query("SELECT * FROM exec_hof WHERE kind = 'production' ORDER BY ym DESC LIMIT 1")->fetch() ?: null;
    $live = exec_sellers($pdo, $cur);
    $lead = array_values(array_filter($live['ranking'], fn($r) => $r['ok']))[0] ?? null;
    $hist = exec_hof_history($pdo, 12);
    require_once __DIR__ . '/inc/team.php';
    $uRow = function (int $id) use ($pdo): ?array { $st = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null; };
?>
<section class="xsec" id="hall" aria-labelledby="h-hall">
  <h2 id="h-hall" class="xh">Hall of fame <?= mx_ii('Os 3 negócios de sempre com mais gross profit (valor − custos − tempo da equipa). Conta o real depois da entrega; até lá aparece como «provisório». Quando um negócio entra no pódio ou bate o recorde, vai um aviso ao chat da equipa.') ?></h2>
  <div class="xhall">
    <div class="xhall-pod"><?= exec_podium_html($pdo, $top, $money, $fresh) ?></div>
    <div class="xhall-side">
      <div class="xcard xrec"><span class="k">Negócio do mês · recorde a bater</span>
        <?php if ($mBest): ?>
          <a class="xrec-n" href="<?= e(mx_url($deals[$mBest['id']])) ?>"><span class="mono"><?= e($mBest['code']) ?></span> <?= e($mBest['name']) ?></a>
          <?php if ($money): ?><b class="xrec-v"><?= e(eur0($mBest['gp'])) ?></b><?php endif; ?>
          <?php $pc = fn(?array $r) => $r && $r['gp'] > 0 ? (int)round($mBest['gp'] / $r['gp'] * 100) : null; ?>
          <div class="xrec-bars">
            <?php foreach ([['do 1.º lugar de ' . exec_month_label(exec_ym_add($cur, -1)), $pc($lBest)], ['do recorde de sempre', $pc($record)]] as [$l, $v]): if ($v === null) continue; ?>
              <div class="xrb"><span>a <b><?= $v ?>%</b> <?= e($l) ?></span><span class="tr"><i style="width:<?= min(100, $v) ?>%"></i></span></div>
            <?php endforeach; ?>
          </div>
          <?php if ($mBest['provisional']): ?><span class="chip wait">provisório</span><?php endif; ?>
        <?php else: ?><p class="muted small">Ainda nenhum kickoff em <?= e(exec_month_label($cur)) ?>.</p><?php endif; ?>
      </div>
      <div class="xcard xseller"><span class="k">Comercial do mês <?= mx_ii('A melhor conversão pipeline → kickoff do mês, com pelo menos ' . (int)exec_set($pdo, 'exec_seller_min') . ' negócios em pipeline (Definições). Desempate pelo gross profit ganho e depois pelo tempo de primeira resposta. Anunciado no chat no dia 1 do mês seguinte.') ?></span>
        <?php if ($seller && ($u = $uRow((int)$seller['user_id']))): $sd = json_decode((string)$seller['data'], true) ?: []; ?>
          <div class="xperson"><?= team_avatar($u, 'lg', false) ?><div><b><?= e($u['display_name'] ?: $u['name']) ?></b><span class="muted small"><?= e(exec_month_label($seller['ym']) . ' de ' . substr($seller['ym'], 0, 4)) ?></span>
            <span><b><?= e(exec_pct($sd['rate'] ?? null)) ?></b> de conversão · <?= (int)($sd['k'] ?? 0) ?> kickoffs de <?= (int)($sd['n'] ?? 0) ?></span>
            <span class="muted small">média da equipa <?= e(exec_pct($sd['avg'] ?? null)) ?></span></div></div>
        <?php else: ?><p class="muted small">Anunciado no dia 1 de cada mês.</p><?php endif; ?>
        <?php if ($lead): ?><p class="small xlive">Este mês, até agora: <b><?= e(exec_person($pdo, $lead['user'])) ?></b> com <?= e(exec_pct($lead['rate'])) ?> (<?= $lead['k'] ?> de <?= $lead['n'] ?>)</p><?php endif; ?>
      </div>
      <div class="xcard xprod"><span class="k">Destaque de produção <?= mx_ii('A melhor % de entregas no prazo do mês e, a seguir, de peças aprovadas à 1.ª ronda no Review, por responsável de execução.') ?></span>
        <?php if ($prod && ($u = $uRow((int)$prod['user_id']))): $pd = json_decode((string)$prod['data'], true) ?: []; ?>
          <div class="xperson"><?= team_avatar($u, 'lg', false) ?><div><b><?= e($u['display_name'] ?: $u['name']) ?></b><span class="muted small"><?= e(exec_month_label($prod['ym']) . ' de ' . substr($prod['ym'], 0, 4)) ?></span>
            <span><b><?= e(exec_pct($pd['ontime'] ?? null)) ?></b> entregas no prazo<?= ($pd['first'] ?? null) !== null ? ' · <b>' . e(exec_pct($pd['first'])) . '</b> à 1.ª ronda' : '' ?></span></div></div>
        <?php else: ?><p class="muted small">Anunciado no dia 1 de cada mês.</p><?php endif; ?>
      </div>
    </div>
  </div>
  <?php if ($hist): ?>
  <details class="xhist"><summary>Histórico do hall of fame</summary>
    <div class="tablewrap"><table><thead><tr><th>Mês</th><th>Pódio no fim do mês</th><th>Negócio do mês</th><th>Comercial do mês</th><th>Produção</th><th>Entradas e recordes</th></tr></thead><tbody>
    <?php foreach ($hist as $ym => $k): ?>
      <tr><td><?= e(ucfirst(exec_month_label($ym)) . ' ' . substr($ym, 0, 4)) ?></td>
        <td><?= e(implode(' · ', array_map(fn($r) => $r['rank_no'] . '.º ' . ($r['data']['code'] ?? ''), $k['podium'] ?? []))) ?: '—' ?></td>
        <td><?= isset($k['deal_month'][0]) ? e(($k['deal_month'][0]['data']['code'] ?? '') . ' · ' . ($k['deal_month'][0]['data']['name'] ?? '')) . ($money ? ' · ' . e(eur0((float)$k['deal_month'][0]['value'])) : '') : '—' ?></td>
        <td><?= isset($k['seller'][0]) ? e(exec_person($pdo, (int)$k['seller'][0]['user_id'])) : '—' ?></td>
        <td><?= isset($k['production'][0]) ? e(exec_person($pdo, (int)$k['production'][0]['user_id'])) : '—' ?></td>
        <td class="small"><?= e(implode(' · ', array_map(fn($r) => ($r['kind'] === 'record' ? 'Recorde: ' : $r['rank_no'] . '.º: ') . ($r['data']['code'] ?? ''), array_merge($k['record'] ?? [], $k['enter'] ?? [])))) ?: '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </details>
  <?php endif; ?>
</section>
<?php endif; // $exec ?>

<?php
// ── 7. Comercial: lido da fotografia diária dos Relatórios (a mesma camada de métricas, inc/metrics.php) ──
if (can('relatorios') && metrics_ready($pdo)):
    $snapDay = (string)$pdo->query("SELECT MAX(day) FROM metrics_daily WHERE scope = ''")->fetchColumn();
    $snap = $snapDay !== '' ? array_map('floatval', $pdo->query("SELECT mkey, value FROM metrics_daily WHERE scope = '' AND day = " . $pdo->quote($snapDay))->fetchAll(PDO::FETCH_KEY_PAIR)) : [];
    $wk = $snapDay !== '' ? metrics_series($pdo, 'pipeline_ponderado', (new DateTimeImmutable($snapDay))->modify('-29 days')->format('Y-m-d'), (new DateTimeImmutable($snapDay))->modify('+1 day')->format('Y-m-d')) : [];
    $gs = mx_goals_status($pdo, metrics_deals($pdo), $money);
    $gs = array_filter($gs, fn($g) => $g['scope'] !== 'user');
    $gc = ['ok' => 0, 'risk' => 0, 'behind' => 0];
    foreach ($gs as $g) $gc[in_array($g['state'], ['done', 'ok'], true) ? 'ok' : ($g['state'] === 'risk' ? 'risk' : 'behind')]++;
    $att = mx_attention_count($pdo); ?>
<div class="bar" style="margin:22px 0 10px"><h2 class="xh" style="margin:0">Comercial</h2><a class="lnk" style="margin-left:auto" href="/admin/relatorios.php">Relatórios →</a></div>
<div class="stats">
  <?php if ($money && $snap): ?>
  <a class="stat" href="/admin/relatorios.php?tab=funil#valor"><div class="k">Pipeline ponderado</div><div class="v"><?= eur0($snap['pipeline_ponderado'] ?? 0) ?></div><div class="n">de <?= eur0($snap['pipeline_aberto'] ?? 0) ?> em aberto · fotografia de <?= e(date('d/m', strtotime($snapDay))) ?></div><?= chart_spark(array_values($wk), 'Pipeline ponderado, 30 dias') ?></a>
  <?php endif; ?>
  <a class="stat" href="/admin/relatorios.php?tab=metas"><div class="k">Metas do período</div><div class="v"><?= count($gs) ? $gc['ok'] . '/' . count($gs) : '—' ?></div><div class="n"><?= count($gs) ? 'em linha · ' . $gc['risk'] . ' em risco · ' . $gc['behind'] . ' atrás' : 'sem metas definidas' ?></div></a>
  <?php if ($snap): ?>
  <a class="stat" href="/admin/relatorios.php?tab=velocidade"><div class="k">Respostas no prazo (30 dias)</div><div class="v"><?= (int)round($snap['sla_30d'] ?? 0) ?>%</div><div class="n">mediana <?= e(mx_h($snap['resposta_mediana_30d'] ?? null)) ?> úteis</div></a>
  <?php endif; ?>
  <a class="stat" href="/admin/relatorios.php"><div class="k">Pedem atenção</div><div class="v"><?= $att ?></div><div class="n">no separador Atenção dos Relatórios</div></a>
</div>
<?php endif; ?>
<?php if ($hot): ?>
<div class="bar" style="margin:22px 0 10px"><h2 class="xh" style="margin:0">Pedem atenção agora</h2><?php if (can('relatorios')): ?><a class="lnk" style="margin-left:auto" href="/admin/relatorios.php?tab=atencao">Ver tudo em Atenção →</a><?php endif; ?></div>
<div class="hot">
  <?php foreach ($hot as $h): $d = $h['d']; ?>
    <a class="<?= $h['risk'] ? 'risk' : '' ?>" href="<?= e($h['url'] ?? deal_url($d)) ?>">
      <span class="id"><span><?= e($d['code']) ?></span><span><?= e($d['stage'] ?? '') ?></span></span>
      <b><?= e($d['name']) ?></b>
      <span class="row"><span><?= e($h['why']) ?></span><span class="mono"><?= $money && $h['val'] !== null ? eur0((float)$h['val']) : '' ?></span></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="mdl" id="mdl-mx" role="dialog" aria-modal="true" aria-label="Registos" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <div class="mdl-card wide mx-mdl">
    <div class="mx-mdl-body"></div>
    <div class="actions"><button class="btn ghost" type="button" data-mdl-close>Fechar</button></div>
  </div>
</div>
<script src="<?= asset_url('reports.js') ?>" defer></script>
<script src="<?= asset_url('dash.js') ?>" defer></script>
<?php render_footer(); ?>
