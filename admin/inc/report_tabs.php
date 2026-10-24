<?php
/**
 * topper. admin — os separadores dos Relatórios (§29.3–§29.10). Cada rep_<separador>($c) escreve o seu HTML;
 * as tabelas passam por mx_table (saem também no CSV). $c: pdo, all (todos os registos), deals (com os
 * filtros), per (período), f (filtros), money, admin, uid, funnels, people, user.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

/** Um nome de pessoa que quem vê pode ver (os operadores não veem os nomes dos colegas nos números). */
function rep_person(array $c, int $uid): string {
    if (!$uid) return 'Sem responsável';
    if ($c['admin'] || $uid === $c['uid']) return $c['people'][$uid] ?? '—';
    return 'Colega';
}

// ── Atenção ─────────────────────────────────────────────────

const REP_ATT_KINDS = ['sla' => 'Sem primeira resposta', 'stalled' => 'Parados', 'feedback' => 'Propostas sem seguimento', 'gate' => 'Gates bloqueados',
                       'task' => 'Tarefas atrasadas', 'kickoff' => 'Kickoffs sem data', 'review' => 'Reviews à espera', 'invoice' => 'Faturas', 'goal' => 'Metas em risco'];

function rep_atencao(array $c): void {
    $items = rep_attention_items($c);
    $mine = !empty($_GET['minhas']);
    $kind = isset(REP_ATT_KINDS[$_GET['k'] ?? '']) ? (string)$_GET['k'] : '';
    $byKind = [];
    foreach ($items as $i) $byKind[$i['kind']][] = $i;
    $show = array_values(array_filter($items, fn($i) => (!$mine || $i['owner'] === $c['uid']) && ($kind === '' || $i['kind'] === $kind)));
    $myN = count(array_filter($items, fn($i) => $i['owner'] === $c['uid']));

    // o resumo semanal (segunda às 9h)
    $wk = mx_week_latest($c['pdo'], $c['uid']);
    if ($wk['team'] || $wk['me']) {
        $todo = json_decode((string)($wk['team']['todo'] ?? '[]'), true) ?: [];
        echo '<section class="box mx-week" id="resumo"><h3>Resumo da semana <span class="muted small">· ' . e(date('d/m', strtotime($wk['team']['week'] ?? $wk['me']['week']))) . ($wk['team'] && (int)$wk['team']['by_ai'] ? ' · as 3 coisas pela IA' : '') . '</span></h3>';
        if ($todo) {
            echo '<ol class="mx-todo">';
            foreach (array_slice($todo, 0, 3) as $t) echo '<li>' . e((string)$t) . '</li>';
            echo '</ol>';
        }
        echo '<details><summary>Ler o resumo' . ($wk['me'] ? ' (equipa e o seu)' : '') . '</summary>';
        if ($wk['team']) echo '<pre class="mx-pre">' . e((string)$wk['team']['body']) . '</pre>';
        if ($wk['me']) echo '<pre class="mx-pre">' . e((string)$wk['me']['body']) . '</pre>';
        echo '</details></section>';
    }

    echo '<div class="mx-attbar"><div class="views" role="group" aria-label="Tipo">';
    echo '<a class="' . ($kind === '' ? 'on' : '') . '" href="' . e(mx_href(['k' => null])) . '">Tudo <span class="muted">' . count($items) . '</span></a>';
    foreach (REP_ATT_KINDS as $k => $l) {
        if (empty($byKind[$k])) continue;
        echo '<a class="' . ($kind === $k ? 'on' : '') . '" href="' . e(mx_href(['k' => $k])) . '">' . e($l) . ' <span class="muted">' . count($byKind[$k]) . '</span></a>';
    }
    echo '</div><a class="btn sm ' . ($mine ? '' : 'ghost') . '" href="' . e(mx_href(['minhas' => $mine ? null : 1])) . '" aria-pressed="' . ($mine ? 'true' : 'false') . '">Só as minhas · ' . $myN . '</a></div>';

    if (!$show) {
        echo '<div class="mx-calm"><b>Nada urgente' . ($mine ? ' para si' : '') . '.</b> <span class="muted">Leads respondidas a tempo, nada parado acima do normal, sem tarefas nem faturas em atraso.</span></div>';
    }
    $rows = [];
    $all = !empty($_GET['todas']) || (($_GET['export'] ?? '') === 'csv');
    $total = count($show);
    if (!$all) $show = array_slice($show, 0, 40);
    foreach ($show as $i) {
        $sev = ['', 'aviso', 'atenção', 'grave'][$i['sev']];
        $rows[] = [
            ['h' => '<span class="mx-sev s' . $i['sev'] . '" role="img" aria-label="' . $sev . '" data-tip="' . ucfirst($sev) . '"></span>', 'c' => $sev],
            ['h' => '<b>' . e($i['title']) . '</b><div class="small muted">' . e($i['sub']) . '</div>', 'c' => $i['title'] . ' — ' . $i['sub']],
            rep_person($c, $i['owner']),
            $i['age'],
            ['h' => '<a class="btn sm ghost" href="' . e($i['url']) . '">Abrir</a>', 'c' => $i['url']],
        ];
    }
    if ($show) echo mx_table('Atenção', ['', 'O quê', 'Dono', 'Há quanto tempo', ''], $rows);
    if (!$all && $total > count($show)) echo '<p><a class="btn sm ghost" href="' . e(mx_href(['todas' => 1])) . '">Mostrar todas (' . $total . ')</a></p>';
    echo '<p class="small muted">Ordenado por gravidade e antiguidade. ' . mx_ii('Leads sem primeira resposta depois do prazo do canal (horas úteis); leads e negócios parados acima do limite da etapa (ou da média histórica × 1,5); propostas em «Aguardar feedback» sem interação; gates e tarefas atrasadas; kickoffs sem data de entrega; reviews à espera do cliente; faturas em atraso e pedidas sem resposta (só com Financeiro); metas em risco.') . ' Os prazos mudam-se em Definições › Leads e Pipeline.</p>';
}

// ── Metas ───────────────────────────────────────────────────

const REP_GOAL_STATE = ['done' => ['Cumprida', 'ok'], 'ok' => ['Em linha', 'info'], 'risk' => ['Em risco', 'wait'], 'behind' => ['Atrás', 'no'], 'missed' => ['Não cumprida', 'no']];

function rep_goal_row(array $g): array {
    $fmt = $g['fmt'];
    $st = REP_GOAL_STATE[$g['state']];
    $tip = $g['progress'] . ($g['unit'] !== 'pct' && !$g['closed'] ? ' · previsão ao ritmo atual: ' . $fmt($g['forecast']) : '');
    return [
        ['h' => '<b>' . e($g['label']) . '</b>' . ($g['is_auto'] ? ' <span class="muted small" data-tip="Sugerida pela cascata a partir das taxas reais">· cascata</span>' : ''), 'c' => $g['label']],
        $g['scope_label'],
        ['h' => chart_bullet($g['value'], $g['target'], $g['expected'], $g['forecast'], $g['state'], $tip), 'c' => ''],
        ['h' => mx_a($fmt($g['value']), $g['ids'], $g['label'] . ' · ' . $g['scope_label'] . ' · ' . $g['period_label']) . ' <span class="muted">/ ' . e($fmt($g['target'])) . '</span>', 'c' => $fmt($g['value']) . ' / ' . $fmt($g['target'])],
        $g['unit'] === 'pct' ? '—' : $fmt($g['expected']),
        $g['unit'] === 'pct' || $g['closed'] ? '—' : $fmt($g['forecast']),
        ['h' => '<span class="chip ' . $st[1] . '">' . $st[0] . '</span>', 'c' => $st[0]],
    ];
}

function rep_metas(array $c): void {
    $pdo = $c['pdo'];
    $goals = mx_goals_status($pdo, $c['all'], $c['money']);
    if (!$c['admin']) $goals = array_values(array_filter($goals, fn($g) => $g['scope'] !== 'user' || $g['scope_id'] === $c['uid']));
    if ($c['f']['fu']) $goals = array_values(array_filter($goals, fn($g) => $g['scope'] !== 'funnel' || $g['scope_id'] === $c['f']['fu']));
    if ($c['f']['pe']) $goals = array_values(array_filter($goals, fn($g) => $g['scope'] !== 'user' || $g['scope_id'] === $c['f']['pe']));
    $head = ['Meta', 'Âmbito', 'Progresso', '#Real / meta', '#Ritmo esperado', '#Previsão', 'Estado'];
    if (!$goals) {
        echo '<div class="mx-calm"><b>Ainda não há metas para o período em curso.</b> <span class="muted">' . ($c['admin'] ? 'Defina-as no botão «Definir metas», abaixo: por mês ou trimestre, para a empresa, um funil ou uma pessoa.' : 'Os administradores definem as metas.') . '</span></div>';
    } else {
        $first = $goals[0];
        echo '<p class="sub">' . e($first['period_label']) . ' · dia ' . $first['day'] . ' de ' . $first['days'] . ' — ' . (int)round($first['frac'] * 100) . '% do período ' . mx_ii('Ritmo esperado = meta × parte do período que já passou. Previsão = o real ao ritmo de agora até ao fim do período. Em linha: pelo menos 95% do ritmo; em risco: 80–95%; atrás: abaixo de 80%. As taxas comparam-se diretamente com a meta.') . '</p>';
        foreach (['company' => 'Empresa', 'funnel' => 'Por funil', 'user' => 'Por pessoa'] as $s => $label) {
            $rows = array_map('rep_goal_row', array_values(array_filter($goals, fn($g) => $g['scope'] === $s)));
            if ($rows) echo '<h3 class="mx-h3">' . e($label) . '</h3>' . mx_table('Metas · ' . $label, $head, $rows);
        }
    }

    // as taxas reais que alimentam a cascata
    $scope = (string)($_GET['gs'] ?? 'company');
    $scopeId = (int)($_GET['gi'] ?? 0);
    if (!in_array($scope, ['company', 'funnel', 'user'], true)) $scope = 'company';
    $rates = mx_cascade_rates($c['all'], $scope, $scopeId);
    $rr = [];
    foreach ($rates['rates'] as $to => $r) {
        $rr[] = [MX_GOAL_METRICS[$r['prev']][0] . ' → ' . MX_GOAL_METRICS[$to][0], (string)$r['from'], (string)$r['to'], ['h' => '<b>' . mx_pct($r['rate'] === null ? null : $r['rate'] * 100, 1) . '</b>' . mx_small($r['from'], 'casos'), 'c' => mx_pct($r['rate'] === null ? null : $r['rate'] * 100, 1)]];
    }
    echo '<h3 class="mx-h3">Taxas reais dos últimos 6 meses ' . mx_ii('Contagens das passagens nos últimos 6 meses (desde ' . date('d/m/Y', strtotime($rates['from'])) . '): por exemplo, das propostas, quantas chegaram a kickoff. É com estas taxas que a cascata calcula o que é preciso.') . '</h3>';
    echo mx_table('Taxas reais (6 meses)', ['Passagem', '#De', '#Para', '#Taxa'], $rr);

    // definir metas (só superadmin e admins)
    if ($c['admin']) rep_goal_form($c, $rates);

    // histórico: metas por período (cumpridas ou não)
    $hist = array_values(array_filter(mx_goals_status($pdo, $c['all'], $c['money'], today_lisbon(), true), fn($g) => $g['closed']));
    if (!$c['admin']) $hist = array_values(array_filter($hist, fn($g) => $g['scope'] !== 'user' || $g['scope_id'] === $c['uid']));
    $rows = [];
    $byPeriod = [];
    foreach ($hist as $g) $byPeriod[$g['period_label']][] = $g;
    foreach (array_slice($byPeriod, 0, 12, true) as $pl => $gs) {
        $ok = count(array_filter($gs, fn($g) => $g['state'] === 'done'));
        $rows[] = [$pl, ['h' => '<b>' . $ok . '</b> de ' . count($gs), 'c' => $ok . ' de ' . count($gs)],
                   ['h' => implode(' ', array_map(fn($g) => '<span class="chip ' . ($g['state'] === 'done' ? 'ok' : 'no') . '" data-tip="' . e($g['progress']) . '">' . e($g['label'] . ' · ' . $g['scope_label']) . '</span>', $gs)),
                    'c' => implode('; ', array_map(fn($g) => $g['label'] . ' (' . $g['scope_label'] . '): ' . ($g['fmt'])($g['value']) . '/' . ($g['fmt'])($g['target']), $gs))]];
    }
    echo '<h3 class="mx-h3">Histórico de metas cumpridas</h3>' . mx_table('Histórico de metas', ['Período', '#Cumpridas', 'Metas'], $rows, [], 'Ainda não há períodos fechados com metas.');
}

