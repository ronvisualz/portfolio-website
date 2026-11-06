<?php
/**
 * Pipeline v2, fase 1 — um lado de uma corrida (p2v_race): uma ação da plataforma numa base privada, com a sessão de
 * um utilizador, depois do sinal de partida.
 *
 *   php tests/pipe2_race.php --db=<base privada> --uid=<n|0> --go=F
 *       [--do=task_done --task=ID | --do=tick --today=AAAA-MM-DD | --do=deal_move --deal=ID --stage=ID [--force=motivo]]
 *
 * Liga à base (pipe2_use), põe a sessão do utilizador (0 = sem sessão) e lê o que a ação precisa ANTES do sinal (a
 * tarefa; no deal_move, o negócio por deal_get — como dois pedidos que carregaram a página antes de o outro mover);
 * depois espera o sinal (p2v_go_wait) e faz a ação: task_done (por defeito), pipe2_tick($pdo, $today) (de
 * admin/inc/pipe2_tick.php; sem o ficheiro, {"error":"pipe2_tick.php em falta"} e código 1) ou
 * deal_move($pdo, <negócio lido>, $stage, $force). Imprime {"ok":true} (no deal_move, {"ok":true,"msg":…}); num erro,
 * {"error": "..."} e código 1. Os avisos de PHP vão para o stderr (quem lança a corrida procura «Warning»).
 */

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);
require __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/tasks.php';
require_once __DIR__ . '/../admin/inc/pipeline.php';

$p2rOut = static function (array $v): void {
    echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
};

try {
    $opt = [];
    foreach (array_slice($argv, 1) as $a) {
        if (!preg_match('/^--(db|uid|go|do|task|today|deal|stage|force)=(.*)$/s', (string)$a, $m)) throw new InvalidArgumentException("Argumento inválido: $a");
        $opt[$m[1]] = $m[2];
    }
    $db = (string)($opt['db'] ?? '');
    if (!preg_match(PIPE2_TMPDB_RE, $db)) throw new InvalidArgumentException("--db tem de ser uma base privada topper_p2_<pid>_<n> (não «{$db}»).");
    if (!isset($opt['uid']) || !preg_match('/^\d+$/', $opt['uid'])) throw new InvalidArgumentException('--uid tem de ser um número (0 = sem sessão).');
    $do = (string)($opt['do'] ?? 'task_done');

    $tp = pipe2_tmpdb_pdo($db);
    pipe2_use($tp);
    $uid = (int)$opt['uid'];
    p2v_as($uid === 0 ? null : $uid, $tp);

    // o que a ação precisa, lido antes do sinal
    switch ($do) {
        case 'task_done':
            $taskId = (int)($opt['task'] ?? 0);
            if (!task_by_id($tp, $taskId)) throw new InvalidArgumentException("--task: a tarefa {$taskId} não existe.");
            break;
        case 'tick':
            $today = (string)($opt['today'] ?? today_lisbon());
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) throw new InvalidArgumentException('--today tem de ser AAAA-MM-DD.');
            $tick = __DIR__ . '/../admin/inc/pipe2_tick.php';
            if (!is_file($tick)) { $p2rOut(['error' => 'pipe2_tick.php em falta']); exit(1); }
            require_once $tick;
            break;
        case 'deal_move':
            $deal = deal_get($tp, (int)($opt['deal'] ?? 0));
            if (!$deal) throw new InvalidArgumentException('--deal: o negócio não existe.');
            $stageId = (int)($opt['stage'] ?? 0);
            if ($stageId <= 0) throw new InvalidArgumentException('--stage tem de ser o id de uma etapa.');
            break;
        default:
            throw new InvalidArgumentException("--do desconhecido: {$do}");
    }

    p2v_go_wait($argv);

    switch ($do) {
        case 'task_done':
            task_done($tp, $taskId);
            $p2rOut(['ok' => true]);
            break;
        case 'tick':
            pipe2_tick($tp, $today);
            $p2rOut(['ok' => true]);
            break;
        case 'deal_move':
            $msg = deal_move($tp, $deal, $stageId, (string)($opt['force'] ?? ''));
            $p2rOut(['ok' => true, 'msg' => $msg]);
            break;
    }
} catch (Throwable $e) {
    $p2rOut(['error' => get_class($e) . ': ' . $e->getMessage()]);
    exit(1);
}
exit(0);
