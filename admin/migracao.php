<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/leads.php';
require __DIR__ . '/inc/migrate_sheet.php';
require __DIR__ . '/inc/export.php';
$user = require_login();
if ($user['role'] !== 'superadmin') { http_response_code(403); exit('Só o superadmin.'); }

// Migração da folha Google para o MySQL (uma vez), exportação para a folha
// e cópias de segurança. A página monta o plano sem escrever nada; só o
// botão «Migrar agora», com o plano limpo, escreve na base.

$funnels = $pdo->query('SELECT id, name FROM funnels WHERE is_active = 1 ORDER BY position, name')->fetchAll(PDO::FETCH_KEY_PAIR);
$byName  = array_flip($funnels);
$users   = $pdo->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

/** As escolhas do formulário (GET para ver o plano, POST para executar). */
function ms_options(array $src, array $funnels, array $byName, int $me): array {
    $type = []; $svc = [];
    foreach ((array)($src['ft'] ?? []) as $k => $v) if (isset($funnels[(int)$v])) $type[(string)$k] = (int)$v;
    foreach ((array)($src['fs'] ?? []) as $k => $v) if (isset($funnels[(int)$v])) $svc[(string)$k] = (int)$v;
    return [
        'funnel_by_type'    => $type,
        'funnel_by_service' => $svc,
        'lost_with_budget'  => array_map('intval', (array)($src['lost'] ?? [])),
        'owner_id'          => (int)($src['owner'] ?? $me),
        'today'             => today_lisbon(),
    ];
}

