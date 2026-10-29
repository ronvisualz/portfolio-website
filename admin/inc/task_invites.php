<?php
/**
 * topper. admin — reuniões criadas no próprio formulário da tarefa (§31.6)
 *
 *  · «Convidar»: os convidados externos de uma reunião (tasks.invitees), sugeridos a partir da ficha de contacto
 *    (pessoa e empresa), do negócio/lead e dos outros contactos da mesma empresa; os internos (responsável e
 *    participantes) são convidados por defeito com o email de trabalho.
 *  · Google Meet: o evento é criado/atualizado ao gravar, pela camada inc/gcal.php (organizador por defeito a conta
 *    da topper.); a Google envia os convites, as mudanças e os cancelamentos.
 *  · Discord e Presencial: convite por email (serviço de envio, §31.1) com o evento em .ics (METHOD:REQUEST),
 *    atualização quando muda e cancelamento (METHOD:CANCEL) a quem sai ou quando a reunião é apagada. As respostas
 *    (Sim/Não/Talvez) voltam à caixa geral, que a Cloudflare entrega à plataforma.
 *  · Em português, ou em inglês para quem tem esse idioma na ficha; o código e o nome do negócio no título.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/gcal.php';
require_once __DIR__ . '/ical.php';
require_once __DIR__ . '/mailer.php';

const TINV_MAX = 30;                 // convidados externos por reunião
const TINV_RESP = ['accepted' => ['Sim', 'Yes'], 'declined' => ['Não', 'No'], 'tentative' => ['Talvez', 'Maybe']];

function tinv_ready(PDO $pdo): bool {
    return db_has_table($pdo, 'task_invites') && db_has_column($pdo, 'tasks', 'invitees');
}

/** Os emails escritos (vírgulas, espaços, linhas ou JSON): [válidos (minúsculas, sem repetidos), inválidos]. */
function tinv_parse($raw): array {
    if (is_string($raw) && str_starts_with(ltrim($raw), '[')) $raw = json_decode($raw, true) ?: [];
    $list = is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
    $ok = []; $bad = [];
    foreach ($list as $e) {
        $e = strtolower(trim((string)$e, " \t\n\r<>\"'"));
        if ($e === '') continue;
        if (filter_var($e, FILTER_VALIDATE_EMAIL) && strlen($e) <= 190) $ok[$e] = true; else $bad[] = $e;
    }
    return [array_keys($ok), $bad];
}

/** Os convidados externos guardados numa tarefa. */
function tinv_of(array $t): array {
    return tinv_parse((string)($t['invitees'] ?? ''))[0];
}

/**
 * As sugestões para «Convidar»: os emails da ficha de contacto (pessoa e empresa), do negócio/lead e das outras
 * pessoas da mesma empresa. Cada uma: email, name, src (de onde vem), lang. Também a morada sugerida para o «Local».
 */
