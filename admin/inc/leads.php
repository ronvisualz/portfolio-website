<?php
/**
 * topper. admin — leads (MySQL)
 *
 * Uma lead é um registo de `deals` com phase = 'lead'. Ao ser convertida
 * recebe o ID do negócio e passa ao Pipeline, sem nunca se duplicar.
 * As funções daqui servem a aba Leads e, mais tarde, o formulário do site.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const LEAD_TYPES_LIST  = ['Produtora', 'Agência de Marketing', 'Empresa Particular', 'Realizador', 'VFX company'];
const LEAD_SERVICES_LIST = ['VFX / 3D', 'Marketing Buddy', 'Ambos'];
const LEAD_PRIORITY    = ['alta' => 'Alta', 'media' => 'Média', 'baixa' => 'Baixa'];
const LEAD_STATE       = ['novo' => 'Novo', 'contactado' => 'Contactado', 'em_conversa' => 'Em conversa'];
const LEAD_ORIGIN      = ['manual' => 'Manual', 'site' => 'Site', 'anuncio' => 'Anúncio'];
const ARCHIVE_REASONS  = [
    'sem_interesse' => 'Sem interesse',
    'sem_resposta'  => 'Sem resposta',
    'fora_perfil'   => 'Fora de perfil',
    'spam'          => 'Spam',
    'outro'         => 'Outro',
    'perdido_migracao' => 'Perdido (migração)',
];

function today_lisbon(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d');
}

function now_sql(PDO $pdo): string {
    return db_is_sqlite($pdo) ? "datetime('now')" : 'NOW()';
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

/**
 * Registos e contactos com o mesmo email ou telefone.
 * Devolve ['deals' => [...], 'contacts' => [...]].
 */
function find_duplicates(PDO $pdo, string $email, string $phone, int $exceptId = 0): array {
    $ek = email_key($email);
    $pk = phone_key($phone);
    $out = ['deals' => [], 'contacts' => []];
    if ($ek === '' && strlen($pk) < 6) return $out;

    foreach (['deals' => 'SELECT id, code, name, phase, email, phone, owner_id FROM deals WHERE id <> ?',
              'contacts' => 'SELECT id, name, email, phone FROM contacts WHERE id <> ?'] as $k => $sql) {
        $st = $pdo->prepare($sql . ' AND (LOWER(email) = ? OR phone <> \'\')');
        $st->execute([$k === 'deals' ? $exceptId : 0, $ek === '' ? '-' : $ek]);
        foreach ($st->fetchAll() as $r) {
            $hit = ($ek !== '' && email_key((string)$r['email']) === $ek)
                || (strlen($pk) >= 6 && phone_key((string)$r['phone']) === $pk);
            if ($hit) $out[$k][] = $r;
        }
    }
    return $out;
}

/** Os campos de lead vindos de um formulário (sem estado nem dono). */
function lead_input(array $src): array {
    $types = [];
    foreach ((array)($src['types'] ?? []) as $t) {
        $t = trim((string)$t);
        if (in_array($t, LEAD_TYPES_LIST, true) && !in_array($t, $types, true)) $types[] = $t;
    }
    $links = array_values(array_filter(array_map('trim',
        explode('|', str_replace(["\r\n", "\n"], '|', (string)($src['links'] ?? '')))), 'strlen'));
    $txt = static fn(string $k, int $n) => mb_substr(trim((string)($src[$k] ?? '')), 0, $n);
    $service = (string)($src['service'] ?? '');
    $prio    = (string)($src['priority'] ?? 'media');
    $origin  = (string)($src['origin'] ?? 'manual');
    return [
        'name'     => $txt('name', 160) ?: 'Sem nome',
        'types'    => implode(', ', $types),
        'links'    => implode(' | ', $links),
        'phone'    => $txt('phone', 40),
        'email'    => $txt('email', 190),
        'service'  => in_array($service, LEAD_SERVICES_LIST, true) ? $service : '',
        'priority' => isset(LEAD_PRIORITY[$prio]) ? $prio : 'media',
        'detail'   => $txt('detail', 4000),
        'why'      => $txt('why', 4000),
        'notes'    => $txt('notes', 8000),
        'origin'   => isset(LEAD_ORIGIN[$origin]) ? $origin : 'manual',
        'campaign' => $txt('campaign', 160),
    ];
}

