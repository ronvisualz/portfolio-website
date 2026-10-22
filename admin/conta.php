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

// Orçamentação (§14): MSO, margem, horas de um dia, meta mensal e condições da proposta. Só admins.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'budget') {
    csrf_check();
    if (!$isAdmin) { http_response_code(403); exit('Sem permissão.'); }
    require_once __DIR__ . '/inc/budget.php';
    $set = $pdo->prepare(db_is_sqlite($pdo)
        ? 'INSERT OR REPLACE INTO settings (skey, svalue) VALUES (?, ?)'
        : 'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $lim = ['budget_mso_pct' => [0, 50], 'budget_margin_pct' => [0, 95], 'budget_day_hours' => [1, 24], 'budget_month_target' => [0, 10000000]];
    $bad = false;
    foreach ($lim as $k => [$min, $max]) {
        $v = budget_num($_POST[$k] ?? '', 10000000);
        if (trim((string)($_POST[$k] ?? '')) === '' || $v < $min || $v > $max) { $bad = true; continue; }
        $set->execute([$k, (string)$v]);
    }
    $set->execute(['budget_conditions', mb_substr(trim((string)($_POST['budget_conditions'] ?? '')), 0, 4000)]);
    flash($bad ? 'Um dos valores estava fora dos limites e ficou como estava; o resto foi guardado.' : 'Definições da Orçamentação guardadas.', $bad ? 'err' : 'ok');
    redirect('/admin/conta.php#orcamentacao');
}

// Pipeline (§19.3): as condições de pagamento por defeito das propostas. Só admins.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'pay_terms') {
    csrf_check();
    if (!$isAdmin) { http_response_code(403); exit('Sem permissão.'); }
    $set = $pdo->prepare(db_is_sqlite($pdo)
        ? 'INSERT OR REPLACE INTO settings (skey, svalue) VALUES (?, ?)'
        : 'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    foreach (['pay_terms_default', 'pay_terms_large', 'pay_terms_buddy'] as $k) $set->execute([$k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, 500)]);
    $lim = money_in($_POST['pay_terms_threshold'] ?? '');
    if ($lim > 0) $set->execute(['pay_terms_threshold', (string)$lim]);
    flash('Condições de pagamento guardadas. Valem para as propostas novas.');
    redirect('/admin/conta.php#funis');
}

// Marketing (§16): cadência alvo por semana e os pilares de conteúdo. Só admins.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['do'] ?? '', ['mkt_targets', 'mkt_pillars'], true)) {
    csrf_check();
    if (!$isAdmin) { http_response_code(403); exit('Sem permissão.'); }
    require_once __DIR__ . '/inc/marketing.php';
    if ($_POST['do'] === 'mkt_targets') {
        $bad = false;
        foreach (['mkt_target_ig_min', 'mkt_target_ig_max', 'mkt_target_li', 'mkt_target_x'] as $k) {
            $raw = trim((string)($_POST[$k] ?? ''));
            if (!ctype_digit($raw) || (int)$raw > 99) { $bad = true; continue; }
            mkt_setting_set($pdo, $k, (string)(int)$raw);
        }
        if ((int)mkt_setting($pdo, 'mkt_target_ig_max', '3') < (int)mkt_setting($pdo, 'mkt_target_ig_min', '2')) {
            mkt_setting_set($pdo, 'mkt_target_ig_max', mkt_setting($pdo, 'mkt_target_ig_min', '2'));
        }
        flash($bad ? 'Cada alvo é um número de 0 a 99; os outros foram guardados.' : 'Cadência alvo guardada.', $bad ? 'err' : 'ok');
        redirect('/admin/conta.php#marketing');
    }
    // Pilares: nome, nome curto, cor, ordem, ativo, employer branding; acrescentar. Não se apagam (as peças apontam para eles).
    $err = '';
    foreach ($pdo->query('SELECT * FROM mkt_pillars')->fetchAll() as $r) {
        $in = $_POST['pl'][$r['id']] ?? null;
        if (!is_array($in)) continue;
        $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80);
        if ($name === '') { $err = 'Cada pilar precisa de um nome.'; continue; }
        $color = isset(MKT_PILLAR_COLORS[$in['color'] ?? '']) ? (string)$in['color'] : 'neutral';
        $pdo->prepare('UPDATE mkt_pillars SET name = ?, short_name = ?, color = ?, kind = ?, description = ?, position = ?, is_active = ? WHERE id = ?')
            ->execute([$name, mb_substr(trim((string)($in['short'] ?? '')), 0, 24), $color, !empty($in['employer']) ? 'employer' : '',
                       mb_substr(trim((string)($in['desc'] ?? '')), 0, 500), max(0, min(999, (int)($in['pos'] ?? 0))), !empty($in['on']) ? 1 : 0, (int)$r['id']]);
    }
    $new = mb_substr(trim((string)($_POST['new_pillar'] ?? '')), 0, 80);
    if ($new !== '') {
        $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) FROM mkt_pillars')->fetchColumn() + 1;
        $pdo->prepare("INSERT INTO mkt_pillars (name, short_name, color, position) VALUES (?, ?, 'neutral', ?)")->execute([$new, mb_substr($new, 0, 24), $pos]);
    }
    flash($err !== '' ? $err . ' Os outros foram guardados.' : 'Pilares guardados.', $err !== '' ? 'err' : 'ok');
    redirect('/admin/conta.php#marketing');
}

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

