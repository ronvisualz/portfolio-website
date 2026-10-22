<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/marketing_view.php';
$user = require_login();
require_page('marketing');

// Marketing (§16): o motor de conteúdo da topper. para Instagram, LinkedIn e X.
// Separadores Ideias · Estúdio · Produção · Calendário · Desempenho — o ciclo
// ideias → criar → produzir → agendar → medir → novas ideias. Tudo o que grava
// passa por mkt_api.php (permissões no servidor); aqui só se lê e desenha.

$tab = isset(MKT_TABS[$_GET['t'] ?? '']) ? (string)$_GET['t'] : 'ideias';
$canEdit = can('marketing', PERM_EDIT);
$canApprove = mkt_can_approve($pdo);
$self = '/admin/marketing.php' . ($tab !== 'ideias' ? '?t=' . $tab : '');
$here = (string)($_SERVER['REQUEST_URI'] ?? $self);
if (!preg_match('~^/admin/marketing\.php(\?[^#\s]*)?$~', $here)) $here = $self;
$pillars = mkt_pillars($pdo);
$newCount = (int)$pdo->query("SELECT COUNT(*) FROM mkt_pieces WHERE status = 'nova'")->fetchColumn();

render_header('Marketing', 'marketing', '', [MKT_TABS[$tab]]);
render_flashes();
?>
<div class="page-head">
  <div><h1>Marketing</h1>
    <p class="sub">Conteúdo para Instagram, LinkedIn e X: ideias → criar → produzir → agendar → medir.</p></div>
  <?php if ($canEdit): ?>
    <button type="button" class="btn" data-new="#mdl-idea" data-new-idea aria-keyshortcuts="I N"><?= icon('plus') ?> Ideia <span class="kbd">I</span></button>
  <?php endif; ?>
</div>

<nav class="tabs mtabs" aria-label="Separadores">
  <?php foreach (MKT_TABS as $k => $label): ?>
    <a href="/admin/marketing.php<?= $k !== 'ideias' ? '?t=' . $k : '' ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?><?= $k === 'ideias' && $newCount ? ' <span class="mono">(' . $newCount . ')</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php
// ════════════════════════════════════════════════════════════
if ($tab === 'ideias'):
    $views = ['nova' => 'Novas', 'guardada' => 'Guardadas', 'descartada' => 'Descartadas', 'todas' => 'Todas'];
    $view = isset($views[$_GET['v'] ?? '']) ? (string)$_GET['v'] : 'nova';
    $pf = (int)($_GET['pilar'] ?? 0);
    $all = mkt_pieces_where($pdo, '1=1', [], 'p.created_at DESC, p.id DESC');
    $counts = ['todas' => count($all)];
    foreach (['nova', 'guardada', 'descartada'] as $k) $counts[$k] = count(array_filter($all, fn($p) => $p['status'] === $k));
    $rows = array_values(array_filter($all, fn($p) => ($view === 'todas' || $p['status'] === $view) && (!$pf || (int)$p['pillar_id'] === $pf)));
    if ($view === 'nova') usort($rows, fn($a, $b) => [(int)$b['votes'], $b['created_at']] <=> [(int)$a['votes'], $a['created_at']]);
    $voted = $pdo->prepare('SELECT piece_id FROM mkt_votes WHERE user_id = ?');
    $voted->execute([(int)$user['id']]);
    $mine = array_flip(array_map('intval', $voted->fetchAll(PDO::FETCH_COLUMN)));
    $sugg = mkt_repeat_suggestions($pdo);
    $url = fn(array $q) => '/admin/marketing.php?' . http_build_query(array_filter(['v' => $view !== 'nova' ? $view : null, 'pilar' => $pf ?: null] + $q, fn($x) => $x !== null && $x !== ''));
?>
<?php if ($canEdit): ?>
<form class="mquick" method="post" action="/admin/mkt_api.php" data-idea-quick>
  <?= csrf_field() ?><input type="hidden" name="a" value="idea_new"><input type="hidden" name="back" value="<?= e($here) ?>">
  <?= icon('bulb') ?><label class="sr" for="mq">Nova ideia</label>
  <input type="text" id="mq" name="idea" maxlength="6000" placeholder="Escreva uma ideia e carregue em Enter (só o texto é obrigatório)" autocomplete="off">
  <button class="btn sm" type="submit">Guardar</button>
</form>
<?php endif; ?>

<?php if ($sugg && $canEdit): ?>
<section class="msugg" aria-label="Sugestões automáticas">
  <h2 class="h-sm"><?= icon('sparkle') ?> Correram bem — fazer parte 2?</h2>
  <ul><?php foreach ($sugg as $s): ?>
    <li><?= mkt_net_chip($s['network']) ?> <a href="/admin/marketing_peca.php?id=<?= $s['id'] ?>" data-panel="mkt:<?= $s['id'] ?>"><?= e($s['title']) ?></a>
      <span class="muted small"><?= number_format($s['x'], 1, ',', '') ?>× a média de interações</span>
      <form method="post" action="/admin/mkt_api.php" data-mkt-form class="inl"><?= csrf_field() ?><input type="hidden" name="a" value="repeat"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="back" value="<?= e($here) ?>">
        <button class="btn ghost sm" type="submit"><?= icon('plus') ?> Criar parte 2</button></form></li>
  <?php endforeach; ?></ul>
</section>
<?php endif; ?>

<div class="toolbar">
  <?= views_bar($views, $view, fn($k) => '/admin/marketing.php?' . http_build_query(array_filter(['v' => $k !== 'nova' ? $k : null, 'pilar' => $pf ?: null])), $counts) ?>
  <form method="get" class="filters" data-autosubmit>
    <?php if ($view !== 'nova'): ?><input type="hidden" name="v" value="<?= e($view) ?>"><?php endif; ?>
    <label class="sr" for="fpil">Pilar</label>
    <select id="fpil" name="pilar" onchange="this.form.submit()"><option value="">Todos os pilares</option>
      <?php foreach ($pillars as $id => $pl): ?><option value="<?= $id ?>" <?= $pf === $id ? 'selected' : '' ?>><?= e($pl['name']) ?></option><?php endforeach; ?></select>
    <noscript><button class="btn ghost sm">Filtrar</button></noscript>
  </form>
