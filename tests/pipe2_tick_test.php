<?php
/**
 * Pipeline v2, fase 1 — o relógio da v2 (admin/inc/pipe2_tick.php, chamado pelo flow_tick depois do xsell_tick;
 * tarefa 13: E3, E4, E7, E8, nota 6; Review Focus 6).
 *
 * pipe2_tick corre o relógio da avença (pipe2_billing_tick) e depois, para cada negócio v2 em produção, com o lock do
 * negócio e relendo o negócio e as frentes depois dele: em cada frente em curso (ou Ativa) — o replaneamento (com um
 * aviso 'replan' uma vez), a frente parada há N dias (aviso 'stale' uma vez e as regras f_stale da sub-etapa), as
 * regras 'date' da sub-etapa (pela entrega da frente) e a reavaliação (pipe2_front_reevaluate, que também liberta as
 * frentes À espera); no fim, a reavaliação do negócio («cron»). Um negócio com dados partidos não impede os outros e
 * o relógio nunca lança. Num negócio v1 não faz nada.
 *
 * Cada caso deixa em produção só os seus negócios (os outros ficam «estacionados» em 'done', que o relógio não vê).
 * Corre só em bases privadas (p2v_db, uma base na 42) e em subprocessos sobre elas (p2v_race).
 *   php tests/pipe2_tick_test.php
 * Casos: ::v1_noop ::last_move ::reevaluate_cron ::stale_once ::replan_notice_once ::date_rules_on_substages
 *        ::never_throws ::cron_and_request
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/tasks.php';
require_once __DIR__ . '/../admin/inc/pipeline.php';
require_once __DIR__ . '/../admin/inc/pipeline_flow.php';
require_once __DIR__ . '/../admin/inc/alerts.php';
require_once __DIR__ . '/../admin/inc/pipe2_engine.php';
require_once __DIR__ . '/../admin/inc/pipe2_deal.php';
if (is_file(__DIR__ . '/../admin/inc/pipe2_tick.php')) require_once __DIR__ . '/../admin/inc/pipe2_tick.php';

if (!function_exists('pipe2_tick') || !function_exists('pipe2_front_last_move')) {
    t_true(false, 'pipe2_tick e pipe2_front_last_move existem em admin/inc/pipe2_tick.php');
    t_done();
}

const P2T_BILL0 = ['opened' => 0, 'restarted' => 0, 'ended' => 0];

function p2t_n(PDO $pdo, string $sql, array $a = []): int {
    return (int)p2v_col($pdo, $sql, $a);
}

function p2t_line(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deal_lines WHERE id = ?', [$id])[0] ?? [];
}

function p2t_deal(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deals WHERE id = ?', [$id])[0] ?? [];
}

/** O resultado do relógio com estes valores (o resto a 0). */
function p2t_res(array $o = []): array {
    return array_merge(['billing' => P2T_BILL0, 'fronts' => 0, 'advanced' => 0, 'replanned' => 0, 'stale' => 0, 'dated' => 0, 'deals' => 0], $o);
}

/** Só os negócios $active ficam em produção; os outros dos testes ficam em 'done' (o relógio só vê os em produção). */
function p2t_only(PDO $pdo, array $all, array $active): void {
    if ($all) $pdo->exec("UPDATE deals SET phase = 'done' WHERE id IN (" . pipe2_ids_sql($all) . ')');
    if ($active) $pdo->exec("UPDATE deals SET phase = 'production' WHERE id IN (" . pipe2_ids_sql($active) . ')');
}

/** O relógio como um pedido novo. */
function p2t_tick(PDO $pdo, string $today): array {
    pipe2_request_reset();
    return pipe2_tick($pdo, $today);
}

/** Fecha uma tarefa por SQL direto (sem task_done: nenhum gancho corre). */
function p2t_close(PDO $pdo, int $taskId, ?string $at = null): void {
    $pdo->prepare("UPDATE tasks SET status = 'done', done_at = ? WHERE id = ?")->execute([$at ?? date('Y-m-d H:i:s'), $taskId]);
}

