<?php
// Pipeline v2, fase 1 — admin/inc/pipe2_leads.php: os serviços da lead como lista de códigos (D27). Os códigos limpos
// e pela ordem da v2, o mapa dos rótulos da v1 («VFX / 3D» e «Ambos» ambíguos) e dos valores do site, a gravação na
// lead (só na lead e no Pipeline, sem mudar deals.service), a categoria calculada na hora (nunca gravada), o funil v2
// de cada código e o lead_convert para um funil v2 (frentes planned na mesma transação). Num funil v1 e numa base na
// 42 nada muda; LEAD_SERVICES_LIST e o lead_input ficam como estão. Corre em bases privadas (p2v_db: dump da 42
// migrado até à SCHEMA_VERSION; e uma fresca na 42, sem migrar); não escreve na bancada.
//   php tests/pipe2_lead_services_test.php
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2_leads.php';

[$db, $tp] = p2v_db();

/** A linha do negócio/lead, como a recebem as funções. */
function p2ls_row(PDO $pdo, int $id): array {
    return p2v_rows($pdo, 'SELECT * FROM deals WHERE id = ?', [$id])[0];
}

/** As linhas de um evento do histórico do negócio, pela ordem. */
function p2ls_hist(PDO $pdo, int $dealId, string $event): array {
    return p2v_rows($pdo, 'SELECT note, user_id FROM deal_history WHERE deal_id = ? AND event = ? ORDER BY id', [$dealId, $event]);
}

/** As frentes do negócio: [funil, principal, estado, sub-etapa], a principal primeiro. */
function p2ls_lines(PDO $pdo, int $dealId): array {
    return array_map(fn($r) => [(int)$r['funnel_id'], (int)$r['is_main'], (string)$r['status'], $r['stage_id'] === null ? null : (int)$r['stage_id']],
        p2v_rows($pdo, 'SELECT funnel_id, is_main, status, stage_id FROM deal_lines WHERE deal_id = ? ORDER BY is_main DESC, position, id', [$dealId]));
}

/** Uma lead de teste (fase lead, sem funil), com os códigos e o rótulo da v1 dados. */
function p2ls_lead(PDO $pdo, string $codes = '', string $service = '', string $phase = 'lead'): int {
    $id = pipe2_deal($pdo, ['phase' => $phase, 'name' => 'lead serviços', 'service_codes' => $codes]);
    $pdo->prepare('UPDATE deals SET service = ? WHERE id = ?')->execute([$service, $id]);
    return $id;
}

/** A mensagem da exceção de $fn (ou '' se não lançar). */
function p2ls_throws(callable $fn): string {
    try { $fn(); } catch (RuntimeException $e) { return $e->getMessage(); }
    return '';
}

// Tudo o que os casos usam cria-se antes das leituras (regra da base de testes). Sem funil de teste para adv: o
// Adverts real (service_code adv) não é v2, por isso adv não tem funil v2 nesta base.
$w = p2v_world($tp, ['vfx', '3d', 'md', 'ed', 'cam']);
$md2 = p2v_funnel($tp, 'md', ['active' => 0]);                           // um 2.º funil v2 de md, inativo
$fVfx1 = (int)p2v_col($tp, "SELECT id FROM funnels WHERE xray_kind = 'vfx' AND is_base = 1 ORDER BY id LIMIT 1");
$fMdReal = (int)p2v_col($tp, "SELECT id FROM funnels WHERE service_code = 'md' AND name NOT LIKE 'P2T%' ORDER BY id LIMIT 1");
t_true($fVfx1 > 0 && $fMdReal > 0, 'a base privada tem o VFX real (v1) e o Motion Design real (v2, inativo, só da v2)');

