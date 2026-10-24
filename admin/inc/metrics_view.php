<?php
/**
 * topper. admin — peças de ecrã dos Relatórios (§29.1): números clicáveis até aos registos, ⓘ com a fórmula,
 * comparação com o período anterior, aviso de amostra pequena, tabelas que também saem em CSV, e a lista de
 * registos por trás de cada número (com o acesso de quem vê).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/reach.php';
require_once __DIR__ . '/interactions.php';

/** O contexto do pedido (filtros, período) para os links. */
function mx_ctx(?array $set = null): array {
    static $ctx = [];
    if ($set !== null) $ctx = $set;
    return $ctx;
}

/** O endereço dos Relatórios com o estado atual (período, filtros, separador) e as mudanças dadas. */
function mx_href(array $over = []): string {
    $q = array_merge(mx_ctx()['q'] ?? [], $over);
    return '/admin/relatorios.php?' . http_build_query(array_filter($q, fn($v) => $v !== null && $v !== '' && $v !== 0 && $v !== '0'));
}

/**
 * Um número clicável: abre a lista dos registos que o compõem. $kind: d (leads e negócios), t (tarefas),
 * f (documentos do Financeiro), i (interações), h (histórico). $ids pode misturar «tipo:id» (atividade).
 */
function mx_a($label, array $ids, string $title, string $kind = 'd', string $class = ''): string {
    $ids = array_values(array_unique(array_map('strval', $ids)));
    if (!$ids) return '<span class="mx-z' . ($class ? ' ' . $class : '') . '">' . (is_string($label) ? e($label) : (string)$label) . '</span>';
    $href = mx_href(['lista' => $kind, 'ids' => implode(',', array_slice($ids, 0, 400)), 'lt' => mb_substr($title, 0, 120)]);
    return '<a class="mx-n' . ($class ? ' ' . $class : '') . '" href="' . e($href) . '" data-mx-list>' . (is_string($label) ? e($label) : (string)$label) . '</a>';
}

/** ⓘ com a fórmula em linguagem simples. */
function mx_ii(string $text): string {
    return '<span class="ii" tabindex="0" role="img" aria-label="Como se calcula: ' . e($text) . '" data-tip="' . e($text) . '">i</span>';
}

/** «só 4 leads — pouco fiável» quando a amostra é pequena (mas não vazia). */
function mx_small(int $n, string $what = 'registos'): string {
    if ($n <= 0 || $n >= MX_SMALL) return '';
    return ' <span class="mx-small" data-tip="Só ' . $n . ' ' . e($n === 1 ? rtrim($what, 's') : $what) . ': com menos de ' . MX_SMALL . ' a percentagem muda muito com um só caso." tabindex="0"><span class="l">só ' . $n . ' ' . e($n === 1 ? rtrim($what, 's') : $what) . ' — </span><span class="w">pouco fiável</span></span>';
}

/** A comparação com o período anterior: «▲ 12%» com a dica do valor anterior. */
function mx_cmp(?float $now, ?float $prev, string $good = 'up', ?callable $fmt = null, bool $points = false): string {
    if ($now === null || $prev === null) return '';
    $fmt ??= fn($v) => (string)(int)round($v);
    [$t, $cls] = mx_delta($now, $prev, $good, $points);
    $p = mx_ctx()['per'] ?? null;
    return '<span class="mx-d ' . $cls . '" tabindex="0" data-tip="' . e('Antes (' . ($p['prev_label'] ?? 'período anterior') . '): ' . $fmt($prev)) . '">' . e($t) . '</span>';
}

/** Um indicador grande: rótulo + ⓘ, valor (clicável), comparação, nota e mini-linha. */
function mx_kpi(string $k, string $v, string $note = '', string $cmp = '', string $info = '', string $spark = '', bool $hero = false): string {
    return '<div class="stat mx-kpi' . ($hero ? ' hero' : '') . '"><div class="k">' . e($k) . ($info !== '' ? ' ' . mx_ii($info) : '') . '</div>'
        . '<div class="v">' . $v . ($cmp !== '' ? ' ' . $cmp : '') . '</div>' . ($note !== '' ? '<div class="n">' . $note . '</div>' : '') . ($spark !== '' ? '<div class="sp">' . $spark . '</div>' : '') . '</div>';
}

