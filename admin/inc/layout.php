<?php
if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/**
 * Endereço da folha de estilos com a data do ficheiro colada ao fim.
 *
 * Sem isto, o browser guarda o CSS antigo e continua a usá-lo depois
 * de publicar uma versão nova — a página fica com o desenho velho e
 * só um Ctrl+F5 a corrige. Assim que o ficheiro muda, o endereço
 * muda com ele e o browser é obrigado a ir buscar o novo.
 */
function css_url(): string {
    $path = __DIR__ . '/../assets/app.css';
    $v    = is_file($path) ? filemtime($path) : time();
    return '/admin/assets/app.css?v=' . $v;
}

function render_header(string $title, string $active = ''): void {
    $u = current_user();
    $nav = [
        'dashboard' => ['Painel',    '/admin/dashboard.php'],
        'calendar'  => ['Calendário','/admin/calendar.php'],
        'leads'     => ['Leads',     '/admin/leads.php'],
        'drive'     => ['Drive',     '/admin/drive.php'],
        'tools'     => ['Tools',     '/admin/tools.php'],
    ];
    ?><!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title><?= e($title) ?> · topper.</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&display=swap">
<link rel="stylesheet" href="<?= css_url() ?>">
</head>
<body>
<div class="topbar">
  <div class="mark"></div>
  <div class="brand"><a href="/admin/dashboard.php">topper. admin</a></div>
  <nav>
    <?php foreach ($nav as $key => [$label, $href]): ?>
      <a href="<?= $href ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="session">
    <span class="who hide-sm"><?= e($u['name'] ?? '') ?></span>
    <a class="lnk" href="/admin/logout.php">Sair</a>
  </div>
</div>
<main>
<?php
}

function render_footer(): void {
    echo "</main>\n</body>\n</html>";
}

/** One-shot messages that survive a redirect. */
function flash(string $msg, string $kind = 'ok'): void {
    $_SESSION['flash'][] = ['m' => $msg, 'k' => $kind];
}

function render_flashes(): void {
    foreach ($_SESSION['flash'] ?? [] as $f) {
        echo '<div class="flash ' . e($f['k']) . '">' . e($f['m']) . '</div>';
    }
    unset($_SESSION['flash']);
}

function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}
