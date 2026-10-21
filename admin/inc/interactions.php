<?php
/**
 * topper. admin — histórico de interações (§11.9)
 *
 * Leads, negócios e contactos têm uma cronologia: o que se registou à
 * mão («Registar interação»: chamada, email, reunião, nota) junto com o
 * que o sistema já guarda sozinho em deal_history (conversão, etapas,
 * funil, responsável, arquivo, perdido…). Quem, quando, o quê.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/reach.php';
require_once __DIR__ . '/ui.php';

const INTERACTION_KINDS = [
    'call'    => ['Chamada', 'phone'],
    'email'   => ['Email', 'mail'],
    'meeting' => ['Reunião', 'meeting'],
    'note'    => ['Nota', 'note'],
];

const HISTORY_LABELS = [
    'convert' => 'Convertida em negócio', 'stage' => 'Mudou de etapa', 'funnel' => 'Mudou de funil',
    'lost' => 'Perdido', 'reopen' => 'Reaberto', 'assign' => 'Responsável', 'archive' => 'Arquivada',
    'reactivate' => 'Reativada', 'import' => 'Importado da folha', 'state' => 'Estado da lead',
];

/**
 * Regista uma interação. $dealId ou $contactId (pelo menos um).
 * Numa lead ou num negócio, conta como contacto: o «último contacto» avança.
 */
