<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/leads.php';
require_once __DIR__ . '/inc/metrics_view.php';
require_once __DIR__ . '/inc/settings_def.php';   // setting_log (registo de alterações das metas)
$user = require_login();
require_perm('relatorios');

// Relatórios (§29): o micro — onde está o problema, quem, que registo, o que fazer. Separadores Atenção (abre aqui)
// · Metas · Funil comercial · Velocidade · Origens · Atividade · Equipa · Operacional · Financeiro. Um período com
// comparação ao anterior e os filtros globais (funil, origem, pessoa, serviço) mantêm-se entre separadores. Cada
// número abre os registos que o compõem; ⓘ diz a fórmula; cada separador sai em CSV ou PDF (imprimir). Os euros só
// aparecem a quem vê o Financeiro. As contas estão todas em inc/metrics.php (as mesmas do Dashboard e dos alertas).

$money = can('financeiro');
$admin = role_is_admin((string)$user['role']);
$uid = (int)$user['id'];

const REP_TABS = ['atencao' => 'Atenção', 'metas' => 'Metas', 'funil' => 'Funil comercial', 'velocidade' => 'Velocidade', 'origens' => 'Origens',
                  'atividade' => 'Atividade', 'equipa' => 'Equipa', 'operacional' => 'Operacional', 'financeiro' => 'Financeiro'];
$tabs = REP_TABS;
if (!$money) unset($tabs['financeiro']);
$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string)$_GET['tab'] : 'atencao';

$funnels = $pdo->query('SELECT id, name FROM funnels ORDER BY position, name')->fetchAll(PDO::FETCH_KEY_PAIR);
$people = mx_people($pdo);

// ── período e filtros (mantêm-se entre separadores) ──
$p = isset(MX_PERIODS[$_GET['p'] ?? '']) ? (string)$_GET['p'] : 'mes';
$per = mx_period($p, (int)($_GET['n'] ?? 0), (string)($_GET['de'] ?? ''), (string)($_GET['ate'] ?? ''));
$f = [
    'fu' => isset($funnels[(int)($_GET['fu'] ?? 0)]) ? (int)$_GET['fu'] : 0,
    'or' => isset(LEAD_ORIGIN[$_GET['or'] ?? '']) ? (string)$_GET['or'] : '',
    'pe' => isset($people[(int)($_GET['pe'] ?? 0)]) ? (int)$_GET['pe'] : 0,
    'sv' => isset($funnels[(int)($_GET['sv'] ?? 0)]) ? (int)$_GET['sv'] : 0,
];
// os operadores só filtram por si próprios (os números dos colegas não aparecem com nome)
if (!$admin && $f['pe'] && $f['pe'] !== $uid) $f['pe'] = 0;
$q = ['tab' => $tab, 'p' => $p, 'n' => $per['n'] ?: null, 'de' => $p === 'custom' ? $per['from'] : null,
      'ate' => $p === 'custom' ? date('Y-m-d', strtotime($per['to'] . ' -1 day')) : null] + $f;
mx_ctx(['q' => $q, 'per' => $per]);

// ── metas: gravar (só superadmin e admins) ──
$goalMsg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if (!$admin) { http_response_code(403); exit('Só os administradores definem metas.'); }
    $do = (string)($_POST['do'] ?? '');
    $scope = (string)($_POST['scope'] ?? 'company');
    $sid = $scope === 'funnel' ? (int)($_POST['scope_funnel'] ?? 0) : ($scope === 'user' ? (int)($_POST['scope_user'] ?? 0) : 0);
    $period = ($_POST['period'] ?? 'month') === 'quarter' ? 'quarter' : 'month';
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['start'] ?? '')) ? (string)$_POST['start'] : today_lisbon();
    try {
        if ($scope === 'funnel' && !isset($funnels[$sid])) throw new RuntimeException('Escolha o funil.');
        if ($scope === 'user' && !isset($people[$sid])) throw new RuntimeException('Escolha a pessoa.');
        if ($do === 'save') {
            $n = 0;
            foreach (MX_GOAL_METRICS as $m => [$label, $unit]) {
                if ($unit === 'eur' && !$money) continue;
                $raw = trim((string)($_POST['g'][$m] ?? ''));
                if ($raw === '' && !isset($_POST['had'][$m])) continue;
                mx_goal_save($pdo, $scope, $sid, $period, $start, $m, (float)str_replace(',', '.', $raw), !empty($_POST['auto'][$m]), $uid);
                $n++;
            }
            setting_log($pdo, 'metas', 'Relatórios · Metas', '', ($scope === 'company' ? 'Empresa' : ($scope === 'funnel' ? $funnels[$sid] : $people[$sid])) . ' · ' . mx_goal_period_label($period, mx_goal_bounds($period, $start)[0]));
            flash($n ? 'Metas gravadas.' : 'Nada para gravar.');
            redirect(mx_href(['tab' => 'metas', 'gs' => $scope, 'gi' => $sid ?: null, 'gp' => $period, 'gd' => mx_goal_bounds($period, $start)[0]]));
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
        redirect(mx_href(['tab' => 'metas']));
    }
}

