<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
$user = require_login();
require_page('equipa');

// Equipa (§17): quem é quem, quem está disponível e como falar com cada pessoa — sem
// microgestão (sem registo de horas, sem vigiar o que se faz). Separadores:
//   Equipa (todos) · Organograma (admins e chefes) · Utilizadores e acessos (admins:
//   contas, cargos, permissões) · Departamentos (admins, com subdepartamentos).
// Quem pode o quê decide-se aqui no servidor, pelo papel lido na base.

if (!team_ready()) {
    render_header('Equipa', 'equipa');
    echo '<h1>Equipa</h1><p class="muted">A base de dados ainda está a ser atualizada. Volte daqui a pouco.</p>';
    render_footer();
    exit;
}
require_once __DIR__ . '/inc/team_view.php';
require_once __DIR__ . '/inc/undo.php';
require_once __DIR__ . '/inc/interactions.php';
require_once __DIR__ . '/inc/leads.php';

const PASSWORD_MIN = 10;

$tabs  = team_tabs($pdo);
$tab   = isset($tabs[$_GET['t'] ?? '']) ? (string)$_GET['t'] : 'equipa';
$admin = team_is_admin($pdo);
$me    = (int)$user['id'];
$actor = $pdo->query('SELECT * FROM users WHERE id = ' . $me)->fetch();

