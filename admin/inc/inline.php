<?php
/**
 * topper. admin — edição no próprio campo (§11.8)
 *
 * Só os campos desta lista se editam assim; cada um com a mesma limpeza
 * que tem no formulário da aba. A permissão confirma-se aqui em cada
 * pedido: nível «editar» da aba do registo e o registo no meu âmbito.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/reach.php';

/** entidade → campo → [tipo, limite ou opções]. */
function inline_fields(): array {
    return [
        'lead' => [
            'name'       => ['text', 160],
            'email'      => ['text', 190],
            'phone'      => ['text', 40],
            'service'    => ['select', ['' => '—'] + array_combine(LEAD_SERVICES_LIST, LEAD_SERVICES_LIST)],
            'priority'   => ['select', LEAD_PRIORITY],
            'lead_state' => ['select', LEAD_STATE],
            'origin'     => ['select', LEAD_ORIGIN],
            'campaign'   => ['text', 160],
            'detail'     => ['textarea', 4000],
            'why'        => ['textarea', 4000],
            'notes'      => ['textarea', 8000],
            'last_touch' => ['date', 10],
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
function inline_display(string $entity, string $field, $value): string {
    $def = inline_fields()[$entity][$field] ?? ['text', 0];
    if ($value === null || $value === '') return '';
    return match ($def[0]) {
        'select' => (string)($def[1][$value] ?? $value),
        'money'  => eur0((float)$value),
        'date'   => date('d/m/Y', strtotime((string)$value)),
        default  => (string)$value,
    };
}

/**
 * Grava um campo. Devolve ['value', 'display', 'prev'].
 * RuntimeException com a mensagem para o utilizador quando não pode.
 */
function inline_save(PDO $pdo, string $entity, int $id, string $field, $raw): array {
    $def = inline_fields()[$entity][$field] ?? null;
    if (!$def) throw new RuntimeException('Este campo não se edita aqui.');

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

    $raw = is_string($raw) ? trim($raw) : '';
    switch ($def[0]) {
        case 'select':
            if (!array_key_exists($raw, $def[1])) throw new RuntimeException('Escolha uma opção da lista.');
            $value = $raw;
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
    if ($field === 'lead_state' && $row['phase'] !== 'lead') throw new RuntimeException('Esta lead não está ativa.');

    $prev = $row[$field];
    $sets = ["$field = ?"]; $args = [$value];
    // Mudar o estado de uma lead conta como contacto (o mesmo que na aba Leads).
    if ($field === 'lead_state' && $value !== $prev) { $sets[] = 'last_touch = ?'; $args[] = today_lisbon(); }
    $args[] = $id;
    $pdo->prepare("UPDATE $table SET " . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
    if ($field === 'lead_state' && $value !== $prev) {
        deal_log($pdo, $id, 'state', ['note' => (LEAD_STATE[$prev] ?? $prev) . ' → ' . LEAD_STATE[$value]]);
    }
    return ['value' => $value, 'display' => inline_display($entity, $field, $value), 'prev' => $prev];
}
