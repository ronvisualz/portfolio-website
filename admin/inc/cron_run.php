<?php
/**
 * topper. admin — tarefas pela linha de comandos (cron do hPanel)
 *
 *   php …/admin/inc/cron_run.php backup    cópia de segurança da base para o R2 (todas as noites)
 *   php …/admin/inc/cron_run.php plan      mostra o plano da migração para a plataforma, sem escrever
 *   php …/admin/inc/cron_run.php migrate   faz a migração com as escolhas por omissão (uma vez)
 *
 * A pasta inc/ está fechada à web pelo .htaccess, e este ficheiro recusa
 * tudo o que não seja CLI. O resultado fica também em inc/cache/cron-<tarefa>.txt.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/export.php';
require __DIR__ . '/platform_import.php';

$job = $argv[1] ?? '';
$out = '';
$code = 0;
try {
    if ($job === 'backup') {
        $out = 'Cópia guardada: ' . backup_to_r2($pdo) . "\n";
    } elseif ($job === 'plan' || $job === 'migrate') {
        if ($d = platform_migrated($pdo)) {
            $out = "A migração já foi feita a $d.\n";
        } else {
            $opt = platform_default_options($pdo);
            $plan = platform_plan($pdo, $opt);
            $out = 'Plano: ' . json_encode($plan['counts']) . "\n";
            foreach ($plan['jobs'] as $j) $out .= sprintf("  %s  %s  (%d/%s, funil %d)\n", $j['code'] ?? '—', $j['name'], $j['year'], $j['month'] ?? '—', $j['funnel_id']);
            foreach ($plan['issues'] as $i) $out .= "  ! $i\n";
            if ($job === 'migrate') {
                $opt['drive_folders'] = platform_drive_folders($plan['jobs']);
                $s = platform_migrate($pdo, $opt);
                $out .= 'MIGRADO E CONFERIDO: ' . json_encode($s) . "\n";
            }
        }
    } else {
        fwrite(STDERR, "Uso: php cron_run.php backup|plan|migrate\n");
        exit(2);
    }
} catch (Throwable $e) {
    $out .= $job . ' falhou: ' . $e->getMessage() . "\n";
    $code = 1;
}
echo $out;
$dir = __DIR__ . '/cache';
if (is_dir($dir) || @mkdir($dir, 0700, true)) @file_put_contents($dir . '/cron-' . preg_replace('/\W/', '', $job) . '.txt', date('c') . "\n" . $out);
exit($code);
