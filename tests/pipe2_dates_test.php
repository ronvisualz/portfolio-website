<?php
/**
 * Pipeline v2, fase 1 — plano, replaneamento e datas (admin/inc/pipe2_deps.php; tarefa 5: D17; E7, E8).
 *
 * A entrega da frente é a dela (deal_lines.delivery_date) se estiver preenchida, senão a do negócio. Gravar uma data
 * comum põe-na no negócio e limpa as das frentes; gravar por frente põe as das frentes e o negócio fica com a maior.
 * Os prazos das tarefas de frente que contam a partir da entrega usam a da frente, e as que mudam gravam updated_at
 * (o Google Calendar acompanha). O plano das sub-etapas é em dias úteis, contíguo (cada sub-etapa começa no dia em que
 * a anterior acaba: o fim é o início + N dias úteis, como o prazo «+N dias úteis» das tarefas), com os dias da linha
 * de passos senão os da sub-etapa (sem dias, sem plano). Quando a sub-etapa atual passa o fim, as seguintes deslizam
 * pelos dias úteis de atraso (uma vez por dia de atraso, somados em replanned_days); a entrega não muda.
 *
 * Corre numa base privada do dump da 42, migrada até à SCHEMA_VERSION (p2v_db); não escreve na bancada.
 *
 * Casos: ::front_delivery ::deal_delivery ::common_or_per_front ::front_tasks_use_front_delivery ::plan_and_replan
 *        ::plan_days_override ::replan_rules ::plan_save_v2 ::v1_noop ::strict
 *        ganchos do crosselling (tarefa 13): ::vdeal_front_delivery ::save_plan_v2
 *
 * Uso: php tests/pipe2_dates_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2_deps.php';
require_once __DIR__ . '/../admin/inc/xsell.php';

/** A mensagem da exceção de $fn, ou ''. */
function p2dt_err(callable $fn): string {
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
}

function p2dt_deal(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deals WHERE id = ?', [$id])[0];
}

function p2dt_line(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deal_lines WHERE id = ?', [$id])[0];
}

/** As datas de entrega: [negócio, [código => frente]]. */
function p2dt_dates(PDO $pdo, array $d): array {
    $lines = [];
    foreach ($d['lines'] as $code => $lid) $lines[$code] = p2v_col($pdo, 'SELECT delivery_date FROM deal_lines WHERE id = ?', [$lid]) ?: null;
    return [p2v_col($pdo, 'SELECT delivery_date FROM deals WHERE id = ?', [$d['id']]) ?: null, $lines];
}

/** O plano de uma frente: [sub-etapa => [plan_start, plan_end, replanned_days]]. */
function p2dt_plan(PDO $pdo, int $lineId): array {
    $out = [];
    foreach (p2v_rows($pdo, 'SELECT stage_id, plan_start, plan_end, replanned_days FROM deal_line_steps WHERE line_id = ? ORDER BY id', [$lineId]) as $r) {
        $out[(int)$r['stage_id']] = [$r['plan_start'], $r['plan_end'], (int)$r['replanned_days']];
    }
    return $out;
}

/** O prazo e o updated_at de uma tarefa. */
function p2dt_task(PDO $pdo, int $id): array {
    $r = p2v_rows($pdo, 'SELECT due_date, updated_at FROM tasks WHERE id = ?', [$id])[0];
    return [$r['due_date'], $r['updated_at']];
}

function p2dt_hist(PDO $pdo, int $dealId, string $event): array {
    return p2v_rows($pdo, 'SELECT note, user_id FROM deal_history WHERE deal_id = ? AND event = ? ORDER BY id', [$dealId, $event]);
}

const P2DT_OLD = '2026-01-01 00:00:00';

// ── a base privada, os funis, os negócios e as regras (tudo antes das leituras) ──
[$db, $tp] = p2v_db();
$w = p2v_world($tp, ['vfx', '3d']);
$S = array_map(fn($fid) => p2v_stages($tp, $fid), $w);
$vfxPlan = p2v_funnel($tp, 'vfx', ['plan_days' => [2, 3, 4, 2, 1, 0]]);
$SP = p2v_stages($tp, $vfxPlan);
$wp = ['vfx' => $vfxPlan];

