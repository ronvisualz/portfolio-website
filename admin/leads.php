<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/paginate.php';
require __DIR__ . '/inc/leads.php';
$user = require_login();
require_page('leads');

// As leads vivem em MySQL (tabela deals, phase = 'lead'). Separadores:
// Ativas · Por atribuir · Arquivadas · Quarentena. Quem vê o quê vem do
// âmbito da aba Leads (inc/perm.php); a fila sem dono é dos gestores.

const VIEWS = ['ativas' => 'Ativas', 'fila' => 'Por atribuir', 'arquivadas' => 'Arquivadas', 'quarentena' => 'Quarentena'];

function lead_filters(array $src): array {
    $f = [];
    foreach (['v', 'q', 'estado', 'tipo', 'prio', 'origem', 'dono', 'pl'] as $k) {
        $v = trim((string)($src[$k] ?? ''));
        if ($v !== '') $f[$k] = $v;
    }
    return $f;
}
function leads_url(array $f = []): string {
    return '/admin/leads.php' . ($f ? '?' . http_build_query($f) : '');
}
function back_fields(array $f): string {
    $h = '';
    foreach ($f as $k => $v) $h .= '<input type="hidden" name="back[' . e($k) . ']" value="' . e($v) . '">';
    return $h;
}

$me = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$me->execute([(int)$user['id']]);
$me = $me->fetch();
$manager = sees_unassigned($me);

/**
 * O dono escolhido no formulário, validado pelas regras de atribuição.
 * $current: o dono atual (null = sem dono); numa lead nova, quem a cria.
 * Uma escolha não permitida mantém o dono atual e avisa.
 */
function owner_from_post(PDO $pdo, array $me, ?int $current): ?int {
    $raw = (string)($_POST['owner_id'] ?? '');
    if ($raw === '') return $current;
    if ($raw === 'none') {
        if (sees_unassigned($me)) return null;
        flash('Só admins e chefes de equipa podem deixar uma lead por atribuir.', 'err');
        return $current;
    }
    $id = (int)$raw;
    if ($id === $current) return $current;
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t || !can_assign_to($me, $t)) {
        flash('Não pode atribuir a essa pessoa.', 'err');
        return $current;
    }
    return $id;
}

