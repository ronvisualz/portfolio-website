<?php
/**
 * topper. admin — Centro Criativo (§23): o modelo
 *
 *  · ficheiros: no Cloudflare R2 (em «.topper/cc/», invisível no Drive) ou, sem R2, numa pasta
 *    privada (inc/cc_files). As chaves guardam-se com o prefixo «r2:» ou «local:».
 *  · miniaturas por nível de zoom (256 · 768 · 1600 px, WebP) feitas a partir da pré-visualização
 *    que o browser envia (descodifica HEIC/AVIF e poupa memória ao servidor); sem ela, o GD faz.
 *  · paleta: extração determinística por k-means em Lab (nunca por IA); pesquisa por cor em ΔE2000.
 *  · biblioteca: cada referência existe uma vez (impressão digital do ficheiro); os quadros
 *    apontam para ela (cartões), sem cópia.
 *  · quadros e cartões, com «rev» por quadro para os outros separadores abertos se atualizarem.
 *
 * Permissões: tudo passa por cc_api.php / cc_file.php, que verificam a aba «criativo» no servidor.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/drive.php';   // r2.php e as ajudas do Drive (pastas, «Mostrar no Drive»)

/** Os sub-quadros do projeto, pela ordem (o modelo por defeito de cada funil). */
const CC_STAGES = [
    'brief'       => 'Brief',
    'refs'        => 'Referências',
    'conceito'    => 'Conceito',
    'tratamento'  => 'Tratamento',
    'styleframes' => 'Styleframes',
    'storyboard'  => 'Storyboard',
    'planos'      => 'Lista de planos',
    'review'      => 'Review',
];

const CC_KINDS = [
    'project'     => 'Projeto',
    'stage'       => 'Etapa',
    'precreative' => 'Pré-criativo',
    'front'       => 'Frente',
    'route'       => 'Rota não escolhida',
    'free'        => 'Quadro livre',
];

const CC_CARD_TYPES = ['image', 'video', 'link', 'note', 'list', 'column', 'swatch', 'drawing', 'file', 'arrow', 'board'];

/** Larguras das miniaturas (px). O browser escolhe pela largura no ecrã × densidade. */
const CC_THUMBS = [256, 768, 1600];

const CC_LANES = ['A' => 'Opção A · Pedido', 'B' => 'Opção B · Proposta de valor'];

/** Vocabulário controlado da topper. (editável em Definições › Centro Criativo). */
const CC_VOCAB = [
    'style' => ['Estilo', ['Minimalista', 'Cinematográfico', 'Editorial', 'Documental', 'Gráfico', 'Retro', 'Futurista', 'Surreal',
                            'Lúdico', 'Luxo', 'Natural', 'Industrial', 'Brutalista', 'Pop', 'Clean corporate']],
    'technique' => ['Técnica', ['Live action', 'CG 3D', 'Motion graphics 2D', 'Animação 2D', 'Stop motion', 'Tabletop', 'Fotografia',
                                'Ilustração', 'Mixed media', 'Drone', 'Macro', 'Timelapse', 'Colagem', 'Tipografia cinética', 'UGC']],
    'camera' => ['Câmara', ['Estática', 'Travelling', 'Dolly in', 'Dolly out', 'Panorâmica', 'Tilt', 'Grua', 'Steadicam / gimbal',
                            'Câmara à mão', 'Drone', 'Orbital', 'Zoom', 'Câmara lenta', 'Top shot']],
    'environment' => ['Ambiente', ['Estúdio', 'Fundo infinito', 'Exterior urbano', 'Natureza', 'Interior doméstico', 'Escritório',
                                   'Industrial', 'Retalho / loja', 'Praia / mar', 'Noturno', 'Abstrato / digital']],
    'light' => ['Luz', ['Natural suave', 'Natural dura', 'Hora dourada', 'Contraluz', 'Low key', 'High key', 'Néon / cor',
                        'Prática', 'Recorte', 'Difusa de estúdio', 'Noturna']],
];

// ── estado ──────────────────────────────────────────────────

/** O Centro Criativo existe depois do passo 24 da base. */
function cc_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $ok = (int)$pdo->query("SELECT svalue FROM settings WHERE skey = 'schema_version'")->fetchColumn() >= 24;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

function cc_now(): string {
    return date('Y-m-d H:i:s');
}

/** O vocabulário de uma faceta (Definições, uma por linha; por defeito, o da casa). */
function cc_vocab(PDO $pdo, string $facet): array {
    if (!isset(CC_VOCAB[$facet])) return [];
    return setting_lines($pdo, 'cc_vocab_' . $facet, CC_VOCAB[$facet][1]);
}

/** Os sub-quadros do projeto num funil (Definições; por defeito, todos). */
function cc_funnel_stages(PDO $pdo, ?int $funnelId): array {
    $raw = '';
    if ($funnelId) {
        $st = $pdo->prepare('SELECT cc_stages FROM funnels WHERE id = ?');
        $st->execute([$funnelId]);
        $raw = (string)$st->fetchColumn();
    }
    $keys = array_values(array_filter(explode(',', $raw), fn($k) => isset(CC_STAGES[$k])));
    return $keys ?: array_keys(CC_STAGES);
}

// ── ficheiros ───────────────────────────────────────────────

function cc_local_dir(): string {
    $d = __DIR__ . '/cc_files';
    if (!is_dir($d)) {
        @mkdir($d, 0750, true);
        @file_put_contents($d . '/.htaccess', "Require all denied\n");
    }
    return $d;
}

/** Guarda bytes e devolve a chave («r2:…» ou «local:…»). $rel: «a/ab/abcd….jpg». */
function cc_store_put(string $rel, string $bytes, string $mime): string {
    $rel = ltrim(preg_replace('~[^A-Za-z0-9/._-]~', '_', $rel), '/');
    if (r2_config()) {
        r2_put('.topper/cc/' . $rel, $bytes, $mime);
        return 'r2:.topper/cc/' . $rel;
    }
    $path = cc_local_dir() . '/' . $rel;
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0750, true);
    if (file_put_contents($path, $bytes) === false) throw new RuntimeException('Não foi possível guardar o ficheiro. Tente outra vez.');
    return 'local:' . $rel;
}

/** A chave R2 de um original enviado diretamente pelo browser. */
function cc_r2_orig_key(string $fp, string $name): string {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? '.' . $ext : '';
    return '.topper/cc/o/' . substr($fp, 0, 2) . '/' . $fp . $ext;
}

function cc_store_get(string $key, int $max = 157286400): string {
    if (strncmp($key, 'r2:', 3) === 0) return r2_get(substr($key, 3), null, $max);
    if (strncmp($key, 'local:', 6) === 0) {
        $p = cc_local_path($key);
        if ($p === null) throw new RuntimeException('Ficheiro em falta.');
        if (filesize($p) > $max) throw new RuntimeException('Ficheiro grande demais.');
        return (string)file_get_contents($p);
    }
    throw new RuntimeException('Ficheiro em falta.');
}

function cc_local_path(string $key): ?string {
    $rel = substr($key, 6);
    if ($rel === '' || strpos($rel, '..') !== false) return null;
    $p = cc_local_dir() . '/' . $rel;
    return is_file($p) ? $p : null;
}

function cc_store_delete(string $key): void {
    try {
        if (strncmp($key, 'r2:', 3) === 0) r2_delete(substr($key, 3));
        elseif (($p = cc_local_path($key)) !== null) @unlink($p);
    } catch (Throwable $e) {
        error_log('cc_store_delete: ' . $e->getMessage());
    }
}

/**
 * Onde fica no Drive o original de uma referência, pelo quadro onde entrou (§10.15):
 *   quadro de um negócio → «<pasta do negócio>/Criativo/Referências/» (raiz) ou «…/Criativo/<sub-quadro>/»
 *   quadro livre         → «Biblioteca criativa/<quadro>/<sub-quadro>/»
 *   sem quadro           → «Biblioteca criativa/Caixa de entrada/» ou «Biblioteca criativa/Sem quadro/»
 * Devolve as opções para files_adopt (deal_id + prefix).
 */
function cc_drive_place(PDO $pdo, ?array $board, bool $inbox, bool $create = true): array {
    require_once __DIR__ . '/files.php';
    if (!$board) return ['prefix' => FILES_LIBRARY . ($inbox ? 'Caixa de entrada/' : 'Sem quadro/')];
    $path = cc_board_path($pdo, $board);
    $names = array_map(fn($b) => r2_clean_name(trim(preg_replace('/^Pré-criativo · /u', '', (string)$b['title']))) ?: 'Quadro', $path);
    $dealId = (int)($path[0]['deal_id'] ?? 0);
    // sem $create (a pré-visualização): a pasta que vai ter, sem a criar
    if ($dealId && ($d = files_deal_row($pdo, $dealId)) && ($f = files_folder($pdo, $d, $create) ?? ($create ? null : files_place($d) . files_leaf($d) . '/'))) {
        $sub = array_slice($names, 1);
        return ['deal_id' => $dealId, 'section' => 'criativo', 'prefix' => $f . 'Criativo/' . ($sub ? implode('/', $sub) : 'Referências') . '/'];
    }
    return ['prefix' => FILES_LIBRARY . implode('/', $names) . '/'];
}

