<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/paginate.php';
require __DIR__ . '/inc/leads.php';
require __DIR__ . '/inc/pipeline.php';
require __DIR__ . '/inc/finance.php';
$user = require_login();
require_page('negocios');

// Negócios: os que já passaram o Kickoff e estão em produção.
// Os concluídos e os perdidos ficam no Pipeline. Cada linha abre a ficha.

function neg_filters(array $src): array {
    $f = [];
    foreach (['q', 'funil', 'dono', 'pn'] as $k) {
        $v = trim((string)($src[$k] ?? ''));
        if ($v !== '') $f[$k] = $v;
    }
    return $f;
}
function neg_url(array $f = []): string {
    return '/admin/negocios.php' . ($f ? '?' . http_build_query($f) : '');
}

$me = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$me->execute([(int)$user['id']]);
$me = $me->fetch();

[$scopeSql, $scopeArgs] = scope_sql($pdo, 'negocios', 'd.owner_id');
$reach = sees_unassigned($me) ? "($scopeSql OR d.owner_id IS NULL)" : $scopeSql;
$st = $pdo->prepare(
    "SELECT d.*, u.name AS owner_name, c.name AS contact_name, f.name AS funnel_name, s.name AS stage_name
       FROM deals d
       LEFT JOIN users u ON u.id = d.owner_id
       LEFT JOIN contacts c ON c.id = d.contact_id
       LEFT JOIN funnels f ON f.id = d.funnel_id
       LEFT JOIN funnel_stages s ON s.id = d.stage_id
      WHERE d.phase = 'production' AND $reach"
);
$st->execute($scopeArgs);
$all = $st->fetchAll();

$filters = neg_filters($_GET);
$lc = static fn(string $s): string => mb_strtolower($s, 'UTF-8');
$needle = $lc($filters['q'] ?? '');
$rows = array_values(array_filter($all, static function ($d) use ($filters, $needle, $lc) {
    if (($filters['funil'] ?? '') !== '' && (string)$d['funnel_id'] !== $filters['funil']) return false;
    if (($filters['dono'] ?? '') !== '' && (string)$d['owner_id'] !== $filters['dono']) return false;
    if ($needle !== '' && strpos($lc($d['code'] . ' ' . $d['name'] . ' ' . $d['contact_name'] . ' ' . $d['notes']), $needle) === false) return false;
    return true;
}));
usort($rows, fn($a, $b) => strcmp((string)$b['code'], (string)$a['code']));
$pg = paginate($rows, 'pn');

$money = can('financeiro', PERM_VIEW);
$rate  = vat_rate($pdo);
$funnels = $pdo->query('SELECT id, name FROM funnels ORDER BY position, name')->fetchAll(PDO::FETCH_KEY_PAIR);
$owners  = $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

render_header('Negócios', 'negocios');
render_flashes();
echo paginate_css();
?>
<h1>Negócios</h1>
<div class="sub"><?= count($all) ?> em produção · os concluídos e os perdidos estão no <a class="lnk" href="/admin/pipeline.php?v=concluidos">Pipeline</a></div>

<form class="bar" method="get">
  <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Procurar ID, nome, cliente">
  <select name="funil" onchange="this.form.submit()" aria-label="Funil"><option value="">Todos os funis</option>
    <?php foreach ($funnels as $id => $n): ?><option value="<?= (int)$id ?>" <?= ($filters['funil'] ?? '') === (string)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
  </select>
  <?php if (my_scope('negocios') !== 'own'): ?>
  <select name="dono" onchange="this.form.submit()" aria-label="Responsável"><option value="">Responsável</option>
    <?php foreach ($owners as $id => $n): ?><option value="<?= (int)$id ?>" <?= ($filters['dono'] ?? '') === (string)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button class="btn sm" type="submit">Filtrar</button>
  <?php if (array_diff_key($filters, ['pn' => 1])): ?><a class="lnk" href="/admin/negocios.php">Limpar</a><?php endif; ?>
</form>

<div class="tablewrap">
<table>
  <thead><tr><th>ID</th><th>Negócio</th><th class="hide-sm">Funil · etapa</th>
    <?php if ($money): ?><th class="num">Budget s/ IVA</th><th class="num hide-sm">Lucro</th><th>Pago</th><?php endif; ?>
    <th class="hide-sm">Responsável</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nenhum negócio em produção.</td></tr><?php endif; ?>
  <?php foreach ($pg['rows'] as $d):
        $m = $money ? deal_money($d, deal_costs($pdo, (int)$d['id'], $rate), $rate) : null; ?>
    <tr>
      <td class="nowrap"><a class="lnk" href="/admin/negocio.php?id=<?= (int)$d['id'] ?>"><?= e($d['code']) ?></a></td>
      <td><a class="b" href="/admin/negocio.php?id=<?= (int)$d['id'] ?>" style="color:inherit;text-decoration:none"><?= e($d['name']) ?></a>
        <?php if ($d['contact_name'] && $d['contact_name'] !== $d['name']): ?><div class="muted" style="font-size:0.62rem"><?= e($d['contact_name']) ?></div><?php endif; ?></td>
      <td class="hide-sm"><?= e($d['funnel_name'] ?? '—') ?> · <?= e($d['stage_name'] ?? '—') ?></td>
      <?php if ($money): ?>
        <td class="num"><?= eur0($m['budget_net']) ?></td>
        <td class="num hide-sm"><?= eur0($m['profit']) ?></td>
        <td><?= (int)$d['is_paid'] ? '<span class="chip ok">Pago</span>' : '<span class="chip wait">Por pagar</span>' ?></td>
      <?php endif; ?>
      <td class="hide-sm"><?= e($d['owner_name'] ?? '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= page_nav($pg) ?>
<?php render_footer();
