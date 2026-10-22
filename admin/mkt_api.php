<?php
/**
 * topper. admin — pedidos da aba Marketing (§16)
 *
 *   POST a=idea_new      title, idea, pillar_id, format, networks[], template, owner_id, due_date — «+ Ideia» (com «Anular»)
 *   POST a=piece_save    id, f[campo] — dados da ideia/peça (com «Anular»)
 *   POST a=triage        id, to (producao | guardada | descartada | nova) — com «Anular»
 *   POST a=vote          id — votar / tirar o voto
 *   POST a=move          id, stage — etapa no kanban (com «Anular»)
 *   POST a=review        id, decision (approve | changes), note — só superadmin e admins
 *   POST a=schedule      id, when[rede] = «aaaa-mm-dd hh:mm» ('' tira) — com «Anular»
 *   POST a=publish       id, network, url — publicação assistida (fase 1), com «Anular»
 *   POST a=metrics       id, network, reach, interactions, saves, shares, clicks
 *   POST a=version       id, network, field (hook | body | hashtags | cta), value — o Estúdio grava sozinho
 *   POST a=check         id, i, done | add | remove — checklist da peça
 *   POST a=ai            id, act, network, tone — a IA propõe (nunca grava sozinha)
 *   POST a=ai_use        id, network, field, value, parts[] — «Usar» uma proposta (com «Anular»)
 *   POST a=repeat        id — ideia de parte 2 a partir de uma peça com bom desempenho
 *   POST a=shoot_save    id?, day, title, place, notes, pieces[] — dia de gravação
 *   POST a=shoot_drop    id
 *   POST a=summary       month — resumo mensal escrito pela IA
 *   POST a=drive         id — cria a pasta Marketing/AAAA-MM/<peça>/ e devolve o endereço
 *
 * Com Accept: application/json responde em JSON; sem JavaScript, os formulários
 * voltam à página de onde vieram (back) com a mensagem. Pedem o token CSRF e a
 * permissão de editar a Marketing; aprovar pede superadmin ou admin (lido na base).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/marketing.php';

$json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

function mout(int $code, array $body): void {
    global $json;
    if (!$json) {
        $back = (string)($_POST['back'] ?? '');
        if (!preg_match('~^/admin/[a-z_]+\.php(\?[^#\s]*)?(#[\w-]+)?$~', $back)) $back = '/admin/marketing.php';
        flash($body['ok'] ? ($body['msg'] ?? 'Feito.') : ($body['error'] ?? 'Não foi possível concluir.'), $body['ok'] ? 'ok' : 'err', $body['undo'] ?? '');
        redirect($back);
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (current_user() === null) mout(401, ['ok' => false, 'error' => 'A sessão terminou. Entre outra vez.']);
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') mout(405, ['ok' => false, 'error' => 'Pedido inválido.']);
$sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) mout(400, ['ok' => false, 'error' => 'Pedido inválido. Recarregue a página e tente outra vez.']);
if (!can('marketing', PERM_EDIT)) mout(403, ['ok' => false, 'error' => can('marketing') ? 'A sua conta vê a Marketing mas não a edita.' : 'A sua conta não tem acesso à Marketing.']);

$a  = (string)($_POST['a'] ?? '');
$by = (int)$user['id'];

/** A peça do pedido. */
function piece_req(PDO $pdo): array {
    $p = mkt_piece($pdo, (int)($_POST['id'] ?? 0));
    if (!$p) throw new RuntimeException('Peça não encontrada. Recarregue a página.');
    return $p;
}

