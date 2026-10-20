<?php
/**
 * topper. admin — migração única da folha Google para o MySQL
 *
 * Em dois tempos:
 *   1. ms_plan()    lê a folha e monta o plano: o que vai para onde, os IDs
 *                   que cada negócio vai ter, as contas recalculadas pelo
 *                   sistema comparadas linha a linha com as colunas
 *                   calculadas da folha, e a lista do que não se resolve
 *                   sozinho (nomes repetidos, meses vazios, números escritos
 *                   como texto…). Não escreve nada.
 *   2. ms_execute() só corre se o plano não tiver problemas. Escreve tudo
 *                   numa transação e marca a migração como feita.
 * Nunca adivinha: o que for ambíguo fica na lista para validar à mão.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/sheet.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/pipeline.php';

const MS_TOLERANCE = 0.005;

/** Uma célula numérica escrita como texto não-numérico ("1.200 €", "mil"). */
function ms_text_number($v): bool {
    if (is_int($v) || is_float($v) || $v === '' || $v === null || is_bool($v)) return false;
    return !preg_match('/^\s*-?\d+([.,]\d+)?\s*$/', (string)$v);
}

function ms_eq(float $a, float $b): bool {
    return abs(round($a, 2) - round($b, 2)) < MS_TOLERANCE;
}

function ms_stars($v): ?int {
    $n = mb_substr_count((string)$v, '★');
    return $n >= 1 && $n <= 5 ? $n : null;
}

/**
 * Monta o plano a partir de uma leitura da folha (sheet_read()).
 * $opt: funnel_by_type [tipo => funnel_id], funnel_by_service [serviço => funnel_id],
 *       lost_with_budget [row, …] (leads «Perdido» que chegaram a ter orçamento),
 *       today 'Y-m-d' (data da migração, para os IDs das leads convertidas).
 */
