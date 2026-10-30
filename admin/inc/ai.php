<?php
/**
 * topper. admin — o assistente: cliente da API da Anthropic (Claude), sem SDK.
 *
 * Um POST JSON a {base_url}/v1/messages com cURL (o SDK oficial precisa de
 * Composer, que o alojamento não tem). Aqui vivem também as regras que valem
 * para o chat e para o índice de conteúdo:
 *
 *   ai_config()          a configuração (inc/ai_config.php), ou null
 *   ai_call()            um pedido, com novas tentativas, registo do gasto e erros em português
 *   ai_private()         o que o assistente nunca vê (Empresa/Documentos legais/ e .topper/)
 *   ai_month_spend_usd() gasto do mês civil de Lisboa; ai_cap_usd(), ai_budget_left()
 *   ai_fold()            minúsculas e sem acentos, com o mesmo número de caracteres
 *
 * Preços do claude-haiku-5-5 (USD por milhão de tokens): prompt até 100 000 tokens
 * → 0,10 entrada / 0,50 saída; acima → 0,50 / 2,50. Leitura de cache = 0,1× a
 * entrada, escrita (5 min) = 1,25×. O limite mensal está em € mas conta-se como
 * USD — 1 USD vale menos de 1 €, por isso o gasto real fica sempre abaixo.
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const AI_MODEL_DEFAULT = 'claude-haiku-5-5';
const AI_API_VERSION   = '2023-06-01';
const AI_TIMEOUT       = 90;      // segundos por pedido
const AI_RETRIES       = 2;       // novas tentativas em 429/500/502/503/529 e falhas de rede
const AI_MIN_LEFT      = 0.02;    // USD: abaixo disto já não se chama a API
const AI_CAP_DEFAULT   = 10.0;

/**
 * [prompt até N tokens, USD/M entrada, USD/M saída] — o primeiro cartão que serve.
 * Modelos sem preço conhecido contam pelo cartão caro (melhor sobrestimar).
 */
const AI_PRICES = [
    'claude-haiku-5-5' => [[100000, 0.10, 0.50], [PHP_INT_MAX, 0.50, 2.50]],
];
const AI_PRICE_UNKNOWN = [[PHP_INT_MAX, 5.00, 25.00]];
const AI_CACHE_READ_X  = 0.1;
const AI_CACHE_WRITE_X = 1.25;

const AI_MONTHS = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
                   'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

/**
 * Um erro do assistente, já com mensagem para mostrar.
 * $kind: not_configured, budget, auth, billing, not_found, bad_request, too_large,
 *        rate, overloaded, server, network, timeout, stale (raciocínio de outra versão).
 */
class AiException extends RuntimeException {
    public string $kind;
    public int $status;
    public function __construct(string $message, string $kind = 'error', int $status = 0) {
        parent::__construct($message, $status);
        $this->kind = $kind;
        $this->status = $status;
    }
}

// ── configuração ────────────────────────────────────────────

/** ['api_key', 'model', 'monthly_cap_eur', 'base_url'], ou null se faltar a chave (ou for o exemplo). */
function ai_config(): ?array {
    static $c = false;
    if ($c !== false) return $c;
    $c = null;
    $f = __DIR__ . '/ai_config.php';
    if (!is_file($f)) return null;
    $raw = require $f;
    if (!is_array($raw)) return null;
    $key = trim((string)($raw['api_key'] ?? ''));
    if ($key === '' || strlen($key) < 16 || strpos($key, '…') !== false || stripos($key, 'COLE_') === 0
        || preg_match('/\s/', $key)) return null;
    $base = rtrim(trim((string)($raw['base_url'] ?? 'https://api.anthropic.com')), '/');
    if (!preg_match('~^(https?)://([A-Za-z0-9.-]+)(:\d+)?$~', $base, $m)) return null;
    // A chave nunca segue em claro pela rede: http só para a própria máquina (testes).
    if ($m[1] !== 'https' && !in_array(strtolower($m[2]), ['127.0.0.1', 'localhost'], true)) return null;
    $model = trim((string)($raw['model'] ?? ''));
    if (!preg_match('/^[a-z0-9][a-z0-9.-]{2,63}$/', $model)) $model = AI_MODEL_DEFAULT;
    $cap = $raw['monthly_cap_eur'] ?? AI_CAP_DEFAULT;
    $cap = is_numeric($cap) ? max(0.0, (float)$cap) : AI_CAP_DEFAULT;
    return $c = ['api_key' => $key, 'model' => $model, 'monthly_cap_eur' => $cap, 'base_url' => $base];
}

