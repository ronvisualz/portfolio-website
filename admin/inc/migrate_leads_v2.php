<?php
/**
 * topper. admin — passo 13 da base de dados: aba Leads v2 (§12)
 *
 * Empresa e pessoa de contacto em cada lead (ligadas aos Contactos),
 * estados Novo · Sem resposta · Follow-Up com prazo em horas, tentativas
 * de chamada, último contacto automático, telefones sem o limite de 40
 * caracteres, e tarefas com tipo, hora, duração e repetição. Como os
 * outros passos, verifica antes de mudar e pode correr as vezes que forem;
 * os dados antigos passam para a v2 uma vez por registo.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_add_column
require_once __DIR__ . '/leads.php';

/** O tipo de uma coluna em MySQL («enum('novo',…)», «varchar(40)»). */
function db_column_type(PDO $pdo, string $table, string $column): string {
    $st = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $st->execute([$table, $column]);
    return strtolower((string)$st->fetchColumn());
}

function migrate_leads_v2(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);

    // ── leads: empresa, pessoa, prazo, tentativas ───────────
    db_add_column($pdo, 'deals', 'company',         "VARCHAR(160) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'deals', 'person',          "VARCHAR(160) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'deals', 'company_id',      'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'deals', 'person_id',       'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'deals', 'state_since',     'DATETIME NULL', 'TEXT NULL');
    db_add_column($pdo, 'deals', 'no_answer',       'SMALLINT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'deals', 'last_contact_at', 'DATETIME NULL', 'TEXT NULL');

    if (!$lite) {
        // Os estados novos não cabem no ENUM antigo; vários telefones não cabem em 40 caracteres.
        if (str_starts_with(db_column_type($pdo, 'deals', 'lead_state'), 'enum')) {
            $pdo->exec("ALTER TABLE deals MODIFY lead_state VARCHAR(16) NOT NULL DEFAULT 'novo'");
        }
        if (db_column_type($pdo, 'deals', 'phone') === 'varchar(40)') {
            $pdo->exec("ALTER TABLE deals MODIFY phone VARCHAR(255) NOT NULL DEFAULT ''");
        }
    }

    // ── tarefas: tipo, hora, duração, repetição ─────────────
    if (db_has_table($pdo, 'tasks')) {
        db_add_column($pdo, 'tasks', 'kind',         "VARCHAR(16) NOT NULL DEFAULT 'task'", "TEXT NOT NULL DEFAULT 'task'");
        db_add_column($pdo, 'tasks', 'due_time',     'TIME NULL', 'TEXT NULL');
        db_add_column($pdo, 'tasks', 'duration_min', 'SMALLINT NULL', 'INTEGER NULL');
        db_add_column($pdo, 'tasks', 'repeat_rule',  "VARCHAR(8) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    }

    // ── prazos por estado (horas, 24/7) ─────────────────────
    $set = $lite
        ? $pdo->prepare('INSERT OR IGNORE INTO settings (skey, svalue) VALUES (?, ?)')
        : $pdo->prepare('INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
    foreach (LEAD_DEADLINE_DEFAULTS as $state => $hours) $set->execute(['lead_deadline_' . $state, (string)$hours]);

    // ── dados: as leads antigas passam para a v2 ────────────
    leads_v2_convert($pdo);
}