function team_url(string $tab, array $q = []): string {
    $q = array_filter(($tab !== 'equipa' ? ['t' => $tab] : []) + $q, fn($x) => $x !== null && $x !== '');
    return '/admin/equipa.php' . ($q ? '?' . http_build_query($q) : '');
}
function user_row(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// ── ações (só admins) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    if (!$admin) { flash('Só superadmin e admins alteram a equipa.', 'err'); redirect(team_url('equipa')); }
    $back = team_url($tab);
    try {
        switch ($do) {

        // Contas (§17.8): criar = convite por email; nunca apagar, só desativar.
        case 'add_user':
            $_SESSION['team_form'] = $_POST;
            $in = team_user_input($pdo, $actor, null, $_POST);
            $r = $in['row'];
            $pos = (int)$pdo->query('SELECT COALESCE(MAX(position),0) FROM users')->fetchColumn() + 1;
            $pdo->prepare('INSERT INTO users (name, email, password_hash, role, department_id, reports_to, is_partner, color, hourly_cost, monthly_hours, work_hours, position, is_active, created_by, invite_pending, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,1,?)')
                ->execute([$r['name'], $r['email'], password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $r['role'], $r['department_id'], $r['reports_to'], $r['is_partner'],
                           $r['color'], $r['hourly_cost'], $r['monthly_hours'] ?? 168, $r['work_hours'], $pos, $me, now_php()]);
            $uid = (int)$pdo->lastInsertId();
            team_set_positions($pdo, $uid, $in['positions']);
            team_log($pdo, $uid, 'create', ROLES[$r['role']] . ($r['department_id'] ? ' · ' . team_dept_label(team_depts($pdo), $r['department_id']) : ''));
            team_send_invite($pdo, $uid, $me);
            team_checklist_start($pdo, $uid, 'entrada', ['conta']);
            unset($_SESSION['team_form']);
            flash('Conta criada. O convite para definir a palavra-passe seguiu para ' . $r['email'] . ' (vale ' . TEAM_INVITE_DAYS . ' dias).');
            redirect(team_url('acessos', ['u' => $uid]));

        case 'save_user':
        case 'resend_invite':
        case 'deactivate':
        case 'reactivate':
        case 'check':
            $t = user_row($pdo, (int)($_POST['id'] ?? 0));
            if (!$t || !can_manage_user($actor, $t)) throw new RuntimeException($t && $t['role'] === 'admin' ? 'Só o superadmin altera contas de admin.' : 'Não pode alterar esta conta.');
            $back = team_url('acessos', ['u' => (int)$t['id']]);
            if ($do === 'save_user') {
                $in = team_user_input($pdo, $actor, $t, $_POST);
                $r = $in['row'];
                $old = array_intersect_key($t, $r);
                $oldPos = $pdo->query('SELECT position_id FROM user_positions WHERE user_id = ' . (int)$t['id'])->fetchAll(PDO::FETCH_COLUMN);
                $pdo->prepare('UPDATE users SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($r))) . ' WHERE id = ?')->execute([...array_values($r), $t['id']]);
                team_set_positions($pdo, (int)$t['id'], $in['positions']);
                // Quem deixa um departamento deixa de o chefiar.
                $pdo->prepare('UPDATE departments SET head_user_id = NULL WHERE head_user_id = ? AND id <> COALESCE(?, 0) AND (parent_id IS NULL OR parent_id <> COALESCE(?, 0))')
                    ->execute([$t['id'], $r['department_id'], $r['department_id']]);
                $changes = [];
                foreach (['name' => 'nome', 'email' => 'email', 'role' => 'papel', 'department_id' => 'departamento', 'reports_to' => 'responde a', 'is_partner' => 'sócio', 'work_hours' => 'horário', 'hourly_cost' => 'custo/hora', 'monthly_hours' => 'horas/mês'] as $c => $l) {
                    if ((string)$old[$c] !== (string)$r[$c]) $changes[] = $l;
                }
                if (array_map('intval', $oldPos) != $in['positions']) $changes[] = 'cargos';
                if ($old['role'] !== $r['role']) team_log($pdo, (int)$t['id'], 'role', ROLES[$old['role']] . ' → ' . ROLES[$r['role']]);
                if ($changes) team_log($pdo, (int)$t['id'], 'edit', 'Alterado: ' . implode(', ', $changes));
                flash($changes ? 'Conta guardada.' : 'Sem alterações.', 'ok', $changes ? undo_push('team', ['what' => 'user', 'id' => (int)$t['id'], 'row' => $old, 'positions' => $oldPos, 'msg' => 'Alterações anuladas.']) : '');
                redirect($back);
            }
            if ($do === 'resend_invite') {
                team_send_invite($pdo, (int)$t['id'], $me);
                flash('Convite enviado outra vez para ' . $t['email'] . '.');
                redirect($back);
            }
            if ($do === 'deactivate') {
                if ((int)$t['id'] === $me) throw new RuntimeException('Não pode desativar a sua própria conta.');
                $work = team_open_work($pdo, $t);
                $to = (int)($_POST['reassign_to'] ?? 0);
                if (array_sum($work) > 0) {
                    $tu = $to ? user_row($pdo, $to) : null;
                    if (!$tu || !(int)$tu['is_active'] || $tu['deactivated_at'] || (int)$tu['id'] === (int)$t['id']) throw new RuntimeException('Escolha a quem passa o trabalho aberto.');
                    $moved = team_reassign($pdo, $t, $tu);
                    team_log($pdo, (int)$t['id'], 'reassign', 'Trabalho passado para ' . $tu['name'] . ': ' . implode(', ', array_map(fn($k, $n) => "$n $k", array_keys(array_filter($moved)), array_filter($moved))));
                } else {
                    $pdo->prepare('UPDATE departments SET head_user_id = NULL WHERE head_user_id = ?')->execute([$t['id']]);
                    $pdo->prepare('UPDATE users SET reports_to = ? WHERE reports_to = ?')->execute([$t['reports_to'], $t['id']]);
                }
                $pdo->prepare('UPDATE users SET is_active = 0, deactivated_at = ?, focus_until = NULL WHERE id = ?')->execute([now_php(), $t['id']]);
                $pdo->prepare("UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL")->execute([now_php(), $t['id']]);
                team_log($pdo, (int)$t['id'], 'off', 'Conta desativada');
                team_checklist_start($pdo, (int)$t['id'], 'saida', ['trabalho', 'acessos']);
                flash('Conta desativada: a pessoa deixa de entrar e o histórico mantém-se.');
                redirect($back);
            }
            if ($do === 'reactivate') {
                $pdo->prepare('UPDATE users SET is_active = 1, deactivated_at = NULL WHERE id = ?')->execute([$t['id']]);
                team_log($pdo, (int)$t['id'], 'on', 'Conta reativada');
                flash('Conta reativada. Se a pessoa não souber a palavra-passe, reenvie o convite.');
                redirect($back);
            }
            if ($do === 'check') {
                $kind = ($_POST['kind'] ?? '') === 'saida' ? 'saida' : 'entrada';
                team_checklist_set($pdo, (int)$t['id'], $kind, (string)($_POST['key'] ?? ''), !empty($_POST['done']));
                if (!empty($_POST['done'])) team_log($pdo, (int)$t['id'], 'check', (($kind === 'saida' ? TEAM_CHECK_OUT : TEAM_CHECK_IN)[$_POST['key']] ?? ''));
                redirect($back . '#checklist');
            }
            break;

        // Cargos (§17.2): títulos na empresa; não dão permissões.
        case 'add_pos':
        case 'save_pos':
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 80) throw new RuntimeException('Indique o nome do cargo (até 80 caracteres).');
            if ($do === 'add_pos') {
                $p = (int)$pdo->query('SELECT COALESCE(MAX(position),0) FROM positions')->fetchColumn() + 1;
                $pdo->prepare('INSERT INTO positions (name, position) VALUES (?, ?)')->execute([$name, $p]);
            } else {
                $pdo->prepare('UPDATE positions SET name = ? WHERE id = ?')->execute([$name, (int)$_POST['id']]);
            }
            flash('Cargo guardado.');
            redirect(team_url('acessos') . '#cargos');
        case 'del_pos':
            $id = (int)($_POST['id'] ?? 0);
            $n = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM user_positions WHERE position_id = $id) + (SELECT COUNT(*) FROM team_openings WHERE position_id = $id)")->fetchColumn();
            if ($n) throw new RuntimeException('Este cargo está atribuído ou tem uma posição por preencher: retire-o primeiro.');
            $pdo->prepare('DELETE FROM positions WHERE id = ?')->execute([$id]);
            flash('Cargo eliminado.');
            redirect(team_url('acessos') . '#cargos');

        // Posições por preencher (organograma, a tracejado).
        case 'add_opening':
            $pid = (int)($_POST['position_id'] ?? 0);
            if (!isset(team_positions($pdo)[$pid])) throw new RuntimeException('Escolha o cargo.');
            $dep = (int)($_POST['department_id'] ?? 0) ?: null;
            $rep = (int)($_POST['reports_to'] ?? 0) ?: null;
            $pdo->prepare('INSERT INTO team_openings (position_id, department_id, reports_to, note, created_at) VALUES (?,?,?,?,?)')
                ->execute([$pid, $dep, $rep, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 160), now_php()]);
            flash('Posição por preencher adicionada.', 'ok', undo_push('team', ['what' => 'opening', 'delete' => (int)$pdo->lastInsertId()]));
            redirect(team_url('organograma'));
        case 'del_opening':
            $st = $pdo->prepare('SELECT * FROM team_openings WHERE id = ?');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            if ($o = $st->fetch()) {
                $pdo->prepare('DELETE FROM team_openings WHERE id = ?')->execute([$o['id']]);
                flash('Posição retirada.', 'ok', undo_push('team', ['what' => 'opening', 'row' => array_filter($o, 'is_string', ARRAY_FILTER_USE_KEY)]));
            }
            redirect(team_url('organograma'));

        // Departamentos e subdepartamentos (§17.3).
        case 'add_dept':
        case 'save_dept':
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 80) throw new RuntimeException('Indique o nome do departamento.');
            $depts = team_depts($pdo);
            $parent = (int)($_POST['parent_id'] ?? 0) ?: null;
            $id = $do === 'save_dept' ? (int)($_POST['id'] ?? 0) : 0;
            if ($parent !== null && (!isset($depts[$parent]) || $parent === $id || ($id && (int)($depts[$parent]['parent_id'] ?? 0) === $id))) throw new RuntimeException('Departamento-mãe inválido.');
            $head = (int)($_POST['head_user_id'] ?? 0) ?: null;
            $h = $head ? user_row($pdo, $head) : null;
            if ($head && (!$h || !(int)$h['is_active'] || $h['deactivated_at'])) throw new RuntimeException('Escolha um responsável ativo.');
            if ($do === 'add_dept') {
                $p = (int)$pdo->query('SELECT COALESCE(MAX(position),0) FROM departments')->fetchColumn() + 1;
                $pdo->prepare('INSERT INTO departments (name, parent_id, position) VALUES (?, ?, ?)')->execute([$name, $parent, $p]);
                $id = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE departments SET name = ?, parent_id = ? WHERE id = ?')->execute([$name, $parent, $id]);
            }
            $pdo->prepare('UPDATE departments SET head_user_id = ? WHERE id = ?')->execute([$head, $id]);
            // O responsável passa a pertencer ao departamento (se ainda não tem nenhum) e um
            // colaborador passa a chefe de equipa. Admins continuam admins: nunca se desce o papel.
            if ($h) {
                if ($h['department_id'] === null) $pdo->prepare('UPDATE users SET department_id = ? WHERE id = ?')->execute([$id, $h['id']]);
                if ($h['role'] === 'member' && can_manage_user($actor, $h)) {
                    $pdo->prepare("UPDATE users SET role = 'lead' WHERE id = ?")->execute([$h['id']]);
                    team_log($pdo, (int)$h['id'], 'role', ROLES['member'] . ' → ' . ROLES['lead'] . ' (responsável de ' . $name . ')');
                }
            }
            flash('Departamento guardado.');
            redirect(team_url('departamentos'));
        case 'del_dept':
            $depts = team_depts($pdo);
            $id = (int)($_POST['id'] ?? 0);
            if (!isset($depts[$id])) redirect(team_url('departamentos'));
            $up = $depts[$id]['parent_id'] !== null ? (int)$depts[$id]['parent_id'] : null;
            $pdo->prepare('UPDATE departments SET parent_id = ? WHERE parent_id = ?')->execute([$up, $id]);
            $pdo->prepare('UPDATE users SET department_id = ? WHERE department_id = ?')->execute([$up, $id]);
            $pdo->prepare('UPDATE team_openings SET department_id = ? WHERE department_id = ?')->execute([$up, $id]);
            $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
            flash('Departamento eliminado. As pessoas e os subdepartamentos passaram para ' . ($up ? $depts[$up]['name'] : '«sem departamento»') . '.');
            redirect(team_url('departamentos'));

        // Permissões (§9.3): grelha papel × aba e exceções por pessoa.
        case 'save_perms':
        case 'save_user_perms':
            $isUser = $do === 'save_user_perms';
            $uid = $isUser ? (int)($_POST['user_id'] ?? 0) : 0;
            if ($isUser) {
                $t = user_row($pdo, $uid);
                if (!$t || role_is_admin($t['role']) || !can_manage_user($actor, $t)) throw new RuntimeException('Não pode alterar as permissões desta pessoa.');
            }
            $del = $pdo->prepare('DELETE FROM permissions WHERE role = ? AND user_id = ?');
            $ins = $pdo->prepare('INSERT INTO permissions (role, user_id, tab, level, scope) VALUES (?,?,?,?,?)');
            $pdo->beginTransaction();
            foreach ($isUser ? [''] : ['lead', 'member'] as $role) {
                $del->execute([$role, $uid]);
                $key = $isUser ? 'u' : $role;
                foreach (array_keys(TABS) as $tabKey) {
                    $lvl = $_POST['lvl'][$key][$tabKey] ?? '';
                    if ($lvl === '') continue;
                    $lvl = max(PERM_NONE, min(PERM_MANAGE, (int)$lvl));
                    $sc = (string)($_POST['scope'][$key][$tabKey] ?? '');
                    $sc = in_array($tabKey, SCOPED_TABS, true) && isset(SCOPES[$sc]) ? $sc : null;
                    $ins->execute([$role, $uid, $tabKey, $lvl, $sc]);
                }
            }
            $pdo->commit();
            if ($isUser) team_log($pdo, $uid, 'edit', 'Exceções de permissões alteradas');
            flash('Permissões guardadas.');
            redirect(team_url('acessos', $isUser ? ['pu' => $uid] : []) . '#permissoes');
        }
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash($e->getMessage(), 'err');
        redirect($back . ($do === 'add_user' ? (str_contains($back, '?') ? '&' : '?') . 'novo=1' : ''));
    }
    redirect($back);
}

