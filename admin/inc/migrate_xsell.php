<?php
/**
 * topper. admin — passo 23 da base de dados: crosselling (um negócio, várias frentes)
 *
 *  · deal_lines: os serviços de um negócio de crosselling (o principal incluído). Na fase
 *    comercial são só a lista; no Kickoff cada um passa a uma frente com etapa própria no
 *    funil do seu serviço, responsável de execução, arranque e entrega.
 *  · deals: sync_mode ('' · sync · independent), xsell_from_id (crosselling posterior: o
 *    negócio anterior do cliente) e package_id (o pacote escolhido).
 *  · packages: pacotes pré-definidos (serviços, sincronização por defeito, modelo de orçamento).
 *  · xsell_opps: oportunidades (faladas no X-Ray, sugeridas depois da entrega, ou à mão).
 *  · xsell_rules: «depois de entregar X, propor Y ao fim de N dias».
 *  · budget_lines.funnel_id (a linha do orçamento é de que serviço) e tasks.line_id (a frente).
 *
 * Idempotente: corre de novo sem duplicar.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

function migrate_xsell(PDO $pdo): void {
    db_create($pdo, 'deal_lines', "
        id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        deal_id          INT UNSIGNED NOT NULL,
        funnel_id        INT UNSIGNED NOT NULL,
        is_main          TINYINT(1)   NOT NULL DEFAULT 0,
        position         INT          NOT NULL DEFAULT 0,
        stage_id         INT UNSIGNED NULL,
        stage_entered_at DATETIME     NULL,
        status           VARCHAR(10)  NOT NULL DEFAULT 'planned',
        exec_owner_id    INT UNSIGNED NULL,
        start_mode       VARCHAR(8)   NOT NULL DEFAULT 'kickoff',
        start_after_id   INT UNSIGNED NULL,
        start_date       DATE         NULL,
        delivery_date    DATE         NULL,
        started_at       DATETIME     NULL,
        done_at          DATETIME     NULL,
        value            DECIMAL(12,2) NULL,
        created_at       DATETIME     NOT NULL,
        UNIQUE KEY deal_funnel (deal_id, funnel_id),
        KEY stage (stage_id),
        KEY funnel (funnel_id)");
    db_add_column($pdo, 'deals', 'sync_mode', "VARCHAR(12) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'deals', 'xsell_from_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'deals', 'package_id', 'INT UNSIGNED NULL', 'INTEGER NULL');

    db_create($pdo, 'packages', "
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(120) NOT NULL,
        funnels     VARCHAR(255) NOT NULL DEFAULT '',
        sync_mode   VARCHAR(12)  NOT NULL DEFAULT 'sync',
        template_id INT UNSIGNED NULL,
        is_active   TINYINT(1)   NOT NULL DEFAULT 1,
        position    INT          NOT NULL DEFAULT 0,
        created_at  DATETIME     NOT NULL");

    db_create($pdo, 'xsell_opps', "
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        contact_id     INT UNSIGNED NULL,
        deal_id        INT UNSIGNED NULL,
        funnel_id      INT UNSIGNED NOT NULL,
        source         VARCHAR(10)  NOT NULL DEFAULT 'manual',
        status         VARCHAR(10)  NOT NULL DEFAULT 'open',
        note           VARCHAR(255) NOT NULL DEFAULT '',
        task_id        INT UNSIGNED NULL,
        result_deal_id INT UNSIGNED NULL,
        created_by     INT UNSIGNED NULL,
        created_at     DATETIME     NOT NULL,
        closed_at      DATETIME     NULL,
        KEY contact (contact_id),
        KEY deal (deal_id),
        KEY result (result_deal_id)");

    db_create($pdo, 'xsell_rules', "
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        from_funnel_id INT UNSIGNED NOT NULL,
        to_funnel_id   INT UNSIGNED NOT NULL,
        days           INT          NOT NULL DEFAULT 30,
        is_active      TINYINT(1)   NOT NULL DEFAULT 1,
        created_at     DATETIME     NOT NULL");

    db_add_column($pdo, 'budget_lines', 'funnel_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'tasks', 'line_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
}
