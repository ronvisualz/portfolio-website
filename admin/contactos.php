<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/paginate.php';
$user = require_login();
require_page('contactos');

// Contactos: a base única de pessoas e empresas. Um contacto pode ser
// cliente, fornecedor, parceiro e/ou freelancer ao mesmo tempo. Os
// fornecedores mantêm as categorias, a tarifa e a avaliação de sempre.

const CONTACT_TABS = ['clientes' => 'Clientes', 'fornecedores' => 'Fornecedores', 'parceiros' => 'Parceiros', 'todos' => 'Todos'];
const CONTACT_TYPES = ['is_client' => 'Cliente', 'is_supplier' => 'Fornecedor', 'is_partner' => 'Parceiro', 'is_freelancer' => 'Freelancer'];
const TAB_FLAG = ['clientes' => 'is_client', 'fornecedores' => 'is_supplier', 'parceiros' => 'is_partner'];
const SUPPLIER_CATS = ['Produção', 'Imagem', 'Som', 'Pós-produção', '3D / VFX', 'Elenco', 'Material / aluguer',
                       'Locais', 'Software / serviços', 'Outro'];

function ct_filters(array $src): array {
    $f = [];
    foreach (['t', 'q', 'cat', 'pc'] as $k) {
        $v = trim((string)($src[$k] ?? ''));
        if ($v !== '') $f[$k] = $v;
    }
    return $f;
}
function ct_url(array $f = []): string {
    return '/admin/contactos.php' . ($f ? '?' . http_build_query($f) : '');
}
function back_fields(array $f): string {
    $h = '';
    foreach ($f as $k => $v) $h .= '<input type="hidden" name="back[' . e($k) . ']" value="' . e($v) . '">';
    return $h;
}

function contact_input(array $src): array {
    $t = static fn(string $k, int $n) => mb_substr(trim((string)($src[$k] ?? '')), 0, $n);
    $cat = (string)($src['category'] ?? '');
    $rating = (int)($src['rating'] ?? 0);
    $v = [
        'kind'           => ($src['kind'] ?? '') === 'person' ? 'person' : 'company',
        'name'           => $t('name', 160) ?: 'Sem nome',
        'category'       => in_array($cat, SUPPLIER_CATS, true) ? $cat : '',
        'service'        => $t('service', 160),
        'contact_person' => $t('contact_person', 160),
        'email'          => $t('email', 190),
        'phone'          => $t('phone', 40),
        'nif'            => $t('nif', 20),
        'website'        => $t('website', 255),
        'rate'           => $t('rate', 120),
        'rating'         => $rating >= 1 && $rating <= 5 ? $rating : null,
        'last_job'       => $t('last_job', 160),
        'notes'          => $t('notes', 8000),
    ];
    foreach (array_keys(CONTACT_TYPES) as $flag) $v[$flag] = !empty($src[$flag]) ? 1 : 0;
    return $v;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do   = (string)($_POST['do'] ?? '');
    $back = ct_url(ct_filters(is_array($_POST['back'] ?? null) ? $_POST['back'] : []));

    if ($do === 'create' || $do === 'save') {
        $v = contact_input($_POST);
        $cols = array_keys($v);
        if ($do === 'create') {
            $pdo->prepare('INSERT INTO contacts (' . implode(',', $cols) . ', created_by) VALUES (' . implode(',', array_fill(0, count($cols) + 1, '?')) . ')')
                ->execute([...array_values($v), (int)$user['id']]);
            flash('Contacto adicionado.');
        } else {
            $pdo->prepare('UPDATE contacts SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
                ->execute([...array_values($v), (int)($_POST['id'] ?? 0)]);
            flash('Contacto guardado.');
        }
        redirect($back);
    }

    if ($do === 'delete') {
        require_perm('contactos', PERM_MANAGE);
        $cid = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM deal_contacts WHERE contact_id = ?) + (SELECT COUNT(*) FROM deals WHERE contact_id = ?) + (SELECT COUNT(*) FROM costs WHERE contact_id = ?)');
        $st->execute([$cid, $cid, $cid]);
        if ((int)$st->fetchColumn() > 0) {
            flash('Este contacto está ligado a negócios ou custos: desligue-o primeiro.', 'err');
        } else {
            $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$cid]);
            flash('Contacto eliminado.');
        }
        redirect($back);
    }
    redirect($back);
}

