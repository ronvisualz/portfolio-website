<?php
/**
 * Pipeline v2, fase 1 — «Desfazer» de uma frente (D14, nota 4) e o «Anular» em cascata (D13, D18, D29; E5, T4).
 *
 * «Desfazer» devolve a frente à sub-etapa anterior e suspende o avanço automático dessa sub-etapa nessa frente
 * (deal_line_steps.auto_hold_at, auto_hold_why 'undo') até alguém fechar, reabrir, apagar ou mudar a obrigatória de uma
 * tarefa dessa sub-etapa. Na primeira sub-etapa não há para onde voltar.
 *
 * «Anular» em cascata: o «Anular» de uma ação desfaz também os movimentos que ela provocou no mesmo pedido
 * (pipe2_moves: o avanço automático, a frente libertada, as séries fechadas pelo sistema, as tarefas, o histórico, os
 * stage_events, os passos e os avisos), tudo ou nada; não é oferecido depois de um Concluído automático; recusa se
 * alguma frente ou o negócio já não estiver onde o movimento o deixou. Anular o Kickoff tira as frentes que ele criou
 * (D29), salvo se já houver trabalho no Toppernote ou no Review delas. Num negócio v1 nada muda.
 *
 * Corre numa base privada (p2v_db), como num pedido: p2v_as(1), undo_push/undo_run e pipe2_request_reset() entre
 * pedidos. Não escreve na bancada.
 *   php tests/pipe2_undo_test.php
 * Casos: ::undo_suspends_until_mandatory_touch ::undo_first_step_refused ::undo_cascades ::cascade_with_release
 *        ::undo_reopens_closed_series ::undo_kickoff_removes_fronts ::not_offered_after_auto_done ::refuses_if_changed
 *        ::inside_multi ::budget_accept_values ::v1_noop
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/tasks.php';
require_once __DIR__ . '/../admin/inc/pipeline.php';
require_once __DIR__ . '/../admin/inc/pipeline_flow.php';
require_once __DIR__ . '/../admin/inc/budget.php';
require_once __DIR__ . '/../admin/inc/undo.php';
require_once __DIR__ . '/../admin/inc/pipe2_engine.php';

const P2U_CHANGED = 'O registo mudou entretanto; não foi anulado.';
const P2U_WORK = 'Já há trabalho no Toppernote ou no Review destas frentes; não foi anulado.';
const P2U_CLOSED_NOTE = 'Fechada pelo sistema: a frente saiu da sub-etapa';

function p2u_err(callable $fn): string {
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
}

/** O valor de uma coluna (NULL incluído); '(falta)' se a linha ou a chave não existirem. */
function p2u_v(array $row, string $k) {
    return array_key_exists($k, $row) ? $row[$k] : '(falta)';
}

function p2u_line(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deal_lines WHERE id = ?', [$id])[0] ?? [];
}

function p2u_step(PDO $pdo, int $line, int $stage): array {
    return p2v_rows($pdo, 'SELECT * FROM deal_line_steps WHERE line_id = ? AND stage_id = ? ORDER BY id', [$line, $stage])[0] ?? [];
}

function p2u_task(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM tasks WHERE id = ?', [$id])[0] ?? [];
}

function p2u_n(PDO $pdo, string $sql, array $a = []): int {
    return (int)p2v_col($pdo, $sql, $a);
}

/** Quantos dos ids ainda existem na tabela. */
function p2u_left(PDO $pdo, string $table, array $ids): int {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return 0;
    return (int)$pdo->query("SELECT COUNT(*) FROM $table WHERE id IN (" . implode(',', $ids) . ')')->fetchColumn();
}

/** As linhas do task_log de uma tarefa: [[event, user_id, note], …]. */
function p2u_log(PDO $pdo, int $task): array {
    return array_map(fn($r) => [$r['event'], $r['user_id'] === null ? null : (int)$r['user_id'], $r['note']],
        p2v_rows($pdo, 'SELECT event, user_id, note FROM task_log WHERE task_id = ? ORDER BY id', [$task]));
}

/** Uma obrigatória da frente numa sub-etapa. */
function p2u_gate(PDO $pdo, int $deal, int $line, int $stage, string $title, array $o = []): int {
    return p2v_task($pdo, $deal, ['title' => $title, 'line_id' => $line, 'stage_id' => $stage, 'is_gate' => 1] + $o);
}

/** Uma obrigatória do negócio numa etapa, com o título exato (sem o prefixo dos testes). */
function p2u_deal_gate(PDO $pdo, int $deal, int $stage, string $title, array $o = []): int {
    $id = p2v_task($pdo, $deal, ['stage_id' => $stage, 'is_gate' => 1] + $o);
    $pdo->prepare('UPDATE tasks SET title = ? WHERE id = ?')->execute([$title, $id]);
    return $id;
}

