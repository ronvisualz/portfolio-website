<?php
// Pipeline v2 — cada ficheiro que chama funções pipe2_* inclui o admin/inc/pipe2.php ele próprio. A Hostinger recebe
// os ficheiros um a um: se um leitor novo chegar antes do inc/pipeline.php novo, não pode depender dele para ter as
// funções (erro fatal). Corre sem a bancada: php tests/pipe2_includes_test.php
$ok = 0; $fail = 0;
$root = dirname(__DIR__) . '/admin';
$files = array_merge(glob($root . '/*.php') ?: [], glob($root . '/inc/*.php') ?: []);
foreach ($files as $f) {
    $b = basename($f);
    if ($b === 'pipe2.php') continue;
    $src = file_get_contents($f);
    if (!preg_match('/\bpipe2_[a-z0-9_]+\s*\(/', $src)) continue;
    // as definições do próprio passo de migração (function pipe2_...) não contam como chamadas a outro ficheiro
    $calls = preg_replace('/function\s+pipe2_[a-z0-9_]+/', '', $src);
    if (!preg_match('/\bpipe2_[a-z0-9_]+\s*\(/', $calls)) continue;
    if (preg_match("~require_once\\s+__DIR__\\s*\\.\\s*'/(inc/)?pipe2\\.php'~", $src)) { $ok++; continue; }
    $fail++;
    echo 'FALHA: ' . substr($f, strlen(dirname(__DIR__)) + 1) . " chama pipe2_* sem require_once do inc/pipe2.php\n";
}
if ($ok === 0) { $fail++; echo "FALHA: nenhum ficheiro encontrado (o teste não está a ver o código)\n"; }
echo $fail ? "FALHOU $fail de " . ($ok + $fail) . "\n" : "OK $ok\n";
exit($fail ? 1 : 0);
