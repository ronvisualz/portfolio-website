<?php
/**
 * topper. admin — os dados da empresa, na base de dados
 *
 * Trabalhos, a equipa/custos de cada trabalho, os custos da empresa, as
 * leads e os fornecedores. Até outubro de 2026 viviam na folha Google
 * «TOPPER DIGITAL»; o import_sheet.php trouxe-os de lá uma vez e a folha
 * ficou como arquivo.
 *
 *   data_all()                    tudo, já com as contas feitas (IVA, totais, gastos, lucro)
 *   data_job_*, data_crew_*,      gravar e apagar (lançam DataException com uma
 *   data_cost_*, data_lead_*,     mensagem em português que se mostra tal e qual)
 *   data_supplier_*
 *   data_try()                    corre uma escrita e deixa o resultado num aviso
 *
 * As contas seguem as fórmulas que a folha tinha, ao cêntimo: IVA de 23 %,
 * arredondado a 2 casas em cada linha; os gastos de um trabalho são o valor
 * manual, se houver, ou a soma (sem IVA) da equipa.
 *
 * Precisa de bootstrap.php ($pdo, MONTHS_PT, VAT_MODES, money_in…) e de
 * layout.php para o data_try (flash).
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** Um erro que se pode mostrar ao utilizador tal e qual. */
class DataException extends RuntimeException {}

/** A taxa de IVA das contas (era a das fórmulas da folha). */
const DATA_VAT = 23.0;

const JOB_TYPES       = ['Animação 3D', 'VFX', 'Edição', 'Produção Completa', 'Fotografia'];
const LEAD_TYPES      = ['Produtora', 'Agência de Marketing', 'Empresa Particular', 'Realizador', 'VFX company'];
const LEAD_SERVICES   = ['VFX / 3D', 'Marketing Buddy', 'Ambos'];
const LEAD_PRIORITIES = ['Alta', 'Média', 'Baixa'];
const SUPPLIER_CATEGORIES = ['Produção', 'Imagem', 'Som', 'Pós-produção', '3D / VFX', 'Elenco',
                             'Material / aluguer', 'Locais', 'Software / serviços', 'Outro'];
/** Avaliação: de «★» a «★★★★★» (guarda-se o número de estrelas). */
const SUPPLIER_RATINGS = ['★', '★★', '★★★', '★★★★', '★★★★★'];
const LEAD_STAGES = [
    'novo'        => 'Novo',
    'contactado'  => 'Contactado',
    'em_conversa' => 'Em conversa',
    'proposta'    => 'Proposta enviada',
    'ganho'       => 'Ganho',
    'perdido'     => 'Perdido',
];

// ── contas ──────────────────────────────────────────────────

/**
 * Um valor tal como está na fatura → [net, vat, total].
 *   none  sem IVA            net = valor
 *   plus  acresce IVA        IVA = valor × 23 %
 *   incl  IVA já incluído    net = valor ÷ 1,23
 */
function data_vat_split(float $v, string $mode): array {
    $r = DATA_VAT / 100;
    $net = $mode === 'incl' ? round($v / (1 + $r), 2) : $v;
    $vat = $mode === 'plus' ? round($v * $r, 2) : ($mode === 'incl' ? round($v - $v / (1 + $r), 2) : 0.0);
    return ['net' => $net, 'vat' => $vat, 'total' => round($net + $vat, 2)];
}

function data_vat_mode(string $m): string {
    return isset(VAT_MODES[$m]) ? $m : 'plus';
}

// ── leitura ─────────────────────────────────────────────────

/**
 * Tudo, numa só leitura por pedido:
 *   jobs, crew, company, leads, suppliers, years, fetched
 * Cada registo tem «id». Os campos calculados (net, vat, total, expenses,
 * profit, budget_gross, vat_in/out, crew_*) não se gravam: fazem-se aqui.
 */