function ms_plan(array $read, array $opt): array {
    $rate   = SHEET_VAT;
    $issues = [];
    $checks = [];
    $d      = sheet_map($read);
    $tab    = fn(string $t) => sheet_tab($read, $t);

    // ── Trabalhos → negócios concluídos ─────────────────────
    $names = [];
    foreach ($d['jobs'] as $j) $names[mb_strtolower($j['name'])][] = $j['row'];
    foreach ($names as $n => $rows) {
        if (count($rows) > 1) $issues[] = "Trabalhos: o nome «{$n}» repete-se nas linhas " . implode(', ', $rows) . '. Os custos ligam-se pelo nome: torne-os únicos.';
    }
    $raw = [];
    foreach ($tab('Trabalhos') as $r) $raw[(int)$r['_row']] = $r;
    foreach ($raw as $row => $r) {
        foreach (['Ano', 'Budget (s/ IVA)', 'Gastos manuais'] as $col) {
            if (ms_text_number($r[$col] ?? '')) $issues[] = "Trabalhos linha $row: «{$col}» tem texto («{$r[$col]}») em vez de um número.";
        }
    }

    $crewByJob = [];
    foreach ($d['crew'] as $c) $crewByJob[$c['job']][] = $c;
    foreach ($d['crew'] as $c) {
        if ($c['job_row'] === null) $issues[] = "Equipa linha {$c['row']}: o trabalho «{$c['job']}» não existe em Trabalhos.";
    }
    foreach ($tab('Equipa') as $r) {
        if (ms_text_number($r['Valor'] ?? '')) $issues[] = "Equipa linha {$r['_row']}: «Valor» tem texto («{$r['Valor']}»).";
    }

    $jobs = [];
    foreach ($d['jobs'] as $j) {
        if ($j['year'] < 2000) $issues[] = "Trabalhos linha {$j['row']} («{$j['name']}»): falta o ano.";
        if (!$j['start_month']) $issues[] = "Trabalhos linha {$j['row']} («{$j['name']}»): falta o mês — é preciso para o ID.";
        $funnel = (int)($opt['funnel_by_type'][$j['type_label']] ?? 0);
        if (!$funnel) $issues[] = "Trabalhos linha {$j['row']} («{$j['name']}»): escolha o funil para o tipo «" . ($j['type_label'] ?: 'sem tipo') . '».';

        $costs = [];
        foreach ($crewByJob[$j['name']] ?? [] as $c) {
            $cost = ['amount' => $c['fee'], 'vat_mode' => $c['vat_mode']];
            $net = cost_net($cost, $rate); $vat = cost_vat($cost, $rate);
            foreach (['Valor s/ IVA' => [$net, $c['net']], 'IVA' => [$vat, $c['vat']], 'Total' => [$net + $vat, $c['total']]] as $col => [$mine, $theirs]) {
                if (!ms_eq($mine, $theirs)) $checks[] = "Equipa linha {$c['row']} («{$j['name']}» · {$c['role']}): {$col} — sistema " . eur($mine) . ', folha ' . eur($theirs) . '.';
            }
            $costs[] = $c + ['net_calc' => $net, 'vat_calc' => $vat];
        }

        $deal = [
            'budget' => $j['budget_net'], 'has_vat' => $j['has_vat'],
            'expenses_mode' => $j['expenses_mode'], 'expenses_manual' => $j['expenses_manual'],
        ];
        $m = deal_money($deal, array_map(fn($c) => ['net' => $c['net_calc'], 'vat' => $c['vat_calc'], 'is_closed' => $c['is_confirmed'] ? 1 : 0], $costs), $rate);
        foreach (['Budget (c/ IVA)' => [$m['budget_gross'], $j['budget_gross']], 'Gastos' => [$m['expenses'], $j['expenses']], 'Lucro' => [$m['profit'], $j['profit']]] as $col => [$mine, $theirs]) {
            if (!ms_eq($mine, $theirs)) $checks[] = "Trabalhos linha {$j['row']} («{$j['name']}»): {$col} — sistema " . eur($mine) . ', folha ' . eur($theirs) . '.';
        }
        $jobs[] = $j + ['funnel_id' => $funnel, 'costs' => $costs, 'money' => $m];
    }

    // IDs: por ano, mês e ordem na folha; a sequência recomeça todos os meses.
    usort($jobs, fn($a, $b) => [$a['year'], (int)$a['start_month'], $a['row']] <=> [$b['year'], (int)$b['start_month'], $b['row']]);
    $seq = [];
    foreach ($jobs as &$j) {
        if ($j['year'] < 2000 || !$j['start_month']) { $j['code'] = null; continue; }
        $yymm = sprintf('%02d%02d', $j['year'] % 100, $j['start_month']);
        $seq[$yymm] = ($seq[$yymm] ?? 0) + 1;
        $j['code'] = deal_code_format($yymm, $seq[$yymm]);
    }
    unset($j);

    // ── Custos empresa ──────────────────────────────────────
    $company = [];
    foreach ($tab('Custos empresa') as $r) {
        if (ms_text_number($r['Valor'] ?? '')) $issues[] = "Custos empresa linha {$r['_row']}: «Valor» tem texto («{$r['Valor']}»).";
    }
    foreach ($d['company'] as $c) {
        if ($c['year'] < 2000) $issues[] = "Custos empresa linha {$c['row']} («{$c['name']}»): falta o ano.";
        $cost = ['amount' => $c['amount'], 'vat_mode' => $c['vat_mode']];
        $net = cost_net($cost, $rate); $vat = cost_vat($cost, $rate);
        foreach (['Valor s/ IVA' => [$net, $c['net']], 'IVA' => [$vat, $c['vat']], 'Total' => [$net + $vat, $c['total']]] as $col => [$mine, $theirs]) {
            if (!ms_eq($mine, $theirs)) $checks[] = "Custos empresa linha {$c['row']} («{$c['name']}»): {$col} — sistema " . eur($mine) . ', folha ' . eur($theirs) . '.';
        }
        $company[] = $c;
    }

    // ── Leads ───────────────────────────────────────────────
    $jobNames = array_map(fn($j) => mb_strtolower($j['name']), $jobs);
    $leads = ['lead' => [], 'xray' => [], 'won' => [], 'won_dup' => [], 'archived' => [], 'lost' => []];
    $lostBudget = array_map('intval', (array)($opt['lost_with_budget'] ?? []));
    foreach ($d['leads'] as $l) {
        switch ($l['stage']) {
            case 'novo': case 'contactado': case 'em_conversa':
                $leads['lead'][] = $l; break;
            case 'proposta':
                $leads['xray'][] = $l; break;
            case 'ganho':
                in_array(mb_strtolower($l['name']), $jobNames, true) ? $leads['won_dup'][] = $l : $leads['won'][] = $l; break;
            case 'perdido':
                in_array($l['row'], $lostBudget, true) ? $leads['lost'][] = $l : $leads['archived'][] = $l; break;
            default:
                $issues[] = "Leads linha {$l['row']} («{$l['name']}»): fase desconhecida «{$l['stage_label']}».";
        }
    }
    foreach (array_merge($leads['xray'], $leads['won'], $leads['lost']) as $l) {
        if (!(int)($opt['funnel_by_service'][$l['service'] ?: '—'] ?? 0)) {
            $issues[] = "Leads linha {$l['row']} («{$l['name']}»): escolha o funil para o serviço «" . ($l['service'] ?: 'sem serviço') . '».';
        }
    }

    return [
        'jobs' => $jobs, 'company' => $company, 'leads' => $leads, 'suppliers' => $d['suppliers'],
        'issues' => array_values(array_unique($issues)), 'checks' => $checks,
        'counts' => [
            'jobs' => count($jobs), 'crew' => count($d['crew']), 'company' => count($company),
            'leads' => count($d['leads']), 'suppliers' => count($d['suppliers']),
        ],
    ];
}

