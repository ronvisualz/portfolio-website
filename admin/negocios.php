<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/paginate.php';
require_once __DIR__ . '/inc/leads.php';
require_once __DIR__ . '/inc/pipeline.php';
require_once __DIR__ . '/inc/finance.php';
require_once __DIR__ . '/inc/deal_file.php';
$user = require_login();
require_page('negocios');

// Negócios (§15.1): aparecem quando chegam a Kickoff, em qualquer funil, e os
// concluídos ficam aqui também (o Pipeline guarda só a fase comercial e os
// perdidos). Separadores Em produção · Concluídos · Todos; vistas Os meus ·
// Entrega esta semana · Em risco · Por receber. Cada linha abre a ficha central.
// Valor, margem real e recebido só aparecem a quem vê o Financeiro.

function neg_filters(array $src): array {
    $f = [];
    foreach (['t', 'q', 'funil', 'dono', 'vista', 'pn'] as $k) {
        $v = trim((string)($src[$k] ?? ''));
        if ($v !== '') $f[$k] = $v;
    }
    return $f;
}
function neg_url(array $f = []): string {
    return '/admin/negocios.php' . ($f ? '?' . http_build_query($f) : '');
}

$me = me_row($pdo);
$money = can('financeiro', PERM_VIEW);
$rate  = vat_rate($pdo);

[$scopeSql, $scopeArgs] = scope_sql($pdo, 'negocios', 'd.owner_id');
$reach = sees_unassigned($me) ? "($scopeSql OR d.owner_id IS NULL)" : $scopeSql;
$st = $pdo->prepare(
    "SELECT d.*, u.name AS owner_name, COALESCE(co.name, c.name, NULLIF(d.company, '')) AS client_name, f.name AS funnel_name,
            s.name AS stage_name, s.kind AS stage_kind
       FROM deals d
       LEFT JOIN users u ON u.id = d.owner_id
       LEFT JOIN contacts co ON co.id = d.company_id
       LEFT JOIN contacts c ON c.id = d.contact_id
       LEFT JOIN funnels f ON f.id = d.funnel_id
       LEFT JOIN funnel_stages s ON s.id = d.stage_id
      WHERE d.code IS NOT NULL AND d.phase IN ('production','done') AND $reach"
);
$st->execute($scopeArgs);
$base = $st->fetchAll();

$filters = neg_filters($_GET);
$tab = isset(DEAL_LIST_TABS[$filters['t'] ?? '']) ? (string)$filters['t'] : 'producao';
if ($tab === 'producao') unset($filters['t']);
$tabPhase = ['producao' => ['production'], 'concluidos' => ['done'], 'todos' => ['production', 'done']];
$tabCount = [];
foreach ($tabPhase as $k => $ph) $tabCount[$k] = count(array_filter($base, fn($d) => in_array($d['phase'], $ph, true)));
$all = array_values(array_filter($base, fn($d) => in_array($d['phase'], $tabPhase[$tab], true)));

// O que as vistas e as colunas precisam: tarefas atrasadas, entrega prevista, risco, contas.
$late = [];
$st = $pdo->prepare("SELECT deal_id, COUNT(*) FROM tasks WHERE deal_id IS NOT NULL AND status <> 'done' AND due_date < ? GROUP BY deal_id");
$st->execute([today_lisbon()]);
$late = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
$weekEnd = (new DateTimeImmutable(today_lisbon()))->modify('sunday this week')->format('Y-m-d');
foreach ($all as &$d) {
    $d['delivery'] = $d['phase'] === 'production' ? deal_delivery_date($pdo, $d) : null;
    $d['risk'] = deal_is_risk($pdo, $d, $late[(int)$d['id']] ?? 0, $d['delivery']);
    if ($money) {
        $m = deal_money($d, deal_costs($pdo, (int)$d['id'], $rate), $rate);
        $d['value'] = (float)$m['budget_net'];
        $d['margin'] = $d['value'] > 0 ? round(((float)$m['budget_net'] - (float)$m['expenses']) / $d['value'] * 100, 1) : null;
        $d['gross'] = (float)$m['budget_gross'];
    }
}
unset($d);

