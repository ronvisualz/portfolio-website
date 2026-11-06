<?php
/**
 * topper. admin — Pipeline v2: tarefas, deduplicação e regras de dois níveis
 *
 * Regras (D10, D11). As tarefas de frente nascem das regras da sub-etapa da frente (gatilhos f_enter — que apanha
 * também o 'enter' de uma sub-etapa —, f_ready, dep_met, f_stale, rounds, date) e ficam na sub-etapa, com a frente e o
 * ciclo dela. As do negócio nascem das regras da etapa do negócio (a do funil principal) e das da etapa com o mesmo
 * papel (ou tipo) nos funis das outras frentes vivas; as de fatura e pagamento só do funil principal. Numa tarefa de
 * negócio a etapa gravada é sempre a do negócio (deals.stage_id), mesmo quando a regra vem de outro funil: a etapa da
 * regra só serve para a ler. Ações: criar tarefa (com doc_kind = pedir documento) ou notificar (um aviso, sem tarefa).
 * Responsáveis: os da v1 e o chefe do serviço (svc_head); nunca o superadmin nem uma conta desativada (pipe2_user_ok).
 *
 * Deduplicação (D12, §34.11 T2). dedup_key = sha1(título normalizado | negócio | âmbito | ciclo), com âmbito
 * 'L<frente>:S<sub-etapa>' nas de frente e '' nas do negócio. Antes de criar: (1) a regra já criou tarefa para este
 * negócio, frente e ciclo (qualquer estado) → não cria; (2) há uma tarefa com a mesma chave, aberta ou feita → não
 * cria e, se a regra é obrigatória, a existente passa a obrigatória; (3) só nas do negócio: há tarefas das frentes,
 * abertas ou feitas, com o mesmo título normalizado e o mesmo ciclo → não cria e, se a regra é obrigatória, todas
 * ficam com gate_stage_id = etapa do negócio e contam para o gate dela (a feita como cumprida, a aberta por fazer).
 * Assim uma reentrada na Entrega não duplica. O SELECT antes do INSERT não tem UNIQUE por trás: o motor chama estas
 * funções dentro do lock do negócio (pipe2_engine, pipe2_deal).
 *
 * Gates. pipe2_step_gate: as obrigatórias da frente na sub-etapa e no ciclo atual, mais as unidades (D7).
 * pipe2_deal_gate: os itens do papel da etapa (deal_gate, sem as tarefas), as obrigatórias do negócio na etapa e as
 * tarefas de frente que contam para ela (gate_stage_id).
 *
 * Caminho v1 (regra de ouro). Os ganchos nos ficheiros existentes só mudam alguma coisa num negócio v2:
 *   · flow_task_from_rule → pipe2_task_from_rule só num negócio v2;
 *   · task_repeat_next → pipe2_repeat_extra devolve [] numa tarefa que não seja de uma frente v2 (a v1 fica igual);
 *   · deal_flow.php 'people' → pipe2_reassign_scope_sql dá '' num v1;
 *   · team_reassign → pipe2_team_reassign só mexe em negócios v2 (sem pipe2_schema_ready, zeros sem consultas);
 *   · pipe2_task_delete_human regista também os apagamentos v1 (D19), mas o INSERT é guardado (pipe2_schema_ready e
 *     a tabela) e fica em try/catch: o DELETE corre sempre, como hoje.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_fronts.php';     // pipe2_deal_is_v2, pipe2_fronts, pipe2_front_get, pipe2_user_ok, pipe2_service_head, pipe2_line_is_v2, PIPE2_FRONT_LIVE
require_once __DIR__ . '/pipe2_notify.php';     // pipe2_notice_add
require_once __DIR__ . '/pipe2_deps.php';       // pipe2_front_delivery, pipe2_dep_service
require_once __DIR__ . '/pipeline_flow.php';    // flow_due, flow_title, flow_rule_parts, flow_created, deal_gate
require_once __DIR__ . '/tasks.php';            // task_next_position, task_checklist_parse
require_once __DIR__ . '/leads.php';            // member_for_user
require_once __DIR__ . '/deals.php';            // deal_log, today_lisbon

/** Os papéis das etapas do negócio que têm igual noutro funil (pipe2_stage_match). */
const PIPE2_MATCH_ROLES = ['proposal', 'feedback', 'exec', 'delivery'];
const PIPE2_MATCH_KINDS = ['xray', 'kickoff', 'done'];

