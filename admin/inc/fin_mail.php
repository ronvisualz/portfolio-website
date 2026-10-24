<?php
/**
 * topper. admin — Financeiro: faturas pelo contabilista (§28.14)
 *
 * Enquanto não há programa de faturação certificado:
 *  1. «Pedir fatura» — os dados do cliente e do serviço seguem por email (SMTP do Hostinger, da caixa das
 *     faturas) para o contabilista, com o código no assunto: «[topper FAT-2026-0142] Pedido de fatura — NEG-0123».
 *     O lançamento fica «Fatura pedida».
 *  2. O cron lê a caixa por IMAP (imap.hostinger.com:993, SSL) a cada 5 minutos — cliente IMAP em PHP puro,
 *     sem a extensão imap (removida do núcleo no PHP 8.4).
 *  3. Liga a resposta ao pedido pelo código; sem código, a IA lê o PDF (NIF, total, data) e procura o pedido
 *     aberto. Com dúvida fica «Por confirmar» com as opções — nunca liga sozinho com baixa confiança.
 *  4. Regista: o PDF vai para o Drive, compara os valores com o pedido, passa a «Emitida», conclui a tarefa.
 *  5. Avisa o responsável e o comercial; 6. «Enviar ao cliente» (PDF, IBAN, vencimento).
 *  Faturas de fornecedores reencaminhadas para a caixa entram em «A pagar» por confirmar; «Pedir recibo» idem.
 *  As mensagens tratadas passam para a pasta «Processadas»; nada é apagado.
 *
 * Credenciais: só no servidor, em inc/fin_mail_config.php (fora do Git; ver fin_mail_config.example.php).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin.php';

const FIN_MAIL_DONE_FOLDER = 'Processadas';
const FIN_MAIL_MAX_PER_RUN = 25;

/** [user, pass, smtp_host, smtp_port, imap_host, imap_port, from_name, folder] — ou null se faltar (ou for o exemplo). */
function fin_mail_config(): ?array {
    static $c = false;
    if ($c !== false) return $c;
    $c = null;
    $f = __DIR__ . '/fin_mail_config.php';
    if (!is_file($f)) return null;
    $raw = require $f;
    if (!is_array($raw)) return null;
    $user = trim((string)($raw['user'] ?? ''));
    $pass = (string)($raw['pass'] ?? '');
    if (!filter_var($user, FILTER_VALIDATE_EMAIL) || $pass === '' || stripos($pass, 'COLE_') === 0) return null;
    $host = fn($v, $d) => preg_match('/^[a-z0-9.-]+$/i', (string)$v) ? (string)$v : $d;
    return $c = ['user' => $user, 'pass' => $pass,
                 'smtp_host' => $host($raw['smtp_host'] ?? '', 'smtp.hostinger.com'), 'smtp_port' => (int)($raw['smtp_port'] ?? 465),
                 'smtp_tls' => (string)($raw['smtp_tls'] ?? 'ssl'), 'imap_host' => $host($raw['imap_host'] ?? '', 'imap.hostinger.com'),
                 'imap_port' => (int)($raw['imap_port'] ?? 993), 'imap_tls' => (string)($raw['imap_tls'] ?? 'ssl'),
                 'from_name' => mb_substr(trim((string)($raw['from_name'] ?? 'topper. Financeiro')), 0, 80), 'folder' => (string)($raw['folder'] ?? 'INBOX'),
                 'verify_peer' => ($raw['verify_peer'] ?? true) !== false];
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
 * Envia um email (texto simples, UTF-8) com anexos [['name','mime','bytes']] pela caixa das faturas.
 * $o: reply_to, cc, test (só liga e autentica). Devolve o Message-ID.
 */
function fin_smtp_send(string $to, string $subject, string $body, array $att = [], array $o = []): string {
    $c = fin_mail_config();
    if (!$c) throw new RuntimeException('A caixa das faturas ainda não está configurada no servidor (inc/fin_mail_config.php).');
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

    /** UIDs de uma pasta (todas as mensagens que lá estão — as tratadas saem para «Processadas»). */
    public function uids(string $folder): array {
        $this->cmd('SELECT ' . $this->q($folder), 'abrir a pasta');
        [$lines] = $this->cmd('UID SEARCH ALL');
        foreach ($lines as $l) if (preg_match('/^\* SEARCH ?(.*)$/', $l, $m)) return array_map('intval', array_filter(explode(' ', trim($m[1])), 'strlen'));
        return [];
    }

    /** A mensagem inteira (sem a marcar como lida). */
    public function fetch(int $uid): string {
        [, $lits] = $this->cmd('UID FETCH ' . $uid . ' (BODY.PEEK[])');
        return $lits[0] ?? '';
    }

    /** Move para outra pasta (criada se não existir). Sem MOVE, copia e marca como lida — nunca apaga. */
    public function move(int $uid, string $to): void {
        try { $this->cmd('CREATE ' . $this->q($to)); } catch (RuntimeException $e) { /* já existe */ }
        if (stripos($this->caps, 'MOVE') !== false) { $this->cmd('UID MOVE ' . $uid . ' ' . $this->q($to), 'mover'); return; }
        $this->cmd('UID COPY ' . $uid . ' ' . $this->q($to), 'copiar');
        $this->cmd('UID STORE ' . $uid . ' +FLAGS (\\Seen)');
    }

    public function close(): void {
        try { $this->cmd('LOGOUT'); } catch (Throwable $e) {}
        @fclose($this->s);
    }
}

// ── MIME ────────────────────────────────────────────────────

/** Descodifica um cabeçalho (RFC 2047) para UTF-8. */
function fin_mime_hdr(string $v): string {
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
    if ($name !== '' || stripos($disp, 'attachment') !== false || in_array($type, ['application/pdf', 'image/jpeg', 'image/png', 'application/octet-stream'], true)) {
        $mime = fin_sniff($data) ?: $type;
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
        fin_smtp_send($to, $subject, $body, [], ['reply_to' => fin_mail_config()['user'] ?? null]);
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
    fin_smtp_send($to, '[topper ' . $code . '] Pedido de recibo — ' . fin_doc_label($d), implode("\n", $l), [], ['reply_to' => fin_mail_config()['user'] ?? null]);
    $pdo->prepare('UPDATE fin_docs SET notes = ?, updated_at = ? WHERE id = ?')->execute([trim($d['notes'] . "\n" . date('d/m/Y') . ' · Recibo pedido (' . $code . ')'), fin_now(), (int)$d['id']]);
    $pdo->prepare('INSERT INTO fin_mail (uid, message_id, from_addr, subject, code, status, doc_id, note, created_at) VALUES (\'\', \'\', ?, ?, ?, \'waiting\', ?, ?, ?)')
        ->execute([$to, 'Pedido de recibo — ' . fin_doc_label($d), $code, (int)$d['id'], 'Pedido enviado', fin_now()]);
    return $code;
}

// ── 2–5. ler a caixa ────────────────────────────────────────

/** Lê a caixa: cada mensagem nova é tratada e passa para «Processadas». Devolve o resumo. */
function fin_mail_poll(PDO $pdo, float $seconds = 40.0): string {
    if (!fin_ready($pdo)) return '';
    $c = fin_mail_config();
    if (!$c) return 'Caixa das faturas: por configurar';
    $t0 = microtime(true);
    $done = 0; $err = '';
    try {
        $im = new FinImap($c);
        try {
            foreach (array_slice($im->uids($c['folder']), 0, FIN_MAIL_MAX_PER_RUN) as $uid) {
                if (microtime(true) - $t0 > $seconds) break;
                $raw = $im->fetch($uid);
                if ($raw === '') continue;
                $m = fin_mime_parse($raw);
                $mid = $m['message_id'] !== '' ? mb_substr($m['message_id'], 0, 255) : 'uid:' . $uid . ':' . sha1($raw);
                $st = $pdo->prepare('SELECT COUNT(*) FROM fin_mail WHERE message_id = ?');
                $st->execute([$mid]);
                if (!(int)$st->fetchColumn()) fin_mail_handle($pdo, $m, $mid, (string)$uid);
                $im->move($uid, FIN_MAIL_DONE_FOLDER);
                $done++;
            }
        } finally { $im->close(); }
    } catch (Throwable $e) {
        $err = $e->getMessage();
        error_log('fin_mail_poll: ' . $err);
    }
    ai_setting_set_safe($pdo, 'fin_mail_last', fin_now());
    ai_setting_set_safe($pdo, 'fin_mail_error', $err);
    return 'Caixa das faturas: ' . $done . ' mensagens tratadas' . ($err !== '' ? ' · erro: ' . $err : '');
}

function ai_setting_set_safe(PDO $pdo, string $k, string $v): void {
    require_once __DIR__ . '/ai.php';
    ai_setting_set($pdo, $k, $v);
}

/** Os PDFs e imagens de uma mensagem (os logótipos pequenos das assinaturas ficam de fora). */
function fin_mail_docs(array $m): array {
    return array_values(array_filter($m['att'], fn($a) => $a['mime'] === 'application/pdf' || (in_array($a['mime'], ['image/jpeg', 'image/png'], true) && strlen($a['bytes']) > 60000)));
}

/**
 * Trata uma mensagem: código no assunto/corpo → o pedido; sem código, a IA lê o PDF e procura o pedido aberto
 * (só liga sozinho com confiança alta e um único candidato); faturas de terceiros → «A pagar» por confirmar.
 */
function fin_mail_handle(PDO $pdo, array $m, string $mid, string $uid): int {
    $code = '';
    if (preg_match('/\b((?:FAT|REC)-\d{4}-\d{4})\b/', $m['subject'] . ' ' . $m['text'], $mm)) $code = $mm[1];
    $docs = fin_mail_docs($m);
    $attMeta = [];
    // os anexos ficam já no Drive (pasta do mês), para se poderem ver mesmo antes de confirmar
    foreach ($docs as $i => $a) {
        try {
            [$key, $name] = fin_file_store($pdo, $a['bytes'], date('Y-m-d') . '_Email_' . preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $a['name']), $a['mime'], ['issue_date' => today_lisbon()], 'attach');
            $attMeta[] = ['name' => $a['name'], 'mime' => $a['mime'], 'key' => $key, 'size' => strlen($a['bytes'])];
        } catch (Throwable $e) { $attMeta[] = ['name' => $a['name'], 'mime' => $a['mime'], 'key' => '', 'size' => strlen($a['bytes']), 'error' => $e->getMessage()]; }
    }
    $pdo->prepare('INSERT INTO fin_mail (uid, message_id, from_addr, subject, received_at, code, status, att, created_at) VALUES (?,?,?,?,?,?,\'new\',?,?)')
        ->execute([$uid, $mid, mb_substr($m['from'], 0, 190), mb_substr($m['subject'], 0, 300), $m['date'], $code, json_encode($attMeta, JSON_UNESCAPED_UNICODE), fin_now()]);
    $mailId = (int)$pdo->lastInsertId();
    $set = function (string $status, ?int $doc, string $note, array $extra = []) use ($pdo, $mailId) {
        $pdo->prepare('UPDATE fin_mail SET status = ?, doc_id = ?, note = ?, suggestions = ?, ai = ? WHERE id = ?')
            ->execute([$status, $doc, mb_substr($note, 0, 300), isset($extra['sug']) ? json_encode($extra['sug'], JSON_UNESCAPED_UNICODE) : null,
                       isset($extra['ai']) ? json_encode($extra['ai'], JSON_UNESCAPED_UNICODE) : null, $mailId]);
    };
    if (!$docs) { $set('noattach', null, 'Sem PDF em anexo'); return $mailId; }
    $first = $docs[0];
    $ai = null;
    try {
        require_once __DIR__ . '/fin_ai.php';
        if (fin_ai_on()) $ai = fin_ai_read($pdo, $first['bytes'], $first['mime']);
    } catch (Throwable $e) { $ai = null; }

    // recibo pedido: junta-se à fatura
    if (str_starts_with($code, 'REC-')) {
        $st = $pdo->prepare("SELECT doc_id FROM fin_mail WHERE code = ? AND status = 'waiting' ORDER BY id DESC LIMIT 1");
        $st->execute([$code]);
        if ($docId = (int)$st->fetchColumn()) {
            $d = fin_doc($pdo, $docId);
            $pdo->prepare('UPDATE fin_docs SET receipt_key = ?, receipt_name = ?, updated_at = ? WHERE id = ?')->execute([$attMeta[0]['key'], $first['name'], fin_now(), $docId]);
            fin_doc_refresh($pdo, $docId);
            $pdo->prepare("UPDATE fin_mail SET status = 'done' WHERE code = ? AND status = 'waiting'")->execute([$code]);
            $set('linked', $docId, 'Recibo junto a ' . fin_doc_label($d), ['ai' => $ai]);
            return $mailId;
        }
    }
    // fatura pedida: pelo código
    $req = null;
    if (str_starts_with($code, 'FAT-')) {
        $st = $pdo->prepare("SELECT id FROM fin_docs WHERE req_code = ? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$code]);
        $req = ($id = (int)$st->fetchColumn()) ? fin_doc($pdo, $id) : null;
    }
    if ($req && $req['status'] === 'requested') {
        $warn = fin_mail_register($pdo, $req, $attMeta[0], $first, $ai, null);
        $set('linked', (int)$req['id'], 'Ligado pelo código ' . $code . ($warn ? ' · ' . implode(' · ', $warn) : ''), ['ai' => $ai]);
        return $mailId;
    }
    // sem código: a IA diz se é uma fatura nossa (emitida pelo contabilista) ou de um fornecedor
    if ($ai && $ai['direction'] === 'in') {
        $cands = fin_mail_candidates($pdo, $ai);
        $best = $cands[0] ?? null;
        $sure = $best && $best['score'] >= 80 && $ai['confidence'] >= 0.8 && (count($cands) === 1 || $cands[1]['score'] < $best['score'] - 20);
        if ($sure) {
            $d = fin_doc($pdo, $best['id']);
            $warn = fin_mail_register($pdo, $d, $attMeta[0], $first, $ai, null);
            $set('linked', (int)$d['id'], 'Ligado pela IA (NIF, total e data)' . ($warn ? ' · ' . implode(' · ', $warn) : ''), ['ai' => $ai, 'sug' => $cands]);
        } else {
            $set('review', null, $cands ? 'Por confirmar: escolha o pedido certo' : 'Por confirmar: não encontrei o pedido', ['ai' => $ai, 'sug' => $cands]);
        }
        return $mailId;
    }
    // fatura de fornecedor (ou sem IA): entra em «A pagar» por confirmar
    $v = ['direction' => 'out', 'doc_type' => ($ai['kind'] ?? '') === 'credit_note' ? 'credit' : 'invoice', 'scope' => $ai['scope'] ?? 'company',
          'party_kind' => $ai['party_kind'] ?? 'supplier', 'party_name' => $ai['party_name'] ?? ($m['from_name'] ?: $m['from']), 'party_nif' => $ai['party_nif'] ?? '',
          'party_email' => $m['from'], 'category' => ($ai['category'] ?? '') ?: 'Outros', 'number' => $ai['number'] ?? '', 'description' => $ai['description'] ?? $m['subject'],
          'base' => $ai['base'] ?? 0, 'vat_rate' => $ai['vat_rate'] ?? 0, 'vat' => $ai['vat'] ?? 0, 'total' => $ai['total'] ?? 0, 'wh_rate' => $ai['wh_rate'] ?? 0,
          'withholding' => $ai['withholding'] ?? 0, 'issue_date' => ($ai['issue_date'] ?? '') ?: null, 'due_date' => ($ai['due_date'] ?? '') ?: null, 'currency' => $ai['currency'] ?? 'EUR',
          'contact_id' => ($ai['contact_id'] ?? 0) ?: null, 'status' => 'review', 'needs_review' => 1, 'pdf_key' => $attMeta[0]['key'], 'pdf_name' => $first['name'],
          'ai_data' => $ai ? json_encode($ai, JSON_UNESCAPED_UNICODE) : null, 'notes' => 'Chegou por email de ' . $m['from'] . ' · ' . $m['subject']];
    $dup = $v['party_nif'] !== '' && $v['number'] !== '' ? fin_doc_dup($pdo, $v['party_nif'], $v['number']) : null;
    if ($dup) { $set('dup', (int)$dup['id'], 'Duplicado de ' . fin_doc_label($dup) . ' (mesmo NIF e número)', ['ai' => $ai]); return $mailId; }
    $id = fin_doc_create($pdo, $v, null);
    $set('supplier', $id, 'Fatura de fornecedor em «A pagar», por confirmar', ['ai' => $ai]);
    return $mailId;
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

/** O estado da ligação para Definições › Integrações › Email. */
function fin_mail_status(PDO $pdo): array {
    require_once __DIR__ . '/ai.php';
    return ['configured' => fin_mail_config() !== null, 'user' => fin_mail_config()['user'] ?? '', 'last' => ai_setting_get($pdo, 'fin_mail_last') ?: '', 'error' => ai_setting_get($pdo, 'fin_mail_error') ?: '',
            'accountant' => fin_accountant_email($pdo), 'pending' => fin_ready($pdo) ? (int)$pdo->query("SELECT COUNT(*) FROM fin_mail WHERE status = 'review'")->fetchColumn() : 0];
}

/** «Testar»: entra no IMAP (conta as mensagens) e no SMTP (autentica, sem enviar). */
function fin_mail_test(): string {
    $c = fin_mail_config();
    if (!$c) throw new RuntimeException('Falta o ficheiro inc/fin_mail_config.php no servidor (ver o exemplo).');
    $im = new FinImap($c);
    $n = count($im->uids($c['folder']));
    $im->close();
    fin_smtp_send('', '', '', [], ['test' => true]);
    return 'Ligação OK: IMAP (' . $n . ($n === 1 ? ' mensagem' : ' mensagens') . ' por tratar) e SMTP (autenticado).';
}
