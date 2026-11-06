<?php
/**
 * Pipeline v2, fase 1 — o fecho do negócio v2 (§34.11 T1, D10, D16; tarefa 11, a tarefa 12 acrescenta
 * ::is_paid_on_delivery_rule).
 *
 * As regras de fecho (as 4 de PIPE_RULES_DELIVERY e «Validar pagamento final») correm na Entrega do negócio uma vez,
 * juntas pela deduplicação das Entregas de todos os funis das frentes; as de fatura e de pagamento só do funil
 * principal. Todas ficam do negócio (line_id NULL), na Entrega do negócio — também a obrigatória que só existe na
 * Entrega de outro funil, que bloqueia o Concluído automático até estar feita.
 *
 * Corre só numa base privada (p2v_db).
 *   php tests/pipe2_close_test.php
 * ::is_paid_on_delivery_rule (tarefa 12): num negócio v2, o pagamento final é o da Entrega (role 'delivery') e marca
 * deals.is_paid; o pagamento de um ciclo (uma tarefa da Em execução) não. Num v1, o de Concluído, como hoje.
 *
 * Casos: ::once_per_deal ::other_funnel_mandatory_blocks_done ::is_paid_on_delivery_rule
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/tasks.php';
require_once __DIR__ . '/../admin/inc/pipeline.php';
require_once __DIR__ . '/../admin/inc/pipeline_flow.php';
require_once __DIR__ . '/../admin/inc/pipe2_engine.php';
if (is_file(__DIR__ . '/../admin/inc/pipe2_deal.php')) require_once __DIR__ . '/../admin/inc/pipe2_deal.php';

function p2c_n(PDO $pdo, string $sql, array $a = []): int {
    return (int)p2v_col($pdo, $sql, $a);
}

/** O id da tarefa do negócio cujo título começa assim, ou 0. */
function p2c_task(PDO $pdo, int $deal, string $start): int {
    return (int)p2v_col($pdo, 'SELECT id FROM tasks WHERE deal_id = ? AND title LIKE ? ORDER BY id LIMIT 1', [$deal, $start . '%']);
}

/** Fecha as 5 de fecho como na plataforma: as normais por task_done, a fatura e o pagamento pelo documento. */
function p2c_close_five(PDO $pdo, int $deal): void {
    foreach (['Aprovação final', 'Enviar entregáveis finais', 'Documento de direitos'] as $t) {
        pipe2_request_reset();
        task_done($pdo, p2c_task($pdo, $deal, $t));
    }
    pipe2_request_reset();
    flow_complete_doc_tasks($pdo, $deal, 'invoice');
    pipe2_request_reset();
    flow_complete_doc_tasks($pdo, $deal, 'payment');
}

// ── a base privada, os funis e as regras (tudo antes das leituras) ──
[$db, $tp] = p2v_db();
$wa = p2v_world($tp, ['vfx', '3d']);   // ::once_per_deal
$wb = p2v_world($tp, ['vfx', '3d']);   // ::other_funnel_mandatory_blocks_done
$A = p2v_stages($tp, $wa['vfx']);
$A3 = p2v_stages($tp, $wa['3d']);
$B = p2v_stages($tp, $wb['vfx']);
$B3 = p2v_stages($tp, $wb['3d']);
$closeA = p2v_closing_rules($tp, $A['delivery']);
$closeA3 = p2v_closing_rules($tp, $A3['delivery']);
$closeB = p2v_closing_rules($tp, $B['delivery']);
$closeB3 = p2v_closing_rules($tp, $B3['delivery']);
$only3 = p2v_rule($tp, $B3['delivery'], ['title' => 'P2T · só no 3D', 'trig' => 'enter', 'assign_to' => 'owner', 'is_gate' => 1]);
// ::is_paid_on_delivery_rule
$wp = p2v_world($tp, ['vfx']);
$P = p2v_stages($tp, $wp['vfx']);
$dPay = p2v_deal($tp, $wp, ['vfx'], ['at' => 'delivery', 'status' => 'ready']);
$tPayCycle = p2v_task($tp, $dPay['id'], ['stage_id' => $P['exec'], 'doc_kind' => 'payment', 'cycle_ym' => '2026-11', 'title' => 'Validar pagamento do mês']);
$tPayFinal = p2v_task($tp, $dPay['id'], ['stage_id' => $P['delivery'], 'doc_kind' => 'payment', 'is_gate' => 1, 'title' => 'Validar pagamento final']);
$v1f = pipe2_funnel($tp, 'v1 pagamento');
$v1done = funnel_fixed_stage($tp, $v1f, 'done');
$v1kick = funnel_fixed_stage($tp, $v1f, 'kickoff');
$dV1 = pipe2_deal($tp, ['phase' => 'done', 'funnel_id' => $v1f]);
$tV1Final = p2v_task($tp, $dV1, ['stage_id' => $v1done, 'doc_kind' => 'payment', 'is_gate' => 1, 'title' => 'Validar pagamento final']);
$dV1b = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1f]);
$tV1Kick = p2v_task($tp, $dV1b, ['stage_id' => $v1kick, 'doc_kind' => 'payment', 'title' => 'Validar pagamento inicial']);
p2v_as(99);

