<?php
/**
 * topper. admin — chaves de acesso (passkeys) (§31.2)
 *
 *   GET  ?a=login_options   o desafio para entrar (público; guardado na sessão, vale 2 minutos, uma vez)
 *   POST ?a=login           entrar com a resposta do aparelho (público; token CSRF da página de entrada)
 *   GET  ?a=reg_options     o pedido para criar uma chave nova (com sessão)
 *   POST ?a=reg             gravar a chave nova (com sessão; token CSRF)
 * A chave privada nunca sai do aparelho: aqui só ficam o id da credencial, a chave pública e o contador.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/webauthn.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function pj(int $code, array $b): void { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
if (!auth_ready($pdo)) pj(503, ['error' => 'A base de dados ainda não está no passo 35.']);

$a = (string)($_GET['a'] ?? '');
[$rpId, $origin] = wa_rp();
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
// já entrou (ex.: o browser propôs a chave e a pessoa também carregou no botão): segue, sem mudar nada
if ($a === 'login' && $post && current_user() !== null) pj(200, ['ok' => true, 'go' => home_url()]);
if ($post) {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) pj(403, ['error' => 'Pedido inválido. Recarregue a página e tente outra vez.']);
}
$in = $post ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];

/** O desafio guardado para esta cerimónia (gasta-se ao usar). */
$challenge = function (string $k): string {
    $c = $_SESSION[$k] ?? null;
    unset($_SESSION[$k]);
    if (!is_array($c) || ($c['t'] ?? 0) < time() - 120) throw new RuntimeException('O pedido expirou. Tente outra vez.');
    return (string)$c['c'];
};

try {
    if ($a === 'login_options') {
        $c = random_bytes(32);
        $_SESSION['wa_login'] = ['c' => $c, 't' => time()];
        pj(200, ['challenge' => wa_b64u($c), 'rpId' => $rpId, 'timeout' => 120000, 'userVerification' => 'required']);
    }

    if ($a === 'login' && $post) {
        if (is_locked_out($pdo, '')) pj(429, ['error' => 'Demasiadas tentativas. Aguarde alguns minutos.']);
        $ch = $challenge('wa_login');
        $credId = (string)($in['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,255}$/', $credId)) throw new RuntimeException('Chave inválida.');
        $st = $pdo->prepare('SELECT p.*, u.role, u.is_active, u.deactivated_at, u.email FROM user_passkeys p JOIN users u ON u.id = p.user_id WHERE p.cred_id = ?');
        $st->execute([$credId]);
        $pk = $st->fetch();
        if (!$pk || (int)$pk['is_active'] !== 1 || !empty($pk['deactivated_at'])) {
            record_attempt($pdo, '', false);
            throw new RuntimeException('Esta chave de acesso não está registada (ou foi removida). Entre com a palavra-passe e crie outra no Perfil.');
        }
        // a chave tem de ser da pessoa a quem foi emitida
        if (isset($in['userHandle']) && $in['userHandle'] !== null && $in['userHandle'] !== '' && !hash_equals(wa_b64u(wa_user_handle((int)$pk['user_id'])), (string)$in['userHandle'])) throw new RuntimeException('Chave inválida.');
        try {
            $count = wa_login_verify($in, $pk, $ch, $origin, $rpId);
        } catch (RuntimeException $e) {
            record_attempt($pdo, (string)$pk['email'], false);
            throw $e;
        }
        $pdo->prepare('UPDATE user_passkeys SET sign_count = ?, last_used_at = ?, last_ua = ? WHERE id = ?')
            ->execute([$count, date('Y-m-d H:i:s'), mb_substr(auth_device((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255), (int)$pk['id']]);
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([(int)$pk['user_id']]);
        $user = $st->fetch();
        record_attempt($pdo, (string)$user['email'], true);
        login_user($user);
        auth_log_login($pdo, (int)$user['id'], 'passkey');
        pj(200, ['ok' => true, 'go' => auth_after_login($pdo, $user, 'passkey')]);
    }

    // ── daqui para baixo, só com sessão ──
    $me = current_user();
    if ($me === null) pj(401, ['error' => 'A sessão terminou. Entre outra vez.']);
    $me = require_login();

    if ($a === 'reg_options') {
        $c = random_bytes(32);
        $_SESSION['wa_reg'] = ['c' => $c, 't' => time()];
        $ex = array_map(fn($p) => ['type' => 'public-key', 'id' => $p['cred_id']], auth_passkeys($pdo, (int)$me['id']));
        pj(200, ['challenge' => wa_b64u($c), 'rp' => ['id' => $rpId, 'name' => 'topper.'],
                 'user' => ['id' => wa_b64u(wa_user_handle((int)$me['id'])), 'name' => (string)$me['email'], 'displayName' => (string)$me['name']],
                 'pubKeyCredParams' => array_map(fn($alg) => ['type' => 'public-key', 'alg' => $alg], WA_ALGS),
                 'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
                 'attestation' => 'none', 'timeout' => 120000, 'excludeCredentials' => $ex]);
    }

    if ($a === 'reg' && $post) {
        $ch = $challenge('wa_reg');
        $r = wa_register_verify($in, $ch, $origin, $rpId);
        $name = trim(mb_substr(preg_replace('/[\x00-\x1f<>]/u', '', (string)($in['name'] ?? '')), 0, 80));
        if ($name === '') $name = auth_device((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $tr = implode(',', array_slice(array_filter(array_map(fn($t) => preg_replace('/[^a-z-]/', '', (string)$t), (array)($in['transports'] ?? []))), 0, 6));
        try {
            $pdo->prepare('INSERT INTO user_passkeys (user_id, name, cred_id, public_key, alg, sign_count, transports, created_at) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([(int)$me['id'], $name, $r['cred_id'], $r['key'], $r['alg'], $r['count'], $tr, date('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            throw new RuntimeException('Esta chave já está registada.');
        }
        if (function_exists('team_log')) team_log($pdo, (int)$me['id'], 'passkey', 'Chave de acesso criada: ' . $name, (int)$me['id']);
        pj(200, ['ok' => true, 'msg' => 'Chave de acesso «' . $name . '» criada. Da próxima vez, entre com ela.']);
    }

    pj(400, ['error' => 'Ação desconhecida.']);
} catch (RuntimeException $e) {
    pj(422, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('passkey ' . $a . ': ' . $e->getMessage());
    pj(500, ['error' => 'Não foi possível concluir. Tente outra vez.']);
}