/** O formulário das metas: âmbito, período e as métricas; «Calcular a cascata» parte dos kickoffs. */
function rep_goal_form(array $c, array $rates): void {
    $pdo = $c['pdo'];
    $scope = in_array($_GET['gs'] ?? '', ['company', 'funnel', 'user'], true) ? (string)$_GET['gs'] : 'company';
    $sid = (int)($_GET['gi'] ?? 0);
    $period = ($_GET['gp'] ?? 'month') === 'quarter' ? 'quarter' : 'month';
    $today = today_lisbon();
    $opts = [];
    foreach ([0, 1, 2] as $k) {
        $d = (new DateTimeImmutable($today))->modify('first day of this month')->modify('+' . ($period === 'quarter' ? $k * 3 : $k) . ' months')->format('Y-m-d');
        [$a] = mx_goal_bounds($period, $d);
        $opts[$a] = mx_goal_period_label($period, $a) . ($k === 0 ? ' (em curso)' : '');
    }
    $start = isset($opts[$_GET['gd'] ?? '']) ? (string)$_GET['gd'] : array_key_first($opts);
    $cur = [];
    $st = $pdo->prepare('SELECT metric, target, is_auto FROM metric_goals WHERE scope = ? AND scope_id = ? AND period = ? AND period_start = ?');
    $st->execute([$scope, $scope === 'company' ? 0 : $sid, $period, $start]);
    foreach ($st->fetchAll() as $r) $cur[$r['metric']] = $r;
    $sug = [];
    $k = (int)($_GET['cascata'] ?? 0);
    if ($k > 0) $sug = mx_cascade($rates, $k);
    $val = function (string $m) use ($cur, $sug) {
        if (isset($sug[$m])) return (string)$sug[$m];
        if (!isset($cur[$m])) return '';
        return rtrim(rtrim(number_format((float)$cur[$m]['target'], 2, ',', ''), '0'), ',');
    };
    // fechado atrás de um botão; abre sozinho quando se está a trabalhar nele (âmbito, período ou cascata no endereço)
    $open = array_intersect(['gs', 'gi', 'gp', 'gd', 'cascata'], array_keys($_GET)) !== [];
    echo '<details class="mx-goalform" id="definir"' . ($open ? ' open' : '') . '><summary class="btn">' . icon('plus') . ' Definir metas</summary>'
        . '<section class="box"><h3>Definir metas <span class="muted small">· só administradores</span></h3>';
    // escolher âmbito e período (GET: recarrega com as metas que já existem)
    echo '<form class="bar" method="get" data-auto>' . rep_hidden(['tab' => 'metas']) . '<select name="gs" aria-label="Âmbito"><option value="company"' . ($scope === 'company' ? ' selected' : '') . '>Empresa</option><option value="funnel"' . ($scope === 'funnel' ? ' selected' : '') . '>Funil</option><option value="user"' . ($scope === 'user' ? ' selected' : '') . '>Pessoa</option></select>';
    if ($scope === 'funnel') { echo '<select name="gi" aria-label="Funil">'; foreach ($c['funnels'] as $id => $n) echo '<option value="' . (int)$id . '"' . ($sid === (int)$id ? ' selected' : '') . '>' . e($n) . '</option>'; echo '</select>'; }
    if ($scope === 'user') { echo '<select name="gi" aria-label="Pessoa">'; foreach ($c['people'] as $id => $n) echo '<option value="' . (int)$id . '"' . ($sid === (int)$id ? ' selected' : '') . '>' . e($n) . '</option>'; echo '</select>'; }
    echo '<select name="gp" aria-label="Período"><option value="month"' . ($period === 'month' ? ' selected' : '') . '>Por mês</option><option value="quarter"' . ($period === 'quarter' ? ' selected' : '') . '>Por trimestre</option></select>';
    echo '<select name="gd" aria-label="Qual">'; foreach ($opts as $a => $l) echo '<option value="' . e($a) . '"' . ($start === $a ? ' selected' : '') . '>' . e($l) . '</option>'; echo '</select>';
    echo '<button class="btn sm apply" type="submit">Ver</button></form>';
    // a cascata (GET): dos kickoffs para trás
    echo '<form class="bar mx-cascade" method="get">' . rep_hidden(['tab' => 'metas', 'gs' => $scope, 'gi' => $sid ?: '', 'gp' => $period, 'gd' => $start])
        . '<label class="small" for="mx-k">Meta em cascata: quero</label><input id="mx-k" type="number" name="cascata" min="1" max="999" value="' . ($k ?: e($val('kickoffs'))) . '" style="width:90px"> <span class="small">kickoffs</span>'
        . '<button class="btn sm" type="submit">Calcular</button> ' . mx_ii('Parte do objetivo final (kickoffs) e, com as taxas reais dos últimos 6 meses acima, calcula quantas propostas, entradas em pipeline, leads abordadas e leads são precisas. Os números ficam no formulário: pode ajustá-los à mão antes de gravar.') . '</form>';
    if ($sug && count($sug) < 5) echo '<p class="small warn">Sem histórico suficiente para todas as passagens: preencha à mão o que falta.</p>';
    echo '<form method="post" class="mx-goals">' . csrf_field()
        . '<input type="hidden" name="do" value="save"><input type="hidden" name="scope" value="' . e($scope) . '"><input type="hidden" name="scope_funnel" value="' . ($scope === 'funnel' ? $sid : 0) . '"><input type="hidden" name="scope_user" value="' . ($scope === 'user' ? $sid : 0) . '">'
        . '<input type="hidden" name="period" value="' . e($period) . '"><input type="hidden" name="start" value="' . e($start) . '"><div class="grid">';
    foreach (MX_GOAL_METRICS as $m => [$label, $unit]) {
        if ($unit === 'eur' && !$c['money']) continue;
        echo '<div class="field"><label for="g-' . $m . '">' . e($label) . ($unit === 'eur' ? ' (€)' : ($unit === 'pct' ? ' (%)' : '')) . '</label>'
            . '<input id="g-' . $m . '" name="g[' . $m . ']" inputmode="decimal" value="' . e($val($m)) . '" placeholder="sem meta"' . (isset($sug[$m]) ? ' class="mx-sug"' : '') . '>'
            . (isset($cur[$m]) ? '<input type="hidden" name="had[' . $m . ']" value="1">' : '') . (isset($sug[$m]) ? '<input type="hidden" name="auto[' . $m . ']" value="1">' : '') . '</div>';
    }
    echo '</div><div class="actions"><button class="btn" type="submit">Gravar metas</button><span class="small muted">Vazio ou 0 tira a meta. ' . e(mx_goal_period_label($period, $start)) . '.</span></div></form></section></details>';
}

/** Campos escondidos com o estado (período e filtros) e os dados. */
function rep_hidden(array $over): string {
    $h = '';
    foreach (array_merge(mx_ctx()['q'] ?? [], $over) as $k => $v) if ($v !== null && $v !== '' && $v !== 0) $h .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
    return $h;
}

// ── Funil comercial ─────────────────────────────────────────

/** Contagens por semana (as últimas $n semanas até ao fim do período) para as mini-linhas. */
function rep_weekly(array $deals, string $to, callable $when, int $n = 8): array {
    $end = new DateTimeImmutable($to);
    $out = [];
    for ($i = $n; $i >= 1; $i--) {
        $a = $end->modify('-' . ($i * 7) . ' days')->format('Y-m-d'); $b = $end->modify('-' . (($i - 1) * 7) . ' days')->format('Y-m-d');
        $out[] = count(array_filter($deals, fn($d) => mx_in($when($d), $a, $b)));
    }
    return $out;
}

