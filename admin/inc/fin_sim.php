<?php
/**
 * topper. admin — Financeiro: simulador «E se…?» (§28.10)
 *
 * Quatro decisões: contratar, arrendar escritório, investir (serviço, software, equipamento) e comprar
 * viatura. Para cada uma: custo mensal real, custo anual, o que sai de uma vez, runway útil antes → depois
 * (leitura conservadora), mês em que se entra na reserva, receita extra para manter o runway (custo ÷
 * margem média dos negócios) e a poupança de IRC (só com lucro: 15% até ao limite PME, 19% acima, mais a
 * derrama). Todas as taxas são parâmetros; o resultado é sempre uma estimativa a confirmar.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin_cash.php';
require_once __DIR__ . '/fin_pay.php';

const FIN_SIM_KINDS = ['hire' => 'Contratar', 'office' => 'Arrendar escritório', 'invest' => 'Investir', 'car' => 'Comprar viatura'];
const FIN_CAR_MOTORS = ['ev' => 'Elétrico', 'phev' => 'Híbrido plug-in', 'hybrid' => 'Híbrido normal', 'petrol' => 'Gasolina', 'diesel' => 'Gasóleo', 'gpl' => 'GPL / GNV'];
const FIN_CAR_BUY = ['cash' => 'A pronto', 'credit' => 'Crédito', 'renting' => 'Renting (AOV)'];
const FIN_INVEST_TYPES = ['service' => 'Serviço / subscrição', 'software' => 'Software (licença)', 'it' => 'Equipamento informático', 'av' => 'Equipamento audiovisual / câmaras', 'furniture' => 'Mobiliário'];
/** Consumos por omissão para comparar motorizações (consumo por 100 km, preço por unidade com IVA). */
const FIN_CAR_DEFAULTS = ['ev' => [17, 0.20], 'phev' => [3.5, 1.30], 'hybrid' => [5.0, 1.75], 'petrol' => [6.5, 1.75], 'diesel' => [5.5, 1.65], 'gpl' => [8.0, 0.95]];

/** O IRC (com derrama) de um lucro tributável: PME até ao limite, taxa geral acima. */
function fin_irc(PDO $pdo, float $p): float {
    if ($p <= 0) return 0.0;
    $lim = fin_p($pdo, 'irc_sme_limit', 50000);
    return min($p, $lim) * fin_p($pdo, 'irc_sme_rate', 15) / 100 + max(0, $p - $lim) * fin_p($pdo, 'irc_rate', 19) / 100 + $p * fin_p($pdo, 'derrama_rate', 1.5) / 100;
}

/** O lucro anual estimado (o do ano até agora, anualizado) e a margem média dos negócios dos últimos 12 meses. */
function fin_sim_base(PDO $pdo): array {
    static $b = null;
    if ($b) return $b;
    $y = (int)date('Y', strtotime(today_lisbon()));
    $rate = vat_rate($pdo);
    $t = finance_year($pdo, $y, $rate);
    $months = max(1, (int)date('n', strtotime(today_lisbon())));
    $rev = 0.0; $prof = 0.0;
    foreach ([$y - 1, $y] as $yy) foreach (year_deals($pdo, $yy, $rate) as $d) {
        $when = sprintf('%04d-%02d', $yy, (int)($d['month'] ?: 12));
        if ($when < date('Y-m', strtotime(today_lisbon() . ' -12 months'))) continue;
        $rev += $d['m']['budget_net']; $prof += $d['m']['profit'];
    }
    $margin = $rev > 0 ? $prof / $rev : (float)setting($pdo, 'budget_margin_pct', 65) / 100;
    return $b = ['profit' => round($t['profit'] / $months * 12, 2), 'margin' => max(0.05, min(0.95, $margin)), 'margin_from' => $rev > 0 ? 'negócios' : 'Definições'];
}

/**
 * Runway útil conservador (sem receita) com um custo mensal a mais a partir de $startIn meses e um valor que sai
 * já: [útil, data em que se entra na reserva]. A reserva conta com os custos fixos depois da decisão.
 */
