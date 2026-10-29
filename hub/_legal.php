<?php
/**
 * topperhub.xyz — páginas públicas (sem login) da Política de Privacidade e dos Termos de Utilização, pedidas pela
 * Google para publicar a app OAuth («Entrar com Google», Google Calendar). Só desenho comum; o texto está em
 * privacidade.php e termos.php.
 */
const LEGAL_CONTACT = 'geraltopperdigital@gmail.com';
const LEGAL_UPDATED = '29 de outubro de 2026';

function legal_page(string $title, string $html): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    $other = $title === 'Política de Privacidade' ? ['/termos', 'Termos de Utilização'] : ['/privacidade', 'Política de Privacidade'];
    ?><!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> · topper.</title>
<style>
  :root { color-scheme: light dark; --bg: #F6F6F4; --card: #fff; --fg: #111; --mute: #5B5B5B; --line: #E3E3E3; --hi: #FFC718; }
  @media (prefers-color-scheme: dark) { :root { --bg: #0E0E0E; --card: #171717; --fg: #EDEDED; --mute: #A3A3A3; --line: #2A2A2A; } }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.6 system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
  header { background: #000; color: #fff; }
  .in { width: min(820px, 100%); margin: 0 auto; padding: 0 20px; }
  header .in { display: flex; justify-content: space-between; align-items: center; height: 60px; }
  header b { font-size: 22px; letter-spacing: -.02em; } header b i { color: var(--hi); font-style: normal; }
  header a { color: #fff; font-size: 14px; }
  .band { height: 5px; background: var(--hi); }
  main .in { padding-top: 32px; padding-bottom: 48px; }
  article { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 28px 28px 12px; }
  h1 { font-size: 28px; line-height: 1.2; margin: 0 0 4px; }
  .upd { color: var(--mute); font-size: 14px; margin: 0 0 20px; }
  h2 { font-size: 18px; margin: 26px 0 6px; }
  p, li { color: var(--fg); } ul { padding-left: 20px; }
  a { color: inherit; }
  footer { color: var(--mute); font-size: 14px; padding: 0 0 32px; }
  footer a { margin-right: 14px; }
  @media (max-width: 560px) { article { padding: 20px 16px 6px; } h1 { font-size: 23px; } }
</style>
</head>
<body>
<header><div class="in"><b>topper<i>.</i></b><a href="/admin/">Entrar na plataforma</a></div></header>
<div class="band" aria-hidden="true"></div>
<main><div class="in"><article>
<h1><?= htmlspecialchars($title) ?></h1>
<p class="upd">Última atualização: <?= LEGAL_UPDATED ?></p>
<?= $html ?>
</article></div></main>
<footer><div class="in"><a href="<?= $other[0] ?>"><?= $other[1] ?></a><a href="/">topperhub.xyz</a> Topper Digital · <a href="mailto:<?= LEGAL_CONTACT ?>"><?= LEGAL_CONTACT ?></a></div></footer>
</body>
</html>
<?php
}
