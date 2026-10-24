<?php
/**
 * topper. admin — Financeiro (§28): o modelo
 *
 *  · Parâmetros (fin_params): todas as taxas e limites, com fonte legal, ano e verificação. Nada fixo
 *    no código: os cálculos leem fin_p().
 *  · Lançamentos (fin_docs): tudo o que entra ou sai — fatura emitida, fatura recebida, recibo, nota
 *    de crédito, despesa, salário, imposto — com sentido (in = receita, out = despesa), âmbito
 *    (company = Empresa, deal = Negócio), contraparte, valores, datas, ficheiros e estado.
 *  · Pagamentos (fin_payments), também parciais. Uma fatura só fica «Paga» com comprovativo
 *    submetido ou movimento bancário conciliado.
 *  · Custos fixos (fin_fixed): a periodicidade passa a valor mensal; geram «Por pagar» em cada
 *    vencimento (fin_fixed_tick, no cron).
 *  · As despesas de um negócio entram nos custos reais dele (deal_costs, inc/finance.php).
 *
 * Acesso: superadmin, admins e quem tem a aba Financeiro — verificado em cada rota (require_perm)
 * e em cada ficheiro (fin_file.php). Os parâmetros só os mudam superadmin e admins.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/leads.php';   // LEAD_ORIGIN (canal do custo de aquisição)

const FIN_CATS_DEFAULT = ['Software', 'Equipamento', 'Renda', 'Telecomunicações', 'Contabilidade', 'Seguros', 'Viaturas', 'Deslocações',
                          'Freelancers', 'Fornecedores de produção', 'Marketing', 'Formação', 'Impostos', 'Salários', 'Outros'];

/** Estados (A receber: pedida → emitida → parcial → paga → recibo arquivado · anulada; A pagar: por confirmar → por pagar → paga → recibo arquivado · em disputa). */
const FIN_ST = [
    'requested' => 'Fatura pedida', 'issued' => 'Emitida', 'partial' => 'Parcialmente paga', 'paid' => 'Paga', 'archived' => 'Recibo arquivado',
    'void' => 'Anulada', 'review' => 'Por confirmar', 'to_pay' => 'Por pagar', 'dispute' => 'Em disputa',
];
const FIN_ST_TONE = ['requested' => 'wait', 'issued' => 'wait', 'partial' => 'warn', 'paid' => 'ok', 'archived' => 'ok', 'void' => '', 'review' => 'warn', 'to_pay' => 'wait', 'dispute' => 'bad'];
const FIN_OPEN = ['requested', 'issued', 'partial', 'to_pay', 'dispute', 'review'];

const FIN_TYPES = ['invoice' => 'Fatura', 'receipt' => 'Fatura-recibo / recibo', 'credit' => 'Nota de crédito', 'expense' => 'Despesa', 'salary' => 'Salário', 'tax' => 'Imposto', 'other' => 'Outro'];
const FIN_PARTY = ['client' => 'Cliente', 'supplier' => 'Fornecedor', 'freelancer' => 'Freelancer (recibo verde)', 'landlord' => 'Senhorio', 'employee' => 'Colaborador', 'state' => 'Estado', 'other' => 'Outro'];
const FIN_METHODS = ['transfer' => 'Transferência', 'mbway' => 'MB WAY', 'card' => 'Cartão', 'debit' => 'Débito direto', 'cash' => 'Numerário', 'other' => 'Outro'];
const FIN_PERIODS = [1 => 'Mensal', 2 => 'Bimestral', 3 => 'Trimestral', 6 => 'Semestral', 12 => 'Anual'];
const FIN_ACC_KINDS = ['bank' => 'Banco', 'card' => 'Cartão', 'cash' => 'Caixa'];
const FIN_MAX_BYTES = 15728640;   // 15 MB por documento

/** A aba depois do passo 30 da base. */
function fin_ready(PDO $pdo): bool {
    static $ok = null;
    return $ok ??= db_has_table($pdo, 'fin_docs') && db_has_table($pdo, 'fin_params');
}

function fin_now(): string { return date('Y-m-d H:i:s'); }

function fin_date_ok(?string $d): bool {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
}

function fin_date_pt(?string $d): string {
    return fin_date_ok((string)$d) ? date('d/m/Y', strtotime((string)$d)) : '—';
}

/** Um valor do formulário, com 2 casas (aceita «1 234,56»). */
function fin_money($raw): float {
    return round(money_in($raw), 2);
}

/** Um número com vírgula e sem zeros a mais: 23 · 23,75 · 6,15. */
function fin_num(float $v, int $dec = 2): string {
    $s = number_format($v, $dec, ',', ' ');
    return $dec > 0 ? rtrim(rtrim($s, '0'), ',') : $s;
}

// ── parâmetros ──────────────────────────────────────────────

/** Todos os parâmetros, chave → linha. */
function fin_params(PDO $pdo, bool $fresh = false): array {
    static $p = null;
    if ($p !== null && !$fresh) return $p;
    $p = [];
    if (!fin_ready($pdo)) return $p;
    foreach ($pdo->query('SELECT * FROM fin_params ORDER BY position, id')->fetchAll() as $r) $p[$r['pkey']] = $r;
    return $p;
}

/** Um parâmetro numérico (o valor guardado; o $d só serve se a linha não existir). */
function fin_p(PDO $pdo, string $k, float $d = 0.0): float {
    $p = fin_params($pdo)[$k] ?? null;
    return $p === null ? $d : (float)str_replace(',', '.', (string)$p['value']);
}

/** Um parâmetro de texto. */
function fin_ps(PDO $pdo, string $k, string $d = ''): string {
    $p = fin_params($pdo)[$k] ?? null;
    return $p === null ? $d : (string)$p['value'];
}

/** Um parâmetro com vários números separados por «;» (ex.: as taxas da tributação autónoma por escalão). */
function fin_pl(PDO $pdo, string $k): array {
    return array_map(fn($v) => (float)str_replace(',', '.', trim($v)), array_filter(explode(';', fin_ps($pdo, $k)), fn($v) => trim($v) !== ''));
}

/** O ano das regras em uso (para o aviso do simulador). */
function fin_rules_year(PDO $pdo): int {
    return (int)fin_p($pdo, 'irs_table_year', (float)date('Y'));
}

/**
 * Muda um parâmetro (Definições › Financeiro). Só superadmin e admins; os marcados «só admins»
 * (reserva, limiar do runway) idem — fica tudo no registo (fin_param_log e o registo das Definições).
 * $in: value, source, year, verified_on (qualquer um pode faltar). Devolve [antes, depois].
 */