// ── ações ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do   = (string)($_POST['do'] ?? '');
    $back = leads_url(lead_filters(is_array($_POST['back'] ?? null) ? $_POST['back'] : []));

    if ($do === 'stale_days') {
        require_perm('leads', PERM_MANAGE);
        $n = max(1, min(365, (int)($_POST['days'] ?? 14)));
        $pdo->prepare(db_is_sqlite($pdo)
            ? "INSERT OR REPLACE INTO settings (skey, svalue) VALUES ('lead_stale_days', ?)"
            : "INSERT INTO settings (skey, svalue) VALUES ('lead_stale_days', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)"
        )->execute([(string)$n]);
        flash("Alerta de lead parada: $n dias.");
        redirect($back);
    }

    if ($do === 'create') {
        $v = lead_input($_POST);
        $dups = find_duplicates($pdo, $v['email'], $v['phone']);
        if (($dups['deals'] || $dups['contacts']) && empty($_POST['confirm_dup'])) {
            $_SESSION['lead_dup'] = $_POST;
            redirect(leads_url(['dup' => 1]) . '#duplicados');
        }
        $owner = owner_from_post($pdo, $me, (int)$me['id']);
        $id = lead_create($pdo, $v, $owner, (int)$me['id']);
        $cid = (int)($_POST['contact_id'] ?? 0);
        if ($cid > 0) $pdo->prepare('UPDATE deals SET contact_id = ? WHERE id = ?')->execute([$cid, $id]);
        unset($_SESSION['lead_dup']);
        flash($owner === null ? 'Lead criada na fila «Por atribuir».' : 'Lead criada.');
        redirect($back);
    }

    // As restantes ações são sobre um registo: tem de estar ao meu alcance.
    $lead = lead_get($pdo, (int)($_POST['id'] ?? 0));
    if (!$lead || !lead_in_reach($pdo, $lead, $me)) {
        flash('Lead não encontrada.', 'err');
        redirect($back);
    }
    $id = (int)$lead['id'];

    if ($do === 'merge') {
        $v = $_SESSION['lead_dup'] ?? null;
        if (!$v || $lead['phase'] !== 'lead') { flash('Nada para juntar.', 'err'); redirect($back); }
        lead_merge_into($pdo, $lead, lead_input($v));
        unset($_SESSION['lead_dup']);
        flash('Os dados foram juntados à lead «' . $lead['name'] . '».');
        redirect(leads_url(['edit' => $id]) . '#lead');
    }

    if ($do === 'state') {
        $s = (string)($_POST['lead_state'] ?? '');
        if (isset(LEAD_STATE[$s]) && $lead['phase'] === 'lead') {
            $pdo->prepare('UPDATE deals SET lead_state = ?, last_touch = ? WHERE id = ?')->execute([$s, today_lisbon(), $id]);
        }
        redirect($back);
    }

    if ($do === 'assign') {
        $owner = owner_from_post($pdo, $me, $lead['owner_id'] !== null ? (int)$lead['owner_id'] : null);
        if ((string)$owner !== (string)$lead['owner_id']) {
            $pdo->prepare('UPDATE deals SET owner_id = ? WHERE id = ?')->execute([$owner, $id]);
            deal_log($pdo, $id, 'assign', ['note' => 'Responsável: ' . ($owner === null ? 'por atribuir' : $pdo->query('SELECT name FROM users WHERE id = ' . (int)$owner)->fetchColumn())]);
            flash('Responsável atualizado.');
        }
        redirect($back);
    }

    if ($do === 'save') {
        $v = lead_input($_POST);
        $s = (string)($_POST['lead_state'] ?? $lead['lead_state']);
        $s = isset(LEAD_STATE[$s]) ? $s : $lead['lead_state'];
        $touch = (string)($_POST['last_touch'] ?? '');
        $touch = preg_match('/^\d{4}-\d{2}-\d{2}$/', $touch) ? $touch : $lead['last_touch'];
        if ($s !== $lead['lead_state']) $touch = today_lisbon();
        $owner = owner_from_post($pdo, $me, $lead['owner_id'] !== null ? (int)$lead['owner_id'] : null);
        $pdo->prepare(
            'UPDATE deals SET name=?, types=?, links=?, phone=?, email=?, service=?, priority=?, detail=?, why=?, notes=?,
                              origin=?, campaign=?, lead_state=?, last_touch=?, owner_id=? WHERE id=?'
        )->execute([$v['name'], $v['types'], $v['links'], $v['phone'], $v['email'], $v['service'], $v['priority'],
                    $v['detail'], $v['why'], $v['notes'], $v['origin'], $v['campaign'], $s, $touch, $owner, $id]);
        flash('Lead guardada.');
        redirect($back);
    }

    if ($do === 'archive') {
        $r = (string)($_POST['reason'] ?? '');
        if (!isset(ARCHIVE_REASONS[$r]) || $r === 'perdido_migracao') { flash('Escolha o motivo.', 'err'); redirect($back); }
        $pdo->prepare('UPDATE deals SET phase = \'archived\', archived_reason = ?, archived_note = ?, archived_at = ' . now_sql($pdo) . ' WHERE id = ? AND phase = \'lead\'')
            ->execute([$r, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255), $id]);
        deal_log($pdo, $id, 'archive', ['note' => ARCHIVE_REASONS[$r]]);
        flash('Lead arquivada.');
        redirect($back);
    }

    if ($do === 'reactivate') {
        $pdo->prepare('UPDATE deals SET phase = \'lead\', archived_reason = \'\', archived_note = \'\', archived_at = NULL, is_quarantined = 0, last_touch = ? WHERE id = ? AND phase = \'archived\'')
            ->execute([today_lisbon(), $id]);
        deal_log($pdo, $id, 'reactivate');
        flash('Lead reativada.');
        redirect($back);
    }

    if ($do === 'release') {   // tirar da quarentena
        $pdo->prepare('UPDATE deals SET is_quarantined = 0 WHERE id = ?')->execute([$id]);
        flash('Lead libertada da quarentena.');
        redirect($back);
    }

    if ($do === 'convert') {
        if ($lead['phase'] !== 'lead') { flash('Esta lead já não está ativa.', 'err'); redirect($back); }
        require_perm('pipeline', PERM_EDIT);
        $funnel = (int)($_POST['funnel_id'] ?? 0);
        $st = $pdo->prepare('SELECT COUNT(*) FROM funnels WHERE id = ? AND is_active = 1');
        $st->execute([$funnel]);
        if (!(int)$st->fetchColumn()) { flash('Escolha o funil.', 'err'); redirect(leads_url(['conv' => $id]) . '#converter'); }
        try {
            $code = lead_convert($pdo, $lead, $funnel, (int)($_POST['contact_id'] ?? 0));
        } catch (Throwable $e) {
            flash('Não foi possível converter: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'erro na base de dados.'), 'err');
            redirect($back);
        }
        flash("Lead convertida: $code entrou no Pipeline, na etapa X-Ray.");
        redirect($back);
    }

    if ($do === 'delete') {
        require_perm('leads', PERM_MANAGE);
        if (!in_array($lead['phase'], ['lead', 'archived'], true)) { flash('Só se eliminam leads.', 'err'); redirect($back); }
        $pdo->prepare('DELETE FROM deals WHERE id = ?')->execute([$id]);
        flash('Lead eliminada.');
        redirect($back);
    }

    redirect($back);
}

