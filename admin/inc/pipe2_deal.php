<?php
/**
 * topper. admin — Pipeline v2: motor do negócio
 *
 * Kickoff (M4, D4, D29). Ao entrar no Kickoff (flow_on_enter → pipe2_on_deal_enter) nascem as frentes 'queued', uma
 * linha de deal_line_steps por sub-etapa, as ligações do modelo da categoria, a categoria (gravada uma vez) e os
 * valores das frentes. Tudo dentro do lock do negócio e de uma transação com a linha do negócio bloqueada (FOR
 * UPDATE), relendo o negócio e as frentes depois do lock: dois deal_move para o Kickoff ao mesmo tempo (o deal_move da
 * v1 não é condicional) criam tudo uma vez e o UNIQUE deal_id, funnel_id de deal_lines nunca rebenta.
 *
 * Movimentos do sistema (D6, D9, D16). O negócio move-se sozinho, como «Sistema» (deal_move com 'system' e uma nota,
 * user_id NULL mesmo com sessão; a nota também impede o aviso «Gate de produção passado»):
 *   · Kickoff → Em execução quando uma frente entra numa sub-etapa; com o gate do Kickoff cumprido e pelo menos uma
 *     obrigatória, o sistema começa as frentes Por começar (pipe2_front_start); sem obrigatórias, começa-as um humano;
 *   · Em execução → Entrega quando todas as frentes vivas estão concluídas (pontual Pronta; avença terminada e
 *     Pronta), depois das regras all_ready; senão, com alguma concluída, «Pronto para a Entrega — falta: …»;
 *   · Entrega → Em execução quando uma frente sai de Pronto;
 *   · Entrega → Concluído com o gate da Entrega cumprido, as frentes concluídas e, entre as obrigatórias, a aprovação
 *     final e o pagamento final (pipe2_close_auto_missing); sem elas só um humano conclui (deal_move de sempre, que
 *     passa o gate). O Concluído automático marca delivered_at pela tarefa «Enviar entregáveis finais…».
 * Um passo de cada vez por pedido (pipe2_once('deal:<id>')).
 *
 * Concorrência (Review Focus 6). deal_move não é um UPDATE condicional: pipe2_deal_reevaluate decide só sobre o que
 * relê depois de pipe2_lock_deal, e pipe2_deal_system_move não move se deals.stage_id já não é o esperado ('stale').
 * As regras all_ready correm só no pedido que vai mover para a Entrega (dentro do lock, logo antes); as de fecho correm
 * pelo flow_on_enter desse movimento. Com uma transação aberta (de quem chamou) nada se move: 'deferred', e o relógio
 * apanha-o (Review Focus 1).
 *
 * Nunca decide por permissões nem pela sessão (o cron e o portal não a têm). Nunca lança para quem chamou a
 * reavaliação: os erros ficam no error_log («[topper] pipe2 …»). Mensagens para quem chamou em pipe2_note() (pt-PT).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_fronts.php';    // pipe2_deal_is_v2, pipe2_fronts, pipe2_deal_stage, pipe2_kickoff_fronts, pipe2_store_values, avença
require_once __DIR__ . '/pipe2_category.php';  // pipe2_category_store
require_once __DIR__ . '/pipe2_deps.php';      // pipe2_deps_instantiate, pipe2_deps_into, pipe2_dep_service
require_once __DIR__ . '/pipe2_tasks.php';     // pipe2_run_rules, pipe2_deal_gate, pipe2_title_norm
require_once __DIR__ . '/pipe2_engine.php';    // pipe2_lock_deal, pipe2_once, pipe2_moves, pipe2_note, pipe2_front_start, pipe2_next_stage
require_once __DIR__ . '/pipeline.php';        // deal_get, stage_get, deal_move, funnel_stages, phase_for_kind
require_once __DIR__ . '/pipeline_flow.php';   // GateBlocked, flow_created
require_once __DIR__ . '/deals.php';           // deal_log, today_lisbon
require_once __DIR__ . '/metrics.php';         // metrics_ready

const PIPE2_NOTE_STARTED  = 'Uma frente começou';
const PIPE2_NOTE_ALLREADY = 'Todas as frentes prontas';
const PIPE2_NOTE_AUTODONE = 'Concluído automático: aprovação final e pagamento final validados';

// ── o Kickoff ───────────────────────────────────────────────

/**
 * O negócio v2 entrou numa etapa (o ramo v2 de flow_on_enter). Tudo dentro do lock do negócio, relendo-o depois do
 * lock (se entretanto já saiu da etapa, não faz nada): no Kickoff, pipe2_kickoff; depois as regras 'enter' da etapa
 * (a do funil do negócio e as iguais nos funis das outras frentes, D10); no Concluído, as oportunidades de
 * crosselling dos funis das frentes vivas. Devolve quantas tarefas criou.
 */
