<?php
// §32.1: mover é instantâneo — a resposta chega logo, o catálogo mostra o item no destino, abrir um ficheiro no destino
// lê da origem enquanto a cópia não acaba; drive_moves_run faz a cópia; «Anular» antes de correr repõe tudo.
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/drive_moves.php';

$base = 'Empresa/Teste32/mv-' . bin2hex(random_bytes(3)) . '/';
for ($i = 1; $i <= 30; $i++) tests_put(sprintf('%ssrc/F/f%02d.txt', $base, $i), 'conteudo ' . $i);
r2_mkdir($base . 'dst/');
$api = fn(array $b) => tests_http('POST', '/admin/drive_api.php', $b, 1);
$keys = fn() => array_column($api(['do' => 'catalog', 'fresh' => true])['json']['objects'] ?? [], 'k');
$batch = bin2hex(random_bytes(8));

// 1. mover uma pasta de 30 ficheiros: resposta imediata
$t = microtime(true);
$r = $api(['do' => 'move', 'from' => $base . 'src/F/', 'to' => $base . 'dst/F/', 'on_conflict' => 'error', 'instant' => true, 'batch' => $batch]);
$dt = microtime(true) - $t;
t_eq($r['code'], 200, 'mover responde 200');
t_true(!empty($r['json']['queued']) && !empty($r['json']['done']), 'fica em fila e a interface dá por feito');
t_true($dt < 1.0, sprintf('responde em menos de 1 s (%.2f s)', $dt));
t_true(r2_head($base . 'src/F/f01.txt') !== null, 'ainda nada foi copiado no armazenamento');
$k = $keys();
t_true(in_array($base . 'dst/F/f01.txt', $k, true) && in_array($base . 'dst/F/f30.txt', $k, true), 'o catálogo já mostra a pasta no destino');
t_true(!in_array($base . 'src/F/f01.txt', $k, true), 'e já não a mostra na origem');
$u = $api(['do' => 'url', 'key' => $base . 'dst/F/f07.txt'])['json']['url'] ?? '';
t_true(str_contains(rawurldecode($u), 'src/F/f07.txt'), 'abrir no destino lê da origem enquanto não acaba');
$st = $api(['do' => 'moves_status'])['json'] ?? [];
t_true(($st['files'] ?? 0) >= 30, 'o estado diz quantos ficheiros faltam arrumar (' . ($st['files'] ?? '?') . ')');

// 2. em cadeia, antes de correr: dst/F/ → dst/G/ (a origem ainda só existe «na fila»)
$r2 = $api(['do' => 'move', 'from' => $base . 'dst/F/', 'to' => $base . 'dst/G/', 'on_conflict' => 'error', 'instant' => true, 'batch' => bin2hex(random_bytes(8))]);
t_eq($r2['code'], 200, 'mover outra vez antes de a cópia acabar é aceite');
t_eq($api(['do' => 'move', 'from' => $base . 'src/X.txt', 'to' => $base . 'dst/X.txt', 'on_conflict' => 'error', 'instant' => true])['code'], 404, 'o que não existe nem na fila → 404');

// enquanto está a caminho, copiar / Lixo / partilhar dizem que ainda está a ser arrumado (e não que não existe)
foreach ([['do' => 'copy', 'from' => $base . 'dst/G/f01.txt', 'to' => $base . 'dst/c01.txt', 'on_conflict' => 'error'],
          ['do' => 'trash', 'key' => $base . 'dst/G/f02.txt'],
          ['do' => 'share_quick', 'key' => $base . 'dst/G/f03.txt']] as $b) {
    $x = $api($b);
    t_true($x['code'] === 409 && str_contains((string)($x['json']['error'] ?? ''), 'a ser arrumad'), $b['do'] . ' de um item a caminho → 409 «a ser arrumado» (' . $x['code'] . ' ' . ($x['json']['error'] ?? '') . ')');
}

// revisão final: também a pasta que contém uma origem a caminho, e criar uma pasta em cima de um destino a caminho
$x = $api(['do' => 'trash', 'key' => $base . 'src/']);
t_eq($x['code'], 409, 'Lixo da pasta que contém a origem a caminho → 409 (' . ($x['json']['error'] ?? '') . ')');
t_eq($api(['do' => 'mkdir', 'key' => $base . 'dst/G/'])['code'], 409, 'criar uma pasta em cima de um destino a caminho → 409');

