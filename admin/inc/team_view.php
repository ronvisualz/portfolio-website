<?php
/**
 * topper. admin — peças de ecrã da aba Equipa (§17): o painel de chat e os
 * formulários de disponibilidade e de reunião (em todas as páginas, com o
 * chat.js), o cartão da pessoa e o organograma.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/team.php';

function team_opts(array $list, $cur, string $empty = ''): string {
    $h = $empty !== '' ? '<option value="">' . e($empty) . '</option>' : '';
    foreach ($list as $k => $l) $h .= '<option value="' . e((string)$k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . e($l) . '</option>';
    return $h;
}

/** O painel de chat e os formulários de disponibilidade, reunião e «propor outra hora». */
function team_global_html(): string {
    $dur = team_opts(MEETING_DURATIONS, 30);
    $plat = '';
    foreach (MEETING_PLATFORMS as $k => $l) $plat .= '<label class="rb"><input type="radio" name="platform" value="' . $k . '"' . ($k === 'meet' ? ' checked' : '') . '><span>' . icon('video') . ' ' . e($l) . '</span></label>';
    $focus = '';
    foreach (TEAM_FOCUS_OPTIONS as $m => $l) $focus .= '<button type="button" class="chip-btn" data-focus="' . $m . '">' . e($l) . '</button>';
    return '
<div class="chat-back" data-chat-close></div>
<aside class="chatp" id="chatp" aria-label="Chat da equipa" tabindex="-1" hidden>
  <header class="chat-h">
    <button type="button" class="ibtn chat-backbtn" data-chat-home aria-label="Voltar às conversas" data-tip hidden>' . icon('chev-left') . '</button>
    <div class="chat-who" data-chat-who><b>Chat</b><small>Só conversas privadas 1:1</small></div>
    <details class="chat-focus"><summary class="ibtn" aria-label="Modo Foco (não incomodar)" data-tip>' . icon('headphones') . '</summary>
      <div class="chat-focus-m"><b>Modo Foco</b><p class="muted small">Silencia o chat e as notificações; os pedidos de reunião ficam em espera.</p>
        <div class="chips-wrap">' . $focus . '</div><button type="button" class="lnk small" data-focus-off hidden>Desligar o Foco</button></div></details>
    ' . icon_button('x', 'Fechar (Esc)', ['class' => 'ibtn', 'data-chat-close' => true]) . '
  </header>
  <p class="chat-focusbar" data-focus-bar hidden></p>
  <div class="chat-list" data-chat-list><div class="sp-loading">A carregar…</div></div>
  <div class="chat-thread" data-chat-thread hidden>
    <div class="chat-acts"><button type="button" class="btn ghost sm" data-chat-avail>' . icon('calendar') . ' Ver disponibilidade</button>
      <a class="btn ghost sm" data-chat-mail href="#">' . icon('mail') . ' Email</a><a class="btn ghost sm" data-chat-tel href="#" hidden>' . icon('phone') . ' Ligar</a></div>
    <div class="chat-msgs" data-chat-msgs aria-live="polite"></div>
    <form class="chat-compose" data-chat-form autocomplete="off">
      <label class="sr" for="chat-in">Mensagem</label>
      <textarea id="chat-in" name="body" rows="1" maxlength="4000" placeholder="Escreva… (cole o link de uma lead, negócio ou tarefa para o mostrar como cartão)"></textarea>
      <button class="btn sm" type="submit" aria-label="Enviar">' . icon('send') . '</button>
    </form>
  </div>
</aside>

<div class="mdl" id="mdl-avail" role="dialog" aria-modal="true" aria-labelledby="mdl-avail-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <div class="mdl-card wide">
    <h3 id="mdl-avail-t">' . icon('calendar') . ' Disponibilidade · <span data-avail-name></span></h3>
    <p class="muted" data-avail-sub></p>
    <div class="avail-nav"><button type="button" class="ibtn" data-avail-go="prev" aria-label="Semana anterior" data-tip>' . icon('chev-left') . '</button>
      <b data-avail-label></b><button type="button" class="ibtn" data-avail-go="next" aria-label="Semana seguinte" data-tip>' . icon('chev-right') . '</button></div>
    <div class="avail-grid" data-avail-grid></div>
    <p class="muted small">Clique num bloco livre para pedir uma reunião. Os outros veem só «Ocupado»; os admins veem o título.</p>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Fechar</button></div>
  </div>
</div>

<div class="mdl" id="mdl-meet" role="dialog" aria-modal="true" aria-labelledby="mdl-meet-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card" data-meet-form autocomplete="off">
    <h3 id="mdl-meet-t">' . icon('video') . ' Pedir reunião <span data-meet-to></span></h3>
    <p class="muted">O pedido chega no chat com Aceitar · Recusar · Propor outra hora. Ao aceitar fica «Ocupado» na agenda dos dois e o link segue por email.</p>
    <div data-meet-ids></div>
    <div class="grid">
      <div class="field"><label for="mt-day">Dia</label><input type="date" id="mt-day" name="day" required></div>
      <div class="field"><label for="mt-time">Hora</label><input type="time" id="mt-time" name="time" step="900" required></div>
      <div class="field"><label for="mt-dur">Duração</label><select id="mt-dur" name="duration">' . $dur . '</select></div>
      <div class="field"><label for="mt-deal">Negócio (opcional)</label><select id="mt-deal" name="deal_id"><option value="">—</option></select></div>
      <div class="field wide"><label for="mt-sub">Assunto</label><input type="text" id="mt-sub" name="subject" maxlength="200" required placeholder="Ex.: Review dos renders"></div>
      <div class="field wide"><label>Plataforma</label><div class="chips-check">' . $plat . '</div></div>
      <div class="field wide"><label for="mt-link">Link da chamada <span class="muted">(opcional: quem aceita pode colá-lo)</span></label><input type="url" id="mt-link" name="link" maxlength="500" placeholder="https://meet.google.com/… ou https://discord.gg/…"></div>
    </div>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">Enviar pedido</button></div>
  </form>
</div>

<div class="mdl" id="mdl-propose" role="dialog" aria-modal="true" aria-labelledby="mdl-propose-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card" data-propose-form autocomplete="off">
    <input type="hidden" name="id" value="">
    <h3 id="mdl-propose-t">' . icon('clock') . ' Propor outra hora</h3>
    <p class="muted">O pedido fica como «outra hora proposta» e a outra pessoa recebe o novo no chat.</p>
    <div class="grid">
      <div class="field"><label for="pp-day">Dia</label><input type="date" id="pp-day" name="day" required></div>
      <div class="field"><label for="pp-time">Hora</label><input type="time" id="pp-time" name="time" step="900" required></div>
    </div>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">Propor</button></div>
  </form>
</div>';
}

