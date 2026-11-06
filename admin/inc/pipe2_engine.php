<?php
/**
 * topper. admin — Pipeline v2: motor das frentes
 *
 * Avanço automático (D7, nota 4). Uma sub-etapa completa-se quando todas as obrigatórias da frente nessa sub-etapa e
 * no ciclo atual estão feitas e, com unidades previstas, as feitas chegam às previstas (pipe2_step_gate). A frente
 * entra sozinha na sub-etapa viva seguinte só se a sub-etapa tiver pelo menos uma obrigatória ou unidades previstas
 * (E3), os interruptores deixarem (D5: funil ∧ sub-etapa ∧ frente, NULL na frente = segue), a sub-etapa não estiver
 * suspensa pelo «Desfazer» (D14) e for a primeira vez no pedido (pipe2_once). Um passo de cada vez: entrar numa
 * sub-etapa nunca reavalia a seguinte no mesmo pedido.
 *
 * O movimento (pipe2_front_enter) é um UPDATE condicional (estado e sub-etapa esperados) dentro do lock do negócio
 * (pipe2_lock_deal, reentrante): só quem o ganha regista e corre as regras; os automáticos ficam como «Sistema»
 * (user_id NULL, também com sessão) com a nota «Avanço automático». Os movimentos de frente gravam
 * deal_history 'p2_front' (ou 'p2_wait' ao ficar À espera) e stage_events com a frente — nunca 'stage', por isso não
 * mexem no orçamento, nos passos do negócio nem no aviso «Gate de produção passado».
 *
 * Dependências (D8). Uma frente que vai entrar numa sub-etapa Y com ligações por cumprir fica «À espera» (depwait) na
 * sub-etapa que concluiu (stage_id NULL se Y for a 1.ª). «A conclui X» = A sai de X para a frente (também quando fica
 * À espera no fim de X): as ligações de (A, X) ficam cumpridas e cada B À espera sem mais nenhuma por cumprir entra em
 * Y (pipe2_front_release, «Sistema», com as regras dep_met e um aviso ao responsável de B).
 *
 * Repetições (D18, §34.11 T4). Quando a frente conclui uma sub-etapa (avanço, para a frente à mão ou forçado, À
 * espera, Pronto final, ciclo novo), as instâncias abertas das séries dessa frente, sub-etapa e ciclo fecham pelo
 * sistema na mesma transação (UPDATE direto e task_log sem utilizador, sem deal_log nem repetição); com um ciclo novo,
 * as de todas as sub-etapas do ciclo antigo. Para trás não fecha.
 *
 * Reavaliação (E4). pipe2_task_changed corre depois de fechar, reabrir, apagar ou mudar a obrigatória de uma tarefa de
 * um negócio v2 (ganchos em task_done, task_move, calendar.php e pipe2_task_set_gate); nunca lança, nunca recua a
 * frente e nunca anula a ação que o chamou. Num negócio v1 sai antes de escrever ou de tomar o lock.
 *
 * Registo do pedido (D13). pipe2_moves() guarda cada movimento com o que criou ou mudou, para o «Anular» em cascata
 * (tarefa 15); pipe2_note() as mensagens para quem chamou (texto pt-PT, sem «§»); pipe2_request_reset() zera os dois
 * e o pipe2_once (um pedido HTTP começa sempre vazio; os testes e o relógio chamam-no entre «pedidos»).
 *
 * Nunca decide por permissões nem pela sessão: o cron e o portal não têm sessão. As exceções são as da v1: forçar uma
 * passagem exige is_admin(). As permissões das ações humanas ficam nos pontos de entrada (fase 2).
 *
 * Lock e transações: quem abre uma transação dentro do lock faz commit (ou rollback) antes de o soltar, para o pedido
 * seguinte ler o que ficou gravado. pipe2_front_enter abre a sua se não houver uma aberta; com uma aberta (quem chamou),
 * escreve nela.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_fronts.php';    // pipe2_front_get, pipe2_front_stages, pipe2_deal_is_v2, pipe2_deal_stage, pipe2_user_ok, avença
require_once __DIR__ . '/pipe2_deps.php';      // pipe2_deps_into, pipe2_deps_mark_met, pipe2_dep_service, pipe2_plan_front
require_once __DIR__ . '/pipe2_tasks.php';     // pipe2_step_gate, pipe2_deal_gate, pipe2_run_rules
require_once __DIR__ . '/pipe2_notify.php';    // pipe2_notice_add
require_once __DIR__ . '/pipeline.php';        // deal_get, stage_get
require_once __DIR__ . '/pipeline_flow.php';   // GateBlocked
require_once __DIR__ . '/deals.php';           // deal_log, today_lisbon
require_once __DIR__ . '/metrics.php';         // mx_event, metrics_ready

const PIPE2_AUTO_NOTE = 'Avanço automático';
const PIPE2_CLOSED_NOTE = 'Fechada pelo sistema: a frente saiu da sub-etapa';
const PIPE2_LOCK_BUSY = 'Outro pedido está a mexer neste negócio; tente outra vez.';
const PIPE2_FRONT_CHANGED = 'A frente mudou entretanto; recarregue a página.';

// ── o pedido ────────────────────────────────────────────────

/** O estado do pedido (pipe2_once, pipe2_moves, pipe2_note); nasce vazio. */
function &pipe2_req(): array {
    if (!isset($GLOBALS['PIPE2_REQ']) || !is_array($GLOBALS['PIPE2_REQ'])) $GLOBALS['PIPE2_REQ'] = ['once' => [], 'moves' => [], 'note' => [], 'last' => []];
    return $GLOBALS['PIPE2_REQ'];
}

/** Zera pipe2_once, pipe2_moves e pipe2_note (um pedido novo). */
function pipe2_request_reset(): void {
    $GLOBALS['PIPE2_REQ'] = ['once' => [], 'moves' => [], 'note' => [], 'last' => []];
}

/** true só da 1.ª vez por pedido. */
function pipe2_once(string $key): bool {
    $r = &pipe2_req();
    if (isset($r['once'][$key])) return false;
    $r['once'][$key] = true;
    return true;
}

/** O registo dos movimentos do pedido (D13); com $add, acrescenta-o. */
function pipe2_moves(?array $add = null): array {
    $r = &pipe2_req();
    if ($add !== null) $r['moves'][] = $add;
    return $r['moves'];
}

/** As mensagens para quem chamou (pt-PT); com $add, acrescenta-a (sem repetir). */
function pipe2_note(?string $add = null): array {
    $r = &pipe2_req();
    if ($add !== null && trim($add) !== '' && !in_array($add, $r['note'], true)) $r['note'][] = $add;
    return $r['note'];
}

/** Acrescenta um movimento e lembra-o como o último da frente (para os acrescentos de quem chamou). */
function pipe2_move_add(array $entry): int {
    $r = &pipe2_req();
    $r['moves'][] = $entry;
    $i = count($r['moves']) - 1;
    if (!empty($entry['line'])) $r['last'][(int)$entry['line']] = $i;
    return $i;
}

