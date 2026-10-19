<?php
/**
 * topper. admin — pedidos do Drive (Cloudflare R2)
 *
 * Só POST com corpo JSON e o token CSRF no cabeçalho X-CSRF-Token.
 * O servidor só assina: os bytes vão do browser diretamente para o R2.
 * Responde {"ok":true,…} ou {"ok":false,"error":"…"} (português).
 *
 *   sign        prefix, name, type, size, overwrite? → envio simples (PUT) ou multipart
 *   mp_sign     key, uploadId, parts[]               → URLs das partes
 *   mp_parts    key, uploadId                        → partes já recebidas (retomar)
 *   mp_complete key, uploadId, parts[{n,etag}]
 *   mp_abort    key, uploadId
 *   url         key, inline?                         → URL GET curto (download/pré-visualização)
 *   mkdir       prefix, name
 *   delete      key (ficheiro ou pasta vazia «…/»)
 *   rename      key, name                            → CopyObject + DeleteObject
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/drive.php';

header('Content-Type: application/json; charset=utf-8');

function api_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function api_err(string $msg, int $code = 400): void { api_out($code, ['ok' => false, 'error' => $msg]); }

if (current_user() === null) api_err('A sessão terminou. Entre outra vez.', 401);
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_err('Só POST.', 405);
$tok = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $tok) || $tok === '') {
    api_err('Pedido inválido. Recarregue a página e tente outra vez.', 403);
}
$raw = (string)file_get_contents('php://input', false, null, 0, 1024 * 1024);
$in = json_decode($raw, true);
if (!is_array($in)) api_err('Pedido inválido (JSON).');
if (!r2_config()) api_err('O armazenamento R2 ainda não está configurado (falta inc/r2_config.php).', 503);

$do = (string)($in['do'] ?? '');
$str = static fn(string $k): string => is_string($in[$k] ?? null) ? $in[$k] : '';

/** Uma chave vinda do browser, validada. */
function want_key(string $k, bool $folder = false): string {
    if (!r2_key_ok($k, $folder)) api_err('Caminho inválido.');
    return $k;
}
function want_prefix(string $p): string {
    if (!r2_prefix_ok($p)) api_err('Pasta inválida.');
    return $p;
}
/** O uploadId tem de ter sido criado nesta sessão para esta chave. */
function want_upload(string $key, string $id): void {
    if ($id === '' || strlen($id) > 1024 || (($_SESSION['r2_up'][$id] ?? null) !== $key)) {
        api_err('Este envio não pertence a esta sessão. Comece outra vez.');
    }
}
function safe_type(string $t): string {
    return preg_match('~^[\w.+-]+/[\w.+-]+$~', $t) && strlen($t) < 128 ? $t : 'application/octet-stream';
}