<?php require_once __DIR__ . '/inc/budget.php';
    $bs = fn(string $k) => rtrim(rtrim(number_format(budget_setting($pdo, $k), 2, ',', ''), '0'), ','); ?>
<h2 id="orcamentacao">Orçamentação</h2>
<form method="post" class="panel" style="max-width:720px">
  <?= csrf_field() ?><input type="hidden" name="do" value="budget">
  <p class="muted" style="margin-top:0">Valores da calculadora para toda a plataforma. MSO, margem e horas de um dia entram nas folhas novas
    (as que já existem guardam os seus; a margem muda-se em cada folha, com histórico). A meta mensal vale já para todas. Só superadmin e admins podem mudar.</p>
  <div class="grid">
    <div class="field"><label for="b-mso">MSO (% dos custos diretos)</label>
      <input type="text" id="b-mso" name="budget_mso_pct" inputmode="decimal" maxlength="6" required value="<?= e($bs('budget_mso_pct')) ?>">
      <span class="muted" style="font-size:12px">Margem de segurança operacional. Por defeito 5%.</span></div>
    <div class="field"><label for="b-mg">Margem por defeito (%)</label>
      <input type="text" id="b-mg" name="budget_margin_pct" inputmode="decimal" maxlength="6" required value="<?= e($bs('budget_margin_pct')) ?>">
      <span class="muted" style="font-size:12px">Preço = base ÷ (1 − margem). Por defeito 65%; até 95%.</span></div>
    <div class="field"><label for="b-dh">1 dia de trabalho (horas)</label>
      <input type="text" id="b-dh" name="budget_day_hours" inputmode="decimal" maxlength="5" required value="<?= e($bs('budget_day_hours')) ?>">
      <span class="muted" style="font-size:12px">Converte dias em horas nas linhas. Por defeito 8 h.</span></div>
    <div class="field"><label for="b-mt">Meta de lucro por mês (€)</label>
      <input type="text" id="b-mt" name="budget_month_target" inputmode="decimal" maxlength="12" required value="<?= e($bs('budget_month_target')) ?>">
      <span class="muted" style="font-size:12px">O semáforo de cada folha: 🟢 na meta, 🟠 abaixo, 🔴 abaixo de 75%. Por defeito 8 000 €.</span></div>
    <div class="field wide"><label for="b-cond">Condições por defeito da proposta</label>
      <textarea id="b-cond" name="budget_conditions" rows="3" maxlength="4000"><?= e((string)lead_setting($pdo, 'budget_conditions', '')) ?></textarea></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar Orçamentação</button></div>
</form>
<?php endif; ?>

