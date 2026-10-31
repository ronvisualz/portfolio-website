<?php
/**
 * topper. admin — pedidos das features transversais (§11)
 *
 *   GET  a=search&q=          pesquisa global ⌘K (só o que a pessoa pode ver)
 *   GET  a=notifications      centro de notificações
 *   POST a=notifications_read keys[] (vazio = todas)
 *   POST a=prefs              theme ('' | light | dark) · nav ('' | closed)
 *   POST a=undo               token
 *   POST a=inline             entity, id, field, value
 *   POST a=interaction        deal_id | contact_id, kind, date, body (também sem JavaScript)
 *   GET  a=panel&type=&id=    painel lateral (lead | deal | contact)
 *   POST a=deal_move          id, stage_id — arrastar no Pipeline, sem recarregar
 *
 *   Leads v2 (§12):
 *   GET  a=lead_dups&email=&phone=   duplicados enquanto se escreve (nova lead)
 *   POST a=lead_save          id, f[campo] — «Gravar» na ficha (todos os campos de uma vez, com «Anular»)
 *   POST a=lead_task          id, kind, title, notes, date, time, duration, assignee, repeat, [platform, location] — Nova ação
 *   POST a=lead_task_done     id, task — concluir no Foco (uma chamada abre «Como correu?»)
 *   POST a=lead_outcome       id, result (missed | followup), note
 *   POST a=lead_archive       id, reason, note — com «Anular»
 *   GET  a=lead_next&id=      a próxima lead urgente
 *
 *   Contactos v2 (§13):
 *   GET  a=contact_dups&email=&phone=&name=&kind=   duplicados enquanto se escreve (novo contacto)
 *   GET  a=contact_pick&q=    escolher um contacto escrevendo («Trazido por»)
 *   POST a=contact_save       id, f[campo] — «Gravar» na ficha, com «Anular»
 *   POST a=contact_active     id — Ativo / Inativo (só superadmin e admins), com «Anular»
 *   POST a=service_save       id, line, service_id, unit, price, vat, valid_from, notes
 *   POST a=service_remove     id, line — com «Anular»
 *   POST a=contact_task       id, kind, title, notes, date, time, duration, assignee, repeat — Nova ação
 *   POST a=contact_task_done  id, task — concluir no Foco (um follow-up de relação abre «Como correu?»)
 *   POST a=relation_set       id, formality, potential, business, owner_id, cadence_days
 *   POST a=relation_outcome   id, result (talked | missed | meeting | lead), note, next, date, time
 *   POST a=relation_pause     id, until  ·  relation_resume id  ·  relation_stop id (com «Anular»)
 *   POST a=relation_review    id, decision (accept | keep), potential
 *   POST a=contact_merge      id, other — juntar duplicados (gestores)
 *   POST a=cost_rate          cost, stars — avaliação de um trabalho pago (Financeiro)
 *
 *   Orçamentação (§14) — o cálculo volta sempre filtrado: sem Financeiro não seguem
 *   preços de fornecedores, custos internos, margens nem lucro:
 *   GET  a=budget_suppliers&service=   fornecedores ATIVOS desse serviço (preço só com Financeiro)
 *   POST a=budget_set         version, field, value — duração, datas, fases, ocupação, margem*, custos fixos*, textos (*Financeiro)
 *   POST a=budget_fixed_avg   version — volta à média dos 3 meses (Financeiro)
 *   POST a=budget_line_save   version, line, kind, service_id, contact_id, user_id, …, qty, qty_unit, note
 *   POST a=budget_line_qty    line, qty | qty_unit
 *   POST a=budget_line_remove line — com «Anular»
 *   POST a=budget_version_new version — nova versão a partir desta, com «Anular»
 *   POST a=budget_accept      version — com «Anular»
 *   POST a=budget_tpl_save    version, name  ·  budget_tpl_apply sheet, template (com «Anular»)
 *   POST a=budget_pdf         version — proposta em PDF guardada na pasta do negócio no Drive
 *
 * Os POST pedem o token CSRF (campo «csrf» ou cabeçalho X-CSRF-Token).
 * Todas as permissões se verificam aqui, em cada pedido (§4).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ui.php';
require_once __DIR__ . '/inc/reach.php';
require_once __DIR__ . '/inc/alerts.php';
require_once __DIR__ . '/inc/undo.php';
require_once __DIR__ . '/inc/panel.php';
require_once __DIR__ . '/inc/tasks.php';

$json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

function out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (current_user() === null) out(401, ['ok' => false, 'error' => 'A sessão terminou. Entre outra vez.']);
require_login();

$a = (string)($_REQUEST['a'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post) {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        out(400, ['ok' => false, 'error' => 'Pedido inválido. Recarregue a página e tente outra vez.']);
    }
}

/** A lead do pedido, ativa (ou não, com $active false) e ao meu alcance para editar. */
function lead_for_edit(PDO $pdo, bool $active = true): array {
    require_perm('leads', PERM_EDIT);
    $l = record_get($pdo, (int)($_POST['id'] ?? 0));
    if (!$l || !in_array($l['phase'], ['lead', 'archived'], true) || !record_reach($pdo, $l, PERM_EDIT)) throw new RuntimeException('Lead não encontrada.');
    if ($active && $l['phase'] !== 'lead') throw new RuntimeException('Esta lead já não está ativa.');
    return $l;
}

/** As partes da vista (e da linha da lista) que mudam depois de uma ação. */
function lead_parts(PDO $pdo, int $id): array {
    require_once __DIR__ . '/inc/lead_view.php';
    $l = record_get($pdo, $id);
    $tasks = lead_open_tasks($pdo, [$id])[$id] ?? [];
    $clock = lead_clock($l, lead_deadline_hours($pdo));
    $next = $tasks[0] ?? null;
    return ['focus' => lead_focus_html($tasks, can('leads', PERM_EDIT)), 'focus_n' => count($tasks),
            'history' => timeline_html(timeline($pdo, 'deal', $id)),
            'state' => lead_state_chip($l), 'state_key' => (string)$l['lead_state'], 'ring' => lead_ring($clock), 'level' => $clock['level'] ?? '',
            'next' => $next ? '<span class="' . (task_is_late($next) ? 'neg' : '') . '">' . e((LEAD_TASK_KINDS[$next['kind'] ?? 'task'] ?? LEAD_TASK_KINDS['task'])[0]) . ' · ' . e(lead_task_when($next)) . '</span>'
                            : '<span class="risk-txt">Sem próxima ação</span>',
            'last' => lead_ago($l['last_contact_at'])];
}

function lead_task_default_title(string $kind, string $who): string {
    return match ($kind) {
        'call' => 'Ligar a ' . $who, 'meeting' => 'Reunião com ' . $who, 'email' => 'Email para ' . $who,
        'lunch' => 'Almoço com ' . $who, 'dinner' => 'Jantar com ' . $who,
        'document' => 'Documento para ' . $who, 'event' => 'Evento · ' . $who,
        default => 'Tarefa · ' . $who,
    };
}

/** O contacto do pedido, para editar (Contactos, nível editar). */
function contact_for_edit(PDO $pdo): array {
    require_perm('contactos', PERM_EDIT);
    require_once __DIR__ . '/inc/contacts.php';
    $c = contact_get($pdo, (int)($_POST['id'] ?? 0));
    if (!$c) throw new RuntimeException('Contacto não encontrado.');
    return $c;
}

/** As partes da ficha do contacto que mudam depois de uma ação. */
function contact_parts(PDO $pdo, int $id): array {
    require_once __DIR__ . '/inc/contact_view.php';
    $c = contact_get($pdo, $id);
    $tasks = contact_open_tasks($pdo, $id);
    return ['focus' => contact_focus_html($tasks, can('contactos', PERM_EDIT)), 'focus_n' => count($tasks),
            'history' => timeline_html(timeline($pdo, 'contact', $id)),
            'relation' => contact_relation_html($pdo, $c, relation_get($pdo, $id), can('contactos', PERM_EDIT))];
}

/** A versão do pedido, para editar (Orçamentação, nível editar; só a última, não aceite, com a folha aberta). */
function budget_version_for_edit(PDO $pdo, int $versionId): array {
    require_perm('orcamentacao', PERM_EDIT);
    require_once __DIR__ . '/inc/budget_view.php';
    $v = budget_version_get($pdo, $versionId);
    $sheet = $v ? budget_sheet_by_id($pdo, (int)$v['sheet_id']) : null;
    if (!$v || !$sheet) throw new RuntimeException('Versão não encontrada. Recarregue a página.');
    if (!budget_editable($sheet, $v, budget_versions($pdo, (int)$sheet['id']))) {
        throw new RuntimeException('Esta versão já não se edita (aceite, anterior ou negócio concluído). Recarregue a página.');
    }
    return [$sheet, $v];
}

/** Depois de mudar a folha: o cálculo (filtrado pelas permissões) e o custo de cada linha; com $rows, as linhas desenhadas. */
function budget_parts(PDO $pdo, int $versionId, bool $rows = false): array {
    // A opção que a folha está a mostrar (§19.3): Opção A (Pedido) ou B (Proposta de valor).
    $rc = budget_recalc($pdo, $versionId, ($_POST['opt'] ?? 'A') === 'B' ? 'B' : 'A');
    $money = budget_money();
    $costs = [];
    foreach ($rc['lines'] as $l) if ($money || $l['kind'] === 'other') $costs[(int)$l['id']] = budget_eur((float)$l['cost']);
    $out = ['calc' => budget_calc_view($rc['calc'], $money), 'costs' => $costs];
    if ($rows) {
        $out['rows'] = budget_lines_rows($pdo, $rc['lines'], $money, true);
        $out['lines'] = json_decode(budget_lines_json($rc['lines'], $money), true);
    }
    return $out;
}

