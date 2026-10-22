<?php
/**
 * topper. admin — a lead por dentro (§12.5–12.9)
 *
 * A mesma vista em duas colunas serve o painel lateral (largo) e a ficha
 * completa (lead.php): à esquerda os dados da lead e do contacto, com
 * edição no campo e Converter / Arquivar fixos no fundo; à direita Nova
 * ação, Foco (tarefas abertas) e Notas & Histórico. Quem chama já
 * confirmou que a lead está ao alcance de quem pede.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/interactions.php';
require_once __DIR__ . '/inline.php';
require_once __DIR__ . '/contacts.php';   // «Trazido por» (§13.4)

/** Anel do prazo (enche com a percentagem) + texto, com a hora limite na dica. */
function lead_ring(?array $c, bool $withLabel = true): string {
    if (!$c) return '<span class="ring-none muted">—</span>';
    $len = 50.27;   // 2πr, r = 8
    $fill = min(1, $c['pct']) * $len;
    return '<span class="ring lvl-' . $c['level'] . '" data-tip="' . e($c['tip']) . '" aria-label="' . e($c['tip'] . ' · ' . $c['label']) . '" tabindex="0">'
         . '<svg viewBox="0 0 20 20" aria-hidden="true"><circle class="trk" cx="10" cy="10" r="8"/>'
         . '<circle class="val" cx="10" cy="10" r="8" stroke-dasharray="' . round($fill, 2) . ' ' . $len . '"/></svg>'
         . ($withLabel ? '<b>' . e($c['label']) . '</b>' : '') . '</span>';
}

/** Um contacto (Cliente, Trazido por) como ligação para o painel; «—» sem contacto. */
function deal_people_link(PDO $pdo, $contactId): string {
    if (!$contactId || !($c = contact_get($pdo, (int)$contactId))) return '<span class="muted">—</span>';
    $label = e($c['name']) . ($c['kind'] === 'person' && $c['company_name'] ? ' <span class="muted">· ' . e($c['company_name']) . '</span>' : '');
    return can('contactos') ? '<a href="/admin/contact.php?id=' . (int)$c['id'] . '" data-panel="contact:' . (int)$c['id'] . '">' . $label . '</a>' : $label;
}

/** Chip do estado (Sem resposta mostra a tentativa). */
function lead_state_chip(array $l): string {
    if ($l['phase'] === 'archived') return '<span class="chip">Arquivada</span>';
    $label = LEAD_STATE[$l['lead_state']] ?? $l['lead_state'];
    if ($l['lead_state'] === 'sem_resposta' && (int)$l['no_answer'] > 0) $label .= ' · ' . (int)$l['no_answer'] . '.ª';
    return '<span class="chip st-' . e($l['lead_state']) . '">' . e($label) . '</span>';
}

/** As redes sociais como ícones reconhecidos (com o nome na dica). */
function lead_links_html(string $links): string {
    $out = '';
    foreach (array_filter(array_map('trim', preg_split('/\s*[|\n]\s*/', $links)), 'strlen') as $u) {
        if (!lead_is_link($u)) { $out .= '<span class="muted">' . e($u) . '</span> '; continue; }
        [$label, $k] = social_kind($u);
        $short = preg_replace('~^https?://(www\.)?~i', '', rtrim($u, '/'));
        $out .= '<a class="soc" href="' . e(lead_link_url($u)) . '" target="_blank" rel="noopener noreferrer nofollow" data-tip="' . e($label . ' · ' . $short) . '" aria-label="' . e($label . ' · ' . $short) . '">'
              . icon(SOCIAL_ICONS[$k] ?? 'globe') . '</a>';
    }
    return $out;
}

/** «há 14 h», «ontem», «20/10». */
function lead_ago(?string $when): string {
    if (!$when) return '—';
    $s = time() - strtotime($when);
    if ($s < 3600) return 'há ' . max(1, (int)round($s / 60)) . ' min';
    if ($s < 86400) return 'há ' . (int)floor($s / 3600) . ' h';
    if ($s < 172800) return 'ontem';
    if ($s < 7 * 86400) return 'há ' . (int)floor($s / 86400) . ' dias';
    return lisbon_fmt(strtotime($when), 'd/m/Y');
}

