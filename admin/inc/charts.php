<?php
/**
 * topper. admin — gráficos simples em SVG, desenhados no servidor
 *
 * Um só eixo por gráfico (nunca dois); traço fino; barras até 24px com a
 * ponta arredondada e a base direita; grelha num cinzento discreto. Azul
 * é o valor real, coral é a meta — as mesmas cores nos dois gráficos, com
 * tons próprios em cada modo (tokens --c-real e --c-goal no app.css,
 * validados contra a superfície de cada modo). Cada coluna tem uma dica
 * ao passar o cursor ou com o teclado, e a tabela por baixo tem os mesmos
 * valores para quem não vê o gráfico.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

const MONTHS_SHORT = [1 => 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

/** Um máximo «redondo» para o eixo (1, 2, 2,5 ou 5 × 10ⁿ). */
function nice_max(float $v): float {
    if ($v <= 0) return 1000;
    $p = 10 ** floor(log10($v));
    foreach ([1, 2, 2.5, 5, 10] as $m) if ($v <= $m * $p) return $m * $p;
    return 10 * $p;
}

/** €0 · €12K · €1,2M — compacto para os eixos. */
function eur_compact(float $v): string {
    if ($v >= 1e6) return '€' . rtrim(rtrim(number_format($v / 1e6, 1, ',', ''), '0'), ',') . 'M';
    if ($v >= 1e3) return '€' . rtrim(rtrim(number_format($v / 1e3, 1, ',', ''), '0'), ',') . 'K';
    return '€' . number_format($v, 0, ',', ' ');
}

/** Ponta de cima arredondada (4px), base direita. */
function bar_path(float $x, float $y, float $w, float $h): string {
    if ($h <= 0) return '';
    $r = min(4, $w / 2, $h);
    return sprintf('M%.1f %.1fV%.1fQ%.1f %.1f %.1f %.1fH%.1fQ%.1f %.1f %.1f %.1fV%.1fZ',
        $x, $y + $h, $y + $r, $x, $y, $x + $r, $y, $x + $w - $r, $x + $w, $y, $x + $w, $y + $r, $y + $h);
}

/**
 * Colunas por mês, com a meta do mês como referência.
 * $values: mês (1–12) → valor. $goal: valor por mês (0 = sem meta). $now: mês a destacar (0 = nenhum).
 */
function chart_months(array $values, float $goal, int $now, string $label): string {
    $W = 480; $H = 210; $L = 46; $R = 8; $T = 12; $B = 24;
    $max = nice_max(max(array_merge([$goal], array_values($values), [0])));
    $slot = ($W - $L - $R) / 12;
    $bw = min(24, $slot * 0.5);
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - $v / $max);
    $h = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . '">';
    foreach ([0, .5, 1] as $f) {
        $yy = $y($max * $f);
        $h .= '<line class="gl" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text x="' . ($L - 8) . '" y="' . ($yy + 3.5) . '" text-anchor="end">' . e(eur_compact($max * $f)) . '</text>';
    }
    for ($m = 1; $m <= 12; $m++) {
        $v = (float)($values[$m] ?? 0);
        $cx = $L + $slot * ($m - 0.5);
        $top = $y($v);
        $tip = month_name($m) . ' · ' . eur0($v) . ($goal > 0 ? ' · meta ' . eur0($goal) : '');
        $h .= '<g class="col">'
            . '<path class="bar-m' . ($m === $now ? ' now' : '') . '" d="' . bar_path($cx - $bw / 2, $top, $bw, $y(0) - $top) . '"/>'
            . '<text x="' . $cx . '" y="' . ($H - 8) . '" text-anchor="middle"' . ($m === $now ? ' class="cur"' : '') . '>' . MONTHS_SHORT[$m] . '</text>'
            . '<rect class="hit" x="' . ($cx - $slot / 2) . '" y="' . $T . '" width="' . $slot . '" height="' . ($H - $T - $B) . '" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"/>'
            . '</g>';
    }
    if ($goal > 0) {
        $gy = $y($goal);
        $h .= '<line class="goal" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $gy . '" y2="' . $gy . '"/>';
    }
    return $h . '</svg>';
}

/**
 * O acumulado do ano contra o ritmo da meta (linear até dezembro).
 * $values: mês → valor do mês; $upto: último mês com dados reais (12 num ano fechado).
 */
