<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();

$s   = settings($pdo);
$vat = (float)($s['vat_rate'] ?? 23);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do  = $_POST['do'] ?? '';
    $tab = (string)($_POST['tab'] ?? 'todos');
    $back = '/admin/projects.php?tab=' . urlencode($tab);

    if ($do === 'create') {
        // Se o valor escrito já trazia IVA lá dentro, tira-se agora:
        // na base de dados o budget fica sempre limpo.
        $gross = isset($_POST['amount_includes_vat']);
        $st = $pdo->prepare(
            "INSERT INTO projects (year, name, kind, type_label, start_month, budget_net,
                                   is_international, has_vat, expenses_mode,
                                   expenses_manual, is_paid, notes)
             VALUES (?,?,'job',?,?,?,?,?,'crew',0,?,?)"
        );
        $st->execute([
            (int)($_POST['year'] ?? date('Y')),
            trim((string)($_POST['name'] ?? '')) ?: 'Sem nome',
            trim((string)($_POST['type_label'] ?? '')),
            ((int)($_POST['start_month'] ?? 0)) ?: null,
            net_from_input($_POST['budget_net'] ?? 0, $gross, $vat),
            isset($_POST['is_international']) ? 1 : 0,
            isset($_POST['has_vat']) ? 1 : 0,
            isset($_POST['is_paid']) ? 1 : 0,
            trim((string)($_POST['notes'] ?? '')),
        ]);
        flash('Trabalho criado.');
        redirect($back);
    }

    if ($do === 'toggle_paid') {
        $pdo->prepare('UPDATE projects SET is_paid = 1 - is_paid WHERE id = ?')
            ->execute([(int)($_POST['id'] ?? 0)]);
        redirect($back);
    }

    if ($do === 'toggle_vat') {
        $pdo->prepare('UPDATE projects SET has_vat = 1 - has_vat WHERE id = ?')
            ->execute([(int)($_POST['id'] ?? 0)]);
        redirect($back);
    }

    if ($do === 'delete') {
        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('Trabalho eliminado.');
        redirect($back);
    }

    // ── custos ──────────────────────────────────────────────
    // O mesmo formulário serve os dois: se escolher um trabalho,
    // o custo entra na lista dele; se escolher «Empresa», fica na
    // estrutura, que existe mesmo sem trabalhos.
    if ($do === 'add_cost') {
        $mode = (string)($_POST['vat_mode'] ?? 'plus');
        if (!isset(VAT_MODES[$mode])) $mode = 'plus';
        $name  = trim((string)($_POST['name'] ?? '')) ?: 'Sem nome';
        $value = money_in($_POST['amount'] ?? 0);
        $jobId = (int)($_POST['project_id'] ?? 0);

        if ($jobId > 0) {
            $pdo->prepare(
                'INSERT INTO crew (project_id, person, role, fee, vat_mode, notes)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $jobId,
                trim((string)($_POST['person'] ?? '')),
                $name,
                $value,
                $mode,
                trim((string)($_POST['notes'] ?? '')),
            ]);
            flash('Custo acrescentado ao trabalho.');
        } else {
            $pdo->prepare(
                'INSERT INTO company_costs (year, month, name, amount, vat_mode, is_paid, notes)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                (int)($_POST['year'] ?? date('Y')),
                ((int)($_POST['month'] ?? 0)) ?: null,
                $name,
                $value,
                $mode,
                isset($_POST['is_paid']) ? 1 : 0,
                trim((string)($_POST['notes'] ?? '')),
            ]);
            flash('Custo da empresa acrescentado.');
        }
        redirect('/admin/projects.php?tab=custos');
    }

    if ($do === 'set_cost_vat') {
        $mode = (string)($_POST['vat_mode'] ?? 'plus');
        if (!isset(VAT_MODES[$mode])) $mode = 'plus';
        $table = ($_POST['src'] ?? '') === 'company' ? 'company_costs' : 'crew';
        $pdo->prepare("UPDATE {$table} SET vat_mode = ? WHERE id = ?")
            ->execute([$mode, (int)($_POST['id'] ?? 0)]);
        redirect('/admin/projects.php?tab=custos');
    }

    if ($do === 'toggle_cost_state') {
        if (($_POST['src'] ?? '') === 'company') {
            $pdo->prepare('UPDATE company_costs SET is_paid = 1 - is_paid WHERE id = ?')
                ->execute([(int)($_POST['id'] ?? 0)]);
        } else {
            $pdo->prepare('UPDATE crew SET is_confirmed = 1 - is_confirmed WHERE id = ?')
                ->execute([(int)($_POST['id'] ?? 0)]);
        }
        redirect('/admin/projects.php?tab=custos');
    }

    if ($do === 'delete_cost') {
        $table = ($_POST['src'] ?? '') === 'company' ? 'company_costs' : 'crew';
        $pdo->prepare("DELETE FROM {$table} WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
        flash('Custo eliminado.');
        redirect('/admin/projects.php?tab=custos');
    }
}

