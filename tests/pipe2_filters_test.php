<?php
/**
 * Pipeline v2, fase 0 — os filtros e as guardas da biblioteca (M2, F3; Review Focus 2 e 3).
 *
 * Os leitores da v1 (funnel_stages, funnel_fixed_stage, flow_tick_rules e o que deles herda) nunca mostram as
 * sub-etapas (level 'front') nem as etapas retiradas; as escritas (deal_move, deal_change_funnel, xsell_line_move)
 * recusam-nas. Os leitores e os ecrãs publicam-se antes da migração, por isso também têm de correr numa base na 42.
 *
 * Corre numa base privada restaurada da linha de base v42 (tests/.pipe2/base42.sql): a bancada partilhada não se
 * escreve. A migração faz-se num subprocesso (pipe2_v1_snap.php --migrate), para que as caches estáticas deste
 * processo fiquem limpas; antes de migrar nunca se chama flow_order nem deal_funnel_prod_stages, e depois de injetar
 * cada leitor é chamado aqui pela primeira vez.
 *
 * Casos: ::before_migration_no_error ::ready_not_cached_false ::front_and_retired_hidden
 *        ::writes_refuse_hidden_stages ::strict_readers
 *
 * Uso: php tests/pipe2_filters_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2.php';
pipe2_lock($pdo);

/** Corre $fn; uma exceção conta como falha (com a mensagem) e devolve $fallback. */
function p2f_try(string $what, callable $fn, $fallback = null) {
    try {
        return $fn();
    } catch (Throwable $e) {
        t_true(false, $what . ' lançou ' . get_class($e) . ': ' . $e->getMessage());
        return $fallback;
    }
}

function p2f_ids(array $rows): array {
    return array_map(fn($r) => (int)$r['id'], array_values($rows));
}

/** Os ids de flow_tick_rules, ordenados; null (e uma falha) se a função ainda não existir. */
function p2f_tick(PDO $p): ?array {
    if (!function_exists('flow_tick_rules')) {
        t_true(false, 'flow_tick_rules(PDO) existe em admin/inc/pipeline_flow.php');
        return null;
    }
    $ids = p2f_ids(flow_tick_rules($p));
    sort($ids);
    return $ids;
}

/** $fn tem de lançar RuntimeException com a mensagem $msg. */
function p2f_refuses(string $what, callable $fn, string $msg): void {
    try {
        $fn();
        t_true(false, "$what: devia ser recusado com «{$msg}», mas passou");
    } catch (Throwable $e) {
        t_true($e instanceof RuntimeException && $e->getMessage() === $msg,
            "$what: recusado com «{$msg}» (obtido " . get_class($e) . ': «' . $e->getMessage() . '»)');
    }
}

/** A primeira linha que cumpre $fn, ou null. */
function p2f_first(array $rows, callable $fn): ?array {
    foreach ($rows as $r) if ($fn($r)) return $r;
    return null;
}

function p2f_col(PDO $p, string $sql, array $args = []) {
    $st = $p->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}

$db = pipe2_tmpdb_create(pipe2_base_dump());
$tp = pipe2_tmpdb_pdo($db);
pipe2_use($tp);

// ── ::before_migration_no_error (Review Focus 2) ───────────
// Os leitores e os ecrãs vão para o servidor antes da migração: numa base na 42 não pode haver «Unknown column».

t_eq((int)p2f_col($tp, "SELECT svalue FROM settings WHERE skey = 'schema_version'"), 42, '::before_migration_no_error a base privada está na 42');
t_eq(pipe2_schema_ready($tp), false, '::before_migration_no_error pipe2_schema_ready dá false na 42');

