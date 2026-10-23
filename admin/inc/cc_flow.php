<?php
/**
 * topper. admin — o Centro Criativo ligado ao negócio (§23.2, §23.3)
 *
 *  · Pré-criativo: nasce sozinho ao validar o X-Ray — o quadro «Pré-criativo · <negócio>» com o
 *    brief do X-Ray e duas faixas (Opção A · Pedido, Opção B · Proposta de valor) e a tarefa
 *    «Pré-criativo · <cliente>» para o responsável de execução (prazo: Definições, +2 d.u.).
 *    Crosselling (§22): um sub-quadro por frente, cada um com as suas faixas.
 *  · «Enviar para a Orçamentação»: a página da faixa A liga-se à Opção A e a da B à Opção B da
 *    versão atual do orçamento («Serve as duas» liga às duas). Entram no PDF da proposta e na
 *    página de resposta do cliente. Conclui a tarefa do pré-criativo.
 *  · Negócio perdido → pré-criativo arquivado; reaberto → volta.
 *  · Kickoff → o quadro do projeto, com os sub-quadros do modelo do funil; a opção escolhida
 *    pelo cliente passa a «Conceito», a outra fica como «Rota não escolhida». Crosselling: um
 *    sub-quadro por frente, com o seu responsável de execução.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/cc.php';
require_once __DIR__ . '/pipeline_flow.php';

/** O pré-criativo é obrigatório, recomendado ou desligado neste funil (Definições › Centro Criativo). */
function cc_pre_mode(PDO $pdo, ?int $funnelId): string {
    if (!$funnelId || !cc_ready($pdo)) return 'off';
    $st = $pdo->prepare('SELECT cc_pre_mode FROM funnels WHERE id = ?');
    $st->execute([$funnelId]);
    $m = (string)$st->fetchColumn();
    return in_array($m, ['required', 'recommended', 'off'], true) ? $m : 'recommended';
}

/** O texto do brief a partir do X-Ray (sem preços nem pontuações internas). */
function cc_xray_brief(PDO $pdo, array $deal): string {
    require_once __DIR__ . '/pipeline_flow.php';
    $x = xray_data($deal);
    $out = [];
    foreach (['objetivo' => 'Objetivo', 'entregaveis' => 'Entregáveis', 'formatos' => 'Formatos e canais', 'prazo' => 'Prazo',
              'referencias' => 'Referências do cliente', 'ativos' => 'Ativos disponíveis', 'uso' => 'Uso, território e duração'] as $k => $l) {
        $v = trim((string)($x[$k] ?? ''));
        if ($v !== '') $out[] = $l . ': ' . $v;
    }
    foreach ((array)($x['funil'] ?? []) as $q => $ans) if (trim((string)$ans) !== '') $out[] = $q . ': ' . trim((string)$ans);
    $a = trim((string)($x['pedido'] ?? ''));
    $b = trim((string)($x['valor'] ?? ''));
    if ($a !== '') $out[] = "\nOpção A · Pedido: " . $a;
    if ($b !== '') $out[] = 'Opção B · Proposta de valor: ' . $b;
    return mb_substr(implode("\n", $out), 0, 12000);
}

/** As frentes (crosselling) de um negócio: [[id, funnel_id, nome, exec_owner_id], …] — vazio num negócio de um só serviço. */
function cc_deal_fronts(PDO $pdo, array $deal): array {
    require_once __DIR__ . '/xsell.php';
    if (!xsell_is_multi($pdo, $deal)) return [];
    $out = [];
    foreach (xsell_lines($pdo, (int)$deal['id']) as $l) {
        $f = funnel_get($pdo, (int)$l['funnel_id']);
        $out[] = ['id' => (int)$l['id'], 'funnel_id' => (int)$l['funnel_id'], 'name' => $f ? (string)$f['name'] : 'Serviço',
                  'exec' => $l['exec_owner_id'] ? (int)$l['exec_owner_id'] : null];
    }
    return $out;
}

