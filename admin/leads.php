<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/data.php';
require __DIR__ . '/inc/paginate.php';
$user = require_login();

// As leads (tabela leads). A fase vai como chave (?status=proposta).
const STAGES = LEAD_STAGES;

/** Os filtros atuais, para voltar a eles depois de gravar. */
function lead_filters(array $src): array {
    $f = [];
    foreach (['q', 'status', 'tipo', 'prio', 'pl'] as $k) {   // pl = página da tabela
        $v = trim((string)($src[$k] ?? ''));
        if ($v !== '') $f[$k] = $v;
    }
    return $f;
}
function leads_url(array $f = []): string {
    return '/admin/leads.php' . ($f ? '?' . http_build_query($f) : '');
}

/** Os campos do formulário, prontos para gravar. */
function lead_values(): array {
    $types = [];
    foreach ((array)($_POST['types'] ?? []) as $t) {
        $t = trim((string)$t);
        if ($t !== '' && strpos($t, ',') === false && !in_array($t, $types, true)) $types[] = $t;
    }
    $contacts = data_split(str_replace(["\r\n", "\n"], '|', (string)($_POST['contacts'] ?? '')), '|');
    $stage    = (string)($_POST['status'] ?? 'novo');
    $pick     = static function (string $k, array $list): string {
        $v = trim((string)($_POST[$k] ?? ''));
        // valores fora da lista só se aceitam se já lá estavam
        return ($v === '' || in_array($v, $list, true) || $v === trim((string)($_POST[$k . '_was'] ?? ''))) ? $v : '';
    };
    return [
        'name'       => trim((string)($_POST['name'] ?? '')) ?: 'Sem nome',
        'types'      => $types,
        'contacts'   => $contacts,
        'phone'      => (string)($_POST['phone'] ?? ''),
        'stage'      => isset(STAGES[$stage]) ? $stage : 'novo',
        'service'    => $pick('service', LEAD_SERVICES),
        'priority'   => $pick('priority', LEAD_PRIORITIES),
        'detail'     => (string)($_POST['detail'] ?? ''),
        'why'        => (string)($_POST['why'] ?? ''),
        'email'      => (string)($_POST['email'] ?? ''),
        'notes'      => (string)($_POST['notes'] ?? ''),
        'last_touch' => (string)($_POST['last_touch'] ?? ''),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do   = $_POST['do'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);
    $back = leads_url(lead_filters(is_array($_POST['back'] ?? null) ? $_POST['back'] : []));

    if ($do === 'create') {
        data_try(fn() => data_lead_insert(lead_values()), 'Lead adicionada.');
        redirect($back);
    }

    if ($do === 'save') {
        data_try(fn() => data_lead_update($id, lead_values()), 'Lead guardada.');
        redirect($back);
    }

    if ($do === 'move') {
        $to = (string)($_POST['status'] ?? '');
        if (isset(STAGES[$to])) {
            data_try(fn() => data_lead_update($id, ['stage' => $to, 'last_touch' => data_today()]), 'Fase: ' . STAGES[$to] . '.');
        }
        redirect($back);
    }

    if ($do === 'delete') {
        data_try(fn() => data_lead_delete($id), 'Lead eliminada.');
        redirect($back);
    }

    redirect($back);
}

$d = data_all();
$all = $d['leads'];

$filters = lead_filters($_GET);
$fStatus = $filters['status'] ?? '';
$fTipo   = $filters['tipo'] ?? '';
$fPrio   = $filters['prio'] ?? '';
$q       = $filters['q'] ?? '';
if (!isset(STAGES[$fStatus])) { $fStatus = ''; unset($filters['status']); }

$counts = [];
foreach ($all as $l) {
    $k = $l['stage'] !== '' ? $l['stage'] : $l['stage_label'];
    $counts[$k] = ($counts[$k] ?? 0) + 1;
}

$lc = static fn(string $s): string => function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
$needle = $lc($q);
$rows = array_values(array_filter($all, static function ($l) use ($fStatus, $fTipo, $fPrio, $needle, $lc) {
    if ($fStatus !== '' && $l['stage'] !== $fStatus) return false;
    if ($fTipo !== '' && !in_array($fTipo, $l['types'], true)) return false;
    if ($fPrio !== '' && $l['priority'] !== $fPrio) return false;
    if ($needle !== '') {
        $hay = $lc(implode(' ', [$l['name'], implode(' ', $l['contacts']), $l['notes'], $l['detail'],
                                $l['why'], $l['email'], $l['phone'], implode(' ', $l['types']), $l['service']]));
        if (strpos($hay, $needle) === false) return false;
    }
    return true;
}));

// Primeiro o que está mais perto de fechar; dentro de cada fase, o toque mais recente.
$order = array_flip(['proposta', 'em_conversa', 'contactado', 'novo', 'ganho', 'perdido']);
usort($rows, static function ($a, $b) use ($order) {
    return [$order[$a['stage']] ?? 9, $b['last_touch'] ?: '0', $a['name']]
       <=> [$order[$b['stage']] ?? 9, $a['last_touch'] ?: '0', $b['name']];
});

// Lead à espera de confirmação para ser eliminada (dois passos, sem diálogos).
$pendingLead = (int)($_GET['del'] ?? 0);
$editLead    = (int)($_GET['edit'] ?? 0);
$el = $editLead ? data_lead($d, $editLead) : null;

// 8 por página (?pl=); uma lead a eliminar/editar abre na página onde está.
foreach ($rows as $n => $l) {
    if ($l['id'] === $pendingLead || $l['id'] === $editLead) $_GET['pl'] = intdiv($n, PAGE_SIZE) + 1;
}
$pgL = paginate($rows, 'pl');
if ($pgL['page'] > 1) $filters['pl'] = (string)$pgL['page']; else unset($filters['pl']);

/** Os filtros como campos escondidos, para os formulários de POST. */
function back_fields(array $f): string {
    $h = '';
    foreach ($f as $k => $v) $h .= '<input type="hidden" name="back[' . e($k) . ']" value="' . e($v) . '">';
    return $h;
}

/** Um contacto: link quando é um endereço, texto quando não é. */
function contact_html(string $c): string {
    if (preg_match('~^https?://~i', $c)) {
        return '<a href="' . e($c) . '" target="_blank" rel="noopener noreferrer nofollow">'
             . e(shorten((string)preg_replace('~^https?://(www\.)?~i', '', rtrim($c, '/')), 34)) . '</a>';
    }
    return '<span class="muted">' . e($c) . '</span>';
}

const PRIO_CHIP = ['Alta' => 'no', 'Média' => 'wait', 'Baixa' => ''];

render_header('Leads', 'leads');
render_flashes();
echo data_export_line();
echo paginate_css();
?>

<h1>Leads</h1>


<div class="sub"><?= count($all) ?> contactos no total</div>

<div class="stats" style="margin-bottom:1.6rem">
  <?php foreach (['novo','contactado','em_conversa','proposta'] as $k): ?>
    <div class="stat">
      <div class="k"><?= STAGES[$k] ?></div>
      <div class="v"><?= $counts[$k] ?? 0 ?></div>
    </div>
  <?php endforeach; ?>
</div>

<form class="bar" method="get">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Procurar nome, contacto, nota">
  <select name="status" onchange="this.form.submit()" aria-label="Fase">
    <option value="">Todas as fases</option>
    <?php foreach (STAGES as $k => $label): ?>
      <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>>
        <?= $label ?> (<?= $counts[$k] ?? 0 ?>)
      </option>
    <?php endforeach; ?>
  </select>
  <select name="tipo" onchange="this.form.submit()" aria-label="Tipo">
    <?= data_options(LEAD_TYPES, $fTipo, 'Todos os tipos') ?>
  </select>
  <select name="prio" onchange="this.form.submit()" aria-label="Prioridade">
    <?= data_options(LEAD_PRIORITIES, $fPrio, 'Todas as prioridades') ?>
  </select>
  <button class="btn sm" type="submit">Filtrar</button>
  <?php if (array_diff_key($filters, ['pl' => 1])): ?><a class="lnk" href="/admin/leads.php">Limpar</a><?php endif; ?>
</form>

<div class="tablewrap">
<table>
  <thead>
    <tr><th>Nome</th><th>Tipo</th><th class="hide-sm">Contactos</th>
        <th class="hide-sm">Notas</th><th>Fase</th><th class="hide-sm">Último toque</th><th></th></tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="7" class="empty">Nenhuma lead encontrada.</td></tr>
  <?php endif; ?>
  <?php foreach ($pgL['rows'] as $l): $lid = $l['id']; ?>
    <tr class="<?= $pendingLead === $lid || ($el && $el['id'] === $lid) ? 'pending' : '' ?>">
      <td>
        <span class="b"><?= e($l['name']) ?></span>
        <?php if ($l['priority'] !== ''): ?>
          <span class="chip <?= PRIO_CHIP[$l['priority']] ?? '' ?>" title="Prioridade"><?= e($l['priority']) ?></span>
        <?php endif; ?>
        <?php if ($l['detail'] !== ''): ?>
          <div class="muted" style="font-size:0.62rem"><?= e(shorten($l['detail'], 60)) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php foreach ($l['types'] ?: ['—'] as $t): ?><span class="chip"><?= e($t) ?></span> <?php endforeach; ?>
        <?php if ($l['service'] !== ''): ?>
          <div class="muted" style="font-size:0.62rem;margin-top:0.25rem"><?= e($l['service']) ?></div>
        <?php endif; ?>
      </td>
      <td class="hide-sm" style="font-size:0.7rem">
        <?php foreach ($l['contacts'] as $c): ?><div><?= contact_html($c) ?></div><?php endforeach; ?>
        <?php if ($l['email'] !== ''):
              $mail = preg_match('/[^\s<>()]+@[^\s<>()]+\.[a-z]{2,}/i', $l['email'], $mm) ? $mm[0] : ''; ?>
          <div><?php if ($mail): ?><a href="mailto:<?= e($mail) ?>"><?= e(shorten($l['email'], 34)) ?></a><?php else: ?><span class="muted"><?= e($l['email']) ?></span><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($l['phone'] !== ''): ?><div class="muted"><?= e($l['phone']) ?></div><?php endif; ?>
        <?php if (!$l['contacts'] && $l['email'] === '' && $l['phone'] === ''): ?><span class="muted">—</span><?php endif; ?>
      </td>
      <td class="muted hide-sm" style="font-size:0.7rem">
        <?= e(shorten($l['notes'], 55)) ?>
        <?php if ($l['why'] !== ''): ?>
          <div title="<?= e($l['why']) ?>"><b>Porquê:</b> <?= e(shorten($l['why'], 55)) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="move">
          <input type="hidden" name="id" value="<?= $lid ?>">
          <?= back_fields($filters) ?>
          <select name="status" onchange="this.form.submit()" aria-label="Fase"
                  style="font-size:0.66rem;padding:0.25rem 0.3rem;border-color:var(--hairline)">
            <?php if ($l['stage'] === ''): ?>
              <option value="" selected><?= e($l['stage_label']) ?></option>
            <?php endif; ?>
            <?php foreach (STAGES as $k => $label): ?>
              <option value="<?= $k ?>" <?= $l['stage'] === $k ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td class="muted hide-sm nowrap" style="font-size:0.7rem"><?= e($l['last_touch'] ?: '—') ?></td>
      <td class="num nowrap">
        <?php if ($pendingLead === $lid): ?>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= $lid ?>">
            <?= back_fields($filters) ?>
            <button class="btn danger sm" type="submit">Sim, eliminar</button>
          </form>
          <a class="lnk" href="<?= e(leads_url($filters)) ?>">Cancelar</a>
        <?php else: ?>
          <a class="lnk" href="<?= e(leads_url($filters + ['edit' => $lid])) ?>#lead">Editar</a>
          <a class="lnk del" href="<?= e(leads_url($filters + ['del' => $lid])) ?>">Eliminar</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?= page_nav($pgL, ['del', 'edit']) ?>

<?php
  $v = $el ?? ['name' => '', 'types' => [], 'contacts' => [], 'phone' => '', 'stage' => 'novo', 'stage_label' => '',
               'service' => '', 'priority' => '', 'detail' => '', 'why' => '', 'email' => '', 'notes' => '', 'last_touch' => ''];
  $touchOk = $v['last_touch'] === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['last_touch']);
  $extraTypes = array_diff($v['types'], LEAD_TYPES);