function tinv_suggest(PDO $pdo, ?int $dealId, ?int $contactId): array {
    $out = []; $addr = ''; $addrOf = '';
    $add = function (string $email, string $name, string $src, string $lang = 'pt') use (&$out) {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($out[$email])) return;
        $out[$email] = ['email' => $email, 'name' => $name, 'src' => $src, 'lang' => $lang === 'en' ? 'en' : 'pt'];
    };
    $lang = db_has_column($pdo, 'contacts', 'lang');
    $get = function (int $id) use ($pdo, $lang) {
        $st = $pdo->prepare('SELECT id, kind, name, email, company_id, address' . ($lang ? ', lang' : ", 'pt' AS lang") . ' FROM contacts WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    };
    $people = function (int $companyId) use ($pdo, $lang) {
        $st = $pdo->prepare("SELECT id, name, email" . ($lang ? ', lang' : ", 'pt' AS lang") . " FROM contacts WHERE company_id = ? AND kind = 'person' AND email <> '' AND (is_active = 1 OR is_active IS NULL) ORDER BY name LIMIT 20");
        $st->execute([$companyId]);
        return $st->fetchAll();
    };
    $companies = [];
    $take = function (?array $c, string $src) use (&$companies, &$addr, &$addrOf, $add, $get) {
        if (!$c) return;
        $add((string)$c['email'], (string)$c['name'], $src, (string)$c['lang']);
        if ($addr === '' && trim((string)$c['address']) !== '') { $addr = trim((string)$c['address']); $addrOf = (string)$c['name']; }
        if ($c['kind'] === 'company') { $companies[(int)$c['id']] = true; return; }
        if ($c['company_id'] && ($co = $get((int)$c['company_id']))) {
            $add((string)$co['email'], (string)$co['name'], 'Empresa', (string)$co['lang']);
            if ($addr === '' && trim((string)$co['address']) !== '') { $addr = trim((string)$co['address']); $addrOf = (string)$co['name']; }
            $companies[(int)$co['id']] = true;
        }
    };
    if ($contactId) $take($get($contactId), 'Contacto');
    if ($dealId) {
        $st = $pdo->prepare('SELECT id, code, name, email, person, contact_id, person_id, company_id FROM deals WHERE id = ?');
        $st->execute([$dealId]);
        if ($d = $st->fetch()) {
            foreach (['person_id', 'contact_id', 'company_id'] as $f) if ($d[$f] && (int)$d[$f] !== $contactId) $take($get((int)$d[$f]), 'Negócio');
            $add((string)$d['email'], (string)($d['person'] ?: $d['name']), 'Negócio');
            if (db_has_table($pdo, 'deal_contacts')) {
                $st = $pdo->prepare('SELECT contact_id FROM deal_contacts WHERE deal_id = ? LIMIT 20');
                $st->execute([$dealId]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) $take($get((int)$cid), 'Negócio');
            }
        }
    }
    foreach (array_keys($companies) as $coId) foreach ($people($coId) as $p) $add((string)$p['email'], (string)$p['name'], 'Mesma empresa', (string)$p['lang']);
    return ['suggest' => array_values(array_slice($out, 0, 40)), 'address' => $addr, 'address_of' => $addrOf];
}

/** O idioma de um email (o da ficha de contacto com esse email; senão $fallback). */
function tinv_lang_of(PDO $pdo, string $email, string $fallback = 'pt'): string {
    if (!db_has_column($pdo, 'contacts', 'lang')) return 'pt';
    $st = $pdo->prepare('SELECT lang FROM contacts WHERE LOWER(email) = ? ORDER BY (lang = ?) DESC LIMIT 1');
    $st->execute([strtolower($email), 'en']);
    $l = $st->fetchColumn();
    return $l === false ? $fallback : ((string)$l === 'en' ? 'en' : 'pt');
}

/** O idioma da reunião: o do contacto da tarefa (ou da sua empresa); senão inglês só se todos os externos o tiverem. */
function tinv_task_lang(PDO $pdo, array $t, array $external = []): string {
    if (!db_has_column($pdo, 'contacts', 'lang')) return 'pt';
    if (!empty($t['contact_id'])) {
        $st = $pdo->prepare('SELECT c.lang, co.lang AS co_lang FROM contacts c LEFT JOIN contacts co ON co.id = c.company_id WHERE c.id = ?');
        $st->execute([(int)$t['contact_id']]);
        if ($r = $st->fetch()) return $r['lang'] === 'en' || $r['co_lang'] === 'en' ? 'en' : 'pt';
    }
    if (!$external) return 'pt';
    foreach ($external as $e) if (tinv_lang_of($pdo, $e, '') !== 'en') return 'pt';
    return 'en';
}

/**
 * O evento como os convidados o veem: título com o código e o nome do negócio, o tipo, a hora, o link (Discord)
 * ou o local, a ordem de trabalhos. Sem links internos da plataforma.
 */
function tinv_event(array $t, string $lang, string $link = ''): array {
    $en = $lang === 'en';
    $deal = trim(($t['deal_code'] ?? '') . ' · ' . ($t['deal_name'] ?? ''), ' ·');
    $title = (string)$t['title'];
    if (($t['deal_name'] ?? '') !== '' && mb_stripos($title, (string)$t['deal_name']) !== false) $deal = (string)($t['deal_code'] ?? '');
    $summary = ($deal !== '' ? $deal . ' · ' : '') . $title;
    $start = $t['due_date'] . ' ' . substr((string)$t['due_time'], 0, 5);
    $min = (int)($t['duration_min'] ?: 60);
    $plat = (string)($t['platform'] ?? '');
    $where = $plat === 'discord' ? 'Discord' : ($plat === 'meet' ? 'Google Meet' : trim((string)($t['location'] ?? '')));
    $desc = ($en ? 'Meeting' : 'Reunião') . ($where !== '' ? ($en ? ' · ' : ' · ') . $where : '') . "\n"
          . (($t['deal_code'] ?? '') !== '' ? ($en ? 'Project: ' : 'Negócio: ') . $t['deal_code'] . ' · ' . $t['deal_name'] . "\n" : '')
          . ($link !== '' ? "\n" . ($en ? 'Join: ' : 'Entrar: ') . $link . "\n" : '')
          . (trim((string)$t['description']) !== '' ? "\n" . ($en ? 'Agenda / notes:' : 'Ordem de trabalhos / notas:') . "\n" . trim((string)$t['description']) . "\n" : '')
          . "\n— topper.";
    return ['summary' => $summary, 'description' => $desc, 'start' => $start, 'minutes' => $min,
            'location' => $plat === 'discord' ? $link : ($plat === '' ? trim((string)($t['location'] ?? '')) : ''), 'busy' => (int)($t['busy'] ?? 1) === 1];
}

/** A data por extenso para o email (Lisboa). */
function tinv_when(string $start, int $min, string $lang): string {
    $a = new DateTimeImmutable($start, new DateTimeZone(GCAL_TZ));
    $b = $a->modify('+' . $min . ' minutes');
    if ($lang === 'en') return $a->format('l, j F Y') . ', ' . $a->format('H:i') . '–' . $b->format('H:i') . ' (Lisbon time)';
    $d = ['', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado', 'domingo'][(int)$a->format('N')];
    $m = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'][(int)$a->format('n')];
    return $d . ', ' . (int)$a->format('j') . ' de ' . $m . ' de ' . $a->format('Y') . ', ' . $a->format('H:i') . '–' . $b->format('H:i') . ' (hora de Lisboa)';
}

/** O endereço que organiza os convites por email: a caixa geral (as respostas voltam lá e daí à plataforma). */
function tinv_organizer(PDO $pdo): string {
    $g = strtolower((string)setting($pdo, 'mail_general', 'info@topperdigital.xyz'));
    return filter_var($g, FILTER_VALIDATE_EMAIL) ? $g : 'info@topperdigital.xyz';
}

/** O .ics de um convite (METHOD:REQUEST) ou de um cancelamento (METHOD:CANCEL). */
function tinv_ics(int $taskId, array $ev, string $method, int $seq, string $to, string $organizer): string {
    $host = parse_url(app_base_url(), PHP_URL_HOST) ?: 'topperhub.xyz';
    $utc = new DateTimeZone('UTC');
    $a = new DateTimeImmutable($ev['start'], new DateTimeZone(GCAL_TZ));
    $b = $a->modify('+' . max(5, (int)$ev['minutes']) . ' minutes');
    $cancel = $method === 'CANCEL';
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//topper.//Convites//PT\r\nCALSCALE:GREGORIAN\r\nMETHOD:$method\r\nBEGIN:VEVENT\r\n"
         . ical_prop('UID', 'topper-task-' . $taskId . '@' . $host)
         . 'SEQUENCE:' . $seq . "\r\n"
         . 'DTSTAMP:' . (new DateTimeImmutable('now', $utc))->format('Ymd\THis\Z') . "\r\n"
         . 'DTSTART:' . $a->setTimezone($utc)->format('Ymd\THis\Z') . "\r\n" . 'DTEND:' . $b->setTimezone($utc)->format('Ymd\THis\Z') . "\r\n"
         . ical_prop('SUMMARY', $ev['summary']) . ical_prop('DESCRIPTION', $ev['description'])
         . ((string)$ev['location'] !== '' ? ical_prop('LOCATION', (string)$ev['location']) : '')
         . ical_prop('ORGANIZER;CN=topper.', 'mailto:' . $organizer)
         . ical_prop('ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE', 'mailto:' . $to)
         . 'STATUS:' . ($cancel ? 'CANCELLED' : 'CONFIRMED') . "\r\nTRANSP:OPAQUE\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
}

/** O texto do email do convite, da atualização ou do cancelamento. Devolve [assunto, corpo]. */
function tinv_mail_text(array $ev, string $kind, string $lang, string $by, string $link = '', string $plat = ''): array {
    $en = $lang === 'en';
    $when = tinv_when($ev['start'], (int)$ev['minutes'], $lang);
    $head = ['new' => $en ? 'Invitation' : 'Convite', 'update' => $en ? 'Updated' : 'Reunião atualizada', 'cancel' => $en ? 'Cancelled' : 'Reunião cancelada'][$kind];
    $subject = $head . ': ' . $ev['summary'] . ' · ' . (new DateTimeImmutable($ev['start']))->format($en ? 'M j, H:i' : 'd/m H:i');
    if ($kind === 'cancel') {
        $body = ($en ? "This meeting has been cancelled:\n\n" : "Esta reunião foi cancelada:\n\n") . $ev['summary'] . "\n" . $when . "\n\n"
              . ($en ? 'The attached file removes it from your calendar.' : 'O ficheiro anexo retira-a do seu calendário.') . "\n\n— " . $by . ' · topper.';
        return [$subject, $body];
    }
    $where = $plat === 'discord' ? 'Discord' . ($link !== '' ? ': ' . $link : '') : ((string)$ev['location'] !== '' ? ($en ? 'Place: ' : 'Local: ') . $ev['location'] : '');
    $body = ($kind === 'update' ? ($en ? "The meeting has been updated:\n\n" : "A reunião foi atualizada:\n\n") : ($en ? "You are invited to a meeting:\n\n" : "Está convidado(a) para uma reunião:\n\n"))
          . $ev['summary'] . "\n" . $when . "\n" . ($where !== '' ? $where . "\n" : '')
          . "\n" . ($en ? 'Open the attached invitation to add it to your calendar and reply (Yes / No / Maybe).' : 'Abra o convite em anexo para o pôr no seu calendário e responder (Sim / Não / Talvez).') . "\n"
          . "\n— " . $by . ' · topper.';
    return [$subject, $body];
}

/** Envia um email do convite (com o .ics). Pelo serviço de envio quando está pronto; senão pelo mail() do servidor. */
function tinv_mail(PDO $pdo, string $to, string $subject, string $body, string $ics, string $method, string $replyTo, string $fromName): void {
    $att = [['name' => $method === 'CANCEL' ? 'cancel.ics' : 'invite.ics', 'mime' => 'text/calendar; charset=UTF-8; method=' . $method, 'bytes' => $ics]];
    if (!app_mail($pdo, $to, $subject, $body, $fromName, $att, $replyTo)) throw new RuntimeException('O email não saiu.');
}

/** O que foi enviado a um convidado (para saber se mudou). */
function tinv_sig(array $ev, string $lang, string $link): string {
    return hash('sha256', json_encode([$ev['summary'], $ev['start'], (int)$ev['minutes'], (string)$ev['location'], $link, $lang]));
}

/** Os convites por email já enviados de uma tarefa [email => linha]. */
function tinv_rows(PDO $pdo, int $taskId): array {
    if (!tinv_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT * FROM task_invites WHERE task_id = ?');
    $st->execute([$taskId]);
    $o = [];
    foreach ($st->fetchAll() as $r) $o[strtolower((string)$r['email'])] = $r;
    return $o;
}

/** Quem recebe convite por email numa reunião Discord/presencial: os externos e os internos sem o Google ligado. */
function tinv_email_guests(PDO $pdo, array $t): array {
    $ext = tinv_of($t);
    $int = [];
    foreach (gcal_task_people($pdo, $t) as $uid => $email) {
        $c = gcal_conn($pdo, (int)$uid);
        if ($c && (int)$c['sync_on']) continue;   // já tem o espelho no seu calendário: sem convite duplicado
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $int[] = $email;
    }
    return array_values(array_unique(array_merge($ext, $int)));
}

/** O link do Discord (Definições › Integrações). */
function tinv_discord(): string {
    if (!function_exists('team_meet_settings')) require_once __DIR__ . '/team.php';
    return (string)(team_meet_settings()['discord']['invite_url'] ?? '');
}

/**
 * Põe os convites por email em linha com a tarefa (Discord e presencial): envia a quem entrou, atualiza quando a hora,
 * o título, o local ou o link mudam, cancela a quem saiu. Reunião feita fica; apagada, sem hora ou passada para o Meet
 * → cancela a todos. Devolve ['sent', 'updated', 'cancelled', 'failed', 'error'].
 */
function tinv_sync(PDO $pdo, int $taskId, ?int $by = null): array {
    $r = ['sent' => 0, 'updated' => 0, 'cancelled' => 0, 'failed' => 0, 'error' => ''];
    if (!tinv_ready($pdo)) return $r;
    $t = gcal_task($pdo, $taskId);
    $rows = tinv_rows($pdo, $taskId);
    if ($t && $t['status'] === 'done') return $r;   // já aconteceu
    $live = $t && $t['kind'] === 'meeting' && in_array((string)$t['platform'], ['discord', ''], true) && gcal_task_live($t);
    $want = $live ? tinv_email_guests($pdo, $t) : [];
    $organizer = tinv_organizer($pdo);
    $uid = $by ?? (int)($t['created_by'] ?? 0);
    $st = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch() ?: ['name' => 'topper.', 'email' => ''];
    $byName = (string)$u['name'] ?: 'topper.';
    $reply = filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL) ? (string)$u['email'] : $organizer;
    $now = date('Y-m-d H:i:s');
    if ($live) {
        $link = (string)$t['platform'] === 'discord' ? ((string)$t['call_link'] !== '' ? (string)$t['call_link'] : tinv_discord()) : '';
        $lang0 = tinv_task_lang($pdo, $t, tinv_of($t));
        foreach ($want as $email) {
            $row = $rows[$email] ?? null;
            $lang = tinv_lang_of($pdo, $email, $lang0);
            $ev = tinv_event($t, $lang, $link);
            $sig = tinv_sig($ev, $lang, $link);
            if ($row && $row['status'] === 'sent' && $row['sig'] === $sig) continue;
            $seq = $row ? (int)$row['seq'] + 1 : 0;
            $kind = $row && $row['status'] === 'sent' ? 'update' : 'new';
            [$subj, $body] = tinv_mail_text($ev, $kind, $lang, $byName, $link, (string)$t['platform']);
            $meta = json_encode($ev + ['lang' => $lang], JSON_UNESCAPED_UNICODE);
            try {
                tinv_mail($pdo, $email, $subj, $body, tinv_ics($taskId, $ev, 'REQUEST', $seq, $email, $organizer), 'REQUEST', $reply, $byName);
                $status = 'sent'; $err = '';
                $r[$kind === 'new' ? 'sent' : 'updated']++;
            } catch (Throwable $e) {
                $status = 'failed'; $err = mb_substr($e->getMessage(), 0, 300);
                $r['failed']++; $r['error'] = $err;
            }
            if ($row) {
                $pdo->prepare('UPDATE task_invites SET channel = ?, lang = ?, seq = ?, sig = ?, meta = ?, status = ?, last_error = ?, sent_at = ?' . ($kind === 'new' ? ", response = ''" : '') . ' WHERE id = ?')
                    ->execute([(string)$t['platform'] ?: 'local', $lang, $seq, $status === 'sent' ? $sig : '', $meta, $status, $err, $now, (int)$row['id']]);
            } else {
                $pdo->prepare('INSERT INTO task_invites (task_id, email, channel, lang, seq, sig, meta, status, last_error, sent_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$taskId, $email, (string)$t['platform'] ?: 'local', $lang, $seq, $status === 'sent' ? $sig : '', $meta, $status, $err, $now, $now]);
            }
        }
    }
    // quem saiu (ou a reunião toda): cancelamento com o resumo guardado no envio
    foreach ($rows as $email => $row) {
        if (in_array($email, $want, true) || $row['status'] !== 'sent') {
            if (!in_array($email, $want, true) && $row['status'] === 'failed') $pdo->prepare('DELETE FROM task_invites WHERE id = ?')->execute([(int)$row['id']]);
            continue;
        }
        $ev = json_decode((string)$row['meta'], true) ?: null;
        if (!$ev || empty($ev['start'])) { $pdo->prepare('DELETE FROM task_invites WHERE id = ?')->execute([(int)$row['id']]); continue; }
        $lang = (string)($ev['lang'] ?? 'pt');
        [$subj, $body] = tinv_mail_text($ev, 'cancel', $lang, $byName);
        try {
            tinv_mail($pdo, $email, $subj, $body, tinv_ics($taskId, $ev, 'CANCEL', (int)$row['seq'] + 1, $email, $organizer), 'CANCEL', $reply, $byName);
            $pdo->prepare('DELETE FROM task_invites WHERE id = ?')->execute([(int)$row['id']]);
            $r['cancelled']++;
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE task_invites SET last_error = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 300), (int)$row['id']]);
            $r['failed']++; $r['error'] = $e->getMessage();
        }
    }
    return $r;
}

