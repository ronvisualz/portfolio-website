<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/platform_import.php';
require __DIR__ . '/inc/export.php';
$user = require_login();
if (!is_admin()) { http_response_code(403); exit('Só os administradores.'); }

// Passagem única dos dados da versão 10 (jobs, job_crew, company_costs,
// leads, suppliers) para a plataforma, a exportação para Excel e as cópias
// de segurança. A página mostra o plano sem escrever nada; só «Migrar
// agora» escreve, e só se as contas baterem certo ao cêntimo.

$funnels = $pdo->query('SELECT id, name FROM funnels WHERE is_active = 1 ORDER BY position, name')->fetchAll(PDO::FETCH_KEY_PAIR);
$users   = $pdo->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

/** As escolhas do formulário por cima das sugestões. */
function pi_options(PDO $pdo, array $src, array $funnels): array {
    $opt = platform_default_options($pdo);
    foreach ((array)($src['ft'] ?? []) as $k => $v) if (isset($funnels[(int)$v])) $opt['funnel_by_type'][(string)$k] = (int)$v;
    foreach ((array)($src['fs'] ?? []) as $k => $v) if (isset($funnels[(int)$v])) $opt['funnel_by_service'][(string)$k] = (int)$v;
    if (isset($src['lost'])) $opt['lost_with_budget'] = array_map('intval', (array)$src['lost']);
    if (!empty($src['owner'])) $opt['owner_id'] = (int)$src['owner'];
    return $opt;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    try {
        if ($do === 'migrate') {
            $opt = pi_options($pdo, $_POST, $funnels);
            if (!isset($users[$opt['owner_id']])) throw new RuntimeException('Escolha o responsável.');
            $plan = platform_plan($pdo, $opt);
            $opt['drive_folders'] = platform_drive_folders($plan['jobs']);
            $s = platform_migrate($pdo, $opt);
            flash("Migração feita e conferida: {$s['deals']} negócios concluídos, {$s['costs']} custos de negócios, {$s['company']} custos da empresa, "
                . "{$s['leads']} leads, {$s['archived']} arquivadas, {$s['xray']} no X-Ray, {$s['won']} em produção, {$s['lost']} perdidos, "
                . "{$s['contacts']} contactos, {$s['events']} eventos e {$s['tasks']} tarefas ligados, {$s['folders']} pastas do Drive ligadas.");
        } elseif ($do === 'backup') {
            flash('Cópia de segurança guardada: ' . backup_to_r2($pdo));
        }
    } catch (Throwable $e) {
        flash($e instanceof RuntimeException ? $e->getMessage() : 'Erro: ' . $e->getMessage(), 'err');
    }
    redirect('/admin/migracao.php');
}

$done = platform_migrated($pdo);
$opt  = pi_options($pdo, $_GET, $funnels);
$plan = $done ? null : platform_plan($pdo, $opt);

render_header('Migração', 'migracao');
render_flashes();
?>
<h1>Migração para a plataforma</h1>

<?php if ($done): ?>
  <div class="flash ok">A migração foi feita a <?= e($done) ?>. Os dados vivem agora em Negócios, Leads, Contactos e Financeiro.
    As tabelas da versão anterior ficaram guardadas, sem nada apagado.</div>
<?php elseif ($plan['counts']['jobs'] + $plan['counts']['leads'] + $plan['counts']['company'] + $plan['counts']['suppliers'] === 0): ?>
  <div class="flash">Não há dados da versão anterior para passar.</div>
<?php else:
    $types = array_values(array_unique(array_map(fn($j) => (string)$j['type_label'], $plan['jobs'])));
    $svcs  = array_values(array_unique(array_map(fn($l) => (string)$l['service'], array_merge($plan['leads']['xray'], $plan['leads']['won'], $plan['leads']['lost']))));
    $lostAll = array_merge($plan['leads']['archived'], $plan['leads']['lost']);
    $c = $plan['counts'];
?>
<p class="muted" style="font-size:0.8rem">Os dados da versão anterior (Carteira, Equipa, Custos empresa, Leads, Fornecedores) passam para a estrutura nova.
  Nada é escrito até carregar em «Migrar agora». No fim as contas de cada trabalho e de cada ano são refeitas e comparadas, ao cêntimo; se alguma não bater, nada fica gravado.</p>

