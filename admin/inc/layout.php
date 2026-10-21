<?php
if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/ui.php';

/**
 * Endereço de um ficheiro de assets com a data do ficheiro colada ao fim.
 *
 * Sem isto, o browser guarda o CSS antigo e continua a usá-lo depois
 * de publicar uma versão nova — a página fica com o desenho velho e
 * só um Ctrl+F5 a corrige. Assim que o ficheiro muda, o endereço
 * muda com ele e o browser é obrigado a ir buscar o novo.
 */
function asset_url(string $file): string {
    $path = __DIR__ . '/../assets/' . $file;
    $v    = is_file($path) ? filemtime($path) : time();
    return '/admin/assets/' . $file . '?v=' . $v;
}

function css_url(): string {
    return asset_url('app.css');
}

/** O <head> comum (também usado pela página de entrada). */
function render_head(string $title): void {
    ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="color-scheme" content="light dark">
<title><?= e($title) ?> · topper.</title>
<script>document.documentElement.classList.add('js');</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Jost:wght@600;700&family=JetBrains+Mono:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= css_url() ?>">
<?php
}

/**
 * $bodyClass: classe opcional do <body> (ex.: «td-full» nas páginas de ecrã
 * inteiro, como o Drive, em que a página não rola e o conteúdo rola por dentro).
 */
function render_header(string $title, string $active = '', string $bodyClass = '', array $crumbs = []): void {
    global $pdo;
    require_once __DIR__ . '/alerts.php';
    $u      = current_user();
    $prefs  = ui_prefs();
    $badges = nav_badges($pdo);
    $unread = notifications_unread($pdo);
    $html   = ($prefs['pref_theme'] !== '' ? ' data-theme="' . e($prefs['pref_theme']) . '"' : '')
            . ($prefs['pref_nav'] === 'closed' ? ' data-nav="closed"' : '');
    $pageTitle = NAV_ITEMS[$active][0] ?? ($active === 'conta' ? 'A minha conta' : ($active === 'migracao' ? 'Migração' : $title));
    $crumbGroup = 'Conta';
    foreach (NAV_GROUPS as $g => $keys) if (in_array($active, $keys, true)) $crumbGroup = $g;
    if ($active === 'migracao') $crumbGroup = 'Gestão';
    ?><!DOCTYPE html>
<html lang="pt"<?= $html ?>>
<head>
<?php render_head($title); ?>
<meta name="csrf" content="<?= e(csrf_token()) ?>">
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
<a class="skip" href="#conteudo">Saltar para o conteúdo</a>
<div class="app">
<aside class="rail" id="rail" aria-label="Menu">
  <div class="brand">
    <a href="<?= e(home_url()) ?>" aria-label="topper. admin — início">
      <?= wordmark_svg() ?><span class="mk" aria-hidden="true"></span>
    </a>
    <div class="brand-sub">admin</div>
  </div>
  <nav aria-label="Principal">
    <?php $first = true; foreach (NAV_GROUPS as $group => $keys):
        $items = array_values(array_filter($keys, fn($k) => can(NAV_TABS[$k] ?? $k)));
        if ($group === 'Gestão' && is_admin()) $items[] = 'migracao';
        if (!$items) continue; ?>
      <?= $first ? '' : '<div class="grp-sep" aria-hidden="true"></div>' ?>
      <div class="grp"><?= e($group) ?></div>
      <?php foreach ($items as $key):
          [$label, $href, $ico] = $key === 'migracao' ? ['Migração', '/admin/migracao.php', 'migracao'] : NAV_ITEMS[$key];
          $b = $badges[$key] ?? null;
          $tip = $label . ($b ? ' · ' . $b['tip'] : '');
          $on = $active === $key; ?>
        <a class="ni<?= $on ? ' on' : '' ?>" href="<?= e($href) ?>" aria-label="<?= e($tip) ?>" data-tip="<?= e($tip) ?>" data-tip-nav<?= $on ? ' aria-current="page"' : '' ?>>
          <?= icon($ico) ?><span class="lbl"><?= e($label) ?></span>
          <?php if ($b): ?><em class="<?= $b['al'] ? 'al' : '' ?>" aria-hidden="true"><?= (int)$b['n'] ?></em><?php endif; ?>
        </a>
      <?php endforeach; $first = false; ?>
    <?php endforeach; ?>
  </nav>
  <div class="rail-foot">
    <a class="who<?= $active === 'conta' ? ' on' : '' ?>" href="/admin/conta.php" aria-label="A minha conta · <?= e($u['name'] ?? '') ?>" data-tip="A minha conta" data-tip-nav>
      <span class="av" style="--who:<?= e(preg_match('/^#[0-9a-fA-F]{6}$/', $prefs['color']) ? $prefs['color'] : '#81CFE3') ?>" aria-hidden="true"><?= e(initials((string)($u['name'] ?? ''))) ?></span>
      <span class="nm"><?= e($u['name'] ?? '') ?><small><?= e(ROLES[$u['role'] ?? ''] ?? '') ?></small></span>
    </a>
    <a class="ni" href="/admin/logout.php" aria-label="Sair" data-tip="Sair" data-tip-nav><?= icon('logout') ?><span class="lbl">Sair</span></a>
  </div>
</aside>
<?php $navLabel = $prefs['pref_nav'] === 'closed' ? 'Expandir menu' : 'Recolher menu'; ?>
<button type="button" class="rail-edge rail-toggle" aria-controls="rail" aria-expanded="<?= $prefs['pref_nav'] === 'closed' ? 'false' : 'true' ?>"
        aria-keyshortcuts="[" aria-label="<?= $navLabel ?> ( [ )" data-tip="<?= $navLabel ?>  ·  [ "><?= icon('chev-left') ?></button>
<div class="nav-scrim" data-nav-close></div>
<div class="shell">
<header class="topbar">
  <?= icon_button('menu', 'Abrir o menu', ['class' => 'ibtn menu-btn', 'aria-controls' => 'rail', 'aria-expanded' => 'false']) ?>
  <nav class="crumb" aria-label="Caminho"><?= e($crumbGroup) ?><i aria-hidden="true">/</i><?php if ($crumbs): ?><a href="<?= e(NAV_ITEMS[$active][1] ?? '#') ?>"><?= e($pageTitle) ?></a><?php foreach ($crumbs as $ci => $c): ?><i aria-hidden="true">/</i><?= $ci === count($crumbs) - 1 ? '<b>' . e($c) . '</b>' : e($c) ?><?php endforeach; else: ?><b><?= e($pageTitle) ?></b><?php endif; ?></nav>
  <div class="top-actions">
    <button type="button" class="cmd-btn" data-cmdk aria-label="Pesquisar (Ctrl+K)" aria-keyshortcuts="Control+K Meta+K">
      <?= icon('search') ?><span class="lbl">Procurar ID, negócio, lead…</span><span class="kbd" data-kbd>Ctrl K</span>
    </button>
    <button type="button" class="ibtn theme-btn" aria-label="Mudar de modo (claro / escuro)" data-tip><?= icon('moon', 'i-moon') . icon('sun', 'i-sun') ?></button>
    <button type="button" class="ibtn bell" aria-label="Notificações<?= $unread ? ' · ' . $unread . ' por ler' : '' ?>" data-tip aria-haspopup="dialog" aria-expanded="false" aria-controls="notif">
      <?= icon('bell') ?><span class="badge"<?= $unread ? '' : ' hidden' ?>><?= $unread ?></span>
    </button>
    <div class="pop" id="notif" role="dialog" aria-label="Notificações">
      <div class="pop-head"><b>Notificações</b><button type="button" class="lnk" data-read-all>Marcar tudo como lido</button></div>
      <div class="pop-list"><div class="sp-loading">A carregar…</div></div>
    </div>
  </div>
</header>
<main id="conteudo">
<?php
    // Até a migração para a plataforma estar feita, os admins são avisados.
    if (is_admin() && $active !== 'migracao') {
        try {
            $done = $pdo->query("SELECT svalue FROM settings WHERE skey = 'platform_migrated_at'")->fetchColumn();
            $old  = !$done && db_has_table($pdo, 'jobs')
                 && (int)$pdo->query('SELECT (SELECT COUNT(*) FROM jobs) + (SELECT COUNT(*) FROM leads)')->fetchColumn() > 0;
        } catch (Throwable $e) {
            $old = false;
        }
        if ($old) {
            echo '<div class="flash err">Os dados da versão anterior ainda não passaram para a plataforma. '
               . '<a href="/admin/migracao.php">Abrir a Migração</a></div>';
        }
    }
}

