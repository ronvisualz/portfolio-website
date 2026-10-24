<?php
/**
 * topper. admin — Financeiro: salários (§28.6)
 *
 * A ficha de salário de cada colaborador (fin_employees, só para quem tem Financeiro) e o processamento
 * do mês: bruto, Segurança Social (trabalhador e TSU), IRS pelas tabelas guardadas (fin_irs — Despacho
 * n.º 233-A/2026 no continente), subsídio de refeição (isento até ao limite por dia, dinheiro ou cartão),
 * subsídios de férias e de Natal retidos autonomamente (ou em duodécimos), seguro de acidentes de trabalho
 * e FCT. Todas as taxas vêm dos parâmetros (fin_p). «Emitir» grava o recibo (PDF) e os lançamentos:
 * líquido ao colaborador, IRS retido e Segurança Social ao Estado.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin.php';

const FIN_IRS_STATUS = ['single' => 'Não casado', 'married1' => 'Casado, único titular', 'married2' => 'Casado, dois titulares'];
const FIN_RESIDENCE = ['continente' => 'Continente', 'acores' => 'Açores', 'madeira' => 'Madeira'];
const FIN_CONTRACTS = ['' => '—', 'sem_termo' => 'Sem termo', 'termo_certo' => 'A termo certo', 'termo_incerto' => 'A termo incerto', 'estagio' => 'Estágio', 'outro' => 'Outro'];

/** As fichas de salário, com o nome e o estado da conta. */
function fin_employees(PDO $pdo): array {
    if (!fin_ready($pdo)) return [];
    return $pdo->query('SELECT e.*, u.name, u.email, u.role, u.is_active AS user_active, u.job_title FROM fin_employees e JOIN users u ON u.id = e.user_id ORDER BY e.active DESC, u.name')->fetchAll();
}

function fin_employee(PDO $pdo, int $uid): ?array {
    if (!fin_ready($pdo)) return null;
    $st = $pdo->prepare('SELECT e.*, u.name, u.email, u.role, u.is_active AS user_active, u.job_title FROM fin_employees e JOIN users u ON u.id = e.user_id WHERE e.user_id = ?');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}

/** Os campos da ficha vindos do formulário. */
function fin_employee_input(array $src): array {
    $base = fin_money($src['base_salary'] ?? 0);
    if ($base < 0) throw new RuntimeException('Vencimento inválido.');
    $meal = fin_money($src['meal_value'] ?? 0);
    $days = (int)($src['meal_days'] ?? 22);
    if ($days < 0 || $days > 31) throw new RuntimeException('Dias de subsídio de refeição: de 0 a 31.');
    $start = (string)($src['start_date'] ?? '');
    if ($start !== '' && !fin_date_ok($start)) throw new RuntimeException('Data de entrada inválida.');
    $iban = strtoupper(preg_replace('/\s+/', '', (string)($src['iban'] ?? '')));
    if ($iban !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) throw new RuntimeException('IBAN inválido.');
    $nif = preg_replace('/\D/', '', (string)($src['nif'] ?? ''));
    if ($nif !== '' && strlen($nif) !== 9) throw new RuntimeException('O NIF tem 9 algarismos.');
    $niss = preg_replace('/\D/', '', (string)($src['niss'] ?? ''));
    if ($niss !== '' && strlen($niss) !== 11) throw new RuntimeException('O NISS tem 11 algarismos.');
    $deps = max(0, min(20, (int)($src['dependents'] ?? 0)));
    return [
        'base_salary' => $base, 'meal_value' => $meal, 'meal_type' => ($src['meal_type'] ?? '') === 'cash' ? 'cash' : 'card', 'meal_days' => $days,
        'irs_status' => isset(FIN_IRS_STATUS[$src['irs_status'] ?? '']) ? (string)$src['irs_status'] : 'single',
        'dependents' => $deps, 'dep_disabled' => max(0, min($deps, (int)($src['dep_disabled'] ?? 0))), 'disability' => !empty($src['disability']) ? 1 : 0,
        'residence' => isset(FIN_RESIDENCE[$src['residence'] ?? '']) ? (string)$src['residence'] : 'continente', 'duodecimos' => !empty($src['duodecimos']) ? 1 : 0,
        'iban' => $iban, 'nif' => $nif, 'niss' => $niss, 'contract_type' => isset(FIN_CONTRACTS[$src['contract_type'] ?? '']) ? (string)($src['contract_type'] ?? '') : '',
        'start_date' => $start !== '' ? $start : null, 'active' => !isset($src['active']) || !empty($src['active']) ? 1 : 0,
    ];
}