function ai_model(): string {
    $c = ai_config();
    return $c ? $c['model'] : AI_MODEL_DEFAULT;
}

// ── privacidade ─────────────────────────────────────────────

/**
 * Minúsculas e sem acentos, carácter a carácter: o resultado tem sempre o mesmo
 * número de caracteres que o original (as posições batem certo para os excertos).
 */
function ai_fold(string $s): string {
    static $map = null;
    if ($map === null) {
        $map = [];
        $groups = [
            'a' => 'àáâãäåāăąǎạảấầẩẫậắằẳẵặÀÁÂÃÄÅĀĂĄǍẠẢẤẦẨẪẬẮẰẲẴẶªæÆ',
            'c' => 'çćĉċčÇĆĈĊČ', 'd' => 'ďđðĎĐÐ',
            'e' => 'èéêëēĕėęěẹẻẽếềểễệÈÉÊËĒĔĖĘĚẸẺẼẾỀỂỄỆ',
            'g' => 'ĝğġģĜĞĠĢ', 'h' => 'ĥħĤĦ',
            'i' => 'ìíîïĩīĭįıỉịÌÍÎÏĨĪĬĮİỈỊ', 'j' => 'ĵĴ', 'k' => 'ķĶ',
            'l' => 'ĺļľŀłĹĻĽĿŁ', 'n' => 'ñńņňÑŃŅŇ',
            'o' => 'òóôõöøōŏőọỏốồổỗộớờởỡợơºœÒÓÔÕÖØŌŎŐỌỎỐỒỔỖỘỚỜỞỠỢƠŒ',
            'r' => 'ŕŗřŔŖŘ', 's' => 'śŝşšșßŚŜŞŠȘ', 't' => 'ţťŧțŢŤŦȚ',
            'u' => 'ùúûüũūŭůűųưụủứừửữựÙÚÛÜŨŪŬŮŰŲƯỤỦỨỪỬỮỰ',
            'w' => 'ŵŴ', 'y' => 'ýÿŷỳỹỵÝŸŶỲỸỴ', 'z' => 'źżžŹŻŽ',
        ];
        foreach ($groups as $base => $chars) foreach (mb_str_split($chars, 1, 'UTF-8') as $ch) $map[$ch] = $base;
        // aspas e travessões tipográficos → ASCII (também um carácter cada)
        $map += ['‘' => "'", '’' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', "\u{00A0}" => ' '];
    }
    $s = strtr($s, $map);
    $s = strtr($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    if (!preg_match('/[^\x00-\x7F]/', $s)) return $s;
    $low = mb_strtolower($s, 'UTF-8');
    if (mb_strlen($low, 'UTF-8') === mb_strlen($s, 'UTF-8')) return $low;
    // um carácter que em minúsculas vira dois: fica como está
    $out = '';
    foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
        $l = mb_strtolower($ch, 'UTF-8');
        $out .= mb_strlen($l, 'UTF-8') === 1 ? $l : $ch;
    }
    return $out;
}

/**
 * Privado para o assistente: tudo debaixo de «Empresa/Documentos legais/» (sem
 * maiúsculas nem acentos, nos dois primeiros segmentos) e o sistema (.topper/).
 * Nunca indexado em conteúdo, nunca listado, lido nem proposto.
 */
function ai_private(string $key): bool {
    if (strncmp($key, '.topper/', 8) === 0 || $key === '.topper') return true;
    $seg = explode('/', ltrim($key, '/'), 3);
    if (count($seg) < 2) return false;
    $norm = static fn(string $x): string => trim((string)preg_replace('/\s+/u', ' ', ai_fold($x)));
    return $norm($seg[0]) === 'empresa' && $norm($seg[1]) === 'documentos legais';
}

// ── gasto e limite ──────────────────────────────────────────

/** O mês civil de Lisboa que contém $now: [início UTC, fim UTC, «outubro de 2026»]. */
function ai_month_bounds(?int $now = null): array {
    $tz = new DateTimeZone('Europe/Lisbon');
    $d = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz);
    $start = $d->setDate((int)$d->format('Y'), (int)$d->format('n'), 1)->setTime(0, 0, 0);
    $end = $start->modify('+1 month');
    $utc = new DateTimeZone('UTC');
    return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            AI_MONTHS[(int)$d->format('n')] . ' de ' . $d->format('Y')];
}