function interaction_add(PDO $pdo, ?int $dealId, ?int $contactId, string $kind, string $body, string $date): int {
    $kind = isset(INTERACTION_KINDS[$kind]) ? $kind : 'note';
    $body = trim(mb_substr($body, 0, 4000));
    if ($body === '') throw new RuntimeException('Escreva o que aconteceu.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > today_lisbon()) $date = today_lisbon();
    $pdo->prepare('INSERT INTO interactions (deal_id, contact_id, kind, body, happened_at, user_id) VALUES (?,?,?,?,?,?)')
        ->execute([$dealId, $contactId, $kind, $body, $date . ' ' . date('H:i:s'), (int)current_user()['id']]);
    $id = (int)$pdo->lastInsertId();
    if ($dealId) {
        $pdo->prepare('UPDATE deals SET last_touch = ? WHERE id = ? AND (last_touch IS NULL OR last_touch < ?)')
            ->execute([$date, $dealId, $date]);
    }
    return $id;
}

/** Texto de uma linha de deal_history. */
function history_text(array $h): string {
    $parts = [];
    if ($h['event'] === 'funnel') {
        $parts[] = ($h['from_funnel'] ?? '?') . ' › ' . ($h['from_stage'] ?? '?') . ' → ' . ($h['to_funnel'] ?? '?') . ' › ' . ($h['to_stage'] ?? '?');
    } elseif ($h['from_stage'] || $h['to_stage']) {
        $parts[] = trim(($h['from_stage'] ?? '') . ' → ' . ($h['to_stage'] ?? ''), ' →');
    }
    if ((string)$h['note'] !== '') $parts[] = $h['note'];
    return implode(' · ', $parts);
}

/**
 * A cronologia de um registo: 'deal' (lead ou negócio) ou 'contact'.
 * Cada linha: when, icon, title, text, who, sys. A visibilidade do registo
 * verifica-se antes de chamar; nos contactos, só entram os negócios que
 * a pessoa pode ver.
 */
function timeline(PDO $pdo, string $type, int $id, int $limit = 80): array {
    $out = [];
    $dealIds = [];
    if ($type === 'deal') {
        $dealIds = [$id];
        $st = $pdo->prepare('SELECT i.*, u.name AS who FROM interactions i LEFT JOIN users u ON u.id = i.user_id WHERE i.deal_id = ?');
        $st->execute([$id]);
    } else {
        $st = $pdo->prepare('SELECT d.* FROM deals d WHERE d.contact_id = ? OR d.id IN (SELECT deal_id FROM deal_contacts WHERE contact_id = ?)');
        $st->execute([$id, $id]);
        foreach ($st->fetchAll() as $d) if (record_reach($pdo, $d)) $dealIds[] = (int)$d['id'];
        $in = $dealIds ? ' OR i.deal_id IN (' . implode(',', $dealIds) . ')' : '';
        $st = $pdo->prepare("SELECT i.*, u.name AS who, d.code FROM interactions i LEFT JOIN users u ON u.id = i.user_id
                               LEFT JOIN deals d ON d.id = i.deal_id WHERE i.contact_id = ?$in");
        $st->execute([$id]);
    }
    foreach ($st->fetchAll() as $i) {
        [$label, $ico] = INTERACTION_KINDS[$i['kind']] ?? INTERACTION_KINDS['note'];
        $out[] = ['when' => $i['happened_at'], 'icon' => $ico, 'title' => $label . (!empty($i['code']) ? ' · ' . $i['code'] : ''),
                  'text' => $i['body'], 'who' => $i['who'] ?? '—', 'sys' => false];
    }
    if ($dealIds) {
        $only = $type === 'contact' ? " AND h.event IN ('convert','lost','reopen')" : '';
        $hist = $pdo->query(
            'SELECT h.*, u.name AS who, d.code, fs.name AS from_stage, ts.name AS to_stage, ff.name AS from_funnel, tf.name AS to_funnel
               FROM deal_history h
               JOIN deals d ON d.id = h.deal_id
               LEFT JOIN users u ON u.id = h.user_id
               LEFT JOIN funnel_stages fs ON fs.id = h.from_stage_id
               LEFT JOIN funnel_stages ts ON ts.id = h.to_stage_id
               LEFT JOIN funnels ff ON ff.id = h.from_funnel_id
               LEFT JOIN funnels tf ON tf.id = h.to_funnel_id
              WHERE h.deal_id IN (' . implode(',', $dealIds) . ")$only"
        )->fetchAll();
        $icons = ['convert' => 'won', 'stage' => 'stage', 'funnel' => 'stage', 'lost' => 'x', 'reopen' => 'undo',
                  'assign' => 'user', 'archive' => 'archive', 'reactivate' => 'undo', 'state' => 'stage', 'import' => 'file'];
        foreach ($hist as $h) {
            $out[] = ['when' => $h['created_at'], 'icon' => $icons[$h['event']] ?? 'clock',
                      'title' => (HISTORY_LABELS[$h['event']] ?? $h['event']) . ($type === 'contact' && $h['code'] ? ' · ' . $h['code'] : ''),
                      'text' => history_text($h), 'who' => $h['who'] ?? 'Sistema', 'sys' => true];
        }
    }
    usort($out, fn($a, $b) => strcmp((string)$b['when'], (string)$a['when']));
    return array_slice($out, 0, $limit);
}

/** A cronologia em HTML. */
function timeline_html(array $items): string {
    if (!$items) return '<p class="muted" style="margin:0">Ainda sem histórico.</p>';
    $h = '<ol class="tline">';
    foreach ($items as $i) {
        $h .= '<li class="' . ($i['sys'] ? 'sys' : '') . '">' . icon($i['icon'])
            . '<div><div class="meta">' . e(date('d/m/Y H:i', strtotime((string)$i['when']))) . ' · ' . e($i['who']) . ' · ' . e($i['title']) . '</div>'
            . ($i['text'] !== '' ? '<div class="what">' . e($i['text']) . '</div>' : '') . '</div></li>';
    }
    return $h . '</ol>';
}

/** O formulário «Registar interação» (funciona com e sem JavaScript). */
function log_form_html(string $type, int $id, string $back): string {
    $opts = '';
    foreach (INTERACTION_KINDS as $k => [$label]) $opts .= '<option value="' . $k . '">' . e($label) . '</option>';
    return '<form class="log-form" method="post" action="/admin/ui_api.php" data-log>'
         . csrf_field() . '<input type="hidden" name="a" value="interaction">'
         . '<input type="hidden" name="' . ($type === 'contact' ? 'contact_id' : 'deal_id') . '" value="' . $id . '">'
         . '<input type="hidden" name="back" value="' . e($back) . '">'
         . '<select name="kind" aria-label="Tipo de interação">' . $opts . '</select>'
         . '<input type="date" name="date" value="' . e(today_lisbon()) . '" max="' . e(today_lisbon()) . '" aria-label="Quando">'
         . '<textarea name="body" required maxlength="4000" placeholder="O que aconteceu? (ex.: ligou a pedir orçamento para março)" aria-label="O que aconteceu"></textarea>'
         . '<div class="actions"><button class="btn sm" type="submit">' . icon('plus') . ' Registar interação</button></div>'
         . '</form>';
}

/** Bloco completo: título, formulário (se pode editar) e cronologia. */
function history_block(PDO $pdo, string $type, int $id, bool $canLog, string $back, string $heading = 'Histórico'): string {
    return '<section class="hist" data-hist="' . e($type) . ':' . $id . '">'
         . ($heading !== '' ? '<h2 id="historico">' . e($heading) . '</h2>' : '')
         . ($canLog ? log_form_html($type, $id, $back) : '')
         . '<div class="hist-list">' . timeline_html(timeline($pdo, $type, $id)) . '</div></section>';
}
