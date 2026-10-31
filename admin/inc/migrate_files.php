<?php
/**
 * topper. admin — passo 25 da base de dados: o registo de ficheiros (§10.15)
 *
 *  · files: cada ficheiro que entra na plataforma ou é gerado por ela, guardado uma só vez
 *    no Drive — a chave atual, o ETag e o tamanho (para o voltar a encontrar se alguém o
 *    mover à mão), a origem (proposta, fatura, Centro Criativo, anexo…), a secção da
 *    pasta do negócio e a ligação ao negócio/lead, à peça de marketing ou à referência.
 *  · file_moves: a fila das pastas a mudar de sítio (lead convertida, arquivada, negócio
 *    perdido, reaberto). Corre em segundo plano (cron) e no próprio pedido, por pouco tempo.
 *  · Os caminhos do Drive crescem: deals.drive_folder e cc_assets.file_key passam a 600.
 *  · file_jobs (passo 26): «Reorganizar o Drive» — os itens escolhidos na pré-visualização.
 *
 * Idempotente: corre de novo sem duplicar.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

function migrate_files(PDO $pdo): void {
    db_create($pdo, 'files', "
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        obj_key       VARCHAR(1024) NOT NULL,
        key_hash      CHAR(64)     NOT NULL,
        etag          VARCHAR(80)  NOT NULL DEFAULT '',
        size          BIGINT       NOT NULL DEFAULT 0,
        mime          VARCHAR(100) NOT NULL DEFAULT '',
        origin        VARCHAR(16)  NOT NULL DEFAULT 'attach',
        section       VARCHAR(16)  NOT NULL DEFAULT '',
        label         VARCHAR(200) NOT NULL DEFAULT '',
        deal_id       INT UNSIGNED NULL,
        mkt_piece_id  INT UNSIGNED NULL,
        cc_asset_id   INT UNSIGNED NULL,
        status        VARCHAR(8)   NOT NULL DEFAULT 'ok',
        created_by    INT UNSIGNED NULL,
        created_at    DATETIME     NOT NULL,
        updated_at    DATETIME     NOT NULL,
        UNIQUE KEY uq_files_key (key_hash),
        INDEX idx_files_deal (deal_id),
        INDEX idx_files_piece (mkt_piece_id),
        INDEX idx_files_asset (cc_asset_id),
        INDEX idx_files_etag (etag)");

    db_create($pdo, 'file_moves', "
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        deal_id     INT UNSIGNED NULL,
        why         VARCHAR(12)  NOT NULL DEFAULT '',
        from_prefix VARCHAR(600) NOT NULL,
        to_prefix   VARCHAR(600) NOT NULL,
        status      VARCHAR(8)   NOT NULL DEFAULT 'queued',
        moved       INT          NOT NULL DEFAULT 0,
        attempts    INT          NOT NULL DEFAULT 0,
        error       VARCHAR(255) NOT NULL DEFAULT '',
        created_at  DATETIME     NOT NULL,
        started_at  DATETIME     NULL,
        done_at     DATETIME     NULL,
        INDEX idx_fmoves_status (status, id),
        INDEX idx_fmoves_deal (deal_id)");

    // passo 26 · «Reorganizar o Drive» (§25, fase 2): o que foi escolhido na pré-visualização, um por linha
    db_create($pdo, 'file_jobs', "
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_id      INT UNSIGNED NOT NULL,
        item        VARCHAR(80)  NOT NULL,
        kind        VARCHAR(12)  NOT NULL,
        label       VARCHAR(255) NOT NULL DEFAULT '',
        payload     TEXT         NULL,
        status      VARCHAR(8)   NOT NULL DEFAULT 'queued',
        result      VARCHAR(255) NOT NULL DEFAULT '',
        created_by  INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL,
        done_at     DATETIME     NULL,
        INDEX idx_fjobs_run (run_id, status),
        INDEX idx_fjobs_status (status, id)");

    if (!db_is_sqlite($pdo)) {
        // caminhos como «Arquivo/Negócios/ID2610-03_…/Criativo/Referências/…» passam os 160/255 de antes
        $len = static function (string $t, string $c) use ($pdo): int {
            $st = $pdo->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([$t, $c]);
            return (int)$st->fetchColumn();
        };
        if ($len('deals', 'drive_folder') < 600) $pdo->exec("ALTER TABLE deals MODIFY drive_folder VARCHAR(600) NOT NULL DEFAULT ''");
        if ($len('cc_assets', 'file_key') < 600) $pdo->exec("ALTER TABLE cc_assets MODIFY file_key VARCHAR(600) NOT NULL DEFAULT ''");
        if ($len('mkt_pieces', 'drive_folder') < 600) $pdo->exec('ALTER TABLE mkt_pieces MODIFY drive_folder VARCHAR(600) NULL');
        if ($len('deal_billing', 'file_key') < 600) $pdo->exec("ALTER TABLE deal_billing MODIFY file_key VARCHAR(600) NOT NULL DEFAULT ''");
    }
}

/**
 * Passo 40 · Drive (§32): partilhas com permissão («Só ver» / «Pode editar»), quem a criou e se inclui pastas privadas;
 * a fila do mover instantâneo, o histórico de versões (substituir guarda a anterior) e o registo do que os visitantes
 * fazem numa partilha com edição.
 */
