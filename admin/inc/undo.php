<?php
/**
 * topper. admin — «Anular» em vez de «Tens a certeza?» (§11.10)
 *
 * As ações reversíveis fazem-se logo; quem as fez recebe um aviso em
 * baixo com «Anular» durante 8 segundos. O que é preciso para desfazer
 * fica na sessão, preso a quem fez a ação, e caduca pouco depois. Ao
 * anular confirma-se outra vez a permissão e que o registo não mudou
 * entretanto. Eliminar continua a pedir confirmação.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/reach.php';
require_once __DIR__ . '/inline.php';

/** Segundos em que o «Anular» ainda vale (o aviso mostra 8; a margem cobre o recarregar da página). */
const UNDO_TTL = 90;

/** Guarda o necessário para desfazer e devolve o código do «Anular». */
function undo_push(string $kind, array $data): string {
    $now = time();
    $list = array_filter($_SESSION['undo'] ?? [], fn($u) => $u['exp'] > $now);
    $token = bin2hex(random_bytes(12));
    $list[$token] = ['kind' => $kind, 'data' => $data, 'uid' => (int)current_user()['id'], 'exp' => $now + UNDO_TTL];
    $_SESSION['undo'] = array_slice($list, -20, null, true);
    return $token;
}

/** O estado de um negócio antes de mudar de etapa, para o poder repor. */
function undo_deal_snapshot(array $d): array {
    return ['id' => (int)$d['id'], 'stage_id' => $d['stage_id'], 'phase' => $d['phase'], 'done_at' => $d['done_at'],
            'year' => $d['year'], 'month' => $d['month'], 'last_touch' => $d['last_touch']];
}

/** Desfaz. Devolve a mensagem; RuntimeException quando já não dá. */
function undo_run(PDO $pdo, string $token): string {
    $u = $_SESSION['undo'][$token] ?? null;
    unset($_SESSION['undo'][$token]);
    if (!$u || $u['exp'] < time() || $u['uid'] !== (int)current_user()['id']) {
        throw new RuntimeException('Já não é possível anular.');
    }
    return undo_apply($pdo, $u['kind'], $u['data']);
}

