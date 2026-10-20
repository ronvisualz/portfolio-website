<?php
/**
 * topper. admin — a passagem única da folha Google para a base de dados
 *
 * data_import($pdo, $sheet, $apply, $replace) recebe o que sheet_map() devolve
 * (os cinco separadores já convertidos, com as colunas calculadas da folha) e:
 *   1. grava trabalhos, equipa, custos da empresa, leads e fornecedores;
 *   2. volta a fazer as contas na base e compara-as, linha a linha, com as
 *      colunas calculadas da folha (Budget c/ IVA, Gastos, Lucro, Valor s/ IVA,
 *      IVA, Total) e com os totais de cada ano;
 *   3. liga os eventos do calendário aos trabalhos (pelo nome do projeto antigo).
 * Tudo numa só transação: sem $apply, ou se alguma conta não bater, desfaz no fim.
 * Devolve um relatório (contagens, avisos, diferenças).
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/data.php';

const DATA_TABLES = ['job_crew', 'jobs', 'company_costs', 'leads', 'suppliers'];

function data_import(PDO $pdo, array $sheet, bool $apply, bool $replace = false, bool $force = false): array {
    $rep = ['applied' => false, 'counts' => [], 'errors' => [], 'warnings' => [], 'mismatches' => [], 'years' => [], 'events_linked' => 0];
    // errors: impedem a importação mesmo com --force (corrigir na folha e voltar a correr)
    $long = static function (string $table, array $f, string $where) use (&$rep): void {
        foreach (data_too_long($table, $f) as $col => $len)
            $rep['warnings'][] = "$where: «{$col}» tem $len caracteres; ficou cortado em " . DATA_MAX[$table][$col] . '.';
    };

    $existing = 0;
    foreach (DATA_TABLES as $t) $existing += (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    if ($existing > 0 && !$replace) {
        $rep['warnings'][] = "A base já tem $existing registos nestas tabelas. Nada foi importado (use --replace para os substituir).";
        $rep['blocked'] = true;
        return $rep;
    }

    $pdo->beginTransaction();
    try {
        if ($replace) {
            foreach (DATA_TABLES as $t) $pdo->exec("DELETE FROM $t");
            if (db_has_column($pdo, 'events', 'job_id')) $pdo->exec('UPDATE events SET job_id = NULL');
        }

        // ── Trabalhos (pela ordem da folha) ──
        // Os nomes comparam-se como a base os compara (sem maiúsculas nem acentos): «Nike» e «NIKE» são o mesmo.
        $jobId = []; $jobAt = []; $rowName = []; $sheetJobs = $sheet['jobs'];
        usort($sheetJobs, static fn($a, $b) => $a['row'] <=> $b['row']);
        foreach ($sheetJobs as $j) {
            $name = trim($j['name']);
            if ($name === '') {
                $name = 'Sem nome (linha ' . $j['row'] . ')';
                $rep['warnings'][] = "Trabalhos, linha {$j['row']}: sem nome; importado como «{$name}».";
            }
            $key = data_name_key($name);
            if (isset($jobId[$key])) {
                $rep['errors'][] = "Trabalhos, linha {$j['row']}: «{$name}» repete o trabalho da linha {$jobAt[$key]}. Mude o nome de um deles na folha.";
                continue;
            }
            if (($j['budget_net'] ?? 0) < 0 || ($j['expenses_manual'] ?? 0) < 0)
                $rep['warnings'][] = "Trabalhos, linha {$j['row']} «{$name}»: valor negativo (confirme no admin depois de importar).";
            $f = [
                'name' => $name, 'year' => $j['year'], 'month' => $j['start_month'], 'type_label' => $j['type_label'],
                'budget_net' => $j['budget_net'], 'is_international' => $j['is_international'], 'has_vat' => $j['has_vat'],
                'expenses_manual' => $j['expenses_mode'] === 'manual' ? $j['expenses_manual'] : null,
                'is_paid' => $j['is_paid'], 'notes' => $j['notes'], 'position' => $j['row'],
            ];
            $long('jobs', $f, "Trabalhos, linha {$j['row']}");
            try {
                $jobId[$key] = data_insert('jobs', $f);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                $rep['errors'][] = "Trabalhos, linha {$j['row']}: a base considera «{$name}» igual a outro nome da folha. Mude-lhe o nome na folha.";
                continue;
            }
            $jobAt[$key] = $j['row'];
            $rowName[$j['row']] = $name;
        }
        $rep['counts']['Trabalhos'] = count($rowName);

        // ── Equipa ──
        $n = 0;
        foreach ($sheet['crew'] as $c) {
            $jid = $jobId[data_name_key($c['job'])] ?? null;
            if ($jid === null) {
                $rep['warnings'][] = "Equipa, linha {$c['row']}: o trabalho «{$c['job']}» não existe nos Trabalhos ({$c['person']}, {$c['role']}, " . eur($c['fee']) . '). Não foi importada.';
                $rep['orphans'][] = $c['row'];
                continue;
            }
            if ($c['fee'] < 0) $rep['warnings'][] = "Equipa, linha {$c['row']} ({$c['person']}): valor negativo (confirme no admin depois de importar).";
            $f = [
                'job_id' => $jid, 'person' => $c['person'], 'role' => $c['role'], 'fee' => $c['fee'], 'vat_mode' => $c['vat_mode'],
                'is_confirmed' => $c['is_confirmed'], 'contact' => $c['contact'], 'notes' => $c['notes'], 'position' => $c['row'],
            ];
            $long('job_crew', $f, "Equipa, linha {$c['row']}");
            data_insert('job_crew', $f);
            $n++;
        }
        $rep['counts']['Equipa'] = $n;

        // ── Custos da empresa ──
        foreach ($sheet['company'] as $c) {
            if ($c['amount'] < 0) $rep['warnings'][] = "Custos empresa, linha {$c['row']} «{$c['name']}»: valor negativo (confirme no admin depois de importar).";
            $f = [
                'year' => $c['year'], 'month' => $c['start_month'], 'name' => $c['name'], 'amount' => $c['amount'],
                'vat_mode' => $c['vat_mode'], 'is_paid' => $c['is_paid'], 'notes' => $c['notes'], 'position' => $c['row'],
            ];
            $long('company_costs', $f, "Custos empresa, linha {$c['row']}");
            data_insert('company_costs', $f);
        }
        $rep['counts']['Custos empresa'] = count($sheet['company']);

        // ── Leads ──
        foreach ($sheet['leads'] as $l) {
            $notes = $l['notes'];
            if ($l['stage'] === '') {
                $rep['warnings'][] = "Leads, linha {$l['row']} («{$l['name']}»): a fase «{$l['stage_label']}» não existe no admin; ficou «Novo» e a fase original foi para as notas.";
                $notes = trim($notes . "\nFase na folha: " . $l['stage_label']);
            }
            $touch = (string)data_cast('d', substr($l['last_touch'], 0, 10));
            if ($l['last_touch'] !== '' && $touch === '') {
                $rep['warnings'][] = "Leads, linha {$l['row']} («{$l['name']}»): «Último toque» = «{$l['last_touch']}» não é uma data; foi para as notas.";
                $notes = trim($notes . "\nÚltimo toque: " . $l['last_touch']);
            }
            $f = [
                'name' => $l['name'], 'types' => $l['types'], 'contacts' => $l['contacts'], 'phone' => $l['phone'],
                'stage' => $l['stage'] !== '' ? $l['stage'] : 'novo', 'service' => $l['service'], 'priority' => $l['priority'],
                'detail' => $l['detail'], 'why' => $l['why'], 'email' => $l['email'], 'notes' => $notes,
                'last_touch' => $touch, 'position' => $l['row'],
            ];
            $long('leads', $f, "Leads, linha {$l['row']}");
            data_insert('leads', $f);
        }
        $rep['counts']['Leads'] = count($sheet['leads']);

        // ── Fornecedores ──
        // sem este separador a importação ficaria sem fornecedores, e depois já não dava para repetir
        if (!empty($sheet['suppliers_error'])) $rep['errors'][] = 'Fornecedores: ' . $sheet['suppliers_error'];
        foreach ($sheet['suppliers'] as $s) {
            if (trim((string)$s['rating']) !== '' && data_cast('r', $s['rating']) === 0)
                $rep['warnings'][] = "Fornecedores, linha {$s['row']} «{$s['name']}»: a avaliação «{$s['rating']}» não foi percebida; ficou sem estrelas.";
            $f = [
                'name' => $s['name'], 'category' => $s['category'], 'service' => $s['service'], 'contact' => $s['contact'],
                'email' => $s['email'], 'phone' => $s['phone'], 'links' => $s['links'], 'nif' => $s['nif'], 'rate' => $s['rate'],
                'rating' => $s['rating'], 'last_job' => $s['last_job'], 'notes' => $s['notes'], 'position' => $s['row'],
            ];
            $long('suppliers', $f, "Fornecedores, linha {$s['row']}");
            data_insert('suppliers', $f);
        }
        $rep['counts']['Fornecedores'] = count($sheet['suppliers']);

        // ── as contas da base contra as da folha ──
        $d = data_all(true);
        $near = static fn($a, $b) => abs((float)$a - (float)$b) < 0.005;
        $byName = [];
        foreach ($d['jobs'] as $j) $byName[$j['name']] = $j;
        foreach ($sheetJobs as $sj) {
            if (!isset($rowName[$sj['row']])) continue;   // repetido: já está nos erros
            $dj = $byName[$rowName[$sj['row']]] ?? null;
            if (!$dj) { $rep['mismatches'][] = "Trabalhos, linha {$sj['row']} «{$sj['name']}»: não ficou na base."; continue; }
            foreach (['budget_gross' => 'Budget (c/ IVA)', 'expenses' => 'Gastos', 'profit' => 'Lucro'] as $k => $label) {
                if (!$near($dj[$k], $sj[$k])) $rep['mismatches'][] = "Trabalhos, linha {$sj['row']} «{$sj['name']}»: $label na folha " . eur($sj[$k]) . ', na base ' . eur($dj[$k]);
            }
        }
        // a posição de cada linha é a linha da folha
        $crewByPos = [];
        foreach ($d['crew'] as $c) $crewByPos[$c['position']] = $c;
        $orphan = array_flip($rep['orphans'] ?? []);
        foreach ($sheet['crew'] as $sc) {
            if (isset($orphan[$sc['row']])) continue;
            $dc = $crewByPos[$sc['row']] ?? null;
            if (!$dc) { $rep['mismatches'][] = "Equipa, linha {$sc['row']} ({$sc['person']}): não ficou na base."; continue; }
            foreach (['net' => 'Valor s/ IVA', 'vat' => 'IVA', 'total' => 'Total'] as $k => $label) {
                if (!$near($dc[$k], $sc[$k])) $rep['mismatches'][] = "Equipa, linha {$sc['row']} ({$sc['person']}): $label na folha " . eur($sc[$k]) . ', na base ' . eur($dc[$k]);
            }
        }
        $cost = [];
        foreach ($d['company'] as $c) $cost[$c['position']] = $c;
        foreach ($sheet['company'] as $sc) {
            $dc = $cost[$sc['row']] ?? null;
            if (!$dc) { $rep['mismatches'][] = "Custos empresa, linha {$sc['row']} «{$sc['name']}»: não ficou na base."; continue; }
            foreach (['net' => 'Valor s/ IVA', 'vat' => 'IVA', 'total' => 'Total'] as $k => $label) {
                if (!$near($dc[$k], $sc[$k])) $rep['mismatches'][] = "Custos empresa, linha {$sc['row']} «{$sc['name']}»: $label na folha " . eur($sc[$k]) . ', na base ' . eur($dc[$k]);
            }
        }
        foreach ($sheet['years'] as $y) {
            $a = sheet_year_totals($sheet, $y);
            $b = data_year_totals($d, $y);
            $rep['years'][$y] = $b;
            foreach (['revenue' => 'Faturação', 'costs' => 'Gastos', 'profit' => 'Lucro', 'vat_out' => 'IVA liquidado', 'vat_in' => 'IVA suportado', 'unpaid' => 'Por receber'] as $k => $label) {
                if (!$near($a[$k], $b[$k])) $rep['mismatches'][] = "$y: $label na folha " . eur($a[$k]) . ', na base ' . eur($b[$k]);
            }
        }

        // ── o calendário: eventos dos projetos antigos → trabalhos com o mesmo nome ──
        if (db_has_table($pdo, 'legacy_projects') && db_has_column($pdo, 'events', 'project_id') && db_has_column($pdo, 'events', 'job_id')) {
            $up = $pdo->prepare('UPDATE events SET job_id = ? WHERE id = ?');
            foreach ($pdo->query('SELECT e.id, p.name FROM events e JOIN legacy_projects p ON p.id = e.project_id WHERE e.job_id IS NULL') as $r) {
                $jid = $jobId[data_name_key((string)$r['name'])] ?? null;
                if ($jid !== null) { $up->execute([$jid, (int)$r['id']]); $rep['events_linked']++; }
                else $rep['warnings'][] = "Calendário: o evento #{$r['id']} era do projeto «{$r['name']}», que não está nos Trabalhos; fica sem trabalho.";
            }
        }

        $ok = !$rep['errors'] && ($force || (!$rep['mismatches'] && empty($rep['orphans'])));
        if ($apply && $ok) {
            $pdo->commit();
            $rep['applied'] = true;
            setting_put($pdo, 'data_imported_at', gmdate('Y-m-d H:i:s'));
        } else {
            $pdo->rollBack();
            if ($apply) $rep['warnings'][] = $rep['errors']
                ? 'Nada foi gravado: corrija na folha os erros acima e volte a correr.'
                : 'Nada foi gravado: há diferenças nas contas ou linhas da Equipa sem trabalho (veja acima). Use --force para importar mesmo assim.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    data_forget();
    return $rep;
}

/** settings: grava (ou substitui) um valor. */
function setting_put(PDO $pdo, string $key, string $value): void {
    $pdo->prepare('DELETE FROM settings WHERE skey = ?')->execute([$key]);
    $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)')->execute([$key, $value]);
}

