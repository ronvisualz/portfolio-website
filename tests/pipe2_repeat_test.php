<?php
/**
 * Pipeline v2, fase 1 — repetições das tarefas de frente (D18, §34.11 T4): o gancho em task_repeat_next
 * (admin/inc/tasks.php) com pipe2_repeat_extra (admin/inc/pipe2_tasks.php).
 *
 * Numa tarefa de frente v2, a instância seguinte copia line_id, stage_id, auto_rule_id, doc_kind e cycle_ym e nasce
 * com is_gate 0 (a série conta uma vez para o gate da sub-etapa). Se a frente já saiu da sub-etapa (ou mudou de
 * ciclo), não nasce a seguinte. Numa tarefa v1 a seguinte nasce como antes (sem frente, etapa, regra nem obrigatória).
 *
 * As séries fecham quando a frente conclui a sub-etapa (tarefa 10, §34.11 T4): no avanço automático, num movimento
 * humano para a frente (também forçado), ao ficar À espera e com um ciclo novo; o motor fecha as instâncias abertas
 * como «Sistema» (task_log sem utilizador, sem deal_history 'task_done' nem instância nova). Para trás não fecham.
 *
 * Corre numa base privada (p2v_db). Não escreve na bancada.
 *   php tests/pipe2_repeat_test.php
 * Casos: ::copies_and_closes ::v1_noop ::mandatory_series_closes_on_advance ::series_counts_once ::closes_on_leave
 *        ::back_move_keeps_open
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/tasks.php';
require_once __DIR__ . '/../admin/inc/pipe2_engine.php';
require_once __DIR__ . '/../admin/inc/pipe2_deps.php';

[$db, $tp] = p2v_db();

/** O valor de uma coluna (NULL incluído); '(falta)' se a chave não existir. */
function p2_val(array $row, string $k) {
    return array_key_exists($k, $row) ? $row[$k] : '(falta)';
}

function p2r_task(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM tasks WHERE id = ?', [$id])[0] ?? [];
}

/** Quantas tarefas do negócio têm este título. */
function p2r_count(PDO $pdo, int $deal, string $title): int {
    return (int)p2v_col($pdo, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND title = ?', [$deal, $title]);
}

// ── os funis e as regras antes de tudo ──────────────────────
$w = p2v_world($tp, ['vfx', '3d']);
$sv = p2v_stages($tp, $w['vfx']);
[$s1, $s2] = [$sv['fronts'][0], $sv['fronts'][1]];
$s3 = $sv['fronts'][2];
$rule = p2v_rule($tp, $s1, ['title' => 'P2T · Ponto da semana', 'trig' => 'f_enter', 'repeat_rule' => 'weekly', 'is_gate' => 1]);
$ruleS2 = p2v_rule($tp, $s2, ['title' => 'P2T · Entrada em S2', 'trig' => 'f_enter', 'is_gate' => 0]);   // a frente entra em S2 (não obrigatória)
$s3d = p2v_stages($tp, $w['3d'])['fronts'];
$v1f = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$v1k = (int)p2v_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND kind = 'kickoff' ORDER BY position, id LIMIT 1", [$v1f]);
$v1rule = p2v_rule($tp, $v1k, ['title' => 'P2T · semanal v1', 'trig' => 'enter', 'repeat_rule' => 'weekly', 'is_gate' => 1, 'assign_to' => 'commercial']);

// ── ::copies_and_closes ─────────────────────────────────────
$c = '::copies_and_closes';
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);   // a frente em curso na S1
$lid = $d['lines']['vfx'];
$weekly = p2v_task($tp, $d['id'], ['title' => 'P2T · Ponto da semana', 'line_id' => $lid, 'stage_id' => $s1, 'auto_rule_id' => $rule, 'is_gate' => 1,
                                   'repeat_rule' => 'weekly', 'due_date' => '2026-11-09', 'doc_kind' => 'noreply', 'cycle_ym' => '', 'dedup_key' => sha1('p2t-semana')]);
// outra obrigatória por fazer na mesma sub-etapa: o gate não fica completo e a frente não avança (tarefa 10)
p2v_task($tp, $d['id'], ['title' => 'P2T · Outra obrigatória', 'line_id' => $lid, 'stage_id' => $s1, 'is_gate' => 1]);
$next = task_done($tp, $weekly);
t_true($next !== null, "$c fechar a semanal cria a seguinte");
$n = p2r_task($tp, (int)$next);
t_eq([(int)($n['line_id'] ?? 0), (int)($n['stage_id'] ?? 0), (int)($n['auto_rule_id'] ?? 0), (string)($n['doc_kind'] ?? ''), (string)($n['cycle_ym'] ?? 'x')],
     [$lid, $s1, $rule, 'noreply', ''], "$c a seguinte copia a frente, a sub-etapa, a regra, o documento e o ciclo");
