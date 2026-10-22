<?php
/**
 * topper. admin — peças de ecrã da aba Tarefas (§18): filtros, linhas da lista,
 * quadro da equipa, o formulário «+ Nova tarefa» e a agenda de 24 horas.
 * A lógica vive em inc/tasks.php; aqui só se desenha.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/tasks.php';

/** Os filtros do endereço (§18.5), normalizados. */
function tasks_filters(array $g, bool $operator): array {
    $f = [
        'est'    => in_array($g['est'] ?? '', ['todo', 'done', 'all'], true) ? $g['est'] : 'todo',
        'urg'    => in_array($g['urg'] ?? '', ['late', 'today', 'week'], true) ? $g['urg'] : '',
        'quem'   => (string)($g['quem'] ?? ($operator ? 'me' : 'all')),
        'tipo'   => isset(TASK_TYPES[$g['tipo'] ?? '']) ? $g['tipo'] : '',
        'origem' => isset(TASK_ORIGINS[$g['origem'] ?? '']) ? $g['origem'] : '',
        'neg'    => max(0, (int)($g['neg'] ?? 0)),
        'solto'  => !empty($g['solto']) ? 1 : 0,
    ];
    if (!in_array($f['quem'], ['me', 'all', 'none'], true) && !ctype_digit($f['quem'])) $f['quem'] = $operator ? 'me' : 'all';
    return $f;
}

/** O endereço com os filtros, mudando só o que se pede (os valores por defeito saem). */
function tasks_url(string $tab, array $f, array $over = []): string {
    $q = array_merge($f, $over);
    $def = ['est' => 'todo', 'urg' => '', 'tipo' => '', 'origem' => '', 'neg' => 0, 'solto' => 0];
    $out = $tab !== 'tarefas' ? ['t' => $tab] : [];
    foreach ($q as $k => $v) {
        if ($k === 'quem') { $out['quem'] = $v; continue; }
        if (array_key_exists($k, $def) && (string)$def[$k] === (string)$v) continue;
        if ($v === '' || $v === null) continue;
        $out[$k] = $v;
    }
    return '/admin/calendar.php?' . http_build_query($out);
}

/** Aplica os filtros (o «quem» só na lista; o quadro mostra todas as colunas). */
function tasks_filter(array $list, array $f, ?int $myMember, bool $withWho = true): array {
    $today = today_lisbon();
    return array_values(array_filter($list, function ($t) use ($f, $myMember, $withWho, $today) {
        $mid = $t['member_id'] !== null ? (int)$t['member_id'] : null;
        if ($withWho) {
            if ($f['quem'] === 'me' && $mid !== $myMember) return false;
            if ($f['quem'] === 'none' && $mid !== null) return false;
            if (ctype_digit($f['quem']) && $mid !== (int)$f['quem']) return false;
        }
        if ($f['urg'] !== '' && $t['status'] !== 'done') {
            $b = task_bucket($t, $today);
            if ($f['urg'] === 'late' && $b !== 'late') return false;
            if ($f['urg'] === 'today' && $b !== 'today') return false;
            if ($f['urg'] === 'week' && !in_array($b, ['today', 'week'], true)) return false;
        }
        if ($f['tipo'] !== '' && task_type($t) !== $f['tipo']) return false;
        if ($f['origem'] !== '' && task_origin($t) !== $f['origem']) return false;
        if ($f['neg'] && (int)$t['deal_id'] !== $f['neg']) return false;
        return true;
    }));
}

/** Iniciais na cor da pessoa (o responsável de uma linha ou cartão). */
function task_who_html(array $members, ?int $mid, bool $name = false): string {
    if (!$mid || !isset($members[$mid])) return '<span class="twho none" data-tip="Por atribuir">—</span>';
    $m = $members[$mid];
    $ini = mb_strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(preg_split('/\s+/', trim($m['name'])), 0, 2))));
    return '<span class="twho" style="--who:' . e(member_color($m)) . '" data-tip="' . e($m['name']) . '">' . e($ini) . '</span>' . ($name ? ' <span>' . e(first_name($m['name'])) . '</span>' : '');
}

