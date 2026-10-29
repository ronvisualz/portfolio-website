<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/tasks_view.php';
require_once __DIR__ . '/inc/undo.php';
require_once __DIR__ . '/inc/team.php';
require_once __DIR__ . '/inc/gcal.php';
require_once __DIR__ . '/inc/task_invites.php';
$user = require_login();
require_page('tarefas');

// Tarefas (§18): Tarefas (lista por urgência, abre por defeito) · Quadro da equipa ·
// Agenda (24 h). O superadmin vê e gere tudo, mas não é operador: não é responsável,
// não tem coluna no quadro e não entra em distribuições (§18.2). Tarefas pessoais e
// bloqueios são privados: os outros — admins incluídos — veem só «Ocupado».

const TASK_TABS = ['tarefas' => 'Tarefas', 'quadro' => 'Quadro da equipa', 'agenda' => 'Agenda'];

// Os endereços antigos continuam a funcionar.
$tab = (string)($_GET['t'] ?? '');
if ($tab === '' && isset($_GET['view'])) $tab = ['mes' => 'agenda', 'tarefas' => 'tarefas'][$_GET['view']] ?? 'tarefas';
if (!isset(TASK_TABS[$tab])) $tab = 'tarefas';

$me = me_row($pdo);
$admin = role_is_admin((string)$me['role']);
$members = team_members($pdo);                       // operadores (sem o superadmin)
$visible = task_visible_members($pdo, $members);
if ($visible !== null) $members = array_intersect_key($members, array_flip($visible));
$myMember = member_for_user($pdo, (int)$me['id']);
$operator = $myMember !== null && isset($members[$myMember]);
$canEdit = can('tarefas', PERM_EDIT);
$assignable = array_filter($members, fn($m, $mid) => task_can_assign($pdo, (int)$mid, $members, $visible), ARRAY_FILTER_USE_BOTH);

/** Uma tarefa que posso ver e mexer (as privadas dos outros não existem para mim). */
$reach = function (?array $t) use ($pdo, $visible, $myMember): ?array {
    if (!$t) return null;
    if (task_hidden_for($t, $myMember)) return null;
    if (!task_can_see($pdo, $t, $visible, $myMember)) return null;   // as por atribuir: só as da minha equipa
    return $t;
};

