<?php
/**
 * topper. admin — Pipeline v2: avença e faturação
 *
 * Avença (D15, D4 dos sócios). Uma frente avença tem deal_lines.billing = 'monthly', a mensalidade e os meses
 * (deals.billing = 'monthly' quando uma frente o é); o valor da frente é mensalidade × meses. O período vai do 1.º
 * dia do mês em que a frente começa ao último dia do mês início + meses − 1 (billing_end). Há duas condições
 * separadas, ambas com o dia efetivo min(N, último dia do mês) (pipe2_billing_day_ok):
 *   (a) a fatura do mês — por regra fee_day da Em execução do funil da frente (dia N = trig_n, senão 1): a avença
 *       começada e não cancelada, o mês de hoje dentro do período e o dia já chegou. Não olha para o ciclo da frente,
 *       por isso a fatura do mês em que a frente começa também abre. Uma por regra e ciclo AAAA-MM de hoje (a
 *       deduplicação D12), por isso o 1.º cron depois de um dia falhado abre-a uma só vez;
 *   (b) o recomeço do ciclo de produção — a frente em Pronto (Ativa), a avença por terminar, o ciclo de um mês
 *       anterior e o dia já chegou (N = o menor trig_n das regras fee_day, senão 1): volta à 1.ª sub-etapa com o
 *       ciclo de hoje e as linhas de passos do ciclo novo. Fora de Pronto o ciclo atual continua e há um aviso
 *       'cycle' ao responsável, uma vez por mês.
 * A avença conclui no fim do período (hoje > billing_end) ou ao cancelar: a frente passa a Pronto final ('ready').
 *
 * O relógio (pipe2_billing_tick) toma o lock de cada negócio (pipe2_lock_deal) e relê o negócio e as frentes depois do
 * lock; um erro num negócio fica no error_log e não impede os outros. Os movimentos são do «Sistema» (pipe2_front_enter
 * sem autor). Nunca decide por permissões nem pela sessão (o cron não a tem).
 *
 * Pagamento final (D16, T1): num negócio v2 é o pagamento validado numa tarefa da Entrega (role 'delivery') que marca
 * deals.is_paid; o pagamento de um ciclo (uma tarefa da Em execução) não.
 *
 * «Pedir fatura» pela tarefa (T3, D24). Só as funções nesta fase (a ação e o botão são da fase 2, onde quem chama
 * verifica pipe2_can_request_invoice: negócio v2 e flow_can_bill). O pedido monta-se no servidor: o NIF, o nome e o
 * email do cliente vêm de billing_deal, nunca do formulário. O pedido guarda a tarefa em fin_docs.task_id; a fatura
 * emitida desse pedido fecha só essa tarefa (fin_doc_issued). pipe2_invoice_state dá o estado sem NIF nem valores
 * («sem Financeiro vê-se o estado, não o NIF»).
 *
 * As notas do histórico desta parte não levam euros (o histórico do negócio vê-se sem Financeiro).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_fronts.php';    // pipe2_deal_is_v2, pipe2_front_get, pipe2_fronts, pipe2_front_stages, pipe2_deal_stage, avença, pipe2_user_ok
require_once __DIR__ . '/pipe2_deps.php';      // pipe2_dep_service, pipe2_plan_front
require_once __DIR__ . '/pipe2_tasks.php';     // pipe2_stage_rules, pipe2_task_from_rule, pipe2_vdeal
require_once __DIR__ . '/pipe2_notify.php';    // pipe2_notice_add, pipe2_notice_key
require_once __DIR__ . '/pipe2_engine.php';    // pipe2_lock_deal, pipe2_front_enter, pipe2_step_row, pipe2_after_front_change, pipe2_req
require_once __DIR__ . '/pipeline.php';        // deal_get, stage_get
require_once __DIR__ . '/deals.php';           // deal_log, today_lisbon
require_once __DIR__ . '/tasks.php';           // task_by_id

const PIPE2_FEE_MAX_MONTHS = 120;
const PIPE2_BILLING_ENDED_NOTE = 'Avença terminou';
const PIPE2_BILLING_LIVE = ['queued', 'running', 'depwait', 'ongoing'];   // começada ou por começar, ainda não em Pronto final

// ── definir, tirar e cancelar ───────────────────────────────

/** Relê (dentro do lock) o negócio v2 e a frente viva dele; senão lança. */
function pipe2_billing_load(PDO $pdo, array $deal, array $line): array {
    $dealId = (int)($deal['id'] ?? 0);
    $fresh = $dealId > 0 ? deal_get($pdo, $dealId) : null;
    if (!$fresh || !pipe2_deal_is_v2($pdo, $fresh)) throw new RuntimeException('Este negócio não é da Pipeline v2.');
    $l = pipe2_front_get($pdo, (int)($line['id'] ?? 0));
    if (!$l || (int)$l['deal_id'] !== $dealId || $l['status'] === 'merged') throw new RuntimeException('Frente não encontrada.');
    return [$fresh, $l];
}

