<?php
/**
 * topper. admin — Financeiro: tesouraria, runway e tendência (§28.7–28.9)
 *
 *  · Contas (bancos, cartões, caixa) com o saldo atualizado pelo extrato (CSV ou OFX) ou à mão.
 *  · Conciliação: cada movimento do extrato emparelha com uma fatura, custo ou salário (sugestão por valor,
 *    data e NIF/nome; o utilizador confirma). Conciliar conta como comprovativo de pagamento.
 *  · Saldo disponível = contas − compromissos até ao fim do mês (faturas a pagar vencidas ou a vencer
 *    este mês, IRS e SS dos salários, IVA a entregar).
 *  · Runway: total = disponível ÷ custos fixos mensais; útil = total − N meses de reserva (parâmetro).
 *    Duas leituras: Conservador (sem receita) e Com tendência (receita média recebida em 6 meses,
 *    ajustada pelo crescimento composto).
 *  · Tendência: recebido e faturado por mês, médias móveis de 3 e 6 meses, crescimento composto de 6 e
 *    12 meses, projeção com três cenários e o pipeline à parte.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin.php';

// ── contas ──────────────────────────────────────────────────

function fin_accounts(PDO $pdo, bool $activeOnly = false): array {
    if (!fin_ready($pdo)) return [];
    return $pdo->query('SELECT a.*, (SELECT COUNT(*) FROM fin_bank_tx t WHERE t.account_id = a.id AND t.status = \'open\') AS open_tx FROM fin_accounts a'
                       . ($activeOnly ? ' WHERE a.active = 1' : '') . ' ORDER BY a.active DESC, a.position, a.id')->fetchAll();
}

function fin_account(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM fin_accounts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function fin_account_input(array $src): array {
    $name = mb_substr(trim((string)($src['name'] ?? '')), 0, 120);
    if ($name === '') throw new RuntimeException('Dê um nome à conta.');
    $iban = strtoupper(preg_replace('/\s+/', '', (string)($src['iban'] ?? '')));
    if ($iban !== '' && !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) throw new RuntimeException('IBAN inválido.');
    $date = (string)($src['balance_date'] ?? '');
    if ($date !== '' && !fin_date_ok($date)) throw new RuntimeException('Data do saldo inválida.');
    return ['name' => $name, 'kind' => isset(FIN_ACC_KINDS[$src['kind'] ?? '']) ? (string)$src['kind'] : 'bank', 'iban' => $iban,
            'balance' => round(money_in($src['balance'] ?? 0) * (str_starts_with(trim((string)($src['balance'] ?? '')), '-') ? -1 : 1), 2),
            'balance_date' => $date !== '' ? $date : today_lisbon(), 'active' => !isset($src['active']) || !empty($src['active']) ? 1 : 0];
}

// ── extratos ────────────────────────────────────────────────

/** «1.234,56» · «-1234.56» · «1 234,56» · «(12,00)» · «12,00-» → número. */
function fin_amount_parse(string $s): ?float {
    $s = trim(str_replace(["\xC2\xA0", ' ', '€', 'EUR'], '', $s));
    if ($s === '') return null;
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) { $neg = true; $s = $m[1]; }
    if (str_ends_with($s, '-')) { $neg = true; $s = substr($s, 0, -1); }
    if (str_starts_with($s, '+')) $s = substr($s, 1);
    if (str_starts_with($s, '-')) { $neg = !$neg; $s = substr($s, 1); }
    if (!preg_match('/^[\d.,]+$/', $s)) return null;
    $lc = strrpos($s, ','); $ld = strrpos($s, '.');
    if ($lc !== false && $ld !== false) {
        $s = $lc > $ld ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
    } elseif ($lc !== false) {
        $s = strlen($s) - $lc === 4 && substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    } elseif ($ld !== false && substr_count($s, '.') > 1) {
        $s = str_replace('.', '', $s);
    }
    if (!is_numeric($s)) return null;
    return round(($neg ? -1 : 1) * (float)$s, 2);
}

/** «23-10-2026» · «23/10/2026» · «2026-10-23» · «20261023» · «23.10.26» → AAAA-MM-DD. */
function fin_date_parse(string $s): ?string {
    $s = trim($s);
    if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})/', $s, $m)) $d = "$m[1]-$m[2]-$m[3]";
    elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})/', $s, $m)) $d = sprintf('%04d-%02d-%02d', strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3], (int)$m[2], (int)$m[1]);
    else return null;
    return fin_date_ok($d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)) ? $d : null;
}