function chart_cumulative(array $values, float $goal, int $upto, string $label): string {
    $W = 480; $H = 210; $L = 46; $R = 64; $T = 12; $B = 24;
    $cum = []; $run = 0.0;
    for ($m = 1; $m <= 12; $m++) { $run += (float)($values[$m] ?? 0); $cum[$m] = $run; }
    $max = nice_max(max($goal, $cum[12]));
    $step = ($W - $L - $R) / 11;
    $x = fn(int $m) => $L + $step * ($m - 1);
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - $v / $max);
    $h = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . '">';
    foreach ([0, .5, 1] as $f) {
        $yy = $y($max * $f);
        $h .= '<line class="gl" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text x="' . ($L - 8) . '" y="' . ($yy + 3.5) . '" text-anchor="end">' . e(eur_compact($max * $f)) . '</text>';
    }
    for ($m = 1; $m <= 12; $m += ($m === 1 ? 2 : 3)) {
        $h .= '<text x="' . $x($m) . '" y="' . ($H - 8) . '" text-anchor="middle">' . MONTHS_SHORT[$m] . '</text>';
    }
    $h .= '<text x="' . $x(12) . '" y="' . ($H - 8) . '" text-anchor="middle">dez</text>';
    if ($goal > 0) {
        $h .= '<line class="goal" x1="' . $x(1) . '" y1="' . $y($goal / 12) . '" x2="' . $x(12) . '" y2="' . $y($goal) . '"/>'
            . '<text class="lb" x="' . ($x(12) + 8) . '" y="' . ($y($goal) + 4) . '">Meta ' . e(eur_compact($goal)) . '</text>';
    }
    $upto = max(1, min(12, $upto));
    $pts = [];
    for ($m = 1; $m <= $upto; $m++) $pts[] = round($x($m), 1) . ',' . round($y($cum[$m]), 1);
    $h .= '<path class="area" d="M' . $x(1) . ',' . $y(0) . ' L' . implode(' L', $pts) . ' L' . $x($upto) . ',' . $y(0) . 'Z"/>'
        . '<polyline class="cum" points="' . implode(' ', $pts) . '"/>'
        . '<circle class="end" cx="' . $x($upto) . '" cy="' . $y($cum[$upto]) . '" r="4.5"/>';
    $ly = $y($cum[$upto]) + 4;
    if ($goal > 0 && abs($ly - ($y($goal) + 4)) < 14 && $upto === 12) $ly += 14;
    $h .= '<text class="lb" x="' . ($x($upto) + 8) . '" y="' . $ly . '">' . e(eur_compact($cum[$upto])) . '</text>';
    for ($m = 1; $m <= 12; $m++) {
        $tip = 'Até ' . month_name($m) . ' · ' . ($m <= $upto ? eur0($cum[$m]) : '—') . ($goal > 0 ? ' · meta ' . eur0($goal * $m / 12) : '');
        $h .= '<rect class="hit" x="' . ($x($m) - $step / 2) . '" y="' . $T . '" width="' . $step . '" height="' . ($H - $T - $B) . '" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"/>';
    }
    return $h . '</svg>';
}

// ── Financeiro (§28.12) ─────────────────────────────────────
//
// A mesma linha: um só eixo, barras com a ponta arredondada, grelha discreta, dica em cada coluna
// (rato e teclado) e a tabela equivalente ao lado. Entradas em azul (--c-real), saídas em coral
// (--c-goal), o saldo numa linha fina; o que é previsão vai a tracejado.

/** Etiqueta curta de um mês AAAA-MM: «out» (ou «jan 27» na viragem do ano). */
function chart_month_label(string $ym, bool $year = false): string {
    $m = (int)substr($ym, 5, 2);
    return MONTHS_SHORT[$m] . ($year ? ' ' . substr($ym, 2, 2) : '');
}

/**
 * Runway: uma barra horizontal — os meses úteis (amarelo da marca) e a reserva de N meses (tracejada) — com o
 * número grande e a data. $useful pode ser negativo (já dentro da reserva).
 */
function chart_runway(?float $useful, float $reserve, ?float $total, string $date, string $label): string {
    if ($total === null) {
        return '<div class="rw-empty muted">Sem custos fixos registados — o runway conta a partir deles.</div>';
    }
    $W = 480; $H = 64; $L = 0; $R = 0; $T = 18; $bh = 22;
    $max = max(1.0, $reserve + max(0.0, (float)$useful), $total, 12.0);
    $x = fn(float $m) => $L + ($W - $L - $R) * max(0, min($m, $max)) / $max;
    $h = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . '">';
    // a reserva (tracejada, sempre à esquerda: é o fim da linha)
    $rw = $x($reserve);
    $h .= '<rect class="rw-res" x="' . $L . '" y="' . $T . '" width="' . round($rw, 1) . '" height="' . $bh . '" rx="5" tabindex="0" data-tip="Reserva de emergência · ' . e(fin_num($reserve, 0)) . ' meses de custos fixos"/>';
    $fill = min($total, $reserve);
    if ($fill > 0 && $fill < $reserve) $h .= '<rect class="rw-in" x="' . $L . '" y="' . $T . '" width="' . round($x($fill), 1) . '" height="' . $bh . '" rx="5"/>';
    if ($useful !== null && $useful > 0) {
        $uw = $x($reserve + $useful) - $rw;
        $h .= '<path class="rw-use" d="' . sprintf('M%.1f %dH%.1fQ%.1f %d %.1f %dV%dQ%.1f %d %.1f %dH%.1fZ', $rw, $T, $rw + $uw - 5, $rw + $uw, $T, $rw + $uw, $T + 5, $T + $bh - 5, $rw + $uw, $T + $bh, $rw + $uw - 5, $T + $bh, $rw) . '"'
            . ' tabindex="0" data-tip="' . e(fin_num($useful, 1) . ' meses úteis · até ' . $date) . '"/>';
    }
    $h .= '<text x="' . $L . '" y="12">Reserva · ' . e(fin_num($reserve, 0)) . ' m</text>';
    if ($useful !== null && $useful > 0) $h .= '<text x="' . round(min($W - 4, $rw + 6), 1) . '" y="12">Úteis · ' . e(fin_num($useful, 1)) . ' m</text>';
    $h .= '<text x="' . $W . '" y="' . ($H - 6) . '" text-anchor="end">' . e(fin_num($max, 0)) . ' meses</text>';
    return $h . '</svg>';
}

