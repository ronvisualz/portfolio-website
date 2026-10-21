<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/leads.php';
require_once __DIR__ . '/inc/contacts.php';
$user = require_login();

// A minha conta: definições (como quer ser tratado, modo claro/escuro) e a
// palavra-passe. Não depende de permissões — toda a gente com sessão tem acesso.
// Superadmin e admins veem também os prazos das leads (§12.4), as cadências
// das relações e a lista de serviços dos Contactos (§13.3, §13.5), que valem
// para toda a plataforma.

const ACCOUNT_PASSWORD_MIN = 10;

// O papel confirma-se na base de dados (não só na sessão).
$st = $pdo->prepare('SELECT role FROM users WHERE id = ?');
$st->execute([(int)$user['id']]);
$isAdmin = role_is_admin((string)$st->fetchColumn());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'deadlines') {
    csrf_check();
    if (!$isAdmin) { http_response_code(403); exit('Sem permissão.'); }
    $set = $pdo->prepare(db_is_sqlite($pdo)
        ? 'INSERT OR REPLACE INTO settings (skey, svalue) VALUES (?, ?)'
        : 'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $bad = false;
    foreach (array_keys(LEAD_DEADLINE_DEFAULTS) as $k) {
        $h = (int)($_POST['h'][$k] ?? 0);
        if ($h < 1 || $h > 720) { $bad = true; continue; }
        $set->execute(['lead_deadline_' . $k, (string)$h]);
    }
    flash($bad ? 'Cada prazo tem de estar entre 1 e 720 horas; os outros foram guardados.' : 'Prazos das leads guardados para toda a plataforma.', $bad ? 'err' : 'ok');
    redirect('/admin/conta.php#prazos');
}

// Contactos (§13): cadência dos follow-ups por nível e a lista comum de serviços. Só admins.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['do'] ?? '', ['cadences', 'services'], true)) {
    csrf_check();
    if (!$isAdmin) { http_response_code(403); exit('Sem permissão.'); }
    $set = $pdo->prepare(db_is_sqlite($pdo)
        ? 'INSERT OR REPLACE INTO settings (skey, svalue) VALUES (?, ?)'
        : 'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    if ($_POST['do'] === 'cadences') {
        $bad = false;
        foreach (array_keys(REL_CADENCE_DEFAULTS) as $l) {
            $d = (int)($_POST['d'][$l] ?? 0);
            if ($d < 1 || $d > 365) { $bad = true; continue; }
            $set->execute(['rel_cadence_' . strtolower($l), (string)$d]);
        }
        flash($bad ? 'Cada cadência tem de estar entre 1 e 365 dias; as outras foram guardadas.' : 'Cadências das relações guardadas para toda a plataforma.', $bad ? 'err' : 'ok');
        redirect('/admin/conta.php#cadencias');
    }
    // Serviços: mudar nome, ordem e ativo; acrescentar. Não se apagam (as linhas de preço apontam para eles).
    $names = [];
    $rows = $pdo->query('SELECT * FROM services')->fetchAll();
    $err = '';
    foreach ($rows as $r) {
        $in = $_POST['sv'][$r['id']] ?? null;
        if (!is_array($in)) continue;
        $n = mb_substr(trim((string)($in['name'] ?? '')), 0, 80);
        if ($n === '') { $err = 'Um serviço não pode ficar sem nome.'; continue; }
        if (isset($names[mb_strtolower($n)])) { $err = '«' . $n . '» aparece duas vezes.'; continue; }
        $names[mb_strtolower($n)] = true;
        $pdo->prepare('UPDATE services SET name = ?, position = ?, is_active = ? WHERE id = ?')
            ->execute([$n, max(0, min(999, (int)($in['pos'] ?? 0))), !empty($in['on']) ? 1 : 0, $r['id']]);
    }
    $new = mb_substr(trim((string)($_POST['new_name'] ?? '')), 0, 80);
    if ($new !== '') {
        if (isset($names[mb_strtolower($new)])) $err = '«' . $new . '» já está na lista.';
        else $pdo->prepare('INSERT INTO services (name, position, is_active) VALUES (?, ?, 1)')
                 ->execute([$new, (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM services')->fetchColumn()]);
    }
    flash($err !== '' ? $err . ' O resto foi guardado.' : 'Lista de serviços guardada.', $err !== '' ? 'err' : 'ok');
    redirect('/admin/conta.php#servicos');
}

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

<?php if ($isAdmin): $dh = lead_deadline_hours($pdo); ?>
<h2 id="prazos">Prazos das leads</h2>
<form method="post" class="panel" style="max-width:720px">
  <?= csrf_field() ?><input type="hidden" name="do" value="deadlines">
  <p class="muted" style="margin-top:0">Horas seguidas (24/7, sem pausas) até uma lead em cada estado ficar em atraso. Valem para
    toda a plataforma e para as leads ativas que já existem. Só superadmin e admins podem mudar.</p>
  <div class="grid">
    <?php foreach (LEAD_STATE as $k => $label): ?>
      <div class="field"><label for="dl-<?= $k ?>"><?= e($label) ?></label>
        <input type="number" id="dl-<?= $k ?>" name="h[<?= $k ?>]" min="1" max="720" step="1" required value="<?= (int)$dh[$k] ?>" inputmode="numeric">
        <span class="muted" style="font-size:12px"><?= e(['novo' => 'Conta desde a criação.', 'sem_resposta' => 'Recomeça a cada chamada não atendida.', 'follow_up' => 'Recomeça a cada follow-up.'][$k] ?? '') ?>
          Por defeito <?= (int)LEAD_DEADLINE_DEFAULTS[$k] ?> h.</span></div>
    <?php endforeach; ?>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar prazos</button></div>
</form>
<?php endif; ?>

<?php if ($isAdmin):
    $cad = [];
    foreach (REL_CADENCE_DEFAULTS as $l => $d) $cad[$l] = max(1, (int)lead_setting($pdo, 'rel_cadence_' . strtolower($l), $d));
    $svcRows = services_list($pdo, false);
    $used = $pdo->query('SELECT service_id, COUNT(DISTINCT contact_id) FROM contact_services WHERE ended_at IS NULL AND service_id IS NOT NULL GROUP BY service_id')->fetchAll(PDO::FETCH_KEY_PAIR); ?>
<h2 id="cadencias">Relações: cadência dos follow-ups</h2>
<form method="post" class="panel" style="max-width:720px">
  <?= csrf_field() ?><input type="hidden" name="do" value="cadences">
  <p class="muted" style="margin-top:0">Dias entre toques em cada nível (o nível A, B ou C sai da formalidade, do potencial e do tipo de negócio).
    Valem para toda a plataforma; cada contacto pode ter uma exceção. Só superadmin e admins podem mudar.</p>
  <div class="grid">
    <?php foreach ($cad as $l => $d): ?>
      <div class="field"><label for="cad-<?= $l ?>">Nível <?= $l ?></label>
        <input type="number" id="cad-<?= $l ?>" name="d[<?= $l ?>]" min="1" max="365" step="1" required value="<?= $d ?>" inputmode="numeric">
        <span class="muted" style="font-size:12px">Por defeito <?= (int)REL_CADENCE_DEFAULTS[$l] ?> dias.</span></div>
    <?php endforeach; ?>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar cadências</button></div>
</form>

<h2 id="servicos">Serviços dos fornecedores e freelancers</h2>
<form method="post" class="panel" style="max-width:720px">
  <?= csrf_field() ?><input type="hidden" name="do" value="services">
  <p class="muted" style="margin-top:0">A lista comum de «Serviços e preços» (Contactos) e da calculadora da Orçamentação. Um serviço inativo
    deixa de aparecer para escolher; os preços que já o usam ficam. Só superadmin e admins podem mudar.</p>
  <div class="tablewrap"><table>
    <thead><tr><th>Serviço</th><th class="num">Ordem</th><th>Ativo</th><th class="num hide-sm">Em uso</th></tr></thead>
    <tbody>
    <?php foreach ($svcRows as $r): ?>
      <tr><td><input type="text" name="sv[<?= (int)$r['id'] ?>][name]" maxlength="80" required value="<?= e($r['name']) ?>" aria-label="Nome do serviço"></td>
        <td class="num"><input type="number" name="sv[<?= (int)$r['id'] ?>][pos]" min="0" max="999" value="<?= (int)$r['position'] ?>" style="width:5.5em" aria-label="Ordem de <?= e($r['name']) ?>"></td>
        <td><label class="check"><input type="checkbox" name="sv[<?= (int)$r['id'] ?>][on]" value="1" <?= (int)$r['is_active'] ? 'checked' : '' ?>> Ativo</label></td>
        <td class="num hide-sm"><?= (int)($used[$r['id']] ?? 0) ?: '<span class="muted">—</span>' ?></td></tr>
    <?php endforeach; ?>
      <tr><td colspan="4"><input type="text" name="new_name" maxlength="80" placeholder="+ Novo serviço (ex.: Motion Design)" aria-label="Novo serviço"></td></tr>
    </tbody>
  </table></div>
  <div class="actions"><button class="btn" type="submit">Guardar serviços</button></div>
</form>
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