$tabs = ['todos' => 'Trabalhos', 'custos' => 'Custos', 'iva' => 'IVA'];
$tab  = (string)($_GET['tab'] ?? 'todos');
if (!isset($tabs[$tab])) $tab = 'todos';

// Sem filtro de ano: cada linha diz o seu mês/ano.
$rows = $pdo->query(
    "SELECT * FROM projects WHERE kind = 'job' ORDER BY year DESC, COALESCE(start_month, 13), id"
)->fetchAll();

$items = [];
$inNet = 0.0; $jobCost = 0.0; $vatOut = 0.0; $vatIn = 0.0;

foreach ($rows as $p) {
    $cost = project_expenses($pdo, $p);
    $vOut = project_vat_out($p, $vat);
    $vIn  = project_vat_in($pdo, $p, $vat);

    $inNet   += (float)$p['budget_net'];
    $jobCost += $cost;
    $vatOut  += $vOut;
    $vatIn   += $vIn;

    $items[] = [
        'p'       => $p,
        'net'     => (float)$p['budget_net'],
        'cost'    => $cost,
        'vat_out' => $vOut,
        'vat_in'  => $vIn,
        'profit'  => (float)$p['budget_net'] - $cost,
        'intl'    => (int)$p['is_international'] === 1,
        'when'    => month_year((int)$p['start_month'], (int)$p['year']),
    ];
}

// ── todas as linhas de custo, das duas origens ──────────────
$costLines = [];
foreach ($items as $i) {
    if ($i['p']['expenses_mode'] !== 'crew') continue;
    $cq = $pdo->prepare('SELECT * FROM crew WHERE project_id = ? ORDER BY sort_order, id');
    $cq->execute([(int)$i['p']['id']]);
    foreach ($cq->fetchAll() as $c) {
        $costLines[] = [
            'src'   => 'job',
            'id'    => (int)$c['id'],
            'name'  => $c['role'] ?: ($c['person'] ?: '—'),
            'who'   => $c['person'],
            'ref'   => $i['p']['name'],
            'refid' => (int)$i['p']['id'],
            'when'  => $i['when'],
            'raw'   => (float)$c['fee'],
            'mode'  => $c['vat_mode'],
            'net'   => cost_net($c, $vat),
            'vat'   => cost_vat($c, $vat),
            'on'    => (int)$c['is_confirmed'] === 1,
            'label' => 'Fechado',
        ];
    }
}

$company     = company_costs($pdo, $vat);
$companyNet  = 0.0; $companyVat = 0.0;
foreach ($company as $c) {
    $companyNet += $c['net'];
    $companyVat += $c['vat'];
    $costLines[] = [
        'src'   => 'company',
        'id'    => (int)$c['id'],
        'name'  => $c['name'],
        'who'   => '',
        'ref'   => null,
        'refid' => 0,
        'when'  => month_year($c['month'] === null ? null : (int)$c['month'], (int)$c['year']),
        'raw'   => (float)$c['amount'],
        'mode'  => $c['vat_mode'],
        'net'   => $c['net'],
        'vat'   => $c['vat'],
        'on'    => (int)$c['is_paid'] === 1,
        'label' => 'Pago',
    ];
}

$outNet = round($jobCost + $companyNet, 2);
$vatIn  = round($vatIn + $companyVat, 2);
$vatBalance = round($vatOut - $vatIn, 2);

// Linha à espera de confirmação para ser eliminada.
$pendingDelete = (int)($_GET['del'] ?? 0);
$pendingCost   = (string)($_GET['delcost'] ?? '');
$pendingItem   = null;
foreach ($items as $i) {
    if ((int)$i['p']['id'] === $pendingDelete) { $pendingItem = $i; break; }
}
if ($pendingItem === null) $pendingDelete = 0;

render_header('Carteira', 'projects');
render_flashes();

/** Uma caixa que guarda sozinha ao ser clicada. */
function tickbox(array $hidden, bool $on, string $label): string {
    ob_start(); ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <?php foreach ($hidden as $k => $v): ?>
        <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
      <?php endforeach; ?>
      <input type="checkbox" class="tick" onchange="this.form.submit()"
             <?= $on ? 'checked' : '' ?> title="<?= e($label) ?>" aria-label="<?= e($label) ?>">
    </form>
    <?php return (string)ob_get_clean();
}

