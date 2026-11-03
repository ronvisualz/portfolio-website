<?php
// Conta-gotas (§33.11): cc_file.php &px=1 serve os píxeis do original no próprio domínio. Só imagens raster — um SVG
// servido inline no domínio do admin corre <script> com a sessão de quem o abre.   php tests/cc_file_px_test.php
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/cc.php';
$BID = (int)trim((string)shell_exec('php ' . escapeshellarg(__DIR__ . '/ui/seed_board.php')));
$base = 'Empresa/Teste33/px-' . bin2hex(random_bytes(3)) . '/';
$svg = tests_put($base . 'mau.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>');
$im = imagecreatetruecolor(4, 4); ob_start(); imagepng($im); $png = tests_put($base . 'bom.png', (string)ob_get_clean());

$r = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([$svg, $png]), 'x' => 100, 'y' => 1200], 1, true);
t_eq($r['code'], 200, 'juntar um SVG e um PNG da Drive (' . substr($r['body'], 0, 120) . ')');
$aid = [];
foreach ($r['json']['cards'] ?? [] as $c) {
    $fk = (string)$pdo->query('SELECT file_key FROM cc_assets WHERE id = ' . (int)$c['asset']['id'])->fetchColumn();
    $aid[str_ends_with($fk, '.svg') ? 'svg' : 'png'] = (int)$c['asset']['id'];
}
t_true(isset($aid['svg'], $aid['png']), 'os dois cartões foram criados');

$s = tests_http('GET', '/admin/cc_file.php?a=' . ($aid['svg'] ?? 0) . '&s=0&px=1', [], 1);
$ct = strtolower((string)($s['headers']['content-type'] ?? ''));
t_true(!($s['code'] === 200 && str_contains($ct, 'svg')), 'px=1 não serve SVG inline no domínio (código ' . $s['code'] . ', ' . $ct . ')');
$p = tests_http('GET', '/admin/cc_file.php?a=' . ($aid['png'] ?? 0) . '&s=0&px=1', [], 1);
t_eq($p['code'], 200, 'px=1 continua a servir o PNG');
t_true(str_contains(strtolower((string)($p['headers']['content-type'] ?? '')), 'image/png'), 'com o tipo image/png');

// §33.14: o fotograma de um vídeo lê-se por um clone com crossorigin; o pedido CORS tem o seu próprio endereço assinado
// (o R2 real pode reaproveitar a resposta sem cabeçalhos CORS que o leitor do detalhe já guardou para o mesmo URL)
$vk = tests_put($base . 'cor.webm', (string)file_get_contents(__DIR__ . '/fixtures/pick_red_blue.webm'), 'video/webm');
$r = tests_http('POST', '/admin/cc_api.php?a=drive_add', ['board' => $BID, 'keys' => json_encode([$vk]), 'x' => 400, 'y' => 1200], 1, true);
$vid = (int)($r['json']['cards'][0]['asset']['id'] ?? 0);
t_true($vid > 0 && ($r['json']['cards'][0]['type'] ?? '') === 'video', 'juntar um vídeo da Drive (' . substr($r['body'], 0, 120) . ')');
$plain = tests_http('GET', "/admin/cc_file.php?a=$vid&s=0", [], 1);
$cors = tests_http('GET', "/admin/cc_file.php?a=$vid&s=0&cors=1", [], 1);
$cors2 = tests_http('GET', "/admin/cc_file.php?a=$vid&s=0&cors=1", [], 1);
$L1 = (string)($plain['headers']['location'] ?? ''); $L2 = (string)($cors['headers']['location'] ?? '');
t_eq([$plain['code'], $cors['code']], [302, 302], 'o original e o pedido CORS redirecionam para o R2');
t_true($L1 !== '' && $L2 !== '' && $L1 !== $L2, 'o pedido CORS tem outro endereço assinado (' . substr($L2, 0, 90) . ')');
t_eq(parse_url($L1, PHP_URL_PATH), parse_url($L2, PHP_URL_PATH), 'do mesmo objeto');
t_eq((string)($cors2['headers']['location'] ?? ''), $L2, 'e estável (a cache do browser serve)');
$g = tests_http('GET', $L2, [], 0, false, ['Origin: ' . TESTS_BASE]);
t_eq($g['code'], 200, 'o endereço CORS abre');
t_true(str_starts_with($g['body'], "\x1A\x45\xDF\xA3"), 'e traz o vídeo');
t_done();