function data_all(bool $fresh = false): array {
    global $pdo;
    if (!$fresh && !empty($GLOBALS['__data_all'])) return $GLOBALS['__data_all'];

    // Equipa
    $crew = [];
    foreach ($pdo->query('SELECT c.*, j.name AS job_name FROM job_crew c JOIN jobs j ON j.id = c.job_id ORDER BY c.job_id, c.position, c.id') as $r) {
        $mode = data_vat_mode((string)$r['vat_mode']);
        $fee  = (float)$r['fee'];
        $crew[] = [
            'id'           => (int)$r['id'],
            'job_id'       => (int)$r['job_id'],
            'job'          => (string)$r['job_name'],
            'person'       => (string)$r['person'],
            'role'         => (string)$r['role'],
            'fee'          => $fee,
            'vat_mode'     => $mode,
            'is_confirmed' => (int)$r['is_confirmed'] === 1,
            'contact'      => (string)$r['contact'],
            'notes'        => (string)$r['notes'],
            'position'     => (int)$r['position'],
        ] + data_vat_split($fee, $mode);
    }
    $crewByJob = [];
    foreach ($crew as $i => $c) $crewByJob[$c['job_id']][] = $i;

    // Trabalhos
    $r23 = DATA_VAT / 100;
    $jobs = [];
    foreach ($pdo->query('SELECT * FROM jobs') as $r) {
        $id     = (int)$r['id'];
        $net    = (float)$r['budget_net'];
        $hasVat = (int)$r['has_vat'] === 1;
        $manual = $r['expenses_manual'] === null ? null : (float)$r['expenses_manual'];
        $cNet = 0.0; $cVat = 0.0; $cConf = 0.0; $cCount = 0;
        foreach ($crewByJob[$id] ?? [] as $i) {
            $cNet += $crew[$i]['net'];
            $cVat += $crew[$i]['vat'];
            if ($crew[$i]['is_confirmed']) $cConf += $crew[$i]['net'];
            $cCount++;
        }
        $expenses = $manual !== null ? $manual : round($cNet, 2);
        $gross    = $hasVat ? round($net * (1 + $r23), 2) : $net;
        $jobs[] = [
            'id'               => $id,
            'kind'             => 'job',
            'name'             => (string)$r['name'],
            'year'             => (int)$r['year'],
            'start_month'      => $r['month'] === null ? null : (int)$r['month'],
            'type_label'       => (string)$r['type_label'],
            'budget_net'       => $net,
            'budget_gross'     => $gross,
            'is_international' => (int)$r['is_international'] === 1,
            'has_vat'          => $hasVat ? 1 : 0,
            'expenses_mode'    => $manual !== null ? 'manual' : 'crew',
            'expenses_manual'  => $manual ?? 0.0,
            'is_paid'          => (int)$r['is_paid'] === 1,
            'notes'            => (string)$r['notes'],
            'position'         => (int)$r['position'],
            'expenses'         => $expenses,
            'profit'           => round($net - $expenses, 2),
            'vat_out'          => round($gross - $net, 2),
            'vat_in'           => $manual !== null
                                    ? ($hasVat ? round($manual * $r23, 2) : 0.0)
                                    : round($cVat, 2),
            'crew_net'         => round($cNet, 2),
            'crew_vat'         => round($cVat, 2),
            'crew_confirmed'   => round($cConf, 2),
            'crew_count'       => $cCount,
        ];
    }
    usort($jobs, 'data_cmp_dated');

    // Custos da empresa
    $company = [];
    foreach ($pdo->query('SELECT * FROM company_costs') as $r) {
        $mode = data_vat_mode((string)$r['vat_mode']);
        $amount = (float)$r['amount'];
        $company[] = [
            'id'          => (int)$r['id'],
            'year'        => (int)$r['year'],
            'start_month' => $r['month'] === null ? null : (int)$r['month'],
            'name'        => (string)$r['name'],
            'amount'      => $amount,
            'vat_mode'    => $mode,
            'is_paid'     => (int)$r['is_paid'] === 1,
            'notes'       => (string)$r['notes'],
            'position'    => (int)$r['position'],
        ] + data_vat_split($amount, $mode);
    }
    usort($company, 'data_cmp_dated');

    // Leads
    $leads = [];
    foreach ($pdo->query('SELECT * FROM leads ORDER BY position, id') as $r) {
        $stage = isset(LEAD_STAGES[(string)$r['stage']]) ? (string)$r['stage'] : 'novo';
        $leads[] = [
            'id'          => (int)$r['id'],
            'name'        => (string)$r['name'],
            'types'       => data_split((string)$r['types'], ','),
            'contacts'    => data_split((string)$r['contacts'], '|'),
            'phone'       => (string)$r['phone'],
            'stage'       => $stage,
            'stage_label' => LEAD_STAGES[$stage],
            'service'     => (string)$r['service'],
            'priority'    => (string)$r['priority'],
            'detail'      => (string)$r['detail'],
            'why'         => (string)$r['why'],
            'email'       => (string)$r['email'],
            'notes'       => (string)$r['notes'],
            'last_touch'  => (string)($r['last_touch'] ?? ''),
        ];
    }

    // Fornecedores
    $suppliers = [];
    foreach ($pdo->query('SELECT * FROM suppliers ORDER BY position, id') as $r) {
        $stars = max(0, min(5, (int)$r['rating']));
        $suppliers[] = [
            'id'       => (int)$r['id'],
            'name'     => (string)$r['name'],
            'category' => (string)$r['category'],
            'service'  => (string)$r['service'],
            'contact'  => (string)$r['contact'],
            'email'    => (string)$r['email'],
            'phone'    => (string)$r['phone'],
            'links'    => data_split((string)$r['links'], '|'),
            'nif'      => (string)$r['nif'],
            'rate'     => (string)$r['rate'],
            'rating'   => $stars > 0 ? str_repeat('★', $stars) : '',
            'last_job' => (string)$r['last_job'],
            'notes'    => (string)$r['notes'],
        ];
    }

    // Anos com registos, mais o corrente
    $years = [(int)date('Y')];
    foreach ($jobs as $j)    if ($j['year'] > 0) $years[] = $j['year'];
    foreach ($company as $c) if ($c['year'] > 0) $years[] = $c['year'];
    $years = array_values(array_unique($years));
    rsort($years);

    return $GLOBALS['__data_all'] = [
        'jobs'      => $jobs,
        'crew'      => $crew,
        'company'   => $company,
        'leads'     => $leads,
        'suppliers' => $suppliers,
        'years'     => $years,
        'fetched'   => time(),
    ];
}