$filters = ct_filters($_GET);
$tab = isset(CONTACT_TABS[$filters['t'] ?? '']) ? $filters['t'] : 'clientes';
$where = isset(TAB_FLAG[$tab]) ? TAB_FLAG[$tab] . ' = 1' : '1=1';
$all = $pdo->query("SELECT * FROM contacts WHERE $where")->fetchAll();

$counts = [];
foreach (CONTACT_TABS as $k => $_) {
    $counts[$k] = (int)$pdo->query('SELECT COUNT(*) FROM contacts WHERE ' . (isset(TAB_FLAG[$k]) ? TAB_FLAG[$k] . ' = 1' : '1=1'))->fetchColumn();
}

$lc = static fn(string $s): string => mb_strtolower($s, 'UTF-8');
$needle = $lc($filters['q'] ?? '');
$rows = array_values(array_filter($all, static function ($c) use ($filters, $needle, $lc) {
    if (($filters['cat'] ?? '') !== '' && $c['category'] !== $filters['cat']) return false;
    if ($needle !== '' && strpos($lc(implode(' ', [$c['name'], $c['service'], $c['contact_person'], $c['email'], $c['phone'],
                                                     $c['nif'], $c['website'], $c['notes'], $c['category']])), $needle) === false) return false;
    return true;
}));
usort($rows, fn($a, $b) => strcmp($lc($a['name']), $lc($b['name'])));

$pending = (int)($_GET['del'] ?? 0);
$editId  = (int)($_GET['edit'] ?? 0);
$ec = null;
if ($editId) { $st = $pdo->prepare('SELECT * FROM contacts WHERE id = ?'); $st->execute([$editId]); $ec = $st->fetch() ?: null; }
foreach ($rows as $n => $c) if (in_array((int)$c['id'], [$pending, $editId], true)) $_GET['pc'] = intdiv($n, PAGE_SIZE) + 1;
$pg = paginate($rows, 'pc');
if ($pg['page'] > 1) $filters['pc'] = (string)$pg['page']; else unset($filters['pc']);

$canEdit = can('contactos', PERM_EDIT);
$canManage = can('contactos', PERM_MANAGE);

render_header('Contactos', 'contactos');
render_flashes();
echo paginate_css();
?>
<h1>Contactos</h1>

<div class="tabs">
  <?php foreach (CONTACT_TABS as $k => $label): ?>
    <a href="<?= e(ct_url(['t' => $k])) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($label) ?> (<?= $counts[$k] ?>)</a>
  <?php endforeach; ?>
</div>