try {
    switch ($do) {
    case 'sign': {
        $prefix = want_prefix($str('prefix'));
        $name = r2_clean_name($str('name'));
        if ($name === '') api_err('Nome de ficheiro inválido.');
        $key = want_key($prefix . $name);
        $size = (int)($in['size'] ?? -1);
        if ($size < 0) api_err('Tamanho inválido.');
        if ($size > 5 * 1024 * 1024 * 1024 * 1024) api_err('O ficheiro é maior do que o máximo do R2 (5 TB).');
        $type = safe_type($str('type')) ;
        if ($type === 'application/octet-stream') $type = r2_mime($name);
        if (empty($in['overwrite']) && r2_head($key) !== null) {
            api_out(409, ['ok' => false, 'exists' => true, 'key' => $key, 'error' => 'Já existe «' . $name . '» nesta pasta.']);
        }
        if ($size <= R2_SINGLE_MAX) {
            api_out(200, ['ok' => true, 'mode' => 'single', 'key' => $key, 'type' => $type, 'url' => r2_presign('PUT', $key, R2_URL_TTL)]);
        }
        $id = r2_mp_create($key, $type);
        $_SESSION['r2_up'][$id] = $key;
        if (count($_SESSION['r2_up']) > 50) array_shift($_SESSION['r2_up']);
        api_out(200, ['ok' => true, 'mode' => 'multipart', 'key' => $key, 'type' => $type, 'uploadId' => $id, 'partSize' => R2_PART_SIZE]);
    }
    case 'mp_sign': {
        $key = want_key($str('key')); $id = $str('uploadId'); want_upload($key, $id);
        $parts = is_array($in['parts'] ?? null) ? $in['parts'] : [];
        if (!$parts || count($parts) > 100) api_err('Partes inválidas.');
        $urls = [];
        foreach ($parts as $n) {
            $n = (int)$n;
            if ($n < 1 || $n > 10000) api_err('Número de parte inválido.');
            $urls[(string)$n] = r2_mp_part_url($key, $id, $n);
        }
        api_out(200, ['ok' => true, 'urls' => $urls]);
    }
    case 'mp_parts': {
        $key = want_key($str('key')); $id = $str('uploadId'); want_upload($key, $id);
        api_out(200, ['ok' => true, 'parts' => (object)r2_mp_parts($key, $id)]);
    }
    case 'mp_complete': {
        $key = want_key($str('key')); $id = $str('uploadId'); want_upload($key, $id);
        $parts = [];
        foreach ((array)($in['parts'] ?? []) as $p) {
            $n = (int)($p['n'] ?? 0); $etag = is_string($p['etag'] ?? null) ? $p['etag'] : '';
            if ($n < 1 || $n > 10000 || !preg_match('/^"?[A-Za-z0-9-]{1,128}"?$/', $etag)) api_err('Partes inválidas.');
            $parts[$n] = [$n, $etag];
        }
        if (!$parts) api_err('Partes inválidas.');
        r2_mp_complete($key, $id, array_values($parts));
        unset($_SESSION['r2_up'][$id]);
        api_out(200, ['ok' => true, 'key' => $key]);
    }
    case 'mp_abort': {
        $key = want_key($str('key')); $id = $str('uploadId'); want_upload($key, $id);
        r2_mp_abort($key, $id);
        unset($_SESSION['r2_up'][$id]);
        api_out(200, ['ok' => true]);
    }
    case 'url': {
        $key = want_key($str('key'));
        $inline = !empty($in['inline']);
        api_out(200, ['ok' => true, 'url' => r2_get_url($key, $inline, $inline ? r2_mime($key) : '')]);
    }
    case 'mkdir': {
        $prefix = want_prefix($str('prefix'));
        $name = r2_clean_name($str('name'));
        if ($name === '') api_err('Escreva o nome da pasta.');
        $key = want_key($prefix . $name . '/', true);
        $l = r2_list($prefix);
        if (in_array($key, $l['folders'], true)) api_err('Já existe uma pasta chamada «' . $name . '» aqui.', 409);
        if ($prefix !== '') r2_mkdir_p($prefix);
        r2_mkdir($key);
        api_out(200, ['ok' => true, 'key' => $key, 'message' => 'Pasta «' . $name . '» criada.']);
    }
    case 'delete': {
        $k = $str('key');
        if (substr($k, -1) === '/') {
            $k = want_key($k, true);
            $l = r2_list($k);
            if ($l['folders'] || $l['files']) api_err('A pasta não está vazia. Apague primeiro o que está lá dentro.', 409);
            r2_delete($k);
        } else {
            r2_delete(want_key($k));
        }
        api_out(200, ['ok' => true, 'message' => 'Apagado.']);
    }
    case 'rename': {
        $key = want_key($str('key'));
        $name = r2_clean_name($str('name'));
        if ($name === '') api_err('Nome inválido.');
        $dir = ($p = strrpos($key, '/')) === false ? '' : substr($key, 0, $p + 1);
        $to = want_key($dir . $name);
        if ($to === $key) api_out(200, ['ok' => true, 'key' => $key]);
        if (r2_head($to) !== null) api_err('Já existe «' . $name . '» nesta pasta.', 409);
        $h = r2_head($key);
        if ($h === null) api_err('O ficheiro já não existe.', 404);
        if ($h['size'] > 5 * 1024 * 1024 * 1024) api_err('Ficheiros com mais de 5 GB não podem ser renomeados aqui.');
        r2_copy($key, $to);
        r2_delete($key);
        api_out(200, ['ok' => true, 'key' => $to, 'message' => 'Renomeado para «' . $name . '».']);
    }
    default:
        api_err('Pedido desconhecido.');
    }
} catch (R2Exception $e) {
    api_err($e->getMessage(), 502);
}