</div>

<div class="tablewrap">
<table class="midea">
  <thead><tr><th class="num" style="width:56px">Votos</th><th>Ideia</th><th class="hide-sm">Pilar</th><th class="hide-sm">Formato</th><th>Redes</th><th class="hide-sm">Autor</th><th class="num">Triagem</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty"><?= $view === 'nova' ? 'Sem ideias novas. Use «+ Ideia» ou a tecla I em qualquer ecrã.' : 'Nada aqui.' ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $p): $id = (int)$p['id']; $on = isset($mine[$id]); ?>
    <tr data-panel="mkt:<?= $id ?>" data-row="<?= $id ?>">
      <td class="num"><?php if ($canEdit): ?>
        <form method="post" action="/admin/mkt_api.php" data-vote class="inl"><?= csrf_field() ?><input type="hidden" name="a" value="vote"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="<?= e($here) ?>">
          <button type="submit" class="mvote<?= $on ? ' on' : '' ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>" aria-label="<?= $on ? 'Tirar o meu voto' : 'Votar nesta ideia' ?>" data-tip><?= icon('thumb') ?><span><?= (int)$p['votes'] ?></span></button></form>
        <?php else: ?><span class="mono"><?= (int)$p['votes'] ?></span><?php endif; ?></td>
      <td><a class="b" href="/admin/marketing_peca.php?id=<?= $id ?>"><?= e($p['title']) ?></a>
        <?php if ($p['source'] !== 'manual'): ?> <span class="chip sky" data-tip="Ideia automática"><?= e($p['source'] === 'portfolio' ? 'do portfólio' : 'parte 2') ?></span><?php endif; ?>
        <?php if ($p['status'] === 'producao'): ?> <span class="chip ok"><?= e(MKT_STAGES[$p['stage']] ?? '') ?></span><?php elseif ($view === 'todas'): ?> <span class="chip"><?= e(MKT_IDEA_STATUS[$p['status']]) ?></span><?php endif; ?>
        <?php if ($p['idea'] && trim((string)$p['idea']) !== trim((string)$p['title'])): ?><div class="muted small clamp2"><?= e(shorten((string)$p['idea'], 180)) ?></div><?php endif; ?>
        <?= mkt_deal_line($p) ?></td>
      <td class="hide-sm"><?= mkt_pill($p) ?: '<span class="muted">—</span>' ?></td>
      <td class="hide-sm"><?= e(MKT_FORMATS[$p['format']] ?? '—') ?></td>
      <td><span class="mnets"><?= mkt_nets_html($p) ?: '<span class="muted">—</span>' ?></span></td>
      <td class="hide-sm muted"><?= e(first_word((string)($p['author_name'] ?? '—'))) ?> <span class="mono small"><?= e(lisbon_fmt(strtotime((string)$p['created_at']), 'd/m')) ?></span></td>
      <td class="num"><?php if ($canEdit && $p['status'] !== 'producao'): ?>
        <form method="post" action="/admin/mkt_api.php" data-mkt-form class="mtriage"><?= csrf_field() ?><input type="hidden" name="a" value="triage"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="<?= e($here) ?>">
          <button class="btn sm" name="to" value="producao" data-tip="Vai para Produção (Ideia aprovada)"><?= icon('arrow') ?> Avançar</button>
          <?php if ($p['status'] !== 'guardada'): ?><button class="btn ghost sm" name="to" value="guardada" data-tip="Fica para depois">Guardar</button><?php endif; ?>
          <?php if ($p['status'] !== 'descartada'): ?><button class="btn ghost sm" name="to" value="descartada">Descartar</button><?php else: ?><button class="btn ghost sm" name="to" value="nova">Repor</button><?php endif; ?>
        </form>
      <?php elseif ($p['status'] === 'producao'): ?><a class="btn ghost sm" href="/admin/marketing.php?t=producao">Produção</a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php
// ════════════════════════════════════════════════════════════
elseif ($tab === 'estudio'):
    $pid = (int)($_GET['peca'] ?? 0);
    $p = $pid ? mkt_piece($pdo, $pid) : null;
    $pickable = mkt_pieces_where($pdo, "p.status IN ('nova','guardada','producao') AND (p.stage IS NULL OR p.stage <> 'publicado')", [], 'p.updated_at DESC, p.id DESC');
?>
<form method="get" class="mpick">
  <input type="hidden" name="t" value="estudio">
  <label for="mpk">Peça</label>
  <select id="mpk" name="peca" onchange="this.form.submit()"><option value="">Escolher uma ideia ou peça…</option>
    <?php foreach ($pickable as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $pid === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['title']) ?> · <?= e($x['status'] === 'producao' ? MKT_STAGES[$x['stage']] : MKT_IDEA_STATUS[$x['status']]) ?></option><?php endforeach; ?></select>
  <noscript><button class="btn ghost sm">Abrir</button></noscript>
</form>
<?php if (!$p): ?>
  <div class="empty-card"><?= icon('sparkle') ?><p><b>Escreve-se a ideia uma vez; a IA cria a versão de cada rede.</b><br>
    Escolha uma ideia ou peça acima. A IA (Claude) usa sempre a «Voz da topper.» do Centro de Conhecimento e só propõe: uma pessoa escolhe o que usar e a peça é aprovada na Revisão.</p></div>