// 3. o trabalho em segundo plano
drive_moves_run($pdo, 60.0);
t_true(r2_head($base . 'dst/G/f01.txt') !== null && r2_head($base . 'dst/G/f30.txt') !== null, 'depois de correr, os ficheiros estão no destino final');
t_eq(r2_get($base . 'dst/G/f07.txt'), 'conteudo 7', 'com o conteúdo certo');
t_true(r2_head($base . 'src/F/f01.txt') === null && r2_head($base . 'dst/F/f01.txt') === null, 'a origem e o passo intermédio desapareceram');
t_eq((int)$pdo->query("SELECT COUNT(*) FROM drive_moves WHERE src LIKE " . $pdo->quote($base . '%') . " AND status <> 'done'")->fetchColumn(), 0, 'as linhas ficam «done»');
t_true(($api(['do' => 'moves_status'])['json']['files'] ?? -1) === 0, 'nada por arrumar');

// 4. «Anular» antes de correr: nada mexe no armazenamento e volta a aparecer na origem
tests_put($base . 'u/H/a.txt', 'a');
$b2 = bin2hex(random_bytes(8));
$api(['do' => 'move', 'from' => $base . 'u/H/', 'to' => $base . 'dst/H/', 'on_conflict' => 'error', 'instant' => true, 'batch' => $b2]);
$un = $api(['do' => 'move_undo', 'batch' => $b2]);
t_eq($un['code'], 200, 'anular responde 200');
$k = $keys();
t_true(in_array($base . 'u/H/a.txt', $k, true) && !in_array($base . 'dst/H/a.txt', $k, true), 'anulado: volta a estar na origem');
drive_moves_run($pdo, 30.0);
t_true(r2_head($base . 'u/H/a.txt') !== null && r2_head($base . 'dst/H/a.txt') === null, 'e o armazenamento nem mexeu');

// 5. «Anular» depois de correr: enfileira o inverso
$b3 = bin2hex(random_bytes(8));
$api(['do' => 'move', 'from' => $base . 'u/H/', 'to' => $base . 'dst/H2/', 'on_conflict' => 'error', 'instant' => true, 'batch' => $b3]);
drive_moves_run($pdo, 30.0);
t_true(r2_head($base . 'dst/H2/a.txt') !== null, 'movido');
t_eq($api(['do' => 'move_undo', 'batch' => $b3])['code'], 200, 'anular depois de feito');
t_true(in_array($base . 'u/H/a.txt', $keys(), true), 'aparece logo de volta na origem');
drive_moves_run($pdo, 30.0);
t_true(r2_head($base . 'u/H/a.txt') !== null && r2_head($base . 'dst/H2/a.txt') === null, 'e o armazenamento volta ao que era');

// 5b. «Anular» com a cópia já a meio: para e tudo volta à origem
for ($i = 1; $i <= 12; $i++) tests_put(sprintf('%smeio/P/f%02d.txt', $base, $i), 'p' . $i);
$b5 = bin2hex(random_bytes(8));
$api(['do' => 'move', 'from' => $base . 'meio/P/', 'to' => $base . 'meio2/P/', 'on_conflict' => 'error', 'instant' => true, 'batch' => $b5]);
drive_moves_run($pdo, 30.0, 4);   // só 4 objetos: fica a meio
$st5 = (string)$pdo->query("SELECT status FROM drive_moves WHERE batch = " . $pdo->quote($b5))->fetchColumn();
t_eq($st5, 'running', 'a cópia ficou a meio');
t_true(r2_head($base . 'meio2/P/f01.txt') !== null && r2_head($base . 'meio/P/f12.txt') !== null, 'parte no destino, parte na origem');
t_eq($api(['do' => 'move_undo', 'batch' => $b5])['code'], 200, 'anular a meio');
drive_moves_run($pdo, 60.0);
$back = count(array_filter(r2_list_all($base . 'meio/P/', 1), fn($o) => substr($o['key'], -1) !== '/'));
t_eq($back, 12, 'os 12 ficheiros voltaram à origem');
t_eq(count(array_filter(r2_list_all($base . 'meio2/P/', 1), fn($o) => substr($o['key'], -1) !== '/')), 0, 'e nada ficou no destino');
// 5c. um «falhou» não escreve por cima de um «anulado»
$cid = drive_move_enqueue($pdo, $base . 'nada.txt', $base . 'nada2.txt', false, 'error', 1, bin2hex(random_bytes(8)));
$pdo->exec("UPDATE drive_moves SET status = 'cancelled' WHERE id = " . (int)$cid);
drive_moves_mark_failed($pdo, $cid, 'teste');
t_eq((string)$pdo->query('SELECT status FROM drive_moves WHERE id = ' . (int)$cid)->fetchColumn(), 'cancelled', '«falhou» não escreve por cima de «anulado»');