/** O resumo para a mensagem depois de gravar. */
function tinv_summary(array $r): string {
    $p = [];
    if ($r['sent']) $p[] = $r['sent'] . ($r['sent'] === 1 ? ' convite enviado' : ' convites enviados') . ' por email';
    if ($r['updated']) $p[] = $r['updated'] . ($r['updated'] === 1 ? ' convidado avisado' : ' convidados avisados') . ' da mudança';
    if ($r['cancelled']) $p[] = $r['cancelled'] . ($r['cancelled'] === 1 ? ' convite cancelado' : ' convites cancelados');
    if ($r['failed']) $p[] = $r['failed'] . ' por enviar (' . $r['error'] . ')';
    return $p ? ' ' . ucfirst(implode(' · ', $p)) . '.' : '';
}

/** As respostas dos convites por email [email => accepted|declined|tentative|'']. */
function tinv_responses(PDO $pdo, int $taskId): array {
    $o = [];
    foreach (tinv_rows($pdo, $taskId) as $e => $r) $o[$e] = ['status' => (string)$r['response'], 'state' => (string)$r['status'], 'error' => (string)$r['last_error']];
    return $o;
}

/**
 * Uma resposta a um convite que voltou à caixa geral (iTIP METHOD:REPLY): guarda Sim/Não/Talvez ao lado do email.
 * Devolve a nota para o registo ou '' se não for uma resposta a um convite da plataforma.
 */
