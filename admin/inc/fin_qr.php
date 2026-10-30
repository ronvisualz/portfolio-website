<?php
/**
 * topper. admin — Financeiro: ler o QR code fiscal das faturas portuguesas, sem IA (§28.14)
 *
 * As faturas emitidas em Portugal trazem um QR code (Portaria n.º 195/2020) com o NIF do emitente (A) e do
 * adquirente (B), o tipo e o número do documento, a data, o ATCUD, as bases e o IVA por taxa, o total e a
 * retenção. Lê-lo não custa nada e é exato; a IA só entra quando não há QR legível.
 *
 *  · fin_doc_scan()        um anexo (PDF, imagem ou XML) → os campos da fatura, ou null.
 *  · fin_qr_fields()       o texto do QR → os campos normalizados (o mesmo formato que a IA devolve).
 *  · fin_pdf_qr()          o QR dentro de um PDF: imagens embutidas (Flate/DCT, com ou sem preditor),
 *                          quadrados desenhados (vetorial) ou o texto do QR presente no próprio PDF.
 *  · FinQr                 o descodificador (localizar os padrões, amostrar, Reed-Solomon, segmentos),
 *                          em PHP puro — o servidor não tem bibliotecas nem programas externos.
 *  · fin_xml_invoice()     as faturas eletrónicas em XML (UBL 2.1 / CIUS-PT).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const FIN_QR_DOC_KIND = ['FT' => 'invoice', 'FS' => 'invoice', 'ND' => 'invoice', 'FR' => 'invoice_receipt', 'NC' => 'credit_note', 'RC' => 'receipt', 'RG' => 'receipt'];
const FIN_VAT_RATES = [23, 13, 6, 22, 12, 5, 16, 9, 4];   // continente, Madeira, Açores

// ── o anexo ─────────────────────────────────────────────────

/** Um anexo financeiro → os campos (fonte «qr» ou «xml») ou null se não tiver QR/XML legível. */
function fin_doc_scan(string $bytes, string $mime): ?array {
    try {
        if ($mime === 'application/pdf') { $p = fin_pdf_qr($bytes); return $p !== null ? fin_qr_fields($p) : null; }
        if (in_array($mime, ['image/jpeg', 'image/png'], true)) { $p = fin_img_qr($bytes); return $p !== null ? fin_qr_fields($p) : null; }
        if ($mime === 'application/xml') return fin_xml_invoice($bytes);
    } catch (Throwable $e) {
        error_log('fin_doc_scan: ' . $e->getMessage());
    }
    return null;
}

/** NIF português válido (9 algarismos e o dígito de controlo). */
function fin_nif_ok(string $n): bool {
    if (!preg_match('/^\d{9}$/', $n)) return false;
    $s = 0;
    for ($i = 0; $i < 8; $i++) $s += (int)$n[$i] * (9 - $i);
    $c = 11 - $s % 11;
    return ($c >= 10 ? 0 : $c) === (int)$n[8];
}

/**
 * O texto do QR fiscal → os campos, no formato da leitura por IA (com 'source' => 'qr' e 'atcud').
 * Só devolve quando está claro: NIF do emitente válido, data, número e total.
 */
function fin_qr_fields(string $s): ?array {
    $f = [];
    foreach (explode('*', trim($s)) as $part) {
        $p = strpos($part, ':');
        if ($p === false) continue;
        $f[strtoupper(trim(substr($part, 0, $p)))] = trim(substr($part, $p + 1));
    }
    $num = fn(string $k) => isset($f[$k]) && is_numeric($f[$k]) ? (float)$f[$k] : 0.0;
    $a = (string)($f['A'] ?? ''); $b = strtoupper(preg_replace('/\s+/', '', (string)($f['B'] ?? '')));
    $date = (string)($f['F'] ?? '');
    if (!fin_nif_ok($a) || !preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $d) || !checkdate((int)$d[2], (int)$d[3], (int)$d[1]) || ($f['G'] ?? '') === '' || !isset($f['O']) || !is_numeric($f['O'])) return null;
    $type = strtoupper((string)($f['D'] ?? ''));
    // bases e IVA por taxa: I (continente ou o espaço fiscal de I1), J (Açores), K (Madeira)
    $taxable = 0.0; $vat = 0.0; $exempt = 0.0; $pairs = [];
    foreach (['I', 'J', 'K'] as $z) {
        $exempt += $num($z . '2');
        foreach ([[3, 4], [5, 6], [7, 8]] as [$bi, $vi]) {
            $bb = $num($z . $bi); $vv = $num($z . $vi);
            if ($bb > 0 || $vv > 0) { $taxable += $bb; $vat += $vv; $pairs[] = [$bb, $vv]; }
        }
    }
    $total = round($num('O'), 2);
    $rate = 0.0;
    if ($pairs) {
        usort($pairs, fn($x, $y) => $y[1] <=> $x[1]);
        [$bb, $vv] = $pairs[0];
        if ($bb > 0) {
            $r = $vv / $bb * 100;
            $best = null;
            foreach (FIN_VAT_RATES as $c) if ($best === null || abs($c - $r) < abs($best - $r)) $best = $c;
            $rate = abs($best - $r) < 0.6 ? (float)$best : round($r, 2);
        }
    }
    $vat = round($vat, 2);
    return ['source' => 'qr', 'kind' => (($f['E'] ?? 'N') === 'A') ? 'void' : (FIN_QR_DOC_KIND[$type] ?? 'other'), 'doc_code' => $type,
            'issuer_name' => '', 'issuer_nif' => $a, 'client_name' => '', 'client_nif' => $b === '999999990' ? '' : $b, 'client_country' => strtoupper((string)($f['C'] ?? 'PT')),
            'number' => mb_substr((string)$f['G'], 0, 60), 'atcud' => mb_substr((string)($f['H'] ?? ''), 0, 70),
            'issue_date' => "$d[1]-$d[2]-$d[3]", 'due_date' => '', 'base' => round($total - $vat - $num('M'), 2), 'vat_rate' => $rate, 'vat' => $vat,
            'total' => $total, 'withholding' => round($num('P'), 2), 'stamp' => round($num('M'), 2), 'currency' => 'EUR', 'description' => '', 'confidence' => 1.0];
}

// ── XML (UBL 2.1 / CIUS-PT) ─────────────────────────────────

/** Uma fatura eletrónica em XML → os campos (fonte «xml»), ou null se não for uma fatura UBL. */
function fin_xml_invoice(string $xml): ?array {
    if (strlen($xml) > 5 * 1048576 || stripos($xml, '<!ENTITY') !== false) return null;
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if ($x === false) return null;   // (um elemento só com filhos num espaço de nomes conta como «falso»)
    $root = $x->getName();
    if (!in_array($root, ['Invoice', 'CreditNote'], true)) return null;
    $q = function (string $path) use ($x): string {
        $r = $x->xpath($path);
        return $r ? trim((string)$r[0]) : '';
    };
    $nif = function (string $party) use ($q): string {
        foreach (["//*[local-name()='$party']//*[local-name()='PartyTaxScheme']/*[local-name()='CompanyID']", "//*[local-name()='$party']//*[local-name()='PartyLegalEntity']/*[local-name()='CompanyID']",
                  "//*[local-name()='$party']//*[local-name()='EndpointID']"] as $p) {
            $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $q($p)));
            if ($v !== '') return preg_replace('/^PT(?=\d{9}$)/', '', $v);
        }
        return '';
    };
    $name = fn(string $party) => $q("//*[local-name()='$party']//*[local-name()='PartyLegalEntity']/*[local-name()='RegistrationName']") ?: $q("//*[local-name()='$party']//*[local-name()='PartyName']/*[local-name()='Name']");
    $amt = fn(string $n) => round((float)$q("/*/*[local-name()='LegalMonetaryTotal']/*[local-name()='$n']"), 2);
    $issuer = $nif('AccountingSupplierParty');
    $date = $q("/*/*[local-name()='IssueDate']");
    if ($issuer === '' || !fin_date_ok($date)) return null;
    $total = $amt('TaxInclusiveAmount') ?: $amt('PayableAmount');
    $base = $amt('TaxExclusiveAmount');
    $vat = round((float)$q("/*/*[local-name()='TaxTotal']/*[local-name()='TaxAmount']"), 2);
    $rate = (float)$q("//*[local-name()='TaxSubtotal']//*[local-name()='Percent']");
    $due = $q("/*/*[local-name()='DueDate']") ?: $q("//*[local-name()='PaymentDueDate']");
    return ['source' => 'xml', 'kind' => $root === 'CreditNote' ? 'credit_note' : 'invoice', 'doc_code' => $root === 'CreditNote' ? 'NC' : 'FT',
            'issuer_name' => mb_substr($name('AccountingSupplierParty'), 0, 190), 'issuer_nif' => $issuer,
            'client_name' => mb_substr($name('AccountingCustomerParty'), 0, 190), 'client_nif' => $nif('AccountingCustomerParty'), 'client_country' => 'PT',
            'number' => mb_substr($q("/*/*[local-name()='ID']"), 0, 60), 'atcud' => '', 'issue_date' => $date, 'due_date' => fin_date_ok($due) ? $due : '',
            'base' => $base ?: round($total - $vat, 2), 'vat_rate' => $rate, 'vat' => $vat, 'total' => $total, 'withholding' => 0.0, 'stamp' => 0.0,
            'currency' => strtoupper($q("/*/*[local-name()='DocumentCurrencyCode']") ?: 'EUR'), 'description' => mb_substr($q("//*[local-name()='InvoiceLine']//*[local-name()='Item']/*[local-name()='Name']"), 0, 300),
            'confidence' => 1.0];
}

// ── imagens ─────────────────────────────────────────────────

/** O QR numa imagem (JPEG ou PNG): o texto, ou null. */
function fin_img_qr(string $bytes): ?string {
    if (!function_exists('imagecreatefromstring')) return null;
    $im = @imagecreatefromstring($bytes);
    if (!$im) return null;
    try { return FinQr::fromGd($im); } finally { imagedestroy($im); }
}

// ── PDF ─────────────────────────────────────────────────────

/**
 * Um PDF cifrado sem palavra-passe de abertura (os bancos, como o Millennium, cifram os comprovativos só para impedir
 * alterações) → o mesmo PDF com os streams decifrados e sem /Encrypt, para o resto da leitura. Norma: RC4 e AES-128
 * (R2–R4) e AES-256 (R5/R6). Se precisar de palavra-passe (ou não houver OpenSSL), devolve o original.
 */