/** OFX (SGML ou XML): os movimentos e o saldo final. */
function fin_parse_ofx(string $raw): array {
    $tx = [];
    $tag = function (string $blk, string $t): string {
        return preg_match('/<' . $t . '>\s*([^<\r\n]*)/i', $blk, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : '';
    };
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    preg_match_all('/<STMTTRN>(.*?)(<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/is', $raw, $mm);
    foreach ($mm[1] as $b) {
        $date = fin_date_parse(substr($tag($b, 'DTPOSTED'), 0, 8));
        $amt = fin_amount_parse(str_replace(',', '.', $tag($b, 'TRNAMT')));
        if (!$date || $amt === null) continue;
        $desc = trim($tag($b, 'NAME') . ' ' . $tag($b, 'MEMO'));
        $tx[] = ['date' => $date, 'amount' => $amt, 'desc' => $desc, 'ref' => $tag($b, 'FITID')];
    }
    $bal = null; $bdate = null;
    if (preg_match('/<LEDGERBAL>(.*?)(<\/LEDGERBAL>|$)/is', $raw, $m)) {
        $bal = fin_amount_parse(str_replace(',', '.', $tag($m[1], 'BALAMT')));
        $bdate = fin_date_parse(substr($tag($m[1], 'DTASOF'), 0, 8));
    }
    return ['tx' => $tx, 'balance' => $bal, 'balance_date' => $bdate];
}

/**
 * CSV dos bancos: separador (; , ou tab) e colunas pelo cabeçalho — data (data, data mov., date), descrição,
 * montante (montante, valor, amount) ou débito/crédito, saldo. Sem cabeçalho reconhecível: data;descrição;valor[;saldo].
 */
function fin_parse_csv(string $raw): array {
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $lines = preg_split('/\r\n|\r|\n/', trim($raw));
    $sep = ';';
    $sample = implode("\n", array_slice($lines, 0, 15));
    $counts = [';' => substr_count($sample, ';'), "\t" => substr_count($sample, "\t"), ',' => substr_count($sample, ',')];
    if ($counts[';'] === 0) $sep = $counts["\t"] > 0 ? "\t" : ',';
    $fold = fn($s) => preg_replace('/[^a-z]/', '', strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$s)));
    $map = null; $tx = []; $bal = null; $bdate = null;
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $c = str_getcsv($line, $sep, '"', '\\');
        if ($map === null) {
            $h = array_map($fold, $c);
            $find = function (array $names) use ($h) { foreach ($h as $i => $v) foreach ($names as $n) if ($v === $n || str_starts_with($v, $n)) return $i; return null; };
            $di = $find(['datamov', 'datadomovimento', 'datalancamento', 'datavalor', 'data', 'date', 'bookingdate']);
            $ai = $find(['montante', 'valor', 'amount', 'importancia', 'quantia']);
            $de = $find(['debito', 'debit', 'saida']);
            $cr = $find(['credito', 'credit', 'entrada']);
            $ds = $find(['descricao', 'descritivo', 'description', 'movimento', 'designacao', 'detalhe', 'memo', 'texto']);
            $bi = $find(['saldo', 'balance', 'saldocontabilistico', 'saldodisponivel']);
            if ($di !== null && ($ai !== null || $de !== null || $cr !== null)) { $map = compact('di', 'ai', 'de', 'cr', 'ds', 'bi'); continue; }
            // sem cabeçalho: data;descrição;valor[;saldo]
            if (fin_date_parse($c[0] ?? '') && count($c) >= 3) $map = ['di' => 0, 'ds' => 1, 'ai' => 2, 'de' => null, 'cr' => null, 'bi' => count($c) >= 4 ? 3 : null];
            else continue;
        }
        $date = fin_date_parse((string)($c[$map['di']] ?? ''));
        if (!$date) continue;
        if ($map['ai'] !== null) $amt = fin_amount_parse((string)($c[$map['ai']] ?? ''));
        else {
            $d = $map['de'] !== null ? fin_amount_parse((string)($c[$map['de']] ?? '')) : null;
            $cc = $map['cr'] !== null ? fin_amount_parse((string)($c[$map['cr']] ?? '')) : null;
            $amt = ($cc ?? 0) - abs($d ?? 0);
            if ($d === null && $cc === null) $amt = null;
        }
        if ($amt === null || $amt == 0.0) continue;
        $tx[] = ['date' => $date, 'amount' => $amt, 'desc' => trim((string)($map['ds'] !== null ? ($c[$map['ds']] ?? '') : '')), 'ref' => ''];
        if ($map['bi'] !== null && ($b = fin_amount_parse((string)($c[$map['bi']] ?? ''))) !== null) {
            if ($bdate === null || $date >= $bdate) { $bal = $b; $bdate = $date; }
        }
    }
    if ($map === null) throw new RuntimeException('Não reconheci as colunas do CSV: precisa de uma coluna de data e uma de valor (ou débito/crédito).');
    return ['tx' => $tx, 'balance' => $bal, 'balance_date' => $bdate];
}

