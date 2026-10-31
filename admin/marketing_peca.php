<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/marketing_view.php';
$user = require_login();
require_page('marketing');

// A peça (§16.5): dados, checklist do formato, etapas, Revisão (só superadmin e
// admins aprovam), publicação assistida por rede (copiar legenda com o link com
// rastreio, colar o link, métricas), textos das redes e histórico.

$p = mkt_piece($pdo, (int)($_GET['id'] ?? 0));
if (!$p) {
    flash('Peça não encontrada.', 'err');
    redirect('/admin/marketing.php');
}
$id = (int)$p['id'];
$canEdit = can('marketing', PERM_EDIT);
$canApprove = mkt_can_approve($pdo);
$self = '/admin/marketing_peca.php?id=' . $id . nav_keep();
$back = nav_from();
$people = mkt_people($pdo);
$posts = $p['status'] === 'producao' ? mkt_posts($pdo, $id) : [];
$vers = mkt_versions($pdo, $id);
$nets = mkt_nets($p);
$check = mkt_checklist($p);
$editing = $canEdit && isset($_GET['editar']);
$disc = mkt_disclose_state($p['disclose'], $p['disclose_from']);
$today = today_lisbon();
require_once __DIR__ . '/inc/drive.php';
$driveReady = function_exists('r2_config') && r2_config() !== null;

render_header($p['title'], 'marketing', '', [$p['status'] === 'producao' ? 'Produção' : 'Ideias', $p['title']]);
render_flashes();
?>
<div class="lead-page-top">
  <?php if ($back): ?><a class="btn ghost sm" href="<?= e($back) ?>" data-back><?= icon('chev-left') ?> <?= e(nav_label($back)) ?></a>
  <?php else: ?><a class="btn ghost sm" href="/admin/marketing.php<?= $p['status'] === 'producao' ? '?t=producao' : ($p['status'] !== 'nova' ? '?v=' . e($p['status']) : '') ?>" data-back><?= icon('chev-left') ?> Voltar à Marketing</a><?php endif; ?>
  <a class="btn ghost sm" href="<?= e(nav_to('/admin/marketing.php?t=estudio&peca=' . $id, $self)) ?>"><?= icon('sparkle') ?> Estúdio</a>
  <?php if ($p['deal_id'] && can('negocios')): ?><a class="btn ghost sm" href="<?= e(nav_to('/admin/negocio.php?id=' . (int)$p['deal_id'], $self)) ?>"><?= icon('negocios') ?> <?= e((string)$p['deal_code']) ?></a><?php endif; ?>
  <?php if ($p['status'] === 'producao' && can('drive')): ?>
    <?php if ($p['drive_folder']): ?><a class="btn ghost sm" href="<?= e(drive_page_url((string)$p['drive_folder'])) ?>"><?= icon('drive') ?> Pasta no Drive</a>
    <?php elseif ($canEdit && $driveReady): ?><form method="post" action="/admin/mkt_api.php" class="inl" data-drive><?= csrf_field() ?><input type="hidden" name="a" value="drive"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="<?= e($self) ?>">
      <button class="btn ghost sm" type="submit" data-tip="<?= $p['deal_id'] ? 'Na pasta do negócio, em Marketing/<peça>/' : 'Marketing/AAAA-MM/<peça>/' ?>"><?= icon('drive') ?> Criar pasta no Drive</button></form><?php endif; ?>
  <?php endif; ?>
  <?php if ($p['deal_drive'] && can('drive')): ?><a class="btn ghost sm" href="<?= e(drive_page_url((string)$p['deal_drive'])) ?>" data-tip="Os ficheiros do negócio (material do breakdown)"><?= icon('drive') ?> Ficheiros do negócio</a><?php endif; ?>
</div>

