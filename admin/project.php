<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/sheet.php';
require __DIR__ . '/inc/drive.php';
$user = require_login();

// Um trabalho é uma linha do separador Trabalhos: project.php?row=5.
// O «n» (nome) que vai junto no endereço serve para o reencontrar se a
// folha tiver mudado de ordem entretanto; project.php?n=Nome também serve.
$row  = (int)($_GET['row'] ?? $_POST['row'] ?? 0);
$want = trim((string)($_GET['n'] ?? ''));
$vat  = SHEET_VAT;

function job_page(int $row, string $name, string $extra = ''): string {
    return '/admin/project.php?row=' . $row . '&n=' . rawurlencode($name) . $extra;
}

/** Página de erro com o aspeto do resto do admin. */
function job_fail(string $html, ?array $d): void {
    render_header('Carteira', 'projects');
    render_flashes();
    echo sheet_status_line($d);
    echo '<h1>Carteira</h1><div class="sub"><a href="/admin/dashboard.php#carteira">← Painel</a></div>';
    echo $html;
    render_footer();
    exit;
}

$d = null;
try {
    $d = sheet_data();
} catch (SheetException $e) {
    job_fail(sheet_error_box($e), null);
}

$p = $row > 0 ? sheet_job_by_row($d, $row) : null;
if ($want !== '' && (!$p || $p['name'] !== $want)) {
    // A linha mudou (ou só veio o nome): procura-se pelo nome.
    $byName = sheet_job_by_name($d, $want);
    if ($byName && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect(job_page($byName['row'], $byName['name']));
    }
    if (!$byName) $p = null;
}
if (!$p) {
    http_response_code(404);
    job_fail('<div class="flash err">Este trabalho não está na folha (pode ter sido apagado ou mudado de nome). '
           . '<a href="/admin/dashboard.php#carteira">Voltar ao Painel</a></div>', $d);
}
$id   = $p['row'];
$here = job_page($id, $p['name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    // A página foi aberta com um trabalho; se a linha entretanto tem
    // outro, não se escreve nada.
    if ((string)($_POST['name_key'] ?? '') !== $p['name']) {
        flash('A folha mudou entretanto. Recarregue a página e tente outra vez.', 'err');
        redirect($here);
    }
    $crewKey = ['Trabalho' => $p['name'], 'Função' => (string)($_POST['k_role'] ?? '')];
    $crewRow = (int)($_POST['crew_row'] ?? 0);

    // A pasta do trabalho no Drive (R2): criada (se faltar) só quando se carrega no botão.
    if ($do === 'drive_folder') {
        $year = $p['year'] ?: (int)date('Y');
        $name = $p['name'] !== '' ? $p['name'] : 'Sem nome';
        if (!r2_config()) redirect('/admin/drive.php');   // mostra os passos de configuração
        try {
            redirect(drive_page(drive_project($name, $year)));
        } catch (R2Exception $e) {
            flash('Não foi possível abrir a pasta no Drive. ' . $e->getMessage(), 'err');
            redirect($here);
        }
    }

    if ($do === 'save_project') {
        $name  = trim((string)($_POST['name'] ?? '')) ?: 'Sem nome';
        $mode  = ($_POST['expenses_mode'] ?? 'crew') === 'manual' ? 'manual' : 'crew';
        $gross = isset($_POST['amount_includes_vat']);
        $y     = (int)($_POST['year'] ?? $p['year']);
        $ok = sheet_try(function () use ($p, $name, $mode, $gross, $vat, $y) {
            $other = sheet_job_by_name(sheet_data(), $name);
            if ($other && $other['row'] !== $p['row']) {
                throw new SheetException('Já existe um trabalho chamado «' . $name . '». Escolha outro nome.');
            }
            // Mudar o nome leva a equipa com ele (a folha trata disso).
            sheet_update('Trabalhos', $p['row'], ['Nome' => $p['name']], [
                'Nome'            => sheet_text($name),
                'Tipo'            => sheet_text((string)($_POST['type_label'] ?? '')),
                'Mês'             => sheet_month_out(((int)($_POST['start_month'] ?? 0)) ?: null),
                'Ano'             => ($y >= 2000 && $y <= 2100) ? $y : $p['year'],
                'Budget (s/ IVA)' => net_from_input($_POST['budget_net'] ?? 0, $gross, $vat),
                'Internacional'   => isset($_POST['is_international']),
                'Fatura com IVA'  => isset($_POST['has_vat']),
                // Vazio = os custos são a soma da Equipa.
                'Gastos manuais'  => $mode === 'manual' ? net_from_input($_POST['expenses_manual'] ?? 0, $gross, $vat) : '',
                'Pago'            => isset($_POST['is_paid']),
                'Notas'           => sheet_text((string)($_POST['notes'] ?? '')),
            ]);
        }, 'Alterações guardadas.');
        redirect($ok ? job_page($id, $name) : $here);
    }

    if ($do === 'add_crew' || $do === 'save_crew') {
        $mode = (string)($_POST['vat_mode'] ?? 'plus');
        if (!isset(VAT_MODES[$mode])) $mode = 'plus';
        $values = [
            'Trabalho'    => sheet_text($p['name']),
            'Pessoa'      => sheet_text((string)($_POST['person'] ?? '')),
            'Função'      => sheet_text((string)($_POST['role'] ?? '')),
            'Valor'       => money_in($_POST['fee'] ?? 0),   // guarda-se tal como foi escrito
            'Tipo de IVA' => sheet_vat_label($mode),
            'Fechado'     => isset($_POST['is_confirmed']),
            'Contacto'    => sheet_text((string)($_POST['contact'] ?? '')),
            'Notas'       => sheet_text((string)($_POST['notes'] ?? '')),
        ];
        if ($do === 'add_crew') {
            sheet_try(fn() => sheet_append('Equipa', $values), 'Custo adicionado.');
        } else {
            sheet_try(fn() => sheet_update('Equipa', $crewRow, $crewKey, $values), 'Custo guardado.');
        }
        redirect($here);
    }

    if ($do === 'toggle_crew') {
        $to = ($_POST['to'] ?? '') === '1';
        sheet_try(fn() => sheet_update('Equipa', $crewRow, $crewKey, ['Fechado' => $to]),
                  $to ? 'Custo fechado.' : 'Custo em aberto.');
        redirect($here);
    }

    // O que é o valor desta linha: sem IVA, mais IVA, ou já com IVA
    // lá dentro. É daqui que sai o IVA gasto do trabalho.
    if ($do === 'set_crew_vat') {
        $mode = (string)($_POST['vat_mode'] ?? 'plus');
        if (!isset(VAT_MODES[$mode])) $mode = 'plus';
        sheet_try(fn() => sheet_update('Equipa', $crewRow, $crewKey, ['Tipo de IVA' => sheet_vat_label($mode)]),
                  'IVA do custo: ' . VAT_MODES[$mode] . '.');
        redirect($here);
    }

    if ($do === 'del_crew') {
        sheet_try(fn() => sheet_delete('Equipa', $crewRow, $crewKey), 'Removido da equipa.');
        redirect($here);
    }

    redirect($here);
}

$crew = sheet_job_crew($d, $p['name']);

// Pessoa à espera de confirmação para ser removida. Em dois passos,
// para não depender dos diálogos do browser, que podem estar bloqueados.
$pendingCrew = (int)($_GET['delcrew'] ?? 0);
$editCrew    = (int)($_GET['editcrew'] ?? 0);
$ec = $editCrew ? sheet_crew_by_row($d, $editCrew) : null;
if ($ec && $ec['job'] !== $p['name']) $ec = null;

// Os números são as colunas calculadas da folha.
$crewTotal     = $p['crew_net'];        // o que pesa no lucro
$crewVat       = $p['crew_vat'];        // o que se deduz
$crewConfirmed = $p['crew_confirmed'];

$net     = $p['budget_net'];
$exp     = $p['expenses'];
$vatOut  = $p['vat_out'];
$vatIn   = $p['vat_in'];
$profit  = $p['profit'];
$margin  = $net > 0 ? $profit / $net * 100 : 0;
$usedPct = $net > 0 ? min(100, $exp / $net * 100) : 0;

/** Os campos escondidos que identificam uma linha da Equipa. */
function crew_hidden(array $c, string $job): string {
    return '<input type="hidden" name="name_key" value="' . e($job) . '">'
         . '<input type="hidden" name="crew_row" value="' . $c['row'] . '">'
         . '<input type="hidden" name="k_role" value="' . e($c['role']) . '">';
}

render_header($p['name'] !== '' ? $p['name'] : 'Trabalho', 'projects');
render_flashes();
echo sheet_status_line($d);
?>

<h1><?= e($p['name'] !== '' ? $p['name'] : '(sem nome)') ?></h1>
<div class="sub">
  <a href="/admin/dashboard.php#carteira">← Painel</a>
  · <?= e($p['type_label'] ?: 'Trabalho') ?>
  · <?= month_name($p['start_month']) ?> <?= $p['year'] ?: '' ?>
</div>

<form method="post" class="actions" style="margin:-0.8rem 0 1.6rem">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="drive_folder">
  <input type="hidden" name="row" value="<?= $id ?>">
  <input type="hidden" name="name_key" value="<?= e($p['name']) ?>">
  <button class="btn ghost sm" type="submit">Pasta no Drive</button>
</form>

<div class="stats">
  <div class="stat hero <?= $profit < 0 ? 'neg' : '' ?>">
    <div class="k">Lucro</div>
    <div class="v"><?= eur($profit) ?></div>
    <div class="n">Margem <?= number_format($margin, 1, ',', '') ?>%</div>
  </div>
  <div class="stat">
    <div class="k">Orçamento s/ IVA</div>
    <div class="v"><?= eur($net) ?></div>
    <div class="n">c/ IVA: <?= eur($p['budget_gross']) ?></div>
  </div>
  <div class="stat">
    <div class="k">Gastos</div>
    <div class="v"><?= eur($exp) ?></div>
    <div class="n"><?= number_format($usedPct, 0) ?>% do orçamento
      <div class="meter"><i class="<?= $exp > $net ? 'over' : '' ?>" style="width:<?= $usedPct ?>%"></i></div>
    </div>
  </div>
  <div class="stat">
    <div class="k">Equipa fechada</div>
    <div class="v"><?= eur($crewConfirmed) ?></div>
    <div class="n">de <?= eur($crewTotal) ?> orçamentado</div>
  </div>
</div>

<h2>IVA deste trabalho</h2>
<div class="stats paint">
  <div class="stat">
    <div class="k">IVA a cobrar ao cliente</div>
    <div class="v"><?= eur($vatOut) ?></div>
    <div class="n">
      <?= has_vat($p)
            ? number_format($vat, 0) . '% sobre ' . eur($net)
            : 'Marcado como não tributado' ?>
    </div>
  </div>
  <div class="stat">
    <div class="k">IVA suportado nos custos</div>
    <div class="v"><?= eur($vatIn) ?></div>
    <div class="n">
      <?= $p['expenses_mode'] === 'crew'
            ? 'Somado linha a linha na lista de custos'
            : (has_vat($p) ? 'Custo manual com IVA' : 'Marcado como sem IVA') ?>
    </div>
  </div>
  <div class="stat">
    <div class="k">Total a receber</div>
    <div class="v"><?= eur($net + $vatOut) ?></div>
    <div class="n">Orçamento mais IVA</div>
  </div>
  <div class="stat">
    <div class="k">Efeito no IVA a entregar</div>
    <div class="v"><?= eur($vatOut - $vatIn) ?></div>
    <div class="n">Cobrado menos suportado</div>
  </div>
</div>

<h2>Custos do trabalho</h2>
<?php if ($p['expenses_mode'] === 'crew'): ?>
  <div class="flash ok" style="background:var(--sky);color:var(--black);border-color:var(--black)">
    O custo deste trabalho é a soma da lista abaixo: <b><?= eur($crewTotal) ?></b> s/ IVA,
    mais <b><?= eur($crewVat) ?></b> de IVA dedutível.
  </div>
<?php else: ?>
  <div class="flash" style="background:var(--paper);border-color:var(--hairline)">
    Este trabalho está com custo manual (<?= eur($p['expenses_manual']) ?>), por isso a lista
    abaixo (<?= eur($crewTotal) ?>) <b>não</b> está a contar. Mude para «somar a lista de custos»
    nos detalhes, em baixo.
  </div>
<?php endif; ?>

<div class="tablewrap">
<table>
  <thead>
    <tr>
      <th>Pessoa</th><th>Função</th>
      <th class="num">Valor</th>
      <th>O valor é</th>
      <th class="num">Custo s/ IVA</th>
      <th class="num">IVA</th>
      <th class="num">Total a pagar</th>
      <th>Estado</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$crew): ?>
    <tr><td colspan="9" class="empty">Sem custos registados. Acrescente o primeiro abaixo.</td></tr>
  <?php endif; ?>
  <?php foreach ($crew as $c): $cid = $c['row']; ?>
    <tr class="<?= $pendingCrew === $cid || ($ec && $ec['row'] === $cid) ? 'pending' : '' ?>">
      <td class="b"><?= e($c['person'] ?: '—') ?>
        <?php if ($c['contact']): ?>
          <div class="muted" style="font-size:0.6rem"><?= e($c['contact']) ?></div>
        <?php endif; ?>
        <?php if ($c['notes']): ?>
          <div class="muted" style="font-size:0.6rem"><?= e(shorten($c['notes'], 60)) ?></div>
        <?php endif; ?>
      </td>
      <td><?= e($c['role']) ?></td>
      <td class="num"><?= eur($c['fee']) ?></td>
      <td>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="set_crew_vat">
          <input type="hidden" name="row" value="<?= $id ?>">
          <?= crew_hidden($c, $p['name']) ?>
          <select name="vat_mode" class="mini" onchange="this.form.submit()"
                  title="O valor escrito já inclui IVA, leva IVA por cima, ou não tem IVA?">
            <?php foreach (VAT_MODES as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $c['vat_mode'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td class="num"><?= eur($c['net']) ?></td>
      <td class="num <?= $c['vat'] > 0 ? '' : 'muted' ?>"><?= $c['vat'] > 0 ? eur($c['vat']) : '—' ?></td>
      <td class="num b"><?= eur($c['total']) ?></td>
      <td>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle_crew">
          <input type="hidden" name="row" value="<?= $id ?>">
          <input type="hidden" name="to" value="<?= $c['is_confirmed'] ? '0' : '1' ?>">
          <?= crew_hidden($c, $p['name']) ?>
          <button type="submit" class="chip <?= $c['is_confirmed'] ? 'ok' : 'wait' ?>"
                  style="border:0;cursor:pointer;font-family:inherit">
            <?= $c['is_confirmed'] ? 'Fechado' : 'Em aberto' ?>
          </button>
        </form>
      </td>
      <td class="num nowrap">
        <?php if ($pendingCrew === $cid): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="del_crew">
            <input type="hidden" name="row" value="<?= $id ?>">
            <?= crew_hidden($c, $p['name']) ?>
            <button class="btn danger sm" type="submit">Sim, remover</button>
          </form>
          <a class="lnk" href="<?= e($here) ?>">Cancelar</a>
        <?php else: ?>
          <a class="lnk" href="<?= e(job_page($id, $p['name'], '&editcrew=' . $cid . '#custo')) ?>">Editar</a>
          <a class="lnk del" href="<?= e(job_page($id, $p['name'], '&delcrew=' . $cid)) ?>">Remover</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr>
      <td colspan="3">Total dos custos</td>
      <td></td>
      <td class="num"><?= eur($crewTotal) ?></td>
      <td class="num"><?= eur($crewVat) ?></td>
      <td class="num"><?= eur($crewTotal + $crewVat) ?></td>
      <td colspan="2" class="muted" style="font-weight:400">
        Fechado <?= eur($crewConfirmed) ?> · em aberto <?= eur($crewTotal - $crewConfirmed) ?>
      </td>
    </tr>
  </tfoot>
</table>
</div>

<?php $fMode = $ec['vat_mode'] ?? 'plus'; ?>
<form method="post" id="custo" style="margin-top:1.2rem">
  <?= csrf_field() ?>
  <input type="hidden" name="row" value="<?= $id ?>">
  <?php if ($ec): ?>
    <input type="hidden" name="do" value="save_crew">
    <?= crew_hidden($ec, $p['name']) ?>
    <h2>Editar custo</h2>
  <?php else: ?>
    <input type="hidden" name="do" value="add_crew">
    <input type="hidden" name="name_key" value="<?= e($p['name']) ?>">
  <?php endif; ?>
  <div class="grid">
    <div class="field">
      <label for="person">Pessoa</label>
      <input type="text" id="person" name="person" placeholder="Nome" value="<?= e($ec['person'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="role">Função</label>
      <input type="text" id="role" name="role" placeholder="Diretor de Fotografia" required value="<?= e($ec['role'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="fee">Valor (€)</label>
      <input type="text" id="fee" name="fee" inputmode="decimal" placeholder="0,00" required
             value="<?= $ec ? number_format($ec['fee'], 2, ',', '') : '' ?>">
    </div>
    <div class="field">
      <label for="crewvat">O valor que escrevi</label>
      <select id="crewvat" name="vat_mode">
        <option value="plus" <?= $fMode === 'plus' ? 'selected' : '' ?>>Não inclui IVA — acrescem <?= number_format($vat, 0) ?>%</option>
        <option value="incl" <?= $fMode === 'incl' ? 'selected' : '' ?>>Já inclui IVA</option>
        <option value="none" <?= $fMode === 'none' ? 'selected' : '' ?>>Não tem IVA</option>
      </select>
    </div>
    <div class="field">
      <label for="crewnotes2">Notas</label>
      <input type="text" id="crewnotes2" name="notes" value="<?= e($ec['notes'] ?? '') ?>">
    </div>
    <div class="field">
      <label for="contact">Contacto</label>
      <input type="text" id="contact" name="contact" placeholder="@instagram / telefone" value="<?= e($ec['contact'] ?? '') ?>">
    </div>
  </div>
  <div class="actions">
    <label class="check"><input type="checkbox" name="is_confirmed" <?= !empty($ec['is_confirmed']) ? 'checked' : '' ?>> Fechado</label>
  </div>
  <div class="actions">
    <?php if ($ec): ?>
      <button class="btn" type="submit">Guardar custo</button>
      <a class="btn ghost" href="<?= e($here) ?>">Cancelar</a>
    <?php else: ?>
      <button class="btn" type="submit">Adicionar custo</button>
      <span class="muted" style="font-size:0.68rem">
        O valor fica guardado tal como o escrever — a coluna «O valor é» pode ser mudada depois.
      </span>
    <?php endif; ?>
  </div>
</form>

<h2>Detalhes do registo</h2>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save_project">
  <input type="hidden" name="row" value="<?= $id ?>">
  <input type="hidden" name="name_key" value="<?= e($p['name']) ?>">
  <div class="grid">
    <div class="field">
      <label for="pname">Nome</label>
      <input type="text" id="pname" name="name" value="<?= e($p['name']) ?>" required>
    </div>
    <div class="field">
      <label for="pmode2">Como contar os custos</label>
      <select id="pmode2" name="expenses_mode">
        <option value="crew"   <?= $p['expenses_mode'] === 'crew'   ? 'selected' : '' ?>>Somar a lista de custos</option>
        <option value="manual" <?= $p['expenses_mode'] === 'manual' ? 'selected' : '' ?>>Um valor manual</option>
      </select>
    </div>
    <div class="field">
      <label for="ptype">Tipo</label>
      <select id="ptype" name="type_label">
        <?= sheet_options(JOB_TYPES, $p['type_label'], '—') ?>
      </select>
    </div>
    <div class="field">
      <label for="pmonth">Mês</label>
      <select id="pmonth" name="start_month">
        <option value="">—</option>
        <?php foreach (MONTHS_PT as $i => $m): ?>
          <option value="<?= $i ?>" <?= $i === $p['start_month'] ? 'selected' : '' ?>><?= $m ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="pyear">Ano</label>
      <input type="number" id="pyear" name="year" value="<?= $p['year'] ?: (int)date('Y') ?>" min="2020" max="2100">
    </div>
    <div class="field">
      <label for="pbudget">Orçamento s/ IVA (€)</label>
      <input type="text" id="pbudget" name="budget_net" inputmode="decimal"
             value="<?= number_format($p['budget_net'], 2, ',', '') ?>">
    </div>
    <div class="field">
      <label for="pexp">Custo manual (€)</label>
      <input type="text" id="pexp" name="expenses_manual" inputmode="decimal"
             value="<?= number_format($p['expenses_manual'], 2, ',', '') ?>">
    </div>
    <div class="field wide">
      <label for="pnotes">Notas</label>
      <textarea id="pnotes" name="notes"><?= e($p['notes']) ?></textarea>
    </div>
  </div>
  <div class="actions">
    <label class="check"><input type="checkbox" name="has_vat" <?= has_vat($p) ? 'checked' : '' ?>> Tem IVA (<?= number_format($vat, 0) ?>%)</label>
    <label class="check"><input type="checkbox" name="amount_includes_vat"> Os valores acima já incluem IVA</label>
    <label class="check"><input type="checkbox" name="is_international" <?= $p['is_international'] ? 'checked' : '' ?>> Cliente estrangeiro</label>
    <label class="check"><input type="checkbox" name="is_paid" <?= $p['is_paid'] ? 'checked' : '' ?>> Já pago</label>
  </div>
  <p class="muted" style="font-size:0.68rem;margin:0.6rem 0 0">
    Os campos acima mostram sempre o valor <b>sem IVA</b>. Ligue «já incluem IVA» só quando
    estiver a escrever um total copiado de uma fatura — nesse momento é dividido por
    <?= number_format(1 + $vat / 100, 2, ',', '') ?> antes de guardar. A caixa não fica ligada.
  </p>
  <div class="actions">
    <button class="btn" type="submit">Guardar</button>
    <a class="btn ghost" href="/admin/dashboard.php#carteira">Voltar</a>
  </div>
</form>

<?php render_footer(); ?>
