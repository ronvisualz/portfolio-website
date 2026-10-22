<?php
/**
 * topper. — link com rastreio de uma publicação nas redes (Marketing, §16.8)
 *
 *   /go.php?c=<código>
 *
 * Conta o clique (sem guardar o IP: só um resumo, para não contar duas vezes
 * a mesma pessoa em 30 minutos), lembra o código durante 30 dias e leva para
 * a página do site escolhida na peça (por omissão, a página inicial). Uma
 * lead que escreva depois pelo formulário do site entra com a origem
 * «<rede> · <peça>» (contacto.php). Um código desconhecido vai para a página
 * inicial, sem erro.
 */

declare(strict_types=1);

define('TOPPER_ADMIN', true);
$__host = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
define('IS_LOCAL', in_array($__host, ['localhost', '127.0.0.1', '::1'], true));

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Robots-Tag: noindex');

$code = strtolower(trim((string)($_GET['c'] ?? '')));
$to = '/';
if (preg_match('/^[a-z0-9]{6,12}$/', $code)) {
    try {
        $CONFIG = require __DIR__ . '/admin/inc/config.php';
        $dsn = (IS_LOCAL && !empty($CONFIG['db_dsn']))
            ? $CONFIG['db_dsn'] : "mysql:host={$CONFIG['db_host']};dbname={$CONFIG['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $st = $pdo->prepare('SELECT mp.id, mp.network, mp.piece_id, p.link_target FROM mkt_posts mp JOIN mkt_pieces p ON p.id = mp.piece_id WHERE mp.code = ?');
        $st->execute([$code]);
        $post = $st->fetch();
        if ($post) {
            $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $bot = $ua === '' || preg_match('/bot|crawl|spider|preview|facebookexternalhit|slack|whatsapp|telegram|linkedinbot|twitterbot|embedly|curl|wget/', $ua);
            if (!$bot) {
                $hash = substr(hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $ua . '|' . gmdate('Y-m-d')), 0, 16);
                $seen = $pdo->prepare('SELECT COUNT(*) FROM mkt_clicks WHERE post_id = ? AND ip_hash = ? AND created_at > ?');
                $seen->execute([(int)$post['id'], $hash, gmdate('Y-m-d H:i:s', time() - 1800)]);
                if (!(int)$seen->fetchColumn()) {
                    $pdo->prepare('INSERT INTO mkt_clicks (post_id, ip_hash, created_at) VALUES (?,?,?)')->execute([(int)$post['id'], $hash, gmdate('Y-m-d H:i:s')]);
                }
            }
            $target = (string)($post['link_target'] ?? '');
            // Só páginas deste site (nunca outro domínio).
            if (!preg_match('~^/(?!/)[^\s\\\\]{0,250}$~', $target)) $target = '/';
            $hash = '';
            if (($p = strpos($target, '#')) !== false) { $hash = substr($target, $p); $target = substr($target, 0, $p); }
            $source = ['ig' => 'instagram', 'li' => 'linkedin', 'x' => 'x'][$post['network']] ?? 'social';
            $q = http_build_query(['src' => $code, 'utm_source' => $source, 'utm_medium' => 'social', 'utm_campaign' => 'peca-' . (int)$post['piece_id']]);
            $to = $target . (str_contains($target, '?') ? '&' : '?') . $q . $hash;
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie('tp_src', $code, ['expires' => time() + 30 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
        }
    } catch (Throwable $e) {
        error_log('go.php: ' . $e->getMessage());
    }
}
header('Location: ' . $to, true, 302);
