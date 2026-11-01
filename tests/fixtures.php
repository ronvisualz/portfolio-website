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

/**
 * Referência do Toppernote para os testes. A bancada reaproveita a referência pela impressão digital, mas o R2
 * simulado vive em memória: depois de reiniciar a bancada, o objeto pode já não existir. Nesse caso volta a pô-lo.
 * Devolve [referência, se foi preciso repor]. Precisa de admin/inc/cc.php.
 */
function tests_cc_asset(PDO $pdo, string $bytes, array $meta): array {
    [$asset] = cc_asset_create($pdo, $bytes, $meta);
    if (!is_array($asset)) $asset = cc_asset_get($pdo, (int)$asset);
    $key = (string)($asset['file_key'] ?? '');
    $restored = false;
    if (str_starts_with($key, 'r2:') && !r2_head(substr($key, 3))) {
        r2_put(substr($key, 3), $bytes, (string)($meta['mime'] ?? 'application/octet-stream'));
        $restored = true;
    }
    return [$asset, $restored];
}