function rep_funil(array $c): void {
    $per = $c['per']; $deals = $c['deals']; $money = $c['money'];
    [$from, $to, $pf, $pt] = [$per['from'], $per['to'], $per['prev_from'], $per['prev_to']];
    $mode = ($_GET['leitura'] ?? 'coorte') === 'periodo' ? 'periodo' : 'coorte';
    $fc = $mode === 'coorte' ? mx_funnel_cohort($deals, $from, $to) : mx_funnel_period($deals, $from, $to);
    $fp = $mode === 'coorte' ? mx_funnel_cohort($deals, $pf, $pt) : mx_funnel_period($deals, $pf, $pt);
    $keys = array_keys(MX_STEPS);

    echo '<div class="mx-row"><nav class="views" aria-label="Leitura"><a class="' . ($mode === 'coorte' ? 'on' : '') . '" href="' . e(mx_href(['leitura' => null])) . '">Por coorte</a>'
        . '<a class="' . ($mode === 'periodo' ? 'on' : '') . '" href="' . e(mx_href(['leitura' => 'periodo'])) . '">Por período</a></nav> '
        . mx_ii($mode === 'coorte' ? 'Por coorte: das leads que entraram neste período, quantas já chegaram a cada etapa (até hoje). É a leitura certa da conversão.' : 'Por período: o que aconteceu neste período em cada etapa (entradas, passagens e perdas com data no período), venham as leads de quando vierem.') . '</div>';

    // indicadores
    $nLead = count($fc['lead']['in']); $pLead = count($fp['lead']['in']);
    $wonNow = array_filter($deals, fn($d) => $d['end'] === 'won' && mx_in($d['won_at'], $from, $to));
    $wonPrev = array_filter($deals, fn($d) => $d['end'] === 'won' && mx_in($d['won_at'], $pf, $pt));
    $conv = $mode === 'coorte' ? mx_rate(count($fc['kickoff']['in']), $nLead) : mx_rate(count($wonNow), $nLead);
    $convP = $mode === 'coorte' ? mx_rate(count($fp['kickoff']['in']), $pLead) : mx_rate(count($wonPrev), $pLead);
    $cyc = mx_median(array_map(fn($d) => mx_days($d['at']['lead'], $d['won_at']), $wonNow));
    $cycP = mx_median(array_map(fn($d) => mx_days($d['at']['lead'], $d['won_at']), $wonPrev));
    $avg = $wonNow ? array_sum(array_column($wonNow, 'value')) / count($wonNow) : null;
    $avgP = $wonPrev ? array_sum(array_column($wonPrev, 'value')) / count($wonPrev) : null;
    $convIds = $mode === 'coorte' ? $fc['kickoff']['in'] : array_keys($wonNow);
    echo '<div class="stats mx-kpis">'
        . mx_kpi('Leads', mx_a((string)$nLead, $fc['lead']['in'], 'Leads que entraram · ' . $per['label']), 'entraram no período', mx_cmp($nLead, $pLead), 'Leads e negócios criados no período (sem quarentena nem spam).', chart_spark(rep_weekly($deals, $to, fn($d) => $d['at']['lead']), 'Leads por semana'), true)
        . mx_kpi('Lead → Kickoff', mx_a(mx_pct($conv, 1), $convIds, 'Chegaram a Kickoff · ' . $per['label']), count($convIds) . ' kickoffs' . mx_small($nLead, 'leads'), mx_cmp($conv, $convP, 'up', fn($v) => mx_pct($v, 1), true),
                  $mode === 'coorte' ? 'Das leads que entraram no período, % que já chegou a Kickoff.' : 'Kickoffs no período ÷ leads que entraram no período.')
        . mx_kpi($money ? 'Valor médio ganho' : 'Kickoffs no período', $money ? mx_a($avg === null ? '—' : eur0($avg), array_keys($wonNow), 'Ganhos · ' . $per['label']) : mx_a((string)count($wonNow), array_keys($wonNow), 'Ganhos · ' . $per['label']),
                  count($wonNow) . ' negócios chegaram a Kickoff', $money ? mx_cmp($avg, $avgP, 'up', fn($v) => eur0($v)) : mx_cmp(count($wonNow), count($wonPrev)), $money ? 'Valor (sem IVA) dos negócios que chegaram a Kickoff no período ÷ número deles.' : 'Negócios que chegaram a Kickoff no período.')
        . mx_kpi('Ciclo Lead → Kickoff', $cyc === null ? '—' : mx_num($cyc, 0) . ' dias', 'mediana dos ganhos no período', mx_cmp($cyc, $cycP, 'down', fn($v) => mx_num($v, 0) . ' dias'), 'Dias entre a entrada da lead e o Kickoff, mediana dos negócios ganhos no período.')
        . '</div>';

    // o funil
    $rows = [];
    foreach ($keys as $i => $k) {
        $n = count($fc[$k]['in']);
        $pass = $i < 5 && $n ? count($fc[$k]['next']) / $n * 100 : null;
        $rows[] = ['label' => MX_STEPS[$k], 'n' => $n, 'lost' => count($fc[$k]['lost']), 'pass' => $i < 5 ? ($pass ?? 0) : null,
                   'url' => mx_href(['lista' => 'd', 'ids' => implode(',', array_slice($fc[$k]['in'], 0, 400)), 'lt' => MX_STEPS[$k] . ' · ' . $per['label']]),
                   'url_lost' => mx_href(['lista' => 'd', 'ids' => implode(',', array_slice($fc[$k]['lost'], 0, 400)), 'lt' => 'Perdidas em ' . MX_STEPS[$k] . ' · ' . $per['label']]),
                   'tip' => MX_STEPS[$k] . ' · ' . $n . ($i < 5 && $n ? ' · ' . (int)round($pass) . '% passou · ' . count($fc[$k]['lost']) . ' perdidas' : '')];
    }
    echo '<div class="fin-grid"><div class="flow"><h3>Funil ' . ($mode === 'coorte' ? 'por coorte' : 'por período') . '</h3><div class="lg"><span><i class="box" style="background:var(--c-real)"></i>chegaram</span><span><i class="box" style="background:var(--mute-2)"></i>perdidas</span></div>'
        . chart_funnel($rows, 'Funil comercial') . '</div>';

    // motivos de perda
    $reasons = [];
    foreach ($keys as $k) foreach ($fc[$k]['reasons'] as $r => $ids) { $reasons[$r][$k] = $ids; }
    $rr = [];
    $lk = array_values(array_filter($keys, fn($k) => (bool)$fc[$k]['lost']));
    foreach ($reasons as $r => $byStep) {
        $all = array_merge(...array_values($byStep));
        $row = [$r];
        foreach ($lk as $k) $row[] = ['h' => isset($byStep[$k]) ? mx_a((string)count($byStep[$k]), $byStep[$k], $r . ' · ' . MX_STEPS[$k]) : '<span class="muted">·</span>', 'c' => (string)count($byStep[$k] ?? [])];
        $row[] = ['h' => '<b>' . mx_a((string)count($all), $all, 'Perdidas: ' . $r) . '</b>', 'c' => (string)count($all)];
        $rr[count($all) * 1000 + count($rr)] = $row;
    }
    krsort($rr);
    echo '<div class="flow"><h3>Motivos de perda ' . mx_ii('Os motivos são obrigatórios ao perder um negócio ou arquivar uma lead. «Recusou a proposta» vem da resposta do cliente na página da proposta.') . '</h3>'
        . ($rr ? mx_table('Motivos de perda', array_merge(['Motivo'], array_map(fn($k) => '#' . MX_STEPS[$k], $lk), ['#Total']), array_values($rr))
               : '<p class="empty">Sem perdas registadas ' . ($mode === 'coorte' ? 'nesta coorte' : 'neste período') . '.</p>') . '</div></div>';

    // por etapa
    $sr = [];
    foreach ($keys as $i => $k) {
        $n = count($fc[$k]['in']);
        $sr[] = [MX_STEPS[$k], ['h' => mx_a((string)$n, $fc[$k]['in'], MX_STEPS[$k] . ' · ' . $per['label']), 'c' => (string)$n],
                 ['h' => $i < 5 ? mx_a((string)count($fc[$k]['next']), $fc[$k]['next'], 'Passaram de ' . MX_STEPS[$k]) : '—', 'c' => $i < 5 ? (string)count($fc[$k]['next']) : ''],
                 ['h' => $i < 5 ? mx_pct(mx_rate(count($fc[$k]['next']), $n)) . mx_small($n, 'casos') : '—', 'c' => $i < 5 ? mx_pct(mx_rate(count($fc[$k]['next']), $n)) : ''],
                 ['h' => mx_a((string)count($fc[$k]['lost']), $fc[$k]['lost'], 'Perdidas em ' . MX_STEPS[$k]), 'c' => (string)count($fc[$k]['lost'])],
                 mx_pct(mx_rate(count($fc[$k]['lost']), $n)),
                 ['h' => $mode === 'coorte' && $i < 5 ? mx_a((string)count($fc[$k]['open']), $fc[$k]['open'], 'Ainda em ' . MX_STEPS[$k]) : '—', 'c' => $mode === 'coorte' && $i < 5 ? (string)count($fc[$k]['open']) : ''],
                 $i < 5 ? mx_num(mx_avg($fc[$k]['days'])) : '—', $i < 5 ? mx_num(mx_median($fc[$k]['days'])) : '—'];
    }
    echo '<h3 class="mx-h3">Por etapa ' . mx_ii('Entraram: chegaram à etapa. Passaram: já chegaram à seguinte. Perdidas: perdidas (ou arquivadas) nesta etapa. Ainda abertas: estão nesta etapa hoje. Tempo na etapa: dias entre entrar nesta etapa e chegar à seguinte (média e mediana).') . '</h3>';
    echo mx_table('Funil por etapa', ['Etapa', '#Entraram', '#Passaram', '#% passou', '#Perdidas', '#% perdida', '#Ainda abertas', '#Dias (média)', '#Dias (mediana)'], $sr);

    // coortes mensais (mapa de calor)
    $co = mx_cohorts($deals, 6);
    $hr = [];
    foreach ($co as $ym => $steps) {
        $n = count($steps['lead'] ?? []);
        $row = [chart_month_label($ym, true), ['h' => mx_a((string)$n, $steps['lead'] ?? [], 'Leads de ' . chart_month_label($ym, true)), 'c' => (string)$n]];
        foreach (array_slice($keys, 1) as $k) {
            $m = count($steps[$k] ?? []);
            $pct = $n ? $m / $n * 100 : null;
            $row[] = ['h' => '<span class="heatc" style="--a:' . ($pct === null ? 0 : round($pct / 100, 3)) . '">' . ($n ? mx_a(mx_pct($pct), $steps[$k] ?? [], chart_month_label($ym, true) . ' · chegaram a ' . MX_STEPS[$k]) . ' <small>' . $m . '</small>' : '—') . '</span>', 'c' => $n ? mx_pct($pct) . ' (' . $m . ')' : ''];
        }
        $hr[] = $row;
    }
    echo '<h3 class="mx-h3">Coortes por mês de entrada ' . mx_ii('Cada linha são as leads que entraram nesse mês; cada coluna, a % delas que já chegou a essa etapa (até hoje). As coortes recentes ainda estão a andar.') . '</h3>';
    echo mx_table('Coortes mensais', array_merge(['Mês', '#Leads'], array_map(fn($k) => '#' . MX_STEPS[$k], array_slice($keys, 1))), $hr, [], '', '');

    // os funis lado a lado (a partir do X-Ray)
    $fr = [];
    foreach ($c['funnels'] as $fid => $fname) {
        $set = array_filter($deals, fn($d) => $d['fu'] === (int)$fid);
        $co2 = mx_funnel_cohort($set, $from, $to, 'xray');
        $n = count($co2['xray']['in']);
        if (!$n && !$set) continue;
        $won = array_filter($set, fn($d) => in_array($d['id'], $co2['kickoff']['in'], true));
        $lost = array_merge(...array_values(array_map(fn($k) => $co2[$k]['lost'], array_slice($keys, 2))));
        $cy = mx_median(array_map(fn($d) => mx_days($d['at']['xray'], $d['won_at']), array_filter($won, fn($d) => $d['won_at'])));
        $fr[] = [$fname, ['h' => mx_a((string)$n, $co2['xray']['in'], $fname . ' · entraram em X-Ray'), 'c' => (string)$n],
                 ['h' => mx_pct(mx_rate(count($co2['proposta']['in']), $n)), 'c' => mx_pct(mx_rate(count($co2['proposta']['in']), $n))],
                 ['h' => mx_pct(mx_rate(count($co2['feedback']['in']), $n)), 'c' => mx_pct(mx_rate(count($co2['feedback']['in']), $n))],
                 ['h' => '<b>' . mx_a(mx_pct(mx_rate(count($co2['kickoff']['in']), $n)), $co2['kickoff']['in'], $fname . ' · chegaram a Kickoff') . '</b>' . mx_small($n, 'casos'), 'c' => mx_pct(mx_rate(count($co2['kickoff']['in']), $n))],
                 ['h' => mx_a((string)count($lost), $lost, $fname . ' · perdidos'), 'c' => (string)count($lost)],
                 $cy === null ? '—' : mx_num($cy, 0)];
    }
    echo '<h3 class="mx-h3">Os funis lado a lado ' . mx_ii('Coorte a partir do X-Ray: dos negócios que entraram em pipeline neste período em cada funil, % que já chegou a cada etapa. Ciclo: dias do X-Ray ao Kickoff (mediana).') . '</h3>';
    echo mx_table('Funis lado a lado', ['Funil', '#Entraram em X-Ray', '#→ Proposta', '#→ Aguardar feedback', '#→ Kickoff', '#Perdidos', '#Ciclo (dias)'], $fr, [], 'Sem entradas em pipeline neste período.');

    rep_funil_value($c);
    rep_crosselling($c);
}

