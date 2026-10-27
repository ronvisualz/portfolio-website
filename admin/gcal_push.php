<?php
/**
 * topper. admin — notificações push do Google Calendar (§31.4, fase 2)
 *
 * A Google avisa aqui quando algo muda no calendário de uma pessoa ligada (canal aberto pelo cron, renovado antes de
 * expirar, só quando ligado em Definições). O aviso não traz dados: confere-se o canal e o token, marca-se a pessoa
 * como «com mudanças» e o cron lê-as com o syncToken (inc/gcal.php, gcal_pull). Responde sempre depressa.
 */

define('TOPPER_NO_SESSION', true);
require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$id = (string)($_SERVER['HTTP_X_GOOG_CHANNEL_ID'] ?? '');
$tok = (string)($_SERVER['HTTP_X_GOOG_CHANNEL_TOKEN'] ?? '');
$state = (string)($_SERVER['HTTP_X_GOOG_RESOURCE_STATE'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $id) || !preg_match('/^[a-f0-9]{32}$/', $tok) || !db_has_table($pdo, 'user_google')) { http_response_code(200); exit; }

$st = $pdo->prepare('SELECT user_id, channel_token FROM user_google WHERE channel_id = ?');
$st->execute([$id]);
$row = $st->fetch();
if ($row && hash_equals((string)$row['channel_token'], $tok) && $state !== 'sync') {
    $pdo->prepare('UPDATE user_google SET push_pending = 1 WHERE user_id = ?')->execute([(int)$row['user_id']]);
}
http_response_code(200);
echo 'ok';