// ── a base privada, os funis e as regras (tudo antes das leituras de flow_order/stage_match/mx_stages) ──
[$db, $tp] = p2v_db();
$today = today_lisbon();
$ago = fn(int $n): string => date('Y-m-d H:i:s', strtotime($today . ' 09:00:00 -' . $n . ' days'));
$in = fn(int $n): string => date('Y-m-d', strtotime($today . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));

$w = p2v_world($tp, ['vfx', '3d']);             // ::reevaluate_cron, ::cron_and_request (all_ready e fecho)
$S = p2v_stages($tp, $w['vfx']);
$S3 = p2v_stages($tp, $w['3d']);
$rAll = p2v_rule($tp, $S['exec'], ['title' => 'P2T · todas prontas', 'trig' => 'all_ready', 'assign_to' => 'owner', 'is_gate' => 0]);
$closeV = p2v_closing_rules($tp, $S['delivery']);
p2v_closing_rules($tp, $S3['delivery']);

$wS = p2v_world($tp, ['vfx']);                  // ::stale_once
$SS = p2v_stages($tp, $wS['vfx']);
$tp->prepare('UPDATE funnel_stages SET max_days = 5 WHERE id = ?')->execute([$SS['fronts'][0]]);
$stale = ['trig' => 'f_stale', 'is_gate' => 0, 'assign_to' => 'execution'];
$rSa = p2v_rule($tp, $SS['fronts'][0], ['title' => 'P2T · parada (limite da sub-etapa)'] + $stale);
$rSb = p2v_rule($tp, $SS['fronts'][0], ['title' => 'P2T · parada há 3 dias', 'trig_n' => 3] + $stale);
$rSc = p2v_rule($tp, $SS['fronts'][1], ['title' => 'P2T · parada (limite geral)'] + $stale);

$wP = ['vfx' => p2v_funnel($tp, 'vfx', ['plan_days' => [2, 3, 4, 2, 1, 0]])];   // ::replan_notice_once
$SP = p2v_stages($tp, $wP['vfx']);

$wD = p2v_world($tp, ['vfx']);                  // ::date_rules_on_substages
$SD = p2v_stages($tp, $wD['vfx']);
$date = ['trig' => 'date', 'due_mode' => 'delivery', 'due_days' => -3, 'due_unit' => 'd', 'is_gate' => 0, 'assign_to' => 'execution'];
$rD = p2v_rule($tp, $SD['fronts'][0], ['title' => 'P2T · três dias antes da entrega'] + $date);
$rD2 = p2v_rule($tp, $SD['fronts'][1], ['title' => 'P2T · noutra sub-etapa'] + $date);

$wB = p2v_world($tp, ['vfx', '3d']);            // ::never_throws
$SB = p2v_stages($tp, $wB['vfx']);
$SB3 = p2v_stages($tp, $wB['3d']);

$lim = alert_limits($tp)['deal'];
$all = [];
p2v_as(99);

// ── ::v1_noop (regra de ouro) ───────────────────────────────
$c = '::v1_noop';
$sigT = ['tasks', 'deal_lines', 'deal_history', 'deal_line_steps', 'pipe2_notices', 'deals'];
$before = p2v_sig($tp, $sigT);
t_eq(p2t_tick($tp, $today), p2t_res(), "$c numa base privada sem negócios v2, todas as contagens a 0");
t_eq(p2v_sig($tp, $sigT), $before, "$c nenhuma linha muda (tarefas, frentes, histórico, passos, avisos, negócios)");
$db42 = pipe2_tmpdb_create(pipe2_base_dump());
$p42 = pipe2_tmpdb_pdo($db42);
$warn = [];
set_error_handler(function (int $no, string $msg) use (&$warn) { $warn[] = $msg; return true; });
$r42 = null;
try { $r42 = pipe2_tick($p42, $today); } catch (Throwable $e) { $warn[] = get_class($e) . ': ' . $e->getMessage(); }
restore_error_handler();
t_eq([$r42, $warn], [p2t_res(), []], "$c numa base na 42 (sem as colunas da v2): contagens a 0, sem exceção nem aviso de PHP");