/** Os pedidos de documento que só existem no negócio (frente principal, §34.11 T2). */
const PIPE2_DEAL_DOCS = ['invoice', 'payment'];

// ── chave (D12) ─────────────────────────────────────────────

/** O título normalizado: minúsculas, espaços seguidos reduzidos a um, sem espaços nas pontas. */
function pipe2_title_norm(string $title): string {
    return trim((string)preg_replace('/\s+/u', ' ', mb_strtolower($title, 'UTF-8')));
}

/** A chave de deduplicação (40 caracteres). $scope: 'L<frente>:S<sub-etapa>' ou '' (negócio); $cycle: '' ou AAAA-MM. */
function pipe2_dedup_key(string $title, int $dealId, string $scope, string $cycle): string {
    return sha1(pipe2_title_norm($title) . '|' . $dealId . '|' . $scope . '|' . $cycle);
}

/** O âmbito de uma tarefa: 'L<frente>:S<sub-etapa>' numa de frente, '' numa do negócio. */
function pipe2_task_scope(?int $lineId, ?int $stageId): string {
    return $lineId ? 'L' . $lineId . ':S' . (int)$stageId : '';
}

// ── o negócio visto pela frente ─────────────────────────────

/**
 * O negócio visto por uma frente v2 (como xsell_vdeal, sem depender de sync_mode): o funil, a sub-etapa, a execução
 * (a da frente, senão a do negócio) e a entrega da frente (pipe2_front_delivery); '_line', '_main', '_service' (nome
 * do serviço) e '_cycle' (o da frente). Sem frente: o negócio, '_line' null e '_cycle' = $cycle ?? ''. Sempre '_v2'.
 */
function pipe2_vdeal(array $deal, ?array $line, ?string $cycle = null): array {
    $v = $deal;
    $v['_v2'] = true;
    if ($line === null) {
        $v['_line'] = null;
        $v['_main'] = false;
        $v['_service'] = '';
        $v['_cycle'] = $cycle ?? '';
        return $v;
    }
    $v['funnel_id'] = (int)$line['funnel_id'];
    $v['stage_id'] = ($line['stage_id'] ?? null) !== null ? (int)$line['stage_id'] : null;
    $exec = $line['exec_owner_id'] ?? null;
    $v['exec_owner_id'] = $exec ? (int)$exec : (($deal['exec_owner_id'] ?? null) !== null ? (int)$deal['exec_owner_id'] : null);
    $v['delivery_date'] = pipe2_front_delivery($deal, $line);
    $v['_line'] = (int)$line['id'];
    $v['_main'] = (int)($line['is_main'] ?? 0) === 1;
    $v['_service'] = pipe2_dep_service($line);
    $v['_cycle'] = $cycle ?? (string)($line['cycle_ym'] ?? '');
    return $v;
}

// ── regras (D10, D11) ───────────────────────────────────────

/**
 * A etapa do negócio «igual» noutro funil: pelo papel (proposal, feedback, exec, delivery), senão pelo tipo (xray,
 * kickoff, done), senão (comercial sem papel) pelo nome. Só etapas vivas do negócio; sem cache (os funis mudam a meio
 * de um pedido nos testes e na conversão). Uma sub-etapa nunca tem igual.
 */
