<?php
/**
 * topper. admin — Pipeline v2: «Anular» em cascata
 *
 * O «Anular» de uma ação (§11.10) desfaz também os movimentos que ela provocou no mesmo pedido (D13): o avanço
 * automático de uma frente, as frentes que esse avanço libertou, os movimentos do sistema do negócio e tudo o que eles
 * criaram ou mudaram (tarefas, histórico, stage_events, linhas e mudanças de deal_line_steps, met_at das ligações,
 * avisos, as instâncias de séries fechadas pelo sistema, D18). O motor (pipe2_engine.php, pipe2_deal.php) guarda cada
 * movimento em pipe2_moves(); undo_push junta-os aos dados do «Anular» ('p2_moves') e não oferece o «Anular» quando
 * algum não se desfaz (o Concluído automático: pipe2_undo_offer).
 *
 * Tudo ou nada (pipe2_undo_apply): no lock de cada negócio (pela ordem dos ids, para dois «Anular» nunca se
 * esperarem um ao outro) e numa transação — a de quem chamou, se houver (o 'multi') —, primeiro verifica que cada
 * frente e o negócio continuam onde o seu movimento os deixou (do último para o primeiro: pipe2_undo_check), depois
 * corre o «Anular» da v1 e só então desfaz os movimentos, do último para o primeiro (pipe2_undo_moves). Qualquer erro
 * desfaz a transação e nada muda. Cada movimento de frente desfeito deixa um p2_front «… · Anulado» de quem anulou.
 *
 * Anular o Kickoff (D29, pipe2_undo_kickoff, com o retrato pipe2_undo_snapshot tirado antes do movimento): as frentes
 * criadas pelo Kickoff saem, com as linhas de passos e as ligações criadas depois do retrato e os Topperboards dessas
 * frentes (e os cartões que apontavam para eles); as frentes que já existiam voltam ao que eram (planned) e a
 * categoria também. Recusa, antes de mexer, se já há trabalho no Toppernote ou no Review das frentes criadas.
 *
 * Só corre em negócios v2 (os dados de um «Anular» v1 não têm 'p2_moves' nem 'p2'). Sem «§» nas mensagens.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_fronts.php';    // pipe2_deal_is_v2, pipe2_front_get
require_once __DIR__ . '/pipe2_deps.php';      // pipe2_dep_service
require_once __DIR__ . '/pipe2_engine.php';    // pipe2_lock_deal, pipe2_unlock_deal, pipe2_max_id, pipe2_front_place
require_once __DIR__ . '/pipeline.php';        // deal_get, stage_get
require_once __DIR__ . '/deals.php';           // deal_log

const PIPE2_UNDO_CHANGED = 'O registo mudou entretanto; não foi anulado.';
const PIPE2_UNDO_WORK = 'Já há trabalho no Toppernote ou no Review destas frentes; não foi anulado.';

/** Os movimentos desfazem-se todos (D13): false se algum não se desfaz (o Concluído automático). */
function pipe2_undo_offer(array $moves): bool {
    foreach ($moves as $m) {
        if (!is_array($m) || (array_key_exists('undoable', $m) && !$m['undoable'])) return false;
    }
    return true;
}

/**
 * O retrato de um negócio v2 antes de mudar de etapa, para anular o Kickoff (D29): ['deal', 'phase', 'lines' =>
 * [['id', 'status', 'stage_id', 'exec_owner_id', 'value', 'is_main'], …], 'steps_max', 'deps_max', 'category',
 * 'category_at']. Num negócio v1, [] (sem consultas às tabelas da v2).
 */