// ── ::last_move (o movimento mais recente da frente) ────────
$c = '::last_move';
$dl = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$all[] = $dl['id'];
$ll = $dl['lines']['vfx'];
p2v_front($tp, $ll, ['stage_entered_at' => $ago(8), 'started_at' => $ago(9)]);
t_eq(pipe2_front_last_move($tp, p2t_line($tp, $ll)), $ago(8), "$c só a entrada na sub-etapa → stage_entered_at");
$steps = p2v_steps($tp, $ll);
$tp->prepare('UPDATE deal_line_steps SET started_at = ?, done_at = ? WHERE id = ?')->execute([$ago(10), $ago(5), $steps[$S['fronts'][3]]]);
t_eq(pipe2_front_last_move($tp, p2t_line($tp, $ll)), $ago(5), "$c um passo concluído depois → o done_at do passo");
p2v_task($tp, $dl['id'], ['line_id' => $ll, 'stage_id' => $S['fronts'][0], 'status' => 'done', 'done_at' => $ago(2)]);
p2v_task($tp, $dl['id'], ['line_id' => $ll, 'stage_id' => $S['fronts'][0], 'status' => 'todo']);
p2v_task($tp, $dl['id'], ['stage_id' => $S['exec'], 'status' => 'done', 'done_at' => $ago(1)]);   // do negócio: não conta
t_eq(pipe2_front_last_move($tp, p2t_line($tp, $ll)), $ago(2), "$c uma tarefa feita da frente → o done_at dela (as do negócio não contam)");

// ── ::reevaluate_cron (E4) ──────────────────────────────────
$c = '::reevaluate_cron';
$dA = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'status' => ['vfx' => 'running', '3d' => 'ready'], 'front_at' => ['vfx' => $S['revisions']]]);
$dB = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
array_push($all, $dA['id'], $dB['id']);
p2t_only($tp, $all, [$dA['id'], $dB['id']]);
$gA = p2v_task($tp, $dA['id'], ['title' => 'última da VFX', 'line_id' => $dA['lines']['vfx'], 'stage_id' => $S['revisions'], 'is_gate' => 1]);
$gB = p2v_task($tp, $dB['id'], ['title' => 'primeira da VFX', 'line_id' => $dB['lines']['vfx'], 'stage_id' => $S['fronts'][0], 'is_gate' => 1]);
p2t_close($tp, $gA);
p2t_close($tp, $gB);
t_eq([p2t_line($tp, $dA['lines']['vfx'])['status'], (int)p2t_line($tp, $dB['lines']['vfx'])['stage_id']], ['running', $S['fronts'][0]],
     "$c (antes) fechadas por SQL direto: nenhuma frente andou");
$r = p2t_tick($tp, $today);
t_eq($r, p2t_res(['fronts' => 3, 'advanced' => 2, 'deals' => 1]), "$c o relógio: 3 frentes em curso vistas, 2 avançam, 1 negócio movido");
t_eq([p2t_line($tp, $dA['lines']['vfx'])['status'], (int)p2t_line($tp, $dA['lines']['vfx'])['stage_id']], ['ready', $S['ready']], "$c a VFX do negócio A fica Pronta");
t_eq((int)p2t_deal($tp, $dA['id'])['stage_id'], $S['delivery'], "$c e, sendo a última, o negócio A passa à Entrega");
t_eq(p2v_rows($tp, "SELECT user_id, note FROM deal_history WHERE deal_id = ? AND event = 'stage' ORDER BY id", [$dA['id']]),
     [['user_id' => null, 'note' => 'Todas as frentes prontas']], "$c pelo «Sistema» (user_id NULL, mesmo com sessão)");
