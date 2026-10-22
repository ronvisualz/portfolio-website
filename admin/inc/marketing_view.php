<?php
/**
 * topper. admin — peças de ecrã da aba Marketing (§16), partilhadas pelos
 * separadores (marketing.php), pela página da peça (marketing_peca.php) e
 * pelo «+ Ideia» que existe em todas as páginas (layout.php, atalho I).
 * Os formulários funcionam sem JavaScript (mkt_api.php volta a «back»);
 * com JavaScript, marketing.js/app.js gravam sem recarregar e dão «Anular».
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/marketing.php';

function mkt_opts(array $list, $current, string $empty = ''): string {
    $h = $empty !== '' ? '<option value="">' . e($empty) . '</option>' : '';
    foreach ($list as $k => $label) $h .= '<option value="' . e((string)$k) . '"' . ((string)$k === (string)$current ? ' selected' : '') . '>' . e($label) . '</option>';
    return $h;
}

function mkt_pillar_opts(PDO $pdo, $current): string {
    $list = [];
    foreach (mkt_pillars($pdo) as $id => $p) $list[$id] = $p['name'];
    return mkt_opts($list, $current, 'Sem pilar');
}

function mkt_template_opts($current): string {
    $list = [];
    foreach (MKT_TEMPLATES as $k => $t) $list[$k] = $t[0];
    return mkt_opts($list, $current, 'Sem modelo');
}

function mkt_net_checks(string $name, array $on, string $idp): string {
    $h = '<div class="chips-check">';
    foreach (MKT_NETWORKS as $k => [$label]) {
        $h .= '<label class="rb"><input type="checkbox" name="' . e($name) . '[]" value="' . $k . '"' . (in_array($k, $on, true) ? ' checked' : '') . ' id="' . e($idp . $k) . '"><span>' . e($label) . '</span></label>';
    }
    return $h . '</div>';
}

/** O «+ Ideia» (§16.3): só o texto é obrigatório. Em todas as páginas, com o atalho I. */
function mkt_idea_modal_html(PDO $pdo, string $back): string {
    return '<div class="mdl" id="mdl-idea" data-from="[data-new-idea]" role="dialog" aria-modal="true" aria-labelledby="mdl-idea-t" hidden>'
         . '<div class="mdl-back" data-mdl-close></div>'
         . '<form class="mdl-card" method="post" action="/admin/mkt_api.php" autocomplete="off" data-idea-form>'
         . csrf_field() . '<input type="hidden" name="a" value="idea_new"><input type="hidden" name="back" value="' . e($back) . '">'
         . '<h3 id="mdl-idea-t">' . icon('bulb') . ' Nova ideia de conteúdo</h3>'
         . '<p class="muted">Só o texto é obrigatório. Fica em Marketing › Ideias para a triagem.</p>'
         . '<div class="grid">'
         . '<div class="field wide"><label for="mi-idea">A ideia</label><textarea id="mi-idea" name="idea" rows="3" maxlength="6000" required placeholder="Ex.: breakdown do plano da garrafa — do plate ao render final"></textarea></div>'
         . '<div class="field"><label for="mi-pl">Pilar</label><select id="mi-pl" name="pillar_id">' . mkt_pillar_opts($pdo, '') . '</select></div>'
         . '<div class="field"><label for="mi-fo">Formato</label><select id="mi-fo" name="format">' . mkt_opts(MKT_FORMATS, '', 'Por decidir') . '</select></div>'
         . '<div class="field"><label for="mi-tp">Modelo</label><select id="mi-tp" name="template">' . mkt_template_opts('') . '</select></div>'
         . '<div class="field"><label>Redes</label>' . mkt_net_checks('networks', [], 'mi-n-') . '</div>'
         . '</div>'
         . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span>'
         . '<button class="btn" type="submit">Guardar ideia</button></div>'
         . '</form></div>';
}

