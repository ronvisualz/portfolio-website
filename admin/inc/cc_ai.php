<?php
/**
 * topper. admin — a IA do Centro Criativo (§23.5): Claude + embeddings multimodais, sem SDK.
 *
 * O SDK oficial precisa de Composer, que o alojamento não tem: um POST JSON com cURL, como o
 * assistente do Drive (inc/ai.php), mas com as regras do serviço premium:
 *   · o modelo Claude mais capaz (cc_config.php › model; por defeito claude-fable-5-1), esforço
 *     «high», saídas estruturadas (JSON Schema) e fallbacks do servidor ligados («default»);
 *   · embeddings multimodais por API paga (Voyage) para «Mais como este» e «Referências para o
 *     brief»; sem chave, a semelhança cai para etiquetas, facetas e paleta;
 *   · gasto registado em cc_ai_usage (à parte do assistente do Drive), com limite mensal próprio,
 *     visível em Definições › Integrações.
 * As chaves ficam no servidor (inc/cc_config.php, fora do Git); nunca vão para o browser.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/cc.php';

const CC_AI_VERSION   = '2026-10-21';
const CC_AI_FALLBACK  = 'server-side-fallback-2026-07-01';
const CC_AI_MODEL     = 'claude-fable-5-1';
const CC_AI_MODEL_ALT = 'claude-opus-5-5';
const CC_AI_TIMEOUT   = 280;
const CC_AI_RETRIES   = 2;
const CC_AI_CAP       = 60.0;
const CC_EMBED_URL    = 'https://api.voyageai.com/v1/multimodalembeddings';
const CC_EMBED_MODEL  = 'voyage-multimodal-3';

/** USD por milhão de tokens: [entrada, saída, leitura de cache]. Escrita de cache = 1,25 × entrada. */
const CC_AI_PRICES = [
    'claude-fable-5-1'  => [10.0, 50.0, 0.25],
    'claude-fable-5'    => [10.0, 50.0, 1.00],
    'claude-opus-5-5'   => [4.0, 20.0, 0.20],
    'claude-opus-5'     => [5.0, 25.0, 0.50],
    'claude-opus-4-8'   => [5.0, 25.0, 0.50],
    'claude-sonnet-5-5' => [2.0, 10.0, 0.20],
];

const CC_AI_PURPOSES = [
    'cc-analyze' => 'Análise de referências', 'cc-embed' => 'Embeddings (semelhança)', 'cc-angles' => 'Ângulos',
    'cc-prepare' => 'Preparar com IA (pré-criativo)', 'cc-page' => 'Páginas do pré-criativo', 'cc-tidy' => 'Arrumar o quadro',
    'cc-treat' => 'Tratamentos', 'cc-shots' => 'Listas de planos',
];

class CcAiException extends RuntimeException {
    public string $kind;
    public function __construct(string $message, string $kind = 'error', int $status = 0) {
        parent::__construct($message, $status);
        $this->kind = $kind;
    }
}

// ── configuração e gasto ────────────────────────────────────

function cc_ai_raw_config(): array {
    static $c = null;
    if ($c !== null) return $c;
    $f = __DIR__ . '/cc_config.php';
    $raw = is_file($f) ? require $f : [];
    return $c = is_array($raw) ? $raw : [];
}

function cc_key_ok(string $k): bool {
    return $k !== '' && strlen($k) >= 16 && strpos($k, '…') === false && stripos($k, 'COLE_') !== 0 && !preg_match('/\s/', $k);
}

/** A configuração do Claude, ou null se não houver chave (nem aqui nem no assistente do Drive). */
function cc_ai_config(): ?array {
    $raw = cc_ai_raw_config();
    $key = trim((string)($raw['anthropic_key'] ?? ''));
    $base = rtrim(trim((string)($raw['base_url'] ?? '')), '/');
    if (!cc_key_ok($key)) {
        require_once __DIR__ . '/ai.php';
        $drive = ai_config();
        if (!$drive) return null;
        $key = $drive['api_key'];
        if ($base === '') $base = $drive['base_url'];
    }
    if ($base === '') $base = 'https://api.anthropic.com';
    if (!preg_match('~^(https?)://([A-Za-z0-9.-]+)(:\d+)?$~', $base, $m)) return null;
    if ($m[1] !== 'https' && !in_array(strtolower($m[2]), ['127.0.0.1', 'localhost'], true)) return null;   // a chave nunca vai em claro
    $model = trim((string)($raw['model'] ?? ''));
    $alt = trim((string)($raw['fallback_model'] ?? ''));
    $effort = (string)($raw['effort'] ?? 'high');
    $cap = $raw['monthly_cap_eur'] ?? CC_AI_CAP;
    return [
        'key' => $key, 'base' => $base,
        'model' => preg_match('/^[a-z0-9][a-z0-9.-]{2,63}$/', $model) ? $model : CC_AI_MODEL,
        'alt' => preg_match('/^[a-z0-9][a-z0-9.-]{2,63}$/', $alt) ? $alt : CC_AI_MODEL_ALT,
        'effort' => in_array($effort, ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $effort : 'high',
        'cap' => is_numeric($cap) ? max(0.0, (float)$cap) : CC_AI_CAP,
        'auto' => !array_key_exists('auto_analyze', $raw) || !empty($raw['auto_analyze']),
    ];
}

/** Embeddings multimodais (Voyage), ou null. */
function cc_embed_config(): ?array {
    $raw = cc_ai_raw_config();
    $key = trim((string)($raw['voyage_key'] ?? ''));
    if (!cc_key_ok($key)) return null;
    $url = trim((string)($raw['embed_url'] ?? CC_EMBED_URL));
    if (!preg_match('~^https://~', $url) && !preg_match('~^http://(127\.0\.0\.1|localhost)(:\d+)?/~', $url)) return null;
    $model = trim((string)($raw['embed_model'] ?? ''));
    return ['key' => $key, 'url' => $url, 'model' => preg_match('/^[a-z0-9][a-z0-9.-]{2,63}$/', $model) ? $model : CC_EMBED_MODEL,
            'price' => is_numeric($raw['embed_usd_mtok'] ?? null) ? (float)$raw['embed_usd_mtok'] : 0.12];
}

/** O mês civil de Lisboa (UTC). */
function cc_ai_month(): array {
    $tz = new DateTimeZone('Europe/Lisbon');
    $d = new DateTimeImmutable('now', $tz);
    $a = $d->setDate((int)$d->format('Y'), (int)$d->format('n'), 1)->setTime(0, 0);
    return [$a->format('Y-m-d H:i:s'), $a->modify('+1 month')->format('Y-m-d H:i:s')];
}

function cc_ai_month_spend(PDO $pdo): float {
    [$a, $b] = cc_ai_month();
    $st = $pdo->prepare('SELECT COALESCE(SUM(cost_usd), 0) FROM cc_ai_usage WHERE created_at >= ? AND created_at < ?');
    $st->execute([$a, $b]);
    return round((float)$st->fetchColumn(), 6);
}

function cc_ai_cap(): float {
    $c = cc_ai_config();
    $raw = cc_ai_raw_config();
    return $c ? $c['cap'] : (is_numeric($raw['monthly_cap_eur'] ?? null) ? (float)$raw['monthly_cap_eur'] : CC_AI_CAP);
}

function cc_ai_record(PDO $pdo, string $purpose, string $provider, string $model, array $u, float $cost, array $ctx = []): void {
    $pdo->prepare('INSERT INTO cc_ai_usage (created_at, purpose, provider, model, input_tokens, output_tokens, cache_read, cache_write, cost_usd, user_id, board_id, asset_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([date('Y-m-d H:i:s'), substr($purpose, 0, 16), $provider, substr($model, 0, 64), (int)($u['input_tokens'] ?? 0), (int)($u['output_tokens'] ?? 0),
                   (int)($u['cache_read_input_tokens'] ?? 0), (int)($u['cache_creation_input_tokens'] ?? 0), round($cost, 6),
                   $ctx['user'] ?? (current_user()['id'] ?? null), $ctx['board'] ?? null, $ctx['asset'] ?? null]);
}