$dDates  = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'delivery_date' => '2026-12-01']);
$dMerged = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
$dTasks  = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'delivery_date' => '2026-12-01']);
$dPlan   = p2v_deal($tp, $wp, ['vfx'], ['at' => 'exec', 'delivery_date' => '2026-12-31']);
$dPlan2  = p2v_deal($tp, $wp, ['vfx'], ['at' => 'exec']);
$dWeek   = p2v_deal($tp, $wp, ['vfx'], ['at' => 'exec']);
$dNoPlan = p2v_deal($tp, $w, ['3d'], ['at' => 'exec']);
$dQueued = p2v_deal($tp, $wp, ['vfx'], ['at' => 'kickoff']);
$dReady  = p2v_deal($tp, $wp, ['vfx'], ['at' => 'exec', 'status' => 'ready']);
$dSave   = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'delivery_date' => '2026-12-01']);
$dDone   = p2v_deal($tp, $w, ['vfx'], ['at' => 'done']);
$dStrict = p2v_deal($tp, $wp, ['vfx'], ['at' => 'exec', 'delivery_date' => '2026-12-31']);
$dXsell  = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'delivery_date' => '2026-12-01']);

// as regras com prazo pela entrega: uma de frente (sub-etapa 1 da VFX e do 3D) e uma de negócio (Em execução)
$due = ['due_mode' => 'delivery', 'due_days' => -2, 'due_unit' => 'd'];
$rVfx = p2v_rule($tp, $S['vfx']['fronts'][0], $due);
$r3d = p2v_rule($tp, $S['3d']['fronts'][0], $due);
$rDeal = p2v_rule($tp, $S['vfx']['exec'], $due + ['trig' => 'enter']);
$tVfx = p2v_task($tp, $dTasks['id'], ['line_id' => $dTasks['lines']['vfx'], 'stage_id' => $S['vfx']['fronts'][0], 'auto_rule_id' => $rVfx, 'due_date' => '2026-01-01']);
$t3d = p2v_task($tp, $dTasks['id'], ['line_id' => $dTasks['lines']['3d'], 'stage_id' => $S['3d']['fronts'][0], 'auto_rule_id' => $r3d, 'due_date' => '2026-01-01']);
$tDeal = p2v_task($tp, $dTasks['id'], ['stage_id' => $S['vfx']['exec'], 'auto_rule_id' => $rDeal, 'due_date' => '2026-01-01']);
$tDone = p2v_task($tp, $dTasks['id'], ['line_id' => $dTasks['lines']['vfx'], 'stage_id' => $S['vfx']['fronts'][0], 'auto_rule_id' => $rVfx, 'due_date' => '2026-01-01',
                                       'status' => 'done']);
$tp->exec("UPDATE tasks SET updated_at = '" . P2DT_OLD . "' WHERE id IN ($tVfx, $t3d, $tDeal, $tDone)");

// um negócio v1 (VFX real, crosselling com o CGI real, frentes active, sincronizadas) com tarefas pela entrega
$realVfx = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$real3d = (int)p2v_col($tp, "SELECT id FROM funnels WHERE service_code = '3d' AND is_base = 1 ORDER BY id LIMIT 1");
$v1 = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $realVfx, 'owner_id' => 99, 'sync_mode' => 'sync', 'delivery_date' => '2026-12-15']);
$v1Main = pipe2_front($tp, $v1, $realVfx, ['is_main' => 1, 'status' => 'active']);
$v1Line = pipe2_front($tp, $v1, $real3d, ['is_main' => 0, 'status' => 'active', 'delivery_date' => '2026-12-01']);
$v1Kick = funnel_fixed_stage($tp, $realVfx, 'kickoff');
$v1Kick3 = funnel_fixed_stage($tp, $real3d, 'kickoff');
$rv1 = p2v_rule($tp, $v1Kick, $due + ['trig' => 'enter']);
$rv13 = p2v_rule($tp, $v1Kick3, $due + ['trig' => 'enter']);
$tv1 = p2v_task($tp, $v1, ['stage_id' => $v1Kick, 'auto_rule_id' => $rv1, 'due_date' => '2026-01-01']);
$tv1l = p2v_task($tp, $v1, ['line_id' => $v1Line, 'stage_id' => $v1Kick3, 'auto_rule_id' => $rv13, 'due_date' => '2026-01-01']);

// ── ::front_delivery (puro) ─────────────────────────────────
t_eq(pipe2_front_delivery(['delivery_date' => '2026-12-15'], ['delivery_date' => null]), '2026-12-15', '::front_delivery sem data da frente → a do negócio');
t_eq(pipe2_front_delivery(['delivery_date' => '2026-12-15'], ['delivery_date' => '2026-12-10']), '2026-12-10', '::front_delivery a da frente, quando preenchida');
t_eq(pipe2_front_delivery(['delivery_date' => '2026-12-15'], ['delivery_date' => '']), '2026-12-15', '::front_delivery vazia conta como sem data');
t_eq(pipe2_front_delivery(['delivery_date' => null], ['delivery_date' => null]), null, '::front_delivery nenhuma → null');
t_eq(pipe2_front_delivery(['delivery_date' => '2026-12-15', 'sync_mode' => 'sync'], ['delivery_date' => '2026-12-10']), '2026-12-10',
     '::front_delivery não depende de sync_mode');