/** Agendar (data e hora por rede). Preenchido pelo marketing.js no kanban e no Calendário; na página da peça vem já preenchido. */
function mkt_schedule_modal_html(?array $p = null, array $posts = [], string $back = ''): string {
    $rows = '';
    foreach (MKT_NETWORKS as $n => [$label]) {
        $on = $p ? in_array($n, mkt_nets($p), true) : true;
        $at = $posts[$n]['scheduled_at'] ?? '';
        $pub = !empty($posts[$n]['published_at']);
        $rows .= '<div class="msched-row" data-net="' . $n . '"' . ($on ? '' : ' hidden') . '>'
               . mkt_net_chip($n) . '<b>' . e($label) . '</b>'
               . ($pub ? '<span class="pos small">já publicada</span>'
                      : '<input type="date" name="day[' . $n . ']" value="' . e($at ? substr($at, 0, 10) : '') . '" aria-label="Dia no ' . e($label) . '">'
                      . '<input type="time" name="time[' . $n . ']" value="' . e($at ? substr($at, 11, 5) : '10:00') . '" step="300" aria-label="Hora no ' . e($label) . '">')
               . '</div>';
    }
    return '<div class="mdl" id="mdl-msched" role="dialog" aria-modal="true" aria-labelledby="mdl-msched-t" hidden>'
         . '<div class="mdl-back" data-mdl-close></div>'
         . '<form class="mdl-card" method="post" action="/admin/mkt_api.php" data-mkt-form>'
         . csrf_field() . '<input type="hidden" name="a" value="schedule"><input type="hidden" name="id" value="' . ($p ? (int)$p['id'] : '') . '"><input type="hidden" name="back" value="' . e($back) . '">'
         . '<h3 id="mdl-msched-t">' . icon('calendar') . ' Agendar <span data-title>' . e($p['title'] ?? '') . '</span></h3>'
         . '<p class="muted">Quem publica recebe o lembrete nesse dia (Tarefas). Deixe o dia vazio para tirar a data. Só peças aprovadas e, se ligadas a um negócio, com autorização de divulgação nessa data.</p>'
         . '<div class="msched">' . $rows . '</div>'
         . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">Agendar</button></div>'
         . '</form></div>';
}

/** Marcar como publicada (fase 1): o link da publicação de cada rede. */
function mkt_publish_modal_html(?array $p = null, array $posts = [], string $back = ''): string {
    $rows = '';
    foreach (MKT_NETWORKS as $n => [$label]) {
        $on = $p ? in_array($n, mkt_nets($p), true) : true;
        $pub = $posts[$n]['published_at'] ?? null;
        $rows .= '<div class="field wide msched-row" data-net="' . $n . '"' . ($on ? '' : ' hidden') . '><label for="mp-' . $n . '">' . mkt_net_chip($n) . ' Link da publicação no ' . e($label) . '</label>'
               . '<input type="url" id="mp-' . $n . '" name="url[' . $n . ']" maxlength="500" placeholder="https://…" value="' . e((string)($posts[$n]['url'] ?? '')) . '"' . ($pub ? ' data-done' : '') . '></div>';
    }
    return '<div class="mdl" id="mdl-mpub" role="dialog" aria-modal="true" aria-labelledby="mdl-mpub-t" hidden>'
         . '<div class="mdl-back" data-mdl-close></div>'
         . '<form class="mdl-card" method="post" action="/admin/mkt_api.php" data-mkt-form>'
         . csrf_field() . '<input type="hidden" name="a" value="publish"><input type="hidden" name="id" value="' . ($p ? (int)$p['id'] : '') . '"><input type="hidden" name="back" value="' . e($back) . '">'
         . '<h3 id="mdl-mpub-t">' . icon('won') . ' Marcar como publicada</h3>'
         . '<p class="muted">Publicação assistida: publique na rede (legenda e link com rastreio copiados na peça) e cole aqui o link. As métricas ficam lembradas para daqui a 7 dias.</p>'
         . '<div class="grid">' . $rows . '</div>'
         . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">Guardar</button></div>'
         . '</form></div>';
}

/** Métricas de uma publicação (à mão na fase 1). */
function mkt_metrics_modal_html(string $back = ''): string {
    $f = '';
    foreach (['reach' => 'Alcance', 'interactions' => 'Interações', 'saves' => 'Guardados', 'shares' => 'Partilhas', 'clicks' => 'Cliques (na rede)'] as $k => $l) {
        $f .= '<div class="field"><label for="mm-' . $k . '">' . e($l) . '</label><input type="text" inputmode="numeric" id="mm-' . $k . '" name="' . $k . '" maxlength="9" pattern="[0-9 .]*"></div>';
    }
    return '<div class="mdl" id="mdl-mmet" role="dialog" aria-modal="true" aria-labelledby="mdl-mmet-t" hidden>'
         . '<div class="mdl-back" data-mdl-close></div>'
         . '<form class="mdl-card" method="post" action="/admin/mkt_api.php" data-mkt-form>'
         . csrf_field() . '<input type="hidden" name="a" value="metrics"><input type="hidden" name="id" value=""><input type="hidden" name="network" value=""><input type="hidden" name="back" value="' . e($back) . '">'
         . '<h3 id="mdl-mmet-t">' . icon('trend') . ' Métricas <span data-title></span></h3>'
         . '<p class="muted">Os números da rede 7 dias depois de publicar. Os cliques no link com rastreio contam sozinhos.</p>'
         . '<div class="grid">' . $f . '</div>'
         . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">Guardar métricas</button></div>'
         . '</form></div>';
}

