<?php
// Pipeline v2, fase 1 — admin/inc/pipe2_notify.php e o gancho em admin/inc/alerts.php (my_alerts): os avisos da v2
// guardados uma vez por chave (nkey) e para um destinatário, juntos ao grupo 'deal' dos alertas, por isso o centro de
// notificações aplica as preferências do Perfil (user_wants(…, 'deal', 'app')). Só os vê quem tem o Pipeline ou os
// Negócios. Numa base sem a tabela (antes do passo 44) a lista é [] sem exceção nem aviso de PHP, e os alertas de uma
// base sem avisos são exatamente os do código da fase 0 (8c581ae).
// Corre em bases privadas (p2v_db: dump da 42 migrado até à SCHEMA_VERSION; frescas na 42 e na 43). As chamadas com
// sessão vão por p2v_probe (um processo por utilizador; a my_grid() e a my_alerts() guardam o 1.º resultado numa cache
// estática); a fase 0 corre numa árvore de 8c581ae (pipe2_tree). Não escreve na bancada.
//   php tests/pipe2_notify_test.php
// Casos: ::v1_noop ::key ::once_per_step ::no_recipient ::invalid ::for_user ::strict ::race ::preferences
//        ::needs_access ::late_table
require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/pipe2_v2_lib.php';
require_once __DIR__ . '/pipe2_tree.php';
require_once __DIR__ . '/../admin/inc/migrate_pipeline2.php';      // migrate_pipeline2_43 (a fresca na 43)
require_once __DIR__ . '/../admin/inc/migrate_pipeline2_44.php';   // migrate_pipeline2_44 (::late_table)
require_once __DIR__ . '/../admin/inc/ui.php';                     // ICONS (os ícones dos avisos existem)
require_once __DIR__ . '/../admin/inc/pipe2_notify.php';

const P2N_PHASE0 = '8c581ae';
const P2N_TREE_FILES = ['tests/boot.php', 'tests/lib.php', 'tests/pipe2_lib.php', 'tests/pipe2_v2_lib.php', 'tests/pipe2_probe.php'];

/** Um aviso de teste (para o 99, kind replan) com a chave calculada pelos campos; $o sobrepõe. */
function p2n_n(int $deal, ?int $line, ?int $stage, array $o = []): array {
    $kind = (string)($o['kind'] ?? 'replan');
    $cycle = (string)($o['cycle_ym'] ?? '');
    return array_merge([
        'deal_id' => $deal, 'line_id' => $line, 'stage_id' => $stage, 'cycle_ym' => $cycle, 'kind' => $kind, 'rule_id' => null,
        'user_id' => 99, 'title' => 'P2T · Replaneado', 'body' => 'As sub-etapas seguintes deslizaram 2 dias úteis.',
        'nkey' => pipe2_notice_key($kind, $deal, $line, $stage, $cycle),
    ], $o);
}

/** As linhas de pipe2_notices de um negócio, pela ordem. */
function p2n_rows(PDO $pdo, int $deal): array {
    return p2v_rows($pdo, 'SELECT * FROM pipe2_notices WHERE deal_id = ? ORDER BY id', [$deal]);
}

/** As chaves de uma lista de alertas, ordenadas. */
function p2n_keys(array $items): array {
    $k = array_map('strval', array_column($items, 'key'));
    sort($k);
    return $k;
}

/** grupo => chaves ordenadas (o resultado de my_alerts). */
function p2n_group_keys($alerts): array {
    $out = [];
    foreach ((array)$alerts as $g => $items) $out[$g] = p2n_keys((array)$items);
    ksort($out);
    return $out;
}

/** As chaves «p2n:» de uma lista de alertas. */
function p2n_only(array $items): array {
    return array_values(array_filter(p2n_keys($items), fn($k) => str_starts_with($k, 'p2n:')));
}

/** Corre $fn e devolve [valor, mensagem da exceção ou null, avisos de PHP]. */
function p2n_quiet(callable $fn): array {
    $warn = [];
    set_error_handler(function (int $no, string $msg) use (&$warn) { $warn[] = $msg; return true; });
    try {
        return [$fn(), null, $warn];
    } catch (Throwable $e) {
        return [null, get_class($e) . ': ' . $e->getMessage(), $warn];
    } finally {
        restore_error_handler();
    }
}

