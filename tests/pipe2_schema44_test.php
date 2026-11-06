<?php
/**
 * Pipeline v2, fase 1 — passo 44 (admin/inc/migrate_pipeline2_44.php): a tabela dos avisos (pipe2_notices), a ação e o
 * N das regras (stage_automations.action, trig_n) e o gate partilhado das tarefas (tasks.gate_stage_id). Só acrescenta.
 *
 * Corre sempre sobre bases privadas novas, restauradas da linha de base v42 (tests/.pipe2/base42.sql): a bancada
 * partilhada não se escreve (por isso não pede o pipe2_lock). «Na 43» = fresca + migrate_pipeline2_43. Os valores
 * esperados estão escritos aqui à letra (plano da fase 1, tarefa 1, secção «Esquema 44»), não lidos da migração.
 *
 * Casos: ::columns ::existing_untouched ::rows_defaults ::idempotent ::codes ::strict_modes ::from_42_and_43 ::lock
 *
 * Uso: php tests/pipe2_schema44_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/migrate_pipeline2.php';
require_once __DIR__ . '/../admin/inc/migrate_pipeline2_44.php';

// ── o que se espera (à letra) ───────────────────────────────

/** As colunas novas em tabelas existentes: [COLUMN_TYPE do MariaDB, IS_NULLABLE, COLUMN_DEFAULT sem aspas]. */
const P244_COLS = [
    'stage_automations.action' => ['varchar(8)', 'NO', 'task'],
    'stage_automations.trig_n' => ['smallint(6)', 'YES', null],
    'tasks.gate_stage_id'      => ['int(10) unsigned', 'YES', null],
];

/** A tabela nova, coluna a coluna (pela ordem). */
const P244_NOTICES = [
    'id'         => ['int(10) unsigned', 'NO', null],
    'deal_id'    => ['int(10) unsigned', 'NO', null],
    'line_id'    => ['int(10) unsigned', 'YES', null],
    'stage_id'   => ['int(10) unsigned', 'YES', null],
    'cycle_ym'   => ['varchar(7)', 'NO', ''],
    'kind'       => ['varchar(10)', 'NO', 'rule'],
    'rule_id'    => ['int(10) unsigned', 'YES', null],
    'user_id'    => ['int(10) unsigned', 'YES', null],
    'title'      => ['varchar(200)', 'NO', ''],
    'body'       => ['varchar(255)', 'NO', ''],
    'nkey'       => ['varchar(64)', 'NO', ''],
    'created_at' => ['datetime', 'NO', 'CURRENT_TIMESTAMP'],
];

/** Os índices novos: «tabela.índice» => [NON_UNIQUE, colunas]. */
const P244_INDEXES = [
    'pipe2_notices.PRIMARY'      => [0, 'id'],
    'pipe2_notices.idx_p2n_deal' => [1, 'deal_id'],
    'pipe2_notices.idx_p2n_key'  => [1, 'nkey'],
    'pipe2_notices.idx_p2n_user' => [1, 'user_id,created_at'],
];

/** Os códigos que a fase 1 acrescenta a PIPE2_CODES (exatamente). */
const P244_CODES = [
    'stage_automations.action'    => ['task', 'notify'],
    'stage_automations.assign_to' => ['svc_head'],
    'stage_events.step'           => ['p2_front', 'p2_wait'],
    'pipe2_notices.kind'          => ['replan', 'stale', 'dep_met', 'rule', 'cycle'],
    'tasks.cycle_ym'              => ['2026-11'],
    'deal_history.event'          => ['v2_convert', 'p2_front', 'p2_wait', 'p2_undo', 'p2_auto', 'p2_units', 'p2_dep', 'p2_replan',
                                      'p2_billing', 'p2_services', 'p2_category'],
];

/** As chaves de PIPE2_CODES da fase 0 (ficam todas). */
const P244_CODES_PHASE0 = [
    'funnels.service_code', 'funnel_stages.level', 'funnel_stages.role', 'deal_lines.status', 'deal_lines.billing', 'deal_lines.exec_mode',
    'deal_lines.cycle_ym', 'deals.billing', 'deals.category', 'deals.service_codes', 'stage_automations.trig', 'deal_line_deps.source',
    'deal_line_steps.auto_hold_why', 'pipe2_dep_models.category', 'pipe2_categories.code', 'pipe2_convert_batches.status',
    'pipe2_convert_log.op', 'deal_history.event', 'metric_goals.scope', 'metric_goals.metric',
];

// ── ajudas ──────────────────────────────────────────────────

