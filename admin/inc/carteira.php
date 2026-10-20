<?php
/**
 * A Carteira (Trabalhos / Custos / IVA), mostrada no Painel.
 * dashboard.php chama carteira_handle_post() antes de escrever a página e
 * carteira_render() por baixo dos números; projects.php só trata dos POST
 * antigos e redireciona para aqui.
 * Precisa de bootstrap.php, layout.php e data.php já carregados.
 */
require_once __DIR__ . '/paginate.php';

const CARTEIRA_TABS = ['todos' => 'Trabalhos', 'custos' => 'Custos', 'iva' => 'IVA'];
/** Parâmetros do Painel que sobrevivem a qualquer ação/navegação. */
const CARTEIRA_KEEP = ['year', 'pt', 'pc', 'pi'];

/** Endereço do Painel na Carteira, com o separador e as páginas atuais. */
function carteira_url(string $tab, array $extra = [], string $anchor = 'carteira'): string {
    $q = [];
    foreach (CARTEIRA_KEEP as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $q[$k] = (string)$_GET[$k];
    }
    $q = ['tab' => isset(CARTEIRA_TABS[$tab]) ? $tab : 'todos'] + $q;
    foreach ($extra as $k => $v) $q[$k] = (string)$v;
    return '/admin/dashboard.php?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) . ($anchor !== '' ? '#' . $anchor : '');
}


/** O ano e o mês que vêm de um formulário. */
function form_year(string $key): int {
    $y = (int)($_POST[$key] ?? date('Y'));
    return ($y >= 2000 && $y <= 2100) ? $y : (int)date('Y');
}
function form_month(string $key): ?int {
    $m = (int)($_POST[$key] ?? 0);
    return $m >= 1 && $m <= 12 ? $m : null;
}
function form_vat_mode(): string {
    $mode = (string)($_POST['vat_mode'] ?? 'plus');
    return isset(VAT_MODES[$mode]) ? $mode : 'plus';
}

