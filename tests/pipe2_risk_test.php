<?php
/**
 * Pipeline v2, fase 1 — «Em risco» (admin/inc/pipe2_risk.php; tarefa 7: D25; X4).
 *
 * Uma só função para o Pipeline, a aba Negócios, o Dashboard e a vista guardada (ainda não ligada a ecrã nenhum), com
 * os 4 motivos da §34.9: sem movimento há N dias ou mais (limite deal_stale_days, comparação ≥; movimento inclui
 * concluir tarefas e entrar ou sair de sub-etapas; num negócio em Em execução sem nenhuma frente em curso não se
 * aplica), pagamento em atraso (só faturas emitidas vencidas, com saldo), entrega ultrapassada (do negócio ou de uma
 * frente) e sub-etapa atrasada só quando empurra a entrega. Vários motivos → o mais grave e «+n». Os rótulos nunca têm
 * euros nem números de faturas. As consultas são agregadas: o número delas não cresce com o número de negócios.
 *
 * Corre numa base privada do dump da 42, migrada até à SCHEMA_VERSION (p2v_db), e numa fresca na 42 (::schema_guard);
 * não escreve na bancada. Datas fixas com $today.
 *
 * Casos: ::limit_ge ::movement_includes_tasks_and_substeps ::waiting_and_queued_dont_count ::late_payment_only_issued
 *        ::late_delivery_deal_and_front ::substep_late_only_if_pushes ::label_plus_one ::no_money_in_labels
 *        ::strict_group_by ::v1_negocio ::many_keys ::schema_guard
 *
 * Uso: php tests/pipe2_risk_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2_risk.php';

const P2RK_TODAY = '2026-12-15';   // terça-feira
const P2RK_TP = '2026-11-06';      // sexta-feira (o dia dos casos do plano das sub-etapas)

/** Um dia relativo a P2RK_TODAY (AAAA-MM-DD). */
function p2rk_d(int $days, string $from = P2RK_TODAY): string {
    return (new DateTimeImmutable($from))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/** UPDATE direto de deals. */
function p2rk_deal_set(PDO $pdo, int $id, array $set): void {
    foreach (array_keys($set) as $c) if (!preg_match('/^[a-z_]+$/', (string)$c)) throw new InvalidArgumentException('p2rk_deal_set: coluna inválida ' . $c);
    $pdo->prepare('UPDATE deals SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?')
        ->execute(array_merge(array_values($set), [$id]));
}

/** O último movimento do negócio fica em $date (last_touch, converted_at e created_at). */
function p2rk_moved(PDO $pdo, int $id, string $date): void {
    p2rk_deal_set($pdo, $id, ['last_touch' => $date, 'converted_at' => $date . ' 08:00:00', 'created_at' => $date . ' 08:00:00']);
}

/** Uma fatura (ou outro documento) do negócio: a receber, emitida, vencida há 10 dias, por pagar ($o sobrepõe). */
function p2rk_fin(PDO $pdo, int $dealId, array $o = []): int {
    $now = date('Y-m-d H:i:s');
    return pipe2_insert($pdo, 'fin_docs', array_merge([
        'direction' => 'in', 'doc_type' => 'invoice', 'scope' => 'deal', 'deal_id' => $dealId, 'party_name' => PIPE2_FUNNEL_PREFIX . 'cliente',
        'number' => 'FT 2026/77', 'base' => 1000, 'vat_rate' => 23, 'vat' => 230, 'total' => 1230, 'paid_amount' => 0, 'withholding' => 0,
        'status' => 'issued', 'issue_date' => p2rk_d(-40), 'due_date' => p2rk_d(-10), 'created_at' => $now, 'updated_at' => $now,
    ], $o));
}

/** Os motivos em forma curta: [[código, rótulo, frente], …]. */
function p2rk_c(array $reasons): array {
    return array_map(fn($r) => [$r['code'], $r['label'], $r['line']], $reasons);
}

/** As linhas de passos de uma frente com o plano dado ([posição => [início, fim]]) e o started_at da 1.ª. */
function p2rk_plan(PDO $pdo, int $lineId, array $plan, string $started): void {
    $ids = p2v_steps($pdo, $lineId, ['start' => $started]);
    $up = $pdo->prepare('UPDATE deal_line_steps SET plan_start = ?, plan_end = ? WHERE id = ?');
    foreach (array_values($ids) as $i => $id) {
        if (isset($plan[$i])) $up->execute([$plan[$i][0], $plan[$i][1], $id]);
    }
}

/** Statements que o servidor recebeu nesta sessão (SHOW SESSION STATUS conta-se a si próprio). */
function p2rk_questions(PDO $pdo): int {
    return (int)$pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
}

// ── a base privada, os funis, os negócios (tudo antes das leituras) ──
[$db, $tp] = p2v_db();
$w = p2v_world($tp, ['vfx', '3d']);
$S = array_map(fn($fid) => p2v_stages($tp, $fid), $w);
$all = [];
$mk = function (array $codes, array $o = []) use ($tp, $w, &$all): array {
    $d = p2v_deal($tp, $w, $codes, $o);
    $all[] = $d['id'];
    return $d;
};

// ::limit_ge
$dStale5 = $mk(['vfx'], ['at' => 'proposal']);
$dStale4 = $mk(['vfx'], ['at' => 'proposal']);
// ::movement_includes_tasks_and_substeps
$dTask   = $mk(['vfx', '3d'], ['at' => 'exec']);
$dStep   = $mk(['vfx', '3d'], ['at' => 'exec']);
$dNoMove = $mk(['vfx', '3d'], ['at' => 'exec']);
// ::waiting_and_queued_dont_count
$dWait    = $mk(['vfx', '3d'], ['at' => 'exec', 'status' => ['vfx' => 'depwait', '3d' => 'queued']]);
$dWaitRdy = $mk(['vfx', '3d'], ['at' => 'exec', 'status' => ['vfx' => 'ready', '3d' => 'depwait']]);
$dFeeRdy  = $mk(['vfx', '3d'], ['at' => 'exec', 'status' => ['vfx' => 'ongoing', '3d' => 'depwait'], 'billing' => ['vfx' => [500, 12]],
                                'front_at' => ['vfx' => $S['vfx']['ready']]]);
$dFeeRun  = $mk(['vfx', '3d'], ['at' => 'exec', 'status' => ['vfx' => 'ongoing', '3d' => 'depwait'], 'billing' => ['vfx' => [500, 12]]]);
$dKick    = $mk(['vfx', '3d'], ['at' => 'kickoff']);
// ::late_payment_only_issued
$dReq     = $mk(['vfx'], ['at' => 'exec']);
$dIssued  = $mk(['vfx'], ['at' => 'exec']);
$dDueToday = $mk(['vfx'], ['at' => 'exec']);
$dLegacy  = $mk(['vfx'], ['at' => 'done', 'budget' => 5000]);
$dPaid    = $mk(['vfx'], ['at' => 'exec']);
$dPartial = $mk(['vfx'], ['at' => 'exec']);
$dWithheld = $mk(['vfx'], ['at' => 'exec']);
$dOthers  = $mk(['vfx'], ['at' => 'exec']);
$dIssue31 = $mk(['vfx'], ['at' => 'exec']);
$dIssue30 = $mk(['vfx'], ['at' => 'exec']);
$dDonePay = $mk(['vfx'], ['at' => 'done']);
// ::late_delivery_deal_and_front
$dLate     = $mk(['vfx', '3d'], ['at' => 'exec', 'delivery_date' => p2rk_d(-1)]);
$dDelivered = $mk(['vfx', '3d'], ['at' => 'exec', 'delivery_date' => p2rk_d(-1)]);
$dFront    = $mk(['vfx', '3d'], ['at' => 'exec', 'delivery_date' => p2rk_d(10)]);
$dFrontRdy = $mk(['vfx', '3d'], ['at' => 'exec', 'delivery_date' => p2rk_d(10), 'status' => ['3d' => 'ready']]);
$dFrontOnly = $mk(['vfx', '3d'], ['at' => 'exec']);
$dBoth     = $mk(['vfx', '3d'], ['at' => 'exec', 'delivery_date' => p2rk_d(-2)]);
$dSent     = $mk(['vfx', '3d'], ['at' => 'delivery', 'delivery_date' => p2rk_d(-3), 'status' => 'ready']);
$dSentLine = $mk(['vfx', '3d'], ['at' => 'delivery', 'delivery_date' => p2rk_d(-3), 'status' => 'ready']);
$dPipeLate = $mk(['vfx'], ['at' => 'proposal', 'delivery_date' => p2rk_d(-1)]);
$dDoneLate = $mk(['vfx'], ['at' => 'done', 'delivery_date' => p2rk_d(-1)]);
// ::substep_late_only_if_pushes (P2RK_TP; plano de 6 sub-etapas, a 1.ª acaba a 4 de novembro)
$dPushNo   = $mk(['vfx'], ['at' => 'exec', 'delivery_date' => '2026-11-25']);
$dPushYes  = $mk(['vfx'], ['at' => 'exec', 'delivery_date' => '2026-11-19']);
$dReplNo   = $mk(['vfx'], ['at' => 'exec', 'delivery_date' => '2026-11-23']);
$dReplYes  = $mk(['vfx'], ['at' => 'exec', 'delivery_date' => '2026-11-19']);
$dPushWait = $mk(['vfx'], ['at' => 'exec', 'delivery_date' => '2026-11-19', 'status' => 'depwait', 'front_at' => ['vfx' => $S['vfx']['fronts'][0]]]);
$dPushOwn  = $mk(['vfx'], ['at' => 'exec', 'delivery_date' => '2026-11-30']);
// ::label_plus_one
$dTwo   = $mk(['vfx'], ['at' => 'exec']);
$dThree = $mk(['vfx'], ['at' => 'exec', 'delivery_date' => p2rk_d(-1)]);
// ::zero_dates (MySQL sem modo estrito: «0000-00-00» em vez de NULL)
$dZeroDue   = $mk(['vfx'], ['at' => 'exec']);
$dZeroDeliv = $mk(['vfx'], ['at' => 'exec', 'delivery_date' => p2rk_d(-1)]);
$dZeroFront = $mk(['vfx', '3d'], ['at' => 'exec', 'delivery_date' => p2rk_d(10)]);
$dZeroInv   = $mk(['vfx'], ['at' => 'exec']);
$dZeroPlan  = $mk(['vfx'], ['at' => 'exec', 'delivery_date' => '2026-11-19']);
// ::strict_group_by (30 negócios com frentes, tarefas, passos e faturas)
$many = [];
for ($i = 0; $i < 30; $i++) $many[] = $mk(['vfx', '3d'], ['at' => 'exec', 'delivery_date' => p2rk_d($i % 2 ? -1 : 5)]);

// ::v1_negocio: o funil VFX real (v1, do dump)
$v1Funnel = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$dV1 = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1Funnel, 'delivery_date' => p2rk_d(-3)]);
$dV1Line = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1Funnel, 'delivery_date' => p2rk_d(5)]);
$v1Line = pipe2_front($tp, $dV1Line, $v1Funnel, ['status' => 'active', 'delivery_date' => p2rk_d(-4)]);
$dV1Stale = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1Funnel]);
$all = array_merge($all, [$dV1, $dV1Line, $dV1Stale]);