// 6. conflitos decididos antes, como hoje
tests_put($base . 'c/a.txt', '1'); tests_put($base . 'c2/a.txt', '2');
t_eq($api(['do' => 'move', 'from' => $base . 'c/a.txt', 'to' => $base . 'c2/a.txt', 'on_conflict' => 'error', 'instant' => true])['code'], 409, 'conflito → 409 (diálogo)');
// 6b. revisão final: «Substituir» mostra e serve logo o ficheiro novo (não o antigo que lá estava)
tests_put($base . 'ow/novo.txt', 'NOVO-NOVO'); tests_put($base . 'ow2/novo.txt', 'velho');
$api(['do' => 'move', 'from' => $base . 'ow/novo.txt', 'to' => $base . 'ow2/novo.txt', 'on_conflict' => 'overwrite', 'instant' => true]);
$u = $api(['do' => 'url', 'key' => $base . 'ow2/novo.txt'])['json']['url'] ?? '';
t_true(str_contains(rawurldecode($u), 'ow/novo.txt') && !str_contains(rawurldecode($u), 'ow2/novo.txt'), 'substituir: abrir lê o novo (da origem) enquanto não acaba');
$cat = array_column($api(['do' => 'catalog', 'fresh' => true])['json']['objects'] ?? [], null, 'k');
t_eq((int)($cat[$base . 'ow2/novo.txt']['s'] ?? -1), 9, 'substituir: o catálogo mostra o tamanho do novo');
drive_moves_run($pdo, 30.0);
t_eq(r2_get($base . 'ow2/novo.txt'), 'NOVO-NOVO', 'substituir: depois de correr, o destino tem o novo');
// 6c. o destino ficou ocupado depois de pôr na fila (sem «Substituir»): não escreve por cima
tests_put($base . 'cf/x.txt', 'meu');
$api(['do' => 'move', 'from' => $base . 'cf/x.txt', 'to' => $base . 'cf2/x.txt', 'on_conflict' => 'error', 'instant' => true]);
tests_put($base . 'cf2/x.txt', 'de outra pessoa');
drive_moves_run($pdo, 30.0);
t_eq(r2_get($base . 'cf2/x.txt'), 'de outra pessoa', 'conflito tardio: o que outra pessoa pôs no destino fica');
t_eq(r2_get($base . 'cf/x.txt'), 'meu', 'e o ficheiro movido fica na origem');
t_eq((string)$pdo->query("SELECT status FROM drive_moves WHERE src = " . $pdo->quote($base . 'cf/x.txt') . " ORDER BY id DESC LIMIT 1")->fetchColumn(), 'failed', 'a linha fica «failed» (a Drive avisa)');
tests_put($base . 'cg/F/a.txt', 'a');
$api(['do' => 'move', 'from' => $base . 'cg/F/', 'to' => $base . 'cg2/F/', 'on_conflict' => 'error', 'instant' => true]);
tests_put($base . 'cg2/F/a.txt', 'outro');
drive_moves_run($pdo, 30.0);
t_eq(r2_get($base . 'cg2/F/a.txt'), 'outro', 'conflito tardio numa pasta: não escreve por cima');

// 7. outra pessoa não anula o que não é seu
$b4 = bin2hex(random_bytes(8));
$api(['do' => 'move', 'from' => $base . 'c/a.txt', 'to' => $base . 'c3/a.txt', 'on_conflict' => 'error', 'instant' => true, 'batch' => $b4]);
$member = (int)$pdo->query("SELECT id FROM users WHERE is_active = 1 AND role NOT IN ('admin','superadmin') ORDER BY id LIMIT 1")->fetchColumn();
if ($member) t_eq(tests_http('POST', '/admin/drive_api.php', ['do' => 'move_undo', 'batch' => $b4], $member)['code'], 404, 'só quem moveu (ou um admin) anula');
drive_moves_run($pdo, 30.0);

foreach (r2_list_all($base, 5) as $o) r2_delete($o['key']);
$pdo->exec("DELETE FROM drive_moves WHERE src LIKE " . $pdo->quote($base . '%'));
drive_catalog_invalidate();
t_done();