// ── dados ───────────────────────────────────────────────────
$filters = lead_filters($_GET);
$view = $filters['v'] ?? 'ativas';
if (!isset(VIEWS[$view]) || (in_array($view, ['fila', 'quarentena'], true) && !$manager)) $view = 'ativas';

[$scopeSql, $scopeArgs] = scope_sql($pdo, 'leads', 'd.owner_id');
$reach = $manager ? "($scopeSql OR d.owner_id IS NULL)" : $scopeSql;

$where = ['1=1']; $args = [];
switch ($view) {
    case 'fila':       $where[] = "d.phase = 'lead' AND d.owner_id IS NULL AND d.is_quarantined = 0"; break;
    case 'quarentena': $where[] = "d.phase = 'lead' AND d.is_quarantined = 1"; break;
    case 'arquivadas': $where[] = "d.phase = 'archived'"; $where[] = $reach; $args = $scopeArgs; break;
    default:           $where[] = "d.phase = 'lead' AND d.owner_id IS NOT NULL AND d.is_quarantined = 0"; $where[] = $scopeSql; $args = $scopeArgs;
}
$stmt = $pdo->prepare('SELECT d.*, u.name AS owner_name FROM deals d LEFT JOIN users u ON u.id = d.owner_id WHERE '
                    . implode(' AND ', $where));
$stmt->execute($args);
$all = $stmt->fetchAll();

// Contagens dos separadores (dentro do que posso ver).
$cnt = static function (string $cond, bool $scoped) use ($pdo, $scopeSql, $scopeArgs, $reach) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM deals d WHERE $cond" . ($scoped ? " AND $reach" : ''));
    $st->execute($scoped ? $scopeArgs : []);
    return (int)$st->fetchColumn();
};
$counts = [
    'ativas'     => (function () use ($pdo, $scopeSql, $scopeArgs) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM deals d WHERE d.phase = 'lead' AND d.owner_id IS NOT NULL AND d.is_quarantined = 0 AND $scopeSql");
        $st->execute($scopeArgs); return (int)$st->fetchColumn(); })(),
    'fila'       => $manager ? $cnt("d.phase = 'lead' AND d.owner_id IS NULL AND d.is_quarantined = 0", false) : 0,
    'arquivadas' => $cnt("d.phase = 'archived'", true),
    'quarentena' => $manager ? $cnt("d.phase = 'lead' AND d.is_quarantined = 1", false) : 0,
];

$staleDays = lead_stale_days($pdo);
$q = $filters['q'] ?? '';
$lc = static fn(string $s): string => mb_strtolower($s, 'UTF-8');
$needle = $lc($q);
$rows = array_values(array_filter($all, static function ($l) use ($filters, $needle, $lc) {
    if (($filters['estado'] ?? '') !== '' && $l['lead_state'] !== $filters['estado']) return false;
    if (($filters['prio'] ?? '') !== '' && $l['priority'] !== $filters['prio']) return false;
    if (($filters['origem'] ?? '') !== '' && $l['origin'] !== $filters['origem']) return false;
    if (($filters['dono'] ?? '') !== '' && (string)$l['owner_id'] !== $filters['dono']) return false;
    if (($filters['tipo'] ?? '') !== '' && !in_array($filters['tipo'], array_map('trim', explode(',', (string)$l['types'])), true)) return false;
    if ($needle !== '') {
        $hay = $lc(implode(' ', [$l['name'], $l['links'], $l['notes'], $l['detail'], $l['why'], $l['email'], $l['phone'],
                                 $l['types'], $l['service'], $l['campaign']]));
        if (strpos($hay, $needle) === false) return false;
    }
    return true;
}));
$order = ['em_conversa' => 0, 'contactado' => 1, 'novo' => 2];
usort($rows, static function ($a, $b) use ($order, $view) {
    if ($view === 'arquivadas') return strcmp((string)$b['archived_at'], (string)$a['archived_at']);
    return [$order[$a['lead_state']] ?? 9, (string)$b['last_touch'], $a['name']]
       <=> [$order[$b['lead_state']] ?? 9, (string)$a['last_touch'], $b['name']];
});