<?php else:
    $vers = mkt_versions($pdo, (int)$p['id']);
    $posts = $p['status'] === 'producao' ? mkt_posts($pdo, (int)$p['id']) : [];
    $nets = mkt_nets($p) ?: array_keys(MKT_NETWORKS);
    $edit = $canEdit && !mkt_locked($p);
    $tpl = $p['template'] !== '' ? MKT_TEMPLATES[$p['template']] : null;
    $scenes = json_decode((string)$p['script'], true);
    require_once __DIR__ . '/inc/ai.php';
    $aiReady = ai_config() !== null;
?>
<div class="st2" data-studio="<?= (int)$p['id'] ?>" data-edit="<?= $edit ? '1' : '0' ?>">
  <section class="ed lv-blk">
    <h6 class="eyebrow">Ideia base · <?= e($p['pillar_name'] ?? 'sem pilar') ?><?= $tpl ? ' · modelo «' . e($tpl[0]) . '»' : '' ?></h6>
    <h2 class="keep"><a href="/admin/marketing_peca.php?id=<?= (int)$p['id'] ?>" style="color:inherit"><?= e($p['title']) ?></a></h2>
    <label class="sr" for="st-idea">Ideia base</label>
    <textarea id="st-idea" data-piece-field="idea" rows="6" maxlength="6000"<?= $edit ? '' : ' readonly' ?>><?= e((string)$p['idea']) ?></textarea>
    <div class="grid mini3">
      <div class="field"><label for="st-tp">Modelo</label><select id="st-tp" data-piece-field="template"<?= $edit ? '' : ' disabled' ?>><?= mkt_template_opts($p['template']) ?></select></div>
      <div class="field"><label for="st-pl">Pilar</label><select id="st-pl" data-piece-field="pillar_id"<?= $edit ? '' : ' disabled' ?>><?= mkt_pillar_opts($pdo, $p['pillar_id']) ?></select></div>
      <div class="field"><label for="st-fo">Formato</label><select id="st-fo" data-piece-field="format"<?= $edit ? '' : ' disabled' ?>><?= mkt_opts(MKT_FORMATS, $p['format'], 'Por decidir') ?></select></div>
    </div>
    <?php if ($canEdit): ?>
    <div class="aib" role="group" aria-label="Ações da IA">
      <?php foreach (MKT_AI_ACTIONS as $k => [$label, $needNet]): ?>
        <button type="button" class="<?= $k === 'adapt' ? 'btn sm' : 'chip-btn' ?>" data-ai="<?= $k ?>"<?= $needNet ? ' data-need-net' : '' ?><?= $edit && $aiReady ? '' : ' disabled' ?>><?= $k === 'adapt' ? icon('sparkle') . ' ' : '' ?><?= e($label) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="aiopt">
      <label for="ai-net">Rede</label><select id="ai-net"><?php foreach ($nets as $n): ?><option value="<?= $n ?>"><?= e(MKT_NETWORKS[$n][0]) ?></option><?php endforeach; ?></select>
      <label for="ai-tone">Tom</label><select id="ai-tone"><?= mkt_opts(MKT_TONES, 'profissional') ?></select>
    </div>
    <?php endif; ?>
    <p class="muted small"><?= icon('sparkle') ?> Usa sempre a «Voz da topper.» (<a href="/admin/conhecimento.php?s=voz">Centro de Conhecimento</a>). A IA propõe; uma pessoa escolhe e a peça é aprovada na Revisão.
      <?= $aiReady ? '' : '<br><b>A IA ainda não está configurada neste servidor</b> (falta a chave da Anthropic em admin/inc/ai_config.php).' ?>
      <?= mkt_locked($p) ? '<br><b>Aprovada:</b> os textos ficam como foram aprovados. Para mudar, volte a peça a Edição (pede nova aprovação).' : '' ?></p>
    <div class="mprops" data-props aria-live="polite" hidden><h6 class="eyebrow">Propostas da IA</h6><div data-props-list></div></div>
    <?php if (is_array($scenes) && $scenes): ?>
      <h6 class="eyebrow">Guião</h6>
      <ol class="mscript"><?php foreach ($scenes as $s): ?><li><span class="mono"><?= e((string)($s['time'] ?? '')) ?></span> <?= e((string)($s['scene'] ?? '')) ?></li><?php endforeach; ?></ol>
    <?php endif; ?>
  </section>
  <div class="outs">
    <?php foreach ($nets as $n): ?><?= mkt_version_card($n, $vers[$n] ?? null, $p, $posts[$n] ?? null, $edit) ?><?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
elseif ($tab === 'producao'):
    $pieces = mkt_pieces_where($pdo, "p.status = 'producao'", [], 'p.due_date IS NULL, p.due_date, p.id');
    $by = [];
    foreach ($pieces as $p) $by[$p['stage']][] = $p;
    // Publicado: só as do último mês no quadro (o resto fica em Desempenho).
    $by['publicado'] = array_values(array_filter($by['publicado'] ?? [], fn($p) => strtotime((string)$p['published_at']) > time() - 31 * 86400));
    $postsBy = [];
    if ($pieces) {
        foreach ($pdo->query('SELECT * FROM mkt_posts WHERE piece_id IN (' . implode(',', array_map(fn($p) => (int)$p['id'], $pieces)) . ')')->fetchAll() as $x) $postsBy[(int)$x['piece_id']][$x['network']] = $x;
    }
    $shoots = mkt_shoots($pdo);
    $dia = (int)($_GET['dia'] ?? 0);
    $shoot = null;
    if ($dia) { $st = $pdo->prepare('SELECT * FROM mkt_shoots WHERE id = ?'); $st->execute([$dia]); $shoot = $st->fetch() ?: null; }
