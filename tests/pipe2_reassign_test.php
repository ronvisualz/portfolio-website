<?php
/**
 * Pipeline v2, fase 1 — reatribuição (D20, §34.11 T5): só nos negócios v2.
 *
 *   · deal_flow.php 'people': mudar o responsável do negócio passa as tarefas automáticas abertas desse papel, e num
 *     negócio v2 só as do negócio (line_id NULL; pipe2_reassign_scope_sql) — as das frentes seguem o responsável da
 *     frente. Num v1, como hoje (todas).
 *   · team_reassign (equipa): passa também deals.exec_owner_id dos negócios v2 abertos, deal_lines.exec_owner_id das
 *     frentes v2 vivas desses negócios e deal_line_execs.user_id delas (pipe2_team_reassign, em try/catch). Negócios
 *     e frentes v1 ficam como estavam; numa base na 42 faz exatamente o de hoje, sem exceção nem aviso de PHP.
 *
 * ::by_line e ::v1_unchanged correm na bancada (HTTP em 127.0.0.1:8765, uid 1, com pipe2_lock) e só escrevem em
 * funis e negócios «P2T» deste teste; os outros casos correm em bases privadas.
 *   php tests/pipe2_reassign_test.php
 * Casos: ::by_line ::v1_unchanged ::team_reassign_exec ::v1_at_42
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/team.php';
require_once __DIR__ . '/../admin/inc/pipe2_tasks.php';

$bench = $pdo;
pipe2_lock($bench);
pipe2_cleanup($bench);   // sobras «P2T» de uma corrida interrompida

function p2ra_member(PDO $pdo, int $task) {
    return p2v_col($pdo, 'SELECT member_id FROM tasks WHERE id = ?', [$task]);
}

/** POST do=people para um negócio «P2T» deste teste (recusa outro); uma falha transitória repete-se uma vez. */
function p2ra_people(PDO $pdo, int $deal, array $data): array {
    $code = (string)p2v_col($pdo, 'SELECT code FROM deals WHERE id = ?', [$deal]);
    if (!str_starts_with($code, PIPE2_T)) { t_true(false, "p2ra_people recusou o negócio $deal (não é de teste)"); t_done(); }
    $post = ['do' => 'people', 'id' => $deal, 'back' => '/admin/pipeline.php'] + $data;
    $r = tests_http('POST', '/admin/deal_flow.php', $post, 1, true);
    if ($r['code'] >= 500 || $r['code'] === 0) $r = tests_http('POST', '/admin/deal_flow.php', $post, 1, true);
    return $r;
}

$m99 = member_for_user($bench, 99);
$m4 = member_for_user($bench, 4);

// ── ::by_line (bancada) ─────────────────────────────────────
$c = '::by_line';
$bf = p2v_bench_funnel($bench, 'reatribuir');
$bs = p2v_stages($bench, $bf);
$rDeal = p2v_rule($bench, $bs['exec'], ['title' => 'P2T · reat negócio', 'trig' => 'enter', 'assign_to' => 'commercial', 'is_gate' => 0]);
$rLine = p2v_rule($bench, $bs['fronts'][0], ['title' => 'P2T · reat frente', 'assign_to' => 'commercial', 'is_gate' => 0]);
$bd = pipe2_deal($bench, ['phase' => 'production', 'funnel_id' => $bf, 'stage_id' => $bs['exec'], 'owner_id' => 99]);
$bl = pipe2_front($bench, $bd, $bf, ['status' => 'running', 'stage_id' => $bs['fronts'][0], 'is_main' => 1]);
$tDeal = p2v_task($bench, $bd, ['title' => 'P2T · reat negócio', 'auto_rule_id' => $rDeal, 'stage_id' => $bs['exec'], 'member_id' => $m99]);
$tLine = p2v_task($bench, $bd, ['title' => 'P2T · reat frente', 'auto_rule_id' => $rLine, 'line_id' => $bl, 'stage_id' => $bs['fronts'][0], 'member_id' => $m99]);
t_true(pipe2_deal_is_v2($bench, deal_get($bench, $bd)), "$c o negócio de teste é v2");
$r = p2ra_people($bench, $bd, ['owner_id' => 4]);
t_eq($r['code'], 302, "$c o POST redireciona");
t_eq((int)p2v_col($bench, 'SELECT owner_id FROM deals WHERE id = ?', [$bd]), 4, "$c o comercial mudou");
t_eq((int)p2ra_member($bench, $tDeal), (int)$m4, "$c a tarefa do negócio passa para o novo comercial");
t_eq((int)p2ra_member($bench, $tLine), (int)$m99, "$c a tarefa da frente fica com quem estava");