/** O Foco: as tarefas abertas da lead, por prazo, com o botão concluir. */
function lead_focus_html(array $tasks, bool $edit): string {
    if (!$tasks) return '<div class="noact">' . icon('alert') . ' Sem próxima ação</div>';
    $h = '<ul class="focus">';
    foreach ($tasks as $t) {
        [$kindLabel, $kindIcon] = LEAD_TASK_KINDS[$t['kind'] ?? 'task'] ?? LEAD_TASK_KINDS['task'];
        $late = task_is_late($t);
        $h .= '<li data-task="' . (int)$t['id'] . '">'
            . ($edit ? '<button type="button" class="tick" data-done="' . (int)$t['id'] . '" data-kind="' . e((string)$t['kind']) . '" aria-label="Concluir «' . e($t['title']) . '»" data-tip>' . icon('check') . '</button>' : '')
            . '<span class="k ' . lead_kind_class((string)($t['kind'] ?? 'task')) . '" data-tip="' . e($kindLabel) . '" aria-label="' . e($kindLabel) . '" role="img" tabindex="0">' . icon($kindIcon) . '</span>'
            . '<span class="t">' . e($t['title']) . ($t['description'] ? '<small>' . e(shorten((string)$t['description'], 80)) . '</small>' : '') . '</span>'
            . '<small class="when' . ($late ? ' late' : '') . '">' . e(lead_task_when($t)) . ($t['repeat_rule'] ? ' ↻' : '') . '<br>' . e($t['who'] ? first_name_of($t['who']) : 'Por atribuir') . '</small></li>';
    }
    return $h . '</ul>';
}

function first_name_of(string $n): string {
    return explode(' ', trim($n))[0];
}

