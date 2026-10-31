<?php
// Cartões de link (§33.8), sem rede: reconhecer os serviços, ler o oEmbed, cair para Open Graph quando o oEmbed falha.
// php tests/cc_link_meta_test.php
require __DIR__ . '/boot.php';
require_once __DIR__ . '/../admin/inc/cc.php';
$P = fn(string $u) => (cc_link_provider($u) ?? ['name' => null])['name'];
t_eq($P('https://www.youtube.com/watch?v=dQw4w9WgXcQ'), 'YouTube', 'YouTube (watch)');
t_eq($P('https://youtu.be/dQw4w9WgXcQ'), 'YouTube', 'YouTube (youtu.be)');
t_eq($P('https://youtube.com/shorts/abcdefghijk'), 'YouTube', 'YouTube (shorts)');
t_eq($P('https://vimeo.com/76979871'), 'Vimeo', 'Vimeo');
t_eq($P('https://www.instagram.com/p/C1abcDEF/'), 'Instagram', 'Instagram (post)');
t_eq($P('https://www.tiktok.com/@ze/video/7234567890123456789'), 'TikTok', 'TikTok');
t_eq($P('https://www.behance.net/gallery/123456/Projeto'), 'Behance', 'Behance');
t_eq($P('https://dribbble.com/shots/123-Logo'), 'Dribbble', 'Dribbble');
t_eq($P('https://www.artstation.com/artwork/AbC123'), 'ArtStation', 'ArtStation');
t_eq($P('https://x.com/nasa/status/1234567890'), 'X', 'X (x.com)');
t_eq($P('https://twitter.com/nasa/status/1234567890'), 'X', 'X (twitter.com)');
t_eq($P('https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC'), 'Spotify', 'Spotify');
t_eq($P('https://soundcloud.com/artista/faixa'), 'SoundCloud', 'SoundCloud');
t_eq($P('https://exemplo.pt/artigo'), null, 'site genérico → sem fornecedor (Open Graph)');
t_eq($P('https://youtube.com.evil.example/watch?v=x'), null, 'um domínio que só se parece não conta');
$yt = cc_link_provider('https://youtu.be/dQw4w9WgXcQ');
t_true(str_starts_with((string)$yt['oembed'], 'https://www.youtube.com/oembed?') && $yt['video'] === true, 'o endpoint oEmbed vem da lista fixa');
t_eq(cc_link_provider('https://www.instagram.com/p/C1abcDEF/')['oembed'], null, 'Instagram sem oEmbed público → Open Graph');

$j = cc_oembed_parse('{"title":"Never Gonna","author_name":"Rick","thumbnail_url":"https://i.ytimg.com/vi/x/hqdefault.jpg","thumbnail_width":480,"thumbnail_height":360,"html":"<iframe></iframe>","provider_name":"YouTube"}');
t_eq([$j['title'], $j['author'], $j['thumb'], $j['w'], $j['h']], ['Never Gonna', 'Rick', 'https://i.ytimg.com/vi/x/hqdefault.jpg', 480, 360], 'oEmbed do YouTube');
$j = cc_oembed_parse('{"title":"Curta","author_name":"Estúdio","thumbnail_url":"https://i.vimeocdn.com/video/1.jpg","duration":95}');
t_eq([$j['title'], $j['author'], $j['duration']], ['Curta', 'Estúdio', 95], 'oEmbed do Vimeo (com duração)');
t_eq(cc_oembed_parse('<html>não é json</html>'), [], 'JSON inválido → []');
t_eq(cc_oembed_parse('{"title":"x","thumbnail_url":"javascript:alert(1)"}')['thumb'] ?? '', '', 'miniatura que não é https é ignorada');

// cc_link_meta com um «fetch» falso: oEmbed primeiro; se falhar (HTML, JSON inválido), Open Graph sem erro
$og = '<html><head><meta property="og:title" content="Título OG"><meta property="og:image" content="https://cdn.exemplo/og.jpg"><meta property="og:site_name" content="YouTube"></head></html>';
$calls = [];
$fake = function (string $u, int $max = 0) use (&$calls, $og) {
    $calls[] = $u;
    if (str_contains($u, '/oembed')) return ['{"title":"Do oEmbed","author_name":"Canal","thumbnail_url":"https://i.ytimg.com/vi/z/hq.jpg"}', 'application/json', $u];
    return [$og, 'text/html', $u];
};
$m = cc_link_meta('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $fake);
t_eq([$m['title'], $m['author'], $m['image'], $m['provider']], ['Do oEmbed', 'Canal', 'https://i.ytimg.com/vi/z/hq.jpg', 'YouTube'], 'com oEmbed: título, autor e miniatura do oEmbed');
t_true(str_contains((string)$m['embed'], 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'e o leitor embutido (sem cookies)');
t_true(str_starts_with($calls[0], 'https://www.youtube.com/oembed?'), 'o pedido de oEmbed vai para o endpoint da lista');
$bad = fn(string $u, int $max = 0) => str_contains($u, '/oembed') ? ['<html>erro</html>', 'text/html', $u] : [$og, 'text/html', $u];
$m = cc_link_meta('https://vimeo.com/76979871', $bad);
t_eq([$m['title'], $m['image']], ['Título OG', 'https://cdn.exemplo/og.jpg'], 'oEmbed com HTML → cai para Open Graph');
$none = fn(string $u, int $max = 0) => str_contains($u, '/oembed') ? null : [$og, 'text/html', $u];
$m = cc_link_meta('https://www.tiktok.com/@ze/video/7234567890123456789', $none);
t_eq($m['title'], 'Título OG', 'oEmbed que não responde (ou demasiado grande) → Open Graph');
t_done();
