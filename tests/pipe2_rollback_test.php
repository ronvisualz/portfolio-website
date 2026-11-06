<?php
/**
 * Pipeline v2, fase 0 — reversão do código depois do passo 43 («a migração aplicada e revertida numa cópia da base»).
 *
 * Em produção a migração não se desfaz (nada se apaga): reverte-se o código. Depois do passo 43, a reversão segura
 * (10.33 e plano da fase 0) repõe o código de antes da fase 0 (428480f) MENOS 8 ficheiros, que ficam na versão da
 * fase 0: inc/pipe2.php e os leitores e ecrãs que filtram (inc/deals.php, inc/pipeline.php, inc/pipeline_flow.php,
 * inc/xsell.php, funis.php, negocios.php, relatorios.php). O inc/migrate.php antigo (42) não volta a migrar nem
 * desfaz nada, a base fica na 43, e os leitores novos continuam a esconder as sub-etapas e os funis só da v2.
 *
 *   ::documented            a 10.33 e o plano da fase 0 dizem como se reverte e nomeiam os 8 ficheiros que ficam;
 *   ::code_rollback         base privada do dump da 42, migrada (43); o código de 428480f com os 8 da fase 0 por cima
 *                           não a migra nem a desfaz (--migrate: 43 → 43) e a fotografia PHP da v1 com ele (--php)
 *                           dá diff [] com a linha de base;
 *   ::full_rollback_breaks  controlo: o código de 428480f inteiro contra a mesma base mostra as sub-etapas e os funis
 *                           só da v2 (diff ≠ []) — é por isso que os 8 ficam, e prova que o diff [] não é cego;
 *   ::rollback_injected_rows as 4 linhas do pipe2_v1_unchanged_test::injected_rows_hidden (etapas retiradas, regras
 *                           numa sub-etapa e numa etapa retirada): com o código revertido continuam escondidas;
 *   ::rollback_guards       com o código revertido, mover o negócio, mudar de funil e mover a frente para uma
 *                           sub-etapa continuam recusados e nada muda; com o de 428480f inteiro, não (controlo).
 *
 * Cada um dos 5 ficheiros da biblioteca é preciso: tirá-lo da lista P2R_KEEP faz falhar pelo menos um caso
 * (pipe2.php e pipeline.php: todos; xsell.php: ::rollback_guards; deals.php e pipeline_flow.php:
 * ::rollback_injected_rows). Os 3 ecrãs (funis.php, negocios.php, relatorios.php) só se veem por HTTP; ficam porque
 * são os mesmos ficheiros que o pipe2_screens_test e o pipe2_v1_unchanged_test::after_migration_http provam.
 *
 * As árvores revertidas montam-se numa pasta temporária (git archive de 428480f), com ligações simbólicas para os
 * ficheiros de configuração locais (fora do Git; não se copiam credenciais) e a base de testes atual em tests/.
 * Vale para a fase 0; cada fase seguinte acrescenta o seu caso de reversão.
 *
 * Uso: php tests/pipe2_rollback_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
pipe2_lock($pdo);

/** O commit de antes da fase 0 (a base do trabalho). */
const P2R_BEFORE = '428480f';

/** Os ficheiros que, depois do passo 43, ficam sempre na versão da fase 0 (nunca se repõe a versão antiga). */
const P2R_KEEP = [
    'admin/inc/pipe2.php',
    'admin/inc/deals.php',
    'admin/inc/pipeline.php',
    'admin/inc/pipeline_flow.php',
    'admin/inc/xsell.php',
    'admin/funis.php',
    'admin/negocios.php',
    'admin/relatorios.php',
];

/** A base de testes que corre dentro da árvore revertida (a de agora: a de 428480f não tem o pipe2_lib). */
const P2R_TEST_FILES = ['tests/boot.php', 'tests/lib.php', 'tests/pipe2_lib.php', 'tests/pipe2_v1_snap.php'];