/**
 * Um original acabado de chegar (em .topper/cc/o/) passa para o seu sítio no Drive e fica no
 * registo. As miniaturas e os frames continuam no sistema. Sem Drive, nada muda.
 */
function cc_place_original(PDO $pdo, array $asset, ?array $board, bool $inbox, ?int $by): array {
    $key = (string)$asset['file_key'];
    if (strncmp($key, 'r2:.topper/cc/o/', 16) !== 0 || !r2_config()) return $asset;
    try {
        $o = cc_drive_place($pdo, $board, $inbox) + ['name' => (string)$asset['file_name'] ?: drive_base($key), 'mime' => (string)$asset['mime'],
                                                      'origin' => 'cc', 'cc_asset_id' => (int)$asset['id'], 'label' => (string)$asset['title'], 'by' => $by];
        $new = files_adopt($pdo, substr($key, 3), $o);
        if ($new !== substr($key, 3)) {
            $pdo->prepare('UPDATE cc_assets SET file_key = ? WHERE id = ?')->execute(['r2:' . $new, (int)$asset['id']]);
            $asset['file_key'] = 'r2:' . $new;
        }
    } catch (Throwable $e) {
        error_log('cc_place_original ' . $asset['id'] . ': ' . $e->getMessage());   // fica no sistema; nada se perde
    }
    return $asset;
}

/** Impressão digital de um ficheiro: sha1(primeiros 4 MiB + últimos 4 MiB + tamanho) — o browser calcula igual. */
function cc_fingerprint(string $bytes): string {
    $n = strlen($bytes);
    $mb = 4194304;
    return sha1(substr($bytes, 0, $mb) . ($n > $mb ? substr($bytes, max($mb, $n - $mb)) : '') . $n);
}

/** O endereço de uma miniatura (ou do original, $size = 0) para a interface. */
function cc_file_url(array $asset, int $size = 768): string {
    return '/admin/cc_file.php?a=' . (int)$asset['id'] . '&s=' . $size . '&v=' . substr((string)$asset['sha1'], 0, 8);
}

function cc_frame_url(array $frame, int $size = 768): string {
    return '/admin/cc_file.php?f=' . (int)$frame['id'] . '&s=' . $size;
}

// ── imagens: miniaturas e paleta ────────────────────────────

/** Abre uma imagem (GD), já direita (EXIF). Recusa imagens gigantes (memória do servidor). */
function cc_gd_open(string $bytes, int $maxPixels = 36000000): ?GdImage {
    if (!function_exists('imagecreatefromstring')) return null;
    $info = @getimagesizefromstring($bytes);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > $maxPixels) return null;
    $img = @imagecreatefromstring($bytes);
    if (!$img) return null;
    if (($info[2] ?? 0) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data('data://image/jpeg;base64,' . base64_encode(substr($bytes, 0, 131072)));
        $o = (int)($ex['Orientation'] ?? 1);
        if ($o === 3) $img = imagerotate($img, 180, 0);
        elseif ($o === 6) $img = imagerotate($img, -90, 0);
        elseif ($o === 8) $img = imagerotate($img, 90, 0);
    }
    return $img;
}

/** Redimensiona para a largura $w (nunca aumenta). */
function cc_gd_fit(GdImage $img, int $w): GdImage {
    $sw = imagesx($img); $sh = imagesy($img);
    if ($sw <= $w) return $img;
    $h = max(1, (int)round($sh * $w / $sw));
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopyresampled($out, $img, 0, 0, 0, 0, $w, $h, $sw, $sh);
    return $out;
}

/** GD → WebP (ou JPEG, se o servidor não tiver WebP). Devolve [bytes, mime, ext]. */
function cc_gd_encode(GdImage $img, int $q = 82): array {
    ob_start();
    if (function_exists('imagewebp')) { imagewebp($img, null, $q); $mime = 'image/webp'; $ext = 'webp'; }
    else { imagejpeg($img, null, $q); $mime = 'image/jpeg'; $ext = 'jpg'; }
    return [(string)ob_get_clean(), $mime, $ext];
}

/** JPEG (para o PDF da proposta, que só sabe desenhar JPEG). */
function cc_gd_jpeg(GdImage $img, int $q = 84): string {
    $w = imagesx($img); $h = imagesy($img);
    $flat = imagecreatetruecolor($w, $h);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
    ob_start();
    imagejpeg($flat, null, $q);
    return (string)ob_get_clean();
}

/** As miniaturas a partir de uma imagem: [largura => chave]. $base: «t/ab/abcd…». */
function cc_thumbs_make(GdImage $img, string $base): array {
    $out = [];
    $last = 0;
    foreach (CC_THUMBS as $w) {
        $fit = cc_gd_fit($img, $w);
        $fw = imagesx($fit);
        if ($fw <= $last) continue;   // a imagem é mais pequena do que esta largura: a anterior serve
        [$bytes, $mime, $ext] = cc_gd_encode($fit, $w <= 256 ? 76 : 82);
        $out[(string)$w] = cc_store_put($base . '_' . $w . '.' . $ext, $bytes, $mime);
        $last = $fw;
    }
    return $out;
}

/** A miniatura mais próxima (por cima) de $want, a partir do JSON guardado. */
function cc_thumb_pick(?string $json, int $want): ?string {
    $t = json_decode((string)$json, true);
    if (!is_array($t) || !$t) return null;
    ksort($t, SORT_NUMERIC);
    foreach ($t as $w => $k) if ((int)$w >= $want) return $k;
    return end($t);
}

// ── cor (Lab, ΔE2000) ───────────────────────────────────────

function cc_rgb_lab(int $r, int $g, int $b): array {
    $f = static function (float $c): float { $c /= 255; return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; };
    $R = $f($r); $G = $f($g); $B = $f($b);
    $x = ($R * 0.4124564 + $G * 0.3575761 + $B * 0.1804375) / 0.95047;
    $y = ($R * 0.2126729 + $G * 0.7151522 + $B * 0.0721750);
    $z = ($R * 0.0193339 + $G * 0.1191920 + $B * 0.9503041) / 1.08883;
    $p = static fn(float $t): float => $t > 0.008856 ? $t ** (1 / 3) : 7.787 * $t + 16 / 116;
    $fx = $p($x); $fy = $p($y); $fz = $p($z);
    return [116 * $fy - 16, 500 * ($fx - $fy), 200 * ($fy - $fz)];
}

function cc_lab_rgb(float $L, float $a, float $b): array {
    $fy = ($L + 16) / 116; $fx = $fy + $a / 500; $fz = $fy - $b / 200;
    $q = static fn(float $t): float => $t ** 3 > 0.008856 ? $t ** 3 : ($t - 16 / 116) / 7.787;
    $x = $q($fx) * 0.95047; $y = $q($fy); $z = $q($fz) * 1.08883;
    $R = $x * 3.2404542 - $y * 1.5371385 - $z * 0.4985314;
    $G = -$x * 0.9692660 + $y * 1.8760108 + $z * 0.0415560;
    $B = $x * 0.0556434 - $y * 0.2040259 + $z * 1.0572252;
    $g = static function (float $c): int { $c = $c <= 0.0031308 ? 12.92 * $c : 1.055 * ($c ** (1 / 2.4)) - 0.055; return max(0, min(255, (int)round($c * 255))); };
    return [$g($R), $g($G), $g($B)];
}

function cc_hex_lab(string $hex): ?array {
    if (!preg_match('/^#?([0-9a-f]{6})$/i', trim($hex), $m)) return null;
    return cc_rgb_lab(hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2)));
}

