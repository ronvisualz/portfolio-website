<?php
/**
 * Pipeline v2, fase 1 — o probe: chamadas de leitura noutro processo, com a sessão de um utilizador, numa base privada.
 * A my_grid() e a me_row() guardam o primeiro utilizador numa cache estática, por isso cada utilizador é um processo.
 *
 *   php tests/pipe2_probe.php --db=<base privada> --uid=<n|0> --calls=<ficheiro.json> [--go=F]
 *
 * Liga à base (pipe2_use), põe a sessão do utilizador (0 = sem sessão), carrega admin/inc/alerts.php,
 * pipeline_view.php, reach.php e todos os admin/inc/pipe2_*.php que existam, espera o sinal de partida (p2v_go_wait:
 * por isso também serve de script de eco no p2v_race) e, para cada {"fn": "...", "args": [...]} do ficheiro, chama a
 * função se estiver na lista fechada (P2V_PROBE_FN: can, is_admin, current_user, record_reach, reach_sql,
 * flow_can_edit, flow_can_bill, flow_is_commercial, my_alerts, notifications e as pipe2_* definidas em admin/inc).
 * Nos args, {"deal": id}, {"line": id} e {"task": id} viram a linha; a ligação vai à frente quando o 1.º parâmetro da
 * função é PDO. Imprime {"results": [...]}; num erro, {"error": "..."} e código 1. --calls vazio ou com [] →
 * {"results": []}.
 */

ini_set('display_errors', 'stderr');
require __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';

$p2pOut = static function (array $v): void {
    echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
};

try {
    $opt = [];
    foreach (array_slice($argv, 1) as $a) {
        if (!preg_match('/^--(db|uid|calls|go)=(.*)$/s', $a, $m)) throw new InvalidArgumentException("Argumento inválido: $a");
        $opt[$m[1]] = $m[2];
    }
    $db = (string)($opt['db'] ?? '');
    if (!preg_match(PIPE2_TMPDB_RE, $db)) throw new InvalidArgumentException("--db tem de ser uma base privada topper_p2_<pid>_<n> (não «{$db}»).");
    if (!isset($opt['uid']) || !preg_match('/^\d+$/', $opt['uid'])) throw new InvalidArgumentException('--uid tem de ser um número (0 = sem sessão).');
    $calls = [];
    if (($opt['calls'] ?? '') !== '') {
        $raw = @file_get_contents($opt['calls']);
        if ($raw === false) throw new InvalidArgumentException('Não foi possível ler --calls ' . $opt['calls']);
        $calls = trim($raw) === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($calls) || !array_is_list($calls)) throw new InvalidArgumentException('--calls tem de ser uma lista de {"fn", "args"}.');
    }

    $tp = pipe2_tmpdb_pdo($db);
    pipe2_use($tp);
    $uid = (int)$opt['uid'];
    p2v_as($uid === 0 ? null : $uid, $tp);

    require_once __DIR__ . '/../admin/inc/pipe2.php';
    require_once __DIR__ . '/../admin/inc/alerts.php';
    require_once __DIR__ . '/../admin/inc/pipeline_view.php';
    require_once __DIR__ . '/../admin/inc/reach.php';
    $files = glob(__DIR__ . '/../admin/inc/pipe2_*.php') ?: [];
    sort($files);
    foreach ($files as $f) require_once $f;
    $inc = realpath(__DIR__ . '/../admin/inc') . '/';

    p2v_go_wait($argv);

    $results = [];
    foreach ($calls as $i => $c) {
        $fn = is_array($c) ? (string)($c['fn'] ?? '') : '';
        $args = is_array($c) ? ($c['args'] ?? []) : null;
        if (!is_array($args) || !array_is_list($args)) throw new InvalidArgumentException("Chamada $i: args tem de ser uma lista.");
        if (!preg_match(P2V_PROBE_FN, $fn)) throw new InvalidArgumentException("Chamada $i: a função «{$fn}» não está na lista do probe.");
        if (!function_exists($fn)) throw new InvalidArgumentException("Chamada $i: a função «{$fn}» não está definida.");
        $file = (string)(new ReflectionFunction($fn))->getFileName();
        if (str_starts_with($fn, 'pipe2_') && !str_starts_with((string)realpath($file), $inc)) {
            throw new InvalidArgumentException("Chamada $i: a função «{$fn}» não é de admin/inc (é de " . basename($file) . ').');
        }
        $results[] = $fn(...p2v_probe_args($tp, $fn, $args));
    }
    $p2pOut(['results' => $results]);
} catch (Throwable $e) {
    $p2pOut(['error' => get_class($e) . ': ' . $e->getMessage()]);
    exit(1);
}
exit(0);