$root = dirname(__DIR__);
$GLOBALS['P2R_TREES'] = [];
register_shutdown_function(static function (): void {
    foreach ($GLOBALS['P2R_TREES'] as $dir) p2r_rmtree($dir);
});
// Árvores que ficaram de uma corrida interrompida (processo que já não existe).
foreach (glob(rtrim(sys_get_temp_dir(), '/') . '/p2r_*_{keep,full}', GLOB_BRACE | GLOB_ONLYDIR) ?: [] as $old) {
    if (preg_match('#/p2r_(\d+)_(?:keep|full)$#', $old, $m) && !file_exists('/proc/' . $m[1])) p2r_rmtree($old);
}

/** Apaga uma árvore temporária deste teste (só dentro de sys_get_temp_dir(), com o prefixo p2r_). */
function p2r_rmtree(string $dir): void {
    $tmp = rtrim(sys_get_temp_dir(), '/') . '/p2r_';
    if (!str_starts_with($dir, $tmp) || !is_dir($dir) || is_link($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (is_link($p) || !$f->isDir()) unlink($p);
        else rmdir($p);
    }
    rmdir($dir);
}

/**
 * O texto de um item «- **Reversão…» dentro de $text, com tudo o que lhe pertence (as linhas mais indentadas do que
 * o traço, também depois de uma linha em branco); '' se não houver.
 */
function p2r_bullet(string $text): string {
    if (!preg_match('/^([ \t]*)- \*\*Revers[ãa]o\b.*$/mu', $text, $m, PREG_OFFSET_CAPTURE)) return '';
    $indent = strlen($m[1][0]);
    $lines = preg_split('/\R/u', substr($text, $m[0][1]));
    $out = [array_shift($lines)];
    foreach ($lines as $l) {
        if (trim($l) !== '' && strlen($l) - strlen(ltrim($l, " \t")) <= $indent) break;
        $out[] = $l;
    }
    return implode("\n", $out);
}

/** Os 8 ficheiros como aparecem nos documentos (sem «admin/»). */
function p2r_doc_names(): array {
    return array_map(fn($f) => substr($f, strlen('admin/')), P2R_KEEP);
}

/**
 * Monta a árvore revertida: admin/ de 428480f; com $keep, os 8 ficheiros da fase 0 por cima; ligações para a
 * configuração local; e a base de testes atual em tests/.
 */
function p2r_tree(string $root, bool $keep): string {
    $dir = rtrim(sys_get_temp_dir(), '/') . '/p2r_' . getmypid() . '_' . ($keep ? 'keep' : 'full');
    p2r_rmtree($dir);
    if (!mkdir($dir, 0700, true)) throw new RuntimeException('Não foi possível criar ' . $dir);
    $GLOBALS['P2R_TREES'][] = $dir;
    [$code, $out] = pipe2_shell('git -C ' . escapeshellarg($root) . ' archive ' . escapeshellarg(P2R_BEFORE) . ' admin | tar -x -C ' . escapeshellarg($dir));
    if ($code !== 0) throw new RuntimeException('git archive ' . P2R_BEFORE . ' falhou: ' . substr($out, 0, 500));
    // A configuração local (ignorada pelo Git): ligações simbólicas, para não copiar credenciais.
    [$code, $out] = pipe2_shell('git -C ' . escapeshellarg($root) . ' ls-files --others --ignored --exclude-standard --directory admin');
    if ($code !== 0) throw new RuntimeException('git ls-files falhou: ' . substr($out, 0, 500));
    foreach (preg_split('/\R/', trim($out)) as $rel) {
        if (!preg_match('#^admin/[\w/]+\.php$#', $rel) || file_exists($dir . '/' . $rel)) continue;
        if (!symlink($root . '/' . $rel, $dir . '/' . $rel)) throw new RuntimeException('Não foi possível ligar ' . $rel);
        $GLOBALS['P2R_LINKS'][] = realpath($root . '/' . $rel);
    }
    if ($keep) {
        foreach (P2R_KEEP as $rel) {
            if (!copy($root . '/' . $rel, $dir . '/' . $rel)) throw new RuntimeException('Não foi possível copiar ' . $rel);
        }
    }
    mkdir($dir . '/tests', 0700);
    foreach (P2R_TEST_FILES as $rel) {
        if (!copy($root . '/' . $rel, $dir . '/' . $rel)) throw new RuntimeException('Não foi possível copiar ' . $rel);
    }
    // A sonda: que SCHEMA_VERSION e que ficheiros carrega a plataforma desta árvore (os mesmos que a fotografia).
    file_put_contents($dir . '/tests/p2r_probe.php', "<?php\nrequire __DIR__ . '/boot.php';\nrequire_once __DIR__ . '/pipe2_lib.php';\n"
        . "if (is_file(__DIR__ . '/../admin/inc/pipe2.php')) require_once __DIR__ . '/../admin/inc/pipe2.php';\n"
        . "echo json_encode(['schema' => SCHEMA_VERSION, 'files' => get_included_files()]), \"\\n\";\n");
    file_put_contents($dir . '/tests/p2r_guards.php', P2R_GUARDS);
    return $dir;
}

/**
 * As escritas forjadas (Review Focus 3) com o código desta árvore, na base privada argv[1]: mover o negócio, mudar
 * de funil e mover a frente para uma sub-etapa do VFX. Imprime, por escrita, a mensagem da recusa ou «passou», e as
 * etapas depois. Os negócios «P2T» saem na limpeza do fim do processo (pipe2_lib).
 */
const P2R_GUARDS = <<<'PHP'
<?php
require __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
$tp = pipe2_tmpdb_pdo($argv[1]);
pipe2_use($tp);
$col = function (string $sql, array $a = []) use ($tp) { $st = $tp->prepare($sql); $st->execute($a); return (int)$st->fetchColumn(); };
$vfx = $col("SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY position, id LIMIT 1");
$cgi = $col("SELECT id FROM funnels WHERE xray_kind = 'cgi' AND is_base = 1 ORDER BY position, id LIMIT 1");
$sub = $col("SELECT id FROM funnel_stages WHERE funnel_id = ? AND level = 'front' ORDER BY position, id LIMIT 1", [$vfx]);
$xray = (int)funnel_fixed_stage($tp, $vfx, 'xray');
$prod = (int)(array_values(array_filter(funnel_stages($tp, $vfx), fn($s) => $s['kind'] === 'production'))[0]['id'] ?? 0);
$prop = (int)(array_values(array_filter(funnel_stages($tp, $cgi), fn($s) => $s['kind'] === 'commercial'))[0]['id'] ?? 0);
$out = ['ids' => [$vfx, $cgi, $sub, $xray, $prod, $prop]];
$try = function (string $k, callable $fn) use (&$out) {
    try { $fn(); $out[$k] = 'passou'; } catch (Throwable $e) { $out[$k] = $e->getMessage(); }
};
$d1 = pipe2_deal($tp, ['phase' => 'pipeline', 'funnel_id' => $vfx, 'stage_id' => $xray]);
$try('deal_move', fn() => deal_move($tp, deal_get($tp, $d1), $sub));
$d2 = pipe2_deal($tp, ['phase' => 'pipeline', 'funnel_id' => $cgi, 'stage_id' => $prop]);
$try('deal_change_funnel', fn() => deal_change_funnel($tp, deal_get($tp, $d2), $vfx, $sub));
$d3 = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $vfx, 'stage_id' => $prod]);
$ln = pipe2_front($tp, $d3, $vfx, ['status' => 'active', 'stage_id' => $prod]);
$try('xsell_line_move', fn() => xsell_line_move($tp, deal_get($tp, $d3), xsell_line_get($tp, $ln), $sub));
$out['after'] = [(int)deal_get($tp, $d1)['stage_id'], (int)deal_get($tp, $d2)['funnel_id'], (int)deal_get($tp, $d2)['stage_id'],
                 (int)xsell_line_get($tp, $ln)['stage_id']];