// ── ::once_per_deal (T1, D10) ───────────────────────────────
$c = '::once_per_deal';
pipe2_request_reset();
$d = p2v_deal($tp, $wa, ['vfx', '3d'], ['at' => 'exec', 'status' => 'ready']);
t_eq(pipe2_deal_reevaluate($tp, $d['id']), 'to_delivery', "$c as duas frentes Prontas: o negócio entra na Entrega");
$rows = p2v_rows($tp, 'SELECT id, auto_rule_id, line_id, stage_id, doc_kind FROM tasks WHERE deal_id = ? ORDER BY id', [$d['id']]);
t_eq(count($rows), 5, "$c 5 tarefas no total");
t_eq(array_values(array_unique(array_map(fn($r) => (int)$r['auto_rule_id'], $rows))), $closeA, "$c todas das regras do funil principal (as do 3D deduplicadas)");
t_eq(array_values(array_unique(array_map(fn($r) => [$r['line_id'], (int)$r['stage_id']], $rows), SORT_REGULAR)), [[null, $A['delivery']]],
     "$c nenhuma com line_id, todas na Entrega do negócio");
t_eq(array_values(array_filter(array_map(fn($r) => $r['doc_kind'], $rows))), ['invoice', 'payment'], "$c uma fatura e um pagamento (só do principal)");
t_eq(p2c_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id IN (' . implode(',', $closeA3) . ')', [$d['id']]), 0, "$c nenhuma das regras da Entrega do 3D");
pipe2_request_reset();
pipe2_deal_reevaluate($tp, $d['id'], 'done');
t_eq(p2c_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ?', [$d['id']]), 5, "$c reavaliar na Entrega não cria mais");

// ── ::other_funnel_mandatory_blocks_done (D10) ──────────────
$c = '::other_funnel_mandatory_blocks_done';
pipe2_request_reset();
$d = p2v_deal($tp, $wb, ['vfx', '3d'], ['at' => 'exec', 'status' => 'ready']);
t_eq(pipe2_deal_reevaluate($tp, $d['id']), 'to_delivery', "$c o negócio entra na Entrega");
$t3 = p2v_rows($tp, 'SELECT id, stage_id, line_id, is_gate FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$d['id'], $only3]);
t_eq(array_map(fn($r) => [(int)$r['stage_id'], $r['line_id'], (int)$r['is_gate']], $t3), [[$B['delivery'], null, 1]],
     "$c a obrigatória que só existe na Entrega do 3D fica do negócio, na Entrega do negócio");
t_eq(p2c_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ?', [$d['id']]), 6, "$c 6 tarefas (as 5 de fecho e a do 3D)");
p2c_close_five($tp, $d['id']);
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deals WHERE id = ?', [$d['id']]), $B['delivery'], "$c fechadas as 5 com ela por fazer, o negócio fica na Entrega");
t_true(in_array('Pronto para concluir — falta: P2T · só no 3D', pipe2_note(), true), "$c a nota «Pronto para concluir — falta: P2T · só no 3D» — obtido " . var_export(pipe2_note(), true));
pipe2_request_reset();
t_eq(pipe2_deal_reevaluate($tp, $d['id']), 'blocked', "$c pipe2_deal_reevaluate → blocked");
t_eq(pipe2_close_missing($tp, deal_get($tp, $d['id'])), ['P2T · só no 3D'], "$c pipe2_close_missing lista-a");
pipe2_request_reset();
task_done($tp, (int)$t3[0]['id']);
$D = p2v_rows($tp, 'SELECT phase, stage_id FROM deals WHERE id = ?', [$d['id']])[0] ?? [];
t_eq([$D['phase'] ?? '', (int)($D['stage_id'] ?? 0)], ['done', $B['done']], "$c fechá-la leva ao Concluído automático");

// ── ::is_paid_on_delivery_rule (T1, D16) ────────────────────
$c = '::is_paid_on_delivery_rule';
$pay = fn(int $deal, int $task) => billing_validate_payment($tp, deal_get($tp, $deal), task_by_id($tp, $task), ['doc_date' => today_lisbon(), 'total' => '100'], null, 99);
$paid = fn(int $deal) => (int)p2v_col($tp, 'SELECT is_paid FROM deals WHERE id = ?', [$deal]);
pipe2_request_reset();
$pay($dPay['id'], $tPayCycle);
t_eq([(string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [$tPayCycle]), $paid($dPay['id'])], ['done', 0], "$c v2: o pagamento de um ciclo (Em execução) fica feito e o negócio não fica pago");
pipe2_request_reset();
$pay($dPay['id'], $tPayFinal);
t_eq([(string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [$tPayFinal]), $paid($dPay['id'])], ['done', 1], "$c v2: o pagamento final da Entrega marca o negócio pago");
pipe2_request_reset();
$pay($dV1b, $tV1Kick);
t_eq($paid($dV1b), 0, "$c v1: um pagamento fora de Concluído não marca, como hoje");
pipe2_request_reset();
$pay($dV1, $tV1Final);
t_eq($paid($dV1), 1, "$c v1: o de Concluído marca o negócio pago, como hoje");

t_done();