// ── dados ───────────────────────────────────────────────────
$depts  = team_depts($pdo);
$people = team_people($pdo, true, $admin);   // sem custos para quem não é admin
$sub = ['equipa' => 'Quem é quem, quem está disponível e como falar com cada pessoa.', 'organograma' => 'A estrutura da empresa a partir de «responde a».',
        'acessos' => 'Contas, papéis, cargos e permissões. Desativar, nunca apagar.', 'departamentos' => 'Departamentos, subdepartamentos e responsáveis.'];

render_header('Equipa', 'equipa', '', [$tabs[$tab]]);
render_flashes();
?>
<div class="page-head">
  <div><h1>Equipa</h1><p class="sub"><?= e($sub[$tab]) ?></p></div>
  <div class="ph-acts">
    <?php if ($admin && $tab === 'acessos' && empty($_GET['u'])): ?>
      <button type="button" class="btn" data-new="#mdl-user" aria-keyshortcuts="N"><?= icon('plus') ?> Utilizador <span class="kbd">N</span></button>
    <?php endif; ?>
    <button type="button" class="btn ghost" data-new="#mdl-find"><?= icon('calendar') ?> Encontrar hora</button>
  </div>
</div>

<nav class="tabs" aria-label="Separadores">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="<?= e(team_url($k)) ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php
// ════════════════════════════════════════════════════════════
if ($tab === 'equipa'):
    $views = ['pessoas' => 'Pessoas', 'ausencias' => 'Férias e ausências'] + ($admin ? ['carga' => 'Carga da equipa'] : []);
    $view = isset($views[$_GET['v'] ?? '']) ? (string)$_GET['v'] : 'pessoas';
    $today = team_today($pdo, $people);
    $pendingAbs = $admin ? $pdo->query("SELECT a.*, u.name FROM absences a JOIN users u ON u.id = a.user_id WHERE a.status = 'pending' ORDER BY a.start_date")->fetchAll() : [];
    $first = fn(array $u) => explode(' ', trim((string)$u['name']))[0];
?>
<div class="tday" aria-label="Hoje na equipa">
  <b>Hoje na equipa</b>
  <?php $any = false;
  foreach ([['absent', 'plane'], ['meeting', 'video'], ['focus', 'headphones'], ['birthday', 'cake']] as [$k, $ic]):
      foreach ($today[$k] as [$u, $txt]): $any = true; ?>
        <span class="tchip t-<?= $k ?>" data-tip="<?= e($u['name']) ?>"><?= icon($ic) ?> <?= e($first($u)) ?> <?= e($k === 'absent' ? '· ' . mb_strtolower($txt) : $txt) ?></span>
  <?php endforeach; endforeach; ?>
  <?php if (!$today['absent']): ?><span class="tchip muted"><?= icon('plane') ?> ninguém ausente</span><?php endif; ?>
  <?php if (!$any && !$today['absent']): ?><span class="tchip muted">sem reuniões a decorrer</span><?php endif; ?>
</div>

<?= views_bar($views, $view, fn($k) => team_url('equipa', ['v' => $k !== 'pessoas' ? $k : null]), $admin && $pendingAbs ? ['ausencias' => count($pendingAbs)] : []) ?>

<?php if ($view === 'pessoas'):
    $topDepts = array_filter($depts, fn($d) => $d['parent_id'] === null);
?>
<div class="team-bar">
  <label class="sr" for="team-q">Procurar pessoa, cargo ou competência</label>
  <input type="search" id="team-q" class="team-q" placeholder="Procurar pessoa, cargo ou competência (ex.: Houdini, Nuke)…" data-team-q autocomplete="off">
  <div class="chips" role="group" aria-label="Departamento">
    <button type="button" class="chip-btn on" data-dept-f="">Todos</button>
    <?php foreach ($topDepts as $d): ?><button type="button" class="chip-btn" data-dept-f="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></button><?php endforeach; ?>
  </div>