// todos com movimento hoje; os que precisam ficam mais velhos a seguir
$tp->exec("UPDATE deals SET last_touch = '" . P2RK_TODAY . "' WHERE id IN (" . pipe2_ids_sql($all) . ')');

// ── ::limit_ge ──────────────────────────────────────────────
$c = '::limit_ge';
t_eq(pipe2_risk_limit($tp), 10, "$c sem a definição: 10 (o de hoje)");
$tp->exec("INSERT INTO settings (skey, svalue) VALUES ('deal_stale_days', '5') ON DUPLICATE KEY UPDATE svalue = '5'");
settings($tp, true);
t_eq(pipe2_risk_limit($tp), 5, "$c deal_stale_days = 5");
p2rk_moved($tp, $dStale5['id'], p2rk_d(-5));
p2rk_moved($tp, $dStale4['id'], p2rk_d(-4));
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dStale5['id']], P2RK_TODAY)), [['stale', 'Sem movimento há 5 d', null]], "$c há 5 dias com limite 5 (≥) → sem movimento");
t_eq(pipe2_risk($tp, ['id' => $dStale4['id']], P2RK_TODAY), [], "$c há 4 dias → nada");
$r = pipe2_risk($tp, ['id' => $dStale5['id']], P2RK_TODAY)[0];
t_eq([$r['sev'], $r['icon'], $r['days']], [1, 'pause', 5], "$c gravidade 1, ícone pause, 5 dias");
// o movimento mais recente conta, venha de onde vier (last_touch mais antigo do que a conversão)
p2rk_deal_set($tp, $dStale4['id'], ['last_touch' => p2rk_d(-30), 'converted_at' => p2rk_d(-2) . ' 10:00:00']);
t_eq(pipe2_risk($tp, ['id' => $dStale4['id']], P2RK_TODAY), [], "$c o mais recente de last_touch/converted_at/created_at");