try {
    switch ($a) {

    case 'idea_new':
        $v = mkt_input($pdo, $_POST, mkt_people($pdo));
        $id = mkt_idea_create($pdo, $v, $by);
        mout(200, ['ok' => true, 'id' => $id, 'msg' => 'Ideia guardada em Marketing › Ideias.',
                   'undo' => undo_push_mkt_delete($pdo, $id), 'url' => '/admin/marketing_peca.php?id=' . $id]);

    case 'piece_save':
        $p = piece_req($pdo);
        $snap = mkt_snapshot($pdo, (int)$p['id']);
        $f = (array)($_POST['f'] ?? []);
        if (isset($f['networks_sent']) && !isset($f['networks'])) $f['networks'] = [];
        // Só os campos enviados mudam (o Estúdio grava um de cada vez); os outros ficam como estão.
        $cur = ['title' => $p['title'], 'idea' => $p['idea'], 'pillar_id' => $p['pillar_id'], 'format' => $p['format'], 'template' => $p['template'],
                'owner_id' => $p['owner_id'], 'due_date' => $p['due_date'], 'networks' => mkt_nets($p)];
        $people = mkt_people($pdo);
        if ($p['owner_id'] && !isset($people[(int)$p['owner_id']]) && !array_key_exists('owner_id', $f)) $people[(int)$p['owner_id']] = ['id' => (int)$p['owner_id']];
        $v = mkt_input($pdo, $f + $cur, $people);
        $set = ['title' => $v['title'], 'idea' => $v['idea'], 'pillar_id' => $v['pillar_id'], 'format' => $v['format'], 'template' => $v['template'],
                'owner_id' => $v['owner_id'], 'due_date' => $v['due_date']];
        if (array_key_exists('networks', $f) || isset($f['networks_sent'])) {
            if ($p['status'] === 'producao' && $v['networks'] === '') throw new RuntimeException('Uma peça em Produção precisa de pelo menos uma rede.');
            if (mkt_locked($p) && $v['networks'] !== $p['networks']) throw new RuntimeException('Aprovada: as redes ficam como foram aprovadas.');
            $set['networks'] = $v['networks'];
        }
        if (array_key_exists('link_target', $f)) {
            $t = trim((string)$f['link_target']);
            if ($t !== '' && !preg_match('~^/[^\s]{0,250}$~', $t)) throw new RuntimeException('O destino do link é uma página do site, a começar por «/» (ex.: /#work).');
            $set['link_target'] = $t !== '' ? $t : null;
        }
        if (array_key_exists('shoot_id', $f)) {
            $sid = (int)$f['shoot_id'];
            $set['shoot_id'] = $sid && $pdo->query('SELECT COUNT(*) FROM mkt_shoots WHERE id = ' . $sid)->fetchColumn() ? $sid : null;
        }
        $pdo->prepare('UPDATE mkt_pieces SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?')
            ->execute([...array_values($set), (int)$p['id']]);
        mkt_touch($pdo, (int)$p['id']);
        $now = mkt_piece($pdo, (int)$p['id']);
        if ($now['status'] === 'producao') mkt_sync_posts($pdo, $now);
        mkt_log($pdo, (int)$p['id'], 'm_edit', 'Dados da peça', $by);
        mout(200, ['ok' => true, 'msg' => 'Guardado.', 'undo' => mkt_undo_push($pdo, $snap, 'Dados repostos.')]);

    case 'triage':
        $p = piece_req($pdo);
        $snap = mkt_snapshot($pdo, (int)$p['id']);
        $msg = mkt_triage($pdo, $p, (string)($_POST['to'] ?? ''), $by);
        mout(200, ['ok' => true, 'msg' => $msg, 'undo' => mkt_undo_push($pdo, $snap, 'Triagem anulada.')]);

    case 'vote':
        $p = piece_req($pdo);
        [$on, $n] = mkt_vote($pdo, (int)$p['id'], $by);
        mout(200, ['ok' => true, 'on' => $on, 'n' => $n, 'msg' => $on ? 'Voto registado.' : 'Voto retirado.']);

    case 'move':
        $p = piece_req($pdo);
        $snap = mkt_snapshot($pdo, (int)$p['id']);
        [$msg] = mkt_move($pdo, $p, (string)($_POST['stage'] ?? ''), $by);
        mout(200, ['ok' => true, 'msg' => $msg, 'undo' => mkt_undo_push($pdo, $snap, 'Etapa reposta.'), 'card' => mkt_card_html(mkt_piece($pdo, (int)$p['id']), true)]);

    case 'review':
        $p = piece_req($pdo);
        $snap = mkt_snapshot($pdo, (int)$p['id']);
        [$msg] = mkt_review($pdo, $p, (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? ''), $by);
        mout(200, ['ok' => true, 'msg' => $msg, 'undo' => mkt_undo_push($pdo, $snap, 'Revisão anulada.')]);

    case 'schedule':
        $p = piece_req($pdo);
        $snap = mkt_snapshot($pdo, (int)$p['id']);
        $when = [];
        foreach ((array)($_POST['when'] ?? []) as $n => $w) if (isset(MKT_NETWORKS[$n])) $when[$n] = (string)$w;
        // Do formulário: data e hora em campos separados.
        foreach ((array)($_POST['day'] ?? []) as $n => $d) {
            if (!isset(MKT_NETWORKS[$n])) continue;
            $d = trim((string)$d);
            $when[$n] = $d === '' ? '' : $d . ' ' . (preg_match('/^\d{2}:\d{2}$/', (string)($_POST['time'][$n] ?? '')) ? $_POST['time'][$n] : '10:00');
        }
        if (!$when) throw new RuntimeException('Escolha a data de pelo menos uma rede.');
        [$msg] = mkt_schedule($pdo, $p, $when, $by);
        mout(200, ['ok' => true, 'msg' => $msg, 'undo' => mkt_undo_push($pdo, $snap, 'Agendamento anulado.')]);

    case 'publish':
        $p = piece_req($pdo);
        $snap = mkt_snapshot($pdo, (int)$p['id']);
        $msg = '';
        $urls = (array)($_POST['url'] ?? []);
        if (!$urls && isset($_POST['network'])) $urls = [(string)$_POST['network'] => (string)($_POST['link'] ?? '')];
        $done = 0;
        foreach ($urls as $n => $u) {
            if (!isset(MKT_NETWORKS[$n]) || trim((string)$u) === '') continue;
            [$msg] = mkt_publish($pdo, mkt_piece($pdo, (int)$p['id']), $n, (string)$u, $by);
            $done++;
        }
        if (!$done) throw new RuntimeException('Cole o link da publicação.');
        mout(200, ['ok' => true, 'msg' => $msg, 'undo' => mkt_undo_push($pdo, $snap, 'Publicação anulada.')]);

    case 'metrics':
        $p = piece_req($pdo);
        $snap = mkt_snapshot($pdo, (int)$p['id']);
        $msg = mkt_metrics_save($pdo, $p, (string)($_POST['network'] ?? ''), $_POST, $by);
        mout(200, ['ok' => true, 'msg' => $msg, 'undo' => mkt_undo_push($pdo, $snap, 'Métricas repostas.')]);

    case 'version':
        $p = piece_req($pdo);
        $field = (string)($_POST['field'] ?? '');
        if (!in_array($field, ['hook', 'body', 'hashtags', 'cta'], true)) throw new RuntimeException('Campo desconhecido.');
        $net = (string)($_POST['network'] ?? '');
        mkt_version_save($pdo, $p, $net, [$field => (string)($_POST['value'] ?? '')], $by);
        // Uma entrada no histórico por rede e por pessoa a cada 10 minutos (o Estúdio grava a cada pausa).
        $st = $pdo->prepare("SELECT COUNT(*) FROM mkt_log WHERE piece_id = ? AND event = 'm_version' AND user_id = ? AND note = ? AND created_at > ?");
        $st->execute([(int)$p['id'], $by, MKT_NETWORKS[$net][0], date('Y-m-d H:i:s', time() - 600)]);
        if (!(int)$st->fetchColumn()) mkt_log($pdo, (int)$p['id'], 'm_version', MKT_NETWORKS[$net][0], $by);
        mout(200, ['ok' => true, 'msg' => 'Guardado.']);

    case 'check':
        $p = piece_req($pdo);
        $c = mkt_checklist($p);
        if (isset($_POST['add'])) {
            $t = mb_substr(trim((string)$_POST['add']), 0, 160);
            if ($t === '') throw new RuntimeException('Escreva o passo.');
            $c[] = ['t' => $t, 'done' => false];
        } else {
            $i = (int)($_POST['i'] ?? -1);
            if (!isset($c[$i])) throw new RuntimeException('Passo não encontrado. Recarregue a página.');
            if (isset($_POST['remove'])) array_splice($c, $i, 1);
            else $c[$i]['done'] = !empty($_POST['done']);
        }
        $pdo->prepare('UPDATE mkt_pieces SET checklist = ? WHERE id = ?')->execute([json_encode(array_values($c), JSON_UNESCAPED_UNICODE), (int)$p['id']]);
        mkt_touch($pdo, (int)$p['id']);
        $done = count(array_filter($c, fn($x) => !empty($x['done'])));
        mout(200, ['ok' => true, 'msg' => 'Checklist guardada.', 'done' => $done, 'total' => count($c)]);

    case 'ai':
        $p = piece_req($pdo);
        $props = mkt_ai_run($pdo, $p, (string)($_POST['act'] ?? ''), (string)($_POST['network'] ?? ''), (string)($_POST['tone'] ?? ''), $by);
        mout(200, ['ok' => true, 'proposals' => $props, 'msg' => 'A IA propôs ' . count($props) . (count($props) === 1 ? ' versão' : ' versões') . '. Escolha o que usar.']);

    case 'ai_use':
        $p = piece_req($pdo);
        $snap = mkt_snapshot($pdo, (int)$p['id']);
        $parts = array_map('strval', array_intersect_key((array)($_POST['parts'] ?? []), ['hook' => 1, 'body' => 1, 'hashtags' => 1]));
        $msg = mkt_ai_use($pdo, $p, (string)($_POST['network'] ?? ''), (string)($_POST['field'] ?? ''), (string)($_POST['value'] ?? ''), $parts, $by);
        mout(200, ['ok' => true, 'msg' => $msg, 'undo' => mkt_undo_push($pdo, $snap, 'Texto reposto.', ['versions' => true]),
                   'versions' => mkt_versions($pdo, (int)$p['id']), 'script' => (string)mkt_piece($pdo, (int)$p['id'])['script']]);

    case 'repeat':
        $p = piece_req($pdo);
        $id = mkt_idea_repeat($pdo, $p, $by);
        mout(200, ['ok' => true, 'id' => $id, 'msg' => 'Ideia «parte 2» criada em Ideias.', 'undo' => undo_push_mkt_delete($pdo, $id)]);

    case 'shoot_save':
        $sid = (int)($_POST['id'] ?? 0);
        $day = (string)($_POST['day'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) throw new RuntimeException('Escolha o dia da gravação.');
        $vals = [$day, mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160), mb_substr(trim((string)($_POST['place'] ?? '')), 0, 160), mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 4000)];
        if ($sid) {
            $pdo->prepare('UPDATE mkt_shoots SET day = ?, title = ?, place = ?, notes = ? WHERE id = ?')->execute([...$vals, $sid]);
        } else {
            $pdo->prepare('INSERT INTO mkt_shoots (day, title, place, notes, created_by, created_at) VALUES (?,?,?,?,?,?)')->execute([...$vals, $by, now_php()]);
            $sid = (int)$pdo->lastInsertId();
        }
        $want = array_map('intval', (array)($_POST['pieces'] ?? []));
        $was = $pdo->query('SELECT id FROM mkt_pieces WHERE shoot_id = ' . $sid)->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_diff($was, $want) as $pid) { $pdo->prepare('UPDATE mkt_pieces SET shoot_id = NULL WHERE id = ?')->execute([(int)$pid]); mkt_touch($pdo, (int)$pid); }
        foreach (array_diff($want, array_map('intval', $was)) as $pid) {
            $st = $pdo->prepare("UPDATE mkt_pieces SET shoot_id = ? WHERE id = ? AND status = 'producao'");
            $st->execute([$sid, $pid]);
            if ($st->rowCount()) { mkt_touch($pdo, $pid); mkt_log($pdo, $pid, 'm_shoot', date('d/m/Y', strtotime($day)) . ($vals[1] !== '' ? ' · ' . $vals[1] : ''), $by); }
        }
        mout(200, ['ok' => true, 'id' => $sid, 'msg' => 'Dia de gravação guardado.']);

    case 'shoot_drop':
        $sid = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE mkt_pieces SET shoot_id = NULL WHERE shoot_id = ?')->execute([$sid]);
        $pdo->prepare('DELETE FROM mkt_shoots WHERE id = ?')->execute([$sid]);
        mout(200, ['ok' => true, 'msg' => 'Dia de gravação apagado (as peças ficam).']);

    case 'summary':
        $ym = (string)($_POST['month'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) throw new RuntimeException('Mês inválido.');
        $rows = mkt_month_posts($pdo, $ym);
        if (!$rows) throw new RuntimeException('Ainda não há publicações neste mês para resumir.');
        $sum = mkt_ai_summary($pdo, $ym, mkt_month_stats($pdo, $ym, $rows), $rows, $by);
        mout(200, ['ok' => true, 'summary' => $sum, 'msg' => 'Resumo escrito pela IA.']);

    case 'drive':
        $p = piece_req($pdo);
        if ($p['status'] !== 'producao') throw new RuntimeException('A pasta cria-se quando a ideia avança para Produção.');
        require_perm('drive', PERM_VIEW);
        $path = mkt_drive_folder($pdo, $p);
        if (!$path) throw new RuntimeException('O Drive ainda não está configurado neste servidor.');
        mout(200, ['ok' => true, 'url' => drive_page($path), 'msg' => 'Pasta pronta: ' . $path]);
    }
    mout(400, ['ok' => false, 'error' => 'Ação desconhecida.']);
} catch (PDOException $e) {
    error_log('mkt_api ' . $a . ': ' . $e->getMessage());
    mout(500, ['ok' => false, 'error' => 'Não foi possível guardar. Tente outra vez.']);
} catch (RuntimeException $e) {
    mout(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('mkt_api ' . $a . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    mout(500, ['ok' => false, 'error' => 'Não foi possível concluir. Tente outra vez.']);
}

/** O «Anular» de uma ideia acabada de criar: sai, se ninguém mexeu nela. */
function undo_push_mkt_delete(PDO $pdo, int $id): string {
    require_once __DIR__ . '/inc/undo.php';
    $rev = (int)$pdo->query('SELECT rev FROM mkt_pieces WHERE id = ' . $id)->fetchColumn();
    return undo_push('mkt', ['delete' => $id, 'rev' => $rev, 'msg' => 'Ideia anulada.']);
}
