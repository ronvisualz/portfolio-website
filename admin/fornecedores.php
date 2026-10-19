<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/sheet.php';
require __DIR__ . '/inc/paginate.php';
$user = require_login();

// Os fornecedores vivem no separador «Fornecedores» da folha Google.

/** Os filtros atuais, para voltar a eles depois de gravar. */
function sup_filters(array $src): array {
    $f = [];
    foreach (['q', 'cat', 'pf'] as $k) {   // pf = página da tabela
        $v = trim((string)($src[$k] ?? ''));
        if ($v !== '') $f[$k] = $v;
    }
    return $f;
}
function sup_url(array $f = []): string {
    return '/admin/fornecedores.php' . ($f ? '?' . http_build_query($f) : '');
}

/** Os campos do formulário, prontos para a folha. */
function sup_values(): array {
    $links = sheet_split(str_replace(["\r\n", "\n"], '|', (string)($_POST['links'] ?? '')), '|');
    $pick  = static function (string $k, array $list): string {
        $v = trim((string)($_POST[$k] ?? ''));
        // valores fora da lista só se aceitam se já estavam na folha
        return ($v === '' || in_array($v, $list, true) || $v === trim((string)($_POST[$k . '_was'] ?? ''))) ? $v : '';
    };
    $nif = trim((string)($_POST['nif'] ?? ''));
    return [
        'Nome'               => sheet_text(trim((string)($_POST['name'] ?? '')) ?: 'Sem nome'),
        'Categoria'          => $pick('category', SUPPLIER_CATEGORIES),
        'Serviço'            => sheet_text((string)($_POST['service'] ?? '')),
        'Pessoa de contacto' => sheet_text((string)($_POST['contact'] ?? '')),
        'Email'              => sheet_text((string)($_POST['email'] ?? '')),
        'Telefone'           => sheet_text((string)($_POST['phone'] ?? ''), true),
        'Site / redes'       => sheet_text(implode(' | ', $links)),
        'NIF'                => sheet_text($nif, true),
        'Tarifa'             => sheet_text((string)($_POST['rate'] ?? '')),
        'Avaliação'          => $pick('rating', SUPPLIER_RATINGS),
        'Último trabalho'    => sheet_text((string)($_POST['last_job'] ?? '')),
        'Notas'              => sheet_text((string)($_POST['notes'] ?? '')),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do   = $_POST['do'] ?? '';
    $row  = (int)($_POST['row'] ?? 0);
    $key  = ['Nome' => (string)($_POST['name_key'] ?? '')];
    $back = sup_url(sup_filters(is_array($_POST['back'] ?? null) ? $_POST['back'] : []));

    if ($do === 'create') {
        sheet_try(fn() => sheet_append('Fornecedores', sup_values()), 'Fornecedor adicionado.');
        redirect($back);
    }
    if ($do === 'save') {
        sheet_try(fn() => sheet_update('Fornecedores', $row, $key, sup_values()), 'Fornecedor guardado.');
        redirect($back);
    }
    if ($do === 'delete') {
        sheet_try(fn() => sheet_delete('Fornecedores', $row, $key), 'Fornecedor eliminado.');
        redirect($back);
    }
    redirect($back);
}

$d = null; $sheetErr = null;
try {
    $d = sheet_data();
} catch (SheetException $e) {
    $sheetErr = $e;
}
$all = $d['suppliers'] ?? [];

$filters = sup_filters($_GET);
$fCat    = $filters['cat'] ?? '';
$q       = $filters['q'] ?? '';

$counts = [];
foreach ($all as $s) if ($s['category'] !== '') $counts[$s['category']] = ($counts[$s['category']] ?? 0) + 1;

$lc = static fn(string $s): string => function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
$needle = $lc($q);
$rows = array_values(array_filter($all, static function ($s) use ($fCat, $needle, $lc) {
    if ($fCat !== '' && $s['category'] !== $fCat) return false;
    if ($needle !== '') {
        $hay = $lc(implode(' ', [$s['name'], $s['category'], $s['service'], $s['contact'], $s['email'], $s['phone'],
                                implode(' ', $s['links']), $s['nif'], $s['rate'], $s['last_job'], $s['notes']]));
        if (strpos($hay, $needle) === false) return false;
    }
    return true;
}));
usort($rows, static fn($a, $b) => [$lc($a['name']), $a['row']] <=> [$lc($b['name']), $b['row']]);

// Fornecedor à espera de confirmação para ser eliminado (dois passos, sem diálogos).
$pending = (int)($_GET['del'] ?? 0);
$editRow = (int)($_GET['edit'] ?? 0);
$es = ($d && $editRow && empty($d['suppliers_error'])) ? sheet_supplier_by_row($d, $editRow) : null;

// 8 por página (?pf=); um fornecedor a eliminar/editar abre na página onde está.
foreach ($rows as $n => $s) {
    if ($s['row'] === $pending || $s['row'] === $editRow) $_GET['pf'] = intdiv($n, PAGE_SIZE) + 1;
}
$pg = paginate($rows, 'pf');
if ($pg['page'] > 1) $filters['pf'] = (string)$pg['page']; else unset($filters['pf']);

function back_fields(array $f): string {
    $h = '';
    foreach ($f as $k => $v) $h .= '<input type="hidden" name="back[' . e($k) . ']" value="' . e($v) . '">';
    return $h;
}

/** Um site ou rede: link (com https:// quando falta) ou texto. */
function sup_link_html(string $c): string {
    $url = preg_match('~^https?://~i', $c) ? $c
         : (preg_match('~^(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(/\S*)?$~i', $c) ? 'https://' . $c : '');
    if ($url === '') return '<span class="muted">' . e($c) . '</span>';
    return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer nofollow">'
         . e(shorten((string)preg_replace('~^https?://(www\.)?~i', '', rtrim($c, '/')), 34)) . '</a>';
}

/** A avaliação como estrelas: «★★★☆☆». */
function sup_stars(string $r): string {
    if ($r === '') return '<span class="muted">—</span>';
    $n = (int)array_search($r, SUPPLIER_RATINGS, true) + (in_array($r, SUPPLIER_RATINGS, true) ? 1 : 0);
    if (!$n) return '<span class="muted">' . e($r) . '</span>';
    return '<span class="stars" title="' . $n . ' de 5" aria-label="' . $n . ' de 5">'
         . str_repeat('★', $n) . '<span class="muted">' . str_repeat('☆', 5 - $n) . '</span></span>';
}

render_header('Fornecedores', 'fornecedores');
render_flashes();
echo sheet_status_line($d);
echo paginate_css();
?>

<h1>Fornecedores</h1>

<?php if ($sheetErr): ?>
  <?= sheet_error_box($sheetErr) ?>
<?php render_footer(); exit; endif; ?>

<?php if (!empty($d['suppliers_error'])): ?>
  <div class="flash err"><b>Atualize o Code.gs.</b> <?= e($d['suppliers_error']) ?>
    <div style="margin-top:0.5rem">Crie o separador «Fornecedores» na folha (títulos na linha 1: Nome, Categoria,
      Serviço, Pessoa de contacto, Email, Telefone, Site / redes, NIF, Tarifa, Avaliação, Último trabalho, Notas),
      cole a versão atual do <code>sheets/Code.gs</code> no Apps Script e faça Implementar &gt; Gerir implementações &gt;
      Nova versão. As outras páginas continuam a funcionar.</div>
    <div style="margin-top:0.5rem"><a href="<?= e(sheet_link()) ?>" target="_blank" rel="noopener noreferrer">Abrir folha</a>
      · <a href="<?= e(sheet_refresh_url()) ?>">Tentar outra vez</a></div>
  </div>
<?php render_footer(); exit; endif; ?>

<div class="sub"><?= count($all) ?> fornecedores no total</div>

<form class="bar" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Procurar nome, serviço, contacto, nota">
  <select name="cat" onchange="this.form.submit()" aria-label="Categoria">
    <option value="">Todas as categorias</option>
    <?php foreach (array_unique(array_merge(SUPPLIER_CATEGORIES, array_keys($counts), $fCat !== '' ? [$fCat] : [])) as $c): ?>
      <option value="<?= e($c) ?>" <?= $fCat === $c ? 'selected' : '' ?>><?= e($c) ?> (<?= $counts[$c] ?? 0 ?>)</option>
    <?php endforeach; ?>
  </select>
  <button class="btn sm" type="submit">Filtrar</button>
  <?php if (array_diff_key($filters, ['pf' => 1])): ?><a class="lnk" href="/admin/fornecedores.php">Limpar</a><?php endif; ?>
</form>

<div class="tablewrap">
<table>
  <thead>
    <tr><th>Nome</th><th>Categoria</th><th class="hide-sm">Contacto</th>
        <th class="hide-sm">Tarifa</th><th>Avaliação</th><th class="hide-sm">Notas</th><th></th></tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="7" class="empty">Nenhum fornecedor encontrado.</td></tr>
  <?php endif; ?>
  <?php foreach ($pg['rows'] as $s): $sid = $s['row']; ?>
    <tr class="<?= $pending === $sid || ($es && $es['row'] === $sid) ? 'pending' : '' ?>">
      <td>
        <span class="b"><?= e($s['name']) ?></span>
        <?php if ($s['service'] !== ''): ?>
          <div class="muted" style="font-size:0.62rem"><?= e(shorten($s['service'], 60)) ?></div>
        <?php endif; ?>
        <?php if ($s['nif'] !== ''): ?>
          <div class="muted" style="font-size:0.62rem">NIF <?= e($s['nif']) ?></div>
        <?php endif; ?>
      </td>
      <td><?= $s['category'] !== '' ? '<span class="chip">' . e($s['category']) . '</span>' : '<span class="muted">—</span>' ?></td>
      <td class="hide-sm" style="font-size:0.7rem">
        <?php if ($s['contact'] !== ''): ?><div><?= e($s['contact']) ?></div><?php endif; ?>
        <?php if ($s['email'] !== ''):
              $mail = preg_match('/[^\s<>()]+@[^\s<>()]+\.[a-z]{2,}/i', $s['email'], $mm) ? $mm[0] : ''; ?>
          <div><?php if ($mail): ?><a href="mailto:<?= e($mail) ?>"><?= e(shorten($s['email'], 34)) ?></a><?php else: ?><span class="muted"><?= e($s['email']) ?></span><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($s['phone'] !== ''): $tel = preg_replace('/[^\d+]/', '', $s['phone']); ?>
          <div><?php if ($tel !== ''): ?><a href="tel:<?= e($tel) ?>"><?= e($s['phone']) ?></a><?php else: ?><span class="muted"><?= e($s['phone']) ?></span><?php endif; ?></div>
        <?php endif; ?>
        <?php foreach ($s['links'] as $c): ?><div><?= sup_link_html($c) ?></div><?php endforeach; ?>
        <?php if ($s['contact'] === '' && $s['email'] === '' && $s['phone'] === '' && !$s['links']): ?><span class="muted">—</span><?php endif; ?>
      </td>
      <td class="hide-sm" style="font-size:0.7rem"><?= e($s['rate'] ?: '—') ?></td>
      <td class="nowrap"><?= sup_stars($s['rating']) ?></td>
      <td class="muted hide-sm" style="font-size:0.7rem">
        <?= e(shorten($s['notes'], 55)) ?>
        <?php if ($s['last_job'] !== ''): ?><div><b>Último trabalho:</b> <?= e(shorten($s['last_job'], 40)) ?></div><?php endif; ?>
      </td>
      <td class="num nowrap">
        <?php if ($pending === $sid): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="row" value="<?= $sid ?>">
            <input type="hidden" name="name_key" value="<?= e($s['name']) ?>">
            <?= back_fields($filters) ?>
            <button class="btn danger sm" type="submit">Sim, eliminar</button>
          </form>
          <a class="lnk" href="<?= e(sup_url($filters)) ?>">Cancelar</a>
        <?php else: ?>
          <a class="lnk" href="<?= e(sup_url($filters + ['edit' => $sid])) ?>#fornecedor">Editar</a>
          <a class="lnk del" href="<?= e(sup_url($filters + ['del' => $sid])) ?>">Eliminar</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= page_nav($pg, ['del', 'edit']) ?>

<?php
  $v = $es ?? ['name' => '', 'category' => '', 'service' => '', 'contact' => '', 'email' => '', 'phone' => '',
               'links' => [], 'nif' => '', 'rate' => '', 'rating' => '', 'last_job' => '', 'notes' => ''];
?>
<h2 id="fornecedor"><?= $es ? 'Editar fornecedor' : 'Adicionar fornecedor' ?></h2>
<form method="post">
  <?= csrf_field() ?>
  <?= back_fields($filters) ?>
  <?php if ($es): ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="row" value="<?= $es['row'] ?>">
    <input type="hidden" name="name_key" value="<?= e($es['name']) ?>">
    <input type="hidden" name="category_was" value="<?= e($es['category']) ?>">
    <input type="hidden" name="rating_was" value="<?= e($es['rating']) ?>">
  <?php else: ?>
    <input type="hidden" name="do" value="create">
  <?php endif; ?>
  <div class="grid">
    <div class="field">
      <label for="sname">Nome</label>
      <input type="text" id="sname" name="name" required value="<?= e($v['name']) ?>">
    </div>
    <div class="field">
      <label for="category">Categoria</label>
      <select id="category" name="category"><?= sheet_options(SUPPLIER_CATEGORIES, $v['category'], '—') ?></select>
    </div>
    <div class="field">
      <label for="rating">Avaliação</label>
      <select id="rating" name="rating"><?= sheet_options(SUPPLIER_RATINGS, $v['rating'], '—') ?></select>
    </div>
    <div class="field wide">
      <label for="service">Serviço</label>
      <input type="text" id="service" name="service" value="<?= e($v['service']) ?>" placeholder="Aluguer de câmaras, color grading…">
    </div>
    <div class="field">
      <label for="contact">Pessoa de contacto</label>
      <input type="text" id="contact" name="contact" value="<?= e($v['contact']) ?>">
    </div>
    <div class="field">
      <label for="email">Email</label>
      <input type="text" id="email" name="email" value="<?= e($v['email']) ?>" placeholder="nome@empresa.com">
    </div>
    <div class="field">
      <label for="phone">Telefone</label>
      <input type="text" id="phone" name="phone" value="<?= e($v['phone']) ?>" placeholder="+351 …" inputmode="tel">
    </div>
    <div class="field wide">
      <label for="links">Site / redes</label>
      <input type="text" id="links" name="links" value="<?= e(implode(' | ', $v['links'])) ?>"
             placeholder="https://site.pt | instagram.com/…">
    </div>
    <div class="field">
      <label for="nif">NIF</label>
      <input type="text" id="nif" name="nif" value="<?= e($v['nif']) ?>" inputmode="numeric">
    </div>
    <div class="field">
      <label for="rate">Tarifa</label>
      <input type="text" id="rate" name="rate" value="<?= e($v['rate']) ?>" placeholder="350 €/dia">
    </div>
    <div class="field">
      <label for="last_job">Último trabalho</label>
      <input type="text" id="last_job" name="last_job" value="<?= e($v['last_job']) ?>">
    </div>
    <div class="field wide">
      <label for="snotes">Notas</label>
      <textarea id="snotes" name="notes"><?= e($v['notes']) ?></textarea>
    </div>
  </div>
  <div class="actions">
    <button class="btn" type="submit"><?= $es ? 'Guardar' : 'Adicionar' ?></button>
    <?php if ($es): ?><a class="btn ghost" href="<?= e(sup_url($filters)) ?>">Cancelar</a><?php endif; ?>
  </div>
</form>

<?php render_footer(); ?>
