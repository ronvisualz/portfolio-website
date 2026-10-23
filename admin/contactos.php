<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/paginate.php';
require_once __DIR__ . '/inc/contact_view.php';
$user = require_login();
require_page('contactos');

// Aba Contactos v2 (docs/ESPECIFICACAO-PLATAFORMA.md §13). Pessoas e
// empresas: Todos (por defeito) · Clientes · Fornecedores · Freelancers ·
// Parceiros · Empresas · Relações. Cada contacto abre no painel lateral;
// a ficha completa é contact.php. «+ Adicionar contacto» e «Importar»
// crescem a partir do botão (§13.8). As permissões confirmam-se aqui e no
// ui_api.php, em cada pedido.

const CT_TABS = ['todos' => 'Todos', 'clientes' => 'Clientes', 'fornecedores' => 'Fornecedores', 'freelancers' => 'Freelancers',
                 'parceiros' => 'Parceiros', 'empresas' => 'Empresas', 'relacoes' => 'Relações'];
const CT_TAB_FLAG = ['clientes' => 'is_client', 'fornecedores' => 'is_supplier', 'freelancers' => 'is_freelancer', 'parceiros' => 'is_partner'];
const CT_REL_FILTERS = ['atraso' => 'Em atraso', 'semana' => 'Esta semana', 'A' => 'Nível A', 'B' => 'B', 'C' => 'C', 'pausadas' => 'Pausadas'];

function ct_filters(array $src): array {
    $f = [];
    foreach (['t', 'q', 'svc', 'estado', 'dup', 'rel', 'pc'] as $k) {
        $v = trim((string)($src[$k] ?? ''));
        if ($v !== '') $f[$k] = $v;
    }
    return $f;
}
function ct_url(array $f = []): string {
    return '/admin/contactos.php' . ($f ? '?' . http_build_query($f) : '');
}
function back_fields(array $f): string {
    $h = '';
    foreach ($f as $k => $v) $h .= '<input type="hidden" name="back[' . e($k) . ']" value="' . e($v) . '">';
    return $h;
}

/** As linhas de serviço do formulário de criação (svc[n][…]), validadas. */
function ct_lines_input(PDO $pdo, $raw, string $from): array {
    $out = [];
    foreach (is_array($raw) ? $raw : [] as $r) {
        if (!is_array($r) || ((string)($r['service_id'] ?? '') === '' && trim((string)($r['price'] ?? '')) === '')) continue;
        $out[] = service_line_input($pdo, $r + ['valid_from' => $from]);
    }
    return $out;
}

/** O que a importação faria com uma linha: o contacto igual (mesmo email/telefone, ou nome), se houver. */
function ct_import_match(PDO $pdo, array $r): ?array {
    $d = find_duplicates($pdo, $r['email'], $r['phone']);
    foreach ($d['contacts'] as $c) if ($c['kind'] === $r['kind']) return ['id' => (int)$c['id'], 'name' => $c['name']];
    $st = $pdo->prepare('SELECT id, name FROM contacts WHERE kind = ? AND LOWER(TRIM(name)) = ? LIMIT 1');
    $st->execute([$r['kind'], mb_strtolower(trim($r['name']))]);
    $c = $st->fetch();
    return $c ? ['id' => (int)$c['id'], 'name' => $c['name']] : null;
}

