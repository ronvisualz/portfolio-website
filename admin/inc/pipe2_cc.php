<?php
/**
 * topper. admin — Pipeline v2: Review e rondas por frente
 *
 * Num negócio v2 (D28, §34.11 C2):
 *   · uma peça de Review tem sempre frente quando o negócio tem duas ou mais frentes vivas, e só fecha as tarefas de
 *     aprovação dessa frente; com uma só frente, a peça sem frente é da frente única (e fecha também as do negócio);
 *   · as rondas contam por frente e por ciclo (deal_rounds.line_id/cycle_ym), na sub-etapa Revisões do funil da frente
 *     (role 'revisions', com as rondas incluídas dela); a ronda do Review (cc_rv_rounds.line_id) leva a frente;
 *   · uma frente que já tem rondas do portal no ciclo não aceita rondas registadas à mão (contavam-se duas vezes);
 *   · acima das incluídas correm as regras da Revisões com o gatilho «rondas ultrapassadas» ('rounds', uma vez por
 *     ciclo pela deduplicação de pipe2_tasks), em vez da tarefa «Pedido de alteração · …» da v1
 *     (cc_rv_change_order_task, que num v2 não corre).
 *
 * Caminho v1 (regra de ouro). Os ganchos em cc_review.php e deal_flow.php perguntam primeiro pipe2_deal_is_v2 (que
 * começa por pipe2_schema_ready); num negócio v1 estas funções devolvem o resultado de hoje (null, nada, o filtro de
 * sempre) sem ler colunas da v2. O motor (lock do negócio, regras) só se carrega dentro de pipe2_rounds_add, que só
 * corre num v2. Sem permissões aqui: quem chama verifica (deal_flow.php: flow_can_edit; o portal: o link do cliente).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipe2.php';
require_once __DIR__ . '/pipe2_fronts.php';   // pipe2_deal_is_v2, pipe2_fronts, pipe2_front_get
require_once __DIR__ . '/pipeline.php';       // deal_get

const PIPE2_CC_PICK = 'Escolha a frente desta peça (o negócio tem várias frentes).';
const PIPE2_CC_PICK_ROUND = 'Escolha a frente desta ronda (o negócio tem várias frentes).';
const PIPE2_CC_PORTAL = 'As rondas desta frente contam-se pelo portal do cliente.';
const PIPE2_CC_NO_FRONT = 'Frente não encontrada.';

// ── a frente de uma peça ────────────────────────────────────

/**
 * A frente de uma peça (ou de uma ronda) num negócio v2: a indicada, se for uma frente viva deste negócio; sem
 * nenhuma indicada, a única frente viva (com duas ou mais, nenhuma). Num v1, null.
 */
function pipe2_cc_item_front(PDO $pdo, ?array $deal, ?int $lineId): ?array {
    if (!$deal || empty($deal['id']) || !pipe2_deal_is_v2($pdo, $deal)) return null;
    if ($lineId) {
        $l = pipe2_front_get($pdo, $lineId);
        return $l && (int)$l['deal_id'] === (int)$deal['id'] && $l['status'] !== 'merged' ? $l : null;
    }
    $fronts = pipe2_fronts($pdo, (int)$deal['id']);
    return count($fronts) === 1 ? $fronts[0] : null;
}

