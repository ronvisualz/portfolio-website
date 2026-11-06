<?php
/**
 * Pipeline v2, fase 0 — os ecrãs: funis só da v2 escondidos e guardas em funis.php, negocios.php e relatorios.php
 * (M2; D3 e D4; Review Focus 3, 4 e 6).
 *
 * Até ao «Aplicar» (fase 3), os funis v2_only = 1 (Motion Design, Composit, Edição) tratam-se como inexistentes: não
 * aparecem nas listas, o endereço direto (?f=) mostra o primeiro funil visível e os POST de funis.php recusam-nos
 * («Funil não encontrado.»), sem «Ativar funil». As sub-etapas (level 'front') e as etapas retiradas não aparecem em
 * funis.php nem se guardam, movem ou apagam por um POST forjado («Etapa não encontrada.»), e o «Adicionar etapa» conta
 * a posição só com as etapas da v1.
 *
 * Corre na bancada partilhada (topper, já na 43; HTTP em 127.0.0.1:8765, uid 1 salvo indicação). REGRA DOS DADOS
 * (Review Focus 6): contra os funis e as etapas reais (md, cmp, ed, VFX e a 1.ª sub-etapa do VFX) só há GET. Todos os
 * POST passam por p2_post, que recusa — antes de enviar — qualquer funnel_id, from[], from_funnel_id, to_funnel_id ou
 * stage_id fora dos três funis descartáveis do teste ($hf, $gf, $tf) e qualquer nome que não comece por «P2T · ». A
 * assinatura da bancada ($sig0) guarda-se antes de os criar e confere-se no fim, depois da limpeza.
 *
 * Casos: ::hidden_funnel_direct_url ::funis_stage_guard ::add_stage_position ::lists_hide_v2 ::leitor_403
 *        ::bench_untouched
 *
 * Uso: php tests/pipe2_screens_test.php
 */

require_once __DIR__ . '/boot.php';
require_once __DIR__ . '/pipe2_lib.php';
require_once __DIR__ . '/../admin/inc/pipe2.php';
pipe2_lock($pdo);

const P2S_NOT_P2T = "CAST(f.name AS BINARY) NOT LIKE CAST('P2T · %' AS BINARY)";

function p2s_col(string $sql, array $args = []) {
    $st = $GLOBALS['pdo']->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}

/** A 1.ª coluna de todas as linhas, como inteiros e ordenada. */
function p2s_ids(string $sql, array $args = []): array {
    $st = $GLOBALS['pdo']->prepare($sql);
    $st->execute($args);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    sort($ids);
    return $ids;
}

/** Os números capturados por $re no HTML, como inteiros, sem repetir e ordenados. */
function p2s_match_ids(string $re, string $html): array {
    preg_match_all($re, $html, $m);
    $ids = array_values(array_unique(array_map('intval', $m[1])));
    sort($ids);
    return $ids;
}

/** Os valores das opções do <select name="$name">; null se o select não existir. */
function p2s_select_ids(string $html, string $name): ?array {
    if (!preg_match('/<select name="' . preg_quote($name, '/') . '"[^>]*>(.*?)<\/select>/s', $html, $m)) return null;
    return p2s_match_ids('/<option value="(\d+)"/', $m[1]);
}

/** A assinatura da bancada fora dos funis «P2T · »: funis, etapas e regras de automação. */
function p2s_sig(PDO $pdo): array {
    $f = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|', f.id, f.name, f.is_base, f.made_from, f.position, f.is_active,
                             f.xray_kind, f.v2_only, f.service_code))), 0) AS crc
                        FROM funnels f WHERE " . P2S_NOT_P2T)->fetch();
    $s = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(CRC32(CONCAT_WS('|', s.id, s.funnel_id, s.name, s.kind, s.position, s.role, s.level,
                             s.retired_at, s.gate_on, s.rounds_included))), 0) AS crc
                        FROM funnel_stages s JOIN funnels f ON f.id = s.funnel_id WHERE " . P2S_NOT_P2T)->fetch();
    $a = $pdo->query('SELECT COUNT(*) FROM stage_automations a JOIN funnel_stages s ON s.id = a.stage_id JOIN funnels f ON f.id = s.funnel_id
                       WHERE ' . P2S_NOT_P2T)->fetchColumn();
    return ['funnels' => [(int)$f['n'], (string)$f['crc']], 'stages' => [(int)$s['n'], (string)$s['crc']], 'rules' => (int)$a];
}