<?php if ($isAdmin && db_has_table($pdo, 'proposals')): $ps = fn(string $k) => e((string)setting($pdo, $k, '')); ?>
<h2 id="funis">Funis e automações</h2>
<div class="panel" style="max-width:720px">
  <p class="muted" style="margin-top:0">Os funis, as etapas, as perguntas do X-Ray, as rondas de revisão incluídas e as tarefas automáticas de cada etapa (com os gates).
    Só superadmin e admins mudam as automações.</p>
  <p><a class="btn" href="/admin/funis.php"><?= icon('pipeline') ?> Abrir Funis e automações</a></p>
</div>
<form method="post" class="panel" style="max-width:720px">
  <?= csrf_field() ?><input type="hidden" name="do" value="pay_terms">
  <h3 style="margin-top:0">Condições de pagamento por defeito (propostas)</h3>
  <div class="grid">
    <div class="field wide"><label for="pt-d">Até ao limite</label><input type="text" id="pt-d" name="pay_terms_default" maxlength="500" value="<?= $ps('pay_terms_default') ?>"></div>
    <div class="field"><label for="pt-t">Limite (€ s/ IVA)</label><input type="text" id="pt-t" name="pay_terms_threshold" inputmode="decimal" maxlength="12" value="<?= $ps('pay_terms_threshold') ?>"></div>
    <div class="field wide"><label for="pt-l">Acima do limite</label><input type="text" id="pt-l" name="pay_terms_large" maxlength="500" value="<?= $ps('pay_terms_large') ?>"></div>
    <div class="field wide"><label for="pt-b">Marketing Buddy (avença)</label><input type="text" id="pt-b" name="pay_terms_buddy" maxlength="500" value="<?= $ps('pay_terms_buddy') ?>"></div>
  </div>
  <p class="muted small">Sugeridas na folha de orçamento, em «Proposta». Por defeito: 50% adjudicação / 50% entrega; acima de 10 000 €: 40% / 40% / 20%; Marketing Buddy mensal adiantado, mínimo 3 meses.</p>
  <div class="actions"><button class="btn" type="submit">Guardar condições</button></div>
</form>
<?php endif; ?>

<?php if ($isAdmin && db_has_table($pdo, 'mkt_pillars')):
    require_once __DIR__ . '/inc/marketing.php';
    require_once __DIR__ . '/inc/ai.php';
    $tg = mkt_targets($pdo);
    [$ma, $mb, $mLabel] = ai_month_bounds();
    $spent = ai_month_spend_usd($pdo);
    $st = $pdo->prepare("SELECT COALESCE(SUM(cost_usd), 0), COUNT(*) FROM ai_usage WHERE purpose = 'mkt' AND created_at >= ? AND created_at < ?");
    $st->execute([$ma, $mb]);
    [$mktSpent, $mktCalls] = $st->fetch(PDO::FETCH_NUM);
    $log = $pdo->query('SELECT l.*, u.name AS who, p.title FROM mkt_ai_log l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN mkt_pieces p ON p.id = l.piece_id ORDER BY l.id DESC LIMIT 25')->fetchAll();
    $byUser = $pdo->prepare('SELECT u.name, COUNT(*) AS n, SUM(l.cost_usd) AS c FROM mkt_ai_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.created_at >= ? AND l.created_at < ? GROUP BY l.user_id, u.name ORDER BY c DESC');
    $byUser->execute([$ma, $mb]);
    $byUser = $byUser->fetchAll();
    $accounts = $pdo->query('SELECT * FROM mkt_accounts')->fetchAll(PDO::FETCH_UNIQUE);
    $usd = fn($v) => number_format((float)$v, 4, ',', ' ') . ' US$'; ?>