/**
 * Fluxo de caixa de 12 meses: entradas e saídas por mês, lado a lado, e o saldo numa linha; os meses futuros
 * (previsão) tracejados. $rows: AAAA-MM → [in, out, balance, future].
 */
function chart_cashflow(array $rows, string $label): string {
    $W = 520; $H = 220; $L = 50; $R = 10; $T = 12; $B = 24;
    $vals = [0.0];
    foreach ($rows as $r) array_push($vals, (float)$r['in'], (float)$r['out'], abs((float)($r['balance'] ?? 0)));
    $max = nice_max(max($vals));
    $minB = min(0.0, ...array_values(array_map(fn($r) => (float)($r['balance'] ?? 0), $rows)));
    $min = $minB < 0 ? -nice_max(-$minB) : 0.0;
    $n = max(1, count($rows));
    $slot = ($W - $L - $R) / $n;
    $bw = min(12, $slot * 0.3);
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - ($v - $min) / ($max - $min));
    $h = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . '">';
    foreach (array_unique([$min, 0.0, $max / 2, $max]) as $v) {
        $yy = $y($v);
        $h .= '<line class="gl' . ($v == 0.0 && $min < 0 ? ' zero' : '') . '" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text x="' . ($L - 8) . '" y="' . ($yy + 3.5) . '" text-anchor="end">' . e(($v < 0 ? '−' : '') . eur_compact(abs($v))) . '</text>';
    }
    $pts = []; $i = 0; $firstFuture = null;
    foreach ($rows as $ym => $r) {
        $cx = $L + $slot * ($i + 0.5);
        $fut = !empty($r['future']);
        if ($fut && $firstFuture === null) $firstFuture = $i;
        $y0 = $y(0);
        $tip = chart_month_label($ym, true) . ($fut ? ' (previsão)' : '') . ' · entradas ' . eur0((float)$r['in']) . ' · saídas ' . eur0((float)$r['out']) . ($r['balance'] !== null ? ' · saldo ' . eur0((float)$r['balance']) : '');
        $hi = $y0 - $y((float)$r['in']); $ho = $y0 - $y((float)$r['out']);
        $h .= '<g class="col' . ($fut ? ' fut' : '') . '">'
            . '<path class="bar-in" d="' . bar_path($cx - $bw - 1, $y((float)$r['in']), $bw, $hi) . '"/>'
            . '<path class="bar-out" d="' . bar_path($cx + 1, $y((float)$r['out']), $bw, $ho) . '"/>'
            . '<text x="' . $cx . '" y="' . ($H - 8) . '" text-anchor="middle">' . e(chart_month_label($ym)) . '</text>'
            . '<rect class="hit" x="' . ($cx - $slot / 2) . '" y="' . $T . '" width="' . $slot . '" height="' . ($H - $T - $B) . '" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"/></g>';
        if ($r['balance'] !== null) $pts[] = [round($cx, 1), round($y((float)$r['balance']), 1)];
        $i++;
    }
    if ($pts) {
        $cut = $firstFuture === null ? count($pts) : max(1, $firstFuture);
        $past = array_slice($pts, 0, $cut);
        $fut = array_slice($pts, $cut - 1);
        $poly = fn(array $p) => implode(' ', array_map(fn($q) => $q[0] . ',' . $q[1], $p));
        if (count($past) > 1) $h .= '<polyline class="bal" points="' . $poly($past) . '"/>';
        if (count($fut) > 1) $h .= '<polyline class="bal fut" points="' . $poly($fut) . '"/>';
        foreach ($pts as $k => $p) $h .= '<circle class="bal-pt' . ($firstFuture !== null && $k >= $firstFuture ? ' fut' : '') . '" cx="' . $p[0] . '" cy="' . $p[1] . '" r="2.6"/>';
    }
    return $h . '</svg>';
}

/**
 * Por receber por antiguidade: uma barra empilhada (em dia · 1–30 · 31–60 · 61–90 · +90), cada parte um link
 * que filtra a lista. $parts: chave → [label, value, n, url, on].
 */