/** Menu das três hipóteses de IVA, que guarda ao mudar. */
function vat_select(array $hidden, string $current): string {
    ob_start(); ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <?php foreach ($hidden as $k => $v): ?>
        <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
      <?php endforeach; ?>
      <select name="vat_mode" class="mini" onchange="this.form.submit()"
              title="O valor escrito não tem IVA, leva IVA por cima, ou já o inclui?">
        <?php foreach (VAT_MODES as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= $current === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php return (string)ob_get_clean();
}
?>

<h1>Carteira</h1>

<?php if ($pendingItem): ?>
  <div class="flash err">
    Eliminar «<b><?= e($pendingItem['p']['name']) ?></b>»?
    Apaga também todos os custos deste trabalho. Não há como desfazer —
    confirme na linha marcada abaixo.
  </div>
<?php endif; ?>

<div class="tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="/admin/projects.php?tab=<?= $key ?>" class="<?= $tab === $key ? 'on' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>


<?php /* ══════════════════ TRABALHOS ══════════════════ */ ?>
<?php if ($tab === 'todos'): ?>
<div class="tablewrap">
<table>
  <thead>
    <tr>
      <th>Registo</th>
      <th>Mês / Ano</th>
      <th class="num">Valor s/ IVA</th>
      <th class="num">IVA coletado</th>
      <th class="num">IVA pago</th>
      <th class="mid">Tem IVA</th>
      <th class="num">Lucro</th>
      <th class="mid">Pago</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$items): ?>
    <tr><td colspan="9" class="empty">Ainda não há trabalhos. Acrescente o primeiro abaixo.</td></tr>
  <?php endif; ?>
  <?php foreach ($items as $i): $p = $i['p']; $pid = (int)$p['id']; ?>
    <tr class="<?= $pendingDelete === $pid ? 'pending' : '' ?>">
      <td>
        <a href="/admin/project.php?id=<?= $pid ?>"><?= e($p['name']) ?></a>
        <?php if ($i['intl']): ?><span class="chip">Intl.</span><?php endif; ?>
        <?php if ($p['type_label']): ?>
          <div class="muted" style="font-size:0.62rem"><?= e($p['type_label']) ?></div>
        <?php endif; ?>
      </td>
      <td class="nowrap"><?= e($i['when']) ?></td>
      <td class="num"><?= eur($i['net']) ?></td>
      <td class="num <?= $i['vat_out'] > 0 ? '' : 'muted' ?>"><?= $i['vat_out'] > 0 ? eur($i['vat_out']) : '—' ?></td>
      <td class="num <?= $i['vat_in'] > 0 ? 'neg' : 'muted' ?>"><?= $i['vat_in'] > 0 ? eur($i['vat_in']) : '—' ?></td>
      <td class="mid"><?= tickbox(['do'=>'toggle_vat','id'=>$pid,'tab'=>'todos'], has_vat($p), 'Coletei IVA ao cliente') ?></td>
      <td class="num b <?= $i['profit'] < 0 ? 'neg' : 'pos' ?>"><?= eur($i['profit']) ?></td>
      <td class="mid"><?= tickbox(['do'=>'toggle_paid','id'=>$pid,'tab'=>'todos'], (int)$p['is_paid'] === 1, 'Recebido') ?></td>
      <td class="num nowrap">
        <?php if ($pendingDelete === $pid): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $pid ?>">
            <input type="hidden" name="tab" value="todos">
            <button class="btn danger sm" type="submit">Sim, eliminar</button>
          </form>
          <a class="lnk" href="/admin/projects.php?tab=todos">Cancelar</a>
        <?php else: ?>
          <a class="lnk del" href="/admin/projects.php?tab=todos&amp;del=<?= $pid ?>">Eliminar</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<details class="panel" style="margin-top:2rem">
  <summary style="cursor:pointer;font-size:0.62rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--gray)">
    Adicionar trabalho
  </summary>
  <form method="post" style="margin-top:1.2rem">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="create">
    <input type="hidden" name="tab" value="todos">
    <div class="grid">
      <div class="field">
        <label for="name">Nome</label>
        <input type="text" id="name" name="name" required placeholder="Cliente ou projeto">
      </div>
      <div class="field">
        <label for="type_label">Tipo de trabalho</label>
        <input type="text" id="type_label" name="type_label" list="types" placeholder="Edição">
        <datalist id="types">
          <option value="Edição"><option value="Animação 3D"><option value="Produção Completa">
          <option value="Fotografia"><option value="Direção Criativa">
        </datalist>
      </div>
      <div class="field">
        <label for="start_month">Mês</label>
        <select id="start_month" name="start_month">
          <option value="">—</option>
          <?php foreach (MONTHS_PT as $i => $m): ?>
            <option value="<?= $i ?>" <?= $i === (int)date('n') ? 'selected' : '' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="fyear">Ano</label>
        <input type="number" id="fyear" name="year" value="<?= (int)date('Y') ?>" min="2020" max="2100">
      </div>
      <div class="field">
        <label for="budget_net">Quanto vai faturar (€)</label>
        <input type="text" id="budget_net" name="budget_net" inputmode="decimal" placeholder="0,00">
      </div>
      <div class="field wide">
        <label for="notes">Notas</label>
        <input type="text" id="notes" name="notes">
      </div>
    </div>
    <div class="actions">
      <label class="check"><input type="checkbox" name="has_vat" checked> Coletei IVA ao cliente (<?= number_format($vat, 0) ?>%)</label>
      <label class="check"><input type="checkbox" name="amount_includes_vat"> O valor que escrevi já inclui IVA</label>
      <label class="check"><input type="checkbox" name="is_international"> Cliente estrangeiro</label>
      <label class="check"><input type="checkbox" name="is_paid"> Já recebi</label>
    </div>
    <div class="actions"><button class="btn" type="submit">Adicionar trabalho</button></div>
  </form>