// ── ações ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $back = (string)($_POST['back'] ?? '/admin/calendar.php');
    // O formulário também se abre no negócio (Pipeline, ficha): volta para lá.
    if (!preg_match('~^/admin/(calendar|pipeline|negocio)\.php(\?[^#\s]*)?(#[A-Za-z0-9_-]*)?$~', $back)) $back = '/admin/calendar.php';
    $back = preg_replace('~([?&])(edit|nova)=[^&]*&?~', '$1', $back);
    $back = rtrim($back, '?&');
    try {
        if (in_array($do, ['save_task', 'delete_task'], true)) {
            if (!$canEdit) throw new RuntimeException('Sem permissão para editar tarefas.');
            $id = (int)($_POST['id'] ?? 0);
            $old = $id ? $reach(task_by_id($pdo, $id)) : null;
            if ($id && !$old) throw new RuntimeException('Essa tarefa já não existe.');

            if ($do === 'delete_task') {
                // Uma tarefa obrigatória (gate, §19.1) só sai por um admin: senão abria a passagem da etapa.
                if (!empty($old['is_gate']) && $old['status'] !== 'done' && !is_admin()) throw new RuntimeException('Esta tarefa é obrigatória para o negócio avançar: só um admin a pode eliminar.');
                $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
                if (db_has_table($pdo, 'task_log')) $pdo->prepare('DELETE FROM task_log WHERE task_id = ?')->execute([$id]);
                // o Google acompanha (§31.4): o Meet é cancelado (com aviso aos convidados) e sai dos calendários;
                // os convites por email (Discord, presencial) recebem o cancelamento (§31.6)
                try { gcal_task_sync($pdo, $id); } catch (Throwable $e) { error_log('[topper] gcal delete: ' . $e->getMessage()); }
                $ci = tinv_sync($pdo, $id, (int)$me['id']);
                flash('Tarefa eliminada.' . ($ci['cancelled'] ? ' ' . $ci['cancelled'] . ($ci['cancelled'] === 1 ? ' convidado avisado' : ' convidados avisados') . ' do cancelamento.' : ''));
                redirect($back);
            }

            $kind = isset(TASK_TYPES[$_POST['kind'] ?? '']) ? (string)$_POST['kind'] : 'task';
            $priv = in_array($kind, ['personal', 'block'], true);
            $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 200));
            if ($title === '') $title = $kind === 'block' ? 'Bloqueio' : '';
            if ($title === '') throw new RuntimeException('A tarefa precisa de um título.');
            $due = trim((string)($_POST['due_date'] ?? ''));
            if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) throw new RuntimeException('Indique uma data válida.');
            $time = trim((string)($_POST['due_time'] ?? ''));
            if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) throw new RuntimeException('Indique uma hora válida.');
            if ($time !== '' && $due === '') throw new RuntimeException('Uma hora precisa de um dia: indique a data.');
            if ($kind === 'block' && ($due === '' || $time === '')) throw new RuntimeException('Um bloqueio precisa de dia e hora.');
            $dur = (int)($_POST['duration_min'] ?? 0);
            if (!isset(TASK_DURATIONS[$dur])) $dur = 0;
            // Responsável: um operador (nunca o superadmin). Pessoal e bloqueio são de quem os cria.
            $mid = (int)($_POST['member_id'] ?? 0) ?: null;
            if ($priv) $mid = $old ? ($old['member_id'] !== null ? (int)$old['member_id'] : $myMember) : $myMember;
            elseif ($mid !== null && !isset($members[$mid])) throw new RuntimeException('Escolha um responsável da equipa.');
            $oldMid = $old && $old['member_id'] !== null ? (int)$old['member_id'] : null;
            if (!$priv && $mid !== $oldMid && !task_can_assign($pdo, $mid, $members, $visible)) throw new RuntimeException('Não pode atribuir tarefas a essa pessoa.');
            $platform = $kind === 'meeting' && in_array($_POST['platform'] ?? '', ['meet', 'discord'], true) ? (string)$_POST['platform'] : '';
            $location = ($kind === 'meeting' && $platform === '') || in_array($kind, ['lunch', 'dinner', 'event'], true) ? trim(mb_substr((string)($_POST['location'] ?? ''), 0, 200)) : '';
            $parts = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['participants'] ?? [])))));
            $ownerUid = $mid !== null && isset($members[$mid]) ? (int)$members[$mid]['user_id'] : (int)$me['id'];
            // Participantes só em reuniões, gravações e visitas (recebem o convite no chat).
            $parts = $priv || !in_array($kind, TASK_GUEST_KINDS, true) ? [] : array_values(array_filter($parts, fn($u) => $u !== $ownerUid));
            $row = [
                'title' => $title, 'description' => trim((string)($_POST['description'] ?? '')), 'kind' => $kind, 'member_id' => $mid,
                'deal_id' => task_deal_in($_POST['deal_id'] ?? 0, $pdo), 'contact_id' => (int)($_POST['contact_id'] ?? 0) ?: null,
                'due_date' => $due !== '' ? $due : null, 'due_time' => $time !== '' ? $time . ':00' : null, 'duration_min' => $time !== '' && $dur ? $dur : null,
                'priority' => isset(TASK_PRIO[$_POST['priority'] ?? '']) ? (string)$_POST['priority'] : 'media',
                'repeat_rule' => isset(TASK_REPEAT[$_POST['repeat_rule'] ?? '']) ? (string)$_POST['repeat_rule'] : '',
                'busy' => $priv ? 1 : (($_POST['busy'] ?? '1') === '0' ? 0 : 1), 'private' => $priv ? 1 : 0,
                'platform' => $platform, 'location' => $location,
                'checklist' => task_checklist_parse((string)($_POST['checklist'] ?? '')), 'attachments' => task_links_parse((string)($_POST['attachments'] ?? '')),
                'participants' => implode(',', $parts),
            ];
            // o link da chamada colado à mão (sem Google ligado) ou o do Meet criado pela plataforma
            if (db_has_column($pdo, 'tasks', 'call_link')) {
                $cl = trim(mb_substr((string)($_POST['call_link'] ?? ''), 0, 500));
                if ($cl !== '' && !preg_match('~^https://\S+$~', $cl)) throw new RuntimeException('O link da chamada começa por https://.');
                $row['call_link'] = $cl;
            }
            if ($row['contact_id'] && !(int)$pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . (int)$row['contact_id'])->fetchColumn()) $row['contact_id'] = null;
            // Reunião (§31.6): os convidados de «Convidar», quem envia o Meet e o link do Discord
            $meeting = $kind === 'meeting' && tinv_ready($pdo);
            if ($meeting && isset($_POST['invitees_sent'])) {
                [$inv, $bad] = tinv_parse((string)($_POST['invitees'] ?? ''));
                if ($bad) throw new RuntimeException('Email inválido em «Convidar»: ' . implode(', ', array_slice($bad, 0, 3)) . '.');
                if (count($inv) > TINV_MAX) throw new RuntimeException('No máximo ' . TINV_MAX . ' convidados externos.');
                $row['invitees'] = json_encode($inv);
                if ($platform === 'meet' && !($old && gcal_link($pdo, 'task', $id, 'meet'))) $row['organizer'] = ($_POST['organizer'] ?? '') === 'me' ? 'me' : 'topper';
            }
            if ($meeting && $platform === 'discord' && ($dl = tinv_discord()) !== '' && !preg_match('~^https://(discord\.gg|discord\.com)/~', (string)($row['call_link'] ?? ''))) $row['call_link'] = $dl;
            if ($meeting && $platform === '' && isset($row['call_link'])) $row['call_link'] = '';
            $now = task_now();
            if ($old) {
                $pdo->prepare('UPDATE tasks SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($row))) . ', updated_at = ? WHERE id = ?')
                    ->execute([...array_values($row), $now, $id]);
                if ($oldMid !== $mid) {
                    $pdo->prepare('UPDATE tasks SET help_by = NULL, position = ? WHERE id = ?')->execute([task_next_position($pdo, $mid), $id]);
                    task_log($pdo, $id, 'assign', 'Responsável: ' . ($mid ? $members[$mid]['name'] : 'por atribuir'));
                }
                task_log($pdo, $id, 'edit', 'Editada');
                if (($old['doc_kind'] ?? '') === '' && ($dk = task_doc_kind_for($title, $row['deal_id'])) !== '') $pdo->prepare('UPDATE tasks SET doc_kind = ? WHERE id = ?')->execute([$dk, $id]);
                $newParts = array_diff($parts, array_filter(array_map('intval', explode(',', (string)$old['participants']))));
                $msg = 'Tarefa guardada.';
                $undo = '';
            } else {
                $row['doc_kind'] = task_doc_kind_for($title, $row['deal_id']);
                $cols = array_keys($row);
                $pdo->prepare('INSERT INTO tasks (' . implode(', ', $cols) . ', job_name, status, position, created_at, updated_at, created_by) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ", '', 'todo', ?, ?, ?, ?)")
                    ->execute([...array_values($row), task_next_position($pdo, $mid), $now, $now, (int)$me['id']]);
                $id = (int)$pdo->lastInsertId();
                task_log($pdo, $id, 'create', TASK_TYPES[$kind][0] . ($mid ? ' · ' . $members[$mid]['name'] : ($priv ? '' : ' · por atribuir')));
                $newParts = $parts;
                $msg = $kind === 'block' ? 'Bloqueio criado: fica «Ocupado» para os outros.' : ($priv ? 'Tarefa pessoal criada: os outros veem só «Ocupado».' : 'Tarefa criada.');
                $undo = undo_push('task_new', ['id' => $id]);
            }
            // Participantes (§18.7): convite pelo chat (§17.7). Com dia e hora é um pedido de
            // reunião (Aceitar/Recusar/Propor); sem hora, uma mensagem com a tarefa.
            if ($newParts) {
                $sent = 0; $warn = '';
                $group = bin2hex(random_bytes(6));
                foreach ($newParts as $uid) {
                    try {
                        if ($row['due_date'] && $row['due_time']) {
                            team_meeting_request($pdo, (int)$me['id'], (int)$uid, $due . ' ' . $time, $dur ?: 60, $title, $row['deal_id'], $platform ?: 'local', '', $group, null, $location);
                        } else {
                            team_chat_send($pdo, (int)$me['id'], (int)$uid, 'Juntei-te à tarefa «' . $title . '»: /admin/calendar.php?edit=' . $id);
                        }
                        $sent++;
                    } catch (RuntimeException $e) { $warn = $e->getMessage(); }
                }
                // Quem pediu já tem a tarefa: ao aceitarem, não se cria outra (§17.7).
                $pdo->prepare('UPDATE meetings SET requester_task_id = ? WHERE group_key = ?')->execute([$id, $group]);
                if ($sent) task_log($pdo, $id, 'invite', $sent . ' participante(s) convidado(s) pelo chat');
                $msg .= $sent ? ' Convite enviado no chat a ' . $sent . ' pessoa(s).' : '';
                if ($warn !== '') $msg .= ' Convite não enviado: ' . $warn;
            }
            // o Google acompanha (§31.4): hora, título e convidados do Meet; o espelho no calendário de cada pessoa ligada.
            // Numa reunião no Google Meet (§31.6) o Meet é criado ao gravar (com dia e hora), sem outro botão.
            $hadMeet = gcal_link($pdo, 'task', $id, 'meet') !== null;
            $wantMeet = $row['due_date'] && $row['due_time'] && ($meeting ? $platform === 'meet' && (gcal_can_meet($pdo, (int)$me['id']) || $hadMeet) : !empty($_POST['gmeet']));
            if ($meeting && $platform === 'meet' && !$wantMeet && !$hadMeet && trim((string)($row['call_link'] ?? '')) === '') {
                $msg .= $row['due_date'] && $row['due_time'] ? ' Meet por criar: a conta da topper. não está ligada (Definições › Integrações). Cole o link da chamada à mão.' : ' O Meet é criado quando a reunião tiver dia e hora.';
            }
            if (!$wantMeet) { try { gcal_task_sync($pdo, $id); } catch (Throwable $e) { error_log('[topper] gcal save: ' . $e->getMessage()); } }
            if ($wantMeet) {   // cria o Meet e põe o resto em linha (sem cópias para quem já é convidado)
                try {
                    if ($meeting) {
                        $l = gcal_meet_for_task($pdo, $id, null, (int)$me['id'], (string)($row['organizer'] ?? ''));
                        $msg .= $hadMeet ? ' Google Meet atualizado: os convidados são avisados pelo Google.' : ' Google Meet criado (' . ($l['account'] === 'topper' ? 'conta da topper.' : 'o seu Google Calendar') . '): os convidados recebem o convite do Google.';
                    } else {
                        $guests = preg_split('/[\s,;]+/', (string)($_POST['gmeet_guests'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                        $l = gcal_meet_for_task($pdo, $id, array_filter($guests, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)), (int)$me['id']);
                        $msg .= ' Google Meet criado: os convidados recebem o convite do Google.';
                    }
                } catch (RuntimeException $e) {
                    $msg .= ' Meet não criado: ' . $e->getMessage();
                    try { gcal_task_sync($pdo, $id); } catch (Throwable $e2) { error_log('[topper] gcal save: ' . $e2->getMessage()); }
                }
            }
            // Discord e presencial (§31.6): o convite por email com o .ics; mudanças e cancelamentos a quem sai
            if (tinv_ready($pdo) && ($meeting || ($old && $old['kind'] === 'meeting'))) {
                $ri = tinv_sync($pdo, $id, (int)$me['id']);
                if (($sum = tinv_summary($ri)) !== '') { $msg .= $sum; task_log($pdo, $id, 'invite', trim($sum)); }
                if ($ri['sent'] && $row['deal_id'] && function_exists('deal_log')) {
                    deal_log($pdo, (int)$row['deal_id'], 'meeting', ['note' => 'Reunião (' . ($platform === 'discord' ? 'Discord' : 'presencial') . '): ' . $title . ' · ' . date('d/m H:i', strtotime($due . ' ' . $time)) . ($platform === 'discord' && ($row['call_link'] ?? '') !== '' ? ' · ' . $row['call_link'] : ($location !== '' ? ' · ' . $location : ''))]);
                }
            }
            flash($msg, 'ok', $undo);
            redirect($back);
        }

        if ($do === 'save_view' || $do === 'del_view') {
            if ($do === 'save_view') {
                $name = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 60));
                $q = (string)($_POST['query'] ?? '');
                if ($name === '' || strlen($q) > 500 || preg_match('/[<>"\s]/', $q)) throw new RuntimeException('Dê um nome à vista.');
                $pdo->prepare('INSERT INTO task_views (user_id, name, query) VALUES (?,?,?)')->execute([(int)$me['id'], $name, $q]);
                flash('Vista «' . $name . '» guardada.');
            } else {
                $pdo->prepare('DELETE FROM task_views WHERE id = ? AND user_id = ?')->execute([(int)($_POST['id'] ?? 0), (int)$me['id']]);
                flash('Vista apagada.');
            }
            redirect($back);
        }

        if ($do === 'delete_event') {
            if (!$canEdit) throw new RuntimeException('Sem permissão.');
            $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            flash('Evento eliminado.');
            redirect($back);
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
        redirect($back);
    }
    redirect($back);
}

