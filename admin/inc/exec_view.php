<?php
/**
 * topper. admin — peças de ecrã do Dashboard Executivo (§30.7): cartões de KPI (número grande, ▲▼ e mini-linha),
 * semáforos, cascata do P&L, barras de Revenue com a linha da Gross Margin, previsão do ano, mix a 100% e o pódio.
 * A linha da §11 e de inc/charts.php: um eixo por gráfico, ponta arredondada, dicas com os valores exatos (rato e
 * teclado), entrada animada, claro/escuro pelos tokens. Sem circulares nem velocímetros.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/exec.php';
require_once __DIR__ . '/metrics_view.php';

/** Um número que leva aos registos que o compõem (Relatórios), ou à página que o explica para quem não vê os Relatórios. */
function exec_drill(string $label, array $ids, string $title, string $fallback = ''): string {
    if ($ids && can('relatorios')) return mx_a($label, $ids, $title, 'd');
    if ($fallback !== '') return '<a class="mx-n" href="' . e($fallback) . '">' . e($label) . '</a>';
    return '<span>' . e($label) . '</span>';
}

/** ▲▼ contra um valor anterior (dica com o valor e o período). */
function exec_delta(?float $now, ?float $prev, string $label, string $good = 'up', bool $points = false, ?callable $fmt = null): string {
    if ($now === null || $prev === null) return '';
    $fmt ??= fn($v) => eur0((float)$v);
    [$t, $cls] = mx_delta($now, $prev, $good, $points);
    return '<span class="mx-d ' . $cls . '" tabindex="0" data-tip="' . e('Em ' . $label . ': ' . ($points ? mx_pct($prev) : $fmt($prev))) . '">' . e($t) . '</span>';
}

/** «41%» (ou «—»). */
function exec_pct(?float $v): string {
    return $v === null ? '—' : str_replace('.', ',', (string)round($v)) . '%';
}

/** Valor com sinal para resultados (−€1 200). */
function exec_eur(float $v): string {
    return ($v < 0 ? '−' : '') . eur0(abs($v));
}

/**
 * Um cartão do P&L: rótulo e ⓘ, o número (aos registos), ▲▼ contra o período anterior e o do ano anterior, a margem
 * e a mini-linha de 12 meses. $c: k, info, v, ids, title, fallback, now, prev, yoy, margin, margin_prev, margin_label,
 * spark, class, note.
 */
function exec_kpi_card(array $c, array $per): string {
    $v = exec_drill(exec_eur((float)$c['now']), $c['ids'] ?? [], $c['title'] ?? $c['k'], $c['fallback'] ?? '');
    $d = exec_delta((float)$c['now'], $c['prev'], $per['prev_label']) . ($c['yoy'] !== null && $per['yoy_label'] !== '' ? ' ' . exec_delta((float)$c['now'], $c['yoy'], $per['yoy_label']) : '');
    $m = '';
    if (array_key_exists('margin', $c)) {
        $m = '<div class="xk-m"><b>' . e(exec_pct($c['margin'])) . '</b> ' . e($c['margin_label']) . ' '
           . ($c['margin'] !== null && $c['margin_prev'] !== null ? exec_delta($c['margin'], $c['margin_prev'], $per['prev_label'], 'up', true) : '') . '</div>';
    }
    return '<div class="stat mx-kpi xk ' . e($c['class'] ?? '') . '"><div class="k">' . e($c['k']) . ' ' . mx_ii($c['info']) . '</div>'
        . '<div class="v">' . $v . '</div><div class="xk-d">' . $d . '</div>' . $m
        . (($c['note'] ?? '') !== '' ? '<div class="n">' . $c['note'] . '</div>' : '')
        . '<div class="sp">' . chart_spark(array_values(array_map(fn($x) => (float)$x, $c['spark'])), $c['k'] . ', 12 meses', 120, 26) . '</div></div>';
}

/** Um semáforo: ponto com a cor e o estado escrito, a frase e a ligação ao relatório certo. */
function exec_light_html(array $h): string {
    $label = ['ok' => 'Verde', 'warn' => 'Amarelo', 'bad' => 'Vermelho', 'none' => 'Sem dados'][$h['state']] ?? '';
    return '<a class="xl st-' . e($h['state']) . '" href="' . e($h['url']) . '"><span class="xl-h"><i class="xl-dot" aria-hidden="true"></i><b>' . e($h['title']) . '</b>'
        . '<span class="xl-s">' . e($label) . '</span></span><span class="xl-t">' . e($h['text']) . '</span><span class="xl-go">Ver →</span></a>';
}

