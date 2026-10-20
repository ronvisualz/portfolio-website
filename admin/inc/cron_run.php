<?php
/**
 * topper. admin — tarefas noturnas (só pela linha de comandos)
 *
 *   php …/admin/inc/cron_run.php backup   cópia de segurança da base para o R2
 *   php …/admin/inc/cron_run.php export   espelho MySQL → folha Google
 *
 * Marcadas no hPanel (Avançado › Cron Jobs). A pasta inc/ está fechada à
 * web pelo .htaccess, e este ficheiro recusa tudo o que não seja CLI.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/export.php';

$job = $argv[1] ?? '';
try {
    if ($job === 'backup') {
        echo 'Cópia guardada: ' . backup_to_r2($pdo) . "\n";
    } elseif ($job === 'export') {
        foreach (export_to_sheet($pdo) as $tab => $n) echo "$tab: $n linhas\n";
    } else {
        fwrite(STDERR, "Uso: php cron_run.php backup|export\n");
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $job . ' falhou: ' . $e->getMessage() . "\n");
    exit(1);
}