$stateCount = array_fill_keys(array_keys(LEAD_STATE), 0);
foreach ($all as $l) if ($l['phase'] === 'lead' && isset($stateCount[$l['lead_state']])) $stateCount[$l['lead_state']]++;
$staleCount = count(array_filter($all, fn($l) => $l['phase'] === 'lead' && lead_is_stale($l, $staleDays)));

$pending = (int)($_GET['del'] ?? 0);
$editId  = (int)($_GET['edit'] ?? 0);
$archId  = (int)($_GET['arch'] ?? 0);
$convId  = (int)($_GET['conv'] ?? 0);
$el = $editId ? lead_get($pdo, $editId) : null;
if ($el && !lead_in_reach($pdo, $el, $me)) $el = null;

foreach ($rows as $n => $l) {
    if (in_array((int)$l['id'], [$pending, $editId, $archId, $convId], true)) $_GET['pl'] = intdiv($n, PAGE_SIZE) + 1;
}
$pg = paginate($rows, 'pl');
if ($pg['page'] > 1) $filters['pl'] = (string)$pg['page']; else unset($filters['pl']);

$assignees = assignable_users($pdo, $me);
$owners = $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);

function owner_select(array $assignees, ?int $current, bool $manager, string $name = 'owner_id', array $owners = []): string {
    $h = '<select name="' . $name . '" aria-label="Responsável">';
    if ($manager) $h .= '<option value="none"' . ($current === null ? ' selected' : '') . '>— Por atribuir —</option>';
    $ids = array_map(fn($u) => (int)$u['id'], $assignees);
    if ($current !== null && !in_array($current, $ids, true)) {
        $h .= '<option value="' . $current . '" selected>' . e($owners[$current] ?? '—') . '</option>';
    }
    foreach ($assignees as $u) {
        $h .= '<option value="' . (int)$u['id'] . '"' . ($current === (int)$u['id'] ? ' selected' : '') . '>' . e($u['name']) . '</option>';
    }
    return $h . '</select>';
}

function contact_links(string $links): string {
    $out = [];
    foreach (array_filter(array_map('trim', explode('|', $links)), 'strlen') as $c) {
        $url = preg_match('~^https?://~i', $c) ? $c
             : (preg_match('~^(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(/\S*)?$~i', $c) ? 'https://' . $c : '');
        $out[] = $url === '' ? '<span class="muted">' . e($c) . '</span>'
            : '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer nofollow">'
              . e(shorten((string)preg_replace('~^https?://(www\.)?~i', '', rtrim($c, '/')), 30)) . '</a>';
    }
    return implode('<br>', $out);
}

$canEdit   = can('leads', PERM_EDIT);
$canManage = can('leads', PERM_MANAGE);
$funnels   = $pdo->query('SELECT id, name FROM funnels WHERE is_active = 1 ORDER BY position, name')->fetchAll();

render_header('Leads', 'leads');
render_flashes();
echo paginate_css();
?>
<h1>Leads</h1>
<div class="sub"><?= $counts['ativas'] ?> leads ativas<?= $staleCount ? ' · <b>' . $staleCount . ' parada' . ($staleCount > 1 ? 's' : '') . '</b> há mais de ' . $staleDays . ' dias' : '' ?></div>

<div class="stats" style="margin-bottom:1.6rem">
  <?php foreach (LEAD_STATE as $k => $label): ?>
    <a class="stat" href="<?= e(leads_url(['estado' => $k])) ?>"><div class="k"><?= e($label) ?></div><div class="v"><?= $stateCount[$k] ?></div></a>
  <?php endforeach; ?>
  <?php if ($manager): ?>
    <a class="stat" href="<?= e(leads_url(['v' => 'fila'])) ?>"><div class="k">Por atribuir</div><div class="v"><?= $counts['fila'] ?></div></a>
  <?php endif; ?>
</div>

<div class="tabs">
  <?php foreach (VIEWS as $k => $label): if (in_array($k, ['fila', 'quarentena'], true) && !$manager) continue; ?>
    <a href="<?= e(leads_url($k === 'ativas' ? [] : ['v' => $k])) ?>" class="<?= $view === $k ? 'on' : '' ?>"><?= e($label) ?> (<?= $counts[$k] ?>)</a>
  <?php endforeach; ?>
</div>

<?php // ── duplicados: avisar e propor juntar ──
if (!empty($_GET['dup']) && !empty($_SESSION['lead_dup'])):
    $dv = lead_input($_SESSION['lead_dup']);
    $dups = find_duplicates($pdo, $dv['email'], $dv['phone']);
