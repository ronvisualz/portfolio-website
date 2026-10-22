<?php
/**
 * topper. admin — passo 22 da base de dados: Perfil e Definições
 *
 *  · settings_log: o registo de alterações das Definições (quem, o quê, quando, antes → depois),
 *    com «Repor» por linha.
 *  · users.prefs: as preferências pessoais do Perfil (notificações e por onde, vista com que
 *    cada aba abre), em JSON. Tema e menu continuam nas colunas pref_theme e pref_nav.
 *
 * Idempotente: corre de novo sem duplicar.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

function migrate_settings_tab(PDO $pdo): void {
    db_create($pdo, 'settings_log', "
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        skey       VARCHAR(80)  NOT NULL,
        label      VARCHAR(160) NOT NULL DEFAULT '',
        old_value  TEXT NULL,
        new_value  TEXT NULL,
        user_id    INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL,
        KEY skey (skey),
        KEY created_at (created_at)");
    db_add_column($pdo, 'users', 'prefs', 'TEXT NULL', 'TEXT NULL');
}
