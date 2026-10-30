<?php
/**
 * topper. admin — Financeiro: faturas pelo contabilista e leitura das caixas de email (§28.14)
 *
 * Enquanto não há programa de faturação certificado:
 *  1. «Pedir fatura» — os dados do cliente e do serviço seguem por email (SMTP do Hostinger) para o
 *     contabilista, com o código no assunto: «[topper FAT-2026-0142] Pedido de fatura — NEG-0123». O
 *     lançamento fica «Fatura pedida». Sai da primeira caixa da lista (Definições › Integrações › Email).
 *  2. O cron lê, a cada 5 minutos, as caixas existentes escolhidas em Definições › Integrações › Email
 *     (ex.: info@topperdigital.xyz), por IMAP (imap.hostinger.com:993, SSL), em PHP puro:
 *       · só leitura — EXAMINE e BODY.PEEK: não marca como lido, não move, não apaga; guarda o UID da
 *         última mensagem vista por caixa (na primeira vez, olha só os últimos 7 dias);
 *       · primeiro os cabeçalhos (remetentes a ignorar, sem anexos → fora); só as mensagens com anexo
 *         PDF/XML/imagem são descarregadas;
 *       · lê o QR code fiscal / ATCUD do anexo (ou o XML da fatura eletrónica) sem IA; a IA só entra
 *         quando não há QR claro;
 *       · NIF do adquirente = o nosso → despesa («A pagar», por confirmar); NIF do emitente = o nosso →
 *         receita (liga ao pedido «Fatura pedida»). Os outros documentos são ignorados;
 *       · não guarda o corpo dos emails: só o anexo financeiro e o remetente, o assunto e a data.
 *  3. Liga a resposta ao pedido pelo código; sem código, pelos dados (NIF do cliente, total, data). Com dúvida
 *     fica «Por confirmar» com as opções — nunca liga sozinho com baixa confiança.
 *  4. Regista: o PDF vai para o Drive, compara os valores com o pedido, passa a «Emitida», conclui a tarefa.
 *  5. Avisa o responsável e o comercial; 6. «Enviar ao cliente» (PDF, IBAN, vencimento). «Pedir recibo» idem.
 *
 * Credenciais: só no servidor, em inc/fin_mail_config.php (fora do Git; ver fin_mail_config.example.php),
 * uma palavra-passe por caixa.
 *
 * Desde a §31.1, por defeito, nada disto precisa de palavras-passe: os emails chegam pela Cloudflare (Email Routing →
 * Worker → admin/fin_inbound.php, ver inc/mail_inbound.php) e saem por um serviço de envio com chave API
 * (inc/mailer.php). O leitor IMAP e o SMTP ficam como alternativa («Modo IMAP» / «SMTP do Hostinger»).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mail_inbound.php';

const FIN_MAIL_FIRST_DAYS = 7;              // primeira leitura de uma caixa: só os últimos dias
const FIN_MAIL_MAX_FETCH = 15;              // mensagens descarregadas por caixa e por volta do cron
const FIN_MAIL_MAX_BYTES = 25 * 1048576;    // acima disto não se descarrega

/**
 * A configuração do servidor (inc/fin_mail_config.php): anfitriões, portas e as caixas com a palavra-passe
 * [endereço => palavra-passe]. Aceita o formato antigo (user/pass de uma caixa). Null se faltar.
 */
function fin_mail_server(bool $fresh = false): ?array {
    static $c = false;
    if ($c !== false && !$fresh) return $c;
    $c = null;
    $raw = fin_mail_raw();
    if ($raw === null) return null;
    $boxes = [];
    foreach ((array)($raw['boxes'] ?? []) as $addr => $pass) {
        $addr = strtolower(trim((string)$addr)); $pass = (string)$pass;
        if (filter_var($addr, FILTER_VALIDATE_EMAIL) && $pass !== '' && stripos($pass, 'COLE_') !== 0) $boxes[$addr] = $pass;
    }
    $u = strtolower(trim((string)($raw['user'] ?? '')));
    if (filter_var($u, FILTER_VALIDATE_EMAIL) && ($p = (string)($raw['pass'] ?? '')) !== '' && stripos($p, 'COLE_') !== 0 && !isset($boxes[$u])) $boxes[$u] = $p;
    $host = fn($v, $d) => preg_match('/^[a-z0-9.-]+$/i', (string)$v) ? (string)$v : $d;
    return $c = ['boxes' => $boxes,
                 'smtp_host' => $host($raw['smtp_host'] ?? '', 'smtp.hostinger.com'), 'smtp_port' => (int)($raw['smtp_port'] ?? 465),
                 'smtp_tls' => (string)($raw['smtp_tls'] ?? 'ssl'), 'imap_host' => $host($raw['imap_host'] ?? '', 'imap.hostinger.com'),
                 'imap_port' => (int)($raw['imap_port'] ?? 993), 'imap_tls' => (string)($raw['imap_tls'] ?? 'ssl'),
                 'from_name' => mb_substr(trim((string)($raw['from_name'] ?? 'topper. Financeiro')), 0, 80), 'folder' => (string)($raw['folder'] ?? 'INBOX'),
                 'verify_peer' => ($raw['verify_peer'] ?? true) !== false];
}

/** As caixas escolhidas em Definições › Integrações › Email (endereços, por ordem). */
function fin_mail_boxes(PDO $pdo): array {
    $out = [];
    foreach (preg_split('/\R/', (string)setting($pdo, 'fin_mail_boxes', '')) as $l) {
        $l = strtolower(trim($l));
        if (filter_var($l, FILTER_VALIDATE_EMAIL) && !in_array($l, $out, true)) $out[] = $l;
    }
    return $out;
}

/** O ficheiro do servidor tal como está (ou null se não existe). */
function fin_mail_raw(): ?array {
    $f = __DIR__ . '/fin_mail_config.php';
    if (!is_file($f)) return null;
    if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
    $raw = require $f;
    return is_array($raw) ? $raw : null;
}

/**
 * Grava (ou tira, com $pass null) a palavra-passe de uma caixa no ficheiro do servidor, inc/fin_mail_config.php
 * (fora do Git, fechado à web). Antes de gravar, entra na caixa por IMAP para confirmar que está certa. Nunca
 * passa pela base de dados nem volta a ser mostrada.
 */
function fin_mail_save_pass(string $addr, ?string $pass): void {
    $addr = strtolower(trim($addr));
    if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Endereço inválido.');
    $raw = fin_mail_raw() ?? ['boxes' => [], 'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 465, 'smtp_tls' => 'ssl',
                               'imap_host' => 'imap.hostinger.com', 'imap_port' => 993, 'imap_tls' => 'ssl', 'from_name' => 'topper.'];
    $raw['boxes'] = (array)($raw['boxes'] ?? []);
    // o formato antigo (uma caixa em user/pass) passa para a lista
    if (!empty($raw['user']) && !empty($raw['pass'])) { $raw['boxes'][strtolower((string)$raw['user'])] ??= (string)$raw['pass']; }
    unset($raw['user'], $raw['pass']);
    if ($pass === null) unset($raw['boxes'][$addr]);
    else {
        if ($pass === '' || strlen($pass) > 200 || preg_match('/[\r\n\x00]/', $pass)) throw new RuntimeException('Palavra-passe inválida.');
        $srv = fin_mail_server() ?? ['imap_host' => (string)$raw['imap_host'], 'imap_port' => (int)$raw['imap_port'], 'imap_tls' => (string)$raw['imap_tls'], 'verify_peer' => true];
        try { (new FinImap($srv + ['user' => $addr, 'pass' => $pass]))->close(); }
        catch (Throwable $e) {
            throw new RuntimeException(preg_match('/autentica|AUTHENTICATIONFAILED|LOGIN|credentials/i', $e->getMessage())
                ? 'A palavra-passe de ' . $addr . ' não está certa. Nada foi gravado.'
                : 'Não consegui ligar a ' . $addr . ' (' . preg_replace('/^IMAP: /', '', $e->getMessage()) . '). Nada foi gravado.');
        }
        $raw['boxes'][$addr] = $pass;
    }
    $f = __DIR__ . '/fin_mail_config.php';
    $php = "<?php\n// As caixas de email da plataforma, escritas pela própria plataforma (Definições › Integrações › Email e Perfil).\n"
         . "// Só no servidor: fora do Git e fechado à web. Ver fin_mail_config.example.php.\nreturn " . var_export($raw, true) . ";\n";
    $tmp = $f . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) throw new RuntimeException('Não consegui escrever no servidor (admin/inc). Nada foi gravado.');
    @chmod($tmp, 0600);
    if (!@rename($tmp, $f)) { @unlink($tmp); throw new RuntimeException('Não consegui gravar o ficheiro no servidor. Nada foi gravado.'); }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
    fin_mail_server(true);
}

/** O email geral da empresa (Definições › Integrações › Email), de onde também se pode enviar. */
function mail_general(PDO $pdo): string {
    $g = strtolower(trim((string)setting($pdo, 'mail_general', 'info@topperdigital.xyz')));
    return filter_var($g, FILTER_VALIDATE_EMAIL) ? $g : 'info@topperdigital.xyz';
}

const FIN_MAIL_AS = ['own' => 'O meu email', 'general' => 'O email geral'];

/**
 * De onde pode enviar quem está na plataforma: o seu email de registo (se for do domínio da empresa) e o email
 * geral. Cada um: [endereço, se tem palavra-passe no servidor, porque não dá].
 */
function fin_mail_as_options(PDO $pdo, ?array $u): array {
    $srv = fin_mail_server();
    $gen = mail_general($pdo);
    $dom = substr($gen, strpos($gen, '@'));
    $own = strtolower(trim((string)($u['email'] ?? '')));
    $out = [];
    // pelo serviço de envio (§31.1) não há palavras-passe: basta o endereço ser do domínio da empresa
    if (mailer_mode($pdo) === 'api') {
        $api = mailer_api() !== null;
        $why = $api ? '' : 'falta a chave do serviço de envio (Definições › Integrações › Email)';
        if ($own !== '' && $own !== $gen) $out['own'] = ['addr' => $own, 'ok' => $api && str_ends_with($own, $dom), 'why' => !str_ends_with($own, $dom) ? 'não é um email ' . $dom : $why];
        $out['general'] = ['addr' => $gen, 'ok' => $api, 'why' => $why];
        return $out;
    }
    if ($own !== '' && $own !== $gen) {
        $okDom = str_ends_with($own, $dom);
        $out['own'] = ['addr' => $own, 'ok' => $okDom && $srv && isset($srv['boxes'][$own]),
                       'why' => !$okDom ? 'não é um email ' . $dom : (($srv && isset($srv['boxes'][$own])) ? '' : 'falta a palavra-passe (Perfil › Email de envio)')];
    }
    $out['general'] = ['addr' => $gen, 'ok' => $srv && isset($srv['boxes'][$gen]), 'why' => $srv && isset($srv['boxes'][$gen]) ? '' : 'falta a palavra-passe (Definições › Integrações › Email)'];
    return $out;
}

