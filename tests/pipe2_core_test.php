<?php
/**
 * Pipeline v2, fase 0 — admin/inc/pipe2.php: bandeira, serviços, códigos e filtros da v1.
 *
 * Corre na bancada `topper` (na versão 42 ou já na 43). Nunca deixa `pipeline_v2_at` gravado: a bandeira só se liga
 * dentro de uma transação desfeita no fim. Os casos da cache, dos erros e dos fragmentos SQL usam bases SQLite em
 * memória, para não mexer na `schema_version` da bancada partilhada.
 *
 * Uso: php tests/pipe2_core_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/../admin/inc/pipe2.php';

/** Uma base SQLite em memória com `settings` (as linhas dadas) e, se pedido, as colunas novas de funis e etapas. */
function p2c_mem(array $settings, bool $v2cols = true, int $errmode = PDO::ERRMODE_EXCEPTION): PDO {
    $p = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => $errmode, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec('CREATE TABLE settings (skey VARCHAR(64) PRIMARY KEY, svalue TEXT NOT NULL)');
    $ins = $p->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)');
    foreach ($settings as $k => $v) $ins->execute([$k, $v]);
    if ($v2cols) {
        $p->exec("CREATE TABLE funnel_stages (id INTEGER PRIMARY KEY, name TEXT, level VARCHAR(8) NOT NULL DEFAULT 'deal', retired_at DATETIME NULL)");
        $p->exec('CREATE TABLE funnels (id INTEGER PRIMARY KEY, name TEXT, v2_only TINYINT NOT NULL DEFAULT 0)');
    } else {
        // como numa base ainda na 42: sem level, retired_at nem v2_only
        $p->exec('CREATE TABLE funnel_stages (id INTEGER PRIMARY KEY, name TEXT)');
        $p->exec('CREATE TABLE funnels (id INTEGER PRIMARY KEY, name TEXT)');
    }
    return $p;
}

function p2c_count(PDO $p, string $sql): int {
    return (int)$p->query($sql)->fetchColumn();
}

// ── serviços e mapa da v1 ───────────────────────────────────

$want = [
    'vfx' => ['name' => 'VFX',           'token' => '--s-vfx', 'v1' => 'vfx'],
    '3d'  => ['name' => '3D',            'token' => '--s-3d',  'v1' => 'cgi'],
    'md'  => ['name' => 'Motion Design', 'token' => '--s-md',  'v1' => null],
    'cmp' => ['name' => 'Composit',      'token' => '--s-cmp', 'v1' => null],
    'ed'  => ['name' => 'Edição',        'token' => '--s-ed',  'v1' => null],
    'adv' => ['name' => 'Adverts',       'token' => '--s-adv', 'v1' => 'adverts'],
    'cam' => ['name' => 'Campanhas',     'token' => '--s-cam', 'v1' => 'campanhas'],
];
$svc = pipe2_services();
t_eq(array_keys($svc), ['vfx', '3d', 'md', 'cmp', 'ed', 'adv', 'cam'], 'serviços: os 7 códigos pela ordem da v2');
foreach ($want as $code => $w) {
    t_eq($svc[$code]['name'] ?? null, $w['name'], "serviço $code: nome");
    t_eq($svc[$code]['token'] ?? null, $w['token'], "serviço $code: token de cor");
    t_true(array_key_exists('v1', $svc[$code] ?? []), "serviço $code: tem a chave v1");
    t_eq($svc[$code]['v1'] ?? null, $w['v1'], "serviço $code: xray_kind do funil base v1");
}
foreach ($svc as $code => $s) {
    foreach (['Blaine', 'Buddy', 'Ads'] as $bad) t_true(!str_contains((string)$s['name'], $bad), "serviço $code: o nome não contém «{$bad}»");
}

t_eq(PIPE2_V1_MAP, ['vfx' => 'vfx', 'cgi' => '3d', 'adverts' => 'adv', 'ads' => 'adv', 'campanhas' => 'cam', 'buddy' => 'cam'],
    'PIPE2_V1_MAP: xray_kind v1 → código v2');
foreach (PIPE2_V1_MAP as $k => $code) t_true(isset($svc[$code]), "PIPE2_V1_MAP[$k] aponta para um serviço que existe");

t_eq(PIPE2_SCHEMA, 43, 'PIPE2_SCHEMA é o passo 43');
t_eq(array_keys(PIPE2_LEVELS), ['deal', 'front'], 'níveis: deal e front');

// ── coerência dos códigos ───────────────────────────────────

