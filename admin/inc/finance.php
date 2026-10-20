<?php
/**
 * topper. admin — contas dos negócios, calculadas pelo sistema
 *
 * As mesmas regras que a folha usa (sheets/CONTRACT.md), agora feitas aqui:
 *   Budget c/ IVA = budget s/ IVA × 1,23 quando o negócio fatura com IVA;
 *   Gastos        = gastos manuais, se preenchidos; senão a soma dos custos
 *                   do negócio, sem IVA (cost_net);
 *   Lucro         = budget s/ IVA − gastos;
 *   IVA liquidado = budget c/ IVA − budget s/ IVA;
 *   IVA suportado = IVA dos custos; com gastos manuais, gastos × 23% se
 *                   o negócio fatura com IVA (a mesma regra de sempre).
 * Os valores guardam-se sempre sem IVA.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

// ── IVA de cada custo ───────────────────────────────────────
//
// Cada custo guarda o valor TAL COMO FOI ESCRITO, mais aquilo que
// esse valor é:
//   none  Sem IVA        1800 →  custo 1800,00   IVA    0,00
//   plus  + IVA 23%      1800 →  custo 1800,00   IVA  414,00
//   incl  IVA incluído   1800 →  custo 1463,41   IVA  336,59
// O custo que conta para o lucro é sempre o valor limpo.

/** A taxa de IVA (settings.vat_rate, 23 por omissão). */
function vat_rate(PDO $pdo): float {
    return (float)setting($pdo, 'vat_rate', 23);
}

/** O valor escrito (costs.amount; aceita também fee). */
function cost_amount(array $c): float {
    return (float)($c['amount'] ?? $c['fee'] ?? 0);
}

/** O que o custo pesa no lucro. */
function cost_net(array $c, float $rate): float {
    $v = cost_amount($c);
    return ($c['vat_mode'] ?? 'plus') === 'incl' ? round($v / (1 + $rate / 100), 2) : $v;
}

/** O IVA do custo, que se deduz. */
function cost_vat(array $c, float $rate): float {
    $v = cost_amount($c);
    $mode = $c['vat_mode'] ?? 'plus';
    if ($mode === 'plus') return round($v * $rate / 100, 2);
    if ($mode === 'incl') return round($v - $v / (1 + $rate / 100), 2);
    return 0.0;
}