/** O que já se gastou este mês (USD estimados, chat + índice). */
function ai_month_spend_usd(PDO $pdo): float {
    [$a, $b] = ai_month_bounds();
    $st = $pdo->prepare('SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage WHERE created_at >= ? AND created_at < ?');
    $st->execute([$a, $b]);
    return round((float)$st->fetchColumn(), 6);
}

/** O limite mensal: os € do ai_config.php contados como USD. */
function ai_cap_usd(): float {
    $c = ai_config();
    return $c ? (float)$c['monthly_cap_eur'] : AI_CAP_DEFAULT;
}

function ai_budget_left(PDO $pdo): float {
    return ai_cap_usd() - ai_month_spend_usd($pdo);
}

/** «10 €», «12,50 €». */
function ai_eur(float $v): string {
    return (abs($v - round($v)) < 0.005 ? number_format($v, 0, ',', ' ') : number_format($v, 2, ',', ' ')) . ' €';
}

function ai_budget_message(): string {
    return 'Chegou ao limite mensal de ' . ai_eur(ai_cap_usd()) . ' do assistente. Volta a funcionar no dia 1.';
}

/** Tokens do prompt = entrada + escrita de cache + leitura de cache (decide o cartão de preços). */
function ai_prompt_tokens(array $usage): int {
    return (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0)
         + (int)($usage['cache_read_input_tokens'] ?? 0);
}

/** O custo de uma resposta, em USD. */
function ai_cost(string $model, array $usage): float {
    $cards = AI_PRICES[$model] ?? AI_PRICE_UNKNOWN;
    $prompt = ai_prompt_tokens($usage);
    $card = end($cards);
    foreach ($cards as $c) if ($prompt <= $c[0]) { $card = $c; break; }
    [, $in, $out] = $card;
    $usd = ((int)($usage['input_tokens'] ?? 0) * $in
          + (int)($usage['cache_creation_input_tokens'] ?? 0) * $in * AI_CACHE_WRITE_X
          + (int)($usage['cache_read_input_tokens'] ?? 0) * $in * AI_CACHE_READ_X
          + (int)($usage['output_tokens'] ?? 0) * $out) / 1000000;
    return round($usd, 6);
}

