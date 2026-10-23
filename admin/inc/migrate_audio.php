<?php
/**
 * topper. admin — passo 29 da base de dados: Centro Criativo — áudio (§27)
 *
 *  · cc_assets.audio_cat: a categoria de um áudio (musica, voz, sfx, ambiente, outro), sugerida pela IA e editável.
 *  · cc_assets.peaks e cc_rv_versions.peaks: a forma de onda (picos calculados no browser, guardados para não recalcular).
 *  · Os áudios que entraram como «Ficheiro» (o Centro Criativo ainda não conhecia áudio) passam a «Áudio»,
 *    e os cartões deles também. Só uma vez: os novos já entram como áudio.
 *
 * Idempotente: corre de novo sem duplicar.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column

const CC_AUDIO_EXTS = ['mp3', 'wav', 'm4a', 'aac', 'flac', 'ogg', 'aif', 'aiff'];

function migrate_audio(PDO $pdo): void {
    if (!db_has_table($pdo, 'cc_assets')) return;
    db_add_column($pdo, 'cc_assets', 'audio_cat', "VARCHAR(10) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'cc_assets', 'peaks', 'TEXT NULL', 'TEXT NULL');
    if (db_has_table($pdo, 'cc_rv_versions')) db_add_column($pdo, 'cc_rv_versions', 'peaks', 'TEXT NULL', 'TEXT NULL');

    // os áudios antigos: «Ficheiro» → «Áudio» (pelo tipo MIME ou pela extensão)
    $ids = [];
    foreach ($pdo->query("SELECT id, mime, file_name FROM cc_assets WHERE kind = 'file'")->fetchAll() as $a) {
        $ext = strtolower(pathinfo((string)$a['file_name'], PATHINFO_EXTENSION));
        if (str_starts_with((string)$a['mime'], 'audio/') || in_array($ext, CC_AUDIO_EXTS, true)) $ids[] = (int)$a['id'];
    }
    foreach (array_chunk($ids, 200) as $chunk) {
        $in = implode(',', $chunk);
        $pdo->exec("UPDATE cc_assets SET kind = 'audio' WHERE id IN ($in)");
        if (db_has_table($pdo, 'cc_cards')) $pdo->exec("UPDATE cc_cards SET type = 'audio', h = 0 WHERE type = 'file' AND asset_id IN ($in)");
    }
}
