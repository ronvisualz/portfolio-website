<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';

// Entrar — o único momento de marca da plataforma (§11.17): cartão sobre o
// fundo animado da topper., com a troca suave entre «Entrar» e «Recuperar
// palavra-passe». A recuperação manda por email um link que vale 30 min;
// só o hash do código fica na base, e a resposta nunca diz se o email existe.

// Already signed in? Go straight through.
if (current_user() !== null) redirect(home_url());

const RESET_PASSWORD_MIN = 10;   // o mesmo mínimo de «A minha conta»
const RESET_TTL_MINUTES  = 30;

$error = '';
$note  = '';
$view  = !empty($_GET['recuperar']) ? 'forgot' : 'login';
if (!empty($_GET['expired'])) $error = 'A sessão expirou. Entre outra vez.';
// a volta de «Entrar com Google» quando não deu (a mensagem fica na sessão, nunca vem do endereço)
if (isset($_GET['g']) && isset($_SESSION['g_err'])) { $error = (string)$_SESSION['g_err']; unset($_SESSION['g_err']); }
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/secrets.php';
$authOn = auth_ready($pdo);
$googleOn = google_client() !== null;

$lite = db_is_sqlite($pdo);
$now  = $lite ? "datetime('now')" : 'NOW()';
$ago15 = $lite ? "datetime('now','-15 minutes')" : 'NOW() - INTERVAL 15 MINUTE';