/** Trata um POST da Carteira (redireciona sempre; não volta). */
function carteira_handle_post(): void {
    $vat = DATA_VAT;
    csrf_check();
    $do   = $_POST['do'] ?? '';
    $tab  = (string)($_POST['tab'] ?? 'todos');
    $back = carteira_url($tab);
    $costBack = carteira_url('custos');
    $id   = (int)($_POST['id'] ?? 0);
    $company = ($_POST['src'] ?? '') === 'company';

    if ($do === 'create') {
        // Se o valor escrito já trazia IVA lá dentro, tira-se agora: o budget fica sempre limpo.
        $gross = isset($_POST['amount_includes_vat']);
        data_try(fn() => data_job_insert([
            'name'             => (string)($_POST['name'] ?? ''),
            'year'             => form_year('year'),
            'month'            => form_month('start_month'),
            'type_label'       => (string)($_POST['type_label'] ?? ''),
            'budget_net'       => net_from_input($_POST['budget_net'] ?? 0, $gross, $vat),
            'is_international' => isset($_POST['is_international']),
            'has_vat'          => isset($_POST['has_vat']),
            'expenses_manual'  => null,          // soma a equipa
            'is_paid'          => isset($_POST['is_paid']),
            'notes'            => (string)($_POST['notes'] ?? ''),
        ]), 'Trabalho criado.');
        redirect($back);
    }

    if ($do === 'toggle_paid') {
        $to = ($_POST['to'] ?? '') === '1';
        data_try(fn() => data_job_update($id, ['is_paid' => $to]), $to ? 'Marcado como recebido.' : 'Marcado como por receber.');
        redirect($back);
    }

    if ($do === 'toggle_vat') {
        $to = ($_POST['to'] ?? '') === '1';
        data_try(fn() => data_job_update($id, ['has_vat' => $to]), $to ? 'Fatura com IVA.' : 'Fatura sem IVA.');
        redirect($back);
    }

    if ($do === 'delete') {
        // Apaga também a equipa (os custos) deste trabalho.
        data_try(fn() => data_job_delete($id), 'Trabalho eliminado.');
        redirect($back);
    }

    // ── custos ──────────────────────────────────────────────
    // O mesmo formulário serve os dois: se escolher um trabalho,
    // o custo entra na equipa dele; se escolher «Empresa», vai para
    // os custos da empresa, a estrutura que existe mesmo sem trabalhos.
    if ($do === 'add_cost' || $do === 'save_cost') {
        $edit  = $do === 'save_cost';
        $jobId = (int)($_POST['job'] ?? 0);
        $src   = $edit ? ($company ? 'company' : 'job') : ($jobId > 0 ? 'job' : 'company');
        $what  = trim((string)($_POST['name'] ?? '')) ?: 'Sem nome';
        $value = money_in($_POST['amount'] ?? 0);   // tal como foi escrito
        $mode  = form_vat_mode();
        $paid  = isset($_POST['is_paid']);
        $notes = (string)($_POST['notes'] ?? '');

        if ($src === 'job') {
            $f = ['person' => (string)($_POST['person'] ?? ''), 'role' => $what, 'fee' => $value,
                  'vat_mode' => $mode, 'is_confirmed' => $paid, 'notes' => $notes];
            if ($edit && $jobId > 0) $f['job_id'] = $jobId;   // pode mudar de trabalho
            data_try(fn() => $edit ? data_crew_update($id, $f) : data_crew_insert($jobId, $f),
                     $edit ? 'Custo guardado.' : 'Custo acrescentado ao trabalho.');
        } else {
            $f = ['year' => form_year('year'), 'month' => form_month('month'), 'name' => $what, 'amount' => $value,
                  'vat_mode' => $mode, 'is_paid' => $paid, 'notes' => $notes];
            data_try(fn() => $edit ? data_cost_update($id, $f) : data_cost_insert($f),
                     $edit ? 'Custo guardado.' : 'Custo da empresa acrescentado.');
        }
        redirect($costBack);
    }

    if ($do === 'set_cost_vat') {
        $mode = form_vat_mode();
        data_try(fn() => $company ? data_cost_update($id, ['vat_mode' => $mode]) : data_crew_update($id, ['vat_mode' => $mode]),
                 'IVA do custo: ' . VAT_MODES[$mode] . '.');
        redirect($costBack);
    }

    if ($do === 'toggle_cost_state') {
        $to = ($_POST['to'] ?? '') === '1';
        data_try(fn() => $company ? data_cost_update($id, ['is_paid' => $to]) : data_crew_update($id, ['is_confirmed' => $to]),
                 $company ? ($to ? 'Custo pago.' : 'Custo por pagar.') : ($to ? 'Custo fechado.' : 'Custo em aberto.'));
        redirect($costBack);
    }

    if ($do === 'delete_cost') {
        data_try(fn() => $company ? data_cost_delete($id) : data_crew_delete($id), 'Custo eliminado.');
        redirect($costBack);
    }
    redirect($back);
}