function migrate_drive_40(PDO $pdo): void {
    if (!db_has_table($pdo, 'drive_shares')) return;
    db_add_column($pdo, 'drive_shares', 'perm', "VARCHAR(5) NOT NULL DEFAULT 'view'", "TEXT NOT NULL DEFAULT 'view'");
    db_add_column($pdo, 'drive_shares', 'created_by', 'INT UNSIGNED NULL', 'INTEGER NULL');
    db_add_column($pdo, 'drive_shares', 'inc_private', 'TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    db_create($pdo, 'drive_moves', "
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        batch         CHAR(16)      NOT NULL DEFAULT '',
        src           VARCHAR(1024) NOT NULL,
        dst           VARCHAR(1024) NOT NULL,
        is_folder     TINYINT(1)    NOT NULL DEFAULT 0,
        policy        VARCHAR(10)   NOT NULL DEFAULT 'error',
        status        VARCHAR(10)   NOT NULL DEFAULT 'queued',
        cursor_after  VARCHAR(1024) NOT NULL DEFAULT '',
        moved         INT           NOT NULL DEFAULT 0,
        total         INT           NOT NULL DEFAULT 0,
        error         VARCHAR(300)  NOT NULL DEFAULT '',
        created_by    INT UNSIGNED  NULL,
        created_at    DATETIME      NOT NULL,
        done_at       DATETIME      NULL,
        INDEX idx_dm (status, id),
        INDEX idx_dm_batch (batch)");
    db_create($pdo, 'drive_versions', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        obj_key      VARCHAR(1024) NOT NULL,
        key_hash     CHAR(64)      NOT NULL,
        ver_key      VARCHAR(1024) NOT NULL,
        size         BIGINT        NOT NULL DEFAULT 0,
        etag         VARCHAR(80)   NOT NULL DEFAULT '',
        by_label     VARCHAR(120)  NOT NULL DEFAULT '',
        share_id     INT UNSIGNED  NULL,
        created_at   DATETIME      NOT NULL,
        INDEX idx_dv (key_hash, id)");
    db_create($pdo, 'drive_share_log', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        share_id     INT UNSIGNED  NOT NULL,
        action       VARCHAR(10)   NOT NULL,
        obj_key      VARCHAR(1024) NOT NULL DEFAULT '',
        detail       VARCHAR(300)  NOT NULL DEFAULT '',
        visitor      VARCHAR(80)   NOT NULL DEFAULT '',
        ip_part      VARCHAR(20)   NOT NULL DEFAULT '',
        created_at   DATETIME      NOT NULL,
        seen         TINYINT(1)    NOT NULL DEFAULT 0,
        INDEX idx_dsl (share_id, id),
        INDEX idx_dsl_seen (seen, id)");
}