/** Pedir alterações (Revisão): o comentário é obrigatório. */
function mkt_changes_modal_html(array $p, string $back): string {
    return '<div class="mdl" id="mdl-mchg" role="dialog" aria-modal="true" aria-labelledby="mdl-mchg-t" hidden>'
         . '<div class="mdl-back" data-mdl-close></div>'
         . '<form class="mdl-card" method="post" action="/admin/mkt_api.php" data-mkt-form>'
         . csrf_field() . '<input type="hidden" name="a" value="review"><input type="hidden" name="decision" value="changes"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
         . '<h3 id="mdl-mchg-t">' . icon('bubble') . ' Pedir alterações</h3>'
         . '<p class="muted">A peça volta a Edição e o responsável recebe uma tarefa com o seu comentário.</p>'
         . '<div class="field wide"><label for="mc-n">O que é preciso mudar</label><textarea id="mc-n" name="note" rows="4" maxlength="2000" required></textarea></div>'
         . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">Pedir alterações</button></div>'
         . '</form></div>';
}

/** Os dados do kanban/calendário que o marketing.js precisa para preencher os formulários. */
function mkt_card_data(array $p, array $posts): string {
    $s = [];
    foreach ($posts as $n => $x) $s[$n] = ['at' => $x['scheduled_at'] ? substr((string)$x['scheduled_at'], 0, 16) : '', 'pub' => (bool)$x['published_at'], 'url' => (string)$x['url']];
    return e(json_encode(['id' => (int)$p['id'], 'title' => (string)$p['title'], 'nets' => mkt_nets($p), 'posts' => $s], JSON_UNESCAPED_UNICODE));
}

/** Uma versão por rede no Estúdio: gancho, texto, CTA, hashtags, com contagem de caracteres. */
function mkt_version_card(string $n, ?array $v, array $p, ?array $post, bool $edit): string {
    [$label] = MKT_NETWORKS[$n];
    $ro = $edit ? '' : ' readonly';
    $kind = MKT_FORMATS[$p['format']] ?? 'Post';
    if ($n === 'x' && mkt_thread_parts((string)($v['body'] ?? '')) && count(mkt_thread_parts((string)$v['body'])) > 1) $kind = 'Thread';
    $max = $n === 'x' ? MKT_X_LIMIT : ($n === 'ig' ? 2200 : 3000);
    $h = '<section class="mout" data-net="' . $n . '" data-max="' . $max . '">'
       . '<header>' . mkt_net_chip($n) . '<b>' . e(mb_strtoupper($label) . ' · ' . mb_strtoupper($kind)) . '</b><span class="mcount mono" data-count aria-live="polite"></span></header>'
       . '<label class="sr" for="v-' . $n . '-hook">Gancho no ' . e($label) . '</label>'
       . '<input type="text" id="v-' . $n . '-hook" data-field="hook" maxlength="300" placeholder="Gancho (primeira linha)" value="' . e((string)($v['hook'] ?? '')) . '"' . $ro . '>'
       . '<label class="sr" for="v-' . $n . '-body">Texto no ' . e($label) . '</label>'
       . '<textarea id="v-' . $n . '-body" data-field="body" rows="' . ($n === 'li' ? 9 : 7) . '" maxlength="8000" placeholder="' . ($n === 'x' ? 'Post até 280 caracteres. Numa thread, separe os posts com uma linha ---' : 'Texto') . '"' . $ro . '>' . e((string)($v['body'] ?? '')) . '</textarea>'
       . '<label class="sr" for="v-' . $n . '-cta">CTA no ' . e($label) . '</label>'
       . '<input type="text" id="v-' . $n . '-cta" data-field="cta" maxlength="300" placeholder="CTA (chamada à ação)" value="' . e((string)($v['cta'] ?? '')) . '"' . $ro . '>'
       . '<label class="sr" for="v-' . $n . '-tags">Hashtags no ' . e($label) . '</label>'
       . '<input type="text" id="v-' . $n . '-tags" data-field="hashtags" maxlength="500" placeholder="#hashtags" value="' . e((string)($v['hashtags'] ?? '')) . '"' . $ro . '>';
    if ($post) {
        $h .= '<footer><button type="button" class="btn ghost sm" data-copy="' . e(mkt_caption($v ?? [], $post)) . '" data-tip="Gancho, texto, CTA, link com rastreio e hashtags">' . icon('copy') . ' Copiar legenda</button>'
            . '<span class="muted small mono" data-tip="Link com rastreio desta publicação">' . e(mkt_track_url((string)$post['code'])) . '</span></footer>';
    }
    return $h . '</section>';
}
