<?php
/**
 * topper. admin — bootstrap
 *
 * Every page includes this first. It opens the database, starts a hardened
 * session, and exposes the auth / CSRF / formatting helpers.
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) define('TOPPER_ADMIN', true);

// ── ambiente: produção ou máquina local? ────────────────────
// Em localhost não existe certificado TLS, por isso o salto para
// HTTPS e os cookies "secure" teriam de falhar. Fora de localhost
// nada disto se aplica — o comportamento em produção é o mesmo.
if (!defined('IS_LOCAL')) {
    $__host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
    define('IS_LOCAL', in_array($__host, ['localhost', '127.0.0.1', '::1'], true));
}

$CONFIG = require __DIR__ . '/config.php';

// ── force HTTPS ─────────────────────────────────────────────
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (!$https && !IS_LOCAL && PHP_SAPI !== 'cli') {
    $host = $_SERVER['HTTP_HOST'] ?? 'digitaltopper.xyz';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/admin/';
    header('Location: https://' . $host . $uri, true, 301);
    exit;
}

// ── endereço antigo (§10.3) ─────────────────────────────────
// A plataforma está em topperhub.xyz. As páginas do endereço antigo são redirecionadas no
// .htaccess; um envio ou pedido de dados de um separador antigo chega aqui e recebe uma
// explicação, em vez de os dados se perderem num redirecionamento.
if (!IS_LOCAL && PHP_SAPI !== 'cli'
    && preg_match('/^(www\.)?topperdigital\.xyz$/', strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]))) {
    $__moved = 'A plataforma mudou-se para topperhub.xyz e este separador ainda é do endereço antigo. Abra https://topperhub.xyz e repita o que estava a fazer (nada foi gravado).';
    http_response_code(409);
    header('Cache-Control: no-store');
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $__moved], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pt"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>topper.</title>'
           . '<p style="font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 16px">' . htmlspecialchars($__moved)
           . '<br><br><a href="https://topperhub.xyz/admin/">Abrir topperhub.xyz</a> · ou volte atrás no browser para copiar o que tinha escrito.</p></html>';
    }
    exit;
}

// ── headers: keep this page out of indexes, caches, frames ──
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
// O Drive envia/lê ficheiros diretamente do Cloudflare R2 (origem tirada do r2_config.php).
require_once __DIR__ . '/r2.php';
$__r2 = r2_origin();
$__r2 = $__r2 !== '' ? ' ' . $__r2 : '';
header(
    "Content-Security-Policy: default-src 'self'; "
  . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
  . "font-src 'self' https://fonts.gstatic.com; "
  . "script-src 'self' 'unsafe-inline'; "
  . "connect-src 'self'$__r2; "
  . "media-src 'self' blob:$__r2; "
  . "img-src 'self' data: blob:$__r2; "
  . "frame-src 'self'$__r2; "
  . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'"
);

// ── session ─────────────────────────────────────────────────
// Páginas públicas sem sessão (ex.: a proposta ao cliente, §19.4): não criam cookie nem tocam na sessão de quem esteja ligado.
if (session_status() === PHP_SESSION_NONE && !defined('TOPPER_NO_SESSION')) {
    session_name('topper_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/admin',
        'secure'   => !IS_LOCAL,
        'httponly' => true,
        // Lax e não Strict: com Strict, o Safari não enviava o cookie ao voltar atrás de um PDF
        // (ou ao abrir um link de um email) e a página de entrada criava uma sessão nova — logout.
        // Os POST de outros sites continuam sem cookie, e todos os formulários levam token CSRF.
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── database ────────────────────────────────────────────────
try {
    // Na bancada de testes local pode usar-se SQLite (db_dsn); em produção é sempre MySQL.
    $__dsn = (IS_LOCAL && !empty($CONFIG['db_dsn']))
        ? $CONFIG['db_dsn']
        : "mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4";
    $pdo = new PDO(
        $__dsn,
        $CONFIG['db_user'],
        $CONFIG['db_pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (Throwable $e) {
    // Never echo the driver message — it can leak credentials and paths.
    http_response_code(500);
    exit('Database unavailable. Check inc/config.php.');
}

// ── base de dados actualizada? ──────────────────────────────
// Uma consulta barata por pedido. Quando a versão bate certo não
// acontece mais nada; quando está atrasada, o site actualiza-se
// sozinho — não é preciso mexer no phpMyAdmin depois de publicar.
require_once __DIR__ . '/migrate.php';
require_once __DIR__ . '/perm.php';
require_once __DIR__ . '/deals.php';

try {
    $__ver = (int)$pdo->query("SELECT svalue FROM settings WHERE skey = 'schema_version'")
                      ->fetchColumn();
} catch (Throwable $e) {
    $__ver = 0;
}

if ($__ver < SCHEMA_VERSION) {
    try {
        run_migrations($pdo, $__ver);
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Não foi possível actualizar a base de dados. Contacte o suporte.');
    }
}

// ── CSRF ────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** Call at the top of every POST handler. */
function csrf_check(): void {
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('Pedido inválido. Recarregue a página e tente outra vez.');
    }
}