/** O plano está pronto a executar? */
function ms_ready(array $plan): bool {
    return !$plan['issues'] && !$plan['checks'];
}

function ms_done(PDO $pdo): ?string {
    // Lido sempre da base (settings() guarda as definições em memória).
    $v = $pdo->query("SELECT svalue FROM settings WHERE skey = 'sheet_migrated_at'")->fetchColumn();
    return $v ?: null;
}

/**
 * Executa o plano. Tudo numa transação: ou entra tudo, ou nada.
 * $opt: owner_id (responsável das leads e negócios migrados), today, drive_folders [nome do trabalho => prefixo].
 * Devolve um resumo.
 */
function ms_execute(PDO $pdo, array $plan, array $opt): array {
    if (!ms_ready($plan)) throw new RuntimeException('O plano ainda tem problemas por resolver.');
    if (ms_done($pdo)) throw new RuntimeException('A migração já foi feita.');
    $owner = (int)$opt['owner_id'];
    $today = new DateTimeImmutable($opt['today'] ?? 'now', new DateTimeZone('Europe/Lisbon'));
    $sum = ['deals' => 0, 'costs' => 0, 'company' => 0, 'leads' => 0, 'archived' => 0, 'xray' => 0, 'won' => 0, 'lost' => 0, 'contacts' => 0, 'folders' => 0];

    $pdo->beginTransaction();
    try {
        $insDeal = $pdo->prepare(
            'INSERT INTO deals (code, phase, name, notes, origin, owner_id, created_by, funnel_id, origin_funnel_id, stage_id,
                                job_type, year, month, budget, is_international, has_vat, is_paid, expenses_mode, expenses_manual,
                                drive_folder, converted_at, done_at)
             VALUES (?, \'done\', ?, ?, \'manual\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . now_sql($pdo) . ', ' . now_sql($pdo) . ')'
        );
        $insCost = $pdo->prepare(
            'INSERT INTO costs (deal_id, year, month, name, role, amount, vat_mode, is_closed, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $maxSeq = [];
        foreach ($plan['jobs'] as $j) {
            $done = funnel_fixed_stage($pdo, $j['funnel_id'], 'done');
            $folder = (string)($opt['drive_folders'][$j['name']] ?? '');
            $insDeal->execute([
                $j['code'], $j['name'], $j['notes'], $owner, $owner, $j['funnel_id'], $j['funnel_id'], $done,
                $j['type_label'], $j['year'], $j['start_month'], $j['budget_net'], $j['is_international'] ? 1 : 0,
                $j['has_vat'], $j['is_paid'] ? 1 : 0, $j['expenses_mode'], $j['expenses_manual'], $folder,
            ]);
            $id = (int)$pdo->lastInsertId();
            deal_log($pdo, $id, 'import', ['to_funnel' => $j['funnel_id'], 'to_stage' => $done, 'note' => 'Trabalhos, linha ' . $j['row'], 'user_id' => $owner]);
            $sum['deals']++;
            if ($folder !== '') $sum['folders']++;
            foreach ($j['costs'] as $c) {
                $notes = trim(($c['contact'] !== '' ? 'Contacto: ' . $c['contact'] . "\n" : '') . $c['notes']);
                $insCost->execute([$id, $j['year'], $j['start_month'], $c['person'], $c['role'], $c['fee'], $c['vat_mode'],
                                   $c['is_confirmed'] ? 1 : 0, $notes, $owner]);
                $sum['costs']++;
            }
            [$yymm, $n] = [substr($j['code'], 2, 4), (int)substr($j['code'], 7)];
            $maxSeq[$yymm] = max($maxSeq[$yymm] ?? 0, $n);
        }
        // As sequências continuam a seguir aos IDs migrados.
        foreach ($maxSeq as $yymm => $n) {
            if (db_is_sqlite($pdo)) {
                $pdo->prepare('INSERT OR REPLACE INTO deal_codes (yymm, last_seq) VALUES (?, MAX(?, COALESCE((SELECT last_seq FROM deal_codes WHERE yymm = ?), 0)))')->execute([$yymm, $n, $yymm]);
            } else {
                $pdo->prepare('INSERT INTO deal_codes (yymm, last_seq) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_seq = GREATEST(last_seq, VALUES(last_seq))')->execute([$yymm, $n]);
            }
        }

        foreach ($plan['company'] as $c) {
            $insCost->execute([null, $c['year'], $c['start_month'], $c['name'], '', $c['amount'], $c['vat_mode'], $c['is_paid'] ? 1 : 0, $c['notes'], $owner]);
            $sum['company']++;
        }

        // Leads
        $prio = ['alta' => 'alta', 'média' => 'media', 'media' => 'media', 'baixa' => 'baixa'];
        $leadRow = function (array $l, string $phase, string $state = 'novo') use ($pdo, $owner, $prio) {
            $types = implode(', ', array_values(array_intersect($l['types'], LEAD_TYPES_LIST)));
            $other = array_diff($l['types'], LEAD_TYPES_LIST);
            $notes = trim($l['notes'] . ($other ? "\nTipo (folha): " . implode(', ', $other) : ''));
            $touch = preg_match('/^\d{4}-\d{2}-\d{2}$/', $l['last_touch']) ? $l['last_touch'] : null;
            $pdo->prepare(
                'INSERT INTO deals (phase, lead_state, name, types, links, phone, email, service, priority, detail, why, notes,
                                    origin, owner_id, created_by, last_touch)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,\'manual\',?,?,?)'
            )->execute([
                $phase, $state, $l['name'] ?: 'Sem nome', $types, implode(' | ', $l['contacts']), $l['phone'], $l['email'],
                in_array($l['service'], LEAD_SERVICES_LIST, true) ? $l['service'] : '',
                $prio[mb_strtolower($l['priority'])] ?? 'media', $l['detail'], $l['why'], $notes, $owner, $owner, $touch,
            ]);
            return (int)$pdo->lastInsertId();
        };
        foreach ($plan['leads']['lead'] as $l) { $leadRow($l, 'lead', $l['stage']); $sum['leads']++; }
        foreach ($plan['leads']['archived'] as $l) {
            $id = $leadRow($l, 'archived');
            $pdo->prepare("UPDATE deals SET archived_reason = 'perdido_migracao', archived_at = " . now_sql($pdo) . ' WHERE id = ?')->execute([$id]);
            $sum['archived']++;
        }
        // Proposta enviada → X-Ray; Ganho → Kickoff; Perdido com orçamento → perdido no X-Ray. Todos com ID.
        foreach (['xray' => 'xray', 'won' => 'kickoff', 'lost' => 'xray'] as $group => $kind) {
            foreach ($plan['leads'][$group] as $l) {
                $id = $leadRow($l, 'lead');
                $funnel = (int)$opt['funnel_by_service'][$l['service'] ?: '—'];
                $stage  = funnel_fixed_stage($pdo, $funnel, $kind);
                $pdo->prepare('INSERT INTO contacts (kind, name, is_client, email, phone, website, created_by) VALUES (\'company\', ?, 1, ?, ?, ?, ?)')
                    ->execute([$l['name'] ?: 'Sem nome', $l['email'], $l['phone'], mb_substr(implode(' | ', $l['contacts']), 0, 255), $owner]);
                $cid = (int)$pdo->lastInsertId();
                $sum['contacts']++;
                $pdo->prepare('INSERT INTO deal_contacts (deal_id, contact_id) VALUES (?, ?)')->execute([$id, $cid]);
                deal_assign_code($pdo, $id, $today);
                $phase = $group === 'won' ? 'production' : ($group === 'lost' ? 'lost' : 'pipeline');
                $pdo->prepare('UPDATE deals SET phase = ?, funnel_id = ?, origin_funnel_id = ?, stage_id = ?, contact_id = ?, converted_at = ' . now_sql($pdo)
                            . ($group === 'lost' ? ", lost_reason = 'Migração: perdido depois do orçamento', lost_stage_id = ?, lost_at = " . now_sql($pdo) : '')
                            . ' WHERE id = ?')
                    ->execute(array_merge([$phase, $funnel, $funnel, $stage, $cid], $group === 'lost' ? [$stage] : [], [$id]));
                if ($group === 'won') {
                    $pdo->prepare('UPDATE deals SET year = ?, month = ? WHERE id = ?')->execute([(int)$today->format('Y'), (int)$today->format('n'), $id]);
                }
                deal_log($pdo, $id, 'import', ['to_funnel' => $funnel, 'to_stage' => $stage, 'note' => 'Leads, linha ' . $l['row'] . ' (' . $l['stage_label'] . ')', 'user_id' => $owner]);
                $sum[$group]++;
            }
        }

        // Fornecedores → contactos do tipo Fornecedor
        $insC = $pdo->prepare(
            'INSERT INTO contacts (kind, name, is_supplier, category, service, contact_person, email, phone, nif, website, rate, rating, last_job, notes, created_by)
             VALUES (\'company\', ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($plan['suppliers'] as $s) {
            $insC->execute([$s['name'] ?: 'Sem nome', $s['category'], $s['service'], $s['contact'], $s['email'], $s['phone'], mb_substr($s['nif'], 0, 20),
                            mb_substr(implode(' | ', $s['links']), 0, 255), $s['rate'], ms_stars($s['rating']), $s['last_job'], $s['notes'], $owner]);
            $sum['contacts']++;
        }

        $pdo->prepare(db_is_sqlite($pdo)
            ? "INSERT OR REPLACE INTO settings (skey, svalue) VALUES ('sheet_migrated_at', ?)"
            : "INSERT INTO settings (skey, svalue) VALUES ('sheet_migrated_at', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)"
        )->execute([(new DateTimeImmutable('now', new DateTimeZone('Europe/Lisbon')))->format('Y-m-d H:i')]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $sum;
}

/**
 * As pastas antigas do Drive (Projetos/NN_NOME_PROJECT/, incluindo os nomes
 * associados à mão) que correspondem a cada trabalho. Só lê; não cria nada.
 */
function ms_drive_folders(array $jobs): array {
    if (!function_exists('r2_config') || !r2_config()) return [];
    require_once __DIR__ . '/drive.php';
    $folders = [];
    foreach (r2_list('Projetos/')['folders'] ?? [] as $f) {
        $leaf = substr(rtrim($f, '/'), strlen('Projetos/'));
        $folders[drive_project_core($leaf)] = 'Projetos/' . $leaf . '/';
    }
    $out = [];
    foreach ($jobs as $j) {
        $core = DRIVE_PROJECT_ALIASES[$j['name']] ?? drive_slug($j['name']);
        if (isset($folders[$core])) $out[$j['name']] = $folders[$core];
    }
    return $out;
}
