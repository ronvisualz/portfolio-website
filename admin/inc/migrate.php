<?php
/**
 * topper. admin — actualização automática da base de dados
 *
 * Corre sozinho, uma vez, quando o site nota que a base está
 * atrasada em relação ao código. Não há nada a fazer no phpMyAdmin.
 *
 * Cada passo verifica primeiro se já está feito, por isso pode
 * correr as vezes que forem: nunca duplica nem estraga nada. No
 * fim guarda a versão em settings.schema_version e deixa de correr.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** A versão que este código espera encontrar. */
const SCHEMA_VERSION = 5;

function db_has_table(PDO $pdo, string $table): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function db_has_column(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

function run_migrations(PDO $pdo): void {

    // ── 01 · «Tem IVA» em cada registo ──────────────────────
    if (!db_has_column($pdo, 'projects', 'has_vat')) {
        $pdo->exec(
            'ALTER TABLE projects
               ADD COLUMN has_vat TINYINT(1) NOT NULL DEFAULT 1 AFTER is_international'
        );
        // Os trabalhos de exportação não levavam IVA: preserva-se o que
        // o campo «internacional» significava até aqui.
        $pdo->exec('UPDATE projects SET has_vat = 0 WHERE is_international = 1');
    }

    // ── 02 · o valor de cada custo diz o que é ──────────────
    if (!db_has_column($pdo, 'crew', 'vat_mode')) {
        $pdo->exec(
            "ALTER TABLE crew
               ADD COLUMN vat_mode ENUM('none','plus','incl') NOT NULL DEFAULT 'plus' AFTER fee"
        );
        if (db_has_column($pdo, 'crew', 'invoices_vat')) {
            $pdo->exec("UPDATE crew SET vat_mode = 'none' WHERE invoices_vat = 0");
        }
    }
    if (db_has_column($pdo, 'crew', 'invoices_vat')) {
        $pdo->exec('ALTER TABLE crew DROP COLUMN invoices_vat');
    }

    // ── 03 · custos da empresa ──────────────────────────────
    if (!db_has_table($pdo, 'company_costs')) {
        $pdo->exec(
            "CREATE TABLE company_costs (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               year       SMALLINT     NOT NULL,
               month      TINYINT      NULL,
               name       VARCHAR(160) NOT NULL,
               amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
               vat_mode   ENUM('none','plus','incl') NOT NULL DEFAULT 'plus',
               is_paid    TINYINT(1)   NOT NULL DEFAULT 0,
               notes      TEXT         NULL,
               created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               INDEX idx_year (year)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // ── 04 · calendário ─────────────────────────────────────
    if (!db_has_table($pdo, 'events')) {
        $pdo->exec(
            "CREATE TABLE events (
               id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
               title      VARCHAR(160) NOT NULL,
               event_date DATE         NOT NULL,
               start_time TIME         NULL,
               end_time   TIME         NULL,
               project_id INT UNSIGNED NULL,
               location   VARCHAR(160) NOT NULL DEFAULT '',
               notes      TEXT         NULL,
               created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
               CONSTRAINT fk_event_project FOREIGN KEY (project_id)
                 REFERENCES projects(id) ON DELETE SET NULL,
               INDEX idx_date (event_date),
               INDEX idx_project (project_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // ── 05 · os gastos antigos mudam-se para a estrutura ────
    //
    // A Carteira passou a mostrar só trabalhos. Os registos antigos
    // com kind='expense' — contabilidade, alojamento, abertura da
    // empresa — deixariam de aparecer em lado nenhum, por isso
    // passam para company_costs, que é onde vivem agora.
    $antigos = $pdo->query(
        "SELECT id, year, start_month, name, expenses_manual, has_vat, notes
           FROM projects WHERE kind = 'expense'"
    )->fetchAll();

    if ($antigos) {
        $ins = $pdo->prepare(
            'INSERT INTO company_costs (year, month, name, amount, vat_mode, is_paid, notes)
             VALUES (?,?,?,?,?,0,?)'
        );
        $del = $pdo->prepare('DELETE FROM projects WHERE id = ?');

        foreach ($antigos as $g) {
            $ins->execute([
                (int)$g['year'],
                $g['start_month'] !== null ? (int)$g['start_month'] : null,
                $g['name'],
                (float)$g['expenses_manual'],
                (int)$g['has_vat'] === 1 ? 'plus' : 'none',
                $g['notes'],
            ]);
            $del->execute([(int)$g['id']]);
        }
    }

    $pdo->prepare(
        "INSERT INTO settings (skey, svalue) VALUES ('schema_version', ?)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)"
    )->execute([(string)SCHEMA_VERSION]);
}