function chart_aging_stack(array $parts, string $label): string {
    $tot = array_sum(array_map(fn($p) => (float)$p['value'], $parts));
    $h = '<div class="agst" role="group" aria-label="' . e($label) . '">';
    if ($tot <= 0) return $h . '<div class="agst-bar empty"><span>Nada por receber</span></div></div>';
    $h .= '<div class="agst-bar">';
    foreach ($parts as $k => $p) {
        if ((float)$p['value'] <= 0) continue;
        $pct = max(2.5, (float)$p['value'] / $tot * 100);
        $tip = $p['label'] . ' · ' . eur0((float)$p['value']) . ' · ' . $p['n'] . ((int)$p['n'] === 1 ? ' fatura' : ' faturas');
        $h .= '<a class="ag-' . e($k) . (!empty($p['on']) ? ' on' : '') . '" style="flex:' . round($pct, 2) . ' 1 0" href="' . e($p['url']) . '" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"></a>';
    }
    $h .= '</div><div class="agst-lg">';
    foreach ($parts as $k => $p) {
        $h .= '<a class="' . (!empty($p['on']) ? 'on' : '') . '" href="' . e($p['url']) . '"><i class="ag-' . e($k) . '"></i>' . e($p['label']) . ' <b>' . e(eur0((float)$p['value'])) . '</b></a>';
    }
    return $h . '</div></div>';
}

/** Barras horizontais ordenadas (custos fixos por categoria): rótulo, barra, valor por mês. */
function chart_hbars(array $vals, string $unit = '/mês'): string {
    arsort($vals);
    $max = max(1.0, ...array_values($vals ?: [1.0]));
    $h = '<div class="hbars">';
    foreach ($vals as $k => $v) {
        $h .= '<div class="hbar" tabindex="0" data-tip="' . e($k . ' · ' . eur((float)$v) . $unit) . '"><span>' . e($k) . '</span><span class="track"><i style="width:' . round($v / $max * 100, 1) . '%"></i></span><span class="num">' . e(eur0((float)$v)) . '</span></div>';
    }
    return $h . '</div>';
}

/**
 * Receita com tendência: barras dos meses fechados (recebido), a média móvel de 3 meses, e a projeção com a banda
 * pessimista–otimista e a linha realista a tracejado. $proj: AAAA-MM → [pess, real, opt].
 */
function chart_revenue_trend(array $months, array $vals, array $ma, array $proj, string $label, int $W = 520): string {
    $H = 220; $L = 50; $R = 10; $T = 12; $B = 24;
    $all = array_merge([0.0], $vals, $ma, ...array_map(fn($p) => array_values($p), array_values($proj)));
    $max = nice_max(max($all));
    $keys = array_merge($months, array_keys($proj));
    $n = max(1, count($keys));
    $slot = ($W - $L - $R) / $n;
    $bw = min(16, $slot * 0.5);
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - $v / $max);
    $cx = fn(int $i) => $L + $slot * ($i + 0.5);
    $h = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . '">';
    foreach ([0, .5, 1] as $f) {
        $yy = $y($max * $f);
        $h .= '<line class="gl" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '"/><text x="' . ($L - 8) . '" y="' . ($yy + 3.5) . '" text-anchor="end">' . e(eur_compact($max * $f)) . '</text>';
    }
    // banda e linha da projeção (começa no último mês fechado)
    $np = count($months);
    if ($proj && $np) {
        $up = [[$cx($np - 1), $y((float)end($ma))]]; $dn = $up; $re = $up;
        $i = $np;
        foreach ($proj as $p) { $up[] = [$cx($i), $y($p['opt'])]; $dn[] = [$cx($i), $y($p['pess'])]; $re[] = [$cx($i), $y($p['real'])]; $i++; }
        $pt = fn(array $a) => implode(' ', array_map(fn($q) => round($q[0], 1) . ',' . round($q[1], 1), $a));
        $h .= '<polygon class="band" points="' . $pt($up) . ' ' . $pt(array_reverse($dn)) . '"/>'
            . '<polyline class="proj" points="' . $pt($re) . '"/>';
    }
    foreach ($months as $i => $ym) {
        $v = (float)($vals[$i] ?? 0);
        $tip = chart_month_label($ym, true) . ' · recebido ' . eur0($v) . ' · média de 3 meses ' . eur0((float)($ma[$i] ?? 0));
        $h .= '<g class="col"><path class="bar-m" d="' . bar_path($cx($i) - $bw / 2, $y($v), $bw, $y(0) - $y($v)) . '"/>'
            . '<text x="' . $cx($i) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e(chart_month_label($ym)) . '</text>'
            . '<rect class="hit" x="' . ($cx($i) - $slot / 2) . '" y="' . $T . '" width="' . $slot . '" height="' . ($H - $T - $B) . '" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"/></g>';
    }
    if (count($ma) > 1) $h .= '<polyline class="ma" points="' . implode(' ', array_map(fn($i) => round($cx($i), 1) . ',' . round($y((float)$ma[$i]), 1), array_keys($ma))) . '"/>';
    $i = $np;
    foreach ($proj as $ym => $p) {
        $tip = chart_month_label($ym, true) . ' (projeção) · pessimista ' . eur0($p['pess']) . ' · realista ' . eur0($p['real']) . ' · otimista ' . eur0($p['opt']);
        $h .= '<g class="col"><text x="' . $cx($i) . '" y="' . ($H - 8) . '" text-anchor="middle" class="fut">' . e(chart_month_label($ym)) . '</text>'
            . '<rect class="hit" x="' . ($cx($i) - $slot / 2) . '" y="' . $T . '" width="' . $slot . '" height="' . ($H - $T - $B) . '" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"/></g>';
        $i++;
    }
    return $h . '</svg>';
}