</div>
<div class="pcards" data-pcards>
  <?php foreach ($people as $u):
      $top = $u['department_id'] && isset($depts[(int)$u['department_id']]) ? ((int)($depts[(int)$u['department_id']]['parent_id'] ?? 0) ?: (int)$u['department_id']) : 0;
      echo str_replace('<article class="pcard"', '<article class="pcard" data-dept="' . $top . '"', team_card_html($pdo, $u, $depts, $admin, $me));
  endforeach; ?>
</div>
<p class="empty-s muted" data-team-none hidden>Ninguém corresponde à pesquisa.</p>
<p class="muted small">Online/offline diz só se a pessoa está na plataforma, nunca o que está a fazer. Não há registo de horas.</p>

<?php elseif ($view === 'ausencias'):
    $from = (new DateTimeImmutable('now', team_tz()))->modify('monday this week');
    $days = 35;
    $to = $from->modify('+' . ($days - 1) . ' days');
    $abs = team_absences_between($pdo, $from->format('Y-m-d'), $to->format('Y-m-d'));
    $byUser = [];
    foreach ($abs as $a) $byUser[(int)$a['user_id']][] = $a;
?>
<?php if ($admin): ?>
<section class="box" aria-labelledby="abs-p">
  <h2 id="abs-p">Por aprovar <?= $pendingAbs ? '<span class="cnt">' . count($pendingAbs) . '</span>' : '' ?></h2>
  <?php if (!$pendingAbs): ?><p class="muted">Nenhum pedido por aprovar.</p><?php endif; ?>
  <?php foreach ($pendingAbs as $a): ?>
    <div class="absrow" data-abs="<?= (int)$a['id'] ?>">
      <?= team_avatar($people[(int)$a['user_id']] ?? ['id' => $a['user_id'], 'name' => $a['name'], 'color' => '', 'photo' => null], 'sm', false) ?>
      <span><b><?= e($a['name']) ?></b> · <?= e(ABSENCE_KINDS[$a['kind']] ?? $a['kind']) ?> · <?= e(date('d/m', strtotime($a['start_date']))) ?> a <?= e(date('d/m/Y', strtotime($a['end_date']))) ?>
        <?= $a['note'] !== '' ? '<small class="muted">— ' . e($a['note']) . '</small>' : '' ?></span>
      <span class="spacer"></span>
      <button type="button" class="btn sm" data-abs-decide="approved">Aprovar</button>
      <button type="button" class="btn ghost sm" data-abs-decide="rejected">Recusar</button>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<section aria-labelledby="abs-c">
  <div class="sec-h"><h2 id="abs-c">Calendário de ausências</h2><a class="btn ghost sm" href="/admin/conta.php#ausencias"><?= icon('plane') ?> Pedir férias ou ausência</a></div>
  <p class="muted small">Próximas 5 semanas. Só as aprovadas bloqueiam a agenda.</p>
  <?php if (!$byUser): ?><p class="muted">Ninguém ausente nas próximas semanas.</p><?php else: ?>
  <div class="tablewrap"><table class="abscal">
    <thead><tr><th>Pessoa</th>
      <?php for ($i = 0; $i < $days; $i++): $d = $from->modify("+$i days"); $wk = (int)$d->format('N') >= 6; ?>
        <th class="<?= $wk ? 'we' : '' ?><?= $d->format('Y-m-d') === date('Y-m-d') ? ' is-today' : '' ?>"><small><?= e(TEAM_DAYS[(int)$d->format('N')][0]) ?></small><?= $d->format('j') ?></th>
      <?php endfor; ?></tr></thead>
    <tbody>
    <?php foreach ($byUser as $uid => $list): $u = $people[$uid] ?? null; if (!$u) continue; ?>
      <tr><th><?= e($u['name']) ?></th>
      <?php for ($i = 0; $i < $days; $i++): $ds = $from->modify("+$i days")->format('Y-m-d'); $hit = null;
          foreach ($list as $a) if ($a['start_date'] <= $ds && $a['end_date'] >= $ds) $hit = $a; ?>
        <td class="<?= $hit ? 'ab k-' . e($hit['kind']) : '' ?>"<?= $hit ? ' data-tip="' . e(ABSENCE_KINDS[$hit['kind']] ?? '') . '"' : '' ?>></td>
      <?php endfor; ?></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <p class="avl"><?php foreach (ABSENCE_KINDS as $k => $l): ?><i class="ab k-<?= $k ?>"></i><?= e($l) ?> <?php endforeach; ?></p>
  <?php endif; ?>
</section>

<?php else: // carga (admins)
    $w = (string)($_GET['w'] ?? '');
    $monday = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $w) ? new DateTimeImmutable($w, team_tz()) : new DateTimeImmutable('now', team_tz()))->modify('monday this week');
    $load = team_load($pdo, $people, $monday->format('Y-m-d'));
    $fmtH = fn(float $h) => rtrim(rtrim(number_format($h, 1, ',', ''), '0'), ',') . ' h';
?>
<div class="sec-h">
  <h2>Carga da semana</h2>
  <div class="avail-nav">
    <a class="ibtn" href="<?= e(team_url('equipa', ['v' => 'carga', 'w' => $monday->modify('-7 days')->format('Y-m-d')])) ?>" aria-label="Semana anterior" data-tip><?= icon('chev-left') ?></a>
    <b><?= e($monday->format('d/m')) ?> – <?= e($monday->modify('+6 days')->format('d/m')) ?></b>
    <a class="ibtn" href="<?= e(team_url('equipa', ['v' => 'carga', 'w' => $monday->modify('+7 days')->format('Y-m-d')])) ?>" aria-label="Semana seguinte" data-tip><?= icon('chev-right') ?></a>
  </div>
</div>
<div class="loadlist">
  <?php foreach ($people as $id => $u): $l = $load[$id]; ?>
    <div class="load l-<?= $l['level'] ?>">
      <span class="ln"><?= team_avatar($u, 'sm', false) ?> <?= e($u['name']) ?></span>
      <span class="lb" role="img" aria-label="<?= (int)round($l['pct']) ?>% ocupado"><i style="width:<?= min(100, (int)round($l['pct'])) ?>%"></i></span>
      <span class="lp mono"><?= (int)round($l['pct']) ?>%</span>
      <span class="lh muted small"><?= e($fmtH($l['busy'])) ?> de <?= e($fmtH($l['avail'])) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<p class="muted small">Horas «Ocupado» (tarefas e reuniões com hora) vs horas disponíveis da semana (horário menos ausências).
  🟢 menos de 80% · 🟠 80–100% · 🔴 acima de 100%. Não é registo de horas: serve para distribuir trabalho.</p>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
elseif ($tab === 'organograma'):
    $positions = team_positions($pdo);
?>
<?php if ($admin): ?><p class="muted small">Arraste uma pessoa para cima de outra para mudar a quem responde (ou para a faixa «Topo»). Clique para ver a disponibilidade.</p>
  <div class="org-top" data-org-root>Topo — largue aqui para não responder a ninguém</div><?php endif; ?>
