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
 *   POST a=lead_task          id, kind, title, notes, date, time, duration, assignee, repeat — Nova ação
 *   POST a=lead_task_done     id, task — concluir no Foco (uma chamada abre «Como correu?»)
 *   POST a=lead_outcome       id, result (missed | followup), note
 *   POST a=lead_archive       id, reason, note — com «Anular»
 *   GET  a=lead_next&id=      a próxima lead urgente
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
        'visit' => 'Visita a ' . $who, 'document' => 'Documento para ' . $who, 'event' => 'Evento · ' . $who,
        default => 'Tarefa · ' . $who,
    };
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
                $st = $pdo->prepare("SELECT id, name, email, contact_person FROM contacts
                                      WHERE name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!'
                                         OR nif LIKE ? ESCAPE '!' OR contact_person LIKE ? ESCAPE '!'
                                      ORDER BY name LIMIT 6");
                $st->execute([$l, $l, $l, $l, $l]);
                foreach ($st->fetchAll() as $c) {
                    $add('Contactos', ['title' => $c['name'], 'sub' => $c['contact_person'] ?: $c['email'],
                                       'url' => '/admin/contactos.php?edit=' . $c['id'] . '#contacto', 'panel' => 'contact:' . $c['id'], 'icon' => icon('contactos')]);
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
            $st = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
            $st->execute([$id]);
            $c = $st->fetch();
            if (!$c) out(404, ['ok' => false, 'error' => 'Contacto não encontrado.']);
            out(200, ['ok' => true] + panel_contact($pdo, $c));
        }
        break;


    // ── Leads v2 (§12) ──────────────────────────────────────
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
        // Reunião, email ou visita feitos contam como contacto e, em Follow-Up, como follow-up.
        if (in_array($t['kind'], ['meeting', 'email', 'visit'], true) && $l['phase'] === 'lead') {
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