function tinv_reply(PDO $pdo, string $raw): string {
    if (!tinv_ready($pdo) || (stripos($raw, 'text/calendar') === false && stripos($raw, '.ics') === false)) return '';
    require_once __DIR__ . '/fin_mail.php';
    [$h, $body] = fin_mime_split($raw);
    $txt = '';
    tinv_cal_parts($h, $body, $txt, 0);
    if (stripos($txt, 'METHOD:REPLY') === false) return '';
    $txt = preg_replace("/\r?\n[ \t]/", '', $txt);   // desdobrar as linhas do iCalendar
    if (!preg_match('/UID:topper-task-(\d+)@/i', $txt, $u)) return '';
    if (!preg_match('/^ATTENDEE[^\n]*PARTSTAT=([A-Z-]+)[^\n]*:mailto:([^\s;>]+)/mi', $txt, $a)) return '';
    $map = ['ACCEPTED' => 'accepted', 'DECLINED' => 'declined', 'TENTATIVE' => 'tentative'];
    $st = $map[strtoupper($a[1])] ?? '';
    $email = strtolower(trim($a[2]));
    if ($st === '') return '';
    $q = $pdo->prepare('UPDATE task_invites SET response = ? WHERE task_id = ? AND email = ?');
    $q->execute([$st, (int)$u[1], $email]);
    if (!$q->rowCount()) return '';
    if (function_exists('task_log')) task_log($pdo, (int)$u[1], 'invite', $email . ' respondeu: ' . TINV_RESP[$st][0]);
    return 'Resposta ao convite: ' . $email . ' · ' . TINV_RESP[$st][0];
}

