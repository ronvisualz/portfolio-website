<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();

$id = (int)($_GET['id'] ?? $_POST['project_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM projects WHERE id = ?');
$st->execute([$id]);
$p = $st->fetch();
if (!$p) { http_response_code(404); exit('Registo não encontrado.'); }

$s   = settings($pdo);
$vat = (float)($s['vat_rate'] ?? 23);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'save_project') {
        // A pipeline só trata de trabalhos — o que entra dinheiro.
        $kind = 'job';
        $mode = ($_POST['expenses_mode'] ?? 'crew') === 'manual' ? 'manual' : 'crew';
        $gross = isset($_POST['amount_includes_vat']);
        $up = $pdo->prepare(
            'UPDATE projects SET name=?, kind=?, type_label=?, start_month=?, year=?,
                    budget_net=?, is_international=?, has_vat=?, expenses_mode=?,
                    expenses_manual=?, is_paid=?, notes=? WHERE id=?'
        );
        $up->execute([
            trim((string)($_POST['name'] ?? '')) ?: 'Sem nome',
            $kind,
            trim((string)($_POST['type_label'] ?? '')),
            ((int)($_POST['start_month'] ?? 0)) ?: null,
            (int)($_POST['year'] ?? $p['year']),
            $kind === 'expense' ? 0 : net_from_input($_POST['budget_net'] ?? 0, $gross, $vat),
            isset($_POST['is_international']) ? 1 : 0,
            isset($_POST['has_vat']) ? 1 : 0,
            $mode,
            net_from_input($_POST['expenses_manual'] ?? 0, $gross, $vat),
            isset($_POST['is_paid']) ? 1 : 0,
            trim((string)($_POST['notes'] ?? '')),
            $id,
        ]);
        flash('Alterações guardadas.');
        redirect('/admin/project.php?id=' . $id);
    }

    if ($do === 'add_crew') {
        $mode = (string)($_POST['vat_mode'] ?? 'plus');
        if (!isset(VAT_MODES[$mode])) $mode = 'plus';

        $ins = $pdo->prepare(
            'INSERT INTO crew (project_id, person, role, fee, vat_mode, contact, notes, is_confirmed, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $id,
            trim((string)($_POST['person'] ?? '')),
            trim((string)($_POST['role'] ?? '')),
            money_in($_POST['fee'] ?? 0),   // guarda-se tal como foi escrito
            $mode,
            trim((string)($_POST['contact'] ?? '')),
            trim((string)($_POST['notes'] ?? '')),
            isset($_POST['is_confirmed']) ? 1 : 0,
            (int)($_POST['sort_order'] ?? 0),
        ]);
        flash('Custo adicionado.');
        redirect('/admin/project.php?id=' . $id);
    }

    if ($do === 'toggle_crew') {
        $pdo->prepare('UPDATE crew SET is_confirmed = 1 - is_confirmed WHERE id = ? AND project_id = ?')
            ->execute([(int)($_POST['crew_id'] ?? 0), $id]);
        redirect('/admin/project.php?id=' . $id);
    }

    // O que é o valor desta linha: sem IVA, mais IVA, ou já com IVA
    // lá dentro. É daqui que sai o IVA gasto do trabalho.
    if ($do === 'set_crew_vat') {
        $mode = (string)($_POST['vat_mode'] ?? 'plus');
        if (!isset(VAT_MODES[$mode])) $mode = 'plus';
        $pdo->prepare('UPDATE crew SET vat_mode = ? WHERE id = ? AND project_id = ?')
            ->execute([$mode, (int)($_POST['crew_id'] ?? 0), $id]);
        redirect('/admin/project.php?id=' . $id);
    }

    if ($do === 'del_crew') {
        $pdo->prepare('DELETE FROM crew WHERE id = ? AND project_id = ?')
            ->execute([(int)($_POST['crew_id'] ?? 0), $id]);
        flash('Removido da equipa.');
        redirect('/admin/project.php?id=' . $id);
    }
}

$cq = $pdo->prepare('SELECT * FROM crew WHERE project_id = ? ORDER BY sort_order, id');
$cq->execute([$id]);
$crew = $cq->fetchAll();

// Pessoa à espera de confirmação para ser removida. Em dois passos,
// para não depender dos diálogos do browser, que podem estar bloqueados.
$pendingCrew = (int)($_GET['delcrew'] ?? 0);

$sum           = crew_totals($pdo, $id, $vat);
$crewTotal     = $sum['net'];        // o que pesa no lucro
$crewVat       = $sum['vat'];        // o que se deduz
$crewConfirmed = $sum['confirmed'];

