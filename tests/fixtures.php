<?php
// Dados de teste na bancada (R2 simulado): ficheiros e pastas na Drive. Incluir depois de boot.php.
require_once __DIR__ . '/../admin/inc/r2.php';
require_once __DIR__ . '/../admin/inc/drive.php';

/** Cria (ou substitui) um ficheiro na Drive e devolve a chave. */
function tests_put(string $key, string $body = 'conteudo de teste', string $type = 'text/plain'): string {
    r2_put($key, $body, $type);
    drive_catalog_invalidate();
    return $key;
}
