<?php
/**
 * topper. admin — a folha de orçamento por dentro (§14)
 *
 * As partes da folha que a página (orcamento.php) desenha e que o
 * ui_api.php devolve depois de cada mudança: linhas de custo, cálculo,
 * preço, Resultado, histórico e o formulário «+ Adicionar linha». Tudo o
 * que é dinheiro passa por budget_money(): quem não vê o Financeiro
 * recebe as mesmas partes sem preços de fornecedores, custos da equipa,
 * margens nem lucro (§14.6).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/budget.php';

// ── formatos ────────────────────────────────────────────────

/** «28 629 €» (como no preçário dos Contactos). */
function budget_eur(?float $v, int $dec = 0): string {
    if ($v === null) return '—';
    return ($v < 0 ? '−' : '') . number_format(abs($v), $dec, ',', ' ') . ' €';
}

/** «65%», «62,5%». */
function budget_pct(?float $v): string {
    if ($v === null) return '—';
    return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . '%';
}

/** «1,5» sem zeros a mais. */
function budget_n(float $v, int $dec = 2): string {
    return rtrim(rtrim(number_format($v, $dec, ',', ' '), '0'), ',');
}

function budget_months_label(float $m): string {
    $r = round($m, 1);
    return budget_n($r, 1) . ($r == 1 ? ' mês' : ' meses');
}

/** O ⓘ de um campo de cálculo (§14.4): dica ao passar o cursor, com o teclado ou com toque longo. */
function budget_ii(string $key, string $extra = ''): string {
    $t = (BUDGET_INFO[$key] ?? '') . ($extra !== '' ? ' ' . $extra : '');
    return '<span class="ii" tabindex="0" role="img" aria-label="Ajuda: ' . e($t) . '" data-tip="' . e($t) . '">i</span>';
}

/** O semáforo do lucro por mês contra a meta. */
function budget_status_chip(string $status, ?float $pm = null): string {
    $cls = ['ok' => 'g', 'below' => 'o', 'far' => 'r'][$status] ?? 'o';
    $txt = $pm !== null ? budget_k($pm) : BUDGET_STATUS[$status];
    return '<span class="sem ' . $cls . '" data-tip="' . e(BUDGET_STATUS[$status] . ' (lucro por mês contra a meta mensal)') . '" tabindex="0"><i aria-hidden="true"></i>' . e($txt) . '</span>';
}

