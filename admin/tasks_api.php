<?php
/**
 * topper. admin — pedidos do quadro de tarefas (arrastar e largar)
 *
 * Só POST, com o token CSRF da página (campo «csrf» ou cabeçalho
 * X-CSRF-Token). Responde sempre em JSON: {"ok":true,…} ou
 * {"ok":false,"error":"…"} com uma mensagem em português.
 *
 *   do=move   id, to (id da pessoa; 0 = Por atribuir), order (ids da
 *             pilha de destino separados por vírgulas, já com a tarefa
 *             no sítio onde foi largada). to=done → coluna «Feito»:
 *             fica feita, com a mesma pessoa. Uma feita largada numa
 *             pilha volta a «A fazer» com essa pessoa.
 *   do=done   id — o botão «✓ Feito»
 *   do=reopen id, to (opcional; por omissão a pessoa que a fez)
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

if (current_user() === null) {
    api_out(401, ['ok' => false, 'error' => 'A sessão terminou. Entre outra vez.']);
}
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_out(405, ['ok' => false, 'error' => 'Pedido inválido.']);
}

$sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
    api_out(400, ['ok' => false, 'error' => 'Pedido inválido. Recarregue a página e tente outra vez.']);
}

$do = (string)($_POST['do'] ?? '');
require_perm('tarefas', PERM_EDIT);

if (in_array($do, ['move', 'done', 'reopen'], true)) {
    $id = (int)($_POST['id'] ?? 0);
    $task = task_by_id($pdo, $id);
    if (!$task) {
        api_out(404, ['ok' => false, 'error' => 'Essa tarefa já não existe. Recarregue a página.']);
    }
    $members = team_members($pdo);
    $visible = task_visible_members($pdo, $members);
    if (!task_member_visible($task['member_id'] !== null ? (int)$task['member_id'] : null, $visible)) {
        api_out(404, ['ok' => false, 'error' => 'Essa tarefa já não existe. Recarregue a página.']);
    }
    $rawTo = (string)($_POST['to'] ?? '');
    $toDone = $do === 'done' || ($do === 'move' && $rawTo === 'done');
    if ($do === 'reopen' && $rawTo === '') $rawTo = (string)(int)$task['member_id'];
    $to    = $toDone ? task_member_in($task['member_id'], $members) : task_member_in($rawTo, $members);
    $order = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['order'] ?? '')))));
    $from  = $task['member_id'] !== null ? (int)$task['member_id'] : null;
    if (!$toDone && $to !== $from && !task_can_assign($pdo, $to, $members, $visible)) {
        api_out(403, ['ok' => false, 'error' => 'Não pode atribuir tarefas a essa pessoa.']);
    }

    // O necessário para o «Anular» (§11.10).
    $snap = ['id' => $id, 'status' => $task['status'], 'done_at' => $task['done_at'], 'member_id' => $task['member_id'], 'position' => $task['position']];
    try {
        if ($toDone) {
            $snap['spawned'] = task_done($pdo, $id);
            $final = [];
        } else {
            $final = task_move($pdo, $id, $to, $order);
        }
    } catch (Throwable $e) {
        error_log('tasks_api: ' . $e->getMessage());
        api_out(500, ['ok' => false, 'error' => 'Não foi possível guardar. Tente outra vez.']);
    }
    $now = task_by_id($pdo, $id);
    api_out(200, [
        'ok'        => true,
        'id'        => $id,
        'status'    => $now['status'],
        'done_at'   => $now['done_at'],
        'member_id' => $to,
        'member'    => $to ? $members[$to]['name'] : 'Por atribuir',
        'order'     => $final,
        'undo'      => undo_push('task', $snap),
    ]);
}

api_out(400, ['ok' => false, 'error' => 'Pedido inválido.']);
