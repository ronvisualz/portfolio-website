<?php
/**
 * topper. admin — Financeiro: impostos (§28.11)
 *
 *  · IVA por período (mensal ou trimestral, parâmetro): liquidado (faturas emitidas) − dedutível (faturas
 *    recebidas com IVA dedutível) = a entregar ou a recuperar. Os negócios de antes das faturas contam pelo
 *    mês do trabalho. Estimativa: a declaração confirma-se com o contabilista.
 *  · Calendário fiscal: DMR e Segurança Social, retenções de IRS, IVA (declaração e pagamento) e as obrigações
 *    anuais — todas as datas são parâmetros. Cada obrigação dá uma tarefa ao responsável financeiro (uma vez).
 *  · Exportação para o contabilista: ZIP do mês com os PDFs e o CSV dos lançamentos.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin.php';

// ── IVA ─────────────────────────────────────────────────────

/** Os períodos do IVA de um ano: [from, to, label, out, in, balance, decl, pay]. */
function fin_vat_periods(PDO $pdo, int $year): array {
    $per = (int)fin_p($pdo, 'vat_period', 3) === 1 ? 1 : 3;
    $out = [];
    for ($m = 1; $m <= 12; $m += $per) {
        $from = sprintf('%04d-%02d-01', $year, $m);
        $to = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $m + $per - 1)));
        $after = (new DateTimeImmutable($to))->modify('first day of this month')->modify('+2 month');
        $label = $per === 1 ? month_year($m, $year) : (intdiv($m - 1, 3) + 1) . '.º trimestre ' . $year;
        $v = fin_vat_between($pdo, $from, $to);
        $out[] = ['from' => $from, 'to' => $to, 'label' => $label, 'out' => $v['out'], 'in' => $v['in'], 'balance' => round($v['out'] - $v['in'], 2), 'rows' => $v['rows'],
                  'decl' => $after->format('Y-m-') . sprintf('%02d', min((int)$after->format('t'), (int)fin_p($pdo, 'cal_vat_day', 20))),
                  'pay' => $after->format('Y-m-') . sprintf('%02d', min((int)$after->format('t'), (int)fin_p($pdo, 'cal_vat_pay', 25)))];
    }
    return $out;
}

/** O IVA liquidado e dedutível entre duas datas, com as linhas. */
function fin_vat_between(PDO $pdo, string $from, string $to): array {
    $o = 0.0; $i = 0.0; $rows = [];
    $st = $pdo->prepare("SELECT f.*, d.code AS deal_code FROM fin_docs f LEFT JOIN deals d ON d.id = f.deal_id
                          WHERE f.deleted_at IS NULL AND f.status NOT IN ('void','requested','review') AND f.vat <> 0 AND f.issue_date BETWEEN ? AND ? ORDER BY f.issue_date, f.id");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $d) {
        $sign = $d['doc_type'] === 'credit' ? -1 : 1;
        if ($d['direction'] === 'in') { $o += $sign * (float)$d['vat']; $rows[] = ['date' => $d['issue_date'], 'label' => fin_doc_label($d) . ' · ' . $d['party_name'], 'out' => $sign * (float)$d['vat'], 'in' => 0.0, 'doc' => (int)$d['id']]; }
        elseif ((int)$d['vat_deductible']) { $i += $sign * (float)$d['vat']; $rows[] = ['date' => $d['issue_date'], 'label' => fin_doc_label($d) . ' · ' . $d['party_name'], 'out' => 0.0, 'in' => $sign * (float)$d['vat'], 'doc' => (int)$d['id']]; }
    }
    // negócios de antes das faturas (sem lançamento de receita), pelo mês do trabalho
    $rate = vat_rate($pdo);
    $y1 = (int)substr($from, 0, 4); $m1 = (int)substr($from, 5, 2); $m2 = (int)substr($to, 5, 2);
    $st = $pdo->prepare("SELECT d.id, d.code, d.name, d.budget, d.has_vat, d.month, d.expenses_mode, d.expenses_manual FROM deals d WHERE d.code IS NOT NULL AND d.phase IN ('production','done')
                          AND d.year = ? AND d.month BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM fin_docs f WHERE f.deal_id = d.id AND f.direction = 'in' AND f.deleted_at IS NULL AND f.status <> 'void')");
    $st->execute([$y1, $m1, $m2]);
    foreach ($st->fetchAll() as $d) {
        $m = deal_money($d, deal_costs($pdo, (int)$d['id'], $rate), $rate);
        if ($m['vat_out'] == 0 && $m['vat_in'] == 0) continue;
        $o += $m['vat_out']; $i += $m['vat_in'];
        $rows[] = ['date' => sprintf('%04d-%02d-01', $y1, (int)$d['month']), 'label' => $d['code'] . ' · ' . $d['name'] . ' (pelo negócio)', 'out' => $m['vat_out'], 'in' => $m['vat_in'], 'doc' => 0, 'deal' => (int)$d['id']];
    }
    return ['out' => round($o, 2), 'in' => round($i, 2), 'rows' => $rows];
}