/** Os custos de um negócio, já com valor limpo, IVA e total. */
function deal_costs(PDO $pdo, int $dealId, float $rate): array {
    $st = $pdo->prepare('SELECT c.*, ct.name AS contact_name FROM costs c LEFT JOIN contacts ct ON ct.id = c.contact_id
                          WHERE c.deal_id = ? ORDER BY c.id');
    $st->execute([$dealId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$c) {
        $c['net']   = cost_net($c, $rate);
        $c['vat']   = cost_vat($c, $rate);
        $c['total'] = round($c['net'] + $c['vat'], 2);
    }
    return $rows;
}

/** As contas de um negócio. $costs: as linhas de deal_costs (para não as ler duas vezes). */
function deal_money(array $d, array $costs, float $rate): array {
    $net    = round((float)$d['budget'], 2);
    $hasVat = (int)$d['has_vat'] === 1;
    $gross  = $hasVat ? round($net * (1 + $rate / 100), 2) : $net;

    $cNet = 0.0; $cVat = 0.0; $cClosed = 0.0;
    foreach ($costs as $c) {
        $cNet += $c['net'];
        $cVat += $c['vat'];
        if ((int)$c['is_closed'] === 1) $cClosed += $c['net'];
    }
    $manual   = $d['expenses_mode'] === 'manual';
    $expenses = $manual ? round((float)$d['expenses_manual'], 2) : round($cNet, 2);
    $vatIn    = $manual ? ($hasVat ? round((float)$d['expenses_manual'] * $rate / 100, 2) : 0.0) : round($cVat, 2);

    return [
        'budget_net'   => $net,
        'budget_gross' => $gross,
        'vat_out'      => round($gross - $net, 2),
        'expenses'     => $expenses,
        'vat_in'       => $vatIn,
        'profit'       => round($net - $expenses, 2),
        'costs_net'    => round($cNet, 2),
        'costs_vat'    => round($cVat, 2),
        'costs_closed' => round($cClosed, 2),
        'costs_count'  => count($costs),
    ];
}

/** Os campos financeiros de um negócio vindos do formulário (budget sempre guardado sem IVA). */
function deal_finance_input(array $src, float $rate): array {
    $incl = !empty($src['budget_incl_vat']);
    $m = (int)($src['month'] ?? 0);
    $y = (int)($src['year'] ?? 0);
    return [
        'job_type'         => in_array($src['job_type'] ?? '', DEAL_JOB_TYPES, true) ? $src['job_type'] : '',
        'year'             => $y >= 2000 && $y <= 2100 ? $y : null,
        'month'            => $m >= 1 && $m <= 12 ? $m : null,
        'budget'           => net_from_input($src['budget'] ?? '0', $incl, $rate),
        'is_international' => !empty($src['is_international']) ? 1 : 0,
        'has_vat'          => !empty($src['has_vat']) ? 1 : 0,
        'is_paid'          => !empty($src['is_paid']) ? 1 : 0,
        'expenses_mode'    => ($src['expenses_mode'] ?? '') === 'manual' ? 'manual' : 'crew',
        'expenses_manual'  => money_in($src['expenses_manual'] ?? '0'),
    ];
}

const DEAL_JOB_TYPES = ['Animação 3D', 'VFX', 'Edição', 'Produção Completa', 'Fotografia'];

/** Os campos de um custo vindos do formulário. */
function cost_input(array $src): array {
    $mode = (string)($src['vat_mode'] ?? 'plus');
    return [
        'name'     => mb_substr(trim((string)($src['name'] ?? '')), 0, 160),
        'role'     => mb_substr(trim((string)($src['role'] ?? '')), 0, 120),
        'amount'   => money_in($src['amount'] ?? '0'),
        'vat_mode' => isset(VAT_MODES[$mode]) ? $mode : 'plus',
        'is_closed'=> !empty($src['is_closed']) ? 1 : 0,
        'notes'    => mb_substr(trim((string)($src['notes'] ?? '')), 0, 2000),
    ];
}

/** Os custos da empresa (sem negócio), já com valor limpo e IVA. $year null = todos. */
function company_cost_rows(PDO $pdo, float $rate, ?int $year = null): array {
    $st = $pdo->prepare('SELECT * FROM costs WHERE deal_id IS NULL' . ($year ? ' AND year = ?' : '') . ' ORDER BY year DESC, COALESCE(month, 13) DESC, id DESC');
    $st->execute($year ? [$year] : []);
    $rows = $st->fetchAll();
    foreach ($rows as &$c) {
        $c['net']   = cost_net($c, $rate);
        $c['vat']   = cost_vat($c, $rate);
        $c['total'] = round($c['net'] + $c['vat'], 2);
    }
    return $rows;
}

/** Os negócios faturados de um ano (em produção ou concluídos), cada um com as suas contas. */
function year_deals(PDO $pdo, int $year, float $rate): array {
    $st = $pdo->prepare("SELECT d.*, c.name AS contact_name FROM deals d LEFT JOIN contacts c ON c.id = d.contact_id
                          WHERE d.code IS NOT NULL AND d.phase IN ('production','done') AND d.year = ?
                          ORDER BY COALESCE(d.month, 0) DESC, d.code DESC");
    $st->execute([$year]);
    $rows = $st->fetchAll();
    foreach ($rows as &$d) $d['m'] = deal_money($d, deal_costs($pdo, (int)$d['id'], $rate), $rate);
    return $rows;
}

/** Os totais de um ano — as mesmas contas que a folha fazia. */
function finance_year(PDO $pdo, int $year, float $rate): array {
    $revenue = 0.0; $gross = 0.0; $costs = 0.0; $unpaid = 0.0; $vatOut = 0.0; $vatIn = 0.0;
    $deals = year_deals($pdo, $year, $rate);
    foreach ($deals as $d) {
        $revenue += $d['m']['budget_net'];
        $gross   += $d['m']['budget_gross'];
        $costs   += $d['m']['expenses'];
        $vatOut  += $d['m']['vat_out'];
        $vatIn   += $d['m']['vat_in'];
        if (!(int)$d['is_paid']) $unpaid += $d['m']['budget_net'];
    }
    foreach (company_cost_rows($pdo, $rate, $year) as $c) {
        $costs += $c['net'];
        $vatIn += $c['vat'];
    }
    return [
        'revenue' => round($revenue, 2), 'gross' => round($gross, 2), 'costs' => round($costs, 2),
        'profit'  => round($revenue - $costs, 2), 'unpaid' => round($unpaid, 2),
        'vat_out' => round($vatOut, 2), 'vat_in' => round($vatIn, 2), 'count' => count($deals),
    ];
}

/** Anos com negócios ou custos, mais o corrente, do mais recente para o mais antigo. */
function finance_years(PDO $pdo): array {
    $y = [(int)date('Y')];
    foreach ($pdo->query("SELECT DISTINCT year FROM deals WHERE year IS NOT NULL AND code IS NOT NULL
                          UNION SELECT DISTINCT year FROM costs WHERE year IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $v) $y[] = (int)$v;
    $y = array_values(array_unique(array_filter($y)));
    rsort($y);
    return $y;
}
