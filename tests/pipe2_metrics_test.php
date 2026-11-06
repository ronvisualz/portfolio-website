<?php
/**
 * Pipeline v2, fase 1 — métricas v2 (admin/inc/pipe2_metrics.php; tarefa 8: D26, X1, X3) e o gancho do budget_accept.
 *
 * O serviço de um negócio nas métricas é o serviço v2: os códigos do funil atual (cur_fu) e das frentes (sv), nunca o
 * de origem (fu); um funil sem service_code dá os do mapa da v1 de cada xray_kind (um combinado conta em cada serviço).
 * O filtro e as metas pelo serviço, a categoria gravada (mudar as regras não a reescreve), o tempo por sub-etapa (a
 * espera fora, as reentradas somadas), a margem por frente (dado do Financeiro: quem chama decide; na fase 1 ninguém a
 * chama) e os valores das frentes que acompanham o orçamento aceite num negócio v2 em produção (num v1 nada muda).
 *
 * Corre numa base privada do dump da 42, migrada até à SCHEMA_VERSION (p2v_db); o ::funnel_codes_at_42 usa outra,
 * na 42 sem migrar. Não escreve na bancada.
 *
 * Casos: ::funnel_codes ::by_v2_service ::origin_untouched ::filter_or_pe ::goal_service ::category_frozen
 *        ::substep_time ::front_margin ::margin_not_wired ::values_follow_accepted_budget ::strict ::funnel_codes_at_42
 *
 * Uso: php tests/pipe2_metrics_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/metrics.php';
require_once __DIR__ . '/../admin/inc/budget.php';
require_once __DIR__ . '/../admin/inc/pipe2_metrics.php';

/** Os ids de um resultado (lista de registos ou mapa id => registo), por ordem crescente. */
function p2m_ids(array $deals): array {
    $ids = array_map('intval', array_values(array_map(fn($d) => is_array($d) ? $d['id'] : $d, $deals)));
    sort($ids);
    return $ids;
}

/** [valor, ids ordenados] de pipe2_goal_actual (os ids na ordem crescente, para comparar). */
function p2m_goal(array $r): array {
    [$v, $ids] = $r;
    $ids = array_map('intval', $ids);
    sort($ids);
    return [$v, $ids];
}

/**
 * Uma folha de orçamento: $versions = [['price' => €, 'lines' => [[kind, funil|null, preço unitário, qtd, unidade], …]], …].
 * Linhas 'other' a valor fixo (custo = preço) e 'team'/'supplier' à hora (custo = qtd × preço). As versões ficam com a
 * ocupação parada (0), para o budget_accept não recalcular o preço. $accepted = índice da versão aceite (null: nenhuma).
 * Devolve [folha, [ids das versões]].
 */
function p2m_budget(PDO $pdo, int $dealId, array $versions, ?int $accepted = null): array {
    $sheet = pipe2_insert($pdo, 'budget_sheets', ['deal_id' => $dealId]);
    $vids = [];
    foreach (array_values($versions) as $i => $v) {
        $vid = pipe2_insert($pdo, 'budget_versions', ['sheet_id' => $sheet, 'num' => $i + 1, 'occupancy' => 0, 'price' => $v['price'], 'day_hours' => 8]);
        foreach (array_values($v['lines']) as $j => [$kind, $fid, $price, $qty, $unit]) {
            pipe2_insert($pdo, 'budget_lines', ['version_id' => $vid, 'line_key' => 'p2m' . $i . '_' . $j, 'position' => $j, 'kind' => $kind,
                'label' => PIPE2_FUNNEL_PREFIX . 'linha', 'price_unit' => $unit, 'unit_price' => $price, 'qty' => $qty, 'qty_unit' => 'hour',
                'opt' => 'A', 'funnel_id' => $fid]);
        }
        $vids[] = $vid;
    }
    if ($accepted !== null) $pdo->prepare('UPDATE budget_sheets SET accepted_version_id = ? WHERE id = ?')->execute([$vids[$accepted], $sheet]);
    return [$sheet, $vids];
}

/** Um custo lançado na ficha do negócio (sem IVA no valor), com ou sem frente. */
function p2m_cost(PDO $pdo, int $dealId, float $amount, ?int $lineId): int {
    return pipe2_insert($pdo, 'costs', ['deal_id' => $dealId, 'name' => PIPE2_FUNNEL_PREFIX . 'custo', 'amount' => $amount, 'vat_mode' => 'none', 'line_id' => $lineId]);
}

