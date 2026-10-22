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

/** O negócio no painel lateral (§19.8): igual ao da lead, mais completo — etapas e gates, proposta, faturação. */
function panel_deal(PDO $pdo, array $d): array {
    require_once __DIR__ . '/pipeline_view.php';
    $js = __DIR__ . '/../assets/tasks.js';
    return ['title' => 'Negócio', 'html' => flow_panel_html($pdo, $d, deal_url($d)), 'url' => deal_url($d), 'wide' => true,
            'script' => '/admin/assets/tasks.js?v=' . (is_file($js) ? filemtime($js) : 0)];
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