/** «Concluir» como em deal_flow.php: o retrato para o «Anular», task_done e o task_log. Devolve o retrato. */
function p2u_done(PDO $pdo, int $id): array {
    $t = task_by_id($pdo, $id);
    $snap = ['id' => $id, 'status' => $t['status'], 'done_at' => $t['done_at'], 'member_id' => $t['member_id'], 'position' => $t['position'], 'help_by' => $t['help_by'] ?? null];
    $snap['spawned'] = task_done($pdo, $id);
    task_log($pdo, $id, 'done', 'Concluída');
    return $snap;
}

/** Os dados guardados na sessão para um código do «Anular». */
function p2u_data(string $tok): array {
    return $_SESSION['undo'][$tok]['data'] ?? [];
}

/** Uma folha de orçamento (como em pipe2_metrics_test): versões com linhas 'other' a valor fixo por funil. */
function p2u_budget(PDO $pdo, int $dealId, array $versions, ?int $accepted = null): array {
    $sheet = pipe2_insert($pdo, 'budget_sheets', ['deal_id' => $dealId]);
    $vids = [];
    foreach (array_values($versions) as $i => $v) {
        $vid = pipe2_insert($pdo, 'budget_versions', ['sheet_id' => $sheet, 'num' => $i + 1, 'occupancy' => 0, 'price' => $v['price'], 'day_hours' => 8]);
        foreach (array_values($v['lines']) as $j => [$fid, $price]) {
            pipe2_insert($pdo, 'budget_lines', ['version_id' => $vid, 'line_key' => 'p2u' . $i . '_' . $j, 'position' => $j, 'kind' => 'other',
                'label' => PIPE2_FUNNEL_PREFIX . 'linha', 'price_unit' => 'fixed', 'unit_price' => $price, 'qty' => 1, 'qty_unit' => 'hour',
                'opt' => 'A', 'funnel_id' => $fid]);
        }
        $vids[] = $vid;
    }
    if ($accepted !== null) $pdo->prepare('UPDATE budget_sheets SET accepted_version_id = ? WHERE id = ?')->execute([$vids[$accepted], $sheet]);
    return [$sheet, $vids];
}

function p2u_values(PDO $pdo, int $dealId): array {
    return array_map(fn($r) => $r['value'], p2v_rows($pdo, 'SELECT value FROM deal_lines WHERE deal_id = ? ORDER BY is_main DESC, position, id', [$dealId]));
}

// ── a base privada, os funis e as regras (tudo antes das leituras) ──
[$db, $tp] = p2v_db();
$w = p2v_world($tp, ['vfx', '3d']);
$S = p2v_stages($tp, $w['vfx']);
$T = p2v_stages($tp, $w['3d'])['fronts'];
[$s1, $s2, $s3] = [$S['fronts'][0], $S['fronts'][1], $S['fronts'][2]];
$K = $S['kickoff'];
$rS2 = p2v_rule($tp, $s2, ['title' => 'P2T · tarefa de S2']);   // f_enter, obrigatória, para a execução
$rKick = p2v_rule($tp, $K, ['title' => 'P2T · obrigatória do Kickoff', 'trig' => 'enter', 'assign_to' => 'owner']);
$v1u = pipe2_funnel($tp, 'v1 undo', true);
$v1x = (int)p2v_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND kind = 'xray' ORDER BY position, id LIMIT 1", [$v1u]);
$v1n = (int)p2v_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND level = 'deal' AND position > (SELECT position FROM funnel_stages WHERE id = ?)
                           ORDER BY position, id LIMIT 1", [$v1u, $v1x]);

// ── ::undo_suspends_until_mandatory_touch (D14) ─────────────
$c = '::undo_suspends_until_mandatory_touch';
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec', 'front_at' => ['vfx' => $s2]]);
$l = $d['lines']['vfx'];
p2v_steps($tp, $l);
$a = p2v_task($tp, $d['id'], ['title' => 'P2T · S1 A', 'line_id' => $l, 'stage_id' => $s1, 'is_gate' => 1, 'status' => 'done']);
p2v_task($tp, $d['id'], ['title' => 'P2T · S1 B', 'line_id' => $l, 'stage_id' => $s1, 'is_gate' => 1, 'status' => 'done']);
$msg = pipe2_front_undo($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $l), 99);
t_eq($msg, 'VFX: voltou a «Turnover e breakdown».', "$c «Desfazer» devolve a mensagem");
$L = p2u_line($tp, $l);
t_eq([(int)$L['stage_id'], $L['status']], [$s1, 'running'], "$c a frente volta a S1");
$st = p2u_step($tp, $l, $s1);
t_eq([($st['auto_hold_at'] ?? null) !== null, $st['auto_hold_why'] ?? ''], [true, 'undo'], "$c S1 fica suspensa (undo)");
$h = p2v_rows($tp, "SELECT note, user_id FROM deal_history WHERE deal_id = ? AND event = 'p2_undo'", [$d['id']]);
t_eq([count($h), $h[0]['note'] ?? '', (int)($h[0]['user_id'] ?? 0)], [1, 'VFX: voltou a «Turnover e breakdown» · avanço automático suspenso nesta sub-etapa', 99],
     "$c o histórico p2_undo, com a pessoa");