/** Um documento do Financeiro do negócio (o IVA dedutível não conta no custo). */
function p2m_fin(PDO $pdo, int $dealId, ?int $lineId, float $base, array $o = []): int {
    $now = date('Y-m-d H:i:s');
    return pipe2_insert($pdo, 'fin_docs', array_merge(['direction' => 'out', 'doc_type' => 'invoice', 'scope' => 'deal', 'deal_id' => $dealId, 'line_id' => $lineId,
        'party_name' => PIPE2_FUNNEL_PREFIX . 'fornecedor', 'base' => $base, 'vat_rate' => 23, 'vat' => round($base * 0.23, 2), 'vat_deductible' => 1,
        'total' => round($base * 1.23, 2), 'status' => 'to_pay', 'created_at' => $now, 'updated_at' => $now], $o));
}

/** Os valores das frentes de um negócio, pela ordem (texto, como na base). */
function p2m_values(PDO $pdo, int $dealId): array {
    return array_map(fn($r) => $r['value'], p2v_rows($pdo, 'SELECT value FROM deal_lines WHERE deal_id = ? ORDER BY is_main DESC, position, id', [$dealId]));
}

/** Um passo estruturado de teste (stage_events). */
function p2m_event(PDO $pdo, int $dealId, ?int $lineId, string $step, ?int $to, string $at): void {
    pipe2_insert($pdo, 'stage_events', ['deal_id' => $dealId, 'line_id' => $lineId, 'step' => $step, 'to_stage_id' => $to, 'at' => $at, 'source' => 'live']);
}

// ── a base privada; os funis e os negócios todos antes da primeira leitura de mx_stages/metrics_deals ──
[$db, $tp] = p2v_db();
$w = p2v_world($tp, ['vfx', '3d', 'ed', 'cam']);
$fid = fn(string $where) => (int)p2v_col($tp, "SELECT id FROM funnels WHERE $where ORDER BY id LIMIT 1");
$realVfx = $fid("xray_kind = 'vfx' AND is_base = 1");
$real3d  = $fid("xray_kind = 'cgi' AND is_base = 1");
$ads     = $fid("xray_kind = 'ads'");
$adverts = $fid("xray_kind = 'adverts'");
$buddy   = $fid("xray_kind = 'buddy'");
t_true($realVfx > 0 && $real3d > 0 && $ads > 0 && $adverts > 0 && $buddy > 0, 'a base privada tem os funis reais VFX, CGI, Ads, Adverts e Marketing Buddy');
$combo = pipe2_funnel($tp, 'combinado');
$tp->prepare("UPDATE funnels SET xray_kind = 'vfx,cgi', service_code = '' WHERE id = ?")->execute([$combo]);

// X1: serviço v2
$dAds     = pipe2_deal($tp, ['funnel_id' => $adverts, 'origin_funnel_id' => $ads, 'owner_id' => 99]);   // começou em Ads, está em Adverts
$dAdsOnly = pipe2_deal($tp, ['funnel_id' => $ads, 'owner_id' => 1]);                                      // funil v1 sem service_code
$dCombo   = pipe2_deal($tp, ['funnel_id' => $combo, 'owner_id' => 1]);                                    // xray_kind combinado
$dVfx3d   = pipe2_deal($tp, ['funnel_id' => $realVfx, 'owner_id' => 99]);                                 // negócio VFX com uma frente 3D
pipe2_front($tp, $dVfx3d, $realVfx, ['is_main' => 1]);
pipe2_front($tp, $dVfx3d, $real3d, ['is_main' => 0]);
$dOrigin  = pipe2_deal($tp, ['funnel_id' => $real3d, 'origin_funnel_id' => $realVfx, 'owner_id' => 1]);   // começou em VFX, está em 3D
$dLead    = pipe2_deal($tp, ['phase' => 'lead', 'owner_id' => 99]);                                       // lead: sem funil
$tp->prepare("UPDATE deals SET origin = 'site' WHERE id = ?")->execute([$dCombo]);
$x1 = [$dAds, $dAdsOnly, $dCombo, $dVfx3d, $dOrigin, $dLead];