/** ΔE2000 (CIEDE2000) entre duas cores Lab. */
function cc_de2000(array $l1, array $l2): float {
    [$L1, $a1, $b1] = $l1; [$L2, $a2, $b2] = $l2;
    $C1 = sqrt($a1 * $a1 + $b1 * $b1); $C2 = sqrt($a2 * $a2 + $b2 * $b2);
    $Cm = ($C1 + $C2) / 2;
    $G = 0.5 * (1 - sqrt($Cm ** 7 / ($Cm ** 7 + 25 ** 7)));
    $a1p = $a1 * (1 + $G); $a2p = $a2 * (1 + $G);
    $C1p = sqrt($a1p * $a1p + $b1 * $b1); $C2p = sqrt($a2p * $a2p + $b2 * $b2);
    $h = static function (float $b, float $a): float { if ($a == 0.0 && $b == 0.0) return 0.0; $d = rad2deg(atan2($b, $a)); return $d < 0 ? $d + 360 : $d; };
    $h1p = $h($b1, $a1p); $h2p = $h($b2, $a2p);
    $dL = $L2 - $L1; $dC = $C2p - $C1p;
    if ($C1p * $C2p == 0.0) $dhp = 0.0;
    else { $dhp = $h2p - $h1p; if ($dhp > 180) $dhp -= 360; elseif ($dhp < -180) $dhp += 360; }
    $dH = 2 * sqrt($C1p * $C2p) * sin(deg2rad($dhp / 2));
    $Lm = ($L1 + $L2) / 2; $Cmp = ($C1p + $C2p) / 2;
    if ($C1p * $C2p == 0.0) $hm = $h1p + $h2p;
    elseif (abs($h1p - $h2p) <= 180) $hm = ($h1p + $h2p) / 2;
    else $hm = ($h1p + $h2p + ($h1p + $h2p < 360 ? 360 : -360)) / 2;
    $T = 1 - 0.17 * cos(deg2rad($hm - 30)) + 0.24 * cos(deg2rad(2 * $hm)) + 0.32 * cos(deg2rad(3 * $hm + 6)) - 0.20 * cos(deg2rad(4 * $hm - 63));
    $dTh = 30 * exp(-((($hm - 275) / 25) ** 2));
    $Rc = 2 * sqrt($Cmp ** 7 / ($Cmp ** 7 + 25 ** 7));
    $Sl = 1 + (0.015 * (($Lm - 50) ** 2)) / sqrt(20 + (($Lm - 50) ** 2));
    $Sc = 1 + 0.045 * $Cmp; $Sh = 1 + 0.015 * $Cmp * $T;
    $Rt = -sin(deg2rad(2 * $dTh)) * $Rc;
    return sqrt(($dL / $Sl) ** 2 + ($dC / $Sc) ** 2 + ($dH / $Sh) ** 2 + $Rt * ($dC / $Sc) * ($dH / $Sh));
}

/**
 * A paleta de uma imagem: k-means ponderado em Lab sobre o histograma (6 bits por canal) de
 * uma versão de 96 px. Determinística: arranque pelo tom mais frequente e depois sempre o mais
 * afastado (ponderado), 14 iterações, cores quase iguais (ΔE < 7) juntas. Devolve até $k cores
 * [hex, peso 0–1, L, a, b], da mais presente para a menos.
 */
function cc_palette(GdImage $img, int $k = 6): array {
    $small = cc_gd_fit($img, 96);
    $w = imagesx($small); $h = imagesy($small);
    $hist = [];
    $true = imageistruecolor($small);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($small, $x, $y);
            if ($true) { $a = ($c >> 24) & 0x7F; $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF; }
            else { $i = imagecolorsforindex($small, $c); $a = $i['alpha']; $r = $i['red']; $g = $i['green']; $b = $i['blue']; }
            if ($a > 100) continue;   // transparente
            $q = (($r >> 2) << 12) | (($g >> 2) << 6) | ($b >> 2);
            $hist[$q] = ($hist[$q] ?? 0) + 1;
        }
    }
    if (!$hist) return [];
    ksort($hist);
    $pts = []; $wts = []; $total = 0;
    foreach ($hist as $q => $n) {
        $r = (($q >> 12) & 63) * 4 + 2; $g = (($q >> 6) & 63) * 4 + 2; $b = ($q & 63) * 4 + 2;
        $pts[] = cc_rgb_lab($r, $g, $b); $wts[] = $n; $total += $n;
    }
    $N = count($pts);
    $k = min($k, $N);
    $d2 = static fn(array $p, array $q): float => ($p[0] - $q[0]) ** 2 + ($p[1] - $q[1]) ** 2 + ($p[2] - $q[2]) ** 2;
    // arranque: o mais frequente; depois o que maximiza peso × distância² ao centro mais próximo
    $first = array_keys($wts, max($wts))[0];
    $C = [$pts[$first]];
    $minD = array_map(fn($p) => $d2($p, $C[0]), $pts);
    while (count($C) < $k) {
        $best = -1; $bi = -1;
        for ($i = 0; $i < $N; $i++) { $s = $wts[$i] * $minD[$i]; if ($s > $best) { $best = $s; $bi = $i; } }
        if ($best <= 0) break;
        $C[] = $pts[$bi];
        for ($i = 0; $i < $N; $i++) $minD[$i] = min($minD[$i], $d2($pts[$i], $pts[$bi]));
    }
    $assign = array_fill(0, $N, 0);
    for ($it = 0; $it < 14; $it++) {
        $sum = array_fill(0, count($C), [0.0, 0.0, 0.0, 0.0]);
        $moved = 0;
        for ($i = 0; $i < $N; $i++) {
            $bj = 0; $bd = INF;
            foreach ($C as $j => $c) { $d = $d2($pts[$i], $c); if ($d < $bd) { $bd = $d; $bj = $j; } }
            if ($assign[$i] !== $bj) { $assign[$i] = $bj; $moved++; }
            $s = &$sum[$bj];
            $s[0] += $pts[$i][0] * $wts[$i]; $s[1] += $pts[$i][1] * $wts[$i]; $s[2] += $pts[$i][2] * $wts[$i]; $s[3] += $wts[$i];
            unset($s);
        }
        foreach ($sum as $j => $s) if ($s[3] > 0) $C[$j] = [$s[0] / $s[3], $s[1] / $s[3], $s[2] / $s[3]];
        if ($it > 0 && $moved === 0) break;
    }
    $weight = array_fill(0, count($C), 0);
    for ($i = 0; $i < $N; $i++) $weight[$assign[$i]] += $wts[$i];
    // juntar as quase iguais
    $out = [];
    foreach ($C as $j => $c) {
        if ($weight[$j] <= 0) continue;
        foreach ($out as &$o) {
            if (cc_de2000([$o[2], $o[3], $o[4]], $c) < 7) {
                $t = $o[1] + $weight[$j];
                $o[2] = ($o[2] * $o[1] + $c[0] * $weight[$j]) / $t; $o[3] = ($o[3] * $o[1] + $c[1] * $weight[$j]) / $t; $o[4] = ($o[4] * $o[1] + $c[2] * $weight[$j]) / $t;
                $o[1] = $t;
                continue 2;
            }
        }
        unset($o);
        $out[] = ['', $weight[$j], $c[0], $c[1], $c[2]];
    }
    unset($o);
    usort($out, fn($a, $b) => $b[1] <=> $a[1] ?: $a[2] <=> $b[2]);
    foreach ($out as &$o) {
        [$r, $g, $b] = cc_lab_rgb($o[2], $o[3], $o[4]);
        $o = [sprintf('#%02X%02X%02X', $r, $g, $b), round($o[1] / $total, 3), round($o[2], 2), round($o[3], 2), round($o[4], 2)];
    }
    return $out;
}

/**
 * A cor de uma paleta mais próxima de $lab (só cores com peso ≥ 4%): [ordem, ΔE2000], ou null.
 * A ordem dá um pequeno bónus às cores mais presentes na imagem; o ΔE é o real.
 */
function cc_palette_distance(?string $json, array $lab): ?array {
    $p = json_decode((string)$json, true);
    if (!is_array($p)) return null;
    $best = null;
    foreach ($p as $c) {
        if (!is_array($c) || count($c) < 5 || (float)$c[1] < 0.04) continue;
        $de = cc_de2000([(float)$c[2], (float)$c[3], (float)$c[4]], $lab);
        $score = $de - min(4.0, (float)$c[1] * 10);
        if ($best === null || $score < $best[0]) $best = [$score, $de];
    }
    return $best;
}

/** As cores (hex) de uma paleta guardada. */
function cc_palette_hex(?string $json, int $n = 6): array {
    $p = json_decode((string)$json, true);
    if (!is_array($p)) return [];
    $out = [];
    foreach ($p as $c) if (is_array($c) && isset($c[0])) $out[] = ['hex' => (string)$c[0], 'w' => (float)($c[1] ?? 0)];
    return array_slice($out, 0, $n);
}

// ── biblioteca ──────────────────────────────────────────────

function cc_asset_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cc_assets WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function cc_asset_by_fp(PDO $pdo, string $fp): ?array {
    if (!preg_match('/^[a-f0-9]{40}$/', $fp)) return null;
    $st = $pdo->prepare('SELECT * FROM cc_assets WHERE sha1 = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
    $st->execute([$fp]);
    return $st->fetch() ?: null;
}

function cc_kind_for(string $mime, string $name): string {
    if (str_starts_with($mime, 'image/') && $mime !== 'image/vnd.adobe.photoshop') return 'image';
    if (str_starts_with($mime, 'video/')) return 'video';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'bmp'], true)) return 'image';
    if (in_array($ext, ['mp4', 'mov', 'm4v', 'webm', 'mkv'], true)) return 'video';
    return 'file';
}

