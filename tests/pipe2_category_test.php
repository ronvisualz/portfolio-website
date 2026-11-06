<?php
// Pipeline v2, fase 1 — admin/inc/pipe2_category.php: as regras das categorias (Pós-produção antes de CGI; VFX + 3D +
// Motion Design → CGI), os códigos dos serviços de um funil e de um negócio, a categoria gravada uma vez e o
// «Recalcular». Corre numa base privada (p2v_db: dump da 42 migrado até à SCHEMA_VERSION, com a semente das
// categorias); não escreve na bancada.
//   php tests/pipe2_category_test.php
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2_category.php';

[$db, $tp] = p2v_db();

/** A linha do negócio, como a recebem as funções. */
function p2cat_deal(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deals WHERE id = ?', [$id])[0];
}

/** As linhas p2_category do histórico do negócio, pela ordem. */
function p2cat_hist(PDO $pdo, int $dealId): array {
    return p2v_rows($pdo, "SELECT note, user_id FROM deal_history WHERE deal_id = ? AND event = 'p2_category' ORDER BY id", [$dealId]);
}

/** Uma regra de categoria de teste (código ≤ 8, como a coluna). Devolve o id. */
function p2cat_rule(PDO $pdo, array $o): int {
    $row = array_merge(['code' => 'p2tx', 'name' => 'P2T · x', 'position' => 0, 'need_all' => '', 'need_any' => '', 'exclude_any' => '',
                        'need_min' => 0, 'is_active' => 1], $o);
    return pipe2_insert($pdo, 'pipe2_categories', $row);
}

// Tudo o que os casos usam cria-se antes das leituras (regra da base de testes).
$w = p2v_world($tp, ['vfx', '3d', 'md', 'cmp', 'ed']);
$dCgi    = p2v_deal($tp, $w, ['vfx', '3d', 'md']);                       // Kickoff, 3 frentes queued
$dOrder  = p2v_deal($tp, $w, ['md', '3d', 'vfx']);                       // a principal é md; a ordem da v2 manda
$dNoLine = p2v_deal($tp, $w, ['ed'], ['lines' => false]);                // sem deal_lines: o funil do negócio
$dPlan   = p2v_deal($tp, $w, ['ed', 'vfx'], ['at' => 'proposal']);       // antes do Kickoff: frentes planned
$dStore  = p2v_deal($tp, $w, ['vfx', '3d', 'md']);
$dOne    = p2v_deal($tp, $w, ['vfx']);
$dBy     = p2v_deal($tp, $w, ['ed', 'cmp']);
$dStrict = p2v_deal($tp, $w, ['3d', 'cmp']);
$fVfx1   = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$fCgi1   = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'cgi' AND is_base = 1 ORDER BY id LIMIT 1");
t_true($fVfx1 > 0 && $fCgi1 > 0, 'a base privada tem os funis reais vfx e cgi');
$dV1     = pipe2_deal($tp, ['funnel_id' => $fVfx1, 'phase' => 'pipeline']);                 // negócio v1, sem linhas
$dV1x    = pipe2_deal($tp, ['funnel_id' => $fVfx1, 'phase' => 'pipeline']);                 // negócio v1 com crosselling
pipe2_front($tp, $dV1x, $fVfx1, ['is_main' => 1, 'status' => 'planned']);
pipe2_front($tp, $dV1x, $fCgi1, ['is_main' => 0, 'status' => 'planned']);

// ── as regras da base (semente) ─────────────────────────────
$rules = pipe2_category_rules($tp);
t_eq($rules, [
    ['code' => 'postprod', 'name' => 'Pós-produção', 'need_all' => ['ed'], 'need_any' => ['vfx', '3d', 'md', 'cmp'], 'need_min' => 1, 'exclude_any' => []],
    ['code' => 'cgi', 'name' => 'CGI', 'need_all' => [], 'need_any' => ['vfx', '3d', 'cmp'], 'need_min' => 2, 'exclude_any' => ['ed']],
], 'rules: as 2 da semente, por posição, com as listas de códigos separadas');

