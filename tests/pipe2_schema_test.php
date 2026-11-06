<?php
/**
 * Pipeline v2, fase 0 — passo 43 (admin/inc/migrate_pipeline2.php): esquema, semente e garantias da migração.
 *
 * Corre sempre sobre bases privadas novas, restauradas da linha de base v42 (tests/.pipe2/base42.sql): a bancada
 * partilhada não se escreve. «Fresca» = pipe2_tmpdb_create + pipe2_tmpdb_pdo; «migrar» = migrate_pipeline2_43.
 * Os valores esperados estão escritos aqui à letra (plano da fase 0, secções «Esquema» e «Semente»), e não lidos das
 * constantes da migração: o teste também apanha uma gralha nas constantes.
 *
 * Casos: ::columns ::existing_untouched ::deals_untouched ::new_funnels_inactive ::kept_funnels ::substages
 *        ::departments ::categories_and_models ::no_rules_no_flag ::idempotent ::seed_once ::fault_rolls_back
 *        ::strict_modes ::seed_lookup_edges
 *
 * Uso: php tests/pipe2_schema_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
pipe2_lock($pdo);
require_once __DIR__ . '/../admin/inc/migrate_pipeline2.php';

// ── o que se espera (à letra) ───────────────────────────────

/** Colunas novas em tabelas existentes: [COLUMN_TYPE normalizado, IS_NULLABLE, COLUMN_DEFAULT normalizado]. */
const P2S_COLS = [
    'funnels.service_code'       => ['varchar(8)', 'NO', ''],
    'funnels.v2_only'            => ['tinyint(1)', 'NO', '0'],
    'funnels.department_id'      => ['int unsigned', 'YES', null],
    'funnels.auto_advance'       => ['tinyint(1)', 'NO', '1'],
    'funnel_stages.level'        => ['varchar(8)', 'NO', 'deal'],
    'funnel_stages.retired_at'   => ['datetime', 'YES', null],
    'funnel_stages.auto_advance' => ['tinyint(1)', 'NO', '1'],
    'funnel_stages.plan_days'    => ['smallint', 'YES', null],
    'funnel_stages.unit_label'   => ['varchar(20)', 'NO', ''],
    'deal_lines.billing'         => ['varchar(8)', 'NO', ''],
    'deal_lines.monthly_fee'     => ['decimal(12,2)', 'YES', null],
    'deal_lines.fee_months'      => ['smallint', 'YES', null],
    'deal_lines.billing_end'     => ['date', 'YES', null],
    'deal_lines.cancelled_at'    => ['datetime', 'YES', null],
    'deal_lines.cycle_ym'        => ['varchar(7)', 'NO', ''],
    'deal_lines.merged_into_id'  => ['int unsigned', 'YES', null],
    'deal_lines.exec_mode'       => ['varchar(8)', 'NO', ''],
    'deal_lines.auto_advance'    => ['tinyint(1)', 'YES', null],
    'deals.service_codes'        => ['varchar(40)', 'NO', ''],
    'deals.category'             => ['varchar(8)', 'NO', ''],
    'deals.category_at'          => ['datetime', 'YES', null],
    'deals.billing'              => ['varchar(8)', 'NO', ''],
    'tasks.dedup_key'            => ['varchar(64)', 'NO', ''],
    'tasks.cycle_ym'             => ['varchar(7)', 'NO', ''],
    'deal_rounds.line_id'        => ['int unsigned', 'YES', null],
    'deal_rounds.cycle_ym'       => ['varchar(7)', 'NO', ''],
    'cc_rv_rounds.line_id'       => ['int unsigned', 'YES', null],
    'fin_docs.task_id'           => ['int unsigned', 'YES', null],
    'costs.line_id'              => ['int unsigned', 'YES', null],
];

const P2S_ID = ['int unsigned', 'NO', null];
const P2S_UID_NULL = ['int unsigned', 'YES', null];
const P2S_UID = ['int unsigned', 'NO', null];
const P2S_DT_NULL = ['datetime', 'YES', null];
const P2S_CREATED = ['datetime', 'NO', 'CURRENT_TIMESTAMP'];