t_eq(p2v_rows($tp, "SELECT COUNT(*) AS n FROM tasks WHERE deal_id = ? AND stage_id = ? AND auto_rule_id IS NOT NULL", [$d['id'], $s1])[0]['n'], 0, "$c sem regras novas");
t_eq(pipe2_front_reevaluate($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $l)), 'held', "$c com as obrigatórias de S1 todas feitas, a reavaliação diz held");
t_eq((int)p2u_line($tp, $l)['stage_id'], $s1, "$c e a frente fica em S1");
pipe2_request_reset();
task_reopen($tp, $a, null);
t_eq(p2u_v(p2u_step($tp, $l, $s1), 'auto_hold_at'), null, "$c reabrir uma obrigatória de S1 levanta a suspensão");
t_eq(pipe2_front_reevaluate($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $l)), 'incomplete', "$c e a sub-etapa fica por completar");
pipe2_request_reset();
task_done($tp, $a);
t_eq((int)p2u_line($tp, $l)['stage_id'], $s2, "$c fechá-la leva a frente a S2");
// as unidades não levantam a suspensão
pipe2_request_reset();
pipe2_front_undo($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $l), 99);
pipe2_step_units($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $l), 3, null, 99);
t_true((p2u_step($tp, $l, $s1)['auto_hold_at'] ?? null) !== null, "$c as unidades não levantam a suspensão");
t_eq((int)p2u_line($tp, $l)['stage_id'], $s1, "$c e a frente fica em S1");

// ── ::undo_first_step_refused ───────────────────────────────
$c = '::undo_first_step_refused';
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$l = $d['lines']['vfx'];
p2v_steps($tp, $l);
$sig = p2v_sig($tp, ['deal_lines', 'deal_history', 'deal_line_steps'], 'deal_id = ' . $d['id']);
t_eq(p2u_err(fn() => pipe2_front_undo($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $l), 99)), 'A frente já está na primeira sub-etapa.', "$c na 1.ª sub-etapa: recusado");
t_eq(p2v_sig($tp, ['deal_lines', 'deal_history', 'deal_line_steps'], 'deal_id = ' . $d['id']), $sig, "$c e nada muda");

// A partir daqui, como num pedido de quem está na plataforma (admin).
p2v_as(1, $tp);

