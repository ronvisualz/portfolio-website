<?php
/**
 * Pipeline v2, fase 1 — a avença (D15, D4 dos sócios; tarefa 12; Review Focus 4).
 *
 * Valor = mensalidade × meses. Duas condições separadas: (a) a fatura do mês (regras fee_day da Em execução do funil
 * da frente, uma por regra e ciclo AAAA-MM de hoje, a partir do dia N — min(N, último dia do mês) —, também no mês em
 * que a frente começa e no 1.º cron depois de um dia falhado); (b) o recomeço do ciclo de produção (a frente em Pronto,
 * com o ciclo de um mês anterior, volta à 1.ª sub-etapa com o ciclo de hoje; fora de Pronto o ciclo continua e há um
 * aviso 'cycle' uma vez por mês). Conclui no fim do período ou ao cancelar (Pronto final). O relógio da avença
 * (pipe2_billing_tick) toma o lock de cada negócio e relê-o depois do lock.
 *
 * Os casos com pipe2_front_start usam o mês de hoje (m0) e os seguintes: o arranque grava o ciclo pelo dia real.
 * Cada caso deixa em produção só os seus negócios (os outros ficam «estacionados» em 'done', que o relógio não vê).
 *
 * Corre só em bases privadas (p2v_db e uma base na 42).
 *   php tests/pipe2_monthly_test.php
 * Casos: ::v1_noop ::value ::due_functions ::one_invoice_per_month ::short_month_and_missed_day ::cycle_restart
 *        ::cancel_and_end
 *        pelo flow_tick (tarefa 13): ::cycle_opens_once ::ends
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/tasks.php';
require_once __DIR__ . '/../admin/inc/pipeline.php';
require_once __DIR__ . '/../admin/inc/pipeline_flow.php';
require_once __DIR__ . '/../admin/inc/pipe2_engine.php';
require_once __DIR__ . '/../admin/inc/pipe2_deal.php';
if (is_file(__DIR__ . '/../admin/inc/pipe2_billing.php')) require_once __DIR__ . '/../admin/inc/pipe2_billing.php';

/** [classe, mensagem] da exceção de $fn, ou ['', '']. */
function p2m_err(callable $fn): array {
    try { $fn(); } catch (Throwable $e) { return [get_class($e), $e->getMessage()]; }
    return ['', ''];
}

/** O valor de uma coluna (NULL incluído); '(falta)' se a chave não existir. */
function p2m_v(array $row, string $k) {
    return array_key_exists($k, $row) ? $row[$k] : '(falta)';
}

function p2m_line(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deal_lines WHERE id = ?', [$id])[0] ?? [];
}

/** AAAA-MM mais $n meses. */
function p2m_ym(string $ym, int $n): string {
    return (new DateTimeImmutable($ym . '-01'))->modify(($n >= 0 ? '+' : '') . $n . ' months')->format('Y-m');
}

/** Os ciclos das tarefas da regra $rule no negócio, por ordem. */
function p2m_inv(PDO $pdo, int $deal, int $rule): array {
    return array_map('strval', array_column(p2v_rows($pdo, 'SELECT cycle_ym FROM tasks WHERE deal_id = ? AND auto_rule_id = ? ORDER BY cycle_ym, id', [$deal, $rule]), 'cycle_ym'));
}

/** Só os negócios $active ficam em produção; os outros dos testes ficam em 'done' (o relógio só vê os em produção). */
function p2m_only(PDO $pdo, array $all, array $active): void {
    $pdo->exec("UPDATE deals SET phase = 'done' WHERE id IN (" . pipe2_ids_sql($all) . ')');
    if ($active) $pdo->exec("UPDATE deals SET phase = 'production' WHERE id IN (" . pipe2_ids_sql($active) . ')');
}

/** O relógio da avença como um pedido novo. */
function p2m_tick(PDO $pdo, string $today): array {
    pipe2_request_reset();
    return pipe2_billing_tick($pdo, $today);
}

// ── a base privada, os funis, as regras e os negócios (tudo antes das leituras) ──
[$db, $tp] = p2v_db();
$m0 = substr(today_lisbon(), 0, 7);
$m1 = p2m_ym($m0, 1);

