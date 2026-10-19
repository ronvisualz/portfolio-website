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
 *             no sítio onde foi largada)
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/tasks.php';

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

if ($do === 'move') {
    $id = (int)($_POST['id'] ?? 0);
    if (!task_by_id($pdo, $id)) {
        api_out(404, ['ok' => false, 'error' => 'Essa tarefa já não existe. Recarregue a página.']);
    }
    $members = team_members($pdo);
    $to    = task_member_in($_POST['to'] ?? 0, $members);
    $order = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['order'] ?? '')))));

    try {
        $final = task_move($pdo, $id, $to, $order);
    } catch (Throwable $e) {
        error_log('tasks_api: ' . $e->getMessage());
        api_out(500, ['ok' => false, 'error' => 'Não foi possível guardar. Tente outra vez.']);
    }
    api_out(200, [
        'ok'        => true,
        'id'        => $id,
        'member_id' => $to,
        'member'    => $to ? $members[$to]['name'] : 'Por atribuir',
        'order'     => $final,
    ]);
}

api_out(400, ['ok' => false, 'error' => 'Pedido inválido.']);