// metas: ganhos hoje (Kickoff) e concluídos (done_at)
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$gA = pipe2_deal($tp, ['funnel_id' => $real3d, 'phase' => 'production', 'budget' => 1000, 'owner_id' => 99]);
$gB = pipe2_deal($tp, ['funnel_id' => $realVfx, 'phase' => 'production', 'budget' => 2000, 'owner_id' => 99]);
$gC = pipe2_deal($tp, ['funnel_id' => $real3d, 'phase' => 'pipeline', 'budget' => 4000, 'owner_id' => 99]);
$gD = pipe2_deal($tp, ['funnel_id' => $realVfx, 'phase' => 'production', 'budget' => 3000, 'owner_id' => 99]);
pipe2_front($tp, $gD, $realVfx, ['is_main' => 1, 'status' => 'active']);
pipe2_front($tp, $gD, $real3d, ['is_main' => 0, 'status' => 'active']);
$gE = pipe2_deal($tp, ['funnel_id' => $real3d, 'phase' => 'done', 'budget' => 1500, 'done_at' => $today . ' 10:00:00', 'owner_id' => 99]);
$gF = pipe2_deal($tp, ['funnel_id' => $real3d, 'phase' => 'done', 'budget' => 2500, 'done_at' => '2026-01-15 10:00:00', 'owner_id' => 99]);
$gG = pipe2_deal($tp, ['funnel_id' => $realVfx, 'phase' => 'done', 'budget' => 700, 'done_at' => $today . ' 11:00:00', 'owner_id' => 1]);
foreach ([$gE, $gF, $gG] as $g) p2m_event($tp, $g, null, 'kickoff', null, '2026-01-10 10:00:00');   // ganhos há meses
$goal = [$gA, $gB, $gC, $gD, $gE, $gF, $gG];

// X3: categoria gravada
$cStored = pipe2_deal($tp, ['funnel_id' => $w['vfx'], 'category' => 'cgi', 'category_at' => date('Y-m-d H:i:s')]);
$cCalc   = pipe2_deal($tp, ['funnel_id' => $w['vfx']]);
$cNone   = pipe2_deal($tp, ['funnel_id' => $w['vfx'], 'category' => '', 'category_at' => date('Y-m-d H:i:s')]);   // congelada sem categoria
$cPost   = pipe2_deal($tp, ['funnel_id' => $w['vfx']]);
foreach ([$cStored, $cCalc, $cNone] as $d) { pipe2_front($tp, $d, $w['vfx'], ['is_main' => 1]); pipe2_front($tp, $d, $w['3d'], ['is_main' => 0]); }
pipe2_front($tp, $cPost, $w['vfx'], ['is_main' => 1]);
pipe2_front($tp, $cPost, $w['ed'], ['is_main' => 0]);
$cats4 = [$cStored, $cCalc, $cNone, $cPost];

$all = metrics_deals($tp, true);
$fc = pipe2_mx_funnel_codes($tp);
$pick = fn(array $ids) => array_intersect_key($all, array_flip($ids));

// ── ::funnel_codes (D26) ────────────────────────────────────
$c = '::funnel_codes';
t_eq($fc[$adverts] ?? null, ['adv'], "$c Adverts → adv (service_code)");
t_eq($fc[$ads] ?? null, ['adv'], "$c Ads (sem service_code, xray_kind ads) → adv pelo mapa da v1");
t_eq($fc[$buddy] ?? null, ['cam'], "$c Marketing Buddy (buddy) → cam");
t_eq($fc[$combo] ?? null, ['vfx', '3d'], "$c combinado vfx,cgi → vfx e 3d, pela ordem da v2");
t_eq($fc[$w['3d']] ?? null, ['3d'], "$c funil de teste 3d → 3d");
t_eq($fc[$realVfx] ?? null, ['vfx'], "$c VFX real → vfx");
t_true(!array_filter($fc, fn($codes) => in_array('blaine', $codes, true)), "$c nunca «blaine»");

