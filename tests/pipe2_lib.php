<?php
/**
 * Base de testes do Pipeline v2 (incluir depois de tests/boot.php).
 *
 * - Bases privadas «topper_p2_<pid>_<n>», restauradas da linha de base v42 (tests/.pipe2/base42.sql, fora do Git),
 *   ligadas como root pelo socket do MariaDB e apagadas no fim do processo. A bancada partilhada não se escreve.
 * - Subprocessos (migrar e fotografar fazem-se sempre noutro processo, por causa das caches estáticas da v1).
 * - pipe2_diff, os papéis da bancada, negócios/frentes/tarefas/funis de teste (prefixo «P2T») e a limpeza.
 *
 * Limpeza (pipe2_cleanup, corre também no fim do processo):
 *   1. apaga por id o que os helpers registaram nessa ligação;
 *   2. apaga as sobras «P2T%» em tasks, deal_lines, stage_events, deal_rounds e deals;
 *   3. apaga os funis «P2T · %» (prefixo exato; as etapas e as regras vão por CASCADE);
 *   4. em todos os passos, nunca apaga um funil com is_base = 1, com service_code <> '' nem com negócios.
 */

if (!defined('TOPPER_ADMIN')) { fwrite(STDERR, "Inclua tests/boot.php antes de tests/pipe2_lib.php.\n"); exit(1); }

require_once __DIR__ . '/../admin/inc/pipeline.php';
require_once __DIR__ . '/../admin/inc/pipeline_flow.php';
require_once __DIR__ . '/../admin/inc/xsell.php';
require_once __DIR__ . '/../admin/inc/deal_file.php';
require_once __DIR__ . '/../admin/inc/alerts.php';
require_once __DIR__ . '/../admin/inc/leads.php';

const PIPE2_T = 'P2T';                          // prefixo de todos os dados de teste
const PIPE2_FUNNEL_PREFIX = 'P2T · ';           // prefixo dos funis de teste (com o ponto do meio e o espaço)
const PIPE2_SOCK = '/run/mysqld/mysqld.sock';   // MariaDB da bancada (root sem palavra-passe, pelo socket)
const PIPE2_TMPDB_RE = '/^topper_p2_(\d+)_\d+$/';
const PIPE2_PDO_ATTRS = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$GLOBALS['PIPE2_REG'] = $GLOBALS['PIPE2_REG'] ?? [];        // spl_object_id(ligação) => [pdo, db, ids[tabela][]]
$GLOBALS['PIPE2_TMPDBS'] = $GLOBALS['PIPE2_TMPDBS'] ?? [];  // bases privadas criadas por este processo

// ── linha de base ───────────────────────────────────────────

/** O diretório da linha de base (fora do Git); cria-o. */
function pipe2_dir(): string {
    $dir = getenv('PIPE2_BASE_DIR') ?: dirname(__DIR__) . '/tests/.pipe2';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar ' . $dir);
    return $dir;
}

function pipe2_base_missing(string $path): void {
    fwrite(STDERR, "FALHA: falta $path — gere a linha de base na 42 (T1): php tests/pipe2_v1_snap.php --baseline\n");
    exit(1);
}

/** O caminho do dump da linha de base (base42.sql); sem ele, o teste falha com a instrução. */
function pipe2_base_dump(): string {
    $p = pipe2_dir() . '/base42.sql';
    if (!is_file($p) || filesize($p) === 0) pipe2_base_missing($p);
    return $p;
}

/** A fotografia da linha de base (base42.json); sem ela, o teste falha com a instrução. */
function pipe2_base_json(): array {
    $p = pipe2_dir() . '/base42.json';
    if (!is_file($p)) pipe2_base_missing($p);
    $j = json_decode((string)file_get_contents($p), true);
    if (!is_array($j)) { fwrite(STDERR, "FALHA: $p não é JSON válido — gere-a outra vez na 42 com --baseline --force\n"); exit(1); }
    return $j;
}

// ── lock e bases privadas ───────────────────────────────────

