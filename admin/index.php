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

$lite = db_is_sqlite($pdo);
$now  = $lite ? "datetime('now')" : 'NOW()';
$ago15 = $lite ? "datetime('now','-15 minutes')" : 'NOW() - INTERVAL 15 MINUTE';

/** O pedido de recuperação ainda válido para este código, ou null. */
function reset_row(PDO $pdo, string $token, string $now): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = $pdo->prepare("SELECT r.id, r.user_id FROM password_resets r JOIN users u ON u.id = r.user_id
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
        $base = IS_LOCAL ? 'http://' . $host
              : (in_array($host, ['topperdigital.xyz', 'www.topperdigital.xyz'], true) ? 'https://' . $host : 'https://topperdigital.xyz');
    }
    return rtrim($base, '/') . '/admin/index.php?reset=' . $token;
}

$token = (string)($_GET['reset'] ?? $_POST['token'] ?? '');
if ($token !== '') {
    $view = 'reset';
    if (!reset_row($pdo, $token, $now)) {
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
                $headers = "From: topper. <no-reply@topperdigital.xyz>\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Auto-Response-Suppress: All";
                $sent = @mail($u['email'], '=?UTF-8?B?' . base64_encode('topper. admin — nova palavra-passe') . '?=', $body, $headers);
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
                record_attempt($pdo, $email, true);
                $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
                    ->execute([$user['id']]);
                login_user($user);
                redirect(home_url());
            }

            record_attempt($pdo, $email, false);
            // Deliberately vague: never confirm whether the email exists.
            $error = 'Credenciais inválidas.';
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
      <h1>Nova palavra-passe</h1>
      <p>Escolha uma palavra-passe com pelo menos <?= RESET_PASSWORD_MIN ?> caracteres.</p>
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
                 value="<?= e($_POST['email'] ?? '') ?>" autocomplete="username"></div>
        <div class="field"><label for="password">Palavra-passe</label>
          <input type="password" id="password" name="password" required autocomplete="current-password"></div>
        <button type="submit" class="btn">Entrar</button>
      </form>
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
</body>
</html>