t_eq(PIPE2_CODES['funnels.service_code'] ?? null, ['vfx', '3d', 'md', 'cmp', 'ed', 'adv', 'cam'], 'PIPE2_CODES: service_code');
t_eq(PIPE2_CODES['funnels.service_code'] ?? null, array_keys(PIPE2_SERVICES), 'PIPE2_CODES: service_code = chaves de PIPE2_SERVICES');
t_eq(PIPE2_CODES['funnel_stages.level'] ?? null, array_keys(PIPE2_LEVELS), 'PIPE2_CODES: level = chaves de PIPE2_LEVELS');
t_eq(PIPE2_CODES['funnel_stages.role'] ?? null, array_keys(PIPE2_ROLES), 'PIPE2_CODES: role = chaves de PIPE2_ROLES');
t_eq(array_keys(PIPE2_ROLES), ['exec', 'delivery', 'revisions', 'ready'], 'papéis: exec, delivery, revisions, ready');
t_eq(PIPE2_CODES['deal_lines.status'] ?? null, array_keys(PIPE2_LINE_STATUS), 'PIPE2_CODES: estado da frente = chaves de PIPE2_LINE_STATUS');
t_eq(array_keys(PIPE2_LINE_STATUS), ['queued', 'running', 'depwait', 'ready', 'ongoing', 'merged'], 'estados da frente');
t_eq(PIPE2_CODES['stage_automations.trig'] ?? null, array_keys(PIPE2_TRIGGERS), 'PIPE2_CODES: trig = chaves de PIPE2_TRIGGERS');
t_eq(array_keys(PIPE2_TRIGGERS), ['f_enter', 'f_ready', 'all_ready', 'dep_met', 'f_stale', 'rounds', 'fee_day'], 'gatilhos novos');
t_eq(PIPE2_CODES['deals.service_codes'][0] ?? null, implode(',', array_keys(PIPE2_SERVICES)), 'PIPE2_CODES: service_codes com todos os serviços');
t_eq(PIPE2_CODES['deals.service_codes'][0] ?? null, 'vfx,3d,md,cmp,ed,adv,cam', 'PIPE2_CODES: service_codes literal');
// Os estados novos da frente não reaproveitam os do crosselling v1 (D7).
foreach (['planned', 'waiting', 'active', 'done'] as $old) t_true(!isset(PIPE2_LINE_STATUS[$old]), "estado da frente «{$old}» continua a ser só da v1");
foreach (PIPE2_CODES as $col => $codes) {
    t_true(is_string($col) && substr_count($col, '.') === 1, "PIPE2_CODES: «{$col}» é tabela.coluna");
    t_true(is_array($codes) && $codes && array_is_list($codes), "PIPE2_CODES[$col]: lista não vazia");
}
foreach (PIPE2_TRIGGERS as $k => $label) t_true(strlen($k) <= 10, "gatilho $k cabe em stage_automations.trig (10)");
foreach (PIPE2_LINE_STATUS as $k => $label) t_true(strlen($k) <= 10, "estado $k cabe em deal_lines.status (10)");

// ── pipe2_stage_live ────────────────────────────────────────

t_true(pipe2_stage_live([]), 'etapa sem chaves (base na 42) está viva');
t_true(pipe2_stage_live(['level' => 'deal', 'retired_at' => null]), 'etapa de negócio sem retirada está viva');
t_true(pipe2_stage_live(['level' => 'deal', 'retired_at' => '']), 'retired_at vazio conta como não retirada');
t_true(pipe2_stage_live(['id' => 7, 'name' => 'X-Ray', 'kind' => 'xray']), 'etapa v1 sem as colunas novas está viva');
t_true(!pipe2_stage_live(['level' => 'front']), 'sub-etapa (front) não está viva na v1');
t_true(!pipe2_stage_live(['level' => 'front', 'retired_at' => null]), 'sub-etapa sem retirada continua escondida');
t_true(!pipe2_stage_live(['level' => 'deal', 'retired_at' => '2026-11-06 00:00:00']), 'etapa retirada não está viva');

// ── pipe2_funnel_hidden sem bandeira ────────────────────────

t_true(pipe2_funnel_hidden($pdo, ['v2_only' => 1]), 'funil só da v2 está escondido sem a bandeira');
t_true(pipe2_funnel_hidden($pdo, ['v2_only' => '1']), 'v2_only «1» (texto, como vem do PDO) também esconde');
t_true(!pipe2_funnel_hidden($pdo, ['v2_only' => 0]), 'funil v1 não está escondido');
t_true(!pipe2_funnel_hidden($pdo, []), 'funil sem v2_only (base na 42) não está escondido');
t_true(!pipe2_funnel_hidden($pdo, null), 'funil inexistente (null) não está «escondido»');

