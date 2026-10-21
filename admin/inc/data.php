<?php
/**
 * topper. admin — os dados da empresa, no formato da versão 10
 *
 * Desde a plataforma (versão 11) os dados vivem em deals, costs e contacts.
 * Este ficheiro dá-os no formato antigo — trabalhos, equipa, custos da
 * empresa, leads e fornecedores — a quem ainda o usa: o assistente do Drive
 * (ai_api.php, ferramenta company_data).
 *
 * data_all() respeita as permissões de quem pergunta: sem a aba Financeiro
 * não há trabalhos, equipa nem custos; sem Leads não há leads (e só as do
 * seu âmbito); sem Contactos não há fornecedores. O assistente nunca vê mais
 * do que a pessoa veria nas páginas.
 *
 * As contas são as de inc/finance.php (as mesmas fórmulas da folha).
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/finance.php';

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

/**
 * Um valor tal como está na fatura → [net, vat, total].
 *   none  sem IVA            net = valor
 *   plus  acresce IVA        IVA = valor × 23 %
 *   incl  IVA já incluído    net = valor ÷ 1,23
 */
function data_vat_split(float $v, string $mode): array {
    $c = ['amount' => $v, 'vat_mode' => $mode];
    $net = cost_net($c, DATA_VAT);
    $vat = cost_vat($c, DATA_VAT);
    return ['net' => $net, 'vat' => $vat, 'total' => round($net + $vat, 2)];
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

/**
 * Tudo, numa só leitura por pedido, já filtrado pelas permissões:
 *   jobs, crew, company, leads, suppliers, years, fetched
 */
function data_all(bool $fresh = false): array {
    global $pdo;
    if (!$fresh && !empty($GLOBALS['__data_all'])) return $GLOBALS['__data_all'];
    $rate = DATA_VAT;
    $money = current_user() !== null && can('financeiro', PERM_VIEW) && can('negocios', PERM_VIEW);

    $jobs = []; $crew = []; $company = [];
    if ($money) {
        [$sql, $args] = scope_sql($pdo, 'negocios', 'd.owner_id');
        $u = current_user();
        if (role_is_admin((string)$u['role']) || $u['role'] === 'lead') $sql = "($sql OR d.owner_id IS NULL)";
        $st = $pdo->prepare("SELECT d.* FROM deals d WHERE d.code IS NOT NULL AND d.phase IN ('production','done')
                               AND $sql ORDER BY d.year DESC, COALESCE(d.month, 13), d.code");
        $st->execute($args);
        foreach ($st->fetchAll() as $d) {
            $costs = deal_costs($pdo, (int)$d['id'], $rate);
            $m = deal_money($d, $costs, $rate);
            $jobs[] = [
                'id' => (int)$d['id'], 'kind' => 'job', 'code' => $d['code'], 'name' => $d['code'] . ' · ' . $d['name'],
                'year' => (int)$d['year'], 'start_month' => $d['month'] === null ? null : (int)$d['month'],
                'type_label' => (string)$d['job_type'], 'budget_net' => $m['budget_net'], 'budget_gross' => $m['budget_gross'],
                'is_international' => (int)$d['is_international'] === 1, 'has_vat' => (int)$d['has_vat'],
                'expenses_mode' => $d['expenses_mode'], 'expenses_manual' => (float)$d['expenses_manual'],
                'is_paid' => (int)$d['is_paid'] === 1, 'notes' => (string)$d['notes'], 'position' => 0,
                'expenses' => $m['expenses'], 'profit' => $m['profit'], 'vat_out' => $m['vat_out'], 'vat_in' => $m['vat_in'],
                'crew_net' => $m['costs_net'], 'crew_vat' => $m['costs_vat'], 'crew_confirmed' => $m['costs_closed'],
                'crew_count' => $m['costs_count'],
            ];
            foreach ($costs as $c) {
                $crew[] = [
                    'id' => (int)$c['id'], 'job_id' => (int)$d['id'], 'job' => $d['code'] . ' · ' . $d['name'],
                    'person' => (string)$c['name'], 'role' => (string)$c['role'], 'fee' => (float)$c['amount'],
                    'vat_mode' => $c['vat_mode'], 'is_confirmed' => (int)$c['is_closed'] === 1,
                    'contact' => (string)($c['contact_name'] ?? ''), 'notes' => (string)$c['notes'], 'position' => 0,
                    'net' => $c['net'], 'vat' => $c['vat'], 'total' => $c['total'],
                ];
            }
        }
        foreach (company_cost_rows($pdo, $rate) as $c) {
            $company[] = [
                'id' => (int)$c['id'], 'year' => (int)$c['year'], 'start_month' => $c['month'] === null ? null : (int)$c['month'],
                'name' => (string)$c['name'], 'amount' => (float)$c['amount'], 'vat_mode' => $c['vat_mode'],
                'is_paid' => (int)$c['is_closed'] === 1, 'notes' => (string)$c['notes'], 'position' => 0,
                'net' => $c['net'], 'vat' => $c['vat'], 'total' => $c['total'],
            ];
        }
    }

    $leads = [];
    if (current_user() !== null && can('leads', PERM_VIEW)) {
        $u = current_user();
        [$sql, $args] = scope_sql($pdo, 'leads', 'owner_id');
        $unassigned = role_is_admin((string)$u['role']) || $u['role'] === 'lead';
        $st = $pdo->prepare("SELECT * FROM deals WHERE phase = 'lead' AND is_quarantined = 0 AND ($sql"
                          . ($unassigned ? ' OR owner_id IS NULL' : '') . ') ORDER BY created_at DESC, id DESC');
        $st->execute($args);
        $labels = ['novo' => 'Novo', 'sem_resposta' => 'Sem resposta', 'follow_up' => 'Follow-Up'];
        $prio = ['alta' => 'Alta', 'media' => 'Média', 'baixa' => 'Baixa'];
        foreach ($st->fetchAll() as $l) {
            $leads[] = [
                'id' => (int)$l['id'], 'name' => (string)$l['name'], 'types' => data_split((string)$l['types'], ','),
                'contacts' => data_split((string)$l['links'], '|'), 'phone' => (string)$l['phone'],
                'stage' => (string)$l['lead_state'], 'stage_label' => $labels[$l['lead_state']] ?? (string)$l['lead_state'],
                'service' => (string)$l['service'], 'priority' => $prio[$l['priority']] ?? '', 'detail' => (string)$l['detail'],
                'why' => (string)$l['why'], 'email' => (string)$l['email'], 'notes' => (string)$l['notes'],
                'last_touch' => (string)($l['last_touch'] ?? ''),
            ];
        }
    }

    $suppliers = [];
    if (current_user() !== null && can('contactos', PERM_VIEW)) {
        foreach ($pdo->query('SELECT * FROM contacts WHERE is_supplier = 1 ORDER BY name') as $s) {
            $stars = max(0, min(5, (int)$s['rating']));
            $suppliers[] = [
                'id' => (int)$s['id'], 'name' => (string)$s['name'], 'category' => (string)$s['category'],
                'service' => (string)$s['service'], 'contact' => (string)$s['contact_person'], 'email' => (string)$s['email'],
                'phone' => (string)$s['phone'], 'links' => data_split((string)$s['website'], '|'), 'nif' => (string)$s['nif'],
                'rate' => (string)$s['rate'], 'rating' => $stars > 0 ? str_repeat('★', $stars) : '',
                'last_job' => (string)$s['last_job'], 'notes' => (string)$s['notes'],
            ];
        }
    }

    $years = [(int)date('Y')];
    foreach ($jobs as $j)    if ($j['year'] > 0) $years[] = $j['year'];
    foreach ($company as $c) if ($c['year'] > 0) $years[] = $c['year'];
    $years = array_values(array_unique($years));
    rsort($years);

    return $GLOBALS['__data_all'] = [
        'jobs' => $jobs, 'crew' => $crew, 'company' => $company, 'leads' => $leads,
        'suppliers' => $suppliers, 'years' => $years, 'fetched' => time(),
    ];
}

/** Depois de gravar, a próxima leitura volta à base. */
function data_forget(): void {
    unset($GLOBALS['__data_all']);
}
