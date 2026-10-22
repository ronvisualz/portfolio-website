<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/paginate.php';
require_once __DIR__ . '/inc/budget_view.php';
require_once __DIR__ . '/inc/undo.php';
$user = require_login();
require_page('orcamentacao');

// Aba Orçamentação (§14): uma folha por negócio, criada na conversão da lead,
// no separador da fase em que o negócio está — Em orçamentação (pipeline),
// Em produção (desde o Kickoff), Ganhos (concluídos) e Arquivo (perdidos, por
// ano, fechados por defeito e com pesquisa própria). Os valores mostrados são
// os da versão que conta (a aceite, ou a última); o lucro por mês só aparece a
// quem vê o Financeiro.

$money = budget_money();
$canEdit = can('orcamentacao', PERM_EDIT);

// Modelos: apagar (quem o criou ou quem gere a Orçamentação).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['tpl_del'])) {
        $st = $pdo->prepare('SELECT * FROM budget_templates WHERE id = ?');
        $st->execute([(int)$_POST['tpl_del']]);
        $t = $st->fetch();
        if ($t && (can('orcamentacao', PERM_MANAGE) || (int)$t['created_by'] === (int)$user['id'])) {
            $pdo->prepare('DELETE FROM budget_templates WHERE id = ?')->execute([$t['id']]);
            flash('Modelo «' . $t['name'] . '» apagado.', 'ok', undo_push('budget_tpl', $t));
        } else {
            flash('Só quem criou o modelo ou quem gere a Orçamentação o pode apagar.', 'err');
        }
    }
    redirect('/admin/orcamentacao.php' . (!empty($_POST['t']) && isset(BUDGET_TABS[$_POST['t']]) ? '?t=' . $_POST['t'] : ''));
}

// Cada negócio tem a sua folha e o Resultado segue o estado (também os que chegaram por outros caminhos).
foreach ($pdo->query("SELECT d.* FROM deals d LEFT JOIN budget_sheets s ON s.deal_id = d.id
                       WHERE d.code IS NOT NULL AND d.phase IN ('pipeline','production','done','lost')
                         AND (s.id IS NULL OR (d.phase = 'done' AND s.closed_at IS NULL) OR (d.phase <> 'done' AND s.closed_at IS NOT NULL))
                       LIMIT 60")->fetchAll() as $d) {
    budget_sync_state($pdo, budget_sheet_ensure($pdo, $d), $d);
}

$tab = (string)($_GET['t'] ?? 'orcamentacao');
if (!isset(BUDGET_TABS[$tab])) $tab = 'orcamentacao';
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));

$counts = array_fill_keys(array_keys(BUDGET_TABS), 0);
foreach ($pdo->query('SELECT d.phase, COUNT(*) FROM budget_sheets s JOIN deals d ON d.id = s.deal_id GROUP BY d.phase')->fetchAll(PDO::FETCH_NUM) as [$ph, $n]) {
    $t = budget_tab_of((string)$ph);
    if (isset($counts[$t]) && in_array($ph, BUDGET_TAB_PHASE, true)) $counts[$t] += (int)$n;
}

$sql = "SELECT s.id AS sheet_id, s.accepted_version_id, s.closed_at, d.id, d.code, d.name, d.phase, d.lost_at, d.lost_reason, d.done_at, d.converted_at, d.created_at,
               COALESCE(co.name, c.name, NULLIF(d.company, '')) AS client_name, bb.name AS brought_name, st.name AS stage_name, f.name AS funnel_name,
               cv.num, cv.price, cv.profit_month, cv.work_days, cv.id AS version_id,
               (SELECT MAX(num) FROM budget_versions x WHERE x.sheet_id = s.id) AS nver,
               (SELECT COUNT(*) FROM budget_lines bl WHERE bl.version_id = cv.id) AS nlines
          FROM budget_sheets s JOIN deals d ON d.id = s.deal_id
          LEFT JOIN contacts co ON co.id = d.company_id LEFT JOIN contacts c ON c.id = d.contact_id
          LEFT JOIN contacts bb ON bb.id = d.brought_by_id LEFT JOIN funnel_stages st ON st.id = d.stage_id LEFT JOIN funnels f ON f.id = d.funnel_id
          LEFT JOIN budget_versions cv ON cv.id = COALESCE(s.accepted_version_id, (SELECT y.id FROM budget_versions y WHERE y.sheet_id = s.id ORDER BY y.num DESC LIMIT 1))
         WHERE d.phase = ?";
