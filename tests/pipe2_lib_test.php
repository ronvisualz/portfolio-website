<?php
// Base de testes do Pipeline v2 (fase 0): papéis da bancada, diff, bases privadas restauradas da linha de base v42,
// negócios, frentes, tarefas e funis de teste, limpeza (com a guarda que nunca apaga funis reais), subprocessos e o lock.
//   php tests/pipe2_lib_test.php
require __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
pipe2_lock($pdo);
$bench = $pdo;   // depois de pipe2_use(), o $pdo global passa a ser o da base privada
$benchVer = fn() => (string)$bench->query("SELECT svalue FROM settings WHERE skey = 'schema_version'")->fetchColumn();
$benchVer0 = $benchVer();

// ── papéis da bancada ───────────────────────────────────────
t_eq(pipe2_users($bench), ['admin' => 1, 'comercial' => 99, 'leitor' => 3], 'papéis da bancada: admin 1, comercial 99, leitor 3');

// ── diff ────────────────────────────────────────────────────
t_eq(pipe2_diff(['a' => 1, 'b' => [1, 2]], ['a' => 1, 'b' => [1, 3]]), ['b.1: 2 ≠ 3'], 'diff: caminho e valores');
t_eq(pipe2_diff([1], [1]), [], 'diff: iguais');
t_eq(pipe2_diff(['a' => ['x' => 'é']], ['a' => []]), ['a.x: "é" ≠ (falta)'], 'diff: chave que falta à direita');
t_eq(pipe2_diff(['a' => 1], ['a' => 1, 'c' => null]), ['c: (falta) ≠ null'], 'diff: chave a mais à direita');
t_eq(pipe2_diff(['n' => 1], ['n' => '1']), ['n: 1 ≠ "1"'], 'diff: tipos diferentes contam');

// ── linha de base ───────────────────────────────────────────
$base = pipe2_base_json();
t_eq($base['meta']['schema_version'] ?? null, 42, 'a linha de base é da versão 42');
t_true(isset($base['php']['funnels_active'], $base['http']['u1']['pipeline_cols']), 'a linha de base tem a parte PHP e a parte HTTP');
t_true(is_file(pipe2_base_dump()) && filesize(pipe2_base_dump()) > 0, 'o dump da linha de base existe');

// ── base privada ────────────────────────────────────────────
settings($bench, true);   // a cache fica com as definições da bancada
$db = pipe2_tmpdb_create(pipe2_base_dump());
t_true((bool)preg_match('/^topper_p2_' . getmypid() . '_\d+$/', $db), 'nome da base privada: topper_p2_<pid>_<n> (' . $db . ')');
$tp = pipe2_tmpdb_pdo($db);
t_eq($tp->query('SELECT DATABASE()')->fetchColumn(), $db, 'a ligação privada aponta para a base privada');
t_eq($tp->query('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()')->fetchColumn(), 'utf8mb4_unicode_ci', 'a base privada é utf8mb4_unicode_ci');
t_true(db_has_table($tp, 'deals'), 'a base privada tem a tabela deals');
$tp->exec("INSERT INTO settings (skey, svalue) VALUES ('p2t_probe', 'privada')");
pipe2_use($tp);
t_eq(setting($tp, 'schema_version'), '42', 'schema_version da base privada é 42');
t_eq(setting($tp, 'p2t_probe'), 'privada', 'pipe2_use refresca a cache das definições');
t_true($GLOBALS['pdo'] === $tp, 'pipe2_use troca o $pdo global');
t_eq((int)$bench->query("SELECT COUNT(*) FROM settings WHERE skey = 'p2t_probe'")->fetchColumn(), 0, 'a bancada não foi tocada');
$tp->exec("DELETE FROM settings WHERE skey = 'p2t_probe'");
settings($tp, true);

$mode0 = (string)$tp->query('SELECT @@SESSION.sql_mode')->fetchColumn();
pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY');
t_eq($tp->query('SELECT @@SESSION.sql_mode')->fetchColumn(), 'ONLY_FULL_GROUP_BY', 'pipe2_sql_mode muda o modo da sessão');
pipe2_sql_mode($tp, $mode0);