<form method="get">
  <h2>Escolhas</h2>
  <div class="grid">
    <?php foreach ($types as $t): ?>
      <div class="field"><label>Trabalhos «<?= e($t ?: 'sem tipo') ?>» → funil</label>
        <select name="ft[<?= e($t) ?>]"><option value="">Escolher…</option>
          <?php foreach ($funnels as $id => $n): ?><option value="<?= (int)$id ?>" <?= ($opt['funnel_by_type'][$t] ?? 0) === (int)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select></div>
    <?php endforeach; ?>
    <?php foreach ($svcs as $s): ?>
      <div class="field"><label>Leads com serviço «<?= e($s ?: 'sem serviço') ?>» → funil</label>
        <select name="fs[<?= e($s) ?>]"><option value="">Escolher…</option>
          <?php foreach ($funnels as $id => $n): ?><option value="<?= (int)$id ?>" <?= ($opt['funnel_by_service'][$s] ?? 0) === (int)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select></div>
    <?php endforeach; ?>
    <div class="field"><label for="owner">Responsável das leads e negócios migrados</label>
      <select id="owner" name="owner"><?php foreach ($users as $id => $n): ?><option value="<?= (int)$id ?>" <?= $opt['owner_id'] === (int)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
  </div>
  <?php if ($lostAll): ?>
    <p style="margin-top:1rem"><b>Leads em «Perdido»:</b> marque as que chegaram a ter orçamento (passam a negócio perdido no X-Ray, com ID). As outras ficam arquivadas.</p>
    <input type="hidden" name="lost[]" value="0">
    <div class="actions">
      <?php foreach ($lostAll as $l): ?>
        <label class="check"><input type="checkbox" name="lost[]" value="<?= (int)$l['id'] ?>" <?= in_array((int)$l['id'], $opt['lost_with_budget'], true) ? 'checked' : '' ?>> <?= e($l['name']) ?></label>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="actions"><button class="btn ghost" type="submit">Atualizar o plano</button></div>
</form>

<h2>Plano</h2>
<div class="stats">
  <div class="stat"><div class="k">Trabalhos → concluídos</div><div class="v"><?= $c['jobs'] ?></div><div class="n"><?= $c['crew'] ?> custos de equipa</div></div>
  <div class="stat"><div class="k">Custos da empresa</div><div class="v"><?= $c['company'] ?></div></div>
  <div class="stat"><div class="k">Leads</div><div class="v"><?= $c['leads'] ?></div>
    <div class="n"><?= count($plan['leads']['lead']) ?> ativas · <?= count($plan['leads']['xray']) ?> X-Ray · <?= count($plan['leads']['won']) ?> ganhas · <?= count($plan['leads']['lost']) ?> perdidas · <?= count($plan['leads']['archived']) ?> arquivadas</div></div>
  <div class="stat"><div class="k">Fornecedores → contactos</div><div class="v"><?= $c['suppliers'] ?></div></div>
</div>
<?php if ($plan['leads']['won_dup']): ?>
  <p class="muted" style="font-size:0.75rem">Leads «Ganho» que já são um dos trabalhos (não se duplicam): <?= e(implode(', ', array_map(fn($l) => $l['name'], $plan['leads']['won_dup']))) ?>.</p>
<?php endif; ?>

<?php if ($plan['issues']): ?>
  <h2>Para resolver primeiro</h2>
  <div class="flash err"><ul style="margin:0;padding-left:1.1rem">
    <?php foreach ($plan['issues'] as $i): ?><li><?= e($i) ?></li><?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<h2>IDs que vão ser atribuídos</h2>
<div class="tablewrap"><table>
  <thead><tr><th>ID</th><th>Trabalho</th><th>Ano · mês</th><th>Funil</th><th class="num">s/ IVA</th><th class="num">Gastos</th><th class="num">Lucro</th></tr></thead>
  <tbody>
  <?php foreach ($plan['jobs'] as $j): ?>
    <tr><td class="nowrap"><?= e($j['code'] ?? '—') ?></td><td><?= e($j['name']) ?></td>
      <td class="nowrap"><?= (int)$j['year'] ?> · <?= e($j['month'] ? month_name((int)$j['month']) : '—') ?></td>
      <td><?= e($funnels[$j['funnel_id']] ?? '—') ?></td>
      <td class="num"><?= eur((float)$j['budget_net']) ?></td><td class="num"><?= eur($j['v10']['expenses']) ?></td><td class="num"><?= eur($j['v10']['profit']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<?php if (!$plan['issues']): ?>
<h2>Migrar</h2>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="migrate">
  <?php foreach ($opt['funnel_by_type'] as $k => $v): ?><input type="hidden" name="ft[<?= e((string)$k) ?>]" value="<?= (int)$v ?>"><?php endforeach; ?>
  <?php foreach ($opt['funnel_by_service'] as $k => $v): ?><input type="hidden" name="fs[<?= e((string)$k) ?>]" value="<?= (int)$v ?>"><?php endforeach; ?>
  <input type="hidden" name="lost[]" value="0">
  <?php foreach ($opt['lost_with_budget'] as $r): ?><input type="hidden" name="lost[]" value="<?= (int)$r ?>"><?php endforeach; ?>
  <input type="hidden" name="owner" value="<?= (int)$opt['owner_id'] ?>">
  <button class="btn" type="submit">Migrar agora</button>
  <p class="muted" style="font-size:0.75rem">Corre uma só vez, numa transação: ou entra tudo e as contas batem certo, ou nada.</p>
</form>
<?php endif; ?>
<?php endif; ?>

<h2>Exportar para Excel</h2>
<p class="muted" style="font-size:0.8rem">Negócios, custos, leads e contactos num ficheiro .xlsx, já com as contas feitas.</p>
<p><a class="btn ghost" href="/admin/export.php">Descarregar Excel</a></p>

<h2>Cópia de segurança</h2>
<p class="muted" style="font-size:0.8rem">Cópia completa da base, guardada no R2 numa pasta de sistema (não aparece no Drive); ficam as últimas <?= BACKUP_KEEP ?>. Corre todas as noites.</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="backup"><button class="btn ghost" type="submit">Fazer uma cópia agora</button></form>

<?php render_footer();