$args = [BUDGET_TAB_PHASE[$tab]];
if ($q !== '') {
    $sql .= " AND (d.code LIKE ? ESCAPE '!' OR d.name LIKE ? ESCAPE '!' OR co.name LIKE ? ESCAPE '!' OR c.name LIKE ? ESCAPE '!' OR d.company LIKE ? ESCAPE '!')";
    $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    array_push($args, $like, $like, $like, $like, $like);
}
$sql .= match ($tab) {
    'arquivo' => ' ORDER BY d.lost_at DESC, d.id DESC',
    'ganhos'  => ' ORDER BY d.done_at DESC, d.id DESC',
    default   => ' ORDER BY d.code DESC',
};
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$target = budget_setting($pdo, 'budget_month_target');
$statusOf = fn(float $pm) => $pm >= $target ? 'ok' : ($pm >= $target * 0.75 ? 'below' : 'far');

// KPIs da fase comercial (o que está a ser orçamentado agora).
$open = $pdo->query("SELECT cv.price, cv.profit_month, s.accepted_version_id,
                            (SELECT COUNT(*) FROM budget_lines bl WHERE bl.version_id = cv.id) AS nlines
                       FROM budget_sheets s JOIN deals d ON d.id = s.deal_id
                       LEFT JOIN budget_versions cv ON cv.id = COALESCE(s.accepted_version_id, (SELECT y.id FROM budget_versions y WHERE y.sheet_id = s.id ORDER BY y.num DESC LIMIT 1))
                      WHERE d.phase = 'pipeline'")->fetchAll();
$openValue = array_sum(array_map(fn($r) => (float)$r['price'], $open));
$withLines = array_filter($open, fn($r) => (int)$r['nlines'] > 0);
$below = count(array_filter($withLines, fn($r) => (float)$r['profit_month'] < $target));
$empty = count($open) - count($withLines);
$year = (int)substr(today_lisbon(), 0, 4);
$won = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN cv.price > 0 THEN cv.price ELSE COALESCE(d.budget, 0) END), 0) FROM budget_sheets s JOIN deals d ON d.id = s.deal_id
                        LEFT JOIN budget_versions cv ON cv.id = COALESCE(s.accepted_version_id, (SELECT y.id FROM budget_versions y WHERE y.sheet_id = s.id ORDER BY y.num DESC LIMIT 1))
                       WHERE d.phase IN ('production','done') AND COALESCE(d.converted_at, d.created_at) >= ?");
$won->execute([$year . '-01-01 00:00:00']);
[$wonN, $wonV] = $won->fetch(PDO::FETCH_NUM);

$url = fn(array $p = []) => '/admin/orcamentacao.php' . ($p ? '?' . http_build_query($p) : '');
$templates = budget_templates($pdo);

render_header('Orçamentação', 'orcamentacao');
render_flashes();
echo paginate_css();