// ── ::undo_cascades (D13, E5) ───────────────────────────────
$c = '::undo_cascades';
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$l = $d['lines']['vfx'];
p2v_steps($tp, $l);
$g = p2u_gate($tp, $d['id'], $l, $s1, 'P2T · última de S1');
$L0 = p2u_line($tp, $l);
$steps0 = p2v_sig($tp, ['deal_line_steps'], 'deal_id = ' . $d['id']);
$snap = p2u_done($tp, $g);
$L1 = p2u_line($tp, $l);
t_eq([(int)$L1['stage_id'], $L1['status']], [$s2, 'running'], "$c (antes) fechar a última obrigatória de S1 leva a frente a S2");
$t2 = (int)p2v_col($tp, 'SELECT id FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$d['id'], $rS2]);
t_true($t2 > 0, "$c (antes) a tarefa da regra de S2 nasce");
$mv = pipe2_moves();
$hist = array_merge(...array_map(fn($m) => $m['history'] ?? [], $mv));
$evs = array_merge(...array_map(fn($m) => $m['events'] ?? [], $mv));
t_true(count($mv) === 1 && $hist && $evs, "$c (antes) um movimento no registo do pedido, com histórico e stage_events");
$tok = undo_push('task', $snap);
t_true($tok !== '', "$c undo_push oferece o «Anular»");
t_eq(p2u_data($tok)['p2_moves'] ?? null, $mv, "$c e guarda os movimentos do pedido (p2_moves)");
pipe2_request_reset();
t_eq(p2u_err(fn() => $GLOBALS['p2u_msg'] = undo_run($tp, $tok)), '', "$c undo_run passa");
t_eq($GLOBALS['p2u_msg'] ?? '', 'Tarefa reposta.', "$c com a mensagem «Tarefa reposta.»");
$G = p2u_task($tp, $g);
t_eq([$G['status'] ?? '', p2u_v($G, 'done_at')], ['todo', null], "$c a tarefa volta a todo");
$L2 = p2u_line($tp, $l);
t_eq([(int)$L2['stage_id'], $L2['status'], $L2['stage_entered_at'], $L2['started_at'], $L2['done_at'], $L2['cycle_ym']],
     [$s1, 'running', $L0['stage_entered_at'], $L0['started_at'], $L0['done_at'], $L0['cycle_ym']], "$c a frente volta a S1, com o estado e o stage_entered_at de antes");
t_eq(p2u_left($tp, 'tasks', [$t2]), 0, "$c a tarefa de S2 (por fazer) sai");
t_eq([p2u_left($tp, 'deal_history', $hist), p2u_left($tp, 'stage_events', $evs)], [0, 0], "$c o histórico e os stage_events do movimento saem");
t_eq(p2v_sig($tp, ['deal_line_steps'], 'deal_id = ' . $d['id']), $steps0, "$c as linhas de passos ficam como estavam");
$an = p2v_rows($tp, "SELECT * FROM deal_history WHERE deal_id = ? AND event = 'p2_front' ORDER BY id", [$d['id']]);
t_eq(array_map(fn($r) => [$r['note'], (int)$r['user_id'], (int)$r['from_stage_id'], (int)$r['to_stage_id']], $an),
     [['VFX: Look dev / shot de referência → Turnover e breakdown · Anulado', 1, $s2, $s1]], "$c fica um p2_front «Anulado», de quem anulou");
t_eq(pipe2_front_reevaluate($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $l)), 'incomplete', "$c e a frente não volta a avançar sozinha");

// ── ::cascade_with_release (D8, D13) ────────────────────────
$c = '::cascade_with_release';
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'status' => ['vfx' => 'running', '3d' => 'depwait'], 'front_at' => ['3d' => $T[0]]]);
[$la, $lb] = [$d['lines']['vfx'], $d['lines']['3d']];
p2v_steps($tp, $la);
p2v_steps($tp, $lb);
$dep = pipe2_dep_add($tp, deal_get($tp, $d['id']), $la, $s1, $lb, $T[1], 1);
$g = p2u_gate($tp, $d['id'], $la, $s1, 'P2T · última de S1 (A)');
$B0 = p2u_line($tp, $lb);
$steps0 = p2v_sig($tp, ['deal_line_steps'], 'deal_id = ' . $d['id']);
$snap = p2u_done($tp, $g);
$B1 = p2u_line($tp, $lb);
t_eq([(int)$B1['stage_id'], $B1['status'], p2v_col($tp, 'SELECT met_at FROM deal_line_deps WHERE id = ?', [$dep]) !== null], [$T[1], 'running', true],
     "$c (antes) A sai de S1, a ligação cumpre-se e B entra em Y");
$tok = undo_push('task', $snap);
t_eq(count(p2u_data($tok)['p2_moves'] ?? []), 2, "$c os dois movimentos (A e B) ficam no «Anular»");
pipe2_request_reset();
t_eq(p2u_err(fn() => undo_run($tp, $tok)), '', "$c undo_run passa");
$B2 = p2u_line($tp, $lb);
t_eq([(int)$B2['stage_id'], $B2['status'], $B2['stage_entered_at'], $B2['done_at']], [$T[0], 'depwait', $B0['stage_entered_at'], $B0['done_at']], "$c B volta a À espera, como estava");
t_eq(p2u_v(p2v_rows($tp, 'SELECT met_at FROM deal_line_deps WHERE id = ?', [$dep])[0] ?? [], 'met_at'), null, "$c a ligação volta a por cumprir (met_at NULL)");
t_eq([(int)p2u_line($tp, $la)['stage_id'], p2u_task($tp, $g)['status'] ?? ''], [$s1, 'todo'], "$c A volta a S1 e a tarefa a todo");
t_eq(p2v_sig($tp, ['deal_line_steps'], 'deal_id = ' . $d['id']), $steps0, "$c as linhas de passos de A e de B ficam como estavam");

