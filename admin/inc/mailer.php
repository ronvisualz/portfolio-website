<?php
/**
 * topper. admin — envio de emails sem palavras-passe (§31.1)
 *
 * Por defeito os emails saem por um serviço transacional (Resend ou Brevo) com o domínio da empresa verificado e
 * uma chave API: não é preciso guardar a palavra-passe de nenhuma caixa. O remetente continua a ser a pessoa (o
 * seu email da empresa, ou o email geral) e as respostas vão para uma caixa ligada (ex.: info@), onde a
 * Cloudflare as apanha. O envio por SMTP do Hostinger (com a palavra-passe da caixa) fica como alternativa
 * em Definições › Integrações › Email.
 *
 * Também aqui: o segredo partilhado com o Worker do email (assinatura HMAC dos emails que chegam).
 *
 * Tudo fica em inc/fin_mail_config.php (fora do Git, fechado à web), escrito pela plataforma:
 *   'api'            => ['provider' => 'resend'|'brevo', 'key' => '…']
 *   'inbound_secret' => '…'   (o mesmo valor vai como secret do Worker: INBOUND_SECRET)
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/secrets.php';

const MAILER_PROVIDERS = ['resend' => 'Resend', 'brevo' => 'Brevo'];
const MAIL_SEND_MODES = ['api' => 'Serviço de envio (sem palavras-passe)', 'smtp' => 'SMTP do Hostinger (palavra-passe de cada caixa)'];

function mailer_file(): string { return __DIR__ . '/fin_mail_config.php'; }

/** Junta valores ao ficheiro do servidor (fin_mail_config.php) sem mexer no resto (caixas, anfitriões). */
function mailer_config_put(array $changes): void {
    $raw = config_file_read(mailer_file()) ?? ['boxes' => [], 'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 465, 'smtp_tls' => 'ssl',
                                               'imap_host' => 'imap.hostinger.com', 'imap_port' => 993, 'imap_tls' => 'ssl', 'from_name' => 'topper.'];
    foreach ($changes as $k => $v) { if ($v === null) unset($raw[$k]); else $raw[$k] = $v; }
    config_file_write(mailer_file(), "<?php\n// As caixas de email e o envio da plataforma, escritos pela própria plataforma (Definições › Integrações › Email e Perfil).\n"
        . "// Só no servidor: fora do Git e fechado à web. Ver fin_mail_config.example.php.\nreturn " . var_export($raw, true) . ";\n");
    if (function_exists('fin_mail_server')) fin_mail_server(true);
}

/** O serviço de envio configurado: ['provider', 'key'] ou null. */
function mailer_api(): ?array {
    $a = (array)((config_file_read(mailer_file()) ?? [])['api'] ?? []);
    $p = (string)($a['provider'] ?? '');
    return isset(MAILER_PROVIDERS[$p]) && config_filled($a['key'] ?? null) ? ['provider' => $p, 'key' => trim((string)$a['key'])] : null;
}

/** Como sai o email: 'api' (por defeito) ou 'smtp'. */
function mailer_mode(PDO $pdo): string {
    return (string)setting($pdo, 'mail_send_mode', 'api') === 'smtp' ? 'smtp' : 'api';
}

/** O envio pelo serviço está pronto? */
function mailer_api_on(PDO $pdo): bool {
    return mailer_mode($pdo) === 'api' && mailer_api() !== null;
}

/** Confirma a chave no serviço (sem enviar nada). Devolve o texto para a página. */
function mailer_api_check(string $provider, string $key): string {
    if ($provider === 'resend') {
        [$code, $j] = http_call('GET', ext_url('https://api.resend.com/domains'), ['Authorization: Bearer ' . $key], null, 15);
        if ($code === 200) {
            $ok = array_values(array_filter((array)($j['data'] ?? []), fn($d) => ($d['status'] ?? '') === 'verified'));
            return $ok ? 'Chave certa · domínio verificado: ' . implode(', ', array_map(fn($d) => (string)$d['name'], $ok)) : 'Chave certa, mas ainda não há nenhum domínio verificado no Resend (Domains › Add domain).';
        }
        // uma chave «só envio» não pode listar os domínios, mas é válida
        if ($code === 401 && str_contains(strtolower((string)($j['name'] ?? '')), 'restricted')) return 'Chave certa (só envio).';
        throw new RuntimeException('O Resend recusou a chave (' . $code . '). Nada foi gravado.');
    }
    if ($provider === 'brevo') {
        [$code, $j] = http_call('GET', ext_url('https://api.brevo.com/v3/account'), ['api-key: ' . $key, 'Accept: application/json'], null, 15);
        if ($code === 200) return 'Chave certa · conta ' . (string)($j['email'] ?? '');
        throw new RuntimeException('O Brevo recusou a chave (' . $code . '). Nada foi gravado.');
    }
    throw new RuntimeException('Escolha o serviço (Resend ou Brevo).');
}

/** Grava (ou tira, com $key null) a chave do serviço, depois de a confirmar. */
function mailer_api_save(string $provider, ?string $key): string {
    if ($key === null) { mailer_config_put(['api' => null]); return 'Chave tirada.'; }
    $key = trim($key);
    if ($key === '' || strlen($key) > 300 || preg_match('/[\s\x00]/', $key)) throw new RuntimeException('A chave não parece certa.');
    $msg = mailer_api_check($provider, $key);
    mailer_config_put(['api' => ['provider' => $provider, 'key' => $key]]);
    return $msg;
}