$hf = p2v_rows($tp, "SELECT user_id, note FROM deal_history WHERE deal_id = ? AND event = 'p2_front' ORDER BY id", [$dA['id']]);
t_true(count($hf) === 1 && $hf[0]['user_id'] === null && str_contains((string)$hf[0]['note'], PIPE2_AUTO_NOTE), "$c o p2_front da VFX do «Sistema», com «Avanço automático»");
t_eq([(int)p2t_line($tp, $dB['lines']['vfx'])['stage_id'], p2t_line($tp, $dB['lines']['vfx'])['status']], [$S['fronts'][1], 'running'], "$c a VFX do negócio B passa à 2.ª sub-etapa");
t_eq([(int)p2t_deal($tp, $dB['id'])['stage_id'], p2t_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'stage'", [$dB['id']])], [$S['exec'], 0],
     "$c o negócio B fica em Em execução (o 3D está em curso)");
t_eq(p2t_tick($tp, $today), p2t_res(['fronts' => 2]), "$c outro relógio: nada de novo (a 2.ª sub-etapa da VFX de B não tem obrigatórias)");

// ── ::stale_once (nota 6) ───────────────────────────────────
$c = '::stale_once';
$mk = function (int $stage, int $days) use ($tp, $wS, $ago, &$all): array {
    $d = p2v_deal($tp, $wS, ['vfx'], ['at' => 'exec', 'front_at' => ['vfx' => $stage]]);
    p2v_front($tp, $d['lines']['vfx'], ['stage_entered_at' => $ago($days), 'started_at' => $ago($days), 'exec_owner_id' => 99]);
    $all[] = $d['id'];
    return $d;
};
$sA = $mk($SS['fronts'][0], 6);              // limite da sub-etapa (max_days 5): aviso, a regra do limite e a de 3 dias
$sB = $mk($SS['fronts'][0], 4);              // só a regra de 3 dias
$sC = $mk($SS['fronts'][1], $lim - 1);       // sem max_days: o limite geral (deal_stale_days), ainda não
$sD = $mk($SS['fronts'][1], $lim);           // o limite geral: aviso e a regra
$sE = $mk($SS['fronts'][0], 6);              // entrou há 6 dias, mas uma tarefa feita há 2 dias conta como movimento
p2v_task($tp, $sE['id'], ['line_id' => $sE['lines']['vfx'], 'stage_id' => $SS['fronts'][0], 'status' => 'done', 'done_at' => $ago(2)]);
$sDeals = [$sA['id'], $sB['id'], $sC['id'], $sD['id'], $sE['id']];
p2t_only($tp, $all, $sDeals);
$notices = fn(int $deal): array => array_map(fn($n) => [(int)$n['user_id'], (int)$n['line_id'], (int)$n['stage_id']],
                                             p2v_rows($tp, "SELECT * FROM pipe2_notices WHERE deal_id = ? AND kind = 'stale' ORDER BY id", [$deal]));
$rules = fn(array $d): array => array_map(fn($t) => [(int)$t['auto_rule_id'], (int)$t['line_id'], (int)$t['stage_id'], (int)$t['is_gate']],
                                         p2v_rows($tp, "SELECT * FROM tasks WHERE deal_id = ? AND auto_rule_id IS NOT NULL ORDER BY auto_rule_id, id", [$d['id']]));
$r = p2t_tick($tp, $today);
t_eq($r, p2t_res(['fronts' => 5, 'stale' => 6]), "$c 5 frentes vistas; 2 avisos e 4 tarefas f_stale novos");
t_eq($notices($sA['id']), [[99, $sA['lines']['vfx'], $SS['fronts'][0]]], "$c parada há 6 dias com max_days 5: um aviso 'stale' ao responsável da frente");
t_eq($rules($sA), [[$rSa, $sA['lines']['vfx'], $SS['fronts'][0], 0], [$rSb, $sA['lines']['vfx'], $SS['fronts'][0], 0]],
     "$c e as tarefas f_stale da sub-etapa (a do limite e a de 3 dias), na frente");
t_eq([$notices($sB['id']), $rules($sB)], [[], [[$rSb, $sB['lines']['vfx'], $SS['fronts'][0], 0]]], "$c parada há 4 dias: sem aviso, só a regra de 3 dias (trig_n)");
t_eq([$notices($sC['id']), $rules($sC)], [[], []], "$c sem max_days, " . ($lim - 1) . " dias: abaixo do limite geral ($lim), nada");
t_eq([$notices($sD['id']), $rules($sD)], [[[99, $sD['lines']['vfx'], $SS['fronts'][1]]], [[$rSc, $sD['lines']['vfx'], $SS['fronts'][1], 0]]],
     "$c sem max_days, $lim dias: o aviso e a regra pelo limite geral");
t_eq([$notices($sE['id']), $rules($sE)], [[], []], "$c uma tarefa da frente feita há 2 dias: não está parada");
$n0 = p2v_rows($tp, "SELECT title, body FROM pipe2_notices WHERE deal_id = ? AND kind = 'stale'", [$sA['id']])[0] ?? [];
t_true(($n0['title'] ?? '') !== '' && !str_contains(($n0['title'] ?? '') . ($n0['body'] ?? ''), '§'), "$c o aviso tem título, em pt-PT, sem «§» — obtido " . var_export($n0, true));
$ha = p2v_rows($tp, "SELECT user_id FROM deal_history WHERE deal_id = ? AND event = 'auto'", [$sA['id']]);
t_eq($ha, [['user_id' => null]], "$c uma linha 'auto' no histórico, do «Sistema»");
$counts = fn() => [p2t_n($tp, "SELECT COUNT(*) FROM pipe2_notices WHERE kind = 'stale' AND deal_id IN (" . pipe2_ids_sql($sDeals) . ')'),
                   p2t_n($tp, 'SELECT COUNT(*) FROM tasks WHERE auto_rule_id IN (?, ?, ?)', [$rSa, $rSb, $rSc])];
t_eq($counts(), [2, 4], "$c (2 avisos e 4 tarefas no total)");
t_eq(p2t_tick($tp, $today), p2t_res(['fronts' => 5]), "$c 2.º relógio: nada de novo");
t_eq($counts(), [2, 4], "$c continuam 2 avisos e 4 tarefas");
// amanhã: só o que chega agora aos limites é novo (o que já existia não se repete: uma vez por frente, sub-etapa e ciclo)
t_eq(p2t_tick($tp, $in(1)), p2t_res(['fronts' => 5, 'stale' => 5]),
     "$c amanhã: a de 4 dias chega aos 5 (aviso e regra do limite), a de " . ($lim - 1) . " ao limite geral (aviso e regra), a da tarefa aos 3 dias (regra)");
t_eq([$notices($sB['id']), $notices($sC['id']), $notices($sE['id'])], [[[99, $sB['lines']['vfx'], $SS['fronts'][0]]], [[99, $sC['lines']['vfx'], $SS['fronts'][1]]], []],
     "$c (os avisos novos)");
t_eq([count($notices($sA['id'])), count($rules($sA)), count($notices($sD['id'])), count($rules($sD))], [1, 2, 1, 1], "$c as frentes que já tinham aviso e tarefas não repetem");

// ── ::replan_notice_once (E8, D17) ──────────────────────────
$c = '::replan_notice_once';
$dP = p2v_deal($tp, $wP, ['vfx'], ['at' => 'exec', 'delivery_date' => $in(90)]);
$all[] = $dP['id'];
$lP = $dP['lines']['vfx'];
p2v_front($tp, $lP, ['exec_owner_id' => 99]);
p2v_steps($tp, $lP);
pipe2_plan_front($tp, p2t_line($tp, $lP), $in(-21));
p2t_only($tp, $all, [$dP['id']]);
$planOf = fn() => array_map(fn($r) => [$r['plan_start'], (int)$r['replanned_days']],
                            p2v_rows($tp, 'SELECT plan_start, replanned_days FROM deal_line_steps WHERE line_id = ? ORDER BY id', [$lP]));
$plan0 = $planOf();
$repl = fn() => p2v_rows($tp, "SELECT user_id, line_id, stage_id FROM pipe2_notices WHERE deal_id = ? AND kind = 'replan'", [$dP['id']]);
t_eq(p2t_tick($tp, $today), p2t_res(['fronts' => 1, 'replanned' => 1]), "$c a 1.ª sub-etapa passou o fim: o relógio replaneia a frente");
$plan1 = $planOf();
t_true($plan1[1][1] > 0 && $plan1[1][0] > $plan0[1][0] && $plan1[0] === $plan0[0], "$c as sub-etapas seguintes deslizaram (replanned_days > 0); a atual fica — " . json_encode([$plan0, $plan1]));
t_eq(array_map(fn($n) => [(int)$n['user_id'], (int)$n['line_id'], (int)$n['stage_id']], $repl()), [[99, $lP, $SP['fronts'][0]]], "$c um aviso 'replan' ao responsável da frente");
t_eq(p2t_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_replan' AND user_id IS NULL", [$dP['id']]), 1, "$c p2_replan do «Sistema»");
t_eq((string)p2t_deal($tp, $dP['id'])['delivery_date'], $in(90), "$c a entrega não muda");
t_eq(p2t_tick($tp, $today), p2t_res(['fronts' => 1]), "$c outra vez no mesmo dia: nada (o atraso já foi aplicado)");
$next = flow_add_workdays($today, 1);
t_eq(p2t_tick($tp, $next), p2t_res(['fronts' => 1, 'replanned' => 1]), "$c no dia útil seguinte: desliza mais um dia");
t_eq(count($repl()), 1, "$c e o aviso continua a ser um só");
t_eq(p2t_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_replan'", [$dP['id']]), 2, "$c (dois p2_replan no histórico)");

// ── ::date_rules_on_substages (nota 6, E8) ──────────────────
$c = '::date_rules_on_substages';
$dD = p2v_deal($tp, $wD, ['vfx'], ['at' => 'exec', 'delivery_date' => $in(20)]);
$all[] = $dD['id'];
$lD = $dD['lines']['vfx'];
p2v_front($tp, $lD, ['delivery_date' => $in(3), 'exec_owner_id' => 99]);
p2t_only($tp, $all, [$dD['id']]);
$dated = fn() => array_map(fn($t) => [(int)$t['auto_rule_id'], (int)$t['line_id'], (int)$t['stage_id'], (string)$t['due_date'], (string)$t['cycle_ym']],
                           p2v_rows($tp, 'SELECT * FROM tasks WHERE deal_id = ? AND auto_rule_id IS NOT NULL ORDER BY id', [$dD['id']]));
t_eq(p2t_tick($tp, $in(-1)), p2t_res(['fronts' => 1]), "$c 4 dias antes da entrega da frente: nada");
t_eq($dated(), [], "$c (nenhuma tarefa)");
t_eq(p2t_tick($tp, $today), p2t_res(['fronts' => 1, 'dated' => 1]), "$c 3 dias antes da entrega da frente: a regra 'date' da sub-etapa cria a tarefa");
t_eq($dated(), [[$rD, $lD, $SD['fronts'][0], $today, '']], "$c na frente, na sub-etapa, com o prazo pela entrega da frente (não pela do negócio)");
t_eq(p2t_tick($tp, $in(1)), p2t_res(['fronts' => 1]), "$c no dia seguinte: não repete");
t_eq(count($dated()), 1, "$c uma só; a regra da outra sub-etapa não corre");

// ── ::never_throws ──────────────────────────────────────────
$c = '::never_throws';
$dX = p2v_deal($tp, $wB, ['vfx'], ['at' => 'exec']);
p2v_front($tp, $dX['lines']['vfx'], ['stage_id' => $SB3['fronts'][0]]);   // dados partidos: a sub-etapa é do funil do 3D
$dY = p2v_deal($tp, $wB, ['vfx'], ['at' => 'exec']);
array_push($all, $dX['id'], $dY['id']);
$gY = p2v_task($tp, $dY['id'], ['title' => 'obrigatória de Y', 'line_id' => $dY['lines']['vfx'], 'stage_id' => $SB['fronts'][0], 'is_gate' => 1]);
p2t_close($tp, $gY);
p2t_only($tp, $all, [$dX['id'], $dY['id']]);
$sigX = p2v_sig($tp, ['deal_lines', 'deal_history', 'tasks', 'deal_line_steps'], 'deal_id = ' . (int)$dX['id']);
$logF = tempnam(sys_get_temp_dir(), 'p2tl');
$oldLog = ini_get('error_log');
ini_set('error_log', $logF);
$err = '';
$r = null;
try { $r = p2t_tick($tp, $today); } catch (Throwable $e) { $err = get_class($e) . ': ' . $e->getMessage(); }
ini_set('error_log', (string)$oldLog);
$log = (string)file_get_contents($logF);
@unlink($logF);
t_eq($err, '', "$c o relógio não lança");
t_eq([(int)p2t_line($tp, $dY['lines']['vfx'])['stage_id'], $r['advanced'] ?? null], [$SB['fronts'][1], 1], "$c o negócio seguinte (Y) foi tratado: a frente avançou");
t_eq(p2v_sig($tp, ['deal_lines', 'deal_history', 'tasks', 'deal_line_steps'], 'deal_id = ' . (int)$dX['id']), $sigX, "$c o negócio partido (X) fica como estava");
t_true(str_contains($log, '[topper] pipe2') && str_contains($log, 'negócio ' . $dX['id']), "$c o erro fica no error_log, com o negócio — obtido " . var_export($log, true));

// ── ::cron_and_request (Review Focus 6, E3, E4) ─────────────
$c = '::cron_and_request';
for ($round = 1; $round <= 5; $round++) {
    $dc = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'front_at' => ['vfx' => $S['revisions'], '3d' => $S3['revisions']]]);
    $all[] = $dc['id'];
    p2t_only($tp, $all, [$dc['id']]);
    $gv = p2v_task($tp, $dc['id'], ['title' => 'corrida VFX', 'line_id' => $dc['lines']['vfx'], 'stage_id' => $S['revisions'], 'is_gate' => 1]);
    $g3 = p2v_task($tp, $dc['id'], ['title' => 'corrida 3D', 'line_id' => $dc['lines']['3d'], 'stage_id' => $S3['revisions'], 'is_gate' => 1]);
    p2t_close($tp, $gv);   // o relógio vai levar a VFX a Pronto
    $r = p2v_race([['pipe2_race.php', ['--db=' . $db, '--uid=0', '--do=tick', '--today=' . $today]],
                   ['pipe2_race.php', ['--db=' . $db, '--uid=99', '--task=' . $g3]]]);
    $why = ' (ronda ' . $round . ': ' . trim(($r[0]['err'] ?? '') . ' ' . ($r[1]['err'] ?? '') . ' ' . ($r[0]['json']['error'] ?? '') . ' ' . ($r[1]['json']['error'] ?? '')) . ')';
    t_eq([$r[0]['code'], $r[1]['code'], $r[0]['json']['ok'] ?? null, $r[1]['json']['ok'] ?? null], [0, 0, true, true], "$c ronda $round: o relógio e o pedido saem bem" . $why);
    t_true(!preg_match('/Warning|Notice|Deprecated/', $r[0]['err'] . $r[1]['err']), "$c ronda $round: sem avisos de PHP" . $why);
    t_eq([p2t_line($tp, $dc['lines']['vfx'])['status'] ?? '', p2t_line($tp, $dc['lines']['3d'])['status'] ?? ''], ['ready', 'ready'], "$c ronda $round: as duas frentes Prontas");
    t_eq([p2t_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_front' AND to_stage_id = ?", [$dc['id'], $S['ready']]),
          p2t_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_front' AND to_stage_id = ?", [$dc['id'], $S3['ready']])], [1, 1],
         "$c ronda $round: exatamente um p2_front para Pronto por frente");
    t_eq(p2v_rows($tp, "SELECT from_stage_id, to_stage_id FROM deal_history WHERE deal_id = ? AND event = 'stage' ORDER BY id", [$dc['id']]),
         [['from_stage_id' => $S['exec'], 'to_stage_id' => $S['delivery']]], "$c ronda $round: exatamente um 'stage' Em execução → Entrega (nenhum outro)");
    t_eq((int)p2t_deal($tp, $dc['id'])['stage_id'], $S['delivery'], "$c ronda $round: o negócio na Entrega");
    t_eq(p2t_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dc['id'], $rAll]), 1, "$c ronda $round: a tarefa all_ready uma vez");
    t_eq(array_map(fn($x) => p2t_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dc['id'], $x]), $closeV), [1, 1, 1, 1, 1],
         "$c ronda $round: as 5 de fecho uma vez cada");
}

t_done();
