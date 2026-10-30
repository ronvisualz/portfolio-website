<?php
/**
 * topper. admin — Financeiro: a IA a ler faturas e a escrever lembretes (§28.3, 28.4, 28.13)
 *
 * fin_ai_read(): o PDF (ou a imagem) de uma fatura → emitente, cliente, NIFs, número, datas, base, IVA, total,
 * retenção, moeda, descrição e uma confiança. Nada é gravado sem o utilizador confirmar: o resultado só
 * preenche o formulário (ou, na caixa das faturas, fica «Por confirmar» quando a confiança é baixa).
 * fin_ai_suggest(): categoria e âmbito pelo histórico do mesmo NIF.
 * fin_ai_reminder(): o rascunho do lembrete ao cliente (cordial → firme conforme o atraso), aprovado antes de sair.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin.php';
require_once __DIR__ . '/ai.php';

/** A IA está ligada neste servidor? */
function fin_ai_on(): bool {
    return ai_config() !== null;
}

/** Porque é que a IA não pode ler agora, em palavras para o ecrã ('' = pode). */
function fin_ai_why_off(): string {
    if (fin_ai_on()) return '';
    return is_file(__DIR__ . '/ai_config.php') ? 'a chave da Anthropic guardada no servidor não é válida (Definições › Integrações › IA)'
                                               : 'falta a chave da Anthropic no servidor (Definições › Integrações › IA)';
}

function fin_ai_schema(): array {
    $s = ['type' => 'string'];
    $n = ['type' => 'number'];
    return ['type' => 'object', 'additionalProperties' => false,
        'required' => ['kind', 'issuer_name', 'issuer_nif', 'client_name', 'client_nif', 'number', 'issue_date', 'due_date', 'base', 'vat_rate', 'vat', 'total', 'withholding', 'currency', 'description', 'pay_iban', 'mb_entity', 'mb_ref', 'proof', 'confidence'],
        'properties' => [
            'kind' => ['type' => 'string', 'enum' => ['invoice', 'invoice_receipt', 'receipt', 'credit_note', 'green_receipt', 'payment_proof', 'quote', 'other']],
            'pay_iban' => ['type' => 'string', 'description' => 'IBAN indicado para pagar (vazio se não houver)'],
            'mb_entity' => ['type' => 'string', 'description' => 'Entidade Multibanco (5 algarismos) ou vazio'], 'mb_ref' => ['type' => 'string', 'description' => 'Referência Multibanco (9 algarismos) ou vazio'],
            'proof' => ['type' => 'object', 'additionalProperties' => false, 'description' => 'Só num comprovativo de pagamento (kind payment_proof); senão valores vazios/0',
                'required' => ['amount', 'date', 'direction', 'payee_name', 'payee_iban', 'payer_name', 'payer_iban', 'description', 'mb_entity', 'mb_ref', 'bank'],
                'properties' => ['amount' => $n, 'date' => ['type' => 'string', 'description' => 'AAAA-MM-DD'], 'direction' => ['type' => 'string', 'enum' => ['out', 'in', '']],
                    'payee_name' => $s, 'payee_iban' => $s, 'payer_name' => $s, 'payer_iban' => $s, 'description' => $s, 'mb_entity' => $s, 'mb_ref' => $s, 'bank' => $s]],
            'issuer_name' => $s, 'issuer_nif' => $s, 'client_name' => $s, 'client_nif' => $s, 'number' => $s,
            'issue_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD ou vazio'], 'due_date' => ['type' => 'string', 'description' => 'AAAA-MM-DD ou vazio'],
            'base' => $n, 'vat_rate' => $n, 'vat' => $n, 'total' => $n, 'withholding' => $n, 'currency' => $s, 'description' => $s,
            'confidence' => ['type' => 'number', 'description' => '0 a 1: quanto confia nos valores lidos'],
        ]];
}

/**
 * Lê uma fatura. $bytes: o PDF ou a imagem. Devolve os campos normalizados (datas AAAA-MM-DD ou '', NIFs só
 * algarismos/letras, valores com 2 casas) — ou lança AiException quando a IA não está ligada ou falha.
 */
