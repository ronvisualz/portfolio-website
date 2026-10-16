<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
$user = require_login();

/**
 * Ferramentas do estúdio. Para acrescentar uma, junte uma entrada a esta lista.
 *
 * O render farm corre no computador de quem o usa (abre o Blender local e
 * guarda as chaves da Vast.ai e da Cloudflare só nessa máquina); o servidor
 * do site não o executa. "Instalar" descarrega o programa com um instalador
 * que regista o endereço topperfarm:// no Windows, e "Abrir" usa esse
 * endereço para o arrancar (ou só mostrá-lo, se já estiver aberto).
 */
$tools = [
    [
        'name'    => 'Render farm',
        'what'    => 'Renderiza cenas do Blender em GPUs alugadas à Vast.ai e traz os frames de volta. Prepara e verifica a cena antes de alugar seja o que for.',
        'install' => '/admin/download.php?f=renderfarm',
        'open'    => 'topperfarm://open',
        'local'   => 'http://localhost:8765/',
        'steps'   => [
            'Carregue em <b>Instalar</b> e abra o ZIP descarregado: botão direito → <b>Extrair tudo</b>.',
            'Na pasta extraída, faça duplo clique em <b>Instalar.bat</b>. Só é preciso uma vez por computador (e outra vez para atualizar).',
            'A partir daí, <b>Abrir</b> arranca o programa. O browser pergunta se pode abrir o “topper. Render Farm”: aceite e marque “permitir sempre”.',
        ],
        'needs'   => 'Windows, Python 3.10–3.14 (python.org, com “Add python.exe to PATH”) e o Blender. A primeira vez demora um ou dois minutos a preparar.',
    ],
];

render_header('Tools', 'tools');
render_flashes();
?>

<h1 style="margin-bottom:1.8rem">Tools</h1>

<?php foreach ($tools as $t): ?>
<section class="stat" style="padding:1.4rem 1.5rem;margin-bottom:1.2rem">
  <div style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:1rem;justify-content:space-between">
    <div style="max-width:62ch">
      <h2 style="margin:0 0 0.5rem"><?= e($t['name']) ?></h2>
      <p style="margin:0;line-height:1.55"><?= e($t['what']) ?></p>
    </div>
    <div class="nowrap" style="display:flex;gap:0.5rem">
      <a class="btn ghost sm" href="<?= e($t['install']) ?>">Instalar</a>
      <a class="btn sm" href="<?= e($t['open']) ?>">Abrir</a>
    </div>
  </div>

  <ol style="margin:1.2rem 0 0;padding-left:1.2rem;line-height:1.7;font-size:0.85rem">
    <?php foreach ($t['steps'] as $s): ?><li><?= $s /* texto fixo desta página */ ?></li><?php endforeach; ?>
  </ol>
  <p class="muted" style="font-size:0.75rem;margin:0.9rem 0 0;line-height:1.5">
    Precisa de: <?= e($t['needs']) ?>
    Se “Abrir” não fizer nada, ainda não foi instalado neste computador.
    Já está aberto? <a href="<?= e($t['local']) ?>" target="_blank" rel="noopener">Ir para o programa</a>.
  </p>
</section>
<?php endforeach; ?>

<?php render_footer();
