<?php
/**
 * topper. admin — Drive: partilhas «Só ver» / «Pode editar» (§32.2)
 *
 *  · drive_share_allows()  o que quem tem o link pode fazer — a única porta, verificada no servidor em cada pedido.
 *                          «Só ver»: ver e descarregar (pasta: também o ZIP). «Pode editar» numa pasta: carregar,
 *                          criar subpastas, renomear e substituir versões; num ficheiro: substituir. Apagar (ficheiros,
 *                          pastas ou versões), mover ou mandar para o Lixo: nunca, com nenhuma permissão.
 *  · drive_share_update()  muda a permissão, a validade ou a password sem mudar o link (o token fica).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const DRIVE_SHARE_PERMS = ['view' => 'Só ver', 'edit' => 'Pode editar'];
const DRIVE_SHARE_EDIT_ACTIONS = ['upload', 'mkdir', 'rename', 'replace'];

function drive_share_allows(array $share, string $action): bool {
    if (($share['state'] ?? 'ok') !== 'ok') return false;
    $folder = !empty($share['folder']);
    if ($action === 'view' || $action === 'download') return true;
    if ($action === 'zip') return $folder;
    if (($share['perm'] ?? 'view') !== 'edit') return false;
    return $folder ? in_array($action, DRIVE_SHARE_EDIT_ACTIONS, true) : $action === 'replace';
}

/**
 * Muda uma partilha ativa: perm ('view'|'edit'), hours (1–168, a contar de agora) ou forever (só pastas), password
 * (nova) ou clear_password. O link (token) nunca muda. Lança RuntimeException com o código HTTP.
 */
function drive_share_update(PDO $pdo, int $id, array $in): array {
    require_once __DIR__ . '/drive_store.php';
    $s = drive_share_get($pdo, $id);
    if (!$s || $s['revoked'] || (!$s['forever'] && $s['exp'] <= time())) throw new RuntimeException('Este link já não está ativo.', 404);
    $set = [];
    if (array_key_exists('perm', $in)) {
        if (!isset(DRIVE_SHARE_PERMS[$in['perm']])) throw new RuntimeException('Permissão inválida.', 400);
        $set['perm'] = $in['perm'];
    }
    if (!empty($in['forever'])) {
        if (!$s['folder']) throw new RuntimeException('Só uma pasta pode ficar partilhada sem prazo.', 400);
        $set['expires_at'] = DRIVE_SHARE_FOREVER;
    } elseif (array_key_exists('hours', $in)) {
        $h = filter_var($in['hours'], FILTER_VALIDATE_INT);
        if (!is_int($h) || $h < 1 || $h > DRIVE_SHARE_MAX_HOURS) throw new RuntimeException('Escolha uma duração entre 1 hora e 7 dias.', 400);
        $set['expires_at'] = drive_now($h * 3600);
    }
    if (!empty($in['clear_password'])) $set['pw_hash'] = null;
    elseif (isset($in['password']) && $in['password'] !== '') {
        $pw = (string)$in['password'];
        if (mb_strlen($pw) < DRIVE_SHARE_PW_MIN || mb_strlen($pw) > DRIVE_SHARE_PW_MAX) throw new RuntimeException('A password do link tem de ter entre ' . DRIVE_SHARE_PW_MIN . ' e ' . DRIVE_SHARE_PW_MAX . ' caracteres.', 400);
        $set['pw_hash'] = password_hash($pw, PASSWORD_DEFAULT);
    }
    if ($set) $pdo->prepare('UPDATE drive_shares SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $id]);
    return drive_share_get($pdo, $id);
}
