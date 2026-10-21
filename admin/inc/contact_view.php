<?php
/**
 * topper. admin — o contacto por dentro (§13.7)
 *
 * A mesma vista em duas colunas serve o painel lateral (largo) e a ficha
 * completa (contact.php), como nas Leads: à esquerda os dados, a empresa
 * ou as pessoas ligadas e a relação; à direita os serviços e preços, as
 * fontes de negócio, Nova ação, Foco e Notas & Histórico. Quem chama já
 * confirmou que a pessoa pode ver Contactos.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/contacts.php';
require_once __DIR__ . '/lead_view.php';   // anel, redes sociais, «há 2 dias», Nova ação

/** «Ativo» / «Inativo»: botão para os admins, só leitura para os outros. */
function contact_active_html(array $c, bool $admin): string {
    $on = (int)$c['is_active'] === 1;
    $label = $on ? 'Ativo' : 'Inativo';
    if (!$admin) {
        return '<span class="on-dot' . ($on ? '' : ' off') . '" data-tip="Só superadmin e admins alteram" tabindex="0"><i></i>' . $label . '</span>';
    }
    return '<button type="button" class="on-dot btn-dot' . ($on ? '' : ' off') . '" data-active-toggle="' . (int)$c['id'] . '" aria-pressed="' . ($on ? 'true' : 'false') . '"'
         . ' data-tip="' . ($on ? 'Passar a Inativo (deixa de aparecer na Orçamentação)' : 'Passar a Ativo') . '"><i></i>' . $label . '</button>';
}

/** O Foco do contacto: as tarefas abertas ligadas a ele, com o botão concluir. */
function contact_focus_html(array $tasks, bool $edit): string {
    if (!$tasks) return '<p class="muted" style="margin:0">Sem tarefas abertas.</p>';
    $h = '<ul class="focus">';
    foreach ($tasks as $t) {
        [$kindLabel, $kindIcon] = task_kind_info($t['kind'] ?? 'task');
        $h .= '<li data-task="' . (int)$t['id'] . '">'
            . ($edit ? '<button type="button" class="tick" data-cdone="' . (int)$t['id'] . '" data-kind="' . e((string)$t['kind']) . '" aria-label="Concluir «' . e($t['title']) . '»" data-tip>' . icon('check') . '</button>' : '')
            . '<span class="k" data-tip="' . e($kindLabel) . '" aria-label="' . e($kindLabel) . '" role="img" tabindex="0">' . icon($kindIcon) . '</span>'
            . '<span class="t">' . e($t['title']) . ($t['description'] ? '<small>' . e(shorten((string)$t['description'], 90)) . '</small>' : '') . '</span>'
            . '<small class="when' . (task_is_late($t) ? ' late' : '') . '">' . e(lead_task_when($t)) . ($t['repeat_rule'] ? ' ↻' : '') . '<br>' . e($t['who'] ? first_name_of($t['who']) : 'Por atribuir') . '</small></li>';
    }
    return $h . '</ul>';
}