<form class="bar" method="get">
  <input type="hidden" name="t" value="<?= e($tab) ?>">
  <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Procurar nome, serviço, email, NIF">
  <?php if ($tab === 'fornecedores' || $tab === 'todos'): ?>
  <select name="cat" onchange="this.form.submit()" aria-label="Categoria"><option value="">Todas as categorias</option>
    <?php foreach (SUPPLIER_CATS as $c): ?><option <?= ($filters['cat'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <button class="btn sm" type="submit">Filtrar</button>
  <?php if (array_diff_key($filters, ['pc' => 1, 't' => 1])): ?><a class="lnk" href="<?= e(ct_url(['t' => $tab])) ?>">Limpar</a><?php endif; ?>
</form>

<div class="tablewrap">
<table>
  <thead><tr><th>Nome</th><th>Tipo</th><th class="hide-sm">Contacto</th>
    <?= in_array($tab, ['fornecedores', 'todos'], true) ? '<th class="hide-sm">Categoria</th><th class="hide-sm">Tarifa</th><th>Avaliação</th>' : '' ?><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">Nenhum contacto aqui.</td></tr><?php endif; ?>
  <?php foreach ($pg['rows'] as $c): $cid = (int)$c['id']; ?>
    <tr class="<?= in_array($cid, [$pending, $editId], true) ? 'pending' : '' ?>">
      <td><span class="b"><?= e($c['name']) ?></span>
        <div class="muted" style="font-size:0.62rem"><?= $c['kind'] === 'person' ? 'Pessoa' : 'Empresa' ?><?= $c['service'] ? ' · ' . e(shorten($c['service'], 50)) : '' ?><?= $c['nif'] ? ' · NIF ' . e($c['nif']) : '' ?></div></td>
      <td><?= implode(' ', array_map(fn($f) => '<span class="chip">' . e(CONTACT_TYPES[$f]) . '</span>', array_filter(array_keys(CONTACT_TYPES), fn($f) => (int)$c[$f] === 1))) ?: '—' ?></td>
      <td class="hide-sm" style="font-size:0.7rem">
        <?php if ($c['contact_person']): ?><div><?= e($c['contact_person']) ?></div><?php endif; ?>
        <?php if ($c['email']): ?><div><a href="mailto:<?= e($c['email']) ?>"><?= e(shorten($c['email'], 32)) ?></a></div><?php endif; ?>
        <?php if ($c['phone']): ?><div><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a></div><?php endif; ?>
      </td>
      <?php if (in_array($tab, ['fornecedores', 'todos'], true)): ?>
        <td class="hide-sm"><?= $c['category'] ? '<span class="chip">' . e($c['category']) . '</span>' : '—' ?></td>
        <td class="hide-sm" style="font-size:0.7rem"><?= e($c['rate'] ?: '—') ?></td>
        <td class="nowrap"><?= $c['rating'] ? '<span class="stars">' . str_repeat('★', (int)$c['rating']) . '<span class="muted">' . str_repeat('☆', 5 - (int)$c['rating']) . '</span></span>' : '<span class="muted">—</span>' ?></td>
      <?php endif; ?>
      <td class="num nowrap">
        <?php if ($pending === $cid && $canManage): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><?= back_fields($filters + ['t' => $tab]) ?>
            <input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $cid ?>">
            <button class="btn danger sm" type="submit">Sim, eliminar</button></form>
          <a class="lnk" href="<?= e(ct_url($filters + ['t' => $tab])) ?>">Cancelar</a>
        <?php else: ?>
          <a class="lnk" href="<?= e(ct_url($filters + ['t' => $tab, 'edit' => $cid])) ?>#contacto"><?= $canEdit ? 'Editar' : 'Abrir' ?></a>
          <?php if ($canManage): ?><a class="lnk del" href="<?= e(ct_url($filters + ['t' => $tab, 'del' => $cid])) ?>">Eliminar</a><?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= page_nav($pg, ['del', 'edit']) ?>

<?php if ($ec):
    $st = $pdo->prepare("SELECT DISTINCT d.id, d.code, d.name, d.phase FROM deals d
                          LEFT JOIN deal_contacts dc ON dc.deal_id = d.id
                         WHERE d.code IS NOT NULL AND (d.contact_id = ? OR dc.contact_id = ?) ORDER BY d.code DESC");
    $st->execute([$ec['id'], $ec['id']]);
    $linked = $st->fetchAll(); ?>
<h2>Negócios de <span class="keep"><?= e($ec['name']) ?></span></h2>
<?php if (!$linked): ?><p class="muted">Ainda sem negócios.</p><?php else: ?>
  <ul><?php foreach ($linked as $d): ?><li><a class="lnk" href="/admin/negocio.php?id=<?= (int)$d['id'] ?>"><?= e($d['code']) ?></a> <?= e($d['name']) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
<?php endif; ?>

<?php if ($canEdit):
  $v = $ec ?? array_merge(['kind' => 'company', 'name' => '', 'category' => '', 'service' => '', 'contact_person' => '', 'email' => '', 'phone' => '',
                           'nif' => '', 'website' => '', 'rate' => '', 'rating' => null, 'last_job' => '', 'notes' => ''],
                          array_fill_keys(array_keys(CONTACT_TYPES), 0), isset(TAB_FLAG[$tab]) ? [TAB_FLAG[$tab] => 1] : ['is_client' => 1]); ?>
<h2 id="contacto"><?= $ec ? 'Editar contacto' : 'Adicionar contacto' ?></h2>
<form method="post">
  <?= csrf_field() ?><?= back_fields($filters + ['t' => $tab]) ?>
  <input type="hidden" name="do" value="<?= $ec ? 'save' : 'create' ?>">
  <?php if ($ec): ?><input type="hidden" name="id" value="<?= (int)$ec['id'] ?>"><?php endif; ?>
  <div class="grid">
    <div class="field"><label for="cname">Nome</label><input type="text" id="cname" name="name" required maxlength="160" value="<?= e($v['name']) ?>"></div>
    <div class="field"><label for="ckind">É</label><select id="ckind" name="kind">
      <option value="company" <?= $v['kind'] === 'company' ? 'selected' : '' ?>>Empresa</option>
      <option value="person" <?= $v['kind'] === 'person' ? 'selected' : '' ?>>Pessoa</option></select></div>
    <div class="field wide"><label>Tipo</label>
      <div class="actions" style="margin-top:0.2rem">
        <?php foreach (CONTACT_TYPES as $flag => $label): ?>
          <label class="check"><input type="checkbox" name="<?= $flag ?>" value="1" <?= (int)$v[$flag] ? 'checked' : '' ?>> <?= e($label) ?></label>
        <?php endforeach; ?>
      </div></div>
    <div class="field"><label for="ccp">Pessoa de contacto</label><input type="text" id="ccp" name="contact_person" maxlength="160" value="<?= e($v['contact_person']) ?>"></div>
    <div class="field"><label for="cem">Email</label><input type="text" id="cem" name="email" maxlength="190" value="<?= e($v['email']) ?>"></div>
    <div class="field"><label for="cph">Telefone</label><input type="text" id="cph" name="phone" maxlength="40" inputmode="tel" value="<?= e($v['phone']) ?>"></div>
    <div class="field"><label for="cnif">NIF</label><input type="text" id="cnif" name="nif" maxlength="20" inputmode="numeric" value="<?= e($v['nif']) ?>"></div>
    <div class="field wide"><label for="cweb">Site / redes</label><input type="text" id="cweb" name="website" maxlength="255" value="<?= e($v['website']) ?>" placeholder="https://site.pt | instagram.com/…"></div>
    <div class="field wide"><label for="csrv">Serviço</label><input type="text" id="csrv" name="service" maxlength="160" value="<?= e($v['service']) ?>" placeholder="Aluguer de câmaras, color grading…"></div>
    <div class="field"><label for="ccat">Categoria (fornecedor)</label><select id="ccat" name="category"><option value="">—</option>
      <?php foreach (SUPPLIER_CATS as $c): ?><option <?= $v['category'] === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="crate">Tarifa</label><input type="text" id="crate" name="rate" maxlength="120" value="<?= e($v['rate']) ?>" placeholder="350 €/dia"></div>
    <div class="field"><label for="crat">Avaliação</label><select id="crat" name="rating"><option value="">—</option>
      <?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>" <?= (int)$v['rating'] === $i ? 'selected' : '' ?>><?= str_repeat('★', $i) ?></option><?php endfor; ?></select></div>
    <div class="field"><label for="clj">Último trabalho</label><input type="text" id="clj" name="last_job" maxlength="160" value="<?= e($v['last_job']) ?>"></div>
    <div class="field wide"><label for="cno">Notas</label><textarea id="cno" name="notes"><?= e((string)$v['notes']) ?></textarea></div>
  </div>
  <div class="actions"><button class="btn" type="submit"><?= $ec ? 'Guardar' : 'Adicionar' ?></button>
    <?php if ($ec): ?><a class="btn ghost" href="<?= e(ct_url($filters + ['t' => $tab])) ?>">Cancelar</a><?php endif; ?></div>
</form>
<?php endif; ?>
<?php render_footer();
