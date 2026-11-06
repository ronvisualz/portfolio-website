<?php
/**
 * Pipeline v2, fase 1 — o motor das frentes (admin/inc/pipe2_engine.php; tarefa 10: D5, D7, D9, D14, D18; E3, E4;
 * nota 4; Review Focus 2, 3 e 7).
 *
 * Avanço automático: quando todas as obrigatórias da sub-etapa (no ciclo da frente) estão feitas — e as unidades, se
 * houver —, a frente entra na sub-etapa seguinte, uma vez por pedido, com os interruptores serviço → sub-etapa →
 * frente e sem a suspensão do «Desfazer». O movimento é um UPDATE condicional dentro do lock do negócio; fica como
 * «Sistema» (user_id NULL) também com sessão. Os movimentos humanos andam uma sub-etapa de cada vez, com o gate
 * completo (só um admin força, com motivo) e, para trás, suspendem o destino. A reavaliação corre em task_done,
 * ao reabrir (nunca recua), ao apagar pela Agenda e ao mudar a obrigatória; nunca anula a ação que a chamou. Num
 * negócio v1 os ganchos não escrevem nada.
 *
 * Corre numa base privada (p2v_db), salvo a parte «apagar pela Agenda» do ::reevaluate_hooks, que corre na bancada
 * (HTTP em 127.0.0.1:8765, uid 1, com pipe2_lock) e só escreve num funil e num negócio «P2T» deste teste.
 *   php tests/pipe2_autoadvance_test.php
 * Casos: ::advance_on_last_mandatory ::no_mandatory_no_advance ::one_step_per_request ::concurrent_last_mandatory
 *        ::conditional_update ::switches ::reevaluate_hooks ::system_actor ::no_session ::never_cancels_completion
 *        ::reentry_no_duplicates ::retired_stage_next ::human_move_rules ::start ::v1_noop
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/tasks.php';
require_once __DIR__ . '/../admin/inc/pipe2_engine.php';

$bench = $pdo;
pipe2_lock($bench);
pipe2_cleanup($bench);   // sobras «P2T» de uma corrida interrompida

/** [classe, mensagem] da exceção de $fn, ou ['', '']. */
function p2a_err(callable $fn): array {
    try { $fn(); } catch (Throwable $e) { return [get_class($e), $e->getMessage()]; }
    return ['', ''];
}

/** O valor de uma coluna (NULL incluído); '(falta)' se a linha ou a chave não existirem. */
function p2a_v(array $row, string $k) {
    return array_key_exists($k, $row) ? $row[$k] : '(falta)';
}

function p2a_line(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deal_lines WHERE id = ?', [$id])[0] ?? [];
}

function p2a_n(PDO $pdo, string $sql, array $a = []): int {
    return (int)p2v_col($pdo, $sql, $a);
}