/** Recusa antes de enviar: falha o teste e sai (a limpeza corre no fim do processo). */
function p2s_refuse(string $why): void {
    t_true(false, 'p2_post recusou antes de enviar: ' . $why);
    echo 'FALHOU (p2_post recusou um POST fora dos funis descartáveis)' . "\n";
    exit(1);
}

/**
 * Um POST a funis.php (uid 1, formulário). Só para os funis descartáveis do teste: recusa um funnel_id, from[],
 * from_funnel_id ou to_funnel_id que não seja um deles, um stage_id que não seja de um deles e um nome sem «P2T · ».
 */
function p2_post(array $data): array {
    $allowed = $GLOBALS['P2S_ALLOWED'] ?? [];
    if (!$allowed) p2s_refuse('ainda não há funis descartáveis');
    foreach (['funnel_id', 'from_funnel_id', 'to_funnel_id'] as $k) {
        if (array_key_exists($k, $data) && !in_array((int)$data[$k], $allowed, true)) p2s_refuse("$k={$data[$k]} não é um funil descartável");
    }
    foreach ((array)($data['from'] ?? []) as $x) {
        if (!in_array((int)$x, $allowed, true)) p2s_refuse("from[]=$x não é um funil descartável");
    }
    if (array_key_exists('stage_id', $data)) {
        $sf = (int)p2s_col('SELECT funnel_id FROM funnel_stages WHERE id = ?', [(int)$data['stage_id']]);
        if (!in_array($sf, $allowed, true)) p2s_refuse("stage_id={$data['stage_id']} não é de um funil descartável");
    }
    if (($data['do'] ?? '') !== 'create' && !array_key_exists('funnel_id', $data)) p2s_refuse('POST sem funnel_id');
    if (array_key_exists('name', $data) && !str_starts_with((string)$data['name'], PIPE2_FUNNEL_PREFIX)) p2s_refuse('nome sem «P2T · »: ' . $data['name']);
    return tests_http('POST', '/admin/funis.php', $data, 1, true);
}

/** O redirecionamento de um POST não leva ao funil $id (?f=$id). */
function p2s_not_to(array $r, int $id): bool {
    return !preg_match('/[?&]f=' . $id . '(?!\d)/', (string)($r['headers']['location'] ?? ''));
}

function p2s_get(string $path, int $uid = 1): array {
    return tests_http('GET', $path, [], $uid);
}

// ── a bancada ───────────────────────────────────────────────

pipe2_cleanup($pdo);   // sobras «P2T» de uma corrida interrompida
$byCode = fn(string $c) => (int)p2s_col('SELECT id FROM funnels WHERE service_code = ? ORDER BY position, id LIMIT 1', [$c]);
$md = $byCode('md'); $cmp = $byCode('cmp'); $ed = $byCode('ed'); $vfx = $byCode('vfx');
$vfront = $vfx ? (int)p2s_col("SELECT id FROM funnel_stages WHERE funnel_id = ? AND level = 'front' ORDER BY position, id LIMIT 1", [$vfx]) : 0;
if (!$md || !$cmp || !$ed || !$vfx || !$vfront) {
    fwrite(STDERR, "FALHA: a bancada não está semeada (faltam md/cmp/ed/vfx ou as sub-etapas do VFX); corra a migração 43 antes.\n");
    exit(1);
}
$seedNames = [$md => 'Motion Design', $cmp => 'Composit', $ed => 'Edição'];
$sig0 = p2s_sig($pdo);