$out['before'] = [$xray, $cgi, $prop, $prod];
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
PHP;

/**
 * A plataforma desta árvore: SCHEMA_VERSION e os ficheiros carregados que não são dela (fora de $dir e que não são a
 * configuração ligada). Prova que a fotografia corre o código revertido e não o da cópia de trabalho.
 */
function p2r_probe(string $dir): array {
    $r = p2r_run($dir, 'tests/p2r_probe.php', []);
    $j = $r['json'];
    if ($r['code'] !== 0 || !is_array($j)) return ['schema' => null, 'foreign' => ['(a sonda falhou: ' . substr(trim($r['err']), 0, 300) . ')']];
    $links = $GLOBALS['P2R_LINKS'] ?? [];
    $foreign = array_values(array_filter($j['files'], fn($f) => !str_starts_with($f, $dir . '/') && !in_array($f, $links, true)));
    return ['schema' => $j['schema'], 'foreign' => $foreign];
}

/** Corre php <$dir>/<$script> <args> a partir de $dir: ['code', 'err', 'json']. */
function p2r_run(string $dir, string $script, array $args): array {
    $outF = tempnam(sys_get_temp_dir(), 'p2ro');
    $errF = tempnam(sys_get_temp_dir(), 'p2re');
    $p = proc_open(array_merge([PHP_BINARY, $dir . '/' . $script], array_map('strval', $args)),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outF, 'w'], 2 => ['file', $errF, 'w']], $pipes, $dir);
    if (!is_resource($p)) throw new RuntimeException('Não foi possível lançar ' . $script . ' em ' . $dir);
    $code = proc_close($p);
    $out = (string)file_get_contents($outF);
    $err = (string)file_get_contents($errF);
    @unlink($outF);
    @unlink($errF);
    return ['code' => $code, 'err' => $err, 'json' => json_decode($out, true)];
}

