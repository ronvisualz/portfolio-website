<?php
/**
 * topper. admin — passo 24 da base de dados: Toppernote (§23)
 *
 *  · cc_boards: quadros (do projeto, por etapa, pré-criativo, por frente, rota não escolhida,
 *    livres). Um quadro pode ter sub-quadros (parent_id).
 *  · cc_assets: a biblioteca. Cada referência existe uma vez; os quadros apontam para ela
 *    (sem cópia). Ficheiro, miniaturas por zoom, paleta (k-means em Lab), análise da IA,
 *    embedding multimodal, origem (externa · topper.) e se está na Caixa de entrada.
 *  · cc_frames: os frames-chave de cada vídeo (pesquisáveis um a um).
 *  · cc_cards: os cartões de um quadro (imagem, vídeo, link, nota, lista, coluna, cor,
 *    desenho, ficheiro, seta, sub-quadro), com posição na tela e faixa (A/B no pré-criativo).
 *  · cc_comments: comentários e @menções por cartão (preparado para o Review e o portal do
 *    cliente: timecode, desenho, autor «Cliente»).
 *  · cc_pages: as páginas do pré-criativo (uma por opção) e a ligação à proposta.
 *  · cc_jobs: a fila da IA (análise, embeddings) e das importações.
 *  · cc_ai_usage: o gasto da IA do Toppernote (separado do assistente do Drive).
 *  · cc_versions, cc_share e cc_capture: Review, portal do cliente e extensão do browser
 *    (fases 2 e 3: as tabelas ficam prontas).
 *  · funnels.cc_pre_mode (pré-criativo recomendado · obrigatório · desligado) e cc_stages
 *    (o modelo de sub-quadros do projeto por funil).
 *
 * Idempotente: corre de novo sem duplicar.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

function migrate_creative(PDO $pdo): void {
    db_create($pdo, 'cc_boards', "
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        deal_id       INT UNSIGNED NULL,
        line_id       INT UNSIGNED NULL,
        parent_id     INT UNSIGNED NULL,
        kind          VARCHAR(12)  NOT NULL DEFAULT 'free',
        stage_key     VARCHAR(20)  NOT NULL DEFAULT '',
        title         VARCHAR(200) NOT NULL,
        brief         TEXT         NULL,
        status        VARCHAR(10)  NOT NULL DEFAULT 'active',
        archived_why  VARCHAR(12)  NOT NULL DEFAULT '',
        position      INT          NOT NULL DEFAULT 0,
        cover_asset_id INT UNSIGNED NULL,
        exec_owner_id INT UNSIGNED NULL,
        task_id       INT UNSIGNED NULL,
        ai_state      VARCHAR(10)  NOT NULL DEFAULT '',
        rev           INT UNSIGNED NOT NULL DEFAULT 0,
        created_by    INT UNSIGNED NULL,
        created_at    DATETIME     NOT NULL,
        updated_at    DATETIME     NOT NULL,
        KEY deal (deal_id),
        KEY parent (parent_id),
        KEY kind (kind, status)");

    db_create($pdo, 'cc_assets', "
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kind            VARCHAR(8)    NOT NULL DEFAULT 'image',
        title           VARCHAR(255)  NOT NULL DEFAULT '',
        source_url      VARCHAR(1000) NOT NULL DEFAULT '',
        file_key        VARCHAR(255)  NOT NULL DEFAULT '',
        file_name       VARCHAR(255)  NOT NULL DEFAULT '',
        mime            VARCHAR(100)  NOT NULL DEFAULT '',
        width           INT UNSIGNED  NULL,
        height          INT UNSIGNED  NULL,
        duration        DECIMAL(10,3) NULL,
        bytes           BIGINT UNSIGNED NULL,
        sha1            CHAR(40)      NOT NULL DEFAULT '',
        thumbs          TEXT          NULL,
        palette         TEXT          NULL,
        analysis        MEDIUMTEXT    NULL,
        ai_status       VARCHAR(10)   NOT NULL DEFAULT '',
        ai_error        VARCHAR(255)  NOT NULL DEFAULT '',
        ai_model        VARCHAR(64)   NOT NULL DEFAULT '',
        analyzed_at     DATETIME      NULL,
        edited_by       INT UNSIGNED  NULL,
        edited_at       DATETIME      NULL,
        style           VARCHAR(60)   NOT NULL DEFAULT '',
        technique       VARCHAR(60)   NOT NULL DEFAULT '',
        camera          VARCHAR(60)   NOT NULL DEFAULT '',
        environment     VARCHAR(60)   NOT NULL DEFAULT '',
        light           VARCHAR(60)   NOT NULL DEFAULT '',
        tags            VARCHAR(1000) NOT NULL DEFAULT '',
        search_text     MEDIUMTEXT    NULL,
        embedding       MEDIUMBLOB    NULL,
        embed_model     VARCHAR(64)   NOT NULL DEFAULT '',
        transcript      MEDIUMTEXT    NULL,
        origin          VARCHAR(10)   NOT NULL DEFAULT 'external',
        deal_id         INT UNSIGNED  NULL,
        inbox           TINYINT(1)    NOT NULL DEFAULT 1,
        created_by      INT UNSIGNED  NULL,
        created_at      DATETIME      NOT NULL,
        deleted_at      DATETIME      NULL,
        KEY inbox (inbox, deleted_at),
        KEY sha (sha1),
        KEY origin (origin),
        KEY deal (deal_id)");

    db_create($pdo, 'cc_frames', "
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        asset_id    INT UNSIGNED NOT NULL,
        t           DECIMAL(10,3) NOT NULL DEFAULT 0,
        file_key    VARCHAR(255) NOT NULL DEFAULT '',
        thumbs      TEXT         NULL,
        palette     TEXT         NULL,
        analysis    TEXT         NULL,
        embedding   MEDIUMBLOB   NULL,
        position    INT          NOT NULL DEFAULT 0,
        KEY asset (asset_id)");

    db_create($pdo, 'cc_cards', "
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        board_id      INT UNSIGNED NOT NULL,
        type          VARCHAR(10)  NOT NULL DEFAULT 'note',
        asset_id      INT UNSIGNED NULL,
        ref_board_id  INT UNSIGNED NULL,
        column_id     INT UNSIGNED NULL,
        lane          VARCHAR(1)   NOT NULL DEFAULT '',
        x             DOUBLE       NOT NULL DEFAULT 0,
        y             DOUBLE       NOT NULL DEFAULT 0,
        w             DOUBLE       NOT NULL DEFAULT 240,
        h             DOUBLE       NOT NULL DEFAULT 0,
        z             INT          NOT NULL DEFAULT 0,
        ord           INT          NOT NULL DEFAULT 0,
        content       MEDIUMTEXT   NULL,
        created_by    INT UNSIGNED NULL,
        created_at    DATETIME     NOT NULL,
        updated_by    INT UNSIGNED NULL,
        updated_at    DATETIME     NOT NULL,
        deleted_at    DATETIME     NULL,
        KEY board (board_id, deleted_at),
        KEY asset (asset_id),
        KEY col (column_id)");

    db_create($pdo, 'cc_comments', "
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        board_id      INT UNSIGNED NOT NULL,
        card_id       INT UNSIGNED NULL,
        version_id    INT UNSIGNED NULL,
        parent_id     INT UNSIGNED NULL,
        user_id       INT UNSIGNED NULL,
        author_kind   VARCHAR(8)   NOT NULL DEFAULT 'team',
        client_name   VARCHAR(160) NOT NULL DEFAULT '',
        client_email  VARCHAR(190) NOT NULL DEFAULT '',
        body          TEXT         NOT NULL,
        mentions      VARCHAR(255) NOT NULL DEFAULT '',
        t             DECIMAL(10,3) NULL,
        drawing       TEXT         NULL,
        round         INT          NULL,
        resolved_at   DATETIME     NULL,
        created_at    DATETIME     NOT NULL,
        deleted_at    DATETIME     NULL,
        KEY card (card_id),
        KEY board (board_id),
        KEY version (version_id)");

    db_create($pdo, 'cc_pages', "
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        board_id      INT UNSIGNED NOT NULL,
        lane          VARCHAR(1)   NOT NULL DEFAULT 'A',
        serves_both   TINYINT(1)   NOT NULL DEFAULT 0,
        data          MEDIUMTEXT   NULL,
        sent_data     MEDIUMTEXT   NULL,
        generated_by  INT UNSIGNED NULL,
        generated_at  DATETIME     NULL,
        version_id    INT UNSIGNED NULL,
        opt           VARCHAR(2)   NOT NULL DEFAULT '',
        sent_by       INT UNSIGNED NULL,
        sent_at       DATETIME     NULL,
        UNIQUE KEY board_lane (board_id, lane),
        KEY version (version_id)");

    db_create($pdo, 'cc_jobs', "
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kind        VARCHAR(12)  NOT NULL,
        asset_id    INT UNSIGNED NULL,
        board_id    INT UNSIGNED NULL,
        payload     MEDIUMTEXT   NULL,
        status      VARCHAR(10)  NOT NULL DEFAULT 'queued',
        attempts    INT          NOT NULL DEFAULT 0,
        error       VARCHAR(255) NOT NULL DEFAULT '',
        created_by  INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL,
        started_at  DATETIME     NULL,
        done_at     DATETIME     NULL,
        KEY st (status, id),
        KEY asset (asset_id)");

    db_create($pdo, 'cc_ai_usage', "
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at    DATETIME     NOT NULL,
        purpose       VARCHAR(16)  NOT NULL,
        provider      VARCHAR(12)  NOT NULL DEFAULT 'anthropic',
        model         VARCHAR(64)  NOT NULL DEFAULT '',
        input_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
        output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
        cache_read    INT UNSIGNED NOT NULL DEFAULT 0,
        cache_write   INT UNSIGNED NOT NULL DEFAULT 0,
        cost_usd      DECIMAL(12,6) NOT NULL DEFAULT 0,
        user_id       INT UNSIGNED NULL,
        board_id      INT UNSIGNED NULL,
        asset_id      INT UNSIGNED NULL,
        KEY created (created_at)");

    // Fase 2 (Review e portal do cliente): versões v001…, decisões e o link por negócio.
    db_create($pdo, 'cc_versions', "
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        board_id      INT UNSIGNED NOT NULL,
        deal_id       INT UNSIGNED NULL,
        line_id       INT UNSIGNED NULL,
        stage_id      INT UNSIGNED NULL,
        asset_id      INT UNSIGNED NULL,
        num           INT          NOT NULL DEFAULT 1,
        label         VARCHAR(80)  NOT NULL DEFAULT '',
        status        VARCHAR(10)  NOT NULL DEFAULT 'review',
        task_id       INT UNSIGNED NULL,
        decided_kind  VARCHAR(8)   NOT NULL DEFAULT '',
        decided_by    INT UNSIGNED NULL,
        decided_name  VARCHAR(160) NOT NULL DEFAULT '',
        decided_at    DATETIME     NULL,
        created_by    INT UNSIGNED NULL,
        created_at    DATETIME     NOT NULL,
        KEY board (board_id),
        KEY deal (deal_id)");
    db_create($pdo, 'cc_share', "
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        deal_id       INT UNSIGNED NOT NULL,
        token_hash    CHAR(64)     NOT NULL,
        boards        VARCHAR(255) NOT NULL DEFAULT '',
        expires_on    DATE         NULL,
        revoked_at    DATETIME     NULL,
        created_by    INT UNSIGNED NULL,
        created_at    DATETIME     NOT NULL,
        UNIQUE KEY token (token_hash),
        KEY deal (deal_id)");
    // Fase 3 (extensão do browser e captura do telemóvel): um token pessoal por dispositivo.
    db_create($pdo, 'cc_capture', "
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id       INT UNSIGNED NOT NULL,
        token_hash    CHAR(64)     NOT NULL,
        label         VARCHAR(80)  NOT NULL DEFAULT '',
        created_at    DATETIME     NOT NULL,
        last_used_at  DATETIME     NULL,
        revoked_at    DATETIME     NULL,
        UNIQUE KEY token (token_hash)");

    db_add_column($pdo, 'funnels', 'cc_pre_mode', "VARCHAR(12) NOT NULL DEFAULT 'recommended'", "TEXT NOT NULL DEFAULT 'recommended'");
    db_add_column($pdo, 'funnels', 'cc_stages', "VARCHAR(255) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
}