function cc_ai_cost(string $model, array $u): float {
    [$in, $out, $cr] = CC_AI_PRICES[$model] ?? [10.0, 50.0, 1.0];
    return ((int)($u['input_tokens'] ?? 0) * $in + (int)($u['cache_creation_input_tokens'] ?? 0) * $in * 1.25
          + (int)($u['cache_read_input_tokens'] ?? 0) * $cr + (int)($u['output_tokens'] ?? 0) * $out) / 1000000;
}

/** Resumo para Definições › Integrações: gasto do mês, limite, por uso e por modelo. */
function cc_ai_usage_summary(PDO $pdo): array {
    [$a, $b] = cc_ai_month();
    $st = $pdo->prepare('SELECT purpose, provider, model, COUNT(*) AS n, SUM(input_tokens) AS tin, SUM(output_tokens) AS tout, SUM(cost_usd) AS usd
                           FROM cc_ai_usage WHERE created_at >= ? AND created_at < ? GROUP BY purpose, provider, model ORDER BY usd DESC');
    $st->execute([$a, $b]);
    $rows = $st->fetchAll();
    $last = $pdo->query('SELECT created_at, purpose, model, cost_usd FROM cc_ai_usage ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    return ['spend' => cc_ai_month_spend($pdo), 'cap' => cc_ai_cap(), 'rows' => $rows, 'last' => $last,
            'analyzed' => (int)$pdo->query("SELECT COUNT(*) FROM cc_assets WHERE ai_status = 'done' AND deleted_at IS NULL")->fetchColumn(),
            'embedded' => (int)$pdo->query('SELECT COUNT(*) FROM cc_assets WHERE embedding IS NOT NULL AND deleted_at IS NULL')->fetchColumn(),
            'queued' => cc_jobs_pending($pdo),
            'errors' => (int)$pdo->query("SELECT COUNT(*) FROM cc_jobs WHERE status = 'error' AND done_at >= '" . date('Y-m-d H:i:s', time() - 7 * 86400) . "'")->fetchColumn()];
}

function cc_ai_guard(PDO $pdo): array {
    $cfg = cc_ai_config();
    if (!$cfg) throw new CcAiException('A IA do Centro Criativo ainda não está ligada: falta a chave da Anthropic (Definições › Integrações).', 'not_configured');
    if (cc_ai_month_spend($pdo) >= $cfg['cap'] - 0.05) {
        throw new CcAiException('A IA do Centro Criativo chegou ao limite mensal de ' . number_format($cfg['cap'], 0, ',', ' ') . ' €. Volta no dia 1 (ou suba o limite).', 'budget');
    }
    return $cfg;
}

// ── HTTP ────────────────────────────────────────────────────

function cc_http_post(string $url, array $headers, string $json, int $timeout): array {
    if (!function_exists('curl_init')) throw new CcAiException('Este servidor não tem a extensão cURL do PHP ativa.', 'network');
    $ch = curl_init($url);
    $hdr = [];
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge($headers, ['content-type: application/json', 'accept: application/json', 'Expect:']),
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$hdr) {
            $p = strpos($line, ':');
            if ($p !== false) $hdr[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $out = ['code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $hdr, 'body' => is_string($body) ? $body : '',
            'errno' => curl_errno($ch), 'error' => curl_error($ch)];
    curl_close($ch);
    if ($out['errno'] !== 0) $out['code'] = 0;
    return $out;
}

function cc_http_error(array $r, string $who): CcAiException {
    if ($r['code'] === 0) {
        return $r['errno'] === CURLE_OPERATION_TIMEDOUT
            ? new CcAiException($who . ' demorou demasiado a responder. Tente outra vez.', 'timeout')
            : new CcAiException('Não foi possível falar com ' . $who . ' (' . $r['error'] . ').', 'network');
    }
    $j = json_decode($r['body'], true);
    $msg = is_array($j) ? trim((string)($j['error']['message'] ?? $j['detail'] ?? '')) : '';
    $c = $r['code'];
    if ($c === 401 || $c === 403) return new CcAiException('A chave da API (' . $who . ') não é válida ou não tem permissão.', 'auth');
    if ($c === 402) return new CcAiException('A conta (' . $who . ') não tem crédito disponível.', 'billing');
    if ($c === 404) return new CcAiException('O modelo não está disponível para esta chave (' . $who . ').', 'not_found');
    if ($c === 429) return new CcAiException($who . ' recebeu demasiados pedidos. Tente daqui a um minuto.', 'rate');
    if ($c === 529) return new CcAiException($who . ' está sobrecarregada. Tente daqui a pouco.', 'overloaded');
    if ($c >= 500) return new CcAiException($who . ' teve um erro (HTTP ' . $c . ').', 'server');
    return new CcAiException($who . ' recusou o pedido' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '.'), 'bad_request');
}

// ── Claude ──────────────────────────────────────────────────

/** O contexto da casa (igual em todos os pedidos: fica em cache). */
function cc_ai_system(PDO $pdo): string {
    $vocab = [];
    foreach (CC_VOCAB as $k => [$label]) $vocab[] = $label . ': ' . implode(', ', cc_vocab($pdo, $k));
    return "És o diretor criativo sénior da topper., um estúdio de produção (vídeo, motion design, 3D/CG, fotografia e conteúdos) em Portugal. "
         . "Trabalhas no Centro Criativo da plataforma interna: analisas referências, propões ângulos criativos, escreves páginas de pré-criativo, "
         . "tratamentos e listas de planos. Escreve sempre em português de Portugal (pt-PT), com frases curtas, concretas e visuais, "
         . "sem jargão vazio nem superlativos. Nunca inventes dados do cliente que não estejam no brief. "
         . "Quando classificas, usa o vocabulário controlado da casa (escolhe o termo que melhor serve, ou deixa vazio se nenhum serve):\n"
         . implode("\n", $vocab)
         . "\nAs etiquetas livres são curtas, em minúsculas, em português.";
}

/**
 * Um pedido ao Claude com saída estruturada. $content: blocos da mensagem (texto e imagens).
 * Devolve ['data' => array, 'model', 'cost'].
 */
function cc_claude(PDO $pdo, string $purpose, array $content, array $schema, array $o = []): array {
    $cfg = cc_ai_guard($pdo);
    $body = [
        'model' => $cfg['model'],
        'max_tokens' => (int)($o['max'] ?? 16000),
        'system' => [['type' => 'text', 'text' => cc_ai_system($pdo), 'cache_control' => ['type' => 'ephemeral']]],
        'messages' => [['role' => 'user', 'content' => $content]],
        'output_config' => ['effort' => $o['effort'] ?? $cfg['effort'], 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        'fallbacks' => 'default',
    ];
    $useFallbacks = true;
    $triedAlt = false;
    if (function_exists('set_time_limit')) @set_time_limit(CC_AI_TIMEOUT * (CC_AI_RETRIES + 2) + 60);
    for ($attempt = 0; ; $attempt++) {
        $headers = ['x-api-key: ' . $cfg['key'], 'anthropic-version: ' . CC_AI_VERSION];
        if ($useFallbacks) $headers[] = 'anthropic-beta: ' . CC_AI_FALLBACK;
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        $r = cc_http_post($cfg['base'] . '/v1/messages', $headers, (string)$json, CC_AI_TIMEOUT);
        if ($r['code'] === 200) break;
        $msg = (string)(json_decode($r['body'], true)['error']['message'] ?? '');
        // O modelo principal não está disponível para esta conta (ou exige retenção de dados): o seguinte mais capaz.
        if (!$triedAlt && ($r['code'] === 404 || ($r['code'] === 400 && preg_match('/model|retention/i', $msg) && !preg_match('/fallback/i', $msg)))) {
            $triedAlt = true;
            $body['model'] = $cfg['alt'];
            continue;
        }
        // Uma conta sem acesso aos fallbacks do servidor: segue sem eles.
        if ($useFallbacks && $r['code'] === 400 && preg_match('/fallback|anthropic-beta/i', $msg)) {
            $useFallbacks = false;
            unset($body['fallbacks']);
            continue;
        }
        $again = in_array($r['code'], [429, 500, 502, 503, 529], true) || ($r['code'] === 0 && $r['errno'] !== CURLE_OPERATION_TIMEDOUT);
        if (!$again || $attempt >= CC_AI_RETRIES) {
            error_log('cc_claude ' . $purpose . ': HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 300));
            throw cc_http_error($r, 'a Anthropic');
        }
        $ra = $r['headers']['retry-after'] ?? '';
        usleep((int)((is_numeric($ra) ? max(0.5, min(20.0, (float)$ra)) : [2.0, 5.0, 10.0][$attempt] ?? 10.0) * 1000000));
    }
    $resp = json_decode($r['body'], true);
    if (!is_array($resp) || !is_array($resp['content'] ?? null)) throw new CcAiException('A Anthropic respondeu algo que não se percebe.', 'server');
    $usage = is_array($resp['usage'] ?? null) ? $resp['usage'] : [];
    $model = is_string($resp['model'] ?? null) && $resp['model'] !== '' ? $resp['model'] : $body['model'];
    $cost = cc_ai_cost($model, $usage);
    cc_ai_record($pdo, $purpose, 'anthropic', $model, $usage, $cost, $o['ctx'] ?? []);
    $stop = (string)($resp['stop_reason'] ?? '');
    if ($stop === 'refusal') throw new CcAiException('A IA não respondeu a este pedido (política de utilização). Reformule ou faça à mão.', 'refusal');
    if ($stop === 'max_tokens') throw new CcAiException('A resposta da IA ficou cortada. Tente com menos cartões.', 'truncated');
    $text = '';
    foreach ($resp['content'] as $b) if (($b['type'] ?? '') === 'text' && is_string($b['text'] ?? null)) $text = $b['text'];
    $data = json_decode(trim($text), true);
    if (!is_array($data)) throw new CcAiException('A IA respondeu num formato inesperado. Tente outra vez.', 'server');
    return ['data' => $data, 'model' => $model, 'cost' => $cost];
}

/** Uma imagem guardada (miniatura) como bloco para o Claude. */
function cc_ai_image_block(string $key): ?array {
    try {
        $bytes = cc_store_get($key, 8388608);
    } catch (Throwable $e) {
        return null;
    }
    $info = @getimagesizefromstring($bytes);
    $mime = $info['mime'] ?? '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) return null;
    return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]];
}

/** O texto de uma referência para dar contexto (sem dados internos). */
function cc_ai_asset_line(array $a): string {
    $an = json_decode((string)$a['analysis'], true);
    $bits = array_filter([
        '#' . (int)$a['id'], $a['kind'] === 'video' ? 'vídeo' : ($a['kind'] === 'link' ? 'link' : 'imagem'),
        $a['title'] !== '' ? '«' . mb_substr((string)$a['title'], 0, 80) . '»' : '',
        is_array($an) && !empty($an['caption']) ? mb_substr((string)$an['caption'], 0, 220) : '',
        $a['style'], $a['technique'], $a['camera'], $a['environment'], $a['light'],
        $a['tags'] !== '' ? 'etiquetas: ' . mb_substr((string)$a['tags'], 0, 160) : '',
        ($p = cc_palette_hex($a['palette'], 4)) ? 'cores ' . implode(' ', array_column($p, 'hex')) : '',
    ]);
    return implode(' · ', $bits);
}

/** O esquema da análise de uma referência (as facetas só aceitam o vocabulário da casa ou vazio). */
function cc_ai_analysis_schema(PDO $pdo, bool $video, int $frames): array {
    $enum = fn(string $k) => ['type' => 'string', 'enum' => array_merge(cc_vocab($pdo, $k), [''])];
    $props = [
        'caption' => ['type' => 'string', 'description' => 'Legenda: o que se vê, em 1–2 frases.'],
        'style' => $enum('style'), 'technique' => $enum('technique'), 'camera' => $enum('camera'),
        'environment' => $enum('environment'), 'light' => $enum('light'),
        'composition' => ['type' => 'string', 'description' => 'Composição e enquadramento (1 frase).'],
        'color' => ['type' => 'string', 'description' => 'A cor e o grading (1 frase).'],
        'movement' => ['type' => 'string', 'description' => 'Movimento de câmara ou do sujeito (vazio numa imagem parada).'],
        'why' => ['type' => 'string', 'description' => 'Porque funciona: a ideia ou técnica que vale a pena roubar (1–2 frases).'],
        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '5 a 12 etiquetas livres.'],
        'subjects' => ['type' => 'array', 'items' => ['type' => 'string']],
        'moods' => ['type' => 'array', 'items' => ['type' => 'string']],
        'confidence' => ['type' => 'number', 'description' => 'Confiança na classificação, de 0 a 1.'],
    ];
    $req = array_keys($props);
    if ($video && $frames > 0) {
        $props['shots'] = ['type' => 'array', 'description' => 'Uma entrada por frame enviado, pela ordem.',
            'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['i', 'caption', 'camera', 'why'],
                        'properties' => ['i' => ['type' => 'integer'], 'caption' => ['type' => 'string'], 'camera' => ['type' => 'string'], 'why' => ['type' => 'string']]]];
        $req[] = 'shots';
    }
    return ['type' => 'object', 'additionalProperties' => false, 'required' => $req, 'properties' => $props];
}

