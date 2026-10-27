<?php
/**
 * topper. admin — formas de entrar e registo de entradas (§31.2)
 *
 * Palavra-passe (como sempre), chave de acesso (passkey) e «Entrar com Google» (só quem já tem utilizador com o
 * mesmo email). O método de entrada não muda nada do que cada pessoa vê: a sessão é a mesma (login_user).
 * Cada entrada fica no registo (método, aparelho, data), que a pessoa vê em Perfil › Segurança.
 * Os admins podem tornar a chave de acesso obrigatória por função (Definições › Segurança).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const LOGIN_METHODS = ['password' => 'Palavra-passe', 'passkey' => 'Chave de acesso', 'google' => 'Google'];
/** Quem tem de entrar com chave de acesso: nível → funções. */
const PASSKEY_REQUIRED = ['' => [], 'admins' => ['superadmin', 'admin'], 'leads' => ['superadmin', 'admin', 'lead'], 'all' => ['superadmin', 'admin', 'lead', 'member']];

function auth_ready(PDO $pdo): bool {
    return db_has_table($pdo, 'login_log') && db_has_table($pdo, 'user_passkeys');
}

/** «Chrome · macOS» a partir do User-Agent (só para a pessoa reconhecer o aparelho). */
function auth_device(string $ua): string {
    $b = match (true) {
        str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', default => 'Browser',
    };
    $os = match (true) {
        str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad', str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS', str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'CrOS') => 'ChromeOS', str_contains($ua, 'Linux') => 'Linux', default => '',
    };
    return $os !== '' ? $b . ' · ' . $os : $b;
}

/** Regista uma entrada bem-sucedida (e a data da última entrada). */
function auth_log_login(PDO $pdo, int $uid, string $method): void {
    $pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $uid]);
    if (!auth_ready($pdo)) return;
    $pdo->prepare('INSERT INTO login_log (user_id, method, device, ip, created_at) VALUES (?,?,?,?,?)')
        ->execute([$uid, isset(LOGIN_METHODS[$method]) ? $method : 'password', mb_substr(auth_device((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 120), client_ip_bin(), date('Y-m-d H:i:s')]);
}

/** As últimas entradas de uma pessoa. */
function auth_log_list(PDO $pdo, int $uid, int $n = 15): array {
    if (!auth_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT method, device, ip, created_at FROM login_log WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $n)));
    $st->execute([$uid]);
    return $st->fetchAll();
}

/** Um IP para mostrar à própria pessoa, sem o último bloco. */
function auth_ip_show(?string $bin): string {
    if ($bin === null || $bin === '') return '';
    $ip = @inet_ntop($bin);
    if (!$ip) return '';
    return str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 3)) . ':…' : preg_replace('/\.\d+$/', '.…', $ip);
}

function auth_passkeys(PDO $pdo, int $uid): array {
    if (!auth_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT id, name, cred_id, transports, created_at, last_used_at, last_ua FROM user_passkeys WHERE user_id = ? ORDER BY id');
    $st->execute([$uid]);
    return $st->fetchAll();
}

/** A chave de acesso é obrigatória para esta função? (Definições › Segurança) */
function auth_passkey_required(PDO $pdo, string $role): bool {
    $lvl = (string)setting($pdo, 'passkey_required', '');
    return in_array($role, PASSKEY_REQUIRED[$lvl] ?? [], true);
}

/**
 * Pode entrar por este método (palavra-passe ou Google)? Quem tem de usar a chave e já tem uma criada, não.
 * Devolve '' (pode) ou a mensagem.
 */
function auth_method_blocked(PDO $pdo, array $user, string $method): string {
    if ($method === 'passkey' || !auth_passkey_required($pdo, (string)$user['role'])) return '';
    return auth_passkeys($pdo, (int)$user['id']) ? 'Para a sua função, a entrada é com a chave de acesso (Face ID, impressão digital ou PIN). Use «Entrar com chave de acesso».' : '';
}

/** Depois de entrar: a pessoa tem de criar a chave? Então vai para o Perfil com o aviso. */
function auth_after_login(PDO $pdo, array $user, string $method): string {
    if ($method !== 'passkey' && auth_passkey_required($pdo, (string)$user['role']) && !auth_passkeys($pdo, (int)$user['id'])) {
        flash('A sua função pede uma chave de acesso: crie-a agora (demora segundos) e, das próximas vezes, entre com ela.', 'info');
        return '/admin/conta.php#seguranca';
    }
    return home_url();
}