function pipe2_stage_match(PDO $pdo, array $stage, int $funnelId): ?array {
    if (($stage['level'] ?? 'deal') !== 'deal') return null;
    $role = (string)($stage['role'] ?? '');
    $kind = (string)($stage['kind'] ?? '');
    $base = "SELECT * FROM funnel_stages WHERE funnel_id = ? AND level = 'deal' AND retired_at IS NULL";
    if (in_array($role, PIPE2_MATCH_ROLES, true)) {
        $st = $pdo->prepare($base . ' AND role = ? ORDER BY position, id LIMIT 1');
        $st->execute([$funnelId, $role]);
    } elseif (in_array($kind, PIPE2_MATCH_KINDS, true)) {
        $st = $pdo->prepare($base . ' AND kind = ? ORDER BY position, id LIMIT 1');
        $st->execute([$funnelId, $kind]);
    } elseif ($kind === 'commercial' && $role === '') {
        $st = $pdo->prepare($base . " AND kind = 'commercial' AND role = '' ORDER BY position, id");
        $st->execute([$funnelId]);
        $name = pipe2_title_norm((string)($stage['name'] ?? ''));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) if (pipe2_title_norm((string)$s['name']) === $name) return $s;
        return null;
    } else {
        return null;
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    return $row ?: null;
}