t_eq((int)($n['is_gate'] ?? -1), 0, "$c a seguinte não é obrigatória (a série conta uma vez)");
t_eq((string)($n['due_date'] ?? ''), '2026-11-16', "$c uma semana depois");
t_eq((string)($n['dedup_key'] ?? 'x'), '', "$c a seguinte não copia a chave");
t_eq((string)($n['repeat_rule'] ?? ''), 'weekly', "$c e continua a repetir-se");
// ciclo da avença: a frente noutro ciclo
$tp->prepare("UPDATE tasks SET status = 'todo', done_at = NULL WHERE id = ?")->execute([(int)$next]);
p2v_front($tp, $lid, ['cycle_ym' => '2026-11']);
$before = p2r_count($tp, $d['id'], 'P2T · Ponto da semana');
t_eq(task_done($tp, (int)$next), null, "$c a frente mudou de ciclo: não nasce a seguinte");
t_eq(p2r_count($tp, $d['id'], 'P2T · Ponto da semana'), $before, "$c e nenhuma tarefa nova");
p2v_front($tp, $lid, ['cycle_ym' => '']);
// a frente já noutra sub-etapa
$w2 = p2v_task($tp, $d['id'], ['title' => 'P2T · Ponto da semana', 'line_id' => $lid, 'stage_id' => $s1, 'auto_rule_id' => $rule, 'repeat_rule' => 'weekly', 'due_date' => '2026-11-16']);
p2v_front($tp, $lid, ['stage_id' => $s2]);
$before = p2r_count($tp, $d['id'], 'P2T · Ponto da semana');
t_eq(task_done($tp, $w2), null, "$c a frente já noutra sub-etapa: task_done devolve null");
t_eq(p2r_count($tp, $d['id'], 'P2T · Ponto da semana'), $before, "$c e nenhuma tarefa nova");
t_eq((string)p2r_task($tp, $w2)['status'], 'done', "$c a tarefa fica feita");
// à espera (concluiu a sub-etapa) também fecha a série
$w3 = p2v_task($tp, $d['id'], ['title' => 'P2T · Ponto da semana', 'line_id' => $lid, 'stage_id' => $s2, 'auto_rule_id' => $rule, 'repeat_rule' => 'weekly', 'due_date' => '2026-11-16']);
p2v_front($tp, $lid, ['status' => 'depwait']);
t_eq(task_done($tp, $w3), null, "$c a frente À espera (concluiu a sub-etapa): não nasce a seguinte");

// ── ::v1_noop ───────────────────────────────────────────────
$c = '::v1_noop';
$dv = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1f]);
$lv = pipe2_front($tp, $dv, $v1f, ['status' => 'active', 'stage_id' => $v1k]);
$tv = p2v_task($tp, $dv, ['title' => 'P2T · semanal v1', 'line_id' => $lv, 'stage_id' => $v1k, 'auto_rule_id' => $v1rule, 'is_gate' => 1, 'repeat_rule' => 'weekly',
                          'due_date' => '2026-11-09', 'doc_kind' => '']);
$nv = task_done($tp, $tv);
t_true($nv !== null, "$c a seguinte nasce");
$x = p2r_task($tp, (int)$nv);
t_eq([p2_val($x, 'line_id'), p2_val($x, 'stage_id'), p2_val($x, 'auto_rule_id'), (int)($x['is_gate'] ?? -1), (string)($x['cycle_ym'] ?? 'x'), (string)($x['doc_kind'] ?? 'x')],
     [null, null, null, 0, '', ''], "$c como antes: sem frente, etapa, regra nem obrigatória");
t_eq([(string)$x['title'], (string)$x['due_date'], (int)$x['deal_id'], (string)$x['repeat_rule'], (string)$x['status']],
     ['P2T · semanal v1', '2026-11-16', $dv, 'weekly', 'todo'], "$c o título, o prazo, o negócio e a repetição de sempre");