/** O cartão da pessoa (§17.5). Custo/hora e horas/mês só com $admin. */
function team_card_html(PDO $pdo, array $u, array $depts, bool $admin, int $me): string {
    $p = team_presence($u);
    [$sk, $sl] = team_agenda_state($pdo, $u);
    $skills = array_filter(array_map('trim', explode(',', (string)$u['skills'])));
    $dept = team_dept_label($depts, $u['department_id'] !== null ? (int)$u['department_id'] : null);
    $search = mb_strtolower(implode(' ', [$u['name'], team_positions_label($u), $dept, $u['skills']]));
    $h = '<article class="pcard" data-person="' . (int)$u['id'] . '" data-search="' . e($search) . '">'
       . ($u['is_partner'] ? '<span class="socio" data-tip="Sócio">Sócio</span>' : '')
       . team_avatar($u, 'lg')
       . '<b class="pname">' . e($u['name']) . ((int)$u['id'] === $me ? ' <span class="muted small">(eu)</span>' : '') . '</b>'
       . '<span class="prole">' . e(team_positions_label($u) ?: (ROLES[$u['role']] ?? '')) . ($dept !== '' ? ' · ' . e($dept) : '') . '</span>'
       . '<span class="pstate s-' . e($sk) . '" data-pstate="' . (int)$u['id'] . '"><span class="pres-l' . ($p['online'] ? ' on' : '') . '" data-plabel="' . (int)$u['id'] . '">' . e($p['online'] ? 'Online' : $p['label']) . '</span> · ' . ($sk === 'focus' ? icon('headphones') . ' ' : '') . e($sl) . '</span>'
       . ($skills ? '<div class="pskills">' . implode('', array_map(fn($s) => '<span>' . e($s) . '</span>', array_slice($skills, 0, 6))) . '</div>' : '')
       . '<span class="pcontact">' . ($u['phone'] ? e((string)$u['phone']) . ' · ' : '') . '<a href="mailto:' . e((string)$u['email']) . '">' . e((string)$u['email']) . '</a></span>'
       . ($admin ? '<span class="pcost muted small" data-tip="Só admins veem">' . ($u['hourly_cost'] !== null ? e(number_format((float)$u['hourly_cost'], 2, ',', ' ')) . ' €/h' : '— €/h')
                  . ' · ' . ($u['monthly_hours'] !== null ? e(rtrim(rtrim(number_format((float)$u['monthly_hours'], 1, ',', ''), '0'), ',')) . ' h/mês' : '— h/mês') . '</span>' : '')
       . '<div class="pbtn">';
    if ((int)$u['id'] !== $me) {
        $h .= '<button type="button" class="btn ghost sm" data-chat-with="' . (int)$u['id'] . '" aria-label="Chat com ' . e($u['name']) . '" data-tip>' . icon('bubble') . '</button>';
    }
    $h .= '<button type="button" class="btn ghost sm" data-avail="' . (int)$u['id'] . '" aria-label="Ver disponibilidade de ' . e($u['name']) . '" data-tip>' . icon('calendar') . '</button>';
    if ($u['phone']) $h .= '<a class="btn ghost sm" href="tel:' . e(preg_replace('/[^\d+]/', '', (string)$u['phone'])) . '" aria-label="Ligar a ' . e($u['name']) . '" data-tip>' . icon('phone') . '</a>';
    if ((int)$u['id'] === $me) $h .= '<a class="btn ghost sm" href="/admin/conta.php#perfil" aria-label="Editar o meu perfil" data-tip>' . icon('pencil') . '</a>';
    elseif ($admin) $h .= '<a class="btn ghost sm" href="/admin/equipa.php?t=acessos&amp;u=' . (int)$u['id'] . '" aria-label="Conta e acessos de ' . e($u['name']) . '" data-tip>' . icon('user') . '</a>';
    return $h . '</div></article>';
}