function ai_record_usage(PDO $pdo, string $purpose, string $model, array $usage, float $cost): void {
    $pdo->prepare('INSERT INTO ai_usage (created_at, purpose, model, input_tokens, output_tokens, cache_read, cache_write, cost_usd)
                   VALUES (?,?,?,?,?,?,?,?)')
        ->execute([gmdate('Y-m-d H:i:s'), substr($purpose, 0, 16), substr($model, 0, 64),
                   (int)($usage['input_tokens'] ?? 0), (int)($usage['output_tokens'] ?? 0),
                   (int)($usage['cache_read_input_tokens'] ?? 0), (int)($usage['cache_creation_input_tokens'] ?? 0), $cost]);
}

// ── o pedido ────────────────────────────────────────────────

/** Um POST cru. Devolve ['code', 'headers' => [lower => valor], 'body', 'errno', 'error']. */
function ai_http(array $cfg, string $json): array {
    if (!function_exists('curl_init')) throw new AiException('Este servidor não tem a extensão cURL do PHP ativa.', 'network');
    $ch = curl_init($cfg['base_url'] . '/v1/messages');
    $hdr = [];
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $cfg['api_key'],
            'anthropic-version: ' . AI_API_VERSION,
            'content-type: application/json',
            'accept: application/json',
            'Expect:',
        ],
        CURLOPT_TIMEOUT        => AI_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 10,
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

/** Quanto esperar antes da tentativa seguinte (respeita o retry-after, até 20 s). */
function ai_retry_wait(array $r, int $attempt): float {
    $ra = $r['headers']['retry-after'] ?? '';
    if ($ra !== '' && is_numeric($ra)) return max(0.2, min(20.0, (float)$ra));
    if ($ra !== '' && ($t = strtotime($ra)) !== false) return max(0.2, min(20.0, (float)($t - time())));
    return [1.0, 3.0, 6.0][$attempt] ?? 6.0;
}

/** A resposta de erro da API → AiException com mensagem em português. */
function ai_error(array $r): AiException {
    if ($r['code'] === 0) {
        if ($r['errno'] === CURLE_OPERATION_TIMEDOUT) {
            return new AiException('A Anthropic demorou demasiado a responder. Tente outra vez.', 'timeout', 504);
        }
        return new AiException('Não foi possível falar com a Anthropic (' . $r['error'] . '). Tente outra vez daqui a pouco.', 'network', 502);
    }
    $j = json_decode($r['body'], true);
    $apiMsg = is_array($j) ? trim((string)($j['error']['message'] ?? '')) : '';
    $code = $r['code'];
    switch (true) {
        case $code === 400 && strpos($apiMsg, 'bound to a different conversation') !== false:
            return new AiException('Esta conversa já não pode continuar (o assistente foi atualizado entretanto). Comece uma nova.', 'stale', 409);
        case $code === 400:
            return new AiException('A Anthropic recusou o pedido' . ($apiMsg !== '' ? ': ' . $apiMsg : '.'), 'bad_request', 400);
        case $code === 401:
            return new AiException('A chave da API da Anthropic não é válida. Confirme o admin/inc/ai_config.php.', 'auth', 401);
        case $code === 402:
            return new AiException('A conta da Anthropic não tem crédito disponível. Veja «Billing» em console.anthropic.com.', 'billing', 402);
        case $code === 403:
            return new AiException('A chave da API da Anthropic não tem permissão para este pedido.', 'auth', 403);
        case $code === 404:
            return new AiException('O modelo «' . ai_model() . '» não está disponível para esta chave da API.', 'not_found', 404);
        case $code === 413:
            return new AiException('O pedido é grande demais para a Anthropic.', 'too_large', 413);
        case $code === 429:
            return new AiException('O assistente recebeu demasiados pedidos (limite da Anthropic). Tente outra vez daqui a um minuto.', 'rate', 429);
        case $code === 529:
            return new AiException('A Anthropic está sobrecarregada neste momento. Tente outra vez daqui a pouco.', 'overloaded', 529);
        case $code >= 500:
            return new AiException('A Anthropic teve um erro (HTTP ' . $code . '). Tente outra vez daqui a pouco.', 'server', $code);
    }
    return new AiException('A Anthropic devolveu um erro (HTTP ' . $code . ')' . ($apiMsg !== '' ? ': ' . $apiMsg : '.'), 'server', $code);
}

/**
 * Um pedido a /v1/messages. $purpose: 'chat' ou 'index' (fica no registo do gasto).
 * Recusa se o mês já não tem orçamento. Até 2 novas tentativas em 429/500/502/503/529
 * e falhas de ligação, com espera crescente. Regista o uso e o custo.
 *
 * Devolve a resposta descodificada, mais:
 *   _content       o array «content» com os objetos JSON preservados (para guardar e reenviar tal e qual)
 *   _cost_usd      o custo deste pedido
 *   _prompt_tokens entrada + cache
 */
function ai_call(PDO $pdo, array $body, string $purpose): array {
    $cfg = ai_config();
    if (!$cfg) throw new AiException('O assistente ainda não está configurado: falta a chave da API da Anthropic em admin/inc/ai_config.php.', 'not_configured', 503);
    if (ai_budget_left($pdo) < AI_MIN_LEFT) throw new AiException(ai_budget_message(), 'budget', 402);
    $body = ['model' => $cfg['model']] + $body;
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new AiException('Não foi possível preparar o pedido ao assistente (' . json_last_error_msg() . ').', 'bad_request', 400);
    if (function_exists('set_time_limit')) @set_time_limit(AI_TIMEOUT * (AI_RETRIES + 1) + 60);

    for ($attempt = 0; ; $attempt++) {
        $r = ai_http($cfg, $json);
        if ($r['code'] === 200) break;
        $again = in_array($r['code'], [429, 500, 502, 503, 529], true)
              || ($r['code'] === 0 && $r['errno'] !== CURLE_OPERATION_TIMEDOUT);
        if (!$again || $attempt >= AI_RETRIES) {
            $e = ai_error($r);
            error_log('ai_call ' . $purpose . ': HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 300));
            throw $e;
        }
        usleep((int)(ai_retry_wait($r, $attempt) * 1000000));
    }

    $resp = json_decode($r['body'], true);
    $obj = json_decode($r['body'], false);
    if (!is_array($resp) || ($resp['type'] ?? '') !== 'message' || !is_array($resp['content'] ?? null) || !is_object($obj)) {
        throw new AiException('A Anthropic respondeu algo que não se percebe.', 'server', 502);
    }
    $usage = is_array($resp['usage'] ?? null) ? $resp['usage'] : [];
    $model = is_string($resp['model'] ?? null) && $resp['model'] !== '' ? $resp['model'] : (string)$body['model'];
    $cost = ai_cost($model, $usage);
    ai_record_usage($pdo, $purpose, $model, $usage, $cost);
    $resp['_content'] = is_array($obj->content ?? null) ? $obj->content : [];
    $resp['_cost_usd'] = $cost;
    $resp['_prompt_tokens'] = ai_prompt_tokens($usage);
    return $resp;
}

/** Os blocos «text» de uma resposta, pela ordem (os «thinking» e o resto ficam de fora). */
function ai_texts(array $content): array {
    $out = [];
    foreach ($content as $b) {
        $b = (array)$b;
        if (($b['type'] ?? '') === 'text' && is_string($b['text'] ?? null) && trim($b['text']) !== '') $out[] = $b['text'];
    }
    return $out;
}

// ── pequenas ajudas partilhadas (chat e índice) ─────────────

/** Diretório de cache (inc/cache), criado com a tranca .htaccess; null se não der. */
function ai_cache_dir(): ?string {
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) return null;
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return is_writable($dir) ? $dir : null;
}

/**
 * Uma tranca por nome (flock). Devolve o recurso, null se não houver onde a pôr
 * (segue sem tranca) ou false se outro pedido a tiver e $wait segundos não chegaram.
 */
function ai_lock(string $name, float $wait = 0.0) {
    $dir = ai_cache_dir();
    if ($dir === null) return null;
    $fh = @fopen($dir . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $name) . '.lock', 'c');
    if (!$fh) return null;
    $end = microtime(true) + $wait;
    while (!flock($fh, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $end) { fclose($fh); return false; }
        usleep(200000);
    }
    return $fh;
}

