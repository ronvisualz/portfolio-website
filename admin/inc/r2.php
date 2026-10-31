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
function r2_request(string $method, string $key, array $query = [], string $body = '', array $headers = [], int $timeout = 30, int $maxBytes = 0): array {
    [$ch, $box] = r2_prepare($method, $key, $query, $body, $headers, $timeout, $maxBytes);
    $out = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false && $errno === CURLE_FILESIZE_EXCEEDED) throw new R2Exception('O ficheiro é grande demais para ser lido aqui.');
    if ($out === false) throw new R2Exception('Não foi possível falar com o R2 (' . $err . ').');
    if ($maxBytes > 0 && strlen((string)$out) > $maxBytes) throw new R2Exception('O ficheiro é grande demais para ser lido aqui.');
    return ['code' => $code, 'headers' => $box->h, 'body' => (string)$out];
}

/**
 * GET em streaming (§32.4): cada bloco do objeto vai para $sink(string) à medida que chega, sem o guardar em memória.
 * Lança R2Exception se o objeto não existir, se a resposta não for 200 ou se a ligação cair a meio.
 */
function r2_stream(string $key, callable $sink, int $timeout = 0): void {
    [$ch, $box] = r2_prepare('GET', $key, [], '', [], $timeout, 0);
    $bad = 0;
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use ($sink, &$bad): int {
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($code !== 200) { $bad = $code; return 0; }   // não passa uma página de erro para dentro do ZIP
        $sink($chunk);
        return strlen($chunk);
    });
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($bad || $code !== 200) throw new R2Exception('Não foi possível ler «' . basename($key) . '» (' . ($bad ?: $code) . ').');
    if ($ok === false) throw new R2Exception('A leitura de «' . basename($key) . '» falhou a meio (' . $err . ').');
}