/** Completa um contacto existente com uma linha importada (só os campos vazios; telefones e redes juntam-se). */
function ct_import_fill(PDO $pdo, int $id, array $r, ?int $by): void {
    $c = contact_get($pdo, $id);
    if (!$c) return;
    $set = [];
    foreach (['email', 'nif', 'address', 'role', 'birthday'] as $f) {
        if ((string)$c[$f] === '' && (string)($r[$f] ?? '') !== '' && ($f !== 'role' || $c['kind'] === 'person')) $set[$f] = $r[$f];
    }
    $phones = array_values(array_unique(array_merge(lead_phones((string)$c['phone']), lead_phones($r['phone']))));
    if ($phones && implode(' | ', $phones) !== (string)$c['phone']) $set['phone'] = mb_substr(implode(' | ', $phones), 0, 255);
    $links = array_values(array_unique(array_filter(array_merge(preg_split('/\s*[|\n]\s*/', (string)$c['links']), preg_split('/\s*[|\n]\s*/', (string)$r['links'])), 'strlen')));
    if (implode("\n", $links) !== trim((string)$c['links'])) $set['links'] = implode("\n", $links);
    if ($r['notes'] !== '' && !str_contains((string)$c['notes'], $r['notes'])) $set['notes'] = trim((string)$c['notes'] . "\n\n" . $r['notes']);
    foreach (array_keys(CONTACT_TYPES) as $f) if ((int)$r[$f] === 1 && (int)$c[$f] === 0 && ($f !== 'is_freelancer' || $c['kind'] === 'person')) $set[$f] = 1;
    if ($set) $pdo->prepare('UPDATE contacts SET ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $id]);
    if ($c['kind'] === 'person' && !$c['company_id'] && $r['company'] !== '') contact_set_company($pdo, $c, $r['company'], $by);
    contact_log($pdo, $id, 'c_import', $set ? 'Completado pela importação: ' . implode(', ', array_keys($set)) : 'Importação: nada de novo', $by);
}

$canEdit   = can('contactos', PERM_EDIT);
$canManage = can('contactos', PERM_MANAGE);
$by        = (int)$user['id'];

// ── ações ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do   = (string)($_POST['do'] ?? '');
    $bf   = ct_filters(is_array($_POST['back'] ?? null) ? $_POST['back'] : []);
    $back = ct_url($bf);

    if ($do === 'create') {
        $kind = ($_POST['kind'] ?? '') === 'company' ? 'company' : 'person';
        $v = contact_input($_POST, $kind);
        $co = $kind === 'person' ? trim((string)($_POST['company'] ?? '')) : '';
        $err = '';
        if ($v['name'] === '') $err = 'Indique o nome.';
        elseif ($v['is_freelancer'] && $co !== '') $err = 'Um freelancer é uma pessoa sem empresa: tire a empresa ou desmarque Freelancer.';
        elseif ($co !== '' && mb_strtolower($co) === mb_strtolower($v['name'])) $err = 'A empresa não pode ter o mesmo nome da pessoa.';
        $lines = [];
        if ($err === '' && ($v['is_supplier'] || $v['is_freelancer'])) {
            try { $lines = ct_lines_input($pdo, $_POST['svc'] ?? [], (string)($_POST['valid_from'] ?? '')); }
            catch (RuntimeException $e) { $err = $e->getMessage(); }
        }
        if ($err !== '') {
            $_SESSION['contact_form'] = $_POST;
            flash($err, 'err');
            redirect(ct_url($bf + ['novo' => $kind === 'company' ? 'empresa' : 'pessoa']));
        }
        $pdo->beginTransaction();
        try {
            $id = contact_create($pdo, $kind, $v, $co, $by);
            foreach ($lines as $l) service_line_save($pdo, $id, null, $l, $by);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        unset($_SESSION['contact_form']);
        $dups = contact_duplicates($pdo, contact_get($pdo, $id));
        flash(($kind === 'company' ? 'Empresa criada.' : 'Contacto criado.') . ($dups ? ' Atenção: já existia «' . $dups[0]['name'] . '» com este email ou telefone — pode juntá-los na ficha.' : ''));
        redirect(!empty($_POST['again']) ? ct_url($bf + ['novo' => $kind === 'company' ? 'empresa' : 'pessoa']) : ct_url($bf + ['open' => $id]));
    }

    if ($do === 'import_preview') {
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { flash('Escolha um ficheiro CSV ou vCard.', 'err'); redirect($back); }
        if ($f['size'] > 2 * 1024 * 1024) { flash('O ficheiro passa de 2 MB.', 'err'); redirect($back); }
        $name = (string)$f['name'];
        if (!preg_match('/\.(csv|vcf|vcard|txt)$/i', $name)) { flash('Só CSV (.csv) ou vCard (.vcf).', 'err'); redirect($back); }
        $rows = contact_import_parse((string)file_get_contents($f['tmp_name']), $name);
        if (!$rows) { flash('Não encontrei contactos no ficheiro. O CSV precisa de cabeçalho (Nome, Empresa, Email, Telefone…).', 'err'); redirect($back); }
        foreach ($rows as &$r) $r['match'] = ct_import_match($pdo, $r);
        unset($r);
        $_SESSION['ct_import'] = ['file' => mb_substr($name, 0, 120), 'rows' => $rows];
        redirect(ct_url($bf + ['importar' => 1]));
    }

    if ($do === 'import_commit') {
        $imp = $_SESSION['ct_import'] ?? null;
        if (!$imp) { flash('A importação expirou. Escolha o ficheiro outra vez.', 'err'); redirect($back); }
        $act = is_array($_POST['act'] ?? null) ? $_POST['act'] : [];
        $n = ['create' => 0, 'update' => 0, 'skip' => 0];
        $pdo->beginTransaction();
        try {
            foreach ($imp['rows'] as $i => $r) {
                $a = (string)($act[$i] ?? 'skip');
                if ($a === 'update' && $r['match']) {
                    ct_import_fill($pdo, (int)$r['match']['id'], $r, $by);
                } elseif ($a === 'create') {
                    $v = contact_input($r, $r['kind']);
                    $id = contact_create($pdo, $r['kind'], $v, $r['company'], $by);
                    contact_log($pdo, $id, 'c_import', 'De ' . $imp['file'], $by);
                } else {
                    $a = 'skip';
                }
                $n[$a]++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('A importação não foi feita: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'erro na base de dados.'), 'err');
            redirect(ct_url($bf + ['importar' => 1]));
        }
        unset($_SESSION['ct_import']);
        flash('Importação: ' . $n['create'] . ' novos, ' . $n['update'] . ' completados, ' . $n['skip'] . ' ignorados.');
        redirect($back);
    }

    if ($do === 'delete') {
        require_perm('contactos', PERM_MANAGE);
        $c = contact_get($pdo, (int)($_POST['id'] ?? 0));
        if (!$c) { flash('Contacto não encontrado.', 'err'); redirect($back); }
        if (contact_refs($pdo, (int)$c['id']) > 0) {
            flash('«' . $c['name'] . '» está ligado a negócios, leads, custos ou pessoas: desligue-o primeiro (ou junte-o a outro).', 'err');
            redirect($back);
        }
        $pdo->prepare("DELETE FROM tasks WHERE contact_id = ? AND status <> 'done'")->execute([$c['id']]);
        $pdo->prepare('UPDATE tasks SET contact_id = NULL WHERE contact_id = ?')->execute([$c['id']]);
        $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$c['id']]);
        flash('«' . $c['name'] . '» eliminado.');
        redirect($back);
    }
    redirect($back);
}

// ── dados ───────────────────────────────────────────────────
relation_tick($pdo);   // os follow-ups de relação que chegaram à data
$filters = ct_filters($_GET);
$tab = isset(CT_TABS[$filters['t'] ?? '']) ? (string)$filters['t'] : 'todos';
$lc = static fn(string $s): string => mb_strtolower($s, 'UTF-8');
$needle = $lc($filters['q'] ?? '');

$all = $pdo->query('SELECT c.*, co.name AS company_name, co.is_client AS co_client, co.is_supplier AS co_supplier, co.is_partner AS co_partner
                      FROM contacts c LEFT JOIN contacts co ON co.id = c.company_id')->fetchAll();
foreach ($all as &$c) {
    $c['types'] = contact_types($c, $c['company_id'] ? ['is_client' => $c['co_client'], 'is_supplier' => $c['co_supplier'], 'is_partner' => $c['co_partner']] : null);
}
unset($c);
$inTab = static function (array $c, string $t): bool {
    return match ($t) {
        'clientes', 'parceiros' => isset($c['types'][CT_TAB_FLAG[$t]]),
        'fornecedores', 'freelancers' => (int)$c[CT_TAB_FLAG[$t]] === 1,   // os preços vivem no próprio registo
        'empresas' => $c['kind'] === 'company',
        default => true,
    };
};
$matches = static function (array $c) use ($needle, $lc): bool {
    if ($needle === '') return true;
    return strpos($lc(implode(' ', [$c['name'], $c['company_name'], $c['email'], $c['phone'], $c['role'], $c['nif'], $c['notes'], $c['links'], $c['address']])), $needle) !== false;
};

$counts = [];
foreach (array_keys(CT_TABS) as $k) $counts[$k] = $k === 'relacoes' ? 0 : count(array_filter($all, fn($c) => $inTab($c, $k)));
$relRows = $pdo->query('SELECT r.*, c.name, c.kind, c.company_id, c.is_freelancer, co.name AS company_name, u.name AS owner_name
                          FROM relations r JOIN contacts c ON c.id = r.contact_id LEFT JOIN contacts co ON co.id = c.company_id
                          LEFT JOIN users u ON u.id = r.owner_id')->fetchAll();
$counts['relacoes'] = count($relRows);
$today = today_lisbon();
foreach ($relRows as &$r) {
    $r['health'] = relation_health($pdo, $r);
    $r['paused'] = $r['paused_until'] && $r['paused_until'] > $today;
}
unset($r);
$relLate = count(array_filter($relRows, fn($r) => ($r['health']['level'] ?? '') === 'late'));

// Serviços (filtro e coluna dos fornecedores/freelancers).
$services = services_list($pdo, false);
$svcBy = [];
foreach ($pdo->query('SELECT cs.contact_id, cs.price, cs.unit, s.id AS sid, s.name FROM contact_services cs LEFT JOIN services s ON s.id = cs.service_id
                       WHERE cs.ended_at IS NULL ORDER BY COALESCE(s.position, 999), s.name')->fetchAll() as $l) {
    $svcBy[(int)$l['contact_id']][] = $l;
}
$ratings = [];
foreach ($pdo->query('SELECT contact_id, AVG(stars) AS a, COUNT(*) AS n FROM contact_ratings GROUP BY contact_id')->fetchAll() as $x) {
    $ratings[(int)$x['contact_id']] = [round((float)$x['a'], 1), (int)$x['n']];
}
$dupIds = $tab === 'todos' && $canEdit ? array_flip(contact_dup_ids($pdo)) : [];

$rows = [];
if ($tab === 'relacoes') {
    $rel = $filters['rel'] ?? '';
    $weekEnd = (new DateTimeImmutable($today))->modify('sunday this week')->format('Y-m-d');
    $relCount = [];
    $relTest = static function (array $r, string $f) use ($weekEnd): bool {
        return match ($f) {
            'atraso'   => ($r['health']['level'] ?? '') === 'late',
            'semana'   => !$r['paused'] && $r['next_at'] && $r['next_at'] <= $weekEnd,
            'A', 'B', 'C' => $r['level'] === $f,
            'pausadas' => $r['paused'],
            default    => true,
        };
    };
    $base = array_values(array_filter($relRows, fn($r) => $needle === '' || strpos($lc($r['name'] . ' ' . $r['company_name'] . ' ' . $r['owner_name']), $needle) !== false));
    foreach (array_keys(CT_REL_FILTERS) as $k) $relCount[$k] = count(array_filter($base, fn($r) => $relTest($r, $k)));
    $rows = array_values(array_filter($base, fn($r) => $relTest($r, $rel)));
    $gen = relation_generated($pdo, array_column($rows, 'contact_id'));
    foreach ($rows as &$r) $r['generated'] = $gen[(int)$r['contact_id']] ?? 0;
    unset($r);
    // O mais atrasado primeiro; as pausadas no fim.
    usort($rows, fn($a, $b) => [$a['paused'] ? 1 : 0, $a['health']['due'] ?? PHP_INT_MAX, $a['name']] <=> [$b['paused'] ? 1 : 0, $b['health']['due'] ?? PHP_INT_MAX, $b['name']]);
} else {
    $prov = in_array($tab, ['fornecedores', 'freelancers'], true);
    $svc = (int)($filters['svc'] ?? 0);
    $estado = (string)($filters['estado'] ?? '');
    $rows = array_values(array_filter($all, function ($c) use ($tab, $inTab, $matches, $prov, $svc, $estado, $svcBy, $dupIds, $filters) {
        if (!$inTab($c, $tab) || !$matches($c)) return false;
        if ($prov && $svc && !in_array($svc, array_map(fn($l) => (int)$l['sid'], $svcBy[(int)$c['id']] ?? []), true)) return false;
        if ($prov && $estado !== '' && (int)$c['is_active'] !== ($estado === 'ativos' ? 1 : 0)) return false;
        if (!empty($filters['dup']) && !isset($dupIds[(int)$c['id']])) return false;
        return true;
    }));
    usort($rows, fn($a, $b) => strcmp($lc($a['name']), $lc($b['name'])));
}

// Contagens que as colunas pedem (negócios como cliente, fontes, pessoas).
$dealCount = $brought = $peopleCount = [];
if (in_array($tab, ['clientes', 'empresas', 'parceiros'], true)) {
    foreach ($pdo->query("SELECT id, phase, owner_id, code, contact_id, company_id, person_id, brought_by_id FROM deals WHERE code IS NOT NULL OR brought_by_id IS NOT NULL")->fetchAll() as $d) {
        foreach (array_unique(array_filter([(int)$d['contact_id'], (int)$d['company_id'], (int)$d['person_id']])) as $cid) if ($d['code'] !== null) $dealCount[$cid] = ($dealCount[$cid] ?? 0) + 1;
        if ($d['brought_by_id']) $brought[(int)$d['brought_by_id']] = ($brought[(int)$d['brought_by_id']] ?? 0) + 1;
    }
}
$people = [];
foreach ($all as $c) if ($c['kind'] === 'person' && $c['company_id']) $people[(int)$c['company_id']][] = $c;
$relBy = [];
foreach ($relRows as $r) $relBy[(int)$r['contact_id']] = $r;

$pg = paginate($rows, 'pc', $tab === 'empresas' ? 12 : PAGE_SIZE);   // os cartões enchem 2, 3 ou 4 colunas
if ($pg['page'] > 1) $filters['pc'] = (string)$pg['page']; else unset($filters['pc']);
$self = ct_url($filters);

// KPIs
$nPeople = count(array_filter($all, fn($c) => $c['kind'] === 'person'));
$nCompanies = count(array_filter($all, fn($c) => $c['kind'] === 'company'));
$provAll = array_filter($all, fn($c) => contact_is_provider($c));
$provActive = count(array_filter($provAll, fn($c) => (int)$c['is_active'] === 1));
$monthStart = (new DateTimeImmutable($today))->format('Y-m-01') . ' 00:00:00';
$newMonth = count(array_filter($all, fn($c) => (string)$c['created_at'] >= $monthStart));

// Painel e formulários abertos por endereço (sem JavaScript também).
$openId = (int)($_GET['open'] ?? 0);
$openC = $openId ? contact_get($pdo, $openId) : null;
$novo = $canEdit ? (string)($_GET['novo'] ?? '') : '';
$delC = $canManage && !empty($_GET['del']) ? contact_get($pdo, (int)$_GET['del']) : null;
$imp = $canEdit && ($_GET['importar'] ?? '') === '1' ? ($_SESSION['ct_import'] ?? null) : null;

render_header('Contactos', 'contactos');
render_flashes();
echo paginate_css();
$chip = fn(array $set, array $unset = []) => ct_url(array_diff_key(array_merge($filters, $set), array_flip(array_merge($unset, ['pc']))));
$stars = function (int $cid) use ($ratings) { return isset($ratings[$cid]) ? rating_html($ratings[$cid][0], $ratings[$cid][1]) : '<span class="muted">—</span>'; };
$onDot = fn(array $c) => '<span class="on-dot' . ((int)$c['is_active'] ? '' : ' off') . '"><i></i>' . ((int)$c['is_active'] ? 'Ativo' : 'Inativo') . '</span>';
$typeChips = fn(array $c) => implode(' ', array_map(fn($t) => '<span class="tag2">' . e($t) . '</span>', $c['types'])) ?: '<span class="muted">—</span>';
$nameCell = function (array $c) {
    $sub = $c['kind'] === 'company' ? 'Empresa' : ($c['company_name'] ?: ((int)$c['is_freelancer'] ? 'Freelancer' : 'Pessoa'));
    return '<td class="nm"><a class="b" href="/admin/contact.php?id=' . (int)$c['id'] . '" data-panel="contact:' . (int)$c['id'] . '">'
         . ($c['kind'] === 'company' ? '<span class="k-ico" aria-hidden="true">' . icon('building') . '</span>' : '') . e($c['name']) . '</a>'
         . '<small>' . e($sub) . ($c['role'] !== '' ? ' · ' . e($c['role']) : '') . '</small></td>';
};
$contactCell = function (array $c) {
    $ph = lead_phones((string)$c['phone'])[0] ?? (string)$c['phone'];
    return '<td class="hide-sm small">' . ($ph !== '' ? '<div><a href="tel:' . e(lead_phone_dial($ph)) . '">' . e($ph) . '</a></div>' : '')
         . ($c['email'] !== '' ? '<div><a href="mailto:' . e($c['email']) . '">' . e(shorten($c['email'], 34)) . '</a></div>' : '')
         . ($ph === '' && $c['email'] === '' ? '<span class="muted">—</span>' : '') . '</td>';
};
?>
<?php if ($openC): ?><div data-open-panel="contact:<?= (int)$openC['id'] ?>" hidden></div><?php endif; ?>
<div class="h1-row"><h1>Contactos</h1><?= cfg_link('contactos') ?></div>

<div class="stats">
  <a class="stat hero" href="<?= e(ct_url(['t' => 'relacoes', 'rel' => 'atraso'])) ?>"><div class="k">Relações em atraso</div><div class="v"><?= $relLate ?></div><div class="n">de <?= count($relRows) ?> acompanhadas</div></a>
  <a class="stat" href="<?= e(ct_url()) ?>"><div class="k">Pessoas</div><div class="v"><?= $nPeople ?></div><div class="n">e <?= $nCompanies ?> empresas</div></a>
  <a class="stat" href="<?= e(ct_url(['t' => 'fornecedores', 'estado' => 'ativos'])) ?>"><div class="k">Fornecedores ativos</div><div class="v"><?= $provActive ?></div><div class="n">com freelancers · <?= count($provAll) - $provActive ?> inativos</div></a>
  <div class="stat"><div class="k">Novos este mês</div><div class="v"><?= $newMonth ?></div><div class="n">contactos criados</div></div>
</div>

<div class="tabs-row">
  <div class="tabs">
    <?php foreach (CT_TABS as $k => $label): ?>
      <a href="<?= e(ct_url($k === 'todos' ? [] : ['t' => $k])) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($label) ?> (<?= $counts[$k] ?>)</a>
    <?php endforeach; ?>
  </div>
  <?php if ($canEdit): ?>
    <span class="add-lead tabs-acts">
      <a class="btn dark" href="<?= e(ct_url($filters + ['novo' => 1])) ?>" data-new="#mdl-cnew" data-new-contact aria-keyshortcuts="N" data-tip="Novo contacto  ·  N"><?= icon('plus') ?> Adicionar contacto</a>
      <a class="btn ghost" href="<?= e(ct_url($filters + ['importar' => 'escolher'])) ?>" data-new="#mdl-imp" data-import data-tip="Importar CSV ou vCard"><?= icon('upload') ?> Importar</a>
    </span>
  <?php endif; ?>
</div>

<?php if ($tab === 'relacoes'): ?>
<nav class="views lead-views" aria-label="Filtros das relações">
  <a class="urg<?= ($filters['rel'] ?? '') === 'atraso' ? ' on' : '' ?>" href="<?= e(($filters['rel'] ?? '') === 'atraso' ? $chip([], ['rel']) : $chip(['rel' => 'atraso'])) ?>">Em atraso <i><?= $relCount['atraso'] ?></i></a>
  <span class="sep" aria-hidden="true"></span>
  <a href="<?= e($chip([], ['rel'])) ?>" class="<?= empty($filters['rel']) ? 'on' : '' ?>">Todas</a>
  <?php foreach (CT_REL_FILTERS as $k => $label): if ($k === 'atraso') continue; ?>
    <a href="<?= e(($filters['rel'] ?? '') === $k ? $chip([], ['rel']) : $chip(['rel' => $k])) ?>" class="<?= ($filters['rel'] ?? '') === (string)$k ? 'on' : '' ?>"><?= e($label) ?> <span class="mono"><?= $relCount[$k] ?></span></a>
  <?php endforeach; ?>
</nav>
<?php elseif ($tab === 'todos' && $dupIds): ?>
<nav class="views lead-views" aria-label="Filtros rápidos">
  <a href="<?= e($chip([], ['dup'])) ?>" class="<?= empty($filters['dup']) ? 'on' : '' ?>">Todos</a>
  <a href="<?= e(!empty($filters['dup']) ? $chip([], ['dup']) : $chip(['dup' => 1])) ?>" class="<?= !empty($filters['dup']) ? 'on' : '' ?>" data-tip="Mesmo email ou telefone: abra e use «Juntar»">Possíveis duplicados <span class="mono"><?= count($dupIds) ?></span></a>
</nav>
<?php endif; ?>

<form class="bar" method="get" data-auto>
  <?php foreach (['t', 'rel', 'dup'] as $k): if (isset($filters[$k])): ?><input type="hidden" name="<?= $k ?>" value="<?= e($filters[$k]) ?>"><?php endif; endforeach; ?>
  <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="<?= $tab === 'relacoes' ? 'Procurar contacto ou responsável' : 'Procurar nome, empresa, email, telefone, NIF' ?>" aria-label="Procurar" data-refocus>
  <?php if (in_array($tab, ['fornecedores', 'freelancers'], true)): ?>
  <select name="svc" aria-label="Serviço"><option value="">Todos os serviços</option>
    <?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)($filters['svc'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
  </select>
  <select name="estado" aria-label="Estado"><option value="">Ativos e inativos</option>
    <option value="ativos" <?= ($filters['estado'] ?? '') === 'ativos' ? 'selected' : '' ?>>Só ativos</option>
    <option value="inativos" <?= ($filters['estado'] ?? '') === 'inativos' ? 'selected' : '' ?>>Só inativos</option>
  </select>
  <?php endif; ?>
  <button class="btn sm apply" type="submit">Filtrar</button>
  <?php if (array_diff_key($filters, ['pc' => 1, 't' => 1])): ?><a class="lnk" href="<?= e(ct_url($tab === 'todos' ? [] : ['t' => $tab])) ?>">Limpar</a><?php endif; ?>
</form>

<?php if ($tab === 'empresas'): ?>
  <?php if (!$rows): ?><p class="empty muted">Nenhuma empresa aqui.</p><?php endif; ?>
  <div class="co-grid">
  <?php foreach ($pg['rows'] as $c): $cid = (int)$c['id']; $pp = $people[$cid] ?? []; $meta = array_values($c['types']);
      if (contact_is_provider($c) && !(int)$c['is_active']) $meta[] = 'Inativo';
      $meta[] = count($pp) . (count($pp) === 1 ? ' pessoa' : ' pessoas');
      if (!empty($dealCount[$cid])) $meta[] = $dealCount[$cid] . ($dealCount[$cid] === 1 ? ' negócio' : ' negócios');
      if (!empty($brought[$cid])) $meta[] = $brought[$cid] . ($brought[$cid] === 1 ? ' fonte' : ' fontes'); ?>
    <article class="cocard">
      <h3><a href="/admin/contact.php?id=<?= $cid ?>" data-panel="contact:<?= $cid ?>"><?= e($c['name']) ?></a></h3>
      <div class="meta"><?= e(implode(' · ', $meta)) ?></div>
      <?php if ($c['phone'] !== '' || $c['email'] !== ''): ?><div class="meta small"><?= e(implode(' · ', array_filter([lead_phones((string)$c['phone'])[0] ?? $c['phone'], $c['email']], 'strlen'))) ?></div><?php endif; ?>
      <?php if ($pp): ?><ul class="ppl">
        <?php foreach (array_slice($pp, 0, 5) as $p): $bits = array_filter([(string)$p['role'], lead_phones((string)$p['phone'])[0] ?? (string)$p['phone'], (string)$p['email']], 'strlen'); ?>
          <li><a href="/admin/contact.php?id=<?= (int)$p['id'] ?>" data-panel="contact:<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a><?= $bits ? ' <span class="muted">· ' . e(implode(' · ', $bits)) . '</span>' : '' ?></li>
        <?php endforeach; ?>
        <?php if (count($pp) > 5): ?><li class="muted">+ <?= count($pp) - 5 ?> pessoas</li><?php endif; ?>
      </ul><?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
<?php elseif ($tab === 'relacoes'): ?>
<div class="tablewrap">
<table class="leads-t rel-t">
  <thead><tr><th><span class="sr">Nível</span></th><th>Contacto</th><th>Saúde</th><th>Próximo</th><th class="num hide-sm">Gerou</th><th class="hide-sm">Responsável</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6" class="empty"><?= $relRows ? 'Nenhuma relação neste filtro.' : 'Ainda nenhuma relação acompanhada. Abra um contacto e use «Acompanhar relação».' ?></td></tr><?php endif; ?>
  <?php foreach ($pg['rows'] as $r): $cid = (int)$r['contact_id']; $h = $r['health'];
      $sub = $r['kind'] === 'company' ? 'Empresa' : ($r['company_name'] ?: ((int)$r['is_freelancer'] ? 'Freelancer' : 'Pessoa'));
      $nextTxt = $r['paused'] ? 'pausada até ' . lisbon_fmt(lisbon_ts($r['paused_until'] . ' 12:00'), 'd/m')
               : ($r['next_at'] ? (($h['level'] ?? '') === 'late' ? 'atrasado' : lisbon_fmt(lisbon_ts($r['next_at'] . ' 12:00'), 'd/m')) : '—'); ?>
    <tr data-panel="contact:<?= $cid ?>" class="<?= $h ? 'lvl-' . $h['level'] : '' ?>">
      <td><?= rel_level_badge((string)$r['level']) ?></td>
      <td class="nm"><a class="b" href="/admin/contact.php?id=<?= $cid ?>" data-panel="contact:<?= $cid ?>"><?= e($r['name']) ?></a><small><?= e($sub) ?></small></td>
      <td class="nowrap"><?= $r['paused'] ? '<span class="chip">' . icon('pause') . ' Pausada</span>' : lead_ring($h) ?></td>
      <td class="nowrap<?= ($h['level'] ?? '') === 'late' ? ' neg' : '' ?>"><?= e($nextTxt) ?></td>
      <td class="num hide-sm"><?= $r['generated'] ? e(eur0($r['generated'])) : '<span class="muted">—</span>' ?></td>
      <td class="hide-sm"><?= e($r['owner_name'] ?? '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: $prov = in_array($tab, ['fornecedores', 'freelancers'], true); ?>
<div class="tablewrap">
<table class="leads-t ct-t">
  <thead><tr><th>Nome</th>
    <?php if ($prov): ?><th>Serviços</th><th class="hide-sm">Preço</th><th>Avaliação</th><th>Estado</th>
    <?php elseif ($tab === 'clientes'): ?><th>Tipo</th><th class="hide-sm">Contacto</th><th class="num">Negócios</th>
    <?php elseif ($tab === 'parceiros'): ?><th class="hide-sm">Contacto</th><th class="num">Trouxe</th><th>Relação</th>
    <?php else: ?><th>Tipo</th><th class="hide-sm">Contacto</th><th>Estado</th><?php endif; ?>
  </tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="5" class="empty">Nenhum contacto aqui.</td></tr><?php endif; ?>
  <?php foreach ($pg['rows'] as $c): $cid = (int)$c['id']; $ls = $svcBy[$cid] ?? []; ?>
    <tr id="c-<?= $cid ?>" data-panel="contact:<?= $cid ?>">
      <?= $nameCell($c) ?>
      <?php if ($prov): ?>
        <td><?= $ls ? implode(' ', array_map(fn($l) => '<span class="tag2">' . e($l['name'] ?? 'por rever') . '</span>', array_slice($ls, 0, 4))) . (count($ls) > 4 ? ' <span class="muted">+' . (count($ls) - 4) . '</span>' : '') : '<span class="muted">—</span>' ?></td>
        <td class="hide-sm nowrap mono small"><?= $ls && $ls[0]['price'] !== null ? e(service_line_price($ls[0])) : '<span class="muted">—</span>' ?></td>
        <td class="nowrap"><?= $stars($cid) ?></td>
        <td class="nowrap"><?= $onDot($c) ?></td>
      <?php elseif ($tab === 'clientes'): ?>
        <td><?= $typeChips($c) ?></td><?= $contactCell($c) ?>
        <td class="num"><?= (int)($dealCount[$cid] ?? 0) ?: '<span class="muted">—</span>' ?></td>
      <?php elseif ($tab === 'parceiros'): ?>
        <?= $contactCell($c) ?>
        <td class="num"><?= (int)($brought[$cid] ?? 0) ?: '<span class="muted">—</span>' ?></td>
        <td><?= isset($relBy[$cid]) ? rel_level_badge((string)$relBy[$cid]['level']) : '<span class="muted">—</span>' ?></td>
      <?php else: ?>
        <td><?= $typeChips($c) ?><?= isset($dupIds[$cid]) ? ' <span class="chip wait" data-tip="Mesmo email ou telefone que outro contacto">Duplicado?</span>' : '' ?></td>
        <?= $contactCell($c) ?>
        <td class="nowrap"><?= contact_is_provider($c) ? $onDot($c) : '<span class="muted">—</span>' ?></td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?= page_nav($pg, ['open']) ?>

<?php // ── + Adicionar contacto: cresce a partir do botão (§13.8) ──
if ($canEdit):
    $old = $_SESSION['contact_form'] ?? []; unset($_SESSION['contact_form']);
    $kindNew = ($old['kind'] ?? '') === 'company' || $novo === 'empresa' ? 'company' : 'person';
    $coPre = '';
    if (!empty($_GET['empresa']) && ($co = contact_get($pdo, (int)$_GET['empresa'])) && $co['kind'] === 'company') { $coPre = $co['name']; $kindNew = 'person'; }
    $ov = fn(string $k, string $d = '') => e((string)($old[$k] ?? $d));
    $chk = fn(string $f) => !empty($old[$f]) || (!$old && isset(CT_TAB_FLAG[$tab]) && CT_TAB_FLAG[$tab] === $f && ($f !== 'is_freelancer' || $kindNew === 'person'));
    $showSvc = $chk('is_supplier') || $chk('is_freelancer');
    $companies = $pdo->query("SELECT name FROM contacts WHERE kind = 'company' ORDER BY name LIMIT 2000")->fetchAll(PDO::FETCH_COLUMN);
    $svcOpts = fn($sel) => '<option value="">Escolher…</option>' . implode('', array_map(fn($s) => '<option value="' . (int)$s['id'] . '"' . ((string)$sel === (string)$s['id'] ? ' selected' : '') . '>' . e($s['name']) . '</option>', services_list($pdo)));
    $unitOpts = fn($sel) => implode('', array_map(fn($k, $u) => '<option value="' . $k . '"' . ($k === ($sel ?: 'day') ? ' selected' : '') . '>' . e($u) . '</option>', array_keys(SERVICE_UNITS), SERVICE_UNITS));
    $oldLines = array_values(array_filter((array)($old['svc'] ?? []), 'is_array')) ?: [[]];
    $lineRow = fn(int $i, array $l) => '<tr data-svc-row>'
        . '<td><select name="svc[' . $i . '][service_id]" aria-label="Serviço">' . $svcOpts($l['service_id'] ?? '') . '</select></td>'
        . '<td><select name="svc[' . $i . '][unit]" aria-label="Unidade">' . $unitOpts($l['unit'] ?? '') . '</select></td>'
        . '<td><input type="text" name="svc[' . $i . '][price]" inputmode="decimal" maxlength="14" placeholder="280" aria-label="Preço sem IVA (€)" value="' . e((string)($l['price'] ?? '')) . '"></td>'
        . '<td class="c"><input type="checkbox" name="svc[' . $i . '][vat]" value="1" aria-label="Cobra IVA"' . (!$l || !empty($l['vat']) ? ' checked' : '') . '></td>'
        . '<td><input type="text" name="svc[' . $i . '][notes]" maxlength="2000" placeholder="Notas" aria-label="Notas" value="' . e((string)($l['notes'] ?? '')) . '"></td>'
        . '<td>' . icon_button('x', 'Tirar esta linha', ['data-svc-row-del' => '1']) . '</td></tr>';
?>
<div class="mdl<?= $novo !== '' ? ' open' : '' ?>" id="mdl-cnew" data-from="[data-new-contact]" data-clear="novo empresa" role="dialog" aria-modal="true" aria-labelledby="mdl-cnew-t"<?= $novo !== '' ? '' : ' hidden' ?>>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card wide" method="post" action="/admin/contactos.php" autocomplete="off" data-cnew-form>
    <?= csrf_field() ?><?= back_fields(array_diff_key($filters, ['pc' => 1])) ?>
    <input type="hidden" name="do" value="create">
    <h3 id="mdl-cnew-t">Novo contacto</h3>
    <p class="muted">Uma pessoa pode ter empresa (a empresa fica também em Contactos); um freelancer é uma pessoa sem empresa.</p>
    <div class="chips-check kind-pick" role="radiogroup" aria-label="Pessoa ou empresa">
      <label class="rb"><input type="radio" name="kind" value="person" <?= $kindNew === 'person' ? 'checked' : '' ?>><span><?= icon('user') ?> Pessoa</span></label>
      <label class="rb"><input type="radio" name="kind" value="company" <?= $kindNew === 'company' ? 'checked' : '' ?>><span><?= icon('building') ?> Empresa</span></label>
    </div>
    <div class="grid">
      <div class="field"><label for="nc-name">Nome</label><input type="text" id="nc-name" name="name" maxlength="160" required value="<?= $ov('name') ?>" data-cdup></div>
      <div class="field" data-only="person"><label for="nc-role">Cargo</label><input type="text" id="nc-role" name="role" maxlength="120" value="<?= $ov('role') ?>" placeholder="Produtora, diretor criativo…"></div>
      <div class="field" data-only="person"><label for="nc-co">Empresa</label>
        <input type="text" id="nc-co" name="company" maxlength="160" list="dl-ct-companies" value="<?= $ov('company', $coPre) ?>" placeholder="Procurar ou criar · vazio = sem empresa">
        <datalist id="dl-ct-companies"><?php foreach ($companies as $cn): ?><option value="<?= e($cn) ?>"><?php endforeach; ?></datalist></div>
      <div class="field"><label for="nc-ph">Telefone(s)</label><input type="text" id="nc-ph" name="phone" maxlength="255" inputmode="tel" value="<?= $ov('phone') ?>" placeholder="+351 … (vários: separe com |)" data-cdup></div>
      <div class="field"><label for="nc-em">Email</label><input type="email" id="nc-em" name="email" maxlength="190" value="<?= $ov('email') ?>" data-cdup></div>
      <div class="dup field wide" hidden aria-live="polite"></div>
      <div class="field wide"><label for="nc-li">Site e redes sociais</label><textarea id="nc-li" name="links" rows="2" placeholder="Um link por linha (site, Instagram, LinkedIn, Vimeo…)"><?= $ov('links') ?></textarea></div>
      <div class="field"><label for="nc-nif">NIF</label><input type="text" id="nc-nif" name="nif" maxlength="20" inputmode="numeric" value="<?= $ov('nif') ?>"></div>
      <div class="field"><label for="nc-ad">Morada</label><input type="text" id="nc-ad" name="address" maxlength="255" value="<?= $ov('address') ?>"></div>
      <div class="field" data-only="person"><label for="nc-bd">Aniversário</label><input type="date" id="nc-bd" name="birthday" value="<?= $ov('birthday') ?>"></div>
      <div class="field wide"><span class="lbl">Tipo</span>
        <div class="chips-check" role="group" aria-label="Tipo">
          <?php foreach (CONTACT_TYPES as $f => $label): ?>
            <label class="rb"<?= $f === 'is_freelancer' ? ' data-only="person"' : '' ?>><input type="checkbox" name="<?= $f ?>" value="1" <?= $chk($f) ? 'checked' : '' ?> data-type="<?= $f ?>"><span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div></div>
      <div class="field wide svc-box" data-svc-box<?= $showSvc ? '' : ' hidden' ?>>
        <span class="lbl"><?= icon('euro') ?> Serviços e preços <span class="muted">· usados na Orçamentação</span></span>
        <table class="svc-new">
          <thead><tr><th>Serviço</th><th>Unidade</th><th>Preço s/ IVA (€)</th><th class="c">IVA</th><th>Notas</th><th><span class="sr">Tirar</span></th></tr></thead>
          <tbody><?php foreach ($oldLines as $i => $l) echo $lineRow($i, $l); ?></tbody>
        </table>
        <template data-svc-tpl><?= $lineRow(999, []) ?></template>
        <div class="svc-acts"><button type="button" class="lnk" data-svc-row-add><?= icon('plus') ?> Adicionar serviço</button>
          <label class="small">Preços válidos desde <input type="date" name="valid_from" value="<?= $ov('valid_from', $today) ?>"></label></div>
      </div>
      <div class="field wide"><label for="nc-no">Notas</label><textarea id="nc-no" name="notes" rows="2"><?= $ov('notes') ?></textarea></div>
    </div>
    <div class="actions">
      <button type="button" class="btn ghost" data-mdl-close>Cancelar</button>
      <span class="spacer"></span>
      <button class="btn ghost" type="submit" name="again" value="1">Gravar e adicionar outro</button>
      <button class="btn" type="submit">Gravar</button>
    </div>
  </form>
</div>

<div class="mdl<?= ($_GET['importar'] ?? '') === 'escolher' ? ' open' : '' ?>" id="mdl-imp" data-from="[data-import]" data-clear="importar" role="dialog" aria-modal="true" aria-labelledby="mdl-imp-t"<?= ($_GET['importar'] ?? '') === 'escolher' ? '' : ' hidden' ?>>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card" method="post" action="/admin/contactos.php" enctype="multipart/form-data">
    <?= csrf_field() ?><?= back_fields(array_diff_key($filters, ['pc' => 1])) ?>
    <input type="hidden" name="do" value="import_preview">
    <h3 id="mdl-imp-t">Importar contactos</h3>
    <p class="muted">CSV com cabeçalho (Nome, Empresa, Cargo, Email, Telefone, NIF, Site, Morada, Tipo, Notas — «;» ou «,») ou vCard (.vcf, exportado do telemóvel, Gmail ou Outlook). Antes de gravar mostro o que encontrei e os possíveis duplicados.</p>
    <div class="field"><label for="imp-file">Ficheiro</label><input type="file" id="imp-file" name="file" accept=".csv,.vcf,.vcard,text/csv,text/vcard" required></div>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><button class="btn" type="submit"><?= icon('upload') ?> Ler o ficheiro</button></div>
  </form>
</div>

<?php if ($imp): $nDup = count(array_filter($imp['rows'], fn($r) => $r['match'])); ?>
<div class="mdl open" id="mdl-impv" data-from="[data-import]" role="dialog" aria-modal="true" aria-labelledby="mdl-impv-t">
  <a class="mdl-back" href="<?= e($self) ?>" aria-label="Fechar"></a>
  <form class="mdl-card wide" method="post" action="/admin/contactos.php">
    <?= csrf_field() ?><?= back_fields(array_diff_key($filters, ['pc' => 1])) ?>
    <input type="hidden" name="do" value="import_commit">
    <h3 id="mdl-impv-t">Importar «<?= e($imp['file']) ?>»</h3>
    <p class="muted"><?= count($imp['rows']) ?> contactos encontrados<?= $nDup ? ' · ' . $nDup . ' já existem (mesmo email, telefone ou nome): por defeito completam o que existe, sem apagar nada' : '' ?>.</p>
    <div class="imp-list">
    <table class="imp-t">
      <thead><tr><th>Nome</th><th class="hide-sm">Email · telefone</th><th>O que fazer</th></tr></thead>
      <tbody>
      <?php foreach ($imp['rows'] as $i => $r): ?>
        <tr class="<?= $r['match'] ? 'dupr' : '' ?>">
          <td><b><?= e($r['name']) ?></b><small><?= $r['kind'] === 'company' ? 'Empresa' : ($r['company'] !== '' ? e($r['company']) : 'Pessoa') ?><?= $r['role'] !== '' ? ' · ' . e($r['role']) : '' ?></small></td>
          <td class="hide-sm small"><?= e(implode(' · ', array_filter([$r['email'], lead_phones($r['phone'])[0] ?? $r['phone']], 'strlen')) ?: '—') ?></td>
          <td><select name="act[<?= $i ?>]" aria-label="O que fazer com <?= e($r['name']) ?>">
            <?php if ($r['match']): ?><option value="update" selected>Completar «<?= e(shorten($r['match']['name'], 28)) ?>»</option><option value="create">Criar outro</option>
            <?php else: ?><option value="create" selected>Criar</option><?php endif; ?>
            <option value="skip">Ignorar</option></select></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="actions"><a class="btn ghost" href="<?= e($self) ?>">Cancelar</a><button class="btn" type="submit"><?= icon('check') ?> Importar</button></div>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($delC): $refs = contact_refs($pdo, (int)$delC['id']); ?>
<div class="mdl open" role="dialog" aria-modal="true" aria-labelledby="mdl-del-t">
  <a class="mdl-back" href="<?= e($self) ?>" aria-label="Fechar"></a>
  <form class="mdl-card pop-in" method="post" action="/admin/contactos.php">
    <?= csrf_field() ?><?= back_fields(array_diff_key($filters, ['pc' => 1])) ?>
    <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$delC['id'] ?>">
    <h3 id="mdl-del-t">Eliminar «<?= e($delC['name']) ?>»?</h3>
    <?php if ($refs): ?>
      <p>Está ligado a negócios, leads, custos ou pessoas (<?= $refs ?>). Desligue-o primeiro — ou, se for um duplicado, abra-o e use «Juntar».</p>
      <div class="actions"><a class="btn" href="<?= e($self) ?>">Fechar</a></div>
    <?php else: ?>
      <p class="muted">O contacto, os serviços, a relação e o histórico desaparecem. Isto não se anula.</p>
      <div class="actions"><a class="btn ghost" href="<?= e($self) ?>">Cancelar</a><button class="btn danger" type="submit">Sim, eliminar</button></div>
    <?php endif; ?>
  </form>
</div>
<?php endif; ?>

<?= $canEdit ? contact_modals_html($pdo) : '' ?>
<script src="<?= asset_url('contacts.js') ?>" defer></script>
<?php render_footer();
