<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/leads.php';
require_once __DIR__ . '/inc/pipeline.php';
require_once __DIR__ . '/inc/finance.php';
require_once __DIR__ . '/inc/deal_drive.php';
require_once __DIR__ . '/inc/undo.php';
require_once __DIR__ . '/inc/interactions.php';
require_once __DIR__ . '/inc/lead_view.php';   // ligações a contactos; «Trazido por» (inc/contacts.php)
$user = require_login();
require_page('negocios');

// Ficha do negócio: a central que junta, pelo ID, o que as outras áreas
// ligaram a este negócio — contactos, orçamentação, contas, ficheiros,
// criativos, tarefas e etapas. Os dados financeiros pedem a aba Financeiro.
// Cliente (quem paga), Trazido por (quem originou) e Criado por são três
// coisas diferentes (§13.4). Fechar o custo de um fornecedor pede a
// avaliação do trabalho (§13.3).

$me = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$me->execute([(int)$user['id']]);
$me = $me->fetch();

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$deal = deal_get($pdo, $id);
$ok   = $deal && $deal['code'] !== null
     && ($deal['owner_id'] === null ? sees_unassigned($me) : in_my_scope($pdo, 'negocios', (int)$deal['owner_id']));
if (!$ok) {
    http_response_code(404);
    render_header('Negócio', 'negocios');
    echo '<h1>Negócio não encontrado</h1><p><a class="lnk" href="/admin/negocios.php">← Negócios</a></p>';
    render_footer();
    exit;
}

$rate     = vat_rate($pdo);
$self     = '/admin/negocio.php?id=' . $id;
$canEdit  = can('negocios', PERM_EDIT);
$money    = can('financeiro', PERM_VIEW);
$moneyEdit= can('financeiro', PERM_EDIT);