/** Sugestões iniciais de funil (só se o nome existir; o resto escolhe-se). */
function ms_default_type(string $t, array $byName): int {
    $map = ['VFX' => 'VFX / 3D', 'Animação 3D' => 'CGI', 'Produção Completa' => 'Campanhas'];
    return (int)($byName[$map[$t] ?? ''] ?? 0);
}
function ms_default_service(string $s, array $byName): int {
    $map = ['VFX / 3D' => 'VFX / 3D', 'Marketing Buddy' => 'Marketing Buddy'];
    return (int)($byName[$map[$s] ?? ''] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    try {
        if ($do === 'migrate') {
            if (trim((string)($_POST['confirm'] ?? '')) !== 'MIGRAR') throw new RuntimeException('Escreva MIGRAR para confirmar.');
            $opt  = ms_options($_POST, $funnels, $byName, (int)$user['id']);
            if (!isset($users[$opt['owner_id']])) throw new RuntimeException('Escolha o responsável.');
            $read = sheet_read(true);
            $plan = ms_plan($read, $opt);
            if (!ms_ready($plan)) throw new RuntimeException('A folha mudou ou o plano tem problemas. Reveja a lista.');
            $opt['drive_folders'] = ms_drive_folders($plan['jobs']);
            $s = ms_execute($pdo, $plan, $opt);
            flash("Migração feita: {$s['deals']} negócios concluídos, {$s['costs']} custos de negócios, {$s['company']} custos da empresa, "
                . "{$s['leads']} leads, {$s['archived']} arquivadas, {$s['xray']} no X-Ray, {$s['won']} em produção, {$s['lost']} perdidos, "
                . "{$s['contacts']} contactos, {$s['folders']} pastas do Drive ligadas. Ponha agora a folha só de leitura.");
        } elseif ($do === 'export') {
            $r = export_to_sheet($pdo);
            flash('Exportado para a folha: ' . implode(' · ', array_map(fn($t, $n) => "$t ($n)", array_keys($r), $r)) . '.');
        } elseif ($do === 'backup') {
            flash('Cópia de segurança guardada no Drive: ' . backup_to_r2($pdo));
        }
    } catch (SheetException $e) {
        flash($e->getMessage() . ($do === 'export' ? ' Confirme que publicou a versão nova do Code.gs (ação «mirror»).' : ''), 'err');
    } catch (Throwable $e) {
        flash(($e instanceof RuntimeException ? $e->getMessage() : 'Erro: ' . $e->getMessage()), 'err');
    }
    redirect('/admin/migracao.php');
}

$done = ms_done($pdo);
$plan = null; $err = null; $read = null;
if (!$done && isset($_GET['ver'])) {
    try {
        $read = sheet_read(true);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
$opt = ms_options($_GET, $funnels, $byName, (int)$user['id']);
if ($read) {
    $d = sheet_map($read);
    // Valores sugeridos para o que ainda não foi escolhido.
    foreach ($d['jobs'] as $j) $opt['funnel_by_type'][$j['type_label']] ??= ms_default_type($j['type_label'], $byName);
    foreach ($d['leads'] as $l) $opt['funnel_by_service'][$l['service'] ?: '—'] ??= ms_default_service($l['service'], $byName);
    $opt['funnel_by_type'] = array_filter($opt['funnel_by_type']);
    $opt['funnel_by_service'] = array_filter($opt['funnel_by_service']);
    $plan = ms_plan($read, $opt);
}

render_header('Migração', 'migracao');
render_flashes();
?>
<h1>Migração da folha</h1>

<?php if ($done): ?>
  <div class="flash ok">A migração foi feita a <?= e($done) ?>. O MySQL é agora a fonte de verdade; a folha é só um espelho.</div>
<?php else: ?>
<ol class="muted" style="font-size:0.8rem;line-height:1.7">
  <li>Faça uma cópia da folha no dia (Ficheiro › Fazer uma cópia).</li>
  <li>Veja o plano abaixo: nada é escrito até carregar em «Migrar agora».</li>
  <li>Resolva na folha tudo o que aparecer em «Para validar à mão» e veja o plano outra vez.</li>
  <li>Com o plano limpo, migre. A seguir ponha a folha só de leitura.</li>
</ol>

<form method="get">
  <input type="hidden" name="ver" value="1">
  <?php if ($plan):
      $types = array_values(array_unique(array_map(fn($j) => $j['type_label'], $plan['jobs'])));
      $svcs  = array_values(array_unique(array_map(fn($l) => $l['service'] ?: '—', array_merge($plan['leads']['xray'], $plan['leads']['won'], $plan['leads']['lost']))));
      $lostAll = array_merge($plan['leads']['archived'], $plan['leads']['lost']);
  ?>
  <h2>Escolhas</h2>
  <div class="grid">
    <?php foreach ($types as $t): ?>
      <div class="field"><label>Trabalhos «<?= e($t ?: 'sem tipo') ?>» → funil</label>
        <select name="ft[<?= e($t) ?>]"><option value="">Escolher…</option>
          <?php foreach ($funnels as $id => $n): ?><option value="<?= (int)$id ?>" <?= ($opt['funnel_by_type'][$t] ?? 0) === (int)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select></div>
    <?php endforeach; ?>
    <?php foreach ($svcs as $s): ?>
      <div class="field"><label>Leads com serviço «<?= e($s) ?>» → funil</label>
        <select name="fs[<?= e($s) ?>]"><option value="">Escolher…</option>
          <?php foreach ($funnels as $id => $n): ?><option value="<?= (int)$id ?>" <?= ($opt['funnel_by_service'][$s] ?? 0) === (int)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select></div>
    <?php endforeach; ?>
    <div class="field"><label for="owner">Responsável do que for migrado</label>
      <select id="owner" name="owner"><?php foreach ($users as $id => $n): ?><option value="<?= (int)$id ?>" <?= $opt['owner_id'] === (int)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
  </div>
  <?php if ($lostAll): ?>
    <p style="margin-top:1rem"><b>Leads em «Perdido»:</b> marque as que chegaram a ter orçamento (passam a negócio perdido no X-Ray, com ID). As outras ficam arquivadas.</p>
    <div class="actions">
      <?php foreach ($lostAll as $l): ?>
        <label class="check"><input type="checkbox" name="lost[]" value="<?= (int)$l['row'] ?>" <?= in_array($l['row'], $opt['lost_with_budget'], true) ? 'checked' : '' ?>> <?= e($l['name']) ?></label>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
  <div class="actions"><button class="btn<?= $plan ? ' ghost' : '' ?>" type="submit"><?= $plan ? 'Atualizar o plano' : 'Ler a folha e ver o plano' ?></button></div>
</form>

<?php if ($err): ?><div class="flash err"><?= e($err) ?></div><?php endif; ?>

<?php if ($plan): $c = $plan['counts']; ?>
  <h2>Plano</h2>
  <div class="stats">
    <div class="stat"><div class="k">Trabalhos → concluídos</div><div class="v"><?= $c['jobs'] ?></div><div class="n"><?= $c['crew'] ?> custos de equipa</div></div>
    <div class="stat"><div class="k">Custos da empresa</div><div class="v"><?= $c['company'] ?></div></div>
    <div class="stat"><div class="k">Leads</div><div class="v"><?= $c['leads'] ?></div>
      <div class="n"><?= count($plan['leads']['lead']) ?> ativas · <?= count($plan['leads']['xray']) ?> X-Ray · <?= count($plan['leads']['won']) ?> ganhas · <?= count($plan['leads']['lost']) ?> perdidas · <?= count($plan['leads']['archived']) ?> arquivadas</div></div>
    <div class="stat"><div class="k">Fornecedores → contactos</div><div class="v"><?= $c['suppliers'] ?></div></div>
  </div>
  <?php if ($plan['leads']['won_dup']): ?>
    <p class="muted" style="font-size:0.75rem">Leads «Ganho» que já existem em Trabalhos (não se duplicam): <?= e(implode(', ', array_map(fn($l) => $l['name'], $plan['leads']['won_dup']))) ?>.</p>
  <?php endif; ?>

  <?php if ($plan['issues'] || $plan['checks']): ?>
    <h2>Para validar à mão</h2>
    <div class="flash err"><ul style="margin:0;padding-left:1.1rem">
      <?php foreach (array_merge($plan['issues'], $plan['checks']) as $i): ?><li><?= e($i) ?></li><?php endforeach; ?>
    </ul></div>
    <p class="muted" style="font-size:0.75rem">As diferenças de contas comparam o que o sistema calcula com as colunas calculadas da folha. A migração só avança com números idênticos.</p>
  <?php else: ?>
    <div class="flash ok">Tudo validado: os totais de cada linha batem certo com a folha.</div>
  <?php endif; ?>

  <h2>IDs que vão ser atribuídos</h2>
  <div class="tablewrap"><table>
    <thead><tr><th>ID</th><th>Trabalho</th><th>Ano · mês</th><th>Funil</th><th class="num">s/ IVA</th><th class="num">Gastos</th><th class="num">Lucro</th></tr></thead>
    <tbody>
    <?php foreach ($plan['jobs'] as $j): ?>
      <tr><td class="nowrap"><?= e($j['code'] ?? '—') ?></td><td><?= e($j['name']) ?></td>
        <td class="nowrap"><?= (int)$j['year'] ?> · <?= e($j['start_month'] ? month_name($j['start_month']) : '—') ?></td>
        <td><?= e($funnels[$j['funnel_id']] ?? '—') ?></td>
        <td class="num"><?= eur($j['money']['budget_net']) ?></td><td class="num"><?= eur($j['money']['expenses']) ?></td><td class="num"><?= eur($j['money']['profit']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php if (ms_ready($plan)): ?>
  <h2>Migrar</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do" value="migrate">
    <?php foreach ($opt['funnel_by_type'] as $k => $v): ?><input type="hidden" name="ft[<?= e((string)$k) ?>]" value="<?= (int)$v ?>"><?php endforeach; ?>
    <?php foreach ($opt['funnel_by_service'] as $k => $v): ?><input type="hidden" name="fs[<?= e((string)$k) ?>]" value="<?= (int)$v ?>"><?php endforeach; ?>
    <?php foreach ($opt['lost_with_budget'] as $r): ?><input type="hidden" name="lost[]" value="<?= (int)$r ?>"><?php endforeach; ?>
    <input type="hidden" name="owner" value="<?= (int)$opt['owner_id'] ?>">
    <div class="bar"><input type="text" name="confirm" placeholder="Escreva MIGRAR" autocomplete="off" required>
      <button class="btn" type="submit">Migrar agora</button></div>
    <p class="muted" style="font-size:0.75rem">Corre uma só vez, numa transação: ou entra tudo, ou nada.</p>
  </form>
  <?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<h2>Exportar para a folha</h2>
<p class="muted" style="font-size:0.8rem">Reescreve os separadores «Espelho · Negócios / Custos / Leads / Contactos» com o que está no admin. Corre também todas as noites.
  Última exportação: <?= e((string)setting($pdo, 'last_export_at', '—')) ?>.</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="export"><button class="btn ghost" type="submit">Exportar agora</button></form>

<h2>Cópia de segurança</h2>
<p class="muted" style="font-size:0.8rem">Cópia completa da base, guardada no Drive em <code><?= e(BACKUP_PREFIX) ?></code> (os últimos <?= BACKUP_KEEP ?> dias). Corre todas as noites.</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="backup"><button class="btn ghost" type="submit">Fazer uma cópia agora</button></form>

<?php render_footer();