// ── auth ────────────────────────────────────────────────────
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/** Superadmin ou admin. */
function is_admin(): bool {
    $u = current_user();
    return $u !== null && role_is_admin((string)$u['role']);
}

/** Redirect to login unless a valid, unexpired session exists. */
function require_login(): array {
    global $CONFIG;
    $u = current_user();

    if ($u === null) {
        header('Location: /admin/index.php');
        exit;
    }

    $now      = time();
    [$idleMax, $hardMax] = session_limits();
    $lastSeen = (int)($_SESSION['last_seen'] ?? 0);
    $started  = (int)($_SESSION['started'] ?? 0);

    if (($now - $lastSeen) > $idleMax || ($now - $started) > $hardMax) {
        logout_user();
        header('Location: /admin/index.php?expired=1');
        exit;
    }

    $_SESSION['last_seen'] = $now;

    // O papel, o departamento e o estado da conta podem mudar a meio da
    // sessão (na aba Equipa): relê-se a conta em cada pedido. Uma conta
    // desativada perde o acesso logo no pedido seguinte.
    global $pdo;
    $st = $pdo->prepare('SELECT id, name, email, role, department_id, is_active, deactivated_at FROM users WHERE id = ?');
    $st->execute([(int)$u['id']]);
    $row = $st->fetch();
    if (!$row || (int)$row['is_active'] !== 1 || !empty($row['deactivated_at'])) {
        logout_user();
        header('Location: /admin/index.php');
        exit;
    }
    $_SESSION['user'] = session_user_fields($row);

    // O Drive e o seu assistente pedem a aba Drive. Verifica-se aqui para os
    // ficheiros do Drive ficarem como estão.
    if (in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['drive.php', 'drive_api.php', 'ai_api.php'], true)) {
        require_perm('drive', PERM_VIEW);
    }
    return $_SESSION['user'];
}

function session_user_fields(array $row): array {
    return [
        'id'            => (int)$row['id'],
        'name'          => $row['name'],
        'email'         => $row['email'],
        'role'          => $row['role'],
        'department_id' => $row['department_id'] !== null ? (int)$row['department_id'] : null,
    ];
}

function login_user(array $row): void {
    // New session ID on privilege change — blocks session fixation.
    session_regenerate_id(true);
    $_SESSION['user'] = session_user_fields($row);
    $_SESSION['started']   = time();
    $_SESSION['last_seen'] = time();
    $_SESSION['csrf']      = bin2hex(random_bytes(32));
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ── login throttling ────────────────────────────────────────
function client_ip_bin(): ?string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $packed = @inet_pton($ip);
    return $packed === false ? null : $packed;
}