// ── ::undo_reopens_closed_series (D18, T4) ──────────────────
$c = '::undo_reopens_closed_series';
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$l = $d['lines']['vfx'];
p2v_steps($tp, $l);
$ser = p2v_task($tp, $d['id'], ['title' => 'P2T · série', 'line_id' => $l, 'stage_id' => $s1, 'repeat_rule' => 'weekly', 'due_date' => '2026-11-16', 'is_gate' => 0]);
$g = p2u_gate($tp, $d['id'], $l, $s1, 'P2T · última de S1 (série)');
$Ser0 = p2u_task($tp, $ser);
$snap = p2u_done($tp, $g);
t_eq([(int)p2u_line($tp, $l)['stage_id'], p2u_task($tp, $ser)['status'] ?? ''], [$s2, 'done'], "$c (antes) a frente em S2 e a série fechada pelo sistema");
t_eq(p2u_log($tp, $ser), [['done', null, P2U_CLOSED_NOTE]], "$c (antes) com o task_log do fecho, sem utilizador");
$tok = undo_push('task', $snap);
pipe2_request_reset();
t_eq(p2u_err(fn() => undo_run($tp, $tok)), '', "$c undo_run passa");
t_eq((int)p2u_line($tp, $l)['stage_id'], $s1, "$c a frente volta a S1");
$Ser = p2u_task($tp, $ser);
t_eq([$Ser['status'] ?? '', p2u_v($Ser, 'done_at')], [$Ser0['status'], $Ser0['done_at']], "$c a instância da série volta a estar aberta (estado e done_at de antes)");
t_eq(p2u_log($tp, $ser), [], "$c sem a linha do task_log do fecho");
t_eq(p2u_log($tp, $g), [['done', 1, 'Concluída']], "$c o task_log da obrigatória fica como o «Anular» da v1 o deixa");
t_eq(p2u_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND title = ?', [$d['id'], 'P2T · série']), 1, "$c e não nasce outra instância da série");

// ── ::undo_kickoff_removes_fronts (D29) ─────────────────────
$c = '::undo_kickoff_removes_fronts';
pipe2_request_reset();
$dk = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'feedback']);
$tp->prepare('DELETE FROM deal_lines WHERE id = ?')->execute([$dk['lines']['vfx']]);   // a principal está por criar
$l3 = $dk['lines']['3d'];
$Dk0 = p2v_rows($tp, 'SELECT * FROM deals WHERE id = ?', [$dk['id']])[0];
$L30 = p2u_line($tp, $l3);
$snap = undo_deal_snapshot(deal_get($tp, $dk['id']));
t_eq([array_column($snap['p2']['lines'] ?? [], 'id'), $snap['p2']['steps_max'] ?? null, $snap['p2']['deps_max'] ?? null, $snap['p2']['category'] ?? null,
      array_key_exists('category_at', $snap['p2'] ?? []) ? $snap['p2']['category_at'] : '(falta)'],
     [[$l3], 0, 0, (string)$Dk0['category'], $Dk0['category_at']], "$c o retrato do negócio v2 tem as frentes, os máximos e a categoria (p2)");
