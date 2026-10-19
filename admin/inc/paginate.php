<?php
/**
 * Paginação simples para as tabelas das listas.
 * Cada tabela usa o seu parâmetro (?pt=, ?pc=, ?pl=…), para mudar de página
 * numa não mexer nas outras; os restantes parâmetros do endereço mantêm-se.
 */
const PAGE_SIZE = 8;

/** A página pedida em $param, limitada ao intervalo válido. */
function page_get(string $param, int $count, int $per = PAGE_SIZE): array {
    $pages = max(1, (int)ceil($count / max(1, $per)));
    $page  = (int)($_GET[$param] ?? 1);
    $page  = min(max(1, $page), $pages);
    return [$page, $pages];
}

/**
 * Corta a lista na página pedida.
 * Devolve ['rows' => …, 'page' => n, 'pages' => n, 'total' => n, 'param' => …].
 */
function paginate(array $rows, string $param, int $per = PAGE_SIZE): array {
    $rows = array_values($rows);
    [$page, $pages] = page_get($param, count($rows), $per);
    return [
        'rows'  => array_slice($rows, ($page - 1) * $per, $per),
        'page'  => $page,
        'pages' => $pages,
        'total' => count($rows),
        'param' => $param,
    ];
}

/** O endereço atual com $param = $page (os outros parâmetros ficam). */
function page_url(string $param, int $page, array $drop = [], string $anchor = ''): string {
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $q = $_GET;
    foreach (array_merge($drop, ['fresh']) as $k) unset($q[$k]);   // ?fresh=1 não se propaga
    if ($page > 1) $q[$param] = $page; else unset($q[$param]);
    $qs = http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    return $path . ($qs !== '' ? '?' . $qs : '') . ($anchor !== '' ? '#' . $anchor : '');
}

/**
 * «← Anterior · Página X de Y · Seguinte →». Nada quando só há uma página.
 * $drop: parâmetros que não devem seguir (confirmações pendentes, edição…).
 */
function page_nav(array $pg, array $drop = [], string $anchor = ''): string {
    if ($pg['pages'] <= 1) return '';
    $p = $pg['page']; $n = $pg['pages']; $param = $pg['param'];
    $prev = $p > 1
        ? '<a class="pg-btn" rel="prev" href="' . e(page_url($param, $p - 1, $drop, $anchor)) . '">← Anterior</a>'
        : '<span class="pg-btn off" aria-disabled="true">← Anterior</span>';
    $next = $p < $n
        ? '<a class="pg-btn" rel="next" href="' . e(page_url($param, $p + 1, $drop, $anchor)) . '">Seguinte →</a>'
        : '<span class="pg-btn off" aria-disabled="true">Seguinte →</span>';
    return '<nav class="pager" aria-label="Paginação">' . $prev
         . ' <span class="pg-sep">·</span> <span class="pg-at">Página ' . $p . ' de ' . $n . '</span>'
         . ' <span class="pg-sep">·</span> ' . $next . '</nav>';
}

/** A folha de estilo da paginação (o layout é partilhado, por isso vai à parte). */
function paginate_css(): string {
    $f = __DIR__ . '/../assets/paginate.css';
    $v = is_file($f) ? (string)filemtime($f) : '1';
    return '<link rel="stylesheet" href="/admin/assets/paginate.css?v=' . $v . '">';
}
