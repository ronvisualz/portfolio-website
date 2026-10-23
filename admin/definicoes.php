<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/settings_def.php';
require_once __DIR__ . '/inc/undo.php';
$user = require_login();

// Definições (§20): um separador por aba com campos editáveis. Grava sozinho (sem botão
// Gravar), com «Anular»; avisa antes de mexer em dados existentes; «Repor valor de origem»
// em cada campo; registo de alterações com «Repor» por linha; ⓘ em cada definição.
// Só superadmin e admins (verificado aqui e em cada gravação, no servidor).
// Utilizadores, papéis, permissões, cargos e departamentos continuam na aba Equipa.

if (!is_admin()) {
    http_response_code(403);
    render_header('Definições', 'definicoes');
    echo '<h1>Definições</h1><p class="muted">Só superadmin e admins mudam as Definições. As suas preferências estão no <a href="/admin/conta.php">Perfil</a>.</p>';
    render_footer();
    exit;
}

// Modelos de orçamento: apagar (com «Anular»).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['tpl_del'])) {
        $st = $pdo->prepare('SELECT * FROM budget_templates WHERE id = ?');
        $st->execute([(int)$_POST['tpl_del']]);
        if ($t = $st->fetch()) {
            $pdo->prepare('DELETE FROM budget_templates WHERE id = ?')->execute([$t['id']]);
            setting_log($pdo, 'budget_templates', 'Orçamentação · Modelo apagado', $t['name'], '');
            flash('Modelo «' . $t['name'] . '» apagado.', 'ok', undo_push('budget_tpl', $t));
        }
    }
    redirect('/admin/definicoes.php?t=orcamentacao#set-budget_templates');
}

$tab = (string)($_GET['t'] ?? 'geral');
if (!isset(SET_TABS[$tab]) && $tab !== 'registo') $tab = 'geral';

/** ⓘ com a frase da definição. */
function set_ii(string $help): string {
    return '<span class="ii" tabindex="0" role="img" aria-label="Ajuda: ' . e($help) . '" data-tip="' . e($help) . '">i</span>';
}

/** Uma linha: nome e ⓘ · o campo · «Repor (valor de origem)». */
function set_row_html(PDO $pdo, string $key, array $d): string {
    $id = 'set-' . $key;
    $label = '<span class="set-l"><label for="in-' . e($key) . '">' . e($d['label']) . '</label>' . set_ii($d['help']) . '</span>';
    $v = setting_get($pdo, $key);
    $def = (string)($d['default'] ?? '');
    $unit = (string)($d['unit'] ?? '');
    $attrs = ' id="in-' . e($key) . '" data-set="' . e($key) . '" data-was="' . e($v) . '"';
    $field = '';
    switch ($d['type']) {
        case 'int': case 'num':
            $shown = $d['type'] === 'num' ? str_replace('.', ',', $v) : $v;
            $field = '<span class="set-in"><input type="text" inputmode="decimal" value="' . e($shown) . '"' . $attrs . ' autocomplete="off">' . ($unit !== '' ? '<em>' . e($unit) . '</em>' : '') . '</span>';
            break;
        case 'text': case 'email': case 'url':
            $field = '<input type="' . ($d['type'] === 'email' ? 'email' : ($d['type'] === 'url' ? 'url' : 'text')) . '" maxlength="' . (int)($d['max'] ?? 200) . '" value="' . e($v) . '"' . $attrs . ' autocomplete="off">';
            break;
        case 'time':
            $field = '<input type="time" step="900" value="' . e($v) . '"' . $attrs . '>';
            break;
        case 'textarea': case 'list':
            $rows = $d['type'] === 'list' ? min(12, max(3, substr_count($v, "\n") + 2)) : 4;
            $field = '<textarea rows="' . $rows . '"' . ($d['type'] === 'list' ? ' placeholder="Uma por linha"' : '') . ' maxlength="' . (int)($d['max'] ?? 4000) . '"' . $attrs . '>' . e($v) . '</textarea>'
                   . ($d['type'] === 'list' ? '<small class="muted">Uma por linha. Grava ao sair do campo.</small>' : '');
            break;
        case 'select':
            $o = '';
            foreach ($d['opts'] as $k => $l) $o .= '<option value="' . e((string)$k) . '"' . ((string)$k === $v ? ' selected' : '') . '>' . e($l) . '</option>';
            $field = '<select' . $attrs . '>' . $o . '</select>';
            break;
        case 'link':
            $field = '<a class="btn ghost sm" href="' . e($d['href']) . '">' . icon('external') . ' ' . e($d['show']) . '</a>';
            break;
        default:
            $field = set_special_html($pdo, $key, $d);
    }
    $editable = in_array($d['type'], ['int', 'num', 'text', 'email', 'url', 'time', 'textarea', 'list', 'select'], true);
    $reset = $editable ? '<button type="button" class="set-reset" data-reset="' . e($key) . '" data-def="' . e($def) . '"' . ($v === $def ? ' hidden' : '') . ' data-tip="Repor o valor de origem">Repor (' . e(setting_show($d, $def)) . ')</button>' : '';
    $wide = in_array($d['type'], ['textarea', 'list', 'services', 'pillars', 'templates', 'types', 'origins', 'ai', 'cc_ai', 'cc_funnels'], true);
    return '<div class="set-row' . ($wide ? ' wide' : '') . '" id="' . $id . '" data-row="' . e($key) . '">' . $label . '<div class="set-f">' . $field . '</div>' . $reset
         . '<div class="set-warn" hidden role="alert"><p></p><div class="actions"><button type="button" class="btn sm" data-impact-ok>Confirmar</button><button type="button" class="btn ghost sm" data-impact-no>Cancelar</button></div></div></div>';
}

