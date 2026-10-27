<?php
/**
 * topper. admin — volta da Google (§31.2 «Entrar com Google» e §31.3 «Ligar Google Calendar»)
 *
 *   ?start=login      vai à Google para entrar (sem sessão)
 *   ?start=calendar   vai à Google para ligar o calendário (com sessão)
 *   ?code=…&state=…   a volta: entra, ou guarda a ligação do calendário (token cifrado)
 * Este endereço tem de estar nos «URIs de redirecionamento autorizados» do cliente OAuth (Definições mostra-o).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/google_oauth.php';

// a mensagem fica na sessão (nunca no endereço: ninguém consegue fabricar um link com texto falso no ecrã de entrada)
$back = function (string $msg): void { $_SESSION['g_err'] = mb_substr($msg, 0, 300); redirect('/admin/index.php?g=1'); };

try {
    $start = (string)($_GET['start'] ?? '');
    if ($start === 'login') {
        if (current_user() !== null) redirect(home_url());
        redirect(google_auth_url('login'));
    }
    if ($start === 'calendar') {
        $me = require_login();
        if (!db_has_table($pdo, 'user_google')) throw new RuntimeException('A base de dados ainda não está no passo 35.');
        redirect(google_auth_url('calendar', (string)$me['email']));
    }

    if (isset($_GET['error'])) {
        $cal = current_user() !== null;
        $msg = (string)$_GET['error'] === 'access_denied' ? 'Cancelado na Google.' : 'A Google não deu acesso (' . preg_replace('/[^a-z_]/', '', (string)$_GET['error']) . ').';
        if ($cal) { flash($msg, 'err'); redirect('/admin/conta.php#ligacoes'); }
        $back($msg);
    }
    $state = (string)($_GET['state'] ?? '');
    $code = (string)($_GET['code'] ?? '');
    if ($state === '' || $code === '' || strlen($state) > 100 || strlen($code) > 2000) $back('Pedido inválido.');

    [$purpose, $tok, $id, $uid] = google_callback($state, $code);
    $email = strtolower(trim((string)($id['email'] ?? '')));
    $verified = ($id['email_verified'] ?? false) === true || ($id['email_verified'] ?? '') === 'true';
    $sub = preg_replace('/[^0-9A-Za-z_-]/', '', (string)($id['sub'] ?? ''));

    if ($purpose === 'calendar') {
        $me = require_login();
        if ($uid !== (int)$me['id']) throw new RuntimeException('A sessão mudou a meio. Tente outra vez.');
        if (empty($tok['refresh_token'])) throw new RuntimeException('A Google não devolveu o acesso permanente. Em myaccount.google.com › Segurança › Ligações de terceiros, retire a topper. e volte a ligar.');
        $scopes = (string)($tok['scope'] ?? '');
        if (!str_contains($scopes, 'calendar.events')) throw new RuntimeException('Falta a autorização para os eventos do calendário: volte a ligar e deixe a caixa marcada.');
        require_once __DIR__ . '/inc/secrets.php';
        $enc = secret_seal(json_encode(['refresh_token' => (string)$tok['refresh_token']]));
        $now = date('Y-m-d H:i:s');
        $exists = $pdo->prepare('SELECT COUNT(*) FROM user_google WHERE user_id = ?');
        $exists->execute([(int)$me['id']]);
        if ((int)$exists->fetchColumn()) {
            $pdo->prepare("UPDATE user_google SET google_email = ?, token_enc = ?, scopes = ?, last_error = '', busy_cache = NULL, busy_at = NULL, connected_at = ? WHERE user_id = ?")
                ->execute([$email, $enc, mb_substr($scopes, 0, 500), $now, (int)$me['id']]);
        } else {
            $pdo->prepare('INSERT INTO user_google (user_id, google_email, token_enc, scopes, calendar_id, connected_at) VALUES (?,?,?,?,?,?)')
                ->execute([(int)$me['id'], $email, $enc, mb_substr($scopes, 0, 500), 'primary', $now]);
        }
        flash('Google Calendar ligado (' . $email . '). As suas reuniões, gravações, visitas e prazos com hora passam a aparecer lá.');
        redirect('/admin/conta.php#ligacoes');
    }

    // ── entrar com Google ──
    if (current_user() !== null) redirect(home_url());
    if (is_locked_out($pdo, $email)) $back('Demasiadas tentativas. Aguarde alguns minutos.');
    if ($email === '' || !$verified) { record_attempt($pdo, $email, false); $back('A Google não confirmou o email desta conta.'); }
    $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1 AND deactivated_at IS NULL LIMIT 1');
    $st->execute([$email]);
    $user = $st->fetch();
    // nunca cria contas: só entra quem já existe com o mesmo email
    if (!$user) { record_attempt($pdo, $email, false); $back('Não há nenhuma conta da topper. com o email ' . $email . '. Entre com a conta Google do seu email de trabalho.'); }
    $known = (string)($user['google_sub'] ?? '');
    if ($known !== '' && $sub !== '' && !hash_equals($known, $sub)) { record_attempt($pdo, $email, false); $back('Esta conta Google não é a que ficou ligada a este utilizador.'); }
    if ($block = auth_method_blocked($pdo, $user, 'google')) $back($block);
    if ($known === '' && $sub !== '') $pdo->prepare('UPDATE users SET google_sub = ? WHERE id = ?')->execute([$sub, (int)$user['id']]);
    record_attempt($pdo, $email, true);
    login_user($user);
    auth_log_login($pdo, (int)$user['id'], 'google');
    redirect(auth_after_login($pdo, $user, 'google'));
} catch (RuntimeException $e) {
    if (current_user() !== null) { flash($e->getMessage(), 'err'); redirect('/admin/conta.php#ligacoes'); }
    $back($e->getMessage());
}