/**
 * Importa um extrato para uma conta. Os movimentos já importados (mesma conta, data, valor, descrição e
 * ordem no dia) não se repetem. O saldo da conta passa a ser o do extrato, se o trouxer.
 */
function fin_import(PDO $pdo, int $accountId, string $bytes, string $fname, ?int $by): array {
    $acc = fin_account($pdo, $accountId);
    if (!$acc) throw new RuntimeException('Escolha a conta.');
    $isOfx = stripos($bytes, '<OFX>') !== false || stripos($bytes, 'OFXHEADER') !== false || preg_match('/\.(ofx|qfx)$/i', $fname);
    $p = $isOfx ? fin_parse_ofx($bytes) : fin_parse_csv($bytes);
    if (!$p['tx']) throw new RuntimeException('O ficheiro não tem movimentos que eu consiga ler.');
    $pdo->prepare('INSERT INTO fin_imports (account_id, file_name, fmt, n_new, n_dup, balance, created_by, created_at) VALUES (?,?,?,0,0,?,?,?)')
        ->execute([$accountId, mb_substr($fname, 0, 200), $isOfx ? 'ofx' : 'csv', $p['balance'], $by, fin_now()]);
    $imp = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO fin_bank_tx (account_id, import_id, tx_date, amount, description, ref, hash, status, created_at) VALUES (?,?,?,?,?,?,?,\'open\',?)');
    $seen = []; $new = 0; $dup = 0;
    $has = $pdo->prepare('SELECT COUNT(*) FROM fin_bank_tx WHERE account_id = ? AND hash = ?');
    foreach ($p['tx'] as $t) {
        $k = $t['date'] . '|' . number_format($t['amount'], 2, '.', '') . '|' . mb_strtolower(preg_replace('/\s+/', ' ', $t['desc']));
        $seen[$k] = ($seen[$k] ?? 0) + 1;
        $hash = sha1($t['ref'] !== '' ? 'ref|' . $t['ref'] : $k . '|' . $seen[$k]);
        $has->execute([$accountId, $hash]);
        if ((int)$has->fetchColumn()) { $dup++; continue; }
        $ins->execute([$accountId, $imp, $t['date'], $t['amount'], mb_substr($t['desc'], 0, 400), mb_substr($t['ref'], 0, 120), $hash, fin_now()]);
        $new++;
    }
    $pdo->prepare('UPDATE fin_imports SET n_new = ?, n_dup = ? WHERE id = ?')->execute([$new, $dup, $imp]);
    if ($p['balance'] !== null) {
        $pdo->prepare('UPDATE fin_accounts SET balance = ?, balance_date = ?, updated_at = ? WHERE id = ?')->execute([$p['balance'], $p['balance_date'] ?: today_lisbon(), fin_now(), $accountId]);
    }
    return ['new' => $new, 'dup' => $dup, 'balance' => $p['balance'], 'fmt' => $isOfx ? 'OFX' : 'CSV', 'import' => $imp];
}

// ── conciliação ─────────────────────────────────────────────