/** Uma tarefa simples ligada ao negócio (para quem executa). Devolve o id. */
function cc_task_create(PDO $pdo, array $deal, string $title, string $desc, ?int $uid, string $due, ?int $by): int {
    require_once __DIR__ . '/tasks.php';
    require_once __DIR__ . '/leads.php';
    $uid = $uid ? flow_user_ok($pdo, $uid) : null;
    $member = $uid ? member_for_user($pdo, $uid) : null;
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO tasks (title, description, member_id, job_name, deal_id, due_date, kind, repeat_rule, priority, status, position,
                                      created_at, updated_at, created_by, checklist, participants, auto_rule_id, stage_id, is_gate, doc_kind)
                   VALUES (?,?,?,\'\',?,?,\'task\',\'\',\'media\',\'todo\',?,?,?,?,NULL,\'\',NULL,NULL,0,\'\')')
        ->execute([mb_substr($title, 0, 190), $desc, $member, (int)$deal['id'], $due, task_next_position($pdo, $member), $now, $now, $by]);
    return (int)$pdo->lastInsertId();
}

/** A nota do brief no topo de um quadro. */
function cc_brief_card(PDO $pdo, int $boardId, string $brief, ?int $by, float $x = 80, float $y = -260): void {
    if (trim($brief) === '') return;
    cc_card_add($pdo, $boardId, ['type' => 'note', 'x' => $x, 'y' => $y, 'w' => 560,
                                 'content' => ['text' => "Brief (do X-Ray)\n\n" . $brief, 'color' => '#FFC718', 'brief' => true]], $by);
}

/**
 * Ao validar o X-Ray: cria o pré-criativo (se o funil não o tiver desligado e ainda não existir)
 * e a tarefa para a execução. Reabrir o X-Ray e validar outra vez só atualiza o brief.
 */
function cc_on_xray_validated(PDO $pdo, array $deal, int $by): ?int {
    if (!cc_ready($pdo) || cc_pre_mode($pdo, $deal['funnel_id'] ? (int)$deal['funnel_id'] : null) === 'off') return null;
    $deal = deal_get($pdo, (int)$deal['id']) ?: $deal;
    $brief = cc_xray_brief($pdo, $deal);
    if ($b = cc_deal_board($pdo, (int)$deal['id'], 'precreative')) {
        $pdo->prepare('UPDATE cc_boards SET brief = ?, updated_at = ? WHERE id = ?')->execute([$brief, cc_now(), (int)$b['id']]);
        return (int)$b['id'];
    }
    require_once __DIR__ . '/pipeline_flow.php';
    [, $exec] = deal_roles($deal);
    $id = cc_board_create($pdo, ['deal_id' => (int)$deal['id'], 'kind' => 'precreative', 'title' => 'Pré-criativo · ' . $deal['name'], 'brief' => $brief,
                                 'exec_owner_id' => $exec ?: null], $by);
    cc_brief_card($pdo, $id, $brief, $by);
    foreach (cc_deal_fronts($pdo, $deal) as $i => $f) {
        $sub = cc_board_create($pdo, ['deal_id' => (int)$deal['id'], 'line_id' => $f['id'], 'parent_id' => $id, 'kind' => 'front', 'position' => $i,
                                      'title' => 'Pré-criativo · ' . $f['name'], 'brief' => $brief, 'exec_owner_id' => $f['exec'] ?: ($exec ?: null)], $by);
        cc_card_add($pdo, $id, ['type' => 'board', 'ref_board_id' => $sub, 'x' => 700 + $i * 260, 'y' => -260, 'w' => 240, 'content' => ['title' => $f['name']]], $by);
    }
    $days = max(0, min(30, (int)setting($pdo, 'cc_pre_days', 2)));
    $client = flow_client_name($pdo, $deal);
    $task = cc_task_create($pdo, $deal, 'Pré-criativo · ' . $client,
        "Preparar o pré-criativo de «" . $deal['name'] . "» (" . $deal['code'] . "): duas faixas, Opção A (pedido) e Opção B (proposta de valor).\n"
        . "1. «Preparar com IA» · 2. manter / tirar / trocar cartões · 3. «Gerar página» · 4. «Enviar para a Orçamentação».\n\n"
        . 'Abrir o quadro: /admin/criativo.php?b=' . $id, $exec ?: null, flow_add_workdays(today_lisbon(), $days), $by);
    $pdo->prepare('UPDATE cc_boards SET task_id = ? WHERE id = ?')->execute([$task, $id]);
    deal_log($pdo, (int)$deal['id'], 'auto', ['note' => 'Pré-criativo criado no Centro Criativo (tarefa para a execução)']);
    return $id;
}

