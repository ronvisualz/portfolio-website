<?php
/**
 * topper. admin — emails que chegam pela Cloudflare (§31.1)
 *
 * Circuito: cada caixa escolhida do Hostinger (ex.: info@topperdigital.xyz) reencaminha uma cópia para
 * entrada@in.topperhub.xyz (Email Routing só no subdomínio in.: os MX de topperhub.xyz continuam no Hostinger) → Cloudflare Email Routing → Email Worker (mail/) → POST do email em bruto para
 * admin/fin_inbound.php, assinado (HMAC-SHA256 com o segredo partilhado) e com carimbo de tempo.
 *
 * Aqui: valida a assinatura e a hora (5 min), recusa repetições (o mesmo Message-ID não entra duas vezes),
 * descobre de que caixa veio (cabeçalhos do reencaminhamento) e ignora as caixas que não estão em «Caixas
 * ligadas». Depois, as regras de sempre do Financeiro (fin_mail_handle): só anexos, QR/ATCUD primeiro, a IA só
 * sem QR, receita/despesa pelo NIF, ligação ao pedido pelo código. O corpo nunca é guardado; de uma caixa que
 * não está ligada não fica nada além da hora (contra repetições).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/mailer.php';

const MAIL_INBOUND_MAX_BYTES = 25 * 1048576;
const MAIL_INBOUND_SKEW = 300;               // segundos de diferença aceites no carimbo
const MAIL_TEST_TAG = 'topper teste';
/** O endereço que recebe as cópias: Email Routing só no subdomínio in.topperhub.xyz (o domínio principal fica com o email do Hostinger). */
const MAIL_INBOUND_DEFAULT = 'entrada@in.topperhub.xyz';        // «[topper teste AB12CD]» no assunto = email de teste de Definições

function mail_inbound_ready(PDO $pdo): bool {
    return db_has_table($pdo, 'mail_inbound');
}

/** Como chegam as faturas: 'cloudflare' (por defeito) ou 'imap' (o leitor antigo, com palavras-passe). */
function fin_mail_mode(PDO $pdo): string {
    return (string)setting($pdo, 'fin_mail_mode', 'cloudflare') === 'imap' ? 'imap' : 'cloudflare';
}

/** O endereço que recebe as cópias (Definições). */
function mail_inbound_address(PDO $pdo): string {
    $a = strtolower(trim((string)setting($pdo, 'mail_inbound_address', MAIL_INBOUND_DEFAULT)));
    return filter_var($a, FILTER_VALIDATE_EMAIL) ? $a : MAIL_INBOUND_DEFAULT;
}

/**
 * Valida o pedido do Worker. $h: cabeçalhos em minúsculas. Devolve [ok, código HTTP, motivo].
 */
function mail_inbound_verify(array $h, string $raw, ?int $now = null): array {
    $secret = mailer_inbound_secret();
    if ($secret === '') return [false, 503, 'O segredo ainda não foi gerado (Definições › Integrações › Email).'];
    $ts = (string)($h['x-topper-ts'] ?? '');
    $mid = (string)($h['x-topper-id'] ?? '');
    $sig = strtolower((string)($h['x-topper-sig'] ?? ''));
    if (!preg_match('/^\d{9,11}$/', $ts) || $mid === '' || strlen($mid) > 998 || !preg_match('/^[a-f0-9]{64}$/', $sig)) return [false, 400, 'Pedido incompleto.'];
    $want = mailer_inbound_sign($secret, $ts, $mid, hash('sha256', $raw));
    if (!hash_equals($want, $sig)) return [false, 401, 'Assinatura inválida.'];
    if (abs(($now ?? time()) - (int)$ts) > MAIL_INBOUND_SKEW) return [false, 409, 'Carimbo de tempo fora do prazo.'];
    return [true, 200, ''];
}

/**
 * De que caixa ligada veio: os cabeçalhos que o reencaminhamento junta (Delivered-To, X-Original-To,
 * X-Forwarded-To / -For, Resent-*), o destinatário original (To, Cc) e o remetente do envelope.
 */
function mail_inbound_box(array $headers, string $envFrom, array $connected): string {
    if (!$connected) return '';
    $cands = [];
    foreach (['delivered-to', 'x-original-to', 'x-forwarded-to', 'x-forwarded-for', 'resent-to', 'resent-from', 'envelope-to', 'to', 'cc'] as $k) {
        if (!isset($headers[$k])) continue;
        if (preg_match_all('/[A-Z0-9._%+\'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', (string)$headers[$k], $m)) foreach ($m[0] as $a) $cands[] = strtolower($a);
    }
    // o envelope de um reencaminhamento com SRS («SRS0=…=dominio=caixa@…») ou o próprio endereço
    if (preg_match('/^srs[01][=+-].*?=([^=@]+)=([^=@]+)@/i', $envFrom, $m)) $cands[] = strtolower($m[2] . '@' . $m[1]);
    if (filter_var($envFrom, FILTER_VALIDATE_EMAIL)) $cands[] = strtolower($envFrom);
    foreach ($cands as $c) if (in_array($c, $connected, true)) return $c;
    return '';
}