/** Desfaz uma ação já validada (quem, prazo). Também usado pelas ações compostas. */
function undo_apply(PDO $pdo, string $kind, array $d): string {
    $changed = 'O registo mudou entretanto; não foi anulado.';

    switch ($kind) {
        case 'multi':
            // Várias alterações de uma vez (ex.: «Gravar» na ficha da lead): desfaz todas ou nenhuma.
            $pdo->beginTransaction();
            try {
                foreach ($d['subs'] as [$k, $data]) undo_apply($pdo, $k, $data);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            return $d['msg'] ?? 'Alterações repostas.';

        case 'deal_stage':
            $deal = record_get($pdo, (int)$d['id']);
            if (!$deal) throw new RuntimeException($changed);
            // Chegar a Kickoff passa o negócio para Negócios: vale o acesso de antes ou o de agora.
            if (!record_reach($pdo, $deal, PERM_EDIT) && !record_reach($pdo, ['phase' => $d['phase']] + $deal, PERM_EDIT)) {
                throw new RuntimeException('Sem permissão.');
            }
            if ((int)$deal['stage_id'] !== (int)$d['to_stage']) throw new RuntimeException($changed);
            $pdo->prepare('UPDATE deals SET stage_id = ?, phase = ?, done_at = ?, year = ?, month = ?, last_touch = ? WHERE id = ?')
                ->execute([$d['stage_id'], $d['phase'], $d['done_at'], $d['year'], $d['month'], $d['last_touch'], $d['id']]);
            deal_log($pdo, (int)$d['id'], 'stage', ['from_funnel' => $deal['funnel_id'], 'from_stage' => $deal['stage_id'],
                                                     'to_funnel' => $deal['funnel_id'], 'to_stage' => $d['stage_id'], 'note' => 'Anulado']);
            return 'Etapa reposta.';

        case 'deal_lost':
            $deal = record_get($pdo, (int)$d['id']);
            if (!record_reach($pdo, $deal, PERM_EDIT)) throw new RuntimeException('Sem permissão.');
            if ($deal['phase'] !== 'lost') throw new RuntimeException($changed);
            $pdo->prepare("UPDATE deals SET phase = 'pipeline', stage_id = ?, lost_reason = '', lost_stage_id = NULL, lost_at = NULL WHERE id = ?")
                ->execute([$d['stage_id'], $d['id']]);
            deal_log($pdo, (int)$d['id'], 'reopen', ['to_funnel' => $deal['funnel_id'], 'to_stage' => $d['stage_id'], 'note' => 'Anulado']);
            return $deal['code'] . ' voltou ao Pipeline.';

        case 'lead_state':
            $lead = record_get($pdo, (int)$d['id']);
            if (!$lead || $lead['phase'] !== 'lead' || !record_reach($pdo, $lead, PERM_EDIT)) throw new RuntimeException('Sem permissão.');
            if ($lead['lead_state'] !== $d['to']) throw new RuntimeException($changed);
            // Repõe também o prazo e as tentativas como estavam (§12.4).
            $pdo->prepare('UPDATE deals SET lead_state = ?, last_touch = ?, state_since = ?, no_answer = ? WHERE id = ?')
                ->execute([$d['from'], $d['last_touch'], $d['state_since'] ?? $lead['state_since'], (int)($d['no_answer'] ?? $lead['no_answer']), $d['id']]);
            deal_log($pdo, (int)$d['id'], 'state', ['note' => (LEAD_STATE[$d['to']] ?? $d['to']) . ' → ' . (LEAD_STATE[$d['from']] ?? $d['from']) . ' (anulado)']);
            return 'Estado reposto.';

        case 'lead_archive':
            $lead = record_get($pdo, (int)$d['id']);
            if (!$lead || $lead['phase'] !== 'archived' || !record_reach($pdo, $lead, PERM_EDIT)) throw new RuntimeException($changed);
            $pdo->prepare("UPDATE deals SET phase = 'lead', archived_reason = '', archived_note = '', archived_at = NULL WHERE id = ?")
                ->execute([$d['id']]);
            lead_restore_tasks($pdo, $d['tasks'] ?? []);   // as tarefas abertas voltam ao Foco
            deal_log($pdo, (int)$d['id'], 'reactivate', ['note' => 'Arquivo anulado']);
            return '«' . $lead['name'] . '» voltou às leads ativas.';

        case 'assign':
            $row = record_get($pdo, (int)$d['id']);
            if (!$row || !can(deal_tab_for($row['phase']), PERM_EDIT)) throw new RuntimeException('Sem permissão.');
            if ((string)$row['owner_id'] !== (string)$d['to']) throw new RuntimeException($changed);
            $me = me_row($pdo);
            $name = 'por atribuir';
            if ($d['from'] === null) {
                if (!sees_unassigned($me)) throw new RuntimeException('Sem permissão.');
            } else {
                $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $st->execute([(int)$d['from']]);
                $t = $st->fetch();
                if (!$t || !can_assign_to($me, $t)) throw new RuntimeException('Já não pode devolver a essa pessoa.');
                $name = $t['name'];
            }
            if ($row['phase'] === 'lead') {
                lead_reassign($pdo, $row, $d['from'] === null ? null : (int)$d['from']);   // as tarefas da lead voltam também
            } else {
                $pdo->prepare('UPDATE deals SET owner_id = ? WHERE id = ?')->execute([$d['from'], $d['id']]);
                deal_log($pdo, (int)$d['id'], 'assign', ['note' => 'Responsável: ' . $name . ' (anulado)']);
            }
            return 'Responsável reposto.';

        case 'deal_paid':
            require_perm('financeiro', PERM_EDIT);
            $pdo->prepare('UPDATE deals SET is_paid = ? WHERE id = ? AND code IS NOT NULL')->execute([(int)$d['from'], $d['id']]);
            return (int)$d['from'] ? 'Volta a estar paga.' : 'Volta a estar por pagar.';

        case 'cost_closed':
            require_perm('financeiro', PERM_EDIT);
            $pdo->prepare('UPDATE costs SET is_closed = ? WHERE id = ?')->execute([(int)$d['from'], $d['id']]);
            return 'Custo reposto.';

        case 'task':
            require_once __DIR__ . '/tasks.php';
            require_perm('tarefas', PERM_EDIT);
            $t = task_by_id($pdo, (int)$d['id']);
            $members = team_members($pdo);
            $visible = task_visible_members($pdo, $members);
            if (!$t || !task_member_visible($t['member_id'] !== null ? (int)$t['member_id'] : null, $visible)) throw new RuntimeException($changed);
            $from = $d['member_id'] !== null ? (int)$d['member_id'] : null;
            if (!task_member_visible($from, $visible)) throw new RuntimeException('Sem permissão.');
            $pdo->prepare('UPDATE tasks SET status = ?, done_at = ?, member_id = ?, position = ?, updated_at = ? WHERE id = ?')
                ->execute([$d['status'], $d['done_at'], $from, $d['position'], task_now(), $d['id']]);
            // A repetição criada ao concluir sai também (se ainda ninguém lhe mexeu).
            if (!empty($d['spawned'])) $pdo->prepare("DELETE FROM tasks WHERE id = ? AND status = 'todo'")->execute([(int)$d['spawned']]);
            return 'Tarefa reposta.';

        case 'inline':
            inline_save($pdo, $d['entity'], (int)$d['id'], $d['field'], $d['prev'] === null ? '' : (string)$d['prev']);
            return 'Valor reposto.';

        case 'brought_by':
            // «Trazido por» de uma lead ou negócio: volta ao contacto de antes.
            $row = record_get($pdo, (int)$d['id']);
            if (!$row || !record_reach($pdo, $row, PERM_EDIT)) throw new RuntimeException('Sem permissão.');
            if (($row['brought_by_id'] !== null ? (int)$row['brought_by_id'] : null) !== $d['to']) throw new RuntimeException($changed);
            $pdo->prepare('UPDATE deals SET brought_by_id = ? WHERE id = ?')->execute([$d['from'], $d['id']]);
            deal_log($pdo, (int)$d['id'], 'brought', ['note' => 'Trazido por reposto (anulado)']);
            return '«Trazido por» reposto.';

        // ── Contactos (§13) ──
        case 'contact_restore':
            // «Gravar» na ficha: repõe os campos se ninguém lhes mexeu entretanto.
            require_once __DIR__ . '/contacts.php';
            require_perm('contactos', PERM_EDIT);
            $c = contact_get($pdo, (int)$d['id']);
            if (!$c || contact_snapshot($c) != $d['after'] || $c['kind'] !== $d['kind_after']) throw new RuntimeException($changed);
            $cols = array_keys($d['before']);
            $pdo->prepare('UPDATE contacts SET kind = ?, ' . implode(', ', array_map(fn($f) => "$f = ?", $cols)) . ' WHERE id = ?')
                ->execute([$d['kind_before'], ...array_values($d['before']), $d['id']]);
            contact_log($pdo, (int)$d['id'], 'c_edit', 'Alterações anuladas');
            return 'Ficha reposta como estava.';

        case 'contact_active':
            require_once __DIR__ . '/contacts.php';
            if (!contacts_admin($pdo)) throw new RuntimeException('Só superadmin e admins mudam o estado.');
            $c = contact_get($pdo, (int)$d['id']);
            if (!$c || (int)$c['is_active'] !== (int)$d['to']) throw new RuntimeException($changed);
            $pdo->prepare('UPDATE contacts SET is_active = ? WHERE id = ?')->execute([(int)$d['from'], $d['id']]);
            contact_log($pdo, (int)$d['id'], 'c_active', ((int)$d['from'] ? 'Ativo' : 'Inativo') . ' (anulado)');
            return 'Estado reposto.';

        case 'service_line':
            // Tirar uma linha de serviço: volta a estar em vigor.
            require_once __DIR__ . '/contacts.php';
            require_perm('contactos', PERM_EDIT);
            $st = $pdo->prepare('SELECT * FROM contact_services WHERE id = ? AND contact_id = ?');
            $st->execute([(int)$d['line'], (int)$d['id']]);
            $r = $st->fetch();
            if (!$r || $r['ended_at'] === null) throw new RuntimeException($changed);
            $pdo->prepare('UPDATE contact_services SET ended_at = NULL WHERE id = ?')->execute([$r['id']]);
            contact_log($pdo, (int)$d['id'], 'c_price', 'Serviço reposto (anulado)');
            return 'Serviço reposto.';

        case 'contact_task':
            // Concluir no Foco do contacto: a tarefa reabre e a relação volta ao que estava.
            require_once __DIR__ . '/contacts.php';
            require_perm('contactos', PERM_EDIT);
            $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND contact_id = ?');
            $st->execute([(int)$d['task'], (int)$d['id']]);
            $t = $st->fetch();
            if (!$t || $t['status'] !== 'done') throw new RuntimeException($changed);
            $pdo->prepare('UPDATE tasks SET status = ?, done_at = NULL, member_id = ?, position = ?, updated_at = ? WHERE id = ?')
                ->execute([$d['status'], $d['member_id'], $d['position'], date('Y-m-d H:i:s'), $t['id']]);
            if (!empty($d['spawned'])) $pdo->prepare("DELETE FROM tasks WHERE id = ? AND status = 'todo'")->execute([(int)$d['spawned']]);
            if (!empty($d['rel'])) undo_apply($pdo, 'relation_row', $d['rel']);
            return 'Tarefa reposta.';

        case 'relation_row':
            // Repõe a relação como estava (toques, datas, pausa, classificações); recria-a se saiu.
            require_once __DIR__ . '/contacts.php';
            require_perm('contactos', PERM_EDIT);
            $cols = array_keys($d);
            if (relation_get($pdo, (int)$d['contact_id'])) {
                $set = array_values(array_diff($cols, ['contact_id']));
                $pdo->prepare('UPDATE relations SET ' . implode(', ', array_map(fn($f) => "$f = ?", $set)) . ' WHERE contact_id = ?')
                    ->execute([...array_map(fn($f) => $d[$f], $set), $d['contact_id']]);
            } else {
                $pdo->prepare('INSERT INTO relations (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($d));
                contact_log($pdo, (int)$d['contact_id'], 'rel_resume', 'Voltou a ser acompanhada (anulado)');
            }
            return 'Relação reposta.';

        case 'relation_drop':
            // «Acompanhar relação» acabado de fazer: deixa de ser acompanhada.
            require_once __DIR__ . '/contacts.php';
            require_perm('contactos', PERM_EDIT);
            if (!relation_get($pdo, (int)$d['id'])) throw new RuntimeException($changed);
            relation_stop($pdo, (int)$d['id']);
            return 'Já não é acompanhada.';
    }
    throw new RuntimeException('Já não é possível anular.');
}