// a frente v1 noutra etapa não muda nada: a seguinte nasce na mesma
$tp->prepare('UPDATE deal_lines SET stage_id = NULL WHERE id = ?')->execute([$lv]);
t_true(task_done($tp, (int)$nv) !== null, "$c numa frente v1 a série continua mesmo com a frente noutra etapa");

/** As tarefas de uma série (o título) de um negócio: [id => [status, is_gate, done_at]]. */
function p2r_series(PDO $pdo, int $deal, string $title): array {
    $out = [];
    foreach (p2v_rows($pdo, 'SELECT id, status, is_gate, done_at FROM tasks WHERE deal_id = ? AND title = ? ORDER BY id', [$deal, $title]) as $r) {
        $out[(int)$r['id']] = [$r['status'], (int)$r['is_gate'], $r['done_at']];
    }
    return $out;
}

/** As linhas do task_log de uma tarefa: [[event, user_id, note], …]. */
function p2r_log(PDO $pdo, int $task): array {
    return array_map(fn($r) => [$r['event'], $r['user_id'] === null ? null : (int)$r['user_id'], $r['note']],
        p2v_rows($pdo, 'SELECT event, user_id, note FROM task_log WHERE task_id = ? ORDER BY id', [$task]));
}

/** Os ids fechados pelo sistema no registo do pedido. */
function p2r_closed(): array {
    $ids = [];
    foreach (pipe2_moves() as $m) foreach ($m['closed'] ?? [] as $c) $ids[] = (int)$c[0];
    return $ids;
}

const P2R_CLOSED_NOTE = 'Fechada pelo sistema: a frente saiu da sub-etapa';

/** Uma instância aberta de uma série semanal da frente (não obrigatória). */
function p2r_inst(PDO $pdo, int $deal, int $line, int $stage, string $title, array $o = []): int {
    return p2v_task($pdo, $deal, ['title' => $title, 'line_id' => $line, 'stage_id' => $stage, 'repeat_rule' => 'weekly', 'due_date' => '2026-11-16', 'is_gate' => 0] + $o);
}

// ── ::mandatory_series_closes_on_advance (D18) ──────────────
$c = '::mandatory_series_closes_on_advance';
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$lid = $d['lines']['vfx'];
p2v_steps($tp, $lid);
$ws = p2v_task($tp, $d['id'], ['title' => 'P2T · Semanal única', 'line_id' => $lid, 'stage_id' => $s1, 'auto_rule_id' => $rule, 'is_gate' => 1,
                               'repeat_rule' => 'weekly', 'due_date' => '2026-11-09']);
t_eq(task_done($tp, $ws), null, "$c fechar a única obrigatória (semanal): task_done devolve null");
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$lid]), $s2, "$c a frente passa a S2 (avanço automático)");
t_eq(count(p2r_series($tp, $d['id'], 'P2T · Semanal única')), 1, "$c e não nasce nenhuma instância nova em S1");
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ?', [$d['id'], $ruleS2]), 1, "$c a regra de S2 correu");

// ── ::series_counts_once (D18, §34.11 T4) ───────────────────
$c = '::series_counts_once';
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$lid = $d['lines']['vfx'];
p2v_steps($tp, $lid);
$ws = p2v_task($tp, $d['id'], ['title' => 'P2T · Semanal conta uma vez', 'line_id' => $lid, 'stage_id' => $s1, 'auto_rule_id' => $rule, 'is_gate' => 1,
                               'repeat_rule' => 'weekly', 'due_date' => '2026-11-09']);
$other = p2v_task($tp, $d['id'], ['title' => 'P2T · A outra obrigatória', 'line_id' => $lid, 'stage_id' => $s1, 'is_gate' => 1]);
$next = task_done($tp, $ws);
t_true($next !== null, "$c fechar a semanal: nasce a seguinte");
t_eq((int)p2v_col($tp, 'SELECT is_gate FROM tasks WHERE id = ?', [(int)$next]), 0, "$c com is_gate 0");
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$lid]), $s1, "$c a frente fica em S1 (falta a outra)");
$histBefore = (int)p2v_col($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'task_done' AND note LIKE '%Semanal conta uma vez%'", [$d['id']]);
pipe2_request_reset();
t_eq(task_done($tp, $other), null, "$c fechar a outra");
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$lid]), $s2, "$c a frente avança: a instância aberta da série não bloqueia o gate");
$n = p2v_rows($tp, 'SELECT status, done_at FROM tasks WHERE id = ?', [(int)$next])[0];
t_eq([$n['status'], $n['done_at'] !== null], ['done', true], "$c a instância aberta fecha pelo sistema no mesmo movimento");
t_eq(p2r_log($tp, (int)$next), [['done', null, P2R_CLOSED_NOTE]], "$c com uma linha task_log 'done' sem utilizador e a nota do sistema");
t_eq((int)p2v_col($tp, "SELECT COUNT(*) FROM deal_history WHERE deal_id = ? AND event = 'task_done' AND note LIKE '%Semanal conta uma vez%'", [$d['id']]), $histBefore,
     "$c sem deal_history 'task_done' dela");
