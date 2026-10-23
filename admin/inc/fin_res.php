<?php
/**
 * topper. admin — Financeiro: saldos semanais das contas e reservas dos sócios
 *
 *  · Saldos: uma vez por semana (dia em Definições › Financeiro) quem é responsável atualiza o saldo de cada
 *    conta (valor e data). Responsável: o da conta, senão o dos saldos (Definições), senão o financeiro.
 *    Cada atualização fica registada. A tarefa da semana conclui sozinha quando as contas estão em dia.
 *  · Reservas: num negócio, superadmin ou admin reserva uma parte da faturação para um sócio (% do que o
 *    cliente pagou, ou um valor fixo que se vai acumulando na proporção do que foi pago). Conta como custo
 *    do negócio (margem), sai do saldo disponível e do runway. Cada levantamento fica com data e comprovativo.
 *    O detalhe por sócio só o veem superadmin e admins; os outros veem o total reservado.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin.php';
require_once __DIR__ . '/fin_cash.php';

const FIN_RES_MODES = ['pct' => '% do que o cliente pagou', 'fixed' => 'Valor fixo'];
const FIN_STALE_DAYS = 7;
const FIN_WEEKDAYS = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo'];

function fin_res_ready(PDO $pdo): bool {
    static $ok = null;
    return $ok ??= db_has_table($pdo, 'fin_reserve_rules');
}

// ── saldos semanais ─────────────────────────────────────────

function fin_user_active(PDO $pdo, int $id): bool {
    static $c = [];
    return $c[$id] ??= $id > 0 && (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1 AND id = ' . $id)->fetchColumn() > 0;
}

/** Quem atualiza o saldo de uma conta: o responsável da conta, o dos saldos (Definições) ou o financeiro. */
function fin_cash_owner_id(PDO $pdo, ?array $acc = null): ?int {
    foreach ([(int)($acc['owner_id'] ?? 0), (int)setting($pdo, 'fin_cash_owner', 0)] as $id) if (fin_user_active($pdo, $id)) return $id;
    return fin_owner_id($pdo);
}

/** Pode atualizar o saldo desta conta: quem edita o Financeiro, ou o responsável por ela. */
function fin_can_balance(PDO $pdo, array $acc, array $me): bool {
    return can('financeiro', PERM_EDIT) || fin_cash_owner_id($pdo, $acc) === (int)$me['id'];
}

/** Dias desde a data do saldo (null: nunca atualizado). */
function fin_balance_age(array $a, ?string $today = null): ?int {
    if (empty($a['balance_date'])) return null;
    return max(0, (int)round((strtotime($today ?? today_lisbon()) - strtotime((string)$a['balance_date'])) / 86400));
}

function fin_balance_stale(array $a, ?string $today = null): bool {
    $age = fin_balance_age($a, $today);
    return $age === null || $age > FIN_STALE_DAYS;
}

/** «hoje» · «há 3 dias» · «nunca» */
function fin_age_label(?int $age): string {
    if ($age === null) return 'nunca';
    return $age === 0 ? 'hoje' : ($age === 1 ? 'ontem' : 'há ' . $age . ' dias');
}

/** O dia de atualização desta semana (AAAA-MM-DD) e a chave da semana (AAAA-Wss). */
function fin_balance_week(PDO $pdo, ?string $today = null): array {
    $t = new DateTimeImmutable($today ?? today_lisbon());
    $day = max(1, min(7, (int)setting($pdo, 'fin_cash_day', 1)));
    $ref = $t->setISODate((int)$t->format('o'), (int)$t->format('W'), $day);
    return ['date' => $ref->format('Y-m-d'), 'key' => $t->format('o-\WW'), 'day' => $day];
}

/** Grava o saldo de uma conta (valor e data), com registo. Devolve a conta como estava (para o «Anular»). */
function fin_balance_set(PDO $pdo, array $acc, $balance, string $date, ?int $by): array {
    $raw = trim((string)$balance);
    if ($raw === '') throw new RuntimeException('Indique o saldo.');
    $v = fin_amount_parse($raw);
    if ($v === null) throw new RuntimeException('O saldo não é um valor válido.');
    if ($date === '') $date = today_lisbon();
    if (!fin_date_ok($date)) throw new RuntimeException('Data do saldo inválida.');
    if ($date > today_lisbon()) throw new RuntimeException('A data do saldo não pode ser no futuro.');
    fin_balance_write($pdo, (int)$acc['id'], $v, $date, $by);
    return $acc;
}

