<?php
/**
 * Pipeline v2, fase 1 — os apagamentos humanos de tarefas ficam em task_deletions antes do DELETE (D19, §34.11 T6):
 * pipe2_task_delete_human (admin/inc/pipe2_tasks.php), que a Agenda passa a usar na tarefa 10.
 *
 * Com pipe2_schema_ready e a tabela, o registo leva a tarefa, o negócio, a frente, a etapa, a regra, quem apagou, o
 * título e a data de criação; também nas tarefas v1 (ninguém o lê na v1: serve o medidor de ruído depois da
 * conversão). O INSERT fica em try/catch com error_log: o DELETE de tasks e task_log corre sempre, como hoje. Numa
 * base na 42 (sem a tabela) só apaga, sem exceção nem aviso de PHP.
 *
 * Corre em bases privadas (p2v_db; e uma na 42, sem migrar). Não escreve na bancada.
 *   php tests/pipe2_noise_test.php
 * Casos: ::deletion_logged ::v1_also_logged ::insert_fails_delete_runs ::v1_at_42
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2_tasks.php';

[$db, $tp] = p2v_db();

/** O valor de uma coluna (NULL incluído); '(falta)' se a chave não existir. */
function p2_val(array $row, string $k) {
    return array_key_exists($k, $row) ? $row[$k] : '(falta)';
}

/** Duas linhas de task_log de uma tarefa (o que o DELETE também apaga). */
function p2no_log(PDO $pdo, int $task): void {
    $ins = $pdo->prepare("INSERT INTO task_log (task_id, event, note, user_id, created_at) VALUES (?, ?, 'P2T', NULL, ?)");
    $ins->execute([$task, 'create', date('Y-m-d H:i:s')]);
    $ins->execute([$task, 'edit', date('Y-m-d H:i:s')]);
}

function p2no_count(PDO $pdo, string $sql, array $a): int {
    return (int)p2v_col($pdo, $sql, $a);
}

/** Corre $fn e devolve [mensagem da exceção ou null, avisos de PHP]. */
function p2no_quiet(callable $fn): array {
    $warn = [];
    set_error_handler(function (int $no, string $msg) use (&$warn) { $warn[] = $msg; return true; });
    try {
        $fn();
        return [null, $warn];
    } catch (Throwable $e) {
        return [get_class($e) . ': ' . $e->getMessage(), $warn];
    } finally {
        restore_error_handler();
    }
}

$w = p2v_world($tp, ['vfx']);
$sv = p2v_stages($tp, $w['vfx']);
$rule = p2v_rule($tp, $sv['fronts'][0], ['title' => 'P2T · regra do ruído']);
$v1f = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$v1k = (int)p2v_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND kind = 'kickoff' ORDER BY position, id LIMIT 1", [$v1f]);

// ── ::deletion_logged ───────────────────────────────────────
$c = '::deletion_logged';
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$created = '2026-10-20 09:15:00';
$t = p2v_task($tp, $d['id'], ['title' => 'P2T · para apagar', 'line_id' => $d['lines']['vfx'], 'stage_id' => $sv['fronts'][0], 'auto_rule_id' => $rule,
                              'is_gate' => 1, 'created_at' => $created]);
p2no_log($tp, $t);
$row = task_by_id($tp, $t);
$before = date('Y-m-d H:i:s', time() - 1);
pipe2_task_delete_human($tp, $row, 1);
$del = p2v_rows($tp, 'SELECT * FROM task_deletions WHERE task_id = ?', [$t]);
t_eq(count($del), 1, "$c uma linha em task_deletions");
t_eq([(int)($del[0]['deal_id'] ?? 0), (int)($del[0]['line_id'] ?? 0), (int)($del[0]['stage_id'] ?? 0), (int)($del[0]['auto_rule_id'] ?? 0), (int)($del[0]['deleted_by'] ?? 0)],
     [$d['id'], $d['lines']['vfx'], $sv['fronts'][0], $rule, 1], "$c o negócio, a frente, a sub-etapa, a regra e quem apagou");
t_eq([(string)($del[0]['title'] ?? ''), (string)($del[0]['task_created_at'] ?? '')], ['P2T · para apagar', $created], "$c o título e a data de criação da tarefa");
t_true((string)($del[0]['deleted_at'] ?? '') >= $before, "$c a hora do apagamento");
t_eq(p2no_count($tp, 'SELECT COUNT(*) FROM tasks WHERE id = ?', [$t]), 0, "$c a tarefa foi apagada");
t_eq(p2no_count($tp, 'SELECT COUNT(*) FROM task_log WHERE task_id = ?', [$t]), 0, "$c e o histórico dela");

