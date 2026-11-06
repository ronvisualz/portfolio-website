<?php
/**
 * Pipeline v2, fase 1 — «Eliminar etapa» em funis.php recusa uma etapa com frentes ou tarefas (D21, M6).
 *
 * Além dos negócios («Há negócios nesta etapa: mova-os primeiro.»), del_stage recusa uma etapa com frentes
 * (deal_lines.stage_id) ou tarefas (tasks.stage_id, qualquer estado) com «Há frentes ou tarefas nesta etapa: mova-as
 * ou apague-as primeiro.»; sem nada, apaga como antes.
 *
 * Corre na bancada (HTTP em 127.0.0.1:8765, uid 1) com pipe2_lock. REGRA DOS DADOS (plano da fase 0): só há POST para o
 * funil descartável deste teste («P2T · guarda») e as suas etapas; p2g_post recusa, antes de enviar, qualquer outro
 * funnel_id ou stage_id. A assinatura da bancada fora dos funis «P2T · » confere-se no fim, depois da limpeza.
 *
 * Casos: ::front_blocks ::task_blocks ::free_deletes ::deal_blocks ::bench_untouched
 *
 * Uso: php tests/pipe2_stage_guard_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2.php';
pipe2_lock($pdo);
pipe2_cleanup($pdo);   // sobras «P2T» de uma corrida interrompida

const P2G_BLOCK = 'Há frentes ou tarefas nesta etapa: mova-as ou apague-as primeiro.';
const P2G_DEALS = 'Há negócios nesta etapa: mova-os primeiro.';

function p2g_col(string $sql, array $a = []) {
    $st = $GLOBALS['pdo']->prepare($sql);
    $st->execute($a);
    return $st->fetchColumn();
}

/** A assinatura da bancada fora dos funis «P2T · »: funis, etapas e regras. */
function p2g_sig(PDO $pdo): array {
    $not = "CAST(f.name AS BINARY) NOT LIKE CAST('P2T · %' AS BINARY)";
    $f = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|', f.id, f.name, f.is_active, f.position, f.v2_only, f.service_code))), 0) AS crc FROM funnels f WHERE $not")->fetch();
    $s = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|', s.id, s.funnel_id, s.name, s.kind, s.position, s.role, s.level, s.retired_at))), 0) AS crc
                        FROM funnel_stages s JOIN funnels f ON f.id = s.funnel_id WHERE $not")->fetch();
    $a = $pdo->query("SELECT COUNT(*) FROM stage_automations a JOIN funnel_stages s ON s.id = a.stage_id JOIN funnels f ON f.id = s.funnel_id WHERE $not")->fetchColumn();
    return ['funnels' => [(int)$f['n'], (string)$f['crc']], 'stages' => [(int)$s['n'], (string)$s['crc']], 'rules' => (int)$a];
}

/** Recusa antes de enviar: falha o teste e sai (a limpeza corre no fim do processo). */
function p2g_refuse(string $why): void {
    t_true(false, 'p2g_post recusou antes de enviar: ' . $why);
    echo "FALHOU (p2g_post recusou um POST fora do funil descartável)\n";
    exit(1);
}

/** POST do=del_stage a funis.php, só para o funil descartável; devolve [resposta, html da página seguinte]. */
function p2g_post(int $funnelId, int $stageId): array {
    $gf = (int)($GLOBALS['P2G_FUNNEL'] ?? 0);
    if (!$gf || $funnelId !== $gf) p2g_refuse("funnel_id=$funnelId não é o funil descartável");
    if ((int)p2g_col('SELECT funnel_id FROM funnel_stages WHERE id = ?', [$stageId]) !== $gf) p2g_refuse("stage_id=$stageId não é do funil descartável");
    $r = tests_http('POST', '/admin/funis.php', ['do' => 'del_stage', 'funnel_id' => $funnelId, 'stage_id' => $stageId], 1, true);
    if ($r['code'] >= 500 || $r['code'] === 0) $r = tests_http('POST', '/admin/funis.php', ['do' => 'del_stage', 'funnel_id' => $funnelId, 'stage_id' => $stageId], 1, true);   // uma falha transitória repete-se uma vez
    $loc = (string)($r['headers']['location'] ?? '');
    $page = $loc !== '' ? tests_http('GET', $loc, [], 1)['body'] : '';
    return [$r, $page];
}

