<?php
/**
 * topper. admin — aba Orçamentação (docs/ESPECIFICACAO-PLATAFORMA.md §14)
 *
 * Uma folha de orçamento por negócio (o mesmo ID), criada na conversão da
 * lead, com versões (v1, v2…; a aceite é a que conta), linhas de custo
 * (fornecedores ativos com o preço copiado no momento, equipa interna ao
 * custo/hora da aba Equipa, outros custos manuais) e o cálculo da §14.3:
 *
 *   custos diretos = Σ linhas
 *   MSO            = custos diretos × MSO%                    (Definições, 5%)
 *   meses          = dias úteis (seg–sex) ÷ 21
 *   ocupação       = horas internas ÷ (horas da equipa por mês × meses)   (sugerida; editável)
 *   estrutura      = custos fixos mensais × meses × ocupação
 *   base           = custos diretos + MSO + estrutura
 *   preço s/ IVA   = base ÷ (1 − margem)                       (Definições, 65%)
 *   gross profit   = preço − base · gross margin = gross profit ÷ preço
 *   lucro por mês  = gross profit ÷ meses, contra a meta mensal (Definições, 8 000 €)
 *
 * Quem não vê o Financeiro preenche linhas mas não recebe preços de
 * fornecedores, custos internos da equipa, margens nem lucro: a filtragem
 * faz-se aqui (budget_public, budget_line_public) antes de qualquer
 * resposta — página, API ou PDF. As páginas e o ui_api.php confirmam a
 * permissão da aba Orçamentação em cada pedido.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/contacts.php';   // serviços, preços, unidades, fornecedores ativos
require_once __DIR__ . '/finance.php';    // IVA, valor limpo dos custos

const BUDGET_MONTH_DAYS = 21;   // 1 mês = 21 dias úteis (§14.3)
const BUDGET_SETTINGS = ['budget_mso_pct' => 5, 'budget_margin_pct' => 65, 'budget_day_hours' => 8, 'budget_month_target' => 8000,
                         'budget_occupancy_default' => 0, 'budget_fixed_override' => 0];
const BUDGET_LINE_KINDS = ['supplier' => 'Fornecedor / freelancer', 'team' => 'Equipa interna', 'other' => 'Outro custo interno'];
const BUDGET_QTY_UNITS = ['hour' => 'horas', 'day' => 'dias'];
const BUDGET_OTHER_UNITS = ['fixed' => 'valor fixo', 'unit' => 'unidade', 'hour' => 'hora', 'day' => 'dia'];
const BUDGET_PHASES = ['Pré-produção', 'Produção', 'Pós-produção', 'Entregas'];
const BUDGET_DUR_MODES = ['dates' => 'Datas', 'weeks' => 'Semanas', 'days' => 'Dias úteis', 'phases' => 'Fases'];
const BUDGET_TABS = ['orcamentacao' => 'Em orçamentação', 'producao' => 'Em produção', 'ganhos' => 'Ganhos', 'arquivo' => 'Arquivo'];
const BUDGET_TAB_PHASE = ['orcamentacao' => 'pipeline', 'producao' => 'production', 'ganhos' => 'done', 'arquivo' => 'lost'];
const BUDGET_STATUS = ['ok' => 'Acima da meta', 'below' => 'Abaixo da meta', 'far' => 'Muito abaixo da meta'];

/** Os textos dos ⓘ da calculadora (§14.4). */
const BUDGET_INFO = [
    'direct'       => 'O que pagamos a fornecedores, freelancers e custos internos deste projeto.',
    'mso'          => 'Margem de segurança operacional: almofada para imprevistos, em % dos custos diretos.',
    'duration'     => 'A duração diz durante quanto tempo o projeto usa a empresa.',
    'occupancy'    => 'A % de ocupação diz quanto da capacidade da empresa é usada nesse tempo.',
    'fixed'        => 'Renda, software, seguros e outros custos que a empresa paga todos os meses.',
    'structure'    => 'A parte dos custos fixos que este projeto deve pagar: custos fixos × meses × ocupação.',
    'base'         => 'Tudo o que o projeto custa à empresa antes do lucro.',
    'margin'       => 'Percentagem do preço final que é lucro.',
    'price'        => 'Valor a propor ao cliente: base ÷ (1 − margem).',
    'gross'        => 'Lucro esperado em € e em % do preço.',
    'profit_month' => 'Lucro dividido pelos meses de duração; compara projetos de durações diferentes.',
    'target'       => 'Lucro por mês mínimo que a empresa quer em cada projeto.',
];

/** Uma definição da Orçamentação (globais, só admins as mudam em Definições). */
function budget_setting(PDO $pdo, string $key): float {
    return (float)lead_setting($pdo, $key, BUDGET_SETTINGS[$key] ?? 0);
}

/** Quem vê o Financeiro vê também preços de fornecedores, margens e lucro (§14.6). */
function budget_money(): bool {
    return can('financeiro', PERM_VIEW);
}

// ── tempo: dias úteis de segunda a sexta ───────────────────

function budget_workdays(string $from, string $to): int {
    try { $a = new DateTimeImmutable($from); $b = new DateTimeImmutable($to); } catch (Throwable $e) { return 0; }
    if ($b < $a) return 0;
    $n = 0;
    for ($d = $a; $d <= $b; $d = $d->modify('+1 day')) if ((int)$d->format('N') <= 5) $n++;
    return $n;
}

/** O dia útil em que acaba um período de $n dias úteis que começa em $from (inclusive). */
function budget_add_workdays(string $from, float $n): string {
    $d = new DateTimeImmutable($from);
    while ((int)$d->format('N') > 5) $d = $d->modify('+1 day');
    $left = max(1, (int)ceil($n)) - 1;
    while ($left > 0) {
        $d = $d->modify('+1 day');
        if ((int)$d->format('N') <= 5) $left--;
    }
    return $d->format('Y-m-d');
}

function budget_phases(array $v): array {
    $p = json_decode((string)($v['phases'] ?? ''), true);
    if (!is_array($p)) return [];
    return array_values(array_filter(array_map(fn($x) => ['name' => mb_substr(trim((string)($x['name'] ?? '')), 0, 60), 'days' => max(0, round((float)($x['days'] ?? 0), 1))],
                                               $p), fn($x) => $x['name'] !== '' || $x['days'] > 0));
}

/** A duração em dias úteis, conforme o modo escolhido (datas, semanas, dias ou fases). */
function budget_duration_days(array $v): float {
    return match ($v['dur_mode'] ?? 'days') {
        'dates'  => $v['start_date'] && $v['end_date'] ? (float)budget_workdays($v['start_date'], $v['end_date']) : (float)($v['days'] ?? 0),
        'weeks'  => round((float)($v['weeks'] ?? 0) * 5, 1),
        'phases' => array_sum(array_column(budget_phases($v), 'days')),
        default  => (float)($v['days'] ?? 0),
    };
}