function fin_pdf_decrypt(string $pdf): string {
    static $cache = [];
    if (!preg_match('/\/Encrypt\s+(\d+)\s+(\d+)\s+R/', $pdf, $em)) return $pdf;
    $ck = md5($pdf);
    if (isset($cache[$ck])) return $cache[$ck];
    if (count($cache) > 4) $cache = [];
    try { $out = fin_pdf_decrypt_run($pdf, (int)$em[1]); }
    catch (Throwable $e) { error_log('fin_pdf_decrypt: ' . $e->getMessage()); $out = $pdf; }
    return $cache[$ck] = $out;
}

function fin_pdf_decrypt_run(string $pdf, int $encNum): string {
    if (!function_exists('openssl_decrypt')) return $pdf;
    // os objetos «n g obj … endobj» (posição, número, geração, corpo)
    if (!preg_match_all('/(?<![\d])(\d+)\s+(\d+)\s+obj\b/', $pdf, $mm, PREG_OFFSET_CAPTURE)) return $pdf;
    $objs = [];
    foreach ($mm[0] as $k => [$tok, $at]) {
        $end = strpos($pdf, 'endobj', $at);
        if ($end === false) continue;
        $objs[] = ['num' => (int)$mm[1][$k][0], 'gen' => (int)$mm[2][$k][0], 'at' => $at, 'body' => $at + strlen($tok), 'end' => $end];
    }
    $byNum = [];
    foreach ($objs as $o) $byNum[$o['num']] = $o;
    if (!isset($byNum[$encNum])) return $pdf;
    $enc = substr($pdf, $byNum[$encNum]['body'], $byNum[$encNum]['end'] - $byNum[$encNum]['body']);
    if (!preg_match('/\/Filter\s*\/Standard\b/', $enc)) return $pdf;   // só o método normal (sem certificados)
    $top = preg_replace('/\/CF\s*<<.*?>>\s*>>/s', '', $enc);   // (o /Length dos filtros é outro)
    $int = fn(string $k, int $d) => preg_match('/\/' . $k . '\s+(-?\d+)/', $top, $x) ? (int)$x[1] : $d;
    $V = $int('V', 0); $R = $int('R', 2); $P = $int('P', 0); $len = $int('Length', 40);
    $O = fin_pdf_dict_str($enc, 'O'); $U = fin_pdf_dict_str($enc, 'U');
    $id0 = '';
    if (preg_match('/\/ID\s*\[\s*(<[0-9A-Fa-f\s]*>|\()/', $pdf, $im, PREG_OFFSET_CAPTURE)) { $p = $im[1][1]; $id0 = fin_pdf_str_at($pdf, $p); }
    // o método dos streams: RC4, AES-128 ou AES-256 (V4/V5 dizem-no no filtro StdCF); Identity = sem cifra
    $cfm = $V >= 4 ? (preg_match('/\/StmF\s*\/Identity/', $enc) ? 'None' : (preg_match('/\/CFM\s*\/(\w+)/', $enc, $c) ? $c[1] : 'V2')) : 'V2';
    if ($cfm === 'None') return preg_replace('/\/Encrypt\s+\d+\s+\d+\s+R/', '', $pdf);
    $pad = hex2bin('28BF4E5E4E758A4164004E56FFFA01082E2E00B6D0683E802F0CA9FE6453697A');
    if ($R >= 5) {
        // AES-256: a chave vem de UE, cifrada com o hash da palavra-passe (vazia) e do «key salt» de U
        $UE = fin_pdf_dict_str($enc, 'UE');
        if (strlen($U) < 48 || strlen($UE) < 32) return $pdf;
        if (fin_pdf_hash6('', substr($U, 32, 8), '', $R) !== substr($U, 0, 32)) return $pdf;   // precisa de palavra-passe
        $ik = fin_pdf_hash6('', substr($U, 40, 8), '', $R);
        $key = (string)openssl_decrypt(substr($UE, 0, 32), 'aes-256-cbc', $ik, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        if (strlen($key) !== 32) return $pdf;
    } else {
        $n = $R === 2 ? 5 : intdiv(max(40, min(128, $len)), 8);
        $h = md5($pad . $O . pack('V', $P) . $id0 . ($R >= 4 && preg_match('/\/EncryptMetadata\s+false/', $enc) ? "\xff\xff\xff\xff" : ''), true);
        if ($R >= 3) for ($i = 0; $i < 50; $i++) $h = md5(substr($h, 0, $n), true);
        $key = substr($h, 0, $n);
        // confirma que abre sem palavra-passe (o U calculado bate com o do ficheiro)
        if ($R === 2) $ok = fin_rc4($key, $pad) === substr($U, 0, 32);
        else {
            $u = fin_rc4($key, md5($pad . $id0, true));
            for ($i = 1; $i <= 19; $i++) { $k2 = ''; for ($j = 0; $j < $n; $j++) $k2 .= chr(ord($key[$j]) ^ $i); $u = fin_rc4($k2, $u); }
            $ok = $u === substr($U, 0, 16);
        }
        if (!$ok) return $pdf;
    }
    $aes = in_array($cfm, ['AESV2', 'AESV3'], true);
    $dec = function (string $data, int $num, int $gen) use ($key, $R, $aes): string {
        if ($R >= 5) $k = $key;
        else $k = substr(md5($key . substr(pack('V', $num), 0, 3) . substr(pack('V', $gen), 0, 2) . ($aes ? 'sAlT' : ''), true), 0, min(strlen($key) + 5, 16));
        if (!$aes) return fin_rc4($k, $data);
        if (strlen($data) < 32) return '';
        $r = openssl_decrypt(substr($data, 16), strlen($k) === 32 ? 'aes-256-cbc' : 'aes-128-cbc', $k, OPENSSL_RAW_DATA, substr($data, 0, 16));
        return $r === false ? '' : $r;
    };
    // reescreve: os streams decifrados (com o /Length novo); o resto fica igual
    $out = ''; $pos = 0;
    foreach ($objs as $o) {
        if ($o['num'] === $encNum || $o['at'] < $pos) continue;
        $body = substr($pdf, $o['body'], $o['end'] - $o['body']);
        if (!preg_match('/>>\s*stream(\r\n|\n|\r)/', $body, $sm, PREG_OFFSET_CAPTURE)) continue;
        $dict = substr($body, 0, $sm[0][1] + 2);
        if (preg_match('/\/Type\s*\/XRef\b/', $dict)) continue;   // os streams de referências não são cifrados
        $start = $o['body'] + $sm[0][1] + strlen($sm[0][0]);
        $L = -1;
        if (preg_match('/\/Length\s+(\d+)\s+(\d+)\s+R/', $dict, $lm) && isset($byNum[(int)$lm[1]])) {
            $lo = $byNum[(int)$lm[1]];
            if (preg_match('/^\s*(\d+)/', substr($pdf, $lo['body'], 40), $lv)) $L = (int)$lv[1];
        } elseif (preg_match('/\/Length\s+(\d+)/', $dict, $lm)) $L = (int)$lm[1];
        if ($L < 0 || $start + $L > $o['end']) { $e2 = strpos($pdf, 'endstream', $start); if ($e2 === false) continue; $L = strlen(rtrim(substr($pdf, $start, $e2 - $start), "\r\n")); }
        $plain = $dec(substr($pdf, $start, $L), $o['num'], $o['gen']);
        $dict2 = preg_replace('/\/Length\s+\d+(\s+\d+\s+R)?/', '/Length ' . strlen($plain), $dict, 1);
        $out .= substr($pdf, $pos, $o['body'] - $pos) . substr($dict2, 0, -2) . '>>stream' . "\n" . $plain . "\nendstream\n";
        $pos = $start + $L;
        $e3 = strpos($pdf, 'endstream', $pos);
        if ($e3 !== false && $e3 < $o['end']) $pos = $e3 + 9;
    }
    $out .= substr($pdf, $pos);
    return preg_replace('/\/Encrypt\s+\d+\s+\d+\s+R/', '', $out);
}

/** Uma string de um dicionário PDF (/O, /U, /UE…) em bytes: literal «(…)» com escapes ou hexadecimal «<…>». */
function fin_pdf_dict_str(string $dict, string $key): string {
    if (!preg_match('/\/' . $key . '\s*(\(|<(?!<))/', $dict, $m, PREG_OFFSET_CAPTURE)) return '';
    return fin_pdf_str_at($dict, $m[1][1]);
}

/** A string PDF que começa na posição $p (com «(» ou «<»). */
function fin_pdf_str_at(string $s, int $p): string {
    if ($s[$p] === '<') {
        $e = strpos($s, '>', $p);
        $h = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $p + 1, ($e === false ? strlen($s) : $e) - $p - 1));
        return (string)hex2bin(strlen($h) % 2 ? $h . '0' : $h);
    }
    $out = ''; $depth = 0; $n = strlen($s);
    for ($i = $p + 1; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '\\') {
            $x = $s[++$i] ?? '';
            if (ctype_digit($x) && $x < '8') { $o = $x; while (strlen($o) < 3 && isset($s[$i + 1]) && $s[$i + 1] >= '0' && $s[$i + 1] <= '7') $o .= $s[++$i]; $out .= chr(octdec($o) & 255); }
            elseif ($x === "\r") { if (($s[$i + 1] ?? '') === "\n") $i++; }
            elseif ($x !== "\n") $out .= ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c"][$x] ?? $x;
            continue;
        }
        if ($c === '(') $depth++;
        elseif ($c === ')') { if ($depth === 0) break; $depth--; }
        $out .= $c;
    }
    return $out;
}