// ── ::movement_includes_tasks_and_substeps ──────────────────
$c = '::movement_includes_tasks_and_substeps';
foreach ([$dTask, $dStep, $dNoMove] as $d) p2rk_moved($tp, $d['id'], p2rk_d(-20));
p2v_task($tp, $dTask['id'], ['status' => 'done', 'done_at' => p2rk_d(-1) . ' 17:00:00']);
t_eq(pipe2_risk($tp, ['id' => $dTask['id']], P2RK_TODAY), [], "$c uma tarefa feita ontem é movimento");
$st = p2v_steps($tp, $dStep['lines']['vfx'], ['start' => p2rk_d(-30) . ' 09:00:00']);
$tp->prepare('UPDATE deal_line_steps SET started_at = ? WHERE id = ?')->execute([p2rk_d(-1) . ' 09:00:00', $st[$S['vfx']['fronts'][1]]]);
t_eq(pipe2_risk($tp, ['id' => $dStep['id']], P2RK_TODAY), [], "$c entrar numa sub-etapa ontem é movimento");
$st = p2v_steps($tp, $dNoMove['lines']['vfx'], ['start' => p2rk_d(-25) . ' 09:00:00']);
p2v_task($tp, $dNoMove['id'], ['status' => 'todo', 'created_at' => p2rk_d(-1) . ' 09:00:00']);
p2v_task($tp, $dNoMove['id'], ['status' => 'done', 'done_at' => p2rk_d(-21) . ' 09:00:00']);
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dNoMove['id']], P2RK_TODAY)), [['stale', 'Sem movimento há 20 d', null]],
     "$c sem tarefas feitas nem passos recentes (uma tarefa criada ontem não conta) → há 20 d");
