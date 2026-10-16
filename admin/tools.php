<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();

/**
 * Ferramentas do estúdio. Para acrescentar uma, junte uma entrada a esta lista.
 *
 * O render farm corre no computador de quem o usa (abre o Blender local e
 * guarda as chaves da Vast.ai e da Cloudflare só nessa máquina), por isso o
 * botão abre o endereço local 127.0.0.1 — funciona em qualquer computador
 * onde o "Blender Farm.bat" esteja a correr. O servidor do site não o executa.
 */
$tools = [
    [
        'name'  => 'Render farm',
        'what'  => 'Renderiza cenas do Blender em GPUs alugadas à Vast.ai e traz os frames de volta. Prepara e verifica a cena antes de alugar seja o que for.',
        'where' => 'Corre neste computador. Abra primeiro o "Blender Farm.bat" e depois carregue em Abrir.',
        'open'  => 'http://127.0.0.1:8765/',
        'repo'  => 'https://github.com/ronvisualz/render-farm-topper',
    ],
];

render_header('Tools', 'tools');
render_flashes();
?>

<h1 style="margin-bottom:1.8rem">Tools</h1>

<div class="tablewrap">
<table>
  <thead>
    <tr><th>Ferramenta</th><th>O que faz</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($tools as $t): ?>
    <tr>
      <td class="b nowrap"><?= e($t['name']) ?></td>
      <td>
        <?= e($t['what']) ?>
        <div class="muted" style="font-size:0.75rem;margin-top:0.35rem"><?= e($t['where']) ?></div>
      </td>
      <td class="nowrap" style="text-align:right">
        <a class="btn sm" href="<?= e($t['open']) ?>" target="_blank" rel="noopener">Abrir</a>
        <?php if (!empty($t['repo'])): ?>
          <a class="btn ghost sm" href="<?= e($t['repo']) ?>" target="_blank" rel="noopener">Código</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php render_footer();
