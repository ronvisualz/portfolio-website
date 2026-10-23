<?php
/**
 * topper. admin — o formulário X-Ray do negócio (§19.2)
 *
 * Campos comuns a todos os funis + as perguntas do funil (Definições › Funis e automações),
 * a pontuação de qualificação (orçamento, autoridade, necessidade, prazo: 1 a 3) e as duas
 * secções finais: «Pedido do cliente» e «Proposta de valor». Gravar não valida: validar é
 * do responsável comercial (ou de um admin) e só com o X-Ray preenchido. Mudar um X-Ray já
 * validado tira a validação.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/pipeline_view.php';
require_once __DIR__ . '/inc/alerts.php';
require_once __DIR__ . '/inc/xsell.php';
$user = require_login();

$deal = record_get($pdo, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$deal || $deal['code'] === null || !record_reach($pdo, $deal)) {
    flash('Negócio não encontrado.', 'err');
    redirect('/admin/pipeline.php');
}
$id = (int)$deal['id'];
$edit = flow_can_edit($pdo, $deal) && in_array($deal['phase'], ['pipeline', 'production', 'done'], true);
$self = '/admin/xray.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (!$edit) throw new RuntimeException('Sem permissão para mudar este negócio.');
        $msg = xray_save($pdo, $deal, $_POST);
        // Os serviços falados com o cliente e não incluídos ficam como oportunidade (§22.6).
        if (isset($_POST['xs_opps_sent'])) xsell_xray_opps_save($pdo, $deal, (array)($_POST['xs_opp'] ?? []), trim((string)($_POST['xs_opp_note'] ?? '')));
        if (($_POST['do'] ?? '') === 'validate') {
            if (!flow_is_commercial($deal)) throw new RuntimeException('Guardado. Validar é do responsável comercial ou de um admin.');
            $msg = xray_validate($pdo, deal_get($pdo, $id), (int)$user['id']);
        }
        flash($msg);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
    }
    redirect($self);
}

$x = xray_data($deal);
$qs = xray_questions_deal($pdo, $deal);
$xsLabel = xsell_label($pdo, $deal);
$xsIn = xsell_funnel_ids($pdo, $deal);
$xsOpps = xsell_xray_opps($pdo, $id);
$miss = xray_missing($pdo, $deal, $x);
$score = xray_score($x);
$funnel = $deal['funnel_id'] ? funnel_get($pdo, (int)$deal['funnel_id']) : null;
$title = 'X-Ray · ' . $deal['code'];
render_header($title, 'pipeline', '', [$deal['code'] . ' · ' . $deal['name'], 'X-Ray']);
render_flashes();
$val = fn(string $k) => e((string)($x[$k] ?? ''));
$dis = $edit ? '' : ' disabled';
?>
<div class="lead-page-top">
  <a class="btn ghost sm" href="<?= e(deal_url($deal)) ?>" data-back><?= icon('chev-left') ?> <?= e($deal['code']) ?> · <?= e($deal['name']) ?></a>
</div>
<header class="xr-head">
  <h1>X-Ray <span class="muted"><?= e($deal['code']) ?></span></h1>
  <p class="muted"><?= e($deal['name']) ?><?= $funnel ? ' · ' . e($funnel['name']) : '' ?></p>
  <p class="xr-state">
    <?php if (!empty($deal['xray_validated_at'])): ?>
      <span class="chip ok"><?= icon('check') ?> Validado por <?= e(flow_user_name($pdo, $deal['xray_validated_by']) ?: '—') ?> a <?= e(date('d/m/Y', strtotime((string)$deal['xray_validated_at']))) ?></span>
    <?php elseif ($miss): ?>
      <span class="chip wait">Por preencher: <?= e(implode(', ', array_slice($miss, 0, 5))) ?><?= count($miss) > 5 ? '…' : '' ?></span>
    <?php else: ?>
      <span class="chip">Preenchido · falta validar</span>
    <?php endif; ?>
    <?php if ($score !== null): ?><span class="chip" data-tip="Orçamento + autoridade + necessidade + prazo (1 a 3 cada)">Qualificação <?= $score ?>/12</span><?php endif; ?>
  </p>
</header>

<form method="post" class="xr" action="<?= e($self) ?>">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <section class="lv-blk">
    <h4>Qualificação e briefing <span class="h-sub">comum a todos os funis · * obrigatório</span></h4>
    <div class="grid">
      <?php foreach (XRAY_COMMON as $k => [$label, $type, $req]): ?>
        <div class="field<?= $type === 'textarea' ? ' wide' : '' ?>"><label for="x-<?= $k ?>"><?= e($label) ?><?= $req ? ' *' : '' ?></label>
          <?php if ($type === 'textarea'): ?>
            <textarea id="x-<?= $k ?>" name="<?= $k ?>" rows="3" maxlength="4000"<?= $dis ?>><?= $val($k) ?></textarea>
          <?php elseif ($type === 'select'): ?>
            <select id="x-<?= $k ?>" name="<?= $k ?>"<?= $dis ?>><option value="">—</option>
              <?php foreach (XRAY_BUDGET_RANGES as $r): ?><option<?= ($x[$k] ?? '') === $r ? ' selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select>
          <?php else: ?>
            <input type="text" id="x-<?= $k ?>" name="<?= $k ?>" maxlength="500" value="<?= $val($k) ?>"<?= $dis ?>>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <fieldset class="xr-score"><legend>Pontuação de qualificação * <span class="muted small">1 = fraco · 3 = forte</span></legend>
      <?php foreach (XRAY_SCORES as $k => $label): ?>
        <div class="xr-sc" role="radiogroup" aria-label="<?= e($label) ?>"><span><?= e($label) ?></span>
          <?php for ($n = 1; $n <= 3; $n++): ?><label class="rb"><input type="radio" name="<?= $k ?>" value="<?= $n ?>"<?= (int)($x[$k] ?? 0) === $n ? ' checked' : '' ?><?= $dis ?>><span><?= $n ?></span></label><?php endfor; ?>
        </div>
      <?php endforeach; ?>
    </fieldset>
  </section>

  <section class="lv-blk">
    <h4>Perguntas do funil <span class="h-sub"><?= e($xsLabel !== '' ? $xsLabel : ($funnel['name'] ?? '—')) ?> · editáveis em Definições › Funis e automações</span></h4>
    <?php if (!$qs): ?><p class="muted small">Este funil ainda não tem perguntas próprias.</p><?php endif; ?>
    <div class="grid">
      <?php foreach ($qs as $i => $q): ?>
        <div class="field wide"><label for="xq-<?= $i ?>"><?= e($q) ?></label>
          <textarea id="xq-<?= $i ?>" name="q[<?= $i ?>]" rows="2" maxlength="2000"<?= $dis ?>><?= e((string)($x['funil'][$q] ?? '')) ?></textarea></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="lv-blk xr-xs">
    <h4>Oportunidades de crosselling <span class="h-sub">serviços falados com o cliente e não incluídos</span></h4>
    <input type="hidden" name="xs_opps_sent" value="1">
    <div class="chips-check">
      <?php foreach (funnels_active($pdo) as $f): if (in_array((int)$f['id'], $xsIn, true)) continue; ?>
        <label class="rb"><input type="checkbox" name="xs_opp[]" value="<?= (int)$f['id'] ?>"<?= isset($xsOpps[(int)$f['id']]) ? ' checked' : '' ?><?= $dis ?>><span><?= e($f['name']) ?></span></label>
      <?php endforeach; ?>
    </div>
    <input type="text" name="xs_opp_note" maxlength="255" placeholder="Nota (opcional): o que o cliente disse" aria-label="Nota das oportunidades" value="<?= e((string)(reset($xsOpps) ?: '')) ?>"<?= $dis ?>>
    <p class="muted small">Ficam registadas (contam nos Relatórios › Crosselling e aparecem na ficha do cliente). Para vender já, junte o serviço ao negócio em «Serviços».</p>
  </section>

  <div class="xr-final">
    <?php foreach (XRAY_FINAL as $k => [$label, $help]): ?>
      <section class="lv-blk xr-<?= $k ?>"><h4><?= e($label) ?><?= $k === 'pedido' ? ' *' : '' ?></h4>
        <p class="muted small"><?= e($help) ?></p>
        <textarea name="<?= $k ?>" rows="6" maxlength="6000" aria-label="<?= e($label) ?>"<?= $dis ?>><?= $val($k) ?></textarea>
        <p class="muted small"><?= $k === 'pedido' ? 'Vai dar a Opção A (Pedido) da proposta.' : 'Vai dar a Opção B (Proposta de valor), se a houver.' ?></p></section>
    <?php endforeach; ?>
  </div>

  <?php if ($edit): ?>
  <div class="actions xr-acts">
    <button class="btn ghost" type="submit" name="do" value="save"><?= icon('check') ?> Guardar</button>
    <?php if (flow_is_commercial($deal)): ?>
      <button class="btn" type="submit" name="do" value="validate"><?= icon('check') ?> Guardar e validar o X-Ray</button>
    <?php else: ?>
      <span class="muted small">Validar é do responsável comercial (<?= e(flow_user_name($pdo, $deal['owner_id']) ?: 'por atribuir') ?>) ou de um admin.</span>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</form>
<?php render_footer();
