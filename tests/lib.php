<?php
/**
 * Testes da plataforma: scripts PHP simples, sem dependências (o servidor não tem Composer nem exec()).
 * Uso: `php tests/<ficheiro>_test.php` — imprime «OK n» e sai com 0, ou mostra as falhas e sai com 1.
 * Os testes de base de dados e HTTP correm na bancada (MariaDB `topper`, servidor em 127.0.0.1:8765).
 */

const TESTS_BASE = 'http://127.0.0.1:8765';

$GLOBALS['t_ok'] = 0;
$GLOBALS['t_fail'] = [];

function t_true(bool $c, string $msg): void {
    if ($c) $GLOBALS['t_ok']++;
    else { $GLOBALS['t_fail'][] = $msg; fwrite(STDERR, "FALHA: $msg\n"); }
}

function t_eq($got, $want, string $msg): void {
    t_true($got === $want, $msg . ($got === $want ? '' : ' — esperado ' . var_export($want, true) . ', obtido ' . var_export($got, true)));
}

function t_done(): void {
    if ($GLOBALS['t_fail']) { echo 'FALHOU ' . count($GLOBALS['t_fail']) . ' de ' . ($GLOBALS['t_ok'] + count($GLOBALS['t_fail'])) . "\n"; exit(1); }
    echo 'OK ' . $GLOBALS['t_ok'] . "\n";
    exit(0);
}

/**
 * Um pedido à bancada com a sessão de um utilizador (entra por /admin/__as/{uid}) e o token CSRF da página.
 * $data em POST vai como JSON (as APIs da Drive) ou formulário ($form = true). Devolve code, body, headers, json.
 */
function tests_http(string $method, string $path, array $data = [], int $uid = 1, bool $form = false, array $hdr = []): array {
    static $jars = [], $csrf = [];
    $jar = $jars[$uid] ??= tempnam(sys_get_temp_dir(), 'tjar');
    if ($uid > 0 && !isset($csrf[$uid])) {
        tests_curl('GET', TESTS_BASE . '/admin/__as/' . $uid, null, $jar, []);
        $page = tests_curl('GET', TESTS_BASE . '/admin/drive.php', null, $jar, []);
        $csrf[$uid] = preg_match('/<meta name="csrf" content="([^"]+)"/', $page['body'], $m) ? html_entity_decode($m[1]) : '';
    }
    $h = $hdr;
    if ($uid > 0) $h[] = 'X-CSRF-Token: ' . $csrf[$uid];
    $body = null;
    if ($method !== 'GET' && $data) {
        if ($form) $body = http_build_query($data + ($uid > 0 ? ['csrf' => $csrf[$uid]] : []));
        else { $body = json_encode($data); $h[] = 'Content-Type: application/json'; }
    }
    return tests_curl($method, str_starts_with($path, 'http') ? $path : TESTS_BASE . $path, $body, $uid > 0 ? $jar : null, $h);
}

function tests_curl(string $method, string $url, ?string $body, ?string $jar, array $hdr): array {
    $c = curl_init($url);
    $heads = [];
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $hdr,
        CURLOPT_HEADERFUNCTION => function ($ch, $l) use (&$heads) { if (strpos($l, ':')) { [$k, $v] = explode(':', $l, 2); $heads[strtolower(trim($k))] = trim($v); } return strlen($l); },
        CURLOPT_TIMEOUT => 60]);
    if ($jar) { curl_setopt($c, CURLOPT_COOKIEJAR, $jar); curl_setopt($c, CURLOPT_COOKIEFILE, $jar); }
    if ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $body);
    $out = (string)curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return ['code' => $code, 'body' => $out, 'headers' => $heads, 'json' => json_decode($out, true)];
}
