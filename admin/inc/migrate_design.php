<?php
/**
 * topper. admin — passo 12 da base de dados: a linha de design (§11)
 *
 * Preferências de cada pessoa (modo claro/escuro, menu recolhido, como
 * quer ser tratada), histórico de interações, notificações já lidas e
 * pedidos de recuperação de palavra-passe. Como os outros passos,
 * verifica antes de criar e pode correr as vezes que forem.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column

function migrate_design(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);

    // ── preferências na conta (não só no browser) ───────────
    db_add_column($pdo, 'users', 'display_name', "VARCHAR(80) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'users', 'pref_theme',   "VARCHAR(8) NOT NULL DEFAULT ''",  "TEXT NOT NULL DEFAULT ''");   // '' = como o sistema · light · dark
    db_add_column($pdo, 'users', 'pref_nav',     "VARCHAR(8) NOT NULL DEFAULT ''",  "TEXT NOT NULL DEFAULT ''");   // '' = aberto · closed

    // ── histórico de interações (leads, negócios, contactos) ─
    // As mudanças de etapa, conversões, etc. já ficam em deal_history;
    // aqui ficam as interações registadas à mão (chamada, email, reunião…).
    db_create($pdo, 'interactions', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id     INT UNSIGNED NULL,
        contact_id  INT UNSIGNED NULL,
        kind        VARCHAR(16)  NOT NULL DEFAULT 'note',
        body        TEXT         NOT NULL,
        happened_at DATETIME     NOT NULL,
        user_id     INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_int_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE,
        CONSTRAINT fk_int_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
        INDEX idx_deal (deal_id, happened_at),
        INDEX idx_contact (contact_id, happened_at)");

    // ── notificações já vistas (os alertas calculam-se; aqui só o «lido») ─
    db_create($pdo, 'notification_reads', "
        id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id  INT UNSIGNED NOT NULL,
        nkey     VARCHAR(120) NOT NULL,
        read_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_read (user_id, nkey)");

    // ── recuperar palavra-passe ─────────────────────────────
    // Só se guarda o hash do código; o código vai por email e vale 30 min.
    db_create($pdo, 'password_resets', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id    INT UNSIGNED NOT NULL,
        token_hash CHAR(64)     NOT NULL,
        expires_at DATETIME     NOT NULL,
        used_at    DATETIME     NULL,
        ip         VARBINARY(16) NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_token (token_hash),
        INDEX idx_user (user_id, created_at)");

    // ── limites dos alertas ─────────────────────────────────
    $set = $lite
        ? $pdo->prepare('INSERT OR IGNORE INTO settings (skey, svalue) VALUES (?, ?)')
        : $pdo->prepare('INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
    $set->execute(['deal_stale_days', '10']);       // negócio sem movimento no Pipeline
    $set->execute(['task_due_days', '2']);          // tarefa a vencer
    $set->execute(['invoice_overdue_days', '30']);  // por receber em atraso
}