/** As fases com as datas (quando há data de início): para a folha e para a proposta. */
function budget_phase_dates(array $v): array {
    $out = [];
    $start = $v['start_date'] ?: null;
    foreach (budget_phases($v) as $p) {
        $row = $p + ['from' => null, 'to' => null];
        if ($start && $p['days'] > 0) {
            $row['from'] = budget_add_workdays($start, 1);
            $row['to'] = budget_add_workdays($start, $p['days']);
            $start = budget_add_workdays($row['to'], 2);   // o dia útil a seguir
        }
        $out[] = $row;
    }
    return $out;
}

// ── custos fixos mensais (§14.3.4) ─────────────────────────

/**
 * A média dos custos da empresa (Financeiro, sem negócio, valor limpo) nos 3 meses
 * completos antes de $ref. Um custo sem mês conta 1/12 em cada mês do seu ano.
 */
function budget_fixed_average(PDO $pdo, ?string $ref = null): array {
    $rate = vat_rate($pdo);
    $m = (new DateTimeImmutable($ref ?? today_lisbon()))->modify('first day of this month');
    $months = [];
    for ($i = 1; $i <= 3; $i++) $months[] = $m->modify("-$i month");
    $total = 0.0; $detail = [];
    foreach ($months as $d) {
        $y = (int)$d->format('Y'); $mo = (int)$d->format('n');
        $st = $pdo->prepare('SELECT * FROM costs WHERE deal_id IS NULL AND year = ? AND (month = ? OR month IS NULL)');
        $st->execute([$y, $mo]);
        $sum = 0.0;
        foreach ($st->fetchAll() as $c) $sum += $c['month'] === null ? cost_net($c, $rate) / 12 : cost_net($c, $rate);
        $detail[] = ['y' => $y, 'm' => $mo, 'label' => sprintf('%02d/%d', $mo, $y), 'total' => round($sum, 2)];
        $total += $sum;
    }
    return ['avg' => round($total / 3, 2), 'months' => array_reverse($detail)];
}

// ── folha, versões, linhas ──────────────────────────────────

function budget_sheet_get(PDO $pdo, int $dealId): ?array {
    $st = $pdo->prepare('SELECT * FROM budget_sheets WHERE deal_id = ?');
    $st->execute([$dealId]);
    return $st->fetch() ?: null;
}