/** A escolha da pessoa (Perfil), ou o próprio email quando está pronto. */
function fin_mail_pref(PDO $pdo, ?array $u): string {
    $o = fin_mail_as_options($pdo, $u);
    $p = $u && function_exists('user_prefs') ? (string)(user_prefs($pdo, (int)$u['id'])['mail_as'] ?? '') : '';
    if (isset($o[$p])) return $p;
    return !empty($o['own']['ok']) ? 'own' : 'general';
}

/** Escolhe a origem só para este pedido («Enviar de» nos formulários). */
function fin_mail_use(?string $as): ?string {
    static $v = null;
    if (func_num_args() && $as !== '__get') $v = isset(FIN_MAIL_AS[(string)$as]) ? $as : null;
    return $v;
}

/**
 * A ligação para enviar: a configuração do servidor com o user/pass da origem de quem envia (o seu email ou o
 * geral, pela escolha no formulário ou no Perfil; se essa não estiver pronta, a outra) e o nome da pessoa como
 * remetente. Sem ninguém com sessão (cron), o email geral com o nome da empresa. Null se nenhuma está pronta.
 */
function fin_mail_config(): ?array {
    global $pdo;
    if (!$pdo instanceof PDO) return null;
    $api = mailer_mode($pdo) === 'api';
    $c = $api ? [] : fin_mail_server();
    if (!$api && (!$c || !$c['boxes'])) return null;
    $u = function_exists('current_user') ? current_user() : null;
    $opts = fin_mail_as_options($pdo, $u);
    $want = fin_mail_use('__get') ?? fin_mail_pref($pdo, $u);
    $name = $u ? trim(preg_replace('/[\r\n"<>]/', '', (string)($u['name'] ?? ''))) : '';
    if ($name === '') $name = trim(preg_replace('/[\r\n"<>]/', '', (string)setting($pdo, 'mail_from_name', 'topper.'))) ?: 'topper.';
    foreach (array_unique([$want, 'own', 'general']) as $k) {
        if ($api) { if (!empty($opts[$k]['ok'])) return ['api' => true, 'user' => $opts[$k]['addr'], 'from_name' => $name, 'as' => $k]; continue; }
        if (!empty($opts[$k]['ok'])) return array_merge($c, ['user' => $opts[$k]['addr'], 'pass' => $c['boxes'][$opts[$k]['addr']], 'from_name' => $name, 'as' => $k]);
    }
    return null;
}

/** Para onde respondem os pedidos ao contabilista: a própria caixa se é lida; senão a primeira caixa lida. */
function fin_mail_reply_box(PDO $pdo, array $c): ?string {
    $read = fin_mail_boxes($pdo);
    return in_array((string)($c['user'] ?? ''), $read, true) ? null : ($read[0] ?? null);
}

/** O «Enviar de» de um formulário (vazio se só há uma origem pronta). */
function fin_mail_as_select(PDO $pdo): string {
    $u = current_user();
    $opts = fin_mail_as_options($pdo, $u);
    $ready = array_filter($opts, fn($o) => $o['ok']);
    if (count($ready) < 2) return '';
    $pref = fin_mail_pref($pdo, $u);
    $h = '<div class="field"><label for="send-as">Enviar de</label><select id="send-as" name="send_as">';
    foreach ($opts as $k => $o) $h .= '<option value="' . $k . '"' . ($k === $pref ? ' selected' : '') . ($o['ok'] ? '' : ' disabled') . '>' . e(FIN_MAIL_AS[$k] . ' · ' . $o['addr']) . '</option>';
    return $h . '</select></div>';
}

/** Os remetentes a ignorar (endereços completos ou domínios «@loja.pt» / «loja.pt»). */
function fin_mail_ignored(PDO $pdo, string $from): bool {
    $from = strtolower(trim($from));
    if ($from === '') return false;
    foreach (preg_split('/\R/', (string)setting($pdo, 'fin_mail_ignore', '')) as $l) {
        $l = strtolower(trim($l));
        if ($l === '') continue;
        if (str_contains($l, '@') && !str_starts_with($l, '@')) { if ($from === $l) return true; continue; }
        $dom = ltrim($l, '@');
        if (str_ends_with($from, '@' . $dom) || str_ends_with($from, '.' . $dom)) return true;
    }
    return false;
}