// ── dados ───────────────────────────────────────────────────
require_once __DIR__ . '/inc/contacts.php';
relation_tick($pdo);   // os follow-ups de relação que chegaram à data (§13.5)
$f = tasks_filters($_GET, $operator, user_start_view($pdo, (int)$user['id'], 'tarefas'));
// Definições › Tarefas: máximo de cartões por coluna e agrupar (ou não) as tarefas automáticas.
$boardMax = max(3, min(30, (int)setting($pdo, 'task_board_max', TASK_BOARD_MAX)));
$groupOn = (string)setting($pdo, 'task_group_auto', '1') !== '0';
if (!$groupOn) $f['solto'] = 1;
$here = (string)($_SERVER['REQUEST_URI'] ?? '/admin/calendar.php');
if (!preg_match('~^/admin/calendar\.php(\?[^#\s]*)?$~', $here)) $here = '/admin/calendar.php';
$editUrl = function (int $id) use ($here): string {
    $u = preg_replace('~([?&])(edit|nova)=[^&]*&?~', '$1', $here);
    $u = rtrim($u, '?&');
    return $u . (str_contains($u, '?') ? '&' : '?') . 'edit=' . $id;
};
$open = tasks_open($pdo, $visible, $myMember);
$views = $pdo->prepare('SELECT * FROM task_views WHERE user_id = ? ORDER BY name');
$views->execute([(int)$me['id']]);
$views = $views->fetchAll();
$lateMine = count(array_filter(tasks_filter($open, ['urg' => 'late'] + $f, $myMember, $tab === 'tarefas'), fn($t) => $t['kind'] !== 'block'));
$editing = isset($_GET['edit']) ? $reach(task_by_id($pdo, (int)$_GET['edit'])) : null;
$participants = [];
foreach ($members as $m) if ($m['user_id'] !== null && (int)$m['user_id'] !== (int)$me['id']) $participants[(int)$m['user_id']] = $m['name'];