/** As regras ativas de uma etapa com um destes gatilhos, pela ordem. */
function pipe2_stage_rules(PDO $pdo, int $stageId, array $trigs): array {
    $in = implode(',', array_fill(0, count($trigs), '?'));
    $st = $pdo->prepare("SELECT * FROM stage_automations WHERE stage_id = ? AND is_active = 1 AND trig IN ($in) ORDER BY position, id");
    $st->execute(array_merge([$stageId], array_values($trigs)));
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * As regras que correm numa etapa: [[regra, etapa da regra], …]. Com $line (âmbito da frente): as da sub-etapa (em
 * f_enter também as 'enter' da sub-etapa). Sem $line (âmbito do negócio): as da etapa do negócio e as da etapa igual
 * (pipe2_stage_match) nos funis das outras frentes vivas — também planned —, sem as de fatura e pagamento. A etapa da
 * regra só serve para ler a regra; a tarefa fica em $stage.
 */
function pipe2_rules_for(PDO $pdo, array $deal, array $stage, string $trig, ?array $line = null): array {
    $trigs = [$trig];
    if ($trig === 'f_enter' && (($stage['level'] ?? '') === 'front' || $line !== null)) $trigs[] = 'enter';
    $out = [];
    foreach (pipe2_stage_rules($pdo, (int)$stage['id'], $trigs) as $r) $out[] = [$r, $stage];
    if ($line !== null || empty($deal['id'])) return $out;
    $seen = [(int)($deal['funnel_id'] ?? 0) => true];
    foreach (pipe2_fronts($pdo, (int)$deal['id']) as $l) {
        $fid = (int)$l['funnel_id'];
        if (isset($seen[$fid])) continue;
        $seen[$fid] = true;
        $ms = pipe2_stage_match($pdo, $stage, $fid);
        if (!$ms) continue;
        foreach (pipe2_stage_rules($pdo, (int)$ms['id'], $trigs) as $r) {
            if (in_array((string)$r['doc_kind'], PIPE2_DEAL_DOCS, true)) continue;
            $out[] = [$r, $ms];
        }
    }
    return $out;
}

// ── responsáveis (D11) ──────────────────────────────────────

/**
 * Quem fica com a tarefa (ou o aviso) de uma regra: comercial (owner, commercial, both), execução (numa tarefa de
 * frente, o responsável da frente; senão a do negócio), pessoa, chefe do departamento, chefe do serviço (o
 * responsável do departamento do funil da frente, ou do negócio, senão o do departamento-pai). Sempre por
 * pipe2_user_ok: nunca o superadmin nem uma conta desativada (null = Por atribuir).
 */
function pipe2_assignee(PDO $pdo, array $r, array $vdeal): ?int {
    switch ((string)($r['assign_to'] ?? '')) {
        case 'owner': case 'commercial': case 'both':
            return pipe2_user_ok($pdo, $vdeal['owner_id'] ?? null);
        case 'execution':
            return pipe2_user_ok($pdo, $vdeal['exec_owner_id'] ?? null);
        case 'user':
            return pipe2_user_ok($pdo, $r['assign_ref'] ?? null);
        case 'dept':
            if (empty($r['assign_ref'])) return null;
            $st = $pdo->prepare('SELECT head_user_id FROM departments WHERE id = ?');
            $st->execute([(int)$r['assign_ref']]);
            return pipe2_user_ok($pdo, $st->fetchColumn());
        case 'svc_head':
            return empty($vdeal['funnel_id']) ? null : pipe2_service_head($pdo, (int)$vdeal['funnel_id']);
    }
    return null;
}

/** «Quando …» de uma regra, para a descrição da tarefa e do aviso (pt-PT; os gatilhos da v1 pelo flow_rule_parts). */
function pipe2_rule_when(PDO $pdo, array $r, string $stageName, bool $front): string {
    $n = (int)($r['trig_n'] ?? 0);
    return match ((string)$r['trig']) {
        'f_enter'   => 'a frente entra em ' . $stageName,
        'enter'     => $front ? 'a frente entra em ' . $stageName : flow_rule_parts($pdo, $r, $stageName)['when'],
        'f_ready'   => 'a frente fica Pronta',
        'all_ready' => 'todas as frentes ficam Prontas',
        'dep_met'   => 'uma dependência da frente fica cumprida (' . $stageName . ')',
        'f_stale'   => 'a frente está parada' . ($n > 0 ? ' há ' . $n . ' dias' : '') . ' (' . $stageName . ')',
        'rounds'    => 'as rondas de revisão passam as incluídas',
        'fee_day'   => 'avença mensal: no dia ' . max(1, $n) . ' de cada mês',
        default     => flow_rule_parts($pdo, $r, $stageName)['when'],
    };
}

// ── criar (D12) ─────────────────────────────────────────────

/**
 * A tarefa de uma regra num negócio v2 (D12). $vdeal: pipe2_vdeal (ou o negócio com '_v2', vindo do gancho de
 * flow_task_from_rule); $stage: onde a tarefa fica (a etapa do negócio nas de negócio, a sub-etapa nas de frente).
 * Notificar → um aviso (kind 'rule', chave = a da tarefa) e null. Fatura ou pagamento numa frente → null. Devolve o id
 * ou null (já existia ou não se cria).
 */
function pipe2_task_from_rule(PDO $pdo, array $r, array $vdeal, ?array $stage, string $from, ?int $by): ?int {
    return pipe2_rule_apply($pdo, $r, $vdeal, $stage, $from, $by)['task'];
}

/** O mesmo que pipe2_task_from_rule, a devolver ['task' => ?id, 'notice' => ?id]. */
function pipe2_rule_apply(PDO $pdo, array $r, array $vdeal, ?array $stage, string $from, ?int $by): array {
    $out = ['task' => null, 'notice' => null];
    $dealId = (int)($vdeal['id'] ?? 0);
    if ($dealId <= 0) return $out;
    $lineId = !empty($vdeal['_line']) ? (int)$vdeal['_line'] : null;
    if ($lineId && !array_key_exists('_cycle', $vdeal)) {
        // veio de um caminho da v1 com a frente (xsell_vdeal): o ciclo é o da frente
        $vdeal['_cycle'] = (string)(pipe2_front_get($pdo, $lineId)['cycle_ym'] ?? '');
    }
    $cycle = (string)($vdeal['_cycle'] ?? '');
    if ((string)$r['trig'] === 'monthly') $cycle = substr($from, 0, 7);   // a regra mensal da v1: uma por mês
    $stageId = $stage ? (int)$stage['id'] : null;
    $docKind = (string)($r['doc_kind'] ?? '');
    if ($lineId && in_array($docKind, PIPE2_DEAL_DOCS, true)) return $out;
    $gate = (int)($r['is_gate'] ?? 0) === 1;
    $title = flow_title($pdo, (string)$r['title'], $vdeal, $stage);
    $key = pipe2_dedup_key($title, $dealId, pipe2_task_scope($lineId, $stageId), $cycle);
    $stageName = (string)($stage['name'] ?? '');

    if ((string)($r['action'] ?? 'task') === 'notify') {
        $desc = trim((string)($r['description'] ?? ''));
        $out['notice'] = pipe2_notice_add($pdo, [
            'deal_id' => $dealId, 'line_id' => $lineId, 'stage_id' => $stageId, 'cycle_ym' => $cycle, 'kind' => 'rule', 'rule_id' => (int)$r['id'],
            'user_id' => pipe2_assignee($pdo, $r, $vdeal), 'title' => $title,
            'body' => $desc !== '' ? $desc : 'Aviso da automação «' . pipe2_rule_when($pdo, $r, $stageName, $lineId !== null) . '».', 'nkey' => $key,
        ]);
        return $out;
    }

    // (1) a regra já criou tarefa para este negócio, frente e ciclo (qualquer estado)
    $st = $pdo->prepare('SELECT COUNT(*) FROM tasks WHERE deal_id = ? AND auto_rule_id = ? AND ' . ($lineId ? 'line_id = ?' : 'line_id IS NULL') . ' AND cycle_ym = ?');
    $st->execute($lineId ? [$dealId, (int)$r['id'], $lineId, $cycle] : [$dealId, (int)$r['id'], $cycle]);
    if ((int)$st->fetchColumn() > 0) return $out;

    // (2) a mesma chave (aberta ou feita): não cria; a regra obrigatória torna a existente obrigatória
    $st = $pdo->prepare('SELECT id, is_gate FROM tasks WHERE deal_id = ? AND dedup_key = ? ORDER BY id');
    $st->execute([$dealId, $key]);
    $same = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($same) {
        if ($gate && !array_filter($same, fn($t) => (int)$t['is_gate'] === 1)) {
            $pdo->prepare('UPDATE tasks SET is_gate = 1, updated_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int)$same[0]['id']]);
        }
        return $out;
    }

    // (3) só nas do negócio: a mesma tarefa, aberta ou feita, numa frente do negócio e no mesmo ciclo
    if (!$lineId) {
        $front = pipe2_front_twins($pdo, $dealId, $title, $cycle);
        if ($front) {
            if ($gate && $stageId) {
                $in = implode(',', array_map('intval', $front));
                $pdo->prepare("UPDATE tasks SET gate_stage_id = ?, updated_at = ? WHERE id IN ($in) AND (gate_stage_id IS NULL OR gate_stage_id <> ?)")
                    ->execute([$stageId, date('Y-m-d H:i:s'), $stageId]);
            }
            return $out;
        }
    }

    $uid = pipe2_assignee($pdo, $r, $vdeal);
    $member = $uid ? member_for_user($pdo, $uid) : null;
    [$due, $note] = flow_due($pdo, $r, $vdeal, $from);
    $parts = '';
    if ((string)$r['assign_to'] === 'both' && ($e = pipe2_user_ok($pdo, $vdeal['exec_owner_id'] ?? null)) && $e !== $uid) $parts = (string)$e;
    $desc = trim(trim((string)($r['description'] ?? '')) . ($note !== '' ? "\n\n" . $note : '')
               . "\n\nCriada pela automação «" . pipe2_rule_when($pdo, $r, $stageName, $lineId !== null) . '».'
               . ($gate ? ($lineId ? ' Obrigatória: tem de estar feita para a frente sair desta sub-etapa.'
                                   : ' Obrigatória: tem de estar feita para o negócio sair desta etapa.') : ''));
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO tasks (title, description, member_id, job_name, deal_id, due_date, kind, repeat_rule, priority, status, position,
                                      created_at, updated_at, created_by, checklist, participants, auto_rule_id, stage_id, is_gate, doc_kind,
                                      line_id, dedup_key, cycle_ym)
                   VALUES (?,?,?,\'\',?,?,?,?,?,\'todo\',?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$title, $desc, $member, $dealId, $due, (string)($r['kind'] ?? '') ?: 'task', (string)($r['repeat_rule'] ?? ''),
                   $gate ? 'alta' : 'media', task_next_position($pdo, $member), $now, $now, $by,
                   !empty($r['checklist']) ? task_checklist_parse((string)$r['checklist']) : null, $parts, (int)$r['id'],
                   $stageId, $gate ? 1 : 0, $docKind, $lineId, $key, $cycle]);
    $id = (int)$pdo->lastInsertId();
    flow_created([$id]);
    $out['task'] = $id;
    return $out;
}

/** As tarefas das frentes vivas do negócio, abertas ou feitas, com este título (normalizado) e este ciclo. */
function pipe2_front_twins(PDO $pdo, int $dealId, string $title, string $cycle): array {
    $st = $pdo->prepare("SELECT t.id, t.title FROM tasks t JOIN deal_lines l ON l.id = t.line_id
                          WHERE t.deal_id = ? AND l.deal_id = ? AND l.status <> 'merged' AND t.cycle_ym = ? ORDER BY t.id");
    $st->execute([$dealId, $dealId, $cycle]);
    $norm = pipe2_title_norm($title);
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) if (pipe2_title_norm((string)$t['title']) === $norm) $ids[] = (int)$t['id'];
    return $ids;
}

/**
 * Corre as regras de um gatilho numa etapa de um negócio v2: com $line, as da sub-etapa da frente (tarefas da
 * frente); sem $line, as da etapa do negócio e as iguais nos funis das outras frentes (tarefas do negócio, na etapa
 * do negócio). Uma linha 'auto' no histórico quando cria tarefas («Sistema» sem $by). Num v1 não faz nada.
 * Devolve ['tasks' => [ids], 'notices' => [ids]].
 */
function pipe2_run_rules(PDO $pdo, array $deal, array $stage, string $trig, ?array $line, ?int $by, ?string $from = null): array {
    $out = ['tasks' => [], 'notices' => []];
    if (empty($deal['id']) || !pipe2_deal_is_v2($pdo, $deal)) return $out;
    $from ??= today_lisbon();
    $v = pipe2_vdeal($deal, $line);
    foreach (pipe2_rules_for($pdo, $deal, $stage, $trig, $line) as [$r]) {
        $res = pipe2_rule_apply($pdo, $r, $v, $stage, $from, $by);
        if ($res['task'] !== null) $out['tasks'][] = $res['task'];
        if ($res['notice'] !== null) $out['notices'][] = $res['notice'];
    }
    $n = count($out['tasks']);
    if ($n) {
        deal_log($pdo, (int)$deal['id'], 'auto', ['note' => ($n === 1 ? '1 tarefa automática criada' : $n . ' tarefas automáticas criadas') . ' (' . $stage['name'] . ')',
                                                  'user_id' => $by, 'system' => $by === null]);
    }
    return $out;
}

// ── gates ───────────────────────────────────────────────────

/**
 * O gate de uma sub-etapa para uma frente (D7): as obrigatórias da frente nessa sub-etapa e no ciclo atual da frente
 * e as unidades (deal_line_steps do mesmo ciclo). complete = todas feitas e, com unidades previstas, feitas ≥
 * previstas; auto_ok = há pelo menos uma obrigatória ou unidades previstas (sem isso não avança sozinha, E3).
 */
function pipe2_step_gate(PDO $pdo, array $line, array $stage): array {
    $cycle = (string)($line['cycle_ym'] ?? '');
    $st = $pdo->prepare("SELECT id, title, status FROM tasks WHERE line_id = ? AND stage_id = ? AND is_gate = 1 AND cycle_ym = ? ORDER BY id");
    $st->execute([(int)$line['id'], (int)$stage['id'], $cycle]);
    $total = 0; $done = 0; $missing = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $total++;
        if ($t['status'] === 'done') $done++;
        else $missing[] = (string)$t['title'];
    }
    $st = $pdo->prepare('SELECT units_plan, units_done FROM deal_line_steps WHERE line_id = ? AND stage_id = ? AND cycle_ym = ? ORDER BY id LIMIT 1');
    $st->execute([(int)$line['id'], (int)$stage['id'], $cycle]);
    $step = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $st->closeCursor();
    $plan = $step && $step['units_plan'] !== null ? (int)$step['units_plan'] : null;
    $unitsDone = $step ? (int)$step['units_done'] : 0;
    $unitsOk = $plan === null || $plan <= 0 || $unitsDone >= $plan;
    return ['total' => $total, 'done' => $done, 'missing' => $missing, 'units_plan' => $plan, 'units_done' => $unitsDone,
            'complete' => $done === $total && $unitsOk, 'auto_ok' => $total > 0 || ($plan ?? 0) > 0];
}