/**
 * A cascata do P&L: Revenue → custos diretos → Gross Profit → operacionais → EBITDA → amortizações → EBIT → juros →
 * impostos → Net Profit. Totais a azul (desde o zero), deduções a coral (a flutuar), negativos abaixo do zero.
 */
function chart_waterfall(array $pl, string $label): string {
    // [rótulo curto no eixo, nome completo na dica, tipo, valor]
    $steps = [['Revenue', 'Revenue', 'tot', $pl['revenue']], ['Diretos', 'Custos diretos', 'dn', $pl['direct']], ['GP', 'Gross Profit', 'tot', $pl['gp']],
              ['Operac.', 'Custos operacionais', 'dn', $pl['opex']], ['EBITDA', 'EBITDA', 'tot', $pl['ebitda']], ['Amort.', 'Amortizações', 'dn', $pl['da']], ['EBIT', 'EBIT', 'tot', $pl['ebit']]];
    if (abs((float)$pl['interest']) >= 0.5) $steps[] = ['Juros', 'Juros e encargos financeiros', 'dn', $pl['interest']];
    $steps[] = ['Impostos', 'IRC estimado e tributação autónoma', 'dn', $pl['irc'] + $pl['ta']];
    $steps[] = ['Net', 'Net Profit', 'tot', $pl['net']];
    $W = 560; $H = 230; $L = 50; $R = 8; $T = 18; $B = 30;
    $lev = [0.0]; $run = 0.0; $bars = [];
    foreach ($steps as [$l, $full, $k, $v]) {
        $v = (float)$v;
        if ($k === 'tot') { $bars[] = [$l, $k, 0.0, $v, $v, $full]; $run = $v; }
        else { $bars[] = [$l, $k, $run, $run - $v, $v, $full]; $run -= $v; }
        array_push($lev, $bars[count($bars) - 1][2], $bars[count($bars) - 1][3]);
    }
    $max = nice_max(max($lev)); $minV = min($lev); $min = $minV < 0 ? -nice_max(-$minV) : 0.0;
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - ($v - $min) / ($max - $min));
    $n = count($bars); $slot = ($W - $L - $R) / $n; $bw = min(30, $slot * .58);
    $h = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . '">';
    foreach (array_unique([$min, 0.0, $max / 2, $max]) as $v) {
        $h .= '<line class="gl' . ($v == 0.0 && $min < 0 ? ' zero' : '') . '" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y($v) . '" y2="' . $y($v) . '"/>'
            . '<text x="' . ($L - 8) . '" y="' . ($y($v) + 3.5) . '" text-anchor="end">' . e(($v < 0 ? '−' : '') . eur_compact(abs($v))) . '</text>';
    }
    $prevX = null; $prevY = null;
    foreach ($bars as $i => [$l, $k, $a, $b, $v, $full]) {
        $cx = $L + $slot * ($i + .5);
        $top = $y(max($a, $b)); $bot = $y(min($a, $b));
        $cls = $k === 'tot' ? ($v < 0 ? 'wf-neg' : 'wf-tot') : 'wf-dn';
        $tip = $full . ' · ' . ($k === 'dn' ? ($v > 0 ? '−' : ($v < 0 ? '+' : '')) . eur0(abs($v)) : exec_eur($v));
        $hh = max(1.0, $bot - $top);
        $h .= '<g class="col">' . ($prevY !== null ? '<line class="wf-c" x1="' . round($prevX, 1) . '" x2="' . round($cx - $bw / 2, 1) . '" y1="' . round($prevY, 1) . '" y2="' . round($prevY, 1) . '"/>' : '')
            . '<rect class="' . $cls . '" x="' . round($cx - $bw / 2, 1) . '" y="' . round($top, 1) . '" width="' . round($bw, 1) . '" height="' . round($hh, 1) . '" rx="3"/>'
            . '<text class="wf-v" x="' . round($cx, 1) . '" y="' . round($top - 5, 1) . '" text-anchor="middle">' . e((abs($v) >= 0.5 && ($k === 'dn' ? $v > 0 : $v < 0) ? '−' : ($k === 'dn' && $v < 0 ? '+' : '')) . eur_compact(abs($v))) . '</text>'
            . '<text x="' . round($cx, 1) . '" y="' . ($H - 12) . '" text-anchor="middle"' . ($k === 'tot' ? ' class="cur"' : '') . '>' . e($l) . '</text>'
            . '<rect class="hit" x="' . round($cx - $slot / 2, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . ($H - $T - $B) . '" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"/></g>';
        $prevX = $cx + $bw / 2; $prevY = $y($k === 'tot' ? $v : $b);
    }
    return $h . '</svg>';
}