/** A fotografia correu e devolveu JSON; senão mostra o erro e devolve null. */
function p2r_snap_ok(string $what, string $dir, array $args): ?array {
    $r = p2r_run($dir, 'tests/pipe2_v1_snap.php', $args);
    $ok = $r['code'] === 0 && is_array($r['json']);
    t_true($ok, "$what: pipe2_v1_snap.php " . implode(' ', $args) . ' sai com 0 e JSON (código ' . $r['code'] . ': ' . substr(trim($r['err']), 0, 600) . ')');
    return $ok ? $r['json'] : null;
}

// ── ::documented ────────────────────────────────────────────

$spec = (string)file_get_contents($root . '/docs/ESPECIFICACAO-PLATAFORMA.md');
$sec = preg_match('/^### 10\.33 .*?(?=^### )/msu', $spec, $m) ? $m[0] : '';
t_true($sec !== '', '::documented a especificação tem a 10.33 (Pipeline v2 — fase 0)');
$plan = (string)file_get_contents($root . '/docs/superpowers/plans/2026-11-05-pipeline-v2-fase0.md');
$gc = preg_match('/^## Global Constraints.*?(?=^## )/msu', $plan, $m) ? $m[0] : '';
t_true($gc !== '', '::documented o plano da fase 0 tem as Global Constraints');
foreach (['a 10.33' => p2r_bullet($sec), 'as Global Constraints do plano da fase 0' => p2r_bullet($gc)] as $where => $txt) {
    t_true($txt !== '', "::documented $where têm um item «Reversão»");
    $missing = array_values(array_filter(p2r_doc_names(), fn($n) => !str_contains($txt, '`' . $n . '`')));
    t_eq($missing, [], "::documented a «Reversão» d$where nomeia os 8 ficheiros que nunca se repõem depois do passo 43");
    t_true(str_contains($txt, '`inc/migrate.php`'), "::documented a «Reversão» d$where diz o que acontece ao `inc/migrate.php` antigo");
    t_true(str_contains($txt, 'pipe2_rollback_test'), "::documented a «Reversão» d$where aponta a prova (pipe2_rollback_test)");
}