// ── ::by_v2_service (X1) ────────────────────────────────────
$c = '::by_v2_service';
$x1d = $pick($x1);
t_eq(pipe2_mx_codes($x1d[$dAds], $fc), ['adv'], "$c começou em Ads, está em Adverts → adv");
t_eq(pipe2_mx_codes($x1d[$dCombo], $fc), ['vfx', '3d'], "$c funil combinado → vfx e 3d");
t_eq(pipe2_mx_codes($x1d[$dVfx3d], $fc), ['vfx', '3d'], "$c negócio VFX com frente 3D → vfx e 3d");
t_eq(pipe2_mx_codes($x1d[$dOrigin], $fc), ['3d'], "$c começou em VFX, está em 3D → só 3d (nunca o de origem)");
t_eq(pipe2_mx_codes($x1d[$dLead], $fc), [], "$c lead sem funil → nenhum");
t_eq(p2m_ids(pipe2_mx_filter($x1d, $fc, [], ['svc' => 'adv'])), p2m_ids([$dAds, $dAdsOnly]), "$c svc adv: Ads→Adverts e o do funil Ads");
t_eq(p2m_ids(pipe2_mx_filter($x1d, $fc, [], ['svc' => 'vfx'])), p2m_ids([$dCombo, $dVfx3d]), "$c svc vfx: o combinado e o VFX (não o que começou em VFX)");
t_eq(p2m_ids(pipe2_mx_filter($x1d, $fc, [], ['svc' => '3d'])), p2m_ids([$dCombo, $dVfx3d, $dOrigin]), "$c svc 3d: combinado, frente 3D e o que está em 3D");
t_eq(p2m_ids(pipe2_mx_filter($x1d, $fc, [], [])), p2m_ids($x1), "$c sem filtros: todos");
t_eq(p2m_ids(pipe2_mx_filter($x1d, $fc, [], ['svc' => 'cmp'])), [], "$c svc sem negócios → nenhum");

// ── ::origin_untouched (X1: o filtro v1 não muda) ───────────
$c = '::origin_untouched';
t_true(!in_array($dOrigin, p2m_ids(pipe2_mx_filter($x1d, $fc, [], ['svc' => 'vfx'])), true), "$c o que começou em VFX não está em svc vfx");
t_true(in_array($dOrigin, p2m_ids(mx_filter($x1d, ['fu' => $realVfx])), true), "$c o mx_filter v1 com fu = VFX continua a mostrá-lo");
t_eq(p2m_ids(mx_filter($x1d, ['fu' => $realVfx])), p2m_ids([$dVfx3d, $dOrigin]), "$c o mx_filter v1 com fu = VFX: os de origem VFX, como antes");

// ── ::filter_or_pe (os outros filtros globais) ──────────────
$c = '::filter_or_pe';
t_eq(p2m_ids(pipe2_mx_filter($x1d, $fc, [], ['or' => 'site'])), [$dCombo], "$c or site: só o da origem «site»");
t_eq(p2m_ids(pipe2_mx_filter($x1d, $fc, [], ['pe' => 99])), p2m_ids([$dAds, $dVfx3d, $dLead]), "$c pe 99: os do comercial 99");
t_eq(p2m_ids(pipe2_mx_filter($x1d, $fc, [], ['pe' => 99, 'svc' => 'vfx'])), [$dVfx3d], "$c pe 99 e svc vfx juntos");

// ── ::goal_service (X1, X2: metas pelo serviço; concluídos por done_at) ──
$c = '::goal_service';
$gd = $pick($goal);
t_eq(p2m_goal(pipe2_goal_actual($gd, $fc, 'kickoffs', 'service', '3d', $today, $tomorrow)), [2, p2m_ids([$gA, $gD])], "$c kickoffs 3d: os ganhos de hoje com 3D (o funil e a frente)");
t_eq(p2m_goal(pipe2_goal_actual($gd, $fc, 'kickoffs', 'company', '', $today, $tomorrow)), [3, p2m_ids([$gA, $gB, $gD])], "$c kickoffs da empresa");
t_eq(p2m_goal(pipe2_goal_actual($gd, $fc, 'valor', 'service', '3d', $today, $tomorrow)), [4000.0, p2m_ids([$gA, $gD])], "$c valor ganho 3d = 1000 + 3000");
t_eq(p2m_goal(pipe2_goal_actual($gd, $fc, 'concluidos', 'service', '3d', $today, $tomorrow)), [1, [$gE]], "$c concluidos 3d: pelo done_at no período (não o de janeiro)");
t_eq(p2m_goal(pipe2_goal_actual($gd, $fc, 'valor_concluido', 'service', '3d', $today, $tomorrow)), [1500.0, [$gE]], "$c valor_concluido 3d = 1500");
t_eq(p2m_goal(pipe2_goal_actual($gd, $fc, 'concluidos', 'company', '', $today, $tomorrow)), [2, p2m_ids([$gE, $gG])], "$c concluidos da empresa");
t_eq(p2m_goal(pipe2_goal_actual($gd, $fc, 'valor_concluido', 'company', '', $today, $tomorrow)), [2200.0, p2m_ids([$gE, $gG])], "$c valor_concluido da empresa = 1500 + 700");
t_eq(p2m_goal(pipe2_goal_actual($gd, $fc, 'concluidos', 'user', '1', $today, $tomorrow)), [1, [$gG]], "$c concluidos da pessoa 1");
t_eq(p2m_goal(pipe2_goal_actual($gd, $fc, 'concluidos', 'service', '3d', '2026-01-01', '2026-02-01')), [1, [$gF]], "$c concluidos 3d em janeiro");
t_eq(pipe2_goal_actual($gd, $fc, 'inventada', 'company', '', $today, $tomorrow), [0, []], "$c métrica desconhecida → [0, []]");
t_eq(p2m_goal(pipe2_goal_actual($gd, $fc, 'kickoffs', 'service', 'blaine', $today, $tomorrow)), [0, []], "$c serviço desconhecido → nenhum");

