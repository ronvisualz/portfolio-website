<?php
/**
 * topper. admin — passagem única dos dados da versão 10 para a plataforma
 *
 * A versão 10 trouxe a folha Google para cinco tabelas (jobs, job_crew,
 * company_costs, leads, suppliers). A plataforma (versão 11) guarda tudo em
 * deals (lead → oportunidade → negócio, com ID), costs e contacts. Aqui
 * passa-se de uma para a outra, com as regras da especificação (§5 e §9):
 *
 *   Trabalhos                 → negócios Concluídos, ID por ano, mês e ordem,
 *                               no funil escolhido para o tipo de trabalho
 *   Equipa                    → custos do negócio, ligados pelo ID
 *   Custos empresa            → custos gerais (Financeiro)
 *   Leads Novo/Contactado/…   → leads com o mesmo estado
 *   Leads Proposta enviada    → negócios no X-Ray, com ID
 *   Leads Ganho               → negócios em produção (Kickoff), com ID
 *                               (a não ser que já sejam um dos Trabalhos)
 *   Leads Perdido             → leads arquivadas; as que tiveram orçamento
 *                               → negócios perdidos no X-Ray, com ID
 *   Fornecedores              → contactos do tipo Fornecedor
 *   Eventos e tarefas         → ligados ao negócio pelo ID
 *   Pastas do Drive           → as pastas antigas ficam ligadas ao ID
 *
 * platform_plan()    lê e prepara, sem escrever; lista o que não se
 *                    resolve sozinho (mês ou ano em falta, funil por escolher…).
 * platform_migrate() escreve tudo numa transação e, antes de a fechar,
 *                    refaz as contas com as tabelas novas e compara-as com as
 *                    da versão 10, trabalho a trabalho e ano a ano. Uma
 *                    diferença de um cêntimo desfaz tudo. As tabelas da
 *                    versão 10 ficam como estão (nada se apaga).
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/leads.php';

const PI_TABLES = ['jobs', 'job_crew', 'company_costs', 'leads', 'suppliers'];

/** Funil sugerido para cada tipo de trabalho (pelo nome do funil). */
const PI_DEFAULT_TYPE_FUNNEL = [
    'VFX'               => 'VFX / 3D',
    'Edição'            => 'VFX / 3D',
    'Animação 3D'       => 'CGI',
    'Produção Completa' => 'Campanhas',
    'Fotografia'        => 'Campanhas',
];
/** Funil sugerido para o serviço de uma lead que passa a negócio. */
const PI_DEFAULT_SERVICE_FUNNEL = [
    'VFX / 3D'        => 'VFX / 3D',
    'Marketing Buddy' => 'Marketing Buddy',
    'Ambos'           => 'VFX / 3D',
    ''                => 'VFX / 3D',
];

