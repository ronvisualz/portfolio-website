<?php
// Ficheiros da Drive no Topperboard (§33.9): o cartão aponta para o ficheiro (não copia); mover/renomear acompanha;
// enviar para o Lixo marca «Ficheiro apagado da Drive» (aviso no Topperboard + notificação a quem criou o cartão);
// restaurar limpa; apagar de vez mantém a marca, sem «Restaurar do Lixo».   php tests/cc_drive_test.php
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/cc.php';
require_once __DIR__ . '/../admin/inc/drive_moves.php';
$BID = (int)trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/ui/seed_board.php')));
$base = 'Empresa/Teste33/drv-' . bin2hex(random_bytes(3)) . '/';
$f1 = tests_put($base . 'a/ref1.txt', 'um ' . microtime(true));
$f2 = tests_put($base . 'a/ref2.txt', 'dois ' . microtime(true));
$drive = fn(array $b, int $uid = 1) => tests_http('POST', '/admin/drive_api.php', $b, $uid);
$assets0 = (int)$pdo->query("SELECT COUNT(*) FROM cc_assets WHERE file_key LIKE 'r2:.topper/cc/%'")->fetchColumn();

$r = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([$f1, $f2]), 'x' => 100, 'y' => 900], 3, true);
t_eq($r['code'], 200, 'juntar 2 ficheiros da Drive (' . substr($r['body'], 0, 160) . ')');
$cards = array_column($r['json']['cards'] ?? [], 'id');
$aids = array_map(fn($c) => (int)$c['asset']['id'], $r['json']['cards'] ?? []);
$key = fn(int $aid) => (string)$pdo->query('SELECT file_key FROM cc_assets WHERE id = ' . $aid)->fetchColumn();
t_eq([$key($aids[0]), $key($aids[1])], ['r2:' . $f1, 'r2:' . $f2], 'os cartões apontam para os ficheiros da Drive');
t_eq((int)$pdo->query("SELECT COUNT(*) FROM cc_assets WHERE file_key LIKE 'r2:.topper/cc/%'")->fetchColumn(), $assets0, 'nada foi copiado para o armazenamento do Toppernote');

// mover a pasta (instantâneo + fila) e renomear um ficheiro: o cartão acompanha
$drive(['do' => 'move', 'from' => $base . 'a/', 'to' => $base . 'b/', 'on_conflict' => 'error', 'instant' => true, 'batch' => bin2hex(random_bytes(8))]);
drive_moves_run($pdo, 60);
t_eq($key($aids[0]), 'r2:' . $base . 'b/ref1.txt', 'mover a pasta na Drive → o cartão segue');
$drive(['do' => 'rename', 'key' => $base . 'b/ref1.txt', 'name' => 'luz.txt']);
t_eq($key($aids[0]), 'r2:' . $base . 'b/luz.txt', 'renomear na Drive → o cartão segue');

// Lixo: a pasta inteira (2 ficheiros, 1 Topperboard) → 2 cartões marcados, 1 aviso no Topperboard, 1 notificação
$t = $drive(['do' => 'trash', 'key' => $base . 'b/']);
t_eq($t['code'], 200, 'enviar a pasta para o Lixo (' . substr($t['body'], 0, 120) . ')');
$gone = $pdo->query('SELECT id, drive_gone, drive_trash_id FROM cc_assets WHERE id IN (' . implode(',', $aids) . ')')->fetchAll();
t_true(count(array_filter($gone, fn($g) => $g['drive_gone'] && (int)$g['drive_trash_id'] > 0)) === 2, 'as 2 referências ficam marcadas com o item do Lixo');
$j = tests_http('GET', '/admin/cc_api.php?a=board&id=' . $BID, [], 1)['json'];
$g = $j['gone'] ?? [];
t_eq(count($g), 2, 'o Topperboard traz os 2 cartões apagados da Drive');
t_true(($g[0]['trash_id'] ?? 0) > 0 && ($g[0]['name'] ?? '') !== '', 'cada um com o nome e o item do Lixo');
$byCard = array_column($j['cards'], null, 'id');
t_true(!empty($byCard[$cards[0]]['gone']), 'o cartão vem marcado (faixa «Ficheiro apagado da Drive»)');
$al = array_values(array_filter(tests_http('GET', '/admin/ui_api.php?a=notifications', [], 3)['json']['items'] ?? [], fn($n) => str_starts_with((string)($n['key'] ?? ''), 'ccg:')));
t_eq(count($al), 1, 'uma notificação para quem criou os cartões (não 2) (' . json_encode(array_column($al, 'title')) . ')');
t_true(str_contains((string)($al[0]['title'] ?? ''), 'apagado') && str_contains((string)($al[0]['url'] ?? ''), 'b=' . $BID), 'diz o que aconteceu e leva ao Topperboard');
t_eq(count(array_filter(tests_http('GET', '/admin/ui_api.php?a=notifications', [], 1)['json']['items'] ?? [], fn($n) => str_starts_with((string)($n['key'] ?? ''), 'ccg:'))), 0, 'quem não criou os cartões não é notificado');

// restaurar do Lixo → limpa
$tid = (int)$gone[0]['drive_trash_id'];
$rs = $drive(['do' => 'restore', 'id' => $tid]);
t_eq($rs['code'], 200, 'restaurar do Lixo');
$left = (int)$pdo->query('SELECT COUNT(*) FROM cc_assets WHERE drive_gone IS NOT NULL AND id IN (' . implode(',', $aids) . ')')->fetchColumn();
t_eq($left, 0, 'restaurado → os cartões deixam de estar marcados');
t_eq($key($aids[0]), 'r2:' . $base . 'b/luz.txt', 'e continuam a apontar para o sítio certo');

// Lixo e apagar de vez → fica marcado, sem item do Lixo
$t2 = $drive(['do' => 'trash', 'key' => $base . 'b/luz.txt']);
$tid2 = (int)($t2['json']['id'] ?? $pdo->query('SELECT drive_trash_id FROM cc_assets WHERE id = ' . $aids[0])->fetchColumn());
$drive(['do' => 'purge', 'id' => $tid2]);
$g2 = array_values(array_filter(tests_http('GET', '/admin/cc_api.php?a=board&id=' . $BID, [], 1)['json']['gone'] ?? [], fn($x) => (int)$x['card'] === (int)$cards[0]));
t_true(count($g2) === 1 && empty($g2[0]['trash_id']), 'apagado de vez: continua marcado, sem «Restaurar do Lixo»');
// quem não vê o ficheiro na Drive (Faturação sem Financeiro) também não o vê pelo Topperboard
$bill = tests_put('Empresa/Financeiro/2026/10/cc33-' . bin2hex(random_bytes(2)) . '.pdf', '%PDF-1.4 teste', 'application/pdf');
$rb = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([$bill]), 'x' => 0, 'y' => 1400], 1, true);
$baid = (int)($rb['json']['cards'][0]['asset']['id'] ?? 0);
t_true($baid > 0, 'quem tem Financeiro junta um ficheiro da Faturação');
t_true(in_array(tests_http('GET', "/admin/cc_file.php?a=$baid&s=0", [], 1)['code'], [200, 302], true), 'quem vê a Faturação abre o ficheiro pelo Topperboard');
t_eq(tests_http('GET', "/admin/cc_file.php?a=$baid&s=0", [], 3)['code'], 404, 'quem não vê a Faturação na Drive não o abre pelo Topperboard');
t_done();