flow_created(null, true);
t_eq(p2u_err(fn() => deal_move($tp, deal_get($tp, $dk['id']), $K, 'teste')), '', "$c deal_move para o Kickoff (forçado por admin) passa");
$data = $snap + ['to_stage' => $K, 'auto_tasks' => flow_created()];
$main = (int)p2v_col($tp, 'SELECT id FROM deal_lines WHERE deal_id = ? AND funnel_id = ?', [$dk['id'], $w['vfx']]);
$boards = array_map('intval', array_column(p2v_rows($tp, 'SELECT id FROM cc_boards WHERE line_id = ? ORDER BY id', [$main]), 'id'));
$kt = (int)p2v_col($tp, 'SELECT id FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$dk['id'], $rKick]);
t_true($main > 0 && (string)p2u_line($tp, $l3)['status'] === 'queued' && p2u_n($tp, 'SELECT COUNT(*) FROM deal_line_steps WHERE deal_id = ?', [$dk['id']]) > 0,
       "$c (antes) a principal nasceu, o serviço ficou Por começar e há linhas de passos");
t_true(count($boards) > 0 && $kt > 0, "$c (antes) há Topperboards da frente nova e a tarefa do Kickoff");
$sigOf = fn() => [p2v_sig($tp, ['deal_lines', 'deal_line_steps', 'deal_line_deps', 'tasks', 'deal_history'], 'deal_id = ' . $dk['id']),
                  p2v_sig($tp, ['deals'], 'id = ' . $dk['id']), p2v_sig($tp, ['cc_boards'], 'deal_id = ' . $dk['id']),
                  p2u_n($tp, 'SELECT COUNT(*) FROM cc_cards WHERE board_id IN (SELECT id FROM cc_boards WHERE deal_id = ?)', [$dk['id']])];
// (a) com um cartão num quadro da frente: recusa e nada muda
$now = date('Y-m-d H:i:s');
$card = pipe2_insert($tp, 'cc_cards', ['board_id' => $boards[0], 'type' => 'note', 'x' => 0, 'y' => 0, 'w' => 240, 'h' => 0, 'content' => '{"text":"P2T"}',
                                       'created_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
$sig = $sigOf();
t_eq(p2u_err(fn() => undo_apply($tp, 'deal_stage', $data)), P2U_WORK, "$c com um cartão num quadro da frente: «Já há trabalho…»");
t_eq($sigOf(), $sig, "$c e nada muda");
$tp->prepare('DELETE FROM cc_cards WHERE id = ?')->execute([$card]);
// (b) com uma peça de Review na frente: recusa
$item = pipe2_insert($tp, 'cc_rv_items', ['deal_id' => $dk['id'], 'line_id' => $main, 'title' => 'P2T · peça', 'created_at' => $now, 'updated_at' => $now]);
$sig = $sigOf();
t_eq(p2u_err(fn() => undo_apply($tp, 'deal_stage', $data)), P2U_WORK, "$c com uma peça de Review na frente: «Já há trabalho…»");
t_eq($sigOf(), $sig, "$c e nada muda");
$tp->prepare('DELETE FROM cc_rv_items WHERE id = ?')->execute([$item]);
// (c) sem trabalho: anula
t_eq(p2u_err(fn() => $GLOBALS['p2u_msg'] = undo_apply($tp, 'deal_stage', $data)), '', "$c sem trabalho: undo_apply('deal_stage') passa");
t_eq($GLOBALS['p2u_msg'] ?? '', 'Etapa reposta.', "$c com a mensagem de sempre");
$Dk = p2v_rows($tp, 'SELECT * FROM deals WHERE id = ?', [$dk['id']])[0];
t_eq([(int)$Dk['stage_id'], $Dk['phase']], [(int)$Dk0['stage_id'], 'pipeline'], "$c o negócio volta a Aguardar feedback");
t_eq(p2u_line($tp, $main), [], "$c a principal criada pelo Kickoff sai");
$L3 = p2u_line($tp, $l3);
t_eq([$L3['status'], p2u_v($L3, 'stage_id'), p2u_v($L3, 'exec_owner_id'), p2u_v($L3, 'value'), (int)$L3['is_main']],
     [$L30['status'], $L30['stage_id'], $L30['exec_owner_id'], $L30['value'], (int)$L30['is_main']], "$c o serviço volta a planned, como estava");
t_eq([p2u_n($tp, 'SELECT COUNT(*) FROM deal_line_steps WHERE deal_id = ?', [$dk['id']]), p2u_n($tp, 'SELECT COUNT(*) FROM deal_line_deps WHERE deal_id = ?', [$dk['id']])],
     [0, 0], "$c 0 linhas de passos e 0 ligações");
t_eq([(string)$Dk['category'], $Dk['category_at']], [(string)$Dk0['category'], $Dk0['category_at']], "$c category e category_at como estavam");
t_eq([p2u_left($tp, 'cc_boards', $boards), p2u_n($tp, 'SELECT COUNT(*) FROM cc_cards WHERE ref_board_id IN (' . implode(',', $boards ?: [0]) . ')')], [0, 0],
     "$c os Topperboards da frente nova saem, com os cartões que apontavam para eles");
t_eq(p2u_left($tp, 'tasks', [$kt]), 0, "$c a tarefa automática do Kickoff (por fazer) sai");

// ── ::not_offered_after_auto_done (D13, D16) ────────────────
$c = '::not_offered_after_auto_done';
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'delivery', 'status' => 'ready']);
p2u_deal_gate($tp, $d['id'], $S['delivery'], 'Validar pagamento final · X', ['status' => 'done', 'doc_kind' => 'payment']);
$apr = p2u_deal_gate($tp, $d['id'], $S['delivery'], 'Aprovação final do cliente por escrito');
$snap = p2u_done($tp, $apr);
t_eq([(string)p2v_col($tp, 'SELECT phase FROM deals WHERE id = ?', [$d['id']]), (int)p2v_col($tp, 'SELECT stage_id FROM deals WHERE id = ?', [$d['id']])], ['done', $S['done']],
     "$c (antes) a última obrigatória leva ao Concluído automático");
$before = array_keys($_SESSION['undo'] ?? []);
t_eq(undo_push('task', $snap), '', "$c undo_push('task') devolve '' (sem «Anular»)");
t_eq(array_keys($_SESSION['undo'] ?? []), $before, "$c e não guarda nada na sessão");

// ── ::refuses_if_changed (D13) ──────────────────────────────
$c = '::refuses_if_changed';
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$l = $d['lines']['vfx'];
p2v_steps($tp, $l);
$g = p2u_gate($tp, $d['id'], $l, $s1, 'P2T · última de S1 (mudou)');
$snap = p2u_done($tp, $g);
$tok = undo_push('task', $snap);
// outro pedido: a tarefa de S2 fica feita e alguém passa a frente a S3
pipe2_request_reset();
$tp->prepare("UPDATE tasks SET status = 'done', done_at = ? WHERE deal_id = ? AND auto_rule_id = ?")->execute([date('Y-m-d H:i:s'), $d['id'], $rS2]);
t_eq(p2u_err(fn() => pipe2_front_move($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $l), $s3, '', 1)), '', "$c (antes) o movimento humano para S3 passa");
pipe2_request_reset();
$tabs = ['deal_lines', 'tasks', 'deal_history', 'deal_line_steps', 'stage_events', 'deal_line_deps'];
$sig = [p2v_sig($tp, $tabs, 'deal_id = ' . $d['id']), p2v_sig($tp, ['deals'], 'id = ' . $d['id'])];
t_eq(p2u_err(fn() => undo_run($tp, $tok)), P2U_CHANGED, "$c o «Anular» do 1.º pedido: «O registo mudou entretanto; não foi anulado.»");
t_eq([p2v_sig($tp, $tabs, 'deal_id = ' . $d['id']), p2v_sig($tp, ['deals'], 'id = ' . $d['id'])], $sig, "$c e nada muda");
t_eq([p2u_task($tp, $g)['status'] ?? '', (int)p2u_line($tp, $l)['stage_id']], ['done', $s3], "$c (a tarefa continua done e a frente em S3)");
t_true(!$tp->inTransaction(), "$c sem transação aberta");