render_header('Tarefas', 'calendar', '', [TASK_TABS[$tab]]);
render_flashes();
?>
<div class="page-head">
  <div><div class="h1-row"><h1>Tarefas</h1><?= cfg_link('tarefas') ?></div>
    <p class="sub"><?= $tab === 'tarefas' ? 'O que há para fazer, por urgência.' : ($tab === 'quadro' ? 'Quem tem o quê: pegue em tarefas dos colegas ou peça ajuda.' : 'O dia, a semana e o mês em 24 horas.') ?></p></div>
  <?php if ($canEdit): ?>
    <button type="button" class="btn" data-new="#mdl-task" aria-keyshortcuts="N"><?= icon('plus') ?> Nova tarefa <span class="kbd">N</span></button>
  <?php endif; ?>
</div>

<nav class="tabs" aria-label="Separadores">
  <?php foreach (TASK_TABS as $k => $label): ?>
    <a href="/admin/calendar.php<?= $k !== 'tarefas' ? '?t=' . $k : '' ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php
// ════════════════════════════════════════════════════════════
if ($tab === 'tarefas'):
    $list = array_values(array_filter(tasks_filter($open, $f, $myMember), fn($t) => $t['kind'] !== 'block'));
    task_urgency_sort($list);
    // «Por atribuir»: as abertas sem responsável, com os outros filtros (menos o «Quem»)
    $noneN = count(array_filter(tasks_filter($open, ['quem' => 'none'] + $f, $myMember), fn($t) => $t['kind'] !== 'block'));
    echo tasks_filters_html($f, $members, $myMember, 'tarefas', $views, task_deal_options($pdo), ['late' => $lateMine, 'none' => $noneN]);
    // Um só quadro: as por fazer pela ordem de urgência (as em atraso primeiro, a vermelho) e, em «Todas», as
    // concluídas a seguir. As chamadas de leads e os follow-ups de relação continuam juntos numa linha.
    $showWho = $f['quem'] !== 'me';
    $open1 = $f['est'] !== 'done' ? ($f['solto'] ? array_map(fn($t) => ['task' => $t], $list) : task_aggregate($list)) : [];
    $done = $f['est'] !== 'todo' ? tasks_filter(tasks_open($pdo, $visible, $myMember, true), $f, $myMember) : [];
    $total = count($list);
    $lateN = count(array_filter($list, fn($t) => task_bucket($t) === 'late'));
    $rows = '';
    foreach ($open1 as $it) $rows .= isset($it['task']) ? task_row_html($it['task'], $members, $editUrl((int)$it['task']['id'])) : task_group_html($it, $members, $showWho, $editUrl);
    foreach ($done as $t) $rows .= task_row_html($t, $members, $editUrl((int)$t['id']));
    $head = $f['est'] === 'done' ? 'Concluídas' : ($f['est'] === 'all' ? 'Todas' : 'Por fazer');
?>
<div class="tlist" data-tlist>
  <section class="tgrp one" data-grp="all">
    <h2 class="tgh"><?= e($head) ?> <span class="n"><?= $f['est'] === 'done' ? count($done) : $total + count($done) ?></span>
      <?php if ($lateN && $f['est'] !== 'done'): ?><span class="tgl late"><?= icon('alert') ?> <?= $lateN ?> em atraso</span><?php endif; ?>
      <?php if ($f['est'] !== 'todo'): ?><small class="muted">concluídas: as mais recentes</small><?php endif; ?></h2>
    <div class="tgb"><?= $rows ?>
    <?php if ($rows === ''): ?><p class="empty-s muted"><?= $f['est'] === 'done' ? 'Ainda não há concluídas com estes filtros.' : 'Nada por fazer com estes filtros.' ?> <?= $f['quem'] === 'me' ? '<a href="' . e(tasks_url('tarefas', $f, ['quem' => 'all'])) . '">Ver as da equipa</a>' : '' ?></p><?php endif; ?></div>
  </section>
  <?php if ($f['solto'] && $groupOn): ?>
    <p class="muted small">As chamadas de leads e os follow-ups de relação estão uma a uma. <a href="<?= e(tasks_url('tarefas', $f, ['solto' => 0])) ?>">Voltar a agrupar</a></p>
  <?php endif; ?>
</div>
<?php
    if ($canEdit): ?>
<div class="snz" data-snz hidden role="dialog" aria-label="Adiar a tarefa">
  <b class="snz-h"><?= icon('clock') ?> Adiar</b>
  <button type="button" data-when="1d"><span>+1 dia</span><small data-to="1"></small></button>
  <button type="button" data-when="3d"><span>+3 dias</span><small data-to="3"></small></button>
  <button type="button" data-when="1w"><span>+1 semana</span><small data-to="7"></small></button>
  <button type="button" data-when="custom" aria-expanded="false"><span>Personalizar…</span></button>
  <form class="snz-c" data-snz-custom hidden>
    <label><span>Data</span><input type="date" name="date" required></label>
    <label><span>Hora</span><input type="time" name="time" step="900"></label>
    <button class="btn sm" type="submit">Adiar</button>
  </form>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