/** Os campos que não são um valor só: tabelas, listas de tipos, estado da IA. */
function set_special_html(PDO $pdo, string $key, array $d): string {
    switch ($d['type']) {
        case 'info':
            $show = (string)$d['show'];
            if ($key === 'mkt_templates') { require_once __DIR__ . '/inc/marketing.php'; $show = implode(' · ', MKT_FORMATS); }
            if ($key === 'int_meet') { require_once __DIR__ . '/inc/team.php'; $show = team_meet_config()['google'] ? 'Ligado (cria o link do Meet)' : 'Por ligar: as reuniões levam o link escrito à mão'; }
            return '<span class="set-info">' . e($show) . '</span>';
        case 'services':
            $rows = services_list($pdo, false);
            $used = $pdo->query('SELECT service_id, COUNT(DISTINCT contact_id) FROM contact_services WHERE ended_at IS NULL AND service_id IS NOT NULL GROUP BY service_id')->fetchAll(PDO::FETCH_KEY_PAIR);
            $h = '<div class="tablewrap"><table class="set-t" data-table="services"><thead><tr><th>Serviço</th><th class="num">Ordem</th><th>Ativo</th><th class="num hide-sm">Em uso</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $id = (int)$r['id'];
                $h .= '<tr data-id="' . $id . '"><td><input type="text" maxlength="80" value="' . e($r['name']) . '" data-cell="name" aria-label="Nome do serviço"></td>'
                    . '<td class="num"><input type="number" min="0" max="999" value="' . (int)$r['position'] . '" data-cell="position" style="width:5.5em" aria-label="Ordem de ' . e($r['name']) . '"></td>'
                    . '<td><label class="check"><input type="checkbox" value="1" data-cell="is_active"' . ((int)$r['is_active'] ? ' checked' : '') . '> Ativo</label></td>'
                    . '<td class="num hide-sm">' . ((int)($used[$id] ?? 0) ?: '<span class="muted">—</span>') . '</td></tr>';
            }
            return $h . '<tr data-id="0"><td colspan="4"><input type="text" maxlength="80" placeholder="+ Novo serviço (ex.: Motion Design) e Enter" data-cell="name" aria-label="Novo serviço"></td></tr></tbody></table></div>';
        case 'pillars':
            if (!db_has_table($pdo, 'mkt_pillars')) return '<span class="set-info">Disponível depois do passo 18 da base de dados.</span>';
            require_once __DIR__ . '/inc/marketing.php';
            $h = '<div class="tablewrap"><table class="set-t" data-table="mkt_pillars"><thead><tr><th>Pilar</th><th class="hide-sm">Nome curto</th><th>Cor</th><th class="num">Ordem</th><th>Ativo</th><th class="hide-sm">Talento</th></tr></thead><tbody>';
            foreach (mkt_pillars($pdo, false) as $pl) {
                $id = (int)$pl['id'];
                $col = '';
                foreach (MKT_PILLAR_COLORS as $ck => $cl) $col .= '<option value="' . $ck . '"' . ($pl['color'] === $ck ? ' selected' : '') . '>' . e($cl) . '</option>';
                $h .= '<tr data-id="' . $id . '"><td><input type="text" maxlength="80" value="' . e($pl['name']) . '" data-cell="name" aria-label="Nome do pilar">'
                    . '<input type="text" maxlength="500" value="' . e($pl['description']) . '" placeholder="Descrição (a IA usa-a)" data-cell="description" aria-label="Descrição de ' . e($pl['name']) . '" class="sub-in"></td>'
                    . '<td class="hide-sm"><input type="text" maxlength="24" value="' . e($pl['short_name']) . '" data-cell="short_name" style="width:9em" aria-label="Nome curto"></td>'
                    . '<td><span class="mpil p-' . e($pl['color']) . '" aria-hidden="true">Aa</span> <select data-cell="color" aria-label="Cor de ' . e($pl['name']) . '">' . $col . '</select></td>'
                    . '<td class="num"><input type="number" min="0" max="999" value="' . (int)$pl['position'] . '" data-cell="position" style="width:5em" aria-label="Ordem"></td>'
                    . '<td><label class="check"><input type="checkbox" value="1" data-cell="is_active"' . ((int)$pl['is_active'] ? ' checked' : '') . '> Ativo</label></td>'
                    . '<td class="hide-sm"><label class="check"><input type="checkbox" value="employer" data-cell="kind"' . ($pl['kind'] === 'employer' ? ' checked' : '') . '> Talento</label></td></tr>';
            }
            return $h . '<tr data-id="0"><td colspan="6"><input type="text" maxlength="80" placeholder="+ Novo pilar e Enter" data-cell="name" aria-label="Novo pilar"></td></tr></tbody></table></div>';
        case 'templates':
            require_once __DIR__ . '/inc/budget.php';
            $tpls = budget_templates($pdo);
            if (!$tpls) return '<span class="set-info">Ainda não há modelos. Numa folha, «Guardar como modelo» guarda as linhas e a duração (sem preços).</span>';
            $h = '<ul class="set-list">';
            foreach ($tpls as $t) {
                $h .= '<li><b>' . e($t['name']) . '</b> <span class="muted small">' . e($t['author'] ?? '—') . ' · ' . e(date('d/m/Y', strtotime((string)$t['created_at']))) . '</span>'
                    . '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="tpl_del" value="' . (int)$t['id'] . '">'
                    . icon_button('x', 'Apagar o modelo «' . $t['name'] . '»', ['type' => 'submit', 'class' => 'ibtn del']) . '</form></li>';
            }
            return $h . '</ul>';
        case 'types':
            $h = '<div class="set-chips">';
            foreach (TASK_TYPES as $k => [$l, $i]) $h .= '<span class="set-chip ty-' . $k . '"><i>' . icon($i) . '</i>' . e($l) . '</span>';
            return $h . '</div>';
        case 'origins':
            $h = '<div class="set-chips">';
            foreach (TASK_ORIGINS as $k => [$l, $i]) $h .= '<span class="set-chip or-' . $k . '"><i>' . icon($i) . '</i>' . e($l) . '</span>';
            return $h . '</div>';
        case 'cc_funnels':
            if (!db_has_column($pdo, 'funnels', 'cc_pre_mode')) return '<span class="set-info">Disponível depois do passo 24 da base de dados.</span>';
            require_once __DIR__ . '/inc/cc.php';
            $modes = ['recommended' => 'Recomendado', 'required' => 'Obrigatório', 'off' => 'Desligado'];
            $h = '<div class="tablewrap"><table class="set-t" data-table="funnels"><thead><tr><th>Funil</th><th>Pré-criativo</th><th>Sub-quadros do projeto (Kickoff)</th></tr></thead><tbody>';
            foreach (funnels_active($pdo) as $f) {
                $o = '';
                foreach ($modes as $k => $l) $o .= '<option value="' . $k . '"' . (($f['cc_pre_mode'] ?? 'recommended') === $k ? ' selected' : '') . '>' . $l . '</option>';
                $on = cc_funnel_stages($pdo, (int)$f['id']);
                $ck = '';
                foreach (CC_STAGES as $k => $l) $ck .= '<label class="check"><input type="checkbox" value="1" data-cell="cc_stage_' . $k . '"' . (in_array($k, $on, true) ? ' checked' : '') . '> ' . e($l) . '</label> ';
                $h .= '<tr data-id="' . (int)$f['id'] . '"><td><b>' . e($f['name']) . '</b></td><td><select data-cell="cc_pre_mode" aria-label="Pré-criativo em ' . e($f['name']) . '">' . $o . '</select></td><td class="set-checks">' . $ck . '</td></tr>';
            }
            return $h . '</tbody></table></div>';
        case 'cc_ai':
            if (!db_has_table($pdo, 'cc_ai_usage')) return '<span class="set-info">Disponível depois do passo 24 da base de dados.</span>';
            require_once __DIR__ . '/inc/cc_ai.php';
            $cfg = cc_ai_config();
            $emb = cc_embed_config();
            $sum = cc_ai_usage_summary($pdo);
            $usd = fn($v) => number_format((float)$v, 2, ',', ' ') . ' US$';
            $h = '<div class="set-ai"><span class="chip ' . ($cfg ? 'ok' : 'wait') . '">' . ($cfg ? 'Claude ligado · ' . e($cfg['model']) . ' · esforço ' . e($cfg['effort']) : 'Claude sem chave neste servidor') . '</span> '
               . '<span class="chip ' . ($emb ? 'ok' : 'wait') . '">' . ($emb ? 'Embeddings ligados · ' . e($emb['model']) : 'Embeddings por ligar (semelhança por etiquetas e paleta)') . '</span> '
               . '<span class="chip">Transcrição dos vídeos: por ligar</span></div>'
               . '<p><span class="mono">' . e($usd($sum['spend'])) . '</span> <span class="muted small">este mês · limite ' . e(number_format($sum['cap'], 0, ',', ' ')) . ' € (contado como US$)'
               . ($cfg ? ' · fallbacks do servidor ligados' . ($cfg['auto'] ? ' · análise automática de cada referência nova' : ' · análise só a pedido') : '') . '</span></p>'
               . '<p class="small muted">' . $sum['analyzed'] . ' referências analisadas · ' . $sum['embedded'] . ' com embedding · ' . $sum['queued'] . ' na fila' . ($sum['errors'] ? ' · <b>' . $sum['errors'] . ' erros nos últimos 7 dias</b>' : '') . '</p>';
            if ($sum['rows']) {
                $h .= '<div class="tablewrap"><table class="set-t"><thead><tr><th>Uso</th><th>Modelo</th><th class="num">Pedidos</th><th class="num hide-sm">Tokens (entrada / saída)</th><th class="num">Custo</th></tr></thead><tbody>';
                foreach ($sum['rows'] as $r) {
                    $h .= '<tr><td>' . e(CC_AI_PURPOSES[$r['purpose']] ?? $r['purpose']) . '</td><td class="mono small">' . e($r['model']) . '</td><td class="num">' . (int)$r['n'] . '</td>'
                        . '<td class="num hide-sm mono small">' . number_format((int)$r['tin'], 0, ',', ' ') . ' / ' . number_format((int)$r['tout'], 0, ',', ' ') . '</td><td class="num mono">' . e($usd($r['usd'])) . '</td></tr>';
                }
                $h .= '</tbody></table></div>';
            }
            return $h;
        case 'ai':
            require_once __DIR__ . '/inc/ai.php';
            [$ma, $mb, $mLabel] = ai_month_bounds();
            $spent = ai_month_spend_usd($pdo);
            $usd = fn($v) => number_format((float)$v, 4, ',', ' ') . ' US$';
            $log = db_has_table($pdo, 'mkt_ai_log') ? $pdo->query('SELECT l.*, u.name AS who FROM mkt_ai_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 8')->fetchAll() : [];
            $h = '<div class="set-ai"><span class="chip ' . (ai_config() ? 'ok' : 'wait') . '">' . (ai_config() ? 'Ligada' : 'Sem chave neste servidor') . '</span>'
               . ' <span class="mono">' . e($usd($spent)) . '</span> <span class="muted small">em ' . e($mLabel) . ' · limite ' . e(ai_eur(ai_cap_usd())) . ' (contado como US$)</span></div>';
            if ($log) {
                $h .= '<ul class="set-list small">';
                foreach ($log as $l) $h .= '<li><span class="mono">' . e(lisbon_fmt(strtotime((string)$l['created_at']), 'd/m H:i')) . '</span> ' . e($l['who'] ?? '—') . ' · ' . e((string)$l['action']) . ' · <span class="mono">' . e($usd($l['cost_usd'])) . '</span></li>';
                $h .= '</ul>';
            }
            return $h;
    }
    return '';
}