try {
    // os funis descartáveis (os únicos que recebem POST)
    $hf = pipe2_funnel($pdo, 'oculto');
    $pdo->prepare('UPDATE funnels SET v2_only = 1 WHERE id = ?')->execute([$hf]);
    $hfStages = p2s_ids('SELECT id FROM funnel_stages WHERE funnel_id = ?', [$hf]);
    $gf = pipe2_funnel($pdo, 'guarda');
    $gfront = pipe2_insert($pdo, 'funnel_stages', ['funnel_id' => $gf, 'name' => 'P2T · sub-etapa', 'kind' => 'production', 'position' => 110, 'level' => 'front']);
    $gret = pipe2_insert($pdo, 'funnel_stages', ['funnel_id' => $gf, 'name' => 'P2T · retirada', 'kind' => 'production', 'position' => 67, 'level' => 'deal',
                                                 'retired_at' => date('Y-m-d H:i:s')]);
    $tf = pipe2_funnel($pdo, 'add_stage');
    $GLOBALS['P2S_ALLOWED'] = [$hf, $gf, $tf];
    $hidden = [$md, $cmp, $ed, $hf];
    $firstAll = (int)p2s_col('SELECT id FROM funnels WHERE v2_only = 0 ORDER BY is_active DESC, position, name LIMIT 1');
    $firstActive = (int)p2s_col('SELECT id FROM funnels WHERE is_active = 1 ORDER BY position, name LIMIT 1');

    // ── ::hidden_funnel_direct_url (Review Focus 4) ──────────
    $c = '::hidden_funnel_direct_url';
    $list = p2s_get('/admin/funis.php');
    t_eq($list['code'], 200, "$c GET funis.php responde 200");
    $tabs = p2s_match_ids('/href="\/admin\/funis\.php\?f=(\d+)"/', $list['body']);
    t_true(in_array($vfx, $tabs, true), "$c a lista de funis.php tem o VFX (o teste não é vazio)");
    foreach ($hidden as $x) t_true(!in_array($x, $tabs, true), "$c a lista de funis.php não tem o funil $x (só da v2)");

    $pipeFirst = p2s_match_ids('/data-stage="(\d+)"/', p2s_get('/admin/pipeline.php?f=' . $firstActive)['body']);
    t_true($pipeFirst !== [], "$c pipeline.php?f=$firstActive (1.º ativo) tem colunas");
    foreach ($hidden as $x) {
        $xStages = p2s_ids('SELECT id FROM funnel_stages WHERE funnel_id = ?', [$x]);
        foreach (['f', 'edit'] as $q) {
            $page = p2s_get("/admin/funis.php?$q=$x");
            t_eq($page['code'], 200, "$c GET funis.php?$q=$x responde 200");
            $shown = p2s_match_ids('/id="st-(\d+)"/', $page['body']);
            t_eq(array_values(array_intersect($shown, $xStages)), [], "$c funis.php?$q=$x não mostra etapas do funil $x");
            $ren = preg_match('/name="do" value="rename"><input type="hidden" name="funnel_id" value="(\d+)"/', $page['body'], $m) ? (int)$m[1] : 0;
            t_true($ren !== $x, "$c funis.php?$q=$x: o formulário «Guardar funil» não é do funil $x");
            t_eq($ren, $firstAll, "$c funis.php?$q=$x mostra o primeiro funil visível ($firstAll)");
        }
        $pipe = p2s_match_ids('/data-stage="(\d+)"/', p2s_get('/admin/pipeline.php?f=' . $x)['body']);
        t_eq($pipe, $pipeFirst, "$c pipeline.php?f=$x tem as mesmas colunas do 1.º funil ativo");
        t_eq(array_values(array_intersect($pipe, $xStages)), [], "$c pipeline.php?f=$x não tem etapas do funil $x");
    }

    // POST, só no funil descartável $hf
    $r = p2_post(['do' => 'toggle', 'funnel_id' => $hf]);
    $act = (int)p2s_col('SELECT is_active FROM funnels WHERE id = ?', [$hf]);
    t_eq($act, 0, "$c toggle no funil escondido não o ativa");
    if ($act !== 0) $pdo->prepare('UPDATE funnels SET is_active = 0 WHERE id = ?')->execute([$hf]);   // o funil antigo ativou-o: repor já
    t_true(p2s_not_to($r, $hf), "$c toggle no funil escondido não volta ao funil");
    t_true(str_contains(p2s_get('/admin/funis.php')['body'], 'Funil não encontrado.'), "$c toggle no funil escondido: «Funil não encontrado.»");

    $n0 = (int)p2s_col('SELECT COUNT(*) FROM funnel_stages WHERE funnel_id = ?', [$hf]);
    $r = p2_post(['do' => 'add_stage', 'funnel_id' => $hf, 'name' => 'P2T · x', 'kind' => 'production']);
    t_eq((int)p2s_col('SELECT COUNT(*) FROM funnel_stages WHERE funnel_id = ?', [$hf]), $n0, "$c add_stage no funil escondido não cria etapa");
    t_true(p2s_not_to($r, $hf), "$c add_stage no funil escondido não volta ao funil");
    t_true(str_contains(p2s_get('/admin/funis.php')['body'], 'Funil não encontrado.'), "$c add_stage no funil escondido: «Funil não encontrado.»");

    $r = p2_post(['do' => 'rename', 'funnel_id' => $hf, 'name' => 'P2T · renomeado', 'xray_fields' => '']);
    t_eq((string)p2s_col('SELECT name FROM funnels WHERE id = ?', [$hf]), 'P2T · oculto', "$c rename no funil escondido mantém o nome");
    t_true(p2s_not_to($r, $hf), "$c rename no funil escondido não volta ao funil");
    t_true(str_contains(p2s_get('/admin/funis.php')['body'], 'Funil não encontrado.'), "$c rename no funil escondido: «Funil não encontrado.»");

    $r = p2_post(['do' => 'create', 'name' => 'P2T · c', 'from' => [$hf]]);
    $newId = preg_match('/[?&]f=(\d+)/', (string)($r['headers']['location'] ?? ''), $m) ? (int)$m[1] : 0;
    $new = $newId ? funnel_get($pdo, $newId) : null;
    t_true($new !== null && $new['name'] === 'P2T · c', "$c create com from[] escondido cria o funil «P2T · c»");
    t_true($new !== null && !str_contains((string)$new['made_from'], 'P2T'), "$c create com from[] escondido: made_from sem o funil escondido (obtido «" . ($new['made_from'] ?? '') . '»)');

    // ── ::funis_stage_guard (Review Focus 3) ─────────────────
    $c = '::funis_stage_guard';
    $vSubs = p2s_ids("SELECT id FROM funnel_stages WHERE funnel_id = ? AND level = 'front'", [$vfx]);
    $vLive = p2s_ids("SELECT id FROM funnel_stages WHERE funnel_id = ? AND level = 'deal' AND retired_at IS NULL", [$vfx]);
    $page = p2s_get('/admin/funis.php?f=' . $vfx);
    $shown = p2s_match_ids('/id="st-(\d+)"/', $page['body']);
    t_true(in_array($vfront, $vSubs, true), "$c a 1.ª sub-etapa do VFX ($vfront) é front");
    t_eq(array_values(array_intersect($shown, $vSubs)), [], "$c funis.php?f=VFX não mostra as sub-etapas");
    t_eq(array_values(array_intersect($shown, $vLive)), $vLive, "$c funis.php?f=VFX mostra as etapas da v1");
    $shown = p2s_match_ids('/id="st-(\d+)"/', p2s_get('/admin/funis.php?f=' . $gf)['body']);
    t_true(!in_array($gfront, $shown, true), "$c funis.php?f=$gf não mostra a sub-etapa");
    t_true(!in_array($gret, $shown, true), "$c funis.php?f=$gf não mostra a etapa retirada");
    t_true($shown !== [], "$c funis.php?f=$gf mostra as etapas fixas (o teste não é vazio)");

    $notFound = fn() => str_contains(p2s_get('/admin/funis.php?f=' . $gf)['body'], 'Etapa não encontrada.');
    foreach (['sub-etapa' => $gfront, 'retirada' => $gret] as $what => $s) {
        $row0 = stage_get($pdo, $s);
        p2_post(['do' => 'save_stage', 'funnel_id' => $gf, 'stage_id' => $s, 'name' => 'P2T · mudou', 'rounds' => '3']);
        $row = stage_get($pdo, $s);
        t_eq($row['name'] ?? null, $row0['name'], "$c save_stage na $what mantém o nome");
        t_eq($row['rounds_included'] ?? null, $row0['rounds_included'], "$c save_stage na $what mantém as rondas");
        t_true($notFound(), "$c save_stage na $what: «Etapa não encontrada.»");
        foreach (['up', 'down'] as $dir) {
            p2_post(['do' => 'move_stage', 'funnel_id' => $gf, 'stage_id' => $s, 'dir' => $dir]);
            t_eq((int)(stage_get($pdo, $s)['position'] ?? -1), (int)$row0['position'], "$c move_stage $dir na $what mantém a posição");
            t_true($notFound(), "$c move_stage $dir na $what: «Etapa não encontrada.»");
        }
        p2_post(['do' => 'del_stage', 'funnel_id' => $gf, 'stage_id' => $s]);
        t_true(stage_get($pdo, $s) !== null, "$c del_stage na $what deixa a etapa a existir");
        t_true($notFound(), "$c del_stage na $what: «Etapa não encontrada.»");
    }

    // ── ::add_stage_position (M2) ────────────────────────────
    $c = '::add_stage_position';
    pipe2_insert($pdo, 'funnel_stages', ['funnel_id' => $tf, 'name' => 'P2T · sub-etapa', 'kind' => 'production', 'position' => 110, 'level' => 'front']);
    p2_post(['do' => 'add_stage', 'funnel_id' => $tf, 'name' => 'P2T · nova', 'kind' => 'production']);
    $pos = p2s_col("SELECT position FROM funnel_stages WHERE funnel_id = ? AND name = 'P2T · nova' ORDER BY id DESC LIMIT 1", [$tf]);
    t_eq($pos === false ? null : (int)$pos, 60, "$c a etapa nova fica na posição 60 (a sub-etapa em 110 não conta)");

    // ── ::lists_hide_v2 (D3, só GET) ─────────────────────────
    $c = '::lists_hide_v2';
    $visible = p2s_ids('SELECT id FROM funnels WHERE v2_only = 0');   // agora: o create acima juntou o «P2T · c»
    $checkSel = function (string $where, string $html, string $name) use ($c, $hidden, $visible) {
        $ids = p2s_select_ids($html, $name);
        t_true($ids !== null, "$c $where tem o <select name=\"$name\">");
        $ids ??= [];
        t_eq(array_values(array_intersect($ids, $hidden)), [], "$c $where: o select $name não tem os funis só da v2");
        t_eq($ids, $visible, "$c $where: o select $name tem todos os funis com v2_only = 0");
    };
    $neg = p2s_get('/admin/negocios.php');
    t_eq($neg['code'], 200, "$c GET negocios.php responde 200");
    $checkSel('negocios.php', $neg['body'], 'funil');
    $rel = p2s_get('/admin/relatorios.php');
    t_eq($rel['code'], 200, "$c GET relatorios.php responde 200");
    $checkSel('relatorios.php', $rel['body'], 'fu');
    $checkSel('relatorios.php', $rel['body'], 'sv');
    $met = p2s_get('/admin/relatorios.php?tab=metas&gs=funnel');
    t_eq($met['code'], 200, "$c GET relatorios.php?tab=metas&gs=funnel responde 200");
    $checkSel('relatorios.php?tab=metas&gs=funnel', $met['body'], 'gi');

    // ── ::leitor_403 (não muda) ──────────────────────────────
    t_eq(p2s_get('/admin/funis.php', 3)['code'], 403, '::leitor_403 o leitor (uid 3) não entra em funis.php');
} finally {
    // ── ::bench_untouched (Review Focus 6) ───────────────────
    $c = '::bench_untouched';
    pipe2_cleanup($pdo);
    t_eq(p2s_sig($pdo), $sig0, "$c a assinatura de funis, etapas e regras fora dos funis P2T não mudou");
    t_eq((int)$pdo->query("SELECT COUNT(*) FROM funnels WHERE name LIKE 'P2T%'")->fetchColumn(), 0, "$c já não há funis P2T");
    foreach ($seedNames as $id => $name) {
        $f = funnel_get($pdo, $id);
        t_eq([(int)($f['is_active'] ?? -1), (int)($f['v2_only'] ?? -1), $f['name'] ?? null], [0, 1, $name], "$c o funil $name continua inativo, só da v2 e com o nome da semente");
    }
    t_eq((int)$pdo->query("SELECT COUNT(*) FROM funnel_stages WHERE level = 'front'")->fetchColumn(), 42, "$c continuam 42 sub-etapas");
}

t_done();