<h2 id="marketing">Marketing</h2>
<form method="post" class="panel" style="max-width:720px">
  <?= csrf_field() ?><input type="hidden" name="do" value="mkt_targets">
  <h3 style="margin-top:0">Cadência alvo por semana</h3>
  <p class="muted" style="margin-top:0">Publicações por semana em cada rede. No Calendário, as semanas abaixo do alvo ficam destacadas. Por defeito: Instagram 2–3 · LinkedIn 5 · X 10.</p>
  <div class="grid">
    <div class="field"><label for="tg-igmin">Instagram (mínimo)</label><input type="number" id="tg-igmin" name="mkt_target_ig_min" min="0" max="99" required value="<?= $tg['ig'][0] ?>" inputmode="numeric"></div>
    <div class="field"><label for="tg-igmax">Instagram (máximo)</label><input type="number" id="tg-igmax" name="mkt_target_ig_max" min="0" max="99" required value="<?= $tg['ig'][1] ?>" inputmode="numeric"></div>
    <div class="field"><label for="tg-li">LinkedIn</label><input type="number" id="tg-li" name="mkt_target_li" min="0" max="99" required value="<?= $tg['li'][0] ?>" inputmode="numeric"></div>
    <div class="field"><label for="tg-x">X</label><input type="number" id="tg-x" name="mkt_target_x" min="0" max="99" required value="<?= $tg['x'][0] ?>" inputmode="numeric"></div>
  </div>
  <div class="actions"><button class="btn" type="submit">Guardar cadência</button></div>
</form>

<form method="post" class="panel" style="max-width:920px">
  <?= csrf_field() ?><input type="hidden" name="do" value="mkt_pillars">
  <h3 style="margin-top:0">Pilares de conteúdo</h3>
  <p class="muted" style="margin-top:0">Os temas das publicações (cor no Calendário e equilíbrio do mês). Um pilar inativo deixa de aparecer para escolher; as peças que já o usam ficam.
    «Employer branding» mede também o talento gerado (candidaturas e contactos de talento).</p>
  <div class="tablewrap"><table>
    <thead><tr><th>Pilar</th><th class="hide-sm">Nome curto</th><th>Cor</th><th class="num">Ordem</th><th>Ativo</th><th class="hide-sm">Employer</th></tr></thead>
    <tbody>
    <?php foreach (mkt_pillars($pdo, false) as $pl): $pid = (int)$pl['id']; ?>
      <tr><td><input type="text" name="pl[<?= $pid ?>][name]" maxlength="80" required value="<?= e($pl['name']) ?>" aria-label="Nome do pilar">
            <input type="text" name="pl[<?= $pid ?>][desc]" maxlength="500" value="<?= e($pl['description']) ?>" placeholder="Descrição (a IA usa-a)" aria-label="Descrição de <?= e($pl['name']) ?>" style="margin-top:4px;font-size:12.5px"></td>
        <td class="hide-sm"><input type="text" name="pl[<?= $pid ?>][short]" maxlength="24" value="<?= e($pl['short_name']) ?>" style="width:9em" aria-label="Nome curto de <?= e($pl['name']) ?>"></td>
        <td><span class="mpil p-<?= e($pl['color']) ?>" aria-hidden="true">Aa</span>
          <select name="pl[<?= $pid ?>][color]" aria-label="Cor de <?= e($pl['name']) ?>"><?php foreach (MKT_PILLAR_COLORS as $ck => $cl): ?><option value="<?= $ck ?>" <?= $pl['color'] === $ck ? 'selected' : '' ?>><?= e($cl) ?></option><?php endforeach; ?></select></td>
        <td class="num"><input type="number" name="pl[<?= $pid ?>][pos]" min="0" max="999" value="<?= (int)$pl['position'] ?>" style="width:5em" aria-label="Ordem de <?= e($pl['name']) ?>"></td>
        <td><label class="check"><input type="checkbox" name="pl[<?= $pid ?>][on]" value="1" <?= (int)$pl['is_active'] ? 'checked' : '' ?>> Ativo</label></td>
        <td class="hide-sm"><label class="check"><input type="checkbox" name="pl[<?= $pid ?>][employer]" value="1" <?= $pl['kind'] === 'employer' ? 'checked' : '' ?>> Talento</label></td></tr>
    <?php endforeach; ?>
      <tr><td colspan="6"><input type="text" name="new_pillar" maxlength="80" placeholder="+ Novo pilar" aria-label="Novo pilar"></td></tr>
    </tbody>
  </table></div>
  <div class="actions"><button class="btn" type="submit">Guardar pilares</button></div>