// ── ::deal_delivery ─────────────────────────────────────────
p2v_front($tp, $dMerged['lines']['vfx'], ['delivery_date' => '2026-12-10']);
p2v_front($tp, $dMerged['lines']['3d'], ['delivery_date' => '2026-12-20']);
t_eq(pipe2_deal_delivery($tp, p2dt_deal($tp, $dMerged['id'])), '2026-12-20', '::deal_delivery sem data comum → a maior das frentes');
t_eq(pipe2_deal_delivery($tp, ['delivery_date' => '2027-01-05'] + p2dt_deal($tp, $dMerged['id'])), '2027-01-05', '::deal_delivery a do negócio, quando preenchida');
p2v_front($tp, $dMerged['lines']['3d'], ['status' => 'merged']);
t_eq(pipe2_deal_delivery($tp, p2dt_deal($tp, $dMerged['id'])), '2026-12-10', '::deal_delivery uma frente juntada não conta');
p2v_front($tp, $dMerged['lines']['vfx'], ['delivery_date' => null]);
t_eq(pipe2_deal_delivery($tp, p2dt_deal($tp, $dMerged['id'])), null, '::deal_delivery sem nenhuma → null');

// ── ::common_or_per_front ───────────────────────────────────
$deal = p2dt_deal($tp, $dDates['id']);
p2v_front($tp, $dDates['lines']['vfx'], ['delivery_date' => '2026-11-30']);
t_eq(pipe2_dates_save($tp, $deal, '2026-12-15', []), 0, '::common_or_per_front comum: nenhuma tarefa com prazo');
t_eq(p2dt_dates($tp, $dDates), ['2026-12-15', ['vfx' => null, '3d' => null]], '::common_or_per_front comum → no negócio e as frentes limpas');
$h = p2dt_hist($tp, $dDates['id'], 'dates');
t_true(count($h) === 1 && str_contains((string)$h[0]['note'], '15/12/2026'), '::common_or_per_front histórico «dates» com a data');
pipe2_dates_save($tp, p2dt_deal($tp, $dDates['id']), null, [$dDates['lines']['vfx'] => '2026-12-10', $dDates['lines']['3d'] => '2026-12-20']);
t_eq(p2dt_dates($tp, $dDates), ['2026-12-20', ['vfx' => '2026-12-10', '3d' => '2026-12-20']], '::common_or_per_front por frente → as frentes e o negócio com a maior');
$h = p2dt_hist($tp, $dDates['id'], 'dates');
t_true(count($h) === 2 && str_contains((string)$h[1]['note'], '10/12/2026') && str_contains((string)$h[1]['note'], '20/12/2026'), '::common_or_per_front histórico por frente');
pipe2_dates_save($tp, p2dt_deal($tp, $dDates['id']), null, [$dDates['lines']['vfx'] => '2026-12-12']);
t_eq(p2dt_dates($tp, $dDates), ['2026-12-20', ['vfx' => '2026-12-12', '3d' => '2026-12-20']], '::common_or_per_front só uma frente: a outra fica');
pipe2_dates_save($tp, p2dt_deal($tp, $dDates['id']), null, [$dDates['lines']['3d'] => '']);
t_eq(p2dt_dates($tp, $dDates), ['2026-12-12', ['vfx' => '2026-12-12', '3d' => null]], '::common_or_per_front limpar uma frente: o negócio fica com a maior das outras');
$nh = count(p2dt_hist($tp, $dDates['id'], 'dates'));
pipe2_dates_save($tp, p2dt_deal($tp, $dDates['id']), null, [$dDates['lines']['vfx'] => '2026-12-12']);
t_eq(count(p2dt_hist($tp, $dDates['id'], 'dates')), $nh, '::common_or_per_front sem mudança, sem histórico');
t_eq(p2dt_err(fn() => pipe2_dates_save($tp, p2dt_deal($tp, $dDates['id']), '15/12/2026', [])), 'Data de entrega inválida.', '::common_or_per_front data inválida');
t_eq(p2dt_err(fn() => pipe2_dates_save($tp, p2dt_deal($tp, $dDates['id']), null, [$dDates['lines']['vfx'] => '2026-02-30'])), 'Data de entrega inválida.',
     '::common_or_per_front dia que não existe');
t_eq(p2dt_err(fn() => pipe2_dates_save($tp, p2dt_deal($tp, $dDates['id']), null, [$dTasks['lines']['vfx'] => '2026-12-12'])), 'Frente não encontrada.',
     '::common_or_per_front frente de outro negócio');
