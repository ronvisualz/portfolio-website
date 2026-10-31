<?php
// §32.4: o ZIP em streaming — «store», descritor de dados, CRC32, ZIP64 a partir de um limiar; se a leitura falhar a
// meio, o ficheiro fica sem o diretório central (o browser dá erro em vez de um ZIP «bom» mas incompleto).
require __DIR__ . '/lib.php';
define('TOPPER_ADMIN', true);
require __DIR__ . '/../admin/inc/zipstream.php';

$S = '/tmp/claude-0/-home-user-portfolio-website/07a7b6fb-2708-535b-b71f-5de7fa1226a8/scratchpad';
$files = [
    'galeria/a.txt' => 'olá mundo',
    'galeria/Sub/ção ñ 日本.txt' => str_repeat("linha\n", 5000),
    'galeria/b.bin' => random_bytes(70000),
];
$make = function (int $zip64At) use ($files): string {
    $buf = '';
    $z = new ZipStream(function (string $s) use (&$buf) { $buf .= $s; }, $zip64At);
    foreach ($files as $n => $body) {
        $z->addFile($n, 1793000000, function (callable $sink) use ($body) { foreach (str_split($body, 8192) as $c) $sink($c); });
    }
    $z->addDir('galeria/Vazia/', 1793000000);
    $z->finish();
    return $buf;
};
foreach ([0xFFFFFFFF => 'normal', 10 => 'ZIP64'] as $at => $label) {
    $path = $S . '/zs-' . $label . '.zip';
    file_put_contents($path, $make($at));
    $za = new ZipArchive();
    t_eq($za->open($path, ZipArchive::CHECKCONS), true, "$label: o ZipArchive abre (com verificação)");
    t_eq($za->numFiles, 4, "$label: 3 ficheiros + 1 pasta vazia");
    foreach ($files as $n => $body) {
        t_true($za->getFromName($n) === $body, "$label: conteúdo de «{$n}» igual");
        t_eq($za->statName($n)['crc'] ?? null, crc32($body), "$label: CRC de «{$n}»");
    }
    t_true($za->locateName('galeria/Vazia/') !== false, "$label: a pasta vazia vem no ZIP");
    $za->close();
    $out = []; $rc = 0;
    exec('unzip -t ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    t_eq($rc, 0, "$label: unzip -t sem erros (" . trim((string)end($out)) . ')');
}
t_true(str_contains($make(10), "PK\x06\x06"), 'com o limiar baixo escreve o registo ZIP64');
t_true(!str_contains($make(0xFFFFFFFF), "PK\x06\x06"), 'abaixo do limiar não usa ZIP64');

// a leitura falha a meio → exceção e nada de fim de ficheiro
$buf = '';
$z = new ZipStream(function (string $s) use (&$buf) { $buf .= $s; });
$z->addFile('ok.txt', time(), fn($sink) => $sink('ok'));
$threw = false;
try { $z->addFile('falha.txt', time(), function ($sink) { $sink('meio'); throw new RuntimeException('R2 caiu'); }); }
catch (RuntimeException $e) { $threw = true; }
t_true($threw, 'a falha da leitura chega a quem chamou');
t_true(!str_contains($buf, "PK\x05\x06") && !str_contains($buf, "PK\x01\x02"), 'sem diretório central nem fim de ficheiro');
$late = false;
try { $z->finish(); } catch (LogicException $e) { $late = true; }
t_true($late, 'depois de uma falha já não fecha o ZIP');

// tamanho declarado diferente do lido (o ficheiro mudou entretanto) → exceção
$z = new ZipStream(fn($s) => null);
$bad = false;
try { $z->addFile('x.txt', time(), fn($sink) => $sink('12345'), 3); } catch (RuntimeException $e) { $bad = true; }
t_true($bad, 'tamanho lido diferente do esperado → exceção');
t_done();