/** Negócio perdido: os quadros do pré-criativo ficam arquivados com ele. */
function cc_on_lost(PDO $pdo, int $dealId): void {
    if (!cc_ready($pdo)) return;
    $pdo->prepare("UPDATE cc_boards SET status = 'archived', archived_why = 'lost', updated_at = ? WHERE deal_id = ? AND kind IN ('precreative','front') AND status = 'active'")
        ->execute([cc_now(), $dealId]);
}

/** Negócio reaberto: volta o que foi arquivado por ter sido perdido. */
function cc_on_reopen(PDO $pdo, int $dealId): void {
    if (!cc_ready($pdo)) return;
    $pdo->prepare("UPDATE cc_boards SET status = 'active', archived_why = '', updated_at = ? WHERE deal_id = ? AND archived_why = 'lost'")
        ->execute([cc_now(), $dealId]);
}

/** Copia os cartões de uma faixa para outro quadro (as referências não se copiam: apontam para a mesma). */
function cc_copy_lane(PDO $pdo, int $fromBoard, string $lane, int $toBoard, ?int $by): int {
    $cards = array_values(array_filter(cc_cards($pdo, $fromBoard), fn($c) => $lane === '' || $c['lane'] === $lane));
    if (!$cards) return 0;
    $minX = min(array_map(fn($c) => (float)$c['x'], $cards));
    $minY = min(array_map(fn($c) => (float)$c['y'], $cards));
    $map = [];
    foreach ($cards as $c) {
        if ($c['type'] === 'arrow') continue;
        $content = cc_card_content((string)$c['type'], $c['content']);
        if (isset($content['state']) && $content['state'] === 'swap') continue;   // as marcadas para trocar ficam para trás
        $map[(int)$c['id']] = cc_card_add($pdo, $toBoard, ['type' => $c['type'], 'asset_id' => $c['asset_id'], 'ref_board_id' => $c['ref_board_id'],
            'x' => (float)$c['x'] - $minX + 80, 'y' => (float)$c['y'] - $minY + 80, 'w' => (float)$c['w'], 'h' => (float)$c['h'], 'content' => $content], $by);
    }
    foreach ($cards as $c) {   // colunas: o cartão vai para a cópia da coluna
        if ($c['column_id'] && isset($map[(int)$c['id']], $map[(int)$c['column_id']])) {
            $pdo->prepare('UPDATE cc_cards SET column_id = ?, ord = ? WHERE id = ?')->execute([$map[(int)$c['column_id']], (int)$c['ord'], $map[(int)$c['id']]]);
        }
    }
    return count($map);
}

/** Cria a árvore de sub-quadros de etapa debaixo de $parent. Devolve [stage_key => id]. */
function cc_stage_boards(PDO $pdo, int $parent, array $deal, ?int $funnelId, ?int $lineId, ?int $exec, ?int $by): array {
    $out = [];
    foreach (cc_funnel_stages($pdo, $funnelId) as $i => $k) {
        $out[$k] = cc_board_create($pdo, ['deal_id' => (int)$deal['id'], 'line_id' => $lineId, 'parent_id' => $parent, 'kind' => 'stage', 'stage_key' => $k,
                                          'title' => CC_STAGES[$k], 'position' => $i, 'exec_owner_id' => $exec], $by);
        cc_card_add($pdo, $parent, ['type' => 'board', 'ref_board_id' => $out[$k], 'x' => 80 + ($i % 4) * 260, 'y' => 80 + intdiv($i, 4) * 200, 'w' => 240,
                                    'content' => ['title' => CC_STAGES[$k]]], $by);
    }
    return $out;
}

