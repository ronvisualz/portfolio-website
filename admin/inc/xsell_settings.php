<?php
/**
 * topper. admin — Definições › Pipeline › Crosselling (§22): os pacotes pré-definidos e as
 * regras de crosselling depois da entrega. Incluído por funis.php (separador «Crosselling»).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

$xsFunnels = funnels_active($pdo);
$xsNames = array_column($xsFunnels, 'name', 'id');
$xsTpls = $pdo->query('SELECT id, name FROM budget_templates ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$xsDis = $canRules ? '' : ' disabled';

/** O formulário de um pacote (novo ou a editar). */
$pkgForm = function (?array $p) use ($xsFunnels, $xsTpls, $xsDis): string {
    $fs = $p ? xsell_package_funnels($p) : [];
    $main = $fs[0] ?? 0;
    $rows = '';
    foreach ($xsFunnels as $f) {
        $fid = (int)$f['id'];
        $rows .= '<tr><td><label class="check"><input type="checkbox" name="funnels[]" value="' . $fid . '"' . (in_array($fid, $fs, true) ? ' checked' : '') . $xsDis . '> ' . e($f['name']) . '</label></td>'
               . '<td><label class="check"><input type="radio" name="main" value="' . $fid . '"' . ($fid === $main ? ' checked' : '') . $xsDis . '> principal</label></td></tr>';
    }
    $sync = '';
    foreach (XSELL_SYNC as $k => $label) $sync .= '<option value="' . $k . '"' . (($p['sync_mode'] ?? 'sync') === $k ? ' selected' : '') . '>' . e($label) . '</option>';
    $tpl = '<option value="">Sem modelo</option>';
    foreach ($xsTpls as $id => $n) $tpl .= '<option value="' . (int)$id . '"' . ((int)($p['template_id'] ?? 0) === (int)$id ? ' selected' : '') . '>' . e($n) . '</option>';
    $id = (int)($p['id'] ?? 0);
    return '<form method="post" class="xs-pkf">' . csrf_field() . '<input type="hidden" name="do" value="pkg_save"><input type="hidden" name="pkg_id" value="' . $id . '">'
         . '<div class="grid"><div class="field"><label for="pk-n-' . $id . '">Nome</label><input type="text" id="pk-n-' . $id . '" name="name" maxlength="120" required value="' . e((string)($p['name'] ?? '')) . '" placeholder="ex.: Campanha + Ads"' . $xsDis . '></div>'
         . '<div class="field"><label for="pk-s-' . $id . '">Frentes</label><select id="pk-s-' . $id . '" name="sync_mode"' . $xsDis . '>' . $sync . '</select></div>'
         . '<div class="field"><label for="pk-t-' . $id . '">Modelo de orçamento <small class="muted">opcional</small></label><select id="pk-t-' . $id . '" name="template_id"' . $xsDis . '>' . $tpl . '</select></div>'
         . ($id ? '<div class="field"><label class="check" style="margin-top:22px"><input type="checkbox" name="is_active" value="1"' . ((int)$p['is_active'] ? ' checked' : '') . $xsDis . '> Ativo</label></div>' : '')
         . '</div><div class="tablewrap"><table class="xs-pkt"><thead><tr><th>Serviços do pacote</th><th>Principal</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
         . ($xsDis === '' ? '<div class="actions"><button class="btn sm" type="submit">' . ($id ? 'Guardar pacote' : 'Criar pacote') . '</button></div>' : '')
         . '</form>';
};
?>
<section class="lv-blk" id="pacotes">
  <h4>Pacotes <span class="h-sub">escolher um pacote preenche os serviços do negócio, a sincronização e (se a folha ainda não existir) o orçamento</span></h4>
  <?php $pks = xsell_packages($pdo, false); if (!$pks): ?><p class="muted small">Ainda não há pacotes.</p><?php endif; ?>
  <?php foreach ($pks as $p): ?>
    <details class="xs-pk-item">
      <summary><b><?= e($p['name']) ?></b> <span class="muted small"><?= e(implode(' + ', array_map(fn($f) => $xsNames[$f] ?? '?', xsell_package_funnels($p)))) ?> · <?= e(mb_strtolower(XSELL_SYNC[$p['sync_mode']] ?? '')) ?><?= (int)$p['is_active'] ? '' : ' · inativo' ?></span></summary>
      <?= $pkgForm($p) ?>
      <?php if ($canRules): ?>
        <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="pkg_del"><input type="hidden" name="pkg_id" value="<?= (int)$p['id'] ?>">
          <button class="btn ghost danger-t sm" type="submit">Apagar pacote</button></form>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>
  <?php if ($canRules): ?><details class="xs-pk-item" <?= $pks ? '' : 'open' ?>><summary><?= icon('plus') ?> Novo pacote</summary><?= $pkgForm(null) ?></details><?php endif; ?>