$lSet      = p2ls_lead($tp, '', 'VFX / 3D');
$lPipe     = p2ls_lead($tp, '', '', 'pipeline');
$lProd     = pipe2_deal($tp, ['phase' => 'production', 'funnel_id' => $w['vfx']]);
$lDone     = pipe2_deal($tp, ['phase' => 'done', 'funnel_id' => $w['vfx']]);
$lArch     = p2ls_lead($tp, 'vfx', '', 'archived');
$lCatPost  = p2ls_lead($tp, 'vfx,3d,ed');
$lCatNone  = p2ls_lead($tp, 'md,3d');
$lCatOld   = p2ls_lead($tp, '', 'VFX / 3D');
$lConv     = p2ls_lead($tp, 'vfx,3d', 'VFX / 3D');
$lConvAdv  = p2ls_lead($tp, 'vfx,3d,adv');
$lConvMd   = p2ls_lead($tp, 'md');
$lConvNone = p2ls_lead($tp, '');
$lDirect   = p2ls_lead($tp, 'vfx,3d');
$lV1       = p2ls_lead($tp, 'vfx,3d', 'VFX / 3D');
$lStrict   = p2ls_lead($tp, '');

// ── ::clean_and_order ───────────────────────────────────────
t_eq(pipe2_codes_clean('3d, vfx ,xx,vfx'), 'vfx,3d', 'clean: CSV com espaços, um código que não existe e um repetido → pela ordem da v2');
t_eq(pipe2_codes_clean(['3d', ' vfx ', 'xx', 'vfx']), 'vfx,3d', 'clean: um array dá o mesmo');
t_eq(pipe2_codes_clean(''), '', "clean: '' → ''");
t_eq(pipe2_codes_clean(null), '', 'clean: null → \'\'');
t_eq(pipe2_codes_clean([]), '', 'clean: [] → \'\'');
t_eq(pipe2_codes_clean('VFX,Blaine,buddy,campanhas'), 'vfx', 'clean: maiúsculas aceites; Blaine, buddy e campanhas não são códigos da v2');
$all = pipe2_codes_clean('cam,adv,ed,cmp,md,3d,vfx');
t_eq($all, 'vfx,3d,md,cmp,ed,adv,cam', 'clean: os 7 pela ordem da v2');
t_eq(strlen($all), 24, 'clean: o máximo tem 24 caracteres (cabe em deals.service_codes, 40)');
t_eq(pipe2_codes_list('3d,vfx'), ['vfx', '3d'], 'list: CSV → códigos pela ordem da v2');
t_eq(pipe2_codes_list(' ed , xx ,ED'), ['ed'], 'list: sem espaços, sem desconhecidos, sem repetidos');
t_eq(pipe2_codes_list(''), [], "list: '' → []");

// ── ::v1_label_map ──────────────────────────────────────────
t_eq(pipe2_v1_service_codes('Marketing Buddy'), ['codes' => ['cam'], 'ambiguous' => false], 'v1: Marketing Buddy → Campanhas, sem dúvida');
t_eq(pipe2_v1_service_codes('VFX / 3D'), ['codes' => ['vfx', '3d'], 'ambiguous' => true], 'v1: «VFX / 3D» → vfx,3d, ambíguo (o admin escolhe)');
t_eq(pipe2_v1_service_codes('Ambos'), ['codes' => ['vfx', '3d', 'cam'], 'ambiguous' => true], 'v1: «Ambos» → vfx,3d,cam, ambíguo');
t_eq(pipe2_v1_service_codes(''), ['codes' => [], 'ambiguous' => false], "v1: '' → nenhum, sem dúvida");
t_eq(pipe2_v1_service_codes('  VFX / 3D '), ['codes' => ['vfx', '3d'], 'ambiguous' => true], 'v1: espaços nas pontas não contam');
t_eq(pipe2_v1_service_codes('Outro serviço'), ['codes' => [], 'ambiguous' => true], 'v1: um rótulo desconhecido fica sem códigos e para o admin escolher');
foreach (LEAD_SERVICES_LIST as $label) {
    t_true(pipe2_v1_service_codes($label)['codes'] !== [], 'v1: o rótulo «' . $label . '» da lista tem códigos');
}