/** Uma caixa que guarda sozinha ao ser clicada. */
function tickbox(array $hidden, bool $on, string $label): string {
    ob_start(); ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <?php foreach ($hidden + ['to' => $on ? '0' : '1'] as $k => $v): ?>
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

function job_url(array $p): string {
    return '/admin/project.php?id=' . $p['id'];
}

/** Ano*100+mês, para ordenar do mais recente para o mais antigo. */
function carteira_ym($year, $month): int {
    return ((int)$year) * 100 + ((int)$month);
}

/** A Carteira: separadores, tabelas e formulários. $d = data_all(). */
function carteira_render(array $d): void {
    $vat = DATA_VAT;
    $tabs = CARTEIRA_TABS;
    $tab = (string)($_GET['tab'] ?? 'todos');
    if (!isset($tabs[$tab])) $tab = 'todos';

// Sem filtro de ano: cada linha diz o seu mês/ano.
$items = [];
$inNet = 0.0; $jobCost = 0.0; $vatOut = 0.0; $vatIn = 0.0;
$costLines = [];
$companyNet = 0.0; $companyVat = 0.0;

if ($d) {
    foreach ($d['jobs'] as $p) {
        $inNet   += $p['budget_net'];
        $jobCost += $p['expenses'];
        $vatOut  += $p['vat_out'];
        $vatIn   += $p['vat_in'];
        $items[] = [
            'p'       => $p,
            'net'     => $p['budget_net'],
            'cost'    => $p['expenses'],
            'vat_out' => $p['vat_out'],
            'vat_in'  => $p['vat_in'],
            'profit'  => $p['profit'],
            'intl'    => $p['is_international'],
            'when'    => month_year($p['start_month'], $p['year']),
        ];
    }

    // ── todas as linhas de custo, das duas origens ──────────
    foreach ($items as $i) {
        if ($i['p']['expenses_mode'] !== 'crew') continue;
        foreach (data_job_crew($d, $i['p']['id']) as $c) {
            $costLines[] = [
                'src'   => 'job',
                'id'    => $c['id'],
                'name'  => $c['role'] ?: ($c['person'] ?: '—'),
                'who'   => $c['person'],
                'ref'   => $i['p']['name'],
                'p'     => $i['p'],
                'when'  => $i['when'],
                'ym'    => carteira_ym($i['p']['year'], $i['p']['start_month']),
                'raw'   => $c['fee'],
                'mode'  => $c['vat_mode'],
                'net'   => $c['net'],
                'vat'   => $c['vat'],
                'total' => $c['total'],
                'on'    => $c['is_confirmed'],
                'label' => 'Fechado',
                'c'     => $c,
            ];
        }
    }

    foreach ($d['company'] as $c) {
        $companyNet += $c['net'];
        $companyVat += $c['vat'];
        $costLines[] = [
            'src'   => 'company',
            'id'    => $c['id'],
            'name'  => $c['name'],
            'who'   => '',
            'ref'   => null,
            'p'     => null,
            'when'  => month_year($c['start_month'], $c['year']),
            'ym'    => carteira_ym($c['year'], $c['start_month']),
            'raw'   => $c['amount'],
            'mode'  => $c['vat_mode'],
            'net'   => $c['net'],
            'vat'   => $c['vat'],
            'total' => $c['total'],
            'on'    => $c['is_paid'],
            'label' => 'Pago',
            'c'     => $c,
        ];
    }
}

$outNet = round($jobCost + $companyNet, 2);
$vatIn  = round($vatIn + $companyVat, 2);
$vatOut = round($vatOut, 2);
$vatBalance = round($vatOut - $vatIn, 2);

// Linha à espera de confirmação para ser eliminada.
$pendingDelete = (int)($_GET['del'] ?? 0);
$pendingCost   = (string)($_GET['delcost'] ?? '');
$pendingItem   = null;
foreach ($items as $i) {
    if ($i['p']['id'] === $pendingDelete) { $pendingItem = $i; break; }
}
if ($pendingItem === null) $pendingDelete = 0;

// Custo a ser editado no formulário de baixo.
$editKey  = (string)($_GET['editcost'] ?? '');
$editLine = null;
foreach ($costLines as $c) {
    if ($c['src'] . ':' . $c['id'] === $editKey) { $editLine = $c; break; }
}

// Mais recentes primeiro (ano/mês; sem data, no fim). Os totais acima já
// somam tudo — a paginação só corta o que se vê.
usort($items, static fn($a, $b) =>
    [carteira_ym($b['p']['year'], $b['p']['start_month']), $b['p']['position'], $b['p']['id']]
    <=> [carteira_ym($a['p']['year'], $a['p']['start_month']), $a['p']['position'], $a['p']['id']]);
usort($costLines, static fn($a, $b) => [$b['ym'], $b['src'] === 'company' ? 1 : 0, $b['id']]
                                   <=> [$a['ym'], $a['src'] === 'company' ? 1 : 0, $a['id']]);

// IVA: cada trabalho e cada custo da empresa que mexe no IVA.
$vatLines = [];
foreach ($items as $i) {
    if ($i['vat_out'] == 0 && $i['vat_in'] == 0) continue;
    $vatLines[] = ['name' => $i['p']['name'], 'p' => $i['p'], 'when' => $i['when'],
                   'ym' => carteira_ym($i['p']['year'], $i['p']['start_month']),
                   'out' => $i['vat_out'], 'in' => $i['vat_in']];
}
foreach ($d['company'] as $c) {
    if ($c['vat'] == 0) continue;
    $vatLines[] = ['name' => $c['name'], 'p' => null, 'when' => month_year($c['start_month'], $c['year']),
                   'ym' => carteira_ym($c['year'], $c['start_month']), 'out' => 0.0, 'in' => $c['vat']];
}
usort($vatLines, static fn($a, $b) => $b['ym'] <=> $a['ym']);

$drop = ['del', 'delcost', 'editcost'];
// Uma linha à espera de confirmação/edição abre na página onde está.
foreach ($items as $n => $i) {
    if ($pendingDelete && $i['p']['id'] === $pendingDelete) $_GET['pt'] = intdiv($n, PAGE_SIZE) + 1;
}
foreach ($costLines as $n => $c) {
    $k = $c['src'] . ':' . $c['id'];
    if ($k === $pendingCost || $k === $editKey) $_GET['pc'] = intdiv($n, PAGE_SIZE) + 1;
}
$pgT = paginate($items, 'pt');
$pgC = paginate($costLines, 'pc');
$pgI = paginate($vatLines, 'pi');
?>
<section id="carteira" class="carteira" style="margin-top:2.6rem">
<h2>Carteira</h2>
<?php if ($pendingItem): ?>
  <div class="flash err">
    Eliminar «<b><?= e($pendingItem['p']['name']) ?></b>»?
    Apaga também todos os custos deste trabalho (a equipa dele). Não há como desfazer —
    confirme na linha marcada abaixo.
  </div>
<?php endif; ?>

<div class="tabs">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="<?= e(carteira_url($key)) ?>" class="<?= $tab === $key ? 'on' : '' ?>"><?= $label ?></a>
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
  <?php foreach ($pgT['rows'] as $i): $p = $i['p']; $pid = $p['id'];
        $keys = ['id' => $pid, 'tab' => 'todos']; ?>
    <tr class="<?= $pendingDelete === $pid ? 'pending' : '' ?>">
      <td>
        <a href="<?= e(job_url($p)) ?>"><?= e($p['name'] !== '' ? $p['name'] : '(sem nome)') ?></a>
        <?php if ($i['intl']): ?><span class="chip">Intl.</span><?php endif; ?>
        <?php if ($p['type_label']): ?>
          <div class="muted" style="font-size:0.62rem"><?= e($p['type_label']) ?></div>
        <?php endif; ?>
      </td>
      <td class="nowrap"><?= e($i['when']) ?></td>
      <td class="num"><?= eur($i['net']) ?></td>
      <td class="num <?= $i['vat_out'] > 0 ? '' : 'muted' ?>"><?= $i['vat_out'] > 0 ? eur($i['vat_out']) : '—' ?></td>
      <td class="num <?= $i['vat_in'] > 0 ? 'neg' : 'muted' ?>"><?= $i['vat_in'] > 0 ? eur($i['vat_in']) : '—' ?></td>
      <td class="mid"><?= tickbox(['do' => 'toggle_vat'] + $keys, has_vat($p), 'Coletei IVA ao cliente') ?></td>
      <td class="num b <?= $i['profit'] < 0 ? 'neg' : 'pos' ?>"><?= eur($i['profit']) ?></td>
      <td class="mid"><?= tickbox(['do' => 'toggle_paid'] + $keys, $p['is_paid'], 'Recebido') ?></td>
      <td class="num nowrap">
        <?php if ($pendingDelete === $pid): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $pid ?>">
            <input type="hidden" name="tab" value="todos">
            <button class="btn danger sm" type="submit">Sim, eliminar</button>
          </form>
          <a class="lnk" href="<?= e(carteira_url('todos')) ?>">Cancelar</a>
        <?php else: ?>
          <a class="lnk del" href="<?= e(carteira_url('todos', ['del' => $pid])) ?>">Eliminar</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= page_nav($pgT, $drop, 'carteira') ?>

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
        <select id="type_label" name="type_label">
          <?= data_options(JOB_TYPES, '', '—') ?>
        </select>
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
  <?php foreach ($pgC['rows'] as $c): $key = $c['src'] . ':' . $c['id'];
        $hid = ['src' => $c['src'], 'id' => $c['id']]; ?>
    <tr class="<?= $pendingCost === $key || $editKey === $key ? 'pending' : '' ?>">
      <td class="b"><?= e($c['name']) ?>
        <?php if ($c['who']): ?>
          <div class="muted" style="font-size:0.62rem"><?= e($c['who']) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($c['ref'] === null): ?>
          <span class="chip exp">Empresa</span>
        <?php else: ?>
          <a href="<?= e(job_url($c['p'])) ?>"><?= e($c['ref']) ?></a>
        <?php endif; ?>
      </td>
      <td class="nowrap"><?= e($c['when']) ?></td>
      <td class="num"><?= eur($c['raw']) ?></td>
      <td><?= vat_select(['do' => 'set_cost_vat'] + $hid, $c['mode']) ?></td>
      <td class="num"><?= eur($c['net']) ?></td>
      <td class="num <?= $c['vat'] > 0 ? '' : 'muted' ?>"><?= $c['vat'] > 0 ? eur($c['vat']) : '—' ?></td>
      <td class="num b"><?= eur($c['total']) ?></td>
      <td class="mid"><?= tickbox(['do' => 'toggle_cost_state'] + $hid, $c['on'], $c['label']) ?></td>
      <td class="num nowrap">
        <?php if ($pendingCost === $key): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete_cost">
            <?php foreach ($hid as $k => $v): ?>
              <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
            <?php endforeach; ?>
            <button class="btn danger sm" type="submit">Sim, eliminar</button>
          </form>
          <a class="lnk" href="<?= e(carteira_url('custos')) ?>">Cancelar</a>
        <?php else: ?>
          <a class="lnk" href="<?= e(carteira_url('custos', ['editcost' => $key], 'custo')) ?>">Editar</a>
          <a class="lnk del" href="<?= e(carteira_url('custos', ['delcost' => $key])) ?>">Eliminar</a>
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
<?= page_nav($pgC, $drop, 'carteira') ?>

<?php
  // O formulário serve para acrescentar e para editar.
  $ec  = $editLine['c'] ?? null;
  $eSrc = $editLine['src'] ?? '';
  $fMode  = $ec['vat_mode'] ?? 'plus';
  $fMonth = $eSrc === 'company' ? ($ec['start_month'] ?? 0) : (int)date('n');
  $fYear  = $eSrc === 'company' ? $ec['year'] : (int)date('Y');
?>
<details class="panel" id="custo" style="margin-top:2rem" <?= $editLine ? 'open' : '' ?>>
  <summary style="cursor:pointer;font-size:0.62rem;letter-spacing:0.2em;text-transform:uppercase;color:var(--gray)">
    <?= $editLine ? 'Editar custo' : 'Adicionar custo' ?>
  </summary>
  <form method="post" style="margin-top:1.2rem">
    <?= csrf_field() ?>
    <?php if ($editLine): ?>
      <input type="hidden" name="do" value="save_cost">
      <input type="hidden" name="src" value="<?= e($eSrc) ?>">
      <input type="hidden" name="id" value="<?= (int)$editLine['id'] ?>">
    <?php else: ?>
      <input type="hidden" name="do" value="add_cost">
    <?php endif; ?>
    <div class="grid">
      <?php if ($eSrc !== 'company'): ?>
      <div class="field">
        <label for="cref">Referente a</label>
        <select id="cref" name="job">
          <?php if (!$editLine): ?><option value="">A empresa — custo de estrutura</option><?php endif; ?>
          <?php foreach ($items as $i): ?>
            <option value="<?= (int)$i['p']['id'] ?>" <?= $ec && ($ec['job_id'] ?? 0) === $i['p']['id'] ? 'selected' : '' ?>><?= e($i['p']['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="field">
        <label for="cname">O que é</label>
        <input type="text" id="cname" name="name" required placeholder="Contabilidade / Diretor de Fotografia"
               value="<?= e($ec ? ($eSrc === 'company' ? $ec['name'] : $ec['role']) : '') ?>">
      </div>
      <?php if ($eSrc !== 'company'): ?>
      <div class="field">
        <label for="cperson">Quem (só em trabalhos)</label>
        <input type="text" id="cperson" name="person" placeholder="Nome" value="<?= e($ec['person'] ?? '') ?>">
      </div>
      <?php endif; ?>
      <div class="field">
        <label for="camount">Valor (€)</label>
        <input type="text" id="camount" name="amount" inputmode="decimal" placeholder="0,00" required
               value="<?= $ec ? number_format($eSrc === 'company' ? $ec['amount'] : $ec['fee'], 2, ',', '') : '' ?>">
      </div>
      <div class="field">
        <label for="cvat">O valor que escrevi</label>
        <select id="cvat" name="vat_mode">
          <option value="plus" <?= $fMode === 'plus' ? 'selected' : '' ?>>Não inclui IVA — acrescem <?= number_format($vat, 0) ?>%</option>
          <option value="incl" <?= $fMode === 'incl' ? 'selected' : '' ?>>Já inclui IVA</option>
          <option value="none" <?= $fMode === 'none' ? 'selected' : '' ?>>Não tem IVA</option>
        </select>
      </div>
      <?php if ($eSrc !== 'job'): ?>
      <div class="field">
        <label for="cmonth">Mês<?= $editLine ? '' : ' (só na empresa)' ?></label>
        <select id="cmonth" name="month">
          <option value="">—</option>
          <?php foreach (MONTHS_PT as $i => $m): ?>
            <option value="<?= $i ?>" <?= $i === $fMonth ? 'selected' : '' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="cyear">Ano<?= $editLine ? '' : ' (só na empresa)' ?></label>
        <input type="number" id="cyear" name="year" value="<?= (int)$fYear ?>" min="2020" max="2100">
      </div>
      <?php endif; ?>
      <div class="field wide">
        <label for="cnotes">Notas</label>
        <input type="text" id="cnotes" name="notes" value="<?= e($ec['notes'] ?? '') ?>">
      </div>
    </div>
    <div class="actions">
      <?php if ($eSrc === 'company'): ?>
        <label class="check"><input type="checkbox" name="is_paid" <?= $ec['is_paid'] ? 'checked' : '' ?>> Já está pago</label>
      <?php elseif ($eSrc === 'job'): ?>
        <label class="check"><input type="checkbox" name="is_paid" <?= $ec['is_confirmed'] ? 'checked' : '' ?>> Fechado</label>
      <?php else: ?>
        <label class="check"><input type="checkbox" name="is_paid"> Já está pago (na empresa) / fechado (num trabalho)</label>
      <?php endif; ?>
    </div>
    <div class="actions">
      <button class="btn" type="submit"><?= $editLine ? 'Guardar custo' : 'Adicionar custo' ?></button>
      <?php if ($editLine): ?>
        <a class="btn ghost" href="<?= e(carteira_url('custos')) ?>">Cancelar</a>
      <?php else: ?>
      <span class="muted" style="font-size:0.68rem">
        Num custo de trabalho a data é a do trabalho; o mês e o ano acima só contam na empresa.
      </span>
      <?php endif; ?>
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

<div class="tablewrap" style="margin-top:1.6rem">
<table>
  <thead>
    <tr><th>Registo</th><th>Mês / Ano</th><th class="num">IVA coletado</th><th class="num">IVA pago</th></tr>
  </thead>
  <tbody>
  <?php if (!$vatLines): ?>
    <tr><td colspan="4" class="empty">Ainda não há movimentos de IVA.</td></tr>
  <?php endif; ?>
  <?php foreach ($pgI['rows'] as $v): ?>
    <tr>
      <td><?php if ($v['p']): ?><a href="<?= e(job_url($v['p'])) ?>"><?= e($v['name'] !== '' ? $v['name'] : '(sem nome)') ?></a>
          <?php else: ?><?= e($v['name']) ?> <span class="chip exp">Empresa</span><?php endif; ?></td>
      <td class="nowrap"><?= e($v['when']) ?></td>
      <td class="num <?= $v['out'] > 0 ? '' : 'muted' ?>"><?= $v['out'] > 0 ? eur($v['out']) : '—' ?></td>
      <td class="num <?= $v['in'] > 0 ? 'neg' : 'muted' ?>"><?= $v['in'] > 0 ? eur($v['in']) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <?php if ($vatLines): ?>
  <tfoot>
    <tr><td colspan="2">Total</td><td class="num"><?= eur($vatOut) ?></td><td class="num"><?= eur($vatIn) ?></td></tr>
  </tfoot>
  <?php endif; ?>
</table>
</div>
<?= page_nav($pgI, $drop, 'carteira') ?>

<p class="sub" style="margin-top:1.2rem;text-transform:none;letter-spacing:0;font-size:0.72rem">
  O IVA não é receita nem despesa: é dinheiro do Estado que passa pela sua conta.
  <b>Vermelho</b> quer dizer que tem de entregar; <b>verde</b>, que tem a receber.
  Estimativa — a declaração periódica confirma-se sempre com a contabilidade.
</p>
<?php endif; ?>
</section>
<?php
}
