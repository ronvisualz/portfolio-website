<?php
/**
 * Fotografia do modo v1 (Pipeline v2, fase 0): o que os leitores PHP e os ecrãs da v1 mostram, para provar que a
 * migração não muda a v1. Os valores voláteis ficam de fora (CSRF, contagens, euros, datas).
 *
 *   php tests/pipe2_v1_snap.php --baseline [--force]  linha de base na 42: tests/.pipe2/base42.sql e base42.json
 *   php tests/pipe2_v1_snap.php --php [--db=X]        {meta, php}: só leitura, numa transação desfeita no fim
 *   php tests/pipe2_v1_snap.php --http                {http} da bancada viva (127.0.0.1:8765)
 *   php tests/pipe2_v1_snap.php --migrate --db=X      corre run_migrations na base privada X: {"from":n,"to":m}
 *
 * X é sempre uma base privada (topper_p2_<pid>_<n>, de pipe2_tmpdb_create). A bancada partilhada não se escreve.
 */
ini_set('display_errors', 'stderr');
require __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
// Os filtros da v2 (admin/inc/pipe2.php), se já existirem; numa base na 42 dão o mesmo que a v1.
if (!function_exists('pipe2_funnel_cond') && is_file(__DIR__ . '/../admin/inc/pipe2.php')) {
    try { require_once __DIR__ . '/../admin/inc/pipe2.php'; } catch (Throwable $e) { fwrite(STDERR, 'pipe2.php: ' . $e->getMessage() . "\n"); }
}

// ── PHP ─────────────────────────────────────────────────────

function snap_ids(array $rows): array {
    return array_map(fn($r) => (int)$r['id'], array_values($rows));
}

function snap_int($v): ?int {
    return $v === null ? null : (int)$v;
}

/** A versão gravada na base (sem cache). */
function snap_version(PDO $pdo): int {
    return (int)$pdo->query("SELECT svalue FROM settings WHERE skey = 'schema_version'")->fetchColumn();
}