/** Percentagem legível (ou «—»). */
function mx_pct(?float $v, int $dec = 0): string {
    return $v === null ? '—' : str_replace('.', ',', (string)round($v, $dec)) . '%';
}

/** Número com vírgula. */
function mx_num(?float $v, int $dec = 1): string {
    return $v === null ? '—' : str_replace('.', ',', (string)round($v, $dec));
}

/** Horas úteis legíveis (mediana, p90). */
function mx_h(?float $h): string {
    if ($h === null) return '—';
    if ($h < 1) return max(1, (int)round($h * 60)) . ' min';
    return str_replace('.', ',', (string)round($h, $h < 10 ? 1 : 0)) . ' h';
}

/**
 * Uma tabela que também sai no CSV do separador. $head: [rótulo, …] (rótulo começado por «#» = coluna numérica);
 * $rows: [[célula, …]] onde cada célula é texto, ou ['h' => html, 'c' => texto para o CSV]; $foot igual.
 */
function mx_table(string $title, array $head, array $rows, array $foot = [], string $empty = 'Sem dados neste período.', string $caption = ''): string {
    $GLOBALS['MX_CSV'][] = [$title, $head, $rows, $foot];
    $th = '';
    foreach ($head as $hcol) $th .= str_starts_with($hcol, '#') ? '<th class="num">' . e(substr($hcol, 1)) . '</th>' : '<th>' . e($hcol) . '</th>';
    $cell = function ($c, int $i) use ($head) {
        $num = str_starts_with($head[$i] ?? '', '#');
        $html = is_array($c) ? (string)($c['h'] ?? e((string)($c['c'] ?? ''))) : e((string)$c);
        return '<td' . ($num ? ' class="num"' : '') . '>' . $html . '</td>';
    };
    $h = '<div class="tablewrap mx-t"><table>' . ($caption !== '' ? '<caption class="muted small">' . e($caption) . '</caption>' : '') . '<thead><tr>' . $th . '</tr></thead><tbody>';
    if (!$rows) $h .= '<tr><td colspan="' . count($head) . '" class="empty">' . e($empty) . '</td></tr>';
    foreach ($rows as $r) { $h .= '<tr>'; foreach (array_values($r) as $i => $c) $h .= $cell($c, $i); $h .= '</tr>'; }
    $h .= '</tbody>';
    if ($foot) { $h .= '<tfoot><tr>'; foreach (array_values($foot) as $i => $c) $h .= $cell($c, $i); $h .= '</tr></tfoot>'; }
    return $h . '</table></div>';
}

/** O CSV de tudo o que o separador mostrou em tabelas (separador ; e BOM, para abrir no Excel em português). */
function mx_csv_send(string $name): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_-]+/i', '-', $name) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $txt = fn($c) => is_array($c) ? (string)($c['c'] ?? trim(html_entity_decode(strip_tags((string)($c['h'] ?? '')), ENT_QUOTES, 'UTF-8'))) : (string)$c;
    foreach ($GLOBALS['MX_CSV'] ?? [] as [$title, $head, $rows, $foot]) {
        fputcsv($out, [$title], ';');
        fputcsv($out, array_map(fn($x) => ltrim($x, '#'), $head), ';');
        foreach ($rows as $r) fputcsv($out, array_map($txt, array_values($r)), ';');
        if ($foot) fputcsv($out, array_map($txt, array_values($foot)), ';');
        fputcsv($out, [], ';');
    }
    fclose($out);
}

/**
 * A lista de registos por trás de um número, com o acesso de quem vê (o que não pode abrir conta, mas sem
 * nome). $kind: d, t, f, i, h; $ids: ids ou «tipo:id».
 */
