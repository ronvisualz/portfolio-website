<?php
// Faturação e quem não tem o Financeiro (segurança): (1) durante um mover instantâneo (§32.1) para a Faturação, ainda na
// fila, o ficheiro já não se lê pela chave antiga (Drive, Toppernote «Da Drive» e os ficheiros de uma referência que já
// estava num Topperboard); (2) os números do Lixo e das pastas não contam a Faturação.   php tests/drive_billing_window_test.php
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/drive_store.php';
require_once __DIR__ . '/../admin/inc/drive_moves.php';
require_once __DIR__ . '/../admin/inc/cc.php';

$tag = bin2hex(random_bytes(3));
$png = function (int $r): string { $im = imagecreatetruecolor(40, 30); imagefill($im, 0, 0, imagecolorallocate($im, $r, 20, 20)); ob_start(); imagepng($im); return (string)ob_get_clean(); };
$api = fn(array $b, int $uid) => tests_http('POST', '/admin/drive_api.php', $b, $uid);
$fin = 1; $nofin = 3;
$BID = (int)trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/ui/seed_board.php')));

// (1) mover instantâneo de um ficheiro visível para a Faturação, ainda na fila
$V = tests_put('Empresa/Teste33/janela-' . $tag . '/recibo.png', $png(200), 'image/png');
$r = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([$V]), 'x' => 100, 'y' => 1400], $fin, true);
$aid = (int)($r['json']['cards'][0]['asset']['id'] ?? 0);
t_true($aid > 0, 'com o Financeiro: o ficheiro entra num Topperboard antes de ser movido (' . substr($r['body'], 0, 80) . ')');
$to = 'Empresa/Financeiro/2026/11/janela-' . $tag . '.png';
$m = $api(['do' => 'move', 'from' => $V, 'to' => $to, 'on_conflict' => 'error', 'instant' => true], $fin);
t_true($m['code'] === 200 && !empty($m['json']['queued']), 'o mover instantâneo fica na fila (' . substr($m['body'], 0, 80) . ')');
t_true(r2_head($V) !== null, 'o objeto ainda está no sítio antigo');
$u = $api(['do' => 'url', 'key' => $V], $nofin);
t_eq($u['code'], 403, 'sem o Financeiro: url da chave antiga → 403 (' . substr($u['body'], 0, 80) . ')');
t_eq($api(['do' => 'info', 'key' => $V], $nofin)['code'], 403, 'sem o Financeiro: info da chave antiga → 403');
$a3 = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([$V]), 'x' => 100, 'y' => 1600], $nofin, true);
t_eq((int)($a3['json']['added'] ?? count($a3['json']['cards'] ?? [])), 0, 'sem o Financeiro: «Da Drive» com a chave antiga não junta nada (' . substr($a3['body'], 0, 80) . ')');
$f3 = tests_http('GET', '/admin/cc_file.php?a=' . $aid . '&s=256', [], $nofin);
t_eq($f3['code'], 404, 'sem o Financeiro: a miniatura da referência que vai para a Faturação → 404 (' . $f3['code'] . ')');
t_true(in_array(tests_http('GET', '/admin/cc_file.php?a=' . $aid . '&s=256', [], $fin)['code'], [200, 302], true), 'com o Financeiro continua');
t_eq($api(['do' => 'url', 'key' => $V], $fin)['code'], 200, 'com o Financeiro: url da chave antiga continua');
// a fila corre: o ficheiro passa para a Faturação de vez
drive_moves_run($pdo, 30);
t_true(r2_head($to) !== null && r2_head($V) === null, 'depois da fila o ficheiro está na Faturação');

// (2) os números: o Lixo e as pastas não contam a Faturação a quem não a vê
$B = tests_put('Empresa/Financeiro/2026/11/lixo-' . $tag . '.png', $png(90), 'image/png');
$before = $api(['do' => 'catalog', 'fresh' => true], $nofin)['json']['trash'] ?? [];
$t = $api(['do' => 'trash', 'key' => $B], $fin);
t_eq($t['code'], 200, 'com o Financeiro: recibo para o Lixo');
$after = $api(['do' => 'catalog', 'fresh' => true], $nofin)['json']['trash'] ?? [];
t_eq([(int)($after['count'] ?? -1), (int)($after['size'] ?? -1)], [(int)($before['count'] ?? -2), (int)($before['size'] ?? -2)], 'sem o Financeiro: o Lixo do catálogo não conta o recibo');
$tl = $api(['do' => 'trash_list'], $nofin)['json'] ?? [];
t_eq([(int)($after['count'] ?? -1), (int)($after['size'] ?? -1)], [(int)($tl['count'] ?? -2), (int)($tl['size'] ?? -2)], 'e bate certo com o trash_list');
$I = $api(['do' => 'info', 'key' => 'Empresa/'], $nofin)['json']['folder'] ?? [];
$cat = $api(['do' => 'catalog'], $nofin)['json']['objects'] ?? [];
$mine = array_filter($cat, fn($o) => str_starts_with($o['k'], 'Empresa/') && !str_ends_with($o['k'], '/'));
t_eq((int)($I['files'] ?? -1), count($mine), 'sem o Financeiro: a pasta Empresa/ conta só os ficheiros que a pessoa vê (' . ($I['files'] ?? '?') . ' vs ' . count($mine) . ')');
t_eq((int)($I['size'] ?? -1), array_sum(array_column($mine, 's')), 'e o tamanho também');
$I1 = $api(['do' => 'info', 'key' => 'Empresa/'], $fin)['json']['folder'] ?? [];
t_true((int)($I1['files'] ?? 0) > (int)($I['files'] ?? 0), 'com o Financeiro a pasta conta também a Faturação');

// limpeza
foreach (drive_trash_all($pdo) as $x) if (str_contains((string)$x['k'], $tag)) $api(['do' => 'purge', 'id' => (int)$x['id']], $fin);
r2_delete($to); drive_catalog_invalidate();
$pdo->prepare('DELETE FROM cc_cards WHERE asset_id = ?')->execute([$aid]);
t_done();
