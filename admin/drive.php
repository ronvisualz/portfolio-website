<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/sheet.php';
require __DIR__ . '/inc/drive.php';
require __DIR__ . '/inc/paginate.php';
$user = require_login();

// drive.php?f=<id da pasta>. Sem f, a pasta raiz «Topper Digital».
$f = trim((string)($_GET['f'] ?? $_POST['f'] ?? ''));
if ($f !== '' && !drive_id_ok($f)) {
    flash('Endereço de pasta inválido.', 'err');
    redirect('/admin/drive.php');
}

function drive_page(string $f, string $extra = ''): string {
    return '/admin/drive.php' . ($f !== '' ? '?f=' . rawurlencode($f) . $extra : ($extra !== '' ? '?' . ltrim($extra, '&') : ''));
}

$here = drive_page($f);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Um pedido maior que o post_max_size chega sem nada (nem o csrf).
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash(DRIVE_TOO_BIG, 'err');
        redirect($here);
    }
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'mkdir') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            flash('Escreva o nome da pasta.', 'err');
        } else {
            sheet_try(fn() => drive_mkdir($f ?: DRIVE_ROOT_ID, clip($name, 200)), 'Pasta «' . $name . '» criada.');
        }
        redirect($here);
    }

    if ($do === 'upload') {
        $u = $_FILES['file'] ?? null;
        $err = is_array($u) ? (int)($u['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            flash(DRIVE_TOO_BIG, 'err');
        } elseif ($err === UPLOAD_ERR_NO_FILE || !is_string($u['tmp_name'] ?? null) || $u['tmp_name'] === '') {
            flash('Escolha um ficheiro para enviar.', 'err');
        } elseif ($err !== UPLOAD_ERR_OK || !is_uploaded_file($u['tmp_name'])) {
            flash('O envio do ficheiro falhou (erro ' . $err . '). Tente outra vez.', 'err');
        } elseif ((int)$u['size'] > DRIVE_MAX_BYTES || (int)@filesize($u['tmp_name']) > DRIVE_MAX_BYTES) {
            flash(DRIVE_TOO_BIG, 'err');
        } else {
            $name = trim(str_replace(['/', '\\'], ' ', (string)$u['name'])) ?: 'ficheiro';
            $mime = (string)($u['type'] ?? '');
            if (function_exists('mime_content_type')) {
                $det = @mime_content_type($u['tmp_name']);
                if (is_string($det) && $det !== '' && ($mime === '' || $mime === 'application/octet-stream')) $mime = $det;
            }
            if (!preg_match('~^[\w.+-]+/[\w.+-]+$~', $mime)) $mime = 'application/octet-stream';
            sheet_try(fn() => drive_upload($f ?: DRIVE_ROOT_ID, clip($name, 200), $mime, $u['tmp_name']),
                      'Ficheiro «' . $name . '» enviado para o Drive.');
        }
        redirect($here);
    }

    if ($do === 'trash') {
        $id = (string)($_POST['id'] ?? '');
        if (!drive_id_ok($id)) {
            flash('Item inválido.', 'err');
        } else {
            sheet_try(fn() => drive_trash($id), 'Enviado para o lixo do Drive (pode ser recuperado lá durante 30 dias).');
        }
        redirect($here);
    }

    redirect($here);
}

$list = null;
$error = null;
try {
    $list = drive_list($f);
} catch (SheetException $e) {
    $error = $e->getMessage();
}

$pendingTrash = (string)($_GET['del'] ?? '');
$phpLimit = drive_php_limit();

render_header('Drive', 'drive');
render_flashes();
echo paginate_css();
?>

<h1>Drive</h1>

<?php if ($error !== null): ?>
  <div class="flash err"><b>Não foi possível abrir o Drive.</b> <?= e($error) ?>
    <div style="margin-top:0.5rem"><a href="<?= e($here) ?>">Tentar outra vez</a>
      <?php if ($f !== ''): ?> · <a href="/admin/drive.php">Voltar à pasta Topper Digital</a><?php endif; ?></div>
  </div>
<?php render_footer(); exit; endif; ?>

<?php
$folder = $list['folder'];
$crumbs = is_array($list['breadcrumb'] ?? null) && $list['breadcrumb'] ? $list['breadcrumb'] : [['id' => $folder['id'], 'name' => $folder['name']]];
// Só os itens com id válido; 8 por página (?pd=), a ordem é a do Drive.
$kids = array_values(array_filter((array)$list['children'], static fn($k) => drive_id_ok((string)($k['id'] ?? ''))));
foreach ($kids as $n => $k) {
    if ($pendingTrash !== '' && (string)$k['id'] === $pendingTrash) $_GET['pd'] = intdiv($n, PAGE_SIZE) + 1;
}
$pgD = paginate($kids, 'pd');
$pdQ = $pgD['page'] > 1 ? '&pd=' . $pgD['page'] : '';
$folderUrl = (string)($folder['url'] ?? '');
if (!preg_match('~^https://drive\.google\.com/~', $folderUrl)) $folderUrl = 'https://drive.google.com/drive/folders/' . rawurlencode((string)$folder['id']);
?>

