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