// ── ::category_frozen (X3) ──────────────────────────────────
$c = '::category_frozen';
$cd = $pick($cats4);
$cats = pipe2_mx_categories($tp);
t_eq(array_intersect_key($cats, array_flip($cats4)), [$cStored => 'cgi', $cNone => ''], "$c pipe2_mx_categories: só as gravadas (com category_at), também a sem categoria");
$rules = pipe2_category_rules($tp);
t_eq(p2m_ids(pipe2_mx_filter($cd, $fc, $cats, ['cat' => 'cgi'], $rules)), p2m_ids([$cStored, $cCalc]), "$c cat cgi: a gravada e a calculada (vfx + 3d); a congelada sem categoria não");
t_eq(p2m_ids(pipe2_mx_filter($cd, $fc, $cats, ['cat' => 'postprod'], $rules)), [$cPost], "$c cat postprod: vfx + ed calculada");
$tp->exec("UPDATE pipe2_categories SET need_min = 3 WHERE code = 'cgi'");   // vfx + 3d deixa de ser CGI
$rules3 = pipe2_category_rules($tp);
t_eq(p2m_ids(pipe2_mx_filter($cd, $fc, $cats, ['cat' => 'cgi'], $rules3)), [$cStored], "$c com as regras mudadas, a gravada continua em cgi e a calculada sai");
$tp->exec("UPDATE pipe2_categories SET need_min = 2 WHERE code = 'cgi'");
t_eq(p2m_ids(pipe2_mx_filter($cd, $fc, $cats, ['cat' => 'cgi', 'svc' => '3d'], $rules)), p2m_ids([$cStored, $cCalc]), "$c cat e svc juntos");

