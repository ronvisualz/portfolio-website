<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/drive.php';
require __DIR__ . '/inc/paginate.php';
$user = require_login();

// drive.php?p=<prefixo da pasta>/. Sem p, a raiz do bucket.
$p = (string)($_GET['p'] ?? '');
if ($p !== '' && substr($p, -1) !== '/') $p .= '/';
if (!r2_prefix_ok($p)) {
    flash('Endereço de pasta inválido.', 'err');
    redirect('/admin/drive.php');
}
$here = drive_page($p);
$cfg = r2_config();

function drive_assets(): string {
    $o = '';
    foreach (['drive.css' => 'link', 'drive.js' => 'script'] as $f => $tag) {
        $path = __DIR__ . '/assets/' . $f;
        $v = is_file($path) ? (string)filemtime($path) : '1';
        $o .= $tag === 'link'
            ? '<link rel="stylesheet" href="/admin/assets/' . $f . '?v=' . $v . '">'
            : '<script src="/admin/assets/' . $f . '?v=' . $v . '" defer></script>';
    }
    return $o;
}

render_header('Drive', 'drive');
render_flashes();
echo paginate_css(), drive_assets();
?>

<h1>Drive</h1>

<?php if (!$cfg): ?>
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
<?php render_footer(); exit; endif; ?>

<?php
$error = null; $list = ['folders' => [], 'files' => []];
try {
    $list = r2_list($p);
} catch (R2Exception $e) {
    $error = $e->getMessage();
}
if ($error !== null): ?>
  <div class="flash err"><b>Não foi possível abrir o Drive.</b> <?= e($error) ?>
    <div style="margin-top:0.5rem"><a href="<?= e($here) ?>">Tentar outra vez</a>
      <?php if ($p !== ''): ?> · <a href="/admin/drive.php">Voltar à raiz</a><?php endif; ?></div>
  </div>
<?php render_footer(); exit; endif;

// Na raiz, as pastas base aparecem sempre.
if ($p === '') $list['folders'] = array_values(array_unique(array_merge(DRIVE_ROOTS, $list['folders'])));
$coll = static fn(string $a, string $b): int => strcmp(mb_strtolower($a), mb_strtolower($b));
$folders = $list['folders'];
if ($p === '') {
    usort($folders, static function ($a, $b) use ($coll) {
        $ia = array_search($a, DRIVE_ROOTS, true); $ib = array_search($b, DRIVE_ROOTS, true);
        if ($ia !== false || $ib !== false) return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
        return $coll($a, $b);
    });
} else {
    usort($folders, $coll);
}
$files = $list['files'];
usort($files, static fn($a, $b) => $coll($a['name'], $b['name']));

$items = [];
foreach ($folders as $f) $items[] = ['folder' => true, 'key' => $f, 'name' => rtrim(substr($f, strlen($p)), '/'), 'size' => -1, 'modified' => 0];
foreach ($files as $f) $items[] = ['folder' => false] + $f;

$pendingDel = (string)($_GET['del'] ?? '');
foreach ($items as $n => $it) {
    if ($pendingDel !== '' && $it['key'] === $pendingDel) $_GET['pd'] = intdiv($n, PAGE_SIZE) + 1;
}
$pg = paginate($items, 'pd');
$pdQ = $pg['page'] > 1 ? '&pd=' . $pg['page'] : '';

$crumbs = [['', 'Drive']];
$acc = '';
foreach ($p === '' ? [] : explode('/', rtrim($p, '/')) as $seg) { $acc .= $seg . '/'; $crumbs[] = [$acc, $seg]; }
?>

<div class="sub drive-crumbs">
  <?php foreach ($crumbs as $i => [$cp, $cn]): ?>
    <?= $i ? ' / ' : '' ?>
    <?php if ($i === count($crumbs) - 1): ?>
      <b><?= e($cn) ?></b>
    <?php else: ?>
      <a href="<?= e(drive_page($cp)) ?>"><?= e($cn) ?></a>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<div id="drive" data-prefix="<?= e($p) ?>" data-csrf="<?= e(csrf_token()) ?>" data-api="/admin/drive_api.php">

