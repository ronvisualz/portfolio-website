<?php
/**
 * topper. admin — passo 16 da base de dados: aba Orçamentação (§14)
 *
 * Uma folha de orçamento por negócio (com versões, linhas de custo e
 * histórico), modelos de orçamento, o custo/hora interno e as horas
 * disponíveis por mês de cada pessoa da Equipa, a ligação de cada custo
 * do Financeiro a uma linha do orçamento (para o Resultado) e as
 * definições globais (MSO, margem, horas por dia, meta mensal). Como os
 * outros passos, verifica antes de mudar e pode correr as vezes que forem.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column

function migrate_budget(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);

    // ── Equipa: custo/hora interno e horas disponíveis por mês ──
    db_add_column($pdo, 'users', 'hourly_cost',   'DECIMAL(10,2) NULL', 'REAL NULL');
    db_add_column($pdo, 'users', 'monthly_hours', 'DECIMAL(6,1) NULL',  'REAL NULL');

    // ── a linha do orçamento a que um custo do negócio corresponde ──
    db_add_column($pdo, 'costs', 'budget_key', 'VARCHAR(12) NULL', 'TEXT NULL');

    db_create($pdo, 'budget_sheets', "
        id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id             INT UNSIGNED NOT NULL,
        accepted_version_id INT UNSIGNED NULL,
        closed_at           DATETIME     NULL,
        result_json         MEDIUMTEXT   NULL,
        created_by          INT UNSIGNED NULL,
        created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_deal (deal_id),
        CONSTRAINT fk_bs_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE CASCADE");

    db_create($pdo, 'budget_versions', "
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sheet_id       INT UNSIGNED NOT NULL,
        num            SMALLINT     NOT NULL DEFAULT 1,
        description    TEXT         NULL,
        conditions     TEXT         NULL,
        dur_mode       VARCHAR(8)   NOT NULL DEFAULT 'days',
        start_date     DATE         NULL,
        end_date       DATE         NULL,
        weeks          DECIMAL(6,2) NULL,
        days           DECIMAL(7,1) NULL,
        phases         TEXT         NULL,
        fixed_monthly  DECIMAL(12,2) NOT NULL DEFAULT 0,
        fixed_manual   TINYINT(1)   NOT NULL DEFAULT 0,
        occupancy      DECIMAL(5,2) NULL,
        margin         DECIMAL(5,2) NOT NULL DEFAULT 65,
        mso_pct        DECIMAL(5,2) NOT NULL DEFAULT 5,
        day_hours      DECIMAL(4,2) NOT NULL DEFAULT 8,
        price          DECIMAL(12,2) NOT NULL DEFAULT 0,
        base           DECIMAL(12,2) NOT NULL DEFAULT 0,
        profit_month   DECIMAL(12,2) NOT NULL DEFAULT 0,
        work_days      DECIMAL(7,1) NOT NULL DEFAULT 0,
        created_by     INT UNSIGNED NULL,
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        accepted_at    DATETIME     NULL,
        accepted_by    INT UNSIGNED NULL,
        CONSTRAINT fk_bv_sheet FOREIGN KEY (sheet_id) REFERENCES budget_sheets(id) ON DELETE CASCADE,
        INDEX idx_sheet (sheet_id, num)");

    db_create($pdo, 'budget_lines', "
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        version_id  INT UNSIGNED NOT NULL,
        line_key    VARCHAR(12)  NOT NULL,
        position    INT          NOT NULL DEFAULT 0,
        kind        VARCHAR(8)   NOT NULL,
        service_id  INT UNSIGNED NULL,
        contact_id  INT UNSIGNED NULL,
        user_id     INT UNSIGNED NULL,
        label       VARCHAR(160) NOT NULL DEFAULT '',
        price_unit  VARCHAR(16)  NOT NULL DEFAULT 'fixed',
        unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0,
        qty         DECIMAL(10,2) NOT NULL DEFAULT 1,
        qty_unit    VARCHAR(16)  NOT NULL DEFAULT 'fixed',
        cost        DECIMAL(12,2) NOT NULL DEFAULT 0,
        note        VARCHAR(255) NOT NULL DEFAULT '',
        CONSTRAINT fk_bl_version FOREIGN KEY (version_id) REFERENCES budget_versions(id) ON DELETE CASCADE,
        INDEX idx_version (version_id, position),
        INDEX idx_key (line_key)");

    db_create($pdo, 'budget_history', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sheet_id   INT UNSIGNED NOT NULL,
        event      VARCHAR(20)  NOT NULL,
        note       VARCHAR(255) NOT NULL DEFAULT '',
        user_id    INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_bh_sheet FOREIGN KEY (sheet_id) REFERENCES budget_sheets(id) ON DELETE CASCADE,
        INDEX idx_sheet (sheet_id, created_at)");

    db_create($pdo, 'budget_templates', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(120) NOT NULL,
        data       MEDIUMTEXT   NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP");

    // ── definições globais (Definições, só superadmin e admins) ──
    $set = $lite
        ? $pdo->prepare('INSERT OR IGNORE INTO settings (skey, svalue) VALUES (?, ?)')
        : $pdo->prepare('INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
    foreach (['budget_mso_pct' => '5', 'budget_margin_pct' => '65', 'budget_day_hours' => '8', 'budget_month_target' => '8000',
              'budget_conditions' => "Proposta válida por 30 dias.\nPagamento: 50% na adjudicação e 50% na entrega.\nValores sem IVA; acresce IVA à taxa legal em vigor."] as $k => $v) {
        $set->execute([$k, $v]);
    }

    // ── cada negócio tem uma folha (§14.1): também os que já existem ──
    require_once __DIR__ . '/budget.php';
    foreach ($pdo->query("SELECT d.* FROM deals d LEFT JOIN budget_sheets s ON s.deal_id = d.id
                           WHERE d.code IS NOT NULL AND d.phase IN ('pipeline','production','done','lost')
                             AND (s.id IS NULL OR NOT EXISTS (SELECT 1 FROM budget_versions v WHERE v.sheet_id = s.id))")->fetchAll() as $d) {
        budget_sheet_ensure($pdo, $d, null);
    }
}
