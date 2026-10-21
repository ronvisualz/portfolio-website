<?php
/**
 * topper. admin — o ID dos negócios e o histórico de etapas
 *
 * ID = «ID» + AA + MM + «-» + sequência do mês (ID2610-01). Atribuído
 * na conversão da lead, imutável e nunca reutilizado: a sequência vive
 * em deal_codes e só sobe, por isso um negócio apagado ou perdido
 * deixa um «buraco» em vez de devolver o número.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** A data de hoje em Lisboa (yyyy-mm-dd). */
function today_lisbon(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d');
}

/** «Agora» em SQL, para MySQL ou para a bancada SQLite. */
function now_sql(PDO $pdo): string {
    return db_is_sqlite($pdo) ? "datetime('now')" : 'NOW()';
}

/** Formata o ID. Com 100 ou mais no mês passa naturalmente a 3 dígitos. */
function deal_code_format(string $yymm, int $seq): string {
    return 'ID' . $yymm . '-' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
}

/**
 * Reserva o próximo ID do mês de $when, de forma atómica: duas conversões
 * ao mesmo tempo nunca recebem o mesmo número. Tem de correr dentro de
 * uma transação aberta pelo chamador (deal_assign_code trata disso).
 */
function deal_next_code(PDO $pdo, DateTimeInterface $when): string {
    $yymm = $when->format('ym');
    if (db_is_sqlite($pdo)) {
        $pdo->prepare('INSERT OR IGNORE INTO deal_codes (yymm, last_seq) VALUES (?, 0)')->execute([$yymm]);
        $pdo->prepare('UPDATE deal_codes SET last_seq = last_seq + 1 WHERE yymm = ?')->execute([$yymm]);
        $st = $pdo->prepare('SELECT last_seq FROM deal_codes WHERE yymm = ?');
        $st->execute([$yymm]);
        $seq = (int)$st->fetchColumn();
    } else {
        // LAST_INSERT_ID(expr) devolve o valor desta ligação, mesmo com outras a correr.
        $pdo->prepare(
            'INSERT INTO deal_codes (yymm, last_seq) VALUES (?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)'
        )->execute([$yymm]);
        $seq = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }
    return deal_code_format($yymm, $seq);
}

/** Dá um ID a um registo que ainda não tem. Devolve o ID (o que já tinha, se tinha). */
function deal_assign_code(PDO $pdo, int $dealId, ?DateTimeInterface $when = null): string {
    $when ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon'));
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT code FROM deals WHERE id = ?' . (db_is_sqlite($pdo) ? '' : ' FOR UPDATE'));
        $st->execute([$dealId]);
        $code = $st->fetchColumn();
        if ($code === false) throw new RuntimeException('Registo não encontrado.');
        if ($code === null || $code === '') {
            $code = deal_next_code($pdo, $when);
            $pdo->prepare('UPDATE deals SET code = ? WHERE id = ?')->execute([$code, $dealId]);
        }
        if ($own) $pdo->commit();
        return (string)$code;
    } catch (Throwable $e) {
        if ($own) $pdo->rollBack();
        throw $e;
    }
}

/** Uma linha no histórico do negócio (etapa, funil, conversão, perdido, reaberto…). */
function deal_log(PDO $pdo, int $dealId, string $event, array $d = []): void {
    $u = current_user();
    $pdo->prepare(
        'INSERT INTO deal_history (deal_id, event, from_funnel_id, from_stage_id, to_funnel_id, to_stage_id, note, user_id)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $dealId, $event,
        $d['from_funnel'] ?? null, $d['from_stage'] ?? null,
        $d['to_funnel'] ?? null,   $d['to_stage'] ?? null,
        mb_substr((string)($d['note'] ?? ''), 0, 255),
        $d['user_id'] ?? ($u['id'] ?? null),
    ]);
    if (in_array($event, ['convert', 'stage', 'lost', 'reopen', 'funnel'], true)) budget_follow_deal($pdo, $dealId);
}

/**
 * A folha de orçamento segue o negócio (§14.1): nasce na conversão da lead e o
 * Resultado fecha quando o negócio é concluído (reabre se voltar atrás).
 */
function budget_follow_deal(PDO $pdo, int $dealId): void {
    static $has = null;
    $has ??= db_has_table($pdo, 'budget_sheets') && db_has_column($pdo, 'costs', 'budget_key');
    if (!$has) return;
    require_once __DIR__ . '/budget.php';
    $st = $pdo->prepare('SELECT * FROM deals WHERE id = ?');
    $st->execute([$dealId]);
    $d = $st->fetch();
    if (!$d || $d['code'] === null || !in_array($d['phase'], ['pipeline', 'production', 'done', 'lost'], true)) return;
    budget_sync_state($pdo, budget_sheet_ensure($pdo, $d), $d);
}

/** A etapa fixa de um funil (xray, kickoff ou done). */
function funnel_fixed_stage(PDO $pdo, int $funnelId, string $kind): ?int {
    $st = $pdo->prepare('SELECT id FROM funnel_stages WHERE funnel_id = ? AND kind = ? ORDER BY position LIMIT 1');
    $st->execute([$funnelId, $kind]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}
