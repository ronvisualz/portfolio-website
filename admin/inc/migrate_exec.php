<?php
/**
 * topper. admin — passo 34 da base de dados: Dashboard Executivo (§30)
 *
 *   fin_assets      registo de ativos (equipamento, viaturas, obras…): valor, data, taxa de amortização
 *                   (dos parâmetros) — dá as amortizações mensais do resultado (EBIT)
 *   fin_loans       empréstimos e leasings: capital, taxa, prazo, início — dá os juros mensais (Net Profit) e
 *                   gera as prestações «Por pagar» (fin_docs.loan_id; o capital não é custo)
 *   exec_months     a fotografia mensal do resultado (P&L), refeita pelo cron: o Dashboard lê daqui
 *   exec_reading    a leitura do mês escrita de manhã (com e sem euros) e os números em que se baseou
 *   exec_hof        o hall of fame: pódio por mês, entradas no pódio, recordes, comercial e destaque de produção
 *   team_posts      os avisos da equipa no chat (pódio, recorde, comercial do mês)
 *   fin_params      horas produtivas por ano (custo/hora interno a partir do salário)
 *
 * Como os outros passos, verifica antes de mudar e pode correr as vezes que forem.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column

function migrate_exec_34(PDO $pdo): void {
    db_create($pdo, 'fin_assets', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(160) NOT NULL,
        kind         VARCHAR(12)  NOT NULL DEFAULT 'it',
        value        DECIMAL(12,2) NOT NULL DEFAULT 0,
        acquired_on  DATE         NOT NULL,
        rate_pct     DECIMAL(5,2) NOT NULL DEFAULT 0,
        car_motor    VARCHAR(8)   NOT NULL DEFAULT '',
        car_phev_ok  TINYINT(1)   NOT NULL DEFAULT 0,
        ended_on     DATE         NULL,
        doc_id       INT UNSIGNED NULL,
        notes        VARCHAR(500) NOT NULL DEFAULT '',
        created_by   INT UNSIGNED NULL,
        created_at   DATETIME     NOT NULL,
        updated_at   DATETIME     NOT NULL,
        deleted_at   DATETIME     NULL");
    db_create($pdo, 'fin_loans', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(160) NOT NULL,
        kind         VARCHAR(8)   NOT NULL DEFAULT 'loan',
        lender       VARCHAR(190) NOT NULL DEFAULT '',
        principal    DECIMAL(14,2) NOT NULL DEFAULT 0,
        rate_pct     DECIMAL(6,3) NOT NULL DEFAULT 0,
        months       SMALLINT     NOT NULL DEFAULT 12,
        start_date   DATE         NOT NULL,
        due_day      TINYINT      NOT NULL DEFAULT 1,
        residual     DECIMAL(14,2) NOT NULL DEFAULT 0,
        asset_id     INT UNSIGNED NULL,
        gen_docs     TINYINT(1)   NOT NULL DEFAULT 1,
        notes        VARCHAR(500) NOT NULL DEFAULT '',
        created_by   INT UNSIGNED NULL,
        created_at   DATETIME     NOT NULL,
        updated_at   DATETIME     NOT NULL,
        deleted_at   DATETIME     NULL");
    if (db_has_table($pdo, 'fin_docs')) db_add_column($pdo, 'fin_docs', 'loan_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_create($pdo, 'exec_months', "
        ym          CHAR(7)      NOT NULL PRIMARY KEY,
        data        MEDIUMTEXT   NOT NULL,
        updated_at  DATETIME     NOT NULL");
    db_create($pdo, 'exec_reading', "
        day         DATE         NOT NULL PRIMARY KEY,
        body_fin    TEXT         NOT NULL,
        body        TEXT         NOT NULL,
        facts       MEDIUMTEXT   NOT NULL,
        by_ai       TINYINT(1)   NOT NULL DEFAULT 0,
        created_at  DATETIME     NOT NULL");
    db_create($pdo, 'exec_hof', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ym           CHAR(7)      NOT NULL,
        kind         VARCHAR(12)  NOT NULL,
        rank_no      TINYINT      NOT NULL DEFAULT 1,
        deal_id      INT UNSIGNED NULL,
        user_id      INT UNSIGNED NULL,
        value        DECIMAL(14,2) NOT NULL DEFAULT 0,
        provisional  TINYINT(1)   NOT NULL DEFAULT 0,
        data         TEXT         NULL,
        created_at   DATETIME     NOT NULL,
        UNIQUE KEY uq_hof (ym, kind, rank_no),
        INDEX idx_hof_kind (kind, created_at)");
    db_create($pdo, 'team_posts', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kind         VARCHAR(12)  NOT NULL DEFAULT 'note',
        title        VARCHAR(200) NOT NULL,
        body         TEXT         NULL,
        body_fin     TEXT         NULL,
        url          VARCHAR(300) NOT NULL DEFAULT '',
        created_at   DATETIME     NOT NULL,
        INDEX idx_tp (created_at)");

    if (db_has_table($pdo, 'fin_params')) {
        $have = (int)$pdo->query("SELECT COUNT(*) FROM fin_params WHERE pkey = 'productive_hours'")->fetchColumn();
        if (!$have) {
            $pos = (int)$pdo->query("SELECT COALESCE(MAX(position), 0) FROM fin_params WHERE grp = 'Salários'")->fetchColumn();
            $pdo->prepare('INSERT INTO fin_params (pkey, grp, label, value, unit, source, year, verified_on, admin_only, position, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(['productive_hours', 'Salários', 'Horas produtivas por pessoa e por ano (custo/hora interno)', '1600', 'h/ano',
                           'Política da empresa (horas faturáveis por ano, sem férias, feriados e tempo interno)', 2026, '2026-10-24', 1, $pos + 1, date('Y-m-d H:i:s')]);
        }
        // o custo/hora interno de quem já tem ficha de salário passa a vir do salário (§30.2)
        require_once __DIR__ . '/exec.php';
        foreach ($pdo->query('SELECT user_id FROM fin_employees WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN) as $uid) exec_sync_hourly($pdo, (int)$uid);
    }
}