function fin_param_save(PDO $pdo, array $user, string $k, array $in): array {
    if (!role_is_admin((string)($user['role'] ?? ''))) throw new RuntimeException('Só superadmin e admins mudam os parâmetros do Financeiro.');
    $p = fin_params($pdo, true)[$k] ?? null;
    if (!$p) throw new RuntimeException('Parâmetro desconhecido.');
    $set = []; $log = [];
    if (array_key_exists('value', $in)) {
        $v = trim((string)$in['value']);
        if ($p['unit'] === '' || in_array($k, ['ta_comb', 'ta_phev', 'ta_ev', 'cal_annual', 'vat_exempt_note'], true)) {
            $v = mb_substr($v, 0, 500);
            if ($k === 'cal_annual' && $v !== '' && !preg_match('/^([^|;]+\|\d{2}-\d{2})(;[^|;]+\|\d{2}-\d{2})*$/u', $v)) throw new RuntimeException('Use «Nome|MM-DD;Nome|MM-DD».');
            if (in_array($k, ['ta_comb', 'ta_phev', 'ta_ev'], true) && !preg_match('/^\d+([.,]\d+)?(;\d+([.,]\d+)?)*$/', $v)) throw new RuntimeException('Use percentagens separadas por «;», ex.: 8;25;32.');
        } else {
            $v = str_replace([' ', ','], ['', '.'], $v);
            if (!is_numeric($v) || (float)$v < 0) throw new RuntimeException('Indique um número (0 ou mais).');
            if ($p['unit'] === '%' && (float)$v > 100) throw new RuntimeException('Uma percentagem não passa de 100.');
            if ($p['unit'] === 'dia' && ((float)$v < 1 || (float)$v > 31)) throw new RuntimeException('Um dia do mês: de 1 a 31.');
            if ($p['unit'] === 'mês' && ((float)$v < 1 || (float)$v > 12)) throw new RuntimeException('Um mês: de 1 a 12.');
            if ($k === 'vat_period' && !in_array((int)$v, [1, 3], true)) throw new RuntimeException('O período do IVA é 1 (mensal) ou 3 (trimestral).');
            $v = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
        }
        if ($v !== (string)$p['value']) { $set['value'] = $v; $log[] = ['value', (string)$p['value'], $v]; }
    }
    if (array_key_exists('source', $in)) {
        $v = mb_substr(trim((string)$in['source']), 0, 255);
        if ($v !== (string)$p['source']) { $set['source'] = $v; $log[] = ['source', (string)$p['source'], $v]; }
    }
    if (array_key_exists('year', $in)) {
        $y = (int)$in['year'];
        if ($y && ($y < 2000 || $y > 2100)) throw new RuntimeException('Ano inválido.');
        if ($y !== (int)$p['year']) { $set['year'] = $y ?: null; $log[] = ['year', (string)$p['year'], (string)$y]; }
    }
    if (array_key_exists('verified_on', $in)) {
        $d = trim((string)$in['verified_on']);
        if ($d !== '' && !fin_date_ok($d)) throw new RuntimeException('Data de verificação inválida.');
        if ($d !== (string)$p['verified_on']) { $set['verified_on'] = $d !== '' ? $d : null; $log[] = ['verified_on', (string)$p['verified_on'], $d]; }
    }
    if (!$set) return [(string)$p['value'], (string)$p['value']];
    $set['updated_by'] = (int)$user['id'];
    $set['updated_at'] = fin_now();
    $pdo->prepare('UPDATE fin_params SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($set))) . ' WHERE pkey = ?')
        ->execute([...array_values($set), $k]);
    $ins = $pdo->prepare('INSERT INTO fin_param_log (pkey, old_value, new_value, what, user_id, at) VALUES (?,?,?,?,?,?)');
    foreach ($log as [$what, $a, $b]) $ins->execute([$k, $a, $b, $what, (int)$user['id'], fin_now()]);
    if (function_exists('setting_log')) {
        foreach ($log as [$what, $a, $b]) {
            setting_log($pdo, 'fin:' . $k, 'Financeiro · ' . $p['label'] . ($what === 'value' ? '' : ' (' . ['source' => 'fonte', 'year' => 'ano', 'verified_on' => 'verificado em'][$what] . ')'), $a, $b);
        }
    }
    fin_params($pdo, true);
    return [(string)$p['value'], (string)($set['value'] ?? $p['value'])];
}

/** As categorias (lista editável em Definições › Financeiro). */
function fin_categories(PDO $pdo): array {
    return setting_lines($pdo, 'fin_categories', FIN_CATS_DEFAULT);
}

/** O responsável financeiro (recebe as tarefas do calendário fiscal): Definições, ou o primeiro superadmin. */
function fin_owner_id(PDO $pdo): ?int {
    $id = (int)setting($pdo, 'fin_owner', 0);
    if ($id && (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1 AND id = ' . $id)->fetchColumn()) return $id;
    $r = $pdo->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('superadmin','admin') ORDER BY role = 'superadmin' DESC, id LIMIT 1")->fetchColumn();
    return $r ? (int)$r : null;
}

// ── lançamentos ─────────────────────────────────────────────

/** Um lançamento, com o negócio (código e nome). */
function fin_doc(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT f.*, d.code AS deal_code, d.name AS deal_name, d.owner_id AS deal_owner FROM fin_docs f LEFT JOIN deals d ON d.id = f.deal_id WHERE f.id = ? AND f.deleted_at IS NULL');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** O que é devido: o total menos a retenção na fonte (essa vai ao Estado, não à contraparte). */
function fin_doc_due(array $d): float {
    return round((float)$d['total'] - (float)$d['withholding'], 2);
}

/** O que falta pagar. */
function fin_doc_left(array $d): float {
    return max(0.0, round(fin_doc_due($d) - (float)$d['paid_amount'], 2));
}

/** O custo que conta no resultado: a base, mais o IVA quando não é dedutível. */
function fin_doc_cost(array $d): float {
    return round((float)$d['base'] + ((int)$d['vat_deductible'] ? 0.0 : (float)$d['vat']), 2);
}

/** O número da fatura para mostrar (ou «Fatura pedida FAT-2026-0142»). */
function fin_doc_label(array $d): string {
    if ($d['number'] !== '') return ($d['doc_type'] === 'credit' ? 'NC ' : '') . $d['number'];
    if ($d['req_code'] !== '') return $d['req_code'];
    return $d['description'] !== '' ? mb_strimwidth((string)$d['description'], 0, 60, '…') : ('Lançamento ' . (int)$d['id']);
}

/** Dias de atraso (0 = em dia; negativo = falta para vencer). Sem vencimento: 30 dias depois da emissão. */
function fin_overdue_days(array $d): int {
    $due = (string)($d['due_date'] ?: ($d['issue_date'] ? date('Y-m-d', strtotime($d['issue_date'] . ' +30 days')) : ''));
    if ($due === '') return 0;
    return (int)floor((strtotime(today_lisbon()) - strtotime($due)) / 86400);
}

/** A faixa de antiguidade: em dia · 1–30 · 31–60 · 61–90 · +90. */
function fin_aging(int $overdue): string {
    if ($overdue <= 0) return 'ok';
    if ($overdue <= 30) return '1-30';
    if ($overdue <= 60) return '31-60';
    if ($overdue <= 90) return '61-90';
    return '90+';
}
const FIN_AGING = ['ok' => 'Em dia', '1-30' => '1–30 dias', '31-60' => '31–60 dias', '61-90' => '61–90 dias', '90+' => '+90 dias'];

/**
 * Lançamentos com filtros: dir (in|out), st (lista de estados), open (só por pagar/receber), scope,
 * deal, cat, q (texto), from/to (data de emissão), limit.
 */
function fin_docs(PDO $pdo, array $f = []): array {
    $w = ['f.deleted_at IS NULL']; $a = [];
    if (!empty($f['dir']))  { $w[] = 'f.direction = ?'; $a[] = $f['dir']; }
    if (!empty($f['open'])) $w[] = "f.status IN ('" . implode("','", FIN_OPEN) . "')";
    if (!empty($f['st']))   { $w[] = 'f.status IN (' . implode(',', array_fill(0, count($f['st']), '?')) . ')'; array_push($a, ...$f['st']); }
    if (!empty($f['type'])) { $w[] = 'f.doc_type IN (' . implode(',', array_fill(0, count($f['type']), '?')) . ')'; array_push($a, ...$f['type']); }
    if (!empty($f['scope'])) { $w[] = 'f.scope = ?'; $a[] = $f['scope']; }
    if (!empty($f['deal'])) { $w[] = 'f.deal_id = ?'; $a[] = (int)$f['deal']; }
    if (!empty($f['cat']))  { $w[] = 'f.category = ?'; $a[] = $f['cat']; }
    if (!empty($f['from'])) { $w[] = 'f.issue_date >= ?'; $a[] = $f['from']; }
    if (!empty($f['to']))   { $w[] = 'f.issue_date <= ?'; $a[] = $f['to']; }
    if (($q = trim((string)($f['q'] ?? ''))) !== '') {
        $w[] = '(f.number LIKE ? OR f.party_name LIKE ? OR f.party_nif LIKE ? OR f.description LIKE ? OR f.req_code LIKE ? OR d.code LIKE ?)';
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        array_push($a, $like, $like, $like, $like, $like, $like);
    }
    $order = $f['order'] ?? 'COALESCE(f.due_date, f.issue_date) ASC, f.id';
    $st = $pdo->prepare('SELECT f.*, d.code AS deal_code, d.name AS deal_name, d.owner_id AS deal_owner FROM fin_docs f LEFT JOIN deals d ON d.id = f.deal_id
                          WHERE ' . implode(' AND ', $w) . ' ORDER BY ' . $order . (!empty($f['limit']) ? ' LIMIT ' . (int)$f['limit'] : ''));
    $st->execute($a);
    return $st->fetchAll();
}

/** Mesmo NIF e mesmo número (faturas recebidas): o duplicado, se houver. */
function fin_doc_dup(PDO $pdo, string $nif, string $number, string $dir = 'out', int $except = 0): ?array {
    if ($nif === '' || $number === '') return null;
    $st = $pdo->prepare("SELECT * FROM fin_docs WHERE direction = ? AND party_nif = ? AND number = ? AND id <> ? AND deleted_at IS NULL AND status <> 'void' LIMIT 1");
    $st->execute([$dir, $nif, $number, $except]);
    return $st->fetch() ?: null;
}

/**
 * Os campos de um lançamento vindos do formulário (ou da IA, depois de confirmados). Valida tudo:
 * sentido e âmbito obrigatórios; negócio obrigatório no âmbito «Negócio»; fornecedores e freelancers
 * nunca sem âmbito; base, IVA e total que batem; retenção pela taxa do parâmetro.
 */
function fin_doc_input(PDO $pdo, array $src, ?array $old = null): array {
    $dir = (string)($src['direction'] ?? ($old['direction'] ?? ''));
    if (!in_array($dir, ['in', 'out'], true)) throw new RuntimeException('Escolha o sentido: Receita ou Despesa.');
    $scope = (string)($src['scope'] ?? ($old['scope'] ?? ''));
    if (!in_array($scope, ['company', 'deal'], true)) throw new RuntimeException('Escolha o âmbito: Empresa (interno) ou Negócio.');
    $dealId = (int)($src['deal_id'] ?? 0) ?: null;
    if ($scope === 'deal') {
        if (!$dealId) throw new RuntimeException('Escolha o negócio.');
        $st = $pdo->prepare('SELECT id FROM deals WHERE id = ? AND code IS NOT NULL');
        $st->execute([$dealId]);
        if (!$st->fetchColumn()) throw new RuntimeException('Esse negócio não existe.');
    } else {
        $dealId = null;
    }
    $lineId = $dealId ? ((int)($src['line_id'] ?? 0) ?: null) : null;
    if ($lineId) {
        $st = $pdo->prepare('SELECT id FROM deal_lines WHERE id = ? AND deal_id = ?');
        $st->execute([$lineId, $dealId]);
        if (!$st->fetchColumn()) $lineId = null;
    }
    $type = (string)($src['doc_type'] ?? 'invoice');
    if (!isset(FIN_TYPES[$type])) $type = 'invoice';
    $pk = (string)($src['party_kind'] ?? '');
    if (!isset(FIN_PARTY[$pk])) $pk = $dir === 'in' ? 'client' : 'supplier';
    $cats = fin_categories($pdo);
    $cat = trim((string)($src['category'] ?? ''));
    if ($cat !== '' && !in_array($cat, $cats, true) && !($old && $cat === $old['category'])) $cat = 'Outros';
    if ($dir === 'out' && $cat === '') throw new RuntimeException('Escolha a categoria.');
    $nif = preg_replace('/[^A-Za-z0-9]/', '', strtoupper((string)($src['party_nif'] ?? '')));
    $name = mb_substr(trim((string)($src['party_name'] ?? '')), 0, 190);
    if ($name === '' && !in_array($type, ['tax', 'salary'], true)) throw new RuntimeException($dir === 'in' ? 'Indique o cliente.' : 'Indique o fornecedor.');

    $base = fin_money($src['base'] ?? 0);
    $rate = (float)str_replace(',', '.', (string)($src['vat_rate'] ?? 0));
    if ($rate < 0 || $rate > 50) throw new RuntimeException('Taxa de IVA inválida.');
    $vat = array_key_exists('vat', $src) && trim((string)$src['vat']) !== '' ? fin_money($src['vat']) : round($base * $rate / 100, 2);
    $total = array_key_exists('total', $src) && trim((string)$src['total']) !== '' ? fin_money($src['total']) : round($base + $vat, 2);
    if ($base < 0 || $vat < 0 || $total < 0) throw new RuntimeException('Os valores não podem ser negativos (use uma nota de crédito).');
    if ($total <= 0 && $base <= 0) throw new RuntimeException('Indique o valor.');
    if (abs($base + $vat - $total) > 0.05) throw new RuntimeException('Base + IVA não dá o total (' . eur($base + $vat) . '). Confirme os valores.');
    $whRate = (float)str_replace(',', '.', (string)($src['wh_rate'] ?? 0));
    if ($whRate < 0 || $whRate > 50) throw new RuntimeException('Taxa de retenção inválida.');
    $wh = array_key_exists('withholding', $src) && trim((string)$src['withholding']) !== '' ? fin_money($src['withholding']) : round($base * $whRate / 100, 2);
    $cur = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($src['currency'] ?? 'EUR')), 0, 3)) ?: 'EUR';

    $issue = (string)($src['issue_date'] ?? '');
    $due = (string)($src['due_date'] ?? '');
    if ($issue !== '' && !fin_date_ok($issue)) throw new RuntimeException('Data de emissão inválida.');
    if ($due !== '' && !fin_date_ok($due)) throw new RuntimeException('Data de vencimento inválida.');
    if ($issue !== '' && $due !== '' && $due < $issue) throw new RuntimeException('O vencimento não pode ser antes da emissão.');
    $number = mb_substr(trim((string)($src['number'] ?? '')), 0, 60);

    return [
        'direction' => $dir, 'doc_type' => $type, 'scope' => $scope, 'deal_id' => $dealId, 'line_id' => $lineId,
        'contact_id' => (int)($src['contact_id'] ?? 0) ?: null, 'party_kind' => $pk, 'party_name' => $name, 'party_nif' => mb_substr($nif, 0, 20),
        'party_email' => mb_substr(trim((string)($src['party_email'] ?? '')), 0, 190), 'party_address' => mb_substr(trim((string)($src['party_address'] ?? '')), 0, 300),
        'category' => $cat, 'number' => $number, 'description' => mb_substr(trim((string)($src['description'] ?? '')), 0, 500),
        'base' => $base, 'vat_rate' => $rate, 'vat' => $vat, 'vat_deductible' => $dir === 'out' && array_key_exists('vat_deductible', $src) && empty($src['vat_deductible']) ? 0 : 1,
        'vat_note' => mb_substr(trim((string)($src['vat_note'] ?? '')), 0, 190), 'total' => $total, 'wh_rate' => $whRate, 'withholding' => $wh, 'currency' => $cur,
        'issue_date' => $issue !== '' ? $issue : null, 'due_date' => $due !== '' ? $due : null,
        'installment' => mb_substr(trim((string)($src['installment'] ?? '')), 0, 60), 'notes' => mb_substr(trim((string)($src['notes'] ?? '')), 0, 2000),
    ] + ($dir === 'out' && db_has_column($pdo, 'fin_docs', 'acq_channel') ? [
        // custo de aquisição (Relatórios › Origens): o canal e a campanha/fonte que esta despesa pagou
        'acq_channel' => isset(LEAD_ORIGIN[$src['acq_channel'] ?? '']) ? (string)$src['acq_channel'] : '',
        'acq_source'  => mb_substr(trim((string)($src['acq_source'] ?? '')), 0, 160),
    ] : []);
}

/** Cria um lançamento. $v: o resultado de fin_doc_input mais status, pdf_key… Devolve o id. */
function fin_doc_create(PDO $pdo, array $v, ?int $by): int {
    $v += ['status' => $v['direction'] === 'in' ? 'issued' : 'to_pay', 'created_by' => $by, 'created_at' => fin_now(), 'updated_at' => fin_now()];
    $cols = array_keys($v);
    $pdo->prepare('INSERT INTO fin_docs (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($v));
    $id = (int)$pdo->lastInsertId();
    if (!empty($v['deal_id'])) {
        deal_log($pdo, (int)$v['deal_id'], 'billing', ['note' => ($v['direction'] === 'in' ? 'Receita' : 'Despesa') . ' lançada no Financeiro · ' . fin_doc_label($v + ['id' => $id, 'req_code' => $v['req_code'] ?? '']) . ' · ' . eur((float)$v['total']), 'user_id' => $by]);
    }
    return $id;
}

/** Atualiza campos de um lançamento (sem mexer nos pagamentos); o estado volta a ser calculado. */
function fin_doc_update(PDO $pdo, int $id, array $v): void {
    $v['updated_at'] = fin_now();
    $pdo->prepare('UPDATE fin_docs SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($v))) . ' WHERE id = ?')->execute([...array_values($v), $id]);
    fin_doc_refresh($pdo, $id);
}

/**
 * Recalcula o que foi pago e o estado. Pedida, por confirmar, anulada e em disputa ficam como estão;
 * as outras seguem os pagamentos: nada → Emitida / Por pagar; parte → Parcialmente paga;
 * tudo → Paga (ou Recibo arquivado, com o recibo junto).
 */
function fin_doc_refresh(PDO $pdo, int $id): ?array {
    $d = fin_doc($pdo, $id);
    if (!$d) return null;
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount), 0), MAX(paid_on) FROM fin_payments WHERE doc_id = ? AND deleted_at IS NULL');
    $st->execute([$id]);
    [$paid, $last] = $st->fetch(PDO::FETCH_NUM);
    $paid = round((float)$paid, 2);
    $status = (string)$d['status'];
    if (!in_array($status, ['requested', 'review', 'void', 'dispute'], true)) {
        $due = fin_doc_due($d);
        if ($paid > 0 && $paid >= $due - 0.005) $status = $d['receipt_key'] !== '' ? 'archived' : 'paid';
        elseif ($paid > 0) $status = 'partial';
        else $status = $d['direction'] === 'in' ? 'issued' : 'to_pay';
    }
    $paidDate = ($status === 'paid' || $status === 'archived') ? ($last ?: $d['paid_date']) : null;
    if ($paid !== round((float)$d['paid_amount'], 2) || $status !== $d['status'] || (string)$paidDate !== (string)$d['paid_date']) {
        $pdo->prepare('UPDATE fin_docs SET paid_amount = ?, status = ?, paid_date = ?, updated_at = ? WHERE id = ?')->execute([$paid, $status, $paidDate, fin_now(), $id]);
        $was = $d['status'];
        $d = fin_doc($pdo, $id);
        if ($d['deal_id'] && $d['direction'] === 'in' && in_array($status, ['paid', 'archived'], true) && !in_array($was, ['paid', 'archived'], true)) fin_deal_paid_check($pdo, (int)$d['deal_id']);
    }
    return $d;
}

/** Mudar o estado à mão: anular (com nota), em disputa, voltar. Devolve a mensagem. */
function fin_doc_set_status(PDO $pdo, array $d, string $to, string $note, ?int $by): string {
    $allowed = $d['direction'] === 'in' ? ['void', 'issued'] : ['void', 'dispute', 'to_pay'];
    if (!in_array($to, $allowed, true)) throw new RuntimeException('Estado inválido.');
    if ($to === 'void' && (float)$d['paid_amount'] > 0) throw new RuntimeException('Tem pagamentos registados: anule-os primeiro (ou registe a nota de crédito).');
    if ($to === 'issued' && $d['status'] === 'requested' && $d['number'] === '') throw new RuntimeException('Falta o número da fatura emitida: junte o PDF.');
    $pdo->prepare('UPDATE fin_docs SET status = ?, notes = ?, updated_at = ? WHERE id = ?')
        ->execute([$to, trim($d['notes'] . ($note !== '' ? "\n" . date('d/m/Y') . ' · ' . $note : '')), fin_now(), (int)$d['id']]);
    if (in_array($to, ['issued', 'to_pay'], true)) fin_doc_refresh($pdo, (int)$d['id']);
    if ($d['deal_id']) deal_log($pdo, (int)$d['deal_id'], 'billing', ['note' => fin_doc_label($d) . ': ' . FIN_ST[$to] . ($note !== '' ? ' · ' . $note : ''), 'user_id' => $by]);
    return fin_doc_label($d) . ': ' . FIN_ST[$to] . '.';
}

// ── pagamentos ──────────────────────────────────────────────

function fin_payments(PDO $pdo, int $docId): array {
    $st = $pdo->prepare('SELECT p.*, u.name AS who FROM fin_payments p LEFT JOIN users u ON u.id = p.created_by WHERE p.doc_id = ? AND p.deleted_at IS NULL ORDER BY p.paid_on, p.id');
    $st->execute([$docId]);
    return $st->fetchAll();
}

/**
 * Regista um pagamento (parcial ou total). Exige comprovativo — o ficheiro ($proof: [bytes, nome, mime])
 * ou o movimento bancário conciliado ($txId). Não deixa pagar mais do que falta.
 */
function fin_pay_add(PDO $pdo, array $d, array $in, ?array $proof, ?int $txId, ?int $by): int {
    if (in_array($d['status'], ['void', 'requested', 'review'], true)) throw new RuntimeException($d['status'] === 'requested' ? 'A fatura ainda não foi emitida.' : ($d['status'] === 'review' ? 'Confirme primeiro o lançamento.' : 'O lançamento está anulado.'));
    $amount = fin_money($in['amount'] ?? 0);
    $left = fin_doc_left($d);
    if ($amount <= 0) throw new RuntimeException('Indique o valor pago.');
    if ($amount > $left + 0.01) throw new RuntimeException('O valor passa o que falta pagar (' . eur($left) . ').');
    $date = (string)($in['paid_on'] ?? '');
    if (!fin_date_ok($date)) throw new RuntimeException('Indique a data do pagamento.');
    if ($date > today_lisbon()) throw new RuntimeException('A data do pagamento não pode ser no futuro.');
    if (!$proof && !$txId) throw new RuntimeException('Junte o comprovativo do pagamento (PDF ou imagem) ou concilie com o movimento do extrato.');
    $method = isset(FIN_METHODS[$in['method'] ?? '']) ? (string)$in['method'] : 'transfer';
    [$key, $name] = $proof ? fin_file_store($pdo, $proof[0], fin_file_name($d, 'Comprovativo', $proof[1]), $proof[2], $d, 'receipt') : ['', ''];
    $pdo->prepare('INSERT INTO fin_payments (doc_id, amount, paid_on, method, proof_key, proof_name, bank_tx_id, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$d['id'], $amount, $date, $method, $key, $name, $txId, mb_substr(trim((string)($in['note'] ?? '')), 0, 255), $by, fin_now()]);
    $pid = (int)$pdo->lastInsertId();
    fin_doc_refresh($pdo, (int)$d['id']);
    if ($d['deal_id']) deal_log($pdo, (int)$d['deal_id'], 'billing', ['note' => ($d['direction'] === 'in' ? 'Recebido' : 'Pago') . ' ' . eur($amount) . ' · ' . fin_doc_label($d) . ' · ' . fin_date_pt($date) . ($txId ? ' (extrato)' : ''), 'user_id' => $by]);
    return $pid;
}

/** Anula um pagamento (fica marcado, não se apaga). */
function fin_pay_del(PDO $pdo, int $payId): ?array {
    $st = $pdo->prepare('SELECT * FROM fin_payments WHERE id = ? AND deleted_at IS NULL');
    $st->execute([$payId]);
    $p = $st->fetch();
    if (!$p) return null;
    $pdo->prepare('UPDATE fin_payments SET deleted_at = ? WHERE id = ?')->execute([fin_now(), $payId]);
    if ($p['bank_tx_id']) $pdo->prepare("UPDATE fin_bank_tx SET status = 'open', doc_id = NULL, payment_id = NULL WHERE id = ?")->execute([(int)$p['bank_tx_id']]);
    fin_doc_refresh($pdo, (int)$p['doc_id']);
    return $p;
}

// ── ficheiros ───────────────────────────────────────────────

/** Um ficheiro enviado (PDF ou imagem), validado. Devolve [bytes, nome, mime] ou null se não veio nenhum. */
function fin_upload(?array $f, bool $images = true): ?array {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('O ficheiro não chegou inteiro. Tente outra vez.');
    if ((int)$f['size'] > FIN_MAX_BYTES) throw new RuntimeException('O ficheiro tem mais de 15 MB.');
    $bytes = (string)file_get_contents($f['tmp_name']);
    $mime = fin_sniff($bytes);
    if ($mime === '' || (!$images && $mime !== 'application/pdf')) throw new RuntimeException($images ? 'O ficheiro tem de ser PDF, JPG ou PNG.' : 'O ficheiro tem de ser um PDF.');
    return [$bytes, (string)$f['name'], $mime];
}

/** O tipo pelo conteúdo (não pelo nome): PDF, JPEG, PNG, ou ''. */
function fin_sniff(string $b): string {
    if (strncmp($b, '%PDF-', 5) === 0) return 'application/pdf';
    if (strncmp($b, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
    if (strncmp($b, "\x89PNG", 4) === 0) return 'image/png';
    return '';
}

/**
 * O ficheiro que a IA leu fica guardado à parte (inc/cache, fechado à web) até o utilizador confirmar o
 * formulário; o token só serve a quem o enviou e dura 6 horas.
 */
function fin_tmp_dir(): string {
    $d = __DIR__ . '/cache/fin_tmp';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

function fin_tmp_put(array $f, int $uid): string {
    $d = fin_tmp_dir();
    foreach (glob($d . '/*.json') ?: [] as $old) if (filemtime($old) < time() - 21600) { @unlink($old); @unlink(substr($old, 0, -5) . '.bin'); }
    $tok = bin2hex(random_bytes(16));
    if (file_put_contents($d . '/' . $tok . '.bin', $f[0]) === false) throw new RuntimeException('Não foi possível guardar o ficheiro. Tente outra vez.');
    file_put_contents($d . '/' . $tok . '.json', json_encode(['uid' => $uid, 'name' => $f[1], 'mime' => $f[2], 'at' => time()]));
    return $tok;
}

/** O ficheiro de um token (e apaga-o): [bytes, nome, mime] ou null. */
function fin_tmp_take(string $tok, int $uid): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $tok)) return null;
    $base = fin_tmp_dir() . '/' . $tok;
    $meta = is_file($base . '.json') ? json_decode((string)file_get_contents($base . '.json'), true) : null;
    if (!$meta || (int)$meta['uid'] !== $uid || !is_file($base . '.bin') || (int)$meta['at'] < time() - 21600) return null;
    $bytes = (string)file_get_contents($base . '.bin');
    @unlink($base . '.bin'); @unlink($base . '.json');
    return [$bytes, (string)$meta['name'], (string)$meta['mime']];
}

/** «2026-10-23_FT-2026-142_Cliente.pdf» */
function fin_file_name(array $d, string $what, string $orig = ''): string {
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION)) ?: 'pdf';
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) $ext = 'pdf';
    $parts = [date('Y-m-d', strtotime((string)($d['issue_date'] ?: today_lisbon()))), $what];
    if (($d['number'] ?? '') !== '') $parts[] = preg_replace('/[^A-Za-z0-9-]+/', '-', (string)$d['number']);
    if (($d['party_name'] ?? '') !== '') $parts[] = mb_substr(preg_replace('/[^\p{L}\p{N}-]+/u', '-', (string)$d['party_name']), 0, 40);
    return trim(implode('_', $parts), '-_') . '.' . $ext;
}

