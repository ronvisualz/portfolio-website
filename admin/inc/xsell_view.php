<?php
/**
 * topper. admin — o crosselling no ecrã (§22)
 *
 *  · «Serviços» (fase comercial): o principal e os adicionais, pacotes e o plano das frentes
 *    (sincronizadas ou timelines diferentes — obrigatório — arranques, entregas e execução);
 *  · as frentes (produção): cada serviço com a sua linha de etapas, os requisitos da etapa
 *    atual, «Avançar» (e «Forçar» para admins) e as rondas; no fim, o que falta para concluir.
 * Os formulários gravam em deal_flow.php, que volta a verificar tudo no servidor.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/xsell.php';
require_once __DIR__ . '/pipeline_view.php';

/** O texto de quando arranca uma frente à espera. */
function xsell_start_text(PDO $pdo, array $l): string {
    if ($l['start_mode'] === 'after' && $l['start_after_id']) {
        $a = xsell_line_get($pdo, (int)$l['start_after_id']);
        return 'arranca quando ' . ($a['funnel_name'] ?? 'a outra frente') . ' concluir';
    }
    if ($l['start_mode'] === 'date' && $l['start_date']) return 'arranca a ' . date('d/m/Y', strtotime((string)$l['start_date']));
    return 'arranca no Kickoff';
}

/** Os chips dos serviços («VFX & Pós-produção · principal», «+ Ads»). */
function xsell_chips_html(array $lines): string {
    $h = '';
    foreach ($lines as $l) {
        $h .= '<span class="chip xs-chip' . ((int)$l['is_main'] ? ' main' : '') . '">' . e($l['funnel_name']) . ((int)$l['is_main'] ? ' <small>principal</small>' : '') . '</span>';
    }
    return $h;
}

/** «Serviços»: o bloco do crosselling no painel e na ficha do negócio. */
function xsell_services_html(PDO $pdo, array $deal, string $back): string {
    if (!xsell_ready($pdo) || !$deal['funnel_id'] || !in_array($deal['phase'], ['pipeline', 'production', 'done', 'lost'], true)) return '';
    $lines = xsell_lines($pdo, (int)$deal['id']);
    $multi = count($lines) >= 2;
    $edit = flow_can_edit($pdo, $deal);
    $commercial = $deal['phase'] === 'pipeline';
    if (!$multi && !($edit && $commercial)) return '';
    $main = funnel_get($pdo, (int)$deal['funnel_id']);
    $h = '<section class="lv-blk xs" id="servicos"><h4>Serviços <span class="h-sub">' . ($multi ? 'crosselling · ' . e(XSELL_SYNC[$deal['sync_mode']] ?? 'falta escolher a sincronização') : 'um serviço · junte outros para crosselling') . '</span></h4>';
    $h .= '<div class="xs-chips">' . ($multi ? xsell_chips_html($lines) : '<span class="chip xs-chip main">' . e($main['name'] ?? '') . ' <small>principal</small></span>') . '</div>';

    if ($commercial && $edit) {
        // Os serviços adicionais e os pacotes.
        $in = array_map(fn($l) => (int)$l['funnel_id'], $lines);
        $boxes = '';
        foreach (funnels_active($pdo) as $f) {
            if ((int)$f['id'] === (int)$deal['funnel_id']) continue;
            $boxes .= '<label class="rb"><input type="checkbox" name="funnels[]" value="' . (int)$f['id'] . '"' . (in_array((int)$f['id'], $in, true) ? ' checked' : '') . '><span>' . e($f['name']) . '</span></label>';
        }
        $h .= '<details class="fl-edit xs-add"' . (!$multi ? '' : '') . '><summary>' . icon('plus') . ' ' . ($multi ? 'Mudar os serviços adicionais' : 'Juntar serviços (crosselling)') . '</summary>'
            . flow_form('services', $deal, $back, '<div class="chips-check">' . $boxes . '</div><button class="btn sm" type="submit">Guardar serviços</button>'
                . '<p class="muted small">Uma só fase comercial e uma só proposta. No Kickoff cada serviço passa a uma frente com as etapas e as tarefas do seu funil.</p>');
        $pk = xsell_packages($pdo);
        if ($pk) {
            $o = '<option value="">Escolher um pacote…</option>';
            foreach ($pk as $p) {
                $names = implode(' + ', array_map(fn($f) => (string)(funnel_get($pdo, $f)['name'] ?? '?'), xsell_package_funnels($p)));
                $o .= '<option value="' . (int)$p['id'] . '"' . ((int)$deal['package_id'] === (int)$p['id'] ? ' selected' : '') . '>' . e($p['name'] . ' · ' . $names) . '</option>';
            }
            $h .= flow_form('services', $deal, $back, '<label class="sr-only" for="xs-pk-' . (int)$deal['id'] . '">Pacote</label><select id="xs-pk-' . (int)$deal['id'] . '" name="package" required>' . $o . '</select>'
                . '<button class="btn ghost sm" type="submit">' . icon('package') . ' Aplicar pacote</button>', ['class' => 'fl-inline xs-pk']);
        }
        $h .= '</details>';
    }
    if ($multi && $deal['phase'] !== 'done' && $deal['phase'] !== 'lost') $h .= xsell_plan_html($pdo, $deal, $lines, $back, $edit);
    if ($multi && in_array($deal['phase'], ['production', 'done'], true)) $h .= xsell_lanes_html($pdo, $deal, $lines, $back, $edit);
    return $h . '</section>';
}