/** p2v_probe com uma repetição numa falha (a bancada é partilhada: uma falha transitória repete-se uma vez). */
function p2n_probe(string $db, int $uid, array $calls): array {
    $r = p2v_probe($db, $uid, $calls);
    if (isset($r['error'])) $r = p2v_probe($db, $uid, $calls);
    return $r;
}

/** O probe na árvore da fase 0 (o mesmo script e a mesma base, com o admin/ de 8c581ae). */
function p2n_probe_tree(string $tree, string $db, int $uid, array $calls): array {
    $f = tempnam(sys_get_temp_dir(), 'p2c');
    file_put_contents($f, json_encode($calls, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    try {
        $r = pipe2_tree_php($tree, 'tests/pipe2_probe.php', ['--db=' . $db, '--uid=' . $uid, '--calls=' . $f]);
        if ($r['code'] !== 0) $r = pipe2_tree_php($tree, 'tests/pipe2_probe.php', ['--db=' . $db, '--uid=' . $uid, '--calls=' . $f]);
    } finally {
        @unlink($f);
    }
    if ($r['code'] === 0 && isset($r['json']['results'])) return $r['json']['results'];
    return ['error' => trim((string)($r['json']['error'] ?? $r['out'] . ' ' . $r['err']))];
}

/** O item de my_alerts esperado para a linha de um aviso (os campos exatos, pela ordem do contrato). */
function p2n_item(array $row, string $icon): array {
    return ['key' => 'p2n:' . (int)$row['id'], 'kind' => 'deal', 'icon' => $icon, 'sev' => 'warn', 'title' => (string)$row['title'],
            'sub' => (string)$row['body'], 'date' => substr((string)$row['created_at'], 0, 10), 'url' => '/admin/negocio.php?id=' . (int)$row['deal_id']];
}

// Tudo o que os casos usam cria-se antes das leituras (regra da base de testes).
[$db, $tp] = p2v_db();
$w = p2v_world($tp, ['vfx']);
$d = p2v_deal($tp, $w, ['vfx'], ['at' => 'exec']);
$deal = (int)$d['id'];
$line = (int)$d['lines']['vfx'];
$fr = p2v_stages($tp, (int)$w['vfx'])['fronts'];
$dGone = (int)p2v_deal($tp, $w, ['vfx'], ['at' => 'exec'])['id'];   // negócio apagado depois do aviso (::for_user)
$dRace = (int)p2v_deal($tp, $w, ['vfx'], ['at' => 'exec'])['id'];
t_eq(count($fr), 6, 'setup: o funil de teste tem 6 sub-etapas');

// ═══ ::v1_noop — sem avisos (e sem a tabela) nada muda ═══════
// 1. a base migrada, ainda sem nenhum aviso
[$v, $e, $warn] = p2n_quiet(fn() => [pipe2_notices_for($tp, 1), pipe2_notices_for($tp, 99)]);
t_eq([$v, $e, $warn], [[[], []], null, []], '::v1_noop base sem avisos → [] para o 1 e para o 99, sem exceção nem aviso de PHP');

// 2. frescas na 42 (pipe2_schema_ready falso) e na 43 (sem a tabela pipe2_notices): [] sem exceção
$db42 = pipe2_tmpdb_create(pipe2_base_dump());
$p42 = pipe2_tmpdb_pdo($db42);
$db43 = pipe2_tmpdb_create(pipe2_base_dump());
$p43 = pipe2_tmpdb_pdo($db43);
migrate_pipeline2_43($p43);
t_true(!db_has_table($p43, 'pipe2_notices') && pipe2_schema_ready($p43), 'setup: a fresca na 43 tem a semente da v2 e não tem a tabela dos avisos');
foreach (['42' => $p42, '43' => $p43] as $at => $p) {
    [$v, $e, $warn] = p2n_quiet(fn() => pipe2_notices_for($p, 1));
    t_eq([$v, $e, $warn], [[], null, []], "::v1_noop fresca na $at: pipe2_notices_for → [] sem exceção nem aviso de PHP");
}

// 3. os alertas com sessão: o gancho não acrescenta nem tira nada (comparado com o código da fase 0, na mesma base)
$calls = [['fn' => 'my_alerts', 'args' => []], ['fn' => 'notifications', 'args' => []]];
foreach ([1, 99, 3] as $uid) p2n_probe($db, $uid, $calls);   // aquece: a relation_tick() da my_alerts pode criar tarefas uma vez
$tree = pipe2_tree(P2N_PHASE0, [], P2N_TREE_FILES, 'notify');
foreach ([1, 99, 3] as $uid) {
    $now = p2n_probe($db, $uid, $calls);
    $old = p2n_probe_tree($tree, $db, $uid, $calls);
    t_true(!isset($now['error']) && !isset($old['error']), "::v1_noop probe do $uid corre nas duas árvores" . (isset($now['error']) ? ' — agora: ' . $now['error'] : '') . (isset($old['error']) ? ' — fase 0: ' . $old['error'] : ''));
    if (isset($now['error']) || isset($old['error'])) continue;
    t_true(is_array($now[0]) && array_key_exists('deal', $now[0]), "::v1_noop my_alerts do $uid tem o grupo 'deal'");
    t_eq(p2n_group_keys($now[0]), p2n_group_keys($old[0]), "::v1_noop my_alerts do $uid: as mesmas chaves, grupo a grupo, que o código da fase 0");
    t_eq(p2n_keys($now[1]), p2n_keys($old[1]), "::v1_noop notifications do $uid: as mesmas chaves que o código da fase 0");
    t_eq(p2n_only($now[1]), [], "::v1_noop notifications do $uid sem nenhuma chave p2n:");
    if ($uid === 1) {
        // as chaves da notifications são as da my_alerts (as que as preferências deixam; no máximo 60)
        $want = [];
        foreach ($now[0] as $g => $items) if (!isset(NOTIF_CATS[$g]) || user_wants($tp, 1, (string)$g, 'app')) $want = array_merge($want, p2n_keys((array)$items));
        sort($want);
        $got = p2n_keys($now[1]);
        if (count($want) <= 60) t_eq($got, $want, '::v1_noop notifications do 1 = as chaves da my_alerts do 1');
        else t_true(count($got) === 60 && !array_diff($got, $want), '::v1_noop notifications do 1: 60 das chaves da my_alerts do 1');
    }
}
// numa base sem a tabela, os alertas com sessão correm (o gancho dá [] sem exceção) e são os da fase 0
foreach (['42' => $db42, '43' => $db43] as $at => $dbx) {
    $now = p2n_probe($dbx, 1, [['fn' => 'my_alerts', 'args' => []]]);
    $old = p2n_probe_tree($tree, $dbx, 1, [['fn' => 'my_alerts', 'args' => []]]);
    t_true(!isset($now['error']), "::v1_noop my_alerts do 1 na fresca na $at corre sem exceção" . (isset($now['error']) ? ' — ' . $now['error'] : ''));
    if (!isset($now['error']) && !isset($old['error'])) t_eq(p2n_group_keys($now[0]), p2n_group_keys($old[0]), "::v1_noop my_alerts do 1 na fresca na $at = fase 0");
}

// ═══ ::key ═══════════════════════════════════════════════════
t_eq(pipe2_notice_key('replan', 12, 34, 56, '2026-11'), 'replan:12:34:56:2026-11', '::key kind:negócio:frente:sub-etapa:ciclo');
t_eq(pipe2_notice_key('stale', 12, null, null), 'stale:12:0:0:', '::key 0 para frente e sub-etapa nulas, ciclo vazio');
t_eq(pipe2_notice_key('rule', 12, 34, 56, '', 78), 'rule:12:34:56::78', '::key com a regra no fim');
t_eq(pipe2_notice_key('rule', 12, 34, 56, '', null), 'rule:12:34:56:', '::key sem regra não leva o último campo');
$long1 = pipe2_notice_key('cycle', 4294967295, 4294967295, 4294967295, str_repeat('x', 80), 4294967295);
$long2 = pipe2_notice_key('cycle', 4294967295, 4294967295, 4294967295, str_repeat('y', 80), 4294967295);
t_true(strlen($long1) <= 64 && strlen($long2) <= 64, '::key nunca passa os 64 caracteres da coluna (' . strlen($long1) . ')');
t_true($long1 !== $long2, '::key duas chaves longas diferentes continuam diferentes');
t_eq(pipe2_notice_key('cycle', 4294967295, 4294967295, 4294967295, str_repeat('x', 80), 4294967295), $long1, '::key a chave longa é sempre a mesma para os mesmos campos');
t_true(str_starts_with($long1, 'cycle:'), '::key a chave longa começa pelo tipo');
t_true(strlen(pipe2_notice_key('replan', 4294967295, 4294967295, 4294967295, '2026-11', 4294967295)) <= 64, '::key ids máximos e um ciclo cabem nos 64');

// ═══ ::once_per_step ═════════════════════════════════════════
$n1 = p2n_n($deal, $line, $fr[0]);
$id1 = pipe2_notice_add($tp, $n1);
t_true(is_int($id1) && $id1 > 0, '::once_per_step o 1.º aviso grava-se e devolve o id');
t_eq(pipe2_notice_add($tp, $n1), null, '::once_per_step a 2.ª com a mesma nkey devolve null');
t_eq(pipe2_notice_add($tp, ['user_id' => 1, 'title' => 'P2T · outro'] + $n1), null, '::once_per_step a mesma nkey para outro destinatário também não grava (um por chave)');
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM pipe2_notices WHERE nkey = ?', [$n1['nkey']]), 1, '::once_per_step 1 linha com a chave');
$r1 = p2n_rows($tp, $deal)[0] ?? [];
t_eq(array_intersect_key($r1, array_flip(['id', 'deal_id', 'line_id', 'stage_id', 'cycle_ym', 'kind', 'rule_id', 'user_id', 'title', 'body', 'nkey'])),
     ['id' => $id1, 'deal_id' => $deal, 'line_id' => $line, 'stage_id' => $fr[0], 'cycle_ym' => '', 'kind' => 'replan', 'rule_id' => null, 'user_id' => 99,
      'title' => 'P2T · Replaneado', 'body' => 'As sub-etapas seguintes deslizaram 2 dias úteis.', 'nkey' => 'replan:' . $deal . ':' . $line . ':' . $fr[0] . ':'],
     '::once_per_step a linha gravada tem os campos dados');
t_true(abs(strtotime((string)($r1['created_at'] ?? '')) - time()) < 120, '::once_per_step created_at é agora');
$id2 = pipe2_notice_add($tp, p2n_n($deal, $line, $fr[1]));
t_true(is_int($id2) && $id2 !== $id1, '::once_per_step outra sub-etapa → nova linha');
$id3 = pipe2_notice_add($tp, p2n_n($deal, $line, $fr[1], ['cycle_ym' => '2026-12']));
t_true(is_int($id3) && !in_array($id3, [$id1, $id2], true), '::once_per_step a mesma sub-etapa noutro ciclo → nova linha');
// sem nkey: a chave calcula-se pelos campos (e conta para a deduplicação)
$nNoKey = p2n_n($deal, $line, $fr[2], ['kind' => 'stale', 'title' => 'P2T · Parada']);
unset($nNoKey['nkey']);
$id4 = pipe2_notice_add($tp, $nNoKey);
t_eq(p2v_col($tp, 'SELECT nkey FROM pipe2_notices WHERE id = ?', [(int)$id4]), 'stale:' . $deal . ':' . $line . ':' . $fr[2] . ':', '::once_per_step sem nkey, grava a de pipe2_notice_key pelos campos');
t_eq(pipe2_notice_add($tp, $nNoKey + ['nkey' => '']), null, '::once_per_step nkey vazia = a calculada: já existe → null');
t_eq(pipe2_notice_add($tp, p2n_n($deal, $line, $fr[2], ['kind' => 'stale'])), null, '::once_per_step e com a chave explícita igual também');
t_eq(count(p2n_rows($tp, $deal)), 4, '::once_per_step 4 avisos no negócio');

// ═══ ::no_recipient ══════════════════════════════════════════
$before = count(p2n_rows($tp, $deal));
t_eq(pipe2_notice_add($tp, p2n_n($deal, $line, $fr[3], ['user_id' => null])), null, '::no_recipient user_id null → null');
t_eq(pipe2_notice_add($tp, p2n_n($deal, $line, $fr[3], ['user_id' => 0])), null, '::no_recipient user_id 0 → null');
$nNoUser = p2n_n($deal, $line, $fr[3]);
unset($nNoUser['user_id']);
t_eq(pipe2_notice_add($tp, $nNoUser), null, '::no_recipient sem user_id → null');
t_eq(count(p2n_rows($tp, $deal)), $before, '::no_recipient nada gravado');
$idNr = pipe2_notice_add($tp, p2n_n($deal, $line, $fr[3]));
t_true(is_int($idNr), '::no_recipient a mesma chave grava-se quando houver destinatário (a tentativa sem ele não a gastou)');

// ═══ ::invalid — o que a coluna cortaria sem avisar recusa-se ═
$before = count(p2n_rows($tp, $deal));
foreach ([
    'kind desconhecido'         => p2n_n($deal, $line, $fr[4], ['kind' => 'xpto', 'nkey' => 'xpto:1']),
    'kind maior do que 10'      => p2n_n($deal, $line, $fr[4], ['kind' => 'replanning_x', 'nkey' => 'k:1']),
    'sem negócio'               => p2n_n(0, $line, $fr[4]),
    'nkey com mais de 64'       => p2n_n($deal, $line, $fr[4], ['nkey' => str_repeat('k', 65)]),
    'ciclo fora de AAAA-MM'     => p2n_n($deal, $line, $fr[4], ['cycle_ym' => 'nov-2026']),
] as $what => $n) {
    [$v, $e] = p2n_quiet(fn() => pipe2_notice_add($tp, $n));
    t_true($v === null && is_string($e) && str_starts_with($e, 'InvalidArgumentException'), "::invalid $what → InvalidArgumentException" . ($e ? '' : ' (devolveu ' . var_export($v, true) . ')'));
}
t_eq(count(p2n_rows($tp, $deal)), $before, '::invalid nada gravado');

// ═══ ::for_user ══════════════════════════════════════════════
$mk = function (int $dl, array $o, string $ago) use ($tp, $line, $fr): array {
    $id = pipe2_notice_add($tp, p2n_n($dl, $line, $fr[5], $o));
    $tp->prepare('UPDATE pipe2_notices SET created_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', strtotime($ago)), (int)$id]);
    return p2v_rows($tp, 'SELECT * FROM pipe2_notices WHERE id = ?', [(int)$id])[0];
};
$tp->exec('DELETE FROM pipe2_notices');   // só os deste caso
$fA = $mk($deal, ['kind' => 'replan', 'cycle_ym' => '2026-01', 'title' => 'P2T · A'], '-2 days');
$fB = $mk($deal, ['kind' => 'stale', 'cycle_ym' => '2026-02', 'title' => 'P2T · B', 'body' => 'Sem movimento há 6 dias.'], '-13 days');
$fC = $mk($deal, ['kind' => 'rule', 'cycle_ym' => '2026-03', 'title' => 'P2T · C'], '-15 days');           // fora dos 14 dias
$fD = $mk($deal, ['kind' => 'dep_met', 'cycle_ym' => '2026-04', 'title' => 'P2T · D', 'user_id' => 1], '-1 day');
$fE = $mk($deal, ['kind' => 'cycle', 'cycle_ym' => '2026-05', 'title' => 'P2T · E', 'user_id' => 1], '-3 hours');
$fG = $mk($dGone, ['kind' => 'rule', 'cycle_ym' => '2026-06', 'title' => 'P2T · G'], '-1 hour');          // negócio apagado a seguir
$tp->prepare('DELETE FROM deals WHERE id = ?')->execute([$dGone]);
t_eq(pipe2_notices_for($tp, 99), [p2n_item($fB, 'pause'), p2n_item($fA, 'clock')],
     '::for_user o 99: só os dele, dos últimos 14 dias, por data, com os campos exatos (e sem o do negócio apagado)');