function fin_sim_runway(PDO $pdo, float $extra, float $oneOff, int $startIn = 0): array {
    $rw = fin_runway($pdo);
    $burn0 = $rw['burn'];
    $burn1 = $burn0 + $extra;
    if ($burn1 <= 0) return [null, null];
    $bal = $rw['available'] - $oneOff;
    $reserve = $rw['reserve_months'] * $burn1;
    if ($bal < $reserve) return [round(($bal - $reserve) / $burn1, 1), today_lisbon()];
    for ($k = 1; $k <= 600; $k++) {
        $b = $k <= $startIn ? $burn0 : $burn1;
        if ($bal - $b < $reserve) { $m = $k - 1 + ($bal - $reserve) / $b; return [round($m, 1), fin_add_months(today_lisbon(), $m)]; }
        $bal -= $b;
    }
    return [600.0, null];
}

/** Os campos de cada tipo, com os valores por omissão (o formulário e a gravação usam isto). */
function fin_sim_fields(string $kind): array {
    return match ($kind) {
        'hire' => ['name' => '', 'gross' => '1200', 'meal' => '10.46', 'meal_type' => 'card', 'start' => date('Y-m-01', strtotime('first day of next month')), 'equipment' => '0', 'equipment_type' => 'it'],
        'office' => ['name' => '', 'rent' => '1500', 'deposit_months' => '2', 'expenses' => '300', 'works' => '0', 'landlord_vat' => '0', 'landlord_wh' => '1', 'start' => date('Y-m-01', strtotime('first day of next month'))],
        'invest' => ['name' => '', 'mode' => 'once', 'value' => '3000', 'vat_deductible' => '1', 'months' => '12', 'type' => 'it'],
        'car' => ['name' => '', 'car_type' => 'passenger', 'motor' => 'ev', 'price' => '40000', 'buy' => 'cash', 'down' => '0', 'credit_rate' => '6', 'credit_months' => '60',
                  'renting' => '0', 'km' => '15000', 'consumption' => '', 'energy_price' => '', 'insurance' => '900', 'maintenance' => '400', 'tolls' => '300', 'phev_ok' => '1'],
        default => [],
    };
}

/** Lê e limpa os campos (números com vírgula). */
function fin_sim_inputs(string $kind, array $src): array {
    $out = [];
    foreach (fin_sim_fields($kind) as $k => $def) {
        $v = array_key_exists($k, $src) ? trim((string)$src[$k]) : (string)$def;
        if (in_array($k, ['name', 'meal_type', 'start', 'equipment_type', 'mode', 'type', 'car_type', 'motor', 'buy'], true)) $out[$k] = mb_substr($v, 0, 120);
        else $out[$k] = $v === '' ? '' : (string)money_in($v);
    }
    if (isset($out['start']) && !fin_date_ok($out['start'])) $out['start'] = date('Y-m-01', strtotime('first day of next month'));
    return $out;
}