?>
<div class="flash err" id="duplicados">
  <b>Já existe alguém com este email ou telefone.</b> Junte a nova informação a um registo existente ou crie a lead mesmo assim.
  <ul style="margin:0.6rem 0">
  <?php foreach ($dups['deals'] as $d): $reachable = lead_in_reach($pdo, $d, $me); ?>
    <li><b><?= e($d['name']) ?></b> <?= $d['code'] ? '(' . e($d['code']) . ')' : '' ?> · <?= e(['lead' => 'lead', 'archived' => 'lead arquivada', 'pipeline' => 'no Pipeline', 'production' => 'em produção', 'done' => 'concluído', 'lost' => 'perdido'][$d['phase']] ?? $d['phase']) ?>
      <?php if ($d['phase'] === 'lead' && $reachable): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="do" value="merge"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button class="btn sm" type="submit">Juntar a esta lead</button></form>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  <?php foreach ($dups['contacts'] as $c): ?>
    <li>Contacto <b><?= e($c['name']) ?></b>
      <form method="post" style="display:inline"><?= csrf_field() ?>
        <?php foreach ($_SESSION['lead_dup'] as $k => $val): if (in_array($k, ['csrf', 'contact_id', 'confirm_dup'], true)) continue;
              foreach ((array)$val as $kk => $vv): ?>
          <input type="hidden" name="<?= e(is_array($val) ? $k . '[' . $kk . ']' : $k) ?>" value="<?= e((string)$vv) ?>">
        <?php endforeach; endforeach; ?>
        <input type="hidden" name="confirm_dup" value="1"><input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
        <button class="btn sm" type="submit">Criar lead ligada a este contacto</button></form>
    </li>
  <?php endforeach; ?>
  </ul>
  <form method="post" style="display:inline"><?= csrf_field() ?>
    <?php foreach ($_SESSION['lead_dup'] as $k => $val): if (in_array($k, ['csrf', 'confirm_dup'], true)) continue;
          foreach ((array)$val as $kk => $vv): ?>
      <input type="hidden" name="<?= e(is_array($val) ? $k . '[' . $kk . ']' : $k) ?>" value="<?= e((string)$vv) ?>">
    <?php endforeach; endforeach; ?>
    <input type="hidden" name="confirm_dup" value="1">
    <button class="btn ghost sm" type="submit">Criar mesmo assim</button></form>
  <a class="lnk" href="<?= e(leads_url()) ?>">Cancelar</a>
</div>
<?php endif; ?>

