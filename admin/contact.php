<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/panel.php';
require_once __DIR__ . '/inc/contact_view.php';
$user = require_login();
require_page('contactos');

// Ficha completa do contacto (§13.7): a mesma vista do painel, a toda a
// largura, com o caminho Comercial / Contactos / nome. «Voltar» regressa à
// lista com os filtros de onde se veio.

relation_tick($pdo);
$c = contact_get($pdo, (int)($_GET['id'] ?? 0));
if (!$c) {
    flash('Contacto não encontrado.', 'err');
    redirect('/admin/contactos.php');
}
$id = (int)$c['id'];

// Só se volta para a própria lista de contactos (nunca para fora do site).
$back = (string)($_GET['back'] ?? '');
if (!preg_match('~^/admin/contactos\.php(\?[^#\s]*)?$~', $back)) $back = '/admin/contactos.php';
$self = '/admin/contact.php?id=' . $id . ($back !== '/admin/contactos.php' ? '&back=' . rawurlencode($back) : '');

render_header($c['name'], 'contactos', '', [$c['name']]);
render_flashes();
?>
<div class="lead-page-top">
  <a class="btn ghost sm" href="<?= e($back) ?>#c-<?= $id ?>" data-back><?= icon('chev-left') ?> Voltar aos contactos</a>
  <?php if (can('contactos', PERM_EDIT)): ?><a data-new href="/admin/contactos.php?novo=1" hidden></a><?php endif; ?>
</div>
<?= contact_view($pdo, $c, 'page', $self) ?>
<?= can('contactos', PERM_EDIT) ? contact_modals_html($pdo) : '' ?>
<script src="<?= asset_url('contacts.js') ?>" defer></script>
<?php render_footer();
