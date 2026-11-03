<?php
// Seletor «Juntar da Drive» (§33.9): um Topperboard próprio (com uma nota) e uma pasta própria na Drive, únicos por
// corrida (nada se apaga): três PNG (o «a» já com miniatura da Drive em .topper/thumbs/<etag>.jpg, verde, para se ver
// que é a miniatura e não o original), um PDF, um MP3, um TXT e um WebM (o Chromium do Playwright não lê H.264).
// Opções: many → 80 PNG pequenos em muitos/ (carregamento preguiçoso); bill → um PNG da Faturação com miniatura.
// Uso: php tests/ui/seed_drive_pick.php <tag> [many] [bill]   Imprime JSON.
require __DIR__ . '/../boot.php';
require_once __DIR__ . '/../../admin/inc/cc.php';
require_once __DIR__ . '/../fixtures.php';
$tag = preg_replace('/[^a-z0-9]/', '', strtolower((string)($argv[1] ?? ''))) ?: 'dp' . bin2hex(random_bytes(3));
$opt = array_slice($argv, 2);
$b = cc_board_create($pdo, ['title' => 'T6 · Drive · ' . $tag, 'kind' => 'free'], 1);
cc_card_add($pdo, $b, ['type' => 'note', 'x' => 0, 'y' => 0, 'content' => ['text' => 'Nota ' . $tag]], 1);
$dir = 'Projetos/T6-' . $tag . '/';

/** PNG com uma cor (e um píxel ao acaso, para o etag ser só deste ficheiro). */
$png = function (int $w, int $h, array $rgb): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, ...$rgb));
    imagesetpixel($im, 1, 1, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    imagesetpixel($im, 2, 1, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    ob_start(); imagepng($im); return (string)ob_get_clean();
};
$jpg = function (int $w, int $h, array $rgb): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, ...$rgb));
    ob_start(); imagejpeg($im, null, 80); return (string)ob_get_clean();
};
$etag = fn(string $k) => (string)(r2_head($k)['etag'] ?? '');
$out = ['b' => $b, 'tag' => $tag, 'dir' => $dir, 'k' => [], 'e' => []];
$put = function (string $name, string $body, string $type) use (&$out, $dir, $etag) {
    $k = tests_put($dir . $name, $body, $type);
    $out['k'][$name] = $k;
    $out['e'][$name] = $etag($k);
    return $k;
};
$put($tag . '-a.png', $png(400, 300, [200, 60, 60]), 'image/png');
$put($tag . '-b.png', $png(300, 400, [60, 90, 200]), 'image/png');
$put($tag . '-c.png', $png(320, 320, [240, 200, 40]), 'image/png');
$put($tag . '-x.pdf', "%PDF-1.4\n% " . $tag . "\n", 'application/pdf');
$put($tag . '-s.mp3', 'ID3' . str_repeat("\0", 7) . random_bytes(64), 'audio/mpeg');
$put($tag . '-n.txt', 'notas ' . $tag, 'text/plain');
// a miniatura do «a» já existe (feita antes na Drive): verde, 320×240
tests_put(DRIVE_THUMBS . $out['e'][$tag . '-a.png'] . '.jpg', $jpg(320, 240, [40, 170, 80]), 'image/jpeg');
// vídeo WebM de 2 s com uma cor ao acaso
$webm = sys_get_temp_dir() . '/t6-' . $tag . '.webm';
$col = sprintf('0x%02x%02x%02x', random_int(0, 255), random_int(0, 255), random_int(0, 255));
@exec('ffmpeg -hide_banner -loglevel error -y -f lavfi -i ' . escapeshellarg('color=c=' . $col . ':s=320x240:d=2:r=10') . ' -c:v libvpx -b:v 100k ' . escapeshellarg($webm) . ' 2>&1', $o, $rc);
if ($rc === 0 && is_file($webm)) { $put($tag . '-v.webm', (string)file_get_contents($webm), 'video/webm'); @unlink($webm); }
if (in_array('many', $opt, true)) {
    for ($i = 1; $i <= 80; $i++) {
        $k = tests_put($dir . 'muitos/' . sprintf('%sm-%03d.png', $tag, $i), $png(48, 36, [random_int(0, 255), random_int(0, 255), random_int(0, 255)]), 'image/png');
        $out['many'][] = $k;
        $out['me'][] = $etag($k);
    }
}
if (in_array('bill', $opt, true)) {
    $bk = tests_put('Empresa/Financeiro/2026/11/' . $tag . '-fat.png', $png(400, 560, [250, 250, 250]), 'image/png');
    $out['bill'] = $bk;
    $out['billEtag'] = $etag($bk);
    tests_put(DRIVE_THUMBS . $out['billEtag'] . '.jpg', $jpg(228, 320, [250, 250, 250]), 'image/jpeg');
}
drive_catalog_invalidate();
echo json_encode($out);
