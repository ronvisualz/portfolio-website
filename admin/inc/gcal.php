<?php
/**
 * topper. admin — Google Calendar e Google Meet, numa só camada (§31.3 e §31.4)
 *
 * Nada mais na plataforma fala com a API do Google Calendar: tudo passa por aqui.
 *
 * Contas: «topper» (a conta da empresa, inc/meet_config.php — já criava os Meet das reuniões da Equipa) e
 * «user:<id>» (o Google Calendar de cada pessoa, ligado em Perfil › Ligações; token cifrado em user_google).
 *
 *  · Criar Google Meet num evento com hora (qualquer tarefa com dia e hora: reuniões e gravações/visitas de
 *    leads e negócios, kickoff, review, Agenda; e os pedidos de reunião da Equipa). Organizador: quem marca, se tiver
 *    o calendário ligado; senão a conta da topper. (com a pessoa como convidada). Convidados: os participantes
 *    internos e os contactos externos escolhidos — o cliente recebe o convite do Google.
 *  · Espelho no calendário de cada pessoa ligada: as suas reuniões, gravações, visitas e prazos com hora (criar,
 *    mudar, cancelar), sem convidados (não envia nada a ninguém). Quem já é convidado do Meet não leva cópia.
 *  · calendar_links liga cada evento do Google ao registo: mudar a hora ou cancelar na plataforma atualiza o
 *    Google; nunca há duplicados.
 *  · Ocupado/livre (freeBusy) de quem tem o calendário ligado, em cache, para a disponibilidade e as 3 sugestões.
 *  · Fase 2 preparada: scopes calendar.events + calendar.freebusy pedidos já na ligação, syncToken guardado,
 *    notificações push em admin/gcal_push.php (canal por calendário, renovado pelo cron quando ligado em Definições).
 * Sem credenciais Google, nada disto corre e tudo funciona como antes (link colado à mão).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/google_oauth.php';

const GCAL_API = 'https://www.googleapis.com/calendar/v3';
const GCAL_TZ = 'Europe/Lisbon';
const GCAL_BUSY_DAYS = 21;          // ocupado/livre em cache: os próximos dias
const GCAL_BUSY_TTL = 600;          // segundos até se voltar a perguntar à Google
const GCAL_MIRROR_DAYS = 60;        // na primeira ligação, os próximos dias vão para o calendário

function gcal_ready(PDO $pdo): bool {
    return db_has_table($pdo, 'calendar_links') && db_has_table($pdo, 'user_google');
}

/** A conta da topper. (inc/meet_config.php): ['refresh_token', 'calendar_id'] ou null. */
function gcal_topper(): ?array {
    static $c = false;
    if ($c !== false) return $c;
    $raw = config_file_read(__DIR__ . '/meet_config.php') ?? [];
    $g = (array)($raw['google'] ?? []);
    return $c = google_client() && config_filled($g['refresh_token'] ?? null)
        ? ['refresh_token' => trim((string)$g['refresh_token']), 'calendar_id' => (string)($g['calendar_id'] ?? 'primary') ?: 'primary'] : null;
}