</details>
<?php endif; ?>


<?php /* ══════════════════ CUSTOS ══════════════════ */ ?>
<?php if ($tab === 'custos'): ?>
<div class="tablewrap">
<table>
  <thead>
    <tr>
      <th>Custo</th>
      <th>Referente a</th>
      <th>Mês / Ano</th>
      <th class="num">Valor</th>
      <th>O valor é</th>
      <th class="num">Custo s/ IVA</th>
      <th class="num">IVA pago</th>
      <th class="num">Total</th>
      <th class="mid">Estado</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$costLines): ?>
    <tr><td colspan="10" class="empty">Ainda não há custos. Acrescente o primeiro abaixo.</td></tr>
  <?php endif; ?>
  <?php foreach ($costLines as $c): $key = $c['src'] . ':' . $c['id']; ?>
    <tr class="<?= $pendingCost === $key ? 'pending' : '' ?>">
      <td class="b"><?= e($c['name']) ?>
        <?php if ($c['who']): ?>
          <div class="muted" style="font-size:0.62rem"><?= e($c['who']) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($c['ref'] !== null): ?>
          <a href="/admin/project.php?id=<?= $c['refid'] ?>"><?= e($c['ref']) ?></a>
        <?php else: ?>
          <span class="chip exp">Empresa</span>
        <?php endif; ?>
      </td>
      <td class="nowrap"><?= e($c['when']) ?></td>
      <td class="num"><?= eur($c['raw']) ?></td>
      <td><?= vat_select(['do'=>'set_cost_vat','src'=>$c['src'],'id'=>$c['id']], $c['mode']) ?></td>
      <td class="num"><?= eur($c['net']) ?></td>
      <td class="num <?= $c['vat'] > 0 ? '' : 'muted' ?>"><?= $c['vat'] > 0 ? eur($c['vat']) : '—' ?></td>
      <td class="num b"><?= eur($c['net'] + $c['vat']) ?></td>
      <td class="mid"><?= tickbox(['do'=>'toggle_cost_state','src'=>$c['src'],'id'=>$c['id']], $c['on'], $c['label']) ?></td>
      <td class="num nowrap">
        <?php if ($pendingCost === $key): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete_cost">
            <input type="hidden" name="src" value="<?= e($c['src']) ?>">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn danger sm" type="submit">Sim, eliminar</button>
          </form>
          <a class="lnk" href="/admin/projects.php?tab=custos">Cancelar</a>
        <?php else: ?>
          <a class="lnk del" href="/admin/projects.php?tab=custos&amp;delcost=<?= e($key) ?>">Eliminar</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <?php if ($costLines): ?>
  <tfoot>
    <tr>
      <td colspan="5">Trabalhos <?= eur($jobCost) ?> · empresa <?= eur($companyNet) ?></td>
      <td class="num"><?= eur($outNet) ?></td>
      <td class="num"><?= eur($vatIn) ?></td>
      <td class="num"><?= eur($outNet + $vatIn) ?></td>
      <td colspan="2"></td>
    </tr>
  </tfoot>
  <?php endif; ?>