// ── bandeira na bancada (transação desfeita) ────────────────

// «pronta» = a semente da v2 correu (pipe2_seeded), nunca o número da versão: o main pode ganhar outro passo 43
$ready = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE skey = 'pipe2_seeded'")->fetchColumn() > 0;
t_eq((int)$pdo->query("SELECT COUNT(*) FROM settings WHERE skey = 'pipeline_v2_at'")->fetchColumn(), 0,
    'a bancada não tem pipeline_v2_at (pré-condição)');
t_true(pipe2_on($pdo) === false, 'sem pipeline_v2_at, pipe2_on é falso');

$stageWant = $ready ? "s.level = 'deal' AND s.retired_at IS NULL" : '1=1';
try {
    $pdo->beginTransaction();
    $set = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $set->execute(['pipeline_v2_at', '2026-11-06 10:00:00']);
    t_true(pipe2_on($pdo) === true, 'com pipeline_v2_at preenchido, pipe2_on é verdadeiro (sem cache do falso anterior)');
    $set->execute(['pipeline_v2_at', '  ']);
    t_true(pipe2_on($pdo) === false, 'pipeline_v2_at só com espaços conta como vazio');
    $set->execute(['pipeline_v2_at', '']);
    t_true(pipe2_on($pdo) === false, 'pipeline_v2_at vazio conta como desligado');
    $set->execute(['pipeline_v2_at', '2026-11-06 10:00:00']);
    t_true(pipe2_on($pdo) === true, 'de novo preenchido, volta a verdadeiro');
    t_true(pipe2_funnel_hidden($pdo, ['v2_only' => 1]) === false, 'com a bandeira, o funil só da v2 deixa de estar escondido');
    t_eq(pipe2_funnel_cond($pdo, 'f'), '1=1', 'com a bandeira, o filtro de funis não esconde nada');
    t_eq(pipe2_stage_cond($pdo, 's'), $stageWant, 'o filtro de etapas não depende da bandeira');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
t_true(pipe2_on($pdo) === false, 'depois do rollBack, pipe2_on volta a falso');
t_true(pipe2_funnel_hidden($pdo, ['v2_only' => 1]), 'depois do rollBack, o funil só da v2 volta a estar escondido');

// ── fragmentos na bancada (42 ou 43) ────────────────────────

t_true(pipe2_schema_ready($pdo) === $ready, 'pipe2_schema_ready segue pipe2_seeded na bancada (' . ($ready ? 'semeada' : 'por semear') . ')');
t_eq(pipe2_stage_cond($pdo, 's'), $stageWant, "stage_cond com o alias 's'");
t_eq(pipe2_stage_cond($pdo, 's.'), $stageWant, "stage_cond com o alias 's.'");
t_eq(pipe2_stage_cond($pdo), $ready ? "level = 'deal' AND retired_at IS NULL" : '1=1', 'stage_cond sem alias');
t_eq(pipe2_funnel_cond($pdo, 'f'), $ready ? 'f.v2_only = 0' : '1=1', "funnel_cond com o alias 'f'");
// O fragmento corre de facto na bancada, com ou sem as colunas novas.
$n = (int)$pdo->query('SELECT COUNT(*) FROM funnel_stages s WHERE ' . pipe2_stage_cond($pdo, 's'))->fetchColumn();
t_true($n > 0, 'o fragmento das etapas é SQL válido na bancada e deixa as etapas da v1');
$n = (int)$pdo->query('SELECT COUNT(*) FROM funnels f WHERE ' . pipe2_funnel_cond($pdo, 'f'))->fetchColumn();
t_true($n > 0, 'o fragmento dos funis é SQL válido na bancada e deixa os funis da v1');

// ── base pronta (43) em memória: o filtro filtra de facto ───

$m = p2c_mem(['schema_version' => '43', 'pipe2_seeded' => '2026-11-06 00:00:00']);
$m->exec("INSERT INTO funnel_stages (name, level, retired_at) VALUES ('viva', 'deal', NULL), ('sub', 'front', NULL), ('retirada', 'deal', '2026-11-06 00:00:00'), ('sub retirada', 'front', '2026-11-06 00:00:00')");
$m->exec("INSERT INTO funnels (name, v2_only) VALUES ('v1', 0), ('só v2', 1)");
t_true(pipe2_schema_ready($m), 'com pipe2_seeded está pronta');
t_true(!pipe2_on($m), 'sem a chave pipeline_v2_at, desligado');
t_eq(pipe2_stage_cond($m, 's'), "s.level = 'deal' AND s.retired_at IS NULL", 'base pronta: fragmento das etapas com alias');
t_eq(pipe2_stage_cond($m), "level = 'deal' AND retired_at IS NULL", 'base pronta: fragmento das etapas sem alias');
t_eq(pipe2_funnel_cond($m, 'f'), 'f.v2_only = 0', 'base pronta e sem bandeira: esconde os funis só da v2');
t_eq(pipe2_funnel_cond($m, 'f.'), 'f.v2_only = 0', "funnel_cond com o alias 'f.'");
t_eq(pipe2_funnel_cond($m), 'v2_only = 0', 'funnel_cond sem alias');
t_eq(p2c_count($m, 'SELECT COUNT(*) FROM funnel_stages s WHERE ' . pipe2_stage_cond($m, 's')), 1, 'só a etapa de negócio não retirada passa o filtro');
t_eq(p2c_count($m, 'SELECT COUNT(*) FROM funnel_stages WHERE ' . pipe2_stage_cond($m)), 1, 'idem sem alias');
t_eq(p2c_count($m, 'SELECT COUNT(*) FROM funnels f WHERE ' . pipe2_funnel_cond($m, 'f')), 1, 'o funil só da v2 não passa o filtro');
$m->exec("INSERT INTO settings (skey, svalue) VALUES ('pipeline_v2_at', '2026-11-06 10:00:00')");
t_true(pipe2_on($m), 'chave preenchida: ligado');
t_eq(pipe2_funnel_cond($m, 'f'), '1=1', 'base pronta e bandeira ligada: os funis só da v2 aparecem');
t_eq(p2c_count($m, 'SELECT COUNT(*) FROM funnels f WHERE ' . pipe2_funnel_cond($m, 'f')), 2, 'com a bandeira, os dois funis passam');
t_eq(pipe2_stage_cond($m, 's'), "s.level = 'deal' AND s.retired_at IS NULL", 'com a bandeira, o filtro das etapas mantém-se');
unset($m);

// ── base ainda na 42 (os leitores publicam-se antes da migração) ──

$m42 = p2c_mem(['schema_version' => '42'], false);
$m42->exec("INSERT INTO funnel_stages (name) VALUES ('a'), ('b')");
$m42->exec("INSERT INTO funnels (name) VALUES ('VFX')");
t_true(!pipe2_schema_ready($m42), 'schema_version 42 não está pronta');
t_eq(pipe2_stage_cond($m42, 's'), '1=1', 'base na 42: o fragmento das etapas não filtra');
t_eq(pipe2_funnel_cond($m42, 'f'), '1=1', 'base na 42: o fragmento dos funis não filtra');
t_eq(p2c_count($m42, 'SELECT COUNT(*) FROM funnel_stages s WHERE ' . pipe2_stage_cond($m42, 's')), 2, 'base na 42: sem «Unknown column», todas as etapas');
t_eq(p2c_count($m42, 'SELECT COUNT(*) FROM funnels f WHERE ' . pipe2_funnel_cond($m42, 'f')), 1, 'base na 42: sem «Unknown column», todos os funis');
$m42->exec("INSERT INTO settings (skey, svalue) VALUES ('pipeline_v2_at', '2026-11-06 10:00:00')");
t_eq(pipe2_funnel_cond($m42, 'f'), '1=1', 'base na 42 com a bandeira: continua 1=1');
t_true(!pipe2_schema_ready(p2c_mem([], false)), 'sem schema_version: não está pronta');
// o main ganhou outro passo 43 antes da publicação (a base vai na 44) mas a migração da v2 ainda não chegou:
// sem pipe2_seeded não está pronta e os fragmentos não pedem colunas que não existem
$m44 = p2c_mem(['schema_version' => '44'], false);
$m44->exec("INSERT INTO funnel_stages (name) VALUES ('a')");
$m44->exec("INSERT INTO funnels (name) VALUES ('VFX')");
t_true(!pipe2_schema_ready($m44), 'base na 44 sem pipe2_seeded: não está pronta');
t_eq(p2c_count($m44, 'SELECT COUNT(*) FROM funnel_stages s WHERE ' . pipe2_stage_cond($m44, 's')), 1, 'base na 44 sem pipe2_seeded: sem «Unknown column»');
t_eq(p2c_count($m44, 'SELECT COUNT(*) FROM funnels f WHERE ' . pipe2_funnel_cond($m44, 'f')), 1, 'base na 44 sem pipe2_seeded: funis sem «Unknown column»');
unset($m44);
unset($m42);

// ── cache de pipe2_schema_ready: por ligação e só do true ──

$c = p2c_mem(['schema_version' => '42']);
t_true(!pipe2_schema_ready($c), 'cache: começa por semear, não está pronta');
$c->exec("INSERT INTO settings (skey, svalue) VALUES ('pipe2_seeded', '2026-11-06 00:00:00')");
t_true(pipe2_schema_ready($c), 'cache: o falso não fica guardado — depois da semente, a mesma ligação está pronta');
t_eq(pipe2_stage_cond($c, 's'), "s.level = 'deal' AND s.retired_at IS NULL", 'cache: o fragmento passa a filtrar na mesma ligação');
$c->exec("DELETE FROM settings WHERE skey = 'pipe2_seeded'");
t_true(pipe2_schema_ready($c), 'cache: o verdadeiro fica guardado para esta ligação');
$other = p2c_mem(['schema_version' => '42']);
t_true(!pipe2_schema_ready($other), 'cache: outra ligação não herda o verdadeiro');
unset($other);

// Uma ligação nova pode receber o mesmo spl_object_id de uma que já morreu: não pode herdar o verdadeiro dela.
$a = p2c_mem(['schema_version' => '43', 'pipe2_seeded' => '2026-11-06 00:00:00']);
t_true(pipe2_schema_ready($a), 'reutilização do id: a primeira ligação está pronta');
$idA = spl_object_id($a);
unset($a);
$b = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
t_eq(spl_object_id($b), $idA, 'reutilização do id: a ligação nova tem o mesmo spl_object_id (pré-condição do caso)');
$b->exec('CREATE TABLE settings (skey VARCHAR(64) PRIMARY KEY, svalue TEXT NOT NULL)');
$b->exec("INSERT INTO settings (skey, svalue) VALUES ('schema_version', '42')");
t_true(!pipe2_schema_ready($b), 'reutilização do id: a ligação nova, numa base na 42, não está pronta');
t_eq(pipe2_stage_cond($b, 's'), '1=1', 'reutilização do id: o fragmento da ligação nova não filtra');
unset($b);

// ── erros dão o comportamento da v1 ─────────────────────────

foreach ([PDO::ERRMODE_EXCEPTION => 'exceções', PDO::ERRMODE_SILENT => 'silencioso'] as $mode => $label) {
    $e = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => $mode]);   // sem tabela settings
    t_true(pipe2_on($e) === false, "erro ($label): pipe2_on é falso");
    t_true(pipe2_schema_ready($e) === false, "erro ($label): pipe2_schema_ready é falso");
    t_eq(pipe2_stage_cond($e, 's'), '1=1', "erro ($label): fragmento das etapas 1=1");
    t_eq(pipe2_funnel_cond($e, 'f'), '1=1', "erro ($label): fragmento dos funis 1=1");
    t_true(pipe2_funnel_hidden($e, ['v2_only' => 1]), "erro ($label): sem bandeira legível, o funil só da v2 fica escondido");
    unset($e);
}

