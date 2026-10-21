<?php
/**
 * topper. admin — painel lateral de detalhe (§11.7)
 *
 * Abrir um registo de uma lista mostra à direita um resumo editável com a
 * próxima ação, sem sair da lista; a página completa abre-se a partir
 * dele. Nas Leads é o comportamento normal; a pesquisa ⌘K abre também
 * negócios e contactos aqui com ⌘Enter. Quem chama já confirmou que o
 * registo está ao alcance de quem pede.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/interactions.php';
require_once __DIR__ . '/inline.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/pipeline.php';

/** Uma linha do resumo: rótulo + valor (editável quando $edit). */
function panel_row(string $label, string $entity, int $id, string $field, array $row, bool $edit, array $opt = []): string {
    $def = inline_fields($entity === 'lead' ? $row : null)[$entity][$field] ?? ['text', 0];
    $val = $row[$field] ?? '';
    $shown = $opt['html'] ?? e(inline_display($entity, $field, $val, $row));
    $o = ['edit' => $edit, 'type' => $opt['type'] ?? $def[0], 'label' => 'Editar ' . mb_strtolower($label), 'value' => $opt['value'] ?? (string)$val];
    if (in_array($def[0], ['select', 'multi'], true)) $o['options'] = $def[1];
    if ($def[0] === 'money' && $val !== null && $val !== '') $o['value'] = number_format((float)$val, 2, ',', '');
    return '<span>' . e($label) . '</span><div>' . inline_field($entity, $id, $field, $shown, $o) . '</div>';
}

/** A lead no painel lateral: a vista larga em duas colunas (§12.5). */
function panel_lead(PDO $pdo, array $l): array {
    require_once __DIR__ . '/lead_view.php';
    $id = (int)$l['id'];
    $back = '/admin/leads.php?open=' . $id;
    // Fora da aba Leads (⌘K → ⌘Enter), o painel traz consigo os formulários e o leads.js.
    $modals = can('leads', PERM_EDIT) ? '<template data-lead-modals>' . lead_modals_html(null, $back) . '</template>' : '';
    $js = __DIR__ . '/../assets/leads.js';
    return ['title' => 'Lead', 'html' => lead_view($pdo, $l, 'panel', $back) . $modals,
            'url' => '/admin/lead.php?id=' . $id, 'wide' => true,
            'script' => '/admin/assets/leads.js?v=' . (is_file($js) ? filemtime($js) : 0)];
}

function panel_deal(PDO $pdo, array $d): array {
    $id = (int)$d['id'];
    $edit = record_reach($pdo, $d, PERM_EDIT);
    $stage = $d['stage_id'] ? stage_get($pdo, (int)$d['stage_id']) : null;
    $funnel = $d['funnel_id'] ? funnel_get($pdo, (int)$d['funnel_id']) : null;
    $owner = $d['owner_id'] ? (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$d['owner_id'])->fetchColumn() : 'Por atribuir';
    $phase = ['pipeline' => 'Fase comercial', 'production' => 'Em produção', 'done' => 'Concluído', 'lost' => 'Perdido'][$d['phase']] ?? $d['phase'];
    $idle = $d['phase'] === 'pipeline' ? days_since(deal_last_move($d)) : 0;
    $risk = $d['phase'] === 'pipeline' && $idle > alert_limits($pdo)['deal'];

    require_once __DIR__ . '/lead_view.php';   // ligações a contactos (Cliente, Trazido por)
    $kv = '<span>Funil · etapa</span><div>' . e(($funnel['name'] ?? '—') . ' · ' . ($stage['name'] ?? '—')) . '</div>'
        . '<span>Responsável</span><div>' . e($owner) . '</div>'
        . '<span>Cliente</span><div>' . deal_people_link($pdo, $d['contact_id']) . '</div>'
        . '<span>Trazido por</span><div>' . deal_people_link($pdo, $d['brought_by_id']) . '</div>'
        . panel_row('Valor estimado', 'deal', $id, 'value_estimate', $d, $edit)
        . ($d['phase'] === 'pipeline' ? '<span>Último movimento</span><div>' . e(ago_days(deal_last_move($d))) . '</div>' : '');
    $body = '<header><div class="id mono">' . e($d['code']) . '</div><h2>' . inline_field('deal', $id, 'name', e($d['name']), ['edit' => $edit, 'label' => 'Editar nome', 'value' => $d['name']]) . '</h2>'
          . '<div style="margin-top:6px"><span class="chip">' . e($phase) . '</span>' . ($risk ? ' <span class="chip risk">Sem movimento há ' . $idle . ' dias</span>' : '') . '</div></header>'
          . '<section class="sp-next"><h4>Próxima ação</h4><a class="btn" href="' . e(deal_url($d)) . '">' . icon('external') . ' Abrir a ficha completa</a></section>'
          . '<section><h4>Resumo</h4><div class="kv">' . $kv . '</div></section>'
          . '<section><h4>Notas</h4>' . inline_field('deal', $id, 'notes', e((string)$d['notes']), ['edit' => $edit, 'type' => 'textarea', 'label' => 'Editar notas', 'value' => (string)$d['notes']]) . '</section>'
          . '<section><h4>Histórico</h4>' . history_block($pdo, 'deal', $id, $edit, deal_url($d), '') . '</section>';
    return ['title' => 'Negócio', 'html' => $body, 'url' => deal_url($d)];
}

/** O contacto no painel lateral: a vista larga em duas colunas (§13.7). */
function panel_contact(PDO $pdo, array $c): array {
    require_once __DIR__ . '/contact_view.php';
    $id = (int)$c['id'];
    // Fora da aba Contactos, o painel traz consigo os formulários e o contacts.js.
    $modals = can('contactos', PERM_EDIT) ? '<template data-contact-modals>' . contact_modals_html($pdo) . '</template>' : '';
    $js = __DIR__ . '/../assets/contacts.js';
    return ['title' => $c['kind'] === 'company' ? 'Empresa' : 'Contacto', 'html' => contact_view($pdo, $c, 'panel', '/admin/contactos.php?open=' . $id) . $modals,
            'url' => '/admin/contact.php?id=' . $id, 'wide' => true,
            'script' => '/admin/assets/contacts.js?v=' . (is_file($js) ? filemtime($js) : 0)];
}