/** Uma linha da lista (§18.3): ícone do tipo · origem · título · ligação · prazo · ações (adiar, editar, abrir) · responsável. */
function task_row_html(array $t, array $members, string $editUrl): string {
    $done = $t['status'] === 'done';
    [$lt, $lu] = task_link($t);
    $ck = task_checklist($t);
    $ckDone = count(array_filter($ck, fn($i) => !empty($i[1])));
    $extra = '';
    if ($ck) $extra .= '<span class="tmeta" data-tip="Checklist">' . icon('checklist') . $ckDone . '/' . count($ck) . '</span>';
    if (($t['repeat_rule'] ?? '') !== '') $extra .= '<span class="tmeta" data-tip="' . e(TASK_REPEAT[$t['repeat_rule']] ?? 'Repete') . '">↻</span>';
    if (!empty($t['help_by'])) $extra .= '<span class="tmeta help" data-tip="Pedido de ajuda de ' . e((string)$t['help_name']) . '">🙋</span>';
    if (($t['platform'] ?? '') !== '') $extra .= '<span class="tmeta" data-tip="' . e(MEETING_PLATFORMS_TASK[$t['platform']] ?? '') . '">' . icon('video') . '</span>';
    elseif (($t['location'] ?? '') !== '') $extra .= '<span class="tmeta" data-tip="' . e((string)$t['location']) . '">' . icon('pin') . '</span>';
    if (!empty($t['private'])) $extra .= '<span class="tmeta" data-tip="Privada: os outros veem só «Ocupado»">' . icon('eye') . '</span>';
    return '<div class="trow' . ($done ? ' is-done' : '') . '" data-task="' . (int)$t['id'] . '" data-mid="' . (int)$t['member_id'] . '">'
         . ($done ? '<button type="button" class="tchk on" data-reopen="' . (int)$t['id'] . '" aria-label="Reabrir" data-tip>' . icon('check') . '</button>'
                  : '<button type="button" class="tchk" data-done="' . (int)$t['id'] . '" aria-label="Concluir" data-tip>' . icon('check') . '</button>')
         . task_type_badge($t) . task_origin_badge($t)
         . '<a class="tt" href="' . e($editUrl) . '">' . e($t['title']) . '</a><span class="tex">' . $extra . '</span>'
         . ($lt !== '' ? ($lu !== '' ? '<a class="tlink" href="' . e($lu) . '">' . e(shorten($lt, 40)) . '</a>' : '<span class="tlink">' . e(shorten($lt, 40)) . '</span>') : '<span class="tlink"></span>')
         . task_due_html($t)
         . '<span class="tacts">'
         . (!$done ? '<button type="button" class="tact" data-snooze="' . (int)$t['id'] . '" data-due="' . e((string)$t['due_date']) . '" data-time="' . e(substr((string)($t['due_time'] ?? ''), 0, 5)) . '" aria-label="Adiar" data-tip aria-haspopup="menu">' . icon('clock') . '</button>' : '')
         . '<a class="tact" href="' . e($editUrl) . '" aria-label="Editar" data-tip>' . icon('pencil') . '</a>'
         . ($lu !== '' ? '<a class="tact" href="' . e($lu) . '" aria-label="Abrir ' . e($lt) . '" data-tip="Abrir ' . e(shorten($lt, 40)) . '">' . icon('external') . '</a>' : '')
         . '</span>'
         . task_who_html($members, $t['member_id'] !== null ? (int)$t['member_id'] : null)
         . '</div>';
}

/** O prazo na linha: data e hora; por baixo a duração e quanto falta (ou há quanto venceu). */
function task_due_html(array $t): string {
    if (($t['status'] ?? '') === 'done') return '<span class="tdue"><b>feita ' . e(date('d/m/y', strtotime((string)$t['done_at']))) . '</b></span>';
    [$abs, $sub, $late] = task_due_parts($t);
    return '<span class="tdue' . ($late ? ' late' : '') . ($t['due_date'] ? '' : ' none') . '"><b>' . e($abs) . '</b>' . ($sub !== '' ? '<small>' . e($sub) . '</small>' : '') . '</span>';
}