const NEG_VISTAS = ['' => 'Todos', 'meus' => 'Os meus', 'semana' => 'Entrega esta semana', 'risco' => 'Em risco', 'receber' => 'Por receber'];
$vistas = NEG_VISTAS;
if (!$money) unset($vistas['receber']);
$vista = isset($vistas[$filters['vista'] ?? '']) ? (string)($filters['vista'] ?? '') : '';
$inVista = static function (array $d, string $v) use ($user, $weekEnd): bool {
    return match ($v) {
        'meus'    => (int)$d['owner_id'] === (int)$user['id'],
        'semana'  => $d['phase'] === 'production' && !$d['delivered_at'] && $d['delivery'] !== null && $d['delivery'] <= $weekEnd,
        'risco'   => $d['risk'],
        'receber' => !(int)$d['is_paid'] && (float)$d['budget'] > 0,
        default   => true,
    };
};
$vistaCount = [];
foreach (array_keys($vistas) as $k) $vistaCount[$k] = count(array_filter($all, fn($d) => $inVista($d, $k)));

$lc = static fn(string $s): string => mb_strtolower($s, 'UTF-8');
$needle = $lc($filters['q'] ?? '');
$rows = array_values(array_filter($all, static function ($d) use ($filters, $needle, $lc, $inVista, $vista) {
    if (!$inVista($d, $vista)) return false;
    if (($filters['funil'] ?? '') !== '' && (string)$d['funnel_id'] !== $filters['funil']) return false;
    if (($filters['dono'] ?? '') !== '' && (string)$d['owner_id'] !== $filters['dono']) return false;
    if ($needle !== '' && strpos($lc($d['code'] . ' ' . $d['name'] . ' ' . $d['client_name'] . ' ' . $d['notes']), $needle) === false) return false;
    return true;
}));
usort($rows, fn($a, $b) => $tab === 'concluidos' ? strcmp((string)$b['done_at'], (string)$a['done_at']) : strcmp((string)$b['code'], (string)$a['code']));
$pg = paginate($rows, 'pn', 15);

$funnels = $pdo->query('SELECT id, name FROM funnels ORDER BY position, name')->fetchAll(PDO::FETCH_KEY_PAIR);
$owners  = $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

// Indicadores do que está em produção.
$prod = array_filter($base, fn($d) => $d['phase'] === 'production');
$prodValue = $money ? array_sum(array_map(fn($d) => (float)$d['budget'], $prod)) : 0;
$unpaid = $money ? array_filter($base, fn($d) => !(int)$d['is_paid'] && (float)$d['budget'] > 0) : [];
$riskAll = $vistaCount['risco'];

render_header('Negócios', 'negocios');
render_flashes();
echo paginate_css();
$keep = fn(array $set = []) => neg_url(array_filter(array_merge(['t' => $tab === 'producao' ? null : $tab], $set)));
?>
<h1>Negócios</h1>
<div class="sub">Desde o Kickoff, em qualquer funil · os concluídos ficam aqui · os perdidos estão no <a class="lnk" href="/admin/pipeline.php?v=perdidos">Pipeline</a></div>

<div class="stats">
  <a class="stat hero" href="<?= e(neg_url()) ?>"><div class="k">Em produção</div><div class="v"><?= count($prod) ?></div><div class="n"><?= count(array_filter($prod, fn($d) => (int)$d['owner_id'] === (int)$user['id'])) ?> são seus</div></a>
  <?php if ($money): ?>
  <div class="stat"><div class="k">Valor em produção</div><div class="v"><?= e(budget_eur($prodValue)) ?></div><div class="n">s/ IVA</div></div>
  <a class="stat" href="<?= e($keep(['t' => 'todos', 'vista' => 'receber'])) ?>"><div class="k">Por receber</div><div class="v"><?= e(budget_eur(array_sum(array_map(fn($d) => (float)$d['budget'], $unpaid)))) ?></div><div class="n"><?= count($unpaid) ?> negócios · s/ IVA</div></a>
  <?php endif; ?>
  <a class="stat<?= $riskAll ? ' risk' : '' ?>" href="<?= e($keep(['vista' => 'risco'])) ?>"><div class="k">Em risco</div><div class="v"><?= $riskAll ?></div><div class="n">tarefas ou entrega atrasadas, fecho incompleto</div></a>
</div>

<div class="tabs-row">
  <div class="tabs">
    <?php foreach (DEAL_LIST_TABS as $k => $label): ?>
      <a href="<?= e(neg_url($k === 'producao' ? [] : ['t' => $k])) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($label) ?> (<?= $tabCount[$k] ?>)</a>
    <?php endforeach; ?>
  </div>
</div>

<?= views_bar($vistas, $vista, fn($k) => $keep(['vista' => $k ?: null]), $vistaCount) ?>