// ── ::site_values ───────────────────────────────────────────
t_eq(pipe2_site_service_codes('vfx'), ['vfx', '3d'], "site: o valor antigo 'vfx' («VFX / 3D») → vfx,3d");
t_eq(pipe2_site_service_codes('marketing'), ['cam'], "site: 'marketing' → cam");
t_eq(pipe2_site_service_codes('both'), ['vfx', '3d', 'cam'], "site: 'both' → vfx,3d,cam");
t_eq(pipe2_site_service_codes(' Both '), ['vfx', '3d', 'cam'], 'site: sem espaços nem maiúsculas');
t_eq(pipe2_site_service_codes(''), [], "site: '' → []");
t_eq(pipe2_site_service_codes('xx'), [], 'site: um valor desconhecido → []');
t_eq(pipe2_site_service_codes('md'), ['md'], 'site: um dos 7 códigos aceita-se tal e qual');
t_eq(pipe2_site_service_codes(' ED '), ['ed'], 'site: o código sem espaços nem maiúsculas');
t_eq(pipe2_site_service_codes('md,vfx'), ['vfx', 'md'], 'site: uma lista de códigos (só o \'vfx\' sozinho é o valor antigo)');
t_eq(pipe2_site_service_codes(['md', '3d']), ['3d', 'md'], 'site: um array (escolha múltipla) junta-se, pela ordem da v2');
t_eq(pipe2_site_service_codes(['vfx']), ['vfx'], "site: no array (formulário novo), 'vfx' é o código VFX");
t_eq(pipe2_site_service_codes(['marketing', 'md']), ['md', 'cam'], 'site: os valores antigos também se juntam no array');
t_eq(pipe2_site_service_codes(['both', 'ed', 'vfx']), ['vfx', '3d', 'ed', 'cam'], 'site: o array junta tudo sem repetir');
t_eq(pipe2_site_service_codes(['md', ['x'], null, 'xx']), ['md'], 'site: valores que não são texto ignoram-se (sem aviso)');
t_eq(pipe2_site_service_codes([]), [], 'site: [] → []');

// ── ::set_on_lead ───────────────────────────────────────────
p2v_as(99, $tp);
t_eq(pipe2_lead_services_set($tp, $lSet, '3d, vfx', 1), 'vfx,3d', 'set: devolve os códigos limpos');
$r = p2ls_row($tp, $lSet);
t_eq([$r['service_codes'], $r['service']], ['vfx,3d', 'VFX / 3D'], 'set: grava deals.service_codes e deals.service fica igual');
t_eq(p2ls_hist($tp, $lSet, 'p2_services'), [['note' => 'Serviços: VFX + 3D', 'user_id' => 1]], 'set: regista p2_services com quem mudou');
t_eq(pipe2_lead_services_set($tp, $lSet, ['vfx', '3d', 'xx'], 1), 'vfx,3d', 'set: os mesmos códigos devolvem o mesmo');
t_eq(count(p2ls_hist($tp, $lSet, 'p2_services')), 1, 'set: sem mudança, sem linha nova no histórico');
t_eq(pipe2_lead_services_set($tp, $lSet, ['md']), 'md', 'set: sem $by');
t_eq(p2ls_hist($tp, $lSet, 'p2_services')[1], ['note' => 'Serviços: Motion Design', 'user_id' => null], 'set: sem $by é do «Sistema» (user_id NULL, mesmo com sessão)');
t_eq(pipe2_lead_services_set($tp, $lSet, 'xx', 1), '', 'set: só códigos desconhecidos → nenhum');
t_eq(p2ls_hist($tp, $lSet, 'p2_services')[2]['note'], 'Serviços: nenhum', 'set: tirar todos regista «nenhum»');
t_eq(p2ls_row($tp, $lSet)['service'], 'VFX / 3D', 'set: deals.service nunca muda');
t_eq(pipe2_lead_services_set($tp, $lPipe, 'cam', 1), 'cam', 'set: no Pipeline também se grava');
t_eq(p2ls_row($tp, $lPipe)['service_codes'], 'cam', 'set: gravado no Pipeline');
t_eq(p2ls_throws(fn() => pipe2_lead_services_set($tp, $lProd, 'vfx,3d', 1)), 'Os serviços mudam-se nas frentes.', 'set: em produção recusa');
t_eq(p2ls_throws(fn() => pipe2_lead_services_set($tp, $lDone, 'vfx,3d', 1)), 'Os serviços mudam-se nas frentes.', 'set: concluído recusa igual');
t_eq(p2ls_throws(fn() => pipe2_lead_services_set($tp, $lArch, 'vfx,3d', 1)), 'Este registo já não está ativo: os serviços não mudam.', 'set: arquivado recusa');
t_eq(p2ls_throws(fn() => pipe2_lead_services_set($tp, 999999999, 'vfx', 1)), 'Lead não encontrada.', 'set: lead que não existe');
foreach ([$lProd, $lDone, $lArch] as $id) {
    t_eq([p2ls_row($tp, $id)['service_codes'], count(p2ls_hist($tp, $id, 'p2_services'))], [$id === $lArch ? 'vfx' : '', 0], 'set: a recusa não grava nem regista (' . $id . ')');
}