/** Um teste do Pipeline de cada vez (o lock fica com a ligação até ao fim do processo). */
function pipe2_lock(PDO $pdo): void {
    $v = $pdo->query("SELECT GET_LOCK('topper_pipe2_tests', 300)")->fetchColumn();
    if ((string)$v !== '1') throw new RuntimeException('Não foi possível obter o lock topper_pipe2_tests (outro teste do Pipeline a correr?).');
}

/** Uma ligação root pelo socket, com os atributos do bootstrap; $db '' = sem base. */
function pipe2_root_connect(string $db = ''): PDO {
    return new PDO('mysql:unix_socket=' . PIPE2_SOCK . ($db !== '' ? ';dbname=' . $db : '') . ';charset=utf8mb4', 'root', '', PIPE2_PDO_ATTRS);
}

function pipe2_root_pdo(): PDO {
    static $root = null;
    return $root ??= pipe2_root_connect();
}

/** Corre um comando da shell; [código, saída]. */
function pipe2_shell(string $cmd): array {
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

/** Apaga as bases privadas que ficaram de processos que já não existem (um teste interrompido). */
function pipe2_tmpdb_sweep(): void {
    foreach (pipe2_root_pdo()->query("SHOW DATABASES LIKE 'topper\\_p2\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $name) {
        if (!preg_match(PIPE2_TMPDB_RE, $name, $m)) continue;
        $pid = (int)$m[1];
        if ($pid !== getmypid() && !file_exists('/proc/' . $pid)) pipe2_tmpdb_drop($name);
    }
}

/** Cria uma base privada a partir de um dump; é apagada no fim do processo. Devolve o nome. */
function pipe2_tmpdb_create(string $dump): string {
    static $n = 0, $swept = false;
    if (!is_file($dump)) throw new RuntimeException('Dump não encontrado: ' . $dump);
    if (!$swept) { $swept = true; pipe2_tmpdb_sweep(); }
    $db = 'topper_p2_' . getmypid() . '_' . (++$n);
    $root = pipe2_root_pdo();
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    $root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $GLOBALS['PIPE2_TMPDBS'][] = $db;
    pipe2_shutdown_once();
    [$code, $out] = pipe2_shell('mariadb --protocol=socket -S ' . escapeshellarg(PIPE2_SOCK) . ' -u root --default-character-set=utf8mb4 '
        . escapeshellarg($db) . ' < ' . escapeshellarg($dump));
    if ($code !== 0) throw new RuntimeException('Não foi possível carregar o dump em ' . $db . ': ' . substr($out, 0, 500));
    return $db;
}

/** Uma ligação nova à base privada (root pelo socket, sem palavra-passe, atributos iguais aos do bootstrap). */
function pipe2_tmpdb_pdo(string $db): PDO {
    if (!preg_match(PIPE2_TMPDB_RE, $db)) throw new InvalidArgumentException('Não é uma base privada: ' . $db);
    return pipe2_root_connect($db);
}

/** Apaga uma base privada (fecha antes as ligações que ainda tenha). Só aceita nomes topper_p2_<pid>_<n>. */
function pipe2_tmpdb_drop(string $db): void {
    if (!preg_match(PIPE2_TMPDB_RE, $db)) throw new InvalidArgumentException('Só se apagam bases privadas topper_p2_…: ' . $db);
    $root = pipe2_root_pdo();
    $st = $root->prepare('SELECT ID FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID()');
    $st->execute([$db]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        try { $root->exec('KILL CONNECTION ' . (int)$id); } catch (Throwable $e) { /* já tinha saído */ }
    }
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    $GLOBALS['PIPE2_TMPDBS'] = array_values(array_diff($GLOBALS['PIPE2_TMPDBS'], [$db]));
    foreach ($GLOBALS['PIPE2_REG'] as $k => $r) if ($r['db'] === $db) unset($GLOBALS['PIPE2_REG'][$k]);
}

/** Esta ligação passa a ser a da plataforma: $pdo global e cache das definições. */
function pipe2_use(PDO $pdo): void {
    $GLOBALS['pdo'] = $pdo;
    settings($pdo, true);
}

function pipe2_sql_mode(PDO $pdo, string $mode): void {
    $pdo->prepare('SET SESSION sql_mode = ?')->execute([$mode]);
}

// ── subprocessos ────────────────────────────────────────────

/** Corre php tests/<script> <args>; ['code', 'out', 'err', 'json']. */
function pipe2_sub(string $script, array $args = []): array {
    return pipe2_sub_parallel([[$script, $args]])[0];
}

/** Lança as corridas [[script, args], …] ao mesmo tempo; os resultados vêm pela mesma ordem. */
function pipe2_sub_parallel(array $runs): array {
    $procs = [];
    foreach (array_values($runs) as $i => $run) {
        [$script, $args] = $run + [1 => []];
        $path = __DIR__ . '/' . $script;
        if (!preg_match('/^[\w\-]+\.php$/', $script) || !is_file($path)) throw new InvalidArgumentException('Script de teste inválido: ' . $script);
        $outF = tempnam(sys_get_temp_dir(), 'p2o');
        $errF = tempnam(sys_get_temp_dir(), 'p2e');
        $cmd = array_merge([PHP_BINARY, $path], array_map('strval', $args));
        $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outF, 'w'], 2 => ['file', $errF, 'w']], $pipes, dirname(__DIR__));
        if (!is_resource($p)) throw new RuntimeException('Não foi possível lançar ' . $script);
        $procs[$i] = [$p, $outF, $errF];
    }
    $res = [];
    foreach ($procs as $i => [$p, $outF, $errF]) {
        $code = proc_close($p);
        $out = (string)file_get_contents($outF);
        $err = (string)file_get_contents($errF);
        @unlink($outF);
        @unlink($errF);
        $res[$i] = ['code' => $code, 'out' => $out, 'err' => $err, 'json' => json_decode($out, true)];
    }
    return $res;
}

