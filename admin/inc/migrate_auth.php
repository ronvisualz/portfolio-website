<?php
/**
 * topper. admin — passo 35 da base de dados: email sem palavras-passe, login rápido e calendário (§31)
 *
 *   mail_inbound    os emails que chegam pela Cloudflare (Email Routing → Worker → fin_inbound.php): só o
 *                   remetente, o assunto, a caixa de origem e o resultado — nunca o corpo. Serve também contra
 *                   repetições (o mesmo Message-ID não entra duas vezes) e para a data do último email por caixa
 *   user_passkeys   as chaves de acesso (WebAuthn) de cada pessoa: id da credencial, chave pública, contador
 *   login_log       o registo de entradas (método, dispositivo, data) que cada um vê no Perfil
 *   user_google     a ligação de cada pessoa ao seu Google Calendar: o token cifrado (a chave fica num ficheiro
 *                   do servidor, fora da base), os scopes, a cache do ocupado/livre e o que a fase 2 precisa
 *                   (syncToken, canal das notificações push)
 *   calendar_links  evento do Google ↔ registo da plataforma (tarefa, reunião da Equipa): nunca duplicados
 *   users.google_sub     a conta Google (OpenID) com que a pessoa entrou da primeira vez
 *   tasks.call_link      o link da chamada (Meet criado pela plataforma ou colado à mão)
 *
 * Como os outros passos, verifica antes de mudar e pode correr as vezes que forem.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column

function migrate_auth_35(PDO $pdo): void {
    db_create($pdo, 'mail_inbound', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        msg_hash     CHAR(64)     NOT NULL,
        box          VARCHAR(190) NOT NULL DEFAULT '',
        from_addr    VARCHAR(190) NOT NULL DEFAULT '',
        subject      VARCHAR(300) NOT NULL DEFAULT '',
        size         INT UNSIGNED NOT NULL DEFAULT 0,
        status       VARCHAR(12)  NOT NULL DEFAULT '',
        note         VARCHAR(300) NOT NULL DEFAULT '',
        created_at   DATETIME     NOT NULL,
        UNIQUE KEY uq_mi_hash (msg_hash),
        INDEX idx_mi_box (box, created_at)");
    db_create($pdo, 'user_passkeys', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id      INT UNSIGNED NOT NULL,
        name         VARCHAR(80)  NOT NULL DEFAULT '',
        cred_id      VARCHAR(255) NOT NULL,
        public_key   TEXT         NOT NULL,
        alg          INT          NOT NULL DEFAULT -7,
        sign_count   INT UNSIGNED NOT NULL DEFAULT 0,
        transports   VARCHAR(120) NOT NULL DEFAULT '',
        created_at   DATETIME     NOT NULL,
        last_used_at DATETIME     NULL,
        last_ua      VARCHAR(255) NOT NULL DEFAULT '',
        UNIQUE KEY uq_pk_cred (cred_id),
        INDEX idx_pk_user (user_id)");
    db_create($pdo, 'login_log', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id      INT UNSIGNED NOT NULL,
        method       VARCHAR(12)  NOT NULL,
        device       VARCHAR(120) NOT NULL DEFAULT '',
        ip           VARBINARY(16) NULL,
        created_at   DATETIME     NOT NULL,
        INDEX idx_ll_user (user_id, created_at)");
    db_create($pdo, 'user_google', "
        user_id        INT UNSIGNED NOT NULL PRIMARY KEY,
        google_email   VARCHAR(190) NOT NULL DEFAULT '',
        token_enc      TEXT         NOT NULL,
        scopes         VARCHAR(500) NOT NULL DEFAULT '',
        calendar_id    VARCHAR(190) NOT NULL DEFAULT 'primary',
        sync_on        TINYINT(1)   NOT NULL DEFAULT 1,
        show_busy      TINYINT(1)   NOT NULL DEFAULT 0,
        busy_cache     MEDIUMTEXT   NULL,
        busy_at        DATETIME     NULL,
        sync_token     VARCHAR(255) NOT NULL DEFAULT '',
        channel_id     VARCHAR(64)  NOT NULL DEFAULT '',
        channel_token  VARCHAR(64)  NOT NULL DEFAULT '',
        channel_res    VARCHAR(190) NOT NULL DEFAULT '',
        channel_exp    DATETIME     NULL,
        push_pending   TINYINT(1)   NOT NULL DEFAULT 0,
        last_sync_at   DATETIME     NULL,
        last_error     VARCHAR(300) NOT NULL DEFAULT '',
        connected_at   DATETIME     NOT NULL");
    db_create($pdo, 'calendar_links', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ref_type     VARCHAR(12)  NOT NULL,
        ref_id       INT UNSIGNED NOT NULL,
        kind         VARCHAR(8)   NOT NULL DEFAULT 'meet',
        account      VARCHAR(24)  NOT NULL,
        calendar_id  VARCHAR(190) NOT NULL DEFAULT 'primary',
        event_id     VARCHAR(255) NOT NULL,
        etag         VARCHAR(64)  NOT NULL DEFAULT '',
        meet_url     VARCHAR(255) NOT NULL DEFAULT '',
        html_link    VARCHAR(500) NOT NULL DEFAULT '',
        guests       TEXT         NULL,
        responses    TEXT         NULL,
        status       VARCHAR(12)  NOT NULL DEFAULT 'active',
        synced_at    DATETIME     NULL,
        created_by   INT UNSIGNED NULL,
        created_at   DATETIME     NOT NULL,
        UNIQUE KEY uq_cl (ref_type, ref_id, kind, account),
        INDEX idx_cl_event (event_id)");
    db_add_column($pdo, 'users', 'google_sub', 'VARCHAR(64) NULL', 'TEXT NULL');
    if (db_has_table($pdo, 'tasks')) db_add_column($pdo, 'tasks', 'call_link', "VARCHAR(500) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");

    // As caixas que já eram lidas por IMAP passam a ser as «Caixas ligadas» (o mesmo setting); quem já tinha
    // o leitor IMAP a funcionar continua nesse modo até mudar em Definições — sem perder emails pelo caminho.
    $has = fn(string $k) => (int)$pdo->query('SELECT COUNT(*) FROM settings WHERE skey = ' . $pdo->quote($k))->fetchColumn() > 0;
    if (!$has('fin_mail_mode')) {
        $boxes = trim((string)$pdo->query("SELECT svalue FROM settings WHERE skey = 'fin_mail_boxes'")->fetchColumn());
        $f = __DIR__ . '/fin_mail_config.php';
        $imapOn = false;
        if ($boxes !== '' && is_file($f)) {
            $raw = @include $f;
            $imapOn = is_array($raw) && array_filter((array)($raw['boxes'] ?? []), fn($p) => (string)$p !== '' && stripos((string)$p, 'COLE_') !== 0);
        }
        $ins = $pdo->prepare(db_is_sqlite($pdo) ? 'INSERT OR IGNORE INTO settings (skey, svalue) VALUES (?, ?)' : 'INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
        $ins->execute(['fin_mail_mode', $imapOn ? 'imap' : 'cloudflare']);
        $ins->execute(['mail_send_mode', $imapOn ? 'smtp' : 'api']);
    }
}

/**
 * Passo 36 (§31.5): sem contas Google nos emails de trabalho.
 *   users.google_email / google_linked_at   a conta Google que a pessoa associou no Perfil (pode ter outro email)
 *   users.ical_hash / ical_enc / ical_at     o link de subscrição da agenda: o sha256 do token (para o procurar) e o
 *                                            token cifrado com a chave do servidor (para o voltar a mostrar no Perfil)
 *   mail_confirm                             os links de confirmação dos reencaminhamentos do Hostinger que chegam
 *                                            pela Cloudflare (só o link, o remetente e o assunto; nunca o corpo)
 */
function migrate_auth_36(PDO $pdo): void {
    db_add_column($pdo, 'users', 'google_email', 'VARCHAR(190) NULL', 'TEXT NULL');
    db_add_column($pdo, 'users', 'google_linked_at', 'DATETIME NULL', 'TEXT NULL');
    db_add_column($pdo, 'users', 'ical_hash', 'CHAR(64) NULL', 'TEXT NULL');
    db_add_column($pdo, 'users', 'ical_enc', 'TEXT NULL', 'TEXT NULL');
    db_add_column($pdo, 'users', 'ical_at', 'DATETIME NULL', 'TEXT NULL');
    db_create($pdo, 'mail_confirm', "
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        msg_hash     CHAR(64)      NOT NULL,
        from_addr    VARCHAR(190)  NOT NULL DEFAULT '',
        subject      VARCHAR(300)  NOT NULL DEFAULT '',
        box          VARCHAR(190)  NOT NULL DEFAULT '',
        link         VARCHAR(1000) NOT NULL,
        done_at      DATETIME      NULL,
        created_at   DATETIME      NOT NULL,
        UNIQUE KEY uq_mc_hash (msg_hash)");
}