/**
 * Barras mensais de Revenue (12 meses) com a linha da Gross Margin %. Um só eixo (o dos euros): a linha usa a
 * sua própria escala de 0 a 100% e cada ponto diz o valor na dica; o último leva a etiqueta.
 */
function chart_rev_margin(array $rev, array $gm, string $label): string {
    $W = 560; $H = 220; $L = 50; $R = 30; $T = 14; $B = 24;
    $months = array_keys($rev);
    $max = nice_max(max(array_merge([0.0], array_map('floatval', array_values($rev)))));
    $n = max(1, count($months)); $slot = ($W - $L - $R) / $n; $bw = min(18, $slot * .5);
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - max(0, $v) / $max);
    $ym = fn(float $p) => $T + ($H - $T - $B) * (1 - max(0, min(100, $p)) / 100);
    $h = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . '">';
    foreach ([0, .5, 1] as $f) $h .= '<line class="gl" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y($max * $f) . '" y2="' . $y($max * $f) . '"/><text x="' . ($L - 8) . '" y="' . ($y($max * $f) + 3.5) . '" text-anchor="end">' . e(eur_compact($max * $f)) . '</text>';
    $pts = [];
    foreach ($months as $i => $k) {
        $cx = $L + $slot * ($i + .5);
        $v = (float)$rev[$k];
        $g = $gm[$k] ?? null;
        $tip = chart_month_label($k, true) . ' · revenue ' . eur0($v) . ' · gross margin ' . exec_pct($g);
        $h .= '<g class="col"><path class="bar-m' . ($k === exec_ym() ? ' now' : '') . '" d="' . bar_path($cx - $bw / 2, $y($v), $bw, $y(0) - $y($v)) . '"/>'
            . '<text x="' . $cx . '" y="' . ($H - 8) . '" text-anchor="middle">' . e(chart_month_label($k)) . '</text>'
            . '<rect class="hit" x="' . round($cx - $slot / 2, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . ($H - $T - $B) . '" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"/></g>';
        if ($g !== null) $pts[] = [round($cx, 1), round($ym((float)$g), 1), $g];
    }
    if (count($pts) > 1) $h .= '<polyline class="ln alt gm" points="' . implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $pts)) . '"/>';
    foreach ($pts as $p) $h .= '<circle class="pt alt" cx="' . $p[0] . '" cy="' . $p[1] . '" r="2.8"/>';
    if ($pts) { $l = end($pts); $h .= '<text class="lb gm-l" x="' . ($l[0] + 6) . '" y="' . ($l[1] + 4) . '">' . e(exec_pct($l[2])) . '</text>'; }
    return $h . '</svg>';
}

/**
 * A previsão do ano numa barra: realizado · ganho por faturar · pipeline ponderado · tendência, a banda pessimista–
 * otimista por baixo e a meta anual (traço coral). Com $money = false não é chamada (sem euros).
 */
function exec_forecast_html(array $f): string {
    $max = max($f['opt'], $f['goal'], $f['total'], 1.0) * 1.06;
    $p = fn(float $v) => round(max(0, min(100, $v / $max * 100)), 2);
    $parts = [['fc-real', 'Faturado', $f['realized']], ['fc-back', 'Ganho por faturar', $f['backlog']], ['fc-pipe', 'Pipeline ponderado', $f['weighted']], ['fc-trend', 'Tendência', $f['trend']]];
    $h = '<div class="fc" role="group" aria-label="Previsão até ao fim do ano"><div class="fc-bar">';
    foreach ($parts as $i => [$c, $l, $v]) if ($v > 0) $h .= '<span class="' . $c . '" style="width:' . $p($v) . '%;animation-delay:' . ($i * 80) . 'ms" tabindex="0" data-tip="' . e($l . ' · ' . eur0($v)) . '" aria-label="' . e($l . ' ' . eur0($v)) . '"></span>';
    if ($f['goal'] > 0) $h .= '<i class="fc-goal" style="left:' . $p($f['goal']) . '%" data-tip="' . e('Meta anual · ' . eur0($f['goal'])) . '" tabindex="0"></i>';
    $h .= '</div><div class="fc-band"><span style="left:' . $p($f['pess']) . '%;width:' . max(0.5, $p($f['opt']) - $p($f['pess'])) . '%" data-tip="' . e('Entre ' . eur0($f['pess']) . ' (pessimista) e ' . eur0($f['opt']) . ' (otimista)') . '" tabindex="0"></span></div>';
    $h .= '<div class="fc-lg">';
    foreach ($parts as [$c, $l, $v]) $h .= '<span><i class="' . $c . '"></i>' . e($l) . ' <b>' . e(eur0($v)) . '</b></span>';
    if ($f['goal'] > 0) $h .= '<span><i class="fc-goal-k"></i>Meta ' . e(eur0($f['goal'])) . '</span>';
    return $h . '</div></div>';
}