/** O pedido de recuperação ainda válido para este código, ou null. */
function reset_row(PDO $pdo, string $token, string $now): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = $pdo->prepare("SELECT r.id, r.user_id, " . (db_has_column($pdo, 'password_resets', 'kind') ? 'r.kind' : "'reset' AS kind") . ", u.name FROM password_resets r JOIN users u ON u.id = r.user_id
                          WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > $now
                            AND u.is_active = 1 AND u.deactivated_at IS NULL");
    $st->execute([hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

/** Endereço público para o link do email (nunca tirado só do cabeçalho Host). */
function reset_link(string $token): string {
    global $CONFIG;
    $base = (string)($CONFIG['app_url'] ?? '');
    if ($base === '') {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        // A plataforma vive em topperhub.xyz (§10.3); topperdigital.xyz/admin redireciona para lá.
        $base = IS_LOCAL ? 'http://' . $host : 'https://topperhub.xyz';
    }
    return rtrim($base, '/') . '/admin/index.php?reset=' . $token;
}

$token = (string)($_GET['reset'] ?? $_POST['token'] ?? '');
$invite = null;   // um convite da aba Equipa (§17.8): o mesmo link, outro texto
if ($token !== '') {
    $view = 'reset';
    $rr = reset_row($pdo, $token, $now);
    if ($rr && $rr['kind'] === 'invite') $invite = $rr;
    if (!$rr) {
        $view = 'forgot';
        $error = 'Este link já não é válido. Peça outro.';
        $token = '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? 'login');

    if ($do === 'forgot') {
        $view  = 'forgot';
        $email = trim((string)($_POST['email'] ?? ''));
        $ip    = client_ip_bin();
        $st = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE created_at > $ago15 AND " . ($lite ? 'ip IS ?' : 'ip <=> ?'));
        $st->execute([$ip]);
        $busy = (int)$st->fetchColumn() >= 5;
        $u = null;
        if (!$busy && $email !== '' && strlen($email) <= 190) {
            $st = $pdo->prepare('SELECT id, name, email FROM users WHERE email = ? AND is_active = 1 AND deactivated_at IS NULL LIMIT 1');
            $st->execute([$email]);
            $u = $st->fetch() ?: null;
        }
        if ($u) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > $ago15");
            $st->execute([(int)$u['id']]);
            if ((int)$st->fetchColumn() < 3) {
                $raw = bin2hex(random_bytes(32));
                $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES (?, ?, '
                            . ($lite ? "datetime('now','+" . RESET_TTL_MINUTES . " minutes')" : 'NOW() + INTERVAL ' . RESET_TTL_MINUTES . ' MINUTE') . ', ?)')
                    ->execute([(int)$u['id'], hash('sha256', $raw), $ip]);
                $link = reset_link($raw);
                $body = "Olá " . explode(' ', trim($u['name']))[0] . ",\n\n"
                      . "Pediu para definir uma palavra-passe nova na topper. admin.\n"
                      . "Abra este link nos próximos " . RESET_TTL_MINUTES . " minutos:\n\n$link\n\n"
                      . "Se não foi você, ignore este email: a palavra-passe atual continua a valer.\n";
                // pelo serviço de envio, se estiver pronto (§31.1); senão pelo mail() do servidor, como antes
                require_once __DIR__ . '/inc/mailer.php';
                $sent = app_mail($pdo, (string)$u['email'], 'topper. admin — nova palavra-passe', $body, 'topper.');
                if (IS_LOCAL) error_log('[topper] link de recuperação (só na bancada): ' . $link);
                elseif (!$sent) error_log('[topper] o email de recuperação não saiu para o utilizador ' . (int)$u['id']);
            }
        }
        // Sempre a mesma resposta: não revela se o email existe.
        $note = 'Se o email estiver registado, enviámos um link para definir uma palavra-passe nova. Vale ' . RESET_TTL_MINUTES . ' minutos.';
    }

    elseif ($do === 'reset') {
        $view = 'reset';
        $row  = reset_row($pdo, (string)($_POST['token'] ?? ''), $now);
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if (!$row) {
            $view = 'forgot'; $error = 'Este link já não é válido. Peça outro.'; $token = '';
        } elseif (mb_strlen($p1) < RESET_PASSWORD_MIN) {
            $error = 'A palavra-passe precisa de pelo menos ' . RESET_PASSWORD_MIN . ' caracteres.';
        } elseif ($p1 !== $p2) {
            $error = 'As palavras-passe não coincidem.';
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($p1, PASSWORD_DEFAULT), (int)$row['user_id']]);
            $pdo->prepare("UPDATE password_resets SET used_at = $now WHERE user_id = ? AND used_at IS NULL")->execute([(int)$row['user_id']]);
            if (db_has_column($pdo, 'users', 'invite_pending')) $pdo->prepare('UPDATE users SET invite_pending = 0 WHERE id = ?')->execute([(int)$row['user_id']]);
            $view = 'login'; $token = '';
            $note = 'Palavra-passe definida. Já pode entrar.';
        }
    }

    else {
        $email = trim((string)($_POST['email'] ?? ''));
        $pass  = (string)($_POST['password'] ?? '');

        if (is_locked_out($pdo, $email)) {
            $error = 'Demasiadas tentativas. Aguarde alguns minutos.';
        } elseif ($email === '' || $pass === '') {
            $error = 'Preencha o email e a palavra-passe.';
        } else {
            $st = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
            $st->execute([$email]);
            $user = $st->fetch();

            // Always run a hash comparison, even when the user doesn't exist,
            // so response time doesn't reveal which emails are registered.
            $hash = $user['password_hash']
                 ?? '$2y$12$usesomesillystringfoeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

            if (password_verify($pass, $hash) && $user) {
                // quem tem de usar a chave de acesso (Definições › Segurança) e já a criou não entra com a palavra-passe
                if ($authOn && ($block = auth_method_blocked($pdo, $user, 'password')) !== '') {
                    $error = $block;
                } else {
                    record_attempt($pdo, $email, true);
                    login_user($user);
                    auth_log_login($pdo, (int)$user['id'], 'password');
                    redirect($authOn ? auth_after_login($pdo, $user, 'password') : home_url());
                }
            } else {
                record_attempt($pdo, $email, false);
                // Deliberately vague: never confirm whether the email exists.
                $error = 'Credenciais inválidas.';
            }
        }
    }
}


$msg = function (string $for) use ($view, $error, $note) {
    if ($view !== $for) return '';
    return ($error ? '<div class="flash err" role="alert">' . e($error) . '</div>' : '')
         . ($note ? '<div class="flash ok" role="status">' . e($note) . '</div>' : '');
};
?><!DOCTYPE html>
<html lang="pt">
<head>
<?php render_head('Entrar'); ?>
</head>
<body class="login-page">
<div class="login-bg" aria-hidden="true">
  <span class="blob b1"></span><span class="blob b2"></span><span class="blob b3"></span>
  <div class="mark3d"><i></i></div>
</div>
<main class="login-card">
  <?= wordmark_svg('wm') ?>
  <div class="login-views">
    <?php if ($view === 'reset'): ?>
    <section data-view="reset" aria-hidden="false">
      <?php if ($invite): ?>
      <h1>Bem-vindo, <?= e(explode(' ', trim((string)$invite['name']))[0]) ?></h1>
      <p>Defina a sua palavra-passe para entrar na plataforma da topper. (pelo menos <?= RESET_PASSWORD_MIN ?> caracteres).</p>
      <?php else: ?>
      <h1>Nova palavra-passe</h1>
      <p>Escolha uma palavra-passe com pelo menos <?= RESET_PASSWORD_MIN ?> caracteres.</p>
      <?php endif; ?>
      <?= $msg('reset') ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="do" value="reset"><input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="np">Nova palavra-passe</label>
          <input type="password" id="np" name="password" required minlength="<?= RESET_PASSWORD_MIN ?>" autocomplete="new-password" autofocus></div>
        <div class="field"><label for="np2">Repetir</label>
          <input type="password" id="np2" name="password2" required minlength="<?= RESET_PASSWORD_MIN ?>" autocomplete="new-password"></div>
        <button type="submit" class="btn">Guardar e entrar</button>
      </form>
    </section>
    <?php else: ?>
    <section data-view="login" aria-hidden="<?= $view === 'login' ? 'false' : 'true' ?>">
      <h1>Entrar</h1>
      <p>Área reservada à equipa da topper.</p>
      <?= $msg('login') ?>
      <form method="post" autocomplete="on">
        <?= csrf_field() ?><input type="hidden" name="do" value="login">
        <div class="field"><label for="email">Email</label>
          <input type="email" id="email" name="email" required <?= $view === 'login' ? 'autofocus' : '' ?>
                 value="<?= e($_POST['email'] ?? '') ?>" autocomplete="username webauthn"></div>
        <div class="field"><label for="password">Palavra-passe</label>
          <input type="password" id="password" name="password" required autocomplete="current-password"></div>
        <button type="submit" class="btn">Entrar</button>
      </form>
      <?php if ($authOn || $googleOn): ?>
      <div class="login-alt">
        <span class="login-or">ou</span>
        <div class="flash" data-passkey-out hidden role="status"></div>
        <?php if ($authOn): ?><button type="button" class="btn ghost" data-passkey-login hidden><?= icon('key') ?> Entrar com chave de acesso</button><?php endif; ?>
        <?php if ($googleOn): ?><a class="btn ghost" href="/admin/google_auth.php?start=login"><svg class="g-logo" viewBox="0 0 18 18" aria-hidden="true"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/><path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/></svg> Entrar com Google</a><?php endif; ?>
      </div>
      <?php endif; ?>
      <a class="lnk" href="?recuperar=1" data-go="forgot">Esqueceu-se da palavra-passe?</a>
    </section>
    <section data-view="forgot" aria-hidden="<?= $view === 'forgot' ? 'false' : 'true' ?>">
      <h1>Recuperar palavra-passe</h1>
      <p>Indique o email da sua conta. Enviamos um link para definir uma palavra-passe nova.</p>
      <?= $msg('forgot') ?>
      <form method="post" autocomplete="on">
        <?= csrf_field() ?><input type="hidden" name="do" value="forgot">
        <div class="field"><label for="femail">Email</label>
          <input type="email" id="femail" name="email" required <?= $view === 'forgot' ? 'autofocus' : '' ?> autocomplete="username"></div>
        <button type="submit" class="btn">Enviar link</button>
      </form>
      <a class="lnk" href="/admin/index.php" data-go="login">← Voltar a entrar</a>
    </section>
    <?php endif; ?>
  </div>
</main>
<script>
// Troca suave entre «Entrar» e «Recuperar palavra-passe», sem recarregar.
document.addEventListener('click', function (e) {
  var a = e.target.closest('[data-go]'); if (!a) return;
  e.preventDefault();
  var to = a.getAttribute('data-go');
  document.querySelectorAll('.login-views > section').forEach(function (s) {
    s.setAttribute('aria-hidden', s.getAttribute('data-view') === to ? 'false' : 'true');
  });
  var mail = document.getElementById(to === 'forgot' ? 'femail' : 'email'), from = document.getElementById(to === 'forgot' ? 'email' : 'femail');
  if (mail && from && !mail.value) mail.value = from.value;
  setTimeout(function () { if (mail) mail.focus(); }, 60);
});
</script>
<?php if ($authOn && $view !== 'reset'): ?><script src="<?= asset_url('passkey.js') ?>" defer></script><?php endif; ?>
</body>
</html>