/** «12 meses», «1 mês». */
function pipe2_billing_months_label(int $n): string {
    return $n . ($n === 1 ? ' mês' : ' meses');
}

/** Corre $fn numa transação (a de quem chamou, se houver uma aberta). */
function pipe2_billing_tx(PDO $pdo, callable $fn) {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $r = $fn();
        if ($own) $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * A frente passa a avença (D15): mensalidade > 0 e 1 a 120 meses; valor = mensalidade × meses; deals.billing
 * 'monthly'; deal_log 'p2_billing' (sem euros). Numa avença já começada, o fim do período recalcula-se pelos meses
 * novos. Recusa um negócio v1, uma frente de outro negócio, uma avença cancelada e uma frente concluída.
 */
function pipe2_billing_set(PDO $pdo, array $deal, array $line, float $fee, int $months, ?int $by = null): void {
    $fee = round($fee, 2);
    if ($fee <= 0) throw new RuntimeException('Indique a mensalidade.');
    if ($months < 1 || $months > PIPE2_FEE_MAX_MONTHS) throw new RuntimeException('Indique entre 1 e 120 meses.');
    $dealId = (int)($deal['id'] ?? 0);
    pipe2_lock_deal($pdo, $dealId);
    try {
        [, $l] = pipe2_billing_load($pdo, $deal, $line);
        if (!empty($l['cancelled_at'])) throw new RuntimeException('A avença foi cancelada.');
        if ($l['status'] === 'ready') throw new RuntimeException('A frente já está concluída.');
        $end = !empty($l['started_at']) ? pipe2_billing_period(['fee_months' => $months], (string)$l['started_at'])['end'] : null;
        pipe2_billing_tx($pdo, function () use ($pdo, $l, $dealId, $fee, $months, $end, $by) {
            $sql = "UPDATE deal_lines SET billing = 'monthly', monthly_fee = ?, fee_months = ?, value = ?" . ($end !== null ? ', billing_end = ?' : '') . ' WHERE id = ?';
            $args = [$fee, $months, round($fee * $months, 2)];
            if ($end !== null) $args[] = $end;
            $args[] = (int)$l['id'];
            $pdo->prepare($sql)->execute($args);
            $pdo->prepare("UPDATE deals SET billing = 'monthly' WHERE id = ?")->execute([$dealId]);
            deal_log($pdo, $dealId, 'p2_billing', ['note' => pipe2_dep_service($l) . ': avença de ' . pipe2_billing_months_label($months)
                                                             . ($end !== null ? ' (até ' . date('d/m/Y', strtotime($end)) . ')' : ''),
                                                   'user_id' => $by, 'system' => $by === null]);
        });
        $GLOBALS['XSELL_FRESH'] = true;
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/**
 * A avença volta a pontual (só antes de a frente começar: Por começar ou antes do Kickoff, sem started_at): limpa a
 * mensalidade, os meses e o fim; deals.billing fica 'monthly' só se outra frente viva for avença; os valores das
 * frentes recalculam-se (pipe2_store_values). Uma frente que não é avença fica como está.
 */
function pipe2_billing_clear(PDO $pdo, array $deal, array $line, ?int $by = null): void {
    $dealId = (int)($deal['id'] ?? 0);
    pipe2_lock_deal($pdo, $dealId);
    try {
        [$fresh, $l] = pipe2_billing_load($pdo, $deal, $line);
        if (!pipe2_line_is_monthly($l)) return;
        if (!in_array($l['status'], ['planned', 'queued'], true) || !empty($l['started_at'])) {
            throw new RuntimeException('A avença já começou: cancele-a em vez de a tirar.');
        }
        pipe2_billing_tx($pdo, function () use ($pdo, $fresh, $l, $dealId, $by) {
            $pdo->prepare("UPDATE deal_lines SET billing = '', monthly_fee = NULL, fee_months = NULL, billing_end = NULL, value = NULL WHERE id = ?")->execute([(int)$l['id']]);
            $st = $pdo->prepare("SELECT COUNT(*) FROM deal_lines WHERE deal_id = ? AND status <> 'merged' AND billing = 'monthly'");
            $st->execute([$dealId]);
            $left = (int)$st->fetchColumn();
            $pdo->prepare('UPDATE deals SET billing = ? WHERE id = ?')->execute([$left > 0 ? 'monthly' : '', $dealId]);
            deal_log($pdo, $dealId, 'p2_billing', ['note' => pipe2_dep_service($l) . ': deixou de ser avença (pontual)', 'user_id' => $by, 'system' => $by === null]);
            pipe2_store_values($pdo, $fresh);
        });
        $GLOBALS['XSELL_FRESH'] = true;
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/** A sub-etapa Pronto (role 'ready') viva do funil, ou null. */
function pipe2_billing_ready_stage(PDO $pdo, int $funnelId): ?array {
    foreach (pipe2_front_stages($pdo, $funnelId) as $s) if (($s['role'] ?? '') === 'ready') return $s;
    return null;
}

/**
 * Cancela a avença (D15): motivo obrigatório; cancelled_at = agora, billing_end = min(billing_end, hoje); a frente vai
 * para Pronto final (pipe2_front_enter, why 'cancel', status 'ready'); deal_log 'p2_billing' «Avença cancelada:
 * <motivo>». Tudo numa transação, dentro do lock. O movimento não se anula pelo «Anular» (o cancelamento não volta
 * atrás). Quem chama reavalia o negócio.
 */
function pipe2_billing_cancel(PDO $pdo, array $deal, array $line, string $reason, ?int $by = null): void {
    $reason = trim((string)preg_replace('/\s+/u', ' ', $reason));
    if ($reason === '') throw new RuntimeException('Indique o motivo.');
    $reason = mb_substr($reason, 0, 200);
    $dealId = (int)($deal['id'] ?? 0);
    pipe2_lock_deal($pdo, $dealId);
    try {
        [$fresh, $l] = pipe2_billing_load($pdo, $deal, $line);
        if (!pipe2_line_is_monthly($l)) throw new RuntimeException('Esta frente não é uma avença.');
        if (!empty($l['cancelled_at'])) throw new RuntimeException('A avença já foi cancelada.');
        if ($l['status'] === 'planned') throw new RuntimeException('A avença ainda não chegou ao Kickoff: tire-a em vez de a cancelar.');
        if ($l['status'] === 'ready') throw new RuntimeException('A avença já terminou.');
        $ready = pipe2_billing_ready_stage($pdo, (int)$l['funnel_id']);
        if (!$ready) throw new RuntimeException('O funil da frente ' . pipe2_dep_service($l) . ' não tem a sub-etapa Pronto.');
        $lineId = (int)$l['id'];
        $today = today_lisbon();
        $end = substr((string)($l['billing_end'] ?? ''), 0, 10);
        $end = $end !== '' && $end < $today ? $end : $today;
        pipe2_billing_tx($pdo, function () use ($pdo, $fresh, $lineId, $dealId, $ready, $end, $reason, $by) {
            $st = $pdo->prepare('UPDATE deal_lines SET cancelled_at = ?, billing_end = ? WHERE id = ? AND cancelled_at IS NULL');
            $st->execute([date('Y-m-d H:i:s'), $end, $lineId]);
            if ($st->rowCount() !== 1) throw new RuntimeException('A avença já foi cancelada.');
            deal_log($pdo, $dealId, 'p2_billing', ['note' => 'Avença cancelada: ' . $reason, 'user_id' => $by, 'system' => $by === null]);
            $cur = pipe2_front_get($pdo, $lineId);
            if (!$cur || pipe2_front_enter($pdo, $fresh, $cur, $ready, ['why' => 'cancel', 'status' => 'ready', 'by' => $by, 'note' => 'Avença cancelada']) !== 'entered') {
                throw new RuntimeException(PIPE2_FRONT_CHANGED);
            }
        });
        // o cancelamento não se desfaz: o movimento fica registado, mas sem «Anular» (D13)
        $r = &pipe2_req();
        $i = $r['last'][$lineId] ?? null;
        if ($i !== null && isset($r['moves'][$i])) $r['moves'][$i]['undoable'] = false;
        $GLOBALS['XSELL_FRESH'] = true;
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

// ── as condições (D15, puras salvo pipe2_billing_day) ───────

/** O N do recomeço: o menor trig_n (> 0) das regras fee_day ativas da Em execução do funil da frente; senão 1. */
function pipe2_billing_day(PDO $pdo, array $line): int {
    $exec = !empty($line['funnel_id']) ? pipe2_deal_stage($pdo, (int)$line['funnel_id'], 'exec') : null;
    if (!$exec) return 1;
    $min = null;
    foreach (pipe2_stage_rules($pdo, (int)$exec['id'], ['fee_day']) as $r) {
        $n = (int)($r['trig_n'] ?? 0);
        if ($n > 0 && ($min === null || $n < $min)) $min = $n;
    }
    return $min ?? 1;
}

/** Puro: o dia de $today já chegou a min($dayN, último dia do mês de $today) ($dayN ≤ 0 conta como 1). */
function pipe2_billing_day_ok(string $today, int $dayN): bool {
    $t = new DateTimeImmutable(substr($today, 0, 10));
    return (int)$t->format('j') >= min(max(1, $dayN), (int)$t->format('t'));
}

/**
 * Puro, D15 (a): a fatura do mês. Avença começada (started_at), sem cancelamento, o mês de hoje entre o mês de início
 * e o de billing_end (sem billing_end, o fim do período pelo início e pelos meses) e o dia já chegou. Não olha para o
 * ciclo da frente: a fatura do mês de início também abre.
 */
function pipe2_billing_invoice_due(array $line, string $today, int $dayN): bool {
    if (!pipe2_line_is_monthly($line)) return false;
    $start = (string)($line['started_at'] ?? '');
    if ($start === '') return false;
    if (($line['cancelled_at'] ?? null) !== null && (string)$line['cancelled_at'] !== '') return false;
    $ym = substr($today, 0, 7);
    $end = (string)($line['billing_end'] ?? '');
    if ($end === '') $end = pipe2_billing_period($line, $start)['end'];
    if ($ym < substr($start, 0, 7) || $ym > substr($end, 0, 7)) return false;
    return pipe2_billing_day_ok($today, $dayN);
}

/**
 * Puro, D15 (b): o recomeço do ciclo. A frente em Pronto (Ativa na sub-etapa 'ready'), a avença por terminar, o ciclo
 * de um mês anterior ao de hoje e o dia já chegou.
 */
function pipe2_billing_restart_due(array $line, string $today, int $dayN): bool {
    if (!pipe2_line_is_monthly($line)) return false;
    if (($line['status'] ?? '') !== 'ongoing' || ($line['stage_role'] ?? '') !== 'ready') return false;
    if (pipe2_billing_ended($line, $today)) return false;
    $cycle = (string)($line['cycle_ym'] ?? '');
    if ($cycle === '' || $cycle >= substr($today, 0, 7)) return false;
    return pipe2_billing_day_ok($today, $dayN);
}

// ── o relógio da avença ─────────────────────────────────────

/**
 * O relógio da avença (D15): para cada frente avença viva de negócios v2 em produção, com o lock do negócio e relendo o
 * negócio e a frente depois dele, por esta ordem: (1) terminada e não em Pronto final → Pronto final («Avença
 * terminou»; why 'cancel' se cancelada, senão 'cycle') e passa à seguinte; (2) a fatura do mês, por regra fee_day; (3)
 * o recomeço do ciclo, ou o aviso 'cycle' fora de Pronto. Com uma frente terminada, o negócio reavalia-se. Devolve
 * ['opened' => tarefas de fatura criadas, 'restarted' => ciclos recomeçados, 'ended' => avenças terminadas]. Nunca lança.
 */
function pipe2_billing_tick(PDO $pdo, string $today): array {
    $out = ['opened' => 0, 'restarted' => 0, 'ended' => 0];
    $today = substr($today, 0, 10);
    try {
        if (!pipe2_schema_ready($pdo)) return $out;
        $rows = $pdo->query("SELECT l.id, l.deal_id FROM deal_lines l JOIN deals d ON d.id = l.deal_id
                              WHERE l.billing = 'monthly' AND l.status <> 'merged' AND d.phase = 'production'
                                AND d.funnel_id IN (" . pipe2_v2_funnels_sql() . ')
                              ORDER BY l.deal_id, l.id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[topper] pipe2 avença: ' . $e->getMessage());
        return $out;
    }
    $byDeal = [];
    foreach ($rows as $r) $byDeal[(int)$r['deal_id']][] = (int)$r['id'];
    foreach ($byDeal as $dealId => $lineIds) {
        $locked = false;
        try {
            pipe2_lock_deal($pdo, $dealId);
            $locked = true;
            pipe2_billing_tick_deal($pdo, $dealId, $lineIds, $today, $out);
        } catch (Throwable $e) {
            error_log('[topper] pipe2 avença (negócio ' . $dealId . '): ' . $e->getMessage());
        } finally {
            if ($locked) pipe2_unlock_deal($pdo, $dealId);
        }
    }
    return $out;
}

/** Um negócio do relógio da avença, dentro do lock dele (ver pipe2_billing_tick). Soma em $out. */
function pipe2_billing_tick_deal(PDO $pdo, int $dealId, array $lineIds, string $today, array &$out): void {
    $deal = deal_get($pdo, $dealId);
    if (!$deal || $deal['phase'] !== 'production' || !pipe2_deal_is_v2($pdo, $deal)) return;
    $ym = substr($today, 0, 7);
    $ended = false;
    foreach ($lineIds as $lineId) {
        $line = pipe2_front_get($pdo, $lineId);
        if (!$line || (int)$line['deal_id'] !== $dealId || $line['status'] === 'merged' || !pipe2_line_is_monthly($line)) continue;

        // (1) terminou (fim do período ou cancelada): Pronto final
        if (pipe2_billing_ended($line, $today)) {
            $ready = in_array($line['status'], PIPE2_BILLING_LIVE, true) ? pipe2_billing_ready_stage($pdo, (int)$line['funnel_id']) : null;
            if ($ready) {
                $why = !empty($line['cancelled_at']) ? 'cancel' : 'cycle';
                if (pipe2_front_enter($pdo, $deal, $line, $ready, ['why' => $why, 'status' => 'ready', 'note' => PIPE2_BILLING_ENDED_NOTE]) === 'entered') {
                    $out['ended']++;
                    $ended = true;
                }
            }
            continue;
        }

        // (2) a fatura do mês: uma por regra fee_day e ciclo AAAA-MM de hoje (tarefa do negócio, na Em execução dele)
        $exec = pipe2_deal_stage($pdo, (int)$line['funnel_id'], 'exec');
        $dealExec = pipe2_deal_stage($pdo, (int)$deal['funnel_id'], 'exec');
        if ($exec && $dealExec) {
            $made = 0;
            $v = pipe2_vdeal($deal, null, $ym);
            foreach (pipe2_stage_rules($pdo, (int)$exec['id'], ['fee_day']) as $r) {
                if (!pipe2_billing_invoice_due($line, $today, (int)($r['trig_n'] ?? 0) ?: 1)) continue;
                if (pipe2_task_from_rule($pdo, $r, $v, $dealExec, $today, null) !== null) $made++;
            }
            if ($made) {
                $out['opened'] += $made;
                deal_log($pdo, $dealId, 'auto', ['note' => ($made === 1 ? '1 tarefa automática criada' : $made . ' tarefas automáticas criadas') . ' (' . $dealExec['name']
                                                         . ' · ' . pipe2_dep_service($line) . ', avença de ' . date('m/Y', strtotime($ym . '-01')) . ')',
                                                 'user_id' => null, 'system' => true]);
            }
        }

        // (3) o recomeço do ciclo (em Pronto) ou, fora de Pronto, o aviso de que o ciclo atual continua
        $dayN = pipe2_billing_day($pdo, $line);
        if (pipe2_billing_restart_due($line, $today, $dayN)) {
            if (pipe2_billing_restart($pdo, $deal, $line, $ym, $today)) $out['restarted']++;
        } elseif (in_array($line['status'], ['running', 'ongoing', 'depwait'], true) && !empty($line['started_at'])
                  && (string)$line['cycle_ym'] !== '' && (string)$line['cycle_ym'] < $ym && pipe2_billing_day_ok($today, $dayN)) {
            pipe2_billing_cycle_notice($pdo, $deal, $line, $ym);
        }
    }
    if ($ended) pipe2_after_front_change($pdo, $dealId, 'cron');
}

/**
 * O ciclo novo (D15 b): a frente volta à 1.ª sub-etapa («Sistema», why 'cycle', ciclo $ym, Ativa), nascem as linhas de
 * passos de todas as sub-etapas do ciclo novo (pipe2_step_row, que toma o lock) e faz-se o plano a partir de $today.
 */
function pipe2_billing_restart(PDO $pdo, array $deal, array $line, string $ym, string $today): bool {
    $stages = pipe2_front_stages($pdo, (int)$line['funnel_id']);
    if (!$stages) return false;
    if (pipe2_front_enter($pdo, $deal, $line, $stages[0], ['why' => 'cycle', 'cycle' => $ym, 'status' => 'ongoing']) !== 'entered') return false;
    $fresh = pipe2_front_get($pdo, (int)$line['id']) ?? (['cycle_ym' => $ym] + $line);
    foreach ($stages as $s) pipe2_step_row($pdo, ['cycle_ym' => $ym] + $fresh, (int)$s['id']);
    pipe2_plan_front($pdo, $fresh, $today);
    return true;
}

/** O aviso 'cycle' (uma vez por frente e mês): ao responsável da frente, senão ao da execução do negócio. */
function pipe2_billing_cycle_notice(PDO $pdo, array $deal, array $line, string $ym): void {
    $uid = pipe2_user_ok($pdo, $line['exec_owner_id'] ?? null) ?? pipe2_user_ok($pdo, $deal['exec_owner_id'] ?? null);
    if ($uid === null) return;
    $dealId = (int)$deal['id'];
    $lineId = (int)$line['id'];
    $month = fn(string $m) => date('m/Y', strtotime($m . '-01'));
    pipe2_notice_add($pdo, [
        'deal_id' => $dealId, 'line_id' => $lineId, 'cycle_ym' => $ym, 'kind' => 'cycle', 'user_id' => $uid,
        'title' => pipe2_dep_service($line) . ': o ciclo de ' . $month($ym) . ' ainda não começou',
        'body' => 'A frente ainda não está em Pronto: o ciclo de ' . $month((string)$line['cycle_ym']) . ' continua e o de ' . $month($ym) . ' começa quando ela lá chegar.',
        'nkey' => pipe2_notice_key('cycle', $dealId, $lineId, null, $ym),
    ]);
}

// ── o pagamento final (D16) ─────────────────────────────────

/** O pagamento validado é o final de um negócio v2: tarefa de pagamento numa etapa do negócio com role 'delivery'. */
function pipe2_payment_is_final(PDO $pdo, array $deal, ?array $task): bool {
    if (!$task || (string)($task['doc_kind'] ?? '') !== 'payment' || empty($task['stage_id'])) return false;
    if (!pipe2_deal_is_v2($pdo, $deal)) return false;
    $s = stage_get($pdo, (int)$task['stage_id']);
    return $s !== null && ($s['level'] ?? 'deal') === 'deal' && ($s['role'] ?? '') === 'delivery';
}

// ── «Pedir fatura» pela tarefa (T3) ─────────────────────────

/**
 * O pedido ao contabilista ($src de fin_request_invoice) de uma tarefa de fatura aberta deste negócio v2 (relida do
 * servidor). O cliente — NIF, nome, email (billing_deal) e morada — vem sempre da base, nunca de $in; de $in só o valor
 * (base, vat_rate, vat, total), a descrição, o vencimento, a prestação, as notas e «sem NIF» (só se o cliente não tiver
 * NIF). Recusa um negócio v1, uma tarefa de outro negócio, que não seja de fatura ou que já esteja feita.
 */
function pipe2_invoice_src(PDO $pdo, array $deal, array $task, array $in): array {
    $dealId = (int)($deal['id'] ?? 0);
    $fresh = $dealId > 0 ? deal_get($pdo, $dealId) : null;
    if (!$fresh || !pipe2_deal_is_v2($pdo, $fresh)) throw new RuntimeException('Este negócio não é da Pipeline v2.');
    $t = !empty($task['id']) ? task_by_id($pdo, (int)$task['id']) : null;
    if (!$t || (int)($t['deal_id'] ?? 0) !== $dealId) throw new RuntimeException('Esta tarefa não é deste negócio.');
    if ((string)($t['doc_kind'] ?? '') !== 'invoice') throw new RuntimeException('Esta tarefa não é um pedido de fatura.');
    if ((string)$t['status'] === 'done') throw new RuntimeException('Esta tarefa já está feita.');
    require_once __DIR__ . '/billing.php';   // billing_deal (e finance.php: vat_rate)
    $c = billing_deal($pdo, $dealId);
    if (!$c) throw new RuntimeException('Este negócio ainda não tem código.');
    $address = '';
    if (!empty($c['contact_id'])) {
        $st = $pdo->prepare('SELECT address FROM contacts WHERE id = ?');
        $st->execute([(int)$c['contact_id']]);
        $address = (string)($st->fetchColumn() ?: '');
    }
    $txt = fn(string $k, int $max) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);
    $cycle = (string)($t['cycle_ym'] ?? '');
    $desc = $txt('description', 500);
    if ($desc === '') {
        $desc = (string)$c['name'] . ((string)($c['job_type'] ?? '') !== '' ? ' — ' . $c['job_type'] : '')
              . ($cycle !== '' ? ' — mensalidade de ' . date('m/Y', strtotime($cycle . '-01')) : '');
    }
    $due = (string)($in['due_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = date('Y-m-d', strtotime(today_lisbon() . ' +' . (int)setting($pdo, 'fin_terms_days', 30) . ' days'));
    $src = [
        'scope' => 'deal', 'deal_id' => $dealId, 'contact_id' => !empty($c['contact_id']) ? (int)$c['contact_id'] : null,
        'party_name' => (string)($c['contact_name'] ?? ''), 'party_nif' => (string)($c['contact_nif'] ?? ''), 'party_email' => (string)($c['contact_email'] ?? ''),
        'party_address' => $address,
        'base' => $in['base'] ?? '', 'vat_rate' => $in['vat_rate'] ?? ((int)($c['has_vat'] ?? 0) === 1 ? vat_rate($pdo) : 0),
        'description' => $desc, 'due_date' => $due, 'installment' => $txt('installment', 60), 'notes' => $txt('notes', 2000),
    ];
    foreach (['vat', 'total'] as $k) if (array_key_exists($k, $in) && trim((string)$in[$k]) !== '') $src[$k] = $in[$k];
    if ($src['party_nif'] === '' && !empty($in['no_nif'])) $src['no_nif'] = 1;
    return $src;
}

/**
 * «Pedir fatura» de uma tarefa (D24): pipe2_invoice_src, recusa um 2.º pedido para a mesma tarefa, fin_request_invoice
 * (cria o lançamento «Fatura pedida» e envia ao contabilista; se o envio falhar, nada fica) e grava a tarefa em
 * fin_docs.task_id. Dentro do lock do negócio. Devolve ['id' => lançamento, 'code' => 'FAT-…'] (nunca o NIF). As
 * permissões verifica-as quem chama (pipe2_can_request_invoice).
 */
function pipe2_invoice_request(PDO $pdo, array $deal, array $task, array $in, ?int $by = null): array {
    $dealId = (int)($deal['id'] ?? 0);
    pipe2_lock_deal($pdo, $dealId);
    try {
        $src = pipe2_invoice_src($pdo, $deal, $task, $in);
        if (!db_has_column($pdo, 'fin_docs', 'task_id')) throw new RuntimeException('O Financeiro ainda não aceita pedidos por tarefa.');
        $taskId = (int)$task['id'];
        $st = $pdo->prepare("SELECT req_code FROM fin_docs WHERE task_id = ? AND deleted_at IS NULL AND status <> 'void' ORDER BY id LIMIT 1");
        $st->execute([$taskId]);
        $have = $st->fetchColumn();
        $st->closeCursor();
        if ($have !== false) throw new RuntimeException('Já há um pedido de fatura para esta tarefa (' . $have . ').');
        require_once __DIR__ . '/fin.php';
        require_once __DIR__ . '/fin_mail.php';
        [$id, $code] = fin_request_invoice($pdo, $src, $by);
        $pdo->prepare('UPDATE fin_docs SET task_id = ? WHERE id = ?')->execute([$taskId, (int)$id]);
        return ['id' => (int)$id, 'code' => (string)$code];
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/** O estado do pedido de fatura de uma tarefa (o mais recente que não esteja anulado): código FIN_ST, rótulo e código do pedido; sem NIF nem valores. */
function pipe2_invoice_state(PDO $pdo, int $taskId): ?array {
    if ($taskId <= 0 || !db_has_table($pdo, 'fin_docs') || !db_has_column($pdo, 'fin_docs', 'task_id')) return null;
    $st = $pdo->prepare("SELECT status, req_code FROM fin_docs WHERE task_id = ? AND deleted_at IS NULL ORDER BY CASE WHEN status = 'void' THEN 1 ELSE 0 END, id DESC LIMIT 1");
    $st->execute([$taskId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    if (!$r) return null;
    require_once __DIR__ . '/fin.php';   // FIN_ST
    $s = (string)$r['status'];
    return ['status' => $s, 'label' => FIN_ST[$s] ?? $s, 'code' => (string)$r['req_code']];
}
