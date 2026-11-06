<?php
/**
 * Pipeline v2, fase 1 — a regra de ouro de ponta a ponta: um cenário v1 inteiro (tests/pipe2_v1_scenario.php) dá o
 * mesmo com o código de agora e com o da fase 0 (8c581ae). Prova o conjunto dos ganchos da fase 1 nos ficheiros
 * existentes (task_done, deal_move, flow_on_enter, flow_gate_check, flow_tick, xsell, undo, faturação, Financeiro,
 * equipa, Review, alertas): num negócio v1 não fazem nada.
 *
 * Duas bases privadas do dump da 42 (tests/.pipe2/base42.sql): uma migrada e corrida com o código de agora
 * (pipe2_sub: SCHEMA_VERSION), a outra com a árvore de 8c581ae (pipe2_tree: 43), migrada e corrida nela. Compara-se a
 * parte normalizada da saída (cmp: as colunas que existem nas duas bases, ids pela ordem de criação, sem horas).
 *
 *   ::scenario_runs   o cenário sai com 0 nas duas, cada um com o código da sua árvore (SCHEMA_VERSION e ficheiros
 *                     carregados), mexe em pelo menos 30 linhas e as únicas recusas são as que o cenário provoca;
 *   ::same_as_phase0  pipe2_diff(fase 0, agora) === [];
 *   ::no_v2_writes    com o código de agora, os negócios do cenário não têm nada nas tabelas da v2 (passos, ligações,
 *                     executores, avisos, apagamentos), nenhuma tarefa com gate partilhado (passo 44) e a bandeira
 *                     pipeline_v2_at continua desligada;
 *   ::not_blind       controlo: o mesmo cenário sem um passo (--skip=repeat: não fecha a tarefa que se repete) dá
 *                     diferenças nas tarefas — o diff [] não é cego.
 *
 * Só bases privadas (a bancada não se escreve). Uso: php tests/pipe2_v1_hooks_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_tree.php';

/** O código da fase 0 (contra o qual a fase 1 se compara). */
const P2H_PHASE0 = '8c581ae';

/** A base de testes que corre dentro da árvore da fase 0 (a de agora). */
const P2H_TEST_FILES = ['tests/boot.php', 'tests/lib.php', 'tests/pipe2_lib.php', 'tests/pipe2_v1_snap.php', 'tests/pipe2_v1_scenario.php'];

/** As tabelas que o cenário compara (as colunas são as que existem nas duas bases). */
const P2H_TABLES = ['deals', 'contacts', 'relations', 'deal_lines', 'tasks', 'task_log', 'deal_history', 'stage_events', 'deal_rounds',
                    'cc_rv_items', 'fin_docs', 'deal_billing', 'users', 'team_members', 'departments', 'funnels', 'funnel_stages'];

/** As recusas que o cenário provoca de propósito (e só essas). */
const P2H_REFUSALS = [
    'lead: X-Ray → Kickoff sem forçar',
    'crosselling: o negócio para uma etapa de produção',
    'crosselling: frente principal para o Concluído antes das outras etapas, sem forçar',
];