/** O cálculo. Devolve: monthly, annual, oneoff, cash_monthly, irc_saving, lines, notes, runway (antes/depois), extra_revenue. */
function fin_sim(PDO $pdo, string $kind, array $in): array {
    $base = fin_sim_base($pdo);
    $tsu = fin_p($pdo, 'ss_employer', 23.75) / 100;
    $vatN = fin_p($pdo, 'vat_normal', 23) / 100;
    $lines = []; $notes = [];
    $monthly = 0.0; $oneoff = 0.0; $deductYear = 0.0; $startIn = 0; $cashMonthly = null;
    $num = fn($k) => (float)($in[$k] ?? 0);
    switch ($kind) {
        case 'hire':
            $g = $num('gross');
            $e = ['name' => 'Simulação', 'base_salary' => $g, 'meal_value' => $num('meal'), 'meal_type' => $in['meal_type'] === 'cash' ? 'cash' : 'card', 'meal_days' => 22,
                  'irs_status' => 'single', 'dependents' => 0, 'dep_disabled' => 0, 'disability' => 0, 'residence' => 'continente', 'duodecimos' => 0, 'nif' => 'x', 'niss' => 'x', 'iban' => 'x', 'start_date' => null];
            $a = fin_employee_annual($pdo, $e);
            $monthly = $a['monthly'];
            $deductYear = $a['annual'];
            $lines[] = ['Salário bruto', $g, '14 meses por ano'];
            $lines[] = ['TSU da empresa (' . fin_num($tsu * 100) . '%)', round($g * $tsu, 2), 'por mês de salário'];
            $lines[] = ['Subsídio de refeição', round($a['meal'] / 12, 2), '22 dias × 11 meses, média mensal'];
            if ($a['insurance'] > 0) $lines[] = ['Seguro de acidentes de trabalho e FCT', round($a['insurance'] / 12, 2), 'média mensal'];
            try {
                $calc = fin_payroll_calc($pdo, $e, (int)date('Y'), 3);
                $lines[] = ['Líquido que a pessoa recebe (mês normal)', $calc['net'], 'não casado, sem dependentes, continente'];
            } catch (Throwable $ex) { $notes[] = $ex->getMessage(); }
            $min = fin_p($pdo, 'min_wage', 0);
            if ($g > 0 && $g < $min) $notes[] = 'Abaixo do salário mínimo (' . eur($min) . ').';
            if ($num('equipment') > 0) {
                $oneoff = $num('equipment');
                $rate = fin_p($pdo, 'depr_' . (in_array($in['equipment_type'], ['it', 'av', 'furniture', 'software'], true) ? $in['equipment_type'] : 'it'), 33.33) / 100;
                $deductYear += $oneoff * $rate;
                $lines[] = ['Equipamento inicial (de uma vez, s/ IVA)', $oneoff, 'amortizado a ' . fin_num($rate * 100) . '%/ano'];
            }
            $startIn = max(0, (int)round((strtotime($in['start']) - strtotime(today_lisbon())) / (86400 * 30.4)));
            break;
        case 'office':
            $rent = $num('rent'); $exp = $num('expenses');
            $vatRent = (int)$num('landlord_vat') ? 'deductible' : 'none';
            $monthly = round($rent + $exp, 2);
            $oneoff = round($rent * $num('deposit_months') + $num('works'), 2);
            $deductYear = ($rent + $exp) * 12 + $num('works') * fin_p($pdo, 'depr_furniture', 12.5) / 100;
            $lines[] = ['Renda', $rent, $vatRent === 'deductible' ? 'com IVA (renúncia à isenção) — dedutível' : 'isenta de IVA (sem IVA a deduzir)'];
            $lines[] = ['Despesas fixas (eletricidade, água, internet, condomínio, limpeza, seguro)', $exp, 's/ IVA (IVA dedutível)'];
            $lines[] = ['Caução (' . fin_num($num('deposit_months'), 0) . ' meses) — sai do saldo, não é custo', round($rent * $num('deposit_months'), 2), 'de uma vez'];
            if ($num('works') > 0) $lines[] = ['Obras e mobiliário', $num('works'), 'amortizado a ' . fin_num(fin_p($pdo, 'depr_furniture', 12.5)) . '%/ano'];
            if ((int)$num('landlord_wh')) $notes[] = 'Senhorio com contabilidade organizada ou empresa: retém-se ' . fin_num(fin_p($pdo, 'wh_rent', 25)) . '% de IRS na renda e entrega-se ao Estado até dia ' . (int)fin_p($pdo, 'cal_irs_pay', 20) . ' — o custo é o mesmo.';
            $notes[] = 'A renda é gasto dedutível em IRC.';
            $startIn = max(0, (int)round((strtotime($in['start']) - strtotime(today_lisbon())) / (86400 * 30.4)));
            break;
        case 'invest':
            $v = $num('value');
            $ded = (int)$num('vat_deductible') === 1;
            $vatCost = $ded ? 0.0 : $v * $vatN;
            $type = isset(FIN_INVEST_TYPES[$in['type']]) ? $in['type'] : 'service';
            if ($in['mode'] === 'monthly') {
                $monthly = round($v + $vatCost, 2);
                $deductYear = $monthly * 12;
                $lines[] = ['Valor mensal' . ($ded ? ' (IVA dedutível)' : ' (com IVA não dedutível)'), $monthly, (int)$num('months') ? 'durante ' . (int)$num('months') . ' meses' : ''];
            } else {
                $oneoff = round($v + $v * $vatN, 2);
                $cost = $v + $vatCost;
                if ($type === 'service') {
                    $deductYear = $cost;
                    $monthly = round($cost / 12, 2);
                    $lines[] = ['Serviço pago de uma vez', $cost, 'gasto do ano'];
                } else {
                    $rate = fin_p($pdo, 'depr_' . $type, 33.33) / 100;
                    $deductYear = $cost * $rate;
                    $monthly = round($cost * $rate / 12, 2);
                    $lines[] = ['Investimento' . ($ded ? ' (s/ IVA; IVA recuperado)' : ' (com IVA)'), $cost, 'amortizado a ' . fin_num($rate * 100) . '%/ano (' . fin_num(1 / max(0.01, $rate), 1) . ' anos)'];
                    $lines[] = ['Amortização por mês', $monthly, 'o custo que entra no resultado'];
                }
                if ($ded) $notes[] = 'O IVA (' . eur($v * $vatN) . ') sai de uma vez e volta na declaração do período.';
            }
            break;
        case 'car':
            return fin_sim_car($pdo, $in, $base);
    }
    return fin_sim_result($pdo, $base, $monthly, $oneoff, $deductYear, $lines, $notes, $startIn, $cashMonthly);
}

