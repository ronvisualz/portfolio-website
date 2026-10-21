<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
$user = require_login();
require_page('equipa');

// Equipa: utilizadores, departamentos e permissões. Tudo em MySQL.
// Quem pode o quê vem de inc/perm.php — aqui só se pergunta.

const TEAM_COLORS_ALL = ['#81CFE3', '#FBAE9C', '#FFC718', '#9CCB8F', '#B9A6E3'];
const PASSWORD_MIN = 10;

$tab = in_array($_GET['tab'] ?? '', ['pessoas', 'departamentos', 'permissoes'], true) ? $_GET['tab'] : 'pessoas';

function team_url(string $tab, array $q = []): string {
    return '/admin/equipa.php?' . http_build_query(['tab' => $tab] + $q);
}

function user_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function dept_exists(PDO $pdo, ?int $id): bool {
    if ($id === null) return true;
    $st = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE id = ?');
    $st->execute([$id]);
    return (int)$st->fetchColumn() > 0;
}

/** Os campos de um utilizador vindos do formulário, validados. Devolve [valores, erro]. */
function user_input(PDO $pdo, array $actor, ?array $editing): array {
    $name  = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $role  = (string)($_POST['role'] ?? 'member');
    $dept  = (string)($_POST['department_id'] ?? '') === '' ? null : (int)$_POST['department_id'];
    $job   = trim((string)($_POST['job_title'] ?? ''));
    $color = (string)($_POST['color'] ?? '');

    if ($name === '' || mb_strlen($name) > 120)                    return [null, 'Indique o nome (até 120 caracteres).'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) return [null, 'Email inválido.'];
    // A conta de superadmin (info@) cria-se uma vez, por um admin, se ainda não existir.
    $super = $email === SUPERADMIN_EMAIL;
    if ($super) {
        $has = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'superadmin'")->fetchColumn();
        if ($has > 0 || $editing) return [null, 'Esse email é reservado ao superadmin.'];
        $role = 'superadmin';
    } elseif (!in_array($role, assignable_roles($actor), true)) {
        return [null, 'Papel inválido.'];
    }
    if (!dept_exists($pdo, $dept))                                  return [null, 'Departamento inválido.'];
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color))                 $color = TEAM_COLORS_ALL[0];

    $st = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id <> ?');
    $st->execute([$email, (int)($editing['id'] ?? 0)]);
    if ($st->fetchColumn() !== false) return [null, 'Já existe um utilizador com esse email.'];

    return [[
        'name' => $name, 'email' => $email, 'role' => $role, 'department_id' => $dept,
        'job_title' => mb_substr($job, 0, 80), 'color' => strtoupper($color),
    ], null];
}

function password_input(bool $required): array {
    $p1 = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if ($p1 === '' && !$required) return [null, null];
    if (mb_strlen($p1) < PASSWORD_MIN) return [null, 'A palavra-passe precisa de pelo menos ' . PASSWORD_MIN . ' caracteres.'];
    if ($p1 !== $p2)                   return [null, 'As palavras-passe não coincidem.'];
    return [password_hash($p1, PASSWORD_DEFAULT), null];
}

