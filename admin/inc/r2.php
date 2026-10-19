<?php
/**
 * topper. admin — Cloudflare R2 (API S3) sem SDK.
 *
 * O servidor só assina pedidos (AWS Signature V4). Os ficheiros vão do
 * browser diretamente para o R2 com URLs pré-assinados; aqui passam só
 * os pedidos pequenos (listar, criar pasta, apagar, multipart).
 *
 * Configuração: inc/r2_config.php (ver r2_config.example.php).
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const R2_KEY_MAX      = 1024;          // limite do S3, em bytes
const R2_NAME_MAX     = 200;           // nome de um ficheiro/pasta, em bytes
const R2_PART_SIZE    = 16 * 1024 * 1024;
const R2_SINGLE_MAX   = 16 * 1024 * 1024; // até aqui, um único PUT; acima, multipart
const R2_URL_TTL      = 3600;          // URLs de envio
const R2_GET_TTL      = 300;           // URLs de download/pré-visualização

class R2Exception extends RuntimeException {}

/** A configuração, ou null se faltar/estiver incompleta. */
function r2_config(): ?array {
    static $c = false;
    if ($c !== false) return $c;
    $f = __DIR__ . '/r2_config.php';
    $c = null;
    if (!is_file($f)) return null;
    $raw = require $f;
    if (!is_array($raw)) return null;
    foreach (['account_id', 'access_key_id', 'secret_access_key', 'bucket'] as $k) {
        $v = trim((string)($raw[$k] ?? ''));
        if ($v === '' || strpos($v, '…') !== false || stripos($v, 'COLE_') === 0) return null;
        $raw[$k] = $v;
    }
    if (!preg_match('/^[a-f0-9]{32}$/i', $raw['account_id']) && empty($raw['endpoint'])) return null;
    if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $raw['bucket'])) return null;
    $ep = rtrim((string)($raw['endpoint'] ?? ('https://' . strtolower($raw['account_id']) . '.r2.cloudflarestorage.com')), '/');
    if (!preg_match('~^https?://[A-Za-z0-9.-]+(:\d+)?$~', $ep)) return null;
    $raw['endpoint'] = $ep;
    $raw['region'] = (string)($raw['region'] ?? 'auto');
    return $c = $raw;
}

/** A origem do R2 para o CSP (connect-src), ou '' se não houver configuração. */
function r2_origin(): string {
    $c = r2_config();
    return $c ? $c['endpoint'] : '';
}

// ── chaves ──────────────────────────────────────────────────

/** Uma chave de objeto aceitável: UTF-8, sem «..», sem «/» inicial, sem segmentos vazios. */
function r2_key_ok(string $k, bool $folder = false): bool {
    if ($k === '' || strlen($k) > R2_KEY_MAX || !mb_check_encoding($k, 'UTF-8')) return false;
    if ($k[0] === '/' || strpos($k, '\\') !== false || preg_match('/[\x00-\x1f\x7f]/', $k)) return false;
    $body = $folder ? (substr($k, -1) === '/' ? substr($k, 0, -1) : null) : $k;
    if ($body === null || $body === '' || (!$folder && substr($k, -1) === '/')) return false;
    foreach (explode('/', $body) as $seg) {
        if ($seg === '' || $seg === '.' || $seg === '..' || trim($seg) === '') return false;
    }
    return true;
}

/** Um prefixo de pasta («» = raiz, senão termina em «/»). */
function r2_prefix_ok(string $p): bool {
    return $p === '' || r2_key_ok($p, true);
}

/** Limpa um nome de ficheiro/pasta escrito por alguém (sem «/»). Devolve '' se não servir. */
function r2_clean_name(string $n): string {
    $n = str_replace(['/', '\\'], '-', $n);
    $n = preg_replace('/[\x00-\x1f\x7f]/', '', $n);
    $n = trim(preg_replace('/\s+/u', ' ', (string)$n) ?? '');
    if (!mb_check_encoding($n, 'UTF-8')) return '';
    if (strlen($n) > R2_NAME_MAX) $n = rtrim(mb_strcut($n, 0, R2_NAME_MAX, 'UTF-8'));
    if ($n === '.' || $n === '..') return '';
    return $n;
}

// ── Signature V4 ────────────────────────────────────────────

/** URI-encode do S3: cada byte fora de A-Za-z0-9-._~ em %XX; «/» mantém-se no caminho. */
function r2_enc(string $s, bool $path = false): string {
    $e = rawurlencode($s);
    return $path ? str_replace('%2F', '/', $e) : $e;
}