$wV = p2v_world($tp, ['cam']);   // ::value
$wD0 = p2v_world($tp, ['cam']);  // ::due_functions sem regras
$wD2 = p2v_world($tp, ['cam']);  // ::due_functions com duas regras
$wI = p2v_world($tp, ['cam']);   // ::one_invoice_per_month
$wS = p2v_world($tp, ['cam']);   // ::short_month_and_missed_day (N = 31)
$wM = p2v_world($tp, ['cam']);   // ::short_month_and_missed_day (N = 5, cron falhado)
$wR = p2v_world($tp, ['cam']);   // ::cycle_restart
$wC = p2v_world($tp, ['cam']);   // ::cancel_and_end
$wO = p2v_world($tp, ['cam']);   // ::cycle_opens_once (pelo flow_tick)
$wE = p2v_world($tp, ['cam']);   // ::ends (pelo flow_tick)
$SV = p2v_stages($tp, $wV['cam']);
$SD2 = p2v_stages($tp, $wD2['cam']);
$SI = p2v_stages($tp, $wI['cam']);
$SS = p2v_stages($tp, $wS['cam']);
$SM = p2v_stages($tp, $wM['cam']);
$SR = p2v_stages($tp, $wR['cam']);
$SC = p2v_stages($tp, $wC['cam']);
$SO = p2v_stages($tp, $wO['cam']);
$SE = p2v_stages($tp, $wE['cam']);
$fee = ['trig' => 'fee_day', 'doc_kind' => 'invoice', 'is_gate' => 0, 'title' => 'P2T · Fatura da avença', 'assign_to' => 'owner'];
p2v_rule($tp, $SD2['exec'], ['trig_n' => 10] + $fee);
p2v_rule($tp, $SD2['exec'], ['trig_n' => 5] + $fee);
p2v_rule($tp, $SD2['exec'], ['trig_n' => 2, 'is_active' => 0] + $fee);                 // desligada: não conta
p2v_rule($tp, $SD2['exec'], ['trig_n' => 3, 'trig' => 'f_enter'] + $fee);             // outro gatilho: não conta
$rI = p2v_rule($tp, $SI['exec'], ['trig_n' => 5] + $fee);
$rS = p2v_rule($tp, $SS['exec'], ['trig_n' => 31] + $fee);
$rM = p2v_rule($tp, $SM['exec'], ['trig_n' => 5] + $fee);
$rR = p2v_rule($tp, $SR['exec'], ['trig_n' => 5] + $fee);
$rRf = p2v_rule($tp, $SR['fronts'][0], ['trig' => 'f_enter', 'title' => 'P2T · arranque do ciclo', 'is_gate' => 1]);
$rO = p2v_rule($tp, $SO['exec'], ['trig_n' => 5] + $fee);

$v1f = pipe2_funnel($tp, 'v1 avença');
$dV1 = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1f]);
$lV1 = pipe2_front($tp, $dV1, $v1f, ['status' => 'active']);

$dV = p2v_deal($tp, $wV, ['cam'], ['at' => 'kickoff']);                                                        // ::value: Por começar, pontual
$dV2 = p2v_deal($tp, $wV, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'billing' => ['cam' => [500, 12]]]); // ::value: avença começada
$dD0 = p2v_deal($tp, $wD0, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'billing' => ['cam' => [500, 12]]]);
$dD2 = p2v_deal($tp, $wD2, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'billing' => ['cam' => [500, 12]]]);
$dI = p2v_deal($tp, $wI, ['cam'], ['at' => 'exec', 'status' => 'queued', 'billing' => ['cam' => [500, 12]]]);
$dS = p2v_deal($tp, $wS, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'billing' => ['cam' => [500, 12]]]);
$dM = p2v_deal($tp, $wM, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'billing' => ['cam' => [500, 12]]]);
$dR1 = p2v_deal($tp, $wR, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'front_at' => ['cam' => $SR['ready']], 'billing' => ['cam' => [500, 12]]]);
$dR2 = p2v_deal($tp, $wR, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'front_at' => ['cam' => $SR['fronts'][1]], 'billing' => ['cam' => [500, 12]]]);
$dC1 = p2v_deal($tp, $wC, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'billing' => ['cam' => [500, 12]]]);
$dC2 = p2v_deal($tp, $wC, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'billing' => ['cam' => [500, 12]]]);
$dO = p2v_deal($tp, $wO, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'billing' => ['cam' => [500, 12]]]);
$dE = p2v_deal($tp, $wE, ['cam'], ['at' => 'exec', 'status' => 'ongoing', 'billing' => ['cam' => [500, 12]]]);
$lR1 = $dR1['lines']['cam'];
$lR2 = $dR2['lines']['cam'];
p2v_front($tp, $lR1, ['exec_owner_id' => 99]);
p2v_front($tp, $lR2, ['exec_owner_id' => 99]);
$stepsR1 = p2v_steps($tp, $lR1);
$all = [$dV['id'], $dV2['id'], $dD0['id'], $dD2['id'], $dI['id'], $dS['id'], $dM['id'], $dR1['id'], $dR2['id'], $dC1['id'], $dC2['id'], $dO['id'], $dE['id']];
p2v_as(99);

