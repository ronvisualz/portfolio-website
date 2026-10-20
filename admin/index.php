<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

// Already signed in? Go straight through.
if (current_user() !== null) redirect(home_url());

$error = '';
if (!empty($_GET['expired'])) $error = 'A sessão expirou. Entre outra vez.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
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
?><!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>Entrar · topper.</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Jost:wght@600;700&display=swap">
<link rel="stylesheet" href="/admin/assets/app.css?v=<?= @filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
<div class="login-wrap">
  <form class="login" method="post" autocomplete="on">
    <div class="head">
      <div class="mark"></div>
      <h1>topper. admin</h1>
    </div>

    <?php if ($error): ?>
      <div class="flash err"><?= e($error) ?></div>
    <?php endif; ?>

    <?= csrf_field() ?>
    <div class="field">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required autofocus
             value="<?= e($_POST['email'] ?? '') ?>" autocomplete="username">
    </div>
    <div class="field">
      <label for="password">Palavra-passe</label>
      <input type="password" id="password" name="password" required
             autocomplete="current-password">
    </div>
    <button type="submit" class="btn">Entrar</button>
  </form>
</div>
</body>
</html>