/** Junta o resultado comum: IRC, runway antes/depois, receita extra. */
function fin_sim_result(PDO $pdo, array $base, float $monthly, float $oneoff, float $deductYear, array $lines, array $notes, int $startIn, ?float $cashMonthly, array $extra = []): array {
    $saving = $base['profit'] > 0 ? round(fin_irc($pdo, $base['profit']) - fin_irc($pdo, $base['profit'] - $deductYear), 2) : 0.0;
    if ($base['profit'] <= 0) $notes[] = 'Sem lucro estimado este ano: não há poupança de IRC.';
    $cashM = $cashMonthly ?? $monthly;
    [$uBefore, $dBefore] = fin_sim_runway($pdo, 0, 0);
    [$uAfter, $dAfter] = fin_sim_runway($pdo, $cashM, $oneoff, $startIn);
    return $extra + [
        'monthly' => round($monthly, 2), 'annual' => round($monthly * 12, 2), 'oneoff' => round($oneoff, 2), 'cash_monthly' => round($cashM, 2),
        'irc_saving' => $saving, 'net_annual' => round($monthly * 12 - $saving, 2), 'lines' => $lines, 'notes' => $notes,
        'runway_before' => $uBefore, 'runway_after' => $uAfter, 'reserve_before' => $dBefore, 'reserve_after' => $dAfter,
        'extra_revenue' => round($cashM / $base['margin'], 2), 'margin' => $base['margin'], 'margin_from' => $base['margin_from'], 'profit' => $base['profit'],
    ];
}

/** A tributação autónoma de uma viatura ligeira de passageiros: taxa pelo custo de aquisição e motorização. */
function fin_car_ta_rate(PDO $pdo, string $motor, float $price, bool $phevOk): float {
    if ($motor === 'ev') {
        [$r0, $r1] = fin_pl($pdo, 'ta_ev') + [0, 0];
        return ($price <= fin_p($pdo, 'ta_ev_limit', 62500) ? $r0 : $r1) / 100;
    }
    $rates = $motor === 'phev' && $phevOk ? fin_pl($pdo, 'ta_phev') : fin_pl($pdo, 'ta_comb');
    $rates += [0, 0, 0];
    if ($price < fin_p($pdo, 'ta_limit1', 37500)) return $rates[0] / 100;
    if ($price < fin_p($pdo, 'ta_limit2', 45000)) return $rates[1] / 100;
    return $rates[2] / 100;
}