// ── ::v1_noop ───────────────────────────────────────────────
$c = '::v1_noop';
p2m_only($tp, $all, []);
$sigT = ['tasks', 'deal_lines', 'deal_history', 'deal_line_steps', 'pipe2_notices', 'deals'];
$before = p2v_sig($tp, $sigT);
t_eq(p2m_tick($tp, $m1 . '-05'), ['opened' => 0, 'restarted' => 0, 'ended' => 0], "$c sem negócios v2 em produção, nada");
t_eq(p2v_sig($tp, $sigT), $before, "$c nenhuma linha muda (tarefas, frentes, histórico, passos, avisos, negócios)");
$db42 = pipe2_tmpdb_create(pipe2_base_dump());
$p42 = pipe2_tmpdb_pdo($db42);
$warn = [];
set_error_handler(function (int $no, string $msg) use (&$warn) { $warn[] = $msg; return true; });
[$cls, $msg] = p2m_err(fn() => t_eq(pipe2_billing_tick($p42, $m1 . '-05'), ['opened' => 0, 'restarted' => 0, 'ended' => 0], "$c numa base na 42, nada"));
restore_error_handler();
t_eq([$cls, $msg, $warn], ['', '', []], "$c numa base na 42, sem exceção nem aviso de PHP");

// ── ::value ─────────────────────────────────────────────────
$c = '::value';
p2m_only($tp, $all, [$dV['id'], $dV2['id']]);
$lV = $dV['lines']['cam'];
pipe2_billing_set($tp, deal_get($tp, $dV['id']), pipe2_front_get($tp, $lV), 500, 12, 99);
$L = p2m_line($tp, $lV);
t_eq([$L['billing'], (float)$L['monthly_fee'], (int)$L['fee_months'], (float)$L['value']], ['monthly', 500.0, 12, 6000.0], "$c 500 × 12: billing, mensalidade, meses, valor 6000");
t_eq((string)p2v_col($tp, 'SELECT billing FROM deals WHERE id = ?', [$dV['id']]), 'monthly', "$c deals.billing 'monthly'");
t_eq(pipe2_deal_value($tp, deal_get($tp, $dV['id'])), 6000.0, "$c pipe2_deal_value 6000 (só avenças: a soma)");
$H = p2v_rows($tp, "SELECT note, user_id FROM deal_history WHERE deal_id = ? AND event = 'p2_billing' ORDER BY id", [$dV['id']]);
t_eq(count($H), 1, "$c uma linha p2_billing no histórico");
t_eq((int)($H[0]['user_id'] ?? 0), 99, "$c de quem a fez");
t_true(!str_contains((string)($H[0]['note'] ?? ''), '€') && !str_contains((string)($H[0]['note'] ?? ''), '500'), "$c a nota do histórico não tem euros — obtido " . var_export($H[0]['note'] ?? null, true));
foreach ([[500, 0], [500, 121]] as [$f, $m]) {
    t_eq(p2m_err(fn() => pipe2_billing_set($tp, deal_get($tp, $dV['id']), pipe2_front_get($tp, $lV), $f, $m, 99)), ['RuntimeException', 'Indique entre 1 e 120 meses.'], "$c $m meses → recusa");
}
foreach ([0, -5] as $f) {
    t_eq(p2m_err(fn() => pipe2_billing_set($tp, deal_get($tp, $dV['id']), pipe2_front_get($tp, $lV), $f, 12, 99)), ['RuntimeException', 'Indique a mensalidade.'], "$c mensalidade $f → recusa");
}
t_eq((float)p2m_line($tp, $lV)['value'], 6000.0, "$c as recusas não mudaram nada");
t_eq(p2m_err(fn() => pipe2_billing_set($tp, deal_get($tp, $dV1), p2m_line($tp, $lV1), 500, 12, 99)), ['RuntimeException', 'Este negócio não é da Pipeline v2.'], "$c num negócio v1 → recusa");
t_eq((string)p2m_line($tp, $lV1)['billing'], '', "$c a frente v1 fica como estava");
t_eq(p2m_err(fn() => pipe2_billing_set($tp, deal_get($tp, $dV['id']), pipe2_front_get($tp, $dV2['lines']['cam']), 500, 12, 99)), ['RuntimeException', 'Frente não encontrada.'], "$c a frente de outro negócio → recusa");
// pontual outra vez (antes de começar)
pipe2_billing_clear($tp, deal_get($tp, $dV['id']), pipe2_front_get($tp, $lV), 99);
$L = p2m_line($tp, $lV);
t_eq([$L['billing'], $L['monthly_fee'], $L['fee_months']], ['', null, null], "$c pipe2_billing_clear: volta a pontual");
t_eq((string)p2v_col($tp, 'SELECT billing FROM deals WHERE id = ?', [$dV['id']]), '', "$c deals.billing volta a ''");
t_eq(p2m_err(fn() => pipe2_billing_clear($tp, deal_get($tp, $dV2['id']), pipe2_front_get($tp, $dV2['lines']['cam']), 99)),
     ['RuntimeException', 'A avença já começou: cancele-a em vez de a tirar.'], "$c depois de começar não se tira");