// ── ::category_on_lead ──────────────────────────────────────
t_eq(pipe2_lead_category($tp, p2ls_row($tp, $lCatPost)), 'postprod', 'category: vfx,3d,ed → Pós-produção');
t_eq(pipe2_lead_category($tp, p2ls_row($tp, $lCatNone)), '', 'category: md,3d → sem categoria');
t_eq(pipe2_lead_category($tp, ['service_codes' => 'vfx,3d']), 'cgi', 'category: vfx,3d → CGI');
t_eq(pipe2_lead_category($tp, ['service_codes' => 'ed']), '', 'category: um só serviço → sem categoria');
t_eq(pipe2_lead_category($tp, []), '', 'category: sem service_codes → sem categoria');
t_eq(pipe2_lead_category($tp, p2ls_row($tp, $lCatOld)), '', 'category: o rótulo antigo (ambíguo) não dá categoria; só os códigos');
$r = p2ls_row($tp, $lCatPost);
t_eq([$r['category'], $r['category_at'], count(p2ls_hist($tp, $lCatPost, 'p2_category'))], ['', null, 0], 'category: na lead nunca se grava nem regista');

// ── ::funnel_for_code ───────────────────────────────────────
t_eq(pipe2_funnel_for_code($tp, 'vfx'), $w['vfx'], 'funnel: vfx → o funil v2 de teste (o VFX real, com service_code vfx, é da v1)');
t_eq(pipe2_funnel_for_code($tp, '3d'), $w['3d'], 'funnel: 3d → o funil v2 de teste');
t_eq(pipe2_funnel_for_code($tp, 'md'), $w['md'], 'funnel: md → o ativo ganha ao real (inativo, só da v2) e ao 2.º de teste (inativo)');
t_eq(pipe2_funnel_for_code($tp, 'adv'), null, 'funnel: adv → nenhum (o Adverts real não é v2)');
t_eq(pipe2_funnel_for_code($tp, 'xx'), null, 'funnel: código desconhecido → nenhum');
t_eq(pipe2_funnel_for_code($tp, ''), null, "funnel: '' → nenhum");
$tp->prepare('UPDATE funnels SET is_active = 0 WHERE id = ?')->execute([$w['md']]);
t_eq(pipe2_funnel_for_code($tp, 'md'), $w['md'], 'funnel: entre inativos com v2_only 0, o de menor posição');
$tp->prepare('UPDATE funnels SET v2_only = 1 WHERE id = ?')->execute([$w['md']]);
t_eq(pipe2_funnel_for_code($tp, 'md'), $md2, 'funnel: entre inativos, v2_only 0 ganha a v2_only 1');
$posMdReal = (int)p2v_col($tp, 'SELECT position FROM funnels WHERE id = ?', [$fMdReal]);
$tp->prepare('UPDATE funnels SET position = 0 WHERE id = ?')->execute([$fMdReal]);
t_eq(pipe2_funnel_for_code($tp, 'md'), $md2, 'funnel: v2_only vem antes da posição');
$tp->prepare("UPDATE funnel_stages SET retired_at = NOW() WHERE funnel_id IN (?, ?) AND role = 'exec'")->execute([$w['md'], $md2]);
t_eq(pipe2_funnel_for_code($tp, 'md'), $fMdReal, 'funnel: um funil sem Em execução viva deixa de ser v2; fica o real');
$tp->prepare("UPDATE funnel_stages SET retired_at = NULL WHERE funnel_id IN (?, ?) AND role = 'exec'")->execute([$w['md'], $md2]);
$tp->prepare('UPDATE funnels SET is_active = 1, v2_only = 0 WHERE id = ?')->execute([$w['md']]);
$tp->prepare('UPDATE funnels SET position = ? WHERE id = ?')->execute([$posMdReal, $fMdReal]);
t_eq(pipe2_funnel_for_code($tp, 'md'), $w['md'], 'funnel: reposto');