/** Junta $items à lista $key do último movimento registado da frente (se houver). */
function pipe2_move_append(int $lineId, string $key, array $items): void {
    if (!$items) return;
    $r = &pipe2_req();
    $i = $r['last'][$lineId] ?? null;
    if ($i === null || !isset($r['moves'][$i])) return;
    $r['moves'][$i][$key] = array_merge($r['moves'][$i][$key] ?? [], array_values($items));
}

// ── lock do negócio ─────────────────────────────────────────

function pipe2_lock_key(PDO $pdo, int $dealId): string {
    return spl_object_id($pdo) . ':' . $dealId;
}

/**
 * GET_LOCK('topper_p2d_' . md5(DATABASE()) . '_' . id, 10), reentrante: um contador por negócio e ligação no processo,
 * e só o 1.º nível faz GET_LOCK. Sem o lock a tempo → RuntimeException (PIPE2_LOCK_BUSY). Em SQLite só conta.
 */
function pipe2_lock_deal(PDO $pdo, int $dealId): void {
    $k = pipe2_lock_key($pdo, $dealId);
    $GLOBALS['PIPE2_LOCKS'] ??= [];
    if (isset($GLOBALS['PIPE2_LOCKS'][$k])) {
        $GLOBALS['PIPE2_LOCKS'][$k]['n']++;
        return;
    }
    if (!db_is_sqlite($pdo)) {
        $st = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $st->execute([pipe2_lock_name($pdo, $dealId)]);
        $got = $st->fetchColumn();
        $st->closeCursor();
        if ((string)$got !== '1') throw new RuntimeException(PIPE2_LOCK_BUSY);
    }
    $GLOBALS['PIPE2_LOCKS'][$k] = ['n' => 1, 'tx' => $pdo->inTransaction()];
}

/**
 * Desce o contador; só o nível de fora faz RELEASE_LOCK. Uma transação aberta dentro do lock e ainda por fechar é um
 * erro de quem a abriu (fica no error_log): a regra é fazer commit (ou rollback) antes de soltar.
 */
function pipe2_unlock_deal(PDO $pdo, int $dealId): void {
    $k = pipe2_lock_key($pdo, $dealId);
    if (!isset($GLOBALS['PIPE2_LOCKS'][$k])) return;
    if (--$GLOBALS['PIPE2_LOCKS'][$k]['n'] > 0) return;
    $hadTx = $GLOBALS['PIPE2_LOCKS'][$k]['tx'];
    unset($GLOBALS['PIPE2_LOCKS'][$k]);
    if (!$hadTx && $pdo->inTransaction()) error_log('[topper] pipe2 lock: o negócio ' . $dealId . ' foi solto com uma transação aberta dentro do lock');
    if (db_is_sqlite($pdo)) return;
    try {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([pipe2_lock_name($pdo, $dealId)]);
    } catch (Throwable $e) {
        error_log('[topper] pipe2 lock: ' . $e->getMessage());
    }
}

function pipe2_lock_name(PDO $pdo, int $dealId): string {
    return 'topper_p2d_' . md5((string)$pdo->query('SELECT DATABASE()')->fetchColumn()) . '_' . $dealId;
}

// ── sub-etapas e passos ─────────────────────────────────────

/** Os interruptores (D5): funil ∧ sub-etapa ∧ frente (NULL = segue). Um 1 na frente não liga o que os outros desligam. */
function pipe2_auto_on(PDO $pdo, array $line, array $stage): bool {
    $st = $pdo->prepare('SELECT auto_advance FROM funnels WHERE id = ?');
    $st->execute([(int)$line['funnel_id']]);
    $f = $st->fetchColumn();
    $st->closeCursor();
    if ($f === false || (int)$f !== 1) return false;
    if (!array_key_exists('auto_advance', $stage)) $stage = stage_get($pdo, (int)$stage['id']) ?? [];
    if ((int)($stage['auto_advance'] ?? 0) !== 1) return false;
    $l = $line['auto_advance'] ?? null;
    return $l === null || $l === '' || (int)$l === 1;
}