/** A linha que junta as tarefas automáticas iguais (§18.3). */
function task_group_html(array $g, array $members, bool $showWho): string {
    [$label, $type, $origin, $url] = TASK_AUTO_GROUPS[$g['group']];
    $n = count($g['tasks']);
    $first = $g['tasks'][0];
    return '<div class="trow agg" data-group="' . e($g['group']) . '">'
         . '<span class="tchk ghost" aria-hidden="true"></span>'
         . task_type_badge(['kind' => $type]) . task_origin_badge(['kind' => $origin === 'relation' ? 'relation' : 'call', 'deal_id' => $origin === 'lead' ? 1 : null, 'deal_phase' => 'lead'])
         . '<span class="tt"><b>' . e($label) . ' · ' . $n . '</b>' . ($g['late'] ? ' · <span class="late">' . $g['late'] . ' em atraso</span>' : '') . ' <small class="muted">agrupadas</small></span><span class="tex"></span>'
         . '<span class="tlink muted">' . ($showWho && $g['member'] && isset($members[$g['member']]) ? e(first_name($members[$g['member']]['name'])) : '') . '</span>'
         . task_due_html($first)
         . '<a class="tgo" href="' . e($url) . '">Abrir fila →</a>'
         . '</div>';
}

/** Os filtros (§18.5): botões combináveis e as vistas guardadas. */
function tasks_filters_html(array $f, array $members, ?int $myMember, string $tab, array $views, array $dealOpts, array $counts): string {
    $chip = function (string $k, string $v, string $label, string $cls = '', string $tip = '') use ($f, $tab) {
        $on = (string)$f[$k] === $v;
        return '<a class="fchip' . ($on ? ' on' : '') . ($cls ? ' ' . $cls : '') . '" href="' . e(tasks_url($tab, $f, [$k => $on && $k !== 'est' && $k !== 'quem' ? '' : $v])) . '"' . ($tip ? ' data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"' : '') . ($on ? ' aria-current="true"' : '') . '>' . $label . '</a>';
    };
    $h = '<div class="tfilters">';
    if ($tab === 'tarefas') {
        $h .= '<div class="fg"><em>Estado</em>' . $chip('est', 'todo', 'Por fazer') . $chip('est', 'done', 'Concluídas') . $chip('est', 'all', 'Todas') . '</div>';
    }
    $h .= '<div class="fg"><em>Urgência</em>' . $chip('urg', 'late', 'Em atraso' . ($counts['late'] ? ' <i>' . $counts['late'] . '</i>' : ''), 'urg') . $chip('urg', 'today', 'Hoje') . $chip('urg', 'week', 'Esta semana') . '</div>';
    if ($tab === 'tarefas') {
        // «Quem»: um botão que abre a lista de pessoas (em vez de um botão por pessoa).
        $opts = ($myMember !== null && isset($members[$myMember]) ? ['me' => ['Eu', task_who_html($members, $myMember)]] : [])
              + ['all' => ['Todos', '<span class="fpl-i">' . icon('equipa') . '</span>'], 'none' => ['Por atribuir', '<span class="fpl-i">' . icon('hand') . '</span>']];
        foreach ($members as $mid => $m) if ($mid !== $myMember) $opts[(string)$mid] = [$m['name'], task_who_html($members, (int)$mid)];
        $cur = (string)$f['quem'];
        $items = ''; $sep = false;
        foreach ($opts as $v => [$l, $av]) {
            $on = $cur === (string)$v;
            if (is_int($v) && !$sep) { $items .= '<hr>'; $sep = true; }   // as pessoas, depois de Eu · Todos · Por atribuir
            $items .= '<a class="fpl' . ($on ? ' on' : '') . '" href="' . e(tasks_url($tab, $f, ['quem' => (string)$v])) . '"' . ($on ? ' aria-current="true"' : '') . '>' . $av . '<span>' . e($l) . '</span>' . ($on ? '<span class="ok">' . icon('check') . '</span>' : '') . '</a>';
        }
        $label = $opts[$cur][0] ?? 'Todos';
        $h .= '<div class="fg"><details class="fpop" data-fpop><summary class="fchip' . ($cur !== 'all' ? ' on' : '') . '">' . icon('user') . ' Quem: ' . e($cur === 'me' ? 'Eu' : (ctype_digit($cur) ? first_name($label) : $label)) . ' ' . icon('chev-down', 'chev') . '</summary>'
            . '<div class="fpop-box" role="menu" aria-label="Quem"><div class="fpop-h"><b>Quem</b></div><div class="fpop-l">' . $items . '</div></div></details></div>';
    }
    // Tipo e origem: um botão que abre uma caixa com os ícones (tipo: 5 × 3).
    $pop = function (string $k, string $title, array $list, string $cls) use ($f, $tab) {
        $cur = (string)$f[$k];
        $items = '';
        foreach ($list as $v => [$l, $i]) {
            $on = $cur === $v;
            $items .= '<a class="fpi ' . $cls . '-' . $v . ($on ? ' on' : '') . '" href="' . e(tasks_url($tab, $f, [$k => $on ? '' : $v])) . '"' . ($on ? ' aria-current="true"' : '') . '><span>' . icon($i) . '</span><small>' . e($l) . '</small></a>';
        }
        $sum = $cur !== '' ? '<span class="fpi-c ' . $cls . '-' . $cur . '">' . icon($list[$cur][1]) . '</span>' . e($list[$cur][0]) : e($title);
        return '<details class="fpop' . ($k === 'tipo' ? ' wide' : '') . '" data-fpop><summary class="fchip' . ($cur !== '' ? ' on' : '') . '">' . $sum . ' ' . icon('chev-down', 'chev') . '</summary>'
             . '<div class="fpop-box" role="menu" aria-label="' . e($title) . '"><div class="fpop-h"><b>' . e($title) . '</b>' . ($cur !== '' ? '<a href="' . e(tasks_url($tab, $f, [$k => ''])) . '">Todos</a>' : '') . '</div><div class="fpop-g">' . $items . '</div></div></details>';
    };
    $h .= '<div class="fg">' . $pop('tipo', 'Tipo de tarefa', array_diff_key(TASK_TYPES, ['block' => 1]), 'ty') . $pop('origem', 'Origem', TASK_ORIGINS, 'or') . '</div>';
    $opts = '<option value="">Todos os negócios</option>';
    foreach ($dealOpts as $id => $l) $opts .= '<option value="' . $id . '"' . ($f['neg'] === $id ? ' selected' : '') . '>' . e($l) . '</option>';
    $hidden = '';
    foreach ($f as $k => $v) if ($k !== 'neg' && (string)$v !== '' && !($k === 'solto' && !$v)) $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    $h .= '<form class="fg" method="get" action="/admin/calendar.php">' . ($tab !== 'tarefas' ? '<input type="hidden" name="t" value="' . e($tab) . '">' : '') . $hidden
        . '<label class="sr" for="f-neg">Negócio</label><select id="f-neg" name="neg" onchange="this.form.submit()">' . $opts . '</select></form>';
    // «Limpar filtros»: volta ao que a aba mostra ao abrir.
    $def = ['est' => 'todo', 'urg' => '', 'quem' => $myMember !== null && isset($members[$myMember]) ? 'me' : 'all', 'tipo' => '', 'origem' => '', 'neg' => 0];
    $dirty = false;
    foreach ($def as $k => $v) if ((string)$f[$k] !== (string)$v && ($tab === 'tarefas' || !in_array($k, ['est', 'quem'], true))) $dirty = true;
    if ($dirty) $h .= '<a class="fclear" href="' . e(tasks_url($tab, ['solto' => $f['solto']] + $def + $f)) . '">' . icon('x') . ' Limpar filtros</a>';
    $h .= '</div>';
    // Vistas guardadas.
    $v = '<div class="tviews"><em>Vistas</em>';
    foreach ($views as $vw) {
        $v .= '<span class="tvchip"><a href="/admin/calendar.php?' . e($vw['query']) . '">' . e($vw['name']) . '</a>'
            . '<form method="post" class="inl"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="do" value="del_view"><input type="hidden" name="id" value="' . (int)$vw['id'] . '">'
            . '<button type="submit" class="x" aria-label="Apagar a vista ' . e($vw['name']) . '" data-tip>×</button></form></span>';
    }
    $q = parse_url(tasks_url($tab, $f), PHP_URL_QUERY);
    $v .= '<form method="post" class="vsave"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="do" value="save_view"><input type="hidden" name="query" value="' . e((string)$q) . '">'
        . '<label class="sr" for="v-name">Nome da vista</label><input type="text" id="v-name" name="name" maxlength="60" placeholder="Guardar estes filtros como…" required><button class="btn ghost sm" type="submit">' . icon('plus') . ' Guardar vista</button></form></div>';
    return $h . $v;
}

