<?php
/**
 * topper. admin — trazer os dados da folha Google para a base (uma vez, na passagem).
 *
 *   php admin/import_sheet.php                    ensaio: lê a folha, importa numa transação,
 *                                                  confere as contas e desfaz tudo (nada fica gravado)
 *   php admin/import_sheet.php --apply            importa a sério (só se as tabelas estiverem vazias
 *                                                  e todas as contas baterem com as da folha)
 *   php admin/import_sheet.php --apply --replace  apaga o que já houver nas tabelas e importa de novo
 *   … --force                                     grava mesmo com diferenças (não usar sem ler o relatório)
 *
 * O relatório sai no ecrã e fica também em inc/cache/import-<data>.txt.
 * Só corre pela linha de comandos: pela web responde 403.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/sheet.php';
require_once __DIR__ . '/inc/data_import.php';

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$replace = in_array('--replace', $args, true);
$force = in_array('--force', $args, true);

try {
    $sheet = sheet_map(sheet_read(true));
} catch (Throwable $e) {
    fwrite(STDERR, 'Não foi possível ler a folha: ' . $e->getMessage() . "\n");
    exit(1);
}
$rep = data_import($pdo, $sheet, $apply, $replace, $force);
$txt = data_import_text($rep);
echo $txt;
$dir = __DIR__ . '/inc/cache';
if (is_dir($dir) || @mkdir($dir, 0700, true)) @file_put_contents($dir . '/import-' . gmdate('Ymd-His') . '.txt', $txt);
exit($apply && !$rep['applied'] ? 2 : 0);