// ── ações ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');

    // Utilizadores: criar exige gerir a aba; editar exige ainda poder mexer nesse utilizador.
    if (in_array($do, ['add_user', 'save_user', 'deactivate', 'reactivate'], true)) {
        require_perm('equipa', PERM_MANAGE);
        $actor = user_by_id($pdo, (int)$user['id']);

        if ($do === 'add_user') {
            [$v, $err] = user_input($pdo, $actor, null);
            [$hash, $perr] = $v ? password_input(true) : [null, null];
            if ($err || $perr) { flash($err ?: $perr, 'err'); $_SESSION['team_form'] = $_POST; redirect(team_url('pessoas', ['new' => 1]) . '#utilizador'); }
            $pos = (int)$pdo->query('SELECT COALESCE(MAX(position),0) FROM users')->fetchColumn() + 1;
            $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, department_id, job_title, color, position, is_active, created_by)
                 VALUES (?,?,?,?,?,?,?,?,1,?)'
            )->execute([$v['name'], $v['email'], $hash, $v['role'], $v['department_id'], $v['job_title'], $v['color'], $pos, (int)$user['id']]);
            flash('Utilizador criado. Entregue-lhe o email e a palavra-passe por um canal privado.');
            redirect(team_url('pessoas'));
        }

        $target = user_by_id($pdo, (int)($_POST['id'] ?? 0));
        if (!$target || !can_manage_user($actor, $target)) {
            flash('Não pode alterar este utilizador.', 'err');
            redirect(team_url('pessoas'));
        }

        if ($do === 'save_user') {
            [$v, $err] = user_input($pdo, $actor, $target);
            [$hash, $perr] = $v ? password_input(false) : [null, null];
            if ($err || $perr) { flash($err ?: $perr, 'err'); redirect(team_url('pessoas', ['edit' => $target['id']]) . '#utilizador'); }
            $pdo->prepare('UPDATE users SET name=?, email=?, role=?, department_id=?, job_title=?, color=? WHERE id=?')
                ->execute([$v['name'], $v['email'], $v['role'], $v['department_id'], $v['job_title'], $v['color'], $target['id']]);
            if ($hash) $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $target['id']]);
            // Um chefe que deixou de o ser, ou mudou de departamento, deixa de chefiar o antigo.
            $pdo->prepare('UPDATE departments SET head_user_id = NULL WHERE head_user_id = ? AND (? <> \'lead\' OR id <> COALESCE(?, 0))')
                ->execute([$target['id'], $v['role'], $v['department_id']]);
            flash('Utilizador guardado.');
            redirect(team_url('pessoas'));
        }

        // Eliminar = desativar: deixa de entrar, o histórico fica.
        if ($do === 'deactivate') {
            if ((int)$target['id'] === (int)$user['id']) { flash('Não pode desativar a sua própria conta.', 'err'); redirect(team_url('pessoas')); }
            $pdo->prepare('UPDATE users SET is_active = 0, deactivated_at = ' . (db_is_sqlite($pdo) ? "datetime('now')" : 'NOW()') . ' WHERE id = ?')
                ->execute([$target['id']]);
            $pdo->prepare('UPDATE departments SET head_user_id = NULL WHERE head_user_id = ?')->execute([$target['id']]);
            flash('Conta desativada. O histórico desta pessoa mantém-se.');
            redirect(team_url('pessoas'));
        }
        if ($do === 'reactivate') {
            $pdo->prepare('UPDATE users SET is_active = 1, deactivated_at = NULL WHERE id = ?')->execute([$target['id']]);
            flash('Conta reativada.');
            redirect(team_url('pessoas'));
        }
    }

    // Departamentos e permissões: gerir a aba.
    if (in_array($do, ['add_dept', 'save_dept', 'del_dept', 'save_perms', 'save_user_perms'], true)) {
        require_perm('equipa', PERM_MANAGE);

        if ($do === 'add_dept' || $do === 'save_dept') {
            $name = trim((string)($_POST['name'] ?? ''));
            $head = (string)($_POST['head_user_id'] ?? '') === '' ? null : (int)$_POST['head_user_id'];
            if ($name === '' || mb_strlen($name) > 80) { flash('Indique o nome do departamento.', 'err'); redirect(team_url('departamentos')); }
            if ($do === 'add_dept') {
                $pos = (int)$pdo->query('SELECT COALESCE(MAX(position),0) FROM departments')->fetchColumn() + 1;
                $pdo->prepare('INSERT INTO departments (name, position) VALUES (?, ?)')->execute([$name, $pos]);
                $deptId = (int)$pdo->lastInsertId();
            } else {
                $deptId = (int)($_POST['id'] ?? 0);
                $pdo->prepare('UPDATE departments SET name = ? WHERE id = ?')->execute([$name, $deptId]);
            }
            // O chefe é um utilizador ativo; passa a chefe de equipa e a pertencer ao departamento.
            if ($head !== null) {
                $h = user_by_id($pdo, $head);
                $actor = user_by_id($pdo, (int)$user['id']);
                if (!$h || (int)$h['is_active'] !== 1 || !can_manage_user($actor, $h)) {
                    flash('Esse utilizador não pode ser chefe deste departamento.', 'err');
                    redirect(team_url('departamentos'));
                }
                $pdo->prepare("UPDATE users SET role = 'lead', department_id = ? WHERE id = ?")->execute([$deptId, $head]);
            }
            $pdo->prepare('UPDATE departments SET head_user_id = ? WHERE id = ?')->execute([$head, $deptId]);
            flash('Departamento guardado.');
            redirect(team_url('departamentos'));
        }

        if ($do === 'del_dept') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('UPDATE users SET department_id = NULL WHERE department_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
            flash('Departamento eliminado. As pessoas ficaram sem departamento.');
            redirect(team_url('departamentos'));
        }

        // Grelha papel × aba (só chefe de equipa e colaborador; admins têm sempre tudo).
        if ($do === 'save_perms' || $do === 'save_user_perms') {
            $isUser = $do === 'save_user_perms';
            $uid = $isUser ? (int)($_POST['user_id'] ?? 0) : 0;
            if ($isUser) {
                $t = user_by_id($pdo, $uid);
                $actor = user_by_id($pdo, (int)$user['id']);
                if (!$t || role_is_admin($t['role']) || !can_manage_user($actor, $t)) {
                    flash('Não pode alterar as permissões deste utilizador.', 'err');
                    redirect(team_url('permissoes'));
                }
            }
            $roles = $isUser ? [''] : ['lead', 'member'];
            $del = $pdo->prepare('DELETE FROM permissions WHERE role = ? AND user_id = ?');
            $ins = $pdo->prepare('INSERT INTO permissions (role, user_id, tab, level, scope) VALUES (?,?,?,?,?)');
            $pdo->beginTransaction();
            foreach ($roles as $role) {
                $del->execute([$role, $uid]);
                $key = $isUser ? 'u' : $role;
                foreach (array_keys(TABS) as $tabKey) {
                    $lvl = $_POST['lvl'][$key][$tabKey] ?? '';
                    if ($lvl === '' ) continue;                         // exceção vazia = segue o papel
                    $lvl = max(PERM_NONE, min(PERM_MANAGE, (int)$lvl));
                    $sc = (string)($_POST['scope'][$key][$tabKey] ?? '');
                    $sc = in_array($tabKey, SCOPED_TABS, true) && isset(SCOPES[$sc]) ? $sc : null;
                    $ins->execute([$role, $uid, $tabKey, $lvl, $sc]);
                }
            }
            $pdo->commit();
            flash('Permissões guardadas.');
            redirect(team_url('permissoes', $isUser ? ['u' => $uid] : []) );
        }
    }

    redirect(team_url($tab));
}