/** A pasta local (fora da web) quando o Drive não está ligado. */
function fin_local_dir(): string {
    $dir = __DIR__ . '/fin_files';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir;
}

/**
 * Guarda no Drive em «Empresa/Financeiro/AAAA/MM/» (só quem vê o Financeiro vê essa pasta) e, se for de um
 * negócio, uma cópia na pasta «Faturação» dele. Sem Drive, numa pasta privada do servidor.
 * Devolve [chave «r2:…» ou «local:…», nome].
 */
function fin_file_store(PDO $pdo, string $bytes, string $name, string $mime, array $d, string $origin = 'invoice'): array {
    $ref = (string)($d['issue_date'] ?: today_lisbon());
    require_once __DIR__ . '/r2.php';
    if (r2_config()) {
        require_once __DIR__ . '/files.php';
        $by = current_user()['id'] ?? null;
        $r = files_put($pdo, ['prefix' => 'Empresa/Financeiro/' . substr($ref, 0, 4) . '/' . substr($ref, 5, 2) . '/', 'name' => $name, 'mime' => $mime,
                              'origin' => $origin, 'label' => $origin === 'receipt' ? 'Comprovativo de pagamento' : 'Documento financeiro', 'by' => $by,
                              'deal_id' => $d['deal_id'] ?? null], $bytes);
        if ($r) {
            if (!empty($d['deal_id'])) {
                try {
                    files_put($pdo, ['deal_id' => (int)$d['deal_id'], 'section' => 'faturacao', 'name' => $name, 'mime' => $mime, 'origin' => $origin,
                                     'label' => $origin === 'receipt' ? 'Comprovativo de pagamento' : 'Fatura', 'by' => $by], $bytes);
                } catch (Throwable $e) { error_log('fin copy to deal: ' . $e->getMessage()); }
            }
            return ['r2:' . $r['key'], $r['name']];
        }
    }
    $local = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if (file_put_contents(fin_local_dir() . '/' . $local, $bytes) === false) throw new RuntimeException('Não foi possível guardar o ficheiro. Tente outra vez.');
    return ['local:' . $local, $name];
}