/** Depois de gravar, a próxima leitura volta à base. */
function data_forget(): void {
    unset($GLOBALS['__data_all']);
}

/** Ano mais recente primeiro, depois o mês (sem mês no fim), depois a ordem de entrada. */
function data_cmp_dated(array $a, array $b): int {
    return [$b['year'], $a['start_month'] ?? 13, $a['position'], $a['id']]
       <=> [$a['year'], $b['start_month'] ?? 13, $b['position'], $b['id']];
}

/** «a, b» → ['a','b'] sem vazios. */
function data_split(string $s, string $sep): array {
    $out = [];
    foreach (explode($sep, $s) as $p) {
        $p = trim($p);
        if ($p !== '') $out[] = $p;
    }
    return $out;
}

function data_find(array $list, int $id): ?array {
    foreach ($list as $x) if ($x['id'] === $id) return $x;
    return null;
}
function data_job(array $d, int $id): ?array      { return data_find($d['jobs'], $id); }
function data_crew(array $d, int $id): ?array     { return data_find($d['crew'], $id); }
function data_cost(array $d, int $id): ?array     { return data_find($d['company'], $id); }
function data_lead(array $d, int $id): ?array     { return data_find($d['leads'], $id); }
function data_supplier(array $d, int $id): ?array { return data_find($d['suppliers'], $id); }

function data_job_by_name(array $d, string $name): ?array {
    foreach ($d['jobs'] as $j) if ($j['name'] === trim($name)) return $j;
    return null;
}

/** A equipa de um trabalho, pela ordem de entrada. */
function data_job_crew(array $d, int $jobId): array {
    return array_values(array_filter($d['crew'], static fn($c) => $c['job_id'] === $jobId));
}

