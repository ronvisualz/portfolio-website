<?php
/**
 * topper. admin — edição no próprio campo (§11.8, §12)
 *
 * Só os campos desta lista se editam assim; cada um com a mesma limpeza
 * que tem no formulário da aba. A permissão confirma-se aqui em cada
 * pedido: nível «editar» da aba do registo e o registo no meu âmbito; o
 * responsável segue as regras de atribuição por hierarquia (§3.1).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/reach.php';

/** As opções de responsável que a pessoa com sessão pode dar a esta lead. */
function owner_options(PDO $pdo, ?array $lead = null): array {
    $me = me_row($pdo);
    $out = [];
    if (sees_unassigned($me)) $out['none'] = '— Por atribuir —';
    foreach (assignable_users($pdo, $me) as $u) $out[(string)$u['id']] = $u['name'];
    if ($lead && $lead['owner_id'] !== null && !isset($out[(string)$lead['owner_id']])) {
        $out[(string)$lead['owner_id']] = (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$lead['owner_id'])->fetchColumn();
    }
    return $out;
}

/** entidade → campo → [tipo, limite ou opções]. */
function inline_fields(?array $lead = null): array {
    global $pdo;
    return [
        'lead' => [
            'company'    => ['text', 160],
            'person'     => ['text', 160],
            'phone'      => ['text', 255],
            'email'      => ['text', 190],
            'links'      => ['textarea', 4000],
            'types'      => ['multi', array_combine(lead_types($GLOBALS['pdo']), lead_types($GLOBALS['pdo']))],
            'service'    => ['select', ['' => '—'] + array_combine(LEAD_SERVICES_LIST, LEAD_SERVICES_LIST)],
            'priority'   => ['select', LEAD_PRIORITY],
            'lead_state' => ['select', LEAD_STATE],
            'origin'     => ['select', LEAD_ORIGIN],
            'campaign'   => ['text', 160],
            'detail'     => ['textarea', 4000],
            'why'        => ['textarea', 4000],
            'notes'      => ['textarea', 8000],
            'owner_id'   => ['select', $pdo && current_user() ? owner_options($pdo, $lead) : []],
        ],
        'deal' => [
            'name'           => ['text', 160],
            'notes'          => ['textarea', 8000],
            'value_estimate' => ['money', 0],
        ],
        'contact' => [
            'name'           => ['text', 160],
            'contact_person' => ['text', 160],
            'email'          => ['text', 190],
            'phone'          => ['text', 40],
            'nif'            => ['text', 20],
            'website'        => ['text', 255],
            'service'        => ['text', 160],
            'rate'           => ['text', 120],
            'notes'          => ['textarea', 8000],
        ],
    ];
}

/** O texto que se mostra para um valor guardado. */
function inline_display(string $entity, string $field, $value, ?array $lead = null): string {
    if ($entity === 'lead' && $field === 'owner_id') {
        global $pdo;
        return $value ? (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$value)->fetchColumn() : 'Por atribuir';
    }
    $def = inline_fields($lead)[$entity][$field] ?? ['text', 0];
    if ($value === null || $value === '') return '';
    return match ($def[0]) {
        'select' => (string)($def[1][$value] ?? $value),
        'money'  => eur0((float)$value),
        'date'   => date('d/m/Y', strtotime((string)$value)),
        default  => (string)$value,
    };
}

/** Campos de lead que mudam o resto do painel (prazo, nome, contactos): recarrega-se. */
const INLINE_RELOAD = ['company', 'person', 'phone', 'email', 'links', 'lead_state', 'owner_id', 'types'];

/**
 * Grava um campo. Devolve ['value', 'display', 'prev', 'reload'].
 * RuntimeException com a mensagem para o utilizador quando não pode.
 */
function inline_save(PDO $pdo, string $entity, int $id, string $field, $raw): array {
    if ($entity === 'contact') {
        require_perm('contactos', PERM_EDIT);
        $st = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException('Contacto não encontrado.');
        $table = 'contacts';
    } else {
        $row = record_get($pdo, $id);
        $isLead = $row && in_array($row['phase'], ['lead', 'archived'], true);
        if (!$row || ($entity === 'lead') !== $isLead || !record_reach($pdo, $row, PERM_EDIT)) {
            throw new RuntimeException('Registo não encontrado.');
        }
        $table = 'deals';
    }
    $def = inline_fields($entity === 'lead' ? $row : null)[$entity][$field] ?? null;
    if (!$def) throw new RuntimeException('Este campo não se edita aqui.');

    $raw = is_string($raw) ? trim($raw) : '';
    switch ($def[0]) {
        case 'select':
            if (!array_key_exists($raw, $def[1])) throw new RuntimeException('Escolha uma opção da lista.');
            $value = $raw;
            break;
        case 'multi':
            $value = implode(', ', array_values(array_intersect(array_keys($def[1]), array_map('trim', explode(',', $raw)))));
            break;
        case 'date':
            if ($raw !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) throw new RuntimeException('Indique uma data válida.');
            $value = $raw === '' ? null : $raw;
            break;
        case 'money':
            $value = $raw === '' ? null : money_in($raw);
            break;
        default:
            $value = mb_substr($raw, 0, $def[1]);
            if ($field === 'name' && $value === '') throw new RuntimeException('O nome não pode ficar vazio.');
    }
    $prev = $row[$field];

    if ($entity === 'lead') return inline_save_lead($pdo, $row, $field, $value) + ['prev' => $prev];

    $pdo->prepare("UPDATE $table SET $field = ? WHERE id = ?")->execute([$value, $id]);
    return ['value' => $value, 'display' => inline_display($entity, $field, $value), 'prev' => $prev, 'reload' => false];
}

