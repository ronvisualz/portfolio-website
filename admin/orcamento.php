<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/reach.php';
require_once __DIR__ . '/inc/alerts.php';        // deal_url
require_once __DIR__ . '/inc/interactions.php';  // histórico
require_once __DIR__ . '/inc/budget_view.php';
require_once __DIR__ . '/inc/pipeline_view.php';  // enviar a proposta (§19.4)
$user = require_login();
require_page('orcamentacao');

// A folha de orçamento de um negócio (§14): o mesmo ID do negócio, versões
// v1, v2… (só a última se edita; a aceite conta para o Resultado e para o
// valor no Pipeline), linhas de custo, tempo, cálculo e preço. Cada mudança
// grava-se logo (ui_api.php) e o servidor devolve o cálculo já filtrado
// pelas permissões: sem Financeiro não chegam preços de fornecedores,
// custos internos, margens nem lucro.

$deal = budget_deal_row($pdo, (int)($_GET['id'] ?? 0));
if (!$deal || $deal['code'] === null || !in_array($deal['phase'], ['pipeline', 'production', 'done', 'lost'], true)) {
    flash('Folha de orçamento não encontrada.', 'err');
    redirect('/admin/orcamentacao.php');
}
$sheet = budget_sync_state($pdo, budget_sheet_ensure($pdo, $deal, (int)$user['id']), $deal);
$versions = budget_versions($pdo, (int)$sheet['id']);
$num = (int)($_GET['v'] ?? 0);
$v = null;
foreach ($versions as $x) if ((int)$x['num'] === $num) $v = $x;
$v ??= end($versions);
$money = budget_money();
$canEdit = can('orcamentacao', PERM_EDIT);
$editable = budget_editable($sheet, $v, $versions);
// Opção A (Pedido) e Opção B (Proposta de valor), §19.3: cada uma com as suas linhas e preço.
$hasOpts = budget_has_options($pdo);
$opt = $hasOpts && ($_GET['opt'] ?? '') === 'B' ? 'B' : 'A';
if ($editable) {
    $rc = budget_recalc($pdo, (int)$v['id'], $opt);
    $v = $rc['version']; $lines = $rc['lines']; $calc = $rc['calc'];
} else {
    $lines = budget_lines($pdo, (int)$v['id'], $hasOpts ? $opt : null);
    $calc = budget_calc($pdo, $v + ($hasOpts ? ['calc_opt' => $opt] : []), $lines);
}
$hasB = $hasOpts && ($opt === 'B' ? (bool)$lines : (bool)budget_lines($pdo, (int)$v['id'], 'B'));
$accepted = (int)$sheet['accepted_version_id'] === (int)$v['id'];
$last = end($versions);
$tab = (string)($_GET['tab'] ?? 'orc');
if (!in_array($tab, ['orc', 'res', 'hist', 'cmp'], true) || ($tab === 'res' && !$money)) $tab = 'orc';
$self = fn(array $q = []) => '/admin/orcamento.php?' . http_build_query(array_filter(array_merge(['id' => (int)$deal['id'], 'v' => (int)$v['num'], 'opt' => $opt === 'B' ? 'B' : null], $q, nav_from() ? ['de' => nav_from()] : []), fn($x) => $x !== null));
$back = nav_from();   // veio de outra página (ex.: a ficha do negócio): «Voltar» faz o caminho inverso
$listTab = budget_tab_of((string)$deal['phase']);

$title = $deal['code'] . ' · ' . $deal['name'];
$flowDeal = record_get($pdo, (int)$deal['id']) ?? [];   // o negócio completo (etapa, contacto) para enviar a proposta
render_header($title, 'orcamentacao', '', [$title]);
render_flashes();

