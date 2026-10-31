<?php
// Um Topperboard livre com nota, lista (3 itens), amostra, desenho, imagem e sub-Topperboard (§33, testes de UI).
// Apaga o semeado da vez anterior. Imprime o id do Topperboard.
require __DIR__ . '/../boot.php';
require_once __DIR__ . '/../../admin/inc/cc.php';
$T = 'Teste33 · semeado';
foreach ($pdo->query("SELECT id FROM cc_boards WHERE title LIKE 'Teste33 ·%'")->fetchAll(PDO::FETCH_COLUMN) as $old) {
    $pdo->exec('DELETE FROM cc_cards WHERE board_id = ' . (int)$old);
    $pdo->exec('DELETE FROM cc_comments WHERE board_id = ' . (int)$old);
    $pdo->exec('DELETE FROM cc_boards WHERE id = ' . (int)$old);
}
$b = cc_board_create($pdo, ['title' => $T, 'kind' => 'free'], 1);
$sub = cc_board_create($pdo, ['title' => 'Teste33 · Luz', 'kind' => 'free', 'parent_id' => $b], 1);

// imagem 2400×1600: metade esquerda vermelha, direita azul (serve aos testes de media e conta-gotas)
$im = imagecreatetruecolor(2400, 1600);
imagefilledrectangle($im, 0, 0, 1199, 1599, imagecolorallocate($im, 220, 40, 40));
imagefilledrectangle($im, 1200, 0, 2399, 1599, imagecolorallocate($im, 47, 119, 177));
ob_start(); imagepng($im); $png = (string)ob_get_clean();
[$asset] = cc_asset_create($pdo, $png, ['name' => 'teste33.png', 'mime' => 'image/png']);
$aid = (int)(is_array($asset) ? $asset['id'] : $asset);

$add = fn(array $d) => cc_card_add($pdo, $b, $d, 1);
$ids = [
    'note' => $add(['type' => 'note', 'x' => 40, 'y' => 120, 'content' => ['text' => 'Um exemplo de nota']]),
    'list' => $add(['type' => 'list', 'x' => 320, 'y' => 120, 'content' => ['title' => 'Tarefas', 'items' => [['t' => 'Primeiro'], ['t' => 'Segundo'], ['t' => 'Terceiro']]]]),
    'swatch' => $add(['type' => 'swatch', 'x' => 620, 'y' => 120, 'content' => ['hex' => '#FFC718', 'name' => 'Amarelo']]),
    'drawing' => $add(['type' => 'drawing', 'x' => 40, 'y' => 420, 'w' => 320, 'h' => 200, 'content' => ['paths' => [['c' => '#111111', 'w' => 3, 'pts' => [10, 10, 160, 100, 310, 190]]]]]),
    'image' => $add(['type' => 'image', 'x' => 420, 'y' => 420, 'w' => 300, 'asset_id' => $aid]),
    'board' => $add(['type' => 'board', 'x' => 780, 'y' => 420, 'ref_board_id' => $sub, 'content' => ['title' => 'Teste33 · Luz']]),
];
cc_board_touch($pdo, $b);
echo $b, "\n";
