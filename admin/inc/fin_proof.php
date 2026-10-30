<?php
/**
 * topper. admin — Financeiro: comprovativos de pagamento e recibos que chegam por email (§28.17)
 *
 *  · fin_proof_read()      o texto de um comprovativo (o PDF do banco, decifrado se preciso) → valor, data, beneficiário
 *                          e IBAN, ordenante, descritivo, referência Multibanco, comissões e a fatura-recibo do banco
 *                          por essas comissões. Sem IA; a IA só lê comprovativos digitalizados (imagem).
 *  · fin_text_payinfo()    numa fatura: o IBAN para pagar e a referência Multibanco (entidade + referência) — ficam no
 *                          lançamento e são as pistas mais fortes para associar o comprovativo depois.
 *  · fin_proof_suggest()   os lançamentos que o comprovativo pode pagar, por pontos: referência MB igual, IBAN conhecido
 *                          (da fatura, aprendido em validações anteriores ou da ficha de salário), valor igual ao que
 *                          falta, nome/número/NIF no descritivo, data perto do vencimento; e os pagamentos já
 *                          registados pelo extrato sem comprovativo.
 *  · fin_proof_validate()  a validação humana: regista o pagamento (o PDF é o comprovativo), cria a despesa da comissão
 *                          (já paga, com a fatura-recibo do banco) e aprende o IBAN. Tudo com «Anular».
 *  · fin_receipt_*()       os recibos que chegam sem pedido ficam por validar; ao validar juntam-se à fatura e, se ela
 *                          ainda não estava paga, registam também o pagamento (o recibo prova-o).
 *  · fin_tx_pay_*()        o extrato: um movimento que corresponde a um pagamento já registado com comprovativo liga-se
 *                          a ele, em vez de criar um segundo.
 * Nada fica pago sem alguém carregar em «Validar».
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin.php';
require_once __DIR__ . '/fin_qr.php';

const FIN_BANKS = ['millennium|banco comercial portugu' => 'Millennium bcp', 'caixa geral|\bcgd\b' => 'Caixa Geral de Depósitos', 'santander' => 'Santander',
                   'novo ?banco' => 'Novo Banco', '\bbpi\b' => 'BPI', 'montepio' => 'Banco Montepio', 'cr[eé]dito agr[ií]cola' => 'Crédito Agrícola', 'activobank' => 'ActivoBank',
                   'bankinter' => 'Bankinter', 'banco ctt' => 'Banco CTT', 'eurobic|\bbic\b' => 'EuroBic', 'revolut' => 'Revolut', '\bwise\b' => 'Wise', 'moey' => 'Moey'];

// ── IBAN ────────────────────────────────────────────────────

function fin_iban_norm(string $s): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s));
}

/** IBAN válido (formato e dígitos de controlo, mod 97). */
function fin_iban_ok(string $iban): bool {
    $i = fin_iban_norm($iban);
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $i) || (str_starts_with($i, 'PT') && strlen($i) !== 25)) return false;
    $num = preg_replace_callback('/[A-Z]/', fn($m) => (string)(ord($m[0]) - 55), substr($i, 4) . substr($i, 0, 4));
    $mod = 0;
    foreach (str_split($num, 7) as $part) $mod = (int)(($mod . $part) % 97);
    return $mod === 1;
}

/** «PT50 0033 0000 …» */
function fin_iban_fmt(string $iban): string {
    return trim(chunk_split(fin_iban_norm($iban), 4, ' '));
}

/** Os IBANs válidos num texto, com o que está antes na mesma linha (o rótulo): [[iban, rótulo], …]. */
function fin_text_ibans(string $text): array {
    $out = [];
    if (!preg_match_all('/(?<![A-Z0-9])[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,40}/', $text, $mm, PREG_OFFSET_CAPTURE)) return [];
    foreach ($mm[0] as [$m, $at]) {
        $c = str_replace(' ', '', $m);
        foreach (str_starts_with($c, 'PT') ? [25] : range(min(34, strlen($c)), 15, -1) as $L) {
            if (strlen($c) >= $L && fin_iban_ok(substr($c, 0, $L))) {
                $ls = strrpos(substr($text, 0, $at), "\n");
                $label = trim(substr($text, $ls === false ? 0 : $ls + 1, $at - ($ls === false ? 0 : $ls + 1)));
                if ($label === '' || preg_match('/^iban:?$/i', $label)) {   // o rótulo pode estar na linha de cima
                    $prev = substr($text, 0, $ls === false ? 0 : $ls);
                    $p2 = strrpos($prev, "\n");
                    $label = trim(substr($prev, $p2 === false ? 0 : $p2 + 1)) . ' ' . $label;
                }
                $out[] = [substr($c, 0, $L), mb_substr(trim($label), -80)];
                break;
            }
        }
    }
    return $out;
}

/** Os IBANs da empresa: as contas da Tesouraria e o IBAN para os clientes (Definições). */
function fin_our_ibans(PDO $pdo): array {
    $o = [];
    foreach ($pdo->query("SELECT iban FROM fin_accounts WHERE iban <> ''")->fetchAll(PDO::FETCH_COLUMN) as $i) $o[] = fin_iban_norm((string)$i);
    if (($s = fin_iban_norm((string)setting($pdo, 'fin_iban', ''))) !== '') $o[] = $s;
    return array_values(array_unique(array_filter($o)));
}

// ── valores, datas, referências ─────────────────────────────

const FIN_AMT_RE = '(\d{1,3}(?:[. ]\d{3})+,\d{2}|\d+,\d{2}|\d{1,3}(?:,\d{3})+\.\d{2}|\d+\.\d{2})';

