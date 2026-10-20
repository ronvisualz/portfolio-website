<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/drive.php';
$user = require_login();

// drive.php?p=<prefixo da pasta>/ abre essa pasta; ?v=recent|fav|shared|trash e ?q= são lidos pelo app.js.
// A página é só a moldura: a lista vem do catálogo (drive_api.php › catalog) e é desenhada no browser.
$p = (string)($_GET['p'] ?? '');
if ($p !== '' && substr($p, -1) !== '/') $p .= '/';
if (!r2_prefix_ok($p) || strpos($p, '.topper/') === 0) {
    flash('Endereço de pasta inválido.', 'err');
    redirect('/admin/drive.php');
}
$cfg = r2_config();

/** CSS e scripts do Drive, pela ordem do contrato, com ?v=data do ficheiro. Os que ainda não existem ficam de fora. */
function drive_assets(): array {
    $css = ''; $js = '';
    foreach (['core.css', 'drive.css', 'io.css', 'chat.css'] as $f) {
        $path = __DIR__ . '/assets/drive/' . $f;
        if (is_file($path)) $css .= '<link rel="stylesheet" href="/admin/assets/drive/' . $f . '?v=' . filemtime($path) . '">' . "\n";
    }
    foreach (['core.js', 'upload.js', 'zip.js', 'preview.js', 'app.js', 'chat.js'] as $f) {
        $path = __DIR__ . '/assets/drive/' . $f;
        if (is_file($path)) $js .= '<script src="/admin/assets/drive/' . $f . '?v=' . filemtime($path) . '" defer></script>' . "\n";
    }
    return [$css, $js];
}

if (!$cfg) {
    render_header('Drive', 'drive');
    render_flashes();
    $cssFile = __DIR__ . '/assets/drive/drive.css';
    if (is_file($cssFile)) echo '<link rel="stylesheet" href="/admin/assets/drive/drive.css?v=' . filemtime($cssFile) . '">';
    ?>
<h1>Drive</h1>
<div class="panel r2-setup">
  <h2 style="margin-top:0">Ligar o Drive ao Cloudflare R2</h2>
  <p>Os ficheiros passam a ficar num bucket do Cloudflare R2 e são enviados diretamente do browser
     (sem limite de 25 MB). Falta só a configuração:</p>
  <ol>
    <li>No <b>Cloudflare</b> abra <b>R2 Object Storage</b> e crie um bucket (ex.: <code>topper-drive</code>), localização automática.</li>
    <li>Na página principal do R2, à direita, copie o <b>Account ID</b> (32 caracteres).</li>
    <li>Em <b>R2 › Manage API tokens › Create API token</b>: permissão <b>Object Read &amp; Write</b>,
        «Apply to specific buckets only» → o bucket criado. Guarde a <b>Access Key ID</b> e a <b>Secret Access Key</b>
        (a secreta só aparece uma vez).</li>
    <li>No bucket, <b>Settings › CORS Policy › Add CORS policy</b>, cole isto e guarde:
      <pre class="r2-cors"><?= e(drive_cors_json()) ?></pre></li>
    <li>No servidor (Hostinger › Gestor de ficheiros), copie <code>admin/inc/r2_config.example.php</code> para
        <code>admin/inc/r2_config.php</code> e preencha os quatro valores. Este ficheiro não vai para o Git.</li>
    <li>Recarregue esta página.</li>
  </ol>
</div>
<?php
    render_footer();
    exit;
}

[$css, $js] = drive_assets();
render_header('Drive', 'drive', 'td-full');
render_flashes();
echo $css;
?>
<h1 class="sr">Drive</h1>
<div id="drive" class="drive" data-api="/admin/drive_api.php" data-ai-api="/admin/ai_api.php"
     data-csrf="<?= e(csrf_token()) ?>" data-prefix="<?= e($p) ?>">
  <div id="drive-app" class="dr" aria-busy="true">
    <div class="dr-boot" aria-hidden="true"><span></span><span></span><span></span></div>
  </div>
  <aside id="drive-chat" class="drive-chat" aria-label="Assistente"></aside>
</div>
<noscript>
  <div class="flash err drive-noscript">O Drive precisa de JavaScript para mostrar, enviar e organizar os ficheiros.
    Ative o JavaScript neste browser e recarregue a página.</div>
</noscript>
<div id="drive-preview"></div>
<div id="drive-upload-dock"></div>
<?= $js ?>
<?php render_footer();