// ── ::v1_also_logged ────────────────────────────────────────
$c = '::v1_also_logged';
$dv = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $v1f]);
$tv = p2v_task($tp, $dv, ['title' => 'P2T · v1 para apagar', 'stage_id' => $v1k, 'created_at' => $created]);
p2no_log($tp, $tv);
pipe2_task_delete_human($tp, task_by_id($tp, $tv), 99);
$delv = p2v_rows($tp, 'SELECT * FROM task_deletions WHERE task_id = ?', [$tv]);
t_eq(count($delv), 1, "$c a tarefa v1 também fica registada");
t_eq([(int)($delv[0]['deal_id'] ?? 0), p2_val($delv[0] ?? [], 'line_id'), (int)($delv[0]['stage_id'] ?? 0), p2_val($delv[0] ?? [], 'auto_rule_id'), (int)($delv[0]['deleted_by'] ?? 0)],
     [$dv, null, $v1k, null, 99], "$c com os campos dela");
t_eq(p2no_count($tp, 'SELECT COUNT(*) FROM tasks WHERE id = ?', [$tv]) + p2no_count($tp, 'SELECT COUNT(*) FROM task_log WHERE task_id = ?', [$tv]), 0, "$c e é apagada");
$tn = p2v_task($tp, 0, ['title' => 'P2T · sem negócio']);
pipe2_task_delete_human($tp, task_by_id($tp, $tn), null);
t_eq(p2v_rows($tp, 'SELECT deal_id, deleted_by FROM task_deletions WHERE task_id = ?', [$tn]), [['deal_id' => null, 'deleted_by' => null]], "$c sem negócio nem pessoa: NULL");

// ── ::insert_fails_delete_runs ──────────────────────────────
$c = '::insert_fails_delete_runs';
$tf = p2v_task($tp, $d['id'], ['title' => 'P2T · o registo falha', 'line_id' => $d['lines']['vfx'], 'stage_id' => $sv['fronts'][0]]);
p2no_log($tp, $tf);
$tp->exec("CREATE TRIGGER p2t_td_fail BEFORE INSERT ON task_deletions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'P2T falha de propósito'");
$logf = tempnam(sys_get_temp_dir(), 'p2nolog');
$oldLog = ini_set('error_log', $logf);
[$err, $warn] = p2no_quiet(fn() => pipe2_task_delete_human($tp, task_by_id($tp, $tf), 1));
ini_set('error_log', (string)$oldLog);
$tp->exec('DROP TRIGGER p2t_td_fail');
$logged = (string)file_get_contents($logf);
@unlink($logf);
t_eq($err, null, "$c sem exceção");
t_eq($warn, [], "$c sem avisos de PHP");
t_eq(p2no_count($tp, 'SELECT COUNT(*) FROM tasks WHERE id = ?', [$tf]), 0, "$c a tarefa é apagada na mesma");
t_eq(p2no_count($tp, 'SELECT COUNT(*) FROM task_log WHERE task_id = ?', [$tf]), 0, "$c e o histórico dela");
t_eq(p2no_count($tp, 'SELECT COUNT(*) FROM task_deletions WHERE task_id = ?', [$tf]), 0, "$c sem registo");
t_true(str_contains($logged, '[topper] pipe2'), "$c o error_log tem «[topper] pipe2»");

// ── ::v1_at_42 ──────────────────────────────────────────────
$c = '::v1_at_42';
$db42 = pipe2_tmpdb_create(pipe2_base_dump());
$p42 = pipe2_tmpdb_pdo($db42);
t_eq(db_has_table($p42, 'task_deletions'), false, "$c a base na 42 não tem task_deletions");
$t42 = pipe2_task($p42, 0, ['title' => 'P2T · na 42']);
p2no_log($p42, $t42);
$row42 = p2v_rows($p42, 'SELECT * FROM tasks WHERE id = ?', [$t42])[0];
[$err, $warn] = p2no_quiet(fn() => pipe2_task_delete_human($p42, $row42, 1));
t_eq($err, null, "$c sem exceção");
t_eq($warn, [], "$c sem avisos de PHP");
t_eq(p2no_count($p42, 'SELECT COUNT(*) FROM tasks WHERE id = ?', [$t42]), 0, "$c a tarefa é apagada");
t_eq(p2no_count($p42, 'SELECT COUNT(*) FROM task_log WHERE task_id = ?', [$t42]), 0, "$c e o histórico dela");

t_done();