/** Nova ação do contacto (o mesmo formulário das Leads). */
function contact_new_action_html(PDO $pdo, array $c, ?array $rel): string {
    $me = me_row($pdo);
    $people = [];
    foreach (assignable_users($pdo, $me) as $u) $people[(int)$u['id']] = $u['name'];
    $default = $rel && $rel['owner_id'] !== null && isset($people[(int)$rel['owner_id']]) ? (int)$rel['owner_id'] : (int)$me['id'];
    $kinds = '';
    foreach (LEAD_TASK_KINDS as $k => [$label, $ico]) {
        $kinds .= '<label class="kind" data-tip="' . e($label) . '"><input type="radio" name="kind" value="' . $k . '"' . ($k === 'call' ? ' checked' : '') . '>'
                . '<span aria-hidden="true">' . icon($ico) . '</span><span class="sr">' . e($label) . '</span></label>';
    }
    $next = (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->modify('+1 hour');
    $opt = fn(array $o, $sel) => implode('', array_map(fn($k, $v) => '<option value="' . e((string)$k) . '"' . ((string)$k === (string)$sel ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($o), $o));
    return '<form class="newact" data-contact-task autocomplete="off">'
         . '<input type="hidden" name="id" value="' . (int)$c['id'] . '">'
         . '<div class="kinds" role="radiogroup" aria-label="Tipo de ação">' . $kinds . '</div>'
         . '<input type="text" name="title" maxlength="200" placeholder="Ligar a ' . e($c['name']) . '" aria-label="O quê" data-who="' . e($c['name']) . '">'
         . '<textarea name="notes" rows="2" maxlength="4000" placeholder="Notas (opcional)" aria-label="Notas"></textarea>'
         . '<div class="quick" role="group" aria-label="Quando">'
         . '<button type="button" data-quick="1h">Em 1h</button><button type="button" data-quick="3h">Em 3h</button>'
         . '<button type="button" data-quick="tomorrow">Amanhã</button><button type="button" data-quick="week">Próxima semana</button></div>'
         . '<div class="when-row">'
         . '<input type="date" name="date" value="' . $next->format('Y-m-d') . '" aria-label="Data" required>'
         . '<input type="time" name="time" value="' . $next->format('H') . ':00" aria-label="Hora">'
         . '<select name="duration" aria-label="Duração">' . $opt(['15' => '15 min', '30' => '30 min', '45' => '45 min', '60' => '1 h', '90' => '1 h 30', '120' => '2 h'], '30') . '</select></div>'
         . '<div class="when-row">'
         . '<select name="assignee" aria-label="Para quem">' . $opt($people, $default) . '</select>'
         . '<select name="repeat" aria-label="Repetir">' . $opt(TASK_REPEAT, '') . '</select></div>'
         . '<div class="actions"><button class="btn sm" type="submit">' . icon('plus') . ' Criar tarefa</button></div>'
         . '</form>';
}

/** Os campos do contacto num só formulário: «Editar» → «Gravar» (ui_api.php?a=contact_save). */
function contact_edit_form_html(PDO $pdo, array $c): string {
    $id = (int)$c['id'];
    $person = $c['kind'] === 'person';
    $f = fn(string $k) => 'ce-' . $id . '-' . $k;
    $text = fn(string $k, string $label, int $max, array $a = []) =>
        '<div class="field' . (!empty($a['wide']) ? ' wide' : '') . '"' . (!empty($a['only']) ? ' data-only="' . $a['only'] . '"' : '') . (!empty($a['only']) && $a['only'] !== $c['kind'] ? ' hidden' : '') . '>'
        . '<label for="' . $f($k) . '">' . e($label) . '</label>'
        . (!empty($a['area'])
            ? '<textarea id="' . $f($k) . '" name="f[' . $k . ']" rows="' . ($a['rows'] ?? 3) . '" maxlength="' . $max . '"' . (isset($a['ph']) ? ' placeholder="' . e($a['ph']) . '"' : '') . '>' . e((string)($a['value'] ?? $c[$k])) . '</textarea>'
            : '<input type="' . ($a['type'] ?? 'text') . '" id="' . $f($k) . '" name="f[' . $k . ']" maxlength="' . $max . '" value="' . e((string)($a['value'] ?? $c[$k])) . '"'
              . (isset($a['ph']) ? ' placeholder="' . e($a['ph']) . '"' : '') . (isset($a['im']) ? ' inputmode="' . $a['im'] . '"' : '') . (isset($a['list']) ? ' list="' . $a['list'] . '"' : '') . '>')
        . '</div>';
    $types = '';
    foreach (CONTACT_TYPES as $flag => $label) {
        $types .= '<label class="rb"' . ($flag === 'is_freelancer' ? ' data-only="person"' . ($person ? '' : ' hidden') : '') . '><input type="checkbox" name="f[' . $flag . ']" value="1"' . ((int)$c[$flag] === 1 ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
    }
    $people = $c['kind'] === 'company' ? count(contact_people($pdo, $id)) : 0;
    $kind = '<div class="field wide"><span class="lbl">É</span><div class="chips-check" role="radiogroup" aria-label="Pessoa ou empresa">'
          . '<label class="rb"><input type="radio" name="f[kind]" value="person"' . ($person ? ' checked' : '') . ($people ? ' disabled' : '') . '><span>' . icon('user') . ' Pessoa</span></label>'
          . '<label class="rb"><input type="radio" name="f[kind]" value="company"' . (!$person ? ' checked' : '') . '><span>' . icon('building') . ' Empresa</span></label>'
          . ($people ? '<span class="muted small">Tem ' . $people . ($people === 1 ? ' pessoa ligada' : ' pessoas ligadas') . ': fica empresa.</span>' : '')
          . '</div></div>';
    return '<form class="lv-form" data-contact-edit autocomplete="off" hidden>'
         . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="f[types_sent]" value="1">'
         . '<div class="grid">'
         . $kind
         . $text('name', 'Nome', 160)
         . $text('role', 'Cargo', 120, ['only' => 'person'])
         . $text('company', 'Empresa', 160, ['only' => 'person', 'value' => (string)($c['company_name'] ?? ''), 'list' => 'dl-co-' . $id, 'ph' => 'Sem empresa'])
         . $text('phone', 'Telefone(s)', 255, ['ph' => '+351 … (vários: separe com |)', 'im' => 'tel'])
         . $text('email', 'Email', 190, ['im' => 'email'])
         . $text('links', 'Site e redes sociais', 4000, ['area' => true, 'rows' => 2, 'wide' => true, 'ph' => 'Um link por linha (site, Instagram, LinkedIn, Vimeo…)', 'value' => (string)$c['links']])
         . $text('nif', 'NIF', 20, ['im' => 'numeric'])
         . $text('address', 'Morada', 255)
         . $text('birthday', 'Aniversário', 10, ['only' => 'person', 'type' => 'date', 'value' => (string)$c['birthday']])
         . '<div class="field wide"><span class="lbl">Tipo</span><div class="chips-check" role="group" aria-label="Tipo">' . $types . '</div></div>'
         . $text('notes', 'Notas', 8000, ['area' => true, 'wide' => true, 'rows' => 4, 'value' => (string)$c['notes']])
         . '</div>'
         . '<datalist id="dl-co-' . $id . '"></datalist>'
         . '<div class="lv-foot lv-edit-foot"><button type="button" class="btn ghost" data-cedit-cancel>Cancelar</button>'
         . '<span class="spacer"></span><span class="muted kbd-hint">Esc cancela · ⌘/Ctrl+Enter grava</span>'
         . '<button type="submit" class="btn">' . icon('check') . ' Gravar</button></div>'
         . '</form>';
}

/** Serviços e preços (fornecedores e freelancers), com o formulário de linha e o histórico. */
function contact_services_html(PDO $pdo, array $c, bool $edit): string {
    $id = (int)$c['id'];
    $lines = contact_service_lines($pdo, $id);
    $all = contact_service_lines($pdo, $id, true);
    $old = array_values(array_filter($all, fn($r) => $r['ended_at'] !== null));
    $rows = '';
    foreach ($lines as $r) {
        $data = ' data-line="' . (int)$r['id'] . '" data-service="' . (int)$r['service_id'] . '" data-unit="' . e((string)$r['unit']) . '" data-price="'
              . e($r['price'] === null ? '' : rtrim(rtrim(number_format((float)$r['price'], 2, ',', ''), '0'), ',')) . '" data-vat="' . (int)$r['vat'] . '" data-from="'
              . e((string)$r['valid_from']) . '" data-notes="' . e((string)$r['notes']) . '"';
        $rows .= '<tr' . $data . '><td><b>' . e(service_line_label($r)) . '</b>' . ($r['notes'] ? '<small>' . e(shorten((string)$r['notes'], 80)) . '</small>' : '') . '</td>'
               . '<td>' . e($r['unit'] ? (SERVICE_UNITS[$r['unit']] ?? $r['unit']) : '—') . '</td>'
               . '<td class="num' . ($r['price'] === null ? ' muted' : '') . '">' . e($r['price'] === null ? 'por rever' : number_format((float)$r['price'], (float)$r['price'] == floor((float)$r['price']) ? 0 : 2, ',', ' ') . ' €') . '</td>'
               . '<td>' . ((int)$r['vat'] ? 'sim' : 'não') . '</td>'
               . '<td class="nowrap act">' . ($edit
                    ? icon_button('pencil', 'Alterar «' . service_line_label($r) . '»', ['data-svc-edit' => (string)$r['id']])
                    . icon_button('x', 'Tirar «' . service_line_label($r) . '»', ['data-svc-del' => (string)$r['id']]) : '') . '</td></tr>';
    }
    $svcOpts = '<option value="">Escolher…</option>';
    foreach (services_list($pdo) as $s) $svcOpts .= '<option value="' . (int)$s['id'] . '">' . e($s['name']) . '</option>';
    $unitOpts = '';
    foreach (SERVICE_UNITS as $k => $u) $unitOpts .= '<option value="' . $k . '"' . ($k === 'day' ? ' selected' : '') . '>' . e($u) . '</option>';
    $form = !$edit ? '' :
        '<form class="svc-form" data-svc-form hidden autocomplete="off">'
      . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="line" value="">'
      . '<div class="svc-grid">'
      . '<label>Serviço<select name="service_id" required>' . $svcOpts . '</select></label>'
      . '<label>Unidade<select name="unit" required>' . $unitOpts . '</select></label>'
      . '<label>Preço s/ IVA (€)<input type="text" name="price" inputmode="decimal" maxlength="14" placeholder="280"></label>'
      . '<label class="chk"><input type="checkbox" name="vat" value="1" checked> Cobra IVA</label>'
      . '<label>Válido desde<input type="date" name="valid_from" value="' . e(today_lisbon()) . '"></label>'
      . '<label class="w">Notas<input type="text" name="notes" maxlength="2000" placeholder="Mínimo de 2 dias, inclui render…"></label>'
      . '</div><p class="muted small" data-svc-why hidden>Mudar o preço, a unidade ou o IVA guarda o preço antigo no histórico.</p>'
      . '<div class="actions"><button type="button" class="btn ghost sm" data-svc-cancel>Cancelar</button><button type="submit" class="btn sm">' . icon('check') . ' Gravar</button></div>'
      . '</form>';
    $hist = '';
    foreach ($old as $r) {
        $hist .= '<li><b>' . e(service_line_label($r)) . '</b> · ' . e(service_line_price($r)) . ($r['vat'] ? '' : ' · sem IVA')
               . ' <span class="muted mono">' . e($r['valid_from'] ? date('d/m/Y', strtotime((string)$r['valid_from'])) : '?') . ' → ' . e(date('d/m/Y', strtotime((string)$r['ended_at']))) . '</span></li>';
    }
    return '<section class="lv-blk cv-svc" data-services="' . $id . '">'
         . '<h4>Serviços e preços <span class="h-sub">usados na Orçamentação</span></h4>'
         . ($lines ? '<table class="svc-t"><thead><tr><th>Serviço</th><th>Unidade</th><th class="num">Preço s/ IVA</th><th>IVA</th><th><span class="sr">Ações</span></th></tr></thead><tbody>' . $rows . '</tbody></table>'
                   : '<p class="muted" style="margin:0">Ainda sem serviços. ' . ((int)$c['is_active'] === 1 ? 'Sem eles não aparece na calculadora da Orçamentação.' : '') . '</p>')
         . $form
         . '<div class="svc-acts">' . ($edit ? '<button type="button" class="lnk" data-svc-add>' . icon('plus') . ' Adicionar serviço</button>' : '')
         . ($old ? '<button type="button" class="lnk" data-svc-hist aria-expanded="false">Histórico de preços (' . count($old) . ')</button>' : '') . '</div>'
         . ($old ? '<ul class="svc-hist" hidden>' . $hist . '</ul>' : '')
         . '</section>';
}

/** Fontes de negócio (§13.4): o que veio deste contacto (e da empresa / das pessoas dela). */
function contact_sources_html(PDO $pdo, array $c): string {
    $s = contact_sources($pdo, (int)$c['id']);
    $rows = '';
    foreach ($s['rows'] as $d) {
        $isLead = in_array($d['phase'], ['lead', 'archived'], true);
        $key = ($isLead ? 'lead:' : 'deal:') . (int)$d['id'];
        $href = $isLead ? '/admin/lead.php?id=' . (int)$d['id'] : deal_url($d);
        $client = $d['client_name'] ?: ($d['company'] ?: lead_display_name($d));
        $val = in_array($d['phase'], ['production', 'done'], true) ? (float)$d['budget'] : ($d['value_estimate'] !== null ? (float)$d['value_estimate'] : null);
        $state = source_state($pdo, $d);
        $cls = match ($d['phase']) { 'done', 'production' => 'ok', 'lost', 'archived' => 'no', 'lead' => 'info', default => '' };
        $rows .= '<tr data-panel="' . $key . '"><td><a href="' . e($href) . '" data-panel="' . $key . '"><b>' . e($client) . '</b></a>'
               . ' <span class="muted">· ' . e(($d['code'] ? $d['code'] . ' ' : '') . ($isLead ? lead_display_name($d) : $d['name'])) . '</span></td>'
               . '<td><span class="chip ' . $cls . '">' . e($state) . '</span></td>'
               . '<td class="num">' . ($val ? e(eur0($val)) : '<span class="muted">—</span>') . '</td></tr>';
    }
    $who = $c['kind'] === 'person' && !empty($c['company_name']) ? $c['name'] . ' / ' . $c['company_name'] : $c['name'];
    $newLead = can('leads', PERM_EDIT)
        ? '<a class="lnk" href="/admin/leads.php?nova=1&amp;trazido=' . (int)$c['id'] . '">' . icon('plus') . ' Nova lead trazida por ' . e(first_name_of($c['name'])) . '</a>' : '';
    if (!$s['rows']) {
        return '<section class="lv-blk cv-src"><h4>Fontes de negócio</h4><p class="muted" style="margin:0 0 6px">Nenhum negócio trazido por este contacto.</p>' . $newLead . '</section>';
    }
    return '<section class="lv-blk cv-src"><h4>Fontes de negócio <span class="h-sub">trazidos por ' . e($who) . '</span></h4>'
         . '<div class="mini3"><div><small>Gerados</small><b>' . $s['generated'] . '</b></div>'
         . '<div><small>Faturado</small><b>' . e(eur0($s['billed'])) . '</b></div>'
         . '<div><small>Conversão</small><b>' . ($s['conversion'] === null ? '—' : $s['conversion'] . '%') . '</b></div></div>'
         . '<table class="src-t"><thead><tr><th>Cliente · negócio</th><th>Estado</th><th class="num">Valor</th></tr></thead><tbody>' . $rows . '</tbody></table>'
         . '<p class="muted small" style="margin:6px 0">Abrir uma linha mostra o histórico desse negócio.</p>' . $newLead
         . '</section>';
}

/** Nível A/B/C. */
function rel_level_badge(string $lvl): string {
    return '<span class="lvl lvl-' . e(strtolower($lvl)) . '" data-tip="Nível ' . e($lvl) . ' (automático)" aria-label="Nível ' . e($lvl) . '" tabindex="0">' . e($lvl) . '</span>';
}

/** A relação (§13.5): nível, saúde, próxima data, sugestão, revisão e as ações. */
function contact_relation_html(PDO $pdo, array $c, ?array $r, bool $edit): string {
    $id = (int)$c['id'];
    if (!$r) {
        return $edit ? '<section class="lv-blk cv-rel off"><h4>Relação</h4><p class="muted" style="margin:0 0 8px">Ainda não é acompanhada. Com nível e cadência, os follow-ups aparecem sozinhos no Foco de quem a acompanha.</p>'
                     . '<button type="button" class="btn ghost sm" data-rel-open="' . $id . '">' . icon('heart') . ' Acompanhar relação</button></section>' : '';
    }
    $cad = relation_cadence($pdo, $r);
    $paused = $r['paused_until'] && $r['paused_until'] > today_lisbon();
    $h = relation_health($pdo, $r);
    $json = e(json_encode(['formality' => $r['formality'], 'potential' => (int)$r['potential'], 'business' => $r['business'],
                           'owner_id' => $r['owner_id'] !== null ? (int)$r['owner_id'] : '', 'cadence_days' => $r['cadence_days'] !== null ? (int)$r['cadence_days'] : ''], JSON_UNESCAPED_UNICODE));
    if ($paused) {
        $when = '<span class="chip">' . icon('pause') . ' Pausada até ' . e(lisbon_fmt(lisbon_ts($r['paused_until'] . ' 12:00'), 'd/m/Y')) . '</span>';
    } else {
        $days = $r['next_at'] ? (int)(new DateTimeImmutable(today_lisbon()))->diff(new DateTimeImmutable($r['next_at']))->format('%r%a') : null;
        $txt = $days === null ? '' : ($days > 0 ? 'próximo toque em ' . $days . ' d' : ($days === 0 ? 'próximo toque hoje' : 'toque em atraso há ' . -$days . ' d'));
        $when = lead_ring($h, false) . ' <span>' . e($txt . ' · a cada ' . $cad . ' d' . ($r['cadence_days'] ? ' (exceção)' : '')) . '</span>';
    }
    $review = !$paused && $edit ? relation_review($pdo, $r) : null;
    $rev = '';
    if ($review) {
        $lvl = relation_level($r['formality'], $review['potential'], $r['business']);
        $prop = match ($review['dir']) {
            'up' => 'propõe subir o potencial para ' . $review['potential'] . ' (nível ' . $lvl . ')',
            'down' => 'propõe descer o potencial para ' . $review['potential'] . ' (nível ' . $lvl . ')',
            default => 'propõe manter o nível ' . $r['level'],
        };
        $rev = '<div class="rel-review"><b>' . icon('arrows') . ' Revisão de nível (6 meses)</b><p>' . e(ucfirst($review['why']) . ': ' . $prop . '.') . '</p>'
             . '<div class="actions">' . ($review['dir'] !== 'keep' ? '<button type="button" class="btn sm" data-rel-review="accept" data-potential="' . (int)$review['potential'] . '">Aceitar</button>' : '')
             . '<button type="button" class="btn ghost sm" data-rel-review="keep">' . ($review['dir'] === 'keep' ? 'Confirmar' : 'Manter') . '</button></div></div>';
    }
    $last = $r['last_touch_at'] ? 'último toque ' . lead_ago((string)$r['last_touch_at']) : 'ainda sem toques';
    return '<section class="lv-blk cv-rel" data-rel="' . $id . '" data-rel-json="' . $json . '" data-cadence="' . $cad . '">'
         . '<h4>Relação</h4>'
         . '<div class="rel-top">' . rel_level_badge((string)$r['level']) . '<span>' . e(REL_FORMALITY[$r['formality']] . ' · potencial ' . $r['potential'] . ' · ' . REL_BUSINESS[$r['business']]) . '</span></div>'
         . '<div class="rel-when">' . $when . '</div>'
         . '<p class="muted small" style="margin:0">' . e(($r['owner_name'] ? 'Acompanhada por ' . $r['owner_name'] : 'Sem responsável') . ' · ' . (int)$r['touches'] . ((int)$r['touches'] === 1 ? ' toque' : ' toques') . ' · ' . $last) . '</p>'
         . (!$paused ? '<p class="rel-sug">' . icon('star') . ' ' . e(relation_suggestion($pdo, $r)) . '</p>' : '')
         . $rev
         . ($edit ? '<div class="rel-acts">'
                  . '<button type="button" class="btn ghost sm" data-rel-open="' . $id . '">' . icon('pencil') . ' Alterar</button>'
                  . ($paused ? '<button type="button" class="btn ghost sm" data-rel-resume="' . $id . '">' . icon('refresh') . ' Retomar</button>'
                             : '<button type="button" class="btn ghost sm" data-rel-pause="' . $id . '">' . icon('pause') . ' Pausar</button>')
                  . '<button type="button" class="lnk del" data-rel-stop="' . $id . '">Deixar de acompanhar</button></div>' : '')
         . '</section>';
}

/** A empresa (numa pessoa) ou as pessoas (numa empresa), com telefones e emails. */
function contact_linked_html(PDO $pdo, array $c, bool $edit): string {
    $id = (int)$c['id'];
    $line = function (array $p) {
        $bits = array_filter([(string)$p['role'], lead_phones((string)$p['phone'])[0] ?? (string)$p['phone'], (string)$p['email']], 'strlen');
        return '<li><a href="/admin/contact.php?id=' . (int)$p['id'] . '" data-panel="contact:' . (int)$p['id'] . '">' . e($p['name']) . '</a>'
             . ($bits ? ' <span class="muted">· ' . e(implode(' · ', $bits)) . '</span>' : '') . '</li>';
    };
    if ($c['kind'] === 'company') {
        $people = contact_people($pdo, $id);
        $add = $edit ? '<a class="lnk" href="/admin/contactos.php?novo=pessoa&amp;empresa=' . $id . '">' . icon('plus') . ' Adicionar pessoa</a>' : '';
        return '<section><h4>Pessoas <span class="mono">' . count($people) . '</span></h4>'
             . ($people ? '<ul class="ppl">' . implode('', array_map($line, $people)) . '</ul>' : '<p class="muted" style="margin:0 0 4px">Ainda sem pessoas.</p>') . $add . '</section>';
    }
    if (!$c['company_id']) return '';
    $co = contact_get($pdo, (int)$c['company_id']);
    if (!$co) return '';
    $others = array_filter(contact_people($pdo, (int)$co['id']), fn($p) => (int)$p['id'] !== $id);
    return '<section><h4>Empresa</h4><p style="margin:0"><a href="/admin/contact.php?id=' . (int)$co['id'] . '" data-panel="contact:' . (int)$co['id'] . '"><b>' . e($co['name']) . '</b></a>'
         . (contact_types($co) ? ' <span class="muted">· ' . e(implode(', ', contact_types($co))) . '</span>' : '') . '</p>'
         . ($others ? '<ul class="ppl">' . implode('', array_map($line, $others)) . '</ul>' : '') . '</section>';
}

/**
 * A vista completa. $mode 'panel' (painel lateral) ou 'page' (contact.php).
 * $back: para onde voltar depois de eliminar ou juntar sem JavaScript.
 */
function contact_view(PDO $pdo, array $c, string $mode = 'panel', string $back = '/admin/contactos.php'): string {
    $id = (int)$c['id'];
    $edit = can('contactos', PERM_EDIT);
    $admin = contacts_admin($pdo);
    $person = $c['kind'] === 'person';
    $company = $person && $c['company_id'] ? contact_get($pdo, (int)$c['company_id']) : null;
    $types = contact_types($c, $company);
    $prov = contact_is_provider($c);
    $rel = relation_get($pdo, $id);
    $tasks = contact_open_tasks($pdo, $id);

    $dial = lead_phone_dial((string)$c['phone']);
    $wa = ltrim($dial, '+');
    $acts = '<div class="lv-acts">'
          . ($dial !== '' ? '<a class="btn ghost sm" href="tel:' . e($dial) . '">' . icon('phone') . ' Ligar</a>' : '<span class="btn ghost sm off" aria-disabled="true">' . icon('phone') . ' Ligar</span>')
          . ($wa !== '' ? '<a class="btn ghost sm" href="https://wa.me/' . e($wa) . '" target="_blank" rel="noopener noreferrer">' . icon('whatsapp') . ' WhatsApp</a>' : '<span class="btn ghost sm off" aria-disabled="true">' . icon('whatsapp') . ' WhatsApp</span>')
          . ($c['email'] ? '<a class="btn ghost sm" href="mailto:' . e($c['email']) . '">' . icon('mail') . ' Email</a>' : '<span class="btn ghost sm off" aria-disabled="true">' . icon('mail') . ' Email</span>')
          . '</div>';

    $phones = lead_phones((string)$c['phone']) ?: array_filter([(string)$c['phone']], 'strlen');
    $kv = '';
    $row = function (string $label, string $html) use (&$kv) { if ($html !== '') $kv .= '<span>' . e($label) . '</span><div>' . $html . '</div>'; };
    $row('Telefone(s)', implode('<br>', array_map(fn($p) => '<a href="tel:' . e(lead_phone_dial($p)) . '">' . e($p) . '</a>', $phones)));
    $row('Email', $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '');
    $row('Site e redes', lead_links_html((string)$c['links']));
    if ($person) $row('Cargo', e((string)$c['role']));
    $row('NIF', $c['nif'] !== '' ? '<span class="mono">' . e($c['nif']) . '</span>' : '');
    $row('Morada', e((string)$c['address']));
    if ($person && $c['birthday']) $row('Aniversário', '<span class="mono">' . e(date('d/m', strtotime((string)$c['birthday']))) . '</span>');
    if ($prov) { [$avg, $n] = contact_rating($pdo, $id); $row('Avaliação', rating_html($avg, $n)); }
    $row('Criado', '<span class="mono">' . e(lisbon_fmt(strtotime((string)$c['created_at']), 'd/m/Y')) . '</span>');

    $sub = $person ? contact_sub($c) . ($c['role'] !== '' ? ' · ' . $c['role'] : '') : 'Empresa';
    $chips = implode(' ', array_map(fn($t) => '<span class="chip">' . e($t) . '</span>', $types));
    $head = '<header class="lv-head">'
          . ($edit ? '<button type="button" class="btn ghost sm lv-edit-btn" data-cedit-start aria-keyshortcuts="E" data-tip="Editar o contacto  ·  E">' . icon('pencil') . ' Editar</button>' : '')
          . '<h2 class="lv-name">' . e($c['name']) . '</h2>'
          . '<div class="lv-co">' . ($person && $company ? '<a href="/admin/contact.php?id=' . (int)$company['id'] . '" data-panel="contact:' . (int)$company['id'] . '">' . e($company['name']) . '</a>' . ($c['role'] !== '' ? ' · ' . e($c['role']) : '') : e($sub)) . '</div>'
          . '<div class="lv-chips">' . $chips . ($prov ? ' ' . contact_active_html($c, $admin) : '') . '</div>'
          . '</header>';

    $dups = $edit ? contact_duplicates($pdo, $c) : [];
    $dupHtml = '';
    if ($dups) {
        $li = '';
        foreach ($dups as $d) {
            $li .= '<li><a href="/admin/contact.php?id=' . (int)$d['id'] . '" data-panel="contact:' . (int)$d['id'] . '">' . e($d['name']) . '</a>'
                 . (can('contactos', PERM_MANAGE) ? ' <button type="button" class="lnk" data-merge="' . (int)$d['id'] . '" data-merge-name="' . e($d['name']) . '">Juntar</button>' : '') . '</li>';
        }
        $dupHtml = '<div class="dup">' . '<b>' . icon('alert') . ' Possível duplicado (mesmo email ou telefone)</b><ul>' . $li . '</ul>'
                 . (can('contactos', PERM_MANAGE) ? '' : '<span class="small">Peça a um gestor para os juntar.</span>') . '</div>';
    }

    $foot = '';
    if (can('contactos', PERM_MANAGE)) $foot .= '<a class="lnk del" href="/admin/contactos.php?del=' . $id . '">Eliminar</a>';

    $left = '<div class="lv-l">' . $head . $acts . $dupHtml
          . '<div class="lv-read">'
          . '<div class="kv">' . $kv . '</div>'
          . contact_linked_html($pdo, $c, $edit)
          . ($c['notes'] !== null && trim((string)$c['notes']) !== '' ? '<section><h4>Notas</h4><div class="pre">' . nl2br(e((string)$c['notes'])) . '</div></section>' : '')
          . contact_relation_html($pdo, $c, $rel, $edit)
          . ($foot !== '' ? '<div class="lv-foot">' . $foot . '</div>' : '')
          . '</div>'
          . ($edit ? contact_edit_form_html($pdo, $c) : '')
          . '</div>';

    $right = '<div class="lv-r">'
           . ($prov ? contact_services_html($pdo, $c, $edit) : '')
           . contact_sources_html($pdo, $c)
           . ($edit ? '<section class="lv-blk"><h4>Nova ação</h4>' . contact_new_action_html($pdo, $c, $rel) . '</section>' : '')
           . '<section class="lv-blk"><h4>Foco <span class="mono" data-focus-n>' . count($tasks) . '</span></h4><div class="lv-focus">' . contact_focus_html($tasks, $edit) . '</div></section>'
           . '<section class="lv-blk lv-hist"><h4>Notas &amp; Histórico</h4>' . history_block($pdo, 'contact', $id, $edit, $back, '', ['note']) . '</section>'
           . '</div>';

    return '<div class="lv cv lv-' . e($mode) . '" data-contact="' . $id . '" data-name="' . e($c['name']) . '" data-kind="' . e($c['kind']) . '">' . $left . $right . '</div>';
}

/**
 * Os formulários por cima da ficha: Acompanhar relação, Pausar, «Como correu?» de um
 * follow-up de relação e Juntar duplicados. Preenchidos pelo contacts.js para o contacto aberto.
 */
function contact_modals_html(PDO $pdo): string {
    $me = me_row($pdo);
    $owners = '<option value="">— Sem responsável —</option>';
    foreach (assignable_users($pdo, $me) as $u) $owners .= '<option value="' . (int)$u['id'] . '"' . ((int)$u['id'] === (int)$me['id'] ? ' selected' : '') . '>' . e($u['name']) . '</option>';
    $radio = function (string $name, array $opts) {
        $h = '';
        foreach ($opts as $k => $l) $h .= '<label class="rb"><input type="radio" name="' . $name . '" value="' . e((string)$k) . '" required><span>' . e($l) . '</span></label>';
        return $h;
    };
    $cad = [];
    foreach (REL_CADENCE_DEFAULTS as $l => $d) $cad[$l] = max(1, (int)lead_setting($pdo, 'rel_cadence_' . strtolower($l), $d));
    $soon = (new DateTimeImmutable(today_lisbon()))->modify('+1 month')->format('Y-m-d');
    return
      '<div class="mdl" id="mdl-rel" role="dialog" aria-modal="true" aria-labelledby="mdl-rel-t" hidden>'
    . '<div class="mdl-back" data-mdl-close></div>'
    . '<form class="mdl-card" data-rel-form autocomplete="off" data-cad=\'' . e(json_encode($cad)) . '\'>'
    . '<input type="hidden" name="id" value="">'
    . '<h3 id="mdl-rel-t">Acompanhar relação</h3><p class="muted" data-rel-name></p>'
    . '<div class="field"><span class="lbl">Formalidade</span><div class="chips-check" role="radiogroup" aria-label="Formalidade">' . $radio('formality', REL_FORMALITY) . '</div></div>'
    . '<div class="field"><span class="lbl">Potencial</span><div class="chips-check" role="radiogroup" aria-label="Potencial (1 a 5)">' . $radio('potential', [1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5']) . '</div></div>'
    . '<div class="field"><span class="lbl">Tipo de negócio</span><div class="chips-check" role="radiogroup" aria-label="Tipo de negócio">' . $radio('business', REL_BUSINESS) . '</div></div>'
    . '<div class="grid">'
    . '<div class="field"><label for="rel-owner">Responsável da relação</label><select id="rel-owner" name="owner_id">' . $owners . '</select></div>'
    . '<div class="field"><label for="rel-cad">Exceção de cadência (dias)</label><input type="number" id="rel-cad" name="cadence_days" min="1" max="365" placeholder="do nível"></div>'
    . '</div>'
    . '<p class="rel-calc" data-rel-calc aria-live="polite"></p>'
    . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><button class="btn" type="submit">' . icon('heart') . ' Gravar</button></div>'
    . '</form></div>'

    . '<div class="mdl" id="mdl-relpause" role="dialog" aria-modal="true" aria-labelledby="mdl-relpause-t" hidden>'
    . '<div class="mdl-back" data-mdl-close></div>'
    . '<form class="mdl-card" data-relpause-form><input type="hidden" name="id" value="">'
    . '<h3 id="mdl-relpause-t">Pausar relação</h3><p class="muted">Os follow-ups param até à data de regresso; nesse dia o toque volta ao Foco.</p>'
    . '<div class="field"><label for="rel-until">Regressa a</label><input type="date" id="rel-until" name="until" value="' . e($soon) . '" min="' . e((new DateTimeImmutable(today_lisbon()))->modify('+1 day')->format('Y-m-d')) . '" required></div>'
    . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><button class="btn" type="submit">' . icon('pause') . ' Pausar</button></div>'
    . '</form></div>'

    . '<div class="mdl" id="mdl-rout" role="dialog" aria-modal="true" aria-labelledby="mdl-rout-t" hidden>'
    . '<div class="mdl-back" data-mdl-close></div>'
    . '<div class="mdl-card">'
    . '<h3 id="mdl-rout-t">Como correu? · Follow-up de relação</h3><p class="muted" data-rout-name></p>'
    . '<div class="out-step" data-step="choose"><div class="opts">'
    . '<button type="button" class="opt" data-rout="talked">' . icon('bubble') . '<b>Falámos</b><small>nota + próxima data</small></button>'
    . '<button type="button" class="opt" data-rout="missed">' . icon('phone-off') . '<b>Não atendeu</b><small>nova tentativa em 2 dias</small></button>'
    . '<button type="button" class="opt" data-rout="meeting">' . icon('meeting') . '<b>Reunião marcada</b><small>cria a tarefa</small></button>'
    . '<button type="button" class="opt cv" data-rout="lead">' . icon('arrow') . '<b>Gerou uma lead</b><small>abre «+ Adicionar Lead» com «Trazido por»</small></button>'
    . '</div></div>'
    . '<form class="out-step" data-step="talked" hidden><textarea name="note" rows="3" maxlength="4000" placeholder="Nota rápida da conversa" aria-label="Nota da conversa"></textarea>'
    . '<div class="field"><label for="rout-next">Próximo toque</label><input type="date" id="rout-next" name="next" required></div>'
    . '<div class="actions"><button class="btn" type="submit">' . icon('check') . ' Gravar</button></div></form>'
    . '<form class="out-step" data-step="meeting" hidden><div class="grid">'
    . '<div class="field"><label for="rout-md">Dia</label><input type="date" id="rout-md" name="date" required></div>'
    . '<div class="field"><label for="rout-mt">Hora</label><input type="time" id="rout-mt" name="time" value="10:00"></div></div>'
    . '<textarea name="note" rows="2" maxlength="4000" placeholder="Onde / assunto (opcional)" aria-label="Notas da reunião"></textarea>'
    . '<div class="actions"><button class="btn" type="submit">' . icon('meeting') . ' Criar a reunião</button></div></form>'
    . '<div class="actions out-foot"><button type="button" class="btn ghost" data-mdl-close>Fechar</button></div>'
    . '</div></div>'

    . '<div class="mdl" id="mdl-merge" role="dialog" aria-modal="true" aria-labelledby="mdl-merge-t" hidden>'
    . '<div class="mdl-back" data-mdl-close></div>'
    . '<form class="mdl-card" data-merge-form><input type="hidden" name="id" value=""><input type="hidden" name="other" value="">'
    . '<h3 id="mdl-merge-t">Juntar duplicados</h3>'
    . '<p data-merge-text></p>'
    . '<p class="muted small">Os negócios, leads, custos, tarefas, serviços, avaliações e o histórico dos dois ficam neste contacto; os campos vazios são preenchidos com os do outro. Isto não se anula.</p>'
    . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><button class="btn" type="submit">' . icon('merge') . ' Juntar</button></div>'
    . '</form></div>';
}