function r2_canonical_query(array $q): string {
    $pairs = [];
    foreach ($q as $k => $v) $pairs[r2_enc((string)$k)] = r2_enc((string)$v);
    ksort($pairs, SORT_STRING);
    $o = [];
    foreach ($pairs as $k => $v) $o[] = $k . '=' . $v;
    return implode('&', $o);
}

function r2_signing_key(string $secret, string $date, string $region, string $service): string {
    $k = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $k = hash_hmac('sha256', $region, $k, true);
    $k = hash_hmac('sha256', $service, $k, true);
    return hash_hmac('sha256', 'aws4_request', $k, true);
}

/**
 * O núcleo da assinatura (testável com os vetores da AWS).
 * $uri já codificado; $headers nome => valor (inclui host); $query sem codificar.
 * Devolve ['signature', 'signed_headers', 'canonical_request', 'string_to_sign', 'scope'].
 */
function r2_sigv4(string $method, string $uri, array $query, array $headers, string $payloadHash,
                  string $amzDate, string $region, string $service, string $secret): array {
    $h = [];
    foreach ($headers as $k => $v) $h[strtolower(trim((string)$k))] = preg_replace('/\s+/', ' ', trim((string)$v));
    ksort($h, SORT_STRING);
    $canonHeaders = '';
    foreach ($h as $k => $v) $canonHeaders .= $k . ':' . $v . "\n";
    $signed = implode(';', array_keys($h));
    $creq = $method . "\n" . $uri . "\n" . r2_canonical_query($query) . "\n" . $canonHeaders . "\n" . $signed . "\n" . $payloadHash;
    $date = substr($amzDate, 0, 8);
    $scope = "$date/$region/$service/aws4_request";
    $sts = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $creq);
    $sig = hash_hmac('sha256', $sts, r2_signing_key($secret, $date, $region, $service));
    return ['signature' => $sig, 'signed_headers' => $signed, 'canonical_request' => $creq, 'string_to_sign' => $sts, 'scope' => $scope];
}

/** host e caminho codificado (estilo path: /bucket/chave). */
function r2_target(array $c, string $key): array {
    $host = (string)parse_url($c['endpoint'], PHP_URL_HOST);
    $port = parse_url($c['endpoint'], PHP_URL_PORT);
    if ($port) $host .= ':' . $port;
    return [$host, '/' . r2_enc($c['bucket']) . ($key !== '' ? '/' . r2_enc($key, true) : '')];
}

/** Um URL pré-assinado (o browser usa-o diretamente). */
function r2_presign(string $method, string $key, int $ttl, array $query = [], ?int $now = null): string {
    $c = r2_config();
    if (!$c) throw new R2Exception('O armazenamento R2 não está configurado.');
    $now = $now ?? time();
    $amz = gmdate('Ymd\THis\Z', $now);
    [$host, $uri] = r2_target($c, $key);
    $q = $query + [
        'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential'    => $c['access_key_id'] . '/' . gmdate('Ymd', $now) . '/' . $c['region'] . '/s3/aws4_request',
        'X-Amz-Date'          => $amz,
        'X-Amz-Expires'       => (string)max(1, min($ttl, 604800)),
        'X-Amz-SignedHeaders' => 'host',
    ];
    $s = r2_sigv4($method, $uri, $q, ['host' => $host], 'UNSIGNED-PAYLOAD', $amz, $c['region'], 's3', $c['secret_access_key']);
    $q['X-Amz-Signature'] = $s['signature'];
    return $c['endpoint'] . $uri . '?' . r2_canonical_query($q);
}

/**
 * Um pedido assinado feito pelo servidor (cURL).
 * Devolve ['code', 'headers' => [lower => valor], 'body'].
 */