</form>

<section class="panel" style="max-width:920px" id="ia">
  <h3 style="margin-top:0">IA do Estúdio: registo de uso</h3>
  <p class="muted" style="margin-top:0">Claude, da Anthropic. A chave fica só no servidor (<span class="mono">admin/inc/ai_config.php</span>, fora do Git) e nunca vai para o browser.
    O limite mensal é o mesmo do assistente do Drive. A IA só propõe: nunca publica nem grava sozinha.
    <?= ai_config() ? '' : '<br><b>Ainda sem chave neste servidor:</b> os botões da IA ficam desativados até ela existir.' ?></p>
  <div class="stats">
    <div class="stat"><div class="k">Marketing em <?= e($mLabel) ?></div><div class="v"><?= e($usd($mktSpent)) ?></div><div class="n"><?= (int)$mktCalls ?> pedidos</div></div>
    <div class="stat"><div class="k">Total da IA (mês)</div><div class="v"><?= e($usd($spent)) ?></div><div class="n">limite <?= e(ai_eur(ai_cap_usd())) ?> (contado como US$)</div></div>
  </div>
  <?php if ($byUser): ?><p class="small"><?php foreach ($byUser as $b): ?><span class="chip"><?= e($b['name'] ?? '—') ?> · <?= (int)$b['n'] ?> · <?= e($usd($b['c'])) ?></span> <?php endforeach; ?></p><?php endif; ?>
  <div class="tablewrap"><table>
    <thead><tr><th>Quando</th><th>Quem</th><th>Peça</th><th>Ação</th><th class="num">Tokens</th><th class="num">Custo</th></tr></thead>
    <tbody>
    <?php if (!$log): ?><tr><td colspan="6" class="empty">Ainda sem pedidos à IA.</td></tr><?php endif; ?>
    <?php foreach ($log as $l): ?>
      <tr><td class="mono"><?= e(lisbon_fmt(strtotime((string)$l['created_at']), 'd/m H:i')) ?></td><td><?= e($l['who'] ?? '—') ?></td>
        <td><?= $l['piece_id'] ? '<a href="/admin/marketing_peca.php?id=' . (int)$l['piece_id'] . '">' . e(shorten((string)$l['title'], 40)) . '</a>' : '<span class="muted">resumo do mês</span>' ?></td>
        <td><?= e(MKT_AI_ACTIONS[$l['action']][0] ?? ($l['action'] === 'summary' ? 'Resumo do mês' : $l['action'])) ?><?= $l['network'] ? ' · ' . e(MKT_NETWORKS[$l['network']][0] ?? '') : '' ?><?= (int)$l['ok'] ? '' : ' <span class="chip no">falhou</span>' ?></td>
        <td class="num mono"><?= (int)$l['input_tokens'] + (int)$l['output_tokens'] ?></td><td class="num mono"><?= e($usd($l['cost_usd'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="panel" style="max-width:720px">
  <h3 style="margin-top:0">Ligações às redes (fase 2)</h3>
  <p class="muted" style="margin-top:0">Por agora a publicação é assistida: lembrete a quem publica, legenda copiada num clique, link colado à mão e métricas registadas à mão.
    A fase 2 (publicar e ler métricas pelas APIs do Instagram/Meta, da página do LinkedIn e do X — este pago) fica preparada e liga-se aqui.</p>
  <ul class="mpubs"><?php foreach (MKT_NETWORKS as $n => [$label]): $acc = $accounts[$n] ?? null; ?>
    <li><?= mkt_net_chip($n) ?> <?= e($label) ?> · <span class="muted"><?= $acc && $acc['api_status'] === 'on' ? 'ligada' . ($acc['handle'] ? ' (' . e($acc['handle']) . ')' : '') : 'por ligar (fase 2)' ?></span></li>
  <?php endforeach; ?></ul>
</section>
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
