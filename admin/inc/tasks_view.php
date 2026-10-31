<?php
/**
 * topper. admin — peças de ecrã da aba Tarefas (§18): filtros, linhas da lista,
 * quadro da equipa, o formulário «+ Nova tarefa» e a agenda de 24 horas.
 * A lógica vive em inc/tasks.php; aqui só se desenha.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/tasks.php';

/** Os filtros do endereço, normalizados. $defWho: o «Quem» com que a lista abre (o do Perfil, ou «Eu» para operadores). */
function tasks_filters(array $g, bool $operator, string $defWho = ''): array {
    if (!in_array($defWho, ['me', 'all'], true) || ($defWho === 'me' && !$operator)) $defWho = $operator ? 'me' : 'all';
    tasks_default_who($defWho);
    $f = [
        'est'    => in_array($g['est'] ?? '', ['todo', 'done', 'all'], true) ? $g['est'] : 'todo',
        'urg'    => in_array($g['urg'] ?? '', ['late', 'today', 'week'], true) ? $g['urg'] : '',
        'quem'   => (string)($g['quem'] ?? $defWho),
        'tipo'   => isset(TASK_TYPES[$g['tipo'] ?? '']) ? $g['tipo'] : '',
        'origem' => isset(TASK_ORIGINS[$g['origem'] ?? '']) ? $g['origem'] : '',
        'neg'    => max(0, (int)($g['neg'] ?? 0)),
        'solto'  => !empty($g['solto']) ? 1 : 0,
    ];
    if (!in_array($f['quem'], ['me', 'all', 'none'], true) && !ctype_digit($f['quem'])) $f['quem'] = $defWho;
    // Um membro (âmbito «só os meus») vê as suas e as por atribuir da sua equipa: o «Quem» não tem mais nada.
    // (O servidor limita sempre o que cada um vê: isto só tira as escolhas que não dariam nada.)
    if (my_scope('tarefas') === 'own' && !in_array($f['quem'], ['me', 'none'], true)) $f['quem'] = 'me';
    return $f;
}

