<?php
/**
 * topper. admin — passo 28 da base de dados: Centro Criativo, fase 3 — tempo real e captura (§26)
 *
 *  · cc_board_versions: o histórico do quadro — versões automáticas (uma por sessão de trabalho e
 *    antes das ações grandes, como «Arrumar o quadro») com o estado dos cartões, para «Ver» e «Restaurar».
 *  · cc_comments.reactions: reações aos comentários ({"👍": [ids], …}).
 *  · cc_assets.capture: de onde veio o que se guardou com a extensão / Partilhar / Atalho (página, autor, tempo, nota).
 *  · user_tokens: tokens pessoais (Perfil › Ligações) para a extensão «Guardar na topper.» e o
 *    Atalho do iOS — só o hash fica guardado; revogáveis pela pessoa e pelos admins.
 *  · rt_usage / rt_usage_win: o uso do serviço de tempo real no dia (ligações, mensagens e janelas de
 *    10 s com atividade por quadro), para o indicador e o aviso aos 80% do plano gratuito.
 *
 * Idempotente: corre de novo sem duplicar.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column

function migrate_rt(PDO $pdo): void {
    db_create($pdo, 'cc_board_versions', "
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        board_id    INT UNSIGNED NOT NULL,
        kind        VARCHAR(10)  NOT NULL DEFAULT 'session',
        label       VARCHAR(200) NOT NULL DEFAULT '',
        data        MEDIUMTEXT   NOT NULL,
        cards_n     INT          NOT NULL DEFAULT 0,
        created_by  INT UNSIGNED NULL,
        created_at  DATETIME     NOT NULL,
        INDEX idx_cbv_board (board_id, id)");

    db_add_column($pdo, 'cc_comments', 'reactions', 'TEXT NULL', 'TEXT NULL');
    // de onde veio uma referência guardada pela extensão, pelo Android ou pelo iOS: página, autor, tempo do vídeo, nota, texto
    db_add_column($pdo, 'cc_assets', 'capture', 'TEXT NULL', 'TEXT NULL');

    db_create($pdo, 'user_tokens', "
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id      INT UNSIGNED NOT NULL,
        name         VARCHAR(80)  NOT NULL DEFAULT '',
        token_hash   CHAR(64)     NOT NULL,
        last4        VARCHAR(8)   NOT NULL DEFAULT '',
        scope        VARCHAR(16)  NOT NULL DEFAULT 'capture',
        created_at   DATETIME     NOT NULL,
        last_used_at DATETIME     NULL,
        last_ip      VARCHAR(45)  NOT NULL DEFAULT '',
        last_ua      VARCHAR(160) NOT NULL DEFAULT '',
        uses         INT          NOT NULL DEFAULT 0,
        revoked_at   DATETIME     NULL,
        revoked_by   INT UNSIGNED NULL,
        UNIQUE KEY uq_ut_hash (token_hash),
        INDEX idx_ut_user (user_id)");

    db_create($pdo, 'rt_usage', "
        day       DATE         NOT NULL,
        board_id  INT UNSIGNED NOT NULL,
        conns     INT          NOT NULL DEFAULT 0,
        msgs      INT          NOT NULL DEFAULT 0,
        PRIMARY KEY (day, board_id)");

    db_create($pdo, 'rt_usage_win', "
        day       DATE         NOT NULL,
        board_id  INT UNSIGNED NOT NULL,
        win       INT          NOT NULL,
        PRIMARY KEY (day, board_id, win)");
}