// ── ::convert_creates_planned_lines ─────────────────────────
p2v_as(1, $tp);
$GLOBALS['XSELL_FRESH'] = false;
t_true(!xsell_is_multi($tp, ['id' => $lConv]), 'convert: antes, a lead não é de crosselling (fica na cache)');
$code = lead_convert($tp, lead_get($tp, $lConv), $w['vfx'], 0);
t_true($code !== '', 'convert: a conversão devolve o código');
t_eq(p2ls_lines($tp, $lConv), [[$w['vfx'], 1, 'planned', null], [$w['3d'], 0, 'planned', null]],
    'convert: vfx,3d para o funil vfx → 2 frentes planned, vfx principal, sem sub-etapa');
t_true(!empty($GLOBALS['XSELL_FRESH']), 'convert: XSELL_FRESH ligado');
t_true(xsell_is_multi($tp, ['id' => $lConv]), 'convert: xsell_is_multi passa a verdadeiro (a cache refrescou)');
$r = p2ls_row($tp, $lConv);
$xray = (int)p2v_col($tp, "SELECT id FROM funnel_stages WHERE funnel_id = ? AND kind = 'xray'", [$w['vfx']]);
t_eq([$r['phase'], (int)$r['funnel_id'], (int)$r['stage_id'], $r['service_codes'], $r['service']], ['pipeline', $w['vfx'], $xray, 'vfx,3d', 'VFX / 3D'],
    'convert: no Pipeline, no X-Ray do funil vfx; service_codes e service como estavam');
t_eq([$r['category'], $r['category_at']], ['', null], 'convert: a categoria não se grava antes do Kickoff');
$hist = p2ls_hist($tp, $lConv, 'p2_services');
t_eq(count($hist), 1, 'convert: um p2_services (o serviço acrescentado)');
t_true(str_contains((string)($hist[0]['note'] ?? ''), 'P2T · 3d'), 'convert: o histórico diz que serviço se acrescentou');
t_eq(count(p2ls_hist($tp, $lConv, 'convert')), 1, 'convert: a conversão regista-se uma vez, como hoje');
t_eq(pipe2_lead_convert_lines($tp, lead_get($tp, $lConv), $w['vfx']), 0, 'convert: outra vez não cria nada');
t_eq(count(p2ls_lines($tp, $lConv)), 2, 'convert: continuam 2 frentes');

lead_convert($tp, lead_get($tp, $lConvAdv), $w['vfx'], 0);
t_eq(p2ls_lines($tp, $lConvAdv), [[$w['vfx'], 1, 'planned', null], [$w['3d'], 0, 'planned', null]], 'convert: adv sem funil v2 fica de fora');
lead_convert($tp, lead_get($tp, $lConvMd), $w['vfx'], 0);
t_eq(p2ls_lines($tp, $lConvMd), [[$w['vfx'], 1, 'planned', null], [$w['md'], 0, 'planned', null]],
    'convert: o funil escolhido é a principal mesmo sem o código na lead; md junta-se');
lead_convert($tp, lead_get($tp, $lConvNone), $w['vfx'], 0);
t_eq(p2ls_lines($tp, $lConvNone), [[$w['vfx'], 1, 'planned', null]], 'convert: sem códigos, só a principal (um só serviço também tem frente)');

// a função sozinha, num negócio já no Pipeline de um funil v2: devolve as frentes criadas
$tp->prepare("UPDATE deals SET phase = 'pipeline', funnel_id = ? WHERE id = ?")->execute([$w['3d'], $lDirect]);
t_eq(pipe2_lead_convert_lines($tp, p2ls_row($tp, $lDirect), $w['3d']), 2, 'convert: devolve as criadas (a principal 3d e a vfx)');
t_eq(p2ls_lines($tp, $lDirect), [[$w['3d'], 1, 'planned', null], [$w['vfx'], 0, 'planned', null]], 'convert: a principal é a do funil do negócio');
t_eq(pipe2_lead_convert_lines($tp, p2ls_row($tp, $lDirect), $w['3d']), 0, 'convert: idempotente');