$tp->prepare('UPDATE deal_line_steps SET done_at = ? WHERE id = ?')->execute([p2rk_d(-2) . ' 18:00:00', $st[$S['vfx']['fronts'][0]]]);
t_eq(pipe2_risk($tp, ['id' => $dNoMove['id']], P2RK_TODAY), [], "$c sair de uma sub-etapa anteontem é movimento");

// ── ::waiting_and_queued_dont_count ─────────────────────────
$c = '::waiting_and_queued_dont_count';
foreach ([$dWait, $dWaitRdy, $dFeeRdy, $dFeeRun, $dKick] as $d) p2rk_moved($tp, $d['id'], p2rk_d(-20));
t_eq(pipe2_risk($tp, ['id' => $dWait['id']], P2RK_TODAY), [], "$c Em execução com as frentes À espera e Por começar → sem «sem movimento»");
t_eq(pipe2_risk($tp, ['id' => $dWaitRdy['id']], P2RK_TODAY), [], "$c Em execução com as frentes Prontas e À espera → nada");
t_eq(pipe2_risk($tp, ['id' => $dFeeRdy['id']], P2RK_TODAY), [], "$c avença Ativa em Pronto entre ciclos não está em curso → nada");
t_eq(array_column(pipe2_risk($tp, ['id' => $dFeeRun['id']], P2RK_TODAY), 'code'), ['stale'], "$c avença Ativa numa sub-etapa de trabalho está em curso → sem movimento");
t_eq(array_column(pipe2_risk($tp, ['id' => $dKick['id']], P2RK_TODAY), 'code'), ['stale'], "$c no Kickoff (frentes Por começar) conta: só a Em execução é exceção");

// ── ::late_payment_only_issued ──────────────────────────────
$c = '::late_payment_only_issued';
p2rk_fin($tp, $dReq['id'], ['status' => 'requested', 'number' => '', 'req_code' => 'FAT-2026-0142']);
p2rk_fin($tp, $dIssued['id'], ['due_date' => p2rk_d(-1)]);
p2rk_fin($tp, $dDueToday['id'], ['due_date' => P2RK_TODAY]);
p2rk_deal_set($tp, $dLegacy['id'], ['is_paid' => 0]);
p2rk_fin($tp, $dPaid['id'], ['status' => 'paid', 'paid_amount' => 1230, 'paid_date' => p2rk_d(-5)]);
p2rk_fin($tp, $dPartial['id'], ['status' => 'partial', 'paid_amount' => 600]);
p2rk_fin($tp, $dWithheld['id'], ['withholding' => 250, 'paid_amount' => 980]);
p2rk_fin($tp, $dOthers['id'], ['doc_type' => 'credit']);
p2rk_fin($tp, $dOthers['id'], ['deleted_at' => p2rk_d(-3) . ' 10:00:00']);
p2rk_fin($tp, $dOthers['id'], ['direction' => 'out', 'status' => 'to_pay']);
p2rk_fin($tp, $dOthers['id'], ['status' => 'void']);
p2rk_fin($tp, $dIssue31['id'], ['due_date' => null, 'issue_date' => p2rk_d(-31)]);
p2rk_fin($tp, $dIssue30['id'], ['due_date' => null, 'issue_date' => p2rk_d(-30)]);
p2rk_fin($tp, $dDonePay['id'], ['due_date' => p2rk_d(-7)]);
t_eq(pipe2_risk($tp, ['id' => $dReq['id']], P2RK_TODAY), [], "$c fatura pedida (requested) vencida → nada");
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dIssued['id']], P2RK_TODAY)), [['late_pay', 'Pagamento em atraso', null]], "$c emitida, vencida ontem, por pagar → pagamento em atraso");
$r = pipe2_risk($tp, ['id' => $dIssued['id']], P2RK_TODAY)[0];
t_eq([$r['sev'], $r['icon'], $r['days']], [3, 'euro', 1], "$c gravidade 3, ícone euro, 1 dia");
t_eq(pipe2_risk($tp, ['id' => $dDueToday['id']], P2RK_TODAY), [], "$c vence hoje → ainda não está em atraso");
t_eq(pipe2_risk($tp, ['id' => $dLegacy['id']], P2RK_TODAY), [], "$c concluído sem faturas (o «legacy» do A receber) → nada");
t_eq(pipe2_risk($tp, ['id' => $dPaid['id']], P2RK_TODAY), [], "$c paga → nada");
t_eq(array_column(pipe2_risk($tp, ['id' => $dPartial['id']], P2RK_TODAY), 'code'), ['late_pay'], "$c parcialmente paga, vencida, com saldo → pagamento em atraso");
t_eq(pipe2_risk($tp, ['id' => $dWithheld['id']], P2RK_TODAY), [], "$c o saldo desconta a retenção (como o A receber): paga a parte do cliente → nada");
t_eq(pipe2_risk($tp, ['id' => $dOthers['id']], P2RK_TODAY), [], "$c nota de crédito, apagada, a pagar e anulada → nada");
t_eq(array_column(pipe2_risk($tp, ['id' => $dIssue31['id']], P2RK_TODAY), 'code'), ['late_pay'], "$c sem vencimento: 30 dias depois da emissão (31 dias → em atraso)");
t_eq(pipe2_risk($tp, ['id' => $dIssue30['id']], P2RK_TODAY), [], "$c sem vencimento, emitida há 30 dias → ainda não");
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dDonePay['id']], P2RK_TODAY)), [['late_pay', 'Pagamento em atraso', null]], "$c um negócio concluído com a fatura em atraso continua em risco");

