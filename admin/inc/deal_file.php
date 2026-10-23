<?php
/**
 * topper. admin — a ficha central do negócio (docs/ESPECIFICACAO-PLATAFORMA.md §15)
 *
 * A ficha não cria dados: cada cartão lê de outra aba pelo ID do negócio —
 * Contactos, Orçamentação, Financeiro (custos e contas), Tarefas, Drive e,
 * quando existir, Centro Criativo. «Ver detalhe» abre o painel lateral dessa
 * aba já filtrado para o negócio (ui_api.php?a=panel&type=d…). O que é dinheiro
 * (valor, custos, margens, lucro) só chega a quem vê o Financeiro.
 *
 * Etapas de produção: enquanto o funil não tiver etapas de produção próprias
 * (funnel_stages.kind = 'production'), a barra usa Pré-produção · Produção ·
 * Pós-produção · Entrega (deals.prod_step) e termina em Concluído. Quando o
 * funil as tiver, a barra passa a ser essas etapas, sem mudar nada aqui fora.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/budget_view.php';   // Orçamentação, contactos, finanças, formatos
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/reach.php';
require_once __DIR__ . '/interactions.php';
require_once __DIR__ . '/alerts.php';
require_once __DIR__ . '/lead_view.php';     // deal_people_link (Cliente, Trazido por)

const DEAL_PROD_STEPS = ['pre' => 'Pré-produção', 'prod' => 'Produção', 'post' => 'Pós-produção', 'delivery' => 'Entrega'];
const DEAL_LIST_TABS = ['producao' => 'Em produção', 'concluidos' => 'Concluídos', 'todos' => 'Todos'];

/** O negócio com cliente, «trazido por», responsável, funil e etapa. */
function deal_file_row(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT d.*, COALESCE(co.name, c.name, NULLIF(d.company, \'\')) AS client_name, COALESCE(d.company_id, d.contact_id) AS client_id,
                                bb.name AS brought_name, u.name AS owner_name, f.name AS funnel_name, s.name AS stage_name, s.kind AS stage_kind
                           FROM deals d LEFT JOIN contacts co ON co.id = d.company_id LEFT JOIN contacts c ON c.id = d.contact_id
                           LEFT JOIN contacts bb ON bb.id = d.brought_by_id LEFT JOIN users u ON u.id = d.owner_id
                           LEFT JOIN funnels f ON f.id = d.funnel_id LEFT JOIN funnel_stages s ON s.id = d.stage_id
                          WHERE d.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// ── etapas de produção ──────────────────────────────────────

/** As etapas de produção próprias do funil (kind = production), se as tiver. */
function deal_funnel_prod_stages(PDO $pdo, ?int $funnelId): array {
    static $cache = [];
    if (!$funnelId) return [];
    return $cache[$funnelId] ??= array_values(array_filter(funnel_stages($pdo, $funnelId), fn($s) => $s['kind'] === 'production'));
}

/**
 * A barra de etapas: [mode => step|stage, items => [[key, label, state (done|now|next)]], now => rótulo].
 * Só tem sentido a partir do Kickoff (produção e concluídos).
 */
function deal_steps(PDO $pdo, array $deal): array {
    $stages = deal_funnel_prod_stages($pdo, $deal['funnel_id'] ? (int)$deal['funnel_id'] : null);
    $items = [];
    if ($stages) {
        foreach ($stages as $s) $items[] = ['key' => 's' . $s['id'], 'label' => $s['name']];
        $cur = 0;
        foreach ($stages as $i => $s) if ((int)$s['id'] === (int)$deal['stage_id']) $cur = $i;
        $mode = 'stage';
    } else {
        foreach (DEAL_PROD_STEPS as $k => $l) $items[] = ['key' => $k, 'label' => $l];
        $cur = max(0, (int)array_search((string)($deal['prod_step'] ?: 'pre'), array_keys(DEAL_PROD_STEPS), true));
        $mode = 'step';
    }
    $items[] = ['key' => 'done', 'label' => 'Concluído'];
    $done = $deal['phase'] === 'done';
    foreach ($items as $i => &$it) $it['state'] = $done ? 'done' : ($i < $cur ? 'done' : ($i === $cur ? 'now' : 'next'));
    unset($it);
    return ['mode' => $mode, 'items' => $items, 'now' => $done ? 'Concluído' : $items[$cur]['label']];
}

/** O rótulo da etapa para as listas. */
function deal_step_label(PDO $pdo, array $deal): string {
    if ($deal['phase'] === 'done') return 'Concluído';
    if ($deal['phase'] !== 'production') return (string)($deal['stage_name'] ?? '—');
    // Crosselling (§22): onde está cada frente.
    require_once __DIR__ . '/xsell_view.php';
    if (xsell_is_multi($pdo, $deal)) return xsell_lanes_short($pdo, $deal);
    return deal_steps($pdo, $deal)['now'];
}

// ── prazos ──────────────────────────────────────────────────

/** A entrega prevista, pela folha de orçamento (datas, ou início/Kickoff + dias úteis). Null sem orçamento. */
function deal_delivery_date(PDO $pdo, array $deal): ?string {
    $sheet = budget_sheet_get($pdo, (int)$deal['id']);
    $v = $sheet ? budget_current_version($pdo, $sheet) : null;
    if (!$v) return null;
    if ($v['dur_mode'] === 'dates' && $v['end_date']) return (string)$v['end_date'];
    $hasLines = (int)$pdo->query('SELECT COUNT(*) FROM budget_lines WHERE version_id = ' . (int)$v['id'])->fetchColumn() > 0;
    if (!$hasLines) return null;
    $days = budget_duration_days($v);
    if ($days <= 0) return null;
    $start = $v['start_date'] ?: budget_kickoff_date($pdo, (int)$deal['id']);
    return $start ? budget_add_workdays((string)$start, $days) : null;
}

// ── fecho do negócio (§15.5) ────────────────────────────────

/**
 * A checklist de fecho: [items => [[key, label, ok, detail]], complete, missing].
 * Não mostra valores (só contagens), por isso serve a quem não vê o Financeiro.
 */
function deal_checklist(PDO $pdo, array $deal): array {
    $id = (int)$deal['id'];
    $c = $pdo->query("SELECT COUNT(*), COALESCE(SUM(is_closed), 0) FROM costs WHERE deal_id = $id")->fetch(PDO::FETCH_NUM);
    $sup = $pdo->query("SELECT COUNT(*), COALESCE(SUM(EXISTS (SELECT 1 FROM contact_ratings r WHERE r.cost_id = co.id)), 0)
                          FROM costs co JOIN contacts ct ON ct.id = co.contact_id
                         WHERE co.deal_id = $id AND co.is_closed = 1 AND (ct.is_supplier = 1 OR ct.is_freelancer = 1)")->fetch(PDO::FETCH_NUM);
    $sheet = budget_sheet_get($pdo, $id);
    $hasBudget = $sheet && budget_has_lines($pdo, $sheet);
    $items = [
        ['key' => 'costs', 'label' => 'Custos todos pagos', 'ok' => (int)$c[0] === (int)$c[1], 'detail' => (int)$c[0] ? (int)$c[1] . '/' . (int)$c[0] : 'sem custos'],
        ['key' => 'paid', 'label' => 'Cliente pagou', 'ok' => (int)$deal['is_paid'] === 1, 'detail' => (int)$deal['is_paid'] ? 'sim' : 'ainda não'],
        ['key' => 'delivered', 'label' => 'Entregas feitas', 'ok' => !empty($deal['delivered_at']),
         'detail' => $deal['delivered_at'] ? date('d/m/Y', strtotime((string)$deal['delivered_at'])) : 'por confirmar'],
        ['key' => 'rated', 'label' => 'Fornecedores avaliados', 'ok' => (int)$sup[0] === (int)$sup[1], 'detail' => (int)$sup[0] ? (int)$sup[1] . '/' . (int)$sup[0] : 'nenhum a avaliar'],
        ['key' => 'result', 'label' => 'Resultado fechado', 'ok' => $sheet && $sheet['closed_at'] || !$hasBudget,
         'detail' => $sheet && $sheet['closed_at'] ? 'fechado a ' . date('d/m/Y', strtotime((string)$sheet['closed_at'])) : ($hasBudget ? 'fecha ao concluir' : 'sem orçamento na plataforma')],
    ];
    $missing = array_values(array_filter($items, fn($i) => !$i['ok']));
    return ['items' => $items, 'complete' => !$missing, 'missing' => $missing];
}

/** A folha tem linhas de custo (os negócios anteriores à plataforma não têm, §15.6). */
function budget_has_lines(PDO $pdo, array $sheet): bool {
    $v = budget_current_version($pdo, $sheet);
    return $v && (int)$pdo->query('SELECT COUNT(*) FROM budget_lines WHERE version_id = ' . (int)$v['id'])->fetchColumn() > 0;
}

// ── números do negócio ──────────────────────────────────────

/** Tarefas do negócio: abertas, feitas, atrasadas e as próximas. */
function deal_task_stats(PDO $pdo, int $dealId): array {
    $st = $pdo->prepare('SELECT t.*, tm.name AS who FROM tasks t LEFT JOIN team_members tm ON tm.id = t.member_id WHERE t.deal_id = ?
                          ORDER BY t.status = \'done\', t.due_date IS NULL, t.due_date, t.id');
    $st->execute([$dealId]);
    $all = $st->fetchAll();
    $open = array_values(array_filter($all, fn($t) => $t['status'] !== 'done'));
    $late = array_filter($open, fn($t) => $t['due_date'] && $t['due_date'] < today_lisbon());
    return ['all' => $all, 'open' => $open, 'done' => count($all) - count($open), 'late' => count($late)];
}

/**
 * As contas do negócio vistas pela ficha (só com Financeiro): valor s/ IVA, custos reais,
 * margem real vs a meta da casa (a margem por defeito da Orçamentação), recebido, lucro
 * e lucro por mês quando há prazo (o real, ou o da folha de orçamento).
 */
function deal_figures(PDO $pdo, array $deal): array {
    $rate = vat_rate($pdo);
    $costs = deal_costs($pdo, (int)$deal['id'], $rate);
    $m = deal_money($deal, $costs, $rate);
    $value = (float)$m['budget_net'];
    $sheet = budget_sheet_get($pdo, (int)$deal['id']);
    $hasBudget = $sheet && budget_has_lines($pdo, $sheet);
    $calc = null;
    if ($hasBudget) {
        $v = budget_current_version($pdo, $sheet);
        $calc = budget_calc($pdo, $v, budget_lines($pdo, (int)$v['id']));
        if ($value <= 0) $value = $calc['price'];   // ainda sem valor no Financeiro: o do orçamento aceite
    }
    $profit = round($value - (float)$m['expenses'], 2);
    $margin = $value > 0 ? round($profit / $value * 100, 1) : null;
    $days = null;
    $start = $hasBudget ? (budget_current_version($pdo, $sheet)['start_date'] ?: budget_kickoff_date($pdo, (int)$deal['id'])) : budget_kickoff_date($pdo, (int)$deal['id']);
    if ($deal['phase'] === 'done' && $start && $deal['done_at']) $days = budget_workdays((string)$start, substr((string)$deal['done_at'], 0, 10));
    elseif ($calc) $days = (int)round($calc['days']);
    $months = $days ? max($days, 1) / BUDGET_MONTH_DAYS : null;
    $open = array_filter($costs, fn($c) => !(int)$c['is_closed']);
    return [
        'costs' => $costs, 'm' => $m, 'value' => $value, 'value_from' => (float)$m['budget_net'] > 0 ? 'financeiro' : ($calc ? 'orcamento' : 'nenhum'),
        'expenses' => (float)$m['expenses'], 'profit' => $profit, 'margin' => $margin,
        'target' => budget_setting($pdo, 'budget_margin_pct'), 'received' => (int)$deal['is_paid'] ? 100 : 0,
        'days' => $days, 'profit_month' => $months ? round($profit / $months, 2) : null, 'calc' => $calc, 'has_budget' => $hasBudget,
        'open_costs' => count($open), 'open_costs_value' => round(array_sum(array_column($open, 'net')), 2),
        'vat_in' => (float)$m['vat_in'], 'international' => (int)$deal['is_international'] === 1, 'has_vat' => (int)$deal['has_vat'] === 1,
    ];
}

/** «Precisa de atenção» (§15.3): chips automáticos [texto, classe g|o|r|'' , dica]. */
function deal_attention(PDO $pdo, array $deal, ?array $fig, array $tasks, array $check, ?string $delivery): array {
    $out = [];
    $today = today_lisbon();
    $active = $deal['phase'] === 'production';
    if ($fig) {
        if ($fig['margin'] !== null && $fig['margin'] < $fig['target']) {
            $out[] = ['Margem ' . budget_n(round($fig['target'] - $fig['margin'], 1), 1) . ' pp abaixo da meta', $fig['margin'] < $fig['target'] * 0.75 ? 'r' : 'o', 'Margem real ' . budget_pct($fig['margin']) . ' · meta da casa ' . budget_pct($fig['target'])];
        }
        if (!(int)$deal['is_paid'] && $fig['value'] > 0) {
            $rec = array_values(array_filter(receivables($pdo), fn($r) => (int)$r['id'] === (int)$deal['id']));
            if ($rec && $rec[0]['age'] > 0) $out[] = ['Fatura em atraso há ' . $rec[0]['age'] . ' dias', 'r', 'Por receber ' . budget_eur($rec[0]['gross']) . ($rec[0]['legacy'] ? ' · sem fatura registada' : ' · ' . $rec[0]['number'])];
        }
        if ($fig['open_costs']) $out[] = [$fig['open_costs'] . ($fig['open_costs'] === 1 ? ' custo por pagar' : ' custos por pagar'), 'o', budget_eur($fig['open_costs_value']) . ' s/ IVA'];
        if ((int)$deal['is_paid'] && !$fig['open_costs'] && $fig['costs']) $out[] = ['Tudo pago e recebido', 'g', 'O cliente pagou e todos os custos estão fechados'];
    }
    if ($delivery && $active && !$deal['delivered_at']) {
        $d = -days_since($delivery);
        if ($d < 0) $out[] = ['Entrega atrasada ' . -$d . ($d === -1 ? ' dia' : ' dias'), 'r', 'Entrega prevista a ' . date('d/m/Y', strtotime($delivery)) . ' (folha de orçamento)'];
        elseif ($d <= 7) $out[] = [$d === 0 ? 'Entrega hoje' : 'Entrega em ' . $d . ($d === 1 ? ' dia' : ' dias'), 'o', 'Entrega prevista a ' . date('d/m/Y', strtotime($delivery))];
    }
    if ($tasks['late']) $out[] = [$tasks['late'] . ($tasks['late'] === 1 ? ' tarefa atrasada' : ' tarefas atrasadas'), 'r', 'Ver em Produção e tarefas'];
    $toRate = array_values(array_filter($check['items'], fn($i) => $i['key'] === 'rated'))[0];
    if (!$toRate['ok']) {
        [$r, $n] = array_map('intval', explode('/', $toRate['detail']));
        $out[] = ['Avaliar ' . ($n - $r) . ($n - $r === 1 ? ' fornecedor' : ' fornecedores'), 'o', 'Como correu o trabalho (fica em Contactos)'];
    }
    $sheet = budget_sheet_get($pdo, (int)$deal['id']);
    if (!$sheet || !budget_has_lines($pdo, $sheet)) $out[] = ['Sem orçamento na plataforma', '', $deal['created_at'] < '2026-10-21' ? 'Negócio anterior à plataforma: o Resultado usa o valor e os custos existentes' : 'A folha da Orçamentação ainda não tem linhas'];
    if ($deal['phase'] === 'done' && !$check['complete']) $out[] = ['Fecho incompleto: ' . count($check['missing']) . ($check['missing'] && count($check['missing']) === 1 ? ' item' : ' itens'), 'r', 'Conta como «Em risco» até fechar: ' . implode(', ', array_map(fn($i) => mb_strtolower($i['label']), $check['missing']))];
    return $out;
}

/** «Em risco» nas listas: tarefas atrasadas, entrega atrasada, ou concluído com o fecho incompleto. */
function deal_is_risk(PDO $pdo, array $deal, int $lateTasks, ?string $delivery): bool {
    if ($lateTasks > 0) return true;
    if ($deal['phase'] === 'production' && $delivery && $delivery < today_lisbon() && !$deal['delivered_at']) return true;
    return $deal['phase'] === 'done' && !deal_checklist($pdo, $deal)['complete'];
}

// ── Drive (ficheiros e documentos) ──────────────────────────

/**
 * A pasta do negócio no Drive, sem a criar: [folder, files (objetos), by_type, last, docs]. Null
 * sem Drive ligado ou sem acesso à aba Drive. Os documentos separam-se pelo nome e pela pasta:
 * contratuais (proposta, contrato, orçamento), fiscais (faturas emitidas) e de fornecedores.
 */
function deal_drive_summary(PDO $pdo, array $deal): ?array {
    if (!can('drive')) return null;
    require_once __DIR__ . '/deal_drive.php';
    if (!r2_config()) return ['off' => true];
    try {
        $p = (string)$deal['drive_folder'];
        if ($p === '') {
            foreach (r2_list('Projetos/')['folders'] ?? [] as $f) {
                $leaf = substr(rtrim($f, '/'), strlen('Projetos/'));
                if (str_starts_with($leaf, $deal['code'] . '_') || $leaf === $deal['code']) { $p = 'Projetos/' . $leaf . '/'; break; }
            }
        }
        if ($p === '') return ['folder' => null, 'files' => [], 'by_type' => [], 'last' => null, 'docs' => ['contract' => [], 'fiscal' => [], 'supplier' => []]];
        $cat = drive_catalog_cached();
        $objs = $cat ? array_filter($cat['objects'], fn($o) => str_starts_with($o['key'], $p)) : r2_list_all($p, 20);
    } catch (Throwable $e) {
        error_log('deal_drive_summary: ' . $e->getMessage());
        return ['error' => true];
    }
    // a faturação só para quem vê o Financeiro (§10.15)
    $files = array_values(array_filter($objs, fn($o) => substr($o['key'], -1) !== '/' && drive_can_see($o['key'])));
    usort($files, fn($a, $b) => (int)$b['modified'] <=> (int)$a['modified']);
    $by = []; $docs = ['contract' => [], 'fiscal' => [], 'supplier' => []];
    foreach ($files as $o) {
        $name = basename($o['key']);
        $by[drive_kind($name, false)[0]] = ($by[drive_kind($name, false)[0]] ?? 0) + 1;
        $rel = mb_strtolower(substr($o['key'], strlen($p)));
        if (preg_match('/fornecedor|supplier|freelanc/u', $rel)) $docs['supplier'][] = $o;
        elseif (preg_match('/fatura|factura|invoice|recibo|\bft[ _-]?\d/u', $rel)) $docs['fiscal'][] = $o;
        elseif (preg_match('/propost|contrat|or[cç]amento|nda|acordo/u', $rel)) $docs['contract'][] = $o;
    }
    arsort($by);
    return ['folder' => $p, 'files' => $files, 'by_type' => $by, 'last' => $files[0] ?? null, 'docs' => $docs];
}

// ── histórico de todas as fontes ────────────────────────────

/**
 * A linha do tempo do negócio com tudo: interações e etapas (deal_history), a folha de
 * orçamento, as tarefas (criadas e feitas) e, para quem vê o Financeiro, os custos.
 */
function deal_timeline_all(PDO $pdo, array $deal, bool $money, int $limit = 200): array {
    $id = (int)$deal['id'];
    $out = timeline($pdo, 'deal', $id, 400);
    if (can('orcamentacao') && ($sheet = budget_sheet_get($pdo, $id))) {
        foreach (budget_history($pdo, (int)$sheet['id']) as $h) {
            if (!$money && $h['event'] === 'b_margin') continue;
            $note = (string)$h['note'];
            if (!$money && in_array($h['event'], ['b_accept'], true)) $note = preg_replace('/ · [\d\s]+ € s\/ IVA$/u', '', $note);
            $out[] = ['when' => $h['created_at'], 'type' => isset(HISTORY_TYPES[$h['event']]) ? $h['event'] : 'state', 'title' => 'Orçamento · ' . (HISTORY_TYPES[$h['event']][0] ?? $h['event']),
                      'text' => $note, 'who' => $h['who'] ?? 'Sistema', 'sys' => true];
        }
    }
    if (can('tarefas')) {
        $st = $pdo->prepare("SELECT t.title, t.status, t.done_at, t.created_at, tm.name AS who FROM tasks t LEFT JOIN team_members tm ON tm.id = t.member_id WHERE t.deal_id = ?");
        $st->execute([$id]);
        foreach ($st->fetchAll() as $t) {
            if ($t['status'] === 'done' && $t['done_at']) $out[] = ['when' => $t['done_at'], 'type' => 'task_done', 'title' => 'Tarefa concluída', 'text' => (string)$t['title'], 'who' => $t['who'] ?? '—', 'sys' => true];
        }
    }
    if ($money) {
        $st = $pdo->prepare('SELECT c.*, ct.name AS contact_name, u.name AS who FROM costs c LEFT JOIN contacts ct ON ct.id = c.contact_id LEFT JOIN users u ON u.id = c.created_by WHERE c.deal_id = ?');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $c) {
            if (!$c['created_at']) continue;
            $out[] = ['when' => $c['created_at'], 'type' => 'cost', 'title' => 'Custo registado', 'who' => $c['who'] ?? 'Sistema', 'sys' => true,
                      'text' => trim(($c['contact_name'] ?: $c['name']) . ' · ' . ($c['role'] ?: '') . ' · ' . budget_eur(cost_amount($c), 2) . ((int)$c['is_closed'] ? ' · pago' : ''), ' ·')];
        }
    }
    usort($out, fn($a, $b) => strcmp((string)$b['when'], (string)$a['when']));
    return array_slice($out, 0, $limit);
}

// ── cartões ─────────────────────────────────────────────────

/** Um cartão da ficha: cabeçalho com ícone, contagem e «›» para o painel; corpo; «Ver detalhe». */
function deal_card(string $key, string $icon, string $color, string $title, string $body, array $o = []): string {
    $panel = $o['panel'] ?? '';
    $more = $o['more'] ?? 'Ver detalhe';
    $href = $o['href'] ?? '#';
    $go = $panel !== '' ? '<a class="dc-go" href="' . e($href) . '" data-panel="' . e($panel) . '" aria-label="' . e($more . ' · ' . $title) . '" data-tip="' . e($more) . '">' . icon('chev-right') . '</a>'
        : ($href !== '#' ? '<a class="dc-go" href="' . e($href) . '" aria-label="' . e($more . ' · ' . $title) . '" data-tip="' . e($more) . '">' . icon('chev-right') . '</a>' : '');
    return '<section class="dc' . (!empty($o['wide']) ? ' wide' : '') . (!empty($o['empty']) ? ' empty-st' : '') . '" id="c-' . e($key) . '">'
         . '<h3><span class="dc-i c-' . e($color) . '" aria-hidden="true">' . icon($icon) . '</span>' . e($title)
         . (isset($o['count']) && $o['count'] !== '' ? '<span class="dc-n">' . e((string)$o['count']) . '</span>' : '') . $go . '</h3>'
         . '<div class="dc-b">' . $body . '</div>'
         . ($panel !== '' ? '<a class="dc-more" href="' . e($href) . '" data-panel="' . e($panel) . '">' . e($more) . '</a>'
                          : ($href !== '#' && !empty($o['more']) ? '<a class="dc-more" href="' . e($href) . '">' . e($more) . '</a>' : ''))
         . '</section>';
}

/** Linhas «rótulo · valor» dos cartões. */
function deal_kv(array $rows): string {
    $h = '<dl class="dkv">';
    foreach ($rows as $r) {
        if ($r === null) continue;
        [$k, $v] = $r;
        $h .= '<dt>' . e($k) . '</dt><dd' . (isset($r[2]) ? ' class="' . e($r[2]) . '"' : '') . '>' . $v . '</dd>';
    }
    return $h . '</dl>';
}

/** A checklist de fecho em HTML (cartão, painel e formulário de concluir). */
function deal_checklist_html(array $check): string {
    $h = '<ul class="dchk">';
    foreach ($check['items'] as $i) {
        $h .= '<li class="' . ($i['ok'] ? 'ok' : 'no') . '"><span class="dchk-i" aria-hidden="true">' . icon($i['ok'] ? 'check' : 'alert') . '</span>'
            . '<span>' . e($i['label']) . '</span><b>' . e($i['detail']) . '</b></li>';
    }
    return $h . '</ul>';
}

// ── painéis laterais «Ver detalhe» (§15.2) ──────────────────

/**
 * O painel de um cartão, já filtrado para este negócio. Cada um confirma a permissão da aba
 * de origem. Devolve [title, html, url] ou lança RuntimeException sem permissão.
 */
function deal_panel(PDO $pdo, string $type, array $deal): array {
    $id = (int)$deal['id'];
    $money = can('financeiro');
    $self = '/admin/negocio.php?id=' . $id;
    $head = '<header><div class="id mono">' . e($deal['code']) . '</div><h2>' . e($deal['name']) . '</h2></header>';
    $go = fn(string $url) => e(nav_to($url, $self));   // para outra aba, o «Voltar» de lá traz de volta à ficha
    switch ($type) {
        case 'dcontacts':
            require_perm('contactos', PERM_VIEW);
            $st = $pdo->prepare('SELECT c.*, dc.role AS link_role FROM deal_contacts dc JOIN contacts c ON c.id = dc.contact_id WHERE dc.deal_id = ? ORDER BY c.name');
            $st->execute([$id]);
            $people = $st->fetchAll();
            $li = '';
            foreach ($people as $c) {
                $li .= '<li><a href="' . $go('/admin/contact.php?id=' . (int)$c['id']) . '" data-panel="contact:' . (int)$c['id'] . '">' . e($c['name']) . '</a>'
                     . ' <span class="muted">' . e(trim(($c['link_role'] ?: $c['role']) . ($c['email'] ? ' · ' . $c['email'] : '') . ($c['phone'] ? ' · ' . $c['phone'] : ''), ' ·')) . '</span></li>';
            }
            $others = [];
            if ($deal['client_id']) {
                $st = $pdo->prepare('SELECT * FROM deals WHERE id <> ? AND code IS NOT NULL AND (contact_id = ? OR company_id = ?) ORDER BY code DESC LIMIT 12');
                $st->execute([$id, $deal['client_id'], $deal['client_id']]);
                foreach ($st->fetchAll() as $o) if (record_reach($pdo, $o)) $others[] = $o;
            }
            $oh = '';
            foreach ($others as $o) $oh .= '<li><a href="' . $go(deal_url($o)) . '"><span class="mono">' . e($o['code']) . '</span> ' . e($o['name']) . '</a> <span class="muted">· ' . e(['pipeline' => 'Pipeline', 'production' => 'Em produção', 'done' => 'Concluído', 'lost' => 'Perdido'][$o['phase']] ?? '') . '</span></li>';
            $html = $head . '<section><h4>Cliente <span class="h-sub">quem paga</span></h4><p>' . deal_people_link($pdo, $deal['contact_id']) . '</p></section>'
                  . '<section><h4>Trazido por</h4><p>' . deal_people_link($pdo, $deal['brought_by_id']) . '</p></section>'
                  . '<section><h4>Pessoas do negócio <span class="mono">' . count($people) . '</span></h4>' . ($li ? '<ul class="ppl">' . $li . '</ul>' : '<p class="muted">Sem pessoas ligadas.</p>') . '</section>'
                  . '<section><h4>Outros negócios do cliente <span class="mono">' . count($others) . '</span></h4>' . ($oh ? '<ul class="ppl">' . $oh . '</ul>' : '<p class="muted">Nenhum.</p>') . '</section>'
                  . (can('negocios', PERM_EDIT) ? '<p><a class="btn ghost sm" href="' . e($self) . '&amp;vista=dados#contactos">' . icon('pencil') . ' Ligar ou desligar pessoas</a></p>' : '');
            return ['title' => 'Cliente e contactos', 'html' => $html, 'url' => $self . '&vista=dados'];

        case 'dbudget':
            require_perm('orcamentacao', PERM_VIEW);
            $sheet = budget_sheet_ensure($pdo, $deal);
            $vers = budget_versions($pdo, (int)$sheet['id']);
            $rows = '';
            foreach ($vers as $v) {
                $acc = (int)$sheet['accepted_version_id'] === (int)$v['id'];
                $rows .= '<tr><td><a href="' . $go('/admin/orcamento.php?id=' . $id . '&v=' . (int)$v['num']) . '">v' . (int)$v['num'] . '</a>' . ($acc ? ' <span class="chip wait">aceite</span>' : '') . '</td>'
                       . '<td class="small muted">' . e(date('d/m/Y', strtotime((string)$v['created_at'])) . ($v['author'] ? ' · ' . $v['author'] : '')) . '</td>'
                       . '<td class="num">' . ((float)$v['price'] > 0 ? e(budget_eur((float)$v['price'])) : '<span class="muted">—</span>') . '</td></tr>';
            }
            $cur = budget_current_version($pdo, $sheet);
            $calc = budget_calc($pdo, $cur, $lines = budget_lines($pdo, (int)$cur['id']));
            $lh = '';
            foreach ($lines as $l) $lh .= '<li><b>' . e($l['label']) . '</b> <span class="muted">· ' . e(budget_line_who($l) . ' · ' . budget_qty_label($l)) . '</span>' . ($money || $l['kind'] === 'other' ? ' <span class="mono">' . e(budget_eur((float)$l['cost'])) . '</span>' : '') . '</li>';
            $html = $head . '<section><h4>Versões</h4><table class="src-t"><tbody>' . $rows . '</tbody></table></section>'
                  . '<section><h4>Linhas de custo · v' . (int)$cur['num'] . '</h4>' . ($lh ? '<ul class="ppl">' . $lh . '</ul>' : '<p class="muted">Sem linhas: sem orçamento na plataforma.</p>') . '</section>'
                  . ($lines ? '<section><h4>Preço</h4><div class="kv"><span>Preço s/ IVA</span><div class="mono">' . e(budget_eur($calc['price'])) . '</div>'
                            . ($money ? '<span>Margem</span><div class="mono">' . e(budget_pct($calc['margin'])) . '</div><span>Lucro por mês</span><div>' . budget_status_chip($calc['status'], $calc['profit_month']) . '</div>' : '')
                            . '<span>Duração</span><div class="mono">' . e(budget_n($calc['days'], 1)) . ' dias úteis</div></div></section>' : '')
                  . '<p><a class="btn sm" href="' . $go('/admin/orcamento.php?id=' . $id) . '">' . icon('orcamentacao') . ' Abrir a folha</a></p>';
            return ['title' => 'Orçamento', 'html' => $html, 'url' => nav_to('/admin/orcamento.php?id=' . $id, $self)];

        case 'dresult':
            require_perm('financeiro', PERM_VIEW);
            $fig = deal_figures($pdo, $deal);
            $html = $head;
            if ($fig['has_budget'] && can('orcamentacao')) {
                $sheet = budget_sheet_get($pdo, $id);
                $r = $sheet['closed_at'] && $sheet['result_json'] ? json_decode((string)$sheet['result_json'], true) : budget_result($pdo, $sheet, $deal);
                $html .= '<div class="bs">' . budget_result_html($r, $deal) . '</div>';
            } else {
                $html .= '<section><h4>Resultado <span class="h-sub">sem orçamento na plataforma: valor e custos existentes</span></h4><div class="kv">'
                       . '<span>Preço s/ IVA</span><div class="mono">' . e(budget_eur($fig['value'])) . '</div>'
                       . '<span>Custos</span><div class="mono">' . e(budget_eur($fig['expenses'])) . '</div>'
                       . '<span>Gross profit</span><div class="mono">' . e(budget_eur($fig['profit'])) . '</div>'
                       . '<span>Gross margin</span><div class="mono">' . e(budget_pct($fig['margin'])) . ' <span class="muted">· meta ' . e(budget_pct($fig['target'])) . '</span></div>'
                       . ($fig['profit_month'] !== null ? '<span>Lucro por mês</span><div class="mono">' . e(budget_eur($fig['profit_month'])) . '</div>' : '')
                       . '</div></section>';
            }
            return ['title' => 'Resultado', 'html' => $html, 'url' => $fig['has_budget'] ? '/admin/orcamento.php?id=' . $id . '&tab=res' : $self . '&vista=contas'];

        case 'dcosts':
            require_perm('financeiro', PERM_VIEW);
            $fig = deal_figures($pdo, $deal);
            $rated = $fig['costs'] ? $pdo->query('SELECT cost_id, stars FROM contact_ratings WHERE cost_id IN (' . implode(',', array_map(fn($c) => (int)$c['id'], $fig['costs'])) . ')')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
            $rows = '';
            foreach ($fig['costs'] as $c) {
                $rows .= '<tr><td><b>' . e($c['contact_name'] ?: ($c['name'] ?: '—')) . '</b><small>' . e($c['role'] ?: '') . (isset($rated[(int)$c['id']]) ? ' · ' . str_repeat('★', (int)$rated[(int)$c['id']]) : '') . '</small></td>'
                       . '<td class="num">' . e(budget_eur((float)$c['net'], 2)) . '</td><td class="num">' . e(budget_eur((float)$c['vat'], 2)) . '</td>'
                       . '<td>' . ((int)$c['is_closed'] ? '<span class="chip ok">pago</span>' : '<span class="chip wait">por pagar</span>') . '</td></tr>';
            }
            $html = $head . '<section><h4>Equipa e fornecedores <span class="mono">' . count($fig['costs']) . '</span></h4>'
                  . ($rows ? '<table class="src-t"><thead><tr><th>Quem</th><th class="num">s/ IVA</th><th class="num">IVA</th><th>Estado</th></tr></thead><tbody>' . $rows . '</tbody></table>'
                           . '<p class="muted small">Total ' . e(budget_eur($fig['expenses'], 2)) . ' s/ IVA · IVA suportado ' . e(budget_eur($fig['vat_in'], 2)) . ' (a deduzir no Financeiro).</p>'
                           : '<p class="muted">Sem custos registados.</p>') . '</section>'
                  . (can('financeiro', PERM_EDIT) ? '<p><a class="btn ghost sm" href="' . e($self) . '&amp;vista=contas#custos">' . icon('pencil') . ' Registar, pagar ou avaliar custos</a></p>' : '');
            return ['title' => 'Equipa e fornecedores', 'html' => $html, 'url' => $self . '&vista=contas'];

        case 'dtasks':
            require_perm('tarefas', PERM_VIEW);
            $t = deal_task_stats($pdo, $id);
            $rows = '';
            foreach ($t['all'] as $x) {
                [$kl, $ki] = task_kind_info($x['kind'] ?? 'task');
                $late = $x['status'] !== 'done' && $x['due_date'] && $x['due_date'] < today_lisbon();
                $rows .= '<li class="' . ($x['status'] === 'done' ? 'done' : '') . '"><span class="k" data-tip="' . e($kl) . '" aria-label="' . e($kl) . '" role="img" tabindex="0">' . icon($ki) . '</span>'
                       . '<span class="t"><a href="' . $go('/admin/calendar.php?edit=' . (int)$x['id']) . '">' . e($x['title']) . '</a></span>'
                       . '<small class="when' . ($late ? ' late' : '') . '">' . e(($x['due_date'] ? date('d/m', strtotime($x['due_date'])) : 'sem prazo') . ' · ' . ($x['status'] === 'done' ? 'feita' : ($x['who'] ?: 'por atribuir'))) . '</small></li>';
            }
            $html = $head . '<section><h4>Tarefas <span class="mono">' . count($t['open']) . ' abertas · ' . $t['done'] . ' feitas</span></h4>' . ($rows ? '<ul class="focus">' . $rows . '</ul>' : '<p class="muted">Sem tarefas ligadas.</p>') . '</section>'
                  . '<p><a class="btn ghost sm" href="' . $go('/admin/calendar.php') . '">' . icon('tarefas') . ' Abrir em Tarefas</a></p>';
            return ['title' => 'Produção e tarefas', 'html' => $html, 'url' => '/admin/calendar.php'];

        case 'dfiles':
        case 'ddocs':
            require_perm('drive', PERM_VIEW);
            $dr = deal_drive_summary($pdo, $deal);
            if (!$dr || !empty($dr['off'])) return ['title' => 'Ficheiros', 'html' => $head . '<p class="muted">O Drive não está ligado neste servidor.</p>', 'url' => '/admin/drive.php'];
            if (!empty($dr['error'])) return ['title' => 'Ficheiros', 'html' => $head . '<p class="muted">Não foi possível ler o Drive agora. Tente outra vez.</p>', 'url' => '/admin/drive.php'];
            $list = function (array $objs) use ($dr) {
                if (!$objs) return '<p class="muted" style="margin:0">Nenhum.</p>';
                $h = '<ul class="ppl">';
                foreach (array_slice($objs, 0, 30) as $o) {
                    $h .= '<li><a href="' . e(drive_page(drive_parent($o['key']))) . '">' . e(basename($o['key'])) . '</a> <span class="muted small">· ' . e(drive_size((int)$o['size'])) . ' · ' . e(date('d/m/Y', (int)$o['modified'])) . '</span></li>';
                }
                return $h . '</ul>';
            };
            if ($type === 'ddocs') {
                $props = can('orcamentacao') && ($sheet = budget_sheet_get($pdo, $id)) ? array_filter(budget_history($pdo, (int)$sheet['id']), fn($h) => $h['event'] === 'b_pdf') : [];
                $ph = '';
                foreach ($props as $h) $ph .= '<li>' . e($h['note']) . ' <span class="muted small">· ' . e(date('d/m/Y', strtotime((string)$h['created_at']))) . '</span></li>';
                $html = $head . '<section><h4>Contratuais <span class="h-sub">propostas, contratos</span></h4>' . $list($dr['docs']['contract']) . ($ph ? '<ul class="ppl">' . $ph . '</ul>' : '') . '</section>'
                      . '<section><h4>Fiscais <span class="h-sub">faturas emitidas</span></h4>' . $list($dr['docs']['fiscal'])
                      . ($money ? '<p><a class="lnk" href="' . $go('/admin/documento.php?id=' . $id) . '">' . icon('file') . ' Resumo de cobrança</a></p>' : '') . '</section>'
                      . '<section><h4>Faturas de fornecedores</h4>' . $list($dr['docs']['supplier']) . '</section>'
                      . '<p class="muted small">Os documentos vêm da pasta do negócio no Drive, pelo nome e pela subpasta (Propostas, Contratos, Faturação, Fornecedores).</p>';
                return ['title' => 'Documentos', 'html' => $html, 'url' => $dr['folder'] ? drive_page($dr['folder']) : '/admin/drive.php'];
            }
            // por secção (Propostas, Faturação, Criativo…), com a origem e «Mostrar no Drive»; anexar daqui (§10.15)
            require_once __DIR__ . '/files_view.php';
            $html = $head . '<section><h4>Ficheiros</h4>' . files_list_html($pdo, $deal, ['edit' => record_reach($pdo, $deal, PERM_EDIT)]) . '</section>';
            return ['title' => 'Ficheiros', 'html' => $html, 'url' => $dr['folder'] ? drive_page($dr['folder']) : '/admin/drive.php'];

        case 'dhist':
            return ['title' => 'Histórico', 'html' => $head . '<section class="hist"><h4>Todas as fontes</h4><div class="hist-list">' . timeline_html(deal_timeline_all($pdo, $deal, $money)) . '</div></section>', 'url' => $self . '#c-hist'];

        case 'dclose':
            $check = deal_checklist($pdo, $deal);
            $html = $head . '<section><h4>Fecho do negócio</h4>' . deal_checklist_html($check) . '</section>'
                  . '<section><h4>Depois do fecho</h4><div class="kv"><span>Portfólio</span><div>' . ($deal['portfolio_at'] ? 'enviado a ' . e(date('d/m/Y', strtotime((string)$deal['portfolio_at']))) : '<span class="muted">—</span>') . '</div>'
                  . '<span>Testemunho</span><div>' . ($deal['testimonial_at'] ? 'pedido a ' . e(date('d/m/Y', strtotime((string)$deal['testimonial_at']))) : '<span class="muted">—</span>') . '</div></div></section>'
                  . '<p class="muted small">Pode concluir com itens em falta, mas o negócio fica assinalado e conta como «Em risco» até fechar.</p>';
            return ['title' => 'Fecho do negócio', 'html' => $html, 'url' => $self . '#c-close'];
    }
    throw new RuntimeException('Painel desconhecido.');
}

// ── ações da ficha (etapas e fecho) ─────────────────────────

/**
 * Avança (ou recua) a etapa de produção. Com as etapas do funil, muda a etapa do negócio;
 * sem elas, muda deals.prod_step. Num concluído, voltar a uma etapa reabre-o (Kickoff).
 * Concluir faz-se pela checklist de fecho (deal_close).
 */
function deal_step_set(PDO $pdo, array $deal, string $to, string $force = ''): string {
    $steps = deal_steps($pdo, $deal);
    if ($to === 'done') throw new RuntimeException('Para concluir, use a checklist de fecho.');
    if (!in_array($to, array_column($steps['items'], 'key'), true)) throw new RuntimeException('Etapa inválida.');
    if ($steps['mode'] === 'stage') return deal_move($pdo, $deal, (int)substr($to, 1), $force);
    $wasDone = $deal['phase'] === 'done';
    if ($wasDone) {
        $k = funnel_fixed_stage($pdo, (int)$deal['funnel_id'], 'kickoff');
        if (!$k) throw new RuntimeException('Este funil não tem a etapa Kickoff.');
        deal_move($pdo, $deal, $k);
    }
    $from = (string)($deal['prod_step'] ?: 'pre');
    $pdo->prepare('UPDATE deals SET prod_step = ?, last_touch = ? WHERE id = ?')->execute([$to, today_lisbon(), $deal['id']]);
    deal_log($pdo, (int)$deal['id'], 'step', ['note' => ($wasDone ? 'Concluído' : (DEAL_PROD_STEPS[$from] ?? $from)) . ' → ' . DEAL_PROD_STEPS[$to]]);
    return ($wasDone ? $deal['code'] . ' reaberto · ' : '') . 'Etapa: ' . DEAL_PROD_STEPS[$to] . '.';
}

/** O que a checklist e as ações de fecho mudam, para o «Anular». */
function deal_flags_snapshot(array $deal): array {
    return ['id' => (int)$deal['id'], 'delivered_at' => $deal['delivered_at'], 'portfolio_at' => $deal['portfolio_at'], 'testimonial_at' => $deal['testimonial_at'], 'tasks' => [], 'ideas' => []];
}

/** «Pode ser divulgado?» do negócio, em texto (Marketing, §16.3). */
function deal_disclose_label(array $deal): string {
    $d = $deal['disclose'] ?? null;
    $f = $deal['disclose_from'] ?? null;
    return match ($d) {
        'yes'  => 'Pode ser divulgado',
        'no'   => 'Não pode ser divulgado',
        'from' => 'Pode ser divulgado a partir de ' . ($f ? date('d/m/Y', strtotime($f)) : '—'),
        default => 'Divulgação por decidir',
    };
}

function deal_mark_delivered(PDO $pdo, array $deal, bool $on): void {
    $pdo->prepare('UPDATE deals SET delivered_at = ? WHERE id = ?')->execute([$on ? now_php() : null, $deal['id']]);
    deal_log($pdo, (int)$deal['id'], 'delivered', ['note' => $on ? 'Entregas feitas' : 'Entregas por confirmar']);
    // Depois da entrega o cliente entra nas Relações, com o comercial como dono (se ainda lá não estiver).
    if ($on) { require_once __DIR__ . '/pipeline_flow.php'; flow_relation_after_delivery($pdo, $deal); }
}

/** «Enviar para o portfólio» (Marketing): fica registado e cria a tarefa de preparar o material. Devolve o id da tarefa. */
/**
 * «Enviar para o portfólio»: a tarefa de preparar o material e (§16.3) a ideia «Breakdown: <negócio>»
 * em Marketing › Ideias, ligada ao negócio. Devolve [id da tarefa, id da ideia ou null].
 */
function deal_send_portfolio(PDO $pdo, array $deal, int $by): array {
    if ($deal['portfolio_at']) throw new RuntimeException('Este negócio já foi enviado para o portfólio.');
    $pdo->prepare('UPDATE deals SET portfolio_at = ? WHERE id = ?')->execute([now_php(), $deal['id']]);
    $due = (new DateTimeImmutable(today_lisbon()))->modify('+3 days')->format('Y-m-d');
    $tid = lead_task_create($pdo, $deal, 'task', 'Portfólio: ' . $deal['code'] . ' · ' . $deal['name'],
                            'Enviar para o portfólio (Marketing): escolher as imagens e os vídeos finais na pasta do negócio no Drive e escrever o texto do projeto.'
                            . ' A ideia «Breakdown: ' . $deal['name'] . '» fica em Marketing › Ideias.',
                            $due, null, $by, '', $by);
    $idea = null;
    if (db_has_table($pdo, 'mkt_pieces')) {
        require_once __DIR__ . '/marketing.php';
        $idea = mkt_idea_from_deal($pdo, $deal, $by);
    }
    deal_log($pdo, (int)$deal['id'], 'portfolio', ['note' => 'Tarefa de portfólio criada' . ($idea ? ' · ideia de breakdown em Marketing' : '')]);
    return [$tid, $idea];
}

/** «Pedir testemunho» ao cliente: um email para o responsável do negócio (ou quem pede). Devolve o id da tarefa. */
function deal_ask_testimonial(PDO $pdo, array $deal, int $by): int {
    if ($deal['testimonial_at']) throw new RuntimeException('O testemunho já foi pedido.');
    $who = $by;
    if ($deal['owner_id']) {
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([(int)$deal['owner_id']]);
        $o = $st->fetch();
        if ($o && can_assign_to(me_row($pdo), $o)) $who = (int)$o['id'];
    }
    $client = (string)($deal['client_name'] ?? '') ?: $deal['name'];
    $pdo->prepare('UPDATE deals SET testimonial_at = ? WHERE id = ?')->execute([now_php(), $deal['id']]);
    $due = (new DateTimeImmutable(today_lisbon()))->modify('+2 days')->format('Y-m-d');
    $tid = lead_task_create($pdo, $deal, 'email', 'Pedir testemunho a ' . $client,
                            'Pedir ao cliente uma frase sobre o trabalho (' . $deal['code'] . ' · ' . $deal['name'] . ') para o site e as redes, com autorização para publicar.',
                            $due, null, $who, '', $by);
    deal_log($pdo, (int)$deal['id'], 'testimonial', ['note' => 'Pedido a ' . $client]);
    return $tid;
}