// ── comparar ────────────────────────────────────────────────

function pipe2_json($v): string {
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** As diferenças entre $a e $b: ["caminho.a.b: <json a> ≠ <json b>", …]; chave em falta = «(falta)»; [] = iguais. */
function pipe2_diff($a, $b, string $path = ''): array {
    if (is_array($a) && is_array($b)) {
        $out = [];
        foreach (array_keys($a + $b) as $k) {
            $p = $path === '' ? (string)$k : $path . '.' . $k;
            if (!array_key_exists($k, $b)) $out[] = $p . ': ' . pipe2_json($a[$k]) . ' ≠ (falta)';
            elseif (!array_key_exists($k, $a)) $out[] = $p . ': (falta) ≠ ' . pipe2_json($b[$k]);
            else array_push($out, ...pipe2_diff($a[$k], $b[$k], $p));
        }
        return $out;
    }
    return $a === $b ? [] : [($path === '' ? '(raiz)' : $path) . ': ' . pipe2_json($a) . ' ≠ ' . pipe2_json($b)];
}

// ── papéis da bancada ───────────────────────────────────────

/** Os utilizadores de teste por papel, confirmados com perm_grid; lança se a bancada não bater certo. */
function pipe2_users(PDO $pdo): array {
    $want = ['admin' => 1, 'comercial' => 99, 'leitor' => 3];
    $st = $pdo->prepare('SELECT id, name, role, is_active, deactivated_at FROM users WHERE id = ?');
    foreach ($want as $k => $id) {
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u || (int)$u['is_active'] !== 1 || !empty($u['deactivated_at'])) throw new RuntimeException("A bancada não tem o utilizador $id ($k) ativo.");
        $g = perm_grid($pdo, $u);
        $lvl = fn(string $tab) => (int)($g[$tab]['level'] ?? PERM_NONE);
        $ok = match ($k) {
            'admin'     => role_is_admin((string)$u['role']) && $lvl('financeiro') === PERM_MANAGE,
            'comercial' => !role_is_admin((string)$u['role']) && $lvl('pipeline') >= PERM_EDIT && $lvl('negocios') >= PERM_EDIT && $lvl('financeiro') === PERM_NONE,
            'leitor'    => !role_is_admin((string)$u['role']) && $lvl('pipeline') === PERM_VIEW && $lvl('financeiro') === PERM_NONE,
        };
        if (!$ok) throw new RuntimeException("O utilizador {$id} não tem as permissões de «{$k}» na bancada (papel {$u['role']}).");
    }
    return $want;
}