const MEETING_PLATFORMS_TASK = ['meet' => 'Google Meet', 'discord' => 'Discord'];

/**
 * O formulário «+ Nova tarefa» / editar (§18.7): tipo só com ícones, título, notas,
 * atalhos de tempo, data/hora/duração, responsável, ligação, prioridade, checklist,
 * anexos, repetir, Ocupado/Livre, participantes; reunião: Meet, Discord ou presencial (Local).
 */
function task_modal_html(PDO $pdo, ?array $t, array $assignable, ?int $myMember, array $participants, string $back, bool $open, array $pre = []): string {
    require_once __DIR__ . '/contacts.php';
    $edit = $t !== null;
    $v = $t ?? array_merge(['id' => 0, 'kind' => 'task', 'title' => '', 'description' => '', 'due_date' => '', 'due_time' => null, 'duration_min' => null, 'member_id' => $myMember,
                            'deal_id' => null, 'contact_id' => null, 'priority' => 'media', 'checklist' => null, 'attachments' => null, 'repeat_rule' => '', 'busy' => 1,
                            'platform' => '', 'location' => '', 'participants' => '', 'private' => 0], $pre);
    $type = task_type($v);
    $types = '';
    foreach (TASK_TYPES as $k => [$l, $i]) {
        $types .= '<label class="tyb ty-' . $k . '" data-tip="' . e($l) . '"><input type="radio" name="kind" value="' . $k . '"' . ($type === $k ? ' checked' : '') . ' aria-label="' . e($l) . '"><span>' . icon($i) . '</span></label>';
    }
    $who = '<option value="0">— Por atribuir —</option>';
    foreach ($assignable as $mid => $m) $who .= '<option value="' . $mid . '"' . ((int)$v['member_id'] === (int)$mid ? ' selected' : '') . '>' . e($m['name']) . '</option>';
    $deals = '<option value="0">— nenhum —</option>';
    foreach (task_deal_options($pdo, $v['deal_id'] ? (int)$v['deal_id'] : null) as $id => $l) $deals .= '<option value="' . $id . '"' . ((int)$v['deal_id'] === $id ? ' selected' : '') . '>' . e($l) . '</option>';
    $dur = '';
    foreach (TASK_DURATIONS as $k => $l) $dur .= '<option value="' . $k . '"' . ((int)($v['duration_min'] ?? 0) === $k ? ' selected' : '') . '>' . $l . '</option>';
    $prio = '';
    foreach (TASK_PRIO as $k => $l) $prio .= '<option value="' . $k . '"' . ($v['priority'] === $k ? ' selected' : '') . '>' . $l . '</option>';
    $rep = '';
    foreach (TASK_REPEAT as $k => $l) $rep .= '<option value="' . $k . '"' . (($v['repeat_rule'] ?? '') === $k ? ' selected' : '') . '>' . $l . '</option>';
    $ck = implode("\n", array_map(fn($i) => ($i[1] ? '[x] ' : '[ ] ') . $i[0], task_checklist($v)));
    $att = implode("\n", json_decode((string)($v['attachments'] ?? ''), true) ?: []);
    $plat = (string)($v['platform'] ?? '');
    $parts = array_filter(array_map('intval', explode(',', (string)($v['participants'] ?? ''))));
    $pp = '';
    foreach ($participants as $uid => $name) $pp .= '<label class="chk"><input type="checkbox" name="participants[]" value="' . $uid . '"' . (in_array($uid, $parts, true) ? ' checked' : '') . '><span>' . e($name) . '</span></label>';
    $hist = '';
    if ($edit) {
        foreach (task_history($pdo, (int)$v['id']) as $h) {
            $hist .= '<li><time>' . e(date('d/m H:i', strtotime((string)$h['created_at']))) . '</time> <b>' . e(first_name((string)($h['name'] ?? 'Sistema'))) . '</b> · ' . e($h['note'] ?: $h['event']) . '</li>';
        }
    }
    return '<div class="mdl' . ($open ? ' open' : '') . '" id="mdl-task" role="dialog" aria-modal="true" aria-labelledby="mdl-task-t"' . ($open ? '' : ' hidden') . ' data-clear="edit nova novo dia hora dur tipo">
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card wide tform" method="post" action="/admin/calendar.php" autocomplete="off" data-task-form>
    <input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="do" value="save_task"><input type="hidden" name="id" value="' . (int)$v['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">
    <h3 id="mdl-task-t">' . icon('tarefas') . ' ' . ($edit ? 'Editar tarefa' : 'Nova tarefa') . '</h3>
    <div class="tyrow" role="radiogroup" aria-label="Tipo">' . $types . '</div>
    <div class="grid">
      <div class="field wide"><label for="tk-title">Título</label><input type="text" id="tk-title" name="title" required maxlength="200" value="' . e((string)$v['title']) . '" placeholder="O que é preciso fazer"></div>
      <div class="field wide"><label for="tk-notes">Notas</label><textarea id="tk-notes" name="description" rows="2">' . e((string)$v['description']) . '</textarea></div>
      <div class="field wide"><span class="lbl">Quando</span><div class="chips-wrap qtimes">
        <button type="button" class="chip-btn" data-qt="1h">Em 1 h</button><button type="button" class="chip-btn" data-qt="3h">Em 3 h</button>
        <button type="button" class="chip-btn" data-qt="tomorrow">Amanhã 9:00</button><button type="button" class="chip-btn" data-qt="nextweek">Próxima semana</button>
        <button type="button" class="chip-btn" data-qt="none">Sem data</button></div></div>
      <div class="field"><label for="tk-day">Data</label><input type="date" id="tk-day" name="due_date" value="' . e((string)$v['due_date']) . '"></div>
      <div class="field"><label for="tk-time">Hora</label><input type="time" id="tk-time" name="due_time" step="900" value="' . e(substr((string)($v['due_time'] ?? ''), 0, 5)) . '"></div>
      <div class="field"><label for="tk-dur">Duração</label><select id="tk-dur" name="duration_min">' . $dur . '</select></div>
      <div class="field"><span class="lbl">Na agenda <span class="ii" tabindex="0" role="img" aria-label="Ajuda: com hora, «Ocupado» bloqueia o bloco na disponibilidade (Equipa); «Livre» fica só como lembrete. Pessoal e Bloqueio são sempre Ocupado e privados." data-tip="Com hora, «Ocupado» bloqueia o bloco na disponibilidade (Equipa); «Livre» fica só como lembrete. Pessoal e Bloqueio são sempre Ocupado e privados.">i</span></span>
        <div class="chips-check"><label class="rb"><input type="radio" name="busy" value="1"' . ((int)($v['busy'] ?? 1) === 1 ? ' checked' : '') . '><span>Ocupado</span></label><label class="rb"><input type="radio" name="busy" value="0"' . ((int)($v['busy'] ?? 1) === 0 ? ' checked' : '') . '><span>Livre</span></label></div></div>
      <div class="field tmeet wide" data-only="meeting"><span class="lbl">Reunião via <span class="muted">(nenhuma = presencial)</span></span>
        <div class="chips-check"><label class="rb"><input type="radio" name="platform" value="meet"' . ($plat === 'meet' ? ' checked' : '') . '><span>' . icon('video') . ' Google Meet</span></label>
          <label class="rb"><input type="radio" name="platform" value="discord"' . ($plat === 'discord' ? ' checked' : '') . '><span>' . icon('video') . ' Discord</span></label>
          <label class="rb"><input type="radio" name="platform" value=""' . ($plat === '' ? ' checked' : '') . '><span>' . icon('pin') . ' Presencial</span></label></div></div>
      <div class="field wide" data-only="local"><label for="tk-loc">Local</label><input type="text" id="tk-loc" name="location" maxlength="200" value="' . e((string)($v['location'] ?? '')) . '" placeholder="Estúdio, morada do cliente…"></div>
      <div class="field"><label for="tk-who">Responsável</label><select id="tk-who" name="member_id">' . $who . '</select></div>
      <div class="field"><label for="tk-deal">Negócio ou lead</label><select id="tk-deal" name="deal_id">' . $deals . '</select></div>
      <div class="field"><label for="tk-ct">Contacto</label>' . contact_pick_html($pdo, 'contact_label', 'contact_id', $v['contact_id'] ? (int)$v['contact_id'] : null, 'tk-ct') . '</div>
      <div class="field"><label for="tk-prio">Prioridade</label><select id="tk-prio" name="priority">' . $prio . '</select></div>
      <div class="field"><label for="tk-rep">Repetir</label><select id="tk-rep" name="repeat_rule">' . $rep . '</select></div>
      <div class="field wide" data-not="block"><span class="lbl">Participantes <span class="muted">(recebem o convite no chat, §17.7; pede data e hora)</span></span><div class="chips-check">' . ($pp ?: '<span class="muted small">Sem outras pessoas.</span>') . '</div></div>
      <div class="field"><label for="tk-ck">Checklist <span class="muted">(um item por linha; [x] = feito)</span></label><textarea id="tk-ck" name="checklist" rows="3" placeholder="[ ] Exportar&#10;[ ] Enviar ao cliente">' . e($ck) . '</textarea></div>
      <div class="field"><label for="tk-att">Anexos <span class="muted">(links, um por linha)</span></label><textarea id="tk-att" name="attachments" rows="3" placeholder="https://…">' . e($att) . '</textarea></div>
    </div>
    ' . ($hist !== '' ? '<details class="thist"><summary>Histórico</summary><ul>' . $hist . '</ul></details>' : '') . '
    <div class="actions">' . ($edit ? '<button type="submit" class="btn ghost danger-t" name="do" value="delete_task" formnovalidate data-confirm-del>Eliminar</button>' : '')
      . '<button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">' . ($edit ? 'Gravar' : 'Criar tarefa') . '</button></div>
  </form>
</div>';
}

/**
 * Atribui faixas aos blocos que se sobrepõem (agenda): cada item recebe lane e lanes.
 * Itens: ['a' => minutos desde 00:00, 'b' => minutos].
 */
function agenda_lanes(array $items): array {
    usort($items, fn($x, $y) => [$x['a'], -$x['b']] <=> [$y['a'], -$y['b']]);
    $out = []; $cluster = []; $end = -1;
    $flush = function () use (&$cluster, &$out) {
        $lanes = [];
        foreach ($cluster as &$it) {
            $l = 0;
            while (isset($lanes[$l]) && $lanes[$l] > $it['a']) $l++;
            $lanes[$l] = $it['b']; $it['lane'] = $l;
        }
        unset($it);
        foreach ($cluster as $it) { $it['lanes'] = count($lanes); $out[] = $it; }
        $cluster = [];
    };
    foreach ($items as $it) {
        if ($cluster && $it['a'] >= $end) $flush();
        $cluster[] = $it; $end = max($end, $it['b']);
    }
    if ($cluster) $flush();
    return $out;
}