// ── dados ───────────────────────────────────────────────────
$canManage = can('equipa', PERM_MANAGE);
$actor     = user_by_id($pdo, (int)$user['id']);
$depts     = $pdo->query('SELECT * FROM departments ORDER BY position, name')->fetchAll();
$deptName  = array_column($depts, 'name', 'id');
$users     = $pdo->query('SELECT * FROM users ORDER BY (is_active = 0), position, name')->fetchAll();

render_header('Equipa', 'equipa');
render_flashes();
?>
<h1>Equipa</h1>
<?php $act = array_filter($users, fn($u) => (int)$u['is_active'] === 1 && empty($u['deactivated_at']));
      $byRole = array_count_values(array_column($act, 'role')); ?>
<div class="stats">
  <div class="stat hero"><div class="k">Pessoas ativas</div><div class="v"><?= count($act) ?></div><div class="n"><?= count($users) - count($act) ?> desativadas</div></div>
  <div class="stat"><div class="k">Admins</div><div class="v"><?= ($byRole['superadmin'] ?? 0) + ($byRole['admin'] ?? 0) ?></div><div class="n">acesso total</div></div>
  <div class="stat"><div class="k">Chefes de equipa</div><div class="v"><?= $byRole['lead'] ?? 0 ?></div><div class="n"><?= count($depts) ?> departamentos</div></div>
  <div class="stat"><div class="k">Colaboradores</div><div class="v"><?= $byRole['member'] ?? 0 ?></div><div class="n">com a grelha de permissões</div></div>
</div>

<div class="tabs">
  <a href="<?= e(team_url('pessoas')) ?>" class="<?= $tab === 'pessoas' ? 'on' : '' ?>">Pessoas</a>
  <a href="<?= e(team_url('departamentos')) ?>" class="<?= $tab === 'departamentos' ? 'on' : '' ?>">Departamentos</a>
  <a href="<?= e(team_url('permissoes')) ?>" class="<?= $tab === 'permissoes' ? 'on' : '' ?>">Permissões</a>
</div>

<?php if ($tab === 'pessoas'):
    $editId = (int)($_GET['edit'] ?? 0);
    $eu = $editId ? user_by_id($pdo, $editId) : null;
    if ($eu && !can_manage_user($actor, $eu)) $eu = null;
    $pendingOff = (int)($_GET['off'] ?? 0);
    $old = $_SESSION['team_form'] ?? null; unset($_SESSION['team_form']);
