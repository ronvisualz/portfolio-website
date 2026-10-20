<?php
/**
 * topper. admin — passo 08 da base de dados: a plataforma
 *
 * Utilizadores com papéis e departamentos, permissões, funis e etapas,
 * leads e negócios num só registo (com ID), histórico de etapas,
 * contactos e custos. Como os outros passos, verifica antes de criar
 * e pode correr as vezes que forem.
 *
 * As instruções estão escritas para MySQL; sqlite_ddl() adapta-as à
 * bancada de testes local.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/perm.php';

/** Traduz um CREATE TABLE de MySQL para SQLite (só o que aqui se usa). */
function sqlite_ddl(string $sql): string {
    $sql = preg_replace('/INT UNSIGNED AUTO_INCREMENT PRIMARY KEY/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace("/ENUM\\([^)]*\\)/", 'TEXT', $sql);
    $sql = preg_replace('/ ON UPDATE CURRENT_TIMESTAMP/', '', $sql);
    $sql = preg_replace('/,\s*(INDEX|KEY) \w+ \([^)]*\)/', '', $sql);
    $sql = preg_replace('/UNIQUE KEY \w+ /', 'UNIQUE ', $sql);
    $sql = preg_replace('/,\s*CONSTRAINT \w+ FOREIGN KEY \(\w+\)\s*REFERENCES \w+\(\w+\)( ON DELETE (SET NULL|CASCADE|RESTRICT))?/', '', $sql);
    $sql = preg_replace('/\)\s*ENGINE=.*$/s', ')', $sql);
    return $sql;
}

function db_create(PDO $pdo, string $table, string $body): void {
    if (db_has_table($pdo, $table)) return;
    $sql = "CREATE TABLE $table (\n$body\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec(db_is_sqlite($pdo) ? sqlite_ddl($sql) : $sql);
}

function db_add_column(PDO $pdo, string $table, string $column, string $mysqlDef, string $sqliteDef): void {
    if (db_has_column($pdo, $table, $column)) return;
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column " . (db_is_sqlite($pdo) ? $sqliteDef : $mysqlDef));
}

function migrate_platform(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);

    // ── departamentos ───────────────────────────────────────
    db_create($pdo, 'departments', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(80)  NOT NULL,
        head_user_id INT UNSIGNED NULL,
        position     INT          NOT NULL DEFAULT 0,
        created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP");
    if ((int)$pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO departments (name, position) VALUES (?, ?)');
        foreach (['Comercial', 'Orçamentação', 'VFX', 'Desenvolvimento'] as $i => $n) {
            $ins->execute([$n, $i + 1]);
        }
    }

    // ── utilizadores: papel, departamento, função, cor ──────
    // O papel passa de ENUM para texto: superadmin · admin · lead · member.
    if (!$lite) {
        $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'member'");
    }
    db_add_column($pdo, 'users', 'department_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'users', 'job_title',     "VARCHAR(80) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'users', 'color',         "VARCHAR(16) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'users', 'position',      'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'users', 'deactivated_at','DATETIME NULL', 'TEXT NULL');
    db_add_column($pdo, 'users', 'created_by',    'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'users', 'team_member_id','INT UNSIGNED NULL', 'INTEGER NULL');

    // Quem não tem um papel conhecido fica admin (hoje todos entram como admin).
    $pdo->exec("UPDATE users SET role = 'admin'
                 WHERE role NOT IN ('superadmin','admin','lead','member') OR role IS NULL");
    // A conta única de superadmin.
    $pdo->prepare("UPDATE users SET role = 'admin' WHERE role = 'superadmin' AND LOWER(email) <> ?")
        ->execute([SUPERADMIN_EMAIL]);
    $pdo->prepare("UPDATE users SET role = 'superadmin' WHERE LOWER(email) = ?")
        ->execute([SUPERADMIN_EMAIL]);

    // ── permissões ──────────────────────────────────────────
    // Linha de papel: user_id = 0. Exceção de um utilizador: role = ''.
    // level: 0 sem acesso · 1 ver · 2 editar · 3 gerir.
    // scope (só Leads, Pipeline, Negócios, Tarefas): own · dept · all.
    db_create($pdo, 'permissions', "
        id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        role     VARCHAR(20)  NOT NULL DEFAULT '',
        user_id  INT UNSIGNED NOT NULL DEFAULT 0,
        tab      VARCHAR(32)  NOT NULL,
        level    TINYINT      NOT NULL DEFAULT 0,
        scope    VARCHAR(8)   NULL,
        UNIQUE KEY uq_perm (role, user_id, tab)");

    // ── funis e etapas ──────────────────────────────────────
    db_create($pdo, 'funnels', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(120) NOT NULL,
        is_base    TINYINT(1)   NOT NULL DEFAULT 0,
        made_from  VARCHAR(255) NOT NULL DEFAULT '',
        position   INT          NOT NULL DEFAULT 0,
        is_active  TINYINT(1)   NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP");

    // kind: xray e kickoff e done são fixas; commercial e production são as variáveis.
    db_create($pdo, 'funnel_stages', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        funnel_id  INT UNSIGNED NOT NULL,
        name       VARCHAR(120) NOT NULL,
        kind       ENUM('xray','commercial','kickoff','production','done') NOT NULL,
        position   INT          NOT NULL DEFAULT 0,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_stage_funnel FOREIGN KEY (funnel_id) REFERENCES funnels(id) ON DELETE CASCADE,
        INDEX idx_funnel (funnel_id, position)");

    // Automações: tarefas a criar quando um negócio entra numa etapa.
    // Só a estrutura — a lógica entra numa fase seguinte.
    db_create($pdo, 'stage_automations', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        stage_id    INT UNSIGNED NOT NULL,
        title       VARCHAR(200) NOT NULL,
        description TEXT         NULL,
        assign_to   VARCHAR(20)  NOT NULL DEFAULT 'owner',
        due_days    INT          NULL,
        position    INT          NOT NULL DEFAULT 0,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        CONSTRAINT fk_auto_stage FOREIGN KEY (stage_id) REFERENCES funnel_stages(id) ON DELETE CASCADE,
        INDEX idx_stage (stage_id)");

    if ((int)$pdo->query('SELECT COUNT(*) FROM funnels')->fetchColumn() === 0) {
        $f = $pdo->prepare('INSERT INTO funnels (name, is_base, position) VALUES (?, 1, ?)');
        $s = $pdo->prepare('INSERT INTO funnel_stages (funnel_id, name, kind, position) VALUES (?,?,?,?)');
        foreach (['VFX / 3D', 'CGI', 'Campanhas', 'Ads', 'Marketing Buddy'] as $i => $name) {
            $f->execute([$name, $i + 1]);
            $id = (int)$pdo->lastInsertId();
            $s->execute([$id, 'X-Ray',     'xray',    10]);
            $s->execute([$id, 'Kickoff',   'kickoff', 50]);
            $s->execute([$id, 'Concluído', 'done',    90]);
        }
    }

    // ── contactos ───────────────────────────────────────────
    db_create($pdo, 'contacts', "
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind          ENUM('person','company') NOT NULL DEFAULT 'company',
        name          VARCHAR(160) NOT NULL,
        company_id    INT UNSIGNED NULL,
        is_client     TINYINT(1)   NOT NULL DEFAULT 0,
        is_supplier   TINYINT(1)   NOT NULL DEFAULT 0,
        is_partner    TINYINT(1)   NOT NULL DEFAULT 0,
        is_freelancer TINYINT(1)   NOT NULL DEFAULT 0,
        category      VARCHAR(40)  NOT NULL DEFAULT '',
        service       VARCHAR(160) NOT NULL DEFAULT '',
        contact_person VARCHAR(160) NOT NULL DEFAULT '',
        email         VARCHAR(190) NOT NULL DEFAULT '',
        phone         VARCHAR(40)  NOT NULL DEFAULT '',
        nif           VARCHAR(20)  NOT NULL DEFAULT '',
        website       VARCHAR(255) NOT NULL DEFAULT '',
        rate          VARCHAR(120) NOT NULL DEFAULT '',
        rating        TINYINT      NULL,
        last_job      VARCHAR(160) NOT NULL DEFAULT '',
        notes         TEXT         NULL,
        created_by    INT UNSIGNED NULL,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_phone (phone)");

    // ── leads e negócios: um só registo ─────────────────────
    // phase: lead → pipeline → production → done; ou archived / lost.
    // code (ID2610-01) é atribuído na conversão e nunca muda.
    db_create($pdo, 'deals', "
        id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code             VARCHAR(16)  NULL,
        phase            ENUM('lead','pipeline','production','done','lost','archived') NOT NULL DEFAULT 'lead',
        lead_state       ENUM('novo','contactado','em_conversa') NOT NULL DEFAULT 'novo',
        name             VARCHAR(160) NOT NULL,
        types            VARCHAR(255) NOT NULL DEFAULT '',
        links            TEXT         NULL,
        phone            VARCHAR(40)  NOT NULL DEFAULT '',
        email            VARCHAR(190) NOT NULL DEFAULT '',
        service          VARCHAR(40)  NOT NULL DEFAULT '',
        priority         ENUM('alta','media','baixa') NOT NULL DEFAULT 'media',
        detail           TEXT         NULL,
        why              TEXT         NULL,
        notes            TEXT         NULL,
        origin           ENUM('manual','site','anuncio') NOT NULL DEFAULT 'manual',
        campaign         VARCHAR(160) NOT NULL DEFAULT '',
        is_quarantined   TINYINT(1)   NOT NULL DEFAULT 0,
        owner_id         INT UNSIGNED NULL,
        created_by       INT UNSIGNED NULL,
        last_touch       DATE         NULL,
        contact_id       INT UNSIGNED NULL,
        funnel_id        INT UNSIGNED NULL,
        origin_funnel_id INT UNSIGNED NULL,
        stage_id         INT UNSIGNED NULL,
        value_estimate   DECIMAL(12,2) NULL,
        converted_at     DATETIME     NULL,
        lost_reason      VARCHAR(255) NOT NULL DEFAULT '',
        lost_stage_id    INT UNSIGNED NULL,
        lost_at          DATETIME     NULL,
        archived_reason  VARCHAR(40)  NOT NULL DEFAULT '',
        archived_note    VARCHAR(255) NOT NULL DEFAULT '',
        archived_at      DATETIME     NULL,
        done_at          DATETIME     NULL,
        job_type         VARCHAR(40)  NOT NULL DEFAULT '',
        year             SMALLINT     NULL,
        month            TINYINT      NULL,
        budget           DECIMAL(12,2) NOT NULL DEFAULT 0,
        is_international TINYINT(1)   NOT NULL DEFAULT 0,
        has_vat          TINYINT(1)   NOT NULL DEFAULT 1,
        is_paid          TINYINT(1)   NOT NULL DEFAULT 0,
        expenses_mode    ENUM('crew','manual') NOT NULL DEFAULT 'crew',
        expenses_manual  DECIMAL(12,2) NOT NULL DEFAULT 0,
        drive_folder     VARCHAR(160) NOT NULL DEFAULT '',
        created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_code (code),
        INDEX idx_phase (phase, lead_state),
        INDEX idx_owner (owner_id),
        INDEX idx_funnel (funnel_id, stage_id),
        INDEX idx_email (email),
        INDEX idx_phone (phone)");

    // Sequência dos IDs por mês (2610 → última sequência usada).
    db_create($pdo, 'deal_codes', "
        yymm     CHAR(4)  NOT NULL PRIMARY KEY,
        last_seq INT      NOT NULL DEFAULT 0");

    // Contactos ligados a um negócio (um contacto pode estar em vários).
    db_create($pdo, 'deal_contacts', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id    INT UNSIGNED NOT NULL,
        contact_id INT UNSIGNED NOT NULL,
        role       VARCHAR(80)  NOT NULL DEFAULT '',
        CONSTRAINT fk_dc_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE,
        CONSTRAINT fk_dc_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
        UNIQUE KEY uq_dc (deal_id, contact_id)");

    // Histórico: mudanças de etapa, de funil, conversão, perdido, reaberto…
    db_create($pdo, 'deal_history', "
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id        INT UNSIGNED NOT NULL,
        event          VARCHAR(20)  NOT NULL,
        from_funnel_id INT UNSIGNED NULL,
        from_stage_id  INT UNSIGNED NULL,
        to_funnel_id   INT UNSIGNED NULL,
        to_stage_id    INT UNSIGNED NULL,
        note           VARCHAR(255) NOT NULL DEFAULT '',
        user_id        INT UNSIGNED NULL,
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_hist_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE,
        INDEX idx_deal (deal_id, created_at)");

    // ── custos: de um negócio (deal_id) ou da empresa (deal_id vazio) ──
    db_create($pdo, 'costs', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id    INT UNSIGNED NULL,
        year       SMALLINT     NULL,
        month      TINYINT      NULL,
        name       VARCHAR(160) NOT NULL,
        role       VARCHAR(120) NOT NULL DEFAULT '',
        contact_id INT UNSIGNED NULL,
        amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
        vat_mode   ENUM('none','plus','incl') NOT NULL DEFAULT 'plus',
        is_closed  TINYINT(1)   NOT NULL DEFAULT 0,
        notes      TEXT         NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_cost_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE,
        INDEX idx_deal (deal_id),
        INDEX idx_year (year, month)");

    // ── tarefas e eventos ligados pelo ID do negócio ────────
    db_add_column($pdo, 'tasks',  'deal_id',     'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'tasks',  'assignee_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'tasks',  'created_by',  'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'events', 'deal_id',     'INT UNSIGNED NULL', 'INTEGER NULL');

    // ── definições ──────────────────────────────────────────
    $set = $lite
        ? $pdo->prepare('INSERT OR IGNORE INTO settings (skey, svalue) VALUES (?, ?)')
        : $pdo->prepare('INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
    $set->execute(['lead_stale_days', '14']);
}