$why = '';
if (!$canEdit) $why = 'Só leitura: a sua conta vê a Orçamentação mas não a edita.';
elseif ($sheet['closed_at']) $why = 'Negócio concluído: a folha e o Resultado ficaram fechados nesse dia.';
elseif ($accepted) $why = 'v' . (int)$v['num'] . ' foi aceite: fica como foi proposta. Para mudar, crie uma nova versão.';
elseif ((int)$v['id'] !== (int)$last['id']) $why = 'v' . (int)$v['num'] . ' é uma versão anterior: as mudanças fazem-se na v' . (int)$last['num'] . '.';
elseif (budget_version_sent($v)) $why = 'v' . (int)$v['num'] . ' já foi enviada ao cliente: fica como foi enviada. Para mudar, crie uma nova versão e envie de novo.';
$stageChip = match ($deal['phase']) {
    'lost' => '<span class="chip no">Perdido</span>',
    'done' => '<span class="chip ok">Concluído</span>',
    default => '<span class="chip' . ($deal['phase'] === 'production' ? ' ok' : '') . '">' . e((string)$deal['stage_name']) . '</span>',
};
?>
<div class="lead-page-top">
  <?php if ($back): ?>
    <a class="btn ghost sm" href="<?= e($back) ?>" data-back><?= icon('chev-left') ?> <?= e(nav_label($back)) ?></a>
  <?php else: ?>
    <a class="btn ghost sm" href="/admin/orcamentacao.php<?= $listTab !== 'orcamentacao' ? '?t=' . e($listTab) : '' ?>#s-<?= (int)$sheet['id'] ?>" data-back><?= icon('chev-left') ?> Voltar às folhas</a>
  <?php endif; ?>
  <?php if (record_reach($pdo, $deal) && !($back && str_starts_with($back, '/admin/negocio.php?id=' . (int)$deal['id']))): ?><a class="btn ghost sm" href="<?= e(nav_to(deal_url($deal))) ?>"><?= icon('negocios') ?> Abrir o negócio</a><?php endif; ?>
</div>

<div class="bs" data-sheet="<?= (int)$sheet['id'] ?>" data-version="<?= (int)$v['id'] ?>" data-opt="<?= $opt ?>" data-editable="<?= $editable ? '1' : '0' ?>" data-money="<?= $money ? '1' : '0' ?>">
<header class="bs-head">
  <div class="bs-who">
    <span class="id"><?= e($deal['code']) ?></span>
    <h1 class="keep"><?= e($deal['name']) ?></h1>
    <p class="bs-sub"><?= e((string)($deal['client_name'] ?: 'Sem cliente')) ?><?= $deal['brought_name'] ? ' · trazido por ' . e($deal['brought_name']) : '' ?> · <?= $stageChip ?><?= $deal['funnel_name'] ? ' <span class="muted small">' . e($deal['funnel_name']) . '</span>' : '' ?></p>
  </div>
  <div class="bs-vers" role="group" aria-label="Versões">
    <?php foreach ($versions as $x):
        $acc = (int)$sheet['accepted_version_id'] === (int)$x['id'];
        $tip = 'v' . (int)$x['num'] . ' · ' . date('d/m/Y', strtotime((string)$x['created_at'])) . ($x['author'] ? ' · ' . $x['author'] : '') . ($acc ? ' · aceite' : '')
             . ($money ? ' · ' . budget_eur((float)$x['price']) : ''); ?>
      <a class="vchip<?= $acc ? ' acc' : '' ?><?= (int)$x['id'] === (int)$v['id'] ? ' on' : '' ?>" href="<?= e($self(['v' => (int)$x['num'], 'tab' => $tab === 'cmp' ? 'orc' : $tab])) ?>" data-tip="<?= e($tip) ?>"<?= (int)$x['id'] === (int)$v['id'] ? ' aria-current="true"' : '' ?>>v<?= (int)$x['num'] ?><?= $acc ? ' · aceite' : '' ?></a>
    <?php endforeach; ?>
    <?php if ($canEdit && !$sheet['closed_at']): ?>
      <button type="button" class="vchip add" data-bver-new data-tip="Nova versão a partir da v<?= (int)$v['num'] ?> (a anterior fica guardada)"><?= icon('plus') ?> nova versão</button>
    <?php endif; ?>
    <?php if (count($versions) > 1): ?>
      <button type="button" class="vchip" data-bmdl="#mdl-bcmp" data-tip="Comparar duas versões lado a lado"><?= icon('arrows') ?> comparar</button>
    <?php endif; ?>
    <?php if ($canEdit && !$accepted && !$sheet['closed_at']): ?>
      <button type="button" class="btn sm" data-baccept data-tip="Passa a contar para o Resultado e para o valor do negócio no Pipeline"><?= icon('check') ?> Marcar v<?= (int)$v['num'] ?> como aceite</button>
    <?php endif; ?>
  </div>
</header>