t_eq(p2dt_dates($tp, $dDates), ['2026-12-12', ['vfx' => '2026-12-12', '3d' => null]], '::common_or_per_front as recusas não gravaram nada');

// ── ::front_tasks_use_front_delivery ────────────────────────
$n = pipe2_dates_save($tp, p2dt_deal($tp, $dTasks['id']), null, [$dTasks['lines']['vfx'] => '2026-12-10', $dTasks['lines']['3d'] => '2026-12-20']);
t_eq($n, 3, '::front_tasks_use_front_delivery 3 tarefas com prazo mudado (a feita não conta)');
t_eq(p2dt_task($tp, $tVfx)[0], '2026-12-08', '::front_tasks_use_front_delivery a tarefa da VFX conta da entrega da VFX');
t_true(p2dt_task($tp, $tVfx)[1] !== P2DT_OLD, '::front_tasks_use_front_delivery e updated_at mudou (o Google acompanha)');
t_eq(p2dt_task($tp, $t3d)[0], '2026-12-18', '::front_tasks_use_front_delivery a do 3D, da entrega do 3D');
t_eq(p2dt_task($tp, $tDeal)[0], '2026-12-18', '::front_tasks_use_front_delivery a do negócio fica com a do negócio (a maior)');
t_eq(p2dt_task($tp, $tDone), ['2026-01-01', P2DT_OLD], '::front_tasks_use_front_delivery a feita não muda');
$tp->exec("UPDATE tasks SET updated_at = '" . P2DT_OLD . "' WHERE id = $tVfx");
t_eq(pipe2_dates_save($tp, p2dt_deal($tp, $dTasks['id']), null, [$dTasks['lines']['vfx'] => '2026-12-10']), 0, '::front_tasks_use_front_delivery a mesma data outra vez: 0');
t_eq(p2dt_task($tp, $tVfx), ['2026-12-08', P2DT_OLD], '::front_tasks_use_front_delivery sem mudança, updated_at fica');
t_eq(pipe2_due_refresh($tp, $dTasks['id']), 0, '::front_tasks_use_front_delivery pipe2_due_refresh sem nada a mudar → 0');
t_eq(pipe2_dates_save($tp, p2dt_deal($tp, $dTasks['id']), '2026-12-15', []), 3, '::front_tasks_use_front_delivery data comum: as 3 acompanham');
t_eq([p2dt_task($tp, $tVfx)[0], p2dt_task($tp, $t3d)[0], p2dt_task($tp, $tDeal)[0]], ['2026-12-13', '2026-12-13', '2026-12-13'],
     '::front_tasks_use_front_delivery sem datas das frentes, todas da comum');

