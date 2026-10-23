<?php
/**
 * topper. admin — passo 19 da base de dados: aba Equipa (§17)
 *
 * Cargos (vários por pessoa, posições por preencher), sócio, «responde a»,
 * subdepartamentos, telemóvel, foto, competências, aniversário, horário,
 * presença (só a hora do último sinal), modo Foco, convites, histórico da
 * conta, checklists de entrada e saída, chat privado 1:1, pedidos de
 * reunião, férias e ausências, e Ocupado/Livre nas tarefas. Cria a
 * estrutura inicial (§17.3) e as contas dos sócios (§17.9) — atualiza as
 * que já existirem, nunca duplica. Como os outros passos, verifica antes de
 * mudar e pode correr as vezes que forem.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column

/** Cargos de partida (§17.2): os de direção primeiro, depois os de produção. */
const TEAM_POSITIONS_SEED = ['CEO', 'COO', 'CMO', 'CTO', 'CFO', 'Business Developer', 'Post Production Lead', 'Desenvolvedor de Produto',
                             '3D Artist', 'Compositor', 'Editor', 'Produtor'];

/** Estrutura inicial (§17.3): [departamento, pai, email do responsável]. */
const TEAM_DEPTS_SEED = [
    ['Direção', null, 'rodrigonicolau@topperdigital.xyz'],
    ['Comercial', null, 'santiagoapolonio@topperdigital.xyz'],
    ['Pós-Produção e VFX', null, 'guilhermealbano@topperdigital.xyz'],
    ['Campanhas e Adverts', null, 'valdirfurtado@topperdigital.xyz'],
    ['Adverts', 'Campanhas e Adverts', null],
    ['Campanhas', 'Campanhas e Adverts', null],
    ['Produto e Otimização de Processos', null, 'diogovasconcelos@topperhub.xyz'],
];

/** Os sócios (§17.9): [nome, email, departamento, cargos, responde ao CEO?]. */
const TEAM_PARTNERS_SEED = [
    ['Rodrigo Nicolau', 'rodrigonicolau@topperdigital.xyz', 'Direção', ['CEO'], false],
    ['Santiago Apolónio', 'santiagoapolonio@topperdigital.xyz', 'Comercial', ['COO', 'Business Developer'], true],
    ['Guilherme Albano', 'guilhermealbano@topperdigital.xyz', 'Pós-Produção e VFX', ['Post Production Lead'], true],
    ['Valdir Furtado', 'valdirfurtado@topperdigital.xyz', 'Campanhas e Adverts', ['CMO'], true],
    ['Diogo Vasconcelos', 'diogovasconcelos@topperhub.xyz', 'Produto e Otimização de Processos', ['CTO', 'Desenvolvedor de Produto'], true],
];

/** Os departamentos de exemplo do passo 12, que saem se ninguém os usa. */
const TEAM_DEPTS_PLACEHOLDER = ['Orçamentação', 'VFX', 'Desenvolvimento'];