<?= team_org_html($pdo, $people, $depts, $admin) ?>
<p class="avl"><span class="onode-k">sócio</span> marcado · <span class="onode-k open">a tracejado</span> por preencher</p>
<?php if ($admin):
    $openings = $pdo->query('SELECT o.*, p.name AS pos FROM team_openings o LEFT JOIN positions p ON p.id = o.position_id ORDER BY o.id')->fetchAll(); ?>
<section class="box" aria-labelledby="org-open">
  <h2 id="org-open">Posições por preencher</h2>
  <?php foreach ($openings as $o): ?>
    <form method="post" class="openrow"><?= csrf_field() ?><input type="hidden" name="do" value="del_opening"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
      <span><b><?= e($o['pos'] ?: '—') ?></b> <small class="muted"><?= e(team_dept_label($depts, $o['department_id'] ? (int)$o['department_id'] : null)) ?><?= $o['reports_to'] && isset($people[(int)$o['reports_to']]) ? ' · responde a ' . e($people[(int)$o['reports_to']]['name']) : '' ?><?= $o['note'] !== '' ? ' · ' . e($o['note']) : '' ?></small></span>
      <button class="lnk del" type="submit">Retirar</button></form>
  <?php endforeach; ?>
  <form method="post" class="grid openform"><?= csrf_field() ?><input type="hidden" name="do" value="add_opening">
    <div class="field"><label for="op-pos">Cargo</label><select id="op-pos" name="position_id" required><?= team_opts(array_map(fn($p) => $p['name'], $positions), '', 'Escolher…') ?></select></div>
    <div class="field"><label for="op-dep">Departamento</label><select id="op-dep" name="department_id"><option value="">—</option><?php foreach (team_dept_tree($depts) as $n): ?><option value="<?= (int)$n['d']['id'] ?>"><?= str_repeat('— ', $n['lvl']) . e($n['d']['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="op-rep">Responde a</label><select id="op-rep" name="reports_to"><?= team_opts(array_map(fn($p) => $p['name'], $people), '', '—') ?></select></div>
    <div class="field"><label for="op-note">Nota</label><input type="text" id="op-note" name="note" maxlength="160" placeholder="Ex.: a contratar em 2027"></div>
    <div class="field act"><button class="btn" type="submit"><?= icon('plus') ?> Adicionar</button></div>
  </form>
</section>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
elseif ($tab === 'acessos'):
    $uSel = (int)($_GET['u'] ?? 0);
    $all = team_people($pdo, false, true);
    $tu = $uSel ? ($all[$uSel] ?? null) : null;
    if ($tu):
        $editable = can_manage_user($actor, $tu);
        $off = !(int)$tu['is_active'] || $tu['deactivated_at'];
        $work = team_open_work($pdo, $tu);
        if (!$off) team_checklist_auto($pdo, $tu);
        $ckIn = team_checklist($pdo, (int)$tu['id'], 'entrada');
        $ckOut = team_checklist($pdo, (int)$tu['id'], 'saida');
        $p = team_presence($tu);
?>
<p class="back"><a class="lnk" href="<?= e(team_url('acessos')) ?>"><?= icon('chev-left') ?> Utilizadores e acessos</a></p>
<div class="uhead">
  <?= team_avatar($tu, 'xl') ?>
  <div><h2><?= e($tu['name']) ?> <?= $tu['is_partner'] ? '<span class="socio">Sócio</span>' : '' ?></h2>
    <p class="muted"><?= e(ROLES[$tu['role']] ?? $tu['role']) ?><?= team_positions_label($tu) !== '' ? ' · ' . e(team_positions_label($tu)) : '' ?> · <?= e($tu['email']) ?></p>
    <p class="ustate">
      <?php if ($off): ?><span class="ust ust-off">Desativada</span>
      <?php elseif ($tu['invite_pending']): ?><span class="ust ust-inv">Convite pendente</span> <small class="muted">enviado <?= $tu['invited_at'] ? e(team_ago(strtotime($tu['invited_at']))) : '' ?></small>
      <?php else: ?><span class="ust ust-on">Ativa</span> <small class="muted" data-plabel="<?= (int)$tu['id'] ?>"><?= e($p['label']) ?></small><?php endif; ?></p>
  </div>
</div>
<?php if (!$editable): ?>
  <p class="flash info"><?= $tu['role'] === 'superadmin' ? 'A conta de superadmin não se altera aqui.' : 'Só o superadmin altera contas de admin.' ?></p>
<?php endif; ?>

<div class="ucols">
<div>
<?php if ($editable && !$off): ?>
<form method="post" class="box" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="do" value="save_user"><input type="hidden" name="id" value="<?= (int)$tu['id'] ?>">
  <h3>Conta</h3>
  <?= team_user_fields($pdo, $tu, $actor, 'eu') ?>
  <div class="actions"><button class="btn" type="submit">Gravar</button></div>
</form>
<?php endif; ?>

<section class="box" aria-labelledby="perm-u">
  <h3 id="perm-u">Permissões</h3>
  <?php if (role_is_admin($tu['role'])): ?><p class="muted">Superadmin e admins têm sempre acesso total.</p>
  <?php else: ?><p class="muted small">Seguem a grelha do papel (<?= e(ROLES[$tu['role']]) ?>). <a class="lnk" href="<?= e(team_url('acessos', ['pu' => $tu['id']])) ?>#permissoes">Exceções para esta pessoa</a></p><?php endif; ?>
</section>

<?php if ($editable): ?>
<section class="box" aria-labelledby="u-off">
  <h3 id="u-off"><?= $off ? 'Reativar' : 'Desativar' ?></h3>
  <?php if ($off): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="reactivate"><input type="hidden" name="id" value="<?= (int)$tu['id'] ?>">
      <p class="muted">Desativada <?= $tu['deactivated_at'] ? e(team_ago(strtotime($tu['deactivated_at']))) : '' ?>. O histórico manteve-se.</p>
      <button class="btn ghost" type="submit">Reativar conta</button></form>
  <?php elseif ((int)$tu['id'] === $me): ?>
    <p class="muted">Não pode desativar a sua própria conta.</p>
  <?php else: ?>
    <form method="post" data-confirm-off><?= csrf_field() ?><input type="hidden" name="do" value="deactivate"><input type="hidden" name="id" value="<?= (int)$tu['id'] ?>">
      <p class="muted">Desativar, nunca apagar: a pessoa deixa de entrar (sai de todas as sessões) e o histórico fica.</p>
      <?php if (array_sum($work)): ?>
        <p>Trabalho aberto: <?= e(implode(' · ', array_map(fn($k, $n) => $n . ' ' . $k, array_keys(array_filter($work)), array_filter($work)))) ?></p>
        <div class="field"><label for="re-to">Passar para</label><select id="re-to" name="reassign_to" required><?= team_opts(array_map(fn($x) => $x['name'], array_filter($people, fn($x) => (int)$x['id'] !== (int)$tu['id'])), '', 'Escolher pessoa…') ?></select></div>
      <?php else: ?><p class="muted small">Sem trabalho aberto para reatribuir.</p><?php endif; ?>
      <div class="actions"><button class="btn danger" type="submit">Desativar <?= e(explode(' ', $tu['name'])[0]) ?></button></div>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
</div>

<div>
<?php if ($editable && !$off && $tu['invite_pending']): ?>
<section class="box">
  <h3>Convite</h3>
  <p class="muted small">A pessoa ainda não definiu a palavra-passe. O link vale <?= TEAM_INVITE_DAYS ?> dias.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="resend_invite"><input type="hidden" name="id" value="<?= (int)$tu['id'] ?>">
    <button class="btn ghost sm" type="submit"><?= icon('mail') ?> Reenviar convite</button></form>
</section>
<?php endif; ?>
<?php foreach ([['entrada', $ckIn, 'Checklist de entrada'], ['saida', $ckOut, 'Checklist de saída']] as [$kind, $ck, $title]): if (!$ck) continue;
    $done = count(array_filter($ck['items'], fn($i) => !empty($i['done']))); ?>
<section class="box" id="checklist" aria-labelledby="ck-<?= $kind ?>">
  <h3 id="ck-<?= $kind ?>"><?= e($title) ?> <span class="muted small"><?= $done ?>/<?= count($ck['items']) ?></span></h3>
  <ul class="cklist">
  <?php foreach ($ck['items'] as $i): ?>
    <li><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="check"><input type="hidden" name="id" value="<?= (int)$tu['id'] ?>">
      <input type="hidden" name="kind" value="<?= $kind ?>"><input type="hidden" name="key" value="<?= e($i['k']) ?>"><input type="hidden" name="done" value="<?= empty($i['done']) ? 1 : '' ?>">
      <button type="submit" class="ckb<?= !empty($i['done']) ? ' on' : '' ?>"<?= $editable ? '' : ' disabled' ?> aria-pressed="<?= !empty($i['done']) ? 'true' : 'false' ?>"><span class="box-c"><?= !empty($i['done']) ? icon('check') : '' ?></span><?= e($i['t']) ?></button>
      <?php if ($i['k'] === 'drive' && empty($i['done'])): ?><a class="lnk small" href="/admin/drive.php">Abrir o Drive</a><?php endif; ?>
      <?php if ($i['k'] === 'apres' && empty($i['done']) && !$off): ?><button type="button" class="lnk small" data-chat-with="<?= (int)$tu['id'] ?>">Dar as boas-vindas no chat</button><?php endif; ?>
    </form></li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endforeach; ?>
<section class="box" aria-labelledby="u-hist">
  <h3 id="u-hist">Histórico</h3>
  <?= team_history_html($pdo, (int)$tu['id']) ?>
</section>
</div>
</div>

<?php else: // lista de contas, cargos e permissões
    $f = (string)($_GET['f'] ?? 'ativas');
    $filters = ['ativas' => 'Ativas', 'convite' => 'Convite pendente', 'desativadas' => 'Desativadas', 'todas' => 'Todas'];
    if (!isset($filters[$f])) $f = 'ativas';
    $isOff = fn($u) => !(int)$u['is_active'] || $u['deactivated_at'];
    $counts = ['ativas' => 0, 'convite' => 0, 'desativadas' => 0, 'todas' => count($all)];
    foreach ($all as $u) { if ($isOff($u)) $counts['desativadas']++; else { $counts['ativas']++; if ($u['invite_pending']) $counts['convite']++; } }
    $rows = array_filter($all, fn($u) => $f === 'todas' || ($f === 'desativadas' ? $isOff($u) : (!$isOff($u) && ($f !== 'convite' || $u['invite_pending']))));
    $old = $_SESSION['team_form'] ?? null; unset($_SESSION['team_form']);
?>
<?= views_bar($filters, $f, fn($k) => team_url('acessos', ['f' => $k !== 'ativas' ? $k : null]), $counts) ?>
<div class="tablewrap">
<table class="utable">
  <thead><tr><th>Nome</th><th>Papel</th><th class="hide-sm">Cargos</th><th class="hide-sm">Departamento</th><th>Estado</th>
    <th class="num hide-sm" data-tip="Só admins veem">Custo/hora</th><th class="num hide-sm" data-tip="Só admins veem">Horas/mês</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $u): $off = $isOff($u); ?>
    <tr data-href="<?= e(team_url('acessos', ['u' => $u['id']])) ?>" class="<?= $off ? 'muted' : '' ?>">
      <td><a class="ulink" href="<?= e(team_url('acessos', ['u' => $u['id']])) ?>"><?= team_avatar($u, 'sm') ?> <b><?= e($u['name']) ?></b></a>
        <?= $u['is_partner'] ? '<span class="socio-s">sócio</span>' : '' ?><br><small class="muted"><?= e($u['email']) ?></small></td>
      <td><?= e(ROLES[$u['role']] ?? $u['role']) ?></td>
      <td class="hide-sm"><?= e(team_positions_label($u) ?: '—') ?></td>
      <td class="hide-sm"><?= e(team_dept_label($depts, $u['department_id'] !== null ? (int)$u['department_id'] : null) ?: '—') ?></td>
      <td><?= $off ? '<span class="ust ust-off">Desativada</span>' : ($u['invite_pending'] ? '<span class="ust ust-inv">Convite pendente</span>' : '<span class="ust ust-on">Ativa</span>') ?></td>
      <td class="num hide-sm"><?= $u['hourly_cost'] !== null ? e(number_format((float)$u['hourly_cost'], 2, ',', ' ')) . ' €' : '<span class="muted">—</span>' ?></td>
      <td class="num hide-sm"><?= $u['monthly_hours'] !== null ? e(rtrim(rtrim(number_format((float)$u['monthly_hours'], 1, ',', ''), '0'), ',')) . ' h' : '<span class="muted">—</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nada aqui.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
<p class="muted small">Papel = o que pode fazer · Cargo = título na empresa (não dá permissões) · Sócio = marca. Só o superadmin mexe em contas de admin. A conta de superadmin é só <?= e(SUPERADMIN_EMAIL) ?>.</p>

<section class="box" id="cargos" aria-labelledby="pos-h">
  <h2 id="pos-h">Cargos</h2>
  <p class="muted small">Títulos na empresa. Uma pessoa pode ter vários; um cargo pode ficar por preencher (Organograma).</p>
  <?php $used = [];
  foreach ($pdo->query('SELECT position_id, COUNT(*) n FROM user_positions GROUP BY position_id')->fetchAll() as $r) $used[(int)$r['position_id']] = (int)$r['n']; ?>
  <div class="poslist">
  <?php foreach (team_positions($pdo) as $id => $p): ?>
    <form method="post" class="posrow"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <label class="sr" for="pos-<?= $id ?>">Cargo</label><input type="text" id="pos-<?= $id ?>" name="name" value="<?= e($p['name']) ?>" maxlength="80">
      <small class="muted"><?= $used[$id] ?? 0 ?> pessoa(s)</small>
      <button class="lnk" type="submit" name="do" value="save_pos">Gravar</button>
      <?php if (empty($used[$id])): ?><button class="lnk del" type="submit" name="do" value="del_pos">Eliminar</button><?php endif; ?>
    </form>
  <?php endforeach; ?>
  </div>
  <form method="post" class="posrow add"><?= csrf_field() ?><input type="hidden" name="do" value="add_pos">
    <label class="sr" for="pos-new">Novo cargo</label><input type="text" id="pos-new" name="name" maxlength="80" placeholder="Novo cargo (ex.: Colorista)" required>
    <button class="btn sm" type="submit"><?= icon('plus') ?> Adicionar</button></form>
</section>

<?php // ── permissões (§9.3) ──
    $levels = [PERM_NONE => 'Sem acesso', PERM_VIEW => 'Ver', PERM_EDIT => 'Editar', PERM_MANAGE => 'Gerir'];
    $roleGrid = [];
    foreach (['lead', 'member'] as $r) $roleGrid[$r] = perm_grid($pdo, ['id' => 0, 'role' => $r]);
    $pu = (int)($_GET['pu'] ?? 0);
    $permPeople = array_filter($people, fn($u) => !role_is_admin($u['role']));
    $selU = $permPeople[$pu] ?? null;
    $over = [];
    if ($selU) {
        $st = $pdo->prepare("SELECT tab, level, scope FROM permissions WHERE role = '' AND user_id = ?");
        $st->execute([$pu]);
        foreach ($st->fetchAll() as $r) $over[$r['tab']] = $r;
    }
    $lvlSelect = function (string $key, string $t, $cur, bool $inherit) use ($levels) {
        $h = '<select name="lvl[' . e($key) . '][' . e($t) . ']" aria-label="Nível">' . ($inherit ? '<option value="">Como o papel</option>' : '');
        foreach ($levels as $n => $l) $h .= '<option value="' . $n . '"' . ($cur !== null && (int)$cur === $n ? ' selected' : '') . '>' . $l . '</option>';
        return $h . '</select>';
    };
    $scopeSelect = function (string $key, string $t, $cur, bool $inherit) {
        if (!in_array($t, SCOPED_TABS, true)) return '';
        $h = ' <select name="scope[' . e($key) . '][' . e($t) . ']" aria-label="Âmbito">' . ($inherit ? '<option value="">—</option>' : '');
        foreach (SCOPES as $k => $l) $h .= '<option value="' . $k . '"' . ($cur === $k ? ' selected' : '') . '>' . $l . '</option>';
        return $h . '</select>';
    };
?>
<section class="box" id="permissoes" aria-labelledby="perm-h">
  <h2 id="perm-h">Permissões</h2>
  <p class="muted small">Superadmin e admins têm sempre acesso total. Aqui ajusta-se o que chefes de equipa e colaboradores podem fazer em cada aba.
    <b>Ver</b> só consulta · <b>Editar</b> cria e altera · <b>Gerir</b> também apaga e configura. Em Leads, Pipeline, Negócios e Tarefas escolhe-se ainda que registos se veem. A aba Equipa é sempre visível (no mínimo «Ver»).</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save_perms">
    <div class="tablewrap perm"><table>
      <thead><tr><th>Aba</th><th><?= e(ROLES['lead']) ?></th><th><?= e(ROLES['member']) ?></th></tr></thead>
      <tbody>
      <?php foreach (TABS as $t => $label): ?>
        <tr><td class="b"><?= e($label) ?></td>
          <?php foreach (['lead', 'member'] as $r): ?><td class="nowrap"><?= $lvlSelect($r, $t, $roleGrid[$r][$t]['level'], false) ?><?= $scopeSelect($r, $t, $roleGrid[$r][$t]['scope'], false) ?></td><?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <div class="actions"><button class="btn" type="submit">Guardar permissões</button></div>
  </form>

  <h3 id="excecoes">Exceções por pessoa</h3>
  <form class="bar" method="get"><input type="hidden" name="t" value="acessos">
    <select name="pu" onchange="this.form.submit()" aria-label="Pessoa"><option value="">Escolher pessoa…</option>
      <?php foreach ($permPeople as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $pu === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?> (<?= e(ROLES[$p['role']] ?? '') ?>)</option><?php endforeach; ?>
    </select><noscript><button class="btn sm" type="submit">Abrir</button></noscript></form>
  <?php if ($selU): $base = $roleGrid[$selU['role']] ?? perm_defaults($selU['role']); ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="save_user_perms"><input type="hidden" name="user_id" value="<?= (int)$selU['id'] ?>">
    <div class="tablewrap perm"><table>
      <thead><tr><th>Aba</th><th>Pelo papel</th><th>Exceção para <?= e($selU['name']) ?></th></tr></thead>
      <tbody>
      <?php foreach (TABS as $t => $label): ?>
        <tr><td class="b"><?= e($label) ?></td><td class="muted"><?= e($levels[$base[$t]['level']]) ?><?= $base[$t]['scope'] ? ' · ' . e(SCOPES[$base[$t]['scope']]) : '' ?></td>
          <td class="nowrap"><?= $lvlSelect('u', $t, $over[$t]['level'] ?? null, true) ?><?= $scopeSelect('u', $t, $over[$t]['scope'] ?? null, true) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <div class="actions"><button class="btn" type="submit">Guardar exceções</button></div>
  </form>
  <?php endif; ?>
</section>

<?php // «+ Utilizador»: cria a conta e envia o convite.
    $nv = $old ? ['id' => 0, 'name' => $old['name'] ?? '', 'email' => $old['email'] ?? '', 'role' => $old['role'] ?? 'member', 'department_id' => $old['department_id'] ?? '',
                  'reports_to' => $old['reports_to'] ?? '', 'is_partner' => !empty($old['is_partner']), 'color' => $old['color'] ?? '', 'hourly_cost' => null, 'monthly_hours' => 168,
                  'positions' => array_flip(array_map('intval', (array)($old['positions'] ?? []))), 'work_hours' => null]
               : ['id' => 0, 'name' => '', 'email' => '', 'role' => 'member', 'department_id' => '', 'reports_to' => '', 'is_partner' => 0,
                  'color' => ['#81CFE3', '#FBAE9C', '#FFC718', '#9CCB8F', '#B9A6E3'][count($all) % 5], 'hourly_cost' => null, 'monthly_hours' => 168, 'positions' => [], 'work_hours' => null]; ?>
<div class="mdl<?= !empty($_GET['novo']) ? ' open' : '' ?>" id="mdl-user" role="dialog" aria-modal="true" aria-labelledby="mdl-user-t"<?= !empty($_GET['novo']) ? '' : ' hidden' ?> data-clear="novo">
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card wide" method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="do" value="add_user">
    <h3 id="mdl-user-t"><?= icon('user') ?> Novo utilizador</h3>
    <p class="muted">A pessoa recebe um convite por email para definir a palavra-passe (vale <?= TEAM_INVITE_DAYS ?> dias). Fica logo com a checklist de entrada.</p>
    <?= team_user_fields($pdo, $nv, $actor, 'nu') ?>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">Criar e enviar convite</button></div>
  </form>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
else: // departamentos
    $editD = (int)($_GET['edit'] ?? 0);
    $pendD = (int)($_GET['del'] ?? 0);
    $count = [];
    foreach ($people as $u) if ($u['department_id'] !== null) $count[(int)$u['department_id']] = ($count[(int)$u['department_id']] ?? 0) + 1;
    $tree = team_dept_tree($depts);
    $ed = $depts[$editD] ?? null;
?>
<div class="tablewrap">
<table class="dtable">
  <thead><tr><th>Departamento</th><th>Responsável</th><th class="num">Pessoas</th><th></th></tr></thead>
  <tbody>
  <?php if (!$tree): ?><tr><td colspan="4" class="empty">Ainda não há departamentos.</td></tr><?php endif; ?>
  <?php foreach ($tree as $n): $d = $n['d']; $id = (int)$d['id']; ?>
    <tr class="<?= ($editD === $id || $pendD === $id) ? 'pending' : '' ?>">
      <td class="b" style="padding-left:<?= 12 + $n['lvl'] * 22 ?>px"><?= $n['lvl'] ? '<span class="muted">↳</span> ' : '' ?><?= e($d['name']) ?><?= $n['lvl'] ? ' <small class="muted">subdepartamento</small>' : '' ?></td>
      <td><?= $d['head_user_id'] && isset($people[(int)$d['head_user_id']]) ? team_avatar($people[(int)$d['head_user_id']], 'sm', false) . ' ' . e($d['head_name']) : '<span class="muted">' . ($n['lvl'] ? 'equipa a definir' : '—') . '</span>' ?></td>
      <td class="num"><?= $count[$id] ?? 0 ?></td>
      <td class="num nowrap">
        <?php if ($pendD === $id): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="del_dept"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn danger sm" type="submit">Sim, eliminar</button></form>
          <a class="lnk" href="<?= e(team_url('departamentos')) ?>">Cancelar</a>
        <?php else: ?>
          <a class="lnk" href="<?= e(team_url('departamentos', ['edit' => $id])) ?>#departamento">Editar</a>
          <a class="lnk del" href="<?= e(team_url('departamentos', ['del' => $id])) ?>">Eliminar</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pendD && isset($depts[$pendD])): ?><p class="flash info">Ao eliminar «<?= e($depts[$pendD]['name']) ?>», as pessoas e os subdepartamentos passam para o departamento-mãe (ou ficam sem departamento).</p><?php endif; ?>

<form method="post" class="box" id="departamento">
  <?= csrf_field() ?><input type="hidden" name="do" value="<?= $ed ? 'save_dept' : 'add_dept' ?>">
  <?php if ($ed): ?><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>"><?php endif; ?>
  <h2><?= $ed ? 'Editar ' . e($ed['name']) : 'Novo departamento ou subdepartamento' ?></h2>
  <div class="grid">
    <div class="field"><label for="dname">Nome</label><input type="text" id="dname" name="name" required maxlength="80" value="<?= e($ed['name'] ?? '') ?>"></div>
    <div class="field"><label for="dpar">Pertence a <span class="ii" tabindex="0" role="img" aria-label="Ajuda: escolha um departamento para criar um subdepartamento (ex.: Adverts em Campanhas e Adverts)." data-tip="Escolha um departamento para criar um subdepartamento (ex.: Adverts em Campanhas e Adverts).">i</span></label>
      <select id="dpar" name="parent_id"><option value="">— nenhum (departamento) —</option>
        <?php foreach ($tree as $n): if ($n['lvl'] > 0 || ($ed && (int)$n['d']['id'] === (int)$ed['id'])) continue; ?>
          <option value="<?= (int)$n['d']['id'] ?>"<?= (string)($ed['parent_id'] ?? '') === (string)$n['d']['id'] ? ' selected' : '' ?>><?= e($n['d']['name']) ?></option>
        <?php endforeach; ?></select></div>
    <div class="field"><label for="dhead">Responsável (opcional)</label>
      <select id="dhead" name="head_user_id"><option value="">— sem responsável —</option>
        <?php foreach ($people as $u): if ($u['role'] === 'superadmin') continue; ?>
          <option value="<?= (int)$u['id'] ?>"<?= (string)($ed['head_user_id'] ?? '') === (string)$u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?></select></div>
  </div>
  <p class="muted small">Um colaborador escolhido como responsável passa a «Chefe de equipa». Admins continuam admins.</p>
  <div class="actions"><button class="btn" type="submit"><?= $ed ? 'Gravar' : 'Adicionar' ?></button>
    <?php if ($ed): ?><a class="btn ghost" href="<?= e(team_url('departamentos')) ?>">Cancelar</a><?php endif; ?></div>
</form>
<?php endif; ?>

<?php // «Encontrar hora» (§17.7): espaços livres comuns a várias pessoas. ?>
<div class="mdl" id="mdl-find" role="dialog" aria-modal="true" aria-labelledby="mdl-find-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card wide" data-find-form autocomplete="off">
    <h3 id="mdl-find-t"><?= icon('calendar') ?> Encontrar hora</h3>
    <p class="muted">Escolha as pessoas e a duração: aparecem os blocos em que todos estão livres (dentro do horário de cada um).</p>
    <div class="field"><span class="lbl">Com quem</span><div class="chips-check find-people">
      <?php foreach ($people as $u): if ((int)$u['id'] === $me) continue; ?>
        <label class="chk"><input type="checkbox" name="ids" value="<?= (int)$u['id'] ?>"><span><?= e($u['name']) ?></span></label>
      <?php endforeach; ?></div></div>
    <div class="grid">
      <div class="field"><label for="fd-d">Duração</label><select id="fd-d" name="d"><?= team_opts(MEETING_DURATIONS, 30) ?></select></div>
      <div class="field"><label for="fd-w">Semana de</label><input type="date" id="fd-w" name="w" value="<?= e((new DateTimeImmutable('now', team_tz()))->format('Y-m-d')) ?>"></div>
    </div>
    <div class="find-res" data-find-res></div>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Fechar</button><span class="spacer"></span><button class="btn" type="submit">Procurar</button></div>
  </form>
</div>

<script src="<?= asset_url('team.js') ?>" defer></script>
<?php render_footer();