// ── dados de teste ──────────────────────────────────────────

/** Regista um id criado por um helper nesta ligação (a limpeza apaga-o). */
function pipe2_reg(PDO $pdo, string $table, int $id): void {
    $k = spl_object_id($pdo);
    if (!isset($GLOBALS['PIPE2_REG'][$k])) {
        $GLOBALS['PIPE2_REG'][$k] = ['pdo' => $pdo, 'db' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(), 'ids' => []];
    }
    $GLOBALS['PIPE2_REG'][$k]['ids'][$table][] = $id;
    pipe2_shutdown_once();
}

/** Recusa opções desconhecidas e colunas v2 que esta base ainda não tem. */
function pipe2_opts(PDO $pdo, string $fn, string $table, array $o, array $v1, array $v2): void {
    foreach (array_keys($o) as $k) {
        if (in_array($k, $v1, true)) continue;
        if (!in_array($k, $v2, true)) throw new InvalidArgumentException("{$fn}: opção desconhecida «{$k}».");
        if (!db_has_column($pdo, $table, $k)) throw new InvalidArgumentException("{$fn}: a coluna {$table}.{$k} não existe nesta base.");
    }
}

function pipe2_insert(PDO $pdo, string $table, array $row): int {
    $cols = array_keys($row);
    $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_map(fn($v) => is_bool($v) ? (int)$v : $v, array_values($row)));
    return (int)$pdo->lastInsertId();
}

function pipe2_name(string $s): string {
    return str_starts_with($s, PIPE2_T) ? $s : PIPE2_FUNNEL_PREFIX . $s;
}

