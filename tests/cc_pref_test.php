<?php
// Preferências por pessoa no Toppernote (§33.2, §33.7): últimas 8 cores e largura do painel.   php tests/cc_pref_test.php
require __DIR__ . '/boot.php';
$BID = (int)trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/ui/seed_board.php')));
$prefs = fn(int $uid) => (json_decode((string)$pdo->query('SELECT prefs FROM users WHERE id = ' . $uid)->fetchColumn(), true) ?: [])['cc'] ?? [];

$nine = ['#111111', '#ffc718', '#E8846D', '#81CFE3', '#2F77B1', '#000000', '#FFFFFF', '#123456', '#ABCDEF'];
$r = tests_http('POST', '/admin/cc_api.php?a=pref', ['k' => 'colors', 'v' => json_encode($nine)], 1, true);
t_eq($r['code'], 200, 'grava as cores (' . $r['body'] . ')');
t_eq($prefs(1)['colors'] ?? null, array_slice(array_map('strtoupper', $nine), 0, 8), 'guarda só 8, em maiúsculas');
$r = tests_http('POST', '/admin/cc_api.php?a=pref', ['k' => 'colors', 'v' => json_encode(['azul', '#12', '#FFC718'])], 1, true);
t_eq($prefs(1)['colors'] ?? null, ['#FFC718'], 'ignora o que não é cor');
$r = tests_http('POST', '/admin/cc_api.php?a=pref', ['k' => 'side_w', 'v' => '200'], 1, true);
t_eq($r['code'], 400, 'largura abaixo de 280 → 400');
$r = tests_http('POST', '/admin/cc_api.php?a=pref', ['k' => 'side_w', 'v' => '480'], 1, true);
t_eq([$r['code'], $prefs(1)['side_w'] ?? null], [200, 480], 'largura válida fica');
$r = tests_http('POST', '/admin/cc_api.php?a=pref', ['k' => 'outra', 'v' => '1'], 1, true);
t_eq($r['code'], 400, 'chave desconhecida → 400');
$r = tests_http('POST', '/admin/cc_api.php?a=pref', ['k' => 'side_w', 'v' => '400'], 0, true);
t_eq($r['code'], 401, 'sem sessão → 401');
// as preferências de outros ficam intocadas e o Topperboard devolve as minhas
$before = $pdo->query('SELECT prefs FROM users WHERE id = 3')->fetchColumn();
$j = tests_http('GET', '/admin/cc_api.php?a=board&id=' . $BID, [], 1)['json'];
t_eq($j['prefs'] ?? null, ['colors' => ['#FFC718'], 'side_w' => 480], 'o Topperboard traz as minhas preferências');
t_eq($pdo->query('SELECT prefs FROM users WHERE id = 3')->fetchColumn(), $before, 'não mexe nas de outra pessoa');
t_done();