/** Um nó do organograma (pessoa). */
function team_org_node(array $u, array $depts, bool $admin, bool $top = false): string {
    $dept = team_dept_label($depts, $u['department_id'] !== null ? (int)$u['department_id'] : null);
    return '<div class="onode' . ($top ? ' top' : '') . '" data-org="' . (int)$u['id'] . '"' . ($admin ? ' draggable="true"' : '') . ' tabindex="0" role="button" data-avail="' . (int)$u['id'] . '" aria-label="' . e($u['name']) . '">'
         . team_avatar($u, 'sm', false)
         . '<span><b>' . e($u['name']) . '</b>' . ($u['is_partner'] ? ' <em class="socio-s">sócio</em>' : '') . '<small>' . e(team_positions_label($u) ?: (ROLES[$u['role']] ?? '')) . '</small>'
         . ($dept !== '' ? '<small class="odep">' . e($dept) . '</small>' : '') . '</span></div>';
}

/**
 * O organograma (§17.4): a árvore a partir de «responde a». Cada pessoa com os subdepartamentos
 * que chefia sem pessoas («equipa a definir») e as posições por preencher, a tracejado.
 */
function team_org_html(PDO $pdo, array $people, array $depts, bool $admin): string {
    $kids = [];
    foreach ($people as $id => $u) {
        $r = $u['reports_to'] !== null && isset($people[(int)$u['reports_to']]) ? (int)$u['reports_to'] : 0;
        if ($r === $id) $r = 0;
        $kids[$r][] = $id;
    }
    $openings = $pdo->query('SELECT o.*, p.name AS pos FROM team_openings o LEFT JOIN positions p ON p.id = o.position_id ORDER BY o.id')->fetchAll();
    $openBy = [];
    foreach ($openings as $o) $openBy[$o['reports_to'] !== null && isset($people[(int)$o['reports_to']]) ? (int)$o['reports_to'] : 0][] = $o;
    $members = [];
    foreach ($people as $u) if ($u['department_id']) $members[(int)$u['department_id']] = true;
    $headOf = [];
    foreach ($depts as $d) if ($d['head_user_id']) $headOf[(int)$d['head_user_id']][] = (int)$d['id'];
    $emptySubs = function (int $uid) use ($headOf, $depts, $members): array {
        $out = [];
        foreach ($headOf[$uid] ?? [] as $did) foreach ($depts as $sd) if ((int)$sd['parent_id'] === $did && !isset($members[(int)$sd['id']])) $out[] = $sd;
        return $out;
    };
    // No topo só quem tem equipa (ou posições abertas) por baixo; quem ainda não responde a
    // ninguém nem tem equipa fica numa faixa à parte, para se arrumar arrastando.
    $loose = [];
    foreach ($kids[0] ?? [] as $i => $id) {
        if (empty($kids[$id]) && empty($openBy[$id]) && !$emptySubs($id)) { $loose[] = $id; unset($kids[0][$i]); }
    }
    $walk = function (int $pid, int $lvl) use (&$walk, $kids, $people, $depts, $admin, $openBy, $emptySubs): string {
        $list = $kids[$pid] ?? [];
        if (!$list && empty($openBy[$pid]) && !$emptySubs($pid)) return '';
        $h = '<ul class="olvl' . ($lvl === 0 ? ' root' : '') . '">';
        foreach ($list as $id) {
            $h .= '<li>' . team_org_node($people[$id], $depts, $admin, $lvl === 0);
            $sub = $walk($id, $lvl + 1);
            $extra = '';
            foreach ($emptySubs($id) as $sd) $extra .= '<li><div class="onode open"><span><b>' . e($sd['name']) . '</b><small>equipa a definir</small></span></div></li>';
            if ($extra !== '') $sub = $sub !== '' ? substr($sub, 0, -5) . $extra . '</ul>' : '<ul class="olvl">' . $extra . '</ul>';
            $h .= $sub . '</li>';
        }
        foreach ($openBy[$pid] ?? [] as $o) {
            $h .= '<li><div class="onode open" data-opening="' . (int)$o['id'] . '"><span><b>' . e($o['pos'] ?: 'Posição') . '</b><small>por preencher'
                . ($o['department_id'] && isset($depts[(int)$o['department_id']]) ? ' · ' . e($depts[(int)$o['department_id']]['name']) : '') . ($o['note'] ? ' · ' . e($o['note']) : '') . '</small></span></div></li>';
        }
        return $h . '</ul>';
    };
    $h = '<div class="org"' . ($admin ? ' data-org-edit' : '') . '>' . $walk(0, 0) . '</div>';
    if ($loose) {
        $h .= '<div class="org-loose"><span class="muted small">Sem «responde a»</span><div>';
        foreach ($loose as $id) $h .= team_org_node($people[$id], $depts, $admin);
        $h .= '</div></div>';
    }
    return $h;
}

