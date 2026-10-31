<?php
// §32.4: «Descarregar tudo (.zip)» e «Descarregar seleção» no link partilhado — em streaming, com a estrutura de
// pastas; sem os privados; e nunca fora da pasta partilhada.
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/drive_store.php';

$S = '/tmp/claude-0/-home-user-portfolio-website/07a7b6fb-2708-535b-b71f-5de7fa1226a8/scratchpad';
$dir = 'Empresa/Teste32/zip-' . bin2hex(random_bytes(2)) . '/';
tests_put($dir . 'a.txt', 'A');
tests_put($dir . 'Sub/b.txt', str_repeat('B', 100000));
tests_put($dir . 'Sub/Fundo/c.txt', 'C');
r2_mkdir($dir . 'Vazia/');
$s = drive_share_create($pdo, $dir, true, 24, false, '', 'view', 1);
$P = '/admin/partilha.php?t=' . $s['token'];
$name = drive_base($dir);
$open = function (array $r, string $f) use ($S): ?ZipArchive {
    file_put_contents($S . '/' . $f, $r['body']);
    $z = new ZipArchive();
    return $z->open($S . '/' . $f, ZipArchive::CHECKCONS) === true ? $z : null;
};

$r = tests_http('GET', $P . '&zip=1', [], 0);
t_eq($r['code'], 200, 'zip=1 responde');
$z = $open($r, 'share-all.zip');
t_true($z !== null, 'é um ZIP válido');
if ($z) {
    t_eq($z->getFromName("$name/a.txt"), 'A', 'a.txt na raiz da pasta');
    t_eq(strlen((string)$z->getFromName("$name/Sub/b.txt")), 100000, 'Sub/b.txt inteiro');
    t_eq($z->getFromName("$name/Sub/Fundo/c.txt"), 'C', 'a estrutura de pastas mantém-se');
    t_true($z->locateName("$name/Vazia/") !== false, 'a pasta vazia também');
}
t_true(str_contains($r['headers']['content-disposition'] ?? '', $name . '.zip'), 'com o nome da pasta .zip (' . ($r['headers']['content-disposition'] ?? '') . ')');
t_eq($r['headers']['content-type'] ?? '', 'application/zip', 'tipo application/zip');

// só uma subpasta
$z = $open(tests_http('GET', $P . '&zip=1&p=' . rawurlencode('Sub/'), [], 0), 'share-sub.zip');
t_true($z && $z->getFromName('Sub/Fundo/c.txt') === 'C' && $z->locateName("$name/a.txt") === false, 'zip de uma subpasta só tem essa subpasta');

// a seleção (POST)
$r = tests_http('POST', $P . '&zip=1', ['sel' => ['a.txt', 'Sub/Fundo/']], 0, true);
$z = $open($r, 'share-sel.zip');
t_true($z !== null, 'a seleção é um ZIP válido');
if ($z) {
    t_eq($z->numFiles >= 2, true, 'tem os itens escolhidos');
    t_eq($z->getFromName("$name/a.txt"), 'A', 'o ficheiro escolhido');
    t_eq($z->getFromName("$name/Sub/Fundo/c.txt"), 'C', 'a pasta escolhida, com o caminho');
    t_true($z->locateName("$name/Sub/b.txt") === false, 'e nada do que não foi escolhido');
}
t_eq(tests_http('POST', $P . '&zip=1', ['sel' => ['../fora.txt']], 0, true)['code'], 404, 'seleção fora da pasta → 404');
t_eq(tests_http('GET', $P . '&zip=1&p=' . rawurlencode('../'), [], 0)['code'], 404, 'zip acima da pasta → 404');

// sem os privados
$priv = tests_put('Empresa/Documentos legais/zip-priv-' . bin2hex(random_bytes(2)) . '.txt', 'segredo');
$e = drive_share_create($pdo, 'Empresa/Teste32/', true, 24, false, '', 'view', 1);
$e2 = drive_share_create($pdo, 'Empresa/', true, 24, false, '', 'view', 1);
$r = tests_http('POST', '/admin/partilha.php?t=' . $e2['token'] . '&zip=1', ['sel' => ['Documentos legais/']], 0, true);
t_eq($r['code'], 404, 'escolher a pasta privada → 404');

// as partes: com o limite baixo (só na bancada), uma parte por ficheiro
$r = tests_http('GET', $P . '&zip=1&maxfiles=2', [], 0);
t_true(str_contains($r['body'], 'part=2') && str_contains($r['body'], 'Parte 1'), 'acima do limite mostra as partes');
$z = $open(tests_http('GET', $P . '&zip=1&maxfiles=2&part=2', [], 0), 'share-p2.zip');
t_true($z && $z->numFiles >= 1, 'a parte 2 é um ZIP válido');

// revisão final: acima do limite de objetos não sai um ZIP incompleto (limite baixo só na bancada)
$r = tests_http('GET', $P . '&zip=1&maxobjs=2', [], 0);
t_true($r['code'] === 413 && str_contains($r['body'], 'grande demais') && !str_starts_with($r['body'], 'PK'), 'pasta grande demais → 413 com mensagem, nunca um ZIP a meio (' . $r['code'] . ')');
$r = tests_http('POST', $P . '&zip=1&maxobjs=2', ['sel' => ['Sub/', 'a.txt']], 0, true);
t_eq($r['code'], 413, 'o mesmo na seleção');
// link com password: o ZIP só depois da password; um POST da seleção sem ela não conta como tentativa errada
$pw = drive_share_create($pdo, $dir, true, 24, false, 'segredo123', 'view', 1);
$PW = '/admin/partilha.php?t=' . $pw['token'];
$fails = fn() => (int)$pdo->query('SELECT pw_fails FROM drive_shares WHERE id = ' . (int)$pw['id'])->fetchColumn();
$r = tests_http('POST', $PW . '&zip=1', ['sel' => ['a.txt']], 0, true);
t_true(!str_starts_with($r['body'], 'PK') && str_contains($r['body'], 'password'), 'sem password: pede a password, não dá o ZIP');
t_eq($fails(), 0, 'e não conta como tentativa errada');
$jar = tempnam(sys_get_temp_dir(), 'pw');
$c = curl_init(TESTS_BASE . $PW); curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'pw=segredo123', CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true]); curl_exec($c); curl_close($c);
$c = curl_init(TESTS_BASE . $PW . '&zip=1'); curl_setopt_array($c, [CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true]); $zb = (string)curl_exec($c); curl_close($c);
t_true(str_starts_with($zb, 'PK'), 'com a password dada: o ZIP vem');
$pdo->exec('UPDATE drive_shares SET revoked = 1 WHERE id = ' . (int)$pw['id']);

// revogado → 410
$pdo->prepare('UPDATE drive_shares SET revoked = 1 WHERE id IN (?,?,?)')->execute([$s['id'], $e['id'], $e2['id']]);
t_eq(tests_http('GET', $P . '&zip=1', [], 0)['code'], 410, 'link revogado → 410');
foreach (r2_list_all($dir, 5) as $o) r2_delete($o['key']);
r2_delete($priv);
drive_catalog_invalidate();
t_done();