function mx_list_rows(PDO $pdo, string $kind, array $ids): array {
    $rows = []; $hidden = 0;
    $byKind = [];
    foreach ($ids as $raw) {
        $raw = trim((string)$raw);
        if (preg_match('/^([dtfih]):(\d+)$/', $raw, $m)) $byKind[$m[1]][] = (int)$m[2];
        elseif (ctype_digit($raw)) $byKind[$kind][] = (int)$raw;
    }
    $people = mx_people($pdo);
    foreach ($byKind as $k => $list) {
        $in = implode(',', array_unique(array_map('intval', $list)));
        if ($in === '') continue;
        if ($k === 'd') {
            $all = metrics_deals($pdo);
            $funnels = $pdo->query('SELECT id, name FROM funnels')->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($pdo->query("SELECT * FROM deals WHERE id IN ($in)")->fetchAll() as $d) {
                if (!record_reach($pdo, $d)) { $hidden++; continue; }
                $m = $all[(int)$d['id']] ?? null;
                $step = $m ? (MX_STEPS[array_keys(MX_STEPS)[$m['reach']]] ?? '') : '';
                $state = $d['phase'] === 'lead' ? 'Lead · ' . (LEAD_STATE[$d['lead_state']] ?? '') : ($m ? ($m['end'] === 'lost' ? 'Perdido em ' . $step : ($m['end'] === 'won' ? ($d['phase'] === 'done' ? 'Concluído' : 'Em produção') : $step)) : $d['phase']);
                $rows[] = ['url' => $m ? mx_url($m) : '/admin/leads.php?open=' . (int)$d['id'], 'cells' => [
                    $d['code'] ?: '—', lead_display_name($d) ?: $d['name'], $state, $funnels[(int)($d['origin_funnel_id'] ?: $d['funnel_id'])] ?? '—',
                    LEAD_ORIGIN[$d['origin']] ?? 'Manual', $people[(int)$d['owner_id']] ?? '—', $m && $m['end'] === 'lost' ? mx_reason($m['lost_reason']) : '',
                    date('d/m/Y', strtotime((string)$d['created_at'])),
                ], 'money' => (float)$d['budget'] > 0 ? (float)$d['budget'] : (float)$d['value_estimate']];
            }
        } elseif ($k === 't') {
            $mu = mx_member_users($pdo);
            foreach ($pdo->query("SELECT t.*, d.code FROM tasks t LEFT JOIN deals d ON d.id = t.deal_id WHERE t.id IN ($in)")->fetchAll() as $t) {
                $u = (int)($t['assignee_id'] ?: ($mu[(int)$t['member_id']] ?? 0));
                if ((int)$t['private'] === 1 && $u !== (int)current_user()['id'] && (int)$t['created_by'] !== (int)current_user()['id']) { $hidden++; continue; }
                $rows[] = ['url' => '/admin/calendar.php?edit=' . (int)$t['id'], 'cells' => [
                    $t['code'] ?: '—', $t['title'], $t['status'] === 'done' ? 'Feita' . ($t['done_at'] ? ' a ' . date('d/m', strtotime($t['done_at'])) : '') : 'Por fazer',
                    '', '', $people[$u] ?? '—', '', $t['due_date'] ? date('d/m/Y', strtotime($t['due_date'])) : '—']];
            }
        } elseif ($k === 'f' && can('financeiro')) {
            require_once __DIR__ . '/fin.php';
            foreach ($pdo->query("SELECT * FROM fin_docs WHERE id IN ($in)")->fetchAll() as $f) {
                $rows[] = ['url' => '/admin/financeiro.php?tab=' . ($f['direction'] === 'in' ? 'receber' : 'pagar') . '&doc=' . (int)$f['id'], 'cells' => [
                    fin_doc_label($f), $f['party_name'] ?: $f['description'], FIN_ST[$f['status']][0] ?? $f['status'], $f['category'], '', '', '', $f['issue_date'] ? date('d/m/Y', strtotime($f['issue_date'])) : '—',
                ], 'money' => (float)$f['base']];
            }
        } elseif ($k === 'i') {
            foreach ($pdo->query("SELECT i.*, d.code, d.name AS dname, d.phase, d.owner_id, d.funnel_id FROM interactions i LEFT JOIN deals d ON d.id = i.deal_id WHERE i.id IN ($in)")->fetchAll() as $i) {
                if ($i['deal_id'] && !record_reach($pdo, ['id' => $i['deal_id'], 'phase' => $i['phase'], 'owner_id' => $i['owner_id'], 'code' => $i['code']])) { $hidden++; continue; }
                $rows[] = ['url' => $i['deal_id'] ? ($i['phase'] === 'lead' ? '/admin/leads.php?open=' . (int)$i['deal_id'] : '/admin/negocio.php?id=' . (int)$i['deal_id']) : '/admin/contact.php?id=' . (int)$i['contact_id'],
                           'cells' => [$i['code'] ?: '—', $i['dname'] ?: '—', INTERACTION_KINDS[$i['kind']][0] ?? $i['kind'], '', '', $people[(int)$i['user_id']] ?? '—', mb_strimwidth((string)$i['body'], 0, 80, '…'), date('d/m/Y H:i', strtotime($i['happened_at']))]];
            }
        } elseif ($k === 'h') {
            foreach ($pdo->query("SELECT h.*, d.code, d.name AS dname, d.phase, d.owner_id, d.funnel_id FROM deal_history h JOIN deals d ON d.id = h.deal_id WHERE h.id IN ($in)")->fetchAll() as $hh) {
                if (!record_reach($pdo, ['id' => $hh['deal_id'], 'phase' => $hh['phase'], 'owner_id' => $hh['owner_id'], 'code' => $hh['code']])) { $hidden++; continue; }
                $rows[] = ['url' => $hh['phase'] === 'lead' ? '/admin/leads.php?open=' . (int)$hh['deal_id'] : '/admin/negocio.php?id=' . (int)$hh['deal_id'],
                           'cells' => [$hh['code'] ?: '—', $hh['dname'], HISTORY_TYPES[$hh['event']][0] ?? $hh['event'], '', '', $people[(int)$hh['user_id']] ?? '—', (string)$hh['note'], date('d/m/Y H:i', strtotime($hh['created_at']))]];
            }
        }
    }
    return [$rows, $hidden];
}