/**
 * Kickoff: o quadro do projeto. Se houver pré-criativo, a opção escolhida pelo cliente passa a
 * ser o «Conceito» e a outra fica guardada como «Rota não escolhida»; o pré-criativo fica
 * arquivado (consulta). Crosselling: um sub-quadro por frente, cada um com as suas etapas.
 */
function cc_on_kickoff(PDO $pdo, array $deal, ?int $by = null): ?int {
    if (!cc_ready($pdo)) return null;
    if ($b = cc_deal_board($pdo, (int)$deal['id'], 'project')) return (int)$b['id'];
    require_once __DIR__ . '/pipeline_flow.php';
    $deal = deal_get($pdo, (int)$deal['id']) ?: $deal;
    [, $exec] = deal_roles($deal);
    $brief = cc_xray_brief($pdo, $deal);
    $pid = cc_board_create($pdo, ['deal_id' => (int)$deal['id'], 'kind' => 'project', 'title' => $deal['code'] . ' · ' . $deal['name'], 'brief' => $brief,
                                  'exec_owner_id' => $exec ?: null], $by);
    cc_brief_card($pdo, $pid, $brief, $by, 80, -320);
    $p = proposal_latest($pdo, (int)$deal['id']);
    $chosen = $p && $p['decision'] === 'accept' && in_array($p['choice'], ['A', 'B'], true) ? $p['choice'] : 'A';
    $other = $chosen === 'A' ? 'B' : 'A';
    $pre = cc_deal_board($pdo, (int)$deal['id'], 'precreative');
    $fronts = cc_deal_fronts($pdo, $deal);
    $preFronts = [];
    if ($pre) foreach (cc_board_children($pdo, (int)$pre['id']) as $c) if ($c['line_id']) $preFronts[(int)$c['line_id']] = $c;

    $seed = function (array $stages, ?array $preBoard, int $parent) use ($pdo, $chosen, $other, $by) {
        if (!$preBoard) return;
        if (isset($stages['conceito'])) cc_copy_lane($pdo, (int)$preBoard['id'], $chosen, $stages['conceito'], $by);
        if (isset($stages['brief'])) cc_brief_card($pdo, $stages['brief'], (string)$preBoard['brief'], $by, 80, 80);
        $has = (int)$pdo->query("SELECT COUNT(*) FROM cc_cards WHERE board_id = " . (int)$preBoard['id'] . " AND lane = '$other' AND deleted_at IS NULL")->fetchColumn();
        if ($has) {
            $route = cc_board_create($pdo, ['deal_id' => (int)$preBoard['deal_id'], 'line_id' => $preBoard['line_id'], 'parent_id' => $parent, 'kind' => 'route',
                                            'title' => 'Rota não escolhida · ' . CC_LANES[$other], 'position' => 99], $by);
            cc_copy_lane($pdo, (int)$preBoard['id'], $other, $route, $by);
            cc_card_add($pdo, $parent, ['type' => 'board', 'ref_board_id' => $route, 'x' => 1140, 'y' => -320, 'w' => 240, 'content' => ['title' => 'Rota não escolhida']], $by);
        }
    };
    if ($fronts) {
        foreach ($fronts as $i => $f) {
            $fid = cc_board_create($pdo, ['deal_id' => (int)$deal['id'], 'line_id' => $f['id'], 'parent_id' => $pid, 'kind' => 'front', 'position' => $i,
                                          'title' => $f['name'], 'brief' => $brief, 'exec_owner_id' => $f['exec'] ?: ($exec ?: null)], $by);
            cc_card_add($pdo, $pid, ['type' => 'board', 'ref_board_id' => $fid, 'x' => 80 + $i * 260, 'y' => 80, 'w' => 240, 'content' => ['title' => $f['name']]], $by);
            $stages = cc_stage_boards($pdo, $fid, $deal, $f['funnel_id'], $f['id'], $f['exec'] ?: ($exec ?: null), $by);
            $seed($stages, $preFronts[$f['id']] ?? null, $fid);
        }
        // o conceito geral (o pré-criativo principal) entra no quadro do projeto
        if ($pre) $seed(['conceito' => cc_board_create($pdo, ['deal_id' => (int)$deal['id'], 'parent_id' => $pid, 'kind' => 'stage', 'stage_key' => 'conceito', 'title' => 'Conceito geral', 'position' => 50], $by)], $pre, $pid);
    } else {
        $stages = cc_stage_boards($pdo, $pid, $deal, $deal['funnel_id'] ? (int)$deal['funnel_id'] : null, null, $exec ?: null, $by);
        $seed($stages, $pre, $pid);
    }
    if ($pre) {
        $pdo->prepare("UPDATE cc_boards SET status = 'archived', archived_why = 'kickoff', updated_at = ? WHERE deal_id = ? AND kind IN ('precreative','front') AND status = 'active' AND (id = ? OR parent_id = ?)")
            ->execute([cc_now(), (int)$deal['id'], (int)$pre['id'], (int)$pre['id']]);
        cc_card_add($pdo, $pid, ['type' => 'board', 'ref_board_id' => (int)$pre['id'], 'x' => 1400, 'y' => -320, 'w' => 240, 'content' => ['title' => 'Pré-criativo (arquivo)']], $by);
    }
    deal_log($pdo, (int)$deal['id'], 'auto', ['note' => 'Quadro do projeto criado no Centro Criativo' . ($pre ? ' (a partir do pré-criativo: ' . CC_LANES[$chosen] . ')' : '')]);
    return $pid;
}