/** Assinatura das linhas que não são de teste (a limpeza não as pode mexer). */
$sig = function (PDO $p): array {
    $q = fn(string $sql) => (int)$p->query($sql)->fetchColumn();
    return [
        'deals'         => $q("SELECT COUNT(*) FROM deals WHERE COALESCE(code, '') NOT LIKE 'P2T%' AND name NOT LIKE 'P2T%'"),
        'tasks'         => $q("SELECT COUNT(*) FROM tasks WHERE title NOT LIKE 'P2T%'"),
        'deal_lines'    => $q('SELECT COUNT(*) FROM deal_lines'),
        'funnels'       => $q("SELECT COUNT(*) FROM funnels WHERE name NOT LIKE 'P2T%'"),
        'funnel_stages' => $q("SELECT COUNT(*) FROM funnel_stages s JOIN funnels f ON f.id = s.funnel_id WHERE f.name NOT LIKE 'P2T%'"),
        'automations'   => $q('SELECT COUNT(*) FROM stage_automations'),
    ];
};
$sig0 = $sig($tp);

// ── negócio, frentes e tarefa de teste ──────────────────────
$x1 = funnel_fixed_stage($tp, 1, 'xray');
$d = pipe2_deal($tp, ['phase' => 'pipeline', 'funnel_id' => 1, 'stage_id' => $x1]);
$row = deal_get($tp, $d);
t_true($row && str_starts_with((string)$row['code'], 'P2T') && strlen((string)$row['code']) <= 16, 'pipe2_deal: code começa por P2T e tem até 16 caracteres (' . ($row['code'] ?? '') . ')');
t_true($row && str_starts_with((string)$row['name'], 'P2T · '), 'pipe2_deal: o nome começa por «P2T · »');
t_eq($row ? [$row['phase'], (int)$row['funnel_id'], (int)$row['stage_id']] : null, ['pipeline', 1, $x1], 'pipe2_deal: fase pipeline, funil 1, X-Ray');
t_eq(xsell_is_multi($tp, $row), false, 'sem frentes, não é crosselling');
$l1 = pipe2_front($tp, $d, 1);
$l2 = pipe2_front($tp, $d, 2, ['status' => 'planned']);
$lines = $tp->query('SELECT id, funnel_id, is_main, status FROM deal_lines WHERE deal_id = ' . $d . ' ORDER BY id')->fetchAll();
t_eq(array_map(fn($l) => [(int)$l['id'], (int)$l['funnel_id'], (int)$l['is_main'], $l['status']], $lines),
     [[$l1, 1, 1, 'planned'], [$l2, 2, 0, 'planned']], 'pipe2_front ×2: a do funil do negócio é a principal');
t_eq(xsell_is_multi($tp, $row), true, 'pipe2_front refresca a cache do crosselling');
foreach (['pipe2_deal' => fn() => pipe2_deal($tp, ['xpto' => 1]), 'pipe2_task' => fn() => pipe2_task($tp, $d, ['xpto' => 1])] as $fn => $call) {
    $msg = '';
    try { $call(); } catch (InvalidArgumentException $e) { $msg = $e->getMessage(); }
    t_eq($msg, "$fn: opção desconhecida «xpto».", "$fn recusa uma opção desconhecida");
}
$msg = '';
try { pipe2_deal($tp, ['service_codes' => 'vfx']); } catch (InvalidArgumentException $e) { $msg = $e->getMessage(); }
t_eq($msg, 'pipe2_deal: a coluna deals.service_codes não existe nesta base.', 'pipe2_deal recusa uma coluna v2 numa base na 42');
$t = pipe2_task($tp, $d, ['stage_id' => $x1]);
$trow = $tp->query('SELECT title, status, deal_id, stage_id FROM tasks WHERE id = ' . $t)->fetch();
t_true($trow && str_starts_with($trow['title'], 'P2T · '), 'pipe2_task: título «P2T · …»');
t_eq($trow ? [$trow['status'], (int)$trow['deal_id'], (int)$trow['stage_id']] : null, ['todo', $d, $x1], 'pipe2_task: por fazer, no negócio e na etapa');

