<?php
/**
 * topper. admin — ficheiros do Centro Criativo (§23): miniaturas por zoom e originais.
 *
 *   GET cc_file.php?a=<referência>&s=256|768|1600   a miniatura mais próxima (por cima) da largura
 *   GET cc_file.php?a=<referência>&s=0              o original (vídeo com pedidos parciais)
 *   GET cc_file.php?f=<frame>&s=…                   um frame-chave de um vídeo
 *   GET cc_file.php?pt=<token da proposta>&a=…      as imagens do pré-criativo na página do cliente
 *                                                   (só as referências das páginas dessa proposta)
 *
 * Com sessão: pede a aba Centro Criativo (ver). No R2 responde com um redirecionamento para um
 * endereço assinado estável durante uma hora (o browser guarda-o em cache); sem R2, envia o
 * ficheiro da pasta privada, com cache longa (as chaves mudam quando o ficheiro muda).
 */
$public = isset($_GET['pt']);
if ($public) define('TOPPER_NO_SESSION', true);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/cc.php';

function ccf_404(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Não encontrado.');
}

$size = max(0, (int)($_GET['s'] ?? 768));
if ($public) {
    require_once __DIR__ . '/inc/pipeline_flow.php';
    $p = proposal_by_token($pdo, (string)$_GET['pt']);
    $aid = (int)($_GET['a'] ?? 0);
    if (!$p || $p['status'] === 'void' || !$aid || !cc_ready($pdo)) ccf_404();
    $ok = false;
    $st = $pdo->prepare('SELECT sent_data FROM cc_pages WHERE version_id = ?');
    $st->execute([(int)$p['version_id']]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $d = json_decode((string)$d, true);
        if (is_array($d) && in_array($aid, array_map('intval', (array)($d['refs'] ?? [])), true)) $ok = true;
    }
    if (!$ok) ccf_404();
    $size = max(256, $size);   // o cliente nunca recebe o original
} else {
    if (current_user() === null) { http_response_code(401); exit; }
    require_login();
    require_perm('criativo', PERM_VIEW);
    if (!cc_ready($pdo)) ccf_404();
}
// não segura a sessão (as miniaturas pedem-se às dezenas, ao mesmo tempo que a IA trabalha)
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$key = null; $mime = ''; $name = 'ficheiro';
if (!empty($_GET['f']) && !$public) {
    $st = $pdo->prepare('SELECT * FROM cc_frames WHERE id = ?');
    $st->execute([(int)$_GET['f']]);
    $f = $st->fetch();
    if (!$f) ccf_404();
    $key = $size > 0 ? cc_thumb_pick($f['thumbs'], $size) : $f['file_key'];
} else {
    $a = cc_asset_get($pdo, (int)($_GET['a'] ?? 0));
    if (!$a || ($a['deleted_at'] && !$public)) ccf_404();
    if ($size > 0) {
        $key = cc_thumb_pick($a['thumbs'], $size);
    } else {
        $key = $a['file_key'] !== '' ? $a['file_key'] : null;
        $mime = (string)$a['mime'];
        $name = (string)$a['file_name'] ?: 'ficheiro';
    }
}
if (!$key) ccf_404();
if ($mime === '' || $size > 0) {
    $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
    $mime = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? ($mime ?: 'application/octet-stream');
}

if (strncmp($key, 'r2:', 3) === 0) {
    // endereço assinado com a hora arredondada: o mesmo URL durante uma hora (cache do browser)
    $now = intdiv(time(), 3600) * 3600;
    $url = r2_get_url(substr($key, 3), true, $mime, 7200, $now);
    header('Cache-Control: private, max-age=' . max(60, $now + 3600 - time()));
    header('Location: ' . $url, true, 302);
    exit;
}

$path = cc_local_path($key);
if ($path === null) ccf_404();
$len = filesize($path);
$etag = '"' . substr(sha1($key . $len . filemtime($path)), 0, 16) . '"';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('ETag: ' . $etag);
header('Cache-Control: private, max-age=' . ($size > 0 ? 31536000 : 86400));
header_remove('Pragma');
if ($size === 0) header('Content-Disposition: ' . r2_disposition($name, true));
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }

$start = 0; $end = $len - 1;
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $start = max(0, $len - (int)$m[2]); }
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
    if ($start > $end || $start >= $len) { http_response_code(416); header('Content-Range: bytes */' . $len); exit; }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $len);
}
header('Content-Length: ' . ($end - $start + 1));
$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(1048576, $left));
    if ($chunk === false) break;
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fh);