render_header('Definições', 'definicoes', '', [$tab === 'registo' ? 'Registo de alterações' : SET_TABS[$tab][0]]);
render_flashes();
?>
<div class="page-head">
  <div><h1>Definições</h1>
    <p class="sub">Gravam sozinhas. Cada alteração tem «Anular» e fica no registo.</p></div>
</div>

<div class="set-wrap">
  <nav class="set-nav" aria-label="Separadores das Definições">
    <div class="set-search">
      <?= icon('search') ?><input type="search" placeholder="Procurar definição…" aria-label="Procurar definição" data-set-search autocomplete="off">
      <div class="set-res" data-set-res hidden role="listbox" aria-label="Definições encontradas"></div>
    </div>
    <?php foreach (SET_TABS as $k => [$l, $i]): ?>
      <a href="/admin/definicoes.php?t=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= icon($i) ?> <?= e($l) ?></a>
    <?php endforeach; ?>
    <span class="set-sep" aria-hidden="true"></span>
    <a href="/admin/definicoes.php?t=registo" class="<?= $tab === 'registo' ? 'on' : '' ?>"><?= icon('undo') ?> Registo de alterações</a>
    <a href="/admin/equipa.php?t=acessos" class="set-out"><?= icon('equipa') ?> <span>Utilizadores e permissões<small>na aba Equipa ↗</small></span></a>
  </nav>

  <section class="set-main" data-set-main>
  <?php if ($tab === 'registo'):
      $rows = settings_log_rows($pdo, 150); ?>
    <div class="set-blk"><h2 class="set-h">Registo de alterações</h2>
      <?php if (!$rows): ?><p class="muted">Ainda não há alterações.</p><?php endif; ?>
      <div class="set-log">
      <?php foreach ($rows as $r): $d = setting_def((string)$r['skey']); ?>
        <div class="set-lr">
          <span class="mono muted"><?= e(lisbon_fmt(strtotime((string)$r['created_at']), 'd/m H:i')) ?></span>
          <span><?= e(first_name((string)($r['name'] ?? 'Sistema'))) ?></span>
          <span><b><?= e($r['label']) ?></b> <span class="muted"><?= $d && $d['type'] === 'list' ? e(setting_list_diff((string)$r['old_value'], (string)$r['new_value'])) : ($d ? e(setting_show($d, (string)$r['old_value'])) . ' → ' . e(setting_show($d, (string)$r['new_value'])) : e(mb_strimwidth((string)$r['old_value'], 0, 40, '…')) . ($r['old_value'] !== '' && $r['new_value'] !== '' ? ' → ' : '') . e(mb_strimwidth((string)$r['new_value'], 0, 40, '…'))) ?></span></span>
          <?php if ($d && !str_ends_with((string)$r['label'], '(anulado)')): ?><button type="button" class="set-reset" data-restore="<?= (int)$r['id'] ?>" data-tip="Volta ao valor de antes desta alteração">Repor</button><?php else: ?><span></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  <?php else:
      $groups = [];
      foreach (settings_defs() as $k => $d) if ($d['tab'] === $tab) $groups[$d['group']][$k] = $d;
      $configure = ['pipeline' => '/admin/pipeline.php', 'leads' => '/admin/leads.php', 'contactos' => '/admin/contactos.php', 'orcamentacao' => '/admin/orcamentacao.php',
                    'negocios' => '/admin/negocios.php', 'criativo' => '/admin/criativo.php', 'tarefas' => '/admin/calendar.php', 'marketing' => '/admin/marketing.php', 'financeiro' => '/admin/financeiro.php', 'drive' => '/admin/drive.php'][$tab] ?? '';
      foreach ($groups as $g => $items): ?>
    <div class="set-blk">
      <h2 class="set-h"><?= e($g) ?></h2>
      <?php foreach ($items as $k => $d) echo set_row_html($pdo, $k, $d); ?>
    </div>
  <?php endforeach; ?>
    <?php if ($configure !== ''): ?><p class="muted small">Estas definições valem para <a href="<?= e($configure) ?>">a aba <?= e(SET_TABS[$tab][0]) ?></a> e para toda a equipa.</p><?php endif; ?>
  <?php endif; ?>
  </section>
</div>
<script type="application/json" id="set-index"><?= json_encode(settings_index(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset_url('settings.js') ?>" defer></script>
<?php render_footer();