<header class="mhead">
  <div class="mhead-who">
    <div class="mtop"><?= mkt_pill($p) ?> <span class="mnets"><?= mkt_nets_html($p) ?></span> <?= mkt_review_chip($p) ?>
      <?php if ($p['source'] !== 'manual'): ?><span class="chip sky"><?= e(MKT_SOURCES[$p['source']]) ?></span><?php endif; ?></div>
    <h1 class="keep"><?= e($p['title']) ?></h1>
    <p class="sub"><?= e($p['status'] === 'producao' ? 'Em Produção · ' . (MKT_STAGES[$p['stage']] ?? '') : 'Ideia · ' . MKT_IDEA_STATUS[$p['status']]) ?>
      · <?= e(MKT_FORMATS[$p['format']] ?? 'formato por decidir') ?><?= $p['template'] ? ' · modelo «' . e(MKT_TEMPLATES[$p['template']][0]) . '»' : '' ?>
      · <?= (int)$p['votes'] ?> voto<?= (int)$p['votes'] === 1 ? '' : 's' ?></p>
  </div>
  <?php if ($canEdit && $p['status'] !== 'producao'): ?>
    <form method="post" action="/admin/mkt_api.php" data-mkt-form data-reload class="mtriage"><?= csrf_field() ?><input type="hidden" name="a" value="triage"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="<?= e($self) ?>">
      <button class="btn" name="to" value="producao"><?= icon('arrow') ?> Avançar para Produção</button>
      <?php if ($p['status'] !== 'guardada'): ?><button class="btn ghost" name="to" value="guardada">Guardar</button><?php endif; ?>
      <?php if ($p['status'] !== 'descartada'): ?><button class="btn ghost" name="to" value="descartada">Descartar</button><?php else: ?><button class="btn ghost" name="to" value="nova">Repor</button><?php endif; ?>
    </form>
  <?php endif; ?>
</header>

<?php if ($p['status'] === 'producao'): $order = array_keys(MKT_STAGES); $cur = array_search($p['stage'], $order, true); ?>
<nav class="df-steps msteps" aria-label="Etapas de produção">
  <?php foreach (MKT_STAGES as $k => $label): $i = array_search($k, $order, true);
      $state = $i < $cur ? 'done' : ($i === $cur ? 'now' : 'next');
      $can = $canEdit && $p['stage'] !== 'publicado' && $k !== $p['stage']; ?>
    <?php if ($can && !in_array($k, ['agendado', 'publicado'], true)): ?>
      <form method="post" action="/admin/mkt_api.php" data-mkt-form data-reload class="inl"><?= csrf_field() ?><input type="hidden" name="a" value="move"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="stage" value="<?= $k ?>"><input type="hidden" name="back" value="<?= e($self) ?>">
        <button type="submit" class="dst <?= $state ?>"<?= $i < $cur ? ' data-tip="Voltar a ' . e($label) . ($p['review_state'] === 'approved' ? ' (tira a aprovação)' : '') . '"' : '' ?>><?= e($label) ?></button></form>
    <?php elseif ($can): ?>
      <button type="button" class="dst <?= $state ?>" data-new="<?= $k === 'agendado' ? '#mdl-msched' : '#mdl-mpub' ?>"><?= e($label) ?></button>
    <?php else: ?>
      <span class="dst <?= $state ?>"<?= $state === 'now' ? ' aria-current="step"' : '' ?>><?= e($label) ?></span>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<div class="mgrid">
<div class="mcol">