// ── ::plan_and_replan ───────────────────────────────────────
$pl = $dPlan['lines']['vfx'];
$f = $SP['fronts'];
p2v_steps($tp, $pl);   // plan_days da linha vazio: usa os da sub-etapa (2, 3, 4, 2, 1, 0)
t_eq(pipe2_plan_front($tp, p2dt_line($tp, $pl), '2026-11-02'), 6, '::plan_and_replan as 6 sub-etapas planeadas');
$want = [
    $f[0] => ['2026-11-02', '2026-11-04', 0],
    $f[1] => ['2026-11-04', '2026-11-09', 0],
    $f[2] => ['2026-11-09', '2026-11-13', 0],
    $f[3] => ['2026-11-13', '2026-11-17', 0],
    $f[4] => ['2026-11-17', '2026-11-18', 0],
    $f[5] => ['2026-11-18', '2026-11-18', 0],
];
t_eq(p2dt_plan($tp, $pl), $want, '::plan_and_replan em dias úteis, contíguas (cada uma começa onde a anterior acaba)');
$allWork = true;
foreach (p2dt_plan($tp, $pl) as [$a, $b]) $allWork = $allWork && (int)date('N', strtotime($a)) < 6 && (int)date('N', strtotime($b)) < 6;
t_true($allWork, '::plan_and_replan nenhuma data num fim de semana');
t_eq(pipe2_replan($tp, p2dt_line($tp, $pl), '2026-11-04'), 0, '::plan_and_replan no dia do fim: não está atrasada');
t_eq(pipe2_replan($tp, p2dt_line($tp, $pl), '2026-11-06'), 2, '::plan_and_replan a 1.ª por fechar 2 dias úteis depois do fim → 2');
$want = [
    $f[0] => ['2026-11-02', '2026-11-04', 0],
    $f[1] => ['2026-11-06', '2026-11-11', 2],
    $f[2] => ['2026-11-11', '2026-11-17', 2],
    $f[3] => ['2026-11-17', '2026-11-19', 2],
    $f[4] => ['2026-11-19', '2026-11-20', 2],
    $f[5] => ['2026-11-20', '2026-11-20', 2],
];
t_eq(p2dt_plan($tp, $pl), $want, '::plan_and_replan as seguintes deslizam 2 dias úteis (replanned_days 2); a atual fica');
t_eq(p2v_col($tp, 'SELECT delivery_date FROM deals WHERE id = ?', [$dPlan['id']]), '2026-12-31', '::plan_and_replan a entrega do negócio não muda');
$h = p2dt_hist($tp, $dPlan['id'], 'p2_replan');
t_true(count($h) === 1 && $h[0]['user_id'] === null && str_contains((string)$h[0]['note'], 'Turnover e breakdown'), '::plan_and_replan p2_replan do «Sistema», com a sub-etapa');
t_eq(pipe2_replan($tp, p2dt_line($tp, $pl), '2026-11-06'), 0, '::plan_and_replan no mesmo dia outra vez → 0 (o atraso já foi aplicado)');
t_eq(pipe2_replan($tp, p2dt_line($tp, $pl), '2026-11-07'), 0, '::plan_and_replan sábado não soma');
t_eq(count(p2dt_hist($tp, $dPlan['id'], 'p2_replan')), 1, '::plan_and_replan sem deslize, sem histórico');
t_eq(pipe2_replan($tp, p2dt_line($tp, $pl), '2026-11-09'), 1, '::plan_and_replan no dia útil seguinte → 1');
$plan = p2dt_plan($tp, $pl);
t_eq([$plan[$f[1]], $plan[$f[5]]], [['2026-11-09', '2026-11-12', 3], ['2026-11-23', '2026-11-23', 3]], '::plan_and_replan soma em replanned_days (3)');
// a frente passa à 2.ª sub-etapa, que também se atrasa: deslizam só as que vêm depois dela
p2v_front($tp, $pl, ['stage_id' => $f[1]]);
t_eq(pipe2_replan($tp, p2dt_line($tp, $pl), '2026-11-16'), 2, '::plan_and_replan a 2.ª 2 dias úteis depois do fim → 2');
$plan = p2dt_plan($tp, $pl);
t_eq([$plan[$f[0]][2], $plan[$f[1]][2], $plan[$f[2]], $plan[$f[5]][2]], [0, 3, ['2026-11-16', '2026-11-20', 5], 5], '::plan_and_replan as seguintes à 2.ª somam mais 2');

// ── ::plan_days_override ────────────────────────────────────
$pl2 = $dPlan2['lines']['vfx'];
p2v_steps($tp, $pl2, ['plan_days' => [$f[0] => 5]]);
pipe2_plan_front($tp, p2dt_line($tp, $pl2), '2026-11-02');
$plan = p2dt_plan($tp, $pl2);
t_eq([$plan[$f[0]], $plan[$f[1]]], [['2026-11-02', '2026-11-09', 0], ['2026-11-09', '2026-11-12', 0]], '::plan_days_override os dias da linha de passos antes dos da sub-etapa');
$pl3 = $dWeek['lines']['vfx'];
p2v_steps($tp, $pl3);
pipe2_plan_front($tp, p2dt_line($tp, $pl3), '2026-11-07 10:00:00');
t_eq(p2dt_plan($tp, $pl3)[$f[0]], ['2026-11-09', '2026-11-11', 0], '::plan_days_override a começar ao sábado: o plano começa na segunda');

// ── ::replan_rules ──────────────────────────────────────────
$np = $dNoPlan['lines']['3d'];
p2v_steps($tp, $np);
t_eq(pipe2_plan_front($tp, p2dt_line($tp, $np), '2026-11-02'), 0, '::replan_rules sem dias, sem plano');
t_eq(pipe2_replan($tp, p2dt_line($tp, $np), '2026-12-31'), 0, '::replan_rules sem plano → 0');
foreach (['queued' => $dQueued, 'ready' => $dReady] as $status => $d) {
    $lid = $d['lines']['vfx'];
    p2v_steps($tp, $lid);
    pipe2_plan_front($tp, p2dt_line($tp, $lid), '2026-11-02');
    $sig = p2v_sig($tp, ['deal_line_steps'], 'line_id = ' . $lid);
    t_eq(pipe2_replan($tp, p2dt_line($tp, $lid), '2026-12-31'), 0, "::replan_rules {$status} → 0");
    t_eq(p2v_sig($tp, ['deal_line_steps'], 'line_id = ' . $lid), $sig, "::replan_rules {$status} não mexe nas linhas");
}