t_eq(pipe2_notices_for($tp, 1), [p2n_item($fD, 'check'), p2n_item($fE, 'refresh')], '::for_user o 1: só os dele');
t_eq(pipe2_notices_for($tp, 99, 3), [p2n_item($fA, 'clock')], '::for_user $days = 3 → só o de há 2 dias');
t_eq(pipe2_notices_for($tp, 99, 20), [p2n_item($fC, 'bell'), p2n_item($fB, 'pause'), p2n_item($fA, 'clock')], '::for_user $days = 20 → também o de há 15 dias');
t_eq(pipe2_notices_for($tp, 4), [], '::for_user quem não tem avisos → []');
t_eq(pipe2_notices_for($tp, 2), [], '::for_user o superadmin sem avisos → []');
t_eq(pipe2_notices_for($tp, 0), [], '::for_user uid 0 → []');
t_eq(PIPE2_NOTICE_ICONS, ['replan' => 'clock', 'stale' => 'pause', 'dep_met' => 'check', 'rule' => 'bell', 'cycle' => 'refresh'],
     '::for_user um ícone por tipo de aviso (os de pipe2_notices.kind)');
t_eq(array_keys(PIPE2_NOTICE_ICONS), PIPE2_CODES['pipe2_notices.kind'], '::for_user os tipos dos ícones são os de PIPE2_CODES');
foreach (array_keys(PIPE2_NOTICE_ICONS) as $icon) {
    t_true(isset(ICONS[PIPE2_NOTICE_ICONS[$icon]]), "::for_user o ícone de «{$icon}» existe na interface");
}