/** Uma viatura: compra (a pronto, crédito ou renting), IVA dedutível, depreciação aceite, TA e IRC, a 4 anos. */
function fin_car_calc(PDO $pdo, array $in, array $base): array {
    $num = fn($k) => (float)($in[$k] ?? 0);
    $motor = isset(FIN_CAR_MOTORS[$in['motor']]) ? $in['motor'] : 'diesel';
    $comm = $in['car_type'] === 'commercial';
    $price = $num('price');
    $vatN = fin_p($pdo, 'vat_normal', 23) / 100;
    // IVA dedutível na compra / no renting
    if ($comm) $vatPct = fin_p($pdo, 'car_vat_commercial_pct', 100) / 100;
    else $vatPct = match ($motor) {
        'ev' => $price <= fin_p($pdo, 'car_vat_ev_limit', 62500) ? fin_p($pdo, 'car_vat_ev_pct', 100) / 100 : 0.0,
        'phev' => $price <= fin_p($pdo, 'car_vat_phev_limit', 50000) ? fin_p($pdo, 'car_vat_phev_pct', 100) / 100 : 0.0,
        'gpl' => $price <= fin_p($pdo, 'car_vat_gpl_limit', 37500) ? fin_p($pdo, 'car_vat_gpl_pct', 50) / 100 : 0.0,
        default => 0.0,
    };
    $vat = $price * $vatN;
    $vatCost = $vat * (1 - $vatPct);
    $acq = $price + $vatCost;
    // energia (consumo por 100 km × km × preço com IVA; o IVA dedutível pela energia)
    [$dc, $dp] = FIN_CAR_DEFAULTS[$motor];
    $cons = $in['consumption'] !== '' && $num('consumption') > 0 ? $num('consumption') : $dc;
    $ep = $in['energy_price'] !== '' && $num('energy_price') > 0 ? $num('energy_price') : $dp;
    $energyGross = $num('km') / 100 * $cons * $ep;
    $fuelPct = match ($motor) { 'ev', 'phev' => fin_p($pdo, 'car_fuel_elec_pct', 100), 'diesel', 'gpl' => fin_p($pdo, 'car_fuel_diesel_pct', 50), default => fin_p($pdo, 'car_fuel_petrol_pct', 0) } / 100;
    $energy = $energyGross - $energyGross / (1 + $vatN) * $vatN * $fuelPct;
    $running = $energy + $num('insurance') + $num('maintenance') + $num('tolls');
    // aquisição
    $buy = isset(FIN_CAR_BUY[$in['buy']]) ? $in['buy'] : 'cash';
    $deprRate = fin_p($pdo, 'car_depr_rate', 25) / 100;
    $limit = $comm ? INF : match ($motor) { 'ev' => fin_p($pdo, 'car_depr_limit_ev', 62500), 'phev' => fin_p($pdo, 'car_depr_limit_phev', 50000), 'gpl' => fin_p($pdo, 'car_depr_limit_gpl', 37500), default => fin_p($pdo, 'car_depr_limit_comb', 25000) };
    $depr = $buy === 'renting' ? 0.0 : $acq * $deprRate;
    $deprOk = $buy === 'renting' ? 0.0 : min($acq, $limit) * $deprRate;
    $rentY = 0.0; $interestY = 0.0; $oneoff = 0.0; $cashM = 0.0;
    if ($buy === 'renting') {
        $rentNet = $num('renting');
        $rentY = ($rentNet + $rentNet * $vatN * (1 - $vatPct)) * 12;
        $cashM = $rentY / 12;
    } elseif ($buy === 'credit') {
        $down = min($num('down'), $price + $vat);
        $P = $price + $vat - $down;
        $n = max(1, (int)$num('credit_months'));
        $r = $num('credit_rate') / 100 / 12;
        $inst = $r > 0 ? $P * $r / (1 - (1 + $r) ** -$n) : $P / $n;
        $interestY = max(0, ($inst * $n - $P)) / ($n / 12);
        $oneoff = $down;
        $cashM = $inst;
    } else {
        $oneoff = $price + $vat;
    }
    $charges = $depr + $rentY + $interestY + $running;      // encargos sujeitos a TA
    $taRate = $comm ? 0.0 : fin_car_ta_rate($pdo, $motor, $price, !empty($in['phev_ok']));
    $ta = $charges * $taRate;
    $deductible = $deprOk + $rentY + $interestY + $running;  // a TA não é dedutível
    $saving = $base['profit'] > 0 ? fin_irc($pdo, $base['profit']) - fin_irc($pdo, $base['profit'] - $deductible) : 0.0;
    $yearCost = $depr + $rentY + $interestY + $running + $ta;
    $four = ($buy === 'renting' ? $rentY * 4 : $acq + $interestY * 4) + ($running + $ta) * 4 - ($buy === 'credit' ? 0 : 0);
    return ['motor' => $motor, 'vat_pct' => $vatPct, 'vat_cost' => $vatCost, 'acq' => $acq, 'energy' => $energy, 'running' => $running, 'depr' => $depr, 'depr_ok' => $deprOk,
            'rent_y' => $rentY, 'interest_y' => $interestY, 'ta_rate' => $taRate, 'ta' => $ta, 'year_cost' => $yearCost, 'saving' => $saving, 'four' => $four,
            'four_net' => $four - $saving * 4, 'oneoff' => $oneoff, 'cash_m' => $cashM + $running / 12 + $ta / 12, 'cons' => $cons, 'ep' => $ep, 'deductible' => $deductible];
}

