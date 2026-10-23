<?php
/**
 * topper. admin — Centro Criativo: transcrição da fala com marcas de tempo (§27.4)
 *
 * O Claude não recebe áudio: a fala passa por um serviço de reconhecimento com a API de transcrição
 * da OpenAI (Whisper, ou um compatível como o Groq), configurado em cc_config.php (transcribe_*),
 * só no servidor. Serve o áudio e o vídeo (o campo transcript que o vídeo já tinha).
 *
 * O texto fica em cc_assets.transcript, uma linha por frase com o tempo: «[1:23] …» — pesquisável,
 * copiável e com o tempo clicável no detalhe. Conta no gasto e no limite mensal da IA do Centro Criativo.
 *
 * Ficheiros até 25 MB num formato aceite vão diretos (também a partir das páginas); os outros
 * (aif, vídeos, ficheiros grandes) passam primeiro pelo ffmpeg, só pela linha de comandos (cron).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/cc_ai.php';

const CC_STT_URL = 'https://api.openai.com/v1/audio/transcriptions';
const CC_STT_MODEL = 'whisper-1';
const CC_STT_USD_MIN = 0.006;
const CC_STT_MAX = 26214400;   // 25 MB por pedido
const CC_STT_DIRECT = ['mp3', 'mpga', 'mpeg', 'm4a', 'mp4', 'wav', 'webm', 'ogg', 'oga', 'flac'];

/** A configuração do serviço de fala, ou null. */
function cc_stt_config(): ?array {
    $raw = cc_ai_raw_config();
    $key = trim((string)($raw['transcribe_key'] ?? ''));
    if (!cc_key_ok($key)) return null;
    $url = trim((string)($raw['transcribe_url'] ?? '')) ?: CC_STT_URL;
    if (!preg_match('~^https://[A-Za-z0-9.-]+(:\d+)?/\S*$~', $url) && !preg_match('~^http://(127\.0\.0\.1|localhost)(:\d+)?/~', $url)) return null;
    $model = trim((string)($raw['transcribe_model'] ?? ''));
    $lang = strtolower(trim((string)($raw['transcribe_lang'] ?? '')));
    return ['key' => $key, 'url' => $url, 'model' => preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{1,63}$/', $model) ? $model : CC_STT_MODEL,
            'usd_min' => is_numeric($raw['transcribe_usd_min'] ?? null) ? max(0.0, (float)$raw['transcribe_usd_min']) : CC_STT_USD_MIN,
            'lang' => preg_match('/^[a-z]{2}$/', $lang) ? $lang : '', 'provider' => preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($raw['transcribe_provider'] ?? 'openai'))) ?: 'openai',
            'auto' => !array_key_exists('auto_analyze', $raw) || !empty($raw['auto_analyze'])];
}

/** O tipo de trabalho para transcrever esta referência: 'stt' (direto), 'stt_ff' (precisa do ffmpeg, cron) ou ''. */
function cc_stt_job_kind(array $a): string {
    if (!in_array($a['kind'], ['audio', 'video'], true) || (string)$a['file_key'] === '') return '';
    $ext = strtolower(pathinfo((string)$a['file_name'], PATHINFO_EXTENSION));
    $size = (int)$a['bytes'];
    return $a['kind'] === 'audio' && in_array($ext, CC_STT_DIRECT, true) && $size > 0 && $size <= CC_STT_MAX ? 'stt' : 'stt_ff';
}

/**
 * Põe a referência na fila da IA: com serviço de fala, primeiro a transcrição (que depois chama
 * a análise); sem ele, a análise. $force: mesmo com a análise automática desligada (pedido à mão).
 */
function cc_ai_queue_asset(PDO $pdo, array $a, ?int $by, bool $force = false): string {
    $stt = cc_stt_config();
    $cfg = cc_ai_config();
    if ($stt && ($force || $stt['auto']) && (string)($a['transcript'] ?? '') === '' && ($k = cc_stt_job_kind($a)) !== '') {
        if ($k === 'stt_ff' && !cc_ffmpeg_ok($pdo)) $k = '';
        if ($k !== '') {
            $pdo->prepare("UPDATE cc_assets SET ai_status = 'queued', ai_error = '' WHERE id = ?")->execute([(int)$a['id']]);
            cc_job_add($pdo, $k, (int)$a['id'], null, null, $by);
            return $k;
        }
    }
    if ($cfg && ($force || $cfg['auto']) && ($a['thumbs'] || in_array($a['kind'], ['link', 'audio'], true))) {
        $pdo->prepare("UPDATE cc_assets SET ai_status = 'queued', ai_error = '' WHERE id = ?")->execute([(int)$a['id']]);
        cc_job_add($pdo, 'analyze', (int)$a['id'], null, null, $by);
        return 'analyze';
    }
    return '';
}