/** O relatório em texto (para a linha de comandos e para o ficheiro). */
function data_import_text(array $rep): string {
    $o = [];
    $o[] = $rep['applied'] ? 'IMPORTADO.' : (!empty($rep['blocked']) ? 'NÃO IMPORTADO (a base já tem dados).' : 'ENSAIO (nada foi gravado).');
    foreach ($rep['counts'] as $k => $v) $o[] = sprintf('  %-16s %d', $k, $v);
    if ($rep['events_linked']) $o[] = '  Eventos ligados a trabalhos: ' . $rep['events_linked'];
    foreach ($rep['years'] as $y => $t) {
        $o[] = sprintf('  %d · faturação %s · gastos %s · lucro %s · IVA liq. %s · IVA sup. %s · por receber %s',
            $y, eur($t['revenue']), eur($t['costs']), eur($t['profit']), eur($t['vat_out']), eur($t['vat_in']), eur($t['unpaid']));
    }
    if ($rep['errors']) {
        $o[] = 'Erros (a corrigir na folha): ' . count($rep['errors']);
        foreach ($rep['errors'] as $e) $o[] = '  ✗ ' . $e;
    }
    $o[] = 'Contas que não batem com a folha: ' . count($rep['mismatches']);
    foreach ($rep['mismatches'] as $m) $o[] = '  ✗ ' . $m;
    $o[] = 'Avisos: ' . count($rep['warnings']);
    foreach ($rep['warnings'] as $w) $o[] = '  ! ' . $w;
    return implode("\n", $o) . "\n";
}