// mudar os meses de uma avença começada recalcula o fim do período
$L2 = p2m_line($tp, $dV2['lines']['cam']);
pipe2_billing_set($tp, deal_get($tp, $dV2['id']), pipe2_front_get($tp, (int)$L2['id']), 400, 6, 99);
$L2b = p2m_line($tp, (int)$L2['id']);
t_eq([(float)$L2b['value'], (string)$L2b['billing_end']], [2400.0, pipe2_billing_period(['fee_months' => 6], (string)$L2['started_at'])['end']],
     "$c avença começada, 400 × 6: valor 2400 e fim do período recalculado");

// ── ::due_functions (D15, puro) ─────────────────────────────
$c = '::due_functions';
$F = ['billing' => 'monthly', 'status' => 'ongoing', 'stage_role' => '', 'started_at' => '2026-11-03 10:00:00', 'cycle_ym' => '2026-11',
      'billing_end' => '2027-10-31', 'cancelled_at' => null, 'fee_months' => 12];
t_eq(pipe2_billing_invoice_due($F, '2026-11-04', 5), false, "$c fatura a 2026-11-04 (N 5) → não");
t_eq(pipe2_billing_invoice_due($F, '2026-11-05', 5), true, "$c fatura a 2026-11-05 → sim (o ciclo já ser 2026-11 não impede a do mês de início)");
t_eq(pipe2_billing_invoice_due($F, '2027-10-05', 5), true, "$c fatura no último mês do período → sim");
t_eq(pipe2_billing_invoice_due($F, '2027-11-05', 5), false, "$c fatura depois do período → não");
t_eq(pipe2_billing_invoice_due($F, '2026-10-05', 5), false, "$c fatura antes do mês de início → não");
t_eq(pipe2_billing_invoice_due(['cancelled_at' => '2026-12-01 10:00:00'] + $F, '2026-12-05', 5), false, "$c cancelada → não");
t_eq(pipe2_billing_invoice_due(['started_at' => null] + $F, '2026-11-05', 5), false, "$c sem started_at → não");
t_eq(pipe2_billing_invoice_due(['billing' => ''] + $F, '2026-11-05', 5), false, "$c não é avença → não");
t_eq([pipe2_billing_invoice_due(['billing_end' => null] + $F, '2027-10-05', 5), pipe2_billing_invoice_due(['billing_end' => null] + $F, '2027-11-05', 5)], [true, false],
     "$c sem billing_end, o fim do período vem de started_at e fee_months");