/** O horário de trabalho num formulário: um dia por linha (§17.7). */
function team_work_fields(array $u): string {
    $wh = team_work_hours($u);
    $h = '<div class="whours" role="group" aria-label="Horário de trabalho">';
    foreach (TEAM_DAYS_LONG as $d => $l) {
        $on = isset($wh[$d]);
        [$a, $b] = $wh[$d] ?? ['09:00', '18:00'];
        $h .= '<div class="wh-row"><label class="check"><input type="checkbox" name="wd[' . $d . ']" value="1"' . ($on ? ' checked' : '') . '><span>' . e($l) . '</span></label>'
            . '<label class="sr" for="wa' . $d . '">Início ' . e($l) . '</label><input type="time" id="wa' . $d . '" name="wa[' . $d . ']" value="' . e($a) . '" step="900">'
            . '<span class="muted">–</span><label class="sr" for="wb' . $d . '">Fim ' . e($l) . '</label><input type="time" id="wb' . $d . '" name="wb[' . $d . ']" value="' . e($b) . '" step="900"></div>';
    }
    return $h . '</div>';
}

/**
 * Os campos de uma conta (§17.8): papel, departamento/subdepartamento, cargos, sócio,
 * responde a, horário, custo/hora e horas/mês. $v = a conta (ou os valores por defeito).
 */