/** Lê um ficheiro guardado (para anexar a um email ou pôr no ZIP). */
function fin_file_bytes(string $key): ?string {
    if (str_starts_with($key, 'r2:')) {
        require_once __DIR__ . '/r2.php';
        try { return r2_get(substr($key, 3)); } catch (Throwable $e) { return null; }
    }
    if (str_starts_with($key, 'local:')) {
        $p = fin_local_dir() . '/' . basename(substr($key, 6));
        return is_file($p) ? (string)file_get_contents($p) : null;
    }
    return null;
}

/** Envia o ficheiro ao browser (a permissão já foi vista por quem chama). */
function fin_file_send(string $key, string $name, bool $download = false): void {
    $disp = ($download ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"';
    if (str_starts_with($key, 'r2:')) {
        require_once __DIR__ . '/r2.php';
        header('Location: ' . r2_presign('GET', substr($key, 3), 300, ['response-content-disposition' => $disp]), true, 302);
        exit;
    }
    $p = fin_local_dir() . '/' . basename(substr($key, 6));
    if (!str_starts_with($key, 'local:') || !is_file($p)) { http_response_code(404); exit('Ficheiro não encontrado.'); }
    header('Content-Type: ' . (fin_sniff((string)file_get_contents($p, false, null, 0, 8)) ?: 'application/octet-stream'));
    header('Content-Disposition: ' . $disp);
    header('Content-Length: ' . filesize($p));
    header('X-Content-Type-Options: nosniff');
    readfile($p);
    exit;
}

function fin_file_href(string $what, int $id, bool $dl = false): string {
    return '/admin/fin_file.php?' . http_build_query([$what => $id] + ($dl ? ['dl' => 1] : []));
}

// ── a receber e a pagar ─────────────────────────────────────

/**
 * Por receber: as faturas emitidas em aberto e, da altura antes das faturas, os negócios por pagar sem
 * nenhum lançamento de receita (vencem «Por receber em atraso» dias depois da conclusão ou do mês do
 * trabalho, como antes). Cada linha: id (negócio), doc_id, code, name, contact_name, ref (vencimento),
 * age (dias de atraso; 0 = em dia), bucket, net, gross (o que falta), url, legacy.
 */
function fin_receivables(PDO $pdo): array {
    $rate = vat_rate($pdo);
    $lim = (int)setting($pdo, 'invoice_overdue_days', 30);
    $out = [];
    foreach (fin_docs($pdo, ['dir' => 'in', 'st' => ['issued', 'partial'], 'type' => ['invoice', 'receipt', 'other']]) as $d) {
        $left = fin_doc_left($d);
        if ($left <= 0) continue;
        $age = max(0, fin_overdue_days($d));
        $tot = (float)$d['total'] ?: 1;
        $out[] = ['id' => (int)$d['deal_id'], 'doc_id' => (int)$d['id'], 'code' => $d['deal_code'] ?: fin_doc_label($d), 'name' => $d['deal_name'] ?: $d['party_name'],
                  'number' => fin_doc_label($d), 'contact_name' => $d['party_name'], 'ref' => (string)($d['due_date'] ?: $d['issue_date']), 'age' => $age, 'bucket' => fin_aging($age),
                  'net' => round($left * (float)$d['base'] / $tot, 2), 'gross' => $left, 'url' => '/admin/financeiro.php?tab=receber&doc=' . (int)$d['id'], 'legacy' => false, 'status' => $d['status']];
    }
    $rows = $pdo->query(
        "SELECT d.id, d.code, d.name, d.budget, d.has_vat, d.year, d.month, d.done_at, d.created_at, c.name AS contact_name
           FROM deals d LEFT JOIN contacts c ON c.id = d.contact_id
          WHERE d.code IS NOT NULL AND d.phase IN ('production','done') AND d.is_paid = 0 AND d.budget > 0
            AND NOT EXISTS (SELECT 1 FROM fin_docs f WHERE f.deal_id = d.id AND f.direction = 'in' AND f.deleted_at IS NULL AND f.status <> 'void')"
    )->fetchAll();
    foreach ($rows as $r) {
        if ($r['done_at']) $ref = substr((string)$r['done_at'], 0, 10);
        elseif ($r['year']) $ref = (new DateTimeImmutable(sprintf('%04d-%02d-01', (int)$r['year'], (int)($r['month'] ?: 12))))->format('Y-m-t');
        else $ref = substr((string)$r['created_at'], 0, 10);
        $due = date('Y-m-d', strtotime($ref . ' +' . $lim . ' days'));
        $age = max(0, (int)floor((strtotime(today_lisbon()) - strtotime($due)) / 86400));
        $net = round((float)$r['budget'], 2);
        $out[] = ['id' => (int)$r['id'], 'doc_id' => 0, 'code' => $r['code'], 'name' => $r['name'], 'number' => 'Sem fatura registada', 'contact_name' => $r['contact_name'],
                  'ref' => $due, 'age' => $age, 'bucket' => fin_aging($age), 'net' => $net, 'gross' => (int)$r['has_vat'] ? round($net * (1 + $rate / 100), 2) : $net,
                  'url' => '/admin/financeiro.php?tab=receber&deal=' . (int)$r['id'], 'legacy' => true, 'status' => 'legacy'];
    }
    usort($out, fn($a, $b) => [$b['age'], $b['gross']] <=> [$a['age'], $a['gross']]);
    return $out;
}

/** A pagar: em aberto (por pagar, parcial, em disputa, por confirmar), do vencimento mais próximo. */
function fin_payables(PDO $pdo): array {
    $rows = fin_docs($pdo, ['dir' => 'out', 'st' => ['to_pay', 'partial', 'dispute', 'review']]);
    foreach ($rows as &$d) { $d['left'] = fin_doc_left($d); $d['overdue'] = $d['due_date'] ? fin_overdue_days($d) : 0; }
    unset($d);
    return $rows;
}

// ── os negócios ─────────────────────────────────────────────

/**
 * As despesas lançadas no Financeiro com âmbito neste negócio, no formato dos custos (deal_costs):
 * entram na margem e nos custos reais da ficha e da Orçamentação. Só leitura na ficha (editam-se no Financeiro).
 */
function fin_deal_cost_rows(PDO $pdo, int $dealId): array {
    if (!fin_ready($pdo)) return [];
    $st = $pdo->prepare("SELECT * FROM fin_docs WHERE deal_id = ? AND direction = 'out' AND deleted_at IS NULL AND status NOT IN ('void','review')
                          AND doc_type <> 'credit' ORDER BY COALESCE(issue_date, created_at), id");
    $st->execute([$dealId]);
    $out = [];
    foreach ($st->fetchAll() as $d) {
        $net = fin_doc_cost($d);
        $vat = (int)$d['vat_deductible'] ? (float)$d['vat'] : 0.0;
        $out[] = ['id' => 0, 'fin_id' => (int)$d['id'], 'deal_id' => $dealId, 'name' => $d['party_name'] ?: $d['description'], 'role' => $d['category'],
                  'contact_id' => $d['contact_id'], 'contact_name' => $d['party_name'], 'amount' => $net, 'vat_mode' => 'plus', 'is_closed' => in_array($d['status'], ['paid', 'archived'], true) ? 1 : 0,
                  'notes' => trim(fin_doc_label($d) . ($d['description'] !== '' && $d['party_name'] !== '' ? ' · ' . $d['description'] : '')), 'budget_key' => null,
                  'net' => $net, 'vat' => round($vat, 2), 'total' => round($net + $vat, 2), 'year' => null, 'month' => null];
    }
    // as reservas dos sócios (parte da faturação guardada para um sócio) também são custo do negócio
    require_once __DIR__ . '/fin_res.php';
    return array_merge($out, fin_res_cost_rows($pdo, $dealId));
}

/** Quando todas as faturas do negócio estão pagas e cobrem o valor, o negócio fica pago (e correm as regras «ao validar um pagamento»). */
function fin_deal_paid_check(PDO $pdo, int $dealId): void {
    $st = $pdo->prepare("SELECT COUNT(*) AS n, SUM(CASE WHEN status IN ('paid','archived') THEN 1 ELSE 0 END) AS p, COALESCE(SUM(base), 0) AS base
                           FROM fin_docs WHERE deal_id = ? AND direction = 'in' AND doc_type <> 'credit' AND deleted_at IS NULL AND status NOT IN ('void','requested','review')");
    $st->execute([$dealId]);
    $r = $st->fetch();
    if (!(int)$r['n'] || (int)$r['n'] !== (int)$r['p']) return;
    $st = $pdo->prepare('SELECT budget, is_paid FROM deals WHERE id = ?');
    $st->execute([$dealId]);
    $d = $st->fetch();
    if (!$d || (int)$d['is_paid'] || (float)$r['base'] < (float)$d['budget'] - 1) return;
    $pdo->prepare('UPDATE deals SET is_paid = 1 WHERE id = ?')->execute([$dealId]);
    deal_log($pdo, $dealId, 'billing', ['note' => 'Todas as faturas pagas · negócio pago']);
    if (function_exists('flow_on_event')) flow_on_event($pdo, $dealId, 'paid');
}

/**
 * A fatura passou a «Emitida» (submetida na tarefa, lida da caixa ou lançada aqui): fica também na faturação
 * do negócio (deal_billing, que os gates leem) e a tarefa «Emitir fatura» conclui com esse PDF.
 */
function fin_doc_issued(PDO $pdo, int $id, ?int $by): void {
    $d = fin_doc($pdo, $id);
    if (!$d || !$d['deal_id'] || $d['direction'] !== 'in' || $d['billing_id'] || !db_has_table($pdo, 'deal_billing')) return;
    $pdo->prepare("INSERT INTO deal_billing (deal_id, task_id, kind, number, doc_date, net, vat, total, file_key, file_name, note, created_by, created_at)
                   VALUES (?, NULL, 'invoice', ?, ?, ?, ?, ?, ?, ?, 'Financeiro', ?, ?)")
        ->execute([(int)$d['deal_id'], $d['number'], $d['issue_date'] ?: today_lisbon(), $d['base'], $d['vat'], $d['total'], $d['pdf_key'], $d['pdf_name'], $by, fin_now()]);
    $pdo->prepare('UPDATE fin_docs SET billing_id = ? WHERE id = ?')->execute([(int)$pdo->lastInsertId(), $id]);
    require_once __DIR__ . '/pipeline_flow.php';
    flow_complete_doc_tasks($pdo, (int)$d['deal_id'], 'invoice');
}

/**
 * «Emitir fatura» no pipeline (billing_submit_invoice): um só lançamento. Se havia um pedido ao contabilista
 * à espera, é esse que passa a «Emitida»; senão nasce um novo.
 */
function fin_from_billing(PDO $pdo, array $deal, int $billingId, string $number, string $date, float $net, float $vat, float $total, string $key, string $fname, ?int $by): void {
    if (!fin_ready($pdo)) return;
    $st = $pdo->prepare("SELECT id FROM fin_docs WHERE deal_id = ? AND direction = 'in' AND status = 'requested' AND deleted_at IS NULL ORDER BY id LIMIT 1");
    $st->execute([(int)$deal['id']]);
    $vals = ['number' => $number, 'issue_date' => $date, 'base' => $net, 'vat' => $vat, 'total' => $total, 'pdf_key' => $key, 'pdf_name' => $fname, 'billing_id' => $billingId,
             'vat_rate' => $net > 0 ? round($vat / $net * 100, 2) : 0, 'status' => 'issued', 'ready_at' => fin_now()];
    if ($id = (int)$st->fetchColumn()) {
        fin_doc_update($pdo, $id, $vals);
        return;
    }
    $c = billing_deal($pdo, (int)$deal['id']) ?? $deal;
    $terms = (int)setting($pdo, 'fin_terms_days', 30);
    fin_doc_create($pdo, $vals + ['direction' => 'in', 'doc_type' => 'invoice', 'scope' => 'deal', 'deal_id' => (int)$deal['id'], 'contact_id' => $deal['contact_id'] ?? null,
        'party_kind' => 'client', 'party_name' => (string)($c['contact_name'] ?? ''), 'party_nif' => (string)($c['contact_nif'] ?? ''), 'party_email' => (string)($c['contact_email'] ?? ''),
        'category' => '', 'description' => (string)$deal['name'], 'due_date' => date('Y-m-d', strtotime($date . ' +' . $terms . ' days'))], $by);
}

/** «Validar pagamento» no pipeline: com comprovativo, regista o pagamento na fatura em aberto mais antiga do negócio. */
function fin_from_billing_payment(PDO $pdo, array $deal, string $date, float $amount, string $key, string $fname, ?int $by): string {
    if (!fin_ready($pdo)) return '';
    $open = fin_docs($pdo, ['dir' => 'in', 'deal' => (int)$deal['id'], 'st' => ['issued', 'partial'], 'order' => 'f.issue_date, f.id']);
    if (!$open) return '';
    if ($key === '') return ' A fatura ' . fin_doc_label($open[0]) . ' continua por receber no Financeiro até haver comprovativo.';
    $d = $open[0];
    $amt = $amount > 0 ? min($amount, fin_doc_left($d)) : fin_doc_left($d);
    $pdo->prepare('INSERT INTO fin_payments (doc_id, amount, paid_on, method, proof_key, proof_name, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([(int)$d['id'], $amt, $date, 'transfer', $key, $fname, 'Validado no pipeline', $by, fin_now()]);
    fin_doc_refresh($pdo, (int)$d['id']);
    return '';
}

// ── custos da empresa (para o resultado e a Orçamentação) ────

/**
 * As despesas da empresa (âmbito Empresa) de um ano, no formato antigo de company_cost_rows: id, year, month,
 * name, amount, vat_mode, is_closed, notes, net (custo), vat (dedutível), total. Os impostos sobre o lucro e o IVA
 * entregue não são custo (doc_type «tax» com categoria IVA).
 */
function fin_company_costs(PDO $pdo, ?int $year = null): array {
    $w = "direction = 'out' AND scope = 'company' AND deleted_at IS NULL AND status NOT IN ('void','review') AND doc_type <> 'credit' AND category <> 'IVA'";
    $a = [];
    if ($year) { $w .= ' AND issue_date >= ? AND issue_date <= ?'; $a = [$year . '-01-01', $year . '-12-31']; }
    $st = $pdo->prepare("SELECT * FROM fin_docs WHERE $w ORDER BY issue_date DESC, id DESC");
    $st->execute($a);
    $out = [];
    foreach ($st->fetchAll() as $d) {
        $net = fin_doc_cost($d);
        $vat = (int)$d['vat_deductible'] ? (float)$d['vat'] : 0.0;
        $out[] = ['id' => (int)$d['id'], 'year' => $d['issue_date'] ? (int)substr($d['issue_date'], 0, 4) : null, 'month' => $d['issue_date'] ? (int)substr($d['issue_date'], 5, 2) : null,
                  'name' => trim(($d['description'] ?: $d['party_name']) ?: $d['category']), 'category' => $d['category'], 'amount' => $net, 'vat_mode' => 'plus',
                  'is_closed' => in_array($d['status'], ['paid', 'archived'], true) ? 1 : 0, 'notes' => (string)$d['notes'], 'net' => $net, 'vat' => round($vat, 2), 'total' => round($net + $vat, 2)];
    }
    return $out;
}

// ── custos fixos ────────────────────────────────────────────

function fin_fixed_list(PDO $pdo, bool $activeOnly = false): array {
    return $pdo->query('SELECT * FROM fin_fixed' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, category, name')->fetchAll();
}

/** O custo mensal: o valor (mais o IVA quando não é dedutível) a dividir pelos meses da periodicidade. */
function fin_fixed_monthly(array $f): float {
    $vat = (int)$f['vat_deductible'] ? 0.0 : (float)$f['amount'] * (float)$f['vat_rate'] / 100;
    return round(((float)$f['amount'] + $vat) / max(1, (int)$f['period_months']), 2);
}

/** Ativo numa data (entre o início e o fim)? */
function fin_fixed_on(array $f, string $date): bool {
    return (int)$f['active'] === 1 && $f['start_date'] <= $date && (!$f['end_date'] || $f['end_date'] >= $date);
}

function fin_fixed_input(PDO $pdo, array $src): array {
    $name = mb_substr(trim((string)($src['name'] ?? '')), 0, 160);
    if ($name === '') throw new RuntimeException('Indique o nome do custo.');
    $amount = fin_money($src['amount'] ?? 0);
    if ($amount <= 0) throw new RuntimeException('Indique o valor (sem IVA).');
    $period = (int)($src['period_months'] ?? 1);
    if (!isset(FIN_PERIODS[$period])) throw new RuntimeException('Periodicidade inválida.');
    $start = (string)($src['start_date'] ?? '');
    if (!fin_date_ok($start)) throw new RuntimeException('Indique a data de início.');
    $end = (string)($src['end_date'] ?? '');
    if ($end !== '' && (!fin_date_ok($end) || $end < $start)) throw new RuntimeException('A data de fim tem de ser depois do início.');
    $day = max(1, min(28, (int)($src['due_day'] ?? (int)substr($start, 8, 2))));
    $cat = trim((string)($src['category'] ?? ''));
    if (!in_array($cat, fin_categories($pdo), true)) $cat = 'Outros';
    $rate = (float)str_replace(',', '.', (string)($src['vat_rate'] ?? 0));
    if ($rate < 0 || $rate > 50) throw new RuntimeException('Taxa de IVA inválida.');
    $wh = (float)str_replace(',', '.', (string)($src['wh_rate'] ?? 0));
    if ($wh < 0 || $wh > 50) throw new RuntimeException('Taxa de retenção inválida.');
    return ['name' => $name, 'wh_rate' => $wh, 'category' => $cat, 'party_name' => mb_substr(trim((string)($src['party_name'] ?? '')), 0, 190),
            'party_nif' => mb_substr(preg_replace('/[^A-Za-z0-9]/', '', strtoupper((string)($src['party_nif'] ?? ''))), 0, 20),
            'amount' => $amount, 'vat_rate' => $rate, 'vat_deductible' => !empty($src['vat_deductible']) ? 1 : 0, 'period_months' => $period, 'due_day' => $day,
            'start_date' => $start, 'end_date' => $end !== '' ? $end : null, 'pay_method' => isset(FIN_METHODS[$src['pay_method'] ?? '']) ? (string)$src['pay_method'] : 'transfer',
            'active' => !isset($src['active']) || !empty($src['active']) ? 1 : 0, 'notes' => mb_substr(trim((string)($src['notes'] ?? '')), 0, 500)];
}

/** As datas de vencimento de um custo fixo, do início até $until (inclusive). */
function fin_fixed_dates(array $f, string $until): array {
    $out = [];
    $start = new DateTimeImmutable(substr((string)$f['start_date'], 0, 7) . '-01');
    $day = max(1, min(28, (int)$f['due_day']));
    for ($i = 0; $i < 600; $i += max(1, (int)$f['period_months'])) {
        $m = $start->modify("+$i month");
        $date = $m->format('Y-m-') . sprintf('%02d', min($day, (int)$m->format('t')));
        if ($date > $until) break;
        if ($date < $f['start_date'] && $i === 0 && (int)$f['period_months'] === 1) continue;
        if ($f['end_date'] && $date > $f['end_date']) break;
        $out[] = $date;
    }
    return $out;
}

/**
 * Gera os lançamentos «Por pagar» de cada custo fixo ativo, um por vencimento, até ao fim do mês corrente
 * (os já gerados não se repetem; um custo novo só gera a partir do mês em que foi criado). Devolve quantos.
 */
function fin_fixed_tick(PDO $pdo, ?string $today = null): int {
    if (!fin_ready($pdo)) return 0;
    $today ??= today_lisbon();
    $until = date('Y-m-t', strtotime($today));
    $n = 0;
    $have = $pdo->prepare('SELECT COUNT(*) FROM fin_docs WHERE fixed_id = ? AND issue_date = ?');
    foreach (fin_fixed_list($pdo, true) as $f) {
        $from = max(substr((string)$f['created_at'], 0, 7) . '-01', (string)$f['start_date']);
        foreach (fin_fixed_dates($f, $until) as $date) {
            if ($date < $from) continue;
            $have->execute([(int)$f['id'], $date]);
            if ((int)$have->fetchColumn()) continue;
            $vat = round((float)$f['amount'] * (float)$f['vat_rate'] / 100, 2);
            $wh = round((float)$f['amount'] * (float)$f['wh_rate'] / 100, 2);
            fin_doc_create($pdo, ['direction' => 'out', 'doc_type' => 'invoice', 'scope' => 'company', 'party_kind' => $f['category'] === 'Renda' ? 'landlord' : 'supplier',
                'party_name' => $f['party_name'] ?: $f['name'], 'party_nif' => $f['party_nif'], 'category' => $f['category'], 'description' => $f['name'] . ' · ' . month_year((int)substr($date, 5, 2), (int)substr($date, 0, 4)),
                'base' => (float)$f['amount'], 'vat_rate' => (float)$f['vat_rate'], 'vat' => $vat, 'vat_deductible' => (int)$f['vat_deductible'], 'total' => round((float)$f['amount'] + $vat, 2),
                'wh_rate' => (float)$f['wh_rate'], 'withholding' => $wh, 'issue_date' => $date, 'due_date' => $date, 'fixed_id' => (int)$f['id'], 'status' => 'to_pay'], null);
            $n++;
        }
    }
    return $n;
}

/**
 * O total mensal de custos fixos — a base do runway: os custos fixos ativos hoje, mais o custo mensal de cada
 * colaborador com ficha de salário (14 meses de bruto × (1 + TSU) + refeição + seguro, a dividir por 12).
 */
function fin_fixed_total(PDO $pdo, ?string $date = null): array {
    $date ??= today_lisbon();
    $byCat = []; $fixed = 0.0; $rows = [];
    foreach (fin_fixed_list($pdo, true) as $f) {
        if (!fin_fixed_on($f, $date)) continue;
        $m = fin_fixed_monthly($f);
        $fixed += $m;
        $byCat[$f['category']] = ($byCat[$f['category']] ?? 0) + $m;
        $rows[] = $f + ['monthly' => $m];
    }
    require_once __DIR__ . '/fin_pay.php';
    $sal = 0.0;
    foreach (fin_employees($pdo) as $e) {
        if (!(int)$e['active'] || ($e['start_date'] && $e['start_date'] > $date)) continue;
        $sal += fin_employee_annual($pdo, $e)['monthly'];
    }
    if ($sal > 0) $byCat['Salários'] = ($byCat['Salários'] ?? 0) + $sal;
    arsort($byCat);
    return ['fixed' => round($fixed, 2), 'salaries' => round($sal, 2), 'total' => round($fixed + $sal, 2), 'by_cat' => array_map(fn($v) => round($v, 2), $byCat), 'rows' => $rows];
}

/** As mudanças dos custos fixos nos últimos meses: «+120 €/mês desde março: novo Software». */
function fin_fixed_changes(PDO $pdo, int $months = 6): array {
    $out = [];
    $list = fin_fixed_list($pdo);
    $first = (new DateTimeImmutable(today_lisbon()))->modify('first day of this month');
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = $first->modify("-$i month");
        $cur = $m->format('Y-m-d');
        $prev = $m->modify('-1 day')->format('Y-m-d');
        foreach ($list as $f) {
            // um custo desligado sem data de fim acabou quando foi desligado
            $end = $f['end_date'] ?: ((int)$f['active'] ? null : substr((string)$f['updated_at'], 0, 10));
            $on = fn(string $day) => $f['start_date'] <= $day && ($end === null || $end >= $day);
            $was = $on($prev); $is = $on($cur);
            if ($was === $is) continue;
            $v = fin_fixed_monthly($f);
            $out[] = ['date' => $cur, 'delta' => $is ? $v : -$v,
                      'text' => ($is ? '+' : '−') . eur0($v) . '/mês desde ' . mb_strtolower(MONTHS_PT[(int)$m->format('n')]) . ': ' . ($is ? 'novo ' : 'terminou ') . $f['name'] . ($f['category'] ? ' (' . $f['category'] . ')' : '')];
        }
    }
    return array_reverse($out);
}

// ── alertas e cron ──────────────────────────────────────────

/**
 * Os alertas do Financeiro para quem tem sessão (entram no sino e no menu, tipo «invoice»): faturas prontas a
 * enviar (também ao comercial do negócio), emails por confirmar, faturas a pagar vencidas, obrigações fiscais
 * nos próximos 7 dias e o runway (só sócios/admins).
 */
function fin_alerts(PDO $pdo, array $me): array {
    if (!fin_ready($pdo)) return [];
    $out = [];
    $uid = (int)$me['id'];
    $fin = can('financeiro');
    // fatura pronta para enviar ao cliente
    $st = $pdo->query("SELECT f.*, d.code AS deal_code, d.owner_id AS deal_owner FROM fin_docs f LEFT JOIN deals d ON d.id = f.deal_id
                        WHERE f.direction = 'in' AND f.status = 'issued' AND f.ready_at IS NOT NULL AND f.client_sent_at IS NULL AND f.deleted_at IS NULL ORDER BY f.ready_at DESC LIMIT 20");
    foreach ($st->fetchAll() as $d) {
        if (!$fin && (int)$d['deal_owner'] !== $uid) continue;
        $out[] = ['key' => 'finready:' . $d['id'], 'kind' => 'invoice', 'icon' => 'financeiro', 'sev' => 'warn', 'date' => substr((string)$d['ready_at'], 0, 10),
                  'title' => 'A fatura ' . fin_doc_label($d) . ($d['deal_code'] ? ' do ' . $d['deal_code'] : '') . ' está pronta para enviar ao cliente',
                  'sub' => $d['party_name'] . ' · ' . eur((float)$d['total']), 'url' => $fin ? '/admin/financeiro.php?tab=receber&doc=' . (int)$d['id'] : '/admin/negocio.php?id=' . (int)$d['deal_id']];
    }
    if (!$fin) return $out;
    $n = (int)$pdo->query("SELECT COUNT(*) FROM fin_mail WHERE status = 'review'")->fetchColumn()
       + (int)$pdo->query("SELECT COUNT(*) FROM fin_docs WHERE status = 'review' AND deleted_at IS NULL")->fetchColumn();
    if ($n) $out[] = ['key' => 'finreview:' . $n, 'kind' => 'invoice', 'icon' => 'financeiro', 'sev' => 'warn', 'date' => today_lisbon(),
                      'title' => $n . ($n === 1 ? ' documento por confirmar' : ' documentos por confirmar'), 'sub' => 'Chegaram por email (QR fiscal, XML ou IA)', 'url' => '/admin/financeiro.php?tab=pagar&v=review'];
    $late = $pdo->query("SELECT COUNT(*), COALESCE(SUM(total - withholding - paid_amount), 0) FROM fin_docs WHERE direction = 'out' AND status IN ('to_pay','partial')
                          AND due_date < '" . today_lisbon() . "' AND deleted_at IS NULL")->fetch(PDO::FETCH_NUM);
    if ((int)$late[0]) $out[] = ['key' => 'finlate:' . $late[0] . ':' . today_lisbon(), 'kind' => 'invoice', 'icon' => 'financeiro', 'sev' => 'bad', 'date' => today_lisbon(),
                                 'title' => (int)$late[0] . ((int)$late[0] === 1 ? ' fatura a pagar vencida' : ' faturas a pagar vencidas'), 'sub' => eur((float)$late[1]) . ' em atraso', 'url' => '/admin/financeiro.php?tab=pagar&v=late'];
    require_once __DIR__ . '/fin_tax.php';
    foreach (fin_obligations($pdo, today_lisbon(), 7) as $o) {
        $out[] = ['key' => 'finob:' . $o['key'], 'kind' => 'invoice', 'icon' => 'financeiro', 'sev' => $o['date'] <= today_lisbon() ? 'bad' : 'warn', 'date' => $o['date'],
                  'title' => $o['name'], 'sub' => 'Até ' . fin_date_pt($o['date']) . ($o['amount'] > 0 ? ' · ' . eur($o['amount']) : ''), 'url' => '/admin/financeiro.php?tab=impostos'];
    }
    // saldos das contas com mais de uma semana (ou nunca atualizados)
    require_once __DIR__ . '/fin_cash.php';
    $old = array_values(array_filter(fin_accounts($pdo, true), 'fin_balance_stale'));
    if ($old) $out[] = ['key' => 'finbal:' . count($old) . ':' . fin_balance_week($pdo)['key'], 'kind' => 'invoice', 'icon' => 'financeiro', 'sev' => 'warn', 'date' => today_lisbon(),
                        'title' => count($old) === 1 ? 'O saldo de ' . $old[0]['name'] . ' está desatualizado' : count($old) . ' contas com o saldo desatualizado',
                        'sub' => 'Atualizar uma vez por semana · ' . implode(', ', array_map(fn($a) => $a['name'] . ' ' . fin_age_label(fin_balance_age($a)), array_slice($old, 0, 3))),
                        'url' => '/admin/financeiro.php?tab=tesouraria'];
    if (role_is_admin((string)$me['role'])) {
        $rw = fin_runway($pdo);
        if ($rw['burn'] > 0 && $rw['useful'] !== null && $rw['useful'] < fin_p($pdo, 'runway_alert', 6)) {
            $in = $rw['useful'] <= 0;
            $out[] = ['key' => 'finrunway:' . ($in ? 'reserve' : 'low') . ':' . date('Y-m'), 'kind' => 'invoice', 'icon' => 'financeiro', 'sev' => $in ? 'bad' : 'warn', 'date' => today_lisbon(),
                      'title' => $in ? 'Entrámos na reserva de emergência' : 'Runway útil abaixo de ' . fin_num(fin_p($pdo, 'runway_alert', 6), 0) . ' meses',
                      'sub' => fin_num(max(0, $rw['useful']), 1) . ' meses úteis · reserva de ' . fin_num($rw['reserve_months'], 0) . ' meses', 'url' => '/admin/financeiro.php'];
        }
    }
    return $out;
}

/** O que corre todos os dias (e a cada 5 minutos, é barato): custos fixos, tarefas do calendário fiscal. */
function fin_tick(PDO $pdo): string {
    if (!fin_ready($pdo)) return '';
    $n = fin_fixed_tick($pdo);
    require_once __DIR__ . '/fin_tax.php';
    $t = fin_obligation_tasks($pdo);
    require_once __DIR__ . '/fin_res.php';
    $b = fin_balance_tick($pdo);
    return 'Financeiro: ' . $n . ' lançamentos de custos fixos · ' . $t . ' tarefas do calendário fiscal · ' . $b . ' tarefas de saldos';
}

// ── anular ──────────────────────────────────────────────────

/** Repõe o que uma ação do Financeiro mudou (undo.php → 'fin'). */
function fin_undo_apply(PDO $pdo, array $d): string {
    if ($d['op'] === 'balance') {   // o responsável pela conta também anula a sua atualização
        require_once __DIR__ . '/fin_res.php';
        $row = (array)$d['row'];
        $acc = fin_account($pdo, (int)$row['id']);
        if (!$acc || !fin_can_balance($pdo, $acc, current_user())) throw new RuntimeException('Sem permissão.');
        $pdo->prepare('UPDATE fin_accounts SET balance = ?, balance_date = ?, balance_by = ?, balance_at = ? WHERE id = ?')
            ->execute([$row['balance'], $row['balance_date'], $row['balance_by'] ?? null, $row['balance_at'] ?? null, (int)$row['id']]);
        $pdo->prepare('DELETE FROM fin_balance_log WHERE id = ?')->execute([(int)$d['log']]);
        return 'Saldo reposto.';
    }
    require_perm('financeiro', PERM_EDIT);
    if (in_array($d['op'], ['res_rule', 'payout'], true) && !role_is_admin((string)(current_user()['role'] ?? ''))) throw new RuntimeException('Sem permissão.');
    switch ($d['op']) {
        case 'res_rule':  // uma reserva: a nova sai, a apagada volta, a mudada fica como era
            if (!empty($d['new'])) { $pdo->prepare('UPDATE fin_reserve_rules SET deleted_at = ? WHERE id = ?')->execute([fin_now(), (int)$d['id']]); return 'Reserva retirada.'; }
            $row = (array)$d['row'];
            $pdo->prepare('UPDATE fin_reserve_rules SET mode = ?, value = ?, note = ?, deleted_at = NULL WHERE id = ?')->execute([$row['mode'], $row['value'], $row['note'], (int)$row['id']]);
            return 'Reserva reposta.';
        case 'payout':
            $pdo->prepare('UPDATE fin_reserve_payouts SET deleted_at = ' . (!empty($d['new']) ? '?' : 'NULL') . ' WHERE id = ?')->execute(!empty($d['new']) ? [fin_now(), (int)$d['id']] : [(int)$d['id']]);
            return !empty($d['new']) ? 'Levantamento retirado.' : 'Levantamento reposto.';
        case 'doc':       // um lançamento volta a ser o que era (campos e estado)
            $row = (array)$d['row'];
            $id = (int)$row['id'];
            unset($row['id'], $row['deal_code'], $row['deal_name'], $row['deal_owner']);
            $pdo->prepare('UPDATE fin_docs SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($row))) . ' WHERE id = ?')->execute([...array_values($row), $id]);
            fin_doc_refresh($pdo, $id);
            return 'Lançamento reposto.';
        case 'doc_new':   // um lançamento acabado de criar sai (fica marcado como apagado)
            $pdo->prepare('UPDATE fin_docs SET deleted_at = ? WHERE id = ?')->execute([fin_now(), (int)$d['id']]);
            $pdo->prepare('UPDATE fin_payments SET deleted_at = ? WHERE doc_id = ? AND deleted_at IS NULL')->execute([fin_now(), (int)$d['id']]);
            return 'Lançamento retirado.';
        case 'doc_del':
            $pdo->prepare('UPDATE fin_docs SET deleted_at = NULL WHERE id = ?')->execute([(int)$d['id']]);
            fin_doc_refresh($pdo, (int)$d['id']);
            return 'Lançamento reposto.';
        case 'pay':       // o pagamento acabado de registar sai
            if (!fin_pay_del($pdo, (int)$d['id'])) throw new RuntimeException('O pagamento já não existe.');
            return 'Pagamento anulado.';
        case 'pay_del':
            $pdo->prepare('UPDATE fin_payments SET deleted_at = NULL WHERE id = ?')->execute([(int)$d['id']]);
            if (!empty($d['tx'])) $pdo->prepare("UPDATE fin_bank_tx SET status = 'matched', doc_id = ?, payment_id = ? WHERE id = ?")->execute([(int)$d['doc'], (int)$d['id'], (int)$d['tx']]);
            fin_doc_refresh($pdo, (int)$d['doc']);
            return 'Pagamento reposto.';
        case 'fixed':
            $row = (array)$d['row'];
            if (!empty($d['new'])) { $pdo->prepare('DELETE FROM fin_fixed WHERE id = ?')->execute([(int)$d['id']]); return 'Custo fixo retirado.'; }
            $id = (int)$row['id']; unset($row['id']);
            $pdo->prepare('UPDATE fin_fixed SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($row))) . ' WHERE id = ?')->execute([...array_values($row), $id]);
            return 'Custo fixo reposto.';
        case 'payroll':
            require_once __DIR__ . '/fin_pay.php';
            return fin_payroll_undo($pdo, $d);
        case 'tx':        // um movimento do extrato volta a estar por conciliar (ou deixa de estar ignorado)
            if (!empty($d['pay'])) fin_pay_del($pdo, (int)$d['pay']);
            $pdo->prepare("UPDATE fin_bank_tx SET status = 'open', doc_id = NULL, payment_id = NULL WHERE id = ?")->execute([(int)$d['id']]);
            if (!empty($d['doc_new'])) $pdo->prepare('UPDATE fin_docs SET deleted_at = ? WHERE id = ?')->execute([fin_now(), (int)$d['doc_new']]);
            return 'Movimento por conciliar.';
        case 'scenario':
            $pdo->prepare('UPDATE fin_scenarios SET deleted_at = NULL WHERE id = ?')->execute([(int)$d['id']]);
            return 'Cenário reposto.';
        case 'apply':     // um cenário aplicado: sai o custo fixo e o cenário volta a não aplicado
            foreach ((array)($d['fixed'] ?? []) as $fid) $pdo->prepare('DELETE FROM fin_fixed WHERE id = ?')->execute([(int)$fid]);
            $pdo->prepare('UPDATE fin_scenarios SET applied_at = NULL WHERE id = ?')->execute([(int)$d['id']]);
            return 'Cenário por aplicar.';
        case 'account':
            $row = (array)$d['row']; $id = (int)$row['id']; unset($row['id']);
            $pdo->prepare('UPDATE fin_accounts SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($row))) . ' WHERE id = ?')->execute([...array_values($row), $id]);
            return 'Conta reposta.';
        case 'mail':
            $pdo->prepare('UPDATE fin_mail SET status = ?, doc_id = ?, done_by = NULL, done_at = NULL WHERE id = ?')->execute([(string)$d['status'], $d['doc'] ?? null, (int)$d['id']]);
            if (!empty($d['doc_row'])) return fin_undo_apply($pdo, ['op' => 'doc', 'row' => $d['doc_row']]);
            if (!empty($d['doc_new'])) return fin_undo_apply($pdo, ['op' => 'doc_new', 'id' => $d['doc_new']]);
            return 'Email por confirmar.';
        case 'employee':
            if (!role_is_admin((string)(current_user()['role'] ?? '')) && !can('financeiro', PERM_EDIT)) throw new RuntimeException('Sem permissão.');
            $row = (array)$d['row'];
            if (!empty($d['new'])) { $pdo->prepare('DELETE FROM fin_employees WHERE user_id = ?')->execute([(int)$d['uid']]); return 'Ficha de salário retirada.'; }
            $uid = (int)$row['user_id']; unset($row['user_id']);
            $pdo->prepare('UPDATE fin_employees SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', array_keys($row))) . ' WHERE user_id = ?')->execute([...array_values($row), $uid]);
            return 'Ficha de salário reposta.';
    }
    throw new RuntimeException('Já não é possível anular.');
}