// ── ::substep_time (X3: espera fora, reentradas somadas, o intervalo aberto não conta) ──
$c = '::substep_time';
$sd = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
$so = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$SV = p2v_stages($tp, $w['vfx'])['fronts'];
$S3 = p2v_stages($tp, $w['3d'])['fronts'];
[$lv, $l3] = [$sd['lines']['vfx'], $sd['lines']['3d']];
$day = '2026-10-01 ';
p2m_event($tp, $sd['id'], $lv, 'p2_front', $SV[0], $day . '10:00:00');
p2m_event($tp, $sd['id'], $lv, 'producao', $SV[1], $day . '11:00:00');   // passo da v1 com frente: não conta
p2m_event($tp, $sd['id'], null, 'p2_front', $SV[1], $day . '11:30:00');  // sem frente: não conta
p2m_event($tp, $sd['id'], $lv, 'p2_wait', $SV[0], $day . '12:00:00');
p2m_event($tp, $sd['id'], $lv, 'p2_front', $SV[1], $day . '15:00:00');
p2m_event($tp, $sd['id'], $lv, 'p2_front', $SV[0], $day . '16:00:00');   // «Desfazer»
p2m_event($tp, $sd['id'], $lv, 'p2_front', $SV[1], $day . '17:00:00');   // aberto
p2m_event($tp, $sd['id'], $l3, 'p2_front', $S3[0], $day . '09:00:00');
p2m_event($tp, $sd['id'], $l3, 'p2_front', $S3[1], $day . '10:30:00');   // aberto
p2m_event($tp, $so['id'], $so['lines']['vfx'], 'p2_front', $SV[0], '2026-09-15 08:00:00');
p2m_event($tp, $so['id'], $so['lines']['vfx'], 'p2_front', $SV[1], '2026-09-15 09:00:00');
[$nV, $n3] = [PIPE2_SEED_FRONTS['vfx'][0], PIPE2_SEED_FRONTS['3d'][0]];
$row = fn(string $code, int $sid, string $name, int $n, int $secs, int $avg) => ['code' => $code, 'stage_id' => $sid, 'stage' => $name, 'n' => $n, 'secs' => $secs, 'avg' => $avg];
$want = [
    $row('vfx', $SV[0], $nV[0], 2, 10800, 5400),   // 10:00–12:00 + 16:00–17:00
    $row('vfx', $SV[1], $nV[1], 1, 3600, 3600),    // 15:00–16:00; desde as 17:00 fica aberto
    $row('3d', $S3[0], $n3[0], 1, 5400, 5400),     // 09:00–10:30
];
t_eq(pipe2_substep_times($tp, ['deal_ids' => [$sd['id']]]), $want, "$c S1 = 2 h + 1 h em 2 entradas, a espera fora, S2 = 1 h, por serviço → sub-etapa");
t_eq(pipe2_substep_times($tp, ['from' => '2026-10-01', 'to' => '2026-10-02']), $want, "$c por período (pelo fim do intervalo): o de setembro fica fora");
t_eq(pipe2_substep_times($tp, ['deal_ids' => [$sd['id'], $so['id']]])[0], $row('vfx', $SV[0], $nV[0], 3, 14400, 4800), "$c dois negócios somam na mesma sub-etapa");
t_eq(pipe2_substep_times($tp, ['deal_ids' => [$sd['id']], 'from' => '2026-10-01 15:30:00', 'to' => '2026-10-02']),
    [$row('vfx', $SV[0], $nV[0], 1, 3600, 3600), $row('vfx', $SV[1], $nV[1], 1, 3600, 3600)], "$c conta o intervalo que acaba no período (16:00 e 17:00), não o das 12:00");
t_eq(pipe2_substep_times($tp, ['deal_ids' => [$sd['id']], 'from' => '2026-11-01', 'to' => '2026-12-01']), [], "$c período sem intervalos fechados → []");
t_eq(pipe2_substep_times($tp, ['deal_ids' => []]), [], "$c deal_ids vazio → []");

// ── ::front_margin (X3) ─────────────────────────────────────
$c = '::front_margin';
$m = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'budget' => 4000]);
[$mv, $m3] = [$m['lines']['vfx'], $m['lines']['3d']];
p2v_front($tp, $mv, ['value' => 3000]);
p2v_front($tp, $m3, ['value' => 1000]);
p2m_cost($tp, $m['id'], 200, $mv);
p2m_cost($tp, $m['id'], 100, null);
p2m_budget($tp, $m['id'], [
    ['price' => 4000, 'lines' => [['team', $w['vfx'], 40, 10, 'hour'], ['supplier', $w['vfx'], 999, 1, 'hour'], ['other', $w['3d'], 50, 1, 'fixed']]],
    ['price' => 9000, 'lines' => [['team', $w['vfx'], 500, 10, 'hour']]],   // não aceite: não conta
], 0);
$fm = fn(float $v, float $c, float $t, float $s, float $mg) => ['value' => $v, 'costs' => $c, 'team' => $t, 'shared' => $s, 'margin' => $mg];
t_eq(pipe2_front_margin($tp, $m['id']), [$mv => $fm(3000, 200, 400, 75, 2325), $m3 => $fm(1000, 0, 0, 25, 975)],
    "$c vfx: custo 200, equipa 400, 75 dos 100 sem frente → 2325; 3d: 25 → 975");