<?php if ($p['status'] === 'producao' && in_array($p['stage'], ['revisao', 'agendado', 'publicado'], true) || $p['review_state'] === 'changes'): ?>
<section class="lv-blk mreview<?= $p['stage'] === 'revisao' && $p['review_state'] !== 'approved' ? ' hl' : '' ?>" id="revisao">
  <h4><?= icon('check') ?> Revisão <span class="h-sub">aprovam superadmin e admins</span></h4>
  <?php if ($p['review_state'] === 'approved'): ?>
    <p><span class="chip ok">aprovada</span> <?= $p['reviewed_by'] ? 'por ' . e((string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$p['reviewed_by'])->fetchColumn()) : '' ?>
      <span class="muted mono small"><?= $p['reviewed_at'] ? e(lisbon_fmt(strtotime((string)$p['reviewed_at']), 'd/m H:i')) : '' ?></span></p>
    <?= $p['review_note'] ? '<p class="muted">' . nl2br(e((string)$p['review_note'])) . '</p>' : '' ?>
  <?php elseif ($p['review_state'] === 'changes'): ?>
    <p><span class="chip no">alterações pedidas</span></p><p><?= nl2br(e((string)$p['review_note'])) ?></p>
  <?php else: ?>
    <p class="muted">Ver o ficheiro final (pasta no Drive) e as legendas de cada rede, abaixo. Depois de aprovada, os textos ficam fechados e a peça pode ser agendada.</p>
    <?php if ($canApprove && $canEdit): ?>
      <div class="acts">
        <form method="post" action="/admin/mkt_api.php" data-mkt-form data-reload class="inl"><?= csrf_field() ?><input type="hidden" name="a" value="review"><input type="hidden" name="decision" value="approve"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="<?= e($self) ?>">
          <button class="btn" type="submit"><?= icon('check') ?> Aprovar</button></form>
        <button type="button" class="btn ghost" data-new="#mdl-mchg"><?= icon('bubble') ?> Pedir alterações</button>
      </div>
    <?php else: ?><p class="muted small">À espera de um superadmin ou admin.</p><?php endif; ?>
  <?php endif; ?>
  <?php if ($p['deal_id']): ?><p class="small <?= mkt_disclose_ok($p['disclose'], $p['disclose_from'], $today) ? 'pos' : 'neg' ?>"><?= icon('alert') ?> Negócio <?= e((string)$p['deal_code']) ?>: <?= e($disc['label']) ?>.
    <?= mkt_disclose_ok($p['disclose'], $p['disclose_from'], $today) ? '' : 'Sem autorização, não pode passar a Agendado nem a Publicado.' ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($p['status'] === 'producao'): ?>
<section class="lv-blk" id="publicacao">
  <h4><?= icon('calendar') ?> Publicação <span class="h-sub">fase 1: assistida</span>
    <?php if ($canEdit && $p['stage'] !== 'publicado'): ?><span class="spacer"></span>
      <button type="button" class="btn ghost sm" data-new="#mdl-msched"><?= icon('calendar') ?> Agendar</button>
      <button type="button" class="btn ghost sm" data-new="#mdl-mpub"><?= icon('won') ?> Marcar como publicada</button><?php endif; ?></h4>
  <div class="tablewrap"><table class="mposts">
    <thead><tr><th>Rede</th><th>Estado</th><th class="hide-sm">Link com rastreio</th><th class="num">Cliques</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($nets as $n): $x = $posts[$n] ?? null; $v = $vers[$n] ?? []; $isToday = $x && $x['scheduled_at'] && substr((string)$x['scheduled_at'], 0, 10) <= $today && !$x['published_at']; ?>
      <tr class="<?= $isToday ? 'is-today' : '' ?>">
        <td><?= mkt_net_chip($n) ?> <?= e(MKT_NETWORKS[$n][0]) ?></td>
        <td><?php if ($x && $x['published_at']): ?><span class="pos">publicada <?= e(lisbon_fmt(strtotime((string)$x['published_at']), 'd/m H:i')) ?></span>
              <?php if ($x['url']): ?> <a class="lnk small" href="<?= e($x['url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external') ?> ver</a><?php endif; ?>
              <?= $x['metrics_at'] ? '<br><span class="muted small">alcance ' . (int)$x['reach'] . ' · interações ' . (int)$x['interactions'] . '</span>' : '' ?>
            <?php elseif ($x && $x['scheduled_at']): ?><?= $isToday ? '<b>Publicar hoje</b>' : 'agendada' ?> <span class="mono"><?= e(date('d/m H:i', strtotime((string)$x['scheduled_at']))) ?></span>
              <span class="muted small">· <?= e(first_word((string)($x['publisher_name'] ?? ''))) ?></span>
            <?php else: ?><span class="muted">por agendar</span><?php endif; ?></td>
        <td class="hide-sm"><?php if ($x): ?><button type="button" class="lnk mono small" data-copy="<?= e(mkt_track_url((string)$x['code'])) ?>" data-copy-msg="Link com rastreio copiado." data-tip="<?= e(mkt_track_url((string)$x['code'])) ?> · copiar">go.php?c=<?= e((string)$x['code']) ?></button><?php endif; ?></td>
        <td class="num mono"><?= $x ? (int)$x['tracked'] + (int)$x['clicks'] : '' ?></td>
        <td class="num"><?php if ($x): ?>
          <button type="button" class="btn ghost sm" data-copy="<?= e(mkt_caption($v, $x)) ?>" data-tip="Gancho, texto, CTA, link com rastreio e hashtags"><?= icon('copy') ?> Copiar legenda</button>
          <?php if ($canEdit && $x['published_at']): ?><button type="button" class="btn ghost sm" data-metrics='<?= e(json_encode(['id' => $id, 'net' => $n, 'title' => $p['title'] . ' · ' . MKT_NETWORKS[$n][0], 'v' => ['reach' => $x['reach'], 'interactions' => $x['interactions'], 'saves' => $x['saves'], 'shares' => $x['shares'], 'clicks' => $x['clicks']]], JSON_UNESCAPED_UNICODE)) ?>'><?= icon('trend') ?> Métricas</button><?php endif; ?>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$nets): ?><tr><td colspan="5" class="empty">Escolha as redes da peça.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="muted small">No dia, quem publica tem o lembrete em Tarefas: copia a legenda (já com o link com rastreio), publica o ficheiro final da pasta no Drive e cola aqui o link. As métricas ficam lembradas para 7 dias depois.
    O link com rastreio leva ao site<?= $p['link_target'] ? ' (' . e((string)$p['link_target']) . ')' : '' ?>; as leads do formulário vindas dele entram com a origem «rede · peça».</p>