/** Um funil de teste «P2T · <rótulo>» (funnel_create: is_base 0 e as 5 etapas fixas); inativo salvo $active. */
function pipe2_funnel(PDO $pdo, string $label, bool $active = false): int {
    $id = funnel_create($pdo, PIPE2_FUNNEL_PREFIX . $label);
    $pdo->prepare('UPDATE funnels SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    pipe2_reg($pdo, 'funnels', $id);
    return $id;
}

/**
 * Um negócio de teste, por INSERT direto (sem automações, histórico nem eventos). code 'P2T' + 13 hex, nome «P2T · …».
 * Sem stage_id, fica na etapa fixa da fase (X-Ray; Kickoff em produção; Concluído).
 */
function pipe2_deal(PDO $pdo, array $o = []): int {
    pipe2_opts($pdo, 'pipe2_deal', 'deals', $o,
        ['name', 'phase', 'funnel_id', 'stage_id', 'owner_id', 'exec_owner_id', 'value_estimate', 'budget', 'delivery_date', 'prod_step', 'sync_mode'],
        ['service_codes', 'category', 'category_at', 'billing']);
    $phase = (string)($o['phase'] ?? 'pipeline');
    $fid = isset($o['funnel_id']) ? (int)$o['funnel_id'] : null;
    if (!array_key_exists('stage_id', $o) && $fid) {
        $kind = ['pipeline' => 'xray', 'lost' => 'xray', 'production' => 'kickoff', 'done' => 'done'][$phase] ?? null;
        $o['stage_id'] = $kind ? funnel_fixed_stage($pdo, $fid, $kind) : null;
    }
    $now = date('Y-m-d H:i:s');
    $row = [
        'code'             => PIPE2_T . strtoupper(substr(bin2hex(random_bytes(7)), 0, 13)),
        'name'             => pipe2_name((string)($o['name'] ?? 'negócio')),
        'phase'            => $phase,
        'funnel_id'        => $fid,
        'origin_funnel_id' => $fid,
        'converted_at'     => $phase !== 'lead' ? $now : null,
        'stage_entered_at' => !empty($o['stage_id']) ? $now : null,
        'done_at'          => $phase === 'done' ? $now : null,
        'lost_at'          => $phase === 'lost' ? $now : null,
    ];
    unset($o['name'], $o['phase'], $o['funnel_id']);
    $id = pipe2_insert($pdo, 'deals', $row + $o);
    pipe2_reg($pdo, 'deals', $id);
    return $id;
}

/**
 * Uma frente (deal_lines) por INSERT direto. Sem is_main, é a principal se for do funil do negócio e ainda não houver
 * principal. Refresca a cache do crosselling.
 */
function pipe2_front(PDO $pdo, int $dealId, int $funnelId, array $o = []): int {
    pipe2_opts($pdo, 'pipe2_front', 'deal_lines', $o,
        ['is_main', 'position', 'stage_id', 'status', 'exec_owner_id', 'delivery_date', 'value', 'created_at'],
        ['billing', 'monthly_fee', 'fee_months', 'billing_end', 'cancelled_at', 'cycle_ym', 'merged_into_id', 'exec_mode', 'auto_advance']);
    $st = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(MAX(position), -1) AS pos, COALESCE(SUM(is_main), 0) AS mains FROM deal_lines WHERE deal_id = ?');
    $st->execute([$dealId]);
    $have = $st->fetch();
    $dealFunnel = (int)$pdo->query('SELECT COALESCE(funnel_id, 0) FROM deals WHERE id = ' . $dealId)->fetchColumn();
    $now = date('Y-m-d H:i:s');
    $row = [
        'deal_id'    => $dealId,
        'funnel_id'  => $funnelId,
        'is_main'    => $o['is_main'] ?? (int)($funnelId === $dealFunnel && (int)$have['mains'] === 0),
        'position'   => $o['position'] ?? (int)$have['pos'] + 1,
        'status'     => $o['status'] ?? 'planned',
        'created_at' => $o['created_at'] ?? $now,
    ];
    if (!empty($o['stage_id'])) $row['stage_entered_at'] = $now;
    if (($o['status'] ?? '') === 'active') $row['started_at'] = $now;
    $id = pipe2_insert($pdo, 'deal_lines', $row + $o);
    pipe2_reg($pdo, 'deal_lines', $id);
    $GLOBALS['XSELL_FRESH'] = true;
    return $id;
}

/** Uma tarefa de teste «P2T · …» por INSERT direto (por fazer, salvo indicação). */
function pipe2_task(PDO $pdo, int $dealId, array $o = []): int {
    pipe2_opts($pdo, 'pipe2_task', 'tasks', $o,
        ['title', 'status', 'stage_id', 'line_id', 'auto_rule_id', 'is_gate', 'doc_kind', 'kind', 'assignee_id', 'due_date', 'done_at'],
        ['dedup_key', 'cycle_ym']);
    $status = (string)($o['status'] ?? 'todo');
    $row = [
        'title'   => pipe2_name((string)($o['title'] ?? 'tarefa')),
        'status'  => $status,
        'deal_id' => $dealId ?: null,
        'kind'    => $o['kind'] ?? 'task',
        'done_at' => $o['done_at'] ?? ($status === 'done' ? date('Y-m-d H:i:s') : null),
    ];
    unset($o['title'], $o['status'], $o['kind'], $o['done_at']);
    $id = pipe2_insert($pdo, 'tasks', $row + $o);
    pipe2_reg($pdo, 'tasks', $id);
    return $id;
}

// ── limpeza ─────────────────────────────────────────────────

function pipe2_ids_sql(array $ids): string {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return $ids ? implode(',', $ids) : '0';
}

/** Apaga negócios e o que deles depende sem FK (tarefas, frentes, eventos, rondas); o resto vai por CASCADE. */
function pipe2_delete_deals(PDO $pdo, array $ids): void {
    if (!$ids) return;
    $in = pipe2_ids_sql($ids);
    foreach (['tasks', 'deal_lines', 'stage_events', 'deal_rounds'] as $t) {
        if (db_has_table($pdo, $t)) $pdo->exec("DELETE FROM $t WHERE deal_id IN ($in)");
    }
    $pdo->exec("DELETE FROM deals WHERE id IN ($in)");
}

/** A guarda dos funis: prefixo exato «P2T · », fora da base, sem service_code e sem negócios. */
function pipe2_funnel_guard_sql(PDO $pdo): string {
    $g = "CAST(f.name AS BINARY) LIKE CAST('P2T · %' AS BINARY) AND f.is_base = 0"
       . ' AND NOT EXISTS (SELECT 1 FROM deals d WHERE d.funnel_id = f.id OR d.origin_funnel_id = f.id)';
    if (db_has_table($pdo, 'deal_lines')) $g .= ' AND NOT EXISTS (SELECT 1 FROM deal_lines l WHERE l.funnel_id = f.id)';
    if (db_has_column($pdo, 'funnels', 'service_code')) $g .= " AND f.service_code = ''";
    return $g;
}

function pipe2_delete_funnels(PDO $pdo, string $where): void {
    $ids = $pdo->query('SELECT f.id FROM funnels f WHERE (' . $where . ') AND ' . pipe2_funnel_guard_sql($pdo))->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) $pdo->exec('DELETE FROM funnels WHERE id IN (' . pipe2_ids_sql($ids) . ')');   // etapas e regras por CASCADE
}

