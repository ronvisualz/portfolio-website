<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
$user = require_login();

// A minha conta: definições (como quer ser tratado, modo claro/escuro) e a
// palavra-passe. Não depende de permissões — toda a gente com sessão tem acesso.

const ACCOUNT_PASSWORD_MIN = 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'settings') {
    csrf_check();
    $name  = trim(mb_substr((string)($_POST['display_name'] ?? ''), 0, 80));
    $theme = (string)($_POST['pref_theme'] ?? '');
    $theme = in_array($theme, ['', 'light', 'dark'], true) ? $theme : '';
    $pdo->prepare('UPDATE users SET display_name = ?, pref_theme = ? WHERE id = ?')->execute([$name, $theme, (int)$user['id']]);
    flash('Definições guardadas.');
    redirect('/admin/conta.php#definicoes');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([(int)$user['id']]);
    $hash = (string)$st->fetchColumn();
    $cur = (string)($_POST['current'] ?? '');
    $p1  = (string)($_POST['password'] ?? '');
    $p2  = (string)($_POST['password2'] ?? '');

    if (!password_verify($cur, $hash)) {
        flash('A palavra-passe atual não está certa.', 'err');
    } elseif (mb_strlen($p1) < ACCOUNT_PASSWORD_MIN) {
        flash('A nova palavra-passe precisa de pelo menos ' . ACCOUNT_PASSWORD_MIN . ' caracteres.', 'err');
    } elseif ($p1 !== $p2) {
        flash('As palavras-passe novas não coincidem.', 'err');
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($p1, PASSWORD_DEFAULT), (int)$user['id']]);
        session_regenerate_id(true);
        flash('Palavra-passe alterada.');
    }
    redirect('/admin/conta.php');
}

render_header('A minha conta', 'conta');
render_flashes();
?>
<h1>A minha conta</h1>
<div class="sub"><?= e($user['name']) ?> · <?= e($user['email']) ?> · <?= e(ROLES[$user['role']] ?? $user['role']) ?></div>

<?php $prefs = ui_prefs(); ?>
<h2 id="definicoes">Definições</h2>
<form method="post" class="panel" style="max-width:720px">
  <?= csrf_field() ?><input type="hidden" name="do" value="settings">
  <div class="grid">
    <div class="field"><label for="dn">Como quer ser tratado</label>
      <input type="text" id="dn" name="display_name" maxlength="80" value="<?= e($prefs['display_name']) ?>" placeholder="<?= e(explode(' ', trim($user['name']))[0]) ?>">
      <span class="muted" style="font-size:12px">Aparece na saudação do Dashboard. Vazio: o primeiro nome.</span></div>
    <div class="field"><label for="pt">Modo</label>
      <select id="pt" name="pref_theme">
        <option value="" <?= $prefs['pref_theme'] === '' ? 'selected' : '' ?>>Como o sistema</option>
        <option value="light" <?= $prefs['pref_theme'] === 'light' ? 'selected' : '' ?>>Claro</option>
        <option value="dark" <?= $prefs['pref_theme'] === 'dark' ? 'selected' : '' ?>>Escuro</option>
      </select>
      <span class="muted" style="font-size:12px">Também muda no botão <?= icon('moon') ?> da barra de topo; fica guardado na sua conta.</span></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar definições</button></div>
</form>

<h2>Mudar palavra-passe</h2>
<form method="post" autocomplete="off">
  <?= csrf_field() ?>
  <div class="grid">
    <div class="field"><label for="cur">Palavra-passe atual</label>
      <input type="password" id="cur" name="current" required autocomplete="current-password"></div>
    <div class="field"><label for="np">Nova palavra-passe</label>
      <input type="password" id="np" name="password" required minlength="<?= ACCOUNT_PASSWORD_MIN ?>" autocomplete="new-password"></div>
    <div class="field"><label for="np2">Repetir a nova</label>
      <input type="password" id="np2" name="password2" required minlength="<?= ACCOUNT_PASSWORD_MIN ?>" autocomplete="new-password"></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar</button></div>
</form>
<?php render_footer();