// ── ::code_rollback ─────────────────────────────────────────

$base = pipe2_base_json();
$dump = pipe2_base_dump();
$db = pipe2_tmpdb_create($dump);
$mig = pipe2_sub('pipe2_v1_snap.php', ['--migrate', '--db=' . $db]);
t_eq([$mig['json']['from'] ?? null, $mig['json']['to'] ?? null], [42, 43], '::code_rollback a base privada migra da 42 para a 43 com o código de agora');

$keep = p2r_tree($root, true);
t_true(str_contains((string)file_get_contents($keep . '/admin/inc/migrate.php'), 'const SCHEMA_VERSION = 42;'),
    '::code_rollback a árvore revertida tem o inc/migrate.php de antes (SCHEMA_VERSION 42)');
t_true(!file_exists($keep . '/admin/inc/migrate_pipeline2.php'), '::code_rollback a árvore revertida não tem o passo 43');
$probe = p2r_probe($keep);
t_eq($probe['schema'], 42, '::code_rollback a plataforma da árvore revertida carrega o SCHEMA_VERSION 42');
t_eq($probe['foreign'], [], '::code_rollback a árvore revertida só carrega ficheiros dela (e a configuração ligada)');
$rb = p2r_snap_ok('::code_rollback', $keep, ['--migrate', '--db=' . $db]);
t_eq([$rb['from'] ?? null, $rb['to'] ?? null], [43, 43], '::code_rollback o código revertido não migra nem desfaz nada: a base fica na 43');
$snap = p2r_snap_ok('::code_rollback', $keep, ['--php', '--db=' . $db]);
t_eq($snap['meta']['schema_version'] ?? null, 43, '::code_rollback a fotografia é da base na 43');
$d = pipe2_diff($base['php'], $snap['php'] ?? null);
t_true($d === [], '::code_rollback com o código revertido (menos os 8), a fotografia PHP da v1 é igual à da 42'
    . ($d === [] ? '' : ' — ' . count($d) . " diferença(s):\n  " . implode("\n  ", array_slice($d, 0, 25))));

// ── ::full_rollback_breaks (controlo) ───────────────────────

$full = p2r_tree($root, false);
$probe = p2r_probe($full);
t_eq([$probe['schema'], $probe['foreign']], [42, []], '::full_rollback_breaks a árvore de 428480f inteira carrega o SCHEMA_VERSION 42 e só ficheiros dela');
$bad = p2r_snap_ok('::full_rollback_breaks', $full, ['--php', '--db=' . $db]);
$bd = pipe2_diff($base['php'], $bad['php'] ?? null);
t_true((bool)array_filter($bd, fn($l) => preg_match('/^funnels\.\d+\.stages\b/', $l)),
    '::full_rollback_breaks com o código de antes inteiro, as sub-etapas aparecem nas etapas dos funis ativos');
t_true((bool)array_filter($bd, fn($l) => str_starts_with($l, 'funnel_list')),
    '::full_rollback_breaks com o código de antes inteiro, os funis só da v2 aparecem na lista dos funis');

// ── ::rollback_injected_rows ────────────────────────────────
// As mesmas 4 linhas do pipe2_v1_unchanged_test::injected_rows_hidden (etapa de produção e X-Ray retirados, regra
// mensal numa sub-etapa, regra por data na etapa retirada): com o código revertido continuam escondidas. É o que
// prova que o inc/deals.php (funnel_fixed_stage) e o inc/pipeline_flow.php (flow_tick_rules) ficam na fase 0.

