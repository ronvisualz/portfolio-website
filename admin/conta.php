<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/leads.php';
require_once __DIR__ . '/inc/contacts.php';
$user = require_login();

// Perfil (§20.1): «O meu perfil» (§17.10) e as preferências pessoais de cada um — tema,
// menu recolhido/aberto, notificações (quais e por onde), modo Foco e a vista com que cada
// aba abre — e a palavra-passe. Toda a gente com sessão tem acesso; ninguém mexe nas
// preferências dos outros. As definições da empresa estão em Definições (só admins).

const ACCOUNT_PASSWORD_MIN = 10;

/** A vista com que cada aba abre (Perfil): aba → [nome, opções]. '' = a da aba. */
const PROFILE_START = [
    'leads'    => ['Leads', ['' => 'A da aba (operadores: «As minhas»)', 'minhas' => 'As minhas', 'ativas' => 'Todas']],
    'tarefas'  => ['Tarefas', ['' => 'A da aba (operadores: «Eu»)', 'me' => 'As minhas (Eu)', 'all' => 'Todas (Todos)']],
];

// O papel confirma-se na base de dados (não só na sessão).
$st = $pdo->prepare('SELECT role FROM users WHERE id = ?');
$st->execute([(int)$user['id']]);
$isAdmin = role_is_admin((string)$st->fetchColumn());

