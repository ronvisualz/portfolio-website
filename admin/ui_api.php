<?php
/**
 * topper. admin — pedidos das features transversais (§11)
 *
 *   GET  a=search&q=          pesquisa global ⌘K (só o que a pessoa pode ver)
 *   GET  a=notifications      centro de notificações
 *   POST a=notifications_read keys[] (vazio = todas)
 *   POST a=prefs              theme ('' | light | dark) · nav ('' | closed)
 *   POST a=undo               token
 *   POST a=inline             entity, id, field, value
 *   POST a=interaction        deal_id | contact_id, kind, date, body (também sem JavaScript)
 *   GET  a=panel&type=&id=    painel lateral (lead | deal | contact)
 *   POST a=deal_move          id, stage_id — arrastar no Pipeline, sem recarregar
 *
 *   Leads v2 (§12):
 *   GET  a=lead_dups&email=&phone=   duplicados enquanto se escreve (nova lead)
 *   POST a=lead_save          id, f[campo] — «Gravar» na ficha (todos os campos de uma vez, com «Anular»)
 *   POST a=lead_task          id, kind, title, notes, date, time, duration, assignee, repeat — Nova ação
 *   POST a=lead_task_done     id, task — concluir no Foco (uma chamada abre «Como correu?»)
 *   POST a=lead_outcome       id, result (missed | followup), note
 *   POST a=lead_archive       id, reason, note — com «Anular»
 *   GET  a=lead_next&id=      a próxima lead urgente
 *
 *   Contactos v2 (§13):
 *   GET  a=contact_dups&email=&phone=&name=&kind=   duplicados enquanto se escreve (novo contacto)
 *   GET  a=contact_pick&q=    escolher um contacto escrevendo («Trazido por»)
 *   POST a=contact_save       id, f[campo] — «Gravar» na ficha, com «Anular»
 *   POST a=contact_active     id — Ativo / Inativo (só superadmin e admins), com «Anular»
 *   POST a=service_save       id, line, service_id, unit, price, vat, valid_from, notes
 *   POST a=service_remove     id, line — com «Anular»
 *   POST a=contact_task       id, kind, title, notes, date, time, duration, assignee, repeat — Nova ação
 *   POST a=contact_task_done  id, task — concluir no Foco (um follow-up de relação abre «Como correu?»)
 *   POST a=relation_set       id, formality, potential, business, owner_id, cadence_days
 *   POST a=relation_outcome   id, result (talked | missed | meeting | lead), note, next, date, time
 *   POST a=relation_pause     id, until  ·  relation_resume id  ·  relation_stop id (com «Anular»)
 *   POST a=relation_review    id, decision (accept | keep), potential
 *   POST a=contact_merge      id, other — juntar duplicados (gestores)
 *   POST a=cost_rate          cost, stars — avaliação de um trabalho pago (Financeiro)
 *
 * Os POST pedem o token CSRF (campo «csrf» ou cabeçalho X-CSRF-Token).
 * Todas as permissões se verificam aqui, em cada pedido (§4).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ui.php';
require_once __DIR__ . '/inc/reach.php';
require_once __DIR__ . '/inc/alerts.php';
require_once __DIR__ . '/inc/undo.php';
require_once __DIR__ . '/inc/panel.php';
require_once __DIR__ . '/inc/tasks.php';

$json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

function out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (current_user() === null) out(401, ['ok' => false, 'error' => 'A sessão terminou. Entre outra vez.']);
require_login();

$a = (string)($_REQUEST['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post) {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        out(400, ['ok' => false, 'error' => 'Pedido inválido. Recarregue a página e tente outra vez.']);
    }
}

/** A lead do pedido, ativa (ou não, com $active false) e ao meu alcance para editar. */
function lead_for_edit(PDO $pdo, bool $active = true): array {
    require_perm('leads', PERM_EDIT);
    $l = record_get($pdo, (int)($_POST['id'] ?? 0));
    if (!$l || !in_array($l['phase'], ['lead', 'archived'], true) || !record_reach($pdo, $l, PERM_EDIT)) throw new RuntimeException('Lead não encontrada.');
    if ($active && $l['phase'] !== 'lead') throw new RuntimeException('Esta lead já não está ativa.');
    return $l;
}

/** As partes da vista (e da linha da lista) que mudam depois de uma ação. */
function lead_parts(PDO $pdo, int $id): array {
    require_once __DIR__ . '/inc/lead_view.php';
    $l = record_get($pdo, $id);
    $tasks = lead_open_tasks($pdo, [$id])[$id] ?? [];
    $clock = lead_clock($l, lead_deadline_hours($pdo));
    $next = $tasks[0] ?? null;
    return ['focus' => lead_focus_html($tasks, can('leads', PERM_EDIT)), 'focus_n' => count($tasks),
            'history' => timeline_html(timeline($pdo, 'deal', $id)),
            'state' => lead_state_chip($l), 'state_key' => (string)$l['lead_state'], 'ring' => lead_ring($clock), 'level' => $clock['level'] ?? '',
            'next' => $next ? '<span class="' . (task_is_late($next) ? 'neg' : '') . '">' . e((LEAD_TASK_KINDS[$next['kind'] ?? 'task'] ?? LEAD_TASK_KINDS['task'])[0]) . ' · ' . e(lead_task_when($next)) . '</span>'
                            : '<span class="risk-txt">Sem próxima ação</span>',
            'last' => lead_ago($l['last_contact_at'])];
}

function lead_task_default_title(string $kind, string $who): string {
    return match ($kind) {
        'call' => 'Ligar a ' . $who, 'meeting' => 'Reunião com ' . $who, 'email' => 'Email para ' . $who,
        'lunch' => 'Almoço com ' . $who, 'dinner' => 'Jantar com ' . $who,
        'document' => 'Documento para ' . $who, 'event' => 'Evento · ' . $who,
        default => 'Tarefa · ' . $who,
    };
}

/** O contacto do pedido, para editar (Contactos, nível editar). */
function contact_for_edit(PDO $pdo): array {
    require_perm('contactos', PERM_EDIT);
    require_once __DIR__ . '/inc/contacts.php';
    $c = contact_get($pdo, (int)($_POST['id'] ?? 0));
    if (!$c) throw new RuntimeException('Contacto não encontrado.');
    return $c;
}

/** As partes da ficha do contacto que mudam depois de uma ação. */
function contact_parts(PDO $pdo, int $id): array {
    require_once __DIR__ . '/inc/contact_view.php';
    $c = contact_get($pdo, $id);
    $tasks = contact_open_tasks($pdo, $id);
    return ['focus' => contact_focus_html($tasks, can('contactos', PERM_EDIT)), 'focus_n' => count($tasks),
            'history' => timeline_html(timeline($pdo, 'contact', $id)),
            'relation' => contact_relation_html($pdo, $c, relation_get($pdo, $id), can('contactos', PERM_EDIT))];
}