function team_user_fields(PDO $pdo, array $v, array $actor, string $pfx = 'nu'): string {
    $depts = team_depts($pdo);
    $dopt = '<option value="">—</option>';
    foreach (team_dept_tree($depts) as $n) $dopt .= '<option value="' . (int)$n['d']['id'] . '"' . ((string)($v['department_id'] ?? '') === (string)$n['d']['id'] ? ' selected' : '') . '>' . str_repeat('— ', $n['lvl']) . e($n['d']['name']) . '</option>';
    $ropt = '';
    $roles = assignable_roles($actor);
    if (!in_array($v['role'], $roles, true)) $roles[] = $v['role'];
    foreach ($roles as $r) $ropt .= '<option value="' . $r . '"' . ($v['role'] === $r ? ' selected' : '') . '>' . e(ROLES[$r]) . '</option>';
    $mine = array_keys($v['positions'] ?? []);
    $pos = '';
    foreach (team_positions($pdo) as $id => $p) $pos .= '<label class="chk"><input type="checkbox" name="positions[]" value="' . $id . '"' . (in_array($id, $mine, true) ? ' checked' : '') . '><span>' . e($p['name']) . '</span></label>';
    $popt = '<option value="">— ninguém (topo) —</option>';
    foreach (team_people($pdo) as $id => $p) if ($id !== (int)($v['id'] ?? 0)) $popt .= '<option value="' . $id . '"' . ((string)($v['reports_to'] ?? '') === (string)$id ? ' selected' : '') . '>' . e($p['name']) . '</option>';
    $num = fn($x, int $d) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float)$x, $d, ',', ''), '0'), ',');
    $tip = fn(string $t) => ' <span class="ii" tabindex="0" role="img" aria-label="Ajuda: ' . e($t) . '" data-tip="' . e($t) . '">i</span>';
    return '<div class="grid">'
      . '<div class="field"><label for="' . $pfx . '-name">Nome</label><input type="text" id="' . $pfx . '-name" name="name" required maxlength="120" value="' . e((string)$v['name']) . '"></div>'
      . '<div class="field"><label for="' . $pfx . '-email">Email (é o login)</label><input type="email" id="' . $pfx . '-email" name="email" required maxlength="190" value="' . e((string)$v['email']) . '"></div>'
      . '<div class="field"><label for="' . $pfx . '-role">Papel' . $tip('O que pode fazer na plataforma. Não é o cargo: o cargo é o título na empresa e não dá permissões.') . '</label><select id="' . $pfx . '-role" name="role">' . $ropt . '</select></div>'
      . '<div class="field"><label for="' . $pfx . '-dept">Departamento / subdepartamento</label><select id="' . $pfx . '-dept" name="department_id">' . $dopt . '</select></div>'
      . '<div class="field wide"><span class="lbl">Cargos' . $tip('Títulos na empresa (vários por pessoa). Gerem-se em Utilizadores e acessos › Cargos.') . '</span><div class="chips-check">' . ($pos ?: '<span class="muted">Ainda não há cargos.</span>') . '</div></div>'
      . '<div class="field"><label for="' . $pfx . '-rep">Responde a</label><select id="' . $pfx . '-rep" name="reports_to">' . $popt . '</select></div>'
      . '<div class="field"><span class="lbl">Sócio</span><label class="check switch-l"><input type="checkbox" name="is_partner" value="1"' . (!empty($v['is_partner']) ? ' checked' : '') . '><span>Sim, é sócio</span></label></div>'
      . '<div class="field"><label for="' . $pfx . '-hours">Horas disponíveis por mês' . $tip('Ex.: 168 = 21 dias × 8 h. Dá a capacidade da equipa na Orçamentação. Só admins veem.') . '</label><input type="text" id="' . $pfx . '-hours" name="monthly_hours" inputmode="decimal" maxlength="6" value="' . e($num($v['monthly_hours'] ?? null, 1)) . '" placeholder="168"></div>'
      . '<div class="field"><label for="' . $pfx . '-cost">Custo/hora interno (€)' . $tip('Quanto custa à empresa uma hora desta pessoa (linhas da equipa na Orçamentação). Só admins veem.') . '</label><input type="text" id="' . $pfx . '-cost" name="hourly_cost" inputmode="decimal" maxlength="10" value="' . e($num($v['hourly_cost'] ?? null, 2)) . '" placeholder="25"></div>'
      . '<div class="field"><label for="' . $pfx . '-color">Cor (avatar e Tarefas)</label><input type="color" id="' . $pfx . '-color" name="color" value="' . e((string)($v['color'] ?: '#81CFE3')) . '"></div>'
      . '<div class="field wide"><span class="lbl">Horário de trabalho' . $tip('Por defeito seg–sex 09:00–18:00. Fora do horário a pessoa aparece indisponível.') . '</span>' . team_work_fields($v) . '</div>'
      . '</div>';
}