/** Os números grandes de um ano. */
function data_year_totals(array $d, int $year): array {
    $revenue = 0.0; $costs = 0.0; $unpaid = 0.0; $vatOut = 0.0; $vatIn = 0.0; $count = 0;
    foreach ($d['jobs'] as $j) {
        if ($j['year'] !== $year) continue;
        $count++;
        $revenue += $j['budget_net'];
        $costs   += $j['expenses'];
        $vatOut  += $j['vat_out'];
        $vatIn   += $j['vat_in'];
        if (!$j['is_paid']) $unpaid += $j['budget_net'];
    }
    foreach ($d['company'] as $c) {
        if ($c['year'] !== $year) continue;
        $costs += $c['net'];
        $vatIn += $c['vat'];
    }
    return [
        'revenue' => round($revenue, 2),
        'costs'   => round($costs, 2),
        'profit'  => round($revenue - $costs, 2),
        'unpaid'  => round($unpaid, 2),
        'vat_out' => round($vatOut, 2),
        'vat_in'  => round($vatIn, 2),
        'count'   => $count,
    ];
}

// ── escrita ─────────────────────────────────────────────────

/** As colunas que cada tabela aceita, com o tipo de cada uma. */
const DATA_FIELDS = [
    'jobs'          => ['name' => 's', 'year' => 'i', 'month' => 'm', 'type_label' => 's', 'budget_net' => 'f',
                        'is_international' => 'b', 'has_vat' => 'b', 'expenses_manual' => 'fn', 'is_paid' => 'b', 'notes' => 's'],
    'job_crew'      => ['job_id' => 'i', 'person' => 's', 'role' => 's', 'fee' => 'f', 'vat_mode' => 'v',
                        'is_confirmed' => 'b', 'contact' => 's', 'notes' => 's'],
    'company_costs' => ['year' => 'i', 'month' => 'm', 'name' => 's', 'amount' => 'f', 'vat_mode' => 'v',
                        'is_paid' => 'b', 'notes' => 's'],
    'leads'         => ['name' => 's', 'types' => 'l,', 'contacts' => 'l|', 'phone' => 's', 'stage' => 'st',
                        'service' => 's', 'priority' => 's', 'detail' => 's', 'why' => 's', 'email' => 's',
                        'notes' => 's', 'last_touch' => 'd'],
    'suppliers'     => ['name' => 's', 'category' => 's', 'service' => 's', 'contact' => 's', 'email' => 's',
                        'phone' => 's', 'links' => 'l|', 'nif' => 's', 'rate' => 's', 'rating' => 'r',
                        'last_job' => 's', 'notes' => 's'],
];

/** O máximo de caracteres das colunas VARCHAR (o resto é TEXT). Acima disto o texto é cortado. */
const DATA_MAX = [
    'jobs'          => ['name' => 255, 'type_label' => 120],
    'job_crew'      => ['person' => 255, 'role' => 255],
    'company_costs' => ['name' => 255],
    'leads'         => ['name' => 255, 'phone' => 120, 'service' => 120, 'priority' => 40, 'email' => 255],
    'suppliers'     => ['name' => 255, 'category' => 120, 'contact' => 255, 'email' => 255, 'phone' => 120,
                        'nif' => 60, 'last_job' => 255],
];

/** Converte um valor para a coluna (o que não se percebe fica vazio/zero). */
function data_cast(string $type, $v) {
    switch ($type) {
        case 's':  return trim(str_replace("\0", '', (string)$v));
        case 'i':  return (int)$v;
        case 'b':  return $v ? 1 : 0;
        case 'f':  return round(is_string($v) ? money_in($v) : (float)$v, 2);
        case 'fn': return $v === null || $v === '' ? null : round(is_string($v) ? money_in($v) : (float)$v, 2);
        case 'm':  $m = (int)$v; return $m >= 1 && $m <= 12 ? $m : null;
        case 'v':  return data_vat_mode((string)$v);
        case 'st': return isset(LEAD_STAGES[(string)$v]) ? (string)$v : 'novo';
        case 'd':
            $s = trim((string)$v);
            return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : null;
        case 'r':
            // ★★★★, ⭐⭐⭐⭐, «4», «4/5», «4 estrelas»
            $s = trim((string)$v);
            $stars = mb_substr_count($s, '★') + mb_substr_count($s, '⭐');
            if ($stars > 0) return min(5, $stars);
            return preg_match('/^(\d+)/', $s, $m) ? max(0, min(5, (int)$m[1])) : 0;
        case 'l,': case 'l|':
            $sep = substr($type, 1);
            $parts = is_array($v) ? $v : explode($sep, (string)$v);
            return implode($sep === ',' ? ', ' : ' | ', data_split(implode($sep, array_map('strval', $parts)), $sep));
    }
    throw new InvalidArgumentException("tipo $type");
}