?>
<div class="sub"><?= count(array_filter($users, fn($u) => (int)$u['is_active'] === 1)) ?> pessoas ativas</div>
<div class="tablewrap">
<table>
  <thead><tr><th>Nome</th><th>Papel</th><th class="hide-sm">Departamento</th><th class="hide-sm">Função</th><th class="hide-sm">Email</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): $off = (int)$u['is_active'] !== 1; $mine = can_manage_user($actor, $u); ?>
    <tr class="<?= $off ? 'muted' : '' ?> <?= ($pendingOff === (int)$u['id'] || ($eu && (int)$eu['id'] === (int)$u['id'])) ? 'pending' : '' ?>">
      <td><span class="dot" style="background:<?= e($u['color'] ?: '#ccc') ?>"></span> <span class="b"><?= e($u['name']) ?></span>
        <?php if ($off): ?> <span class="chip">desativado</span><?php endif; ?></td>
      <td><?= e(ROLES[$u['role']] ?? $u['role']) ?></td>
      <td class="hide-sm"><?= e($deptName[$u['department_id']] ?? '—') ?></td>
      <td class="hide-sm"><?= e($u['job_title'] ?: '—') ?></td>
      <td class="hide-sm" style="font-size:0.7rem"><?= e($u['email']) ?></td>
      <td class="num nowrap">
      <?php if ($canManage && $mine): ?>
        <?php if ($pendingOff === (int)$u['id']): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="do" value="deactivate"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn danger sm" type="submit">Sim, desativar</button></form>
          <a class="lnk" href="<?= e(team_url('pessoas')) ?>">Cancelar</a>
        <?php elseif ($off): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="do" value="reactivate"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="btn ghost sm" type="submit">Reativar</button></form>
        <?php else: ?>
          <a class="lnk" href="<?= e(team_url('pessoas', ['edit' => $u['id']])) ?>#utilizador">Editar</a>
          <?php if ((int)$u['id'] !== (int)$user['id']): ?>
            <a class="lnk del" href="<?= e(team_url('pessoas', ['off' => $u['id']])) ?>">Eliminar</a>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="muted" style="font-size:0.7rem">Eliminar desativa a conta: a pessoa deixa de entrar e todo o histórico (leads, negócios, tarefas) mantém-se. Só o superadmin pode alterar ou eliminar admins.</p>

<?php if ($canManage):
    $v = $eu ?? ($old ? [
        'name' => $old['name'] ?? '', 'email' => $old['email'] ?? '', 'role' => $old['role'] ?? 'member',
        'department_id' => $old['department_id'] ?? '', 'job_title' => $old['job_title'] ?? '', 'color' => $old['color'] ?? '',
    ] : ['name' => '', 'email' => '', 'role' => 'member', 'department_id' => '', 'job_title' => '',
         'color' => TEAM_COLORS_ALL[count($users) % count(TEAM_COLORS_ALL)]]);