/** Uma base privada nova do dump v42: [nome, ligação]. */
function p244_fresh(): array {
    $db = pipe2_tmpdb_create(pipe2_base_dump());
    return [$db, pipe2_tmpdb_pdo($db)];
}

/** Uma base privada «na 43»: fresca + migrate_pipeline2_43 (a schema_version gravada continua 42). */
function p244_at43(): array {
    [$db, $p] = p244_fresh();
    migrate_pipeline2_43($p);
    return [$db, $p];
}

/** COLUMN_DEFAULT sem aspas; NULL e 'NULL' = null; current_timestamp() = CURRENT_TIMESTAMP. */
function p244_def($d): ?string {
    if ($d === null || $d === 'NULL') return null;
    $d = trim((string)$d, "'");
    return preg_match('/^current_timestamp(\(\))?$/i', $d) ? 'CURRENT_TIMESTAMP' : $d;
}

/** Retrato de information_schema.columns: «tabela.coluna» => [tipo, nulo, default, colação, extra]. */
function p244_cols(PDO $p): array {
    $out = [];
    foreach (p2v_rows($p, 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS ty, IS_NULLABLE AS n, COLUMN_DEFAULT AS d, COLLATION_NAME AS co, EXTRA AS x
                             FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $r) {
        $out[$r['t'] . '.' . $r['c']] = [strtolower((string)$r['ty']), (string)$r['n'], p244_def($r['d']), $r['co'], (string)$r['x']];
    }
    return $out;
}

/** Retrato dos índices: «tabela.índice» => [NON_UNIQUE, colunas]. */
function p244_idx(PDO $p): array {
    $out = [];
    foreach (p2v_rows($p, 'SELECT TABLE_NAME AS t, INDEX_NAME AS i, NON_UNIQUE AS nu, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
                             FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE()
                            GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME') as $r) {
        $out[$r['t'] . '.' . $r['i']] = [(int)$r['nu'], (string)$r['cols']];
    }
    return $out;
}

/** As colunas de uma tabela no retrato, pela ordem. */
function p244_colnames(array $cols, string $t): array {
    $out = [];
    foreach (array_keys($cols) as $k) if (str_starts_with($k, $t . '.')) $out[] = substr($k, strlen($t) + 1);
    return $out;
}

/** O lock da migração está livre (visto de outra ligação, à mesma base). */
function p244_lock_free(string $db): bool {
    return (string)p2v_col(pipe2_tmpdb_pdo($db), "SELECT IS_FREE_LOCK(CONCAT('topper_pipe2_', DATABASE()))") === '1';
}

/** Corre $fn e devolve a mensagem da exceção, ou null. */
function p244_try(callable $fn): ?string {
    try { $fn(); } catch (Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
    return null;
}

// ═══ base A: na 43, depois o 44 ═════════════════════════════

[$dbA, $A] = p244_at43();
$colsBefore = p244_cols($A);
$idxBefore = p244_idx($A);
$SIGT = ['deals', 'deal_lines', 'tasks', 'stage_automations', 'deal_history', 'stage_events'];
$sigSpec = [];
foreach ($SIGT as $t) $sigSpec[$t] = p244_colnames($colsBefore, $t);   // só as colunas que já existiam (v1 e 43)
$sigBefore = p2v_sig($A, $sigSpec);
t_true((int)p2v_col($A, 'SELECT COUNT(*) FROM stage_automations') > 0 && (int)p2v_col($A, 'SELECT COUNT(*) FROM tasks') > 0,
    'a base A tem regras e tarefas (o retrato das linhas tem o que comparar)');

$err = p244_try(fn() => migrate_pipeline2_44($A));
t_eq($err, null, 'migrar a base A (na 43) com o passo 44 sem exceção');
$colsAfter = p244_cols($A);
$idxAfter = p244_idx($A);

// ::columns
foreach (P244_COLS as $k => $want) t_eq(array_slice($colsAfter[$k] ?? [], 0, 3), $want, "::columns $k");
t_true(db_has_table($A, 'pipe2_notices'), '::columns a tabela pipe2_notices existe');
$got = [];
foreach (p244_colnames($colsAfter, 'pipe2_notices') as $c) $got[$c] = array_slice($colsAfter['pipe2_notices.' . $c], 0, 3);
t_eq($got, P244_NOTICES, '::columns as colunas de pipe2_notices (nomes, ordem, tipos, nulos e defaults)');
t_eq($colsAfter['pipe2_notices.id'][4] ?? null, 'auto_increment', '::columns pipe2_notices.id é auto_increment');
foreach (['cycle_ym', 'kind', 'title', 'body', 'nkey'] as $c) {
    t_eq($colsAfter['pipe2_notices.' . $c][3] ?? null, 'utf8mb4_unicode_ci', "::columns pipe2_notices.$c em utf8mb4_unicode_ci");
}
foreach (P244_INDEXES as $k => $want) t_eq($idxAfter[$k] ?? null, $want, "::columns o índice $k");
t_true(p244_lock_free($dbA), '::columns o lock fica livre depois do passo 44');

// ::existing_untouched
$changed = [];
foreach ($colsBefore as $k => $v) if (($colsAfter[$k] ?? null) !== $v) $changed[] = $k;
t_eq($changed, [], '::existing_untouched nenhuma coluna existente mudou');
$added = array_values(array_diff(array_keys($colsAfter), array_keys($colsBefore)));
$wantAdded = array_keys(P244_COLS);
foreach (array_keys(P244_NOTICES) as $c) $wantAdded[] = 'pipe2_notices.' . $c;
sort($added);
sort($wantAdded);
t_eq($added, $wantAdded, '::existing_untouched só se acrescentaram as 3 colunas e a tabela pipe2_notices');
$changedIdx = [];
foreach ($idxBefore as $k => $v) if (($idxAfter[$k] ?? null) !== $v) $changedIdx[] = $k;
t_eq($changedIdx, [], '::existing_untouched os índices existentes ficaram iguais');
$addedIdx = array_values(array_diff(array_keys($idxAfter), array_keys($idxBefore)));
$wantIdx = array_keys(P244_INDEXES);
sort($addedIdx);
sort($wantIdx);
t_eq($addedIdx, $wantIdx, '::existing_untouched só se acrescentaram os índices de pipe2_notices');

// ::rows_defaults
t_eq((int)p2v_col($A, "SELECT COUNT(*) FROM stage_automations WHERE action <> 'task' OR trig_n IS NOT NULL"), 0,
    "::rows_defaults as regras existentes ficam com action 'task' e trig_n NULL");
t_eq((int)p2v_col($A, 'SELECT COUNT(*) FROM tasks WHERE gate_stage_id IS NOT NULL'), 0, '::rows_defaults nenhuma tarefa ganha gate_stage_id');
t_eq((int)p2v_col($A, 'SELECT COUNT(*) FROM pipe2_notices'), 0, '::rows_defaults pipe2_notices nasce vazia');
t_eq(p2v_sig($A, $sigSpec), $sigBefore, '::rows_defaults deals, deal_lines, tasks, stage_automations, deal_history e stage_events iguais (colunas de antes)');
t_eq(p2v_col($A, "SELECT svalue FROM settings WHERE skey = 'schema_version'"), '42', '::rows_defaults o passo não grava schema_version (é o run_migrations)');
t_eq((int)p2v_col($A, "SELECT COUNT(*) FROM settings WHERE skey = 'pipeline_v2_at'"), 0, '::rows_defaults o passo não grava pipeline_v2_at');

// ::idempotent
$sigAll = p2v_sig($A, array_merge($SIGT, ['pipe2_notices']));
$err = p244_try(fn() => migrate_pipeline2_44($A));
t_eq($err, null, '::idempotent 2.ª chamada sem exceção');
t_eq(p244_cols($A), $colsAfter, '::idempotent 2.ª chamada: information_schema.columns igual');
t_eq(p244_idx($A), $idxAfter, '::idempotent 2.ª chamada: índices iguais');
t_eq(p2v_sig($A, array_merge($SIGT, ['pipe2_notices'])), $sigAll, '::idempotent 2.ª chamada: as linhas ficam iguais');

// ::codes — os códigos novos estão em PIPE2_CODES (os da fase 0 ficam) e cabem nas colunas da base na 44
foreach (P244_CODES as $tc => $want) t_eq(PIPE2_CODES[$tc] ?? null, $want, "::codes PIPE2_CODES['$tc']");
$wantKeys = array_values(array_unique(array_merge(P244_CODES_PHASE0, array_keys(P244_CODES))));
$gotKeys = array_keys(PIPE2_CODES);
sort($wantKeys);
sort($gotKeys);
t_eq($gotKeys, $wantKeys, '::codes PIPE2_CODES tem as chaves da fase 0 e as novas, e só essas');
foreach (P244_CODES as $tc => $vals) {
    [$t, $c] = explode('.', $tc, 2);
    $w = p2v_col($A, 'SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
    $long = array_values(array_filter($vals, fn($v) => $w === false || $w === null || mb_strlen($v) > (int)$w));
    t_eq($long, [], "::codes os códigos de $tc cabem na coluna (largura " . var_export($w, true) . ')');
}

// ::lock — com outra ligação a segurar o lock do passo, o 44 recusa (não corre sem ele) e não o fica a segurar
$holder = pipe2_tmpdb_pdo($dbA);
t_eq((string)p2v_col($holder, "SELECT GET_LOCK(CONCAT('topper_pipe2_', DATABASE()), 30)"), '1', '::lock outra ligação segura o lock do passo');
$t0 = microtime(true);
$err = p244_try(fn() => migrate_pipeline2_44($A));
t_true($err !== null && str_starts_with($err, 'RuntimeException: Passo 44'), '::lock o passo 44 lança RuntimeException «Passo 44: …» (' . var_export($err, true) . ')');
t_true(microtime(true) - $t0 >= 25, '::lock esperou pelo lock (GET_LOCK de 30 s) antes de desistir');
t_eq((string)p2v_col($holder, "SELECT RELEASE_LOCK(CONCAT('topper_pipe2_', DATABASE()))"), '1', '::lock quem o segurava solta-o');
t_true(p244_lock_free($dbA), '::lock depois, o lock fica livre (o passo que falhou não o tomou)');
$holder = null;
$A = null;
pipe2_tmpdb_drop($dbA);

// ═══ ::strict_modes ═════════════════════════════════════════

foreach (['ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES', ''] as $mode) {
    [$db, $p] = p244_at43();
    pipe2_sql_mode($p, $mode);
    $err = p244_try(fn() => migrate_pipeline2_44($p));
    t_eq($err, null, "::strict_modes com sql_mode «{$mode}» migra sem exceção");
    t_true(db_has_column($p, 'stage_automations', 'action') && db_has_column($p, 'tasks', 'gate_stage_id') && db_has_table($p, 'pipe2_notices'),
        "::strict_modes com sql_mode «{$mode}» as colunas e a tabela existem");
    $p = null;
    pipe2_tmpdb_drop($db);
}

// ═══ ::from_42_and_43 (run_migrations, num subprocesso) ═════

t_eq(SCHEMA_VERSION, 44, '::from_42_and_43 SCHEMA_VERSION é 44');
[$db42, $p42] = p244_fresh();
$r = pipe2_sub('pipe2_v1_snap.php', ['--migrate', '--db=' . $db42]);
t_eq($r['json'], ['from' => 42, 'to' => SCHEMA_VERSION], '::from_42_and_43 da 42: {from: 42, to: SCHEMA_VERSION}' . ($r['code'] ? ' — ' . trim($r['err']) : ''));
t_eq(p2v_col($p42, "SELECT svalue FROM settings WHERE skey = 'schema_version'"), (string)SCHEMA_VERSION, '::from_42_and_43 da 42: schema_version gravada');
t_true(db_has_column($p42, 'stage_automations', 'trig_n') && db_has_column($p42, 'tasks', 'gate_stage_id') && db_has_table($p42, 'pipe2_notices'),
    '::from_42_and_43 da 42: o run_migrations corre o passo 44');
t_true(db_has_table($p42, 'deal_line_steps') && (int)p2v_col($p42, "SELECT COUNT(*) FROM settings WHERE skey = 'pipe2_seeded'") === 1,
    '::from_42_and_43 da 42: e o 43 antes dele');
$p42 = null;
pipe2_tmpdb_drop($db42);

[$db43, $p43] = p244_at43();
$p43->exec("UPDATE settings SET svalue = '43' WHERE skey = 'schema_version'");
$r = pipe2_sub('pipe2_v1_snap.php', ['--migrate', '--db=' . $db43]);
t_eq($r['json'], ['from' => 43, 'to' => SCHEMA_VERSION], '::from_42_and_43 da 43: {from: 43, to: SCHEMA_VERSION}' . ($r['code'] ? ' — ' . trim($r['err']) : ''));
t_eq(p2v_col($p43, "SELECT svalue FROM settings WHERE skey = 'schema_version'"), (string)SCHEMA_VERSION, '::from_42_and_43 da 43: schema_version gravada');
t_true(db_has_column($p43, 'stage_automations', 'action') && db_has_table($p43, 'pipe2_notices'), '::from_42_and_43 da 43: o run_migrations corre o passo 44');
$p43 = null;
pipe2_tmpdb_drop($db43);

t_done();