/** O IVA a entregar cujo pagamento vence entre duas datas (para o saldo disponível). */
function fin_vat_due_between(PDO $pdo, string $from, string $to): float {
    $sum = 0.0;
    $y = (int)substr($from, 0, 4);
    foreach ([$y - 1, $y] as $yy) foreach (fin_vat_periods($pdo, $yy) as $p) {
        if ($p['pay'] >= $from && $p['pay'] <= $to && $p['balance'] > 0) $sum += $p['balance'];
    }
    return round($sum, 2);
}

// ── calendário fiscal ───────────────────────────────────────

/**
 * As obrigações entre $from e $from + $days: [key, name, date, amount, kind, detail]. As mensais referem-se ao
 * mês anterior (salários e retenções); o IVA ao período fechado; as anuais pela lista do parâmetro.
 */
function fin_obligations(PDO $pdo, string $from, int $days): array {
    $to = date('Y-m-d', strtotime($from . ' +' . $days . ' days'));
    $out = [];
    $hasPayroll = (int)$pdo->query('SELECT COUNT(*) FROM fin_employees WHERE active = 1')->fetchColumn() > 0
               || (int)$pdo->query('SELECT COUNT(*) FROM fin_payroll WHERE cancelled_at IS NULL')->fetchColumn() > 0;
    $m = (new DateTimeImmutable($from))->modify('first day of this month');
    for ($i = 0; $i <= (int)ceil($days / 28) + 1; $i++) {
        $mm = $m->modify("+$i month");
        $prev = $mm->modify('-1 month');
        $py = (int)$prev->format('Y'); $pm = (int)$prev->format('n');
        $day = fn(string $k, int $d) => $mm->format('Y-m-') . sprintf('%02d', min((int)$mm->format('t'), (int)fin_p($pdo, $k, $d)));
        $pr = $pdo->prepare('SELECT COALESCE(SUM(irs), 0), COALESCE(SUM(ss_employee + ss_employer), 0), COUNT(*) FROM fin_payroll WHERE year = ? AND month = ? AND cancelled_at IS NULL');
        $pr->execute([$py, $pm]);
        [$irs, $ss, $n] = $pr->fetch(PDO::FETCH_NUM);
        // retenções a terceiros (freelancers, rendas) das faturas pagas no mês anterior
        $wh = $pdo->prepare("SELECT COALESCE(SUM(withholding), 0) FROM fin_docs WHERE direction = 'out' AND withholding > 0 AND deleted_at IS NULL AND status IN ('paid','archived') AND paid_date BETWEEN ? AND ?");
        $wh->execute([$prev->format('Y-m-01'), $prev->format('Y-m-t')]);
        $whv = (float)$wh->fetchColumn();
        $ref = month_year($pm, $py);
        if ($hasPayroll || (int)$n) {
            $out[] = ['key' => 'dmr:' . $prev->format('Y-m'), 'name' => 'DMR — declaração de remunerações (AT) de ' . $ref, 'date' => $day('cal_dmr', 10), 'amount' => 0.0, 'kind' => 'irs'];
            $out[] = ['key' => 'ssd:' . $prev->format('Y-m'), 'name' => 'Segurança Social — declaração de remunerações de ' . $ref, 'date' => $day('cal_ss_decl', 10), 'amount' => 0.0, 'kind' => 'ss'];
            $out[] = ['key' => 'ssp:' . $prev->format('Y-m'), 'name' => 'Segurança Social — pagamento das contribuições de ' . $ref, 'date' => $day('cal_ss_pay', 20), 'amount' => round((float)$ss, 2), 'kind' => 'ss'];
        }
        if ($hasPayroll || (int)$n || $whv > 0) {
            $out[] = ['key' => 'irsp:' . $prev->format('Y-m'), 'name' => 'IRS retido — pagamento das retenções de ' . $ref, 'date' => $day('cal_irs_pay', 20), 'amount' => round((float)$irs + $whv, 2), 'kind' => 'irs'];
        }
    }
    $y = (int)substr($from, 0, 4);
    foreach ([$y - 1, $y] as $yy) foreach (fin_vat_periods($pdo, $yy) as $p) {
        $out[] = ['key' => 'ivad:' . $p['from'], 'name' => 'IVA — declaração periódica (' . $p['label'] . ')', 'date' => $p['decl'], 'amount' => 0.0, 'kind' => 'vat'];
        $out[] = ['key' => 'ivap:' . $p['from'], 'name' => 'IVA — pagamento (' . $p['label'] . ')' . ($p['balance'] < 0 ? ' · a recuperar' : ''), 'date' => $p['pay'], 'amount' => max(0.0, $p['balance']), 'kind' => 'vat'];
    }
    foreach ([$y, $y + 1] as $yy) {
        foreach (explode(';', fin_ps($pdo, 'cal_annual')) as $item) {
            if (!preg_match('/^\s*([^|]+)\|(\d{2})-(\d{2})\s*$/u', $item, $mm)) continue;
            $d = sprintf('%04d-%s-%s', $yy, $mm[2], $mm[3]);
            if (!fin_date_ok($d)) continue;
            $out[] = ['key' => 'ano:' . md5($mm[1]) . ':' . $yy, 'name' => trim($mm[1]) . ' (' . ($yy - (str_contains(mb_strtolower($mm[1]), 'pagamento por conta') ? 0 : 1)) . ')', 'date' => $d, 'amount' => 0.0, 'kind' => 'annual'];
        }
    }
    $out = array_values(array_filter($out, fn($o) => $o['date'] >= $from && $o['date'] <= $to));
    usort($out, fn($a, $b) => [$a['date'], $a['name']] <=> [$b['date'], $b['name']]);
    return $out;
}

