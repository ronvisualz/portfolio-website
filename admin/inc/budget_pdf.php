<?php
/**
 * topper. admin — a proposta em PDF (§14.6)
 *
 * «Gerar proposta» numa folha: descrição do trabalho, fases e prazos, preço
 * s/ e c/ IVA e condições, com o logótipo topper. — nunca custos, margens
 * nem lucro (o PDF não lê nada disso, seja quem for que o gera). Fica na
 * pasta do negócio no Drive (Projetos/<ID>_<NOME>/Propostas/) e descarrega-se.
 * Um escritor de PDF pequeno (Helvetica, A4), sem bibliotecas: o alojamento
 * não precisa de nada instalado.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/budget_view.php';

/** Larguras da Helvetica e da Helvetica-Bold (AFM, por 1000) para os caracteres 32–255 do Windows-1252. */
const BUDGET_PDF_W = [
    'F1' => [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,278,222,556,333,1000,556,556,333,1000,667,333,1000,278,611,278,278,222,222,333,333,350,556,1000,333,1000,500,333,944,278,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500],
    'F2' => [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,278,278,556,500,1000,556,556,333,1000,667,333,1000,278,611,278,278,278,278,500,500,350,556,1000,333,1000,556,333,944,278,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556],
];

/** Texto em UTF-8 → Windows-1252 (o que a Helvetica do PDF conhece). */
function budget_pdf_enc(string $s): string {
    $s = strtr($s, ["\u{2212}" => '-', "\u{2192}" => '->', "\u{2265}" => '>=', "\u{2264}" => '<=', "\u{00A0}" => ' ', "\u{2009}" => ' ', "\u{202F}" => ' ']);
    $out = function_exists('mb_convert_encoding') ? @mb_convert_encoding($s, 'Windows-1252', 'UTF-8') : @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
    return is_string($out) ? $out : preg_replace('/[^\x20-\x7E]/', '?', $s);
}

final class BudgetPdf {
    public const W = 595.28, H = 841.89;
    private array $pages = [];
    private string $ops = '';
    /** Imagens JPEG (DCTDecode): nome => [largura, altura, bytes]. */
    private array $images = [];

    public function page(): void {
        if ($this->ops !== '') $this->pages[] = $this->ops;
        $this->ops = '';
    }