/** O plano das frentes: sincronização (obrigatória), arranques, entregas e execução. */
function xsell_plan_html(PDO $pdo, array $deal, array $lines, string $back, bool $edit): string {
    $mode = (string)$deal['sync_mode'];
    $started = $deal['phase'] === 'production';
    if (!$edit) {
        return '<p class="small">' . ($mode !== '' ? '<b>' . e(XSELL_SYNC[$mode]) . '</b> — ' . e(XSELL_SYNC_HINT[$mode]) : '<span class="risk-txt">Falta escolher se as frentes seguem sincronizadas ou em timelines diferentes.</span>') . '</p>';
    }
    $radios = '';
    foreach (XSELL_SYNC as $k => $label) {
        $lock = $started && $mode !== '' && $k !== $mode;
        $radios .= '<label class="opt xs-mode' . ($lock ? ' off' : '') . '"><input type="radio" name="sync_mode" value="' . $k . '" required' . ($mode === $k ? ' checked' : '') . ($lock ? ' disabled' : '') . ' data-xs-mode>'
                 . '<b>' . e($label) . '</b><small>' . e(XSELL_SYNC_HINT[$k]) . '</small></label>';
    }
    $rows = '';
    $users = is_admin() ? $pdo->query("SELECT id, name FROM users WHERE is_active = 1 AND role <> 'superadmin' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    foreach ($lines as $l) {
        $lid = (int)$l['id'];
        $open = in_array($l['status'], ['planned', 'waiting'], true);
        $start = '';
        if (!(int)$l['is_main']) {
            $so = '';
            foreach (XSELL_START as $k => $label) $so .= '<option value="' . $k . '"' . ($l['start_mode'] === $k ? ' selected' : '') . '>' . e($label) . '</option>';
            $ao = '';
            foreach ($lines as $o) if ((int)$o['id'] !== $lid) $ao .= '<option value="' . (int)$o['id'] . '"' . ((int)$l['start_after_id'] === (int)$o['id'] ? ' selected' : '') . '>' . e($o['funnel_name']) . '</option>';
            $start = $open
                ? '<label>Arranque<select name="start[' . $lid . ']" data-xs-start>' . $so . '</select></label>'
                  . '<label data-xs-after>Depois de<select name="after[' . $lid . ']">' . $ao . '</select></label>'
                  . '<label data-xs-date>Data de arranque<input type="date" name="start_date[' . $lid . ']" value="' . e((string)$l['start_date']) . '"></label>'
                : '<span class="muted small">arrancou ' . ($l['started_at'] ? date('d/m/Y', strtotime((string)$l['started_at'])) : '') . '</span>';
        }
        $exec = '';
        if ($users) {
            $eo = '<option value="">Execução do negócio</option>';
            foreach ($users as $uid => $un) $eo .= '<option value="' . (int)$uid . '"' . ((int)$l['exec_owner_id'] === (int)$uid ? ' selected' : '') . '>' . e($un) . '</option>';
            $exec = '<label>Execução<select name="exec[' . $lid . ']">' . $eo . '</select></label>';
        }
        $rows .= '<fieldset class="xs-line" data-start="' . e($l['start_mode']) . '"><legend>' . e($l['funnel_name']) . ((int)$l['is_main'] ? ' <small>principal</small>' : '') . '</legend><div class="fl-grid">'
               . '<div class="xs-ind">' . $start . '</div>'
               . '<label class="xs-ind">Entrega desta frente<input type="date" name="delivery[' . $lid . ']" value="' . e((string)$l['delivery_date']) . '"></label>'
               . $exec . '</div></fieldset>';
    }
    return '<details class="fl-edit xs-plan"' . ($mode === '' ? ' open' : '') . '><summary>' . icon('stage') . ' Plano das frentes'
         . ($mode === '' ? ' <span class="chip no">por escolher</span>' : ' <span class="chip">' . e(XSELL_SYNC[$mode]) . '</span>') . '</summary>'
         . flow_form('plan', $deal, $back, '<div class="opts xs-modes" role="radiogroup" aria-label="Sincronização das frentes">' . $radios . '</div>'
             . '<div class="xs-lines" data-xs-mode-box="' . e($mode) . '">' . $rows . '</div>'
             . '<button class="btn sm" type="submit">Guardar o plano</button>'
             . '<p class="muted small">Obrigatório num negócio de crosselling: é requisito para enviar a proposta. A data de entrega das frentes sincronizadas é a do negócio.</p>', ['class' => 'fl-form xs-planf'])
         . '</details>';
}