$isJob   = $p['kind'] === 'job';
$net     = (float)$p['budget_net'];
$exp     = project_expenses($pdo, $p);
$vatOut  = project_vat_out($p, $vat);
$vatIn   = project_vat_in($pdo, $p, $vat);
$profit  = $isJob ? $net - $exp : 0.0;
$margin  = $net > 0 ? $profit / $net * 100 : 0;
$usedPct = $net > 0 ? min(100, $exp / $net * 100) : 0;

render_header($p['name'], 'projects');
render_flashes();
?>

<h1><?= e($p['name']) ?></h1>
<div class="sub">
  <a href="/admin/projects.php?tab=todos">← Carteira</a>
  · <?= $isJob ? e($p['type_label'] ?: 'Trabalho') : 'Gasto' ?>
  · <?= month_name((int)$p['start_month']) ?>
</div>

<?php if ($isJob): ?>
<div class="stats">
  <div class="stat hero <?= $profit < 0 ? 'neg' : '' ?>">
    <div class="k">Lucro</div>
    <div class="v"><?= eur($profit) ?></div>
    <div class="n">Margem <?= number_format($margin, 1, ',', '') ?>%</div>
  </div>
  <div class="stat">
    <div class="k">Orçamento s/ IVA</div>
    <div class="v"><?= eur($net) ?></div>
    <div class="n">c/ IVA: <?= eur(budget_gross($p, $vat)) ?></div>
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
<?php endif; ?>

<h2>Custos do trabalho</h2>
<?php if ($p['expenses_mode'] === 'crew'): ?>
  <div class="flash ok" style="background:var(--sky);color:var(--black);border-color:var(--black)">
    O custo deste trabalho é a soma da lista abaixo: <b><?= eur($crewTotal) ?></b> s/ IVA,
    mais <b><?= eur($crewVat) ?></b> de IVA dedutível.
  </div>
