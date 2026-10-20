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
  . "font-src https://fonts.gstatic.com; "
  . "script-src 'self' 'unsafe-inline'; "
  . "connect-src 'self'$__r2; "
  . "media-src 'self'$__r2; "
  . "img-src 'self' data:$__r2; "
  . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'"
);

// ── session ─────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_name('topper_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/admin',
        'secure'   => !IS_LOCAL,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── database ────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4",
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
    $idleMax  = (int)$CONFIG['idle_timeout_minutes'] * 60;
    $hardMax  = (int)$CONFIG['absolute_timeout_hours'] * 3600;
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
    $max    = (int)$CONFIG['max_attempts'];

    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE succeeded = 0
            AND attempted_at > (NOW() - INTERVAL ? MINUTE)
            AND (email = ? OR (ip IS NOT NULL AND ip = ?))'
    );
    $st->execute([$window, $email, client_ip_bin()]);
    return (int)$st->fetchColumn() >= $max;
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

// ── the money maths, in one place ───────────────────────────

/**
 * Real cost of a project: either the number typed in, or the sum of the
 * crew fees when the project is set to roll up (mirrors G7='DELTA Y'!C21).
 */
function project_expenses(PDO $pdo, array $p): float {
    if ($p['expenses_mode'] === 'crew') {
        return crew_totals($pdo, (int)$p['id'], vat_rate($pdo))['net'];
    }
    return (float)$p['expenses_manual'];
}

// ── custos de um trabalho ───────────────────────────────────
//
// Cada custo guarda o valor TAL COMO FOI ESCRITO, mais aquilo que
// esse valor é. São as três hipóteses que aparecem numa fatura:
//
//   none  Sem IVA        1800 →  custo 1800,00   IVA    0,00
//   plus  + IVA 23%      1800 →  custo 1800,00   IVA  414,00
//   incl  IVA incluído   1800 →  custo 1463,41   IVA  336,59
//
// O custo que conta para o lucro é sempre o valor limpo: o IVA
// não é gasto, é adiantado ao fornecedor e deduzido a seguir.

function vat_rate(PDO $pdo): float {
    return (float)(setting($pdo, 'vat_rate', 23));
}

/**
 * O valor escrito. Serve os custos de trabalho (crew.fee) e os da
 * empresa (company_costs.amount) sem precisarem de código separado.
 */
function cost_amount(array $c): float {
    return (float)($c['fee'] ?? $c['amount'] ?? 0);
}

/** O que este custo pesa no lucro. */
function cost_net(array $c, float $rate): float {
    $fee = cost_amount($c);
    return ($c['vat_mode'] ?? 'plus') === 'incl'
        ? round($fee / (1 + $rate / 100), 2)
        : $fee;
}

/** O IVA deste custo, que se deduz. */
function cost_vat(array $c, float $rate): float {
    $fee  = cost_amount($c);
    $mode = $c['vat_mode'] ?? 'plus';
    if ($mode === 'plus') return round($fee * $rate / 100, 2);
    if ($mode === 'incl') return round($fee - $fee / (1 + $rate / 100), 2);
    return 0.0;
}

/** O que sai mesmo da conta para pagar este custo. */
function cost_total(array $c, float $rate): float {
    return round(cost_net($c, $rate) + cost_vat($c, $rate), 2);
}

/** Somatório dos custos de um trabalho, separado em limpo e IVA. */
function crew_totals(PDO $pdo, int $projectId, float $rate): array {
    $st = $pdo->prepare('SELECT fee, vat_mode, is_confirmed FROM crew WHERE project_id = ?');
    $st->execute([$projectId]);

    $net = 0.0; $vat = 0.0; $confirmed = 0.0;
    foreach ($st->fetchAll() as $c) {
        $n = cost_net($c, $rate);
        $net += $n;
        $vat += cost_vat($c, $rate);
        if ((int)$c['is_confirmed'] === 1) $confirmed += $n;
    }
    return [
        'net'       => round($net, 2),
        'vat'       => round($vat, 2),
        'total'     => round($net + $vat, 2),
        'confirmed' => round($confirmed, 2),
    ];
}