/** Cria uma lead. $owner null = vai para a fila «Por atribuir». Devolve o id. */
function lead_create(PDO $pdo, array $v, ?int $owner, ?int $createdBy, bool $quarantine = false): int {
    $pdo->prepare(
        'INSERT INTO deals (phase, lead_state, name, types, links, phone, email, service, priority, detail, why, notes,
                            origin, campaign, is_quarantined, owner_id, created_by, last_touch)
         VALUES (\'lead\', \'novo\', ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $v['name'], $v['types'], $v['links'], $v['phone'], $v['email'], $v['service'], $v['priority'],
        $v['detail'], $v['why'], $v['notes'], $v['origin'], $v['campaign'], $quarantine ? 1 : 0,
        $owner, $createdBy, today_lisbon(),
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Junta os dados de uma lead nova a uma existente: preenche o que está
 * vazio, junta tipos e contactos, e acrescenta as notas. Nada se perde.
 */
function lead_merge_into(PDO $pdo, array $target, array $v): void {
    $fill = static fn(string $k) => trim((string)$target[$k]) !== '' ? $target[$k] : $v[$k];
    $types = array_values(array_unique(array_filter(array_map('trim', array_merge(
        explode(',', (string)$target['types']), explode(',', $v['types']))), 'strlen')));
    $links = array_values(array_unique(array_filter(array_map('trim', array_merge(
        explode('|', (string)$target['links']), explode('|', $v['links']))), 'strlen')));
    $extra = [];
    foreach (['detail' => 'Detalhe', 'why' => 'Porquê', 'notes' => 'Notas'] as $k => $label) {
        if ($v[$k] !== '' && $v[$k] !== $target[$k] && trim((string)$target[$k]) !== '') $extra[] = "$label: " . $v[$k];
    }
    $notes = trim((string)$target['notes']) !== '' ? trim((string)$target['notes']) : $v['notes'];
    if ($extra) $notes = trim($notes . "\n\n[Junção " . today_lisbon() . "] " . implode(' · ', $extra));
    $pdo->prepare(
        'UPDATE deals SET types=?, links=?, phone=?, email=?, service=?, detail=?, why=?, notes=?, campaign=?, last_touch=? WHERE id=?'
    )->execute([
        implode(', ', $types), implode(' | ', $links), $fill('phone'), $fill('email'), $fill('service'),
        $fill('detail'), $fill('why'), $notes, $fill('campaign'), today_lisbon(), $target['id'],
    ]);
}

/** Dias sem atividade a partir dos quais uma lead fica marcada como parada. */
function lead_stale_days(PDO $pdo): int {
    return max(1, (int)setting($pdo, 'lead_stale_days', 14));
}

function lead_is_stale(array $l, int $days): bool {
    $ref = $l['last_touch'] ?: substr((string)$l['created_at'], 0, 10);
    if (!$ref) return false;
    return (new DateTimeImmutable($ref)) < (new DateTimeImmutable(today_lisbon()))->modify("-$days days");
}

/**
 * Converte a lead: atribui o ID, cria ou associa o contacto, e entra no
 * Pipeline na etapa X-Ray do funil escolhido. Tudo ou nada.
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
            )->execute([$lead['name'], $lead['email'], $lead['phone'], mb_substr((string)$lead['links'], 0, 255), current_user()['id'] ?? null]);
            $contactId = (int)$pdo->lastInsertId();
        }
        $code = deal_assign_code($pdo, (int)$lead['id']);
        $pdo->prepare(
            'UPDATE deals SET phase = \'pipeline\', funnel_id = ?, origin_funnel_id = ?, stage_id = ?, contact_id = ?,
                              converted_at = ' . now_sql($pdo) . ', is_quarantined = 0, last_touch = ?
              WHERE id = ?'
        )->execute([$funnelId, $funnelId, $xray, $contactId, today_lisbon(), $lead['id']]);
        if (db_is_sqlite($pdo)) {
            $pdo->prepare('INSERT OR IGNORE INTO deal_contacts (deal_id, contact_id) VALUES (?, ?)')->execute([$lead['id'], $contactId]);
        } else {
            $pdo->prepare('INSERT IGNORE INTO deal_contacts (deal_id, contact_id) VALUES (?, ?)')->execute([$lead['id'], $contactId]);
        }
        deal_log($pdo, (int)$lead['id'], 'convert', ['to_funnel' => $funnelId, 'to_stage' => $xray]);
        $pdo->commit();
        return $code;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