/** Para LIKE … ESCAPE '!'. */
function like(string $s): string {
    return '%' . strtr($s, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}

/** Minúsculas e sem acentos, para comparar nomes do menu. */
function fold(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
}

try {
    switch ($a) {

    // ── pesquisa global ─────────────────────────────────────
    case 'search':
        $q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
        $groups = [];
        $add = function (string $group, array $it) use (&$groups) { $groups[$group][] = $it; };

        // Ir para… (só os separadores a que tem acesso)
        $fq = fold($q);
        $nav = [];
        foreach (NAV_ITEMS as $key => [$label, $href, $ico]) {
            if (can(NAV_TABS[$key] ?? $key)) $nav[] = [$label, $href, $ico];
        }
        if (can('criativo')) $nav[] = ['Topperboards (Toppernote)', '/admin/toppernote.php', 'criativo'];
        $nav[] = ['Perfil (preferências, notificações, palavra-passe)', '/admin/conta.php', 'user'];
        if (is_admin()) $nav[] = ['Definições', '/admin/definicoes.php', 'cog'];
        if (can('pipeline', PERM_MANAGE)) $nav[] = ['Funis e automações (Definições)', '/admin/funis.php', 'pipeline'];
        // As próprias definições (só admins): «margem» leva direto ao campo.
        if (is_admin() && $fq !== '' && mb_strlen($fq) >= 3) {
            require_once __DIR__ . '/inc/settings_def.php';
            foreach (settings_index() as $d) {
                if (!str_contains(fold($d['l'] . ' ' . $d['g']), $fq)) continue;
                $add('Definições', ['title' => $d['l'], 'sub' => $d['tab'] . ' · ' . $d['g'], 'url' => '/admin/definicoes.php?t=' . $d['t'] . '#set-' . $d['k'], 'icon' => icon('cog')]);
            }
        }
        if (is_admin()) $nav[] = ['Migração', '/admin/migracao.php', 'migracao'];
        foreach ($nav as [$label, $href, $ico]) {
            if ($fq !== '' && !str_contains(fold($label), $fq) && !str_contains('ir para ' . fold($label), $fq)) continue;
            $add('Ir para', ['title' => $label, 'url' => $href, 'icon' => icon($ico)]);
        }
        if ($fq === '' || str_contains('mudar de modo claro escuro tema', $fq)) {
            $add('Ações', ['title' => 'Mudar de modo (claro / escuro)', 'action' => 'theme', 'icon' => icon('moon')]);
        }

        if (mb_strlen($q) >= 2) {
            $l = like($q);

            // Negócios e IDs: cada fase responde à sua aba.
            $parts = []; $args = [];
            foreach (['pipeline' => "d.phase IN ('pipeline','lost')", 'negocios' => "d.phase IN ('production','done')"] as $tab => $cond) {
                if (!can($tab)) continue;
                [$rs, $ra] = reach_sql($pdo, $tab);
                $parts[] = "($cond AND $rs)";
                array_push($args, ...$ra);
            }
            if ($parts) {
                $st = $pdo->prepare("SELECT d.*, s.name AS stage FROM deals d LEFT JOIN funnel_stages s ON s.id = d.stage_id
                                      WHERE d.code IS NOT NULL AND (d.code LIKE ? ESCAPE '!' OR d.name LIKE ? ESCAPE '!')
                                        AND (" . implode(' OR ', $parts) . ') ORDER BY d.code DESC LIMIT 6');
                $st->execute([$l, $l, ...$args]);
                $ph = ['pipeline' => 'Pipeline', 'production' => 'Em produção', 'done' => 'Concluído', 'lost' => 'Perdido'];
                foreach ($st->fetchAll() as $d) {
                    $add('Negócios', ['title' => $d['name'], 'id' => $d['code'], 'sub' => ($ph[$d['phase']] ?? '') . ($d['stage'] ? ' · ' . $d['stage'] : ''),
                                      'url' => deal_url($d), 'panel' => 'deal:' . $d['id'], 'icon' => icon('negocios')]);
                }
            }

            // Folhas de orçamento (§14): pelo ID, pelo negócio ou pelo cliente, em qualquer separador.
            if (can('orcamentacao') && db_has_table($pdo, 'budget_sheets')) {
                $st = $pdo->prepare("SELECT d.id, d.code, d.name, d.phase, s.accepted_version_id, (SELECT MAX(num) FROM budget_versions v WHERE v.sheet_id = s.id) AS nv,
                                            COALESCE(co.name, c.name, NULLIF(d.company, '')) AS client
                                       FROM budget_sheets s JOIN deals d ON d.id = s.deal_id
                                       LEFT JOIN contacts co ON co.id = d.company_id LEFT JOIN contacts c ON c.id = d.contact_id
                                      WHERE d.code LIKE ? ESCAPE '!' OR d.name LIKE ? ESCAPE '!' OR co.name LIKE ? ESCAPE '!' OR c.name LIKE ? ESCAPE '!'
                                      ORDER BY d.code DESC LIMIT 5");
                $st->execute([$l, $l, $l, $l]);
                $tabName = ['pipeline' => 'Em orçamentação', 'production' => 'Em produção', 'done' => 'Ganhos', 'lost' => 'Arquivo'];
                foreach ($st->fetchAll() as $b) {
                    $add('Orçamentos', ['title' => $b['name'], 'id' => $b['code'], 'sub' => trim(($tabName[$b['phase']] ?? '') . ' · v' . (int)$b['nv'] . ($b['accepted_version_id'] ? ' · com versão aceite' : '') . ($b['client'] ? ' · ' . $b['client'] : '')),
                                        'url' => '/admin/orcamento.php?id=' . (int)$b['id'], 'icon' => icon('orcamentacao')]);
                }
            }

            // Marketing (§16): ideias e peças, pelo título ou pelo texto da ideia.
            if (can('marketing') && db_has_table($pdo, 'mkt_pieces')) {
                require_once __DIR__ . '/inc/marketing.php';
                $st = $pdo->prepare("SELECT id, title, status, stage FROM mkt_pieces WHERE status <> 'descartada' AND (title LIKE ? ESCAPE '!' OR idea LIKE ? ESCAPE '!') ORDER BY id DESC LIMIT 5");
                $st->execute([$l, $l]);
                foreach ($st->fetchAll() as $m) {
                    $add('Marketing', ['title' => $m['title'], 'sub' => $m['status'] === 'producao' ? 'Produção · ' . (MKT_STAGES[$m['stage']] ?? '') : 'Ideia · ' . (MKT_IDEA_STATUS[$m['status']] ?? ''),
                                       'url' => '/admin/marketing_peca.php?id=' . (int)$m['id'], 'panel' => 'mkt:' . (int)$m['id'], 'icon' => icon('marketing')]);
                }
            }

            if (can('leads')) {
                [$rs, $ra] = reach_sql($pdo, 'leads');
                $st = $pdo->prepare("SELECT d.id, d.name, d.company, d.person, d.phase, d.email, d.lead_state FROM deals d
                                      WHERE d.phase IN ('lead','archived') AND $rs
                                        AND (d.name LIKE ? ESCAPE '!' OR d.company LIKE ? ESCAPE '!' OR d.person LIKE ? ESCAPE '!'
                                             OR d.email LIKE ? ESCAPE '!' OR d.phone LIKE ? ESCAPE '!')
                                      ORDER BY d.phase = 'archived', d.id DESC LIMIT 6");
                $st->execute([...$ra, $l, $l, $l, $l, $l]);
                foreach ($st->fetchAll() as $d) {
                    [$dn, $dc] = lead_display($d);
                    $add('Leads', ['title' => $dn, 'sub' => trim(($dc !== '' ? $dc . ' · ' : '') . ($d['phase'] === 'archived' ? 'Arquivada' : (LEAD_STATE[$d['lead_state']] ?? ''))),
                                   'url' => '/admin/leads.php?open=' . $d['id'], 'panel' => 'lead:' . $d['id'], 'icon' => icon('leads')]);
                }
            }

            if (can('contactos')) {
                $st = $pdo->prepare("SELECT c.id, c.kind, c.name, c.email, c.role, c.is_freelancer, co.name AS company_name FROM contacts c LEFT JOIN contacts co ON co.id = c.company_id
                                      WHERE c.name LIKE ? ESCAPE '!' OR c.email LIKE ? ESCAPE '!' OR c.phone LIKE ? ESCAPE '!'
                                         OR c.nif LIKE ? ESCAPE '!' OR c.role LIKE ? ESCAPE '!' OR co.name LIKE ? ESCAPE '!'
                                      ORDER BY c.name LIMIT 6");
                $st->execute([$l, $l, $l, $l, $l, $l]);
                foreach ($st->fetchAll() as $c) {
                    $sub = $c['kind'] === 'company' ? 'Empresa' : ($c['company_name'] ?: ((int)$c['is_freelancer'] ? 'Freelancer' : 'Pessoa'));
                    $add('Contactos', ['title' => $c['name'], 'sub' => $sub . ($c['role'] !== '' ? ' · ' . $c['role'] : ($c['email'] !== '' ? ' · ' . $c['email'] : '')),
                                       'url' => '/admin/contact.php?id=' . $c['id'], 'panel' => 'contact:' . $c['id'], 'icon' => icon($c['kind'] === 'company' ? 'building' : 'contactos')]);
                }
            }

            if (can('tarefas')) {
                require_once __DIR__ . '/inc/tasks.php';
                $visible = task_visible_members($pdo, team_members($pdo));
                $vis = task_vis_where($pdo, $visible);
                $st = $pdo->prepare("SELECT t.id, t.title, t.status, t.due_date, tm.name AS who FROM tasks t LEFT JOIN team_members tm ON tm.id = t.member_id
                                      WHERE t.title LIKE ? ESCAPE '!'$vis ORDER BY t.status = 'done', t.due_date IS NULL, t.due_date LIMIT 6");
                $st->execute([$l]);
                foreach ($st->fetchAll() as $t) {
                    $add('Tarefas', ['title' => $t['title'], 'sub' => trim(($t['who'] ?? 'Por atribuir') . ' · ' . (TASK_STATUS[$t['status']] ?? '') . ($t['due_date'] ? ' · ' . date('d/m', strtotime($t['due_date'])) : '')),
                                     'url' => '/admin/calendar.php?edit=' . $t['id'], 'icon' => icon('tarefas')]);
                }
            }

            // Ficheiros: pelo nome, no índice do Drive (sem a pasta de sistema nem os documentos privados).
            if (can('drive') && db_has_table($pdo, 'drive_index')) {
                $st = $pdo->prepare("SELECT obj_key FROM drive_index WHERE obj_key LIKE ? ESCAPE '!' AND obj_key NOT LIKE '.topper/%'
                                        AND status <> 'private' ORDER BY obj_key LIMIT 6");
                $st->execute([$l]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) {
                    $dir = str_contains($k, '/') ? substr($k, 0, strrpos($k, '/') + 1) : '';
                    $add('Ficheiros', ['title' => basename($k), 'sub' => $dir !== '' ? rtrim($dir, '/') : 'Drive',
                                       'url' => '/admin/drive.php' . ($dir !== '' ? '?p=' . rawurlencode($dir) : ''), 'icon' => icon('drive')]);
                }
            }
        }
        $outG = [];
        foreach ($groups as $name => $items) $outG[] = ['name' => $name, 'items' => $items];
        out(200, ['ok' => true, 'groups' => $outG]);

    // ── notificações ────────────────────────────────────────
    case 'notifications':
        $list = notifications($pdo);
        foreach ($list as &$n) $n['icon'] = icon($n['icon']);
        unset($n);
        out(200, ['ok' => true, 'items' => $list, 'unread' => count(array_filter($list, fn($n) => $n['unread']))]);

    case 'notifications_read':
        if (!$post) break;
        $keys = $_POST['keys'] ?? null;
        notifications_mark($pdo, is_array($keys) && $keys ? array_map('strval', $keys) : null);
        out(200, ['ok' => true, 'unread' => notifications_unread($pdo)]);

    // ── preferências na conta ───────────────────────────────
    case 'prefs':
        if (!$post) break;
        if (isset($_POST['theme'])) {
            $t = (string)$_POST['theme'];
            if (!in_array($t, ['', 'light', 'dark'], true)) out(400, ['ok' => false, 'error' => 'Modo inválido.']);
            $pdo->prepare('UPDATE users SET pref_theme = ? WHERE id = ?')->execute([$t, (int)current_user()['id']]);
        }
        if (isset($_POST['nav'])) {
            $n = (string)$_POST['nav'];
            if (!in_array($n, ['', 'closed'], true)) out(400, ['ok' => false, 'error' => 'Opção inválida.']);
            $pdo->prepare('UPDATE users SET pref_nav = ? WHERE id = ?')->execute([$n, (int)current_user()['id']]);
        }
        out(200, ['ok' => true]);

    // ── anular ──────────────────────────────────────────────
    case 'undo':
        if (!$post) break;
        $msg = undo_run($pdo, (string)($_POST['token'] ?? ''));
        out(200, ['ok' => true, 'msg' => $msg]);

    // ── edição no próprio campo ─────────────────────────────
    case 'inline':
        if (!$post) break;
        $entity = (string)($_POST['entity'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $field = (string)($_POST['field'] ?? '');
        $before = $entity === 'lead' ? record_get($pdo, $id) : null;
        $r = inline_save($pdo, $entity, $id, $field, $_POST['value'] ?? '');
        $token = '';
        if ((string)$r['prev'] !== (string)$r['value']) {
            if ($entity === 'lead' && $field === 'lead_state') {
                $token = undo_push('lead_state', ['id' => $id, 'from' => $before['lead_state'], 'to' => $r['value'], 'last_touch' => $before['last_touch'],
                                                  'state_since' => $before['state_since'], 'no_answer' => (int)$before['no_answer']]);
            } elseif ($entity === 'lead' && $field === 'owner_id') {
                $token = undo_push('assign', ['id' => $id, 'from' => $before['owner_id'] !== null ? (int)$before['owner_id'] : null, 'to' => $r['value']]);
            } else {
                $token = undo_push('inline', ['entity' => $entity, 'id' => $id, 'field' => $field, 'prev' => $r['prev']]);
            }
        }
        out(200, ['ok' => true, 'display' => $r['display'], 'value' => $r['value'], 'undo' => $token, 'reload' => !empty($r['reload'])]);

    // ── registar interação ──────────────────────────────────
    case 'interaction':
        if (!$post) break;
        $dealId = (int)($_POST['deal_id'] ?? 0) ?: null;
        $contactId = (int)($_POST['contact_id'] ?? 0) ?: null;
        if ($dealId) {
            $d = record_get($pdo, $dealId);
            if (!record_reach($pdo, $d, PERM_EDIT)) throw new RuntimeException('Registo não encontrado.');
            $type = 'deal'; $id = $dealId;
        } elseif ($contactId) {
            require_perm('contactos', PERM_EDIT);
            $st = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE id = ?');
            $st->execute([$contactId]);
            if (!(int)$st->fetchColumn()) throw new RuntimeException('Contacto não encontrado.');
            $type = 'contact'; $id = $contactId;
        } else {
            throw new RuntimeException('Registo não encontrado.');
        }
        interaction_add($pdo, $dealId, $contactId, (string)($_POST['kind'] ?? 'note'), (string)($_POST['body'] ?? ''), (string)($_POST['date'] ?? ''));
        // Numa relação acompanhada, uma chamada, email ou reunião registada conta como toque (§13.5).
        if ($contactId && in_array((string)($_POST['kind'] ?? ''), ['call', 'email', 'meeting'], true)) {
            require_once __DIR__ . '/inc/contacts.php';
            if (relation_get($pdo, $contactId)) relation_touch($pdo, $contactId, null, INTERACTION_KINDS[(string)$_POST['kind']][0] . ' registada');
        }
        if (!$json) {
            require_once __DIR__ . '/inc/layout.php';
            flash('Interação registada.');
            $back = (string)($_POST['back'] ?? '');
            redirect(str_starts_with($back, '/admin/') ? $back : '/admin/');
        }
        out(200, ['ok' => true, 'msg' => 'Interação registada.', 'html' => timeline_html(timeline($pdo, $type, $id))]);

    // ── painel lateral ──────────────────────────────────────
    case 'panel':
        $type = (string)($_GET['type'] ?? '');
        $id = (int)($_GET['id'] ?? 0);
        if ($type === 'lead' || $type === 'deal') {
            $d = record_get($pdo, $id);
            $isLead = $d && in_array($d['phase'], ['lead', 'archived'], true);
            if (!record_reach($pdo, $d) || ($type === 'lead') !== $isLead) out(404, ['ok' => false, 'error' => 'Registo não encontrado.']);
            out(200, ['ok' => true] + ($isLead ? panel_lead($pdo, $d) : panel_deal($pdo, $d)));
        }
        // Os cartões da ficha do negócio (§15.2): o painel da aba de origem, já filtrado para o negócio.
        if (in_array($type, ['dcontacts', 'dbudget', 'dresult', 'dcosts', 'dtasks', 'dfiles', 'ddocs', 'dhist', 'dclose'], true)) {
            require_once __DIR__ . '/inc/deal_file.php';
            $d = record_get($pdo, $id);
            if (!$d || $d['code'] === null || !record_reach($pdo, $d)) out(404, ['ok' => false, 'error' => 'Negócio não encontrado.']);
            out(200, ['ok' => true] + deal_panel($pdo, $type, deal_file_row($pdo, $id)));
        }
        if ($type === 'mkt') {
            require_perm('marketing', PERM_VIEW);
            require_once __DIR__ . '/inc/marketing.php';
            $p = mkt_piece($pdo, $id);
            if (!$p) out(404, ['ok' => false, 'error' => 'Peça não encontrada.']);
            out(200, ['ok' => true] + mkt_panel($pdo, $p));
        }
        if ($type === 'contact') {
            require_perm('contactos', PERM_VIEW);
            require_once __DIR__ . '/inc/contacts.php';
            $c = contact_get($pdo, $id);
            if (!$c) out(404, ['ok' => false, 'error' => 'Contacto não encontrado.']);
            out(200, ['ok' => true] + panel_contact($pdo, $c));
        }
        if ($type === 'today') {
            // «O teu dia» do Dashboard (§30.1): o detalhe de um contador, só com o que a pessoa pode ver
            require_once __DIR__ . '/inc/alerts.php';
            $cols = today_columns($pdo);
            $c = $cols[$id] ?? null;
            if (!$c) out(404, ['ok' => false, 'error' => 'Nada para mostrar.']);
            [, $title, $tip, $items, $more, $none] = $c;
            out(200, ['ok' => true, 'title' => $title . ' · ' . count($items), 'url' => $more, 'html' => '<p class="small muted">' . e($tip) . '.</p>' . today_list_html($items, $more, $none)]);
        }
        break;


    // ── Leads v2 (§12) ──────────────────────────────────────
    // Ficha da lead: «Editar» abre todos os campos, «Gravar» grava-os de uma vez, com as mesmas
    // regras da edição no campo (empresa/pessoa em Contactos, prazo ao mudar de estado,
    // atribuição pela hierarquia) e um só «Anular». Tudo ou nada.
    case 'lead_save':
        if (!$post) break;
        $l = lead_for_edit($pdo);
        $id = (int)$l['id'];
        $in = $_POST['f'] ?? null;
        if (!is_array($in)) throw new RuntimeException('Nada para gravar.');
        $fields = inline_fields($l)['lead'];
        $want = [];
        foreach ($fields as $f => $def) {
            if (!array_key_exists($f, $in) && $def[0] !== 'multi') continue;
            $v = $in[$f] ?? [];
            if ($def[0] === 'multi') {
                if (!array_key_exists('types_sent', $in)) continue;   // o formulário marca que mandou os tipos
                $v = implode(', ', array_map('strval', is_array($v) ? $v : []));
            }
            if (!is_string($v)) throw new RuntimeException('Valor inválido.');
            $v = str_replace("\r\n", "\n", $v);   // as caixas de texto do browser mandam \r\n
            $cur = $f === 'owner_id' ? ($l['owner_id'] === null ? 'none' : (string)$l['owner_id']) : (string)$l[$f];
            if (trim($v) !== trim($cur)) $want[$f] = $v;
        }
        // «Trazido por» (§13.4): o contacto escolhido ou escrito; vazio = ninguém.
        $bbSet = false; $bbTo = null;
        if (array_key_exists('brought_by', $in)) {
            require_once __DIR__ . '/inc/contacts.php';
            $bbTo = contact_pick_resolve($pdo, $in['brought_by_id'] ?? '', is_string($in['brought_by']) ? $in['brought_by'] : '');
            $bbSet = $bbTo !== ($l['brought_by_id'] !== null ? (int)$l['brought_by_id'] : null);
        }
        $co = trim($want['company'] ?? (string)$l['company']);
        $pe = trim($want['person'] ?? (string)$l['person']);
        if ($co === '' && $pe === '') throw new RuntimeException('A lead precisa de empresa ou de pessoa de contacto.');
        // A que fica preenchida grava primeiro (a regra «empresa ou pessoa» vê o valor já gravado).
        uksort($want, fn($a, $b) => (int)!(in_array($a, ['company', 'person'], true) && trim($want[$a]) !== '')
                                  <=> (int)!(in_array($b, ['company', 'person'], true) && trim($want[$b]) !== ''));
        $subs = [];
        $pdo->beginTransaction();
        try {
            foreach ($want as $f => $v) {
                $before = record_get($pdo, $id);
                $r = inline_save($pdo, 'lead', $id, $f, $v);
                if ((string)$r['prev'] === (string)$r['value']) continue;
                if ($f === 'lead_state') {
                    $subs[] = ['lead_state', ['id' => $id, 'from' => $before['lead_state'], 'to' => $r['value'], 'last_touch' => $before['last_touch'],
                                              'state_since' => $before['state_since'], 'no_answer' => (int)$before['no_answer']]];
                } elseif ($f === 'owner_id') {
                    $subs[] = ['assign', ['id' => $id, 'from' => $before['owner_id'] !== null ? (int)$before['owner_id'] : null, 'to' => $r['value']]];
                } else {
                    $subs[] = ['inline', ['entity' => 'lead', 'id' => $id, 'field' => $f, 'prev' => $r['prev']]];
                }
            }
            if ($bbSet) {
                $subs[] = ['brought_by', ['id' => $id, 'from' => $l['brought_by_id'] !== null ? (int)$l['brought_by_id'] : null, 'to' => $bbTo]];
                deal_set_brought_by($pdo, record_get($pdo, $id), $bbTo, (int)current_user()['id']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $n = count($subs);
        $msg = $n === 0 ? 'Sem alterações.' : ($n === 1 ? '1 campo gravado.' : $n . ' campos gravados.');
        $token = $n ? undo_push('multi', ['subs' => array_reverse($subs), 'msg' => 'Ficha reposta como estava.']) : '';
        // Na ficha completa a página recarrega: o aviso com «Anular» aparece a seguir.
        if (!empty($_POST['flash'])) $_SESSION['flash'][] = ['m' => $msg, 'k' => 'ok', 'u' => $token];   // como flash() do layout
        $l = record_get($pdo, $id);
        [$dn, $dc] = lead_display($l);
        $owner = $l['owner_id'] ? (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$l['owner_id'])->fetchColumn() : 'Por atribuir';
        out(200, ['ok' => true, 'changed' => $n, 'msg' => $msg, 'undo' => $token,
                  'name' => $dn, 'company' => $dc, 'owner' => $owner] + lead_parts($pdo, $id));

    // Duplicados enquanto se escreve telefone/email (§12.1). Leads fora do meu
    // alcance aparecem só como «existe outro registo», sem nome.
    case 'lead_dups':
        require_perm('leads', PERM_EDIT);
        $d = find_duplicates($pdo, (string)($_GET['email'] ?? ''), (string)($_GET['phone'] ?? ''));
        $me = me_row($pdo);
        $leads = []; $hidden = 0;
        foreach ($d['deals'] as $r) {
            if (in_array($r['phase'], ['lead', 'archived'], true) && lead_in_reach($pdo, $r + ['owner_id' => $r['owner_id']], $me)) {
                $leads[] = ['id' => (int)$r['id'], 'name' => lead_display_name($r), 'phase' => $r['phase']];
            } elseif (record_reach($pdo, record_get($pdo, (int)$r['id']))) {
                $leads[] = ['id' => (int)$r['id'], 'name' => ($r['code'] ? $r['code'] . ' · ' : '') . $r['name'], 'phase' => $r['phase']];
            } else {
                $hidden++;
            }
        }
        $contacts = [];
        if (can('contactos')) {
            foreach ($d['contacts'] as $c) {
                $co = $c['company_id'] ? (string)$pdo->query('SELECT name FROM contacts WHERE id = ' . (int)$c['company_id'])->fetchColumn() : '';
                $contacts[] = ['id' => (int)$c['id'], 'name' => $c['name'], 'kind' => $c['kind'], 'company' => $co];
            }
        }
        out(200, ['ok' => true, 'leads' => $leads, 'contacts' => $contacts, 'hidden' => $hidden]);

    // Nova ação: cria uma tarefa ligada à lead (aparece também na aba Tarefas).
    case 'lead_task':
        if (!$post) break;
        $l = lead_for_edit($pdo);
        $kind = (string)($_POST['kind'] ?? 'task');
        if (!isset(LEAD_TASK_KINDS[$kind])) throw new RuntimeException('Escolha o tipo de ação.');
        $date = (string)($_POST['date'] ?? '');
        $time = (string)($_POST['time'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Indique a data.');
        if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) throw new RuntimeException('Indique uma hora válida.');
        $who = (int)($_POST['assignee'] ?? 0) ?: (int)current_user()['id'];
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$who]);
        $target = $st->fetch();
        if (!$target || !can_assign_to(me_row($pdo), $target)) throw new RuntimeException('Não pode atribuir tarefas a essa pessoa.');
        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 200));
        if ($title === '') $title = lead_task_default_title($kind, lead_display_name($l));
        $dur = (int)($_POST['duration'] ?? 0);
        lead_task_create($pdo, $l, $kind, $title, trim(mb_substr((string)($_POST['notes'] ?? ''), 0, 4000)),
                         $date . ($time !== '' ? ' ' . $time : ''), $dur > 0 && $dur <= 600 ? $dur : null, $who,
                         (string)($_POST['repeat'] ?? ''), (int)current_user()['id'], (string)($_POST['platform'] ?? ''), (string)($_POST['location'] ?? ''));
        out(200, ['ok' => true, 'msg' => 'Tarefa criada.'] + lead_parts($pdo, (int)$l['id']));

    // Foco: concluir. Uma chamada abre «Como correu?» (§12.8).
    case 'lead_task_done':
        if (!$post) break;
        $l = lead_for_edit($pdo, false);
        $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND deal_id = ?');
        $st->execute([(int)($_POST['task'] ?? 0), (int)$l['id']]);
        $t = $st->fetch();
        if (!$t || $t['status'] === 'done') throw new RuntimeException('Essa tarefa já não está aberta.');
        // Uma chamada de uma lead ativa só fica feita com o resultado: primeiro «Como correu?».
        if ($t['kind'] === 'call' && $l['phase'] === 'lead') out(200, ['ok' => true, 'needs_outcome' => true, 'task' => (int)$t['id']]);
        $snap = ['id' => (int)$t['id'], 'status' => $t['status'], 'done_at' => $t['done_at'], 'member_id' => $t['member_id'], 'position' => $t['position']];
        $snap['spawned'] = task_done($pdo, (int)$t['id']);   // fica no histórico da lead; se se repete, cria a seguinte
        // Reunião, email, almoço ou jantar feitos contam como contacto e, em Follow-Up, como follow-up.
        if (in_array($t['kind'], ['meeting', 'email', 'lunch', 'dinner'], true) && $l['phase'] === 'lead') {
            lead_touch($pdo, (int)$l['id']);
            if ($l['lead_state'] === 'follow_up') $pdo->prepare('UPDATE deals SET state_since = ? WHERE id = ?')->execute([now_php(), $l['id']]);
        }
        out(200, ['ok' => true, 'msg' => '«' . $t['title'] . '» feita.', 'undo' => undo_push('task', $snap),
                  'call' => $t['kind'] === 'call' && $l['phase'] === 'lead'] + lead_parts($pdo, (int)$l['id']));

    // «Como correu?»: chamada não atendida ou follow-up (os outros dois botões são Arquivar e Converter).
    case 'lead_outcome':
        if (!$post) break;
        $l = lead_for_edit($pdo);
        $res = (string)($_POST['result'] ?? '');
        if (!in_array($res, ['missed', 'followup', 'answered'], true)) throw new RuntimeException('Escolha como correu.');
        // A chamada que deu origem ao «Como correu?» fica feita agora, com o resultado.
        if (($tid = (int)($_POST['task'] ?? 0)) > 0) {
            $st = $pdo->prepare("SELECT * FROM tasks WHERE id = ? AND deal_id = ? AND kind = 'call'");
            $st->execute([$tid, (int)$l['id']]);
            $t = $st->fetch();
            if (!$t) throw new RuntimeException('Essa chamada já não existe. Recarregue a página.');
            if ($t['status'] !== 'done') {
                $GLOBALS['LEAD_OUTCOME_OK'] = true;
                task_done($pdo, $tid);
                unset($GLOBALS['LEAD_OUTCOME_OK']);
            }
        }
        if ($res === 'answered') {   // atendida, a seguir vem Arquivar ou Converter
            deal_log($pdo, (int)$l['id'], 'call_ok', ['note' => 'Chamada atendida']);
            lead_touch($pdo, (int)$l['id']);
            out(200, ['ok' => true, 'msg' => 'Chamada registada.'] + lead_parts($pdo, (int)$l['id']));
        }
        if ($res === 'missed') {
            $n = (int)$l['no_answer'] + 1;
            $pdo->prepare("UPDATE deals SET lead_state = 'sem_resposta', state_since = ?, no_answer = ? WHERE id = ?")->execute([now_php(), $n, $l['id']]);
            deal_log($pdo, (int)$l['id'], 'call_miss', ['note' => $n . '.ª tentativa · prazo recomeçou']);
            lead_touch($pdo, (int)$l['id']);
            out(200, ['ok' => true, 'msg' => 'Chamada não atendida (' . $n . '.ª tentativa).', 'attempt' => $n, 'suggest_archive' => $n >= max(2, (int)setting($pdo, 'lead_attempts_archive', 3)),
                      'next' => lead_next_urgent($pdo, me_row($pdo), (int)$l['id'])] + lead_parts($pdo, (int)$l['id']));
        }
        if ($res === 'followup') {
            $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 4000));
            $pdo->prepare("UPDATE deals SET lead_state = 'follow_up', state_since = ?, no_answer = 0 WHERE id = ?")->execute([now_php(), $l['id']]);
            deal_log($pdo, (int)$l['id'], 'call_ok', ['note' => $l['lead_state'] !== 'follow_up' ? (LEAD_STATE[$l['lead_state']] ?? '') . ' → Follow-Up' : 'Follow-Up']);
            if ($note !== '') interaction_add($pdo, (int)$l['id'], null, 'call', $note, today_lisbon());
            lead_touch($pdo, (int)$l['id']);
            out(200, ['ok' => true, 'msg' => 'Follow-Up registado. Marque o passo seguinte.',
                      'next' => lead_next_urgent($pdo, me_row($pdo), (int)$l['id'])] + lead_parts($pdo, (int)$l['id']));
        }
        throw new RuntimeException('Escolha como correu.');

    // Arquivar com motivo (§12.9), com «Anular».
    case 'lead_archive':
        if (!$post) break;
        $l = lead_for_edit($pdo);
        $r = (string)($_POST['reason'] ?? '');
        $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 255));
        if (!isset(lead_archive_reasons($pdo)[$r])) throw new RuntimeException('Escolha o motivo.');
        if ($r === 'outro' && $note === '') throw new RuntimeException('Escreva o motivo.');
        out(200, ['ok' => true, 'msg' => 'Lead arquivada.', 'undo' => undo_push('lead_archive', lead_archive($pdo, $l, $r, $note)),
                  'next' => lead_next_urgent($pdo, me_row($pdo), (int)$l['id'])]);

    case 'lead_next':
        require_perm('leads', PERM_VIEW);
        out(200, ['ok' => true, 'next' => lead_next_urgent($pdo, me_row($pdo), (int)($_GET['id'] ?? 0))]);


    // ── Contactos v2 (§13) ──────────────────────────────────
    // Duplicados enquanto se escreve no «+ Adicionar contacto»: mesmo email/telefone, ou o mesmo nome.
    case 'contact_dups':
        require_perm('contactos', PERM_EDIT);
        require_once __DIR__ . '/inc/contacts.php';
        $d = find_duplicates($pdo, (string)($_GET['email'] ?? ''), (string)($_GET['phone'] ?? ''));
        $found = [];
        foreach ($d['contacts'] as $c) $found[(int)$c['id']] = 'email ou telefone';
        $name = trim((string)($_GET['name'] ?? ''));
        if (mb_strlen($name) >= 3) {
            $st = $pdo->prepare('SELECT id FROM contacts WHERE LOWER(TRIM(name)) = ? LIMIT 5');
            $st->execute([mb_strtolower($name)]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) $found[(int)$cid] ??= 'nome';
        }
        $list = [];
        foreach ($found as $cid => $why) {
            $c = contact_get($pdo, $cid);
            if ($c) $list[] = ['id' => $cid, 'name' => $c['name'], 'kind' => $c['kind'], 'sub' => contact_sub($c), 'why' => $why];
        }
        out(200, ['ok' => true, 'contacts' => $list]);

    case 'contact_pick':
        if (!can('contactos') && !can('leads') && !can('pipeline') && !can('negocios')) out(403, ['ok' => false, 'error' => 'Sem permissão.']);
        require_once __DIR__ . '/inc/contacts.php';
        out(200, ['ok' => true, 'items' => contact_pick_search($pdo, (string)($_GET['q'] ?? ''))]);

    // Ficha: «Editar» abre todos os campos, «Gravar» grava-os de uma vez, com um só «Anular».
    case 'contact_save':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $id = (int)$c['id'];
        $in = $_POST['f'] ?? null;
        if (!is_array($in)) throw new RuntimeException('Nada para gravar.');
        $in = array_map(fn($v) => is_string($v) ? str_replace("\r\n", "\n", $v) : $v, $in);
        // Só muda o que vem no pedido: o resto fica como está.
        $cur = ['name' => $c['name'], 'role' => $c['role'], 'email' => $c['email'], 'phone' => $c['phone'], 'links' => (string)$c['links'], 'nif' => $c['nif'],
                'address' => $c['address'], 'birthday' => (string)$c['birthday'], 'notes' => (string)$c['notes'], 'company' => (string)$c['company_name']];
        foreach (array_keys(CONTACT_TYPES) as $f) if ((int)$c[$f] === 1) $cur[$f] = '1';
        if (array_key_exists('lang', $c)) $cur['lang'] = (string)$c['lang'];
        // Os tipos: o formulário marca que os mandou (os desmarcados não vêm).
        $in = array_key_exists('types_sent', $in) ? $in + array_diff_key($cur, CONTACT_TYPES) : $in + $cur;
        $kind = in_array($in['kind'] ?? '', ['person', 'company'], true) ? $in['kind'] : $c['kind'];
        if ($kind === 'person' && $c['kind'] === 'company' && contact_people($pdo, $id)) throw new RuntimeException('Esta empresa tem pessoas ligadas: continua empresa.');
        $v = contact_input($in, $kind);
        if ($v['name'] === '') throw new RuntimeException('Indique o nome.');
        $coName = $kind === 'person' ? trim((string)($in['company'] ?? (string)$c['company_name'])) : '';
        if ($v['is_freelancer'] && $coName !== '') throw new RuntimeException('Um freelancer é uma pessoa sem empresa: tire a empresa ou desmarque Freelancer.');
        if ($coName !== '' && mb_strtolower($coName) === mb_strtolower($v['name'])) throw new RuntimeException('A empresa não pode ter o mesmo nome da pessoa.');
        $before = contact_snapshot($c);
        $labels = ['name' => 'nome', 'role' => 'cargo', 'email' => 'email', 'phone' => 'telefone', 'links' => 'redes', 'nif' => 'NIF', 'address' => 'morada',
                   'birthday' => 'aniversário', 'notes' => 'notas', 'lang' => 'idioma', 'is_client' => 'tipo', 'is_supplier' => 'tipo', 'is_freelancer' => 'tipo', 'is_partner' => 'tipo'];
        $pdo->beginTransaction();
        try {
            $set = $v + ($kind === 'company' ? ['company_id' => null, 'role' => ''] : []);
            $pdo->prepare('UPDATE contacts SET kind = ?, ' . implode(', ', array_map(fn($f) => "$f = ?", array_keys($set))) . ' WHERE id = ?')
                ->execute([$kind, ...array_values($set), $id]);
            if ($kind === 'person') contact_set_company($pdo, contact_get($pdo, $id), $coName, (int)current_user()['id']);
            $now = contact_get($pdo, $id);
            $changed = [];
            foreach ($labels as $f => $lab) if (array_key_exists($f, $before) && (string)$before[$f] !== (string)$now[$f]) $changed[$lab] = true;
            if ($kind !== $c['kind']) $changed[$kind === 'person' ? 'passou a pessoa' : 'passou a empresa'] = true;
            if ($changed) contact_log($pdo, $id, 'c_edit', ucfirst(implode(', ', array_keys($changed))));
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $n = count($changed) + ((int)$before['company_id'] !== (int)$now['company_id'] ? 1 : 0);
        $msg = $n === 0 ? 'Sem alterações.' : 'Contacto gravado.';
        $token = $n ? undo_push('contact_restore', ['id' => $id, 'before' => $before, 'after' => contact_snapshot($now), 'kind_before' => $c['kind'], 'kind_after' => $kind]) : '';
        if (!empty($_POST['flash'])) $_SESSION['flash'][] = ['m' => $msg, 'k' => 'ok', 'u' => $token];
        out(200, ['ok' => true, 'changed' => $n, 'msg' => $msg, 'undo' => $token, 'name' => $now['name'], 'sub' => contact_sub($now)]);

    case 'contact_active':
        if (!$post) break;
        require_perm('contactos', PERM_VIEW);
        require_once __DIR__ . '/inc/contact_view.php';
        if (!contacts_admin($pdo)) throw new RuntimeException('Só superadmin e admins mudam o estado Ativo / Inativo.');
        $c = contact_get($pdo, (int)($_POST['id'] ?? 0));
        if (!$c) throw new RuntimeException('Contacto não encontrado.');
        if (!contact_is_provider($c)) throw new RuntimeException('Só fornecedores e freelancers têm estado Ativo / Inativo.');
        $to = (int)$c['is_active'] === 1 ? 0 : 1;
        $pdo->prepare('UPDATE contacts SET is_active = ? WHERE id = ?')->execute([$to, $c['id']]);
        contact_log($pdo, (int)$c['id'], 'c_active', $to ? 'Inativo → Ativo' : 'Ativo → Inativo');
        out(200, ['ok' => true, 'msg' => $c['name'] . ($to ? ' está ativo.' : ' está inativo: deixa de aparecer na Orçamentação.'), 'active' => $to,
                  'html' => contact_active_html(contact_get($pdo, (int)$c['id']), true),
                  'undo' => undo_push('contact_active', ['id' => (int)$c['id'], 'from' => 1 - $to, 'to' => $to])]);

    case 'service_save':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        require_once __DIR__ . '/inc/contact_view.php';
        if (!contact_is_provider($c)) throw new RuntimeException('Só fornecedores e freelancers têm serviços e preços.');
        $v = service_line_input($pdo, $_POST);
        service_line_save($pdo, (int)$c['id'], (int)($_POST['line'] ?? 0) ?: null, $v, (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => 'Serviço gravado.', 'html' => contact_services_html($pdo, contact_get($pdo, (int)$c['id']), true),
                  'history' => timeline_html(timeline($pdo, 'contact', (int)$c['id']))]);

    case 'service_remove':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        require_once __DIR__ . '/inc/contact_view.php';
        $st = $pdo->prepare('SELECT cs.*, s.name AS service_name FROM contact_services cs LEFT JOIN services s ON s.id = cs.service_id WHERE cs.id = ? AND cs.contact_id = ? AND cs.ended_at IS NULL');
        $st->execute([(int)($_POST['line'] ?? 0), (int)$c['id']]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('Esta linha já não está em vigor.');
        $pdo->prepare('UPDATE contact_services SET ended_at = ? WHERE id = ?')->execute([today_lisbon(), $r['id']]);
        contact_log($pdo, (int)$c['id'], 'c_price', 'Tirado: ' . service_line_label($r) . ' · ' . service_line_price($r));
        out(200, ['ok' => true, 'msg' => '«' . service_line_label($r) . '» tirado.', 'undo' => undo_push('service_line', ['id' => (int)$c['id'], 'line' => (int)$r['id']]),
                  'html' => contact_services_html($pdo, contact_get($pdo, (int)$c['id']), true), 'history' => timeline_html(timeline($pdo, 'contact', (int)$c['id']))]);

    // Nova ação do contacto: uma tarefa ligada a ele (aparece também em Tarefas).
    case 'contact_task':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $kind = (string)($_POST['kind'] ?? 'task');
        if (!isset(LEAD_TASK_KINDS[$kind])) throw new RuntimeException('Escolha o tipo de ação.');
        $date = (string)($_POST['date'] ?? '');
        $time = (string)($_POST['time'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Indique a data.');
        if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) throw new RuntimeException('Indique uma hora válida.');
        $who = (int)($_POST['assignee'] ?? 0) ?: (int)current_user()['id'];
        $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$who]);
        $target = $st->fetch();
        if (!$target || !can_assign_to(me_row($pdo), $target)) throw new RuntimeException('Não pode atribuir tarefas a essa pessoa.');
        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 200));
        if ($title === '') $title = lead_task_default_title($kind, $c['name']);
        $dur = (int)($_POST['duration'] ?? 0);
        contact_task_create($pdo, (int)$c['id'], $kind, $title, trim(mb_substr((string)($_POST['notes'] ?? ''), 0, 4000)),
                            $date . ($time !== '' ? ' ' . $time : ''), $dur > 0 && $dur <= 600 ? $dur : null, $who,
                            (string)($_POST['repeat'] ?? ''), (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => 'Tarefa criada.'] + contact_parts($pdo, (int)$c['id']));

    case 'contact_task_done':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND contact_id = ?');
        $st->execute([(int)($_POST['task'] ?? 0), (int)$c['id']]);
        $t = $st->fetch();
        if (!$t || $t['status'] === 'done') throw new RuntimeException('Essa tarefa já não está aberta.');
        $rel = relation_snapshot($pdo, (int)$c['id']);
        $snap = ['id' => (int)$c['id'], 'task' => (int)$t['id'], 'status' => $t['status'], 'member_id' => $t['member_id'], 'position' => $t['position']];
        $snap['spawned'] = task_done($pdo, (int)$t['id']);   // histórico do contacto e, numa relação, o toque
        if ($rel && relation_snapshot($pdo, (int)$c['id']) != $rel) $snap['rel'] = $rel;
        out(200, ['ok' => true, 'msg' => '«' . $t['title'] . '» feita.', 'undo' => undo_push('contact_task', $snap),
                  'relation_followup' => $t['kind'] === 'relation'] + contact_parts($pdo, (int)$c['id']));

    // ── relações (§13.5) ──
    case 'relation_set':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $before = relation_snapshot($pdo, (int)$c['id']);
        $v = relation_input($pdo, $_POST);
        relation_set($pdo, (int)$c['id'], $v, (int)current_user()['id']);
        $token = $before ? undo_push('relation_row', $before) : undo_push('relation_drop', ['id' => (int)$c['id']]);
        out(200, ['ok' => true, 'msg' => $before ? 'Relação atualizada (nível ' . $v['level'] . ').' : 'Relação acompanhada: nível ' . $v['level'] . '.', 'undo' => $token]
                 + contact_parts($pdo, (int)$c['id']));

    case 'relation_outcome':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $id = (int)$c['id'];
        $r = relation_get($pdo, $id);
        if (!$r) throw new RuntimeException('Esta relação já não é acompanhada.');
        $res = (string)($_POST['result'] ?? '');
        $fmt = fn(string $d) => lisbon_fmt(lisbon_ts($d . ' 12:00'), 'd/m');
        if ($res === 'talked') {
            $next = (string)($_POST['next'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $next) || $next < today_lisbon()) throw new RuntimeException('Escolha a data do próximo toque (hoje ou depois).');
            $note = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 4000));
            $pdo->prepare('UPDATE relations SET next_at = ? WHERE contact_id = ?')->execute([$next, $id]);
            if (mb_strlen($note) > 200) interaction_add($pdo, null, $id, 'note', 'Falámos: ' . $note, today_lisbon());
            contact_log($pdo, $id, 'rel_touch', 'Falámos' . ($note !== '' ? ': ' . shorten($note, 200) : '') . ' · próximo toque ' . $fmt($next));
            out(200, ['ok' => true, 'msg' => 'Registado. Próximo toque a ' . $fmt($next) . '.'] + contact_parts($pdo, $id));
        }
        if ($res === 'missed') {
            $next = (new DateTimeImmutable(today_lisbon()))->modify('+2 days')->format('Y-m-d');
            // O follow-up não conta como toque: volta o último toque de antes.
            $pdo->prepare('UPDATE relations SET last_touch_at = last_touch_prev, touches = CASE WHEN touches > 0 THEN touches - 1 ELSE 0 END, next_at = ? WHERE contact_id = ?')
                ->execute([$next, $id]);
            contact_log($pdo, $id, 'rel_miss', 'Nova tentativa a ' . $fmt($next));
            out(200, ['ok' => true, 'msg' => 'Não atendeu: nova tentativa a ' . $fmt($next) . '.'] + contact_parts($pdo, $id));
        }
        if ($res === 'meeting') {
            $date = (string)($_POST['date'] ?? '');
            $time = (string)($_POST['time'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Indique o dia da reunião.');
            if ($time !== '' && !preg_match('/^\d{2}:\d{2}$/', $time)) $time = '';
            $owner = $r['owner_id'] !== null ? (int)$r['owner_id'] : (int)current_user()['id'];
            contact_task_create($pdo, $id, 'meeting', 'Reunião com ' . $c['name'], trim(mb_substr((string)($_POST['note'] ?? ''), 0, 4000)),
                                $date . ($time !== '' ? ' ' . $time : ''), 60, $owner, '', (int)current_user()['id']);
            contact_log($pdo, $id, 'rel_meet', 'Reunião a ' . $fmt($date) . ($time !== '' ? ' ' . $time : ''));
            out(200, ['ok' => true, 'msg' => 'Reunião marcada para ' . $fmt($date) . '.'] + contact_parts($pdo, $id));
        }
        if ($res === 'lead') {
            if (!can('leads', PERM_EDIT)) throw new RuntimeException('Sem acesso para criar leads. Peça a quem trabalha as Leads.');
            contact_log($pdo, $id, 'rel_lead', 'Abriu «+ Adicionar Lead» com «Trazido por» ' . $c['name']);
            out(200, ['ok' => true, 'url' => '/admin/leads.php?nova=1&trazido=' . $id]);
        }
        throw new RuntimeException('Escolha como correu.');

    case 'relation_pause':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $before = relation_snapshot($pdo, (int)$c['id']);
        if (!$before) throw new RuntimeException('Esta relação já não é acompanhada.');
        relation_pause($pdo, (int)$c['id'], (string)($_POST['until'] ?? ''), (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => 'Relação pausada.', 'undo' => undo_push('relation_row', $before)] + contact_parts($pdo, (int)$c['id']));

    case 'relation_resume':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $before = relation_snapshot($pdo, (int)$c['id']);
        if (!$before) throw new RuntimeException('Esta relação já não é acompanhada.');
        relation_resume($pdo, (int)$c['id'], (int)current_user()['id']);
        relation_tick($pdo);
        out(200, ['ok' => true, 'msg' => 'Relação retomada: o próximo toque é hoje.', 'undo' => undo_push('relation_row', $before)] + contact_parts($pdo, (int)$c['id']));

    case 'relation_stop':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $before = relation_snapshot($pdo, (int)$c['id']);
        if (!$before) throw new RuntimeException('Esta relação já não é acompanhada.');
        relation_stop($pdo, (int)$c['id'], (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => 'Deixou de ser acompanhada.', 'undo' => undo_push('relation_row', $before)] + contact_parts($pdo, (int)$c['id']));

    case 'relation_review':
        if (!$post) break;
        $c = contact_for_edit($pdo);
        $r = relation_get($pdo, (int)$c['id']);
        if (!$r) throw new RuntimeException('Esta relação já não é acompanhada.');
        $before = relation_snapshot($pdo, (int)$c['id']);
        if (($_POST['decision'] ?? '') === 'accept') {
            $p = max(1, min(5, (int)($_POST['potential'] ?? $r['potential'])));
            $lvl = relation_level($r['formality'], $p, $r['business']);
            $pdo->prepare('UPDATE relations SET potential = ?, level = ?, reviewed_at = ? WHERE contact_id = ?')->execute([$p, $lvl, today_lisbon(), $c['id']]);
            contact_log($pdo, (int)$c['id'], 'rel_level', 'Revisão: potencial ' . $r['potential'] . ' → ' . $p . ' · nível ' . $r['level'] . ' → ' . $lvl);
            $msg = 'Nível revisto: ' . $lvl . '.';
        } else {
            $pdo->prepare('UPDATE relations SET reviewed_at = ? WHERE contact_id = ?')->execute([today_lisbon(), $c['id']]);
            contact_log($pdo, (int)$c['id'], 'rel_level', 'Revisão: mantém o nível ' . $r['level']);
            $msg = 'Mantém o nível ' . $r['level'] . '.';
        }
        out(200, ['ok' => true, 'msg' => $msg, 'undo' => undo_push('relation_row', $before)] + contact_parts($pdo, (int)$c['id']));

    case 'contact_merge':
        if (!$post) break;
        require_perm('contactos', PERM_MANAGE);
        require_once __DIR__ . '/inc/contacts.php';
        $keep = (int)($_POST['id'] ?? 0);
        $other = (int)($_POST['other'] ?? 0);
        $o = contact_get($pdo, $other);
        contact_merge($pdo, $keep, $other, (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => '«' . ($o['name'] ?? '') . '» juntado. O histórico dos dois fica aqui.', 'url' => '/admin/contact.php?id=' . $keep]);

    // Avaliação de um trabalho de fornecedor/freelancer, quando o custo é pago (§13.3).
    case 'cost_rate':
        if (!$post) break;
        require_perm('financeiro', PERM_EDIT);
        require_once __DIR__ . '/inc/contacts.php';
        $cost = cost_needs_rating($pdo, (int)($_POST['cost'] ?? 0));
        if (!$cost) throw new RuntimeException('Este trabalho já foi avaliado ou não é de um fornecedor.');
        cost_rate($pdo, $cost, (int)($_POST['stars'] ?? 0), (int)current_user()['id']);
        [$avg, $n] = contact_rating($pdo, (int)$cost['contact_id']);
        out(200, ['ok' => true, 'msg' => $cost['contact_name'] . ' avaliado: média ' . number_format((float)$avg, 1, ',', '') . ' (' . $n . ').']);

    // ── Orçamentação (§14) ──────────────────────────────────
    case 'budget_suppliers':
        require_perm('orcamentacao', PERM_VIEW);
        require_once __DIR__ . '/inc/budget_view.php';
        $money = budget_money();
        $items = [];
        foreach (suppliers_for_service($pdo, (int)($_GET['service'] ?? 0)) as $o) {
            $items[] = ['id' => (int)$o['id'], 'name' => $o['name'], 'unit' => (string)$o['unit'], 'unit_label' => SERVICE_UNITS[$o['unit']] ?? (string)$o['unit'],
                        'timed' => budget_unit_hours((string)$o['unit'], 8) !== null, 'missing' => $o['price'] === null,
                        'price' => $money && $o['price'] !== null ? budget_unit_price_label(['unit_price' => $o['price'], 'price_unit' => $o['unit']]) : null,
                        'rating' => $o['rating'] !== null ? number_format((float)$o['rating'], 1, ',', '') : null];
        }
        out(200, ['ok' => true, 'items' => $items, 'money' => $money]);

    case 'budget_set':
        if (!$post) break;
        [$sheet, $v] = budget_version_for_edit($pdo, (int)($_POST['version'] ?? 0));
        $field = (string)($_POST['field'] ?? '');
        $raw = (string)($_POST['value'] ?? '');
        $money = budget_money();
        $date = function (string $x): ?string {
            if ($x === '') return null;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) || !strtotime($x)) throw new RuntimeException('Data inválida.');
            return $x;
        };
        $set = [];
        switch ($field) {
            case 'dur_mode':
                if (!isset(BUDGET_DUR_MODES[$raw])) throw new RuntimeException('Modo de duração inválido.');
                $set['dur_mode'] = $raw;
                if ($raw === 'phases' && !budget_phases($v)) {
                    $set['phases'] = json_encode(array_map(fn($n) => ['name' => $n, 'days' => 0], BUDGET_PHASES), JSON_UNESCAPED_UNICODE);
                }
                break;
            case 'start_date': case 'end_date':
                $set[$field] = $date($raw);
                $s0 = $field === 'start_date' ? $set[$field] : $v['start_date'];
                $e0 = $field === 'end_date' ? $set[$field] : $v['end_date'];
                if ($s0 && $e0 && $e0 < $s0) throw new RuntimeException('O fim tem de ser depois do início.');
                break;
            case 'weeks': $set['weeks'] = $raw === '' ? null : budget_num($raw, 520); break;
            case 'days':  $set['days'] = $raw === '' ? null : budget_num($raw, 2600); break;
            case 'phases':
                $ph = json_decode($raw, true);
                if (!is_array($ph)) throw new RuntimeException('Fases inválidas.');
                $clean = budget_phases(['phases' => json_encode(array_slice(array_map(fn($p) => ['name' => (string)($p['name'] ?? ''), 'days' => budget_num($p['days'] ?? 0, 1000)], $ph), 0, 20))]);
                $set['phases'] = json_encode($clean, JSON_UNESCAPED_UNICODE);
                break;
            case 'occupancy': $set['occupancy'] = $raw === '' ? null : budget_num($raw, 100); break;
            case 'margin':
                if (!$money) throw new RuntimeException('Só quem vê o Financeiro muda a margem.');
                $m = budget_num($raw, 95);
                if ($raw === '') throw new RuntimeException('Indique a margem.');
                if (($_POST['opt'] ?? 'A') === 'B') {
                    // A Opção B pode ter a sua margem (§19.3); por defeito usa a da A.
                    $was = $v['margin_b'] !== null ? (float)$v['margin_b'] : (float)$v['margin'];
                    if (abs($m - $was) > 0.001) budget_log($pdo, (int)$sheet['id'], 'b_margin', 'v' . $v['num'] . ' · Opção B · margem ' . budget_pct($was) . ' → ' . budget_pct($m));
                    $set['margin_b'] = $m;
                    break;
                }
                if (abs($m - (float)$v['margin']) > 0.001) budget_log_margin($pdo, (int)$sheet['id'], (int)$v['num'], (float)$v['margin'], $m);
                $set['margin'] = $m;
                break;
            case 'fixed_monthly':
                if (!$money) throw new RuntimeException('Só quem vê o Financeiro muda os custos fixos.');
                $set['fixed_monthly'] = budget_num($raw, 10000000);
                $set['fixed_manual'] = 1;
                break;
            case 'description': $set['description'] = mb_substr(trim($raw), 0, 6000); break;
            case 'conditions':  $set['conditions'] = mb_substr(trim($raw), 0, 4000); break;
            // Os campos da proposta (§19.1, §19.3): âmbito da Opção B, prazo, pagamento, revisões, direitos, validade.
            case 'scope_b':        $set['scope_b'] = mb_substr(trim($raw), 0, 6000); break;
            case 'term_text':      $set['term_text'] = mb_substr(trim($raw), 0, 255); break;
            case 'payment_terms':  $set['payment_terms'] = mb_substr(trim($raw), 0, 2000); break;
            case 'revisions_text': $set['revisions_text'] = mb_substr(trim($raw), 0, 2000); break;
            case 'rights_text':    $set['rights_text'] = mb_substr(trim($raw), 0, 2000); break;
            case 'valid_until':
                $set['valid_until'] = $date($raw);
                if ($set['valid_until'] && $set['valid_until'] < today_lisbon()) throw new RuntimeException('A validade tem de ser hoje ou depois.');
                break;
            default: throw new RuntimeException('Campo inválido.');
        }
        $pdo->prepare('UPDATE budget_versions SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?')
            ->execute([...array_values($set), $v['id']]);
        $v2 = budget_version_get($pdo, (int)$v['id']);
        out(200, ['ok' => true] + budget_parts($pdo, (int)$v['id']) + (in_array($field, ['phases', 'start_date', 'dur_mode'], true) ? ['phases' => budget_phases_rows($v2, true)] : []));

    // Opção B a partir da A (§19.3): copia as linhas (a B fica com as suas, para mexer à vontade).
    case 'budget_copy_a':
        if (!$post) break;
        [$sheet, $v] = budget_version_for_edit($pdo, (int)($_POST['version'] ?? 0));
        if (budget_lines($pdo, (int)$v['id'], 'B')) throw new RuntimeException('A Opção B já tem linhas.');
        $a = budget_lines($pdo, (int)$v['id'], 'A');
        if (!$a) throw new RuntimeException('A Opção A ainda não tem linhas.');
        foreach ($a as $l) {
            $nid = budget_line_save($pdo, $v, null, ['kind' => $l['kind'], 'service_id' => $l['service_id'], 'contact_id' => $l['contact_id'], 'user_id' => $l['user_id'],
                'label' => $l['label'], 'price_unit' => $l['price_unit'], 'unit_price' => (float)$l['unit_price'], 'qty' => (float)$l['qty'], 'qty_unit' => $l['qty_unit'],
                'note' => $l['note'], 'opt' => 'B']);
            if (!empty($l['funnel_id'])) $pdo->prepare('UPDATE budget_lines SET funnel_id = ? WHERE id = ?')->execute([$l['funnel_id'], $nid]);
        }
        if (trim((string)$v['scope_b']) === '' && trim((string)$v['description']) !== '') $pdo->prepare('UPDATE budget_versions SET scope_b = ? WHERE id = ?')->execute([$v['description'], $v['id']]);
        budget_log($pdo, (int)$sheet['id'], 'b_line', 'v' . $v['num'] . ' · Opção B criada a partir da A (' . count($a) . ' linhas)');
        $_POST['opt'] = 'B';
        out(200, ['ok' => true, 'msg' => 'Opção B criada com as linhas da A: acrescente o que a torna «proposta de valor».', 'reload' => true] + budget_parts($pdo, (int)$v['id'], true));

    case 'budget_fixed_avg':
        if (!$post) break;
        [$sheet, $v] = budget_version_for_edit($pdo, (int)($_POST['version'] ?? 0));
        if (!budget_money()) throw new RuntimeException('Só quem vê o Financeiro muda os custos fixos.');
        $avg = budget_fixed_average($pdo)['avg'];
        $pdo->prepare('UPDATE budget_versions SET fixed_monthly = ?, fixed_manual = 0 WHERE id = ?')->execute([$avg, $v['id']]);
        out(200, ['ok' => true, 'msg' => 'Custos fixos: média dos 3 últimos meses (' . budget_eur($avg) . ').', 'fixed' => budget_n($avg)] + budget_parts($pdo, (int)$v['id']));

    case 'budget_line_save':
        if (!$post) break;
        [$sheet, $v] = budget_version_for_edit($pdo, (int)($_POST['version'] ?? 0));
        $lineId = (int)($_POST['line'] ?? 0);
        $old = null;
        if ($lineId) {
            $st = $pdo->prepare('SELECT * FROM budget_lines WHERE id = ? AND version_id = ?');
            $st->execute([$lineId, $v['id']]);
            $old = $st->fetch();
            if (!$old) throw new RuntimeException('Esta linha já não existe. Recarregue a página.');
        }
        $kind = (string)($_POST['kind'] ?? '');
        $src = ['kind' => $kind, 'service_id' => $_POST['service_id'] ?? 0, 'contact_id' => $_POST['contact_id'] ?? 0, 'user_id' => $_POST['user_id'] ?? 0,
                'label' => $kind === 'team' ? ($_POST['team_label'] ?? '') : ($_POST['other_label'] ?? ''), 'price_unit' => $_POST['price_unit'] ?? 'fixed',
                'unit_price' => $_POST['unit_price'] ?? 0, 'qty' => $_POST['qty'] ?? 0, 'qty_unit' => $_POST['qty_unit'] ?? 'hour', 'note' => $_POST['note'] ?? ''];
        // Alterar uma linha de quem já lá estava não muda o preço copiado (§14.2).
        $keep = $old && $old['kind'] === $kind && (($kind === 'supplier' && (int)$old['service_id'] === (int)$src['service_id'] && (int)$old['contact_id'] === (int)$src['contact_id'])
                                                  || ($kind === 'team' && (int)$old['user_id'] === (int)$src['user_id']));
        if ($keep && $kind === 'supplier') {
            // O fornecedor pode ter ficado inativo ou mudado o preço: a linha guarda o que foi copiado.
            $d = ['kind' => 'supplier', 'service_id' => (int)$old['service_id'], 'contact_id' => (int)$old['contact_id'], 'user_id' => null, 'label' => $old['label'],
                  'price_unit' => $old['price_unit'], 'unit_price' => (float)$old['unit_price'], 'qty' => budget_num($src['qty']),
                  'qty_unit' => budget_unit_hours((string)$old['price_unit'], 8) !== null ? (($src['qty_unit'] === 'day') ? 'day' : 'hour') : $old['price_unit'],
                  'note' => mb_substr(trim((string)$src['note']), 0, 255)];
            if ($d['qty'] <= 0) throw new RuntimeException('Indique a quantidade.');
        } else {
            if (!budget_money() && $kind === 'other' && $old && $old['kind'] === 'other' && trim((string)$src['unit_price']) === '') $src['unit_price'] = $old['unit_price'];
            $d = budget_line_input($pdo, $v, $src);
            if ($keep && $kind === 'team') $d['unit_price'] = (float)$old['unit_price'];
        }
        if (!$lineId) $d['opt'] = ($_POST['opt'] ?? 'A') === 'B' ? 'B' : 'A';
        $lid = budget_line_save($pdo, $v, $lineId ?: null, $d);
        // Crosselling (§22): o serviço da linha (vazio = o principal), só entre os serviços do negócio.
        if (array_key_exists('funnel_id', $_POST) && db_has_column($pdo, 'budget_lines', 'funnel_id')) {
            require_once __DIR__ . '/inc/xsell.php';
            $fn = (int)$_POST['funnel_id'];
            $ok = $fn && in_array($fn, array_map(fn($l) => (int)$l['funnel_id'], xsell_lines($pdo, (int)$sheet['deal_id'])), true);
            $pdo->prepare('UPDATE budget_lines SET funnel_id = ? WHERE id = ?')->execute([$ok ? $fn : null, $lid]);
        }
        $who = $kind === 'supplier' ? (string)$pdo->query('SELECT name FROM contacts WHERE id = ' . (int)$d['contact_id'])->fetchColumn()
             : ($kind === 'team' ? (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$d['user_id'])->fetchColumn() : 'interno');
        $ql = budget_qty_label($d);
        budget_log($pdo, (int)$sheet['id'], 'b_line', 'v' . $v['num'] . ' · ' . ($old ? 'alterada: ' : 'nova: ') . $d['label'] . ' · ' . $who . ($ql !== '—' ? ' · ' . $ql : ''));
        out(200, ['ok' => true, 'msg' => $old ? 'Linha alterada.' : 'Linha adicionada.', 'line' => $lid] + budget_parts($pdo, (int)$v['id'], true));

    case 'budget_line_qty':
        if (!$post) break;
        require_perm('orcamentacao', PERM_EDIT);
        require_once __DIR__ . '/inc/budget_view.php';
        $st = $pdo->prepare('SELECT * FROM budget_lines WHERE id = ?');
        $st->execute([(int)($_POST['line'] ?? 0)]);
        $l = $st->fetch();
        if (!$l) throw new RuntimeException('Esta linha já não existe. Recarregue a página.');
        [$sheet, $v] = budget_version_for_edit($pdo, (int)$l['version_id']);
        if (isset($_POST['qty'])) {
            $q = budget_num($_POST['qty']);
            if ($q <= 0) throw new RuntimeException('A quantidade tem de ser maior que zero. Para tirar a linha, use o ×.');
            $pdo->prepare('UPDATE budget_lines SET qty = ? WHERE id = ?')->execute([$q, $l['id']]);
        }
        if (isset($_POST['qty_unit']) && isset(BUDGET_QTY_UNITS[$_POST['qty_unit']])
            && ($l['kind'] === 'team' || ($l['kind'] === 'supplier' && budget_unit_hours((string)$l['price_unit'], 8) !== null))) {
            $pdo->prepare('UPDATE budget_lines SET qty_unit = ? WHERE id = ?')->execute([$_POST['qty_unit'], $l['id']]);
        }
        out(200, ['ok' => true] + budget_parts($pdo, (int)$v['id']));

    case 'budget_line_remove':
        if (!$post) break;
        require_perm('orcamentacao', PERM_EDIT);
        require_once __DIR__ . '/inc/budget_view.php';
        $st = $pdo->prepare('SELECT * FROM budget_lines WHERE id = ?');
        $st->execute([(int)($_POST['line'] ?? 0)]);
        $l = $st->fetch();
        if (!$l) throw new RuntimeException('Esta linha já não existe. Recarregue a página.');
        [$sheet, $v] = budget_version_for_edit($pdo, (int)$l['version_id']);
        $pdo->prepare('DELETE FROM budget_lines WHERE id = ?')->execute([$l['id']]);
        budget_log($pdo, (int)$sheet['id'], 'b_line', 'v' . $v['num'] . ' · tirada: ' . $l['label']);
        out(200, ['ok' => true, 'msg' => '«' . $l['label'] . '» tirada.', 'undo' => undo_push('budget_line', $l)] + budget_parts($pdo, (int)$v['id'], true));

    case 'budget_version_new':
        if (!$post) break;
        require_perm('orcamentacao', PERM_EDIT);
        require_once __DIR__ . '/inc/budget_view.php';
        $from = budget_version_get($pdo, (int)($_POST['version'] ?? 0));
        $sheet = $from ? budget_sheet_by_id($pdo, (int)$from['sheet_id']) : null;
        if (!$sheet) throw new RuntimeException('Folha não encontrada.');
        if ($sheet['closed_at']) throw new RuntimeException('Negócio concluído: a folha está fechada.');
        $vid = budget_version_new($pdo, $sheet, $from, (int)current_user()['id']);
        budget_recalc($pdo, $vid);
        $nv = budget_version_get($pdo, $vid);
        out(200, ['ok' => true, 'msg' => 'v' . $nv['num'] . ' criada a partir da v' . $from['num'] . '.',
                  'url' => '/admin/orcamento.php?id=' . (int)$sheet['deal_id'] . '&v=' . (int)$nv['num'],
                  'undo' => undo_push('budget_version', ['version' => $vid, 'sheet' => (int)$sheet['id'], 'updated' => (string)$nv['updated_at']])]);

    case 'budget_accept':
        if (!$post) break;
        require_perm('orcamentacao', PERM_EDIT);
        require_once __DIR__ . '/inc/budget_view.php';
        $v = budget_version_get($pdo, (int)($_POST['version'] ?? 0));
        $sheet = $v ? budget_sheet_by_id($pdo, (int)$v['sheet_id']) : null;
        if (!$sheet) throw new RuntimeException('Versão não encontrada.');
        if ($sheet['closed_at']) throw new RuntimeException('Negócio concluído: a folha está fechada.');
        if ((int)$sheet['accepted_version_id'] === (int)$v['id']) throw new RuntimeException('Esta versão já é a aceite.');
        $deal = record_get($pdo, (int)$sheet['deal_id']);
        $snap = ['sheet' => (int)$sheet['id'], 'version' => (int)$v['id'], 'prev' => $sheet['accepted_version_id'] !== null ? (int)$sheet['accepted_version_id'] : null,
                 'occupancy' => $v['occupancy'], 'value' => $deal['value_estimate'], 'deal' => (int)$deal['id']];
        // Aceite a partir da Opção B (§19.3): conta a B (preço, Resultado).
        $optB = ($_POST['opt'] ?? 'A') === 'B' && budget_has_options($pdo) && budget_lines($pdo, (int)$v['id'], 'B');
        $snap['opt'] = (string)($v['accepted_opt'] ?? '');
        if (budget_has_options($pdo)) $pdo->prepare('UPDATE budget_versions SET accepted_opt = ? WHERE id = ?')->execute([$optB ? 'B' : 'A', $v['id']]);
        budget_accept($pdo, $sheet, budget_version_get($pdo, (int)$v['id']), (int)current_user()['id']);
        $v = budget_version_get($pdo, (int)$v['id']);
        out(200, ['ok' => true, 'msg' => 'v' . $v['num'] . ' aceite' . ($optB ? ' (Opção B)' : '') . (budget_money() ? ': ' . budget_eur(budget_version_price($v)) . ' passa a ser o valor do negócio.' : '.'),
                  'undo' => undo_push('budget_accept', $snap)]);

    case 'budget_tpl_save':
        if (!$post) break;
        require_perm('orcamentacao', PERM_EDIT);
        require_once __DIR__ . '/inc/budget_view.php';
        $v = budget_version_get($pdo, (int)($_POST['version'] ?? 0));
        if (!$v) throw new RuntimeException('Versão não encontrada.');
        $lines = budget_lines($pdo, (int)$v['id']);
        if (!$lines) throw new RuntimeException('Esta versão ainda não tem linhas.');
        budget_template_save($pdo, $v, $lines, (string)($_POST['name'] ?? ''), (int)current_user()['id']);
        out(200, ['ok' => true, 'msg' => 'Modelo «' . trim((string)$_POST['name']) . '» guardado.']);

    case 'budget_tpl_apply':
        if (!$post) break;
        require_perm('orcamentacao', PERM_EDIT);
        require_once __DIR__ . '/inc/budget_view.php';
        $sheet = budget_sheet_by_id($pdo, (int)($_POST['sheet'] ?? 0));
        if (!$sheet) throw new RuntimeException('Folha não encontrada.');
        if ($sheet['closed_at']) throw new RuntimeException('Negócio concluído: a folha está fechada.');
        $st = $pdo->prepare('SELECT * FROM budget_templates WHERE id = ?');
        $st->execute([(int)($_POST['template'] ?? 0)]);
        $tpl = $st->fetch();
        if (!$tpl) throw new RuntimeException('Escolha o modelo.');
        $versions = budget_versions($pdo, (int)$sheet['id']);
        $last = end($versions);
        $before = $last;
        $res = budget_template_apply($pdo, $sheet, $tpl, (int)current_user()['id']);
        $nv = budget_version_get($pdo, $res['version_id']);
        $undo = (int)$res['version_id'] === (int)$last['id']
            ? undo_push('budget_fill', ['version' => (int)$last['id'], 'params' => array_intersect_key($before, array_flip(['dur_mode', 'weeks', 'days', 'phases', 'occupancy', 'description']))])
            : undo_push('budget_version', ['version' => (int)$nv['id'], 'sheet' => (int)$sheet['id'], 'updated' => (string)$nv['updated_at']]);
        out(200, ['ok' => true, 'msg' => 'Modelo «' . $tpl['name'] . '» aplicado na v' . $nv['num'] . '.' . ($res['skipped'] ? ' De fora: ' . implode('; ', $res['skipped']) : ''),
                  'url' => '/admin/orcamento.php?id=' . (int)$sheet['deal_id'] . '&v=' . (int)$nv['num'], 'undo' => $undo]);

    case 'budget_pdf':
        if (!$post) break;
        require_perm('orcamentacao', PERM_VIEW);
        require_once __DIR__ . '/inc/budget_pdf.php';
        $v = budget_version_get($pdo, (int)($_POST['version'] ?? 0));
        $sheet = $v ? budget_sheet_by_id($pdo, (int)$v['sheet_id']) : null;
        if (!$sheet) throw new RuntimeException('Versão não encontrada.');
        $r = budget_pdf_publish($pdo, $sheet, $v);
        out(200, ['ok' => true, 'msg' => $r['msg'], 'url' => '/admin/orcamento_pdf.php?v=' . (int)$v['id']]);

    // ── Pipeline: mover sem recarregar ──────────────────────
    // ── Definições (§20): grava sozinho, com aviso de impacto, «Repor valor de origem» e «Anular» ──
    case 'setting_save':
    case 'setting_reset':
    case 'setting_restore':
        if (!$post) break;
        require_once __DIR__ . '/inc/settings_def.php';
        if (!is_admin()) out(403, ['ok' => false, 'error' => 'Só superadmin e admins mudam as Definições.']);
        $key = (string)($_POST['key'] ?? '');
        $why = '';
        if ($a === 'setting_restore') {
            // «Repor» numa linha do registo: o valor de antes dessa alteração.
            $st = $pdo->prepare('SELECT * FROM settings_log WHERE id = ?');
            $st->execute([(int)($_POST['log'] ?? 0)]);
            $row = $st->fetch();
            if (!$row || !setting_def((string)$row['skey'])) throw new RuntimeException('Essa alteração já não pode ser reposta aqui.');
            $key = (string)$row['skey'];
            $value = (string)$row['old_value'];
            $why = setting_def($key)['label'] . ' reposto';
        } else {
            $def = setting_def($key);
            if (!$def) throw new RuntimeException('Definição desconhecida.');
            $value = $a === 'setting_reset' ? (string)($def['default'] ?? '') : (string)($_POST['value'] ?? '');
            if ($a === 'setting_reset') $why = $def['label'] . ' reposto no valor de origem';
        }
        try {
            [$msg, $shown, $undo] = setting_save($pdo, $key, $value, !empty($_POST['confirm']), $why);
        } catch (SettingImpact $e) {
            out(409, ['ok' => false, 'impact' => $e->getMessage(), 'error' => $e->getMessage()]);
        }
        out(200, ['ok' => true, 'msg' => $msg . ($why !== '' ? ': ' . $shown : ''), 'key' => $key, 'value' => setting_get($pdo, $key), 'shown' => $shown, 'undo' => $undo]);

    // Linhas das listas com tabela (serviços dos Contactos, pilares do Marketing): grava cada campo.
    case 'setting_row':
        if (!$post) break;
        require_once __DIR__ . '/inc/settings_def.php';
        if (!is_admin()) out(403, ['ok' => false, 'error' => 'Só superadmin e admins mudam as Definições.']);
        $tbl = (string)($_POST['table'] ?? '');
        if ($tbl === 'fin_cat_types') {
            // Financeiro › Categorias: o tipo contabilístico de cada uma (Dashboard Executivo, §30.2)
            require_once __DIR__ . '/inc/exec.php';
            $cat = trim((string)($_POST['id'] ?? ''));
            $val = (string)($_POST['value'] ?? '');
            $types = exec_cat_types($pdo);
            if (!isset($types[$cat]) || !isset(EXEC_TYPES[$val]) || in_array($cat, ['Salários', EXEC_LOAN_CAT], true)) throw new RuntimeException('Escolha um tipo.');
            if ($types[$cat] === $val) out(200, ['ok' => true, 'msg' => 'Sem alterações.']);
            $old = (string)setting($pdo, 'fin_cat_types', '');
            $saved = json_decode($old, true) ?: [];
            $saved[$cat] = $val;
            $new = json_encode($saved, JSON_UNESCAPED_UNICODE);
            setting_write($pdo, 'fin_cat_types', $new);
            setting_log($pdo, 'fin_cat_types', 'Financeiro · Tipo de «' . $cat . '»', EXEC_TYPES[$types[$cat]], EXEC_TYPES[$val]);
            exec_snapshot_refresh($pdo);
            out(200, ['ok' => true, 'msg' => '«' . $cat . '» passa a ' . EXEC_TYPES[$val] . '. O resultado do Dashboard já foi recalculado.', 'undo' => undo_push('setting', ['key' => 'fin_cat_types', 'from' => $old, 'to' => $new])]);
        }
        if ($tbl === 'funnels') {
            // Toppernote (§23): pré-criativo por funil e os sub-quadros do projeto
            require_once __DIR__ . '/inc/cc.php';
            $f = funnel_get($pdo, (int)($_POST['id'] ?? 0));
            $field = (string)($_POST['field'] ?? '');
            $raw = (string)($_POST['value'] ?? '');
            if (!$f || !db_has_column($pdo, 'funnels', 'cc_pre_mode')) throw new RuntimeException('Funil inválido.');
            if ($field === 'cc_pre_mode') {
                if (!in_array($raw, ['recommended', 'required', 'off'], true)) throw new RuntimeException('Escolha uma opção.');
                $col = 'cc_pre_mode'; $from = (string)$f['cc_pre_mode']; $val = $raw;
                $what = 'Toppernote · ' . $f['name'] . ' · pré-criativo';
            } elseif (preg_match('/^cc_stage_([a-z]+)$/', $field, $m) && isset(CC_STAGES[$m[1]])) {
                $col = 'cc_stages'; $from = (string)$f['cc_stages'];
                $on = cc_funnel_stages($pdo, (int)$f['id']);
                $on = $raw === '1' ? array_values(array_unique([...$on, $m[1]])) : array_values(array_diff($on, [$m[1]]));
                if (!$on) throw new RuntimeException('O projeto precisa de pelo menos um sub-quadro.');
                $val = implode(',', array_values(array_filter(array_keys(CC_STAGES), fn($k) => in_array($k, $on, true))));
                $what = 'Toppernote · ' . $f['name'] . ' · sub-quadros';
            } else {
                throw new RuntimeException('Campo desconhecido.');
            }
            if ($from === $val) out(200, ['ok' => true, 'msg' => 'Sem alterações.']);
            $pdo->prepare("UPDATE funnels SET $col = ? WHERE id = ?")->execute([$val, (int)$f['id']]);
            setting_log($pdo, 'funnels:' . (int)$f['id'] . ':' . $col, $what, $from, $val);
            out(200, ['ok' => true, 'msg' => ucfirst(explode(' · ', $what, 3)[2]) . ' de «' . $f['name'] . '» alterado.', 'undo' => undo_push('setting_row', ['table' => 'funnels', 'id' => (int)$f['id'], 'field' => $col, 'from' => $from, 'to' => $val])]);
        }
        $fields = ['services' => ['name' => 80, 'position' => 0, 'is_active' => 0, 'default_unit' => 0],
                   'mkt_pillars' => ['name' => 80, 'short_name' => 24, 'color' => 0, 'description' => 500, 'position' => 0, 'is_active' => 0, 'kind' => 0]][$tbl] ?? null;
        $field = (string)($_POST['field'] ?? '');
        if (!$fields || !array_key_exists($field, $fields) || !db_has_column($pdo, $tbl, $field)) throw new RuntimeException('Campo desconhecido.');
        $id = (int)($_POST['id'] ?? 0);
        $raw = (string)($_POST['value'] ?? '');
        if ($id === 0 && $field === 'name') {
            // Linha nova.
            $name = trim(mb_substr($raw, 0, 80));
            if ($name === '') throw new RuntimeException('Escreva o nome.');
            $st = $pdo->prepare("SELECT COUNT(*) FROM $tbl WHERE LOWER(name) = ?");
            $st->execute([mb_strtolower($name)]);
            if ((int)$st->fetchColumn()) throw new RuntimeException('«' . $name . '» já está na lista.');
            $pos = (int)$pdo->query("SELECT COALESCE(MAX(position), 0) + 1 FROM $tbl")->fetchColumn();
            if ($tbl === 'services') $pdo->prepare('INSERT INTO services (name, position, is_active) VALUES (?, ?, 1)')->execute([$name, $pos]);
            else $pdo->prepare("INSERT INTO mkt_pillars (name, short_name, color, position) VALUES (?, ?, 'neutral', ?)")->execute([$name, mb_substr($name, 0, 24), $pos]);
            setting_log($pdo, $tbl, ($tbl === 'services' ? 'Contactos · Serviço' : 'Marketing · Pilar') . ' acrescentado', '', $name);
            out(200, ['ok' => true, 'msg' => '«' . $name . '» acrescentado.', 'reload' => true]);
        }
        $st = $pdo->prepare("SELECT * FROM $tbl WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException('Essa linha já não existe.');
        $max = $fields[$field];
        if ($max > 0) {
            $val = trim(mb_substr($raw, 0, $max));
            if ($field === 'name' && $val === '') throw new RuntimeException('O nome não pode ficar vazio.');
        } elseif ($field === 'color') {
            require_once __DIR__ . '/inc/marketing.php';
            if (!isset(MKT_PILLAR_COLORS[$raw])) throw new RuntimeException('Escolha uma cor.');
            $val = $raw;
        } elseif ($field === 'kind') {
            $val = $raw === 'employer' ? 'employer' : '';
        } elseif ($field === 'default_unit') {
            if ($raw !== '' && !isset(SERVICE_UNITS[$raw])) throw new RuntimeException('Escolha uma unidade.');
            $val = $raw;
        } else {
            $val = (string)max(0, min(999, (int)$raw));
            if ($field === 'is_active') $val = $raw === '1' ? '1' : '0';
        }
        if ((string)$row[$field] === $val) out(200, ['ok' => true, 'msg' => 'Sem alterações.']);
        $pdo->prepare("UPDATE $tbl SET $field = ? WHERE id = ?")->execute([$val, $id]);
        $what = ($tbl === 'services' ? 'Contactos · Serviço «' : 'Marketing · Pilar «') . $row['name'] . '» · ' . ['name' => 'nome', 'short_name' => 'nome curto', 'color' => 'cor', 'description' => 'descrição', 'position' => 'ordem', 'is_active' => 'ativo', 'kind' => 'employer branding', 'default_unit' => 'unidade'][$field];
        setting_log($pdo, $tbl . ':' . $id . ':' . $field, $what, (string)$row[$field], $val);
        out(200, ['ok' => true, 'msg' => ucfirst(explode(' · ', $what, 2)[1]) . ' alterado.', 'undo' => undo_push('setting_row', ['table' => $tbl, 'id' => $id, 'field' => $field, 'from' => (string)$row[$field], 'to' => $val])]);

    case 'deal_move':
        if (!$post) break;
        require_perm('pipeline', PERM_EDIT);
        $deal = record_get($pdo, (int)($_POST['id'] ?? 0));
        if (!$deal || !in_array($deal['phase'], ['pipeline', 'production'], true) || !record_reach($pdo, $deal, PERM_EDIT)) {
            out(404, ['ok' => false, 'error' => 'Negócio não encontrado. Recarregue a página.']);
        }
        // O quadro mostra também a produção (§19.8): em produção, o negócio só anda entre o Kickoff
        // e as etapas de produção (record_reach pede então a aba Negócios); concluir faz-se na checklist de fecho.
        $to = stage_get($pdo, (int)($_POST['stage_id'] ?? 0));
        if (!$to) throw new RuntimeException('Etapa inválida.');
        // Uma frente de crosselling (§22): o cartão é a frente, que anda no funil do seu serviço.
        if (($lid = (int)($_POST['line'] ?? 0)) > 0) {
            require_once __DIR__ . '/inc/xsell.php';
            $line = xsell_line_get($pdo, $lid);
            if (!$line || (int)$line['deal_id'] !== (int)$deal['id']) out(404, ['ok' => false, 'error' => 'Frente não encontrada. Recarregue a página.']);
            $snap = ['id' => (int)$deal['id'], 'lines' => xsell_snapshot($pdo, (int)$deal['id']), 'line' => $lid, 'to_stage' => (int)$to['id']];
            flow_created(null, true);
            try {
                $msg = xsell_line_move($pdo, $deal, $line, (int)$to['id'], (string)($_POST['force'] ?? ''));
            } catch (GateBlocked $e) {
                out(409, ['ok' => false, 'error' => $e->getMessage(), 'gate' => $e->missing, 'can_force' => is_admin()]);
            }
            out(200, ['ok' => true, 'msg' => $msg, 'phase' => 'production', 'undo' => undo_push('line_stage', $snap + ['auto_tasks' => flow_created()]), 'won' => false]);
        }
        if ($to['kind'] === 'done') throw new RuntimeException('Para concluir, use a checklist de fecho na ficha do negócio (aba Negócios).');
        if ($deal['phase'] === 'production' && in_array($to['kind'], ['xray', 'commercial'], true)) throw new RuntimeException('Um negócio em produção não volta à fase comercial.');
        $snap = undo_deal_snapshot($deal);
        require_once __DIR__ . '/inc/pipeline_flow.php';
        flow_created(null, true);
        try {
            $msg = deal_move($pdo, $deal, (int)$to['id'], (string)($_POST['force'] ?? ''));
        } catch (GateBlocked $e) {
            // Os requisitos (§19.1): a lista volta para o quadro; um admin pode forçar com motivo.
            out(409, ['ok' => false, 'error' => $e->getMessage(), 'gate' => $e->missing, 'can_force' => is_admin()]);
        }
        $now = record_get($pdo, (int)$deal['id']);
        $token = (int)$deal['stage_id'] !== (int)$to['id'] ? undo_push('deal_stage', $snap + ['to_stage' => (int)$to['id'], 'auto_tasks' => flow_created()]) : '';
        out(200, ['ok' => true, 'msg' => $msg, 'phase' => $now['phase'], 'undo' => $token, 'won' => $deal['phase'] === 'pipeline' && $now['phase'] === 'production']);
    }
} catch (RuntimeException $e) {
    if (!$json && $a === 'interaction') {
        require_once __DIR__ . '/inc/layout.php';
        flash($e->getMessage(), 'err');
        $back = (string)($_POST['back'] ?? '');
        redirect(str_starts_with($back, '/admin/') ? $back : '/admin/');
    }
    out(400, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('ui_api ' . $a . ': ' . $e->getMessage());
    out(500, ['ok' => false, 'error' => 'Não foi possível concluir. Tente outra vez.']);
}

out(400, ['ok' => false, 'error' => 'Pedido inválido.']);