// ── Relatórios (§29.13) ─────────────────────────────────────
//
// A mesma linha: um só eixo, barras com a ponta arredondada, grelha discreta, dica em cada marca (rato e
// teclado), clicáveis até aos registos, entrada animada. Azul é o real, coral é a meta / o período anterior;
// o cinzento é o que se perdeu. Sem circulares, velocímetros nem 3D.

/** Mini-linha (sparkline) junto a um número: os valores pela ordem; o último ponto marcado. */
function chart_spark(array $vals, string $label = '', int $W = 84, int $H = 22): string {
    $vals = array_values(array_map('floatval', $vals));
    if (count($vals) < 2) return '';
    $max = max($vals); $min = min(0.0, min($vals));
    $span = $max - $min ?: 1;
    $pts = [];
    foreach ($vals as $i => $v) $pts[] = round(2 + ($W - 4) * $i / (count($vals) - 1), 1) . ',' . round($H - 2 - ($H - 4) * ($v - $min) / $span, 1);
    $last = explode(',', end($pts));
    return '<svg class="spark" viewBox="0 0 ' . $W . ' ' . $H . '" width="' . $W . '" height="' . $H . '" role="img" aria-label="' . e($label ?: 'Tendência') . '">'
        . '<polyline points="' . implode(' ', $pts) . '"/><circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.4"/></svg>';
}

/**
 * Meta: barra de progresso com o marcador do ritmo esperado (bullet chart) e a previsão de fim de período a
 * tracejado. Valores na mesma unidade; $tip descreve tudo em texto.
 */
function chart_bullet(float $value, float $target, float $expected, float $forecast, string $state, string $tip): string {
    $max = max($target, $value, $forecast, 0.0001) * 1.08;
    $p = fn(float $v) => round(max(0, min(100, $v / $max * 100)), 2);
    return '<div class="bullet st-' . e($state) . '" tabindex="0" role="img" aria-label="' . e($tip) . '" data-tip="' . e($tip) . '">'
        . '<span class="b-fc" style="width:' . $p($forecast) . '%"></span>'
        . '<span class="b-val" style="width:' . $p($value) . '%"></span>'
        . ($expected > 0 && $expected < $target ? '<span class="b-exp" style="left:' . $p($expected) . '%"></span>' : '')
        . '<span class="b-goal" style="left:' . $p($target) . '%"></span></div>';
}

/**
 * Funil: barras horizontais decrescentes por etapa, a % de passagem entre elas e a perda a cinzento. $rows:
 * [label, n, lost, pass (% para a seguinte ou null), url, url_lost, tip].
 */
function chart_funnel(array $rows, string $label): string {
    $max = max(1, ...array_map(fn($r) => (int)$r['n'], $rows ?: [['n' => 1]]));
    $h = '<div class="mxfun" role="list" aria-label="' . e($label) . '">';
    foreach ($rows as $i => $r) {
        $w = round((int)$r['n'] / $max * 100, 2);
        $lw = $r['n'] ? round(min((int)$r['lost'], (int)$r['n']) / $max * 100, 2) : 0;
        $h .= '<div class="fr" role="listitem"><span class="fl">' . e($r['label']) . '</span>'
            . '<span class="ft" data-tip="' . e($r['tip']) . '" tabindex="0" aria-label="' . e($r['tip']) . '"><i class="fv" style="width:' . $w . '%;animation-delay:' . ($i * 50) . 'ms"></i>'
            . ($lw > 0 ? '<i class="fxl" style="width:' . $lw . '%;left:' . max(0, $w - $lw) . '%"></i>' : '') . '</span>'
            . '<a class="fn mx-n" href="' . e($r['url']) . '" data-mx-list>' . (int)$r['n'] . '</a></div>';
        if ($r['pass'] !== null) {
            $h .= '<div class="fp" aria-hidden="true"><span>↓ ' . (int)round($r['pass']) . '% passou</span>' . ((int)$r['lost'] ? ' · <a class="mx-n" href="' . e($r['url_lost']) . '" data-mx-list>' . (int)$r['lost'] . ' perdidas</a>' : '') . '</div>';
        }
    }
    return $h . '</div>';
}

/**
 * Linhas por mês: uma ou mais séries (a primeira em azul; a de comparação a tracejado). $months: chaves
 * aaaa-mm; $series: [[label, [aaaa-mm => valor|null], classe]]; $fmt formata o eixo e as dicas.
 */
