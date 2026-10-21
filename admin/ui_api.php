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
 * Os POST pedem o token CSRF (campo «csrf» ou cabeçalho X-CSRF-Token).
 * Todas as permissões se verificam aqui, em cada pedido (§4).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ui.php';
require_once __DIR__ . '/inc/reach.php';
require_once __DIR__ . '/inc/alerts.php';
require_once __DIR__ . '/inc/undo.php';
require_once __DIR__ . '/inc/panel.php';

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
        $nav[] = ['A minha conta', '/admin/conta.php', 'user'];
        $nav[] = ['Definições (como quer ser tratado, modo)', '/admin/conta.php#definicoes', 'user'];
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
            foreach (['pipeline' => "d.phase IN ('pipeline','done','lost')", 'negocios' => "d.phase = 'production'"] as $tab => $cond) {
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

            if (can('leads')) {
                [$rs, $ra] = reach_sql($pdo, 'leads');
                $st = $pdo->prepare("SELECT d.id, d.name, d.phase, d.email, d.lead_state FROM deals d
                                      WHERE d.phase IN ('lead','archived') AND $rs
                                        AND (d.name LIKE ? ESCAPE '!' OR d.email LIKE ? ESCAPE '!' OR d.phone LIKE ? ESCAPE '!')
                                      ORDER BY d.phase = 'archived', d.id DESC LIMIT 6");
                $st->execute([...$ra, $l, $l, $l]);
                foreach ($st->fetchAll() as $d) {
                    $add('Leads', ['title' => $d['name'], 'sub' => $d['phase'] === 'archived' ? 'Arquivada' : (LEAD_STATE[$d['lead_state']] ?? ''),
                                   'url' => '/admin/leads.php?open=' . $d['id'], 'panel' => 'lead:' . $d['id'], 'icon' => icon('leads')]);
                }
            }

            if (can('contactos')) {
                $st = $pdo->prepare("SELECT id, name, email, contact_person FROM contacts
                                      WHERE name LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!'
                                         OR nif LIKE ? ESCAPE '!' OR contact_person LIKE ? ESCAPE '!'
                                      ORDER BY name LIMIT 6");
                $st->execute([$l, $l, $l, $l, $l]);
                foreach ($st->fetchAll() as $c) {
                    $add('Contactos', ['title' => $c['name'], 'sub' => $c['contact_person'] ?: $c['email'],
                                       'url' => '/admin/contactos.php?edit=' . $c['id'] . '#contacto', 'panel' => 'contact:' . $c['id'], 'icon' => icon('contactos')]);
                }
            }

            if (can('tarefas')) {
                require_once __DIR__ . '/inc/tasks.php';
                $visible = task_visible_members($pdo, team_members($pdo));
                $vis = $visible === null ? '' : ' AND (t.member_id IS NULL' . ($visible ? ' OR t.member_id IN (' . implode(',', array_map('intval', $visible)) . ')' : '') . ')';
                $st = $pdo->prepare("SELECT t.id, t.title, t.status, t.due_date, tm.name AS who FROM tasks t LEFT JOIN team_members tm ON tm.id = t.member_id
                                      WHERE t.title LIKE ? ESCAPE '!'$vis ORDER BY t.status = 'done', t.due_date IS NULL, t.due_date LIMIT 6");
                $st->execute([$l]);
                foreach ($st->fetchAll() as $t) {
                    $add('Tarefas', ['title' => $t['title'], 'sub' => trim(($t['who'] ?? 'Por atribuir') . ' · ' . (TASK_STATUS[$t['status']] ?? '') . ($t['due_date'] ? ' · ' . date('d/m', strtotime($t['due_date'])) : '')),
                                     'url' => '/admin/calendar.php?view=tarefas&edit=' . $t['id'], 'icon' => icon('tarefas')]);
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
        $r = inline_save($pdo, $entity, $id, $field, $_POST['value'] ?? '');
        $token = (string)$r['prev'] !== (string)$r['value']
            ? undo_push('inline', ['entity' => $entity, 'id' => $id, 'field' => $field, 'prev' => $r['prev']]) : '';
        out(200, ['ok' => true, 'display' => $r['display'], 'value' => $r['value'], 'undo' => $token]);

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
        if ($type === 'contact') {
            require_perm('contactos', PERM_VIEW);
            $st = $pdo->prepare('SELECT * FROM contacts WHERE id = ?');
            $st->execute([$id]);
            $c = $st->fetch();
            if (!$c) out(404, ['ok' => false, 'error' => 'Contacto não encontrado.']);
            out(200, ['ok' => true] + panel_contact($pdo, $c));
        }
        break;

    // ── Pipeline: mover sem recarregar ──────────────────────
    case 'deal_move':
        if (!$post) break;
        require_perm('pipeline', PERM_EDIT);
        $deal = record_get($pdo, (int)($_POST['id'] ?? 0));
        if (!$deal || $deal['phase'] !== 'pipeline' || !record_reach($pdo, $deal, PERM_EDIT)) {
            out(404, ['ok' => false, 'error' => 'Negócio não encontrado. Recarregue a página.']);
        }
        $to = stage_get($pdo, (int)($_POST['stage_id'] ?? 0));
        if (!$to || !in_array($to['kind'], ['xray', 'commercial', 'kickoff'], true)) throw new RuntimeException('Etapa inválida.');
        $snap = undo_deal_snapshot($deal);
        $msg = deal_move($pdo, $deal, (int)$to['id']);
        $now = record_get($pdo, (int)$deal['id']);
        $token = (int)$deal['stage_id'] !== (int)$to['id'] ? undo_push('deal_stage', $snap + ['to_stage' => (int)$to['id']]) : '';
        out(200, ['ok' => true, 'msg' => $msg, 'phase' => $now['phase'], 'undo' => $token, 'won' => $now['phase'] === 'production']);
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
