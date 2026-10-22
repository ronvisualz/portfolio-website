<?php
/**
 * topper. admin — passo 20 da base de dados: aba Tarefas (§18)
 *
 *  · §18.0  a conta admin@topperdigital.xyz passa a info@topperdigital.xyz, papel
 *           Superadmin, a mesma palavra-passe (o hash não se toca). Não se cria outra.
 *  · §18.2  o superadmin não é operador: as leads dele são redistribuídas uma vez, em
 *           rotação pelos 5 operadores (pela ordem de criação das leads), com as tarefas
 *           abertas de cada lead e uma linha no histórico; o resto das tarefas abertas
 *           dele vai para «Por atribuir». O resumo antes/depois fica em settings.
 *  · tarefas: local, plataforma da reunião, privada (pessoal/bloqueio), checklist,
 *           anexos, participantes, pedido de ajuda; histórico das tarefas; vistas guardadas.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** Os operadores (§18.2), pela ordem da rotação. */
const TASK_OPERATORS_ORDER = [
    'rodrigonicolau@topperdigital.xyz',
    'santiagoapolonio@topperdigital.xyz',
    'valdirfurtado@topperdigital.xyz',
    'guilhermealbano@topperdigital.xyz',
    'diogovasconcelos@topperhub.xyz',
];

function migrate_tasks_tab(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);
    db_add_column($pdo, 'tasks', 'location',     "VARCHAR(200) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'tasks', 'platform',     "VARCHAR(8) NOT NULL DEFAULT ''",   "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'tasks', 'private',      'TINYINT(1) NOT NULL DEFAULT 0',    'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'tasks', 'checklist',    'TEXT NULL',                        'TEXT NULL');
    db_add_column($pdo, 'tasks', 'attachments',  'TEXT NULL',                        'TEXT NULL');
    db_add_column($pdo, 'tasks', 'participants', "VARCHAR(255) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'tasks', 'help_by',      'INT UNSIGNED NULL',                'INTEGER NULL');
    // A reunião presencial (§18.7): o local segue no email.
    if (db_has_table($pdo, 'meetings')) db_add_column($pdo, 'meetings', 'location', "VARCHAR(200) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_create($pdo, 'task_log', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id    INT UNSIGNED NOT NULL,
        event      VARCHAR(16)  NOT NULL,
        note       VARCHAR(255) NOT NULL DEFAULT '',
        user_id    INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL,
        INDEX idx_tl (task_id, created_at)");
    // Filtros guardados como vistas (§18.5), por pessoa.
    db_create($pdo, 'task_views', "
        id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id  INT UNSIGNED NOT NULL,
        name     VARCHAR(60)  NOT NULL,
        query    VARCHAR(500) NOT NULL,
        INDEX idx_tv (user_id)");

    // ── §18.0 a conta do superadmin ─────────────────────────
    $find = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = ?');
    $find->execute(['admin@topperdigital.xyz']);
    $adm = $find->fetch() ?: null;
    $find->execute([SUPERADMIN_EMAIL]);
    $info = $find->fetch() ?: null;
    $super = null;
    if ($adm && !$info) {
        // Só o email e o papel: o hash da palavra-passe fica igual.
        $pdo->prepare("UPDATE users SET email = ?, role = 'superadmin' WHERE id = ?")->execute([SUPERADMIN_EMAIL, $adm['id']]);
        $super = (int)$adm['id'];
        if (db_has_table($pdo, 'user_history')) {
            $pdo->prepare('INSERT INTO user_history (user_id, event, note, by_user, created_at) VALUES (?,?,?,?,?)')
                ->execute([$super, 'role', 'admin@topperdigital.xyz → ' . SUPERADMIN_EMAIL . ' · Superadmin (§18.0)', null, now_php()]);
        }
    } elseif ($info) {
        $super = (int)$info['id'];
        if ($info['role'] !== 'superadmin') $pdo->prepare("UPDATE users SET role = 'superadmin' WHERE id = ?")->execute([$super]);
    }
    if ($super === null) return;

    // ── §18.2 redistribuição única ─────────────────────────
    if (setting($pdo, 'tasks_redistribution', '') !== '') return;
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/leads.php';
    $ops = [];
    foreach (TASK_OPERATORS_ORDER as $em) {
        $find->execute([$em]);
        $u = $find->fetch();
        if ($u && (int)$u['is_active'] === 1 && !$u['deactivated_at']) $ops[] = $u;
    }
    $count = fn() => $pdo->query("SELECT u.name, COUNT(d.id) n FROM deals d JOIN users u ON u.id = d.owner_id WHERE d.phase = 'lead' GROUP BY u.name ORDER BY u.name")->fetchAll(PDO::FETCH_KEY_PAIR);
    $before = $count();
    $superMember = member_for_user($pdo, $super);
    $st = $pdo->prepare("SELECT id, owner_id, created_at FROM deals WHERE phase = 'lead' AND owner_id = ? ORDER BY created_at, id");
    $st->execute([$super]);
    $leads = $st->fetchAll();
    $moved = ['leads' => 0, 'tasks' => 0, 'unassigned_tasks' => 0];
    if ($ops && $leads) {
        $members = [];
        foreach ($ops as $o) $members[(int)$o['id']] = member_for_user($pdo, (int)$o['id']);
        $log = $pdo->prepare('INSERT INTO deal_history (deal_id, event, note, user_id) VALUES (?,?,?,?)');
        $own = $pdo->prepare('UPDATE deals SET owner_id = ? WHERE id = ?');
        $tk = $pdo->prepare("UPDATE tasks SET member_id = ? WHERE deal_id = ? AND status <> 'done' AND " . ($superMember ? 'member_id = ?' : 'member_id IS NULL'));
        foreach ($leads as $i => $l) {
            $o = $ops[$i % count($ops)];
            $own->execute([$o['id'], $l['id']]);
            $tk->execute(array_merge([$members[(int)$o['id']], $l['id']], $superMember ? [$superMember] : []));
            $moved['tasks'] += $tk->rowCount();
            $log->execute([$l['id'], 'assign', 'Responsável: ' . $o['name'] . ' (redistribuição única: o superadmin não é operador, §18.2)', null]);
            $moved['leads']++;
        }
    }
    // As outras tarefas abertas do superadmin ficam por atribuir.
    if ($superMember) {
        $q = $pdo->prepare("UPDATE tasks SET member_id = NULL WHERE member_id = ? AND status <> 'done'");
        $q->execute([$superMember]);
        $moved['unassigned_tasks'] = $q->rowCount();
    }
    $summary = json_encode(['at' => now_php(), 'before' => $before, 'after' => $count(), 'moved' => $moved], JSON_UNESCAPED_UNICODE);
    $pdo->prepare($lite ? 'INSERT OR REPLACE INTO settings (skey, svalue) VALUES (?, ?)'
                        : 'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
        ->execute(['tasks_redistribution', $summary]);
}