/** A parte PHP da fotografia: os leitores da v1 sobre esta ligação. */
function snap_php(PDO $pdo): array {
    $active = funnels_active($pdo);
    $fids = array_map(fn($f) => (int)$f['id'], $active);
    $out = [
        'constants' => [
            'LEAD_SERVICES_LIST' => LEAD_SERVICES_LIST,
            'STAGE_KINDS'        => STAGE_KINDS,
            'STAGE_ZONE'         => STAGE_ZONE,
            'FLOW_TRIGGERS'      => array_keys(FLOW_TRIGGERS),
            'XSELL_STATUS'       => array_keys(XSELL_STATUS),
        ],
        'funnels_active' => array_map(fn($f) => [(int)$f['id'], (string)$f['name'], (int)$f['position'], (string)$f['xray_kind']], $active),
    ];
    $cond = function_exists('pipe2_funnel_cond') ? pipe2_funnel_cond($pdo) : '1=1';
    $out['funnel_list'] = array_map(fn($f) => [(int)$f['id'], (string)$f['name']],
        $pdo->query('SELECT id, name FROM funnels WHERE ' . $cond . ' ORDER BY position, name')->fetchAll());

    $out['funnels'] = [];
    foreach ($fids as $fid) {
        $stages = funnel_stages($pdo, $fid);
        $f = [
            'stages' => array_map(fn($s) => [
                'id' => (int)$s['id'], 'name' => (string)$s['name'], 'kind' => (string)$s['kind'], 'position' => (int)$s['position'],
                'role' => (string)$s['role'], 'gate_on' => (int)$s['gate_on'],
                'rounds_included' => snap_int($s['rounds_included']), 'max_days' => snap_int($s['max_days']),
            ], $stages),
            'flow_order' => snap_ids(flow_order($pdo, $fid)),
            'commercial' => snap_ids(commercial_stages($stages)),
            'prod'       => snap_ids(deal_funnel_prod_stages($pdo, $fid)),
            'fixed'      => [],
            'with'       => [],
            'xray_q'     => xray_questions($pdo, $fid),
            'pay_default' => flow_payment_default($pdo, 1000.0, $fid),
            'rules'      => [],
            'match'      => [],
        ];
        foreach (['xray', 'kickoff', 'done'] as $k) $f['fixed'][$k] = funnel_fixed_stage($pdo, $fid, $k);
        foreach (['proposal', 'feedback', 'xray', 'kickoff', 'done'] as $k) $f['with'][$k] = snap_int(flow_stage_with($pdo, $fid, $k)['id'] ?? null);
        foreach ($stages as $s) {
            $sid = (int)$s['id'];
            $f['rules'][$sid] = snap_ids(flow_rules($pdo, $sid, true));
            foreach ($fids as $other) {
                if ($other !== $fid) $f['match'][$sid][$other] = snap_int(stage_match($pdo, $s, $other)['id'] ?? null);
            }
        }
        $out['funnels'][$fid] = $f;
    }

    if (function_exists('flow_tick_rules')) {
        $tick = snap_ids(flow_tick_rules($pdo));
    } else {
        $tick = snap_ids($pdo->query("SELECT a.*, s.funnel_id FROM stage_automations a JOIN funnel_stages s ON s.id = a.stage_id
                                       WHERE a.is_active = 1 AND a.trig IN ('monthly','date')")->fetchAll());
    }
    sort($tick);
    $out['tick_rules'] = $tick;

    $out['deals'] = [];
    $deals = $pdo->query("SELECT * FROM deals WHERE code IS NOT NULL AND phase IN ('pipeline','production','done','lost') ORDER BY id")->fetchAll();
    foreach ($deals as $d) {
        $fid = $d['funnel_id'] ? (int)$d['funnel_id'] : null;
        $out['deals'][(int)$d['id']] = [
            'steps'         => deal_steps($pdo, $d),
            'idx'           => $fid ? flow_index(flow_order($pdo, $fid), (int)$d['stage_id']) : null,
            'multi'         => xsell_is_multi($pdo, $d),
            'close_missing' => xsell_close_missing($pdo, $d),
            'lines'         => array_map(fn($l) => [(int)$l['id'], (int)$l['funnel_id'], (int)$l['is_main'], snap_int($l['stage_id']), (string)$l['status']],
                                         xsell_lines($pdo, (int)$d['id'])),
        ];
    }

    $all = settings($pdo, true);
    $set = ['alert_limits.deal' => alert_limits($pdo)['deal'], 'deal_stale_days' => $all['deal_stale_days'] ?? null];
    foreach ($all as $k => $v) if (str_starts_with($k, 'prob_') || str_starts_with($k, 'pay_terms_')) $set[$k] = $v;
    ksort($set);
    $out['settings'] = $set;

    $out['xsell'] = [
        'packages' => snap_ids(xsell_packages($pdo, true)),
        'rules'    => db_has_table($pdo, 'xsell_rules') ? snap_ids($pdo->query('SELECT id FROM xsell_rules WHERE is_active = 1 ORDER BY id')->fetchAll()) : [],
    ];
    return $out;
}

/** {meta, php}, numa transação só de leitura que se desfaz no fim. */
function snap_php_ro(PDO $pdo): array {
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        return ['meta' => ['schema_version' => snap_version($pdo)], 'php' => snap_php($pdo)];
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

// ── HTTP ────────────────────────────────────────────────────

function snap_get(string $path, int $uid): string {
    $r = tests_http('GET', $path, [], $uid);
    if ($r['code'] !== 200) throw new RuntimeException("GET $path (uid $uid) deu {$r['code']}.");
    return $r['body'];
}

/** Texto visível de uma opção, sem etiquetas, entidades, espaços a mais nem contagens «(n)». */
function snap_text(string $html): string {
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    return trim(preg_replace('/\s*\(\d+\)$/u', '', $t));
}

/** As opções [valor, texto] de um <select> (por name= ou id=); lança se não o encontrar. */
function snap_select(string $html, string $attr, string $page): array {
    if (!preg_match('#<select\b[^>]*\s' . preg_quote($attr, '#') . '[^>]*>(.*?)</select>#s', $html, $m)) throw new RuntimeException("Sem <select $attr> em $page.");
    preg_match_all('#<option\b([^>]*)>(.*?)</option>#s', $m[1], $o, PREG_SET_ORDER);
    return array_map(function ($x) {
        $v = preg_match('/\bvalue="([^"]*)"/', $x[1], $vm) ? html_entity_decode($vm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
        return [$v, snap_text($x[2])];
    }, $o);
}

function snap_pipeline_tabs(string $html): array {
    if (!preg_match('#<div class="tabs-row pipe-tabs">\s*<div class="tabs">(.*?)</div>#s', $html, $m)) throw new RuntimeException('Sem separadores dos funis em pipeline.php.');
    preg_match_all('#href="/admin/pipeline\.php\?f=(\d+)"#', $m[1], $x);
    if (!$x[1]) throw new RuntimeException('Os separadores de pipeline.php estão vazios.');
    return array_map('intval', $x[1]);
}

function snap_pipeline_cols(string $html): array {
    preg_match_all('#<section class="col\b[^"]*" data-stage="(\d+)"#', $html, $x);
    return array_map('intval', $x[1]);
}

/** A parte HTTP da fotografia, na bancada viva. */
function snap_http(): array {
    $out = [];
    foreach ([1 => 'u1', 99 => 'u99', 3 => 'u3'] as $uid => $key) {
        $tabs = snap_pipeline_tabs(snap_get('/admin/pipeline.php', $uid));
        $cols = [];
        foreach ($tabs as $fid) $cols[$fid] = snap_pipeline_cols(snap_get('/admin/pipeline.php?f=' . $fid, $uid));
        $out[$key] = ['pipeline_tabs' => $tabs, 'pipeline_cols' => $cols];
    }

    $funis = snap_get('/admin/funis.php', 1);
    if (!preg_match('#<nav class="tabs" aria-label="Funis">(.*?)</nav>#s', $funis, $m)) throw new RuntimeException('Sem separadores dos funis em funis.php.');
    preg_match_all('#<a href="/admin/funis\.php\?f=(\d+)" class="([^"]*)"#', $m[1], $x, PREG_SET_ORDER);
    if (!$x) throw new RuntimeException('Os separadores de funis.php estão vazios.');
    $tabs = array_map(fn($t) => [(int)$t[1], in_array('muted', preg_split('/\s+/', trim($t[2])), true)], $x);
    $blocks = [];
    foreach ($tabs as [$fid]) {
        preg_match_all('#\bid="st-(\d+)"#', snap_get('/admin/funis.php?f=' . $fid, 1), $s);
        $blocks[$fid] = array_map('intval', $s[1]);
    }
    $rel = snap_get('/admin/relatorios.php', 1);
    $out['admin'] = [
        'funis_tabs'     => $tabs,
        'funis_blocks'   => $blocks,
        'negocios_funil' => snap_select(snap_get('/admin/negocios.php', 1), 'name="funil"', 'negocios.php'),
        'relatorios_fu'  => snap_select($rel, 'name="fu"', 'relatorios.php'),
        'relatorios_sv'  => snap_select($rel, 'name="sv"', 'relatorios.php'),
        'metas_gi'       => snap_select(snap_get('/admin/relatorios.php?tab=metas&gs=funnel', 1), 'name="gi"', 'relatorios.php?tab=metas'),
        'leads_services' => array_column(snap_select(snap_get('/admin/leads.php', 1), 'id="nl-se"', 'leads.php'), 1),
    ];
    $out['u3_funis'] = tests_http('GET', '/admin/funis.php', [], 3)['code'];
    return $out;
}

// ── linha de base ───────────────────────────────────────────

function snap_write(string $path, string $data): void {
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $data) === false) throw new RuntimeException('Não foi possível escrever ' . $tmp);
    chmod($tmp, 0600);
    rename($tmp, $path);
}

function snap_baseline(PDO $pdo, bool $force): void {
    pipe2_lock($pdo);
    $ver = snap_version($pdo);
    if ($ver !== 42 || SCHEMA_VERSION !== 42) {
        throw new RuntimeException("A linha de base só se tira na 42 (base: $ver, SCHEMA_VERSION: " . SCHEMA_VERSION . '). Depois de subir a versão, a linha de base é a que ficou em tests/.pipe2/.');
    }
    $dir = pipe2_dir();
    $sql = $dir . '/base42.sql';
    $json = $dir . '/base42.json';
    if (!$force && (is_file($sql) || is_file($json))) throw new RuntimeException("A linha de base já existe em $dir (use --force para a substituir, ainda na 42).");
    [$code, $err] = pipe2_shell('mariadb-dump --protocol=socket -S ' . escapeshellarg(PIPE2_SOCK) . ' -u root --default-character-set=utf8mb4 --hex-blob'
        . ' --single-transaction --skip-lock-tables topper > ' . escapeshellarg($sql . '.tmp'));
    if ($code !== 0) throw new RuntimeException('mariadb-dump falhou: ' . substr($err, 0, 500));
    chmod($sql . '.tmp', 0600);
    rename($sql . '.tmp', $sql);
    $db = pipe2_tmpdb_create($sql);
    $php = pipe2_sub('pipe2_v1_snap.php', ['--php', '--db=' . $db]);
    if ($php['code'] !== 0 || !isset($php['json']['php'])) throw new RuntimeException('--php falhou: ' . substr($php['err'], 0, 1000));
    if (($php['json']['meta']['schema_version'] ?? null) !== 42) throw new RuntimeException('O dump não está na 42.');
    $out = [
        'meta' => ['schema_version' => 42, 'taken_at' => date('c'), 'commit' => trim((string)shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse HEAD 2>/dev/null'))],
        'php'  => $php['json']['php'],
        'http' => snap_http(),
    ];
    snap_write($json, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n");
    echo "Linha de base v42 gravada em $dir (base42.sql, base42.json).\n";
}

// ── linha de comandos ───────────────────────────────────────

function snap_out(array $v): void {
    echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), "\n";
}

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (!preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) { fwrite(STDERR, "Argumento inválido: $a\n"); exit(1); }
    $opt[$m[1]] = $m[2] ?? true;
}
try {
    $db = isset($opt['db']) ? (string)$opt['db'] : null;
    if ($db !== null && !preg_match(PIPE2_TMPDB_RE, $db)) throw new InvalidArgumentException("--db tem de ser uma base privada topper_p2_<pid>_<n> (não «{$db}»).");
    if (isset($opt['baseline'])) {
        snap_baseline($pdo, isset($opt['force']));
    } elseif (isset($opt['php'])) {
        $p = $db !== null ? pipe2_tmpdb_pdo($db) : $pdo;
        pipe2_use($p);
        snap_out(snap_php_ro($p));
    } elseif (isset($opt['http'])) {
        snap_out(['http' => snap_http()]);
    } elseif (isset($opt['migrate'])) {
        if ($db === null) throw new InvalidArgumentException('--migrate precisa de --db=<base privada>.');
        $p = pipe2_tmpdb_pdo($db);
        pipe2_use($p);
        $from = snap_version($p);
        if ($from < SCHEMA_VERSION) run_migrations($p, $from);
        snap_out(['from' => $from, 'to' => snap_version($p)]);
    } else {
        fwrite(STDERR, "Uso: php tests/pipe2_v1_snap.php --baseline [--force] | --php [--db=X] | --http | --migrate --db=X\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'pipe2_v1_snap: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
exit(0);