/** A linha de passos (frente, sub-etapa, ciclo da frente). */
function p2a_step(PDO $pdo, int $line, int $stage): array {
    return p2v_rows($pdo, 'SELECT s.* FROM deal_line_steps s JOIN deal_lines l ON l.id = s.line_id AND l.cycle_ym = s.cycle_ym
                            WHERE s.line_id = ? AND s.stage_id = ? ORDER BY s.id', [$line, $stage])[0] ?? [];
}

/** Um negócio v2 de teste (vfx) em Em execução com a frente em curso na 1.ª sub-etapa (ou $o['front_at']) e as linhas de passos. */
function p2a_deal(PDO $pdo, array $w, array $o = [], array $steps = []): array {
    $d = p2v_deal($pdo, $w, ['vfx'], ['at' => 'exec'] + $o);
    $l = $d['lines']['vfx'];
    p2v_steps($pdo, $l, $steps);
    return [$d['id'], $l];
}

/** Uma obrigatória da frente numa sub-etapa. */
function p2a_gate(PDO $pdo, int $deal, int $line, int $stage, string $title, array $o = []): int {
    return p2v_task($pdo, $deal, ['title' => $title, 'line_id' => $line, 'stage_id' => $stage, 'is_gate' => 1] + $o);
}

function p2a_reeval(PDO $pdo, int $deal, int $line): string {
    return pipe2_front_reevaluate($pdo, deal_get($pdo, $deal), pipe2_front_get($pdo, $line));
}

/** Os movimentos p2_front do histórico de um negócio (por id). */
function p2a_moves(PDO $pdo, int $deal): array {
    return p2v_rows($pdo, "SELECT * FROM deal_history WHERE deal_id = ? AND event = 'p2_front' ORDER BY id", [$deal]);
}

// ── a base privada, os funis e as regras (tudo antes das leituras) ──
[$db, $tp] = p2v_db();
$w = p2v_world($tp, ['vfx', '3d']);
$S = p2v_stages($tp, $w['vfx']);
[$s1, $s2, $s3, $s4, $s5, $s6] = $S['fronts'];
$K = $S['kickoff'];
$rS2 = p2v_rule($tp, $s2, ['title' => 'P2T · tarefa de S2']);   // f_enter, obrigatória, para a execução
$v1f = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$v1k = (int)p2v_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND kind = 'kickoff' ORDER BY position, id LIMIT 1", [$v1f]);
$v13d = (int)p2v_col($tp, "SELECT id FROM funnels WHERE service_code = '3d' AND is_base = 1 ORDER BY id LIMIT 1");
$today = today_lisbon();

// ── ::advance_on_last_mandatory ─────────────────────────────
$c = '::advance_on_last_mandatory';
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
$g1 = p2a_gate($tp, $d, $l, $s1, 'P2T · obrigatória 1');
$g2 = p2a_gate($tp, $d, $l, $s1, 'P2T · obrigatória 2');
task_done($tp, $g1);
t_eq((int)p2a_line($tp, $l)['stage_id'], $s1, "$c com uma das duas feita, a frente fica em S1");
t_eq(p2a_moves($tp, $d), [], "$c e sem movimento no histórico");
pipe2_request_reset();
task_done($tp, $g2);
$L = p2a_line($tp, $l);
t_eq([(int)$L['stage_id'], $L['status']], [$s2, 'running'], "$c com as duas feitas, a frente entra em S2");
$t2 = p2v_rows($tp, 'SELECT * FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$d, $rS2]);
t_eq(count($t2), 1, "$c a tarefa da regra de S2 nasce");
t_eq([(int)($t2[0]['line_id'] ?? 0), (int)($t2[0]['stage_id'] ?? 0), strlen((string)($t2[0]['dedup_key'] ?? '')), (int)($t2[0]['is_gate'] ?? 0)], [$l, $s2, 40, 1],
     "$c com a frente, a sub-etapa S2, a chave de deduplicação e obrigatória");
$h = p2a_moves($tp, $d);
t_eq(count($h), 1, "$c um p2_front no histórico");
t_eq([p2a_v($h[0] ?? [], 'user_id'), (int)($h[0]['from_stage_id'] ?? 0), (int)($h[0]['to_stage_id'] ?? 0), (int)($h[0]['to_funnel_id'] ?? 0)], [null, $s1, $s2, $w['vfx']],
     "$c do «Sistema», de S1 para S2, no funil da frente");
t_true(str_contains((string)($h[0]['note'] ?? ''), 'Avanço automático'), "$c a nota diz «Avanço automático»");
t_eq((string)($h[0]['note'] ?? ''), 'VFX: Turnover e breakdown → Look dev / shot de referência · Avanço automático', "$c a nota com o serviço e as sub-etapas");
t_eq(p2a_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'stage'", [$d]), 0, "$c nenhum 'stage' do negócio");
$ev = p2v_rows($tp, "SELECT * FROM stage_events WHERE deal_id = ? AND step = 'p2_front'", [$d]);
t_eq(count($ev), 1, "$c um stage_events p2_front");
t_eq([(int)($ev[0]['line_id'] ?? 0), (int)($ev[0]['from_stage_id'] ?? 0), (int)($ev[0]['to_stage_id'] ?? 0), p2a_v($ev[0] ?? [], 'user_id'), (string)($ev[0]['reason'] ?? '')],
     [$l, $s1, $s2, null, 'auto'], "$c com a frente, de S1 para S2, sem utilizador, motivo auto");
t_eq((string)p2v_col($tp, 'SELECT last_touch FROM deals WHERE id = ?', [$d]), $today, "$c deals.last_touch = hoje");
$st1 = p2a_step($tp, $l, $s1);
$st2 = p2a_step($tp, $l, $s2);
t_true(($st1['done_at'] ?? null) !== null, "$c a linha de passos de S1 fica com done_at");
t_true(($st2['started_at'] ?? null) !== null && p2a_v($st2, 'done_at') === null, "$c a de S2 com started_at e sem done_at");
$mv = pipe2_moves();
t_eq(count($mv), 1, "$c um movimento no registo do pedido");
t_eq([$mv[0]['kind'] ?? '', (int)($mv[0]['line'] ?? 0), $mv[0]['to'] ?? null, in_array((int)$t2[0]['id'], $mv[0]['tasks'] ?? [], true), $mv[0]['undoable'] ?? null],
     ['front', $l, [$s2, 'running'], true, true], "$c o registo tem a frente, o destino e a tarefa criada");
t_eq(array_slice($mv[0]['from'] ?? [], 0, 2), [$s1, 'running'], "$c e de onde veio");
t_eq(count($mv[0]['history'] ?? []) >= 1 && count($mv[0]['events'] ?? []) === 1, true, "$c com o histórico e o stage_events do movimento");

// ── ::no_mandatory_no_advance (E3) ──────────────────────────
$c = '::no_mandatory_no_advance';
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
$n = p2v_task($tp, $d, ['title' => 'P2T · normal', 'line_id' => $l, 'stage_id' => $s1, 'is_gate' => 0]);
task_done($tp, $n);
t_eq((int)p2a_line($tp, $l)['stage_id'], $s1, "$c sem obrigatórias, fechar uma tarefa normal não avança");
t_eq(p2a_reeval($tp, $d, $l), 'no_mandatory', "$c a reavaliação diz no_mandatory");
$tp->prepare('UPDATE deal_line_steps SET units_plan = 10 WHERE line_id = ? AND stage_id = ?')->execute([$l, $s1]);
t_eq(p2a_reeval($tp, $d, $l), 'incomplete', "$c com 10 unidades previstas e 0 feitas: incomplete");
pipe2_step_units($tp, deal_get($tp, $d), pipe2_front_get($tp, $l), 10, null, 99);
t_eq((int)p2a_line($tp, $l)['stage_id'], $s2, "$c com as 10 unidades feitas, avança");
t_eq((int)(p2a_step($tp, $l, $s1)['units_done'] ?? -1), 10, "$c as unidades ficam gravadas");
t_eq(p2a_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_units'", [$d]), 1, "$c e no histórico (p2_units)");

// ── ::one_step_per_request (E3) ─────────────────────────────
$c = '::one_step_per_request';
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
$a = p2a_gate($tp, $d, $l, $s1, 'P2T · a de S1');
$b2 = p2a_gate($tp, $d, $l, $s2, 'P2T · tarefa de S2', ['auto_rule_id' => $rS2, 'status' => 'done']);   // já feita
task_done($tp, $a);
t_eq((int)p2a_line($tp, $l)['stage_id'], $s2, "$c um pedido leva a frente só a S2");
t_eq(p2a_reeval($tp, $d, $l), 'once', "$c outra reavaliação no mesmo pedido: once");
t_eq((int)p2a_line($tp, $l)['stage_id'], $s2, "$c e a frente fica em S2");
pipe2_request_reset();
t_eq(p2a_reeval($tp, $d, $l), 'advanced', "$c no pedido seguinte avança");
t_eq((int)p2a_line($tp, $l)['stage_id'], $s3, "$c para S3");

// ── ::concurrent_last_mandatory (Review Focus 7) ────────────
$c = '::concurrent_last_mandatory';
for ($round = 1; $round <= 5; $round++) {
    [$d, $l] = p2a_deal($tp, $w);
    $a = p2a_gate($tp, $d, $l, $s1, 'P2T · corrida A');
    $b = p2a_gate($tp, $d, $l, $s1, 'P2T · corrida B');
    $r = p2v_race([['pipe2_race.php', ['--db=' . $db, '--uid=99', '--task=' . $a]], ['pipe2_race.php', ['--db=' . $db, '--uid=1', '--task=' . $b]]]);
    $why = ' (ronda ' . $round . ': ' . trim(($r[0]['err'] ?? '') . ' ' . ($r[1]['err'] ?? '')) . ')';
    t_eq([$r[0]['code'], $r[1]['code'], $r[0]['json']['ok'] ?? null, $r[1]['json']['ok'] ?? null], [0, 0, true, true], "$c ronda $round: os dois saem bem" . $why);
    t_true(!preg_match('/Warning|Notice|Deprecated|Duplicate entry/', $r[0]['err'] . $r[1]['err']), "$c ronda $round: sem avisos de PHP nem duplicados" . $why);
    t_eq(p2a_n($tp, "SELECT COUNT(*) FROM tasks WHERE id IN (?, ?) AND status = 'done'", [$a, $b]), 2, "$c ronda $round: as duas feitas");
    t_eq(p2a_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_front' AND from_stage_id = ? AND to_stage_id = ?", [$d, $s1, $s2]), 1,
         "$c ronda $round: exatamente um movimento S1 → S2");
    t_eq(p2a_n($tp, "SELECT COUNT(*) FROM stage_events WHERE deal_id = ? AND step = 'p2_front'", [$d]), 1, "$c ronda $round: um stage_events");
    t_eq((int)p2a_line($tp, $l)['stage_id'], $s2, "$c ronda $round: a frente em S2 (não S3)");
    t_eq(p2a_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$d, $rS2]), 1, "$c ronda $round: a tarefa de S2 criada uma vez");
    t_eq(p2v_rows($tp, 'SELECT line_id, stage_id, cycle_ym, COUNT(*) AS n FROM deal_line_steps WHERE deal_id = ? GROUP BY line_id, stage_id, cycle_ym HAVING COUNT(*) > 1', [$d]), [],
         "$c ronda $round: uma linha de passos por frente, sub-etapa e ciclo");
}

// ── ::conditional_update (D7) ───────────────────────────────
$c = '::conditional_update';
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
$old = pipe2_front_get($tp, $l);
$dealOld = deal_get($tp, $d);
t_eq(pipe2_front_enter($tp, $dealOld, $old, stage_get($tp, $s2)), 'entered', "$c o 1.º entra");
$sig = p2v_sig($tp, ['deal_lines', 'deal_history', 'stage_events', 'deal_line_steps', 'tasks'], 'deal_id = ' . $d);
$nm = count(pipe2_moves());
t_eq(pipe2_front_enter($tp, $dealOld, $old, stage_get($tp, $s2)), 'lost', "$c o 2.º, com a frente lida antes, perde");
t_eq(p2v_sig($tp, ['deal_lines', 'deal_history', 'stage_events', 'deal_line_steps', 'tasks'], 'deal_id = ' . $d), $sig, "$c e não grava nada");
t_eq(count(pipe2_moves()), $nm, "$c nem regista movimento");

// ── ::switches (D5) ─────────────────────────────────────────
$c = '::switches';
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
p2a_gate($tp, $d, $l, $s1, 'P2T · feita', ['status' => 'done']);
$tp->prepare('UPDATE funnels SET auto_advance = 0 WHERE id = ?')->execute([$w['vfx']]);
t_eq(p2a_reeval($tp, $d, $l), 'off', "$c o serviço desligado → off");
$tp->prepare('UPDATE funnels SET auto_advance = 1 WHERE id = ?')->execute([$w['vfx']]);
$tp->prepare('UPDATE funnel_stages SET auto_advance = 0 WHERE id = ?')->execute([$s1]);
t_eq(p2a_reeval($tp, $d, $l), 'off', "$c a sub-etapa desligada → off");
$tp->prepare('UPDATE funnel_stages SET auto_advance = 1 WHERE id = ?')->execute([$s1]);
p2v_front($tp, $l, ['auto_advance' => 0]);
t_eq(p2a_reeval($tp, $d, $l), 'off', "$c a frente desligada → off");
p2v_front($tp, $l, ['auto_advance' => 1]);
$tp->prepare('UPDATE funnels SET auto_advance = 0 WHERE id = ?')->execute([$w['vfx']]);
t_eq(p2a_reeval($tp, $d, $l), 'off', "$c a frente ligada não contraria o serviço desligado → off");
$tp->prepare('UPDATE funnels SET auto_advance = 1 WHERE id = ?')->execute([$w['vfx']]);
t_eq((int)p2a_line($tp, $l)['stage_id'], $s1, "$c com os interruptores desligados não andou");
p2v_front($tp, $l, ['auto_advance' => null]);
t_eq(p2a_reeval($tp, $d, $l), 'advanced', "$c a frente a seguir (NULL) com tudo ligado → avança");
t_eq((int)p2a_line($tp, $l)['stage_id'], $s2, "$c para S2");
// o interruptor da frente pela função (NULL / 0 / 1), com histórico; ao ligar, reavalia
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
p2a_gate($tp, $d, $l, $s1, 'P2T · feita', ['status' => 'done']);
pipe2_front_set_auto($tp, deal_get($tp, $d), pipe2_front_get($tp, $l), false, 99);
t_eq([p2a_line($tp, $l)['auto_advance'], (int)p2a_line($tp, $l)['stage_id']], [0, $s1], "$c pipe2_front_set_auto(false) grava 0 e não reavalia");
pipe2_front_set_auto($tp, deal_get($tp, $d), pipe2_front_get($tp, $l), null, 99);
t_eq([p2a_line($tp, $l)['auto_advance'], (int)p2a_line($tp, $l)['stage_id']], [null, $s2], "$c pipe2_front_set_auto(null) volta a seguir e reavalia (avança)");
t_eq(p2a_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'p2_auto'", [$d]), 2, "$c duas linhas p2_auto");

// ── ::reevaluate_hooks (E4, T6) ─────────────────────────────
$c = '::reevaluate_hooks';
// reabrir nunca recua
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w, ['front_at' => ['vfx' => $s2]]);
$x = p2a_gate($tp, $d, $l, $s2, 'P2T · feita em S2', ['status' => 'done']);
task_reopen($tp, $x, null);
t_eq([(int)p2a_line($tp, $l)['stage_id'], p2a_line($tp, $l)['status']], [$s2, 'running'], "$c reabrir uma obrigatória de S2: a frente fica em S2");
t_eq(p2a_moves($tp, $d), [], "$c sem movimento");
t_eq(p2a_reeval($tp, $d, $l), 'incomplete', "$c e a sub-etapa fica por completar");
// mudar a obrigatória (pipe2_task_set_gate) da única por fazer → avança
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
p2a_gate($tp, $d, $l, $s1, 'P2T · feita', ['status' => 'done']);
$y = p2a_gate($tp, $d, $l, $s1, 'P2T · deixa de ser');
pipe2_task_set_gate($tp, $y, false, 1);
t_eq((int)p2v_col($tp, 'SELECT is_gate FROM tasks WHERE id = ?', [$y]), 0, "$c pipe2_task_set_gate grava is_gate 0");
t_eq(p2a_n($tp, "SELECT COUNT(*) FROM task_log WHERE task_id = ? AND note = 'Obrigatória: não'", [$y]), 1, "$c e fica no histórico da tarefa");
t_eq((int)p2a_line($tp, $l)['stage_id'], $s2, "$c a única por fazer deixou de ser obrigatória: a frente avança");
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
$z = p2v_task($tp, $d, ['title' => 'P2T · passa a ser', 'line_id' => $l, 'stage_id' => $s1, 'is_gate' => 0, 'status' => 'done']);
pipe2_task_set_gate($tp, $z, true, 1);
t_eq([(int)p2v_col($tp, 'SELECT is_gate FROM tasks WHERE id = ?', [$z]), (int)p2a_line($tp, $l)['stage_id']], [1, $s2], "$c uma feita passa a obrigatória: a sub-etapa fica completa e avança");
// apagar pela Agenda (bancada, HTTP): regista em task_deletions e reavalia
$bf = p2v_bench_funnel($bench, 'motor', 4);
$bs = p2v_stages($bench, $bf);
$bd = pipe2_deal($bench, ['phase' => 'production', 'funnel_id' => $bf, 'stage_id' => $bs['exec'], 'owner_id' => 99]);
$bl = pipe2_front($bench, $bd, $bf, ['status' => 'running', 'stage_id' => $bs['fronts'][0], 'is_main' => 1, 'started_at' => date('Y-m-d H:i:s')]);
p2v_steps($bench, $bl);
p2v_task($bench, $bd, ['title' => 'P2T · feita', 'line_id' => $bl, 'stage_id' => $bs['fronts'][0], 'is_gate' => 1, 'status' => 'done']);
$bt = p2v_task($bench, $bd, ['title' => 'P2T · a apagar', 'line_id' => $bl, 'stage_id' => $bs['fronts'][0], 'is_gate' => 1]);
t_true(pipe2_deal_is_v2($bench, deal_get($bench, $bd)), "$c o negócio da bancada é v2");
$post = ['do' => 'delete_task', 'id' => $bt, 'back' => '/admin/calendar.php'];
$r = tests_http('POST', '/admin/calendar.php', $post, 1, true);
if ($r['code'] >= 500 || $r['code'] === 0) $r = tests_http('POST', '/admin/calendar.php', $post, 1, true);
t_eq($r['code'], 302, "$c o POST da Agenda redireciona");
t_eq(p2a_n($bench, 'SELECT COUNT(*) FROM tasks WHERE id = ?', [$bt]), 0, "$c a tarefa foi apagada");
$del = p2v_rows($bench, 'SELECT * FROM task_deletions WHERE task_id = ?', [$bt]);
t_eq([count($del), (int)($del[0]['line_id'] ?? 0), (int)($del[0]['deleted_by'] ?? 0)], [1, $bl, 1], "$c o apagamento fica em task_deletions, com a frente e quem apagou");
t_eq((int)p2v_col($bench, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$bl]), $bs['fronts'][1], "$c a única obrigatória por fazer foi apagada: a frente avança");
pipe2_cleanup($bench);
pipe2_use($tp);

// ── ::system_actor (D9) ─────────────────────────────────────
$c = '::system_actor';
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
$a = p2a_gate($tp, $d, $l, $s1, 'P2T · com sessão');
p2v_as(99, $tp);
task_done($tp, $a);
$h = p2a_moves($tp, $d);
t_eq([count($h), p2a_v($h[0] ?? [], 'user_id')], [1, null], "$c com sessão (99), o avanço automático grava user_id NULL no histórico");
t_eq(p2v_col($tp, "SELECT user_id FROM stage_events WHERE deal_id = ? AND step = 'p2_front'", [$d]), null, "$c e no stage_events");
$tp->prepare("UPDATE tasks SET status = 'done', done_at = NOW() WHERE deal_id = ? AND auto_rule_id = ?")->execute([$d, $rS2]);
$msg = pipe2_front_move($tp, deal_get($tp, $d), pipe2_front_get($tp, $l), $s3, '', 99);
t_eq($msg, 'VFX: passou a «Produção de shots».', "$c o movimento humano devolve a mensagem");
$h = p2a_moves($tp, $d);
t_eq([count($h), (int)($h[1]['user_id'] ?? 0), str_contains((string)($h[1]['note'] ?? ''), 'Avanço automático')], [2, 99, false], "$c e grava a pessoa (99), sem «Avanço automático»");
t_eq((int)p2v_col($tp, "SELECT user_id FROM stage_events WHERE deal_id = ? AND step = 'p2_front' ORDER BY id DESC LIMIT 1", [$d]), 99, "$c também no stage_events");
p2v_as(null);

// ── ::no_session (Review Focus 2) ───────────────────────────
$c = '::no_session';
[$d, $l] = p2a_deal($tp, $w);
$a = p2a_gate($tp, $d, $l, $s1, 'P2T · sem sessão');
$r = p2v_race([['pipe2_race.php', ['--db=' . $db, '--uid=0', '--task=' . $a]]])[0];
t_eq([$r['code'], $r['json']['ok'] ?? null], [0, true], "$c o task_done sem sessão sai bem (" . trim($r['err'] . ' ' . $r['out']) . ')');
t_true(!preg_match('/Warning|Notice|Deprecated/', $r['err']), "$c sem avisos de PHP (" . trim($r['err']) . ')');
t_eq((int)p2a_line($tp, $l)['stage_id'], $s2, "$c a frente avança");
t_eq(array_column(p2a_moves($tp, $d), 'user_id'), [null], "$c como «Sistema»");

// ── ::never_cancels_completion (E4) ─────────────────────────
$c = '::never_cancels_completion';
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
$a = p2a_gate($tp, $d, $l, $s1, 'P2T · o motor falha');
$tp->exec("CREATE TRIGGER p2t_falha BEFORE INSERT ON deal_history FOR EACH ROW BEGIN IF NEW.event = 'p2_front' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'P2T falha de teste'; END IF; END");
$log = tempnam(sys_get_temp_dir(), 'p2a');
$prevLog = (string)ini_get('error_log');
ini_set('error_log', $log);
[$cls] = p2a_err(fn() => task_done($tp, $a));
ini_set('error_log', $prevLog);
$tp->exec('DROP TRIGGER p2t_falha');
$logged = (string)file_get_contents($log);
@unlink($log);
t_eq($cls, '', "$c task_done não lança");
t_eq((string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [$a]), 'done', "$c a tarefa fica feita");
t_eq([(int)p2a_line($tp, $l)['stage_id'], p2a_line($tp, $l)['status']], [$s1, 'running'], "$c a frente não avançou (rollback)");
t_eq(p2a_v(p2a_step($tp, $l, $s1), 'done_at'), null, "$c a linha de passos de S1 ficou como estava");
t_eq(p2a_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$d, $rS2]), 0, "$c e as regras de S2 não correram");
t_true(str_contains($logged, '[topper] pipe2'), "$c o error_log tem «[topper] pipe2»");