/** As três hipóteses, prontas para um <select>. */
const VAT_MODES = [
    'none' => 'Sem IVA',
    'plus' => '+ IVA',
    'incl' => 'IVA incluído',
];

/**
 * Custos da empresa — a estrutura que existe mesmo sem trabalhos.
 * Devolve as linhas já com o valor limpo e o IVA calculados.
 */
function company_costs(PDO $pdo, float $rate): array {
    $rows = $pdo->query(
        'SELECT * FROM company_costs ORDER BY year DESC, COALESCE(month, 13), id'
    )->fetchAll();

    foreach ($rows as &$c) {
        $c['net'] = cost_net($c, $rate);
        $c['vat'] = cost_vat($c, $rate);
    }
    return $rows;
}

/** Budget com IVA incluído. Segue a caixa «Tem IVA» do registo. */
function budget_gross(array $p, float $vatRate): float {
    return round((float)$p['budget_net'] + project_vat_out($p, $vatRate), 2);
}

// ── IVA ─────────────────────────────────────────────────────
//
// Manda uma coisa só: a caixa «Tem IVA» de cada registo.
//
//   Entrada com IVA → cobra-se 23% ao cliente  (IVA liquidado)
//   Saída com IVA   → pagou-se 23% e deduz-se  (IVA suportado)
//
// A diferença entre os dois é o que se entrega ao Estado.
//
// Ser um trabalho internacional já não decide nada aqui: um
// trabalho de exportação normalmente não leva IVA, mas isso
// marca-se desligando a caixa — assim o caso raro em que leva
// (ou o gasto nacional que vem sem IVA) também fica certo.
//
// Todos os valores guardados na base são SEM IVA; o imposto
// acresce sempre por cima.

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

/** IVA cobrado ao cliente por este trabalho. Zero nos gastos. */
function project_vat_out(array $p, float $rate): float {
    if ($p['kind'] !== 'job' || !has_vat($p)) return 0.0;
    return round((float)$p['budget_net'] * $rate / 100, 2);
}

/**
 * IVA suportado neste registo — o que se pode deduzir.
 *
 * Segue o mesmo caminho do project_expenses(): quando os gastos
 * somam a equipa, conta só quem fatura com IVA (cada pessoa tem a
 * sua marca); quando são um valor manual, segue a caixa do registo.
 */
function project_vat_in(PDO $pdo, array $p, float $rate): float {
    if ($p['expenses_mode'] === 'crew') {
        return crew_totals($pdo, (int)$p['id'], $rate)['vat'];
    }
    if (!has_vat($p)) return 0.0;
    return round((float)$p['expenses_manual'] * $rate / 100, 2);
}

/**
 * Conta-corrente de IVA do ano.
 *
 * Positivo → há imposto a entregar ao Estado.
 * Negativo → cobrou menos do que suportou e fica com crédito
 *            para o período seguinte.
 */
function year_vat(PDO $pdo, int $year): array {
    $st = $pdo->prepare('SELECT * FROM projects WHERE year = ?');
    $st->execute([$year]);

    $s    = settings($pdo);
    $rate = (float)($s['vat_rate'] ?? 23);

    $out = 0.0; $in = 0.0;
    $nationalNet = 0.0; $intlNet = 0.0;

    foreach ($st->fetchAll() as $p) {
        $out += project_vat_out($p, $rate);
        $in  += project_vat_in($pdo, $p, $rate);
        if ($p['kind'] === 'job') {
            if (has_vat($p)) $nationalNet += (float)$p['budget_net'];
            else             $intlNet     += (float)$p['budget_net'];
        }
    }

    return [
        'rate'         => $rate,
        'out'          => round($out, 2),
        'in'           => round($in, 2),
        'balance'      => round($out - $in, 2),
        'national_net' => round($nationalNet, 2),
        'intl_net'     => round($intlNet, 2),
    ];
}

