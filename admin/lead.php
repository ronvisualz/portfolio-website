<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/leads.php';
require_once __DIR__ . '/inc/panel.php';
require_once __DIR__ . '/inc/lead_view.php';
$user = require_login();
require_page('leads');

// Ficha completa da lead (§12.6): a mesma vista do painel, a toda a largura,
// com o caminho Comercial / Leads / nome. «Voltar» regressa à lista com os
// filtros e a posição de onde se veio.

$l = lead_get($pdo, (int)($_GET['id'] ?? 0));
if (!$l || !in_array($l['phase'], ['lead', 'archived'], true) || !lead_in_reach($pdo, $l, me_row($pdo))) {
    flash('Lead não encontrada.', 'err');
    redirect('/admin/leads.php');
}
$id = (int)$l['id'];

// Só se volta para a própria lista de leads (nunca para fora do site).
$back = (string)($_GET['back'] ?? '');
if (!preg_match('~^/admin/leads\.php(\?[^#\s]*)?$~', $back)) $back = '/admin/leads.php' . ($l['phase'] === 'archived' ? '?v=arquivadas' : '');
$self = '/admin/lead.php?id=' . $id . '&back=' . rawurlencode($back);

[$name] = lead_display($l);
render_header($name, 'leads', '', [$name]);
render_flashes();
?>
<div class="lead-page-top">
  <a class="btn ghost sm" href="<?= e($back) ?>#l-<?= $id ?>" data-back><?= icon('chev-left') ?> Voltar às leads</a>
  <?php if (can('leads', PERM_EDIT)): ?><a data-new href="/admin/leads.php?nova=1" hidden></a><?php endif; ?>
</div>
<?= lead_view($pdo, $l, 'page', $self) ?>
<?= can('leads', PERM_EDIT) ? lead_modals_html(null, $self) : '' ?>
<script src="<?= asset_url('leads.js') ?>" defer></script>
<?php render_footer();