/** A ligação de uma pessoa (ou null). */
function gcal_conn(PDO $pdo, int $uid): ?array {
    if (!gcal_ready($pdo)) return null;
    $st = $pdo->prepare('SELECT * FROM user_google WHERE user_id = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}

/** Pode criar Meet: a conta da topper. ou o calendário de quem marca. */
function gcal_can_meet(PDO $pdo, int $uid): bool {
    return gcal_ready($pdo) && (gcal_topper() !== null || gcal_conn($pdo, $uid) !== null);
}

/** O token de acesso e o calendário de uma conta ('topper' | 'user:<id>'). */
function gcal_token(PDO $pdo, string $account, bool $fresh = false): array {
    static $cache = [];
    if (!$fresh && isset($cache[$account]) && $cache[$account][2] > time() + 60) return $cache[$account];
    if ($account === 'topper') {
        $t = gcal_topper();
        if (!$t) throw new RuntimeException('A conta Google da topper. não está ligada (inc/meet_config.php).');
        $tok = google_access_token($t['refresh_token']);
        return $cache[$account] = [(string)$tok['access_token'], $t['calendar_id'], time() + (int)($tok['expires_in'] ?? 3000)];
    }
    $uid = (int)substr($account, 5);
    $c = gcal_conn($pdo, $uid);
    if (!$c) throw new RuntimeException('Esta pessoa não tem o Google Calendar ligado.');
    $raw = secret_open((string)$c['token_enc']);
    $rt = $raw !== null ? (string)(json_decode($raw, true)['refresh_token'] ?? '') : '';
    if ($rt === '') throw new RuntimeException('O acesso ao Google Calendar perdeu-se: volte a ligar no Perfil.');
    try {
        $tok = google_access_token($rt);
    } catch (RuntimeException $e) {
        $pdo->prepare('UPDATE user_google SET last_error = ? WHERE user_id = ?')->execute([mb_substr($e->getMessage(), 0, 300), $uid]);
        throw $e;
    }
    return $cache[$account] = [(string)$tok['access_token'], (string)$c['calendar_id'] ?: 'primary', time() + (int)($tok['expires_in'] ?? 3000)];
}

/** Um pedido à API do Calendar, com um novo token se o anterior tiver expirado. Devolve [código, json]. */
function gcal_api(PDO $pdo, string $account, string $method, string $path, array $query = [], ?array $body = null): array {
    [$tok] = gcal_token($pdo, $account);
    $url = google_url(GCAL_API . $path) . ($query ? '?' . http_build_query($query) : '');
    [$code, $j] = http_call($method, $url, ['Authorization: Bearer ' . $tok, 'Accept: application/json'], $body, 20);
    if ($code === 401) {
        [$tok] = gcal_token($pdo, $account, true);
        [$code, $j] = http_call($method, $url, ['Authorization: Bearer ' . $tok, 'Accept: application/json'], $body, 20);
    }
    return [$code, $j ?? []];
}

function gcal_cal_path(PDO $pdo, string $account): string {
    return '/calendars/' . rawurlencode(gcal_token($pdo, $account)[1]);
}

// ── eventos ↔ registos (calendar_links) ─────────────────────

function gcal_link(PDO $pdo, string $refType, int $refId, string $kind, ?string $account = null): ?array {
    if (!gcal_ready($pdo)) return null;
    $sql = 'SELECT * FROM calendar_links WHERE ref_type = ? AND ref_id = ? AND kind = ?' . ($account !== null ? ' AND account = ?' : '') . ' ORDER BY id LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($account !== null ? [$refType, $refId, $kind, $account] : [$refType, $refId, $kind]);
    return $st->fetch() ?: null;
}

function gcal_links(PDO $pdo, string $refType, int $refId): array {
    if (!gcal_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT * FROM calendar_links WHERE ref_type = ? AND ref_id = ?');
    $st->execute([$refType, $refId]);
    return $st->fetchAll();
}

/** O link do Meet de um evento devolvido pela Google. */
function gcal_meet_url(array $ev): string {
    if (!empty($ev['hangoutLink'])) return (string)$ev['hangoutLink'];
    foreach ((array)($ev['conferenceData']['entryPoints'] ?? []) as $p) if (($p['entryPointType'] ?? '') === 'video') return (string)$p['uri'];
    return '';
}

/**
 * Cria ou atualiza o evento de um registo. $ev: summary, description, start (aaaa-mm-dd hh:mm, Lisboa), minutes,
 * location, attendees (emails), busy, meet (criar Meet). $kind 'meet' (convites enviados) ou 'mirror' (sem convites).
 * Devolve a linha de calendar_links.
 */
function gcal_upsert(PDO $pdo, string $refType, int $refId, string $kind, string $account, array $ev, ?int $by = null): array {
    $a = new DateTimeImmutable($ev['start'], new DateTimeZone(GCAL_TZ));
    $b = $a->modify('+' . max(5, (int)($ev['minutes'] ?? 60)) . ' minutes');
    $body = ['summary' => mb_substr((string)$ev['summary'], 0, 250), 'description' => mb_substr((string)($ev['description'] ?? ''), 0, 6000),
             'start' => ['dateTime' => $a->format(DATE_RFC3339), 'timeZone' => GCAL_TZ], 'end' => ['dateTime' => $b->format(DATE_RFC3339), 'timeZone' => GCAL_TZ],
             'transparency' => !empty($ev['busy']) || !isset($ev['busy']) ? 'opaque' : 'transparent',
             'extendedProperties' => ['private' => ['topper_ref' => $refType . ':' . $refId, 'topper_kind' => $kind]]];
    if (($ev['location'] ?? '') !== '') $body['location'] = mb_substr((string)$ev['location'], 0, 200);
    $guests = array_values(array_unique(array_filter(array_map('strtolower', (array)($ev['attendees'] ?? [])), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    if ($kind === 'meet') $body['attendees'] = array_map(fn($e) => ['email' => $e], $guests);
    if ($kind === 'mirror') $body['reminders'] = ['useDefault' => true];
    $sig = hash('sha256', json_encode([$body['summary'], $body['start'], $body['end'], $body['location'] ?? '', $guests, $body['transparency']]));
    $send = $kind === 'meet' ? 'all' : 'none';
    $link = gcal_link($pdo, $refType, $refId, $kind, $account);
    $path = gcal_cal_path($pdo, $account) . '/events';
    $res = null;
    if ($link) {
        $old = json_decode((string)$link['guests'], true) ?: [];
        if (($old['sig'] ?? '') === $sig && $link['status'] === 'active') return $link;   // nada mudou
        [$code, $res] = gcal_api($pdo, $account, 'PATCH', $path . '/' . rawurlencode((string)$link['event_id']), ['conferenceDataVersion' => 1, 'sendUpdates' => $send], $body);
        if ($code === 404 || $code === 410) { $pdo->prepare('DELETE FROM calendar_links WHERE id = ?')->execute([(int)$link['id']]); $link = null; $res = null; }
        elseif ($code >= 300) throw new RuntimeException('O Google não atualizou o evento (' . $code . ': ' . mb_substr((string)($res['error']['message'] ?? ''), 0, 120) . ').');
    }
    if (!$link) {
        if (!empty($ev['meet'])) $body['conferenceData'] = ['createRequest' => ['requestId' => 'topper-' . $refType . '-' . $refId . '-' . bin2hex(random_bytes(4)), 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]];
        [$code, $res] = gcal_api($pdo, $account, 'POST', $path, ['conferenceDataVersion' => 1, 'sendUpdates' => $send], $body);
        if ($code >= 300 || empty($res['id'])) throw new RuntimeException('O Google não criou o evento (' . $code . ': ' . mb_substr((string)($res['error']['message'] ?? ''), 0, 120) . ').');
    }
    $cal = gcal_token($pdo, $account)[1];
    $now = date('Y-m-d H:i:s');
    $meetUrl = gcal_meet_url($res) ?: (string)($link['meet_url'] ?? '');
    $gj = json_encode(['emails' => $guests, 'sig' => $sig], JSON_UNESCAPED_UNICODE);
    if ($link) {
        $pdo->prepare("UPDATE calendar_links SET etag = ?, meet_url = ?, html_link = ?, guests = ?, status = 'active', synced_at = ? WHERE id = ?")
            ->execute([mb_substr((string)($res['etag'] ?? ''), 0, 64), mb_substr($meetUrl, 0, 255), mb_substr((string)($res['htmlLink'] ?? $link['html_link']), 0, 500), $gj, $now, (int)$link['id']]);
        return gcal_link($pdo, $refType, $refId, $kind, $account);
    }
    $pdo->prepare('INSERT INTO calendar_links (ref_type, ref_id, kind, account, calendar_id, event_id, etag, meet_url, html_link, guests, status, synced_at, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$refType, $refId, $kind, $account, mb_substr($cal, 0, 190), mb_substr((string)$res['id'], 0, 255), mb_substr((string)($res['etag'] ?? ''), 0, 64),
                   mb_substr($meetUrl, 0, 255), mb_substr((string)($res['htmlLink'] ?? ''), 0, 500), $gj, 'active', $now, $by, $now]);
    return gcal_link($pdo, $refType, $refId, $kind, $account);
}

/** Retira do Google um evento ligado (cancela o Meet com aviso aos convidados; o espelho sem avisos). */
function gcal_delete_link(PDO $pdo, array $l): void {
    // outros registos podem partilhar o mesmo evento (a reunião da Equipa e as tarefas dos dois): só o último o apaga
    $st = $pdo->prepare('SELECT COUNT(*) FROM calendar_links WHERE event_id = ? AND account = ? AND id <> ?');
    $st->execute([$l['event_id'], $l['account'], (int)$l['id']]);
    if (!(int)$st->fetchColumn()) {
        try {
            [$code] = gcal_api($pdo, (string)$l['account'], 'DELETE', '/calendars/' . rawurlencode((string)$l['calendar_id']) . '/events/' . rawurlencode((string)$l['event_id']), ['sendUpdates' => $l['kind'] === 'meet' ? 'all' : 'none']);
            if ($code >= 300 && $code !== 404 && $code !== 410) throw new RuntimeException('O Google não apagou o evento (' . $code . ').');
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'perdeu-se') && !str_contains($e->getMessage(), 'revogou')) throw $e;   // sem acesso já não há o que apagar
        }
    }
    $pdo->prepare('DELETE FROM calendar_links WHERE id = ?')->execute([(int)$l['id']]);
}

/** Cancela no Google tudo o que está ligado a um registo (ou só um tipo). */
function gcal_cancel(PDO $pdo, string $refType, int $refId, ?string $kind = null): int {
    $n = 0;
    foreach (gcal_links($pdo, $refType, $refId) as $l) {
        if ($kind !== null && $l['kind'] !== $kind) continue;
        try { gcal_delete_link($pdo, $l); $n++; } catch (Throwable $e) { error_log('[topper] gcal_cancel: ' . $e->getMessage()); }
    }
    return $n;
}

/** Os convidados e as respostas (Sim/Não/Talvez) do Meet, lidos agora à Google (fase 1: ao abrir). */
function gcal_responses(PDO $pdo, array $l): array {
    [$code, $ev] = gcal_api($pdo, (string)$l['account'], 'GET', '/calendars/' . rawurlencode((string)$l['calendar_id']) . '/events/' . rawurlencode((string)$l['event_id']));
    if ($code >= 300) return json_decode((string)$l['responses'], true) ?: [];
    $out = [];
    foreach ((array)($ev['attendees'] ?? []) as $a) $out[] = ['email' => (string)($a['email'] ?? ''), 'status' => (string)($a['responseStatus'] ?? 'needsAction'), 'organizer' => !empty($a['organizer'])];
    $pdo->prepare('UPDATE calendar_links SET responses = ?, synced_at = ? WHERE id = ?')->execute([json_encode($out, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), (int)$l['id']]);
    return $out;
}

const GCAL_RESP = ['accepted' => 'Sim', 'declined' => 'Não', 'tentative' => 'Talvez', 'needsAction' => 'Sem resposta'];

// ── tarefas (os eventos com hora da plataforma) ─────────────

/** A tarefa com o que é preciso para o evento (negócio, contacto, responsável). */
function gcal_task(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT t.*, d.code AS deal_code, d.name AS deal_name, u.id AS owner_uid, u.email AS owner_email, u.name AS owner_name, c.email AS contact_email, c.name AS contact_name
                           FROM tasks t LEFT JOIN deals d ON d.id = t.deal_id LEFT JOIN users u ON u.team_member_id = t.member_id AND t.member_id IS NOT NULL
                           LEFT JOIN contacts c ON c.id = t.contact_id WHERE t.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** As pessoas da plataforma num evento: o responsável e os participantes [uid => email]. */
function gcal_task_people(PDO $pdo, array $t): array {
    $ids = array_filter(array_map('intval', explode(',', (string)($t['participants'] ?? ''))));
    if (!empty($t['owner_uid'])) $ids[] = (int)$t['owner_uid'];
    $ids = array_values(array_unique($ids));
    if (!$ids) return [];
    $st = $pdo->prepare('SELECT id, email FROM users WHERE is_active = 1 AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    return array_map('strtolower', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Título e descrição do evento: tipo, código do negócio, notas (ordem de trabalhos) e o link da plataforma. */
function gcal_task_event(array $t): array {
    require_once __DIR__ . '/tasks.php';
    $kind = function_exists('task_type') && defined('TASK_TYPES') ? (TASK_TYPES[task_type($t)][0] ?? '') : '';
    $summary = ($t['deal_code'] ? $t['deal_code'] . ' · ' : '') . (string)$t['title'];
    $desc = ($kind !== '' ? $kind . "\n" : '') . ($t['deal_code'] ? 'Negócio: ' . $t['deal_code'] . ' · ' . $t['deal_name'] . "\n" : '')
          . (trim((string)$t['description']) !== '' ? "\nOrdem de trabalhos / notas:\n" . trim((string)$t['description']) . "\n" : '')
          . "\nNa plataforma: " . app_base_url() . '/admin/calendar.php?edit=' . (int)$t['id'];
    return ['summary' => $summary, 'description' => $desc, 'start' => $t['due_date'] . ' ' . substr((string)$t['due_time'], 0, 5),
            'minutes' => (int)($t['duration_min'] ?: 60), 'location' => (string)($t['location'] ?? ''), 'busy' => (int)($t['busy'] ?? 1) === 1];
}

/** Um evento com hora, ainda por fazer? */
function gcal_task_live(?array $t): bool {
    return $t && $t['due_date'] && $t['due_time'] && $t['status'] !== 'done';
}

/**
 * O evento do Meet de uma tarefa: numa reunião (§31.6), o texto que os convidados veem (código e nome do negócio no
 * título, em inglês se o contacto o tiver, sem links internos); nos outros eventos com hora, o de sempre.
 */
function gcal_meet_event(PDO $pdo, array $t, array $ext): array {
    if (($t['kind'] ?? '') !== 'meeting') return gcal_task_event($t);
    require_once __DIR__ . '/task_invites.php';
    $ev = tinv_event($t, tinv_task_lang($pdo, $t, $ext));
    $ev['location'] = '';
    return $ev;
}

/** Os convidados externos de uma tarefa: os de «Convidar» (§31.6) ou, em tarefas antigas, os do evento já criado. */
function gcal_task_external(PDO $pdo, array $t, ?array $link, array $people): array {
    if (db_has_column($pdo, 'tasks', 'invitees') && $t['invitees'] !== null) {
        require_once __DIR__ . '/task_invites.php';
        return array_values(array_diff(tinv_of($t), array_values($people)));
    }
    if (!$link) return [];
    $old = json_decode((string)$link['guests'], true) ?: [];
    return array_values(array_diff((array)($old['emails'] ?? []), array_values($people)));
}

/** A conta que organiza o Meet: a da topper. por defeito (§31.6); «me» = o Google Calendar de quem marca. */
function gcal_meet_account(PDO $pdo, int $by, string $organizer = ''): string {
    $mine = gcal_conn($pdo, $by) ? 'user:' . $by : '';
    if ($organizer === 'me' && $mine !== '') return $mine;
    if (gcal_topper()) return 'topper';
    return $mine;
}

/**
 * Cria (ou atualiza) o Meet de uma tarefa com hora: convida as pessoas da plataforma (responsável e participantes,
 * com o email de trabalho) e os convidados externos ($external; null = os guardados na tarefa). Organizador: a conta
 * da topper. por defeito, ou o Google Calendar de quem marca ($organizer 'me'); depois de criado, fica o mesmo.
 * Da primeira vez, o link vai para a tarefa, o histórico, o negócio e o chat dos participantes. Devolve a linha de
 * calendar_links.
 */
function gcal_meet_for_task(PDO $pdo, int $taskId, ?array $external, int $by, string $organizer = ''): array {
    $t = gcal_task($pdo, $taskId);
    if (!gcal_task_live($t)) throw new RuntimeException('O Meet precisa de um evento com dia e hora (e por fazer).');
    $existing = gcal_link($pdo, 'task', $taskId, 'meet');
    $account = $existing ? (string)$existing['account'] : gcal_meet_account($pdo, $by, $organizer);   // o organizador fica o mesmo
    if ($account === '') throw new RuntimeException('A conta da topper. não está ligada (Definições › Integrações) e não tem o seu Google Calendar ligado: cole o link da chamada à mão.');
    $people = gcal_task_people($pdo, $t);
    $st = $pdo->prepare('SELECT email, name FROM users WHERE id = ?');
    $st->execute([$by]);
    $me = $st->fetch() ?: ['email' => '', 'name' => ''];
    if ($external !== null) {
        $ext = [];
        foreach ($external as $e) { $e = strtolower(trim((string)$e)); if (filter_var($e, FILTER_VALIDATE_EMAIL)) $ext[] = $e; }
        $ext = array_values(array_unique($ext));
        if (db_has_column($pdo, 'tasks', 'invitees')) {
            $pdo->prepare('UPDATE tasks SET invitees = ? WHERE id = ?')->execute([json_encode($ext), $taskId]);
            $t['invitees'] = json_encode($ext);
        }
    } else {
        $ext = gcal_task_external($pdo, $t, $existing, $people);
    }
    $ext = array_values(array_diff($ext, array_values($people)));
    $self = $account === 'topper' ? [strtolower((string)$me['email'])] : [];
    if ($existing && $account === 'topper' && $existing['created_by']) {   // quem marcou continua convidado
        $st->execute([(int)$existing['created_by']]);
        $self = [strtolower((string)($st->fetch()['email'] ?? ''))];
    }
    $guests = array_values(array_unique(array_filter(array_merge(array_values($people), $self, $ext))));
    $ev = gcal_meet_event($pdo, $t, $ext) + ['attendees' => $guests, 'meet' => true];
    $l = gcal_upsert($pdo, 'task', $taskId, 'meet', $account, $ev, $by);
    $url = (string)$l['meet_url'];
    if ($url !== '' && db_has_column($pdo, 'tasks', 'organizer')) $pdo->prepare('UPDATE tasks SET organizer = ? WHERE id = ?')->execute([$account === 'topper' ? 'topper' : 'me', $taskId]);
    if ($url !== '' && !$existing) {
        $pdo->prepare("UPDATE tasks SET call_link = ?, platform = CASE WHEN kind = 'meeting' THEN 'meet' ELSE platform END, updated_at = ? WHERE id = ?")->execute([$url, date('Y-m-d H:i:s'), $taskId]);
        if (function_exists('task_log')) task_log($pdo, $taskId, 'meet', 'Google Meet criado (' . ($account === 'topper' ? 'conta da topper.' : 'Google Calendar de ' . $me['name']) . ') · ' . $url . ($ext ? ' · convidados: ' . implode(', ', $ext) : ''));
        if ($t['deal_id'] && function_exists('deal_log')) deal_log($pdo, (int)$t['deal_id'], 'meeting', ['note' => 'Google Meet: ' . $t['title'] . ' · ' . date('d/m H:i', strtotime($t['due_date'] . ' ' . $t['due_time'])) . ' · ' . $url]);
        if (function_exists('team_chat_send')) {
            foreach (array_keys($people) as $uid) {
                if ((int)$uid === $by) continue;
                try { team_chat_send($pdo, $by, (int)$uid, '📹 Google Meet: ' . $t['title'] . ' · ' . date('d/m H:i', strtotime($t['due_date'] . ' ' . $t['due_time'])) . ' · ' . $url); } catch (Throwable $e) { /* o chat é um extra */ }
            }
        }
    } elseif ($existing && function_exists('task_log')) {
        $was = (array)((json_decode((string)$existing['guests'], true) ?: [])['emails'] ?? []);
        $in = array_diff($guests, $was); $out = array_diff($was, $guests);
        if ($in || $out) task_log($pdo, $taskId, 'meet', 'Convidados do Meet' . ($in ? ' · entram: ' . implode(', ', $in) : '') . ($out ? ' · saem (convite cancelado): ' . implode(', ', $out) : ''));
    }
    gcal_task_sync($pdo, $taskId);   // quem já é convidado não leva cópia no seu calendário
    return $l;
}

/**
 * Põe o Google em linha com a tarefa: o Meet (hora, título, convidados) e o espelho no calendário de cada pessoa
 * ligada (as suas reuniões, gravações, visitas e prazos com hora). Tarefa feita, sem hora ou apagada → retira.
 * Uma reunião que deixou de ser no Google Meet (Discord, presencial) cancela o Meet.
 */
function gcal_task_sync(PDO $pdo, int $taskId): void {
    if (!gcal_ready($pdo) || !google_client()) return;
    $t = gcal_task($pdo, $taskId);
    $links = gcal_links($pdo, 'task', $taskId);
    if (!gcal_task_live($t)) {
        foreach ($links as $l) {
            // feita: o Meet fica (já aconteceu); apagada ou sem hora: sai tudo
            if ($t && $t['status'] === 'done' && $l['kind'] === 'meet') continue;
            try { gcal_delete_link($pdo, $l); } catch (Throwable $e) { error_log('[topper] gcal_task_sync: ' . $e->getMessage()); }
        }
        return;
    }
    $ev = gcal_task_event($t);
    $people = gcal_task_people($pdo, $t);
    $meet = null;
    foreach ($links as $l) if ($l['kind'] === 'meet') $meet = $l;
    if ($meet && $t['kind'] === 'meeting' && (string)$t['platform'] !== 'meet' && $meet['ref_type'] === 'task') {
        try {
            gcal_delete_link($pdo, $meet);
            if ((string)$t['call_link'] === (string)$meet['meet_url']) $pdo->prepare("UPDATE tasks SET call_link = '' WHERE id = ?")->execute([$taskId]);
            if (function_exists('task_log')) task_log($pdo, $taskId, 'meet', 'Google Meet cancelado (a reunião deixou de ser no Meet)');
            $t['call_link'] = '';
        } catch (Throwable $e) { error_log('[topper] gcal meet off: ' . $e->getMessage()); }
        $meet = null;
    }
    $invited = [];
    if ($meet) {
        $ext = gcal_task_external($pdo, $t, $meet, $people);
        $guests = array_values(array_unique(array_merge(array_values($people), $ext)));
        if ($meet['account'] === 'topper' && $meet['created_by']) {   // quem marcou pela conta da topper. continua convidado
            $st = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $st->execute([(int)$meet['created_by']]);
            if (($m = strtolower((string)$st->fetchColumn())) !== '') $guests = array_values(array_unique(array_merge($guests, [$m])));
        }
        try { gcal_upsert($pdo, 'task', $taskId, 'meet', (string)$meet['account'], gcal_meet_event($pdo, $t, $ext) + ['attendees' => $guests, 'meet' => true]); }
        catch (Throwable $e) { error_log('[topper] gcal meet update: ' . $e->getMessage()); }
        $invited = $guests;
        if (str_starts_with((string)$meet['account'], 'user:')) $invited[] = '#' . substr((string)$meet['account'], 5);
    }
    $want = [];
    foreach ($people as $uid => $email) {
        $c = gcal_conn($pdo, (int)$uid);
        if (!$c || !(int)$c['sync_on']) continue;
        if (in_array($email, $invited, true) || in_array(strtolower((string)$c['google_email']), $invited, true) || in_array('#' . $uid, $invited, true)) continue;
        $want['user:' . $uid] = true;
        try {
            $mev = $ev;
            if ((string)$t['call_link'] !== '') $mev['description'] = 'Chamada: ' . $t['call_link'] . "\n\n" . $mev['description'];
            gcal_upsert($pdo, 'task', $taskId, 'mirror', 'user:' . $uid, $mev);
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE user_google SET last_error = ? WHERE user_id = ?')->execute([mb_substr($e->getMessage(), 0, 300), (int)$uid]);
        }
    }
    foreach ($links as $l) {
        if ($l['kind'] === 'mirror' && !isset($want[$l['account']])) {
            try { gcal_delete_link($pdo, $l); } catch (Throwable $e) { error_log('[topper] gcal mirror off: ' . $e->getMessage()); }
        }
    }
}

// ── reuniões da Equipa (pedidos de reunião, §17.7) ──────────

/**
 * O Meet de um pedido de reunião aceite: organizador = quem pediu, se tiver o calendário ligado; senão a conta
 * da topper. Devolve o link.
 */
function gcal_meet_for_meeting(PDO $pdo, array $m): string {
    $req = (int)$m['requester_id'];
    $account = gcal_conn($pdo, $req) ? 'user:' . $req : (gcal_topper() ? 'topper' : '');
    if ($account === '') throw new RuntimeException('A conta Google não está ligada. Cole o link à mão.');
    $ev = ['summary' => (string)$m['subject'], 'description' => ($m['deal_code'] ?? '') ? 'Negócio: ' . $m['deal_code'] . ' · ' . $m['deal_name'] : '',
           'start' => substr((string)$m['starts_at'], 0, 16), 'minutes' => (int)$m['duration_min'], 'busy' => true, 'meet' => true,
           'attendees' => [(string)$m['requester_email'], (string)$m['invitee_email']]];
    $l = gcal_upsert($pdo, 'meeting', (int)$m['id'], 'meet', $account, $ev, $req);
    if ((string)$l['meet_url'] === '') throw new RuntimeException('O Google não criou a chamada. Cole o link à mão.');
    return (string)$l['meet_url'];
}

/** As tarefas que nasceram de um pedido de reunião partilham o mesmo evento (mudar a hora numa atualiza o Google). */
function gcal_link_alias(PDO $pdo, string $fromType, int $fromId, array $taskIds): void {
    $l = gcal_link($pdo, $fromType, $fromId, 'meet');
    if (!$l) return;
    foreach (array_unique(array_filter(array_map('intval', $taskIds))) as $tid) {
        if (gcal_link($pdo, 'task', $tid, 'meet')) continue;
        $pdo->prepare('INSERT INTO calendar_links (ref_type, ref_id, kind, account, calendar_id, event_id, etag, meet_url, html_link, guests, status, synced_at, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(['task', $tid, 'meet', $l['account'], $l['calendar_id'], $l['event_id'], $l['etag'], $l['meet_url'], $l['html_link'], $l['guests'], 'active', $l['synced_at'], $l['created_by'], date('Y-m-d H:i:s')]);
        if ((string)$l['meet_url'] !== '') $pdo->prepare("UPDATE tasks SET call_link = ? WHERE id = ? AND call_link = ''")->execute([$l['meet_url'], $tid]);
    }
}

// ── ocupado/livre ──────────────────────────────────────────

/** Pergunta à Google os períodos ocupados de uma pessoa (próximos dias) e guarda em cache. */
function gcal_busy_refresh(PDO $pdo, int $uid): array {
    $c = gcal_conn($pdo, $uid);
    if (!$c) return [];
    $from = new DateTimeImmutable('today', new DateTimeZone(GCAL_TZ));
    $to = $from->modify('+' . GCAL_BUSY_DAYS . ' days');
    [$code, $j] = gcal_api($pdo, 'user:' . $uid, 'POST', '/freeBusy', [], ['timeMin' => $from->format(DATE_RFC3339), 'timeMax' => $to->format(DATE_RFC3339), 'timeZone' => GCAL_TZ, 'items' => [['id' => $c['calendar_id'] ?: 'primary']]]);
    if ($code >= 300) throw new RuntimeException('A Google não respondeu ao ocupado/livre (' . $code . ').');
    $busy = [];
    foreach ((array)($j['calendars'] ?? []) as $cal) foreach ((array)($cal['busy'] ?? []) as $b) {
        $a = strtotime((string)$b['start']); $z = strtotime((string)$b['end']);
        if ($a && $z && $z > $a) $busy[] = [$a, $z];
    }
    // o que a própria plataforma lá pôs (espelhos e Meet) não conta duas vezes: já está na agenda
    $own = [];
    $st = $pdo->prepare("SELECT t.due_date, t.due_time, t.duration_min FROM calendar_links l JOIN tasks t ON t.id = l.ref_id WHERE l.ref_type = 'task' AND l.account = ? AND t.due_time IS NOT NULL");
    $st->execute(['user:' . $uid]);
    foreach ($st->fetchAll() as $r) { $a = strtotime($r['due_date'] . ' ' . $r['due_time']); $own[] = [$a, $a + 60 * (int)($r['duration_min'] ?: 60)]; }
    $busy = array_values(array_filter($busy, fn($b) => !in_array($b, $own, false)));
    $pdo->prepare("UPDATE user_google SET busy_cache = ?, busy_at = ?, last_error = '' WHERE user_id = ?")->execute([json_encode($busy), date('Y-m-d H:i:s'), $uid]);
    return $busy;
}

/** Os blocos ocupados do Google entre duas datas (unix), da cache (renova se estiver velha e $fresh). */
function gcal_busy_blocks(PDO $pdo, int $uid, int $from, int $to, bool $fresh = false): array {
    $c = gcal_conn($pdo, $uid);
    if (!$c) return [];
    $busy = json_decode((string)$c['busy_cache'], true);
    if ($fresh && (!is_array($busy) || !$c['busy_at'] || strtotime((string)$c['busy_at']) < time() - GCAL_BUSY_TTL)) {
        try { $busy = gcal_busy_refresh($pdo, $uid); } catch (Throwable $e) { error_log('[topper] freeBusy: ' . $e->getMessage()); }
    }
    return array_values(array_filter((array)$busy, fn($b) => is_array($b) && $b[1] > $from && $b[0] < $to));
}

// ── a ligação de cada pessoa (Perfil) ──────────────────────

function gcal_prefs_save(PDO $pdo, int $uid, bool $sync, bool $busy): void {
    if (!gcal_conn($pdo, $uid)) throw new RuntimeException('O Google Calendar não está ligado.');
    $pdo->prepare('UPDATE user_google SET sync_on = ?, show_busy = ? WHERE user_id = ?')->execute([$sync ? 1 : 0, $busy ? 1 : 0, $uid]);
    if (!$sync) foreach ($pdo->query("SELECT * FROM calendar_links WHERE kind = 'mirror' AND account = " . $pdo->quote('user:' . $uid))->fetchAll() as $l) {
        try { gcal_delete_link($pdo, $l); } catch (Throwable $e) { error_log('[topper] gcal prefs: ' . $e->getMessage()); }
    }
    if ($sync) $pdo->prepare('UPDATE user_google SET last_sync_at = NULL WHERE user_id = ?')->execute([$uid]);   // o cron volta a pôr tudo
}

/** Desligar: retira os espelhos, para as notificações, revoga o token na Google e apaga a ligação. */
function gcal_disconnect(PDO $pdo, int $uid): void {
    $c = gcal_conn($pdo, $uid);
    if (!$c) return;
    foreach ($pdo->query("SELECT * FROM calendar_links WHERE kind = 'mirror' AND account = " . $pdo->quote('user:' . $uid))->fetchAll() as $l) {
        try { gcal_delete_link($pdo, $l); } catch (Throwable $e) { $pdo->prepare('DELETE FROM calendar_links WHERE id = ?')->execute([(int)$l['id']]); }
    }
    try { gcal_unwatch($pdo, $c); } catch (Throwable $e) { /* o canal expira sozinho */ }
    $raw = secret_open((string)$c['token_enc']);
    $rt = $raw !== null ? (string)(json_decode($raw, true)['refresh_token'] ?? '') : '';
    if ($rt !== '') google_revoke($rt);
    // os Meet que organizou continuam no Google (os convidados já os têm): só deixam de ser atualizados daqui
    $pdo->prepare("DELETE FROM calendar_links WHERE kind = 'meet' AND account = ?")->execute(['user:' . $uid]);
    $pdo->prepare('DELETE FROM user_google WHERE user_id = ?')->execute([$uid]);
}

// ── fase 2: notificações push e syncToken ──────────────────

/** Abre (ou renova) o canal de notificações do calendário de uma pessoa. */
function gcal_watch(PDO $pdo, int $uid): void {
    $c = gcal_conn($pdo, $uid);
    if (!$c) return;
    $id = bin2hex(random_bytes(16)); $tok = bin2hex(random_bytes(16));
    [$code, $j] = gcal_api($pdo, 'user:' . $uid, 'POST', '/calendars/' . rawurlencode((string)$c['calendar_id'] ?: 'primary') . '/events/watch', [],
                           ['id' => $id, 'type' => 'web_hook', 'address' => app_base_url() . '/admin/gcal_push.php', 'token' => $tok, 'params' => ['ttl' => '604800']]);
    if ($code >= 300) throw new RuntimeException('A Google não abriu o canal de notificações (' . $code . ').');
    if ($c['channel_id'] !== '') { try { gcal_unwatch($pdo, $c); } catch (Throwable $e) { /* expira sozinho */ } }
    $exp = isset($j['expiration']) ? date('Y-m-d H:i:s', (int)((int)$j['expiration'] / 1000)) : date('Y-m-d H:i:s', time() + 6 * 86400);
    $pdo->prepare('UPDATE user_google SET channel_id = ?, channel_token = ?, channel_res = ?, channel_exp = ? WHERE user_id = ?')->execute([$id, $tok, mb_substr((string)($j['resourceId'] ?? ''), 0, 190), $exp, $uid]);
}

function gcal_unwatch(PDO $pdo, array $c): void {
    if ((string)$c['channel_id'] === '') return;
    gcal_api($pdo, 'user:' . (int)$c['user_id'], 'POST', '/channels/stop', [], ['id' => $c['channel_id'], 'resourceId' => $c['channel_res']]);
    $pdo->prepare("UPDATE user_google SET channel_id = '', channel_token = '', channel_res = '', channel_exp = NULL WHERE user_id = ?")->execute([(int)$c['user_id']]);
}

/**
 * Lê as mudanças do calendário de uma pessoa (syncToken). Fase 1: guarda o syncToken e as respostas dos convidados
 * dos Meet que a plataforma criou. Fase 2: a hora e os cancelamentos feitos no Google voltam para a plataforma.
 */
function gcal_pull(PDO $pdo, int $uid): int {
    $c = gcal_conn($pdo, $uid);
    if (!$c) return 0;
    $q = ['maxResults' => 250, 'showDeleted' => 'true', 'singleEvents' => 'true'];
    if ((string)$c['sync_token'] !== '') $q['syncToken'] = $c['sync_token'];
    else $q['timeMin'] = (new DateTimeImmutable('-1 day'))->format(DATE_RFC3339);
    $n = 0; $next = '';
    for ($page = 0; $page < 10; $page++) {
        [$code, $j] = gcal_api($pdo, 'user:' . $uid, 'GET', '/calendars/' . rawurlencode((string)$c['calendar_id'] ?: 'primary') . '/events', $q);
        if ($code === 410) { $pdo->prepare("UPDATE user_google SET sync_token = '' WHERE user_id = ?")->execute([$uid]); return 0; }   // syncToken velho: recomeça
        if ($code >= 300) throw new RuntimeException('A Google não devolveu as mudanças (' . $code . ').');
        foreach ((array)($j['items'] ?? []) as $ev) {
            $st = $pdo->prepare("SELECT id FROM calendar_links WHERE event_id = ? AND kind = 'meet'");
            $st->execute([(string)($ev['id'] ?? '')]);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) continue;
            $resp = [];
            foreach ((array)($ev['attendees'] ?? []) as $a) $resp[] = ['email' => (string)($a['email'] ?? ''), 'status' => (string)($a['responseStatus'] ?? 'needsAction'), 'organizer' => !empty($a['organizer'])];
            $pdo->prepare('UPDATE calendar_links SET responses = ?, synced_at = ? WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->execute([json_encode($resp, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
            $n++;
        }
        if (!empty($j['nextPageToken'])) { $q['pageToken'] = $j['nextPageToken']; continue; }   // as outras opções ficam iguais entre páginas
        $next = (string)($j['nextSyncToken'] ?? '');
        break;
    }
    $pdo->prepare('UPDATE user_google SET sync_token = ?, push_pending = 0 WHERE user_id = ?')->execute([mb_substr($next, 0, 255), $uid]);
    return $n;
}

// ── o cron (de 5 em 5 minutos) ─────────────────────────────

/**
 * Mantém o Google em linha: tarefas mudadas desde a última volta (o Meet e os espelhos), as tarefas apagadas, a
 * primeira sincronização de quem ligou o calendário, a cache do ocupado/livre e (fase 2) os canais de notificações.
 */
function gcal_tick(PDO $pdo, float $seconds = 30.0): string {
    if (!gcal_ready($pdo) || !google_client()) return '';
    $users = $pdo->query('SELECT user_id, last_sync_at, busy_at, channel_exp, push_pending FROM user_google')->fetchAll();
    $hasLinks = (int)$pdo->query('SELECT COUNT(*) FROM calendar_links')->fetchColumn();
    if (!$users && !$hasLinks) return '';
    require_once __DIR__ . '/ai.php';
    $deadline = microtime(true) + $seconds;
    $since = (string)ai_setting_get($pdo, 'gcal_tick_at') ?: date('Y-m-d H:i:s', time() - 3600);
    $start = date('Y-m-d H:i:s');
    $done = 0; $err = 0;
    $sync = function (int $id) use ($pdo, &$done, &$err) { try { gcal_task_sync($pdo, $id); $done++; } catch (Throwable $e) { $err++; error_log('[topper] gcal_tick: ' . $e->getMessage()); } };
    // 1. tarefas que mudaram (com hora, ou que já têm evento)
    $st = $pdo->prepare("SELECT id FROM tasks WHERE updated_at >= ? AND (due_time IS NOT NULL OR id IN (SELECT ref_id FROM calendar_links WHERE ref_type = 'task')) ORDER BY updated_at LIMIT 300");
    $st->execute([date('Y-m-d H:i:s', strtotime($since) - 120)]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { if (microtime(true) > $deadline) break; $sync((int)$id); }
    // 2. tarefas apagadas
    foreach ($pdo->query("SELECT DISTINCT l.ref_id FROM calendar_links l LEFT JOIN tasks t ON t.id = l.ref_id WHERE l.ref_type = 'task' AND t.id IS NULL LIMIT 100")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (microtime(true) > $deadline) break;
        $sync((int)$id);
    }
    // 3. quem ligou agora (ou voltou a ligar o espelho): os próximos dias de uma vez
    foreach ($users as $u) {
        if (microtime(true) > $deadline) break;
        $uid = (int)$u['user_id'];
        if ($u['last_sync_at'] === null) {
            $q = $pdo->prepare("SELECT t.id FROM tasks t LEFT JOIN users u ON u.team_member_id = t.member_id WHERE t.due_time IS NOT NULL AND t.status <> 'done' AND t.due_date >= ? AND t.due_date <= ?
                                   AND (u.id = ? OR CONCAT(',', t.participants, ',') LIKE ?) LIMIT 200");
            $q->execute([date('Y-m-d'), date('Y-m-d', strtotime('+' . GCAL_MIRROR_DAYS . ' days')), $uid, '%,' . $uid . ',%']);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) { if (microtime(true) > $deadline) break 2; $sync((int)$id); }
        }
        $pdo->prepare('UPDATE user_google SET last_sync_at = ? WHERE user_id = ?')->execute([date('Y-m-d H:i:s'), $uid]);
        // 4. ocupado/livre
        if (!$u['busy_at'] || strtotime((string)$u['busy_at']) < time() - GCAL_BUSY_TTL) { try { gcal_busy_refresh($pdo, $uid); } catch (Throwable $e) { $err++; } }
        // 5. fase 2: canais de notificações (só se ligado em Definições) e as mudanças pendentes
        if ((string)setting($pdo, 'gcal_push', '0') === '1') {
            try {
                if (!$u['channel_exp'] || strtotime((string)$u['channel_exp']) < time() + 86400) gcal_watch($pdo, $uid);
                if ((int)$u['push_pending']) gcal_pull($pdo, $uid);
            } catch (Throwable $e) { $err++; }
        }
    }
    ai_setting_set($pdo, 'gcal_tick_at', $start);
    return 'Google Calendar: ' . count($users) . ' ligados · ' . $done . ' eventos em linha' . ($err ? ' · ' . $err . ' erros' : '');
}
