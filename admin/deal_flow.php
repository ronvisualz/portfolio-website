<?php
/**
 * topper. admin — ações do negócio no Pipeline (§19)
 *
 * Só POST (com CSRF), exceto o descarregar de um documento de faturação (GET ?bill=ID).
 * Tudo volta a ser verificado aqui: o negócio ao alcance de quem pede, os gates (deal_move),
 * quem valida (comercial ou admin), quem força (admins, com motivo) e quem fatura.
 *
 *   move          stage_id [force]       avançar (ou forçar) para uma etapa
 *   lost          reason [reason_other]  perdido (fase comercial)
 *   people        owner_id exec_owner_id delivery_date shoot_date
 *   prop_send     email [note]           enviar a proposta, com uma mensagem opcional (move para Aguardar feedback)
 *   prop_validate / prop_contact         a resposta do cliente
 *   invoice       task number doc_date net vat total file
 *   payment       task doc_date [total] [file] [note]
 *   round         [note]                 mais uma ronda de revisão na etapa
 *   task_add      kind title date assignee
 *   task_done     task
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/pipeline_view.php';
require_once __DIR__ . '/inc/leads.php';
require_once __DIR__ . '/inc/undo.php';
$user = require_login();

// ── descarregar um documento de faturação ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['bill'])) {
    $st = $pdo->prepare('SELECT * FROM deal_billing WHERE id = ?');
    $st->execute([(int)$_GET['bill']]);
    $b = $st->fetch();
    $deal = $b ? record_get($pdo, (int)$b['deal_id']) : null;
    if (!$b || !$deal || !record_reach($pdo, $deal) || !(flow_is_commercial($deal) || can('financeiro'))) {
        http_response_code(404);
        exit('Documento não encontrado.');
    }
    if (str_starts_with($b['file_key'], 'r2:')) {
        require_once __DIR__ . '/inc/r2.php';
        header('Location: ' . r2_presign('GET', substr($b['file_key'], 3), 300, ['response-content-disposition' => 'inline; filename="' . $b['file_name'] . '"']), true, 302);
        exit;
    }
    $path = billing_local_dir() . '/' . basename(substr($b['file_key'], 6));
    if (!str_starts_with($b['file_key'], 'local:') || !is_file($path)) { http_response_code(404); exit('Documento não encontrado.'); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $b['file_name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/admin/pipeline.php');
csrf_check();
$do = (string)($_POST['do'] ?? '');
$back = (string)($_POST['back'] ?? '');
if (!preg_match('~^/admin/[a-z_]+\.php(\?[^#\s]*)?(#[A-Za-z0-9_-]*)?$~', $back)) $back = '/admin/pipeline.php';
$deal = record_get($pdo, (int)($_POST['id'] ?? 0));
if (!$deal || $deal['code'] === null || !record_reach($pdo, $deal)) { flash('Negócio não encontrado.', 'err'); redirect($back); }
$me = (int)$user['id'];
$must = function (bool $ok, string $why = 'Sem permissão para mudar este negócio.') { if (!$ok) throw new RuntimeException($why); };
$task = function () use ($pdo, $deal): ?array {
    $t = task_by_id($pdo, (int)($_POST['task'] ?? 0));
    if ($t && (int)$t['deal_id'] !== (int)$deal['id']) $t = null;
    return $t;
};

try {
    switch ($do) {
        case 'move':
            $must(flow_can_edit($pdo, $deal));
            $to = (int)($_POST['stage_id'] ?? 0);
            $force = trim((string)($_POST['force'] ?? ''));
            $snap = undo_deal_snapshot($deal);
            flow_created(null, true);
            $msg = deal_move($pdo, $deal, $to, $force);
            flash(($force !== '' ? 'Passagem forçada (fica no histórico). ' : '') . $msg, 'ok',
                  (int)$deal['stage_id'] !== $to ? undo_push('deal_stage', $snap + ['to_stage' => $to, 'auto_tasks' => flow_created()]) : '');
            break;

        case 'lost':
            $must(flow_can_edit($pdo, $deal));
            $r = (string)($_POST['reason'] ?? '');
            if ($r === 'Outro' && trim((string)($_POST['reason_other'] ?? '')) !== '') $r = 'Outro: ' . trim((string)$_POST['reason_other']);
            elseif (trim((string)($_POST['reason_other'] ?? '')) !== '') $r .= ': ' . trim((string)$_POST['reason_other']);
            deal_mark_lost($pdo, $deal, $r);
            flash($deal['code'] . ' marcado como perdido.', 'ok', undo_push('deal_lost', ['id' => (int)$deal['id'], 'stage_id' => $deal['stage_id']]));
            break;

        case 'people':
            $must(flow_can_edit($pdo, $deal));
            $date = function (string $k) {
                $v = trim((string)($_POST[$k] ?? ''));
                if ($v === '') return null;
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !strtotime($v)) throw new RuntimeException('Data inválida.');
                return $v;
            };
            $set = ['delivery_date' => $date('delivery_date'), 'shoot_date' => $date('shoot_date')];
            $notes = [];
            if (is_admin()) {
                foreach (['owner_id' => 'Comercial', 'exec_owner_id' => 'Execução'] as $col => $label) {
                    if (!array_key_exists($col, $_POST)) continue;
                    $uid = (int)$_POST[$col] ?: null;
                    if ($uid !== null && flow_user_ok($pdo, $uid) === null) throw new RuntimeException('Escolha um operador ativo (o superadmin não fica com negócios).');
                    if ($uid !== ($deal[$col] !== null ? (int)$deal[$col] : null)) { $set[$col] = $uid; $notes[] = $label . ': ' . (flow_user_name($pdo, $uid) ?: 'por atribuir'); }
                }
            }
            $pdo->prepare('UPDATE deals SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?')->execute([...array_values($set), $deal['id']]);
            if ($notes) deal_log($pdo, (int)$deal['id'], 'roles', ['note' => implode(' · ', $notes)]);
            $datesChanged = $set['delivery_date'] !== $deal['delivery_date'] || $set['shoot_date'] !== $deal['shoot_date'];
            if ($datesChanged) {
                deal_log($pdo, (int)$deal['id'], 'dates', ['note' => 'Entrega: ' . ($set['delivery_date'] ? date('d/m/Y', strtotime($set['delivery_date'])) : '—') . ' · rodagem: ' . ($set['shoot_date'] ? date('d/m/Y', strtotime($set['shoot_date'])) : '—')]);
                $n = flow_dates_changed($pdo, (int)$deal['id']);
            }
            // As tarefas automáticas abertas de um responsável que mudou passam para o novo.
            foreach (['owner_id' => ['commercial', 'both', 'owner'], 'exec_owner_id' => ['execution']] as $col => $roles) {
                if (!array_key_exists($col, $set)) continue;
                $mid = $set[$col] ? member_for_user($pdo, $set[$col]) : null;
                $pdo->prepare("UPDATE tasks SET member_id = ? WHERE deal_id = ? AND status <> 'done' AND auto_rule_id IN (SELECT id FROM stage_automations WHERE assign_to IN ('" . implode("','", $roles) . "'))")
                    ->execute([$mid, $deal['id']]);
            }
            flash('Guardado.' . ($datesChanged && !empty($n) ? ' Os prazos de ' . $n . ' tarefa' . ($n === 1 ? '' : 's') . ' acompanharam as datas.' : ''));
            break;

        case 'prop_send':
            $must(flow_can_edit($pdo, $deal));
            [$msg, $link] = proposal_send($pdo, $deal, (string)($_POST['email'] ?? ''), $me, (string)($_POST['note'] ?? ''));
            flash($msg . ' Link (só aparece agora): ' . $link);
            break;

        case 'prop_validate':
        case 'prop_contact':
            $must(flow_can_edit($pdo, $deal) && flow_is_commercial($deal), 'Quem valida a resposta do cliente é o responsável comercial ou um admin.');
            $p = proposal_latest($pdo, (int)$deal['id']);
            if (!$p) throw new RuntimeException('Não há proposta enviada.');
            flash($do === 'prop_validate' ? proposal_validate($pdo, $p, $me) : proposal_contact($pdo, $p, $me));
            break;

        case 'invoice':
        case 'payment':
            $must(flow_can_bill($pdo, $deal), 'A faturação é do responsável comercial, de quem edita o Financeiro ou de um admin.');
            $t = $task();
            if ($t && $t['doc_kind'] !== $do) $t = null;
            flash($do === 'invoice'
                ? billing_submit_invoice($pdo, $deal, $t, $_POST, $_FILES['file'] ?? [], $me)
                : billing_validate_payment($pdo, $deal, $t, $_POST, $_FILES['file'] ?? null, $me));
            break;

        case 'round':
            $must(flow_can_edit($pdo, $deal));
            $s = $deal['stage_id'] ? stage_get($pdo, (int)$deal['stage_id']) : null;
            if (!$s) throw new RuntimeException('O negócio não está numa etapa.');
            flash(rounds_add($pdo, $deal, $s, (string)($_POST['note'] ?? ''), $me));
            break;

        case 'task_add':
            $must(flow_can_edit($pdo, $deal));
            $kind = (string)($_POST['kind'] ?? 'task');
            if (!isset(TASK_TYPES[$kind]) || in_array($kind, ['personal', 'block'], true)) throw new RuntimeException('Escolha o tipo.');
            $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 200));
            if ($title === '') throw new RuntimeException('Escreva o que há a fazer.');
            $date = trim((string)($_POST['date'] ?? ''));
            if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Indique uma data válida.');
            $who = (int)($_POST['assignee'] ?? 0) ?: null;
            if ($who !== null) {
                $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $st->execute([$who]);
                $t = $st->fetch();
                if (!$t || $t['role'] === 'superadmin' || ((int)$t['id'] !== $me && !can_assign_to(me_row($pdo), $t))) throw new RuntimeException('Não pode atribuir tarefas a essa pessoa.');
            }
            $member = $who ? member_for_user($pdo, $who) : null;
            $now = task_now();
            $pdo->prepare('INSERT INTO tasks (title, description, member_id, job_name, deal_id, due_date, kind, priority, status, position, created_at, updated_at, created_by, doc_kind)
                           VALUES (?,\'\',?,\'\',?,?,?,\'media\',\'todo\',?,?,?,?,?)')
                ->execute([$title, $member, $deal['id'], $date !== '' ? $date : null, $kind, task_next_position($pdo, $member), $now, $now, $me, task_doc_kind_for($title, $deal['id'])]);
            $tid = (int)$pdo->lastInsertId();
            deal_log($pdo, (int)$deal['id'], 'task_new', ['note' => TASK_TYPES[$kind][0] . ' · ' . $title]);
            flash('Tarefa criada.', 'ok', undo_push('task_new', ['id' => $tid]));
            break;

        case 'task_done':
            $must(flow_can_edit($pdo, $deal));
            $t = $task();
            if (!$t || $t['status'] === 'done') throw new RuntimeException('Essa tarefa já não está aberta.');
            $snap = ['id' => (int)$t['id'], 'status' => $t['status'], 'done_at' => $t['done_at'], 'member_id' => $t['member_id'], 'position' => $t['position'], 'help_by' => $t['help_by'] ?? null];
            $snap['spawned'] = task_done($pdo, (int)$t['id']);
            task_log($pdo, (int)$t['id'], 'done', 'Concluída');
            $extra = '';
            if ($t['doc_kind'] === 'noreply' && $deal['phase'] === 'pipeline') $extra = ' Sem resposta do cliente? Marque o negócio como perdido com o motivo «Sem resposta».';
            flash('«' . $t['title'] . '» feita.' . $extra, 'ok', undo_push('task', $snap));
            break;

        default:
            throw new RuntimeException('Pedido inválido.');
    }
} catch (GateBlocked $e) {
    flash($e->getMessage() . (is_admin() ? ' Como admin, pode forçar com motivo.' : ''), 'err');
} catch (RuntimeException $e) {
    flash($e->getMessage(), 'err');
}
redirect($back);