t_eq(count(array_filter(p2r_series($tp, $d['id'], 'P2T · Semanal conta uma vez'), fn($r) => $r[0] !== 'done')), 0, "$c e nenhuma instância nova aberta");
t_true(in_array((int)$next, p2r_closed(), true), "$c o id fica em pipe2_moves()[…]['closed']");

// ── ::closes_on_leave (D18) ─────────────────────────────────
$c = '::closes_on_leave';
// (a) movimento humano para a frente (sessão 99), com uma série de outra frente e uma tarefa sem repetição que ficam
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
[$lv, $l3] = [$d['lines']['vfx'], $d['lines']['3d']];
p2v_steps($tp, $lv);
p2v_steps($tp, $l3);
p2v_task($tp, $d['id'], ['title' => 'P2T · feita', 'line_id' => $lv, 'stage_id' => $s1, 'is_gate' => 1, 'status' => 'done']);
$ia = p2r_inst($tp, $d['id'], $lv, $s1, 'P2T · série a');
$io = p2r_inst($tp, $d['id'], $l3, $s1, 'P2T · série de outra frente');
$plain = p2v_task($tp, $d['id'], ['title' => 'P2T · sem repetição', 'line_id' => $lv, 'stage_id' => $s1]);
p2v_front($tp, $lv, ['auto_advance' => 0]);   // o avanço automático não se mete
p2v_as(99, $tp);
pipe2_front_move($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $lv), $s2, '', 99);
p2v_as(null);
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$lv]), $s2, "$c (humano) a frente passa a S2");
t_eq((string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [$ia]), 'done', "$c (humano) a série aberta de S1 fecha");
t_eq(p2r_log($tp, $ia), [['done', null, P2R_CLOSED_NOTE]], "$c (humano) pelo sistema (sem utilizador), mesmo com sessão");
t_eq([(string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [$io]), (string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [$plain])], ['todo', 'todo'],
     "$c (humano) a série de outra frente e a tarefa sem repetição ficam abertas");
// (b) forçado por um admin
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$lv = $d['lines']['vfx'];
p2v_steps($tp, $lv);
p2v_task($tp, $d['id'], ['title' => 'P2T · por fazer', 'line_id' => $lv, 'stage_id' => $s1, 'is_gate' => 1]);
$ib = p2r_inst($tp, $d['id'], $lv, $s1, 'P2T · série b');
p2v_as(1, $tp);
pipe2_front_move($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $lv), $s2, 'teste', 1);
p2v_as(null);
t_eq([(int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$lv]), (string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [$ib])], [$s2, 'done'],
     "$c (forçado) passa e a série fecha");
