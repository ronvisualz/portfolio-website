<?php
/**
 * topper. admin — Dashboard Executivo (§30): a leitura macro
 *
 * Não tem contas próprias: lê o Financeiro (lançamentos, salários, custos fixos, tesouraria, runway e tendência,
 * inc/fin*.php), a camada de métricas dos Relatórios (inc/metrics.php: negócios, metas, operacional, fotografia
 * diária) e junta-os no resultado (P&L) mês a mês:
 *
 *   Revenue        faturado s/ IVA pela data da fatura (antes das faturas, o negócio no mês do trabalho)
 *   custos diretos despesas com âmbito «Negócio» e as das categorias do tipo «Custo direto»; os custos da equipa
 *                  de produção da altura da folha; as reservas dos sócios (na proporção do faturado); o tempo das
 *                  pessoas declaradas na Orçamentação (horas × custo/hora interno, nos meses de execução)
 *   Gross Profit   Revenue − custos diretos
 *   operacionais   despesas da empresa do tipo «Operacional» + salários (processados, ou estimados pela ficha)
 *                  − o que foi imputado aos negócios (sem dupla contagem; nunca mais do que o custo do mês)
 *   EBITDA         Gross Profit − operacionais
 *   amortizações   registo de ativos (valor × taxa ÷ 12) + despesas do tipo «Amortização»
 *   EBIT           EBITDA − amortizações
 *   juros          empréstimos e leasings registados (tabela de amortização) + despesas do tipo «Financeiro»
 *   IRC            estimado sobre o resultado do ano até ao mês (taxa PME, taxa geral e derrama dos parâmetros),
 *                  repartido pelos meses; tributação autónoma das viaturas (parâmetros)
 *   Net Profit     EBIT − juros − IRC − tributação autónoma (estimativa até o contabilista fechar o ano)
 *
 * A fotografia mensal (exec_months) é refeita pelo cron (de hora a hora) e é dela que o Dashboard e a leitura do
 * mês leem. Os euros só saem do servidor para quem vê o Financeiro (quem chama decide com can('financeiro')).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin.php';
require_once __DIR__ . '/fin_cash.php';
require_once __DIR__ . '/fin_pay.php';
require_once __DIR__ . '/fin_res.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/team.php';   // os avisos da equipa no chat

const EXEC_TYPES = ['direct' => 'Custo direto', 'opex' => 'Operacional', 'da' => 'Amortização', 'fin' => 'Financeiro', 'tax' => 'Imposto'];
const EXEC_TYPES_HELP = [
    'direct' => 'Entra no custo dos negócios (Gross Profit): freelancers, fornecedores de produção.',
    'opex'   => 'Custo da empresa (EBITDA): renda, software, contabilidade, seguros, marketing…',
    'da'     => 'Amortizações e depreciações (EBIT). Os ativos registados já entram sozinhos.',
    'fin'    => 'Juros e comissões bancárias (Net Profit). Os empréstimos registados já entram sozinhos.',
    'tax'    => 'Impostos sobre o lucro (IRC, derrama, pagamentos por conta): não contam como custo; o resultado usa a estimativa.',
];
/** Tipos de ativo: rótulo e o parâmetro com a taxa de amortização anual. */
const EXEC_ASSET_KINDS = ['it' => ['Equipamento informático', 'depr_it'], 'software' => ['Programas de computador', 'depr_software'],
                          'av' => ['Equipamento audiovisual / câmaras', 'depr_av'], 'furniture' => ['Mobiliário', 'depr_furniture'],
                          'car' => ['Viatura', 'car_depr_rate'], 'works' => ['Obras e instalações', ''], 'other' => ['Outro', '']];
const EXEC_LOAN_KINDS = ['loan' => 'Empréstimo', 'leasing' => 'Leasing'];
const EXEC_LOAN_CAT = 'Empréstimos e leasings';
const EXEC_PERIODS = ['mes' => 'Mês', 'trim' => 'Trimestre', 'ytd' => 'Ano até hoje', '12m' => 'Últimos 12 meses'];
/** Os limites dos semáforos e do hall of fame (Definições › Geral › Dashboard executivo). */
const EXEC_SETTINGS = ['exec_gm_target' => 40, 'exec_runway_green' => 12, 'exec_runway_red' => 6, 'exec_late_green' => 15, 'exec_late_red' => 30,
                       'exec_pipe_cover' => 1.5, 'exec_ontime_green' => 85, 'exec_rounds_max' => 0.5, 'exec_conc_alert' => 50, 'exec_seller_min' => 5,
                       'exec_reading_hour' => 7];

function exec_ready(PDO $pdo): bool {
    static $ok = null;
    return $ok ??= fin_ready($pdo) && db_has_table($pdo, 'exec_months');
}

function exec_set(PDO $pdo, string $k): float {
    return (float)setting($pdo, $k, EXEC_SETTINGS[$k] ?? 0);
}

// ── meses ───────────────────────────────────────────────────

function exec_ym(?string $date = null): string {
    return substr($date ?? today_lisbon(), 0, 7);
}

function exec_ym_add(string $ym, int $n): string {
    return (new DateTimeImmutable($ym . '-01'))->modify(($n >= 0 ? '+' : '') . $n . ' month')->format('Y-m');
}

/** Os meses de $from a $to (AAAA-MM, inclusive). */
function exec_ym_list(string $from, string $to): array {
    $out = [];
    for ($m = $from; $m <= $to && count($out) < 240; $m = exec_ym_add($m, 1)) $out[] = $m;
    return $out;
}

/** «outubro», «out 25». */
function exec_month_label(string $ym, bool $short = false): string {
    $m = (int)substr($ym, 5, 2);
    return $short ? MONTHS_SHORT[$m] . ' ' . substr($ym, 2, 2) : mb_strtolower(month_name($m));
}

/** Como os dias de [de, até] se repartem pelos meses: AAAA-MM → fração (soma 1). */
function exec_spread(string $from, string $to): array {
    $a = new DateTimeImmutable(substr($from, 0, 10));
    $b = new DateTimeImmutable(substr($to, 0, 10));
    if ($b < $a) $b = $a;
    $days = $a->diff($b)->days + 1;
    $out = [];
    for ($d = $a; $d <= $b; $d = $d->modify('first day of next month')) {
        $end = $d->modify('last day of this month');
        if ($end > $b) $end = $b;
        $out[$d->format('Y-m')] = ($out[$d->format('Y-m')] ?? 0) + ($d->diff($end)->days + 1) / $days;
    }
    return $out;
}

// ── tipo contabilístico das categorias ──────────────────────

function exec_default_type(string $cat): string {
    $c = mb_strtolower(trim($cat));
    if (in_array($c, ['freelancers', 'fornecedores de produção', 'produção', 'subcontratos'], true)) return 'direct';
    if (in_array($c, ['impostos', 'irc'], true)) return 'tax';
    if (in_array($c, ['juros', 'comissões bancárias', 'encargos financeiros', mb_strtolower(EXEC_LOAN_CAT)], true)) return 'fin';
    if (in_array($c, ['amortizações', 'depreciações'], true)) return 'da';
    return 'opex';
}

/** Categoria → tipo (Definições › Financeiro; o que nunca se escolheu fica no tipo sugerido). */
function exec_cat_types(PDO $pdo): array {
    $saved = json_decode((string)setting($pdo, 'fin_cat_types', ''), true);
    $saved = is_array($saved) ? $saved : [];
    $out = [];
    foreach (fin_categories($pdo) as $c) $out[$c] = isset(EXEC_TYPES[$saved[$c] ?? '']) ? $saved[$c] : exec_default_type($c);
    foreach ($saved as $c => $t) if (!isset($out[$c]) && isset(EXEC_TYPES[$t])) $out[$c] = $t;   // categorias retiradas da lista: os lançamentos antigos guardam o tipo
    return $out;
}

function exec_cat_type(array $types, string $cat): string {
    return $types[$cat] ?? exec_default_type($cat);
}

// ── custo/hora interno (a partir do salário) ────────────────

/** Custo total anual da pessoa ÷ horas produtivas por ano; null sem ficha de salário ativa. */
function exec_hour_cost(PDO $pdo, int $uid, bool $fresh = false): ?float {
    static $memo = [];
    if (!$fresh && array_key_exists($uid, $memo)) return $memo[$uid];
    $e = fin_employee($pdo, $uid);
    if (!$e || !(int)$e['active'] || (float)$e['base_salary'] <= 0) return $memo[$uid] = null;
    $h = max(1.0, fin_p($pdo, 'productive_hours', 1600));
    return $memo[$uid] = round(fin_employee_annual($pdo, $e)['annual'] / $h, 2);
}

/** O custo/hora da aba Equipa (e da Orçamentação) passa a ser o da ficha de salário. */
function exec_sync_hourly(PDO $pdo, int $uid): void {
    $c = exec_hour_cost($pdo, $uid, true);
    if ($c === null || !db_has_column($pdo, 'users', 'hourly_cost')) return;
    $pdo->prepare('UPDATE users SET hourly_cost = ? WHERE id = ?')->execute([$c, $uid]);
}

// ── equipa interna nos negócios (custo direto) ──────────────

/** As horas de equipa interna da Orçamentação de cada negócio ganho (versão e opção que contam): negócio → [pessoa → horas]. */
function exec_team_hours(PDO $pdo): array {
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = [];
    if (!db_has_table($pdo, 'budget_sheets')) return $memo;
    require_once __DIR__ . '/budget.php';
    foreach ($pdo->query("SELECT s.* FROM budget_sheets s JOIN deals d ON d.id = s.deal_id WHERE d.phase IN ('production','done')")->fetchAll() as $s) {
        $v = budget_current_version($pdo, $s);
        if (!$v) continue;
        $dh = (float)$v['day_hours'] ?: 8.0;
        foreach (budget_lines($pdo, (int)$v['id']) as $l) {
            if ($l['kind'] !== 'team' || !$l['user_id']) continue;
            $h = budget_qty_hours((float)$l['qty'], (string)$l['qty_unit'], $dh);
            if ($h > 0) $memo[(int)$s['deal_id']][(int)$l['user_id']] = ($memo[(int)$s['deal_id']][(int)$l['user_id']] ?? 0) + $h;
        }
    }
    return $memo;
}

/** De quando a quando o negócio está em execução: Kickoff → entrega (feita, prevista, ou 30 dias). */
function exec_deal_window(array $d): array {
    $start = substr((string)($d['won_at'] ?: $d['created']), 0, 10);
    $end = substr((string)($d['delivered_at'] ?: ($d['delivery_date'] ?: '')), 0, 10);
    if ($end === '' || $end < $start) $end = (new DateTimeImmutable($start))->modify('+30 days')->format('Y-m-d');
    return [$start, $end];
}

/**
 * O tempo da equipa imputado aos negócios: horas × custo/hora interno, repartido pelos meses de execução.
 * ['months' => AAAA-MM → [pessoa → €], 'deals' => negócio → [cost, hours, people → horas]]. Quem não tem ficha de
 * salário conta horas, mas não custo (não há salário a passar para o negócio).
 */