// uma só frente: a equipa sem serviço é dela; documentos do Financeiro (anulados, apagados, a receber e em revisão não contam)
$m1 = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec', 'budget' => 2000]);
$m1v = $m1['lines']['vfx'];
p2v_front($tp, $m1v, ['value' => 2000]);
p2m_budget($tp, $m1['id'], [['price' => 2000, 'lines' => [['team', null, 30, 10, 'hour']]]], 0);
p2m_fin($tp, $m1['id'], $m1v, 100);
p2m_fin($tp, $m1['id'], $m1v, 999, ['status' => 'void']);
p2m_fin($tp, $m1['id'], $m1v, 999, ['deleted_at' => date('Y-m-d H:i:s')]);
p2m_fin($tp, $m1['id'], $m1v, 5000, ['direction' => 'in']);
p2m_fin($tp, $m1['id'], $m1v, 777, ['status' => 'review']);
p2m_cost($tp, $m1['id'], 50, null);
t_eq(pipe2_front_margin($tp, $m1['id']), [$m1v => $fm(2000, 100, 300, 50, 1550)], "$c uma frente: equipa sem serviço 300, documento 100 (só a base), 50 sem frente → 1550");
// 2+ frentes: a equipa sem serviço (ou de um serviço que não é frente) reparte-se pelo valor
$m2 = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'budget' => 1000]);
p2v_front($tp, $m2['lines']['vfx'], ['value' => 600]);
p2v_front($tp, $m2['lines']['3d'], ['value' => 400]);
p2m_budget($tp, $m2['id'], [['price' => 1000, 'lines' => [['team', null, 20, 10, 'hour'], ['team', $w['cam'], 10, 10, 'hour']]]], 0);
t_eq(pipe2_front_margin($tp, $m2['id']), [$m2['lines']['vfx'] => $fm(600, 0, 0, 180, 420), $m2['lines']['3d'] => $fm(400, 0, 0, 120, 280)],
    "$c 2 frentes: equipa sem serviço 200 e de um serviço sem frente 100 → 300 repartidos 60/40");
// frentes sem valor: o que não tem frente fica na principal
$m0 = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec']);
p2m_cost($tp, $m0['id'], 90, null);
t_eq(pipe2_front_margin($tp, $m0['id']), [$m0['lines']['vfx'] => $fm(0, 0, 0, 90, -90), $m0['lines']['3d'] => $fm(0, 0, 0, 0, 0)], "$c sem valores: o não atribuído fica na principal");
t_eq(pipe2_front_margin($tp, 0), [], "$c negócio inexistente → []");

// ── ::margin_not_wired (a margem é do Financeiro: na fase 1 nenhum ecrã a chama) ──
$c = '::margin_not_wired';
$callers = [];
foreach (array_merge(glob(dirname(__DIR__) . '/admin/*.php') ?: [], glob(dirname(__DIR__) . '/admin/inc/*.php') ?: []) as $f) {
    if (basename($f) === 'pipe2_metrics.php') continue;
    if (preg_match('/\bpipe2_front_margin\s*\(/', (string)file_get_contents($f))) $callers[] = basename($f);
}
t_eq($callers, [], "$c nenhum ficheiro fora de pipe2_metrics.php chama pipe2_front_margin");

