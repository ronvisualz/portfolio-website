<?php
/**
 * topper. admin — leads (MySQL), versão 2 (docs/ESPECIFICACAO-PLATAFORMA.md §12)
 *
 * Uma lead é um registo de `deals` com phase = 'lead'. Ao ser convertida
 * recebe o ID do negócio e passa ao Pipeline, sem nunca se duplicar.
 *
 * Na v2 a lead tem Empresa e/ou Pessoa de contacto (as duas ficam também
 * na aba Contactos), telefones, email e redes sociais separados, e um dos
 * três estados — Novo, Sem resposta, Follow-Up — cada um com um prazo em
 * horas seguidas (24/7, sem pausas) que recomeça a cada mudança de estado,
 * chamada não atendida ou follow-up registado. O prazo só acaba quando a
 * lead é arquivada ou convertida.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const LEAD_TYPES_LIST  = ['Produtora', 'Agência de Marketing', 'Empresa Particular', 'Realizador', 'VFX company'];
const LEAD_SERVICES_LIST = ['VFX / 3D', 'Marketing Buddy', 'Ambos'];
const LEAD_PRIORITY    = ['alta' => 'Alta', 'media' => 'Média', 'baixa' => 'Baixa'];
const LEAD_STATE       = ['novo' => 'Novo', 'sem_resposta' => 'Sem resposta', 'follow_up' => 'Follow-Up'];
const LEAD_ORIGIN      = ['manual' => 'Manual', 'site' => 'Site', 'anuncio' => 'Anúncio'];
const ARCHIVE_REASONS  = [
    'sem_interesse' => 'Sem interesse',
    'sem_resposta'  => 'Sem resposta',
    'fora_perfil'   => 'Fora de perfil',
    'spam'          => 'Spam',
    'outro'         => 'Outro',
    'perdido_migracao' => 'Perdido (migração)',
];

/** Prazo por estado, em horas (definições globais, só superadmin e admins mudam). */
const LEAD_DEADLINE_DEFAULTS = ['novo' => 24, 'sem_resposta' => 24, 'follow_up' => 48];

/** Tipos de ação (Nova ação, Foco): nome e ícone. */
const LEAD_TASK_KINDS = [
    'call'     => ['Chamada', 'phone'],
    'meeting'  => ['Reunião', 'meeting'],
    'task'     => ['Tarefa', 'checklist'],
    'document' => ['Documento', 'note'],
    'email'    => ['Email', 'mail'],
    'lunch'    => ['Almoço', 'utensils'],
    'dinner'   => ['Jantar', 'wine'],
    'event'    => ['Evento', 'calendar'],
];

const TASK_REPEAT = ['' => 'Não repetir', 'daily' => 'Todos os dias', 'weekly' => 'Todas as semanas', 'monthly' => 'Todos os meses'];

/** Redes sociais reconhecidas pelo endereço (o resto é «site»). */
const SOCIAL_HOSTS = [
    'instagram.com' => 'Instagram', 'linkedin.com' => 'LinkedIn', 'facebook.com' => 'Facebook', 'fb.com' => 'Facebook',
    'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube', 'vimeo.com' => 'Vimeo', 'tiktok.com' => 'TikTok',
    'twitter.com' => 'X', 'x.com' => 'X', 'behance.net' => 'Behance',
];

/** «Agora» escrito pelo PHP (os prazos comparam sempre com time(), no mesmo relógio). */
function now_php(): string {
    return date('Y-m-d H:i:s');
}

/** Um instante em hora de Lisboa (o que as pessoas leem e escrevem nas tarefas). */
function lisbon_fmt(int $ts, string $f = 'Y-m-d H:i'): string {
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format($f);
}

/** «aaaa-mm-dd hh:mm» em hora de Lisboa → instante. */
function lisbon_ts(string $local): int {
    return (new DateTimeImmutable($local, new DateTimeZone('Europe/Lisbon')))->getTimestamp();
}

/** Uma definição (sem depender do bootstrap: o formulário do site também cria leads). */
function lead_setting(PDO $pdo, string $key, $default) {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        $cache[$key] = $v === false ? null : $v;
    }
    return $cache[$key] ?? $default;
}

/** Só os dígitos, e só os últimos 9 (ignora o +351 e espaços). */
function phone_key(string $p): string {
    $d = preg_replace('/\D+/', '', $p);
    return strlen($d) > 9 ? substr($d, -9) : $d;
}

function email_key(string $e): string {
    return strtolower(trim($e));
}

/** Gestores da fila «Por atribuir»: admins e chefes de equipa. */
function sees_unassigned(array $u): bool {
    return role_is_admin($u['role']) || $u['role'] === 'lead';
}