/** Analisa uma referência (uma vez; fica guardada). Respeita a edição humana das facetas. */
function cc_ai_analyze_asset(PDO $pdo, int $id): void {
    $a = cc_asset_get($pdo, $id);
    if (!$a || $a['deleted_at']) return;
    $thumb = cc_thumb_pick($a['thumbs'], 1600);
    $frames = $a['kind'] === 'video' ? array_slice(cc_frames($pdo, $id), 0, 12) : [];
    if (!$thumb && !$frames && $a['kind'] !== 'link') { $pdo->prepare("UPDATE cc_assets SET ai_status = '' WHERE id = ?")->execute([$id]); return; }
    $pdo->prepare("UPDATE cc_assets SET ai_status = 'running', ai_error = '' WHERE id = ?")->execute([$id]);
    $content = [];
    if ($frames) {
        foreach ($frames as $i => $f) {
            $b = cc_ai_image_block(cc_thumb_pick($f['thumbs'], 768) ?? $f['file_key']);
            if ($b) { $content[] = ['type' => 'text', 'text' => 'Frame ' . $i . ' (t = ' . number_format((float)$f['t'], 1) . ' s)']; $content[] = $b; }
        }
    } elseif ($thumb && ($b = cc_ai_image_block($thumb))) {
        $content[] = $b;
    }
    $an0 = json_decode((string)$a['analysis'], true);
    $ctx = 'Analisa esta referência ' . ($a['kind'] === 'video' ? 'de vídeo (frames-chave por deteção de corte, por ordem)' : ($a['kind'] === 'link' ? '(pré-visualização de um link)' : '(imagem)'))
         . ' para a biblioteca de referências da topper.'
         . ($a['title'] !== '' ? "\nTítulo: " . mb_substr((string)$a['title'], 0, 200) : '')
         . ($a['source_url'] !== '' ? "\nOrigem: " . mb_substr((string)$a['source_url'], 0, 300) : '')
         . (is_array($an0) && !empty($an0['caption']) ? "\nDescrição da página: " . mb_substr((string)$an0['caption'], 0, 600) : '')
         . ($a['transcript'] ? "\nTranscrição: " . mb_substr((string)$a['transcript'], 0, 4000) : '')
         . "\nClassifica com o vocabulário da casa, dá etiquetas livres e explica porque funciona. Sê honesto na confiança.";
    $content[] = ['type' => 'text', 'text' => $ctx];
    $res = cc_claude($pdo, 'cc-analyze', $content, cc_ai_analysis_schema($pdo, $a['kind'] === 'video', count($frames)), ['max' => 12000, 'ctx' => ['asset' => $id]]);
    $d = $res['data'];
    $an = [
        'caption' => mb_substr((string)($d['caption'] ?? ''), 0, 600), 'composition' => mb_substr((string)($d['composition'] ?? ''), 0, 400),
        'color' => mb_substr((string)($d['color'] ?? ''), 0, 400), 'movement' => mb_substr((string)($d['movement'] ?? ''), 0, 400),
        'why' => mb_substr((string)($d['why'] ?? ''), 0, 600), 'subjects' => array_slice(array_map('strval', (array)($d['subjects'] ?? [])), 0, 12),
        'moods' => array_slice(array_map('strval', (array)($d['moods'] ?? [])), 0, 8), 'confidence' => max(0, min(1, (float)($d['confidence'] ?? 0))),
        'model' => $res['model'], 'site' => is_array($an0) ? (string)($an0['site'] ?? '') : '',
    ];
    $human = $a['edited_at'] !== null;
    if ($human && is_array($an0)) foreach (['caption', 'why', 'composition', 'color', 'movement'] as $k) if (!empty($an0[$k]) && !empty($an0['human'])) $an[$k] = $an0[$k];
    if ($human) $an['human'] = true;
    $set = ['analysis' => json_encode($an, JSON_UNESCAPED_UNICODE), 'ai_status' => 'done', 'ai_error' => '', 'ai_model' => $res['model'], 'analyzed_at' => cc_now()];
    if (!$human) {
        foreach (array_keys(CC_VOCAB) as $f) $set[$f] = in_array($d[$f] ?? '', cc_vocab($pdo, $f), true) ? (string)$d[$f] : '';
        $set['tags'] = cc_tags_clean(implode(',', array_map('strval', (array)($d['tags'] ?? []))));
    } elseif (trim((string)$a['tags']) === '') {
        $set['tags'] = cc_tags_clean(implode(',', array_map('strval', (array)($d['tags'] ?? []))));
    }
    $sql = implode(', ', array_map(fn($k) => $k . ' = ?', array_keys($set)));
    $pdo->prepare("UPDATE cc_assets SET $sql WHERE id = ?")->execute([...array_values($set), $id]);
    foreach ((array)($d['shots'] ?? []) as $s) {
        $i = (int)($s['i'] ?? -1);
        if (!isset($frames[$i])) continue;
        $pdo->prepare('UPDATE cc_frames SET analysis = ? WHERE id = ?')->execute([json_encode(['caption' => (string)$s['caption'], 'camera' => (string)$s['camera'], 'why' => (string)$s['why']], JSON_UNESCAPED_UNICODE), (int)$frames[$i]['id']]);
    }
    cc_asset_reindex($pdo, $id);
    if (cc_embed_config()) cc_job_add($pdo, 'embed', $id, null, null, null);
}