function record_attempt(PDO $pdo, string $email, bool $ok): void {
    $st = $pdo->prepare(
        'INSERT INTO login_attempts (email, ip, succeeded) VALUES (?, ?, ?)'
    );
    $st->execute([clip($email, 190), client_ip_bin(), $ok ? 1 : 0]);
}

/** True when this email or IP has burned through its allowance. */
function is_locked_out(PDO $pdo, string $email): bool {
    global $CONFIG;
    $window = (int)$CONFIG['attempt_window_minutes'];
    $max    = max(3, (int)setting($pdo, 'login_max_attempts', (int)$CONFIG['max_attempts']));

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE succeeded = 0
            AND attempted_at > (NOW() - INTERVAL ? MINUTE)
            AND (email = ? OR (ip IS NOT NULL AND ip = ?))'
    );
    $st->execute([$window, $email, client_ip_bin()]);
    return (int)$st->fetchColumn() >= $max;
}

/** Os limites da sessão em segundos [inatividade, máximo] (Definições › Segurança, ou o ficheiro de configuração). */
function session_limits(): array {
    global $CONFIG, $pdo;
    $idle = (int)setting($pdo, 'session_idle_min', (int)$CONFIG['idle_timeout_minutes']);
    $hard = (int)setting($pdo, 'session_max_hours', (int)$CONFIG['absolute_timeout_hours']);
    return [max(5, min(480, $idle)) * 60, max(1, min(72, $hard)) * 3600];
}

// ── preferências pessoais (Perfil, §20.1) ──────────────────
/** As categorias de notificações do Perfil (§20.1): chave → [nome, o que inclui, tem email?]. */
const NOTIF_CATS = [
    'lead'    => ['Leads', 'Leads minhas a aproximar-se ou em atraso', false],
    'queue'   => ['Fila «Por atribuir»', 'Leads novas por atribuir (chefes de equipa e admins)', false],
    'deal'    => ['Pipeline e negócios', 'Negócios parados, respostas de clientes, gates de produção e rondas esgotadas', true],
    'invoice' => ['Por receber', 'Valores em atraso (quem vê o Financeiro)', false],
    'team'    => ['Equipa', 'Mensagens, pedidos de reunião, pedidos de ajuda e férias', true],
];

/** As preferências pessoais (users.prefs em JSON): notificações e vista inicial de cada aba. */
function user_prefs(PDO $pdo, int $uid): array {
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $p = [];
    try {
        if (db_has_column($pdo, 'users', 'prefs')) {
            $st = $pdo->prepare('SELECT prefs FROM users WHERE id = ?');
            $st->execute([$uid]);
            $p = json_decode((string)$st->fetchColumn(), true) ?: [];
        }
    } catch (Throwable $e) { $p = []; }
    return $cache[$uid] = (is_array($p) ? $p : []);
}

/** Esta pessoa quer esta categoria por este canal ('app' ou 'email')? Por defeito, sim. */
function user_wants(PDO $pdo, int $uid, string $cat, string $channel = 'app'): bool {
    $p = user_prefs($pdo, $uid);
    return !isset($p['notif'][$cat][$channel]) || (int)$p['notif'][$cat][$channel] === 1;
}

/** A vista com que uma aba abre para esta pessoa ('' = a da aba). */
function user_start_view(PDO $pdo, int $uid, string $tab): string {
    return (string)(user_prefs($pdo, $uid)['start'][$tab] ?? '');
}

// ── settings ────────────────────────────────────────────────
function settings(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach ($pdo->query('SELECT skey, svalue FROM settings') as $r) {
        $cache[$r['skey']] = $r['svalue'];
    }
    return $cache;
}

function setting(PDO $pdo, string $key, $default = null) {
    $s = settings($pdo);
    return $s[$key] ?? $default;
}

/** Uma definição em lista (uma linha por item, Definições §20), ou a lista de origem. */
function setting_lines(PDO $pdo, string $key, array $default): array {
    $raw = setting($pdo, $key);
    if ($raw === null) return array_values($default);
    $out = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$raw)), fn($x) => $x !== ''));
    return $out ?: array_values($default);
}