/**
 * A forma de onda pelo ffmpeg (cron), para o que o browser não lê (AIFF no Chrome…): mono a 2 kHz,
 * 600 picos 0–255 (a mesma escala do cc_audio.js). Devolve [picos em base64, duração] ou null.
 */
function cc_audio_peaks_ff(string $ff, string $src): ?array {
    $p = proc_open([$ff, '-hide_banner', '-loglevel', 'error', '-i', $src, '-vn', '-ac', '1', '-ar', '2000', '-t', '14400', '-f', 's16le', '-'],
                   [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return null;
    $raw = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $n = intdiv(strlen($raw), 2);
    if ($n < 200) return null;
    $per = (int)ceil($n / 600); $pk = []; $top = 1;
    for ($i = 0; $i < 600; $i++) {
        $chunk = substr($raw, $i * $per * 2, $per * 2);
        $m = 0;
        if ($chunk !== '' && strlen($chunk) >= 2) foreach (unpack('s*', $chunk) as $v) { $v = abs($v); if ($v > $m) $m = $v; }
        $pk[] = $m; if ($m > $top) $top = $m;
    }
    $out = '';
    foreach ($pk as $m) $out .= chr((int)round(sqrt($m / $top) * 255));
    return [base64_encode($out), round($n / 2000, 3)];
}

/** O trabalho «au_peaks» (cron): a forma de onda e a duração de um áudio que o browser não leu. */
function cc_au_peaks_run(PDO $pdo, array $job): void {
    $a = cc_asset_get($pdo, (int)$job['asset_id']);
    if (!$a || $a['deleted_at'] || $a['kind'] !== 'audio' || (string)$a['peaks'] !== '') return;
    if (PHP_SAPI !== 'cli') throw new RuntimeException('A forma de onda deste formato é feita no servidor (cron).');
    require_once __DIR__ . '/cc_review.php';
    $ff = cc_rv_bin('ffmpeg');
    if (!$ff) throw new RuntimeException('Falta o ffmpeg no servidor.');
    $key = (string)$a['file_key'];
    $tmp = null;
    if (str_starts_with($key, 'r2:')) $src = r2_get_url(substr($key, 3), false, '', 3600);
    else { $tmp = tempnam(sys_get_temp_dir(), 'ccpk'); file_put_contents($tmp, cc_store_get($key, 2147483647)); $src = $tmp; }
    try {
        $r = cc_audio_peaks_ff($ff, $src);
        if (!$r) throw new RuntimeException('O ffmpeg não leu este áudio.');
        $pdo->prepare('UPDATE cc_assets SET peaks = ?, duration = COALESCE(NULLIF(duration, 0), ?) WHERE id = ?')->execute([$r[0], $r[1], (int)$a['id']]);
    } finally {
        if ($tmp) @unlink($tmp);
    }
}

/** Um áudio que o browser não consegue ler (sem forma de onda): o cron faz a forma de onda, se houver ffmpeg. */
function cc_au_peaks_queue(PDO $pdo, array $a, ?int $by): void {
    if ($a['kind'] !== 'audio' || (string)($a['peaks'] ?? '') !== '' || !cc_ffmpeg_ok($pdo)) return;
    if (!in_array(strtolower(pathinfo((string)$a['file_name'], PATHINFO_EXTENSION)), ['aif', 'aiff'], true)) return;
    cc_job_add($pdo, 'au_peaks', (int)$a['id'], null, null, $by);
}

/** O cron disse que há ffmpeg (as páginas não correm programas). */
function cc_ffmpeg_ok(PDO $pdo): bool {
    return (string)setting($pdo, 'cc_ffmpeg', '') !== '';
}

/** «83.4» → «1:23». */
function cc_stt_ts(float $t): string {
    $s = (int)floor($t);
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

/** Transcreve uma referência (um trabalho da fila: stt ou stt_ff). */
function cc_stt_run(PDO $pdo, array $job): void {
    $a = cc_asset_get($pdo, (int)$job['asset_id']);
    if (!$a || $a['deleted_at']) return;
    $cfg = cc_stt_config();
    if (!$cfg) throw new CcAiException('A transcrição ainda não está ligada (Definições › Integrações).', 'not_configured');
    $ai = cc_ai_config();
    $cap = $ai ? $ai['cap'] : cc_ai_cap();
    if (cc_ai_month_spend($pdo) >= $cap - 0.05) throw new CcAiException('A IA do Centro Criativo chegou ao limite mensal. Volta no dia 1 (ou suba o limite).', 'budget');
    $pdo->prepare("UPDATE cc_assets SET ai_status = 'running', ai_error = '' WHERE id = ?")->execute([(int)$a['id']]);

    $tmpIn = tempnam(sys_get_temp_dir(), 'ccstt');
    $tmpOut = null;
    try {
        $key = (string)$a['file_key'];
        $ext = strtolower(pathinfo((string)$a['file_name'], PATHINFO_EXTENSION)) ?: 'mp3';
        if ($job['kind'] === 'stt_ff') {
            if (PHP_SAPI !== 'cli') throw new RuntimeException('Esta transcrição corre no servidor (cron).');
            require_once __DIR__ . '/cc_review.php';
            $ff = cc_rv_bin('ffmpeg');
            if (!$ff) throw new RuntimeException('Falta o ffmpeg no servidor.');
            $src = str_starts_with($key, 'r2:') ? r2_get_url(substr($key, 3), false, '', 3600) : $key;
            if (!str_starts_with($key, 'r2:')) { file_put_contents($tmpIn, cc_store_get($key, 2147483647)); $src = $tmpIn; }
            $tmpOut = $tmpIn . '.mp3';
            // mono, 16 kHz, 32 kbit/s: até ~100 min cabem nos 25 MB do serviço
            [$code, , $err] = cc_rv_run([$ff, '-hide_banner', '-loglevel', 'error', '-y', '-i', $src, '-vn', '-ac', '1', '-ar', '16000', '-b:a', '32k', '-t', '6000', $tmpOut], 1800);
            if ($code !== 0 || !is_file($tmpOut) || filesize($tmpOut) < 1000) throw new RuntimeException('O ffmpeg não extraiu o som: ' . trim(mb_substr((string)$err, 0, 160)));
            $file = $tmpOut; $name = 'audio.mp3'; $mime = 'audio/mpeg';
        } else {
            file_put_contents($tmpIn, cc_store_get($key, CC_STT_MAX));
            $file = $tmpIn; $name = 'audio.' . $ext; $mime = (string)$a['mime'] ?: 'audio/mpeg';
        }
        if (filesize($file) > CC_STT_MAX) throw new RuntimeException('O som passa dos 25 MB que o serviço aceita.');
        $fields = ['file' => new CURLFile($file, $mime, $name), 'model' => $cfg['model'], 'response_format' => 'verbose_json', 'timestamp_granularities[]' => 'segment'];
        if ($cfg['lang'] !== '') $fields['language'] = $cfg['lang'];
        $ch = curl_init($cfg['url']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 15,
                                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['key']]]);
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($status === 0) throw new CcAiException('Sem ligação ao serviço de transcrição (' . $cerr . ').', 'network');
        $j = json_decode($body, true);
        if ($status === 429) throw new CcAiException('O serviço de transcrição pediu para esperar.', 'rate');
        if ($status >= 500) throw new CcAiException('O serviço de transcrição falhou (' . $status . ').', 'server');
        if ($status === 401 || $status === 403) throw new CcAiException('A chave do serviço de transcrição não foi aceite.', 'auth');
        if ($status !== 200 || !is_array($j)) throw new CcAiException('O serviço de transcrição recusou o ficheiro: ' . mb_substr((string)($j['error']['message'] ?? $body), 0, 160), 'error');
    } finally {
        @unlink($tmpIn);
        if ($tmpOut) @unlink($tmpOut);
    }

    $lines = [];
    foreach ((array)($j['segments'] ?? []) as $sg) {
        $t = trim((string)($sg['text'] ?? ''));
        if ($t !== '') $lines[] = '[' . cc_stt_ts((float)($sg['start'] ?? 0)) . '] ' . $t;
    }
    if (!$lines && trim((string)($j['text'] ?? '')) !== '') $lines[] = '[0:00] ' . trim((string)$j['text']);
    $dur = (float)($j['duration'] ?? $a['duration'] ?? 0);
    $cost = round(max(1.0, $dur) / 60 * $cfg['usd_min'], 6);
    cc_ai_record($pdo, 'cc-stt', $cfg['provider'], $cfg['model'], [], $cost, ['asset' => (int)$a['id'], 'user' => $job['created_by'] ?? null]);
    $an = json_decode((string)$a['analysis'], true) ?: [];
    $an['speech'] = ['lang' => (string)($j['language'] ?? ''), 'segments' => count($lines), 'model' => $cfg['model']];
    $pdo->prepare('UPDATE cc_assets SET transcript = ?, analysis = ?, ai_status = ? WHERE id = ?')
        ->execute([mb_substr(implode("\n", $lines), 0, 200000), json_encode($an, JSON_UNESCAPED_UNICODE), '', (int)$a['id']]);
    cc_asset_reindex($pdo, (int)$a['id']);
    // a seguir, a análise (que já lê a transcrição)
    $a = cc_asset_get($pdo, (int)$a['id']);
    if (cc_ai_config() && ($a['thumbs'] || in_array($a['kind'], ['link', 'audio'], true)) && (string)$a['ai_model'] === '') {
        $pdo->prepare("UPDATE cc_assets SET ai_status = 'queued' WHERE id = ?")->execute([(int)$a['id']]);
        cc_job_add($pdo, 'analyze', (int)$a['id'], null, null, $job['created_by'] ?? null);
    }
}
