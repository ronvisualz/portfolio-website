<?php
// §33.0 / §33.0.1: a aba chama-se «Toppernote» e os quadros «Topperboards» em toda a interface. Só se olha para o que
// aparece a quem usa (strings e HTML), não para comentários nem nomes internos (cc_*). criativo.php redireciona.
require __DIR__ . '/lib.php';

$A = dirname(__DIR__) . '/admin';
/** As strings e o HTML de um ficheiro (sem comentários). */
function ui_text(string $path): string {
    $src = (string)file_get_contents($path);
    if (str_ends_with($path, '.php')) {
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) $out .= $t[1] . "\n";
        }
        return $out;
    }
    $src = preg_replace('~/\*.*?\*/~s', '', $src);
    return (string)preg_replace('~(^|[^:\'"\\\\])//[^\n]*~m', '$1', $src);
}

// 1. «Centro Criativo» não aparece em lado nenhum da interface (só fica como «ex-Centro Criativo» na especificação)
$all = array_merge(glob("$A/*.php"), glob("$A/inc/*.php"), glob("$A/assets/*.js"), glob("$A/assets/drive/*.js"), glob(dirname(__DIR__) . '/hub/*.php') ?: []);
$hits = [];
foreach ($all as $f) if (preg_match_all('/Centro Criativo/u', ui_text($f), $m)) $hits[] = basename($f) . ' (' . count($m[0]) . ')';
t_true(!$hits, '«Centro Criativo» ainda aparece em: ' . implode(', ', $hits));

// 2. nos ficheiros do Toppernote, «quadro(s)» / «sub-quadro» passaram a Topperboard(s)
$tn = array_merge(["$A/toppernote.php", "$A/cc_api.php", "$A/cc_file.php", "$A/portal.php", "$A/assets/cc.js", "$A/assets/cc_rt.js", "$A/assets/cc_audio.js", "$A/assets/portal.js"], glob("$A/inc/cc*.php"));
$q = [];
foreach ($tn as $f) {
    if (!is_file($f)) { $q[] = basename($f) . ' (não existe)'; continue; }
    if (preg_match_all('/(?<![\p{L}-])(?:[Ss]ub-)?[Qq]uadros?(?![\p{L}])/u', ui_text($f), $m)) $q[] = basename($f) . ' (' . count($m[0]) . ': ' . implode(', ', array_slice(array_unique($m[0]), 0, 4)) . ')';
}
t_true(!$q, '«quadro» ainda aparece em: ' . implode('; ', $q));

// 3. o endereço antigo leva ao novo, com a mesma pergunta
$r = tests_http('GET', '/admin/criativo.php?b=5&card=9', [], 1);
t_eq($r['code'], 301, 'criativo.php redireciona (301)');
t_true(str_ends_with((string)($r['headers']['location'] ?? ''), '/admin/toppernote.php?b=5&card=9'), 'para toppernote.php com o mesmo Topperboard e cartão (' . ($r['headers']['location'] ?? '') . ')');
$p = tests_http('GET', '/admin/toppernote.php', [], 1);
t_eq($p['code'], 200, 'toppernote.php abre');
t_true((bool)preg_match('~<title>[^<]*Toppernote~u', $p['body']), 'o título da página diz «Toppernote»');
t_true(str_contains($p['body'], 'Topperboards'), 'o separador chama-se «Topperboards»');
// 4. a pesquisa (⌘K) encontra a aba pelos dois nomes
foreach (['toppernote', 'topperboard'] as $w) {
    $j = tests_http('GET', '/admin/ui_api.php?a=search&q=' . $w, [], 1)['json'] ?? [];
    t_true(str_contains(json_encode($j), 'toppernote.php'), "a pesquisa por «{$w}» leva ao Toppernote");
}
t_done();
