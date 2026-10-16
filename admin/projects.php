<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/sheet.php';
$user = require_login();

// Tudo o que esta página mostra e grava vive na folha Google:
// separadores Trabalhos, Equipa e Custos empresa. A taxa é a das fórmulas.
$vat = SHEET_VAT;

/** O ano e o mês que vêm de um formulário. */
function form_year(string $key): int {
    $y = (int)($_POST[$key] ?? date('Y'));
    return ($y >= 2000 && $y <= 2100) ? $y : (int)date('Y');
}
function form_month(string $key): string {
    return sheet_month_out(((int)($_POST[$key] ?? 0)) ?: null);
}
function form_vat_mode(): string {
    $mode = (string)($_POST['vat_mode'] ?? 'plus');
    return isset(VAT_MODES[$mode]) ? $mode : 'plus';
}

/**
 * A chave com que a folha confirma que a linha ainda é a mesma:
 * Equipa → Trabalho + Função; Custos empresa → Nome.
 */
function cost_match(): array {
    return ($_POST['src'] ?? '') === 'company'
        ? ['Nome' => (string)($_POST['k_name'] ?? '')]
        : ['Trabalho' => (string)($_POST['k_job'] ?? ''), 'Função' => (string)($_POST['k_role'] ?? '')];
}
function cost_tab(): string {
    return ($_POST['src'] ?? '') === 'company' ? 'Custos empresa' : 'Equipa';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do   = $_POST['do'] ?? '';
    $tab  = (string)($_POST['tab'] ?? 'todos');
    $back = '/admin/projects.php?tab=' . urlencode($tab);
    $row  = (int)($_POST['row'] ?? 0);
    $name = (string)($_POST['name_key'] ?? '');

    if ($do === 'create') {
        $new = trim((string)($_POST['name'] ?? '')) ?: 'Sem nome';
        sheet_try(function () use ($new, $vat) {
            // O nome é a chave que liga o trabalho às linhas da Equipa.
            if (sheet_job_by_name(sheet_data(true), $new)) {
                throw new SheetException('Já existe um trabalho chamado «' . $new . '». Escolha outro nome.');
            }
            // Se o valor escrito já trazia IVA lá dentro, tira-se agora:
            // na folha o budget fica sempre limpo.
            $gross = isset($_POST['amount_includes_vat']);
            sheet_append('Trabalhos', [
                'Ano'             => form_year('year'),
                'Mês'             => form_month('start_month'),
                'Nome'            => sheet_text($new),
                'Tipo'            => sheet_text((string)($_POST['type_label'] ?? '')),
                'Budget (s/ IVA)' => net_from_input($_POST['budget_net'] ?? 0, $gross, $vat),
                'Internacional'   => isset($_POST['is_international']),
                'Fatura com IVA'  => isset($_POST['has_vat']),
                'Gastos manuais'  => '',          // soma a Equipa
                'Pago'            => isset($_POST['is_paid']),
                'Notas'           => sheet_text((string)($_POST['notes'] ?? '')),
            ]);
        }, 'Trabalho criado.');
        redirect($back);
    }

    if ($do === 'toggle_paid') {
        $to = ($_POST['to'] ?? '') === '1';
        sheet_try(fn() => sheet_update('Trabalhos', $row, ['Nome' => $name], ['Pago' => $to]),
                  $to ? 'Marcado como recebido.' : 'Marcado como por receber.');
        redirect($back);
    }

    if ($do === 'toggle_vat') {
        $to = ($_POST['to'] ?? '') === '1';
        sheet_try(fn() => sheet_update('Trabalhos', $row, ['Nome' => $name], ['Fatura com IVA' => $to]),
                  $to ? 'Fatura com IVA.' : 'Fatura sem IVA.');
        redirect($back);
    }

    if ($do === 'delete') {
        // A folha apaga também as linhas da Equipa deste trabalho.
        sheet_try(fn() => sheet_delete('Trabalhos', $row, ['Nome' => $name]), 'Trabalho eliminado.');
        redirect($back);
    }

    // ── custos ──────────────────────────────────────────────
    // O mesmo formulário serve os dois: se escolher um trabalho,
    // o custo entra na Equipa dele; se escolher «Empresa», vai para
    // Custos empresa, a estrutura que existe mesmo sem trabalhos.
    if ($do === 'add_cost' || $do === 'save_cost') {
        $mode  = form_vat_mode();
        $what  = trim((string)($_POST['name'] ?? '')) ?: 'Sem nome';
        $value = money_in($_POST['amount'] ?? 0);
        $job   = trim((string)($_POST['job'] ?? ''));
        $paid  = isset($_POST['is_paid']);
        $edit  = $do === 'save_cost';
        $src   = $edit ? (($_POST['src'] ?? '') === 'company' ? 'company' : 'job') : ($job !== '' ? 'job' : 'company');

        if ($src === 'job') {
            sheet_try(function () use ($job, $what, $value, $mode, $paid, $edit, $row) {
                if (!sheet_job_by_name(sheet_data(true), $job)) {
                    throw new SheetException('O trabalho «' . $job . '» já não existe na folha. Recarregue a página.');
                }
                $values = [
                    'Trabalho'    => sheet_text($job),
                    'Pessoa'      => sheet_text((string)($_POST['person'] ?? '')),
                    'Função'      => sheet_text($what),
                    'Valor'       => $value,           // tal como foi escrito
                    'Tipo de IVA' => sheet_vat_label($mode),
                    'Fechado'     => $paid,
                    'Notas'       => sheet_text((string)($_POST['notes'] ?? '')),
                ];
                if ($edit) sheet_update('Equipa', $row, cost_match(), $values);
                else       sheet_append('Equipa', $values + ['Contacto' => '']);
            }, $edit ? 'Custo guardado.' : 'Custo acrescentado ao trabalho.');
        } else {
            $values = [
                'Ano'         => form_year('year'),
                'Mês'         => form_month('month'),
                'Nome'        => sheet_text($what),
                'Valor'       => $value,
                'Tipo de IVA' => sheet_vat_label($mode),
                'Pago'        => $paid,
                'Notas'       => sheet_text((string)($_POST['notes'] ?? '')),
            ];
            sheet_try(fn() => $edit ? sheet_update('Custos empresa', $row, cost_match(), $values)
                                    : sheet_append('Custos empresa', $values),
                      $edit ? 'Custo guardado.' : 'Custo da empresa acrescentado.');
        }
        redirect('/admin/projects.php?tab=custos');
    }

    if ($do === 'set_cost_vat') {
        $mode = form_vat_mode();
        sheet_try(fn() => sheet_update(cost_tab(), $row, cost_match(), ['Tipo de IVA' => sheet_vat_label($mode)]),
                  'IVA do custo: ' . VAT_MODES[$mode] . '.');
        redirect('/admin/projects.php?tab=custos');
    }

    if ($do === 'toggle_cost_state') {
        $to  = ($_POST['to'] ?? '') === '1';
        $col = ($_POST['src'] ?? '') === 'company' ? 'Pago' : 'Fechado';
        sheet_try(fn() => sheet_update(cost_tab(), $row, cost_match(), [$col => $to]),
                  $col === 'Pago' ? ($to ? 'Custo pago.' : 'Custo por pagar.')
                                  : ($to ? 'Custo fechado.' : 'Custo em aberto.'));
        redirect('/admin/projects.php?tab=custos');
    }

    if ($do === 'delete_cost') {
        sheet_try(fn() => sheet_delete(cost_tab(), $row, cost_match()), 'Custo eliminado.');
        redirect('/admin/projects.php?tab=custos');
    }

    redirect($back);
}