function fin_sim_car(PDO $pdo, array $in, array $base): array {
    $c = fin_car_calc($pdo, $in, $base);
    $lines = [
        ['Preço s/ IVA', (float)$in['price'], FIN_CAR_MOTORS[$c['motor']] . ($in['car_type'] === 'commercial' ? ' · comercial' : ' · ligeiro de passageiros')],
        ['IVA dedutível na compra', round((float)$in['price'] * fin_p($pdo, 'vat_normal', 23) / 100 * $c['vat_pct'], 2), fin_num($c['vat_pct'] * 100, 0) . '% do IVA' . ($c['vat_cost'] > 0 ? ' · ' . eur($c['vat_cost']) . ' não dedutível (é custo)' : '')],
    ];
    if ($in['buy'] !== 'renting') $lines[] = ['Depreciação por ano', round($c['depr'], 2), fin_num(fin_p($pdo, 'car_depr_rate', 25)) . '%/ano · aceite em IRC ' . eur($c['depr_ok'])];
    if ($c['rent_y'] > 0) $lines[] = ['Renting por ano', round($c['rent_y'], 2), 'com o IVA não dedutível'];
    if ($c['interest_y'] > 0) $lines[] = ['Juros do crédito por ano', round($c['interest_y'], 2), ''];
    $lines[] = ['Energia por ano', round($c['energy'], 2), fin_num($c['cons'], 1) . ($c['motor'] === 'ev' ? ' kWh' : ' L') . '/100 km × ' . eur($c['ep']) . ' · já sem o IVA dedutível'];
    $lines[] = ['Seguro, manutenção e portagens por ano', round((float)$in['insurance'] + (float)$in['maintenance'] + (float)$in['tolls'], 2), ''];
    $lines[] = ['Tributação autónoma por ano', round($c['ta'], 2), fin_num($c['ta_rate'] * 100, 1) . '% sobre todos os encargos da viatura'];
    $lines[] = ['Custo a 4 anos', round($c['four'], 2), 'sem valor residual · ' . eur($c['four_net']) . ' depois da poupança de IRC'];
    // comparar motorizações (o mesmo preço e os mesmos km; consumos por omissão nas outras)
    $cmp = [];
    foreach (FIN_CAR_MOTORS as $m => $label) {
        $o = $in;
        $o['motor'] = $m;
        if ($m !== $in['motor']) { $o['consumption'] = ''; $o['energy_price'] = ''; }
        $r = fin_car_calc($pdo, $o, $base);
        $cmp[$m] = ['label' => $label, 'monthly' => round($r['year_cost'] / 12, 2), 'four' => round($r['four'], 2), 'four_net' => round($r['four_net'], 2), 'ta' => round($r['ta'], 2), 'saving' => round($r['saving'], 2)];
    }
    $notes = ['Plug-in com as taxas reduzidas de tributação autónoma só se cumprir os requisitos (Euro 6e-bis até 80 g CO₂/km, ou Euro 6 anterior com ≥ 50 km de autonomia elétrica e < 50 g CO₂/km).'];
    $monthly = $c['year_cost'] / 12;
    $res = fin_sim_result($pdo, $base, $monthly, $c['oneoff'], $c['deductible'], $lines, $notes, 0, $c['cash_m'], ['compare' => $cmp]);
    $res['irc_saving'] = round($c['saving'], 2);
    $res['net_annual'] = round($c['year_cost'] - $c['saving'], 2);
    return $res;
}

// ── cenários guardados ──────────────────────────────────────

