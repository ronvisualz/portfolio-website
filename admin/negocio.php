<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/leads.php';
require_once __DIR__ . '/inc/pipeline.php';
require_once __DIR__ . '/inc/finance.php';
require_once __DIR__ . '/inc/deal_drive.php';
require_once __DIR__ . '/inc/undo.php';
require_once __DIR__ . '/inc/interactions.php';
require_once __DIR__ . '/inc/lead_view.php';   // ligações a contactos; «Trazido por» (inc/contacts.php)
require_once __DIR__ . '/inc/deal_file.php';   // a ficha central (§15): etapas, pulso, atenção, cartões, fecho
$user = require_login();
require_page('negocios');

// Ficha central do negócio (§15): um «cockpit» que lê, pelo ID, o que as outras
// abas sabem dele — Contactos, Orçamentação, Financeiro, Tarefas, Drive e (quando
// existir) Centro Criativo. A ficha não cria dados: «Ver detalhe» abre o painel
// lateral da aba de origem já filtrado para este negócio; o que se regista por
// negócio (contas e custos do Financeiro, pessoas ligadas, «Trazido por») fica nas
// vistas «Contas e custos» e «Dados e contactos». O que é dinheiro pede o
// Financeiro. Cliente, Trazido por e Criado por são coisas diferentes (§13.4).
// Fechar o custo de um fornecedor pede a avaliação do trabalho (§13.3).

$me = me_row($pdo);

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$deal = deal_get($pdo, $id);
$ok   = $deal && $deal['code'] !== null
     && ($deal['owner_id'] === null ? sees_unassigned($me) : in_my_scope($pdo, 'negocios', (int)$deal['owner_id']));
if (!$ok) {
    http_response_code(404);
    render_header('Negócio', 'negocios');
    echo '<h1>Negócio não encontrado</h1><p><a class="lnk" href="/admin/negocios.php">← Negócios</a></p>';
    render_footer();
    exit;
}

$file     = deal_file_row($pdo, $id);
$rate     = vat_rate($pdo);
$self     = '/admin/negocio.php?id=' . $id . nav_keep();   // leva o «de» (de onde se veio) em todas as ações e vistas
$canEdit  = can('negocios', PERM_EDIT);
$dealEdit = $canEdit && record_reach($pdo, $deal, PERM_EDIT);
$money    = can('financeiro', PERM_VIEW);
$moneyEdit= can('financeiro', PERM_EDIT);