<form class="bar" method="get">
  <?php if ($view !== 'ativas'): ?><input type="hidden" name="v" value="<?= e($view) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Procurar nome, contacto, nota">
  <?php if ($view !== 'arquivadas'): ?>
  <select name="estado" onchange="this.form.submit()" aria-label="Estado">
    <option value="">Todos os estados</option>
    <?php foreach (LEAD_STATE as $k => $l): ?><option value="<?= $k ?>" <?= ($filters['estado'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select name="tipo" onchange="this.form.submit()" aria-label="Tipo">
    <option value="">Todos os tipos</option>
    <?php foreach (LEAD_TYPES_LIST as $t): ?><option <?= ($filters['tipo'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
  </select>
  <select name="prio" onchange="this.form.submit()" aria-label="Prioridade">
    <option value="">Prioridade</option>
    <?php foreach (LEAD_PRIORITY as $k => $l): ?><option value="<?= $k ?>" <?= ($filters['prio'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <select name="origem" onchange="this.form.submit()" aria-label="Origem">
    <option value="">Origem</option>
    <?php foreach (LEAD_ORIGIN as $k => $l): ?><option value="<?= $k ?>" <?= ($filters['origem'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <?php if (my_scope('leads') !== 'own' && $view !== 'fila'): ?>
  <select name="dono" onchange="this.form.submit()" aria-label="Responsável">
    <option value="">Responsável</option>
    <?php foreach ($owners as $oid => $on): ?><option value="<?= (int)$oid ?>" <?= ($filters['dono'] ?? '') === (string)$oid ? 'selected' : '' ?>><?= e($on) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button class="btn sm" type="submit">Filtrar</button>
  <?php if (array_diff_key($filters, ['pl' => 1, 'v' => 1])): ?><a class="lnk" href="<?= e(leads_url($view === 'ativas' ? [] : ['v' => $view])) ?>">Limpar</a><?php endif; ?>
</form>

<div class="tablewrap">
<table>
  <thead>
    <tr><th>Nome</th><th class="hide-sm">Contactos</th><th class="hide-sm">Notas</th>
        <th><?= $view === 'arquivadas' ? 'Motivo' : 'Estado' ?></th><th>Responsável</th><th class="hide-sm">Último contacto</th><th></th></tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nenhuma lead aqui.</td></tr><?php endif; ?>
  <?php foreach ($pg['rows'] as $l): $lid = (int)$l['id']; $stale = $l['phase'] === 'lead' && lead_is_stale($l, $staleDays); ?>
    <tr class="<?= in_array($lid, [$pending, $editId, $archId, $convId], true) ? 'pending' : '' ?> <?= $stale ? 'stale' : '' ?>">
      <td>
        <span class="b"><?= e($l['name']) ?></span>
        <div class="muted" style="font-size:0.62rem">
          <?= implode(' · ', array_filter([
                e((string)$l['types']), e((string)$l['service']),
                $l['origin'] !== 'manual' ? '<span class="chip">' . e(LEAD_ORIGIN[$l['origin']]) . ($l['campaign'] ? ': ' . e($l['campaign']) : '') . '</span>' : '',
                $l['priority'] === 'alta' ? '<span class="chip no">Alta</span>' : '',
              ], 'strlen')) ?>
        </div>
      </td>
      <td class="hide-sm" style="font-size:0.7rem">
        <?php if ($l['email']): ?><div><a href="mailto:<?= e($l['email']) ?>"><?= e(shorten($l['email'], 30)) ?></a></div><?php endif; ?>
        <?php if ($l['phone']): ?><div><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $l['phone'])) ?>"><?= e($l['phone']) ?></a></div><?php endif; ?>
        <?= contact_links((string)$l['links']) ?>
      </td>
      <td class="muted hide-sm" style="font-size:0.7rem"><?= e(shorten((string)$l['notes'], 55)) ?>
        <?php if ($l['why']): ?><div><b>Porquê:</b> <?= e(shorten((string)$l['why'], 40)) ?></div><?php endif; ?></td>
      <td>
        <?php if ($view === 'arquivadas'): ?>
          <?= e(ARCHIVE_REASONS[$l['archived_reason']] ?? $l['archived_reason']) ?>
          <?php if ($l['archived_note']): ?><div class="muted" style="font-size:0.62rem"><?= e(shorten($l['archived_note'], 40)) ?></div><?php endif; ?>
        <?php elseif ($canEdit): ?>
          <form method="post"><?= csrf_field() ?><?= back_fields($filters) ?>
            <input type="hidden" name="do" value="state"><input type="hidden" name="id" value="<?= $lid ?>">
            <select class="mini" name="lead_state" onchange="this.form.submit()" aria-label="Estado">
              <?php foreach (LEAD_STATE as $k => $lab): ?><option value="<?= $k ?>" <?= $l['lead_state'] === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?>
            </select><noscript><button class="btn sm">OK</button></noscript></form>
        <?php else: ?>
          <?= e(LEAD_STATE[$l['lead_state']]) ?>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($canEdit && $l['phase'] === 'lead' && ($manager || my_scope('leads') !== 'own')): ?>
          <form method="post"><?= csrf_field() ?><?= back_fields($filters) ?>
            <input type="hidden" name="do" value="assign"><input type="hidden" name="id" value="<?= $lid ?>">
            <?= str_replace('<select ', '<select class="mini" onchange="this.form.submit()" ', owner_select($assignees, $l['owner_id'] !== null ? (int)$l['owner_id'] : null, $manager, 'owner_id', $owners)) ?>
          </form>
        <?php else: ?>
          <?= e($l['owner_name'] ?? '—') ?>
        <?php endif; ?>
      </td>
      <td class="hide-sm nowrap"><?= e($l['last_touch'] ? date('d/m/Y', strtotime($l['last_touch'])) : '—') ?>
        <?php if ($stale): ?><div><span class="chip wait">parada</span></div><?php endif; ?></td>
      <td class="num nowrap">
      <?php if ($pending === $lid && $canManage): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><?= back_fields($filters) ?>
          <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $lid ?>">
          <button class="btn danger sm" type="submit">Sim, eliminar</button></form>
        <a class="lnk" href="<?= e(leads_url($filters)) ?>">Cancelar</a>
      <?php elseif ($canEdit): ?>
        <?php if ($l['phase'] === 'archived'): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><?= back_fields($filters) ?>
            <input type="hidden" name="do" value="reactivate"><input type="hidden" name="id" value="<?= $lid ?>">
            <button class="btn ghost sm" type="submit">Reativar</button></form>
        <?php else: ?>
          <?php if ((int)$l['is_quarantined'] === 1): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><?= back_fields($filters) ?>
              <input type="hidden" name="do" value="release"><input type="hidden" name="id" value="<?= $lid ?>">
              <button class="btn ghost sm" type="submit">Não é spam</button></form>
          <?php elseif (can('pipeline', PERM_EDIT)): ?>
            <a class="btn sm" href="<?= e(leads_url($filters + ['conv' => $lid])) ?>#converter">Converter</a>
          <?php endif; ?>
          <a class="lnk" href="<?= e(leads_url($filters + ['edit' => $lid])) ?>#lead">Editar</a>
          <a class="lnk" href="<?= e(leads_url($filters + ['arch' => $lid])) ?>#arquivar">Arquivar</a>
        <?php endif; ?>
        <?php if ($canManage): ?><a class="lnk del" href="<?= e(leads_url($filters + ['del' => $lid])) ?>">Eliminar</a><?php endif; ?>
      <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= page_nav($pg, ['del', 'edit', 'arch', 'conv']) ?>

<?php // ── converter ──
$cl = $convId ? lead_get($pdo, $convId) : null;
if ($cl && $cl['phase'] === 'lead' && lead_in_reach($pdo, $cl, $me) && can('pipeline', PERM_EDIT)):
    $cdup = find_duplicates($pdo, (string)$cl['email'], (string)$cl['phone'])['contacts'];
    if ($cl['contact_id']) {
        $st = $pdo->prepare('SELECT id, name, email, phone FROM contacts WHERE id = ?');
        $st->execute([(int)$cl['contact_id']]);
        if ($c = $st->fetch()) array_unshift($cdup, $c);
    }
    $cdup = array_values(array_column($cdup, null, 'id'));
?>
<h2 id="converter">Converter «<?= e($cl['name']) ?>»</h2>
<form method="post">
  <?= csrf_field() ?><?= back_fields($filters) ?>
  <input type="hidden" name="do" value="convert"><input type="hidden" name="id" value="<?= (int)$cl['id'] ?>">
  <div class="grid">
    <div class="field"><label for="cfunnel">Funil</label>
      <select id="cfunnel" name="funnel_id" required><option value="">Escolher…</option>
        <?php foreach ($funnels as $f): ?><option value="<?= (int)$f['id'] ?>"><?= e($f['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field wide"><label>Contacto</label>
      <?php foreach ($cdup as $i => $c): ?>
        <label class="check"><input type="radio" name="contact_id" value="<?= (int)$c['id'] ?>" <?= $i === 0 ? 'checked' : '' ?>>
          Associar a <b><?= e($c['name']) ?></b> <span class="muted"><?= e(trim($c['email'] . ' ' . $c['phone'])) ?></span></label>
      <?php endforeach; ?>
      <label class="check"><input type="radio" name="contact_id" value="0" <?= $cdup ? '' : 'checked' ?>>
        Criar o contacto «<?= e($cl['name']) ?>» (cliente)</label>
    </div>
  </div>
  <p class="muted" style="font-size:0.7rem">Ao converter, a lead recebe o ID do negócio (ex.: ID<?= date('ym') ?>-01), sai das Leads e entra no Pipeline na etapa X-Ray.</p>
  <div class="actions"><button class="btn" type="submit">Converter</button>
    <a class="btn ghost" href="<?= e(leads_url($filters)) ?>">Cancelar</a></div>
</form>
<?php endif; ?>

<?php // ── arquivar ──
$al = $archId ? lead_get($pdo, $archId) : null;
if ($al && $al['phase'] === 'lead' && lead_in_reach($pdo, $al, $me) && $canEdit): ?>
<h2 id="arquivar">Arquivar «<?= e($al['name']) ?>»</h2>
<form method="post">
  <?= csrf_field() ?><?= back_fields($filters) ?>
  <input type="hidden" name="do" value="archive"><input type="hidden" name="id" value="<?= (int)$al['id'] ?>">
  <div class="grid">
    <div class="field"><label for="areason">Motivo</label>
      <select id="areason" name="reason" required><option value="">Escolher…</option>
        <?php foreach (ARCHIVE_REASONS as $k => $lab): if ($k === 'perdido_migracao') continue; ?><option value="<?= $k ?>"><?= e($lab) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field wide"><label for="anote">Nota (opcional)</label>
      <input type="text" id="anote" name="note" maxlength="255"></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Arquivar</button>
    <a class="btn ghost" href="<?= e(leads_url($filters)) ?>">Cancelar</a></div>
</form>
<?php endif; ?>

<?php if ($canEdit):
  $v = $el ?? ['name' => '', 'types' => '', 'links' => '', 'phone' => '', 'email' => '', 'service' => '', 'priority' => 'media',
               'detail' => '', 'why' => '', 'notes' => '', 'origin' => 'manual', 'campaign' => '', 'lead_state' => 'novo',
               'last_touch' => '', 'owner_id' => (int)$me['id']];
  $vTypes = array_map('trim', explode(',', (string)$v['types']));
?>
<h2 id="lead"><?= $el ? 'Editar lead' : 'Adicionar lead' ?></h2>
<form method="post">
  <?= csrf_field() ?><?= back_fields($filters) ?>
  <?php if ($el): ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$el['id'] ?>">
  <?php else: ?><input type="hidden" name="do" value="create"><?php endif; ?>
  <div class="grid">
    <div class="field"><label for="lname">Nome</label>
      <input type="text" id="lname" name="name" required maxlength="160" value="<?= e($v['name']) ?>"></div>
    <div class="field"><label>Responsável</label>
      <?= owner_select($assignees, $v['owner_id'] !== null ? (int)$v['owner_id'] : null, $manager, 'owner_id', $owners) ?></div>
    <?php if ($el): ?>
    <div class="field"><label for="lstate">Estado</label>
      <select id="lstate" name="lead_state">
        <?php foreach (LEAD_STATE as $k => $lab): ?><option value="<?= $k ?>" <?= $v['lead_state'] === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <div class="field wide"><label>Tipo</label>
      <div class="actions" style="margin-top:0.2rem">
        <?php foreach (LEAD_TYPES_LIST as $t): ?>
          <label class="check"><input type="checkbox" name="types[]" value="<?= e($t) ?>" <?= in_array($t, $vTypes, true) ? 'checked' : '' ?>> <?= e($t) ?></label>
        <?php endforeach; ?>
      </div></div>
    <div class="field"><label for="lemail">Email</label>
      <input type="text" id="lemail" name="email" maxlength="190" value="<?= e($v['email']) ?>"></div>
    <div class="field"><label for="lphone">Telefone</label>
      <input type="text" id="lphone" name="phone" maxlength="40" inputmode="tel" value="<?= e($v['phone']) ?>"></div>
    <div class="field wide"><label for="llinks">Contactos / redes</label>
      <input type="text" id="llinks" name="links" value="<?= e($v['links']) ?>" placeholder="https://site.pt | instagram.com/…"></div>
    <div class="field"><label for="lservice">Serviço</label>
      <select id="lservice" name="service"><option value="">—</option>
        <?php foreach (LEAD_SERVICES_LIST as $s): ?><option <?= $v['service'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="lprio">Prioridade</label>
      <select id="lprio" name="priority">
        <?php foreach (LEAD_PRIORITY as $k => $lab): ?><option value="<?= $k ?>" <?= $v['priority'] === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="lorigin">Origem</label>
      <select id="lorigin" name="origin">
        <?php foreach (LEAD_ORIGIN as $k => $lab): ?><option value="<?= $k ?>" <?= $v['origin'] === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="lcamp">Campanha</label>
      <input type="text" id="lcamp" name="campaign" maxlength="160" value="<?= e($v['campaign']) ?>"></div>
    <div class="field wide"><label for="ldetail">Detalhe</label>
      <textarea id="ldetail" name="detail"><?= e($v['detail']) ?></textarea></div>
    <div class="field wide"><label for="lwhy">Porquê abordar</label>
      <textarea id="lwhy" name="why"><?= e($v['why']) ?></textarea></div>
    <div class="field wide"><label for="lnotes">Notas</label>
      <textarea id="lnotes" name="notes"><?= e($v['notes']) ?></textarea></div>
    <?php if ($el): ?>
    <div class="field"><label for="ltouch">Último contacto</label>
      <input type="date" id="ltouch" name="last_touch" value="<?= e((string)$v['last_touch']) ?>"></div>
    <?php endif; ?>
  </div>
  <div class="actions">
    <button class="btn" type="submit"><?= $el ? 'Guardar' : 'Adicionar' ?></button>
    <?php if ($el): ?><a class="btn ghost" href="<?= e(leads_url($filters)) ?>">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php if ($canManage): ?>
<form class="bar" method="post" style="margin-top:2rem">
  <?= csrf_field() ?><?= back_fields($filters) ?>
  <input type="hidden" name="do" value="stale_days">
  <label for="sdays" class="muted" style="font-size:0.75rem">Marcar como parada uma lead sem atividade há mais de</label>
  <input type="number" id="sdays" name="days" min="1" max="365" value="<?= $staleDays ?>" style="flex:0 0 80px">
  <span class="muted" style="font-size:0.75rem">dias</span>
  <button class="btn sm ghost" type="submit">Guardar</button>
</form>
<?php endif; ?>

<?php render_footer();