/** Escreve o saldo, guarda no registo e fecha as tarefas da semana que ficaram em dia. */
function fin_balance_write(PDO $pdo, int $accId, float $v, string $date, ?int $by): int {
    $now = fin_now();
    $pdo->prepare('UPDATE fin_accounts SET balance = ?, balance_date = ?, balance_by = ?, balance_at = ?, updated_at = ? WHERE id = ?')->execute([round($v, 2), $date, $by, $now, $now, $accId]);
    $pdo->prepare('INSERT INTO fin_balance_log (account_id, balance, balance_date, user_id, at) VALUES (?,?,?,?,?)')->execute([$accId, round($v, 2), $date, $by, $now]);
    $log = (int)$pdo->lastInsertId();
    fin_balance_tasks_close($pdo);
    return $log;
}

/** As últimas atualizações de uma conta. */
function fin_balance_log(PDO $pdo, int $accId, int $limit = 5): array {
    $st = $pdo->prepare('SELECT l.*, u.name AS who FROM fin_balance_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.account_id = ? ORDER BY l.at DESC, l.id DESC LIMIT ' . (int)$limit);
    $st->execute([$accId]);
    return $st->fetchAll();
}

/** As contas ativas que precisam de atualização nesta semana, por responsável: [uid => [contas]]. */
function fin_balance_due(PDO $pdo, string $refDate): array {
    $out = [];
    $limit = date('Y-m-d', strtotime($refDate . ' -1 day'));       // atualizado no dia ou na véspera já conta
    foreach ($pdo->query('SELECT * FROM fin_accounts WHERE active = 1 ORDER BY position, id')->fetchAll() as $a) {
        if ($a['balance_date'] && $a['balance_date'] >= $limit) continue;
        $uid = fin_cash_owner_id($pdo, $a);
        if ($uid) $out[$uid][] = $a;
    }
    return $out;
}

/**
 * No dia escolhido (e nos seguintes da mesma semana), cada responsável com contas por atualizar recebe uma
 * tarefa «Atualizar os saldos das contas» — uma por semana. Devolve quantas criou.
 */
function fin_balance_tick(PDO $pdo, ?string $today = null): int {
    if (!fin_res_ready($pdo)) return 0;
    $today ??= today_lisbon();
    $w = fin_balance_week($pdo, $today);
    if ($today < $w['date']) return 0;
    require_once __DIR__ . '/tasks.php';
    $has = $pdo->prepare('SELECT COUNT(*) FROM fin_done WHERE okey = ?');
    $n = 0;
    foreach (fin_balance_due($pdo, $w['date']) as $uid => $accs) {
        $key = 'bal:' . $uid . ':' . $w['key'];
        $has->execute([$key]);
        if ((int)$has->fetchColumn()) continue;
        $mid = $pdo->query('SELECT team_member_id FROM users WHERE id = ' . (int)$uid)->fetchColumn();
        if (!$mid) continue;
        $tid = task_create($pdo, ['title' => 'Atualizar os saldos das contas', 'member_id' => (int)$mid, 'job_name' => '', 'deal_id' => null, 'due_date' => $w['date'], 'priority' => 'media', 'status' => 'todo',
            'description' => 'Atualização semanal da tesouraria: ' . implode(', ', array_map(fn($a) => $a['name'], $accs)) . ".\nFinanceiro › Tesouraria › Atualizar saldo (valor e data). A tarefa conclui sozinha quando as contas ficarem em dia."]);
        $pdo->prepare('INSERT INTO fin_done (okey, task_id, created_at) VALUES (?,?,?)')->execute([$key, $tid, fin_now()]);
        $n++;
    }
    return $n;
}