function exec_imputed(PDO $pdo): array {
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = ['months' => [], 'deals' => []];
    $all = metrics_deals($pdo);
    foreach (exec_team_hours($pdo) as $dealId => $people) {
        $d = $all[$dealId] ?? null;
        if (!$d || $d['end'] !== 'won') continue;
        $spread = exec_spread(...exec_deal_window($d));
        $cost = 0.0;
        foreach ($people as $uid => $h) {
            $rate = exec_hour_cost($pdo, (int)$uid);
            if ($rate === null) continue;
            $v = $h * $rate;
            $cost += $v;
            foreach ($spread as $ym => $fr) $memo['months'][$ym][(int)$uid] = ($memo['months'][$ym][(int)$uid] ?? 0) + $v * $fr;
        }
        $memo['deals'][$dealId] = ['cost' => round($cost, 2), 'hours' => round(array_sum($people), 1), 'people' => $people];
    }
    return $memo;
}

// ── salários por mês ────────────────────────────────────────

/**
 * O custo salarial de cada mês: os processamentos (custo total para a empresa) ou, sem eles, a estimativa pela ficha
 * (custo anual ÷ 12) a partir da data de entrada; os lançamentos «Salários» feitos à mão (sem processamento) contam
 * tal como estão e, nesse mês, a estimativa não entra (para não contar duas vezes).
 * AAAA-MM → [total, users → €, est (pessoas estimadas), manual, ids].
 */
