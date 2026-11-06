<?php
/**
 * topper. admin — Pipeline v2: relógio da v2
 *
 * O relógio diário (flow_tick, no cron) chama pipe2_tick depois do xsell_tick. Primeiro corre o relógio da avença
 * (pipe2_billing_tick: fim do período, fatura do mês, recomeço do ciclo); depois, para cada negócio v2 em produção, com
 * o lock do negócio (pipe2_lock_deal) e relendo o negócio e as frentes DEPOIS do lock (um pedido pode tê-los mudado
 * entre a lista e o lock), em cada frente viva:
 *   · em curso ou Ativa (running/ongoing) numa sub-etapa S:
 *       - o replaneamento (pipe2_replan; com deslize, um aviso 'replan' ao responsável, uma vez por frente, sub-etapa e
 *         ciclo);
 *       - parada (fora de Pronto) há N dias, contados do movimento mais recente da frente (pipe2_front_last_move): com
 *         N ≥ o limite (max_days de S, senão deal_stale_days) um aviso 'stale', uma vez; e as regras f_stale de S, cada
 *         uma quando N ≥ trig_n (senão o limite), uma vez por frente e ciclo (deduplicação D12);
 *       - as regras 'date' de S, pelo prazo sobre o negócio visto pela frente (pipe2_vdeal: a entrega da frente), quando
 *         o prazo já chegou, uma vez por frente e ciclo;
 *   · em curso, Ativa ou À espera: a reavaliação (pipe2_front_reevaluate: avança uma sub-etapa, ou liberta a frente À
 *     espera cujas ligações ficaram cumpridas);
 * e, no fim do negócio, a reavaliação do negócio (pipe2_deal_reevaluate, «cron»: sem nota para quem chamou).
 *
 * Uma frente numa etapa que não é uma sub-etapa do funil dela (dados partidos) faz saltar o negócio inteiro: o erro
 * fica no error_log e os outros negócios seguem. O relógio nunca lança. Os movimentos são do «Sistema» (o motor nunca
 * decide por permissões nem pela sessão: o cron não a tem). Sem as colunas da v2 (pipe2_schema_ready) ou sem negócios
 * v2, não lê nem escreve nada da v2.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_fronts.php';    // pipe2_v2_funnels_sql, pipe2_deal_is_v2, pipe2_fronts, pipe2_front_get, pipe2_user_ok
require_once __DIR__ . '/pipe2_deps.php';      // pipe2_replan, pipe2_dep_service
require_once __DIR__ . '/pipe2_tasks.php';     // pipe2_stage_rules, pipe2_rule_apply, pipe2_vdeal
require_once __DIR__ . '/pipe2_notify.php';    // pipe2_notice_add, pipe2_notice_key
require_once __DIR__ . '/pipe2_engine.php';    // pipe2_lock_deal, pipe2_unlock_deal, pipe2_front_reevaluate
require_once __DIR__ . '/pipe2_deal.php';      // pipe2_deal_reevaluate
require_once __DIR__ . '/pipe2_billing.php';   // pipe2_billing_tick
require_once __DIR__ . '/pipeline.php';        // deal_get, stage_get
require_once __DIR__ . '/pipeline_flow.php';   // flow_due
require_once __DIR__ . '/alerts.php';          // alert_limits
require_once __DIR__ . '/deals.php';           // deal_log, today_lisbon

/** Os resultados de pipe2_deal_reevaluate que são um movimento do negócio. */
const PIPE2_TICK_MOVED = ['started', 'to_exec', 'to_delivery', 'back_exec', 'done'];

/**
 * O relógio da v2 (ver o cabeçalho). Devolve ['billing' => o de pipe2_billing_tick, 'fronts' => frentes vistas (em
 * curso, Ativas ou À espera), 'advanced' => frentes que avançaram ou foram libertadas, 'replanned' => frentes
 * replaneadas, 'stale' => avisos 'stale' e tarefas ou avisos das regras f_stale criados, 'dated' => tarefas ou avisos
 * das regras 'date' criados, 'deals' => negócios que o sistema moveu]. Nunca lança.
 */