</section>

<section class="lv-blk" id="depois">
  <h4>Crosselling depois da entrega <span class="h-sub">ao concluir um serviço, o comercial recebe «Propor … a {cliente}» se o cliente ainda não o tiver</span></h4>
  <?php $rules = $pdo->query('SELECT * FROM xsell_rules ORDER BY from_funnel_id, to_funnel_id')->fetchAll();
        $fopts = function (int $cur) use ($xsNames) { $o = ''; foreach ($xsNames as $id => $n) $o .= '<option value="' . (int)$id . '"' . ((int)$id === $cur ? ' selected' : '') . '>' . e($n) . '</option>'; return $o; }; ?>
  <div class="tablewrap"><table class="xs-rt">
    <thead><tr><th>Depois de entregar</th><th>Propor</th><th class="num">Ao fim de</th><th>Ativa</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rules): ?><tr><td colspan="5" class="empty">Ainda não há regras. Ex.: depois de VFX & Pós-produção, propor Ads ao fim de 30 dias.</td></tr><?php endif; ?>
    <?php foreach ($rules as $r): $fid = 'xr-' . (int)$r['id']; ?>
      <tr>
        <td><select name="from_funnel_id" form="<?= $fid ?>" aria-label="Depois de entregar"<?= $xsDis ?>><?= $fopts((int)$r['from_funnel_id']) ?></select></td>
        <td><select name="to_funnel_id" form="<?= $fid ?>" aria-label="Propor"<?= $xsDis ?>><?= $fopts((int)$r['to_funnel_id']) ?></select></td>
        <td class="num nowrap"><input type="number" name="days" min="0" max="365" value="<?= (int)$r['days'] ?>" form="<?= $fid ?>" aria-label="Dias" style="width:5em"<?= $xsDis ?>> dias</td>
        <td><input type="checkbox" name="is_active" value="1" form="<?= $fid ?>"<?= (int)$r['is_active'] ? ' checked' : '' ?> aria-label="Ativa"<?= $xsDis ?>></td>
        <td class="nowrap"><?php if ($canRules): ?>
          <form method="post" id="<?= $fid ?>" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="xr_save"><input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>"><button class="btn ghost sm" type="submit">Guardar</button></form>
          <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="xr_del"><input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>"><button class="btn ghost danger-t sm" type="submit" aria-label="Apagar a regra"><?= icon('x') ?></button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($canRules): ?>
      <tr class="xs-new">
        <td><select name="from_funnel_id" form="xr-new" aria-label="Depois de entregar"><?= $fopts(0) ?></select></td>
        <td><select name="to_funnel_id" form="xr-new" aria-label="Propor"><?= $fopts((int)(array_keys($xsNames)[1] ?? 0)) ?></select></td>
        <td class="num nowrap"><input type="number" name="days" min="0" max="365" value="30" form="xr-new" aria-label="Dias" style="width:5em"> dias</td>
        <td></td>
        <td><form method="post" id="xr-new" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="xr_save"><button class="btn sm" type="submit"><?= icon('plus') ?> Acrescentar</button></form></td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table></div>
  <p class="muted small">A tarefa vai para o responsável comercial do negócio; a oportunidade fica na ficha do cliente (Serviços comprados) e conta nos Relatórios › Crosselling.</p>
</section>