$row = function (array $r) use ($money, $statusOf, $tab) {
    $href = '/admin/orcamento.php?id=' . (int)$r['id'];
    $who = trim(($r['client_name'] ?: 'Sem cliente') . ($r['brought_name'] ? ' · trazido por ' . $r['brought_name'] : ''));
    $hasLines = (int)$r['nlines'] > 0;
    $etapa = match ($tab) {
        'arquivo' => '<span class="chip no">Perdido</span>' . ($r['lost_at'] ? ' <span class="muted small mono">' . e(date('d/m/Y', strtotime((string)$r['lost_at']))) . '</span>' : ''),
        'ganhos'  => '<span class="chip ok">Concluído</span>' . ($r['done_at'] ? ' <span class="muted small mono">' . e(date('d/m/Y', strtotime((string)$r['done_at']))) . '</span>' : ''),
        default   => '<span class="chip' . ($tab === 'producao' ? ' ok' : '') . '">' . e((string)$r['stage_name']) . '</span>',
    };
    $ver = $r['num'] ? 'v' . (int)$r['num'] . ($r['accepted_version_id'] ? ' <span class="chip wait" data-tip="Versão aceite: conta para o Resultado e para o Pipeline" tabindex="0">aceite</span>' : '')
         . ((int)$r['nver'] > (int)$r['num'] ? ' <span class="muted small" data-tip="Há uma versão mais recente por aceitar" tabindex="0">+v' . (int)$r['nver'] . '</span>' : '') : '—';
    return '<tr id="s-' . (int)$r['sheet_id'] . '" data-href="' . e($href) . '">'
         . '<td class="id nowrap"><a href="' . e($href) . '">' . e($r['code']) . '</a></td>'
         . '<td class="nm"><a class="b" href="' . e($href) . '">' . e($r['name']) . '</a><small>' . e($who) . '</small></td>'
         . '<td class="nowrap">' . $ver . '</td>'
         . '<td class="num">' . ($hasLines ? e(budget_eur((float)$r['price'])) : '<span class="muted" data-tip="Ainda sem linhas de custo" tabindex="0">—</span>') . '</td>'
         . ($money ? '<td class="nowrap">' . ($hasLines ? budget_status_chip($statusOf((float)$r['profit_month']), (float)$r['profit_month']) : '<span class="muted">—</span>') . '</td>' : '')
         . '<td class="nowrap">' . $etapa . '</td></tr>';
};
$thead = '<thead><tr><th>ID</th><th>Negócio</th><th>Versão</th><th class="num">Preço s/ IVA</th>' . ($money ? '<th>Lucro/mês ' . budget_ii('profit_month') . '</th>' : '') . '<th>Etapa</th></tr></thead>';
?>
<div class="h1-row"><h1>Orçamentação</h1><?= cfg_link('orcamentacao') ?></div>

<div class="stats">
  <a class="stat hero" href="<?= e($url()) ?>"><div class="k">Em orçamentação</div><div class="v"><?= count($open) ?></div><div class="n"><?= $empty ? $empty . ' ainda sem linhas' : 'todas com linhas de custo' ?></div></a>
  <div class="stat"><div class="k">Valor em proposta</div><div class="v"><?= e(budget_eur($openValue)) ?></div><div class="n">s/ IVA · versões que contam</div></div>
  <?php if ($money): ?>
    <div class="stat<?= $below ? ' risk' : '' ?>"><div class="k">Abaixo da meta</div><div class="v"><?= $below ?></div><div class="n">lucro/mês &lt; <?= e(budget_eur($target)) ?></div></div>
  <?php else: ?>
    <div class="stat"><div class="k">Com versão aceite</div><div class="v"><?= count(array_filter($open, fn($r) => $r['accepted_version_id'])) ?></div><div class="n">de <?= count($open) ?> em orçamentação</div></div>
  <?php endif; ?>
  <a class="stat" href="<?= e($url(['t' => 'producao'])) ?>"><div class="k">Fechados em <?= $year ?></div><div class="v"><?= (int)$wonN ?></div><div class="n"><?= $money ? e(budget_eur((float)$wonV)) . ' s/ IVA' : 'chegaram a Kickoff' ?></div></a>
</div>

<div class="tabs-row">
  <div class="tabs">
    <?php foreach (BUDGET_TABS as $k => $label): ?>
      <a href="<?= e($url($k === 'orcamentacao' ? [] : ['t' => $k])) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($label) ?> (<?= $counts[$k] ?>)</a>
    <?php endforeach; ?>
  </div>
</div>