<?php else: ?>
  <div class="flash" style="background:var(--paper);border-color:var(--hairline)">
    Este trabalho está com custo manual (<?= eur((float)$p['expenses_manual']) ?>), por isso a lista
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
  <?php foreach ($crew as $c):
      $cNet = cost_net($c, $vat);
      $cVat = cost_vat($c, $vat); ?>
    <tr class="<?= $pendingCrew === (int)$c['id'] ? 'pending' : '' ?>">
      <td class="b"><?= e($c['person'] ?: '—') ?>
        <?php if ($c['contact']): ?>
          <div class="muted" style="font-size:0.6rem"><?= e($c['contact']) ?></div>
        <?php endif; ?>
        <?php if ($c['notes']): ?>
          <div class="muted" style="font-size:0.6rem"><?= e(shorten($c['notes'], 60)) ?></div>
        <?php endif; ?>
      </td>
      <td><?= e($c['role']) ?></td>
      <td class="num"><?= eur((float)$c['fee']) ?></td>
      <td>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="set_crew_vat">
          <input type="hidden" name="project_id" value="<?= $id ?>">
          <input type="hidden" name="crew_id" value="<?= (int)$c['id'] ?>">
          <select name="vat_mode" class="mini" onchange="this.form.submit()"
                  title="O valor escrito já inclui IVA, leva IVA por cima, ou não tem IVA?">
            <?php foreach (VAT_MODES as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= ($c['vat_mode'] ?? 'plus') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td class="num"><?= eur($cNet) ?></td>
      <td class="num <?= $cVat > 0 ? '' : 'muted' ?>"><?= $cVat > 0 ? eur($cVat) : '—' ?></td>
      <td class="num b"><?= eur($cNet + $cVat) ?></td>
      <td>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="toggle_crew">
          <input type="hidden" name="project_id" value="<?= $id ?>">
          <input type="hidden" name="crew_id" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="chip <?= (int)$c['is_confirmed'] ? 'ok' : 'wait' ?>"
                  style="border:0;cursor:pointer;font-family:inherit">
            <?= (int)$c['is_confirmed'] ? 'Fechado' : 'Em aberto' ?>
          </button>
        </form>
      </td>
      <td class="num nowrap">
        <?php if ($pendingCrew === (int)$c['id']): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="del_crew">
            <input type="hidden" name="project_id" value="<?= $id ?>">
            <input type="hidden" name="crew_id" value="<?= (int)$c['id'] ?>">
            <button class="btn danger sm" type="submit">Sim, remover</button>
          </form>
          <a class="lnk" href="/admin/project.php?id=<?= $id ?>">Cancelar</a>
        <?php else: ?>
          <a class="lnk del" href="/admin/project.php?id=<?= $id ?>&amp;delcrew=<?= (int)$c['id'] ?>">Remover</a>
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

<form method="post" style="margin-top:1.2rem">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="add_crew">
  <input type="hidden" name="project_id" value="<?= $id ?>">
  <div class="grid">
    <div class="field">
      <label for="person">Pessoa</label>
      <input type="text" id="person" name="person" placeholder="Nome">
    </div>
    <div class="field">
      <label for="role">Função</label>
      <input type="text" id="role" name="role" placeholder="Diretor de Fotografia" required>
    </div>
    <div class="field">
      <label for="fee">Valor (€)</label>
      <input type="text" id="fee" name="fee" inputmode="decimal" placeholder="0,00" required>
    </div>
    <div class="field">
      <label for="crewvat">O valor que escrevi</label>
      <select id="crewvat" name="vat_mode">
        <option value="plus" selected>Não inclui IVA — acrescem <?= number_format($vat, 0) ?>%</option>
        <option value="incl">Já inclui IVA</option>
        <option value="none">Não tem IVA</option>
      </select>
    </div>
    <div class="field">
      <label for="crewnotes2">Notas</label>
      <input type="text" id="crewnotes2" name="notes">
    </div>
    <div class="field">
      <label for="contact">Contacto</label>
      <input type="text" id="contact" name="contact" placeholder="@instagram / telefone">
    </div>
  </div>
  <div class="actions">
    <label class="check"><input type="checkbox" name="is_confirmed"> Fechado</label>
  </div>
  <div class="actions">
    <button class="btn" type="submit">Adicionar custo</button>
    <span class="muted" style="font-size:0.68rem">
      O valor fica guardado tal como o escrever — a coluna «O valor é» pode ser mudada depois.
    </span>
  </div>
</form>

<h2>Detalhes do registo</h2>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save_project">
  <input type="hidden" name="project_id" value="<?= $id ?>">
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
      <input type="text" id="ptype" name="type_label" value="<?= e($p['type_label']) ?>">
    </div>
    <div class="field">
      <label for="pmonth">Mês</label>
      <select id="pmonth" name="start_month">
        <option value="">—</option>
        <?php foreach (MONTHS_PT as $i => $m): ?>
          <option value="<?= $i ?>" <?= $i === (int)$p['start_month'] ? 'selected' : '' ?>><?= $m ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="pyear">Ano</label>
      <input type="number" id="pyear" name="year" value="<?= (int)$p['year'] ?>" min="2020" max="2100">
    </div>
    <div class="field">
      <label for="pbudget">Orçamento s/ IVA (€)</label>
      <input type="text" id="pbudget" name="budget_net" inputmode="decimal"
             value="<?= number_format((float)$p['budget_net'], 2, ',', '') ?>">
    </div>
    <div class="field">
      <label for="pexp">Custo manual (€)</label>
      <input type="text" id="pexp" name="expenses_manual" inputmode="decimal"
             value="<?= number_format((float)$p['expenses_manual'], 2, ',', '') ?>">
    </div>
    <div class="field wide">
      <label for="pnotes">Notas</label>
      <textarea id="pnotes" name="notes"><?= e($p['notes']) ?></textarea>
    </div>
  </div>
  <div class="actions">
    <label class="check"><input type="checkbox" name="has_vat" <?= has_vat($p) ? 'checked' : '' ?>> Tem IVA (<?= number_format($vat, 0) ?>%)</label>
    <label class="check"><input type="checkbox" name="amount_includes_vat"> Os valores acima já incluem IVA</label>
    <label class="check"><input type="checkbox" name="is_international" <?= (int)$p['is_international'] ? 'checked' : '' ?>> Cliente estrangeiro</label>
    <label class="check"><input type="checkbox" name="is_paid" <?= (int)$p['is_paid'] ? 'checked' : '' ?>> Já pago</label>
  </div>
  <p class="muted" style="font-size:0.68rem;margin:0.6rem 0 0">
    Os campos acima mostram sempre o valor <b>sem IVA</b>. Ligue «já incluem IVA» só quando
    estiver a escrever um total copiado de uma fatura — nesse momento é dividido por
    <?= number_format(1 + $vat / 100, 2, ',', '') ?> antes de guardar. A caixa não fica ligada.
  </p>
  <div class="actions">
    <button class="btn" type="submit">Guardar</button>
    <a class="btn ghost" href="/admin/projects.php?year=<?= (int)$p['year'] ?>">Voltar</a>
  </div>
</form>

<?php render_footer(); ?>