// ── ::late_delivery_deal_and_front ──────────────────────────
$c = '::late_delivery_deal_and_front';
p2rk_deal_set($tp, $dDelivered['id'], ['delivered_at' => p2rk_d(-2) . ' 12:00:00']);
p2v_front($tp, $dFront['lines']['3d'], ['delivery_date' => p2rk_d(-1)]);
p2v_front($tp, $dFrontRdy['lines']['3d'], ['delivery_date' => p2rk_d(-1)]);
p2v_front($tp, $dFrontOnly['lines']['3d'], ['delivery_date' => p2rk_d(-3)]);
p2v_front($tp, $dBoth['lines']['vfx'], ['delivery_date' => p2rk_d(-6)]);
p2v_front($tp, $dBoth['lines']['3d'], ['delivery_date' => p2rk_d(-2)]);
// o título real da regra de fecho (o pipe2_task dos testes prefixaria «P2T · »)
$sentTitle = 'Enviar entregáveis finais com comprovativo de download';
$t = p2v_task($tp, $dSent['id'], ['status' => 'done', 'done_at' => p2rk_d(-5) . ' 10:00:00']);
$tp->prepare('UPDATE tasks SET title = ? WHERE id = ?')->execute([$sentTitle, $t]);
$t = p2v_task($tp, $dSentLine['id'], ['status' => 'done', 'done_at' => p2rk_d(-5) . ' 10:00:00', 'line_id' => $dSentLine['lines']['vfx'], 'stage_id' => $S['vfx']['fronts'][3]]);
$tp->prepare('UPDATE tasks SET title = ? WHERE id = ?')->execute([$sentTitle, $t]);
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dLate['id']], P2RK_TODAY)), [['late_delivery', 'Entrega ultrapassada há 1 d', null]], "$c entrega do negócio ontem, por entregar → entrega ultrapassada");
$r = pipe2_risk($tp, ['id' => $dLate['id']], P2RK_TODAY)[0];
t_eq([$r['sev'], $r['icon'], $r['days']], [3, 'clock', 1], "$c gravidade 3, ícone clock, 1 dia");
t_eq(pipe2_risk($tp, ['id' => $dLate['id']], p2rk_d(-1)), [], "$c no próprio dia da entrega → ainda não");
t_eq(pipe2_risk($tp, ['id' => $dDelivered['id']], P2RK_TODAY), [], "$c com delivered_at → nada");
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dFront['id']], P2RK_TODAY)), [['late_delivery', '3D: entrega ultrapassada há 1 d', $dFront['lines']['3d']]],
     "$c só a frente 3D com entrega ontem e não Pronta → «3D: entrega ultrapassada há 1 d»");
