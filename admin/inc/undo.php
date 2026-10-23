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
    require_once __DIR__ . '/xsell.php';
    $lines = xsell_snapshot($GLOBALS['pdo'], (int)$d['id']);   // as frentes de crosselling (§22)
    return ['id' => (int)$d['id'], 'stage_id' => $d['stage_id'], 'phase' => $d['phase'], 'done_at' => $d['done_at'],
            'year' => $d['year'], 'month' => $d['month'], 'last_touch' => $d['last_touch']] + ($lines ? ['lines' => $lines] : []);
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
            // As tarefas automáticas que a entrada na etapa criou (§19.7) saem, se ainda ninguém lhes mexeu.
            $ids = array_values(array_filter(array_map('intval', (array)($d['auto_tasks'] ?? []))));
            if ($ids) $pdo->exec("DELETE FROM tasks WHERE status = 'todo' AND id IN (" . implode(',', $ids) . ')');
            // Crosselling (§22): as frentes voltam como estavam (antes do Kickoff ou do fecho).
            require_once __DIR__ . '/xsell.php';
            if (!empty($d['lines'])) xsell_reopen($pdo, (int)$d['id'], (array)$d['lines']);
            return 'Etapa reposta.';

        case 'line_stage':   // uma frente de crosselling mudou de etapa (§22)
            $deal = record_get($pdo, (int)$d['id']);
            if (!$deal || !record_reach($pdo, $deal, PERM_EDIT)) throw new RuntimeException('Sem permissão.');
            require_once __DIR__ . '/xsell.php';
            $line = xsell_line_get($pdo, (int)$d['line']);
            if (!$line || (int)$line['stage_id'] !== (int)$d['to_stage']) throw new RuntimeException($changed);
            xsell_reopen($pdo, (int)$d['id'], (array)$d['lines']);
            deal_log($pdo, (int)$d['id'], 'stage', ['from_funnel' => $line['funnel_id'], 'from_stage' => $line['stage_id'], 'to_funnel' => $line['funnel_id'],
                                                     'to_stage' => array_column((array)$d['lines'], 'stage_id', 'id')[(int)$line['id']] ?? null, 'note' => 'Anulado']);
            $ids = array_values(array_filter(array_map('intval', (array)($d['auto_tasks'] ?? []))));
            if ($ids) $pdo->exec("DELETE FROM tasks WHERE status = 'todo' AND id IN (" . implode(',', $ids) . ')');
            return $line['funnel_name'] . ': etapa reposta.';

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

        case 'lead_spam':
            if (!can('leads', PERM_EDIT)) throw new RuntimeException('Sem permissão.');
            if (record_get($pdo, (int)$d['deal']['id'])) throw new RuntimeException($changed);
            require_once __DIR__ . '/leads.php';
            lead_spam_restore($pdo, $d);
            return '«' . lead_display_name($d['deal']) . '» voltou à quarentena.';

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

        case 'task_new':
            // Desfazer «Criar tarefa» (§18.7): sai, se ninguém lhe mexeu entretanto.
            require_perm('tarefas', PERM_EDIT);
            $st = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND status <> 'done'");
            $st->execute([(int)$d['id']]);
            if (!$st->rowCount()) throw new RuntimeException($changed);
            if (db_has_table($pdo, 'task_log')) $pdo->prepare('DELETE FROM task_log WHERE task_id = ?')->execute([(int)$d['id']]);
            return 'Tarefa retirada.';

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
            if (array_key_exists('help_by', $d) && db_has_column($pdo, 'tasks', 'help_by')) $pdo->prepare('UPDATE tasks SET help_by = ? WHERE id = ?')->execute([$d['help_by'], $d['id']]);
            // A repetição criada ao concluir sai também (se ainda ninguém lhe mexeu).
            if (!empty($d['spawned'])) $pdo->prepare("DELETE FROM tasks WHERE id = ? AND status = 'todo'")->execute([(int)$d['spawned']]);
            return 'Tarefa reposta.';

        case 'setting':
            // Definições (§20): o valor volta ao de antes, se ninguém lhe mexeu entretanto.
            require_once __DIR__ . '/settings_def.php';
            if (!is_admin()) throw new RuntimeException('Sem permissão.');
            $def = setting_def((string)$d['key']);
            if (!$def || setting_get($pdo, (string)$d['key']) !== (string)$d['to']) throw new RuntimeException($changed);
            setting_write($pdo, (string)$d['key'], (string)$d['from']);
            setting_log($pdo, (string)$d['key'], SET_TABS[$def['tab']][0] . ' · ' . $def['label'] . ' (anulado)', (string)$d['to'], (string)$d['from']);
            return $def['label'] . ' reposto: ' . setting_show($def, (string)$d['from']) . '.';

        case 'setting_row':
            // Uma célula das listas com tabela (serviços, pilares) volta ao que era.
            if (!is_admin()) throw new RuntimeException('Sem permissão.');
            if (!in_array($d['table'], ['services', 'mkt_pillars'], true) || !preg_match('/^[a-z_]+$/', (string)$d['field'])) throw new RuntimeException($changed);
            $st = $pdo->prepare('SELECT ' . $d['field'] . ' FROM ' . $d['table'] . ' WHERE id = ?');
            $st->execute([(int)$d['id']]);
            $cur = $st->fetchColumn();
            if ($cur === false || (string)$cur !== (string)$d['to']) throw new RuntimeException($changed);
            $pdo->prepare('UPDATE ' . $d['table'] . ' SET ' . $d['field'] . ' = ? WHERE id = ?')->execute([$d['from'], (int)$d['id']]);
            return 'Reposto.';

        case 'task_due':
            // «Adiar» na lista (§18.3): o prazo volta ao de antes, se ninguém lhe mexeu.
            require_once __DIR__ . '/tasks.php';
            require_perm('tarefas', PERM_EDIT);
            $t = task_by_id($pdo, (int)$d['id']);
            $members = team_members($pdo);
            $mine = member_for_user($pdo, (int)current_user()['id']);
            if ($t && (task_hidden_for($t, $mine) || (!task_member_visible($t['member_id'] !== null ? (int)$t['member_id'] : null, task_visible_members($pdo, $members)) && (int)$t['member_id'] !== (int)$mine))) throw new RuntimeException('Sem permissão.');
            if (!$t || (string)$t['due_date'] !== (string)$d['to_date'] || (string)$t['due_time'] !== (string)$d['to_time']) throw new RuntimeException($changed);
            $pdo->prepare('UPDATE tasks SET due_date = ?, due_time = ?, duration_min = ?, updated_at = ? WHERE id = ?')->execute([$d['due_date'], $d['due_time'], $d['duration_min'] ?? null, task_now(), $d['id']]);
            task_log($pdo, (int)$d['id'], 'snooze', 'Prazo reposto (anulado)');
            return 'Prazo reposto.';

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

        case 'deal_step':
            // Etapa de produção (§15.3): repõe a etapa, a fase e o passo de produção de antes.
            $deal = record_get($pdo, (int)$d['id']);
            if (!$deal) throw new RuntimeException($changed);
            if (!record_reach($pdo, $deal, PERM_EDIT) && !record_reach($pdo, ['phase' => $d['phase']] + $deal, PERM_EDIT)) throw new RuntimeException('Sem permissão.');
            if ((int)$deal['stage_id'] !== (int)$d['to_stage'] || (string)$deal['prod_step'] !== (string)$d['to_step']) throw new RuntimeException($changed);
            $pdo->prepare('UPDATE deals SET stage_id = ?, phase = ?, done_at = ?, year = ?, month = ?, last_touch = ?, prod_step = ? WHERE id = ?')
                ->execute([$d['stage_id'], $d['phase'], $d['done_at'], $d['year'], $d['month'], $d['last_touch'], $d['prod_step'], $d['id']]);
            if ((int)$d['stage_id'] !== (int)$d['to_stage']) {
                deal_log($pdo, (int)$d['id'], 'stage', ['from_funnel' => $deal['funnel_id'], 'from_stage' => $deal['stage_id'],
                                                         'to_funnel' => $deal['funnel_id'], 'to_stage' => $d['stage_id'], 'note' => 'Anulado']);
            } else {
                deal_log($pdo, (int)$d['id'], 'step', ['note' => 'Anulado']);
            }
            $ids = array_values(array_filter(array_map('intval', (array)($d['auto_tasks'] ?? []))));
            if ($ids) $pdo->exec("DELETE FROM tasks WHERE status = 'todo' AND id IN (" . implode(',', $ids) . ')');
            return 'Etapa reposta.';

        case 'deal_flags':
            // Entregas, portfólio e testemunho (§15.5): voltam ao que estavam; as tarefas criadas saem se ainda estiverem por fazer.
            $deal = record_get($pdo, (int)$d['id']);
            if (!$deal || !record_reach($pdo, $deal, PERM_EDIT)) throw new RuntimeException('Sem permissão.');
            $pdo->prepare('UPDATE deals SET delivered_at = ?, portfolio_at = ?, testimonial_at = ? WHERE id = ?')
                ->execute([$d['delivered_at'], $d['portfolio_at'], $d['testimonial_at'], $d['id']]);
            foreach ($d['tasks'] as $tid) $pdo->prepare("DELETE FROM tasks WHERE id = ? AND deal_id = ? AND status <> 'done'")->execute([(int)$tid, $d['id']]);
            // A ideia «Breakdown» criada em Marketing sai, se ninguém mexeu nela (§16.3).
            foreach ($d['ideas'] ?? [] as $iid) {
                $st = $pdo->prepare("SELECT rev FROM mkt_pieces WHERE id = ? AND deal_id = ? AND status = 'nova'");
                $st->execute([(int)$iid, $d['id']]);
                if ((int)$st->fetchColumn() !== 1) continue;
                foreach (['mkt_votes', 'mkt_log'] as $t) $pdo->prepare("DELETE FROM $t WHERE piece_id = ?")->execute([(int)$iid]);
                $pdo->prepare('DELETE FROM mkt_pieces WHERE id = ?')->execute([(int)$iid]);
            }
            return 'Reposto.';

        case 'deal_disclose':
            // «Pode ser divulgado?» (§16.3) volta ao que estava.
            $deal = record_get($pdo, (int)$d['id']);
            if (!$deal || !record_reach($pdo, $deal, PERM_EDIT)) throw new RuntimeException('Sem permissão.');
            $pdo->prepare('UPDATE deals SET disclose = ?, disclose_from = ? WHERE id = ?')->execute([$d['disclose'], $d['disclose_from'], $d['id']]);
            deal_log($pdo, (int)$d['id'], 'disclose', ['note' => 'Anulado']);
            return 'Reposto.';

        case 'budget_line':
            // Tirar uma linha do orçamento: volta tal como estava (preço copiado incluído).
            require_once __DIR__ . '/budget.php';
            require_perm('orcamentacao', PERM_EDIT);
            $v = budget_version_get($pdo, (int)$d['version_id']);
            $sheet = $v ? budget_sheet_by_id($pdo, (int)$v['sheet_id']) : null;
            if (!$sheet || !budget_editable($sheet, $v, budget_versions($pdo, (int)$sheet['id']))) throw new RuntimeException($changed);
            $cols = array_keys($d);
            $pdo->prepare('INSERT INTO budget_lines (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($d));
            budget_recalc($pdo, (int)$v['id']);
            budget_log($pdo, (int)$sheet['id'], 'b_line', 'v' . $v['num'] . ' · reposta: ' . $d['label'] . ' (anulado)');
            return 'Linha reposta.';

        case 'budget_version':
            // Uma versão acabada de criar (nova ou de um modelo) sai, se ninguém mexeu nela.
            require_once __DIR__ . '/budget.php';
            require_perm('orcamentacao', PERM_EDIT);
            $v = budget_version_get($pdo, (int)$d['version']);
            $sheet = $v ? budget_sheet_by_id($pdo, (int)$v['sheet_id']) : null;
            $versions = $sheet ? budget_versions($pdo, (int)$sheet['id']) : [];
            if (!$v || (int)end($versions)['id'] !== (int)$v['id'] || (int)$sheet['accepted_version_id'] === (int)$v['id']
                || (string)$v['updated_at'] !== (string)$d['updated']) throw new RuntimeException($changed);
            $pdo->prepare('DELETE FROM budget_versions WHERE id = ?')->execute([$v['id']]);
            budget_log($pdo, (int)$sheet['id'], 'b_version', 'v' . $v['num'] . ' anulada');
            return 'v' . $v['num'] . ' anulada.';

        case 'budget_fill':
            // Um modelo aplicado numa versão vazia: a versão volta a ficar vazia.
            require_once __DIR__ . '/budget.php';
            require_perm('orcamentacao', PERM_EDIT);
            $v = budget_version_get($pdo, (int)$d['version']);
            $sheet = $v ? budget_sheet_by_id($pdo, (int)$v['sheet_id']) : null;
            if (!$sheet || !budget_editable($sheet, $v, budget_versions($pdo, (int)$sheet['id']))) throw new RuntimeException($changed);
            $pdo->prepare('DELETE FROM budget_lines WHERE version_id = ?')->execute([$v['id']]);
            $p = $d['params'];
            $pdo->prepare('UPDATE budget_versions SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($p))) . ' WHERE id = ?')->execute([...array_values($p), $v['id']]);
            budget_recalc($pdo, (int)$v['id']);
            budget_log($pdo, (int)$sheet['id'], 'b_model', 'v' . $v['num'] . ' · modelo anulado');
            return 'Modelo anulado.';

        case 'budget_accept':
            // Aceitar uma versão: volta a aceite de antes (ou nenhuma) e o valor do negócio.
            require_once __DIR__ . '/budget.php';
            require_perm('orcamentacao', PERM_EDIT);
            $sheet = budget_sheet_by_id($pdo, (int)$d['sheet']);
            if (!$sheet || (int)$sheet['accepted_version_id'] !== (int)$d['version'] || $sheet['closed_at']) throw new RuntimeException($changed);
            $pdo->prepare('UPDATE budget_sheets SET accepted_version_id = ? WHERE id = ?')->execute([$d['prev'], $sheet['id']]);
            $pdo->prepare('UPDATE budget_versions SET accepted_at = NULL, accepted_by = NULL, occupancy = ? WHERE id = ?')->execute([$d['occupancy'], $d['version']]);
            if (array_key_exists('opt', $d)) $pdo->prepare('UPDATE budget_versions SET accepted_opt = ? WHERE id = ?')->execute([$d['opt'], $d['version']]);
            $pdo->prepare('UPDATE deals SET value_estimate = ? WHERE id = ?')->execute([$d['value'], $d['deal']]);
            budget_recalc($pdo, (int)$d['version']);
            budget_log($pdo, (int)$sheet['id'], 'b_accept', 'Aceitação anulada');
            return 'Aceitação anulada.';

        case 'budget_tpl':
            // Um modelo apagado volta.
            require_perm('orcamentacao', PERM_EDIT);
            $cols = ['id', 'name', 'data', 'created_by', 'created_at'];
            $pdo->prepare('INSERT INTO budget_templates (' . implode(',', $cols) . ') VALUES (?,?,?,?,?)')->execute(array_map(fn($c) => $d[$c], $cols));
            return 'Modelo reposto.';

        case 'team':
            // Equipa (§17): conta, cargos, decisão de ausência, posições por preencher.
            require_once __DIR__ . '/team.php';
            return team_undo_apply($pdo, $d);

        case 'mkt':
            // Marketing (§16): triagem, etapas, revisão, agendar, publicar, textos — a peça volta ao que era.
            require_once __DIR__ . '/marketing.php';
            return mkt_undo_apply($pdo, $d);

        case 'auto_rule':
            // Uma automação eliminada em Definições › Funis e automações (§19.7): volta igual.
            if (!is_admin()) throw new RuntimeException('Sem permissão.');
            if (!(int)$pdo->query('SELECT COUNT(*) FROM funnel_stages WHERE id = ' . (int)$d['stage_id'])->fetchColumn()) throw new RuntimeException($changed);
            if ((int)$pdo->query('SELECT COUNT(*) FROM stage_automations WHERE id = ' . (int)$d['id'])->fetchColumn()) throw new RuntimeException($changed);
            $cols = array_keys($d);
            $pdo->prepare('INSERT INTO stage_automations (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($d));
            return 'Automação reposta.';

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