?>
<section class="mshoots" aria-label="Dias de gravação">
  <h2 class="h-sm"><?= icon('camera') ?> Dias de gravação</h2>
  <div class="mshoot-row">
    <?php foreach ($shoots as $s): ?>
      <a class="mshoot<?= $dia === (int)$s['id'] ? ' on' : '' ?>" href="/admin/marketing.php?t=producao&amp;dia=<?= (int)$s['id'] ?>#dia">
        <b class="mono"><?= e(date('d/m', strtotime((string)$s['day']))) ?></b><span><?= e($s['title'] ?: 'Gravação') ?></span><small><?= (int)$s['pieces'] ?> peça<?= (int)$s['pieces'] === 1 ? '' : 's' ?><?= $s['place'] ? ' · ' . e($s['place']) : '' ?></small></a>
    <?php endforeach; ?>
    <?php if (!$shoots): ?><p class="muted small">Sem gravações marcadas. Junte várias peças num dia: gera a lista de planos e de material.</p><?php endif; ?>
    <?php if ($canEdit): ?><button type="button" class="btn ghost sm" data-new="#mdl-shoot"><?= icon('plus') ?> Dia de gravação</button><?php endif; ?>
  </div>
</section>

<?php if ($shoot): $sl = mkt_shot_list($pdo, (int)$shoot['id']); ?>
<section class="lv-blk mday" id="dia">
  <header class="mday-h"><h2><?= icon('camera') ?> <?= e($shoot['title'] ?: 'Dia de gravação') ?> · <span class="mono"><?= e(date('d/m/Y', strtotime((string)$shoot['day']))) ?></span></h2>
    <div class="acts"><button type="button" class="btn ghost sm" onclick="window.print()"><?= icon('file') ?> Imprimir</button>
      <?php if ($canEdit): ?><button type="button" class="btn ghost sm" data-new="#mdl-shoot" data-shoot='<?= e(json_encode(['id' => (int)$shoot['id'], 'day' => $shoot['day'], 'title' => $shoot['title'], 'place' => $shoot['place'], 'notes' => $shoot['notes'], 'pieces' => array_map(fn($x) => (int)$x['id'], $sl['pieces'])], JSON_UNESCAPED_UNICODE)) ?>'><?= icon('pencil') ?> Editar</button>
        <form method="post" action="/admin/mkt_api.php" class="inl" data-mkt-form data-confirm="Apagar este dia de gravação? As peças ficam."><?= csrf_field() ?><input type="hidden" name="a" value="shoot_drop"><input type="hidden" name="id" value="<?= (int)$shoot['id'] ?>"><input type="hidden" name="back" value="/admin/marketing.php?t=producao">
          <button class="btn ghost sm" type="submit">Apagar</button></form><?php endif; ?></div></header>
  <?php if ($shoot['place'] || $shoot['notes']): ?><p class="muted"><?= e((string)$shoot['place']) ?><?= $shoot['place'] && $shoot['notes'] ? ' · ' : '' ?><?= nl2br(e((string)$shoot['notes'])) ?></p><?php endif; ?>
  <div class="grid2">
    <div><h3 class="h-sm">Lista de planos</h3>
      <?php if (!$sl['shots']): ?><p class="muted">Ainda sem peças neste dia.</p><?php endif; ?>
      <?php foreach ($sl['shots'] as $s): ?>
        <h4><?= e($s['piece']['title']) ?></h4>
        <?php if ($s['shots']): ?><ol class="mscript"><?php foreach ($s['shots'] as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ol>
        <?php else: ?><p class="muted small">Sem guião: escreva-o no Estúdio («Guião cena a cena»).</p><?php endif; ?>
      <?php endforeach; ?></div>
    <div><h3 class="h-sm">Material</h3>
      <?php if ($sl['material']): ?><ul class="mcheck-list"><?php foreach ($sl['material'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
      <?php else: ?><p class="muted small">Sem material pelos modelos das peças.</p><?php endif; ?></div>
  </div>
</section>
<?php endif; ?>

<div class="pboard mboard"<?= $canEdit ? ' data-mboard' : '' ?>>
  <?php foreach (MKT_STAGES as $k => $label): $cards = $by[$k] ?? []; ?>
    <section class="col" data-stage="<?= $k ?>" aria-label="<?= e($label) ?>">
      <header><b><span><?= e($label) ?></span><span data-cnt><?= count($cards) ?></span></b>
        <?php if ($k === 'revisao'): ?><span class="muted small">aprovam: superadmin e admins</span><?php endif; ?>
        <?php if ($k === 'agendado'): ?><span class="muted small">só aprovadas e autorizadas</span><?php endif; ?>
        <?php if ($k === 'publicado'): ?><span class="muted small">últimos 31 dias</span><?php endif; ?></header>
      <div class="cards">
        <?php foreach ($cards as $p): ?>
          <div class="mwrap" data-card='<?= mkt_card_data($p, $postsBy[(int)$p['id']] ?? []) ?>'><?= mkt_card_html($p, $canEdit) ?>
            <?php if ($canEdit && $k !== 'publicado'): ?>
            <form method="post" action="/admin/mkt_api.php" class="dmove"><?= csrf_field() ?><input type="hidden" name="a" value="move"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="back" value="/admin/marketing.php?t=producao">
              <select name="stage" class="mini" aria-label="Mover «<?= e($p['title']) ?>» para"><?php foreach (MKT_STAGES as $o => $ol): if (in_array($o, ['agendado', 'publicado'], true) && $o !== $k) continue; ?><option value="<?= $o ?>" <?= $o === $k ? 'selected' : '' ?>><?= e($ol) ?></option><?php endforeach; ?></select>
              <noscript><button class="btn sm" type="submit">Mover</button></noscript></form>
            <?php endif; ?></div>
        <?php endforeach; ?>
        <?php if (!$cards && $k === 'aprovada'): ?><p class="muted small mempty">Avance ideias em Ideias para começar.</p><?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</div>
<?php if ($canEdit):
    $open = mkt_pieces_where($pdo, "p.status = 'producao' AND p.stage <> 'publicado'", [], 'p.title'); ?>
<?= mkt_schedule_modal_html(null, [], '/admin/marketing.php?t=producao') ?>
<?= mkt_publish_modal_html(null, [], '/admin/marketing.php?t=producao') ?>
<div class="mdl" id="mdl-shoot" role="dialog" aria-modal="true" aria-labelledby="mdl-shoot-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card" method="post" action="/admin/mkt_api.php" data-mkt-form data-reload>
    <?= csrf_field() ?><input type="hidden" name="a" value="shoot_save"><input type="hidden" name="id" value=""><input type="hidden" name="back" value="/admin/marketing.php?t=producao">
    <h3 id="mdl-shoot-t"><?= icon('camera') ?> Dia de gravação</h3>
    <p class="muted">Junte várias peças num dia: a lista de planos sai dos guiões e o material dos modelos.</p>
    <div class="grid">
      <div class="field"><label for="ms-d">Dia</label><input type="date" id="ms-d" name="day" required></div>
      <div class="field"><label for="ms-t">Título</label><input type="text" id="ms-t" name="title" maxlength="160" placeholder="Ex.: Estúdio — employer branding"></div>
      <div class="field wide"><label for="ms-p">Local</label><input type="text" id="ms-p" name="place" maxlength="160"></div>
      <div class="field wide"><label>Peças</label><div class="mshoot-pick">
        <?php foreach ($open as $x): ?><label class="check"><input type="checkbox" name="pieces[]" value="<?= (int)$x['id'] ?>"> <?= e($x['title']) ?> <span class="muted small"><?= e(MKT_STAGES[$x['stage']]) ?></span></label><?php endforeach; ?>
        <?php if (!$open): ?><span class="muted small">Sem peças em Produção.</span><?php endif; ?></div></div>
      <div class="field wide"><label for="ms-n">Notas</label><textarea id="ms-n" name="notes" rows="2" maxlength="4000"></textarea></div>
    </div>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">Guardar</button></div>
  </form>
</div>
<?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
elseif ($tab === 'calendario'):
    $mode = ($_GET['v'] ?? 'semana') === 'mes' ? 'mes' : 'semana';
    $d = (string)($_GET['d'] ?? today_lisbon());
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $d = today_lisbon();
    $day = new DateTimeImmutable($d);
    if ($mode === 'semana') {
        $start = $day->modify('monday this week');
        $weeks = [$start];
        $prev = $start->modify('-7 days'); $next = $start->modify('+7 days');
        $title = 'Semana de ' . $start->format('d/m') . ' a ' . $start->modify('+6 days')->format('d/m/Y');
        $mStart = $start->format('Y-m-01');
    } else {
        $first = $day->modify('first day of this month');
        $start = $first->modify('monday this week');
        $weeks = [];
        for ($w = $start; $w <= $first->modify('last day of this month'); $w = $w->modify('+7 days')) $weeks[] = $w;
        $prev = $first->modify('-1 month'); $next = $first->modify('+1 month');
        $title = ucfirst(month_name((int)$first->format('n'))) . ' de ' . $first->format('Y');
        $mStart = $first->format('Y-m-d');
    }
    $from = $weeks[0]->format('Y-m-d');
    $to = end($weeks)->modify('+7 days')->format('Y-m-d');
    $st = $pdo->prepare(
        "SELECT mp.*, p.title, p.pillar_id, p.review_state, p.stage, pl.color AS pillar_color, pl.name AS pillar_name
           FROM mkt_posts mp JOIN mkt_pieces p ON p.id = mp.piece_id LEFT JOIN mkt_pillars pl ON pl.id = p.pillar_id
          WHERE (mp.scheduled_at >= ? AND mp.scheduled_at < ?) OR (mp.published_at >= ? AND mp.published_at < ?)");
    $st->execute([$from . ' 00:00:00', $to . ' 00:00:00', $from . ' 00:00:00', $to . ' 00:00:00']);
    $cells = [];
    foreach ($st->fetchAll() as $x) {
        $when = $x['published_at'] ? lisbon_fmt(strtotime((string)$x['published_at']), 'Y-m-d H:i') : substr((string)$x['scheduled_at'], 0, 16);
        $x['when'] = $when;
        $cells[substr($when, 0, 10)][$x['network']][] = $x;
    }
    $targets = mkt_targets($pdo);
    // Prontas a agendar: aprovadas, com redes ainda sem data.
    $ready = [];
    foreach (mkt_pieces_where($pdo, "p.status = 'producao' AND p.review_state = 'approved' AND p.stage IN ('revisao','agendado')", [], 'p.reviewed_at') as $p) {
        $pp = mkt_posts($pdo, (int)$p['id']);
        $free = array_values(array_filter(mkt_nets($p), fn($n) => empty($pp[$n]['scheduled_at']) && empty($pp[$n]['published_at'])));
        if ($free) $ready[] = ['p' => $p, 'free' => $free, 'posts' => $pp];
    }
    // Equilíbrio de pilares no mês do calendário.
    $mEnd = (new DateTimeImmutable($mStart))->modify('+1 month')->format('Y-m-d');
    $bal = $pdo->prepare("SELECT p.pillar_id, COUNT(*) FROM mkt_posts mp JOIN mkt_pieces p ON p.id = mp.piece_id
                           WHERE COALESCE(mp.published_at, mp.scheduled_at) >= ? AND COALESCE(mp.published_at, mp.scheduled_at) < ? GROUP BY p.pillar_id");
    $bal->execute([$mStart . ' 00:00:00', $mEnd . ' 00:00:00']);
    $balance = array_map('intval', $bal->fetchAll(PDO::FETCH_KEY_PAIR));
    $balTotal = array_sum($balance);
    $nav = fn(DateTimeImmutable $x, ?string $m = null) => '/admin/marketing.php?' . http_build_query(['t' => 'calendario', 'v' => ($m ?? $mode) === 'mes' ? 'mes' : null, 'd' => $x->format('Y-m-d')]);
    $wd = ['SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB', 'DOM'];
?>
<div class="toolbar mcal-bar">
  <nav class="views" aria-label="Vista"><a href="<?= e($nav($day, 'semana')) ?>"<?= $mode === 'semana' ? ' class="on" aria-current="true"' : '' ?>>Semana</a><a href="<?= e($nav($day, 'mes')) ?>"<?= $mode === 'mes' ? ' class="on" aria-current="true"' : '' ?>>Mês</a></nav>
  <div class="mcal-nav"><?= icon_button('chev-left', $mode === 'mes' ? 'Mês anterior' : 'Semana anterior', ['href' => $nav($prev)]) ?>
    <b><?= e($title) ?></b><?= icon_button('chev-right', $mode === 'mes' ? 'Mês seguinte' : 'Semana seguinte', ['href' => $nav($next)]) ?>
    <a class="btn ghost sm" href="<?= e($nav(new DateTimeImmutable(today_lisbon()))) ?>">Hoje</a></div>
</div>

<div class="mcal-wrap">
<div class="mcal-main">
  <?php foreach ($weeks as $w): $counts = ['ig' => 0, 'li' => 0, 'x' => 0];
      for ($i = 0; $i < 7; $i++) foreach ($counts as $n => $_) $counts[$n] += count($cells[$w->modify("+$i days")->format('Y-m-d')][$n] ?? []);
      $under = array_filter($counts, fn($c, $n) => $c < $targets[$n][0], ARRAY_FILTER_USE_BOTH); ?>
  <div class="mcal-scroll"><div class="mcal<?= $under ? ' under' : '' ?>" role="group" aria-label="Semana de <?= e($w->format('d/m')) ?><?= $under ? ' — abaixo do alvo' : '' ?>">
    <span class="d"></span>
    <?php for ($i = 0; $i < 7; $i++): $x = $w->modify("+$i days"); $out = $mode === 'mes' && $x->format('m') !== (new DateTimeImmutable($mStart))->format('m'); ?>
      <span class="d<?= $x->format('Y-m-d') === today_lisbon() ? ' is-today' : '' ?><?= $out ? ' out' : '' ?>"><?= $wd[$i] ?> <b><?= $x->format('d') ?></b></span>
    <?php endfor; ?>
    <span class="tgt d">ALVO</span>
    <?php foreach (MKT_NETWORKS as $n => [$label]): $t = $targets[$n]; $c = $counts[$n];
        $cls = $c >= $t[0] ? ($c > $t[1] ? 'over' : 'ok') : ($c >= max(1, (int)floor($t[0] / 2)) ? 'near' : 'low'); ?>
      <span class="rn"><?= mkt_net_chip($n) ?> <span class="hide-sm"><?= e($label) ?></span></span>
      <?php for ($i = 0; $i < 7; $i++): $date = $w->modify("+$i days")->format('Y-m-d'); ?>
        <div class="cell" data-day="<?= $date ?>" data-net="<?= $n ?>"<?= $date < today_lisbon() ? ' data-past' : '' ?>>
          <?php foreach ($cells[$date][$n] ?? [] as $x): $pub = (bool)$x['published_at']; ?>
            <a class="mpost<?= $pub ? ' pub' : '' ?>" href="/admin/marketing_peca.php?id=<?= (int)$x['piece_id'] ?>" data-panel="mkt:<?= (int)$x['piece_id'] ?>"
               <?= $canEdit && !$pub ? 'draggable="true" data-move="' . (int)$x['piece_id'] . '"' : '' ?> data-net="<?= $n ?>" data-time="<?= e(substr($x['when'], 11, 5)) ?>"
               data-tip="<?= e(($pub ? 'Publicada · ' : 'Agendada · ') . substr($x['when'], 11, 5) . ' · ' . $x['title'] . ($x['pillar_name'] ? ' · ' . $x['pillar_name'] : '')) ?>">
              <i class="dot p-<?= e((string)($x['pillar_color'] ?: 'neutral')) ?>"></i><span><?= e(substr($x['when'], 11, 5)) ?> <?= e(shorten((string)$x['title'], 22)) ?></span></a>
          <?php endforeach; ?>
        </div>
      <?php endfor; ?>
      <span class="tgt t-<?= $cls ?>" data-tip="<?= e($label) ?>: <?= $c ?> esta semana · alvo <?= e(mkt_target_label($t)) ?>"><?= $c ?>/<?= e(mkt_target_label($t)) ?></span>
    <?php endforeach; ?>
  </div></div>
  <?php endforeach; ?>
  <p class="muted small">Cores = pilares. Semanas abaixo do alvo ficam destacadas (alvo por semana em Definições: Instagram <?= e(mkt_target_label($targets['ig'])) ?> · LinkedIn <?= e(mkt_target_label($targets['li'])) ?> · X <?= e(mkt_target_label($targets['x'])) ?>).
    <?= $canEdit ? 'Arraste uma peça aprovada para um dia = agendar; arraste uma agendada para outro dia = mudar a data.' : '' ?></p>
</div>
<aside class="mcal-side">
  <section class="lv-blk"><h4><?= icon('check') ?> Prontas a agendar</h4>
    <?php if (!$ready): ?><p class="muted small">Nenhuma peça aprovada à espera de data. As peças aprovadas na Revisão aparecem aqui.</p><?php endif; ?>
    <?php foreach ($ready as $r): $p = $r['p']; ?>
      <div class="mready" data-card='<?= mkt_card_data($p, $r['posts']) ?>'>
        <a href="/admin/marketing_peca.php?id=<?= (int)$p['id'] ?>" data-panel="mkt:<?= (int)$p['id'] ?>"><?= mkt_pill($p) ?> <?= e($p['title']) ?></a>
        <?= mkt_deal_line($p) ?>
        <div class="mnets"><?php foreach ($r['free'] as $n): ?><span class="mnet n-<?= $n ?> drag"<?= $canEdit ? ' draggable="true" data-ready="' . (int)$p['id'] . '" data-net="' . $n . '"' : '' ?> data-tip="Arraste para a linha do <?= e(MKT_NETWORKS[$n][0]) ?>"><?= e(MKT_NETWORKS[$n][1]) ?></span><?php endforeach; ?>
          <?php if ($canEdit): ?><button type="button" class="lnk small" data-sched-open>Agendar…</button><?php endif; ?></div>
      </div>
    <?php endforeach; ?>
  </section>
  <section class="lv-blk"><h4><?= icon('trend') ?> Equilíbrio de pilares no mês</h4>
    <?php if (!$balTotal): ?><p class="muted small">Ainda sem publicações neste mês.</p><?php else: ?>
      <div class="mbal" role="img" aria-label="Distribuição das publicações do mês por pilar">
        <?php foreach ($pillars as $id => $pl): $c = $balance[$id] ?? 0; if (!$c) continue; ?><i class="p-<?= e($pl['color']) ?>" style="flex:<?= $c ?>" data-tip="<?= e($pl['name']) ?>: <?= $c ?>"></i><?php endforeach; ?>
      </div>
      <ul class="mbal-l"><?php foreach ($pillars as $id => $pl): $c = $balance[$id] ?? 0; $share = $c / $balTotal; ?>
        <li class="<?= !$c ? 'neg' : '' ?>"><i class="dot p-<?= e($pl['color']) ?>"></i><span class="nm"><?= e($pl['name']) ?><?= !$c ? ' <small>· sem publicações</small>' : '' ?></span><span class="mono"><?= $c ?> · <?= round($share * 100) ?>%</span></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
</aside>
</div>
<?php if ($canEdit): ?><?= mkt_schedule_modal_html(null, [], $here) ?><?php endif; ?>

<?php
// ════════════════════════════════════════════════════════════
else: // desempenho
    $ym = (string)($_GET['m'] ?? lisbon_fmt(time(), 'Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = lisbon_fmt(time(), 'Y-m');
    $rows = mkt_month_posts($pdo, $ym);
    $s = mkt_month_stats($pdo, $ym, $rows);
    $money = can('financeiro', PERM_VIEW);
    $byPillar = mkt_avg_by($rows, fn($r) => (string)($r['pillar_name'] ?? 'Sem pilar'));
    $byFormat = mkt_avg_by($rows, fn($r) => MKT_FORMATS[$r['format']] ?? 'Sem formato');
    $byNet = mkt_avg_by($rows, fn($r) => MKT_NETWORKS[$r['network']][0]);
    $wdn = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
    $byHour = mkt_avg_by($rows, fn($r) => $wdn[(int)lisbon_fmt(strtotime((string)$r['published_at']), 'w')] . ' ' . lisbon_fmt(strtotime((string)$r['published_at']), 'G') . 'h');
    $best = fn(array $a) => $a ? array_key_first($a) : '—';
    $summary = $pdo->prepare('SELECT s.*, u.name AS who FROM mkt_summaries s LEFT JOIN users u ON u.id = s.created_by WHERE month = ?');
    $summary->execute([$ym]);
    $summary = $summary->fetch() ?: null;
    $m0 = new DateTimeImmutable($ym . '-01');
    $mUrl = fn(DateTimeImmutable $x) => '/admin/marketing.php?t=desempenho&m=' . $x->format('Y-m');
    require_once __DIR__ . '/inc/ai.php';
    $aiReady = ai_config() !== null;
    $avgTable = function (string $title, array $a): string {
        if (!$a) return '';
        $h = '<div class="tablewrap"><table class="mavg"><caption>' . e($title) . '</caption><thead><tr><th></th><th class="num">Publ.</th><th class="num">Interações (média)</th></tr></thead><tbody>';
        foreach (array_slice($a, 0, 6, true) as $k => $v) $h .= '<tr data-tip="Alcance: ' . number_format($v['reach'], 0, ',', ' ') . '"><td>' . e((string)$k) . '</td><td class="num">' . $v['n'] . '</td><td class="num mono">' . number_format($v['avg'], 0, ',', ' ') . '</td></tr>';
        return $h . '</tbody></table></div>';
    };
    $k = fn(int $n) => $n >= 10000 ? number_format($n / 1000, 1, ',', '') . 'k' : number_format($n, 0, ',', ' ');
?>
<div class="toolbar">
  <div class="mcal-nav"><?= icon_button('chev-left', 'Mês anterior', ['href' => $mUrl($m0->modify('-1 month'))]) ?>
    <b><?= e(ucfirst(month_name((int)$m0->format('n'))) . ' de ' . $m0->format('Y')) ?></b><?= icon_button('chev-right', 'Mês seguinte', ['href' => $mUrl($m0->modify('+1 month'))]) ?></div>
</div>
<div class="stats">
  <div class="stat hero"><div class="k">Alcance (mês)</div><div class="v"><?= $k($s['reach']) ?></div><div class="n"><?= $s['posts'] ?> publicaç<?= $s['posts'] === 1 ? 'ão' : 'ões' ?> · <?= $k($s['inter']) ?> interações</div></div>
  <div class="stat"><div class="k">Cliques</div><div class="v"><?= $k($s['clicks']) ?></div><div class="n">links com rastreio + cliques na rede</div></div>
  <div class="stat"><div class="k">Leads</div><div class="v"><?= $s['leads'] ?></div><div class="n">do formulário do site, vindas de uma publicação</div></div>
  <div class="stat"><div class="k">Negócios<?= $money ? ' · €' : '' ?></div><div class="v"><?= $s['won'] ?><?= $money ? ' · ' . e(eur0($s['value'])) : '' ?></div><div class="n">leads do conteúdo que chegaram a Kickoff</div></div>
</div>
<div class="stats">
  <div class="stat"><div class="k">Talento (employer)</div><div class="v"><?= $s['talent'] ?></div><div class="n">candidaturas e contactos de talento · <span class="muted" data-tip="Precisa de uma página ou formulário de carreiras no site (a decidir com a aba Equipa/site). A origem «Talento» já está preparada.">página de carreiras pendente</span></div></div>
  <div class="stat"><div class="k">Melhor pilar</div><div class="v small-v"><?= e($best($byPillar)) ?></div><div class="n">por interações por publicação</div></div>
  <div class="stat"><div class="k">Melhor rede</div><div class="v small-v"><?= e($best($byNet)) ?></div><div class="n">por interações por publicação</div></div>
  <div class="stat"><div class="k">Melhor dia e hora</div><div class="v small-v"><?= e($best($byHour)) ?></div><div class="n">hora de Lisboa</div></div>
</div>

<section class="lv-blk msum" data-summary>
  <h4><?= icon('sparkle') ?> Resumo do mês <span class="h-sub">escrito pela IA a partir dos números</span></h4>
  <div data-summary-body><?= $summary ? '<p>' . nl2br(e($summary['body'])) . '</p><p class="muted small">' . e(($summary['who'] ?? '') . ' · ' . lisbon_fmt(strtotime((string)$summary['created_at']), 'd/m H:i')) . '</p>' : '<p class="muted">Ainda sem resumo para este mês.</p>' ?></div>
  <?php if ($canEdit && $rows): ?><button type="button" class="btn ghost sm" data-summary-go="<?= e($ym) ?>"<?= $aiReady ? '' : ' disabled data-tip="A IA ainda não está configurada neste servidor"' ?>><?= icon('sparkle') ?> <?= $summary ? 'Escrever de novo' : 'Escrever resumo' ?></button><?php endif; ?>
</section>

<?php if ($s['no_metrics']): ?><p class="flash err" style="display:block"><?= icon('alert') ?> <?= $s['no_metrics'] ?> publicaç<?= $s['no_metrics'] === 1 ? 'ão' : 'ões' ?> com mais de 7 dias ainda sem métricas.</p><?php endif; ?>

<h2 class="h-sm">Por publicação</h2>
<div class="tablewrap">
<table class="mperf">
  <thead><tr><th>Peça</th><th>Rede</th><th class="hide-sm">Publicada</th><th class="num">Alcance</th><th class="num">Interações</th><th class="num hide-sm">Guardados</th><th class="num hide-sm">Partilhas</th><th class="num">Cliques</th><th class="num">Leads</th><th></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="10" class="empty">Sem publicações neste mês.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $late = $r['metrics_at'] === null && strtotime((string)$r['published_at']) < time() - 7 * 86400; ?>
    <tr data-panel="mkt:<?= (int)$r['piece_id'] ?>">
      <td><a class="b" href="/admin/marketing_peca.php?id=<?= (int)$r['piece_id'] ?>"><?= e($r['title']) ?></a> <span class="mpil p-<?= e((string)($r['pillar_color'] ?: 'neutral')) ?>"><?= e((string)($r['pillar_name'] ?? '')) ?></span></td>
      <td><?= mkt_net_chip($r['network']) ?><?= $r['url'] ? ' <a href="' . e($r['url']) . '" target="_blank" rel="noopener noreferrer" class="lnk small" data-tip="Abrir a publicação">' . icon('external') . '</a>' : '' ?></td>
      <td class="hide-sm mono"><?= e(lisbon_fmt(strtotime((string)$r['published_at']), 'd/m H:i')) ?></td>
      <td class="num mono"><?= $r['reach'] !== null ? $k((int)$r['reach']) : '<span class="muted">—</span>' ?></td>
      <td class="num mono"><?= $r['interactions'] !== null ? $k((int)$r['interactions']) : '<span class="muted">—</span>' ?></td>
      <td class="num mono hide-sm"><?= $r['saves'] ?? '<span class="muted">—</span>' ?></td>
      <td class="num mono hide-sm"><?= $r['shares'] ?? '<span class="muted">—</span>' ?></td>
      <td class="num mono" data-tip="<?= (int)$r['tracked'] ?> no link com rastreio + <?= (int)$r['clicks'] ?> na rede"><?= (int)$r['tracked'] + (int)$r['clicks'] ?></td>
      <td class="num mono"><?= (int)$r['leads'] ?: '<span class="muted">0</span>' ?></td>
      <td class="num"><?php if ($canEdit): ?><button type="button" class="btn <?= $late ? '' : 'ghost ' ?>sm" data-metrics='<?= e(json_encode(['id' => (int)$r['piece_id'], 'net' => $r['network'], 'title' => $r['title'] . ' · ' . MKT_NETWORKS[$r['network']][0],
              'v' => ['reach' => $r['reach'], 'interactions' => $r['interactions'], 'saves' => $r['saves'], 'shares' => $r['shares'], 'clicks' => $r['clicks']]], JSON_UNESCAPED_UNICODE)) ?>'><?= $r['metrics_at'] ? 'Métricas' : ($late ? 'Pôr métricas' : 'Métricas') ?></button><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="grid2 mavgs">
  <?= $avgTable('Por pilar', $byPillar) ?><?= $avgTable('Por formato', $byFormat) ?><?= $avgTable('Por rede', $byNet) ?><?= $avgTable('Por dia e hora', $byHour) ?>
</div>
<?php if ($rows && !$byNet): ?><p class="muted small">As médias aparecem quando houver métricas registadas.</p><?php endif; ?>
<p class="muted small">Cada publicação tem um link com rastreio (<span class="mono"><?= e(mkt_site_base()) ?>/go.php?c=…</span>): as leads do formulário do site vindas dele entram com a origem «rede · peça» e medem conteúdo → lead → negócio<?= $money ? ' → €' : '' ?>.
  Fase 2 (depois): métricas e publicação automáticas pelas APIs do Instagram, LinkedIn e X.</p>
<?php if ($canEdit): ?><?= mkt_metrics_modal_html($here) ?><?php endif; ?>
<?php endif; ?>

<script src="<?= asset_url('marketing.js') ?>" defer></script>
<?php render_footer();