/** As regras da «Limpeza» (cabeçalho deste ficheiro). */
function pipe2_cleanup(PDO $pdo): void {
    $k = spl_object_id($pdo);
    $reg = $GLOBALS['PIPE2_REG'][$k]['ids'] ?? [];
    // 1. o que os helpers registaram nesta ligação
    if (!empty($reg['tasks'])) $pdo->exec('DELETE FROM tasks WHERE id IN (' . pipe2_ids_sql($reg['tasks']) . ')');
    if (!empty($reg['deal_lines'])) $pdo->exec('DELETE FROM deal_lines WHERE id IN (' . pipe2_ids_sql($reg['deal_lines']) . ')');
    pipe2_delete_deals($pdo, $reg['deals'] ?? []);
    if (!empty($reg['funnels'])) pipe2_delete_funnels($pdo, 'f.id IN (' . pipe2_ids_sql($reg['funnels']) . ')');
    // 2. as sobras «P2T%» (também as que não passaram pelos helpers)
    $deals = $pdo->query("SELECT id FROM deals WHERE CAST(COALESCE(code, '') AS BINARY) LIKE 'P2T%' OR CAST(name AS BINARY) LIKE 'P2T%'")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec("DELETE FROM tasks WHERE CAST(title AS BINARY) LIKE 'P2T%'");
    pipe2_delete_deals($pdo, $deals);
    // 3. os funis «P2T · %», com a guarda
    pipe2_delete_funnels($pdo, '1=1');
    if (isset($GLOBALS['PIPE2_REG'][$k])) $GLOBALS['PIPE2_REG'][$k]['ids'] = [];
}

// ── fim do processo ─────────────────────────────────────────

function pipe2_shutdown_once(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    register_shutdown_function('pipe2_shutdown');
}

/** Limpa as ligações registadas (as das bases privadas não: vão ser apagadas) e apaga as bases privadas. */
function pipe2_shutdown(): void {
    foreach ($GLOBALS['PIPE2_REG'] as $r) {
        if (in_array($r['db'], $GLOBALS['PIPE2_TMPDBS'], true)) continue;
        try {
            if ($r['pdo']->inTransaction()) $r['pdo']->rollBack();
            pipe2_cleanup($r['pdo']);
        } catch (Throwable $e) {
            fwrite(STDERR, 'pipe2: a limpeza de ' . $r['db'] . ' falhou: ' . $e->getMessage() . "\n");
        }
    }
    foreach (array_reverse($GLOBALS['PIPE2_TMPDBS']) as $db) {
        try { pipe2_tmpdb_drop($db); } catch (Throwable $e) { fwrite(STDERR, 'pipe2: não foi possível apagar ' . $db . ': ' . $e->getMessage() . "\n"); }
    }
}