<div class="sub">
  <?php foreach ($crumbs as $i => $c): ?>
    <?= $i ? ' / ' : '' ?>
    <?php if ($i === count($crumbs) - 1): ?>
      <b><?= e((string)$c['name']) ?></b>
    <?php else: ?>
      <a href="<?= e(drive_page((string)$c['id'] === DRIVE_ROOT_ID ? '' : (string)$c['id'])) ?>"><?= e((string)$c['name']) ?></a>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="bar">
  <a class="btn" href="<?= e($folderUrl) ?>" target="_blank" rel="noopener noreferrer">Abrir no Drive</a>
  <?php if (count($crumbs) > 1): $up = $crumbs[count($crumbs) - 2]; ?>
    <a class="btn ghost" href="<?= e(drive_page((string)$up['id'] === DRIVE_ROOT_ID ? '' : (string)$up['id'])) ?>">↑ Pasta acima</a>
  <?php endif; ?>
</div>

<div class="tablewrap">
<table>
  <thead>
    <tr><th></th><th>Nome</th><th>Tipo</th><th class="num">Tamanho</th><th>Alterado</th><th></th></tr>
  </thead>
  <tbody>
  <?php if (!$kids): ?>
    <tr><td colspan="6" class="empty">Esta pasta está vazia.</td></tr>
  <?php endif; ?>
  <?php foreach ($pgD['rows'] as $k):
      $kid = (string)($k['id'] ?? '');
      if (!drive_id_ok($kid)) continue;
      $isFolder = !empty($k['folder']) || ($k['mimeType'] ?? '') === 'application/vnd.google-apps.folder';
      [$kind, $icon] = drive_kind((string)($k['mimeType'] ?? ''), $isFolder);
      $url = (string)($k['url'] ?? '');
      if (!preg_match('~^https://(drive|docs)\.google\.com/~', $url)) $url = '';
      $ts = strtotime((string)($k['modified'] ?? '')) ?: 0;
  ?>
    <tr>
      <td class="mid" aria-hidden="true" title="<?= e($kind) ?>"><?= $icon ?></td>
      <td class="b">
        <?php if ($isFolder): ?>
          <a href="<?= e(drive_page($kid)) ?>"><?= e((string)($k['name'] ?? '')) ?></a>
        <?php else: ?>
          <?= e((string)($k['name'] ?? '')) ?>
        <?php endif; ?>
      </td>
      <td class="muted nowrap"><?= e($kind) ?></td>
      <td class="num"><?= $isFolder ? '—' : drive_size((int)($k['size'] ?? 0)) ?></td>
      <td class="nowrap muted"><?= $ts ? e(sheet_clock($ts, 'd/m/Y H:i')) : '—' ?></td>
      <td class="num nowrap">
        <?php if ($pendingTrash === $kid): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="trash">
            <input type="hidden" name="f" value="<?= e($f) ?>">
            <input type="hidden" name="id" value="<?= e($kid) ?>">
            <button class="btn danger sm" type="submit">Sim, para o lixo</button>
          </form>
          <a class="lnk" href="<?= e(drive_page($f, $pdQ)) ?>">Cancelar</a>
        <?php else: ?>
          <?php if ($url !== ''): ?><a class="lnk" href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer">Abrir</a><?php endif; ?>
          <a class="lnk del" href="<?= e(drive_page($f, $pdQ . '&del=' . rawurlencode($kid))) ?>">Lixo</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= page_nav($pgD, ['del']) ?>

<div class="grid" style="margin-top:1.6rem">
  <form method="post" class="panel">
    <h2 style="margin-top:0">Nova pasta</h2>
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="mkdir">
    <input type="hidden" name="f" value="<?= e($f) ?>">
    <div class="field">
      <label for="dname">Nome</label>
      <input type="text" id="dname" name="name" maxlength="200" required>
    </div>
    <div class="actions"><button class="btn" type="submit">Criar pasta</button></div>
  </form>

  <form method="post" class="panel" enctype="multipart/form-data">
    <h2 style="margin-top:0">Enviar ficheiro</h2>
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="upload">
    <input type="hidden" name="f" value="<?= e($f) ?>">
    <div class="field">
      <label for="dfile">Ficheiro (até 25 MB)</label>
      <input type="file" id="dfile" name="file" required>
    </div>
    <p class="muted" style="font-size:0.75rem;line-height:1.5">
      Para vídeos maiores use «Abrir no Drive» e carregue lá diretamente.
      <?php if ($phpLimit > 0 && $phpLimit < DRIVE_MAX_BYTES): ?>
        <br><b class="neg">Atenção: este servidor só aceita envios até <?= e(drive_size($phpLimit)) ?>
        (upload_max_filesize = <?= e((string)ini_get('upload_max_filesize')) ?>, post_max_size = <?= e((string)ini_get('post_max_size')) ?>).</b>
      <?php endif; ?>
    </p>
    <div class="actions"><button class="btn" type="submit">Enviar</button></div>
  </form>
</div>

<?php render_footer();
