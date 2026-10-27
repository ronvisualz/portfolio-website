<?php
/**
 * topper. admin — segredos do servidor (§31)
 *
 * · A chave da plataforma (inc/app_key.php, criada sozinha na primeira vez, fora do Git e fechada à web) cifra os
 *   tokens guardados na base de dados (Google Calendar de cada pessoa). Quem tiver só a base não lê os tokens.
 * · Os ficheiros de configuração com credenciais (fin_mail_config.php, auth_config.php) são escritos pela própria
 *   plataforma a partir de Definições, sempre de forma atómica e só legíveis pelo servidor (0600). Nunca na base,
 *   nunca no Git, nunca mostrados outra vez na página.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** A chave de 32 bytes da plataforma (criada na primeira vez). */
function app_key(): string {
    static $k = null;
    if ($k !== null) return $k;
    $f = __DIR__ . '/app_key.php';
    if (is_file($f)) {
        $hex = (string)(require $f);
        if (preg_match('/^[a-f0-9]{64}$/', $hex)) return $k = (string)hex2bin($hex);
    }
    $hex = bin2hex(random_bytes(32));
    config_file_write($f, "<?php\n// A chave da plataforma: cifra os tokens guardados na base (Google Calendar). Criada sozinha; fora do Git.\n// Se se perder, as pessoas voltam a ligar o Google Calendar no Perfil (nada mais se perde).\nreturn '" . $hex . "';\n");
    return $k = (string)hex2bin($hex);
}

/** Cifra (autenticada) um texto com a chave da plataforma. */
function secret_seal(string $plain): string {
    $key = app_key();
    if (function_exists('sodium_crypto_secretbox')) {
        $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's1:' . base64_encode($n . sodium_crypto_secretbox($plain, $n, $key));
    }
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'g1:' . base64_encode($iv . $tag . $c);
}

/** Decifra; null se não for válido (chave mudou, texto mexido). */
function secret_open(string $sealed): ?string {
    $key = app_key();
    $raw = base64_decode(substr($sealed, 3), true);
    if ($raw === false) return null;
    if (str_starts_with($sealed, 's1:') && function_exists('sodium_crypto_secretbox_open')) {
        $n = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $n, $key);
        return $p === false ? null : $p;
    }
    if (str_starts_with($sealed, 'g1:')) {
        $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $p === false ? null : $p;
    }
    return null;
}

/** Escreve um ficheiro de configuração do servidor de forma atómica (0600). */
function config_file_write(string $file, string $php): void {
    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) throw new RuntimeException('Não consegui escrever no servidor (admin/inc). Nada foi gravado.');
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException('Não consegui gravar o ficheiro no servidor. Nada foi gravado.'); }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
}

/** Lê um ficheiro de configuração (array) ou null. */
function config_file_read(string $file): ?array {
    if (!is_file($file)) return null;
    if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
    $raw = require $file;
    return is_array($raw) ? $raw : null;
}

/** Um valor preenchido de verdade (não vazio nem o «COLE_AQUI» dos exemplos). */
function config_filled($v): bool {
    return is_string($v) && trim($v) !== '' && stripos(trim($v), 'COLE_') !== 0;
}

// ── as credenciais do login e do calendário (inc/auth_config.php) ──

/**
 * O cliente OAuth da Google (ID e segredo) para «Entrar com Google» e «Ligar Google Calendar». Vem de
 * inc/auth_config.php; sem ele, do cliente das reuniões (inc/meet_config.php) — o mesmo cliente serve para tudo.
 */
function google_client(): ?array {
    static $c = false;
    if ($c !== false) return $c;
    $raw = config_file_read(__DIR__ . '/auth_config.php') ?? [];
    $g = (array)($raw['google'] ?? []);
    if (!config_filled($g['client_id'] ?? null) || !config_filled($g['client_secret'] ?? null)) {
        $m = config_file_read(__DIR__ . '/meet_config.php') ?? [];
        $g = (array)($m['google'] ?? []);
    }
    if (!config_filled($g['client_id'] ?? null) || !config_filled($g['client_secret'] ?? null)) return $c = null;
    return $c = ['client_id' => trim((string)$g['client_id']), 'client_secret' => trim((string)$g['client_secret'])];
}

/** Grava o cliente OAuth da Google em inc/auth_config.php (Definições › Integrações). */
function google_client_save(string $id, string $secret): void {
    $id = trim($id); $secret = trim($secret);
    if (!preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/i', $id)) throw new RuntimeException('O ID de cliente da Google termina em «.apps.googleusercontent.com».');
    if ($secret === '' || strlen($secret) > 200 || preg_match('/[\s\x00]/', $secret)) throw new RuntimeException('O segredo do cliente não parece certo.');
    $f = __DIR__ . '/auth_config.php';
    $raw = config_file_read($f) ?? [];
    $raw['google'] = ['client_id' => $id, 'client_secret' => $secret];
    config_file_write($f, "<?php\n// As credenciais do login rápido e do calendário (Entrar com Google, Google Calendar de cada pessoa),\n// escritas pela plataforma (Definições › Integrações). Só no servidor: fora do Git e fechado à web.\n// Ver auth_config.example.php.\nreturn " . var_export($raw, true) . ";\n");
}

/** O endereço público da plataforma (para os links de volta da Google e dos emails). */
function app_base_url(): string {
    global $CONFIG;
    $base = rtrim((string)($CONFIG['app_url'] ?? ''), '/');
    if ($base !== '') return $base;
    if (defined('IS_LOCAL') && IS_LOCAL) return 'http://' . preg_replace('/[^a-z0-9.:-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));
    return 'https://topperhub.xyz';
}

/**
 * O endereço de uma API de fora (Google, serviço de envio). Só na bancada local (localhost) pode ser simulada com a
 * variável de ambiente TOPPER_API_MOCK (ex.: http://127.0.0.1:8791): em produção devolve sempre o verdadeiro.
 */
function ext_url(string $url): string {
    $mock = defined('IS_LOCAL') && IS_LOCAL ? rtrim((string)getenv('TOPPER_API_MOCK'), '/') : '';
    if ($mock === '' || !preg_match('~^http://(127\.0\.0\.1|localhost)(:\d+)?$~', $mock)) return $url;
    $u = parse_url($url);
    return $mock . '/' . $u['host'] . ($u['path'] ?? '') . (isset($u['query']) ? '?' . $u['query'] : '');
}

/**
 * Um pedido HTTPS (cURL) às APIs de fora (Google, serviço de envio). $body: array → JSON, string → como está.
 * Devolve [código, resposta em array (JSON) ou null, texto]. Nunca escreve credenciais no registo.
 */
function http_call(string $method, string $url, array $headers = [], $body = null, int $timeout = 20): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Este servidor não tem o cURL do PHP.');
    $ch = curl_init($url);
    if (is_array($body)) { $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $headers[] = 'Content-Type: application/json'; }
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8];
    if ($body !== null) $opt[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opt);
    $r = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($r === false) throw new RuntimeException('Sem ligação a ' . (parse_url($url, PHP_URL_HOST) ?: 'o serviço') . ($err !== '' ? ' (' . mb_substr($err, 0, 120) . ')' : '') . '.');
    $j = json_decode((string)$r, true);
    return [$code, is_array($j) ? $j : null, (string)$r];
}