t_eq(pipe2_risk($tp, ['id' => $dFrontRdy['id']], P2RK_TODAY), [], "$c a frente Pronta → nada");
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dFrontOnly['id']], P2RK_TODAY)), [['late_delivery', '3D: entrega ultrapassada há 3 d', $dFrontOnly['lines']['3d']]],
     "$c sem data comum: só o motivo da frente (sem repetir pelo negócio)");
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dBoth['id']], P2RK_TODAY)), [
    ['late_delivery', 'Entrega ultrapassada há 2 d', null],
    ['late_delivery', 'VFX: entrega ultrapassada há 6 d', $dBoth['lines']['vfx']],
], "$c a do negócio primeiro; a frente com a mesma data que o negócio não se repete; a mais cedo fica");
t_eq(pipe2_risk($tp, ['id' => $dSent['id']], P2RK_TODAY), [], "$c «Enviar entregáveis finais» feita (do negócio) → entregue, nada");
t_eq(array_column(pipe2_risk($tp, ['id' => $dSentLine['id']], P2RK_TODAY), 'code'), ['late_delivery'], "$c a mesma tarefa numa frente não entrega o negócio");
t_eq(array_column(pipe2_risk($tp, ['id' => $dPipeLate['id']], P2RK_TODAY), 'code'), ['late_delivery'], "$c ainda no Pipeline com a entrega passada → entrega ultrapassada");
t_eq(pipe2_risk($tp, ['id' => $dDoneLate['id']], P2RK_TODAY), [], "$c concluído → nada (nem entrega nem movimento)");

// ── ::substep_late_only_if_pushes ───────────────────────────
$c = '::substep_late_only_if_pushes';
$plan = [['2026-11-02', '2026-11-04'], ['2026-11-04', '2026-11-09'], ['2026-11-09', '2026-11-13'], ['2026-11-13', '2026-11-17'], ['2026-11-17', '2026-11-18'], ['2026-11-18', '2026-11-18']];
$replanned = [['2026-11-02', '2026-11-04'], ['2026-11-06', '2026-11-11'], ['2026-11-11', '2026-11-17'], ['2026-11-17', '2026-11-19'], ['2026-11-19', '2026-11-20'], ['2026-11-20', '2026-11-20']];
foreach ([[$dPushNo, $plan], [$dPushYes, $plan], [$dReplNo, $replanned], [$dReplYes, $replanned], [$dPushWait, $plan], [$dPushOwn, $plan]] as [$d, $p]) {
    p2rk_moved($tp, $d['id'], P2RK_TP);
    p2rk_plan($tp, $d['lines']['vfx'], $p, P2RK_TP . ' 09:00:00');
}
p2v_front($tp, $dPushOwn['lines']['vfx'], ['delivery_date' => '2026-11-19']);
t_eq(pipe2_risk($tp, ['id' => $dPushNo['id']], P2RK_TP), [], "$c 2 dias úteis de atraso, a Revisões acaba a 20 e a entrega é a 25 → nada");
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dPushYes['id']], P2RK_TP)), [['push', 'VFX: o atraso empurra a entrega', $dPushYes['lines']['vfx']]],
     "$c o mesmo atraso com a entrega a 19 → o atraso empurra a entrega");
$r = pipe2_risk($tp, ['id' => $dPushYes['id']], P2RK_TP)[0];
t_eq([$r['sev'], $r['icon']], [2, 'clock'], "$c gravidade 2, ícone clock");
t_eq(pipe2_risk($tp, ['id' => $dPushYes['id']], '2026-11-04'), [], "$c no dia do fim da sub-etapa ainda não está atrasada → nada");
t_eq(pipe2_risk($tp, ['id' => $dReplNo['id']], P2RK_TP), [], "$c já replaneado (a Revisões acaba a 20), entrega a 23 → nada (o atraso não conta duas vezes)");
t_eq(array_column(pipe2_risk($tp, ['id' => $dReplYes['id']], P2RK_TP), 'code'), ['push'], "$c já replaneado, entrega a 19 → empurra");
t_eq(pipe2_risk($tp, ['id' => $dPushWait['id']], P2RK_TP), [], "$c frente À espera com a sub-etapa atrasada → nada");
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dPushOwn['id']], P2RK_TP)), [['push', 'VFX: o atraso empurra a entrega', $dPushOwn['lines']['vfx']]],
     "$c conta a entrega da frente (19), não a do negócio (30)");
t_eq(array_column(pipe2_risk($tp, ['id' => $dPushYes['id']], '2026-11-20'), 'code'), ['late_delivery', 'stale'],
     "$c com a entrega já passada é «entrega ultrapassada», não «empurra» (e 14 dias sem movimento)");

