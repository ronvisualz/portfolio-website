<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/pipeline.php';
$user = require_login();
require_perm('pipeline', PERM_MANAGE);

// Configurar funis: criar (do zero ou combinando funis existentes),
// renomear, ativar/desativar, e gerir as etapas variáveis. As etapas
// fixas (X-Ray, Kickoff, Concluído) podem ser renomeadas mas não saem.

function funis_url(array $q = []): string {
    return '/admin/funis.php' . ($q ? '?' . http_build_query($q) : '');
}

/** As etapas variáveis vindas do formulário de criação: [[nome, tipo], …]. */
function posted_stages(): array {
    $out = [];
    $names = (array)($_POST['st_name'] ?? []);
    $kinds = (array)($_POST['st_kind'] ?? []);
    $drop  = (array)($_POST['st_drop'] ?? []);
    foreach ($names as $i => $n) {
        $n = mb_substr(trim((string)$n), 0, 120);
        $k = (string)($kinds[$i] ?? '');
        if ($n === '' || !empty($drop[$i]) || !in_array($k, ['commercial', 'production'], true)) continue;
        $out[] = [$n, $k];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do  = (string)($_POST['do'] ?? '');
    $fid = (int)($_POST['funnel_id'] ?? 0);

    if ($do === 'create') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        if ($name === '') { flash('Dê um nome ao funil.', 'err'); redirect(funis_url()); }
        $from = array_map('intval', (array)($_POST['from'] ?? []));
        $fromNames = [];
        foreach ($from as $id) if ($f = funnel_get($pdo, $id)) $fromNames[] = $f['name'];
        $pdo->beginTransaction();
        $id = funnel_create($pdo, $name, posted_stages(), implode(' + ', $fromNames));
        $pdo->commit();
        flash("Funil «{$name}» criado.");
        redirect(funis_url(['edit' => $id]));
    }

    $f = funnel_get($pdo, $fid);
    if (!$f) { flash('Funil não encontrado.', 'err'); redirect(funis_url()); }
    $back = funis_url(['edit' => $fid]);

    if ($do === 'rename') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        if ($name !== '') $pdo->prepare('UPDATE funnels SET name = ? WHERE id = ?')->execute([$name, $fid]);
        flash('Funil guardado.');
        redirect($back);
    }
    if ($do === 'toggle') {
        $pdo->prepare('UPDATE funnels SET is_active = 1 - is_active WHERE id = ?')->execute([$fid]);
        flash((int)$f['is_active'] ? 'Funil desativado: deixa de aparecer no Pipeline e na conversão de leads. Os negócios mantêm-se.' : 'Funil ativado.');
        redirect($back);
    }
    if ($do === 'add_stage') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        $kind = (string)($_POST['kind'] ?? '');
        if ($name === '' || !in_array($kind, ['commercial', 'production'], true)) { flash('Indique o nome e a fase da etapa.', 'err'); redirect($back); }
        $st = $pdo->prepare('SELECT COALESCE(MAX(position), ?) FROM funnel_stages WHERE funnel_id = ? AND kind = ?');
        $st->execute([$kind === 'commercial' ? 19 : 59, $fid, $kind]);
        $pdo->prepare('INSERT INTO funnel_stages (funnel_id, name, kind, position) VALUES (?,?,?,?)')
            ->execute([$fid, $name, $kind, (int)$st->fetchColumn() + 1]);
        flash('Etapa adicionada.');
        redirect($back);
    }

    $s = stage_get($pdo, (int)($_POST['stage_id'] ?? 0));
    if (!$s || (int)$s['funnel_id'] !== $fid) { flash('Etapa não encontrada.', 'err'); redirect($back); }

    if ($do === 'rename_stage') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        if ($name !== '') $pdo->prepare('UPDATE funnel_stages SET name = ? WHERE id = ?')->execute([$name, $s['id']]);
        flash('Etapa guardada.');
        redirect($back);
    }
    if ($do === 'move_stage') {
        // Troca com a vizinha da mesma zona (as fixas não se mexem).
        $zone = array_values(array_filter(funnel_stages($pdo, $fid), fn($x) => $x['kind'] === $s['kind']));
        $i = array_search((int)$s['id'], array_map(fn($x) => (int)$x['id'], $zone), true);
        $j = ($_POST['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
        if (in_array($s['kind'], ['commercial', 'production'], true) && isset($zone[$j])) {
            // reescreve as posições da zona pela nova ordem
            [$zone[$i], $zone[$j]] = [$zone[$j], $zone[$i]];
            $base = $s['kind'] === 'commercial' ? 20 : 60;
            $up = $pdo->prepare('UPDATE funnel_stages SET position = ? WHERE id = ?');
            foreach ($zone as $n => $x) $up->execute([$base + $n, $x['id']]);
        }
        redirect($back);
    }
    if ($do === 'del_stage') {
        if (!in_array($s['kind'], ['commercial', 'production'], true)) { flash('As etapas fixas não se eliminam.', 'err'); redirect($back); }
        $st = $pdo->prepare('SELECT COUNT(*) FROM deals WHERE stage_id = ?');
        $st->execute([$s['id']]);
        if ((int)$st->fetchColumn() > 0) { flash('Há negócios nesta etapa: mova-os primeiro.', 'err'); redirect($back); }
        $pdo->prepare('DELETE FROM funnel_stages WHERE id = ?')->execute([$s['id']]);
        flash('Etapa eliminada.');
        redirect($back);
    }
    redirect($back);
}

$all = $pdo->query('SELECT f.*, (SELECT COUNT(*) FROM deals d WHERE d.funnel_id = f.id AND d.phase IN (\'pipeline\',\'production\')) AS active_deals
                      FROM funnels f ORDER BY f.is_active DESC, f.position, f.name')->fetchAll();
$editId = (int)($_GET['edit'] ?? 0);
$ef = $editId ? funnel_get($pdo, $editId) : null;

render_header('Funis', 'pipeline');
render_flashes();
?>
<h1>Funis e etapas</h1>
<div class="sub"><a class="lnk" href="/admin/pipeline.php">← Pipeline</a></div>

<div class="tablewrap">
<table>
  <thead><tr><th>Funil</th><th class="hide-sm">Criado a partir de</th><th class="num">Negócios ativos</th><th>Estado</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($all as $f): ?>
    <tr class="<?= (int)$f['is_active'] ? '' : 'muted' ?> <?= $editId === (int)$f['id'] ? 'pending' : '' ?>">
      <td class="b"><?= e($f['name']) ?><?= (int)$f['is_base'] ? ' <span class="chip">base</span>' : '' ?></td>
      <td class="hide-sm muted"><?= e($f['made_from'] ?: '—') ?></td>
      <td class="num"><?= (int)$f['active_deals'] ?></td>
      <td><?= (int)$f['is_active'] ? 'Ativo' : 'Desativado' ?></td>
      <td class="num"><a class="lnk" href="<?= e(funis_url(['edit' => $f['id']])) ?>#funil">Editar</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($ef): $stages = funnel_stages($pdo, (int)$ef['id']); ?>
<h2 id="funil">Editar <span class="keep">«<?= e($ef['name']) ?>»</span></h2>
<form method="post" class="bar">
  <?= csrf_field() ?><input type="hidden" name="do" value="rename"><input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>">
  <input type="text" name="name" maxlength="120" value="<?= e($ef['name']) ?>" aria-label="Nome do funil">
  <button class="btn sm" type="submit">Renomear</button>
</form>
<form method="post" style="margin:0.5rem 0 1rem">
  <?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>">
  <button class="btn ghost sm" type="submit"><?= (int)$ef['is_active'] ? 'Desativar funil' : 'Ativar funil' ?></button>
</form>

<div class="tablewrap">
<table>
  <thead><tr><th>Etapa</th><th>Fase</th><th>Automações</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($stages as $s): $fixed = !in_array($s['kind'], ['commercial', 'production'], true); ?>
    <tr>
      <td>
        <form method="post" class="inline-edit"><?= csrf_field() ?>
          <input type="hidden" name="do" value="rename_stage"><input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>">
          <input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>">
          <input type="text" name="name" maxlength="120" value="<?= e($s['name']) ?>" aria-label="Nome da etapa" onchange="this.form.submit()">
        </form>
      </td>
      <td><?= $fixed ? '<span class="chip">fixa · ' . e(STAGE_KINDS[$s['kind']]) . '</span>' : e(STAGE_KINDS[$s['kind']]) ?></td>
      <td class="muted" style="font-size:0.7rem">em breve</td>
      <td class="num nowrap">
        <?php if (!$fixed): ?>
          <?php foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="do" value="move_stage"><input type="hidden" name="dir" value="<?= $dir ?>">
              <input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>"><input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>">
              <button class="btn ghost sm" type="submit" aria-label="<?= $dir === 'up' ? 'Subir' : 'Descer' ?>"><?= $arrow ?></button></form>
          <?php endforeach; ?>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="do" value="del_stage">
            <input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>"><input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>">
            <button class="btn danger sm" type="submit">Eliminar</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<form method="post" class="bar" style="margin-top:12px">
  <?= csrf_field() ?><input type="hidden" name="do" value="add_stage"><input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>">
  <input type="text" name="name" maxlength="120" placeholder="Nova etapa" required aria-label="Nova etapa">
  <select name="kind" aria-label="Fase"><option value="commercial">Comercial (entre X-Ray e Kickoff)</option><option value="production">Produção (entre Kickoff e Concluído)</option></select>
  <button class="btn sm" type="submit">Adicionar etapa</button>
</form>
<p class="muted" style="font-size:0.7rem">Cada etapa vai poder criar tarefas automaticamente quando um negócio lá entra. A estrutura já existe; a configuração das automações chega numa fase seguinte.</p>
<?php endif; ?>

<?php // ── novo funil ──
$combine = array_map('intval', (array)($_GET['from'] ?? []));
$preset  = $combine ? funnels_combined_stages($pdo, $combine) : [];
$activeF = array_filter($all, fn($f) => (int)$f['is_active']);
?>
<h2 id="novo">Novo funil</h2>
<form method="get" action="/admin/funis.php#novo">
  <div class="field wide"><label>Combinar funis existentes (opcional)</label>
    <div class="actions" style="margin-top:0.2rem">
      <?php foreach ($activeF as $f): ?>
        <label class="check"><input type="checkbox" name="from[]" value="<?= (int)$f['id'] ?>" <?= in_array((int)$f['id'], $combine, true) ? 'checked' : '' ?>> <?= e($f['name']) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="actions"><button class="btn ghost sm" type="submit">Juntar as etapas destes funis</button></div>
</form>

<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="create">
  <?php foreach ($combine as $id): ?><input type="hidden" name="from[]" value="<?= $id ?>"><?php endforeach; ?>
  <div class="grid">
    <div class="field wide"><label for="fname">Nome do funil</label>
      <input type="text" id="fname" name="name" required maxlength="120"
             value="<?= e(implode(' + ', array_map(fn($id) => funnel_get($pdo, $id)['name'] ?? '', $combine))) ?>"
             placeholder="ex.: VFX / 3D + CGI + Ads"></div>
  </div>
  <p class="muted" style="font-size:0.75rem">Etapas fixas: X-Ray → … → Kickoff → … → Concluído. As etapas abaixo entram entre elas; edite, retire ou acrescente antes de criar.</p>
  <div class="tablewrap"><table>
    <thead><tr><th>Etapa</th><th>Fase</th><th>Retirar</th></tr></thead>
    <tbody>
    <?php $rows = array_merge($preset, array_fill(0, 3, ['', 'commercial']));
      foreach ($rows as $i => [$n, $k]): ?>
      <tr>
        <td><input type="text" name="st_name[<?= $i ?>]" maxlength="120" value="<?= e($n) ?>" placeholder="<?= $n === '' ? 'nova etapa (opcional)' : '' ?>"></td>
        <td><select name="st_kind[<?= $i ?>]"><option value="commercial" <?= $k === 'commercial' ? 'selected' : '' ?>>Comercial</option>
            <option value="production" <?= $k === 'production' ? 'selected' : '' ?>>Produção</option></select></td>
        <td><?php if ($n !== ''): ?><input type="checkbox" name="st_drop[<?= $i ?>]" value="1" aria-label="Retirar"><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="actions"><button class="btn" type="submit">Criar funil</button></div>
</form>

<?php render_footer();