// sobras fora dos helpers (o passo 2 da limpeza): um negócio P2T inserido à mão, com frente, tarefa, evento e ronda
$tp->exec("INSERT INTO deals (code, phase, name, funnel_id, stage_id) VALUES ('P2T0000000000000', 'pipeline', 'P2T · sobra', 1, $x1)");
$ds = (int)$tp->lastInsertId();
$now = date('Y-m-d H:i:s');
$tp->exec("INSERT INTO deal_lines (deal_id, funnel_id, is_main, position, created_at) VALUES ($ds, 1, 1, 0, '$now')");
$tp->exec("INSERT INTO tasks (title, deal_id) VALUES ('Tarefa da sobra', $ds)");
$tp->exec("INSERT INTO tasks (title) VALUES ('P2T · sobra sem negócio')");
$tp->exec("INSERT INTO stage_events (deal_id, step, at) VALUES ($ds, 'enter', '$now')");
$tp->exec("INSERT INTO deal_rounds (deal_id, stage_id, created_at) VALUES ($ds, $x1, '$now')");

$f = pipe2_funnel($tp, 'lib');
$fa = pipe2_funnel($tp, 'ativo', true);
$frow = funnel_get($tp, $f);
t_eq($frow ? [$frow['name'], (int)$frow['is_active'], (int)$frow['is_base']] : null, ['P2T · lib', 0, 0], 'pipe2_funnel: «P2T · lib», inativo e fora da base');
t_eq((int)$tp->query('SELECT COUNT(*) FROM funnel_stages WHERE funnel_id = ' . $f)->fetchColumn(), 5, 'pipe2_funnel: 5 etapas (X-Ray, Proposta, Aguardar feedback, Kickoff, Concluído)');
t_eq((int)(funnel_get($tp, $fa)['is_active'] ?? -1), 1, 'pipe2_funnel(…, true): ativo');

pipe2_cleanup($tp);
$left = fn(string $sql) => (int)$tp->query($sql)->fetchColumn();
t_eq($left("SELECT COUNT(*) FROM deals WHERE id IN ($d, $ds)"), 0, 'limpeza: os negócios de teste saem (registado e sobra)');
t_eq($left("SELECT COUNT(*) FROM deal_lines WHERE deal_id IN ($d, $ds)"), 0, 'limpeza: as frentes saem');
t_eq($left("SELECT COUNT(*) FROM tasks WHERE deal_id IN ($d, $ds) OR title LIKE 'P2T%'"), 0, 'limpeza: as tarefas saem');
t_eq($left("SELECT COUNT(*) FROM stage_events WHERE deal_id = $ds") + $left("SELECT COUNT(*) FROM deal_rounds WHERE deal_id = $ds"), 0, 'limpeza: eventos e rondas saem');
t_eq($left("SELECT COUNT(*) FROM funnels WHERE id IN ($f, $fa)") + $left("SELECT COUNT(*) FROM funnel_stages WHERE funnel_id IN ($f, $fa)"), 0, 'limpeza: os funis de teste e as etapas saem');
t_eq($sig($tp), $sig0, 'limpeza: nada que não seja de teste mudou');

// ── a fotografia v1 num subprocesso ─────────────────────────
$r = pipe2_sub('pipe2_v1_snap.php', ['--php', '--db=' . $db]);
t_eq($r['code'], 0, '--php sai com 0 (' . substr($r['err'], 0, 300) . ')');
t_eq(count($r['json']['php']['funnels_active'] ?? []), 6, '--php: 6 funis ativos');
t_eq($r['json']['meta']['schema_version'] ?? null, 42, '--php: a base privada está na 42');
t_eq(pipe2_diff($base['php'], $r['json']['php'] ?? null), [], 'a fotografia de uma base na 42 é igual à linha de base (e a limpeza não deixou rasto)');
t_eq($benchVer(), $benchVer0, 'a fotografia da base privada não mexe na versão da bancada');