function pipe2_tick(PDO $pdo, ?string $today = null): array {
    $out = ['billing' => ['opened' => 0, 'restarted' => 0, 'ended' => 0], 'fronts' => 0, 'advanced' => 0, 'replanned' => 0, 'stale' => 0, 'dated' => 0, 'deals' => 0];
    try {
        $today = substr($today ?? today_lisbon(), 0, 10);
        if (!pipe2_schema_ready($pdo)) return $out;
        $out['billing'] = pipe2_billing_tick($pdo, $today);
        $ids = array_map('intval', $pdo->query("SELECT id FROM deals WHERE phase = 'production' AND funnel_id IN (" . pipe2_v2_funnels_sql() . ') ORDER BY id')
                                       ->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        error_log('[topper] pipe2 relógio: ' . $e->getMessage());
        return $out;
    }
    foreach ($ids as $dealId) {
        $locked = false;
        try {
            pipe2_lock_deal($pdo, $dealId);
            $locked = true;
            pipe2_tick_deal($pdo, $dealId, $today, $out);
        } catch (Throwable $e) {
            error_log('[topper] pipe2 relógio (negócio ' . $dealId . '): ' . $e->getMessage());
        } finally {
            if ($locked) pipe2_unlock_deal($pdo, $dealId);
        }
    }
    return $out;
}

/** Um negócio do relógio, dentro do lock dele (ver pipe2_tick). Soma em $out; lança nos dados partidos. */
function pipe2_tick_deal(PDO $pdo, int $dealId, string $today, array &$out): void {
    $deal = deal_get($pdo, $dealId);
    if (!$deal || $deal['phase'] !== 'production' || !pipe2_deal_is_v2($pdo, $deal)) return;
    foreach (pipe2_fronts($pdo, $dealId) as $l) {
        $line = pipe2_front_get($pdo, (int)$l['id']);   // uma frente anterior pode ter libertado esta
        if (!$line || !in_array($line['status'], ['running', 'ongoing', 'depwait'], true)) continue;
        $out['fronts']++;
        $stage = !empty($line['stage_id']) ? stage_get($pdo, (int)$line['stage_id']) : null;
        if (!empty($line['stage_id']) && (!$stage || (int)$stage['funnel_id'] !== (int)$line['funnel_id'] || ($stage['level'] ?? '') !== 'front')) {
            throw new RuntimeException('A frente ' . (int)$line['id'] . ' está numa etapa que não é uma sub-etapa do funil dela.');
        }
        if ($stage && $line['status'] !== 'depwait') {
            if (pipe2_tick_replan($pdo, $deal, $line, $stage, $today)) $out['replanned']++;
            $out['stale'] += pipe2_tick_stale($pdo, $deal, $line, $stage, $today);
            $out['dated'] += pipe2_tick_dates($pdo, $deal, $line, $stage, $today);
            $line = pipe2_front_get($pdo, (int)$line['id']) ?? $line;
        }
        if (in_array(pipe2_front_reevaluate($pdo, $deal, $line), ['advanced', 'released'], true)) $out['advanced']++;
    }
    if (in_array(pipe2_deal_reevaluate($pdo, $dealId, 'cron'), PIPE2_TICK_MOVED, true)) $out['deals']++;
}

/** O responsável de um aviso da frente: o da frente, senão o da execução do negócio (nunca o superadmin nem uma conta desativada). */
function pipe2_tick_owner(PDO $pdo, array $deal, array $line): ?int {
    return pipe2_user_ok($pdo, $line['exec_owner_id'] ?? null) ?? pipe2_user_ok($pdo, $deal['exec_owner_id'] ?? null);
}

/** «1 dia», «3 dias úteis»… */
function pipe2_tick_days(int $n, bool $work = false): string {
    return $n . ' ' . ($work ? ($n === 1 ? 'dia útil' : 'dias úteis') : ($n === 1 ? 'dia' : 'dias'));
}

/** O replaneamento da frente e, com deslize, o aviso 'replan' (uma vez por frente, sub-etapa e ciclo). true = replaneou. */
function pipe2_tick_replan(PDO $pdo, array $deal, array $line, array $stage, string $today): bool {
    $n = pipe2_replan($pdo, $line, $today);
    if ($n <= 0) return false;
    $uid = pipe2_tick_owner($pdo, $deal, $line);
    if ($uid !== null) {
        $dealId = (int)$deal['id'];
        $cycle = (string)$line['cycle_ym'];
        pipe2_notice_add($pdo, [
            'deal_id' => $dealId, 'line_id' => (int)$line['id'], 'stage_id' => (int)$stage['id'], 'cycle_ym' => $cycle, 'kind' => 'replan', 'user_id' => $uid,
            'title' => pipe2_dep_service($line) . ': «' . (string)$stage['name'] . '» atrasada',
            'body' => 'As sub-etapas seguintes deslizaram ' . pipe2_tick_days($n, true) . ' (replaneado); a entrega não muda.',
            'nkey' => pipe2_notice_key('replan', $dealId, (int)$line['id'], (int)$stage['id'], $cycle),
        ]);
    }
    return true;
}

/**
 * O movimento mais recente da frente (AAAA-MM-DD hh:mm:ss): a entrada na sub-etapa (stage_entered_at), as tarefas da
 * frente feitas (done_at) e as entradas e saídas das sub-etapas (deal_line_steps). Sem nenhum, o começo da frente,
 * senão a criação; '' sem datas.
 */
function pipe2_front_last_move(PDO $pdo, array $line): string {
    $lineId = (int)($line['id'] ?? 0);
    $dates = [(string)($line['stage_entered_at'] ?? '')];
    if ($lineId > 0) {
        $st = $pdo->prepare("SELECT MAX(done_at) FROM tasks WHERE line_id = ? AND status = 'done'");
        $st->execute([$lineId]);
        $dates[] = (string)$st->fetchColumn();
        $st = $pdo->prepare('SELECT MAX(started_at) AS s, MAX(done_at) AS d FROM deal_line_steps WHERE line_id = ?');
        $st->execute([$lineId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $dates[] = (string)($r['s'] ?? '');
        $dates[] = (string)($r['d'] ?? '');
    }
    $dates = array_filter($dates, fn($d) => $d !== '');
    if ($dates) return (string)max($dates);
    foreach (['started_at', 'created_at'] as $k) if ((string)($line[$k] ?? '') !== '') return (string)$line[$k];
    return '';
}

/** Os dias de calendário entre a data de $from e $today (negativo se $from for depois). */
function pipe2_tick_days_since(string $from, string $today): int {
    if ($from === '') return 0;
    return (int)(new DateTimeImmutable(substr($from, 0, 10)))->diff(new DateTimeImmutable($today))->format('%r%a');
}

/**
 * A frente parada (fora de Pronto): o aviso 'stale' com N ≥ o limite (max_days da sub-etapa, senão deal_stale_days),
 * uma vez por frente, sub-etapa e ciclo; e as regras f_stale da sub-etapa, cada uma com N ≥ trig_n (senão o limite).
 * Devolve quantos avisos e tarefas criou.
 */
function pipe2_tick_stale(PDO $pdo, array $deal, array $line, array $stage, string $today): int {
    if (($stage['role'] ?? '') === 'ready') return 0;   // em Pronto, entre ciclos, a frente não está parada
    $days = pipe2_tick_days_since(pipe2_front_last_move($pdo, $line), $today);
    if ($days <= 0) return 0;
    $limit = (int)($stage['max_days'] ?? 0) > 0 ? (int)$stage['max_days'] : alert_limits($pdo)['deal'];
    $dealId = (int)$deal['id'];
    $lineId = (int)$line['id'];
    $cycle = (string)$line['cycle_ym'];
    $svc = pipe2_dep_service($line);
    $n = 0;
    if ($days >= $limit && ($uid = pipe2_tick_owner($pdo, $deal, $line)) !== null) {
        $id = pipe2_notice_add($pdo, [
            'deal_id' => $dealId, 'line_id' => $lineId, 'stage_id' => (int)$stage['id'], 'cycle_ym' => $cycle, 'kind' => 'stale', 'user_id' => $uid,
            'title' => $svc . ': parada há ' . pipe2_tick_days($days),
            'body' => 'Sem movimento em «' . (string)$stage['name'] . '» há ' . pipe2_tick_days($days) . ' (o limite é ' . pipe2_tick_days($limit) . ').',
            'nkey' => pipe2_notice_key('stale', $dealId, $lineId, (int)$stage['id'], $cycle),
        ]);
        if ($id !== null) $n++;
    }
    $rules = array_filter(pipe2_stage_rules($pdo, (int)$stage['id'], ['f_stale']), fn($r) => $days >= ((int)($r['trig_n'] ?? 0) > 0 ? (int)$r['trig_n'] : $limit));
    return $n + pipe2_tick_rules($pdo, $deal, $line, $stage, array_values($rules), $today, 'frente parada');
}

/** As regras 'date' da sub-etapa cujo prazo (pela entrega da frente) já chegou. Devolve quantas tarefas ou avisos criou. */
function pipe2_tick_dates(PDO $pdo, array $deal, array $line, array $stage, string $today): int {
    $v = pipe2_vdeal($deal, $line);
    $rules = [];
    foreach (pipe2_stage_rules($pdo, (int)$stage['id'], ['date']) as $r) {
        [$due] = flow_due($pdo, $r, $v, $today);
        if ($due !== null && $due <= $today) $rules[] = $r;
    }
    return pipe2_tick_rules($pdo, $deal, $line, $stage, $rules, $today, 'data');
}

/**
 * Aplica as regras do relógio a uma frente (tarefas da frente, na sub-etapa e no ciclo dela; deduplicação D12), como
 * «Sistema», com uma linha 'auto' no histórico quando cria tarefas. Devolve quantas tarefas ou avisos criou.
 */
function pipe2_tick_rules(PDO $pdo, array $deal, array $line, array $stage, array $rules, string $today, string $why): int {
    if (!$rules) return 0;
    $v = pipe2_vdeal($deal, $line);
    $tasks = 0;
    $notices = 0;
    foreach ($rules as $r) {
        $res = pipe2_rule_apply($pdo, $r, $v, $stage, $today, null);
        if ($res['task'] !== null) $tasks++;
        if ($res['notice'] !== null) $notices++;
    }
    if ($tasks) {
        deal_log($pdo, (int)$deal['id'], 'auto', ['note' => ($tasks === 1 ? '1 tarefa automática criada' : $tasks . ' tarefas automáticas criadas')
                                                          . ' (' . (string)$stage['name'] . ' · ' . pipe2_dep_service($line) . ', ' . $why . ')',
                                                  'user_id' => null, 'system' => true]);
    }
    return $tasks + $notices;
}