/** As 8 tabelas novas, coluna a coluna (pela ordem). */
const P2S_TABLES = [
    'deal_line_steps' => [
        'id' => P2S_ID, 'deal_id' => P2S_UID, 'line_id' => P2S_UID, 'stage_id' => P2S_UID,
        'cycle_ym' => ['varchar(7)', 'NO', ''], 'plan_days' => ['smallint', 'YES', null], 'units_plan' => ['smallint', 'YES', null],
        'units_done' => ['smallint', 'NO', '0'], 'plan_start' => ['date', 'YES', null], 'plan_end' => ['date', 'YES', null],
        'replanned_days' => ['smallint', 'NO', '0'], 'started_at' => P2S_DT_NULL, 'done_at' => P2S_DT_NULL, 'auto_hold_at' => P2S_DT_NULL,
        'auto_hold_why' => ['varchar(8)', 'NO', ''], 'created_at' => P2S_CREATED,
    ],
    'deal_line_execs' => [
        'id' => P2S_ID, 'deal_id' => P2S_UID, 'line_id' => P2S_UID, 'user_id' => P2S_UID_NULL, 'contact_id' => P2S_UID_NULL,
        'budget_line_id' => P2S_UID_NULL, 'created_by' => P2S_UID_NULL, 'position' => ['int', 'NO', '0'], 'created_at' => P2S_CREATED,
    ],
    'deal_line_deps' => [
        'id' => P2S_ID, 'deal_id' => P2S_UID, 'from_line_id' => P2S_UID, 'from_stage_id' => P2S_UID, 'to_line_id' => P2S_UID,
        'to_stage_id' => P2S_UID, 'source' => ['varchar(8)', 'NO', 'model'], 'met_at' => P2S_DT_NULL, 'removed_at' => P2S_DT_NULL,
        'removed_by' => P2S_UID_NULL, 'created_by' => P2S_UID_NULL, 'created_at' => P2S_CREATED,
    ],
    'pipe2_dep_models' => [
        'id' => P2S_ID, 'category' => ['varchar(8)', 'NO', null], 'from_stage_id' => P2S_UID, 'to_stage_id' => P2S_UID,
        'position' => ['int', 'NO', '0'], 'is_active' => ['tinyint(1)', 'NO', '1'], 'updated_at' => P2S_DT_NULL, 'updated_by' => P2S_UID_NULL,
    ],
    'pipe2_categories' => [
        'id' => P2S_ID, 'code' => ['varchar(8)', 'NO', null], 'name' => ['varchar(60)', 'NO', null], 'position' => ['int', 'NO', '0'],
        'need_all' => ['varchar(40)', 'NO', ''], 'need_any' => ['varchar(40)', 'NO', ''], 'exclude_any' => ['varchar(40)', 'NO', ''],
        'need_min' => ['tinyint', 'NO', '0'], 'is_active' => ['tinyint(1)', 'NO', '1'], 'updated_at' => P2S_DT_NULL, 'updated_by' => P2S_UID_NULL,
    ],
    'pipe2_convert_batches' => [
        'id' => P2S_ID, 'status' => ['varchar(10)', 'NO', 'review'], 'backup_key' => ['varchar(255)', 'NO', ''], 'note' => ['text', 'YES', null],
        'created_by' => P2S_UID_NULL, 'created_at' => P2S_CREATED, 'applied_at' => P2S_DT_NULL, 'reverted_at' => P2S_DT_NULL,
    ],
    'pipe2_convert_log' => [
        'id' => P2S_ID, 'batch_id' => P2S_UID, 'op' => ['varchar(8)', 'NO', 'update'], 'tbl' => ['varchar(40)', 'NO', null],
        'row_id' => P2S_UID, 'col' => ['varchar(40)', 'NO', ''], 'old_val' => ['text', 'YES', null], 'new_val' => ['text', 'YES', null],
        'created_at' => P2S_CREATED,
    ],
    'task_deletions' => [
        'id' => P2S_ID, 'task_id' => P2S_UID, 'deal_id' => P2S_UID_NULL, 'line_id' => P2S_UID_NULL, 'stage_id' => P2S_UID_NULL,
        'auto_rule_id' => P2S_UID_NULL, 'deleted_by' => P2S_UID_NULL, 'title' => ['varchar(200)', 'NO', ''],
        'task_created_at' => P2S_DT_NULL, 'deleted_at' => P2S_CREATED,
    ],
];

/** Índices novos: «tabela.índice» => [NON_UNIQUE, colunas]. */
const P2S_INDEXES = [
    'tasks.idx_task_dedup'                 => [1, 'deal_id,dedup_key'],
    'deal_line_steps.PRIMARY'              => [0, 'id'],
    'deal_line_steps.idx_dls_line'         => [1, 'line_id,stage_id'],
    'deal_line_steps.idx_dls_deal'         => [1, 'deal_id'],
    'deal_line_execs.PRIMARY'              => [0, 'id'],
    'deal_line_execs.idx_dle_line'         => [1, 'line_id'],
    'deal_line_execs.idx_dle_contact'      => [1, 'contact_id'],
    'deal_line_deps.PRIMARY'               => [0, 'id'],
    'deal_line_deps.idx_dld_deal'          => [1, 'deal_id'],
    'deal_line_deps.idx_dld_to'            => [1, 'to_line_id'],
    'pipe2_dep_models.PRIMARY'             => [0, 'id'],
    'pipe2_dep_models.idx_p2dm_cat'        => [1, 'category'],
    'pipe2_categories.PRIMARY'             => [0, 'id'],
    'pipe2_convert_batches.PRIMARY'        => [0, 'id'],
    'pipe2_convert_log.PRIMARY'            => [0, 'id'],
    'pipe2_convert_log.idx_p2cl_batch'     => [1, 'batch_id'],
    'pipe2_convert_log.idx_p2cl_row'       => [1, 'tbl,row_id'],
    'task_deletions.PRIMARY'               => [0, 'id'],
    'task_deletions.idx_td_rule'           => [1, 'auto_rule_id'],
];

/** As 7 etapas de negócio dos funis novos: [nome, kind, posição, role]. */
const P2S_DEAL_STAGES = [
    ['X-Ray', 'xray', 10, ''],
    ['Proposta', 'commercial', 20, 'proposal'],
    ['Aguardar feedback', 'commercial', 21, 'feedback'],
    ['Kickoff', 'kickoff', 50, ''],
    ['Em execução', 'production', 70, 'exec'],
    ['Entrega e pagamento final', 'production', 80, 'delivery'],
    ['Concluído', 'done', 90, ''],
];