/** As regras próprias da lead v2: empresa/pessoa em Contactos, prazos, responsável, telefones. */
function inline_save_lead(PDO $pdo, array $l, string $field, $value): array {
    $id = (int)$l['id'];
    $reload = in_array($field, INLINE_RELOAD, true);
    switch ($field) {
        case 'company':
        case 'person':
            $other = $field === 'company' ? 'person' : 'company';
            if ($value === '' && trim((string)$l[$other]) === '') throw new RuntimeException('A lead precisa de empresa ou de pessoa de contacto.');
            $l[$field] = $value;
            $name = lead_display_name($l);
            $pdo->prepare("UPDATE deals SET $field = ?, name = ?, {$field}_id = NULL WHERE id = ?")->execute([$value, mb_substr($name, 0, 160), $id]);
            // A empresa e a pessoa ficam em Contactos (reutiliza ou cria); vazio desliga.
            if ($value !== '' && !(int)$l['is_quarantined']) {
                if ($field === 'company') {
                    $cid = lead_company_contact($pdo, $value, [], (int)current_user()['id']);
                    $pdo->prepare('UPDATE deals SET company_id = ? WHERE id = ?')->execute([$cid, $id]);
                    if ($l['person_id']) $pdo->prepare('UPDATE contacts SET company_id = ? WHERE id = ? AND company_id IS NULL')->execute([$cid, (int)$l['person_id']]);
                } else {
                    $pid = lead_person_contact($pdo, $value, $l['company_id'] ? (int)$l['company_id'] : null,
                                               ['email' => (string)$l['email'], 'phone' => (string)$l['phone']], null, (int)current_user()['id']);
                    $pdo->prepare('UPDATE deals SET person_id = ? WHERE id = ?')->execute([$pid, $id]);
                }
            }
            break;

        case 'phone':
            $phones = lead_phones((string)$value);
            $value = $phones ? mb_substr(implode(' | ', $phones), 0, 255) : (string)$value;
            $pdo->prepare('UPDATE deals SET phone = ? WHERE id = ?')->execute([$value, $id]);
            break;

        case 'links':
            $links = array_values(array_unique(array_filter(array_map('trim', preg_split('/\s*[|\n]\s*/', (string)$value)), 'strlen')));
            $value = implode("\n", $links);
            $pdo->prepare('UPDATE deals SET links = ? WHERE id = ?')->execute([$value, $id]);
            break;

        case 'lead_state':
            if ($l['phase'] !== 'lead') throw new RuntimeException('Esta lead não está ativa.');
            if ($value !== $l['lead_state']) lead_set_state($pdo, $l, (string)$value);   // recomeça o prazo
            break;

        case 'owner_id':
            if ($l['phase'] !== 'lead') throw new RuntimeException('Esta lead não está ativa.');
            $owner = $value === 'none' ? null : (int)$value;
            if ((string)$owner === (string)$l['owner_id']) break;
            $me = me_row($pdo);
            if ($owner === null) {
                if (!sees_unassigned($me)) throw new RuntimeException('Só admins e chefes de equipa podem deixar uma lead por atribuir.');
            } else {
                $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $st->execute([$owner]);
                $t = $st->fetch();
                if (!$t || !can_assign_to($me, $t)) throw new RuntimeException('Não pode atribuir a essa pessoa.');
            }
            lead_reassign($pdo, $l, $owner);
            $value = $owner;
            break;

        default:
            $pdo->prepare("UPDATE deals SET $field = ? WHERE id = ?")->execute([$value, $id]);
    }
    return ['value' => $value, 'display' => inline_display('lead', $field, $value, $l), 'reload' => $reload];
}

/**
 * Muda o responsável da lead (as regras de atribuição já foram verificadas)
 * e leva-lhe as tarefas abertas da lead que eram do responsável anterior.
 */
function lead_reassign(PDO $pdo, array $l, ?int $owner): void {
    $pdo->prepare('UPDATE deals SET owner_id = ? WHERE id = ?')->execute([$owner, $l['id']]);
    $name = $owner === null ? 'por atribuir' : (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$owner)->fetchColumn();
    deal_log($pdo, (int)$l['id'], 'assign', ['note' => 'Responsável: ' . $name]);
    $from = member_for_user($pdo, $l['owner_id'] !== null ? (int)$l['owner_id'] : null);
    $to = member_for_user($pdo, $owner);
    $sql = "UPDATE tasks SET member_id = ? WHERE deal_id = ? AND status <> 'done' AND " . ($from === null ? 'member_id IS NULL' : 'member_id = ?');
    $pdo->prepare($sql)->execute(array_merge([$to, (int)$l['id']], $from === null ? [] : [$from]));
}