/** Já foi feita? Devolve a data ou null (lido sempre da base). */
function platform_migrated(PDO $pdo): ?string {
    try {
        $v = $pdo->query("SELECT svalue FROM settings WHERE skey = 'platform_migrated_at'")->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
    return $v ?: null;
}

/** Há dados da versão 10 para passar? */
function platform_has_v10_data(PDO $pdo): bool {
    foreach (PI_TABLES as $t) {
        if (db_has_table($pdo, $t) && (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn() > 0) return true;
    }
    return false;
}

/** Os valores por omissão das escolhas, a partir dos nomes dos funis que existem. */
function platform_default_options(PDO $pdo): array {
    $byName = [];
    foreach ($pdo->query('SELECT id, name FROM funnels WHERE is_active = 1') as $f) $byName[$f['name']] = (int)$f['id'];
    $type = []; $svc = [];
    foreach (PI_DEFAULT_TYPE_FUNNEL as $t => $n) if (isset($byName[$n])) $type[$t] = $byName[$n];
    foreach (PI_DEFAULT_SERVICE_FUNNEL as $s => $n) if (isset($byName[$n])) $svc[$s] = $byName[$n];
    $owner = $pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND LOWER(email) = ?");
    $owner->execute([SUPERADMIN_EMAIL]);
    $ownerId = $owner->fetchColumn();
    if ($ownerId === false) {
        $ownerId = $pdo->query("SELECT id FROM users WHERE is_active = 1 AND role IN ('superadmin','admin') ORDER BY id LIMIT 1")->fetchColumn();
    }
    return [
        'funnel_by_type'    => $type,
        'funnel_by_service' => $svc,
        'lost_with_budget'  => [],
        'owner_id'          => $ownerId === false ? 0 : (int)$ownerId,
    ];
}

/** As contas de um trabalho tal como a versão 10 as fazia (inc/data.php de então). */
function platform_v10_money(array $job, array $crew): array {
    $r = 23.0;
    $net = round((float)$job['budget_net'], 2);
    $hasVat = (int)$job['has_vat'] === 1;
    $manual = $job['expenses_manual'] === null ? null : (float)$job['expenses_manual'];
    $cNet = 0.0; $cVat = 0.0;
    foreach ($crew as $c) {
        $cNet += cost_net(['amount' => (float)$c['fee'], 'vat_mode' => $c['vat_mode']], $r);
        $cVat += cost_vat(['amount' => (float)$c['fee'], 'vat_mode' => $c['vat_mode']], $r);
    }
    $expenses = $manual !== null ? $manual : round($cNet, 2);
    $gross = $hasVat ? round($net * (1 + $r / 100), 2) : $net;
    return [
        'budget_gross' => $gross, 'expenses' => round($expenses, 2), 'profit' => round($net - $expenses, 2),
        'vat_out' => round($gross - $net, 2),
        'vat_in'  => $manual !== null ? ($hasVat ? round($manual * $r / 100, 2) : 0.0) : round($cVat, 2),
    ];
}

/** Lê as tabelas da versão 10 e monta o plano. Não escreve nada. */
function platform_plan(PDO $pdo, array $opt): array {
    $issues = [];
    $jobs = db_has_table($pdo, 'jobs') ? $pdo->query('SELECT * FROM jobs ORDER BY year, COALESCE(month, 13), position, id')->fetchAll() : [];
    $crew = db_has_table($pdo, 'job_crew') ? $pdo->query('SELECT * FROM job_crew ORDER BY job_id, position, id')->fetchAll() : [];
    $company = db_has_table($pdo, 'company_costs') && db_has_column($pdo, 'company_costs', 'position')
        ? $pdo->query('SELECT * FROM company_costs ORDER BY year, COALESCE(month, 13), position, id')->fetchAll() : [];
    $leads = db_has_table($pdo, 'leads') && db_has_column($pdo, 'leads', 'stage')
        ? $pdo->query('SELECT * FROM leads ORDER BY position, id')->fetchAll() : [];
    $suppliers = db_has_table($pdo, 'suppliers') ? $pdo->query('SELECT * FROM suppliers ORDER BY position, id')->fetchAll() : [];

    $crewByJob = [];
    foreach ($crew as $c) $crewByJob[(int)$c['job_id']][] = $c;

    // IDs pelos trabalhos: ano, mês e ordem; a sequência recomeça todos os meses.
    $seq = [];
    foreach ($jobs as &$j) {
        $j['crew'] = $crewByJob[(int)$j['id']] ?? [];
        $j['v10'] = platform_v10_money($j, $j['crew']);
        $j['funnel_id'] = (int)($opt['funnel_by_type'][(string)$j['type_label']] ?? 0);
        $j['code'] = null;
        $y = (int)$j['year']; $m = $j['month'] === null ? 0 : (int)$j['month'];
        if ($y < 2000 || $y > 2099) $issues[] = "Trabalho «{$j['name']}»: falta o ano (ou está errado) — é preciso para o ID.";
        if ($m < 1 || $m > 12)      $issues[] = "Trabalho «{$j['name']}»: falta o mês — é preciso para o ID.";
        if (!$j['funnel_id'])       $issues[] = "Trabalho «{$j['name']}»: escolha o funil para o tipo «" . ($j['type_label'] ?: 'sem tipo') . '».';
        if ($y >= 2000 && $y <= 2099 && $m >= 1 && $m <= 12) {
            $yymm = sprintf('%02d%02d', $y % 100, $m);
            $seq[$yymm] = ($seq[$yymm] ?? 0) + 1;
            $j['code'] = deal_code_format($yymm, $seq[$yymm]);
        }
    }
    unset($j);

    foreach ($company as $c) {
        if ((int)$c['year'] < 2000) $issues[] = "Custo da empresa «{$c['name']}»: falta o ano.";
    }

    $jobNames = array_map(fn($j) => mb_strtolower(trim($j['name'])), $jobs);
    $groups = ['lead' => [], 'xray' => [], 'won' => [], 'won_dup' => [], 'archived' => [], 'lost' => []];
    $lostBudget = array_map('intval', (array)($opt['lost_with_budget'] ?? []));
    foreach ($leads as $l) {
        switch ((string)$l['stage']) {
            case 'proposta': $groups['xray'][] = $l; break;
            case 'ganho':    in_array(mb_strtolower(trim($l['name'])), $jobNames, true) ? $groups['won_dup'][] = $l : $groups['won'][] = $l; break;
            case 'perdido':  in_array((int)$l['id'], $lostBudget, true) ? $groups['lost'][] = $l : $groups['archived'][] = $l; break;
            case 'contactado': case 'em_conversa': case 'novo': $groups['lead'][] = $l; break;
            default:         $l['stage'] = 'novo'; $groups['lead'][] = $l;
        }
    }
    foreach (array_merge($groups['xray'], $groups['won'], $groups['lost']) as $l) {
        if (!(int)($opt['funnel_by_service'][(string)$l['service']] ?? 0)) {
            $issues[] = "Lead «{$l['name']}»: escolha o funil para o serviço «" . ($l['service'] ?: 'sem serviço') . '».';
        }
    }
    if (!(int)($opt['owner_id'] ?? 0)) $issues[] = 'Escolha o responsável das leads e negócios migrados.';

    return [
        'jobs' => $jobs, 'company' => $company, 'leads' => $groups, 'suppliers' => $suppliers,
        'issues' => array_values(array_unique($issues)),
        'counts' => ['jobs' => count($jobs), 'crew' => count($crew), 'company' => count($company),
                     'leads' => count($leads), 'suppliers' => count($suppliers)],
    ];
}

/** Os totais de um ano na versão 10 (para comparar no fim). */
function platform_v10_year(array $plan, int $year): array {
    $t = ['revenue' => 0.0, 'costs' => 0.0, 'vat_out' => 0.0, 'vat_in' => 0.0];
    foreach ($plan['jobs'] as $j) {
        if ((int)$j['year'] !== $year) continue;
        $t['revenue'] += (float)$j['budget_net'];
        $t['costs']   += $j['v10']['expenses'];
        $t['vat_out'] += $j['v10']['vat_out'];
        $t['vat_in']  += $j['v10']['vat_in'];
    }
    foreach ($plan['company'] as $c) {
        if ((int)$c['year'] !== $year) continue;
        $t['costs'] += cost_net(['amount' => (float)$c['amount'], 'vat_mode' => $c['vat_mode']], 23.0);
        $t['vat_in'] += cost_vat(['amount' => (float)$c['amount'], 'vat_mode' => $c['vat_mode']], 23.0);
    }
    return array_map(fn($v) => round($v, 2), $t);
}

/**
 * Executa o plano. Tudo numa transação; antes de a fechar, confere as contas.
 * $opt: funnel_by_type, funnel_by_service, lost_with_budget, owner_id, drive_folders [job id => prefixo].
 * Devolve o resumo; lança RuntimeException (e desfaz tudo) se algo não bater.
 */
function platform_migrate(PDO $pdo, array $opt): array {
    if (platform_migrated($pdo)) throw new RuntimeException('A migração já foi feita.');
    $plan = platform_plan($pdo, $opt);
    if ($plan['issues']) throw new RuntimeException('O plano tem pontos por resolver: ' . implode(' ', $plan['issues']));
    $owner = (int)$opt['owner_id'];
    $rate  = 23.0;
    $now   = new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon'));
    $sum = ['deals' => 0, 'costs' => 0, 'company' => 0, 'leads' => 0, 'archived' => 0, 'xray' => 0, 'won' => 0, 'lost' => 0,
            'contacts' => 0, 'events' => 0, 'tasks' => 0, 'folders' => 0, 'checked' => 0];

    $pdo->beginTransaction();
    try {
        // Restos de uma tentativa anterior que falhou a meio não podem existir: a base tem de estar vazia.
        if ((int)$pdo->query('SELECT COUNT(*) FROM deals')->fetchColumn() > 0 || (int)$pdo->query('SELECT COUNT(*) FROM costs')->fetchColumn() > 0) {
            throw new RuntimeException('As tabelas novas já têm dados. A migração só corre numa plataforma vazia.');
        }

        $insDeal = $pdo->prepare(
            'INSERT INTO deals (code, phase, name, notes, origin, owner_id, created_by, funnel_id, origin_funnel_id, stage_id,
                                job_type, year, month, budget, is_international, has_vat, is_paid, expenses_mode, expenses_manual,
                                drive_folder, converted_at, done_at)
             VALUES (?, \'done\', ?, ?, \'manual\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insCost = $pdo->prepare(
            'INSERT INTO costs (deal_id, year, month, name, role, amount, vat_mode, is_closed, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $dealOfJob = []; $jobName = []; $maxSeq = [];
        foreach ($plan['jobs'] as $j) {
            $done = funnel_fixed_stage($pdo, $j['funnel_id'], 'done');
            $folder = (string)($opt['drive_folders'][(int)$j['id']] ?? '');
            $manual = $j['expenses_manual'] !== null;
            // Sem data de fecho na versão 10: conta o mês do próprio trabalho.
            $when = sprintf('%04d-%02d-01 00:00:00', (int)$j['year'], (int)$j['month']);
            $insDeal->execute([
                $j['code'], mb_substr(trim((string)$j['name']), 0, 160), (string)$j['notes'], $owner, $owner, $j['funnel_id'], $j['funnel_id'], $done,
                mb_substr((string)$j['type_label'], 0, 40), (int)$j['year'], (int)$j['month'], (float)$j['budget_net'], (int)$j['is_international'],
                (int)$j['has_vat'], (int)$j['is_paid'], $manual ? 'manual' : 'crew', $manual ? (float)$j['expenses_manual'] : 0,
                $folder, $when, $when,
            ]);
            $id = (int)$pdo->lastInsertId();
            $dealOfJob[(int)$j['id']] = $id;
            $jobName[mb_strtolower(trim($j['name']))] = $id;
            deal_log($pdo, $id, 'import', ['to_funnel' => $j['funnel_id'], 'to_stage' => $done, 'note' => 'Trabalhos (versão 10)', 'user_id' => $owner]);
            $sum['deals']++;
            if ($folder !== '') $sum['folders']++;
            foreach ($j['crew'] as $c) {
                $notes = trim(((string)$c['contact'] !== '' ? 'Contacto: ' . $c['contact'] . "\n" : '') . (string)$c['notes']);
                $insCost->execute([$id, (int)$j['year'], (int)$j['month'], mb_substr((string)$c['person'], 0, 160), mb_substr((string)$c['role'], 0, 120), (float)$c['fee'],
                                   isset(VAT_MODES[$c['vat_mode']]) ? $c['vat_mode'] : 'plus', (int)$c['is_confirmed'], $notes, $owner]);
                $sum['costs']++;
            }
            $yymm = substr($j['code'], 2, 4);
            $maxSeq[$yymm] = max($maxSeq[$yymm] ?? 0, (int)substr($j['code'], 7));
        }
        // A sequência dos IDs continua a seguir aos migrados.
        foreach ($maxSeq as $yymm => $n) {
            $cur = $pdo->prepare('SELECT last_seq FROM deal_codes WHERE yymm = ?');
            $cur->execute([$yymm]);
            $have = $cur->fetchColumn();
            if ($have === false) $pdo->prepare('INSERT INTO deal_codes (yymm, last_seq) VALUES (?, ?)')->execute([$yymm, $n]);
            elseif ((int)$have < $n) $pdo->prepare('UPDATE deal_codes SET last_seq = ? WHERE yymm = ?')->execute([$n, $yymm]);
        }

        foreach ($plan['company'] as $c) {
            $insCost->execute([null, (int)$c['year'], $c['month'] === null ? null : (int)$c['month'], mb_substr((string)$c['name'], 0, 160), '',
                               (float)$c['amount'], isset(VAT_MODES[$c['vat_mode']]) ? $c['vat_mode'] : 'plus', (int)$c['is_paid'],
                               (string)$c['notes'], $owner]);
            $sum['company']++;
        }

        // Leads
        $prio = ['alta' => 'alta', 'média' => 'media', 'media' => 'media', 'baixa' => 'baixa'];
        $insLead = function (array $l, string $phase, string $state) use ($pdo, $owner, $prio): int {
            $types = data_split_types((string)$l['types']);
            $notes = trim((string)$l['notes'] . ($types['other'] ? "\nTipo (antes): " . implode(', ', $types['other']) : ''));
            $touch = preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$l['last_touch']) ? substr((string)$l['last_touch'], 0, 10) : null;
            $pdo->prepare(
                'INSERT INTO deals (phase, lead_state, name, types, links, phone, email, service, priority, detail, why, notes,
                                    origin, owner_id, created_by, last_touch, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'manual\',?,?,?,?)'
            )->execute([
                $phase, $state, mb_substr(trim((string)$l['name']) ?: 'Sem nome', 0, 160), implode(', ', $types['known']),
                (string)$l['contacts'], mb_substr((string)$l['phone'], 0, 40), mb_substr((string)$l['email'], 0, 190),
                in_array($l['service'], LEAD_SERVICES_LIST, true) ? $l['service'] : '',   // VARCHAR(40); valores fora da lista ficam vazios
                $prio[mb_strtolower(trim((string)$l['priority']))] ?? 'media', (string)$l['detail'], (string)$l['why'],
                $notes, $owner, $owner, $touch, (string)($l['created_at'] ?: date('Y-m-d H:i:s')),
            ]);
            return (int)$pdo->lastInsertId();
        };
        foreach ($plan['leads']['lead'] as $l) { $insLead($l, 'lead', (string)$l['stage']); $sum['leads']++; }
        foreach ($plan['leads']['archived'] as $l) {
            $id = $insLead($l, 'archived', 'novo');
            $pdo->prepare("UPDATE deals SET archived_reason = 'perdido_migracao', archived_at = " . now_sql($pdo) . ' WHERE id = ?')->execute([$id]);
            $sum['archived']++;
        }
        foreach (['xray' => 'xray', 'won' => 'kickoff', 'lost' => 'xray'] as $group => $kind) {
            foreach ($plan['leads'][$group] as $l) {
                $id = $insLead($l, 'lead', 'novo');
                $funnel = (int)$opt['funnel_by_service'][(string)$l['service']];
                $stage = funnel_fixed_stage($pdo, $funnel, $kind);
                $pdo->prepare('INSERT INTO contacts (kind, name, is_client, email, phone, website, created_by) VALUES (\'company\', ?, 1, ?, ?, ?, ?)')
                    ->execute([mb_substr(trim((string)$l['name']) ?: 'Sem nome', 0, 160), mb_substr((string)$l['email'], 0, 190),
                               mb_substr((string)$l['phone'], 0, 40), mb_substr((string)$l['contacts'], 0, 255), $owner]);
                $cid = (int)$pdo->lastInsertId();
                $sum['contacts']++;
                $pdo->prepare('INSERT INTO deal_contacts (deal_id, contact_id) VALUES (?, ?)')->execute([$id, $cid]);
                deal_assign_code($pdo, $id, $now);
                $phase = $group === 'won' ? 'production' : ($group === 'lost' ? 'lost' : 'pipeline');
                $pdo->prepare('UPDATE deals SET phase = ?, funnel_id = ?, origin_funnel_id = ?, stage_id = ?, contact_id = ?, converted_at = ' . now_sql($pdo)
                            . ($group === 'won' ? ', year = ' . (int)$now->format('Y') . ', month = ' . (int)$now->format('n') : '')
                            . ($group === 'lost' ? ", lost_reason = 'Migração: perdido depois do orçamento', lost_stage_id = stage_id, lost_at = " . now_sql($pdo) : '')
                            . ' WHERE id = ?')
                    ->execute([$phase, $funnel, $funnel, $stage, $cid, $id]);
                if ($group === 'lost') $pdo->prepare('UPDATE deals SET lost_stage_id = stage_id WHERE id = ?')->execute([$id]);
                deal_log($pdo, $id, 'import', ['to_funnel' => $funnel, 'to_stage' => $stage, 'note' => 'Leads (versão 10): ' . (LEAD_STAGES_V10[$l['stage']] ?? $l['stage']), 'user_id' => $owner]);
                $sum[$group]++;
            }
        }

        // Fornecedores → contactos do tipo Fornecedor
        $insC = $pdo->prepare(
            'INSERT INTO contacts (kind, name, is_supplier, category, service, contact_person, email, phone, nif, website, rate, rating, last_job, notes, created_by)
             VALUES (\'company\', ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($plan['suppliers'] as $s) {
            $r = (int)$s['rating'];
            $insC->execute([mb_substr(trim((string)$s['name']) ?: 'Sem nome', 0, 160), mb_substr((string)$s['category'], 0, 40),
                            mb_substr((string)$s['service'], 0, 160), mb_substr((string)$s['contact'], 0, 160), mb_substr((string)$s['email'], 0, 190),
                            mb_substr((string)$s['phone'], 0, 40), mb_substr((string)$s['nif'], 0, 20), mb_substr((string)$s['links'], 0, 255),
                            mb_substr((string)$s['rate'], 0, 120), $r >= 1 && $r <= 5 ? $r : null, mb_substr((string)$s['last_job'], 0, 160),
                            (string)$s['notes'], $owner]);
            $sum['contacts']++;
        }

        // Eventos (events.job_id) e tarefas (pelo nome do trabalho) passam a apontar para o negócio.
        if (db_has_column($pdo, 'events', 'job_id')) {
            foreach ($pdo->query('SELECT id, job_id FROM events WHERE job_id IS NOT NULL')->fetchAll() as $ev) {
                if (isset($dealOfJob[(int)$ev['job_id']])) {
                    $pdo->prepare('UPDATE events SET deal_id = ? WHERE id = ?')->execute([$dealOfJob[(int)$ev['job_id']], $ev['id']]);
                    $sum['events']++;
                }
            }
        }
        foreach ($pdo->query("SELECT id, job_name FROM tasks WHERE job_name <> '' AND deal_id IS NULL")->fetchAll() as $t) {
            $id = $jobName[mb_strtolower(trim((string)$t['job_name']))] ?? null;
            if ($id) {
                $pdo->prepare('UPDATE tasks SET deal_id = ? WHERE id = ?')->execute([$id, $t['id']]);
                $sum['tasks']++;
            }
        }

        // ── conferir: as contas nas tabelas novas têm de ser as da versão 10, ao cêntimo ──
        $diff = [];
        foreach ($plan['jobs'] as $j) {
            $id = $dealOfJob[(int)$j['id']];
            $d = deal_get($pdo, $id);
            $m = deal_money($d, deal_costs($pdo, $id, $rate), $rate);
            foreach (['budget_gross', 'expenses', 'profit', 'vat_out', 'vat_in'] as $k) {
                if (abs($m[$k] - $j['v10'][$k]) >= 0.005) $diff[] = "«{$j['name']}» {$k}: versão 10 " . eur($j['v10'][$k]) . ', agora ' . eur($m[$k]);
            }
            $sum['checked']++;
        }
        $years = array_unique(array_merge(array_map(fn($j) => (int)$j['year'], $plan['jobs']), array_map(fn($c) => (int)$c['year'], $plan['company'])));
        foreach ($years as $y) {
            $old = platform_v10_year($plan, $y);
            $new = finance_year($pdo, $y, $rate);
            foreach (['revenue', 'costs', 'vat_out', 'vat_in'] as $k) {
                if (abs($new[$k] - $old[$k]) >= 0.005) $diff[] = "Ano $y {$k}: versão 10 " . eur($old[$k]) . ', agora ' . eur($new[$k]);
            }
        }
        if ($diff) throw new RuntimeException('As contas não bateram certo; nada foi gravado. ' . implode(' · ', $diff));

        $pdo->prepare(db_is_sqlite($pdo)
            ? "INSERT OR REPLACE INTO settings (skey, svalue) VALUES ('platform_migrated_at', ?)"
            : "INSERT INTO settings (skey, svalue) VALUES ('platform_migrated_at', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)"
        )->execute([$now->format('Y-m-d H:i')]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $sum;
}

const LEAD_STAGES_V10 = ['novo' => 'Novo', 'contactado' => 'Contactado', 'em_conversa' => 'Em conversa',
                         'proposta' => 'Proposta enviada', 'ganho' => 'Ganho', 'perdido' => 'Perdido'];

/** «Produtora, Outra coisa» → os tipos conhecidos e os outros (que vão para as notas). */
function data_split_types(string $s): array {
    $known = []; $other = [];
    foreach (explode(',', $s) as $t) {
        $t = trim($t);
        if ($t === '') continue;
        in_array($t, LEAD_TYPES_LIST, true) ? $known[] = $t : $other[] = $t;
    }
    return ['known' => array_values(array_unique($known)), 'other' => $other];
}

/**
 * As pastas antigas do Drive (Projetos/NN_NOME_PROJECT/ e os nomes associados
 * à mão) de cada trabalho: [id do trabalho => prefixo]. Só lê o R2.
 */
function platform_drive_folders(array $jobs): array {
    if (!function_exists('r2_config') || !r2_config()) return [];
    require_once __DIR__ . '/drive.php';
    $folders = [];
    try {
        foreach (r2_list('Projetos/')['folders'] ?? [] as $f) {
            $leaf = substr(rtrim($f, '/'), strlen('Projetos/'));
            $folders[drive_project_core($leaf)] = 'Projetos/' . $leaf . '/';
        }
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($jobs as $j) {
        $core = DRIVE_PROJECT_ALIASES[$j['name']] ?? drive_slug((string)$j['name']);
        if (isset($folders[$core])) $out[(int)$j['id']] = $folders[$core];
    }
    return $out;
}
