<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();

/** Ferramentas do estúdio. Para acrescentar uma, junte uma entrada a esta lista. */
$tools = [
    [
        'name' => 'Render farm',
        'what' => 'Renderiza cenas do Blender em GPUs alugadas à Vast.ai e traz os frames de volta.',
        'url'  => 'https://github.com/ronvisualz/render-farm-topper',
    ],
];

render_header('Tools', 'tools');
render_flashes();
?>

<h1 style="margin-bottom:1.8rem">Tools</h1>

<div class="tablewrap">
<table>
  <tbody>
  <?php foreach ($tools as $t): ?>
    <tr>
      <td class="b nowrap"><?= e($t['name']) ?></td>
      <td><?= e($t['what']) ?></td>
      <td class="nowrap" style="text-align:right">
        <a class="btn sm" href="<?= e($t['url']) ?>" target="_blank" rel="noopener">GitHub</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php render_footer();
