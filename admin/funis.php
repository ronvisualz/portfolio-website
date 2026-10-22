<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/pipeline_flow.php';
require __DIR__ . '/inc/undo.php';
$user = require_login();
require_perm('pipeline', PERM_MANAGE);

// Definições › Funis e automações (§19.7). Funis: criar (do zero ou combinando), renomear,
// ativar/desativar, perguntas do X-Ray; etapas: as fixas (X-Ray, Proposta, Aguardar feedback,
// Kickoff, Concluído) renomeiam-se mas não saem; as variáveis acrescentam-se, ordenam-se e
// eliminam-se; cada etapa diz as rondas de revisão incluídas. Automações (só superadmin e
// admins): por etapa, regras em frase legível — criar, editar, duplicar, ativar/desativar,
// reordenar, eliminar (com «Anular»), aplicar a todos os funis, pré-visualizar.
// Mudar uma regra não mexe nas tarefas já criadas.

$canRules = is_admin();

function funis_url(array $q = []): string {
    return '/admin/funis.php' . ($q ? '?' . http_build_query($q) : '');
}

/** As etapas variáveis vindas do formulário de criação: [[nome, tipo], …]. */
function posted_stages(): array {
    $out = [];
    $names = (array)($_POST['st_name'] ?? []);
    $kinds = (array)($_POST['st_kind'] ?? []);
    $drop  = (array)($_POST['st_drop'] ?? []);
    foreach ($names as $i => $n) {
        $n = mb_substr(trim((string)$n), 0, 120);
        $k = (string)($kinds[$i] ?? '');
        if ($n === '' || !empty($drop[$i]) || !in_array($k, ['commercial', 'production'], true)) continue;
        $out[] = [$n, $k];
    }
    return $out;
}

/** Uma regra vinda do formulário, validada. */
function rule_input(PDO $pdo, array $in): array {
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 200);
    if ($title === '') throw new RuntimeException('Dê um título à tarefa.');
    $trig = (string)($in['trig'] ?? 'enter');
    if (!isset(FLOW_TRIGGERS[$trig])) throw new RuntimeException('Escolha o gatilho.');
    $kind = (string)($in['kind'] ?? 'task');
    if (!isset(TASK_TYPES[$kind]) || in_array($kind, ['personal', 'block'], true)) throw new RuntimeException('Escolha o tipo de tarefa.');
    $assign = (string)($in['assign_to'] ?? 'commercial');
    if (!isset(FLOW_ASSIGN[$assign])) throw new RuntimeException('Escolha o responsável.');
    $ref = null;
    if ($assign === 'user') {
        $ref = (int)($in['assign_user'] ?? 0);
        if (flow_user_ok($pdo, $ref) === null) throw new RuntimeException('Escolha uma pessoa ativa (o superadmin não fica com tarefas).');
    } elseif ($assign === 'dept') {
        $ref = (int)($in['assign_dept'] ?? 0);
        if (!(int)$pdo->query('SELECT COUNT(*) FROM departments WHERE id = ' . $ref)->fetchColumn()) throw new RuntimeException('Escolha o departamento.');
    }
    $mode = (string)($in['due_mode'] ?? 'entry');
    if (!isset(FLOW_DUE_MODES[$mode])) $mode = 'entry';
    $days = trim((string)($in['due_days'] ?? ''));
    if ($trig === 'monthly') {
        $d = (int)($in['month_day'] ?? 1);
        if ($d < 1 || $d > 31) throw new RuntimeException('Indique o dia do mês (1 a 31).');
        $mode = 'none'; $days = (string)$d;
    } elseif ($mode === 'none') {
        $days = '';
    } elseif ($days === '' || !preg_match('/^[+-]?\d{1,3}$/', $days)) {
        throw new RuntimeException('Indique o prazo em dias (ex.: 3, ou -5 para antes da data).');
    }
    if ($trig === 'date' && !in_array($mode, ['delivery', 'shoot', 'validity'], true)) throw new RuntimeException('Num gatilho por data, escolha a data do negócio (entrega, rodagem ou validade).');
    $doc = (string)($in['doc_kind'] ?? '');
    $rep = (string)($in['repeat_rule'] ?? '');
    return [
        'title' => $title, 'trig' => $trig, 'kind' => $kind, 'assign_to' => $assign, 'assign_ref' => $ref,
        'due_mode' => $mode, 'due_days' => $days === '' ? null : (int)$days, 'due_unit' => ($in['due_unit'] ?? 'wd') === 'd' ? 'd' : 'wd',
        'is_gate' => !empty($in['is_gate']) ? 1 : 0, 'doc_kind' => isset(FLOW_DOC_KINDS[$doc]) ? $doc : '',
        'repeat_rule' => isset(FLOW_REPEAT[$rep]) ? $rep : '', 'checklist' => mb_substr(trim((string)($in['checklist'] ?? '')), 0, 4000) ?: null,
        'description' => mb_substr(trim((string)($in['description'] ?? '')), 0, 4000), 'is_active' => isset($in['is_active']) ? (int)!empty($in['is_active']) : 1,
    ];
}