/** Regista a chegada (nunca o corpo). Devolve false se já tinha chegado (repetição). */
function mail_inbound_log(PDO $pdo, string $hash, array $v): bool {
    try {
        $pdo->prepare('INSERT INTO mail_inbound (msg_hash, box, from_addr, subject, size, status, note, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$hash, mb_substr((string)($v['box'] ?? ''), 0, 190), mb_substr((string)($v['from'] ?? ''), 0, 190), mb_substr((string)($v['subject'] ?? ''), 0, 300),
                       (int)($v['size'] ?? 0), (string)($v['status'] ?? 'new'), mb_substr((string)($v['note'] ?? ''), 0, 300), date('Y-m-d H:i:s')]);
        return true;
    } catch (PDOException $e) {
        if (in_array((string)$e->getCode(), ['23000', '23505'], true) || str_contains($e->getMessage(), 'UNIQUE')) return false;
        throw $e;
    }
}

function mail_inbound_set(PDO $pdo, string $hash, string $status, string $note): void {
    $pdo->prepare('UPDATE mail_inbound SET status = ?, note = ? WHERE msg_hash = ?')->execute([$status, mb_substr($note, 0, 300), $hash]);
}

/**
 * Trata um email que chegou (já validado). Devolve ['status', 'note'] para a resposta ao Worker.
 */
function mail_inbound_accept(PDO $pdo, string $raw, array $h): array {
    require_once __DIR__ . '/fin_mail.php';
    $mid = trim((string)($h['x-topper-id'] ?? ''));
    $hash = hash('sha256', 'mid:' . strtolower($mid));
    $parsed = fin_mime_parse($raw);
    [$headers] = fin_mime_split($raw);
    $connected = fin_mail_boxes($pdo);
    $box = mail_inbound_box($headers, (string)($h['x-topper-from'] ?? ''), $connected);
    $subject = (string)$parsed['subject'];

    // um email de teste de Definições: fica registado com o código, para a página o ver chegar
    if (preg_match('/\[' . preg_quote(MAIL_TEST_TAG, '/') . ' ([A-Z0-9]{6})\]/i', $subject, $tm)) {
        if (!mail_inbound_log($pdo, $hash, ['box' => $box, 'from' => $parsed['from'], 'subject' => $subject, 'size' => strlen($raw), 'status' => 'test', 'note' => strtoupper($tm[1])])) return ['dup', 'Repetido.'];
        return ['test', 'Email de teste ' . strtoupper($tm[1]) . ($box !== '' ? ' de ' . $box : ' (caixa por identificar)')];
    }
    if ($box === '') {
        // de uma caixa que não está ligada: nada fica além da marca contra repetições
        if (!mail_inbound_log($pdo, $hash, ['status' => 'ignored', 'size' => strlen($raw), 'note' => 'Caixa não ligada'])) return ['dup', 'Repetido.'];
        return ['ignored', 'Caixa não ligada.'];
    }
    if (!mail_inbound_log($pdo, $hash, ['box' => $box, 'from' => $parsed['from'], 'subject' => $subject, 'size' => strlen($raw), 'status' => 'new'])) return ['dup', 'Repetido.'];
    if (fin_mail_mode($pdo) !== 'cloudflare') { mail_inbound_set($pdo, $hash, 'off', 'Modo IMAP ativo: lido pelo leitor IMAP'); return ['off', 'Modo IMAP ativo.']; }
    if (!fin_ready($pdo)) { mail_inbound_set($pdo, $hash, 'skipped', 'Financeiro por instalar'); return ['skipped', 'Financeiro por instalar.']; }
    if (fin_mail_ignored($pdo, $parsed['from'])) { mail_inbound_set($pdo, $hash, 'skipped', 'Remetente a ignorar'); return ['skipped', 'Remetente a ignorar.']; }
    if (!fin_mail_docs($parsed)) { mail_inbound_set($pdo, $hash, 'skipped', 'Sem anexos de documentos'); return ['skipped', 'Sem anexos.']; }
    $msgId = $parsed['message_id'] !== '' ? mb_substr($parsed['message_id'], 0, 255) : 'cf:' . substr($hash, 0, 40);
    $q = $pdo->prepare('SELECT COUNT(*) FROM fin_mail WHERE message_id = ?');
    $q->execute([$msgId]);
    if ((int)$q->fetchColumn()) { mail_inbound_set($pdo, $hash, 'dup', 'Já tratado (a mesma mensagem por outra caixa)'); return ['dup', 'Já tratado.']; }
    try {
        $r = fin_mail_handle($pdo, $parsed, $msgId, $box, '');
    } catch (Throwable $e) {
        error_log('[topper] fin_inbound: ' . $e->getMessage());
        mail_inbound_set($pdo, $hash, 'error', mb_substr($e->getMessage(), 0, 280));
        return ['error', 'Erro ao tratar.'];
    }
    $labels = ['linked' => 'Ligado a um pedido', 'review' => 'Por confirmar', 'supplier' => 'Despesa em «A pagar», por confirmar', 'dup' => 'Duplicado'];
    mail_inbound_set($pdo, $hash, $r === null ? 'skipped' : 'doc', $r === null ? 'Sem documento da empresa' : ($labels[$r] ?? $r));
    return [$r === null ? 'skipped' : 'doc', $r === null ? 'Sem documento da empresa.' : ($labels[$r] ?? $r)];
}