function fin_tx(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT t.*, a.name AS account FROM fin_bank_tx t JOIN fin_accounts a ON a.id = t.account_id WHERE t.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function fin_txs(PDO $pdo, string $status = 'open', ?int $account = null, int $limit = 200): array {
    $st = $pdo->prepare('SELECT t.*, a.name AS account, f.number AS doc_number, f.party_name AS doc_party, f.req_code AS doc_req, f.description AS doc_desc
                           FROM fin_bank_tx t JOIN fin_accounts a ON a.id = t.account_id LEFT JOIN fin_docs f ON f.id = t.doc_id
                          WHERE t.status = ?' . ($account ? ' AND t.account_id = ' . (int)$account : '') . ' ORDER BY t.tx_date DESC, t.id DESC LIMIT ' . (int)$limit);
    $st->execute([$status]);
    return $st->fetchAll();
}

/**
 * Sugestões para um movimento: lançamentos em aberto do mesmo sentido (entrada → receita; saída → despesa)
 * com pontos por valor igual ao que falta (ou a uma prestação), data perto do vencimento, e NIF, número
 * ou nome da contraparte no descritivo. Devolve até 5, da melhor para a pior: [doc, score, why].
 */
function fin_tx_suggest(PDO $pdo, array $t, int $max = 5): array {
    $dir = (float)$t['amount'] > 0 ? 'in' : 'out';
    $amt = abs((float)$t['amount']);
    $desc = mb_strtolower((string)$t['description']);
    $descN = preg_replace('/\D/', '', $desc);
    $out = [];
    foreach (fin_docs($pdo, ['dir' => $dir, 'st' => ['issued', 'partial', 'to_pay', 'dispute']]) as $d) {
        $left = fin_doc_left($d);
        if ($left <= 0) continue;
        $score = 0; $why = [];
        if (abs($left - $amt) < 0.01) { $score += 60; $why[] = 'valor igual'; }
        elseif (abs(fin_doc_due($d) - $amt) < 0.01) { $score += 50; $why[] = 'valor do total'; }
        elseif ($amt < $left && $left > 0 && in_array(round($amt / $left * 100), [20, 25, 30, 33, 40, 50, 60, 67, 75], true)) { $score += 25; $why[] = 'parece uma prestação'; }
        elseif (abs($left - $amt) / max($left, 1) < 0.02) { $score += 20; $why[] = 'valor parecido'; }
        $ref = (string)($d['due_date'] ?: $d['issue_date']);
        if ($ref !== '') {
            $days = abs((int)round((strtotime($t['tx_date']) - strtotime($ref)) / 86400));
            if ($days <= 3) { $score += 20; $why[] = 'data do vencimento'; }
            elseif ($days <= 15) { $score += 12; $why[] = 'perto do vencimento'; }
            elseif ($days <= 45) $score += 5;
        }
        if ($d['party_nif'] !== '' && $descN !== '' && str_contains($descN, preg_replace('/\D/', '', $d['party_nif']))) { $score += 25; $why[] = 'NIF no descritivo'; }
        if ($d['number'] !== '' && str_contains($desc, mb_strtolower(preg_replace('/\s+/', ' ', $d['number'])))) { $score += 25; $why[] = 'nº da fatura no descritivo'; }
        $name = mb_strtolower((string)$d['party_name']);
        if ($name !== '') {
            foreach (preg_split('/[\s,.\-]+/', $name) as $w) {
                if (mb_strlen($w) >= 4 && !in_array($w, ['lda', 'unipessoal', 'sociedade', 'limitada'], true) && str_contains($desc, $w)) { $score += 15; $why[] = 'nome no descritivo'; break; }
            }
        }
        if ($score >= 25) $out[] = ['doc' => $d + ['left' => $left], 'score' => min(100, $score), 'why' => implode(' · ', $why)];
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $max);
}

/** Concilia: regista o pagamento (o movimento é o comprovativo) e marca o movimento. Devolve o id do pagamento. */
function fin_tx_match(PDO $pdo, array $t, array $d, ?int $by): int {
    if ($t['status'] !== 'open') throw new RuntimeException('Esse movimento já foi tratado.');
    $dir = (float)$t['amount'] > 0 ? 'in' : 'out';
    if ($d['direction'] !== $dir) throw new RuntimeException($dir === 'in' ? 'Uma entrada só concilia com uma receita.' : 'Uma saída só concilia com uma despesa.');
    $amt = min(abs((float)$t['amount']), fin_doc_left($d));
    if ($amt <= 0) throw new RuntimeException('Esse lançamento já está pago.');
    $pid = fin_pay_add($pdo, $d, ['amount' => $amt, 'paid_on' => min((string)$t['tx_date'], today_lisbon()), 'method' => 'transfer', 'note' => 'Conciliado com o extrato (' . $t['account'] . ')'], null, (int)$t['id'], $by);
    $pdo->prepare("UPDATE fin_bank_tx SET status = 'matched', doc_id = ?, payment_id = ? WHERE id = ?")->execute([(int)$d['id'], $pid, (int)$t['id']]);
    return $pid;
}

/** Um movimento sem documento (comissões, juros, uma despesa pequena): cria o lançamento já pago e concilia. */
function fin_tx_new_doc(PDO $pdo, array $t, array $src, ?int $by): int {
    if ($t['status'] !== 'open') throw new RuntimeException('Esse movimento já foi tratado.');
    $dir = (float)$t['amount'] > 0 ? 'in' : 'out';
    $amt = abs((float)$t['amount']);
    $rate = (float)str_replace(',', '.', (string)($src['vat_rate'] ?? 0));
    $base = round($amt / (1 + $rate / 100), 2);
    $v = fin_doc_input($pdo, ['direction' => $dir, 'doc_type' => $dir === 'in' ? 'other' : 'expense', 'scope' => $src['scope'] ?? 'company', 'deal_id' => $src['deal_id'] ?? 0,
                              'party_name' => trim((string)($src['party_name'] ?? '')) ?: mb_substr((string)$t['description'], 0, 120) ?: 'Banco', 'category' => $src['category'] ?? 'Outros',
                              'description' => trim((string)($src['description'] ?? '')) ?: (string)$t['description'], 'base' => $base, 'vat_rate' => $rate, 'vat' => round($amt - $base, 2), 'total' => $amt,
                              'issue_date' => $t['tx_date'], 'due_date' => $t['tx_date'], 'vat_deductible' => !empty($src['vat_deductible']) ? 1 : 0]);
    $id = fin_doc_create($pdo, $v, $by);
    fin_tx_match($pdo, fin_tx($pdo, (int)$t['id']), fin_doc($pdo, $id), $by);
    return $id;
}

// ── séries por mês ──────────────────────────────────────────

/** Os últimos $n meses (AAAA-MM), do mais antigo ao atual. */
function fin_months_back(int $n, ?string $ref = null): array {
    $m = (new DateTimeImmutable($ref ?? today_lisbon()))->modify('first day of this month');
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) $out[] = $m->modify("-$i month")->format('Y-m');
    return $out;
}

/**
 * Por mês (AAAA-MM): faturado e recebido sem IVA (receitas) e o dinheiro que entrou e saiu (com IVA, pelos
 * pagamentos). Antes das faturas, os negócios contam pelo mês do trabalho (faturado) e, se pagos, como recebidos.
 */
function fin_series(PDO $pdo, array $months): array {
    $out = array_fill_keys($months, ['invoiced' => 0.0, 'received' => 0.0, 'cash_in' => 0.0, 'cash_out' => 0.0]);
    $from = $months[0] . '-01';
    $to = date('Y-m-t', strtotime(end($months) . '-01'));
    $st = $pdo->prepare("SELECT issue_date, base, doc_type FROM fin_docs WHERE direction = 'in' AND deleted_at IS NULL AND status NOT IN ('void','requested','review') AND issue_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $d) { $k = substr($d['issue_date'], 0, 7); if (isset($out[$k])) $out[$k]['invoiced'] += ($d['doc_type'] === 'credit' ? -1 : 1) * (float)$d['base']; }
    $st = $pdo->prepare("SELECT p.paid_on, p.amount, f.direction, f.base, f.total, f.withholding FROM fin_payments p JOIN fin_docs f ON f.id = p.doc_id
                          WHERE p.deleted_at IS NULL AND f.deleted_at IS NULL AND p.paid_on BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $p) {
        $k = substr($p['paid_on'], 0, 7);
        if (!isset($out[$k])) continue;
        $due = max(0.01, (float)$p['total'] - (float)$p['withholding']);
        if ($p['direction'] === 'in') { $out[$k]['cash_in'] += (float)$p['amount']; $out[$k]['received'] += (float)$p['amount'] * (float)$p['base'] / $due; }
        else $out[$k]['cash_out'] += (float)$p['amount'];
    }
    // negócios de antes das faturas (sem lançamento de receita)
    $st = $pdo->query("SELECT year, month, budget, has_vat, is_paid FROM deals d WHERE code IS NOT NULL AND phase IN ('production','done') AND budget > 0 AND year IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM fin_docs f WHERE f.deal_id = d.id AND f.direction = 'in' AND f.deleted_at IS NULL AND f.status <> 'void')");
    $rate = vat_rate($pdo);
    foreach ($st->fetchAll() as $d) {
        $k = sprintf('%04d-%02d', (int)$d['year'], (int)($d['month'] ?: 12));
        if (!isset($out[$k])) continue;
        $out[$k]['invoiced'] += (float)$d['budget'];
        if ((int)$d['is_paid']) { $out[$k]['received'] += (float)$d['budget']; $out[$k]['cash_in'] += (int)$d['has_vat'] ? (float)$d['budget'] * (1 + $rate / 100) : (float)$d['budget']; }
    }
    // custos da empresa de antes (pagos, sem registo de pagamento) contam como saída no mês
    $st = $pdo->prepare("SELECT issue_date, total FROM fin_docs f WHERE direction = 'out' AND deleted_at IS NULL AND status IN ('paid','archived') AND legacy_cost_id IS NOT NULL
                          AND issue_date BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM fin_payments p WHERE p.doc_id = f.id AND p.deleted_at IS NULL)");
    $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $d) { $k = substr($d['issue_date'], 0, 7); if (isset($out[$k])) $out[$k]['cash_out'] += (float)$d['total']; }
    foreach ($out as &$r) foreach ($r as &$v) $v = round($v, 2);
    return $out;
}

/** Média móvel de $n (só com os meses que existem). */
function fin_ma(array $vals, int $n): array {
    $vals = array_values($vals); $out = [];
    foreach ($vals as $i => $_) {
        $w = array_slice($vals, max(0, $i - $n + 1), min($n, $i + 1));
        $out[] = count($w) ? array_sum($w) / count($w) : 0.0;
    }
    return $out;
}

/** Crescimento médio mensal composto entre dois valores a $n meses de distância (null sem base). */
function fin_cmgr(float $from, float $to, int $n): ?float {
    if ($from <= 0 || $to <= 0 || $n <= 0) return null;
    return ($to / $from) ** (1 / $n) - 1;
}

/**
 * A tendência: 12 meses fechados (recebido e faturado, s/ IVA), médias móveis de 3 e 6, crescimento composto de
 * 6 e 12 meses (sobre a média de 3, para não saltar com um mês bom), e a projeção a 6 meses em três cenários
 * (pessimista e otimista: fatores do parâmetro sobre o crescimento). O pipeline vem à parte.
 */
function fin_trend(PDO $pdo, int $ahead = 6): array {
    $months = fin_months_back(13);
    array_pop($months);                         // só meses fechados
    $s = fin_series($pdo, $months);
    $rec = array_column($s, 'received');
    $inv = array_column($s, 'invoiced');
    $ma3 = fin_ma($rec, 3); $ma6 = fin_ma($rec, 6);
    $g6 = fin_cmgr($ma3[5] ?? 0, $ma3[11] ?? 0, 6);
    $g12 = fin_cmgr($ma3[2] ?? 0, $ma3[11] ?? 0, 9);   // a média de 3 só existe inteira a partir do 3.º mês
    $g = $g6 ?? 0.0;
    $g = max(-0.3, min(0.3, $g));
    $fp = fin_p($pdo, 'trend_pess', 0.5); $fo = fin_p($pdo, 'trend_opt', 1.5);
    $gp = $g >= 0 ? $g * $fp : $g * $fo;
    $go = $g >= 0 ? $g * $fo : $g * $fp;
    $start = end($ma3) ?: 0.0;
    $proj = [];
    $first = (new DateTimeImmutable(today_lisbon()))->modify('first day of this month');
    for ($k = 1; $k <= $ahead; $k++) {
        $proj[$first->modify('+' . ($k - 1) . ' month')->format('Y-m')] = ['pess' => round($start * (1 + $gp) ** $k, 2), 'real' => round($start * (1 + $g) ** $k, 2), 'opt' => round($start * (1 + $go) ** $k, 2)];
    }
    return ['months' => $months, 'received' => $rec, 'invoiced' => $inv, 'ma3' => $ma3, 'ma6' => $ma6, 'cmgr6' => $g6, 'cmgr12' => $g12, 'g' => $g,
            'avg6' => array_sum(array_slice($rec, -6)) / 6, 'proj' => $proj, 'pipeline' => fin_pipeline($pdo)];
}

/** Probabilidade de ganhar por etapa comercial (parâmetros): X-Ray, Proposta, Aguardar feedback. */
function fin_stage_prob(PDO $pdo, array $s): float {
    if ($s['kind'] === 'xray') return fin_p($pdo, 'prob_xray', 10) / 100;
    if (($s['role'] ?? '') === 'feedback') return fin_p($pdo, 'prob_feedback', 50) / 100;
    return fin_p($pdo, 'pipe_prob', 30) / 100;
}

/** O pipeline: ganhos ainda por faturar (certos) e propostas abertas × probabilidade da etapa (prováveis). */
function fin_pipeline(PDO $pdo): array {
    $won = 0.0; $wonN = 0; $wonRows = [];
    $rows = $pdo->query("SELECT d.id, d.code, d.name, d.budget, d.delivery_date, d.is_paid,
                                (SELECT COALESCE(SUM(CASE WHEN f.doc_type = 'credit' THEN -f.base ELSE f.base END), 0) FROM fin_docs f WHERE f.deal_id = d.id AND f.direction = 'in' AND f.deleted_at IS NULL AND f.status NOT IN ('void','requested','review')) AS invoiced
                           FROM deals d WHERE d.code IS NOT NULL AND d.phase IN ('production','done') AND d.budget > 0 AND d.is_paid = 0")->fetchAll();
    foreach ($rows as $r) {
        $left = round((float)$r['budget'] - (float)$r['invoiced'], 2);
        if ($left <= 0.5) continue;
        $won += $left; $wonN++;
        $wonRows[] = $r + ['left' => $left];
    }
    $prob = 0.0; $probN = 0;
    $rows = $pdo->query("SELECT d.value_estimate, s.kind, s.role FROM deals d JOIN funnel_stages s ON s.id = d.stage_id
                          WHERE d.phase = 'pipeline' AND d.value_estimate > 0 AND s.kind IN ('xray','commercial')")->fetchAll();
    foreach ($rows as $r) { $prob += (float)$r['value_estimate'] * fin_stage_prob($pdo, $r); $probN++; }
    return ['won' => round($won, 2), 'won_n' => $wonN, 'won_rows' => $wonRows, 'probable' => round($prob, 2), 'probable_n' => $probN];
}

// ── saldo disponível e runway ───────────────────────────────

/**
 * O saldo disponível: a soma das contas ativas menos o que já se sabe que sai até ao fim do mês — faturas e
 * custos por pagar vencidos ou a vencer este mês (inclui IRS e SS dos salários) e o IVA a entregar que vença
 * até lá.
 */
function fin_cash(PDO $pdo): array {
    $acc = 0.0; $n = 0; $oldest = null;
    foreach (fin_accounts($pdo, true) as $a) { $acc += (float)$a['balance']; $n++; if ($a['balance_date'] && ($oldest === null || $a['balance_date'] < $oldest)) $oldest = $a['balance_date']; }
    $eom = date('Y-m-t', strtotime(today_lisbon()));
    $commit = 0.0; $parts = ['payables' => 0.0, 'payroll' => 0.0, 'vat' => 0.0];
    foreach (fin_payables($pdo) as $d) {
        if ($d['status'] === 'review' || !$d['due_date'] || $d['due_date'] > $eom) continue;
        $k = $d['payroll_id'] ? 'payroll' : 'payables';
        $parts[$k] += $d['left'];
    }
    require_once __DIR__ . '/fin_tax.php';
    $vat = fin_vat_due_between($pdo, today_lisbon(), $eom);
    $parts['vat'] = max(0.0, $vat);
    $commit = array_sum($parts);
    return ['accounts' => round($acc, 2), 'n_accounts' => $n, 'balance_date' => $oldest, 'commit' => round($commit, 2), 'parts' => array_map(fn($v) => round($v, 2), $parts),
            'available' => round($acc - $commit, 2)];
}

/**
 * O runway (§28.8). burn = custos fixos mensais; total = disponível ÷ burn; útil = total − N.
 * Com tendência: mês a mês, entra a receita média recebida dos últimos 6 meses a crescer à tendência, sai o burn;
 * conta os meses até o saldo descer à reserva. Se a receita cobre o burn: sustentável, e quanto cresce.
 */
function fin_runway(PDO $pdo, ?float $extraMonthly = null, ?float $oneOff = null): array {
    $cash = fin_cash($pdo);
    $fx = fin_fixed_total($pdo);
    $burn = $fx['total'] + (float)$extraMonthly;
    $avail = $cash['available'] - (float)$oneOff;
    $N = fin_p($pdo, 'reserve_months', 6);
    $out = ['available' => $avail, 'burn' => round($burn, 2), 'reserve_months' => $N, 'reserve' => round($N * $burn, 2), 'cash' => $cash, 'fixed' => $fx,
            'total' => null, 'useful' => null, 'date' => null, 'trend_useful' => null, 'trend_date' => null, 'sustainable' => false, 'growth' => 0.0];
    if ($burn <= 0) return $out;
    $total = $avail / $burn;
    $out['total'] = round($total, 1);
    $out['useful'] = round($total - $N, 1);
    $out['date'] = fin_add_months(today_lisbon(), max(0, $total - $N));
    // com tendência
    static $trend = null;
    $trend ??= fin_trend($pdo);
    $rev0 = (float)$trend['avg6'];
    $g = (float)$trend['g'];
    if ($rev0 - $burn >= 0 && $g >= -0.001) {
        $out['sustainable'] = true;
        $out['growth'] = round($rev0 - $burn, 2);
        return $out;
    }
    $reserve = $N * $burn;
    if ($avail < $reserve) {
        $months = ($avail - $reserve) / max(1.0, $burn - $rev0);       // já dentro da reserva: negativo
    } else {
        $bal = $avail; $months = null;
        for ($k = 1; $k <= 120; $k++) {
            $net = $rev0 * (1 + $g) ** $k - $burn;
            if ($net < 0 && $bal + $net < $reserve) { $months = $k - 1 + ($bal - $reserve) / -$net; break; }
            $bal += $net;
        }
        if ($months === null) { $out['sustainable'] = true; $out['growth'] = round($rev0 * (1 + $g) - $burn, 2); return $out; }
    }
    $out['trend_useful'] = round($months, 1);
    $out['trend_date'] = fin_add_months(today_lisbon(), max(0, $months));
    return $out;
}

/** Uma data + n meses (com fração, em dias). */
function fin_add_months(string $date, float $m): string {
    $whole = (int)floor($m);
    $d = (new DateTimeImmutable($date))->modify("+$whole month");
    return $d->modify('+' . (int)round(($m - $whole) * 30) . ' days')->format('Y-m-d');
}

/**
 * Previsão de tesouraria a $n meses (§28.7): saldo + recebimentos esperados (faturas emitidas pelo vencimento — as
 * vencidas no mês corrente —, os negócios ganhos por faturar no mês de entrega, ou no seguinte) − custos fixos −
 * pagamentos previstos (faturas a pagar pelo vencimento). Os 6 meses antes vêm do que aconteceu (pagamentos).
 */
function fin_forecast(PDO $pdo, int $n = 6): array {
    $cash = fin_cash($pdo);
    $fx = fin_fixed_total($pdo);
    $first = (new DateTimeImmutable(today_lisbon()))->modify('first day of this month');
    $months = [];
    for ($i = 0; $i < $n; $i++) $months[$first->modify("+$i month")->format('Y-m')] = ['in' => 0.0, 'out' => 0.0];
    $cur = $first->format('Y-m');
    $lastKey = array_key_last($months);
    foreach (fin_docs($pdo, ['dir' => 'in', 'st' => ['issued', 'partial']]) as $d) {
        $k = max($cur, substr((string)($d['due_date'] ?: $d['issue_date'] ?: today_lisbon()), 0, 7));
        if (isset($months[$k])) $months[$k]['in'] += fin_doc_left($d);
    }
    foreach (fin_pipeline($pdo)['won_rows'] as $r) {
        $k = $r['delivery_date'] ? max($cur, substr((string)$r['delivery_date'], 0, 7)) : $first->modify('+1 month')->format('Y-m');
        if ($k > $lastKey) continue;
        if (isset($months[$k])) $months[$k]['in'] += (float)$r['left'] * (1 + vat_rate($pdo) / 100);
    }
    foreach (fin_payables($pdo) as $d) {
        if ($d['status'] === 'review' || $d['fixed_id']) continue;     // os custos fixos entram pelo valor mensal
        $k = max($cur, substr((string)($d['due_date'] ?: $d['issue_date'] ?: today_lisbon()), 0, 7));
        if (isset($months[$k])) $months[$k]['out'] += $d['left'];
    }
    $bal = $cash['accounts'];
    $rows = [];
    foreach ($months as $k => $v) {
        $out = $v['out'] + $fx['total'];
        $bal += $v['in'] - $out;
        $rows[$k] = ['in' => round($v['in'], 2), 'out' => round($out, 2), 'balance' => round($bal, 2)];
    }
    return $rows;
}

/** O fluxo de caixa de 12 meses para o gráfico: 6 passados (pagamentos), o atual e 5 futuros (previsão), com o saldo. */
function fin_cashflow12(PDO $pdo): array {
    $past = fin_months_back(7);
    array_pop($past);
    $s = fin_series($pdo, $past);
    $fc = fin_forecast($pdo, 6);
    $cash = fin_cash($pdo);
    // o saldo para trás: parte do saldo das contas e anda ao contrário pelos movimentos
    $bal = $cash['accounts'];
    $rows = [];
    $rev = array_reverse($past);
    $backBal = [];
    foreach ($rev as $k) { $backBal[$k] = $bal; $bal -= $s[$k]['cash_in'] - $s[$k]['cash_out']; }
    $has = $cash['n_accounts'] > 0;   // sem contas não há saldo a mostrar (só entradas e saídas)
    foreach ($past as $k) $rows[$k] = ['in' => $s[$k]['cash_in'], 'out' => $s[$k]['cash_out'], 'balance' => $has ? round($backBal[$k], 2) : null, 'future' => false];
    foreach ($fc as $k => $v) $rows[$k] = ['balance' => $has ? $v['balance'] : null] + $v + ['future' => $k !== date('Y-m', strtotime(today_lisbon()))];
    return $rows;
}