// ── ::cases ─────────────────────────────────────────────────
$cases = [
    [['vfx', '3d', 'md'], 'cgi'],                    // tem dois de VFX/3D/Composit e não tem Edição
    [['md', '3d'], ''],                              // Motion Design sem Edição: sem categoria
    [['vfx'], ''],                                   // um só serviço
    [['ed'], ''],
    [['ed', 'vfx'], 'postprod'],
    [['vfx', '3d', 'md', 'cmp', 'ed'], 'postprod'],  // One House Only
    [['vfx', '3d'], 'cgi'],
    [['3d', 'cmp'], 'cgi'],
    [['vfx', '3d', 'ed'], 'postprod'],
    [['ed', 'adv'], ''],
    [['cam', 'adv'], ''],
    [['3d', 'vfx', '3d'], 'cgi'],                    // a ordem e os repetidos não contam
    [['vfx', 'vfx'], ''],                            // um serviço repetido continua a ser um só
    [['md', 'ed'], 'postprod'],
    [[], ''],
];
foreach ($cases as [$codes, $want]) {
    t_eq(pipe2_category_of($codes, $rules), $want, 'cases: ' . (implode(' + ', $codes) ?: '(nenhum)') . ' → ' . ($want ?: 'sem categoria'));
}
$only = [['code' => 'x', 'name' => 'X', 'need_all' => [], 'need_any' => ['vfx'], 'need_min' => 1, 'exclude_any' => []]];
t_eq(pipe2_category_of(['vfx', 'blaine'], $only), '', 'cases: um código que não é serviço não conta para os 2 serviços');
t_eq(pipe2_category_of(['vfx', '3d'], $only), 'x', 'cases: a regra bate com 2 serviços');
t_eq(pipe2_category_of([' VFX', '3d '], $rules), 'cgi', 'cases: os códigos comparam-se sem espaços nem maiúsculas');
t_eq(pipe2_category_of(['vfx', '3d'], []), '', 'cases: sem regras, sem categoria');
// cada condição sozinha (com a semente, Pós-produção vem antes e esconde o exclude_any do CGI)
t_eq(pipe2_category_of(['vfx', '3d', 'ed'], array_reverse($rules)), 'postprod', 'cases: CGI primeiro na lista, mas com Edição o exclude_any afasta-o');
t_eq(pipe2_category_of(['vfx', '3d', 'ed'], [$rules[1]]), '', 'cases: só a regra do CGI e com Edição → sem categoria');
t_eq(pipe2_category_of(['vfx', '3d'], [$rules[0]]), '', 'cases: só a regra da Pós-produção e sem Edição (need_all) → sem categoria');
t_eq(pipe2_category_of(['ed', 'adv'], [$rules[0]]), '', 'cases: Edição sem nenhum de produção (need_min 1) → sem categoria');

// ── ::order_postprod_first ──────────────────────────────────
$tp->exec("UPDATE pipe2_categories SET exclude_any = '' WHERE code = 'cgi'");
$both = pipe2_category_rules($tp);
t_eq(pipe2_category_of(['vfx', '3d', 'ed'], $both), 'postprod', 'order: VFX + 3D + Edição bate nas duas e ganha Pós-produção (posição 1)');
t_eq(pipe2_category_of(['vfx', '3d', 'ed'], array_reverse($both)), 'cgi', 'order: pipe2_category_of segue a ordem da lista que recebe');
$tp->exec("UPDATE pipe2_categories SET exclude_any = 'ed' WHERE code = 'cgi'");
$extra = p2cat_rule($tp, ['need_any' => ' vfx, 3d ,,ed ', 'need_min' => 2]);
$withExtra = pipe2_category_rules($tp);
t_eq(array_column($withExtra, 'code'), ['p2tx', 'postprod', 'cgi'], 'order: a regra de posição 0 vem primeiro');
t_eq($withExtra[0]['need_any'], ['vfx', '3d', 'ed'], 'order: a lista de códigos ignora espaços e vazios');
t_eq(pipe2_category_of(['vfx', '3d', 'ed'], $withExtra), 'p2tx', 'order: ganha a regra de menor posição');
$twin = p2cat_rule($tp, ['code' => 'p2ty', 'name' => 'P2T · y', 'need_any' => 'vfx,3d', 'need_min' => 2]);
t_eq(array_column(pipe2_category_rules($tp), 'code'), ['p2tx', 'p2ty', 'postprod', 'cgi'], 'order: na mesma posição, pelo id');
$tp->prepare('UPDATE pipe2_categories SET is_active = 0 WHERE id IN (?, ?)')->execute([$extra, $twin]);
t_eq(array_column(pipe2_category_rules($tp), 'code'), ['postprod', 'cgi'], 'order: is_active 0 fica de fora');
t_eq(pipe2_category_of(['vfx', '3d', 'ed'], pipe2_category_rules($tp)), 'postprod', 'order: sem a regra inativa, volta a Pós-produção');
$tp->prepare('DELETE FROM pipe2_categories WHERE id IN (?, ?)')->execute([$extra, $twin]);

