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
    $def = inline_fields()[$entity][$field] ?? ['text', 0];
    $val = $row[$field] ?? '';
    $shown = $opt['html'] ?? e(inline_display($entity, $field, $val));
    $o = ['edit' => $edit, 'type' => $def[0], 'label' => 'Editar ' . mb_strtolower($label), 'value' => (string)$val];
    if ($def[0] === 'select') $o['options'] = $def[1];
    if ($def[0] === 'money' && $val !== null && $val !== '') $o['value'] = number_format((float)$val, 2, ',', '');
    return '<span>' . e($label) . '</span><div>' . inline_field($entity, $id, $field, $shown, $o) . '</div>';
}

function panel_lead(PDO $pdo, array $l): array {
    $id = (int)$l['id'];
    $edit = can('leads', PERM_EDIT);
    $active = $l['phase'] === 'lead';
    $owner = $l['owner_id'] ? (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$l['owner_id'])->fetchColumn() : 'Por atribuir';
    $stale = $active && lead_is_stale($l, lead_stale_days($pdo));

    $chips = '<span class="chip">' . e($active ? (LEAD_STATE[$l['lead_state']] ?? '') : 'Arquivada') . '</span>'
           . ($l['priority'] === 'alta' ? ' <span class="chip no">Alta</span>' : '')
           . ($stale ? ' <span class="chip risk">Parada</span>' : '')
           . ((int)$l['is_quarantined'] ? ' <span class="chip wait">Quarentena</span>' : '');

    $next = '';
    if ($edit && $active) {
        if (can('pipeline', PERM_EDIT) && !(int)$l['is_quarantined']) {
            $next .= '<a class="btn" href="/admin/leads.php?conv=' . $id . '#converter">' . icon('arrow') . ' Converter em negócio</a>';
        }
        $next .= '<a class="btn ghost" href="/admin/leads.php?arch=' . $id . '#arquivar">' . icon('archive') . ' Arquivar</a>';
    } elseif ($edit) {
        $next .= '<form method="post" action="/admin/leads.php">' . csrf_field()
               . '<input type="hidden" name="do" value="reactivate"><input type="hidden" name="id" value="' . $id . '">'
               . '<button class="btn ghost" type="submit">' . icon('undo') . ' Reativar</button></form>';
    }
    $next .= '<a class="btn ghost" href="/admin/leads.php?edit=' . $id . '#lead">' . icon('external') . ' Abrir a ficha completa</a>';

    $ed = $edit && $active;
    $kv = panel_row('Estado', 'lead', $id, 'lead_state', $l, $ed)
        . '<span>Responsável</span><div>' . e($owner) . '</div>'
        . panel_row('Email', 'lead', $id, 'email', $l, $ed, $l['email'] ? ['html' => '<a href="mailto:' . e($l['email']) . '">' . e($l['email']) . '</a>'] : [])
        . panel_row('Telefone', 'lead', $id, 'phone', $l, $ed, $l['phone'] ? ['html' => '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $l['phone'])) . '">' . e($l['phone']) . '</a>'] : [])
        . panel_row('Serviço', 'lead', $id, 'service', $l, $ed)
        . panel_row('Prioridade', 'lead', $id, 'priority', $l, $ed)
        . panel_row('Origem', 'lead', $id, 'origin', $l, $ed)
        . panel_row('Campanha', 'lead', $id, 'campaign', $l, $ed)
        . panel_row('Último contacto', 'lead', $id, 'last_touch', $l, $ed)
        . '<span>Criada</span><div class="mono">' . e(date('d/m/Y', strtotime((string)$l['created_at']))) . '</div>'
        . ($l['types'] ? '<span>Tipo</span><div>' . e($l['types']) . '</div>' : '');

    $body = '<header><h2>' . inline_field('lead', $id, 'name', e($l['name']), ['edit' => $ed, 'label' => 'Editar nome', 'value' => $l['name']]) . '</h2>'
          . '<div style="margin-top:6px">' . $chips . '</div></header>'
          . '<section class="sp-next"><h4>Próxima ação</h4>' . $next . '</section>'
          . '<section><h4>Resumo</h4><div class="kv">' . $kv . '</div></section>'
          . '<section><h4>Notas</h4>' . inline_field('lead', $id, 'notes', e((string)$l['notes']), ['edit' => $ed, 'type' => 'textarea', 'label' => 'Editar notas', 'value' => (string)$l['notes']]) . '</section>'
          . '<section><h4>Porquê abordar</h4>' . inline_field('lead', $id, 'why', e((string)$l['why']), ['edit' => $ed, 'type' => 'textarea', 'label' => 'Editar porquê abordar', 'value' => (string)$l['why']]) . '</section>'
          . '<section><h4>Histórico</h4>' . history_block($pdo, 'deal', $id, $edit, '/admin/leads.php?open=' . $id, '') . '</section>';
    return ['title' => 'Lead', 'html' => $body, 'url' => '/admin/leads.php?edit=' . $id . '#lead'];
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

    $kv = '<span>Funil · etapa</span><div>' . e(($funnel['name'] ?? '—') . ' · ' . ($stage['name'] ?? '—')) . '</div>'
        . '<span>Responsável</span><div>' . e($owner) . '</div>'
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

function panel_contact(PDO $pdo, array $c): array {
    $id = (int)$c['id'];
    $edit = can('contactos', PERM_EDIT);
    $types = array_filter(['is_client' => 'Cliente', 'is_supplier' => 'Fornecedor', 'is_partner' => 'Parceiro', 'is_freelancer' => 'Freelancer'],
                          fn($k) => (int)($c[$k] ?? 0) === 1, ARRAY_FILTER_USE_KEY);
    $st = $pdo->prepare('SELECT * FROM deals WHERE code IS NOT NULL AND (contact_id = ? OR id IN (SELECT deal_id FROM deal_contacts WHERE contact_id = ?)) ORDER BY code DESC');
    $st->execute([$id, $id]);
    $deals = '';
    foreach ($st->fetchAll() as $d) {
        if (!record_reach($pdo, $d)) continue;
        $deals .= '<li><a href="' . e(deal_url($d)) . '"><span><span class="id">' . e($d['code']) . '</span> ' . e($d['name']) . '</span><small>'
                . e(['pipeline' => 'comercial', 'production' => 'produção', 'done' => 'concluído', 'lost' => 'perdido'][$d['phase']] ?? '') . '</small></a></li>';
    }
    $kv = '';
    foreach (['contact_person' => 'Pessoa', 'email' => 'Email', 'phone' => 'Telefone', 'nif' => 'NIF', 'website' => 'Site', 'service' => 'Serviço', 'rate' => 'Tarifa'] as $f => $label) {
        $kv .= panel_row($label, 'contact', $id, $f, $c, $edit);
    }
    $body = '<header><h2>' . inline_field('contact', $id, 'name', e($c['name']), ['edit' => $edit, 'label' => 'Editar nome', 'value' => $c['name']]) . '</h2>'
          . '<div style="margin-top:6px">' . implode(' ', array_map(fn($t) => '<span class="chip">' . e($t) . '</span>', $types)) . '</div></header>'
          . '<section class="sp-next"><h4>Próxima ação</h4><a class="btn ghost" href="/admin/contactos.php?edit=' . $id . '#contacto">' . icon('external') . ' Abrir a ficha completa</a></section>'
          . '<section><h4>Resumo</h4><div class="kv">' . $kv . '</div></section>'
          . ($deals !== '' ? '<section class="today-col"><h3>Negócios</h3><ul>' . $deals . '</ul></section>' : '')
          . '<section><h4>Notas</h4>' . inline_field('contact', $id, 'notes', e((string)$c['notes']), ['edit' => $edit, 'type' => 'textarea', 'label' => 'Editar notas', 'value' => (string)$c['notes']]) . '</section>'
          . '<section><h4>Histórico</h4>' . history_block($pdo, 'contact', $id, $edit, '/admin/contactos.php?edit=' . $id . '#contacto', '') . '</section>';
    return ['title' => 'Contacto', 'html' => $body, 'url' => '/admin/contactos.php?edit=' . $id . '#contacto'];
}