$P = ['stage_role' => 'ready'] + $F;
t_eq(pipe2_billing_restart_due($P, '2026-11-05', 5), false, "$c recomeço no mesmo mês → não");
t_eq(pipe2_billing_restart_due($P, '2026-12-04', 5), false, "$c recomeço com dia < N → não");
t_eq(pipe2_billing_restart_due($P, '2026-12-05', 5), true, "$c recomeço a 2026-12-05, em Pronto → sim");
t_eq(pipe2_billing_restart_due(['status' => 'running'] + $P, '2026-12-05', 5), false, "$c a frente em curso (running) → não");
t_eq(pipe2_billing_restart_due(['stage_role' => ''] + $F, '2026-12-05', 5), false, "$c Ativa fora de Pronto → não");
t_eq(pipe2_billing_restart_due(['billing_end' => '2026-11-30'] + $P, '2026-12-05', 5), false, "$c terminada → não");
t_eq(pipe2_billing_restart_due(['cancelled_at' => '2026-11-20 10:00:00'] + $P, '2026-12-05', 5), false, "$c cancelada → não");
t_eq(pipe2_billing_restart_due(['cycle_ym' => ''] + $P, '2026-12-05', 5), false, "$c sem ciclo → não");
t_eq([pipe2_billing_day_ok('2027-02-28', 31), pipe2_billing_day_ok('2027-02-27', 31), pipe2_billing_day_ok('2028-02-29', 30), pipe2_billing_day_ok('2028-02-28', 30)],
     [true, false, true, false], "$c pipe2_billing_day_ok: N maior do que o mês conta o último dia (também em ano bissexto)");
t_eq([pipe2_billing_day_ok('2026-12-05', 5), pipe2_billing_day_ok('2026-12-04', 5), pipe2_billing_day_ok('2026-12-01', 0), pipe2_billing_day_ok('2026-12-01', 1)],
     [true, false, true, true], "$c pipe2_billing_day_ok: dia ≥ N; N ≤ 0 conta como 1");
t_eq(pipe2_billing_day($tp, p2m_line($tp, $dD0['lines']['cam'])), 1, "$c pipe2_billing_day sem regras fee_day → 1");
t_eq(pipe2_billing_day($tp, p2m_line($tp, $dD2['lines']['cam'])), 5, "$c com duas (10 e 5; uma desligada e outra de outro gatilho não contam) → 5");