// ── o ficheiro sozinho: guarda e nenhum include ─────────────

$file = realpath(__DIR__ . '/../admin/inc/pipe2.php');
$php = escapeshellarg(PHP_BINARY);
$out = shell_exec($php . ' -r ' . escapeshellarg('require ' . var_export($file, true) . '; echo "CARREGOU";') . ' 2>&1');
t_true(!str_contains((string)$out, 'CARREGOU'), 'sem TOPPER_ADMIN, o ficheiro sai antes de definir o que quer que seja');
$out = shell_exec($php . ' -r ' . escapeshellarg('define("TOPPER_ADMIN", true); require ' . var_export($file, true)
    . '; $p = new PDO("sqlite::memory:"); echo json_encode([count(get_included_files()), pipe2_on($p), pipe2_stage_cond($p, "s")]);') . ' 2>&1');
t_eq(json_decode((string)$out, true), [1, false, '1=1'], 'com TOPPER_ADMIN, carrega sozinho, sem incluir nenhum ficheiro (saída: ' . trim((string)$out) . ')');

// ── a bancada fica como estava ──────────────────────────────

$dsn = (IS_LOCAL && !empty($CONFIG['db_dsn'])) ? $CONFIG['db_dsn'] : "mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4";
$chk = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
t_eq((int)$chk->query("SELECT COUNT(*) FROM settings WHERE skey = 'pipeline_v2_at'")->fetchColumn(), 0,
    'no fim, a bancada continua sem pipeline_v2_at (vista por outra ligação)');

t_done();