/** A linha de passos (frente, sub-etapa, ciclo), a primeira se houver duas; null se não existir. */
function pipe2_step_find(PDO $pdo, int $lineId, int $stageId, string $cycle): ?array {
    $st = $pdo->prepare('SELECT * FROM deal_line_steps WHERE line_id = ? AND stage_id = ? AND cycle_ym = ? ORDER BY id LIMIT 1');
    $st->execute([$lineId, $stageId, $cycle]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    return $r ?: null;
}

/**
 * A linha de passos (frente, sub-etapa, ciclo) e se nasceu agora. O ciclo é o de $line['cycle_ym'] (quem chama passa
 * o ciclo novo, num recomeço). Com $create, dentro do lock do negócio (deal_line_steps não tem UNIQUE): SELECT e, sem
 * linha, INSERT com o plan_days da sub-etapa.
 */
function pipe2_step_row_ex(PDO $pdo, array $line, int $stageId, bool $create = true): array {
    $lineId = (int)($line['id'] ?? 0);
    if ($lineId <= 0 || $stageId <= 0) return [null, false];
    $cycle = (string)($line['cycle_ym'] ?? '');
    if (!$create) return [pipe2_step_find($pdo, $lineId, $stageId, $cycle), false];
    $fresh = pipe2_front_get($pdo, $lineId);
    if (!$fresh) return [null, false];
    if (!array_key_exists('cycle_ym', $line)) $cycle = (string)$fresh['cycle_ym'];
    $dealId = (int)$fresh['deal_id'];
    pipe2_lock_deal($pdo, $dealId);
    try {
        $row = pipe2_step_find($pdo, $lineId, $stageId, $cycle);
        if ($row) return [$row, false];
        $st = $pdo->prepare('SELECT plan_days FROM funnel_stages WHERE id = ?');
        $st->execute([$stageId]);
        $pd = $st->fetchColumn();
        $st->closeCursor();
        $pdo->prepare('INSERT INTO deal_line_steps (deal_id, line_id, stage_id, cycle_ym, plan_days, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$dealId, $lineId, $stageId, $cycle, $pd === false || $pd === null ? null : (int)$pd, date('Y-m-d H:i:s')]);
        return [pipe2_step_find($pdo, $lineId, $stageId, $cycle), true];
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/** A linha de passos (frente, sub-etapa, ciclo da frente); com $create toma o lock e cria-a se faltar. */
function pipe2_step_row(PDO $pdo, array $line, int $stageId, bool $create = true): ?array {
    return pipe2_step_row_ex($pdo, $line, $stageId, $create)[0];
}

/** A sub-etapa viva seguinte pela posição; sem sub-etapa → a 1.ª; de Pronto (role 'ready') → null. */
function pipe2_next_stage(PDO $pdo, array $line): ?array {
    $stages = pipe2_front_stages($pdo, (int)$line['funnel_id']);
    if (!$stages) return null;
    if (empty($line['stage_id'])) return $stages[0];
    $cur = stage_get($pdo, (int)$line['stage_id']);
    if (!$cur || ($cur['role'] ?? '') === 'ready') return null;
    foreach ($stages as $s) {
        if ([(int)$s['position'], (int)$s['id']] > [(int)$cur['position'], (int)$cur['id']]) return $s;
    }
    return null;
}

/** A sub-etapa viva anterior pela posição; na 1.ª (ou sem sub-etapa) → null. */
function pipe2_prev_stage(PDO $pdo, array $line): ?array {
    if (empty($line['stage_id'])) return null;
    $cur = stage_get($pdo, (int)$line['stage_id']);
    if (!$cur) return null;
    $prev = null;
    foreach (pipe2_front_stages($pdo, (int)$line['funnel_id']) as $s) {
        if ([(int)$s['position'], (int)$s['id']] < [(int)$cur['position'], (int)$cur['id']]) $prev = $s;
    }
    return $prev;
}

/** O nome do sítio onde a frente está: a sub-etapa, senão o estado (Por começar, À espera…). */
function pipe2_front_place(?array $stage, array $line): string {
    if ($stage) return (string)$stage['name'];
    return PIPE2_LINE_STATUS[(string)($line['status'] ?? '')] ?? (string)($line['status'] ?? '');
}

/** O maior id de uma tabela com deal_id, para o negócio (0 sem linhas). */
function pipe2_max_id(PDO $pdo, string $table, int $dealId): int {
    $st = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM $table WHERE deal_id = ?");
    $st->execute([$dealId]);
    $v = (int)$st->fetchColumn();
    $st->closeCursor();
    return $v;
}

/** Os ids de uma tabela com deal_id, do negócio, depois de $after. */
function pipe2_ids_after(PDO $pdo, string $table, int $dealId, int $after): array {
    $st = $pdo->prepare("SELECT id FROM $table WHERE deal_id = ? AND id > ? ORDER BY id");
    $st->execute([$dealId, $after]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Fecha pelo sistema as instâncias abertas das séries da frente (D18): a sub-etapa $stageId (null = todas) e o ciclo
 * $cycle. UPDATE direto e task_log sem utilizador, sem deal_log, sem repetição e sem reavaliar. Devolve
 * [[id, status antes, done_at antes, id do task_log], …].
 */
function pipe2_close_series(PDO $pdo, int $lineId, ?int $stageId, string $cycle, string $now): array {
    $sql = "SELECT id, status, done_at FROM tasks WHERE line_id = ? AND cycle_ym = ? AND repeat_rule <> '' AND status <> 'done'";
    $args = [$lineId, $cycle];
    if ($stageId !== null) { $sql .= ' AND stage_id = ?'; $args[] = $stageId; }
    $st = $pdo->prepare($sql . ' ORDER BY id');
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $up = $pdo->prepare("UPDATE tasks SET status = 'done', done_at = ?, updated_at = ? WHERE id = ? AND status <> 'done'");
    $hasLog = db_has_table($pdo, 'task_log');
    $log = $hasLog ? $pdo->prepare("INSERT INTO task_log (task_id, event, note, user_id, created_at) VALUES (?, 'done', ?, NULL, ?)") : null;
    $out = [];
    foreach ($rows as $r) {
        $up->execute([$now, $now, (int)$r['id']]);
        if ($up->rowCount() !== 1) continue;
        $logId = null;
        if ($log) {
            $log->execute([(int)$r['id'], PIPE2_CLOSED_NOTE, $now]);
            $logId = (int)$pdo->lastInsertId();
        }
        $out[] = [(int)$r['id'], (string)$r['status'], $r['done_at'], $logId];
    }
    return $out;
}

// ── o movimento ─────────────────────────────────────────────

/**
 * A frente entra na sub-etapa $to (D7, D8, D9, D18). $o: by (?int; null = Sistema), why (auto|manual|start|dep|undo|
 * cycle|force|cancel), note, status (sobrepõe o estado novo), cycle (ciclo novo), rules (true), record (true: regista
 * em pipe2_moves). Corre dentro do lock do negócio e relê a frente: se já não está onde $line diz (sub-etapa e
 * estado), 'lost' sem gravar nada. Com ligações por cumprir para $to (e why fora de force, undo, cycle, cancel) fica À
 * espera → 'waiting'; senão entra → 'entered'. Ver o cabeçalho para o resto.
 */
function pipe2_front_enter(PDO $pdo, array $deal, array $line, array $to, array $o = []): string {
    $lineId = (int)($line['id'] ?? 0);
    $dealId = (int)($deal['id'] ?? ($line['deal_id'] ?? 0));
    if ($lineId <= 0 || $dealId <= 0 || empty($to['id'])) return 'lost';
    pipe2_lock_deal($pdo, $dealId);
    try {
        return pipe2_front_enter_locked($pdo, $dealId, $line, (int)$to['id'], $o);
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

function pipe2_front_enter_locked(PDO $pdo, int $dealId, array $line, int $toId, array $o): string {
    $lineId = (int)$line['id'];
    $by = isset($o['by']) && $o['by'] !== null && (int)$o['by'] > 0 ? (int)$o['by'] : null;
    $why = (string)($o['why'] ?? 'auto');
    $cur = pipe2_front_get($pdo, $lineId);
    if (!$cur || (int)$cur['deal_id'] !== $dealId) return 'lost';
    if ((int)($cur['stage_id'] ?? 0) !== (int)($line['stage_id'] ?? 0) || (string)$cur['status'] !== (string)($line['status'] ?? '')) return 'lost';
    $to = stage_get($pdo, $toId);
    if (!$to || (int)$to['funnel_id'] !== (int)$cur['funnel_id'] || ($to['level'] ?? '') !== 'front') throw new RuntimeException('Etapa inválida.');
    $deal = deal_get($pdo, $dealId) ?? ['id' => $dealId];
    $from = !empty($cur['stage_id']) ? stage_get($pdo, (int)$cur['stage_id']) : null;
    $cycleOld = (string)$cur['cycle_ym'];
    $cycleNew = isset($o['cycle']) && (string)$o['cycle'] !== '' ? (string)$o['cycle'] : $cycleOld;
    $newCycle = $cycleNew !== $cycleOld;
    $cmp = $from ? ([(int)$to['position'], (int)$to['id']] <=> [(int)$from['position'], (int)$from['id']]) : 1;
    $back = $why === 'undo' || ($cmp < 0 && !$newCycle);
    $svc = pipe2_dep_service($cur);
    $now = date('Y-m-d H:i:s');
    $today = today_lisbon();
    $expStage = (int)($cur['stage_id'] ?? 0);
    $expStatus = (string)$cur['status'];
    $hist0 = pipe2_max_id($pdo, 'deal_history', $dealId);
    $ev0 = metrics_ready($pdo) ? pipe2_max_id($pdo, 'stage_events', $dealId) : 0;
    $fromEntry = [$from ? (int)$from['id'] : null, $expStatus, $cur['stage_entered_at'], $cur['done_at'], $cycleOld, $cur['started_at']];

    // (1) À espera: ligações por cumprir para $to
    $deps = (!$back && !in_array($why, ['force', 'undo', 'cycle', 'cancel'], true)) ? pipe2_deps_into($pdo, $lineId, $toId) : [];
    if ($deps) {
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("UPDATE deal_lines SET status = 'depwait', started_at = COALESCE(started_at, ?) WHERE id = ? AND COALESCE(stage_id, 0) = ? AND status = ?");
            $st->execute([$now, $lineId, $expStage, $expStatus]);
            if ($st->rowCount() !== 1) {
                if ($own) $pdo->rollBack();
                return 'lost';
            }
            $steps = [];
            $closed = [];
            if ($from) {
                $r = pipe2_step_find($pdo, $lineId, (int)$from['id'], $cycleOld);
                if ($r) {
                    $steps[] = [(int)$r['id'], ['done_at' => $r['done_at']]];
                    $pdo->prepare('UPDATE deal_line_steps SET done_at = ? WHERE id = ?')->execute([$now, (int)$r['id']]);
                }
                $closed = pipe2_close_series($pdo, $lineId, (int)$from['id'], $cycleOld, $now);
            }
            $waitFor = implode(', ', array_map(fn($d) => $d['from_service'] . ' («' . (string)$d['from_stage_name'] . '»)', $deps));
            deal_log($pdo, $dealId, 'p2_wait', ['from_stage' => $from ? (int)$from['id'] : null, 'to_stage' => $toId, 'to_funnel' => (int)$cur['funnel_id'],
                                                'note' => $svc . ': à espera de ' . $waitFor, 'user_id' => null, 'system' => true]);
            if (metrics_ready($pdo)) {
                mx_event($pdo, $dealId, 'p2_wait', ['line' => $lineId, 'funnel' => (int)$cur['funnel_id'], 'from' => $from ? (int)$from['id'] : null, 'to' => $toId,
                                                    'user' => null, 'reason' => $why]);
            }
            $pdo->prepare('UPDATE deals SET last_touch = ? WHERE id = ?')->execute([$today, $dealId]);
            if ($own) $pdo->commit();
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $GLOBALS['XSELL_FRESH'] = true;
        if ($o['record'] ?? true) {
            pipe2_move_add(['kind' => 'front', 'deal' => $dealId, 'line' => $lineId, 'from' => $fromEntry, 'to' => [$from ? (int)$from['id'] : null, 'depwait'],
                            'to_cycle' => $cycleOld, 'why' => $why, 'by' => null, 'waiting_for' => $toId, 'steps' => $steps, 'steps_new' => [], 'tasks' => [],
                            'closed' => $closed, 'history' => pipe2_ids_after($pdo, 'deal_history', $dealId, $hist0),
                            'events' => metrics_ready($pdo) ? pipe2_ids_after($pdo, 'stage_events', $dealId, $ev0) : [], 'deps' => [], 'notices' => [], 'undoable' => true]);
        }
        if ($from) pipe2_front_release_from($pdo, $dealId, $lineId, (int)$from['id']);
        return 'waiting';
    }

    // (2) entra
    $status = isset($o['status']) && (string)$o['status'] !== '' ? (string)$o['status'] : null;
    if ($status === null) {
        $monthly = pipe2_line_is_monthly($cur);
        if (($to['role'] ?? '') === 'ready') $status = $monthly && !pipe2_billing_ended($cur, $today) ? 'ongoing' : 'ready';
        else $status = $monthly ? 'ongoing' : 'running';
    }
    $final = $status === 'ready';
    $same = $from && (int)$from['id'] === $toId && !$newCycle;
    $leaving = $from && !$back && ($cmp > 0 || $newCycle || ($same && $final));
    $steps = [];
    $stepsNew = [];
    $closed = [];
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE deal_lines SET stage_id = ?, status = ?, stage_entered_at = ?, started_at = COALESCE(started_at, ?), done_at = ?, cycle_ym = ?
                              WHERE id = ? AND COALESCE(stage_id, 0) = ? AND status = ?');
        $st->execute([$toId, $status, $now, $now, $final ? $now : null, $cycleNew, $lineId, $expStage, $expStatus]);
        if ($st->rowCount() !== 1) {
            if ($own) $pdo->rollBack();
            return 'lost';
        }
        // passos: a de onde sai fica concluída; a nova começa (para trás, volta a estar por concluir)
        if ($leaving && !$same) {
            $r = pipe2_step_find($pdo, $lineId, (int)$from['id'], $cycleOld);
            if ($r) {
                $steps[] = [(int)$r['id'], ['done_at' => $r['done_at']]];
                $pdo->prepare('UPDATE deal_line_steps SET done_at = ? WHERE id = ?')->execute([$now, (int)$r['id']]);
            }
        }
        if (!$same || $back) {
            [$row, $created] = pipe2_step_row_ex($pdo, ['cycle_ym' => $cycleNew] + $cur, $toId);
            if ($row) {
                if ($created) $stepsNew[] = (int)$row['id'];
                else $steps[] = [(int)$row['id'], ['started_at' => $row['started_at'], 'done_at' => $row['done_at']]];
                $pdo->prepare('UPDATE deal_line_steps SET started_at = COALESCE(started_at, ?), done_at = NULL WHERE id = ?')->execute([$now, (int)$row['id']]);
            }
        } elseif ($final) {
            $r = pipe2_step_find($pdo, $lineId, $toId, $cycleOld);
            if ($r) {
                $steps[] = [(int)$r['id'], ['done_at' => $r['done_at']]];
                $pdo->prepare('UPDATE deal_line_steps SET done_at = ? WHERE id = ?')->execute([$now, (int)$r['id']]);
            }
        }
        // as séries da sub-etapa concluída (D18); com um ciclo novo, as do ciclo antigo em todas as sub-etapas
        if ($leaving) $closed = pipe2_close_series($pdo, $lineId, $newCycle ? null : (int)$from['id'], $cycleOld, $now);
        $note = $svc . ': ' . pipe2_front_place($from, $cur) . ' → ' . (string)$to['name'] . ($by === null ? ' · ' . PIPE2_AUTO_NOTE : '');
        $extra = trim((string)($o['note'] ?? ''));
        if ($extra !== '') $note .= ' · ' . $extra;
        deal_log($pdo, $dealId, 'p2_front', ['from_stage' => $from ? (int)$from['id'] : null, 'to_stage' => $toId, 'to_funnel' => (int)$cur['funnel_id'],
                                             'note' => $note, 'user_id' => $by, 'system' => $by === null]);
        if (metrics_ready($pdo)) {
            mx_event($pdo, $dealId, 'p2_front', ['line' => $lineId, 'funnel' => (int)$cur['funnel_id'], 'from' => $from ? (int)$from['id'] : null, 'to' => $toId,
                                                 'user' => $by, 'reason' => $why]);
        }
        $pdo->prepare('UPDATE deals SET last_touch = ? WHERE id = ?')->execute([$today, $dealId]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $GLOBALS['XSELL_FRESH'] = true;

    // (4) as regras da sub-etapa (nunca desfazem o movimento)
    $fresh = pipe2_front_get($pdo, $lineId) ?? $cur;
    $tasks = [];
    $notices = [];
    if ($o['rules'] ?? true) {
        $trigs = ['f_enter'];
        if (($to['role'] ?? '') === 'ready') $trigs[] = 'f_ready';
        if ($why === 'dep') $trigs[] = 'dep_met';
        foreach ($trigs as $trig) {
            try {
                $res = pipe2_run_rules($pdo, deal_get($pdo, $dealId) ?? $deal, $to, $trig, $fresh, $by);
                array_push($tasks, ...$res['tasks']);
                array_push($notices, ...$res['notices']);
            } catch (Throwable $e) {
                error_log('[topper] pipe2 regras ' . $trig . ' (negócio ' . $dealId . '): ' . $e->getMessage());
            }
        }
    }
    if ($why === 'dep') {
        try {
            $uid = pipe2_user_ok($pdo, $fresh['exec_owner_id'] ?? null) ?? pipe2_user_ok($pdo, $deal['exec_owner_id'] ?? null);
            $n = pipe2_notice_add($pdo, ['deal_id' => $dealId, 'line_id' => $lineId, 'stage_id' => $toId, 'cycle_ym' => $cycleNew, 'kind' => 'dep_met', 'user_id' => $uid,
                                         'title' => $svc . ': dependência cumprida', 'body' => 'A frente pode avançar para «' . (string)$to['name'] . '».']);
            if ($n !== null) $notices[] = $n;
        } catch (Throwable $e) {
            error_log('[topper] pipe2 aviso dep_met (negócio ' . $dealId . '): ' . $e->getMessage());
        }
    }

    // (5) o registo do pedido
    if ($o['record'] ?? true) {
        pipe2_move_add(['kind' => 'front', 'deal' => $dealId, 'line' => $lineId, 'from' => $fromEntry, 'to' => [$toId, $status], 'to_cycle' => $cycleNew,
                        'why' => $why, 'by' => $by, 'steps' => $steps, 'steps_new' => $stepsNew, 'tasks' => $tasks, 'closed' => $closed,
                        'history' => pipe2_ids_after($pdo, 'deal_history', $dealId, $hist0),
                        'events' => metrics_ready($pdo) ? pipe2_ids_after($pdo, 'stage_events', $dealId, $ev0) : [], 'deps' => [], 'notices' => $notices,
                        'undoable' => true]);
    }

    // (3) A concluiu X: as ligações cumprem-se e as frentes que só esperavam por elas entram
    if ($leaving && $from && !$newCycle) pipe2_front_release_from($pdo, $dealId, $lineId, (int)$from['id']);
    return 'entered';
}

/** A frente concluiu a sub-etapa: liberta as que esperavam (dentro do lock de quem chama); nunca lança. */
function pipe2_front_release_from(PDO $pdo, int $dealId, int $lineId, int $stageId): array {
    try {
        $r = pipe2_front_release_inner($pdo, $dealId, $lineId, $stageId);
        pipe2_move_append($lineId, 'deps', array_map(fn($id) => [$id, null], $r['met']));
        return $r['entered'];
    } catch (Throwable $e) {
        error_log('[topper] pipe2 libertar (negócio ' . $dealId . '): ' . $e->getMessage());
        return [];
    }
}

function pipe2_front_release_inner(PDO $pdo, int $dealId, int $fromLineId, int $fromStageId): array {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $r = pipe2_deps_mark_met($pdo, $dealId, $fromLineId, $fromStageId);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $entered = [];
    foreach ($r['released'] as [$bId, $yId]) {
        $b = pipe2_front_get($pdo, (int)$bId);
        if (!$b || (int)$b['deal_id'] !== $dealId || $b['status'] !== 'depwait') continue;
        $next = pipe2_next_stage($pdo, $b);
        if (!$next || (int)$next['id'] !== (int)$yId) continue;
        if (pipe2_front_enter($pdo, deal_get($pdo, $dealId) ?? ['id' => $dealId], $b, $next, ['why' => 'dep']) === 'entered') $entered[] = (int)$bId;
    }
    return ['met' => $r['met'], 'entered' => $entered];
}

/**
 * A frente $fromLine concluiu a sub-etapa $fromStageId (D8): as ligações de (frente, sub-etapa) ficam cumpridas e as
 * frentes À espera sem mais nenhuma por cumprir entram na sub-etapa delas («Sistema», why 'dep'). Devolve os ids das
 * frentes que entraram.
 */
function pipe2_front_release(PDO $pdo, array $deal, array $fromLine, int $fromStageId): array {
    $dealId = (int)($deal['id'] ?? ($fromLine['deal_id'] ?? 0));
    $lineId = (int)($fromLine['id'] ?? 0);
    if ($dealId <= 0 || $lineId <= 0) return [];
    pipe2_lock_deal($pdo, $dealId);
    try {
        $line = pipe2_front_get($pdo, $lineId);
        if (!$line || (int)$line['deal_id'] !== $dealId) return [];
        $r = pipe2_front_release_inner($pdo, $dealId, $lineId, $fromStageId);
        pipe2_move_append($lineId, 'deps', array_map(fn($id) => [$id, null], $r['met']));
        return $r['entered'];
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

// ── a reavaliação ───────────────────────────────────────────

/**
 * Reavalia a frente (D7): À espera → entra se já não há ligações por cumprir ('released'; senão 'waiting'); fora de
 * Em curso/Ativa ou em Pronto → 'idle'; depois, por esta ordem, 'no_mandatory' (sem obrigatórias nem unidades),
 * 'incomplete', 'held' (suspensa pelo «Desfazer»), 'off' (interruptores), 'once' (já avançou neste pedido) e, por fim,
 * entra na seguinte ('advanced', ou 'waiting' se lá tiver uma dependência). Nunca recua.
 */
function pipe2_front_reevaluate(PDO $pdo, array $deal, array $line): string {
    $dealId = (int)($deal['id'] ?? ($line['deal_id'] ?? 0));
    $lineId = (int)($line['id'] ?? 0);
    if ($dealId <= 0 || $lineId <= 0) return 'idle';
    pipe2_lock_deal($pdo, $dealId);
    try {
        $deal = deal_get($pdo, $dealId);
        $line = pipe2_front_get($pdo, $lineId);
        if (!$deal || !$line || (int)$line['deal_id'] !== $dealId || $line['status'] === 'merged') return 'idle';
        if ($line['status'] === 'depwait') {
            $next = pipe2_next_stage($pdo, $line);
            if (!$next) return 'idle';
            if (pipe2_deps_into($pdo, $lineId, (int)$next['id'])) return 'waiting';
            $r = pipe2_front_enter($pdo, $deal, $line, $next, ['why' => 'dep']);
            return $r === 'entered' ? 'released' : ($r === 'waiting' ? 'waiting' : 'idle');
        }
        if (!in_array($line['status'], ['running', 'ongoing'], true) || empty($line['stage_id']) || ($line['stage_role'] ?? '') === 'ready') return 'idle';
        $stage = stage_get($pdo, (int)$line['stage_id']);
        if (!$stage) return 'idle';
        $gate = pipe2_step_gate($pdo, $line, $stage);
        if (!$gate['auto_ok']) return 'no_mandatory';
        if (!$gate['complete']) return 'incomplete';
        $step = pipe2_step_row($pdo, $line, (int)$stage['id'], false);
        if ($step && ($step['auto_hold_at'] ?? null) !== null) return 'held';
        if (!pipe2_auto_on($pdo, $line, $stage)) return 'off';
        $next = pipe2_next_stage($pdo, $line);
        if (!$next) return 'idle';
        if (!pipe2_once('front:' . $lineId)) return 'once';
        $r = pipe2_front_enter($pdo, $deal, $line, $next, ['why' => 'auto']);
        return $r === 'entered' ? 'advanced' : ($r === 'waiting' ? 'waiting' : 'idle');
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/**
 * Uma tarefa mudou (why: done|reopen|delete|gate; E4). Sai sem escrever se não tiver negócio ou o negócio não for v2.
 * Dentro do lock, relê o negócio e a frente; numa obrigatória (ou que deixou de o ser) de uma frente: tira a suspensão
 * da linha de passos (frente, sub-etapa, ciclo da tarefa) e, se a frente está nessa sub-etapa, reavalia-a. Depois
 * pipe2_after_front_change. Nunca lança (error_log) e nunca recua a frente.
 */
function pipe2_task_changed(PDO $pdo, array $task, string $why): void {
    $dealId = (int)($task['deal_id'] ?? 0);
    if ($dealId <= 0) return;
    try {
        $deal = deal_get($pdo, $dealId);
        if (!$deal || !pipe2_deal_is_v2($pdo, $deal)) return;
        pipe2_lock_deal($pdo, $dealId);
        try {
            $deal = deal_get($pdo, $dealId) ?? $deal;
            $lineId = (int)($task['line_id'] ?? 0);
            $stageId = (int)($task['stage_id'] ?? 0);
            $gate = (int)($task['is_gate'] ?? 0) === 1 || $why === 'gate';
            if ($lineId > 0 && $stageId > 0 && $gate && in_array($why, ['done', 'reopen', 'delete', 'gate'], true)) {
                $line = pipe2_front_get($pdo, $lineId);
                if ($line && (int)$line['deal_id'] === $dealId) {
                    $cycle = array_key_exists('cycle_ym', $task) ? (string)$task['cycle_ym'] : (string)$line['cycle_ym'];
                    $pdo->prepare("UPDATE deal_line_steps SET auto_hold_at = NULL, auto_hold_why = '' WHERE line_id = ? AND stage_id = ? AND cycle_ym = ? AND auto_hold_at IS NOT NULL")
                        ->execute([$lineId, $stageId, $cycle]);
                    if ((int)($line['stage_id'] ?? 0) === $stageId) pipe2_front_reevaluate($pdo, $deal, $line);
                }
            }
            pipe2_after_front_change($pdo, $dealId, $why);
        } finally {
            pipe2_unlock_deal($pdo, $dealId);
        }
    } catch (Throwable $e) {
        error_log('[topper] pipe2 tarefa ' . (int)($task['id'] ?? 0) . ' (' . $why . '): ' . $e->getMessage());
    }
}

/**
 * Depois de uma frente mudar (ou de uma tarefa de um negócio v2 mudar): o negócio reavalia-se (motor do negócio,
 * inc/pipe2_deal.php). Nunca lança nem anula a ação que o chamou (error_log).
 */
function pipe2_after_front_change(PDO $pdo, int $dealId, string $why): void {
    try {
        require_once __DIR__ . '/pipe2_deal.php';
        pipe2_deal_reevaluate($pdo, $dealId, $why);
    } catch (Throwable $e) {
        error_log('[topper] pipe2 negócio ' . $dealId . ' (' . $why . '): ' . $e->getMessage());
    }
}

// ── ações humanas (e do sistema) ────────────────────────────

/** Relê o negócio v2 e a frente dele (dentro do lock), ou lança. */
function pipe2_front_load(PDO $pdo, int $dealId, int $lineId): array {
    $deal = deal_get($pdo, $dealId);
    $line = pipe2_front_get($pdo, $lineId);
    if (!$deal || !$line || (int)$line['deal_id'] !== $dealId || $line['status'] === 'merged') throw new RuntimeException('Frente não encontrada.');
    if (!pipe2_deal_is_v2($pdo, $deal)) throw new RuntimeException('Este negócio não é da Pipeline v2.');
    return [$deal, $line];
}

/**
 * Começa uma frente (D6): Por começar (ou À espera sem sub-etapa), com o negócio no Kickoff — e o gate do Kickoff
 * cumprido, senão GateBlocked — ou em Em execução. Faz o plano a partir de hoje; numa avença grava o ciclo (mês de
 * início) e o fim do período, se ainda vazios. Entra na 1.ª sub-etapa (why 'start') ou fica À espera dela. Devolve
 * 'entered' | 'waiting' | 'lost'.
 */
function pipe2_front_start(PDO $pdo, array $deal, array $line, ?int $by = null): string {
    $dealId = (int)($deal['id'] ?? ($line['deal_id'] ?? 0));
    $lineId = (int)($line['id'] ?? 0);
    pipe2_lock_deal($pdo, $dealId);
    try {
        [$deal, $line] = pipe2_front_load($pdo, $dealId, $lineId);
        $svc = pipe2_dep_service($line);
        $waiting = $line['status'] === 'depwait' && empty($line['stage_id']);
        if ($line['status'] === 'planned') throw new RuntimeException('A frente ' . $svc . ' ainda não está no Kickoff.');
        if ($line['status'] !== 'queued' && !$waiting) throw new RuntimeException('A frente ' . $svc . ' já começou.');
        $kick = pipe2_deal_stage($pdo, (int)$deal['funnel_id'], 'kickoff');
        $exec = pipe2_deal_stage($pdo, (int)$deal['funnel_id'], 'exec');
        $at = (int)($deal['stage_id'] ?? 0);
        if ($deal['phase'] !== 'production' || !in_array($at, array_filter([(int)($kick['id'] ?? 0), (int)($exec['id'] ?? 0)]), true)) {
            throw new RuntimeException('As frentes começam no Kickoff ou em Em execução.');
        }
        if ($kick && $at === (int)$kick['id']) {
            $miss = array_values(array_map(fn($i) => (string)$i['label'], array_filter(pipe2_deal_gate($pdo, $deal, $kick), fn($i) => !$i['ok'])));
            if ($miss) throw new GateBlocked($miss, (string)$kick['name']);
        }
        $first = pipe2_next_stage($pdo, ['stage_id' => null] + $line);
        if (!$first) throw new RuntimeException('O funil da frente ' . $svc . ' não tem sub-etapas.');
        if ($waiting && pipe2_deps_into($pdo, $lineId, (int)$first['id'])) return 'waiting';
        $today = today_lisbon();
        if (pipe2_line_is_monthly($line) && ((string)$line['cycle_ym'] === '' || empty($line['billing_end']))) {
            $p = pipe2_billing_period($line, $today);
            $cycle = (string)$line['cycle_ym'] !== '' ? (string)$line['cycle_ym'] : substr($p['start'], 0, 7);
            $pdo->prepare('UPDATE deal_lines SET cycle_ym = ?, billing_end = COALESCE(billing_end, ?) WHERE id = ?')->execute([$cycle, $p['end'], $lineId]);
            // as linhas de passos do Kickoff (ciclo '') passam a ser as do 1.º ciclo
            $st = $pdo->prepare('SELECT COUNT(*) FROM deal_line_steps WHERE line_id = ? AND cycle_ym = ?');
            $st->execute([$lineId, $cycle]);
            $have = (int)$st->fetchColumn();
            $st->closeCursor();
            if ($cycle !== '' && $have === 0) $pdo->prepare("UPDATE deal_line_steps SET cycle_ym = ? WHERE line_id = ? AND cycle_ym = ''")->execute([$cycle, $lineId]);
            $GLOBALS['XSELL_FRESH'] = true;
            $line = pipe2_front_get($pdo, $lineId) ?? $line;
        }
        pipe2_plan_front($pdo, $line, $today);
        $r = pipe2_front_enter($pdo, $deal, $line, $first, ['why' => 'start', 'by' => $by]);
        pipe2_after_front_change($pdo, $dealId, 'start');
        return $r;
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/**
 * Movimento humano de uma frente para a sub-etapa $toStageId (só sub-etapas vivas do funil dela). Para trás passa
 * sempre e suspende o destino (D14). Para a frente, só a seguinte («avançar uma sub-etapa de cada vez»), com o gate da
 * sub-etapa completo e sem ligações por cumprir; senão GateBlocked com a lista — ou, com $force e is_admin(), passa com
 * a nota «Passagem forçada: <motivo> · faltava: …» e as ligações por cumprir ficam cumpridas. Devolve a mensagem.
 */
function pipe2_front_move(PDO $pdo, array $deal, array $line, int $toStageId, string $force = '', ?int $by = null): string {
    $dealId = (int)($deal['id'] ?? ($line['deal_id'] ?? 0));
    $lineId = (int)($line['id'] ?? 0);
    pipe2_lock_deal($pdo, $dealId);
    try {
        [$deal, $line] = pipe2_front_load($pdo, $dealId, $lineId);
        $svc = pipe2_dep_service($line);
        if ($deal['phase'] !== 'production') throw new RuntimeException('As frentes andam depois do Kickoff, enquanto o negócio está em produção.');
        if (in_array($line['status'], ['planned', 'queued'], true)) throw new RuntimeException('A frente ' . $svc . ' ainda não começou.');
        $to = pipe2_dep_stage($pdo, $line, $toStageId);
        if (!$to) throw new RuntimeException('Etapa inválida.');
        $from = !empty($line['stage_id']) ? stage_get($pdo, (int)$line['stage_id']) : null;
        $depwait = $line['status'] === 'depwait';
        if ($from && (int)$from['id'] === $toStageId && !$depwait) return 'Sem alterações.';
        $cmp = $from ? ([(int)$to['position'], (int)$to['id']] <=> [(int)$from['position'], (int)$from['id']]) : 1;
        $msg = $svc . ': passou a «' . (string)$to['name'] . '».';

        if ($cmp <= 0) {
            // para trás (ou reabrir a sub-etapa onde ficou À espera): passa sempre e suspende o destino
            $r = pipe2_front_enter($pdo, $deal, $line, $to, ['why' => 'undo', 'by' => $by, 'rules' => false]);
            if ($r !== 'entered') throw new RuntimeException(PIPE2_FRONT_CHANGED);
            pipe2_front_hold($pdo, $lineId, $toStageId);
            pipe2_after_front_change($pdo, $dealId, 'manual');
            return $msg;
        }

        $missing = [];
        $stages = pipe2_front_stages($pdo, (int)$line['funnel_id']);
        $ids = array_map(fn($s) => (int)$s['id'], $stages);
        $i = $from ? array_search((int)$from['id'], $ids, true) : -1;
        $j = array_search($toStageId, $ids, true);
        if ($i === false) {
            // a frente está numa sub-etapa retirada: a seguinte é a primeira viva depois dela
            $i = -1;
            foreach ($stages as $k => $s) if ([(int)$s['position'], (int)$s['id']] < [(int)$from['position'], (int)$from['id']]) $i = $k;
        }
        if ($j > $i + 1) {
            $missing[] = 'avançar uma sub-etapa de cada vez (salta ' . implode(', ', array_map(fn($s) => '«' . $s['name'] . '»', array_slice($stages, $i + 1, $j - $i - 1))) . ')';
        }
        if ($from && !$depwait) {
            $g = pipe2_step_gate($pdo, $line, $from);
            foreach ($g['missing'] as $t) $missing[] = $t;
            if ($g['units_plan'] !== null && $g['units_plan'] > 0 && $g['units_done'] < $g['units_plan']) {
                $missing[] = 'unidades: ' . $g['units_done'] . ' de ' . $g['units_plan'];
            }
        }
        $deps = pipe2_deps_into($pdo, $lineId, $toStageId);
        foreach ($deps as $d) $missing[] = 'à espera de ' . $d['from_service'] . ' («' . (string)$d['from_stage_name'] . '»)';
        $why = 'manual';
        $note = '';
        if ($missing) {
            $force = trim($force);
            if ($force === '') throw new GateBlocked($missing, pipe2_front_place($from, $line));
            if (!is_admin()) throw new RuntimeException('Só os admins podem forçar a passagem.');
            $why = 'force';
            $note = 'Passagem forçada: ' . $force . ' · faltava: ' . implode(' · ', $missing);
        }
        $r = pipe2_front_enter($pdo, $deal, $line, $to, ['why' => $why, 'by' => $by, 'note' => $note]);
        if ($r !== 'entered') throw new RuntimeException(PIPE2_FRONT_CHANGED);
        if ($deps) {
            // forçada: as ligações por cumprir ficam cumpridas (já não há por quem esperar)
            $met = [];
            $up = $pdo->prepare('UPDATE deal_line_deps SET met_at = ? WHERE id = ? AND met_at IS NULL AND removed_at IS NULL');
            foreach ($deps as $d) {
                $up->execute([date('Y-m-d H:i:s'), (int)$d['id']]);
                if ($up->rowCount() === 1) $met[] = [(int)$d['id'], null];
            }
            pipe2_move_append($lineId, 'deps', $met);
        }
        pipe2_after_front_change($pdo, $dealId, 'manual');
        return $msg;
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/** Suspende o avanço automático da sub-etapa da frente, no ciclo dela (D14); fica no registo do último movimento. */
function pipe2_front_hold(PDO $pdo, int $lineId, int $stageId): void {
    $line = pipe2_front_get($pdo, $lineId);
    if (!$line) return;
    [$row] = pipe2_step_row_ex($pdo, $line, $stageId);
    if (!$row) return;
    $pdo->prepare("UPDATE deal_line_steps SET auto_hold_at = ?, auto_hold_why = 'undo' WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$row['id']]);
    pipe2_move_append($lineId, 'steps', [[(int)$row['id'], ['auto_hold_at' => $row['auto_hold_at'], 'auto_hold_why' => $row['auto_hold_why']]]]);
}

/**
 * «Desfazer» (D14): a frente volta à sub-etapa anterior (À espera no fim de uma sub-etapa: volta a ela), sem regras
 * novas, e essa sub-etapa fica suspensa até alguém mexer numa obrigatória dela. Na 1.ª sub-etapa recusa. Devolve a
 * mensagem.
 */
function pipe2_front_undo(PDO $pdo, array $deal, array $line, ?int $by = null): string {
    $dealId = (int)($deal['id'] ?? ($line['deal_id'] ?? 0));
    $lineId = (int)($line['id'] ?? 0);
    pipe2_lock_deal($pdo, $dealId);
    try {
        [$deal, $line] = pipe2_front_load($pdo, $dealId, $lineId);
        $svc = pipe2_dep_service($line);
        if (!in_array($line['status'], ['running', 'ongoing', 'ready', 'depwait'], true)) throw new RuntimeException('A frente ' . $svc . ' ainda não começou.');
        $target = ($line['status'] === 'depwait' && !empty($line['stage_id'])) ? stage_get($pdo, (int)$line['stage_id']) : pipe2_prev_stage($pdo, $line);
        if (!$target) throw new RuntimeException('A frente já está na primeira sub-etapa.');
        $r = pipe2_front_enter($pdo, $deal, $line, $target, ['why' => 'undo', 'by' => $by, 'rules' => false]);
        if ($r !== 'entered') throw new RuntimeException(PIPE2_FRONT_CHANGED);
        pipe2_front_hold($pdo, $lineId, (int)$target['id']);
        deal_log($pdo, $dealId, 'p2_undo', ['to_stage' => (int)$target['id'], 'to_funnel' => (int)$line['funnel_id'],
                                            'note' => $svc . ': voltou a «' . (string)$target['name'] . '» · avanço automático suspenso nesta sub-etapa',
                                            'user_id' => $by, 'system' => $by === null]);
        pipe2_move_append($lineId, 'history', [(int)$pdo->lastInsertId()]);
        pipe2_after_front_change($pdo, $dealId, 'undo');
        return $svc . ': voltou a «' . (string)$target['name'] . '».';
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/**
 * O interruptor da frente (D5): null = segue o serviço e a sub-etapa, true = ligado, false = desligado (um ligado na
 * frente não contraria o serviço nem a sub-etapa). deal_log 'p2_auto'; reavalia quando não desliga. Quem pode mudá-lo
 * verifica-se no ponto de entrada (pipe2_can_front_switch).
 */
function pipe2_front_set_auto(PDO $pdo, array $deal, array $line, ?bool $on, ?int $by = null): void {
    $dealId = (int)($deal['id'] ?? ($line['deal_id'] ?? 0));
    $lineId = (int)($line['id'] ?? 0);
    pipe2_lock_deal($pdo, $dealId);
    try {
        [$deal, $line] = pipe2_front_load($pdo, $dealId, $lineId);
        $pdo->prepare('UPDATE deal_lines SET auto_advance = ? WHERE id = ?')->execute([$on === null ? null : ($on ? 1 : 0), $lineId]);
        $GLOBALS['XSELL_FRESH'] = true;
        $label = $on === null ? 'segue o serviço e a sub-etapa' : ($on ? 'ligado nesta frente' : 'desligado nesta frente');
        deal_log($pdo, $dealId, 'p2_auto', ['note' => pipe2_dep_service($line) . ': avanço automático ' . $label, 'user_id' => $by, 'system' => $by === null]);
        if ($on !== false) pipe2_front_reevaluate($pdo, $deal, pipe2_front_get($pdo, $lineId) ?? $line);
        pipe2_after_front_change($pdo, $dealId, 'auto');
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/**
 * As unidades da sub-etapa atual da frente (feitas e, se vier, previstas): grava, deal_log 'p2_units' e reavalia (as
 * unidades não levantam a suspensão do «Desfazer»).
 */
function pipe2_step_units(PDO $pdo, array $deal, array $line, int $done, ?int $plan = null, ?int $by = null): void {
    $dealId = (int)($deal['id'] ?? ($line['deal_id'] ?? 0));
    $lineId = (int)($line['id'] ?? 0);
    pipe2_lock_deal($pdo, $dealId);
    try {
        [$deal, $line] = pipe2_front_load($pdo, $dealId, $lineId);
        if (empty($line['stage_id'])) throw new RuntimeException('A frente não está numa sub-etapa.');
        $stage = stage_get($pdo, (int)$line['stage_id']);
        $row = pipe2_step_row($pdo, $line, (int)$line['stage_id']);
        if (!$row || !$stage) throw new RuntimeException('A frente não está numa sub-etapa.');
        $done = max(0, $done);
        if ($plan !== null) $plan = max(0, $plan);
        $pdo->prepare('UPDATE deal_line_steps SET units_done = ?, units_plan = COALESCE(?, units_plan) WHERE id = ?')->execute([$done, $plan, (int)$row['id']]);
        $p = $plan ?? ($row['units_plan'] !== null ? (int)$row['units_plan'] : null);
        $unit = trim((string)($stage['unit_label'] ?? ''));
        deal_log($pdo, $dealId, 'p2_units', ['note' => pipe2_dep_service($line) . ': «' . (string)$stage['name'] . '» — ' . $done . ($p !== null ? ' de ' . $p : '')
                                                     . ($unit !== '' ? ' ' . $unit : ' unidades'), 'user_id' => $by, 'system' => $by === null]);
        pipe2_front_reevaluate($pdo, $deal, pipe2_front_get($pdo, $lineId) ?? $line);
        pipe2_after_front_change($pdo, $dealId, 'units');
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}

/** Muda a obrigatória de uma tarefa: is_gate, task_log («Obrigatória: sim/não») e a reavaliação (why 'gate'). */
function pipe2_task_set_gate(PDO $pdo, int $taskId, bool $on, ?int $by = null): void {
    $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ?');
    $st->execute([$taskId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    if (!$t) throw new RuntimeException('Essa tarefa já não existe.');
    $dealId = (int)($t['deal_id'] ?? 0);
    if ($dealId > 0) pipe2_lock_deal($pdo, $dealId);
    try {
        $now = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE tasks SET is_gate = ?, updated_at = ? WHERE id = ?')->execute([$on ? 1 : 0, $now, $taskId]);
        if (db_has_table($pdo, 'task_log')) {
            $pdo->prepare("INSERT INTO task_log (task_id, event, note, user_id, created_at) VALUES (?, 'edit', ?, ?, ?)")
                ->execute([$taskId, 'Obrigatória: ' . ($on ? 'sim' : 'não'), $by, $now]);
        }
        $st->execute([$taskId]);
        $fresh = $st->fetch(PDO::FETCH_ASSOC) ?: $t;
        $st->closeCursor();
        pipe2_task_changed($pdo, $fresh, 'gate');
    } finally {
        if ($dealId > 0) pipe2_unlock_deal($pdo, $dealId);
    }
}