t_true(in_array($ib, p2r_closed(), true), "$c (forçado) no registo do pedido");
// (c) À espera no fim de S1 (uma ligação por cumprir para S2)
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
[$lv, $l3] = [$d['lines']['vfx'], $d['lines']['3d']];
p2v_steps($tp, $lv);
p2v_steps($tp, $l3);
pipe2_dep_add($tp, deal_get($tp, $d['id']), $l3, $s3d[1], $lv, $s2, 1);
$g = p2v_task($tp, $d['id'], ['title' => 'P2T · última', 'line_id' => $lv, 'stage_id' => $s1, 'is_gate' => 1]);
$ic = p2r_inst($tp, $d['id'], $lv, $s1, 'P2T · série c');
task_done($tp, $g);
$L = p2v_rows($tp, 'SELECT status, stage_id FROM deal_lines WHERE id = ?', [$lv])[0];
t_eq([$L['status'], (int)$L['stage_id']], ['depwait', $s1], "$c (À espera) a frente fica À espera no fim de S1");
t_eq((string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [$ic]), 'done', "$c (À espera) a série de S1 fecha");
// (d) ciclo novo: fecham as abertas do ciclo antigo em todas as sub-etapas; as do ciclo novo não
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec', 'billing' => ['vfx' => [500, 12]], 'status' => 'ongoing', 'front_at' => ['vfx' => $sv['ready']]]);
$lv = $d['lines']['vfx'];
p2v_steps($tp, $lv);
$old = (string)p2v_col($tp, 'SELECT cycle_ym FROM deal_lines WHERE id = ?', [$lv]);
$new = date('Y-m', strtotime($old . '-01 +1 month'));
$o1 = p2r_inst($tp, $d['id'], $lv, $s1, 'P2T · ciclo antigo S1', ['cycle_ym' => $old]);
$o3 = p2r_inst($tp, $d['id'], $lv, $s3, 'P2T · ciclo antigo S3', ['cycle_ym' => $old]);
$n1 = p2r_inst($tp, $d['id'], $lv, $s1, 'P2T · ciclo novo S1', ['cycle_ym' => $new]);
$r = pipe2_front_enter($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $lv), stage_get($tp, $s1), ['why' => 'cycle', 'cycle' => $new, 'status' => 'ongoing']);
t_eq($r, 'entered', "$c (ciclo) a avença recomeça na 1.ª sub-etapa");
$L = p2v_rows($tp, 'SELECT status, stage_id, cycle_ym FROM deal_lines WHERE id = ?', [$lv])[0];
t_eq([$L['status'], (int)$L['stage_id'], $L['cycle_ym']], ['ongoing', $s1, $new], "$c (ciclo) com o ciclo novo");
t_eq(array_map(fn($id) => (string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [$id]), [$o1, $o3, $n1]), ['done', 'done', 'todo'],
     "$c (ciclo) as abertas do ciclo antigo fecham em todas as sub-etapas; a do ciclo novo não");
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM deal_line_steps WHERE line_id = ? AND stage_id = ? AND cycle_ym = ?', [$lv, $s1, $new]), 1,
     "$c (ciclo) a linha de passos do ciclo novo nasce");

// ── ::back_move_keeps_open (D18: para trás não fecha) ───────
$c = '::back_move_keeps_open';
pipe2_request_reset();
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec', 'front_at' => ['vfx' => $s2]]);
$lv = $d['lines']['vfx'];
p2v_steps($tp, $lv);
p2v_task($tp, $d['id'], ['title' => 'P2T · S1 feita', 'line_id' => $lv, 'stage_id' => $s1, 'is_gate' => 1, 'status' => 'done']);
$m2 = p2v_task($tp, $d['id'], ['title' => 'P2T · obrigatória de S2', 'line_id' => $lv, 'stage_id' => $s2, 'is_gate' => 1]);
$A = p2r_inst($tp, $d['id'], $lv, $s2, 'P2T · série S2', ['due_date' => '2026-11-09']);
$B = p2r_inst($tp, $d['id'], $lv, $s2, 'P2T · série S2', ['due_date' => '2026-11-16']);
pipe2_front_undo($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $lv), 99);
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$lv]), $s1, "$c «Desfazer» → S1");
t_eq([(string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [$A]), (string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [$B])], ['todo', 'todo'],
     "$c A e B continuam abertas");
$before = count(p2r_series($tp, $d['id'], 'P2T · série S2'));
t_eq(task_done($tp, $A), null, "$c fechar A com a frente em S1: nenhuma nova");
t_eq(count(p2r_series($tp, $d['id'], 'P2T · série S2')), $before, "$c (a série não cresce)");
p2v_as(99, $tp);
pipe2_front_move($tp, deal_get($tp, $d['id']), pipe2_front_get($tp, $lv), $s2, '', 99);
p2v_as(null);
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$lv]), $s2, "$c a frente volta a S2 (movimento humano)");
$C = task_done($tp, $B);
t_true($C !== null, "$c fechar B com a frente em S2: nasce C (a série continua)");
pipe2_request_reset();
task_done($tp, $m2);
t_eq((int)p2v_col($tp, 'SELECT stage_id FROM deal_lines WHERE id = ?', [$lv]), $s3, "$c fechar a obrigatória de S2: a frente avança");
t_eq((string)p2v_col($tp, 'SELECT status FROM tasks WHERE id = ?', [(int)$C]), 'done', "$c e C fecha pelo sistema");
t_eq(p2r_log($tp, (int)$C), [['done', null, P2R_CLOSED_NOTE]], "$c (task_log do sistema)");

t_done();