$vfx = (int)p2f_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY position, id LIMIT 1");
$cgi = (int)p2f_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'cgi' AND is_base = 1 ORDER BY position, id LIMIT 1");
t_true($vfx > 0 && $cgi > 0, '::before_migration_no_error o dump tem os funis base VFX e CGI');
$fids = array_map('intval', $tp->query('SELECT id FROM funnels ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

$before = [];
$beforeRows = [];
foreach ($fids as $fid) {
    $beforeRows[$fid] = p2f_try("::before_migration_no_error funnel_stages($fid) na 42", fn() => funnel_stages($tp, $fid), []);
    $before[$fid] = p2f_ids($beforeRows[$fid]);
}
$prodV = array_values(array_filter($beforeRows[$vfx], fn($s) => $s['kind'] === 'production'));
$prodC = array_values(array_filter($beforeRows[$cgi], fn($s) => $s['kind'] === 'production'));
t_true(count($prodV) >= 2 && count($prodC) >= 2, '::before_migration_no_error o VFX e o CGI têm etapas de produção v1');
$propV = (int)(p2f_first($beforeRows[$vfx], fn($s) => $s['role'] === 'proposal')['id'] ?? 0);
$kickV = (int)(p2f_first($beforeRows[$vfx], fn($s) => $s['role'] === '' && $s['kind'] === 'kickoff')['id'] ?? 0);
$propC = (int)(p2f_first($beforeRows[$cgi], fn($s) => $s['role'] === 'proposal')['id'] ?? 0);
t_true($propV > 0 && $kickV > 0 && $propC > 0, '::before_migration_no_error o VFX e o CGI têm Proposta e Kickoff');

$xrayV = p2f_try('::before_migration_no_error funnel_fixed_stage(xray) na 42', fn() => funnel_fixed_stage($tp, $vfx, 'xray'));
$doneV = p2f_try('::before_migration_no_error funnel_fixed_stage(done) na 42', fn() => funnel_fixed_stage($tp, $vfx, 'done'));
t_true($xrayV !== null && $doneV !== null, '::before_migration_no_error funnel_fixed_stage dá o X-Ray e o Concluído do VFX');
$tickBefore = p2f_try('::before_migration_no_error flow_tick_rules na 42', fn() => p2f_tick($tp));

// As peças dos ecrãs da T5, publicados também antes da migração.
t_eq(pipe2_stage_cond($tp, 's'), '1=1', "::before_migration_no_error pipe2_stage_cond(s) dá '1=1' na 42");
t_eq(pipe2_stage_cond($tp), '1=1', "::before_migration_no_error pipe2_stage_cond() dá '1=1' na 42");
t_eq(pipe2_funnel_cond($tp, 'f'), '1=1', "::before_migration_no_error pipe2_funnel_cond(f) dá '1=1' na 42");
t_eq(pipe2_funnel_cond($tp), '1=1', "::before_migration_no_error pipe2_funnel_cond() dá '1=1' na 42");
t_eq(pipe2_funnel_hidden($tp, funnel_get($tp, $vfx)), false, '::before_migration_no_error pipe2_funnel_hidden(VFX) dá false (sem v2_only)');
t_eq(pipe2_stage_live(stage_get($tp, (int)$xrayV)), true, '::before_migration_no_error pipe2_stage_live(X-Ray do VFX) dá true (sem level nem retired_at)');

$sorted = $fids;
sort($sorted);
$ids = p2f_try('::before_migration_no_error a consulta de funis de negocios.php e relatorios.php na 42', function () use ($tp) {
    $v = array_map('intval', array_keys($tp->query('SELECT id, name FROM funnels WHERE ' . pipe2_funnel_cond($tp) . ' ORDER BY position, name')->fetchAll(PDO::FETCH_KEY_PAIR)));
    sort($v);
    return $v;
}, []);
t_eq($ids, $sorted, '::before_migration_no_error a lista de funis de negocios.php e relatorios.php tem todos os funis do dump');
$ids = p2f_try('::before_migration_no_error o $all de funis.php na 42', function () use ($tp) {
    $v = p2f_ids($tp->query('SELECT f.*, (SELECT COUNT(*) FROM deals d WHERE d.funnel_id = f.id AND d.phase IN (\'pipeline\',\'production\')) AS active_deals
                               FROM funnels f WHERE ' . pipe2_funnel_cond($tp, 'f') . ' ORDER BY f.is_active DESC, f.position, f.name')->fetchAll());
    sort($v);
    return $v;
}, []);
t_eq($ids, $sorted, '::before_migration_no_error o $all de funis.php tem todos os funis do dump');
$max = p2f_try('::before_migration_no_error o MAX(position) do add_stage na 42',
    fn() => (int)p2f_col($tp, 'SELECT COALESCE(MAX(position), ?) FROM funnel_stages WHERE funnel_id = ? AND kind = ? AND ' . pipe2_stage_cond($tp), [59, $vfx, 'production']));
t_eq($max, max(array_map(fn($s) => (int)$s['position'], $prodV)), '::before_migration_no_error o MAX(position) do add_stage é o da v1');

// ── migrar, num subprocesso ─────────────────────────────────

$mig = pipe2_sub('pipe2_v1_snap.php', ['--migrate', '--db=' . $db]);
t_true($mig['code'] === 0 && ($mig['json']['from'] ?? null) === 42 && ($mig['json']['to'] ?? 0) >= PIPE2_SCHEMA,
    'a base privada migra da 42 para a ' . PIPE2_SCHEMA . ' num subprocesso (' . trim($mig['out'] . ' ' . substr($mig['err'], 0, 400)) . ')');
if ($mig['code'] !== 0) t_done();

// ── ::ready_not_cached_false (Review Focus 2) ──────────────
// A mesma ligação, que viu a 42, passa a ver o filtro: a cache só guarda o verdadeiro.

t_eq(pipe2_schema_ready($tp), true, '::ready_not_cached_false a mesma ligação vê a base pronta depois de migrar');

// ── injetar etapas retiradas e regras escondidas no VFX ─────

$now = date('Y-m-d H:i:s');
$ins = $tp->prepare('INSERT INTO funnel_stages (funnel_id, name, kind, position, retired_at) VALUES (?, ?, ?, ?, ?)');
$ins->execute([$vfx, 'P2T · produção retirada', 'production', 67, $now]);
$retProd = (int)$tp->lastInsertId();
$ins->execute([$vfx, 'P2T · X-Ray retirado', 'xray', 5, $now]);
$retXray = (int)$tp->lastInsertId();
$vfront = (int)p2f_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND level = 'front' ORDER BY position, id LIMIT 1", [$vfx]);
t_true($vfront > 0, 'a semente deu sub-etapas ao VFX');
$rule = $tp->prepare('INSERT INTO stage_automations (stage_id, title, trig, due_days, is_active) VALUES (?, ?, ?, ?, 1)');
$rule->execute([$vfront, 'P2T · mensal na sub-etapa', 'monthly', 1]);
$ruleFront = (int)$tp->lastInsertId();
$rule->execute([$retProd, 'P2T · por data na etapa retirada', 'date', 0]);
$ruleRet = (int)$tp->lastInsertId();

// ── ::front_and_retired_hidden (M2) ────────────────────────
// Primeira chamada de cada leitor depois de injetar: as caches estáticas ainda estão vazias.

foreach ($fids as $fid) {
    t_eq(p2f_ids(p2f_try("::front_and_retired_hidden funnel_stages($fid)", fn() => funnel_stages($tp, $fid), [])), $before[$fid],
        "::front_and_retired_hidden funnel_stages($fid) igual à da 42");
}
t_eq(p2f_ids(flow_order($tp, $vfx)), $before[$vfx], '::front_and_retired_hidden flow_order(VFX) igual à da 42');
t_eq(p2f_ids(deal_funnel_prod_stages($tp, $vfx)), p2f_ids($prodV), '::front_and_retired_hidden deal_funnel_prod_stages(VFX) só com a produção v1');
t_eq(funnel_fixed_stage($tp, $vfx, 'xray'), $xrayV, '::front_and_retired_hidden funnel_fixed_stage(xray) é o X-Ray original, não o retirado');
t_eq(funnel_fixed_stage($tp, $vfx, 'done'), $doneV, '::front_and_retired_hidden funnel_fixed_stage(done) é o Concluído original');
t_eq((int)(flow_stage_with($tp, $vfx, 'proposal')['id'] ?? 0), $propV, '::front_and_retired_hidden flow_stage_with(proposal) é a Proposta original');
t_eq((int)(flow_stage_with($tp, $vfx, 'kickoff')['id'] ?? 0), $kickV, '::front_and_retired_hidden flow_stage_with(kickoff) é o Kickoff original');
$tick = p2f_tick($tp);
t_true($tick !== null && !in_array($ruleFront, $tick, true) && !in_array($ruleRet, $tick, true),
    '::front_and_retired_hidden flow_tick_rules deixa de fora as regras da sub-etapa e da etapa retirada');
t_eq($tick, $tickBefore, '::front_and_retired_hidden flow_tick_rules igual à da 42');

$steps = deal_steps($tp, ['funnel_id' => $vfx, 'stage_id' => (int)$prodV[0]['id'], 'phase' => 'production', 'prod_step' => null]);
t_eq(array_column($steps['items'], 'label'), array_merge(array_column($prodV, 'name'), ['Concluído']),
    '::front_and_retired_hidden deal_steps mostra só as etapas de produção v1 e Concluído');

$dx = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $vfx, 'stage_id' => (int)$prodV[0]['id'], 'sync_mode' => 'sync']);
pipe2_front($tp, $dx, $vfx, ['status' => 'active', 'stage_id' => (int)end($prodV)['id']]);
pipe2_front($tp, $dx, $cgi, ['status' => 'active', 'stage_id' => (int)end($prodC)['id']]);
$deal = deal_get($tp, $dx);
t_true(xsell_is_multi($tp, $deal), '::front_and_retired_hidden o negócio de teste é de crosselling (duas frentes)');
$falta = array_values(array_filter(xsell_close_missing($tp, $deal), fn($m) => str_contains($m, 'falta chegar a')));
t_eq($falta, [], '::front_and_retired_hidden xsell_close_missing: a «última etapa de produção» continua a ser a da v1');

// ── ::writes_refuse_hidden_stages (Review Focus 3) ─────────
// Um POST forjado com o id de uma sub-etapa ou de uma etapa retirada é recusado e nada muda.

$d = pipe2_deal($tp, ['phase' => 'pipeline', 'funnel_id' => $vfx, 'stage_id' => (int)$xrayV]);
foreach (['sub-etapa' => $vfront, 'etapa de produção retirada' => $retProd, 'X-Ray retirado' => $retXray] as $what => $sid) {
    p2f_refuses("::writes_refuse_hidden_stages deal_move para a $what", fn() => deal_move($tp, deal_get($tp, $d), $sid), 'Etapa inválida.');
    t_eq((int)deal_get($tp, $d)['stage_id'], (int)$xrayV, "::writes_refuse_hidden_stages deal_move para a $what não muda a etapa");
}

$d2 = pipe2_deal($tp, ['phase' => 'pipeline', 'funnel_id' => $cgi, 'stage_id' => $propC]);
foreach (['sub-etapa' => $vfront, 'etapa de produção retirada' => $retProd] as $what => $sid) {
    p2f_refuses("::writes_refuse_hidden_stages deal_change_funnel para a $what", fn() => deal_change_funnel($tp, deal_get($tp, $d2), $vfx, $sid),
        'Escolha a etapa de destino no funil novo.');
    $row = deal_get($tp, $d2);
    t_eq([(int)$row['funnel_id'], (int)$row['stage_id']], [$cgi, $propC], "::writes_refuse_hidden_stages deal_change_funnel para a $what não muda o negócio");
}

$d3 = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $vfx, 'stage_id' => (int)$prodV[0]['id']]);
$ln = pipe2_front($tp, $d3, $vfx, ['status' => 'active', 'stage_id' => (int)$prodV[0]['id']]);
foreach (['sub-etapa' => $vfront, 'etapa de produção retirada' => $retProd] as $what => $sid) {
    p2f_refuses("::writes_refuse_hidden_stages xsell_line_move para a $what",
        fn() => xsell_line_move($tp, deal_get($tp, $d3), xsell_line_get($tp, $ln), $sid), 'Etapa inválida.');
    t_eq((int)xsell_line_get($tp, $ln)['stage_id'], (int)$prodV[0]['id'], "::writes_refuse_hidden_stages xsell_line_move para a $what não muda a frente");
}

// ── ::strict_readers (F3) ──────────────────────────────────
// A produção pode ter ONLY_FULL_GROUP_BY e modo estrito; a bancada não tem.

pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
t_eq(p2f_ids(p2f_try('::strict_readers funnel_stages', fn() => funnel_stages($tp, $vfx), [])), $before[$vfx], '::strict_readers funnel_stages em modo estrito');
t_eq(p2f_try('::strict_readers funnel_fixed_stage', fn() => funnel_fixed_stage($tp, $vfx, 'xray')), $xrayV, '::strict_readers funnel_fixed_stage em modo estrito');
t_eq(p2f_try('::strict_readers flow_tick_rules', fn() => p2f_tick($tp)), $tickBefore, '::strict_readers flow_tick_rules em modo estrito');
t_eq(p2f_ids(p2f_try('::strict_readers commercial_stages', fn() => commercial_stages(funnel_stages($tp, $vfx)), [])),
    p2f_ids(commercial_stages($beforeRows[$vfx])), '::strict_readers commercial_stages(funnel_stages) em modo estrito');

t_done();