/**
 * Matéria coletável estimada.
 *
 * A base é configurável em Definições porque depende do regime:
 *   irc_base        → 'gastos' | 'faturacao' | 'lucro'
 *   irc_coefficient → coeficiente aplicado a essa base (ex.: 0,10)
 *
 * Por omissão reproduz a fórmula da folha de cálculo:
 * Gastos Totais × 0,10.
 */
function taxable_base(array $totals, array $s): float {
    switch ($s['irc_base'] ?? 'gastos') {
        case 'faturacao': $v = $totals['revenue']; break;
        case 'lucro':     $v = $totals['profit'];  break;
        default:          $v = $totals['costs'];   break;
    }
    $coef = (float)($s['irc_coefficient'] ?? 1);
    return max(0.0, round($v * $coef, 2));
}

/**
 * IRC por escalões sobre a matéria coletável, mais derrama.
 *
 * Equivale a:
 *   SE(base <= limite; base × reduzida;
 *      limite × reduzida + (base − limite) × geral)
 *
 * As taxas vivem nas Definições — quando o IRC geral descer para 18% em
 * 2027 muda-se lá, sem tocar no código.
 */
function compute_tax(float $base, array $s): array {
    if ($base <= 0) {
        return ['base' => 0.0, 'irc' => 0.0, 'derrama' => 0.0, 'total' => 0.0, 'effective' => 0.0];
    }
    $reduced   = (float)($s['irc_reduced_rate'] ?? 15) / 100;
    $threshold = (float)($s['irc_threshold'] ?? 50000);
    $general   = (float)($s['irc_general_rate'] ?? 19) / 100;
    $derramaR  = (float)($s['derrama_rate'] ?? 0) / 100;

    $irc = $base <= $threshold
        ? $base * $reduced
        : $threshold * $reduced + ($base - $threshold) * $general;

    $derrama = $base * $derramaR;
    $total   = round($irc + $derrama, 2);

    return [
        'base'      => round($base, 2),
        'irc'       => round($irc, 2),
        'derrama'   => round($derrama, 2),
        'total'     => $total,
        'effective' => round($total / $base * 100, 2),
    ];
}

/** Headline numbers for a year. */
function year_totals(PDO $pdo, int $year): array {
    $st = $pdo->prepare('SELECT * FROM projects WHERE year = ?');
    $st->execute([$year]);
    $rows = $st->fetchAll();

    $s   = settings($pdo);
    $vat = (float)($s['vat_rate'] ?? 23);

    $revenue = 0.0; $costs = 0.0; $unpaid = 0.0; $pipeline = 0.0;

    // A estrutura da empresa (contabilidade, alojamento…) também é gasto,
    // mesmo não pertencendo a trabalho nenhum.
    $cc = $pdo->prepare('SELECT * FROM company_costs WHERE year = ?');
    $cc->execute([$year]);
    foreach ($cc->fetchAll() as $c) {
        $costs += cost_net($c, $vat);
    }

    foreach ($rows as $p) {
        $exp = project_expenses($pdo, $p);
        $costs += $exp;
        if ($p['kind'] === 'job') {
            $revenue += (float)$p['budget_net'];
            if ((int)$p['is_paid'] === 0) $unpaid += (float)$p['budget_net'];
            $pipeline += (float)$p['budget_net'] - $exp;
        }
    }
    $profit = $revenue - $costs;

    $totals = [
        'revenue' => round($revenue, 2),
        'costs'   => round($costs, 2),
        'profit'  => round($profit, 2),
    ];
    $base = taxable_base($totals, $s);

    return $totals + [
        'unpaid'   => round($unpaid, 2),
        'tax'      => compute_tax($base, $s),
        'vat_rate' => $vat,
        'count'    => count($rows),
    ];
}

/** Years that have data, newest first, always including the current one. */
function available_years(PDO $pdo): array {
    $years = $pdo->query('SELECT DISTINCT year FROM projects ORDER BY year DESC')
                 ->fetchAll(PDO::FETCH_COLUMN);
    $years = array_map('intval', $years);
    $now   = (int)date('Y');
    if (!in_array($now, $years, true)) array_unshift($years, $now);
    rsort($years);
    return $years;
}
