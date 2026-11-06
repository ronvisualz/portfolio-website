<?php
/**
 * Pipeline v2 — os includes. A Hostinger recebe os ficheiros um a um: um ficheiro que chame uma função pipe2_* sem
 * incluir ele próprio o ficheiro que a define rebenta (erro fatal) se chegar antes de quem a incluía por ele, ou quando
 * o caminho que a incluía por acaso deixar de passar por lá.
 *
 *   ::pipe2_base   cada ficheiro de admin/ e admin/inc/ que chama funções pipe2_* tem
 *                  require_once __DIR__ . '/pipe2.php' (ou '/inc/pipe2.php');
 *   ::definer      para cada chamada pipe2_xxx( (pelo tokenizer: fora de comentários e de strings, sem contar as
 *                  definições nem os métodos), o ficheiro que define function pipe2_xxx (procurado em
 *                  admin/inc/pipe2*.php e admin/inc/migrate_pipeline2*.php) é o próprio ou tem
 *                  require_once __DIR__ . '/<definidor>' (ou '/inc/<definidor>') no ficheiro que chama. Uma chamada
 *                  guardada por function_exists('pipe2_xxx') no mesmo ficheiro não precisa do require (ex.: o «Anular»
 *                  só junta os movimentos do motor se o motor já estiver carregado nesse pedido);
 *   ::defined      cada função chamada existe num desses ficheiros, e só num;
 *   ::detects      controlo: numa árvore de ensaio com um ficheiro que chama sem o require_once, o teste falha com
 *                  «<ficheiro> chama <função> sem require_once de <definidor>» (prova que não é cego).
 *
 * Corre sem a bancada: php tests/pipe2_includes_test.php
 */

$ok = 0;
$fails = [];