// ── ::plan_save_v2 ──────────────────────────────────────────
$sv = $dSave['lines'];
$superadmin = (int)p2v_col($tp, "SELECT id FROM users WHERE role = 'superadmin' ORDER BY id LIMIT 1");
foreach ([null, 99] as $uid) {
    p2v_as($uid, $tp);
    $msg = pipe2_plan_save($tp, p2dt_deal($tp, $dSave['id']), ['delivery_mode' => 'front', 'line_delivery' => [$sv['vfx'] => '2026-12-10', $sv['3d'] => '2026-12-20'],
                                                                'line_exec' => [$sv['vfx'] => 8]]);
    $who = $uid === null ? 'sem sessão' : 'sem ser admin';
    t_eq(p2v_col($tp, 'SELECT exec_owner_id FROM deal_lines WHERE id = ?', [$sv['vfx']]), null, "::plan_save_v2 {$who}: o responsável não muda");
    t_eq(p2dt_dates($tp, $dSave), ['2026-12-20', ['vfx' => '2026-12-10', '3d' => '2026-12-20']], "::plan_save_v2 {$who}: as datas sim");
    t_true($msg !== '' && !str_contains($msg, '§'), "::plan_save_v2 {$who}: mensagem em pt-PT, sem «§»");
}
p2v_as(1, $tp);
pipe2_plan_save($tp, p2dt_deal($tp, $dSave['id']), ['delivery_mode' => 'front', 'line_exec' => [$sv['vfx'] => 8, $sv['3d'] => $superadmin]]);
t_eq([(int)p2v_col($tp, 'SELECT exec_owner_id FROM deal_lines WHERE id = ?', [$sv['vfx']]), p2v_col($tp, 'SELECT exec_owner_id FROM deal_lines WHERE id = ?', [$sv['3d']])],
     [8, null], '::plan_save_v2 admin: o responsável muda; o superadmin nunca fica com uma frente');
t_eq(p2dt_dates($tp, $dSave), ['2026-12-20', ['vfx' => '2026-12-10', '3d' => '2026-12-20']], '::plan_save_v2 sem datas no pedido, as datas ficam');
t_eq(p2dt_err(fn() => pipe2_plan_save($tp, p2dt_deal($tp, $dSave['id']), ['sync_mode' => 'bogus', 'delivery' => [$sv['vfx'] => '2026-12-11']])), '',
     '::plan_save_v2 sync_mode inválido (o pedido da v1) não lança');
t_eq(p2dt_dates($tp, $dSave), ['2026-12-20', ['vfx' => '2026-12-11', '3d' => '2026-12-20']], '::plan_save_v2 e grava as datas por frente');
$msg = pipe2_plan_save($tp, p2dt_deal($tp, $dSave['id']), ['delivery_mode' => 'common', 'delivery_date' => '2027-01-15']);
t_eq(p2dt_dates($tp, $dSave), ['2027-01-15', ['vfx' => null, '3d' => null]], '::plan_save_v2 comum: no negócio, frentes limpas');
t_true(str_contains($msg, 'comum'), '::plan_save_v2 a mensagem diz que a data é comum');
pipe2_plan_save($tp, p2dt_deal($tp, $dSave['id']), ['delivery_mode' => 'front', 'line_delivery' => [$sv['vfx'] => '2027-01-10']]);
t_eq(p2dt_dates($tp, $dSave), ['2027-01-10', ['vfx' => '2027-01-10', '3d' => null]], '::plan_save_v2 por frente: o negócio fica com a maior');
pipe2_plan_save($tp, p2dt_deal($tp, $dSave['id']), ['delivery_mode' => 'front', 'line_delivery' => [$sv['vfx'] => '']]);
t_eq(p2dt_dates($tp, $dSave), ['2027-01-10', ['vfx' => null, '3d' => null]], '::plan_save_v2 por frente sem nenhuma data: a do negócio fica (as frentes seguem-na)');
pipe2_plan_save($tp, p2dt_deal($tp, $dSave['id']), ['delivery_mode' => 'front', 'line_delivery' => [$sv['3d'] => '2027-01-20']]);
pipe2_plan_save($tp, p2dt_deal($tp, $dSave['id']), ['delivery_mode' => 'common']);
t_eq(p2dt_dates($tp, $dSave), ['2027-01-20', ['vfx' => null, '3d' => null]], '::plan_save_v2 comum sem data: fica a do negócio, frentes limpas');
t_eq(p2dt_err(fn() => pipe2_plan_save($tp, p2dt_deal($tp, $dSave['id']), ['delivery_mode' => 'common', 'delivery_date' => 'amanhã'])), 'Data de entrega inválida.',
     '::plan_save_v2 data inválida');
