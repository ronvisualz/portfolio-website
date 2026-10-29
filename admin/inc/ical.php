<?php
/**
 * topper. admin — subscrever a agenda (§31.5): um link iCal privado por pessoa, só leitura.
 *
 * O link leva um token de 32 bytes. Na base fica o sha256 (para o encontrar) e o token cifrado com a chave do
 * servidor (para o voltar a mostrar no Perfil); gerar outro revoga o anterior. Entram as tarefas com hora da pessoa
 * (responsável ou participante): reuniões, gravações, visitas e prazos com hora, de 30 dias para trás a 180 para a
 * frente. A app de calendário volta a ler o link sozinha (de 15 minutos a algumas horas, conforme a app).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/secrets.php';

function ical_ready(PDO $pdo): bool {
    return db_has_column($pdo, 'users', 'ical_hash');
}

function ical_url(string $token): string {
    return app_base_url() . '/admin/ical.php?t=' . $token;
}

/** O link atual da pessoa ('' se não tiver). */
function ical_current(PDO $pdo, int $uid): string {
    if (!ical_ready($pdo)) return '';
    $st = $pdo->prepare('SELECT ical_enc FROM users WHERE id = ? AND ical_hash IS NOT NULL');
    $st->execute([$uid]);
    $enc = (string)$st->fetchColumn();
    $tok = $enc !== '' ? secret_open($enc) : null;
    return $tok ? ical_url($tok) : '';
}

/** Gera um link novo (o anterior deixa de funcionar). Devolve o endereço. */
function ical_new(PDO $pdo, int $uid): string {
    $tok = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare('UPDATE users SET ical_hash = ?, ical_enc = ?, ical_at = ? WHERE id = ?')
        ->execute([hash('sha256', $tok), secret_seal($tok), date('Y-m-d H:i:s'), $uid]);
    return ical_url($tok);
}

function ical_off(PDO $pdo, int $uid): void {
    $pdo->prepare('UPDATE users SET ical_hash = NULL, ical_enc = NULL, ical_at = NULL WHERE id = ?')->execute([$uid]);
}

/** A pessoa dona deste token (utilizador ativo) ou null. */
function ical_user(PDO $pdo, string $tok): ?array {
    if (!ical_ready($pdo) || !preg_match('/^[A-Za-z0-9_-]{40,64}$/', $tok)) return null;
    $st = $pdo->prepare('SELECT * FROM users WHERE ical_hash = ? AND is_active = 1 AND deactivated_at IS NULL');
    $st->execute([hash('sha256', $tok)]);
    return $st->fetch() ?: null;
}

/** Texto de uma propriedade iCalendar: escapado e dobrado a 75 octetos (RFC 5545). */
function ical_prop(string $name, string $value): string {
    $v = str_replace(['\\', "\r\n", "\n", ',', ';'], ['\\\\', '\n', '\n', '\,', '\;'], $value);
    $line = $name . ':' . $v;
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) $cut--;   // não partir um carácter UTF-8
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line . "\r\n";
}

/** O calendário da pessoa em texto iCalendar. */
function ical_feed(PDO $pdo, array $u): string {
    require_once __DIR__ . '/gcal.php';
    $uid = (int)$u['id'];
    $mid = $u['team_member_id'] !== null ? (int)$u['team_member_id'] : 0;
    $st = $pdo->prepare("SELECT t.*, d.code AS deal_code, d.name AS deal_name FROM tasks t LEFT JOIN deals d ON d.id = t.deal_id
                          WHERE t.due_time IS NOT NULL AND t.due_date >= ? AND t.due_date <= ? AND t.status <> 'done'
                            AND (t.member_id = ? OR CONCAT(',', t.participants, ',') LIKE ?)
                          ORDER BY t.due_date, t.due_time LIMIT 1000");
    $st->execute([date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('+180 days')), $mid, '%,' . $uid . ',%']);
    $host = parse_url(app_base_url(), PHP_URL_HOST) ?: 'topperhub.xyz';
    $utc = new DateTimeZone('UTC');
    $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//topper.//Agenda//PT\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n"
         . ical_prop('X-WR-CALNAME', 'topper. · ' . first_name_ical((string)$u['name'])) . "X-WR-TIMEZONE:Europe/Lisbon\r\n"
         . "REFRESH-INTERVAL;VALUE=DURATION:PT15M\r\nX-PUBLISHED-TTL:PT15M\r\n";
    foreach ($st->fetchAll() as $t) {
        $ev = gcal_task_event($t);
        $start = new DateTimeImmutable($ev['start'], new DateTimeZone('Europe/Lisbon'));
        $end = $start->modify('+' . max(15, $ev['minutes']) . ' minutes');
        $desc = $ev['description'] . (($t['call_link'] ?? '') !== '' ? "\nChamada: " . $t['call_link'] : '');
        $out .= "BEGIN:VEVENT\r\n" . ical_prop('UID', 'task-' . (int)$t['id'] . '@' . $host)
              . 'DTSTAMP:' . (new DateTimeImmutable((string)($t['updated_at'] ?: 'now')))->setTimezone($utc)->format('Ymd\THis\Z') . "\r\n"
              . 'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z') . "\r\n" . 'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z') . "\r\n"
              . ical_prop('SUMMARY', $ev['summary']) . ical_prop('DESCRIPTION', $desc)
              . ($ev['location'] !== '' ? ical_prop('LOCATION', $ev['location']) : (($t['call_link'] ?? '') !== '' ? ical_prop('LOCATION', (string)$t['call_link']) : ''))
              . ical_prop('URL', app_base_url() . '/admin/calendar.php?edit=' . (int)$t['id'])
              . 'TRANSP:' . ($ev['busy'] ? 'OPAQUE' : 'TRANSPARENT') . "\r\nEND:VEVENT\r\n";
    }
    return $out . "END:VCALENDAR\r\n";
}

function first_name_ical(string $name): string {
    return explode(' ', trim($name))[0] ?: 'agenda';
}
