<?php
/**
 * topper. — a chave pública que confirma os tokens do tempo real (§26). Pública por natureza: o
 * Worker da Cloudflare lê-a daqui para verificar a assinatura dos tokens que este servidor emite.
 * A chave privada nunca sai do servidor.
 */
define('TOPPER_NO_SESSION', true);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/cc_rt.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
$k = cc_rt_ready($pdo) ? cc_rt_keys($pdo) : null;
if (!$k) { http_response_code(503); echo '{"ok":false}'; exit; }
echo json_encode(['ok' => true, 'alg' => 'ES256', 'pk' => $k[1]]);