    public static function width(string $enc, string $font, float $size): float {
        $w = 0;
        $n = strlen($enc);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($enc[$i]);
            $w += $c >= 32 ? BUDGET_PDF_W[$font][$c - 32] : 0;
        }
        return $w * $size / 1000;
    }

    /** Quebra um texto (UTF-8) em linhas que cabem em $max pontos. */
    public static function wrap(string $text, string $font, float $size, float $max): array {
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $para) {
            $words = preg_split('/\s+/u', trim($para));
            $cur = '';
            foreach ($words as $w) {
                if ($w === '') continue;
                $try = $cur === '' ? $w : $cur . ' ' . $w;
                if ($cur !== '' && self::width(budget_pdf_enc($try), $font, $size) > $max) { $lines[] = $cur; $cur = $w; }
                else $cur = $try;
            }
            $lines[] = $cur;
        }
        return $lines;
    }

    private static function esc(string $enc): string {
        return strtr($enc, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    private static function rgb(string $hex): string {
        $h = ltrim($hex, '#');
        return sprintf('%.3F %.3F %.3F', hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255);
    }

    /** Texto na posição (x, y a partir do topo). $align: l | r. */
    public function text(float $x, float $y, string $s, float $size = 10, bool $bold = false, string $color = '#111111', string $align = 'l'): void {
        $enc = budget_pdf_enc($s);
        $f = $bold ? 'F2' : 'F1';
        if ($align === 'r') $x -= self::width($enc, $f, $size);
        $this->ops .= sprintf("BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n", $f, $size, self::rgb($color), $x, self::H - $y, self::esc($enc));
    }

    public function rect(float $x, float $y, float $w, float $h, string $fill): void {
        $this->ops .= sprintf("%s rg %.2F %.2F %.2F %.2F re f\n", self::rgb($fill), $x, self::H - $y - $h, $w, $h);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color = '#E3E3E0', float $w = 0.6): void {
        $this->ops .= sprintf("%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n", self::rgb($color), $w, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    /** Uma imagem JPEG (bytes) no retângulo (x, y a partir do topo, w × h). Imagens que não são JPEG RGB ficam de fora. */
    public function image(string $jpeg, float $x, float $y, float $w, float $h): void {
        $info = @getimagesizefromstring($jpeg);
        if (!$info || ($info[2] ?? 0) !== IMAGETYPE_JPEG || ($info['channels'] ?? 3) !== 3) return;
        $name = 'Im' . (count($this->images) + 1);
        $this->images[$name] = [(int)$info[0], (int)$info[1], $jpeg];
        $this->ops .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, self::H - $y - $h, $name);
    }

    /** O logótipo topper. (o mesmo desenho do menu), com $h pontos de altura, canto superior esquerdo em (x, y). */
    public function wordmark(float $x, float $y, float $h, string $color = '#111111'): void {
        if (!preg_match('/ d="([^"]+)"/', wordmark_svg(), $m)) return;
        $s = $h / 121.88;
        $t = preg_split('/\s+/', trim($m[1]));
        $path = '';
        for ($i = 0, $n = count($t); $i < $n; $i++) {
            switch ($t[$i]) {
                case 'M': $path .= sprintf('%.2F %.2F m ', $t[$i + 1], $t[$i + 2]); $i += 2; break;
                case 'L': $path .= sprintf('%.2F %.2F l ', $t[$i + 1], $t[$i + 2]); $i += 2; break;
                case 'C': $path .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $t[$i + 1], $t[$i + 2], $t[$i + 3], $t[$i + 4], $t[$i + 5], $t[$i + 6]); $i += 6; break;
                case 'Z': case 'z': $path .= 'h '; break;
            }
        }
        $this->ops .= sprintf("q %.5F 0 0 %.5F %.2F %.2F cm %s rg %sf Q\n", $s, -$s, $x, self::H - $y, self::rgb($color), $path);
    }

    public function output(string $title): string {
        $this->page();
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        // as imagens primeiro (números a seguir às fontes), depois as páginas
        $n = 5;
        $xo = '';
        foreach ($this->images as $name => [$iw, $ih, $bytes]) {
            $objs[$n] = '<< /Type /XObject /Subtype /Image /Width ' . $iw . ' /Height ' . $ih . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($bytes) . " >>\nstream\n" . $bytes . "\nendstream";
            $xo .= '/' . $name . ' ' . $n . ' 0 R ';
            $n++;
        }
        $res = '/Font << /F1 3 0 R /F2 4 0 R >>' . ($xo !== '' ? ' /XObject << ' . $xo . '>>' : '');
        foreach ($this->pages as $ops) {
            $objs[$n] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << ' . $res . ' >> /Contents ' . ($n + 1) . ' 0 R >>';
            $body = function_exists('gzcompress') ? gzcompress($ops) : $ops;
            $objs[$n + 1] = '<< /Length ' . strlen($body) . (function_exists('gzcompress') ? ' /Filter /FlateDecode' : '') . " >>\nstream\n" . $body . "\nendstream";
            $kids[] = $n . ' 0 R';
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objs[$n] = '<< /Title (' . self::esc(budget_pdf_enc($title)) . ') /Producer (topper. admin) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        foreach ($objs as $i => $o) { $off[$i] = strlen($pdf); $pdf .= $i . " 0 obj\n" . $o . "\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($n + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $n; $i++) $pdf .= sprintf("%010d 00000 n \n", $off[$i]);
        return $pdf . "trailer\n<< /Size " . ($n + 1) . " /Root 1 0 R /Info $n 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}

/** O nome do ficheiro: «Proposta_ID2610-04_v2.pdf». */
function budget_pdf_name(array $deal, array $v): string {
    return 'Proposta_' . preg_replace('/[^A-Za-z0-9-]/', '', (string)$deal['code']) . '_v' . (int)$v['num'] . '.pdf';
}

/**
 * Desenha a proposta. Lê da versão só o que o cliente pode ver: descrição, duração e
 * fases, preço s/ IVA (o cálculo corre aqui, mas só o preço sai), IVA e condições.
 */
function budget_pdf_build(PDO $pdo, array $deal, array $v): string {
    $opts = budget_has_options($pdo);
    $calc = budget_calc($pdo, $v + ($opts ? ['calc_opt' => 'A'] : []), budget_lines($pdo, (int)$v['id'], $opts ? 'A' : null));
    // Opção B (§19.3): a proposta de valor, ao lado do pedido.
    $linesB = $opts ? budget_lines($pdo, (int)$v['id'], 'B') : [];
    $calcB = $linesB ? budget_calc($pdo, $v + ['calc_opt' => 'B'], $linesB) : null;
    $price = $calc['price'];
    $rate = $calc['vat_rate'];
    $vat = round($price * $rate / 100, 2);
    $pdf = new BudgetPdf();
    $L = 56.0; $R = BudgetPdf::W - 56.0; $max = $R - $L;
    $date = date('d/m/Y');
    $y = 0.0;

    // A empresa (Definições › Geral): nome, NIF e morada no rodapé.
    $co = trim((string)setting($pdo, 'company_name', 'topper.')) ?: 'topper.';
    $nif = trim((string)setting($pdo, 'company_nif', ''));
    $addr = trim(preg_replace('/\s*\n\s*/', ', ', (string)setting($pdo, 'company_address', '')));
    $footer = function (int $page) use ($pdf, $L, $R, $deal, $v, $date, $co, $nif, $addr) {
        $pdf->line($L, BudgetPdf::H - 46, $R, BudgetPdf::H - 46);
        $pdf->text($L, BudgetPdf::H - 32, $co . ($nif !== '' ? ' · NIF ' . $nif : '') . ' · Proposta ' . $deal['code'] . ' · v' . (int)$v['num'] . ' · ' . $date, 8, false, '#767676');
        if ($addr !== '') $pdf->text($L, BudgetPdf::H - 21, mb_strimwidth($addr, 0, 110, '…'), 7, false, '#9a9a9a');
        $pdf->text($R, BudgetPdf::H - 32, 'Página ' . $page, 8, false, '#767676', 'r');
    };
    $page = 1;
    $need = function (float $h) use (&$y, &$page, $pdf, $footer) {
        if ($y + $h <= BudgetPdf::H - 70) return;
        $footer($page);
        $pdf->page();
        $page++;
        $y = 64;
    };

    // cabeçalho: faixa amarela, logótipo, «Proposta», ID, versão e data
    $pdf->rect(0, 0, BudgetPdf::W, 6, '#FFC718');
    $pdf->wordmark($L, 44, 26);
    $pdf->text($R, 52, 'PROPOSTA', 9, true, '#5C5C5C', 'r');
    $pdf->text($R, 66, $deal['code'] . ' · v' . (int)$v['num'] . ' · ' . $date, 9, false, '#5C5C5C', 'r');
    $y = 118;
    foreach (BudgetPdf::wrap((string)$deal['name'], 'F2', 22, $max) as $ln) { $pdf->text($L, $y, $ln, 22, true); $y += 26; }
    if (!empty($deal['client_name']) && mb_strtolower(trim($deal['client_name'])) !== mb_strtolower(trim((string)$deal['name']))) { $pdf->text($L, $y, 'Para ' . $deal['client_name'], 11, false, '#5C5C5C'); $y += 16; }
    $y += 14;

    $section = function (string $title) use (&$y, $pdf, $L, $R, $need) {
        $need(40);
        $pdf->text($L, $y, mb_strtoupper($title), 8.5, true, '#5C5C5C');
        $y += 7;
        $pdf->line($L, $y, $R, $y);
        $y += 16;
    };
    $para = function (string $text, float $size = 10.5) use (&$y, $pdf, $L, $max, $need) {
        foreach (BudgetPdf::wrap($text, 'F1', $size, $max) as $ln) { $need($size + 5); if ($ln !== '') $pdf->text($L, $y, $ln, $size); $y += $size + 4.5; }
        $y += 6;
    };

    // o trabalho: com duas opções, lado a lado (cada uma com o seu âmbito)
    $desc = trim((string)$v['description']);
    $descB = trim((string)($v['scope_b'] ?? ''));
    if ($calcB) {
        $section('As opções');
        $half = ($max - 18) / 2;
        $colA = BudgetPdf::wrap($desc !== '' ? $desc : 'O âmbito pedido.', 'F1', 10, $half - 20);
        $colB = BudgetPdf::wrap($descB !== '' ? $descB : 'O âmbito pedido, com mais valor.', 'F1', 10, $half - 20);
        $hBox = 58 + max(count($colA), count($colB)) * 14.5;
        $need($hBox + 10);
        foreach ([[$L, 'OPÇÃO A · PEDIDO', $colA, $calc['price'], '#F4F4F2', '#C9C9C4'], [$L + $half + 18, 'OPÇÃO B · PROPOSTA DE VALOR', $colB, $calcB['price'], '#FFF6D6', '#FFC718']] as [$x, $label, $col, $p, $bg, $bar]) {
            $pdf->rect($x, $y - 12, $half, $hBox, $bg);
            $pdf->rect($x, $y - 12, 3, $hBox, $bar);
            $pdf->text($x + 14, $y + 4, $label, 8.5, true, '#5C5C5C');
            $pdf->text($x + 14, $y + 26, budget_eur($p, 2) . ' s/ IVA', 15, true);
            $yy = $y + 48;
            foreach ($col as $ln) { if ($ln !== '') $pdf->text($x + 14, $yy, $ln, 10); $yy += 14.5; }
        }
        $y += $hBox + 8;
    } elseif ($desc !== '') { $section('O trabalho'); $para($desc); }

    // o pré-criativo (§23.3): uma página por opção, com as referências, a paleta e a técnica
    require_once __DIR__ . '/cc_flow.php';
    $ccPages = cc_pages_for_version($pdo, (int)$v['id']);
    if ($ccPages) {
        foreach ($ccPages as $pg) {
            if ($pg['opt'] === 'B' && !$calcB) continue;
            $footer($page);
            $pdf->page();
            $page++;
            $y = 64;
            $label = ($pg['opt'] === 'AB' ? ($calcB ? 'OPÇÕES A E B' : 'OPÇÃO A · PEDIDO') : ($pg['opt'] === 'A' ? 'OPÇÃO A · PEDIDO' : 'OPÇÃO B · PROPOSTA DE VALOR'))
                   . ' · PRÉ-CRIATIVO' . ($pg['front'] !== '' ? ' · ' . mb_strtoupper($pg['front']) : '');
            $pdf->rect($L, $y - 10, 3, 14, $pg['opt'] === 'B' ? '#FFC718' : '#81CFE3');
            $pdf->text($L + 10, $y, $label, 8.5, true, '#5C5C5C');
            $y += 26;
            foreach (BudgetPdf::wrap($pg['title'] !== '' ? $pg['title'] : 'Pré-criativo', 'F2', 22, $max) as $ln) { $pdf->text($L, $y, $ln, 22, true); $y += 26; }
            if ($pg['concept'] !== '') { foreach (BudgetPdf::wrap($pg['concept'], 'F2', 12.5, $max) as $ln) { $pdf->text($L, $y, $ln, 12.5, true, '#333333'); $y += 17; } $y += 4; }
            if ($pg['approach'] !== '') $para($pg['approach'], 10.5);
            // as referências: 3 × 2, recortadas a 4:3
            $refs = cc_pdf_refs($pdo, $pg['refs']);
            if ($refs) {
                $gap = 10; $cw = ($max - 2 * $gap) / 3; $ch = $cw * 0.75;
                foreach (array_values($refs) as $i => $jpg) {
                    $pdf->image($jpg, $L + ($i % 3) * ($cw + $gap), $y + intdiv($i, 3) * ($ch + $gap), $cw, $ch);
                }
                $y += (int)ceil(count($refs) / 3) * ($ch + $gap) + 8;
            }
            if ($pg['palette']) {
                foreach ($pg['palette'] as $i => $hex) {
                    $pdf->rect($L + $i * 74, $y, 64, 26, $hex);
                    $pdf->text($L + $i * 74, $y + 38, $hex, 7.5, false, '#767676');
                }
                $y += 54;
            }
            $meta = array_filter([$pg['technique'] !== '' ? 'Técnica: ' . $pg['technique'] : '', $pg['complexity'] !== '' ? 'Complexidade: ' . mb_strtolower($pg['complexity']) : '']);
            if ($meta) { $pdf->text($L, $y, implode('   ·   ', $meta), 10, false, '#5C5C5C'); $y += 18; }
        }
        $footer($page);
        $pdf->page();
        $page++;
        $y = 64;
    }

    // fases e prazos
    $section('Fases e prazos');
    $days = $calc['days'];
    $months = $calc['months'];
    $phases = $v['dur_mode'] === 'phases' ? budget_phase_dates($v) : [];
    if ($phases) {
        foreach ($phases as $p) {
            $need(18);
            $pdf->text($L, $y, $p['name'] !== '' ? $p['name'] : 'Fase', 10.5, true);
            $pdf->text($L + 230, $y, budget_n((float)$p['days'], 1) . ((float)$p['days'] == 1 ? ' dia útil' : ' dias úteis'), 10.5);
            if ($p['from']) $pdf->text($R, $y, date('d/m/Y', strtotime($p['from'])) . ' – ' . date('d/m/Y', strtotime($p['to'])), 10.5, false, '#5C5C5C', 'r');
            $y += 17;
        }
        $y += 2;
    }
    $dur = 'Duração: ' . budget_n($days, 1) . ' dias úteis (cerca de ' . budget_months_label($months) . ')';
    if ($v['dur_mode'] === 'dates' && $v['start_date'] && $v['end_date']) {
        $dur .= ', de ' . date('d/m/Y', strtotime((string)$v['start_date'])) . ' a ' . date('d/m/Y', strtotime((string)$v['end_date']));
    } elseif ($v['start_date'] && $days > 0) {
        $dur .= ', com início a ' . date('d/m/Y', strtotime((string)$v['start_date'])) . ' e entrega prevista a ' . date('d/m/Y', strtotime(budget_add_workdays((string)$v['start_date'], $days)));
    }
    $para($dur . '.');

    // investimento (uma caixa por opção)
    $section('Investimento');
    $boxes = [['Opção A · Pedido', $price]];
    if ($calcB) $boxes[] = ['Opção B · Proposta de valor', $calcB['price']];
    if (!$calcB) $boxes[0][0] = '';
    foreach ($boxes as [$label, $p]) {
        $pv = round($p * $rate / 100, 2);
        $top = $label !== '' ? 16 : 0;
        $need(84 + $top);
        $pdf->rect($L, $y - 12, $max, 74 + $top, '#FFF6D6');
        $pdf->rect($L, $y - 12, 3, 74 + $top, '#FFC718');
        if ($label !== '') { $pdf->text($L + 16, $y + 2, mb_strtoupper($label), 8.5, true, '#5C5C5C'); $y += $top; }
        $pdf->text($L + 16, $y + 4, 'Preço s/ IVA', 10.5, false, '#5C5C5C');
        $pdf->text($R - 14, $y + 6, budget_eur($p, 2), 16, true, '#111111', 'r');
        $pdf->text($L + 16, $y + 25, 'IVA (' . budget_pct($rate) . ')', 10.5, false, '#5C5C5C');
        $pdf->text($R - 14, $y + 25, budget_eur($pv, 2), 10.5, false, '#111111', 'r');
        $pdf->text($L + 16, $y + 46, 'Total c/ IVA', 10.5, true);
        $pdf->text($R - 14, $y + 46, budget_eur($p + $pv, 2), 12, true, '#111111', 'r');
        $y += 90;
    }

    // as condições da proposta (§19.1): prazo, pagamento, revisões, direitos, validade
    foreach ([['Prazo', 'term_text'], ['Condições de pagamento', 'payment_terms'], ['Revisões incluídas', 'revisions_text'], ['Direitos de utilização', 'rights_text']] as [$t, $k]) {
        $txt = trim((string)($v[$k] ?? ''));
        if ($txt !== '') { $section($t); $para($txt, 10); }
    }
    $cond = trim((string)$v['conditions']);
    if ($cond !== '') { $section('Condições'); $para($cond, 10); }
    if (!empty($v['valid_until'])) { $section('Validade'); $para('Esta proposta é válida até ' . date('d/m/Y', strtotime((string)$v['valid_until'])) . '.', 10); }

    $footer($page);
    return $pdf->output('Proposta ' . $deal['code'] . ' · ' . $deal['name']);
}

/**
 * «Gerar proposta»: guarda o PDF na pasta do negócio no Drive (Propostas/) e regista no
 * histórico da folha. Sem Drive configurado, só se descarrega. Devolve a mensagem.
 */
function budget_pdf_publish(PDO $pdo, array $sheet, array $v): array {
    require_once __DIR__ . '/deal_drive.php';
    $deal = budget_deal_row($pdo, (int)$sheet['deal_id']);
    $bytes = budget_pdf_build($pdo, $deal, $v);
    $name = budget_pdf_name($deal, $v);
    $saved = null;
    if (r2_config()) {
        try {
            $folder = drive_deal_folder($pdo, $deal);
            $key = $folder . 'Propostas/' . $name;
            r2_put($key, $bytes, 'application/pdf');
            drive_catalog_invalidate();
            $saved = $key;
        } catch (Throwable $e) {
            error_log('budget_pdf drive: ' . $e->getMessage());
        }
    }
    budget_log($pdo, (int)$sheet['id'], 'b_pdf', 'v' . (int)$v['num'] . ' · ' . $name . ($saved ? ' · no Drive' : ''));
    return ['msg' => $saved ? 'Proposta guardada no Drive (' . preg_replace('~^Projetos/~', '', $saved) . ') e a descarregar.' : 'Proposta gerada e a descarregar' . (r2_config() ? ' (não foi possível guardar no Drive).' : ' (o Drive não está ligado neste servidor).'),
            'key' => $saved];
}

/** As referências de uma página do pré-criativo em JPEG 4:3 (o PDF só desenha JPEG). */
function cc_pdf_refs(PDO $pdo, array $ids): array {
    $out = [];
    foreach (array_slice($ids, 0, 6) as $id) {
        $a = cc_asset_get($pdo, (int)$id);
        $key = $a ? cc_thumb_pick($a['thumbs'], 768) : null;
        if (!$key) continue;
        try { $img = cc_gd_open(cc_store_get($key, 8388608)); } catch (Throwable $e) { $img = null; }
        if (!$img) continue;
        $w = imagesx($img); $h = imagesy($img);
        $tw = $w; $th = (int)round($w * 0.75);
        if ($th > $h) { $th = $h; $tw = (int)round($h / 0.75); }
        $crop = imagecreatetruecolor(640, 480);
        imagefill($crop, 0, 0, imagecolorallocate($crop, 255, 255, 255));
        imagecopyresampled($crop, $img, 0, 0, (int)(($w - $tw) / 2), (int)(($h - $th) / 2), 640, 480, $tw, $th);
        $out[] = cc_gd_jpeg($crop, 82);
    }
    return $out;
}