function lead_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM deals WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Posso ver / mexer neste registo de lead? (âmbito, ou fila sem dono para gestores) */
function lead_in_reach(PDO $pdo, array $lead, array $u): bool {
    if ($lead['owner_id'] === null) return sees_unassigned($u);
    return in_my_scope($pdo, 'leads', (int)$lead['owner_id']);
}

/** Utilizadores ativos a quem $actor pode atribuir. */
function assignable_users(PDO $pdo, array $actor): array {
    $all = $pdo->query('SELECT id, name, role, department_id, is_active, deactivated_at FROM users
                         WHERE is_active = 1 ORDER BY name')->fetchAll();
    return array_values(array_filter($all, fn($t) => can_assign_to($actor, $t)));
}

// ── contactos: telefones, email, redes ─────────────────────

/** Os telefones de um texto: «número (nota)», um por entrada. */
function lead_phones(string $s): array {
    $out = [];
    if (preg_match_all('/(\+?\d[\d\s.\-]{5,}\d)(\s*\([^)]*\))?/u', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $p = trim(preg_replace('/\s+/', ' ', $x[1] . ($x[2] ?? '')));
            if (strlen(preg_replace('/\D/', '', $x[1])) >= 7) $out[] = $p;
        }
    }
    return $out;
}

/** O primeiro número de um campo de telefones, só dígitos e «+» (para tel: e WhatsApp). */
function lead_phone_dial(string $phones): string {
    $p = lead_phones($phones)[0] ?? '';
    $p = preg_replace('/\s*\(.*$/', '', $p);
    return preg_replace('/[^\d+]/', '', $p);
}

/** Um endereço reconhecível como link (com ou sem http). */
function lead_is_link(string $t): bool {
    return (bool)preg_match('~^(https?://|www\.)\S+$|^[a-z0-9-]+(\.[a-z0-9-]+)+(/\S*)?$~i', $t);
}

function lead_link_url(string $t): string {
    return preg_match('~^https?://~i', $t) ? $t : 'https://' . $t;
}

/** Que rede é este link? ['Instagram', 'instagram'] … ou ['Site', 'globe']. */
function social_kind(string $url): array {
    $host = strtolower((string)parse_url(lead_link_url($url), PHP_URL_HOST));
    $host = preg_replace('/^(www|m|mt|pt|uk)\./', '', $host);
    foreach (SOCIAL_HOSTS as $h => $label) {
        if ($host === $h || str_ends_with($host, '.' . $h)) return [$label, strtolower($label)];
    }
    return ['Site', 'globe'];
}

/**
 * Separa contactos misturados: devolve [telefones[], email, links[], notas[]].
 * Números → telefone · endereços com @ → email · links → redes sociais · o que
 * não for claro → notas. Serve a migração e quem cola tudo num campo só.
 */
function lead_parse_contacts(string $phone, string $email, string $links): array {
    $phones = []; $emails = []; $urls = []; $notes = [];

    foreach (lead_phones($phone) as $p) $phones[] = $p;
    $rest = trim(preg_replace('/(\+?\d[\d\s.\-]{5,}\d)(\s*\([^)]*\))?/u', '', $phone), " \t/,;|");
    if ($rest !== '') $notes[] = 'Telefone: ' . $rest;

    $emailRe = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';
    $tokens = array_filter(array_map('trim', preg_split('/\s*[|\n]\s*/', $links)), 'strlen');
    preg_match_all($emailRe, $email, $em);
    foreach ($em[0] as $e) $emails[] = $e;
    $note = trim(preg_replace($emailRe, '', $email), " \t,;|");
    if ($note !== '' && $em[0]) $notes[] = 'Email ' . $em[0][0] . ': ' . trim($note, '() ');
    elseif ($note !== '') $notes[] = 'Email: ' . $note;

    foreach ($tokens as $t) {
        if (lead_is_link($t)) { $urls[] = $t; continue; }
        if (preg_match_all($emailRe, $t, $m2)) {
            foreach ($m2[0] as $e) $emails[] = $e;
            $left = trim(preg_replace($emailRe, '', $t), " \t,;()");
            if ($left !== '') $notes[] = 'Email ' . $m2[0][0] . ': ' . $left;
            continue;
        }
        $ph = lead_phones($t);
        if ($ph) { foreach ($ph as $p) $phones[] = $p; continue; }
        $notes[] = $t;
    }

    // Sem repetidos (telefones pelo número, emails sem maiúsculas).
    $seen = []; $phones = array_values(array_filter($phones, function ($p) use (&$seen) {
        $k = phone_key($p); if (isset($seen[$k])) return false; $seen[$k] = 1; return true; }));
    $emails = array_values(array_unique(array_map('strtolower', $emails)));
    $email = $emails[0] ?? '';
    if (count($emails) > 1) $notes[] = 'Outros emails: ' . implode(', ', array_slice($emails, 1));
    return [$phones, $email, array_values(array_unique($urls)), $notes];
}

/**
 * Registos e contactos com o mesmo email ou telefone (qualquer um dos telefones).
 * Devolve ['deals' => [...], 'contacts' => [...]].
 */
function find_duplicates(PDO $pdo, string $email, string $phone, int $exceptId = 0): array {
    $ek = email_key($email);
    $pks = array_values(array_filter(array_map('phone_key', lead_phones($phone) ?: [$phone]), fn($k) => strlen($k) >= 6));
    $out = ['deals' => [], 'contacts' => []];
    if ($ek === '' && !$pks) return $out;

    foreach (['deals' => 'SELECT id, code, name, company, person, phase, email, phone, owner_id FROM deals WHERE id <> ?',
              'contacts' => 'SELECT id, kind, name, email, phone, company_id FROM contacts WHERE id <> ?'] as $k => $sql) {
        $st = $pdo->prepare($sql . ' AND (LOWER(email) = ? OR phone <> \'\')');
        $st->execute([$k === 'deals' ? $exceptId : 0, $ek === '' ? '-' : $ek]);
        foreach ($st->fetchAll() as $r) {
            $hit = $ek !== '' && email_key((string)$r['email']) === $ek;
            if (!$hit && $pks) {
                $theirs = array_map('phone_key', lead_phones((string)$r['phone']) ?: [(string)$r['phone']]);
                $hit = (bool)array_intersect($pks, $theirs);
            }
            if ($hit) $out[$k][] = $r;
        }
    }
    return $out;
}

/** Os campos de lead vindos do formulário (sem estado nem dono). */
function lead_input(array $src): array {
    $types = [];
    foreach ((array)($src['types'] ?? []) as $t) {
        $t = trim((string)$t);
        if (in_array($t, LEAD_TYPES_LIST, true) && !in_array($t, $types, true)) $types[] = $t;
    }
    $txt = static fn(string $k, int $n) => mb_substr(trim((string)($src[$k] ?? '')), 0, $n);
    [$phones, $email, $links, $extra] = lead_parse_contacts($txt('phone', 255), $txt('email', 190), (string)($src['links'] ?? ''));
    if ($email === '' && $txt('email', 190) !== '') $email = $txt('email', 190);
    $service = (string)($src['service'] ?? '');
    $prio    = (string)($src['priority'] ?? 'media');
    $origin  = (string)($src['origin'] ?? 'manual');
    $notes   = $txt('notes', 8000);
    if ($extra) $notes = trim($notes . "\n" . implode("\n", $extra));
    return [
        'company'  => $txt('company', 160),
        'person'   => $txt('person', 160),
        'types'    => implode(', ', $types),
        'links'    => implode("\n", $links),
        'phone'    => mb_substr(implode(' | ', $phones), 0, 255),
        'email'    => mb_substr($email, 0, 190),
        'service'  => in_array($service, LEAD_SERVICES_LIST, true) ? $service : '',
        'priority' => isset(LEAD_PRIORITY[$prio]) ? $prio : 'media',
        'detail'   => $txt('detail', 4000),
        'why'      => $txt('why', 4000),
        'notes'    => mb_substr($notes, 0, 8000),
        'origin'   => isset(LEAD_ORIGIN[$origin]) ? $origin : 'manual',
        'campaign' => $txt('campaign', 160),
    ];
}

/** O nome que se mostra: a pessoa, se houver; senão a empresa. A empresa vai em pequeno ao lado. */
function lead_display(array $l): array {
    $person  = trim((string)($l['person'] ?? ''));
    $company = trim((string)($l['company'] ?? ''));
    if ($person !== '') return [$person, $company];
    return [$company !== '' ? $company : (string)$l['name'], ''];
}

function lead_display_name(array $l): string {
    return lead_display($l)[0];
}

// ── empresas e pessoas na aba Contactos ────────────────────

/** A empresa com este nome (sem distinguir maiúsculas), ou criada agora. */
function lead_company_contact(PDO $pdo, string $name, array $extra = [], ?int $by = null): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $st = $pdo->prepare("SELECT id FROM contacts WHERE kind = 'company' AND LOWER(TRIM(name)) = ? ORDER BY id LIMIT 1");
    $st->execute([mb_strtolower($name)]);
    if ($id = $st->fetchColumn()) return (int)$id;
    // A mesma entidade com outro nome: o mesmo email ou telefone.
    $dups = find_duplicates($pdo, (string)($extra['email'] ?? ''), (string)($extra['phone'] ?? ''));
    foreach ($dups['contacts'] as $c) if ($c['kind'] === 'company') return (int)$c['id'];
    $pdo->prepare('INSERT INTO contacts (kind, name, email, phone, website, notes, created_by) VALUES (\'company\', ?, ?, ?, ?, \'\', ?)')
        ->execute([mb_substr($name, 0, 160), mb_substr((string)($extra['email'] ?? ''), 0, 190),
                   mb_substr(lead_phones((string)($extra['phone'] ?? ''))[0] ?? '', 0, 40),
                   mb_substr((string)($extra['website'] ?? ''), 0, 255), $by]);
    $id = (int)$pdo->lastInsertId();
    lead_contact_logged($pdo, $id, 'Empresa', $by);
    return $id;
}