/** Uma data «2026/10/29», «29-10-2026», «29.10.2026» → «2026-10-29» (ou ''). */
function fin_text_date(string $s): string {
    if (preg_match('/(?<!\d)(20\d{2})[\/.-](\d{1,2})[\/.-](\d{1,2})(?!\d)/', $s, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('/(?<!\d)(\d{1,2})[\/.-](\d{1,2})[\/.-](20\d{2})(?!\d)/', $s, $m)) [$y, $mo, $d] = [(int)$m[3], (int)$m[2], (int)$m[1]];
    else return '';
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
}

/** A entidade e a referência Multibanco (ou a referência de um pagamento ao Estado) num texto. */
function fin_text_mbref(string $text): array {
    $flat = preg_replace('/\s+/', ' ', $text);
    $ent = ''; $ref = '';
    if (preg_match('/entidade\s*:?\s*(\d{5})(?!\d)/iu', $flat, $m)) $ent = $m[1];
    if (preg_match('/refer[eê]ncia\s*(?:mb|multibanco|de pagamento|para pagamento)?\s*:?\s*((?:\d{3}\s?){2}\d{3})(?![\d])/iu', $flat, $m)) $ref = preg_replace('/\D/', '', $m[1]);
    // os dois números juntos, com os rótulos depois (como nas faturas do FastReport: «11683 835 691 114 Entidade Referência»)
    if (($ent === '' || $ref === '') && preg_match_all('/entidade/iu', $flat, $em, PREG_OFFSET_CAPTURE)) {
        foreach ($em[0] as [, $at]) {
            $win = substr($flat, max(0, $at - 160), 320);
            if (preg_match('/(?<!\d)(\d{3} ?\d{3} ?\d{3})(?![\d,.])/', $win, $m2)) {
                if ($ref === '') $ref = preg_replace('/\D/', '', $m2[1]);
                $win = str_replace($m2[1], ' ', $win);
            }
            if ($ent === '' && preg_match('/(?<!\d)(\d{5})(?![\d,.])/', $win, $m1)) $ent = $m1[1];
            if ($ent !== '' && $ref !== '') break;
        }
    }
    if ($ref === '' && preg_match('/refer[eê]ncia (?:de|para) pagamento\s*:?\s*((?:\d\s?){15})(?!\d)/iu', $flat, $m)) $ref = preg_replace('/\D/', '', $m[1]);   // AT, Seg. Social
    return ['mb_entity' => $ref !== '' ? $ent : '', 'mb_ref' => $ref];
}

/** Numa fatura: o IBAN para pagar (o primeiro que não é da empresa) e a referência Multibanco. */
function fin_text_payinfo(string $text, array $ourIbans = []): array {
    $iban = '';
    foreach (fin_text_ibans($text) as [$i]) if (!in_array($i, $ourIbans, true)) { $iban = $i; break; }
    return ['pay_iban' => $iban] + fin_text_mbref($text);
}

// ── ler um comprovativo ─────────────────────────────────────

/**
 * O texto de um comprovativo de pagamento → os campos, ou null se não parecer um comprovativo. $ctx: our_nif,
 * our_ibans, our_names (para saber o sentido) e invoice (a leitura do mesmo PDF como fatura, para a comissão).
 */
function fin_proof_read(string $text, array $ctx = []): ?array {
    $t = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', str_replace("\r", '', $text)));
    if (mb_strlen($t) < 30) return null;
    if (!preg_match('/comprovativo|montante da transfer|transfer[eê]ncia\s+(pontual|imediata|sepa|banc[aá]ria|interbanc|efetuada|realizada|a d[eé]bito|a cr[eé]dito|agendada|nacional)|pagamento de servi[cç]os|pagamento ao estado|ordem de (transfer|pagamento)|iban (da conta )?d[oa] (destinat|benefici)|\bmb ?way\b/iu', $t)) return null;
    $lines = array_values(array_filter(array_map('trim', explode("\n", $t)), 'strlen'));
    $amtIn = function (string $s): float { return preg_match('/' . FIN_AMT_RE . '/', $s, $m) ? fin_text_amount($m[1]) : 0.0; };
    $after = function (string $re) use ($lines, $amtIn): array {   // [o resto da linha depois do rótulo, a linha seguinte]
        foreach ($lines as $i => $l) if (preg_match($re, $l, $m, PREG_OFFSET_CAPTURE)) return [trim(substr($l, $m[0][1] + strlen($m[0][0]))), $lines[$i + 1] ?? ''];
        return ['', ''];
    };
    // o valor
    $amount = 0.0;
    foreach (['/^(montante|valor|import[aâ]ncia)( d[aoe]s?)? ?(transfer[eê]ncia|opera[cç][aã]o|pagamento|ordem)?( a transferir| transferido| pago| enviado)?\s*:?/iu',
              '/\b(montante|valor) (da transfer[eê]ncia|do pagamento|transferido|pago)\s*:?/iu'] as $re) {
        [$rest, $next] = $after($re);
        if ($rest !== '' || $next !== '') { $amount = $amtIn($rest) ?: $amtIn($next); if ($amount > 0) break; }
    }
    if ($amount <= 0) return null;
    // a data do movimento (ou da operação, da execução, do pagamento)
    $date = '';
    foreach (['/data (do movimento|da opera[cç][aã]o|de execu[cç][aã]o|do pagamento|da transfer[eê]ncia|de processamento)\s*:?/iu', '/data[ -]valor( da opera[cç][aã]o)?\s*:?/iu', '/^data\s*:?/iu'] as $re) {
        [$rest, $next] = $after($re);
        if (($date = fin_text_date($rest) ?: fin_text_date($next)) !== '') break;
    }
    if ($date === '') $date = fin_text_date($t);
    // IBANs: o do destinatário e o do ordenante (a conta a debitar)
    $payeeIban = ''; $payerIban = ''; $first = '';
    foreach (fin_text_ibans($t) as [$i, $label]) {
        if ($first === '') $first = $i;
        if ($payeeIban === '' && preg_match('/destin|benefici|creditar|para a conta|conta de destino/iu', $label)) $payeeIban = $i;
        elseif ($payerIban === '' && preg_match('/ordenante|origem|debitar|conta a d[eé]bito|^iban:?$|^(moeda da conta: \w+ )?iban:?$/iu', $label)) $payerIban = $i;
    }
    // nomes e descritivo
    $clean = fn(string $s) => mb_substr(trim(preg_replace('/\s{2,}.*$/', '', $s), " \t:-."), 0, 120);
    $payee = '';
    foreach (['/^(nome d[oa] )?(benefici[aá]rio|destinat[aá]rio)( da transfer[eê]ncia)?\s*:?/iu', '/\bTRF\.?\s*P\/\s*/u', '/^pagamento a\s+/iu'] as $re) {
        foreach ($lines as $l) {
            if (!preg_match($re, $l, $m, PREG_OFFSET_CAPTURE)) continue;
            $v = $clean(substr($l, $m[0][1] + strlen($m[0][0])));
            if ($v !== '' && !preg_match('/^(iban|nib|pt\d{2}|institui|banco|\d)/iu', $v)) { $payee = $v; break 2; }
        }
    }
    [$payer] = $after('/^(nome d[oa] )?ordenante\s*:?/iu');
    $payer = $payer !== '' && !preg_match('/^(iban|pt\d{2}|\d)/iu', $payer) ? $clean($payer) : '';
    [$stmt] = $after('/descritivo da transfer[eê]ncia na conta a (debitar|creditar)\s*:?/iu');
    $desc = '';
    foreach ($lines as $l) {
        if (preg_match('/^(descritivo|descri[cç][aã]o|refer[eê]ncia (do ordenante|para o benefici[aá]rio)|mensagem|motivo|observa[cç][oõ]es)\s*:?\s+(.+)$/iu', $l, $m) && !preg_match('/na conta a (debitar|creditar)/iu', $l)) { $desc = $clean($m[3]); break; }
    }
    [$bankRef] = $after('/(nossa refer[eê]ncia|refer[eê]ncia da opera[cç][aã]o|n\.?º (da )?opera[cç][aã]o)\s*:?/iu');
    $mb = fin_text_mbref(implode("\n", array_filter($lines, fn($l) => !preg_match('/nossa refer/iu', $l))));
    // o sentido: pela conta da empresa; senão pelo débito/crédito (um pagamento de serviços ou ao Estado é sempre nosso)
    $ours = (array)($ctx['our_ibans'] ?? []);
    $dir = '';
    if ($payeeIban !== '' && in_array($payeeIban, $ours, true)) $dir = 'in';
    elseif ($payerIban !== '' && in_array($payerIban, $ours, true)) $dir = 'out';
    elseif (preg_match('/total a movimentar\s*:?\s*' . FIN_AMT_RE . '\s*(DB|CR)\b/u', $t, $m)) $dir = $m[2] === 'DB' ? 'out' : 'in';
    elseif (preg_match('/^opera[cç][aã]o\s*:?.*\ba d[eé]bito\b/imu', $t)) $dir = 'out';
    elseif (preg_match('/^opera[cç][aã]o\s*:?.*\ba cr[eé]dito\b/imu', $t)) $dir = 'in';
    else {
        $usNif = ($n = (string)($ctx['our_nif'] ?? '')) !== '' && str_contains(preg_replace('/\D/', '', $t), $n);
        $usName = false;
        foreach ((array)($ctx['our_names'] ?? []) as $nm) if (mb_strlen($nm) >= 5 && mb_stripos(fin_proof_ascii($t), fin_proof_ascii(preg_replace('/,?\s*(lda|unipessoal|s\.?a)\.?$/iu', '', $nm))) !== false) $usName = true;
        if ($payee !== '' && $usName && mb_stripos(fin_proof_ascii($payee), 'constante') !== false) $dir = 'in';
        elseif ($usNif || $usName || preg_match('/efetu[aá]mos|debit/iu', $t)) $dir = 'out';
    }
    if ($dir === '' && ($mb['mb_ref'] !== '' || preg_match('/pagamento (de servi[cç]os|ao estado|de compras)/iu', $t))) $dir = 'out';
    if ($dir === 'out' && $payerIban === '' && $first !== '' && $first !== $payeeIban) $payerIban = $first;
    // comissões do banco e o total movimentado
    $fees = 0.0; $stamp = 0.0;
    if (preg_match('/total de fatura[cç][aã]o\s*:?\s*' . FIN_AMT_RE . '/iu', $t, $m)) $fees = fin_text_amount($m[1]);
    foreach ($lines as $l) {
        if (preg_match('/^(imp\.?|imposto)( do)? selo\b.*?' . FIN_AMT_RE . '\s*(EUR|€)?\s*$/iu', $l, $m)) $stamp += fin_text_amount($m[3]);
        elseif ($fees <= 0 && preg_match('/^(comiss[aã]o|encargos|despesas)\b.*?' . FIN_AMT_RE . '\s*(EUR|€)?\s*$/iu', $l, $m)) $fees += fin_text_amount($m[2]);
    }
    if ($fees > 0 && !preg_match('/total de fatura[cç][aã]o/iu', $t)) $fees += $stamp;
    $moved = preg_match('/total a movimentar\s*:?\s*' . FIN_AMT_RE . '/iu', $t, $m) ? fin_text_amount($m[1]) : round($amount + $fees, 2);
    // o banco (o emitente; o banco de destino não conta)
    $bank = ''; $best = 0;
    $own = implode("\n", array_filter($lines, fn($l) => !preg_match('/destin|benefici/iu', $l)));
    foreach (FIN_BANKS as $re => $name) { $n = preg_match_all('/' . $re . '/iu', $own); if ($n > $best) { $best = $n; $bank = $name; } }
    // a fatura-recibo do banco pelas comissões (o próprio comprovativo, quando o banco a emite junto)
    $fee = null;
    $inv = $ctx['invoice'] ?? null;
    if ($fees > 0 && is_array($inv) && ($inv['issuer_nif'] ?? '') !== '' && ($inv['issuer_nif'] ?? '') !== ($ctx['our_nif'] ?? '-') && ($inv['number'] ?? '') !== '') {
        $fee = ['issuer_nif' => (string)$inv['issuer_nif'], 'issuer_name' => mb_substr(trim(preg_replace('/,\s*(sede|capital|matr).*$/iu', '', (string)($inv['issuer_name'] ?? '')), ' ,'), 0, 190) ?: $bank,
                'number' => (string)$inv['number'], 'atcud' => (string)($inv['atcud'] ?? ''), 'issue_date' => (string)(($inv['issue_date'] ?? '') ?: $date),
                'total' => (float)($inv['total'] ?? 0) > 0 && abs((float)$inv['total'] - $fees) < 0.02 ? (float)$inv['total'] : $fees, 'vat' => (float)($inv['vat'] ?? 0) <= $fees ? (float)($inv['vat'] ?? 0) : 0.0,
                'stamp' => $stamp, 'kind' => (string)($inv['kind'] ?? 'invoice_receipt')];
    }
    // pelo menos dois sinais de comprovativo (uma fatura que diga «envie o comprovativo» não chega)
    $strong = (int)(bool)preg_match('/data (do movimento|da opera[cç][aã]o|de execu[cç][aã]o|da transfer[eê]ncia)|data[ -]valor/iu', $t)
            + (int)(bool)preg_match('/(destinat|benefici|ordenante|origem|debitar|creditar)/iu', implode(' ', array_column(fin_text_ibans($t), 1)))
            + (int)(bool)preg_match('/\b(ordenante|destinat[aá]rio|benefici[aá]rio)\b/iu', $t)
            + (int)(bool)preg_match('/pagamento (de servi[cç]os|ao estado)|\bmb ?way\b/iu', $t)
            + (int)(bool)preg_match('/comprovativo d[eao]s? (transfer|pagamento|opera)|transfer[eê]ncia (pontual|imediata|sepa|interbanc|efetuada|realizada|a d[eé]bito|a cr[eé]dito)/iu', $t)
            + (int)($stmt !== '' || $bankRef !== '');
    if ($strong < 2) return null;
    $found = count(array_filter([$date, $payee, $payeeIban, $desc, $mb['mb_ref']]));
    return ['amount' => $amount, 'date' => $date, 'dir' => $dir, 'payee' => $payee, 'payee_iban' => $payeeIban, 'payer' => $payer, 'payer_iban' => $payerIban,
            'desc' => $desc, 'stmt_desc' => $clean($stmt), 'ref' => $clean($bankRef), 'mb_entity' => $mb['mb_entity'], 'mb_ref' => $mb['mb_ref'],
            'fees' => round($fees, 2), 'moved' => $moved, 'bank' => $bank, 'fee_doc' => $fee, 'confidence' => min(0.95, 0.5 + 0.1 * $found)];
}

function fin_proof_ascii(string $s): string {
    return strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
}

/** Os dados de um comprovativo lidos pela IA (imagem digitalizada) → o mesmo formato. */
function fin_proof_from_ai(array $ai): ?array {
    $p = (array)($ai['proof'] ?? []);
    $amount = round((float)($p['amount'] ?? 0), 2);
    if ($amount <= 0) return null;
    $iban = fn($v) => fin_iban_ok((string)$v) ? fin_iban_norm((string)$v) : '';
    return ['amount' => $amount, 'date' => fin_date_ok((string)($p['date'] ?? '')) ? (string)$p['date'] : '', 'dir' => in_array($p['direction'] ?? '', ['in', 'out'], true) ? $p['direction'] : '',
            'payee' => mb_substr((string)($p['payee_name'] ?? ''), 0, 120), 'payee_iban' => $iban($p['payee_iban'] ?? ''), 'payer' => mb_substr((string)($p['payer_name'] ?? ''), 0, 120),
            'payer_iban' => $iban($p['payer_iban'] ?? ''), 'desc' => mb_substr((string)($p['description'] ?? ''), 0, 120), 'stmt_desc' => '', 'ref' => '',
            'mb_entity' => preg_replace('/\D/', '', (string)($p['mb_entity'] ?? '')), 'mb_ref' => preg_replace('/\D/', '', (string)($p['mb_ref'] ?? '')),
            'fees' => 0.0, 'moved' => $amount, 'bank' => mb_substr((string)($p['bank'] ?? ''), 0, 60), 'fee_doc' => null, 'confidence' => (float)($ai['confidence'] ?? 0.6), 'source' => 'ai'];
}

// ── associar ────────────────────────────────────────────────

/** Um IBAN aprendido (de quem é), ou null. */
function fin_iban_known(PDO $pdo, string $iban): ?array {
    if ($iban === '' || !db_has_table($pdo, 'fin_ibans')) return null;
    $st = $pdo->prepare('SELECT * FROM fin_ibans WHERE iban = ?');
    $st->execute([fin_iban_norm($iban)]);
    return $st->fetch() ?: null;
}

/** Guarda de quem é um IBAN (ao validar). Devolve a linha de antes (para o «Anular»), ou ['new' => true]. */
function fin_iban_learn(PDO $pdo, string $iban, array $d): array {
    $iban = fin_iban_norm($iban);
    if (!fin_iban_ok($iban) || !db_has_table($pdo, 'fin_ibans') || in_array($iban, fin_our_ibans($pdo), true)) return [];
    $old = fin_iban_known($pdo, $iban);
    if ($old) $pdo->prepare('UPDATE fin_ibans SET party_nif = ?, party_name = ?, contact_id = ?, uses = uses + 1, updated_at = ? WHERE iban = ?')
                  ->execute([(string)($d['party_nif'] ?: $old['party_nif']), mb_substr((string)($d['party_name'] ?: $old['party_name']), 0, 190), $d['contact_id'] ?: $old['contact_id'], fin_now(), $iban]);
    else $pdo->prepare('INSERT INTO fin_ibans (iban, party_nif, party_name, contact_id, uses, updated_at) VALUES (?,?,?,?,1,?)')
             ->execute([$iban, (string)$d['party_nif'], mb_substr((string)$d['party_name'], 0, 190), $d['contact_id'] ?: null, fin_now()]);
    return $old ? ['row' => $old] : ['new' => $iban];
}

/** As palavras de um nome que contam (sem «Lda», «SA»…), sem acentos. */
function fin_name_words(string $name): array {
    $w = preg_split('/[^a-z0-9]+/', fin_proof_ascii($name), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter($w, fn($x) => strlen($x) >= 4 && !in_array($x, ['lda', 'unipessoal', 'sociedade', 'limitada', 'portugal', 'servicos', 'comercio'], true)));
}

/**
 * Os lançamentos que um comprovativo pode pagar: [['id', 'score', 'why', 'label', 'left'], …], do mais provável para o
 * menos. Também os pagamentos já registados pelo extrato sem comprovativo (['pay' => id]).
 */
function fin_proof_suggest(PDO $pdo, array $p, int $max = 5): array {
    $dir = $p['dir'] === 'in' ? 'in' : 'out';
    $amt = (float)$p['amount'];
    $iban = $dir === 'out' ? (string)$p['payee_iban'] : (string)$p['payer_iban'];
    $known = $iban !== '' ? fin_iban_known($pdo, $iban) : null;
    $who = $dir === 'out' ? (string)$p['payee'] : (string)$p['payer'];
    $text = fin_proof_ascii($who . ' ' . $p['desc'] . ' ' . $p['stmt_desc']);
    $digits = preg_replace('/\D/', '', $p['desc'] . ' ' . $p['stmt_desc']);
    $words = fin_name_words($who);
    // o IBAN de cada colaborador (salários)
    $empIban = [];
    if ($dir === 'out' && $iban !== '') foreach ($pdo->query("SELECT p.id, e.iban FROM fin_payroll p JOIN fin_employees e ON e.user_id = p.user_id WHERE e.iban <> ''")->fetchAll() as $r) $empIban[(int)$r['id']] = fin_iban_norm((string)$r['iban']);
    $out = [];
    foreach (fin_docs($pdo, ['dir' => $dir, 'st' => ['issued', 'partial', 'to_pay', 'dispute', 'review'], 'limit' => 500]) as $d) {
        $left = fin_doc_left($d);
        if ($left <= 0) continue;
        $s = 0; $why = [];
        if ($p['mb_ref'] !== '' && ($d['mb_ref'] ?? '') !== '' && $d['mb_ref'] === $p['mb_ref'] && ($p['mb_entity'] === '' || ($d['mb_entity'] ?? '') === '' || $d['mb_entity'] === $p['mb_entity'])) { $s += 70; $why[] = 'referência Multibanco igual'; }
        $ibanHit = $iban !== '' && ((($d['pay_iban'] ?? '') !== '' && fin_iban_norm((string)$d['pay_iban']) === $iban)
                 || ($known && (($known['party_nif'] !== '' && $known['party_nif'] === preg_replace('/\D/', '', (string)$d['party_nif'])) || ($known['contact_id'] && (int)$known['contact_id'] === (int)$d['contact_id'])))
                 || ($d['payroll_id'] && ($empIban[(int)$d['payroll_id']] ?? '') === $iban));
        if ($ibanHit) { $s += 45; $why[] = 'IBAN ' . (($d['pay_iban'] ?? '') !== '' ? 'da fatura' : 'conhecido'); }
        if (abs($left - $amt) < 0.01) { $s += 35; $why[] = 'valor igual ao que falta'; }
        elseif (abs(fin_doc_due($d) - $amt) < 0.01) { $s += 25; $why[] = 'valor do total'; }
        elseif ($amt < $left && in_array((int)round($amt / $left * 100), [20, 25, 30, 33, 40, 50, 60, 67, 75], true)) { $s += 12; $why[] = 'parece uma prestação'; }
        elseif (abs($left - $amt) / max($left, 1) < 0.02) { $s += 10; $why[] = 'valor parecido'; }
        foreach (fin_name_words((string)$d['party_name']) as $w) if (str_contains($text, $w) || in_array($w, $words, true)) { $s += 20; $why[] = 'nome'; break; }
        if ($d['number'] !== '' && strlen($n = preg_replace('/[^a-z0-9]/', '', strtolower((string)$d['number']))) >= 4 && str_contains(preg_replace('/[^a-z0-9]/', '', $text), $n)) { $s += 25; $why[] = 'nº da fatura no descritivo'; }
        if (($nif = preg_replace('/\D/', '', (string)$d['party_nif'])) !== '' && strlen($nif) >= 9 && str_contains($digits, $nif)) { $s += 20; $why[] = 'NIF no descritivo'; }
        $ref = (string)($d['due_date'] ?: $d['issue_date']);
        if ($ref !== '' && $p['date'] !== '') {
            $days = abs((int)round((strtotime($p['date']) - strtotime($ref)) / 86400));
            if ($days <= 5) { $s += 10; $why[] = 'data do vencimento'; } elseif ($days <= 30) $s += 5;
            if ($d['issue_date'] && $p['date'] < date('Y-m-d', strtotime($d['issue_date'] . ' -7 days'))) $s -= 15;
        }
        if ($s >= 30) $out[] = ['id' => (int)$d['id'], 'score' => min(100, $s), 'why' => implode(' · ', $why), 'left' => $left, 'status' => (string)$d['status'],
                                'label' => fin_doc_label($d) . ' · ' . $d['party_name'] . ' · ' . ($left < fin_doc_due($d) ? 'falta ' : '') . eur($left) . ($d['status'] === 'review' ? ' · por confirmar' : '')];
    }
    // pagamentos já registados pelo extrato (sem comprovativo), com o mesmo valor e data perto
    if ($p['date'] !== '') {
        $st = $pdo->prepare("SELECT f.*, p.id AS pay_id, p.amount AS pay_amount, p.paid_on AS pay_on FROM fin_payments p JOIN fin_docs f ON f.id = p.doc_id
                              WHERE p.deleted_at IS NULL AND f.deleted_at IS NULL AND p.proof_key = '' AND f.direction = ? AND ABS(p.amount - ?) < 0.01 AND p.paid_on BETWEEN ? AND ?");
        $st->execute([$dir, $amt, date('Y-m-d', strtotime($p['date'] . ' -5 days')), date('Y-m-d', strtotime($p['date'] . ' +5 days'))]);
        foreach ($st->fetchAll() as $r) {
            $out[] = ['id' => (int)$r['id'], 'pay' => (int)$r['pay_id'], 'score' => 75, 'why' => 'já pago pelo extrato a ' . fin_date_pt((string)$r['pay_on']) . ', sem comprovativo', 'left' => 0.0, 'status' => (string)$r['status'],
                      'label' => fin_doc_label($r) . ' · ' . $r['party_name'] . ' · pago ' . eur((float)$r['pay_amount'])];
        }
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $max);
}

/** Os lançamentos em aberto para a lista «Outro lançamento…». */
function fin_proof_open_docs(PDO $pdo, string $dir, bool $receipt = false): array {
    $st = $receipt ? ['issued', 'partial', 'to_pay', 'dispute', 'review', 'paid'] : ['issued', 'partial', 'to_pay', 'dispute', 'review'];
    return array_values(array_filter(fin_docs($pdo, ['dir' => $dir === 'in' ? 'in' : 'out', 'st' => $st, 'limit' => 200, 'order' => 'f.id DESC']),
        fn($d) => $receipt ? (string)$d['receipt_key'] === '' : fin_doc_left($d) > 0));
}

// ── validar ─────────────────────────────────────────────────

/** O email por confirmar (comprovativo ou recibo), o anexo e os bytes. */
function fin_proof_mail(PDO $pdo, int $mailId, string $kind): array {
    $st = $pdo->prepare('SELECT * FROM fin_mail WHERE id = ?');
    $st->execute([$mailId]);
    $m = $st->fetch();
    if (!$m || $m['status'] !== 'review' || ($m['kind'] ?? '') !== $kind) throw new RuntimeException('Esse email já foi tratado.');
    $att = (json_decode((string)$m['att'], true) ?: [])[0] ?? null;
    if (!$att || ($att['key'] ?? '') === '') throw new RuntimeException('O anexo deste email não ficou guardado.');
    $bytes = fin_file_bytes((string)$att['key']);
    if ($bytes === null) throw new RuntimeException('Não encontrei o anexo no Drive.');
    return [$m, $att, $bytes];
}

/** O lançamento por confirmar passa a «Por pagar»/«Emitida» (validar o pagamento confirma-o). */
function fin_proof_confirm_doc(PDO $pdo, array $d): array {
    if ($d['status'] === 'review') {
        $pdo->prepare('UPDATE fin_docs SET status = ?, updated_at = ? WHERE id = ?')->execute([$d['direction'] === 'in' ? 'issued' : 'to_pay', fin_now(), (int)$d['id']]);
        $d = fin_doc_refresh($pdo, (int)$d['id']) ?? $d;
    }
    if (in_array($d['status'], ['void', 'requested'], true)) throw new RuntimeException(fin_doc_label($d) . ' está ' . mb_strtolower(FIN_ST[$d['status']]) . ': escolha outro lançamento.');
    return $d;
}

function fin_doc_snap(array $d): array {
    unset($d['deal_code'], $d['deal_name'], $d['deal_owner'], $d['left'], $d['overdue']);
    return $d;
}

/**
 * Valida um comprovativo. $target: o id do lançamento, «pay:ID» (juntar a um pagamento do extrato) ou «new» (criar a
 * despesa já paga). $fee: criar também a despesa da comissão do banco. Devolve [mensagem, dados do «Anular»].
 */
function fin_proof_validate(PDO $pdo, int $mailId, string $target, bool $fee, ?int $by): array {
    [$m, $att, $bytes] = fin_proof_mail($pdo, $mailId, 'proof');
    $p = json_decode((string)$m['proof'], true) ?: [];
    if ((float)($p['amount'] ?? 0) <= 0) throw new RuntimeException('Não li o valor deste comprovativo: registe o pagamento à mão no lançamento.');
    $dir = ($p['dir'] ?? '') === 'in' ? 'in' : 'out';
    $date = min((string)(($p['date'] ?? '') ?: substr((string)$m['received_at'], 0, 10) ?: today_lisbon()), today_lisbon());
    $what = ($p['bank'] ?? '') !== '' ? 'Comprovativo ' . $p['bank'] : 'Comprovativo';
    $undo = ['op' => 'proof', 'mail' => $mailId, 'note' => (string)$m['note'], 'pays' => [], 'docs_new' => [], 'doc_row' => null, 'pay_proof' => null, 'iban' => []];
    if (str_starts_with($target, 'pay:')) {
        // o pagamento já estava registado pelo extrato: o PDF passa a ser o comprovativo dele
        $st = $pdo->prepare("SELECT * FROM fin_payments WHERE id = ? AND deleted_at IS NULL AND proof_key = ''");
        $st->execute([(int)substr($target, 4)]);
        $pay = $st->fetch();
        if (!$pay) throw new RuntimeException('Esse pagamento já tem comprovativo (ou foi anulado).');
        $d = fin_doc($pdo, (int)$pay['doc_id']);
        [$key, $name] = fin_file_store($pdo, $bytes, fin_file_name($d, 'Comprovativo', (string)$att['name']), (string)$att['mime'], $d, 'receipt');
        $pdo->prepare('UPDATE fin_payments SET proof_key = ?, proof_name = ?, mail_id = ? WHERE id = ?')->execute([$key, $name, $mailId, (int)$pay['id']]);
        $undo['pay_proof'] = (int)$pay['id'];
        $msg = 'Comprovativo junto ao pagamento de ' . eur((float)$pay['amount']) . ' de ' . fin_doc_label($d) . '.';
    } else {
        if ($target === 'new') {
            if ($dir !== 'out') throw new RuntimeException('Escolha a fatura que o cliente pagou.');
            require_once __DIR__ . '/fin_ai.php';
            $who = (string)(($p['payee'] ?? '') ?: 'Transferência');
            $known = fin_iban_known($pdo, (string)($p['payee_iban'] ?? ''));
            $sug = fin_ai_suggest($pdo, (string)($known['party_nif'] ?? ''), 'out', 'invoice');
            $id = fin_doc_create($pdo, ['direction' => 'out', 'doc_type' => 'expense', 'scope' => 'company', 'party_kind' => $sug['party_kind'] ?? 'supplier',
                'party_name' => $known['party_name'] ?? $who, 'party_nif' => (string)($known['party_nif'] ?? ''), 'contact_id' => $known['contact_id'] ?? null,
                'category' => ($sug['category'] ?? '') ?: 'Outros', 'description' => mb_substr((string)(($p['desc'] ?? '') ?: 'Transferência para ' . $who), 0, 500),
                'base' => (float)$p['amount'], 'vat_rate' => 0, 'vat' => 0, 'total' => (float)$p['amount'], 'issue_date' => $date, 'pay_iban' => (string)($p['payee_iban'] ?? ''),
                'status' => 'to_pay', 'needs_review' => 1, 'notes' => date('d/m/Y') . ' · Criado a partir do comprovativo (' . $m['subject'] . '): falta a fatura ou o recibo.'], $by);
            $undo['docs_new'][] = $id;
            $d = fin_doc($pdo, $id);
        } else {
            $d = fin_doc($pdo, (int)$target);
            if (!$d || $d['deleted_at']) throw new RuntimeException('Escolha o lançamento que este comprovativo paga.');
            if ($d['direction'] !== $dir) throw new RuntimeException($dir === 'out' ? 'Este comprovativo é de um pagamento feito: escolha uma despesa (A pagar).' : 'Este comprovativo é de um recebimento: escolha uma fatura de A receber.');
            $undo['doc_row'] = fin_doc_snap($d);
            $d = fin_proof_confirm_doc($pdo, $d);
        }
        $left = fin_doc_left($d);
        if ((float)$p['amount'] > $left + 0.01) throw new RuntimeException('O comprovativo (' . eur((float)$p['amount']) . ') passa o que falta pagar em ' . fin_doc_label($d) . ' (' . eur($left) . '). Escolha outro lançamento.');
        $pid = fin_pay_add($pdo, $d, ['amount' => (float)$p['amount'], 'paid_on' => $date, 'method' => 'transfer', 'note' => mb_substr($what . ' (email)' . (($p['ref'] ?? '') !== '' ? ' · ' . $p['ref'] : ''), 0, 255)],
                           [$bytes, (string)$att['name'], (string)$att['mime']], null, $by);
        $pdo->prepare('UPDATE fin_payments SET mail_id = ? WHERE id = ?')->execute([$mailId, $pid]);
        $undo['pays'][] = $pid;
        $iban = $dir === 'out' ? (string)($p['payee_iban'] ?? '') : (string)($p['payer_iban'] ?? '');
        if ($iban !== '') {
            $undo['iban'] = fin_iban_learn($pdo, $iban, $d);
            if ($dir === 'out' && (string)($d['pay_iban'] ?? '') === '') $pdo->prepare('UPDATE fin_docs SET pay_iban = ? WHERE id = ?')->execute([fin_iban_norm($iban), (int)$d['id']]);
        }
        $d = fin_doc($pdo, (int)$d['id']);
        $msg = ($dir === 'out' ? 'Pagamento' : 'Recebimento') . ' de ' . eur((float)$p['amount']) . ' registado em ' . fin_doc_label($d) . ' (' . mb_strtolower(FIN_ST[$d['status']]) . ').';
    }
    // a comissão do banco: uma despesa já paga, com a fatura-recibo do banco (o mesmo PDF)
    $f = $p['fee_doc'] ?? null;
    if ($fee && (float)($p['fees'] ?? 0) > 0) {
        $dup = $f ? fin_doc_dup($pdo, (string)$f['issuer_nif'], (string)$f['number']) : null;
        if ($dup) $msg .= ' A comissão já estava lançada (' . fin_doc_label($dup) . ').';
        else {
            $total = (float)($f['total'] ?? $p['fees']);
            $vat = (float)($f['vat'] ?? 0);
            $fd = ['direction' => 'out', 'doc_type' => $f ? 'receipt' : 'expense', 'scope' => 'company', 'party_kind' => 'supplier', 'party_name' => (string)(($f['issuer_name'] ?? '') ?: ($p['bank'] ?: 'Banco')),
                   'party_nif' => (string)($f['issuer_nif'] ?? ''), 'category' => 'Comissões bancárias', 'number' => (string)($f['number'] ?? ''),
                   'description' => 'Comissão bancária' . (($p['payee'] ?? '') !== '' ? ' · transferência para ' . $p['payee'] : ''), 'base' => round($total - $vat, 2), 'vat_rate' => 0, 'vat' => $vat, 'total' => $total,
                   'issue_date' => (string)(($f['issue_date'] ?? '') ?: $date), 'status' => 'to_pay', 'pdf_key' => (string)$att['key'], 'pdf_name' => (string)$att['name'],
                   'receipt_key' => $f ? (string)$att['key'] : '', 'receipt_name' => $f ? (string)$att['name'] : '', 'ai_data' => $f ? json_encode($f, JSON_UNESCAPED_UNICODE) : null,
                   'notes' => date('d/m/Y') . ' · Do comprovativo (' . $m['subject'] . ')' . (($f['atcud'] ?? '') !== '' ? ' · ATCUD ' . $f['atcud'] : '') . (($f['stamp'] ?? 0) > 0 ? ' · inclui ' . eur((float)$f['stamp']) . ' de imposto do selo' : '')];
            $fid = fin_doc_create($pdo, $fd, $by);
            $undo['docs_new'][] = $fid;
            $fpid = fin_pay_add($pdo, fin_doc($pdo, $fid), ['amount' => $total, 'paid_on' => $date, 'method' => 'debit', 'note' => 'Debitada com a transferência'], [$bytes, (string)$att['name'], (string)$att['mime']], null, $by);
            $pdo->prepare('UPDATE fin_payments SET mail_id = ? WHERE id = ?')->execute([$mailId, $fpid]);
            $undo['pays'][] = $fpid;
            $msg .= ' Comissão do banco (' . eur($total) . ') lançada e paga.';
        }
    }
    $pdo->prepare("UPDATE fin_mail SET status = 'linked', doc_id = ?, done_by = ?, done_at = ?, note = ? WHERE id = ?")
        ->execute([(int)$d['id'], $by, fin_now(), mb_substr('Validado: ' . $msg, 0, 300), $mailId]);
    return [$msg, $undo];
}

/** Os recibos: os lançamentos a que um recibo lido pode pertencer (mesmo NIF, número da fatura no recibo, valor). */
function fin_receipt_suggest(PDO $pdo, array $data, string $dir, string $text = '', int $max = 5): array {
    $nif = preg_replace('/\D/', '', (string)($dir === 'in' ? ($data['client_nif'] ?? '') : ($data['issuer_nif'] ?? '')));
    $name = (string)($dir === 'in' ? ($data['client_name'] ?? '') : ($data['issuer_name'] ?? ''));
    $total = (float)($data['total'] ?? 0); $net = round($total - (float)($data['withholding'] ?? 0), 2);
    $flat = preg_replace('/[^a-z0-9]/', '', strtolower($text));
    $words = fin_name_words($name);
    $out = [];
    foreach (fin_proof_open_docs($pdo, $dir, true) as $d) {
        $s = 0; $why = [];
        if ($nif !== '' && preg_replace('/\D/', '', (string)$d['party_nif']) === $nif) { $s += 45; $why[] = 'NIF'; }
        elseif ($words && array_intersect($words, fin_name_words((string)$d['party_name']))) { $s += 20; $why[] = 'nome'; }
        if ($d['number'] !== '' && strlen($n = preg_replace('/[^a-z0-9]/', '', strtolower((string)$d['number']))) >= 4 && $flat !== '' && str_contains($flat, $n)) { $s += 40; $why[] = 'nº da fatura no recibo'; }
        $due = fin_doc_due($d); $left = fin_doc_left($d);
        if ($total > 0 && (abs((float)$d['total'] - $total) < 0.02 || abs($due - $net) < 0.02 || abs($due - $total) < 0.02)) { $s += 30; $why[] = 'valor'; }
        elseif ($total > 0 && $left > 0 && abs($left - $net) < 0.02) { $s += 25; $why[] = 'valor do que falta'; }
        if (($data['issue_date'] ?? '') !== '' && $d['issue_date'] && $data['issue_date'] >= $d['issue_date']) $s += 5;
        if ($s >= 40) $out[] = ['id' => (int)$d['id'], 'score' => min(100, $s), 'why' => implode(' + ', $why), 'left' => $left, 'status' => (string)$d['status'],
                                'label' => fin_doc_label($d) . ' · ' . $d['party_name'] . ' · ' . eur((float)$d['total']) . ' · ' . mb_strtolower(FIN_ST[$d['status']] ?? '')];
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $max);
}

/** Valida um recibo: junta-o ao lançamento e, se pedido e ainda faltar pagar, regista o pagamento. */
function fin_receipt_validate(PDO $pdo, int $mailId, int $docId, bool $pay, ?int $by): array {
    [$m, $att, $bytes] = fin_proof_mail($pdo, $mailId, 'receipt');
    $data = json_decode((string)$m['ai'], true) ?: [];
    $d = fin_doc($pdo, $docId);
    if (!$d || $d['deleted_at']) throw new RuntimeException('Escolha o lançamento a que o recibo pertence.');
    if ((string)$d['receipt_key'] !== '') throw new RuntimeException(fin_doc_label($d) . ' já tem recibo.');
    $undo = ['op' => 'proof', 'mail' => $mailId, 'note' => (string)$m['note'], 'pays' => [], 'docs_new' => [], 'doc_row' => fin_doc_snap($d), 'pay_proof' => null, 'iban' => []];
    $d = fin_proof_confirm_doc($pdo, $d);
    $pdo->prepare('UPDATE fin_docs SET receipt_key = ?, receipt_name = ?, updated_at = ? WHERE id = ?')->execute([(string)$att['key'], (string)$att['name'], fin_now(), (int)$d['id']]);
    $d = fin_doc_refresh($pdo, (int)$d['id']);
    $msg = 'Recibo junto a ' . fin_doc_label($d) . '.';
    $left = fin_doc_left($d);
    if ($pay && $left > 0) {
        $total = (float)($data['total'] ?? 0);
        $amount = $total > 0 ? min($left, round($total - (float)($data['withholding'] ?? 0), 2) ?: $total) : $left;
        $date = min((string)(($data['issue_date'] ?? '') ?: substr((string)$m['received_at'], 0, 10) ?: today_lisbon()), today_lisbon());
        $pid = fin_pay_add($pdo, $d, ['amount' => $amount, 'paid_on' => $date, 'method' => 'transfer', 'note' => 'Pagamento provado pelo recibo (email)'], [$bytes, (string)$att['name'], (string)$att['mime']], null, $by);
        $pdo->prepare('UPDATE fin_payments SET mail_id = ? WHERE id = ?')->execute([$mailId, $pid]);
        $undo['pays'][] = $pid;
        $d = fin_doc($pdo, (int)$d['id']);
        $msg = 'Recibo junto a ' . fin_doc_label($d) . ' e pagamento de ' . eur($amount) . ' registado (' . mb_strtolower(FIN_ST[$d['status']]) . ').';
    }
    $pdo->prepare("UPDATE fin_mail SET status = 'linked', doc_id = ?, done_by = ?, done_at = ?, note = ? WHERE id = ?")->execute([(int)$d['id'], $by, fin_now(), mb_substr('Validado: ' . $msg, 0, 300), $mailId]);
    return [$msg, $undo];
}

/** «Anular» uma validação: os pagamentos e lançamentos novos saem, o lançamento e o email voltam a ser o que eram. */
function fin_proof_undo(PDO $pdo, array $u): string {
    foreach ((array)$u['pays'] as $pid) fin_pay_del($pdo, (int)$pid);
    foreach ((array)$u['docs_new'] as $id) {
        $pdo->prepare('UPDATE fin_docs SET deleted_at = ? WHERE id = ?')->execute([fin_now(), (int)$id]);
        $pdo->prepare('UPDATE fin_payments SET deleted_at = ? WHERE doc_id = ? AND deleted_at IS NULL')->execute([fin_now(), (int)$id]);
    }
    if (!empty($u['pay_proof'])) $pdo->prepare("UPDATE fin_payments SET proof_key = '', proof_name = '', mail_id = NULL WHERE id = ?")->execute([(int)$u['pay_proof']]);
    if (!empty($u['doc_row'])) {
        $row = (array)$u['doc_row']; $id = (int)$row['id']; unset($row['id']);
        $pdo->prepare('UPDATE fin_docs SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($row))) . ' WHERE id = ?')->execute([...array_values($row), $id]);
        fin_doc_refresh($pdo, $id);
    }
    $ib = (array)($u['iban'] ?? []);
    if (!empty($ib['new'])) $pdo->prepare('DELETE FROM fin_ibans WHERE iban = ?')->execute([(string)$ib['new']]);
    elseif (!empty($ib['row'])) { $r = (array)$ib['row']; $pdo->prepare('UPDATE fin_ibans SET party_nif = ?, party_name = ?, contact_id = ?, uses = ?, updated_at = ? WHERE iban = ?')->execute([$r['party_nif'], $r['party_name'], $r['contact_id'], $r['uses'], $r['updated_at'], $r['iban']]); }
    $pdo->prepare("UPDATE fin_mail SET status = 'review', doc_id = NULL, done_by = NULL, done_at = NULL, note = ? WHERE id = ?")->execute([(string)$u['note'], (int)$u['mail']]);
    return 'Validação anulada: o email voltou a «Por confirmar».';
}

// ── o extrato ───────────────────────────────────────────────

/**
 * Os pagamentos já registados (com comprovativo) que um movimento do extrato pode ser: mesmo sentido, sem movimento
 * ligado, data a ±7 dias, valor igual — ou igual ao pagamento mais a comissão do mesmo comprovativo.
 */
function fin_tx_pay_cands(PDO $pdo, array $t): array {
    $dir = (float)$t['amount'] > 0 ? 'in' : 'out';
    $amt = abs((float)$t['amount']);
    $hasMail = db_has_column($pdo, 'fin_payments', 'mail_id');
    $st = $pdo->prepare('SELECT p.id AS pay_id, p.amount AS pay_amount, p.paid_on, ' . ($hasMail ? 'p.mail_id' : 'NULL AS mail_id') . ', f.* FROM fin_payments p JOIN fin_docs f ON f.id = p.doc_id
                          WHERE p.deleted_at IS NULL AND f.deleted_at IS NULL AND p.bank_tx_id IS NULL AND f.direction = ? AND p.paid_on BETWEEN ? AND ?');
    $st->execute([$dir, date('Y-m-d', strtotime($t['tx_date'] . ' -7 days')), date('Y-m-d', strtotime($t['tx_date'] . ' +7 days'))]);
    $rows = $st->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $with = (float)$r['pay_amount']; $sib = [];
        if ($r['mail_id']) foreach ($rows as $o) if ((int)$o['mail_id'] === (int)$r['mail_id'] && (int)$o['pay_id'] !== (int)$r['pay_id']) { $with += (float)$o['pay_amount']; $sib[] = (int)$o['pay_id']; }
        $days = abs((int)round((strtotime($t['tx_date']) - strtotime($r['paid_on'])) / 86400));
        if (abs($amt - (float)$r['pay_amount']) < 0.01) $why = 'pagamento já registado';
        elseif ($sib && abs($amt - $with) < 0.01) $why = 'pagamento + comissão do mesmo comprovativo';
        else continue;
        if (!str_contains($why, 'comissão')) $sib = [];
        if ($sib && (float)$r['pay_amount'] < $with - (float)$r['pay_amount']) continue;   // a comissão sozinha não: vai junto com o pagamento principal
        $out[] = ['pay' => (int)$r['pay_id'], 'sib' => $sib, 'score' => 90 - $days, 'why' => $why . ' a ' . fin_date_pt((string)$r['paid_on']),
                  'label' => fin_doc_label($r) . ' · ' . $r['party_name'] . ' · ' . eur((float)$r['pay_amount'])];
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, 3);
}

/** Liga o movimento ao pagamento já registado (e à comissão do mesmo comprovativo, se o valor os juntar). */
function fin_tx_link_pay(PDO $pdo, array $t, int $payId): array {
    if ($t['status'] !== 'open') throw new RuntimeException('Esse movimento já foi tratado.');
    $c = null;
    foreach (fin_tx_pay_cands($pdo, $t) as $x) if ($x['pay'] === $payId) $c = $x;
    if (!$c) throw new RuntimeException('Esse pagamento já não corresponde a este movimento.');
    $st = $pdo->prepare('SELECT * FROM fin_payments WHERE id = ?');
    $st->execute([$payId]);
    $p = $st->fetch();
    foreach (array_merge([$payId], $c['sib']) as $id) $pdo->prepare('UPDATE fin_payments SET bank_tx_id = ? WHERE id = ?')->execute([(int)$t['id'], (int)$id]);
    $pdo->prepare("UPDATE fin_bank_tx SET status = 'matched', doc_id = ?, payment_id = ? WHERE id = ?")->execute([(int)$p['doc_id'], $payId, (int)$t['id']]);
    return array_merge([$payId], $c['sib']);
}

// ── preencher o que já existe ───────────────────────────────

/** Uma vez depois da atualização: o IBAN e a referência MB das faturas a pagar que já tinham PDF. */
function fin_payinfo_backfill(PDO $pdo): int {
    if (!db_has_column($pdo, 'fin_docs', 'mb_ref') || (string)setting($pdo, 'fin_payinfo_39', '') !== '') return 0;
    require_once __DIR__ . '/ai.php';
    ai_setting_set($pdo, 'fin_payinfo_39', date('c'));
    $ours = fin_our_ibans($pdo);
    $n = 0;
    foreach ($pdo->query("SELECT id, pdf_key FROM fin_docs WHERE direction = 'out' AND deleted_at IS NULL AND pdf_key <> '' AND pay_iban = '' AND mb_ref = '' AND status IN ('review','to_pay','partial','dispute') ORDER BY id DESC LIMIT 40")->fetchAll() as $r) {
        try {
            $b = fin_file_bytes((string)$r['pdf_key']);
            if ($b === null || !str_starts_with($b, '%PDF')) continue;
            $i = fin_text_payinfo(fin_pdf_text($b), $ours);
            if ($i['pay_iban'] === '' && $i['mb_ref'] === '') continue;
            $pdo->prepare('UPDATE fin_docs SET pay_iban = ?, mb_entity = ?, mb_ref = ? WHERE id = ?')->execute([$i['pay_iban'], $i['mb_entity'], $i['mb_ref'], (int)$r['id']]);
            $n++;
        } catch (Throwable $e) { error_log('[topper] payinfo ' . $r['id'] . ': ' . $e->getMessage()); }
    }
    return $n;
}
