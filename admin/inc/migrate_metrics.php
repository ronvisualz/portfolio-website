<?php
/**
 * topper. admin — passo 33 da base de dados: Relatórios (§29.11)
 *
 *   stage_events     o histórico de etapas estruturado (lead, abordada, X-Ray, proposta, aguardar
 *                    feedback, kickoff, produção, concluído, perdido, arquivado, reaberto), com quem,
 *                    quando e o motivo da perda; preenchido daqui em diante (deal_log) e reconstruído
 *                    uma vez do deal_history / interações para o que já existia
 *   deals            primeira resposta (quando, canal, quem), fonte e UTM; canais novos na origem
 *   fin_docs         canal e campanha de aquisição (custo por lead e por kickoff)
 *   funnel_stages    limite de dias numa etapa (para «parado»; vazio = média histórica × 1,5)
 *   metric_goals     metas por mês/trimestre, para a empresa, um funil ou uma pessoa
 *   metrics_daily    a fotografia diária das métricas principais (cron)
 *   metrics_weekly   o resumo semanal (segunda às 9h) da equipa e de cada pessoa
 *
 * Como os outros passos, verifica antes de mudar e pode correr as vezes que forem.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column

function migrate_metrics_33(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);
    db_create($pdo, 'stage_events', "
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        deal_id       INT UNSIGNED NOT NULL,
        line_id       INT UNSIGNED NULL,
        step          VARCHAR(12)  NOT NULL,
        funnel_id     INT UNSIGNED NULL,
        from_stage_id INT UNSIGNED NULL,
        to_stage_id   INT UNSIGNED NULL,
        at            DATETIME     NOT NULL,
        user_id       INT UNSIGNED NULL,
        reason        VARCHAR(255) NOT NULL DEFAULT '',
        source        VARCHAR(8)   NOT NULL DEFAULT 'live',
        INDEX idx_se_deal (deal_id, at), INDEX idx_se_step (step, at)");

    db_add_column($pdo, 'deals', 'first_response_at', 'DATETIME NULL', 'TEXT NULL');
    db_add_column($pdo, 'deals', 'first_response_channel', "VARCHAR(12) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'deals', 'first_response_by', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'deals', 'source', "VARCHAR(160) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'deals', 'utm', "VARCHAR(600) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    if (!$lite && str_starts_with(db_column_type($pdo, 'deals', 'origin'), 'enum')) {
        // canais novos (parceiro, indicação de cliente, evento, prospeção ativa): a origem passa a texto curto
        $pdo->exec("ALTER TABLE deals MODIFY origin VARCHAR(12) NOT NULL DEFAULT 'manual'");
    }
    if (db_has_table($pdo, 'fin_docs')) {
        db_add_column($pdo, 'fin_docs', 'acq_channel', "VARCHAR(12) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
        db_add_column($pdo, 'fin_docs', 'acq_source', "VARCHAR(160) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    }
    db_add_column($pdo, 'funnel_stages', 'max_days', 'SMALLINT NULL', 'INTEGER NULL');

    db_create($pdo, 'metric_goals', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scope        VARCHAR(8)    NOT NULL DEFAULT 'company',
        scope_id     INT UNSIGNED  NOT NULL DEFAULT 0,
        period       VARCHAR(8)    NOT NULL DEFAULT 'month',
        period_start DATE          NOT NULL,
        metric       VARCHAR(16)   NOT NULL,
        target       DECIMAL(14,2) NOT NULL DEFAULT 0,
        is_auto      TINYINT(1)    NOT NULL DEFAULT 0,
        created_by   INT UNSIGNED  NULL,
        updated_at   DATETIME      NOT NULL,
        UNIQUE KEY uq_goal (scope, scope_id, period, period_start, metric)");
    db_create($pdo, 'metrics_daily', "
        day      DATE          NOT NULL,
        mkey     VARCHAR(40)   NOT NULL,
        scope    VARCHAR(8)    NOT NULL DEFAULT '',
        scope_id INT UNSIGNED  NOT NULL DEFAULT 0,
        value    DECIMAL(16,4) NOT NULL DEFAULT 0,
        PRIMARY KEY (day, mkey, scope, scope_id)");
    db_create($pdo, 'metrics_weekly', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        week       DATE         NOT NULL,
        user_id    INT UNSIGNED NOT NULL DEFAULT 0,
        body       TEXT         NOT NULL,
        todo       TEXT         NOT NULL,
        by_ai      TINYINT(1)   NOT NULL DEFAULT 0,
        created_at DATETIME     NOT NULL,
        sent_at    DATETIME     NULL,
        UNIQUE KEY uq_week (week, user_id)");

    // o canal das leads que já existiam: quem as trouxe diz se foi um parceiro ou um cliente
    $pdo->exec("UPDATE deals SET origin = 'parceiro' WHERE origin = 'manual' AND brought_by_id IN (SELECT id FROM contacts WHERE is_partner = 1)");
    $pdo->exec("UPDATE deals SET origin = 'indicacao' WHERE origin = 'manual' AND brought_by_id IN (SELECT id FROM contacts WHERE is_client = 1)");

    require_once __DIR__ . '/metrics.php';
    metrics_rebuild($pdo);
}