/** Os itens de Atenção que esta pessoa pode ver, com os filtros globais (funil, origem, pessoa, serviço). */
function rep_attention_items(array $c): array {
    static $memo = [];
    $mk = md5(json_encode($c['f']) . ($c['money'] ? 1 : 0));
    if (isset($memo[$mk])) return $memo[$mk];
    $pdo = $c['pdo'];
    $items = mx_attention($pdo, $c['money'], $c['all']);
    $out = [];
    foreach ($items as $i) {
        $d = $i['deal'] ? ($c['all'][$i['deal']] ?? null) : null;
        if ($i['deal']) {
            if (!$d && $i['kind'] !== 'task' && $i['kind'] !== 'gate' && $i['kind'] !== 'invoice') continue;
            if ($d && !record_reach($pdo, ['phase' => $d['phase'], 'owner_id' => $d['owner'] ?: null, 'code' => $d['code']])) continue;
            if ($d && !isset($c['deals'][$d['id']])) continue;   // fora dos filtros
        } elseif (array_filter($c['f']) && !$c['f']['pe']) {
            if ($i['kind'] !== 'goal') continue;   // com filtro de funil/origem/serviço, só o que é de um registo
        }
        if ($c['f']['pe'] && $i['owner'] !== $c['f']['pe']) continue;
        if ($i['kind'] === 'task' && !$c['admin'] && $i['owner'] !== $c['uid']) continue;   // as tarefas dos colegas são deles
        if ($i['kind'] === 'goal' && !$c['admin'] && $i['owner'] && $i['owner'] !== $c['uid']) continue;
        $out[] = $i;
    }
    return $memo[$mk] = $out;
}

/** O número do menu lateral: os mesmos itens de Atenção (sem filtros), guardado 5 minutos na sessão. */
function mx_attention_count(PDO $pdo): int {
    $u = current_user();
    if (!$u || !metrics_ready($pdo)) return 0;
    $k = 'mx_att_' . (int)$u['id'];
    if (isset($_SESSION[$k]) && $_SESSION[$k][0] > time() - 300) return (int)$_SESSION[$k][1];
    $all = metrics_deals($pdo);
    $c = ['pdo' => $pdo, 'all' => $all, 'deals' => $all, 'f' => ['fu' => 0, 'or' => '', 'pe' => 0, 'sv' => 0], 'money' => can('financeiro'),
          'admin' => role_is_admin((string)$u['role']), 'uid' => (int)$u['id'], 'people' => mx_people($pdo)];
    $n = count(rep_attention_items($c));
    $_SESSION[$k] = [time(), $n];
    return $n;
}
