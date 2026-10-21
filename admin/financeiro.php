<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/paginate.php';
require_once __DIR__ . '/inc/finance.php';
require_once __DIR__ . '/inc/alerts.php';
require_once __DIR__ . '/inc/undo.php';
require_once __DIR__ . '/inc/billing.php';
require_once __DIR__ . '/inc/charts.php';
$user = require_login();
require_page('financeiro');

// Financeiro: o que estava em Carteira › Trabalhos, Custos e IVA, agora
// lido do MySQL e calculado pelo sistema (inc/finance.php).
// Separadores: Negócios · Por receber · Fluxo de caixa · Custos · IVA.
// «Por receber» junta todos os anos (com a antiguidade); os outros são de um ano.

const FIN_TABS = ['negocios' => 'Negócios', 'receber' => 'Por receber', 'fluxo' => 'Fluxo de caixa', 'custos' => 'Custos', 'iva' => 'IVA'];

$rate  = vat_rate($pdo);
$years = finance_years($pdo);
$year  = (int)($_GET['year'] ?? $_POST['year'] ?? $years[0]);
if (!in_array($year, $years, true)) $year = $years[0];
$tab   = isset(FIN_TABS[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'negocios';

function fin_url(array $q): string {
    return '/admin/financeiro.php?' . http_build_query($q);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do   = (string)($_POST['do'] ?? '');
    $back = fin_url(array_filter(['year' => $year, 'tab' => (string)($_POST['tab'] ?? 'negocios'),
                                  'doc' => (int)($_POST['doc'] ?? 0) ?: null, 'aging' => (string)($_POST['aging'] ?? '') ?: null]));

    if ($do === 'toggle_paid') {
        $st = $pdo->prepare('SELECT code, is_paid FROM deals WHERE id = ? AND code IS NOT NULL');
        $st->execute([(int)($_POST['id'] ?? 0)]);
        if ($d = $st->fetch()) {
            $pdo->prepare("UPDATE deals SET is_paid = 1 - is_paid WHERE id = ? AND code IS NOT NULL")->execute([(int)$_POST['id']]);
            flash($d['code'] . ((int)$d['is_paid'] ? ' voltou a estar por pagar.' : ' marcado como pago.'), 'ok',
                  undo_push('deal_paid', ['id' => (int)$_POST['id'], 'from' => (int)$d['is_paid']]));
        }
        redirect($back);
    }
    if ($do === 'goal') {
        require_perm('financeiro', PERM_MANAGE);
        $key = 'meta_anual_' . $year;
        $pdo->prepare(db_is_sqlite($pdo)
            ? 'INSERT OR REPLACE INTO settings (skey, svalue) VALUES (?, ?)'
            : 'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)'
        )->execute([$key, (string)money_in($_POST['goal'] ?? '0')]);
        flash('Meta de ' . $year . ' guardada.');
        redirect(fin_url(['year' => $year, 'tab' => 'fluxo']));
    }
    if ($do === 'add_cost' || $do === 'save_cost') {
        $c = cost_input($_POST);
        if ($c['name'] === '') { flash('Indique o nome do custo.', 'err'); redirect($back); }
        $m = (int)($_POST['month'] ?? 0); $m = $m >= 1 && $m <= 12 ? $m : null;
        $y = (int)($_POST['cost_year'] ?? $year);
        if ($do === 'add_cost') {
            $pdo->prepare('INSERT INTO costs (deal_id, year, month, name, amount, vat_mode, is_closed, notes, created_by) VALUES (NULL,?,?,?,?,?,?,?,?)')
                ->execute([$y, $m, $c['name'], $c['amount'], $c['vat_mode'], $c['is_closed'], $c['notes'], (int)$user['id']]);
            flash('Custo da empresa adicionado.');
        } else {
            $pdo->prepare('UPDATE costs SET year=?, month=?, name=?, amount=?, vat_mode=?, is_closed=?, notes=? WHERE id=? AND deal_id IS NULL')
                ->execute([$y, $m, $c['name'], $c['amount'], $c['vat_mode'], $c['is_closed'], $c['notes'], (int)($_POST['cost_id'] ?? 0)]);
            flash('Custo guardado.');
        }
        redirect(fin_url(['year' => $y, 'tab' => 'custos']));
    }
    if ($do === 'set_vat') {
        $mode = (string)($_POST['vat_mode'] ?? '');
        if (isset(VAT_MODES[$mode])) $pdo->prepare('UPDATE costs SET vat_mode = ? WHERE id = ? AND deal_id IS NULL')->execute([$mode, (int)($_POST['cost_id'] ?? 0)]);
        redirect($back);
    }
    if ($do === 'toggle_cost') {
        $st = $pdo->prepare('SELECT is_closed FROM costs WHERE id = ? AND deal_id IS NULL');
        $st->execute([(int)($_POST['cost_id'] ?? 0)]);
        $was = $st->fetchColumn();
        if ($was !== false) {
            $pdo->prepare('UPDATE costs SET is_closed = 1 - is_closed WHERE id = ? AND deal_id IS NULL')->execute([(int)$_POST['cost_id']]);
            flash((int)$was ? 'Custo por pagar.' : 'Custo pago.', 'ok', undo_push('cost_closed', ['id' => (int)$_POST['cost_id'], 'from' => (int)$was]));
        }
        redirect($back);
    }
    if ($do === 'del_cost') {
        require_perm('financeiro', PERM_MANAGE);
        $pdo->prepare('DELETE FROM costs WHERE id = ? AND deal_id IS NULL')->execute([(int)($_POST['cost_id'] ?? 0)]);
        flash('Custo eliminado.');
        redirect($back);
    }
    redirect($back);
}

$t = finance_year($pdo, $year, $rate);
$canEdit = can('financeiro', PERM_EDIT);
$alertsInv = count(my_alerts($pdo)['invoice']);

render_header('Financeiro', 'financeiro');
render_flashes();
echo paginate_css();
?>
<h1>Financeiro</h1>

<?php if ($tab !== 'receber'): ?>
<form class="bar" method="get">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <select name="year" onchange="this.form.submit()" aria-label="Ano">
    <?php foreach ($years as $y): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
  </select>
  <noscript><button class="btn sm">Ver</button></noscript>
</form>
<?php endif; ?>

<div class="stats" style="margin-bottom:1.6rem">
  <div class="stat"><div class="k">Faturação s/ IVA</div><div class="v"><?= eur0($t['revenue']) ?></div><div class="n">c/ IVA <?= eur0($t['gross']) ?> · <?= $t['count'] ?> negócios</div></div>
  <div class="stat"><div class="k">Gastos</div><div class="v"><?= eur0($t['costs']) ?></div><div class="n"><?= $t['revenue'] > 0 ? round($t['costs'] / $t['revenue'] * 100) . '% da faturação' : '—' ?></div></div>
  <div class="stat hero <?= $t['profit'] < 0 ? 'neg' : '' ?>"><div class="k">Lucro antes de impostos</div><div class="v"><?= eur0($t['profit']) ?></div></div>
  <a class="stat<?= $alertsInv ? ' risk' : '' ?>" href="<?= e(fin_url(['tab' => 'receber'])) ?>"><div class="k">Por receber</div><div class="v"><?= eur0($t['unpaid']) ?></div><div class="n">s/ IVA<?= $alertsInv ? ' · ' . $alertsInv . ' em atraso' : '' ?></div></a>
</div>

<div class="tabs">
  <?php foreach (FIN_TABS as $k => $label): ?>
    <a href="<?= e(fin_url(['year' => $year, 'tab' => $k])) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'negocios'):
    $rows = year_deals($pdo, $year, $rate);
    $pg = paginate($rows, 'pt'); ?>
<div class="tablewrap"><table>
  <thead><tr><th>ID</th><th>Negócio</th><th>Mês</th><th class="num">s/ IVA</th><th class="num hide-sm">c/ IVA</th><th class="num">Gastos</th><th class="num">Lucro</th><th>Pago</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="empty">Sem negócios em <?= $year ?>.</td></tr><?php endif; ?>
  <?php foreach ($pg['rows'] as $d): $m = $d['m']; ?>
    <tr>
      <td class="nowrap"><a class="lnk id" href="/admin/negocio.php?id=<?= (int)$d['id'] ?>#financeiro"><?= e($d['code']) ?></a>
        <?= icon_button('eye', 'Pré-visualizar a cobrança de ' . $d['code'], ['href' => fin_url(['tab' => 'receber', 'doc' => (int)$d['id']]), 'class' => 'ibtn', 'style' => 'width:28px;height:28px;vertical-align:middle']) ?></td>
      <td><span class="b"><?= e($d['name']) ?></span><?= $d['job_type'] ? '<div class="muted" style="font-size:0.62rem">' . e($d['job_type']) . '</div>' : '' ?></td>
      <td class="nowrap"><?= e($d['month'] ? month_name((int)$d['month']) : '—') ?></td>
      <td class="num"><?= eur($m['budget_net']) ?></td>
      <td class="num hide-sm"><?= eur($m['budget_gross']) ?></td>
      <td class="num"><?= eur($m['expenses']) ?></td>
      <td class="num b"><?= eur($m['profit']) ?></td>
      <td><?php if ($canEdit): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_paid"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="tab" value="negocios">
          <label class="check"><input type="checkbox" onchange="this.form.submit()" <?= (int)$d['is_paid'] ? 'checked' : '' ?>> Pago</label></form>
      <?php else: ?><?= (int)$d['is_paid'] ? 'Pago' : 'Por pagar' ?><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?= page_nav($pg) ?>

<?php elseif ($tab === 'receber'):
    $recv = receivables($pdo);
    $buckets = array_fill_keys(array_keys(AGING), ['n' => 0, 'v' => 0.0]);
    foreach ($recv as &$r) { $r['bucket'] = aging_bucket($r['age']); $buckets[$r['bucket']]['n']++; $buckets[$r['bucket']]['v'] += $r['net']; }
    unset($r);
    $ag = isset(AGING[$_GET['aging'] ?? '']) ? (string)$_GET['aging'] : '';
    $list = $ag === '' ? $recv : array_values(array_filter($recv, fn($r) => $r['bucket'] === $ag));
    $docId = (int)($_GET['doc'] ?? 0);
    $doc = $docId ? billing_deal($pdo, $docId) : null;
    $limit = alert_limits($pdo)['invoice'];
?>
<p class="muted" style="font-size:12.5px;margin:-6px 0 12px">Negócios por pagar de todos os anos. A antiguidade conta a partir da conclusão ou, sem ela, do fim do mês do trabalho — ainda não há faturas na plataforma. Em atraso: mais de <?= $limit ?> dias.</p>
<div class="aging" role="list">
  <?php foreach (AGING as $k => $_): $b = $buckets[$k]; ?>
    <a role="listitem" class="<?= in_array($k, ['61-90', '90+'], true) && $b['n'] ? 'late' : '' ?><?= $ag === $k ? ' on' : '' ?>" href="<?= e(fin_url(array_filter(['tab' => 'receber', 'aging' => $ag === $k ? null : $k]))) ?>"<?= $ag === $k ? ' aria-current="true"' : '' ?>>
      <div class="k"><?= e($k) ?> DIAS</div><div class="v"><?= eur0($b['v']) ?></div><div class="n"><?= $b['n'] ?> negócio<?= $b['n'] === 1 ? '' : 's' ?> · s/ IVA</div></a>
  <?php endforeach; ?>
</div>
<div class="fin-split" style="margin-top:16px">
  <div class="tablewrap"><table>
    <thead><tr><th>ID</th><th>Negócio</th><th class="num">Dias</th><th class="num">s/ IVA</th><th class="num hide-sm">c/ IVA</th><th></th></tr></thead>
    <tbody>
    <?php if (!$list): ?><tr><td colspan="6" class="empty">Nada por receber<?= $ag ? ' nesta faixa' : '' ?>.</td></tr><?php endif; ?>
    <?php foreach ($list as $r): $rid = (int)$r['id']; ?>
      <tr class="<?= $docId === $rid ? 'sel' : '' ?> <?= $r['age'] > $limit ? 'stale' : '' ?>">
        <td class="nowrap"><a class="lnk id" href="<?= e(fin_url(array_filter(['tab' => 'receber', 'aging' => $ag ?: null, 'doc' => $rid]))) ?>"><?= e($r['code']) ?></a></td>
        <td><span class="b"><?= e($r['name']) ?></span><?= $r['contact_name'] ? '<div class="muted" style="font-size:0.62rem">' . e($r['contact_name']) . '</div>' : '' ?></td>
        <td class="num<?= $r['age'] > $limit ? ' neg' : '' ?>"><?= $r['age'] ?></td>
        <td class="num"><?= eur($r['net']) ?></td>
        <td class="num hide-sm"><?= eur($r['gross']) ?></td>
        <td class="num nowrap"><?= icon_button('eye', 'Pré-visualizar ' . $r['code'], ['href' => fin_url(array_filter(['tab' => 'receber', 'aging' => $ag ?: null, 'doc' => $rid]))]) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($doc): $docUrl = fin_url(['tab' => 'receber', 'doc' => (int)$doc['id']]); ?>
  <div class="doc-wrap">
    <?= billing_doc($pdo, $doc, $rate) ?>
    <div class="doc-actions">
      <a class="btn ghost sm" href="/admin/documento.php?id=<?= (int)$doc['id'] ?>&amp;dl=1"><?= icon('download') ?> Descarregar</a>
      <button type="button" class="btn ghost sm" data-copy="<?= e($docUrl) ?>"><?= icon('link') ?> Copiar link</button>
      <?php if ($canEdit): ?>
      <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_paid"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <input type="hidden" name="tab" value="receber"><input type="hidden" name="doc" value="<?= (int)$doc['id'] ?>"><input type="hidden" name="aging" value="<?= e($ag) ?>">
        <button class="btn sm" type="submit"><?= icon('check') ?> <?= (int)$doc['is_paid'] ? 'Marcar como por pagar' : 'Marcar como paga' ?></button></form>
      <?php endif; ?>
      <a class="btn ghost sm" href="/admin/documento.php?id=<?= (int)$doc['id'] ?>" target="_blank" rel="noopener"><?= icon('external') ?> Abrir para imprimir</a>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'fluxo'):
    $deals = year_deals($pdo, $year, $rate);
    $rev = array_fill(1, 12, 0.0); $out = array_fill(1, 12, 0.0); $noMonth = 0.0;
    foreach ($deals as $d) {
        if (!$d['month']) { $noMonth += $d['m']['budget_net']; continue; }
        $rev[(int)$d['month']] += $d['m']['budget_net'];
        $out[(int)$d['month']] += $d['m']['expenses'];
    }
    foreach (company_cost_rows($pdo, $rate, $year) as $c) if ($c['month']) $out[(int)$c['month']] += $c['net'];
    $goal = (float)setting($pdo, 'meta_anual_' . $year, 0);
    $cy = (int)date('Y');
    $upto = $year < $cy ? 12 : ($year === $cy ? (int)date('n') : 1);
    $done = array_sum(array_slice($rev, 0, $upto, true));
?>
<p class="muted" style="font-size:12.5px;margin:-6px 0 12px">Faturação s/ IVA pelo mês do trabalho (a plataforma ainda não guarda as datas de pagamento).
  <?= $goal > 0 ? 'Meta de ' . $year . ': <b>' . eur0($goal) . '</b> · ' . round($done / $goal * 100) . '% atingido.' : 'Ainda sem meta para ' . $year . '.' ?></p>
<div class="flow-grid">
  <figure class="flow" style="margin:0">
    <h3>Faturação por mês</h3>
    <div class="lg"><span><i class="box" style="background:var(--c-real)"></i>Faturação s/ IVA</span><?php if ($goal > 0): ?><span><i style="background:var(--c-goal)"></i>Meta do mês (<?= eur0($goal / 12) ?>)</span><?php endif; ?></div>
    <?= chart_months($rev, $goal / 12, $year === $cy ? (int)date('n') : 0, 'Faturação por mês em ' . $year) ?>
  </figure>
  <figure class="flow" style="margin:0">
    <h3>Acumulado contra a meta</h3>
    <div class="lg"><span><i style="background:var(--c-real)"></i>Acumulado</span><?php if ($goal > 0): ?><span><i style="background:var(--c-goal)"></i>Ritmo da meta</span><?php endif; ?></div>
    <?= chart_cumulative($rev, $goal, $upto, 'Faturação acumulada em ' . $year . ' contra a meta anual') ?>
  </figure>
</div>
<?php if ($noMonth > 0): ?><p class="muted" style="font-size:12px">Sem mês definido: <?= eur0($noMonth) ?> (fora dos gráficos).</p><?php endif; ?>
<details class="panel" style="margin-top:14px"><summary>Ver em tabela</summary>
  <div class="tablewrap" style="margin-top:12px"><table>
    <thead><tr><th>Mês</th><th class="num">Faturação s/ IVA</th><th class="num">Gastos</th><th class="num">Saldo</th><th class="num">Acumulado</th><?= $goal > 0 ? '<th class="num">Meta acumulada</th>' : '' ?></tr></thead>
    <tbody>
    <?php $acc = 0.0; for ($m = 1; $m <= 12; $m++): $acc += $rev[$m]; ?>
      <tr><td><?= e(month_name($m)) ?></td><td class="num"><?= eur0($rev[$m]) ?></td><td class="num"><?= eur0($out[$m]) ?></td>
        <td class="num <?= $rev[$m] - $out[$m] < 0 ? 'neg' : '' ?>"><?= eur0($rev[$m] - $out[$m]) ?></td><td class="num"><?= eur0($acc) ?></td>
        <?= $goal > 0 ? '<td class="num">' . eur0($goal * $m / 12) . '</td>' : '' ?></tr>
    <?php endfor; ?>
    </tbody>
  </table></div>
</details>
<?php if (can('financeiro', PERM_MANAGE)): ?>
<form class="bar" method="post" style="margin-top:14px">
  <?= csrf_field() ?><input type="hidden" name="do" value="goal"><input type="hidden" name="year" value="<?= $year ?>">
  <label for="goal" class="muted" style="font-size:12.5px">Meta anual de faturação para <?= $year ?> (€, s/ IVA)</label>
  <input type="text" id="goal" name="goal" inputmode="decimal" value="<?= $goal > 0 ? e(number_format($goal, 0, ',', '')) : '' ?>" style="flex:0 0 140px">
  <button class="btn sm ghost" type="submit">Guardar meta</button>
</form>
<?php endif; ?>

<?php elseif ($tab === 'custos'):
    $company = company_cost_rows($pdo, $rate, $year);
    $st = $pdo->prepare("SELECT c.*, d.code, d.name AS deal_name FROM costs c JOIN deals d ON d.id = c.deal_id
                          WHERE d.year = ? AND d.phase IN ('production','done') ORDER BY d.code DESC, c.id");
    $st->execute([$year]);
    $dealCosts = $st->fetchAll();
    foreach ($dealCosts as &$c) { $c['net'] = cost_net($c, $rate); $c['vat'] = cost_vat($c, $rate); } unset($c);
    $editId = (int)($_GET['edit'] ?? 0); $delId = (int)($_GET['del'] ?? 0);
    $ec = null; foreach ($company as $c) if ((int)$c['id'] === $editId) $ec = $c;
?>
<h2>Custos da empresa</h2>
<div class="tablewrap"><table>
  <thead><tr><th>Mês</th><th>Custo</th><th class="num">Valor</th><th>O valor é</th><th class="num hide-sm">s/ IVA</th><th class="num hide-sm">IVA</th><th>Pago</th><th></th></tr></thead>
  <tbody>
  <?php if (!$company): ?><tr><td colspan="8" class="empty">Sem custos da empresa em <?= $year ?>.</td></tr><?php endif; ?>
  <?php foreach ($company as $c): $cid = (int)$c['id']; ?>
    <tr class="<?= in_array($cid, [$editId, $delId], true) ? 'pending' : '' ?>">
      <td class="nowrap"><?= e($c['month'] ? month_name((int)$c['month']) : '—') ?></td>
      <td><?= e($c['name']) ?><?= $c['notes'] ? '<div class="muted" style="font-size:0.62rem">' . e(shorten($c['notes'], 50)) . '</div>' : '' ?></td>
      <td class="num"><?= eur((float)$c['amount']) ?></td>
      <td><?php if ($canEdit): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="set_vat"><input type="hidden" name="cost_id" value="<?= $cid ?>">
          <input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="tab" value="custos">
          <select class="mini" name="vat_mode" onchange="this.form.submit()" aria-label="O valor é">
            <?php foreach (VAT_MODES as $k => $l): ?><option value="<?= $k ?>" <?= $c['vat_mode'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
          </select></form>
      <?php else: ?><?= e(VAT_MODES[$c['vat_mode']] ?? '') ?><?php endif; ?></td>
      <td class="num hide-sm"><?= eur($c['net']) ?></td>
      <td class="num hide-sm"><?= eur($c['vat']) ?></td>
      <td><?php if ($canEdit): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_cost"><input type="hidden" name="cost_id" value="<?= $cid ?>">
          <input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="tab" value="custos">
          <label class="check"><input type="checkbox" onchange="this.form.submit()" <?= (int)$c['is_closed'] ? 'checked' : '' ?>> Pago</label></form>
      <?php else: ?><?= (int)$c['is_closed'] ? 'Pago' : '—' ?><?php endif; ?></td>
      <td class="num nowrap"><?php if ($canEdit): ?>
        <?php if ($delId === $cid && can('financeiro', PERM_MANAGE)): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="del_cost"><input type="hidden" name="cost_id" value="<?= $cid ?>">
            <input type="hidden" name="year" value="<?= $year ?>"><input type="hidden" name="tab" value="custos">
            <button class="btn danger sm" type="submit">Sim, eliminar</button></form>
          <a class="lnk" href="<?= e(fin_url(['year' => $year, 'tab' => 'custos'])) ?>">Cancelar</a>
        <?php else: ?>
          <a class="lnk" href="<?= e(fin_url(['year' => $year, 'tab' => 'custos', 'edit' => $cid])) ?>#custo">Editar</a>
          <?php if (can('financeiro', PERM_MANAGE)): ?><a class="lnk del" href="<?= e(fin_url(['year' => $year, 'tab' => 'custos', 'del' => $cid])) ?>">Eliminar</a><?php endif; ?>
        <?php endif; ?>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<?php if ($canEdit): $cv = $ec ?? ['name' => '', 'month' => (int)date('n'), 'year' => $year, 'amount' => '', 'vat_mode' => 'plus', 'is_closed' => 0, 'notes' => '']; ?>
<h2 id="custo"><?= $ec ? 'Editar custo da empresa' : 'Novo custo da empresa' ?></h2>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="<?= $ec ? 'save_cost' : 'add_cost' ?>"><input type="hidden" name="year" value="<?= $year ?>">
  <?php if ($ec): ?><input type="hidden" name="cost_id" value="<?= (int)$ec['id'] ?>"><?php endif; ?>
  <div class="grid">
    <div class="field"><label for="kn">Custo</label><input type="text" id="kn" name="name" required maxlength="160" value="<?= e($cv['name']) ?>" placeholder="Contabilidade"></div>
    <div class="field"><label for="km">Mês</label><select id="km" name="month"><option value="">—</option>
      <?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>" <?= (int)$cv['month'] === $i ? 'selected' : '' ?>><?= e(month_name($i)) ?></option><?php endfor; ?></select></div>
    <div class="field"><label for="ky">Ano</label><input type="number" id="ky" name="cost_year" min="2000" max="2100" value="<?= (int)$cv['year'] ?>"></div>
    <div class="field"><label for="ka">Valor (€)</label><input type="text" id="ka" name="amount" required inputmode="decimal" value="<?= $cv['amount'] !== '' ? e(number_format((float)$cv['amount'], 2, ',', '')) : '' ?>"></div>
    <div class="field"><label for="kv">O valor é</label><select id="kv" name="vat_mode">
      <?php foreach (VAT_MODES as $k => $l): ?><option value="<?= $k ?>" <?= $cv['vat_mode'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="check" style="margin-top:1.6rem"><input type="checkbox" name="is_closed" value="1" <?= (int)$cv['is_closed'] ? 'checked' : '' ?>> Pago</label></div>
    <div class="field wide"><label for="kno">Notas</label><input type="text" id="kno" name="notes" maxlength="2000" value="<?= e((string)$cv['notes']) ?>"></div>
  </div>
  <div class="actions"><button class="btn" type="submit"><?= $ec ? 'Guardar' : 'Adicionar' ?></button>
    <?php if ($ec): ?><a class="btn ghost" href="<?= e(fin_url(['year' => $year, 'tab' => 'custos'])) ?>">Cancelar</a><?php endif; ?></div>
</form>
<?php endif; ?>

<h2>Custos dos negócios</h2>
<p class="muted" style="font-size:0.75rem">Criam-se e editam-se na ficha de cada negócio.</p>
<div class="tablewrap"><table>
  <thead><tr><th>Negócio</th><th>Pessoa · função</th><th class="num">Valor</th><th>O valor é</th><th class="num hide-sm">s/ IVA</th><th class="num hide-sm">IVA</th><th>Estado</th></tr></thead>
  <tbody>
  <?php if (!$dealCosts): ?><tr><td colspan="7" class="empty">Sem custos de negócios em <?= $year ?>.</td></tr><?php endif; ?>
  <?php foreach ($dealCosts as $c): ?>
    <tr><td class="nowrap"><a class="lnk" href="/admin/negocio.php?id=<?= (int)$c['deal_id'] ?>#custos"><?= e($c['code']) ?></a> <span class="muted"><?= e(shorten($c['deal_name'], 24)) ?></span></td>
      <td><?= e(trim($c['name'] . ($c['role'] ? ' · ' . $c['role'] : ''), ' ·')) ?></td>
      <td class="num"><?= eur((float)$c['amount']) ?></td><td><?= e(VAT_MODES[$c['vat_mode']] ?? '') ?></td>
      <td class="num hide-sm"><?= eur($c['net']) ?></td><td class="num hide-sm"><?= eur($c['vat']) ?></td>
      <td><?= (int)$c['is_closed'] ? 'Fechado' : 'Aberto' ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<?php else: // IVA
    $deals = year_deals($pdo, $year, $rate);
    $company = array_filter(company_cost_rows($pdo, $rate, $year), fn($c) => $c['vat'] > 0); ?>
<div class="stats" style="margin-bottom:1rem">
  <div class="stat"><div class="k">IVA liquidado</div><div class="v"><?= eur($t['vat_out']) ?></div><div class="n">cobrado aos clientes</div></div>
  <div class="stat"><div class="k">IVA suportado</div><div class="v"><?= eur($t['vat_in']) ?></div><div class="n">pago a fornecedores</div></div>
  <div class="stat hero"><div class="k">Saldo</div><div class="v"><?= eur($t['vat_out'] - $t['vat_in']) ?></div><div class="n"><?= $t['vat_out'] >= $t['vat_in'] ? 'a entregar ao Estado' : 'a recuperar' ?></div></div>
</div>
<div class="flash">Estimativa — a declaração periódica confirma-se sempre com a contabilidade.</div>
<div class="tablewrap"><table>
  <thead><tr><th>Registo</th><th>Mês</th><th class="num">Liquidado</th><th class="num">Suportado</th></tr></thead>
  <tbody>
  <?php foreach ($deals as $d): if ($d['m']['vat_out'] == 0 && $d['m']['vat_in'] == 0) continue; ?>
    <tr><td><a class="lnk" href="/admin/negocio.php?id=<?= (int)$d['id'] ?>"><?= e($d['code']) ?></a> <?= e($d['name']) ?></td>
      <td><?= e($d['month'] ? month_name((int)$d['month']) : '—') ?></td>
      <td class="num"><?= eur($d['m']['vat_out']) ?></td><td class="num"><?= eur($d['m']['vat_in']) ?></td></tr>
  <?php endforeach; ?>
  <?php foreach ($company as $c): ?>
    <tr><td><?= e($c['name']) ?> <span class="chip">empresa</span></td><td><?= e($c['month'] ? month_name((int)$c['month']) : '—') ?></td>
      <td class="num">—</td><td class="num"><?= eur($c['vat']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<?php render_footer();