/** A sub-etapa Revisões (role 'revisions', viva) de um funil, ou null. */
function pipe2_cc_revisions_of(PDO $pdo, int $funnelId): ?array {
    $st = $pdo->prepare("SELECT * FROM funnel_stages WHERE funnel_id = ? AND level = 'front' AND role = 'revisions' AND retired_at IS NULL ORDER BY position, id LIMIT 1");
    $st->execute([$funnelId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    $st->closeCursor();
    return $s ?: null;
}

/** Onde contam as rondas de uma peça num v2: a Revisões do funil da frente (sem frente e com uma só: a dela). v1 → null. */
function pipe2_cc_revisions_stage(PDO $pdo, array $deal, ?int $lineId): ?int {
    $f = pipe2_cc_item_front($pdo, $deal, $lineId);
    $s = $f ? pipe2_cc_revisions_of($pdo, (int)$f['funnel_id']) : null;
    return $s ? (int)$s['id'] : null;
}

/** Num v2 com duas ou mais frentes vivas, a peça precisa de uma frente válida deste negócio. Num v1 não faz nada. */
function pipe2_cc_item_line_check(PDO $pdo, array $deal, ?int $lineId): void {
    if (!pipe2_deal_is_v2($pdo, $deal)) return;
    if (count(pipe2_fronts($pdo, (int)$deal['id'])) < 2) return;
    if (!pipe2_cc_item_front($pdo, $deal, $lineId)) throw new RuntimeException(PIPE2_CC_PICK);
}

/**
 * O filtro das tarefas de aprovação que uma peça fecha (cc_rv_approval_tasks). v2 com duas ou mais frentes: só as da
 * frente da peça (sem frente: só as do negócio); v2 com uma: as do negócio e as da frente; v1: o filtro de hoje.
 */
function pipe2_cc_approval_line_sql(PDO $pdo, array $item): string {
    $lineId = !empty($item['line_id']) ? (int)$item['line_id'] : null;
    $v1 = $lineId ? ' AND (line_id IS NULL OR line_id = ' . $lineId . ')' : '';
    $st = $pdo->prepare('SELECT * FROM deals WHERE id = ?');
    $st->execute([(int)$item['deal_id']]);
    $deal = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $st->closeCursor();
    if (!$deal || !pipe2_deal_is_v2($pdo, $deal)) return $v1;
    $f = pipe2_cc_item_front($pdo, $deal, $lineId);
    if (count(pipe2_fronts($pdo, (int)$deal['id'])) >= 2) return $f ? ' AND line_id = ' . (int)$f['id'] : ' AND line_id IS NULL';
    return $f ? ' AND (line_id IS NULL OR line_id = ' . (int)$f['id'] . ')' : $v1;
}

// ── rondas por frente e ciclo ───────────────────────────────

/** As rondas de uma frente num ciclo ('' numa frente pontual, AAAA-MM numa avença). */
function pipe2_rounds_count(PDO $pdo, int $lineId, string $cycle): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM deal_rounds WHERE line_id = ? AND cycle_ym = ?');
    $st->execute([$lineId, $cycle]);
    return (int)$st->fetchColumn();
}

/**
 * «Ronda X de Y incluídas» de uma peça num v2, pela frente e pelo ciclo atual dela: ['done', 'included', 'stage'], ou
 * null (v1, ou peça sem frente/Revisões: quem chama faz o de hoje).
 */
function pipe2_cc_round_info(PDO $pdo, array $item): ?array {
    $st = $pdo->prepare('SELECT * FROM deals WHERE id = ?');
    $st->execute([(int)$item['deal_id']]);
    $deal = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $st->closeCursor();
    $f = pipe2_cc_item_front($pdo, $deal, !empty($item['line_id']) ? (int)$item['line_id'] : null);
    $rev = $f ? pipe2_cc_revisions_of($pdo, (int)$f['funnel_id']) : null;
    if (!$rev) return null;
    return ['done' => pipe2_rounds_count($pdo, (int)$f['id'], (string)$f['cycle_ym']),
            'included' => $rev['rounds_included'] !== null ? (int)$rev['rounds_included'] : null, 'stage' => (string)$rev['name']];
}

/** O registo à mão recusa uma frente que já tem rondas do portal (user_id 0) no ciclo atual (D28). Num v1 não faz nada. */
function pipe2_round_manual_check(PDO $pdo, array $deal, array $line): void {
    if (!pipe2_deal_is_v2($pdo, $deal)) return;
    $l = pipe2_front_get($pdo, (int)$line['id']) ?? $line;
    $st = $pdo->prepare('SELECT COUNT(*) FROM deal_rounds WHERE deal_id = ? AND line_id = ? AND cycle_ym = ? AND user_id = 0');
    $st->execute([(int)$deal['id'], (int)$l['id'], (string)($l['cycle_ym'] ?? '')]);
    if ((int)$st->fetchColumn() > 0) throw new RuntimeException(PIPE2_CC_PORTAL);
}

/**
 * Mais uma ronda numa frente v2 ($src 'portal': o envio do cliente, user_id 0 e «Sistema» no histórico; 'manual': quem
 * a registou). Fica na Revisões do funil da frente e no ciclo dela; acima das incluídas, o histórico regista «rondas»
 * (o aviso ao comercial) e correm as regras 'rounds' da Revisões para a frente (uma vez por ciclo). Corre dentro do
 * lock do negócio (as regras deduplicam com um SELECT antes do INSERT); um erro nas regras nunca desfaz a ronda.
 * Devolve ['round_id', 'count', 'over', 'msg'].
 */
function pipe2_rounds_add(PDO $pdo, array $deal, array $line, string $note, int $by, string $src): array {
    require_once __DIR__ . '/pipe2_engine.php';   // pipe2_lock_deal
    require_once __DIR__ . '/pipe2_tasks.php';    // pipe2_run_rules
    require_once __DIR__ . '/pipe2_deps.php';     // pipe2_dep_service
    require_once __DIR__ . '/deals.php';          // deal_log
    $dealId = (int)$deal['id'];
    $portal = $src === 'portal';
    $who = $portal ? null : $by;
    pipe2_lock_deal($pdo, $dealId);
    try {
        $d = deal_get($pdo, $dealId) ?: $deal;
        $l = pipe2_front_get($pdo, (int)$line['id']);
        if (!$l || (int)$l['deal_id'] !== $dealId || $l['status'] === 'merged') throw new RuntimeException(PIPE2_CC_NO_FRONT);
        $rev = pipe2_cc_revisions_of($pdo, (int)$l['funnel_id']);
        if (!$rev) throw new RuntimeException('Este serviço não tem a sub-etapa Revisões: a ronda não foi registada.');
        $cycle = (string)$l['cycle_ym'];
        $note = trim($note);
        $pdo->prepare('INSERT INTO deal_rounds (deal_id, stage_id, note, user_id, created_at, line_id, cycle_ym) VALUES (?,?,?,?,?,?,?)')
            ->execute([$dealId, (int)$rev['id'], mb_substr($note, 0, 255), $portal ? 0 : $by, date('Y-m-d H:i:s'), (int)$l['id'], $cycle]);
        $rid = (int)$pdo->lastInsertId();
        $n = pipe2_rounds_count($pdo, (int)$l['id'], $cycle);
        $inc = $rev['rounds_included'] !== null ? (int)$rev['rounds_included'] : null;
        $over = $inc !== null && $n > $inc;
        $spent = $inc !== null && $n === $inc;
        $svc = pipe2_dep_service($l);
        deal_log($pdo, $dealId, $over ? 'rounds' : 'round', [
            'note' => $svc . ': ronda ' . $n . ($inc !== null ? ' de ' . $inc . ' incluída' . ($inc === 1 ? '' : 's') : '') . ' (' . $rev['name'] . ')'
                    . ($over ? ' · acima do incluído' : ($spent ? ' · rondas incluídas esgotadas' : '')) . ($portal ? ' · portal do cliente' : '')
                    . ($note !== '' ? ' · ' . $note : ''),
            'user_id' => $who, 'system' => $portal,
        ]);
        if ($over) {
            try {
                pipe2_run_rules($pdo, $d, $rev, 'rounds', $l, $who);
            } catch (Throwable $e) {
                error_log('[topper] pipe2 rondas (negócio ' . $dealId . ', frente ' . (int)$l['id'] . '): ' . $e->getMessage());
            }
        }
    } finally {
        pipe2_unlock_deal($pdo, $dealId);
    }
    $msg = $svc . ': ronda ' . $n . ' registada' . ($inc !== null ? ' (' . $inc . ' incluída' . ($inc === 1 ? '' : 's') . ')' : '') . '.'
         . ($over ? ' Acima das incluídas: é um pedido de alteração orçamentado e o comercial foi avisado.'
                  : ($spent ? ' As rondas incluídas esgotaram: a próxima é um pedido de alteração orçamentado.' : ''));
    return ['round_id' => $rid, 'count' => $n, 'over' => $over, 'msg' => $msg];
}