/** Nova ação: tipo por ícones, notas, atalhos de tempo, data, hora, duração, para quem, repetir. */
function lead_new_action_html(PDO $pdo, array $l): string {
    $me = me_row($pdo);
    $people = [];
    foreach (assignable_users($pdo, $me) as $u) $people[(int)$u['id']] = $u['name'];
    $default = $l['owner_id'] !== null && isset($people[(int)$l['owner_id']]) ? (int)$l['owner_id'] : (int)$me['id'];
    $kinds = '';
    foreach (LEAD_TASK_KINDS as $k => [$label, $ico]) {
        $kinds .= '<label class="kind ' . lead_kind_class($k) . '" data-tip="' . e($label) . '"><input type="radio" name="kind" value="' . $k . '"' . ($k === 'call' ? ' checked' : '') . '>'
                . '<span aria-hidden="true">' . icon($ico) . '</span><span class="sr">' . e($label) . '</span></label>';
    }
    $next = (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->modify('+1 hour');
    $opt = fn(array $o, $sel) => implode('', array_map(fn($k, $v) => '<option value="' . e((string)$k) . '"' . ((string)$k === (string)$sel ? ' selected' : '') . '>' . e($v) . '</option>', array_keys($o), $o));
    return '<form class="newact" data-lead-task autocomplete="off">'
         . '<input type="hidden" name="id" value="' . (int)$l['id'] . '">'
         . '<div class="kinds" role="radiogroup" aria-label="Tipo de ação">' . $kinds . '</div>'
         . '<input type="text" name="title" maxlength="200" placeholder="Ligar a ' . e(lead_display_name($l)) . '" aria-label="O quê" data-who="' . e(lead_display_name($l)) . '">'
         . '<textarea name="notes" rows="2" maxlength="4000" placeholder="Notas (opcional)" aria-label="Notas"></textarea>'
         // Reunião: Google Meet, Discord ou presencial (como na aba Tarefas); almoço, jantar e evento pedem o local.
         . '<div class="lmeet" data-lk-meet hidden role="radiogroup" aria-label="Reunião via"><span class="lbl">Reunião via</span><div class="chips-check">'
         . '<label class="rb"><input type="radio" name="platform" value="meet" checked><span>' . icon('video') . ' Google Meet</span></label>'
         . '<label class="rb"><input type="radio" name="platform" value="discord"><span>' . icon('video') . ' Discord</span></label>'
         . '<label class="rb"><input type="radio" name="platform" value=""><span>' . icon('pin') . ' Presencial</span></label></div></div>'
         . '<input type="text" name="location" maxlength="200" placeholder="Local (estúdio, morada, restaurante…)" aria-label="Local" data-lk-local hidden>'
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

/**
 * «Editar» (§12.5): todos os campos da lead num só formulário; «Gravar» grava-os de uma vez
 * (ui_api.php?a=lead_save, com as mesmas regras e permissões da edição no campo).
 */
function lead_edit_form_html(PDO $pdo, array $l): string {
    $id = (int)$l['id'];
    $def = inline_fields($l)['lead'];
    $f = fn(string $k) => 'le-' . $id . '-' . $k;
    $text = fn(string $k, string $label, array $a = []) =>
        '<div class="field' . (!empty($a['wide']) ? ' wide' : '') . '"><label for="' . $f($k) . '">' . e($label) . '</label>'
        . (!empty($a['area'])
            ? '<textarea id="' . $f($k) . '" name="f[' . $k . ']" rows="' . ($a['rows'] ?? 3) . '" maxlength="' . (int)$def[$k][1] . '"' . (isset($a['ph']) ? ' placeholder="' . e($a['ph']) . '"' : '') . '>' . e((string)$l[$k]) . '</textarea>'
            : '<input type="text" id="' . $f($k) . '" name="f[' . $k . ']" maxlength="' . (int)$def[$k][1] . '" value="' . e((string)$l[$k]) . '"'
              . (isset($a['ph']) ? ' placeholder="' . e($a['ph']) . '"' : '') . (isset($a['im']) ? ' inputmode="' . $a['im'] . '"' : '') . '>')
        . '</div>';
    $select = function (string $k, string $label, ?string $cur = null) use ($def, $f, $l) {
        $cur ??= (string)$l[$k];
        $o = '';
        foreach ($def[$k][1] as $v => $t) $o .= '<option value="' . e((string)$v) . '"' . ((string)$v === $cur ? ' selected' : '') . '>' . e($t) . '</option>';
        return '<div class="field"><label for="' . $f($k) . '">' . e($label) . '</label><select id="' . $f($k) . '" name="f[' . $k . ']">' . $o . '</select></div>';
    };
    $chosen = array_map('trim', explode(',', (string)$l['types']));
    $types = '';
    foreach ($def['types'][1] as $v => $t) {
        $types .= '<label class="rb"><input type="checkbox" name="f[types][]" value="' . e($v) . '"' . (in_array($v, $chosen, true) ? ' checked' : '') . '><span>' . e($t) . '</span></label>';
    }
    return '<form class="lv-form" data-lead-edit autocomplete="off" hidden>'
         . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="f[types_sent]" value="1">'
         . '<div class="grid">'
         . $text('person', 'Pessoa de contacto')
         . $text('company', 'Empresa')
         . $text('phone', 'Telefone(s)', ['ph' => '+351 … (vários: separe com |)', 'im' => 'tel'])
         . $text('email', 'Email', ['im' => 'email'])
         . $text('links', 'Redes sociais', ['area' => true, 'rows' => 2, 'wide' => true, 'ph' => 'Um link por linha (Instagram, LinkedIn, site…)'])
         . $select('lead_state', 'Estado')
         . $select('owner_id', 'Responsável', $l['owner_id'] === null ? 'none' : (string)$l['owner_id'])
         . $select('service', 'Serviço')
         . $select('priority', 'Prioridade')
         . $select('origin', 'Origem')
         . $text('campaign', 'Campanha')
         . '<div class="field"><label for="' . $f('brought_by') . '">Trazido por</label>'
         . contact_pick_html($pdo, 'f[brought_by]', 'f[brought_by_id]', $l['brought_by_id'] !== null ? (int)$l['brought_by_id'] : null, $f('brought_by'), 'Quem originou (agência, parceiro…)') . '</div>'
         . '<div class="field wide"><span class="lbl">Tipo</span><div class="chips-check" role="group" aria-label="Tipo">' . $types . '</div></div>'
         . $text('why', 'Porquê abordar', ['area' => true, 'wide' => true])
         . $text('detail', 'Detalhe', ['area' => true, 'wide' => true])
         . $text('notes', 'Notas', ['area' => true, 'wide' => true, 'rows' => 4])
         . '</div>'
         . '<div class="lv-foot lv-edit-foot"><button type="button" class="btn ghost" data-edit-cancel>Cancelar</button>'
         . '<span class="spacer"></span><span class="muted kbd-hint">Esc cancela · ⌘/Ctrl+Enter grava</span>'
         . '<button type="submit" class="btn">' . icon('check') . ' Gravar</button></div>'
         . '</form>';
}

/**
 * A vista completa. $mode 'panel' (painel lateral) ou 'page' (lead.php).
 * $back: para onde voltar depois de converter/arquivar sem JavaScript.
 */
function lead_view(PDO $pdo, array $l, string $mode = 'panel', string $back = '/admin/leads.php'): string {
    $id = (int)$l['id'];
    $edit = can('leads', PERM_EDIT);
    $active = $l['phase'] === 'lead';
    $quar = (int)$l['is_quarantined'] === 1;
    $ed = $edit && $active;
    $clock = lead_clock($l, lead_deadline_hours($pdo));
    [$name, $company] = lead_display($l);
    $tasks = lead_open_tasks($pdo, [$id])[$id] ?? [];
    // Só leitura: edita-se tudo de uma vez com «Editar» (sem lápis campo a campo).
    $row = fn(string $label, string $field, array $o = []) => panel_row($label, 'lead', $id, $field, $l, false, $o);

    // Botões de contacto (§12.3).
    $dial = lead_phone_dial((string)$l['phone']);
    $wa = ltrim($dial, '+');
    $acts = '<div class="lv-acts">'
          . ($dial !== '' ? '<a class="btn ghost sm" href="tel:' . e($dial) . '">' . icon('phone') . ' Ligar</a>' : '<span class="btn ghost sm off" aria-disabled="true">' . icon('phone') . ' Ligar</span>')
          . ($wa !== '' ? '<a class="btn ghost sm" href="https://wa.me/' . e($wa) . '" target="_blank" rel="noopener noreferrer">' . icon('whatsapp') . ' WhatsApp</a>' : '<span class="btn ghost sm off" aria-disabled="true">' . icon('whatsapp') . ' WhatsApp</span>')
          . ($l['email'] ? '<a class="btn ghost sm" href="mailto:' . e($l['email']) . '">' . icon('mail') . ' Email</a>' : '<span class="btn ghost sm off" aria-disabled="true">' . icon('mail') . ' Email</span>')
          . '</div>';

    $owner = $l['owner_id'] ? (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$l['owner_id'])->fetchColumn() : 'Por atribuir';
    $kv = $row('Pessoa', 'person')
        . $row('Empresa', 'company')
        . $row('Telefone(s)', 'phone')
        . $row('Email', 'email')
        . $row('Redes sociais', 'links', ['html' => lead_links_html((string)$l['links']), 'type' => 'textarea'])
        . ($active ? $row('Estado', 'lead_state', ['html' => lead_state_chip($l)]) : '')
        . ($active ? $row('Responsável', 'owner_id', ['html' => e($owner), 'value' => $l['owner_id'] === null ? 'none' : (string)$l['owner_id']]) : '<span>Responsável</span><div>' . e($owner) . '</div>')
        . $row('Serviço', 'service')
        . $row('Tipo', 'types')
        . $row('Prioridade', 'priority')
        . $row('Origem', 'origin', $l['origin'] === 'rede' && trim((string)$l['campaign']) !== '' ? ['html' => e($l['campaign']) . ' <span class="muted small">· redes sociais</span>'] : [])
        . $row('Campanha', 'campaign')
        . '<span>Trazido por</span><div>' . deal_people_link($pdo, $l['brought_by_id']) . '</div>'
        . '<span>Último contacto</span><div data-last>' . e(lead_ago($l['last_contact_at'])) . '</div>'
        . '<span>Criada</span><div><span class="mono">' . e(lisbon_fmt(strtotime((string)$l['created_at']), 'd/m/Y')) . '</span>'
        . ($l['created_by'] ? ' <span class="muted">por ' . e((string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$l['created_by'])->fetchColumn()) . '</span>' : '') . '</div>';

    $foot = '';
    if ($edit && $active && !$quar) {
        if (can('pipeline', PERM_EDIT)) $foot .= '<a class="btn" href="/admin/leads.php?conv=' . $id . '#converter">' . icon('arrow') . ' Converter em negócio</a>';
        $foot .= '<button type="button" class="btn ghost" data-archive="' . $id . '" data-name="' . e($name) . '">' . icon('archive') . ' Arquivar</button>';
    } elseif ($edit && $quar) {
        $foot .= '<form method="post" action="/admin/leads.php">' . csrf_field() . '<input type="hidden" name="do" value="release"><input type="hidden" name="id" value="' . $id . '">'
               . '<input type="hidden" name="back[v]" value="quarentena"><button class="btn" type="submit">' . icon('check') . ' Não é spam</button></form>';
    } elseif ($edit) {
        $foot .= '<form method="post" action="/admin/leads.php">' . csrf_field() . '<input type="hidden" name="do" value="reactivate"><input type="hidden" name="id" value="' . $id . '">'
               . '<button class="btn" type="submit">' . icon('refresh') . ' Reativar</button></form>';
    }
    if (can('leads', PERM_MANAGE)) $foot .= '<a class="lnk del" href="/admin/leads.php?del=' . $id . '">Eliminar</a>';

    $head = '<header class="lv-head">'
          . ($ed ? '<button type="button" class="btn ghost sm lv-edit-btn" data-edit-start aria-keyshortcuts="E" data-tip="Editar a lead  ·  E">' . icon('pencil') . ' Editar</button>' : '')
          . '<h2 class="lv-name">' . e($name) . '</h2>'
          . ($company !== '' ? '<div class="lv-co">' . e($company) . '</div>' : '')
          . '<div class="lv-chips">' . lead_state_chip($l) . ($clock ? ' ' . lead_ring($clock) : '')
          . ($l['priority'] === 'alta' ? ' <span class="chip no">Alta</span>' : '')
          . ($quar ? ' <span class="chip wait">Quarentena</span>' : '')
          . ($l['phase'] === 'archived' ? ' <span class="muted">' . e(ARCHIVE_REASONS[$l['archived_reason']] ?? $l['archived_reason']) . ($l['archived_note'] ? ' · ' . e($l['archived_note']) : '') . '</span>' : '')
          . '</div></header>';

    $left = '<div class="lv-l">' . $head . $acts
          . '<div class="lv-read">'
          . '<div class="kv">' . $kv . '</div>'
          . '<section><h4>Porquê abordar</h4>' . inline_field('lead', $id, 'why', e((string)$l['why'])) . '</section>'
          . '<section><h4>Detalhe</h4>' . inline_field('lead', $id, 'detail', e((string)$l['detail'])) . '</section>'
          . '<section><h4>Notas</h4>' . inline_field('lead', $id, 'notes', e((string)$l['notes'])) . '</section>'
          . ($foot !== '' ? '<div class="lv-foot">' . $foot . '</div>' : '')
          . '</div>'
          . ($ed ? lead_edit_form_html($pdo, $l) : '')
          . '</div>';

    $right = '<div class="lv-r">'
           . ($ed && !$quar ? '<section class="lv-blk"><h4>Nova ação</h4>' . lead_new_action_html($pdo, $l) . '</section>' : '')
           . '<section class="lv-blk"><h4>Foco <span class="mono" data-focus-n>' . count($tasks) . '</span></h4><div class="lv-focus">' . lead_focus_html($tasks, $edit) . '</div></section>'
           . '<section class="lv-blk lv-hist"><h4>Notas &amp; Histórico</h4>' . history_block($pdo, 'deal', $id, $edit, $back, '', ['note']) . '</section>'
           . '</div>';

    return '<div class="lv lv-' . e($mode) . '" data-lead="' . $id . '" data-name="' . e($name) . '">' . $left . $right . '</div>';
}

/**
 * Os formulários que surgem por cima (§12.8, §12.9): «Como correu?» e
 * «Arquivar lead». $arch: a lead a arquivar já aberta (sem JavaScript,
 * leads.php?arch=ID); o formulário grava por POST normal se não houver JS.
 */
function lead_modals_html(?array $arch = null, string $back = ''): string {
    $reasons = '';
    foreach (ARCHIVE_REASONS as $k => $label) {
        if ($k === 'perdido_migracao') continue;
        $reasons .= '<label class="rb"><input type="radio" name="reason" value="' . $k . '"' . ($k === 'outro' ? ' data-other' : '') . ' required><span>' . e($label) . '</span></label>';
    }
    $archOpen = $arch !== null;
    return
      '<div class="mdl' . ($archOpen ? ' open' : '') . '" id="mdl-arch" role="dialog" aria-modal="true" aria-labelledby="mdl-arch-t"' . ($archOpen ? '' : ' hidden') . '>'
    . '<div class="mdl-back" data-mdl-close></div>'
    . '<form class="mdl-card pop-in" method="post" action="/admin/leads.php" data-arch-form>'
    . csrf_field() . '<input type="hidden" name="do" value="archive"><input type="hidden" name="id" value="' . ($arch ? (int)$arch['id'] : '') . '">'
    . ($back !== '' ? '<input type="hidden" name="back_url" value="' . e($back) . '">' : '')
    . '<h3 id="mdl-arch-t">Arquivar lead</h3><p class="muted" data-arch-name>' . ($arch ? e(lead_display_name($arch)) : '') . '</p>'
    . '<div class="reasons" role="radiogroup" aria-label="Motivo">' . $reasons . '</div>'
    . '<input type="text" name="note" maxlength="255" placeholder="Qual é o motivo?" aria-label="Motivo (Outro)" data-other-text hidden>'
    . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><button class="btn" type="submit">' . icon('archive') . ' Arquivar</button></div>'
    . '</form></div>'

    . '<div class="mdl" id="mdl-out" role="dialog" aria-modal="true" aria-labelledby="mdl-out-t" hidden>'
    . '<div class="mdl-back" data-mdl-close></div>'
    . '<div class="mdl-card pop-in">'
    . '<h3 id="mdl-out-t">Como correu?</h3><p class="muted" data-out-name></p>'
    . '<div class="out-step" data-step="choose"><div class="opts">'
    . '<button type="button" class="opt" data-out="missed">' . icon('phone-off') . '<b>Chamada não atendida</b><small>→ Sem resposta · o prazo recomeça</small></button>'
    . '<button type="button" class="opt" data-out="followup">' . icon('phone') . '<b>Follow-Up</b><small>→ nota da conversa + próxima ação</small></button>'
    . '<button type="button" class="opt" data-out="archive">' . icon('archive') . '<b>Arquivar</b><small>→ motivo do arquivamento</small></button>'
    . '<a class="opt cv" data-out="convert" href="#">' . icon('arrow') . '<b>Converter em Negócio</b><small>→ funil · ID · X-Ray</small></a>'
    . '</div></div>'
    . '<div class="out-step" data-step="missed" hidden><p data-missed-msg></p>'
    . '<p class="flash err" data-suggest hidden>3.ª tentativa sem resposta. Arquivar com o motivo «Sem resposta»? '
    . '<button type="button" class="btn sm" data-out="archive-noanswer">' . icon('archive') . ' Arquivar</button></p>'
    . '<div class="quick" role="group" aria-label="Próxima chamada"><span class="muted">Ligar outra vez:</span>'
    . '<button type="button" data-recall="1h">Em 1h</button><button type="button" data-recall="3h">Em 3h</button><button type="button" data-recall="tomorrow">Amanhã</button></div></div>'
    . '<form class="out-step" data-step="followup" hidden><textarea name="note" rows="3" maxlength="4000" placeholder="Nota rápida da conversa" aria-label="Nota da conversa"></textarea>'
    . '<div class="actions"><button class="btn" type="submit">' . icon('check') . ' Gravar e marcar o passo seguinte</button></div></form>'
    . '<div class="actions out-foot"><button type="button" class="btn ghost" data-mdl-close>Fechar</button>'
    . '<button type="button" class="btn ghost" data-next-urgent hidden>Próxima urgente →</button></div>'
    . '</div></div>';
}