function fin_ai_read(PDO $pdo, string $bytes, string $mime): array {
    if (!fin_ai_on()) throw new AiException('A IA não está ligada neste servidor: preencha os campos à mão.', 'not_configured', 503);
    if (strlen($bytes) > 9 * 1048576) throw new AiException('O ficheiro é grande demais para a IA ler (máx. 9 MB): preencha à mão.', 'too_large', 413);
    $block = $mime === 'application/pdf'
        ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes)]]
        : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];
    $co = trim((string)setting($pdo, 'company_name', 'topper.'));
    $nif = trim((string)setting($pdo, 'company_nif', ''));
    $prompt = 'Este documento pode ser uma fatura, fatura-recibo, recibo, nota de crédito ou recibo verde português (ou estrangeiro), um comprovativo de pagamento/transferência de um banco (kind payment_proof: preencha proof; direction out se a nossa empresa pagou, in se recebeu), um orçamento (kind quote) ou outra coisa (kind other). '
            . 'Extraia os dados tal como estão no documento. A nossa empresa é «' . $co . '»' . ($nif !== '' ? ' (NIF ' . $nif . ')' : '') . '. '
            . 'Valores em números (ponto decimal), sem símbolos. base = total sem IVA; vat_rate = taxa de IVA em % (0 se isento); withholding = retenção na fonte de IRS (0 se não houver). '
            . 'Se houver várias taxas de IVA, use a predominante e some o IVA. description = o serviço ou produto em poucas palavras. '
            . 'Datas em AAAA-MM-DD; vazio se não existir. confidence baixa (< 0.6) se o documento for ilegível, incompleto ou não for uma fatura.';
    $resp = ai_call($pdo, [
        'max_tokens' => 1200,
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => fin_ai_schema()]],
        'messages' => [['role' => 'user', 'content' => [$block, ['type' => 'text', 'text' => $prompt]]]],
    ], 'fin_read');
    $d = json_decode(implode('', ai_texts($resp['content'])), true);
    if (!is_array($d)) throw new AiException('A IA não conseguiu ler o documento: preencha à mão.', 'server', 502);
    $date = fn($v) => fin_date_ok((string)$v) ? (string)$v : '';
    $nifc = fn($v) => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', strtoupper(preg_replace('/^PT/i', '', (string)$v))), 0, 20);
    $m = fn($v) => round((float)$v, 2);
    $out = ['kind' => (string)($d['kind'] ?? 'other'), 'issuer_name' => mb_substr(trim((string)($d['issuer_name'] ?? '')), 0, 190), 'issuer_nif' => $nifc($d['issuer_nif'] ?? ''),
            'client_name' => mb_substr(trim((string)($d['client_name'] ?? '')), 0, 190), 'client_nif' => $nifc($d['client_nif'] ?? ''), 'number' => mb_substr(trim((string)($d['number'] ?? '')), 0, 60),
            'issue_date' => $date($d['issue_date'] ?? ''), 'due_date' => $date($d['due_date'] ?? ''), 'base' => $m($d['base'] ?? 0), 'vat_rate' => round((float)($d['vat_rate'] ?? 0), 2),
            'vat' => $m($d['vat'] ?? 0), 'total' => $m($d['total'] ?? 0), 'withholding' => $m($d['withholding'] ?? 0),
            'currency' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($d['currency'] ?? 'EUR')), 0, 3)) ?: 'EUR',
            'description' => mb_substr(trim((string)($d['description'] ?? '')), 0, 300), 'confidence' => max(0.0, min(1.0, (float)($d['confidence'] ?? 0))),
            'pay_iban' => preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $ib = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($d['pay_iban'] ?? '')))) ? $ib : '',
            'mb_entity' => mb_substr(preg_replace('/\D/', '', (string)($d['mb_entity'] ?? '')), 0, 8), 'mb_ref' => mb_substr(preg_replace('/\D/', '', (string)($d['mb_ref'] ?? '')), 0, 20),
            'proof' => is_array($d['proof'] ?? null) ? $d['proof'] : []];
    // coerência: base + IVA = total; senão baixa a confiança
    if ($out['total'] > 0 && $out['base'] <= 0) $out['base'] = round($out['total'] - $out['vat'], 2);
    if ($out['kind'] !== 'payment_proof' && abs($out['base'] + $out['vat'] - $out['total']) > 0.05) $out['confidence'] = min($out['confidence'], 0.5);
    // quem é a contraparte: se o emitente somos nós, é uma fatura emitida (receita)
    $ours = $nif !== '' && preg_replace('/\D/', '', $nif) === preg_replace('/\D/', '', $out['issuer_nif']);
    $out['direction'] = $ours ? 'in' : 'out';
    $out['party_name'] = $ours ? $out['client_name'] : $out['issuer_name'];
    $out['party_nif'] = $ours ? $out['client_nif'] : $out['issuer_nif'];
    return $out + fin_ai_suggest($pdo, $out['party_nif'], $out['direction'], $out['kind']);
}

/**
 * Categoria, âmbito, tipo de contraparte e retenção sugeridos: o último lançamento com o mesmo NIF manda; senão
 * um recibo verde é de freelancer (Freelancers, retenção pela taxa do parâmetro, salvo dispensa na ficha).
 */
