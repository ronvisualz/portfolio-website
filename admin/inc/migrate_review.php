<?php
/**
 * topper. admin — passo 27 da base de dados: Toppernote, fase 2 — Review e Portal do cliente (§24)
 *
 *  · cc_rv_items: as peças de review de um negócio (e de uma frente, §22), cada uma numa etapa de
 *    aprovação (styleframes, animatic, offline, picture lock, finais, lote…), com o estado, as opções
 *    do portal (marca de água, download) e a aprovação (quem, nome escrito, IP, quando).
 *  · cc_rv_versions: v001, v002… — nunca se apagam. O original fica no Drive (<negócio>/Review/);
 *    o proxy H.264, o poster, a tira de miniaturas, as páginas de um PDF e o proxy com marca de água
 *    ficam no sistema. fps, frames, duração e timecode de início lidos no servidor.
 *  · cc_comments ganha o que o Review precisa: peça, frame (ou intervalo), pino (x, y, página),
 *    visibilidade (interno · para o cliente), ronda, rascunho do cliente, anexos.
 *  · cc_rv_rounds: cada envio ao cliente abre uma ronda; o envio consolidado dos comentários fecha-a
 *    e conta nas rondas da etapa (deal_rounds, §19.6 D).
 *  · cc_share (preparado na v24) ganha o link cifrado (para «Copiar link»), os separadores, o idioma;
 *    cc_share_log regista os acessos e as ações do cliente; cc_portal_hits limita os pedidos por IP.
 *
 * Idempotente: corre de novo sem duplicar.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

function migrate_review(PDO $pdo): void {
    db_create($pdo, 'cc_rv_items', "
        id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        deal_id             INT UNSIGNED NOT NULL,
        line_id             INT UNSIGNED NULL,
        title               VARCHAR(200) NOT NULL,
        stage_key           VARCHAR(16)  NOT NULL DEFAULT 'outro',
        stage_id            INT UNSIGNED NULL,
        kind                VARCHAR(6)   NOT NULL DEFAULT 'video',
        status              VARCHAR(10)  NOT NULL DEFAULT 'prep',
        shared              TINYINT      NOT NULL DEFAULT 0,
        watermark           TINYINT      NOT NULL DEFAULT 1,
        download            TINYINT      NOT NULL DEFAULT 0,
        current_version_id  INT UNSIGNED NULL,
        approved_version_id INT UNSIGNED NULL,
        approved_kind       VARCHAR(8)   NOT NULL DEFAULT '',
        approved_by         INT UNSIGNED NULL,
        approved_name       VARCHAR(160) NOT NULL DEFAULT '',
        approved_email      VARCHAR(190) NOT NULL DEFAULT '',
        approved_ip         VARCHAR(45)  NOT NULL DEFAULT '',
        approved_at         DATETIME     NULL,
        library_asset_id    INT UNSIGNED NULL,
        position            INT          NOT NULL DEFAULT 0,
        rev                 INT          NOT NULL DEFAULT 0,
        created_by          INT UNSIGNED NULL,
        created_at          DATETIME     NOT NULL,
        updated_at          DATETIME     NOT NULL,
        INDEX idx_rvi_deal (deal_id, position)");

    db_create($pdo, 'cc_rv_versions', "
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        item_id      INT UNSIGNED NOT NULL,
        num          INT          NOT NULL,
        file_key     VARCHAR(600) NOT NULL DEFAULT '',
        file_name    VARCHAR(255) NOT NULL DEFAULT '',
        mime         VARCHAR(100) NOT NULL DEFAULT '',
        bytes        BIGINT       NOT NULL DEFAULT 0,
        proc_status  VARCHAR(8)   NOT NULL DEFAULT 'queued',
        proc_error   VARCHAR(255) NOT NULL DEFAULT '',
        proxy_key    VARCHAR(255) NOT NULL DEFAULT '',
        poster_key   VARCHAR(255) NOT NULL DEFAULT '',
        strip_key    VARCHAR(255) NOT NULL DEFAULT '',
        strip_n      INT          NOT NULL DEFAULT 0,
        strip_w      INT          NOT NULL DEFAULT 0,
        strip_h      INT          NOT NULL DEFAULT 0,
        wm_key       VARCHAR(255) NOT NULL DEFAULT '',
        wm_label     VARCHAR(160) NOT NULL DEFAULT '',
        wm_status    VARCHAR(8)   NOT NULL DEFAULT '',
        fps_num      INT          NOT NULL DEFAULT 25,
        fps_den      INT          NOT NULL DEFAULT 1,
        frames       INT          NOT NULL DEFAULT 0,
        duration     DECIMAL(10,3) NOT NULL DEFAULT 0,
        tc_start     VARCHAR(16)  NOT NULL DEFAULT '00:00:00:00',
        width        INT          NOT NULL DEFAULT 0,
        height       INT          NOT NULL DEFAULT 0,
        pages        MEDIUMTEXT   NULL,
        note         VARCHAR(255) NOT NULL DEFAULT '',
        sent_at      DATETIME     NULL,
        created_by   INT UNSIGNED NULL,
        created_at   DATETIME     NOT NULL,
        UNIQUE KEY uq_rvv_num (item_id, num),
        INDEX idx_rvv_proc (proc_status)");

    db_add_column($pdo, 'cc_comments', 'item_id',     'INT UNSIGNED NULL',            'INTEGER NULL');
    db_add_column($pdo, 'cc_comments', 'frame_in',    'INT NULL',                     'INTEGER NULL');
    db_add_column($pdo, 'cc_comments', 'frame_out',   'INT NULL',                     'INTEGER NULL');
    db_add_column($pdo, 'cc_comments', 'pin_x',       'DECIMAL(7,5) NULL',            'REAL NULL');
    db_add_column($pdo, 'cc_comments', 'pin_y',       'DECIMAL(7,5) NULL',            'REAL NULL');
    db_add_column($pdo, 'cc_comments', 'page',        'INT NULL',                     'INTEGER NULL');
    db_add_column($pdo, 'cc_comments', 'visibility',  "VARCHAR(8) NOT NULL DEFAULT 'internal'", "TEXT NOT NULL DEFAULT 'internal'");
    db_add_column($pdo, 'cc_comments', 'round_id',    'INT UNSIGNED NULL',            'INTEGER NULL');
    db_add_column($pdo, 'cc_comments', 'draft',       'TINYINT NOT NULL DEFAULT 0',   'INTEGER NOT NULL DEFAULT 0');
    db_add_column($pdo, 'cc_comments', 'client_key',  "VARCHAR(40) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'cc_comments', 'attachments', 'TEXT NULL',                    'TEXT NULL');
    db_add_column($pdo, 'cc_comments', 'resolved_by', 'INT UNSIGNED NULL',            'INTEGER NULL');
    if (!db_is_sqlite($pdo)) {
        $st = $pdo->query("SHOW INDEX FROM cc_comments WHERE Key_name = 'idx_cc_item'");
        if (!$st->fetch()) $pdo->exec('ALTER TABLE cc_comments ADD INDEX idx_cc_item (item_id, version_id)');
    }

    db_create($pdo, 'cc_rv_rounds', "
        id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        item_id        INT UNSIGNED NOT NULL,
        deal_id        INT UNSIGNED NOT NULL,
        stage_id       INT UNSIGNED NULL,
        num            INT          NOT NULL DEFAULT 1,
        version_id     INT UNSIGNED NOT NULL,
        opened_at      DATETIME     NOT NULL,
        opened_by      INT UNSIGNED NULL,
        submitted_at   DATETIME     NULL,
        client_name    VARCHAR(160) NOT NULL DEFAULT '',
        client_email   VARCHAR(190) NOT NULL DEFAULT '',
        comments_n     INT          NOT NULL DEFAULT 0,
        deal_round_id  INT UNSIGNED NULL,
        over_included  TINYINT      NOT NULL DEFAULT 0,
        INDEX idx_rvr_item (item_id, id)");

    db_add_column($pdo, 'cc_share', 'token_enc',      'TEXT NULL',                         'TEXT NULL');
    db_add_column($pdo, 'cc_share', 'tabs',           "VARCHAR(40) NOT NULL DEFAULT 'proposal,boards,review'", "TEXT NOT NULL DEFAULT 'proposal,boards,review'");
    db_add_column($pdo, 'cc_share', 'lang',           "VARCHAR(2) NOT NULL DEFAULT 'pt'", "TEXT NOT NULL DEFAULT 'pt'");
    db_add_column($pdo, 'cc_share', 'last_access_at', 'DATETIME NULL',                     'TEXT NULL');

    db_create($pdo, 'cc_share_log', "
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        share_id     INT UNSIGNED NOT NULL,
        deal_id      INT UNSIGNED NOT NULL,
        at           DATETIME     NOT NULL,
        ip           VARCHAR(45)  NOT NULL DEFAULT '',
        ua           VARCHAR(255) NOT NULL DEFAULT '',
        client_name  VARCHAR(160) NOT NULL DEFAULT '',
        client_email VARCHAR(190) NOT NULL DEFAULT '',
        action       VARCHAR(12)  NOT NULL,
        item_id      INT UNSIGNED NULL,
        detail       VARCHAR(255) NOT NULL DEFAULT '',
        INDEX idx_shl_share (share_id, id),
        INDEX idx_shl_deal (deal_id, id)");

    db_create($pdo, 'cc_portal_hits', "
        ip_hash  CHAR(64) NOT NULL,
        win      INT      NOT NULL,
        n        INT      NOT NULL DEFAULT 0,
        PRIMARY KEY (ip_hash, win)");
}