// ── ::v1_convert_unchanged ──────────────────────────────────
lead_convert($tp, lead_get($tp, $lV1), $fVfx1, 0);
$r = p2ls_row($tp, $lV1);
t_eq([$r['phase'], (int)$r['funnel_id'], $r['service_codes'], $r['service']], ['pipeline', $fVfx1, 'vfx,3d', 'VFX / 3D'], 'v1: convertida para o VFX real, como hoje');
t_eq(p2ls_lines($tp, $lV1), [], 'v1: nenhuma deal_lines, como hoje');
t_eq(count(p2ls_hist($tp, $lV1, 'p2_services')), 0, 'v1: nenhum p2_services');
t_eq(pipe2_lead_convert_lines($tp, $r, $fVfx1), 0, 'v1: a função num funil v1 não faz nada');
t_eq(p2ls_lines($tp, $lV1), [], 'v1: continua sem frentes');

// ── ::v1_at_42 (base fresca na 42, sem as colunas da v2) ────
$db42 = pipe2_tmpdb_create(pipe2_base_dump());
$p42 = pipe2_tmpdb_pdo($db42);
$lead42 = (int)p2v_col($p42, "SELECT id FROM deals WHERE phase = 'lead' ORDER BY id LIMIT 1");
$f42 = (int)p2v_col($p42, "SELECT id FROM funnels WHERE xray_kind = 'vfx' ORDER BY id LIMIT 1");
t_true($lead42 > 0 && $f42 > 0, 'v1_at_42: a base na 42 tem uma lead e o VFX');
$before = p2v_sig($p42, ['deals', 'deal_lines', 'deal_history']);
$e42 = '';
try {
    t_eq(pipe2_lead_convert_lines($p42, p2ls_row($p42, $lead42), $f42), 0, 'v1_at_42: sem o esquema da v2, não cria frentes');
    t_eq(pipe2_lead_category($p42, ['service_codes' => 'vfx,3d']), '', 'v1_at_42: sem o esquema, sem categoria');
    t_eq(pipe2_funnel_for_code($p42, 'vfx'), null, 'v1_at_42: sem o esquema, nenhum funil v2');
} catch (Throwable $e) {
    $e42 = $e->getMessage();
}
t_eq($e42, '', 'v1_at_42: sem exceção');
t_eq(p2v_sig($p42, ['deals', 'deal_lines', 'deal_history']), $before, 'v1_at_42: nada mudou na base');

// ── ::strict ────────────────────────────────────────────────
pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
$eS = '';
try {
    t_eq(pipe2_funnel_for_code($tp, 'ed'), $w['ed'], 'strict: o funil v2 de ed em ONLY_FULL_GROUP_BY');
    t_eq(pipe2_lead_services_set($tp, $lStrict, 'cam,adv,ed,cmp,md,3d,vfx', 1), 'vfx,3d,md,cmp,ed,adv,cam', 'strict: os 7 gravam-se em STRICT_ALL_TABLES');
} catch (Throwable $e) {
    $eS = $e->getMessage();
}
t_eq($eS, '', 'strict: sem erro de SQL');
pipe2_sql_mode($tp, '');

// ── ::lists_untouched ───────────────────────────────────────
t_eq(LEAD_SERVICES_LIST, ['VFX / 3D', 'Marketing Buddy', 'Ambos'], 'lists: LEAD_SERVICES_LIST igual');
$in = lead_input(['service' => 'vfx,3d']);
t_eq($in['service'], '', "lists: lead_input(['service' => 'vfx,3d']) → service '', como hoje");
t_true(!array_key_exists('service_codes', $in), 'lists: o lead_input não ganha service_codes (os formulários não mudam)');
t_eq(lead_input(['service' => 'Ambos'])['service'], 'Ambos', 'lists: os rótulos da v1 continuam aceites');

t_done();