/** As frentes (produção): uma linha de etapas por serviço, os requisitos e «Avançar». */
function xsell_lanes_html(PDO $pdo, array $deal, array $lines, string $back, bool $edit): string {
    $sync = $deal['sync_mode'] !== 'independent';
    $h = '<div class="xs-lanes">';
    foreach ($lines as $l) {
        $v = xsell_vdeal($deal, $l);
        $rec = xsell_is_recurring($l);
        $status = $l['status'];
        $chip = match ($status) {
            'done'    => '<span class="chip ok">Concluída</span>',
            'waiting' => '<span class="chip wait">À espera</span>',
            'active'  => $rec && $deal['phase'] === 'done' ? '<span class="chip info">Avença ativa</span>' : '<span class="chip info">Em curso</span>',
            default   => '<span class="chip">Planeada</span>',
        };
        $meta = [];
        if ($l['exec_owner_id'] || $deal['exec_owner_id']) $meta[] = 'execução: ' . flow_user_name($pdo, $l['exec_owner_id'] ?: $deal['exec_owner_id']);
        $dd = $sync ? $deal['delivery_date'] : ($l['delivery_date'] ?: null);
        if ($dd) $meta[] = 'entrega ' . date('d/m/Y', strtotime((string)$dd));
        if ($status === 'waiting') $meta[] = xsell_start_text($pdo, $l);
        if ($l['value'] !== null && can('financeiro')) $meta[] = eur0((float)$l['value']) . ' s/ IVA';
        $h .= '<article class="xs-lane st-' . e($status) . '" data-line="' . (int)$l['id'] . '"><header><b>' . e($l['funnel_name']) . '</b>' . ((int)$l['is_main'] ? '<small class="muted">principal</small>' : '') . $chip
            . ($meta ? '<span class="muted small">' . e(implode(' · ', $meta)) . '</span>' : '') . '</header>';
        $h .= xsell_lane_steps_html($pdo, $deal, $l, $back, $edit);
        if ($status === 'active' && $deal['phase'] === 'production') {
            $h .= flow_stages_html($pdo, $v, $back);
            $h .= flow_rounds_html($pdo, $v, $back);
        }
        $h .= '</article>';
    }
    if ($deal['phase'] === 'production') {
        $miss = xsell_close_missing($pdo, $deal);
        $h .= '<p class="xs-close small' . ($miss ? '' : ' ok') . '">' . ($miss
            ? icon('clock') . ' Para concluir o negócio' . ($sync ? ' (entrega conjunta)' : '') . ': ' . e(implode(' · ', $miss)) . '.'
            : icon('check') . ' Todas as frentes estão prontas: conclua o negócio na checklist de fecho (ficha do negócio).') . '</p>';
    }
    return $h . '</div>';
}

/** A linha de etapas de uma frente (Kickoff → produção → Concluído); na ficha carregam-se. */
function xsell_lane_steps_html(PDO $pdo, array $deal, array $l, string $back, bool $edit): string {
    $order = array_values(array_filter(flow_order($pdo, (int)$l['funnel_id']), fn($s) => in_array($s['kind'], ['kickoff', 'production', 'done'], true)));
    $i = $l['stage_id'] ? flow_index($order, (int)$l['stage_id']) : null;
    $sync = $deal['sync_mode'] !== 'independent';
    $clickable = $edit && $l['status'] === 'active' && $deal['phase'] === 'production';
    $h = '';
    foreach ($order as $k => $s) {
        $st = $l['status'] === 'done' || ($i !== null && $k < $i) ? 'done' : ($i !== null && $k === $i ? 'cur' : 'next');
        $inner = '<span class="dot" aria-hidden="true">' . ($st === 'done' ? icon('check') : '') . '</span><span class="nm">' . e($s['name']) . '</span>' . ($st === 'cur' ? '<small>Etapa atual</small>' : '');
        if ($clickable && $st !== 'cur' && $s['kind'] !== 'kickoff' && !($s['kind'] === 'done' && $sync)) {
            $inner = '<button class="fl-sb" name="stage_id" value="' . (int)$s['id'] . '" data-tip="' . e('Passar a ' . $s['name']) . '">' . $inner . '</button>';
        }
        $h .= '<li class="' . $st . ' z-' . e($s['kind']) . '"' . ($st === 'cur' ? ' aria-current="step"' : '') . '>' . $inner . '</li>';
    }
    $ol = '<ol class="fl-step" aria-label="Etapas da frente ' . e($l['funnel_name']) . '">' . $h . '</ol>';
    return $clickable ? flow_form('move', $deal, $back, '<input type="hidden" name="line" value="' . (int)$l['id'] . '">' . $ol, ['class' => 'xs-steps']) : '<div class="xs-steps">' . $ol . '</div>';
}

/** O resumo das frentes para o topo do painel e para as listas: «VFX: Comp · Ads: à espera». */
function xsell_lanes_short(PDO $pdo, array $deal): string {
    $out = [];
    foreach (xsell_lines($pdo, (int)$deal['id']) as $l) {
        $out[] = $l['funnel_name'] . ': ' . match ($l['status']) { 'done' => 'concluída', 'waiting' => 'à espera', 'planned' => 'planeada', default => (string)($l['stage_name'] ?? '—') };
    }
    return implode(' · ', $out);
}