// ── ações ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $finance = ['save_finance', 'add_cost', 'save_cost', 'toggle_cost', 'del_cost', 'rate_cost'];
    $rateAsk = 0;   // custo fechado de um fornecedor ainda por avaliar: pede as estrelas a seguir
    if (in_array($do, $finance, true)) require_perm('financeiro', PERM_EDIT);

    try {
        switch ($do) {
            case 'save_info':
                $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 160) ?: $deal['name'];
                $pdo->prepare('UPDATE deals SET name = ?, notes = ? WHERE id = ?')
                    ->execute([$name, mb_substr((string)($_POST['notes'] ?? ''), 0, 8000), $id]);
                flash('Negócio guardado.');
                break;

            // ── «Pode ser divulgado?» (§16.3): sem autorização, as peças de Marketing ligadas não passam a Agendado/Publicado ──
            case 'disclose':
                if (!$dealEdit) throw new RuntimeException('Sem permissão para mudar este negócio.');
                $val = (string)($_POST['disclose'] ?? '');
                $from = (string)($_POST['disclose_from'] ?? '');
                if (!in_array($val, ['', 'yes', 'no', 'from'], true)) throw new RuntimeException('Escolha uma opção.');
                if ($val === 'from' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) throw new RuntimeException('Indique a partir de que dia pode ser divulgado.');
                $was = ['id' => $id, 'disclose' => $deal['disclose'], 'disclose_from' => $deal['disclose_from']];
                $pdo->prepare('UPDATE deals SET disclose = ?, disclose_from = ? WHERE id = ?')->execute([$val !== '' ? $val : null, $val === 'from' ? $from : null, $id]);
                require_once __DIR__ . '/inc/marketing.php';
                $label = mkt_disclose_state($val ?: null, $val === 'from' ? $from : null)['label'];
                deal_log($pdo, $id, 'disclose', ['note' => $label]);
                flash($label . '.', 'ok', undo_push('deal_disclose', $was));
                break;

            // ── barra de etapas de produção (§15.3) ──
            case 'step':
                if (!$dealEdit) throw new RuntimeException('Sem permissão para mudar este negócio.');
                if (!in_array($deal['phase'], ['production', 'done'], true)) throw new RuntimeException('As etapas de produção começam no Kickoff.');
                $snap = undo_deal_snapshot($deal) + ['prod_step' => $deal['prod_step']];
                $msg = deal_step_set($pdo, $file, (string)($_POST['step'] ?? ''));
                $now = deal_get($pdo, $id);
                flash($msg, 'ok', undo_push('deal_step', $snap + ['to_stage' => (int)$now['stage_id'], 'to_step' => $now['prod_step']]));
                break;

            // ── concluir, com a checklist de fecho (§15.5) ──
            case 'close':
                if (!$dealEdit) throw new RuntimeException('Sem permissão para mudar este negócio.');
                if ($deal['phase'] !== 'production') throw new RuntimeException('Só um negócio em produção pode ser concluído.');
                $doneStage = funnel_fixed_stage($pdo, (int)$deal['funnel_id'], 'done');
                if (!$doneStage) throw new RuntimeException('Este funil não tem a etapa Concluído.');
                $snap = undo_deal_snapshot($deal);
                $flags = deal_flags_snapshot($deal);
                // deal_move tem a sua própria transação; as ações opcionais vêm depois, com o negócio já concluído.
                deal_move($pdo, $deal, $doneStage);
                if (!empty($_POST['delivered']) && !$deal['delivered_at']) deal_mark_delivered($pdo, $deal, true);
                if (!empty($_POST['portfolio']) && !$deal['portfolio_at']) { [$t, $i] = deal_send_portfolio($pdo, $file, (int)$me['id']); $flags['tasks'][] = $t; if ($i) $flags['ideas'][] = $i; }
                if (!empty($_POST['testimonial']) && !$deal['testimonial_at']) $flags['tasks'][] = deal_ask_testimonial($pdo, $file, (int)$me['id']);
                $check = deal_checklist($pdo, deal_get($pdo, $id));
                flash($deal['code'] . ' concluído.' . ($check['complete'] ? ' Fecho completo.' : ' Fica assinalado (conta como «Em risco») até fechar: ' . implode(', ', array_map(fn($i) => mb_strtolower($i['label']), $check['missing'])) . '.'),
                      'ok', undo_push('multi', ['subs' => [['deal_stage', $snap + ['to_stage' => $doneStage]], ['deal_flags', $flags]], 'msg' => 'Conclusão anulada.']));
                break;

            case 'delivered':
            case 'portfolio':
            case 'testimonial':
                if (!$dealEdit) throw new RuntimeException('Sem permissão para mudar este negócio.');
                $flags = deal_flags_snapshot($deal);
                if ($do === 'delivered') {
                    deal_mark_delivered($pdo, $deal, !$deal['delivered_at']);
                    $msg = $deal['delivered_at'] ? 'Entregas por confirmar.' : 'Entregas marcadas como feitas.';
                } elseif ($do === 'portfolio') {
                    [$t, $i] = deal_send_portfolio($pdo, $file, (int)$me['id']);
                    $flags['tasks'][] = $t;
                    if ($i) $flags['ideas'][] = $i;
                    $msg = 'Enviado para o portfólio: tarefa criada em Tarefas' . ($i ? ' e a ideia «Breakdown» em Marketing › Ideias.' : '.');
                } else {
                    $flags['tasks'][] = deal_ask_testimonial($pdo, $file, (int)$me['id']);
                    $msg = 'Testemunho pedido: tarefa de email criada.';
                }
                flash($msg, 'ok', undo_push('deal_flags', $flags));
                break;

            // ── nova tarefa ligada ao negócio (aparece em Tarefas) ──
            case 'task':
                if (!$dealEdit) throw new RuntimeException('Sem permissão para mudar este negócio.');
                $kind = (string)($_POST['kind'] ?? 'task');
                if (!isset(LEAD_TASK_KINDS[$kind])) throw new RuntimeException('Escolha o tipo de tarefa.');
                $date = (string)($_POST['date'] ?? '');
                $time = (string)($_POST['time'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Indique a data.');
                if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) throw new RuntimeException('Indique uma hora válida.');
                $who = (int)($_POST['assignee'] ?? 0) ?: (int)$me['id'];
                $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $st->execute([$who]);
                $target = $st->fetch();
                if (!$target || !can_assign_to($me, $target)) throw new RuntimeException('Não pode atribuir tarefas a essa pessoa.');
                $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 200));
                if ($title === '') $title = LEAD_TASK_KINDS[$kind][0] . ' · ' . $deal['name'];
                $tid = lead_task_create($pdo, $deal, $kind, $title, trim(mb_substr((string)($_POST['notes'] ?? ''), 0, 4000)),
                                        $date . ($time !== '' ? ' ' . $time : ''), null, $who, '', (int)$me['id']);
                flash('Tarefa criada (também em Tarefas).', 'ok', undo_push('deal_flags', ['tasks' => [$tid]] + deal_flags_snapshot($deal)));
                break;

            case 'save_finance':
                $v = deal_finance_input($_POST, $rate);
                $pdo->prepare(
                    'UPDATE deals SET job_type=?, year=?, month=?, budget=?, is_international=?, has_vat=?, is_paid=?,
                                      expenses_mode=?, expenses_manual=? WHERE id=?'
                )->execute([$v['job_type'], $v['year'], $v['month'], $v['budget'], $v['is_international'], $v['has_vat'],
                            $v['is_paid'], $v['expenses_mode'], $v['expenses_manual'], $id]);
                flash('Contas do negócio guardadas.');
                break;

            case 'add_cost':
            case 'save_cost':
                $c = cost_input($_POST);
                if ($c['name'] === '' && $c['role'] === '') throw new RuntimeException('Indique a pessoa ou a função.');
                $contact = (int)($_POST['contact_id'] ?? 0) ?: null;
                // A linha do orçamento a que o custo corresponde (para o Resultado, §14.5).
                $bk = (string)($_POST['budget_key'] ?? '');
                $bk = $bk === 'auto' ? budget_auto_key($pdo, $id, $contact) : (isset(budget_link_options($pdo, $id)[$bk]) ? $bk : null);
                if ($do === 'add_cost') {
                    $pdo->prepare('INSERT INTO costs (deal_id, year, month, name, role, contact_id, amount, vat_mode, is_closed, notes, created_by, budget_key)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$id, $deal['year'], $deal['month'], $c['name'], $c['role'], $contact, $c['amount'], $c['vat_mode'], $c['is_closed'], $c['notes'], (int)$me['id'], $bk]);
                    $costId = (int)$pdo->lastInsertId();
                    flash('Custo adicionado.');
                } else {
                    $costId = (int)($_POST['cost_id'] ?? 0);
                    $pdo->prepare('UPDATE costs SET name=?, role=?, contact_id=?, amount=?, vat_mode=?, is_closed=?, notes=?, budget_key=? WHERE id=? AND deal_id=?')
                        ->execute([$c['name'], $c['role'], $contact, $c['amount'], $c['vat_mode'], $c['is_closed'], $c['notes'], $bk, $costId, $id]);
                    flash('Custo guardado.');
                }
                if ($c['is_closed'] && cost_needs_rating($pdo, $costId)) $rateAsk = $costId;
                break;

            case 'toggle_cost':
                $st = $pdo->prepare('SELECT is_closed FROM costs WHERE id = ? AND deal_id = ?');
                $st->execute([(int)($_POST['cost_id'] ?? 0), $id]);
                $was = $st->fetchColumn();
                if ($was !== false) {
                    $pdo->prepare('UPDATE costs SET is_closed = 1 - is_closed WHERE id = ? AND deal_id = ?')->execute([(int)($_POST['cost_id'] ?? 0), $id]);
                    flash((int)$was ? 'Custo reaberto.' : 'Custo fechado.', 'ok', undo_push('cost_closed', ['id' => (int)$_POST['cost_id'], 'from' => (int)$was]));
                    if (!(int)$was && cost_needs_rating($pdo, (int)$_POST['cost_id'])) $rateAsk = (int)$_POST['cost_id'];
                }
                break;

            case 'rate_cost':
                $cost = cost_needs_rating($pdo, (int)($_POST['cost_id'] ?? 0));
                if (!$cost || (int)$cost['deal_id'] !== $id) throw new RuntimeException('Este trabalho já foi avaliado.');
                cost_rate($pdo, $cost, (int)($_POST['stars'] ?? 0), (int)$me['id']);
                [$avg, $nr] = contact_rating($pdo, (int)$cost['contact_id']);
                flash($cost['contact_name'] . ' avaliado com ' . (int)$_POST['stars'] . '★ · média ' . number_format((float)$avg, 1, ',', '') . ' (' . $nr . ').');
                break;

            case 'brought_by':
                if (!$canEdit || !record_reach($pdo, $deal, PERM_EDIT)) throw new RuntimeException('Sem permissão para mudar este negócio.');
                $to = contact_pick_resolve($pdo, $_POST['brought_by_id'] ?? '', (string)($_POST['brought_by'] ?? ''));
                $from = $deal['brought_by_id'] !== null ? (int)$deal['brought_by_id'] : null;
                if (deal_set_brought_by($pdo, $deal, $to, (int)$me['id'])) {
                    flash('«Trazido por» guardado.', 'ok', undo_push('brought_by', ['id' => $id, 'from' => $from, 'to' => $to]));
                }
                break;

            case 'del_cost':
                $pdo->prepare('DELETE FROM costs WHERE id = ? AND deal_id = ?')->execute([(int)($_POST['cost_id'] ?? 0), $id]);
                flash('Custo eliminado.');
                break;

            case 'link_contact':
                $cid = (int)($_POST['contact_id'] ?? 0);
                $role = mb_substr(trim((string)($_POST['role'] ?? '')), 0, 80);
                $st = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE id = ?');
                $st->execute([$cid]);
                if (!(int)$st->fetchColumn()) throw new RuntimeException('Escolha o contacto.');
                $pdo->prepare((db_is_sqlite($pdo) ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO deal_contacts (deal_id, contact_id, role) VALUES (?,?,?)')
                    ->execute([$id, $cid, $role]);
                if ($deal['contact_id'] === null) $pdo->prepare('UPDATE deals SET contact_id = ? WHERE id = ?')->execute([$cid, $id]);
                flash('Contacto ligado ao negócio.');
                break;

            case 'unlink_contact':
                $cid = (int)($_POST['contact_id'] ?? 0);
                $pdo->prepare('DELETE FROM deal_contacts WHERE deal_id = ? AND contact_id = ?')->execute([$id, $cid]);
                if ((int)$deal['contact_id'] === $cid) {
                    $pdo->prepare('UPDATE deals SET contact_id = (SELECT MIN(contact_id) FROM deal_contacts WHERE deal_id = ?) WHERE id = ?')->execute([$id, $id]);
                }
                flash('Contacto desligado.');
                break;

            case 'drive':
                require_perm('drive', PERM_VIEW);
                if (!r2_config()) redirect('/admin/drive.php');
                redirect(drive_page(drive_deal_folder($pdo, $deal)));
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
    }
    // Cada ação volta à vista de onde veio: contas e custos, dados e contactos, ou a ficha.
    $anchor = (string)($_POST['anchor'] ?? '');
    $back = in_array($do, $finance, true) || in_array($anchor, ['custos', 'custo', 'financeiro'], true) ? '&vista=contas'
          : (in_array($do, ['link_contact', 'unlink_contact', 'brought_by', 'save_info', 'disclose'], true) ? '&vista=dados' : '');
    redirect($self . $back . (!empty($rateAsk) ? '&avaliar=' . $rateAsk : '') . (preg_match('/^[a-z-]+$/', $anchor) ? '#' . $anchor : ''));
}

// ── dados ───────────────────────────────────────────────────
$vista = (string)($_GET['vista'] ?? 'ficha');
if (!in_array($vista, ['ficha', 'contas', 'dados'], true) || ($vista === 'contas' && !$money)) $vista = 'ficha';
$funnel   = funnel_get($pdo, (int)$deal['funnel_id']);
$stages   = $deal['funnel_id'] ? funnel_stages($pdo, (int)$deal['funnel_id']) : [];
$stage    = $deal['stage_id'] ? stage_get($pdo, (int)$deal['stage_id']) : null;
$owner    = $file['owner_name'];
$costs    = $money ? deal_costs($pdo, $id, $rate) : [];
$m        = $money ? deal_money($deal, $costs, $rate) : null;
$st = $pdo->prepare('SELECT c.*, dc.role AS link_role FROM deal_contacts dc JOIN contacts c ON c.id = dc.contact_id WHERE dc.deal_id = ? ORDER BY c.name');
$st->execute([$id]);
$contacts = $st->fetchAll();
$allContacts = $vista === 'dados' || $vista === 'contas' ? $pdo->query('SELECT id, name FROM contacts ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR) : [];

$rated = $costs ? $pdo->query('SELECT cost_id, stars FROM contact_ratings WHERE cost_id IN (' . implode(',', array_map(fn($c) => (int)$c['id'], $costs)) . ')')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$rateCost = $moneyEdit && !empty($_GET['avaliar']) ? cost_needs_rating($pdo, (int)$_GET['avaliar']) : null;
if ($rateCost && (int)$rateCost['deal_id'] !== $id) $rateCost = null;
if ($rateCost) $vista = 'contas';
$editCost = (int)($_GET['cost'] ?? 0);
$ec = null; foreach ($costs as $c) if ((int)$c['id'] === $editCost) $ec = $c;

$prodPhase = in_array($deal['phase'], ['production', 'done'], true);
$steps     = $prodPhase ? deal_steps($pdo, $file) : null;
$tasks     = deal_task_stats($pdo, $id);
$check     = deal_checklist($pdo, $deal);
$delivery  = $prodPhase ? deal_delivery_date($pdo, $deal) : null;
$fig       = $money ? deal_figures($pdo, $file) : null;
$attention = $vista === 'ficha' ? deal_attention($pdo, $file, $fig, $tasks, $check, $delivery) : [];
$listUrl   = $prodPhase ? '/admin/negocios.php' . ($deal['phase'] === 'done' ? '?t=concluidos' : '') : '/admin/pipeline.php' . ($deal['phase'] === 'lost' ? '?v=perdidos' : '');
$phaseChip = ['pipeline' => ['Fase comercial · ' . ($stage['name'] ?? '—'), ''], 'production' => ['Em produção', 'info'], 'done' => ['Concluído', 'ok'], 'lost' => ['Perdido', 'no']][$deal['phase']] ?? [$deal['phase'], ''];
$vatTxt    = (int)$deal['is_international'] ? 'internacional (sem IVA)' : ((int)$deal['has_vat'] ? 'com IVA' : 'sem IVA');
$tab       = fn(string $v) => $self . ($v === 'ficha' ? '' : '&vista=' . $v);

render_header($deal['code'] . ' · ' . $deal['name'], $prodPhase ? 'negocios' : 'pipeline', '', [$deal['code']]);
render_flashes();
?>
<div class="df" data-deal="<?= $id ?>">
<header class="df-top">
  <?php $back = nav_from(); ?>
  <a class="ibtn df-back" href="<?= e($back ?? $listUrl) ?>" aria-label="<?= e($back ? nav_label($back) : 'Voltar à lista (' . ($prodPhase ? 'Negócios' : 'Pipeline') . ')') ?>" data-tip data-back><?= icon('chev-left') ?></a>
  <div class="df-who">
    <div class="df-l1">
      <span class="df-id mono"><?= e($deal['code']) ?></span>
      <h1 class="keep"><?= inline_field('deal', $id, 'name', e($deal['name']), ['edit' => $dealEdit, 'label' => 'Editar nome', 'value' => $deal['name']]) ?></h1>
      <?php if ($deal['job_type']): ?><span class="chip info" data-tip="Tipo de trabalho" tabindex="0"><?= e($deal['job_type']) ?></span><?php endif; ?>
      <span class="chip <?= $phaseChip[1] ?>"><?= e($phaseChip[0]) ?></span>
      <?php if ($deal['phase'] === 'done' && !$check['complete']): ?><span class="chip risk" data-tip="<?= e('Falta: ' . implode(', ', array_map(fn($i) => mb_strtolower($i['label']), $check['missing']))) ?>" tabindex="0">Fecho incompleto</span><?php endif; ?>
    </div>
    <p class="df-sub">Cliente <b><?= e((string)($file['client_name'] ?: '—')) ?></b> · trazido por <?= e((string)($file['brought_name'] ?: '—')) ?>
      · responsável <?= e((string)($owner ?: 'por atribuir')) ?> · funil <?= e((string)($funnel['name'] ?? '—')) ?> · <?= e($vatTxt) ?></p>
    <?php if ($steps): ?>
      <form method="post" class="df-steps" aria-label="Etapas de produção">
        <?= csrf_field() ?><input type="hidden" name="do" value="step"><input type="hidden" name="id" value="<?= $id ?>">
        <?php foreach ($steps['items'] as $i => $s):
            $lbl = ['done' => 'feita', 'now' => 'atual', 'next' => 'por fazer'][$s['state']];
            if ($s['key'] === 'done'): ?>
              <?php if ($dealEdit && $deal['phase'] === 'production'): ?>
                <button type="button" class="dst <?= $s['state'] ?> fin" data-dmdl="#mdl-close" data-tip="Concluir: abre a checklist de fecho"><?= e($s['label']) ?></button>
              <?php else: ?>
                <span class="dst <?= $s['state'] ?> fin" data-tip="<?= e($s['label'] . ' · ' . $lbl) ?>" tabindex="0"><?= e($s['label']) ?></span>
              <?php endif; ?>
            <?php elseif ($dealEdit && $s['state'] !== 'now'): ?>
              <button class="dst <?= $s['state'] ?>" name="step" value="<?= e($s['key']) ?>" data-tip="<?= e(($deal['phase'] === 'done' ? 'Reabrir em ' : 'Passar a ') . $s['label']) ?>"><?= e($s['label']) ?></button>
            <?php else: ?>
              <span class="dst <?= $s['state'] ?>" data-tip="<?= e($s['label'] . ' · ' . $lbl) ?>" tabindex="0"<?= $s['state'] === 'now' ? ' aria-current="step"' : '' ?>><?= e($s['label']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
      </form>
      <?php if ($steps['mode'] === 'step'): ?><p class="df-hint">Etapas de produção da casa; quando o funil tiver etapas de produção próprias, a barra passa a usá-las.</p><?php endif; ?>
    <?php elseif ($deal['phase'] === 'pipeline'): ?>
      <p class="df-hint">Na fase comercial (<?= e($stage['name'] ?? '—') ?>). As etapas de produção começam no Kickoff.</p>
    <?php endif; ?>
  </div>
  <div class="df-acts">
    <?php if (can('drive')): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="drive"><input type="hidden" name="id" value="<?= $id ?>">
        <?= icon_button('drive', 'Abrir a pasta no Drive', ['type' => 'submit']) ?></form>
    <?php endif; ?>
    <?php if (can('orcamentacao')): ?><?= icon_button('orcamentacao', 'Abrir o orçamento', ['href' => nav_to('/admin/orcamento.php?id=' . $id)]) ?><?php endif; ?>
    <?php if ($dealEdit): ?><?= icon_button('tarefas', 'Nova tarefa', ['data-dmdl' => '#mdl-dtask']) ?><?php endif; ?>
    <?php if (can('drive')): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="drive"><input type="hidden" name="id" value="<?= $id ?>">
        <?= icon_button('upload', 'Adicionar documento (abre a pasta do negócio no Drive)', ['type' => 'submit']) ?></form>
    <?php endif; ?>
    <details class="dmore">
      <summary class="ibtn" aria-label="Mais ações" data-tip><?= icon('dots') ?></summary>
      <div class="dmore-m" role="menu">
        <?php if ($money): ?><a role="menuitem" href="<?= e($tab('contas')) ?>"><?= icon('euro') ?> Contas e custos</a><?php endif; ?>
        <a role="menuitem" href="<?= e($tab('dados')) ?>"><?= icon('contactos') ?> Dados e contactos</a>
        <a role="menuitem" href="<?= e($self) ?>#c-hist" data-panel="dhist:<?= $id ?>"><?= icon('clock') ?> Histórico completo</a>
        <?php if ($money): ?><a role="menuitem" href="<?= e(nav_to('/admin/documento.php?id=' . $id)) ?>"><?= icon('file') ?> Resumo de cobrança</a><?php endif; ?>
        <?php if (!$prodPhase && can('pipeline')): ?><a role="menuitem" href="<?= e(nav_to('/admin/pipeline.php?' . http_build_query(['f' => $deal['funnel_id'], 'deal' => $id]) . '#negocio')) ?>"><?= icon('pipeline') ?> Ver no Pipeline</a><?php endif; ?>
      </div>
    </details>
  </div>
</header>

<nav class="tabs df-tabs" aria-label="Ficha">
  <a href="<?= e($tab('ficha')) ?>" class="<?= $vista === 'ficha' ? 'on' : '' ?>">Ficha</a>
  <?php if ($money): ?><a href="<?= e($tab('contas')) ?>" class="<?= $vista === 'contas' ? 'on' : '' ?>">Contas e custos</a><?php endif; ?>
  <a href="<?= e($tab('dados')) ?>" class="<?= $vista === 'dados' ? 'on' : '' ?>">Dados e contactos</a>
</nav>

<?php if ($vista === 'ficha'):
    // ── pulso (§15.3) ──
    if ($fig) {
        $mCls = $fig['margin'] === null ? '' : ($fig['margin'] >= $fig['target'] ? 'good' : ($fig['margin'] >= $fig['target'] * 0.75 ? 'warn' : 'bad'));
        $pulse = [
            ['Valor s/ IVA', budget_eur($fig['value']), $fig['value_from'] === 'orcamento' ? 'do orçamento · ainda sem valor no Financeiro' : ($fig['international'] ? 'sem IVA (internacional)' : ($fig['has_vat'] ? 'c/ IVA ' . budget_eur((float)$fig['m']['budget_gross']) : 'sem IVA')), ''],
            ['Custos reais', budget_eur($fig['expenses']), $fig['costs'] ? count($fig['costs']) . (count($fig['costs']) === 1 ? ' linha' : ' linhas') . ' · ' . ($fig['open_costs'] ? $fig['open_costs'] . ' por pagar' : 'todas pagas') : ($deal['expenses_mode'] === 'manual' ? 'valor manual' : 'sem custos registados'), $fig['open_costs'] ? 'warn' : ''],
            ['Margem real', budget_pct($fig['margin']), 'meta da casa ' . budget_pct($fig['target']), $mCls],
            ['Recebido', $fig['received'] . '%', $fig['received'] ? budget_eur((float)$fig['m']['budget_gross']) . ' pagos' : ($fig['value'] > 0 ? 'por receber ' . budget_eur((float)$fig['m']['budget_gross'] ?: $fig['value']) : 'sem valor'), $fig['received'] ? 'good' : ($deal['phase'] === 'done' ? 'warn' : '')],
            ['Lucro', budget_eur($fig['profit']), $fig['profit_month'] !== null ? 'lucro/mês ' . budget_eur($fig['profit_month']) . ' (' . $fig['days'] . ' dias úteis)' : 'lucro/mês: depende do prazo', $fig['profit'] < 0 ? 'bad' : ''],
        ];
    } else {
        $pulse = [
            ['Etapa', $steps ? $steps['now'] : ($stage['name'] ?? '—'), $phaseChip[0], ''],
            ['Tarefas abertas', (string)count($tasks['open']), $tasks['late'] ? $tasks['late'] . ' atrasadas' : $tasks['done'] . ' feitas', $tasks['late'] ? 'bad' : ''],
            ['Entrega prevista', $delivery ? date('d/m/Y', strtotime($delivery)) : '—', $deal['delivered_at'] ? 'entregue' : ($delivery ? 'pela folha de orçamento' : 'sem prazo na plataforma'), ''],
            ['Fecho', count(array_filter($check['items'], fn($i) => $i['ok'])) . '/' . count($check['items']), $check['complete'] ? 'completo' : 'itens em falta', $check['complete'] ? 'good' : ''],
        ];
    }
?>
<div class="df-pulse<?= $fig ? '' : ' four' ?>" role="list" aria-label="Pulso do negócio">
  <?php foreach ($pulse as [$k, $v, $n, $cls]): ?>
    <div class="pl <?= $cls ?>" role="listitem"><div class="k"><?= e($k) ?></div><div class="v"><?= e($v) ?></div><div class="n"><?= e($n) ?></div></div>
  <?php endforeach; ?>
</div>

<?php if ($attention): ?>
<div class="df-att" role="list" aria-label="Precisa de atenção">
  <?php foreach ($attention as [$t, $cls, $tip]): ?><span class="att <?= $cls ?>" role="listitem" data-tip="<?= e($tip) ?>" tabindex="0"><?= $cls === 'g' ? icon('check') : ($cls === 'r' ? icon('alert') : '') ?><?= e($t) ?></span><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="dcards">
<?php
// ── Cliente e contactos ──
$others = 0;
if ($file['client_id']) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM deals WHERE id <> ? AND code IS NOT NULL AND (contact_id = ? OR company_id = ?)');
    $st->execute([$id, $file['client_id'], $file['client_id']]);
    $others = (int)$st->fetchColumn();
}
echo deal_card('contacts', 'contactos', 'sky', 'Cliente e contactos', deal_kv([
        ['Cliente', deal_people_link($pdo, $deal['contact_id'])],
        ['Pessoas', $contacts ? e(implode(', ', array_slice(array_map(fn($c) => $c['name'], $contacts), 0, 3)) . (count($contacts) > 3 ? ' +' . (count($contacts) - 3) : '')) : '<span class="muted">—</span>'],
        ['Trazido por', deal_people_link($pdo, $deal['brought_by_id'])],
        ['Outros negócios do cliente', '<span class="mono">' . $others . '</span>'],
    ]), ['panel' => can('contactos') ? 'dcontacts:' . $id : '', 'href' => $tab('dados'), 'more' => 'Ver detalhe']);

// ── Orçamento ──
if (can('orcamentacao')) {
    $bSheet = budget_sheet_ensure($pdo, $deal, (int)$me['id']);
    $bCur = budget_current_version($pdo, $bSheet);
    $bLines = budget_lines($pdo, (int)$bCur['id']);
    $bCalc = $bLines ? budget_calc($pdo, $bCur, $bLines) : null;
    $bAcc = $bSheet['accepted_version_id'] ? budget_version_get($pdo, (int)$bSheet['accepted_version_id']) : null;
    echo deal_card('budget', 'orcamentacao', 'yel', 'Orçamento', $bCalc
        ? deal_kv([
            ['Versão aceite', $bAcc ? 'v' . (int)$bAcc['num'] : '<span class="muted">ainda nenhuma</span>'],
            ['Preço orçado', '<span class="mono">' . e(budget_eur($bCalc['price'])) . '</span>'],
            $money ? ['Margem orçada', '<span class="mono">' . e(budget_pct($bCalc['margin'])) . '</span>'] : null,
            ['Prazo orçado', '<span class="mono">' . e(budget_n($bCalc['days'], 1)) . ' dias úteis</span>'],
          ])
        : '<p class="dc-empty">' . icon('orcamentacao') . ' Sem orçamento na plataforma' . ((float)$deal['budget'] > 0 && $money ? ': o valor (' . e(budget_eur((float)$deal['budget'])) . ') e os custos vêm do Financeiro.' : '.') . '</p>',
        ['panel' => 'dbudget:' . $id, 'href' => nav_to('/admin/orcamento.php?id=' . $id), 'more' => 'Abrir orçamento', 'count' => 'v' . (int)$bCur['num']]);
} else {
    echo deal_card('budget', 'orcamentacao', 'yel', 'Orçamento', '<p class="dc-empty">Sem acesso à Orçamentação com esta conta.</p>', ['empty' => true]);
}

// ── Resultado ──
if ($fig) {
    $gmCls = $fig['margin'] === null ? '' : ($fig['margin'] >= $fig['target'] ? 'pos' : 'neg');
    $costPct = $fig['value'] > 0 ? max(0, min(100, round($fig['expenses'] / $fig['value'] * 100, 1))) : 0;
    echo deal_card('result', 'trend', 'blue', 'Resultado', deal_kv([
            ['Preço', '<span class="mono">' . e(budget_eur($fig['value'])) . '</span>'],
            ['Custos', '<span class="mono">' . e(budget_eur($fig['expenses'])) . '</span>'],
            ['Gross profit', '<span class="mono">' . e(budget_eur($fig['profit'])) . '</span>'],
            ['Gross margin', '<span class="mono ' . $gmCls . '">' . e(budget_pct($fig['margin'])) . '</span>'],
        ]) . ($fig['value'] > 0 ? '<div class="dbar" role="img" aria-label="Custos ' . $costPct . '% · lucro ' . (100 - $costPct) . '% do preço" data-tip="Custos ' . $costPct . '% · lucro ' . (100 - $costPct) . '% do preço" tabindex="0"><i class="c" style="width:' . $costPct . '%"></i><i class="p" style="width:' . (100 - $costPct) . '%"></i></div>' : '')
        . (!$fig['has_budget'] ? '<p class="dc-note">Sem orçamento na plataforma: valor e custos existentes.</p>' : ''),
        ['panel' => 'dresult:' . $id, 'href' => $fig['has_budget'] ? nav_to('/admin/orcamento.php?id=' . $id . '&tab=res') : $tab('contas'), 'more' => 'Ver orçado vs real']);
} else {
    echo deal_card('result', 'trend', 'blue', 'Resultado', '<p class="dc-empty">' . icon('eye') . ' Preço, custos, gross profit e margem: só com acesso ao Financeiro.</p>', ['empty' => true]);
}

// ── Equipa e fornecedores ──
if ($money) {
    $rows = '';
    foreach (array_slice($fig['costs'], 0, 5) as $c) {
        $rows .= '<tr><td>' . e($c['contact_name'] ?: ($c['name'] ?: '—')) . '</td><td class="muted">' . e($c['role'] ?: '—') . '</td><td class="num mono">' . e(budget_eur((float)$c['net'])) . '</td>'
               . '<td>' . ((int)$c['is_closed'] ? '<span class="pos b">pago</span>' : '<span class="neg b">por pagar</span>') . '</td></tr>';
    }
    $body = $rows ? '<table class="dtbl"><tbody>' . $rows . '</tbody></table>'
                  . (count($fig['costs']) > 5 ? '<p class="dc-note">+ ' . (count($fig['costs']) - 5) . ' linhas</p>' : '')
                  . ($fig['vat_in'] > 0 ? '<p class="dc-note">Acresce IVA suportado: ' . e(budget_eur($fig['vat_in'], 2)) . ' (a deduzir no Financeiro).</p>' : '')
                  : '<p class="dc-empty">Sem custos registados' . ($deal['expenses_mode'] === 'manual' ? ' (gastos em valor manual: ' . e(budget_eur((float)$deal['expenses_manual'])) . ')' : '') . '.</p>';
    echo deal_card('team', 'equipa', 'or', 'Equipa e fornecedores', $body,
                   ['wide' => true, 'count' => count($fig['costs']), 'panel' => 'dcosts:' . $id, 'href' => $tab('contas') . '#custos', 'more' => 'Ver todos os custos']);
} else {
    $st = $pdo->prepare('SELECT COALESCE(ct.name, c.name) AS who, c.role FROM costs c LEFT JOIN contacts ct ON ct.id = c.contact_id WHERE c.deal_id = ? ORDER BY c.id');
    $st->execute([$id]);
    $ppl = $st->fetchAll();
    $li = '';
    foreach (array_slice($ppl, 0, 6) as $p) $li .= '<li><b>' . e($p['who'] ?: '—') . '</b> <span class="muted">· ' . e($p['role'] ?: '—') . '</span></li>';
    echo deal_card('team', 'equipa', 'or', 'Equipa e fornecedores', ($li ? '<ul class="dlist">' . $li . '</ul>' : '<p class="dc-empty">Ainda sem equipa registada.</p>') . '<p class="dc-note">Valores, IVA e pagamentos: só com acesso ao Financeiro.</p>',
                   ['wide' => true, 'count' => count($ppl)]);
}

// ── Produção e tarefas ──
$realVsBudget = null;
if ($fig && $fig['days'] && $fig['calc'] && $deal['phase'] === 'done') $realVsBudget = $fig['days'] . ' d / ' . budget_n($fig['calc']['days'], 1) . ' d';
echo deal_card('tasks', 'tarefas', 'green', 'Produção e tarefas', deal_kv([
        ['Etapa', e($steps ? $steps['now'] : ($stage['name'] ?? '—'))],
        ['Tarefas', '<span class="mono">' . count($tasks['open']) . ' abertas · ' . $tasks['done'] . ' feitas</span>' . ($tasks['late'] ? ' <span class="neg b">· ' . $tasks['late'] . ' atrasadas</span>' : '')],
        ['Entregas', $deal['delivered_at'] ? '<span class="pos b">feitas</span> <span class="muted mono">' . e(date('d/m/Y', strtotime((string)$deal['delivered_at']))) . '</span>' : ($delivery ? 'previstas a <span class="mono">' . e(date('d/m/Y', strtotime($delivery))) . '</span>' : '<span class="muted">—</span>')],
        ['Prazo real vs orçado', $realVsBudget ? '<span class="mono">' . e($realVsBudget) . '</span>' : '<span class="muted">—</span>'],
    ]), ['panel' => can('tarefas') ? 'dtasks:' . $id : '', 'href' => nav_to('/admin/calendar.php'), 'more' => 'Ver tarefas']);

// ── Ficheiros e Documentos (Drive) ──
$drive = deal_drive_summary($pdo, $deal);
if (!$drive) {
    echo deal_card('files', 'drive', 'ne', 'Ficheiros', '<p class="dc-empty">Sem acesso ao Drive com esta conta.</p>', ['empty' => true]);
    echo deal_card('docs', 'file', 'ne', 'Documentos', '<p class="dc-empty">Sem acesso ao Drive com esta conta.</p>', ['empty' => true]);
} elseif (!empty($drive['off']) || !empty($drive['error'])) {
    $msg = !empty($drive['off']) ? 'O Drive não está ligado neste servidor.' : 'Não foi possível ler o Drive agora.';
    echo deal_card('files', 'drive', 'ne', 'Ficheiros', '<p class="dc-empty">' . $msg . '</p><p class="dc-note mono">Projetos/' . e($deal['code']) . '_…/</p>', ['empty' => true]);
    echo deal_card('docs', 'file', 'ne', 'Documentos', '<p class="dc-empty">' . $msg . '</p>', ['empty' => true]);
} else {
    $types = array_slice($drive['by_type'], 0, 3, true);
    $kv = [['Pasta', '<span class="mono small">' . e($drive['folder'] ? basename(rtrim($drive['folder'], '/')) : 'ainda não criada') . '</span>']];
    foreach ($types as $k => $n) $kv[] = [$k, '<span class="mono">' . $n . '</span>'];
    $kv[] = ['Último', $drive['last'] ? e(shorten(basename($drive['last']['key']), 28)) : '<span class="muted">—</span>'];
    echo deal_card('files', 'drive', 'ne', 'Ficheiros', deal_kv($kv), ['count' => count($drive['files']), 'panel' => 'dfiles:' . $id, 'href' => $drive['folder'] ? drive_page($drive['folder']) : '/admin/drive.php', 'more' => 'Abrir no Drive']);
    $props = can('orcamentacao') && ($bs = budget_sheet_get($pdo, $id)) ? count(array_filter(budget_history($pdo, (int)$bs['id']), fn($h) => $h['event'] === 'b_pdf')) : 0;
    $n = fn(int $x, string $one, string $many) => $x ? $x . ' ' . ($x === 1 ? $one : $many) : '<span class="muted">—</span>';
    echo deal_card('docs', 'file', 'ne', 'Documentos', deal_kv([
            ['Contratuais', $n(count($drive['docs']['contract']), 'documento', 'documentos') . ($props ? ' <span class="muted">· ' . $props . ($props === 1 ? ' proposta gerada' : ' propostas geradas') . '</span>' : '')],
            ['Fiscais', $n(count($drive['docs']['fiscal']), 'fatura emitida', 'faturas emitidas')],
            ['Fornecedores', $n(count($drive['docs']['supplier']), 'fatura recebida', 'faturas recebidas')],
        ]), ['panel' => 'ddocs:' . $id, 'href' => $drive['folder'] ? drive_page($drive['folder']) : '/admin/drive.php', 'more' => 'Ver documentos']);
}

// ── Criativos (Centro Criativo, ainda por desenhar) ──
echo deal_card('creative', 'criativo', 'yel', 'Criativos', deal_kv([['Versões', '<span class="muted">—</span>'], ['Aprovação do cliente', '<span class="muted">—</span>']])
     . '<p class="dc-note">Vem do Centro Criativo quando a aba existir: versões e aprovações do cliente, por este ID.</p>', ['empty' => true]);

// ── Histórico (todas as fontes) ──
$tl = deal_timeline_all($pdo, $deal, $money, 6);
echo deal_card('hist', 'clock', 'ne', 'Histórico', '<div class="dtl">' . timeline_html($tl) . '</div>',
               ['wide' => true, 'count' => 'todas as fontes', 'panel' => 'dhist:' . $id, 'href' => $self . '#c-hist', 'more' => 'Ver histórico completo']);

// ── Fecho do negócio ──
$closeActs = '';
if ($dealEdit && $deal['phase'] === 'production') {
    $closeActs = '<button type="button" class="btn sm" data-dmdl="#mdl-close">' . icon('check') . ' Concluir…</button>';
} elseif ($dealEdit && $deal['phase'] === 'done') {
    $f = fn(string $do, string $ico, string $label, bool $off = false) => '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="id" value="' . $id . '">'
        . '<button class="btn ghost sm" type="submit"' . ($off ? ' disabled' : '') . '>' . icon($ico) . ' ' . e($label) . '</button></form>';
    $closeActs = (!$deal['delivered_at'] ? $f('delivered', 'check', 'Entregas feitas') : '')
               . $f('portfolio', 'star', $deal['portfolio_at'] ? 'Enviado para o portfólio' : 'Enviar para o portfólio', (bool)$deal['portfolio_at'])
               . $f('testimonial', 'bubble', $deal['testimonial_at'] ? 'Testemunho pedido' : 'Pedir testemunho', (bool)$deal['testimonial_at']);
}
echo deal_card('close', 'won', 'green', 'Fecho do negócio', deal_checklist_html($check)
     . ($closeActs !== '' ? '<div class="dc-acts">' . $closeActs . '</div>' : '')
     . ($deal['phase'] !== 'done' && $deal['phase'] !== 'production' ? '<p class="dc-note">A checklist aparece ao passar a Concluído.</p>' : '')
     . '<p class="dc-note">' . icon('marketing') . ' ' . e(deal_disclose_label($deal)) . ' · <a href="' . e($tab('dados')) . '#info">mudar</a></p>',
     ['count' => count(array_filter($check['items'], fn($i) => $i['ok'])) . '/' . count($check['items']), 'panel' => 'dclose:' . $id, 'href' => $self . '#c-close', 'more' => 'Ver checklist']);
?>
</div>
<?php if ($fig): ?><p class="df-foot muted small">Margem real = (valor − custos) ÷ valor, sem IVA; a meta é a margem por defeito da Orçamentação. O lucro por mês precisa do prazo: o real (Kickoff → conclusão) ou o da folha de orçamento.</p><?php endif; ?>

<?php elseif ($vista === 'contas'): ?>
<?php if ($money): ?>
<h2 id="financeiro">Contas</h2>
<?php if ($moneyEdit): ?>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="save_finance"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="anchor" value="financeiro">
  <div class="grid">
    <div class="field"><label for="ft">Tipo</label><select id="ft" name="job_type"><option value="">—</option>
      <?php foreach (DEAL_JOB_TYPES as $t): ?><option <?= $deal['job_type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="fm">Mês</label><select id="fm" name="month"><option value="">—</option>
      <?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>" <?= (int)$deal['month'] === $i ? 'selected' : '' ?>><?= e(month_name($i)) ?></option><?php endfor; ?></select></div>
    <div class="field"><label for="fy">Ano</label><input type="number" id="fy" name="year" min="2000" max="2100" value="<?= e((string)($deal['year'] ?? date('Y'))) ?>"></div>
    <div class="field"><label for="fb">Budget (€)</label><input type="text" id="fb" name="budget" inputmode="decimal" value="<?= e(number_format((float)$deal['budget'], 2, ',', '')) ?>"></div>
    <div class="field wide">
      <label class="check"><input type="checkbox" name="budget_incl_vat" value="1"> O valor escrito já inclui IVA (guarda-se sem IVA)</label>
      <label class="check"><input type="checkbox" name="has_vat" value="1" <?= (int)$deal['has_vat'] ? 'checked' : '' ?>> Fatura com IVA</label>
      <label class="check"><input type="checkbox" name="is_international" value="1" <?= (int)$deal['is_international'] ? 'checked' : '' ?>> Internacional</label>
      <label class="check"><input type="checkbox" name="is_paid" value="1" <?= (int)$deal['is_paid'] ? 'checked' : '' ?>> Pago</label>
    </div>
    <div class="field"><label for="fem">Gastos</label><select id="fem" name="expenses_mode">
      <option value="crew" <?= $deal['expenses_mode'] === 'crew' ? 'selected' : '' ?>>Soma dos custos</option>
      <option value="manual" <?= $deal['expenses_mode'] === 'manual' ? 'selected' : '' ?>>Valor manual</option></select></div>
    <div class="field"><label for="fmv">Gastos manuais (€)</label><input type="text" id="fmv" name="expenses_manual" inputmode="decimal" value="<?= e(number_format((float)$deal['expenses_manual'], 2, ',', '')) ?>"></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar contas</button></div>
</form>
<?php endif; ?>

<?php $bLinks = budget_link_options($pdo, $id); ?>
<h2 id="custos">Custos do negócio</h2>
<div class="tablewrap"><table>
  <thead><tr><th>Pessoa</th><th>Função</th><th class="num">Valor</th><th>O valor é</th><th class="num hide-sm">s/ IVA</th><th class="num hide-sm">IVA</th><th>Estado</th><th></th></tr></thead>
  <tbody>
  <?php if (!$costs): ?><tr><td colspan="8" class="empty">Sem custos.</td></tr><?php endif; ?>
  <?php foreach ($costs as $c): ?>
    <tr id="cost-<?= (int)$c['id'] ?>" class="<?= ($ec && (int)$ec['id'] === (int)$c['id']) || (int)($_GET['delcost'] ?? 0) === (int)$c['id'] ? 'pending' : '' ?>">
      <td><?= e($c['name'] ?: '—') ?><?= $c['contact_name'] ? '<div class="muted" style="font-size:0.62rem">' . e($c['contact_name']) . (isset($rated[(int)$c['id']]) ? ' · <span class="stars" aria-label="Avaliado ' . (int)$rated[(int)$c['id']] . ' de 5">' . str_repeat('★', (int)$rated[(int)$c['id']]) . '</span>' : '') . '</div>' : '' ?></td>
      <td><?= e($c['role'] ?: '—') ?><?= $c['budget_key'] && isset($bLinks[$c['budget_key']]) ? '<div class="muted" style="font-size:0.62rem" data-tip="Linha do orçamento (para o Resultado)" tabindex="0">↳ ' . e(shorten($bLinks[$c['budget_key']], 48)) . '</div>' : '' ?></td>
      <td class="num"><?= eur((float)$c['amount']) ?></td>
      <td><?= e(VAT_MODES[$c['vat_mode']] ?? $c['vat_mode']) ?></td>
      <td class="num hide-sm"><?= eur($c['net']) ?></td>
      <td class="num hide-sm"><?= eur($c['vat']) ?></td>
      <td><?php if ($moneyEdit): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_cost"><input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="cost_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="anchor" value="custos">
          <label class="check"><input type="checkbox" onchange="this.form.submit()" <?= (int)$c['is_closed'] ? 'checked' : '' ?>> Fechado</label></form>
      <?php else: ?><?= (int)$c['is_closed'] ? 'Fechado' : 'Aberto' ?><?php endif; ?></td>
      <td class="num nowrap"><?php if ($moneyEdit): ?>
        <?php if ((int)($_GET['delcost'] ?? 0) === (int)$c['id']): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="del_cost"><input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="cost_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="anchor" value="custos">
            <button class="btn danger sm" type="submit">Sim, eliminar</button></form>
          <a class="lnk" href="<?= e($self) ?>#custos">Cancelar</a>
        <?php else: ?>
          <a class="lnk" href="<?= e($self . '&cost=' . $c['id']) ?>#custo">Editar</a>
          <a class="lnk del" href="<?= e($self . '&delcost=' . $c['id']) ?>#custos">Eliminar</a>
        <?php endif; ?>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>

<?php if ($moneyEdit): $cv = $ec ?? ['name' => '', 'role' => '', 'amount' => '', 'vat_mode' => 'plus', 'is_closed' => 0, 'notes' => '', 'contact_id' => null]; ?>
<h2 id="custo"><?= $ec ? 'Editar custo' : 'Adicionar custo' ?></h2>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="<?= $ec ? 'save_cost' : 'add_cost' ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="anchor" value="custos">
  <?php if ($ec): ?><input type="hidden" name="cost_id" value="<?= (int)$ec['id'] ?>"><?php endif; ?>
  <div class="grid">
    <div class="field"><label for="cn">Pessoa</label><input type="text" id="cn" name="name" maxlength="160" value="<?= e($cv['name']) ?>"></div>
    <div class="field"><label for="cr">Função</label><input type="text" id="cr" name="role" maxlength="120" value="<?= e($cv['role']) ?>" placeholder="Diretor de Fotografia"></div>
    <div class="field"><label for="ca">Valor (€)</label><input type="text" id="ca" name="amount" inputmode="decimal" required value="<?= $cv['amount'] !== '' ? e(number_format((float)$cv['amount'], 2, ',', '')) : '' ?>"></div>
    <div class="field"><label for="cvm">O valor é</label><select id="cvm" name="vat_mode">
      <?php foreach (VAT_MODES as $k => $l): ?><option value="<?= $k ?>" <?= $cv['vat_mode'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="cc">Fornecedor (contacto)</label><select id="cc" name="contact_id"><option value="">—</option>
      <?php foreach ($allContacts as $cid => $cn): ?><option value="<?= (int)$cid ?>" <?= (string)$cv['contact_id'] === (string)$cid ? 'selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="cbk">Linha do orçamento</label><select id="cbk" name="budget_key">
      <?php if (!$ec): ?><option value="auto" selected>Automática (pelo fornecedor)</option><?php endif; ?>
      <option value="" <?= $ec && !$ec['budget_key'] ? 'selected' : '' ?>>— Fora do orçamento —</option>
      <?php foreach ($bLinks as $k => $l): ?><option value="<?= e($k) ?>" <?= $ec && $ec['budget_key'] === $k ? 'selected' : '' ?>><?= e(shorten($l, 70)) ?></option><?php endforeach; ?></select>
      <span class="muted" style="font-size:12px">Compara o real com o orçado no Resultado.</span></div>
    <div class="field"><label class="check" style="margin-top:1.6rem"><input type="checkbox" name="is_closed" value="1" <?= (int)$cv['is_closed'] ? 'checked' : '' ?>> Fechado</label></div>
    <div class="field wide"><label for="cno">Notas</label><input type="text" id="cno" name="notes" maxlength="2000" value="<?= e((string)$cv['notes']) ?>"></div>
  </div>
  <div class="actions"><button class="btn" type="submit"><?= $ec ? 'Guardar' : 'Adicionar' ?></button>
    <?php if ($ec): ?><a class="btn ghost" href="<?= e($self) ?>#custos">Cancelar</a><?php endif; ?></div>
</form>
<?php endif; ?>
<?php endif; ?>
<?php else: ?>
<h2 id="info">Informação</h2>
<?php $dealEdit = $canEdit && record_reach($pdo, $deal, PERM_EDIT);
      $creator = $deal['created_by'] ? (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$deal['created_by'])->fetchColumn() : ''; ?>
<div class="panel deal-who">
  <div class="kv">
    <span>Cliente</span><div><?= deal_people_link($pdo, $deal['contact_id']) ?> <span class="muted small">· quem paga</span></div>
    <span>Trazido por</span><div>
      <?php if ($dealEdit): ?>
        <form method="post" class="bb-form"><?= csrf_field() ?><input type="hidden" name="do" value="brought_by"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="anchor" value="info">
          <?= contact_pick_html($pdo, 'brought_by', 'brought_by_id', $deal['brought_by_id'] !== null ? (int)$deal['brought_by_id'] : null, 'dbb', 'Ninguém (quem originou: agência, parceiro…)') ?>
          <button class="btn ghost sm" type="submit">Guardar</button></form>
      <?php else: ?><?= deal_people_link($pdo, $deal['brought_by_id']) ?><?php endif; ?>
      <span class="muted small">· quem originou o negócio</span></div>
    <span>Pode ser divulgado?</span><div>
      <?php require_once __DIR__ . '/inc/marketing.php'; $dsc = mkt_disclose_state($deal['disclose'] ?? null, $deal['disclose_from'] ?? null); ?>
      <?php if ($dealEdit): ?>
        <form method="post" class="bb-form disc-form"><?= csrf_field() ?><input type="hidden" name="do" value="disclose"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="anchor" value="info">
          <label class="sr" for="disc">Pode ser divulgado?</label>
          <select id="disc" name="disclose">
            <option value="" <?= $dsc['key'] === 'unset' ? 'selected' : '' ?>>Por decidir</option>
            <option value="yes" <?= $dsc['key'] === 'yes' ? 'selected' : '' ?>>Sim</option>
            <option value="no" <?= $dsc['key'] === 'no' ? 'selected' : '' ?>>Não</option>
            <option value="from" <?= $dsc['key'] === 'from' ? 'selected' : '' ?>>A partir de…</option>
          </select>
          <label class="sr" for="disc-d">A partir de</label><input type="date" id="disc-d" name="disclose_from" value="<?= e((string)($deal['disclose_from'] ?? '')) ?>">
          <button class="btn ghost sm" type="submit">Guardar</button></form>
      <?php else: ?><?= e($dsc['label']) ?><?php endif; ?>
      <span class="muted small">· sem autorização, as peças de Marketing deste negócio não podem ser agendadas nem publicadas</span></div>
    <span>Criado por</span><div><?= $creator !== '' ? e($creator) : '<span class="muted">—</span>' ?> <span class="muted small mono"><?= e(lisbon_fmt(strtotime((string)$deal['created_at']), 'd/m/Y')) ?></span></div>
  </div>
</div>
<?php if ($dealEdit): ?>
<div class="panel js-only">
  <div class="kv">
    <span>Valor estimado</span><div><?= inline_field('deal', $id, 'value_estimate', $deal['value_estimate'] !== null ? e(eur0((float)$deal['value_estimate'])) : '', ['edit' => true, 'type' => 'money', 'label' => 'Editar valor estimado', 'value' => $deal['value_estimate'] !== null ? number_format((float)$deal['value_estimate'], 2, ',', '') : '']) ?></div>
    <span>Notas</span><div><?= inline_field('deal', $id, 'notes', e((string)$deal['notes']), ['edit' => true, 'type' => 'textarea', 'label' => 'Editar notas', 'value' => (string)$deal['notes']]) ?></div>
  </div>
</div>
<noscript>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="save_info"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="anchor" value="info">
  <div class="grid">
    <div class="field"><label for="nn">Nome</label><input type="text" id="nn" name="name" maxlength="160" value="<?= e($deal['name']) ?>"></div>
    <div class="field"><label>ID</label><input type="text" value="<?= e($deal['code']) ?>" disabled></div>
    <div class="field wide"><label for="nnotes">Notas</label><textarea id="nnotes" name="notes"><?= e((string)$deal['notes']) ?></textarea></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar</button></div>
</form>
</noscript>
<?php else: ?>
  <p><?= nl2br(e((string)$deal['notes'])) ?: '<span class="muted">Sem notas.</span>' ?></p>
<?php endif; ?>

<h2 id="contactos">Contactos</h2>
<div class="tablewrap"><table>
  <thead><tr><th>Nome</th><th>Papel</th><th class="hide-sm">Email</th><th class="hide-sm">Telefone</th><th></th></tr></thead>
  <tbody>
  <?php if (!$contacts): ?><tr><td colspan="5" class="empty">Sem contactos ligados.</td></tr><?php endif; ?>
  <?php foreach ($contacts as $c): ?>
    <tr><td><a class="b" style="color:inherit" href="/admin/contact.php?id=<?= (int)$c['id'] ?>"<?= can('contactos') ? ' data-panel="contact:' . (int)$c['id'] . '"' : '' ?>><?= e($c['name']) ?></a>
          <?= (int)$c['id'] === (int)$deal['contact_id'] ? ' <span class="chip">cliente</span>' : '' ?></td>
      <td><?= e($c['link_role'] ?: '—') ?></td>
      <td class="hide-sm"><?= $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '—' ?></td>
      <td class="hide-sm"><?= e($c['phone'] ?: '—') ?></td>
      <td class="num"><?php if ($canEdit): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="unlink_contact">
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="anchor" value="contactos">
          <button class="btn ghost sm" type="submit">Desligar</button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if ($canEdit && $allContacts): ?>
<form method="post" class="bar">
  <?= csrf_field() ?><input type="hidden" name="do" value="link_contact"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="anchor" value="contactos">
  <select name="contact_id" required aria-label="Contacto"><option value="">Ligar contacto…</option>
    <?php foreach ($allContacts as $cid => $cn): ?><option value="<?= (int)$cid ?>"><?= e($cn) ?></option><?php endforeach; ?>
  </select>
  <input type="text" name="role" maxlength="80" placeholder="Papel (ex.: produtor)">
  <button class="btn sm" type="submit">Ligar</button>
  <a class="lnk" href="/admin/contactos.php?novo=1">Novo contacto</a>
</form>
<?php endif; ?>
<?php endif; ?>
</div>

<?php if ($dealEdit && $deal['phase'] === 'production'): ?>
<div class="mdl<?= !empty($_GET['fecho']) ? ' open' : '' ?>" id="mdl-close" data-clear="fecho" role="dialog" aria-modal="true" aria-labelledby="mdl-close-t"<?= !empty($_GET['fecho']) ? '' : ' hidden' ?>>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card" method="post" action="<?= e($self) ?>">
    <?= csrf_field() ?><input type="hidden" name="do" value="close"><input type="hidden" name="id" value="<?= $id ?>">
    <h3 id="mdl-close-t">Concluir <?= e($deal['code']) ?></h3>
    <p class="muted">A checklist de fecho. Pode concluir com itens em falta: o negócio fica assinalado e conta como «Em risco» até fechar.</p>
    <?= deal_checklist_html($check) ?>
    <?php if (!$deal['delivered_at']): ?><label class="check dck"><input type="checkbox" name="delivered" value="1"> As entregas ao cliente estão feitas</label><?php endif; ?>
    <h4 class="dck-h">Depois do fecho <span class="muted">(opcional)</span></h4>
    <label class="check dck"><input type="checkbox" name="portfolio" value="1"<?= $deal['portfolio_at'] ? ' disabled checked' : '' ?>> Enviar para o portfólio <small class="muted">· Marketing: cria a tarefa de preparar o material e a ideia «Breakdown»</small></label>
    <label class="check dck"><input type="checkbox" name="testimonial" value="1"<?= $deal['testimonial_at'] ? ' disabled checked' : '' ?>> Pedir testemunho ao cliente <small class="muted">· cria o email para o responsável</small></label>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><button class="btn" type="submit"><?= icon('check') ?> Concluir</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($dealEdit):
    $people = [];
    foreach (assignable_users($pdo, $me) as $u) $people[(int)$u['id']] = $u['name'];
    $next = (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->modify('+1 day'); ?>
<div class="mdl" id="mdl-dtask" role="dialog" aria-modal="true" aria-labelledby="mdl-dtask-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card newact" method="post" action="<?= e($self) ?>" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="task"><input type="hidden" name="id" value="<?= $id ?>">
    <h3 id="mdl-dtask-t">Nova tarefa · <?= e($deal['code']) ?></h3>
    <p class="muted">Fica ligada ao negócio e aparece também em Tarefas.</p>
    <div class="kinds" role="radiogroup" aria-label="Tipo de tarefa">
      <?php foreach (LEAD_TASK_KINDS as $k => [$label, $ico]): ?>
        <label class="kind" data-tip="<?= e($label) ?>"><input type="radio" name="kind" value="<?= $k ?>"<?= $k === 'task' ? ' checked' : '' ?>><span aria-hidden="true"><?= icon($ico) ?></span><span class="sr"><?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
    <input type="text" name="title" maxlength="200" placeholder="O quê? (ex.: Enviar a v2 ao cliente)" aria-label="O quê">
    <textarea name="notes" rows="2" maxlength="4000" placeholder="Notas (opcional)" aria-label="Notas"></textarea>
    <div class="when-row">
      <input type="date" name="date" value="<?= e($next->format('Y-m-d')) ?>" aria-label="Data" required>
      <input type="time" name="time" aria-label="Hora (opcional)">
      <select name="assignee" aria-label="Para quem"><?php foreach ($people as $pid => $pn): ?><option value="<?= $pid ?>"<?= $pid === (int)$me['id'] ? ' selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?></select>
    </div>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><button class="btn" type="submit"><?= icon('plus') ?> Criar tarefa</button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($rateCost): ?>
<div class="mdl open" id="mdl-rate" data-from="#cost-<?= (int)$rateCost['id'] ?> .check" data-clear="avaliar" role="dialog" aria-modal="true" aria-labelledby="mdl-rate-t">
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card" method="post" action="<?= e($self) ?>">
    <?= csrf_field() ?><input type="hidden" name="do" value="rate_cost"><input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="cost_id" value="<?= (int)$rateCost['id'] ?>"><input type="hidden" name="anchor" value="custos">
    <h3 id="mdl-rate-t">Como correu o trabalho?</h3>
    <p class="muted"><?= e($rateCost['contact_name']) ?> · <?= e($rateCost['role'] ?: $rateCost['name']) ?>. A avaliação do fornecedor é a média real dos trabalhos.</p>
    <div class="rate-stars" role="radiogroup" aria-label="Avaliação de 1 a 5">
      <?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" id="rs-<?= $i ?>" name="stars" value="<?= $i ?>" required><label for="rs-<?= $i ?>" data-tip="<?= $i ?> de 5" aria-label="<?= $i ?> de 5">★</label><?php endfor; ?>
    </div>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Agora não</button><button class="btn" type="submit"><?= icon('star') ?> Avaliar</button></div>
  </form>
</div>
<?php endif; ?>

<script src="<?= asset_url('deal.js') ?>" defer></script>
<?php render_footer();