/** RC4 (sem depender do OpenSSL, que o desliga nas versões novas). */
function fin_rc4(string $key, string $data): string {
    $s = range(0, 255); $j = 0; $kl = strlen($key);
    if ($kl === 0) return $data;
    for ($i = 0; $i < 256; $i++) { $j = ($j + $s[$i] + ord($key[$i % $kl])) & 255; [$s[$i], $s[$j]] = [$s[$j], $s[$i]]; }
    $i = $j = 0; $out = ''; $n = strlen($data);
    for ($k = 0; $k < $n; $k++) {
        $i = ($i + 1) & 255; $j = ($j + $s[$i]) & 255; [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        $out .= chr(ord($data[$k]) ^ $s[($s[$i] + $s[$j]) & 255]);
    }
    return $out;
}

/** O hash das palavras-passe do AES-256 (R5: SHA-256; R6: o algoritmo 2.B da ISO 32000-2). */
function fin_pdf_hash6(string $pwd, string $salt, string $udata, int $R): string {
    $k = hash('sha256', $pwd . $salt . $udata, true);
    if ($R < 6) return $k;
    for ($i = 0; ; ) {
        $k1 = str_repeat($pwd . $k . $udata, 64);
        $e = (string)openssl_encrypt($k1, 'aes-128-cbc', substr($k, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));
        $sum = 0; for ($j = 0; $j < 16; $j++) $sum += ord($e[$j]);
        $k = hash(['sha256', 'sha384', 'sha512'][$sum % 3], $e, true);
        $i++;
        if ($i >= 64 && ord($e[strlen($e) - 1]) <= $i - 32) break;
        if ($i > 1000) break;
    }
    return substr($k, 0, 32);
}

/** Os streams de um PDF: [dicionário, dados em bruto]. (Os PDFs cifrados sem palavra-passe são decifrados antes.) */
function fin_pdf_streams(string $pdf): array {
    $pdf = fin_pdf_decrypt($pdf);
    if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $pdf)) return [];
    $out = [];
    if (!preg_match_all('/>>\s*stream\r?\n/', $pdf, $mm, PREG_OFFSET_CAPTURE)) return [];
    foreach ($mm[0] as [$tok, $at]) {
        // o dicionário: do último «n g obj» antes de «stream» até aqui
        $from = max(0, $at - 8000);
        if (!preg_match_all('/\d+\s+\d+\s+obj\b/', substr($pdf, $from, $at - $from), $oo, PREG_OFFSET_CAPTURE)) continue;
        $last = end($oo[0]);
        $dict = substr($pdf, $from + $last[1] + strlen($last[0]), $at - $from - $last[1] - strlen($last[0]) + 2);
        $start = $at + strlen($tok);
        $len = preg_match('/\/Length\s+(\d+)(?!\s+\d+\s+R)/', $dict, $l) ? (int)$l[1] : -1;
        if ($len >= 0 && preg_match('/^\s*endstream/', (string)substr($pdf, $start + $len, 30))) $data = substr($pdf, $start, $len);
        else {
            $end = strpos($pdf, 'endstream', $start);
            if ($end === false) continue;
            $data = rtrim(substr($pdf, $start, $end - $start), "\r\n");
        }
        $out[] = [$dict, $data];
        if (count($out) > 3000) break;
    }
    return $out;
}

/** Os filtros de um stream, por ordem. */
function fin_pdf_filters(string $dict): array {
    if (preg_match('/\/Filter\s*\[([^\]]*)\]/', $dict, $m)) { preg_match_all('/\/(\w+)/', $m[1], $f); return $f[1]; }
    return preg_match('/\/Filter\s*\/(\w+)/', $dict, $m) ? [$m[1]] : [];
}

/** Aplica os filtros até ao primeiro que não sei (DCT, JBIG2, CCITT…): [dados, filtro que ficou ou '']. */
function fin_pdf_decode(string $dict, string $data): array {
    foreach (fin_pdf_filters($dict) as $f) {
        switch ($f) {
            case 'FlateDecode': case 'Fl':
                $d = @gzuncompress($data);
                if ($d === false) $d = @gzinflate(substr($data, 2));
                if ($d === false) return ['', 'error'];
                $data = fin_pdf_predictor($dict, $d);
                break;
            case 'ASCIIHexDecode': case 'AHx':
                $h = preg_replace('/[^0-9A-Fa-f]/', '', strstr($data . '>', '>', true));
                $data = (string)hex2bin(strlen($h) % 2 ? $h . '0' : $h);
                break;
            case 'ASCII85Decode': case 'A85':
                $data = fin_a85($data);
                break;
            case 'RunLengthDecode': case 'RL':
                $o = ''; $i = 0; $n = strlen($data);
                while ($i < $n) {
                    $c = ord($data[$i++]);
                    if ($c === 128) break;
                    if ($c < 128) { $o .= substr($data, $i, $c + 1); $i += $c + 1; }
                    else { $o .= str_repeat($data[$i] ?? '', 257 - $c); $i++; }
                }
                $data = $o;
                break;
            default:
                return [$data, $f];
        }
    }
    return [$data, ''];
}

function fin_a85(string $s): string {
    $s = preg_replace('/\s+/', '', $s);
    if (str_starts_with($s, '<~')) $s = substr($s, 2);
    $p = strpos($s, '~>'); if ($p !== false) $s = substr($s, 0, $p);
    $out = ''; $tuple = []; $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        if ($s[$i] === 'z' && !$tuple) { $out .= "\0\0\0\0"; continue; }
        $tuple[] = ord($s[$i]) - 33;
        if (count($tuple) === 5) {
            $v = 0; foreach ($tuple as $t) $v = $v * 85 + $t;
            $out .= pack('N', $v & 0xFFFFFFFF); $tuple = [];
        }
    }
    if ($tuple) {
        $k = count($tuple);
        while (count($tuple) < 5) $tuple[] = 84;
        $v = 0; foreach ($tuple as $t) $v = $v * 85 + $t;
        $out .= substr(pack('N', $v & 0xFFFFFFFF), 0, $k - 1);
    }
    return $out;
}

/** Desfaz o preditor PNG (Predictor ≥ 10) do Flate. */
function fin_pdf_predictor(string $dict, string $d): string {
    if (!preg_match('/\/Predictor\s+(\d+)/', $dict, $m) || (int)$m[1] < 10) return $d;
    $colors = preg_match('/\/Colors\s+(\d+)/', $dict, $c) ? (int)$c[1] : 1;
    $bpc = preg_match('/\/DecodeParms\s*<<[^>]*\/BitsPerComponent\s+(\d+)/', $dict, $b) ? (int)$b[1] : 8;
    $cols = preg_match('/\/Columns\s+(\d+)/', $dict, $c2) ? (int)$c2[1] : 1;
    $bpp = max(1, intdiv($colors * $bpc + 7, 8));
    $row = intdiv($colors * $bpc * $cols + 7, 8);
    $out = ''; $prev = str_repeat("\0", $row); $n = strlen($d);
    for ($p = 0; $p + $row < $n + 1 && $p < $n; $p += $row + 1) {
        $t = ord($d[$p]); $cur = substr($d, $p + 1, $row);
        if (strlen($cur) < $row) $cur = str_pad($cur, $row, "\0");
        $r = '';
        for ($i = 0; $i < $row; $i++) {
            $x = ord($cur[$i]); $a = $i >= $bpp ? ord($r[$i - $bpp]) : 0; $up = ord($prev[$i]); $ul = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
            switch ($t) {
                case 1: $x += $a; break;
                case 2: $x += $up; break;
                case 3: $x += intdiv($a + $up, 2); break;
                case 4: $pp = $a + $up - $ul; $pa = abs($pp - $a); $pb = abs($pp - $up); $pc = abs($pp - $ul);
                        $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $up : $ul); break;
            }
            $r .= chr($x & 0xFF);
        }
        $out .= $r; $prev = $r;
    }
    return $out;
}

/**
 * O QR dentro de um PDF. Por ordem: o texto do QR presente no PDF, os quadrados vetoriais, as imagens
 * pequenas e quadradas (o QR costuma ser uma imagem à parte) e, por fim, as páginas digitalizadas.
 */