/** Grava a ficha; devolve [o que havia (ou null), se é nova]. */
function fin_employee_save(PDO $pdo, int $uid, array $v, ?int $by): array {
    $old = fin_employee($pdo, $uid);
    $v['updated_by'] = $by; $v['updated_at'] = fin_now();
    if ($old) {
        $pdo->prepare('UPDATE fin_employees SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($v))) . ' WHERE user_id = ?')->execute([...array_values($v), $uid]);
    } else {
        $cols = array_keys($v);
        $pdo->prepare('INSERT INTO fin_employees (user_id, ' . implode(', ', $cols) . ') VALUES (?, ' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute([$uid, ...array_values($v)]);
    }
    if ($old) foreach (['name', 'email', 'role', 'user_active', 'job_title'] as $k) unset($old[$k]);
    return [$old, !$old];
}

/** Avisos da ficha: abaixo do salário mínimo, dados em falta, região sem tabelas. */
function fin_employee_warnings(PDO $pdo, array $e): array {
    $w = [];
    $min = fin_p($pdo, 'min_wage', 0);
    if ((float)$e['base_salary'] > 0 && $min > 0 && (float)$e['base_salary'] < $min) $w[] = 'Vencimento abaixo do salário mínimo (' . eur($min) . ').';
    if ((float)$e['base_salary'] <= 0) $w[] = 'Falta o vencimento base.';
    $miss = array_keys(array_filter(['NIF' => $e['nif'], 'NISS' => $e['niss'], 'IBAN' => $e['iban']], fn($v) => (string)$v === ''));
    if ($miss) $w[] = 'Faltam ' . implode(', ', $miss) . '.';
    if (($e['residence'] ?? 'continente') !== 'continente') $w[] = 'Residência em ' . FIN_RESIDENCE[$e['residence']] . ': as tabelas carregadas são as do continente — confirme a retenção com o contabilista.';
    return $w;
}

// ── IRS ─────────────────────────────────────────────────────

/** A tabela de retenção: I a III sem deficiência; IV a VII com deficiência (Despacho, n.º 1). */
function fin_irs_table(array $e): string {
    $deps = (int)$e['dependents'] > 0;
    $st = (string)$e['irs_status'];
    if ((int)$e['disability']) {
        if ($st === 'married1') return 'VII';
        if ($st === 'married2') return $deps ? 'VI' : 'IV';
        return $deps ? 'V' : 'IV';
    }
    if ($st === 'married1') return 'III';
    if ($st === 'married2') return 'I';
    return $deps ? 'II' : 'I';
}

function fin_irs_rows(PDO $pdo, int $year, string $tbl, string $region = 'continente'): array {
    $st = $pdo->prepare('SELECT * FROM fin_irs WHERE year = ? AND region = ? AND tbl = ? ORDER BY position, id');
    $st->execute([$year, $region, $tbl]);
    return $st->fetchAll();
}

/** Os anos com tabelas carregadas. */
function fin_irs_years(PDO $pdo): array {
    return array_map('intval', $pdo->query('SELECT DISTINCT year FROM fin_irs ORDER BY year DESC')->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * A retenção de uma remuneração mensal R (Despacho n.º 233-A/2026, n.os 3 e 5):
 * R × taxa − parcela a abater (fixa, ou taxa × k × (base − R)) − parcela por dependente × n.º
 * − o adicional por dependente com incapacidade ≥ 60 %; com 3 ou mais dependentes a taxa desce
 * (parâmetro, 1 p.p.) e a parcela fica igual. Nunca abaixo de zero.
 */
function fin_irs_calc(PDO $pdo, array $e, float $R): array {
    $year = (int)fin_p($pdo, 'irs_table_year', (float)date('Y'));
    $tbl = fin_irs_table($e);
    $rows = fin_irs_rows($pdo, $year, $tbl);
    if (!$rows) throw new RuntimeException('Não há tabelas de retenção de ' . $year . ' (tabela ' . $tbl . ') — carregue-as em Definições › Financeiro.');
    $row = end($rows);
    foreach ($rows as $r) { if ($r['upto'] === null || $R <= (float)$r['upto'] + 0.004) { $row = $r; break; } }
    $rate = (float)$row['rate'];
    $deps = (int)$e['dependents'];
    $rateUsed = $rate;
    if ($deps >= 3 && $rate > 0) $rateUsed = max(0.0, $rate - fin_p($pdo, 'irs_three_dep_cut', 1));
    $deduct = $row['deduct_k'] !== null ? $rate / 100 * (float)$row['deduct_k'] * ((float)$row['deduct_base'] - $R) : (float)$row['deduct'];
    $depDeduct = (float)$row['dep_deduct'] * $deps;
    if ((int)$e['dep_disabled'] > 0 && $rate > 0) {
        $per = $e['irs_status'] === 'married2' ? fin_p($pdo, 'irs_dep_disabled_two', 0) : fin_p($pdo, 'irs_dep_disabled_single', 0);
        $depDeduct += $per * (int)$e['dep_disabled'];
    }
    $value = $rate > 0 ? max(0.0, round($R * $rateUsed / 100 - $deduct - $depDeduct, 2)) : 0.0;
    return ['year' => $year, 'table' => $tbl, 'rate' => $rateUsed, 'deduct' => round($deduct, 2), 'dep' => round($depDeduct, 2), 'value' => $value,
            'eff' => $R > 0 ? $value / $R : 0.0, 'upto' => $row['upto']];
}

// ── o mês ───────────────────────────────────────────────────

/**
 * O processamento de um mês. Devolve as linhas do colaborador e da empresa, os totais e os avisos:
 * gross (remunerações), meal, meal_taxable, irs, ss_employee, ss_employer, insurance, fct, net, company_cost.
 */
function fin_payroll_calc(PDO $pdo, array $e, int $y, int $m): array {
    $warn = fin_employee_warnings($pdo, $e);
    $first = sprintf('%04d-%02d-01', $y, $m);
    $last = date('Y-m-t', strtotime($first));
    $dim = (int)date('t', strtotime($first));
    if ($e['start_date'] && $e['start_date'] > $last) throw new RuntimeException($e['name'] . ' só entra a ' . fin_date_pt($e['start_date']) . '.');
    $prop = 1.0;
    if ($e['start_date'] && $e['start_date'] > $first) {
        $prop = ($dim - (int)substr($e['start_date'], 8, 2) + 1) / $dim;
        $warn[] = 'Entrou a ' . fin_date_pt($e['start_date']) . ': vencimento proporcional (' . fin_num($prop * 100, 0) . '% do mês). No primeiro ano, os subsídios são proporcionais ao tempo de serviço — confirme com o contabilista.';
    }
    $base = round((float)$e['base_salary'] * $prop, 2);
    $full = (float)$e['base_salary'];
    $holM = (int)fin_p($pdo, 'holiday_month', 6); $xmasM = (int)fin_p($pdo, 'xmas_month', 11);
    $duo = (int)$e['duodecimos'] === 1;
    $hol = $duo ? round($full / 12, 2) : ($m === $holM ? $full : 0.0);
    $xmas = $duo ? round($full / 12, 2) : ($m === $xmasM ? $full : 0.0);

    $mealDays = (int)round((int)$e['meal_days'] * $prop);
    $mealVal = (float)$e['meal_value'];
    $exempt = $e['meal_type'] === 'cash' ? fin_p($pdo, 'meal_cash_exempt', 0) : fin_p($pdo, 'meal_card_exempt', 0);
    $meal = round($mealVal * $mealDays, 2);
    $mealTax = round(max(0, $mealVal - $exempt) * $mealDays, 2);

    // IRS: a remuneração do mês (vencimento + excesso da refeição); os subsídios à parte (autónomos) ou, em
    // duodécimos, com a taxa efetiva da remuneração do mês (Despacho, n.º 10)
    $R = $base + $mealTax;
    $irsMain = fin_irs_calc($pdo, $e, $R);
    $irs = $irsMain['value'];
    $irsLines = [['Remuneração do mês', $R, $irsMain]];
    foreach (['Subsídio de férias' => $hol, 'Subsídio de Natal' => $xmas] as $label => $s) {
        if ($s <= 0) continue;
        if ($duo) {
            $v = round($s * $irsMain['eff'], 2);
            $irsLines[] = [$label . ' (duodécimo)', $s, ['value' => $v, 'rate' => $irsMain['eff'] * 100, 'table' => $irsMain['table'], 'deduct' => 0, 'dep' => 0]];
        } else {
            $c = fin_irs_calc($pdo, $e, $s);
            $v = $c['value'];
            $irsLines[] = [$label . ' (retenção autónoma)', $s, $c];
        }
        $irs += $v;
    }
    $irs = round($irs, 2);

    $gross = round($base + $hol + $xmas, 2);
    $ssBase = round($gross + $mealTax, 2);
    $ssE = round($ssBase * fin_p($pdo, 'ss_employee', 11) / 100, 2);
    $ssC = round($ssBase * fin_p($pdo, 'ss_employer', 23.75) / 100, 2);
    $ins = round($gross * fin_p($pdo, 'accident_ins_rate', 0) / 100, 2);
    $fct = round($gross * fin_p($pdo, 'fct_rate', 0) / 100, 2);
    if (fin_p($pdo, 'accident_ins_rate', 0) <= 0) $warn[] = 'Sem taxa do seguro de acidentes de trabalho (obrigatório): ponha a da apólice em Definições › Financeiro.';
    $net = round($gross + $meal - $ssE - $irs, 2);
    $cost = round($gross + $meal + $ssC + $ins + $fct, 2);

    $lines = [['Vencimento base' . ($prop < 1 ? ' (proporcional)' : ''), $base, '']];
    if ($hol > 0) $lines[] = ['Subsídio de férias' . ($duo ? ' (duodécimo)' : ''), $hol, ''];
    if ($xmas > 0) $lines[] = ['Subsídio de Natal' . ($duo ? ' (duodécimo)' : ''), $xmas, ''];
    if ($meal > 0) $lines[] = ['Subsídio de refeição (' . $mealDays . ' dias × ' . eur($mealVal) . ', ' . ($e['meal_type'] === 'cash' ? 'dinheiro' : 'cartão') . ')', $meal,
                               $mealTax > 0 ? 'tributado ' . eur($mealTax) . ' (acima de ' . eur($exempt) . '/dia)' : 'isento'];
    return [
        'y' => $y, 'm' => $m, 'lines' => $lines, 'irs_lines' => $irsLines, 'table' => $irsMain['table'], 'irs_year' => $irsMain['year'],
        'base' => $base, 'holiday' => $hol, 'xmas' => $xmas, 'meal' => $meal, 'meal_taxable' => $mealTax, 'meal_days' => $mealDays,
        'gross' => $gross, 'ss_base' => $ssBase, 'irs' => $irs, 'ss_employee' => $ssE, 'ss_employer' => $ssC, 'insurance' => $ins, 'fct' => $fct,
        'net' => $net, 'company_cost' => $cost, 'warnings' => $warn,
        'rates' => ['ss_employee' => fin_p($pdo, 'ss_employee', 11), 'ss_employer' => fin_p($pdo, 'ss_employer', 23.75), 'ins' => fin_p($pdo, 'accident_ins_rate', 0), 'fct' => fin_p($pdo, 'fct_rate', 0)],
    ];
}

/** O custo anual (14 × bruto × (1 + TSU) + refeição de 11 meses + seguro e FCT) e o mensal (÷ 12). */
function fin_employee_annual(PDO $pdo, array $e): array {
    $b = (float)$e['base_salary'];
    $tsu = fin_p($pdo, 'ss_employer', 23.75) / 100;
    $meal = (float)$e['meal_value'] * (int)$e['meal_days'] * 11;
    $ins = 14 * $b * (fin_p($pdo, 'accident_ins_rate', 0) + fin_p($pdo, 'fct_rate', 0)) / 100;
    $annual = round(14 * $b * (1 + $tsu) + $meal + $ins, 2);
    return ['annual' => $annual, 'monthly' => round($annual / 12, 2), 'meal' => round($meal, 2), 'insurance' => round($ins, 2)];
}

// ── emitir ──────────────────────────────────────────────────

function fin_payroll_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT p.*, u.name, u.email FROM fin_payroll p JOIN users u ON u.id = p.user_id WHERE p.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Os recibos de um mês (ou de uma pessoa, para o Perfil). */
function fin_payroll_list(PDO $pdo, ?int $y = null, ?int $m = null, ?int $uid = null): array {
    $w = []; $a = [];
    if ($y) { $w[] = 'p.year = ?'; $a[] = $y; }
    if ($m) { $w[] = 'p.month = ?'; $a[] = $m; }
    if ($uid) { $w[] = 'p.user_id = ? AND p.cancelled_at IS NULL'; $a[] = $uid; }
    $st = $pdo->prepare('SELECT p.*, u.name FROM fin_payroll p JOIN users u ON u.id = p.user_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY p.year DESC, p.month DESC, u.name');
    $st->execute($a);
    return $st->fetchAll();
}

/**
 * «Emitir» o mês de uma pessoa: grava o processamento, o recibo em PDF (Drive, Financeiro/AAAA/MM — só quem tem
 * Financeiro o vê lá; a pessoa vê o seu no Perfil) e os lançamentos a pagar: líquido (fim do mês), IRS retido
 * e Segurança Social (dia de pagamento do mês seguinte, parâmetros). Devolve o id.
 */
function fin_payroll_issue(PDO $pdo, int $uid, int $y, int $m, ?int $by): int {
    $e = fin_employee($pdo, $uid);
    if (!$e) throw new RuntimeException('Sem ficha de salário.');
    if (!(int)$e['active']) throw new RuntimeException($e['name'] . ': ficha inativa.');
    $st = $pdo->prepare('SELECT id FROM fin_payroll WHERE user_id = ? AND year = ? AND month = ? AND cancelled_at IS NULL');
    $st->execute([$uid, $y, $m]);
    if ($st->fetchColumn()) throw new RuntimeException($e['name'] . ': ' . month_year($m, $y) . ' já foi emitido (anule-o para voltar a emitir).');
    $c = fin_payroll_calc($pdo, $e, $y, $m);
    $now = fin_now();
    $pdo->prepare('INSERT INTO fin_payroll (user_id, year, month, status, gross, irs, ss_employee, ss_employer, net, company_cost, data, created_by, created_at)
                   VALUES (?,?,?,\'issued\',?,?,?,?,?,?,?,?,?)')
        ->execute([$uid, $y, $m, $c['gross'], $c['irs'], $c['ss_employee'], $c['ss_employer'], $c['net'], $c['company_cost'],
                   json_encode(['calc' => $c, 'emp' => array_intersect_key($e, array_flip(['name', 'nif', 'niss', 'iban', 'irs_status', 'dependents', 'disability', 'meal_type', 'contract_type', 'start_date', 'job_title']))],
                               JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $by, $now]);
    $pid = (int)$pdo->lastInsertId();
    $first = sprintf('%04d-%02d-01', $y, $m);
    $last = date('Y-m-t', strtotime($first));
    $next = date('Y-m', strtotime($first . ' +1 month'));
    $period = month_year($m, $y);
    // o recibo
    $pdfKey = '';
    try {
        $bytes = fin_payslip_pdf($pdo, fin_payroll_get($pdo, $pid));
        [$pdfKey] = fin_file_store($pdo, $bytes, sprintf('%04d-%02d_Recibo-vencimento_%s.pdf', $y, $m, preg_replace('/[^\p{L}\p{N}]+/u', '-', (string)$e['name'])), 'application/pdf', ['issue_date' => $last, 'deal_id' => null], 'attach');
        $pdo->prepare('UPDATE fin_payroll SET pdf_key = ? WHERE id = ?')->execute([$pdfKey, $pid]);
    } catch (Throwable $ex) { error_log('payslip pdf: ' . $ex->getMessage()); }
    // os lançamentos
    $mk = function (array $v) use ($pdo, $pid, $by, $last) {
        fin_doc_create($pdo, $v + ['direction' => 'out', 'scope' => 'company', 'issue_date' => $last, 'payroll_id' => $pid, 'status' => 'to_pay', 'vat_deductible' => 1], $by);
    };
    $mk(['doc_type' => 'salary', 'party_kind' => 'employee', 'party_name' => (string)$e['name'], 'party_nif' => (string)$e['nif'], 'category' => 'Salários',
         'description' => 'Vencimento ' . $period . ' (líquido)', 'base' => $c['net'], 'total' => $c['net'], 'due_date' => $last]);
    if ($c['irs'] > 0) $mk(['doc_type' => 'tax', 'party_kind' => 'state', 'party_name' => 'Autoridade Tributária', 'category' => 'Salários',
         'description' => 'IRS retido · ' . $e['name'] . ' · ' . $period, 'base' => $c['irs'], 'total' => $c['irs'], 'due_date' => $next . '-' . sprintf('%02d', (int)fin_p($pdo, 'cal_irs_pay', 20))]);
    $ss = round($c['ss_employee'] + $c['ss_employer'], 2);
    if ($ss > 0) $mk(['doc_type' => 'tax', 'party_kind' => 'state', 'party_name' => 'Segurança Social', 'category' => 'Salários',
         'description' => 'Segurança Social (' . fin_num($c['rates']['ss_employee'] + $c['rates']['ss_employer']) . '%) · ' . $e['name'] . ' · ' . $period, 'base' => $ss, 'total' => $ss,
         'due_date' => $next . '-' . sprintf('%02d', (int)fin_p($pdo, 'cal_ss_pay', 20))]);
    if ($c['insurance'] + $c['fct'] > 0) $mk(['doc_type' => 'expense', 'party_kind' => 'other', 'party_name' => 'Seguro de acidentes de trabalho / FCT', 'category' => 'Seguros',
         'description' => 'Seguro de acidentes de trabalho e FCT · ' . $e['name'] . ' · ' . $period, 'base' => round($c['insurance'] + $c['fct'], 2), 'total' => round($c['insurance'] + $c['fct'], 2), 'due_date' => $last]);
    fin_payslip_notify($pdo, $pid);
    return $pid;
}

/** Avisa a pessoa: com a caixa das faturas configurada, o PDF vai anexado; senão, um aviso com o link do Perfil. */
function fin_payslip_notify(PDO $pdo, int $pid): void {
    $p = fin_payroll_get($pdo, $pid);
    if (!$p || (string)$p['email'] === '') return;
    $subject = 'topper. — recibo de vencimento de ' . month_year((int)$p['month'], (int)$p['year']);
    $body = 'Olá ' . first_name_safe((string)$p['name']) . ",\n\nO seu recibo de vencimento de " . month_year((int)$p['month'], (int)$p['year']) . ' está disponível no seu Perfil:'
          . "\n" . fin_base_url() . "/admin/conta.php#recibos\n\nLíquido: " . eur((float)$p['net']) . "\n";
    $sent = false;
    require_once __DIR__ . '/fin_mail.php';
    if (fin_mail_config() && $p['pdf_key'] !== '' && ($bytes = fin_file_bytes((string)$p['pdf_key'])) !== null) {
        try {
            fin_smtp_send((string)$p['email'], $subject, $body, [['name' => sprintf('Recibo-%04d-%02d.pdf', $p['year'], $p['month']), 'mime' => 'application/pdf', 'bytes' => $bytes]]);
            $sent = true;
        } catch (Throwable $e) { error_log('payslip mail: ' . $e->getMessage()); }
    }
    if (!$sent && function_exists('team_mail')) $sent = team_mail((string)$p['email'], $subject, $body);
    if ($sent) $pdo->prepare('UPDATE fin_payroll SET emailed_at = ? WHERE id = ?')->execute([fin_now(), $pid]);
}

function first_name_safe(string $n): string {
    $p = preg_split('/\s+/', trim($n));
    return $p[0] ?? $n;
}

/** O endereço público da plataforma (para os links nos emails). */
function fin_base_url(): string {
    $h = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($h !== '' && preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $h)) return ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $h;
    return 'https://topperdigital.xyz';
}

/** Anular um processamento: os lançamentos por pagar ficam anulados (se algum já foi pago, não deixa). */
function fin_payroll_cancel(PDO $pdo, int $id): array {
    $p = fin_payroll_get($pdo, $id);
    if (!$p || $p['cancelled_at']) throw new RuntimeException('Esse processamento já não está emitido.');
    $docs = $pdo->query('SELECT * FROM fin_docs WHERE payroll_id = ' . $id . ' AND deleted_at IS NULL')->fetchAll();
    foreach ($docs as $d) if ((float)$d['paid_amount'] > 0) throw new RuntimeException('Já há pagamentos registados (' . $d['description'] . '): anule-os primeiro.');
    $pdo->prepare("UPDATE fin_payroll SET cancelled_at = ?, status = 'cancelled' WHERE id = ?")->execute([fin_now(), $id]);
    $pdo->prepare("UPDATE fin_docs SET status = 'void', updated_at = ? WHERE payroll_id = ? AND deleted_at IS NULL")->execute([fin_now(), $id]);
    return $p;
}

function fin_payroll_undo(PDO $pdo, array $d): string {
    if ($d['what'] === 'issue') { fin_payroll_cancel($pdo, (int)$d['id']); return 'Processamento anulado.'; }
    // repor um anulado (se entretanto não se emitiu outro para o mesmo mês)
    $p = fin_payroll_get($pdo, (int)$d['id']);
    if (!$p) throw new RuntimeException('Já não existe.');
    $st = $pdo->prepare('SELECT COUNT(*) FROM fin_payroll WHERE user_id = ? AND year = ? AND month = ? AND cancelled_at IS NULL');
    $st->execute([$p['user_id'], $p['year'], $p['month']]);
    if ((int)$st->fetchColumn()) throw new RuntimeException('Entretanto foi emitido outro para o mesmo mês.');
    $pdo->prepare("UPDATE fin_payroll SET cancelled_at = NULL, status = 'issued' WHERE id = ?")->execute([(int)$d['id']]);
    $pdo->prepare("UPDATE fin_docs SET status = 'to_pay', updated_at = ? WHERE payroll_id = ? AND deleted_at IS NULL")->execute([fin_now(), (int)$d['id']]);
    foreach ($pdo->query('SELECT id FROM fin_docs WHERE payroll_id = ' . (int)$d['id'])->fetchAll(PDO::FETCH_COLUMN) as $id) fin_doc_refresh($pdo, (int)$id);
    return 'Processamento reposto.';
}

// ── recibo em PDF ───────────────────────────────────────────

function fin_payslip_pdf(PDO $pdo, array $p): string {
    require_once __DIR__ . '/budget_pdf.php';
    $data = json_decode((string)$p['data'], true) ?: [];
    $c = $data['calc'] ?? [];
    $e = $data['emp'] ?? [];
    $pdf = new BudgetPdf();
    $L = 56.0; $R = BudgetPdf::W - 56.0;
    $ref = month_year((int)$p['month'], (int)$p['year']);
    budget_pdf_sheet($pdf, $pdo, 'RECIBO DE VENCIMENTO', $ref);
    $co = trim((string)setting($pdo, 'company_name', 'topper.')) ?: 'topper.';
    $y = 118.0;
    $pdf->text($L, $y, (string)$p['name'], 18, true); $y += 20;
    $meta = array_filter([(string)($e['job_title'] ?? ''), ($e['nif'] ?? '') !== '' ? 'NIF ' . $e['nif'] : '', ($e['niss'] ?? '') !== '' ? 'NISS ' . $e['niss'] : '']);
    if ($meta) { $pdf->text($L, $y, implode('   ·   ', $meta), 9.5, false, '#5C5C5C'); $y += 14; }
    $pdf->text($L, $y, 'Entidade: ' . $co . (($nif = (string)setting($pdo, 'company_nif', '')) !== '' ? ' · NIF ' . $nif : '') . '   ·   Período: ' . $ref, 9.5, false, '#5C5C5C');
    $y += 14;
    $pdf->text($L, $y, 'Situação IRS: ' . (FIN_IRS_STATUS[$e['irs_status'] ?? 'single'] ?? '') . ' · ' . (int)($e['dependents'] ?? 0) . ' dependente(s) · tabela ' . ($c['table'] ?? '') . ' (' . ($c['irs_year'] ?? '') . ')', 9.5, false, '#5C5C5C');
    $y += 28;
    $row = function (string $a, string $b, string $v, bool $bold = false) use ($pdf, $L, $R, &$y) {
        $pdf->text($L, $y, $a, 10, $bold);
        if ($b !== '') $pdf->text($L + 300, $y, $b, 8.5, false, '#767676');
        $pdf->text($R, $y, $v, 10, $bold, '#111111', 'r');
        $y += 17;
    };
    $pdf->text($L, $y, 'ABONOS', 8.5, true, '#5C5C5C'); $y += 6; $pdf->line($L, $y, $R, $y); $y += 14;
    foreach ($c['lines'] ?? [] as [$a, $v, $note]) $row($a, (string)$note, eur((float)$v));
    $y += 6;
    $pdf->text($L, $y, 'DESCONTOS', 8.5, true, '#5C5C5C'); $y += 6; $pdf->line($L, $y, $R, $y); $y += 14;
    $row('Segurança Social (' . fin_num((float)($c['rates']['ss_employee'] ?? 11)) . '% de ' . eur((float)($c['ss_base'] ?? 0)) . ')', '', '−' . eur((float)$p['ss_employee']));
    foreach ($c['irs_lines'] ?? [] as [$a, $base, $calc]) {
        $row('IRS · ' . $a, 'sobre ' . eur((float)$base) . ' · ' . fin_num((float)($calc['rate'] ?? 0)) . '%', '−' . eur((float)($calc['value'] ?? 0)));
    }
    $y += 4; $pdf->line($L, $y, $R, $y, '#111111', 0.8); $y += 18;
    $row('Líquido a receber', '', eur((float)$p['net']), true);
    if (($e['iban'] ?? '') !== '') { $pdf->text($L, $y, 'Pago por transferência para ' . $e['iban'], 9, false, '#5C5C5C'); $y += 14; }
    $y += 18;
    $pdf->text($L, $y, 'Encargos da entidade (informativo): TSU ' . eur((float)$p['ss_employer']) . ' · seguro e FCT ' . eur((float)(($c['insurance'] ?? 0) + ($c['fct'] ?? 0))) . ' · custo total ' . eur((float)$p['company_cost']), 8.5, false, '#767676');
    $pdf->page();
    return $pdf->output('Recibo de vencimento ' . $ref . ' · ' . $p['name']);
}

/** O resumo do mês para o contabilista, em CSV (;). */
function fin_payroll_csv(PDO $pdo, int $y, int $m): string {
    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Colaborador', 'NIF', 'NISS', 'Ano', 'Mês', 'Bruto', 'Subsídio de refeição', 'Refeição tributada', 'Base SS', 'SS trabalhador', 'TSU empresa', 'IRS retido', 'Seguro e FCT', 'Líquido', 'Custo empresa', 'Estado'], ';');
    foreach (fin_payroll_list($pdo, $y, $m) as $p) {
        $d = json_decode((string)$p['data'], true) ?: [];
        $c = $d['calc'] ?? []; $e = $d['emp'] ?? [];
        $n = fn($v) => number_format((float)$v, 2, ',', '');
        fputcsv($out, [$p['name'], $e['nif'] ?? '', $e['niss'] ?? '', $p['year'], $p['month'], $n($p['gross']), $n($c['meal'] ?? 0), $n($c['meal_taxable'] ?? 0), $n($c['ss_base'] ?? 0),
                       $n($p['ss_employee']), $n($p['ss_employer']), $n($p['irs']), $n(($c['insurance'] ?? 0) + ($c['fct'] ?? 0)), $n($p['net']), $n($p['company_cost']),
                       $p['cancelled_at'] ? 'Anulado' : 'Emitido'], ';');
    }
    rewind($out);
    return (string)stream_get_contents($out);
}

/**
 * Importa tabelas de retenção (Definições › Financeiro): linhas «tabela;até;taxa;parcela;k;base;por dependente».
 * Substitui as tabelas desse ano e região que vierem no texto. Devolve o número de linhas.
 */
function fin_irs_import(PDO $pdo, int $year, string $region, string $raw, string $source): int {
    if (!is_admin()) throw new RuntimeException('Só superadmin e admins.');
    if ($year < 2020 || $year > 2100) throw new RuntimeException('Ano inválido.');
    if (!isset(FIN_RESIDENCE[$region])) throw new RuntimeException('Região inválida.');
    if ($source === '') throw new RuntimeException('Indique a fonte (o despacho).');
    $num = function ($v) {
        $v = str_replace([' ', '€', '%'], '', trim((string)$v));
        if ($v === '') return null;
        if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);   // 1.234,56 → 1234.56
        return is_numeric($v) ? (float)$v : false;
    };
    $rows = [];
    foreach (preg_split('/\r?\n/', trim($raw)) as $i => $l) {
        if (trim($l) === '') continue;
        $c = array_map('trim', explode(';', $l));
        $t = strtoupper($c[0] ?? '');
        if (!in_array($t, ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII'], true)) throw new RuntimeException('Linha ' . ($i + 1) . ': tabela «' . $t . '» desconhecida.');
        $vals = [];
        foreach ([1, 2, 3, 4, 5, 6] as $k) { $v = $num($c[$k] ?? ''); if ($v === false) throw new RuntimeException('Linha ' . ($i + 1) . ': número inválido.'); $vals[] = $v; }
        [$upto, $rate, $ded, $k, $base, $dep] = $vals;
        if ($rate === null || $rate < 0 || $rate > 60) throw new RuntimeException('Linha ' . ($i + 1) . ': taxa inválida.');
        $rows[$t][] = [$upto, $rate, $ded ?? 0, $k, $base, $dep ?? 0];
    }
    if (!$rows) throw new RuntimeException('Sem linhas.');
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM fin_irs WHERE year = ? AND region = ? AND tbl = ?');
        $ins = $pdo->prepare('INSERT INTO fin_irs (year, region, tbl, upto, rate, deduct, deduct_k, deduct_base, dep_deduct, position, source) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $n = 0;
        foreach ($rows as $t => $list) {
            $del->execute([$year, $region, $t]);
            usort($list, fn($a, $b) => ($a[0] ?? INF) <=> ($b[0] ?? INF));
            foreach ($list as $i => [$upto, $rate, $ded, $k, $base, $dep]) { $ins->execute([$year, $region, $t, $upto, $rate, $ded, $k, $base, $dep, $i, mb_substr($source, 0, 190)]); $n++; }
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return $n;
}