/** O texto de pesquisa de uma referência (minúsculas e sem acentos). */
function cc_search_text(array $a): string {
    $an = json_decode((string)($a['analysis'] ?? ''), true);
    $parts = [$a['title'] ?? '', $a['file_name'] ?? '', $a['source_url'] ?? '', $a['tags'] ?? '', $a['style'] ?? '', $a['technique'] ?? '',
              $a['camera'] ?? '', $a['environment'] ?? '', $a['light'] ?? '', $a['transcript'] ?? ''];
    if (is_array($an)) {
        foreach (['caption', 'composition', 'color', 'why', 'movement', 'notes'] as $k) $parts[] = is_string($an[$k] ?? null) ? $an[$k] : '';
        foreach (['tags', 'subjects', 'moods'] as $k) if (is_array($an[$k] ?? null)) $parts[] = implode(' ', array_filter($an[$k], 'is_string'));
    }
    return cc_fold(mb_substr(trim(preg_replace('/\s+/u', ' ', implode(' ', $parts))), 0, 60000));
}

function cc_fold(string $s): string {
    if (function_exists('ai_fold')) return ai_fold($s);
    require_once __DIR__ . '/ai.php';
    return ai_fold($s);
}

function cc_asset_reindex(PDO $pdo, int $id): void {
    $a = cc_asset_get($pdo, $id);
    if ($a) $pdo->prepare('UPDATE cc_assets SET search_text = ? WHERE id = ?')->execute([cc_search_text($a), $id]);
}

/**
 * Cria (ou reaproveita) uma referência a partir dos bytes já recebidos.
 *  $o: name, mime, kind, by, origin, deal_id, inbox, source_url, title, preview (bytes da
 *      pré-visualização feita pelo browser), file_key (original já no R2), size, w, h, duration, fp
 * Devolve [asset, novo?].
 */
function cc_asset_create(PDO $pdo, ?string $bytes, array $o): array {
    $name = mb_substr(trim((string)($o['name'] ?? '')), 0, 255);
    $mime = mb_substr((string)($o['mime'] ?? ''), 0, 100) ?: ($name !== '' ? r2_mime($name) : 'application/octet-stream');
    $fp = (string)($o['fp'] ?? '');
    if ($bytes !== null) $fp = cc_fingerprint($bytes);
    if ($fp !== '' && ($dup = cc_asset_by_fp($pdo, $fp))) return [$dup, false];
    $kind = (string)($o['kind'] ?? '') ?: cc_kind_for($mime, $name);
    if (!in_array($kind, ['image', 'video', 'link', 'file'], true)) $kind = 'file';
    if ($fp === '') $fp = sha1(($o['source_url'] ?? '') . '|' . microtime(true) . '|' . random_int(0, PHP_INT_MAX));

    // o original
    $fileKey = (string)($o['file_key'] ?? '');
    if ($bytes !== null && $kind !== 'link') {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? '.' . $ext : '';
        $fileKey = cc_store_put('o/' . substr($fp, 0, 2) . '/' . $fp . $ext, $bytes, $mime);
    }
    // miniaturas e paleta: da pré-visualização do browser ou do próprio ficheiro
    $thumbs = []; $palette = []; $w = (int)($o['w'] ?? 0); $h = (int)($o['h'] ?? 0);
    $src = $o['preview'] ?? null;
    if ($src === null && $kind === 'image' && $bytes !== null) $src = $bytes;
    if (is_string($src) && $src !== '' && ($img = cc_gd_open($src))) {
        if ($kind === 'image' && (!$w || !$h) && $bytes === $src) { $w = imagesx($img); $h = imagesy($img); }
        if (!$w || !$h) { $w = imagesx($img); $h = imagesy($img); }
        $thumbs = cc_thumbs_make($img, 't/' . substr($fp, 0, 2) . '/' . $fp);
        $palette = cc_palette($img);
    }
    require_once __DIR__ . '/cc_ai.php';
    $cfg = cc_ai_config();
    $auto = $thumbs && in_array($kind, ['image', 'video', 'link'], true) && $cfg && $cfg['auto'] && empty($o['no_ai']);
    $now = cc_now();
    $pdo->prepare('INSERT INTO cc_assets (kind, title, source_url, file_key, file_name, mime, width, height, duration, bytes, sha1, thumbs, palette,
                                          ai_status, origin, deal_id, inbox, created_by, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$kind, mb_substr(trim((string)($o['title'] ?? '')) ?: preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name), 0, 255),
                   mb_substr((string)($o['source_url'] ?? ''), 0, 1000), $fileKey, $name, $mime, $w ?: null, $h ?: null,
                   isset($o['duration']) && $o['duration'] !== '' ? round((float)$o['duration'], 3) : null,
                   $bytes !== null ? strlen($bytes) : ((int)($o['size'] ?? 0) ?: null), $fp,
                   $thumbs ? json_encode($thumbs) : null, $palette ? json_encode($palette) : null,
                   $auto ? 'queued' : '',
                   ($o['origin'] ?? '') === 'topper' ? 'topper' : 'external', !empty($o['deal_id']) ? (int)$o['deal_id'] : null,
                   !empty($o['inbox']) ? 1 : 0, $o['by'] ?? null, $now]);
    $id = (int)$pdo->lastInsertId();
    cc_asset_reindex($pdo, $id);
    // vídeos: a análise espera pelos frames-chave (cc_api junta-os e põe na fila)
    if ($auto && $kind !== 'video') cc_job_add($pdo, 'analyze', $id, null, null, $o['by'] ?? null);
    return [cc_asset_get($pdo, $id), true];
}

/** Junta um frame-chave a um vídeo (bytes da imagem do frame, t em segundos). */
function cc_frame_add(PDO $pdo, array $asset, string $bytes, float $t, int $pos): ?int {
    $img = cc_gd_open($bytes);
    if (!$img) return null;
    $base = 'f/' . substr((string)$asset['sha1'], 0, 2) . '/' . $asset['sha1'] . '_' . $pos;
    [$enc, $mime, $ext] = cc_gd_encode(cc_gd_fit($img, 1280));
    $key = cc_store_put($base . '.' . $ext, $enc, $mime);
    $thumbs = cc_thumbs_make($img, $base);
    $pdo->prepare('INSERT INTO cc_frames (asset_id, t, file_key, thumbs, palette, position) VALUES (?,?,?,?,?,?)')
        ->execute([(int)$asset['id'], round($t, 3), $key, json_encode($thumbs), json_encode(cc_palette($img)), $pos]);
    return (int)$pdo->lastInsertId();
}

function cc_frames(PDO $pdo, int $assetId): array {
    $st = $pdo->prepare('SELECT * FROM cc_frames WHERE asset_id = ? ORDER BY t, position');
    $st->execute([$assetId]);
    return $st->fetchAll();
}

/** A referência para a interface (sem chaves de ficheiro nem embeddings). */
function cc_asset_public(array $a, bool $full = false): array {
    $an = json_decode((string)($a['analysis'] ?? ''), true);
    $out = [
        'id' => (int)$a['id'], 'kind' => $a['kind'], 'title' => (string)$a['title'], 'w' => (int)$a['width'], 'h' => (int)$a['height'],
        'dur' => $a['duration'] !== null ? (float)$a['duration'] : null, 'url' => (string)$a['source_url'],
        'thumb' => $a['thumbs'] ? '/admin/cc_file.php?a=' . (int)$a['id'] . '&v=' . substr((string)$a['sha1'], 0, 8) : '',
        'tw' => array_map('intval', array_keys(json_decode((string)$a['thumbs'], true) ?: [])),
        'orig' => $a['file_key'] !== '' ? '/admin/cc_file.php?a=' . (int)$a['id'] . '&s=0' : '',
        'pal' => array_column(cc_palette_hex($a['palette']), 'hex'),
        'ai' => (string)$a['ai_status'], 'origin' => (string)$a['origin'], 'inbox' => (int)$a['inbox'],
        'caption' => is_array($an) ? (string)($an['caption'] ?? '') : '',
        'facets' => array_filter(['style' => $a['style'], 'technique' => $a['technique'], 'camera' => $a['camera'], 'environment' => $a['environment'], 'light' => $a['light']]),
        'tags' => array_values(array_filter(array_map('trim', explode(',', (string)$a['tags'])))),
        'file' => (string)$a['file_name'], 'mime' => (string)$a['mime'],
    ];
    if ($full) {
        $out['analysis'] = is_array($an) ? $an : null;
        $out['ai_error'] = (string)$a['ai_error'];
        $out['ai_model'] = (string)$a['ai_model'];
        $out['edited'] = $a['edited_at'] ? date('d/m/Y H:i', strtotime((string)$a['edited_at'])) : '';
        $out['bytes'] = (int)$a['bytes'];
        $out['deal_id'] = $a['deal_id'] !== null ? (int)$a['deal_id'] : null;
        $out['created'] = date('d/m/Y', strtotime((string)$a['created_at']));
        $out['has_embed'] = $a['embedding'] !== null && $a['embedding'] !== '';
        $out['transcript'] = (string)($a['transcript'] ?? '');
        // onde o original está no Drive (§10.15): «Mostrar no Drive»
        $k = strncmp((string)$a['file_key'], 'r2:', 3) === 0 ? substr((string)$a['file_key'], 3) : '';
        if ($k !== '' && !drive_hidden($k) && can('drive') && drive_can_see($k)) {
            $out['drive'] = drive_page(drive_parent($k), 'f=' . rawurlencode($k));
            $out['drive_path'] = drive_parent($k);
        }
    }
    return $out;
}