$par = pipe2_sub_parallel([['pipe2_v1_snap.php', ['--php', '--db=' . $db]], ['pipe2_v1_snap.php', ['--php', '--db=' . $db]]]);
t_eq([count($par), $par[0]['code'] ?? null, $par[1]['code'] ?? null], [2, 0, 0], 'pipe2_sub_parallel: duas corridas, ambas com 0');
t_true(($par[0]['out'] ?? '') !== '' && $par[0]['out'] === ($par[1]['out'] ?? null), 'pipe2_sub_parallel: duas cópias da mesma corrida dão a mesma saída');

$h = pipe2_sub('pipe2_v1_snap.php', ['--http']);
t_eq($h['code'], 0, '--http sai com 0 (' . substr($h['err'], 0, 300) . ')');
$hh = $h['json']['http'] ?? [];
foreach (['u1', 'u99', 'u3'] as $u) {
    t_true(!empty($hh[$u]['pipeline_tabs']) && array_keys($hh[$u]['pipeline_cols'] ?? []) === $hh[$u]['pipeline_tabs'], "--http: $u tem separadores e as colunas de cada um");
}
t_true(!empty($hh['admin']['funis_tabs']) && !empty($hh['admin']['negocios_funil']) && !empty($hh['admin']['metas_gi']) && !empty($hh['admin']['leads_services']), '--http: as listas do admin');
t_eq($hh['u3_funis'] ?? null, 403, '--http: funis.php dá 403 ao uid 3');

// ── guarda da limpeza, numa 2.ª base privada ────────────────
$db2 = pipe2_tmpdb_create(pipe2_base_dump());
$tp2 = pipe2_tmpdb_pdo($db2);
pipe2_use($tp2);
$nst = fn(int $fid) => (int)$tp2->query('SELECT COUNT(*) FROM funnel_stages WHERE funnel_id = ' . $fid)->fetchColumn();
$has = fn(int $fid) => (int)$tp2->query('SELECT COUNT(*) FROM funnels WHERE id = ' . $fid)->fetchColumn() === 1;

$s1 = $nst(1);
$tp2->exec("UPDATE funnels SET name = 'P2T' WHERE id = 1");
pipe2_cleanup($tp2);
t_true($s1 > 0 && $has(1) && $nst(1) === $s1, 'guarda: o funil 1 renomeado «P2T» fica, com as etapas');

$g = pipe2_funnel($tp2, 'guarda');
$gx = funnel_fixed_stage($tp2, $g, 'xray');
$tp2->prepare("INSERT INTO deals (code, phase, name, funnel_id, stage_id) VALUES ('ZZ2611-99', 'pipeline', 'Negócio verdadeiro', ?, ?)")->execute([$g, $gx]);
$real = (int)$tp2->lastInsertId();
pipe2_cleanup($tp2);
t_true($has($g) && $nst($g) === 5, 'guarda: um funil «P2T · » com um negócio que não é de teste fica');
t_eq((int)$tp2->query('SELECT COUNT(*) FROM deals WHERE id = ' . $real)->fetchColumn(), 1, 'guarda: o negócio que não é de teste fica');
$tp2->exec('DELETE FROM deals WHERE id = ' . $real);
pipe2_cleanup($tp2);
t_true(!$has($g) && $nst($g) === 0, 'guarda: sem o negócio, a limpeza seguinte já apaga o funil');

$s2 = $nst(2);
$tp2->exec("UPDATE funnels SET name = 'P2T · base' WHERE id = 2");
pipe2_cleanup($tp2);
t_true($s2 > 0 && $has(2) && $nst(2) === $s2, 'guarda: o funil 2 (is_base 1) renomeado «P2T · base» fica, com as etapas');

$b1 = pipe2_funnel($tp2, 'base sem negócios');
$tp2->exec("UPDATE funnels SET is_base = 1 WHERE id = $b1");
pipe2_cleanup($tp2);
t_true($has($b1) && $nst($b1) === 5, 'guarda: um funil «P2T · » com is_base 1, mesmo sem negócios, fica');