elseif ($tab === 'quadro'):
    echo tasks_filters_html($f, $members, $myMember, 'quadro', $views, task_deal_options($pdo), ['late' => $lateMine]);
    $all = array_values(array_filter(tasks_filter($open, $f, $myMember, false), fn($t) => $t['kind'] !== 'block' && !$t['private']));
    $cols = [0 => []];
    foreach ($members as $mid => $m) $cols[$mid] = [];
    foreach ($all as $t) {
        $k = $t['member_id'] !== null && isset($members[(int)$t['member_id']]) ? (int)$t['member_id'] : ($t['member_id'] === null ? 0 : null);
        if ($k !== null) $cols[$k][] = $t;
    }
    // Carga da semana (§17.10) de cada operador.
    $uids = array_filter(array_map(fn($m) => $m['user_id'] !== null ? (int)$m['user_id'] : null, $members));
    $people = $uids ? $pdo->query('SELECT * FROM users WHERE id IN (' . implode(',', $uids) . ')')->fetchAll() : [];
    $load = team_load($pdo, $people, (new DateTimeImmutable('now', team_tz()))->modify('monday this week')->format('Y-m-d'));
    $today = today_lisbon();
?>
<div class="tboard" data-board>
  <?php foreach ($cols as $mid => $list):
      task_urgency_sort($list);
      $items = $f['solto'] ? array_map(fn($t) => ['task' => $t], $list) : task_aggregate($list);
      $late = count(array_filter($list, fn($t) => task_bucket($t, $today) === 'late'));
      $tod = count(array_filter($list, fn($t) => task_bucket($t, $today) === 'today'));
      $m = $mid ? $members[$mid] : null;
      $l = $m && $m['user_id'] !== null ? ($load[(int)$m['user_id']] ?? null) : null;
      $mine = $mid && $mid === $myMember;
  ?>
    <section class="bcol<?= $mine ? ' mine' : '' ?>" data-col="<?= (int)$mid ?>" aria-label="<?= e($m ? $m['name'] : 'Por atribuir') ?>">
      <header class="bch">
        <b><?= $m ? task_who_html($members, $mid) . ' ' . e(first_name($m['name'])) : icon('hand') . ' Por atribuir' ?><?= $mine ? ' <small class="muted">(eu)</small>' : '' ?></b>
        <small class="mono" data-tip="Atrasadas · hoje · total"><span class="<?= $late ? 'late' : '' ?>"><?= $late ?></span> · <?= $tod ?> · <?= count($list) ?></small>
      </header>
      <?php if ($l): ?><div class="bload l-<?= $l['level'] ?>" data-tip="Carga da semana: <?= (int)round($l['pct']) ?>% (horas Ocupado vs disponíveis)"><i style="width:<?= min(100, (int)round($l['pct'])) ?>%"></i></div><?php endif; ?>
      <div class="bcards">
      <?php foreach (array_slice($items, 0, $boardMax) as $it):
          if (isset($it['group'])) {
              [$gl, $gt, $go, $gu] = TASK_AUTO_GROUPS[$it['group']]; ?>
          <div class="bcard agg"><div class="br"><?= task_type_badge(['kind' => $gt], 'sm') . task_origin_badge(['kind' => $go === 'relation' ? 'relation' : 'call', 'deal_id' => $go === 'lead' ? 1 : null, 'deal_phase' => 'lead']) ?></div>
            <b><?= e($gl) ?> · <?= count($it['tasks']) ?></b><span class="<?= $it['late'] ? 'late' : 'muted' ?>"><?= $it['late'] ? $it['late'] . ' em atraso' : 'agrupadas' ?></span>
            <?php $gid = 'bg-' . e($it['group']) . '-' . (int)$mid; ?>
            <a class="tgo" href="<?= e($gu) ?>" data-gopen="<?= $gid ?>" aria-expanded="false" aria-controls="<?= $gid ?>"><span class="o">Abrir fila</span><span class="c">Fechar fila</span> <?= icon('chev-down') ?></a>
            <ul class="bgitems" id="<?= $gid ?>" hidden>
              <?php foreach ($it['tasks'] as $gt): [$glt] = task_link($gt); ?>
              <li data-task="<?= (int)$gt['id'] ?>"><?php if ($canEdit): ?><button type="button" class="tchk sm" data-done="<?= (int)$gt['id'] ?>"<?= task_call_attr($gt) ?> aria-label="Concluir" data-tip><?= icon('check') ?></button><?php endif; ?>
                <a href="<?= e($editUrl((int)$gt['id'])) ?>"><?= e(shorten($gt['title'], 40)) ?></a><span class="tdue<?= task_bucket($gt, $today) === 'late' ? ' late' : '' ?>"><?= e(task_when($gt)) ?></span></li>
              <?php endforeach; ?>
            </ul></div>
      <?php } else { $t = $it['task']; [$lt] = task_link($t); $b = task_bucket($t, $today); ?>
          <div class="bcard" data-task="<?= (int)$t['id'] ?>"<?= $canEdit ? ' draggable="true"' : '' ?>>
            <div class="br"><?= task_type_badge($t, 'sm') . task_origin_badge($t) ?><?= !empty($t['help_by']) ? '<span class="help" data-tip="Pedido de ajuda de ' . e((string)$t['help_name']) . '">🙋</span>' : '' ?>
              <span class="spacer"></span>
              <?php if ($canEdit && $operator && !$mine): ?><button type="button" class="pick" data-take="<?= (int)$t['id'] ?>" data-tip="Passa para mim">Pegar</button><?php endif; ?>
              <?php if ($canEdit && $mine): ?><button type="button" class="pick" data-help="<?= (int)$t['id'] ?>" data-tip="Envia para «Por atribuir» com 🙋 e avisa a equipa">Pedir ajuda</button><?php endif; ?>
            </div>
            <a class="bt" href="<?= e($editUrl((int)$t['id'])) ?>"><?= e($t['title']) ?></a>
            <?php if ($lt !== ''): ?><span class="muted small"><?= e(shorten($lt, 34)) ?></span><?php endif; ?>
            <?php if (!empty($t['help_by']) && !$mid): ?><span class="muted small">pedido de ajuda · <?= e(first_name((string)$t['help_name'])) ?></span><?php endif; ?>
            <div class="br"><span class="tdue<?= $b === 'late' ? ' late' : '' ?>"><?= e(task_when($t)) ?></span><span class="spacer"></span>
              <?php if ($canEdit): ?><button type="button" class="tchk sm" data-done="<?= (int)$t['id'] ?>"<?= task_call_attr($t) ?> aria-label="Concluir" data-tip><?= icon('check') ?></button><?php endif; ?></div>
          </div>
      <?php } endforeach; ?>
      <?php if (count($items) > $boardMax):
          $rest = 0; foreach (array_slice($items, $boardMax) as $it) $rest += isset($it['tasks']) ? count($it['tasks']) : 1; ?>
        <a class="bmore" href="<?= e(tasks_url('tarefas', $f, ['quem' => $mid ? (string)$mid : 'none'])) ?>">+ <?= $rest ?> tarefa<?= $rest === 1 ? '' : 's' ?></a>
      <?php endif; ?>
      <?php if (!$items): ?><p class="bempty muted small"><?= $mid ? 'Nada por fazer.' : 'Nada por atribuir.' ?></p><?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<p class="muted small">No máximo <?= $boardMax ?> cartões por coluna, os mais urgentes. Arraste um cartão para a sua coluna (ou use «Pegar»); «Pedir ajuda» manda para «Por atribuir» e avisa a equipa. As concluídas estão no filtro Concluídas da lista.</p>