// ── ::names ─────────────────────────────────────────────────
t_eq(pipe2_category_name($tp, 'postprod'), 'Pós-produção', 'names: postprod');
t_eq(pipe2_category_name($tp, 'cgi'), 'CGI', 'names: cgi');
t_eq(pipe2_category_name($tp, ''), '', "names: '' → ''");
$tp->exec("UPDATE pipe2_categories SET is_active = 0 WHERE code = 'cgi'");
t_eq(pipe2_category_name($tp, 'cgi'), 'CGI', 'names: uma regra desligada continua com nome (a categoria gravada mostra-se)');
$tp->exec("UPDATE pipe2_categories SET is_active = 1 WHERE code = 'cgi'");
t_eq(pipe2_category_name($tp, 'zzz'), 'zzz', 'names: um código sem regra mostra-se como está, não desaparece');
t_eq(pipe2_services_label(['md', '3d']), '3D + Motion Design', 'label: pela ordem da v2');
t_eq(pipe2_services_label(['cam', 'vfx', 'vfx']), 'VFX + Campanhas', 'label: sem repetidos');
t_eq(pipe2_services_label(['ed']), 'Edição', 'label: um serviço');
t_eq(pipe2_services_label(['blaine', 'zzz']), '', 'label: os códigos que não são serviços não aparecem');
t_eq(pipe2_services_label([]), '', 'label: nenhum');

// ── ::funnel_codes ──────────────────────────────────────────
$fc = [
    [['service_code' => '3d', 'xray_kind' => 'cgi'], ['3d']],
    [['service_code' => '', 'xray_kind' => 'ads'], ['adv']],
    [['service_code' => '', 'xray_kind' => 'buddy'], ['cam']],
    [['service_code' => '', 'xray_kind' => 'vfx,cgi'], ['vfx', '3d']],
    [['service_code' => '', 'xray_kind' => 'cgi, vfx'], ['vfx', '3d']],
    [['service_code' => '', 'xray_kind' => 'ads,adverts'], ['adv']],
    [['service_code' => '', 'xray_kind' => ''], []],
    [['service_code' => '', 'xray_kind' => 'blaine'], []],
    [['service_code' => '', 'xray_kind' => 'buddy,blaine'], ['cam']],
    [['service_code' => 'blaine', 'xray_kind' => ''], []],
    [['service_code' => 'blaine', 'xray_kind' => 'buddy'], ['cam']],
    [['xray_kind' => 'campanhas'], ['cam']],
    [[], []],
];
foreach ($fc as [$f, $want]) {
    $got = pipe2_funnel_codes($f);
    t_eq($got, $want, 'funnel_codes: ' . json_encode($f, JSON_UNESCAPED_UNICODE));
    t_true(!in_array('blaine', $got, true), 'funnel_codes: nunca «blaine» (' . json_encode($f) . ')');
}
$realCodes = [];
foreach (p2v_rows($tp, 'SELECT * FROM funnels WHERE is_base = 1 ORDER BY id') as $f) $realCodes[(string)$f['xray_kind'] ?: (string)$f['service_code']] = pipe2_funnel_codes($f);
t_eq([$realCodes['vfx'] ?? null, $realCodes['cgi'] ?? null, $realCodes['campanhas'] ?? null, $realCodes['ads'] ?? null, $realCodes['buddy'] ?? null, $realCodes['adverts'] ?? null],
    [['vfx'], ['3d'], ['cam'], ['adv'], ['cam'], ['adv']], 'funnel_codes: os funis reais da base (com e sem service_code)');
t_eq([$realCodes['md'] ?? null, $realCodes['cmp'] ?? null, $realCodes['ed'] ?? null], [['md'], ['cmp'], ['ed']], 'funnel_codes: os funis novos da semente');