</section>
<?php endif; ?>

<section class="lv-blk">
  <h4><?= icon('pencil') ?> Textos por rede <span class="h-sub">escritos no Estúdio</span><span class="spacer"></span>
    <a class="btn ghost sm" href="<?= e(nav_to('/admin/marketing.php?t=estudio&peca=' . $id, $self)) ?>"><?= icon('sparkle') ?> Abrir no Estúdio</a></h4>
  <?php if (!array_filter($vers, fn($v) => trim($v['body'] . $v['hook']) !== '')): ?><p class="muted">Ainda sem textos. No Estúdio, a IA cria a versão de cada rede a partir da ideia.</p><?php endif; ?>
  <div class="mvers">
  <?php foreach ($nets as $n): $v = $vers[$n] ?? null; if (!$v || trim($v['body'] . $v['hook']) === '') continue; ?>
    <article><header><?= mkt_net_chip($n) ?> <b><?= e(MKT_NETWORKS[$n][0]) ?></b></header>
      <?php if ($v['hook']): ?><p class="b"><?= e($v['hook']) ?></p><?php endif; ?>
      <?php if ($n === 'x' && count(mkt_thread_parts((string)$v['body'])) > 1): ?>
        <ol class="mthread"><?php foreach (mkt_thread_parts((string)$v['body']) as $t): ?><li><?= nl2br(e($t)) ?> <span class="mono small <?= mb_strlen($t) > MKT_X_LIMIT ? 'neg' : 'muted' ?>"><?= mb_strlen($t) ?>/<?= MKT_X_LIMIT ?></span></li><?php endforeach; ?></ol>
      <?php else: ?><p><?= nl2br(e((string)$v['body'])) ?></p><?php endif; ?>
      <?php if ($v['cta']): ?><p class="muted">→ <?= e($v['cta']) ?></p><?php endif; ?>
      <?php if ($v['hashtags']): ?><p class="muted small"><?= e($v['hashtags']) ?></p><?php endif; ?></article>
  <?php endforeach; ?>
  </div>
</section>

</div>
<div class="mcol">