$tabs = ['todos' => 'Trabalhos', 'custos' => 'Custos', 'iva' => 'IVA'];
$tab  = (string)($_GET['tab'] ?? 'todos');
if (!isset($tabs[$tab])) $tab = 'todos';

$d = null; $sheetErr = null;
try {
    $d = sheet_data();
} catch (SheetException $e) {
    $sheetErr = $e;
}

// Sem filtro de ano: cada linha diz o seu mês/ano.
$items = [];
$inNet = 0.0; $jobCost = 0.0; $vatOut = 0.0; $vatIn = 0.0;
$costLines = [];
$companyNet = 0.0; $companyVat = 0.0;

if ($d) {
    foreach ($d['jobs'] as $p) {
        $inNet   += $p['budget_net'];
        $jobCost += $p['expenses'];     // [c] Gastos
        $vatOut  += $p['vat_out'];
        $vatIn   += $p['vat_in'];
        $items[] = [
            'p'       => $p,
            'net'     => $p['budget_net'],
            'cost'    => $p['expenses'],
            'vat_out' => $p['vat_out'],
            'vat_in'  => $p['vat_in'],
            'profit'  => $p['profit'],  // [c] Lucro
            'intl'    => $p['is_international'],
            'when'    => month_year($p['start_month'], $p['year']),
        ];
    }

    // ── todas as linhas de custo, das duas origens ──────────
    foreach ($items as $i) {
        if ($i['p']['expenses_mode'] !== 'crew') continue;
        foreach (sheet_job_crew($d, $i['p']['name']) as $c) {
            $costLines[] = [
                'src'   => 'job',
                'id'    => $c['row'],
                'name'  => $c['role'] ?: ($c['person'] ?: '—'),
                'who'   => $c['person'],
                'ref'   => $i['p']['name'],
                'refrow'=> $i['p']['row'],
                'when'  => $i['when'],
                'raw'   => $c['fee'],
                'mode'  => $c['vat_mode'],
                'net'   => $c['net'],
                'vat'   => $c['vat'],
                'total' => $c['total'],
                'on'    => $c['is_confirmed'],
                'label' => 'Fechado',
                'keys'  => ['k_job' => $c['job'], 'k_role' => $c['role']],
                'c'     => $c,
            ];
        }
    }
    // Linhas da Equipa cujo trabalho não existe (nome mudado à mão na folha):
    // aparecem, para não ficarem esquecidas, mas não contam em total nenhum.
    foreach ($d['crew'] as $c) {
        if ($c['job_row'] !== null) continue;
        $costLines[] = [
            'src' => 'job', 'id' => $c['row'], 'name' => $c['role'] ?: ($c['person'] ?: '—'),
            'who' => $c['person'], 'ref' => $c['job'], 'refrow' => 0, 'when' => '—',
            'raw' => $c['fee'], 'mode' => $c['vat_mode'], 'net' => $c['net'], 'vat' => $c['vat'],
            'total' => $c['total'], 'on' => $c['is_confirmed'], 'label' => 'Fechado',
            'keys' => ['k_job' => $c['job'], 'k_role' => $c['role']], 'c' => $c,
        ];
    }

    foreach ($d['company'] as $c) {
        $companyNet += $c['net'];
        $companyVat += $c['vat'];
        $costLines[] = [
            'src'   => 'company',
            'id'    => $c['row'],
            'name'  => $c['name'],
            'who'   => '',
            'ref'   => null,
            'refrow'=> 0,
            'when'  => month_year($c['start_month'], $c['year']),
            'raw'   => $c['amount'],
            'mode'  => $c['vat_mode'],
            'net'   => $c['net'],
            'vat'   => $c['vat'],
            'total' => $c['total'],
            'on'    => $c['is_paid'],
            'label' => 'Pago',
            'keys'  => ['k_name' => $c['name']],
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
    if ($i['p']['row'] === $pendingDelete) { $pendingItem = $i; break; }
}
if ($pendingItem === null) $pendingDelete = 0;

// Custo a ser editado no formulário de baixo.
$editKey  = (string)($_GET['editcost'] ?? '');
$editLine = null;
foreach ($costLines as $c) {
    if ($c['src'] . ':' . $c['id'] === $editKey) { $editLine = $c; break; }
}

render_header('Carteira', 'projects');
render_flashes();
echo sheet_status_line($d);

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
    return '/admin/project.php?row=' . $p['row'] . '&n=' . rawurlencode($p['name']);
}
?>

<h1>Carteira</h1>

<?php if ($sheetErr): ?>
  <?= sheet_error_box($sheetErr) ?>
<?php render_footer(); exit; endif; ?>

<?php if ($pendingItem): ?>
  <div class="flash err">
    Eliminar «<b><?= e($pendingItem['p']['name']) ?></b>»?
    Apaga também todos os custos deste trabalho (as linhas dele na Equipa). Não há como desfazer —
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
  <?php foreach ($items as $i): $p = $i['p']; $pid = $p['row'];
        $keys = ['row' => $pid, 'name_key' => $p['name'], 'tab' => 'todos']; ?>
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
            <input type="hidden" name="row" value="<?= $pid ?>">
            <input type="hidden" name="name_key" value="<?= e($p['name']) ?>">
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
        <select id="type_label" name="type_label">
          <?= sheet_options(JOB_TYPES, '', '—') ?>
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
  <?php foreach ($costLines as $c): $key = $c['src'] . ':' . $c['id'];
        $hid = ['src' => $c['src'], 'row' => $c['id']] + $c['keys']; ?>
    <tr class="<?= $pendingCost === $key || $editKey === $key ? 'pending' : '' ?>">
      <td class="b"><?= e($c['name']) ?>
        <?php if ($c['who']): ?>
          <div class="muted" style="font-size:0.62rem"><?= e($c['who']) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($c['ref'] === null): ?>
          <span class="chip exp">Empresa</span>
        <?php elseif ($c['refrow'] > 0): ?>
          <a href="<?= e('/admin/project.php?row=' . $c['refrow'] . '&n=' . rawurlencode($c['ref'])) ?>"><?= e($c['ref']) ?></a>
        <?php else: ?>
          <?= e($c['ref'] ?: '—') ?>
          <div class="muted" style="font-size:0.62rem">trabalho não encontrado — não conta</div>
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
          <a class="lnk" href="/admin/projects.php?tab=custos">Cancelar</a>
        <?php else: ?>
          <a class="lnk" href="/admin/projects.php?tab=custos&amp;editcost=<?= e($key) ?>#custo">Editar</a>
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
      <input type="hidden" name="row" value="<?= (int)$editLine['id'] ?>">
      <?php foreach ($editLine['keys'] as $k => $v): ?>
        <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
      <?php endforeach; ?>
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
            <option value="<?= e($i['p']['name']) ?>" <?= $ec && $ec['job'] === $i['p']['name'] ? 'selected' : '' ?>><?= e($i['p']['name']) ?></option>
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
        <a class="btn ghost" href="/admin/projects.php?tab=custos">Cancelar</a>
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

<p class="sub" style="margin-top:1.2rem;text-transform:none;letter-spacing:0;font-size:0.72rem">
  O IVA não é receita nem despesa: é dinheiro do Estado que passa pela sua conta.
  <b>Vermelho</b> quer dizer que tem de entregar; <b>verde</b>, que tem a receber.
  Estimativa — a declaração periódica confirma-se sempre com a contabilidade.
</p>
<?php endif; ?>

<?php render_footer(); ?>