t_eq(p2dt_err(fn() => pipe2_plan_save($tp, p2dt_deal($tp, $dDone['id']), ['delivery_mode' => 'common', 'delivery_date' => '2027-01-15'])), 'O negócio já está concluído.',
     '::plan_save_v2 negócio concluído');
t_eq(p2dt_err(fn() => pipe2_plan_save($tp, p2dt_deal($tp, $v1), ['delivery_mode' => 'common', 'delivery_date' => '2027-01-15'])), 'Este negócio não é da Pipeline v2.',
     '::plan_save_v2 um negócio v1 não passa por aqui (o xsell_save_plan da v1 continua)');
p2v_as(null);

// ── ::v1_noop (regra de ouro) ───────────────────────────────
// Os prazos da v1 calculam-se como o flow_dates_changed os calcula (flow_due sobre o negócio, ou sobre xsell_vdeal
// nas tarefas de uma frente). Não se compara com o que o flow_dates_changed grava: o SELECT dele («t.id, …, a.*»)
// deixa o id da regra sobrepor o da tarefa e o UPDATE acerta noutra tarefa (erro da v1, fora desta tarefa).
$v1Calc = function (int $taskId) use ($tp, $v1): ?string {
    $row = p2v_rows($tp, 'SELECT a.*, t.line_id AS t_line FROM tasks t JOIN stage_automations a ON a.id = t.auto_rule_id WHERE t.id = ?', [$taskId])[0];
    $deal = deal_get($tp, $v1);
    $line = $row['t_line'] ? xsell_line_get($tp, (int)$row['t_line']) : null;
    return flow_due($tp, $row, $line ? xsell_vdeal($deal, $line) : $deal, today_lisbon())[0];
};
foreach (['sync' => ['2026-12-13', '2026-12-13'], 'independent' => ['2026-12-13', '2026-11-29']] as $mode => $wantDue) {
    $tp->prepare('UPDATE deals SET sync_mode = ? WHERE id = ?')->execute([$mode, $v1]);
    $tp->exec("UPDATE tasks SET due_date = '2026-01-01' WHERE id IN ($tv1, $tv1l)");
    // a assinatura depois de preparar (deals.updated_at é ON UPDATE CURRENT_TIMESTAMP: o sync_mode acima mexe-lhe)
    $sig = p2v_sig($tp, ['deals', 'deal_lines', 'deal_history']);
    t_eq(pipe2_due_refresh($tp, $v1), 2, "::v1_noop {$mode}: as 2 tarefas mudam");
    t_eq(p2v_sig($tp, ['deals', 'deal_lines', 'deal_history']), $sig, "::v1_noop {$mode}: pipe2_due_refresh não mexe no negócio, nas frentes nem no histórico");
    $mine = [p2dt_task($tp, $tv1)[0], p2dt_task($tp, $tv1l)[0]];
    t_eq($mine, [$v1Calc($tv1), $v1Calc($tv1l)], "::v1_noop {$mode}: pipe2_due_refresh dá os prazos que a v1 calcula (flow_due, xsell_vdeal)");
    t_eq($mine, $wantDue, "::v1_noop {$mode}: os prazos da v1 (a frente v1 só usa a data dela em timelines diferentes)");
}
$tp->prepare("UPDATE deals SET sync_mode = 'sync' WHERE id = ?")->execute([$v1]);
// a tarefa com o id da regra (a que o erro da v1 atingia): existe sempre aqui, com um prazo-sentinela
if (!in_array($rv1, [$tv1, $tv1l], true)) {
    if (!p2v_col($tp, 'SELECT id FROM tasks WHERE id = ?', [$rv1])) {
        $tp->prepare("INSERT INTO tasks (id, title, status, kind) VALUES (?, 'P2T · vítima', 'todo', 'task')")->execute([$rv1]);
    }
    $tp->prepare("UPDATE tasks SET due_date = '2001-01-01' WHERE id = ?")->execute([$rv1]);
    $tp->exec("UPDATE tasks SET due_date = '2026-01-01' WHERE id IN ($tv1, $tv1l)");
    pipe2_due_refresh($tp, $v1);
    t_eq(p2v_col($tp, 'SELECT due_date FROM tasks WHERE id = ?', [$rv1]), '2001-01-01', '::v1_noop pipe2_due_refresh só mexe nas tarefas do negócio (não na que tem o id da regra)');
}