/** O email do contabilista (Definições › Financeiro). */
function fin_accountant_email(PDO $pdo): string {
    $e = trim((string)setting($pdo, 'fin_accountant_email', ''));
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

// ── ligações (SSL/TLS) ──────────────────────────────────────

/** Abre a ligação: 'ssl' (porta 465/993) direto; 'starttls' a negociar depois; 'none' só para a bancada local. */
function fin_sock(string $host, int $port, string $tls, bool $verify, int $timeout = 20) {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $scheme = $tls === 'ssl' ? 'ssl://' : 'tcp://';
    if ($tls === 'none' && !in_array($host, ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Sem TLS só na própria máquina.');
    $s = @stream_socket_client($scheme . $host . ':' . $port, $errno, $err, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException('Sem ligação a ' . $host . ':' . $port . ' (' . ($err ?: 'erro ' . $errno) . ').');
    stream_set_timeout($s, $timeout);
    return $s;
}

// ── SMTP ────────────────────────────────────────────────────

function fin_smtp_read($s): array {
    $lines = [];
    while (($l = fgets($s, 2048)) !== false) {
        $lines[] = rtrim($l, "\r\n");
        if (strlen($l) < 4 || $l[3] === ' ') break;
    }
    if (!$lines) throw new RuntimeException('O servidor de email não respondeu.');
    return [(int)substr($lines[0], 0, 3), implode("\n", $lines)];
}

function fin_smtp_cmd($s, string $cmd, array $ok): string {
    fwrite($s, $cmd . "\r\n");
    [$code, $msg] = fin_smtp_read($s);
    if (!in_array($code, $ok, true)) throw new RuntimeException('SMTP: ' . (str_starts_with($cmd, 'AUTH') || str_contains($cmd, '=') && strlen($cmd) > 20 ? 'autenticação' : strtok($cmd, ' ')) . ' recusado (' . mb_substr($msg, 0, 160) . ').');
    return $msg;
}

/** Codifica um cabeçalho com acentos (RFC 2047). */
function fin_mail_hdr(string $s): string {
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/**
 * Envia um email (texto simples, UTF-8) com anexos [['name','mime','bytes']] pela caixa de envio (a primeira da lista).
 * $o: reply_to, cc, test (só liga e autentica). Devolve o Message-ID.
 */
function fin_smtp_send(string $to, string $subject, string $body, array $att = [], array $o = []): string {
    $c = $o['cred'] ?? fin_mail_config();
    if (!$c) throw new RuntimeException('Não há de onde enviar: ' . (isset($GLOBALS['pdo']) && mailer_mode($GLOBALS['pdo']) === 'api' ? 'falta a chave do serviço de envio (Definições › Integrações › Email).' : 'falta a palavra-passe do seu email (Perfil › Email de envio) ou do email geral (Definições › Integrações › Email).'));
    if (!empty($c['api'])) {
        // pelo serviço de envio: as respostas vão para o email geral (info@), que chega ao Financeiro pela Cloudflare
        global $pdo;
        if (empty($o['reply_to']) && $pdo instanceof PDO && $c['user'] !== mail_general($pdo)) $o['reply_to'] = mail_general($pdo);
        return mailer_api_send($c, $to, $subject, $body, $att, $o);
    }
    if (empty($o['test']) && !filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Endereço de email inválido: ' . $to);
    $s = fin_sock($c['smtp_host'], $c['smtp_port'], $c['smtp_tls'], $c['verify_peer']);
    try {
        [$code] = fin_smtp_read($s);
        if ($code !== 220) throw new RuntimeException('SMTP: o servidor não está pronto.');
        $ehlo = 'topper.' . preg_replace('/^[^@]+@/', '', $c['user']);
        $caps = fin_smtp_cmd($s, 'EHLO ' . $ehlo, [250]);
        if ($c['smtp_tls'] === 'starttls') {
            fin_smtp_cmd($s, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('SMTP: TLS falhou.');
            $caps = fin_smtp_cmd($s, 'EHLO ' . $ehlo, [250]);
        }
        if (stripos($caps, 'AUTH') !== false && stripos($caps, 'PLAIN') !== false) {
            fin_smtp_cmd($s, 'AUTH PLAIN ' . base64_encode("\0" . $c['user'] . "\0" . $c['pass']), [235]);
        } else {
            fin_smtp_cmd($s, 'AUTH LOGIN', [334]);
            fin_smtp_cmd($s, base64_encode($c['user']), [334]);
            fin_smtp_cmd($s, base64_encode($c['pass']), [235]);
        }
        if (!empty($o['test'])) { @fwrite($s, "QUIT\r\n"); return ''; }
        $rcpt = array_values(array_unique(array_filter(array_merge([$to], (array)($o['cc'] ?? [])), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
        fin_smtp_cmd($s, 'MAIL FROM:<' . $c['user'] . '>', [250]);
        foreach ($rcpt as $r) fin_smtp_cmd($s, 'RCPT TO:<' . $r . '>', [250, 251]);
        fin_smtp_cmd($s, 'DATA', [354]);
        $mid = '<' . bin2hex(random_bytes(12)) . '@' . preg_replace('/^[^@]+@/', '', $c['user']) . '>';
        $h = ['Date: ' . date('r'), 'From: ' . fin_mail_hdr($c['from_name']) . ' <' . $c['user'] . '>', 'To: <' . $to . '>', 'Subject: ' . fin_mail_hdr($subject), 'Message-ID: ' . $mid, 'MIME-Version: 1.0'];
        if (!empty($o['cc'])) $h[] = 'Cc: ' . implode(', ', array_map(fn($e) => '<' . $e . '>', (array)$o['cc']));
        if (!empty($o['reply_to'])) $h[] = 'Reply-To: <' . $o['reply_to'] . '>';
        $text = chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body))));
        if (!$att) {
            $msg = implode("\r\n", $h) . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $text;
        } else {
            $b = 'b' . bin2hex(random_bytes(10));
            $msg = implode("\r\n", $h) . "\r\nContent-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $text;
            foreach ($att as $a) {
                $n = preg_replace('/[\r\n"]/', '', (string)$a['name']);
                $msg .= "\r\n--$b\r\nContent-Type: " . $a['mime'] . '; name="' . fin_mail_hdr($n) . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . fin_mail_hdr($n) . "\"\r\n\r\n"
                      . chunk_split(base64_encode((string)$a['bytes']));
            }
            $msg .= "\r\n--$b--";
        }
        $msg = preg_replace('/^\./m', '..', $msg);
        fwrite($s, $msg . "\r\n.\r\n");
        [$code, $txt] = fin_smtp_read($s);
        if ($code !== 250) throw new RuntimeException('SMTP: a mensagem foi recusada (' . mb_substr($txt, 0, 160) . ').');
        @fwrite($s, "QUIT\r\n");
        return $mid;
    } finally {
        @fclose($s);
    }
}

// ── IMAP (o mínimo, em PHP puro) ────────────────────────────

final class FinImap {
    private $s;
    private int $n = 0;
    public string $caps = '';

    /** $c: a configuração do servidor com «user» e «pass» da caixa. */
    public function __construct(array $c) {
        $this->s = fin_sock($c['imap_host'], $c['imap_port'], $c['imap_tls'], $c['verify_peer'], 25);
        $greet = fgets($this->s, 4096);
        if ($greet === false || !str_starts_with($greet, '* OK')) throw new RuntimeException('IMAP: o servidor não está pronto.');
        if ($c['imap_tls'] === 'starttls') {
            $this->cmd('STARTTLS');
            if (!stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('IMAP: TLS falhou.');
        }
        $this->cmd('LOGIN ' . $this->q($c['user']) . ' ' . $this->q($c['pass']), 'autenticação');
        $this->caps = implode(' ', $this->cmd('CAPABILITY')[0]);
    }

    /** Uma string IMAP entre aspas (ou literal, se tiver caracteres estranhos). */
    public function q(string $v): string {
        if (preg_match('/[\r\n\x00]/', $v)) throw new RuntimeException('IMAP: valor inválido.');
        return '"' . addcslashes($v, '"\\') . '"';
    }

    /** Envia um comando e lê até à resposta marcada. Devolve [linhas sem tag, literais por ordem]. */
    public function cmd(string $c, string $what = ''): array {
        $tag = 'A' . (++$this->n);
        fwrite($this->s, $tag . ' ' . $c . "\r\n");
        $lines = []; $lits = [];
        while (true) {
            $l = fgets($this->s, 65536);
            if ($l === false) throw new RuntimeException('IMAP: a ligação caiu.');
            // literal {n}: lê n bytes e continua a linha
            while (preg_match('/\{(\d+)\}\r\n$/', $l, $m)) {
                $len = (int)$m[1]; $buf = '';
                while (strlen($buf) < $len) { $chunk = fread($this->s, min(65536, $len - strlen($buf))); if ($chunk === false || $chunk === '') throw new RuntimeException('IMAP: leitura interrompida.'); $buf .= $chunk; }
                $lits[] = $buf;
                $rest = fgets($this->s, 65536);
                $l = substr($l, 0, -2) . '[literal' . (count($lits) - 1) . ']' . ($rest === false ? '' : $rest);
            }
            if (str_starts_with($l, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK/i', $l)) throw new RuntimeException('IMAP: ' . ($what ?: strtok($c, ' ')) . ' recusado (' . trim(substr($l, strlen($tag) + 1, 160)) . ').');
                break;
            }
            $lines[] = rtrim($l, "\r\n");
        }
        return [$lines, $lits];
    }

    /** Abre a pasta só para leitura (EXAMINE: nada muda na caixa). Devolve [mensagens, UIDVALIDITY, UIDNEXT]. */
    public function examine(string $folder): array {
        [$lines] = $this->cmd('EXAMINE ' . $this->q($folder), 'abrir a pasta');
        $r = ['exists' => 0, 'validity' => 0, 'next' => 0];
        foreach ($lines as $l) {
            if (preg_match('/^\* (\d+) EXISTS/i', $l, $m)) $r['exists'] = (int)$m[1];
            if (preg_match('/UIDVALIDITY (\d+)/i', $l, $m)) $r['validity'] = (int)$m[1];
            if (preg_match('/UIDNEXT (\d+)/i', $l, $m)) $r['next'] = (int)$m[1];
        }
        return $r;
    }

    /** UIDs por um critério de pesquisa (ex.: «UID 120:*», «SINCE 17-Oct-2026»). */
    public function search(string $criteria): array {
        [$lines] = $this->cmd('UID SEARCH ' . $criteria);
        $out = [];
        foreach ($lines as $l) if (preg_match('/^\* SEARCH ?(.*)$/i', $l, $m)) foreach (preg_split('/\s+/', trim($m[1])) as $u) if ($u !== '') $out[] = (int)$u;
        sort($out);
        return $out;
    }

    /** Só os cabeçalhos e o tamanho de várias mensagens (BODY.PEEK: não marca como lidas). [uid => [size, cabeçalhos]] */
    public function headers(array $uids): array {
        if (!$uids) return [];
        [$lines, $lits] = $this->cmd('UID FETCH ' . implode(',', $uids) . ' (UID RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE MESSAGE-ID CONTENT-TYPE)])');
        $out = [];
        foreach ($lines as $l) {
            if (!preg_match('/FETCH \(.*\bUID (\d+)/i', $l, $m)) continue;
            $size = preg_match('/RFC822\.SIZE (\d+)/i', $l, $z) ? (int)$z[1] : 0;
            $hdr = preg_match('/\[literal(\d+)\]/', $l, $x) ? (string)($lits[(int)$x[1]] ?? '') : '';
            $out[(int)$m[1]] = [$size, $hdr];
        }
        return $out;
    }

    /** A mensagem inteira, sem a marcar como lida. */
    public function fetch(int $uid): string {
        [, $lits] = $this->cmd('UID FETCH ' . $uid . ' (BODY.PEEK[])');
        return $lits[0] ?? '';
    }

    public function close(): void {
        try { $this->cmd('LOGOUT'); } catch (Throwable $e) {}
        @fclose($this->s);
    }
}

// ── MIME ────────────────────────────────────────────────────

/** Descodifica um cabeçalho (RFC 2047) para UTF-8. */
function fin_mime_hdr(string $v): string {
    // sem palavras codificadas: o texto já vem em UTF-8 (SMTPUTF8) ou, nos servidores antigos, em Latin-1
    if (!str_contains($v, '=?')) return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
    $v = preg_replace('/\?=\s+=\?/', '?==?', $v);
    if (function_exists('iconv_mime_decode')) {
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($d !== false) return $d;
    }
    return preg_replace_callback('/=\?([^?]+)\?([BQ])\?([^?]*)\?=/i', function ($m) {
        $t = strtoupper($m[2]) === 'B' ? base64_decode($m[3]) : quoted_printable_decode(str_replace('_', ' ', $m[3]));
        return @mb_convert_encoding($t, 'UTF-8', $m[1]) ?: $t;
    }, $v);
}

/** Separa cabeçalhos e corpo; cabeçalhos em minúsculas (os repetidos juntos). */
function fin_mime_split(string $raw): array {
    $p = preg_split("/\r?\n\r?\n/", $raw, 2);
    $hraw = preg_replace("/\r?\n[ \t]+/", ' ', $p[0]);
    $h = [];
    foreach (preg_split("/\r?\n/", $hraw) as $l) {
        if (!str_contains($l, ':')) continue;
        [$k, $v] = explode(':', $l, 2);
        $k = strtolower(trim($k));
        $h[$k] = isset($h[$k]) ? $h[$k] . ', ' . trim($v) : trim($v);
    }
    return [$h, $p[1] ?? ''];
}

/** Um parâmetro de um cabeçalho (boundary, charset, name, filename — também em RFC 2231). */
function fin_mime_param(string $h, string $name): string {
    if (preg_match('/' . $name . '\*=(?:[^\']*\'[^\']*\')?("?)([^";]+)\1/i', $h, $m)) return rawurldecode($m[2]);
    if (preg_match('/' . $name . '="([^"]*)"/i', $h, $m) || preg_match('/' . $name . '=([^;\s]+)/i', $h, $m)) return fin_mime_hdr($m[1]);
    return '';
}

/**
 * Lê uma mensagem: assunto, de, message-id, data, texto e anexos [name, mime, bytes]. Desce pelas partes
 * multipart e pelas mensagens reencaminhadas (message/rfc822).
 */
function fin_mime_parse(string $raw): array {
    [$h, $body] = fin_mime_split($raw);
    $out = ['subject' => fin_mime_hdr((string)($h['subject'] ?? '')), 'from' => '', 'from_name' => '', 'message_id' => trim((string)($h['message-id'] ?? '')),
            'date' => isset($h['date']) && ($t = strtotime($h['date'])) ? date('Y-m-d H:i:s', $t) : null, 'text' => '', 'att' => [],
            'in_reply_to' => trim((string)($h['in-reply-to'] ?? '')) . ' ' . trim((string)($h['references'] ?? ''))];
    $from = fin_mime_hdr((string)($h['from'] ?? ''));
    if (preg_match('/<([^>]+)>/', $from, $m)) { $out['from'] = strtolower(trim($m[1])); $out['from_name'] = trim(str_replace($m[0], '', $from), " \"'"); }
    else $out['from'] = strtolower(trim($from));
    fin_mime_walk($h, $body, $out, 0);
    return $out;
}

function fin_mime_walk(array $h, string $body, array &$out, int $depth): void {
    if ($depth > 6) return;
    $ct = (string)($h['content-type'] ?? 'text/plain');
    $type = strtolower(trim(strtok($ct, ';')));
    $enc = strtolower(trim((string)($h['content-transfer-encoding'] ?? '')));
    if (str_starts_with($type, 'multipart/')) {
        $b = fin_mime_param($ct, 'boundary');
        if ($b === '') return;
        $parts = explode('--' . $b, $body);
        array_shift($parts);
        foreach ($parts as $p) {
            if (str_starts_with($p, '--')) break;
            [$ph, $pb] = fin_mime_split(ltrim($p, "\r\n"));
            fin_mime_walk($ph, preg_replace("/\r?\n$/", '', $pb), $out, $depth + 1);
        }
        return;
    }
    $data = $enc === 'base64' ? (string)base64_decode(preg_replace('/\s+/', '', $body)) : ($enc === 'quoted-printable' ? quoted_printable_decode($body) : $body);
    if ($type === 'message/rfc822') {
        [$ih, $ib] = fin_mime_split($data);
        $out['text'] .= "\n" . fin_mime_hdr((string)($ih['subject'] ?? ''));
        fin_mime_walk($ih, $ib, $out, $depth + 1);
        return;
    }
    $disp = (string)($h['content-disposition'] ?? '');
    $name = fin_mime_param($disp, 'filename') ?: fin_mime_param($ct, 'name');
    if ($name !== '' || stripos($disp, 'attachment') !== false || in_array($type, ['application/pdf', 'image/jpeg', 'image/png', 'application/octet-stream', 'application/xml', 'text/xml'], true)) {
        $mime = fin_sniff($data) ?: $type;
        if ($mime !== 'application/pdf' && (preg_match('/\.xml$/i', $name) || in_array($type, ['application/xml', 'text/xml'], true)) && preg_match('/^\s*</', $data)) $mime = 'application/xml';
        $out['att'][] = ['name' => $name ?: 'anexo', 'mime' => $mime, 'bytes' => $data];
        return;
    }
    if ($type === 'text/plain' || ($type === 'text/html' && $out['text'] === '')) {
        $cs = fin_mime_param($ct, 'charset') ?: 'UTF-8';
        $t = strcasecmp($cs, 'UTF-8') === 0 ? $data : (@mb_convert_encoding($data, 'UTF-8', $cs) ?: $data);
        if ($type === 'text/html') $t = html_entity_decode(strip_tags(preg_replace('/<(br|p|div)[^>]*>/i', "\n", $t)), ENT_QUOTES, 'UTF-8');
        $out['text'] .= "\n" . $t;
    }
}

// ── 1. pedir fatura ─────────────────────────────────────────

/** O próximo código (FAT-AAAA-NNNN ou REC-AAAA-NNNN). */
function fin_next_code(PDO $pdo, string $prefix): string {
    $y = date('Y', strtotime(today_lisbon()));
    $st = $pdo->prepare($prefix === 'REC' ? 'SELECT code FROM fin_mail WHERE code LIKE ? ORDER BY code DESC LIMIT 1'
                                          : 'SELECT req_code FROM fin_docs WHERE req_code LIKE ? ORDER BY req_code DESC LIMIT 1');
    $st->execute([$prefix . '-' . $y . '-%']);
    $last = (string)$st->fetchColumn();
    return sprintf('%s-%s-%04d', $prefix, $y, $last !== '' ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1);
}

/** Os dados por omissão de um pedido de fatura (do negócio, do cliente, do valor por faturar). */
function fin_request_defaults(PDO $pdo, ?array $deal): array {
    $rate = vat_rate($pdo);
    $v = ['scope' => $deal ? 'deal' : 'company', 'deal_id' => $deal['id'] ?? 0, 'party_name' => '', 'party_nif' => '', 'party_email' => '', 'party_address' => '',
          'description' => '', 'base' => '', 'vat_rate' => $rate, 'vat_note' => '', 'installment' => '', 'due_date' => date('Y-m-d', strtotime(today_lisbon() . ' +' . (int)setting($pdo, 'fin_terms_days', 30) . ' days')),
          'contact_id' => 0];
    if (!$deal) return $v;
    $st = $pdo->prepare('SELECT c.id, c.name, c.nif, c.email, c.address FROM contacts c WHERE c.id = ?');
    $st->execute([(int)($deal['company_id'] ?: $deal['contact_id'])]);
    $c = $st->fetch() ?: [];
    $invoiced = (float)$pdo->query("SELECT COALESCE(SUM(base), 0) FROM fin_docs WHERE deal_id = " . (int)$deal['id'] . " AND direction = 'in' AND deleted_at IS NULL AND status <> 'void'")->fetchColumn();
    $left = max(0, round((float)$deal['budget'] - $invoiced, 2));
    $intl = (int)($deal['is_international'] ?? 0) === 1;
    return array_merge($v, ['party_name' => (string)($c['name'] ?? ''), 'party_nif' => (string)($c['nif'] ?? ''), 'party_email' => (string)($c['email'] ?? ''), 'party_address' => (string)($c['address'] ?? ''),
        'contact_id' => (int)($c['id'] ?? 0), 'description' => (string)$deal['name'] . ((string)($deal['job_type'] ?? '') !== '' ? ' — ' . $deal['job_type'] : ''), 'base' => $left > 0 ? $left : '',
        'vat_rate' => (int)$deal['has_vat'] ? $rate : 0, 'vat_note' => !(int)$deal['has_vat'] && $intl ? fin_ps($pdo, 'vat_exempt_note') : '',
        'installment' => $invoiced > 0 ? 'Restante' : '']);
}

/** O texto do pedido ao contabilista. */
function fin_request_body(PDO $pdo, array $v, string $code, ?array $deal): string {
    $co = trim((string)setting($pdo, 'company_name', 'topper.')) ?: 'topper.';
    $vat = round((float)$v['base'] * (float)$v['vat_rate'] / 100, 2);
    $l = ["Bom dia,", '', 'Pedimos a emissão da seguinte fatura (referência ' . $code . '):', '',
          'CLIENTE', 'Nome: ' . $v['party_name'], 'NIF: ' . ($v['party_nif'] ?: '—'), 'Morada: ' . ($v['party_address'] ?: '—'), 'Email: ' . ($v['party_email'] ?: '—'), '',
          'SERVIÇO', 'Descrição: ' . $v['description'], 'Valor sem IVA: ' . eur((float)$v['base']),
          (float)$v['vat_rate'] > 0 ? 'IVA (' . fin_num((float)$v['vat_rate']) . '%): ' . eur($vat) : 'IVA: isento — ' . ($v['vat_note'] ?: 'indicar a menção aplicável'),
          'Total: ' . eur((float)$v['base'] + $vat)];
    if ($v['installment'] !== '') $l[] = 'Prestação: ' . $v['installment'];
    $l[] = 'Vencimento: ' . fin_date_pt($v['due_date']);
    if ($deal) $l[] = 'Referência interna: ' . $deal['code'] . ' — ' . $deal['name'];
    if (($v['notes'] ?? '') !== '') { $l[] = ''; $l[] = 'Notas: ' . $v['notes']; }
    $l[] = '';
    $l[] = 'Por favor responda a este email com o PDF da fatura em anexo, mantendo o código ' . $code . ' no assunto.';
    $l[] = '';
    $l[] = 'Obrigado,';
    $l[] = $co;
    return implode("\n", $l);
}

/**
 * «Pedir fatura»: valida, cria o lançamento «Fatura pedida» e envia ao contabilista. Se o envio falhar, o
 * lançamento não fica (nada a meio). Devolve [id, código].
 */
function fin_request_invoice(PDO $pdo, array $src, ?int $by): array {
    $to = fin_accountant_email($pdo);
    if ($to === '') throw new RuntimeException('Falta o email do contabilista em Definições › Financeiro.');
    $deal = null;
    if (($src['scope'] ?? '') === 'deal') {
        $deal = billing_deal($pdo, (int)($src['deal_id'] ?? 0));
        if (!$deal) throw new RuntimeException('Escolha o negócio.');
    }
    $v = fin_doc_input($pdo, ['direction' => 'in', 'doc_type' => 'invoice', 'party_kind' => 'client', 'category' => ''] + $src);
    if ($v['base'] <= 0) throw new RuntimeException('Indique o valor sem IVA.');
    if ($v['party_nif'] === '' && empty($src['no_nif'])) throw new RuntimeException('Indique o NIF do cliente (ou marque «consumidor final / sem NIF»).');
    $code = fin_next_code($pdo, 'FAT');
    $subject = '[topper ' . $code . '] Pedido de fatura' . ($deal ? ' — ' . $deal['code'] : ' — ' . $v['party_name']);
    $body = trim((string)($src['body'] ?? '')) ?: fin_request_body($pdo, $v + ['notes' => $v['notes']], $code, $deal);
    if (!str_contains($body, $code)) $body .= "\n\nReferência: " . $code;
    $pdo->beginTransaction();
    try {
        $id = fin_doc_create($pdo, $v + ['status' => 'requested', 'req_code' => $code, 'req_body' => $body, 'number' => ''], $by);
        fin_smtp_send($to, $subject, $body, [], ['reply_to' => fin_mail_reply_box($pdo, (array)fin_mail_config())]);
        $pdo->prepare('UPDATE fin_docs SET req_sent_at = ? WHERE id = ?')->execute([fin_now(), $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e instanceof RuntimeException ? $e : new RuntimeException('O pedido não seguiu: ' . $e->getMessage());
    }
    if ($deal) deal_log($pdo, (int)$deal['id'], 'billing', ['note' => 'Fatura pedida ao contabilista (' . $code . ') · ' . eur($v['total']), 'user_id' => $by]);
    return [$id, $code];
}

/** «Pedir recibo» de uma fatura paga: o mesmo circuito, com código REC-…; a resposta junta o recibo. */
function fin_request_receipt(PDO $pdo, array $d, ?int $by): string {
    $to = fin_accountant_email($pdo);
    if ($to === '') throw new RuntimeException('Falta o email do contabilista em Definições › Financeiro.');
    if (!in_array($d['status'], ['paid', 'partial'], true)) throw new RuntimeException('Só se pede o recibo de uma fatura com pagamentos.');
    $code = fin_next_code($pdo, 'REC');
    $pays = fin_payments($pdo, (int)$d['id']);
    $l = ['Bom dia,', '', 'Pedimos a emissão do recibo da fatura ' . fin_doc_label($d) . ' (' . $d['party_name'] . ($d['party_nif'] !== '' ? ', NIF ' . $d['party_nif'] : '') . ').', ''];
    foreach ($pays as $p) $l[] = '· ' . eur((float)$p['amount']) . ' recebidos a ' . fin_date_pt($p['paid_on']);
    $l[] = '';
    $l[] = 'Por favor responda com o PDF do recibo, mantendo o código ' . $code . ' no assunto.';
    $l[] = '';
    $l[] = 'Obrigado,';
    $l[] = trim((string)setting($pdo, 'company_name', 'topper.')) ?: 'topper.';
    fin_smtp_send($to, '[topper ' . $code . '] Pedido de recibo — ' . fin_doc_label($d), implode("\n", $l), [], ['reply_to' => fin_mail_reply_box($pdo, (array)fin_mail_config())]);
    $pdo->prepare('UPDATE fin_docs SET notes = ?, updated_at = ? WHERE id = ?')->execute([trim($d['notes'] . "\n" . date('d/m/Y') . ' · Recibo pedido (' . $code . ')'), fin_now(), (int)$d['id']]);
    $pdo->prepare('INSERT INTO fin_mail (uid, message_id, from_addr, subject, code, status, doc_id, note, created_at) VALUES (\'\', \'\', ?, ?, ?, \'waiting\', ?, ?, ?)')
        ->execute([$to, 'Pedido de recibo — ' . fin_doc_label($d), $code, (int)$d['id'], 'Pedido enviado', fin_now()]);
    return $code;
}

// ── 2–5. ler as caixas ──────────────────────────────────────

/** O estado de leitura por caixa (UID visto, UIDVALIDITY, última leitura, erro, contagens). */
function fin_mail_state(PDO $pdo): array {
    require_once __DIR__ . '/ai.php';
    return json_decode((string)ai_setting_get($pdo, 'fin_mail_state'), true) ?: [];
}

function fin_mail_state_save(PDO $pdo, array $st): void {
    ai_setting_set_safe($pdo, 'fin_mail_state', json_encode($st, JSON_UNESCAPED_UNICODE));
}

/** Lê as caixas escolhidas (só leitura). Devolve o resumo para o registo do cron. */
function fin_mail_poll(PDO $pdo, float $seconds = 40.0): string {
    if (!fin_ready($pdo)) return '';
    if (fin_mail_mode($pdo) !== 'imap') return '';   // por defeito chegam pela Cloudflare (§31.1): o leitor IMAP fica desligado
    $srv = fin_mail_server();
    $boxes = fin_mail_boxes($pdo);
    if (!$boxes) return 'Caixas de email: nenhuma escolhida';
    $deadline = microtime(true) + $seconds;
    $state = fin_mail_state($pdo);
    $sum = [];
    foreach ($boxes as $addr) {
        if (microtime(true) > $deadline) break;
        $st = $state[$addr] ?? [];
        if (!$srv || !isset($srv['boxes'][$addr])) { $st['error'] = 'Falta a palavra-passe no servidor (inc/fin_mail_config.php).'; $state[$addr] = $st; $sum[] = $addr . ': sem palavra-passe'; continue; }
        try {
            $r = fin_mail_poll_box($pdo, $srv + ['user' => $addr, 'pass' => $srv['boxes'][$addr]], $st, $deadline);
            $st['error'] = '';
            $sum[] = $addr . ': ' . $r['seen'] . ' novas, ' . $r['docs'] . ' com documento';
        } catch (Throwable $e) {
            $st['error'] = mb_substr($e->getMessage(), 0, 300);
            error_log('fin_mail_poll ' . $addr . ': ' . $e->getMessage());
            $sum[] = $addr . ': erro — ' . $st['error'];
        }
        $st['last'] = fin_now();
        $state[$addr] = $st;
        fin_mail_state_save($pdo, $state);
    }
    fin_mail_state_save($pdo, $state);
    return 'Caixas de email: ' . implode(' · ', $sum);
}

/**
 * Uma caixa: EXAMINE (só leitura), as mensagens depois do último UID visto (na primeira vez ou se a caixa
 * mudou de UIDVALIDITY, as dos últimos dias), primeiro só os cabeçalhos; descarrega (BODY.PEEK) as que têm
 * anexos e não vêm de um remetente a ignorar. $st fica com o novo UID e as contagens.
 */
function fin_mail_poll_box(PDO $pdo, array $c, array &$st, float $deadline): array {
    $im = new FinImap($c);
    $seen = 0; $docs = 0;
    try {
        $box = $im->examine($c['folder']);
        $st['exists'] = $box['exists'];
        if (empty($st['validity']) || (int)$st['validity'] !== $box['validity'] || !isset($st['uid'])) {
            $uids = $im->search('SINCE ' . date('j-M-Y', strtotime(today_lisbon() . ' -' . FIN_MAIL_FIRST_DAYS . ' days')));
            $st = ['validity' => $box['validity'], 'uid' => 0, 'since' => fin_now(), 'seen' => 0, 'docs' => 0] + $st;
        } else {
            $uids = (int)$st['uid'] + 1 < max(1, $box['next']) || !$box['next'] ? $im->search('UID ' . ((int)$st['uid'] + 1) . ':*') : [];
        }
        $uids = array_values(array_filter($uids, fn($u) => $u > (int)$st['uid']));   // «n:*» devolve a última mesmo se for anterior
        $fetched = 0;
        foreach (array_chunk($uids, 40) as $chunk) {
            if (microtime(true) > $deadline) break;
            $heads = $im->headers($chunk);
            foreach ($chunk as $uid) {
                if (microtime(true) > $deadline || $fetched >= FIN_MAIL_MAX_FETCH) break 2;
                [$size, $hdr] = $heads[$uid] ?? [0, ''];
                $seen++;
                $st['uid'] = $uid;
                [$h] = fin_mime_split($hdr . "\r\n\r\n");
                $from = fin_mime_parse($hdr . "\r\n\r\n")['from'];
                $ct = strtolower((string)($h['content-type'] ?? 'text/plain'));
                if (fin_mail_ignored($pdo, $from)) continue;
                if (!str_starts_with($ct, 'multipart/') && !preg_match('#^(application/(pdf|xml|octet-stream)|text/xml|image/(jpeg|png))#', $ct)) continue;   // sem anexos
                if ($size > FIN_MAIL_MAX_BYTES) continue;
                $mid = trim((string)($h['message-id'] ?? ''));
                if ($mid !== '') {
                    $q = $pdo->prepare('SELECT COUNT(*) FROM fin_mail WHERE message_id = ?');
                    $q->execute([mb_substr($mid, 0, 255)]);
                    if ((int)$q->fetchColumn()) continue;   // a mesma mensagem noutra caixa (ou já vista)
                }
                $raw = $im->fetch($uid);
                $fetched++;
                if ($raw === '') continue;
                $m = fin_mime_parse($raw);
                unset($raw);
                $mid = $m['message_id'] !== '' ? mb_substr($m['message_id'], 0, 255) : 'uid:' . $c['user'] . ':' . $box['validity'] . ':' . $uid;
                if (fin_mail_handle($pdo, $m, $mid, $c['user'], (string)$uid) !== null) $docs++;
            }
        }
    } finally { $im->close(); }
    $st['seen'] = (int)($st['seen'] ?? 0) + $seen;
    $st['docs'] = (int)($st['docs'] ?? 0) + $docs;
    return ['seen' => $seen, 'docs' => $docs];
}

function ai_setting_set_safe(PDO $pdo, string $k, string $v): void {
    require_once __DIR__ . '/ai.php';
    ai_setting_set($pdo, $k, $v);
}

/** Os anexos que podem ser documentos: PDF, XML e imagens (os logótipos pequenos das assinaturas ficam de fora). */
function fin_mail_docs(array $m): array {
    return array_values(array_filter($m['att'], fn($a) => in_array($a['mime'], ['application/pdf', 'application/xml'], true)
        || (in_array($a['mime'], ['image/jpeg', 'image/png'], true) && strlen($a['bytes']) > 30000)));
}

/** O NIF da empresa (Definições › Financeiro › Empresa), só algarismos. */
function fin_our_nif(PDO $pdo): string {
    return preg_replace('/\D/', '', preg_replace('/^PT/i', '', trim((string)setting($pdo, 'company_nif', ''))));
}

/** Os nomes por que a empresa aparece nas faturas: o nome legal, o comercial, «topper», «Topper Digital». */
function fin_our_names(PDO $pdo): array {
    $n = [trim((string)setting($pdo, 'company_legal_name', 'Feitiço Constante, Lda.')), trim((string)setting($pdo, 'company_name', 'topper.')), 'Topper Digital', 'topper'];
    return array_values(array_unique(array_filter($n, fn($x) => mb_strlen(trim($x, ' .')) >= 5)));
}

/** Os nomes a procurar no texto de um PDF: só os inconfundíveis (o nome legal e «Topper Digital»; «topper» sozinho aparece em emails e domínios). */
function fin_text_names(PDO $pdo): array {
    return array_values(array_unique(array_filter([trim((string)setting($pdo, 'company_legal_name', 'Feitiço Constante, Lda.')), 'Topper Digital'], 'strlen')));
}

/** Este nome é o da empresa? (sem acentos, maiúsculas, «Lda.», pontuação) */
function fin_is_us(PDO $pdo, string $name): bool {
    $norm = fn(string $s) => trim(preg_replace('/\s+/', ' ', preg_replace('/\b(lda|sa|unipessoal|limitada)\b/', '', strtolower(preg_replace('/[^a-z0-9 ]/i', ' ', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s))))));
    $n = $norm($name);
    if (strlen($n) < 5) return false;
    foreach (fin_our_names($pdo) as $o) { $k = $norm($o); if (strlen($k) >= 5 && (str_contains($n, $k) || str_contains($k, $n))) return true; }
    return false;
}

/** A classificação escolhida à mão para este emitente (NIF) ou remetente: 'in' | 'out' | ''. */
function fin_class_rule(PDO $pdo, string $nif, string $email): string {
    if (!db_has_table($pdo, 'fin_class_rules')) return '';
    $nif = preg_replace('/\D/', '', $nif);
    $st = $pdo->prepare("SELECT direction FROM fin_class_rules WHERE (nif <> '' AND nif = ?) OR (nif = '' AND email <> '' AND email = ?) ORDER BY nif = '' LIMIT 1");
    $st->execute([$nif, strtolower(trim($email))]);
    return (string)$st->fetchColumn();
}

/** Guarda a escolha como regra: o mesmo emitente (pelo NIF; sem NIF, pelo remetente) passa a ser classificado igual. */
function fin_class_rule_set(PDO $pdo, string $nif, string $email, string $dir, ?int $by): void {
    if (!db_has_table($pdo, 'fin_class_rules') || !in_array($dir, ['in', 'out'], true)) return;
    $nif = preg_replace('/\D/', '', $nif);
    $email = $nif !== '' ? '' : strtolower(trim($email));
    if ($nif === '' && $email === '') return;
    $pdo->prepare('DELETE FROM fin_class_rules WHERE nif = ? AND email = ?')->execute([$nif, $email]);
    $pdo->prepare('INSERT INTO fin_class_rules (nif, email, direction, created_by, created_at) VALUES (?,?,?,?,?)')->execute([$nif, $email, $dir, $by, fin_now()]);
}

/** O estado de uma regra antes de mexer (para o «Anular»): [nif, email, direção atual ou '']. */
function fin_class_rule_snapshot(PDO $pdo, string $nif, string $email): array {
    $nif = preg_replace('/\D/', '', $nif);
    $email = $nif !== '' ? '' : strtolower(trim($email));
    $cur = '';
    if (db_has_table($pdo, 'fin_class_rules')) {
        $st = $pdo->prepare('SELECT direction FROM fin_class_rules WHERE nif = ? AND email = ?');
        $st->execute([$nif, $email]);
        $cur = (string)$st->fetchColumn();
    }
    return ['nif' => $nif, 'email' => $email, 'dir' => $cur];
}

/** Repõe uma regra como estava (sem regra, ou a direção de antes). */
function fin_class_rule_restore(PDO $pdo, array $snap): void {
    if (!db_has_table($pdo, 'fin_class_rules') || ($snap['nif'] === '' && $snap['email'] === '')) return;
    if ($snap['dir'] === '') $pdo->prepare('DELETE FROM fin_class_rules WHERE nif = ? AND email = ?')->execute([$snap['nif'], $snap['email']]);
    else fin_class_rule_set($pdo, $snap['nif'], $snap['email'], $snap['dir'], null);
}

/** O emitente de um documento lido (NIF) e o remetente, para as regras. */
function fin_rule_keys(?array $data, string $from): array {
    return [preg_replace('/\D/', '', (string)($data['issuer_nif'] ?? '')), strtolower(trim($from))];
}

/**
 * Passa um lançamento de A pagar para A receber (ou ao contrário): direção, contraparte (o outro lado do documento,
 * se foi lido), tipo de contraparte e estado («Por confirmar» numa despesa, «Emitida» numa receita). Não mexe em
 * lançamentos com pagamentos. Devolve [o lançamento antes, a regra antes, a mensagem].
 */
function fin_doc_flip(PDO $pdo, int $id, ?int $by): array {
    $d = fin_doc($pdo, $id);
    if (!$d) throw new RuntimeException('Esse lançamento já não existe.');
    if ((float)$d['paid_amount'] > 0) throw new RuntimeException('Já tem pagamentos registados: anule-os antes de o passar entre A pagar e A receber.');
    if ($d['payroll_id'] || $d['fixed_id'] || $d['billing_id']) throw new RuntimeException('Este lançamento vem de salários, custos fixos ou da faturação de um negócio: não muda de lado.');
    $to = $d['direction'] === 'out' ? 'in' : 'out';
    $ai = json_decode((string)$d['ai_data'], true) ?: [];
    $upd = ['direction' => $to, 'party_kind' => $to === 'in' ? 'client' : 'supplier', 'status' => $to === 'in' ? 'issued' : 'review', 'needs_review' => 1];
    $name = (string)($to === 'in' ? ($ai['client_name'] ?? '') : ($ai['issuer_name'] ?? ''));
    $nif = (string)($to === 'in' ? ($ai['client_nif'] ?? '') : ($ai['issuer_nif'] ?? ''));
    if ($nif !== '' && $nif !== fin_our_nif($pdo)) { $upd['party_nif'] = $nif; if ($name !== '') $upd['party_name'] = $name; }
    if ($to === 'in') { $upd['category'] = ''; $upd['vat_deductible'] = 0; }
    elseif ((string)$d['category'] === '') $upd['category'] = 'Outros';
    [$rn, $re] = fin_rule_keys($ai ?: ['issuer_nif' => $d['direction'] === 'out' ? $d['party_nif'] : ''], (string)$d['party_email']);
    $snap = fin_class_rule_snapshot($pdo, $rn, $re);
    fin_doc_update($pdo, $id, $upd);
    fin_class_rule_set($pdo, $rn, $re, $to, $by);
    return [$d, $snap, $to === 'in' ? 'Passou para «A receber» (fatura emitida), por confirmar.' : 'Passou para «A pagar» (despesa), por confirmar.'];
}

/**
 * De que lado está a empresa num documento que chegou: ['out' despesa | 'in' receita | '' não sei, porquê].
 * Ordem: o NIF da empresa como emitente/adquirente; o nome legal; a regra escolhida à mão para o emitente/remetente;
 * o contabilista sem código de «Pedir fatura» (é quase sempre a avença dele, uma despesa).
 */
function fin_mail_role(PDO $pdo, ?array $data, array $m, string $code): array {
    $our = fin_our_nif($pdo);
    $dig = fn($v) => preg_replace('/\D/', '', (string)$v);
    if ($data) {
        if ($our !== '' && $dig($data['issuer_nif'] ?? '') === $our) return ['in', 'o NIF da empresa é o do emitente'];
        if ($our !== '' && $dig($data['client_nif'] ?? '') === $our) return ['out', 'o NIF da empresa é o do adquirente'];
        if (!empty($data['we_issue']) || fin_is_us($pdo, (string)($data['issuer_name'] ?? ''))) return ['in', 'a empresa é a emitente'];
        if (!empty($data['named_us']) || fin_is_us($pdo, (string)($data['client_name'] ?? ''))) return ['out', 'a empresa (nome legal) é a adquirente'];
    }
    if (($r = fin_class_rule($pdo, (string)($data['issuer_nif'] ?? ''), (string)$m['from'])) !== '') return [$r, 'como escolhido antes para este ' . (($data['issuer_nif'] ?? '') !== '' ? 'emitente' : 'remetente')];
    $acc = strtolower(fin_accountant_email($pdo));
    if ($acc !== '' && $acc === strtolower((string)$m['from']) && !str_starts_with($code, 'FAT-')) return ['out', 'enviado pelo contabilista sem código de «Pedir fatura»'];
    return ['', ''];
}

/** Sem dados lidos: parece um documento financeiro? (vem do contabilista, ou o assunto/nome do ficheiro di-lo) */
function fin_mail_looks_financial(PDO $pdo, array $m, array $a): bool {
    $acc = fin_accountant_email($pdo);
    if ($acc !== '' && strtolower($acc) === strtolower($m['from'])) return true;
    return (bool)preg_match('/\b(fatura|factura|invoice|recibo|nota de cr[eé]dito|ATCUD|FT|FR|NC)\b/iu', $m['subject'] . ' ' . $a['name']);
}

/**
 * Lê o documento: primeiro sem IA (QR fiscal, XML da fatura eletrónica); sem QR, a IA (lê o texto do PDF e, se
 * for digitalizado, a imagem); sem IA (ou se falhar), o texto do próprio PDF. Devolve [dados ou null, o anexo a
 * guardar, porque é que a IA não leu ('' se leu ou não foi preciso)]. Se os dados vierem do XML e houver o PDF,
 * guarda-se o PDF.
 */
function fin_mail_read(PDO $pdo, array $docs): array {
    require_once __DIR__ . '/fin_qr.php';
    require_once __DIR__ . '/fin_ai.php';
    foreach ($docs as $a) {
        $d = fin_doc_scan($a['bytes'], $a['mime']);
        if ($d === null) continue;
        if ($a['mime'] === 'application/xml') foreach ($docs as $p) if ($p['mime'] === 'application/pdf') return [$d, $p, ''];
        // o QR não traz nomes nem o vencimento: vêm do texto do PDF, quando o há
        if ($a['mime'] === 'application/pdf' && ($t = fin_text_invoice(fin_pdf_text($a['bytes']), fin_our_nif($pdo), fin_text_names($pdo)))) {
            foreach (['issuer_name', 'client_name', 'due_date'] as $k) if (($d[$k] ?? '') === '' && ($t[$k] ?? '') !== '') $d[$k] = $t[$k];
        }
        return [$d, $a, ''];
    }
    $files = array_values(array_filter($docs, fn($a) => $a['mime'] !== 'application/xml'));
    $why = fin_ai_why_off();
    if ($why === '') foreach ($files as $a) {
        try { return [fin_ai_read($pdo, $a['bytes'], $a['mime']) + ['source' => 'ai'], $a, '']; }
        catch (Throwable $e) { $why = 'a leitura falhou — ' . rtrim($e->getMessage(), '.'); break; }
    }
    foreach ($files as $a) {
        if ($a['mime'] !== 'application/pdf') continue;
        $d = fin_text_invoice(fin_pdf_text($a['bytes']), fin_our_nif($pdo), fin_text_names($pdo));
        if ($d !== null) return [$d, $a, $why];
    }
    return [null, $files[0] ?? $docs[0], $why];
}

/**
 * Trata uma mensagem com anexos. Devolve o estado gravado em fin_mail, ou null se não for um documento nosso
 * (nesse caso nada fica guardado). Ordem: código do pedido no assunto; o QR/XML (ou a IA) diz quem emitiu e
 * para quem: o nosso NIF como adquirente → despesa; como emitente → receita; os outros documentos, ignorados.
 */
function fin_mail_handle(PDO $pdo, array $m, string $mid, string $box = '', string $uid = '', ?array $redo = null): ?string {
    $code = '';
    if (preg_match('/\b((?:FAT|REC)-\d{4}-\d{4})\b/', $m['subject'] . ' ' . $m['text'], $mm)) $code = $mm[1];
    $docs = fin_mail_docs($m);
    if (!$docs) return null;
    [$data, $pick, $aiWhy] = fin_mail_read($pdo, $docs);
    $our = fin_our_nif($pdo);
    [$role, $roleWhy] = fin_mail_role($pdo, $data, $m, $code);
    $source = $data['source'] ?? ($code !== '' ? 'code' : '');
    $how = ['qr' => 'lida do QR fiscal', 'xml' => 'lida do XML', 'ai' => 'lida pela IA', 'text' => 'lida do texto do PDF (sem QR)'][$source] ?? '';
    $aiNote = $aiWhy !== '' ? ' · IA indisponível: ' . $aiWhy : '';
    // o texto do email (até ~1 000 caracteres) só fica nos emails guardados como documento financeiro
    $body = mb_substr(trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', (string)$m['text'])))), 0, 1000);
    // o registo da mensagem e do anexo — só quando a mensagem fica
    $mailId = 0; $att = $redo['att'] ?? null;
    $cols = db_has_column($pdo, 'fin_mail', 'direction');
    $keep = function (string $status, ?int $doc, string $note, array $extra = [], bool $store = true) use ($pdo, $m, $mid, $box, $uid, $code, $pick, $data, $source, $body, $cols, $redo, &$role, &$mailId, &$att): string {
        if ($store && $att === null) {
            try {
                [$key, $name] = fin_file_store($pdo, $pick['bytes'], date('Y-m-d') . '_Email_' . preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $pick['name']), $pick['mime'], ['issue_date' => today_lisbon()], 'attach');
                $att = ['name' => $pick['name'], 'mime' => $pick['mime'], 'key' => $key, 'size' => strlen($pick['bytes'])];
            } catch (Throwable $e) { $att = ['name' => $pick['name'], 'mime' => $pick['mime'], 'key' => '', 'size' => strlen($pick['bytes']), 'error' => $e->getMessage()]; }
        }
        $v = ['code' => $code, 'status' => $status, 'doc_id' => $doc, 'note' => mb_substr($note, 0, 300),
              'suggestions' => isset($extra['sug']) ? json_encode($extra['sug'], JSON_UNESCAPED_UNICODE) : null, 'att' => json_encode($att ? [$att] : [], JSON_UNESCAPED_UNICODE),
              'ai' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, 'source' => $source];
        if ($cols) { $v['direction'] = $role; $v['body_text'] = $body !== '' ? $body : null; }
        if ($redo) {
            if ($cols && $body === '') unset($v['body_text']);
            $pdo->prepare('UPDATE fin_mail SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($v))) . ' WHERE id = ?')->execute([...array_values($v), (int)$redo['id']]);
            $mailId = (int)$redo['id'];
            return $status;
        }
        $v += ['uid' => $uid, 'box' => mb_substr($box, 0, 190), 'message_id' => $mid, 'from_addr' => mb_substr($m['from'], 0, 190), 'subject' => mb_substr($m['subject'], 0, 300), 'received_at' => $m['date'], 'created_at' => fin_now()];
        $pdo->prepare('INSERT INTO fin_mail (' . implode(', ', array_keys($v)) . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')')->execute(array_values($v));
        $mailId = (int)$pdo->lastInsertId();
        return $status;
    };
    $attKey = function () use (&$att): string { return (string)($att['key'] ?? ''); };

    // a) recibo pedido ao contabilista (código REC-…): junta-se à fatura
    if (str_starts_with($code, 'REC-')) {
        $st = $pdo->prepare("SELECT doc_id FROM fin_mail WHERE code = ? AND status = 'waiting' ORDER BY id DESC LIMIT 1");
        $st->execute([$code]);
        if ($docId = (int)$st->fetchColumn()) {
            $d = fin_doc($pdo, $docId);
            $role = 'in';
            $r = $keep('linked', $docId, 'Recibo junto a ' . fin_doc_label($d));
            $pdo->prepare('UPDATE fin_docs SET receipt_key = ?, receipt_name = ?, updated_at = ? WHERE id = ?')->execute([$attKey(), $pick['name'], fin_now(), $docId]);
            fin_doc_refresh($pdo, $docId);
            $pdo->prepare("UPDATE fin_mail SET status = 'done' WHERE code = ? AND status = 'waiting'")->execute([$code]);
            return $r;
        }
    }
    // b) fatura pedida (código FAT-…): a resposta a um «Pedir fatura» é uma fatura emitida por nós
    if (str_starts_with($code, 'FAT-')) {
        $st = $pdo->prepare('SELECT id FROM fin_docs WHERE req_code = ? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$code]);
        $req = ($id = (int)$st->fetchColumn()) ? fin_doc($pdo, $id) : null;
        if ($req && $req['status'] === 'requested' && $role !== 'out') {
            $role = 'in';
            $r = $keep('linked', (int)$req['id'], 'Ligado pelo código ' . $code);
            $warn = fin_mail_register($pdo, $req, $att, $pick, $data, null);
            if ($warn) $pdo->prepare('UPDATE fin_mail SET note = ? WHERE id = ?')->execute([mb_substr('Ligado pelo código ' . $code . ' · ' . implode(' · ', $warn), 0, 300), $mailId]);
            return $r;
        }
    }
    // c) sem dados (sem QR, sem XML, sem texto legível e sem IA): fica por confirmar se parecer um documento financeiro
    if (!$data) {
        if ($role === '' && !fin_mail_looks_financial($pdo, $m, $pick)) return null;
        return $keep('review', null, 'Sem QR fiscal nem texto legível' . $aiNote . ($role !== '' ? ' · sugestão: ' . ($role === 'out' ? 'despesa' : 'receita') . ' (' . $roleWhy . ')' : '') . ': confirme à mão');
    }
    if (in_array($data['kind'], ['other', 'void', 'quote'], true) || ($data['source'] === 'ai' && $data['confidence'] < 0.4 && $role === '')) return null;   // orçamentos, guias, anulados…
    // d) não sei de que lado está a empresa: fica por confirmar (aparece em A pagar e em A receber)
    if ($role === '') {
        if ($our !== '' && in_array($data['source'], ['qr', 'xml'], true) && $data['issuer_nif'] !== '' && $data['client_nif'] !== '') return null;   // documento entre terceiros: ignorado
        return $keep('review', null, ($our === '' ? 'Falta o NIF da empresa em Definições › Financeiro › Empresa' : 'Não encontrei o NIF nem o nome da empresa no documento') . ' (' . $how . ')' . $aiNote . ': é despesa ou receita?');
    }
    $data['direction'] = $role;
    $data['party_nif'] = $role === 'in' ? $data['client_nif'] : $data['issuer_nif'];
    $data['party_name'] = $role === 'in' ? $data['client_name'] : $data['issuer_name'];

    // e) receita: a fatura que o contabilista emitiu em nosso nome
    if ($role === 'in') {
        if ($data['kind'] === 'receipt') {
            $st = $pdo->prepare("SELECT id FROM fin_docs WHERE direction = 'in' AND deleted_at IS NULL AND receipt_key = '' AND REPLACE(party_nif, ' ', '') = ? AND ABS(total - withholding - ?) < 0.02 ORDER BY id DESC LIMIT 1");
            $st->execute([$data['client_nif'], $data['total']]);
            if ($docId = (int)$st->fetchColumn()) {
                $r = $keep('linked', $docId, 'Recibo junto a ' . fin_doc_label(fin_doc($pdo, $docId)));
                $pdo->prepare('UPDATE fin_docs SET receipt_key = ?, receipt_name = ?, updated_at = ? WHERE id = ?')->execute([$attKey(), $pick['name'], fin_now(), $docId]);
                fin_doc_refresh($pdo, $docId);
                return $r;
            }
            return $keep('review', null, 'Recibo emitido por nós sem fatura correspondente');
        }
        if ($data['kind'] === 'credit_note') return $keep('review', null, 'Nota de crédito emitida por nós: confirme');
        if ($data['number'] !== '' && ($dup = fin_doc_dup($pdo, $data['client_nif'], $data['number'], 'in'))) return $keep('dup', (int)$dup['id'], 'Já lançada: ' . fin_doc_label($dup), [], false);
        $cands = fin_mail_candidates($pdo, $data);
        $best = $cands[0] ?? null;
        $sure = $best && $best['score'] >= 80 && $data['confidence'] >= 0.8 && (count($cands) === 1 || $cands[1]['score'] < $best['score'] - 20);
        if ($sure) {
            $d = fin_doc($pdo, $best['id']);
            $r = $keep('linked', (int)$d['id'], 'Ligado (' . $how . ': NIF, total e data)', ['sug' => $cands]);
            $warn = fin_mail_register($pdo, $d, $att, $pick, $data, null);
            if ($warn) $pdo->prepare('UPDATE fin_mail SET note = ? WHERE id = ?')->execute([mb_substr('Ligado (' . $how . ') · ' . implode(' · ', $warn), 0, 300), $mailId]);
            return $r;
        }
        return $keep('review', null, ($cands ? 'Fatura emitida pela empresa: escolha o pedido certo' : 'Fatura emitida pela empresa sem pedido em aberto: confirme') . ' (' . $how . '; ' . $roleWhy . ')' . $aiNote, ['sug' => $cands]);
    }

    // f) despesa: um documento passado à topper.
    if ($data['kind'] === 'receipt') {
        $st = $pdo->prepare("SELECT id FROM fin_docs WHERE direction = 'out' AND deleted_at IS NULL AND receipt_key = '' AND REPLACE(party_nif, ' ', '') = ? AND ABS(total - ?) < 0.02 ORDER BY id DESC LIMIT 1");
        $st->execute([$data['issuer_nif'], $data['total']]);
        if ($docId = (int)$st->fetchColumn()) {
            $r = $keep('linked', $docId, 'Recibo do fornecedor junto a ' . fin_doc_label(fin_doc($pdo, $docId)));
            $pdo->prepare('UPDATE fin_docs SET receipt_key = ?, receipt_name = ?, updated_at = ? WHERE id = ?')->execute([$attKey(), $pick['name'], fin_now(), $docId]);
            fin_doc_refresh($pdo, $docId);
            return $r;
        }
        return $keep('review', null, 'Recibo de fornecedor sem fatura correspondente');
    }
    if ($data['number'] !== '' && ($dup = fin_doc_dup($pdo, $data['issuer_nif'], $data['number']))) return $keep('dup', (int)$dup['id'], 'Duplicado de ' . fin_doc_label($dup) . ' (mesmo NIF e número)', [], false);
    require_once __DIR__ . '/fin_ai.php';
    $sug = fin_ai_suggest($pdo, $data['issuer_nif'], 'out', $data['kind']);
    $name = $data['issuer_name'];
    if ($name === '') {
        $st = $pdo->prepare("SELECT id, name FROM contacts WHERE REPLACE(nif, ' ', '') = ? LIMIT 1");
        $st->execute([$data['issuer_nif']]);
        if ($ct = $st->fetch()) { $name = (string)$ct['name']; $sug['contact_id'] ??= (int)$ct['id']; }
    }
    if ($name === '') $name = $m['from_name'] ?: $m['from'];
    $r = $keep('supplier', null, 'Despesa em «A pagar», por confirmar (' . $how . '; ' . $roleWhy . ')' . $aiNote);
    $v = ['direction' => 'out', 'doc_type' => $data['kind'] === 'credit_note' ? 'credit' : 'invoice', 'scope' => $sug['scope'] ?? 'company',
          'party_kind' => $sug['party_kind'] ?? 'supplier', 'party_name' => $name, 'party_nif' => $data['issuer_nif'], 'party_email' => $m['from'],
          'category' => ($sug['category'] ?? '') ?: 'Outros', 'number' => $data['number'], 'description' => $data['description'] ?: $m['subject'],
          'base' => $data['base'], 'vat_rate' => $data['vat_rate'], 'vat' => $data['vat'], 'total' => $data['total'], 'wh_rate' => $sug['wh_rate'] ?? 0,
          'withholding' => $data['withholding'], 'issue_date' => $data['issue_date'] ?: null, 'due_date' => $data['due_date'] ?: null, 'currency' => $data['currency'] ?: 'EUR',
          'contact_id' => ($sug['contact_id'] ?? 0) ?: null, 'status' => 'review', 'needs_review' => 1, 'pdf_key' => $attKey(), 'pdf_name' => $pick['name'],
          'ai_data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'notes' => 'Chegou por email de ' . $m['from'] . ' · ' . $m['subject'] . ($data['atcud'] ?? '' ? ' · ATCUD ' . $data['atcud'] : '')];
    $id = fin_doc_create($pdo, $v, null);
    $pdo->prepare('UPDATE fin_mail SET doc_id = ? WHERE id = ?')->execute([$id, $mailId]);
    return $r;
}

/**
 * «Ler outra vez» um email por confirmar (depois de ligar a IA, de pôr o NIF da empresa ou de uma regra nova): o mesmo
 * anexo e o texto guardado, a leitura e a classificação de novo. Devolve a mensagem para o ecrã.
 */
function fin_mail_reread(PDO $pdo, int $id): string {
    $st = $pdo->prepare('SELECT * FROM fin_mail WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || $row['status'] !== 'review') throw new RuntimeException('Esse email já foi tratado.');
    $att = (json_decode((string)$row['att'], true) ?: [])[0] ?? null;
    if (!$att || ($att['key'] ?? '') === '') throw new RuntimeException('O anexo deste email não ficou guardado: não há o que reler.');
    $bytes = fin_file_bytes((string)$att['key']);
    if ($bytes === null) throw new RuntimeException('Não encontrei o anexo no Drive.');
    $m = ['from' => (string)$row['from_addr'], 'from_name' => '', 'subject' => (string)$row['subject'], 'text' => (string)($row['body_text'] ?? ''),
          'date' => $row['received_at'], 'att' => [['name' => (string)$att['name'], 'mime' => (string)$att['mime'], 'bytes' => $bytes]]];
    $r = fin_mail_handle($pdo, $m, (string)$row['message_id'], (string)$row['box'], (string)$row['uid'], ['id' => (int)$row['id'], 'att' => $att]);
    if ($r === null) {
        $pdo->prepare("UPDATE fin_mail SET status = 'ignored', note = ? WHERE id = ?")->execute(['Relido: não parece um documento da empresa', (int)$row['id']]);
        return 'Relido: não parece um documento da empresa, ficou de parte.';
    }
    $st->execute([$id]);
    $now = $st->fetch();
    return ['supplier' => 'Relido: é uma despesa — está em «A pagar», por confirmar.', 'linked' => 'Relido e ligado: ' . $now['note'], 'dup' => 'Relido: ' . $now['note'], 'review' => 'Relido: ' . $now['note']][$r] ?? 'Relido.';
}

/** Uma vez depois da atualização: reler os que estavam por confirmar (a leitura do QR e do texto melhorou). */
function fin_mail_reread_pending(PDO $pdo): int {
    if (!db_has_column($pdo, 'fin_mail', 'direction') || (string)setting($pdo, 'fin_reread_38', '') !== '') return 0;
    require_once __DIR__ . '/ai.php';
    ai_setting_set($pdo, 'fin_reread_38', date('c'));
    $n = 0;
    foreach ($pdo->query("SELECT id FROM fin_mail WHERE status = 'review' ORDER BY id LIMIT 30")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try { fin_mail_reread($pdo, (int)$id); $n++; } catch (Throwable $e) { error_log('[topper] reler email ' . $id . ': ' . $e->getMessage()); }
    }
    return $n;
}

/** Os pedidos em aberto que podem corresponder a uma fatura lida pela IA: NIF do cliente, total e data. */
function fin_mail_candidates(PDO $pdo, array $ai): array {
    $out = [];
    foreach (fin_docs($pdo, ['dir' => 'in', 'st' => ['requested']]) as $d) {
        $s = 0; $why = [];
        if ($ai['client_nif'] !== '' && preg_replace('/\D/', '', $d['party_nif']) === preg_replace('/\D/', '', $ai['client_nif'])) { $s += 50; $why[] = 'NIF'; }
        if ($ai['total'] > 0 && abs((float)$d['total'] - $ai['total']) < 0.02) { $s += 35; $why[] = 'total'; }
        elseif ($ai['base'] > 0 && abs((float)$d['base'] - $ai['base']) < 0.02) { $s += 30; $why[] = 'valor s/ IVA'; }
        if ($ai['issue_date'] !== '' && $d['req_sent_at'] && abs(strtotime($ai['issue_date']) - strtotime(substr((string)$d['req_sent_at'], 0, 10))) <= 15 * 86400) { $s += 15; $why[] = 'data'; }
        if ($s >= 35) $out[] = ['id' => (int)$d['id'], 'score' => $s, 'why' => implode(' + ', $why), 'label' => $d['req_code'] . ' · ' . $d['party_name'] . ' · ' . eur((float)$d['total']) . ($d['deal_code'] ? ' · ' . $d['deal_code'] : '')];
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, 5);
}

/**
 * Regista a fatura recebida no pedido: número, data e valores lidos (se a IA leu), PDF, «Emitida», pronta a enviar;
 * compara com o pedido e devolve os avisos (valor ou NIF diferentes). Conclui a tarefa «Emitir fatura».
 */
function fin_mail_register(PDO $pdo, array $req, array $att, array $file, ?array $ai, ?int $by): array {
    $warn = [];
    $upd = ['pdf_key' => $att['key'], 'pdf_name' => $file['name'], 'status' => 'issued', 'ready_at' => fin_now(), 'ai_data' => $ai ? json_encode($ai, JSON_UNESCAPED_UNICODE) : null];
    if ($ai) {
        if ($ai['number'] !== '') $upd['number'] = $ai['number'];
        if ($ai['issue_date'] !== '') $upd['issue_date'] = $ai['issue_date'];
        if ($ai['due_date'] !== '') $upd['due_date'] = $ai['due_date'];
        if ($ai['total'] > 0 && abs($ai['total'] - (float)$req['total']) > 0.02) $warn[] = 'o total da fatura (' . eur($ai['total']) . ') não bate com o pedido (' . eur((float)$req['total']) . ')';
        if ($ai['client_nif'] !== '' && $req['party_nif'] !== '' && preg_replace('/\D/', '', $ai['client_nif']) !== preg_replace('/\D/', '', $req['party_nif'])) $warn[] = 'o NIF do cliente na fatura (' . $ai['client_nif'] . ') é diferente do pedido';
        if ($ai['total'] > 0 && !$warn) { $upd['base'] = $ai['base']; $upd['vat'] = $ai['vat']; $upd['total'] = $ai['total']; $upd['vat_rate'] = $ai['vat_rate']; }
    }
    if (empty($upd['number'])) { $upd['number'] = $req['req_code']; $warn[] = 'não li o número da fatura: confirme-o'; }
    if (empty($upd['issue_date']) && !$req['issue_date']) $upd['issue_date'] = today_lisbon();
    $upd['needs_review'] = $warn ? 1 : 0;
    if ($warn) $upd['notes'] = trim($req['notes'] . "\n" . date('d/m/Y') . ' · Atenção: ' . implode('; ', $warn));
    fin_doc_update($pdo, (int)$req['id'], $upd);
    fin_doc_issued($pdo, (int)$req['id'], $by);
    if ($req['deal_id']) deal_log($pdo, (int)$req['deal_id'], 'billing', ['note' => 'Fatura ' . $upd['number'] . ' recebida do contabilista' . ($warn ? ' — ' . implode('; ', $warn) : '') . ' · pronta para enviar ao cliente', 'user_id' => $by]);
    return $warn;
}

/** «Por confirmar» → o utilizador escolhe o pedido (ou cria um lançamento novo a partir da fatura). */
function fin_mail_confirm(PDO $pdo, int $mailId, int $docId, ?int $by): string {
    $st = $pdo->prepare('SELECT * FROM fin_mail WHERE id = ?');
    $st->execute([$mailId]);
    $m = $st->fetch();
    if (!$m || $m['status'] !== 'review') throw new RuntimeException('Esse email já foi tratado.');
    $d = fin_doc($pdo, $docId);
    if (!$d || $d['status'] !== 'requested') throw new RuntimeException('Esse pedido já não está à espera de fatura.');
    $att = (json_decode((string)$m['att'], true) ?: [])[0] ?? null;
    if (!$att || $att['key'] === '') throw new RuntimeException('O anexo não ficou guardado.');
    $ai = json_decode((string)$m['ai'], true) ?: null;
    $warn = fin_mail_register($pdo, $d, $att, ['name' => $att['name']], $ai, $by);
    $pdo->prepare("UPDATE fin_mail SET status = 'linked', doc_id = ?, done_by = ?, done_at = ?, note = ? WHERE id = ?")->execute([$docId, $by, fin_now(), 'Ligado à mão' . ($warn ? ' · ' . implode(' · ', $warn) : ''), $mailId]);
    return 'Fatura registada em ' . ($d['req_code'] ?: fin_doc_label($d)) . ($warn ? '. Atenção: ' . implode('; ', $warn) . '.' : '.');
}

// ── 6. enviar ao cliente, lembretes ─────────────────────────

/** O email ao cliente, pronto a rever: assunto, corpo (dados de pagamento, IBAN, vencimento). */
function fin_client_mail_draft(PDO $pdo, array $d): array {
    $co = trim((string)setting($pdo, 'company_name', 'topper.')) ?: 'topper.';
    $iban = trim((string)setting($pdo, 'fin_iban', ''));
    $body = "Olá,\n\nSegue em anexo a fatura " . fin_doc_label($d) . ($d['deal_name'] ? ' relativa a «' . $d['deal_name'] . '»' : '') . ', no valor de ' . eur(fin_doc_due($d)) . '.'
          . ($d['installment'] !== '' ? ' (' . $d['installment'] . ')' : '') . "\n\nVencimento: " . fin_date_pt($d['due_date'])
          . ($iban !== '' ? "\nIBAN: " . $iban : '') . "\n\nQualquer dúvida, estamos ao dispor.\n\nObrigado,\n" . $co;
    return ['to' => (string)$d['party_email'], 'subject' => 'Fatura ' . fin_doc_label($d) . ' · ' . $co, 'body' => $body];
}

/** Envia ao cliente com o PDF; fica no histórico do negócio e do contacto. */
function fin_client_send(PDO $pdo, array $d, string $to, string $subject, string $body, ?int $by, string $kind = 'invoice'): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Indique o email do cliente.');
    $att = [];
    if ($d['pdf_key'] !== '') {
        $b = fin_file_bytes((string)$d['pdf_key']);
        if ($b === null) throw new RuntimeException('Não encontrei o PDF da fatura no Drive.');
        $att[] = ['name' => $d['pdf_name'] ?: 'Fatura.pdf', 'mime' => fin_sniff($b) ?: 'application/pdf', 'bytes' => $b];
    } elseif ($kind === 'invoice') {
        throw new RuntimeException('A fatura ainda não tem PDF.');
    }
    fin_smtp_send($to, mb_substr($subject, 0, 200), $body, $att);
    if ($kind === 'invoice') {
        $pdo->prepare('UPDATE fin_docs SET client_sent_at = ?, party_email = ?, updated_at = ? WHERE id = ?')->execute([fin_now(), $to, fin_now(), (int)$d['id']]);
    } else {
        $pdo->prepare('UPDATE fin_docs SET reminder_draft = NULL, notes = ?, updated_at = ? WHERE id = ?')->execute([trim($d['notes'] . "\n" . date('d/m/Y') . ' · Lembrete enviado a ' . $to), fin_now(), (int)$d['id']]);
    }
    $what = $kind === 'invoice' ? 'Fatura ' . fin_doc_label($d) . ' enviada ao cliente (' . $to . ')' : 'Lembrete de pagamento da fatura ' . fin_doc_label($d) . ' enviado a ' . $to;
    if ($d['deal_id']) deal_log($pdo, (int)$d['deal_id'], 'billing', ['note' => $what, 'user_id' => $by]);
    if ($d['contact_id'] && function_exists('contact_log')) contact_log($pdo, (int)$d['contact_id'], 'billing', $what, $by);
}

/** O estado por caixa para Definições › Integrações › Email: as caixas lidas e o email geral. */
function fin_mail_status(PDO $pdo): array {
    $srv = fin_mail_server();
    $state = fin_mail_state($pdo);
    $read = fin_mail_boxes($pdo);
    $gen = mail_general($pdo);
    $boxes = [];
    $lastIn = mail_inbound_last($pdo);
    foreach (array_unique(array_merge($read, [$gen])) as $b) {
        $st = $state[$b] ?? [];
        $boxes[] = ['last_in' => (string)($lastIn[$b] ?? ''), 'addr' => $b, 'pass' => $srv && isset($srv['boxes'][$b]), 'read' => in_array($b, $read, true), 'general' => $b === $gen,
                    'last' => (string)($st['last'] ?? ''), 'error' => in_array($b, $read, true) ? (string)($st['error'] ?? '') : '', 'uid' => (int)($st['uid'] ?? 0),
                    'seen' => (int)($st['seen'] ?? 0), 'docs' => (int)($st['docs'] ?? 0), 'since' => (string)($st['since'] ?? '')];
    }
    return ['mode' => fin_mail_mode($pdo), 'send' => mailer_mode($pdo), 'server' => $srv !== null, 'boxes' => $boxes, 'general' => $gen, 'company_nif' => fin_our_nif($pdo), 'accountant' => fin_accountant_email($pdo),
            'pending' => fin_ready($pdo) ? (int)$pdo->query("SELECT COUNT(*) FROM fin_mail WHERE status = 'review'")->fetchColumn() : 0];
}

/** «Testar» uma caixa: entra no IMAP só para ler (EXAMINE) e autentica no SMTP, sem enviar nada. */
function fin_mail_test(PDO $pdo, string $addr): string {
    $addr = strtolower(trim($addr));
    if (!in_array($addr, array_merge(fin_mail_boxes($pdo), [mail_general($pdo)]), true)) throw new RuntimeException('Essa caixa não está na lista.');
    $srv = fin_mail_server();
    if (!$srv || !isset($srv['boxes'][$addr])) throw new RuntimeException('Falta a palavra-passe desta caixa: escreva-a ao lado e carregue em «Guardar».');
    $c = array_merge($srv, ['user' => $addr, 'pass' => $srv['boxes'][$addr], 'from_name' => 'topper.']);
    $im = new FinImap($c);
    try { $box = $im->examine($srv['folder']); } finally { $im->close(); }
    fin_smtp_send('', '', '', [], ['test' => true, 'cred' => $c]);
    return 'IMAP OK, só leitura (' . $box['exists'] . ($box['exists'] === 1 ? ' mensagem' : ' mensagens') . ' na caixa de entrada; nada foi mudado) · SMTP OK (pode enviar).';
}