// ── ações ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $finance = ['save_finance', 'add_cost', 'save_cost', 'toggle_cost', 'del_cost', 'rate_cost'];
    $rateAsk = 0;   // custo fechado de um fornecedor ainda por avaliar: pede as estrelas a seguir
    if (in_array($do, $finance, true)) require_perm('financeiro', PERM_EDIT);

    try {
        switch ($do) {
            case 'save_info':
                $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 160) ?: $deal['name'];
                $pdo->prepare('UPDATE deals SET name = ?, notes = ? WHERE id = ?')
                    ->execute([$name, mb_substr((string)($_POST['notes'] ?? ''), 0, 8000), $id]);
                flash('Negócio guardado.');
                break;

            case 'stage':
                $snap = undo_deal_snapshot($deal);
                $to = (int)($_POST['stage_id'] ?? 0);
                $msg = deal_move($pdo, $deal, $to);
                flash($msg, 'ok', (int)$deal['stage_id'] !== $to ? undo_push('deal_stage', $snap + ['to_stage' => $to]) : '');
                if (($s = stage_get($pdo, (int)$_POST['stage_id'])) && $s['kind'] === 'done') redirect('/admin/pipeline.php?v=concluidos');
                break;

            case 'save_finance':
                $v = deal_finance_input($_POST, $rate);
                $pdo->prepare(
                    'UPDATE deals SET job_type=?, year=?, month=?, budget=?, is_international=?, has_vat=?, is_paid=?,
                                      expenses_mode=?, expenses_manual=? WHERE id=?'
                )->execute([$v['job_type'], $v['year'], $v['month'], $v['budget'], $v['is_international'], $v['has_vat'],
                            $v['is_paid'], $v['expenses_mode'], $v['expenses_manual'], $id]);
                flash('Contas do negócio guardadas.');
                break;

            case 'add_cost':
            case 'save_cost':
                $c = cost_input($_POST);
                if ($c['name'] === '' && $c['role'] === '') throw new RuntimeException('Indique a pessoa ou a função.');
                $contact = (int)($_POST['contact_id'] ?? 0) ?: null;
                if ($do === 'add_cost') {
                    $pdo->prepare('INSERT INTO costs (deal_id, year, month, name, role, contact_id, amount, vat_mode, is_closed, notes, created_by)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$id, $deal['year'], $deal['month'], $c['name'], $c['role'], $contact, $c['amount'], $c['vat_mode'], $c['is_closed'], $c['notes'], (int)$me['id']]);
                    $costId = (int)$pdo->lastInsertId();
                    flash('Custo adicionado.');
                } else {
                    $costId = (int)($_POST['cost_id'] ?? 0);
                    $pdo->prepare('UPDATE costs SET name=?, role=?, contact_id=?, amount=?, vat_mode=?, is_closed=?, notes=? WHERE id=? AND deal_id=?')
                        ->execute([$c['name'], $c['role'], $contact, $c['amount'], $c['vat_mode'], $c['is_closed'], $c['notes'], $costId, $id]);
                    flash('Custo guardado.');
                }
                if ($c['is_closed'] && cost_needs_rating($pdo, $costId)) $rateAsk = $costId;
                break;

            case 'toggle_cost':
                $st = $pdo->prepare('SELECT is_closed FROM costs WHERE id = ? AND deal_id = ?');
                $st->execute([(int)($_POST['cost_id'] ?? 0), $id]);
                $was = $st->fetchColumn();
                if ($was !== false) {
                    $pdo->prepare('UPDATE costs SET is_closed = 1 - is_closed WHERE id = ? AND deal_id = ?')->execute([(int)($_POST['cost_id'] ?? 0), $id]);
                    flash((int)$was ? 'Custo reaberto.' : 'Custo fechado.', 'ok', undo_push('cost_closed', ['id' => (int)$_POST['cost_id'], 'from' => (int)$was]));
                    if (!(int)$was && cost_needs_rating($pdo, (int)$_POST['cost_id'])) $rateAsk = (int)$_POST['cost_id'];
                }
                break;

            case 'rate_cost':
                $cost = cost_needs_rating($pdo, (int)($_POST['cost_id'] ?? 0));
                if (!$cost || (int)$cost['deal_id'] !== $id) throw new RuntimeException('Este trabalho já foi avaliado.');
                cost_rate($pdo, $cost, (int)($_POST['stars'] ?? 0), (int)$me['id']);
                [$avg, $nr] = contact_rating($pdo, (int)$cost['contact_id']);
                flash($cost['contact_name'] . ' avaliado com ' . (int)$_POST['stars'] . '★ · média ' . number_format((float)$avg, 1, ',', '') . ' (' . $nr . ').');
                break;

            case 'brought_by':
                if (!$canEdit || !record_reach($pdo, $deal, PERM_EDIT)) throw new RuntimeException('Sem permissão para mudar este negócio.');
                $to = contact_pick_resolve($pdo, $_POST['brought_by_id'] ?? '', (string)($_POST['brought_by'] ?? ''));
                $from = $deal['brought_by_id'] !== null ? (int)$deal['brought_by_id'] : null;
                if (deal_set_brought_by($pdo, $deal, $to, (int)$me['id'])) {
                    flash('«Trazido por» guardado.', 'ok', undo_push('brought_by', ['id' => $id, 'from' => $from, 'to' => $to]));
                }
                break;

            case 'del_cost':
                $pdo->prepare('DELETE FROM costs WHERE id = ? AND deal_id = ?')->execute([(int)($_POST['cost_id'] ?? 0), $id]);
                flash('Custo eliminado.');
                break;

            case 'link_contact':
                $cid = (int)($_POST['contact_id'] ?? 0);
                $role = mb_substr(trim((string)($_POST['role'] ?? '')), 0, 80);
                $st = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE id = ?');
                $st->execute([$cid]);
                if (!(int)$st->fetchColumn()) throw new RuntimeException('Escolha o contacto.');
                $pdo->prepare((db_is_sqlite($pdo) ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO deal_contacts (deal_id, contact_id, role) VALUES (?,?,?)')
                    ->execute([$id, $cid, $role]);
                if ($deal['contact_id'] === null) $pdo->prepare('UPDATE deals SET contact_id = ? WHERE id = ?')->execute([$cid, $id]);
                flash('Contacto ligado ao negócio.');
                break;

            case 'unlink_contact':
                $cid = (int)($_POST['contact_id'] ?? 0);
                $pdo->prepare('DELETE FROM deal_contacts WHERE deal_id = ? AND contact_id = ?')->execute([$id, $cid]);
                if ((int)$deal['contact_id'] === $cid) {
                    $pdo->prepare('UPDATE deals SET contact_id = (SELECT MIN(contact_id) FROM deal_contacts WHERE deal_id = ?) WHERE id = ?')->execute([$id, $id]);
                }
                flash('Contacto desligado.');
                break;

            case 'drive':
                require_perm('drive', PERM_VIEW);
                if (!r2_config()) redirect('/admin/drive.php');
                redirect(drive_page(drive_deal_folder($pdo, $deal)));
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
    }
    $anchor = (string)($_POST['anchor'] ?? '');
    redirect($self . (!empty($rateAsk) ? '&avaliar=' . $rateAsk : '') . (preg_match('/^[a-z]+$/', $anchor) ? '#' . $anchor : ''));
}

// ── dados ───────────────────────────────────────────────────
$funnel   = funnel_get($pdo, (int)$deal['funnel_id']);
$stages   = $deal['funnel_id'] ? funnel_stages($pdo, (int)$deal['funnel_id']) : [];
$stage    = $deal['stage_id'] ? stage_get($pdo, (int)$deal['stage_id']) : null;
$owner    = $deal['owner_id'] ? $pdo->query('SELECT name FROM users WHERE id = ' . (int)$deal['owner_id'])->fetchColumn() : null;
$costs    = $money ? deal_costs($pdo, $id, $rate) : [];
$m        = $money ? deal_money($deal, $costs, $rate) : null;

$st = $pdo->prepare('SELECT c.*, dc.role AS link_role FROM deal_contacts dc JOIN contacts c ON c.id = dc.contact_id WHERE dc.deal_id = ? ORDER BY c.name');
$st->execute([$id]);
$contacts = $st->fetchAll();
$allContacts = $pdo->query('SELECT id, name FROM contacts ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

$tasks = [];
if (db_has_column($pdo, 'tasks', 'deal_id')) {
    $st = $pdo->prepare('SELECT t.*, tm.name AS who FROM tasks t LEFT JOIN team_members tm ON tm.id = t.member_id WHERE t.deal_id = ? ORDER BY t.status = \'done\', t.due_date');
    $st->execute([$id]);
    $tasks = $st->fetchAll();
}

$rated = $costs ? $pdo->query('SELECT cost_id, stars FROM contact_ratings WHERE cost_id IN (' . implode(',', array_map(fn($c) => (int)$c['id'], $costs)) . ')')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$rateCost = $moneyEdit && !empty($_GET['avaliar']) ? cost_needs_rating($pdo, (int)$_GET['avaliar']) : null;
if ($rateCost && (int)$rateCost['deal_id'] !== $id) $rateCost = null;
$editCost = (int)($_GET['cost'] ?? 0);
$ec = null; foreach ($costs as $c) if ((int)$c['id'] === $editCost) $ec = $c;
$phaseChip = ['pipeline' => 'Fase comercial', 'production' => 'Em produção', 'done' => 'Concluído', 'lost' => 'Perdido'][$deal['phase']] ?? $deal['phase'];

render_header($deal['code'] . ' · ' . $deal['name'], $deal['phase'] === 'production' ? 'negocios' : 'pipeline');
render_flashes();
?>
<p><a class="lnk" href="<?= $deal['phase'] === 'production' ? '/admin/negocios.php' : '/admin/pipeline.php' ?>">← <?= $deal['phase'] === 'production' ? 'Negócios' : 'Pipeline' ?></a></p>
<h1><span class="keep"><span class="id"><?= e($deal['code']) ?></span> · <?= inline_field('deal', $id, 'name', e($deal['name']), ['edit' => $canEdit && record_reach($pdo, $deal, PERM_EDIT), 'label' => 'Editar nome', 'value' => $deal['name']]) ?></span></h1>
<div class="sub"><?= e($funnel['name'] ?? '—') ?> · <?= e($stage['name'] ?? '—') ?> · <span class="chip"><?= e($phaseChip) ?></span>
  · Responsável: <?= e($owner ?: 'por atribuir') ?></div>

<?php if ($money): ?>
<div class="stats" style="margin:1rem 0 1.6rem">
  <div class="stat"><div class="k">Budget s/ IVA</div><div class="v"><?= eur($m['budget_net']) ?></div><div class="n">c/ IVA <?= eur($m['budget_gross']) ?></div></div>
  <div class="stat"><div class="k">Gastos</div><div class="v"><?= eur($m['expenses']) ?></div><div class="n"><?= $deal['expenses_mode'] === 'manual' ? 'valor manual' : $m['costs_count'] . ' custos' ?></div></div>
  <div class="stat hero <?= $m['profit'] < 0 ? 'neg' : '' ?>"><div class="k">Lucro</div><div class="v"><?= eur($m['profit']) ?></div></div>
  <div class="stat"><div class="k">IVA</div><div class="v"><?= eur($m['vat_out'] - $m['vat_in']) ?></div><div class="n">liquidado <?= eur($m['vat_out']) ?> · suportado <?= eur($m['vat_in']) ?></div></div>
</div>
<?php endif; ?>

<?php if ($canEdit && in_array($deal['phase'], ['production', 'done'], true)):
    $prodStages = array_values(array_filter($stages, fn($s) => in_array($s['kind'], ['kickoff', 'production', 'done'], true))); ?>
<h2 id="etapa">Etapa de produção</h2>
<form method="post" class="bar">
  <?= csrf_field() ?><input type="hidden" name="do" value="stage"><input type="hidden" name="id" value="<?= $id ?>">
  <select name="stage_id" aria-label="Etapa">
    <?php foreach ($prodStages as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === (int)$deal['stage_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
  </select>
  <button class="btn sm" type="submit">Mudar etapa</button>
  <span class="muted" style="font-size:0.75rem">«Concluído» passa o negócio para Pipeline › Concluídos.</span>
</form>
<?php endif; ?>

<h2 id="info">Informação</h2>
<?php $dealEdit = $canEdit && record_reach($pdo, $deal, PERM_EDIT);
      $creator = $deal['created_by'] ? (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$deal['created_by'])->fetchColumn() : ''; ?>
<div class="panel deal-who">
  <div class="kv">
    <span>Cliente</span><div><?= deal_people_link($pdo, $deal['contact_id']) ?> <span class="muted small">· quem paga</span></div>
    <span>Trazido por</span><div>
      <?php if ($dealEdit): ?>
        <form method="post" class="bb-form"><?= csrf_field() ?><input type="hidden" name="do" value="brought_by"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="anchor" value="info">
          <?= contact_pick_html($pdo, 'brought_by', 'brought_by_id', $deal['brought_by_id'] !== null ? (int)$deal['brought_by_id'] : null, 'dbb', 'Ninguém (quem originou: agência, parceiro…)') ?>
          <button class="btn ghost sm" type="submit">Guardar</button></form>
      <?php else: ?><?= deal_people_link($pdo, $deal['brought_by_id']) ?><?php endif; ?>
      <span class="muted small">· quem originou o negócio</span></div>
    <span>Criado por</span><div><?= $creator !== '' ? e($creator) : '<span class="muted">—</span>' ?> <span class="muted small mono"><?= e(lisbon_fmt(strtotime((string)$deal['created_at']), 'd/m/Y')) ?></span></div>
  </div>
</div>
<?php if ($dealEdit): ?>
<div class="panel js-only">
  <div class="kv">
    <span>Valor estimado</span><div><?= inline_field('deal', $id, 'value_estimate', $deal['value_estimate'] !== null ? e(eur0((float)$deal['value_estimate'])) : '', ['edit' => true, 'type' => 'money', 'label' => 'Editar valor estimado', 'value' => $deal['value_estimate'] !== null ? number_format((float)$deal['value_estimate'], 2, ',', '') : '']) ?></div>
    <span>Notas</span><div><?= inline_field('deal', $id, 'notes', e((string)$deal['notes']), ['edit' => true, 'type' => 'textarea', 'label' => 'Editar notas', 'value' => (string)$deal['notes']]) ?></div>
  </div>
</div>
<noscript>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="save_info"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="anchor" value="info">
  <div class="grid">
    <div class="field"><label for="nn">Nome</label><input type="text" id="nn" name="name" maxlength="160" value="<?= e($deal['name']) ?>"></div>
    <div class="field"><label>ID</label><input type="text" value="<?= e($deal['code']) ?>" disabled></div>
    <div class="field wide"><label for="nnotes">Notas</label><textarea id="nnotes" name="notes"><?= e((string)$deal['notes']) ?></textarea></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar</button></div>
</form>
</noscript>
<?php else: ?>
  <p><?= nl2br(e((string)$deal['notes'])) ?: '<span class="muted">Sem notas.</span>' ?></p>
<?php endif; ?>

<h2 id="contactos">Contactos</h2>
<div class="tablewrap"><table>
  <thead><tr><th>Nome</th><th>Papel</th><th class="hide-sm">Email</th><th class="hide-sm">Telefone</th><th></th></tr></thead>
  <tbody>
  <?php if (!$contacts): ?><tr><td colspan="5" class="empty">Sem contactos ligados.</td></tr><?php endif; ?>
  <?php foreach ($contacts as $c): ?>
    <tr><td><a class="b" style="color:inherit" href="/admin/contact.php?id=<?= (int)$c['id'] ?>"<?= can('contactos') ? ' data-panel="contact:' . (int)$c['id'] . '"' : '' ?>><?= e($c['name']) ?></a>
          <?= (int)$c['id'] === (int)$deal['contact_id'] ? ' <span class="chip">cliente</span>' : '' ?></td>
      <td><?= e($c['link_role'] ?: '—') ?></td>
      <td class="hide-sm"><?= $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '—' ?></td>
      <td class="hide-sm"><?= e($c['phone'] ?: '—') ?></td>
      <td class="num"><?php if ($canEdit): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="unlink_contact">
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="anchor" value="contactos">
          <button class="btn ghost sm" type="submit">Desligar</button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if ($canEdit && $allContacts): ?>
<form method="post" class="bar">
  <?= csrf_field() ?><input type="hidden" name="do" value="link_contact"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="anchor" value="contactos">
  <select name="contact_id" required aria-label="Contacto"><option value="">Ligar contacto…</option>
    <?php foreach ($allContacts as $cid => $cn): ?><option value="<?= (int)$cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
  </select>
  <input type="text" name="role" maxlength="80" placeholder="Papel (ex.: produtor)">
  <button class="btn sm" type="submit">Ligar</button>
  <a class="lnk" href="/admin/contactos.php?novo=1">Novo contacto</a>
</form>
<?php endif; ?>

<h2 id="orcamentacao">Orçamentação</h2>
<p class="muted">Os orçamentos feitos na aba Orçamentação vão aparecer aqui. A aba ainda está em construção.</p>

<?php if ($money): ?>
<h2 id="financeiro">Contas</h2>
<?php if ($moneyEdit): ?>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="save_finance"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="anchor" value="financeiro">
  <div class="grid">
    <div class="field"><label for="ft">Tipo</label><select id="ft" name="job_type"><option value="">—</option>
      <?php foreach (DEAL_JOB_TYPES as $t): ?><option <?= $deal['job_type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="fm">Mês</label><select id="fm" name="month"><option value="">—</option>
      <?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>" <?= (int)$deal['month'] === $i ? 'selected' : '' ?>><?= e(month_name($i)) ?></option><?php endfor; ?></select></div>
    <div class="field"><label for="fy">Ano</label><input type="number" id="fy" name="year" min="2000" max="2100" value="<?= e((string)($deal['year'] ?? date('Y'))) ?>"></div>
    <div class="field"><label for="fb">Budget (€)</label><input type="text" id="fb" name="budget" inputmode="decimal" value="<?= e(number_format((float)$deal['budget'], 2, ',', '')) ?>"></div>
    <div class="field wide">
      <label class="check"><input type="checkbox" name="budget_incl_vat" value="1"> O valor escrito já inclui IVA (guarda-se sem IVA)</label>
      <label class="check"><input type="checkbox" name="has_vat" value="1" <?= (int)$deal['has_vat'] ? 'checked' : '' ?>> Fatura com IVA</label>
      <label class="check"><input type="checkbox" name="is_international" value="1" <?= (int)$deal['is_international'] ? 'checked' : '' ?>> Internacional</label>
      <label class="check"><input type="checkbox" name="is_paid" value="1" <?= (int)$deal['is_paid'] ? 'checked' : '' ?>> Pago</label>
    </div>
    <div class="field"><label for="fem">Gastos</label><select id="fem" name="expenses_mode">
      <option value="crew" <?= $deal['expenses_mode'] === 'crew' ? 'selected' : '' ?>>Soma dos custos</option>
      <option value="manual" <?= $deal['expenses_mode'] === 'manual' ? 'selected' : '' ?>>Valor manual</option></select></div>
    <div class="field"><label for="fmv">Gastos manuais (€)</label><input type="text" id="fmv" name="expenses_manual" inputmode="decimal" value="<?= e(number_format((float)$deal['expenses_manual'], 2, ',', '')) ?>"></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar contas</button></div>
</form>
<?php endif; ?>

<h2 id="custos">Custos do negócio</h2>
<div class="tablewrap"><table>
  <thead><tr><th>Pessoa</th><th>Função</th><th class="num">Valor</th><th>O valor é</th><th class="num hide-sm">s/ IVA</th><th class="num hide-sm">IVA</th><th>Estado</th><th></th></tr></thead>
  <tbody>
  <?php if (!$costs): ?><tr><td colspan="8" class="empty">Sem custos.</td></tr><?php endif; ?>
  <?php foreach ($costs as $c): ?>
    <tr id="cost-<?= (int)$c['id'] ?>" class="<?= ($ec && (int)$ec['id'] === (int)$c['id']) || (int)($_GET['delcost'] ?? 0) === (int)$c['id'] ? 'pending' : '' ?>">
      <td><?= e($c['name'] ?: '—') ?><?= $c['contact_name'] ? '<div class="muted" style="font-size:0.62rem">' . e($c['contact_name']) . (isset($rated[(int)$c['id']]) ? ' · <span class="stars" aria-label="Avaliado ' . (int)$rated[(int)$c['id']] . ' de 5">' . str_repeat('★', (int)$rated[(int)$c['id']]) . '</span>' : '') . '</div>' : '' ?></td>
      <td><?= e($c['role'] ?: '—') ?></td>
      <td class="num"><?= eur((float)$c['amount']) ?></td>
      <td><?= e(VAT_MODES[$c['vat_mode']] ?? $c['vat_mode']) ?></td>
      <td class="num hide-sm"><?= eur($c['net']) ?></td>
      <td class="num hide-sm"><?= eur($c['vat']) ?></td>
      <td><?php if ($moneyEdit): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_cost"><input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="cost_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="anchor" value="custos">
          <label class="check"><input type="checkbox" onchange="this.form.submit()" <?= (int)$c['is_closed'] ? 'checked' : '' ?>> Fechado</label></form>
      <?php else: ?><?= (int)$c['is_closed'] ? 'Fechado' : 'Aberto' ?><?php endif; ?></td>
      <td class="num nowrap"><?php if ($moneyEdit): ?>
        <?php if ((int)($_GET['delcost'] ?? 0) === (int)$c['id']): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="del_cost"><input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="cost_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="anchor" value="custos">
            <button class="btn danger sm" type="submit">Sim, eliminar</button></form>
          <a class="lnk" href="<?= e($self) ?>#custos">Cancelar</a>
        <?php else: ?>
          <a class="lnk" href="<?= e($self . '&cost=' . $c['id']) ?>#custo">Editar</a>
          <a class="lnk del" href="<?= e($self . '&delcost=' . $c['id']) ?>#custos">Eliminar</a>
        <?php endif; ?>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<?php if ($moneyEdit): $cv = $ec ?? ['name' => '', 'role' => '', 'amount' => '', 'vat_mode' => 'plus', 'is_closed' => 0, 'notes' => '', 'contact_id' => null]; ?>
<h2 id="custo"><?= $ec ? 'Editar custo' : 'Adicionar custo' ?></h2>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="<?= $ec ? 'save_cost' : 'add_cost' ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="anchor" value="custos">
  <?php if ($ec): ?><input type="hidden" name="cost_id" value="<?= (int)$ec['id'] ?>"><?php endif; ?>
  <div class="grid">
    <div class="field"><label for="cn">Pessoa</label><input type="text" id="cn" name="name" maxlength="160" value="<?= e($cv['name']) ?>"></div>
    <div class="field"><label for="cr">Função</label><input type="text" id="cr" name="role" maxlength="120" value="<?= e($cv['role']) ?>" placeholder="Diretor de Fotografia"></div>
    <div class="field"><label for="ca">Valor (€)</label><input type="text" id="ca" name="amount" inputmode="decimal" required value="<?= $cv['amount'] !== '' ? e(number_format((float)$cv['amount'], 2, ',', '')) : '' ?>"></div>
    <div class="field"><label for="cvm">O valor é</label><select id="cvm" name="vat_mode">
      <?php foreach (VAT_MODES as $k => $l): ?><option value="<?= $k ?>" <?= $cv['vat_mode'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="cc">Fornecedor (contacto)</label><select id="cc" name="contact_id"><option value="">—</option>
      <?php foreach ($allContacts as $cid => $cn): ?><option value="<?= (int)$cid ?>" <?= (string)$cv['contact_id'] === (string)$cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="check" style="margin-top:1.6rem"><input type="checkbox" name="is_closed" value="1" <?= (int)$cv['is_closed'] ? 'checked' : '' ?>> Fechado</label></div>
    <div class="field wide"><label for="cno">Notas</label><input type="text" id="cno" name="notes" maxlength="2000" value="<?= e((string)$cv['notes']) ?>"></div>
  </div>
  <div class="actions"><button class="btn" type="submit"><?= $ec ? 'Guardar' : 'Adicionar' ?></button>
    <?php if ($ec): ?><a class="btn ghost" href="<?= e($self) ?>#custos">Cancelar</a><?php endif; ?></div>
</form>
<?php endif; ?>
<?php endif; ?>

<h2 id="ficheiros">Ficheiros</h2>
<?php if (can('drive')): ?>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="drive"><input type="hidden" name="id" value="<?= $id ?>">
  <button class="btn ghost" type="submit">Abrir a pasta no Drive</button>
  <span class="muted" style="font-size:0.75rem"><?= e($deal['drive_folder'] ?: 'Projetos/' . $deal['code'] . '_…/') ?></span>
</form>
<?php else: ?><p class="muted">Sem acesso ao Drive.</p><?php endif; ?>

<h2 id="criativos">Criativos</h2>
<p class="muted">Briefings, versões e aprovações do Centro Criativo vão aparecer aqui. A aba ainda está em construção.</p>

<h2 id="tarefas">Tarefas</h2>
<div class="tablewrap"><table>
  <thead><tr><th>Tarefa</th><th>Responsável</th><th>Prazo</th><th>Estado</th></tr></thead>
  <tbody>
  <?php if (!$tasks): ?><tr><td colspan="4" class="empty">Sem tarefas ligadas a este negócio.</td></tr><?php endif; ?>
  <?php foreach ($tasks as $t): ?>
    <tr class="<?= $t['status'] === 'done' ? 'muted' : '' ?>"><td><?= e($t['title']) ?></td><td><?= e($t['who'] ?? '—') ?></td>
      <td><?= $t['due_date'] ? e(date('d/m/Y', strtotime($t['due_date']))) : '—' ?></td>
      <td><?= e(['todo' => 'A fazer', 'doing' => 'Em curso', 'review' => 'Em revisão', 'done' => 'Feito'][$t['status']] ?? $t['status']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<?= history_block($pdo, 'deal', $id, $canEdit, $self . '#historico') ?>

<?php if ($rateCost): ?>
<div class="mdl open" id="mdl-rate" data-from="#cost-<?= (int)$rateCost['id'] ?> .check" data-clear="avaliar" role="dialog" aria-modal="true" aria-labelledby="mdl-rate-t">
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card" method="post" action="<?= e($self) ?>">
    <?= csrf_field() ?><input type="hidden" name="do" value="rate_cost"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="cost_id" value="<?= (int)$rateCost['id'] ?>"><input type="hidden" name="anchor" value="custos">
    <h3 id="mdl-rate-t">Como correu o trabalho?</h3>
    <p class="muted"><?= e($rateCost['contact_name']) ?> · <?= e($rateCost['role'] ?: $rateCost['name']) ?>. A avaliação do fornecedor é a média real dos trabalhos.</p>
    <div class="rate-stars" role="radiogroup" aria-label="Avaliação de 1 a 5">
      <?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" id="rs-<?= $i ?>" name="stars" value="<?= $i ?>" required><label for="rs-<?= $i ?>" data-tip="<?= $i ?> de 5" aria-label="<?= $i ?> de 5">★</label><?php endfor; ?>
    </div>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Agora não</button><button class="btn" type="submit"><?= icon('star') ?> Avaliar</button></div>
  </form>
</div>
<?php endif; ?>

<?php render_footer();