// ── formatting / input ──────────────────────────────────────
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function eur(float $n): string {
    return '€' . number_format($n, 2, ',', ' ');
}

/**
 * Shorten for display without depending on the mbstring extension
 * (not guaranteed on shared hosting) and without cutting a UTF-8
 * character in half, which would render as a replacement glyph.
 */
function shorten(?string $s, int $len, string $tail = '…'): string {
    $s = trim((string)$s);
    if ($s === '') return '';
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($s, 0, $len, $tail, 'UTF-8');
    }
    if (strlen($s) <= $len) return $s;
    $cut = substr($s, 0, $len);
    // step back off a partial multi-byte sequence
    while ($cut !== '' && (ord($cut[strlen($cut) - 1]) & 0xC0) === 0x80) {
        $cut = substr($cut, 0, -1);
    }
    if ($cut !== '' && (ord($cut[strlen($cut) - 1]) & 0x80) === 0x80) {
        $cut = substr($cut, 0, -1);
    }
    return $cut . $tail;
}

/** Byte-safe truncation for storage (columns are sized in bytes). */
function clip(string $s, int $bytes): string {
    return function_exists('mb_substr')
        ? mb_substr($s, 0, $bytes)
        : substr($s, 0, $bytes);
}

function eur0(float $n): string {
    return '€' . number_format($n, 0, ',', ' ');
}

/** Accepts "1.234,56" and "1,234.56" alike. Returns a float >= 0. */
function money_in($raw): float {
    $s = trim((string)$raw);
    if ($s === '') return 0.0;
    $s = preg_replace('/[^\d.,\-]/', '', $s);
    $hasC = strpos($s, ',') !== false;
    $hasD = strpos($s, '.') !== false;
    if ($hasC && $hasD) {
        $s = strrpos($s, ',') > strrpos($s, '.')
            ? str_replace(['.', ','], ['', '.'], $s)
            : str_replace(',', '', $s);
    } elseif ($hasC) {
        $s = str_replace(',', '.', $s);
    }
    return round(abs((float)$s), 2);
}

const MONTHS_PT = [1=>'Janeiro','Fevereiro','Março','Abril','Maio','Junho',
                   'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

function month_name(?int $m): string {
    return ($m && isset(MONTHS_PT[$m])) ? MONTHS_PT[$m] : '—';
}

/** «Julho 2026» — a data de um registo, como se lê numa tabela. */
function month_year(?int $m, int $year): string {
    return month_name($m) . ' ' . $year;
}

// ── IVA ─────────────────────────────────────────────────────
//
// Os dados (trabalhos, custos, IVA, totais) vivem em inc/data.php; aqui ficam
// só as peças que os formulários usam.

/** As três hipóteses, prontas para um <select>. */
const VAT_MODES = [
    'none' => 'Sem IVA',
    'plus' => '+ IVA',
    'incl' => 'IVA incluído',
];


/** Este registo é tributado? */
function has_vat(array $p): bool {
    return (int)($p['has_vat'] ?? 1) === 1;
}

/**
 * A REGRA: na base de dados um valor está SEMPRE sem IVA.
 *
 * Só que as faturas chegam das duas maneiras — umas dizem o valor
 * limpo, outras já vêm com o IVA lá dentro. Em vez de guardar as
 * duas coisas (e passar a vida a adivinhar qual é qual), aceita-se
 * o que vier no formulário e converte-se aqui, à entrada:
 *
 *     123,00 c/ IVA  ÷ 1,23  →  100,00 guardados
 *
 * A caixa que liga esta conversão nunca é guardada. É uma instrução
 * de momento, não um atributo do registo — por isso voltar a gravar
 * o mesmo registo não divide outra vez.
 */
function net_from_input($raw, bool $includesVat, float $rate): float {
    $v = money_in($raw);
    if (!$includesVat || $rate <= 0) return $v;
    return round($v / (1 + $rate / 100), 2);
}