/**
 * Uma tarefa ao responsável financeiro por cada obrigação dos próximos 10 dias, uma só vez (fin_done).
 * Devolve quantas foram criadas.
 */
function fin_obligation_tasks(PDO $pdo, ?string $today = null): int {
    $today ??= today_lisbon();
    $uid = fin_owner_id($pdo);
    if (!$uid) return 0;
    $mid = $pdo->query('SELECT team_member_id FROM users WHERE id = ' . $uid)->fetchColumn();
    if (!$mid) return 0;
    require_once __DIR__ . '/tasks.php';
    $n = 0;
    $has = $pdo->prepare('SELECT COUNT(*) FROM fin_done WHERE okey = ?');
    foreach (fin_obligations($pdo, $today, 10) as $o) {
        $has->execute([$o['key']]);
        if ((int)$has->fetchColumn()) continue;
        $tid = task_create($pdo, ['title' => $o['name'], 'description' => 'Calendário fiscal' . ($o['amount'] > 0 ? ' · valor estimado ' . eur($o['amount']) : '') . "\nConfirmar com o contabilista. Ver Financeiro › Impostos.",
                                  'member_id' => (int)$mid, 'job_name' => '', 'deal_id' => null, 'due_date' => $o['date'], 'priority' => 'alta', 'status' => 'todo']);
        $pdo->prepare('INSERT INTO fin_done (okey, task_id, created_at) VALUES (?,?,?)')->execute([$o['key'], $tid, fin_now()]);
        $n++;
    }
    return $n;
}

// ── exportação para o contabilista ──────────────────────────