/** Guarda a edição humana da análise (facetas, etiquetas, legenda, «porque funciona»). */
function cc_asset_edit(PDO $pdo, array $a, array $in, int $by): void {
    $an = json_decode((string)$a['analysis'], true);
    if (!is_array($an)) $an = [];
    foreach (['caption', 'why', 'composition', 'color', 'movement'] as $k) if (array_key_exists($k, $in)) $an[$k] = mb_substr(trim((string)$in[$k]), 0, 2000);
    $an['human'] = true;
    $set = ['analysis' => json_encode($an, JSON_UNESCAPED_UNICODE), 'edited_by' => $by, 'edited_at' => cc_now()];
    if (array_key_exists('title', $in)) $set['title'] = mb_substr(trim((string)$in['title']), 0, 255);
    foreach (array_keys(CC_VOCAB) as $f) if (array_key_exists($f, $in)) $set[$f] = mb_substr(trim((string)$in[$f]), 0, 60);
    if (array_key_exists('tags', $in)) $set['tags'] = cc_tags_clean((string)$in['tags']);
    if (array_key_exists('origin', $in)) $set['origin'] = $in['origin'] === 'topper' ? 'topper' : 'external';
    $sql = implode(', ', array_map(fn($k) => $k . ' = ?', array_keys($set)));
    $pdo->prepare("UPDATE cc_assets SET $sql WHERE id = ?")->execute([...array_values($set), (int)$a['id']]);
    cc_asset_reindex($pdo, (int)$a['id']);
}

function cc_tags_clean(string $raw): string {
    $t = [];
    foreach (preg_split('/[,;\n]+/u', $raw) as $x) {
        $x = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $x)));
        if ($x !== '' && mb_strlen($x) <= 40) $t[$x] = true;
    }
    return mb_substr(implode(', ', array_slice(array_keys($t), 0, 30)), 0, 1000);
}

// ── links: pré-visualização (o servidor vai buscar a página, com guarda contra SSRF) ──