/** Junta as partes text/calendar (e .ics) de um email, já decifradas. */
function tinv_cal_parts(array $h, string $body, string &$acc, int $depth): void {
    if ($depth > 6) return;
    $ct = (string)($h['content-type'] ?? 'text/plain');
    $type = strtolower(trim(strtok($ct, ';')));
    if (str_starts_with($type, 'multipart/')) {
        $b = fin_mime_param($ct, 'boundary');
        if ($b === '') return;
        $parts = explode('--' . $b, $body);
        array_shift($parts);
        foreach ($parts as $p) {
            if (str_starts_with($p, '--')) break;
            [$ph, $pb] = fin_mime_split(ltrim($p, "\r\n"));
            tinv_cal_parts($ph, $pb, $acc, $depth + 1);
        }
        return;
    }
    $name = fin_mime_param((string)($h['content-disposition'] ?? ''), 'filename') ?: fin_mime_param($ct, 'name');
    if ($type !== 'text/calendar' && $type !== 'application/ics' && !preg_match('/\.ics$/i', $name)) return;
    $enc = strtolower(trim((string)($h['content-transfer-encoding'] ?? '')));
    $acc .= "\n" . ($enc === 'base64' ? (string)base64_decode(preg_replace('/\s+/', '', $body)) : ($enc === 'quoted-printable' ? quoted_printable_decode($body) : $body));
}

/** Os convites e os eventos de tarefas que já não existem (apagadas por outro caminho): cancela-os. Corre no cron. */
function tinv_orphans(PDO $pdo): int {
    $n = 0;
    if (tinv_ready($pdo)) {
        foreach ($pdo->query('SELECT DISTINCT i.task_id FROM task_invites i LEFT JOIN tasks t ON t.id = i.task_id WHERE t.id IS NULL LIMIT 20')->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            $n += tinv_sync($pdo, (int)$tid)['cancelled'];
        }
    }
    if (gcal_ready($pdo)) {
        foreach ($pdo->query("SELECT DISTINCT l.ref_id FROM calendar_links l LEFT JOIN tasks t ON t.id = l.ref_id WHERE l.ref_type = 'task' AND t.id IS NULL LIMIT 20")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            $n += gcal_cancel($pdo, 'task', (int)$tid);
        }
    }
    return $n;
}
