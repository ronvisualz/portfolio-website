<?php
// Uma pasta partilhada com imagens, um vídeo, um PDF, um texto e uma subpasta; imprime os dois links (Só ver e Pode editar).
require __DIR__ . '/../boot.php';
require __DIR__ . '/../fixtures.php';
require_once __DIR__ . '/../../admin/inc/drive_store.php';
$dir = 'Empresa/Teste32/galeria/';
foreach (r2_list_all($dir, 2) as $o) if (preg_match('~/enviado-\d+(?: \(\d+\))?\.txt$~', $o['key'])) r2_delete($o['key']);   // o que o teste anterior carregou
$png = function (array $rgb): string { $im = imagecreatetruecolor(320, 200); imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb)); ob_start(); imagepng($im); return (string)ob_get_clean(); };
tests_put($dir . '01-vermelho.png', $png([220, 40, 40]), 'image/png');
tests_put($dir . '02-azul.png', $png([40, 80, 220]), 'image/png');
tests_put($dir . '03-filme.mp4', str_repeat("\0", 2048), 'video/mp4');
tests_put($dir . '04-documento.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF", 'application/pdf');
tests_put($dir . '05-notas.txt', 'notas');
tests_put($dir . 'Sub/dentro.txt', 'dentro');
foreach (['view', 'edit'] as $perm) {
    $st = $pdo->prepare('SELECT token FROM drive_shares WHERE obj_key = ? AND perm = ? AND revoked = 0 AND expires_at > ? ORDER BY id DESC LIMIT 1');
    $st->execute([$dir, $perm, drive_now(3600)]);
    $tok = $st->fetchColumn() ?: drive_share_create($pdo, $dir, true, 168, false, '', $perm, 1)['token'];
    echo '/admin/partilha.php?t=' . $tok, "\n";
}
