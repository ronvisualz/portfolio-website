<?php
// Miniaturas da Drive (.topper/thumbs/<etag>.jpg) e a Faturação: quem não tem o Financeiro não recebe o etag no
// catálogo nem um URL assinado (thumb_urls / thumb_sign) da miniatura de um documento da Faturação — a miniatura de
// um recibo mostra os valores em euros. Quem tem o Financeiro continua a ver tudo, e os envios novos (etag que o
// catálogo ainda não conhece) continuam a poder gravar a sua miniatura.   php tests/drive_thumbs_perm_test.php
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';

$tag = bin2hex(random_bytes(3));
$png = function (int $r, int $g, int $b): string { $im = imagecreatetruecolor(40, 30); imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b)); ob_start(); imagepng($im); return (string)ob_get_clean(); };
$jpg = function (): string { $im = imagecreatetruecolor(32, 24); ob_start(); imagejpeg($im); return (string)ob_get_clean(); };
$bill = tests_put('Empresa/Financeiro/2026/11/thumbperm-' . $tag . '.png', $png(200, 10, 10), 'image/png');
$open = tests_put('Empresa/Teste33/thumbperm-' . $tag . '/ok.png', $png(10, 200, 10), 'image/png');
$eB = (string)(r2_head($bill)['etag'] ?? ''); $eO = (string)(r2_head($open)['etag'] ?? '');
t_true(preg_match(DRIVE_ETAG_RE, $eB) === 1 && preg_match(DRIVE_ETAG_RE, $eO) === 1 && $eB !== $eO, 'dois ficheiros com etags diferentes (' . $eB . ', ' . $eO . ')');
tests_put(DRIVE_THUMBS . $eB . '.jpg', $jpg(), 'image/jpeg');
tests_put(DRIVE_THUMBS . $eO . '.jpg', $jpg(), 'image/jpeg');

$api = fn(array $b, int $uid) => tests_http('POST', '/admin/drive_api.php', $b, $uid);
$fin = 1; $nofin = 3;
t_eq(tests_http('GET', '/admin/financeiro.php', [], $nofin)['code'], 403, 'o utilizador 3 não tem o Financeiro');

// com o Financeiro: tudo como antes
$c1 = $api(['do' => 'catalog', 'fresh' => true], $fin)['json'] ?? [];
t_true(in_array($eB, $c1['thumbs'] ?? [], true) && in_array($eO, $c1['thumbs'] ?? [], true), 'com o Financeiro: o catálogo traz as duas miniaturas');
$u1 = (array)($api(['do' => 'thumb_urls', 'etags' => [$eB, $eO]], $fin)['json']['urls'] ?? []);
t_true(!empty($u1[$eB]) && !empty($u1[$eO]), 'com o Financeiro: thumb_urls assina as duas');
$s1 = $api(['do' => 'thumb_sign', 'etag' => $eB, 'key' => $bill], $fin);
t_true($s1['code'] === 200 && !empty($s1['json']['url']) && !empty($s1['json']['get']), 'com o Financeiro: thumb_sign da Faturação (com a chave) continua');
t_true(!empty($api(['do' => 'thumb_sign', 'etag' => $eB], $fin)['json']['get']), 'com o Financeiro: sem a chave (browser com o JS antigo em cache) também');