$tp = pipe2_tmpdb_pdo($db);
$col = function (string $sql, array $a = []) use ($tp) { $st = $tp->prepare($sql); $st->execute($a); return (int)$st->fetchColumn(); };
$vfx = $col("SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY position, id LIMIT 1");
$vfront = $col("SELECT id FROM funnel_stages WHERE funnel_id = ? AND level = 'front' ORDER BY position, id LIMIT 1", [$vfx]);
t_true($vfx > 0 && $vfront > 0, '::rollback_injected_rows a base privada tem o VFX e as sub-etapas dele');
$now = date('Y-m-d H:i:s');
$ins = $tp->prepare('INSERT INTO funnel_stages (funnel_id, name, kind, position, retired_at) VALUES (?, ?, ?, ?, ?)');
$ins->execute([$vfx, 'P2T · produção retirada', 'production', 67, $now]);
$retProd = (int)$tp->lastInsertId();
$ins->execute([$vfx, 'P2T · X-Ray retirado', 'xray', 5, $now]);
$rule = $tp->prepare('INSERT INTO stage_automations (stage_id, title, trig, due_days, is_active) VALUES (?, ?, ?, ?, 1)');
$rule->execute([$vfront, 'P2T · mensal na sub-etapa', 'monthly', 1]);
$rule->execute([$retProd, 'P2T · por data na etapa retirada', 'date', 0]);
$inj = p2r_snap_ok('::rollback_injected_rows', $keep, ['--php', '--db=' . $db]);
$d = pipe2_diff($base['php'], $inj['php'] ?? null);
t_true($d === [], '::rollback_injected_rows com o código revertido, as etapas retiradas e as regras escondidas não aparecem'
    . ($d === [] ? '' : ' — ' . count($d) . " diferença(s):\n  " . implode("\n  ", array_slice($d, 0, 25))));

// ── ::rollback_guards ───────────────────────────────────────
// Com o código revertido, um POST forjado com o id de uma sub-etapa continua recusado (inc/pipeline.php, inc/xsell.php);
// com o de antes inteiro, não (controlo).

// Numa 2.ª cópia migrada: as escritas que o controlo deixa passar não tocam na base das fotografias.

$db2 = pipe2_tmpdb_create($dump);
$mig2 = pipe2_sub('pipe2_v1_snap.php', ['--migrate', '--db=' . $db2]);
t_eq($mig2['json']['to'] ?? null, 43, '::rollback_guards a 2.ª base privada migra para a 43');
$want = ['deal_move' => 'Etapa inválida.', 'deal_change_funnel' => 'Escolha a etapa de destino no funil novo.', 'xsell_line_move' => 'Etapa inválida.'];
$g = p2r_run($keep, 'tests/p2r_guards.php', [$db2]);
$gj = is_array($g['json']) ? $g['json'] : [];
t_true($g['code'] === 0 && $gj !== [] && !in_array(0, $gj['ids'] ?? [0], true),
    '::rollback_guards a sonda das escritas corre com o código revertido (código ' . $g['code'] . ': ' . substr(trim($g['err']), 0, 600) . ')');
t_eq(array_intersect_key($gj, $want), $want, '::rollback_guards com o código revertido, mover o negócio, mudar de funil e mover a frente para uma sub-etapa são recusados');
t_eq($gj['after'] ?? null, $gj['before'] ?? false, '::rollback_guards com o código revertido, as escritas recusadas não mudam nada');
$gf = p2r_run($full, 'tests/p2r_guards.php', [$db2]);
$gfj = is_array($gf['json']) ? $gf['json'] : [];
t_true($gf['code'] === 0 && array_intersect_key($gfj, $want) !== $want,
    '::rollback_guards (controlo) com o código de antes inteiro, alguma escrita para a sub-etapa passa (código ' . $gf['code'] . ': '
    . pipe2_json(array_intersect_key($gfj, $want)) . ' ' . substr(trim($gf['err']), 0, 300) . ')');

$tp = null;
pipe2_tmpdb_drop($db);
pipe2_tmpdb_drop($db2);
t_done();