/** As colunas de $f que a tabela aceita, já convertidas. */
function data_values(string $table, array $f): array {
    $out = [];
    foreach (DATA_FIELDS[$table] as $col => $type) {
        if (!array_key_exists($col, $f)) continue;
        $out[$col] = data_cast($type, $f[$col]);
        $max = DATA_MAX[$table][$col] ?? 0;
        if ($max && is_string($out[$col]) && mb_strlen($out[$col]) > $max) $out[$col] = rtrim(mb_substr($out[$col], 0, $max));
    }
    return $out;
}

/** As colunas de $f que passam do máximo e vão ser cortadas: [coluna => comprimento]. */
function data_too_long(string $table, array $f): array {
    $out = [];
    foreach (DATA_MAX[$table] ?? [] as $col => $max) {
        if (!array_key_exists($col, $f)) continue;
        $v = data_cast(DATA_FIELDS[$table][$col], $f[$col]);
        if (is_string($v) && mb_strlen($v) > $max) $out[$col] = mb_strlen($v);
    }
    return $out;
}

/**
 * A chave com que a base compara nomes de trabalho (utf8mb4_unicode_ci): sem maiúsculas,
 * sem acentos, espaços seguidos contam como um. «Café Nike» = «cafe  NIKE».
 */
function data_name_key(string $name): string {
    $s = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($s, Normalizer::FORM_D);
        if (is_string($n)) $s = preg_replace('/\p{Mn}+/u', '', $n) ?? $s;
    }
    return mb_strtolower($s, 'UTF-8');
}

function data_now(): string {
    return gmdate('Y-m-d H:i:s');
}

/** INSERT com a posição a seguir à última. Devolve o id. */
function data_insert(string $table, array $f): int {
    global $pdo;
    $v = data_values($table, $f);
    $v['position'] = isset($f['position']) ? (int)$f['position']
                   : (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 1 FROM ' . $table)->fetchColumn();
    $v['created_at'] = $v['updated_at'] = data_now();
    $cols = array_keys($v);
    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($v));
    data_forget();
    return (int)$pdo->lastInsertId();
}

/** UPDATE das colunas dadas. Lança DataException se o registo já não existir. */
function data_update(string $table, int $id, array $f, string $gone): void {
    global $pdo;
    $v = data_values($table, $f);
    if (!data_exists($table, $id)) throw new DataException($gone);
    if (!$v) return;
    $v['updated_at'] = data_now();
    $set = implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($v)));
    $pdo->prepare('UPDATE ' . $table . ' SET ' . $set . ' WHERE id = ?')->execute(array_merge(array_values($v), [$id]));
    data_forget();
}

function data_exists(string $table, int $id): bool {
    global $pdo;
    $st = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE id = ?');
    $st->execute([$id]);
    return (int)$st->fetchColumn() > 0;
}

function data_delete(string $table, int $id): void {
    global $pdo;
    $pdo->prepare('DELETE FROM ' . $table . ' WHERE id = ?')->execute([$id]);
    data_forget();
}

/** Outro trabalho com este nome? (o nome identifica o trabalho nas tarefas e no Drive) */
function data_job_name_taken(string $name, int $exceptId = 0): bool {
    global $pdo;
    $st = $pdo->prepare('SELECT id FROM jobs WHERE name = ?');
    $st->execute([trim($name)]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) if ((int)$id !== $exceptId) return true;
    return false;
}

function data_job_insert(array $f): int {
    $name = trim((string)($f['name'] ?? '')) ?: 'Sem nome';
    if (data_job_name_taken($name)) throw new DataException('Já existe um trabalho chamado «' . $name . '». Escolha outro nome.');
    return data_insert('jobs', ['name' => $name] + $f);
}