// sem o Financeiro: a Faturação não aparece em lado nenhum
$c3 = $api(['do' => 'catalog'], $nofin)['json'] ?? [];
t_true(!in_array($bill, array_column($c3['objects'] ?? [], 'k'), true), 'sem o Financeiro: o ficheiro da Faturação não está no catálogo');
t_true(!in_array($eB, $c3['thumbs'] ?? [], true), 'sem o Financeiro: o etag da miniatura da Faturação também não (estava em catalog.thumbs)');
t_true(in_array($eO, $c3['thumbs'] ?? [], true), 'sem o Financeiro: a miniatura de um ficheiro visível continua no catálogo');
$r3 = $api(['do' => 'thumb_urls', 'etags' => [$eB, $eO]], $nofin);
$u3 = (array)($r3['json']['urls'] ?? []);
t_true(empty($u3[$eB]), 'sem o Financeiro: thumb_urls não assina a miniatura da Faturação (' . substr((string)($u3[$eB] ?? ''), 0, 60) . ')');
t_true(!empty($u3[$eO]), 'sem o Financeiro: thumb_urls continua a assinar a de um ficheiro visível');
// thumb_sign prova a chave: a pessoa tem de ver o ficheiro e o etag tem de ser o do objeto — nem às cegas (a miniatura
// órfã de um documento da Faturação no Lixo podia ser reescrita), nem com respostas diferentes que digam se um etag
// existe na Faturação (oráculo)
$sB = $api(['do' => 'thumb_sign', 'etag' => $eB], $nofin);
$sR = $api(['do' => 'thumb_sign', 'etag' => md5('qualquer-' . $tag)], $nofin);
t_true(empty($sB['json']['url']) && empty($sB['json']['get']), 'sem o Financeiro: o etag da Faturação sem chave não dá URL nenhum (' . $sB['code'] . ')');
t_eq([$sB['code'], (string)($sB['json']['error'] ?? '')], [$sR['code'], (string)($sR['json']['error'] ?? '')], 'e a resposta é a mesma de um etag qualquer (sem oráculo)');
$sBk = $api(['do' => 'thumb_sign', 'etag' => $eB, 'key' => $bill], $nofin);
t_true($sBk['code'] === 403 && empty($sBk['json']['url']), 'sem o Financeiro: com a chave da Faturação → 403 (' . $sBk['code'] . ')');
$sOk = $api(['do' => 'thumb_sign', 'etag' => $eO, 'key' => $open], $nofin);
t_true($sOk['code'] === 200 && !empty($sOk['json']['url']) && !empty($sOk['json']['get']), 'sem o Financeiro: um ficheiro visível (chave + etag certo) → escrever e ler');
$sWrong = $api(['do' => 'thumb_sign', 'etag' => $eB, 'key' => $open], $nofin);
t_true(empty($sWrong['json']['url']), 'sem o Financeiro: a chave de um ficheiro visível com o etag da Faturação não serve (' . $sWrong['code'] . ')');
// um envio acabado de chegar (ainda fora do catálogo de 30 s): a chave prova-o
$fresh = 'Empresa/Teste33/thumbperm-' . $tag . '/novo.png';
r2_put($fresh, $png(1, 2, 3), 'image/png');
$eF = (string)(r2_head($fresh)['etag'] ?? '');
$sF = $api(['do' => 'thumb_sign', 'etag' => $eF, 'key' => $fresh], $nofin);
t_true($sF['code'] === 200 && !empty($sF['json']['url']) && !empty($sF['json']['get']), 'sem o Financeiro: um envio novo (fora do catálogo) grava e lê a sua miniatura com a chave');
r2_delete($fresh);
// a miniatura órfã (o original já não está na Drive, p. ex. foi para o Lixo): nem por thumb_sign nem por thumb_urls
$orphan = md5('orfa-' . $tag) . '-1';
tests_put(DRIVE_THUMBS . $orphan . '.jpg', $jpg(), 'image/jpeg');
t_true(empty($api(['do' => 'thumb_sign', 'etag' => $orphan], $nofin)['json']['url']), 'sem o Financeiro: miniatura órfã → sem URL de escrita nem de leitura');
$u3x = (array)($api(['do' => 'thumb_urls', 'etags' => [$orphan]], $nofin)['json']['urls'] ?? []);
t_true(empty($u3x[$orphan]), 'sem o Financeiro: nem por thumb_urls');
r2_delete(DRIVE_THUMBS . $orphan . '.jpg');
// um etag com mudança de linha no fim não passa por válido (o «$» da expressão aceitava o «\n» final)
$nl = $api(['do' => 'thumb_sign', 'etag' => $eO . "\n", 'key' => $open], $nofin);
t_eq([$nl['code'], (string)($nl['json']['error'] ?? '')], [400, 'Miniatura inválida.'], 'etag com «\n» no fim → «Miniatura inválida.»');

// o mesmo conteúdo fora da Faturação (mesmo etag): esse ficheiro a pessoa vê, por isso a miniatura também
$copy = tests_put('Empresa/Teste33/thumbperm-' . $tag . '/copia.png', (string)r2_get($bill), 'image/png');
$c3b = $api(['do' => 'catalog', 'fresh' => true], $nofin)['json'] ?? [];
t_true(in_array($eB, $c3b['thumbs'] ?? [], true), 'uma cópia visível com o mesmo conteúdo traz a miniatura (é a mesma imagem que a pessoa já vê)');
r2_delete($copy); drive_catalog_invalidate();

r2_delete($bill); r2_delete($open); r2_delete(DRIVE_THUMBS . $eB . '.jpg'); r2_delete(DRIVE_THUMBS . $eO . '.jpg');
drive_catalog_invalidate();
t_done();