// ── embeddings multimodais (Voyage) ─────────────────────────

/** JPEG em base64 (data URI) para a API de embeddings. */
function cc_embed_image(string $key): ?string {
    try { $bytes = cc_store_get($key, 8388608); } catch (Throwable $e) { return null; }
    $img = cc_gd_open($bytes);
    if (!$img) return null;
    return 'data:image/jpeg;base64,' . base64_encode(cc_gd_jpeg(cc_gd_fit($img, 768), 86));
}

/**
 * $inputs: lista de listas de blocos [['type' => 'text', 'text' => …], ['type' => 'image_base64', 'image_base64' => …]].
 * $type: document | query. Devolve um vetor por entrada.
 */
function cc_embed(PDO $pdo, array $inputs, string $type, array $ctx = []): array {
    $cfg = cc_embed_config();
    if (!$cfg) throw new CcAiException('Os embeddings ainda não estão ligados (falta a chave da Voyage em Definições › Integrações).', 'not_configured');
    if (cc_ai_month_spend($pdo) >= cc_ai_cap() - 0.05) throw new CcAiException('A IA do Centro Criativo chegou ao limite mensal.', 'budget');
    $body = json_encode(['inputs' => array_map(fn($c) => ['content' => $c], $inputs), 'model' => $cfg['model'], 'input_type' => $type === 'query' ? 'query' : 'document'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    for ($attempt = 0; ; $attempt++) {
        $r = cc_http_post($cfg['url'], ['Authorization: Bearer ' . $cfg['key']], (string)$body, 90);
        if ($r['code'] === 200) break;
        if (!in_array($r['code'], [0, 429, 500, 502, 503], true) || $attempt >= 2) throw cc_http_error($r, 'a Voyage');
        usleep([1500000, 4000000][$attempt] ?? 4000000);
    }
    $j = json_decode($r['body'], true);
    $tokens = (int)($j['usage']['total_tokens'] ?? 0);
    cc_ai_record($pdo, 'cc-embed', 'voyage', $cfg['model'], ['input_tokens' => $tokens], $tokens * $cfg['price'] / 1000000, $ctx);
    $out = [];
    foreach ((array)($j['data'] ?? []) as $row) $out[(int)($row['index'] ?? count($out))] = array_map('floatval', (array)($row['embedding'] ?? []));
    ksort($out);
    return array_values($out);
}

function cc_ai_embed_asset(PDO $pdo, int $id): void {
    $cfg = cc_embed_config();
    $a = cc_asset_get($pdo, $id);
    if (!$cfg || !$a || $a['deleted_at']) return;
    $text = mb_substr(trim(cc_ai_asset_line($a) . ' ' . (string)(json_decode((string)$a['analysis'], true)['why'] ?? '')), 0, 2000);
    $frames = $a['kind'] === 'video' ? array_slice(cc_frames($pdo, $id), 0, 12) : [];
    $inputs = [];
    $thumb = cc_thumb_pick($a['thumbs'], 768);
    $img = $thumb ? cc_embed_image($thumb) : null;
    $inputs[] = array_values(array_filter([['type' => 'text', 'text' => $text], $img ? ['type' => 'image_base64', 'image_base64' => $img] : null]));
    foreach ($frames as $f) {
        $fi = cc_embed_image(cc_thumb_pick($f['thumbs'], 768) ?? $f['file_key']);
        if ($fi) $inputs[] = [['type' => 'image_base64', 'image_base64' => $fi]];
    }
    $vecs = cc_embed($pdo, $inputs, 'document', ['asset' => $id]);
    if (!$vecs) return;
    $pdo->prepare('UPDATE cc_assets SET embedding = ?, embed_model = ? WHERE id = ?')->execute([cc_vec_pack($vecs[0]), $cfg['model'], $id]);
    $k = 1;
    foreach ($frames as $f) {
        if (!isset($vecs[$k])) break;
        $pdo->prepare('UPDATE cc_frames SET embedding = ? WHERE id = ?')->execute([cc_vec_pack($vecs[$k]), (int)$f['id']]);
        $k++;
    }
}

/** «Mais como este»: por vetor (se houver) ou por etiquetas/facetas/paleta. Devolve [[asset, score]]. */
function cc_more_like(PDO $pdo, array $a, int $n = 18): array {
    $vec = cc_vec_unpack($a['embedding'] ?? null);
    $sc = cc_nearest($pdo, $vec, $n, [(int)$a['id']], $vec ? null : $a);
    $rows = cc_assets_by_ids($pdo, array_keys($sc));
    return array_map(fn($r) => [$r, $sc[(int)$r['id']]], $rows);
}

/** «Referências para o brief»: embeddings do texto (pesquisa) ou, sem eles, as palavras do brief. */
function cc_refs_for_text(PDO $pdo, string $text, int $n = 9, array $exclude = []): array {
    $text = trim($text);
    if ($text === '') return [];
    if (cc_embed_config()) {
        try {
            $v = cc_embed($pdo, [[['type' => 'text', 'text' => mb_substr($text, 0, 6000)]]], 'query');
            if ($v) {
                $sc = cc_nearest($pdo, $v[0], $n, $exclude);
                return cc_assets_by_ids($pdo, array_keys($sc));
            }
        } catch (CcAiException $e) {
            if (!in_array($e->kind, ['not_configured', 'budget'], true)) throw $e;
        }
    }
    // sem vetores: as palavras mais longas do texto contra o índice de texto
    $words = array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', cc_fold($text)), fn($w) => mb_strlen($w) >= 5));
    usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $scores = [];
    $ex = array_flip(array_map('intval', $exclude));
    $st = $pdo->prepare('SELECT id FROM cc_assets WHERE deleted_at IS NULL AND search_text LIKE ? LIMIT 200');
    foreach (array_slice($words, 0, 14) as $w) {
        $st->execute(['%' . $w . '%']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) if (!isset($ex[(int)$id])) $scores[(int)$id] = ($scores[(int)$id] ?? 0) + 1;
    }
    arsort($scores);
    return cc_assets_by_ids($pdo, array_slice(array_keys($scores), 0, $n));
}

// ── ângulos, pré-criativo, páginas, arrumar, tratamento, planos ──

/** O brief de um quadro (texto do quadro + X-Ray do negócio, sem preços nem custos). */
function cc_brief_text(PDO $pdo, array $board): string {
    $t = trim((string)($board['brief'] ?? ''));
    if ($board['deal_id']) {
        require_once __DIR__ . '/pipeline_flow.php';
        $deal = deal_get($pdo, (int)$board['deal_id']);
        if ($deal) {
            $x = xray_data($deal);
            $bits = ['Negócio: ' . $deal['name']];
            foreach (['objetivo' => 'Objetivo', 'entregaveis' => 'Entregáveis', 'formatos' => 'Formatos e canais', 'referencias' => 'Referências do cliente',
                      'prazo' => 'Prazo', 'uso' => 'Uso', 'pedido' => 'Pedido do cliente (Opção A)', 'valor' => 'Proposta de valor (Opção B)'] as $k => $l) {
                if (trim((string)($x[$k] ?? '')) !== '') $bits[] = $l . ': ' . trim((string)$x[$k]);
            }
            foreach ((array)($x['funil'] ?? []) as $q => $ans) if (trim((string)$ans) !== '') $bits[] = $q . ': ' . trim((string)$ans);
            if ($t === '') $t = implode("\n", $bits);
            elseif (!str_contains($t, (string)$deal['name'])) $t .= "\n\n" . implode("\n", $bits);
        }
    }
    return mb_substr($t, 0, 12000);
}

function cc_angle_schema(bool $lanes): array {
    $item = ['type' => 'object', 'additionalProperties' => false,
             'required' => ['lane', 'title', 'idea', 'technique', 'complexity', 'why', 'search'],
             'properties' => [
                 'lane' => ['type' => 'string', 'enum' => $lanes ? ['A', 'B'] : ['']],
                 'title' => ['type' => 'string', 'description' => 'Título curto do ângulo (2–6 palavras).'],
                 'idea' => ['type' => 'string', 'description' => 'A ideia numa ou duas frases visuais.'],
                 'technique' => ['type' => 'string', 'description' => 'Técnica de produção (ex.: «CG + tabletop»).'],
                 'complexity' => ['type' => 'string', 'enum' => ['baixa', 'média', 'alta']],
                 'why' => ['type' => 'string', 'description' => 'Porque funciona para este cliente e objetivo.'],
                 'search' => ['type' => 'string', 'description' => 'Descrição visual para procurar referências na biblioteca (estilo, luz, câmara, ambiente).'],
             ]];
    return ['type' => 'object', 'additionalProperties' => false, 'required' => ['angles'],
            'properties' => ['angles' => ['type' => 'array', 'items' => $item]]];
}

/** As referências do quadro (ou de uma faixa) como texto, para dar contexto. */
function cc_board_refs_text(PDO $pdo, int $boardId, string $lane = '', int $max = 40): string {
    $st = $pdo->prepare("SELECT a.* FROM cc_cards c JOIN cc_assets a ON a.id = c.asset_id
                          WHERE c.board_id = ? AND c.deleted_at IS NULL AND a.deleted_at IS NULL" . ($lane !== '' ? ' AND c.lane = ?' : '') . ' ORDER BY c.y, c.x LIMIT ' . $max);
    $st->execute($lane !== '' ? [$boardId, $lane] : [$boardId]);
    return implode("\n", array_map('cc_ai_asset_line', $st->fetchAll()));
}

function cc_board_notes_text(PDO $pdo, int $boardId, string $lane = ''): string {
    $st = $pdo->prepare("SELECT type, content FROM cc_cards WHERE board_id = ? AND deleted_at IS NULL AND type IN ('note','list','column')" . ($lane !== '' ? ' AND lane = ?' : '') . ' ORDER BY y, x');
    $st->execute($lane !== '' ? [$boardId, $lane] : [$boardId]);
    $out = [];
    foreach ($st->fetchAll() as $c) {
        $x = cc_card_content((string)$c['type'], $c['content']);
        if ($c['type'] === 'note' && $x['text'] !== '') $out[] = $x['text'];
        elseif ($c['type'] === 'list') $out[] = $x['title'] . ': ' . implode('; ', array_column($x['items'], 't'));
        elseif ($c['type'] === 'column' && $x['title'] !== '') $out[] = 'Coluna: ' . $x['title'];
    }
    return mb_substr(implode("\n", $out), 0, 6000);
}

/** «Ângulos para este projeto»: 3 (ou 3 por opção, no pré-criativo). */
function cc_ai_angles(PDO $pdo, array $board, int $n = 3, string $avoid = ''): array {
    $lanes = $board['kind'] === 'precreative';
    $brief = cc_brief_text($pdo, $board);
    if ($brief === '') throw new RuntimeException('Escreva primeiro o brief do quadro.');
    $prompt = "Brief:\n" . $brief
            . "\n\nNotas no quadro:\n" . (cc_board_notes_text($pdo, (int)$board['id']) ?: '(nenhuma)')
            . "\n\nReferências já no quadro:\n" . (cc_board_refs_text($pdo, (int)$board['id']) ?: '(nenhuma)')
            . ($avoid !== '' ? "\n\nÂngulos já propostos (não repitas):\n" . $avoid : '')
            . "\n\n" . ($lanes
                ? "Propõe $n ângulos para a Opção A (o pedido do cliente, tal como pedido) e $n para a Opção B (proposta de valor: mais ambiciosa, com mais valor para o cliente). lane = A ou B."
                : "Propõe $n ângulos criativos distintos entre si para este projeto. lane = vazio.");
    $res = cc_claude($pdo, 'cc-angles', [['type' => 'text', 'text' => $prompt]], cc_angle_schema($lanes), ['ctx' => ['board' => (int)$board['id']]]);
    return array_values(array_filter((array)($res['data']['angles'] ?? []), 'is_array'));
}

/**
 * «Preparar com IA» (pré-criativo): 3 ângulos por opção e 6–9 referências da biblioteca por faixa.
 * As referências entram como cartões na faixa (a equipa só mantém/tira/troca). Devolve o resumo.
 */
function cc_ai_prepare(PDO $pdo, array $board, int $by): array {
    $angles = cc_ai_angles($pdo, $board, 3);
    $made = ['A' => 0, 'B' => 0];
    $have = array_map('intval', $pdo->query('SELECT asset_id FROM cc_cards WHERE board_id = ' . (int)$board['id'] . ' AND deleted_at IS NULL AND asset_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
    $brief = cc_brief_text($pdo, $board);
    foreach (['A', 'B'] as $lane) {
        $la = array_values(array_filter($angles, fn($x) => ($x['lane'] ?? '') === $lane));
        // os ângulos ficam como notas no topo da faixa
        [$x0, $y0] = cc_lane_origin($lane);
        foreach ($la as $i => $ang) {
            cc_card_add($pdo, (int)$board['id'], ['type' => 'note', 'lane' => $lane, 'x' => $x0 + $i * 300, 'y' => $y0, 'w' => 280,
                'content' => ['text' => '✦ ' . trim((string)$ang['title']) . "\n" . trim((string)$ang['idea']) . "\n\n" . trim((string)$ang['technique']) . ' · complexidade ' . $ang['complexity']
                                        . "\n\nPorque funciona: " . trim((string)$ang['why']), 'color' => $lane === 'A' ? '#81CFE3' : '#FFC718']], $by);
        }
        $query = ($lane === 'A' ? 'Opção A (pedido do cliente). ' : 'Opção B (proposta de valor). ') . implode(' ', array_map(fn($x) => $x['search'] . ' ' . $x['idea'], $la)) . "\n" . mb_substr($brief, 0, 1500);
        $refs = cc_refs_for_text($pdo, $query, 9, $have);
        foreach (array_values($refs) as $i => $a) {
            $have[] = (int)$a['id'];
            $ratio = ($a['width'] && $a['height']) ? (float)$a['height'] / (float)$a['width'] : 0.62;
            cc_card_add($pdo, (int)$board['id'], ['type' => $a['kind'] === 'video' ? 'video' : ($a['kind'] === 'link' ? 'link' : 'image'), 'asset_id' => (int)$a['id'], 'lane' => $lane,
                'x' => $x0 + ($i % 3) * 300, 'y' => $y0 + 260 + intdiv($i, 3) * 230, 'w' => 280, 'h' => round(280 * min(1.4, $ratio)), 'content' => []], $by);
            $made[$lane]++;
        }
    }
    $pdo->prepare("UPDATE cc_boards SET ai_state = 'prepared' WHERE id = ?")->execute([(int)$board['id']]);
    cc_board_touch($pdo, (int)$board['id']);
    return ['angles' => count($angles), 'refs' => $made];
}

/** Onde começa cada faixa do pré-criativo (A em cima, B em baixo). */
function cc_lane_origin(string $lane): array {
    return $lane === 'B' ? [80.0, 1240.0] : [80.0, 200.0];
}

function cc_page_schema(): array {
    return ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'concept', 'approach', 'technique', 'complexity'],
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Título da página (2–6 palavras).'],
                'concept' => ['type' => 'string', 'description' => 'O conceito numa frase.'],
                'approach' => ['type' => 'string', 'description' => 'A abordagem: como se faz e como se vê (3–5 frases).'],
                'technique' => ['type' => 'string', 'description' => 'Técnica de produção, curta.'],
                'complexity' => ['type' => 'string', 'enum' => ['Baixa', 'Média', 'Alta']],
            ]];
}

/** A paleta de um conjunto de referências: as cores mais presentes, juntas por ΔE < 10, até 5. */
function cc_palette_merge(array $assets, int $n = 5): array {
    $all = [];
    foreach ($assets as $a) foreach ((array)json_decode((string)$a['palette'], true) as $c) if (is_array($c) && count($c) >= 5) $all[] = $c;
    usort($all, fn($x, $y) => $y[1] <=> $x[1]);
    $out = [];
    foreach ($all as $c) {
        foreach ($out as &$o) if (cc_de2000([$o[2], $o[3], $o[4]], [(float)$c[2], (float)$c[3], (float)$c[4]]) < 10) { $o[1] += (float)$c[1]; continue 2; }
        unset($o);
        $out[] = [$c[0], (float)$c[1], (float)$c[2], (float)$c[3], (float)$c[4]];
    }
    unset($o);
    usort($out, fn($x, $y) => $y[1] <=> $x[1]);
    return array_slice(array_column($out, 0), 0, $n);
}

/** As referências que entram na página de uma faixa: as «manter» primeiro, depois pela ordem do quadro; até 6. */
function cc_lane_assets(PDO $pdo, int $boardId, string $lane, int $n = 6): array {
    $st = $pdo->prepare("SELECT c.content, a.* FROM cc_cards c JOIN cc_assets a ON a.id = c.asset_id
                          WHERE c.board_id = ? AND c.lane = ? AND c.deleted_at IS NULL AND a.deleted_at IS NULL AND a.thumbs IS NOT NULL ORDER BY c.y, c.x");
    $st->execute([$boardId, $lane]);
    $rows = $st->fetchAll();
    $keep = []; $rest = [];
    foreach ($rows as $r) {
        $c = cc_card_content('image', $r['content']);
        if ($c['state'] === 'swap') continue;
        if ($c['state'] === 'keep') $keep[] = $r; else $rest[] = $r;
    }
    return array_slice(array_merge($keep, $rest), 0, $n);
}

/**
 * «Gerar página»: uma por faixa (título, conceito numa frase, abordagem, 6 referências, paleta,
 * técnica e complexidade). Com IA, o texto vem do Claude; sem IA, das notas da faixa.
 */
function cc_page_generate(PDO $pdo, array $board, string $lane, int $by): array {
    $assets = cc_lane_assets($pdo, (int)$board['id'], $lane);
    $notes = cc_board_notes_text($pdo, (int)$board['id'], $lane);
    $text = null;
    $ai = false;
    if (cc_ai_config()) {
        $prompt = "Brief:\n" . cc_brief_text($pdo, $board) . "\n\nFaixa: " . CC_LANES[$lane]
                . "\nNotas e ângulo escolhido nesta faixa:\n" . ($notes ?: '(nenhuma)')
                . "\nReferências escolhidas:\n" . implode("\n", array_map('cc_ai_asset_line', $assets))
                . "\n\nEscreve a página de pré-criativo desta opção, para o cliente ler ao lado do preço: título, o conceito numa frase, a abordagem, a técnica e a complexidade.";
        $res = cc_claude($pdo, 'cc-page', [['type' => 'text', 'text' => $prompt]], cc_page_schema(), ['ctx' => ['board' => (int)$board['id']], 'max' => 8000]);
        $text = $res['data'];
        $ai = true;
    }
    if (!$text) {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', ltrim($notes, '✦ ')))));
        $text = ['title' => mb_substr($lines[0] ?? CC_LANES[$lane], 0, 80), 'concept' => mb_substr($lines[1] ?? '', 0, 300),
                 'approach' => mb_substr(implode(' ', array_slice($lines, 2, 4)), 0, 900), 'technique' => '', 'complexity' => 'Média'];
    }
    $data = [
        'title' => mb_substr(trim((string)$text['title']), 0, 120), 'concept' => mb_substr(trim((string)$text['concept']), 0, 400),
        'approach' => mb_substr(trim((string)$text['approach']), 0, 1600), 'technique' => mb_substr(trim((string)$text['technique']), 0, 120),
        'complexity' => in_array($text['complexity'] ?? '', ['Baixa', 'Média', 'Alta'], true) ? $text['complexity'] : 'Média',
        'refs' => array_map(fn($a) => (int)$a['id'], $assets), 'palette' => cc_palette_merge($assets), 'ai' => $ai,
    ];
    $st = $pdo->prepare('SELECT id FROM cc_pages WHERE board_id = ? AND lane = ?');
    $st->execute([(int)$board['id'], $lane]);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($pid = $st->fetchColumn()) {
        $pdo->prepare('UPDATE cc_pages SET data = ?, generated_by = ?, generated_at = ? WHERE id = ?')->execute([$json, $by, cc_now(), (int)$pid]);
    } else {
        $pdo->prepare('INSERT INTO cc_pages (board_id, lane, data, generated_by, generated_at) VALUES (?,?,?,?,?)')->execute([(int)$board['id'], $lane, $json, $by, cc_now()]);
    }
    cc_board_touch($pdo, (int)$board['id']);
    return $data;
}

/** «Arrumar o quadro»: agrupa os cartões soltos em colunas por tema. */
function cc_ai_tidy(PDO $pdo, array $board, int $by): int {
    $cards = array_values(array_filter(cc_cards($pdo, (int)$board['id']), fn($c) => !$c['column_id'] && !in_array($c['type'], ['column', 'arrow', 'drawing'], true)));
    if (count($cards) < 3) throw new RuntimeException('Há poucos cartões soltos para arrumar.');
    $lines = [];
    $assets = [];
    foreach ($cards as $c) {
        if ($c['asset_id'] && ($a = cc_asset_get($pdo, (int)$c['asset_id']))) { $assets[(int)$c['id']] = $a; $lines[] = 'cartão ' . $c['id'] . ': ' . cc_ai_asset_line($a); }
        else { $x = cc_card_content((string)$c['type'], $c['content']); $lines[] = 'cartão ' . $c['id'] . ' (' . $c['type'] . '): ' . mb_substr((string)($x['text'] ?? $x['title'] ?? $x['hex'] ?? ''), 0, 200); }
    }
    $groups = null;
    if (cc_ai_config()) {
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['groups'], 'properties' => ['groups' => ['type' => 'array', 'items' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'cards'],
            'properties' => ['title' => ['type' => 'string'], 'cards' => ['type' => 'array', 'items' => ['type' => 'integer']]]]]]];
        $res = cc_claude($pdo, 'cc-tidy', [['type' => 'text', 'text' => "Agrupa estes cartões de um quadro criativo em 3 a 7 colunas temáticas (por rota, técnica, ambiente ou ideia), com títulos curtos. Cada cartão entra numa só coluna.\n\n" . implode("\n", $lines)]],
                          $schema, ['ctx' => ['board' => (int)$board['id']], 'max' => 8000, 'effort' => 'medium']);
        $groups = $res['data']['groups'] ?? null;
    }
    if (!$groups) {   // sem IA: por técnica (ou tipo)
        $g = [];
        foreach ($cards as $c) {
            $a = $assets[(int)$c['id']] ?? null;
            $key = $a ? ($a['technique'] ?: ($a['style'] ?: 'Referências')) : ['note' => 'Notas', 'list' => 'Listas', 'swatch' => 'Cores'][$c['type']] ?? 'Outros';
            $g[$key][] = (int)$c['id'];
        }
        $groups = [];
        foreach ($g as $t => $ids) $groups[] = ['title' => $t, 'cards' => $ids];
    }
    $valid = array_flip(array_map(fn($c) => (int)$c['id'], $cards));
    $x = 40.0;
    $minY = min(array_map(fn($c) => (float)$c['y'], $cards));
    $n = 0;
    foreach ($groups as $gr) {
        $ids = array_values(array_filter(array_map('intval', (array)($gr['cards'] ?? [])), fn($i) => isset($valid[$i])));
        if (!$ids) continue;
        $col = cc_card_add($pdo, (int)$board['id'], ['type' => 'column', 'x' => $x, 'y' => $minY, 'w' => 300, 'content' => ['title' => mb_substr((string)$gr['title'], 0, 120)]], $by);
        foreach ($ids as $o => $cid) {
            $pdo->prepare('UPDATE cc_cards SET column_id = ?, ord = ?, updated_by = ?, updated_at = ? WHERE id = ?')->execute([$col, $o, $by, cc_now(), $cid]);
            unset($valid[$cid]);
            $n++;
        }
        $x += 330;
    }
    cc_board_touch($pdo, (int)$board['id']);
    return $n;
}