/** Para LIKE … ESCAPE '!'. */
function like(string $s): string {
    return '%' . strtr($s, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}

/** Minúsculas e sem acentos, para comparar nomes do menu. */
function fold(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
}

try {
    switch ($a) {

    // ── pesquisa global ─────────────────────────────────────
    case 'search':
        $q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
        $groups = [];
        $add = function (string $group, array $it) use (&$groups) { $groups[$group][] = $it; };

        // Ir para… (só os separadores a que tem acesso)
        $fq = fold($q);
        $nav = [];
        foreach (NAV_ITEMS as $key => [$label, $href, $ico]) {
            if (can(NAV_TABS[$key] ?? $key)) $nav[] = [$label, $href, $ico];
        }
        $nav[] = ['A minha conta', '/admin/conta.php', 'user'];
        $nav[] = ['Definições (como quer ser tratado, modo)', '/admin/conta.php#definicoes', 'user'];
        if (is_admin()) $nav[] = ['Migração', '/admin/migracao.php', 'migracao'];
        foreach ($nav as [$label, $href, $ico]) {
            if ($fq !== '' && !str_contains(fold($label), $fq) && !str_contains('ir para ' . fold($label), $fq)) continue;
            $add('Ir para', ['title' => $label, 'url' => $href, 'icon' => icon($ico)]);
        }
        if ($fq === '' || str_contains('mudar de modo claro escuro tema', $fq)) {
            $add('Ações', ['title' => 'Mudar de modo (claro / escuro)', 'action' => 'theme', 'icon' => icon('moon')]);
        }

        if (mb_strlen($q) >= 2) {
            $l = like($q);

            // Negócios e IDs: cada fase responde à sua aba.
            $parts = []; $args = [];
            foreach (['pipeline' => "d.phase IN ('pipeline','done','lost')", 'negocios' => "d.phase = 'production'"] as $tab => $cond) {
                if (!can($tab)) continue;
                [$rs, $ra] = reach_sql($pdo, $tab);
                $parts[] = "($cond AND $rs)";
                array_push($args, ...$ra);
            }
            if ($parts) {
                $st = $pdo->prepare("SELECT d.*, s.name AS stage FROM deals d LEFT JOIN funnel_stages s ON s.id = d.stage_id
                                      WHERE d.code IS NOT NULL AND (d.code LIKE ? ESCAPE '!' OR d.name LIKE ? ESCAPE '!')
                                        AND (" . implode(' OR ', $parts) . ') ORDER BY d.code DESC LIMIT 6');
                $st->execute([$l, $l, ...$args]);
                $ph = ['pipeline' => 'Pipeline', 'production' => 'Em produção', 'done' => 'Concluído', 'lost' => 'Perdido'];
                foreach ($st->fetchAll() as $d) {
                    $add('Negócios', ['title' => $d['name'], 'id' => $d['code'], 'sub' => ($ph[$d['phase']] ?? '') . ($d['stage'] ? ' · ' . $d['stage'] : ''),
                                      'url' => deal_url($d), 'panel' => 'deal:' . $d['id'], 'icon' => icon('negocios')]);
                }
            }

            if (can('leads')) {
                [$rs, $ra] = reach_sql($pdo, 'leads');
                $st = $pdo->prepare("SELECT d.id, d.name, d.company, d.person, d.phase, d.email, d.lead_state FROM deals d
                                      WHERE d.phase IN ('lead','archived') AND $rs
                                        AND (d.name LIKE ? ESCAPE '!' OR d.company LIKE ? ESCAPE '!' OR d.person LIKE ? ESCAPE '!'
                                             OR d.email LIKE ? ESCAPE '!' OR d.phone LIKE ? ESCAPE '!')
                                      ORDER BY d.phase = 'archived', d.id DESC LIMIT 6");
                $st->execute([...$ra, $l, $l, $l, $l, $l]);
                foreach ($st->fetchAll() as $d) {
                    [$dn, $dc] = lead_display($d);
                    $add('Leads', ['title' => $dn, 'sub' => trim(($dc !== '' ? $dc . ' · ' : '') . ($d['phase'] === 'archived' ? 'Arquivada' : (LEAD_STATE[$d['lead_state']] ?? ''))),
                                   'url' => '/admin/leads.php?open=' . $d['id'], 'panel' => 'lead:' . $d['id'], 'icon' => icon('leads')]);
                }
            }

            if (can('contactos')) {
                $st = $pdo->prepare("SELECT c.id, c.kind, c.name, c.email, c.role, c.is_freelancer, co.name AS company_name FROM contacts c LEFT JOIN contacts co ON co.id = c.company_id
                                      WHERE c.name LIKE ? ESCAPE '!' OR c.email LIKE ? ESCAPE '!' OR c.phone LIKE ? ESCAPE '!'
                                         OR c.nif LIKE ? ESCAPE '!' OR c.role LIKE ? ESCAPE '!' OR co.name LIKE ? ESCAPE '!'
                                      ORDER BY c.name LIMIT 6");
                $st->execute([$l, $l, $l, $l, $l, $l]);
                foreach ($st->fetchAll() as $c) {
                    $sub = $c['kind'] === 'company' ? 'Empresa' : ($c['company_name'] ?: ((int)$c['is_freelancer'] ? 'Freelancer' : 'Pessoa'));
                    $add('Contactos', ['title' => $c['name'], 'sub' => $sub . ($c['role'] !== '' ? ' · ' . $c['role'] : ($c['email'] !== '' ? ' · ' . $c['email'] : '')),
                                       'url' => '/admin/contact.php?id=' . $c['id'], 'panel' => 'contact:' . $c['id'], 'icon' => icon($c['kind'] === 'company' ? 'building' : 'contactos')]);
                }
            }

            if (can('tarefas')) {
                require_once __DIR__ . '/inc/tasks.php';
                $visible = task_visible_members($pdo, team_members($pdo));
                $vis = $visible === null ? '' : ' AND (t.member_id IS NULL' . ($visible ? ' OR t.member_id IN (' . implode(',', array_map('intval', $visible)) . ')' : '') . ')';
                $st = $pdo->prepare("SELECT t.id, t.title, t.status, t.due_date, tm.name AS who FROM tasks t LEFT JOIN team_members tm ON tm.id = t.member_id
                                      WHERE t.title LIKE ? ESCAPE '!'$vis ORDER BY t.status = 'done', t.due_date IS NULL, t.due_date LIMIT 6");
                $st->execute([$l]);
                foreach ($st->fetchAll() as $t) {
                    $add('Tarefas', ['title' => $t['title'], 'sub' => trim(($t['who'] ?? 'Por atribuir') . ' · ' . (TASK_STATUS[$t['status']] ?? '') . ($t['due_date'] ? ' · ' . date('d/m', strtotime($t['due_date'])) : '')),
                                     'url' => '/admin/calendar.php?view=tarefas&edit=' . $t['id'], 'icon' => icon('tarefas')]);
                }
            }

            // Ficheiros: pelo nome, no índice do Drive (sem a pasta de sistema nem os documentos privados).
            if (can('drive') && db_has_table($pdo, 'drive_index')) {
                $st = $pdo->prepare("SELECT obj_key FROM drive_index WHERE obj_key LIKE ? ESCAPE '!' AND obj_key NOT LIKE '.topper/%'
                                        AND status <> 'private' ORDER BY obj_key LIMIT 6");
                $st->execute([$l]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
                    $dir = str_contains($k, '/') ? substr($k, 0, strrpos($k, '/') + 1) : '';
                    $add('Ficheiros', ['title' => basename($k), 'sub' => $dir !== '' ? rtrim($dir, '/') : 'Drive',
                                       'url' => '/admin/drive.php' . ($dir !== '' ? '?p=' . rawurlencode($dir) : ''), 'icon' => icon('drive')]);
                }
            }
        }
        $outG = [];
        foreach ($groups as $name => $items) $outG[] = ['name' => $name, 'items' => $items];
        out(200, ['ok' => true, 'groups' => $outG]);

    // ── notificações ────────────────────────────────────────
    case 'notifications':
        $list = notifications($pdo);
        foreach ($list as &$n) $n['icon'] = icon($n['icon']);
        unset($n);
        out(200, ['ok' => true, 'items' => $list, 'unread' => count(array_filter($list, fn($n) => $n['unread']))]);

    case 'notifications_read':
        if (!$post) break;
        $keys = $_POST['keys'] ?? null;
        notifications_mark($pdo, is_array($keys) && $keys ? array_map('strval', $keys) : null);
        out(200, ['ok' => true, 'unread' => notifications_unread($pdo)]);

    // ── preferências na conta ───────────────────────────────
    case 'prefs':
        if (!$post) break;
        if (isset($_POST['theme'])) {
            $t = (string)$_POST['theme'];
            if (!in_array($t, ['', 'light', 'dark'], true)) out(400, ['ok' => false, 'error' => 'Modo inválido.']);
            $pdo->prepare('UPDATE users SET pref_theme = ? WHERE id = ?')->execute([$t, (int)current_user()['id']]);
        }
        if (isset($_POST['nav'])) {
            $n = (string)$_POST['nav'];
            if (!in_array($n, ['', 'closed'], true)) out(400, ['ok' => false, 'error' => 'Opção inválida.']);
            $pdo->prepare('UPDATE users SET pref_nav = ? WHERE id = ?')->execute([$n, (int)current_user()['id']]);
        }
        out(200, ['ok' => true]);

    // ── anular ──────────────────────────────────────────────
    case 'undo':
        if (!$post) break;
        $msg = undo_run($pdo, (string)($_POST['token'] ?? ''));
        out(200, ['ok' => true, 'msg' => $msg]);

    // ── edição no próprio campo ─────────────────────────────
    case 'inline':
        if (!$post) break;
        $entity = (string)($_POST['entity'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $field = (string)($_POST['field'] ?? '');
        $before = $entity === 'lead' ? record_get($pdo, $id) : null;
        $r = inline_save($pdo, $entity, $id, $field, $_POST['value'] ?? '');
        $token = '';
        if ((string)$r['prev'] !== (string)$r['value']) {
            if ($entity === 'lead' && $field === 'lead_state') {
                $token = undo_push('lead_state', ['id' => $id, 'from' => $before['lead_state'], 'to' => $r['value'], 'last_touch' => $before['last_touch'],
                                                  'state_since' => $before['state_since'], 'no_answer' => (int)$before['no_answer']]);
            } elseif ($entity === 'lead' && $field === 'owner_id') {
                $token = undo_push('assign', ['id' => $id, 'from' => $before['owner_id'] !== null ? (int)$before['owner_id'] : null, 'to' => $r['value']]);
            } else {
                $token = undo_push('inline', ['entity' => $entity, 'id' => $id, 'field' => $field, 'prev' => $r['prev']]);
            }
        }
        out(200, ['ok' => true, 'display' => $r['display'], 'value' => $r['value'], 'undo' => $token, 'reload' => !empty($r['reload'])]);

    // ── registar interação ──────────────────────────────────
    case 'interaction':
        if (!$post) break;
        $dealId = (int)($_POST['deal_id'] ?? 0) ?: null;
        $contactId = (int)($_POST['contact_id'] ?? 0) ?: null;
        if ($dealId) {
            $d = record_get($pdo, $dealId);
            if (!record_reach($pdo, $d, PERM_EDIT)) throw new RuntimeException('Registo não encontrado.');
            $type = 'deal'; $id = $dealId;
        } elseif ($contactId) {
            require_perm('contactos', PERM_EDIT);
            $st = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE id = ?');
            $st->execute([$contactId]);
            if (!(int)$st->fetchColumn()) throw new RuntimeException('Contacto não encontrado.');
            $type = 'contact'; $id = $contactId;
        } else {
            throw new RuntimeException('Registo não encontrado.');
        }
        interaction_add($pdo, $dealId, $contactId, (string)($_POST['kind'] ?? 'note'), (string)($_POST['body'] ?? ''), (string)($_POST['date'] ?? ''));
        // Numa relação acompanhada, uma chamada, email ou reunião registada conta como toque (§13.5).
        if ($contactId && in_array((string)($_POST['kind'] ?? ''), ['call', 'email', 'meeting'], true)) {
            require_once __DIR__ . '/inc/contacts.php';
            if (relation_get($pdo, $contactId)) relation_touch($pdo, $contactId, null, INTERACTION_KINDS[(string)$_POST['kind']][0] . ' registada');
        }
        if (!$json) {
            require_once __DIR__ . '/inc/layout.php';
            flash('Interação registada.');
            $back = (string)($_POST['back'] ?? '');
            redirect(str_starts_with($back, '/admin/') ? $back : '/admin/');
        }
        out(200, ['ok' => true, 'msg' => 'Interação registada.', 'html' => timeline_html(timeline($pdo, $type, $id))]);

    // ── painel lateral ──────────────────────────────────────
    case 'panel':
        $type = (string)($_GET['type'] ?? '');
        $id = (int)($_GET['id'] ?? 0);
        if ($type === 'lead' || $type === 'deal') {
            $d = record_get($pdo, $id);
            $isLead = $d && in_array($d['phase'], ['lead', 'archived'], true);
            if (!record_reach($pdo, $d) || ($type === 'lead') !== $isLead) out(404, ['ok' => false, 'error' => 'Registo não encontrado.']);
            out(200, ['ok' => true] + ($isLead ? panel_lead($pdo, $d) : panel_deal($pdo, $d)));
        }
        if ($type === 'contact') {
            require_perm('contactos', PERM_VIEW);
            require_once __DIR__ . '/inc/contacts.php';
            $c = contact_get($pdo, $id);
            if (!$c) out(404, ['ok' => false, 'error' => 'Contacto não encontrado.']);
            out(200, ['ok' => true] + panel_contact($pdo, $c));
        }
        break;


    // ── Leads v2 (§12) ──────────────────────────────────────
    // Ficha da lead: «Editar» abre todos os campos, «Gravar» grava-os de uma vez, com as mesmas
    // regras da edição no campo (empresa/pessoa em Contactos, prazo ao mudar de estado,
    // atribuição pela hierarquia) e um só «Anular». Tudo ou nada.
    case 'lead_save':
        if (!$post) break;
        $l = lead_for_edit($pdo);
        $id = (int)$l['id'];
        $in = $_POST['f'] ?? null;
        if (!is_array($in)) throw new RuntimeException('Nada para gravar.');
        $fields = inline_fields($l)['lead'];
        $want = [];
        foreach ($fields as $f => $def) {
            if (!array_key_exists($f, $in) && $def[0] !== 'multi') continue;
            $v = $in[$f] ?? [];
            if ($def[0] === 'multi') {
                if (!array_key_exists('types_sent', $in)) continue;   // o formulário marca que mandou os tipos
                $v = implode(', ', array_map('strval', is_array($v) ? $v : []));
            }
            if (!is_string($v)) throw new RuntimeException('Valor inválido.');
            $v = str_replace("\r\n", "\n", $v);   // as caixas de texto do browser mandam \r\n
            $cur = $f === 'owner_id' ? ($l['owner_id'] === null ? 'none' : (string)$l['owner_id']) : (string)$l[$f];
            if (trim($v) !== trim($cur)) $want[$f] = $v;
        }
        // «Trazido por» (§13.4): o contacto escolhido ou escrito; vazio = ninguém.
        $bbSet = false; $bbTo = null;
        if (array_key_exists('brought_by', $in)) {
            require_once __DIR__ . '/inc/contacts.php';
            $bbTo = contact_pick_resolve($pdo, $in['brought_by_id'] ?? '', is_string($in['brought_by']) ? $in['brought_by'] : '');
            $bbSet = $bbTo !== ($l['brought_by_id'] !== null ? (int)$l['brought_by_id'] : null);
        }
        $co = trim($want['company'] ?? (string)$l['company']);
        $pe = trim($want['person'] ?? (string)$l['person']);
        if ($co === '' && $pe === '') throw new RuntimeException('A lead precisa de empresa ou de pessoa de contacto.');
        // A que fica preenchida grava primeiro (a regra «empresa ou pessoa» vê o valor já gravado).
        uksort($want, fn($a, $b) => (int)!(in_array($a, ['company', 'person'], true) && trim($want[$a]) !== '')
                                  <=> (int)!(in_array($b, ['company', 'person'], true) && trim($want[$b]) !== ''));
        $subs = [];
        $pdo->beginTransaction();
        try {
            foreach ($want as $f => $v) {
                $before = record_get($pdo, $id);
                $r = inline_save($pdo, 'lead', $id, $f, $v);
                if ((string)$r['prev'] === (string)$r['value']) continue;
                if ($f === 'lead_state') {
                    $subs[] = ['lead_state', ['id' => $id, 'from' => $before['lead_state'], 'to' => $r['value'], 'last_touch' => $before['last_touch'],
                                              'state_since' => $before['state_since'], 'no_answer' => (int)$before['no_answer']]];
                } elseif ($f === 'owner_id') {
                    $subs[] = ['assign', ['id' => $id, 'from' => $before['owner_id'] !== null ? (int)$before['owner_id'] : null, 'to' => $r['value']]];
                } else {
                    $subs[] = ['inline', ['entity' => 'lead', 'id' => $id, 'field' => $f, 'prev' => $r['prev']]];
                }
            }
            if ($bbSet) {
                $subs[] = ['brought_by', ['id' => $id, 'from' => $l['brought_by_id'] !== null ? (int)$l['brought_by_id'] : null, 'to' => $bbTo]];
                deal_set_brought_by($pdo, record_get($pdo, $id), $bbTo, (int)current_user()['id']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $n = count($subs);
        $msg = $n === 0 ? 'Sem alterações.' : ($n === 1 ? '1 campo gravado.' : $n . ' campos gravados.');
        $token = $n ? undo_push('multi', ['subs' => array_reverse($subs), 'msg' => 'Ficha reposta como estava.']) : '';
        // Na ficha completa a página recarrega: o aviso com «Anular» aparece a seguir.
        if (!empty($_POST['flash'])) $_SESSION['flash'][] = ['m' => $msg, 'k' => 'ok', 'u' => $token];   // como flash() do layout
        $l = record_get($pdo, $id);
        [$dn, $dc] = lead_display($l);
        $owner = $l['owner_id'] ? (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$l['owner_id'])->fetchColumn() : 'Por atribuir';
        out(200, ['ok' => true, 'changed' => $n, 'msg' => $msg, 'undo' => $token,
                  'name' => $dn, 'company' => $dc, 'owner' => $owner] + lead_parts($pdo, $id));

    // Duplicados enquanto se escreve telefone/email (§12.1). Leads fora do meu
    // alcance aparecem só como «existe outro registo», sem nome.
    case 'lead_dups':
        require_perm('leads', PERM_EDIT);
        $d = find_duplicates($pdo, (string)($_GET['email'] ?? ''), (string)($_GET['phone'] ?? ''));
        $me = me_row($pdo);
        $leads = []; $hidden = 0;
        foreach ($d['deals'] as $r) {
            if (in_array($r['phase'], ['lead', 'archived'], true) && lead_in_reach($pdo, $r + ['owner_id' => $r['owner_id']], $me)) {
                $leads[] = ['id' => (int)$r['id'], 'name' => lead_display_name($r), 'phase' => $r['phase']];
            } elseif (record_reach($pdo, record_get($pdo, (int)$r['id']))) {
                $leads[] = ['id' => (int)$r['id'], 'name' => ($r['code'] ? $r['code'] . ' · ' : '') . $r['name'], 'phase' => $r['phase']];
            } else {
                $hidden++;
            }
        }
        $contacts = [];
        if (can('contactos')) {
            foreach ($d['contacts'] as $c) {
                $co = $c['company_id'] ? (string)$pdo->query('SELECT name FROM contacts WHERE id = ' . (int)$c['company_id'])->fetchColumn() : '';
                $contacts[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'kind' => $c['kind'], 'company' => $co];
            }
        }
        out(200, ['ok' => true, 'leads' => $leads, 'contacts' => $contacts, 'hidden' => $hidden]);

    // Nova ação: cria uma tarefa ligada à lead (aparece também na aba Tarefas).
    case 'lead_task':
        if (!$post) break;
        $l = lead_for_edit($pdo);
        $kind = (string)($_POST['kind'] ?? 'task');
        if (!isset(LEAD_TASK_KINDS[$kind])) throw new RuntimeException('Escolha o tipo de ação.');
        $date = (string)($_POST['date'] ?? '');
        $time = (string)($_POST['time'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Indique a data.');
        if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) throw new RuntimeException('Indique uma hora válida.');
        $who = (int)($_POST['assignee'] ?? 0) ?: (int)current_user()['id'];
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$who]);
        $target = $st->fetch();
        if (!$target || !can_assign_to(me_row($pdo), $target)) throw new RuntimeException('Não pode atribuir tarefas a essa pessoa.');
        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 200));
        if ($title === '') $title = lead_task_default_title($kind, lead_display_name($l));
        $dur = (int)($_POST['duration'] ?? 0);
        lead_task_create($pdo, $l, $kind, $title, trim(mb_substr((string)($_POST['notes'] ?? ''), 0, 4000)),
                         $date . ($time !== '' ? ' ' . $time : ''), $dur > 0 && $dur <= 600 ? $dur : null, $who,
                         (string)($_POST['repeat'] ?? ''), (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => 'Tarefa criada.'] + lead_parts($pdo, (int)$l['id']));

    // Foco: concluir. Uma chamada abre «Como correu?» (§12.8).
    case 'lead_task_done':
        if (!$post) break;
        $l = lead_for_edit($pdo, false);
        $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND deal_id = ?');
        $st->execute([(int)($_POST['task'] ?? 0), (int)$l['id']]);
        $t = $st->fetch();
        if (!$t || $t['status'] === 'done') throw new RuntimeException('Essa tarefa já não está aberta.');
        $snap = ['id' => (int)$t['id'], 'status' => $t['status'], 'done_at' => $t['done_at'], 'member_id' => $t['member_id'], 'position' => $t['position']];
        $snap['spawned'] = task_done($pdo, (int)$t['id']);   // fica no histórico da lead; se se repete, cria a seguinte
        // Reunião, email, almoço ou jantar feitos contam como contacto e, em Follow-Up, como follow-up.
        if (in_array($t['kind'], ['meeting', 'email', 'lunch', 'dinner'], true) && $l['phase'] === 'lead') {
            lead_touch($pdo, (int)$l['id']);
            if ($l['lead_state'] === 'follow_up') $pdo->prepare('UPDATE deals SET state_since = ? WHERE id = ?')->execute([now_php(), $l['id']]);
        }
        out(200, ['ok' => true, 'msg' => '«' . $t['title'] . '» feita.', 'undo' => undo_push('task', $snap),
                  'call' => $t['kind'] === 'call' && $l['phase'] === 'lead'] + lead_parts($pdo, (int)$l['id']));

    // «Como correu?»: chamada não atendida ou follow-up (os outros dois botões são Arquivar e Converter).
    case 'lead_outcome':
        if (!$post) break;
        $l = lead_for_edit($pdo);
        $res = (string)($_POST['result'] ?? '');
        if ($res === 'missed') {
            $n = (int)$l['no_answer'] + 1;
            $pdo->prepare("UPDATE deals SET lead_state = 'sem_resposta', state_since = ?, no_answer = ? WHERE id = ?")->execute([now_php(), $n, $l['id']]);
            deal_log($pdo, (int)$l['id'], 'call_miss', ['note' => $n . '.ª tentativa · prazo recomeçou']);
            lead_touch($pdo, (int)$l['id']);
            out(200, ['ok' => true, 'msg' => 'Chamada não atendida (' . $n . '.ª tentativa).', 'attempt' => $n, 'suggest_archive' => $n >= 3,
                      'next' => lead_next_urgent($pdo, me_row($pdo), (int)$l['id'])] + lead_parts($pdo, (int)$l['id']));
        }
        if ($res === 'followup') {
            $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 4000));
            $pdo->prepare("UPDATE deals SET lead_state = 'follow_up', state_since = ?, no_answer = 0 WHERE id = ?")->execute([now_php(), $l['id']]);
            deal_log($pdo, (int)$l['id'], 'call_ok', ['note' => $l['lead_state'] !== 'follow_up' ? (LEAD_STATE[$l['lead_state']] ?? '') . ' → Follow-Up' : 'Follow-Up']);
            if ($note !== '') interaction_add($pdo, (int)$l['id'], null, 'call', $note, today_lisbon());
            lead_touch($pdo, (int)$l['id']);
            out(200, ['ok' => true, 'msg' => 'Follow-Up registado. Marque o passo seguinte.',
                      'next' => lead_next_urgent($pdo, me_row($pdo), (int)$l['id'])] + lead_parts($pdo, (int)$l['id']));
        }
        throw new RuntimeException('Escolha como correu.');

    // Arquivar com motivo (§12.9), com «Anular».
    case 'lead_archive':
        if (!$post) break;
        $l = lead_for_edit($pdo);
        $r = (string)($_POST['reason'] ?? '');
        $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 255));
        if (!isset(ARCHIVE_REASONS[$r]) || $r === 'perdido_migracao') throw new RuntimeException('Escolha o motivo.');
        if ($r === 'outro' && $note === '') throw new RuntimeException('Escreva o motivo.');
        out(200, ['ok' => true, 'msg' => 'Lead arquivada.', 'undo' => undo_push('lead_archive', lead_archive($pdo, $l, $r, $note)),
                  'next' => lead_next_urgent($pdo, me_row($pdo), (int)$l['id'])]);

    case 'lead_next':
        require_perm('leads', PERM_VIEW);
        out(200, ['ok' => true, 'next' => lead_next_urgent($pdo, me_row($pdo), (int)($_GET['id'] ?? 0))]);


    // ── Contactos v2 (§13) ──────────────────────────────────
    // Duplicados enquanto se escreve no «+ Adicionar contacto»: mesmo email/telefone, ou o mesmo nome.
    case 'contact_dups':
        require_perm('contactos', PERM_EDIT);
        require_once __DIR__ . '/inc/contacts.php';
        $d = find_duplicates($pdo, (string)($_GET['email'] ?? ''), (string)($_GET['phone'] ?? ''));
        $found = [];
        foreach ($d['contacts'] as $c) $found[(int)$c['id']] = 'email ou telefone';
        $name = trim((string)($_GET['name'] ?? ''));
        if (mb_strlen($name) >= 3) {
            $st = $pdo->prepare('SELECT id FROM contacts WHERE LOWER(TRIM(name)) = ? LIMIT 5');
            $st->execute([mb_strtolower($name)]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) $found[(int)$cid] ??= 'nome';
        }
        $list = [];
        foreach ($found as $cid => $why) {
            $c = contact_get($pdo, $cid);
            if ($c) $list[] = ['id' => $cid, 'name' => $c['name'], 'kind' => $c['kind'], 'sub' => contact_sub($c), 'why' => $why];
        }
        out(200, ['ok' => true, 'contacts' => $list]);

    case 'contact_pick':
        if (!can('contactos') && !can('leads') && !can('pipeline') && !can('negocios')) out(403, ['ok' => false, 'error' => 'Sem permissão.']);
        require_once __DIR__ . '/inc/contacts.php';
        out(200, ['ok' => true, 'items' => contact_pick_search($pdo, (string)($_GET['q'] ?? ''))]);

    // Ficha: «Editar» abre todos os campos, «Gravar» grava-os de uma vez, com um só «Anular».
    case 'contact_save':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $id = (int)$c['id'];
        $in = $_POST['f'] ?? null;
        if (!is_array($in)) throw new RuntimeException('Nada para gravar.');
        $in = array_map(fn($v) => is_string($v) ? str_replace("\r\n", "\n", $v) : $v, $in);
        // Só muda o que vem no pedido: o resto fica como está.
        $cur = ['name' => $c['name'], 'role' => $c['role'], 'email' => $c['email'], 'phone' => $c['phone'], 'links' => (string)$c['links'], 'nif' => $c['nif'],
                'address' => $c['address'], 'birthday' => (string)$c['birthday'], 'notes' => (string)$c['notes'], 'company' => (string)$c['company_name']];
        foreach (array_keys(CONTACT_TYPES) as $f) if ((int)$c[$f] === 1) $cur[$f] = '1';
        // Os tipos: o formulário marca que os mandou (os desmarcados não vêm).
        $in = array_key_exists('types_sent', $in) ? $in + array_diff_key($cur, CONTACT_TYPES) : $in + $cur;
        $kind = in_array($in['kind'] ?? '', ['person', 'company'], true) ? $in['kind'] : $c['kind'];
        if ($kind === 'person' && $c['kind'] === 'company' && contact_people($pdo, $id)) throw new RuntimeException('Esta empresa tem pessoas ligadas: continua empresa.');
        $v = contact_input($in, $kind);
        if ($v['name'] === '') throw new RuntimeException('Indique o nome.');
        $coName = $kind === 'person' ? trim((string)($in['company'] ?? (string)$c['company_name'])) : '';
        if ($v['is_freelancer'] && $coName !== '') throw new RuntimeException('Um freelancer é uma pessoa sem empresa: tire a empresa ou desmarque Freelancer.');
        if ($coName !== '' && mb_strtolower($coName) === mb_strtolower($v['name'])) throw new RuntimeException('A empresa não pode ter o mesmo nome da pessoa.');
        $before = contact_snapshot($c);
        $labels = ['name' => 'nome', 'role' => 'cargo', 'email' => 'email', 'phone' => 'telefone', 'links' => 'redes', 'nif' => 'NIF', 'address' => 'morada',
                   'birthday' => 'aniversário', 'notes' => 'notas', 'is_client' => 'tipo', 'is_supplier' => 'tipo', 'is_freelancer' => 'tipo', 'is_partner' => 'tipo'];
        $pdo->beginTransaction();
        try {
            $set = $v + ($kind === 'company' ? ['company_id' => null, 'role' => ''] : []);
            $pdo->prepare('UPDATE contacts SET kind = ?, ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($set))) . ' WHERE id = ?')
                ->execute([$kind, ...array_values($set), $id]);
            if ($kind === 'person') contact_set_company($pdo, contact_get($pdo, $id), $coName, (int)current_user()['id']);
            $now = contact_get($pdo, $id);
            $changed = [];
            foreach ($labels as $f => $lab) if ((string)$before[$f] !== (string)$now[$f]) $changed[$lab] = true;
            if ($kind !== $c['kind']) $changed[$kind === 'person' ? 'passou a pessoa' : 'passou a empresa'] = true;
            if ($changed) contact_log($pdo, $id, 'c_edit', ucfirst(implode(', ', array_keys($changed))));
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $n = count($changed) + ((int)$before['company_id'] !== (int)$now['company_id'] ? 1 : 0);
        $msg = $n === 0 ? 'Sem alterações.' : 'Contacto gravado.';
        $token = $n ? undo_push('contact_restore', ['id' => $id, 'before' => $before, 'after' => contact_snapshot($now), 'kind_before' => $c['kind'], 'kind_after' => $kind]) : '';
        if (!empty($_POST['flash'])) $_SESSION['flash'][] = ['m' => $msg, 'k' => 'ok', 'u' => $token];
        out(200, ['ok' => true, 'changed' => $n, 'msg' => $msg, 'undo' => $token, 'name' => $now['name'], 'sub' => contact_sub($now)]);

    case 'contact_active':
        if (!$post) break;
        require_perm('contactos', PERM_VIEW);
        require_once __DIR__ . '/inc/contact_view.php';
        if (!contacts_admin($pdo)) throw new RuntimeException('Só superadmin e admins mudam o estado Ativo / Inativo.');
        $c = contact_get($pdo, (int)($_POST['id'] ?? 0));
        if (!$c) throw new RuntimeException('Contacto não encontrado.');
        if (!contact_is_provider($c)) throw new RuntimeException('Só fornecedores e freelancers têm estado Ativo / Inativo.');
        $to = (int)$c['is_active'] === 1 ? 0 : 1;
        $pdo->prepare('UPDATE contacts SET is_active = ? WHERE id = ?')->execute([$to, $c['id']]);
        contact_log($pdo, (int)$c['id'], 'c_active', $to ? 'Inativo → Ativo' : 'Ativo → Inativo');
        out(200, ['ok' => true, 'msg' => $c['name'] . ($to ? ' está ativo.' : ' está inativo: deixa de aparecer na Orçamentação.'), 'active' => $to,
                  'html' => contact_active_html(contact_get($pdo, (int)$c['id']), true),
                  'undo' => undo_push('contact_active', ['id' => (int)$c['id'], 'from' => 1 - $to, 'to' => $to])]);

    case 'service_save':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        require_once __DIR__ . '/inc/contact_view.php';
        if (!contact_is_provider($c)) throw new RuntimeException('Só fornecedores e freelancers têm serviços e preços.');
        $v = service_line_input($pdo, $_POST);
        service_line_save($pdo, (int)$c['id'], (int)($_POST['line'] ?? 0) ?: null, $v, (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => 'Serviço gravado.', 'html' => contact_services_html($pdo, contact_get($pdo, (int)$c['id']), true),
                  'history' => timeline_html(timeline($pdo, 'contact', (int)$c['id']))]);

    case 'service_remove':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        require_once __DIR__ . '/inc/contact_view.php';
        $st = $pdo->prepare('SELECT cs.*, s.name AS service_name FROM contact_services cs LEFT JOIN services s ON s.id = cs.service_id WHERE cs.id = ? AND cs.contact_id = ? AND cs.ended_at IS NULL');
        $st->execute([(int)($_POST['line'] ?? 0), (int)$c['id']]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('Esta linha já não está em vigor.');
        $pdo->prepare('UPDATE contact_services SET ended_at = ? WHERE id = ?')->execute([today_lisbon(), $r['id']]);
        contact_log($pdo, (int)$c['id'], 'c_price', 'Tirado: ' . service_line_label($r) . ' · ' . service_line_price($r));
        out(200, ['ok' => true, 'msg' => '«' . service_line_label($r) . '» tirado.', 'undo' => undo_push('service_line', ['id' => (int)$c['id'], 'line' => (int)$r['id']]),
                  'html' => contact_services_html($pdo, contact_get($pdo, (int)$c['id']), true), 'history' => timeline_html(timeline($pdo, 'contact', (int)$c['id']))]);

    // Nova ação do contacto: uma tarefa ligada a ele (aparece também em Tarefas).
    case 'contact_task':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $kind = (string)($_POST['kind'] ?? 'task');
        if (!isset(LEAD_TASK_KINDS[$kind])) throw new RuntimeException('Escolha o tipo de ação.');
        $date = (string)($_POST['date'] ?? '');
        $time = (string)($_POST['time'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Indique a data.');
        if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) throw new RuntimeException('Indique uma hora válida.');
        $who = (int)($_POST['assignee'] ?? 0) ?: (int)current_user()['id'];
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$who]);
        $target = $st->fetch();
        if (!$target || !can_assign_to(me_row($pdo), $target)) throw new RuntimeException('Não pode atribuir tarefas a essa pessoa.');
        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 200));
        if ($title === '') $title = lead_task_default_title($kind, $c['name']);
        $dur = (int)($_POST['duration'] ?? 0);
        contact_task_create($pdo, (int)$c['id'], $kind, $title, trim(mb_substr((string)($_POST['notes'] ?? ''), 0, 4000)),
                            $date . ($time !== '' ? ' ' . $time : ''), $dur > 0 && $dur <= 600 ? $dur : null, $who,
                            (string)($_POST['repeat'] ?? ''), (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => 'Tarefa criada.'] + contact_parts($pdo, (int)$c['id']));

    case 'contact_task_done':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND contact_id = ?');
        $st->execute([(int)($_POST['task'] ?? 0), (int)$c['id']]);
        $t = $st->fetch();
        if (!$t || $t['status'] === 'done') throw new RuntimeException('Essa tarefa já não está aberta.');
        $rel = relation_snapshot($pdo, (int)$c['id']);
        $snap = ['id' => (int)$c['id'], 'task' => (int)$t['id'], 'status' => $t['status'], 'member_id' => $t['member_id'], 'position' => $t['position']];
        $snap['spawned'] = task_done($pdo, (int)$t['id']);   // histórico do contacto e, numa relação, o toque
        if ($rel && relation_snapshot($pdo, (int)$c['id']) != $rel) $snap['rel'] = $rel;
        out(200, ['ok' => true, 'msg' => '«' . $t['title'] . '» feita.', 'undo' => undo_push('contact_task', $snap),
                  'relation_followup' => $t['kind'] === 'relation'] + contact_parts($pdo, (int)$c['id']));

    // ── relações (§13.5) ──
    case 'relation_set':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $before = relation_snapshot($pdo, (int)$c['id']);
        $v = relation_input($pdo, $_POST);
        relation_set($pdo, (int)$c['id'], $v, (int)current_user()['id']);
        $token = $before ? undo_push('relation_row', $before) : undo_push('relation_drop', ['id' => (int)$c['id']]);
        out(200, ['ok' => true, 'msg' => $before ? 'Relação atualizada (nível ' . $v['level'] . ').' : 'Relação acompanhada: nível ' . $v['level'] . '.', 'undo' => $token]
                 + contact_parts($pdo, (int)$c['id']));

    case 'relation_outcome':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $id = (int)$c['id'];
        $r = relation_get($pdo, $id);
        if (!$r) throw new RuntimeException('Esta relação já não é acompanhada.');
        $res = (string)($_POST['result'] ?? '');
        $fmt = fn(string $d) => lisbon_fmt(lisbon_ts($d . ' 12:00'), 'd/m');
        if ($res === 'talked') {
            $next = (string)($_POST['next'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $next) || $next < today_lisbon()) throw new RuntimeException('Escolha a data do próximo toque (hoje ou depois).');
            $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 4000));
            $pdo->prepare('UPDATE relations SET next_at = ? WHERE contact_id = ?')->execute([$next, $id]);
            if (mb_strlen($note) > 200) interaction_add($pdo, null, $id, 'note', 'Falámos: ' . $note, today_lisbon());
            contact_log($pdo, $id, 'rel_touch', 'Falámos' . ($note !== '' ? ': ' . shorten($note, 200) : '') . ' · próximo toque ' . $fmt($next));
            out(200, ['ok' => true, 'msg' => 'Registado. Próximo toque a ' . $fmt($next) . '.'] + contact_parts($pdo, $id));
        }
        if ($res === 'missed') {
            $next = (new DateTimeImmutable(today_lisbon()))->modify('+2 days')->format('Y-m-d');
            // O follow-up não conta como toque: volta o último toque de antes.
            $pdo->prepare('UPDATE relations SET last_touch_at = last_touch_prev, touches = CASE WHEN touches > 0 THEN touches - 1 ELSE 0 END, next_at = ? WHERE contact_id = ?')
                ->execute([$next, $id]);
            contact_log($pdo, $id, 'rel_miss', 'Nova tentativa a ' . $fmt($next));
            out(200, ['ok' => true, 'msg' => 'Não atendeu: nova tentativa a ' . $fmt($next) . '.'] + contact_parts($pdo, $id));
        }
        if ($res === 'meeting') {
            $date = (string)($_POST['date'] ?? '');
            $time = (string)($_POST['time'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Indique o dia da reunião.');
            if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) $time = '';
            $owner = $r['owner_id'] !== null ? (int)$r['owner_id'] : (int)current_user()['id'];
            contact_task_create($pdo, $id, 'meeting', 'Reunião com ' . $c['name'], trim(mb_substr((string)($_POST['note'] ?? ''), 0, 4000)),
                                $date . ($time !== '' ? ' ' . $time : ''), 60, $owner, '', (int)current_user()['id']);
            contact_log($pdo, $id, 'rel_meet', 'Reunião a ' . $fmt($date) . ($time !== '' ? ' ' . $time : ''));
            out(200, ['ok' => true, 'msg' => 'Reunião marcada para ' . $fmt($date) . '.'] + contact_parts($pdo, $id));
        }
        if ($res === 'lead') {
            if (!can('leads', PERM_EDIT)) throw new RuntimeException('Sem acesso para criar leads. Peça a quem trabalha as Leads.');
            contact_log($pdo, $id, 'rel_lead', 'Abriu «+ Adicionar Lead» com «Trazido por» ' . $c['name']);
            out(200, ['ok' => true, 'url' => '/admin/leads.php?nova=1&trazido=' . $id]);
        }
        throw new RuntimeException('Escolha como correu.');

    case 'relation_pause':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $before = relation_snapshot($pdo, (int)$c['id']);
        if (!$before) throw new RuntimeException('Esta relação já não é acompanhada.');
        relation_pause($pdo, (int)$c['id'], (string)($_POST['until'] ?? ''), (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => 'Relação pausada.', 'undo' => undo_push('relation_row', $before)] + contact_parts($pdo, (int)$c['id']));

    case 'relation_resume':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $before = relation_snapshot($pdo, (int)$c['id']);
        if (!$before) throw new RuntimeException('Esta relação já não é acompanhada.');
        relation_resume($pdo, (int)$c['id'], (int)current_user()['id']);
        relation_tick($pdo);
        out(200, ['ok' => true, 'msg' => 'Relação retomada: o próximo toque é hoje.', 'undo' => undo_push('relation_row', $before)] + contact_parts($pdo, (int)$c['id']));

    case 'relation_stop':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $before = relation_snapshot($pdo, (int)$c['id']);
        if (!$before) throw new RuntimeException('Esta relação já não é acompanhada.');
        relation_stop($pdo, (int)$c['id'], (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => 'Deixou de ser acompanhada.', 'undo' => undo_push('relation_row', $before)] + contact_parts($pdo, (int)$c['id']));

    case 'relation_review':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $r = relation_get($pdo, (int)$c['id']);
        if (!$r) throw new RuntimeException('Esta relação já não é acompanhada.');
        $before = relation_snapshot($pdo, (int)$c['id']);
        if (($_POST['decision'] ?? '') === 'accept') {
            $p = max(1, min(5, (int)($_POST['potential'] ?? $r['potential'])));
            $lvl = relation_level($r['formality'], $p, $r['business']);
            $pdo->prepare('UPDATE relations SET potential = ?, level = ?, reviewed_at = ? WHERE contact_id = ?')->execute([$p, $lvl, today_lisbon(), $c['id']]);
            contact_log($pdo, (int)$c['id'], 'rel_level', 'Revisão: potencial ' . $r['potential'] . ' → ' . $p . ' · nível ' . $r['level'] . ' → ' . $lvl);
            $msg = 'Nível revisto: ' . $lvl . '.';
        } else {
            $pdo->prepare('UPDATE relations SET reviewed_at = ? WHERE contact_id = ?')->execute([today_lisbon(), $c['id']]);
            contact_log($pdo, (int)$c['id'], 'rel_level', 'Revisão: mantém o nível ' . $r['level']);
            $msg = 'Mantém o nível ' . $r['level'] . '.';
        }
        out(200, ['ok' => true, 'msg' => $msg, 'undo' => undo_push('relation_row', $before)] + contact_parts($pdo, (int)$c['id']));

    case 'contact_merge':
        if (!$post) break;
        require_perm('contactos', PERM_MANAGE);
        require_once __DIR__ . '/inc/contacts.php';
        $keep = (int)($_POST['id'] ?? 0);
        $other = (int)($_POST['other'] ?? 0);
        $o = contact_get($pdo, $other);
        contact_merge($pdo, $keep, $other, (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => '«' . ($o['name'] ?? '') . '» juntado. O histórico dos dois fica aqui.', 'url' => '/admin/contact.php?id=' . $keep]);

    // Avaliação de um trabalho de fornecedor/freelancer, quando o custo é pago (§13.3).
    case 'cost_rate':
        if (!$post) break;
        require_perm('financeiro', PERM_EDIT);
        require_once __DIR__ . '/inc/contacts.php';
        $cost = cost_needs_rating($pdo, (int)($_POST['cost'] ?? 0));
        if (!$cost) throw new RuntimeException('Este trabalho já foi avaliado ou não é de um fornecedor.');
        cost_rate($pdo, $cost, (int)($_POST['stars'] ?? 0), (int)current_user()['id']);
        [$avg, $n] = contact_rating($pdo, (int)$cost['contact_id']);
        out(200, ['ok' => true, 'msg' => $cost['contact_name'] . ' avaliado: média ' . number_format((float)$avg, 1, ',', '') . ' (' . $n . ').']);

    // ── Pipeline: mover sem recarregar ──────────────────────
    case 'deal_move':
        if (!$post) break;
        require_perm('pipeline', PERM_EDIT);
        $deal = record_get($pdo, (int)($_POST['id'] ?? 0));
        if (!$deal || $deal['phase'] !== 'pipeline' || !record_reach($pdo, $deal, PERM_EDIT)) {
            out(404, ['ok' => false, 'error' => 'Negócio não encontrado. Recarregue a página.']);
        }
        $to = stage_get($pdo, (int)($_POST['stage_id'] ?? 0));
        if (!$to || !in_array($to['kind'], ['xray', 'commercial', 'kickoff'], true)) throw new RuntimeException('Etapa inválida.');
        $snap = undo_deal_snapshot($deal);
        $msg = deal_move($pdo, $deal, (int)$to['id']);
        $now = record_get($pdo, (int)$deal['id']);
        $token = (int)$deal['stage_id'] !== (int)$to['id'] ? undo_push('deal_stage', $snap + ['to_stage' => (int)$to['id']]) : '';
        out(200, ['ok' => true, 'msg' => $msg, 'phase' => $now['phase'], 'undo' => $token, 'won' => $now['phase'] === 'production']);
    }
} catch (RuntimeException $e) {
    if (!$json && $a === 'interaction') {
        require_once __DIR__ . '/inc/layout.php';
        flash($e->getMessage(), 'err');
        $back = (string)($_POST['back'] ?? '');
        redirect(str_starts_with($back, '/admin/') ? $back : '/admin/');
    }
    out(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('ui_api ' . $a . ': ' . $e->getMessage());
    out(500, ['ok' => false, 'error' => 'Não foi possível concluir. Tente outra vez.']);
}

out(400, ['ok' => false, 'error' => 'Pedido inválido.']);