<form class="bar" method="get" data-auto>
  <?php if ($tab !== 'producao'): ?><input type="hidden" name="t" value="<?= e($tab) ?>"><?php endif; ?>
  <?php if ($vista !== ''): ?><input type="hidden" name="vista" value="<?= e($vista) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Procurar ID, nome, cliente" aria-label="Procurar" data-refocus>
  <select name="funil" onchange="this.form.submit()" aria-label="Funil"><option value="">Todos os funis</option>
    <?php foreach ($funnels as $fid => $n): ?><option value="<?= (int)$fid ?>" <?= ($filters['funil'] ?? '') === (string)$fid ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
  </select>
  <?php if (my_scope('negocios') !== 'own'): ?>
  <select name="dono" onchange="this.form.submit()" aria-label="Responsável"><option value="">Responsável</option>
    <?php foreach ($owners as $oid => $n): ?><option value="<?= (int)$oid ?>" <?= ($filters['dono'] ?? '') === (string)$oid ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button class="btn sm apply" type="submit">Filtrar</button>
  <?php if (array_diff_key($filters, ['pn' => 1, 'vista' => 1, 't' => 1])): ?><a class="lnk" href="<?= e($keep(['vista' => $vista ?: null])) ?>">Limpar</a><?php endif; ?>
</form>

<div class="tablewrap">
<table class="ng-t">
  <thead><tr><th>ID</th><th>Negócio</th><th>Etapa</th>
    <?php if ($money): ?><th class="num">Valor s/ IVA</th><th class="num">Margem real</th><th class="num">Recebido</th><?php endif; ?>
    <th>Entrega</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty"><?= match ($tab) { 'concluidos' => 'Nenhum negócio concluído', default => 'Nenhum negócio em produção' } ?><?= $vista !== '' || array_diff_key($filters, ['t' => 1, 'pn' => 1]) ? ' com estes filtros' : '' ?>.</td></tr><?php endif; ?>
  <?php foreach ($pg['rows'] as $d):
        $step = deal_step_label($pdo, $d);
        $target = budget_setting($pdo, 'budget_margin_pct');
        $mCls = !$money || $d['margin'] === null ? '' : ($d['margin'] >= $target ? 'pos' : ($d['margin'] >= $target * 0.75 ? 'warn-t' : 'neg')); ?>
    <tr class="<?= $d['risk'] ? 'stale' : '' ?>" data-href="/admin/negocio.php?id=<?= (int)$d['id'] ?>">
      <td class="nowrap"><a class="lnk id" href="/admin/negocio.php?id=<?= (int)$d['id'] ?>"><?= e($d['code']) ?></a></td>
      <td class="nm"><a class="b" href="/admin/negocio.php?id=<?= (int)$d['id'] ?>"><?= e($d['name']) ?></a>
        <small><?= e(trim(($d['client_name'] && $d['client_name'] !== $d['name'] ? $d['client_name'] : '') . ($d['job_type'] ? ($d['client_name'] && $d['client_name'] !== $d['name'] ? ' · ' : '') . $d['job_type'] : ''))) ?: '&nbsp;' ?></small></td>
      <td><span class="stp <?= $d['phase'] === 'done' ? 'done' : 'now' ?>"><?= e($step) ?></span>
        <?php if ($d['risk']): ?> <span class="risk-txt" data-tip="<?= e(!empty($late[(int)$d['id']]) ? 'Tarefas atrasadas' : ($d['phase'] === 'done' ? 'Fecho incompleto' : 'Entrega atrasada')) ?>" tabindex="0">em risco</span><?php endif; ?></td>
      <?php if ($money): ?>
        <td class="num"><?= $d['value'] > 0 ? e(budget_eur($d['value'])) : '<span class="muted">—</span>' ?></td>
        <td class="num <?= $mCls ?>"><?= $d['margin'] !== null ? e(budget_pct($d['margin'])) : '<span class="muted">—</span>' ?></td>
        <td class="num <?= (int)$d['is_paid'] ? 'pos' : ($d['value'] > 0 ? 'warn-t' : '') ?>"><?= (int)$d['is_paid'] ? '100%' : ($d['value'] > 0 ? '0%' : '<span class="muted">—</span>') ?></td>
      <?php endif; ?>
      <td class="nowrap mono small"><?= $d['phase'] === 'done' ? ($d['done_at'] ? '<span class="muted">' . e(date('d/m/Y', strtotime((string)$d['done_at']))) . '</span>' : '—')
          : ($d['delivered_at'] ? '<span class="pos">entregue</span>' : ($d['delivery'] ? '<span class="' . ($d['delivery'] < today_lisbon() ? 'neg' : '') . '">' . e(date('d/m/Y', strtotime($d['delivery']))) . '</span>' : '<span class="muted">—</span>')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= page_nav($pg) ?>
<?php render_footer();