function fin_ai_suggest(PDO $pdo, string $nif, string $dir, string $kind = ''): array {
    $s = ['category' => '', 'scope' => '', 'deal_id' => 0, 'party_kind' => $dir === 'in' ? 'client' : 'supplier', 'contact_id' => 0, 'wh_rate' => 0.0, 'from_history' => false];
    if ($nif !== '') {
        $st = $pdo->prepare('SELECT category, scope, deal_id, party_kind, contact_id, wh_rate FROM fin_docs WHERE party_nif = ? AND direction = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1');
        $st->execute([$nif, $dir]);
        if ($r = $st->fetch()) {
            $s = ['category' => (string)$r['category'], 'scope' => (string)$r['scope'], 'deal_id' => 0, 'party_kind' => (string)$r['party_kind'], 'contact_id' => (int)$r['contact_id'],
                  'wh_rate' => (float)$r['wh_rate'], 'from_history' => true];
        }
        $st = $pdo->prepare('SELECT id, is_freelancer, wh_exempt FROM contacts WHERE REPLACE(nif, \' \', \'\') = ? LIMIT 1');
        $st->execute([$nif]);
        if ($c = $st->fetch()) {
            $s['contact_id'] = (int)$c['id'];
            if ((int)$c['is_freelancer'] && $dir === 'out') { $s['party_kind'] = 'freelancer'; if ($s['category'] === '') $s['category'] = 'Freelancers'; }
            if ((int)($c['wh_exempt'] ?? 0)) $s['wh_rate'] = 0.0;
            elseif ($s['party_kind'] === 'freelancer' && !$s['from_history']) $s['wh_rate'] = fin_p($pdo, 'wh_cat_b', 23);
        }
    }
    if ($kind === 'green_receipt' && $dir === 'out' && !$s['from_history']) {
        $s['party_kind'] = 'freelancer';
        $s['category'] = $s['category'] ?: 'Freelancers';
        if (!$s['contact_id']) $s['wh_rate'] = fin_p($pdo, 'wh_cat_b', 23);
    }
    return $s;
}

/**
 * O rascunho do lembrete ao cliente: o tom sobe com o atraso (até 15 dias cordial, até 45 firme mas educado,
 * depois firme). Sem IA, um texto-modelo com os mesmos dados.
 */
function fin_ai_reminder(PDO $pdo, array $d): array {
    $days = max(0, fin_overdue_days($d));
    $tone = $days <= 15 ? 'cordial e leve' : ($days <= 45 ? 'cordial mas firme' : 'firme e direto, sempre educado');
    $co = trim((string)setting($pdo, 'company_name', 'topper.')) ?: 'topper.';
    $iban = trim((string)setting($pdo, 'fin_iban', ''));
    $subject = 'Fatura ' . fin_doc_label($d) . ' · ' . ($days > 0 ? 'pagamento em atraso' : 'lembrete de pagamento');
    $facts = 'Cliente: ' . $d['party_name'] . "\nFatura: " . fin_doc_label($d) . "\nEmitida a " . fin_date_pt($d['issue_date']) . ', vencida a ' . fin_date_pt($d['due_date'])
           . ' (' . $days . " dias de atraso)\nEm falta: " . eur(fin_doc_left($d)) . ($iban !== '' ? "\nIBAN para pagamento: " . $iban : '') . ($d['deal_code'] ? "\nProjeto: " . $d['deal_code'] . ' — ' . $d['deal_name'] : '');
    $fallback = "Olá,\n\nEscrevemos a propósito da fatura " . fin_doc_label($d) . ', emitida a ' . fin_date_pt($d['issue_date']) . ' e com vencimento a ' . fin_date_pt($d['due_date'])
              . ', no valor em falta de ' . eur(fin_doc_left($d)) . '. ' . ($days > 0 ? 'Segundo os nossos registos, ainda não recebemos o pagamento.' : 'Fica o lembrete da data de pagamento.')
              . ($iban !== '' ? "\n\nIBAN: " . $iban : '') . "\n\nSe o pagamento já foi feito, agradecemos que nos envie o comprovativo e pedimos desculpa pelo incómodo.\n\nObrigado,\n" . $co;
    if (!fin_ai_on()) return ['subject' => $subject, 'body' => $fallback, 'ai' => false];
    try {
        $resp = ai_call($pdo, [
            'max_tokens' => 700,
            'output_config' => ['effort' => 'low'],
            'messages' => [['role' => 'user', 'content' => 'Escreva em português de Portugal o corpo de um email de lembrete de pagamento de ' . $co . ' para o cliente. Tom: ' . $tone
                . '. Curto (até 120 palavras), sem assunto, sem marcadores, sem inventar dados. Termine com «' . $co . '». Dados:' . "\n" . $facts]],
        ], 'fin_reminder');
        $txt = trim(implode("\n", ai_texts($resp['content'])));
        return ['subject' => $subject, 'body' => $txt !== '' ? $txt : $fallback, 'ai' => $txt !== ''];
    } catch (Throwable $e) {
        return ['subject' => $subject, 'body' => $fallback, 'ai' => false];
    }
}
