<?php
/**
 * topper. — formulário de contacto do site
 *
 * Cada envio cria uma lead com origem «Site», sem responsável, na fila
 * «Por atribuir» do admin. Anti-spam: um campo escondido que as pessoas
 * não veem, um tempo mínimo de preenchimento e links a mais mandam a lead
 * para a quarentena (nada se perde); mais de 5 envios por hora do mesmo IP
 * são recusados. Um email ou telefone que já exista fica assinalado.
 */

declare(strict_types=1);

define('TOPPER_ADMIN', true);
$__host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
define('IS_LOCAL', in_array($__host, ['localhost', '127.0.0.1', '::1'], true));

$wantsJson = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
function reply(bool $ok, string $msg, int $code = 200): void {
    global $wantsJson;
    http_response_code($code);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
        header('Location: /?' . ($ok ? 'sent=1' : 'error=1') . '#contact', true, 303);
    }
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(false, 'Method not allowed.', 405);

$CONFIG = require __DIR__ . '/admin/inc/config.php';
require __DIR__ . '/admin/inc/migrate.php';
require __DIR__ . '/admin/inc/perm.php';
require __DIR__ . '/admin/inc/deals.php';
require __DIR__ . '/admin/inc/leads.php';
function current_user(): ?array { return null; }   // ninguém tem sessão aqui

try {
    $dsn = (IS_LOCAL && !empty($CONFIG['db_dsn']))
        ? $CONFIG['db_dsn'] : "mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    reply(false, 'Something went wrong. Please email info@topperdigital.xyz.', 500);
}

$in = static fn(string $k, int $n): string => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
$name    = $in('name', 160);
$email   = $in('email', 190);
$phone   = $in('phone', 40);
$company = $in('company', 160);
$service = $in('service', 40);
$message = $in('message', 4000);

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
    reply(false, 'Please fill in your name, a valid email and a message.', 422);
}

// Limite por IP: mais de 5 envios na última hora são recusados.
$ip = @inet_pton((string)($_SERVER['REMOTE_ADDR'] ?? '')) ?: null;
$st = $pdo->prepare('SELECT COUNT(*) FROM form_submissions WHERE ip ' . (db_is_sqlite($pdo) ? 'IS' : '<=>') . ' ? AND created_at > ?');
$st->execute([$ip, gmdate('Y-m-d H:i:s', time() - 3600)]);
if ((int)$st->fetchColumn() >= 5) reply(false, 'Too many messages in a short time. Please try again later or email us.', 429);
$pdo->prepare('INSERT INTO form_submissions (ip, created_at) VALUES (?, ?)')->execute([$ip, gmdate('Y-m-d H:i:s')]);

// Sinais de spam → quarentena.
$why = [];
if (trim((string)($_POST['website'] ?? '')) !== '') $why[] = 'campo escondido preenchido';
$ts = (int)($_POST['ts'] ?? 0);
if ($ts > 0 && (time() * 1000 - $ts) < 3000) $why[] = 'enviado em menos de 3 segundos';
if (preg_match_all('~https?://~i', $message) > 2) $why[] = 'muitos links';

$services = ['vfx' => 'VFX / 3D', 'marketing' => 'Marketing Buddy', 'both' => 'Ambos'];
$notes = [];
if ($company !== '') $notes[] = 'Empresa: ' . $company;
if ($why) $notes[] = 'Quarentena: ' . implode(', ', $why) . '.';
$dups = find_duplicates($pdo, $email, $phone);
foreach ($dups['deals'] as $d) $notes[] = 'Possível duplicado: ' . $d['name'] . ($d['code'] ? ' (' . $d['code'] . ')' : '') . '.';
foreach ($dups['contacts'] as $c) $notes[] = 'Já existe o contacto «' . $c['name'] . '».';

$id = lead_create($pdo, [
    'name' => $company !== '' ? $company . ' · ' . $name : $name, 'types' => '', 'links' => '', 'phone' => $phone, 'email' => $email,
    'service' => $services[$service] ?? '', 'priority' => 'media', 'detail' => $message, 'why' => '',
    'notes' => implode("\n", $notes), 'origin' => 'site', 'campaign' => '',
], null, null, (bool)$why);

reply(true, 'Thank you — we will get back to you shortly.');