/** «Quadro → tratamento»: o documento (título, logline, secções). Guarda-se como página do quadro. */
function cc_ai_treatment(PDO $pdo, array $board): array {
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'logline', 'sections'],
        'properties' => ['title' => ['type' => 'string'], 'logline' => ['type' => 'string'],
            'sections' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['heading', 'body', 'refs'],
                'properties' => ['heading' => ['type' => 'string'], 'body' => ['type' => 'string'], 'refs' => ['type' => 'array', 'items' => ['type' => 'integer']]]]]]];
    $prompt = "Brief:\n" . cc_brief_text($pdo, $board) . "\n\nNotas do quadro:\n" . (cc_board_notes_text($pdo, (int)$board['id']) ?: '(nenhuma)')
            . "\n\nReferências do quadro (use os #números em refs):\n" . (cc_board_refs_text($pdo, (int)$board['id'], '', 60) ?: '(nenhuma)')
            . "\n\nEscreve o tratamento do projeto, para apresentar ao cliente: título, logline e 4 a 7 secções (conceito, narrativa, look & feel, câmara e montagem, som, entrega), cada uma com as referências que a ilustram.";
    $res = cc_claude($pdo, 'cc-treat', [['type' => 'text', 'text' => $prompt]], $schema, ['ctx' => ['board' => (int)$board['id']], 'max' => 16000]);
    $doc = $res['data'];
    $doc['at'] = cc_now();
    // guarda-se como a «página T» do quadro (as páginas A/B são do pré-criativo)
    $st = $pdo->prepare("SELECT id FROM cc_pages WHERE board_id = ? AND lane = 'T'");
    $st->execute([(int)$board['id']]);
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
    if ($pid = $st->fetchColumn()) $pdo->prepare('UPDATE cc_pages SET data = ?, generated_at = ? WHERE id = ?')->execute([$json, cc_now(), (int)$pid]);
    else $pdo->prepare("INSERT INTO cc_pages (board_id, lane, data, generated_by, generated_at) VALUES (?, 'T', ?, ?, ?)")->execute([(int)$board['id'], $json, current_user()['id'] ?? null, cc_now()]);
    return $doc;
}