/** O pedido assinado, pronto a correr: [handle do cURL, caixa onde caem os cabeçalhos da resposta (->h)]. */
function r2_prepare(string $method, string $key, array $query, string $body, array $headers, int $timeout, int $maxBytes): array {
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
    $box = new stdClass();
    $box->h = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $lines,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use ($box) {
            $p = strpos($line, ':');
            if ($p !== false) $box->h[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    if ($body !== '' || in_array($method, ['PUT', 'POST'], true)) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    // $maxBytes: o cURL nem começa a transferência se o content-length passar disto.
    if ($maxBytes > 0) curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes);
    return [$ch, $box];
}

/**
 * Vários pedidos pequenos ao mesmo tempo (no máximo $par em voo), para as operações com
 * muitos objetos (copiar, confirmar, apagar uma pasta de milhares de frames).
 * $reqs: [i => [método, chave, query?, corpo?, cabeçalhos?, timeout?]].
 * Devolve [i => ['code', 'headers', 'body']] pela mesma ordem; uma falha de rede fica
 * em ['code' => 0, …, 'error' => mensagem] (quem chama decide o que fazer).
 */
function r2_request_many(array $reqs, int $par = 16): array {
    $out = [];
    if (!$reqs) return $out;
    $mh = curl_multi_init();
    $queue = array_keys($reqs);
    $live = [];
    $fill = static function () use (&$queue, &$live, $reqs, $mh, $par): void {
        while ($queue && count($live) < max(1, $par)) {
            $i = array_shift($queue);
            $r = $reqs[$i];
            [$ch, $box] = r2_prepare((string)$r[0], (string)$r[1], $r[2] ?? [], (string)($r[3] ?? ''), $r[4] ?? [], (int)($r[5] ?? 60), 0);
            curl_multi_add_handle($mh, $ch);
            $live[spl_object_id($ch)] = [$i, $box, $ch];
        }
    };
    try {
        $fill();
        while ($live) {
            $st = curl_multi_exec($mh, $running);
            if ($st !== CURLM_OK) throw new R2Exception('Não foi possível falar com o R2 (' . curl_multi_strerror($st) . ').');
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $id = spl_object_id($ch);
                if (!isset($live[$id])) continue;
                [$i, $box] = $live[$id];
                unset($live[$id]);
                if ($info['result'] !== CURLE_OK) {
                    $out[$i] = ['code' => 0, 'headers' => [], 'body' => '', 'error' => 'Não foi possível falar com o R2 (' . curl_strerror($info['result']) . ').'];
                } else {
                    $out[$i] = ['code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $box->h, 'body' => (string)curl_multi_getcontent($ch)];
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                $fill();
            }
            if ($live && $running && curl_multi_select($mh, 1.0) === -1) usleep(2000);
        }
    } finally {
        foreach ($live as [, , $ch]) { curl_multi_remove_handle($mh, $ch); curl_close($ch); }
        curl_multi_close($mh);
    }
    $res = [];
    foreach ($reqs as $i => $_) $res[$i] = $out[$i] ?? ['code' => 0, 'headers' => [], 'body' => '', 'error' => 'Não foi possível falar com o R2.'];
    return $res;
}

/** Vários HEAD de uma vez: [i => ['size','type','modified','etag'] | null]. Lança se o R2 falhar. */
function r2_head_many(array $keys, int $par = 16): array {
    $reqs = [];
    foreach ($keys as $i => $k) $reqs[$i] = ['HEAD', (string)$k];
    $out = [];
    foreach (r2_request_many($reqs, $par) as $i => $r) {
        if (isset($r['error'])) throw new R2Exception($r['error']);
        $out[$i] = $r['code'] === 404 ? null : r2_head_parse(r2_expect($r, 'Ler o ficheiro'));
    }
    return $out;
}

function r2_head_parse(array $r): array {
    return ['size' => (int)($r['headers']['content-length'] ?? 0), 'type' => (string)($r['headers']['content-type'] ?? ''),
            'modified' => strtotime((string)($r['headers']['last-modified'] ?? '')) ?: 0,
            'etag' => strtolower(trim((string)($r['headers']['etag'] ?? ''), '"'))];
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

/** HEAD: ['size','type','modified','etag'] ou null. */
function r2_head(string $key): ?array {
    $r = r2_request('HEAD', $key);
    if ($r['code'] === 404) return null;
    return r2_head_parse(r2_expect($r, 'Ler o ficheiro'));
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

/**
 * Tudo debaixo de $prefix, sem delimitador (inclui os marcadores «pasta/»).
 * [['key','size','modified'(ts),'etag'], …] pela ordem do R2 (bytes UTF-8).
 * $startAfter: só as chaves depois desta (para continuar uma listagem longa).
 */
function r2_list_all(string $prefix = '', int $maxPages = 200, string $startAfter = ''): array {
    $out = []; $token = null;
    for ($i = 0; $i < $maxPages; $i++) {
        $q = ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => '1000'];
        if ($token !== null) $q['continuation-token'] = $token;
        elseif ($startAfter !== '') $q['start-after'] = $startAfter;
        $x = r2_xml(r2_expect(r2_request('GET', '', $q), 'Listar o Drive')['body']);
        foreach ($x->Contents as $o) {
            $out[] = [
                'key' => (string)$o->Key, 'size' => (int)$o->Size,
                'modified' => strtotime((string)$o->LastModified) ?: 0, 'etag' => strtolower(trim((string)$o->ETag, '"')),
            ];
        }
        if ((string)$x->IsTruncated !== 'true') break;
        $token = (string)$x->NextContinuationToken;
        if ($token === '') break;
    }
    return $out;
}

/**
 * Os bytes de um objeto (ou de um intervalo [início, fim], inclusivo).
 * Recusa objetos maiores do que $maxBytes sem os descarregar.
 */
function r2_get(string $key, ?array $range = null, int $maxBytes = 52428800): string {
    $h = [];
    if ($range !== null) {
        $a = max(0, (int)($range[0] ?? 0)); $b = (int)($range[1] ?? -1);
        if ($b < $a) throw new R2Exception('Intervalo de leitura inválido.');
        $h['range'] = 'bytes=' . $a . '-' . $b;
    }
    @set_time_limit(300);
    $r = r2_request('GET', $key, [], '', $h, 120, max(1, $maxBytes));
    if ($r['code'] === 404) throw new R2Exception('O ficheiro já não existe.', 404);
    if ($r['code'] === 416) return '';    // intervalo para lá do fim (ficheiro vazio ou mais curto)
    r2_expect($r, 'Ler o ficheiro');
    return $r['body'];
}

/** Um PUT pequeno feito pelo servidor (ex.: um ficheiro de texto gerado aqui). */
function r2_put(string $key, string $body, string $type): void {
    r2_expect(r2_request('PUT', $key, [], $body, ['content-type' => $type !== '' ? $type : 'application/octet-stream'], 120), 'Guardar');
}

/** Respostas de cópia: o S3 pode dizer 200 e trazer um <Error> no corpo. */
function r2_expect_copy(array $r, string $what): array {
    r2_expect($r, $what);
    if (strpos($r['body'], '<Error>') !== false) r2_expect(['code' => 500] + $r, $what);
    return $r;
}

/**
 * Copia um objeto de qualquer tamanho dentro do bucket.
 * Até $single bytes, um CopyObject (o limite do S3 é 5 GiB); acima, um envio em
 * partes com UploadPartCopy (partes de $part bytes, cada uma lida do original pelo
 * próprio R2). Se alguma parte falhar, o envio é cancelado e nada fica a meio.
 * $single e $part só mudam nos testes (o S3 exige partes ≥ 5 MiB, salvo a última).
 */
function r2_copy_any(string $from, string $to, int $size, int $single = 5368709120, int $part = 1073741824): void {
    $c = r2_config();
    if (!$c) throw new R2Exception('O armazenamento R2 não está configurado.');
    if (function_exists('set_time_limit')) @set_time_limit(0);
    $src = '/' . $c['bucket'] . '/' . r2_enc($from, true);
    if ($size <= $single) {
        r2_expect_copy(r2_request('PUT', $to, [], '', ['x-amz-copy-source' => $src], 900), 'Copiar');
        return;
    }
    $part = max($part, 5 * 1024 * 1024, (int)ceil($size / 10000));   // ≥ 5 MiB e no máximo 10 000 partes
    $h = r2_head($from);
    if ($h === null) throw new R2Exception('Copiar: O ficheiro já não existe.');
    if ($h['size'] !== $size) $size = $h['size'];
    $id = r2_mp_create($to, $h['type'] !== '' ? $h['type'] : r2_mime($to));
    try {
        $parts = [];
        for ($n = 1, $off = 0; $off < $size; $n++, $off += $part) {
            $end = min($size, $off + $part) - 1;
            $r = r2_expect_copy(r2_request('PUT', $to, ['partNumber' => (string)$n, 'uploadId' => $id], '', [
                'x-amz-copy-source' => $src, 'x-amz-copy-source-range' => 'bytes=' . $off . '-' . $end,
            ], 900), 'Copiar (parte ' . $n . ')');
            if (!preg_match('~<ETag>([^<]+)</ETag>~', $r['body'], $m)) throw new R2Exception('Copiar: o R2 não devolveu o ETag da parte ' . $n . '.');
            $parts[] = [$n, html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8')];
        }
        r2_mp_complete($to, $id, $parts);
    } catch (Throwable $e) {
        try { r2_mp_abort($to, $id); } catch (Throwable $e2) { /* o erro que interessa é o primeiro */ }
        throw $e instanceof R2Exception ? $e : new R2Exception('Copiar: ' . $e->getMessage());
    }
}

/** Apaga várias chaves de uma vez (DeleteObjects, até 1000 por pedido). */
function r2_delete_many(array $keys): void {
    foreach (array_chunk(array_values($keys), 1000) as $chunk) {
        $xml = '<Delete><Quiet>true</Quiet>';
        foreach ($chunk as $k) $xml .= '<Object><Key>' . htmlspecialchars((string)$k, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Key></Object>';
        $xml .= '</Delete>';
        $r = r2_expect(r2_request('POST', '', ['delete' => ''], $xml, [
            'content-type' => 'application/xml', 'content-md5' => base64_encode(md5($xml, true)),
        ], 120), 'Apagar');
        if (strpos($r['body'], '<Error>') !== false) {
            $msg = preg_match('~<Error>.*?<Key>([^<]*)</Key>~s', $r['body'], $m) ? ' («' . html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8') . '»)' : '';
            throw new R2Exception('Apagar: o R2 não conseguiu apagar tudo' . $msg . '.');
        }
    }
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

/** $parts: [[n, etag]…]. Devolve o ETag final (sem aspas, minúsculas; «…-N» nos multipart). */
function r2_mp_complete(string $key, string $uploadId, array $parts): string {
    usort($parts, static fn($a, $b) => $a[0] <=> $b[0]);
    $xml = '<CompleteMultipartUpload>';
    foreach ($parts as [$n, $etag]) {
        $xml .= '<Part><PartNumber>' . (int)$n . '</PartNumber><ETag>"' . htmlspecialchars(trim($etag, '"'), ENT_XML1) . '"</ETag></Part>';
    }
    $xml .= '</CompleteMultipartUpload>';
    $r = r2_expect(r2_request('POST', $key, ['uploadId' => $uploadId], $xml, ['content-type' => 'application/xml'], 120), 'Terminar o envio');
    // O S3 pode responder 200 com um erro no corpo.
    if (strpos($r['body'], '<Error>') !== false) r2_expect(['code' => 500] + $r, 'Terminar o envio');
    $etag = '';
    if (preg_match('~<ETag>([^<]*)</ETag>~', $r['body'], $m)) $etag = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
    if ($etag === '') $etag = (string)(r2_head($key)['etag'] ?? '');
    return strtolower(trim($etag, '"'));
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

/** URL de download/pré-visualização (curto, salvo $ttl). */
function r2_get_url(string $key, bool $inline, string $type = '', int $ttl = R2_GET_TTL, ?int $now = null): string {
    $name = basename(str_replace('\\', '/', $key));
    $q = ['response-content-disposition' => r2_disposition($name, $inline)];
    if ($type !== '') $q['response-content-type'] = $type;
    return r2_presign('GET', $key, $ttl, $q, $now);
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
