<?php
/**
 * topper. admin — o separador «Drive», agora no Cloudflare R2.
 *
 * Ajudas de apresentação e a estrutura de pastas. As operações S3 estão
 * em r2.php; os ficheiros vão do browser diretamente para o R2.
 */

declare(strict_types=1);

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/r2.php';

/** As pastas de topo (aparecem sempre na raiz, mesmo sem marcador). */
const DRIVE_ROOTS = ['Empresa/', 'Projetos/', 'Showreel e Portfolio/'];
const DRIVE_PROJECT_SUBS = [];   // por agora sem subpastas: os ficheiros ficam soltos na pasta do projeto

/** drive.php?p=<prefixo> (com extras já codificados, «&a=b»). */
function drive_page(string $p, string $extra = ''): string {
    $q = $p !== '' ? 'p=' . rawurlencode($p) : '';
    $extra = ltrim($extra, '&');
    if ($extra !== '') $q .= ($q !== '' ? '&' : '') . $extra;
    return '/admin/drive.php' . ($q !== '' ? '?' . $q : '');
}

/** O prefixo da pasta de um trabalho: Projetos/<ano>/<nome>/. */
/** Nomes das pastas de projeto já usados pela equipa (trabalho na folha → nome da pasta). */
const DRIVE_PROJECT_ALIASES = [
    'Wuant The One'   => 'THE_ONE',
    'Delta Y (Video)' => 'DELTAY',
    'Casino Solverde' => 'SOLVERDE',
    'LING'            => 'WIZZY_TEYKAN_DISTANTE',
    'Richie Campbell' => 'RICHIE_LOYAL',
];

/** «Casino Solverde» → «CASINO_SOLVERDE». */
function drive_slug(string $s): string {
    $t = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $t = strtoupper((string)($t === false ? $s : $t));
    return trim(preg_replace('/[^A-Z0-9]+/', '_', $t), '_') ?: 'PROJETO';
}

/** «03_SOLVERDE_PROJECT» → «SOLVERDE». */
function drive_project_core(string $folder): string {
    $f = rtrim($folder, '/');
    $f = preg_replace('/^\d+_/', '', $f);
    return preg_replace('/_(PROJECT|PROJETO)$/', '', $f);
}

/**
 * Pasta do trabalho: «Projetos/NN_NOME_PROJECT/», numerada pela ordem de criação.
 * Se já existir uma pasta para este trabalho (pelo nome ou pelo alias), usa essa.
 */
function drive_project_prefix(string $name, int $year): string {
    $core = DRIVE_PROJECT_ALIASES[$name] ?? drive_slug($name);
    $max = 0;
    foreach (r2_list('Projetos/')['folders'] ?? [] as $f) {
        $leaf = substr(rtrim($f, '/'), strlen('Projetos/'));
        if (drive_project_core($leaf) === $core) return 'Projetos/' . $leaf . '/';
        if (preg_match('/^(\d+)_/', $leaf, $m)) $max = max($max, (int)$m[1]);
    }
    return 'Projetos/' . sprintf('%02d', $max + 1) . '_' . $core . '_PROJECT/';
}

/** Cria (se faltar) a pasta do trabalho e as subpastas. Devolve o prefixo. */
function drive_project(string $name, int $year): string {
    $p = drive_project_prefix($name, $year);
    r2_mkdir_p($p);
    foreach (DRIVE_PROJECT_SUBS as $s) {
        if (r2_head($p . $s . '/') === null) r2_mkdir($p . $s . '/');
    }
    return $p;
}

/** «1,2 MB». */
function drive_size(int $b): string {
    if ($b <= 0) return $b === 0 ? '0 B' : '—';
    $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $n = (float)$b;
    while ($n >= 1024 && $i < 4) { $n /= 1024; $i++; }
    return ($i === 0 ? (string)$b : number_format($n, $n < 10 ? 1 : 0, ',', ' ')) . ' ' . $u[$i];
}

/** [etiqueta, ícone, tipo de pré-visualização (video|image|pdf|audio|'')] pelo nome. */
function drive_kind(string $name, bool $folder): array {
    if ($folder) return ['Pasta', '▣', ''];
    $mime = r2_mime($name);
    if ($mime === 'application/pdf') return ['PDF', '▤', 'pdf'];
    if ($mime === 'application/zip') return ['ZIP', '◫', ''];
    if (in_array($mime, ['video/mp4', 'video/webm', 'video/quicktime'], true)) return ['Vídeo', '▶', 'video'];
    if (strpos($mime, 'video/') === 0) return ['Vídeo', '▶', ''];
    if ($mime === 'image/vnd.adobe.photoshop') return ['Imagem', '◩', ''];
    if (strpos($mime, 'image/') === 0) return ['Imagem', '◩', 'image'];
    if (strpos($mime, 'audio/') === 0) return ['Áudio', '♪', 'audio'];
    if (strpos($mime, 'text/') === 0 || strpos($mime, 'word') !== false) return ['Documento', '≡', ''];
    if (strpos($mime, 'sheet') !== false) return ['Folha', '▦', ''];
    if (strpos($mime, 'presentation') !== false) return ['Slides', '▭', ''];
    return ['Ficheiro', '□', ''];
}

/** A política CORS a colar no bucket. */
function drive_cors_json(): string {
    return json_encode([[
        'AllowedOrigins' => ['https://topperdigital.xyz'],
        'AllowedMethods' => ['GET', 'PUT', 'POST', 'DELETE', 'HEAD'],
        'AllowedHeaders' => ['*'],
        'ExposeHeaders'  => ['ETag'],
        'MaxAgeSeconds'  => 3600,
    ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
