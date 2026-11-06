<?php
/**
 * Pipeline v2, fase 0 — corre o passo 43 (migrate_pipeline2_43) numa base privada. É um subprocesso dos testes
 * (pipe2_schema_test::seed_once lança dois ao mesmo tempo): não toma o lock dos testes, que é do processo pai, e
 * nunca escreve na bancada partilhada.
 *
 * Uso: php tests/pipe2_run43.php --db=topper_p2_<pid>_<n>
 * Imprime {"ok":true}; em erro, {"ok":false,"error":"…"} e sai com 1.
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/../admin/inc/migrate_pipeline2.php';

$out = static function (array $v): void {
    echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
};

try {
    $db = null;
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--db=(.+)$/', $a, $m)) $db = $m[1];
        else throw new InvalidArgumentException("Argumento inválido: $a");
    }
    if ($db === null || !preg_match(PIPE2_TMPDB_RE, $db)) throw new InvalidArgumentException('--db tem de ser uma base privada topper_p2_<pid>_<n>.');
    $tp = pipe2_tmpdb_pdo($db);
    pipe2_use($tp);
    migrate_pipeline2_43($tp);
    $out(['ok' => true]);
} catch (Throwable $e) {
    $out(['ok' => false, 'error' => $e->getMessage()]);
    exit(1);
}
exit(0);