/** Só http(s) para endereços públicos (nada de 127.0.0.1, redes privadas nem link-local). */
function cc_url_public(string $url): bool {
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
    if (isset($p['user']) || isset($p['pass'])) return false;
    $host = trim($p['host'], '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
        array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'), array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
    if (!$ips) { $ip = @gethostbyname($host); if ($ip !== $host) $ips = [$ip]; }
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

/** GET com limites (tamanho, tempo, redirecionamentos verificados um a um). Devolve [corpo, tipo, url final] ou null. */
function cc_fetch(string $url, int $max = 3145728, int $timeout = 8): ?array {
    if (!function_exists('curl_init')) return null;
    for ($hop = 0; $hop < 4; $hop++) {
        if (!cc_url_public($url)) return null;
        $buf = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; topperBot/1.0; +https://topperdigital.xyz)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,image/*;q=0.9,*/*;q=0.5', 'Accept-Language: pt-PT,pt;q=0.9,en;q=0.8'],
            CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$buf, $max) { $buf .= $chunk; return strlen($buf) > $max ? 0 : strlen($chunk); },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
        if ($code !== 200 || $buf === '') return null;
        return [$buf, strtolower(trim(explode(';', $type)[0])), $url];
    }
    return null;
}

/** Título, descrição e imagem de uma página (Open Graph / Twitter / <title>). */
function cc_link_meta(string $url): array {
    $out = ['title' => '', 'description' => '', 'image' => '', 'site' => (string)parse_url($url, PHP_URL_HOST)];
    $r = cc_fetch($url, 1572864);
    if (!$r) return $out;
    [$html, $type, $final] = $r;
    if (str_starts_with($type, 'image/')) { $out['image'] = $final; return $out; }
    if (!preg_match_all('~<meta\s[^>]*>~i', $html, $m)) $m = [[]];
    $meta = [];
    foreach ($m[0] as $tag) {
        if (preg_match('~(?:property|name)\s*=\s*["\']([^"\']+)["\']~i', $tag, $k) && preg_match('~content\s*=\s*["\']([^"\']*)["\']~i', $tag, $v)) {
            $meta[strtolower($k[1])] = html_entity_decode($v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    $out['title'] = $meta['og:title'] ?? $meta['twitter:title'] ?? (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $t) ? html_entity_decode(trim($t[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '');
    $out['description'] = $meta['og:description'] ?? $meta['description'] ?? $meta['twitter:description'] ?? '';
    $img = $meta['og:image'] ?? $meta['og:image:url'] ?? $meta['twitter:image'] ?? '';
    if ($img !== '' && !preg_match('~^https?://~i', $img)) {
        $b = parse_url($final);
        $img = str_starts_with($img, '//') ? ($b['scheme'] ?? 'https') . ':' . $img : ($b['scheme'] ?? 'https') . '://' . $b['host'] . '/' . ltrim($img, '/');
    }
    $out['image'] = $img;
    $out['site'] = $meta['og:site_name'] ?? $out['site'];
    foreach (['title', 'description', 'site'] as $k) $out[$k] = mb_substr(trim(preg_replace('/\s+/u', ' ', $out[$k])), 0, $k === 'description' ? 600 : 200);
    return $out;
}

/** Uma referência a partir de um link: pré-visualização com a imagem da página (guardada cá, a CSP não deixa ir lá fora). */
function cc_asset_from_link(PDO $pdo, string $url, array $o): array {
    $url = mb_substr(trim($url), 0, 1000);
    if (!preg_match('~^https?://~i', $url)) throw new RuntimeException('Endereço inválido.');
    $st = $pdo->prepare("SELECT * FROM cc_assets WHERE source_url = ? AND kind = 'link' AND deleted_at IS NULL ORDER BY id LIMIT 1");
    $st->execute([$url]);
    if ($dup = $st->fetch()) return [$dup, false];
    $meta = cc_link_meta($url);
    $preview = null;
    if ($meta['image'] !== '' && ($r = cc_fetch($meta['image'], 8388608)) && str_starts_with($r[1], 'image/')) $preview = $r[0];
    [$a, $new] = cc_asset_create($pdo, null, $o + ['kind' => 'link', 'source_url' => $url, 'title' => $meta['title'] ?: $meta['site'], 'preview' => $preview,
                                                   'name' => '', 'mime' => 'text/html']);
    if ($new) {
        $an = ['caption' => $meta['description'], 'site' => $meta['site']];
        $pdo->prepare('UPDATE cc_assets SET analysis = ? WHERE id = ?')->execute([json_encode($an, JSON_UNESCAPED_UNICODE), (int)$a['id']]);
        cc_asset_reindex($pdo, (int)$a['id']);
        $a = cc_asset_get($pdo, (int)$a['id']);
    }
    return [$a, $new];
}

// ── fila (IA e importações) ─────────────────────────────────

function cc_job_add(PDO $pdo, string $kind, ?int $assetId, ?int $boardId, ?array $payload, ?int $by): int {
    if ($assetId) {
        $st = $pdo->prepare("SELECT id FROM cc_jobs WHERE kind = ? AND asset_id = ? AND status IN ('queued','running')");
        $st->execute([$kind, $assetId]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    $pdo->prepare('INSERT INTO cc_jobs (kind, asset_id, board_id, payload, status, created_by, created_at) VALUES (?,?,?,?,\'queued\',?,?)')
        ->execute([$kind, $assetId, $boardId, $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, $by, cc_now()]);
    return (int)$pdo->lastInsertId();
}

/** Quantos trabalhos esperam (para a interface saber se continua a pedir). */
function cc_jobs_pending(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM cc_jobs WHERE status = 'queued' OR (status = 'running' AND started_at < '" . date('Y-m-d H:i:s', time() - 900) . "')")->fetchColumn();
}

/**
 * Corre trabalhos da fila até $seconds (um de cada vez, com tranca: dois pedidos nunca pegam
 * no mesmo). Trabalhos «a correr» há mais de 15 min voltam à fila (o pedido morreu). Devolve quantos fez.
 */
function cc_jobs_run(PDO $pdo, float $seconds = 20.0): int {
    require_once __DIR__ . '/cc_ai.php';
    $end = microtime(true) + $seconds;
    $n = 0;
    while (microtime(true) < $end) {
        // presos há mais de 15 min (4 h para os vídeos do Review, que o ffmpeg demora a preparar): o pedido morreu
        $stale = "status = 'running' AND ((kind NOT IN ('rv_proc','rv_wm') AND started_at < '" . date('Y-m-d H:i:s', time() - 900) . "') OR started_at < '" . date('Y-m-d H:i:s', time() - 14400) . "')";
        $pdo->exec("UPDATE cc_jobs SET status = 'queued' WHERE $stale AND attempts < 3");
        $pdo->exec("UPDATE cc_jobs SET status = 'error', error = 'Interrompido três vezes.' WHERE $stale");
        // os vídeos do Review (ffmpeg) só pela linha de comandos: as páginas não podem correr programas
        $cli = PHP_SAPI === 'cli' ? '' : " AND kind NOT IN ('rv_proc','rv_wm')";
        $id = (int)$pdo->query("SELECT id FROM cc_jobs WHERE status = 'queued'$cli ORDER BY CASE WHEN kind = 'import' THEN 0 ELSE 1 END, id LIMIT 1")->fetchColumn();
        if (!$id) break;
        $up = $pdo->prepare("UPDATE cc_jobs SET status = 'running', started_at = ?, attempts = attempts + 1 WHERE id = ? AND status = 'queued'");
        $up->execute([cc_now(), $id]);
        if ($up->rowCount() !== 1) continue;   // outro pedido apanhou-o
        $job = $pdo->query('SELECT * FROM cc_jobs WHERE id = ' . $id)->fetch();
        try {
            cc_job_exec($pdo, $job);
            $pdo->prepare("UPDATE cc_jobs SET status = 'done', done_at = ?, error = '' WHERE id = ?")->execute([cc_now(), $id]);
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 250);
            $retry = $e instanceof CcAiException && in_array($e->kind, ['rate', 'overloaded', 'server', 'network', 'timeout'], true) && (int)$job['attempts'] < 3;
            $pdo->prepare('UPDATE cc_jobs SET status = ?, error = ?, done_at = ? WHERE id = ?')->execute([$retry ? 'queued' : 'error', $msg, cc_now(), $id]);
            if (!$retry && $job['asset_id']) {
                $pdo->prepare("UPDATE cc_assets SET ai_status = ?, ai_error = ? WHERE id = ?")
                    ->execute([$e instanceof CcAiException && in_array($e->kind, ['not_configured', 'budget'], true) ? 'off' : 'error', $msg, (int)$job['asset_id']]);
            }
            if ($e instanceof CcAiException && in_array($e->kind, ['not_configured', 'budget', 'auth', 'billing'], true)) break;   // não vale a pena insistir agora
        }
        $n++;
    }
    return $n;
}

function cc_job_exec(PDO $pdo, array $job): void {
    switch ($job['kind']) {
        case 'analyze':
            cc_ai_analyze_asset($pdo, (int)$job['asset_id']);
            break;
        case 'embed':
            cc_ai_embed_asset($pdo, (int)$job['asset_id']);
            break;
        case 'import':
            require_once __DIR__ . '/cc_import.php';
            cc_import_run($pdo, $job);
            break;
        case 'rv_proc':
        case 'rv_wm':
            // Review (§24): o ffmpeg corre só pela linha de comandos (cron)
            require_once __DIR__ . '/cc_review.php';
            cc_rv_job($pdo, $job);
            break;
        default:
            throw new RuntimeException('Trabalho desconhecido.');
    }
}

// ── quadros ─────────────────────────────────────────────────

function cc_board_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cc_boards WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function cc_board_create(PDO $pdo, array $d, ?int $by): int {
    $now = cc_now();
    $pdo->prepare('INSERT INTO cc_boards (deal_id, line_id, parent_id, kind, stage_key, title, brief, status, position, exec_owner_id, created_by, created_at, updated_at)
                   VALUES (?,?,?,?,?,?,?,\'active\',?,?,?,?,?)')
        ->execute([$d['deal_id'] ?? null, $d['line_id'] ?? null, $d['parent_id'] ?? null, $d['kind'] ?? 'free', $d['stage_key'] ?? '',
                   mb_substr(trim((string)($d['title'] ?? 'Quadro')), 0, 200) ?: 'Quadro', $d['brief'] ?? null, (int)($d['position'] ?? 0),
                   $d['exec_owner_id'] ?? null, $by, $now, $now]);
    return (int)$pdo->lastInsertId();
}

/** Marca o quadro como mudado (os outros separadores abertos recarregam). */
function cc_board_touch(PDO $pdo, int $id): int {
    $pdo->prepare('UPDATE cc_boards SET rev = rev + 1, updated_at = ? WHERE id = ?')->execute([cc_now(), $id]);
    return (int)$pdo->query('SELECT rev FROM cc_boards WHERE id = ' . $id)->fetchColumn();
}

function cc_board_children(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT * FROM cc_boards WHERE parent_id = ? ORDER BY position, id');
    $st->execute([$id]);
    return $st->fetchAll();
}

/** O caminho até à raiz (para as migalhas): [raiz, …, este]. */
function cc_board_path(PDO $pdo, array $b): array {
    $path = [$b];
    $seen = [(int)$b['id'] => true];
    while (!empty($b['parent_id']) && ($b = cc_board_get($pdo, (int)$b['parent_id'])) && empty($seen[(int)$b['id']])) {
        $seen[(int)$b['id']] = true;
        array_unshift($path, $b);
    }
    return $path;
}

/** O quadro de topo de um negócio (projeto) ou o pré-criativo. */
function cc_deal_board(PDO $pdo, int $dealId, string $kind): ?array {
    $st = $pdo->prepare('SELECT * FROM cc_boards WHERE deal_id = ? AND kind = ? AND parent_id IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$dealId, $kind]);
    return $st->fetch() ?: null;
}

function cc_board_public(PDO $pdo, array $b): array {
    $deal = null;
    if ($b['deal_id']) {
        $st = $pdo->prepare('SELECT d.id, d.code, d.name, d.phase, c.name AS client FROM deals d LEFT JOIN contacts c ON c.id = d.contact_id WHERE d.id = ?');
        try { $st->execute([(int)$b['deal_id']]); $deal = $st->fetch() ?: null; } catch (Throwable $e) { $deal = null; }
    }
    return [
        'id' => (int)$b['id'], 'kind' => $b['kind'], 'stage' => (string)$b['stage_key'], 'title' => (string)$b['title'],
        'brief' => (string)($b['brief'] ?? ''), 'status' => (string)$b['status'], 'rev' => (int)$b['rev'], 'parent' => $b['parent_id'] ? (int)$b['parent_id'] : null,
        'deal' => $deal ? ['id' => (int)$deal['id'], 'code' => (string)$deal['code'], 'name' => (string)$deal['name'], 'client' => (string)($deal['client'] ?? ''), 'phase' => (string)$deal['phase']] : null,
        'line' => $b['line_id'] ? (int)$b['line_id'] : null, 'ai' => (string)$b['ai_state'],
    ];
}

// ── cartões ─────────────────────────────────────────────────

function cc_cards(PDO $pdo, int $boardId): array {
    $st = $pdo->prepare('SELECT * FROM cc_cards WHERE board_id = ? AND deleted_at IS NULL ORDER BY z, id');
    $st->execute([$boardId]);
    return $st->fetchAll();
}

function cc_card_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cc_cards WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** O conteúdo de um cartão, limpo por tipo (nunca HTML: a interface escreve como texto). */
function cc_card_content(string $type, $raw): array {
    $c = is_array($raw) ? $raw : (json_decode((string)$raw, true) ?: []);
    $s = static fn($v, int $n) => mb_substr(trim((string)$v), 0, $n);
    switch ($type) {
        case 'note':
            return ['text' => $s($c['text'] ?? '', 20000), 'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($c['color'] ?? '')) ? $c['color'] : '', 'brief' => !empty($c['brief'])];
        case 'list':
            $items = [];
            foreach (array_slice((array)($c['items'] ?? []), 0, 200) as $it) {
                if (!is_array($it)) continue;
                $items[] = ['t' => $s($it['t'] ?? '', 500), 'd' => !empty($it['d'])];
            }
            return ['title' => $s($c['title'] ?? '', 200), 'items' => $items];
        case 'column':
            return ['title' => $s($c['title'] ?? '', 200), 'color' => preg_match('/^#[0-9a-f]{6}$/i', (string)($c['color'] ?? '')) ? $c['color'] : '', 'collapsed' => !empty($c['collapsed'])];
        case 'swatch':
            return ['hex' => preg_match('/^#[0-9a-f]{6}$/i', (string)($c['hex'] ?? '')) ? strtoupper($c['hex']) : '#FFC718', 'name' => $s($c['name'] ?? '', 80)];
        case 'drawing':
            $paths = [];
            foreach (array_slice((array)($c['paths'] ?? []), 0, 400) as $p) {
                if (!is_array($p) || !is_array($p['pts'] ?? null)) continue;
                $pts = array_map(fn($v) => round((float)$v, 1), array_slice($p['pts'], 0, 4000));
                $paths[] = ['c' => preg_match('/^#[0-9a-f]{6}$/i', (string)($p['c'] ?? '')) ? $p['c'] : '#111111', 'w' => max(1, min(40, (float)($p['w'] ?? 3))), 'pts' => $pts];
            }
            return ['paths' => $paths];
        case 'arrow':
            return ['from' => (int)($c['from'] ?? 0), 'to' => (int)($c['to'] ?? 0), 'label' => $s($c['label'] ?? '', 120),
                    'x2' => (float)($c['x2'] ?? 0), 'y2' => (float)($c['y2'] ?? 0)];
        case 'board':
            return ['title' => $s($c['title'] ?? '', 200)];
        default:   // image, video, link, file
            return ['caption' => $s($c['caption'] ?? '', 1000), 'state' => in_array($c['state'] ?? '', ['', 'keep', 'swap'], true) ? (string)($c['state'] ?? '') : '',
                    'angle' => $s($c['angle'] ?? '', 200), 'crop' => in_array($c['crop'] ?? '', ['', 'cover'], true) ? (string)($c['crop'] ?? '') : ''];
    }
}

function cc_card_public(array $c, ?array $asset = null, array $comments = []): array {
    $out = [
        'id' => (int)$c['id'], 'type' => $c['type'], 'x' => (float)$c['x'], 'y' => (float)$c['y'], 'w' => (float)$c['w'], 'h' => (float)$c['h'],
        'z' => (int)$c['z'], 'col' => $c['column_id'] ? (int)$c['column_id'] : null, 'ord' => (int)$c['ord'], 'lane' => (string)$c['lane'],
        'c' => cc_card_content((string)$c['type'], $c['content']), 'ref' => $c['ref_board_id'] ? (int)$c['ref_board_id'] : null,
        'asset' => $asset ? cc_asset_public($asset) : null, 'nc' => (int)($comments[(int)$c['id']] ?? 0),
    ];
    return $out;
}

/** Os cartões de um quadro, com as referências e o número de comentários, de uma só vez. */
function cc_board_cards_public(PDO $pdo, int $boardId): array {
    $cards = cc_cards($pdo, $boardId);
    $ids = array_values(array_unique(array_filter(array_map(fn($c) => (int)$c['asset_id'], $cards))));
    $assets = [];
    if ($ids) {
        foreach ($pdo->query('SELECT * FROM cc_assets WHERE id IN (' . implode(',', $ids) . ')')->fetchAll() as $a) $assets[(int)$a['id']] = $a;
    }
    $nc = [];
    $st = $pdo->prepare('SELECT card_id, COUNT(*) FROM cc_comments WHERE board_id = ? AND card_id IS NOT NULL AND deleted_at IS NULL GROUP BY card_id');
    $st->execute([$boardId]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$cid, $n]) $nc[(int)$cid] = (int)$n;
    $out = [];
    foreach ($cards as $c) {
        $a = $c['asset_id'] ? ($assets[(int)$c['asset_id']] ?? null) : null;
        if ($c['asset_id'] && (!$a || $a['deleted_at'])) continue;   // a referência foi apagada da biblioteca
        $out[] = cc_card_public($c, $a, $nc);
    }
    return $out;
}

/** Um cartão novo. $d: type, x, y, w, h, asset_id, ref_board_id, column_id, lane, content. */
function cc_card_add(PDO $pdo, int $boardId, array $d, ?int $by): int {
    $type = in_array($d['type'] ?? '', CC_CARD_TYPES, true) ? $d['type'] : 'note';
    $z = (int)$pdo->query('SELECT COALESCE(MAX(z), 0) + 1 FROM cc_cards WHERE board_id = ' . $boardId)->fetchColumn();
    $now = cc_now();
    $defW = ['column' => 300, 'note' => 240, 'list' => 260, 'swatch' => 140, 'board' => 240, 'drawing' => 320, 'arrow' => 0, 'link' => 280, 'file' => 240][$type] ?? 260;
    $pdo->prepare('INSERT INTO cc_cards (board_id, type, asset_id, ref_board_id, column_id, lane, x, y, w, h, z, ord, content, created_by, created_at, updated_by, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$boardId, $type, !empty($d['asset_id']) ? (int)$d['asset_id'] : null, !empty($d['ref_board_id']) ? (int)$d['ref_board_id'] : null,
                   !empty($d['column_id']) ? (int)$d['column_id'] : null, in_array($d['lane'] ?? '', ['A', 'B'], true) ? $d['lane'] : '',
                   round((float)($d['x'] ?? 0), 1), round((float)($d['y'] ?? 0), 1), max(40, min(4000, (float)($d['w'] ?? $defW))),
                   max(0, min(6000, (float)($d['h'] ?? 0))), $z, (int)($d['ord'] ?? 0),
                   json_encode(cc_card_content($type, $d['content'] ?? []), JSON_UNESCAPED_UNICODE), $by, $now, $by, $now]);
    return (int)$pdo->lastInsertId();
}

/** O próximo lugar livre numa grelha à direita/abaixo do que já existe (para cartões criados sem posição). */
function cc_board_free_spot(PDO $pdo, int $boardId, string $lane = ''): array {
    $st = $pdo->prepare("SELECT x, y, w, h, type FROM cc_cards WHERE board_id = ? AND deleted_at IS NULL AND column_id IS NULL AND type <> 'arrow'" . ($lane !== '' ? ' AND lane = ?' : ''));
    $st->execute($lane !== '' ? [$boardId, $lane] : [$boardId]);
    $rows = $st->fetchAll();
    if (!$rows) return [40.0, 120.0];
    $maxY = 0.0;
    foreach ($rows as $r) $maxY = max($maxY, (float)$r['y'] + max(160.0, (float)$r['h']));
    return [40.0, round($maxY + 40, 1)];
}

// ── comentários e @menções ──────────────────────────────────

/** As @menções de um texto → ids de utilizadores ativos (por primeiro nome ou nome completo, sem acentos). */
function cc_mentions(PDO $pdo, string $body): array {
    if (!preg_match_all('/@([\p{L}][\p{L}.\-]{1,40}(?:\s[\p{L}][\p{L}.\-]{1,40})?)/u', $body, $m)) return [];
    $users = $pdo->query("SELECT id, name, role FROM users WHERE is_active = 1 AND deactivated_at IS NULL AND role <> 'superadmin'")->fetchAll();
    $ids = [];
    foreach ($m[1] as $tag) {
        $w = array_map(fn($x) => rtrim($x, '.-'), explode(' ', cc_fold($tag)));
        $two = count($w) > 1 ? $w[0] . ' ' . $w[1] : '';
        $hit = [];
        // «@Nome Apelido» quando há dois com o mesmo nome; senão «@Nome» (a palavra a seguir é texto)
        foreach ($users as $u) if ($two !== '' && str_starts_with(cc_fold((string)$u['name']), $two)) $hit[] = (int)$u['id'];
        if (!$hit) foreach ($users as $u) if (explode(' ', cc_fold((string)$u['name']))[0] === $w[0]) $hit[] = (int)$u['id'];
        foreach ($hit as $id) $ids[$id] = true;
    }
    return array_keys($ids);
}

function cc_comments(PDO $pdo, int $boardId, ?int $cardId): array {
    $st = $pdo->prepare('SELECT c.*, u.name AS user_name, u.color AS user_color FROM cc_comments c LEFT JOIN users u ON u.id = c.user_id
                          WHERE c.board_id = ? AND ' . ($cardId ? 'c.card_id = ?' : 'c.card_id IS NULL') . ' AND c.deleted_at IS NULL ORDER BY c.id');
    $st->execute($cardId ? [$boardId, $cardId] : [$boardId]);
    return array_map(fn($c) => [
        'id' => (int)$c['id'], 'body' => (string)$c['body'], 'who' => $c['author_kind'] === 'client' ? ($c['client_name'] ?: 'Cliente') : (string)$c['user_name'],
        'client' => $c['author_kind'] === 'client', 'color' => (string)($c['user_color'] ?? ''), 'uid' => $c['user_id'] ? (int)$c['user_id'] : null,
        'when' => date('d/m H:i', strtotime((string)$c['created_at'])), 't' => $c['t'] !== null ? (float)$c['t'] : null,
        'resolved' => $c['resolved_at'] !== null,
    ], $st->fetchAll());
}

/** Um comentário (com @menções → notificação a cada pessoa). Devolve o id. */
function cc_comment_add(PDO $pdo, array $board, ?array $card, string $body, array $user): int {
    $body = mb_substr(trim($body), 0, 4000);
    if ($body === '') throw new RuntimeException('Escreva o comentário.');
    $ment = cc_mentions($pdo, $body);
    $pdo->prepare('INSERT INTO cc_comments (board_id, card_id, user_id, author_kind, body, mentions, created_at) VALUES (?,?,?,\'team\',?,?,?)')
        ->execute([(int)$board['id'], $card ? (int)$card['id'] : null, (int)$user['id'], $body, $ment ? ',' . implode(',', $ment) . ',' : '', cc_now()]);
    return (int)$pdo->lastInsertId();   // as @menções aparecem no sino de cada pessoa (inc/alerts.php)
}

// ── pesquisa na biblioteca ──────────────────────────────────

/**
 * Pesquisa: $f = q (texto), color (#hex), facets (style/technique/camera/environment/light),
 * origin (external|topper), inbox (1), board (só as de um quadro), limit, offset.
 * Devolve [lista de referências (públicas, com «match» quando é um frame), total].
 */
function cc_library_search(PDO $pdo, array $f, int $limit = 60, int $offset = 0): array {
    $where = ['a.deleted_at IS NULL'];
    $args = [];
    if (!empty($f['inbox'])) $where[] = 'a.inbox = 1';
    if (in_array($f['origin'] ?? '', ['external', 'topper'], true)) { $where[] = 'a.origin = ?'; $args[] = $f['origin']; }
    if (in_array($f['kind'] ?? '', ['image', 'video', 'link', 'file'], true)) { $where[] = 'a.kind = ?'; $args[] = $f['kind']; }
    foreach (array_keys(CC_VOCAB) as $k) {
        if (($f[$k] ?? '') !== '') { $where[] = "a.$k = ?"; $args[] = mb_substr((string)$f[$k], 0, 60); }
    }
    if (!empty($f['deal'])) { $where[] = 'a.deal_id = ?'; $args[] = (int)$f['deal']; }
    $q = trim((string)($f['q'] ?? ''));
    $words = $q !== '' ? array_slice(array_filter(preg_split('/\s+/u', cc_fold($q))), 0, 6) : [];
    $textSql = '';
    if ($words) {
        $w = [];
        foreach ($words as $word) { $w[] = 'a.search_text LIKE ?'; $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%'; }
        $textSql = implode(' AND ', $w);
        // um frame que bata no texto também traz o vídeo
        $fw = [];
        foreach ($words as $word) { $fw[] = 'fr.analysis LIKE ?'; $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%'; }
        $where[] = '((' . $textSql . ') OR EXISTS (SELECT 1 FROM cc_frames fr WHERE fr.asset_id = a.id AND ' . implode(' AND ', $fw) . '))';
    }
    $sql = 'SELECT a.* FROM cc_assets a WHERE ' . implode(' AND ', $where) . ' ORDER BY a.id DESC';
    $color = !empty($f['color']) ? cc_hex_lab((string)$f['color']) : null;
    if (!$color) {
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM cc_assets a WHERE ' . implode(' AND ', $where));
        $cnt->execute($args);
        $total = (int)$cnt->fetchColumn();
        $st = $pdo->prepare($sql . ' LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset));
        $st->execute($args);
        return [array_map(fn($a) => cc_asset_public($a), $st->fetchAll()), $total];
    }
    // por cor: ΔE2000 contra as paletas (da referência e dos frames), as mais próximas primeiro
    $st = $pdo->prepare(str_replace('SELECT a.*', 'SELECT a.id, a.palette', $sql) . ' LIMIT 20000');
    $st->execute($args);
    $scores = []; $des = [];
    foreach ($st->fetchAll() as $r) {
        $d = cc_palette_distance($r['palette'], $color);
        if ($d !== null && $d[1] < 16) { $scores[(int)$r['id']] = $d[0]; $des[(int)$r['id']] = $d[1]; }
    }
    $fr = $pdo->query('SELECT asset_id, palette, t FROM cc_frames');
    $hit = [];
    foreach ($fr->fetchAll() as $r) {
        $d = cc_palette_distance($r['palette'], $color);
        if ($d !== null && $d[1] < 16 && (!isset($scores[(int)$r['asset_id']]) || $d[0] < $scores[(int)$r['asset_id']])) {
            $scores[(int)$r['asset_id']] = $d[0];
            $des[(int)$r['asset_id']] = $d[1];
            $hit[(int)$r['asset_id']] = (float)$r['t'];
        }
    }
    if ($scores) {
        // só as que passam nos outros filtros
        $ok = $pdo->prepare('SELECT a.id FROM cc_assets a WHERE ' . implode(' AND ', $where) . ' AND a.id IN (' . implode(',', array_keys($scores)) . ')');
        $ok->execute($args);
        $keep = array_flip(array_map('intval', $ok->fetchAll(PDO::FETCH_COLUMN)));
        $scores = array_intersect_key($scores, $keep);
    }
    asort($scores);
    $total = count($scores);
    $ids = array_slice(array_keys($scores), $offset, $limit);
    if (!$ids) return [[], $total];
    $rows = [];
    foreach ($pdo->query('SELECT * FROM cc_assets WHERE id IN (' . implode(',', $ids) . ')')->fetchAll() as $a) $rows[(int)$a['id']] = $a;
    $out = [];
    foreach ($ids as $id) if (isset($rows[$id])) {
        $p = cc_asset_public($rows[$id]);
        $p['de'] = round($des[$id], 1);
        if (isset($hit[$id])) $p['match_t'] = $hit[$id];
        $out[] = $p;
    }
    return [$out, $total];
}

/** As facetas com contagens (para os filtros da biblioteca). */
function cc_library_facets(PDO $pdo): array {
    $out = [];
    foreach (array_keys(CC_VOCAB) as $k) {
        $rows = $pdo->query("SELECT $k AS v, COUNT(*) AS n FROM cc_assets WHERE deleted_at IS NULL AND $k <> '' GROUP BY $k ORDER BY n DESC, v LIMIT 40")->fetchAll();
        $out[$k] = array_map(fn($r) => ['v' => (string)$r['v'], 'n' => (int)$r['n']], $rows);
    }
    return $out;
}

// ── vetores (embeddings) ────────────────────────────────────

function cc_vec_pack(array $v): string {
    $n = 0.0;
    foreach ($v as $x) $n += $x * $x;
    $n = $n > 0 ? sqrt($n) : 1.0;
    return pack('g*', ...array_map(fn($x) => (float)$x / $n, $v));   // normalizado: o cosseno é o produto interno
}

function cc_vec_unpack(?string $b): ?array {
    if ($b === null || $b === '' || strlen($b) % 4 !== 0) return null;
    return array_values(unpack('g*', $b));
}

function cc_vec_dot(array $a, array $b): float {
    $s = 0.0;
    $n = min(count($a), count($b));
    for ($i = 0; $i < $n; $i++) $s += $a[$i] * $b[$i];
    return $s;
}

/**
 * As referências mais parecidas com um vetor (cosseno). Sem embeddings, cai para etiquetas,
 * facetas e paleta. $exclude: ids a deixar de fora.
 */
function cc_nearest(PDO $pdo, ?array $vec, int $n = 24, array $exclude = [], ?array $like = null): array {
    $ex = array_flip(array_map('intval', $exclude));
    $scores = [];
    if ($vec) {
        $model = (string)$pdo->query("SELECT embed_model FROM cc_assets WHERE embedding IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
        $st = $pdo->prepare('SELECT id, embedding FROM cc_assets WHERE deleted_at IS NULL AND embedding IS NOT NULL AND embed_model = ?');
        $st->execute([$model]);
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            if (isset($ex[(int)$r[0]])) continue;
            $v = cc_vec_unpack($r[1]);
            if ($v && count($v) === count($vec)) $scores[(int)$r[0]] = cc_vec_dot($vec, $v);
        }
        arsort($scores);
        return array_slice($scores, 0, $n, true);
    }
    if (!$like) return [];
    // sem vetores: semelhança por etiquetas/facetas (Jaccard) + paleta
    $lt = array_flip(array_filter(array_map('trim', explode(',', (string)$like['tags']))));
    $lf = array_filter([$like['style'], $like['technique'], $like['camera'], $like['environment'], $like['light']]);
    $lp = cc_palette_hex($like['palette'], 3);
    $labs = array_filter(array_map(fn($c) => cc_hex_lab($c['hex']), $lp));
    $st = $pdo->query('SELECT id, tags, style, technique, camera, environment, light, palette FROM cc_assets WHERE deleted_at IS NULL');
    while ($r = $st->fetch()) {
        if (isset($ex[(int)$r['id']])) continue;
        $t = array_flip(array_filter(array_map('trim', explode(',', (string)$r['tags']))));
        $inter = count(array_intersect_key($lt, $t));
        $union = count($lt + $t) ?: 1;
        $fac = count(array_intersect($lf, array_filter([$r['style'], $r['technique'], $r['camera'], $r['environment'], $r['light']])));
        $col = 0.0;
        foreach ($labs as $lab) { $d = cc_palette_distance($r['palette'], $lab); if ($d !== null) $col += max(0, 20 - $d[1]) / 20; }
        $s = $inter / $union * 2 + $fac * 0.6 + $col * 0.5;
        if ($s > 0.3) $scores[(int)$r['id']] = $s;
    }
    arsort($scores);
    return array_slice($scores, 0, $n, true);
}

/** Lê referências por id, pela ordem dada. */
function cc_assets_by_ids(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) return [];
    $rows = [];
    foreach ($pdo->query('SELECT * FROM cc_assets WHERE deleted_at IS NULL AND id IN (' . implode(',', $ids) . ')')->fetchAll() as $a) $rows[(int)$a['id']] = $a;
    $out = [];
    foreach ($ids as $id) if (isset($rows[$id])) $out[] = $rows[$id];
    return $out;
}