/** «Valor ganho · por funil / origem / mês» + tabela (mantém o bloco de antes), medido a partir do X-Ray, e o desempenho financeiro por funil. */
function rep_funil_value(array $c): void {
    $per = $c['per']; $deals = $c['deals']; $money = $c['money']; $pdo = $c['pdo'];
    [$from, $to] = [$per['from'], $per['to']];
    $seg = in_array($_GET['por'] ?? '', ['funil', 'origem', 'periodo'], true) ? (string)$_GET['por'] : 'funil';
    $segs = ['funil' => 'Por funil', 'origem' => 'Por origem da lead', 'periodo' => 'Por mês'];
    if ($seg === 'periodo') {
        $g = [];
        for ($m = new DateTimeImmutable($from); $m->format('Y-m-d') < $to; $m = $m->modify('first day of next month')) {
            $a = max($from, $m->format('Y-m-d')); $b = min($to, $m->modify('first day of next month')->format('Y-m-d'));
            $r = mx_perf($pdo, $deals, $a, $b, fn($d) => 'x', $money)['x'] ?? null;
            $g[month_name((int)$m->format('n')) . ($per['p'] === 'ano' ? '' : ' ' . $m->format('Y'))] = $r ?? ['in' => [], 'won' => [], 'lost' => [], 'value' => 0.0, 'cycle' => [], 'open' => [], 'open_value' => 0.0, 'weighted' => 0.0, 'gp' => 0.0, 'invoiced' => 0.0, 'received' => 0.0, 'gp_n' => 0];
        }
    } else {
        $g = mx_perf($pdo, $deals, $from, $to, $seg === 'funil' ? fn($d) => $d['fu'] ? ($c['funnels'][$d['fu']] ?? 'Funil removido') : null : fn($d) => LEAD_ORIGIN[$d['ch']] ?? 'Manual', $money);
        uasort($g, fn($a, $b) => $money ? [$b['gp'], $b['value']] <=> [$a['gp'], $a['value']] : [count($b['won']), count($b['in'])] <=> [count($a['won']), count($a['in'])]);
    }
    $rate = function (array $r): array {
        $w = count($r['won']); $l = count($r['lost']);
        if ($w + $l === 0) return ['h' => '—', 'c' => ''];
        if ($l === 0) return ['h' => '<span class="muted" data-tip="Sem negócios marcados como perdidos: a taxa de ganho só é real quando as perdas se registam.">sem perdas registadas</span>', 'c' => 'sem perdas registadas'];
        return ['h' => mx_pct($w / ($w + $l) * 100), 'c' => mx_pct($w / ($w + $l) * 100)];
    };
    echo '<h2 class="mx-h2" id="valor">' . ($money ? 'Valor ganho' : 'Negócios ganhos') . ' · ' . e(mb_strtolower($segs[$seg])) . '</h2>';
    echo '<nav class="views" aria-label="Agrupar">';
    foreach ($segs as $k => $l) echo '<a class="' . ($seg === $k ? 'on' : '') . '" href="' . e(mx_href(['por' => $k === 'funil' ? null : $k])) . '#valor">' . e($l) . '</a>';
    echo '</nav>';
    $bars = [];
    foreach ($g as $k => $r) {
        if (!$r['won'] && !$r['in']) continue;
        $m = $r['value'] > 0 ? $r['gp'] / $r['value'] * 100 : null;
        $bars[] = ['label' => (string)$k, 'value' => $money ? $r['value'] : count($r['won']), 'gp' => $r['gp'], 'url' => mx_href(['lista' => 'd', 'ids' => implode(',', $r['won']), 'lt' => 'Ganhos · ' . $k]),
                   'num' => $money ? eur0($r['value']) : (string)count($r['won']),
                   'tip' => $k . ' · ' . ($money ? eur0($r['value']) . ' ganho · gross profit ' . eur0($r['gp']) . ($m !== null ? ' (' . mx_pct($m) . ')' : '') : count($r['won']) . ' ganhos')];
    }
    echo '<div class="flow mx-valbars"><div class="lg">' . ($money ? '<span><i class="box" style="background:var(--c-real)"></i>gross profit</span><span><i class="box mx-light"></i>resto do valor (custos)</span><span>ordenado por gross profit</span>' : '<span>negócios que chegaram a Kickoff</span>') . '</div>'
        . ($bars ? chart_margin_bars($bars, $money) : '<p class="empty">Sem negócios ganhos neste período.</p>') . '</div>';
    $rows = []; $tot = ['in' => [], 'won' => [], 'lost' => [], 'value' => 0.0];
    foreach ($g as $k => $r) {
        if (!$r['in'] && !$r['won'] && !$r['lost'] && $seg !== 'periodo') continue;
        $rows[] = array_merge([(string)$k, ['h' => mx_a((string)count($r['in']), $r['in'], 'Entraram em pipeline · ' . $k), 'c' => (string)count($r['in'])],
                   ['h' => mx_a((string)count($r['won']), $r['won'], 'Ganhos · ' . $k), 'c' => (string)count($r['won'])],
                   ['h' => mx_a((string)count($r['lost']), $r['lost'], 'Perdidos · ' . $k), 'c' => (string)count($r['lost'])], $rate($r)],
                   $money ? [eur0($r['value'])] : []);
        foreach (['in', 'won', 'lost'] as $x) $tot[$x] = array_merge($tot[$x], $r[$x]);
        $tot['value'] += $r['value'];
    }
    $head = array_merge([$seg === 'periodo' ? 'Mês' : ($seg === 'funil' ? 'Funil' : 'Origem'), '#Entradas em pipeline', '#Ganhos', '#Perdidos', '#Taxa de ganho'], $money ? ['#Valor ganho'] : []);
    $foot = array_merge(['Total', (string)count(array_unique($tot['in'])), (string)count(array_unique($tot['won'])), (string)count(array_unique($tot['lost'])), $rate($tot)], $money ? [eur0($tot['value'])] : []);
    echo mx_table('Valor ganho · ' . $segs[$seg], $head, $rows, $foot, 'Sem movimento neste período.');
    // as leads sem funil: fora do total (a conversão lead → negócio lê-se no funil acima)
    $noFunnel = array_column(array_filter($deals, fn($d) => mx_in($d['created'], $from, $to) && $d['reach'] < 2), 'id');
    echo '<p class="small muted mx-note">Medido a partir da entrada em pipeline (X-Ray → Kickoff). ' . mx_a(count($noFunnel) . ' leads', $noFunnel, 'Leads ainda sem funil · ' . $per['label'])
        . ' deste período ainda não têm funil e ficam fora do total ' . mx_ii('Uma lead só tem funil quando passa a negócio (X-Ray). A conversão lead → negócio está no funil acima; esta tabela mede o que acontece depois.') . '. Taxa de ganho = ganhos ÷ (ganhos + perdidos) no período.</p>';

    // desempenho financeiro por funil (só Financeiro)
    if ($money && $seg === 'funil') {
        $fr = [];
        foreach ($g as $k => $r) {
            if (!$r['won'] && !$r['open']) continue;
            $n = count($r['won']);
            $cy = mx_avg($r['cycle']);
            $fr[] = [(string)$k, ['h' => mx_a(eur0($r['value']), $r['won'], 'Ganhos · ' . $k), 'c' => number_format($r['value'], 2, ',', '')],
                     $n ? eur0($r['value'] / $n) : '—', eur0($r['gp']), $r['value'] > 0 ? mx_pct($r['gp'] / $r['value'] * 100) : '—',
                     eur0($r['invoiced']), eur0($r['received']),
                     ['h' => mx_a(eur0($r['open_value']), $r['open'], 'Pipeline aberto · ' . $k), 'c' => number_format($r['open_value'], 2, ',', '')], eur0($r['weighted']),
                     $cy === null ? '—' : mx_num($cy, 0), $cy ? eur0($r['value'] / max(1, array_sum($r['cycle']) / 30.44)) : '—'];
        }
        echo '<h3 class="mx-h3">Desempenho financeiro por funil ' . mx_ii('Dos negócios que chegaram a Kickoff no período: valor (sem IVA), ticket médio (valor ÷ ganhos), gross profit (valor − custos reais: custos do negócio, despesas do Financeiro com âmbito no negócio e reservas dos sócios) e margem (gross profit ÷ valor); faturado e recebido até hoje (sem IVA). Pipeline aberto: valor dos negócios em pipeline agora; ponderado: valor × probabilidade da etapa (Definições › Pipeline). Ciclo: dias do X-Ray ao Kickoff (média); valor por mês de ciclo = valor ganho ÷ meses de ciclo somados.') . '</h3>';
        echo mx_table('Desempenho financeiro por funil', ['Funil', '#Valor ganho', '#Ticket médio', '#Gross profit', '#Margem', '#Faturado', '#Recebido', '#Pipeline aberto', '#Ponderado', '#Ciclo (dias)', '#Valor por mês de ciclo'], $fr, [], 'Sem ganhos nem pipeline aberto.');
    }
}