// ── ::deal_codes_and_category ───────────────────────────────
$row = p2cat_deal($tp, $dCgi['id']);
t_eq(pipe2_deal_codes($tp, $row), ['vfx', '3d', 'md'], 'deal_codes: as 3 frentes');
t_eq(pipe2_deal_category($tp, $row), 'cgi', 'deal_category: VFX + 3D + Motion Design → CGI');
p2v_front($tp, $dCgi['lines']['md'], ['status' => 'merged']);
t_eq(pipe2_deal_codes($tp, $row), ['vfx', '3d'], 'deal_codes: a frente juntada (merged) não conta');
t_eq(pipe2_deal_category($tp, $row), 'cgi', 'deal_category: VFX + 3D → CGI');
p2v_front($tp, $dCgi['lines']['3d'], ['status' => 'merged']);
t_eq(pipe2_deal_category($tp, $row), '', 'deal_category: só VFX vivo → sem categoria');
t_eq(pipe2_deal_codes($tp, p2cat_deal($tp, $dOrder['id'])), ['vfx', '3d', 'md'], 'deal_codes: pela ordem da v2, não pela das frentes');
t_eq(pipe2_deal_codes($tp, p2cat_deal($tp, $dNoLine['id'])), ['ed'], 'deal_codes: sem linhas, o funil do negócio');
t_eq(pipe2_deal_category($tp, p2cat_deal($tp, $dNoLine['id'])), '', 'deal_category: sem linhas e um serviço → sem categoria');
$rowPlan = p2cat_deal($tp, $dPlan['id']);
t_eq([pipe2_deal_codes($tp, $rowPlan), pipe2_deal_category($tp, $rowPlan)], [['vfx', 'ed'], 'postprod'], 'deal: antes do Kickoff contam as frentes planned');
t_eq(pipe2_deal_codes($tp, p2cat_deal($tp, $dV1)), ['vfx'], 'deal_codes: negócio v1 sem linhas → o funil real pelo mapa da v1');
$rowV1x = p2cat_deal($tp, $dV1x);
t_eq([pipe2_deal_codes($tp, $rowV1x), pipe2_deal_category($tp, $rowV1x)], [['vfx', '3d'], 'cgi'], 'deal: negócio v1 com crosselling VFX + CGI → vfx, 3d (CGI)');

// ── ::stored_once_and_recalc ────────────────────────────────
p2v_as(null);
$row = p2cat_deal($tp, $dStore['id']);
t_eq($row['category_at'], null, 'store: o negócio começa sem categoria gravada');
t_eq(pipe2_category_store($tp, $row), 'cgi', 'store: grava CGI');
$after = p2cat_deal($tp, $dStore['id']);
t_eq($after['category'], 'cgi', 'store: deals.category = cgi');
t_true($after['category_at'] !== null, 'store: deals.category_at preenchido');
t_eq(p2cat_hist($tp, $dStore['id']), [['note' => 'Categoria: CGI', 'user_id' => null]], 'store: uma linha p2_category de «Sistema» (sem utilizador)');

$tp->exec("UPDATE pipe2_categories SET need_min = 3 WHERE code = 'cgi'");
t_eq(pipe2_deal_category($tp, $row), '', 'store: com need_min 3, calculada agora já não é CGI');
t_eq(pipe2_category_store($tp, $row), 'cgi', 'store: gravada uma vez — mudar a regra não a reescreve (mesmo com a linha antiga do negócio)');
t_eq(p2cat_deal($tp, $dStore['id'])['category_at'], $after['category_at'], 'store: category_at não muda');
t_eq(count(p2cat_hist($tp, $dStore['id'])), 1, 'store: a 2.ª chamada não regista nada');

t_eq(pipe2_category_recalc($tp, $after, 1), '', '«Recalcular»: com a regra nova, sem categoria');
$re = p2cat_deal($tp, $dStore['id']);
t_eq([$re['category'], $re['category_at'] !== null], ['', true], '«Recalcular»: grava a categoria vazia, com category_at');
t_eq(p2cat_hist($tp, $dStore['id']), [['note' => 'Categoria: CGI', 'user_id' => null], ['note' => 'Categoria recalculada: CGI → sem categoria', 'user_id' => 1]],
    '«Recalcular»: regista quem recalculou e a mudança');
t_eq(pipe2_category_store($tp, $re), '', 'store: depois do «Recalcular», a vazia também fica gravada');
t_eq(count(p2cat_hist($tp, $dStore['id'])), 2, 'store: sem nova linha');
$tp->exec("UPDATE pipe2_categories SET need_min = 2 WHERE code = 'cgi'");
t_eq(pipe2_category_recalc($tp, $re), 'cgi', '«Recalcular» de novo, com a regra reposta: CGI');
t_eq(array_slice(p2cat_hist($tp, $dStore['id']), -1), [['note' => 'Categoria recalculada: sem categoria → CGI', 'user_id' => null]], '«Recalcular» sem $by: «Sistema»');
t_eq(pipe2_category_recalc($tp, $re, 1), 'cgi', '«Recalcular» sem mudança');
t_eq(array_slice(p2cat_hist($tp, $dStore['id']), -1), [['note' => 'Categoria recalculada: CGI (sem alteração)', 'user_id' => 1]], '«Recalcular» sem mudança também fica registado');

