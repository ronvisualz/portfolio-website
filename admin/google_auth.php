<?php
/**
 * topper. admin — volta da Google (§31.2 «Entrar com Google», §31.3 «Ligar Google Calendar» e §31.5)
 *
 *   ?start=login            vai à Google para entrar (sem sessão)
 *   ?start=link[&cal=1]     associa uma conta Google à pessoa (com sessão); com cal=1 liga também o calendário
 *   ?start=calendar         vai à Google para ligar o calendário (com sessão; sugere a conta associada)
 *   ?start=topper           liga a conta da topper. para o Meet (só admins)
 *   ?code=…&state=…         a volta: entra, associa, guarda a ligação do calendário ou a conta da topper.
 * Este endereço tem de estar nos «URIs de redirecionamento autorizados» do cliente OAuth (Definições mostra-o).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/google_oauth.php';

// a mensagem fica na sessão (nunca no endereço: ninguém consegue fabricar um link com texto falso no ecrã de entrada)
$back = function (string $msg): void { $_SESSION['g_err'] = mb_substr($msg, 0, 300); redirect('/admin/index.php?g=1'); };
$toProfile = '/admin/conta.php#ligacoes';
$toSettings = '/admin/definicoes.php?t=integracoes#set-int_google';

// A Google volta sempre ao endereço registado (app_url): quem começar noutro (ex.: www.) passa primeiro para lá,
// senão a sessão (e o «state» guardado nela) ficava no outro endereço e a volta dava «pedido expirado».
$appHost = strtolower((string)parse_url(app_base_url(), PHP_URL_HOST));
$here = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
if (isset($_GET['start']) && $appHost !== '' && $here !== '' && $here !== $appHost && !(defined('IS_LOCAL') && IS_LOCAL)) {
    redirect(app_base_url() . '/admin/google_auth.php?' . http_build_query(['start' => (string)$_GET['start']] + (isset($_GET['cal']) ? ['cal' => '1'] : [])));
}

try {
    $start = (string)($_GET['start'] ?? '');
    if ($start === 'login') {
        if (current_user() !== null) redirect(home_url());
        redirect(google_auth_url('login'));
    }
    if ($start === 'link') {
        $me = require_login();
        $cal = !empty($_GET['cal']) && db_has_table($pdo, 'user_google');
        $lk = google_linked($pdo, (int)$me['id']);
        redirect(google_auth_url($cal ? 'linkcal' : 'link', $lk['email'] ?? ''));
    }
    if ($start === 'calendar') {
        $me = require_login();
        if (!db_has_table($pdo, 'user_google')) throw new RuntimeException('A base de dados ainda não está no passo 35.');
        // a conta associada no Perfil é a sugerida: a Google só pede a autorização do calendário
        $lk = google_linked($pdo, (int)$me['id']);
        redirect(google_auth_url('calendar', ($lk['email'] ?? '') !== '' ? $lk['email'] : (string)$me['email']));
    }
    if ($start === 'topper') {
        require_login();
        if (!is_admin()) throw new RuntimeException('Só um administrador liga a conta da topper.');
        redirect(google_auth_url('topper', google_topper_account() ?: TOPPER_GOOGLE_ACCOUNT));
    }

    if (isset($_GET['error'])) {
        $msg = (string)$_GET['error'] === 'access_denied' ? 'Cancelado na Google.' : 'A Google não deu acesso (' . preg_replace('/[^a-z_]/', '', (string)$_GET['error']) . ').';
        if (current_user() !== null) { flash($msg, 'err'); redirect($toProfile); }
        $back($msg);
    }
    $state = (string)($_GET['state'] ?? '');
    $code = (string)($_GET['code'] ?? '');
    if ($state === '' || $code === '' || strlen($state) > 100 || strlen($code) > 2000) $back('Pedido inválido.');

    [$purpose, $tok, $id, $uid] = google_callback($state, $code);
    $email = strtolower(trim((string)($id['email'] ?? '')));
    $verified = ($id['email_verified'] ?? false) === true || ($id['email_verified'] ?? '') === 'true';
    $sub = preg_replace('/[^0-9A-Za-z_-]/', '', (string)($id['sub'] ?? ''));

    // ── associar a conta Google (e, com «linkcal», ligar já o calendário: uma só autorização) ──
    if ($purpose === 'link' || $purpose === 'linkcal') {
        $me = require_login();
        if ($uid !== (int)$me['id']) throw new RuntimeException('A sessão mudou a meio. Tente outra vez.');
        google_link_save($pdo, (int)$me['id'], $sub, $email);
        $msg = 'Conta Google associada (' . $email . '): pode entrar com ela em «Entrar com Google».';
        if ($purpose === 'linkcal') {
            google_calendar_store($pdo, (int)$me['id'], $tok, $email);
            $msg .= ' O Google Calendar também ficou ligado.';
        }
        flash($msg);
        redirect($toProfile);
    }

    if ($purpose === 'calendar') {
        $me = require_login();
        if ($uid !== (int)$me['id']) throw new RuntimeException('A sessão mudou a meio. Tente outra vez.');
        google_calendar_store($pdo, (int)$me['id'], $tok, $email);
        // sem conta associada, esta passa a sê-lo (a menos que já seja de outra pessoa): não se pede duas vezes
        $extra = '';
        if (!google_linked($pdo, (int)$me['id']) && $sub !== '') {
            try { google_link_save($pdo, (int)$me['id'], $sub, $email); $extra = ' Esta conta Google ficou também associada para «Entrar com Google».'; }
            catch (RuntimeException $e) { /* já é de outra pessoa: fica só o calendário */ }
        }
        flash('Google Calendar ligado (' . $email . '). As suas reuniões, gravações, visitas e prazos com hora passam a aparecer lá.' . $extra);
        redirect($toProfile);
    }

    if ($purpose === 'topper') {
        require_login();
        if (!is_admin()) throw new RuntimeException('Só um administrador liga a conta da topper.');
        if (empty($tok['refresh_token'])) throw new RuntimeException('A Google não devolveu o acesso permanente. Em myaccount.google.com › Segurança › Ligações de terceiros, retire a topper. e volte a ligar.');
        if (!str_contains((string)($tok['scope'] ?? ''), 'calendar.events')) throw new RuntimeException('Falta a autorização para os eventos do calendário: volte a ligar e deixe a caixa marcada.');
        google_topper_store((string)$tok['refresh_token'], $email);
        flash('Conta da topper. ligada (' . $email . '): os Meet de quem não tem o calendário ligado passam a ser criados por ela.'
            . ($email !== TOPPER_GOOGLE_ACCOUNT ? ' Atenção: não é ' . TOPPER_GOOGLE_ACCOUNT . '.' : ''));
        redirect($toSettings);
    }

    // ── entrar com Google ──
    if (current_user() !== null) redirect(home_url());
    if (is_locked_out($pdo, $email)) $back('Demasiadas tentativas. Aguarde alguns minutos.');
    $active = 'is_active = 1 AND deactivated_at IS NULL';
    // 1) a conta Google associada no Perfil (o email da Google pode ser outro)
    $user = null;
    if ($sub !== '') {
        $st = $pdo->prepare("SELECT * FROM users WHERE google_sub = ? AND $active LIMIT 1");
        $st->execute([$sub]);
        $user = $st->fetch() ?: null;
    }
    // 2) sem associação: o mesmo email, verificado pela Google (e essa conta fica associada)
    if (!$user) {
        if ($email === '' || !$verified) { record_attempt($pdo, $email, false); $back('A Google não confirmou o email desta conta.'); }
        $st = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = ? AND $active LIMIT 1");
        $st->execute([$email]);
        $user = $st->fetch() ?: null;
        if (!$user) { record_attempt($pdo, $email, false); $back('Esta conta Google (' . $email . ') não está associada a ninguém da topper. Entre com o email e a palavra-passe ou com a chave de acesso e associe-a em Perfil › Ligações › «Ligar conta Google».'); }
        $known = (string)($user['google_sub'] ?? '');
        if ($known !== '' && $sub !== '' && !hash_equals($known, $sub)) { record_attempt($pdo, $email, false); $back('Esta conta Google não é a que ficou associada a este utilizador.'); }
        if ($known === '' && $sub !== '') {
            $mail = db_has_column($pdo, 'users', 'google_email');
            $pdo->prepare('UPDATE users SET google_sub = ?' . ($mail ? ', google_email = ?, google_linked_at = ?' : '') . ' WHERE id = ?')
                ->execute($mail ? [$sub, $email, date('Y-m-d H:i:s'), (int)$user['id']] : [$sub, (int)$user['id']]);
        }
    }
    if ($block = auth_method_blocked($pdo, $user, 'google')) $back($block);
    record_attempt($pdo, (string)$user['email'], true);
    login_user($user);
    auth_log_login($pdo, (int)$user['id'], 'google');
    redirect(auth_after_login($pdo, $user, 'google'));
} catch (RuntimeException $e) {
    if (current_user() !== null) { flash($e->getMessage(), 'err'); redirect((($purpose ?? '') === 'topper' || ($start ?? '') === 'topper') && is_admin() ? $toSettings : $toProfile); }
    $back($e->getMessage());
}