</table>
</div>

<details class="panel" style="margin-top:2rem">
  <summary style="cursor:pointer;font-size:0.62rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--gray)">
    Adicionar custo
  </summary>
  <form method="post" style="margin-top:1.2rem">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="add_cost">
    <div class="grid">
      <div class="field">
        <label for="cref">Referente a</label>
        <select id="cref" name="project_id">
          <option value="0">A empresa — custo de estrutura</option>
          <?php foreach ($items as $i): ?>
            <option value="<?= (int)$i['p']['id'] ?>"><?= e($i['p']['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="cname">O que é</label>
        <input type="text" id="cname" name="name" required placeholder="Contabilidade / Diretor de Fotografia">
      </div>
      <div class="field">
        <label for="cperson">Quem (só em trabalhos)</label>
        <input type="text" id="cperson" name="person" placeholder="Nome">
      </div>
      <div class="field">
        <label for="camount">Valor (€)</label>
        <input type="text" id="camount" name="amount" inputmode="decimal" placeholder="0,00" required>
      </div>
      <div class="field">
        <label for="cvat">O valor que escrevi</label>
        <select id="cvat" name="vat_mode">
          <option value="plus" selected>Não inclui IVA — acrescem <?= number_format($vat, 0) ?>%</option>
          <option value="incl">Já inclui IVA</option>
          <option value="none">Não tem IVA</option>
        </select>
      </div>
      <div class="field">
        <label for="cmonth">Mês (só na empresa)</label>
        <select id="cmonth" name="month">
          <option value="">—</option>
          <?php foreach (MONTHS_PT as $i => $m): ?>
            <option value="<?= $i ?>" <?= $i === (int)date('n') ? 'selected' : '' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="cyear">Ano (só na empresa)</label>
        <input type="number" id="cyear" name="year" value="<?= (int)date('Y') ?>" min="2020" max="2100">
      </div>
      <div class="field wide">
        <label for="cnotes">Notas</label>
        <input type="text" id="cnotes" name="notes">
      </div>
    </div>
    <div class="actions">
      <label class="check"><input type="checkbox" name="is_paid"> Já está pago</label>
    </div>
    <div class="actions">
      <button class="btn" type="submit">Adicionar custo</button>
      <span class="muted" style="font-size:0.68rem">
        Num custo de trabalho a data é a do trabalho; o mês e o ano acima só contam na empresa.
      </span>
    </div>
  </form>
</details>
<?php endif; ?>


<?php /* ══════════════════ IVA ══════════════════ */ ?>
<?php if ($tab === 'iva'): ?>
<?php
  $topo = max($vatOut, $vatIn, 0.01);   // escala das barras
  $devo = $vatBalance >= 0;             // devo ao Estado?
?>
<div class="balance">
  <div class="bar-row">
    <div class="bar-k">IVA coletado<div class="muted">cobrado aos clientes</div></div>
    <div class="bar-track"><i class="up" style="width:<?= min(100, $vatOut / $topo * 100) ?>%"></i></div>
    <div class="bar-v"><?= eur($vatOut) ?></div>
  </div>
  <div class="bar-row">
    <div class="bar-k">IVA pago<div class="muted">suportado nos custos</div></div>
    <div class="bar-track"><i class="down" style="width:<?= min(100, $vatIn / $topo * 100) ?>%"></i></div>
    <div class="bar-v"><?= eur($vatIn) ?></div>
  </div>

  <div class="verdict <?= $devo ? 'pay' : 'get' ?>">
    <div class="k"><?= $devo ? 'A pagar ao Estado' : 'A receber do Estado' ?></div>
    <div class="v"><?= eur(abs($vatBalance)) ?></div>
    <div class="n">
      <?= $devo
            ? 'Coletou mais IVA do que pagou. A diferença é do Estado e tem de ser entregue.'
            : 'Pagou mais IVA do que coletou. Fica com crédito a seu favor para o período seguinte.' ?>
    </div>
  </div>
</div>

<p class="sub" style="margin-top:1.2rem;text-transform:none;letter-spacing:0;font-size:0.72rem">
  O IVA não é receita nem despesa: é dinheiro do Estado que passa pela sua conta.
  <b>Vermelho</b> quer dizer que tem de entregar; <b>verde</b>, que tem a receber.
  Estimativa — a declaração periódica confirma-se sempre com a contabilidade.
</p>
<?php endif; ?>

<?php render_footer(); ?>