/** As mensagens (flash) da página. */
function p2g_flashes(string $html): array {
    preg_match_all('/<div class="flash (\w+)"[^>]*>(.*?)<\/div>/s', $html, $m, PREG_SET_ORDER);
    return array_map(fn($x) => [$x[1], html_entity_decode($x[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')], $m);
}

$stageExists = fn(int $id) => (int)p2g_col('SELECT COUNT(*) FROM funnel_stages WHERE id = ?', [$id]) === 1;
$sig0 = p2g_sig($pdo);

$gf = pipe2_funnel($pdo, 'guarda');
$GLOBALS['P2G_FUNNEL'] = $gf;
$st = pipe2_insert($pdo, 'funnel_stages', ['funnel_id' => $gf, 'name' => 'P2T · produção', 'kind' => 'production', 'position' => 60]);
$st2 = pipe2_insert($pdo, 'funnel_stages', ['funnel_id' => $gf, 'name' => 'P2T · com negócio', 'kind' => 'production', 'position' => 61]);
$deal = pipe2_deal($pdo, ['phase' => 'pipeline', 'funnel_id' => $gf]);

// ── ::front_blocks ──────────────────────────────────────────
$c = '::front_blocks';
$line = pipe2_front($pdo, $deal, $gf, ['stage_id' => $st]);
[$r, $page] = p2g_post($gf, $st);
t_eq($r['code'], 302, "$c o POST redireciona");
t_true($stageExists($st), "$c a etapa com uma frente fica");
t_true(in_array(['err', P2G_BLOCK], p2g_flashes($page), true), "$c mostra «" . P2G_BLOCK . "»");
$pdo->prepare('DELETE FROM deal_lines WHERE id = ?')->execute([$line]);

// ── ::task_blocks ───────────────────────────────────────────
$c = '::task_blocks';
$task = pipe2_task($pdo, $deal, ['stage_id' => $st, 'status' => 'done']);
[$r, $page] = p2g_post($gf, $st);
t_true($stageExists($st), "$c a etapa com uma tarefa (feita) fica");
t_true(in_array(['err', P2G_BLOCK], p2g_flashes($page), true), "$c mostra a mesma mensagem");
$pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$task]);

// ── ::free_deletes ──────────────────────────────────────────
$c = '::free_deletes';
[$r, $page] = p2g_post($gf, $st);
t_true(!$stageExists($st), "$c sem frentes nem tarefas, a etapa apaga-se");
t_true(in_array(['ok', 'Etapa eliminada.'], p2g_flashes($page), true), "$c mostra «Etapa eliminada.»");

// ── ::deal_blocks ───────────────────────────────────────────
$c = '::deal_blocks';
$pdo->prepare('UPDATE deals SET stage_id = ? WHERE id = ?')->execute([$st2, $deal]);
[$r, $page] = p2g_post($gf, $st2);
t_true($stageExists($st2), "$c a etapa com um negócio fica");
t_true(in_array(['err', P2G_DEALS], p2g_flashes($page), true), "$c continua com «" . P2G_DEALS . "»");

// ── ::bench_untouched ───────────────────────────────────────
$c = '::bench_untouched';
pipe2_cleanup($pdo);
t_eq((int)p2g_col("SELECT COUNT(*) FROM funnels WHERE CAST(name AS BINARY) LIKE CAST('P2T · %' AS BINARY)"), 0, "$c a limpeza apagou o funil de teste");
t_eq(p2g_sig($pdo), $sig0, "$c a bancada fora dos funis «P2T · » ficou igual");

t_done();