function render_footer(): void {
    ?>
</main>
</div>
</div>
<div class="toasts" role="status" aria-live="polite"></div>
<div class="sp-back" data-sp-close></div>
<aside class="sp" id="sp" aria-labelledby="sp-title" tabindex="-1">
  <div class="sp-nav" hidden>
    <?= icon_button('chev-up', 'Anterior ( ↑ ou K )', ['data-step' => '-1']) ?>
    <?= icon_button('chev-down', 'Seguinte ( ↓ ou J )', ['data-step' => '1']) ?>
  </div>
  <div class="sp-head">
    <span class="eyebrow" id="sp-title">Detalhe</span>
    <?= icon_button('external', 'Abrir a página completa', ['href' => '#', 'class' => 'ibtn sp-full']) ?>
    <?= icon_button('x', 'Fechar (Esc)', ['class' => 'ibtn sp-x', 'data-sp-close' => true]) ?>
  </div>
  <div class="sp-body"></div>
</aside>
<div class="cmdk-back" role="dialog" aria-modal="true" aria-label="Pesquisa global">
  <div class="cmdk">
    <div class="cmdk-in"><?= icon('search') ?>
      <input type="text" placeholder="Procurar IDs, negócios, leads, contactos, ficheiros, tarefas… ou «ir para»" aria-label="Pesquisar"
             autocomplete="off" spellcheck="false" role="combobox" aria-controls="cmdk-res" aria-expanded="true" aria-autocomplete="list">
      <span class="kbd">Esc</span></div>
    <div class="cmdk-res" id="cmdk-res" role="listbox" aria-label="Resultados"></div>
    <div class="cmdk-foot"><span><span class="kbd">↑ ↓</span> escolher</span><span><span class="kbd">Enter</span> abrir</span><span><span class="kbd" data-kbd-mod>Ctrl</span> <span class="kbd">Enter</span> no painel lateral</span></div>
  </div>
</div>
<div class="tip" id="tip" role="tooltip"></div>
<script src="<?= asset_url('app.js') ?>" defer></script>
</body>
</html>
<?php
}

/**
 * Mensagens que sobrevivem a um redirecionamento. Com $undo (código de
 * undo_push), o aviso aparece em baixo com «Anular» durante 8 segundos.
 */
function flash(string $msg, string $kind = 'ok', string $undo = ''): void {
    $_SESSION['flash'][] = ['m' => $msg, 'k' => $kind, 'u' => $undo];
}

function render_flashes(): void {
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $u = (string)($f['u'] ?? '');
        echo '<div class="flash ' . e($f['k']) . '"' . ($u !== '' ? ' data-undo="' . e($u) . '"' : '')
           . ($f['k'] === 'err' ? ' role="alert"' : ' role="status"') . '>' . e($f['m']) . '</div>';
    }
    unset($_SESSION['flash']);
}

function redirect(string $to): void {
    header('Location: ' . $to);
    exit;
}