function budget_sheet_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM budget_sheets WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** A folha do negócio; criada com a v1 (definições atuais) se ainda não existir. */
function budget_sheet_ensure(PDO $pdo, array $deal, ?int $by = null): array {
    $s = budget_sheet_get($pdo, (int)$deal['id']);
    if ($s && $pdo->query('SELECT COUNT(*) FROM budget_versions WHERE sheet_id = ' . (int)$s['id'])->fetchColumn()) return $s;
    $fixedOverride = budget_setting($pdo, 'budget_fixed_override');
    $v1 = [BUDGET_MONTH_DAYS, $fixedOverride > 0 ? $fixedOverride : budget_fixed_average($pdo)['avg'], budget_setting($pdo, 'budget_margin_pct'), budget_setting($pdo, 'budget_mso_pct'),
           budget_setting($pdo, 'budget_day_hours'), (string)lead_setting($pdo, 'budget_conditions', ''), $by];
    if (!$s) {
        $pdo->prepare('INSERT INTO budget_sheets (deal_id, created_by) VALUES (?, ?)')->execute([$deal['id'], $by]);
        $s = budget_sheet_by_id($pdo, (int)$pdo->lastInsertId());
    }
    $pdo->prepare('INSERT INTO budget_versions (sheet_id, num, dur_mode, days, fixed_monthly, margin, mso_pct, day_hours, conditions, created_by)
                   VALUES (?, 1, \'days\', ?, ?, ?, ?, ?, ?, ?)')->execute([$s['id'], ...$v1]);
    budget_default_validity($pdo, (int)$pdo->lastInsertId());
    budget_log($pdo, (int)$s['id'], 'b_create', 'Folha criada' . ($deal['code'] ? ' · ' . $deal['code'] : ''), $by);
    return $s;
}

function budget_versions(PDO $pdo, int $sheetId): array {
    $st = $pdo->prepare('SELECT v.*, u.name AS author FROM budget_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.sheet_id = ? ORDER BY v.num');
    $st->execute([$sheetId]);
    return $st->fetchAll();
}

function budget_version_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT v.*, u.name AS author FROM budget_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** A versão que conta: a aceite, ou a última. */
function budget_current_version(PDO $pdo, array $sheet): ?array {
    if ($sheet['accepted_version_id'] && ($v = budget_version_get($pdo, (int)$sheet['accepted_version_id']))) return $v;
    $st = $pdo->prepare('SELECT id FROM budget_versions WHERE sheet_id = ? ORDER BY num DESC LIMIT 1');
    $st->execute([$sheet['id']]);
    $id = $st->fetchColumn();
    return $id ? budget_version_get($pdo, (int)$id) : null;
}

/**
 * As linhas de uma versão. Cada versão tem Opção A (Pedido) e Opção B (Proposta de valor, §19.3):
 * $opt 'A' ou 'B' escolhe; null = a que conta (a aceite pelo cliente, senão a A); '*' = todas.
 */
function budget_lines(PDO $pdo, int $versionId, ?string $opt = null): array {
    $hasOpt = budget_has_options($pdo);
    if ($hasOpt && $opt === null) {
        $opt = (string)$pdo->query('SELECT accepted_opt FROM budget_versions WHERE id = ' . $versionId)->fetchColumn() ?: 'A';
    }
    $byOpt = $hasOpt && in_array($opt, ['A', 'B'], true);
    $st = $pdo->prepare('SELECT l.*, s.name AS service_name, c.name AS contact_name, c.is_active AS contact_active, u.name AS user_name
                           FROM budget_lines l LEFT JOIN services s ON s.id = l.service_id LEFT JOIN contacts c ON c.id = l.contact_id
                           LEFT JOIN users u ON u.id = l.user_id
                          WHERE l.version_id = ?' . ($byOpt ? ' AND l.opt = ?' : '') . ' ORDER BY l.position, l.id');
    $st->execute($byOpt ? [$versionId, $opt] : [$versionId]);
    return $st->fetchAll();
}

/** A base já tem a Opção A/B (passo 21)? */
function budget_has_options(PDO $pdo): bool {
    static $has = null;
    return $has ??= db_has_column($pdo, 'budget_lines', 'opt');
}

/** A opção que conta numa versão: a aceite pelo cliente, senão a A. */
function budget_counting_opt(array $v): string {
    return ($v['accepted_opt'] ?? '') === 'B' ? 'B' : 'A';
}

/** O preço da versão na opção que conta. */
function budget_version_price(array $v): float {
    return budget_counting_opt($v) === 'B' ? (float)($v['price_b'] ?? 0) : (float)$v['price'];
}

function budget_line_key(): string {
    return bin2hex(random_bytes(6));
}

/** Só a última versão se edita, e só enquanto não for a aceite e a folha não estiver fechada. */
function budget_editable(array $sheet, array $version, array $versions): bool {
    $last = end($versions);
    return can('orcamentacao', PERM_EDIT) && $last && (int)$last['id'] === (int)$version['id']
        && (int)$sheet['accepted_version_id'] !== (int)$version['id'] && !$sheet['closed_at'] && !budget_version_sent($version);
}

/** A versão já foi enviada ao cliente (§19.4)? Fica como foi enviada: mudar = nova versão. */
function budget_version_sent(array $version): bool {
    global $pdo;
    if (!$pdo instanceof PDO || !db_has_table($pdo, 'proposals')) return false;
    $st = $pdo->prepare("SELECT COUNT(*) FROM proposals WHERE version_id = ? AND status <> 'void'");
    $st->execute([(int)$version['id']]);
    return (int)$st->fetchColumn() > 0;
}

// ── custo de cada linha ─────────────────────────────────────

/** Horas de uma unidade de preço (hora, meio dia, dia); null nas que não são de tempo. */
function budget_unit_hours(string $unit, float $dayHours): ?float {
    return match ($unit) { 'hour' => 1.0, 'half_day' => $dayHours / 2, 'day' => $dayHours, default => null };
}

/** As horas de uma quantidade em horas ou dias (1 dia = horas do dia da versão). */
function budget_qty_hours(float $qty, string $qtyUnit, float $dayHours): float {
    return $qtyUnit === 'day' ? $qty * $dayHours : $qty;
}

/**
 * O custo de uma linha. Fornecedor e equipa: com preço à hora / meio dia / dia, a
 * quantidade (horas ou dias) converte-se para a unidade do preço; projeto, peça e
 * segundo de animação são unidades fixas. Outro custo: quantidade × custo unitário.
 */
function budget_line_cost(array $l, float $dayHours): float {
    $price = (float)$l['unit_price'];
    $qty = (float)$l['qty'];
    if ($l['kind'] === 'other') return round($l['price_unit'] === 'fixed' ? $price : $qty * $price, 2);
    $uh = budget_unit_hours((string)$l['price_unit'], $dayHours);
    if ($uh === null) return round($qty * $price, 2);
    return round(budget_qty_hours($qty, (string)$l['qty_unit'], $dayHours) * $price / $uh, 2);
}

/** Horas internas da folha: as linhas da equipa. */
function budget_internal_hours(array $lines, float $dayHours): float {
    $h = 0.0;
    foreach ($lines as $l) if ($l['kind'] === 'team') $h += budget_qty_hours((float)$l['qty'], (string)$l['qty_unit'], $dayHours);
    return round($h, 1);
}

/** As horas disponíveis da equipa por mês (Equipa: soma das pessoas ativas). */
function budget_capacity(PDO $pdo): array {
    $rows = $pdo->query('SELECT COUNT(*), COALESCE(SUM(monthly_hours), 0) FROM users WHERE is_active = 1 AND monthly_hours > 0')->fetch(PDO::FETCH_NUM);
    return ['people' => (int)$rows[0], 'hours' => (float)$rows[1]];
}

// ── o cálculo (§14.3) ───────────────────────────────────────

function budget_calc(PDO $pdo, array $v, array $lines, ?array $capacity = null): array {
    $dh = (float)$v['day_hours'] ?: budget_setting($pdo, 'budget_day_hours');
    $direct = 0.0;
    foreach ($lines as $l) $direct += (float)$l['cost'];
    $direct = round($direct, 2);
    $mso = round($direct * (float)$v['mso_pct'] / 100, 2);
    $days = budget_duration_days($v);
    $months = max($days, 1) / BUDGET_MONTH_DAYS;
    $capacity ??= budget_capacity($pdo);
    $hours = budget_internal_hours($lines, $dh);
    $suggest = $capacity['hours'] > 0 ? min(100, round($hours / ($capacity['hours'] * $months) * 100, 1)) : null;
    $occ = $v['occupancy'] !== null ? (float)$v['occupancy'] : ($suggest ?? budget_setting($pdo, 'budget_occupancy_default'));
    $structure = round((float)$v['fixed_monthly'] * $months * $occ / 100, 2);
    $base = round($direct + $mso + $structure, 2);
    // Opção B (§19.3): a sua margem, se tiver uma própria.
    $opt = $v['calc_opt'] ?? budget_counting_opt($v);
    $margin = min(max((float)($opt === 'B' && isset($v['margin_b']) && $v['margin_b'] !== null ? $v['margin_b'] : $v['margin']), 0), 95);
    $price = round($base / (1 - $margin / 100), 2);
    $vat = vat_rate($pdo);
    $gp = round($price - $base, 2);
    $pm = round($gp / $months, 2);
    $target = budget_setting($pdo, 'budget_month_target');
    $status = $pm >= $target ? 'ok' : ($pm >= $target * 0.75 ? 'below' : 'far');
    return [
        'direct' => $direct, 'mso' => $mso, 'mso_pct' => (float)$v['mso_pct'],
        'days' => $days, 'months' => round($months, 4), 'internal_hours' => $hours,
        'capacity' => $capacity['hours'], 'capacity_people' => $capacity['people'],
        'occ_suggest' => $suggest, 'occupancy' => $occ, 'occ_manual' => $v['occupancy'] !== null,
        'fixed' => (float)$v['fixed_monthly'], 'fixed_manual' => (int)$v['fixed_manual'] === 1, 'structure' => $structure,
        'base' => $base, 'margin' => $margin, 'price' => $price, 'vat_rate' => $vat, 'price_vat' => round($price * (1 + $vat / 100), 2),
        'markup' => $base > 0 ? round(($price / $base - 1) * 100, 1) : null,
        'gp' => $gp, 'gm' => $price > 0 ? round($gp / $price * 100, 1) : null,
        'profit_month' => $pm, 'target' => $target, 'status' => $status,
        'min_price' => round($base + $target * $months, 2), 'max_months' => $target > 0 ? round($gp / $target, 1) : null,
        'day_hours' => $dh,
    ];
}

/** Recalcula as linhas e a versão e guarda o resumo (preço, base, lucro por mês) para as listas. */
function budget_recalc(PDO $pdo, int $versionId, string $opt = 'A'): array {
    $v = budget_version_get($pdo, $versionId);
    $dh = (float)$v['day_hours'];
    $all = budget_lines($pdo, $versionId, '*');
    $upd = $pdo->prepare('UPDATE budget_lines SET cost = ? WHERE id = ?');
    foreach ($all as &$l) {
        $c = budget_line_cost($l, $dh);
        if ((float)$l['cost'] !== $c) { $upd->execute([$c, $l['id']]); $l['cost'] = $c; }
    }
    unset($l);
    $has = budget_has_options($pdo);
    $linesA = $has ? array_values(array_filter($all, fn($l) => ($l['opt'] ?? 'A') !== 'B')) : $all;
    $linesB = $has ? array_values(array_filter($all, fn($l) => ($l['opt'] ?? 'A') === 'B')) : [];
    $calc = budget_calc($pdo, $v + ['calc_opt' => 'A'], $linesA);
    $pdo->prepare('UPDATE budget_versions SET price = ?, base = ?, profit_month = ?, work_days = ? WHERE id = ?')
        ->execute([$calc['price'], $calc['base'], $calc['profit_month'], $calc['days'], $versionId]);
    $calcB = null;
    if ($has) {
        $calcB = budget_calc($pdo, $v + ['calc_opt' => 'B'], $linesB);
        $pdo->prepare('UPDATE budget_versions SET price_b = ?, base_b = ?, profit_month_b = ? WHERE id = ?')
            ->execute($linesB ? [$calcB['price'], $calcB['base'], $calcB['profit_month'], $versionId] : [0, 0, 0, $versionId]);
    }
    $useB = $opt === 'B' && $has;
    return ['version' => budget_version_get($pdo, $versionId), 'lines' => $useB ? $linesB : $linesA, 'calc' => $useB ? $calcB : $calc, 'opt' => $useB ? 'B' : 'A'];
}

/** O que quem não vê o Financeiro pode receber do cálculo (§14.6). */
function budget_public(array $calc, bool $money): array {
    if ($money) return $calc + ['money' => true];
    return ['money' => false, 'days' => $calc['days'], 'months' => $calc['months'], 'internal_hours' => $calc['internal_hours'],
            'capacity' => $calc['capacity'], 'capacity_people' => $calc['capacity_people'],
            'occ_suggest' => $calc['occ_suggest'], 'occupancy' => $calc['occupancy'], 'occ_manual' => $calc['occ_manual'],
            'price' => $calc['price'], 'vat_rate' => $calc['vat_rate'], 'price_vat' => $calc['price_vat'], 'day_hours' => $calc['day_hours']];
}

/** Uma linha para quem não vê o Financeiro: sem preço de fornecedor nem custo da equipa. */
function budget_line_public(array $l, bool $money): array {
    if ($money || $l['kind'] === 'other') return $l;
    $l['unit_price'] = null;
    $l['cost'] = null;
    return $l;
}

// ── histórico ───────────────────────────────────────────────

function budget_log(PDO $pdo, int $sheetId, string $event, string $note = '', ?int $by = null): void {
    $u = current_user();
    $pdo->prepare('INSERT INTO budget_history (sheet_id, event, note, user_id) VALUES (?,?,?,?)')
        ->execute([$sheetId, $event, mb_substr($note, 0, 255), $by ?? ($u['id'] ?? null)]);
}

/** A margem mudou: junta as mudanças seguidas da mesma pessoa (2 min) numa só entrada «65% → 60%». */
function budget_log_margin(PDO $pdo, int $sheetId, int $num, float $from, float $to): void {
    $uid = (int)(current_user()['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM budget_history WHERE sheet_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$sheetId]);
    $last = $st->fetch();
    $fmt = fn(float $x) => rtrim(rtrim(number_format($x, 2, ',', ''), '0'), ',') . '%';
    if ($last && $last['event'] === 'b_margin' && (int)$last['user_id'] === $uid && strtotime((string)$last['created_at']) > time() - 120
        && preg_match('/^v' . $num . ' · ([\d,]+%) →/u', (string)$last['note'], $m)) {
        $pdo->prepare('UPDATE budget_history SET note = ? WHERE id = ?')->execute(['v' . $num . ' · ' . $m[1] . ' → ' . $fmt($to), $last['id']]);
        return;
    }
    budget_log($pdo, $sheetId, 'b_margin', 'v' . $num . ' · ' . $fmt($from) . ' → ' . $fmt($to));
}

function budget_history(PDO $pdo, int $sheetId): array {
    $st = $pdo->prepare('SELECT h.*, u.name AS who FROM budget_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.sheet_id = ? ORDER BY h.created_at DESC, h.id DESC LIMIT 200');
    $st->execute([$sheetId]);
    return $st->fetchAll();
}

// ── versões ─────────────────────────────────────────────────

/**
 * Uma versão que deixa de ser a que se edita (aceite, ou com outra por cima) fica com o
 * preço parado: a % de ocupação automática passa a valor fixo, para que mudanças na
 * Equipa (horas por mês) não mexam num preço já proposto.
 */
function budget_freeze(PDO $pdo, array $v): void {
    if ($v['occupancy'] !== null) return;
    $calc = budget_calc($pdo, $v, budget_lines($pdo, (int)$v['id']));
    $pdo->prepare('UPDATE budget_versions SET occupancy = ? WHERE id = ? AND occupancy IS NULL')->execute([$calc['occupancy'], $v['id']]);
    budget_recalc($pdo, (int)$v['id']);
}

/** Uma versão nova começa com a validade por defeito das propostas (Definições › Pipeline). */
function budget_default_validity(PDO $pdo, int $vid): void {
    if (!$vid || !db_has_column($pdo, 'budget_versions', 'valid_until')) return;
    $days = max(1, min(365, (int)setting($pdo, 'proposal_valid_days', 30)));
    $pdo->prepare('UPDATE budget_versions SET valid_until = ? WHERE id = ? AND valid_until IS NULL')
        ->execute([(new DateTimeImmutable(today_lisbon()))->modify('+' . $days . ' days')->format('Y-m-d'), $vid]);
}

/** Nova versão a partir de outra (copia tudo menos a aceitação). Devolve o id. */
function budget_version_new(PDO $pdo, array $sheet, array $from, ?int $by = null, string $why = ''): int {
    budget_freeze($pdo, $from);
    $num = (int)$pdo->query('SELECT COALESCE(MAX(num), 0) + 1 FROM budget_versions WHERE sheet_id = ' . (int)$sheet['id'])->fetchColumn();
    $cols = ['description', 'conditions', 'dur_mode', 'start_date', 'end_date', 'weeks', 'days', 'phases', 'fixed_monthly', 'fixed_manual',
             'occupancy', 'margin', 'mso_pct', 'day_hours', 'price', 'base', 'profit_month', 'work_days'];
    // Opção B e os campos da proposta (§19.3); a validade não passa (cada envio tem a sua).
    if (budget_has_options($pdo)) $cols = array_merge($cols, ['scope_b', 'margin_b', 'price_b', 'base_b', 'profit_month_b', 'term_text', 'payment_terms', 'revisions_text', 'rights_text']);
    $pdo->prepare('INSERT INTO budget_versions (sheet_id, num, ' . implode(',', $cols) . ', created_by) VALUES (?, ?, ' . implode(',', array_fill(0, count($cols), '?')) . ', ?)')
        ->execute([$sheet['id'], $num, ...array_map(fn($c) => $from[$c], $cols), $by]);
    $vid = (int)$pdo->lastInsertId();
    budget_default_validity($pdo, $vid);
    $lc = ['line_key', 'position', 'kind', 'service_id', 'contact_id', 'user_id', 'label', 'price_unit', 'unit_price', 'qty', 'qty_unit', 'cost', 'note'];
    if (budget_has_options($pdo)) $lc[] = 'opt';
    $ins = $pdo->prepare('INSERT INTO budget_lines (version_id, ' . implode(',', $lc) . ') VALUES (?, ' . implode(',', array_fill(0, count($lc), '?')) . ')');
    foreach (budget_lines($pdo, (int)$from['id'], '*') as $l) $ins->execute([$vid, ...array_map(fn($c) => $l[$c], $lc)]);
    budget_log($pdo, (int)$sheet['id'], 'b_version', 'v' . $num . ' criada a partir da v' . $from['num'] . ($why !== '' ? ' · ' . $why : ''), $by);
    return $vid;
}

/** Aceita uma versão: passa a contar para o Resultado e para o valor do negócio no Pipeline. */
function budget_accept(PDO $pdo, array $sheet, array $version, ?int $by = null): void {
    budget_freeze($pdo, $version);
    $version = budget_version_get($pdo, (int)$version['id']);
    $pdo->prepare('UPDATE budget_sheets SET accepted_version_id = ? WHERE id = ?')->execute([$version['id'], $sheet['id']]);
    $pdo->prepare('UPDATE budget_versions SET accepted_at = ?, accepted_by = ? WHERE id = ?')->execute([now_php(), $by, $version['id']]);
    $price = budget_version_price($version);
    $pdo->prepare('UPDATE deals SET value_estimate = ? WHERE id = ?')->execute([$price, $sheet['deal_id']]);
    budget_log($pdo, (int)$sheet['id'], 'b_accept', 'v' . $version['num'] . ' aceite' . (budget_counting_opt($version) === 'B' ? ' (Opção B)' : '') . ' · ' . number_format($price, 0, ',', ' ') . ' € s/ IVA', $by);
    deal_log($pdo, (int)$sheet['deal_id'], 'state', ['note' => 'Orçamento v' . $version['num'] . ' aceite', 'user_id' => $by]);
}

// ── linhas ──────────────────────────────────────────────────

/** Uma quantidade «1,5», «2», «120» → número (0 a 100 000). */
function budget_num($raw, float $max = 100000): float {
    $s = str_replace([' ', "\u{00A0}"], '', (string)$raw);
    if (str_contains($s, ',') && str_contains($s, '.')) $s = str_replace(['.', ','], ['', '.'], $s);
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    else $s = str_replace(',', '.', $s);
    if ($s === '' || !is_numeric($s)) return 0.0;
    return max(0, min($max, round((float)$s, 2)));
}

/**
 * Os dados de uma linha vindos do formulário, validados e com o preço copiado no momento:
 * fornecedor → o preço em vigor do fornecedor ATIVO para esse serviço; equipa → o custo/hora
 * da pessoa (aba Equipa); outro → o que se escreveu.
 */
function budget_line_input(PDO $pdo, array $v, array $src): array {
    $kind = (string)($src['kind'] ?? '');
    $qty = budget_num($src['qty'] ?? 0);
    $note = mb_substr(trim((string)($src['note'] ?? '')), 0, 255);
    if ($kind === 'supplier') {
        $sid = (int)($src['service_id'] ?? 0);
        $cid = (int)($src['contact_id'] ?? 0);
        $offer = null;
        foreach (suppliers_for_service($pdo, $sid) as $o) if ((int)$o['id'] === $cid) $offer = $o;
        if (!$offer) throw new RuntimeException('Escolha um fornecedor ativo que preste este serviço.');
        if ($offer['price'] === null) throw new RuntimeException('Este fornecedor ainda não tem preço para este serviço (está «por rever» em Contactos).');
        $unit = (string)$offer['unit'];
        $qtyUnit = budget_unit_hours($unit, 8) !== null ? (($src['qty_unit'] ?? '') === 'day' ? 'day' : 'hour') : $unit;
        if ($qty <= 0) throw new RuntimeException('Indique a quantidade.');
        $svc = (string)$pdo->query('SELECT name FROM services WHERE id = ' . $sid)->fetchColumn();
        return ['kind' => 'supplier', 'service_id' => $sid, 'contact_id' => $cid, 'user_id' => null, 'label' => mb_substr($svc, 0, 160),
                'price_unit' => $unit, 'unit_price' => (float)$offer['price'], 'qty' => $qty, 'qty_unit' => $qtyUnit, 'note' => $note];
    }
    if ($kind === 'team') {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
        $st->execute([(int)($src['user_id'] ?? 0)]);
        $u = $st->fetch();
        if (!$u) throw new RuntimeException('Escolha a pessoa da equipa.');
        if ($u['hourly_cost'] === null || (float)$u['hourly_cost'] <= 0) throw new RuntimeException('Falta o custo/hora interno de ' . $u['name'] . ' na aba Equipa.');
        if ($qty <= 0) throw new RuntimeException('Indique a quantidade.');
        $label = mb_substr(trim((string)($src['label'] ?? '')), 0, 160);
        return ['kind' => 'team', 'service_id' => null, 'contact_id' => null, 'user_id' => (int)$u['id'], 'label' => $label !== '' ? $label : ($u['job_title'] ?: 'Equipa interna'),
                'price_unit' => 'hour', 'unit_price' => (float)$u['hourly_cost'], 'qty' => $qty, 'qty_unit' => ($src['qty_unit'] ?? '') === 'day' ? 'day' : 'hour', 'note' => $note];
    }
    if ($kind === 'other') {
        $label = mb_substr(trim((string)($src['label'] ?? '')), 0, 160);
        if ($label === '') throw new RuntimeException('Descreva o custo (ex.: render farm, licenças, deslocação).');
        $unit = isset(BUDGET_OTHER_UNITS[$src['price_unit'] ?? '']) ? $src['price_unit'] : 'fixed';
        $price = budget_num($src['unit_price'] ?? 0, 10000000);
        if ($unit !== 'fixed' && $qty <= 0) throw new RuntimeException('Indique a quantidade.');
        return ['kind' => 'other', 'service_id' => null, 'contact_id' => null, 'user_id' => null, 'label' => $label,
                'price_unit' => $unit, 'unit_price' => $price, 'qty' => $unit === 'fixed' ? 1.0 : $qty, 'qty_unit' => $unit, 'note' => $note];
    }
    throw new RuntimeException('Escolha o tipo de linha.');
}

function budget_line_save(PDO $pdo, array $v, ?int $lineId, array $d): int {
    $d['cost'] = budget_line_cost($d, (float)$v['day_hours']);
    $cols = ['kind', 'service_id', 'contact_id', 'user_id', 'label', 'price_unit', 'unit_price', 'qty', 'qty_unit', 'cost', 'note'];
    if ($lineId) {
        $pdo->prepare('UPDATE budget_lines SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ? AND version_id = ?')
            ->execute([...array_map(fn($c) => $d[$c], $cols), $lineId, $v['id']]);
        return $lineId;
    }
    $pos = (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM budget_lines WHERE version_id = ' . (int)$v['id'])->fetchColumn();
    if (budget_has_options($pdo)) { $cols[] = 'opt'; $d['opt'] = ($d['opt'] ?? 'A') === 'B' ? 'B' : 'A'; }
    $pdo->prepare('INSERT INTO budget_lines (version_id, line_key, position, ' . implode(',', $cols) . ') VALUES (?, ?, ?, ' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute([$v['id'], budget_line_key(), $pos, ...array_map(fn($c) => $d[$c], $cols)]);
    return (int)$pdo->lastInsertId();
}

/** «280 €/dia», «35 €/h», «fixo». */
function budget_unit_price_label(?array $l): string {
    if ($l === null || $l['unit_price'] === null) return '';
    $p = number_format((float)$l['unit_price'], (float)$l['unit_price'] == floor((float)$l['unit_price']) ? 0 : 2, ',', ' ') . ' €';
    return match ($l['price_unit']) {
        'fixed' => $p . ' · fixo', 'hour' => $p . '/h', 'day' => $p . '/dia', 'half_day' => $p . '/meio dia', 'unit' => $p . '/unid.',
        'project' => $p . '/projeto', 'piece' => $p . '/peça', 'anim_second' => $p . '/seg.', default => $p,
    };
}

/** «10 dias», «120 horas», «1 projeto». */
function budget_qty_label(array $l): string {
    $q = rtrim(rtrim(number_format((float)$l['qty'], 2, ',', ' '), '0'), ',');
    if ($l['kind'] === 'other' && $l['price_unit'] === 'fixed') return '—';
    return $q . ' ' . match ($l['qty_unit']) {
        'hour' => (float)$l['qty'] == 1 ? 'hora' : 'horas', 'day' => (float)$l['qty'] == 1 ? 'dia' : 'dias',
        'project' => (float)$l['qty'] == 1 ? 'projeto' : 'projetos', 'piece' => (float)$l['qty'] == 1 ? 'peça' : 'peças',
        'anim_second' => 'seg. de animação', 'half_day' => 'meios dias', 'unit' => (float)$l['qty'] == 1 ? 'unidade' : 'unidades', default => '',
    };
}

/** Quem é a linha: o fornecedor, a pessoa da equipa ou «interno». */
function budget_line_who(array $l): string {
    return match ($l['kind']) {
        'supplier' => (string)$l['contact_name'] . ((int)($l['contact_active'] ?? 1) === 1 ? '' : ' · agora inativo'),
        'team' => ($l['user_name'] ?? 'Equipa') . ' · interno',
        default => 'interno · manual',
    };
}

// ── modelos ─────────────────────────────────────────────────

/** Guarda uma versão como modelo (linhas típicas e parâmetros, sem preços: aplicam-se os do momento). */
function budget_template_save(PDO $pdo, array $v, array $lines, string $name, ?int $by = null): int {
    $name = mb_substr(trim($name), 0, 120);
    if ($name === '') throw new RuntimeException('Dê um nome ao modelo (ex.: «Spot 3D 30s»).');
    $data = ['params' => ['dur_mode' => $v['dur_mode'], 'weeks' => $v['weeks'], 'days' => $v['days'], 'phases' => budget_phases($v),
                          'occupancy' => $v['occupancy'], 'description' => (string)$v['description']],
             'lines' => array_map(fn($l) => ['kind' => $l['kind'], 'service_id' => $l['service_id'], 'contact_id' => $l['contact_id'], 'user_id' => $l['user_id'],
                                             'label' => $l['label'], 'price_unit' => $l['price_unit'], 'unit_price' => $l['kind'] === 'other' ? $l['unit_price'] : null,
                                             'qty' => $l['qty'], 'qty_unit' => $l['qty_unit'], 'note' => $l['note']], $lines)];
    $pdo->prepare('INSERT INTO budget_templates (name, data, created_by) VALUES (?, ?, ?)')->execute([$name, json_encode($data, JSON_UNESCAPED_UNICODE), $by]);
    return (int)$pdo->lastInsertId();
}

function budget_templates(PDO $pdo): array {
    return $pdo->query('SELECT t.*, u.name AS author FROM budget_templates t LEFT JOIN users u ON u.id = t.created_by ORDER BY t.name')->fetchAll();
}

/**
 * Aplica um modelo: numa folha com a última versão vazia e editável, preenche-a; senão cria
 * uma versão nova. Os preços vêm do preçário e da Equipa de agora; uma linha cujo fornecedor
 * deixou de estar ativo ou sem preço fica de fora (devolvida em «skipped»).
 */
function budget_template_apply(PDO $pdo, array $sheet, array $tpl, ?int $by = null): array {
    $data = json_decode((string)$tpl['data'], true) ?: [];
    $versions = budget_versions($pdo, (int)$sheet['id']);
    $last = end($versions);
    $empty = $last && !budget_lines($pdo, (int)$last['id']) && budget_editable($sheet, $last, $versions);
    $vid = $empty ? (int)$last['id'] : budget_version_new($pdo, $sheet, $last, $by, 'modelo «' . $tpl['name'] . '»');
    $v = budget_version_get($pdo, $vid);
    $p = $data['params'] ?? [];
    $pdo->prepare('UPDATE budget_versions SET dur_mode = ?, weeks = ?, days = ?, phases = ?, occupancy = ?, description = COALESCE(NULLIF(description, \'\'), ?) WHERE id = ?')
        ->execute([isset(BUDGET_DUR_MODES[$p['dur_mode'] ?? '']) ? $p['dur_mode'] : 'days', $p['weeks'] ?? null, $p['days'] ?? BUDGET_MONTH_DAYS,
                   json_encode($p['phases'] ?? [], JSON_UNESCAPED_UNICODE), $p['occupancy'] ?? null, (string)($p['description'] ?? ''), $vid]);
    if (!$empty) $pdo->prepare('DELETE FROM budget_lines WHERE version_id = ?')->execute([$vid]);
    $skipped = [];
    foreach ($data['lines'] ?? [] as $l) {
        try {
            $src = $l + ['qty_unit' => $l['qty_unit'] ?? 'hour'];
            if ($l['kind'] === 'other') $src['unit_price'] = $l['unit_price'];
            budget_line_save($pdo, $v, null, budget_line_input($pdo, $v, $src));
        } catch (RuntimeException $e) {
            $skipped[] = ($l['label'] ?: '?') . ' — ' . $e->getMessage();
        }
    }
    budget_log($pdo, (int)$sheet['id'], 'b_model', 'Modelo «' . $tpl['name'] . '» na v' . $v['num'] . ($skipped ? ' · ' . count($skipped) . ' linha(s) de fora' : ''), $by);
    budget_recalc($pdo, $vid);
    return ['version_id' => $vid, 'skipped' => $skipped];
}

// ── resultado: orçado vs real (§14.5) ──────────────────────

/** O dia em que o negócio passou a Kickoff (começo real da produção), se se souber. */
function budget_kickoff_date(PDO $pdo, int $dealId): ?string {
    $st = $pdo->prepare("SELECT MIN(h.created_at) FROM deal_history h JOIN funnel_stages s ON s.id = h.to_stage_id WHERE h.deal_id = ? AND s.kind = 'kickoff'");
    $st->execute([$dealId]);
    $d = $st->fetchColumn();
    return $d ? substr((string)$d, 0, 10) : null;
}

/**
 * Orçado vs real, linha a linha, pela versão aceite (ou a última): o real são os custos do
 * negócio no Financeiro ligados a cada linha (costs.budget_key). As linhas da equipa sem
 * registo real contam como orçadas; os custos que não se ligaram a nenhuma linha aparecem
 * à parte. Prazo real: do Kickoff (ou da data de início) à conclusão (ou a hoje).
 */
function budget_result(PDO $pdo, array $sheet, array $deal): array {
    $v = budget_current_version($pdo, $sheet);
    $lines = budget_lines($pdo, (int)$v['id']);
    $calc = budget_calc($pdo, $v, $lines);
    $rate = vat_rate($pdo);
    $costs = deal_costs($pdo, (int)$deal['id'], $rate);
    $byKey = [];
    foreach ($costs as $c) if ($c['budget_key']) $byKey[$c['budget_key']][] = $c;
    $rows = [];
    $realDirect = 0.0;
    foreach ($lines as $l) {
        $linked = $byKey[$l['line_key']] ?? [];
        unset($byKey[$l['line_key']]);
        $real = $linked ? round(array_sum(array_column($linked, 'net')), 2) : null;
        $assumed = !$linked && $l['kind'] === 'team';
        $use = $real ?? ($assumed ? (float)$l['cost'] : 0.0);
        $realDirect += $use;
        $diff = $real !== null ? round($real - (float)$l['cost'], 2) : null;
        $pct = $real !== null && (float)$l['cost'] > 0 ? round($diff / (float)$l['cost'] * 100, 1) : null;
        $rows[] = ['label' => $l['label'], 'who' => budget_line_who($l), 'service_id' => $l['service_id'], 'kind' => $l['kind'],
                   'budget' => (float)$l['cost'], 'real' => $real, 'assumed' => $assumed, 'diff' => $diff, 'pct' => $pct,
                   'level' => $pct === null ? '' : ($pct > 2 ? 'bad' : ($pct < -2 ? 'ok' : '')), 'costs' => count($linked)];
    }
    $outside = [];
    foreach ($byKey as $list) foreach ($list as $c) $outside[] = $c;
    foreach ($costs as $c) if (!$c['budget_key']) $outside[] = $c;
    foreach ($outside as $c) $realDirect += $c['net'];
    $realDirect = round($realDirect, 2);

    $start = $v['start_date'] ?: (budget_kickoff_date($pdo, (int)$deal['id']) ?: ($deal['converted_at'] ? substr((string)$deal['converted_at'], 0, 10) : null));
    $end = $deal['phase'] === 'done' && $deal['done_at'] ? substr((string)$deal['done_at'], 0, 10) : today_lisbon();
    $realDays = $start && in_array($deal['phase'], ['production', 'done'], true) ? budget_workdays($start, $end) : null;
    $realMonths = $realDays ? max($realDays, 1) / BUDGET_MONTH_DAYS : $calc['months'];
    $realPrice = (float)$deal['budget'] > 0 ? (float)$deal['budget'] : $calc['price'];
    $realStructure = round($calc['fixed'] * $realMonths * $calc['occupancy'] / 100, 2);
    $realBase = round($realDirect + $realStructure, 2);
    $realGp = round($realPrice - $realBase, 2);
    $overrun = max(0, $realDirect - $calc['direct']);
    return [
        'version' => (int)$v['num'], 'accepted' => (int)$sheet['accepted_version_id'] === (int)$v['id'],
        'rows' => $rows, 'outside' => array_map(fn($c) => ['label' => $c['name'] ?: $c['role'], 'who' => (string)($c['contact_name'] ?? ''), 'real' => $c['net']], $outside),
        'budget' => ['direct' => $calc['direct'], 'mso' => $calc['mso'], 'price' => $calc['price'], 'gp' => $calc['gp'], 'gm' => $calc['gm'],
                     'days' => $calc['days'], 'profit_month' => $calc['profit_month'], 'structure' => $calc['structure'], 'base' => $calc['base']],
        'real' => ['direct' => $realDirect, 'price' => $realPrice, 'price_from' => (float)$deal['budget'] > 0 ? 'financeiro' : 'orcamento',
                   'structure' => $realStructure, 'base' => $realBase, 'gp' => $realGp, 'gm' => $realPrice > 0 ? round($realGp / $realPrice * 100, 1) : null,
                   'days' => $realDays, 'start' => $start, 'end' => $end, 'profit_month' => round($realGp / $realMonths, 2)],
        'mso_used' => $calc['mso'] > 0 ? round($overrun / $calc['mso'] * 100) : ($overrun > 0 ? 100 : 0), 'overrun' => round($overrun, 2),
        'closed' => (bool)$sheet['closed_at'], 'computed_at' => now_php(),
    ];
}

/**
 * O Resultado fecha quando o negócio é concluído (fica o retrato desse dia) e volta a abrir se o
 * negócio for reaberto; a folha segue o estado do negócio. Devolve a folha atualizada.
 */
function budget_sync_state(PDO $pdo, array $sheet, array $deal): array {
    if ($deal['phase'] === 'done' && !$sheet['closed_at']) {
        $r = budget_result($pdo, $sheet, $deal);
        $r['closed'] = true;
        $pdo->prepare('UPDATE budget_sheets SET closed_at = ?, result_json = ? WHERE id = ?')->execute([now_php(), json_encode($r, JSON_UNESCAPED_UNICODE), $sheet['id']]);
        budget_log($pdo, (int)$sheet['id'], 'b_close', 'Negócio concluído: Resultado fechado', null);
        return budget_sheet_by_id($pdo, (int)$sheet['id']);
    }
    if ($deal['phase'] !== 'done' && $sheet['closed_at']) {
        $pdo->prepare('UPDATE budget_sheets SET closed_at = NULL, result_json = NULL WHERE id = ?')->execute([$sheet['id']]);
        budget_log($pdo, (int)$sheet['id'], 'b_reopen', 'Negócio reaberto: a folha voltou a abrir', null);
        return budget_sheet_by_id($pdo, (int)$sheet['id']);
    }
    return $sheet;
}

/** As linhas da versão que conta, para ligar um custo do Financeiro a uma delas. */
function budget_link_options(PDO $pdo, int $dealId): array {
    $s = budget_sheet_get($pdo, $dealId);
    if (!$s || !($v = budget_current_version($pdo, $s))) return [];
    $out = [];
    foreach (budget_lines($pdo, (int)$v['id']) as $l) $out[$l['line_key']] = $l['label'] . ' · ' . budget_line_who($l);
    return $out;
}

/** O custo novo de um fornecedor liga-se sozinho à linha desse fornecedor, se houver só uma. */
function budget_auto_key(PDO $pdo, int $dealId, ?int $contactId): ?string {
    if (!$contactId || !($s = budget_sheet_get($pdo, $dealId)) || !($v = budget_current_version($pdo, $s))) return null;
    $keys = [];
    foreach (budget_lines($pdo, (int)$v['id']) as $l) if ((int)$l['contact_id'] === $contactId) $keys[] = $l['line_key'];
    return count($keys) === 1 ? $keys[0] : null;
}

/** «18,6k» para os chips do lucro por mês. */
function budget_k(float $v): string {
    return abs($v) >= 1000 ? str_replace('.', ',', (string)round($v / 1000, 1)) . 'k' : (string)round($v);
}

// ── Relatórios (§14.5): o que os Resultados fechados ensinam ───

/**
 * Dos negócios concluídos no ano (Resultado fechado): margem orçada e real e lucro por mês real,
 * por funil e por tipo de trabalho; e os serviços cujas linhas mais derrapam (real vs orçado).
 */
function budget_reports(PDO $pdo, int $year): array {
    $st = $pdo->prepare("SELECT s.result_json, d.job_type, d.service, f.name AS funnel
                           FROM budget_sheets s JOIN deals d ON d.id = s.deal_id LEFT JOIN funnels f ON f.id = d.funnel_id
                          WHERE s.closed_at IS NOT NULL AND s.result_json IS NOT NULL AND d.done_at >= ? AND d.done_at < ?");
    $st->execute([$year . '-01-01 00:00:00', ($year + 1) . '-01-01 00:00:00']);
    $svc = $pdo->query('SELECT id, name FROM services')->fetchAll(PDO::FETCH_KEY_PAIR);
    $funnel = $type = $over = [];
    $acc = function (array &$bucket, string $k, array $r) {
        $bucket[$k] ??= ['n' => 0, 'gm_b' => 0.0, 'gm_r' => 0.0, 'pm_r' => 0.0, 'price' => 0.0];
        $bucket[$k]['n']++;
        $bucket[$k]['gm_b'] += (float)($r['budget']['gm'] ?? 0);
        $bucket[$k]['gm_r'] += (float)($r['real']['gm'] ?? 0);
        $bucket[$k]['pm_r'] += (float)($r['real']['profit_month'] ?? 0);
        $bucket[$k]['price'] += (float)($r['real']['price'] ?? 0);
    };
    foreach ($st->fetchAll() as $row) {
        $r = json_decode((string)$row['result_json'], true);
        if (!is_array($r) || empty($r['rows'])) continue;   // folhas sem linhas (ex.: negócios antigos) não dizem nada
        $acc($funnel, (string)($row['funnel'] ?: 'Sem funil'), $r);
        $acc($type, (string)($row['job_type'] ?: ($row['service'] ?: 'Sem tipo')), $r);
        foreach ($r['rows'] as $x) {
            if ($x['real'] === null || empty($x['service_id']) || !isset($svc[$x['service_id']])) continue;
            $k = $svc[$x['service_id']];
            $over[$k] ??= ['lines' => 0, 'budget' => 0.0, 'real' => 0.0, 'over' => 0];
            $over[$k]['lines']++;
            $over[$k]['budget'] += (float)$x['budget'];
            $over[$k]['real'] += (float)$x['real'];
            if ((float)$x['real'] > (float)$x['budget'] * 1.02) $over[$k]['over']++;
        }
    }
    $avg = fn(array $b) => array_map(fn($x) => $x + ['gm_b_avg' => round($x['gm_b'] / $x['n'], 1), 'gm_r_avg' => round($x['gm_r'] / $x['n'], 1), 'pm_r_avg' => round($x['pm_r'] / $x['n'], 2)], $b);
    foreach ($over as &$o) $o['pct'] = $o['budget'] > 0 ? round(($o['real'] - $o['budget']) / $o['budget'] * 100, 1) : null;
    unset($o);
    uasort($over, fn($a, $b) => ($b['pct'] ?? 0) <=> ($a['pct'] ?? 0));
    return ['funnel' => $avg($funnel), 'type' => $avg($type), 'over' => $over];
}