/** O bloco Crosselling (mantém-se, no período escolhido). */
function rep_crosselling(array $c): void {
    require_once __DIR__ . '/xsell.php';
    $money = $c['money'];
    $xr = xsell_report($c['pdo'], $c['per']['from'], $c['per']['to']);
    $xf = $xr['funnels'];
    $pct = fn(int $a, int $b) => $b > 0 ? round($a / $b * 100) . '%' : '—';
    $rate = fn(array $p) => $p[0] + $p[1] ? round($p[0] / ($p[0] + $p[1]) * 100) . '%' : '—';
    $avg = fn(float $v, int $n) => $n ? eur0($v / $n) : '—';
    $oppTot = ['open' => 0, 'won' => 0, 'lost' => 0, 'dropped' => 0, 'bundled' => 0];
    foreach ($xr['opps'] as $o) $oppTot[$o['status']] = ($oppTot[$o['status']] ?? 0) + (int)$o['n'];
    echo '<h2 class="mx-h2" id="crosselling">Crosselling <span class="muted small">· negócios com vários serviços (pacote) e serviços vendidos depois a clientes</span></h2>';
    echo '<div class="stats">'
        . mx_kpi('Ganhos com 2+ serviços', $pct($xr['won_multi'], $xr['won']), $xr['won_multi'] . ' de ' . $xr['won'] . ' negócios ganhos', '', 'Negócios ganhos no período com mais do que um serviço (frentes), sobre todos os ganhos.', '', true)
        . mx_kpi('Taxa de fecho', $rate($xr['dec_multi']), 'com pacote · isoladas ' . $rate($xr['dec_single']), '', 'Propostas decididas no período: ganhas ÷ (ganhas + perdidas), com vários serviços e com um só.')
        . ($money ? mx_kpi('Valor médio', $avg($xr['value_multi'], $xr['won_multi']), 'com pacote · isolado ' . $avg($xr['value_single'], $xr['n_single'])) : '')
        . mx_kpi('Crosselling posterior', (string)$xr['later'], $xr['later_days'] ? 'em média ' . (int)round(array_sum($xr['later_days']) / count($xr['later_days'])) . ' dias depois' : 'clientes que voltaram para outro serviço', '', 'Clientes que compraram outro serviço depois de um primeiro negócio.')
        . mx_kpi('Oportunidades', (string)array_sum($oppTot), ($oppTot['won'] + $oppTot['bundled']) . ' vendidas · ' . $oppTot['open'] . ' abertas · ' . ($oppTot['lost'] + $oppTot['dropped']) . ' não')
        . '</div>';
    $used = array_keys(array_filter($xf, fn($n, $id) => isset($xr['pairs'][$id]) || isset($xr['later_pairs'][$id]) || array_filter($xr['pairs'], fn($row) => isset($row[$id])) || array_filter($xr['later_pairs'], fn($row) => isset($row[$id])), ARRAY_FILTER_USE_BOTH));
    echo '<div class="flow-grid"><div class="tablewrap">';
    if (!$used) echo '<p class="empty">Ainda não há negócios de crosselling ganhos neste período.</p>';
    else {
        echo '<table class="xs-matrix"><caption class="muted small" style="text-align:left">Serviço × serviço: negócios ganhos em pacote' . ($xr['later_pairs'] ? ' (+ posteriores: comprou a linha, depois a coluna)' : '') . '</caption><thead><tr><th></th>';
        foreach ($used as $b) echo '<th class="num">' . e($xf[$b]) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($used as $a) {
            echo '<tr><th>' . e($xf[$a]) . '</th>';
            foreach ($used as $b) { $n = $xr['pairs'][$a][$b][0] ?? 0; $l = $xr['later_pairs'][$a][$b] ?? 0;
                echo '<td class="num' . ($a === $b ? ' muted' : '') . '">' . ($a === $b ? '·' : ($n || $l ? '<b>' . $n . '</b>' . ($l ? ' <small class="muted">+' . $l . '</small>' : '') : '<span class="muted">0</span>')) . '</td>'; }
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    $ar = [];
    foreach ($xr['anchor'] as $fid => $a) $ar[] = [$xf[$fid] ?? '—', (string)$a['won'], $pct($a['multi'], $a['won']) . ' (' . $a['multi'] . ')'];
    echo mx_table('Serviço principal', ['Serviço principal', '#Ganhos', '#Com outro serviço'], $ar, [], 'Sem negócios ganhos neste período.') . '</div>';
    $cr = [];
    foreach (array_slice($xr['combos'], 0, 10) as $co) $cr[] = array_merge([implode(' + ', array_map(fn($f) => $xf[$f] ?? '?', $co['set'])), (string)$co['won'], (string)$co['lost'], $rate([$co['won'], $co['lost']])], $money ? [eur0($co['value'])] : []);
    echo mx_table('Combinações', array_merge(['Combinações', '#Ganhos', '#Perdidos', '#Taxa de fecho'], $money ? ['#Valor ganho'] : []), $cr, [], 'Ainda não há propostas com vários serviços decididas neste período.');
    if ($xr['opps']) {
        $op = [];
        foreach ($xr['opps'] as $o) { $op[(int)$o['funnel_id']] ??= ['open' => 0, 'won' => 0, 'lost' => 0, 'dropped' => 0, 'bundled' => 0]; $op[(int)$o['funnel_id']][$o['status']] += (int)$o['n']; }
        $orr = [];
        foreach ($op as $fid => $o) $orr[] = [$xf[$fid] ?? '—', (string)$o['open'], (string)$o['bundled'], (string)$o['won'], (string)($o['lost'] + $o['dropped'])];
        echo mx_table('Oportunidades', ['Oportunidades (serviço proposto)', '#Abertas', '#No pacote', '#Ganhas depois', '#Perdidas / descartadas'], $orr);
    }
}

// ── Velocidade ──────────────────────────────────────────────

function rep_velocidade(array $c): void {
    $pdo = $c['pdo']; $per = $c['per']; $deals = $c['deals'];
    $fr = mx_first_responses($pdo, $deals, $per['from'], $per['to']);
    $frP = mx_first_responses($pdo, $deals, $per['prev_from'], $per['prev_to']);
    $s = mx_fr_stats($fr); $sp = mx_fr_stats($frP);
    $open = array_column(array_filter($fr, fn($r) => $r['h'] === null && $r['ok'] === false), 'id');
    echo '<div class="stats mx-kpis">'
        . mx_kpi('Primeira resposta', mx_h($s['median']), 'mediana em horas úteis · ' . $s['answered'] . ' respondidas' . mx_small($s['answered'], 'leads'), mx_cmp($s['median'], $sp['median'], 'down', 'mx_h'),
                 'Do registo da lead (manual ou automática) até à primeira chamada, email, WhatsApp ou reunião registada, contado só no horário de trabalho (Definições › Geral › Horário). Mediana das leads que entraram no período.', '', true)
        . mx_kpi('p90', mx_h($s['p90']), '9 em cada 10 respondidas até aqui', mx_cmp($s['p90'], $sp['p90'], 'down', 'mx_h'), 'O tempo dentro do qual se respondeu a 90% das leads (as mais lentas ficam acima).')
        . mx_kpi('Dentro do prazo', $s['sla_pct'] === null ? '—' : mx_a(mx_pct($s['sla_pct']), array_values(array_diff($s['ids'], $s['late_ids'])), 'Respondidas dentro do prazo · ' . $per['label']),
                 mx_a(count($s['late_ids']) . ' fora do prazo', $s['late_ids'], 'Fora do prazo · ' . $per['label']), mx_cmp($s['sla_pct'], $sp['sla_pct'], 'up', fn($v) => mx_pct($v), true),
                 '% das leads respondidas dentro do prazo do seu canal (Definições › Leads › Prazo da primeira resposta). As que ainda estão dentro do prazo sem resposta não contam.')
        . mx_kpi('Sem resposta, fora do prazo', mx_a((string)count($open), $open, 'Sem primeira resposta, fora do prazo'), 'leads à espera agora', '', 'Leads deste período sem nenhuma interação de saída registada e já depois do prazo.')
        . '</div>';

    // por canal
    $by = [];
    foreach ($fr as $r) $by[$r['ch']][] = $r;
    $bars = []; $rows = [];
    foreach (LEAD_ORIGIN as $ch => $label) {
        if (empty($by[$ch])) continue;
        $x = mx_fr_stats($by[$ch]);
        $sla = mx_sla($pdo, $ch);
        if ($x['answered']) $bars[] = ['label' => $label, 'median' => $x['median'], 'p90' => $x['p90'], 'sla' => $sla, 'val' => mx_h($x['median']) . ' · p90 ' . mx_h($x['p90']),
                                       'url' => mx_href(['lista' => 'd', 'ids' => implode(',', $x['ids']), 'lt' => 'Leads · ' . $label]),
                                       'tip' => $label . ' · mediana ' . mx_h($x['median']) . ' · p90 ' . mx_h($x['p90']) . ' · prazo ' . mx_h($sla) . ' · ' . mx_pct($x['sla_pct']) . ' no prazo'];
        $rows[] = [$label, ['h' => mx_a((string)$x['n'], $x['ids'], 'Leads · ' . $label), 'c' => (string)$x['n']], (string)$x['answered'], mx_h($x['median']), mx_h($x['p90']), mx_h($sla),
                   ['h' => mx_pct($x['sla_pct']) . mx_small($x['judged'], 'leads'), 'c' => mx_pct($x['sla_pct'])],
                   ['h' => mx_a((string)count($x['late_ids']), $x['late_ids'], 'Fora do prazo · ' . $label), 'c' => (string)count($x['late_ids'])]];
    }
    echo '<div class="fin-grid"><div class="flow"><h3>Por canal <span class="muted small">· horas úteis</span></h3><div class="lg"><span><i class="box" style="background:var(--c-real)"></i>mediana</span><span><i class="box mx-light"></i>p90</span><span><i style="background:var(--c-goal)"></i>prazo do canal</span></div>'
        . ($bars ? chart_speed($bars) : '<p class="empty">Ainda sem primeiras respostas registadas neste período.</p>') . '</div>';
    // velocidade × conversão
    $sc = mx_speed_conversion($fr);
    $sentence = mx_speed_sentence($sc);
    $vr = [];
    foreach ($sc as $band => $x) $vr[] = [$band, ['h' => mx_a((string)$x['n'], $x['ids'], 'Respondidas em ' . $band), 'c' => (string)$x['n']], ['h' => mx_a((string)$x['conv'], $x['conv_ids'], 'Respondidas em ' . $band . ' · passaram a pipeline'), 'c' => (string)$x['conv']],
                                          ['h' => '<b>' . mx_pct($x['rate']) . '</b>' . mx_small($x['n'], 'leads'), 'c' => mx_pct($x['rate'])]];
    echo '<div class="flow"><h3>Velocidade × conversão ' . mx_ii('Das leads que entraram no período, por tempo até à primeira resposta (horas úteis): quantas já passaram a pipeline (X-Ray). Calculado com os dados reais; com menos de 5 leads numa faixa não se tira conclusão.') . '</h3>'
        . ($sentence ? '<p class="mx-say">' . e($sentence) . '</p>' : '<p class="small muted">Ainda poucas leads para comparar as faixas (são precisas pelo menos 5 em cada lado).</p>')
        . mx_table('Velocidade × conversão', ['Primeira resposta', '#Leads', '#Passaram a pipeline', '#%'], $vr) . '</div></div>';
    echo mx_table('Primeira resposta por canal', ['Canal', '#Leads', '#Respondidas', '#Mediana', '#p90', '#Prazo', '#No prazo', '#Fora do prazo'], $rows, [], 'Sem leads neste período.');

    // por pessoa (admins veem todos; cada operador vê o seu e a média da equipa)
    $byP = [];
    foreach ($fr as $r) $byP[$r['by']][] = $r;
    $pr = [];
    foreach ($byP as $uid => $list) {
        if (!$c['admin'] && (int)$uid !== $c['uid']) continue;
        $x = mx_fr_stats($list);
        $pr[] = [rep_person($c, (int)$uid), ['h' => mx_a((string)$x['n'], $x['ids'], 'Leads · ' . rep_person($c, (int)$uid)), 'c' => (string)$x['n']], mx_h($x['median']), mx_h($x['p90']), ['h' => mx_pct($x['sla_pct']) . mx_small($x['judged'], 'leads'), 'c' => mx_pct($x['sla_pct'])]];
    }
    $team = [['h' => '<b>Média da equipa</b>', 'c' => 'Média da equipa'], (string)$s['n'], mx_h($s['median']), mx_h($s['p90']), mx_pct($s['sla_pct'])];
    echo '<h3 class="mx-h3">Por pessoa ' . mx_ii('Quem respondeu primeiro (ou o responsável, quando ainda ninguém respondeu).') . '</h3>' . mx_table('Primeira resposta por pessoa', ['Pessoa', '#Leads', '#Mediana', '#p90', '#No prazo'], $pr, $team);

    // tendência mensal
    $tr = mx_speed_trend($pdo, $deals, 6);
    $months = array_keys($tr);
    echo '<div class="fin-grid"><div class="flow"><h3>% dentro do prazo, por mês</h3>'
        . chart_lines($months, [['No prazo', array_map(fn($x) => $x['sla'], $tr), '']], fn($v) => (int)round($v) . '%', '% de leads respondidas dentro do prazo por mês', 80.0, 'objetivo 80%') . '</div>'
        . '<div class="flow"><h3>Ciclo Lead → Kickoff, por mês <span class="muted small">· mediana em dias</span></h3>'
        . chart_lines($months, [['Ciclo', array_map(fn($x) => $x['cycle'], $tr), '']], fn($v) => (string)(int)round($v), 'Duração do ciclo Lead → Kickoff por mês') . '</div></div>';
    // tempo por etapa (tendência mensal)
    $steps = array_keys(MX_STEPS);
    $tr2 = [];
    foreach ($months as $ym) {
        $a = $ym . '-01'; $b = (new DateTimeImmutable($a))->modify('+1 month')->format('Y-m-d');
        $p = mx_funnel_period($deals, $a, $b);
        $row = [chart_month_label($ym, true)];
        foreach (array_slice($steps, 0, 5) as $k) $row[] = mx_num(mx_median($p[$k]['days']));
        $row[] = (string)$tr[$ym]['cycle_n'];
        $tr2[] = $row;
    }
    echo '<h3 class="mx-h3">Tempo por etapa, por mês ' . mx_ii('Mediana dos dias que se ficou em cada etapa, das passagens à etapa seguinte que aconteceram nesse mês.') . '</h3>'
        . mx_table('Tempo por etapa', array_merge(['Mês'], array_map(fn($k) => '#' . MX_STEPS[$k] . ' (dias)', array_slice($steps, 0, 5)), ['#Kickoffs']), $tr2);
}

// ── Origens ─────────────────────────────────────────────────

function rep_origens(array $c): void {
    $pdo = $c['pdo']; $per = $c['per']; $deals = $c['deals']; $money = $c['money'];
    $g = in_array($_GET['og'] ?? '', ['canal', 'fonte', 'campanha', 'parceiro'], true) ? (string)$_GET['og'] : 'canal';
    $names = $pdo->query('SELECT id, name FROM contacts')->fetchAll(PDO::FETCH_KEY_PAIR);
    $key = match ($g) {
        'fonte'    => fn($d) => (LEAD_ORIGIN[$d['ch']] ?? 'Manual') . ' · ' . ($d['source'] !== '' ? $d['source'] : '(sem fonte)'),
        'campanha' => fn($d) => $d['campaign'] !== '' ? $d['campaign'] : null,
        'parceiro' => fn($d) => $d['partner'] ? ($names[$d['partner']] ?? 'Contacto removido') : null,
        default    => fn($d) => LEAD_ORIGIN[$d['ch']] ?? 'Manual',
    };
    $o = mx_origins($pdo, $deals, $per['from'], $per['to'], $key, $money);
    $costs = $money ? mx_acq_costs($pdo, $per['from'], $per['to']) : [];
    $chOf = array_flip(LEAD_ORIGIN);
    echo '<nav class="views" aria-label="Agrupar">';
    foreach (['canal' => 'Por canal', 'fonte' => 'Por fonte', 'campanha' => 'Por campanha', 'parceiro' => 'Parceiros e indicações'] as $k => $l) echo '<a class="' . ($g === $k ? 'on' : '') . '" href="' . e(mx_href(['og' => $k === 'canal' ? null : $k])) . '">' . e($l) . '</a>';
    echo '</nav>';
    // dispersão: volume × % para pipeline (tamanho = valor ganho)
    $pts = [];
    foreach ($o as $k => $r) {
        $n = count($r['leads']);
        if (!$n) continue;
        $pts[] = ['label' => (string)$k, 'x' => $n, 'y' => count($r['pipe']) / $n * 100, 'r' => $money ? $r['value'] : count($r['won']),
                  'url' => mx_href(['lista' => 'd', 'ids' => implode(',', $r['leads']), 'lt' => 'Leads · ' . $k]),
                  'tip' => $k . ' · ' . $n . ' leads · ' . mx_pct(count($r['pipe']) / $n * 100) . ' para pipeline · ' . count($r['won']) . ' kickoffs' . ($money ? ' · ' . eur0($r['value']) : '')];
    }
    echo '<div class="flow mx-scatter"><h3>Volume × conversão ' . mx_ii('Cada bola é uma origem: à direita, mais leads; acima, mais passam a pipeline; o tamanho é o valor ganho (ou os kickoffs, sem Financeiro). As linhas dividem pela mediana: escalar (muitas e boas), otimizar (boas mas poucas), rever (muitas mas fracas), cortar (poucas e fracas).') . '</h3>'
        . chart_scatter($pts, 'Origens: volume e conversão', 'Leads', '% para pipeline') . '</div>';
    $rows = [];
    foreach ($o as $k => $r) {
        $n = count($r['leads']); $w = count($r['won']);
        $ch = $g === 'canal' ? ($chOf[$k] ?? '') : '';
        $cost = $ch !== '' ? (float)($costs[$ch]['total'] ?? 0) : ($g === 'fonte' || $g === 'campanha' ? (float)array_sum(array_map(fn($x) => (float)($x['src'][mb_strtolower(preg_replace('/^.*· /u', '', (string)$k))] ?? 0), $costs)) : 0.0);
        $row = [(string)$k, ['h' => mx_a((string)$n, $r['leads'], 'Leads · ' . $k), 'c' => (string)$n],
                ['h' => mx_a(mx_pct(mx_rate(count($r['pipe']), $n)), $r['pipe'], 'Passaram a pipeline · ' . $k) . mx_small($n, 'leads'), 'c' => mx_pct(mx_rate(count($r['pipe']), $n))],
                ['h' => mx_a(mx_pct(mx_rate($w, $n)), $r['won'], 'Kickoffs · ' . $k), 'c' => mx_pct(mx_rate($w, $n))], (string)$w];
        if ($money) {
            $row[] = eur0($r['value']); $row[] = $w ? eur0($r['value'] / $w) : '—'; $row[] = $r['value'] > 0 ? mx_pct($r['gp'] / $r['value'] * 100) : '—';
        }
        $row[] = $r['cycle'] ? mx_num(mx_avg($r['cycle']), 0) : '—';
        if ($money) {
            $row[] = $cost > 0 ? ['h' => mx_a(eur0($cost), $ch !== '' ? ($costs[$ch]['ids'] ?? []) : [], 'Custos de aquisição · ' . $k, 'f'), 'c' => number_format($cost, 2, ',', '')] : '—';
            $row[] = $cost > 0 && $n ? eur0($cost / $n) : '—';
            $row[] = $cost > 0 && $w ? eur0($cost / $w) : '—';
            $row[] = $cost > 0 ? str_replace('.', ',', (string)round($r['gp'] / $cost, 1)) . '×' : '—';
        }
        $row[] = ['h' => '<span class="mx-mini" tabindex="0" data-tip="Índice de qualidade: conversão a kickoff × ticket médio' . ($money ? ' × margem' : '') . ', de 0 a 100 (100 = a melhor origem do período)"><span class="tr"><i style="width:' . $r['quality'] . '%"></i></span><b>' . $r['quality'] . '</b></span>', 'c' => (string)$r['quality']];
        $rows[] = $row;
    }
    $head = array_merge([['canal' => 'Canal', 'fonte' => 'Canal · fonte', 'campanha' => 'Campanha', 'parceiro' => 'Quem trouxe'][$g], '#Leads', '#% pipeline', '#% kickoff', '#Kickoffs'],
                        $money ? ['#Valor ganho', '#Ticket médio', '#Margem média'] : [], ['#Ciclo (dias)'], $money ? ['#Custo', '#Custo por lead', '#Custo por kickoff', '#Retorno'] : [], ['#Qualidade']);
    echo '<h3 class="mx-h3">Qualidade das fontes ' . mx_ii('Das leads que entraram no período por esta origem: % que passou a pipeline e a kickoff (até hoje), valor ganho, ticket médio, margem média e ciclo (dias da lead ao kickoff). Custo: despesas do Financeiro marcadas com o canal de aquisição (e a fonte/campanha) no período; retorno = gross profit ÷ custo.') . '</h3>';
    echo mx_table('Origens · ' . $g, $head, $rows, [], $g === 'parceiro' ? 'Sem leads trazidas por parceiros ou clientes neste período.' : 'Sem leads neste período.');
    if ($money && !$costs) echo '<p class="small muted">Para ver custo por lead e por kickoff, marque as despesas de marketing no Financeiro com o canal de aquisição (campo «Custo de aquisição»).</p>';
    echo '<p class="small muted">A origem tem canal, fonte e campanha (e quem trouxe); o formulário do site guarda os UTM da primeira visita (30 dias).</p>';
}

// ── Atividade ───────────────────────────────────────────────

function rep_atividade(array $c): void {
    $pdo = $c['pdo']; $per = $c['per'];
    // o gráfico semanal mostra pelo menos 8 semanas até ao fim do período
    $wFrom = min($per['from'], (new DateTimeImmutable($per['to']))->modify('-56 days')->format('Y-m-d'));
    $wFrom = (new DateTimeImmutable($wFrom))->modify('-' . ((int)(new DateTimeImmutable($wFrom))->format('N') - 1) . ' days')->format('Y-m-d');
    $acts = mx_activity($pdo, $wFrom, $per['to']);
    $user = $c['f']['pe'] ?: null;
    $now = mx_activity_agg(array_filter($acts, fn($a) => mx_in($a['at'], $per['from'], $per['to'])), $user);
    $prev = mx_activity_agg(mx_activity($pdo, $per['prev_from'], $per['prev_to']), $user);
    $weeks = mx_activity_agg($acts, $user)['week'];
    echo '<div class="stats mx-kpis mx-act">';
    foreach (MX_ACT as $k => $l) {
        $ids = $now['kind'][$k] ?? [];
        $n = count($ids); $p = count($prev['kind'][$k] ?? []);
        echo mx_kpi($l, mx_a((string)$n, $ids, $l . ' · ' . $per['label'], $k === 'proposal' || $k === 'xray' ? 'd' : 't'), '', mx_cmp($n, $p, $k === 'task_late' ? 'down' : 'up'));
    }
    echo '</div>';
    $kinds = ['call' => ['Chamadas', 'k-call'], 'email' => ['Emails', 'k-email'], 'meeting' => ['Reuniões', 'k-meet'], 'whatsapp' => ['WhatsApp', 'k-wa']];
    echo '<div class="fin-grid"><div class="flow"><h3>Contactos por semana</h3><div class="lg">';
    foreach ($kinds as [$l, $cl]) echo '<span><i class="box ' . $cl . '"></i>' . e($l) . '</span>';
    echo '</div>' . chart_stack_weeks($weeks, $kinds, 'Contactos por semana') . '</div>';
    echo '<div class="flow"><h3>Quando se trabalham as leads ' . mx_ii('Chamadas, emails, reuniões e WhatsApp registados no período, por dia da semana e hora (Lisboa).') . '</h3>' . chart_heat_dayhour($now['heat']) . '</div></div>';
    // por pessoa
    $rows = [];
    $team = array_fill_keys(array_keys(MX_ACT), 0); $nP = 0;
    foreach ($now['user'] as $uid => $byK) {
        if (!$uid) continue;
        $nP++;
        foreach (MX_ACT as $k => $l) $team[$k] += count($byK[$k] ?? []);
        if (!$c['admin'] && (int)$uid !== $c['uid']) continue;
        $row = [rep_person($c, (int)$uid)];
        foreach (MX_ACT as $k => $l) $row[] = ['h' => mx_a((string)count($byK[$k] ?? []), $byK[$k] ?? [], $l . ' · ' . rep_person($c, (int)$uid), $k === 'proposal' || $k === 'xray' ? 'd' : 't'), 'c' => (string)count($byK[$k] ?? [])];
        $rows[] = $row;
    }
    $foot = [['h' => '<b>Média da equipa</b>', 'c' => 'Média da equipa']];
    foreach (MX_ACT as $k => $l) $foot[] = $nP ? mx_num($team[$k] / $nP) : '—';
    echo '<h3 class="mx-h3">Por pessoa</h3>' . mx_table('Atividade por pessoa', array_merge(['Pessoa'], array_map(fn($l) => '#' . $l, array_values(MX_ACT))), $rows, $foot, 'Sem atividade registada neste período.');
}

// ── Equipa ──────────────────────────────────────────────────

function rep_equipa(array $c): void {
    $pdo = $c['pdo']; $per = $c['per']; $money = $c['money'];
    $acts = mx_activity($pdo, $per['from'], $per['to']);
    $team = mx_team($pdo, $c['deals'], $per['from'], $per['to'], $acts, $money);
    // metas cumpridas (pessoa) nos períodos fechados que acabaram dentro deste período
    $goals = mx_goals_status($pdo, $c['all'], $money, today_lisbon(), true);
    $met = [];
    foreach ($goals as $g) {
        if ($g['scope'] !== 'user' || $g['to'] <= $per['from'] || $g['from'] >= $per['to']) continue;
        $met[$g['scope_id']]['n'] = ($met[$g['scope_id']]['n'] ?? 0) + 1;
        if ($g['state'] === 'done') $met[$g['scope_id']]['ok'] = ($met[$g['scope_id']]['ok'] ?? 0) + 1;
    }
    $rate = fn(array $a, array $b) => mx_rate(count($a), count($b));
    $avg = ['leads' => [], 'p2' => [], 'k2' => [], 'won' => [], 'act' => [], 'fr' => [], 'value' => []];
    foreach ($team as $r) {
        $avg['leads'][] = count($r['leads']); $avg['won'][] = count($r['won']); $avg['act'][] = $r['act']; $avg['value'][] = $r['value'];
        if ($r['leads']) $avg['p2'][] = $rate($r['pipe'], $r['leads']);
        if ($r['pipe_in']) $avg['k2'][] = $rate($r['pipe_won'], $r['pipe_in']);
        if ($r['fr'] !== null) $avg['fr'][] = $r['fr'];
    }
    $mP2 = mx_avg($avg['p2']); $mK2 = mx_avg($avg['k2']);
    $bar = fn(?float $v, ?float $ref) => $v === null ? '—' : '<span class="mx-mini"><span class="tr"><i style="width:' . round(min(100, $v)) . '%"></i>' . ($ref !== null ? '<em style="left:' . round(min(100, $ref)) . '%" data-tip="Média da equipa ' . mx_pct($ref) . '"></em>' : '') . '</span><b>' . mx_pct($v) . '</b></span>';
    $rows = [];
    uasort($team, fn($a, $b) => [count($b['won']), $b['value'], count($b['leads'])] <=> [count($a['won']), $a['value'], count($a['leads'])]);
    foreach ($team as $uid => $r) {
        if (!$c['admin'] && $uid !== $c['uid']) continue;
        $who = rep_person($c, $uid);
        $p2 = $rate($r['pipe'], $r['leads']); $k2 = $rate($r['pipe_won'], $r['pipe_in']);
        $row = [$who, ['h' => mx_a((string)count($r['leads']), $r['leads'], 'Leads atribuídas · ' . $who), 'c' => (string)count($r['leads'])], mx_h($r['fr']),
                ['h' => $bar($p2, $mP2) . mx_small(count($r['leads']), 'leads'), 'c' => mx_pct($p2)], ['h' => $bar($k2, $mK2) . mx_small(count($r['pipe_in']), 'negócios'), 'c' => mx_pct($k2)],
                ['h' => mx_a((string)count($r['won']), $r['won'], 'Kickoffs · ' . $who), 'c' => (string)count($r['won'])]];
        if ($money) { $row[] = eur0($r['value']); $row[] = mx_pct($r['margin']); }
        $row[] = (string)$r['act'];
        $row[] = isset($met[$uid]) ? ($met[$uid]['ok'] ?? 0) . ' de ' . $met[$uid]['n'] : '—';
        $rows[] = $row;
    }
    $n = max(1, count($team));
    $foot = [['h' => '<b>Média da equipa</b>', 'c' => 'Média da equipa'], mx_num(array_sum($avg['leads']) / $n), mx_h(mx_median($avg['fr'])), mx_pct($mP2), mx_pct($mK2), mx_num(array_sum($avg['won']) / $n)];
    if ($money) { $foot[] = eur0(array_sum($avg['value']) / $n); $foot[] = '—'; }
    $foot[] = mx_num(array_sum($avg['act']) / $n, 0); $foot[] = '—';
    echo '<p class="sub">' . ($c['admin'] ? 'Taxas normalizadas pelo volume recebido (não só totais).' : 'Os seus números e a média da equipa (sem os nomes dos colegas).') . ' ' . mx_ii('Leads atribuídas: entraram no período com esta pessoa como responsável. Resposta: mediana da primeira resposta (horas úteis). % lead → pipeline: dessas leads, quantas passaram a X-Ray. % pipeline → kickoff: dos negócios desta pessoa que entraram em pipeline no período, quantos chegaram a Kickoff. Atividade: chamadas, emails, reuniões e WhatsApp registados. O traço em cada barra é a média da equipa.') . '</p>';
    echo mx_table('Equipa', array_merge(['Pessoa', '#Leads atribuídas', '#Resposta (mediana)', '#% lead → pipeline', '#% pipeline → kickoff', '#Kickoffs'], $money ? ['#Valor ganho', '#Margem média'] : [], ['#Atividade', '#Metas cumpridas']), $rows, $foot, 'Sem movimento neste período.');
}

// ── Operacional ─────────────────────────────────────────────

function rep_operacional(array $c): void {
    $pdo = $c['pdo']; $per = $c['per'];
    $o = mx_ops($pdo, $c['deals'], $per['from'], $per['to']);
    $op = mx_ops($pdo, $c['deals'], $per['prev_from'], $per['prev_to']);
    $inExec = array_merge([], ...array_values($o['by_stage']));
    $ot = mx_rate(count($o['on_time']), count($o['delivered'])); $otP = mx_rate(count($op['on_time']), count($op['delivered']));
    $late = array_merge([], ...array_values($o['late_by_stage']));
    echo '<div class="stats mx-kpis">'
        . mx_kpi('Em execução', mx_a((string)count($inExec), $inExec, 'Negócios em execução'), 'negócios em produção agora', '', '', '', true)
        . mx_kpi('Entregas no prazo', mx_a(mx_pct($ot), $o['on_time'], 'Entregues no prazo · ' . $per['label']), mx_a(count($o['delivered']) . ' entregues no período', $o['delivered'], 'Entregues · ' . $per['label']) . mx_small(count($o['delivered']), 'entregas'),
                 mx_cmp($ot, $otP, 'up', fn($v) => mx_pct($v), true), 'Entregas feitas no período até à data de entrega combinada ÷ entregas feitas no período (com data de entrega).')
        . mx_kpi('Kickoff → entrega', $o['k2d'] ? mx_num(mx_median($o['k2d']), 0) . ' dias' : '—', 'mediana das entregas do período', mx_cmp(mx_median($o['k2d']), mx_median($op['k2d']), 'down', fn($v) => mx_num($v, 0) . ' dias'))
        . mx_kpi('Aprovação à 1.ª ronda', $o['first_ok'] === null ? '—' : mx_a(mx_pct($o['first_ok']), $o['first_ids'], 'Peças aprovadas no Review · ' . $per['label']), $o['first_n'] . ' peças aprovadas no Review' . mx_small($o['first_n'], 'peças'), mx_cmp($o['first_ok'], $op['first_ok'], 'up', fn($v) => mx_pct($v), true), 'Peças aprovadas no período no Review com uma só ronda enviada ao cliente.')
        . mx_kpi('Tarefas atrasadas', mx_a((string)count($late), $late, 'Tarefas atrasadas', 't'), 'por fazer, depois do prazo', '')
        . '</div>';
    $bars = [];
    foreach ($o['by_stage'] as $name => $ids) $bars[$name] = count($ids);
    echo '<div class="fin-grid"><div class="flow"><h3>Em execução, por etapa</h3>';
    if (!$bars) echo '<p class="empty">Nenhum negócio em produção.</p>';
    else {
        arsort($bars);
        $max = max($bars);
        echo '<div class="hbars">';
        foreach ($bars as $name => $n) echo '<a class="hbar" href="' . e(mx_href(['lista' => 'd', 'ids' => implode(',', $o['by_stage'][$name]), 'lt' => 'Em execução · ' . $name])) . '" data-mx-list data-tip="' . e($name . ' · ' . $n) . '"><span>' . e($name) . '</span><span class="track"><i style="width:' . round($n / $max * 100, 1) . '%"></i></span><span class="num">' . $n . '</span></a>';
        echo '</div>';
    }
    echo '</div>';
    $lr = [];
    arsort($o['late_by_stage']);
    uasort($o['late_by_stage'], fn($a, $b) => count($b) <=> count($a));
    foreach ($o['late_by_stage'] as $name => $ids) $lr[] = [$name, ['h' => mx_a((string)count($ids), $ids, 'Tarefas atrasadas · ' . $name, 't'), 'c' => (string)count($ids)]];
    echo '<div class="flow"><h3>Tarefas atrasadas por etapa</h3>' . mx_table('Tarefas atrasadas por etapa', ['Etapa', '#Atrasadas'], $lr, [], 'Nenhuma tarefa atrasada.') . '</div></div>';
    $rr = [];
    $codes = $pdo->query("SELECT id, code FROM deals WHERE code IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
    usort($o['rounds'], fn($a, $b) => $b['over'] <=> $a['over']);
    foreach ($o['rounds'] as $r) $rr[] = [['h' => mx_a(($codes[$r['deal']] ?? '—') . ' · ' . ($c['all'][$r['deal']]['name'] ?? ''), [$r['deal']], 'Negócio'), 'c' => ($codes[$r['deal']] ?? '')], $r['stage'], (string)$r['used'], (string)$r['inc'], ['h' => $r['over'] ? '<b class="neg">+' . $r['over'] . '</b>' : '0', 'c' => (string)$r['over']]];
    echo '<h3 class="mx-h3">Rondas de revisão: usadas vs incluídas ' . mx_ii('Rondas registadas por etapa nos negócios em produção e nos entregues no período, contra as rondas incluídas na etapa (Definições › Funis). Excesso = usadas − incluídas.') . '</h3>'
        . mx_table('Rondas de revisão', ['Negócio', 'Etapa', '#Usadas', '#Incluídas', '#Excesso'], $rr, [], 'Sem rondas registadas.');
    $ld = [];
    foreach ($o['load'] as $uid => $l) {
        if (!$uid || (!$c['admin'] && (int)$uid !== $c['uid'])) continue;
        $ld[] = [rep_person($c, (int)$uid), ['h' => mx_a((string)count($l['deals'] ?? []), $l['deals'] ?? [], 'Em execução · ' . rep_person($c, (int)$uid)), 'c' => (string)count($l['deals'] ?? [])],
                 ['h' => mx_a((string)count($l['open'] ?? []), $l['open'] ?? [], 'Tarefas abertas · ' . rep_person($c, (int)$uid), 't'), 'c' => (string)count($l['open'] ?? [])],
                 ['h' => mx_a((string)count($l['late'] ?? []), $l['late'] ?? [], 'Tarefas atrasadas · ' . rep_person($c, (int)$uid), 't'), 'c' => (string)count($l['late'] ?? [])]];
    }
    usort($ld, fn($a, $b) => (int)$b[2]['c'] <=> (int)$a[2]['c']);
    echo '<h3 class="mx-h3">Carga por pessoa</h3>' . mx_table('Carga por pessoa', ['Pessoa', '#Negócios em execução', '#Tarefas abertas', '#Atrasadas'], $ld, [], 'Sem carga registada.');
}

// ── Financeiro (micro) ──────────────────────────────────────

function rep_financeiro(array $c): void {
    if (!$c['money']) { echo '<p class="empty">Sem acesso ao Financeiro.</p>'; return; }
    $pdo = $c['pdo']; $per = $c['per'];
    $x = mx_fin($pdo, $c['deals'], $per['from'], $per['to'], $per['prev_from'], $per['prev_to']);
    $xp = mx_fin($pdo, $c['deals'], $per['prev_from'], $per['prev_to'], $per['prev_from'], $per['prev_to']);
    $sum = fn(array $d, string $k) => array_sum(array_column($d, $k));
    $v = $sum($x['deals'], 'value'); $gp = $sum($x['deals'], 'gp'); $vp = $sum($xp['deals'], 'value'); $gpp = $sum($xp['deals'], 'gp');
    $ids = array_keys($x['deals']);
    echo '<div class="stats mx-kpis">'
        . mx_kpi('Valor ganho', mx_a(eur0($v), $ids, 'Ganhos · ' . $per['label']), count($ids) . ' negócios chegaram a Kickoff', mx_cmp($v, $vp, 'up', 'eur0'), 'Valor sem IVA dos negócios que chegaram a Kickoff no período.', '', true)
        . mx_kpi('Gross profit', eur0($gp), $v > 0 ? 'margem ' . mx_pct($gp / $v * 100) : '', mx_cmp($gp, $gpp, 'up', 'eur0'), 'Valor − custos reais (custos do negócio, despesas do Financeiro com âmbito no negócio e reservas dos sócios).')
        . mx_kpi('Faturado', eur0($sum($x['deals'], 'invoiced')), 'destes negócios, sem IVA', '', 'Faturas emitidas (sem IVA, sem as pedidas e anuladas) dos negócios ganhos no período.')
        . mx_kpi('Recebido', eur0($sum($x['deals'], 'received')), 'destes negócios, sem IVA', '', 'O que os clientes já pagaram (parte sem IVA) dos negócios ganhos no período.')
        . '</div><p class="small muted">P&amp;L, EBITDA e Net Profit ficam no Dashboard; aqui é o detalhe: onde se ganha e onde se perde margem.</p>';
    $mk = function (array $set, string $label) {
        $rows = []; $bars = [];
        foreach ($set as $k => $r) {
            $m = $r['value'] > 0 ? $r['gp'] / $r['value'] * 100 : null;
            $rows[] = [(string)$k, ['h' => mx_a(eur0($r['value']), $r['ids'], $label . ' · ' . $k), 'c' => number_format($r['value'], 2, ',', '')], eur0($r['gp']), ['h' => '<span class="' . ($m !== null && $m < 0 ? 'neg b' : '') . '">' . mx_pct($m) . '</span>', 'c' => mx_pct($m)]];
            $bars[] = ['label' => (string)$k, 'value' => $r['value'], 'gp' => $r['gp'], 'num' => mx_pct($m), 'url' => mx_href(['lista' => 'd', 'ids' => implode(',', $r['ids']), 'lt' => $label . ' · ' . $k]),
                       'tip' => $k . ' · valor ' . eur0($r['value']) . ' · gross profit ' . eur0($r['gp']) . ' · margem ' . mx_pct($m)];
        }
        return [$rows, $bars];
    };
    [$fr, $fb] = $mk($x['funnel'], 'Por funil');
    echo '<div class="fin-grid"><div class="flow"><h3>Margem por funil</h3><div class="lg"><span><i class="box" style="background:var(--c-real)"></i>gross profit</span><span><i class="box mx-light"></i>custos</span><span>número = margem</span></div>'
        . ($fb ? chart_margin_bars($fb) : '<p class="empty">Sem ganhos neste período.</p>') . '</div>';
    echo '<div class="flow"><h3>Por funil</h3>' . mx_table('Margem por funil', ['Funil', '#Valor', '#Gross profit', '#Margem'], $fr, [], 'Sem ganhos neste período.') . '</div></div>';
    [$sr] = $mk($x['service'], 'Por serviço');
    [$cr] = $mk(array_slice($x['client'], 0, 15, true), 'Por cliente');
    echo '<div class="fin-grid"><div class="flow"><h3>Por serviço ' . mx_ii('Nos negócios com várias frentes, o valor e o gross profit dividem-se pela parte de cada serviço.') . '</h3>' . mx_table('Margem por serviço', ['Serviço', '#Valor', '#Gross profit', '#Margem'], $sr, [], 'Sem ganhos.') . '</div>'
        . '<div class="flow"><h3>Top clientes</h3>' . mx_table('Top clientes', ['Cliente', '#Receita', '#Gross profit', '#Margem'], $cr, [], 'Sem ganhos.') . '</div></div>';
    // por negócio, com o orçamentado → real
    $dr = []; $br = [];
    uasort($x['deals'], fn($a, $b) => $b['value'] <=> $a['value']);
    $sheets = [];
    if ($x['deals']) {
        foreach ($pdo->query('SELECT deal_id, result_json FROM budget_sheets WHERE deal_id IN (' . implode(',', array_map('intval', array_keys($x['deals']))) . ') AND result_json IS NOT NULL') as $s) {
            $r = json_decode((string)$s['result_json'], true);
            if (!is_array($r) || empty($r['rows'])) continue;
            $b = 0.0; $real = 0.0;
            foreach ($r['rows'] as $row) { $b += (float)($row['budget'] ?? 0); $real += (float)($row['real'] ?? $row['budget'] ?? 0); }
            $sheets[(int)$s['deal_id']] = [$b, $real];
        }
    }
    foreach ($x['deals'] as $id => $d) {
        $bud = $sheets[$id][0] ?? null;
        $dr[] = [['h' => mx_a(($d['code'] ?: '—') . ' · ' . $d['name'], [$id], 'Negócio'), 'c' => ($d['code'] ?: '') . ' ' . $d['name']], eur0($d['value']), eur0($d['costs']), $bud !== null ? eur0($bud) : '—',
                 $bud !== null ? ['h' => '<span class="' . ($d['costs'] > $bud * 1.02 ? 'neg b' : '') . '">' . ($bud > 0 ? (($d['costs'] - $bud) >= 0 ? '+' : '') . mx_pct(($d['costs'] - $bud) / $bud * 100) : '—') . '</span>', 'c' => $bud > 0 ? mx_pct(($d['costs'] - $bud) / $bud * 100) : ''] : '—',
                 eur0($d['gp']), ['h' => '<span class="' . ($d['margin'] !== null && $d['margin'] < 0 ? 'neg b' : '') . '">' . mx_pct($d['margin']) . '</span>', 'c' => mx_pct($d['margin'])]];
        if ($bud !== null && count($br) < 12) {
            $diff = $d['costs'] - $bud;
            $br[] = ['label' => ($d['code'] ?: '') . ' ' . mb_strimwidth($d['name'], 0, 22, '…'), 'budget' => $bud, 'real' => $d['costs'], 'url' => mx_href(['lista' => 'd', 'ids' => (string)$id, 'lt' => 'Negócio']),
                     'num' => ($diff >= 0 ? '+' : '−') . eur0(abs($diff)), 'tip' => $d['name'] . ' · custos orçados ' . eur0($bud) . ' → reais ' . eur0($d['costs'])];
        }
    }
    echo '<h3 class="mx-h3">Por negócio ' . mx_ii('Os negócios que chegaram a Kickoff no período. Custos orçados: o total das linhas de custo da folha de orçamento (Resultado). Desvio = custos reais ÷ orçados − 1.') . '</h3>';
    if ($br) echo '<div class="flow"><h3>Custos orçados → reais</h3><div class="lg"><span><i class="box mx-light"></i>orçado</span><span><i class="box" style="background:var(--danger)"></i>derrapou</span><span><i class="box" style="background:var(--ok-fg)"></i>abaixo</span></div>' . chart_budget_real($br) . '</div>';
    echo mx_table('Margem por negócio', ['Negócio', '#Valor', '#Custos reais', '#Custos orçados', '#Desvio', '#Gross profit', '#Margem'], $dr, [], 'Sem negócios ganhos neste período.');
    // recebimentos
    $pr = [];
    foreach ($x['pay_days'] as $k => $p) $pr[] = [(string)$k, ['h' => mx_a((string)count($p['ids']), $p['ids'], 'Faturas pagas · ' . $k, 'f'), 'c' => (string)count($p['ids'])], mx_num(mx_avg($p['days']), 0) . ' dias'];
    usort($pr, fn($a, $b) => (float)$b[2] <=> (float)$a[2]);
    $or = []; $parts = ['1-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];
    foreach ($x['overdue'] as $k => $r) {
        $or[] = [(string)$k, ['h' => $r['ids'] ? mx_a((string)$r['n'], $r['ids'], 'Em atraso · ' . $k, 'f') : mx_a((string)$r['n'], $r['deals'], 'Em atraso · ' . $k), 'c' => (string)$r['n']], eur0($r['value']), $r['max_age'] . ' dias'];
    }
    require_once __DIR__ . '/fin.php';
    $ag = [];
    foreach (fin_receivables($pdo) as $r) {
        if ($r['age'] <= 0) continue;
        $b = fin_aging($r['age']);
        $ag[$b] ??= ['label' => FIN_AGING[$b] ?? $b, 'value' => 0.0, 'n' => 0, 'url' => '/admin/financeiro.php?tab=receber&aging=' . urlencode($b)];
        $ag[$b]['value'] += $r['gross']; $ag[$b]['n']++;
    }
    echo '<div class="fin-grid"><div class="flow"><h3>Prazo médio de recebimento ' . mx_ii('Dias entre a emissão e o pagamento, das faturas pagas no período, por cliente.') . '</h3>' . mx_table('Prazo médio de recebimento', ['Cliente', '#Faturas pagas', '#Prazo médio'], $pr, [], 'Sem faturas pagas no período.') . '</div>'
        . '<div class="flow"><h3>Em atraso, por cliente</h3>' . ($ag ? chart_aging_stack($ag, 'Por receber em atraso por antiguidade') : '') . mx_table('Faturas em atraso por cliente', ['Cliente', '#Faturas', '#Em atraso', '#Mais antiga'], $or, [], 'Nada em atraso.') . '</div></div>';
    // custos por categoria contra o período anterior
    $cr = [];
    $cats = $x['cats'];
    foreach ($x['cats_prev'] as $k => $p) $cats[$k] ??= ['value' => 0.0, 'ids' => []];
    uasort($cats, fn($a, $b) => $b['value'] <=> $a['value']);
    foreach ($cats as $k => $r) {
        $p = (float)($x['cats_prev'][$k]['value'] ?? 0);
        $cr[] = [(string)$k, ['h' => mx_a(eur0($r['value']), $r['ids'], 'Custos · ' . $k, 'f'), 'c' => number_format($r['value'], 2, ',', '')], eur0($p), ['h' => mx_cmp($r['value'], $p, 'down', 'eur0'), 'c' => $p ? mx_pct(($r['value'] - $p) / abs($p) * 100) : '']];
    }
    echo '<h3 class="mx-h3">Custos por categoria vs período anterior</h3>' . mx_table('Custos por categoria', ['Categoria', '#Este período', '#Anterior', '#Variação'], $cr, [], 'Sem custos registados.');
    // a Orçamentação: margem orçada vs real (os Resultados fechados do ano)
    if (can('orcamentacao')) {
        require_once __DIR__ . '/budget_view.php';
        $year = (int)substr($per['from'], 0, 4);
        $brp = budget_reports($pdo, $year);
        $mt = [];
        foreach ($brp['funnel'] as $k => $r) $mt[] = [(string)$k, (string)$r['n'], budget_pct($r['gm_b_avg']), budget_pct($r['gm_r_avg']), budget_eur($r['pm_r_avg'])];
        echo '<h3 class="mx-h3">Orçamentação · margem orçada vs real (' . $year . ') ' . budget_ii('margin', 'Aqui: médias dos Resultados fechados (negócios concluídos em ' . $year . ').') . '</h3>'
            . mx_table('Margem orçada vs real', ['Funil', '#Concluídos', '#Margem orçada', '#Margem real', '#Lucro/mês real'], $mt, [], 'Ainda não há negócios concluídos com Resultado em ' . $year . '.');
    }
}