/**
 * O gate de uma etapa do negócio v2, no formato de deal_gate: os itens do papel da etapa (sem as tarefas), as
 * obrigatórias do negócio nessa etapa (line_id NULL, ciclo '') e as tarefas de frente que contam para ela
 * (gate_stage_id: a feita conta como cumprida, a aberta por fazer).
 */
function pipe2_deal_gate(PDO $pdo, array $deal, array $stage): array {
    $items = array_values(array_filter(deal_gate($pdo, $deal, $stage), fn($i) => !array_key_exists('task', $i)));
    $st = $pdo->prepare("SELECT t.id, t.title, t.status, t.line_id, f.name AS funnel_name, f.service_code
                           FROM tasks t LEFT JOIN deal_lines l ON l.id = t.line_id LEFT JOIN funnels f ON f.id = l.funnel_id
                          WHERE t.deal_id = ? AND ((t.line_id IS NULL AND t.stage_id = ? AND t.is_gate = 1 AND t.cycle_ym = '') OR t.gate_stage_id = ?)
                          ORDER BY t.id");
    $st->execute([(int)$deal['id'], (int)$stage['id'], (int)$stage['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $items[] = ['label' => (string)$t['title'], 'ok' => $t['status'] === 'done',
                    'hint' => $t['line_id'] !== null ? 'Na frente ' . pipe2_dep_service($t) . '.' : '', 'task' => (int)$t['id']];
    }
    return $items;
}

// ── repetições (D18) ────────────────────────────────────────

/**
 * O que a próxima instância de uma série copia (o gancho em task_repeat_next). Numa tarefa que não seja de uma frente
 * v2 (ou sem as colunas da v2): [] — a v1 fica igual. Se a frente já saiu da sub-etapa da tarefa, mudou de ciclo, ficou
 * À espera (concluiu a sub-etapa) ou foi juntada: null (não nasce a seguinte; a série fecha). Senão as colunas a
 * copiar: line_id, stage_id, auto_rule_id, doc_kind, cycle_ym e is_gate 0 (nunca copia a obrigatória: a série conta
 * uma vez para o gate da sub-etapa).
 */
function pipe2_repeat_extra(PDO $pdo, array $t): ?array {
    if (empty($t['line_id']) || !pipe2_schema_ready($pdo)) return [];
    $line = pipe2_front_get($pdo, (int)$t['line_id']);
    if (!$line || !pipe2_line_is_v2($line)) return [];
    if (in_array((string)$line['status'], ['depwait', 'merged'], true)) return null;
    if ((int)($line['stage_id'] ?? 0) !== (int)($t['stage_id'] ?? 0)) return null;
    if ((string)($line['cycle_ym'] ?? '') !== (string)($t['cycle_ym'] ?? '')) return null;
    return ['line_id' => (int)$t['line_id'], 'stage_id' => (int)$t['stage_id'],
            'auto_rule_id' => ($t['auto_rule_id'] ?? null) !== null ? (int)$t['auto_rule_id'] : null,
            'doc_kind' => (string)($t['doc_kind'] ?? ''), 'cycle_ym' => (string)($t['cycle_ym'] ?? ''), 'is_gate' => 0];
}

// ── apagamentos (D19) ───────────────────────────────────────

/**
 * Um apagamento humano (a Agenda): regista-o em task_deletions (também nas tarefas v1) e apaga a tarefa e o histórico
 * dela, como hoje. O registo só se tenta com as colunas da v2 e a tabela, num try/catch: o DELETE corre sempre.
 */
function pipe2_task_delete_human(PDO $pdo, array $t, ?int $by): void {
    $id = (int)($t['id'] ?? 0);
    if ($id <= 0) return;
    if (pipe2_schema_ready($pdo) && db_has_table($pdo, 'task_deletions')) {
        try {
            $int = fn($v) => $v !== null && $v !== '' ? (int)$v : null;
            $pdo->prepare('INSERT INTO task_deletions (task_id, deal_id, line_id, stage_id, auto_rule_id, deleted_by, title, task_created_at, deleted_at)
                           VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$id, $int($t['deal_id'] ?? null), $int($t['line_id'] ?? null), $int($t['stage_id'] ?? null), $int($t['auto_rule_id'] ?? null),
                           $by, mb_substr((string)($t['title'] ?? ''), 0, 200), ($t['created_at'] ?? null) ?: null, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            error_log('[topper] pipe2 task_deletions: ' . $e->getMessage());
        }
    }
    $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
    if (db_has_table($pdo, 'task_log')) $pdo->prepare('DELETE FROM task_log WHERE task_id = ?')->execute([$id]);
}

// ── reatribuição (D20) ──────────────────────────────────────

/** O filtro da reatribuição em deal_flow.php 'people': num negócio v2 só as tarefas do negócio; num v1, nenhum. */
function pipe2_reassign_scope_sql(PDO $pdo, array $deal): string {
    return pipe2_deal_is_v2($pdo, $deal) ? ' AND line_id IS NULL' : '';
}

/**
 * A parte v2 de team_reassign (o trabalho aberto de quem sai): deals.exec_owner_id dos negócios v2 abertos (lead,
 * pipeline, production, como o owner_id da v1), deal_lines.exec_owner_id das frentes vivas desses negócios e
 * deal_line_execs.user_id delas. Sem as colunas da v2: zeros, sem consultas. Devolve as contagens.
 */
function pipe2_team_reassign(PDO $pdo, int $from, int $to): array {
    $out = ['deals' => 0, 'lines' => 0, 'execs' => 0];
    if ($from <= 0 || $to <= 0 || $from === $to || !pipe2_schema_ready($pdo)) return $out;
    $open = "SELECT d.id FROM deals d WHERE d.phase IN ('lead','pipeline','production') AND d.funnel_id IN (" . pipe2_v2_funnels_sql() . ')';
    $st = $pdo->prepare("UPDATE deals SET exec_owner_id = ? WHERE exec_owner_id = ? AND phase IN ('lead','pipeline','production') AND funnel_id IN (" . pipe2_v2_funnels_sql() . ')');
    $st->execute([$to, $from]);
    $out['deals'] = $st->rowCount();
    if (db_has_table($pdo, 'deal_line_execs')) {
        $st = $pdo->prepare("UPDATE deal_line_execs SET user_id = ? WHERE user_id = ?
                                AND line_id IN (SELECT l.id FROM deal_lines l WHERE l.status <> 'merged' AND l.deal_id IN ($open))");
        $st->execute([$to, $from]);
        $out['execs'] = $st->rowCount();
    }
    $st = $pdo->prepare("UPDATE deal_lines SET exec_owner_id = ? WHERE exec_owner_id = ? AND status <> 'merged' AND deal_id IN ($open)");
    $st->execute([$to, $from]);
    $out['lines'] = $st->rowCount();
    if ($out['lines']) $GLOBALS['XSELL_FRESH'] = true;
    return $out;
}