$fl = pipe2_funnel($tp2, 'frente real');
$tp2->exec("INSERT INTO deals (code, phase, name, funnel_id, stage_id) VALUES ('ZZ2611-98', 'production', 'Outro negócio verdadeiro', 1, " . (int)funnel_fixed_stage($tp2, 1, 'kickoff') . ')');
$real2 = (int)$tp2->lastInsertId();
$tp2->exec("INSERT INTO deal_lines (deal_id, funnel_id, is_main, position, created_at) VALUES ($real2, $fl, 0, 1, '" . date('Y-m-d H:i:s') . "')");
pipe2_cleanup($tp2);
t_true($has($fl) && (int)$tp2->query("SELECT COUNT(*) FROM deal_lines WHERE deal_id = $real2")->fetchColumn() === 1, 'guarda: um funil «P2T · » com a frente de um negócio que não é de teste fica (e a frente também)');

$rn = pipe2_funnel($tp2, 'renomeado');
$tp2->exec("UPDATE funnels SET name = 'P2Tx renomeado' WHERE id = $rn");
$lc = pipe2_funnel($tp2, 'minúsculas');
$tp2->exec("UPDATE funnels SET name = 'p2t · minúsculas' WHERE id = $lc");
pipe2_cleanup($tp2);
t_true($has($rn) && $nst($rn) === 5 && $has($lc) && $nst($lc) === 5, 'guarda: o prefixo é exato («P2T · », com o ponto do meio e em maiúsculas), também nos ids registados');

$tp2->exec("ALTER TABLE funnels ADD COLUMN service_code VARCHAR(8) NOT NULL DEFAULT ''");
$sc = pipe2_funnel($tp2, 'sc');
$tp2->exec("UPDATE funnels SET service_code = 'md' WHERE id = $sc");
pipe2_cleanup($tp2);
t_true($has($sc) && $nst($sc) === 5, 'guarda: um funil «P2T · » com service_code fica');
$tp2->exec("UPDATE funnels SET service_code = '' WHERE id = $sc");
pipe2_cleanup($tp2);
t_true(!$has($sc), 'guarda: sem service_code, já sai');

// ── migrar uma base privada num subprocesso ─────────────────
$db3 = pipe2_tmpdb_create(pipe2_base_dump());
$m = pipe2_sub('pipe2_v1_snap.php', ['--migrate', '--db=' . $db3]);
t_eq($m['code'], 0, '--migrate sai com 0 (' . substr($m['err'], 0, 300) . ')');
t_eq($m['json'], ['from' => 42, 'to' => SCHEMA_VERSION], '--migrate: da 42 para a SCHEMA_VERSION');
$tp3 = pipe2_tmpdb_pdo($db3);
t_eq((int)$tp3->query("SELECT svalue FROM settings WHERE skey = 'schema_version'")->fetchColumn(), SCHEMA_VERSION, '--migrate: a versão ficou gravada na base privada');
$bad = pipe2_sub('pipe2_v1_snap.php', ['--migrate', '--db=topper']);
t_eq($bad['code'], 1, '--migrate recusa uma base que não é privada');
t_true(str_contains($bad['err'], '(não «topper»)') && !str_contains($bad['err'], 'Warning'), '--migrate diz qual base recusou (' . substr($bad['err'], 0, 160) . ')');
t_eq($benchVer(), $benchVer0, '--migrate numa base privada não mexe na versão da bancada');
pipe2_tmpdb_drop($db3);
t_eq((int)$bench->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $bench->quote($db3))->fetchColumn(), 0, 'pipe2_tmpdb_drop apaga a base');

// ── o lock dos testes do Pipeline ───────────────────────────
$other = pipe2_tmpdb_pdo($db);
t_eq((int)$other->query("SELECT GET_LOCK('topper_pipe2_tests', 0)")->fetchColumn(), 0, 'com o lock seguro, outra ligação não o obtém');

pipe2_use($bench);
t_done();