// ── ::inside_multi (D13) ────────────────────────────────────
$c = '::inside_multi';
// (a) o 'multi' leva os movimentos do pedido
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$l = $d['lines']['vfx'];
p2v_steps($tp, $l);
$g = p2u_gate($tp, $d['id'], $l, $s1, 'P2T · última de S1 (multi a)');
$snap = p2u_done($tp, $g);
$tok = undo_push('multi', ['subs' => [['task', $snap]], 'msg' => 'Alterações repostas.']);
t_true(!empty(p2u_data($tok)['p2_moves']), "$c (a) o 'multi' guarda os movimentos");
pipe2_request_reset();
t_eq(p2u_err(fn() => $GLOBALS['p2u_msg'] = undo_run($tp, $tok)), '', "$c (a) undo_run do 'multi' passa (sem «already an active transaction»)");
t_eq([$GLOBALS['p2u_msg'] ?? '', p2u_task($tp, $g)['status'] ?? '', (int)p2u_line($tp, $l)['stage_id'], $tp->inTransaction()], ['Alterações repostas.', 'todo', $s1, false],
     "$c (a) tudo desfeito numa só transação, já fechada");
// (b) o 'task' dentro do 'multi' é que leva os movimentos
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$l = $d['lines']['vfx'];
p2v_steps($tp, $l);
$g = p2u_gate($tp, $d['id'], $l, $s1, 'P2T · última de S1 (multi b)');
$snap = p2u_done($tp, $g);
$sub = p2u_data(undo_push('task', $snap));
pipe2_request_reset();
$tok = undo_push('multi', ['subs' => [['task', $sub]], 'msg' => 'Alterações repostas.']);
t_true(!empty($sub['p2_moves']) && empty(p2u_data($tok)['p2_moves']), "$c (b) os movimentos estão só no 'task'");
pipe2_request_reset();
t_eq(p2u_err(fn() => undo_run($tp, $tok)), '', "$c (b) undo_run do 'multi' passa");
t_eq([p2u_task($tp, $g)['status'] ?? '', (int)p2u_line($tp, $l)['stage_id'], $tp->inTransaction()], ['todo', $s1, false], "$c (b) a tarefa e a frente repostas");

// ── ::budget_accept_values (X3) ─────────────────────────────
$c = '::budget_accept_values';
pipe2_request_reset();
$bv = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'budget' => 0, 'value_estimate' => 4000]);
[$bSheet, $bVer] = p2u_budget($tp, $bv['id'], [['price' => 4000, 'lines' => [[$w['vfx'], 300], [$w['3d'], 100]]],
                                               ['price' => 8000, 'lines' => [[$w['vfx'], 100], [$w['3d'], 300]]]], 0);
