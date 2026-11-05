<?php
// Preferências por pessoa no Toppernote (§33.2, §33.7, §33.14): últimas 8 cores, largura do painel e barra de ferramentas
// expandida.   php tests/cc_pref_test.php
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
// barra de ferramentas expandida (§33.14): sim/não, por pessoa
$before = $pdo->query('SELECT prefs FROM users WHERE id = 3')->fetchColumn();
$j = tests_http('GET', '/admin/cc_api.php?a=board&id=' . $BID, [], 1)['json'];
t_eq($j['prefs']['tools'] ?? 'falta', false, 'sem escolha, a barra de ferramentas vem recolhida');
$r = tests_http('POST', '/admin/cc_api.php?a=pref', ['k' => 'tools', 'v' => '1'], 1, true);
t_eq([$r['code'], $prefs(1)['tools'] ?? null], [200, true], 'barra expandida fica (' . $r['body'] . ')');
// as preferências de outros ficam intocadas e o Topperboard devolve as minhas
$j = tests_http('GET', '/admin/cc_api.php?a=board&id=' . $BID, [], 1)['json'];
t_eq($j['prefs'] ?? null, ['colors' => ['#FFC718'], 'side_w' => 480, 'tools' => true], 'o Topperboard traz as minhas preferências');
t_eq($pdo->query('SELECT prefs FROM users WHERE id = 3')->fetchColumn(), $before, 'não mexe nas de outra pessoa');
$j3 = tests_http('GET', '/admin/cc_api.php?a=board&id=' . $BID, [], 3)['json'];
t_eq($j3['prefs']['tools'] ?? null, (bool)((json_decode((string)$before, true) ?: [])['cc']['tools'] ?? false), 'outra pessoa não herda a barra expandida');
$r = tests_http('POST', '/admin/cc_api.php?a=pref', ['k' => 'tools', 'v' => 'x'], 1, true);
t_eq([$r['code'], $prefs(1)['tools'] ?? null], [400, true], 'valor que não é 0/1 → 400 e não muda');
$r = tests_http('POST', '/admin/cc_api.php?a=pref', ['k' => 'tools', 'v' => '0'], 1, true);
t_eq([$r['code'], $prefs(1)['tools'] ?? null], [200, false], 'recolher (0) fica');
tests_http('POST', '/admin/cc_api.php?a=pref', ['k' => 'tools', 'v' => '1'], 1, true);
$r = tests_http('POST', '/admin/cc_api.php?a=pref', ['k' => 'tools', 'v' => ''], 1, true);
t_eq([$r['code'], $prefs(1)['tools'] ?? null], [200, false], 'recolher (vazio, como o browser envia false) fica');
// repõe: a pessoa 1 é partilhada pelos testes
$p1 = json_decode((string)$pdo->query('SELECT prefs FROM users WHERE id = 1')->fetchColumn(), true) ?: [];
unset($p1['cc']['tools']);
$pdo->prepare('UPDATE users SET prefs = ? WHERE id = 1')->execute([json_encode($p1, JSON_UNESCAPED_UNICODE)]);
t_done();