<section class="lv-blk">
  <h4><?= icon('file') ?> Dados<?php if ($canEdit && !$editing): ?><span class="spacer"></span><a class="btn ghost sm" href="<?= e($self . '&editar=1') ?>"><?= icon('pencil') ?> Editar</a><?php endif; ?></h4>
  <?php if ($editing): ?>
  <form method="post" action="/admin/mkt_api.php" data-mkt-form data-reload data-go="<?= e($self) ?>">
    <?= csrf_field() ?><input type="hidden" name="a" value="piece_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="<?= e($self) ?>"><input type="hidden" name="f[networks_sent]" value="1">
    <div class="grid">
      <div class="field wide"><label for="pe-t">Título</label><input type="text" id="pe-t" name="f[title]" maxlength="200" required value="<?= e($p['title']) ?>"></div>
      <div class="field wide"><label for="pe-i">Ideia</label><textarea id="pe-i" name="f[idea]" rows="4" maxlength="6000"><?= e((string)$p['idea']) ?></textarea></div>
      <div class="field"><label for="pe-pl">Pilar</label><select id="pe-pl" name="f[pillar_id]"><?= mkt_pillar_opts($pdo, $p['pillar_id']) ?></select></div>
      <div class="field"><label for="pe-fo">Formato</label><select id="pe-fo" name="f[format]"><?= mkt_opts(MKT_FORMATS, $p['format'], 'Por decidir') ?></select></div>
      <div class="field"><label for="pe-tp">Modelo</label><select id="pe-tp" name="f[template]"><?= mkt_template_opts($p['template']) ?></select></div>
      <div class="field"><label>Redes</label><?= mkt_net_checks('f[networks]', $nets, 'pe-n-') ?></div>
      <div class="field"><label for="pe-ow">Responsável</label><select id="pe-ow" name="f[owner_id]"><option value="">Sem responsável</option>
        <?php foreach ($people as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$p['owner_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="pe-du">Prazo</label><input type="date" id="pe-du" name="f[due_date]" value="<?= e((string)$p['due_date']) ?>"></div>
      <?php if ($p['status'] === 'producao'): ?>
      <div class="field"><label for="pe-sh">Dia de gravação</label><select id="pe-sh" name="f[shoot_id]"><option value="">—</option>
        <?php foreach (mkt_shoots($pdo, false) as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$p['shoot_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e(date('d/m/Y', strtotime((string)$s['day'])) . ($s['title'] ? ' · ' . $s['title'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="pe-lt">Destino do link com rastreio</label><input type="text" id="pe-lt" name="f[link_target]" maxlength="250" placeholder="/ (página inicial)" value="<?= e((string)$p['link_target']) ?>"></div>
      <?php endif; ?>
    </div>
    <div class="actions"><a class="btn ghost" href="<?= e($self) ?>">Cancelar</a><span class="spacer"></span><button class="btn" type="submit">Gravar</button></div>
  </form>
  <?php else: ?>
  <div class="kv">
    <span>Pilar</span><div><?= mkt_pill($p) ?: '<span class="muted">—</span>' ?></div>
    <span>Formato</span><div><?= e(MKT_FORMATS[$p['format']] ?? '—') ?></div>
    <span>Redes</span><div class="mnets"><?= mkt_nets_html($p) ?: '<span class="muted">—</span>' ?></div>
    <span>Responsável</span><div><?= e($p['owner_name'] ?? 'Sem responsável') ?></div>
    <span>Prazo</span><div><?= $p['due_date'] ? mkt_due_html($p) : '<span class="muted">—</span>' ?></div>
    <span>Autor</span><div><?= e($p['author_name'] ?? '—') ?> <span class="muted mono small"><?= e(lisbon_fmt(strtotime((string)$p['created_at']), 'd/m/Y')) ?></span></div>
    <?php if ($p['deal_id']): ?><span>Negócio</span><div><?= e($p['deal_code'] . ' · ' . $p['deal_name']) ?><br><span class="small <?= mkt_disclose_ok($p['disclose'], $p['disclose_from'], $today) ? 'pos' : 'neg' ?>"><?= e($disc['label']) ?></span></div><?php endif; ?>
    <?php if ($p['shoot_day']): ?><span>Gravação</span><div><a href="/admin/marketing.php?t=producao&amp;dia=<?= (int)$p['shoot_id'] ?>#dia"><?= e(date('d/m/Y', strtotime((string)$p['shoot_day'])) . ($p['shoot_title'] ? ' · ' . $p['shoot_title'] : '')) ?></a></div><?php endif; ?>
    <?php if ($p['parent_id']): ?><span>Parte 2 de</span><div><a href="/admin/marketing_peca.php?id=<?= (int)$p['parent_id'] ?>">peça #<?= (int)$p['parent_id'] ?></a></div><?php endif; ?>
    <?php if ($p['drive_folder']): ?><span>Pasta</span><div class="mono small"><?= e((string)$p['drive_folder']) ?></div><?php endif; ?>
  </div>
  <?php if ($p['idea']): ?><p class="mid"><?= nl2br(e((string)$p['idea'])) ?></p><?php endif; ?>
  <?php if ($p['template'] === 'breakdown' && $p['deal_id']): ?><p class="muted small"><?= icon('bulb') ?> Breakdown: o material está na pasta do negócio no Drive (Criativo, Produção, Entregas) e no quadro do projeto no Toppernote.</p><?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($driveReady): require_once __DIR__ . '/inc/files_view.php'; ?>
<section class="lv-blk"><h4><?= icon('drive') ?> Ficheiros</h4><?= files_piece_html($pdo, $p, $canEdit) ?></section>
<?php endif; ?>

<?php if ($p['status'] === 'producao'): $done = count(array_filter($check, fn($c) => !empty($c['done']))); ?>
<section class="lv-blk" data-checklist="<?= $id ?>">
  <h4><?= icon('checklist') ?> Checklist <span class="h-sub mono" data-check-n><?= $done ?>/<?= count($check) ?></span></h4>
  <ul class="mcheck">
    <?php foreach ($check as $i => $c): ?>
      <li><form method="post" action="/admin/mkt_api.php" class="inl" data-check><?= csrf_field() ?><input type="hidden" name="a" value="check"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="i" value="<?= $i ?>"><input type="hidden" name="back" value="<?= e($self) ?>">
        <label class="check"><input type="checkbox" name="done" value="1" <?= !empty($c['done']) ? 'checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> <span><?= e($c['t']) ?></span></label>
        <noscript><button class="btn ghost sm">Guardar</button></noscript></form></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($canEdit): ?><form method="post" action="/admin/mkt_api.php" class="mcheck-add" data-mkt-form data-reload><?= csrf_field() ?><input type="hidden" name="a" value="check"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="<?= e($self) ?>">
    <label class="sr" for="ck-add">Novo passo</label><input type="text" id="ck-add" name="add" maxlength="160" placeholder="+ Passo"><button class="btn ghost sm" type="submit">Juntar</button></form><?php endif; ?>
</section>
<?php endif; ?>

<?php $scenes = json_decode((string)$p['script'], true); if (is_array($scenes) && $scenes): ?>
<section class="lv-blk"><h4><?= icon('camera') ?> Guião</h4>
  <ol class="mscript"><?php foreach ($scenes as $s): ?><li><span class="mono"><?= e((string)($s['time'] ?? '')) ?></span> <?= e((string)($s['scene'] ?? '')) ?></li><?php endforeach; ?></ol></section>
<?php endif; ?>

<section class="lv-blk"><h4><?= icon('clock') ?> Histórico</h4><?= mkt_history_html($pdo, $id) ?></section>
</div>
</div>

<?php if ($canEdit && $p['status'] === 'producao'): ?>
<?= mkt_schedule_modal_html($p, $posts, $self) ?>
<?= mkt_publish_modal_html($p, $posts, $self) ?>
<?= mkt_metrics_modal_html($self) ?>
<?php if ($canApprove): ?><?= mkt_changes_modal_html($p, $self) ?><?php endif; ?>
<?php endif; ?>
<script src="<?= asset_url('marketing.js') ?>" defer></script>
<?php render_footer();

function drive_page_url(string $path): string {
    require_once __DIR__ . '/inc/drive.php';
    return drive_page($path);
}
