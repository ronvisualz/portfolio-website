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
