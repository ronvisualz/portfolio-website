<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
$user = require_login();
require_page('conhecimento');

// Centro de Conhecimento (§12.11). Por agora só o separador «Navegação»:
// os atalhos de teclado e gestos da plataforma, a partir de SHORTCUTS
// (inc/ui.php) — atualizar lá sempre que surgir um atalho novo.

const KNOW_TABS = ['navegacao' => 'Navegação'];
$tab = isset(KNOW_TABS[$_GET['s'] ?? '']) ? (string)$_GET['s'] : 'navegacao';

render_header('Conhecimento', 'conhecimento', '', [KNOW_TABS[$tab]]);
?>
<h1>Centro de Conhecimento</h1>
<p class="sub">Processos, templates e guias da equipa.</p>

<div class="tabs" role="tablist" aria-label="Separadores">
  <?php foreach (KNOW_TABS as $k => $label): ?>
    <a href="/admin/conhecimento.php?s=<?= e($k) ?>" class="<?= $tab === $k ? 'on' : '' ?>" role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'navegacao'): ?>
<p class="muted" style="max-width:760px">Atalhos de teclado e gestos que funcionam na plataforma. Os atalhos de uma só tecla
  não atuam enquanto se escreve num campo.</p>
<div class="tablewrap keys">
<table>
  <thead><tr><th>Tecla ou gesto</th><th>O que faz</th><th class="hide-sm">Onde</th></tr></thead>
  <tbody>
  <?php foreach (SHORTCUTS as [$keys, $what, $where]): ?>
    <tr>
      <td><?php foreach (preg_split('/\s+(ou|\/)\s+/u', $keys, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part): ?><?= $i % 2 ? ' <span class="muted">' . e($part) . '</span> ' : '<span class="kbd">' . e($part) . '</span>' ?><?php endforeach; ?></td>
      <td><?= e($what) ?></td>
      <td class="hide-sm muted"><?= e($where) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php render_footer();