function migrate_team(PDO $pdo): void {
    // ── pessoas ──
    db_add_column($pdo, 'users', 'is_partner',     'TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'users', 'reports_to',     'INT UNSIGNED NULL',  'INTEGER NULL');
    db_add_column($pdo, 'users', 'phone',          "VARCHAR(40) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'users', 'photo',          'VARCHAR(120) NULL',  'TEXT NULL');
    db_add_column($pdo, 'users', 'birthday',       'DATE NULL',          'TEXT NULL');
    db_add_column($pdo, 'users', 'skills',         "VARCHAR(500) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'users', 'work_hours',     'VARCHAR(400) NULL',  'TEXT NULL');
    db_add_column($pdo, 'users', 'last_seen_at',   'DATETIME NULL',      'TEXT NULL');
    db_add_column($pdo, 'users', 'focus_until',    'DATETIME NULL',      'TEXT NULL');
    db_add_column($pdo, 'users', 'invite_pending', 'TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'users', 'invited_at',     'DATETIME NULL',      'TEXT NULL');
    db_add_column($pdo, 'password_resets', 'kind', "VARCHAR(8) NOT NULL DEFAULT 'reset'", "TEXT NOT NULL DEFAULT 'reset'");
    db_add_column($pdo, 'departments', 'parent_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'tasks', 'busy',           'TINYINT(1) NOT NULL DEFAULT 1', 'INTEGER NOT NULL DEFAULT 1');

    db_create($pdo, 'positions', "
        id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name      VARCHAR(80) NOT NULL,
        position  INT         NOT NULL DEFAULT 0,
        is_active TINYINT(1)  NOT NULL DEFAULT 1");
    db_create($pdo, 'user_positions', "
        user_id     INT UNSIGNED NOT NULL,
        position_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, position_id)");
    // Uma posição por preencher no organograma: cargo, departamento e a quem responde.
    db_create($pdo, 'team_openings', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        position_id INT UNSIGNED NULL,
        department_id INT UNSIGNED NULL,
        reports_to  INT UNSIGNED NULL,
        note        VARCHAR(160) NOT NULL DEFAULT '',
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP");
    db_create($pdo, 'user_history', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        event      VARCHAR(16)  NOT NULL,
        note       VARCHAR(255) NOT NULL DEFAULT '',
        by_user    INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_uh (user_id, created_at)");
    // Checklists de entrada e de saída (§17.10): itens em JSON [{k, t, done}].
    db_create($pdo, 'team_checklists', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id      INT UNSIGNED NOT NULL,
        kind         VARCHAR(8)   NOT NULL,
        items        TEXT         NOT NULL,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME     NULL,
        UNIQUE KEY uq_tc (user_id, kind)");
    // Chat privado 1:1 (§17.6): só os dois participantes leem.
    db_create($pdo, 'chat_messages', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        from_id    INT UNSIGNED NOT NULL,
        to_id      INT UNSIGNED NOT NULL,
        body       TEXT         NOT NULL,
        meeting_id INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL,
        read_at    DATETIME     NULL,
        INDEX idx_chat_pair (from_id, to_id, id),
        INDEX idx_chat_to (to_id, read_at)");
    // Pedidos de reunião (§17.7): hora de Lisboa, plataforma, link, estado e as tarefas criadas ao aceitar.
    db_create($pdo, 'meetings', "
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        requester_id  INT UNSIGNED NOT NULL,
        invitee_id    INT UNSIGNED NOT NULL,
        group_key     VARCHAR(16)  NOT NULL DEFAULT '',
        starts_at     DATETIME     NOT NULL,
        duration_min  SMALLINT     NOT NULL DEFAULT 30,
        subject       VARCHAR(200) NOT NULL,
        deal_id       INT UNSIGNED NULL,
        platform      VARCHAR(8)   NOT NULL DEFAULT 'meet',
        link          VARCHAR(500) NOT NULL DEFAULT '',
        status        VARCHAR(10)  NOT NULL DEFAULT 'pending',
        parent_id     INT UNSIGNED NULL,
        requester_task_id INT UNSIGNED NULL,
        invitee_task_id   INT UNSIGNED NULL,
        created_at    DATETIME     NOT NULL,
        decided_at    DATETIME     NULL,
        INDEX idx_meet_inv (invitee_id, status)");
    // Férias e ausências (§17.10): pedido → aprovação por admin → bloqueia a agenda.
    db_create($pdo, 'absences', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        kind        VARCHAR(10)  NOT NULL DEFAULT 'ferias',
        start_date  DATE         NOT NULL,
        end_date    DATE         NOT NULL,
        note        VARCHAR(255) NOT NULL DEFAULT '',
        status      VARCHAR(10)  NOT NULL DEFAULT 'pending',
        decided_by  INT UNSIGNED NULL,
        decided_at  DATETIME     NULL,
        created_at  DATETIME     NOT NULL,
        INDEX idx_abs (user_id, start_date)");

    // ── cargos ──
    $pos = [];
    foreach ($pdo->query('SELECT id, name FROM positions')->fetchAll() as $r) $pos[mb_strtolower($r['name'])] = (int)$r['id'];
    $insP = $pdo->prepare('INSERT INTO positions (name, position) VALUES (?, ?)');
    $n = count($pos);
    foreach (TEAM_POSITIONS_SEED as $p) {
        if (!isset($pos[mb_strtolower($p)])) { $insP->execute([$p, ++$n]); $pos[mb_strtolower($p)] = (int)$pdo->lastInsertId(); }
    }
    // A «função» antiga de cada pessoa passa a cargo (fica na lista, gerida pelos admins).
    $link = $pdo->prepare((db_is_sqlite($pdo) ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO user_positions (user_id, position_id) VALUES (?, ?)');
    foreach ($pdo->query("SELECT id, job_title FROM users WHERE job_title <> ''")->fetchAll() as $u) {
        $k = mb_strtolower(trim((string)$u['job_title']));
        if ($k === '') continue;
        if (!isset($pos[$k])) { $insP->execute([trim((string)$u['job_title']), ++$n]); $pos[$k] = (int)$pdo->lastInsertId(); }
        $link->execute([(int)$u['id'], $pos[$k]]);
    }

    // ── departamentos (§17.3) ──
    $dept = [];
    foreach ($pdo->query('SELECT id, name FROM departments')->fetchAll() as $r) $dept[$r['name']] = (int)$r['id'];
    $order = 0;
    foreach (TEAM_DEPTS_SEED as [$name, $parent]) {
        $order++;
        if (!isset($dept[$name])) {
            $pdo->prepare('INSERT INTO departments (name, position, parent_id) VALUES (?, ?, ?)')->execute([$name, $order, $parent ? ($dept[$parent] ?? null) : null]);
            $dept[$name] = (int)$pdo->lastInsertId();
        } elseif ($parent) {
            $pdo->prepare('UPDATE departments SET parent_id = COALESCE(parent_id, ?) WHERE id = ?')->execute([$dept[$parent] ?? null, $dept[$name]]);
        }
    }
    // A ordem do documento de 18 out 2026 (Direção primeiro).
    foreach (TEAM_DEPTS_SEED as $i => [$name]) if (isset($dept[$name])) $pdo->prepare('UPDATE departments SET position = ? WHERE id = ?')->execute([$i + 1, $dept[$name]]);
    // Os de exemplo do passo 12 saem só se ninguém os usa (pessoas, chefe, subdepartamentos).
    foreach (TEAM_DEPTS_PLACEHOLDER as $name) {
        if (!isset($dept[$name])) continue;
        $id = $dept[$name];
        $used = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM users WHERE department_id = $id) + (SELECT COUNT(*) FROM departments WHERE parent_id = $id OR (id = $id AND head_user_id IS NOT NULL))")->fetchColumn();
        if ($used === 0) { $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]); unset($dept[$name]); }
    }

    // ── contas dos sócios (§17.9): Admin, sócio, chefe do seu departamento; convite por email ──
    $ceo = null;
    $invite = [];
    foreach (TEAM_PARTNERS_SEED as [$name, $email, $dname, $cargos]) {
        $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = ?');
        $st->execute([strtolower($email)]);
        $u = $st->fetch();
        $did = $dept[$dname] ?? null;
        if ($u) {
            // Já existe: atualiza (o superadmin nunca muda de papel).
            $role = $u['role'] === 'superadmin' ? 'superadmin' : 'admin';
            $pdo->prepare('UPDATE users SET role = ?, is_partner = 1, department_id = COALESCE(department_id, ?) WHERE id = ?')->execute([$role, $did, $u['id']]);
            $uid = (int)$u['id'];
        } else {
            $p = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) FROM users')->fetchColumn() + 1;
            $color = ['#2F77B1', '#111111', '#E8846D', '#81CFE3', '#2A7928'][count($invite) % 5];
            $pdo->prepare("INSERT INTO users (email, name, password_hash, role, is_active, department_id, color, position, is_partner, invite_pending, monthly_hours, created_at)
                           VALUES (?, ?, ?, 'admin', 1, ?, ?, ?, 1, 1, 168, " . (db_is_sqlite($pdo) ? "datetime('now')" : 'NOW()') . ')')
                ->execute([$email, $name, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $did, $color, $p]);
            $uid = (int)$pdo->lastInsertId();
            $invite[] = $uid;
            $pdo->prepare("INSERT INTO user_history (user_id, event, note) VALUES (?, 'create', 'Conta de sócio criada')")->execute([$uid]);
        }
        foreach ($cargos as $c) if (isset($pos[mb_strtolower($c)])) $link->execute([$uid, $pos[mb_strtolower($c)]]);
        if ($did) $pdo->prepare('UPDATE departments SET head_user_id = COALESCE(head_user_id, ?) WHERE id = ?')->execute([$uid, $did]);
        if ($name === 'Rodrigo Nicolau') $ceo = $uid;
    }
    // Os quatro departamentos respondem ao CEO.
    if ($ceo) {
        foreach (TEAM_PARTNERS_SEED as [, $email, , , $toCeo]) {
            if ($toCeo) $pdo->prepare('UPDATE users SET reports_to = COALESCE(reports_to, ?) WHERE LOWER(email) = ?')->execute([$ceo, strtolower($email)]);
        }
        // O CFO está por preencher (responde ao CEO, na Direção).
        if (!(int)$pdo->query('SELECT COUNT(*) FROM team_openings')->fetchColumn() && isset($pos['cfo'])) {
            $pdo->prepare('INSERT INTO team_openings (position_id, department_id, reports_to, note) VALUES (?, ?, ?, ?)')
                ->execute([$pos['cfo'], $dept['Direção'] ?? null, $ceo, '']);
        }
    }

    // Os convites saem por email (o link vale 7 dias; dá para reenviar em Utilizadores e acessos).
    if ($invite) {
        require_once __DIR__ . '/team.php';
        foreach ($invite as $uid) {
            try { team_send_invite($pdo, $uid, null); } catch (Throwable $e) { error_log('[topper] convite do sócio ' . $uid . ': ' . $e->getMessage()); }
        }
    }
}
