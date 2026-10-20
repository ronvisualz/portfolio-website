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

/** Separador do menu → aba de permissões. */
const NAV_TABS = ['calendar' => 'tarefas', 'fornecedores' => 'contactos'];

function render_header(string $title, string $active = ''): void {
    $u = current_user();
    $nav = [
        'dashboard' => ['Painel',    '/admin/dashboard.php'],
        'calendar'  => ['Calendário','/admin/calendar.php'],
        'leads'     => ['Leads',     '/admin/leads.php'],
        'fornecedores' => ['Fornecedores', '/admin/fornecedores.php'],
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
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Jost:wght@600;700&display=swap">
<link rel="stylesheet" href="<?= css_url() ?>">
</head>
<body>
<a class="skip" href="#conteudo">Saltar para o conteúdo</a>
<div class="app">
<aside class="rail">
  <div class="brand">
    <a href="<?= e(home_url()) ?>" aria-label="topper. admin — início">
      <svg class="wm" viewBox="0 0 432.74 121.88" aria-hidden="true" focusable="false">
<path fill="currentColor" fill-rule="nonzero" d="M 64.7 55.0 C 64.7 60.6 66.2 65.1 69.2 68.3 C 72.3 71.6 76.4 73.3 81.5 73.3 C 86.7 73.3 90.8 71.6 93.8 68.3 C 96.9 65.1 98.4 60.7 98.4 55.2 C 98.4 49.8 96.9 45.4 93.8 42.0 C 90.8 38.7 86.7 37.0 81.5 37.0 C 76.4 37.0 72.3 38.7 69.2 42.0 C 66.2 45.4 64.7 49.7 64.7 55.0 M 45.1 54.8 C 45.1 49.8 46.0 45.2 47.8 41.0 C 49.6 36.8 52.1 33.2 55.3 30.1 C 58.6 27.1 62.4 24.7 66.9 22.9 C 71.3 21.2 76.2 20.3 81.5 20.3 C 86.8 20.3 91.7 21.1 96.2 22.8 C 100.6 24.6 104.5 26.9 107.7 30.0 C 111.0 33.1 113.5 36.8 115.3 41.0 C 117.1 45.3 118.0 50.0 118.0 55.2 C 118.0 60.3 117.1 65.0 115.3 69.3 C 113.5 73.6 111.0 77.3 107.7 80.3 C 104.5 83.4 100.6 85.8 96.1 87.5 C 91.6 89.2 86.7 90.1 81.3 90.1 C 76.0 90.1 71.2 89.2 66.7 87.5 C 62.3 85.8 58.5 83.4 55.3 80.3 C 52.1 77.3 49.6 73.5 47.8 69.2 C 46.0 64.8 45.1 60.0 45.1 54.8 M 146.6 55.0 C 146.6 57.6 147.0 59.9 147.8 62.1 C 148.7 64.3 149.8 66.3 151.4 67.9 C 152.9 69.5 154.7 70.8 156.8 71.7 C 158.9 72.7 161.3 73.2 163.8 73.2 C 166.4 73.2 168.7 72.7 170.8 71.8 C 172.9 70.9 174.6 69.6 176.1 68.0 C 177.5 66.4 178.6 64.5 179.5 62.3 C 180.3 60.1 180.7 57.7 180.7 55.2 C 180.7 49.8 179.2 45.3 176.3 41.9 C 173.4 38.5 169.2 36.8 163.9 36.8 C 158.7 36.8 154.5 38.5 151.4 41.9 C 148.2 45.3 146.6 49.7 146.6 55.0 M 147.6 22.0 L 147.4 29.7 L 147.6 29.7 C 150.6 26.6 153.9 24.2 157.3 22.6 C 160.7 21.0 164.5 20.1 168.7 20.1 C 173.4 20.1 177.8 21.1 181.7 22.9 C 185.6 24.8 189.0 27.3 191.7 30.4 C 194.5 33.6 196.7 37.3 198.2 41.5 C 199.6 45.7 200.4 50.3 200.4 55.2 C 200.4 59.9 199.6 64.4 198.1 68.6 C 196.6 72.8 194.4 76.5 191.6 79.6 C 188.8 82.8 185.5 85.3 181.6 87.1 C 177.7 88.9 173.5 89.8 168.8 89.8 C 164.5 89.8 160.7 89.0 157.2 87.5 C 153.8 86.0 150.5 83.6 147.6 80.3 L 147.4 80.3 C 147.6 82.4 147.7 84.3 147.7 86.0 L 147.7 121.8 L 128.1 121.8 L 128.1 22.0 Z M 147.6 22.0 M 228.9 55.0 C 228.9 57.6 229.3 59.9 230.1 62.1 C 230.9 64.3 232.1 66.3 233.6 67.9 C 235.1 69.5 237.0 70.8 239.1 71.7 C 241.2 72.7 243.5 73.2 246.0 73.2 C 248.7 73.2 251.0 72.7 253.1 71.8 C 255.1 70.9 256.9 69.6 258.3 68.0 C 259.8 66.4 260.9 64.5 261.7 62.3 C 262.5 60.1 262.9 57.7 262.9 55.2 C 262.9 49.8 261.5 45.3 258.5 41.9 C 255.6 38.5 251.5 36.8 246.2 36.8 C 241.0 36.8 236.8 38.5 233.6 41.9 C 230.5 45.3 228.9 49.7 228.9 55.0 M 229.8 22.0 L 229.7 29.7 L 229.8 29.7 C 232.9 26.6 236.1 24.2 239.6 22.6 C 243.0 21.0 246.8 20.1 250.9 20.1 C 255.7 20.1 260.0 21.1 263.9 22.9 C 267.9 24.8 271.2 27.3 274.0 30.4 C 276.8 33.6 278.9 37.3 280.4 41.5 C 281.9 45.7 282.6 50.3 282.6 55.2 C 282.6 59.9 281.9 64.4 280.3 68.6 C 278.8 72.8 276.7 76.5 273.9 79.6 C 271.1 82.8 267.7 85.3 263.9 87.1 C 260.0 88.9 255.7 89.8 251.0 89.8 C 246.8 89.8 243.0 89.0 239.5 87.5 C 236.0 86.0 232.8 83.6 229.8 80.3 L 229.7 80.3 C 229.9 82.4 230.0 84.3 230.0 86.0 L 230.0 121.8 L 210.4 121.8 L 210.4 22.0 Z M 229.8 22.0 M 309.2 46.4 L 335.9 46.4 C 335.4 42.8 334.0 40.0 331.7 38.0 C 329.3 36.0 326.3 35.0 322.6 35.0 C 319.0 35.0 316.1 36.1 313.8 38.1 C 311.5 40.2 310.0 43.0 309.2 46.4 M 322.3 20.3 C 327.5 20.3 332.1 21.1 336.2 22.7 C 340.3 24.3 343.8 26.7 346.7 29.7 C 349.5 32.8 351.8 36.5 353.3 40.9 C 354.9 45.3 355.7 50.2 355.7 55.7 L 355.7 59.2 L 308.4 59.2 C 308.8 64.1 310.2 68.0 312.7 70.9 C 315.2 73.9 318.7 75.3 323.3 75.3 C 326.4 75.3 329.2 74.6 331.5 73.0 C 333.8 71.5 335.8 69.1 337.6 65.8 L 354.8 71.5 C 351.8 77.7 347.5 82.4 341.9 85.5 C 336.3 88.5 330.0 90.1 323.0 90.1 C 317.9 90.1 313.2 89.2 309.0 87.6 C 304.9 86.0 301.2 83.7 298.2 80.7 C 295.1 77.6 292.7 74.0 291.1 69.7 C 289.4 65.4 288.6 60.6 288.6 55.3 C 288.6 50.0 289.4 45.2 291.1 40.8 C 292.7 36.5 295.1 32.8 298.0 29.8 C 301.0 26.8 304.6 24.4 308.7 22.8 C 312.8 21.1 317.4 20.3 322.3 20.3 M 365.8 22.0 L 385.4 22.0 L 385.2 28.8 L 385.6 28.8 C 388.1 25.7 390.5 23.5 392.9 22.2 C 395.3 20.9 398.3 20.3 402.0 20.3 C 404.9 20.3 408.2 21.3 411.9 23.3 L 406.6 42.0 C 405.2 41.1 403.7 40.3 402.3 39.7 C 400.9 39.1 399.3 38.8 397.6 38.8 C 389.5 38.8 385.4 44.6 385.4 56.1 L 385.4 88.2 L 365.8 88.2 Z M 365.8 22.0 M 409.2 79.0 C 409.2 75.7 410.4 73.1 412.7 71.0 C 415.0 68.9 417.8 67.9 420.9 67.9 C 424.1 67.9 426.8 68.9 429.2 71.0 C 431.5 73.1 432.7 75.7 432.7 79.0 C 432.7 82.2 431.5 84.9 429.2 86.9 C 426.8 89.0 424.1 90.1 420.9 90.1 C 417.8 90.1 415.0 89.0 412.7 86.9 C 410.4 84.9 409.2 82.2 409.2 79.0 M 10.5 88.2 L 30.0 88.2 L 30.0 48.7 L 10.5 48.7 Z M 10.5 88.2 M 31.3 23.8 L 31.3 0.0 L 10.5 0.0 L 10.5 23.8 C 10.5 24.6 9.8 25.2 9.1 25.0 L 1.4 23.6 C 0.6 23.5 0.0 24.0 0.0 24.8 L 0.0 40.7 L 41.8 40.7 L 41.8 24.8 C 41.8 24.0 41.1 23.5 40.4 23.6 L 32.7 25.0 C 32.0 25.2 31.3 24.6 31.3 23.8"/></svg>
    </a>
    <div class="brand-sub">admin</div>
  </div>
  <nav aria-label="Principal">
    <?php foreach ($nav as $key => [$label, $href]): if (!can(NAV_TABS[$key] ?? $key)) continue; ?>
      <a href="<?= $href ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="rail-foot hide-sm">Sessão iniciada como<br><b><?= e($u['name'] ?? '') ?></b></div>
</aside>
<div class="shell">
<div class="topbar">
  <div class="mark"></div>
  <div class="page-title" aria-hidden="true"><?= e($nav[$active][0] ?? ($active === 'projects' ? 'Carteira' : $title)) ?>.</div>
  <div class="session">
    <a class="lnk" href="/admin/logout.php">Sair</a>
  </div>
</div>
<main id="conteudo">
<?php
}

function render_footer(): void {
    echo "</main>\n</div>\n</div>\n</body>\n</html>";
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