function fin_scenarios(PDO $pdo): array {
    return $pdo->query('SELECT s.*, u.name AS who FROM fin_scenarios s LEFT JOIN users u ON u.id = s.created_by WHERE s.deleted_at IS NULL ORDER BY s.id DESC LIMIT 50')->fetchAll();
}

function fin_scenario(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM fin_scenarios WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function fin_scenario_save(PDO $pdo, string $kind, array $in, ?int $by): int {
    if (!isset(FIN_SIM_KINDS[$kind])) throw new RuntimeException('Tipo inválido.');
    $r = fin_sim($pdo, $kind, $in);
    $name = trim((string)($in['name'] ?? '')) ?: FIN_SIM_KINDS[$kind] . ' · ' . date('d/m H:i');
    $pdo->prepare('INSERT INTO fin_scenarios (kind, name, inputs, result, created_by, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$kind, mb_substr($name, 0, 160), json_encode($in, JSON_UNESCAPED_UNICODE), json_encode(array_diff_key($r, ['lines' => 1, 'compare' => 1]), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $by, fin_now()]);
    return (int)$pdo->lastInsertId();
}

/**
 * «Aplicar»: o cenário vira custo fixo previsto (contratar → «Salário previsto», arrendar → renda e despesas, investir
 * mensal → subscrição, viatura → renting/prestação e custos de uso). Devolve os ids dos custos fixos criados.
 */
function fin_scenario_apply(PDO $pdo, array $s, ?int $by): array {
    if ($s['applied_at']) throw new RuntimeException('Esse cenário já foi aplicado.');
    $in = json_decode((string)$s['inputs'], true) ?: [];
    $r = fin_sim($pdo, (string)$s['kind'], $in);
    $start = isset($in['start']) && fin_date_ok((string)$in['start']) ? (string)$in['start'] : date('Y-m-01', strtotime('first day of next month'));
    $rows = [];
    $mk = fn(string $name, string $cat, float $amount, float $vat = 0.0, bool $ded = true, float $wh = 0.0) => ['name' => mb_substr($name, 0, 160), 'category' => $cat, 'party_name' => '', 'party_nif' => '',
        'amount' => round($amount, 2), 'vat_rate' => $vat, 'vat_deductible' => $ded ? 1 : 0, 'wh_rate' => $wh, 'period_months' => 1, 'due_day' => 1, 'start_date' => $start, 'end_date' => null,
        'pay_method' => 'transfer', 'active' => 1, 'notes' => 'Do simulador: ' . $s['name']];
    switch ($s['kind']) {
        case 'hire':   $rows[] = $mk('Salário previsto · ' . $s['name'], 'Salários', $r['monthly']); break;
        case 'office':
            $rows[] = $mk('Renda do escritório', 'Renda', (float)$in['rent'], (int)($in['landlord_vat'] ?? 0) ? fin_p($pdo, 'vat_normal', 23) : 0, true, (int)($in['landlord_wh'] ?? 0) ? fin_p($pdo, 'wh_rent', 25) : 0);
            if ((float)$in['expenses'] > 0) $rows[] = $mk('Despesas do escritório', 'Outros', (float)$in['expenses'], fin_p($pdo, 'vat_normal', 23));
            break;
        case 'invest':
            if (($in['mode'] ?? '') !== 'monthly') throw new RuntimeException('Um investimento de uma vez não é custo fixo: lance-o em «A pagar» quando chegar a fatura.');
            $rows[] = $mk($s['name'], ($in['type'] ?? '') === 'service' ? 'Software' : 'Equipamento', (float)$in['value'], fin_p($pdo, 'vat_normal', 23), (int)($in['vat_deductible'] ?? 1) === 1);
            break;
        case 'car':
            $rows[] = $mk('Viatura · ' . $s['name'], 'Viaturas', $r['cash_monthly']);
            break;
    }
    $ids = [];
    foreach ($rows as $v) {
        $v['created_by'] = $by; $v['created_at'] = fin_now(); $v['updated_at'] = fin_now();
        $cols = array_keys($v);
        $pdo->prepare('INSERT INTO fin_fixed (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($v));
        $ids[] = (int)$pdo->lastInsertId();
    }
    $pdo->prepare('UPDATE fin_scenarios SET applied_at = ? WHERE id = ?')->execute([fin_now(), (int)$s['id']]);
    return $ids;
}
