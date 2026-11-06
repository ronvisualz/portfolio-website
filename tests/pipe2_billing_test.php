<?php
/**
 * Pipeline v2, fase 1 — os leitores da avença (M7; tarefa 12).
 *
 * xsell_is_recurring passa a ler também deal_lines.billing ('monthly'), além do Marketing Buddy (xray_kind 'buddy');
 * flow_payment_default ganha o negócio como 4.º argumento: com deals.billing 'monthly' (só a v2 o grava), as condições
 * da avença (pay_terms_buddy). Sem o 4.º argumento, ou com um negócio v1, tudo fica como antes — também a fotografia
 * pay_default do pipe2_v1_unchanged_test. flow_payment_default_view (orçamento) passa o negócio.
 *
 * Corre só numa base privada (p2v_db).
 *   php tests/pipe2_billing_test.php
 * Casos: ::recurring_reads_column
 */
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/../admin/inc/xsell.php';
require_once __DIR__ . '/../admin/inc/pipeline_flow.php';
require_once __DIR__ . '/../admin/inc/budget_view.php';

[$db, $tp] = p2v_db();

// condições distintas, para cada ramo se distinguir dos outros
$terms = ['pay_terms_buddy' => 'P2T · mensal adiantado', 'pay_terms_default' => 'P2T · 50/50', 'pay_terms_large' => 'P2T · 40/40/20', 'pay_terms_threshold' => '10000'];
$put = $tp->prepare('REPLACE INTO settings (skey, svalue) VALUES (?, ?)');
foreach ($terms as $k => $v) $put->execute([$k, $v]);
pipe2_use($tp);

$w = p2v_world($tp, ['cam', 'vfx']);
$dm = p2v_deal($tp, $w, ['vfx'], ['at' => 'kickoff', 'billing' => ['vfx' => [500, 12]]]);   // avença num funil que não é Buddy
$dp = p2v_deal($tp, $w, ['vfx'], ['at' => 'kickoff']);                                      // pontual, no mesmo funil
$monthly = deal_get($tp, $dm['id']);
$oneoff = deal_get($tp, $dp['id']);

/** A regra de antes (fase 0), escrita aqui: Buddy → mensal; acima do limite → large; senão default. */
function p2b_old(PDO $pdo, float $price, ?int $fid): string {
    $kind = $fid ? (string)p2v_col($pdo, 'SELECT xray_kind FROM funnels WHERE id = ?', [$fid]) : '';
    if ($kind === 'buddy') return (string)setting($pdo, 'pay_terms_buddy', '');
    return $price > (float)setting($pdo, 'pay_terms_threshold', 10000) ? (string)setting($pdo, 'pay_terms_large', '') : (string)setting($pdo, 'pay_terms_default', '');
}

// ── ::recurring_reads_column (M7) ───────────────────────────
$c = '::recurring_reads_column';
t_eq(xsell_is_recurring(['xray_kind' => 'buddy']), true, "$c Marketing Buddy (xray_kind) → avença, como antes");
t_eq(xsell_is_recurring(['xray_kind' => 'vfx', 'billing' => 'monthly']), true, "$c deal_lines.billing 'monthly' → avença");
t_eq(xsell_is_recurring(['xray_kind' => 'vfx', 'billing' => '']), false, "$c outra frente → não é avença");
t_eq(xsell_is_recurring([]), false, "$c sem dados → não é avença");
$line = p2v_rows($tp, 'SELECT l.*, f.xray_kind FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id WHERE l.id = ?', [$dm['lines']['vfx']])[0];
t_eq(xsell_is_recurring($line), true, "$c a linha gravada de uma frente avença (billing da base)");

$fids = array_map('intval', $tp->query("SELECT id FROM funnels WHERE CAST(name AS BINARY) NOT LIKE 'P2T%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
t_true(count($fids) >= 6, "$c premissa: os funis da base (pelo menos os 6) — obtidos " . count($fids));
t_true(in_array('buddy', array_map(fn($f) => (string)p2v_col($tp, 'SELECT xray_kind FROM funnels WHERE id = ?', [$f]), $fids), true), "$c premissa: um deles é o Marketing Buddy");
$same3 = $same4 = true;
$bad = [];
foreach ($fids as $fid) {
    foreach ([1000.0, 25000.0] as $price) {
        $want = p2b_old($tp, $price, $fid);
        if (flow_payment_default($tp, $price, $fid) !== $want) { $same3 = false; $bad[] = "3 args, funil $fid, $price"; }
        if (flow_payment_default($tp, $price, $fid, ['billing' => ''] + $oneoff) !== $want) { $same4 = false; $bad[] = "negócio v1, funil $fid, $price"; }
    }
}
t_true($same3, "$c sem o 4.º argumento, igual ao de antes em todos os funis — diferenças: " . implode('; ', $bad));
t_true($same4, "$c com um negócio pontual, igual ao de antes em todos os funis");
t_eq(flow_payment_default($tp, 1000.0, null), p2b_old($tp, 1000.0, null), "$c sem funil, igual ao de antes");
t_eq(flow_payment_default($tp, 1000.0, (int)$w['vfx'], $monthly), 'P2T · mensal adiantado', "$c negócio com billing 'monthly' → pay_terms_buddy");
t_eq(flow_payment_default($tp, 25000.0, (int)$w['vfx'], $monthly), 'P2T · mensal adiantado', "$c também acima do limite");
t_eq(flow_payment_default($tp, 1000.0, (int)$w['vfx'], $oneoff), 'P2T · 50/50', "$c negócio pontual no mesmo funil → por defeito");

$v = ['price' => 1000, 'price_b' => 0];
t_eq(flow_payment_default_view($tp, $v, $monthly), 'P2T · mensal adiantado', "$c flow_payment_default_view passa o negócio (avença)");
t_eq(flow_payment_default_view($tp, $v, $oneoff), 'P2T · 50/50', "$c flow_payment_default_view num negócio pontual, como antes");

t_done();