/** O CSV (;) dos lançamentos de um intervalo, com os pagamentos. */
function fin_docs_csv(PDO $pdo, string $from, string $to): string {
    $out = fopen('php://temp', 'w+');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Id', 'Sentido', 'Tipo', 'Âmbito', 'Negócio', 'Contraparte', 'NIF', 'Categoria', 'Número', 'Descrição', 'Emissão', 'Vencimento', 'Base', 'Taxa IVA', 'IVA', 'IVA dedutível',
                   'Total', 'Retenção', 'Moeda', 'Estado', 'Pago', 'Data do pagamento', 'Ficheiro'], ';');
    $n = fn($v) => number_format((float)$v, 2, ',', '');
    foreach (fin_docs($pdo, ['from' => $from, 'to' => $to, 'order' => 'f.issue_date, f.id']) as $d) {
        fputcsv($out, [$d['id'], $d['direction'] === 'in' ? 'Receita' : 'Despesa', FIN_TYPES[$d['doc_type']] ?? $d['doc_type'], $d['scope'] === 'deal' ? 'Negócio' : 'Empresa',
                       $d['deal_code'] ?? '', $d['party_name'], $d['party_nif'], $d['category'], $d['number'], $d['description'], $d['issue_date'], $d['due_date'], $n($d['base']),
                       $n($d['vat_rate']), $n($d['vat']), (int)$d['vat_deductible'] ? 'sim' : 'não', $n($d['total']), $n($d['withholding']), $d['currency'], FIN_ST[$d['status']] ?? $d['status'],
                       $n($d['paid_amount']), $d['paid_date'], $d['pdf_name']], ';');
    }
    rewind($out);
    return (string)stream_get_contents($out);
}

/**
 * O ZIP de um mês: «lancamentos.csv», «salarios.csv» e os PDFs (faturas, recibos, comprovativos, recibos de
 * vencimento) em pastas. ZIP simples (sem compressão), feito aqui para não depender da extensão zip.
 */
function fin_export_zip(PDO $pdo, int $y, int $m): string {
    $from = sprintf('%04d-%02d-01', $y, $m);
    $to = date('Y-m-t', strtotime($from));
    $files = ['lancamentos.csv' => fin_docs_csv($pdo, $from, $to)];
    require_once __DIR__ . '/fin_pay.php';
    $files['salarios.csv'] = fin_payroll_csv($pdo, $y, $m);
    $seen = [];
    $add = function (string $dir, string $key, string $name) use (&$files, &$seen) {
        if ($key === '' || isset($seen[$key])) return;
        $seen[$key] = 1;
        $b = fin_file_bytes($key);
        if ($b === null) return;
        $base = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $name) ?: 'ficheiro.pdf';
        $path = $dir . '/' . $base;
        for ($i = 2; isset($files[$path]); $i++) $path = $dir . '/' . pathinfo($base, PATHINFO_FILENAME) . '_' . $i . '.' . pathinfo($base, PATHINFO_EXTENSION);
        $files[$path] = $b;
    };
    foreach (fin_docs($pdo, ['from' => $from, 'to' => $to]) as $d) {
        $dir = $d['direction'] === 'in' ? 'faturas-emitidas' : 'faturas-recebidas';
        $add($dir, (string)$d['pdf_key'], (string)($d['pdf_name'] ?: 'documento-' . $d['id'] . '.pdf'));
        $add('recibos', (string)$d['receipt_key'], (string)($d['receipt_name'] ?: 'recibo-' . $d['id'] . '.pdf'));
    }
    $st = $pdo->prepare('SELECT p.proof_key, p.proof_name FROM fin_payments p WHERE p.deleted_at IS NULL AND p.proof_key <> \'\' AND p.paid_on BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $p) $add('comprovativos', (string)$p['proof_key'], (string)$p['proof_name']);
    foreach (fin_payroll_list($pdo, $y, $m) as $p) if (!$p['cancelled_at']) $add('recibos-vencimento', (string)$p['pdf_key'], sprintf('%04d-%02d_%s.pdf', $y, $m, $p['name']));
    return fin_zip($files);
}

/** Um ZIP (método «store», nomes em UTF-8) a partir de nome → bytes. */
function fin_zip(array $files): string {
    $data = ''; $cd = ''; $n = 0;
    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    foreach ($files as $name => $bytes) {
        $crc = crc32($bytes);
        $len = strlen($bytes);
        $off = strlen($data);
        $hdr = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $len, $len, strlen($name), 0);
        $data .= $hdr . $name . $bytes;
        $cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 32, $off) . $name;
        $n++;
    }
    return $data . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), strlen($data), 0);
}