/**
 * Envia pelo serviço. $c: ['user' => remetente, 'from_name']. $o: reply_to, cc. Anexos [['name','mime','bytes']].
 * Devolve o id da mensagem no serviço.
 */
function mailer_api_send(array $c, string $to, string $subject, string $body, array $att = [], array $o = []): string {
    $api = mailer_api();
    if (!$api) throw new RuntimeException('O serviço de envio não está configurado (Definições › Integrações › Email).');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Endereço de email inválido: ' . $to);
    $name = trim(preg_replace('/[\r\n"<>]/', '', (string)($c['from_name'] ?? 'topper.'))) ?: 'topper.';
    $cc = array_values(array_filter((array)($o['cc'] ?? []), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    $reply = filter_var((string)($o['reply_to'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string)$o['reply_to'] : '';
    if ($api['provider'] === 'resend') {
        $msg = ['from' => $name . ' <' . $c['user'] . '>', 'to' => [$to], 'subject' => $subject, 'text' => $body];
        if ($cc) $msg['cc'] = $cc;
        if ($reply !== '') $msg['reply_to'] = [$reply];
        foreach ($att as $a) $msg['attachments'][] = ['filename' => (string)$a['name'], 'content' => base64_encode((string)$a['bytes'])];
        [$code, $j, $txt] = http_call('POST', ext_url('https://api.resend.com/emails'), ['Authorization: Bearer ' . $api['key']], $msg, 40);
        if ($code >= 300 || empty($j['id'])) throw new RuntimeException('O Resend não enviou (' . $code . ': ' . mb_substr((string)($j['message'] ?? $txt), 0, 160) . ').');
        return (string)$j['id'];
    }
    $msg = ['sender' => ['name' => $name, 'email' => $c['user']], 'to' => [['email' => $to]], 'subject' => $subject, 'textContent' => $body];
    if ($cc) $msg['cc'] = array_map(fn($e) => ['email' => $e], $cc);
    if ($reply !== '') $msg['replyTo'] = ['email' => $reply];
    foreach ($att as $a) $msg['attachment'][] = ['name' => (string)$a['name'], 'content' => base64_encode((string)$a['bytes'])];
    [$code, $j, $txt] = http_call('POST', ext_url('https://api.brevo.com/v3/smtp/email'), ['api-key: ' . $api['key'], 'Accept: application/json'], $msg, 40);
    if ($code >= 300 || empty($j['messageId'])) throw new RuntimeException('O Brevo não enviou (' . $code . ': ' . mb_substr((string)($j['message'] ?? $txt), 0, 160) . ').');
    return (string)$j['messageId'];
}

/**
 * Os emails que a plataforma envia sozinha (recuperar a palavra-passe, convites, avisos): pelo serviço de envio
 * quando está pronto; senão pelo mail() do servidor, como antes. O remetente é o de Definições (@ do domínio).
 */
function app_mail(PDO $pdo, string $to, string $subject, string $body, string $name = ''): bool {
    $from = (string)setting($pdo, 'mail_from', 'no-reply@topperdigital.xyz');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !str_ends_with(strtolower($from), '@topperdigital.xyz')) $from = 'no-reply@topperdigital.xyz';
    $name = trim(preg_replace('/[\r\n"<>]/', '', $name !== '' ? $name : (string)setting($pdo, 'mail_from_name', 'topper.'))) ?: 'topper.';
    if (mailer_api_on($pdo)) {
        try { mailer_api_send(['user' => $from, 'from_name' => $name], $to, $subject, $body); return true; }
        catch (Throwable $e) { error_log('[topper] o serviço de envio falhou (' . $e->getMessage() . '): vai pelo mail() do servidor'); }
    }
    $headers = 'From: =?UTF-8?B?' . base64_encode($name) . "?= <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Auto-Response-Suppress: All";
    $sent = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    if (defined('IS_LOCAL') && IS_LOCAL) error_log('[topper] email para ' . $to . ' (só na bancada): ' . $subject . "\n" . $body);
    elseif (!$sent) error_log('[topper] o email «' . $subject . '» não saiu para ' . $to);
    return (bool)$sent;
}

// ── o segredo partilhado com o Worker do email ──────────────

/** O segredo da assinatura dos emails que chegam ('' se ainda não foi gerado). */
function mailer_inbound_secret(): string {
    $s = (string)((config_file_read(mailer_file()) ?? [])['inbound_secret'] ?? '');
    return preg_match('/^[A-Za-z0-9_-]{32,128}$/', $s) ? $s : '';
}

/** Gera um segredo novo (o anterior deixa de valer): devolve-o uma vez, para o pôr no Worker. */
function mailer_inbound_secret_new(): string {
    $s = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    mailer_config_put(['inbound_secret' => $s]);
    return $s;
}

/** A assinatura de um email que chega: HMAC-SHA256 de «carimbo.message-id.sha256(corpo)». */
function mailer_inbound_sign(string $secret, string $ts, string $mid, string $bodyHash): string {
    return hash_hmac('sha256', $ts . '.' . $mid . '.' . $bodyHash, $secret);
}