// ── páginas → Orçamentação ───────────────────────────────────

/** O quadro do pré-criativo e os sub-quadros das frentes, pela ordem. */
function cc_pre_boards(PDO $pdo, array $board): array {
    $root = $board;
    if ($board['kind'] === 'front' && $board['parent_id'] && ($p = cc_board_get($pdo, (int)$board['parent_id'])) && $p['kind'] === 'precreative') $root = $p;
    return array_merge([$root], array_values(array_filter(cc_board_children($pdo, (int)$root['id']), fn($c) => $c['kind'] === 'front')));
}

function cc_page_get(PDO $pdo, int $boardId, string $lane): ?array {
    $st = $pdo->prepare('SELECT * FROM cc_pages WHERE board_id = ? AND lane = ?');
    $st->execute([$boardId, $lane]);
    return $st->fetch() ?: null;
}

/**
 * «Enviar para a Orçamentação»: liga as páginas (A → Opção A, B → Opção B, «Serve as duas» → A e B)
 * à versão atual do orçamento. Precisa da folha de orçamento e de uma versão ainda não enviada.
 */
function cc_send_to_budget(PDO $pdo, array $board, int $by): string {
    require_once __DIR__ . '/pipeline_flow.php';
    require_once __DIR__ . '/budget.php';
    $deal = $board['deal_id'] ? deal_get($pdo, (int)$board['deal_id']) : null;
    if (!$deal) throw new RuntimeException('Este quadro não está ligado a um negócio.');
    [$sheet, $v] = proposal_version($pdo, $deal);
    if (!$sheet || !$v) throw new RuntimeException('Ainda não há folha de orçamento para este negócio: crie-a na Orçamentação e volte a enviar.');
    if (budget_version_sent($v)) throw new RuntimeException('A versão v' . (int)$v['num'] . ' do orçamento já foi enviada ao cliente. Crie uma versão nova na folha de orçamento para juntar o pré-criativo.');
    $n = 0;
    $labels = [];
    foreach (cc_pre_boards($pdo, $board) as $b) {
        foreach (['A', 'B'] as $lane) {
            $pg = cc_page_get($pdo, (int)$b['id'], $lane);
            if (!$pg || !$pg['data']) continue;
            $opt = (int)$pg['serves_both'] ? 'AB' : $lane;
            // o que segue para a proposta é uma cópia: gerar a página outra vez não muda uma proposta já enviada
            $pdo->prepare('UPDATE cc_pages SET version_id = ?, opt = ?, sent_data = data, sent_by = ?, sent_at = ? WHERE id = ?')->execute([(int)$v['id'], $opt, $by, cc_now(), (int)$pg['id']]);
            $n++;
            $labels[$opt] = true;
        }
    }
    if (!$n) throw new RuntimeException('Gere primeiro as páginas (uma por opção) com «Gerar página».');
    // páginas de versões antigas que já não estão ligadas a esta deixam de contar
    $what = implode(' e ', array_map(fn($o) => $o === 'AB' ? 'as duas opções' : 'Opção ' . $o, array_keys($labels)));
    budget_log($pdo, (int)$sheet['id'], 'b_cc', 'Pré-criativo ligado à v' . (int)$v['num'] . ' (' . $what . ')', $by);
    cc_pages_to_drive($pdo, $deal, $v, $by);   // cada página também em PDF, em «Criativo/Opção A|B/»
    deal_log($pdo, (int)$deal['id'], 'auto', ['note' => 'Pré-criativo enviado para a Orçamentação (v' . (int)$v['num'] . ', ' . $what . ')']);
    // a tarefa do pré-criativo fica feita
    $root = cc_pre_boards($pdo, $board)[0];
    if ($root['task_id']) {
        require_once __DIR__ . '/tasks.php';
        try { task_done($pdo, (int)$root['task_id']); } catch (Throwable $e) { /* já feita ou apagada */ }
    }
    return $n === 1 ? '1 página ligada à proposta v' . (int)$v['num'] . '.' : $n . ' páginas ligadas à proposta v' . (int)$v['num'] . '. Entram no PDF e na página do cliente.';
}