/** «Storyboard → lista de planos»: pela ordem dos cartões (esquerda→direita, cima→baixo). */
function cc_ai_shotlist(PDO $pdo, array $board): array {
    $cards = array_values(array_filter(cc_cards($pdo, (int)$board['id']), fn($c) => in_array($c['type'], ['image', 'video', 'note', 'drawing'], true)));
    usort($cards, fn($a, $b) => [round((float)$a['y'] / 200), (float)$a['x']] <=> [round((float)$b['y'] / 200), (float)$b['x']]);
    $lines = [];
    foreach ($cards as $i => $c) {
        $x = cc_card_content((string)$c['type'], $c['content']);
        $a = $c['asset_id'] ? cc_asset_get($pdo, (int)$c['asset_id']) : null;
        $lines[] = ($i + 1) . '. ' . ($a ? cc_ai_asset_line($a) : '') . ' ' . ($x['caption'] ?? $x['text'] ?? '');
    }
    if (!$lines) throw new RuntimeException('O storyboard ainda não tem cartões.');
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['shots'], 'properties' => ['shots' => ['type' => 'array', 'items' => [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['title', 'description', 'camera', 'seconds', 'notes'],
        'properties' => ['title' => ['type' => 'string'], 'description' => ['type' => 'string'], 'camera' => ['type' => 'string'],
                         'seconds' => ['type' => 'number'], 'notes' => ['type' => 'string']]]]]];
    $res = cc_claude($pdo, 'cc-shots', [['type' => 'text', 'text' => "Brief:\n" . mb_substr(cc_brief_text($pdo, $board), 0, 3000) . "\n\nStoryboard, pela ordem:\n" . implode("\n", $lines)
        . "\n\nTransforma o storyboard numa lista de planos para a rodagem/produção: um plano por quadro (junta só quando são claramente o mesmo plano), com título curto, descrição, câmara (lente/movimento), duração estimada em segundos e notas de produção."]],
        $schema, ['ctx' => ['board' => (int)$board['id']], 'max' => 12000]);
    return array_values(array_filter((array)($res['data']['shots'] ?? []), 'is_array'));
}
