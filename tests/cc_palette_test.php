<?php
// Paleta manual (§33.11): adicionar cores escolhidas com o conta-gotas, renomear, esconder as automáticas, remover só as
// manuais; a pesquisa por cor da Biblioteca encontra as manuais.   php tests/cc_palette_test.php
require __DIR__ . '/boot.php';
require_once __DIR__ . '/../admin/inc/cc.php';
$BID = (int)trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/ui/seed_board.php')));
$aid = (int)$pdo->query("SELECT asset_id FROM cc_cards WHERE board_id = $BID AND type = 'image'")->fetchColumn();
// a referência semeada é reaproveitada entre corridas: repõe a paleta automática (sem manuais nem escondidas)
$p0 = json_decode((string)$pdo->query("SELECT palette FROM cc_assets WHERE id = $aid")->fetchColumn(), true) ?: [];
$p0 = array_values(array_map(fn($c) => array_slice($c, 0, 5), array_filter($p0, fn($c) => empty($c[5]['m']))));
$pdo->prepare('UPDATE cc_assets SET palette = ? WHERE id = ?')->execute([json_encode($p0), $aid]);
$pal = fn() => tests_http('GET', '/admin/cc_api.php?a=asset&id=' . $aid, [], 1)['json']['asset']['palette'] ?? [];
$auto = $pal();
t_true(count($auto) >= 1 && empty($auto[0]['m']), 'a referência tem paleta automática (' . json_encode($auto) . ')');

$r = tests_http('POST', '/admin/cc_api.php?a=palette_add', ['asset' => $aid, 'hex' => '7c7774', 'name' => 'Pele'], 1, true);
t_eq($r['code'], 200, 'adicionar uma cor (' . $r['body'] . ')');
$m = array_values(array_filter($pal(), fn($c) => !empty($c['m'])));
t_eq([$m[0]['hex'] ?? null, $m[0]['name'] ?? null, $m[0]['by'] ?? null], ['#7C7774', 'Pele', 1], 'fica como manual, com nome e quem a escolheu');
tests_http('POST', '/admin/cc_api.php?a=palette_add', ['asset' => $aid, 'hex' => '#7C7774', 'name' => 'Pele 2'], 1, true);
t_eq(count(array_filter($pal(), fn($c) => $c['hex'] === '#7C7774')), 1, 'a mesma cor outra vez não duplica');
$r = tests_http('POST', '/admin/cc_api.php?a=palette_add', ['asset' => $aid, 'hex' => 'azul'], 1, true);
t_eq($r['code'], 400, 'o que não é cor → 400');

$r = tests_http('POST', '/admin/cc_api.php?a=palette_edit', ['asset' => $aid, 'hex' => '#7C7774', 'op' => 'name', 'name' => 'Pele quente'], 1, true);
t_eq(array_values(array_filter($pal(), fn($c) => $c['hex'] === '#7C7774'))[0]['name'] ?? null, 'Pele quente', 'renomear');
$h0 = $auto[0]['hex'];
$r = tests_http('POST', '/admin/cc_api.php?a=palette_edit', ['asset' => $aid, 'hex' => $h0, 'op' => 'remove'], 1, true);
t_eq($r['code'], 400, 'remover uma automática → recusa (esconde-se)');
$r = tests_http('POST', '/admin/cc_api.php?a=palette_edit', ['asset' => $aid, 'hex' => $h0, 'op' => 'hide'], 1, true);
t_true($r['code'] === 200 && !in_array($h0, array_column($pal(), 'hex'), true), 'esconder uma automática tira-a da paleta mostrada');
t_true(str_contains((string)$pdo->query("SELECT palette FROM cc_assets WHERE id = $aid")->fetchColumn(), '"h":1'), 'e fica guardada com h:1');

// a pesquisa por cor encontra a manual
$j = tests_http('GET', '/admin/cc_api.php?a=library&color=' . urlencode('#7C7774'), [], 1)['json'];
t_true(in_array($aid, array_column($j['items'] ?? [], 'id'), true), 'a pesquisa por #7C7774 encontra a referência');

$r = tests_http('POST', '/admin/cc_api.php?a=palette_edit', ['asset' => $aid, 'hex' => '#7C7774', 'op' => 'remove'], 1, true);
t_true($r['code'] === 200 && !in_array('#7C7774', array_column($pal(), 'hex'), true), 'remover a manual');
// o conta-gotas lê os píxeis do mesmo domínio: px=1 serve a imagem em vez de redirecionar para o R2
$r = tests_http('GET', "/admin/cc_file.php?a=$aid&s=0&px=1", [], 1);
t_true($r['code'] === 200 && str_starts_with((string)($r['headers']['content-type'] ?? ''), 'image/'), 'px=1 serve o original no mesmo domínio (' . $r['code'] . ' ' . ($r['headers']['content-type'] ?? '') . ')');
t_eq(@getimagesizefromstring($r['body'])[0] ?? 0, 2400, 'é o original em resolução total');
$r = tests_http('GET', "/admin/cc_file.php?a=$aid&s=0&px=1", [], 0);
t_eq($r['code'], 401, 'sem sessão → 401');
t_done();