// ── ::label_plus_one ────────────────────────────────────────
$c = '::label_plus_one';
p2rk_moved($tp, $dTwo['id'], p2rk_d(-8));
p2rk_fin($tp, $dTwo['id']);
p2rk_moved($tp, $dThree['id'], p2rk_d(-8));
p2rk_fin($tp, $dThree['id']);
$two = pipe2_risk($tp, ['id' => $dTwo['id']], P2RK_TODAY);
t_eq(array_column($two, 'code'), ['late_pay', 'stale'], "$c o mais grave primeiro");
t_eq(pipe2_risk_label($two), 'Pagamento em atraso +1', "$c pagamento em atraso + sem movimento → «Pagamento em atraso +1»");
$three = pipe2_risk($tp, ['id' => $dThree['id']], P2RK_TODAY);
t_eq(array_column($three, 'code'), ['late_pay', 'late_delivery', 'stale'], "$c com a mesma gravidade, pela ordem dos motivos");
t_eq(pipe2_risk_label($three), 'Pagamento em atraso +2', "$c três motivos → «+2»");
t_eq(pipe2_risk_label(pipe2_risk($tp, ['id' => $dLate['id']], P2RK_TODAY)), 'Entrega ultrapassada há 1 d', "$c um motivo → só o rótulo");
t_eq(pipe2_risk_label([]), '', "$c sem motivos → ''");

// ── ::no_money_in_labels ────────────────────────────────────
$c = '::no_money_in_labels';
$labels = [];
foreach (pipe2_risk_many($tp, array_map(fn($id) => ['id' => $id], $all), P2RK_TODAY) as $reasons) {
    foreach ($reasons as $r) $labels[] = $r['label'];
    if ($reasons) $labels[] = pipe2_risk_label($reasons);
}
t_true(count($labels) > 20, "$c há rótulos para verificar (" . count($labels) . ')');
t_eq(array_values(array_filter($labels, fn($l) => preg_match('/€|EUR|FAT-|FT 2026|1230|1 230|1\.230|600|980/u', $l))), [], "$c nenhum rótulo com euros, pedidos ou números de faturas");
t_eq(array_values(array_filter($labels, fn($l) => str_contains($l, '§'))), [], "$c nenhum rótulo com «§»");

// ── ::strict_group_by ───────────────────────────────────────
$c = '::strict_group_by';
foreach ($many as $i => $d) {
    p2v_task($tp, $d['id'], ['status' => 'done', 'done_at' => p2rk_d(-3) . ' 10:00:00']);
    p2v_task($tp, $d['id'], ['status' => 'todo']);
    p2rk_plan($tp, $d['lines']['vfx'], $plan, p2rk_d(-2) . ' 09:00:00');
    p2v_steps($tp, $d['lines']['3d']);
    if ($i % 3 === 0) p2rk_fin($tp, $d['id']);
    if ($i % 4 === 0) p2v_front($tp, $d['lines']['3d'], ['delivery_date' => p2rk_d(-4)]);
}
$rows3 = array_map(fn($d) => ['id' => $d['id']], array_slice($many, 0, 3));
$rows30 = array_map(fn($d) => ['id' => $d['id']], $many);
$loose = pipe2_risk_many($tp, $rows30, P2RK_TODAY);
pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
$err = '';
try {
    $strict = pipe2_risk_many($tp, $rows30, P2RK_TODAY);
} catch (Throwable $e) {
    $err = $e->getMessage();
    $strict = null;
}
t_eq($err, '', "$c sem exceção em ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES");
t_eq($strict, $loose, "$c o mesmo resultado nos dois modos");
pipe2_sql_mode($tp, '');
t_true(count(array_filter($loose)) >= 15, "$c os 30 negócios têm motivos (" . count(array_filter($loose)) . ' com risco)');
pipe2_risk_many($tp, $rows3, P2RK_TODAY);   // aquece as caches (definições, esquema)
$q0 = p2rk_questions($tp);
pipe2_risk_many($tp, $rows3, P2RK_TODAY);
$n3 = p2rk_questions($tp) - $q0 - 1;
$q0 = p2rk_questions($tp);
pipe2_risk_many($tp, $rows30, P2RK_TODAY);
$n30 = p2rk_questions($tp) - $q0 - 1;
t_true($n3 > 0 && $n3 < 12, "$c consultas para 3 negócios: $n3 (< 12)");
t_eq($n30, $n3, "$c o número de consultas não cresce com o número de negócios (3: $n3, 30: $n30)");
$q0 = p2rk_questions($tp);
t_eq(pipe2_risk_many($tp, [], P2RK_TODAY), [], "$c sem negócios → []");
t_eq(p2rk_questions($tp) - $q0 - 1, 0, "$c sem negócios → nenhuma consulta");

