<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/paginate.php';
require_once __DIR__ . '/inc/leads.php';
require_once __DIR__ . '/inc/undo.php';
require_once __DIR__ . '/inc/lead_view.php';   // e inc/contacts.php («Trazido por»)
$user = require_login();
require_page('leads');

// Aba Leads v2 (docs/ESPECIFICACAO-PLATAFORMA.md §12). As leads vivem em
// MySQL (deals, phase = 'lead'). Separadores Ativas · Por atribuir ·
// Arquivadas · Quarentena; filtros rápidos por estado, «Urgente» (em
// atraso) e as vistas. Cada lead abre no painel lateral; a ficha completa
// é lead.php. Quem vê o quê vem do âmbito da aba Leads (inc/perm.php).

const VIEWS = ['ativas' => 'Ativas', 'fila' => 'Por atribuir', 'arquivadas' => 'Arquivadas', 'quarentena' => 'Quarentena'];
/** Vistas guardadas (§11.12). «Em risco» = sem próxima ação (§12.7). */
const LEAD_VISTAS = ['meus' => 'As minhas', 'risco' => 'Em risco', 'mes' => 'Este mês'];

function lead_filters(array $src): array {
    $f = [];
    foreach (['v', 'vista', 'estado', 'urgente', 'q', 'tipo', 'prio', 'origem', 'dono', 'pl'] as $k) {
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
    $bf   = lead_filters(is_array($_POST['back'] ?? null) ? $_POST['back'] : []);
    $back = leads_url($bf);
    $bu   = (string)($_POST['back_url'] ?? '');
    if (str_starts_with($bu, '/admin/lead.php') || str_starts_with($bu, '/admin/leads.php')) $back = $bu;

    if ($do === 'create') {
        $v = lead_input($_POST);
        if ($v['company'] === '' && $v['person'] === '') {
            $_SESSION['lead_form'] = $_POST;
            flash('Indique a empresa ou a pessoa de contacto.', 'err');
            redirect(leads_url($bf + ['nova' => 1]));
        }
        try {
            $bb = contact_pick_resolve($pdo, $_POST['brought_by_id'] ?? '', (string)($_POST['brought_by'] ?? ''));
        } catch (RuntimeException $e) {
            $_SESSION['lead_form'] = $_POST;
            flash($e->getMessage(), 'err');
            redirect(leads_url($bf + ['nova' => 1]));
        }
        $owner = owner_from_post($pdo, $me, $me['role'] === 'superadmin' ? null : (int)$me['id']);
        $use = (int)($_POST['use_contact'] ?? 0);
        if ($use) { $st = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE id = ?'); $st->execute([$use]); if (!(int)$st->fetchColumn()) $use = 0; }
        $id = lead_create($pdo, $v, $owner, (int)$me['id'], false, $use ?: null);
        if ($bb) deal_set_brought_by($pdo, lead_get($pdo, $id), $bb, (int)$me['id']);
        $dups = find_duplicates($pdo, $v['email'], $v['phone'], $id);
        $warn = !$use && ($dups['deals'] || array_filter($dups['contacts'], fn($c) => (int)$c['id'] !== (int)lead_get($pdo, $id)['person_id'] && (int)$c['id'] !== (int)lead_get($pdo, $id)['company_id']));
        unset($_SESSION['lead_form']);
        flash(($owner === null ? 'Lead criada na fila «Por atribuir».' : 'Lead criada.') . ($warn ? ' Atenção: já existia alguém com este email ou telefone.' : ''));
        redirect(!empty($_POST['again']) ? leads_url($bf + ['nova' => 1]) : leads_url($bf + ['open' => $id]));
    }

    // As restantes ações são sobre um registo: tem de estar ao meu alcance.
    $lead = lead_get($pdo, (int)($_POST['id'] ?? 0));
    if (!$lead || !in_array($lead['phase'], ['lead', 'archived'], true) || !lead_in_reach($pdo, $lead, $me)) {
        flash('Lead não encontrada.', 'err');
        redirect($back);
    }
    $id = (int)$lead['id'];

    if ($do === 'archive') {
        $r = (string)($_POST['reason'] ?? '');
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
        if (!isset(ARCHIVE_REASONS[$r]) || $r === 'perdido_migracao') { flash('Escolha o motivo.', 'err'); redirect(leads_url($bf + ['arch' => $id])); }
        if ($r === 'outro' && $note === '') { flash('Escreva o motivo.', 'err'); redirect(leads_url($bf + ['arch' => $id])); }
        if ($lead['phase'] !== 'lead') { flash('Esta lead já não está ativa.', 'err'); redirect($back); }
        flash('Lead arquivada.', 'ok', undo_push('lead_archive', lead_archive($pdo, $lead, $r, $note)));
        redirect(str_starts_with($back, '/admin/lead.php') ? leads_url($bf) : $back);
    }

    if ($do === 'reactivate') {
        // Volta a contar o prazo do estado em que estava, a partir de agora.
        $pdo->prepare('UPDATE deals SET phase = \'lead\', archived_reason = \'\', archived_note = \'\', archived_at = NULL, is_quarantined = 0, state_since = ?, last_touch = ? WHERE id = ? AND phase = \'archived\'')
            ->execute([now_php(), today_lisbon(), $id]);
        deal_log($pdo, $id, 'reactivate');
        flash('Lead reativada.');
        redirect(leads_url($bf + ['open' => $id]));
    }

    if ($do === 'release') {   // tirar da quarentena: entra a sério (contactos, prazo, primeira chamada)
        $pdo->prepare('UPDATE deals SET is_quarantined = 0, state_since = ? WHERE id = ?')->execute([now_php(), $id]);
        lead_start($pdo, $id, null, (int)$me['id']);
        flash('Lead libertada da quarentena.');
        redirect(leads_url(['v' => 'quarentena']));
    }

    if ($do === 'convert') {
        if ($lead['phase'] !== 'lead') { flash('Esta lead já não está ativa.', 'err'); redirect($back); }
        require_perm('pipeline', PERM_EDIT);
        $funnel = (int)($_POST['funnel_id'] ?? 0);
        $st = $pdo->prepare('SELECT COUNT(*) FROM funnels WHERE id = ? AND is_active = 1');
        $st->execute([$funnel]);
        if (!(int)$st->fetchColumn()) { flash('Escolha o funil.', 'err'); redirect(leads_url($bf + ['conv' => $id]) . '#converter'); }
        try {
            $code = lead_convert($pdo, $lead, $funnel, (int)($_POST['contact_id'] ?? 0));
        } catch (Throwable $e) {
            flash('Não foi possível converter: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'erro na base de dados.'), 'err');
            redirect($back);
        }
        flash("Lead convertida: $code entrou no Pipeline, na etapa X-Ray.");
        redirect(leads_url($bf));
    }

    if ($do === 'delete') {
        require_perm('leads', PERM_MANAGE);
        $pdo->prepare('DELETE FROM tasks WHERE deal_id = ? AND status <> \'done\'')->execute([$id]);
        $pdo->prepare('DELETE FROM deals WHERE id = ?')->execute([$id]);
        flash('Lead eliminada.');
        redirect(leads_url($bf));
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
$stmt = $pdo->prepare('SELECT d.*, u.name AS owner_name FROM deals d LEFT JOIN users u ON u.id = d.owner_id WHERE ' . implode(' AND ', $where));
$stmt->execute($args);
$all = $stmt->fetchAll();

// Contagens dos separadores (dentro do que posso ver).
$cnt = static function (string $cond, bool $scoped) use ($pdo, $scopeArgs, $reach) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM deals d WHERE $cond" . ($scoped ? " AND $reach" : ''));
    $st->execute($scoped ? $scopeArgs : []);
    return (int)$st->fetchColumn();
};
$st = $pdo->prepare("SELECT COUNT(*) FROM deals d WHERE d.phase = 'lead' AND d.owner_id IS NOT NULL AND d.is_quarantined = 0 AND $scopeSql");
$st->execute($scopeArgs);
$counts = [
    'ativas'     => (int)$st->fetchColumn(),
    'fila'       => $manager ? $cnt("d.phase = 'lead' AND d.owner_id IS NULL AND d.is_quarantined = 0", false) : 0,
    'arquivadas' => $cnt("d.phase = 'archived'", true),
    'quarentena' => $manager ? $cnt("d.phase = 'lead' AND d.is_quarantined = 1", false) : 0,
];

// Prazo, próxima ação e último contacto de cada lead.
$hours = lead_deadline_hours($pdo);
$open  = lead_open_tasks($pdo, array_column($all, 'id'));
foreach ($all as &$l) {
    $l['clock'] = lead_clock($l, $hours);
    $l['next'] = $open[(int)$l['id']][0] ?? null;
    [$l['dn'], $l['dc']] = lead_display($l);
}
unset($l);

$monthStart = (new DateTimeImmutable(today_lisbon()))->format('Y-m-01');
$inVista = static function (array $l, string $vista) use ($me, $monthStart): bool {
    return match ($vista) {
        'meus'  => (int)$l['owner_id'] === (int)$me['id'],
        'risco' => $l['phase'] === 'lead' && $l['next'] === null,
        'mes'   => substr((string)($l['phase'] === 'archived' ? $l['archived_at'] : $l['created_at']), 0, 10) >= $monthStart,
        default => true,
    };
};
$vista   = isset(LEAD_VISTAS[$filters['vista'] ?? '']) ? (string)$filters['vista'] : '';
$estado  = isset(LEAD_STATE[$filters['estado'] ?? '']) ? (string)$filters['estado'] : '';
$urgente = !empty($filters['urgente']);
$isLate  = static fn(array $l) => $l['clock'] && $l['clock']['level'] === 'late';

$q = $filters['q'] ?? '';
$lc = static fn(string $s): string => mb_strtolower($s, 'UTF-8');
$needle = $lc($q);
$rest = static function ($l) use ($filters, $needle, $lc) {
    if (($filters['prio'] ?? '') !== '' && $l['priority'] !== $filters['prio']) return false;
    if (($filters['origem'] ?? '') !== '' && $l['origin'] !== $filters['origem']) return false;
    if (($filters['dono'] ?? '') !== '' && (string)$l['owner_id'] !== $filters['dono']) return false;
    if (($filters['tipo'] ?? '') !== '' && !in_array($filters['tipo'], array_map('trim', explode(',', (string)$l['types'])), true)) return false;
    if ($needle !== '') {
        $hay = $lc(implode(' ', [$l['name'], $l['company'], $l['person'], $l['links'], $l['notes'], $l['detail'], $l['why'], $l['email'], $l['phone'],
                                 $l['types'], $l['service'], $l['campaign']]));
        if (strpos($hay, $needle) === false) return false;
    }
    return true;
};
// Os contadores dos botões rápidos contam dentro dos outros filtros (combinam, §12.4).
$base = array_values(array_filter($all, $rest));
$vistaCount = []; foreach (array_keys(LEAD_VISTAS) as $k) $vistaCount[$k] = count(array_filter($base, fn($l) => $inVista($l, $k) && (!$estado || $l['lead_state'] === $estado) && (!$urgente || $isLate($l))));
$stateCount = []; foreach (array_keys(LEAD_STATE) as $k) $stateCount[$k] = count(array_filter($base, fn($l) => $l['lead_state'] === $k && $inVista($l, $vista) && (!$urgente || $isLate($l))));
$lateCount = count(array_filter($base, fn($l) => $isLate($l) && $inVista($l, $vista) && (!$estado || $l['lead_state'] === $estado)));

$rows = array_values(array_filter($base, fn($l) => $inVista($l, $vista) && (!$estado || $l['lead_state'] === $estado) && (!$urgente || $isLate($l))));
usort($rows, static function ($a, $b) use ($view) {
    if ($view === 'arquivadas') return strcmp((string)$b['archived_at'], (string)$a['archived_at']);
    // O mais urgente primeiro: o prazo que acaba mais cedo (ou já acabou há mais tempo).
    return [$a['clock'] ? 0 : 1, $a['clock']['due'] ?? 0, $a['dn']] <=> [$b['clock'] ? 0 : 1, $b['clock']['due'] ?? 0, $b['dn']];
});

// KPIs (sobre as ativas que vejo).
$kAll   = $view === 'ativas' ? $all : (function () use ($pdo, $scopeSql, $scopeArgs, $hours) {
    $st = $pdo->prepare("SELECT d.* FROM deals d WHERE d.phase = 'lead' AND d.owner_id IS NOT NULL AND d.is_quarantined = 0 AND $scopeSql");
    $st->execute($scopeArgs);
    $r = $st->fetchAll();
    foreach ($r as &$x) $x['clock'] = lead_clock($x, $hours);
    return $r;
})();
$kLate = count(array_filter($kAll, $isLate));
$kNear = count(array_filter($kAll, fn($l) => $l['clock'] && $l['clock']['level'] === 'near'));
$kByState = array_count_values(array_column($kAll, 'lead_state'));

$pg = paginate($rows, 'pl');
if ($pg['page'] > 1) $filters['pl'] = (string)$pg['page']; else unset($filters['pl']);

$canEdit   = can('leads', PERM_EDIT);
$canManage = can('leads', PERM_MANAGE);
$owners    = $pdo->query('SELECT id, name FROM users ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$self      = leads_url($filters);

// Painel aberto por endereço (?open=ID), e os formulários por cima (sem JavaScript também).
$byId = static function (int $id) use ($pdo, $me) {
    $l = $id ? lead_get($pdo, $id) : null;
    return $l && in_array($l['phase'], ['lead', 'archived'], true) && lead_in_reach($pdo, $l, $me) ? $l : null;
};
$openLead = $byId((int)($_GET['open'] ?? 0));
$archLead = $byId((int)($_GET['arch'] ?? 0));
$convLead = $byId((int)($_GET['conv'] ?? 0));
$delLead  = $canManage ? $byId((int)($_GET['del'] ?? 0)) : null;
$newOpen  = $canEdit && !empty($_GET['nova']);

render_header('Leads', 'leads');
render_flashes();
echo paginate_css();
?>
<?php if ($openLead): ?><div data-open-panel="lead:<?= (int)$openLead['id'] ?>" hidden></div><?php endif; ?>
<h1>Leads</h1>

<div class="stats">
  <a class="stat hero" href="<?= e(leads_url(['urgente' => 1])) ?>"><div class="k">Em atraso</div><div class="v"><?= $kLate ?></div><div class="n"><?= $kNear ?> a aproximar do prazo</div></a>
  <a class="stat" href="<?= e(leads_url(['estado' => 'novo'])) ?>"><div class="k">Novas</div><div class="v"><?= (int)($kByState['novo'] ?? 0) ?></div><div class="n">nunca contactadas</div></a>
  <a class="stat" href="<?= e(leads_url(['estado' => 'follow_up'])) ?>"><div class="k">Follow-Up</div><div class="v"><?= (int)($kByState['follow_up'] ?? 0) ?></div><div class="n"><?= (int)($kByState['sem_resposta'] ?? 0) ?> sem resposta</div></a>
  <?php if ($manager): ?>
    <a class="stat<?= $counts['fila'] ? ' risk' : '' ?>" href="<?= e(leads_url(['v' => 'fila'])) ?>"><div class="k">Por atribuir</div><div class="v"><?= $counts['fila'] ?></div><div class="n">na fila de entrada</div></a>
  <?php else: ?>
    <a class="stat" href="<?= e(leads_url(['vista' => 'risco'])) ?>"><div class="k">Sem próxima ação</div><div class="v"><?= count(array_filter($kAll, fn($l) => !isset($open[(int)$l['id']]))) ?></div><div class="n">em risco</div></a>
  <?php endif; ?>
</div>

<div class="tabs-row">
  <div class="tabs">
    <?php foreach (VIEWS as $k => $label): if (in_array($k, ['fila', 'quarentena'], true) && !$manager) continue; ?>
      <a href="<?= e(leads_url($k === 'ativas' ? [] : ['v' => $k])) ?>" class="<?= $view === $k ? 'on' : '' ?>"><?= e($label) ?> (<?= $counts[$k] ?>)</a>
    <?php endforeach; ?>
  </div>
  <?php if ($canEdit): ?>
    <a class="btn dark add-lead" href="<?= e(leads_url($filters + ['nova' => 1])) ?>" data-new="#mdl-new" data-new-lead aria-keyshortcuts="N" data-tip="Nova lead  ·  N"><?= icon('plus') ?> Adicionar Lead</a>
  <?php endif; ?>
</div>

<?php if ($view !== 'arquivadas'):
    $chip = fn(array $set, array $unset = []) => leads_url(array_diff_key(array_merge($filters, $set), array_flip(array_merge($unset, ['pl']))));
?>
<nav class="views lead-views" aria-label="Filtros rápidos">
  <a class="urg<?= $urgente ? ' on' : '' ?>" href="<?= e($urgente ? $chip([], ['urgente']) : $chip(['urgente' => 1])) ?>"<?= $urgente ? ' aria-current="true"' : '' ?>>Urgente <i><?= $lateCount ?></i></a>
  <span class="sep" aria-hidden="true"></span>
  <a href="<?= e($chip([], ['estado'])) ?>" class="<?= $estado === '' ? 'on' : '' ?>">Todos os estados</a>
  <?php foreach (LEAD_STATE as $k => $label): ?>
    <a href="<?= e($estado === $k ? $chip([], ['estado']) : $chip(['estado' => $k])) ?>" class="<?= $estado === $k ? 'on' : '' ?>"><?= e($label) ?> <span class="mono"><?= $stateCount[$k] ?></span></a>
  <?php endforeach; ?>
  <span class="sep" aria-hidden="true"></span>
  <?php foreach (LEAD_VISTAS as $k => $label): ?>
    <a href="<?= e($vista === $k ? $chip([], ['vista']) : $chip(['vista' => $k])) ?>" class="<?= $vista === $k ? 'on' : '' ?>"><?= e($label) ?> <span class="mono"><?= $vistaCount[$k] ?></span></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<form class="bar" method="get" data-auto>
  <?php foreach (['v', 'vista', 'estado', 'urgente'] as $k): if (isset($filters[$k])): ?><input type="hidden" name="<?= $k ?>" value="<?= e($filters[$k]) ?>"><?php endif; endforeach; ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Procurar nome, empresa, contacto, nota" aria-label="Procurar" data-refocus>
  <select name="tipo" aria-label="Tipo">
    <option value="">Todos os tipos</option>
    <?php foreach (LEAD_TYPES_LIST as $t): ?><option <?= ($filters['tipo'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
  </select>
  <select name="prio" aria-label="Prioridade">
    <option value="">Prioridade</option>
    <?php foreach (LEAD_PRIORITY as $k => $l): ?><option value="<?= $k ?>" <?= ($filters['prio'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <select name="origem" aria-label="Origem">
    <option value="">Origem</option>
    <?php foreach (LEAD_ORIGIN as $k => $l): ?><option value="<?= $k ?>" <?= ($filters['origem'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
  </select>
  <?php if (my_scope('leads') !== 'own' && $view !== 'fila'): ?>
  <select name="dono" aria-label="Responsável">
    <option value="">Responsável</option>
    <?php foreach ($owners as $oid => $on): ?><option value="<?= (int)$oid ?>" <?= ($filters['dono'] ?? '') === (string)$oid ? 'selected' : '' ?>><?= e($on) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button class="btn sm apply" type="submit">Filtrar</button>
  <?php if (array_diff_key($filters, ['pl' => 1, 'v' => 1])): ?><a class="lnk" href="<?= e(leads_url($view === 'ativas' ? [] : ['v' => $view])) ?>">Limpar</a><?php endif; ?>
</form>

<div class="tablewrap">
<table class="leads-t">
  <thead>
    <tr><th><?= $view === 'arquivadas' ? 'Arquivada' : 'Prazo' ?></th><th>Nome</th><th><?= $view === 'arquivadas' ? 'Motivo' : 'Estado' ?></th><th>Responsável</th>
        <th class="hide-sm">Próxima ação</th><th class="hide-sm">Último contacto</th></tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty">Nenhuma lead aqui.</td></tr><?php endif; ?>
  <?php foreach ($pg['rows'] as $l): $lid = (int)$l['id']; $c = $l['clock']; ?>
    <tr id="l-<?= $lid ?>" data-panel="lead:<?= $lid ?>" class="<?= $c ? 'lvl-' . $c['level'] : '' ?>">
      <td class="nowrap"><?= $view === 'arquivadas' ? '<span class="mono muted">' . e($l['archived_at'] ? lisbon_fmt(strtotime((string)$l['archived_at']), 'd/m/Y') : '—') . '</span>' : lead_ring($c) ?></td>
      <td class="nm">
        <a class="b" href="/admin/lead.php?id=<?= $lid ?>&amp;back=<?= e(rawurlencode($self)) ?>" data-panel="lead:<?= $lid ?>"><?= e($l['dn']) ?></a>
        <small><?= e($l['dc'] !== '' ? $l['dc'] : implode(' · ', array_filter([(string)$l['types'], (string)$l['service']]))) ?><?= $l['priority'] === 'alta' ? ' <span class="chip no">Alta</span>' : '' ?></small>
      </td>
      <td><?= $view === 'arquivadas' ? e(ARCHIVE_REASONS[$l['archived_reason']] ?? $l['archived_reason']) . ($l['archived_note'] ? '<div class="muted" style="font-size:12px">' . e(shorten($l['archived_note'], 40)) . '</div>' : '') : lead_state_chip($l) ?></td>
      <td><?= e($l['owner_name'] ?? 'Por atribuir') ?></td>
      <td class="hide-sm"><?php if ($l['next']): [$kl] = LEAD_TASK_KINDS[$l['next']['kind'] ?? 'task'] ?? LEAD_TASK_KINDS['task']; ?>
        <span class="<?= task_is_late($l['next']) ? 'neg' : '' ?>"><?= e($kl) ?> · <?= e(lead_task_when($l['next'])) ?></span>
      <?php elseif ($l['phase'] === 'lead'): ?><span class="risk-txt">Sem próxima ação</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td class="hide-sm nowrap muted"><?= e(lead_ago($l['last_contact_at'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= page_nav($pg, ['open']) ?>

<?php // ── nova lead: cresce a partir do botão (§12.1) ──
if ($canEdit):
    $old = $_SESSION['lead_form'] ?? []; unset($_SESSION['lead_form']);
    $assignees = assignable_users($pdo, $me);
    $canAssign = $manager || count($assignees) > 1;
    $companies = $pdo->query("SELECT name FROM contacts WHERE kind = 'company' ORDER BY name LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN);
    $ov = fn(string $k) => e((string)($old[$k] ?? ''));
?>
<div class="mdl<?= $newOpen ? ' open' : '' ?>" id="mdl-new" data-from="[data-new-lead]" data-clear="nova trazido" role="dialog" aria-modal="true" aria-labelledby="mdl-new-t"<?= $newOpen ? '' : ' hidden' ?>>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card wide" method="post" action="/admin/leads.php" autocomplete="off" data-new-form>
    <?= csrf_field() ?><?= back_fields(array_diff_key($filters, ['nova' => 1])) ?>
    <input type="hidden" name="do" value="create"><input type="hidden" name="use_contact" value="">
    <h3 id="mdl-new-t">Nova lead</h3>
    <p class="muted">Pelo menos a empresa ou a pessoa de contacto. A empresa e a pessoa ficam também em Contactos.</p>
    <div class="grid">
      <div class="field"><label for="nl-co">Empresa</label>
        <input type="text" id="nl-co" name="company" maxlength="160" list="dl-companies" value="<?= $ov('company') ?>" placeholder="Procurar ou criar">
        <datalist id="dl-companies"><?php foreach ($companies as $cn): ?><option value="<?= e($cn) ?>"><?php endforeach; ?></datalist></div>
      <div class="field"><label for="nl-pe">Pessoa de contacto</label><input type="text" id="nl-pe" name="person" maxlength="160" value="<?= $ov('person') ?>"></div>
      <div class="field"><label for="nl-ph">Telefone(s)</label><input type="text" id="nl-ph" name="phone" maxlength="255" inputmode="tel" value="<?= $ov('phone') ?>" placeholder="+351 … (vários: separe com |)" data-dup></div>
      <div class="field"><label for="nl-em">Email</label><input type="email" id="nl-em" name="email" maxlength="190" value="<?= $ov('email') ?>" data-dup></div>
      <div class="dup field wide" hidden aria-live="polite"></div>
      <div class="field wide"><label for="nl-li">Redes sociais</label><textarea id="nl-li" name="links" rows="2" placeholder="Um link por linha (Instagram, LinkedIn, site…)"><?= $ov('links') ?></textarea></div>
      <div class="field wide"><label>Tipo</label>
        <div class="chips-check">
          <?php foreach (LEAD_TYPES_LIST as $t): ?><label class="rb"><input type="checkbox" name="types[]" value="<?= e($t) ?>" <?= in_array($t, (array)($old['types'] ?? []), true) ? 'checked' : '' ?>><span><?= e($t) ?></span></label><?php endforeach; ?>
        </div></div>
      <div class="field"><label for="nl-se">Serviço</label>
        <select id="nl-se" name="service"><option value="">—</option><?php foreach (LEAD_SERVICES_LIST as $sv): ?><option <?= ($old['service'] ?? '') === $sv ? 'selected' : '' ?>><?= e($sv) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="nl-bb">Trazido por</label>
        <?= contact_pick_html($pdo, 'brought_by', 'brought_by_id', (int)($old['brought_by_id'] ?? 0) ?: ((int)($_GET['trazido'] ?? 0) ?: null), 'nl-bb', 'Quem originou (agência, parceiro…)') ?></div>
      <div class="field"><label for="nl-or">Origem</label>
        <select id="nl-or" name="origin"><?php foreach (LEAD_ORIGIN as $k => $lab): ?><option value="<?= $k ?>" <?= ($old['origin'] ?? 'manual') === $k ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
      <?php if ($canAssign): ?>
      <div class="field"><label for="nl-ow">Responsável</label>
        <select id="nl-ow" name="owner_id">
          <?php if ($manager): ?><option value="none">— Por atribuir —</option><?php endif; ?>
          <?php foreach ($assignees as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === (int)$me['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div class="field wide"><label for="nl-no">Notas</label><textarea id="nl-no" name="notes" rows="2"><?= $ov('notes') ?></textarea></div>
    </div>
    <div class="actions">
      <button type="button" class="btn ghost" data-mdl-close>Cancelar</button>
      <span class="spacer"></span>
      <button class="btn ghost" type="submit" name="again" value="1">Gravar e adicionar outra</button>
      <button class="btn" type="submit">Gravar</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php // ── converter ──
if ($convLead && $convLead['phase'] === 'lead' && can('pipeline', PERM_EDIT)):
    $funnels = $pdo->query('SELECT id, name FROM funnels WHERE is_active = 1 ORDER BY position, name')->fetchAll();
    $opts = [];
    foreach (['company_id', 'person_id', 'contact_id'] as $f) {
        if ($convLead[$f]) { $s2 = $pdo->prepare('SELECT id, kind, name, email, phone FROM contacts WHERE id = ?'); $s2->execute([(int)$convLead[$f]]); if ($c = $s2->fetch()) $opts[(int)$c['id']] = $c; }
    }
    foreach (find_duplicates($pdo, (string)$convLead['email'], (string)$convLead['phone'])['contacts'] as $c) $opts[(int)$c['id']] ??= $c;
?>
<div class="mdl open" id="mdl-conv" role="dialog" aria-modal="true" aria-labelledby="converter">
  <a class="mdl-back" href="<?= e($self) ?>" aria-label="Fechar"></a>
  <form class="mdl-card pop-in" method="post" action="/admin/leads.php">
    <?= csrf_field() ?><?= back_fields($filters) ?>
    <input type="hidden" name="do" value="convert"><input type="hidden" name="id" value="<?= (int)$convLead['id'] ?>">
    <h3 id="converter">Converter «<?= e(lead_display_name($convLead)) ?>» em negócio</h3>
    <p class="muted">Recebe o ID do negócio (ex.: ID<?= date('ym') ?>-01), sai das Leads e entra no Pipeline na etapa X-Ray. O prazo da lead termina.</p>
    <div class="field"><label for="cfunnel">Funil</label>
      <select id="cfunnel" name="funnel_id" required><option value="">Escolher…</option>
        <?php foreach ($funnels as $f): ?><option value="<?= (int)$f['id'] ?>"><?= e($f['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field" style="margin-top:12px"><label>Cliente (contacto)</label>
      <?php $first = true; foreach ($opts as $c): ?>
        <label class="check"><input type="radio" name="contact_id" value="<?= (int)$c['id'] ?>" <?= $first ? 'checked' : '' ?>>
          <b><?= e($c['name']) ?></b> <span class="muted"><?= ($c['kind'] ?? '') === 'person' ? 'pessoa' : 'empresa' ?> <?= e(trim($c['email'] . ' ' . $c['phone'])) ?></span></label>
      <?php $first = false; endforeach; ?>
      <label class="check"><input type="radio" name="contact_id" value="0" <?= $opts ? '' : 'checked' ?>> Criar um contacto novo</label>
    </div>
    <div class="actions"><a class="btn ghost" href="<?= e($self) ?>">Cancelar</a><button class="btn" type="submit"><?= icon('arrow') ?> Converter</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($delLead): ?>
<div class="mdl open" role="dialog" aria-modal="true" aria-labelledby="mdl-del-t">
  <a class="mdl-back" href="<?= e($self) ?>" aria-label="Fechar"></a>
  <form class="mdl-card pop-in" method="post" action="/admin/leads.php">
    <?= csrf_field() ?><?= back_fields($filters) ?>
    <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$delLead['id'] ?>">
    <h3 id="mdl-del-t">Eliminar «<?= e(lead_display_name($delLead)) ?>»?</h3>
    <p class="muted">A lead e o seu histórico desaparecem. Isto não se anula — para tirar uma lead da lista sem a perder, arquive-a.</p>
    <div class="actions"><a class="btn ghost" href="<?= e($self) ?>">Cancelar</a><button class="btn danger" type="submit">Sim, eliminar</button></div>
  </form>
</div>
<?php endif; ?>

<?= $canEdit ? lead_modals_html($archLead && $archLead['phase'] === 'lead' ? $archLead : null, $self) : '' ?>
<script src="<?= asset_url('leads.js') ?>" defer></script>
<?php render_footer();