// ── ::one_invoice_per_month (D15 a, D12) ────────────────────
$c = '::one_invoice_per_month';
p2m_only($tp, $all, [$dI['id']]);
$lI = $dI['lines']['cam'];
pipe2_request_reset();
t_eq(pipe2_front_start($tp, deal_get($tp, $dI['id']), pipe2_front_get($tp, $lI), 99), 'entered', "$c pipe2_front_start começa a avença");
$L = p2m_line($tp, $lI);
t_eq([$L['status'], (string)$L['cycle_ym']], ['ongoing', $m0], "$c Ativa, com o ciclo do mês de início ($m0)");
p2v_front($tp, $lI, ['started_at' => $m0 . '-03 09:00:00']);
t_eq(p2m_tick($tp, $m0 . '-04'), ['opened' => 0, 'restarted' => 0, 'ended' => 0], "$c dia 4 (N 5): nada");
t_eq(p2m_inv($tp, $dI['id'], $rI), [], "$c nenhuma fatura");
t_eq(p2m_tick($tp, $m0 . '-05'), ['opened' => 1, 'restarted' => 0, 'ended' => 0], "$c dia 5: uma fatura");
t_eq(p2m_inv($tp, $dI['id'], $rI), [$m0], "$c a fatura do mês de início, ciclo $m0");
$T = p2v_rows($tp, 'SELECT line_id, stage_id, doc_kind, is_gate, status FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dI['id'], $rI])[0] ?? [];
t_eq([p2m_v($T, 'line_id'), (int)($T['stage_id'] ?? 0), $T['doc_kind'] ?? '', (int)($T['is_gate'] ?? -1), $T['status'] ?? ''], [null, $SI['exec'], 'invoice', 0, 'todo'],
     "$c tarefa do negócio (sem frente), na Em execução, pedido de fatura, não obrigatória, por fazer");
t_eq(p2m_tick($tp, $m0 . '-05'), ['opened' => 0, 'restarted' => 0, 'ended' => 0], "$c outra vez no mesmo dia: nada");
t_eq(p2m_tick($tp, $m0 . '-20'), ['opened' => 0, 'restarted' => 0, 'ended' => 0], "$c mais tarde no mesmo mês: nada");
t_eq(p2m_tick($tp, $m1 . '-05'), ['opened' => 1, 'restarted' => 0, 'ended' => 0], "$c dia 5 do mês seguinte: a 2.ª");
t_eq(p2m_inv($tp, $dI['id'], $rI), [$m0, $m1], "$c uma por mês ($m0, $m1)");

// ── ::cycle_opens_once (T2, D15 a: pelo flow_tick) ──────────
$c = '::cycle_opens_once';
p2m_only($tp, $all, [$dO['id']]);
$flow = function (string $day) use ($tp): void { pipe2_request_reset(); flow_tick($tp, $day); };
$flow($m1 . '-04');
t_eq(p2m_inv($tp, $dO['id'], $rO), [], "$c flow_tick a $m1-04 (N 5): nenhuma fatura");
$flow($m1 . '-05');
t_eq(p2m_inv($tp, $dO['id'], $rO), [$m1], "$c flow_tick a $m1-05: a fatura do ciclo $m1 (o relógio da v2 corre no relógio diário)");
$flow($m1 . '-05');
$flow($m1 . '-20');
t_eq(p2m_inv($tp, $dO['id'], $rO), [$m1], "$c outra vez no mesmo dia e mais tarde no mês: continua uma só");
$T = p2v_rows($tp, 'SELECT line_id, stage_id, doc_kind FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dO['id'], $rO])[0] ?? [];
t_eq([p2m_v($T, 'line_id'), (int)($T['stage_id'] ?? 0), $T['doc_kind'] ?? ''], [null, $SO['exec'], 'invoice'], "$c tarefa do negócio, na Em execução, pedido de fatura");

// ── ::short_month_and_missed_day (Review Focus 4) ───────────
$c = '::short_month_and_missed_day';
p2m_only($tp, $all, [$dS['id'], $dM['id']]);
// N = 5 e o cron falhou os dias 5, 6 e 7: abre no 1.º cron depois, uma só vez
p2m_tick($tp, $m1 . '-04');
t_eq(p2m_inv($tp, $dM['id'], $rM), [], "$c N 5: a $m1-04 nada");
p2m_tick($tp, $m1 . '-08');
t_eq(p2m_inv($tp, $dM['id'], $rM), [$m1], "$c N 5, cron falhado: abre a $m1-08");
p2m_tick($tp, $m1 . '-09');
t_eq(p2m_inv($tp, $dM['id'], $rM), [$m1], "$c e só uma vez ($m1-09: nada de novo)");
// N = 31 num mês mais curto: abre no último dia do mês
$x = null;
for ($i = 0; $i < 12; $i++) {
    $ym = p2m_ym($m0, $i);
    if ((int)date('t', strtotime($ym . '-01')) < 31) { $x = $ym; break; }
}
$last = (int)date('t', strtotime($x . '-01'));
p2m_tick($tp, $x . '-' . sprintf('%02d', $last - 1));
t_eq(in_array($x, p2m_inv($tp, $dS['id'], $rS), true), false, "$c N 31: a $x-" . ($last - 1) . ' nada');
p2m_tick($tp, $x . '-' . sprintf('%02d', $last));
t_eq(array_values(array_filter(p2m_inv($tp, $dS['id'], $rS), fn($y) => $y === $x)), [$x], "$c N 31: abre a $x-$last (o último dia do mês)");
p2m_tick($tp, $x . '-' . sprintf('%02d', $last));
t_eq(array_values(array_filter(p2m_inv($tp, $dS['id'], $rS), fn($y) => $y === $x)), [$x], "$c e só uma vez");
t_eq(pipe2_billing_invoice_due(['billing' => 'monthly', 'started_at' => '2026-11-03', 'billing_end' => '2027-10-31', 'cancelled_at' => null, 'fee_months' => 12], '2027-02-28', 31), true,
     "$c (puro) N 31 a 2027-02-28 → sim");

// ── ::cycle_restart (D15 b) ─────────────────────────────────
$c = '::cycle_restart';
p2m_only($tp, $all, [$dR1['id'], $dR2['id']]);
t_eq(p2m_tick($tp, $m1 . '-04')['restarted'], 0, "$c dia 4 (N 5): não recomeça");
t_eq([(int)p2m_line($tp, $lR1)['stage_id'], (string)p2m_line($tp, $lR1)['cycle_ym']], [$SR['ready'], $m0], "$c a frente fica em Pronto, no ciclo $m0");
$r = p2m_tick($tp, $m1 . '-05');
t_eq($r['restarted'], 1, "$c dia 5: uma frente recomeça — obtido " . json_encode($r));
$L = p2m_line($tp, $lR1);
t_eq([(int)$L['stage_id'], $L['status'], (string)$L['cycle_ym']], [$SR['fronts'][0], 'ongoing', $m1], "$c em Pronto: volta à 1.ª sub-etapa, Ativa, com o ciclo $m1");
t_eq(p2v_col($tp, 'SELECT done_at FROM deal_line_steps WHERE id = ?', [$stepsR1[$SR['ready']]]) !== null, true, "$c a linha de passos de Pronto do ciclo $m0 fica concluída");
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM deal_line_steps WHERE line_id = ? AND cycle_ym = ?', [$lR1, $m1]), count($SR['fronts']), "$c uma linha de passos por sub-etapa no ciclo $m1");
$TF = p2v_rows($tp, 'SELECT line_id, stage_id, cycle_ym FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dR1['id'], $rRf]);
t_eq(array_map(fn($t) => [(int)$t['line_id'], (int)$t['stage_id'], (string)$t['cycle_ym']], $TF), [[$lR1, $SR['fronts'][0], $m1]], "$c a tarefa f_enter do ciclo novo");
$HF = p2v_rows($tp, "SELECT note, user_id FROM deal_history WHERE deal_id = ? AND event = 'p2_front' ORDER BY id DESC LIMIT 1", [$dR1['id']])[0] ?? [];
t_eq([p2m_v($HF, 'user_id'), str_contains((string)($HF['note'] ?? ''), PIPE2_AUTO_NOTE)], [null, true], "$c movimento do «Sistema» (p2_front, Avanço automático)");
$L2 = p2m_line($tp, $lR2);
t_eq([(int)$L2['stage_id'], (string)$L2['cycle_ym']], [$SR['fronts'][1], $m0], "$c fora de Pronto: o ciclo $m0 continua");
$N = p2v_rows($tp, "SELECT user_id, cycle_ym, line_id FROM pipe2_notices WHERE deal_id = ? AND kind = 'cycle'", [$dR2['id']]);
t_eq(array_map(fn($n) => [(int)$n['user_id'], (string)$n['cycle_ym'], (int)$n['line_id']], $N), [[99, $m1, $lR2]], "$c um aviso 'cycle' ao responsável da frente");
t_eq(p2m_tick($tp, $m1 . '-06')['restarted'], 0, "$c outra vez: não recomeça de novo");
t_eq(count(p2v_rows($tp, "SELECT id FROM pipe2_notices WHERE deal_id = ? AND kind = 'cycle'", [$dR2['id']])), 1, "$c o aviso fica um só");
t_eq(count(p2v_rows($tp, 'SELECT id FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dR1['id'], $rRf])), 1, "$c a tarefa f_enter não se repete");
t_eq(p2m_inv($tp, $dR1['id'], $rR), [$m1], "$c e a fatura do mês abre uma vez (condição separada)");

// ── ::cancel_and_end (D15) ──────────────────────────────────
$c = '::cancel_and_end';
p2m_only($tp, $all, [$dC1['id'], $dC2['id']]);
$lC1 = $dC1['lines']['cam'];
$before = p2m_line($tp, $lC1);
t_eq(p2m_err(fn() => pipe2_billing_cancel($tp, deal_get($tp, $dC1['id']), pipe2_front_get($tp, $lC1), '   ', 99)), ['RuntimeException', 'Indique o motivo.'], "$c sem motivo → recusa");
t_eq(p2m_line($tp, $lC1), $before, "$c a recusa não muda nada");
pipe2_request_reset();
pipe2_billing_cancel($tp, deal_get($tp, $dC1['id']), pipe2_front_get($tp, $lC1), 'Cliente desistiu', 99);
$L = p2m_line($tp, $lC1);
$today = today_lisbon();
t_eq([$L['cancelled_at'] !== null, $L['status'], (int)$L['stage_id'], (string)$L['billing_end']], [true, 'ready', $SC['ready'], min((string)$before['billing_end'], $today)],
     "$c com motivo: cancelada, Pronto final, fim do período = hoje");
$HB = p2v_rows($tp, "SELECT note, user_id FROM deal_history WHERE deal_id = ? AND event = 'p2_billing' ORDER BY id", [$dC1['id']]);
t_eq(array_map(fn($h) => [(string)$h['note'], (int)$h['user_id']], $HB), [['Avença cancelada: Cliente desistiu', 99]], "$c histórico «Avença cancelada: <motivo>»");
t_eq(p2m_err(fn() => pipe2_billing_cancel($tp, deal_get($tp, $dC1['id']), pipe2_front_get($tp, $lC1), 'outra vez', 99)), ['RuntimeException', 'A avença já foi cancelada.'], "$c cancelar outra vez → recusa");
// o fim do período ontem e o relógio hoje (datas reais: a reavaliação do negócio conta o dia de hoje)
$lC2 = $dC2['lines']['cam'];
$yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
p2v_front($tp, $lC2, ['billing_end' => $yesterday]);
$r = p2m_tick($tp, $today);
t_eq($r['ended'], 1, "$c a frente com o fim do período ontem ($yesterday) termina no relógio — obtido " . json_encode($r));
$L = p2m_line($tp, $lC2);
t_eq([$L['status'], (int)$L['stage_id']], ['ready', $SC['ready']], "$c Pronto final");
$HF = p2v_rows($tp, "SELECT note, user_id FROM deal_history WHERE deal_id = ? AND event = 'p2_front' ORDER BY id DESC LIMIT 1", [$dC2['id']])[0] ?? [];
t_eq([p2m_v($HF, 'user_id'), str_contains((string)($HF['note'] ?? ''), 'Avença terminou')], [null, true], "$c «Sistema», com a nota «Avença terminou»");
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deals WHERE id = ?', [$dC2['id']]), $SC['delivery'], "$c a única frente concluída: o negócio passa à Entrega");
t_eq(p2m_tick($tp, $today)['ended'], 0, "$c outra vez: nada");
t_eq(p2m_inv($tp, $dC1['id'], 0) === [] && (int)p2v_col($tp, "SELECT COUNT(*) FROM tasks WHERE deal_id IN (?, ?) AND doc_kind = 'invoice'", [$dC1['id'], $dC2['id']]) === 0, true,
     "$c sem faturas (o funil não tem regras fee_day)");

// ── ::ends (D4/D15: pelo flow_tick) ─────────────────────────
$c = '::ends';
p2m_only($tp, $all, [$dE['id']]);
$lE = $dE['lines']['cam'];
p2v_front($tp, $lE, ['billing_end' => $yesterday]);
t_eq([p2m_line($tp, $lE)['status'], (int)p2v_col($tp, 'SELECT stage_id FROM deals WHERE id = ?', [$dE['id']])], ['ongoing', $SE['exec']], "$c (antes) Ativa, o negócio em Em execução");
pipe2_request_reset();
flow_tick($tp, $today);
$L = p2m_line($tp, $lE);
t_eq([$L['status'], (int)$L['stage_id']], ['ready', $SE['ready']], "$c o fim do período ontem: o flow_tick põe a frente em Pronto final");
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deals WHERE id = ?', [$dE['id']]), $SE['delivery'], "$c e leva o negócio à Entrega");
t_eq(p2v_rows($tp, "SELECT user_id, note FROM deal_history WHERE deal_id = ? AND event = 'stage' ORDER BY id", [$dE['id']]), [['user_id' => null, 'note' => 'Todas as frentes prontas']],
     "$c pelo «Sistema»");

t_done();