pipe2_store_values($tp, deal_get($tp, $bv['id']));
t_eq(p2u_values($tp, $bv['id']), ['3000.00', '1000.00'], "$c (antes) 4000 repartidos 300/100");
$sheet = budget_sheet_by_id($tp, $bSheet);
$ver = budget_version_get($tp, $bVer[1]);
$bsnap = ['sheet' => $bSheet, 'version' => $bVer[1], 'prev' => $bVer[0], 'occupancy' => $ver['occupancy'], 'value' => '4000.00', 'deal' => $bv['id'],
          'opt' => (string)($ver['accepted_opt'] ?? '')];
budget_accept($tp, $sheet, $ver, 1);
t_eq(p2u_values($tp, $bv['id']), ['2000.00', '6000.00'], "$c (antes) aceitar a v2 reparte 8000 100/300");
$tok = undo_push('budget_accept', $bsnap);
pipe2_request_reset();
t_eq(p2u_err(fn() => undo_run($tp, $tok)), '', "$c anular a aceitação passa");
t_eq([(string)p2v_col($tp, 'SELECT value_estimate FROM deals WHERE id = ?', [$bv['id']]), p2u_values($tp, $bv['id'])], ['4000.00', ['3000.00', '1000.00']],
     "$c o valor do negócio e os das frentes voltam aos de antes");

// ── ::v1_noop (regra de ouro) ───────────────────────────────
$c = '::v1_noop';
pipe2_request_reset();
$dv = pipe2_deal($tp, ['phase' => 'pipeline', 'funnel_id' => $v1u, 'stage_id' => $v1x]);
$p2sig = fn() => [p2v_sig($tp, ['deal_lines', 'deal_line_steps', 'deal_line_deps', 'pipe2_notices'], 'deal_id = ' . $dv), p2u_n($tp, 'SELECT COUNT(*) FROM cc_boards WHERE deal_id = ? AND line_id IS NOT NULL', [$dv])];
$p20 = $p2sig();
$Dv0 = p2v_rows($tp, 'SELECT * FROM deals WHERE id = ?', [$dv])[0];
$vsnap = undo_deal_snapshot(deal_get($tp, $dv));
t_eq(array_keys($vsnap), ['id', 'stage_id', 'phase', 'done_at', 'year', 'month', 'last_touch'], "$c deal_stage: o retrato de um v1 não tem 'p2'");
flow_created(null, true);
deal_move($tp, deal_get($tp, $dv), $v1n, 'teste');
$tok = undo_push('deal_stage', $vsnap + ['to_stage' => $v1n, 'auto_tasks' => flow_created()]);
t_true($tok !== '' && !array_key_exists('p2_moves', p2u_data($tok)), "$c deal_stage: os dados não têm p2_moves");
pipe2_request_reset();
t_eq(p2u_err(fn() => $GLOBALS['p2u_msg'] = undo_run($tp, $tok)), '', "$c deal_stage: undo_run passa");
$Dv = p2v_rows($tp, 'SELECT * FROM deals WHERE id = ?', [$dv])[0];
t_eq([$GLOBALS['p2u_msg'] ?? '', (int)$Dv['stage_id'], $Dv['phase']], ['Etapa reposta.', $v1x, $Dv0['phase']], "$c deal_stage: a etapa reposta, como antes");
$an = p2v_rows($tp, "SELECT note, to_stage_id FROM deal_history WHERE deal_id = ? AND event = 'stage' ORDER BY id DESC LIMIT 1", [$dv]);
t_eq([$an[0]['note'] ?? '', (int)($an[0]['to_stage_id'] ?? 0)], ['Anulado', $v1x], "$c deal_stage: o 'stage' «Anulado» de sempre");
$tv = p2v_task($tp, $dv, ['title' => 'P2T · tarefa v1']);
pipe2_request_reset();
$snap = p2u_done($tp, $tv);
$tok = undo_push('task', $snap);
t_true($tok !== '' && !array_key_exists('p2_moves', p2u_data($tok)), "$c task: os dados não têm p2_moves");
pipe2_request_reset();
t_eq(p2u_err(fn() => $GLOBALS['p2u_msg'] = undo_run($tp, $tok)), '', "$c task: undo_run passa");
$Tv = p2u_task($tp, $tv);
t_eq([$GLOBALS['p2u_msg'] ?? '', $Tv['status'] ?? '', p2u_v($Tv, 'done_at')], ['Tarefa reposta.', 'todo', null], "$c task: a tarefa reposta, como antes");
t_eq($p2sig(), $p20, "$c nada da v2 escrito (frentes, passos, ligações, avisos, quadros de frente)");
t_true(!$tp->inTransaction(), "$c sem transação aberta");

p2v_as(null);
t_done();