<nav class="tabs bs-tabs" aria-label="Folha">
  <a href="<?= e($self()) ?>" class="<?= $tab === 'orc' ? 'on' : '' ?>">Orçamentação</a>
  <?php if ($money): ?><a href="<?= e($self(['tab' => 'res'])) ?>" class="<?= $tab === 'res' ? 'on' : '' ?>">Resultado</a><?php endif; ?>
  <a href="<?= e($self(['tab' => 'hist'])) ?>" class="<?= $tab === 'hist' ? 'on' : '' ?>">Histórico</a>
</nav>

<?php if ($tab === 'orc'): ?>
  <?php if ($why !== ''): ?><p class="bs-note"><?= icon('alert') ?> <?= e($why) ?></p><?php endif; ?>
  <?= $hasOpts ? budget_options_nav($v, $opt, $self, $money, $editable, $hasB) : '' ?>
  <div class="bs-grid">
    <div class="bs-col">
      <?= budget_lines_block($pdo, $lines, $calc, $money, $editable) ?>
      <?= budget_time_block($pdo, $v, $calc, $money, $editable) ?>
      <?= budget_proposal_block($v, $editable, $opt, $pdo, $deal) ?>
    </div>
    <div class="bs-col">
      <?= $money ? budget_calc_block($calc, $editable) : '' ?>
      <?= budget_price_block($calc, $money) ?>
      <div class="bs-acts">
        <button type="button" class="btn" data-bpdf data-tip="Proposta para o cliente: as opções lado a lado, fases e prazos, preço s/ e c/ IVA e condições. Fica na pasta do negócio no Drive."><?= icon('file') ?> Gerar proposta (PDF)</button>
        <?php if ($deal['phase'] === 'pipeline'): [$canSend, $whyNot] = flow_can_send($pdo, $flowDeal); ?>
          <?php if ($canSend): ?><button type="button" class="btn ghost" data-new="#mdl-psend"><?= icon('send') ?> Enviar ao cliente</button>
          <?php else: ?><span class="btn ghost off" aria-disabled="true"><?= icon('send') ?> Enviar ao cliente</span><small class="muted"><?= e($whyNot) ?></small><?php endif; ?>
        <?php endif; ?>
        <?php if ($canEdit): ?>
          <button type="button" class="btn ghost" data-bmdl="#mdl-btpl-save"><?= icon('checklist') ?> Guardar como modelo</button>
          <?php if (!$sheet['closed_at']): ?><button type="button" class="btn ghost" data-bmdl="#mdl-btpl-apply"><?= icon('download') ?> A partir de um modelo</button><?php endif; ?>
        <?php endif; ?>
      </div>
      <p class="muted small bs-saved" data-saved aria-live="polite"><?= $editable ? 'Cada mudança grava-se sozinha.' : '' ?></p>
    </div>
  </div>
<?php elseif ($tab === 'res'): ?>
  <?php $res = $sheet['closed_at'] && $sheet['result_json'] ? json_decode((string)$sheet['result_json'], true) : budget_result($pdo, $sheet, $deal); ?>
  <?= budget_result_html($res, $deal) ?>
<?php elseif ($tab === 'hist'): ?>
  <section class="lv-blk bh-blk"><h4>Histórico da folha <span class="h-sub">versões, linhas, margem, aceitação, propostas</span></h4><?= budget_history_html($pdo, (int)$sheet['id']) ?></section>
<?php else: ?>
  <?php
    $va = $vb = null;
    foreach ($versions as $x) { if ((int)$x['num'] === (int)($_GET['a'] ?? 0)) $va = $x; if ((int)$x['num'] === (int)($_GET['b'] ?? 0)) $vb = $x; }
    $va ??= $versions[0]; $vb ??= end($versions);
  ?>
  <p><a class="lnk" href="<?= e($self()) ?>"><?= icon('chev-left') ?> Voltar à folha</a></p>
  <?= budget_compare_html($pdo, $va, $vb, $money) ?>
<?php endif; ?>
</div>

<?= $editable ? budget_line_modal_html($pdo, $v, $money) : '' ?>
<?= budget_small_modals_html($pdo, $sheet, $v, $versions) ?>
<?= $deal['phase'] === 'pipeline' ? flow_send_modal_html($pdo, $flowDeal, $self()) : '' ?>
<script src="<?= asset_url('budget.js') ?>" defer></script>
<?php render_footer();
