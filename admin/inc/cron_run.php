<?php
/**
 * topper. admin — tarefas pela linha de comandos (cron do hPanel)
 *
 *   php …/admin/inc/cron_run.php backup    cópia de segurança da base para o R2 (todas as noites) e,
 *                                          a seguir, os follow-ups de relação que chegaram à data
 *   php …/admin/inc/cron_run.php relations só os follow-ups de relação (§13.5)
 *   php …/admin/inc/cron_run.php pipeline  só as automações de data e mensais do Pipeline (§19.7);
 *                                          também correm todas as noites, com a cópia
 *   php …/admin/inc/cron_run.php cc [seg]  as pastas do Drive a mudar de sítio (arquivar, reabrir,
 *                                          converter) e a fila da IA do Centro Criativo (análises,
 *                                          embeddings, importações) durante [seg] segundos (240)
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
require __DIR__ . '/contacts.php';
require __DIR__ . '/pipeline_flow.php';

$job = $argv[1] ?? '';
$out = '';
$code = 0;
try {
    if ($job === 'backup') {
        $out = 'Cópia guardada: ' . backup_to_r2($pdo) . "\n";
        $out .= 'Follow-ups de relação criados: ' . relation_tick($pdo) . "\n";
        $out .= 'Tarefas automáticas (datas e avenças): ' . flow_tick($pdo) . "\n";
    } elseif ($job === 'pipeline') {
        $out = 'Tarefas automáticas (datas e avenças): ' . flow_tick($pdo) . "\n";
    } elseif ($job === 'cc') {
        require_once __DIR__ . '/cc.php';
        $sec = max(10, min(3000, (int)($argv[2] ?? 240)));
        @set_time_limit($sec + 600);
        // primeiro as pastas que mudam de sítio (arquivar, reabrir, converter — §10.15), depois a IA
        // tudo junto cabe em [seg] (o cron corre de 5 em 5 minutos: não se sobrepõe)
        require_once __DIR__ . '/files.php';
        $t0 = microtime(true);
        $out = '';
        if (files_ready($pdo) && files_on()) {
            require_once __DIR__ . '/files_reorg.php';
            if (files_reorg_ready($pdo)) {
                // a reorganização (se houver) e as pastas na fila
                $rs = files_reorg_run($pdo, (float)min(90, $sec / 2));
                $out .= 'Drive: ' . files_moves_pending($pdo) . ' pastas na fila' . (!empty($rs['queued']) ? ' · reorganização: ' . $rs['queued'] . ' itens por fazer' : '') . "\n";
            } else {
                $fm = files_moves_run($pdo, (float)min(90, $sec / 2));
                $out .= 'Drive: ' . $fm . ' pastas mudadas · ' . files_moves_pending($pdo) . " na fila\n";
            }
        }
        // o Review (§24): o cron diz às páginas se há ffmpeg (elas não correm programas; o cron sim)
        require_once __DIR__ . '/cc_review.php';
        if (cc_rv_ready($pdo)) {
            $ff = cc_rv_bin('ffmpeg') && cc_rv_bin('ffprobe') && function_exists('proc_open') ? (string)cc_rv_bin('ffmpeg') : '';
            require_once __DIR__ . '/ai.php';
            ai_setting_set($pdo, 'cc_ffmpeg', $ff);
            $out .= 'Review: ' . ($ff !== '' ? 'ffmpeg em ' . $ff : 'sem ffmpeg (vídeos preparados no browser)') . "\n";
        }
        $left = max(5.0, $sec - (microtime(true) - $t0));
        $out .= cc_ready($pdo) ? 'Centro Criativo: ' . cc_jobs_run($pdo, $left) . ' trabalhos feitos · ' . cc_jobs_pending($pdo) . " na fila\n" : "Centro Criativo: a base ainda não está no passo 24.\n";
    } elseif ($job === 'relations') {
        $out = 'Follow-ups de relação criados: ' . relation_tick($pdo) . "\n";
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
        fwrite(STDERR, "Uso: php cron_run.php backup|relations|pipeline|cc|plan|migrate\n");
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