function exec_salaries(PDO $pdo, array $months): array {
    $out = [];
    foreach ($months as $ym) $out[$ym] = ['total' => 0.0, 'users' => [], 'est' => [], 'manual' => 0.0, 'ids' => []];
    $set = array_flip($months);
    $have = [];
    foreach ($pdo->query('SELECT user_id, year, month, company_cost FROM fin_payroll WHERE cancelled_at IS NULL')->fetchAll() as $p) {
        $ym = sprintf('%04d-%02d', (int)$p['year'], (int)$p['month']);
        $have[$ym][(int)$p['user_id']] = true;
        if (!isset($set[$ym])) continue;
        $out[$ym]['users'][(int)$p['user_id']] = ($out[$ym]['users'][(int)$p['user_id']] ?? 0) + (float)$p['company_cost'];
    }
    $st = $pdo->query("SELECT id, issue_date, created_at, base, vat, vat_deductible, doc_type FROM fin_docs WHERE direction = 'out' AND category = 'Salários' AND payroll_id IS NULL
                        AND deleted_at IS NULL AND status NOT IN ('void','review')");
    foreach ($st->fetchAll() as $d) {
        $ym = substr((string)($d['issue_date'] ?: $d['created_at']), 0, 7);
        if (!isset($set[$ym])) continue;
        $out[$ym]['manual'] += ($d['doc_type'] === 'credit' ? -1 : 1) * fin_doc_cost($d);
        $out[$ym]['ids'][] = (int)$d['id'];
    }
    $cur = exec_ym();
    foreach (fin_employees($pdo) as $e) {
        if (!(int)$e['active'] || (float)$e['base_salary'] <= 0) continue;
        $from = $e['start_date'] ? exec_ym($e['start_date']) : $cur;
        $monthly = fin_employee_annual($pdo, $e)['monthly'];
        foreach ($months as $ym) {
            if ($ym < $from || $ym > $cur || isset($have[$ym][(int)$e['user_id']]) || $out[$ym]['manual'] > 0) continue;
            $out[$ym]['users'][(int)$e['user_id']] = $monthly;
            $out[$ym]['est'][] = (int)$e['user_id'];
        }
    }
    foreach ($out as &$r) $r['total'] = round(array_sum($r['users']) + $r['manual'], 2);
    unset($r);
    return $out;
}

// ── ativos e amortizações ───────────────────────────────────

function exec_assets(PDO $pdo): array {
    if (!db_has_table($pdo, 'fin_assets')) return [];
    return $pdo->query('SELECT * FROM fin_assets WHERE deleted_at IS NULL ORDER BY acquired_on DESC, id DESC')->fetchAll();
}

/** A taxa anual por omissão de um tipo de ativo (parâmetros do Financeiro). */
function exec_asset_rate(PDO $pdo, string $kind): float {
    $p = EXEC_ASSET_KINDS[$kind][1] ?? '';
    return $p !== '' ? fin_p($pdo, $p, 0) : 0.0;
}

/** A amortização de um ativo num mês (quotas constantes a partir do mês de aquisição, até ao valor ou ao abate). */
function exec_asset_month(array $a, string $ym): float {
    $start = exec_ym($a['acquired_on']);
    if ($ym < $start || ($a['ended_on'] && $ym > exec_ym($a['ended_on']))) return 0.0;
    $monthly = (float)$a['value'] * (float)$a['rate_pct'] / 100 / 12;
    if ($monthly <= 0) return 0.0;
    $n = count(exec_ym_list($start, $ym));
    $before = $monthly * ($n - 1);
    return round(max(0.0, min($monthly, (float)$a['value'] - $before)), 2);
}

/** Já amortizado e valor líquido de um ativo até ao mês corrente. */
function exec_asset_book(array $a): array {
    $done = 0.0;
    $cur = exec_ym();
    if (exec_ym($a['acquired_on']) <= $cur) foreach (exec_ym_list(exec_ym($a['acquired_on']), $cur) as $ym) $done += exec_asset_month($a, $ym);
    return ['done' => round($done, 2), 'net' => round(max(0, (float)$a['value'] - $done), 2), 'monthly' => round((float)$a['value'] * (float)$a['rate_pct'] / 1200, 2)];
}

function exec_asset_input(PDO $pdo, array $src): array {
    $name = mb_substr(trim((string)($src['name'] ?? '')), 0, 160);
    if ($name === '') throw new RuntimeException('Indique o nome do ativo.');
    $kind = isset(EXEC_ASSET_KINDS[$src['kind'] ?? '']) ? (string)$src['kind'] : 'other';
    $value = fin_money($src['value'] ?? 0);
    if ($value <= 0) throw new RuntimeException('Indique o valor de aquisição (sem IVA dedutível).');
    $date = (string)($src['acquired_on'] ?? '');
    if (!fin_date_ok($date)) throw new RuntimeException('Indique a data de aquisição.');
    $rate = trim((string)($src['rate_pct'] ?? ''));
    $rate = $rate === '' ? exec_asset_rate($pdo, $kind) : (float)str_replace(',', '.', $rate);
    if ($rate < 0 || $rate > 100) throw new RuntimeException('A taxa de amortização é de 0 a 100% ao ano.');
    $end = (string)($src['ended_on'] ?? '');
    if ($end !== '' && (!fin_date_ok($end) || $end < $date)) throw new RuntimeException('A data de abate tem de ser depois da aquisição.');
    $motor = $kind === 'car' && isset(FIN_CAR_MOTORS_EXEC[$src['car_motor'] ?? '']) ? (string)$src['car_motor'] : '';
    return ['name' => $name, 'kind' => $kind, 'value' => $value, 'acquired_on' => $date, 'rate_pct' => round($rate, 2), 'car_motor' => $motor,
            'car_phev_ok' => $motor === 'phev' && !empty($src['car_phev_ok']) ? 1 : 0, 'ended_on' => $end !== '' ? $end : null,
            'notes' => mb_substr(trim((string)($src['notes'] ?? '')), 0, 500)];
}
/** Motorizações (as mesmas do simulador), para a tributação autónoma das viaturas. */
const FIN_CAR_MOTORS_EXEC = ['ev' => 'Elétrico', 'phev' => 'Híbrido plug-in', 'hybrid' => 'Híbrido normal', 'petrol' => 'Gasolina', 'diesel' => 'Gasóleo', 'gpl' => 'GPL / GNV'];

// ── empréstimos e leasings ──────────────────────────────────

function exec_loans(PDO $pdo): array {
    if (!db_has_table($pdo, 'fin_loans')) return [];
    return $pdo->query('SELECT * FROM fin_loans WHERE deleted_at IS NULL ORDER BY start_date DESC, id DESC')->fetchAll();
}

/**
 * O plano de um empréstimo (prestação constante, juro sobre o capital em dívida; o valor residual do leasing fica no
 * fim): AAAA-MM → [payment, interest, principal, balance], a começar no mês seguinte ao início.
 */
function exec_loan_plan(array $l): array {
    $P = (float)$l['principal'];
    $n = max(1, (int)$l['months']);
    $r = (float)$l['rate_pct'] / 100 / 12;
    $res = min($P, max(0.0, (float)$l['residual']));
    $pv = $P - $res / ((1 + $r) ** $n);
    $pay = $r > 0 ? $pv * $r / (1 - (1 + $r) ** -$n) : ($P - $res) / $n;
    $bal = $P;
    $out = [];
    $ym = exec_ym_add(exec_ym($l['start_date']), 1);
    for ($k = 1; $k <= $n; $k++, $ym = exec_ym_add($ym, 1)) {
        $int = $bal * $r;
        $prin = $pay - $int;
        if ($k === $n) $prin = $bal - $res;
        $bal -= $prin;
        $out[$ym] = ['payment' => round($prin + $int, 2), 'interest' => round($int, 2), 'principal' => round($prin, 2), 'balance' => round(max(0, $bal), 2)];
    }
    return $out;
}

function exec_loan_input(array $src): array {
    $name = mb_substr(trim((string)($src['name'] ?? '')), 0, 160);
    if ($name === '') throw new RuntimeException('Indique o nome (ex.: Leasing carrinha, Empréstimo equipamento).');
    $P = fin_money($src['principal'] ?? 0);
    if ($P <= 0) throw new RuntimeException('Indique o capital.');
    $rate = (float)str_replace(',', '.', (string)($src['rate_pct'] ?? 0));
    if ($rate < 0 || $rate > 40) throw new RuntimeException('A taxa de juro anual (TAN) vai de 0 a 40%.');
    $n = (int)($src['months'] ?? 0);
    if ($n < 1 || $n > 480) throw new RuntimeException('O prazo vai de 1 a 480 meses.');
    $start = (string)($src['start_date'] ?? '');
    if (!fin_date_ok($start)) throw new RuntimeException('Indique a data de início.');
    $res = fin_money($src['residual'] ?? 0);
    if ($res < 0 || $res >= $P) throw new RuntimeException('O valor residual tem de ser menor do que o capital.');
    return ['name' => $name, 'kind' => isset(EXEC_LOAN_KINDS[$src['kind'] ?? '']) ? (string)$src['kind'] : 'loan', 'lender' => mb_substr(trim((string)($src['lender'] ?? '')), 0, 190),
            'principal' => $P, 'rate_pct' => round($rate, 3), 'months' => $n, 'start_date' => $start, 'due_day' => max(1, min(28, (int)($src['due_day'] ?? (int)substr($start, 8, 2)))),
            'residual' => $res, 'asset_id' => (int)($src['asset_id'] ?? 0) ?: null, 'gen_docs' => !isset($src['gen_docs']) || !empty($src['gen_docs']) ? 1 : 0,
            'notes' => mb_substr(trim((string)($src['notes'] ?? '')), 0, 500)];
}

/**
 * As prestações «Por pagar» de cada empréstimo, até ao fim do mês corrente (como os custos fixos): entram na
 * tesouraria; no resultado só conta o juro (o capital não é custo). Devolve quantas criou.
 */
function exec_loan_tick(PDO $pdo, ?string $today = null): int {
    if (!db_has_table($pdo, 'fin_loans') || !db_has_column($pdo, 'fin_docs', 'loan_id')) return 0;
    $until = exec_ym($today ?? today_lisbon());
    $n = 0;
    $have = $pdo->prepare('SELECT COUNT(*) FROM fin_docs WHERE loan_id = ? AND issue_date = ?');
    foreach (exec_loans($pdo) as $l) {
        if (!(int)$l['gen_docs']) continue;
        $created = exec_ym((string)$l['created_at']);
        foreach (exec_loan_plan($l) as $ym => $p) {
            if ($ym > $until) break;
            if ($ym < $created) continue;   // o que já foi pago antes de o registar não volta a aparecer
            $date = $ym . '-' . sprintf('%02d', min((int)$l['due_day'], (int)date('t', strtotime($ym . '-01'))));
            $have->execute([(int)$l['id'], $date]);
            if ((int)$have->fetchColumn()) continue;
            fin_doc_create($pdo, ['direction' => 'out', 'doc_type' => 'other', 'scope' => 'company', 'party_kind' => 'other', 'party_name' => $l['lender'] ?: $l['name'],
                'category' => EXEC_LOAN_CAT, 'description' => $l['name'] . ' · prestação de ' . month_year((int)substr($ym, 5, 2), (int)substr($ym, 0, 4))
                    . ' (capital ' . eur($p['principal']) . ' + juros ' . eur($p['interest']) . ')',
                'base' => $p['payment'], 'vat_rate' => 0, 'vat' => 0, 'vat_deductible' => 1, 'total' => $p['payment'], 'issue_date' => $date, 'due_date' => $date, 'status' => 'to_pay',
                'loan_id' => (int)$l['id']], null);
            $n++;
        }
    }
    return $n;
}

// ── o resultado mês a mês ───────────────────────────────────

/** Um mês vazio. */
function exec_pl_blank(): array {
    return ['revenue' => 0.0, 'received' => 0.0, 'direct' => 0.0, 'direct_parts' => ['docs' => 0.0, 'crew' => 0.0, 'reserves' => 0.0, 'team' => 0.0],
            'opex' => 0.0, 'opex_cats' => [], 'salaries' => 0.0, 'imputed' => 0.0, 'imputed_raw' => 0.0, 'da' => 0.0, 'da_assets' => 0.0,
            'interest' => 0.0, 'irc' => 0.0, 'ta' => 0.0, 'est' => 0, 'capped' => false,
            'ids' => ['rev' => [], 'direct' => [], 'opex' => [], 'sal' => [], 'da' => [], 'fin' => []]];
}

/**
 * O P&L de cada mês de $fromYm (alargado a janeiro desse ano, por causa do IRC do ano) a $toYm.
 * AAAA-MM → [revenue, received, direct, gp, opex, ebitda, da, ebit, interest, ebt, irc, ta, net, … e os ids por linha].
 */
function exec_pl_compute(PDO $pdo, string $fromYm, string $toYm): array {
    $fromYm = substr($fromYm, 0, 4) . '-01';
    $months = exec_ym_list($fromYm, $toYm);
    $rows = array_fill_keys($months, exec_pl_blank());
    $from = $fromYm . '-01';
    $to = date('Y-m-t', strtotime($toYm . '-01'));
    $types = exec_cat_types($pdo);
    $sign = fn(array $d) => $d['doc_type'] === 'credit' ? -1 : 1;
    $legacy = [];   // negócios sem nenhuma fatura (da altura da folha): receita e custos no mês do trabalho
    foreach ($pdo->query("SELECT id, year, month, budget, is_paid, expenses_mode, expenses_manual, created_at FROM deals d WHERE code IS NOT NULL AND phase IN ('production','done') AND budget > 0
                          AND NOT EXISTS (SELECT 1 FROM fin_docs f WHERE f.deal_id = d.id AND f.direction = 'in' AND f.deleted_at IS NULL AND f.status <> 'void')")->fetchAll() as $d) {
        $legacy[(int)$d['id']] = $d + ['ym' => $d['year'] ? sprintf('%04d-%02d', (int)$d['year'], (int)($d['month'] ?: 12)) : exec_ym((string)$d['created_at'])];
    }

    // receita: faturas pela data (as notas de crédito abatem); recebido pelos pagamentos (fin_series)
    $inv = [];   // negócio → [AAAA-MM → base] (para repartir as reservas)
    $st = $pdo->prepare("SELECT id, deal_id, issue_date, base, doc_type FROM fin_docs WHERE direction = 'in' AND deleted_at IS NULL AND status NOT IN ('void','requested','review') AND issue_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $d) {
        $ym = substr($d['issue_date'], 0, 7);
        if (!isset($rows[$ym])) continue;
        $v = $sign($d) * (float)$d['base'];
        $rows[$ym]['revenue'] += $v;
        $rows[$ym]['ids']['rev'][] = 'f:' . (int)$d['id'];
        if ($d['deal_id']) $inv[(int)$d['deal_id']][$ym] = ($inv[(int)$d['deal_id']][$ym] ?? 0) + $v;
    }
    foreach ($legacy as $id => $d) {
        if (!isset($rows[$d['ym']])) continue;
        $rows[$d['ym']]['revenue'] += (float)$d['budget'];
        $rows[$d['ym']]['ids']['rev'][] = 'd:' . $id;
    }
    foreach (fin_series($pdo, $months) as $ym => $s) $rows[$ym]['received'] = $s['received'];

    // despesas: âmbito «Negócio» e categorias do tipo «Custo direto» → diretos; o resto pelo tipo da categoria
    // (as prestações dos empréstimos e os processamentos de salários têm conta própria; o IVA não é custo)
    $dealOut = [];
    $st = $pdo->prepare("SELECT id, deal_id, scope, category, issue_date, created_at, base, vat, vat_deductible, doc_type, loan_id, payroll_id FROM fin_docs
                          WHERE direction = 'out' AND deleted_at IS NULL AND status NOT IN ('void','review') AND category <> 'IVA'
                            AND COALESCE(issue_date, DATE(created_at)) BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $d) {
        if ($d['deal_id']) $dealOut[(int)$d['deal_id']] = true;
        if ($d['loan_id'] || $d['payroll_id'] || $d['category'] === 'Salários') continue;
        $ym = substr((string)($d['issue_date'] ?: $d['created_at']), 0, 7);
        if (!isset($rows[$ym])) continue;
        $v = $sign($d) * fin_doc_cost($d);
        $t = $d['scope'] === 'deal' ? 'direct' : exec_cat_type($types, (string)$d['category']);
        if ($t === 'direct') { $rows[$ym]['direct_parts']['docs'] += $v; $rows[$ym]['ids']['direct'][] = 'f:' . (int)$d['id']; }
        elseif ($t === 'opex') { $k = $d['category'] !== '' ? $d['category'] : 'Outros'; $rows[$ym]['opex_cats'][$k] = ($rows[$ym]['opex_cats'][$k] ?? 0) + $v; $rows[$ym]['ids']['opex'][] = 'f:' . (int)$d['id']; }
        elseif ($t === 'da') { $rows[$ym]['da'] += $v; $rows[$ym]['ids']['da'][] = 'f:' . (int)$d['id']; }
        elseif ($t === 'fin') { $rows[$ym]['interest'] += $v; $rows[$ym]['ids']['fin'][] = 'f:' . (int)$d['id']; }
        // 'tax': imposto sobre o lucro — não é custo (o resultado usa a estimativa)
    }
    // custos de produção da altura da folha (custos lançados na ficha do negócio, fora do Financeiro)
    require_once __DIR__ . '/finance.php';
    $rate = vat_rate($pdo);
    $deals = [];
    foreach ($pdo->query("SELECT id, year, month, created_at, expenses_mode, expenses_manual FROM deals WHERE code IS NOT NULL AND phase IN ('production','done')")->fetchAll() as $d) $deals[(int)$d['id']] = $d;
    foreach ($pdo->query('SELECT * FROM costs WHERE deal_id IS NOT NULL')->fetchAll() as $c) {
        $d = $deals[(int)$c['deal_id']] ?? null;
        if (!$d || $d['expenses_mode'] === 'manual') continue;
        $ym = $c['year'] ? sprintf('%04d-%02d', (int)$c['year'], (int)($c['month'] ?: ($d['month'] ?: 12))) : ($d['year'] ? sprintf('%04d-%02d', (int)$d['year'], (int)($d['month'] ?: 12)) : exec_ym((string)$d['created_at']));
        if (!isset($rows[$ym])) continue;
        $rows[$ym]['direct_parts']['crew'] += cost_net($c, $rate);
        $rows[$ym]['ids']['direct'][] = 'd:' . (int)$c['deal_id'];
    }
    foreach ($deals as $id => $d) {   // negócios com «gastos» escritos à mão (sem despesas no Financeiro)
        if ($d['expenses_mode'] !== 'manual' || isset($dealOut[$id]) || (float)$d['expenses_manual'] <= 0) continue;
        $ym = $d['year'] ? sprintf('%04d-%02d', (int)$d['year'], (int)($d['month'] ?: 12)) : exec_ym((string)$d['created_at']);
        if (!isset($rows[$ym])) continue;
        $rows[$ym]['direct_parts']['crew'] += (float)$d['expenses_manual'];
        $rows[$ym]['ids']['direct'][] = 'd:' . $id;
    }
    // reservas dos sócios: custo do negócio, na proporção do que já foi faturado (ou no mês do trabalho)
    if (fin_res_ready($pdo)) {
        foreach (fin_res_rules($pdo) as $r) {
            $id = (int)$r['deal_id'];
            $planned = (float)$r['planned'];
            if ($planned <= 0) continue;
            if (isset($inv[$id])) {
                $tot = max((float)$r['budget'], array_sum($inv[$id])) ?: 1;
                foreach ($inv[$id] as $ym => $v) if (isset($rows[$ym])) { $rows[$ym]['direct_parts']['reserves'] += $planned * $v / $tot; $rows[$ym]['ids']['direct'][] = 'd:' . $id; }
            } elseif (isset($legacy[$id]) && isset($rows[$legacy[$id]['ym']])) {
                $rows[$legacy[$id]['ym']]['direct_parts']['reserves'] += $planned;
                $rows[$legacy[$id]['ym']]['ids']['direct'][] = 'd:' . $id;
            }
        }
    }

    // salários: operacionais, menos o tempo imputado aos negócios (nunca mais do que o custo real de cada pessoa)
    $sal = exec_salaries($pdo, $months);
    $imp = exec_imputed($pdo);
    foreach ($months as $ym) {
        $s = $sal[$ym];
        $raw = array_sum($imp['months'][$ym] ?? []);
        $capped = 0.0;
        if ($s['manual'] > 0 && !$s['users']) {
            $capped = min($raw, $s['manual']);       // salários lançados à mão: o teto é o total do mês
        } else {
            foreach ($imp['months'][$ym] ?? [] as $uid => $v) $capped += min($v, (float)($s['users'][$uid] ?? 0));
        }
        $rows[$ym]['salaries'] = $s['total'];
        $rows[$ym]['imputed_raw'] = round($raw, 2);
        $rows[$ym]['imputed'] = round($capped, 2);
        $rows[$ym]['capped'] = $raw - $capped > 1;
        $rows[$ym]['est'] = count($s['est']);
        $rows[$ym]['ids']['sal'] = array_map(fn($i) => 'f:' . $i, $s['ids']);
        $rows[$ym]['direct_parts']['team'] = round($capped, 2);
    }

    // amortizações dos ativos registados e juros dos empréstimos
    $assets = exec_assets($pdo);
    foreach ($assets as $a) foreach ($months as $ym) { $v = exec_asset_month($a, $ym); if ($v > 0) { $rows[$ym]['da_assets'] += $v; } }
    foreach (exec_loans($pdo) as $l) foreach (exec_loan_plan($l) as $ym => $p) if (isset($rows[$ym])) $rows[$ym]['interest'] += $p['interest'];

    // tributação autónoma das viaturas: depreciação de cada viatura + despesas «Viaturas» (à taxa da viatura principal)
    require_once __DIR__ . '/fin_sim.php';
    $cars = array_values(array_filter($assets, fn($a) => $a['kind'] === 'car'));
    usort($cars, fn($a, $b) => (float)$b['value'] <=> (float)$a['value']);
    $taRate = fn(array $a) => fin_car_ta_rate($pdo, $a['car_motor'] ?: 'diesel', (float)$a['value'], (int)$a['car_phev_ok'] === 1);
    $defRate = $cars ? $taRate($cars[0]) : ((fin_pl($pdo, 'ta_comb')[0] ?? 8) / 100);
    foreach ($months as $ym) {
        foreach ($cars as $a) $rows[$ym]['ta'] += exec_asset_month($a, $ym) * $taRate($a);
        $rows[$ym]['ta'] += max(0.0, (float)($rows[$ym]['opex_cats']['Viaturas'] ?? 0)) * $defRate;
    }

    // os totais de cada mês e o IRC (sobre o resultado do ano até ao mês, repartido pelos meses)
    $ytd = []; $ircYtd = [];
    foreach ($months as $ym) {
        $r = &$rows[$ym];
        $r['direct'] = round(array_sum($r['direct_parts']), 2);
        $r['revenue'] = round($r['revenue'], 2);
        $r['gp'] = round($r['revenue'] - $r['direct'], 2);
        $r['opex'] = round(array_sum($r['opex_cats']) + $r['salaries'] - $r['imputed'], 2);
        $r['ebitda'] = round($r['gp'] - $r['opex'], 2);
        $r['da'] = round($r['da'] + $r['da_assets'], 2);
        $r['ebit'] = round($r['ebitda'] - $r['da'], 2);
        $r['interest'] = round($r['interest'], 2);
        $r['ebt'] = round($r['ebit'] - $r['interest'], 2);
        $y = substr($ym, 0, 4);
        $ytd[$y] = ($ytd[$y] ?? 0) + $r['ebt'];
        $cum = fin_irc($pdo, max(0.0, $ytd[$y]));
        $r['irc'] = round($cum - ($ircYtd[$y] ?? 0), 2);
        $ircYtd[$y] = $cum;
        $r['ta'] = round($r['ta'], 2);
        $r['net'] = round($r['ebt'] - $r['irc'] - $r['ta'], 2);
        foreach ($r['opex_cats'] as &$v) $v = round($v, 2);
        unset($v);
        foreach ($r['direct_parts'] as &$v) $v = round($v, 2);
        unset($v);
        foreach ($r['ids'] as &$v) $v = array_values(array_unique($v));
        unset($v);
    }
    unset($r);
    return $rows;
}

/** Soma meses do P&L e calcula as margens (null sem receita). */
function exec_pl_sum(array $rows): array {
    $s = exec_pl_blank();
    foreach (['gp', 'ebitda', 'ebit', 'ebt', 'net'] as $k) $s[$k] = 0.0;
    $s['months'] = 0;
    foreach ($rows as $r) {
        foreach (['revenue', 'received', 'direct', 'gp', 'opex', 'ebitda', 'da', 'ebit', 'interest', 'ebt', 'irc', 'ta', 'net', 'salaries', 'imputed', 'imputed_raw', 'da_assets'] as $k) $s[$k] += (float)($r[$k] ?? 0);
        foreach ($r['direct_parts'] ?? [] as $k => $v) $s['direct_parts'][$k] += $v;
        foreach ($r['opex_cats'] ?? [] as $k => $v) $s['opex_cats'][$k] = ($s['opex_cats'][$k] ?? 0) + $v;
        foreach ($r['ids'] ?? [] as $k => $v) $s['ids'][$k] = array_merge($s['ids'][$k], $v);
        $s['est'] = max($s['est'], (int)($r['est'] ?? 0));
        $s['capped'] = $s['capped'] || !empty($r['capped']);
        $s['months']++;
    }
    foreach ($s as $k => $v) if (is_float($v)) $s[$k] = round($v, 2);
    arsort($s['opex_cats']);
    $m = fn(float $v) => $s['revenue'] > 0 ? $v / $s['revenue'] * 100 : null;
    $s['gm'] = $m($s['gp']); $s['ebitda_m'] = $m($s['ebitda']); $s['om'] = $m($s['ebit']); $s['nm'] = $m($s['net']);
    return $s;
}

// ── a fotografia mensal ─────────────────────────────────────

/** Refaz a fotografia de 24 meses para trás até ao mês corrente (com o ano anterior inteiro, para o IRC). */
function exec_snapshot_refresh(PDO $pdo): int {
    if (!exec_ready($pdo)) return 0;
    $to = exec_ym();
    $rows = exec_pl_compute($pdo, exec_ym_add($to, -24), $to);
    $sql = db_is_sqlite($pdo) ? 'INSERT OR REPLACE INTO exec_months (ym, data, updated_at) VALUES (?,?,?)' : 'REPLACE INTO exec_months (ym, data, updated_at) VALUES (?,?,?)';
    $st = $pdo->prepare($sql);
    $now = date('Y-m-d H:i:s');
    foreach ($rows as $ym => $r) $st->execute([$ym, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $now]);
    metrics_setting_set($pdo, 'exec_snap_at', $now);
    return count($rows);
}

/** Os meses da fotografia (refaz-se se faltar o mês corrente ou se tiver mais de 2 horas). AAAA-MM → linha. */
function exec_months(PDO $pdo): array {
    static $memo = null;
    if ($memo !== null) return $memo;
    if (!exec_ready($pdo)) return $memo = [];
    $at = (string)setting($pdo, 'exec_snap_at', '');
    $cur = exec_ym();
    $has = (int)$pdo->query('SELECT COUNT(*) FROM exec_months WHERE ym = ' . $pdo->quote($cur))->fetchColumn();
    if (!$has || $at === '' || strtotime($at) < time() - 7200) exec_snapshot_refresh($pdo);
    $memo = [];
    foreach ($pdo->query('SELECT ym, data FROM exec_months ORDER BY ym')->fetchAll() as $r) $memo[$r['ym']] = json_decode((string)$r['data'], true) ?: exec_pl_blank();
    return $memo;
}

function exec_snap_at(PDO $pdo): string {
    return (string)setting($pdo, 'exec_snap_at', '');
}

/**
 * Um período do P&L: os meses, os do período anterior e os do mesmo período do ano anterior, com os rótulos.
 * mes = mês em curso · trim = trimestre em curso · ytd = ano até hoje · 12m = últimos 12 meses (com o atual).
 */
function exec_period(string $p, ?string $today = null): array {
    $cur = exec_ym($today);
    $y = (int)substr($cur, 0, 4); $m = (int)substr($cur, 5, 2);
    $day = (int)substr($today ?? today_lisbon(), 8, 2);
    switch ($p) {
        case 'trim':
            $q = intdiv($m - 1, 3);
            $from = sprintf('%04d-%02d', $y, $q * 3 + 1);
            $prevFrom = exec_ym_add($from, -3);
            $label = ($q + 1) . '.º trimestre de ' . $y;
            $prevLabel = (($q + 3) % 4 + 1) . '.º trimestre' . ($q === 0 ? ' de ' . ($y - 1) : '');
            $months = exec_ym_list($from, $cur);
            $prev = exec_ym_list($prevFrom, exec_ym_add($prevFrom, count($months) - 1));
            break;
        case 'ytd':
            $months = exec_ym_list($y . '-01', $cur);
            $prev = exec_ym_list(($y - 1) . '-01', ($y - 1) . '-' . sprintf('%02d', $m));
            $label = $y . ' até hoje'; $prevLabel = 'o mesmo período de ' . ($y - 1);
            break;
        case '12m':
            $months = exec_ym_list(exec_ym_add($cur, -11), $cur);
            $prev = exec_ym_list(exec_ym_add($cur, -23), exec_ym_add($cur, -12));
            $label = 'Últimos 12 meses'; $prevLabel = 'os 12 meses antes';
            break;
        default:
            $p = 'mes';
            $months = [$cur];
            $prev = [exec_ym_add($cur, -1)];
            $label = ucfirst(exec_month_label($cur)) . ' (até dia ' . $day . ')'; $prevLabel = exec_month_label($prev[0]);
    }
    $yoy = array_map(fn($ym) => exec_ym_add($ym, -12), $months);
    $sameYoy = $p === 'ytd' || $p === '12m';   // aí o período anterior já é o do ano anterior
    return ['p' => $p, 'months' => $months, 'prev' => $prev, 'yoy' => $sameYoy ? [] : $yoy, 'label' => $label, 'prev_label' => $prevLabel,
            'yoy_label' => $sameYoy ? '' : 'o mesmo período de ' . ($y - 1), 'from' => $months[0] . '-01', 'to' => date('Y-m-d', strtotime(end($months) . '-01 +1 month'))];
}

/** O P&L de um conjunto de meses, a partir da fotografia. */
function exec_pl(PDO $pdo, array $months): array {
    $all = exec_months($pdo);
    return exec_pl_sum(array_intersect_key($all, array_flip($months)));
}

/** Uma série de 12 meses (até ao corrente) de uma linha do P&L, para as mini-linhas. */
function exec_series(PDO $pdo, string $key, int $n = 12): array {
    $all = exec_months($pdo);
    $out = [];
    foreach (exec_ym_list(exec_ym_add(exec_ym(), -($n - 1)), exec_ym()) as $ym) {
        $r = $all[$ym] ?? null;
        if ($key === 'gm') $out[$ym] = $r && $r['revenue'] > 0 ? $r['gp'] / $r['revenue'] * 100 : null;
        else $out[$ym] = $r ? (float)($r[$key] ?? 0) : 0.0;
    }
    return $out;
}

// ── indicadores macro ───────────────────────────────────────

/**
 * Clientes no período: receita por cliente (faturas; antes delas, os negócios), a parte dos 3 maiores e a parte que
 * vem de clientes que já tinham comprado antes. [total, top3 → [nome, valor, ids], top3_pct, recurring_pct, n].
 */
function exec_clients(PDO $pdo, string $from, string $to): array {
    $names = $pdo->query('SELECT id, name FROM contacts')->fetchAll(PDO::FETCH_KEY_PAIR);
    $key = function (array $r) use ($names): string {
        if ($r['contact_id'] && isset($names[(int)$r['contact_id']])) return 'c' . (int)$r['contact_id'];
        if (($r['party_nif'] ?? '') !== '') return 'n' . $r['party_nif'];
        return 'p' . mb_strtolower(trim((string)($r['party_name'] ?? '')));
    };
    $label = fn(array $r) => $r['contact_id'] && isset($names[(int)$r['contact_id']]) ? $names[(int)$r['contact_id']] : ((string)($r['party_name'] ?? '') ?: '—');
    $first = []; $by = [];
    $rows = $pdo->query("SELECT f.id, f.contact_id, f.party_nif, f.party_name, f.issue_date, f.base, f.doc_type FROM fin_docs f WHERE f.direction = 'in' AND f.deleted_at IS NULL
                           AND f.status NOT IN ('void','requested','review') AND f.issue_date IS NOT NULL ORDER BY f.issue_date")->fetchAll();
    foreach ($pdo->query("SELECT d.id, d.contact_id, d.company AS party_name, d.year, d.month, d.created_at, d.budget FROM deals d WHERE d.code IS NOT NULL AND d.phase IN ('production','done') AND d.budget > 0
                          AND NOT EXISTS (SELECT 1 FROM fin_docs f WHERE f.deal_id = d.id AND f.direction = 'in' AND f.deleted_at IS NULL AND f.status <> 'void')")->fetchAll() as $d) {
        $rows[] = ['id' => 0, 'deal' => (int)$d['id'], 'contact_id' => $d['contact_id'], 'party_nif' => '', 'party_name' => $d['party_name'], 'base' => $d['budget'], 'doc_type' => 'invoice',
                   'issue_date' => $d['year'] ? sprintf('%04d-%02d-01', (int)$d['year'], (int)($d['month'] ?: 12)) : substr((string)$d['created_at'], 0, 10)];
    }
    usort($rows, fn($a, $b) => strcmp($a['issue_date'], $b['issue_date']));
    $total = 0.0; $rec = 0.0;
    foreach ($rows as $r) {
        $k = $key($r);
        $first[$k] ??= $r['issue_date'];
        if ($r['issue_date'] < $from || $r['issue_date'] >= $to) continue;
        $v = ($r['doc_type'] === 'credit' ? -1 : 1) * (float)$r['base'];
        $total += $v;
        if ($first[$k] < $from) $rec += $v;
        $by[$k] ??= ['name' => $label($r), 'value' => 0.0, 'ids' => []];
        $by[$k]['value'] += $v;
        $by[$k]['ids'][] = !empty($r['deal']) ? 'd:' . $r['deal'] : 'f:' . $r['id'];
    }
    uasort($by, fn($a, $b) => $b['value'] <=> $a['value']);
    $top = array_slice(array_values($by), 0, 3);
    $t3 = array_sum(array_column($top, 'value'));
    return ['total' => round($total, 2), 'top3' => $top, 'top3_pct' => $total > 0 ? $t3 / $total * 100 : null, 'recurring_pct' => $total > 0 ? $rec / $total * 100 : null, 'n' => count($by)];
}

/** A equipa (para a receita por pessoa): contas ativas. */
function exec_team_size(PDO $pdo): int {
    return max(1, (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn());
}

// ── previsão até ao fim do ano ──────────────────────────────

/**
 * Previsão do ano = realizado (faturado até hoje) + ganho por faturar (backlog) + pipeline ponderado (fotografia) +
 * tendência (o que a tendência de receita espera para os meses que faltam, para além do que já se conhece), com a
 * banda pessimista/otimista (fatores da tendência dos parâmetros) e a distância à meta anual.
 */
function exec_forecast(PDO $pdo): array {
    $cur = exec_ym();
    $y = (int)substr($cur, 0, 4);
    $realized = exec_pl($pdo, exec_ym_list($y . '-01', $cur))['revenue'];
    $pipe = fin_pipeline($pdo);
    $snapDay = (string)$pdo->query("SELECT MAX(day) FROM metrics_daily WHERE scope = ''")->fetchColumn();
    $weighted = 0.0; $open = 0.0;
    if ($snapDay !== '') {
        $st = $pdo->prepare("SELECT mkey, value FROM metrics_daily WHERE scope = '' AND day = ? AND mkey IN ('pipeline_ponderado','pipeline_aberto')");
        $st->execute([$snapDay]);
        $kv = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $weighted = (float)($kv['pipeline_ponderado'] ?? 0); $open = (float)($kv['pipeline_aberto'] ?? 0);
    }
    $left = 12 - (int)substr($cur, 5, 2);
    $tr = fin_trend($pdo, $left + 1);
    $proj = array_slice($tr['proj'], 1, null, true);   // os meses depois do corrente
    $sum = fn(string $k) => array_sum(array_map(fn($p) => (float)$p[$k], $proj));
    $fp = fin_p($pdo, 'trend_pess', 0.5); $fo = fin_p($pdo, 'trend_opt', 1.5);
    $known = $pipe['won'] + $weighted;
    $trend = max(0.0, $sum('real') - $known);
    $real = $realized + $known + $trend;
    $wP = $weighted * $fp; $wO = min($open, $weighted * $fo);
    $pess = $realized + $pipe['won'] + $wP + max(0.0, $sum('pess') - $pipe['won'] - $wP);
    $opt = $realized + $pipe['won'] + $wO + max(0.0, $sum('opt') - $pipe['won'] - $wO);
    $goal = (float)setting($pdo, 'meta_anual_' . $y, 0);
    return ['year' => $y, 'realized' => round($realized, 2), 'backlog' => round($pipe['won'], 2), 'backlog_n' => $pipe['won_n'], 'weighted' => round($weighted, 2),
            'trend' => round($trend, 2), 'total' => round($real, 2), 'pess' => round(min($pess, $real), 2), 'opt' => round(max($opt, $real), 2),
            'goal' => $goal, 'gap' => $goal > 0 ? round($goal - $real, 2) : null, 'snap_day' => $snapDay, 'months_left' => $left, 'backlog_ids' => array_map(fn($r) => (int)$r['id'], $pipe['won_rows'])];
}

// ── saúde da empresa (semáforos) ────────────────────────────

/**
 * Os quatro semáforos: [key, title, state (ok|warn|bad|none), sentence, url, money (só Financeiro)].
 * As regras e os limites vêm das Definições (Geral › Dashboard executivo).
 */
function exec_health(PDO $pdo, bool $money): array {
    $out = [];
    $deals = metrics_deals($pdo);
    $pct = fn(?float $v) => $v === null ? '—' : str_replace('.', ',', (string)round($v)) . '%';
    if ($money) {
        // Finanças: últimos 3 meses (com o atual)
        $cur = exec_ym();
        $pl = exec_pl($pdo, exec_ym_list(exec_ym_add($cur, -2), $cur));
        $target = exec_set($pdo, 'exec_gm_target');
        if ($pl['revenue'] <= 0) {
            $out['fin'] = ['Finanças', 'none', 'Sem faturação nos últimos 3 meses: ainda não há margem a medir.', '/admin/financeiro.php'];
        } else {
            $okGm = $pl['gm'] >= $target; $okNet = $pl['net'] > 0;
            $st = $okGm && $okNet ? 'ok' : ($okGm || $okNet ? 'warn' : 'bad');
            $out['fin'] = ['Finanças', $st, 'Últimos 3 meses: gross margin ' . $pct($pl['gm']) . ' (meta ' . $pct($target) . ') e net profit ' . ($pl['net'] >= 0 ? '+' : '−') . eur0(abs($pl['net'])) . '.',
                           '/admin/relatorios.php?tab=financeiro&p=trimestre'];
        }
        // Tesouraria: runway útil e atrasos no por receber
        $rw = fin_runway($pdo);
        $recv = fin_receivables($pdo);
        $tot = array_sum(array_column($recv, 'gross'));
        $late = array_sum(array_map(fn($r) => $r['age'] > 0 ? $r['gross'] : 0, $recv));
        $lp = $tot > 0 ? $late / $tot * 100 : 0.0;
        if ($rw['useful'] === null) {
            $out['cash'] = ['Tesouraria', 'none', 'Sem custos fixos registados: o runway conta a partir deles.', '/admin/financeiro.php?tab=fixos'];
        } else {
            $u = (float)$rw['useful'];
            $green = exec_set($pdo, 'exec_runway_green'); $red = exec_set($pdo, 'exec_runway_red');
            $lg = exec_set($pdo, 'exec_late_green'); $lr = exec_set($pdo, 'exec_late_red');
            $st = ($u <= 0 || $u < $red || $lp > $lr) ? 'bad' : (($u >= $green && $lp < $lg) ? 'ok' : 'warn');
            $out['cash'] = ['Tesouraria', $st, ($u <= 0 ? 'Dentro da reserva de emergência' : 'Runway útil de ' . fin_num($u, 1) . ' meses') . ' · ' . $pct($lp) . ' do por receber em atraso.', '/admin/financeiro.php?tab=tesouraria'];
        }
    }
    // Comercial: metas do período e cobertura da meta do mês seguinte pelo pipeline ponderado
    $gs = array_values(array_filter(mx_goals_status($pdo, $deals, $money), fn($g) => $g['scope'] !== 'user'));
    $c = ['ok' => 0, 'risk' => 0, 'behind' => 0];
    foreach ($gs as $g) $c[in_array($g['state'], ['done', 'ok'], true) ? 'ok' : ($g['state'] === 'risk' ? 'risk' : 'behind')]++;
    $snap = (string)$pdo->query("SELECT MAX(day) FROM metrics_daily WHERE scope = ''")->fetchColumn();
    $weighted = $snap !== '' ? (float)$pdo->query("SELECT value FROM metrics_daily WHERE scope = '' AND mkey = 'pipeline_ponderado' AND day = " . $pdo->quote($snap))->fetchColumn() : 0.0;
    $next = (new DateTimeImmutable(today_lisbon()))->modify('first day of next month')->format('Y-m-d');
    $st = $pdo->prepare("SELECT target FROM metric_goals WHERE scope = 'company' AND scope_id = 0 AND period = 'month' AND metric = 'valor' AND period_start <= ? ORDER BY period_start DESC LIMIT 1");
    $st->execute([$next]);
    $nextGoal = (float)($st->fetchColumn() ?: 0);
    $cover = $nextGoal > 0 ? $weighted / $nextGoal : null;
    $need = exec_set($pdo, 'exec_pipe_cover');
    if (!$gs && $cover === null) {
        $out['com'] = ['Comercial', 'none', 'Sem metas definidas: defina-as em Relatórios › Metas.', '/admin/relatorios.php?tab=metas'];
    } else {
        $st = ($c['behind'] > 0 || ($cover !== null && $cover < $need)) ? 'bad' : ($c['risk'] > 0 ? 'warn' : 'ok');
        $s = $gs ? $c['ok'] . ' de ' . count($gs) . ($c['ok'] === 1 ? ' meta em linha' : ' metas em linha') . ($c['risk'] ? ' · ' . $c['risk'] . ' em risco' : '') . ($c['behind'] ? ' · ' . $c['behind'] . ' atrás' : '') : 'Sem metas do período';
        if ($cover !== null) $s .= ' · pipeline ponderado cobre ' . str_replace('.', ',', (string)round($cover, 1)) . '× a meta do mês seguinte (mínimo ' . str_replace('.', ',', (string)$need) . '×)';
        $out['com'] = ['Comercial', $st, $s . '.', '/admin/relatorios.php?tab=metas'];
    }
    // Operação: entregas no prazo e rondas a mais (últimos 90 dias)
    $from = date('Y-m-d', strtotime(today_lisbon() . ' -90 days'));
    $ops = mx_ops($pdo, $deals, $from, date('Y-m-d', strtotime(today_lisbon() . ' +1 day')));
    $n = count($ops['delivered']);
    $rounds = $ops['rounds'];
    $over = $rounds ? array_sum(array_column($rounds, 'over')) / max(1, count(array_unique(array_column($rounds, 'deal')))) : 0.0;
    if (!$n && !$rounds) {
        $out['ops'] = ['Operação', 'none', 'Sem entregas nos últimos 90 dias.', '/admin/relatorios.php?tab=operacional'];
    } else {
        $ot = $n ? count($ops['on_time']) / $n * 100 : null;
        $okT = $ot === null || $ot >= exec_set($pdo, 'exec_ontime_green');
        $okR = $over <= exec_set($pdo, 'exec_rounds_max');
        $st = $okT && $okR ? 'ok' : ($okT || $okR ? 'warn' : 'bad');
        $out['ops'] = ['Operação', $st, ($n ? $pct($ot) . ' das ' . $n . ($n === 1 ? ' entrega' : ' entregas') . ' no prazo' : 'Sem entregas') . ' · ' . str_replace('.', ',', (string)round($over, 1)) . ' rondas a mais por negócio (últimos 90 dias).',
                       '/admin/relatorios.php?tab=operacional&p=trimestre'];
    }
    $order = ['fin', 'com', 'ops', 'cash'];
    $res = [];
    foreach ($order as $k) if (isset($out[$k])) $res[$k] = ['key' => $k, 'title' => $out[$k][0], 'state' => $out[$k][1], 'text' => $out[$k][2], 'url' => $out[$k][3]];
    return $res;
}

// ── hall of fame ────────────────────────────────────────────

/** O gross profit de cada negócio ganho: valor − custos (Financeiro, ficha, reservas) − tempo da equipa; provisório até à entrega. */
function exec_deal_gp(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $mm = mx_money($pdo, $ids);
    $imp = exec_imputed($pdo)['deals'];
    $all = metrics_deals($pdo);
    $out = [];
    foreach ($ids as $id) {
        $m = $mm[(int)$id] ?? null;
        $d = $all[(int)$id] ?? null;
        if (!$m || !$d) continue;
        $team = (float)($imp[(int)$id]['cost'] ?? 0);
        $out[(int)$id] = ['value' => $m['value'], 'gp' => round($m['gp'] - $team, 2), 'team' => $team, 'provisional' => !($d['delivered_at'] || $d['phase'] === 'done')];
    }
    return $out;
}

/** A equipa de um negócio: comercial, responsável de execução e as pessoas declaradas na Orçamentação. */
function exec_deal_people(PDO $pdo, array $d): array {
    $ids = array_filter([$d['owner'], $d['exec']]);
    foreach (array_keys(exec_team_hours($pdo)[$d['id']] ?? []) as $u) $ids[] = (int)$u;
    return array_values(array_unique(array_map('intval', $ids)));
}

/** Os negócios ganhos por gross profit (do maior), com o que o pódio mostra. $ym: só os do mês (do Kickoff). */
function exec_ranking(PDO $pdo, ?string $ym = null): array {
    $all = metrics_deals($pdo);
    $won = array_filter($all, fn($d) => $d['end'] === 'won' && ($ym === null || exec_ym((string)$d['won_at']) === $ym));
    $gp = exec_deal_gp($pdo, array_keys($won));
    $funnels = $pdo->query('SELECT id, name FROM funnels')->fetchAll(PDO::FETCH_KEY_PAIR);
    $names = $pdo->query('SELECT id, name FROM contacts')->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows = [];
    foreach ($won as $id => $d) {
        if (!isset($gp[$id]) || $gp[$id]['value'] <= 0) continue;
        $rows[] = ['id' => $id, 'code' => (string)$d['code'], 'name' => $d['name'], 'client' => $names[$d['contact']] ?? ($d['company'] ?: '—'),
                   'funnel' => $funnels[$d['fu']] ?? '—', 'ym' => exec_ym((string)$d['won_at']), 'people' => exec_deal_people($pdo, $d)] + $gp[$id];
    }
    usort($rows, fn($a, $b) => [$b['gp'], $a['id']] <=> [$a['gp'], $b['id']]);
    return $rows;
}

/**
 * O comercial do mês: a melhor conversão pipeline → kickoff, com pelo menos N negócios em pipeline nesse mês;
 * desempate pelo gross profit ganho e depois pelo tempo de primeira resposta (menor ganha).
 * [ranking (pessoa, n, k, rate, gp, resp), avg (taxa da equipa), min].
 */
function exec_sellers(PDO $pdo, string $ym): array {
    $from = $ym . '-01'; $to = date('Y-m-d', strtotime($from . ' +1 month'));
    $deals = metrics_deals($pdo);
    $by = []; $wonIds = [];
    foreach ($deals as $d) {
        if ($d['reach'] < 2 || !$d['owner']) continue;
        $inPipe = $d['at']['xray'] < $to && !($d['end'] === 'won' && $d['won_at'] < $from) && !($d['end'] === 'lost' && $d['lost_at'] < $from);
        if (!$inPipe) continue;
        $u = $d['owner'];
        $by[$u] ??= ['user' => $u, 'n' => 0, 'k' => 0, 'ids' => [], 'won' => []];
        $by[$u]['n']++; $by[$u]['ids'][] = $d['id'];
        if ($d['end'] === 'won' && mx_in($d['won_at'], $from, $to)) { $by[$u]['k']++; $by[$u]['won'][] = $d['id']; $wonIds[] = $d['id']; }
    }
    $gp = exec_deal_gp($pdo, $wonIds);
    $fr = mx_first_responses($pdo, $deals, $from, $to);
    $min = (int)exec_set($pdo, 'exec_seller_min');
    $N = 0; $K = 0;
    foreach ($by as &$r) {
        $r['rate'] = $r['n'] ? $r['k'] / $r['n'] * 100 : 0.0;
        $r['gp'] = array_sum(array_map(fn($i) => $gp[$i]['gp'] ?? 0, $r['won']));
        $hs = array_values(array_filter(array_map(fn($x) => $x['owner'] === $r['user'] ? $x['h'] : null, $fr), fn($h) => $h !== null));
        $r['resp'] = mx_median($hs);
        $r['ok'] = $r['n'] >= $min;
        $N += $r['n']; $K += $r['k'];
    }
    unset($r);
    $list = array_values($by);
    usort($list, fn($a, $b) => [$b['ok'], $b['rate'], $b['gp'], -($a['resp'] ?? 1e9)] <=> [$a['ok'], $a['rate'], $a['gp'], -($b['resp'] ?? 1e9)]);
    return ['ranking' => $list, 'avg' => $N ? $K / $N * 100 : null, 'min' => $min];
}

/** O destaque de produção do mês: a melhor % de entregas no prazo e de aprovação à 1.ª ronda (por responsável de execução). */
function exec_production(PDO $pdo, string $ym): array {
    $from = $ym . '-01'; $to = date('Y-m-d', strtotime($from . ' +1 month'));
    $deals = metrics_deals($pdo);
    $by = [];
    foreach ($deals as $d) {
        if (!$d['delivered_at'] || !mx_in($d['delivered_at'], $from, $to)) continue;
        $u = $d['exec'] ?: $d['owner'];
        if (!$u) continue;
        $by[$u] ??= ['user' => $u, 'n' => 0, 'on' => 0, 'ids' => [], 'rv' => 0, 'rv_ok' => 0];
        $by[$u]['n']++; $by[$u]['ids'][] = $d['id'];
        if ($d['delivery_date'] && substr((string)$d['delivered_at'], 0, 10) <= $d['delivery_date']) $by[$u]['on']++;
    }
    if (db_has_table($pdo, 'cc_rv_items')) {
        $st = $pdo->prepare('SELECT i.deal_id, (SELECT COUNT(*) FROM cc_rv_rounds r WHERE r.item_id = i.id AND r.submitted_at IS NOT NULL) AS rounds FROM cc_rv_items i WHERE i.approved_at >= ? AND i.approved_at < ?');
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $d = $deals[(int)$r['deal_id']] ?? null;
            $u = $d ? ($d['exec'] ?: $d['owner']) : 0;
            if (!$u) continue;
            $by[$u] ??= ['user' => $u, 'n' => 0, 'on' => 0, 'ids' => [], 'rv' => 0, 'rv_ok' => 0];
            $by[$u]['rv']++;
            if ((int)$r['rounds'] <= 1) $by[$u]['rv_ok']++;
        }
    }
    foreach ($by as &$r) { $r['ontime'] = $r['n'] ? $r['on'] / $r['n'] * 100 : null; $r['first'] = $r['rv'] ? $r['rv_ok'] / $r['rv'] * 100 : null; }
    unset($r);
    $list = array_values(array_filter($by, fn($r) => ($r['ontime'] ?? 0) > 0 || ($r['first'] ?? 0) > 0));   // só há destaque com algo a destacar
    usort($list, fn($a, $b) => [$b['ontime'] ?? -1, $b['first'] ?? -1, $b['n']] <=> [$a['ontime'] ?? -1, $a['first'] ?? -1, $a['n']]);
    return $list;
}

/** Primeiro nome de uma pessoa (para os avisos). */
function exec_person(PDO $pdo, int $uid): string {
    static $names = null;
    $names ??= $pdo->query('SELECT id, COALESCE(NULLIF(display_name, \'\'), name) FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
    return trim((string)($names[$uid] ?? '—'));
}

/** Grava uma linha do hall of fame (substitui a do mesmo mês, tipo e posição). */
function exec_hof_put(PDO $pdo, string $ym, string $kind, int $rank, ?int $deal, ?int $user, float $value, bool $prov, array $data = []): void {
    $sql = db_is_sqlite($pdo) ? 'INSERT OR REPLACE INTO exec_hof (ym, kind, rank_no, deal_id, user_id, value, provisional, data, created_at) VALUES (?,?,?,?,?,?,?,?,?)'
                              : 'REPLACE INTO exec_hof (ym, kind, rank_no, deal_id, user_id, value, provisional, data, created_at) VALUES (?,?,?,?,?,?,?,?,?)';
    $pdo->prepare($sql)->execute([$ym, $kind, $rank, $deal, $user, round($value, 2), $prov ? 1 : 0, json_encode($data, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
}

/**
 * O hall of fame no cron: quando um negócio entra no pódio ou bate o recorde, fica registado, aparece no Dashboard
 * (animação discreta) e vai um aviso ao chat da equipa. No dia 1 do mês (a partir das 9h): o pódio do mês que
 * acabou, o negócio do mês, o comercial do mês e o destaque de produção — com o aviso no chat.
 */
function exec_hof_tick(PDO $pdo, ?string $now = null): string {
    if (!exec_ready($pdo)) return '';
    $msg = [];
    $rank = exec_ranking($pdo);
    $top = array_slice($rank, 0, 3);
    $ids = array_map(fn($r) => $r['id'], $top);
    $prev = json_decode((string)setting($pdo, 'hof_top3', ''), true);
    $cur = exec_ym($now !== null ? substr($now, 0, 10) : null);
    if (!is_array($prev)) {
        metrics_setting_set($pdo, 'hof_top3', json_encode($ids));   // a primeira vez só regista (sem avisos de tudo o que já existia)
        metrics_setting_set($pdo, 'hof_record', json_encode(['id' => $top[0]['id'] ?? 0, 'gp' => $top[0]['gp'] ?? 0]));
    } elseif ($ids !== $prev) {
        $rec = json_decode((string)setting($pdo, 'hof_record', ''), true) ?: ['id' => 0, 'gp' => 0];
        $seen = $pdo->prepare("SELECT COUNT(*) FROM exec_hof WHERE deal_id = ? AND kind IN ('enter','record','podium')");
        foreach ($top as $i => $r) {
            $isRecord = $i === 0 && $r['id'] !== (int)$rec['id'] && $r['gp'] > (float)$rec['gp'];
            if (in_array($r['id'], $prev, true) && !$isRecord) continue;
            $seen->execute([$r['id']]);
            if (!$isRecord && (int)$seen->fetchColumn()) continue;   // já esteve no pódio (voltou depois de uma correção): sem aviso repetido
            exec_hof_put($pdo, $cur, $isRecord ? 'record' : 'enter', $i + 1, $r['id'], null, $r['gp'], $r['provisional'], ['code' => $r['code'], 'name' => $r['name']]);
            $pos = ['1.º', '2.º', '3.º'][$i];
            $t = $isRecord ? 'Novo recorde: ' . $r['code'] . ' · ' . $r['name'] : $r['code'] . ' · ' . $r['name'] . ' entra no pódio em ' . $pos . ' lugar';
            $who = implode(', ', array_map(fn($u) => exec_person($pdo, $u), $r['people']));
            $b = ($isRecord ? 'O maior gross profit de sempre' : 'Entra para o ' . $pos . ' lugar dos negócios de sempre') . ' · ' . $r['client'] . ' · ' . $r['funnel'] . ($who !== '' ? ' · equipa: ' . $who : '') . ($r['provisional'] ? ' (provisório até à entrega)' : '') . '. Parabéns!';
            $bf = ($isRecord ? 'O maior gross profit de sempre: ' : 'Gross profit de ') . eur0($r['gp']) . ' · ' . $r['client'] . ' · ' . $r['funnel'] . ($who !== '' ? ' · equipa: ' . $who : '') . ($r['provisional'] ? ' (provisório até à entrega)' : '') . '. Parabéns!';
            team_post_add($pdo, $isRecord ? 'record' : 'podium', $t, $b, $bf, '/admin/dashboard.php#hall');
            $msg[] = $t;
        }
        metrics_setting_set($pdo, 'hof_top3', json_encode($ids));
    }
    // o recorde a bater é sempre o 1.º lugar de agora (se um valor for corrigido para baixo, o recorde acompanha)
    if ($top && is_array($prev)) metrics_setting_set($pdo, 'hof_record', json_encode(['id' => $top[0]['id'], 'gp' => $top[0]['gp']]));
    // o fecho do mês (dia 1, a partir das 9h, uma vez)
    $t = new DateTimeImmutable($now ?? 'now', new DateTimeZone('Europe/Lisbon'));
    $last = exec_ym_add($cur, -1);
    $done = (int)$pdo->query("SELECT COUNT(*) FROM exec_hof WHERE kind = 'close' AND ym = " . $pdo->quote($last))->fetchColumn();
    if (!$done && (int)$t->format('j') >= 1 && ((int)$t->format('j') > 1 || (int)$t->format('G') >= 9)) {
        $msg[] = exec_month_close($pdo, $last, $rank);
    }
    return $msg ? 'Hall of fame: ' . implode(' · ', $msg) : '';
}

/** Fecha um mês no hall of fame: pódio de sempre nesse momento, negócio do mês, comercial do mês e destaque de produção. */
function exec_month_close(PDO $pdo, string $ym, ?array $rank = null): string {
    $rank ??= exec_ranking($pdo);
    foreach (array_slice($rank, 0, 3) as $i => $r) exec_hof_put($pdo, $ym, 'podium', $i + 1, $r['id'], null, $r['gp'], $r['provisional'], ['code' => $r['code'], 'name' => $r['name'], 'client' => $r['client']]);
    $parts = [];
    $month = array_values(array_filter($rank, fn($r) => $r['ym'] === $ym));
    if ($month) {
        $r = $month[0];
        exec_hof_put($pdo, $ym, 'deal_month', 1, $r['id'], null, $r['gp'], $r['provisional'], ['code' => $r['code'], 'name' => $r['name'], 'client' => $r['client']]);
    }
    $s = exec_sellers($pdo, $ym);
    $win = $s['ranking'][0] ?? null;
    if ($win && $win['ok']) {
        exec_hof_put($pdo, $ym, 'seller', 1, null, $win['user'], $win['gp'], false, ['n' => $win['n'], 'k' => $win['k'], 'rate' => $win['rate'], 'avg' => $s['avg'], 'resp' => $win['resp']]);
        $parts[] = 'Comercial do mês: ' . exec_person($pdo, $win['user']) . ' — ' . str_replace('.', ',', (string)round($win['rate'])) . '% de conversão pipeline → kickoff (' . $win['k'] . ' de ' . $win['n'] . ')'
                 . ($s['avg'] !== null ? ', a média da equipa foi ' . str_replace('.', ',', (string)round($s['avg'])) . '%' : '') . '.';
    }
    $p = exec_production($pdo, $ym);
    if ($p) {
        $w = $p[0];
        exec_hof_put($pdo, $ym, 'production', 1, null, $w['user'], 0, false, ['n' => $w['n'], 'ontime' => $w['ontime'], 'first' => $w['first'], 'rv' => $w['rv']]);
        $parts[] = 'Destaque de produção: ' . exec_person($pdo, $w['user']) . ' — ' . ($w['ontime'] !== null ? str_replace('.', ',', (string)round($w['ontime'])) . '% das entregas no prazo' : 'sem entregas')
                 . ($w['first'] !== null ? ', ' . str_replace('.', ',', (string)round($w['first'])) . '% aprovadas à 1.ª ronda' : '') . '.';
    }
    exec_hof_put($pdo, $ym, 'close', 1, null, null, 0, false, []);
    if ($parts) {
        $title = 'Hall of fame de ' . exec_month_label($ym) . ' de ' . substr($ym, 0, 4);
        $dm = $month[0] ?? null;
        $b = implode("\n", $parts) . ($dm ? "\nNegócio do mês: " . $dm['code'] . ' · ' . $dm['name'] . '.' : '');
        $bf = implode("\n", $parts) . ($dm ? "\nNegócio do mês: " . $dm['code'] . ' · ' . $dm['name'] . ' (gross profit ' . eur0($dm['gp']) . ($dm['provisional'] ? ', provisório' : '') . ').' : '');
        team_post_add($pdo, 'month', $title, $b, $bf, '/admin/dashboard.php#hall');
    }
    return 'mês ' . $ym . ' fechado' . ($parts ? ' (com aviso no chat)' : '');
}

/** O histórico do hall of fame: mês → [podium, seller, production, deal_month, record, enter]. */
function exec_hof_history(PDO $pdo, int $months = 12): array {
    if (!db_has_table($pdo, 'exec_hof')) return [];
    $st = $pdo->prepare("SELECT * FROM exec_hof WHERE ym >= ? AND kind <> 'close' ORDER BY ym DESC, kind, rank_no");
    $st->execute([exec_ym_add(exec_ym(), -$months)]);
    $out = [];
    foreach ($st->fetchAll() as $r) { $r['data'] = json_decode((string)$r['data'], true) ?: []; $out[$r['ym']][$r['kind']][] = $r; }
    return $out;
}

// ── leitura do mês (IA, de manhã) ───────────────────────────

/**
 * Os números da leitura do mês, já escritos como vão aparecer (a IA só pode usar estes). Com $money, os euros e as
 * margens; sem, só contagens e percentagens que não são financeiras.
 */
function exec_reading_facts(PDO $pdo, bool $money): array {
    $cur = exec_ym();
    $prev = exec_ym_add($cur, -1);
    $day = (int)substr(today_lisbon(), 8, 2);
    $deals = metrics_deals($pdo);
    $from = $cur . '-01'; $to = date('Y-m-d', strtotime($from . ' +1 month'));
    $pfrom = $prev . '-01';
    $f = ['mes' => exec_month_label($cur) . ' (até dia ' . $day . ')', 'mes_anterior' => exec_month_label($prev)];
    $cnt = fn(string $a, string $b, callable $w) => count(array_filter($deals, fn($d) => $w($d, $a, $b)));
    $won = fn($d, $a, $b) => $d['end'] === 'won' && mx_in($d['won_at'], $a, $b);
    $f['kickoffs'] = (string)$cnt($from, $to, $won);
    $f['kickoffs_mes_anterior'] = (string)$cnt($pfrom, $from, $won);
    $f['leads'] = (string)$cnt($from, $to, fn($d, $a, $b) => mx_in($d['created'], $a, $b));
    $fb = array_filter($deals, fn($d) => $d['end'] === 'open' && $d['phase'] === 'pipeline' && $d['reach'] >= 4);
    $f['propostas_a_aguardar_feedback'] = (string)count($fb);
    $ops = mx_ops($pdo, $deals, date('Y-m-d', strtotime(today_lisbon() . ' -90 days')), $to);
    $extra = array_values(array_unique(array_map(fn($r) => $r['deal'], array_filter($ops['rounds'], fn($r) => $r['over'] > 0))));
    $f['negocios_com_rondas_a_mais'] = (string)count($extra);
    if ($ops['delivered']) $f['entregas_no_prazo_90_dias'] = (int)round(count($ops['on_time']) / count($ops['delivered']) * 100) . '%';
    $gs = array_values(array_filter(mx_goals_status($pdo, $deals, $money), fn($g) => $g['scope'] !== 'user'));
    if ($gs) {
        $f['metas_em_linha'] = count(array_filter($gs, fn($g) => in_array($g['state'], ['done', 'ok'], true))) . ' de ' . count($gs);
        $f['metas_atras'] = (string)count(array_filter($gs, fn($g) => $g['state'] === 'behind'));
    }
    if ($money) {
        $a = exec_pl($pdo, [$cur]); $b = exec_pl($pdo, [$prev]);
        $f['revenue'] = eur0($a['revenue']); $f['revenue_mes_anterior'] = eur0($b['revenue']);
        $f['gross_profit'] = eur0($a['gp']); $f['gross_profit_mes_anterior'] = eur0($b['gp']);
        if ($b['gp'] > 0) $f['gross_profit_variacao'] = ($a['gp'] >= $b['gp'] ? '+' : '−') . (int)round(abs($a['gp'] / $b['gp'] - 1) * 100) . '%';
        if ($a['gm'] !== null) $f['gross_margin'] = (int)round($a['gm']) . '%';
        if ($b['gm'] !== null) $f['gross_margin_mes_anterior'] = (int)round($b['gm']) . '%';
        if ($a['gm'] !== null && $b['gm'] !== null) $f['gross_margin_variacao'] = ($a['gm'] >= $b['gm'] ? '+' : '−') . (int)round(abs($a['gm'] - $b['gm'])) . ' p.p.';
        $f['net_profit'] = ($a['net'] < 0 ? '−' : '') . eur0(abs($a['net']));
        $rw = fin_runway($pdo);
        if ($rw['useful'] !== null) $f['runway_util'] = fin_num(max(0, (float)$rw['useful']), 0) . ' meses';
        $f['propostas_a_aguardar_feedback_valor'] = eur0(array_sum(array_column($fb, 'value')));
        $recv = array_filter(fin_receivables($pdo), fn($r) => $r['age'] > 0);
        $f['faturas_em_atraso'] = (string)count($recv);
        if ($recv) $f['faturas_em_atraso_valor'] = eur0(array_sum(array_column($recv, 'gross')));
        // de onde veio o gross profit do mês (o funil com mais)
        $fin = mx_fin($pdo, $deals, $from, $to, $pfrom, $from);
        if ($fin['funnel']) { $k = array_key_first($fin['funnel']); $f['funil_com_mais_gross_profit'] = $k . ' (' . eur0($fin['funnel'][$k]['gp']) . ')'; }
    }
    return $f;
}

/** O texto de reserva (sem IA): as mesmas frases, sempre com os números da fotografia. */
function exec_reading_template(array $f, bool $money): string {
    $s = [];
    $mes = ucfirst($f['mes']);
    if ($money) {
        $s[] = $mes . ': revenue de ' . $f['revenue'] . ' e gross profit de ' . $f['gross_profit'] . (isset($f['gross_profit_variacao']) ? ' (' . $f['gross_profit_variacao'] . ' face a ' . $f['mes_anterior'] . ')' : '')
             . (isset($f['gross_margin']) ? ', com gross margin de ' . $f['gross_margin'] . (isset($f['gross_margin_variacao']) ? ' (' . $f['gross_margin_variacao'] . ')' : '') : '') . '.';
        if (isset($f['runway_util'])) $s[] = 'Runway útil de ' . $f['runway_util'] . '.';
    } else {
        $s[] = $mes . ': ' . $f['kickoffs'] . ' kickoffs (' . $f['kickoffs_mes_anterior'] . ' em ' . $f['mes_anterior'] . ') e ' . $f['leads'] . ' leads novas.';
    }
    if ((int)$f['negocios_com_rondas_a_mais'] > 0) $s[] = $f['negocios_com_rondas_a_mais'] . ((int)$f['negocios_com_rondas_a_mais'] === 1 ? ' negócio com rondas a mais' : ' negócios com rondas a mais') . ' nos últimos 90 dias.';
    if ((int)$f['propostas_a_aguardar_feedback'] > 0) {
        $s[] = 'Prioridade: ' . $f['propostas_a_aguardar_feedback'] . ((int)$f['propostas_a_aguardar_feedback'] === 1 ? ' proposta sem feedback' : ' propostas sem feedback') . ($money ? ' (' . $f['propostas_a_aguardar_feedback_valor'] . ')' : '') . '.';
    } elseif ($money && (int)($f['faturas_em_atraso'] ?? 0) > 0) {
        $s[] = 'Prioridade: cobrar ' . $f['faturas_em_atraso'] . ((int)$f['faturas_em_atraso'] === 1 ? ' fatura em atraso' : ' faturas em atraso') . ' (' . $f['faturas_em_atraso_valor'] . ').';
    } elseif ((int)($f['metas_atras'] ?? 0) > 0) {
        $s[] = 'Prioridade: recuperar ' . $f['metas_atras'] . ((int)$f['metas_atras'] === 1 ? ' meta atrás' : ' metas atrás') . ' do ritmo.';
    } else {
        $s[] = 'Prioridade: manter o ritmo de propostas.';
    }
    return implode(' ', $s);
}

/** Os números de um texto (para confirmar que a IA não inventou nenhum): «42 300», «18», «3,5». */
function exec_numbers(string $t): array {
    preg_match_all('/\d+(?:[ .\x{00A0}\x{202F}]\d{3})*(?:,\d+)?/u', $t, $m);
    return array_values(array_unique(array_map(fn($n) => preg_replace('/[ .\x{00A0}\x{202F}]/u', '', $n), $m[0])));
}

/** A leitura pela IA, só com os números dados; null se não houver IA ou se aparecer um número que não está nos dados. */
function exec_reading_ai(PDO $pdo, array $f, bool $money): ?string {
    try {
        require_once __DIR__ . '/ai.php';
        if (!ai_config()) return null;
        $prompt = "És o analista da topper. (estúdio criativo em Portugal). Com estes números do Dashboard (fotografia de hoje):\n"
            . json_encode($f, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\nEscreve a «leitura do mês»: 3 a 4 frases em português de Portugal, num só parágrafo: o que mudou face ao mês anterior e porquê, e no fim a ação mais importante, a começar por «Prioridade:». "
            . "Usa só números que estejam nos dados, escritos exatamente como lá estão; não faças contas nem arredondes. "
            . ($money ? '' : 'Não fales de dinheiro, euros, margens nem lucro. ')
            . 'Sem títulos, sem listas, sem introdução.';
        $r = ai_call($pdo, ['max_tokens' => 400, 'messages' => [['role' => 'user', 'content' => $prompt]]], 'report');
        $t = trim(preg_replace('/\s+/u', ' ', implode(' ', ai_texts($r['_content'] ?? $r['content'] ?? []))));
        if ($t === '' || mb_strlen($t) > 900) return null;
        $allowed = exec_numbers(implode(' ', array_values($f)) . ' ' . date('Y'));
        foreach (exec_numbers($t) as $n) if (!in_array($n, $allowed, true)) return null;
        if (!$money && preg_match('/€|euro/iu', $t)) return null;
        return $t;
    } catch (Throwable $e) {
        error_log('exec_reading_ai: ' . $e->getMessage());
        return null;
    }
}

/** Escreve a leitura de hoje (com e sem euros). $force: mesmo que já exista. */
function exec_reading_make(PDO $pdo, bool $force = false): string {
    if (!exec_ready($pdo)) return '';
    $day = today_lisbon();
    if (!$force && (int)$pdo->query('SELECT COUNT(*) FROM exec_reading WHERE day = ' . $pdo->quote($day))->fetchColumn()) return '';
    $ff = exec_reading_facts($pdo, true);
    $fp = exec_reading_facts($pdo, false);
    $a = exec_reading_ai($pdo, $ff, true);
    $b = exec_reading_ai($pdo, $fp, false);
    $sql = db_is_sqlite($pdo) ? 'INSERT OR REPLACE INTO exec_reading (day, body_fin, body, facts, by_ai, created_at) VALUES (?,?,?,?,?,?)'
                              : 'REPLACE INTO exec_reading (day, body_fin, body, facts, by_ai, created_at) VALUES (?,?,?,?,?,?)';
    $pdo->prepare($sql)->execute([$day, $a ?? exec_reading_template($ff, true), $b ?? exec_reading_template($fp, false),
                                  json_encode(['fin' => $ff, 'plain' => $fp], JSON_UNESCAPED_UNICODE), $a !== null && $b !== null ? 1 : 0, date('Y-m-d H:i:s')]);
    return 'Leitura do mês escrita' . ($a !== null ? ' pela IA' : ' (sem IA: texto com os números da fotografia)');
}

/** A leitura mais recente. */
function exec_reading(PDO $pdo): ?array {
    if (!db_has_table($pdo, 'exec_reading')) return null;
    return $pdo->query('SELECT * FROM exec_reading ORDER BY day DESC LIMIT 1')->fetch() ?: null;
}

// ── cron ────────────────────────────────────────────────────

/**
 * O que corre a cada 5 minutos (leve): as prestações dos empréstimos, a fotografia do resultado (de hora a hora), o
 * hall of fame e, de manhã (a partir da hora das Definições), a leitura do mês.
 */
function exec_tick(PDO $pdo, ?string $now = null): string {
    if (!exec_ready($pdo)) return '';
    $out = [];
    $n = exec_loan_tick($pdo);
    if ($n) $out[] = $n . ' prestações de empréstimos';
    $at = exec_snap_at($pdo);
    if ($at === '' || strtotime($at) < time() - 3600) $out[] = 'fotografia do resultado: ' . exec_snapshot_refresh($pdo) . ' meses';
    $h = exec_hof_tick($pdo, $now);
    if ($h !== '') $out[] = $h;
    $t = new DateTimeImmutable($now ?? 'now', new DateTimeZone('Europe/Lisbon'));
    if ((int)$t->format('G') >= (int)exec_set($pdo, 'exec_reading_hour')) {
        $r = exec_reading_make($pdo);
        if ($r !== '') $out[] = $r;
    }
    return $out ? 'Dashboard: ' . implode(' · ', $out) : '';
}
