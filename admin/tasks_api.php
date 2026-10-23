<?php
/**
 * topper. admin — pedidos da aba Tarefas (§18) que correm sem recarregar
 *
 * Só POST, com o token CSRF da página (campo «csrf» ou cabeçalho X-CSRF-Token).
 * Responde sempre em JSON: {"ok":true,…} ou {"ok":false,"error":"…"}.
 *
 *   do=done    id            concluir (com «Anular»)
 *   do=reopen  id            reabrir (fica com a mesma pessoa)
 *   do=take    id            «Pegar»: passa para mim (entreajuda, §18.4)
 *   do=help    id            «Pedir ajuda»: vai para «Por atribuir» com 🙋 e avisa a equipa
 *   do=move    id, to        arrastar no quadro para uma coluna (0 = Por atribuir)
 *   do=snooze  id, when      «Adiar»: 1d · 3d · 1w (a partir do prazo ou de hoje) ou custom (date, time)
 *
 * As privadas dos outros (pessoal, bloqueio) não existem para quem pede. O superadmin
 * não é operador: nunca fica responsável (§18.2).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/tasks.php';
require_once __DIR__ . '/inc/undo.php';

header('Content-Type: application/json; charset=utf-8');

function api_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (current_user() === null) api_out(401, ['ok' => false, 'error' => 'A sessão terminou. Entre outra vez.']);
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_out(405, ['ok' => false, 'error' => 'Pedido inválido.']);
$sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) api_out(400, ['ok' => false, 'error' => 'Pedido inválido. Recarregue a página e tente outra vez.']);
require_perm('tarefas', PERM_EDIT);

$do = (string)($_POST['do'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$task = task_by_id($pdo, $id);
$members = team_members($pdo);
$visible = task_visible_members($pdo, $members);
$myMember = member_for_user($pdo, (int)current_user()['id']);
$gone = fn() => api_out(404, ['ok' => false, 'error' => 'Essa tarefa já não existe. Recarregue a página.']);
if (!$task || task_hidden_for($task, $myMember)) $gone();
$from = $task['member_id'] !== null ? (int)$task['member_id'] : null;
if (!task_member_visible($from, $visible) && $from !== $myMember) $gone();
$snap = ['id' => $id, 'status' => $task['status'], 'done_at' => $task['done_at'], 'member_id' => $task['member_id'], 'position' => $task['position'], 'help_by' => $task['help_by'] ?? null];
$name = fn(?int $m) => $m && isset($members[$m]) ? $members[$m]['name'] : 'Por atribuir';

try {
    switch ($do) {
    case 'done':
        $snap['spawned'] = task_done($pdo, $id);
        task_log($pdo, $id, 'done', 'Concluída');
        api_out(200, ['ok' => true, 'msg' => 'Concluída.', 'undo' => undo_push('task', $snap)]);

    case 'reopen':
        task_move($pdo, $id, $from);
        task_log($pdo, $id, 'reopen', 'Reaberta');
        api_out(200, ['ok' => true, 'msg' => 'Reaberta.', 'undo' => undo_push('task', $snap)]);

    case 'take':
    case 'move':
        $to = $do === 'take' ? $myMember : ((int)($_POST['to'] ?? 0) ?: null);
        if ($to !== null && !isset($members[$to])) api_out(403, ['ok' => false, 'error' => $do === 'take' ? 'Só os operadores pegam em tarefas.' : 'Escolha uma coluna da equipa.']);
        if (!empty($task['private'])) api_out(403, ['ok' => false, 'error' => 'As tarefas pessoais não mudam de pessoa.']);
        if ($to === $from) api_out(200, ['ok' => true, 'msg' => 'Já está aí.']);
        if (!task_can_assign($pdo, $to, $members, $visible)) api_out(403, ['ok' => false, 'error' => 'Não pode atribuir tarefas a essa pessoa.']);
        task_move($pdo, $id, $to);
        $pdo->prepare('UPDATE tasks SET help_by = NULL WHERE id = ?')->execute([$id]);
        $note = $do === 'take' ? 'Pegou na tarefa (era de ' . $name($from) . ')' : 'Responsável: ' . $name($to) . ' (era ' . $name($from) . ')';
        task_log($pdo, $id, $do === 'take' ? 'take' : 'assign', $note);
        if (!empty($task['deal_id'])) deal_log($pdo, (int)$task['deal_id'], 'assign', ['note' => 'Tarefa «' . $task['title'] . '» → ' . $name($to)]);
        api_out(200, ['ok' => true, 'msg' => $do === 'take' ? 'É sua agora.' : 'Passou para ' . $name($to) . '.', 'undo' => undo_push('task', $snap)]);

    case 'snooze':
        if ($task['status'] === 'done') api_out(400, ['ok' => false, 'error' => 'Uma tarefa concluída não se adia.']);
        $when = (string)($_POST['when'] ?? '');
        $time = $task['due_time'] !== null ? substr((string)$task['due_time'], 0, 5) : '';
        if ($when === 'custom') {
            $date = (string)($_POST['date'] ?? '');
            $time = (string)($_POST['time'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) api_out(400, ['ok' => false, 'error' => 'Indique uma data válida.']);
            if ($time !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) api_out(400, ['ok' => false, 'error' => 'Indique uma hora válida.']);
        } else {
            $days = ['1d' => 1, '3d' => 3, '1w' => 7][$when] ?? 0;
            if (!$days) api_out(400, ['ok' => false, 'error' => 'Pedido inválido.']);
            // Conta a partir do prazo, ou de hoje se o prazo já passou (ou não havia).
            $base = max((string)($task['due_date'] ?? ''), today_lisbon());
            $date = (new DateTimeImmutable($base))->modify('+' . $days . ' days')->format('Y-m-d');
        }
        $newTime = $time !== '' ? $time . ':00' : null;
        $pdo->prepare('UPDATE tasks SET due_date = ?, due_time = ?, duration_min = ?, updated_at = ? WHERE id = ?')
            ->execute([$date, $newTime, $newTime !== null ? $task['duration_min'] : null, task_now(), $id]);
        $label = date('d/m/Y', strtotime($date)) . ($time !== '' ? ' ' . $time : '');
        task_log($pdo, $id, 'snooze', 'Adiada para ' . $label);
        api_out(200, ['ok' => true, 'msg' => 'Adiada para ' . $label . '.', 'undo' => undo_push('task_due', ['id' => $id, 'due_date' => $task['due_date'], 'due_time' => $task['due_time'], 'duration_min' => $task['duration_min'], 'to_date' => $date, 'to_time' => $newTime])]);

    case 'help':
        if ($from === null || $from !== $myMember) api_out(403, ['ok' => false, 'error' => 'Só pede ajuda quem tem a tarefa.']);
        if (!empty($task['private'])) api_out(403, ['ok' => false, 'error' => 'As tarefas pessoais não se partilham.']);
        task_move($pdo, $id, null);
        $pdo->prepare('UPDATE tasks SET help_by = ? WHERE id = ?')->execute([(int)current_user()['id'], $id]);
        task_log($pdo, $id, 'help', '🙋 Pediu ajuda: foi para «Por atribuir»');
        api_out(200, ['ok' => true, 'msg' => 'Pedido de ajuda enviado: a tarefa está em «Por atribuir» e a equipa foi avisada.', 'undo' => undo_push('task', $snap)]);
    }
} catch (TaskNeedsOutcome $e) {
    api_out(409, ['ok' => false, 'error' => $e->getMessage(), 'lead' => $e->lead]);
} catch (RuntimeException $e) {
    api_out(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('tasks_api ' . $do . ': ' . $e->getMessage());
    api_out(500, ['ok' => false, 'error' => 'Não foi possível guardar. Tente outra vez.']);
}
api_out(400, ['ok' => false, 'error' => 'Pedido inválido.']);