/** As sub-etapas por código: [as 6 sub-etapas, rondas incluídas em Revisões]. */
const P2S_FRONTS = [
    'vfx' => [['Turnover e breakdown', 'Look dev / shot de referência', 'Produção de shots', 'Finais e QC', 'Revisões', 'Pronto'], 2],
    '3d'  => [['Styleframes', 'Modelação e lookdev', 'Animação', 'Lighting e render', 'Revisões', 'Pronto'], 1],
    'md'  => [['Guião visual e styleframes', 'Animatic', 'Animação', 'Som e ritmo', 'Revisões', 'Pronto'], 1],
    'cmp' => [['Preparação de plates', 'Composição', 'Integração e look', 'Finais e QC', 'Revisões', 'Pronto'], 1],
    'ed'  => [['Seleção e assembly', 'Montagem offline', 'Picture lock', 'Finalização (cor, som, legendas)', 'Revisões', 'Pronto'], 2],
    'adv' => [['Conceito e tratamento', 'Pré-produção (PPM)', 'Rodagem', 'Montagem e pós', 'Revisões', 'Pronto'], 2],
    'cam' => [['Estratégia e calendário', 'Guiões do lote', 'Gravação do lote', 'Edição do lote', 'Revisões', 'Pronto'], 1],
];

const P2S_NEW_FUNNELS = ['md' => 'Motion Design', 'cmp' => 'Composit', 'ed' => 'Edição'];
const P2S_KEPT = ['vfx' => 'vfx', 'cgi' => '3d', 'adverts' => 'adv', 'campanhas' => 'cam'];   // xray_kind do dump => service_code
const P2S_DEPTS = ['vfx' => 'Pós-Produção e VFX', '3d' => 'Pós-Produção e VFX', 'md' => 'Pós-Produção e VFX', 'cmp' => 'Pós-Produção e VFX',
                   'ed' => 'Pós-Produção e VFX', 'adv' => 'Adverts', 'cam' => 'Campanhas'];
const P2S_CATEGORIES = [
    ['postprod', 'Pós-produção', 1, 'ed', 'vfx,3d,md,cmp', 1, ''],
    ['cgi', 'CGI', 2, '', 'vfx,3d,cmp', 2, 'ed'],
];
/** [categoria, svc de, sub-etapa de, svc para, sub-etapa para, posição na categoria]. */
const P2S_DEP_MODELS = [
    ['postprod', '3d', 'Lighting e render', 'cmp', 'Preparação de plates', 1],
    ['postprod', 'vfx', 'Finais e QC', 'cmp', 'Integração e look', 2],
    ['postprod', 'cmp', 'Composição', 'ed', 'Picture lock', 3],
    ['postprod', 'md', 'Revisões', 'ed', 'Picture lock', 4],
    ['cgi', '3d', 'Modelação e lookdev', 'vfx', 'Look dev / shot de referência', 1],
    ['cgi', '3d', 'Lighting e render', 'cmp', 'Preparação de plates', 2],
    ['cgi', 'vfx', 'Finais e QC', 'cmp', 'Integração e look', 3],
];

// ── ajudas ──────────────────────────────────────────────────

/** Uma base privada nova do dump v42: [nome, ligação]. */
function p2s_fresh(): array {
    $db = pipe2_tmpdb_create(pipe2_base_dump());
    return [$db, pipe2_tmpdb_pdo($db)];
}