<?php
// ════════════════════════════════════════════════════════════
else: // agenda
    $v = in_array($_GET['v'] ?? '', ['dia', 'semana', 'mes'], true) ? (string)$_GET['v'] : 'semana';
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['d'] ?? '')) ? new DateTimeImmutable($_GET['d'], team_tz()) : new DateTimeImmutable('now', team_tz());
    // De quem é a agenda: a minha por defeito; posso ver a dos colegas.
    $pp = (int)($_GET['p'] ?? 0);
    $who = $pp && (isset($members[$pp]) || $pp === $myMember) ? $pp : ($myMember ?: (array_key_first($members) ?? 0));
    $whoUser = null;
    if ($who) { $st = $pdo->prepare('SELECT * FROM users WHERE team_member_id = ?'); $st->execute([$who]); $whoUser = $st->fetch() ?: null; }
    $own = $who === $myMember;
    if ($v === 'dia') { $from = $d; $days = 1; }
    elseif ($v === 'semana') { $from = $d->modify('monday this week'); $days = 7; }
    else { $from = $d->modify('first day of this month')->modify('monday this week'); $days = (int)ceil(((int)$d->modify('first day of this month')->format('N') - 1 + (int)$d->format('t')) / 7) * 7; }
    $to = $from->modify('+' . $days . ' days');
    $step = $v === 'dia' ? '+1 day' : ($v === 'semana' ? '+1 week' : '+1 month');
    $nav = fn(DateTimeImmutable $x) => '/admin/calendar.php?' . http_build_query(['t' => 'agenda', 'v' => $v, 'd' => $x->format('Y-m-d'), 'p' => $who ?: null]);
    $prevD = $d->modify(str_replace('+', '-', $step)); $nextD = $d->modify($step);
    // O que aparece: tarefas (com hora → bloco; sem hora → prazo), ausências, eventos.
    $items = []; $allday = [];
    if ($who) {
        $st = $pdo->prepare("SELECT t.*, d.code AS deal_code, d.name AS deal_name, d.phase AS deal_phase, d.company AS deal_company, d.person AS deal_person, NULL AS contact_name
                               FROM tasks t LEFT JOIN deals d ON d.id = t.deal_id WHERE t.member_id = ? AND t.due_date >= ? AND t.due_date < ? AND (t.status <> 'done' OR t.due_time IS NOT NULL)");
        $st->execute([$who, $from->format('Y-m-d'), $to->format('Y-m-d')]);
        foreach ($st->fetchAll() as $t) {
            // Privada (pessoal, bloqueio) de outra pessoa: só «Ocupado», para todos — admins incluídos (§18.8).
            // Tarefas de um colega: os não-admins veem «Ocupado» (§17.7); os admins veem o título.
            $hidden = task_hidden_for($t, $myMember) || (!$own && !$admin);
            $label = $hidden ? 'Ocupado' : $t['title'];
            $url = $hidden ? '' : $editUrl((int)$t['id']);
            if ($t['due_time']) {
                $a = (int)substr((string)$t['due_time'], 0, 2) * 60 + (int)substr((string)$t['due_time'], 3, 2);
                $items[$t['due_date']][] = ['a' => $a, 'b' => min(1440, $a + (int)($t['duration_min'] ?: 60)), 't' => $t, 'label' => $label, 'url' => $url, 'hidden' => $hidden];
            } else {
                $allday[$t['due_date']][] = ['label' => ($hidden ? '' : 'Prazo: ') . $label, 'url' => $url, 't' => $t, 'hidden' => $hidden, 'cls' => 'due'];
            }
        }
        if ($whoUser) {
            foreach (team_absences_between($pdo, $from->format('Y-m-d'), $to->modify('-1 day')->format('Y-m-d')) as $ab) {
                if ((int)$ab['user_id'] !== (int)$whoUser['id']) continue;
                for ($x = new DateTimeImmutable(max($ab['start_date'], $from->format('Y-m-d'))); $x->format('Y-m-d') <= min($ab['end_date'], $to->modify('-1 day')->format('Y-m-d')); $x = $x->modify('+1 day')) {
                    $allday[$x->format('Y-m-d')][] = ['label' => $own || $admin ? (ABSENCE_KINDS[$ab['kind']] ?? 'Ausente') : 'Ausente', 'url' => '', 'cls' => 'abs'];
                }
            }
        }
    }
    // O «ocupado» do Google Calendar de quem o mostra (Perfil › Ligações), sem detalhes (§31.3).
    if ($whoUser && gcal_ready($pdo) && ($gcw = gcal_conn($pdo, (int)$whoUser['id'])) && (int)$gcw['show_busy']) {
        foreach (gcal_busy_blocks($pdo, (int)$whoUser['id'], $from->getTimestamp(), $to->getTimestamp()) as [$ga, $gb]) {
            for ($x = (new DateTimeImmutable('@' . $ga))->setTimezone(team_tz())->setTime(0, 0); $x->getTimestamp() < $gb; $x = $x->modify('+1 day')) {
                $s0 = max($ga, $x->getTimestamp()); $e0 = min($gb, $x->modify('+1 day')->getTimestamp());
                if ($e0 <= $s0) continue;
                $a = intdiv($s0 - $x->getTimestamp(), 60);
                $items[$x->format('Y-m-d')][] = ['a' => $a, 'b' => min(1440, $a + max(15, intdiv($e0 - $s0, 60))), 't' => ['kind' => 'event'], 'label' => 'Ocupado (Google)', 'url' => '', 'hidden' => true, 'gbusy' => true];
            }
        }
    }
    // Os eventos antigos do calendário (da empresa) continuam visíveis.
    if (db_has_table($pdo, 'events')) {
        $st = $pdo->prepare('SELECT * FROM events WHERE event_date >= ? AND event_date < ?');
        $st->execute([$from->format('Y-m-d'), $to->format('Y-m-d')]);
        foreach ($st->fetchAll() as $ev) {
            if ($ev['start_time']) {
                $a = (int)substr((string)$ev['start_time'], 0, 2) * 60 + (int)substr((string)$ev['start_time'], 3, 2);
                $b = $ev['end_time'] ? (int)substr((string)$ev['end_time'], 0, 2) * 60 + (int)substr((string)$ev['end_time'], 3, 2) : $a + 60;
                $items[$ev['event_date']][] = ['a' => $a, 'b' => max($a + 15, $b), 't' => ['kind' => 'event'], 'label' => $ev['title'] . ($ev['location'] ? ' · ' . $ev['location'] : ''), 'url' => '', 'hidden' => false, 'event' => true];
            } else {
                $allday[$ev['event_date']][] = ['label' => $ev['title'], 'url' => '', 'cls' => 'ev'];
            }
        }
    }
    $wh = $whoUser ? team_work_hours($whoUser) : team_work_default();
    $nowD = (new DateTimeImmutable('now', team_tz()));
    $people = ($myMember && !isset($members[$myMember]) ? [$myMember => ['name' => $me['name']]] : []) + $members;
?>
<div class="agbar">
  <div class="seg"><?php foreach (['dia' => 'Dia', 'semana' => 'Semana', 'mes' => 'Mês'] as $k => $l): ?>
    <a href="<?= e('/admin/calendar.php?' . http_build_query(['t' => 'agenda', 'v' => $k, 'd' => $d->format('Y-m-d'), 'p' => $who ?: null])) ?>" class="<?= $v === $k ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?></div>
  <a class="ibtn" href="<?= e($nav($prevD)) ?>" aria-label="Anterior" data-tip><?= icon('chev-left') ?></a>
  <a class="btn ghost sm" href="<?= e($nav($nowD)) ?>">Hoje</a>
  <a class="ibtn" href="<?= e($nav($nextD)) ?>" aria-label="Seguinte" data-tip><?= icon('chev-right') ?></a>
  <b class="agtitle"><?= e($v === 'mes' ? month_name((int)$d->format('n')) . ' ' . $d->format('Y') : ($v === 'dia' ? TEAM_DAYS_LONG[(int)$d->format('N')] . ', ' . $d->format('d/m/Y') : 'Semana de ' . $from->format('d/m') . ' a ' . $to->modify('-1 day')->format('d/m'))) ?></b>
  <span class="spacer"></span>
  <form method="get" class="inl-f"><input type="hidden" name="t" value="agenda"><input type="hidden" name="v" value="<?= e($v) ?>"><input type="hidden" name="d" value="<?= e($d->format('Y-m-d')) ?>">
    <label class="sr" for="ag-p">Agenda de</label><select id="ag-p" name="p" onchange="this.form.submit()">
      <?php foreach ($people as $mid => $m): ?><option value="<?= (int)$mid ?>"<?= $mid === $who ? ' selected' : '' ?>><?= e($mid === $myMember ? 'A minha agenda' : $m['name']) ?></option><?php endforeach; ?>
    </select></form>
</div>
<?php if ($v === 'mes'): ?>
<div class="agmonth">
  <?php foreach (['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'] as $dn): ?><div class="amh"><?= $dn ?></div><?php endforeach; ?>
  <?php for ($i = 0; $i < $days; $i++): $x = $from->modify("+$i days"); $ds = $x->format('Y-m-d');
      $list = array_merge(array_map(fn($it) => $it + ['time' => sprintf('%02d:%02d', intdiv($it['a'], 60), $it['a'] % 60)], $items[$ds] ?? []), $allday[$ds] ?? []);
      usort($list, fn($p, $q) => ($p['time'] ?? '') <=> ($q['time'] ?? '')); ?>
    <div class="amd<?= $x->format('n') !== $d->format('n') ? ' out' : '' ?><?= $ds === $nowD->format('Y-m-d') ? ' is-today' : '' ?>" data-day="<?= $ds ?>">
      <a class="amn" href="<?= e('/admin/calendar.php?' . http_build_query(['t' => 'agenda', 'v' => 'dia', 'd' => $ds, 'p' => $who ?: null])) ?>"><?= (int)$x->format('j') ?></a>
      <?php foreach (array_slice($list, 0, 4) as $it): $ty = isset($it['t']) && empty($it['hidden']) ? task_type($it['t']) : 'busy'; ?>
        <<?= $it['url'] ? 'a href="' . e($it['url']) . '"' : 'span' ?> class="amc ty-<?= e($ty) ?><?= !empty($it['hidden']) ? ' priv' : '' ?> <?= e($it['cls'] ?? '') ?>"><?= isset($it['time']) ? '<b>' . e($it['time']) . '</b> ' : '' ?><?= e(shorten((string)$it['label'], 24)) ?></<?= $it['url'] ? 'a' : 'span' ?>>
      <?php endforeach; ?>
      <?php if (count($list) > 4): ?><span class="muted small">+ <?= count($list) - 4 ?></span><?php endif; ?>
    </div>
  <?php endfor; ?>
</div>
<?php else: ?>
<div class="agwrap" style="--days:<?= $days ?>">
  <div class="aghead"><span></span>
    <?php for ($i = 0; $i < $days; $i++): $x = $from->modify("+$i days"); ?>
      <span class="<?= $x->format('Y-m-d') === $nowD->format('Y-m-d') ? 'is-today' : '' ?>"><?= e(TEAM_DAYS[(int)$x->format('N')]) ?> <b><?= $x->format('d') ?></b></span>
    <?php endfor; ?></div>
  <div class="agallday"><span class="muted small">dia</span>
    <?php for ($i = 0; $i < $days; $i++): $ds = $from->modify("+$i days")->format('Y-m-d'); ?>
      <div><?php foreach ($allday[$ds] ?? [] as $it): ?><<?= $it['url'] ? 'a href="' . e($it['url']) . '"' : 'span' ?> class="agc <?= e($it['cls'] ?? '') ?><?= !empty($it['hidden']) ? ' priv' : '' ?>"><?= e(shorten((string)$it['label'], 30)) ?></<?= $it['url'] ? 'a' : 'span' ?>><?php endforeach; ?></div>
    <?php endfor; ?></div>
  <div class="agscroll" data-agscroll>
    <div class="aggrid">
      <div class="aghours"><?php for ($h = 0; $h < 24; $h++): ?><span><?= sprintf('%02d:00', $h) ?></span><?php endfor; ?></div>
      <?php for ($i = 0; $i < $days; $i++): $x = $from->modify("+$i days"); $ds = $x->format('Y-m-d'); $dow = (int)$x->format('N'); $w = $wh[$dow] ?? null; ?>
        <div class="agday<?= $own && $canEdit ? ' can-make' : '' ?>" data-day="<?= $ds ?>">
          <?php for ($h = 0; $h < 24; $h++): $off = !$w || sprintf('%02d:00', $h) < $w[0] || sprintf('%02d:00', $h) >= $w[1]; ?><i class="<?= $off ? 'off' : '' ?>"></i><?php endfor; ?>
          <?php foreach (agenda_lanes($items[$ds] ?? []) as $it): $t = $it['t']; $ty = $it['hidden'] ? 'busy' : (!empty($it['event']) ? 'event' : task_type($t)); ?>
            <<?= $it['url'] ? 'a href="' . e($it['url']) . '"' : 'div' ?> class="agev ty-<?= e($ty) ?><?= $it['hidden'] ? ' priv' : '' ?><?= ($t['status'] ?? '') === 'done' ? ' is-done' : '' ?><?= ($t['kind'] ?? '') === 'block' ? ' blk' : '' ?><?= isset($t['busy']) && !(int)$t['busy'] ? ' free' : '' ?>"
              style="top:calc(<?= $it['a'] ?> * var(--min));height:calc(<?= max(15, $it['b'] - $it['a']) ?> * var(--min));left:calc(<?= $it['lane'] ?> / <?= $it['lanes'] ?> * 100%);width:calc(100% / <?= $it['lanes'] ?> - 3px)"
              data-tip="<?= e(sprintf('%02d:%02d–%02d:%02d', intdiv($it['a'], 60), $it['a'] % 60, intdiv($it['b'], 60) % 24, $it['b'] % 60) . ' · ' . $it['label']) ?>">
              <?= !$it['hidden'] && empty($it['event']) ? icon(TASK_TYPES[task_type($t)][1]) : '' ?><b><?= e(sprintf('%02d:%02d', intdiv($it['a'], 60), $it['a'] % 60)) ?></b> <?= e((string)$it['label']) ?>
              <?php if (!$it['hidden'] && ($t['platform'] ?? '') !== ''): ?><small><?= e(MEETING_PLATFORMS_TASK[$t['platform']] ?? '') ?></small><?php elseif (!$it['hidden'] && ($t['location'] ?? '') !== ''): ?><small><?= e((string)$t['location']) ?></small><?php endif; ?>
            </<?= $it['url'] ? 'a' : 'div' ?>>
          <?php endforeach; ?>
          <?php if ($ds === $nowD->format('Y-m-d')): ?><span class="agnow" style="top:calc(<?= (int)$nowD->format('G') * 60 + (int)$nowD->format('i') ?> * var(--min))" data-now></span><?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
  </div>
</div>
<p class="muted small"><?= $own && $canEdit ? 'Arraste na grelha para criar uma tarefa, uma tarefa pessoal ou um bloqueio. ' : '' ?>Horas fora do horário de trabalho mais escuras. Tarefas pessoais e bloqueios aparecem aos outros só como «Ocupado».</p>
<div class="agpick" data-agpick hidden role="menu" aria-label="Criar">
  <button type="button" role="menuitem" data-make="task"><?= icon('checklist') ?> Tarefa</button>
  <button type="button" role="menuitem" data-make="personal"><?= icon('user') ?> Tarefa pessoal</button>
  <button type="button" role="menuitem" data-make="block"><?= icon('ban') ?> Bloqueio</button>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
// «+ Nova tarefa» / editar (§18.7). ?nova=1&dia=&hora=&dur=&tipo= abre já preenchido.
$pre = [];
if (!empty($_GET['dia']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['dia'])) $pre['due_date'] = $_GET['dia'];
if (!empty($_GET['hora']) && preg_match('/^\d{2}:\d{2}$/', $_GET['hora'])) $pre['due_time'] = $_GET['hora'] . ':00';
if (!empty($_GET['dur'])) $pre['duration_min'] = (int)$_GET['dur'];
if (isset(TASK_TYPES[$_GET['tipo'] ?? ''])) $pre['kind'] = $_GET['tipo'];
if (!$operator) $pre['member_id'] = null;
if ($canEdit) echo task_modal_html($pdo, $editing, $assignable, $operator ? $myMember : null, $participants, $here, $editing !== null || !empty($_GET['nova']), $pre);
?>
<?php if ($canEdit):
    require_once __DIR__ . '/inc/lead_view.php';
    echo lead_modals_html(); ?>
<div class="mdl" id="mdl-callnote" role="dialog" aria-modal="true" aria-labelledby="mdl-callnote-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card pop-in" data-callnote>
    <h3 id="mdl-callnote-t">Chamada feita</h3><p class="muted" data-cn-name></p>
    <label class="field"><span>Nota da chamada <small class="muted">(opcional)</small></span>
      <textarea name="note" rows="4" maxlength="4000" placeholder="O que se falou, o que ficou combinado…"></textarea></label>
    <p class="muted small">Fica no histórico do negócio (ou do contacto), como chamada.</p>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><button class="btn" type="submit"><?= icon('check') ?> Concluir</button></div>
  </form>
</div>
<?php endif; ?>
<script src="<?= asset_url('tasks.js') ?>" defer></script>
<script src="<?= asset_url('gcal.js') ?>" defer></script>
<?php render_footer();