/** As colunas de cada tabela de P2H_TABLES nesta base. */
function p2h_cols(PDO $pdo): array {
    $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ORDINAL_POSITION');
    $out = [];
    foreach (P2H_TABLES as $t) {
        $st->execute([$t]);
        $out[$t] = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    return $out;
}

/** Corre o cenário (agora: pipe2_sub; fase 0: na árvore) e devolve o JSON, ou null com a falha. */
function p2h_run(string $what, ?string $tree, string $db, string $colsF, array $extra = []): ?array {
    $args = array_merge(['--db=' . $db, '--cols=' . $colsF], $extra);
    $r = $tree === null ? pipe2_sub('pipe2_v1_scenario.php', $args) : pipe2_tree_php($tree, 'tests/pipe2_v1_scenario.php', $args);
    $ok = $r['code'] === 0 && is_array($r['json']) && isset($r['json']['cmp'], $r['json']['meta']);
    t_true($ok, "::scenario_runs o cenário ($what) sai com 0 e JSON {cmp, meta} (código {$r['code']}: " . substr(trim($r['err'] . ' ' . ($ok ? '' : $r['out'])), 0, 900) . ')');
    return $ok ? $r['json'] : null;
}

if (!is_file(__DIR__ . '/pipe2_v1_scenario.php')) {
    t_true(false, '::scenario_runs falta o cenário tests/pipe2_v1_scenario.php');
    t_done();
}

$dump = pipe2_base_dump();
$dbNew = pipe2_tmpdb_create($dump);
$dbOld = pipe2_tmpdb_create($dump);
$tree = pipe2_tree(P2H_PHASE0, [], P2H_TEST_FILES, 'hooks');

// ── as duas bases, cada uma migrada pelo seu código ─────────

t_true(str_contains((string)file_get_contents($tree . '/admin/inc/migrate.php'), 'const SCHEMA_VERSION = 43;') && !glob($tree . '/admin/inc/pipe2_*.php'),
    '::scenario_runs a árvore de ' . P2H_PHASE0 . ' é a da fase 0 (SCHEMA_VERSION 43, sem os módulos pipe2_* da fase 1)');
$m = pipe2_sub('pipe2_v1_snap.php', ['--migrate', '--db=' . $dbNew]);
t_eq([$m['json']['from'] ?? null, $m['json']['to'] ?? null], [42, SCHEMA_VERSION], '::scenario_runs a base de agora migra da 42 para a SCHEMA_VERSION');
$m0 = pipe2_tree_php($tree, 'tests/pipe2_v1_snap.php', ['--migrate', '--db=' . $dbOld]);
t_eq([$m0['json']['from'] ?? null, $m0['json']['to'] ?? null], [42, 43], '::scenario_runs a base da fase 0 migra da 42 para a 43 com o código da fase 0 (' . substr(trim($m0['err']), 0, 300) . ')');

// as colunas que existem nas duas bases (as do passo 44 só na de agora)
$tpNew = pipe2_tmpdb_pdo($dbNew);
$tpOld = pipe2_tmpdb_pdo($dbOld);
$cNew = p2h_cols($tpNew);
$cOld = p2h_cols($tpOld);
$cols = [];
foreach (P2H_TABLES as $t) $cols[$t] = array_values(array_intersect($cNew[$t], $cOld[$t]));
t_true(!in_array('gate_stage_id', $cols['tasks'], true) && in_array('gate_stage_id', $cNew['tasks'], true) && in_array('cycle_ym', $cols['tasks'], true),
    '::scenario_runs compara as colunas das duas bases (a do passo 44 fica de fora; as do 43 entram)');
$colsF = tempnam(sys_get_temp_dir(), 'p2hc');
file_put_contents($colsF, json_encode($cols));
register_shutdown_function(fn() => @unlink($colsF));

// ── ::scenario_runs ─────────────────────────────────────────

$new = p2h_run('código de agora', null, $dbNew, $colsF);
$old = p2h_run('código da fase 0', $tree, $dbOld, $colsF);
t_eq([$new['meta']['schema'] ?? null, $old['meta']['schema'] ?? null], [SCHEMA_VERSION, 43], '::scenario_runs cada cenário corre com o SCHEMA_VERSION da sua árvore');
$links = pipe2_tree_links($tree);
$foreign = array_values(array_filter($old['meta']['files'] ?? ['(sem lista)'], fn($f) => !str_starts_with($f, $tree . '/') && !in_array($f, $links, true)));
t_eq($foreign, [], '::scenario_runs o cenário da fase 0 só carrega ficheiros da árvore de ' . P2H_PHASE0 . ' (e a configuração ligada)');
t_true((bool)array_filter($new['meta']['files'] ?? [], fn($f) => str_ends_with($f, '/admin/inc/pipe2_engine.php')),
    '::scenario_runs o cenário de agora passa pelos ganchos (carrega o inc/pipe2_engine.php)');
foreach (['agora' => $new, 'fase 0' => $old] as $k => $j) {
    t_true(($j['meta']['touched'] ?? 0) >= 30, "::scenario_runs o cenário ($k) mexe em pelo menos 30 linhas (" . ($j['meta']['touched'] ?? 0) . ')');
    $refused = array_values(array_map(fn($c) => $c[0], array_filter($j['cmp']['calls'] ?? [], fn($c) => is_string($c[1] ?? null) && str_starts_with($c[1], '!'))));
    t_eq($refused, P2H_REFUSALS, "::scenario_runs as únicas recusas do cenário ($k) são as provocadas");
}

// ── ::same_as_phase0 ────────────────────────────────────────

$d = pipe2_diff($old['cmp'] ?? null, $new['cmp'] ?? null);
t_true($d === [], '::same_as_phase0 o cenário v1 dá o mesmo com o código de agora e com o da fase 0'
    . ($d === [] ? '' : ' — ' . count($d) . " diferença(s):\n  " . implode("\n  ", array_slice($d, 0, 40))));

// ── ::no_v2_writes ──────────────────────────────────────────

$deals = array_map('intval', $new['meta']['deals'] ?? []);
$in = pipe2_ids_sql($deals);
t_true(count($deals) >= 4, '::no_v2_writes o cenário de agora criou os seus negócios (' . count($deals) . ')');
$v2 = [];
foreach (['deal_line_steps', 'deal_line_deps', 'deal_line_execs', 'pipe2_notices', 'task_deletions'] as $t) {
    $v2[$t] = db_has_table($tpNew, $t) ? (int)$tpNew->query("SELECT COUNT(*) FROM $t WHERE deal_id IN ($in)")->fetchColumn() : -1;
}
$v2['tasks.gate_stage_id'] = (int)$tpNew->query("SELECT COUNT(*) FROM tasks WHERE deal_id IN ($in) AND gate_stage_id IS NOT NULL")->fetchColumn();
$v2['pipeline_v2_at'] = (int)$tpNew->query("SELECT COUNT(*) FROM settings WHERE skey = 'pipeline_v2_at'")->fetchColumn();
t_eq($v2, array_fill_keys(array_keys($v2), 0), '::no_v2_writes com o código de agora, nada da v2 nos negócios do cenário');

// ── ::not_blind (controlo) ──────────────────────────────────

$dbSkip = pipe2_tmpdb_create($dump);
$ms = pipe2_sub('pipe2_v1_snap.php', ['--migrate', '--db=' . $dbSkip]);
t_eq($ms['json']['to'] ?? null, SCHEMA_VERSION, '::not_blind a 3.ª base privada migra para a SCHEMA_VERSION');
$skip = p2h_run('código de agora, --skip=repeat', null, $dbSkip, $colsF, ['--skip=repeat']);
$ds = pipe2_diff($new['cmp'] ?? null, $skip['cmp'] ?? null);
t_true((bool)array_filter($ds, fn($l) => str_starts_with($l, 'rows.tasks.')),
    '::not_blind sem fechar a tarefa que se repete, o cenário dá diferenças nas tarefas (' . count($ds) . ' diferença(s))');

$tpNew = $tpOld = null;
pipe2_tmpdb_drop($dbNew);
pipe2_tmpdb_drop($dbOld);
pipe2_tmpdb_drop($dbSkip);
t_done();