function p2s_rows(PDO $p, string $sql, array $args = []): array {
    $st = $p->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function p2s_val(PDO $p, string $sql, array $args = []) {
    $st = $p->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}

/** COLUMN_TYPE sem a largura de visualização dos inteiros (tinyint(1) fica, é o booleano). */
function p2s_type(string $t): string {
    $t = strtolower($t);
    $t = preg_replace('/\b(int|smallint|mediumint|bigint)\(\d+\)/', '$1', $t);
    return preg_replace('/\btinyint\((?!1\))\d+\)/', 'tinyint', $t);
}

/** COLUMN_DEFAULT normalizado: sem aspas (o MariaDB devolve-as), NULL e 'NULL' = null, current_timestamp() = CURRENT_TIMESTAMP. */
function p2s_def($d): ?string {
    if ($d === null || $d === 'NULL') return null;
    $d = trim((string)$d, "'");
    return preg_match('/^current_timestamp(\(\))?$/i', $d) ? 'CURRENT_TIMESTAMP' : $d;
}

/** Retrato de information_schema.columns: «tabela.coluna» => [tipo, nulo, default, colação]. */
function p2s_cols(PDO $p): array {
    $out = [];
    foreach (p2s_rows($p, 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS ty, IS_NULLABLE AS n, COLUMN_DEFAULT AS d, COLLATION_NAME AS co
                             FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $r) {
        $out[$r['t'] . '.' . $r['c']] = [p2s_type((string)$r['ty']), (string)$r['n'], p2s_def($r['d']), $r['co']];
    }
    return $out;
}

/** Retrato dos índices: «tabela.índice» => [NON_UNIQUE, colunas]. */
function p2s_idx(PDO $p): array {
    $out = [];
    foreach (p2s_rows($p, 'SELECT TABLE_NAME AS t, INDEX_NAME AS i, NON_UNIQUE AS nu, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
                             FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE()
                            GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE ORDER BY TABLE_NAME, INDEX_NAME') as $r) {
        $out[$r['t'] . '.' . $r['i']] = [(int)$r['nu'], (string)$r['cols']];
    }
    return $out;
}

/** As colunas de uma tabela, pela ordem. */
function p2s_colnames(PDO $p, string $t): array {
    return array_column(p2s_rows($p, 'SELECT COLUMN_NAME AS c FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$t]), 'c');
}

/** Assinatura de uma tabela: COUNT(*) e SUM(CRC32(CONCAT_WS('|', colunas))) (NULL distinto de ''). */
function p2s_sig(PDO $p, string $t, ?array $cols = null): array {
    $cols ??= p2s_colnames($p, $t);
    $parts = array_map(fn($c) => "COALESCE(CAST(`$c` AS CHAR), '<null>')", $cols);
    $r = $p->query("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|', " . implode(', ', $parts) . "))), 0) AS crc FROM `$t`")->fetch(PDO::FETCH_ASSOC);
    return ['n' => (int)$r['n'], 'crc' => (string)$r['crc']];
}

/** O funil com esse service_code (comparação exata, em PHP), ou null. */
function p2s_funnel(PDO $p, string $code): ?array {
    foreach (p2s_rows($p, "SELECT * FROM funnels WHERE service_code <> '' ORDER BY id") as $f) if ($f['service_code'] === $code) return $f;
    return null;
}

/** As etapas de um funil num nível: [nome, kind, posição, role, rondas]. */
function p2s_stages(PDO $p, int $fid, string $level): array {
    return array_map(fn($s) => [$s['name'], $s['kind'], (int)$s['position'], $s['role'], $s['rounds_included'] === null ? null : (int)$s['rounds_included']],
        p2s_rows($p, 'SELECT name, kind, position, role, rounds_included FROM funnel_stages WHERE funnel_id = ? AND level = ? ORDER BY position, id', [$fid, $level]));
}

/** As sub-etapas esperadas de um código, no formato de p2s_stages. */
function p2s_want_fronts(string $code): array {
    [$names, $rounds] = P2S_FRONTS[$code];
    $out = [];
    foreach ($names as $i => $n) {
        $role = $i === 4 ? 'revisions' : ($i === 5 ? 'ready' : '');
        $out[] = [$n, 'production', 110 + 10 * $i, $role, $i === 4 ? $rounds : null];
    }
    return $out;
}

/** As 7 etapas de negócio esperadas, no formato de p2s_stages. */
function p2s_want_deal(): array {
    return array_map(fn($s) => [$s[0], $s[1], $s[2], $s[3], null], P2S_DEAL_STAGES);
}

/** As ligações de pipe2_dep_models lidas por nome: [categoria, svc, sub-etapa, svc, sub-etapa, posição]. */
function p2s_models(PDO $p): array {
    return array_map(fn($r) => [$r['category'], $r['fa'], $r['na'], $r['fb'], $r['nb'], (int)$r['position']],
        p2s_rows($p, "SELECT m.category, fa.service_code AS fa, sa.name AS na, fb.service_code AS fb, sb.name AS nb, m.position
                        FROM pipe2_dep_models m
                        JOIN funnel_stages sa ON sa.id = m.from_stage_id AND sa.level = 'front'
                        JOIN funnels fa ON fa.id = sa.funnel_id
                        JOIN funnel_stages sb ON sb.id = m.to_stage_id AND sb.level = 'front'
                        JOIN funnels fb ON fb.id = sb.funnel_id
                       ORDER BY m.id"));
}

function p2s_categories(PDO $p): array {
    return array_map(fn($r) => [$r['code'], $r['name'], (int)$r['position'], $r['need_all'], $r['need_any'], (int)$r['need_min'], $r['exclude_any']],
        p2s_rows($p, 'SELECT code, name, position, need_all, need_any, need_min, exclude_any FROM pipe2_categories ORDER BY id'));
}

/** O lock da migração está livre (visto de outra ligação, à mesma base). */
function p2s_lock_free(string $db): bool {
    return (string)p2s_val(pipe2_tmpdb_pdo($db), "SELECT IS_FREE_LOCK(CONCAT('topper_pipe2_', DATABASE()))") === '1';
}

function p2s_setting(PDO $p, string $key): ?string {
    $v = p2s_val($p, 'SELECT svalue FROM settings WHERE skey = ?', [$key]);
    return $v === false ? null : (string)$v;
}

/** ::new_funnels_inactive, também usado pelo ::fault_rolls_back. */
function p2s_check_new_funnels(PDO $p, int $dumpMaxPos, string $case): void {
    $prev = $dumpMaxPos;
    foreach (P2S_NEW_FUNNELS as $code => $name) {
        $rows = array_values(array_filter(p2s_rows($p, "SELECT * FROM funnels WHERE service_code <> ''"), fn($f) => $f['service_code'] === $code));
        t_eq(count($rows), 1, "$case: um só funil com service_code $code");
        $f = $rows[0] ?? null;
        if (!$f) continue;
        t_eq($f['name'], $name, "$case: nome do funil $code");
        t_eq([(int)$f['is_active'], (int)$f['v2_only'], (int)$f['is_base'], $f['xray_kind']], [0, 1, 1, ''], "$case: $code inativo, só da v2, base, sem xray_kind");
        t_true((int)$f['position'] > $prev, "$case: posição de $code ({$f['position']}) depois de $prev");
        $prev = (int)$f['position'];
        t_eq(p2s_stages($p, (int)$f['id'], 'deal'), p2s_want_deal(), "$case: as 7 etapas de negócio de $code");
    }
}

// ═══ base A: o esquema e a semente numa só migração ═════════

[$dbA, $A] = p2s_fresh();
t_eq(p2s_setting($A, 'schema_version'), '42', 'base privada no estado v42');
$colsBefore = p2s_cols($A);
$idxBefore = p2s_idx($A);
$DEALT = ['deals', 'deal_lines', 'tasks', 'stage_automations', 'deal_history', 'stage_events'];
$v1cols = [];
$sigBefore = [];
foreach ($DEALT as $t) { $v1cols[$t] = p2s_colnames($A, $t); $sigBefore[$t] = p2s_sig($A, $t, $v1cols[$t]); }
$dumpFunnels = p2s_rows($A, 'SELECT id, name, position, is_active, is_base, xray_kind FROM funnels ORDER BY id');
$dumpStages = p2s_rows($A, 'SELECT id, funnel_id, name, kind, position, role, gate_on, rounds_included, max_days FROM funnel_stages ORDER BY id');
$dumpMaxPos = (int)p2s_val($A, 'SELECT COALESCE(MAX(position), 0) FROM funnels');
$dumpActive = (int)p2s_val($A, 'SELECT COUNT(*) FROM funnels WHERE is_active = 1');
$dumpRules = (int)p2s_val($A, 'SELECT COUNT(*) FROM stage_automations');
$dumpDepts = p2s_rows($A, 'SELECT id, name FROM departments ORDER BY id');
$dumpStale = p2s_setting($A, 'deal_stale_days');

$err = null;
try { migrate_pipeline2_43($A); } catch (Throwable $e) { $err = $e->getMessage(); }
t_eq($err, null, 'migrar a base A sem exceção');
$colsAfter = p2s_cols($A);
$idxAfter = p2s_idx($A);

// ::columns (M5, M7)
foreach (P2S_COLS as $k => $want) t_eq(array_slice($colsAfter[$k] ?? [], 0, 3), $want, "::columns $k");
foreach (P2S_TABLES as $t => $cols) {
    t_true(db_has_table($A, $t), "::columns a tabela $t existe");
    $got = [];
    foreach ($colsAfter as $k => $v) if (str_starts_with($k, $t . '.')) $got[substr($k, strlen($t) + 1)] = array_slice($v, 0, 3);
    t_eq($got, $cols, "::columns as colunas de $t");
}
foreach (P2S_INDEXES as $k => $want) t_eq($idxAfter[$k] ?? null, $want, "::columns o índice $k");
t_eq((int)p2s_val($A, "SELECT NON_UNIQUE FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND INDEX_NAME = 'idx_task_dedup' LIMIT 1"), 1,
    '::columns idx_task_dedup não é UNIQUE');

// ::existing_untouched
$changed = [];
foreach ($colsBefore as $k => $v) if (($colsAfter[$k] ?? null) !== $v) $changed[] = $k;
t_eq($changed, [], '::existing_untouched nenhuma coluna existente mudou');
$added = array_values(array_diff(array_keys($colsAfter), array_keys($colsBefore)));
$wantAdded = array_keys(P2S_COLS);
foreach (P2S_TABLES as $t => $cols) foreach (array_keys($cols) as $c) $wantAdded[] = $t . '.' . $c;
sort($added); sort($wantAdded);
t_eq($added, $wantAdded, '::existing_untouched só se acrescentaram as colunas e as tabelas do passo 43');
$changedIdx = [];
foreach ($idxBefore as $k => $v) if (($idxAfter[$k] ?? null) !== $v) $changedIdx[] = $k;
t_eq($changedIdx, [], '::existing_untouched os índices existentes ficaram iguais');
$addedIdx = array_values(array_diff(array_keys($idxAfter), array_keys($idxBefore)));
$wantIdx = array_keys(P2S_INDEXES);
sort($addedIdx); sort($wantIdx);
t_eq($addedIdx, $wantIdx, '::existing_untouched só se acrescentaram os índices do passo 43');

// ::deals_untouched
foreach ($DEALT as $t) t_eq(p2s_sig($A, $t, $v1cols[$t]), $sigBefore[$t], "::deals_untouched $t igual (COUNT e CRC32 das colunas v1)");

// ::new_funnels_inactive (M6)
p2s_check_new_funnels($A, $dumpMaxPos, '::new_funnels_inactive');

// ::kept_funnels (M6)
$afterFunnels = [];
foreach (p2s_rows($A, 'SELECT id, name, position, is_active, is_base, xray_kind, service_code FROM funnels ORDER BY id') as $f) $afterFunnels[(int)$f['id']] = $f;
foreach ($dumpFunnels as $f) {
    $a = $afterFunnels[(int)$f['id']] ?? [];
    t_eq([$a['name'] ?? null, $a['position'] ?? null, $a['is_active'] ?? null, $a['is_base'] ?? null, $a['xray_kind'] ?? null],
        [$f['name'], $f['position'], $f['is_active'], $f['is_base'], $f['xray_kind']], "::kept_funnels funil {$f['id']} ({$f['xray_kind']}) igual ao dump");
    $want = P2S_KEPT[$f['xray_kind']] ?? '';
    t_eq($a['service_code'] ?? null, $want, "::kept_funnels service_code do funil {$f['xray_kind']}");
}
foreach (P2S_KEPT as $xk => $code) t_eq(count(array_filter($dumpFunnels, fn($f) => $f['xray_kind'] === $xk)), 1, "::kept_funnels o dump tem um funil $xk");
t_eq((int)p2s_val($A, 'SELECT COUNT(*) FROM funnels WHERE is_active = 1'), $dumpActive, '::kept_funnels COUNT(*) dos funis ativos não muda');
$stagesNow = [];
foreach (p2s_rows($A, 'SELECT id, funnel_id, name, kind, position, role, gate_on, rounds_included, max_days, level, retired_at FROM funnel_stages ORDER BY id') as $s) $stagesNow[(int)$s['id']] = $s;
$diffStages = [];
foreach ($dumpStages as $s) {
    $n = $stagesNow[(int)$s['id']] ?? null;
    if ($n === null || array_intersect_key($n, $s) !== $s || $n['level'] !== 'deal' || $n['retired_at'] !== null) $diffStages[] = (int)$s['id'];
}
t_eq($diffStages, [], '::kept_funnels as etapas do dump ficam iguais, level deal e sem retired_at');
foreach ($dumpFunnels as $f) {
    $dealNow = array_values(array_filter($stagesNow, fn($s) => (int)$s['funnel_id'] === (int)$f['id'] && $s['level'] === 'deal'));
    $dealDump = array_values(array_filter($dumpStages, fn($s) => (int)$s['funnel_id'] === (int)$f['id']));
    t_eq(array_column($dealNow, 'id'), array_column($dealDump, 'id'), "::kept_funnels funil {$f['id']}: as etapas level deal são as do dump");
}

// ::substages
foreach (array_keys(P2S_FRONTS) as $code) {
    $f = p2s_funnel($A, $code);
    t_true($f !== null, "::substages há funil $code");
    if ($f) t_eq(p2s_stages($A, (int)$f['id'], 'front'), p2s_want_fronts($code), "::substages as 6 sub-etapas de $code");
}
t_eq((int)p2s_val($A, "SELECT COUNT(*) FROM funnel_stages WHERE level = 'front'"), 42, '::substages 42 sub-etapas no total');
t_eq((int)p2s_val($A, "SELECT COUNT(*) FROM funnel_stages s JOIN funnels f ON f.id = s.funnel_id WHERE s.level = 'front' AND f.service_code = ''"), 0,
    '::substages nenhum funil sem código tem sub-etapas');

// ::departments (M7)
$deptId = function (string $name) use ($dumpDepts): ?int {
    foreach ($dumpDepts as $d) if ($d['name'] === $name) return (int)$d['id'];
    return null;
};
foreach (P2S_DEPTS as $code => $dname) {
    $f = p2s_funnel($A, $code);
    t_true($deptId($dname) !== null, "::departments o dump tem «{$dname}»");
    t_eq($f && $f['department_id'] !== null ? (int)$f['department_id'] : null, $deptId($dname), "::departments $code → «{$dname}»");
}
foreach (['ads', 'buddy'] as $xk) {
    $rows = array_values(array_filter(p2s_rows($A, 'SELECT xray_kind, department_id, service_code FROM funnels'), fn($f) => $f['xray_kind'] === $xk));
    t_eq(array_map(fn($f) => [$f['department_id'], $f['service_code']], $rows), [[null, '']], "::departments $xk sem departamento nem código");
}
t_eq(p2s_rows($A, 'SELECT id, name FROM departments ORDER BY id'), $dumpDepts, '::departments não se criam nem mudam departamentos');

// ::categories_and_models
t_eq(p2s_categories($A), P2S_CATEGORIES, '::categories_and_models as 2 categorias');
t_eq((int)p2s_val($A, 'SELECT COUNT(*) FROM pipe2_categories WHERE is_active = 1'), 2, '::categories_and_models categorias ativas');
t_eq(p2s_models($A), P2S_DEP_MODELS, '::categories_and_models as 7 ligações por nome');
t_eq((int)p2s_val($A, 'SELECT COUNT(*) FROM pipe2_dep_models'), 7, '::categories_and_models 7 linhas, todas resolvidas em sub-etapas');
t_eq((int)p2s_val($A, 'SELECT COUNT(*) FROM pipe2_dep_models WHERE is_active = 1'), 7, '::categories_and_models ligações ativas');

// ::no_rules_no_flag
t_eq((int)p2s_val($A, 'SELECT COUNT(*) FROM stage_automations'), $dumpRules, '::no_rules_no_flag nenhuma regra nova');
t_eq((int)p2s_val($A, "SELECT COUNT(*) FROM stage_automations a JOIN funnel_stages s ON s.id = a.stage_id JOIN funnels f ON f.id = s.funnel_id
                         WHERE f.v2_only = 1 OR s.level <> 'deal'"), 0, '::no_rules_no_flag sem regras em funis só da v2 nem em sub-etapas');
t_eq(p2s_setting($A, 'pipeline_v2_at'), null, '::no_rules_no_flag pipeline_v2_at não existe');
t_eq(pipe2_on($A), false, '::no_rules_no_flag pipe2_on é falso');
t_eq(p2s_setting($A, 'deal_stale_days'), $dumpStale, '::no_rules_no_flag deal_stale_days igual ao dump');
t_true(p2s_setting($A, 'pipe2_seeded') !== null, '::no_rules_no_flag pipe2_seeded existe');
t_eq(p2s_setting($A, 'schema_version'), '42', '::no_rules_no_flag o passo não grava schema_version (é o run_migrations)');
t_true(p2s_lock_free($dbA), '::no_rules_no_flag o lock fica livre');

// ::idempotent (F2)
$SEEDT = ['funnels', 'funnel_stages', 'pipe2_categories', 'pipe2_dep_models'];
$sig1 = [];
foreach ($SEEDT as $t) $sig1[$t] = p2s_sig($A, $t);
$flag1 = p2s_setting($A, 'pipe2_seeded');
$err = null;
try { migrate_pipeline2_43($A); } catch (Throwable $e) { $err = $e->getMessage(); }
t_eq($err, null, '::idempotent 2.ª chamada sem exceção');
t_eq(p2s_cols($A), $colsAfter, '::idempotent 2.ª chamada: information_schema.columns igual');
t_eq(p2s_idx($A), $idxAfter, '::idempotent 2.ª chamada: índices iguais');
foreach ($SEEDT as $t) t_eq(p2s_sig($A, $t), $sig1[$t], "::idempotent 2.ª chamada: $t igual");
t_eq(p2s_setting($A, 'pipe2_seeded'), $flag1, '::idempotent 2.ª chamada: a bandeira fica como estava');
$A->exec("DELETE FROM settings WHERE skey = 'pipe2_seeded'");
$err = null;
try { migrate_pipeline2_43($A); } catch (Throwable $e) { $err = $e->getMessage(); }
t_eq($err, null, '::idempotent sem pipe2_seeded: semente outra vez sem exceção');
foreach ($SEEDT as $t) t_eq(p2s_sig($A, $t), $sig1[$t], "::idempotent sem pipe2_seeded: $t sem duplicados");
t_true(p2s_setting($A, 'pipe2_seeded') !== null, '::idempotent sem pipe2_seeded: a bandeira volta a ser gravada');
$r = pipe2_sub('pipe2_v1_snap.php', ['--migrate', '--db=' . $dbA]);
t_eq($r['code'], 0, '::idempotent run_migrations na base A (subprocesso) sai com 0' . ($r['code'] ? ' — ' . trim($r['err']) : ''));
t_eq($r['json'], ['from' => 42, 'to' => SCHEMA_VERSION], '::idempotent run_migrations da 42 para a SCHEMA_VERSION');
t_eq(p2s_setting($A, 'schema_version'), (string)SCHEMA_VERSION, '::idempotent schema_version = SCHEMA_VERSION');
foreach ($SEEDT as $t) t_eq(p2s_sig($A, $t), $sig1[$t], "::idempotent depois do run_migrations: $t igual");
t_eq((int)p2s_val($A, 'SELECT COUNT(*) FROM stage_automations'), $dumpRules, '::idempotent depois do run_migrations: nenhuma regra nova');
pipe2_tmpdb_drop($dbA);

// ═══ ::seed_once (F2): duas migrações ao mesmo tempo ════════

[$dbS, $S] = p2s_fresh();
$runs = pipe2_sub_parallel([['pipe2_run43.php', ['--db=' . $dbS]], ['pipe2_run43.php', ['--db=' . $dbS]]]);
foreach ($runs as $i => $r) {
    t_eq($r['code'], 0, "::seed_once corrida $i sai com 0" . ($r['code'] ? ' — ' . trim($r['out'] . ' ' . $r['err']) : ''));
    t_eq($r['json'], ['ok' => true], "::seed_once corrida $i imprime ok");
}
foreach (array_keys(P2S_NEW_FUNNELS) as $code) {
    t_eq(count(array_filter(p2s_rows($S, "SELECT service_code FROM funnels WHERE service_code <> ''"), fn($f) => $f['service_code'] === $code)), 1, "::seed_once um só funil $code");
}
t_eq((int)p2s_val($S, "SELECT COUNT(*) FROM funnel_stages WHERE level = 'front'"), 42, '::seed_once 42 sub-etapas');
t_eq((int)p2s_val($S, "SELECT COUNT(*) FROM settings WHERE skey = 'pipe2_seeded'"), 1, '::seed_once uma linha pipe2_seeded');
t_eq((int)p2s_val($S, 'SELECT COUNT(*) FROM pipe2_categories'), 2, '::seed_once 2 categorias');
t_eq((int)p2s_val($S, 'SELECT COUNT(*) FROM pipe2_dep_models'), 7, '::seed_once 7 ligações');
t_true(p2s_lock_free($dbS), '::seed_once o lock fica livre');
pipe2_tmpdb_drop($dbS);

// ═══ ::fault_rolls_back (Review Focus 5) ════════════════════

[$dbF, $F] = p2s_fresh();
$dumpMaxPosF = (int)p2s_val($F, 'SELECT COALESCE(MAX(position), 0) FROM funnels');
migrate_pipeline2_schema($F);   // a tabela pipe2_dep_models tem de existir para o gatilho
$F->exec("CREATE TRIGGER p2t_fail BEFORE INSERT ON pipe2_dep_models FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'p2t'");
$err = null;
try { migrate_pipeline2_43($F); } catch (Throwable $e) { $err = $e->getMessage(); }
t_true($err !== null && str_contains($err, 'p2t'), '::fault_rolls_back a falha a meio da semente chega como exceção' . ($err === null ? ' (não houve exceção)' : ''));
t_eq(p2s_funnel($F, 'md'), null, '::fault_rolls_back não ficou funil md');
$vfx = array_values(array_filter(p2s_rows($F, 'SELECT xray_kind, service_code FROM funnels'), fn($f) => $f['xray_kind'] === 'vfx'));
t_eq(array_column($vfx, 'service_code'), [''], '::fault_rolls_back o VFX ficou sem service_code');
t_eq((int)p2s_val($F, "SELECT COUNT(*) FROM funnel_stages WHERE level = 'front'"), 0, '::fault_rolls_back nenhuma sub-etapa');
t_eq((int)p2s_val($F, 'SELECT COUNT(*) FROM pipe2_categories'), 0, '::fault_rolls_back nenhuma categoria');
t_eq(p2s_setting($F, 'pipe2_seeded'), null, '::fault_rolls_back pipe2_seeded não ficou gravado');
t_true(p2s_lock_free($dbF), '::fault_rolls_back o lock ficou livre');
t_true(!$F->inTransaction(), '::fault_rolls_back a transação foi desfeita');
$F->exec('DROP TRIGGER p2t_fail');
$err = null;
try { migrate_pipeline2_43($F); } catch (Throwable $e) { $err = $e->getMessage(); }
t_eq($err, null, '::fault_rolls_back sem o gatilho, a corrida seguinte semeia');
p2s_check_new_funnels($F, $dumpMaxPosF, '::fault_rolls_back');
t_eq(p2s_models($F), P2S_DEP_MODELS, '::fault_rolls_back as 7 ligações');
t_true(p2s_setting($F, 'pipe2_seeded') !== null, '::fault_rolls_back pipe2_seeded gravado');
pipe2_tmpdb_drop($dbF);

// ═══ ::strict_modes (F3) ════════════════════════════════════

foreach (['ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES', ''] as $mode) {
    $label = $mode === '' ? "sql_mode ''" : "sql_mode $mode";
    [$dbM, $M] = p2s_fresh();
    $keptNames = [];
    foreach (p2s_rows($M, 'SELECT name, xray_kind FROM funnels') as $f) if (isset(P2S_KEPT[$f['xray_kind']])) $keptNames[P2S_KEPT[$f['xray_kind']]] = $f['name'];
    pipe2_sql_mode($M, $mode);
    $err = null;
    try { migrate_pipeline2_43($M); } catch (Throwable $e) { $err = $e->getMessage(); }
    t_eq($err, null, "::strict_modes $label: migrar sem exceção");
    // relido da base: cada nome e código é o pretendido (nada cortado em silêncio)
    foreach (array_keys(P2S_FRONTS) as $code) {
        $f = p2s_funnel($M, $code);
        t_eq($f['service_code'] ?? null, $code, "::strict_modes $label: service_code $code");
        t_eq($f['name'] ?? null, P2S_NEW_FUNNELS[$code] ?? $keptNames[$code] ?? null, "::strict_modes $label: nome do funil $code");
        if ($f) t_eq(p2s_stages($M, (int)$f['id'], 'front'), p2s_want_fronts($code), "::strict_modes $label: sub-etapas de $code");
        if ($f && isset(P2S_NEW_FUNNELS[$code])) t_eq(p2s_stages($M, (int)$f['id'], 'deal'), p2s_want_deal(), "::strict_modes $label: etapas de negócio de $code");
    }
    t_eq(p2s_categories($M), P2S_CATEGORIES, "::strict_modes $label: categorias");
    t_eq(p2s_models($M), P2S_DEP_MODELS, "::strict_modes $label: ligações");
    pipe2_tmpdb_drop($dbM);
}

// ═══ ::seed_lookup_edges (Review Focus 1) ═══════════════════

[$dbE, $E] = p2s_fresh();
$E->exec("UPDATE funnels SET xray_kind = 'cgi,vfx', is_base = 0 WHERE xray_kind = 'cgi'");
$E->exec("INSERT INTO funnels (name, is_base, position, is_active) VALUES ('Edição', 0, 99, 1)");
$teamEd = (int)$E->lastInsertId();
$E->exec("DELETE FROM departments WHERE name = 'Adverts'");
$err = null;
try { migrate_pipeline2_43($E); } catch (Throwable $e) { $err = $e->getMessage(); }
t_eq($err, null, '::seed_lookup_edges migrar sem exceção');
t_eq(p2s_funnel($E, '3d'), null, '::seed_lookup_edges nenhum funil com service_code 3d (o CGI é um combinado)');
t_eq((int)p2s_val($E, "SELECT COUNT(*) FROM funnel_stages WHERE level = 'front' AND name IN ('Styleframes', 'Modelação e lookdev', 'Lighting e render')"), 0,
    '::seed_lookup_edges nenhuma sub-etapa do 3D');
t_eq((int)p2s_val($E, "SELECT COUNT(*) FROM funnel_stages WHERE level = 'front'"), 36, '::seed_lookup_edges 36 sub-etapas (6 serviços)');
$m = p2s_models($E);
t_eq($m, array_values(array_filter(P2S_DEP_MODELS, fn($x) => $x[1] !== '3d' && $x[3] !== '3d')), '::seed_lookup_edges só as ligações sem 3D');
t_eq([count(array_filter($m, fn($x) => $x[0] === 'postprod')), count(array_filter($m, fn($x) => $x[0] === 'cgi'))], [3, 1], '::seed_lookup_edges postprod 3, cgi 1');
$team = p2s_rows($E, 'SELECT name, service_code, is_active, v2_only, is_base, position FROM funnels WHERE id = ?', [$teamEd]);
t_eq($team, [['name' => 'Edição', 'service_code' => '', 'is_active' => 1, 'v2_only' => 0, 'is_base' => 0, 'position' => 99]], '::seed_lookup_edges o funil «Edição» da equipa fica intacto');
$ed = p2s_funnel($E, 'ed');
t_true($ed !== null && (int)$ed['id'] !== $teamEd && (int)$ed['v2_only'] === 1, '::seed_lookup_edges há um funil ed novo, diferente do da equipa, só da v2');
$adv = p2s_funnel($E, 'adv');
t_true($adv !== null, '::seed_lookup_edges o Adverts tem código');
t_eq($adv ? $adv['department_id'] : 'falta', null, '::seed_lookup_edges sem o departamento «Adverts», department_id de adv fica NULL');
$cam = p2s_funnel($E, 'cam');
t_eq($cam ? (int)$cam['department_id'] : null, (int)p2s_val($E, "SELECT id FROM departments WHERE name = 'Campanhas' ORDER BY id LIMIT 1"), '::seed_lookup_edges cam continua com «Campanhas»');
t_eq((int)p2s_val($E, "SELECT COUNT(*) FROM departments WHERE name = 'Adverts'"), 0, '::seed_lookup_edges não se criou o departamento «Adverts»');
pipe2_tmpdb_drop($dbE);

t_done();