?>
<h2 id="lead"><?= $el ? 'Editar lead' : 'Adicionar lead' ?></h2>
<form method="post">
  <?= csrf_field() ?>
  <?= back_fields($filters) ?>
  <?php if ($el): ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= $el['id'] ?>">
    <input type="hidden" name="service_was" value="<?= e($el['service']) ?>">
    <input type="hidden" name="priority_was" value="<?= e($el['priority']) ?>">
  <?php else: ?>
    <input type="hidden" name="do" value="create">
  <?php endif; ?>
  <div class="grid">
    <div class="field">
      <label for="lname">Nome</label>
      <input type="text" id="lname" name="name" required value="<?= e($v['name']) ?>">
    </div>
    <div class="field">
      <label for="status">Fase</label>
      <select id="status" name="status">
        <?php if ($el && $v['stage'] === ''): ?><option value="" selected><?= e($v['stage_label']) ?></option><?php endif; ?>
        <?php foreach (STAGES as $k => $label): ?>
          <option value="<?= $k ?>" <?= $v['stage'] === $k ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="service">Serviço</label>
      <select id="service" name="service"><?= data_options(LEAD_SERVICES, $v['service'], '—') ?></select>
    </div>
    <div class="field">
      <label for="priority">Prioridade</label>
      <select id="priority" name="priority"><?= data_options(LEAD_PRIORITIES, $v['priority'], '—') ?></select>
    </div>
    <div class="field wide">
      <label>Tipo</label>
      <div class="actions" style="margin-top:0.2rem">
        <?php foreach (array_merge(LEAD_TYPES, $extraTypes) as $t): ?>
          <label class="check"><input type="checkbox" name="types[]" value="<?= e($t) ?>"
                 <?= in_array($t, $v['types'], true) ? 'checked' : '' ?>> <?= e($t) ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="field wide">
      <label for="contacts">Contactos (redes / site)</label>
      <input type="text" id="contacts" name="contacts" value="<?= e(implode(' | ', $v['contacts'])) ?>"
             placeholder="https://instagram.com/… | https://site.pt">
    </div>
    <div class="field">
      <label for="email">Email</label>
      <input type="text" id="email" name="email" value="<?= e($v['email']) ?>" placeholder="nome@empresa.com">
    </div>
    <div class="field">
      <label for="phone">Telefone</label>
      <input type="text" id="phone" name="phone" value="<?= e($v['phone']) ?>" placeholder="+351 …" inputmode="tel">
    </div>
    <div class="field">
      <label for="last_touch">Último toque</label>
      <input type="<?= $touchOk ? 'date' : 'text' ?>" id="last_touch" name="last_touch" value="<?= e($v['last_touch']) ?>">
    </div>
    <div class="field wide">
      <label for="detail">Detalhe</label>
      <input type="text" id="detail" name="detail" value="<?= e($v['detail']) ?>" placeholder="Produtora (Lisboa)">
    </div>
    <div class="field wide">
      <label for="why">Porquê abordar</label>
      <textarea id="why" name="why"><?= e($v['why']) ?></textarea>
    </div>
    <div class="field wide">
      <label for="lnotes">Notas</label>
      <input type="text" id="lnotes" name="notes" value="<?= e($v['notes']) ?>">
    </div>
  </div>
  <div class="actions">
    <button class="btn" type="submit"><?= $el ? 'Guardar' : 'Adicionar' ?></button>
    <?php if ($el): ?><a class="btn ghost" href="<?= e(leads_url($filters)) ?>">Cancelar</a><?php endif; ?>
  </div>
</form>

<?php render_footer(); ?>