function chart_lines(array $months, array $series, callable $fmt, string $label, ?float $ref = null, string $refLabel = ''): string {
    $W = 480; $H = 190; $L = 46; $R = 12; $T = 12; $B = 24;
    $all = [0.0];
    foreach ($series as $s) foreach ($s[1] as $v) if ($v !== null) $all[] = (float)$v;
    if ($ref !== null) $all[] = $ref;
    $max = nice_max(max($all));
    $n = max(1, count($months) - 1);
    $x = fn(int $i) => $L + ($W - $L - $R) * ($n ? $i / $n : 0);
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - $v / $max);
    $h = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . '">';
    foreach ([0, .5, 1] as $f) {
        $yy = $y($max * $f);
        $h .= '<line class="gl" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '"/><text x="' . ($L - 8) . '" y="' . ($yy + 3.5) . '" text-anchor="end">' . e($fmt($max * $f)) . '</text>';
    }
    if ($ref !== null) $h .= '<line class="goal" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y($ref) . '" y2="' . $y($ref) . '"/><text class="lb" x="' . ($W - $R) . '" y="' . ($y($ref) - 5) . '" text-anchor="end">' . e($refLabel) . '</text>';
    $keys = array_values($months);
    foreach ($keys as $i => $ym) $h .= '<text x="' . $x($i) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e(chart_month_label($ym)) . '</text>';
    foreach ($series as $s) {
        $pts = [];
        foreach ($keys as $i => $ym) if (($s[1][$ym] ?? null) !== null) $pts[] = [$x($i), $y((float)$s[1][$ym])];
        if (count($pts) > 1) $h .= '<polyline class="ln ' . e($s[2] ?? '') . '" points="' . implode(' ', array_map(fn($p) => round($p[0], 1) . ',' . round($p[1], 1), $pts)) . '"/>';
        foreach ($pts as $p) $h .= '<circle class="pt ' . e($s[2] ?? '') . '" cx="' . round($p[0], 1) . '" cy="' . round($p[1], 1) . '" r="3"/>';
    }
    $step = ($W - $L - $R) / max(1, count($keys));
    foreach ($keys as $i => $ym) {
        $tip = chart_month_label($ym, true) . ' · ' . implode(' · ', array_map(fn($s) => $s[0] . ' ' . (($s[1][$ym] ?? null) === null ? '—' : $fmt((float)$s[1][$ym])), $series));
        $h .= '<rect class="hit" x="' . round($x($i) - $step / 2, 1) . '" y="' . $T . '" width="' . round($step, 1) . '" height="' . ($H - $T - $B) . '" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"/>';
    }
    return $h . '</svg>';
}

/**
 * Velocidade por canal: a mediana (barra forte) e o p90 (barra clara) em horas úteis, com o prazo do canal
 * (traço coral). $rows: [label, median, p90, sla, n, url, tip].
 */
function chart_speed(array $rows): string {
    $max = 1.0;
    foreach ($rows as $r) $max = max($max, (float)$r['p90'], (float)$r['median'], (float)$r['sla']);
    $max = nice_max($max);
    $h = '<div class="mxspeed">';
    foreach ($rows as $i => $r) {
        $p = fn($v) => round(max(0, min(100, (float)$v / $max * 100)), 2);
        $h .= '<a class="spr" href="' . e($r['url']) . '" data-mx-list data-tip="' . e($r['tip']) . '" aria-label="' . e($r['tip']) . '"><span class="sl">' . e($r['label']) . '</span>'
            . '<span class="st"><i class="s90" style="width:' . $p($r['p90']) . '%;animation-delay:' . ($i * 50) . 'ms"></i><i class="s50" style="width:' . $p($r['median']) . '%;animation-delay:' . ($i * 50) . 'ms"></i>'
            . '<i class="ssla" style="left:' . $p($r['sla']) . '%"></i></span><span class="sn">' . e($r['val']) . '</span></a>';
    }
    return $h . '<div class="sx"><span>0</span><span>' . e(str_replace('.', ',', (string)$max)) . ' h úteis</span></div></div>';
}

/**
 * Dispersão volume × conversão (o tamanho é o valor ganho) em quadrantes: escalar (muito volume, boa
 * conversão), otimizar (pouco volume, boa conversão), rever (muito volume, má conversão), cortar.
 * $pts: [label, x, y (%), r (valor), url, tip].
 */