$one = p2cat_deal($tp, $dOne['id']);
t_eq(pipe2_category_store($tp, $one), '', 'store: um só serviço → sem categoria');
t_true(p2cat_deal($tp, $dOne['id'])['category_at'] !== null, 'store: a ausência de categoria também fica gravada (congelada)');
t_eq(p2cat_hist($tp, $dOne['id']), [], 'store: sem categoria e sem mudança, nada no histórico');

t_eq(pipe2_category_store($tp, p2cat_deal($tp, $dBy['id']), 1), 'postprod', 'store com $by: Edição + Composit → Pós-produção');
t_eq(p2cat_hist($tp, $dBy['id']), [['note' => 'Categoria: Pós-produção', 'user_id' => 1]], 'store com $by: regista a pessoa');

// em modo estrito (a produção não o usa, mas as consultas novas têm de passar)
pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
$rowS = p2cat_deal($tp, $dStrict['id']);
t_eq([pipe2_deal_codes($tp, $rowS), pipe2_category_store($tp, $rowS), pipe2_category_recalc($tp, $rowS, 1), count(pipe2_category_rules($tp))],
    [['3d', 'cmp'], 'cgi', 'cgi', 2], 'strict: leitura, gravação e «Recalcular» em modo estrito');
pipe2_sql_mode($tp, '');

// ── sem o esquema da v2 (base na 42: sem pipe2_seeded) ──────
$mem = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$mem->exec('CREATE TABLE settings (skey VARCHAR(64) PRIMARY KEY, svalue TEXT NOT NULL)');
$mem->exec("CREATE TABLE deals (id INTEGER PRIMARY KEY, funnel_id INTEGER NULL)");
$mem->exec("INSERT INTO deals (id, funnel_id) VALUES (1, NULL)");
t_eq(pipe2_category_rules($mem), [], 'sem o esquema da v2: rules → []');
t_eq(pipe2_category_name($mem, 'cgi'), 'cgi', 'sem o esquema da v2: o nome é o código');
t_eq(pipe2_category_store($mem, ['id' => 1, 'funnel_id' => null]), '', 'sem o esquema da v2: store não grava nem rebenta');
t_eq(pipe2_category_recalc($mem, ['id' => 1, 'funnel_id' => null], 1), '', 'sem o esquema da v2: «Recalcular» não grava nem rebenta');

// ── ::v1_noop ───────────────────────────────────────────────
// 1. Só os ficheiros da v2 (admin/inc/pipe2_*.php) chamam a gravação; nenhum código da v1 a chama.
$root = dirname(__DIR__);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$callers = [];
$seen = 0;
foreach ($it as $file) {
    $path = substr((string)$file, strlen($root) + 1);
    if (!str_ends_with($path, '.php') || preg_match('~^(\.git|tests|docs|node_modules|vendor)/~', $path)) continue;
    $seen++;
    $src = preg_replace('/function\s+pipe2_category_(store|recalc)\b/', '', (string)file_get_contents((string)$file));
    if (preg_match('/\bpipe2_category_(store|recalc)\s*\(/', $src) && !preg_match('~^admin/inc/pipe2_[a-z0-9_]+\.php$~', $path)) $callers[] = $path;
}
t_true($seen > 50, 'v1_noop: o varrimento viu o código (' . $seen . ' ficheiros)');
t_eq($callers, [], 'v1_noop: nenhum ficheiro fora de admin/inc/pipe2_*.php grava a categoria');
// 2. As leituras não escrevem: nem nos negócios v1 da base, nem nos v2.
$sigTables = ['deals', 'deal_lines', 'deal_history', 'pipe2_categories'];
$before = p2v_sig($tp, $sigTables);
foreach (p2v_rows($tp, 'SELECT * FROM deals ORDER BY id') as $d) {
    pipe2_deal_codes($tp, $d);
    pipe2_deal_category($tp, $d);
}
pipe2_category_rules($tp);
pipe2_category_name($tp, 'cgi');
t_eq(p2v_sig($tp, $sigTables), $before, 'v1_noop: ler códigos e categorias de todos os negócios não muda nenhuma linha');
t_true((int)p2v_col($tp, "SELECT COUNT(*) FROM deals WHERE category <> '' OR category_at IS NOT NULL") === 4,
    'v1_noop: só os 4 negócios de teste gravados têm categoria (nenhum negócio da base)');

t_done();