// ── ::reentry_no_duplicates (Review Focus 3) ────────────────
$c = '::reentry_no_duplicates';
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
$a = p2a_gate($tp, $d, $l, $s1, 'P2T · reentrada A');
p2a_gate($tp, $d, $l, $s1, 'P2T · reentrada B', ['status' => 'done']);
task_done($tp, $a);
t_eq((int)p2a_line($tp, $l)['stage_id'], $s2, "$c S1 → S2");
t_eq(p2a_n($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$d, $rS2]), 1, "$c a tarefa de S2 nasce");
pipe2_request_reset();
pipe2_front_undo($tp, deal_get($tp, $d), pipe2_front_get($tp, $l), 1);
t_eq((int)p2a_line($tp, $l)['stage_id'], $s1, "$c «Desfazer» → S1");
t_true((p2a_step($tp, $l, $s1)['auto_hold_at'] ?? null) !== null, "$c com S1 suspensa");
task_reopen($tp, $a, null);
t_eq(p2a_v(p2a_step($tp, $l, $s1), 'auto_hold_at'), null, "$c reabrir uma obrigatória de S1 levanta a suspensão");
task_done($tp, $a);
t_eq((int)p2a_line($tp, $l)['stage_id'], $s2, "$c fechá-la leva a frente outra vez a S2");
$t2 = p2v_rows($tp, 'SELECT id FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$d, $rS2]);
t_eq(count($t2), 1, "$c continua só uma tarefa de S2");
task_done($tp, (int)$t2[0]['id']);
t_eq((int)p2a_line($tp, $l)['stage_id'], $s2, "$c e, no mesmo pedido, não passa a S3");

// ── ::retired_stage_next ────────────────────────────────────
$c = '::retired_stage_next';
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
$a = p2a_gate($tp, $d, $l, $s1, 'P2T · antes da retirada');
$tp->prepare('UPDATE funnel_stages SET retired_at = NOW() WHERE id = ?')->execute([$s2]);
task_done($tp, $a);
$tp->prepare('UPDATE funnel_stages SET retired_at = NULL WHERE id = ?')->execute([$s2]);
t_eq((int)p2a_line($tp, $l)['stage_id'], $s3, "$c com S2 retirada, de S1 entra em S3");
t_eq(pipe2_next_stage($tp, ['funnel_id' => $w['vfx'], 'stage_id' => null] + p2a_line($tp, $l))['id'] ?? null, $s1, "$c sem sub-etapa, a seguinte é a 1.ª");

// ── ::human_move_rules (E1, nota 4) ─────────────────────────
$c = '::human_move_rules';
pipe2_request_reset();
[$d, $l] = p2a_deal($tp, $w);
p2a_gate($tp, $d, $l, $s1, 'P2T · por fazer em S1');
$mvh = fn(int $to, string $force = '', ?int $by = 99) => pipe2_front_move($tp, deal_get($tp, $d), pipe2_front_get($tp, $l), $to, $force, $by);
p2v_as(99, $tp);
[$cls, $m] = p2a_err(fn() => $mvh($s3));
t_true($cls === 'GateBlocked' && str_contains($m, 'avançar uma sub-etapa de cada vez'), "$c duas sub-etapas para a frente: GateBlocked «avançar uma sub-etapa de cada vez» ($cls: $m)");
[$cls, $m] = p2a_err(fn() => $mvh($s2));
t_true($cls === 'GateBlocked' && str_contains($m, 'P2T · por fazer em S1'), "$c com o gate incompleto: GateBlocked com o título ($cls: $m)");
[$cls, $m] = p2a_err(fn() => $mvh($s2, 'urgente'));
t_eq($m, 'Só os admins podem forçar a passagem.', "$c forçar sem ser admin: recusado");
t_eq((int)p2a_line($tp, $l)['stage_id'], $s1, "$c e a frente não andou");
p2v_as(1, $tp);
$m = $mvh($s2, 'cliente com pressa', 1);
t_eq($m, 'VFX: passou a «Look dev / shot de referência».', "$c um admin com motivo passa");
$h = p2a_moves($tp, $d);
t_true(str_contains((string)(end($h)['note'] ?? ''), 'Passagem forçada: cliente com pressa · faltava: P2T · por fazer em S1'), "$c e a nota tem «Passagem forçada» com o que faltava");
t_eq((int)(end($h)['user_id'] ?? 0), 1, "$c com a pessoa");
$m = $mvh($s1, '', 1);
t_eq([(int)p2a_line($tp, $l)['stage_id'], $m], [$s1, 'VFX: passou a «Turnover e breakdown».'], "$c para trás passa sempre");
$hold = p2a_step($tp, $l, $s1);
t_eq([($hold['auto_hold_at'] ?? null) !== null, $hold['auto_hold_why'] ?? ''], [true, 'undo'], "$c e suspende o destino");
t_eq($mvh($s1, '', 1), 'Sem alterações.', "$c para a mesma sub-etapa: sem alterações");
[$cls, $m] = p2a_err(fn() => $mvh($S['kickoff'], '', 1));
t_eq($m, 'Etapa inválida.', "$c só sub-etapas vivas do funil da frente");
p2v_as(null);

// ── ::start (D6) ────────────────────────────────────────────
$c = '::start';
pipe2_request_reset();
$dk = p2v_deal($tp, $w, ['vfx'], ['at' => 'kickoff']);
$lk = $dk['lines']['vfx'];
p2v_steps($tp, $lk, ['plan_days' => 2]);
$kg = p2v_task($tp, $dk['id'], ['title' => 'P2T · obrigatória do Kickoff', 'stage_id' => $K, 'is_gate' => 1]);
t_eq(p2a_line($tp, $lk)['status'], 'queued', "$c a frente começa Por começar");
[$cls, $m] = p2a_err(fn() => pipe2_front_start($tp, deal_get($tp, $dk['id']), pipe2_front_get($tp, $lk), 1));
t_true($cls === 'GateBlocked' && str_contains($m, 'P2T · obrigatória do Kickoff'), "$c com o gate do Kickoff por cumprir: GateBlocked ($cls: $m)");
t_eq(p2a_line($tp, $lk)['status'], 'queued', "$c e não começa");
$tp->prepare("UPDATE tasks SET status = 'done', done_at = NOW() WHERE id = ?")->execute([$kg]);
t_eq(pipe2_front_start($tp, deal_get($tp, $dk['id']), pipe2_front_get($tp, $lk), 1), 'entered', "$c com o gate cumprido, começa");
$L = p2a_line($tp, $lk);
t_eq([$L['status'], (int)$L['stage_id'], $L['started_at'] !== null], ['running', $s1, true], "$c entra na 1.ª sub-etapa");
$p1 = p2a_step($tp, $lk, $s1);
t_eq([(string)($p1['plan_start'] ?? ''), (string)($p1['plan_end'] ?? '')], [pipe2_workday_from($today), flow_add_workdays(pipe2_workday_from($today), 2)], "$c com o plano feito a partir de hoje");
t_true(($p1['started_at'] ?? null) !== null, "$c e a linha de passos de S1 começada");
t_eq((string)(p2a_moves($tp, $dk['id'])[0]['note'] ?? ''), 'VFX: Por começar → Turnover e breakdown', "$c o histórico diz de onde e para onde");
[$cls, $m] = p2a_err(fn() => pipe2_front_start($tp, deal_get($tp, $dk['id']), pipe2_front_get($tp, $lk), 1));
t_eq($m, 'A frente VFX já começou.', "$c começar outra vez: recusado");
// avença: o período e o ciclo
pipe2_request_reset();
$dm = p2v_deal($tp, $w, ['vfx'], ['at' => 'kickoff', 'billing' => ['vfx' => [500, 12]]]);
$lm = $dm['lines']['vfx'];
p2v_steps($tp, $lm, ['plan_days' => 1]);
t_eq([p2a_line($tp, $lm)['cycle_ym'], p2a_line($tp, $lm)['billing_end']], ['', null], "$c a avença Por começar sem ciclo nem fim");
t_eq(pipe2_front_start($tp, deal_get($tp, $dm['id']), pipe2_front_get($tp, $lm), 1), 'entered', "$c a avença começa");
$L = p2a_line($tp, $lm);
$period = pipe2_billing_period($L, $today);
t_eq([$L['status'], (string)$L['cycle_ym'], (string)$L['billing_end']], ['ongoing', substr($today, 0, 7), $period['end']], "$c Ativa, com o ciclo do mês e o fim do período");
t_eq([p2a_n($tp, 'SELECT COUNT(*) FROM deal_line_steps WHERE line_id = ? AND cycle_ym = ?', [$lm, substr($today, 0, 7)]),
      p2a_n($tp, "SELECT COUNT(*) FROM deal_line_steps WHERE line_id = ? AND cycle_ym = ''", [$lm])], [6, 0], "$c as linhas de passos passam ao ciclo da avença");
t_true((p2a_step($tp, $lm, $s1)['plan_start'] ?? null) !== null, "$c e o plano fica feito");

// ── ::v1_noop (regra de ouro) ───────────────────────────────
$c = '::v1_noop';
pipe2_request_reset();
$dv = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1f, 'owner_id' => 99, 'sync_mode' => 'sync']);
$lv = pipe2_front($tp, $dv, $v1f, ['is_main' => 1, 'status' => 'active', 'stage_id' => $v1k]);
pipe2_front($tp, $dv, $v13d, ['is_main' => 0, 'status' => 'active']);
$tv = p2v_task($tp, $dv, ['title' => 'P2T · v1 obrigatória', 'line_id' => $lv, 'stage_id' => $v1k, 'is_gate' => 1]);
p2v_task($tp, $dv, ['title' => 'P2T · v1 feita', 'line_id' => $lv, 'stage_id' => $v1k, 'is_gate' => 1, 'status' => 'done']);
t_eq(pipe2_deal_is_v2($tp, deal_get($tp, $dv)), false, "$c o negócio é v1");
$where = 'deal_id = ' . $dv;
$sig =p2v_sig($tp, ['deal_lines', 'deal_line_steps', 'stage_events'], $where) + ['h' => p2v_sig($tp, ['deal_history'], $where . " AND event <> 'task_done'")];
$hd = p2a_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'task_done'", [$dv]);
task_done($tp, $tv);
t_eq(p2v_sig($tp, ['deal_lines', 'deal_line_steps', 'stage_events'], $where) + ['h' => p2v_sig($tp, ['deal_history'], $where . " AND event <> 'task_done'")], $sig,
     "$c task_done de uma tarefa com frente num v1: frentes, passos, eventos e histórico iguais");
t_eq(p2a_n($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'task_done'", [$dv]), $hd + 1, "$c só a linha task_done de sempre");
task_reopen($tp, $tv, null);
t_eq(p2v_sig($tp, ['deal_lines', 'deal_line_steps', 'stage_events'], $where) + ['h' => p2v_sig($tp, ['deal_history'], $where . " AND event <> 'task_done'")], $sig,
     "$c reabrir num v1 também não muda nada");
t_eq(pipe2_moves(), [], "$c e nenhum movimento no registo do pedido");

t_done();