/**
 * As páginas enviadas para a Orçamentação ficam também no Drive, em PDF, na pasta do negócio:
 * «Criativo/Opção A/», «Criativo/Opção B/» ou «Criativo/Opções A e B/». Reenviar a mesma versão
 * substitui os ficheiros. Sem Drive, nada a fazer.
 */
function cc_pages_to_drive(PDO $pdo, array $deal, array $v, ?int $by): int {
    if (!r2_config()) return 0;
    require_once __DIR__ . '/files.php';
    require_once __DIR__ . '/budget_pdf.php';
    $n = 0;
    try {
        $folder = files_folder($pdo, $deal);
        if ($folder === null) return 0;
        foreach (cc_pages_for_version($pdo, (int)$v['id']) as $pg) {
            $dir = $pg['opt'] === 'AB' ? 'Opções A e B' : 'Opção ' . $pg['opt'];
            $name = files_name((string)$deal['code'], 'PreCriativo-' . $pg['opt'], trim(($pg['front'] !== '' ? $pg['front'] . '_' : '') . 'v' . (int)$v['num'], '_'), 'pdf');
            files_put($pdo, ['deal_id' => (int)$deal['id'], 'section' => 'criativo', 'prefix' => $folder . 'Criativo/' . $dir . '/', 'name' => $name, 'mime' => 'application/pdf',
                             'overwrite' => true, 'origin' => 'cc_page', 'label' => 'Pré-criativo · ' . $dir . ($pg['title'] !== '' ? ' · ' . $pg['title'] : ''), 'by' => $by],
                      cc_page_pdf($pdo, $deal, $pg, (int)$v['num']));
            $n++;
        }
    } catch (Throwable $e) {
        error_log('cc_pages_to_drive: ' . $e->getMessage());
    }
    return $n;
}

/** O tratamento em PDF no Drive: «<pasta do negócio>/Criativo/» ou «Biblioteca criativa/<quadro>/». */
function cc_treatment_to_drive(PDO $pdo, array $board, array $doc, ?int $by): ?string {
    if (!r2_config()) return null;
    require_once __DIR__ . '/files.php';
    require_once __DIR__ . '/budget_pdf.php';
    try {
        $root = cc_board_path($pdo, $board)[0];
        $deal = $root['deal_id'] ? files_deal_row($pdo, (int)$root['deal_id']) : null;
        $folder = $deal ? files_folder($pdo, $deal) : null;
        $prefix = $folder !== null ? $folder . 'Criativo/' : FILES_LIBRARY . (r2_clean_name((string)$root['title']) ?: 'Quadro') . '/';
        $code = $deal ? (string)$deal['code'] : '';
        $r = files_put($pdo, ['deal_id' => $deal ? (int)$deal['id'] : null, 'section' => 'criativo', 'prefix' => $prefix,
                              'name' => files_name($code, 'Tratamento', (string)($doc['title'] ?? ''), 'pdf'), 'mime' => 'application/pdf', 'overwrite' => true,
                              'origin' => 'treatment', 'label' => 'Tratamento · ' . (string)($doc['title'] ?? ''), 'by' => $by],
                       cc_treatment_pdf($pdo, $code !== '' ? $code : (string)$root['title'], $doc));
        return $r['key'] ?? null;
    } catch (Throwable $e) {
        error_log('cc_treatment_to_drive: ' . $e->getMessage());
        return null;
    }
}

