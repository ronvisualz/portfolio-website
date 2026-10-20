<?php
/**
 * topper. admin — o índice de conteúdo do Drive pela linha de comandos (cron opcional).
 *
 *   php admin/index_cron.php [segundos]     (por omissão 50; máximo 3500)
 *
 * Faz o mesmo que o Drive aberto no browser faz de 4 em 4 segundos: reconcilia o
 * índice com o catálogo e lê os ficheiros pendentes, durante o tempo indicado.
 * No hPanel da Hostinger (Avançado › Tarefas Cron), por exemplo a cada 15 minutos:
 *   /usr/bin/php /home/<utilizador>/public_html/admin/index_cron.php 240
 * Só corre pela linha de comandos: pela web responde 403.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/drive_store.php';
require_once __DIR__ . '/inc/drive_index.php';

$seconds = max(5, min(3500, (int)($argv[1] ?? 50)));
if (!r2_config()) { fwrite(STDERR, "O Drive não está configurado (falta inc/r2_config.php).\n"); exit(1); }
@set_time_limit($seconds + 300);

$end = time() + $seconds;
$total = 0;
$s = null;
do {
    try {
        $s = drive_index_tick($pdo, max(1, min(30, $end - time())));
    } catch (Throwable $e) {
        fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $total += (int)$s['processed'];
    if (!empty($s['busy'])) { sleep(5); continue; }            // o browser está a ler: espera a vez
    if ((int)$s['pending'] === 0 || $s['paused'] !== null || (int)$s['processed'] === 0) break;
} while (time() < $end);

printf("Índice: %d de %d ficheiros lidos · %d pendentes · %d com erro · %d sem texto · %d privados · %d lidos agora%s\n",
    $s['done'], $s['total'], $s['pending'], $s['errors'], $s['skipped'], $s['private'], $total,
    $s['paused'] !== null ? ' · em pausa: ' . $s['paused'] : '');