/** Fecha as tarefas de saldos cujas contas já estão em dia (corre depois de cada atualização). */
function fin_balance_tasks_close(PDO $pdo): void {
    $w = fin_balance_week($pdo);
    $due = fin_balance_due($pdo, $w['date']);
    $st = $pdo->query("SELECT f.okey, f.task_id FROM fin_done f JOIN tasks t ON t.id = f.task_id WHERE f.okey LIKE 'bal:%' AND t.status <> 'done'");
    foreach ($st->fetchAll() as $r) {
        [, $uid] = explode(':', (string)$r['okey']);
        if (!empty($due[(int)$uid])) continue;
        require_once __DIR__ . '/tasks.php';
        try { task_done($pdo, (int)$r['task_id']); } catch (Throwable $e) { error_log('fin balance task: ' . $e->getMessage()); }
    }
}

// ── reservas dos sócios ─────────────────────────────────────

/** Quanto o cliente já pagou de um negócio, sem IVA (pelos pagamentos; antes das faturas, o negócio pago). */
function fin_deal_received_base(PDO $pdo, int $dealId): float {
    $st = $pdo->prepare("SELECT COUNT(*) FROM fin_docs WHERE deal_id = ? AND direction = 'in' AND deleted_at IS NULL AND status <> 'void'");
    $st->execute([$dealId]);
    if (!(int)$st->fetchColumn()) {
        $st = $pdo->prepare('SELECT budget, is_paid FROM deals WHERE id = ?');
        $st->execute([$dealId]);
        $d = $st->fetch();
        return $d && (int)$d['is_paid'] ? (float)$d['budget'] : 0.0;
    }
    $st = $pdo->prepare("SELECT p.amount, f.base, f.total, f.withholding, f.doc_type FROM fin_payments p JOIN fin_docs f ON f.id = p.doc_id
                          WHERE f.deal_id = ? AND f.direction = 'in' AND p.deleted_at IS NULL AND f.deleted_at IS NULL AND f.status <> 'void'");
    $st->execute([$dealId]);
    $sum = 0.0;
    foreach ($st->fetchAll() as $p) {
        $due = max(0.01, (float)$p['total'] - (float)$p['withholding']);
        $sum += ($p['doc_type'] === 'credit' ? -1 : 1) * (float)$p['amount'] * (float)$p['base'] / $due;
    }
    return round(max(0.0, $sum), 2);
}

/** Previsto (custo do negócio) e reservado até agora (acumula com o que o cliente pagou). */
function fin_res_amounts(PDO $pdo, array $r): array {
    $budget = (float)$r['budget'];
    $rec = fin_deal_received_base($pdo, (int)$r['deal_id']);
    $v = (float)$r['value'];
    if ($r['mode'] === 'fixed') {
        $planned = $v;
        $accrued = $budget > 0 ? $v * min(1.0, $rec / $budget) : ($rec > 0 ? $v : 0.0);
    } else {
        $planned = max($budget, $rec) * $v / 100;
        $accrued = $rec * $v / 100;
    }
    return ['planned' => round($planned, 2), 'accrued' => round(min($accrued, $planned), 2), 'received' => $rec];
}

/** As regras de reserva (todas, de um negócio ou de um sócio), com o previsto e o reservado. */
function fin_res_rules(PDO $pdo, ?int $dealId = null, ?int $userId = null): array {
    if (!fin_res_ready($pdo)) return [];
    $w = ['r.deleted_at IS NULL']; $a = [];
    if ($dealId) { $w[] = 'r.deal_id = ?'; $a[] = $dealId; }
    if ($userId) { $w[] = 'r.user_id = ?'; $a[] = $userId; }
    $st = $pdo->prepare('SELECT r.*, d.code AS deal_code, d.name AS deal_name, d.budget, d.is_paid, u.name AS user_name FROM fin_reserve_rules r
                           JOIN deals d ON d.id = r.deal_id LEFT JOIN users u ON u.id = r.user_id WHERE ' . implode(' AND ', $w) . ' ORDER BY r.deal_id DESC, r.id');
    $st->execute($a);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[] = $r + fin_res_amounts($pdo, $r);
    return $out;
}

function fin_res_rule(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM fin_reserve_rules WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Valida uma regra vinda do formulário. */
function fin_res_rule_input(PDO $pdo, array $src): array {
    $deal = (int)($src['deal_id'] ?? 0);
    $st = $pdo->prepare('SELECT id, budget FROM deals WHERE id = ? AND code IS NOT NULL');
    $st->execute([$deal]);
    $d = $st->fetch();
    if (!$d) throw new RuntimeException('Escolha o negócio.');
    $uid = (int)($src['user_id'] ?? 0);
    if (!fin_user_active($pdo, $uid)) throw new RuntimeException('Escolha o sócio.');
    $mode = isset(FIN_RES_MODES[$src['mode'] ?? '']) ? (string)$src['mode'] : 'pct';
    $v = round((float)str_replace(',', '.', preg_replace('/[^\d,.]/', '', (string)($src['value'] ?? ''))), 2);
    if ($v <= 0) throw new RuntimeException($mode === 'pct' ? 'Indique a percentagem.' : 'Indique o valor.');
    if ($mode === 'pct' && $v > 100) throw new RuntimeException('A percentagem não pode passar de 100%.');
    if ($mode === 'fixed' && (float)$d['budget'] > 0 && $v > (float)$d['budget']) throw new RuntimeException('O valor passa o budget do negócio (' . eur((float)$d['budget']) . ').');
    return ['deal_id' => $deal, 'user_id' => $uid, 'mode' => $mode, 'value' => $v, 'note' => mb_substr(trim((string)($src['note'] ?? '')), 0, 200)];
}

/** Grava a regra (a do mesmo sócio no mesmo negócio é substituída). Devolve [id, linha antiga ou null]. */
function fin_res_rule_save(PDO $pdo, array $v, ?int $by): array {
    $st = $pdo->prepare('SELECT * FROM fin_reserve_rules WHERE deal_id = ? AND user_id = ? AND deleted_at IS NULL LIMIT 1');
    $st->execute([$v['deal_id'], $v['user_id']]);
    if ($old = $st->fetch()) {
        $pdo->prepare('UPDATE fin_reserve_rules SET mode = ?, value = ?, note = ? WHERE id = ?')->execute([$v['mode'], $v['value'], $v['note'], (int)$old['id']]);
        return [(int)$old['id'], $old];
    }
    $pdo->prepare('INSERT INTO fin_reserve_rules (deal_id, user_id, mode, value, note, created_by, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$v['deal_id'], $v['user_id'], $v['mode'], $v['value'], $v['note'], $by, fin_now()]);
    return [(int)$pdo->lastInsertId(), null];
}

/** «20% do que o cliente pagou» · «1.500,00 € fixos» */
function fin_res_rule_label(array $r): string {
    return $r['mode'] === 'fixed' ? eur((float)$r['value']) . ' fixos' : fin_num((float)$r['value']) . '% do que o cliente pagou';
}

/** Por sócio: reservado, levantado e por levantar. [uid => [...]] */
function fin_res_balances(PDO $pdo): array {
    if (!fin_res_ready($pdo)) return [];
    $out = [];
    foreach (fin_res_rules($pdo) as $r) {
        $u = (int)$r['user_id'];
        $out[$u] ??= ['user_id' => $u, 'name' => (string)$r['user_name'], 'accrued' => 0.0, 'planned' => 0.0, 'paid' => 0.0, 'rules' => 0];
        $out[$u]['accrued'] += $r['accrued']; $out[$u]['planned'] += $r['planned']; $out[$u]['rules']++;
    }
    $st = $pdo->query('SELECT p.user_id, u.name, SUM(p.amount) AS paid FROM fin_reserve_payouts p LEFT JOIN users u ON u.id = p.user_id WHERE p.deleted_at IS NULL GROUP BY p.user_id, u.name');
    foreach ($st->fetchAll() as $p) {
        $u = (int)$p['user_id'];
        $out[$u] ??= ['user_id' => $u, 'name' => (string)$p['name'], 'accrued' => 0.0, 'planned' => 0.0, 'paid' => 0.0, 'rules' => 0];
        $out[$u]['paid'] += (float)$p['paid'];
    }
    foreach ($out as &$b) { foreach (['accrued', 'planned', 'paid'] as $k) $b[$k] = round($b[$k], 2); $b['balance'] = round($b['accrued'] - $b['paid'], 2); }
    unset($b);
    uasort($out, fn($a, $b) => strcoll($a['name'], $b['name']));
    return $out;
}

/** O total reservado aos sócios que ainda está nas contas (sai do saldo disponível e do runway). */
function fin_res_total(PDO $pdo): float {
    return round(array_sum(array_map(fn($b) => max(0.0, $b['balance']), fin_res_balances($pdo))), 2);
}

/** Regista um levantamento de um sócio: data e comprovativo obrigatórios, até ao que tem reservado. */
function fin_res_payout_add(PDO $pdo, array $in, ?array $proof, ?int $by): int {
    $uid = (int)($in['user_id'] ?? 0);
    $bal = fin_res_balances($pdo)[$uid] ?? null;
    if (!$bal) throw new RuntimeException('Esse sócio não tem reservas.');
    $amount = fin_money($in['amount'] ?? 0);
    if ($amount <= 0) throw new RuntimeException('Indique o valor levantado.');
    if ($amount > $bal['balance'] + 0.01) throw new RuntimeException('O valor passa o que está reservado a ' . $bal['name'] . ' (' . eur(max(0, $bal['balance'])) . ').');
    $date = (string)($in['paid_on'] ?? '');
    if (!fin_date_ok($date)) throw new RuntimeException('Indique a data do levantamento.');
    if ($date > today_lisbon()) throw new RuntimeException('A data não pode ser no futuro.');
    if (!$proof) throw new RuntimeException('Junte o comprovativo da transferência (PDF ou imagem).');
    $acc = (int)($in['account_id'] ?? 0);
    if ($acc && !(int)$pdo->query('SELECT COUNT(*) FROM fin_accounts WHERE id = ' . $acc)->fetchColumn()) $acc = 0;
    [$key, $name] = fin_file_store($pdo, $proof[0], fin_file_name(['issue_date' => $date, 'party_name' => $bal['name']], 'Levantamento-reserva', $proof[1]), $proof[2], ['issue_date' => $date], 'receipt');
    $pdo->prepare('INSERT INTO fin_reserve_payouts (user_id, amount, paid_on, account_id, proof_key, proof_name, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$uid, $amount, $date, $acc ?: null, $key, $name, mb_substr(trim((string)($in['note'] ?? '')), 0, 255), $by, fin_now()]);
    return (int)$pdo->lastInsertId();
}

function fin_res_payouts(PDO $pdo, int $limit = 50): array {
    if (!fin_res_ready($pdo)) return [];
    return $pdo->query('SELECT p.*, u.name AS user_name, a.name AS account_name FROM fin_reserve_payouts p LEFT JOIN users u ON u.id = p.user_id
                         LEFT JOIN fin_accounts a ON a.id = p.account_id WHERE p.deleted_at IS NULL ORDER BY p.paid_on DESC, p.id DESC LIMIT ' . (int)$limit)->fetchAll();
}

/**
 * As reservas de um negócio no formato dos custos: entram na margem. O nome do sócio só aparece a superadmin e
 * admins; os outros veem «Reserva da equipa».
 */
function fin_res_cost_rows(PDO $pdo, int $dealId): array {
    $admin = role_is_admin((string)(current_user()['role'] ?? ''));
    $out = [];
    foreach (fin_res_rules($pdo, $dealId) as $r) {
        $out[] = ['id' => 0, 'fin_id' => 0, 'res_id' => (int)$r['id'], 'deal_id' => $dealId, 'name' => $admin ? 'Reserva · ' . $r['user_name'] : 'Reserva da equipa', 'role' => 'Reserva do sócio',
                  'contact_id' => null, 'contact_name' => '', 'amount' => $r['planned'], 'vat_mode' => 'none', 'is_closed' => $r['accrued'] >= $r['planned'] - 0.01 ? 1 : 0,
                  'notes' => ($admin ? fin_res_rule_label($r) . ' · ' : '') . 'reservado até agora ' . eur($r['accrued']), 'accrued' => $r['accrued'], 'budget_key' => null,
                  'net' => $r['planned'], 'vat' => 0.0, 'total' => $r['planned'], 'year' => null, 'month' => null];
    }
    return $out;
}