/** O negócio com cliente, «trazido por», etapa e funil, para o cabeçalho e as listas. */
function budget_deal_row(PDO $pdo, int $dealId): ?array {
    $st = $pdo->prepare('SELECT d.*, COALESCE(co.name, c.name, NULLIF(d.company, \'\')) AS client_name, bb.name AS brought_name,
                                s.name AS stage_name, s.kind AS stage_kind, f.name AS funnel_name, u.name AS owner_name
                           FROM deals d LEFT JOIN contacts co ON co.id = d.company_id LEFT JOIN contacts c ON c.id = d.contact_id
                           LEFT JOIN contacts bb ON bb.id = d.brought_by_id LEFT JOIN funnel_stages s ON s.id = d.stage_id
                           LEFT JOIN funnels f ON f.id = d.funnel_id LEFT JOIN users u ON u.id = d.owner_id
                          WHERE d.id = ?');
    $st->execute([$dealId]);
    return $st->fetch() ?: null;
}

/** O separador da Orçamentação onde a folha vive, pela fase do negócio. */
function budget_tab_of(string $phase): string {
    return array_search($phase, BUDGET_TAB_PHASE, true) ?: 'orcamentacao';
}

// ── o que o cálculo mostra (para a página e para o budget.js) ──

/**
 * Os valores do cálculo já formatados, chave → texto, mais o semáforo e as frases.
 * Sem Financeiro só seguem o tempo, a ocupação e o preço final.
 */
function budget_calc_view(array $c, bool $money): array {
    $occTxt = $c['occ_suggest'] === null ? 'sem horas da equipa na aba Equipa' : 'sugestão ' . budget_pct($c['occ_suggest']);
    $v = [
        'dur'       => budget_n($c['days'], 1) . ' dias úteis · ' . budget_months_label($c['months']),
        'occ_label' => ($c['occ_manual'] ? 'manual · ' : 'automática · ') . $occTxt,
        'occ_hint'  => $c['occ_suggest'] === null
            ? 'Para a sugestão automática, indique na aba Equipa as horas disponíveis por mês de cada pessoa.'
            : 'Sugestão: ' . budget_n($c['internal_hours'], 1) . ' h internas ÷ (' . $c['capacity_people'] . ($c['capacity_people'] === 1 ? ' pessoa · ' : ' pessoas · ')
              . budget_n($c['capacity'], 1) . ' h por mês × ' . budget_n($c['months'], 2) . ($c['months'] == 1 ? ' mês' : ' meses') . ') = ' . budget_pct($c['occ_suggest'])
              . ($c['occ_manual'] ? '. Escreveu outro valor; «Usar a sugestão» volta ao automático.' : '. Escreva outro valor para o substituir.'),
        'price'     => budget_eur($c['price']),
        'price_vat' => 'c/ IVA ' . budget_pct($c['vat_rate']) . ': ' . budget_eur($c['price_vat']),
    ];
    $out = ['k' => $v, 'occupancy' => $c['occupancy'], 'occ_manual' => $c['occ_manual'], 'money' => $money];
    if (!$money) return $out;
    $out['k'] = array_merge($out['k'], [
        'direct'       => budget_eur($c['direct']),
        'mso'          => budget_eur($c['mso']),
        'mso_label'    => 'MSO ' . budget_pct($c['mso_pct']),
        'structure'    => budget_eur($c['structure']),
        'structure_f'  => budget_eur($c['fixed']) . ' × ' . budget_n($c['months'], 2) . ' × ' . budget_pct($c['occupancy']),
        'base'         => budget_eur($c['base']),
        'price_vat'    => $v['price_vat'] . ($c['markup'] !== null ? ' · markup equivalente ' . budget_pct(round($c['markup'])) : ''),
        'gp'           => budget_eur($c['gp']) . ' · ' . budget_pct($c['gm']),
        'profit_month' => budget_eur($c['profit_month']),
        'target'       => budget_eur($c['target']),
        'fixed_label'  => $c['fixed_manual'] ? 'manual' : 'média 3 meses · Financeiro',
    ]);
    $out['status'] = $c['status'];
    $out['status_html'] = budget_status_chip($c['status']);
    $out['suggestion'] = $c['status'] === 'ok' ? ''
        : 'Para cumprir a meta: preço mínimo ' . budget_eur($c['min_price']) . ' ou entrega em ≤ ' . budget_n((float)$c['max_months'], 1) . ' meses.';
    return $out;
}

// ── linhas de custo ─────────────────────────────────────────

/** O texto da unidade de uma linha («dias», «projeto»…). */
function budget_unit_cell(array $l, bool $editable): string {
    $timed = $l['kind'] === 'team' || ($l['kind'] === 'supplier' && budget_unit_hours((string)$l['price_unit'], 8) !== null);
    if ($timed && $editable) {
        $o = '';
        foreach (BUDGET_QTY_UNITS as $k => $u) $o .= '<option value="' . $k . '"' . ($l['qty_unit'] === $k ? ' selected' : '') . '>' . $u . '</option>';
        return '<select class="bl-u" data-line="' . (int)$l['id'] . '" aria-label="Unidade da quantidade de «' . e($l['label']) . '»">' . $o . '</select>';
    }
    if ($timed) return e(BUDGET_QTY_UNITS[$l['qty_unit']] ?? $l['qty_unit']);
    if ($l['kind'] === 'other') return $l['price_unit'] === 'fixed' ? '' : e(BUDGET_OTHER_UNITS[$l['price_unit']] ?? '');
    return e(SERVICE_UNITS[$l['price_unit']] ?? $l['price_unit']);
}

/** As linhas da versão (tbody). */
function budget_lines_rows(PDO $pdo, array $lines, bool $money, bool $editable): string {
    if (!$lines) {
        return '<tr class="bl-empty"><td colspan="6"><span class="muted">Ainda sem linhas. '
             . ($editable ? 'Comece por «+ Adicionar linha» ou aplique um modelo.' : '') . '</span></td></tr>';
    }
    $h = '';
    $ratings = [];
    foreach ($lines as $l) {
        $id = (int)$l['id'];
        $who = budget_line_who($l);
        if ($l['kind'] === 'supplier' && $l['contact_id']) {
            $ratings[(int)$l['contact_id']] ??= contact_rating($pdo, (int)$l['contact_id']);
            [$avg] = $ratings[(int)$l['contact_id']];
            if ((int)($l['contact_active'] ?? 1) === 1) $who .= ' · ativo' . ($avg !== null ? ' ★' . number_format($avg, 1, ',', '') : '');
        }
        $show = $money || $l['kind'] === 'other';
        $hide = '<span class="muted" data-tip="Só quem vê o Financeiro vê preços e custos" tabindex="0">—</span>';
        $fixedOther = $l['kind'] === 'other' && $l['price_unit'] === 'fixed';
        $qty = $fixedOther ? '<span class="muted">—</span>'
            : ($editable ? '<input class="bl-q" type="text" inputmode="decimal" maxlength="9" value="' . e(budget_n((float)$l['qty'])) . '" data-line="' . $id . '" aria-label="Quantidade de «' . e($l['label']) . '»">'
                         : '<span class="mono">' . e(budget_n((float)$l['qty'])) . '</span>');
        $inactive = $l['kind'] === 'supplier' && (int)($l['contact_active'] ?? 1) !== 1;
        $h .= '<tr data-line="' . $id . '" data-kind="' . e($l['kind']) . '"' . ($inactive ? ' class="bl-off"' : '') . '>'
            . '<td class="bl-nm"><b>' . e($l['label']) . '</b><small>' . e($who) . ($l['note'] !== '' ? ' · ' . e(shorten((string)$l['note'], 60)) : '') . '</small></td>'
            . '<td class="num nowrap">' . ($show ? e(budget_unit_price_label($l)) : $hide) . '</td>'
            . '<td class="num bl-qc">' . $qty . '</td>'
            . '<td class="bl-uc">' . budget_unit_cell($l, $editable && !$fixedOther) . '</td>'
            . '<td class="num nowrap b" data-cost="' . $id . '">' . ($show ? e(budget_eur((float)$l['cost'])) : $hide) . '</td>'
            . '<td class="nowrap act">' . ($editable
                ? icon_button('pencil', 'Alterar «' . $l['label'] . '»', ['data-bl-edit' => (string)$id])
                . icon_button('x', 'Tirar «' . $l['label'] . '»', ['data-bl-del' => (string)$id]) : '') . '</td>'
            . '</tr>';
    }
    return $h;
}

/** Os dados de cada linha para o formulário de alterar (sem preços para quem não vê o Financeiro). */
function budget_lines_json(array $lines, bool $money): string {
    $out = [];
    foreach ($lines as $l) {
        $p = budget_line_public($l, $money);
        $out[(int)$l['id']] = ['kind' => $l['kind'], 'service_id' => $l['service_id'], 'contact_id' => $l['contact_id'], 'user_id' => $l['user_id'],
                               'label' => $l['label'], 'price_unit' => $l['price_unit'], 'unit_price' => $p['unit_price'] !== null ? budget_n((float)$p['unit_price']) : '',
                               'qty' => budget_n((float)$l['qty']), 'qty_unit' => $l['qty_unit'], 'note' => $l['note']];
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
}

/** O bloco «Linhas de custo». */
function budget_lines_block(PDO $pdo, array $lines, array $calc, bool $money, bool $editable): string {
    return '<section class="lv-blk bl-blk" id="b-lines">'
         . '<h4>Linhas de custo ' . budget_ii('direct') . '</h4>'
         . '<div class="bl-wrap"><table class="bl-t"><thead><tr><th>Serviço · quem</th><th class="num">Preço</th><th class="num">Qtd</th><th>Unid.</th><th class="num">Custo</th><th><span class="sr">Ações</span></th></tr></thead>'
         . '<tbody data-lines>' . budget_lines_rows($pdo, $lines, $money, $editable) . '</tbody>'
         . ($money ? '<tfoot><tr><td colspan="4">Custos diretos</td><td class="num b" data-k="direct">' . e(budget_eur($calc['direct'])) . '</td><td></td></tr></tfoot>' : '')
         . '</table></div>'
         . '<div class="bl-foot">' . ($editable ? '<button type="button" class="btn dark sm" data-new="#mdl-bline" data-bl-add aria-keyshortcuts="N" data-tip="Nova linha  ·  N">' . icon('plus') . ' Adicionar linha</button>' : '')
         . '<span class="muted small">Fornecedores ativos com o preço copiado no momento · 1 dia = ' . budget_n($calc['day_hours'], 1) . ' h úteis, conversão automática</span></div>'
         . '<script type="application/json" data-lines-json>' . budget_lines_json($lines, $money) . '</script>'
         . '</section>';
}

// ── tempo ───────────────────────────────────────────────────

function budget_phases_rows(array $v, bool $editable): string {
    $phases = budget_phases($v);
    if (!$phases) $phases = array_map(fn($n) => ['name' => $n, 'days' => 0], BUDGET_PHASES);
    $dated = [];
    foreach (budget_phase_dates(['phases' => json_encode($phases)] + $v) as $p) $dated[] = $p;
    $h = '';
    foreach ($phases as $i => $p) {
        $d = $dated[$i] ?? [];
        $when = !empty($d['from']) ? date('d/m', strtotime($d['from'])) . ' → ' . date('d/m', strtotime($d['to'])) : '';
        $h .= '<tr>'
            . '<td>' . ($editable ? '<input type="text" class="ph-n" maxlength="60" value="' . e($p['name']) . '" aria-label="Fase">' : e($p['name'])) . '</td>'
            . '<td class="num">' . ($editable ? '<input type="text" class="ph-d" inputmode="decimal" maxlength="6" value="' . e($p['days'] > 0 ? budget_n($p['days'], 1) : '') . '" aria-label="Dias úteis de «' . e($p['name']) . '»">' : e(budget_n($p['days'], 1))) . '</td>'
            . '<td class="muted mono small ph-w">' . e($when) . '</td>'
            . '<td class="act">' . ($editable ? icon_button('x', 'Tirar a fase «' . $p['name'] . '»', ['data-ph-del' => true]) : '') . '</td></tr>';
    }
    return $h;
}

/** O bloco «Tempo»: duração, custos fixos mensais e % de ocupação. */
function budget_time_block(PDO $pdo, array $v, array $calc, bool $money, bool $editable): string {
    $dis = $editable ? '' : ' disabled';
    $modes = '';
    foreach (BUDGET_DUR_MODES as $k => $label) {
        $modes .= '<label class="rb"><input type="radio" name="dur_mode" value="' . $k . '" data-f="dur_mode"' . ($v['dur_mode'] === $k ? ' checked' : '') . $dis . '><span>' . e($label) . '</span></label>';
    }
    $num = fn($x, int $dec = 1) => $x === null || $x === '' ? '' : budget_n((float)$x, $dec);
    $sec = fn(string $mode, string $inner) => '<div class="bt-mode" data-mode="' . $mode . '"' . ($v['dur_mode'] === $mode ? '' : ' hidden') . '>' . $inner . '</div>';
    $fixedDetail = '';
    if ($money) {
        $avg = budget_fixed_average($pdo);
        $fixedDetail = implode(' · ', array_map(fn($m) => mb_substr(month_name($m['m']), 0, 3) . ' ' . budget_eur($m['total']), $avg['months'])) . ' → média ' . budget_eur($avg['avg']);
    }
    $h = '<section class="lv-blk bt-blk" id="b-time"><h4>Tempo</h4>'
       . '<div class="bt-row"><span class="lbl">Duração <small>dias úteis, seg–sex</small> ' . budget_ii('duration', '1 mês = ' . BUDGET_MONTH_DAYS . ' dias úteis.') . '</span>'
       . '<div class="chips-check bt-modes" role="radiogroup" aria-label="Como indicar a duração">' . $modes . '</div></div>'
       . $sec('dates', '<div class="bt-in"><label>Início<input type="date" data-f="start_date" value="' . e((string)$v['start_date']) . '"' . $dis . '></label>'
                     . '<label>Fim<input type="date" data-f="end_date" value="' . e((string)$v['end_date']) . '"' . $dis . '></label></div>')
       . $sec('weeks', '<div class="bt-in"><label>Semanas<input type="text" inputmode="decimal" maxlength="6" data-f="weeks" value="' . e($num($v['weeks'], 2)) . '"' . $dis . '></label><span class="muted small">× 5 dias úteis</span></div>')
       . $sec('days', '<div class="bt-in"><label>Dias úteis<input type="text" inputmode="decimal" maxlength="6" data-f="days" value="' . e($num($v['days'])) . '"' . $dis . '></label></div>')
       . $sec('phases', '<div class="bt-in"><label>Início (opcional, para as datas)<input type="date" data-f="start_date" value="' . e((string)$v['start_date']) . '"' . $dis . '></label></div>'
                      . '<table class="ph-t"><thead><tr><th>Fase</th><th class="num">Dias úteis</th><th>Datas</th><th><span class="sr">Ações</span></th></tr></thead><tbody data-phases>' . budget_phases_rows($v, $editable) . '</tbody></table>'
                      . ($editable ? '<button type="button" class="lnk" data-ph-add>' . icon('plus') . ' Adicionar fase</button>' : ''))
       . '<p class="bt-res">= <b data-k="dur">' . e(budget_calc_view($calc, $money)['k']['dur']) . '</b></p>';
    if ($money) {
        $h .= '<div class="bt-row"><span class="lbl">Custos fixos mensais ' . budget_ii('fixed', 'Últimos 3 meses: ' . $fixedDetail . '.')
            . ' <span class="auto" data-k="fixed_label">' . e($calc['fixed_manual'] ? 'manual' : 'média 3 meses · Financeiro') . '</span></span>'
            . '<div class="bt-in"><input type="text" inputmode="decimal" maxlength="12" data-f="fixed_monthly" value="' . e(budget_n($calc['fixed'])) . '" aria-label="Custos fixos mensais (€)"' . $dis . '><span class="muted">€/mês</span>'
            . ($editable ? '<button type="button" class="lnk" data-fixed-avg data-tip="Repor a média dos 3 últimos meses do Financeiro">' . icon('refresh') . ' Usar a média</button>' : '') . '</div></div>';
    }
    $h .= '<div class="bt-row"><span class="lbl">% de ocupação ' . budget_ii('occupancy') . ' <span class="auto" data-k="occ_label">' . e(budget_calc_view($calc, $money)['k']['occ_label']) . '</span></span>'
        . '<div class="bt-in"><input type="text" inputmode="decimal" maxlength="5" data-f="occupancy" value="' . e(budget_n($calc['occupancy'], 1)) . '" aria-label="Percentagem de ocupação"' . $dis . '><span class="muted">%</span>'
        . ($editable ? '<button type="button" class="lnk" data-occ-auto' . ($calc['occ_manual'] ? '' : ' hidden') . '>' . icon('refresh') . ' Usar a sugestão</button>' : '') . '</div>'
        . '<p class="hint" data-k="occ_hint">' . e(budget_calc_view($calc, $money)['k']['occ_hint']) . '</p></div>'
        . '</section>';
    return $h;
}

// ── cálculo e preço ─────────────────────────────────────────

function budget_calc_block(array $calc, bool $editable): string {
    $view = budget_calc_view($calc, true)['k'];
    $row = fn(string $label, string $key, string $info, string $extra = '') =>
        '<div class="fx"><span class="lbl">' . $label . ' ' . budget_ii($info) . $extra . '</span><b data-k="' . $key . '">' . e($view[$key]) . '</b></div>';
    return '<section class="lv-blk bc-blk" id="b-calc"><h4>Cálculo</h4>'
         . $row('Custos diretos', 'direct', 'direct')
         . $row('<span data-k="mso_label">' . e($view['mso_label']) . '</span>', 'mso', 'mso')
         . $row('Custo de estrutura', 'structure', 'structure', ' <span class="auto" data-k="structure_f">' . e($view['structure_f']) . '</span>')
         . $row('<b>Base</b>', 'base', 'base')
         . '<div class="fx"><span class="lbl">Margem ' . budget_ii('margin', 'Por defeito ' . budget_pct((float)BUDGET_SETTINGS['budget_margin_pct']) . ' (Definições); cada mudança fica no Histórico.') . '</span>'
         . '<span class="bc-in"><input type="text" inputmode="decimal" maxlength="5" data-f="margin" value="' . e(budget_n($calc['margin'], 1)) . '" aria-label="Margem (%)"' . ($editable ? '' : ' disabled') . '><span class="muted">%</span></span></div>'
         . '</section>';
}

function budget_price_block(array $calc, bool $money): string {
    $view = budget_calc_view($calc, $money);
    $k = $view['k'];
    $h = '<section class="lv-blk bp-blk" id="b-price"><h4>Preço s/ IVA ' . budget_ii('price') . '</h4>'
       . '<div class="bp-price" data-k="price">' . e($k['price']) . '</div>'
       . '<p class="hint" data-k="price_vat">' . e($k['price_vat']) . '</p>';
    if (!$money) {
        return $h . '<p class="muted small" style="margin:8px 0 0">Custos, margem e lucro: só quem vê o Financeiro.</p></section>';
    }
    return $h
       . '<div class="fx"><span class="lbl">Gross profit · margin ' . budget_ii('gross') . '</span><b data-k="gp">' . e($k['gp']) . '</b></div>'
       . '<div class="fx"><span class="lbl">Lucro por mês ' . budget_ii('profit_month') . '</span><b data-k="profit_month">' . e($k['profit_month']) . '</b></div>'
       . '<div class="fx"><span class="lbl">Meta mensal ' . budget_ii('target', 'Muda-se em Definições (superadmin e admins).') . '</span><b data-k="target">' . e($k['target']) . '</b></div>'
       . '<div class="bp-sem" data-k-status>' . $view['status_html'] . '</div>'
       . '<p class="hint" data-k="suggestion"' . ($view['suggestion'] === '' ? ' hidden' : '') . '>' . e($view['suggestion']) . '</p>'
       . '</section>';
}

// ── proposta (texto do PDF) ─────────────────────────────────

function budget_proposal_block(array $v, bool $editable): string {
    $dis = $editable ? '' : ' disabled';
    return '<section class="lv-blk bq-blk" id="b-prop"><h4>Proposta <span class="h-sub">o que o cliente vê no PDF</span></h4>'
         . '<label class="bq-l">Descrição do trabalho<textarea rows="4" maxlength="6000" data-f="description" placeholder="Ex.: Spot 3D de 30 segundos para televisão e redes sociais, com duas versões de 15 s."' . $dis . '>' . e((string)$v['description']) . '</textarea></label>'
         . '<label class="bq-l">Condições<textarea rows="3" maxlength="4000" data-f="conditions"' . $dis . '>' . e((string)$v['conditions']) . '</textarea></label>'
         . '<p class="muted small" style="margin:0">A proposta leva a descrição, as fases e prazos, o preço s/ e c/ IVA e as condições. Nunca custos nem margens.</p>'
         . '</section>';
}

// ── Resultado: orçado vs real (§14.5) ──────────────────────

function budget_result_html(array $r, array $deal): string {
    $diffCell = function (?float $d, ?float $pct, bool $invert = false): string {
        if ($d === null) return '<td class="num muted">—</td><td class="num muted">—</td>';
        $bad = $invert ? $d < -0.005 : $d > 0.005;
        $good = $invert ? $d > 0.005 : $d < -0.005;
        $cls = $bad ? 'neg b' : ($good ? 'pos b' : '');
        return '<td class="num ' . $cls . '">' . ($d > 0 ? '+' : '') . e(budget_eur($d)) . '</td>'
             . '<td class="num ' . $cls . '">' . ($pct === null ? '—' : ($pct > 0 ? '+' : '') . e(budget_pct($pct))) . '</td>';
    };
    $rows = '';
    foreach ($r['rows'] as $x) {
        $rows .= '<tr class="' . ($x['level'] === 'bad' ? 'br-bad' : '') . '"><td><b>' . e($x['label']) . '</b><small>' . e($x['who']) . '</small></td>'
               . '<td class="num">' . e(budget_eur($x['budget'])) . '</td>'
               . '<td class="num">' . ($x['real'] !== null ? e(budget_eur($x['real'])) : ($x['assumed'] ? '<span class="muted" data-tip="Equipa interna sem registo real: conta o orçado" tabindex="0">= orçado</span>' : '<span class="muted">sem custos</span>')) . '</td>'
               . $diffCell($x['diff'], $x['pct']) . '</tr>';
    }
    foreach ($r['outside'] as $x) {
        $rows .= '<tr class="br-bad"><td><b>' . e($x['label'] ?: 'Custo') . '</b><small>' . e(($x['who'] ? $x['who'] . ' · ' : '') . 'fora do orçamento') . '</small></td>'
               . '<td class="num muted">—</td><td class="num">' . e(budget_eur($x['real'])) . '</td>' . $diffCell($x['real'], null) . '</tr>';
    }
    $b = $r['budget'];
    $re = $r['real'];
    $dDays = $re['days'] !== null ? $re['days'] - $b['days'] : null;
    $tot = '<tr><td><b>Custos diretos</b></td><td class="num b">' . e(budget_eur($b['direct'])) . '</td><td class="num b">' . e(budget_eur($re['direct'])) . '</td>'
         . $diffCell(round($re['direct'] - $b['direct'], 2), $b['direct'] > 0 ? round(($re['direct'] - $b['direct']) / $b['direct'] * 100, 1) : null) . '</tr>'
         . '<tr><td><b>MSO usado</b><small>a derrapagem paga pela almofada de ' . e(budget_eur($b['mso'])) . '</small></td><td class="num">' . e(budget_eur($b['mso'])) . '</td>'
         . '<td class="num ' . ($r['mso_used'] > 100 ? 'neg b' : '') . '">' . e(budget_eur($r['overrun'])) . '</td><td class="num" colspan="2">' . (int)$r['mso_used'] . '% da MSO</td></tr>'
         . '<tr><td><b>Prazo</b><small>' . e($re['days'] !== null && $re['start'] ? 'de ' . date('d/m/Y', strtotime($re['start'])) . ' a ' . date('d/m/Y', strtotime($re['end'])) : 'o real começa a contar no Kickoff') . '</small></td>'
         . '<td class="num">' . e(budget_n($b['days'], 1)) . ' d</td><td class="num">' . ($re['days'] !== null ? e((string)$re['days']) . ' d' : '—') . '</td>'
         . '<td class="num ' . ($dDays !== null && $dDays > 0 ? 'neg b' : ($dDays !== null && $dDays < 0 ? 'pos b' : '')) . '" colspan="2">' . ($dDays === null ? '—' : ($dDays > 0 ? '+' : '') . e(budget_n($dDays, 1)) . ' d') . '</td></tr>'
         . '<tr><td><b>Preço</b><small>' . e($re['price_from'] === 'financeiro' ? 'real: o valor do negócio no Financeiro' : 'real: ainda o do orçamento') . '</small></td><td class="num">' . e(budget_eur($b['price'])) . '</td><td class="num">' . e(budget_eur($re['price'])) . '</td>'
         . $diffCell(round($re['price'] - $b['price'], 2), null, true) . '</tr>'
         . '<tr><td><b>Gross profit</b></td><td class="num">' . e(budget_eur($b['gp'])) . '</td><td class="num">' . e(budget_eur($re['gp'])) . '</td>' . $diffCell(round($re['gp'] - $b['gp'], 2), $b['gp'] != 0.0 ? round(($re['gp'] - $b['gp']) / abs($b['gp']) * 100, 1) : null, true) . '</tr>'
         . '<tr><td><b>Gross margin</b></td><td class="num">' . e(budget_pct($b['gm'])) . '</td><td class="num">' . e(budget_pct($re['gm'])) . '</td>'
         . '<td class="num ' . ($re['gm'] !== null && $b['gm'] !== null && $re['gm'] < $b['gm'] - 0.05 ? 'neg b' : '') . '" colspan="2">' . ($re['gm'] !== null && $b['gm'] !== null ? (($re['gm'] - $b['gm']) > 0 ? '+' : '') . e(budget_n(round($re['gm'] - $b['gm'], 1), 1)) . ' pp' : '—') . '</td></tr>'
         . '<tr><td><b>Lucro por mês</b></td><td class="num">' . e(budget_eur($b['profit_month'])) . '</td><td class="num">' . e(budget_eur($re['profit_month'])) . '</td>'
         . $diffCell(round($re['profit_month'] - $b['profit_month'], 2), $b['profit_month'] != 0.0 ? round(($re['profit_month'] - $b['profit_month']) / abs($b['profit_month']) * 100, 1) : null, true) . '</tr>';
    $worst = array_filter($r['rows'], fn($x) => $x['level'] === 'bad');
    usort($worst, fn($a, $b2) => $b2['diff'] <=> $a['diff']);
    $where = $worst ? 'Onde derrapou: ' . implode(', ', array_map(fn($x) => $x['label'] . ' (+' . budget_pct($x['pct']) . ')', array_slice($worst, 0, 3))) . '.' : 'Nenhuma linha acima do orçado.';
    $state = $r['closed'] ? 'Fechado na conclusão do negócio (' . date('d/m/Y', strtotime((string)$r['computed_at'])) . ').'
           : ($deal['phase'] === 'production' ? 'Em produção: o real vai-se somando com os custos do negócio no Financeiro.' : 'Ainda em orçamentação: o real começa com os custos registados no Financeiro.');
    return '<section class="lv-blk br-blk"><h4>Resultado · orçado vs real <span class="h-sub">v' . (int)$r['version'] . ($r['accepted'] ? ' aceite' : ' (ainda sem versão aceite)') . '</span></h4>'
         . '<div class="bl-wrap"><table class="br-t"><thead><tr><th>Linha</th><th class="num">Orçado</th><th class="num">Real</th><th class="num">Dif. €</th><th class="num">Dif. %</th></tr></thead><tbody>' . $rows . '</tbody>'
         . '<tbody class="br-tot">' . $tot . '</tbody></table></div>'
         . '<p class="hint">' . e($where) . ' ' . e($state) . ' Os custos ligam-se às linhas na ficha do negócio (Custos → «Linha do orçamento»).</p>'
         . '</section>';
}

// ── histórico ───────────────────────────────────────────────

function budget_history_html(PDO $pdo, int $sheetId): string {
    $items = [];
    foreach (budget_history($pdo, $sheetId) as $h) {
        $items[] = ['when' => $h['created_at'], 'type' => isset(HISTORY_TYPES[$h['event']]) ? $h['event'] : 'state',
                    'title' => HISTORY_TYPES[$h['event']][0] ?? $h['event'], 'text' => (string)$h['note'], 'who' => $h['who'] ?? 'Sistema', 'sys' => true];
    }
    return timeline_html($items);
}

// ── «+ Adicionar linha» (§13.8: sai do botão) ──────────────

function budget_line_modal_html(PDO $pdo, array $v, bool $money): string {
    $svc = '<option value="">Escolher serviço…</option>';
    foreach (services_list($pdo) as $s) $svc .= '<option value="' . (int)$s['id'] . '">' . e($s['name']) . '</option>';
    $team = '<option value="">Escolher pessoa…</option>';
    foreach ($pdo->query('SELECT id, name, job_title, hourly_cost FROM users WHERE is_active = 1 ORDER BY name')->fetchAll() as $u) {
        $has = $u['hourly_cost'] !== null && (float)$u['hourly_cost'] > 0;
        $team .= '<option value="' . (int)$u['id'] . '" data-title="' . e((string)$u['job_title']) . '"' . ($has ? '' : ' disabled') . '>'
               . e($u['name'] . ($has ? ($money ? ' · ' . budget_n((float)$u['hourly_cost']) . ' €/h' : '') : ' · sem custo/hora na Equipa')) . '</option>';
    }
    $kinds = '';
    foreach (BUDGET_LINE_KINDS as $k => $label) {
        $kinds .= '<label class="rb"><input type="radio" name="kind" value="' . $k . '"' . ($k === 'supplier' ? ' checked' : '') . '><span>' . icon($k === 'supplier' ? 'contactos' : ($k === 'team' ? 'equipa' : 'box')) . ' ' . e($label) . '</span></label>';
    }
    $qu = '';
    foreach (BUDGET_QTY_UNITS as $k => $u) $qu .= '<option value="' . $k . '"' . ($k === 'day' ? ' selected' : '') . '>' . $u . '</option>';
    $ou = '';
    foreach (BUDGET_OTHER_UNITS as $k => $u) $ou .= '<option value="' . $k . '">' . e($u) . '</option>';
    return '<div class="mdl" id="mdl-bline" data-from="[data-bl-add]" role="dialog" aria-modal="true" aria-labelledby="mdl-bline-t" hidden>'
         . '<div class="mdl-back" data-mdl-close></div>'
         . '<form class="mdl-card wide bline-form" data-bline-form autocomplete="off" data-money="' . ($money ? '1' : '0') . '">'
         . '<input type="hidden" name="version" value="' . (int)$v['id'] . '"><input type="hidden" name="line" value="">'
         . '<h3 id="mdl-bline-t" data-bl-title>Adicionar linha</h3>'
         . '<div class="chips-check kind-pick" role="radiogroup" aria-label="Tipo de linha">' . $kinds . '</div>'
         . '<div class="bl-sec" data-sec="supplier">'
         . '<div class="field"><label for="bl-svc">Serviço</label><select id="bl-svc" name="service_id">' . $svc . '</select></div>'
         . '<div class="bl-sup" data-sup-list role="radiogroup" aria-label="Fornecedor"><p class="muted small" style="margin:0">Escolha o serviço para ver os fornecedores ativos que o prestam.</p></div>'
         . '</div>'
         . '<div class="bl-sec" data-sec="team" hidden>'
         . '<div class="grid"><div class="field"><label for="bl-user">Pessoa da equipa</label><select id="bl-user" name="user_id">' . $team . '</select></div>'
         . '<div class="field"><label for="bl-tl">Descrição</label><input type="text" id="bl-tl" name="team_label" maxlength="160" placeholder="Direção criativa, produção…"></div></div>'
         . '<p class="muted small" style="margin:0">Custo interno: o custo/hora de cada pessoa na aba Equipa.</p>'
         . '</div>'
         . '<div class="bl-sec" data-sec="other" hidden>'
         . '<div class="grid"><div class="field wide"><label for="bl-ol">Descrição</label><input type="text" id="bl-ol" name="other_label" maxlength="160" placeholder="Render farm, licenças, deslocação…"></div>'
         . '<div class="field"><label for="bl-ou">Unidade</label><select id="bl-ou" name="price_unit">' . $ou . '</select></div>'
         . '<div class="field"><label for="bl-op">Custo (€)</label><input type="text" id="bl-op" name="unit_price" inputmode="decimal" maxlength="12" placeholder="400"></div></div>'
         . '</div>'
         . '<div class="grid bl-qty" data-qty-row><div class="field"><label for="bl-q">Quantidade</label><input type="text" id="bl-q" name="qty" inputmode="decimal" maxlength="9" placeholder="10"></div>'
         . '<div class="field" data-qu-time><label for="bl-qu">Em</label><select id="bl-qu" name="qty_unit">' . $qu . '</select></div>'
         . '<div class="field" data-qu-fixed hidden><span class="lbl">Unidade</span><p class="bl-fixu" data-fixed-unit></p></div></div>'
         . '<div class="field"><label for="bl-note">Nota <small class="muted">(opcional)</small></label><input type="text" id="bl-note" name="note" maxlength="255" placeholder="Inclui 2 rondas de alterações…"></div>'
         . '<p class="bl-prev" data-bl-prev aria-live="polite"></p>'
         . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><button class="btn" type="submit">' . icon('check') . ' <span data-bl-submit>Adicionar linha</span></button></div>'
         . '</form></div>';
}

/** Guardar como modelo · Aplicar modelo · Comparar versões. */
function budget_small_modals_html(PDO $pdo, array $sheet, array $v, array $versions): string {
    $tpls = budget_templates($pdo);
    $opts = '';
    foreach ($tpls as $t) {
        $n = count(json_decode((string)$t['data'], true)['lines'] ?? []);
        $opts .= '<label class="opt bt-opt"><input type="radio" name="template" value="' . (int)$t['id'] . '" required><b>' . e($t['name']) . '</b><small>' . $n . ($n === 1 ? ' linha' : ' linhas') . ($t['author'] ? ' · ' . e($t['author']) : '') . '</small></label>';
    }
    $vopt = fn($sel) => implode('', array_map(fn($x) => '<option value="' . (int)$x['num'] . '"' . ((int)$x['num'] === $sel ? ' selected' : '') . '>v' . (int)$x['num'] . ((int)$sheet['accepted_version_id'] === (int)$x['id'] ? ' · aceite' : '') . '</option>', $versions));
    $nums = array_map(fn($x) => (int)$x['num'], $versions);
    $prev = count($nums) > 1 ? $nums[count($nums) - 2] : $nums[0];
    return '<div class="mdl" id="mdl-btpl-save" role="dialog" aria-modal="true" aria-labelledby="mdl-bts-t" hidden>'
         . '<div class="mdl-back" data-mdl-close></div>'
         . '<form class="mdl-card" data-btpl-save autocomplete="off"><input type="hidden" name="version" value="' . (int)$v['id'] . '">'
         . '<h3 id="mdl-bts-t">Guardar como modelo</h3><p class="muted">As linhas e a duração desta versão. Os preços não ficam no modelo: ao aplicar, vêm os do preçário e da Equipa desse dia.</p>'
         . '<div class="field"><label for="bts-n">Nome do modelo</label><input type="text" id="bts-n" name="name" maxlength="120" placeholder="Spot 3D 30s" required></div>'
         . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><button class="btn" type="submit">' . icon('checklist') . ' Guardar modelo</button></div>'
         . '</form></div>'
         . '<div class="mdl" id="mdl-btpl-apply" role="dialog" aria-modal="true" aria-labelledby="mdl-bta-t" hidden>'
         . '<div class="mdl-back" data-mdl-close></div>'
         . '<form class="mdl-card" data-btpl-apply autocomplete="off"><input type="hidden" name="sheet" value="' . (int)$sheet['id'] . '">'
         . '<h3 id="mdl-bta-t">Criar a partir de um modelo</h3>'
         . ($tpls ? '<p class="muted">Numa versão ainda vazia, preenche-a; senão cria uma versão nova. Fornecedores que deixaram de estar ativos ficam de fora.</p><div class="opts bt-opts">' . $opts . '</div>'
                  : '<p class="muted">Ainda não há modelos. Numa folha completa, use «Guardar como modelo».</p>')
         . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button>' . ($tpls ? '<button class="btn" type="submit">' . icon('checklist') . ' Aplicar</button>' : '') . '</div>'
         . '</form></div>'
         . (count($versions) > 1 ? '<div class="mdl" id="mdl-bcmp" role="dialog" aria-modal="true" aria-labelledby="mdl-bcmp-t" hidden>'
         . '<div class="mdl-back" data-mdl-close></div>'
         . '<form class="mdl-card" method="get" action="/admin/orcamento.php"><input type="hidden" name="id" value="' . (int)$sheet['deal_id'] . '"><input type="hidden" name="tab" value="cmp">'
         . '<h3 id="mdl-bcmp-t">Comparar versões</h3>'
         . '<div class="grid"><div class="field"><label for="bc-a">Versão</label><select id="bc-a" name="a">' . $vopt($prev) . '</select></div>'
         . '<div class="field"><label for="bc-b">com</label><select id="bc-b" name="b">' . $vopt((int)$v['num'] === $prev ? end($nums) : (int)$v['num']) . '</select></div></div>'
         . '<div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><button class="btn" type="submit">' . icon('arrows') . ' Comparar</button></div>'
         . '</form></div>' : '');
}

// ── comparar versões lado a lado (§14.6) ───────────────────

function budget_compare_html(PDO $pdo, array $va, array $vb, bool $money): string {
    $la = budget_lines($pdo, (int)$va['id']);
    $lb = budget_lines($pdo, (int)$vb['id']);
    $ca = budget_calc($pdo, $va, $la);
    $cb = budget_calc($pdo, $vb, $lb);
    $keys = [];
    foreach (array_merge($la, $lb) as $l) $keys[$l['line_key']] = $l['label'] . ' · ' . budget_line_who($l);
    $by = fn(array $ls) => array_column($ls, null, 'line_key');
    $A = $by($la); $B = $by($lb);
    $cell = function (?array $l) use ($money) {
        if (!$l) return '<td class="muted">—</td>';
        $q = budget_qty_label($l);
        return '<td>' . e($q) . ($money || $l['kind'] === 'other' ? ' <span class="muted">·</span> <b class="mono">' . e(budget_eur((float)$l['cost'])) . '</b>' : '') . '</td>';
    };
    $rows = '';
    foreach ($keys as $k => $label) {
        $a = $A[$k] ?? null; $b = $B[$k] ?? null;
        $chg = !$a || !$b || (float)$a['cost'] !== (float)$b['cost'] || (float)$a['qty'] !== (float)$b['qty'];
        $rows .= '<tr class="' . ($chg ? 'cmp-chg' : '') . '"><td>' . e($label) . '</td>' . $cell($a) . $cell($b) . '</tr>';
    }
    $sum = function (string $label, string $x, string $y) {
        return '<tr class="' . ($x !== $y ? 'cmp-chg' : '') . '"><td><b>' . e($label) . '</b></td><td class="mono">' . e($x) . '</td><td class="mono">' . e($y) . '</td></tr>';
    };
    $tot = $sum('Duração', budget_n($ca['days'], 1) . ' d', budget_n($cb['days'], 1) . ' d')
         . $sum('% de ocupação', budget_pct($ca['occupancy']), budget_pct($cb['occupancy']));
    if ($money) {
        $tot .= $sum('Custos diretos', budget_eur($ca['direct']), budget_eur($cb['direct']))
              . $sum('Custo de estrutura', budget_eur($ca['structure']), budget_eur($cb['structure']))
              . $sum('Base', budget_eur($ca['base']), budget_eur($cb['base']))
              . $sum('Margem', budget_pct($ca['margin']), budget_pct($cb['margin']));
    }
    $tot .= $sum('Preço s/ IVA', budget_eur($ca['price']), budget_eur($cb['price']));
    if ($money) $tot .= $sum('Lucro por mês', budget_eur($ca['profit_month']), budget_eur($cb['profit_month']));
    $head = fn(array $v) => 'v' . (int)$v['num'] . ' <small class="muted">' . e(date('d/m/Y', strtotime((string)$v['created_at'])) . ($v['author'] ? ' · ' . $v['author'] : '')) . '</small>';
    return '<section class="lv-blk cmp-blk"><h4>Comparar versões</h4><div class="bl-wrap"><table class="cmp-t"><thead><tr><th>Linha</th><th>' . $head($va) . '</th><th>' . $head($vb) . '</th></tr></thead>'
         . '<tbody>' . $rows . '</tbody><tbody class="br-tot">' . $tot . '</tbody></table></div>'
         . '<p class="hint">As linhas diferentes ficam marcadas. As linhas são as mesmas entre versões quando nasceram uma da outra.</p></section>';
}