/** Barra empilhada a 100% (mix por funil / serviço): $parts [label => [value, ids]]; $fmt diz o valor na dica. */
function chart_stack100(array $parts, string $label, callable $fmt): string {
    $parts = array_filter($parts, fn($p) => (float)$p[0] > 0);
    $tot = array_sum(array_map(fn($p) => (float)$p[0], $parts));
    if ($tot <= 0) return '<p class="muted small">Sem negócios ganhos neste período.</p>';
    $h = '<div class="s100" role="group" aria-label="' . e($label) . '"><div class="s100-bar">';
    $i = 0; $lg = '';
    foreach ($parts as $l => [$v, $ids]) {
        $pc = (float)$v / $tot * 100;
        $tip = $l . ' · ' . str_replace('.', ',', (string)round($pc)) . '% · ' . $fmt((float)$v);
        $seg = '<span class="c' . ($i % 6) . '" style="flex:' . round(max($pc, .8), 2) . ' 1 0;animation-delay:' . ($i * 60) . 'ms" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '" tabindex="0"></span>';
        $h .= $seg;
        $lg .= '<span><i class="c' . ($i % 6) . '"></i>' . exec_drill($l, $ids, $label . ' · ' . $l) . ' <b>' . str_replace('.', ',', (string)round($pc)) . '%</b></span>';
        $i++;
    }
    return $h . '</div><div class="s100-lg">' . $lg . '</div></div>';
}

/** As iniciais/fotos de uma equipa (pequenas). */
function exec_people_html(PDO $pdo, array $ids): string {
    if (!$ids) return '';
    require_once __DIR__ . '/team.php';
    $in = implode(',', array_map('intval', $ids));
    $h = '<span class="xp">';
    foreach ($pdo->query("SELECT * FROM users WHERE id IN ($in)")->fetchAll() as $u) $h .= '<span data-tip="' . e($u['name']) . '" tabindex="0">' . team_avatar($u, 'sm', false) . '</span>';
    return $h . '</span>';
}

/** O pódio (2.º · 1.º · 3.º): código, negócio, cliente, funil, mês, equipa; euros só com $money. $fresh: o aviso recente (entrada ou recorde) que anima o pódio uma vez. */
function exec_podium_html(PDO $pdo, array $top, bool $money, int $fresh = 0): string {
    if (!$top) return '<p class="muted">Ainda não há negócios ganhos com valor para o pódio.</p>';
    $order = [1, 0, 2];
    $h = '<ol class="podium"' . ($fresh ? ' data-hof="' . $fresh . '"' : '') . ' aria-label="Os 3 negócios de sempre com mais gross profit">';
    foreach ($order as $i) {
        if (!isset($top[$i])) { $h .= '<li class="pd p' . ($i + 1) . ' empty" aria-hidden="true"></li>'; continue; }
        $r = $top[$i];
        $h .= '<li class="pd p' . ($i + 1) . '" style="order:' . ($i === 0 ? 2 : ($i === 1 ? 1 : 3)) . '"><a href="' . e(mx_url(metrics_deals($pdo)[$r['id']] ?? ['phase' => 'done', 'id' => $r['id'], 'fu' => 0])) . '">'
            . '<span class="pd-n">' . ($i + 1) . '.º</span><span class="pd-code mono">' . e($r['code']) . '</span><b class="pd-name">' . e($r['name']) . '</b>'
            . '<span class="pd-meta">' . e($r['client']) . ' · ' . e($r['funnel']) . ' · ' . e(exec_month_label($r['ym'], true)) . '</span>'
            . ($money ? '<span class="pd-v">' . e(eur0($r['gp'])) . '</span>' : '')
            . ($r['provisional'] ? '<span class="chip wait pd-prov" data-tip="Conta o gross profit real depois da entrega; até lá é provisório.">provisório</span>' : '')
            . '</a>' . exec_people_html($pdo, $r['people']) . '<span class="pd-step" aria-hidden="true"></span></li>';
    }
    return $h . '</ol>';
}