// ── ::v1_unchanged (bancada) ────────────────────────────────
$c = '::v1_unchanged';
$vf = pipe2_funnel($bench, 'reatribuir v1');
$vk = p2v_stages($bench, $vf)['kickoff'];
$rv1 = p2v_rule($bench, $vk, ['title' => 'P2T · reat v1 negócio', 'trig' => 'enter', 'assign_to' => 'commercial', 'is_gate' => 0]);
$rv2 = p2v_rule($bench, $vk, ['title' => 'P2T · reat v1 frente', 'trig' => 'enter', 'assign_to' => 'commercial', 'is_gate' => 0]);
$vd = pipe2_deal($bench, ['phase' => 'production', 'funnel_id' => $vf, 'owner_id' => 99]);
$vl = pipe2_front($bench, $vd, $vf, ['status' => 'active', 'stage_id' => $vk]);
$tv1 = p2v_task($bench, $vd, ['title' => 'P2T · reat v1 negócio', 'auto_rule_id' => $rv1, 'stage_id' => $vk, 'member_id' => $m99]);
$tv2 = p2v_task($bench, $vd, ['title' => 'P2T · reat v1 frente', 'auto_rule_id' => $rv2, 'line_id' => $vl, 'stage_id' => $vk, 'member_id' => $m99]);
t_eq(pipe2_deal_is_v2($bench, deal_get($bench, $vd)), false, "$c o negócio de teste é v1");
$r = p2ra_people($bench, $vd, ['owner_id' => 4]);
t_eq($r['code'], 302, "$c o POST redireciona");
t_eq([(int)p2ra_member($bench, $tv1), (int)p2ra_member($bench, $tv2)], [(int)$m4, (int)$m4], "$c num v1 passam as duas, como hoje");
pipe2_cleanup($bench);

// ── ::team_reassign_exec (base privada) ─────────────────────
$c = '::team_reassign_exec';
[$db, $tp] = p2v_db();
$w = p2v_world($tp, ['vfx', '3d', 'md']);
$v1f = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$now = date('Y-m-d H:i:s');
$d = p2v_deal($tp, $w, ['vfx', '3d', 'md'], ['at' => 'exec', 'exec' => 6]);
foreach ($d['lines'] as $lid) p2v_front($tp, $lid, ['exec_owner_id' => 6]);
p2v_front($tp, $d['lines']['md'], ['status' => 'merged']);
$exV2 = pipe2_insert($tp, 'deal_line_execs', ['deal_id' => $d['id'], 'line_id' => $d['lines']['vfx'], 'user_id' => 6, 'created_at' => $now]);
$exMerged = pipe2_insert($tp, 'deal_line_execs', ['deal_id' => $d['id'], 'line_id' => $d['lines']['md'], 'user_id' => 6, 'created_at' => $now]);
$dDone = p2v_deal($tp, $w, ['vfx'], ['at' => 'done', 'exec' => 6]);
p2v_front($tp, $dDone['lines']['vfx'], ['exec_owner_id' => 6]);
$dv = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1f, 'exec_owner_id' => 6]);
$lv = pipe2_front($tp, $dv, $v1f, ['status' => 'active', 'exec_owner_id' => 6]);
$exV1 = pipe2_insert($tp, 'deal_line_execs', ['deal_id' => $dv, 'line_id' => $lv, 'user_id' => 6, 'created_at' => $now]);
$u = fn(int $id) => p2v_rows($tp, 'SELECT * FROM users WHERE id = ?', [$id])[0];
$lineExec = fn(int $lid) => (int)p2v_col($tp, 'SELECT exec_owner_id FROM deal_lines WHERE id = ?', [$lid]);
$dealExec = fn(int $did) => (int)p2v_col($tp, 'SELECT exec_owner_id FROM deals WHERE id = ?', [$did]);
$execUser = fn(int $eid) => (int)p2v_col($tp, 'SELECT user_id FROM deal_line_execs WHERE id = ?', [$eid]);
team_reassign($tp, $u(6), $u(8));
t_eq($dealExec($d['id']), 8, "$c a execução do negócio v2 aberto passa");
t_eq([$lineExec($d['lines']['vfx']), $lineExec($d['lines']['3d'])], [8, 8], "$c os responsáveis das frentes v2 vivas passam");
t_eq($execUser($exV2), 8, "$c os executores internos das frentes v2 vivas passam");
t_eq([$lineExec($d['lines']['md']), $execUser($exMerged)], [6, 6], "$c uma frente juntada (merged) fica");
t_eq([$dealExec($dDone['id']), $lineExec($dDone['lines']['vfx'])], [6, 6], "$c um negócio v2 concluído fica (não é trabalho aberto)");
t_eq([$dealExec($dv), $lineExec($lv), $execUser($exV1)], [6, 6, 6], "$c o negócio v1 e a frente v1 ficam como estavam");
t_eq(pipe2_team_reassign($tp, 8, 1), ['deals' => 1, 'lines' => 2, 'execs' => 1], "$c pipe2_team_reassign devolve as contagens");
t_eq(pipe2_team_reassign($tp, 8, 1), ['deals' => 0, 'lines' => 0, 'execs' => 0], "$c sem nada para passar: zeros");