?>
<h2 id="utilizador"><?= $eu ? 'Editar ' . e($eu['name']) : 'Novo utilizador' ?></h2>
<form method="post" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="<?= $eu ? 'save_user' : 'add_user' ?>">
  <?php if ($eu): ?><input type="hidden" name="id" value="<?= (int)$eu['id'] ?>"><?php endif; ?>
  <div class="grid">
    <div class="field"><label for="uname">Nome</label>
      <input type="text" id="uname" name="name" required maxlength="120" value="<?= e($v['name']) ?>"></div>
    <div class="field"><label for="uemail">Email (é o login)</label>
      <input type="email" id="uemail" name="email" required maxlength="190" value="<?= e($v['email']) ?>"></div>
    <div class="field"><label for="urole">Papel</label>
      <select id="urole" name="role">
        <?php foreach (assignable_roles($actor) as $r): ?>
          <option value="<?= $r ?>" <?= $v['role'] === $r ? 'selected' : '' ?>><?= e(ROLES[$r]) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label for="udept">Departamento</label>
      <select id="udept" name="department_id"><option value="">—</option>
        <?php foreach ($depts as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= (string)$v['department_id'] === (string)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="field"><label for="ujob">Função</label>
      <input type="text" id="ujob" name="job_title" maxlength="80" value="<?= e($v['job_title']) ?>" placeholder="Comercial, orçamentista, engenheiro de VFX…"></div>
    <div class="field"><label for="ucolor">Cor (nas Tarefas)</label>
      <input type="color" id="ucolor" name="color" value="<?= e($v['color'] ?: TEAM_COLORS_ALL[0]) ?>"></div>
    <div class="field"><label for="upw"><?= $eu ? 'Nova palavra-passe (opcional)' : 'Palavra-passe inicial' ?></label>
      <input type="password" id="upw" name="password" minlength="<?= PASSWORD_MIN ?>" autocomplete="new-password" <?= $eu ? '' : 'required' ?>></div>
    <div class="field"><label for="upw2">Repetir palavra-passe</label>
      <input type="password" id="upw2" name="password2" minlength="<?= PASSWORD_MIN ?>" autocomplete="new-password" <?= $eu ? '' : 'required' ?>></div>
  </div>
  <div class="actions">
    <button class="btn" type="submit"><?= $eu ? 'Guardar' : 'Criar utilizador' ?></button>
    <?php if ($eu): ?><a class="btn ghost" href="<?= e(team_url('pessoas')) ?>">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php elseif ($tab === 'departamentos'):
    $editD = (int)($_GET['edit'] ?? 0);
    $pendD = (int)($_GET['del'] ?? 0);
    $count = [];
    foreach ($users as $u) if ((int)$u['is_active'] === 1 && $u['department_id'] !== null) $count[$u['department_id']] = ($count[$u['department_id']] ?? 0) + 1;
    $userName = array_column($users, 'name', 'id');
    $headOptions = array_filter($users, fn($u) => (int)$u['is_active'] === 1 && $u['role'] !== 'superadmin' && can_manage_user($actor, $u));
?>
<div class="sub">Um departamento sem chefe é gerido pelos admins.</div>
<div class="tablewrap">
<table>
  <thead><tr><th>Departamento</th><th>Chefe de equipa</th><th class="num">Pessoas</th><th></th></tr></thead>
  <tbody>
  <?php if (!$depts): ?><tr><td colspan="4" class="empty">Ainda não há departamentos.</td></tr><?php endif; ?>
  <?php foreach ($depts as $d): $id = (int)$d['id']; ?>
    <tr class="<?= ($editD === $id || $pendD === $id) ? 'pending' : '' ?>">
      <td class="b"><?= e($d['name']) ?></td>
      <td><?= e($userName[$d['head_user_id']] ?? '—') ?></td>
      <td class="num"><?= $count[$id] ?? 0 ?></td>
      <td class="num nowrap"><?php if ($canManage): ?>
        <?php if ($pendD === $id): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="do" value="del_dept"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn danger sm" type="submit">Sim, eliminar</button></form>
          <a class="lnk" href="<?= e(team_url('departamentos')) ?>">Cancelar</a>
        <?php else: ?>
          <a class="lnk" href="<?= e(team_url('departamentos', ['edit' => $id])) ?>#departamento">Editar</a>
          <a class="lnk del" href="<?= e(team_url('departamentos', ['del' => $id])) ?>">Eliminar</a>
        <?php endif; ?>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($canManage): $ed = null; foreach ($depts as $d) if ((int)$d['id'] === $editD) $ed = $d; ?>
<h2 id="departamento"><?= $ed ? 'Editar departamento' : 'Novo departamento' ?></h2>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="<?= $ed ? 'save_dept' : 'add_dept' ?>">
  <?php if ($ed): ?><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>"><?php endif; ?>
  <div class="grid">
    <div class="field"><label for="dname">Nome</label>
      <input type="text" id="dname" name="name" required maxlength="80" value="<?= e($ed['name'] ?? '') ?>"></div>
    <div class="field"><label for="dhead">Chefe de equipa (opcional)</label>
      <select id="dhead" name="head_user_id"><option value="">— sem chefe —</option>
        <?php foreach ($headOptions as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (string)($ed['head_user_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
  </div>
  <p class="muted" style="font-size:0.7rem">Escolher um chefe passa essa pessoa a «Chefe de equipa» e coloca-a neste departamento.</p>
  <div class="actions">
    <button class="btn" type="submit"><?= $ed ? 'Guardar' : 'Adicionar' ?></button>
    <?php if ($ed): ?><a class="btn ghost" href="<?= e(team_url('departamentos')) ?>">Cancelar</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php else: // permissões
    $levels = [PERM_NONE => 'Sem acesso', PERM_VIEW => 'Ver', PERM_EDIT => 'Editar', PERM_MANAGE => 'Gerir'];
    $roleGrid = [];
    foreach (['lead', 'member'] as $r) $roleGrid[$r] = perm_grid($pdo, ['id' => 0, 'role' => $r]);
    $uSel = (int)($_GET['u'] ?? 0);
    $people = array_filter($users, fn($u) => (int)$u['is_active'] === 1 && !role_is_admin($u['role']));
    $selU = null; foreach ($people as $p) if ((int)$p['id'] === $uSel) $selU = $p;
    $over = [];
    if ($selU) {
        $st = $pdo->prepare("SELECT tab, level, scope FROM permissions WHERE role = '' AND user_id = ?");
        $st->execute([$uSel]);
        foreach ($st->fetchAll() as $r) $over[$r['tab']] = $r;
    }
    $lvlSelect = function (string $key, string $t, $cur, bool $allowInherit) use ($levels, $canManage) {
        $h = '<select name="lvl[' . e($key) . '][' . e($t) . ']" aria-label="Nível"' . ($canManage ? '' : ' disabled') . '>';
        if ($allowInherit) $h .= '<option value="">Como o papel</option>';
        foreach ($levels as $n => $l) $h .= '<option value="' . $n . '"' . ($cur !== null && (int)$cur === $n ? ' selected' : '') . '>' . $l . '</option>';
        return $h . '</select>';
    };
    $scopeSelect = function (string $key, string $t, $cur, bool $allowInherit) use ($canManage) {
        if (!in_array($t, SCOPED_TABS, true)) return '';
        $h = ' <select name="scope[' . e($key) . '][' . e($t) . ']" aria-label="Âmbito"' . ($canManage ? '' : ' disabled') . '>';
        if ($allowInherit) $h .= '<option value="">—</option>';
        foreach (SCOPES as $k => $l) $h .= '<option value="' . $k . '"' . ($cur === $k ? ' selected' : '') . '>' . $l . '</option>';
        return $h . '</select>';
    };
?>
<div class="sub">Superadmin e admins têm sempre acesso total. Aqui ajusta-se o que chefes de equipa e colaboradores podem fazer em cada aba.
  <b>Ver</b> só consulta · <b>Editar</b> cria e altera · <b>Gerir</b> também apaga e configura. Em Leads, Pipeline, Negócios e Tarefas escolhe-se ainda que registos se veem.</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save_perms">
  <div class="tablewrap perm">
  <table>
    <thead><tr><th>Aba</th><th><?= e(ROLES['lead']) ?></th><th><?= e(ROLES['member']) ?></th></tr></thead>
    <tbody>
    <?php foreach (TABS as $t => $label): ?>
      <tr><td class="b"><?= e($label) ?></td>
        <?php foreach (['lead', 'member'] as $r): ?>
          <td class="nowrap"><?= $lvlSelect($r, $t, $roleGrid[$r][$t]['level'], false) ?><?= $scopeSelect($r, $t, $roleGrid[$r][$t]['scope'], false) ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($canManage): ?><div class="actions"><button class="btn" type="submit">Guardar permissões</button></div><?php endif; ?>
</form>

<h2 id="excecoes">Exceções por pessoa</h2>
<form class="bar" method="get">
  <input type="hidden" name="tab" value="permissoes">
  <select name="u" onchange="this.form.submit()" aria-label="Pessoa">
    <option value="">Escolher pessoa…</option>
    <?php foreach ($people as $p): ?>
      <option value="<?= (int)$p['id'] ?>" <?= $uSel === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e(ROLES[$p['role']] ?? '') ?>)</option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="btn sm" type="submit">Abrir</button></noscript>
</form>
<?php if ($selU): ?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save_user_perms">
  <input type="hidden" name="user_id" value="<?= (int)$selU['id'] ?>">
  <div class="tablewrap perm">
  <table>
    <thead><tr><th>Aba</th><th>Pelo papel</th><th>Exceção para <?= e($selU['name']) ?></th></tr></thead>
    <tbody>
    <?php $base = $roleGrid[$selU['role']] ?? perm_defaults($selU['role']);
      foreach (TABS as $t => $label): ?>
      <tr><td class="b"><?= e($label) ?></td>
        <td class="muted"><?= e($levels[$base[$t]['level']]) ?><?= $base[$t]['scope'] ? ' · ' . e(SCOPES[$base[$t]['scope']]) : '' ?></td>
        <td class="nowrap"><?= $lvlSelect('u', $t, $over[$t]['level'] ?? null, true) ?><?= $scopeSelect('u', $t, $over[$t]['scope'] ?? null, true) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($canManage): ?><div class="actions"><button class="btn" type="submit">Guardar exceções</button></div><?php endif; ?>
</form>
<?php endif; ?>
<?php endif; ?>

<?php render_footer();