/** O «Quem» com que a lista abre (para o «Limpar filtros» voltar a ele). */
function tasks_default_who(?string $set = null): string {
    static $who = 'all';
    if ($set !== null) $who = $set;
    return $who;
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
    return '<div class="trow' . ($done ? ' is-done' : (task_bucket($t) === 'late' ? ' is-late' : '')) . '" data-task="' . (int)$t['id'] . '" data-mid="' . (int)$t['member_id'] . '">'
         . ($done ? '<button type="button" class="tchk on" data-reopen="' . (int)$t['id'] . '" aria-label="Reabrir" data-tip>' . icon('check') . '</button>'
                  : '<button type="button" class="tchk" data-done="' . (int)$t['id'] . '"' . task_call_attr($t) . ' aria-label="Concluir" data-tip>' . icon('check') . '</button>')
         . task_type_badge($t) . task_origin_badge($t)
         . '<a class="tt" href="' . e($editUrl) . '">' . e($t['title']) . '</a><span class="tex">' . $extra . '</span>'
         . ($lt !== '' ? ($lu !== '' ? '<a class="tlink" href="' . e($lu) . '">' . e(shorten($lt, 40)) . '</a>' : '<span class="tlink">' . e(shorten($lt, 40)) . '</span>') : '<span class="tlink"></span>')
         . task_due_html($t)
         . '<span class="tacts">'
         . (!$done ? '<button type="button" class="tact" data-snooze="' . (int)$t['id'] . '" data-due="' . e((string)$t['due_date']) . '" data-time="' . e(substr((string)($t['due_time'] ?? ''), 0, 5)) . '" aria-label="Adiar" data-tip aria-haspopup="menu">' . icon('clock') . '</button>' : '')
         . '<a class="tact" href="' . e($editUrl) . '" aria-label="Editar" data-tip>' . icon('pencil') . '</a>'
         // «Abrir na origem»: onde a tarefa nasceu (lead, negócio, contacto, Marketing, Financeiro, Toppernote)
         . ($lu !== '' ? '<a class="tact orig" href="' . e($lu) . '" aria-label="Abrir na origem: ' . e($lt) . '" data-tip="Abrir na origem · ' . e(shorten($lt, 40)) . '">' . icon('external') . '</a>' : '')
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

/**
 * O que o «Concluir» de uma chamada pede antes de a dar como feita: o «Como correu?» numa lead (o resultado vai
 * para o histórico da lead), uma nota opcional nas outras chamadas (negócio, contacto ou interna).
 */
function task_call_attr(array $t): string {
    if (($t['kind'] ?? '') !== 'call') return '';
    if (!empty($t['deal_id']) && ($t['deal_phase'] ?? '') === 'lead') {
        $name = preg_replace('/^Ligar a\s+/u', '', (string)$t['title']);
        return ' data-call="lead" data-lead="' . (int)$t['deal_id'] . '" data-name="' . e($name) . '"';
    }
    return ' data-call="note" data-name="' . e((string)$t['title']) . '"';
}

/**
 * A linha que junta as tarefas automáticas iguais (§18.3). «Abrir fila» abre o grupo ali mesmo, com todas as
 * tarefas (e volta a fechar); sem JavaScript, leva à fila da aba de origem.
 */
function task_group_html(array $g, array $members, bool $showWho, ?callable $editUrl = null): string {
    [$label, $type, $origin, $url] = TASK_AUTO_GROUPS[$g['group']];
    $n = count($g['tasks']);
    $first = $g['tasks'][0];
    $gid = 'tg-' . e($g['group']) . '-' . (int)($g['member'] ?? 0) . '-' . (int)$first['id'];
    $items = '';
    if ($editUrl) foreach ($g['tasks'] as $t) $items .= task_row_html($t, $members, $editUrl((int)$t['id']));
    return '<div class="tgwrap" data-gwrap><div class="trow agg" data-group="' . e($g['group']) . '">'
         . '<span class="tchk ghost" aria-hidden="true"></span>'
         . task_type_badge(['kind' => $type]) . task_origin_badge(['kind' => $origin === 'relation' ? 'relation' : 'call', 'deal_id' => $origin === 'lead' ? 1 : null, 'deal_phase' => 'lead'])
         . '<span class="tt"><b>' . e($label) . ' · ' . $n . '</b>' . ($g['late'] ? ' · <span class="late">' . $g['late'] . ' em atraso</span>' : '') . ' <small class="muted">agrupadas</small></span><span class="tex"></span>'
         . '<span class="tlink muted">' . ($showWho && $g['member'] && isset($members[$g['member']]) ? e(first_name($members[$g['member']]['name'])) : '') . '</span>'
         . task_due_html($first)
         . ($items !== '' ? '<a class="tgo" href="' . e($url) . '" data-gopen="' . $gid . '" aria-expanded="false" aria-controls="' . $gid . '"><span class="o">Abrir fila</span><span class="c">Fechar fila</span> ' . icon('chev-down') . '</a>'
                          : '<a class="tgo" href="' . e($url) . '">Abrir fila →</a>')
         . '</div>' . ($items !== '' ? '<div class="tgitems" id="' . $gid . '" hidden>' . $items . '</div>' : '') . '</div>';
}

/** Os filtros (§18.5): botões combináveis e as vistas guardadas. */
function tasks_filters_html(array $f, array $members, ?int $myMember, string $tab, array $views, array $dealOpts, array $counts): string {
    $chip = function (string $k, string $v, string $label, string $cls = '', string $tip = '') use ($f, $tab) {
        $on = (string)$f[$k] === $v;
        return '<a class="fchip' . ($on ? ' on' : '') . ($cls ? ' ' . $cls : '') . '" href="' . e(tasks_url($tab, $f, [$k => $on && $k !== 'est' && $k !== 'quem' ? '' : $v])) . '"' . ($tip ? ' data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"' : '') . ($on ? ' aria-current="true"' : '') . '>' . $label . '</a>';
    };
    $h = '<div class="tfilters">';
    if ($tab === 'tarefas') {
        // Estado: um só botão (Por fazer · Concluídas · Todas); depois os dois atalhos que mais se usam.
        $est = ['todo' => 'Por fazer', 'done' => 'Concluídas', 'all' => 'Todas'];
        $items = '';
        foreach ($est as $v => $l) {
            $on = $f['est'] === $v;
            $items .= '<a class="fpl' . ($on ? ' on' : '') . '" href="' . e(tasks_url($tab, $f, ['est' => $v])) . '"' . ($on ? ' aria-current="true"' : '') . '><span>' . e($l) . '</span>' . ($on ? '<span class="ok">' . icon('check') . '</span>' : '') . '</a>';
        }
        $h .= '<div class="fg"><details class="fpop" data-fpop><summary class="fchip' . ($f['est'] !== 'todo' ? ' on' : '') . '">' . icon('check') . ' ' . e($est[$f['est']]) . ' ' . icon('chev-down', 'chev') . '</summary>'
            . '<div class="fpop-box" role="menu" aria-label="Estado"><div class="fpop-h"><b>Estado</b></div><div class="fpop-l">' . $items . '</div></div></details>';
        $late = $f['urg'] === 'late';
        $h .= '<a class="fchip urg' . ($late ? ' on' : '') . '" href="' . e(tasks_url($tab, $f, ['urg' => $late ? '' : 'late'])) . '"' . ($late ? ' aria-current="true"' : '') . '>' . icon('alert') . ' Em atraso' . ($counts['late'] ? ' <i>' . $counts['late'] . '</i>' : '') . '</a>';
        $none = $f['quem'] === 'none';
        $h .= '<a class="fchip' . ($none ? ' on' : '') . '" href="' . e(tasks_url($tab, $f, ['quem' => $none ? tasks_default_who() : 'none'])) . '"' . ($none ? ' aria-current="true"' : '') . '>' . icon('hand') . ' Por atribuir' . (($counts['none'] ?? 0) ? ' <i class="nb">' . (int)$counts['none'] . '</i>' : '') . '</a>';
        // «Hoje» e «Esta semana» já não têm botão; uma vista guardada com eles mostra-os aqui, para os poder tirar
        if (in_array($f['urg'], ['today', 'week'], true)) $h .= '<a class="fchip on" href="' . e(tasks_url($tab, $f, ['urg' => ''])) . '" data-tip="Tirar este filtro">' . ($f['urg'] === 'today' ? 'Hoje' : 'Esta semana') . ' ' . icon('x') . '</a>';
        $h .= '</div>';
    } else {
        $h .= '<div class="fg"><em>Urgência</em>' . $chip('urg', 'late', 'Em atraso' . ($counts['late'] ? ' <i>' . $counts['late'] . '</i>' : ''), 'urg') . $chip('urg', 'today', 'Hoje') . $chip('urg', 'week', 'Esta semana') . '</div>';
    }
    // «Quem» depende da função (§18.5): admins — todos ou cada pessoa; chefes de equipa — a sua equipa ou cada
    // membro dela; membros — só as suas (sem botão).
    $scope = my_scope('tarefas');
    if ($tab === 'tarefas' && $scope !== 'own') {
        // «Quem»: um botão que abre a lista de pessoas (em vez de um botão por pessoa). «Por atribuir» tem botão próprio.
        $opts = ($myMember !== null && isset($members[$myMember]) ? ['me' => ['Eu', task_who_html($members, $myMember)]] : [])
              + ['all' => [$scope === 'dept' ? 'A minha equipa' : 'Todos', '<span class="fpl-i">' . icon('equipa') . '</span>']];
        foreach ($members as $mid => $m) if ($mid !== $myMember) $opts[(string)$mid] = [$m['name'], task_who_html($members, (int)$mid)];
        $cur = (string)$f['quem'];
        $items = ''; $sep = false;
        foreach ($opts as $v => [$l, $av]) {
            $on = $cur === (string)$v;
            if (is_int($v) && !$sep) { $items .= '<hr>'; $sep = true; }   // as pessoas, depois de Eu · Todos · Por atribuir
            $items .= '<a class="fpl' . ($on ? ' on' : '') . '" href="' . e(tasks_url($tab, $f, ['quem' => (string)$v])) . '"' . ($on ? ' aria-current="true"' : '') . '>' . $av . '<span>' . e($l) . '</span>' . ($on ? '<span class="ok">' . icon('check') . '</span>' : '') . '</a>';
        }
        $label = $opts[$cur][0] ?? $opts['all'][0];
        $h .= '<div class="fg"><details class="fpop" data-fpop><summary class="fchip' . ($cur !== 'all' && $cur !== 'none' ? ' on' : '') . '">' . icon('user') . ' Quem: ' . e($cur === 'me' ? 'Eu' : (ctype_digit($cur) ? first_name($label) : $label)) . ' ' . icon('chev-down', 'chev') . '</summary>'
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
    $def = ['est' => 'todo', 'urg' => '', 'quem' => tasks_default_who(), 'tipo' => '', 'origem' => '', 'neg' => 0];
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
                            'platform' => '', 'location' => '', 'participants' => '', 'private' => 0, 'call_link' => ''], $pre);
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
    // a chamada (§31.4): o link (Meet criado pela plataforma ou colado à mão) e «Criar Google Meet» — nos eventos com
    // hora que não são reuniões; nas reuniões (§31.6) o Meet é criado ao gravar, com os convidados de «Convidar»
    require_once __DIR__ . '/gcal.php';
    require_once __DIR__ . '/task_invites.php';
    $meUid = (int)(current_user()['id'] ?? 0);
    $canMeet = gcal_can_meet($pdo, $meUid);
    $mlink = $edit ? gcal_link($pdo, 'task', (int)$v['id'], 'meet') : null;
    $hasMeet = $mlink !== null;
    $guestDef = '';
    if (!empty($v['contact_id'])) { $cst = $pdo->prepare('SELECT email FROM contacts WHERE id = ?'); $cst->execute([(int)$v['contact_id']]); $guestDef = (string)$cst->fetchColumn(); }
    $link = (string)($v['call_link'] ?? '');
    $meet = '<div class="field wide gmeet" data-call data-gmeet data-task="' . (int)$v['id'] . '"' . ($hasMeet ? ' data-linked' : '') . '><span class="lbl">' . icon('video') . ' Chamada</span>'
          . ($link !== '' ? '<div class="gm-now"><a class="gm-link" href="' . e($link) . '" target="_blank" rel="noopener">' . e(preg_replace('~^https://~', '', $link)) . '</a>' . ($hasMeet ? ' <span class="chip ok">Google Meet</span>' : '') . '</div><div class="gm-resp small" data-gm-resp data-not="meeting"></div>' : '');
    if ($canMeet && !$hasMeet) {
        $meet .= '<div class="gm-make" data-not="meeting"><input type="text" name="gmeet_guests" value="' . e($guestDef) . '" placeholder="Convidados externos (emails, separados por vírgulas)" aria-label="Convidados externos">'
               . ($edit ? '<button type="button" class="btn sm" data-gmeet-create>' . icon('video') . ' Criar Google Meet</button>'
                        : '<label class="check"><input type="checkbox" name="gmeet" value="1"> Criar Google Meet ao gravar</label>')
               . '</div><p class="small muted gm-hint" data-not="meeting">Precisa de dia e hora. Os participantes da equipa e estes convidados recebem o convite do Google (o cliente também). Contas Google gratuitas: chamadas com 3+ pessoas param aos 60 min.</p>';
    }
    $meet .= '<details class="gm-manual"' . ($link !== '' && !$hasMeet ? ' open' : '') . '><summary class="small">' . ($canMeet ? 'Ou colar um link à mão' : 'Colar o link da chamada') . '</summary>'
           . '<input type="url" name="call_link" maxlength="500" value="' . e($link) . '" placeholder="https://meet.google.com/… ou outro link" aria-label="Link da chamada"></details>'
           . (!$canMeet ? '<p class="small muted" data-not="meeting">' . (is_admin() ? 'Para criar o Meet daqui: <a href="/admin/definicoes.php?t=integracoes#set-int_google">Ligar Google</a> (ou cada pessoa liga o seu calendário no Perfil).' : 'Para criar o Meet daqui, ligue o seu Google Calendar no <a href="/admin/conta.php#ligacoes">Perfil</a>.') . '</p>' : '')
           . '<p class="small" data-gm-out hidden aria-live="polite"></p></div>';
    $inv = tinv_ready($pdo) ? task_invite_html($pdo, $v, $edit, $mlink, $guestDef) : '';
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
      <div class="field wide gslots" data-gslots hidden aria-live="polite"></div>
      <div class="field"><span class="lbl">Na agenda <span class="ii" tabindex="0" role="img" aria-label="Ajuda: com hora, «Ocupado» bloqueia o bloco na disponibilidade (Equipa); «Livre» fica só como lembrete. Pessoal e Bloqueio são sempre Ocupado e privados." data-tip="Com hora, «Ocupado» bloqueia o bloco na disponibilidade (Equipa); «Livre» fica só como lembrete. Pessoal e Bloqueio são sempre Ocupado e privados.">i</span></span>
        <div class="chips-check"><label class="rb"><input type="radio" name="busy" value="1"' . ((int)($v['busy'] ?? 1) === 1 ? ' checked' : '') . '><span>Ocupado</span></label><label class="rb"><input type="radio" name="busy" value="0"' . ((int)($v['busy'] ?? 1) === 0 ? ' checked' : '') . '><span>Livre</span></label></div></div>
      <div class="field tmeet wide" data-only="meeting"><span class="lbl">Reunião via <span class="muted">(nenhuma = presencial)</span></span>
        <div class="chips-check"><label class="rb"><input type="radio" name="platform" value="meet"' . ($plat === 'meet' ? ' checked' : '') . '><span>' . icon('video') . ' Google Meet</span></label>
          <label class="rb"><input type="radio" name="platform" value="discord"' . ($plat === 'discord' ? ' checked' : '') . '><span>' . icon('video') . ' Discord</span></label>
          <label class="rb"><input type="radio" name="platform" value=""' . ($plat === '' ? ' checked' : '') . '><span>' . icon('pin') . ' Presencial</span></label></div></div>
      <div class="field wide" data-only="local"><label for="tk-loc">Local</label><input type="text" id="tk-loc" name="location" maxlength="200" value="' . e((string)($v['location'] ?? '')) . '" placeholder="Estúdio, morada do cliente…">'
        . '<div class="tinv-addr" data-tinv-addr hidden><button type="button" class="chip-btn" data-tinv-addr-use>' . icon('pin') . ' <span></span></button></div></div>
      ' . $inv . $meet . '
      <div class="field"><label for="tk-who">Responsável</label><select id="tk-who" name="member_id">' . $who . '</select></div>
      <div class="field"><label for="tk-deal">Negócio ou lead</label><select id="tk-deal" name="deal_id">' . $deals . '</select></div>
      <div class="field"><label for="tk-ct">Contacto</label>' . contact_pick_html($pdo, 'contact_label', 'contact_id', $v['contact_id'] ? (int)$v['contact_id'] : null, 'tk-ct') . '</div>
      <div class="field"><label for="tk-prio">Prioridade</label><select id="tk-prio" name="priority">' . $prio . '</select></div>
      <div class="field"><label for="tk-rep">Repetir</label><select id="tk-rep" name="repeat_rule">' . $rep . '</select></div>
      <div class="field wide" data-only="guests"><span class="lbl">Participantes — recebem o convite no chat</span><div class="chips-check">' . ($pp ?: '<span class="muted small">Sem outras pessoas.</span>') . '</div></div>
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
 * «Convidar» numa reunião (§31.6): etiquetas de email com sugestões (ficha de contacto, negócio, mesma empresa) e
 * email livre validado; os internos (responsável e participantes) vão por defeito com o email de trabalho; a resposta
 * de cada convidado ao lado do email. Por plataforma: o organizador do Meet (conta da topper. por defeito) ou o aviso
 * sem ela; o link do Discord; a morada sugerida para o «Local». Ao gravar, o Meet é criado e os convites enviados.
 */
function task_invite_html(PDO $pdo, array $v, bool $edit, ?array $mlink, string $guestDef): string {
    $meUid = (int)(current_user()['id'] ?? 0);
    $inv = $edit ? tinv_of($v) : [];
    $sug = tinv_suggest($pdo, $v['deal_id'] ? (int)$v['deal_id'] : null, $v['contact_id'] ? (int)$v['contact_id'] : null);
    // uma reunião nova com o contacto (ou a lead) já escolhido: esse email vem marcado
    if (!$edit) {
        if ($guestDef !== '' && filter_var($guestDef, FILTER_VALIDATE_EMAIL)) $inv = [strtolower($guestDef)];
        elseif ($sug['suggest']) $inv = [$sug['suggest'][0]['email']];
    }
    // as respostas: as do Meet (guardadas; o browser pede as de agora à Google) e as dos convites por email
    $resp = [];
    if ($mlink) foreach (json_decode((string)$mlink['responses'], true) ?: [] as $r) $resp[strtolower((string)$r['email'])] = (string)$r['status'];
    if ($edit) foreach (tinv_responses($pdo, (int)$v['id']) as $e => $r) $resp[$e] = $r['status'] !== '' ? $r['status'] : ($r['state'] === 'failed' ? 'failed' : 'needsAction');
    $data = ['inv' => $inv, 'sug' => $sug['suggest'], 'addr' => $sug['address'], 'addr_of' => $sug['address_of'], 'resp' => $resp, 'max' => TINV_MAX];
    $admin = is_admin();
    $set = '/admin/definicoes.php?t=integracoes#set-int_google';
    // Google Meet: o organizador
    $topper = gcal_ready($pdo) && gcal_topper() !== null;
    $mine = gcal_conn($pdo, $meUid) !== null;
    if ($mlink) {
        $who = $mlink['account'] === 'topper' ? 'a conta da topper. (' . (google_topper_account() ?: TOPPER_GOOGLE_ACCOUNT) . ')'
             : 'o Google Calendar de ' . (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)substr((string)$mlink['account'], 5))->fetchColumn();
        $org = '<p class="small muted">' . icon('video') . ' Organizado por ' . e($who) . '. Gravar atualiza o evento e avisa os convidados; tirar um email cancela-lhe o convite.</p>';
    } else {
        $org = $topper || $mine ? '<div class="chips-check tinv-org" role="radiogroup" aria-label="Quem envia o convite"><span class="small muted">Enviado por</span>'
             . ($topper ? '<label class="rb"><input type="radio" name="organizer" value="topper" checked><span>Conta da topper.</span></label>' : '')
             . ($mine ? '<label class="rb"><input type="radio" name="organizer" value="me"' . (!$topper ? ' checked' : '') . '><span>' . ($topper ? 'Enviar do meu Google Calendar' : 'O meu Google Calendar') . '</span></label>' : '')
             . '</div>' : '';
        if (!$topper) {
            $org .= '<p class="small tinv-warn">' . icon('alert') . ' A conta da topper. não está ligada. '
                  . ($admin ? '<a href="' . $set . '">Ligar em Definições › Integrações</a>.' : 'Peça a um admin para a ligar em Definições › Integrações.')
                  . ($mine ? ' Entretanto, o Meet sai do seu Google Calendar.' : ' Entretanto, cole o link da chamada abaixo.') . '</p>';
        } else {
            $org .= '<p class="small muted">Ao gravar (com dia e hora), o Meet é criado e os convites enviados pelo Google — a cada convidado e à equipa, com o email de trabalho. Contas Google gratuitas: chamadas com 3+ pessoas param aos 60 min; os convidados precisam de conta Google para entrar sem pedir.</p>';
        }
    }
    // Discord: a equipa leva o link direto do canal (abre a app no canal); os convidados externos o convite do servidor
    $dl = tinv_discord();
    $dc = (string)(team_meet_settings()['discord']['channel_url'] ?? '');
    $lnk = fn(string $u) => '<a href="' . e($u) . '" target="_blank" rel="noopener">' . e(preg_replace('~^https://~', '', $u)) . '</a>';
    $disc = ($dc !== '' ? '<p class="small">' . icon('link') . ' Equipa: ' . $lnk($dc) . ' — o canal direto, na tarefa e nos convites da equipa.</p>' : '')
          . ($dl !== '' ? '<p class="small">' . icon('link') . ' Convidados: ' . $lnk($dl) . ' — o convite para entrarem no servidor.</p>' : '');
    $miss = array_filter([$dc === '' ? 'o link direto do canal (para a equipa)' : '', $dl === '' ? 'o convite do servidor (para os convidados)' : '']);
    if ($miss) $disc .= '<p class="small tinv-warn">' . icon('alert') . ' Falta ' . implode(' e ', $miss) . '. ' . ($admin ? '<a href="' . $set . '">Definir em Definições › Integrações › Reuniões</a>.' : 'Peça a um admin para o definir em Definições › Integrações.') . '</p>';
    $mailNote = mailer_api_on($pdo) ? '' : ' ' . ($admin ? '<a href="/admin/definicoes.php?t=integracoes">O serviço de envio de email não está ligado</a>: os convites saem pelo servidor e podem cair no spam.' : '');
    $byMail = '<p class="small muted">Ao gravar (com dia e hora), cada convidado recebe o convite por email com o evento para o calendário (.ics) e pode responder Sim / Não / Talvez. Mudar a hora ou o local avisa; tirar um email cancela-lhe o convite.' . $mailNote . '</p>';
    return '<div class="field wide tinv" data-only="meeting" data-tinv="' . e(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">'
         . '<label class="lbl" for="tinv-in">Convidar</label>'
         . '<div class="tinv-box" data-tinv-box><input type="email" id="tinv-in" class="tinv-in" data-tinv-in placeholder="Escreva um email e Enter" autocomplete="off" aria-describedby="tinv-help"></div>'
         . '<input type="hidden" name="invitees" value="' . e(implode(',', $inv)) . '"><input type="hidden" name="invitees_sent" value="1">'
         . '<p class="small neg" data-tinv-err hidden aria-live="polite"></p>'
         . '<div class="tinv-sug" data-tinv-sug></div>'
         . '<p class="small muted" id="tinv-help" data-tinv-team></p>'
         . '<div data-plat="meet">' . $org . '</div><div data-plat="discord">' . $disc . $byMail . '</div><div data-plat="local">' . $byMail . '</div>'
         . '</div>';
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