function ai_unlock($fh): void {
    if (is_resource($fh)) { flock($fh, LOCK_UN); fclose($fh); }
}

/** Um valor da tabela settings (sem a cache por pedido do bootstrap). */
function ai_setting_get(PDO $pdo, string $k): ?string {
    $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (string)$v;
}

function ai_setting_set(PDO $pdo, string $k, string $v): void {
    $up = $pdo->prepare('UPDATE settings SET svalue = ? WHERE skey = ?');
    $up->execute([$v, $k]);
    if ($up->rowCount() > 0) return;
    if (ai_setting_get($pdo, $k) === $v) return;   // o MySQL conta 0 linhas quando o valor não muda
    try {
        $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)')->execute([$k, $v]);
    } catch (PDOException $e) {
        $pdo->prepare('UPDATE settings SET svalue = ? WHERE skey = ?')->execute([$v, $k]);   // outro pedido inseriu entretanto
    }
}

/** Agora em UTC, para a base de dados. */
function ai_now(): string {
    return gmdate('Y-m-d H:i:s');
}

/** Uma data da base (UTC) → unix. */
function ai_ts($s): int {
    if ($s === null || $s === '') return 0;
    $t = strtotime((string)$s . ' UTC');
    return $t === false ? 0 : $t;
}

/**
 * Guarda a chave da Anthropic no servidor (inc/ai_config.php, fora do Git), depois de a confirmar na API (sem gastar:
 * só lista os modelos). A chave nunca volta ao browser. Devolve a mensagem para o ecrã.
 */
function ai_key_save(string $key, ?float $capEur = null): string {
    require_once __DIR__ . '/secrets.php';
    $key = trim($key);
    if (!preg_match('/^sk-ant-[A-Za-z0-9_-]{20,}$/', $key)) throw new RuntimeException('A chave não parece certa: as chaves da Anthropic começam por «sk-ant-».');
    [$code] = http_call('GET', ext_url('https://api.anthropic.com/v1/models?limit=1'), ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'], null, 15);
    if ($code === 401 || $code === 403) throw new RuntimeException('A Anthropic recusou a chave (' . $code . '). Nada foi gravado.');
    if ($code < 200 || $code >= 300) throw new RuntimeException('Não consegui confirmar a chave na Anthropic agora (resposta ' . $code . '). Nada foi gravado; tente daqui a pouco.');
    $f = __DIR__ . '/ai_config.php';
    $raw = (is_file($f) ? config_file_read($f) : null) ?? [];
    $raw['api_key'] = $key;
    $raw['monthly_cap_eur'] = $capEur !== null ? max(0.0, $capEur) : (float)($raw['monthly_cap_eur'] ?? AI_CAP_DEFAULT);
    config_file_write($f, "<?php\n// A chave da Anthropic (IA), escrita pela plataforma em Definições › Integrações › IA. Só no servidor: fora do Git e fechado à web.\nreturn " . var_export($raw, true) . ";\n");
    return 'Chave da Anthropic confirmada e guardada no servidor: a IA está ligada (limite de ' . ai_eur($raw['monthly_cap_eur']) . ' por mês).';
}