// ═══ ::strict — em ONLY_FULL_GROUP_BY e STRICT_ALL_TABLES ═════
$mode = (string)p2v_col($tp, 'SELECT @@SESSION.sql_mode');
pipe2_sql_mode($tp, 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES');
[$v, $e] = p2n_quiet(fn() => pipe2_notice_add($tp, p2n_n($deal, $line, $fr[5], ['kind' => 'rule', 'cycle_ym' => '2026-07', 'user_id' => 4,
    'title' => 'P2T · ' . str_repeat('á', 250), 'body' => str_repeat('é', 300)])));
t_true(is_int($v) && $e === null, '::strict um título e um corpo longos gravam-se em modo estrito' . ($e ? ' — ' . $e : ''));
t_eq(array_map('intval', array_values(p2v_rows($tp, 'SELECT CHAR_LENGTH(title) AS t, CHAR_LENGTH(body) AS b FROM pipe2_notices WHERE id = ?', [(int)$v])[0] ?? [])),
     [200, 255], '::strict cortados nas larguras das colunas (200 e 255 caracteres)');
[$v, $e] = p2n_quiet(fn() => count(pipe2_notices_for($tp, 4)));
t_eq([$v, $e], [1, null], '::strict a lista corre em modo estrito');
pipe2_sql_mode($tp, $mode);

// ═══ ::race — vários pedidos ao mesmo tempo com a mesma chave ═══
for ($round = 1; $round <= 3; $round++) {
    $n = p2n_n($dRace, $line, $fr[0], ['kind' => 'stale', 'cycle_ym' => sprintf('2027-%02d', $round), 'title' => 'P2T · corrida ' . $round, 'user_id' => 5]);
    $f = tempnam(sys_get_temp_dir(), 'p2c');
    file_put_contents($f, json_encode([['fn' => 'pipe2_notice_add', 'args' => [$n]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $res = p2v_race(array_fill(0, 4, ['pipe2_probe.php', ['--db=' . $db, '--uid=0', '--calls=' . $f]]));
    @unlink($f);
    $ids = [];
    $errs = [];
    foreach ($res as $r) {
        if ($r['code'] !== 0 || !isset($r['json']['results'])) { $errs[] = trim($r['out'] . ' ' . $r['err']); continue; }
        $ids[] = $r['json']['results'][0];
    }
    t_eq($errs, [], "::race ronda $round: as 4 corridas acabam sem erro");
    t_eq(count(array_filter($ids, 'is_int')), 1, "::race ronda $round: exatamente uma grava (as outras devolvem null)");
    t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM pipe2_notices WHERE nkey = ?', [$n['nkey']]), 1, "::race ronda $round: 1 linha com a chave");
}

// ═══ ::preferences — o grupo 'deal' do Perfil (probe como 99) ══
$keyA = 'p2n:' . (int)$fA['id'];
$keyB = 'p2n:' . (int)$fB['id'];
$r = p2n_probe($db, 99, [['fn' => 'my_alerts', 'args' => []], ['fn' => 'notifications', 'args' => []]]);
t_true(!isset($r['error']), '::preferences o probe do 99 corre' . (isset($r['error']) ? ' — ' . $r['error'] : ''));
if (!isset($r['error'])) {
    $deal99 = array_values(array_filter((array)($r[0]['deal'] ?? []), fn($it) => str_starts_with((string)$it['key'], 'p2n:')));
    t_eq($deal99, [p2n_item($fB, 'pause'), p2n_item($fA, 'clock')], "::preferences my_alerts do 99: os avisos dele no grupo 'deal', com os campos exatos");
    $byKey = array_column((array)$r[1], null, 'key');
    t_true(isset($byKey[$keyA]) && $byKey[$keyA]['unread'] === true, "::preferences notifications do 99 traz $keyA por ler");
    t_eq(array_values(array_diff(p2n_only((array)$r[1]), [$keyA, $keyB])), [], '::preferences nenhum aviso de outra pessoa nem de outro negócio');
}
$prefs = p2v_col($tp, 'SELECT prefs FROM users WHERE id = 99');
$tp->prepare('UPDATE users SET prefs = ? WHERE id = 99')->execute(['{"notif":{"deal":{"app":0}}}']);
$r = p2n_probe($db, 99, [['fn' => 'my_alerts', 'args' => []], ['fn' => 'notifications', 'args' => []]]);
t_true(!isset($r['error']), '::preferences o probe do 99 corre sem «deal» na plataforma' . (isset($r['error']) ? ' — ' . $r['error'] : ''));
if (!isset($r['error'])) {
    t_eq(p2n_only((array)$r[1]), [], "::preferences com notif.deal.app = 0, a notifications do 99 não traz avisos p2n:");
    t_eq(p2n_only((array)($r[0]['deal'] ?? [])), [$keyA, $keyB], '::preferences e a my_alerts continua a tê-los (as preferências são do centro de notificações)');
}
$tp->prepare('UPDATE users SET prefs = ? WHERE id = 99')->execute([$prefs === false ? null : $prefs]);

// ═══ ::needs_access — só com o Pipeline ou os Negócios ════════
$fU3 = $mk($deal, ['kind' => 'rule', 'cycle_ym' => '2026-08', 'title' => 'P2T · para o 3', 'user_id' => 3], '-1 hour');
$key3 = 'p2n:' . (int)$fU3['id'];
$perm3 = function (?int $pipeline, ?int $negocios) use ($tp): void {
    $tp->exec("DELETE FROM permissions WHERE role = '' AND user_id = 3 AND tab IN ('pipeline', 'negocios')");
    $ins = $tp->prepare("INSERT INTO permissions (role, user_id, tab, level, scope) VALUES ('', 3, ?, ?, NULL)");
    if ($pipeline !== null) $ins->execute(['pipeline', $pipeline]);
    if ($negocios !== null) $ins->execute(['negocios', $negocios]);
};
foreach ([
    [PERM_VIEW, PERM_NONE, [$key3], 'só o Pipeline (ver)'],
    [PERM_NONE, PERM_VIEW, [$key3], 'só os Negócios (ver)'],
    [PERM_NONE, PERM_NONE, [], 'sem o Pipeline nem os Negócios'],
] as [$pl, $ng, $want, $what]) {
    $perm3($pl, $ng);
    $r = p2n_probe($db, 3, [['fn' => 'can', 'args' => ['pipeline']], ['fn' => 'can', 'args' => ['negocios']], ['fn' => 'my_alerts', 'args' => []]]);
    t_true(!isset($r['error']), "::needs_access o probe do 3 corre ($what)" . (isset($r['error']) ? ' — ' . $r['error'] : ''));
    if (isset($r['error'])) continue;
    t_eq([$r[0], $r[1]], [$pl >= PERM_VIEW, $ng >= PERM_VIEW], "::needs_access o 3 tem as permissões do caso ($what)");
    t_eq(p2n_only((array)($r[2]['deal'] ?? [])), $want, "::needs_access $what → " . ($want ? 'vê o aviso' : 'não vê o aviso'));
}

// ═══ ::late_table — o «sem a tabela» não fica guardado ═══════
// A mesma ligação que viu a 43 sem a tabela passa a vê-la depois do passo 44 (o static só guarda o verdadeiro).
$dLate = pipe2_deal($p43, ['phase' => 'pipeline']);
[$v, $e, $warn] = p2n_quiet(fn() => [pipe2_notice_add($p43, p2n_n($dLate, null, null)), pipe2_notices_for($p43, 99)]);
t_eq([$v, $e, $warn], [[null, []], null, []], '::late_table na 43, pipe2_notice_add → null e a lista → [], sem exceção nem aviso');
migrate_pipeline2_44($p43);
$idLate = pipe2_notice_add($p43, p2n_n($dLate, null, null));
t_true(is_int($idLate), '::late_table depois do passo 44, a mesma ligação grava o aviso');
$late = p2v_rows($p43, 'SELECT * FROM pipe2_notices WHERE id = ?', [(int)$idLate])[0] ?? null;
t_eq(pipe2_notices_for($p43, 99), $late ? [p2n_item($late, 'clock')] : ['(sem linha)'], '::late_table e a lista mostra-o');

pipe2_cleanup($tp);
t_eq((int)p2v_col($tp, 'SELECT COUNT(*) FROM pipe2_notices WHERE deal_id IN (?, ?)', [$deal, $dRace]), 0, 'limpeza: os avisos dos negócios P2T saem com eles');
t_done();