function chart_scatter(array $pts, string $label, string $xLabel, string $yLabel): string {
    $W = 860; $H = 340; $L = 46; $R = 16; $T = 18; $B = 34;
    if (!$pts) return '<p class="empty">Sem origens com leads neste período.</p>';
    $xm = nice_max(max(array_column($pts, 'x')) * 1.1);
    $ym = min(100, nice_max(max(1, max(array_column($pts, 'y'))) * 1.15));
    $rm = max(1, max(array_column($pts, 'r')));
    $mx = mx_median(array_column($pts, 'x')) ?? 0; $my = mx_median(array_column($pts, 'y')) ?? 0;
    $x = fn(float $v) => $L + ($W - $L - $R) * $v / $xm;
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - $v / $ym);
    $h = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . '">';
    $h .= '<rect class="q q-scale" x="' . round($x($mx), 1) . '" y="' . $T . '" width="' . round($W - $R - $x($mx), 1) . '" height="' . round($y($my) - $T, 1) . '"/>';
    foreach ([0, .5, 1] as $f) {
        $h .= '<line class="gl" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y($ym * $f) . '" y2="' . $y($ym * $f) . '"/><text x="' . ($L - 8) . '" y="' . ($y($ym * $f) + 3.5) . '" text-anchor="end">' . (int)round($ym * $f) . '%</text>';
    }
    $h .= '<line class="qm" x1="' . round($x($mx), 1) . '" x2="' . round($x($mx), 1) . '" y1="' . $T . '" y2="' . ($H - $B) . '"/><line class="qm" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . round($y($my), 1) . '" y2="' . round($y($my), 1) . '"/>';
    // os nomes dos quadrantes nos cantos de dentro; ocupam espaço para os rótulos não lhes caírem em cima
    $boxes = [];
    foreach ([['Escalar', $W - $R - 8, $T + 14, 'end'], ['Otimizar', $L + 8, $T + 14, 'start'], ['Rever', $W - $R - 8, $H - $B - 8, 'end'], ['Cortar', $L + 8, $H - $B - 8, 'start']] as [$t, $tx, $ty, $an]) {
        $h .= '<text class="ql" x="' . $tx . '" y="' . $ty . '" text-anchor="' . $an . '">' . $t . '</text>';
        $w = mb_strlen($t) * 7.5;
        $boxes[] = [$an === 'end' ? $tx - $w : $tx, $ty - 10, $w, 12];
    }
    $h .= '<text x="' . (($L + $W - $R) / 2) . '" y="' . ($H - 8) . '" text-anchor="middle">' . e($xLabel) . ' (0 a ' . (int)$xm . ') · ' . e($yLabel) . ' no eixo vertical</text>';
    usort($pts, fn($a, $b) => $b['r'] <=> $a['r']);
    $hit = fn(array $b) => (bool)array_filter($boxes, fn($o) => $b[0] < $o[0] + $o[2] && $b[0] + $b[2] > $o[0] && $b[1] < $o[1] + $o[3] && $b[1] + $b[3] > $o[1]);
    $dots = ''; $labels = '';
    foreach ($pts as $i => $p) {
        $r = 6 + 16 * sqrt(max(0, (float)$p['r']) / $rm);
        $cx = round($x((float)$p['x']), 1); $cy = round($y((float)$p['y']), 1);
        $dots .= '<a href="' . e($p['url']) . '" class="sdot" data-mx-list><circle cx="' . $cx . '" cy="' . $cy . '" r="' . round($r, 1) . '" tabindex="0" data-tip="' . e($p['tip']) . '" aria-label="' . e($p['tip']) . '" style="animation-delay:' . ($i * 40) . 'ms"/></a>';
        $t = mb_strimwidth($p['label'], 0, 24, '…');
        $w = mb_strlen($t) * 6.2 + 4;
        // acima, abaixo, à direita, à esquerda; se nada couber, fica só na dica
        foreach ([[$cx - $w / 2, $cy - $r - 16, 'middle', $cx, $cy - $r - 5], [$cx - $w / 2, $cy + $r + 3, 'middle', $cx, $cy + $r + 13],
                  [$cx + $r + 4, $cy - 6, 'start', $cx + $r + 6, $cy + 4], [$cx - $r - 4 - $w, $cy - 6, 'end', $cx - $r - 6, $cy + 4]] as [$bx, $by, $an, $tx, $ty]) {
            $b = [$bx, $by, $w, 13];
            if ($bx < 0 || $bx + $w > $W || $by < 0 || $by + 13 > $H - $B + 4 || $hit($b)) continue;
            $boxes[] = $b;
            $labels .= '<text class="dl" x="' . round($tx, 1) . '" y="' . round($ty, 1) . '" text-anchor="' . $an . '">' . e($t) . '</text>';
            break;
        }
        $boxes[] = [$cx - $r, $cy - $r, 2 * $r, 2 * $r];
    }
    return $h . $dots . $labels . '</svg>';
}

/** Barras empilhadas por semana (atividade). $weeks: aaaa-mm-dd (segunda) => [tipo => n]; $kinds: tipo => [rótulo, classe]. */
function chart_stack_weeks(array $weeks, array $kinds, string $label): string {
    $W = 520; $H = 200; $L = 34; $R = 8; $T = 12; $B = 24;
    $tot = array_map(fn($w) => array_sum(array_intersect_key($w, $kinds)), $weeks ?: [[]]);
    $max = nice_max(max(1, max($tot ?: [1])));
    $n = max(1, count($weeks));
    $slot = ($W - $L - $R) / $n; $bw = min(24, $slot * .6);
    $y = fn(float $v) => $T + ($H - $T - $B) * (1 - $v / $max);
    $h = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . e($label) . '">';
    foreach ([0, .5, 1] as $f) $h .= '<line class="gl" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $y($max * $f) . '" y2="' . $y($max * $f) . '"/><text x="' . ($L - 6) . '" y="' . ($y($max * $f) + 3.5) . '" text-anchor="end">' . (int)round($max * $f) . '</text>';
    $i = 0;
    foreach ($weeks as $wk => $c) {
        $cx = $L + $slot * ($i + .5);
        $acc = 0.0; $parts = '';
        $keys = array_keys($kinds);
        foreach ($keys as $j => $k) {
            $v = (float)($c[$k] ?? 0);
            if ($v <= 0) continue;
            $top = $y($acc + $v); $bot = $y($acc);
            $isTop = !array_filter(array_slice($keys, $j + 1), fn($kk) => ($c[$kk] ?? 0) > 0);
            $parts .= $isTop ? '<path class="' . e($kinds[$k][1]) . '" d="' . bar_path($cx - $bw / 2, $top, $bw, $bot - $top) . '"/>'
                             : '<rect class="' . e($kinds[$k][1]) . '" x="' . round($cx - $bw / 2, 1) . '" y="' . round($top, 1) . '" width="' . round($bw, 1) . '" height="' . round($bot - $top, 1) . '"/>';
            $acc += $v;
        }
        $tip = 'Semana de ' . date('d/m', strtotime($wk)) . ' · ' . implode(' · ', array_filter(array_map(fn($k) => ($c[$k] ?? 0) ? $kinds[$k][0] . ' ' . $c[$k] : '', array_keys($kinds))));
        $h .= '<g class="col">' . $parts . '<text x="' . $cx . '" y="' . ($H - 8) . '" text-anchor="middle">' . date('d/m', strtotime($wk)) . '</text>'
            . '<rect class="hit" x="' . round($cx - $slot / 2, 1) . '" y="' . $T . '" width="' . round($slot, 1) . '" height="' . ($H - $T - $B) . '" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '"/></g>';
        $i++;
    }
    return $h . '</svg>';
}