/** A data do último email que chegou de cada caixa ligada: [caixa => datetime]. */
function mail_inbound_last(PDO $pdo): array {
    if (!mail_inbound_ready($pdo)) return [];
    return $pdo->query("SELECT box, MAX(created_at) FROM mail_inbound WHERE box <> '' GROUP BY box")->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** O último pedido assinado que chegou (de qualquer caixa): diz se o Worker já está a funcionar. */
function mail_inbound_last_any(PDO $pdo): string {
    if (!mail_inbound_ready($pdo)) return '';
    return (string)$pdo->query('SELECT MAX(created_at) FROM mail_inbound')->fetchColumn();
}

/**
 * «Testar»: envia (pelo serviço de envio) um email com um código para a caixa; o reencaminhamento leva-o à
 * Cloudflare e a página vê-o chegar. Sem serviço de envio, devolve o código para a pessoa o enviar à mão.
 * Devolve ['code', 'sent' (bool), 'msg'].
 */
function mail_inbound_test_start(PDO $pdo, string $box): array {
    $box = strtolower(trim($box));
    if (!in_array($box, fin_mail_boxes_safe($pdo), true)) throw new RuntimeException('Essa caixa não está em «Caixas ligadas».');
    $code = strtoupper(substr(strtr(base64_encode(random_bytes(6)), '+/=', 'XYZ'), 0, 6));
    $subject = '[' . MAIL_TEST_TAG . ' ' . $code . '] Ligação ao Financeiro';
    $sent = false; $msg = '';
    if (mailer_api_on($pdo)) {
        try {
            mailer_api_send(['user' => (string)setting($pdo, 'mail_from', 'no-reply@topperdigital.xyz'), 'from_name' => 'topper.'], $box, $subject,
                "Este email é um teste da plataforma topper.: confirma que as cópias de $box chegam ao Financeiro pela Cloudflare.\nPode apagá-lo.\n");
            $sent = true;
        } catch (Throwable $e) { $msg = $e->getMessage(); }
    }
    return ['code' => $code, 'subject' => $subject, 'sent' => $sent, 'msg' => $msg];
}

/** As caixas ligadas sem carregar o Financeiro inteiro. */
function fin_mail_boxes_safe(PDO $pdo): array {
    $out = [];
    foreach (preg_split('/\R/', (string)setting($pdo, 'fin_mail_boxes', '')) as $l) {
        $l = strtolower(trim($l));
        if (filter_var($l, FILTER_VALIDATE_EMAIL) && !in_array($l, $out, true)) $out[] = $l;
    }
    return $out;
}

/** O teste com este código já chegou? Devolve a linha ou null. */
function mail_inbound_test_seen(PDO $pdo, string $code): ?array {
    if (!preg_match('/^[A-Z0-9]{6}$/', $code) || !mail_inbound_ready($pdo)) return null;
    $st = $pdo->prepare("SELECT box, created_at FROM mail_inbound WHERE status = 'test' AND note = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

/**
 * O estado do circuito para o guia em Definições: MX do domínio na Cloudflare, segredo, último email assinado,
 * serviço de envio. Cada passo: [feito?, texto].
 */
function mail_inbound_guide(PDO $pdo): array {
    $addr = mail_inbound_address($pdo);
    $dom = substr($addr, strpos($addr, '@') + 1);
    $mx = [];
    if (function_exists('dns_get_record') && !(defined('IS_LOCAL') && IS_LOCAL)) {
        $r = @dns_get_record($dom, DNS_MX);
        foreach ((array)$r as $x) $mx[] = strtolower((string)($x['target'] ?? ''));
    }
    $cfMx = (bool)array_filter($mx, fn($t) => str_ends_with($t, '.mx.cloudflare.net'));
    $last = mail_inbound_last_any($pdo);
    return [
        'address' => $addr, 'domain' => $dom, 'mx' => $mx, 'mx_ok' => $cfMx, 'secret' => mailer_inbound_secret() !== '',
        'last' => $last, 'api' => mailer_api(), 'mode' => fin_mail_mode($pdo), 'send' => mailer_mode($pdo),
    ];
}