// O meu perfil (§17.10): foto, telemóvel, competências, horário, aniversário; férias e ausências.
$teamOn = team_ready();
if ($teamOn) {
    require_once __DIR__ . '/inc/team_view.php';
    require_once __DIR__ . '/inc/undo.php';
    $meRow = team_person($pdo, (int)$user['id']);
}
if ($teamOn && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['do'] ?? '', ['profile', 'photo_del', 'absence', 'absence_cancel'], true)) {
    csrf_check();
    $uid = (int)$user['id'];
    try {
        switch ($_POST['do']) {
        case 'profile':
            $phone = mb_substr(trim(preg_replace('/[^\d+ ()-]/', '', (string)($_POST['phone'] ?? ''))), 0, 40);
            $bd = trim((string)($_POST['birthday'] ?? ''));
            if ($bd !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd) || $bd > date('Y-m-d'))) throw new RuntimeException('Data de aniversário inválida.');
            $wh = team_work_parse($_POST);
            $photo = $meRow['photo'];
            if (!empty($_FILES['photo']['name'])) {
                $photo = team_photo_save($uid, $_FILES['photo']);
                team_photo_delete($meRow['photo']);
            }
            $pdo->prepare('UPDATE users SET phone = ?, birthday = ?, skills = ?, work_hours = ?, photo = ? WHERE id = ?')
                ->execute([$phone, $bd !== '' ? $bd : null, team_skills_parse((string)($_POST['skills'] ?? '')), $wh, $photo, $uid]);
            flash('Perfil guardado.', 'ok', undo_push('team', ['what' => 'user', 'self' => true, 'id' => $uid, 'msg' => 'Perfil reposto.',
                'row' => ['phone' => $meRow['phone'], 'birthday' => $meRow['birthday'], 'skills' => $meRow['skills'], 'work_hours' => $meRow['work_hours']]]));
            redirect('/admin/conta.php#perfil');
        case 'photo_del':
            team_photo_delete($meRow['photo']);
            $pdo->prepare('UPDATE users SET photo = NULL WHERE id = ?')->execute([$uid]);
            flash('Foto retirada: aparecem as iniciais.');
            redirect('/admin/conta.php#perfil');
        case 'absence':
            team_absence_request($pdo, $uid, (string)($_POST['kind'] ?? ''), (string)($_POST['from'] ?? ''), (string)($_POST['to'] ?? ''), (string)($_POST['note'] ?? ''));
            flash('Pedido enviado. Fica a bloquear a agenda quando um admin o aprovar.');
            redirect('/admin/conta.php#ausencias');
        case 'absence_cancel':
            $pdo->prepare("UPDATE absences SET status = 'cancelled', decided_at = ? WHERE id = ? AND user_id = ? AND status IN ('pending','approved') AND end_date >= ?")
                ->execute([now_php(), (int)($_POST['id'] ?? 0), $uid, date('Y-m-d')]);
            team_log($pdo, $uid, 'absence', 'Pedido cancelado pela própria pessoa');
            flash('Pedido cancelado.');
            redirect('/admin/conta.php#ausencias');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
        redirect('/admin/conta.php#' . (str_starts_with((string)$_POST['do'], 'absence') ? 'ausencias' : 'perfil'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'settings') {
    csrf_check();
    $name  = trim(mb_substr((string)($_POST['display_name'] ?? ''), 0, 80));
    $theme = (string)($_POST['pref_theme'] ?? '');
    $theme = in_array($theme, ['', 'light', 'dark'], true) ? $theme : '';
    $nav   = ($_POST['pref_nav'] ?? '') === 'closed' ? 'closed' : '';
    $pdo->prepare('UPDATE users SET display_name = ?, pref_theme = ?, pref_nav = ? WHERE id = ?')->execute([$name, $theme, $nav, (int)$user['id']]);
    if (db_has_column($pdo, 'users', 'prefs')) {
        $p = user_prefs($pdo, (int)$user['id']);
        foreach (NOTIF_CATS as $k => [, , $mail]) {
            $p['notif'][$k] = ['app' => !empty($_POST['n'][$k]['app']) ? 1 : 0, 'email' => $mail ? (!empty($_POST['n'][$k]['email']) ? 1 : 0) : 0];
        }
        $p['start'] = [];
        foreach (PROFILE_START as $tab => [, $opts]) {
            $v = (string)($_POST['start'][$tab] ?? '');
            if ($v !== '' && isset($opts[$v])) $p['start'][$tab] = $v;
        }
        $pdo->prepare('UPDATE users SET prefs = ? WHERE id = ?')->execute([json_encode($p, JSON_UNESCAPED_UNICODE), (int)$user['id']]);
    }
    flash('Preferências guardadas.');
    redirect('/admin/conta.php#preferencias');
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

render_header('Perfil', 'conta');
render_flashes();
?>
<h1>Perfil</h1>
<div class="sub"><?= e($user['name']) ?> · <?= e($user['email']) ?> · <?= e(ROLES[$user['role']] ?? $user['role']) ?></div>

<?php $prefs = ui_prefs(); ?>
<?php if ($teamOn):
    $myAbs = $pdo->prepare("SELECT * FROM absences WHERE user_id = ? AND (end_date >= ? OR created_at >= ?) ORDER BY start_date DESC LIMIT 20");
    $myAbs->execute([(int)$user['id'], date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('-60 days'))]);
    $myAbs = $myAbs->fetchAll();
    $f = team_focus_until($meRow); ?>
<h2 id="perfil">O meu perfil</h2>
<form method="post" class="panel profile" style="max-width:920px" enctype="multipart/form-data">
  <?= csrf_field() ?><input type="hidden" name="do" value="profile">
  <div class="prof-top">
    <?= team_avatar($meRow, 'xl', false) ?>
    <div class="field"><label for="ph-f">Foto <span class="muted">(JPG, PNG ou WebP até 5 MB; fica quadrada)</span></label>
      <input type="file" id="ph-f" name="photo" accept="image/jpeg,image/png,image/webp">
      <?php if ($meRow['photo']): ?><button class="lnk del small" type="submit" name="do" value="photo_del" formnovalidate>Retirar a foto</button><?php endif; ?></div>
  </div>
  <div class="grid">
    <div class="field"><label for="pf-ph">Telemóvel</label><input type="tel" id="pf-ph" name="phone" maxlength="40" value="<?= e((string)$meRow['phone']) ?>" placeholder="+351 9…"></div>
    <div class="field"><label for="pf-bd">Aniversário <span class="muted">(opcional; aparece em «Hoje na equipa»)</span></label><input type="date" id="pf-bd" name="birthday" value="<?= e((string)$meRow['birthday']) ?>"></div>
    <div class="field wide"><label for="pf-sk">Competências <span class="ii" tabindex="0" role="img" aria-label="Ajuda: separadas por vírgulas. Pesquisáveis no separador Equipa («quem sabe o quê»)." data-tip="Separadas por vírgulas. Pesquisáveis no separador Equipa («quem sabe o quê»).">i</span></label>
      <input type="text" id="pf-sk" name="skills" maxlength="500" value="<?= e((string)$meRow['skills']) ?>" placeholder="Houdini, Nuke, After Effects, color grading…"></div>
    <div class="field wide"><span class="lbl">Horário de trabalho</span><?= team_work_fields($meRow) ?>
      <span class="muted small">Fora do horário aparece indisponível. As tarefas com hora marcadas «Ocupado» bloqueiam a agenda.</span></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar perfil</button></div>
</form>

<h2 id="foco">Modo Foco</h2>
<div class="panel" style="max-width:720px">
  <p class="muted"><?= $f ? 'Em foco até <b>' . e(lisbon_fmt($f, 'H:i')) . '</b>.' : 'Silencia o chat e as notificações por um período; os pedidos de reunião ficam em espera até ao fim. O seu cartão mostra «Em foco até HH:MM».' ?></p>
  <div class="chips-wrap"><?php foreach (TEAM_FOCUS_OPTIONS as $m => $l): ?><button type="button" class="chip-btn" data-focus="<?= $m ?>"><?= e($l) ?></button><?php endforeach; ?>
    <button type="button" class="lnk" data-focus-off<?= $f ? '' : ' hidden' ?>>Desligar</button></div>
</div>

<h2 id="ausencias">Férias e ausências</h2>
<div class="panel" style="max-width:920px">
  <form method="post" class="grid"><?= csrf_field() ?><input type="hidden" name="do" value="absence">
    <div class="field"><label for="ab-k">Tipo</label><select id="ab-k" name="kind"><?= team_opts(ABSENCE_KINDS, 'ferias') ?></select></div>
    <div class="field"><label for="ab-f">De</label><input type="date" id="ab-f" name="from" required min="<?= date('Y-m-d') ?>"></div>
    <div class="field"><label for="ab-t">Até (inclusive)</label><input type="date" id="ab-t" name="to" required min="<?= date('Y-m-d') ?>"></div>
    <div class="field"><label for="ab-n">Nota (opcional)</label><input type="text" id="ab-n" name="note" maxlength="255"></div>
    <div class="field act"><button class="btn" type="submit"><?= icon('plane') ?> Pedir</button></div>
  </form>
  <p class="muted small">Pedido → aprovação por um admin → bloqueia a agenda nesses dias. Recebe a resposta por email.</p>
  <?php if ($myAbs): ?>
  <ul class="abslist">
  <?php foreach ($myAbs as $a): ?>
    <li><span class="ust ust-<?= e($a['status']) ?>"><?= e(ABSENCE_STATUS[$a['status']] ?? $a['status']) ?></span>
      <?= e(ABSENCE_KINDS[$a['kind']] ?? $a['kind']) ?> · <?= e(date('d/m', strtotime($a['start_date']))) ?> a <?= e(date('d/m/Y', strtotime($a['end_date']))) ?><?= $a['note'] !== '' ? ' <small class="muted">— ' . e($a['note']) . '</small>' : '' ?>
      <?php if (in_array($a['status'], ['pending', 'approved'], true) && $a['end_date'] >= date('Y-m-d')): ?>
        <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="absence_cancel"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="lnk del small" type="submit">Cancelar</button></form>
      <?php endif; ?></li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php $up = user_prefs($pdo, (int)$user['id']); ?>
<h2 id="preferencias">Preferências</h2>
<form method="post" class="panel prefs" style="max-width:920px">
  <?= csrf_field() ?><input type="hidden" name="do" value="settings">
  <div class="grid">
    <div class="field"><label for="dn">Como quer ser tratado</label>
      <input type="text" id="dn" name="display_name" maxlength="80" value="<?= e($prefs['display_name']) ?>" placeholder="<?= e(explode(' ', trim($user['name']))[0]) ?>">
      <span class="muted" style="font-size:12px">Aparece na saudação do Dashboard. Vazio: o primeiro nome.</span></div>
    <div class="field"><label for="pt">Tema</label>
      <select id="pt" name="pref_theme">
        <option value="" <?= $prefs['pref_theme'] === '' ? 'selected' : '' ?>>Como o sistema</option>
        <option value="light" <?= $prefs['pref_theme'] === 'light' ? 'selected' : '' ?>>Claro</option>
        <option value="dark" <?= $prefs['pref_theme'] === 'dark' ? 'selected' : '' ?>>Escuro</option>
      </select>
      <span class="muted" style="font-size:12px">Também muda no botão <?= icon('moon') ?> da barra de topo.</span></div>
    <div class="field"><label for="pn">Menu lateral</label>
      <select id="pn" name="pref_nav">
        <option value="" <?= $prefs['pref_nav'] === '' ? 'selected' : '' ?>>Aberto</option>
        <option value="closed" <?= $prefs['pref_nav'] === 'closed' ? 'selected' : '' ?>>Recolhido (só ícones)</option>
      </select>
      <span class="muted" style="font-size:12px">Também muda na seta do menu ou com a tecla [.</span></div>
    <?php foreach (PROFILE_START as $tab => [$tl, $opts]): $cur = (string)($up['start'][$tab] ?? ''); ?>
      <div class="field"><label for="st-<?= $tab ?>"><?= e($tl) ?> abre em</label>
        <select id="st-<?= $tab ?>" name="start[<?= $tab ?>]"><?php foreach ($opts as $k => $l): ?><option value="<?= e($k) ?>"<?= $cur === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php endforeach; ?>
  </div>
  <h3 class="prefs-h">Notificações <span class="ii" tabindex="0" role="img" aria-label="Ajuda: escolha o que aparece no sino e o que chega por email. Durante o modo Foco ficam em silêncio." data-tip="Escolha o que aparece no sino e o que chega por email. Durante o modo Foco ficam em silêncio.">i</span></h3>
  <div class="tablewrap"><table class="prefs-t">
    <thead><tr><th>O quê</th><th>Na plataforma</th><th>Por email</th></tr></thead>
    <tbody>
    <?php foreach (NOTIF_CATS as $k => [$nl, $nd, $mail]): $n = $up['notif'][$k] ?? []; ?>
      <tr><td><b><?= e($nl) ?></b><div class="muted small"><?= e($nd) ?></div></td>
        <td><label class="check"><input type="checkbox" name="n[<?= $k ?>][app]" value="1"<?= !isset($n['app']) || (int)$n['app'] ? ' checked' : '' ?>> No sino</label></td>
        <td><?php if ($mail): ?><label class="check"><input type="checkbox" name="n[<?= $k ?>][email]" value="1"<?= !isset($n['email']) || (int)$n['email'] ? ' checked' : '' ?>> Email</label><?php else: ?><span class="muted small">—</span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="actions"><button class="btn" type="submit">Guardar preferências</button></div>
</form>

<?php if ($isAdmin): ?>
<div class="panel prof-admin" style="max-width:720px">
  <p class="muted" style="margin:0"><?= icon('cog') ?> As definições da empresa (prazos das leads, funis, orçamentação, marketing, integrações, segurança…) estão em
    <a href="/admin/definicoes.php">Definições</a>. Utilizadores e permissões continuam na aba <a href="/admin/equipa.php?t=acessos">Equipa</a>.</p>
</div>
<?php endif; ?>

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
