<?php
// Conta-gotas (§33.14): um Topperboard próprio com a imagem 2400×1600 (metade vermelha #DC2828, metade azul #2F77B1),
// uma imagem ao alto 1200×2400 com 4 riscas (#DC2828, #2F77B1, #2CA02C, #FFC718) e um vídeo webm (metade vermelha,
// metade azul; o Chromium do Playwright não lê H.264). Título próprio: o seed_board.php não o apaga e os testes que
// usam o seed_board.php (com .ccc.t-image estrito) não veem estes cartões. Apaga o da vez anterior.
// Repõe a paleta das referências (sem cores manuais). Imprime JSON.
require __DIR__ . '/../boot.php';
require_once __DIR__ . '/../../admin/inc/cc.php';
require_once __DIR__ . '/../fixtures.php';
foreach ($pdo->query("SELECT id FROM cc_boards WHERE title LIKE 'Pick33 ·%'")->fetchAll(PDO::FETCH_COLUMN) as $old) {
    $pdo->exec('DELETE FROM cc_cards WHERE board_id = ' . (int)$old);
    $pdo->exec('DELETE FROM cc_comments WHERE board_id = ' . (int)$old);
    $pdo->exec('DELETE FROM cc_boards WHERE id = ' . (int)$old);
}
$b = cc_board_create($pdo, ['title' => 'Pick33 · conta-gotas', 'kind' => 'free'], 1);

$image = function (int $w, int $h, array $stripes, string $name, bool $vertical) use ($pdo): int {
    $im = imagecreatetruecolor($w, $h);
    $n = count($stripes);
    foreach ($stripes as $i => $c) {
        $col = imagecolorallocate($im, ...$c);
        if ($vertical) imagefilledrectangle($im, intdiv($w * $i, $n), 0, intdiv($w * ($i + 1), $n) - 1, $h - 1, $col);
        else imagefilledrectangle($im, 0, intdiv($h * $i, $n), $w - 1, intdiv($h * ($i + 1), $n) - 1, $col);
    }
    ob_start(); imagepng($im); $png = (string)ob_get_clean();
    [$asset, $restored] = tests_cc_asset($pdo, $png, ['name' => $name, 'mime' => 'image/png']);
    $aid = (int)$asset['id'];
    if (trim((string)$asset['thumbs']) === '' || $restored) {
        $pdo->prepare('UPDATE cc_assets SET thumbs = ? WHERE id = ?')->execute([json_encode(cc_thumbs_make($im, 't/' . substr((string)$asset['sha1'], 0, 2) . '/' . $asset['sha1'])), $aid]);
    }
    return $aid;
};
$R = [220, 40, 40]; $B = [47, 119, 177]; $G = [44, 160, 44]; $Y = [255, 199, 24];
$img = $image(2400, 1600, [$R, $B], 'pick33-meias.png', true);
$por = $image(1200, 2400, [$R, $B, $G, $Y], 'pick33retrato.png', true);
[$va] = tests_cc_asset($pdo, (string)file_get_contents(__DIR__ . '/../fixtures/pick_red_blue.webm'), ['name' => 'pick33-video.webm', 'mime' => 'video/webm']);
$vid = (int)$va['id'];
// sem cores manuais de corridas anteriores
foreach ([$img, $por, $vid] as $a) {
    $p = json_decode((string)$pdo->query("SELECT palette FROM cc_assets WHERE id = $a")->fetchColumn(), true) ?: [];
    $p = array_values(array_map(fn($c) => array_slice($c, 0, 5), array_filter($p, fn($c) => empty($c[5]['m']))));
    $pdo->prepare('UPDATE cc_assets SET palette = ?, deleted_at = NULL, inbox = 0 WHERE id = ?')->execute([json_encode($p), $a]);
}
$add = fn(array $d) => cc_card_add($pdo, $b, $d, 1);
$cards = [
    'image' => $add(['type' => 'image', 'x' => 40, 'y' => 40, 'w' => 300, 'asset_id' => $img]),
    'portrait' => $add(['type' => 'image', 'x' => 400, 'y' => 40, 'w' => 150, 'asset_id' => $por]),
    'video' => $add(['type' => 'video', 'x' => 600, 'y' => 40, 'w' => 300, 'asset_id' => $vid]),
];
cc_board_touch($pdo, $b);
echo json_encode(['board' => $b, 'assets' => ['image' => $img, 'portrait' => $por, 'video' => $vid], 'cards' => $cards]), "\n";