const RULE_COLS = ['title', 'trig', 'kind', 'assign_to', 'assign_ref', 'due_mode', 'due_days', 'due_unit', 'is_gate', 'doc_kind', 'repeat_rule', 'checklist', 'description', 'is_active'];

function rule_insert(PDO $pdo, int $stageId, array $r, ?int $pos = null): int {
    $pos ??= (int)$pdo->query('SELECT COALESCE(MAX(position), 0) + 10 FROM stage_automations WHERE stage_id = ' . $stageId)->fetchColumn();
    $pdo->prepare('INSERT INTO stage_automations (stage_id, position, updated_at, updated_by, ' . implode(', ', RULE_COLS) . ') VALUES (?,?,?,?,' . implode(',', array_fill(0, count(RULE_COLS), '?')) . ')')
        ->execute([$stageId, $pos, date('Y-m-d H:i:s'), current_user()['id'] ?? null, ...array_map(fn($c) => $r[$c] ?? null, RULE_COLS)]);
    return (int)$pdo->lastInsertId();
}

/** A etapa «igual» noutro funil: o mesmo papel/tipo fixo, ou uma de produção com o mesmo nome. */
function stage_match(PDO $pdo, array $s, int $funnelId): ?array {
    foreach (flow_order($pdo, $funnelId) as $o) {
        if ($s['role'] !== '' ? $o['role'] === $s['role'] : ($s['kind'] !== 'commercial' && $s['kind'] !== 'production' ? $o['kind'] === $s['kind'] : ($o['kind'] === $s['kind'] && mb_strtolower(trim($o['name'])) === mb_strtolower(trim($s['name']))))) return $o;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do  = (string)($_POST['do'] ?? '');
    $fid = (int)($_POST['funnel_id'] ?? 0);

    if ($do === 'create') {
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        if ($name === '') { flash('Dê um nome ao funil.', 'err'); redirect(funis_url()); }
        $from = array_map('intval', (array)($_POST['from'] ?? []));
        $fromNames = []; $kinds = []; $qs = [];
        foreach ($from as $id) if ($f = funnel_get($pdo, $id)) {
            $fromNames[] = $f['name'];
            if ($f['xray_kind'] !== '') $kinds[] = $f['xray_kind'];
            foreach (xray_questions($pdo, $id) as $q) $qs[mb_strtolower($q)] = $q;
        }
        $pdo->beginTransaction();
        $id = funnel_create($pdo, $name, posted_stages(), implode(' + ', $fromNames));
        // Funil combinado (§9.1): junta as perguntas do X-Ray dos funis de origem.
        $pdo->prepare('UPDATE funnels SET xray_kind = ?, xray_fields = ? WHERE id = ?')->execute([mb_substr(implode(',', array_unique($kinds)), 0, 60), implode("\n", $qs) ?: null, $id]);
        // A fase comercial e o fecho são iguais em todos os funis (§19.1, §19.6 A/B): as automações
        // dessas etapas vêm do primeiro funil base; as de produção vêm dos funis combinados (mesmo nome).
        $model = $from ?: [(int)$pdo->query('SELECT id FROM funnels WHERE is_base = 1 ORDER BY position, id LIMIT 1')->fetchColumn()];
        foreach (flow_order($pdo, $id) as $ns) {
            foreach ($model as $mid) {
                if (!$mid || !($ms = stage_match($pdo, $ns, (int)$mid))) continue;
                foreach (flow_rules($pdo, (int)$ms['id']) as $r) {
                    $st = $pdo->prepare('SELECT COUNT(*) FROM stage_automations WHERE stage_id = ? AND title = ? AND trig = ?');
                    $st->execute([$ns['id'], $r['title'], $r['trig']]);
                    if (!(int)$st->fetchColumn()) rule_insert($pdo, (int)$ns['id'], $r);
                }
            }
        }
        $pdo->commit();
        flash("Funil «{$name}» criado. As automações acrescentam-se por etapa (ou com «Aplicar a todos os funis»).");
        redirect(funis_url(['f' => $id]));
    }

    $f = funnel_get($pdo, $fid);
    if (!$f) { flash('Funil não encontrado.', 'err'); redirect(funis_url()); }
    $back = funis_url(['f' => $fid]);

    try {
        switch ($do) {
            case 'rename':
                $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
                if ($name !== '') $pdo->prepare('UPDATE funnels SET name = ? WHERE id = ?')->execute([$name, $fid]);
                $q = implode("\n", array_slice(array_values(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 200), preg_split('/\r?\n/', (string)($_POST['xray_fields'] ?? ''))))), 0, 40));
                $pdo->prepare('UPDATE funnels SET xray_fields = ? WHERE id = ?')->execute([$q !== '' ? $q : null, $fid]);
                flash('Funil guardado.');
                redirect($back);
            case 'toggle':
                $pdo->prepare('UPDATE funnels SET is_active = 1 - is_active WHERE id = ?')->execute([$fid]);
                flash((int)$f['is_active'] ? 'Funil desativado: deixa de aparecer no Pipeline e na conversão de leads. Os negócios mantêm-se.' : 'Funil ativado.');
                redirect($back);
            case 'add_stage':
                $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
                $kind = (string)($_POST['kind'] ?? '');
                if ($name === '' || !in_array($kind, ['commercial', 'production'], true)) throw new RuntimeException('Indique o nome e a fase da etapa.');
                $st = $pdo->prepare('SELECT COALESCE(MAX(position), ?) FROM funnel_stages WHERE funnel_id = ? AND kind = ?');
                $st->execute([$kind === 'commercial' ? 19 : 59, $fid, $kind]);
                $pdo->prepare('INSERT INTO funnel_stages (funnel_id, name, kind, position) VALUES (?,?,?,?)')->execute([$fid, $name, $kind, (int)$st->fetchColumn() + 1]);
                flash('Etapa adicionada.');
                redirect($back);
        }

        $s = stage_get($pdo, (int)($_POST['stage_id'] ?? 0));
        if (!$s || (int)$s['funnel_id'] !== $fid) throw new RuntimeException('Etapa não encontrada.');
        $sback = $back . '#st-' . (int)$s['id'];
        $fixed = !in_array($s['kind'], ['commercial', 'production'], true) || $s['role'] !== '';

        switch ($do) {
            case 'save_stage':
                $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
                $rounds = trim((string)($_POST['rounds'] ?? ''));
                if ($rounds !== '' && (!ctype_digit($rounds) || (int)$rounds > 20)) throw new RuntimeException('Rondas incluídas: um número de 0 a 20 (ou vazio).');
                $pdo->prepare('UPDATE funnel_stages SET name = ?, rounds_included = ?, gate_on = ? WHERE id = ?')
                    ->execute([$name !== '' ? $name : $s['name'], $rounds === '' ? null : (int)$rounds, $canRules ? (int)!empty($_POST['gate_on']) : (int)$s['gate_on'], $s['id']]);
                flash('Etapa guardada.');
                redirect($sback);
            case 'move_stage':
                // Troca com a vizinha da mesma zona (as fixas não se mexem).
                $zone = array_values(array_filter(funnel_stages($pdo, $fid), fn($x) => $x['kind'] === $s['kind'] && $x['role'] === ''));
                $i = array_search((int)$s['id'], array_map(fn($x) => (int)$x['id'], $zone), true);
                $j = ($_POST['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
                if (!$fixed && $i !== false && isset($zone[$j])) {
                    [$zone[$i], $zone[$j]] = [$zone[$j], $zone[$i]];
                    $base = $s['kind'] === 'commercial' ? 22 : 60;
                    $up = $pdo->prepare('UPDATE funnel_stages SET position = ? WHERE id = ?');
                    foreach ($zone as $n => $x) $up->execute([$base + $n, $x['id']]);
                }
                redirect($sback);
            case 'del_stage':
                if ($fixed) throw new RuntimeException('As etapas fixas não se eliminam.');
                $st = $pdo->prepare('SELECT COUNT(*) FROM deals WHERE stage_id = ?');
                $st->execute([$s['id']]);
                if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Há negócios nesta etapa: mova-os primeiro.');
                $pdo->prepare('DELETE FROM stage_automations WHERE stage_id = ?')->execute([$s['id']]);
                $pdo->prepare('DELETE FROM funnel_stages WHERE id = ?')->execute([$s['id']]);
                flash('Etapa eliminada.');
                redirect($back);
        }

        // ── automações (§19.7): só superadmin e admins ──
        if (!$canRules) throw new RuntimeException('Só o superadmin e os admins mudam as automações.');
        if ($do === 'rule_new') {
            rule_insert($pdo, (int)$s['id'], rule_input($pdo, $_POST));
            flash('Automação criada. As tarefas já criadas não mudam.');
            redirect($sback);
        }
        $r = flow_rule_get($pdo, (int)($_POST['rule_id'] ?? 0));
        if (!$r || (int)$r['stage_id'] !== (int)$s['id']) throw new RuntimeException('Automação não encontrada.');
        switch ($do) {
            case 'rule_save':
                $v = rule_input($pdo, $_POST);
                $pdo->prepare('UPDATE stage_automations SET ' . implode(', ', array_map(fn($c) => "$c = ?", RULE_COLS)) . ', updated_at = ?, updated_by = ? WHERE id = ?')
                    ->execute([...array_map(fn($c) => $v[$c], RULE_COLS), date('Y-m-d H:i:s'), $user['id'], $r['id']]);
                flash('Automação guardada. As tarefas já criadas não mudam.');
                break;
            case 'rule_dup':
                rule_insert($pdo, (int)$s['id'], ['title' => $r['title'] . ' (cópia)'] + $r, (int)$r['position'] + 1);
                flash('Automação duplicada.');
                break;
            case 'rule_toggle':
                $pdo->prepare('UPDATE stage_automations SET is_active = 1 - is_active, updated_at = ?, updated_by = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $user['id'], $r['id']]);
                flash((int)$r['is_active'] ? 'Automação desativada.' : 'Automação ativada.');
                break;
            case 'rule_move':
                $list = flow_rules($pdo, (int)$s['id']);
                $ids = array_map(fn($x) => (int)$x['id'], $list);
                $i = array_search((int)$r['id'], $ids, true);
                $j = ($_POST['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
                if (isset($ids[$j])) {
                    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                    $up = $pdo->prepare('UPDATE stage_automations SET position = ? WHERE id = ?');
                    foreach ($ids as $n => $x) $up->execute([($n + 1) * 10, $x]);
                }
                redirect($sback);
            case 'rule_del':
                $row = $pdo->query('SELECT * FROM stage_automations WHERE id = ' . (int)$r['id'])->fetch();
                $pdo->prepare('DELETE FROM stage_automations WHERE id = ?')->execute([$r['id']]);
                flash('Automação «' . $r['title'] . '» eliminada.', 'ok', undo_push('auto_rule', $row));
                break;
            case 'rule_all':
                // «Aplicar a todos os funis»: a mesma regra na etapa igual de cada funil (sem repetir).
                $n = 0; $skip = [];
                foreach (funnels_active($pdo) as $of) {
                    if ((int)$of['id'] === $fid) continue;
                    $m = stage_match($pdo, $s, (int)$of['id']);
                    if (!$m) { $skip[] = $of['name']; continue; }
                    $st = $pdo->prepare('SELECT COUNT(*) FROM stage_automations WHERE stage_id = ? AND title = ? AND trig = ?');
                    $st->execute([$m['id'], $r['title'], $r['trig']]);
                    if ((int)$st->fetchColumn() > 0) continue;
                    rule_insert($pdo, (int)$m['id'], $r);
                    $n++;
                }
                flash($n ? 'Aplicada a mais ' . $n . ' funi' . ($n === 1 ? 'l' : 's') . '.' . ($skip ? ' Sem etapa igual em: ' . implode(', ', $skip) . '.' : '') : 'Todos os funis com uma etapa igual já tinham esta automação.' . ($skip ? ' Sem etapa igual em: ' . implode(', ', $skip) . '.' : ''));
                break;
        }
        redirect($sback);
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
        redirect($back);
    }
}

$all = $pdo->query('SELECT f.*, (SELECT COUNT(*) FROM deals d WHERE d.funnel_id = f.id AND d.phase IN (\'pipeline\',\'production\')) AS active_deals
                      FROM funnels f ORDER BY f.is_active DESC, f.position, f.name')->fetchAll();
$fid = (int)($_GET['f'] ?? $_GET['edit'] ?? ($all[0]['id'] ?? 0));
$ef = $fid ? funnel_get($pdo, $fid) : null;
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 AND role <> 'superadmin' ORDER BY name")->fetchAll();
$depts = $pdo->query('SELECT id, name FROM departments ORDER BY position, name')->fetchAll();

/** O formulário de uma regra (nova ou a editar). */
function rule_form(array $f, array $s, ?array $r, array $users, array $depts): string {
    $r ??= ['id' => 0, 'title' => '', 'trig' => 'enter', 'kind' => 'task', 'assign_to' => 'commercial', 'assign_ref' => null, 'due_mode' => 'entry',
            'due_days' => 2, 'due_unit' => 'wd', 'is_gate' => 0, 'doc_kind' => '', 'repeat_rule' => '', 'checklist' => '', 'description' => '', 'is_active' => 1];
    $sel = function (string $name, array $opts, $cur, string $attr = '') {
        $o = '';
        foreach ($opts as $k => $label) $o .= '<option value="' . e((string)$k) . '"' . ((string)$cur === (string)$k ? ' selected' : '') . '>' . e(is_array($label) ? $label[0] : $label) . '</option>';
        return '<select name="' . $name . '"' . $attr . '>' . $o . '</select>';
    };
    $types = array_filter(TASK_TYPES, fn($k) => !in_array($k, ['personal', 'block'], true), ARRAY_FILTER_USE_KEY);
    $uOpts = ['' => '—'] + array_column($users, 'name', 'id');
    $dOpts = ['' => '—'] + array_column($depts, 'name', 'id');
    $uid = 'r' . (int)$r['id'] . 's' . (int)$s['id'];
    return '<form method="post" class="ar-form" data-rule-form>' . csrf_field()
        . '<input type="hidden" name="do" value="' . ($r['id'] ? 'rule_save' : 'rule_new') . '"><input type="hidden" name="funnel_id" value="' . (int)$f['id'] . '">'
        . '<input type="hidden" name="stage_id" value="' . (int)$s['id'] . '"><input type="hidden" name="rule_id" value="' . (int)$r['id'] . '">'
        . '<div class="ar-grid">'
        . '<label>Quando o negócio…' . $sel('trig', FLOW_TRIGGERS, $r['trig'], ' data-trig') . '</label>'
        . '<label>Criar tarefa do tipo' . $sel('kind', $types, $r['kind']) . '</label>'
        . '<label class="wide">Título <small class="muted">variáveis: {cliente} {negócio} {ID} {etapa}</small><input type="text" name="title" maxlength="200" required value="' . e($r['title']) . '"></label>'
        . '<label>Para' . $sel('assign_to', FLOW_ASSIGN, $r['assign_to'] === 'owner' ? 'commercial' : $r['assign_to'], ' data-assign') . '</label>'
        . '<label data-only-assign="user"' . ($r['assign_to'] === 'user' ? '' : ' hidden') . '>Pessoa' . $sel('assign_user', $uOpts, $r['assign_to'] === 'user' ? $r['assign_ref'] : '') . '</label>'
        . '<label data-only-assign="dept"' . ($r['assign_to'] === 'dept' ? '' : ' hidden') . '>Departamento' . $sel('assign_dept', $dOpts, $r['assign_to'] === 'dept' ? $r['assign_ref'] : '') . '</label>'
        . '<label data-not-trig="monthly"' . ($r['trig'] === 'monthly' ? ' hidden' : '') . '>Prazo contado' . $sel('due_mode', FLOW_DUE_MODES, $r['due_mode']) . '</label>'
        . '<label data-not-trig="monthly"' . ($r['trig'] === 'monthly' ? ' hidden' : '') . '>Dias <small class="muted">negativo = antes da data</small><input type="number" name="due_days" min="-120" max="365" value="' . e($r['trig'] === 'monthly' ? '' : (string)$r['due_days']) . '"></label>'
        . '<label data-not-trig="monthly"' . ($r['trig'] === 'monthly' ? ' hidden' : '') . '>Contar' . $sel('due_unit', ['wd' => 'dias úteis', 'd' => 'dias de calendário'], $r['due_unit']) . '</label>'
        . '<label data-only-trig="monthly"' . ($r['trig'] === 'monthly' ? '' : ' hidden') . '>Dia do mês<input type="number" name="month_day" min="1" max="31" value="' . e($r['trig'] === 'monthly' ? (string)$r['due_days'] : '1') . '"></label>'
        . '<label>Documento pedido' . $sel('doc_kind', FLOW_DOC_KINDS, $r['doc_kind']) . '</label>'
        . '<label>Repetição' . $sel('repeat_rule', FLOW_REPEAT, $r['repeat_rule']) . '</label>'
        . '<label class="ck"><input type="checkbox" name="is_gate" value="1"' . ((int)$r['is_gate'] ? ' checked' : '') . '> <span><b>Obrigatória</b> (gate): tem de estar feita para o negócio sair da etapa</span></label>'
        . '<label class="ck"><input type="checkbox" name="is_active" value="1"' . ((int)$r['is_active'] ? ' checked' : '') . '> <span>Ativa</span></label>'
        . '<label class="wide">Checklist <small class="muted">um item por linha</small><textarea name="checklist" rows="2" maxlength="4000">' . e((string)$r['checklist']) . '</textarea></label>'
        . '<label class="wide">Descrição <small class="muted">opcional</small><textarea name="description" rows="2" maxlength="4000">' . e((string)$r['description']) . '</textarea></label>'
        . '</div><div class="actions"><button class="btn sm" type="submit">' . ($r['id'] ? 'Guardar automação' : 'Criar automação') . '</button></div></form>';
}

render_header('Funis e automações', 'pipeline');
render_flashes();
?>
<h1>Funis e automações</h1>
<div class="sub"><a class="lnk" href="/admin/pipeline.php">← Pipeline</a> · <a class="lnk" href="/admin/conta.php#funis">Definições</a></div>
<p class="muted small" style="max-width:760px">Cada etapa pode criar tarefas automaticamente e ter requisitos (gates) para avançar. Os valores iniciais vêm da §19 e são editáveis.
  Mudar uma automação não mexe nas tarefas já criadas. <?= $canRules ? '' : 'As automações só mudam com superadmin ou admin: aqui vê-as.' ?></p>

<nav class="tabs" aria-label="Funis">
  <?php foreach ($all as $f): ?>
    <a href="<?= e(funis_url(['f' => $f['id']])) ?>" class="<?= (int)$f['id'] === $fid ? 'on' : '' ?><?= (int)$f['is_active'] ? '' : ' muted' ?>"><?= e($f['name']) ?><?= (int)$f['active_deals'] ? ' <span class="mono small">' . (int)$f['active_deals'] . '</span>' : '' ?></a>
  <?php endforeach; ?>
  <a href="<?= e(funis_url(['novo' => 1])) ?>#novo" class="<?= !empty($_GET['novo']) ? 'on' : '' ?>">+ Novo funil</a>
</nav>

<?php if ($ef && empty($_GET['novo'])): $stages = funnel_stages($pdo, (int)$ef['id']); ?>
<section class="panel ar-funnel">
  <form method="post" class="ar-fhead">
    <?= csrf_field() ?><input type="hidden" name="do" value="rename"><input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>">
    <div class="grid">
      <div class="field"><label for="fn">Nome do funil</label><input type="text" id="fn" name="name" maxlength="120" value="<?= e($ef['name']) ?>">
        <?php if ($ef['made_from']): ?><span class="muted small">Criado a partir de <?= e($ef['made_from']) ?></span><?php endif; ?></div>
      <div class="field wide"><label for="fx">Perguntas do X-Ray deste funil <small class="muted">uma por linha · juntam-se aos campos comuns</small></label>
        <textarea id="fx" name="xray_fields" rows="4" maxlength="8000"><?= e((string)$ef['xray_fields']) ?></textarea></div>
    </div>
    <div class="actions"><button class="btn sm" type="submit">Guardar funil</button></div>
  </form>
  <form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>">
    <button class="btn ghost sm" type="submit"><?= (int)$ef['is_active'] ? 'Desativar funil' : 'Ativar funil' ?></button></form>
</section>

<?php foreach ($stages as $s):
    $fixed = !in_array($s['kind'], ['commercial', 'production'], true) || $s['role'] !== '';
    $rules = flow_rules($pdo, (int)$s['id']);
    $active = array_filter($rules, fn($r) => (int)$r['is_active'] && $r['trig'] === 'enter');
    $builtIn = match (true) {
        $s['kind'] === 'xray' => 'X-Ray preenchido e validado pelo responsável',
        $s['role'] === 'proposal' => 'Opção A com linhas · prazo · pagamento · revisões · direitos · validade · PDF · enviar move para Aguardar feedback',
        $s['role'] === 'feedback' => 'Resposta do cliente validada (aceite → Kickoff; recusa → Perdido)',
        default => '',
    };
?>
<section class="lv-blk ar-stage z-<?= e($s['kind']) ?>" id="st-<?= (int)$s['id'] ?>">
  <header class="ar-sh">
    <h4><?= e(STAGE_KINDS[$s['kind']] ?? $s['kind']) ?> · <?= e($s['name']) ?> <span class="h-sub"><?= count($rules) ?> <?= count($rules) === 1 ? 'regra' : 'regras' ?></span></h4>
    <?php if (!$fixed): ?>
      <span class="ar-stools">
      <?php foreach (['up' => '↑', 'down' => '↓'] as $dir => $arrow): ?>
        <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="move_stage"><input type="hidden" name="dir" value="<?= $dir ?>">
          <input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>"><input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>">
          <button class="btn ghost sm" type="submit" aria-label="<?= $dir === 'up' ? 'Subir' : 'Descer' ?> a etapa <?= e($s['name']) ?>"><?= $arrow ?></button></form>
      <?php endforeach; ?>
        <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="del_stage"><input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>"><input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>">
          <button class="btn danger sm" type="submit">Eliminar etapa</button></form>
      </span>
    <?php else: ?><span class="chip">fixa</span><?php endif; ?>
  </header>
  <form method="post" class="ar-sform"><?= csrf_field() ?><input type="hidden" name="do" value="save_stage"><input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>"><input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>">
    <label>Nome<input type="text" name="name" maxlength="120" value="<?= e($s['name']) ?>"></label>
    <?php if ($s['kind'] === 'production'): ?><label>Rondas de revisão incluídas<input type="number" name="rounds" min="0" max="20" value="<?= $s['rounds_included'] !== null ? (int)$s['rounds_included'] : '' ?>" placeholder="não conta"></label><?php endif; ?>
    <?php if ($builtIn !== ''): ?>
      <label class="ck"><input type="checkbox" name="gate_on" value="1"<?= (int)$s['gate_on'] ? ' checked' : '' ?><?= $canRules ? '' : ' disabled' ?>> <span>Requisito da etapa: <?= e($builtIn) ?></span></label>
    <?php endif; ?>
    <button class="btn ghost sm" type="submit">Guardar etapa</button>
  </form>

  <div class="ar-rules">
  <?php if (!$rules): ?><p class="muted small">Sem automações nesta etapa.</p><?php endif; ?>
  <?php foreach ($rules as $r): $pp = flow_rule_parts($pdo, $r, $s['name']); ?>
    <div class="ar-rule<?= (int)$r['is_active'] ? '' : ' off' ?>">
      <p class="ar-sent">Quando <span class="tok y"><?= e($pp['when']) ?></span> → criar <span class="tok"><?= icon($pp['icon']) ?> <?= e($pp['kind']) ?></span>
        «<span class="tok t"><?= e($pp['title']) ?></span>» para <span class="tok"><?= e($pp['who']) ?></span>
        <?php if ($pp['due'] !== ''): ?>com prazo <span class="tok"><?= e($pp['due']) ?></span><?php endif; ?>
        <?php if ($pp['repeat']): ?><span class="tok"><?= e($pp['repeat']) ?></span><?php endif; ?>
        <?php if ($pp['doc']): ?><span class="tok"><?= e($pp['doc']) ?></span><?php endif; ?>
        <?php if ($pp['gate']): ?><span class="req">OBRIGATÓRIA</span><?php endif; ?>
        <?php if (!(int)$r['is_active']): ?><span class="chip">desativada</span><?php endif; ?></p>
      <?php if ($canRules): ?>
      <div class="ar-tools">
        <details class="ar-edit"><summary>Editar</summary><?= rule_form($ef, $s, $r, $users, $depts) ?></details>
        <?php foreach (['rule_dup' => 'Duplicar', 'rule_toggle' => (int)$r['is_active'] ? 'Desativar' : 'Ativar', 'rule_all' => 'Aplicar a todos os funis', 'rule_move:up' => '↑', 'rule_move:down' => '↓', 'rule_del' => 'Eliminar'] as $act => $label):
            [$a, $dir] = array_pad(explode(':', $act), 2, ''); ?>
          <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $a ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
            <input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>"><input type="hidden" name="stage_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
            <button class="lnk<?= $a === 'rule_del' ? ' del' : '' ?>" type="submit"<?= $dir ? ' aria-label="' . ($dir === 'up' ? 'Subir' : 'Descer') . ' a automação"' : '' ?>><?= e($label) ?></button></form>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>

  <div class="ar-foot">
    <details class="ar-prev"><summary>Pré-visualizar</summary>
      <?php if (!$active): ?><p class="muted small">Ao entrar em «<?= e($s['name']) ?>» não é criada nenhuma tarefa.</p>
      <?php else: ?><p class="small">Ao entrar em «<?= e($s['name']) ?>» <?= count($active) === 1 ? 'vai ser criada esta tarefa' : 'vão ser criadas estas ' . count($active) . ' tarefas' ?>:</p>
        <ol class="ar-plist">
        <?php foreach ($active as $r): $pp = flow_rule_parts($pdo, $r, $s['name']); ?>
          <li><?= icon($pp['icon']) ?> <b><?= e($pp['title']) ?></b> <span class="muted">· <?= e($pp['who']) ?><?= $pp['due'] !== '' ? ' · ' . e($pp['due']) : '' ?></span><?= $pp['gate'] ? ' <span class="req">OBRIGATÓRIA</span>' : '' ?></li>
        <?php endforeach; ?></ol>
      <?php endif; ?>
      <?php if ($builtIn !== '' && (int)$s['gate_on']): ?><p class="muted small">E o requisito da etapa: <?= e($builtIn) ?>.</p><?php endif; ?>
    </details>
    <?php if ($canRules): ?><details class="ar-new"><summary><?= icon('plus') ?> Nova automação</summary><?= rule_form($ef, $s, null, $users, $depts) ?></details><?php endif; ?>
  </div>
</section>
<?php endforeach; ?>

<form method="post" class="bar" style="margin-top:12px">
  <?= csrf_field() ?><input type="hidden" name="do" value="add_stage"><input type="hidden" name="funnel_id" value="<?= (int)$ef['id'] ?>">
  <input type="text" name="name" maxlength="120" placeholder="Nova etapa" required aria-label="Nova etapa">
  <select name="kind" aria-label="Fase"><option value="production">Produção (entre Kickoff e Concluído)</option><option value="commercial">Comercial (entre Aguardar feedback e Kickoff)</option></select>
  <button class="btn sm" type="submit">Adicionar etapa</button>
</form>
<?php endif; ?>

<?php if (!empty($_GET['novo']) || !$ef):
$combine = array_map('intval', (array)($_GET['from'] ?? []));
$preset  = $combine ? funnels_combined_stages($pdo, $combine) : [];
$preset  = array_values(array_filter($preset, fn($p) => !in_array(mb_strtolower($p[0]), ['proposta', 'aguardar feedback'], true)));
$activeF = array_filter($all, fn($f) => (int)$f['is_active']);
?>
<h2 id="novo">Novo funil</h2>
<form method="get" action="/admin/funis.php#novo"><input type="hidden" name="novo" value="1">
  <div class="field wide"><label>Combinar funis existentes (opcional, cross-selling)</label>
    <div class="actions" style="margin-top:0.2rem">
      <?php foreach ($activeF as $f): ?>
        <label class="check"><input type="checkbox" name="from[]" value="<?= (int)$f['id'] ?>" <?= in_array((int)$f['id'], $combine, true) ? 'checked' : '' ?>> <?= e($f['name']) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="actions"><button class="btn ghost sm" type="submit">Juntar as etapas destes funis</button></div>
</form>

<form method="post">
  <?= csrf_field() ?><input type="hidden" name="do" value="create">
  <?php foreach ($combine as $id): ?><input type="hidden" name="from[]" value="<?= $id ?>"><?php endforeach; ?>
  <div class="grid">
    <div class="field wide"><label for="fname">Nome do funil</label>
      <input type="text" id="fname" name="name" required maxlength="120"
             value="<?= e(implode(' + ', array_map(fn($id) => funnel_get($pdo, $id)['name'] ?? '', $combine))) ?>"
             placeholder="ex.: Campanhas + Marketing Buddy"></div>
  </div>
  <p class="muted" style="font-size:0.75rem">Etapas fixas: X-Ray → Proposta → Aguardar feedback → … → Kickoff → … → Concluído. As etapas abaixo entram entre elas; edite, retire ou acrescente antes de criar.</p>
  <div class="tablewrap"><table>
    <thead><tr><th>Etapa</th><th>Fase</th><th>Retirar</th></tr></thead>
    <tbody>
    <?php $rows = array_merge($preset, array_fill(0, 3, ['', 'production']));
      foreach ($rows as $i => [$n, $k]): ?>
      <tr>
        <td><input type="text" name="st_name[<?= $i ?>]" maxlength="120" value="<?= e($n) ?>" placeholder="<?= $n === '' ? 'nova etapa (opcional)' : '' ?>" aria-label="Etapa"></td>
        <td><select name="st_kind[<?= $i ?>]" aria-label="Fase"><option value="commercial" <?= $k === 'commercial' ? 'selected' : '' ?>>Comercial</option>
            <option value="production" <?= $k === 'production' ? 'selected' : '' ?>>Produção</option></select></td>
        <td><?php if ($n !== ''): ?><input type="checkbox" name="st_drop[<?= $i ?>]" value="1" aria-label="Retirar"><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="actions"><button class="btn" type="submit">Criar funil</button></div>
</form>
<?php endif; ?>
<script>
  // O formulário de uma regra: mostra só os campos que o gatilho e o responsável pedem.
  document.querySelectorAll('[data-rule-form]').forEach(function (f) {
    function sync() {
      var t = f.querySelector('[data-trig]').value, a = f.querySelector('[data-assign]').value;
      f.querySelectorAll('[data-only-trig]').forEach(function (n) { n.hidden = n.getAttribute('data-only-trig') !== t; });
      f.querySelectorAll('[data-not-trig]').forEach(function (n) { n.hidden = n.getAttribute('data-not-trig') === t; });
      f.querySelectorAll('[data-only-assign]').forEach(function (n) { n.hidden = n.getAttribute('data-only-assign') !== a; });
    }
    f.addEventListener('change', sync);
  });
</script>
<?php render_footer();