// ── ::v1_at_42 (base na 42, sem migrar) ─────────────────────
$c = '::v1_at_42';
$db42 = pipe2_tmpdb_create(pipe2_base_dump());
$p42 = pipe2_tmpdb_pdo($db42);
$from = (int)p2v_col($p42, "SELECT d.owner_id FROM deals d JOIN users u ON u.id = d.owner_id
                             WHERE d.phase IN ('lead','pipeline','production') AND u.team_member_id IS NOT NULL
                               AND EXISTS (SELECT 1 FROM tasks t WHERE t.member_id = u.team_member_id AND t.status <> 'done')
                             GROUP BY d.owner_id ORDER BY COUNT(*) DESC, d.owner_id LIMIT 1");
$to = (int)p2v_col($p42, "SELECT id FROM users WHERE id <> ? AND is_active = 1 AND role <> 'superadmin' AND team_member_id IS NOT NULL ORDER BY id LIMIT 1", [$from]);
t_true($from > 0 && $to > 0, "$c há um utilizador com negócios e tarefas abertas ($from → $to)");
$ufrom = p2v_rows($p42, 'SELECT * FROM users WHERE id = ?', [$from])[0];
$uto = p2v_rows($p42, 'SELECT * FROM users WHERE id = ?', [$to])[0];
$ids = fn(string $sql, array $a) => array_map('intval', array_column(p2v_rows($p42, $sql, $a), 'id'));
$dealIds = $ids("SELECT id FROM deals WHERE owner_id = ? AND phase IN ('lead','pipeline','production') ORDER BY id", [$from]);
$taskIds = $ids("SELECT id FROM tasks WHERE member_id = ? AND status <> 'done' ORDER BY id", [(int)$ufrom['team_member_id']]);
$userIds = $ids('SELECT id FROM users WHERE reports_to = ? ORDER BY id', [$from]);
t_true($dealIds && $taskIds, "$c com " . count($dealIds) . ' negócios e ' . count($taskIds) . ' tarefas abertas');
// as colunas que a reatribuição da v1 muda (e o updated_at, que o MySQL atualiza sozinho)
$touched = ['deals' => ['owner_id', 'updated_at'], 'tasks' => ['member_id', 'updated_at'], 'users' => ['reports_to', 'updated_at']];
$others = array_values(array_diff(array_column(p2v_rows($p42, "SELECT TABLE_NAME AS t FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"), 't'),
                                  ['deals', 'tasks', 'users', 'relations', 'mkt_pieces', 'departments']));
$colsBut = fn(string $t, array $skip) => array_values(array_diff(array_column(p2v_rows($p42, 'SELECT COLUMN_NAME AS c FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$t]), 'c'), $skip));
$sigSpec = [];
foreach ($touched as $t => $cols) $sigSpec[$t] = $colsBut($t, $cols);
$sigOthers = p2v_sig($p42, $others);
$sigRest = p2v_sig($p42, $sigSpec);
$warn = [];
set_error_handler(function (int $no, string $msg) use (&$warn) { $warn[] = $msg; return true; });
try {
    team_reassign($p42, $ufrom, $uto);
    $zeros = pipe2_team_reassign($p42, $from, $to);
    $err = null;
} catch (Throwable $e) {
    $err = get_class($e) . ': ' . $e->getMessage();
} finally {
    restore_error_handler();
}
t_eq($err, null, "$c sem exceção");
t_eq($warn, [], "$c sem avisos de PHP");
t_eq($zeros ?? null, ['deals' => 0, 'lines' => 0, 'execs' => 0], "$c pipe2_team_reassign devolve zeros");
$in = fn(array $a) => implode(',', $a ?: [0]);
t_eq((int)p2v_col($p42, 'SELECT COUNT(*) FROM deals WHERE id IN (' . $in($dealIds) . ') AND owner_id = ?', [$to]), count($dealIds), "$c deals.owner_id mudados");
t_eq((int)p2v_col($p42, 'SELECT COUNT(*) FROM tasks WHERE id IN (' . $in($taskIds) . ') AND member_id = ?', [(int)$uto['team_member_id']]), count($taskIds), "$c tasks.member_id mudados");
t_eq((int)p2v_col($p42, 'SELECT COUNT(*) FROM users WHERE id IN (' . $in($userIds) . ') AND reports_to = ?', [$to]), count($userIds), "$c users.reports_to mudados");
t_eq((int)p2v_col($p42, 'SELECT COUNT(*) FROM users WHERE reports_to = ?', [$from]), 0, "$c ninguém responde a quem saiu");
t_eq(p2v_sig($p42, $sigSpec), $sigRest, "$c o resto de deals, tasks e users igual");
t_eq(p2v_sig($p42, $others), $sigOthers, "$c as outras tabelas iguais");

t_done();