function data_job_update(int $id, array $f): void {
    if (array_key_exists('name', $f)) {
        $f['name'] = trim((string)$f['name']) ?: 'Sem nome';
        if (data_job_name_taken($f['name'], $id)) throw new DataException('Já existe um trabalho chamado «' . $f['name'] . '». Escolha outro nome.');
    }
    data_update('jobs', $id, $f, 'Este trabalho já não existe (foi apagado entretanto).');
}

/** Apaga o trabalho e a equipa dele; os eventos do calendário ficam, sem trabalho. */
function data_job_delete(int $id): void {
    global $pdo;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM job_crew WHERE job_id = ?')->execute([$id]);
        if (db_has_column($pdo, 'events', 'job_id')) $pdo->prepare('UPDATE events SET job_id = NULL WHERE job_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM jobs WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    data_forget();
}

function data_crew_insert(int $jobId, array $f): int {
    if (!data_exists('jobs', $jobId)) throw new DataException('Este trabalho já não existe (foi apagado entretanto).');
    return data_insert('job_crew', ['job_id' => $jobId] + $f);
}
/** Com «job_id», o custo passa para outro trabalho. */
function data_crew_update(int $id, array $f): void {
    if (isset($f['job_id']) && !data_exists('jobs', (int)$f['job_id'])) {
        throw new DataException('Esse trabalho já não existe (foi apagado entretanto).');
    }
    data_update('job_crew', $id, $f, 'Este custo já não existe (foi apagado entretanto).');
}
function data_crew_delete(int $id): void { data_delete('job_crew', $id); }

function data_cost_insert(array $f): int { return data_insert('company_costs', $f); }
function data_cost_update(int $id, array $f): void {
    data_update('company_costs', $id, $f, 'Este custo já não existe (foi apagado entretanto).');
}
function data_cost_delete(int $id): void { data_delete('company_costs', $id); }

function data_lead_insert(array $f): int { return data_insert('leads', $f); }
function data_lead_update(int $id, array $f): void {
    data_update('leads', $id, $f, 'Esta lead já não existe (foi apagada entretanto).');
}
function data_lead_delete(int $id): void { data_delete('leads', $id); }

function data_supplier_insert(array $f): int { return data_insert('suppliers', $f); }
function data_supplier_update(int $id, array $f): void {
    data_update('suppliers', $id, $f, 'Este fornecedor já não existe (foi apagado entretanto).');
}
function data_supplier_delete(int $id): void { data_delete('suppliers', $id); }

/**
 * Corre uma escrita e deixa o resultado num aviso para a página seguinte.
 * Devolve true quando gravou.
 */
function data_try(callable $fn, string $ok = ''): bool {
    try {
        $fn();
        if ($ok !== '') flash($ok);
        return true;
    } catch (DataException $e) {
        flash($e->getMessage(), 'err');
        return false;
    } catch (PDOException $e) {
        // a base recusou (nome repetido, valor fora do formato…): avisa em vez de rebentar a página
        error_log('data_try: ' . $e->getMessage());
        flash($e->getCode() === '23000'
            ? 'Não foi possível guardar: já existe um registo igual.'
            : 'Não foi possível guardar (a base de dados recusou os valores). Verifique o que escreveu e tente de novo.', 'err');
        return false;
    }
}

// ── pedaços de página ───────────────────────────────────────

/** Hora local (Lisboa) de um instante. */
function data_clock(int $ts, string $fmt = 'H:i:s'): string {
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format($fmt);
}

function data_today(): string {
    return data_clock(time(), 'Y-m-d');
}

/** Opções de um <select>, com o valor atual mantido mesmo que não esteja na lista. */
function data_options(array $list, string $current, string $empty = ''): string {
    $html = $empty !== '' ? '<option value="">' . e($empty) . '</option>' : '';
    if ($current !== '' && !in_array($current, $list, true)) {
        $html .= '<option value="' . e($current) . '" selected>' . e($current) . '</option>';
    }
    foreach ($list as $v) {
        $html .= '<option value="' . e($v) . '"' . ($v === $current ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $html;
}

/** A linha por cima das páginas de dados: o botão para levar tudo para Excel. */
function data_export_line(): string {
    return '<div class="sheetline"><a href="/admin/export.php">Exportar para Excel</a></div>';
}