function r2_request(string $method, string $key, array $query = [], string $body = '', array $headers = [], int $timeout = 30): array {
    $c = r2_config();
    if (!$c) throw new R2Exception('O armazenamento R2 não está configurado.');
    $amz = gmdate('Ymd\THis\Z');
    [$host, $uri] = r2_target($c, $key);
    $hash = hash('sha256', $body);
    $hdr = ['host' => $host, 'x-amz-date' => $amz, 'x-amz-content-sha256' => $hash];
    foreach ($headers as $k => $v) $hdr[strtolower($k)] = $v;
    $s = r2_sigv4($method, $uri, $query, $hdr, $hash, $amz, $c['region'], 's3', $c['secret_access_key']);
    $hdr['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $c['access_key_id'] . '/' . $s['scope']
                          . ', SignedHeaders=' . $s['signed_headers'] . ', Signature=' . $s['signature'];
    $url = $c['endpoint'] . $uri . ($query ? '?' . r2_canonical_query($query) : '');
    $lines = [];
    foreach ($hdr as $k => $v) if ($k !== 'host') $lines[] = $k . ': ' . $v;
    $lines[] = 'Expect:';
    $ch = curl_init($url);
    $resp = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $lines,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$resp) {
            $p = strpos($line, ':');
            if ($p !== false) $resp[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    if ($body !== '' || in_array($method, ['PUT', 'POST'], true)) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) throw new R2Exception('Não foi possível falar com o R2 (' . $err . ').');
    return ['code' => $code, 'headers' => $resp, 'body' => (string)$out];
}

/** Lança uma exceção legível se a resposta não for 2xx. */
function r2_expect(array $r, string $what): array {
    if ($r['code'] >= 200 && $r['code'] < 300) return $r;
    $codeTxt = '';
    if (preg_match('~<Code>([^<]+)</Code>~', $r['body'], $m)) $codeTxt = $m[1];
    $msg = [
        'AccessDenied'          => 'O R2 recusou o acesso. Confirme a chave (Object Read & Write neste bucket).',
        'InvalidAccessKeyId'    => 'A Access Key ID do R2 não é válida.',
        'SignatureDoesNotMatch' => 'A Secret Access Key do R2 não está certa.',
        'NoSuchBucket'          => 'O bucket indicado no r2_config.php não existe.',
        'NoSuchKey'             => 'O ficheiro já não existe.',
        'NoSuchUpload'          => 'O envio em partes já não existe (expirou ou foi cancelado).',
        'InvalidPart'           => 'Uma das partes do envio não chegou ao R2.',
    ][$codeTxt] ?? ('O R2 devolveu um erro' . ($codeTxt !== '' ? ' (' . $codeTxt . ')' : ' (HTTP ' . $r['code'] . ')') . '.');
    throw new R2Exception($what . ': ' . $msg);
}

function r2_xml(string $body): SimpleXMLElement {
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if ($x === false) throw new R2Exception('O R2 respondeu algo que não se percebe.');
    return $x;
}

// ── operações ───────────────────────────────────────────────

/**
 * As pastas e ficheiros diretamente em $prefix.
 * ['folders' => [prefixo…], 'files' => [['key','name','size','modified'(ts),'etag']…]]
 */
function r2_list(string $prefix, int $maxPages = 20): array {
    $folders = []; $files = []; $token = null;
    for ($i = 0; $i < $maxPages; $i++) {
        $q = ['list-type' => '2', 'delimiter' => '/', 'prefix' => $prefix, 'max-keys' => '1000'];
        if ($token !== null) $q['continuation-token'] = $token;
        $x = r2_xml(r2_expect(r2_request('GET', '', $q), 'Listar a pasta')['body']);
        foreach ($x->CommonPrefixes as $cp) $folders[] = (string)$cp->Prefix;
        foreach ($x->Contents as $o) {
            $k = (string)$o->Key;
            if ($k === $prefix) continue;            // o marcador da própria pasta
            if (substr($k, -1) === '/') { $folders[] = $k; continue; }
            $files[] = [
                'key' => $k, 'name' => substr($k, strlen($prefix)), 'size' => (int)$o->Size,
                'modified' => strtotime((string)$o->LastModified) ?: 0, 'etag' => trim((string)$o->ETag, '"'),
            ];
        }
        if ((string)$x->IsTruncated !== 'true') break;
        $token = (string)$x->NextContinuationToken;
        if ($token === '') break;
    }
    $folders = array_values(array_unique($folders));
    return ['folders' => $folders, 'files' => $files];
}

/** HEAD: ['size','type','modified'] ou null. */
function r2_head(string $key): ?array {
    $r = r2_request('HEAD', $key);
    if ($r['code'] === 404) return null;
    r2_expect($r, 'Ler o ficheiro');
    return ['size' => (int)($r['headers']['content-length'] ?? 0), 'type' => (string)($r['headers']['content-type'] ?? ''),
            'modified' => strtotime((string)($r['headers']['last-modified'] ?? '')) ?: 0];
}

/** Cria o marcador de pasta («pasta/» com 0 bytes). */
function r2_mkdir(string $prefix): void {
    if (!r2_key_ok($prefix, true)) throw new R2Exception('Nome de pasta inválido.');
    r2_expect(r2_request('PUT', $prefix, [], '', ['content-type' => 'application/x-directory']), 'Criar a pasta');
}

/** Garante os marcadores de todas as pastas do caminho (a/, a/b/, a/b/c/). */
function r2_mkdir_p(string $prefix): void {
    $acc = '';
    foreach (explode('/', rtrim($prefix, '/')) as $seg) {
        $acc .= $seg . '/';
        if (r2_head($acc) === null) r2_mkdir($acc);
    }
}

function r2_delete(string $key): void {
    r2_expect(r2_request('DELETE', $key), 'Apagar');
}

/** Copia um objeto (até 5 GB, limite do CopyObject). */
function r2_copy(string $from, string $to): void {
    $c = r2_config();
    $src = '/' . $c['bucket'] . '/' . r2_enc($from, true);
    r2_expect(r2_request('PUT', $to, [], '', ['x-amz-copy-source' => $src], 120), 'Copiar');
}

function r2_mp_create(string $key, string $type): string {
    $r = r2_expect(r2_request('POST', $key, ['uploads' => ''], '', ['content-type' => $type]), 'Começar o envio');
    $id = (string)r2_xml($r['body'])->UploadId;
    if ($id === '') throw new R2Exception('O R2 não devolveu o identificador do envio.');
    return $id;
}

function r2_mp_part_url(string $key, string $uploadId, int $n): string {
    return r2_presign('PUT', $key, R2_URL_TTL, ['partNumber' => (string)$n, 'uploadId' => $uploadId]);
}

/** $parts: [[n, etag]…] */
function r2_mp_complete(string $key, string $uploadId, array $parts): void {
    usort($parts, static fn($a, $b) => $a[0] <=> $b[0]);
    $xml = '<CompleteMultipartUpload>';
    foreach ($parts as [$n, $etag]) {
        $xml .= '<Part><PartNumber>' . (int)$n . '</PartNumber><ETag>"' . htmlspecialchars(trim($etag, '"'), ENT_XML1) . '"</ETag></Part>';
    }
    $xml .= '</CompleteMultipartUpload>';
    $r = r2_expect(r2_request('POST', $key, ['uploadId' => $uploadId], $xml, ['content-type' => 'application/xml'], 120), 'Terminar o envio');
    // O S3 pode responder 200 com um erro no corpo.
    if (strpos($r['body'], '<Error>') !== false) r2_expect(['code' => 500] + $r, 'Terminar o envio');
}

function r2_mp_abort(string $key, string $uploadId): void {
    $r = r2_request('DELETE', $key, ['uploadId' => $uploadId]);
    if ($r['code'] !== 404) r2_expect($r, 'Cancelar o envio');
}

/** As partes já recebidas: [n => etag]. */
function r2_mp_parts(string $key, string $uploadId): array {
    $out = []; $marker = null;
    for ($i = 0; $i < 20; $i++) {
        $q = ['uploadId' => $uploadId, 'max-parts' => '1000'];
        if ($marker !== null) $q['part-number-marker'] = $marker;
        $x = r2_xml(r2_expect(r2_request('GET', $key, $q), 'Ver o envio')['body']);
        foreach ($x->Part as $p) $out[(int)$p->PartNumber] = trim((string)$p->ETag, '"');
        if ((string)$x->IsTruncated !== 'true') break;
        $marker = (string)$x->NextPartNumberMarker;
    }
    return $out;
}

/** Content-Disposition com nome UTF-8 (RFC 6266). */
function r2_disposition(string $name, bool $inline): string {
    $ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/u', '_', $name) ?? 'ficheiro';
    return ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}

/** URL de download/pré-visualização (curto). */
function r2_get_url(string $key, bool $inline, string $type = ''): string {
    $name = basename(str_replace('\\', '/', $key));
    $q = ['response-content-disposition' => r2_disposition($name, $inline)];
    if ($type !== '') $q['response-content-type'] = $type;
    return r2_presign('GET', $key, R2_GET_TTL, $q);
}

/** O tipo MIME pela extensão (o R2 guarda o que o browser mandou; isto é para a pré-visualização). */
function r2_mime(string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return [
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'mkv' => 'video/x-matroska',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf', 'zip' => 'application/zip', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'psd' => 'image/vnd.adobe.photoshop', 'ai' => 'application/postscript', 'prproj' => 'application/octet-stream',
    ][$ext] ?? 'application/octet-stream';
}