// ── a lista de registos por trás de um número ──
if (isset($_GET['lista'])) {
    $kind = in_array($_GET['lista'], ['d', 't', 'f', 'i', 'h'], true) ? (string)$_GET['lista'] : 'd';
    [$rows, $hidden] = mx_list_rows($pdo, $kind, array_slice(explode(',', (string)($_GET['ids'] ?? '')), 0, 400));
    $title = mb_substr(trim((string)($_GET['lt'] ?? 'Registos')), 0, 120);
    $head = ['ID', 'Nome', 'Estado', 'Funil', 'Origem', 'Responsável', 'Nota / motivo', 'Data'];
    $showMoney = $money && $kind !== 't' && $kind !== 'i' && $kind !== 'h';
    if (($_GET['export'] ?? '') === 'csv') {
        $GLOBALS['MX_CSV'] = [[$title, $showMoney ? array_merge($head, ['#Valor']) : $head, array_map(fn($r) => $showMoney ? array_merge($r['cells'], [number_format((float)($r['money'] ?? 0), 2, ',', '')]) : $r['cells'], $rows), []]];
        mx_csv_send('relatorios-' . $title);
        exit;
    }
    $table = '<div class="tablewrap"><table class="mx-list"><thead><tr>';
    foreach ($head as $h) $table .= '<th>' . e($h) . '</th>';
    $table .= ($showMoney ? '<th class="num">Valor</th>' : '') . '<th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $table .= '<tr>';
        foreach ($r['cells'] as $i => $c) $table .= '<td' . ($i === 0 ? ' class="mono small"' : '') . '>' . e((string)$c) . '</td>';
        $table .= ($showMoney ? '<td class="num">' . (isset($r['money']) ? eur0((float)$r['money']) : '') . '</td>' : '') . '<td><a class="btn sm ghost" href="' . e($r['url']) . '">Abrir</a></td></tr>';
    }
    if (!$rows) $table .= '<tr><td colspan="' . (count($head) + 2) . '" class="empty">Nenhum registo que possa abrir.</td></tr>';
    $table .= '</tbody></table></div>' . ($hidden ? '<p class="small muted">' . $hidden . ($hidden === 1 ? ' registo de outra pessoa conta para o número' : ' registos de outras pessoas contam para o número') . ', mas não estão no seu acesso.</p>' : '');
    $csv = mx_href(['lista' => $kind, 'ids' => (string)($_GET['ids'] ?? ''), 'lt' => $title, 'export' => 'csv']);
    if (!empty($_GET['frag'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="mx-listhead"><h2>' . e($title) . ' <span class="muted small">· ' . count($rows) . '</span></h2><a class="btn sm ghost" href="' . e($csv) . '">' . icon('download') . ' CSV</a></div>' . $table;
        exit;
    }
    render_header('Relatórios', 'relatorios', '', [$title]);
    echo '<div class="mx-listhead"><h1>' . e($title) . ' <span class="muted">· ' . count($rows) . '</span></h1><div class="fin-tools"><a class="btn sm ghost" href="' . e(mx_href(['lista' => null, 'ids' => null, 'lt' => null])) . '">← Relatórios</a><a class="btn sm ghost" href="' . e($csv) . '">' . icon('download') . ' CSV</a></div></div>' . $table;
    render_footer();
    exit;
}

// ── o separador ──
require_once __DIR__ . '/inc/report_tabs.php';
$all = metrics_deals($pdo);
$c = ['pdo' => $pdo, 'all' => $all, 'deals' => mx_filter($all, $f), 'per' => $per, 'f' => $f, 'money' => $money, 'admin' => $admin, 'uid' => $uid,
      'funnels' => $funnels, 'people' => $people, 'user' => $user];
$GLOBALS['MX_CSV'] = [];
ob_start();
$fn = 'rep_' . $tab;
$fn($c);
$body = ob_get_clean();
if (($_GET['export'] ?? '') === 'csv') {
    mx_csv_send('relatorios-' . $tab . '-' . $per['from']);
    exit;
}

$att = rep_attention_items($c);
render_header('Relatórios', 'relatorios', 'mx-page', [$tabs[$tab]]);
render_flashes();
$nav = fn(int $d) => mx_href(['n' => min(0, $per['n'] + $d) ?: null]);
?>
<div class="fin-head">
  <h1>Relatórios</h1>
  <div class="fin-tools mx-noprint">
    <a class="btn sm ghost" href="<?= e(mx_href(['export' => 'csv'])) ?>"><?= icon('download') ?> CSV</a>
    <button class="btn sm ghost" type="button" data-mx-print><?= icon('file') ?> PDF</button>
  </div>
</div>
<div class="sub"><?= e($per['label']) ?> · comparado com <?= e($per['prev_label']) ?><?= array_filter($f) ? ' · com filtros' : '' ?></div>

<form class="bar mx-bar mx-noprint" method="get" data-auto>
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <?php if ($p !== 'custom'): ?>
  <a class="btn sm ghost icon-only" href="<?= e($nav(-1)) ?>" aria-label="Período anterior" data-tip="Período anterior"><?= icon('chev-left') ?></a>
  <?php endif; ?>
  <select name="p" aria-label="Período"><?php foreach (MX_PERIODS as $k => $l): ?><option value="<?= $k ?>" <?= $p === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <?php if ($p !== 'custom'): ?>
  <a class="btn sm ghost icon-only<?= $per['n'] >= 0 ? ' disabled' : '' ?>" href="<?= e($nav(1)) ?>" aria-label="Período seguinte" data-tip="Período seguinte"<?= $per['n'] >= 0 ? ' aria-disabled="true" tabindex="-1"' : '' ?>><?= icon('chev-right') ?></a>
  <?php else: ?>
  <input type="date" name="de" value="<?= e($per['from']) ?>" aria-label="De"> <input type="date" name="ate" value="<?= e(date('Y-m-d', strtotime($per['to'] . ' -1 day'))) ?>" aria-label="Até">
  <?php endif; ?>
  <select name="fu" aria-label="Funil"><option value="">Todos os funis</option><?php foreach ($funnels as $id => $n): ?><option value="<?= (int)$id ?>" <?= $f['fu'] === (int)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
  <select name="or" aria-label="Origem"><option value="">Todas as origens</option><?php foreach (LEAD_ORIGIN as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['or'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="pe" aria-label="Pessoa"><option value="">Todas as pessoas</option>
    <?php foreach ($admin ? $people : [$uid => $people[$uid] ?? 'Eu'] as $id => $n): ?><option value="<?= (int)$id ?>" <?= $f['pe'] === (int)$id ? 'selected' : '' ?>><?= e($admin ? $n : 'Só eu') ?></option><?php endforeach; ?></select>
  <select name="sv" aria-label="Serviço"><option value="">Todos os serviços</option><?php foreach ($funnels as $id => $n): ?><option value="<?= (int)$id ?>" <?= $f['sv'] === (int)$id ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
  <?php if (array_filter($f) || $p !== 'mes' || $per['n']): ?><a class="btn sm ghost" href="/admin/relatorios.php?tab=<?= e($tab) ?>">Limpar</a><?php endif; ?>
  <button class="btn sm apply" type="submit">Ver</button>
</form>

<nav class="tabs fin-tabs mx-tabs mx-noprint" aria-label="Separadores dos Relatórios">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="<?= e(mx_href(['tab' => $k])) ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?><?= $k === 'atencao' && $att ? ' <span class="cnt">' . count($att) . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>
<h2 class="mx-printonly"><?= e($tabs[$tab]) ?> · <?= e($per['label']) ?></h2>

<div class="mx-body">
<?= $body ?>
</div>

<div class="mdl" id="mdl-mx" role="dialog" aria-modal="true" aria-label="Registos" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <div class="mdl-card wide mx-mdl">
    <div class="mx-mdl-body"></div>
    <div class="actions"><button class="btn ghost" type="button" data-mdl-close>Fechar</button></div>
  </div>
</div>
<script src="<?= asset_url('reports.js') ?>" defer></script>
<?php render_footer();