function pipe2_on_deal_enter(PDO $pdo, array $deal, array $stage, ?int $by): int {
    $dealId = (int)($deal['id'] ?? 0);
    if ($dealId <= 0 || empty($stage['id'])) return 0;
    pipe2_lock_deal($pdo, $dealId);
    try {
        $deal = deal_get($pdo, $dealId);
        if (!$deal || !pipe2_deal_is_v2($pdo, $deal) || (int)$deal['stage_id'] !== (int)$stage['id']) return 0;
        $stage = stage_get($pdo, (int)$stage['id']) ?? $stage;
        if ($stage['kind'] === 'kickoff') {
            pipe2_kickoff($pdo, $deal, $by);
            $deal = deal_get($pdo, $dealId) ?? $deal;
        }
        $res = pipe2_run_rules($pdo, $deal, $stage, 'enter', null, $by);
        if ($stage['kind'] === 'done') {
            require_once __DIR__ . '/xsell.php';
            $funnels = array_values(array_unique(array_map(fn($l) => (int)$l['funnel_id'], pipe2_fronts($pdo, $dealId))));
            xsell_after_delivery($pdo, $deal, $funnels ?: [(int)$deal['funnel_id']]);
        }
        return count($res['tasks']);
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/**
 * As frentes do Kickoff (Review Focus 7), idempotente também em concorrência: dentro do lock do negócio, numa
 * transação própria (ou na de quem chamou) com a linha do negócio bloqueada (SELECT … FOR UPDATE; sem FOR UPDATE em
 * SQLite), relê o negócio e as frentes — o $deal recebido só dá o id — e, se ainda está no Kickoff (fase production),
 * cria só o que falta: pipe2_kickoff_fronts, a categoria (uma vez), as ligações do modelo e os valores. Commit da
 * própria antes de soltar o lock; num erro, rollback e relança. Não entra em pipe2_moves (o «Anular» do Kickoff usa
 * o retrato do negócio). Devolve ['created' => [frentes], 'queued' => [frentes], 'deps' => [ligações], 'category'].
 */
function pipe2_kickoff(PDO $pdo, array $deal, ?int $by = null): array {
    $out = ['created' => [], 'queued' => [], 'deps' => [], 'category' => ''];
    $dealId = (int)($deal['id'] ?? 0);
    if ($dealId <= 0) return $out;
    pipe2_lock_deal($pdo, $dealId);
    $own = false;
    try {
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        $st = $pdo->prepare('SELECT id FROM deals WHERE id = ?' . (db_is_sqlite($pdo) ? '' : ' FOR UPDATE'));
        $st->execute([$dealId]);
        $st->fetchAll();
        $fresh = deal_get($pdo, $dealId);
        $kick = $fresh && !empty($fresh['funnel_id']) ? pipe2_deal_stage($pdo, (int)$fresh['funnel_id'], 'kickoff') : null;
        if ($fresh && $kick && $fresh['phase'] === 'production' && (int)$fresh['stage_id'] === (int)$kick['id'] && pipe2_deal_is_v2($pdo, $fresh)) {
            $deps0 = pipe2_max_id($pdo, 'deal_line_deps', $dealId);
            $r = pipe2_kickoff_fronts($pdo, $fresh, $by);
            $out['created'] = $r['created'];
            $out['queued'] = $r['queued'];
            $out['category'] = pipe2_category_store($pdo, $fresh, $by);
            pipe2_deps_instantiate($pdo, deal_get($pdo, $dealId) ?? $fresh, $by);
            $out['deps'] = pipe2_ids_after($pdo, 'deal_line_deps', $dealId, $deps0);
            pipe2_store_values($pdo, $fresh);
        }
        if ($own) $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

// ── o que falta ─────────────────────────────────────────────

/** A frente está concluída (D16): pontual, Pronta; avença, terminada (fim do período ou cancelada) e Pronta. */
function pipe2_front_concluded(array $line, string $today): bool {
    if (($line['status'] ?? '') !== 'ready') return false;
    return !pipe2_line_is_monthly($line) || pipe2_billing_ended($line, $today);
}

/**
 * As frentes vivas por concluir, para as mensagens: «<Serviço> (<sub-etapa>)», «<Serviço> (à espera de <A>)»,
 * «<Serviço> (por começar)», «<Serviço> (avença até dd/mm/aaaa)». Pela ordem das frentes (a principal primeiro).
 */
function pipe2_fronts_missing(PDO $pdo, array $deal, string $today): array {
    if (empty($deal['id'])) return [];
    $out = [];
    foreach (pipe2_fronts($pdo, (int)$deal['id']) as $l) {
        if (pipe2_front_concluded($l, $today)) continue;
        $svc = pipe2_dep_service($l);
        $status = (string)$l['status'];
        if (in_array($status, ['planned', 'queued'], true)) {
            $out[] = $svc . ' (por começar)';
        } elseif ($status === 'depwait') {
            $next = pipe2_next_stage($pdo, $l);
            $from = $next ? array_values(array_unique(array_map(fn($d) => (string)$d['from_service'], pipe2_deps_into($pdo, (int)$l['id'], (int)$next['id'])))) : [];
            $out[] = $svc . ($from ? ' (à espera de ' . implode(', ', $from) . ')' : ' (à espera)');
        } elseif (pipe2_line_is_monthly($l) && !pipe2_billing_ended($l, $today)) {
            $end = (string)($l['billing_end'] ?? '');
            if ($end === '' && !empty($l['started_at'])) $end = pipe2_billing_period($l, (string)$l['started_at'])['end'];
            $out[] = $svc . ($end !== '' ? ' (avença até ' . date('d/m/Y', strtotime($end)) . ')' : ' (avença)');
        } else {
            $out[] = $svc . ' (' . ((string)($l['stage_name'] ?? '') !== '' ? (string)$l['stage_name'] : (PIPE2_LINE_STATUS[$status] ?? $status)) . ')';
        }
    }
    return $out;
}

/** O que falta para concluir (E2): as obrigatórias da Entrega por cumprir (também as de gate_stage_id) e as frentes por concluir. */
function pipe2_close_missing(PDO $pdo, array $deal): array {
    if (empty($deal['id']) || empty($deal['funnel_id'])) return [];
    $missing = [];
    $deliv = pipe2_deal_stage($pdo, (int)$deal['funnel_id'], 'delivery');
    if ($deliv) {
        foreach (pipe2_deal_gate($pdo, $deal, $deliv) as $i) if (!$i['ok']) $missing[] = (string)$i['label'];
    }
    return array_merge($missing, pipe2_fronts_missing($pdo, $deal, today_lisbon()));
}

/**
 * O que falta para o Concluído poder ser do sistema (D16): as duas obrigatórias da §34.9 entre as do gate da Entrega
 * (do negócio na Entrega, ou com gate_stage_id = Entrega), feitas ou não — ['Aprovação final'] se nenhuma tem o título
 * normalizado a começar por «aprovação final», ['Pagamento final'] se nenhuma tem doc_kind 'payment'. [] = pode.
 */
function pipe2_close_auto_missing(PDO $pdo, array $deal): array {
    $deliv = !empty($deal['funnel_id']) ? pipe2_deal_stage($pdo, (int)$deal['funnel_id'], 'delivery') : null;
    if (!$deliv || empty($deal['id'])) return ['Aprovação final', 'Pagamento final'];
    $st = $pdo->prepare("SELECT title, doc_kind FROM tasks
                          WHERE deal_id = ? AND ((line_id IS NULL AND stage_id = ? AND is_gate = 1 AND cycle_ym = '') OR gate_stage_id = ?)");
    $st->execute([(int)$deal['id'], (int)$deliv['id'], (int)$deliv['id']]);
    $approval = false;
    $payment = false;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if (str_starts_with(pipe2_title_norm((string)$t['title']), 'aprovação final')) $approval = true;
        if ((string)$t['doc_kind'] === 'payment') $payment = true;
    }
    $out = [];
    if (!$approval) $out[] = 'Aprovação final';
    if (!$payment) $out[] = 'Pagamento final';
    return $out;
}

/**
 * O ramo v2 de flow_gate_check: o que falta para passar de $from a $to. Para trás, nada; para a frente, uma etapa de
 * cada vez (etapas vivas do negócio no funil dele). Em execução → Entrega: as frentes por concluir; Entrega →
 * Concluído: pipe2_close_missing; as outras (Kickoff → Em execução incluído): o gate da etapa de origem.
 */
function pipe2_gate_missing(PDO $pdo, array $deal, array $from, array $to): array {
    $order = array_values(funnel_stages($pdo, (int)$deal['funnel_id']));
    $ids = array_map(fn($s) => (int)$s['id'], $order);
    $i = array_search((int)$from['id'], $ids, true);
    $j = array_search((int)$to['id'], $ids, true);
    if ($i === false || $j === false || $j <= $i) return [];
    $role = (string)($from['role'] ?? '');
    if ($role === 'exec') {
        $missing = pipe2_fronts_missing($pdo, $deal, today_lisbon());
    } elseif ($role === 'delivery') {
        $missing = pipe2_close_missing($pdo, $deal);
    } else {
        $missing = array_values(array_map(fn($x) => (string)$x['label'], array_filter(pipe2_deal_gate($pdo, $deal, $from), fn($x) => !$x['ok'])));
    }
    if ($j > $i + 1) $missing[] = 'avançar uma etapa de cada vez (salta ' . implode(', ', array_map(fn($s) => '«' . $s['name'] . '»', array_slice($order, $i + 1, $j - $i - 1))) . ')';
    return $missing;
}

// ── os movimentos do sistema ────────────────────────────────

/** Junta $items à lista $key do último movimento 'deal' registado deste negócio (se houver). */
function pipe2_deal_move_append(int $dealId, string $key, array $items): void {
    if (!$items) return;
    $r = &pipe2_req();
    for ($i = count($r['moves']) - 1; $i >= 0; $i--) {
        if (($r['moves'][$i]['kind'] ?? '') === 'deal' && (int)($r['moves'][$i]['deal'] ?? 0) === $dealId) {
            $r['moves'][$i][$key] = array_merge($r['moves'][$i][$key] ?? [], array_values($items));
            return;
        }
    }
}

/**
 * Um movimento do sistema do negócio (D9): dentro do lock do negócio (reentrante), relê deals.stage_id e, se já não é
 * $expectStageId (outro pedido moveu), devolve 'stale' sem mexer; com uma transação aberta, 'deferred'; senão
 * deal_move com ['system' => true, 'note' => $note] — 'moved', ou 'blocked' se o gate não deixar. Regista o
 * movimento em pipe2_moves (kind 'deal'; o Concluído não se desfaz).
 */
function pipe2_deal_system_move(PDO $pdo, array $deal, array $to, string $note, int $expectStageId): string {
    $dealId = (int)($deal['id'] ?? 0);
    if ($dealId <= 0 || empty($to['id'])) return 'stale';
    if ($pdo->inTransaction()) return 'deferred';
    pipe2_lock_deal($pdo, $dealId);
    try {
        $fresh = deal_get($pdo, $dealId);
        if (!$fresh || (int)$fresh['stage_id'] !== $expectStageId) return 'stale';
        $to = stage_get($pdo, (int)$to['id']) ?? $to;
        $hist0 = pipe2_max_id($pdo, 'deal_history', $dealId);
        $ev0 = metrics_ready($pdo) ? pipe2_max_id($pdo, 'stage_events', $dealId) : 0;
        $tasks0 = count(flow_created());
        try {
            deal_move($pdo, $fresh, (int)$to['id'], '', ['system' => true, 'note' => $note]);
        } catch (GateBlocked $e) {
            return 'blocked';
        }
        pipe2_move_add(['kind' => 'deal', 'deal' => $dealId,
                        'from' => [(int)$fresh['stage_id'], (string)$fresh['phase'], $fresh['stage_entered_at'], $fresh['done_at'], $fresh['last_touch']],
                        'to' => [(int)$to['id'], phase_for_kind((string)$to['kind'])], 'note' => $note, 'by' => null,
                        'tasks' => array_values(array_slice(flow_created(), $tasks0)),
                        'history' => pipe2_ids_after($pdo, 'deal_history', $dealId, $hist0),
                        'events' => metrics_ready($pdo) ? pipe2_ids_after($pdo, 'stage_events', $dealId, $ev0) : [],
                        'notices' => [], 'undoable' => $to['kind'] !== 'done']);
        return 'moved';
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/**
 * Reavalia o negócio v2 (D6, D16, E4): 'started' (o sistema começou frentes sem mover o negócio), 'to_exec',
 * 'to_delivery', 'back_exec', 'done', 'blocked' (falta alguma coisa), 'no_mandatory' (pronto para concluir, mas a
 * Entrega não tem como obrigatórias a aprovação final e o pagamento final: só um humano conclui), 'deferred' (há uma
 * transação aberta) ou 'idle'. $why: o que mudou (done, reopen, delete, gate, start, manual, undo, auto, units, cron…).
 * Dentro do lock do negócio, sobre o negócio e as frentes relidos; uma chamada aninhada (o arranque de uma frente
 * reavalia o negócio) não faz nada. Nunca lança (error_log).
 */
function pipe2_deal_reevaluate(PDO $pdo, int $dealId, string $why = ''): string {
    if ($dealId <= 0 || !empty($GLOBALS['PIPE2_DEAL_BUSY'][$dealId])) return 'idle';
    try {
        $deal = deal_get($pdo, $dealId);
        if (!$deal || !pipe2_deal_is_v2($pdo, $deal)) return 'idle';
        pipe2_lock_deal($pdo, $dealId);
        $GLOBALS['PIPE2_DEAL_BUSY'][$dealId] = true;
        try {
            return pipe2_deal_reevaluate_locked($pdo, $dealId, $why);
        } finally {
            unset($GLOBALS['PIPE2_DEAL_BUSY'][$dealId]);
            pipe2_unlock_deal($pdo, $dealId);
        }
    } catch (Throwable $e) {
        error_log('[topper] pipe2 negócio ' . $dealId . ' (' . $why . '): ' . $e->getMessage());
        return 'idle';
    }
}

function pipe2_deal_reevaluate_locked(PDO $pdo, int $dealId, string $why): string {
    $deal = deal_get($pdo, $dealId);
    if (!$deal || $deal['phase'] !== 'production' || empty($deal['funnel_id'])) return 'idle';
    $fid = (int)$deal['funnel_id'];
    $at = (int)$deal['stage_id'];
    $kick = pipe2_deal_stage($pdo, $fid, 'kickoff');
    $exec = pipe2_deal_stage($pdo, $fid, 'exec');
    $deliv = pipe2_deal_stage($pdo, $fid, 'delivery');
    $done = pipe2_deal_stage($pdo, $fid, 'done');
    $fronts = pipe2_fronts($pdo, $dealId);
    $today = today_lisbon();
    $tx = $pdo->inTransaction();
    $moved = fn(string $r, string $ok): string => $r === 'moved' ? $ok : ($r === 'stale' ? 'idle' : $r);

    // Kickoff: o sistema começa as frentes (gate cumprido e ≥ 1 obrigatória); com uma frente numa sub-etapa, Em execução
    if ($kick && $at === (int)$kick['id']) {
        $gate = pipe2_deal_gate($pdo, $deal, $kick);
        $complete = !array_filter($gate, fn($i) => !$i['ok']);
        $mandatory = count(array_filter($gate, fn($i) => isset($i['task'])));
        $queued = array_values(array_filter($fronts, fn($l) => $l['status'] === 'queued'));
        $started = false;
        if ($complete && $mandatory > 0 && $queued) {
            if ($tx) return 'deferred';
            foreach ($queued as $l) {
                try {
                    pipe2_front_start($pdo, $deal, $l, null);
                    $started = true;
                } catch (Throwable $e) {
                    error_log('[topper] pipe2 começar a frente ' . (int)$l['id'] . ' (negócio ' . $dealId . '): ' . $e->getMessage());
                }
            }
            $fronts = pipe2_fronts($pdo, $dealId);
        }
        $inStep = array_filter($fronts, fn($l) => !empty($l['stage_id']) && !in_array($l['status'], ['planned', 'queued'], true));
        if (!$inStep || !$exec) return $started ? 'started' : 'idle';
        if ($tx) return 'deferred';
        if (!pipe2_once('deal:' . $dealId)) return $started ? 'started' : 'idle';
        return $moved(pipe2_deal_system_move($pdo, $deal, $exec, PIPE2_NOTE_STARTED, $at), 'to_exec');
    }

    // Em execução: todas concluídas → regras all_ready e Entrega; senão, com alguma concluída, o que falta
    if ($exec && $at === (int)$exec['id']) {
        if (!$fronts) return 'idle';
        $concluded = array_filter($fronts, fn($l) => pipe2_front_concluded($l, $today));
        if (count($concluded) === count($fronts)) {
            if (!$deliv) return 'idle';
            if ($tx) return 'deferred';
            if (!pipe2_once('deal:' . $dealId)) return 'idle';
            $tasks = [];
            try {
                $tasks = pipe2_run_rules($pdo, $deal, $exec, 'all_ready', null, null)['tasks'];
            } catch (Throwable $e) {
                error_log('[topper] pipe2 regras all_ready (negócio ' . $dealId . '): ' . $e->getMessage());
            }
            $r = pipe2_deal_system_move($pdo, $deal, $deliv, PIPE2_NOTE_ALLREADY, $at);
            if ($r === 'moved') pipe2_deal_move_append($dealId, 'tasks', $tasks);
            return $moved($r, 'to_delivery');
        }
        if ($concluded) {
            pipe2_note('Pronto para a Entrega — falta: ' . implode(', ', pipe2_fronts_missing($pdo, $deal, $today)));
            return 'blocked';
        }
        return 'idle';
    }

    // Entrega: uma frente por concluir → Em execução; com o fecho cumprido e as duas obrigatórias → Concluído
    if ($deliv && $at === (int)$deliv['id']) {
        $open = array_values(array_filter($fronts, fn($l) => !pipe2_front_concluded($l, $today)));
        if ($open) {
            if (!$exec) return 'idle';
            if ($tx) return 'deferred';
            if (!pipe2_once('deal:' . $dealId)) return 'idle';
            $names = array_map('pipe2_dep_service', $open);
            $note = 'Voltou a Em execução: ' . implode(', ', $names) . (count($names) === 1 ? ' saiu de Pronto' : ' saíram de Pronto');
            return $moved(pipe2_deal_system_move($pdo, $deal, $exec, $note, $at), 'back_exec');
        }
        $missing = pipe2_close_missing($pdo, $deal);
        if ($missing) {
            if ($why === 'done') pipe2_note('Pronto para concluir — falta: ' . implode(', ', $missing));
            return 'blocked';
        }
        $auto = pipe2_close_auto_missing($pdo, $deal);
        if ($auto) {
            if ($why !== 'cron') pipe2_note('Pronto para concluir: conclui-se à mão (a Entrega não tem como obrigatória: ' . implode(', ', $auto) . ').');
            return 'no_mandatory';
        }
        if (!$done) return 'idle';
        if ($tx) return 'deferred';
        if (!pipe2_once('deal:' . $dealId)) return 'idle';
        $r = pipe2_deal_system_move($pdo, $deal, $done, PIPE2_NOTE_AUTODONE, $at);
        if ($r === 'moved') pipe2_conclude_delivered($pdo, deal_get($pdo, $dealId) ?? $deal);
        return $moved($r, 'done');
    }
    return 'idle';
}

/**
 * O Concluído automático (E6): delivered_at = done_at da tarefa feita do fecho (do negócio na Entrega, ou com
 * gate_stage_id = Entrega) cujo título normalizado começa por «enviar entregáveis finais», senão agora; deal_log
 * 'delivered' do «Sistema». Não mexe num delivered_at já marcado. A checklist do fecho (§15.5) não bloqueia.
 */
function pipe2_conclude_delivered(PDO $pdo, array $deal): void {
    $dealId = (int)($deal['id'] ?? 0);
    if ($dealId <= 0) return;
    $deal = deal_get($pdo, $dealId) ?? $deal;
    if (!empty($deal['delivered_at'])) return;
    $when = null;
    $deliv = !empty($deal['funnel_id']) ? pipe2_deal_stage($pdo, (int)$deal['funnel_id'], 'delivery') : null;
    if ($deliv) {
        $st = $pdo->prepare("SELECT title, done_at FROM tasks WHERE deal_id = ? AND status = 'done' AND done_at IS NOT NULL
                                AND ((line_id IS NULL AND stage_id = ?) OR gate_stage_id = ?) ORDER BY done_at, id");
        $st->execute([$dealId, (int)$deliv['id'], (int)$deliv['id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            if (str_starts_with(pipe2_title_norm((string)$t['title']), 'enviar entregáveis finais')) { $when = (string)$t['done_at']; break; }
        }
    }
    $when ??= date('Y-m-d H:i:s');
    $up = $pdo->prepare('UPDATE deals SET delivered_at = ? WHERE id = ? AND delivered_at IS NULL');
    $up->execute([$when, $dealId]);
    if ($up->rowCount() !== 1) return;
    deal_log($pdo, $dealId, 'delivered', ['note' => 'Entregas feitas (Concluído automático)', 'user_id' => null, 'system' => true]);
}