/** Os tokens de um ficheiro PHP sem espaços nem comentários. */
function p2i_tokens(string $src): array {
    return array_values(array_filter(token_get_all($src), fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
}

/** As funções pipe2_* definidas num ficheiro (function pipe2_xxx). */
function p2i_defs(string $src): array {
    $tk = p2i_tokens($src);
    $out = [];
    foreach ($tk as $i => $t) {
        if (!is_array($t) || $t[0] !== T_FUNCTION) continue;
        $n = $tk[$i + 1] ?? null;
        // function &pipe2_x(): o & (devolve por referência) vem entre o function e o nome
        if ($n === '&' || (is_array($n) && in_array($n[0], [T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true))) $n = $tk[$i + 2] ?? null;
        if (is_array($n) && $n[0] === T_STRING && str_starts_with(strtolower($n[1]), 'pipe2_')) $out[] = strtolower($n[1]);
    }
    return array_values(array_unique($out));
}

/** As chamadas pipe2_xxx( de um ficheiro (sem definições nem métodos ->x( / ::x(). */
function p2i_calls(string $src): array {
    $tk = p2i_tokens($src);
    $skipBefore = [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR, T_NEW];
    $out = [];
    foreach ($tk as $i => $t) {
        if (!is_array($t) || $t[0] !== T_STRING || !str_starts_with(strtolower($t[1]), 'pipe2_')) continue;
        if (($tk[$i + 1] ?? null) !== '(') continue;
        $prev = $tk[$i - 1] ?? null;
        if (is_array($prev) && in_array($prev[0], $skipBefore, true)) continue;
        $out[] = strtolower($t[1]);
    }
    return array_values(array_unique($out));
}

/** O ficheiro tem require_once __DIR__ . '/<f>' (ou '/inc/<f>')? */
function p2i_requires(string $src, string $f): bool {
    return (bool)preg_match("~require_once\\s*\\(?\\s*__DIR__\\s*\\.\\s*'/(inc/)?" . preg_quote($f, '~') . "'~", $src);
}

/**
 * As falhas de uma árvore com admin/ (ou []), e quantos ficheiros que chamam pipe2_* estão em ordem.
 * @return array{0: string[], 1: int}
 */
function p2i_check(string $root): array {
    $admin = $root . '/admin';
    $files = array_merge(glob($admin . '/*.php') ?: [], glob($admin . '/inc/*.php') ?: []);
    $definers = array_merge(glob($admin . '/inc/pipe2*.php') ?: [], glob($admin . '/inc/migrate_pipeline2*.php') ?: []);
    $where = [];   // função => [ficheiros que a definem]
    foreach ($definers as $f) {
        foreach (p2i_defs((string)file_get_contents($f)) as $fn) $where[$fn][] = basename($f);
    }
    $fails = [];
    foreach ($where as $fn => $in) {
        if (count($in) > 1) $fails[] = "$fn está definida em mais de um ficheiro: " . implode(', ', $in);
    }
    $ok = 0;
    foreach ($files as $f) {
        $rel = substr($f, strlen($root) + 1);
        $src = (string)file_get_contents($f);
        $calls = p2i_calls($src);
        if (!$calls) continue;
        $before = count($fails);
        $self = basename($f);
        // ::pipe2_base (o pipe2.php não se inclui a si próprio)
        if ($self !== 'pipe2.php' && !p2i_requires($src, 'pipe2.php')) $fails[] = "$rel chama pipe2_* sem require_once do inc/pipe2.php";
        foreach ($calls as $fn) {
            $defs = $where[$fn] ?? [];
            if (!$defs) { $fails[] = "$rel chama $fn, que não está definida em nenhum inc/pipe2*.php nem inc/migrate_pipeline2*.php"; continue; }
            $def = $defs[0];
            if ($def === $self && str_starts_with($rel, 'admin/inc/')) continue;
            if (preg_match("~function_exists\\(\\s*'" . preg_quote($fn, '~') . "'\\s*\\)~i", $src)) continue;
            if (!p2i_requires($src, $def)) $fails[] = "$rel chama $fn sem require_once de $def";
        }
        if (count($fails) === $before) $ok++;
    }
    return [$fails, $ok];
}

// ── a árvore de agora (::pipe2_base, ::definer, ::defined) ──

[$fails, $ok] = p2i_check(dirname(__DIR__));
if ($ok === 0) $fails[] = 'nenhum ficheiro encontrado (o teste não está a ver o código)';

// ── ::detects (controlo) ────────────────────────────────────

$tmp = rtrim(sys_get_temp_dir(), '/') . '/p2i_' . getmypid();
@mkdir($tmp . '/admin/inc', 0700, true);
file_put_contents($tmp . '/admin/inc/pipe2.php', "<?php\nfunction pipe2_base_x(): int { return 1; }\n");
file_put_contents($tmp . '/admin/inc/pipe2_mod.php', "<?php\nrequire_once __DIR__ . '/pipe2.php';\nfunction pipe2_mod_x(): int { return pipe2_base_x(); }\n"
    . "function &pipe2_ref_x(): array { static \$a = []; return \$a; }\nfunction pipe2_usa_ref_x(): array { return pipe2_ref_x(); }\n");
// chama a do módulo sem o incluir (só pelo pipe2.php) — tem de falhar
file_put_contents($tmp . '/admin/inc/sem.php', "<?php\nrequire_once __DIR__ . '/pipe2.php';\n// pipe2_mod_x( num comentário não conta\nfunction f() { return pipe2_mod_x(); }\n");
// a mesma chamada com o require_once — passa; e um método ->pipe2_mod_x() não conta como chamada
file_put_contents($tmp . '/admin/inc/com.php', "<?php\nrequire_once __DIR__ . '/pipe2.php';\nfunction g() { require_once __DIR__ . '/pipe2_mod.php'; return pipe2_mod_x(); }\n");
file_put_contents($tmp . '/admin/pagina.php', "<?php\nrequire_once __DIR__ . '/inc/pipe2.php';\n\$o->pipe2_outra_x();\nif (function_exists('pipe2_mod_x')) pipe2_mod_x();\necho 'pipe2_mod_x(';\n");
[$tf] = p2i_check($tmp);
foreach (array_reverse(glob($tmp . '/admin/inc/*.php') ?: []) as $f) unlink($f);
foreach (glob($tmp . '/admin/*.php') ?: [] as $f) unlink($f);
@rmdir($tmp . '/admin/inc'); @rmdir($tmp . '/admin'); @rmdir($tmp);
$want = ['admin/inc/sem.php chama pipe2_mod_x sem require_once de pipe2_mod.php'];
if ($tf !== $want) $fails[] = '::detects o controlo devia dar exatamente ' . json_encode($want, JSON_UNESCAPED_UNICODE) . ' e deu ' . json_encode($tf, JSON_UNESCAPED_UNICODE);

foreach ($fails as $f) echo 'FALHA: ' . $f . "\n";
echo $fails ? 'FALHOU ' . count($fails) . " (ficheiros em ordem: $ok)\n" : "OK $ok\n";
exit($fails ? 1 : 0);
