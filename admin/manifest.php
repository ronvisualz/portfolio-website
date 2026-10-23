<?php
/**
 * topper. admin — a aplicação instalável (PWA, §26.3): ícone no telemóvel/tablet e, no Android,
 * «topper.» no menu Partilhar (Web Share Target → partilhar.php → Caixa de entrada do Centro Criativo).
 */
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'name' => 'topper. admin',
    'short_name' => 'topper.',
    'description' => 'A plataforma da topper.: leads, negócios, Centro Criativo e equipa.',
    'id' => '/admin/',
    'start_url' => '/admin/',
    'scope' => '/admin/',
    'display' => 'standalone',
    'background_color' => '#111111',
    'theme_color' => '#111111',
    'lang' => 'pt-PT',
    'icons' => [
        ['src' => '/admin/assets/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/admin/assets/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => '/admin/assets/pwa/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'share_target' => [
        'action' => '/admin/partilhar.php',
        'method' => 'POST',
        'enctype' => 'multipart/form-data',
        'params' => ['title' => 'title', 'text' => 'text', 'url' => 'url',
                     'files' => [['name' => 'files', 'accept' => ['image/*', 'video/*', 'application/pdf']]]],
    ],
    'shortcuts' => [
        ['name' => 'Caixa de entrada', 'url' => '/admin/criativo.php?t=entrada', 'icons' => [['src' => '/admin/assets/pwa/icon-192.png', 'sizes' => '192x192']]],
        ['name' => 'Tarefas', 'url' => '/admin/calendar.php', 'icons' => [['src' => '/admin/assets/pwa/icon-192.png', 'sizes' => '192x192']]],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