// ── ::v1_negocio ────────────────────────────────────────────
$c = '::v1_negocio';
t_true(!pipe2_deal_is_v2($tp, ['funnel_id' => $v1Funnel]), "$c o funil VFX real é v1");
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dV1], P2RK_TODAY)), [['late_delivery', 'Entrega ultrapassada há 3 d', null]], "$c negócio v1 em produção com a entrega passada → entrega ultrapassada");
$v1Name = PIPE2_SERVICES[(string)p2v_col($tp, 'SELECT service_code FROM funnels WHERE id = ?', [$v1Funnel])]['name']
       ?? (string)p2v_col($tp, 'SELECT name FROM funnels WHERE id = ?', [$v1Funnel]);
t_eq(p2rk_c(pipe2_risk($tp, ['id' => $dV1Line], P2RK_TODAY)), [['late_delivery', $v1Name . ': entrega ultrapassada há 4 d', $v1Line]], "$c frente v1 Em curso com a entrega dela passada");
p2rk_moved($tp, $dV1Stale, p2rk_d(-6));
t_eq(array_column(pipe2_risk($tp, ['id' => $dV1Stale], P2RK_TODAY), 'code'), ['stale'], "$c negócio v1 em produção parado há 6 dias → sem movimento");

// ── ::many_keys ─────────────────────────────────────────────
$c = '::many_keys';
$m = pipe2_risk_many($tp, [['id' => $dLate['id']], ['id' => $dStale4['id']], ['id' => 999999999]], P2RK_TODAY);
t_eq(array_keys($m), [$dLate['id'], $dStale4['id'], 999999999], "$c uma chave por negócio pedido, pela ordem (também sem risco e inexistente)");
t_eq([$m[$dStale4['id']], $m[999999999]], [[], []], "$c sem risco e inexistente → []");
t_eq(pipe2_risk($tp, ['id' => $dLate['id']], P2RK_TODAY), $m[$dLate['id']], "$c pipe2_risk é pipe2_risk_many de um");

// ── ::zero_dates ────────────────────────────────────────────
$c = '::zero_dates';
pipe2_sql_mode($tp, '');
p2rk_deal_set($tp, $dZeroDue['id'], ['delivery_date' => '0000-00-00']);
p2rk_deal_set($tp, $dZeroDeliv['id'], ['delivered_at' => '0000-00-00 00:00:00']);
p2v_front($tp, $dZeroFront['lines']['3d'], ['delivery_date' => '0000-00-00']);
p2rk_fin($tp, $dZeroInv['id'], ['due_date' => '0000-00-00', 'issue_date' => p2rk_d(-5)]);
p2rk_moved($tp, $dZeroPlan['id'], P2RK_TP);
p2rk_plan($tp, $dZeroPlan['lines']['vfx'], [['2026-11-02', '0000-00-00'], ['0000-00-00', '2026-11-09'], ['2026-11-09', '2026-11-13'], ['2026-11-13', '2026-11-17'],
                                            ['2026-11-17', '2026-11-18'], ['2026-11-18', '2026-11-18']], P2RK_TP . ' 09:00:00');
t_eq(p2v_col($tp, 'SELECT delivery_date FROM deals WHERE id = ?', [$dZeroDue['id']]), '0000-00-00', "$c a base guardou mesmo a data zero");
t_eq(pipe2_risk($tp, ['id' => $dZeroDue['id']], P2RK_TODAY), [], "$c entrega «0000-00-00» = sem entrega");
t_eq(array_column(pipe2_risk($tp, ['id' => $dZeroDeliv['id']], P2RK_TODAY), 'code'), ['late_delivery'], "$c delivered_at «0000-00-00 00:00:00» = por entregar");
t_eq(pipe2_risk($tp, ['id' => $dZeroFront['id']], P2RK_TODAY), [], "$c entrega da frente «0000-00-00» = sem entrega própria");
t_eq(pipe2_risk($tp, ['id' => $dZeroInv['id']], P2RK_TODAY), [], "$c vencimento «0000-00-00» = sem vencimento (30 dias depois da emissão: ainda não)");
t_eq(pipe2_risk($tp, ['id' => $dZeroPlan['id']], P2RK_TP), [], "$c fim do plano «0000-00-00» = sem plano (não está atrasada)");

// ── ::schema_guard (fresca na 42, sem as colunas da v2) ─────
$c = '::schema_guard';
$db42 = pipe2_tmpdb_create(pipe2_base_dump());
$p42 = pipe2_tmpdb_pdo($db42);
$anyDeal = (int)$p42->query("SELECT id FROM deals WHERE phase = 'production' ORDER BY id LIMIT 1")->fetchColumn();
$err = '';
try {
    $g = pipe2_risk_many($p42, [['id' => $anyDeal]], P2RK_TODAY);
} catch (Throwable $e) {
    $err = $e->getMessage();
    $g = null;
}
t_eq($err, '', "$c numa base sem o esquema da v2: sem exceção");
t_eq($g, [$anyDeal => []], "$c numa base sem o esquema da v2: sem motivos");

t_done();