function fin_pdf_qr(string $pdf, float $budget = 12.0): ?string {
    $t0 = microtime(true);
    $pdf = fin_pdf_decrypt($pdf);
    $streams = fin_pdf_streams($pdf);
    $images = []; $content = [];
    foreach ($streams as [$dict, $data]) {
        if (preg_match('/\/Subtype\s*\/Image/', $dict)) $images[] = [$dict, $data];
        elseif (!preg_match('/\/(Type\s*\/(XRef|ObjStm|Metadata|EmbeddedFile)|Length1|Subtype\s*\/(Type1C|CIDFontType0C|OpenType))\b/', $dict)) {
            [$d, $left] = fin_pdf_decode($dict, $data);
            if ($left === '' && $d !== '') $content[] = $d;
        }
    }
    // 1. o texto do QR no próprio PDF (alguns programas escrevem-no, por baixo da imagem)
    foreach (array_merge($content, [$pdf]) as $c) {
        if (preg_match('/A:\d{9}\*B:[^*\s()]{1,20}\*C:[A-Z]{2}\*D:[A-Z]{2}\*[^()<>\r\n]+/', $c, $m) && fin_qr_fields(rtrim($m[0]))) return rtrim($m[0]);
    }
    // 2. QR vetorial (um quadrado por módulo, ou corridas de módulos)
    foreach ($content as $c) {
        if (microtime(true) - $t0 > $budget) return null;
        $q = fin_pdf_vector_qr($c);
        if ($q !== null) return $q;
    }
    // 3. imagens: primeiro as pequenas e quase quadradas, depois as grandes (páginas digitalizadas)
    $cand = [];
    foreach ($images as [$dict, $data]) {
        $w = preg_match('/\/Width\s+(\d+)/', $dict, $m) ? (int)$m[1] : 0;
        $h = preg_match('/\/Height\s+(\d+)/', $dict, $m) ? (int)$m[1] : 0;
        if ($w < 21 || $h < 21 || $w > 8000 || $h > 8000) continue;
        $ar = $w / $h;
        $cand[] = [$ar > 0.8 && $ar < 1.25 ? 0 : 1, $w * $h, $dict, $data, $w, $h];
    }
    usort($cand, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    foreach (array_slice($cand, 0, 12) as [, , $dict, $data, $w, $h]) {
        if (microtime(true) - $t0 > $budget) return null;
        $q = fin_pdf_image_qr($dict, $data, $w, $h);
        if ($q !== null) return $q;
    }
    return null;
}

/**
 * O texto de um PDF (sem programas externos): os operadores Tj/TJ das páginas, com os mapas ToUnicode das fontes
 * (as faturas de programas como o FastReport escrevem os glifos em hexadecimal, ilegíveis sem o mapa). Serve para
 * ler NIFs, nomes, total e número quando o QR não chega; PDFs digitalizados (só imagem) não têm texto: vão à IA.
 */
function fin_pdf_text(string $pdf, int $max = 60000): string {
    $pdf = fin_pdf_decrypt($pdf);
    if (preg_match('/\/Encrypt\s+\d+\s+\d+\s+R/', $pdf)) return '';
    // os objetos: «n 0 obj … endobj»
    $objs = [];
    if (preg_match_all('/(\d+)\s+\d+\s+obj\b/', $pdf, $mm, PREG_OFFSET_CAPTURE)) {
        foreach ($mm[0] as $k => [$tok, $at]) {
            $end = strpos($pdf, 'endobj', $at);
            if ($end === false) continue;
            $objs[(int)$mm[1][$k][0]] = substr($pdf, $at + strlen($tok), $end - $at - strlen($tok));
            if (count($objs) > 4000) break;
        }
    }
    $stream = function (string $body): string {
        if (!preg_match('/^(.*?)>>\s*stream\r?\n(.*)$/s', $body, $m)) return '';
        $data = preg_replace('/\r?\n?endstream\s*$/s', '', rtrim($m[2]));
        [$d, $left] = fin_pdf_decode($m[1] . '>>', $data);
        return $left === '' ? $d : '';
    };
    // fontes com ToUnicode: nome do recurso (/F0) → mapa [código → texto] e largura do código (1 ou 2 bytes)
    $cmaps = [];
    $parse = function (string $c): array {
        $map = []; $two = (bool)preg_match('/begincodespacerange\s*<[0-9A-Fa-f]{4}>/', $c);
        $u = fn(string $h) => (string)@mb_convert_encoding((string)hex2bin(strlen($h) % 2 ? '0' . $h : $h), 'UTF-8', 'UTF-16BE');
        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $c, $bb)) foreach ($bb[1] as $blk) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>/', $blk, $pp, PREG_SET_ORDER);
            foreach ($pp as $p) $map[hexdec($p[1])] = $u($p[2]);
        }
        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $c, $bb)) foreach ($bb[1] as $blk) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]+>|\[[^\]]*\])/', $blk, $pp, PREG_SET_ORDER);
            foreach ($pp as $p) {
                $lo = hexdec($p[1]); $hi = min(hexdec($p[2]), $lo + 2000);
                if ($p[3][0] === '[') { preg_match_all('/<([0-9A-Fa-f]+)>/', $p[3], $ll); foreach ($ll[1] as $i => $h) $map[$lo + $i] = $u($h); }
                else { $base = hexdec(trim($p[3], '<>')); for ($c2 = $lo; $c2 <= $hi; $c2++) $map[$c2] = (string)@mb_convert_encoding(pack('n', $base + $c2 - $lo), 'UTF-8', 'UTF-16BE'); }
            }
        }
        return [$map, $two];
    };
    foreach ($objs as $body) {
        if (!preg_match('/\/Font\s*<<(.*?)>>/s', $body, $fm)) continue;
        preg_match_all('/\/([\w.+-]+)\s+(\d+)\s+\d+\s+R/', $fm[1], $ff, PREG_SET_ORDER);
        foreach ($ff as [, $name, $num]) {
            if (isset($cmaps[$name]) || !isset($objs[(int)$num])) continue;
            $cmaps[$name] = preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $objs[(int)$num], $tu) && isset($objs[(int)$tu[1]]) ? $parse($stream($objs[(int)$tu[1]])) : [[], false];
        }
    }
    $lit = function (string $s): string {
        return preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', fn($m) => ['n' => "\n", 'r' => '', 't' => ' ', 'b' => '', 'f' => '', '(' => '(', ')' => ')', '\\' => '\\'][$m[1]] ?? chr(octdec($m[1])), $s);
    };
    $out = '';
    foreach ($objs as $body) {
        if (strpos($body, 'stream') === false || preg_match('/\/(Subtype\s*\/Image|Type\s*\/(XRef|ObjStm|Metadata)|Length1|FontFile)/', $body)) continue;
        $c = $stream($body);
        if ($c === '' || !preg_match('/\b(Tj|TJ)\b|\)\s*\'/', $c)) continue;
        $c = preg_replace('/\bBI\b.*?\bID\s.*?\sEI\b/s', ' ', $c);   // imagens embutidas no conteúdo (binário com parênteses soltos)
        $font = [[], false];
        preg_match_all('/\/([\w.+-]+)\s+[\d.]+\s+Tf|(<[0-9A-Fa-f\s]*>|\((?:\\\\.|[^\\\\)])*\))\s*(Tj|\'|")|\[((?:[^\]\\\\]|\\\\.)*)\]\s*TJ|(\bET\b|\bTm\b|T\*)|(-?[\d.]+)\s+(-?[\d.]+)\s+T[dD]\b/s', $c, $ops, PREG_SET_ORDER);
        foreach ($ops as $o) {
            if (($o[1] ?? '') !== '') { $font = $cmaps[$o[1]] ?? [[], false]; continue; }
            // mudança de linha (T*, Tm, ET, ou Td com deslocamento vertical); Td só na horizontal é um espaço
            if (($o[5] ?? '') !== '' || (($o[7] ?? '') !== '' && abs((float)$o[7]) > 0.01)) { if (!str_ends_with($out, "\n")) $out .= "\n"; continue; }
            if (($o[6] ?? '') !== '') { if (!str_ends_with($out, "\n") && !str_ends_with($out, ' ')) $out .= ' '; continue; }
            if (in_array($o[3] ?? '', ["'", '"'], true) && !str_ends_with($out, "\n")) $out .= "\n";   // «'» e «"» passam à linha seguinte
            $parts = ($o[2] ?? '') !== '' ? [$o[2]] : [];
            if (($o[4] ?? '') !== '') { preg_match_all('/<[0-9A-Fa-f\s]*>|\((?:\\\\.|[^\\\\)])*\)|-?\d+\.?\d*/', $o[4], $pp); $parts = $pp[0]; }
            foreach ($parts as $p) {
                if ($p[0] !== '<' && $p[0] !== '(') { if ((float)$p < -200) $out .= ' '; continue; }
                $bytes = $p[0] === '<' ? (string)hex2bin(preg_replace('/\s+/', '', trim($p, '<>')) . (strlen(preg_replace('/\s+/', '', trim($p, '<>'))) % 2 ? '0' : '')) : $lit(substr($p, 1, -1));
                [$map, $two] = $font;
                if (!$map) { $out .= (string)@mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252'); continue; }
                $step = $two ? 2 : 1;
                for ($i = 0; $i + $step <= strlen($bytes); $i += $step) $out .= $map[$step === 2 ? unpack('n', substr($bytes, $i, 2))[1] : ord($bytes[$i])] ?? '';
            }
            if (strlen($out) > $max) break 2;
        }
        $out .= "\n";
    }
    return trim(preg_replace("/[ \\t]+/", ' ', preg_replace("/\\n{3,}/", "\n\n", $out)));
}

/** Um valor «1 234,56» ou «1234.56» → número. */
function fin_text_amount(string $v): float {
    $v = preg_replace('/[^\d,.]/', '', $v);
    if (preg_match('/,\d{2}$/', $v)) $v = str_replace([' ', '.', ','], ['', '', '.'], $v);
    else $v = str_replace(',', '', $v);
    return round((float)$v, 2);
}

/**
 * Os campos de uma fatura lidos do texto do PDF (sem QR nem IA): NIFs válidos, o da empresa como emitente ou
 * adquirente (também pelo nome legal), número, ATCUD, data, total e IVA. Confiança 0,6–0,75: fica sempre por confirmar.
 */