// ── ::vdeal_front_delivery (puro; gancho em xsell_vdeal) ────
$vd = ['id' => 1, 'delivery_date' => '2026-12-15', 'sync_mode' => 'sync', 'exec_owner_id' => null];
$vl = ['id' => 7, 'funnel_id' => 3, 'stage_id' => 11, 'status' => 'running', 'exec_owner_id' => null, 'delivery_date' => '2026-12-10', 'is_main' => 0, 'funnel_name' => 'VFX'];
$vv = fn(array $deal, array $line): array => [($r = xsell_vdeal($deal, $line))['delivery_date'] ?? null, !empty($r['_v2'])];
t_eq($vv($vd, $vl), ['2026-12-10', true], '::vdeal_front_delivery frente v2 com entrega, negócio sincronizado: a da frente, marcada _v2');
t_eq($vv($vd, ['delivery_date' => null] + $vl), ['2026-12-15', true], '::vdeal_front_delivery frente v2 sem entrega: a do negócio');
t_eq($vv($vd, ['status' => '', 'billing' => 'monthly'] + $vl), ['2026-12-10', true], '::vdeal_front_delivery uma avença também é v2');
t_eq($vv($vd, ['status' => 'active'] + $vl), ['2026-12-15', false], '::vdeal_front_delivery frente v1 em curso, sincronizada: a do negócio (como hoje)');
t_eq($vv(['sync_mode' => 'independent'] + $vd, ['status' => 'active'] + $vl), ['2026-12-10', false], '::vdeal_front_delivery frente v1 em timelines diferentes: a da frente (como hoje)');
t_eq(array_intersect_key(xsell_vdeal($vd, $vl), array_flip(['funnel_id', 'stage_id', 'phase', '_line', '_main', '_service'])),
     ['funnel_id' => 3, 'stage_id' => 11, 'phase' => 'production', '_line' => 7, '_main' => false, '_service' => 'VFX'], '::vdeal_front_delivery o resto do negócio visto pela frente fica igual');

// ── ::save_plan_v2 (gancho em xsell_save_plan) ──────────────
$xl = $dXsell['lines'];
p2v_as(99, $tp);
$msg = '';
$err = p2dt_err(function () use ($tp, $dXsell, $xl, &$msg) {
    $msg = xsell_save_plan($tp, p2dt_deal($tp, $dXsell['id']), ['sync_mode' => 'bogus', 'delivery' => [$xl['vfx'] => '2026-12-10', $xl['3d'] => '2026-12-20']], 99);
});
t_eq($err, '', '::save_plan_v2 xsell_save_plan de um v2 com sync_mode inválido não lança');
t_eq(p2dt_dates($tp, $dXsell), ['2026-12-20', ['vfx' => '2026-12-10', '3d' => '2026-12-20']], '::save_plan_v2 e grava as datas por frente (o negócio com a maior)');
t_true(str_starts_with($msg, 'Plano das frentes guardado') && !str_contains($msg, '§'), '::save_plan_v2 a mensagem da v2 — obtido ' . var_export($msg, true));
t_eq((string)p2v_col($tp, 'SELECT sync_mode FROM deals WHERE id = ?', [$dXsell['id']]), '', '::save_plan_v2 o sync_mode da v1 não se grava');
t_eq(p2dt_err(fn() => xsell_save_plan($tp, p2dt_deal($tp, $v1), ['sync_mode' => 'bogus'], 99)), 'Escolha se as frentes seguem sincronizadas ou em timelines diferentes.',
     '::save_plan_v2 um v1 com sync_mode inválido: a recusa de sempre');
p2v_as(null);

// ── ::strict (ONLY_FULL_GROUP_BY, STRICT_ALL_TABLES) ────────
pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
$sl = $dStrict['lines']['vfx'];
$err = p2dt_err(function () use ($tp, $dStrict, $sl) {
    p2v_steps($tp, $sl);
    pipe2_plan_front($tp, p2dt_line($tp, $sl), '2026-11-02');
    pipe2_replan($tp, p2dt_line($tp, $sl), '2026-11-10');
    pipe2_deal_delivery($tp, p2dt_deal($tp, $dStrict['id']));
    pipe2_dates_save($tp, p2dt_deal($tp, $dStrict['id']), null, [$sl => '2027-02-01']);
    pipe2_due_refresh($tp, $dStrict['id']);
    pipe2_plan_save($tp, p2dt_deal($tp, $dStrict['id']), ['delivery_mode' => 'common', 'delivery_date' => '2027-02-02']);
});
t_eq($err, '', '::strict as consultas do plano e das datas em modo estrito');
t_eq(p2dt_plan($tp, $sl)[$f[1]][2], 4, '::strict o replaneamento correu (4 dias úteis de atraso)');
pipe2_sql_mode($tp, '');

t_done();