/** Mapa de calor dia × hora (quando a equipa trabalha as leads). $heat: dia (1–7) => hora => n. */
function chart_heat_dayhour(array $heat, int $h0 = 7, int $h1 = 21): string {
    $days = [1 => 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
    $max = 1;
    foreach ($heat as $hs) foreach ($hs as $v) $max = max($max, $v);
    $h = '<div class="heatwrap"><table class="heat" aria-label="Atividade por dia e hora"><thead><tr><th></th>';
    for ($x = $h0; $x <= $h1; $x++) $h .= '<th scope="col">' . $x . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($days as $d => $dl) {
        $h .= '<tr><th scope="row">' . $dl . '</th>';
        for ($x = $h0; $x <= $h1; $x++) {
            $v = (int)($heat[$d][$x] ?? 0);
            $tip = $dl . ' ' . $x . 'h · ' . $v . ($v === 1 ? ' contacto' : ' contactos');
            $h .= '<td style="--a:' . round($v / $max, 3) . '" tabindex="0" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '">' . ($v ?: '') . '</td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

/**
 * Barras horizontais de valor com a margem sobreposta: o gross profit a cor forte e o resto do valor a cor
 * clara, ordenadas por gross profit. $rows: [label, value, gp, url, tip]. Sem dinheiro, $count = true mostra só
 * o número de ganhos.
 */
function chart_margin_bars(array $rows, bool $money = true): string {
    usort($rows, fn($a, $b) => $money ? $b['gp'] <=> $a['gp'] : $b['value'] <=> $a['value']);
    $max = max(1.0, ...array_map(fn($r) => (float)$r['value'], $rows ?: [['value' => 1]]));
    $h = '<div class="hbars mxm">';
    foreach ($rows as $i => $r) {
        $w = round((float)$r['value'] / $max * 100, 2);
        $g = $money && (float)$r['value'] > 0 ? round(max(0, (float)$r['gp']) / $max * 100, 2) : 0;
        $h .= '<a class="hbar" href="' . e($r['url']) . '" data-mx-list data-tip="' . e($r['tip']) . '" aria-label="' . e($r['tip']) . '"><span>' . e($r['label']) . '</span>'
            . '<span class="track"><i class="mv" style="width:' . $w . '%;animation-delay:' . ($i * 50) . 'ms"></i>' . ($money ? '<i class="mg" style="width:' . $g . '%;animation-delay:' . ($i * 50) . 'ms"></i>' : '') . '</span>'
            . '<span class="num">' . e($r['num']) . '</span></a>';
    }
    return $h . '</div>';
}

/**
 * Orçamentado → real por negócio: de onde se partiu (custo orçado) para onde se chegou (custo real); a seta
 * vermelha quando derrapou, verde quando ficou abaixo. $rows: [label, budget, real, url, tip].
 */
function chart_budget_real(array $rows): string {
    $max = 1.0;
    foreach ($rows as $r) $max = max($max, (float)$r['budget'], (float)$r['real']);
    $max *= 1.05;
    $h = '<div class="mxbr">';
    foreach ($rows as $i => $r) {
        $a = round((float)$r['budget'] / $max * 100, 2); $b = round((float)$r['real'] / $max * 100, 2);
        $over = (float)$r['real'] > (float)$r['budget'];
        $h .= '<a class="br" href="' . e($r['url']) . '" data-mx-list data-tip="' . e($r['tip']) . '" aria-label="' . e($r['tip']) . '"><span class="bl">' . e($r['label']) . '</span>'
            . '<span class="bt"><i class="bb" style="width:' . $a . '%"></i><i class="bd ' . ($over ? 'over' : 'under') . '" style="left:' . min($a, $b) . '%;width:' . abs($b - $a) . '%;animation-delay:' . ($i * 50) . 'ms"></i>'
            . '<i class="bm" style="left:' . $b . '%"></i></span><span class="num ' . ($over ? 'neg' : 'pos') . '">' . e($r['num']) . '</span></a>';
    }
    return $h . '</div>';
}