<form class="bo-search" method="get" role="search">
  <?php if ($tab !== 'orcamentacao'): ?><input type="hidden" name="t" value="<?= e($tab) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= $tab === 'arquivo' ? 'Procurar nos perdidos (ID, negócio, cliente)…' : 'Procurar ID, negócio ou cliente…' ?>" aria-label="Procurar">
  <button class="btn ghost sm" type="submit"><?= icon('search') ?> Procurar</button>
  <?php if ($q !== ''): ?><a class="lnk" href="<?= e($url($tab === 'orcamentacao' ? [] : ['t' => $tab])) ?>">Limpar</a><?php endif; ?>
</form>

<?php if ($tab === 'arquivo'):
    $byYear = [];
    foreach ($rows as $r) $byYear[(int)substr((string)($r['lost_at'] ?: $r['created_at']), 0, 4)][] = $r;
    krsort($byYear);
    if (!$byYear): ?>
      <div class="tablewrap"><p class="empty"><?= $q !== '' ? 'Nenhum perdido com «' . e($q) . '».' : 'Ainda não há negócios perdidos.' ?></p></div>
    <?php endif;
    foreach ($byYear as $y => $list): ?>
      <details class="panel bo-year"<?= $q !== '' ? ' open' : '' ?>>
        <summary><?= $y ?> <span class="muted"><?= count($list) ?> <?= count($list) === 1 ? 'perdido' : 'perdidos' ?></span></summary>
        <div class="bl-wrap"><table class="bo-t"><?= $thead ?><tbody><?php foreach ($list as $r) echo $row($r); ?></tbody></table></div>
      </details>
    <?php endforeach; ?>
<?php else:
    $pg = paginate($rows, 'p', 20); ?>
  <div class="tablewrap">
    <?php if (!$rows): ?>
      <p class="empty"><?= $q !== '' ? 'Nada com «' . e($q) . '».' : match ($tab) {
          'orcamentacao' => 'Nenhum negócio em orçamentação. As folhas nascem quando uma lead é convertida (X-Ray).',
          'producao' => 'Nenhum negócio em produção.',
          default => 'Ainda não há negócios concluídos.' } ?></p>
    <?php else: ?>
      <table class="bo-t"><?= $thead ?><tbody><?php foreach ($pg['rows'] as $r) echo $row($r); ?></tbody></table>
    <?php endif; ?>
  </div>
  <?= page_nav($pg) ?>
<?php endif; ?>

<details class="panel" style="margin-top:18px">
  <summary>Modelos de orçamento (<?= count($templates) ?>)</summary>
  <?php if (!$templates): ?>
    <p class="muted" style="margin:10px 0 0">Ainda não há modelos. Numa folha, «Guardar como modelo» guarda as linhas e a duração (sem preços) para começar outras folhas.</p>
  <?php else: ?>
    <div class="bl-wrap"><table class="bo-t">
      <thead><tr><th>Modelo</th><th>Linhas</th><th>Criado</th><th><span class="sr">Ações</span></th></tr></thead>
      <tbody>
      <?php foreach ($templates as $t): $data = json_decode((string)$t['data'], true) ?: []; ?>
        <tr><td><b><?= e($t['name']) ?></b><small class="muted" style="display:block"><?= e(implode(' · ', array_slice(array_map(fn($l) => (string)$l['label'], $data['lines'] ?? []), 0, 4))) ?></small></td>
          <td class="mono"><?= count($data['lines'] ?? []) ?></td>
          <td class="small muted"><?= e(date('d/m/Y', strtotime((string)$t['created_at'])) . ($t['author'] ? ' · ' . $t['author'] : '')) ?></td>
          <td class="act nowrap"><?php if (can('orcamentacao', PERM_MANAGE) || (int)$t['created_by'] === (int)$user['id']): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($tab) ?>"><input type="hidden" name="tpl_del" value="<?= (int)$t['id'] ?>">
              <?= icon_button('x', 'Apagar o modelo «' . $t['name'] . '»', ['type' => 'submit', 'class' => 'ibtn del']) ?></form>
          <?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
  <?php endif; ?>
</details>
<?php render_footer();