/**
 * Contactos criados a partir de uma lead ou de uma pessoa (§13.1): o site passa para «Site e
 * redes» (links) e fica no histórico do contacto. Antes do passo 15 da base de dados, nada.
 */
function lead_contact_logged(PDO $pdo, int $id, string $what, ?int $by): void {
    if (!db_has_table($pdo, 'contact_history')) return;
    $pdo->prepare("UPDATE contacts SET links = website WHERE id = ? AND website <> '' AND (links IS NULL OR links = '')")->execute([$id]);
    $pdo->prepare("INSERT INTO contact_history (contact_id, event, note, user_id) VALUES (?, 'c_create', ?, ?)")
        ->execute([$id, $what . ' · criada a partir de uma lead ou de um contacto', $by]);
}

/** A pessoa de contacto: a escolhida ($useId), a que já tem este email, ou criada agora. */
function lead_person_contact(PDO $pdo, string $name, ?int $companyId, array $extra = [], ?int $useId = null, ?int $by = null): ?int {
    $name = trim($name);
    if ($useId) {
        $st = $pdo->prepare("SELECT id, company_id FROM contacts WHERE id = ? AND kind = 'person'");
        $st->execute([$useId]);
        if ($c = $st->fetch()) {
            if ($companyId && !$c['company_id']) $pdo->prepare('UPDATE contacts SET company_id = ? WHERE id = ?')->execute([$companyId, $useId]);
            return (int)$c['id'];
        }
    }
    if ($name === '') return null;
    $email = email_key((string)($extra['email'] ?? ''));
    if ($email !== '') {
        $st = $pdo->prepare("SELECT id FROM contacts WHERE kind = 'person' AND LOWER(email) = ? ORDER BY id LIMIT 1");
        $st->execute([$email]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    $st = $pdo->prepare("SELECT id FROM contacts WHERE kind = 'person' AND LOWER(TRIM(name)) = ? AND company_id " . ($companyId ? '= ?' : 'IS NULL') . ' LIMIT 1');
    $st->execute($companyId ? [mb_strtolower($name), $companyId] : [mb_strtolower($name)]);
    if ($id = $st->fetchColumn()) return (int)$id;
    $pdo->prepare('INSERT INTO contacts (kind, name, company_id, email, phone, website, notes, created_by) VALUES (\'person\', ?, ?, ?, ?, ?, \'\', ?)')
        ->execute([mb_substr($name, 0, 160), $companyId, mb_substr((string)($extra['email'] ?? ''), 0, 190),
                   mb_substr(lead_phones((string)($extra['phone'] ?? ''))[0] ?? '', 0, 40),
                   mb_substr((string)($extra['website'] ?? ''), 0, 255), $by]);
    $id = (int)$pdo->lastInsertId();
    lead_contact_logged($pdo, $id, 'Pessoa', $by);
    return $id;
}

/** Liga a lead à empresa e à pessoa na aba Contactos (cria o que faltar). */
function lead_attach_contacts(PDO $pdo, int $id, ?int $useContactId = null, ?int $by = null): void {
    $l = lead_get($pdo, $id);
    if (!$l) return;
    $site = '';
    foreach (preg_split('/\s*[|\n]\s*/', (string)$l['links']) as $u) {
        if ($u !== '' && social_kind($u)[0] === 'Site') { $site = $u; break; }
    }
    $extra = ['email' => (string)$l['email'], 'phone' => (string)$l['phone'], 'website' => $site];
    $cid = $l['company_id'] ? (int)$l['company_id'] : lead_company_contact($pdo, (string)$l['company'], $l['person'] === '' ? $extra : [], $by);
    $pid = $l['person_id'] ? (int)$l['person_id'] : lead_person_contact($pdo, (string)$l['person'], $cid, $extra, $useContactId, $by);
    $pdo->prepare('UPDATE deals SET company_id = ?, person_id = ? WHERE id = ?')->execute([$cid, $pid, $id]);
}

// ── criar ──────────────────────────────────────────────────

/**
 * Cria uma lead. $owner null = fila «Por atribuir». Devolve o id.
 * Cria (ou reutiliza) a empresa e a pessoa em Contactos e a tarefa
 * «Ligar a …» com o prazo do estado Novo — exceto em quarentena.
 */
function lead_create(PDO $pdo, array $v, ?int $owner, ?int $createdBy, bool $quarantine = false, ?int $useContactId = null): int {
    $name = trim($v['person']) !== '' ? $v['person'] : (trim($v['company']) !== '' ? $v['company'] : 'Sem nome');
    $pdo->prepare(
        'INSERT INTO deals (phase, lead_state, name, company, person, types, links, phone, email, service, priority, detail, why, notes,
                            origin, campaign, is_quarantined, owner_id, created_by, last_touch, state_since, created_at)
         VALUES (\'lead\', \'novo\', ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        mb_substr($name, 0, 160), $v['company'], $v['person'], $v['types'], $v['links'], $v['phone'], $v['email'], $v['service'],
        $v['priority'], $v['detail'], $v['why'], $v['notes'], $v['origin'], $v['campaign'], $quarantine ? 1 : 0,
        $owner, $createdBy, today_lisbon(), now_php(), now_php(),
    ]);
    $id = (int)$pdo->lastInsertId();
    deal_log($pdo, $id, 'create', ['note' => LEAD_ORIGIN[$v['origin']] ?? '', 'user_id' => $createdBy]);
    if (!$quarantine) lead_start($pdo, $id, $useContactId, $createdBy);
    return $id;
}

/** O que acontece quando uma lead entra a sério: contactos e a primeira chamada. */
function lead_start(PDO $pdo, int $id, ?int $useContactId = null, ?int $by = null): void {
    lead_attach_contacts($pdo, $id, $useContactId, $by);
    $l = lead_get($pdo, $id);
    $due = lead_clock($l, lead_deadline_hours($pdo));
    lead_task_create($pdo, $l, 'call', 'Ligar a ' . lead_display_name($l), '', $due ? lisbon_fmt($due['due']) : null,
                     null, $l['owner_id'] !== null ? (int)$l['owner_id'] : null, '', $by);
}

// ── prazos (24/7) ──────────────────────────────────────────

function lead_deadline_hours(PDO $pdo): array {
    $out = [];
    foreach (LEAD_DEADLINE_DEFAULTS as $k => $def) $out[$k] = max(1, min(720, (int)lead_setting($pdo, 'lead_deadline_' . $k, $def)));
    return $out;
}

/** «18 h», «45 min», «3 d». */
function lead_fmt_span(int $s): string {
    if ($s < 3600) return max(1, (int)round($s / 60)) . ' min';
    $h = (int)floor($s / 3600);
    return $h >= 100 ? (int)round($h / 24) . ' d' : $h . ' h';
}

/**
 * O relógio da lead: null se já não conta (arquivada, convertida, quarentena).
 * ['start','due' (timestamps), 'pct', 'level' ok|near|late, 'label', 'tip'].
 */
function lead_clock(?array $l, array $hours, ?int $now = null): ?array {
    if (!$l || $l['phase'] !== 'lead' || (int)$l['is_quarantined'] === 1) return null;
    $state = isset($hours[$l['lead_state']]) ? $l['lead_state'] : 'novo';
    $start = strtotime((string)($l['state_since'] ?: $l['created_at']));
    $total = $hours[$state] * 3600;
    $due = $start + $total;
    $now ??= time();
    $pct = max(0, ($now - $start) / $total);
    $level = $pct > 1 ? 'late' : ($pct >= .5 ? 'near' : 'ok');
    $left = $due - $now;
    $when = lisbon_fmt($due, 'd/m H:i');
    return [
        'start' => $start, 'due' => $due, 'pct' => $pct, 'level' => $level,
        'label' => $left >= 0 ? lead_fmt_span($left) : '+' . lead_fmt_span(-$left) . ' atraso',
        'tip'   => ['ok' => 'No prazo', 'near' => 'A aproximar', 'late' => 'Em atraso'][$level] . ' · prazo ' . $when,
    ];
}

/** Muda o estado (recomeça o prazo com o valor do novo estado) e regista no histórico. */
function lead_set_state(PDO $pdo, array $l, string $state, string $note = ''): void {
    if (!isset(LEAD_STATE[$state])) throw new RuntimeException('Estado inválido.');
    $pdo->prepare('UPDATE deals SET lead_state = ?, state_since = ?, no_answer = ? WHERE id = ?')
        ->execute([$state, now_php(), $state === 'sem_resposta' ? (int)$l['no_answer'] : 0, $l['id']]);
    if ($state !== $l['lead_state']) {
        deal_log($pdo, (int)$l['id'], 'state', ['note' => (LEAD_STATE[$l['lead_state']] ?? $l['lead_state']) . ' → ' . LEAD_STATE[$state] . ($note !== '' ? ' · ' . $note : '')]);
    }
}

/** Houve contacto agora: o «último contacto» é automático, a partir do histórico. */
function lead_touch(PDO $pdo, int $id): void {
    $pdo->prepare('UPDATE deals SET last_contact_at = ?, last_touch = ? WHERE id = ?')->execute([now_php(), today_lisbon(), $id]);
}

// ── tarefas da lead (Nova ação, Foco) ──────────────────────

/** A pilha de tarefas de um utilizador (cria-a se faltar). */
function member_for_user(PDO $pdo, ?int $userId): ?int {
    if (!$userId) return null;
    $st = $pdo->prepare('SELECT team_member_id FROM users WHERE id = ?');
    $st->execute([$userId]);
    $mid = $st->fetchColumn();
    if (!$mid) {
        require_once __DIR__ . '/tasks.php';
        team_sync($pdo);
        $st->execute([$userId]);
        $mid = $st->fetchColumn();
    }
    return $mid ? (int)$mid : null;
}

/** Cria uma tarefa ligada à lead (aparece também na aba Tarefas). $due «aaaa-mm-dd hh:mm» (Lisboa) ou null. */
function lead_task_create(PDO $pdo, array $l, string $kind, string $title, string $notes, ?string $due, ?int $duration,
                          ?int $assigneeUserId, string $repeat = '', ?int $by = null): int {
    require_once __DIR__ . '/tasks.php';
    $kind = isset(LEAD_TASK_KINDS[$kind]) ? $kind : 'task';
    $member = member_for_user($pdo, $assigneeUserId);
    $date = $due ? substr($due, 0, 10) : null;
    $time = $due && strlen($due) >= 16 ? substr($due, 11, 5) . ':00' : null;
    $now = task_now();
    $pdo->prepare(
        'INSERT INTO tasks (title, description, member_id, job_name, deal_id, due_date, due_time, duration_min, kind, repeat_rule,
                            priority, status, position, created_at, updated_at, created_by)
         VALUES (?,?,?,\'\',?,?,?,?,?,?,\'media\',\'todo\',?,?,?,?)'
    )->execute([mb_substr($title, 0, 200), $notes, $member, (int)$l['id'], $date, $time, $duration, $kind,
                isset(TASK_REPEAT[$repeat]) ? $repeat : '', task_next_position($pdo, $member), $now, $now, $by]);
    $tid = (int)$pdo->lastInsertId();
    deal_log($pdo, (int)$l['id'], 'task_new', ['note' => LEAD_TASK_KINDS[$kind][0] . ' · ' . $title, 'user_id' => $by]);
    return $tid;
}

/** As tarefas abertas de várias leads: lead → [tarefas, por prazo]. */
function lead_open_tasks(PDO $pdo, array $ids): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $rows = $pdo->query('SELECT t.*, tm.name AS who FROM tasks t LEFT JOIN team_members tm ON tm.id = t.member_id
                          WHERE t.status <> \'done\' AND t.deal_id IN (' . implode(',', $ids) . ')
                          ORDER BY t.due_date IS NULL, t.due_date, t.due_time IS NULL, t.due_time, t.id')->fetchAll();
    $out = [];
    foreach ($rows as $t) $out[(int)$t['deal_id']][] = $t;
    return $out;
}

/** «Ligar · hoje 09:00». */
function lead_task_when(array $t): string {
    if (!$t['due_date']) return 'sem data';
    $d = new DateTimeImmutable($t['due_date']);
    $today = new DateTimeImmutable(today_lisbon());
    $diff = (int)$today->diff($d)->format('%r%a');
    $day = match (true) {
        $diff === 0 => 'hoje', $diff === 1 => 'amanhã', $diff === -1 => 'ontem',
        $diff > 1 && $diff < 7 => ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'][(int)$d->format('w')],
        default => $d->format('d/m'),
    };
    return $day . ($t['due_time'] ? ' ' . substr((string)$t['due_time'], 0, 5) : '');
}

/** A tarefa está atrasada (data e hora já passaram)? */
function task_is_late(array $t): bool {
    if (!$t['due_date'] || $t['status'] === 'done') return false;
    return lisbon_ts($t['due_date'] . ' ' . substr((string)($t['due_time'] ?: '23:59:59'), 0, 8)) < time();
}

// ── atrasos por papel ──────────────────────────────────────

/**
 * As leads ativas que $user pode ver e que contam para os atrasos:
 * superadmin e admins todas; os outros só as suas (§12.4).
 */
function lead_counted(PDO $pdo, array $user, bool $all = false): array {
    $sql = "SELECT * FROM deals WHERE phase = 'lead' AND is_quarantined = 0";
    if (!$all && !role_is_admin((string)$user['role'])) {
        $st = $pdo->prepare($sql . ' AND owner_id = ?');
        $st->execute([(int)$user['id']]);
        return $st->fetchAll();
    }
    return $pdo->query($sql)->fetchAll();
}

/** A próxima lead em atraso (a mais atrasada) ao alcance de $me, sem ser $except. */
function lead_next_urgent(PDO $pdo, array $me, int $except = 0): ?int {
    [$sql, $args] = scope_sql($pdo, 'leads', 'owner_id');
    if (sees_unassigned($me)) $sql = "($sql OR owner_id IS NULL)";
    $st = $pdo->prepare("SELECT * FROM deals WHERE phase = 'lead' AND is_quarantined = 0 AND id <> ? AND $sql");
    $st->execute(array_merge([$except], $args));
    $hours = lead_deadline_hours($pdo);
    $best = null; $bestDue = PHP_INT_MAX;
    foreach ($st->fetchAll() as $l) {
        $c = lead_clock($l, $hours);
        if ($c && $c['level'] === 'late' && $c['due'] < $bestDue) { $best = (int)$l['id']; $bestDue = $c['due']; }
    }
    return $best;
}

// ── arquivar e converter ───────────────────────────────────

/**
 * Arquiva uma lead ativa (§12.9): o prazo termina e as tarefas abertas saem
 * do Foco e da aba Tarefas. Devolve o que é preciso para o «Anular» repor.
 */
function lead_archive(PDO $pdo, array $l, string $reason, string $note): array {
    $id = (int)$l['id'];
    $st = $pdo->prepare("SELECT * FROM tasks WHERE deal_id = ? AND status <> 'done'");
    $st->execute([$id]);
    $tasks = $st->fetchAll();
    $pdo->prepare("UPDATE deals SET phase = 'archived', archived_reason = ?, archived_note = ?, archived_at = " . now_sql($pdo) . " WHERE id = ? AND phase = 'lead'")
        ->execute([$reason, $note, $id]);
    if ($tasks) $pdo->prepare("DELETE FROM tasks WHERE deal_id = ? AND status <> 'done'")->execute([$id]);
    deal_log($pdo, $id, 'archive', ['note' => ARCHIVE_REASONS[$reason] . ($note !== '' ? ' · ' . $note : '')]);
    return ['id' => $id, 'tasks' => $tasks];
}

/** Repõe as tarefas abertas que saíram ao arquivar (o «Anular»). */
function lead_restore_tasks(PDO $pdo, array $tasks): void {
    foreach ($tasks as $t) {
        $cols = array_keys($t);
        $pdo->prepare('INSERT INTO tasks (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($t));
    }
}

/**
 * Converte a lead: atribui o ID, cria ou associa o contacto, e entra no
 * Pipeline na etapa X-Ray do funil escolhido. Tudo ou nada. O negócio
 * passa a ter o nome da empresa, quando há empresa; a pessoa fica ligada.
 * $contactId: id de um contacto existente, ou 0 para criar um novo.
 */
function lead_convert(PDO $pdo, array $lead, int $funnelId, int $contactId): string {
    $xray = funnel_fixed_stage($pdo, $funnelId, 'xray');
    if ($xray === null) throw new RuntimeException('Esse funil não tem a etapa X-Ray.');

    $pdo->beginTransaction();
    try {
        if ($contactId > 0) {
            $st = $pdo->prepare('SELECT id FROM contacts WHERE id = ?');
            $st->execute([$contactId]);
            if ($st->fetchColumn() === false) throw new RuntimeException('Contacto não encontrado.');
            $pdo->prepare('UPDATE contacts SET is_client = 1 WHERE id = ?')->execute([$contactId]);
        } else {
            $pdo->prepare(
                'INSERT INTO contacts (kind, name, is_client, email, phone, website, notes, created_by)
                 VALUES (\'company\', ?, 1, ?, ?, ?, \'\', ?)'
            )->execute([trim((string)$lead['company']) ?: $lead['name'], $lead['email'], mb_substr(lead_phones((string)$lead['phone'])[0] ?? '', 0, 40),
                        mb_substr((string)strtok((string)$lead['links'], "\n|"), 0, 255), current_user()['id'] ?? null]);
            $contactId = (int)$pdo->lastInsertId();
        }
        $code = deal_assign_code($pdo, (int)$lead['id']);
        $name = trim((string)$lead['company']) !== '' ? $lead['company'] : $lead['name'];
        $pdo->prepare(
            'UPDATE deals SET phase = \'pipeline\', name = ?, funnel_id = ?, origin_funnel_id = ?, stage_id = ?, contact_id = ?,
                              converted_at = ' . now_sql($pdo) . ', is_quarantined = 0, last_touch = ?
              WHERE id = ?'
        )->execute([mb_substr((string)$name, 0, 160), $funnelId, $funnelId, $xray, $contactId, today_lisbon(), $lead['id']]);
        $ins = (db_is_sqlite($pdo) ? 'INSERT OR IGNORE' : 'INSERT IGNORE') . ' INTO deal_contacts (deal_id, contact_id, role) VALUES (?, ?, ?)';
        $pdo->prepare($ins)->execute([$lead['id'], $contactId, '']);
        if ($lead['person_id'] && (int)$lead['person_id'] !== $contactId) $pdo->prepare($ins)->execute([$lead['id'], (int)$lead['person_id'], 'Contacto']);
        deal_log($pdo, (int)$lead['id'], 'convert', ['to_funnel' => $funnelId, 'to_stage' => $xray]);
        $pdo->commit();
        return $code;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── migração para a v2 (passo 13) ──────────────────────────

/**
 * Passa as leads antigas para a v2, uma vez por registo (as que ainda não
 * têm empresa nem pessoa): o nome passa a Empresa, os contactos separam-se
 * (o que não for claro vai para as notas), Contactado e Em conversa passam a
 * Follow-Up, e a empresa fica registada em Contactos sem duplicar. Os prazos
 * das leads ativas começam a contar agora. Devolve quantas mudaram.
 */
function leads_v2_convert(PDO $pdo): int {
    $pdo->exec("UPDATE deals SET lead_state = 'follow_up' WHERE lead_state IN ('contactado', 'em_conversa')");
    $rows = $pdo->query("SELECT * FROM deals WHERE phase IN ('lead','archived') AND company = '' AND person = ''
                          AND company_id IS NULL AND person_id IS NULL ORDER BY id")->fetchAll();
    if (!$rows) return 0;

    // Telefones cortados aos 40 caracteres na migração anterior: a tabela antiga ainda os tem inteiros.
    $full = [];
    if (db_has_table($pdo, 'leads') && db_has_column($pdo, 'leads', 'phone')) {
        foreach ($pdo->query('SELECT name, phone FROM leads')->fetchAll() as $r) $full[mb_strtolower(trim((string)$r['name']))][] = (string)$r['phone'];
    }

    $now = now_php();
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as $l) {
            $phone = (string)$l['phone'];
            if (mb_strlen($phone) >= 40) {
                foreach ($full[mb_strtolower(trim((string)$l['name']))] ?? [] as $p) if (str_starts_with($p, $phone)) { $phone = $p; break; }
            }
            [$phones, $email, $links, $notes] = lead_parse_contacts($phone, (string)$l['email'], (string)$l['links']);
            $allNotes = trim((string)$l['notes']);
            if ($notes) $allNotes = trim($allNotes . "\n[Migração para a v2] " . implode(' · ', $notes));
            $active = $l['phase'] === 'lead';
            $pdo->prepare('UPDATE deals SET company = ?, phone = ?, email = ?, links = ?, notes = ?, state_since = ?, last_contact_at = ? WHERE id = ?')
                ->execute([mb_substr((string)$l['name'], 0, 160), mb_substr(implode(' | ', $phones), 0, 255), mb_substr($email, 0, 190),
                           implode("\n", $links), $allNotes, $active ? $now : null,
                           $l['lead_state'] === 'follow_up' && $l['last_touch'] ? $l['last_touch'] . ' 12:00:00' : null, $l['id']]);
            if (!(int)$l['is_quarantined']) lead_attach_contacts($pdo, (int)$l['id']);
            $n++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $n;
}