function pipe2_undo_snapshot(PDO $pdo, array $deal): array {
    $dealId = (int)($deal['id'] ?? 0);
    if ($dealId <= 0 || !pipe2_deal_is_v2($pdo, $deal)) return [];
    $st = $pdo->prepare('SELECT id, status, stage_id, exec_owner_id, value, is_main FROM deal_lines WHERE deal_id = ? ORDER BY id');
    $st->execute([$dealId]);
    $lines = array_map(fn($l) => ['id' => (int)$l['id'], 'status' => (string)$l['status'], 'stage_id' => $l['stage_id'] === null ? null : (int)$l['stage_id'],
                                  'exec_owner_id' => $l['exec_owner_id'] === null ? null : (int)$l['exec_owner_id'], 'value' => $l['value'],
                                  'is_main' => (int)$l['is_main']], $st->fetchAll(PDO::FETCH_ASSOC));
    $st = $pdo->prepare('SELECT phase, category, category_at FROM deals WHERE id = ?');
    $st->execute([$dealId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['deal' => $dealId, 'phase' => (string)($row['phase'] ?? ($deal['phase'] ?? '')), 'lines' => $lines,
            'steps_max' => pipe2_max_id($pdo, 'deal_line_steps', $dealId), 'deps_max' => pipe2_max_id($pdo, 'deal_line_deps', $dealId),
            'category' => (string)($row['category'] ?? ''), 'category_at' => $row['category_at'] ?? null];
}

/** Um id (ou null): '' e null → null. */
function pipe2_undo_id($v): ?int {
    return $v === null || $v === '' ? null : (int)$v;
}

/**
 * Cada frente e o negócio continuam no 'to' do seu movimento, do último para o primeiro (um mesmo registo com vários
 * movimentos passa, depois de cada um, para o 'from' desse); senão RuntimeException(PIPE2_UNDO_CHANGED).
 */
function pipe2_undo_check(PDO $pdo, array $moves): void {
    $lines = [];
    $deals = [];
    $getLine = $pdo->prepare('SELECT deal_id, stage_id, status, cycle_ym FROM deal_lines WHERE id = ?');
    $getDeal = $pdo->prepare('SELECT stage_id, phase FROM deals WHERE id = ?');
    for ($i = count($moves) - 1; $i >= 0; $i--) {
        $m = (array)$moves[$i];
        $dealId = (int)($m['deal'] ?? 0);
        $to = array_values((array)($m['to'] ?? []));
        $from = array_values((array)($m['from'] ?? []));
        if ($dealId <= 0 || count($to) < 2 || count($from) < 2) throw new RuntimeException(PIPE2_UNDO_CHANGED);
        if (($m['kind'] ?? '') === 'front') {
            $id = (int)($m['line'] ?? 0);
            if (!array_key_exists($id, $lines)) {
                $getLine->execute([$id]);
                $r = $getLine->fetch(PDO::FETCH_ASSOC);
                $getLine->closeCursor();
                if (!$r || (int)$r['deal_id'] !== $dealId) throw new RuntimeException(PIPE2_UNDO_CHANGED);
                $lines[$id] = [pipe2_undo_id($r['stage_id']), (string)$r['status'], (string)$r['cycle_ym']];
            }
            [$sid, $status, $cycle] = $lines[$id];
            if ($sid !== pipe2_undo_id($to[0]) || $status !== (string)$to[1]) throw new RuntimeException(PIPE2_UNDO_CHANGED);
            if (array_key_exists('to_cycle', $m) && $cycle !== (string)$m['to_cycle']) throw new RuntimeException(PIPE2_UNDO_CHANGED);
            $lines[$id] = [pipe2_undo_id($from[0]), (string)$from[1], (string)($from[4] ?? $cycle)];
        } elseif (($m['kind'] ?? '') === 'deal') {
            if (!array_key_exists($dealId, $deals)) {
                $getDeal->execute([$dealId]);
                $r = $getDeal->fetch(PDO::FETCH_ASSOC);
                $getDeal->closeCursor();
                if (!$r) throw new RuntimeException(PIPE2_UNDO_CHANGED);
                $deals[$dealId] = [pipe2_undo_id($r['stage_id']), (string)$r['phase']];
            }
            [$sid, $phase] = $deals[$dealId];
            if ($sid !== pipe2_undo_id($to[0]) || $phase !== (string)$to[1]) throw new RuntimeException(PIPE2_UNDO_CHANGED);
            $deals[$dealId] = [pipe2_undo_id($from[0]), (string)$from[1]];
        } else {
            throw new RuntimeException(PIPE2_UNDO_CHANGED);
        }
    }
}

/** Os ids inteiros (> 0) de uma lista, sem repetidos, para um IN (…); '' sem nenhum. */
function pipe2_undo_in(array $ids): string {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    return $ids ? implode(',', $ids) : '';
}

/** O sítio da frente num estado [sub-etapa, estado], para a nota do «Anulado». */
function pipe2_undo_place(PDO $pdo, ?int $stageId, string $status): string {
    $stage = $stageId ? stage_get($pdo, $stageId) : null;
    if ($status === 'depwait') return $stage ? (string)$stage['name'] . ' (' . PIPE2_LINE_STATUS['depwait'] . ')' : PIPE2_LINE_STATUS['depwait'];
    return pipe2_front_place($stage, ['status' => $status]);
}

/**
 * Desfaz os movimentos, do último para o primeiro (chamar depois de pipe2_undo_check, dentro da transação de
 * pipe2_undo_apply): repõe a frente ou o negócio (o 'from'), as linhas de passos e o met_at das ligações; reabre as
 * instâncias de séries fechadas pelo sistema (estado e done_at de antes) e apaga as linhas de task_log desse fecho;
 * apaga as tarefas criadas ainda por fazer, o histórico, os stage_events, os passos novos e os avisos. Cada frente
 * desfeita deixa um p2_front «… · Anulado» (de quem anulou).
 */
function pipe2_undo_moves(PDO $pdo, array $moves): void {
    $now = date('Y-m-d H:i:s');
    $hasLog = db_has_table($pdo, 'task_log');
    $hasEvents = db_has_table($pdo, 'stage_events');
    $hasNotices = db_has_table($pdo, 'pipe2_notices');
    $reopen = $pdo->prepare("UPDATE tasks SET status = ?, done_at = ?, updated_at = ? WHERE id = ? AND status = 'done'");
    $dropLog = $hasLog ? $pdo->prepare('DELETE FROM task_log WHERE id = ? AND task_id = ?') : null;
    $metAt = $pdo->prepare('UPDATE deal_line_deps SET met_at = ? WHERE id = ? AND deal_id = ?');
    for ($i = count($moves) - 1; $i >= 0; $i--) {
        $m = (array)$moves[$i];
        $dealId = (int)$m['deal'];
        $from = array_values((array)$m['from']);
        $to = array_values((array)$m['to']);
        $front = ($m['kind'] ?? '') === 'front';
        $lineId = (int)($m['line'] ?? 0);
        if ($front) {
            $pdo->prepare('UPDATE deal_lines SET stage_id = ?, status = ?, stage_entered_at = ?, done_at = ?, cycle_ym = ?, started_at = ? WHERE id = ? AND deal_id = ?')
                ->execute([pipe2_undo_id($from[0]), (string)$from[1], $from[2] ?? null, $from[3] ?? null, (string)($from[4] ?? ''), $from[5] ?? null, $lineId, $dealId]);
            // as instâncias das séries fechadas pelo sistema reabrem; o task_log desse fecho sai (D18)
            foreach ((array)($m['closed'] ?? []) as $c) {
                [$tid, $status, $doneAt, $logId] = array_values((array)$c) + [0, 'todo', null, null];
                $reopen->execute([(string)$status, $doneAt, $now, (int)$tid]);
                if ($dropLog && $logId) $dropLog->execute([(int)$logId, (int)$tid]);
            }
            // as linhas de passos como estavam (do fim para o princípio: a mais antiga mudança de cada linha fica)
            foreach (array_reverse((array)($m['steps'] ?? [])) as $s) {
                [$sid, $cols] = array_values((array)$s) + [0, []];
                $cols = array_filter((array)$cols, fn($k) => is_string($k) && preg_match('/^[a-z_]+$/', $k), ARRAY_FILTER_USE_KEY);
                if (!$cols || (int)$sid <= 0) continue;
                $pdo->prepare('UPDATE deal_line_steps SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($cols))) . ' WHERE id = ? AND line_id = ?')
                    ->execute(array_merge(array_values($cols), [(int)$sid, $lineId]));
            }
            if ($in = pipe2_undo_in((array)($m['steps_new'] ?? []))) $pdo->prepare("DELETE FROM deal_line_steps WHERE id IN ($in) AND line_id = ?")->execute([$lineId]);
            foreach ((array)($m['deps'] ?? []) as $dp) {
                [$depId, $before] = array_values((array)$dp) + [0, null];
                $metAt->execute([$before, (int)$depId, $dealId]);
            }
        } else {
            $pdo->prepare('UPDATE deals SET stage_id = ?, phase = ?, stage_entered_at = ?, done_at = ?, last_touch = ? WHERE id = ?')
                ->execute([pipe2_undo_id($from[0]), (string)$from[1], $from[2] ?? null, $from[3] ?? null, $from[4] ?? null, $dealId]);
        }
        if ($in = pipe2_undo_in((array)($m['tasks'] ?? []))) {
            // as tarefas criadas pelo movimento saem se ainda ninguém lhes mexeu (como no «Anular» da v1)
            $st = $pdo->prepare("SELECT id FROM tasks WHERE id IN ($in) AND deal_id = ? AND status = 'todo'");
            $st->execute([$dealId]);
            if ($gone = pipe2_undo_in($st->fetchAll(PDO::FETCH_COLUMN))) {
                $pdo->exec("DELETE FROM tasks WHERE id IN ($gone)");
                if ($hasLog) $pdo->exec("DELETE FROM task_log WHERE task_id IN ($gone)");
            }
        }
        if ($in = pipe2_undo_in((array)($m['history'] ?? []))) $pdo->prepare("DELETE FROM deal_history WHERE id IN ($in) AND deal_id = ?")->execute([$dealId]);
        if ($hasEvents && ($in = pipe2_undo_in((array)($m['events'] ?? [])))) $pdo->prepare("DELETE FROM stage_events WHERE id IN ($in) AND deal_id = ?")->execute([$dealId]);
        if ($hasNotices && ($in = pipe2_undo_in((array)($m['notices'] ?? [])))) $pdo->prepare("DELETE FROM pipe2_notices WHERE id IN ($in) AND deal_id = ?")->execute([$dealId]);
        if ($front) {
            $line = pipe2_front_get($pdo, $lineId);
            $note = pipe2_dep_service($line ?? []) . ': ' . pipe2_undo_place($pdo, pipe2_undo_id($to[0]), (string)$to[1]) . ' → '
                  . pipe2_undo_place($pdo, pipe2_undo_id($from[0]), (string)$from[1]) . ' · Anulado';
            deal_log($pdo, $dealId, 'p2_front', ['from_stage' => pipe2_undo_id($to[0]), 'to_stage' => pipe2_undo_id($from[0]),
                                                 'to_funnel' => $line ? (int)$line['funnel_id'] : null, 'note' => $note]);
        }
    }
    $GLOBALS['XSELL_FRESH'] = true;
}

/**
 * O «Anular» com os movimentos do pedido (D13) e/ou o retrato do Kickoff (D29), tudo ou nada: o lock de cada negócio
 * (os dos movimentos e $dealIds, pela ordem dos ids), uma transação (a de quem chamou, se houver), pipe2_undo_check,
 * $fn (o «Anular» da v1, que devolve a mensagem), pipe2_undo_moves e commit. Num erro, rollback e relança.
 */
function pipe2_undo_apply(PDO $pdo, array $moves, callable $fn, array $dealIds = []): string {
    $ids = array_merge(array_map(fn($m) => (int)(((array)$m)['deal'] ?? 0), $moves), array_map('intval', $dealIds));
    $ids = array_values(array_unique(array_filter($ids, fn($i) => $i > 0)));
    sort($ids);
    $locked = [];
    try {
        foreach ($ids as $id) {
            pipe2_lock_deal($pdo, $id);
            $locked[] = $id;
        }
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            if ($moves) pipe2_undo_check($pdo, $moves);
            $msg = (string)$fn();
            if ($moves) pipe2_undo_moves($pdo, $moves);
            if ($own) $pdo->commit();
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            $GLOBALS['XSELL_FRESH'] = true;
            throw $e;
        }
        return $msg;
    } finally {
        foreach (array_reverse($locked) as $id) pipe2_unlock_deal($pdo, $id);
    }
}

/** Os Topperboards das frentes (cc_boards.line_id), do negócio; [] sem frentes ou sem o Toppernote. */
function pipe2_undo_boards(PDO $pdo, int $dealId, array $lineIds): array {
    $in = pipe2_undo_in($lineIds);
    if ($in === '' || !db_has_table($pdo, 'cc_boards')) return [];
    $st = $pdo->prepare("SELECT id FROM cc_boards WHERE deal_id = ? AND line_id IN ($in) ORDER BY id");
    $st->execute([$dealId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Há trabalho no Toppernote ou no Review das frentes: cartões vivos nos Topperboards delas (salvo os cartões-ligação
 * que o Kickoff pôs a apontar para os sub-Topperboards delas), comentários, páginas ou versões, ou peças de Review.
 */
function pipe2_undo_has_work(PDO $pdo, array $boards, array $lineIds): bool {
    $b = pipe2_undo_in($boards);
    $l = pipe2_undo_in($lineIds);
    $count = fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
    if ($b !== '') {
        if (db_has_table($pdo, 'cc_cards')
            && $count("SELECT COUNT(*) FROM cc_cards WHERE board_id IN ($b) AND deleted_at IS NULL AND NOT (type = 'board' AND ref_board_id IN ($b))") > 0) return true;
        if (db_has_table($pdo, 'cc_comments') && $count("SELECT COUNT(*) FROM cc_comments WHERE board_id IN ($b) AND deleted_at IS NULL") > 0) return true;
        if (db_has_table($pdo, 'cc_pages') && $count("SELECT COUNT(*) FROM cc_pages WHERE board_id IN ($b)") > 0) return true;
        if (db_has_table($pdo, 'cc_versions') && $count("SELECT COUNT(*) FROM cc_versions WHERE board_id IN ($b)") > 0) return true;
    }
    if ($l !== '') {
        if (db_has_column($pdo, 'cc_rv_items', 'line_id') && $count("SELECT COUNT(*) FROM cc_rv_items WHERE line_id IN ($l)") > 0) return true;
        if (db_has_column($pdo, 'cc_versions', 'line_id') && $count("SELECT COUNT(*) FROM cc_versions WHERE line_id IN ($l)") > 0) return true;
    }
    return false;
}

/** Outros registos ligados às frentes (tarefas, custos, documentos, rondas, executores): apagá-las deixava-os órfãos. */
function pipe2_undo_line_refs(PDO $pdo, array $lineIds): bool {
    $l = pipe2_undo_in($lineIds);
    if ($l === '') return false;
    foreach (['tasks', 'costs', 'fin_docs', 'deal_rounds', 'deal_line_execs', 'cc_rv_rounds'] as $t) {
        if (db_has_column($pdo, $t, 'line_id') && (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE line_id IN ($l)")->fetchColumn() > 0) return true;
    }
    return false;
}

/**
 * Anular o Kickoff (D29), com o retrato de pipe2_undo_snapshot: só quando o movimento anulado foi do Pipeline para o
 * Kickoff (os outros movimentos do negócio não mudam as frentes). Com as frentes ainda por começar (senão
 * PIPE2_UNDO_CHANGED) e sem trabalho no Toppernote ou no Review das frentes criadas (senão PIPE2_UNDO_WORK), antes de
 * mexer: apaga as frentes criadas pelo Kickoff, os Topperboards delas sem trabalho (e os cartões que apontavam para
 * eles) e as linhas de deal_line_steps e deal_line_deps criadas depois do retrato; as frentes que já existiam voltam
 * ao que eram (planned) e a categoria também. Corre no lock do negócio e numa transação (a de quem chamou, se houver).
 */
function pipe2_undo_kickoff(PDO $pdo, array $snap): void {
    $dealId = (int)($snap['deal'] ?? 0);
    if ($dealId <= 0) return;
    pipe2_lock_deal($pdo, $dealId);
    try {
        $deal = deal_get($pdo, $dealId);
        if (!$deal || !pipe2_deal_is_v2($pdo, $deal)) return;
        $stage = !empty($deal['stage_id']) ? stage_get($pdo, (int)$deal['stage_id']) : null;
        if ((string)($snap['phase'] ?? '') !== 'pipeline' || ($stage['kind'] ?? '') !== 'kickoff') return;
        $before = [];
        foreach ((array)($snap['lines'] ?? []) as $l) $before[(int)$l['id']] = (array)$l;
        $st = $pdo->prepare('SELECT id, status, stage_id FROM deal_lines WHERE deal_id = ? ORDER BY id');
        $st->execute([$dealId]);
        $now = $st->fetchAll(PDO::FETCH_ASSOC);
        $created = [];
        foreach ($now as $l) {
            // as frentes ainda não começaram (Por começar, sem sub-etapa); senão o registo mudou entretanto
            if (!in_array((string)$l['status'], ['planned', 'queued'], true) || $l['stage_id'] !== null) throw new RuntimeException(PIPE2_UNDO_CHANGED);
            if (!isset($before[(int)$l['id']])) $created[] = (int)$l['id'];
        }
        if (array_diff(array_keys($before), array_map(fn($l) => (int)$l['id'], $now))) throw new RuntimeException(PIPE2_UNDO_CHANGED);
        $boards = pipe2_undo_boards($pdo, $dealId, $created);
        if (pipe2_undo_has_work($pdo, $boards, $created)) throw new RuntimeException(PIPE2_UNDO_WORK);
        if (pipe2_undo_line_refs($pdo, $created)) throw new RuntimeException(PIPE2_UNDO_CHANGED);

        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            if ($b = pipe2_undo_in($boards)) {
                $pdo->exec("DELETE FROM cc_cards WHERE board_id IN ($b) OR (type = 'board' AND ref_board_id IN ($b))");
                if (db_has_table($pdo, 'cc_board_versions')) $pdo->exec("DELETE FROM cc_board_versions WHERE board_id IN ($b)");
                $pdo->exec("DELETE FROM cc_boards WHERE id IN ($b)");
            }
            $pdo->prepare('DELETE FROM deal_line_steps WHERE deal_id = ? AND id > ?')->execute([$dealId, (int)($snap['steps_max'] ?? 0)]);
            $pdo->prepare('DELETE FROM deal_line_deps WHERE deal_id = ? AND id > ?')->execute([$dealId, (int)($snap['deps_max'] ?? 0)]);
            if ($c = pipe2_undo_in($created)) {
                if (db_has_table($pdo, 'pipe2_notices')) $pdo->prepare("DELETE FROM pipe2_notices WHERE deal_id = ? AND line_id IN ($c)")->execute([$dealId]);
                $pdo->prepare("DELETE FROM deal_lines WHERE deal_id = ? AND id IN ($c)")->execute([$dealId]);
            }
            $up = $pdo->prepare('UPDATE deal_lines SET status = ?, stage_id = ?, exec_owner_id = ?, value = ?, is_main = ? WHERE id = ? AND deal_id = ?');
            foreach ($before as $id => $l) {
                $up->execute([(string)$l['status'], pipe2_undo_id($l['stage_id'] ?? null), pipe2_undo_id($l['exec_owner_id'] ?? null), $l['value'] ?? null,
                              (int)($l['is_main'] ?? 0), $id, $dealId]);
            }
            $pdo->prepare('UPDATE deals SET category = ?, category_at = ? WHERE id = ?')
                ->execute([(string)($snap['category'] ?? ''), $snap['category_at'] ?? null, $dealId]);
            if ($own) $pdo->commit();
        } catch (Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $GLOBALS['XSELL_FRESH'] = true;
        }
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
}