function fin_text_invoice(string $text, string $ourNif = '', array $ourNames = []): ?array {
    if (mb_strlen($text) < 40) return null;
    $flat = preg_replace('/\s+/', ' ', $text);
    $norm = fn(string $s) => strtolower(preg_replace('/[^a-z0-9 ]/i', '', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s)));
    $nifs = [];
    if (preg_match_all('/(?<!\d)(?:PT\s?)?(\d{3}\s?\d{3}\s?\d{3})(?!\d)/', $flat, $mm, PREG_OFFSET_CAPTURE)) foreach ($mm[1] as [$n, $at]) {
        if (preg_match('/(tel|telef|telem|fax|tlm|phone|móvel|movel)[^\d]{0,15}$/iu', substr($flat, max(0, $at - 20), 20))) continue;   // telefones
        $n = preg_replace('/\D/', '', $n);
        if (fin_nif_ok($n) && !in_array($n, $nifs, true)) $nifs[] = $n;
    }
    // os NIFs com rótulo colado («NIF: …», «NIPC …», «Contribuinte n.º …»): o do cabeçalho é o do emitente
    $labeled = [];
    if (preg_match_all('/\b(?:NIF|NIPC|N\.?\s?º?\s?(?:de\s)?contribuinte|contribuinte\s?n\.?\s?º?|VAT(?:\s?n[or]\.?)?)\s*:?\s*(?:PT\s?)?(\d{3}\s?\d{3}\s?\d{3})(?!\d)/iu', $flat, $lm)) {
        foreach ($lm[1] as $n) { $n = preg_replace('/\D/', '', $n); if (fin_nif_ok($n) && !in_array($n, $labeled, true)) $labeled[] = $n; }
    }
    $ours = $ourNif !== '' && in_array($ourNif, $nifs, true);
    $flatN = $norm($flat);
    $named = false;
    foreach ($ourNames as $nm) { $k = trim(preg_replace('/\b(lda|sa|unipessoal)\b/', '', $norm($nm))); if (strlen($k) >= 5 && str_contains($flatN, $k)) { $named = true; break; } }
    $others = array_values(array_diff($nifs, [$ourNif]));
    $othersLab = array_values(array_diff($labeled, [$ourNif]));
    // quem emite: pelo contexto de cada NIF (capital social, conservatória, IBAN, sede → emitente; Exmo., cliente,
    // adquirente, contribuinte → cliente); empatado, um NIF de outra entidade com rótulo → somos o adquirente
    $side = function (string $nif) use ($flat): int {
        $sc = 0;
        if (preg_match_all('/' . substr($nif, 0, 3) . '\s?' . substr($nif, 3, 3) . '\s?' . substr($nif, 6) . '/', $flat, $oo, PREG_OFFSET_CAPTURE)) foreach ($oo[0] as [, $at]) {
            $w = mb_strtolower(substr($flat, max(0, $at - 160), 320));
            $sc += preg_match_all('/capital social|conservat|registo comercial|c\.\s?r\.\s?c|\biban\b|\bsede\b|www\.|e-?mail|matr[ií]cula/u', $w);
            $sc -= preg_match_all('/exmo|cliente|adquirente|contribuinte|v\/\s?n|destinat/u', $w);
        }
        return $sc;
    };
    $weIssue = false;
    if ($ours) {
        $us = $side($ourNif);
        $them = $others ? max(array_map($side, $others)) : -99;
        $weIssue = $us !== $them ? $us > $them : (!$othersLab && in_array($ourNif, $labeled, true));
    }
    $amt = '(\d{1,3}(?:[ .]\d{3})*,\d{2}|\d+\.\d{2})';
    $total = 0.0;
    if (preg_match_all('/total(?:\s+(?:documento|a pagar|geral|final|do documento))?\s*:?\s*(?:€|eur(?:os)?)?\s*' . $amt . '/i', $flat, $tm)) $total = fin_text_amount(end($tm[1]));
    // base e IVA: o par de valores do documento que soma o total a uma taxa legal
    $vals = [];
    if (preg_match_all('/(?<![\d,.])' . $amt . '(?![\d])/', $flat, $am2)) foreach ($am2[1] as $v) $vals[(string)fin_text_amount($v)] = true;
    $base = $total; $vat = 0.0; $rate = 0;
    if ($total > 0) foreach (array_keys($vals) as $bv) {
        $bv = (float)$bv;
        foreach (FIN_VAT_RATES as $r) {
            $v = round($bv * $r / 100, 2);
            if ($bv > 0 && abs($bv + $v - $total) < 0.02 && isset($vals[(string)$v])) { $base = $bv; $vat = $v; $rate = $r; break 2; }
        }
    }
    $number = preg_match('/\b(FT|FR|FS|NC|ND|RC|RG)\s?[A-Z0-9.]*\s?\d*\/\d+/', $flat, $nm) ? trim($nm[0]) : '';
    $atcud = preg_match('/ATCUD\s*:?\s*([A-Z0-9]{8,}-\d+)/i', $flat, $am) ? $am[1] : '';
    // datas: a mais antiga é a de emissão, a mais recente (se houver) o vencimento
    $dates = [];
    if (preg_match_all('/\b(\d{2})[-\/.](\d{2})[-\/.](20\d{2})\b/', $flat, $dm, PREG_SET_ORDER)) foreach ($dm as $d) if (checkdate((int)$d[2], (int)$d[1], (int)$d[3])) $dates[] = "$d[3]-$d[2]-$d[1]";
    if (preg_match_all('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $flat, $dm, PREG_SET_ORDER)) foreach ($dm as $d) if (checkdate((int)$d[2], (int)$d[3], (int)$d[1])) $dates[] = $d[0];
    sort($dates);
    $date = $dates[0] ?? '';
    $due = count($dates) > 1 && end($dates) !== $date ? end($dates) : '';
    if (!$nifs && !$named && $total <= 0) return null;
    $code = $number !== '' ? substr($number, 0, 2) : '';
    $kind = FIN_QR_DOC_KIND[$code] ?? (preg_match('/nota de cr[eé]dito/i', $flat) ? 'credit_note' : (preg_match('/\brecibo\b/i', $flat) && !preg_match('/fatura|factura/i', $flat) ? 'receipt' : 'invoice'));
    // o nome da outra entidade: a primeira linha com forma de sociedade que não seja a nossa
    $otherName = '';
    foreach (preg_split('/\R/', $text) as $ln) {
        $ln = trim($ln);
        if (mb_strlen($ln) < 4 || mb_strlen($ln) > 120 || !preg_match('/\b(Lda\.?|L\.da|S\.?\s?A\.?|Unipessoal|SGPS|Sociedade)\b/iu', $ln)) continue;
        $isOurs = false;
        foreach ($ourNames as $nm) { $k = trim(preg_replace('/\b(lda|sa|unipessoal)\b/', '', $norm($nm))); if (strlen($k) >= 5 && str_contains($norm($ln), $k)) $isOurs = true; }
        if (!$isOurs) { $otherName = $ln; break; }
    }
    $client = $weIssue ? ($others[0] ?? '') : ($ours ? $ourNif : '');
    $iss = $weIssue ? $ourNif : ($othersLab[0] ?? ($others[0] ?? ''));
    $conf = 0.6 + ($iss !== '' && ($client !== '' || $named) ? 0.1 : 0) + ($total > 0 ? 0.05 : 0);
    return ['source' => 'text', 'kind' => $kind, 'doc_code' => $code, 'issuer_name' => $weIssue ? (string)($ourNames[0] ?? '') : $otherName, 'issuer_nif' => $iss,
            'client_name' => $weIssue ? $otherName : ($named || $ours ? (string)($ourNames[0] ?? '') : ''), 'client_nif' => $client,
            'client_country' => 'PT', 'number' => mb_substr($number, 0, 60), 'atcud' => $atcud, 'issue_date' => $date, 'due_date' => $due, 'base' => $base,
            'vat_rate' => $rate, 'vat' => $vat, 'total' => $total, 'withholding' => 0, 'stamp' => 0, 'currency' => 'EUR',
            'description' => '', 'confidence' => min(0.75, $conf), 'named_us' => $named, 'we_issue' => $weIssue];
}

/** Uma imagem de um PDF → o QR, se tiver. */
function fin_pdf_image_qr(string $dict, string $data, int $w, int $h): ?string {
    [$raw, $left] = fin_pdf_decode($dict, $data);
    if ($left === 'DCTDecode' || $left === 'DCT') return fin_img_qr($raw);
    if ($left !== '' || $raw === '') return null;   // JPX, JBIG2, CCITT: fica para a IA
    $mask = (bool)preg_match('/\/ImageMask\s+true/', $dict);
    $bpc = $mask ? 1 : (preg_match('/\/BitsPerComponent\s+(\d+)/', $dict, $m) ? (int)$m[1] : 8);
    if (!in_array($bpc, [1, 2, 4, 8, 16], true)) return null;
    // componentes pelo tamanho dos dados (dispensa resolver o espaço de cor)
    $rowBytes = fn(int $c) => intdiv($w * $c * $bpc + 7, 8);
    $comps = 0;
    foreach ([1, 3, 4, 2] as $c) if (strlen($raw) >= $rowBytes($c) * $h && strlen($raw) < $rowBytes($c) * $h * 1.05 + 16) { $comps = $c; break; }
    if (!$comps) return null;
    $rb = $rowBytes($comps);
    $lum = [];
    $max = (1 << min($bpc, 8)) - 1;
    for ($y = 0; $y < $h; $y++) {
        $row = substr($raw, $y * $rb, $rb);
        $line = '';
        for ($x = 0; $x < $w; $x++) {
            if ($bpc === 8) { $o = $x * $comps; $v = $comps >= 3 ? intdiv(ord($row[$o]) * 30 + ord($row[$o + 1]) * 59 + ord($row[$o + 2]) * 11, 100) : ord($row[$o]); if ($comps === 4) $v = 255 - min(255, intdiv(ord($row[$o]) + ord($row[$o + 1]) + ord($row[$o + 2]), 3) + ord($row[$o + 3])); }
            elseif ($bpc === 16) { $v = ord($row[$x * $comps * 2]); }
            else { $bit = $x * $comps * $bpc; $byte = ord($row[$bit >> 3]); $v = (($byte >> (8 - $bpc - ($bit & 7))) & $max) * 255 / $max; }
            $line .= chr((int)$v);
        }
        $lum[] = $line;
    }
    $inv = (bool)preg_match('/\/Decode\s*\[\s*1(\.0)?\s+0/', $dict);   // máscara: 0 pinta (escuro), salvo /Decode [1 0]
    return FinQr::fromLum($lum, $w, $h, $inv);
}

/** Um QR desenhado com retângulos («x y w h re») num stream de conteúdo. */
function fin_pdf_vector_qr(string $c): ?string {
    $rects = [];
    if (substr_count($c, ' re') >= 60 && preg_match_all('/(-?\d*\.?\d+)\s+(-?\d*\.?\d+)\s+(-?\d*\.?\d+)\s+(-?\d*\.?\d+)\s+re\b/', $c, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $m) {
            [$x, $y, $w, $h] = [(float)$m[1], (float)$m[2], (float)$m[3], (float)$m[4]];
            if ($w < 0) { $x += $w; $w = -$w; }
            if ($h < 0) { $y += $h; $h = -$h; }
            if ($w > 0 && $h > 0) $rects[] = [$x, $y, $w, $h];
        }
    }
    // módulos desenhados como polígonos de 4 cantos («x y m  x y l  x y l  x y l  h  f*», ex.: FastReport)
    if (preg_match_all('/(?:^|\s)m\b/', $c) >= 60) {
        $n = '(-?\d*\.?\d+)';
        if (preg_match_all('/' . $n . '\s+' . $n . '\s+m\s+' . $n . '\s+' . $n . '\s+l\s+' . $n . '\s+' . $n . '\s+l\s+' . $n . '\s+' . $n . '\s+l\s+(?:' . $n . '\s+' . $n . '\s+l\s+)?h\s+f\*?/', $c, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $xs = [(float)$m[1], (float)$m[3], (float)$m[5], (float)$m[7]];
                $ys = [(float)$m[2], (float)$m[4], (float)$m[6], (float)$m[8]];
                $ux = array_unique(array_map(fn($v) => round($v, 2), $xs)); $uy = array_unique(array_map(fn($v) => round($v, 2), $ys));
                if (count($ux) !== 2 || count($uy) !== 2) continue;   // só retângulos alinhados
                $rects[] = [min($xs), min($ys), max($xs) - min($xs), max($ys) - min($ys)];
            }
        }
    }
    if (count($rects) < 60) return null;
    // o módulo: o lado menor mais frequente
    $hist = [];
    foreach ($rects as $r) { $k = (string)round(min($r[2], $r[3]), 2); $hist[$k] = ($hist[$k] ?? 0) + 1; }
    arsort($hist);
    foreach (array_slice($hist, 0, 3, true) as $mod => $n) {
        $mod = (float)$mod;
        if ($n < 40 || $mod <= 0) continue;
        $cells = [];
        foreach ($rects as [$x, $y, $w, $h]) {
            $kw = $w / $mod; $kh = $h / $mod;
            if (abs($kw - round($kw)) > 0.12 || abs($kh - round($kh)) > 0.12 || (round($kw) > 1 && round($kh) > 1) || round($kw) > 200 || round($kh) > 200) continue;
            $cells[] = [$x, $y, (int)round($kw), (int)round($kh)];
        }
        if (count($cells) < 40) continue;
        $x0 = min(array_column($cells, 0)); $y0 = min(array_column($cells, 1));
        // o passo real: os padrões dos cantos dão o tamanho do QR; com módulos arredondados ao desenhar
        // (ex.: 1,71 e 1,78), contar pelo módulo mais frequente acumula erro de um lado ao outro
        $x1 = $x0; $y1 = $y0;
        foreach ($cells as [$x, $y, $kw, $kh]) { $x1 = max($x1, $x + $kw * $mod); $y1 = max($y1, $y + $kh * $mod); }
        $span = max($x1 - $x0, $y1 - $y0);
        $nMod = (int)round($span / $mod);
        foreach ([0, 1, -1, 2, -2] as $dn) if (($nMod + $dn - 17) % 4 === 0 && $nMod + $dn >= 21) { $nMod += $dn; break; }
        // com o tamanho certo, cada módulo lê-se pelo seu centro (aguenta os módulos de 1,71 e 1,78)
        if ($nMod >= 21 && ($nMod - 17) % 4 === 0 && abs($span / $nMod - $mod) / $mod < 0.08) {
            $px = ($x1 - $x0) / $nMod; $py = ($y1 - $y0) / $nMod;
            $g = array_fill(0, $nMod, str_repeat("\0", $nMod));
            foreach ($rects as [$x, $y, $w, $h]) {
                if ($x < $x0 - $px / 2 || $y < $y0 - $py / 2 || $x + $w > $x1 + $px / 2 || $y + $h > $y1 + $py / 2 || $w > $span * 0.9) continue;
                for ($yy = $y + $py / 2; $yy < $y + $h; $yy += $py) for ($xx = $x + $px / 2; $xx < $x + $w; $xx += $px) {
                    $i = (int)floor(($xx - $x0) / $px); $j = $nMod - 1 - (int)floor(($yy - $y0) / $py);
                    if ($i >= 0 && $i < $nMod && $j >= 0 && $j < $nMod) $g[$j][$i] = "\1";
                }
            }
            foreach ([$g, array_reverse($g)] as $mtx) { $r = FinQr::decodeMatrix($mtx); if ($r !== null) return $r; }
        }
        $cols = 0; $rows = 0;
        foreach ($cells as [$x, $y, $kw, $kh]) { $cols = max($cols, (int)round(($x - $x0) / $mod) + $kw); $rows = max($rows, (int)round(($y - $y0) / $mod) + $kh); }
        if ($cols < 21 || $rows < 21 || $cols > 400 || $rows > 400) continue;
        $g = array_fill(0, $rows, str_repeat("\0", $cols));
        foreach ($cells as [$x, $y, $kw, $kh]) {
            $cx = (int)round(($x - $x0) / $mod); $cy = (int)round(($y - $y0) / $mod);
            for ($j = 0; $j < $kh; $j++) for ($i = 0; $i < $kw; $i++) $g[$rows - 1 - ($cy + $j)][$cx + $i] = "\1";   // o PDF conta o y de baixo para cima
        }
        if ($cols === $rows && ($cols - 17) % 4 === 0) {
            foreach ([$g, array_reverse($g)] as $mtx) { $r = FinQr::decodeMatrix($mtx); if ($r !== null) return $r; }
        }
        // outros retângulos à mistura: vai pela deteção na imagem
        foreach ([$g, array_reverse($g)] as $mtx) { $r = FinQr::fromBits($mtx, $cols, $rows, 4, 4); if ($r !== null) return $r; }
    }
    return null;
}

// ── o descodificador ────────────────────────────────────────

final class FinQr {
    private static array $exp = [];
    private static array $log = [];

    /** Nº de blocos e de palavras de correção por bloco, por nível (L, M, Q, H) e versão. */
    private const ECC_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];
    private const NUM_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    // ── imagem → linhas binárias ("\1" escuro) ──

    /** Uma imagem GD. Reduz as grandes; tenta o limiar global (Otsu), depois o local, depois invertido. */
    public static function fromGd(\GdImage $im): ?string {
        $w = imagesx($im); $h = imagesy($im);
        $max = 2000;
        if (max($w, $h) > $max) {
            $s = $max / max($w, $h);
            $sm = imagescale($im, max(1, (int)round($w * $s)), max(1, (int)round($h * $s)), IMG_BILINEAR_FIXED);
            if (!$sm) return null;
            try { return self::fromGd($sm); } finally { imagedestroy($sm); }
        }
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        $lum = self::gdLum($im, $w, $h);
        if (!$lum) return null;
        return self::fromLum($lum, $w, $h, false);
    }

    /** Os píxeis em luminância: pelo BMP sem compressão (rápido); senão píxel a píxel. Transparente = branco. */
    private static function gdLum(\GdImage $im, int $w, int $h): array {
        $lum = [];
        if (function_exists('imagebmp')) {
            imagealphablending($im, false);
            ob_start(); $okb = @imagebmp($im, null, false); $bmp = (string)ob_get_clean();
            if ($okb && strlen($bmp) > 54 && substr($bmp, 0, 2) === 'BM') {
                $u = unpack('Voff', substr($bmp, 10, 4)) + unpack('Vhs/lw/lh/vplanes/vbpp/Vcomp', substr($bmp, 14, 20));
                if (in_array($u['bpp'], [24, 32], true) && $u['comp'] === 0 && $u['w'] === $w && abs($u['h']) === $h) {
                    $bp = $u['bpp'] >> 3; $stride = ($w * $bp + 3) & ~3; $up = $u['h'] > 0;
                    for ($y = 0; $y < $h; $y++) {
                        $row = substr($bmp, $u['off'] + ($up ? $h - 1 - $y : $y) * $stride, $w * $bp);
                        if (strlen($row) < $w * $bp) return [];
                        $line = '';
                        for ($x = 0, $o = 0; $x < $w; $x++, $o += $bp) $line .= chr(intdiv(ord($row[$o + 2]) * 30 + ord($row[$o + 1]) * 59 + ord($row[$o]) * 11, 100));
                        $lum[] = $line;
                    }
                    return $lum;
                }
            }
        }
        if ($w * $h > 4000000) return [];
        for ($y = 0; $y < $h; $y++) {
            $line = '';
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $line .= (($c >> 24) & 0x7F) > 60 ? "\xFF" : chr(intdiv((($c >> 16) & 255) * 30 + (($c >> 8) & 255) * 59 + ($c & 255) * 11, 100));
            }
            $lum[] = $line;
        }
        return $lum;
    }

    /** Luminâncias (uma string por linha, um byte por píxel). */
    public static function fromLum(array $lum, int $w, int $h, bool $inverted): ?string {
        // QR pequeno (1–2 píxeis por módulo, sem margem): aumenta e junta a zona de silêncio
        $scale = max(1, (int)ceil(160 / max(1, min($w, $h))));
        $t = self::otsu($lum);
        $bin = self::binarize($lum, $t, $inverted);
        $r = self::fromBits($bin, $w, $h, $scale, 4 * $scale);
        if ($r !== null) return $r;
        $r = self::fromBits(self::invert($bin), $w, $h, $scale, 4 * $scale);
        if ($r !== null) return $r;
        if ($w * $h > 40000) {   // limiar local (digitalizações com sombras)
            $bin = self::adaptive($lum, $w, $h, $inverted);
            return self::fromBits($bin, $w, $h, 1, 0);
        }
        return null;
    }

    private static function otsu(array $lum): int {
        $hist = array_fill(0, 256, 0); $n = 0;
        foreach ($lum as $l) { foreach (count_chars($l, 1) as $v => $c) $hist[$v] += $c; $n += strlen($l); }
        $sum = 0; foreach ($hist as $v => $c) $sum += $v * $c;
        $sumB = 0; $wB = 0; $best = 0; $t = 128;
        for ($v = 0; $v < 256; $v++) {
            $wB += $hist[$v]; if (!$wB) continue;
            $wF = $n - $wB; if (!$wF) break;
            $sumB += $v * $hist[$v];
            $mB = $sumB / $wB; $mF = ($sum - $sumB) / $wF;
            $between = $wB * $wF * ($mB - $mF) ** 2;
            if ($between > $best) { $best = $between; $t = $v; }
        }
        return $t;
    }

    private static function binarize(array $lum, int $t, bool $inv): array {
        $map = ''; $all = '';
        for ($v = 0; $v < 256; $v++) { $map .= ($v <= $t) !== $inv ? "\1" : "\0"; $all .= chr($v); }
        return array_map(fn($l) => strtr($l, $all, $map), $lum);
    }

    private static function invert(array $bin): array {
        return array_map(fn($l) => strtr($l, "\0\1", "\1\0"), $bin);
    }

    /** Limiar por blocos de 16×16 (média do bloco e vizinhos). */
    private static function adaptive(array $lum, int $w, int $h, bool $inv): array {
        $B = 16; $bw = intdiv($w + $B - 1, $B); $bh = intdiv($h + $B - 1, $B);
        $mean = [];
        for ($by = 0; $by < $bh; $by++) for ($bx = 0; $bx < $bw; $bx++) {
            $s = 0; $n = 0; $mn = 255; $mx = 0;
            for ($y = $by * $B; $y < min($h, ($by + 1) * $B); $y++) {
                $seg = substr($lum[$y], $bx * $B, $B);
                foreach (count_chars($seg, 1) as $v => $c) { $s += $v * $c; $n += $c; $mn = min($mn, $v); $mx = max($mx, $v); }
            }
            $mean[$by][$bx] = $mx - $mn < 24 ? ($mn > 127 ? -1 : 256) : $s / max(1, $n);   // bloco liso: todo claro (-1) ou todo escuro
        }
        $out = [];
        for ($y = 0; $y < $h; $y++) {
            $by = intdiv($y, $B); $line = '';
            for ($x = 0; $x < $w; $x++) {
                $bx = intdiv($x, $B); $s = 0; $n = 0;
                for ($j = max(0, $by - 1); $j <= min($bh - 1, $by + 1); $j++) for ($i = max(0, $bx - 1); $i <= min($bw - 1, $bx + 1); $i++) { $m = $mean[$j][$i]; if ($m >= 0 && $m <= 255) { $s += $m; $n++; } }
                $t = $n ? $s / $n : 128;
                $line .= (ord($lum[$y][$x]) < $t) !== $inv ? "\1" : "\0";
            }
            $out[] = $line;
        }
        return $out;
    }

    // ── localizar e amostrar ──

    /** Linhas binárias → o texto. $scale aumenta (vizinho mais próximo) e $pad junta margem clara. */
    public static function fromBits(array $bin, int $w, int $h, int $scale = 1, int $pad = 0): ?string {
        if ($scale > 1 || $pad > 0) {
            $nw = $w * $scale + 2 * $pad; $blank = str_repeat("\0", $nw); $side = str_repeat("\0", $pad);
            $g = array_fill(0, $pad, $blank);
            foreach ($bin as $l) {
                if ($scale === 1) $line = $side . $l . $side;
                else { $line = $side; for ($x = 0; $x < $w; $x++) $line .= str_repeat($l[$x], $scale); $line .= $side; }
                for ($k = 0; $k < $scale; $k++) $g[] = $line;
            }
            for ($k = 0; $k < $pad; $k++) $g[] = $blank;
            $bin = $g; $w = $nw; $h = count($g);
        }
        $f = self::finders($bin, $w, $h);
        if (count($f) < 3) return null;
        usort($f, fn($a, $b) => $b[3] <=> $a[3]);
        $f = array_slice($f, 0, 7);
        $n = count($f); $tries = 0;
        for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) for ($k = $j + 1; $k < $n; $k++) {
            $tri = [$f[$i], $f[$j], $f[$k]];
            $ms = array_column($tri, 2);
            if (max($ms) > min($ms) * 1.6) continue;
            if (++$tries > 20) return null;
            $r = self::tryTriple($bin, $w, $h, $tri);
            if ($r !== null) return $r;
        }
        return null;
    }

    /** Os padrões de localização (1:1:3:1:1): [x, y, módulo, ocorrências]. */
    private static function finders(array $bin, int $w, int $h): array {
        $found = [];
        $step = $h > 1200 ? 2 : 1;
        for ($y = 0; $y < $h; $y += $step) {
            $row = $bin[$y]; $c = [0, 0, 0, 0, 0]; $st = 0;
            for ($x = 0; $x <= $w; $x++) {
                $dark = $x < $w && $row[$x] === "\1";
                if ($dark) {
                    if ($st === 1 || $st === 3) $st++;
                    $c[$st]++;
                    continue;
                }
                if ($st === 0) { if ($c[0]) { $st = 1; $c[1] = 1; } continue; }
                if ($st === 2) { $st = 3; $c[3] = 1; continue; }
                if ($st === 1 || $st === 3) { $c[$st]++; continue; }
                // st 4 e claro: um padrão completo terminou em x
                if (self::ratio($c)) {
                    $cx = $x - $c[4] - $c[3] - $c[2] / 2;
                    $cy = self::cross($bin, $w, $h, (int)$cx, $y, array_sum($c), true);
                    if ($cy !== null) {
                        $cx2 = self::cross($bin, $w, $h, (int)$cy, (int)$cx, array_sum($c), false);
                        if ($cx2 !== null) self::addFinder($found, $cx2, $cy, array_sum($c) / 7);
                    }
                }
                $c = [$c[2], $c[3], $c[4], 1, 0]; $st = 3;
            }
        }
        return $found;
    }

    private static function ratio(array $c): bool {
        $t = array_sum($c);
        if ($t < 7) return false;
        $m = $t / 7; $v = max(0.5, $m / 1.6);
        return abs($m - $c[0]) < $v && abs($m - $c[1]) < $v && abs(3 * $m - $c[2]) < 3 * $v && abs($m - $c[3]) < $v && abs($m - $c[4]) < $v;
    }

    /** Confirma o padrão na outra direção a partir do centro; devolve o centro nessa direção. */
    private static function cross(array $bin, int $w, int $h, int $fix, int $at, int $total, bool $vertical): ?float {
        $get = $vertical ? fn(int $p) => $p >= 0 && $p < $h && $fix >= 0 && $fix < $w && $bin[$p][$fix] === "\1"
                         : fn(int $p) => $p >= 0 && $p < $w && $fix >= 0 && $fix < $h && $bin[$fix][$p] === "\1";
        $lim = $vertical ? $h : $w;
        if (!$get($at)) return null;
        $c = [0, 0, 0, 0, 0];
        $p = $at; while ($p >= 0 && $get($p)) { $c[2]++; $p--; }
        while ($p >= 0 && !$get($p) && $c[1] <= $total) { $c[1]++; $p--; }
        if ($c[1] === 0 || $c[1] > $total) return null;
        while ($p >= 0 && $get($p) && $c[0] <= $total) { $c[0]++; $p--; }
        if ($c[0] === 0) return null;
        $p = $at + 1; while ($p < $lim && $get($p)) { $c[2]++; $p++; }
        while ($p < $lim && !$get($p) && $c[3] <= $total) { $c[3]++; $p++; }
        if ($c[3] === 0 || $c[3] > $total) return null;
        while ($p < $lim && $get($p) && $c[4] <= $total) { $c[4]++; $p++; }
        if ($c[4] === 0) return null;
        $t = array_sum($c);
        if (abs($t - $total) * 2.5 > $total * 2 || !self::ratio($c)) return null;
        return $p - $c[4] - $c[3] - $c[2] / 2;
    }

    private static function addFinder(array &$found, float $x, float $y, float $m): void {
        foreach ($found as &$f) {
            if (abs($f[0] - $x) <= $f[2] * 2 && abs($f[1] - $y) <= $f[2] * 2 && abs($f[2] - $m) <= max(1.0, $f[2] * 0.5)) {
                $n = $f[3];
                $f = [($f[0] * $n + $x) / ($n + 1), ($f[1] * $n + $y) / ($n + 1), ($f[2] * $n + $m) / ($n + 1), $n + 1];
                return;
            }
        }
        unset($f);
        $found[] = [$x, $y, $m, 1];
    }

    private static function tryTriple(array $bin, int $w, int $h, array $p): ?string {
        $d = fn($a, $b) => hypot($a[0] - $b[0], $a[1] - $b[1]);
        // o canto (TL) é o oposto ao lado maior
        $d01 = $d($p[0], $p[1]); $d02 = $d($p[0], $p[2]); $d12 = $d($p[1], $p[2]);
        if ($d12 >= $d01 && $d12 >= $d02) [$tl, $a, $b] = [$p[0], $p[1], $p[2]];
        elseif ($d02 >= $d01) [$tl, $a, $b] = [$p[1], $p[0], $p[2]];
        else [$tl, $a, $b] = [$p[2], $p[0], $p[1]];
        $cross = ($a[0] - $tl[0]) * ($b[1] - $tl[1]) - ($a[1] - $tl[1]) * ($b[0] - $tl[0]);
        [$tr, $bl] = $cross > 0 ? [$a, $b] : [$b, $a];
        $m = ($tl[2] + $tr[2] + $bl[2]) / 3;
        $est = ($d($tl, $tr) + $d($tl, $bl)) / 2 / $m + 7;
        $base = (int)round($est);
        $dims = [];
        foreach ([$base, $base + 1, $base - 1, $base + 2, $base - 2, $base + 4, $base - 4] as $dim) if ($dim >= 21 && $dim <= 177 && ($dim - 17) % 4 === 0 && !in_array($dim, $dims, true)) $dims[] = $dim;
        foreach ($dims as $dim) {
            $ux = [($tr[0] - $tl[0]) / ($dim - 7), ($tr[1] - $tl[1]) / ($dim - 7)];
            $uy = [($bl[0] - $tl[0]) / ($dim - 7), ($bl[1] - $tl[1]) / ($dim - 7)];
            $mtx = [];
            for ($j = 0; $j < $dim; $j++) {
                $line = '';
                for ($i = 0; $i < $dim; $i++) {
                    $x = (int)round($tl[0] + ($i - 3) * $ux[0] + ($j - 3) * $uy[0]);
                    $y = (int)round($tl[1] + ($i - 3) * $ux[1] + ($j - 3) * $uy[1]);
                    $line .= $x >= 0 && $x < $w && $y >= 0 && $y < $h ? $bin[$y][$x] : "\0";
                }
                $mtx[] = $line;
            }
            $r = self::decodeMatrix($mtx);
            if ($r !== null) return $r;
        }
        return null;
    }

    // ── a matriz → o texto ──

    /** Uma matriz de módulos (strings com "\1" escuro) → o texto, ou null. Tenta também espelhada. */
    public static function decodeMatrix(array $mtx): ?string {
        $r = self::decodeOne($mtx);
        if ($r !== null) return $r;
        $n = count($mtx); $t = [];
        for ($y = 0; $y < $n; $y++) { $l = ''; for ($x = 0; $x < $n; $x++) $l .= $mtx[$x][$y]; $t[] = $l; }
        return self::decodeOne($t);
    }

    private static function decodeOne(array $m): ?string {
        $n = count($m);
        if ($n < 21 || ($n - 17) % 4 !== 0 || strlen($m[0]) !== $n) return null;
        $get = fn(int $x, int $y) => $m[$y][$x] === "\1";
        // formato: nível de correção e máscara
        $b1 = 0; $b2 = 0;
        foreach ([[0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8], [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0]] as [$x, $y]) $b1 = ($b1 << 1) | ($get($x, $y) ? 1 : 0);
        for ($y = $n - 1; $y >= $n - 7; $y--) $b2 = ($b2 << 1) | ($get(8, $y) ? 1 : 0);
        for ($x = $n - 8; $x < $n; $x++) $b2 = ($b2 << 1) | ($get($x, 8) ? 1 : 0);
        $fmt = self::format($b1, $b2);
        if ($fmt === null) return null;
        [$eclBits, $mask] = [$fmt >> 3, $fmt & 7];
        $ecl = [1 => 0, 0 => 1, 3 => 2, 2 => 3][$eclBits];
        $ver = intdiv($n - 17, 4);
        if ($ver >= 7) {
            $vb = 0;
            for ($y = 5; $y >= 0; $y--) for ($x = $n - 9; $x >= $n - 11; $x--) $vb = ($vb << 1) | ($get($x, $y) ? 1 : 0);
            $v2 = self::version($vb);
            if ($v2 !== null && $v2 !== $ver) return null;
        }
        $fn = self::functionMap($ver);
        // ler os bits em zigue-zague, sem a máscara
        $total = intdiv(self::rawModules($ver), 8);
        $bytes = []; $cur = 0; $nb = 0;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $n; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = (($right + 1) & 2) === 0 ? $n - 1 - $vert : $vert;
                    if (isset($fn[$y * $n + $x])) continue;
                    $bit = $get($x, $y) ? 1 : 0;
                    if (self::maskBit($mask, $x, $y)) $bit ^= 1;
                    $cur = ($cur << 1) | $bit;
                    if (++$nb === 8) { $bytes[] = $cur; $cur = 0; $nb = 0; if (count($bytes) === $total) break 3; }
                }
            }
        }
        if (count($bytes) < $total) return null;
        // blocos (desentrelaçar) e correção de erros
        $eccLen = self::ECC_PER_BLOCK[$ecl][$ver]; $numBlocks = self::NUM_BLOCKS[$ecl][$ver];
        $numShort = $numBlocks - $total % $numBlocks; $shortLen = intdiv($total, $numBlocks);
        $blocks = array_fill(0, $numBlocks, []); $k = 0;
        for ($i = 0; $i <= $shortLen; $i++) for ($b = 0; $b < $numBlocks; $b++) {
            if ($i === $shortLen - $eccLen && $b < $numShort) continue;   // os blocos curtos não têm este byte de dados
            $blocks[$b][] = $bytes[$k++] ?? 0;
        }
        $data = [];
        foreach ($blocks as $blk) {
            // o bloco curto: os dados e depois a correção
            $fixed = self::rsCorrect($blk, $eccLen);
            if ($fixed === null) return null;
            foreach (array_slice($fixed, 0, count($blk) - $eccLen) as $v) $data[] = $v;
        }
        return self::segments($data, $ver);
    }

    private static function format(int $b1, int $b2): ?int {
        $best = null; $bd = 4;
        for ($d = 0; $d < 32; $d++) {
            $rem = $d;
            for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
            $code = (($d << 10) | $rem) ^ 0x5412;
            foreach ([$b1, $b2] as $b) { $x = self::pop($b ^ $code); if ($x < $bd) { $bd = $x; $best = $d; } }
        }
        return $best;
    }

    private static function version(int $b): ?int {
        $best = null; $bd = 4;
        for ($v = 7; $v <= 40; $v++) {
            $rem = $v;
            for ($i = 0; $i < 12; $i++) $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            $x = self::pop($b ^ (($v << 12) | $rem));
            if ($x < $bd) { $bd = $x; $best = $v; }
        }
        return $best;
    }

    private static function pop(int $x): int { $c = 0; while ($x) { $c += $x & 1; $x >>= 1; } return $c; }

    private static function maskBit(int $mask, int $x, int $y): bool {
        switch ($mask) {
            case 0: return ($x + $y) % 2 === 0;
            case 1: return $y % 2 === 0;
            case 2: return $x % 3 === 0;
            case 3: return ($x + $y) % 3 === 0;
            case 4: return (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0;
            case 5: return $x * $y % 2 + $x * $y % 3 === 0;
            case 6: return ($x * $y % 2 + $x * $y % 3) % 2 === 0;
            default: return (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
        }
    }

    private static function alignPos(int $ver): array {
        if ($ver === 1) return [];
        $num = intdiv($ver, 7) + 2;
        $step = $ver === 32 ? 26 : intdiv($ver * 4 + $num * 2 + 1, $num * 2 - 2) * 2;
        $r = [6];
        for ($i = 0, $pos = $ver * 4 + 10; $i < $num - 1; $i++, $pos -= $step) array_splice($r, 1, 0, [$pos]);
        return $r;
    }

    private static function rawModules(int $ver): int {
        $r = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) { $num = intdiv($ver, 7) + 2; $r -= (25 * $num - 10) * $num - 55; if ($ver >= 7) $r -= 36; }
        return $r;
    }

    /** Os módulos fixos (padrões, temporização, formato, versão): [y*n+x => true]. */
    private static function functionMap(int $ver): array {
        $n = $ver * 4 + 17; $f = [];
        $set = function (int $x0, int $y0, int $w, int $h) use (&$f, $n) { for ($y = $y0; $y < $y0 + $h; $y++) for ($x = $x0; $x < $x0 + $w; $x++) if ($x >= 0 && $y >= 0 && $x < $n && $y < $n) $f[$y * $n + $x] = true; };
        $set(0, 0, 9, 9); $set($n - 8, 0, 8, 9); $set(0, $n - 8, 9, 8);
        $set(6, 0, 1, $n); $set(0, 6, $n, 1);
        $al = self::alignPos($ver); $c = count($al);
        for ($i = 0; $i < $c; $i++) for ($j = 0; $j < $c; $j++) {
            if (($i === 0 && $j === 0) || ($i === 0 && $j === $c - 1) || ($i === $c - 1 && $j === 0)) continue;
            $set($al[$i] - 2, $al[$j] - 2, 5, 5);
        }
        if ($ver >= 7) { $set($n - 11, 0, 3, 6); $set(0, $n - 11, 6, 3); }
        return $f;
    }

    // ── Reed-Solomon (GF(256), 0x11D) ──

    private static function gf(): void {
        if (self::$exp) return;
        $x = 1;
        for ($i = 0; $i < 255; $i++) { self::$exp[$i] = $x; self::$log[$x] = $i; $x <<= 1; if ($x & 0x100) $x ^= 0x11D; }
        for ($i = 255; $i < 512; $i++) self::$exp[$i] = self::$exp[$i - 255];
    }

    private static function mul(int $a, int $b): int { return $a && $b ? self::$exp[self::$log[$a] + self::$log[$b]] : 0; }
    private static function inv(int $a): int { return self::$exp[255 - self::$log[$a]]; }

    /** Corrige um bloco (primeiro byte = grau mais alto). Devolve o bloco corrigido ou null. */
    public static function rsCorrect(array $c, int $nsym): ?array {
        self::gf();
        $n = count($c);
        $synd = self::syndromes($c, $nsym);
        if (!array_filter($synd)) return $c;
        // Berlekamp-Massey
        $L = [1]; $B = [1]; $len = 0; $m = 1; $b = 1;
        for ($k = 0; $k < $nsym; $k++) {
            $d = $synd[$k];
            for ($i = 1; $i <= $len; $i++) $d ^= self::mul($L[$i] ?? 0, $synd[$k - $i]);
            if ($d === 0) { $m++; continue; }
            $coef = self::mul($d, self::inv($b));
            $T = $L;
            foreach ($B as $i => $bv) $L[$i + $m] = ($L[$i + $m] ?? 0) ^ self::mul($coef, $bv);
            if (2 * $len <= $k) { $len = $k + 1 - $len; $B = $T; $b = $d; $m = 1; } else $m++;
        }
        $deg = max(array_keys($L));
        for ($i = 0; $i <= $deg; $i++) $L[$i] ??= 0;
        ksort($L);
        if ($len * 2 > $nsym) return null;
        // Chien: as posições (grau p) onde Λ(α^-p) = 0
        $pos = [];
        for ($p = 0; $p < $n; $p++) {
            $xi = self::$exp[(255 - $p) % 255]; $v = 0; $pw = 1;
            foreach ($L as $co) { $v ^= self::mul($co, $pw); $pw = self::mul($pw, $xi); }
            if ($v === 0) $pos[] = $p;
        }
        if (count($pos) !== $len) return null;
        // Forney: Ω = S·Λ mod x^nsym
        $om = array_fill(0, $nsym, 0);
        for ($i = 0; $i < $nsym; $i++) foreach ($L as $j => $lj) if ($i + $j < $nsym) $om[$i + $j] ^= self::mul($synd[$i], $lj);
        foreach ($pos as $p) {
            $X = self::$exp[$p]; $xi = self::inv($X);
            $num = 0; $pw = 1; foreach ($om as $o) { $num ^= self::mul($o, $pw); $pw = self::mul($pw, $xi); }
            $den = 0; $pw = 1;
            for ($i = 1; $i <= $deg; $i += 2) { $den ^= self::mul($L[$i], $pw); $pw = self::mul($pw, self::mul($xi, $xi)); }
            if ($den === 0) return null;
            $c[$n - 1 - $p] ^= self::mul(self::mul($X, $num), self::inv($den));
        }
        return array_filter(self::syndromes($c, $nsym)) ? null : $c;
    }

    private static function syndromes(array $c, int $nsym): array {
        $s = [];
        for ($j = 0; $j < $nsym; $j++) { $v = 0; $a = self::$exp[$j]; foreach ($c as $x) $v = self::mul($v, $a) ^ $x; $s[$j] = $v; }
        return $s;
    }

    // ── os segmentos ──

    private static function segments(array $data, int $ver): ?string {
        $bits = ''; foreach ($data as $v) $bits .= str_pad(decbin($v), 8, '0', STR_PAD_LEFT);
        $p = 0; $n = strlen($bits); $out = ''; $latin = false;
        $read = function (int $k) use (&$p, $bits, $n): ?int { if ($p + $k > $n) return null; $v = bindec(substr($bits, $p, $k)); $p += $k; return (int)$v; };
        $cc = fn(int $mode) => $ver <= 9 ? [1 => 10, 2 => 9, 4 => 8, 8 => 8][$mode] : ($ver <= 26 ? [1 => 12, 2 => 11, 4 => 16, 8 => 10][$mode] : [1 => 14, 2 => 13, 4 => 16, 8 => 12][$mode]);
        $alnum = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
        while ($p + 4 <= $n) {
            $mode = $read(4);
            if ($mode === 0) break;
            if ($mode === 7) {   // ECI: 26 = UTF-8, 3 = ISO-8859-1
                $e = $read(8); if ($e === null) return null;
                if (($e & 0x80) === 0) $eci = $e; elseif (($e & 0xC0) === 0x80) $eci = (($e & 0x3F) << 8) | (int)$read(8); else $eci = (($e & 0x1F) << 16) | (int)$read(16);
                $latin = $eci === 3 || $eci === 1;
                continue;
            }
            if (!in_array($mode, [1, 2, 4, 8], true)) return null;
            $count = $read($cc($mode)); if ($count === null) return null;
            if ($mode === 1) {
                for (; $count >= 3; $count -= 3) { $v = $read(10); if ($v === null) return null; $out .= str_pad((string)$v, 3, '0', STR_PAD_LEFT); }
                if ($count === 2) { $v = $read(7); if ($v === null) return null; $out .= str_pad((string)$v, 2, '0', STR_PAD_LEFT); }
                elseif ($count === 1) { $v = $read(4); if ($v === null) return null; $out .= (string)$v; }
            } elseif ($mode === 2) {
                for (; $count >= 2; $count -= 2) { $v = $read(11); if ($v === null) return null; $out .= $alnum[intdiv($v, 45)] . $alnum[$v % 45]; }
                if ($count === 1) { $v = $read(6); if ($v === null) return null; $out .= $alnum[$v]; }
            } elseif ($mode === 4) {
                $s = '';
                for ($i = 0; $i < $count; $i++) { $v = $read(8); if ($v === null) return null; $s .= chr($v); }
                $out .= $latin || !mb_check_encoding($s, 'UTF-8') ? mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1') : $s;
            } else {
                return null;   // kanji: não se usa nas faturas
            }
        }
        return $out;
    }
}