// ── ::values_follow_accepted_budget (X3: budget_accept) ─────
$c = '::values_follow_accepted_budget';
$bv = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'exec', 'budget' => 0, 'value_estimate' => 4000]);
[$bSheet, $bVer] = p2m_budget($tp, $bv['id'], [
    ['price' => 4000, 'lines' => [['other', $w['vfx'], 300, 1, 'fixed'], ['other', $w['3d'], 100, 1, 'fixed']]],
    ['price' => 8000, 'lines' => [['other', $w['vfx'], 100, 1, 'fixed'], ['other', $w['3d'], 300, 1, 'fixed']]],
], 0);
pipe2_store_values($tp, deal_get($tp, $bv['id']));
t_eq(p2m_values($tp, $bv['id']), ['3000.00', '1000.00'], "$c antes: 4000 repartidos 300/100");
budget_accept($tp, budget_sheet_by_id($tp, $bSheet), budget_version_get($tp, $bVer[1]));
t_eq((string)p2v_col($tp, 'SELECT value_estimate FROM deals WHERE id = ?', [$bv['id']]), '8000.00', "$c o valor do negócio passa ao preço da versão aceite");
t_eq(p2m_values($tp, $bv['id']), ['2000.00', '6000.00'], "$c as frentes acompanham: 8000 repartidos 100/300");
// v2 ainda no Pipeline: os valores das frentes guardam-se no Kickoff, não aqui
$bp = p2v_deal($tp, $w, ['vfx', '3d'], ['at' => 'proposal', 'budget' => 0, 'value_estimate' => 4000]);
p2v_front($tp, $bp['lines']['vfx'], ['value' => 5]);
p2v_front($tp, $bp['lines']['3d'], ['value' => 6]);
[$pSheet, $pVer] = p2m_budget($tp, $bp['id'], [['price' => 8000, 'lines' => [['other', $w['vfx'], 100, 1, 'fixed'], ['other', $w['3d'], 300, 1, 'fixed']]]]);
budget_accept($tp, budget_sheet_by_id($tp, $pSheet), budget_version_get($tp, $pVer[0]));
t_eq(p2m_values($tp, $bp['id']), ['5.00', '6.00'], "$c v2 no Pipeline: as frentes não mudam");
// v1 em produção (::v1_noop): nenhuma deal_lines.value muda; o resto do budget_accept é o de sempre
$b1 = pipe2_deal($tp, ['funnel_id' => $realVfx, 'phase' => 'production', 'budget' => 0, 'value_estimate' => 4000]);
pipe2_front($tp, $b1, $realVfx, ['is_main' => 1, 'status' => 'active', 'value' => 222]);
pipe2_front($tp, $b1, $real3d, ['is_main' => 0, 'status' => 'active', 'value' => 111]);
[$vSheet, $vVer] = p2m_budget($tp, $b1, [['price' => 8000, 'lines' => [['other', $realVfx, 100, 1, 'fixed'], ['other', $real3d, 300, 1, 'fixed']]]]);
$sig = p2v_sig($tp, ['deal_lines'], 'deal_id = ' . $b1);
budget_accept($tp, budget_sheet_by_id($tp, $vSheet), budget_version_get($tp, $vVer[0]));
t_eq(p2m_values($tp, $b1), ['222.00', '111.00'], "$c v1: os valores das frentes ficam");
t_eq(p2v_sig($tp, ['deal_lines'], 'deal_id = ' . $b1), $sig, "$c v1: deal_lines igual, linha a linha");
t_eq((string)p2v_col($tp, 'SELECT value_estimate FROM deals WHERE id = ?', [$b1]), '8000.00', "$c v1: o valor do negócio muda como antes");
t_eq((int)p2v_col($tp, 'SELECT accepted_version_id FROM budget_sheets WHERE id = ?', [$vSheet]), $vVer[0], "$c v1: a versão fica aceite como antes");

// ── ::strict (ONLY_FULL_GROUP_BY, STRICT_ALL_TABLES) ────────
$c = '::strict';
$before = [pipe2_substep_times($tp, ['deal_ids' => [$sd['id']]]), pipe2_mx_funnel_codes($tp), pipe2_mx_categories($tp),
           pipe2_deal_values($tp, $pick($cats4)), pipe2_front_margin($tp, $m['id'])];
pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
try {
    $after = [pipe2_substep_times($tp, ['deal_ids' => [$sd['id']]]), pipe2_mx_funnel_codes($tp), pipe2_mx_categories($tp),
              pipe2_deal_values($tp, $pick($cats4)), pipe2_front_margin($tp, $m['id'])];
    t_eq($after, $before, "$c as mesmas respostas em modo estrito");
} catch (Throwable $e) {
    t_true(false, "$c sem exceção em modo estrito: " . $e->getMessage());
}
pipe2_sql_mode($tp, '');

// ── ::funnel_codes_at_42 (sem as colunas da v2: pelo mapa da v1, sem exceção) ──
$c = '::funnel_codes_at_42';
$db42 = pipe2_tmpdb_create(pipe2_base_dump());
$p42 = pipe2_tmpdb_pdo($db42);
try {
    $fc42 = pipe2_mx_funnel_codes($p42);
    t_eq([$fc42[$realVfx] ?? null, $fc42[$real3d] ?? null, $fc42[$ads] ?? null], [['vfx'], ['3d'], ['adv']], "$c na 42: VFX, CGI e Ads pelo xray_kind");
    t_eq(pipe2_mx_categories($p42), [], "$c na 42: nenhuma categoria gravada");
} catch (Throwable $e) {
    t_true(false, "$c sem exceção na 42: " . $e->getMessage());
}
pipe2_use($tp);

t_done();