<?php if (count($crumbs) > 1): ?>
<div class="bar">
  <a class="btn ghost" href="<?= e(drive_page($crumbs[count($crumbs) - 2][0])) ?>">↑ Pasta acima</a>
</div>
<?php endif; ?>

<div class="tablewrap">
<table class="drive-table">
  <thead>
    <tr><th></th><th>Nome</th><th>Tipo</th><th class="num">Tamanho</th><th>Alterado</th><th></th></tr>
  </thead>
  <tbody>
  <?php if (!$items): ?>
    <tr><td colspan="6" class="empty">Esta pasta está vazia.</td></tr>
  <?php endif; ?>
  <?php foreach ($pg['rows'] as $it):
      [$kind, $icon, $pv] = drive_kind($it['name'], $it['folder']);
  ?>
    <tr data-key="<?= e($it['key']) ?>">
      <td class="mid" aria-hidden="true" title="<?= e($kind) ?>"><?= $icon ?></td>
      <td class="b drive-name">
        <?php if ($it['folder']): ?>
          <a href="<?= e(drive_page($it['key'])) ?>"><?= e($it['name']) ?></a>
        <?php else: ?>
          <a href="#" class="js-open" data-key="<?= e($it['key']) ?>" data-preview="<?= e($pv) ?>"><?= e($it['name']) ?></a>
        <?php endif; ?>
      </td>
      <td class="muted nowrap"><?= e($kind) ?></td>
      <td class="num"><?= $it['folder'] ? '—' : drive_size((int)$it['size']) ?></td>
      <td class="nowrap muted"><?= $it['modified'] ? e((new DateTimeImmutable('@' . $it['modified']))->setTimezone(new DateTimeZone('Europe/Lisbon'))->format('d/m/Y H:i')) : '—' ?></td>
      <td class="num nowrap">
        <?php if ($pendingDel === $it['key']): ?>
          <button class="btn danger sm js-del" type="button" data-key="<?= e($it['key']) ?>">Sim, apagar</button>
          <a class="lnk" href="<?= e(drive_page($p, $pdQ)) ?>">Cancelar</a>
        <?php else: ?>
          <?php if (!$it['folder']): ?>
            <a class="lnk js-dl" href="#" data-key="<?= e($it['key']) ?>">Descarregar</a>
            <a class="lnk js-ren" href="#" data-key="<?= e($it['key']) ?>" data-name="<?= e($it['name']) ?>">Renomear</a>
          <?php endif; ?>
          <a class="lnk del" href="<?= e(drive_page($p, $pdQ . '&del=' . rawurlencode($it['key']))) ?>">Apagar</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= page_nav($pg, ['del']) ?>

<div class="grid" style="margin-top:1.6rem">
  <form class="panel" id="drive-mkdir">
    <h2 style="margin-top:0">Nova pasta</h2>
    <div class="field">
      <label for="dname">Nome</label>
      <input type="text" id="dname" name="name" maxlength="200" required>
    </div>
    <div class="actions"><button class="btn" type="submit">Criar pasta</button></div>
  </form>

  <div class="panel">
    <h2 style="margin-top:0">Enviar ficheiros</h2>
    <label class="drive-drop" id="drive-drop" for="dfile">
      <input type="file" id="dfile" multiple>
      <span><b>Arraste ficheiros para aqui</b> ou clique para escolher.</span>
      <span class="muted">Sem limite de tamanho: os ficheiros vão diretamente para o R2.</span>
    </label>
    <ul class="drive-queue" id="drive-queue"></ul>
  </div>
</div>
<noscript><div class="flash err">O Drive precisa de JavaScript para enviar, abrir e apagar ficheiros.</div></noscript>
</div>

<div class="drive-modal" id="drive-modal" hidden>
  <div class="drive-modal-box" role="dialog" aria-modal="true" aria-label="Pré-visualização">
    <div class="drive-modal-head"><b id="drive-modal-title"></b><button type="button" class="btn ghost sm" id="drive-modal-close">Fechar</button></div>
    <div class="drive-modal-body" id="drive-modal-body"></div>
  </div>
</div>

<?php render_footer();