/**
 * As páginas do pré-criativo ligadas a uma versão do orçamento, prontas a mostrar ao cliente:
 * [['opt' => 'A'|'B'|'AB', 'front' => '', 'title', 'concept', 'approach', 'technique', 'complexity', 'palette' => [...], 'refs' => [ids]], …]
 */
function cc_pages_for_version(PDO $pdo, int $versionId): array {
    if (!cc_ready($pdo)) return [];
    $st = $pdo->prepare("SELECT p.*, b.kind AS bkind, b.title AS btitle, b.line_id FROM cc_pages p JOIN cc_boards b ON b.id = p.board_id
                          WHERE p.version_id = ? AND p.lane IN ('A','B') ORDER BY b.parent_id IS NOT NULL, b.position, b.id, p.lane");
    $st->execute([$versionId]);
    $out = [];
    foreach ($st->fetchAll() as $p) {
        $d = json_decode((string)($p['sent_data'] ?? ''), true);
        if (!is_array($d)) continue;
        $front = $p['bkind'] === 'front' ? trim(preg_replace('/^Pré-criativo · /u', '', (string)$p['btitle'])) : '';
        $out[] = ['opt' => (string)$p['opt'], 'front' => $front, 'title' => (string)($d['title'] ?? ''), 'concept' => (string)($d['concept'] ?? ''),
                  'approach' => (string)($d['approach'] ?? ''), 'technique' => (string)($d['technique'] ?? ''), 'complexity' => (string)($d['complexity'] ?? ''),
                  'palette' => array_values(array_filter((array)($d['palette'] ?? []), fn($h) => is_string($h) && preg_match('/^#[0-9A-Fa-f]{6}$/', $h))),
                  'refs' => array_values(array_map('intval', array_slice((array)($d['refs'] ?? []), 0, 6)))];
    }
    return $out;
}

/** As páginas de uma opção (A ou B), incluindo as que servem as duas. */
function cc_pages_for_opt(array $pages, string $opt): array {
    return array_values(array_filter($pages, fn($p) => $p['opt'] === $opt || $p['opt'] === 'AB'));
}

/** O requisito na etapa Proposta (§23.3): obrigatório num funil, recomendado (não trava) nos outros. */
function cc_requirements(PDO $pdo, array $deal): array {
    $mode = cc_pre_mode($pdo, $deal['funnel_id'] ? (int)$deal['funnel_id'] : null);
    if ($mode === 'off') return [];
    $b = cc_deal_board($pdo, (int)$deal['id'], 'precreative');
    $sent = false;
    if ($b) {
        require_once __DIR__ . '/pipeline_flow.php';
        [, $v] = proposal_version($pdo, $deal);
        if ($v) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM cc_pages WHERE version_id = ?');
            $st->execute([(int)$v['id']]);
            $sent = (int)$st->fetchColumn() > 0;
        }
    }
    $hint = !$b ? 'O pré-criativo nasce ao validar o X-Ray.' : 'No Centro Criativo: «Gerar página» e «Enviar para a Orçamentação».';
    if ($mode === 'required') return [['label' => 'Pré-criativo enviado para a Orçamentação', 'ok' => $sent, 'hint' => $hint]];
    return [['label' => 'Pré-criativo enviado para a Orçamentação (recomendado)', 'ok' => true, 'soft' => true, 'done' => $sent, 'hint' => $hint]];
}
