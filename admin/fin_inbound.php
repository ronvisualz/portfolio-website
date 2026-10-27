<?php
/**
 * topper. admin — entrada dos emails pela Cloudflare (§31.1)
 *
 * Só o Worker do email (mail/) fala com esta página: POST do email em bruto (MIME) com
 *   X-Topper-Ts    carimbo de tempo (unix, segundos)       — recusado fora de 5 minutos
 *   X-Topper-Id    o Message-ID (ou o hash do email)        — o mesmo não entra duas vezes
 *   X-Topper-Sig   HMAC-SHA256(segredo, «ts.id.sha256(corpo)») em hex
 *   X-Topper-From  o remetente do envelope (para identificar a caixa que reencaminhou)
 * Respostas: 200 tratado (ou repetido, ou ignorado) · 4xx recusado (o Worker não repete) · 5xx tentar depois.
 * Sem sessão, sem cookies; nunca devolve dados.
 */

define('TOPPER_NO_SESSION', true);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mail_inbound.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = function (int $code, string $status, string $note = '') {
    http_response_code($code);
    echo json_encode(['status' => $status, 'note' => $note], JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, 'method');
if (!mail_inbound_ready($pdo)) $out(503, 'not_ready', 'A base ainda não está no passo 35.');
$len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($len <= 0) $out(400, 'empty');
if ($len > MAIL_INBOUND_MAX_BYTES) $out(413, 'too_large');

$raw = (string)file_get_contents('php://input', false, null, 0, MAIL_INBOUND_MAX_BYTES + 1);
if (strlen($raw) > MAIL_INBOUND_MAX_BYTES) $out(413, 'too_large');

$h = [];
foreach (['ts', 'id', 'sig', 'from'] as $k) $h['x-topper-' . $k] = (string)($_SERVER['HTTP_X_TOPPER_' . strtoupper($k)] ?? '');
[$ok, $code, $why] = mail_inbound_verify($h, $raw);
if (!$ok) $out($code, 'refused', $why);

try {
    [$status, $note] = mail_inbound_accept($pdo, $raw, $h);
} catch (Throwable $e) {
    error_log('[topper] fin_inbound: ' . $e->getMessage());
    $out(500, 'error');
}
$out(200, $status, $note);
