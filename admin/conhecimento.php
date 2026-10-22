<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
$user = require_login();
require_page('conhecimento');

// Centro de Conhecimento (§12.11). Separadores «Navegação» — os atalhos de
// teclado e gestos da plataforma, a partir de SHORTCUTS (inc/ui.php; atualizar
// lá sempre que surgir um atalho novo) — e «Voz da topper.» (§16.4), o texto
// que a IA do Estúdio da Marketing usa sempre.

const KNOW_TABS = ['navegacao' => 'Navegação', 'voz' => 'Voz da topper.'];
$tab = isset(KNOW_TABS[$_GET['s'] ?? '']) ? (string)$_GET['s'] : 'navegacao';
$hasVoice = db_has_table($pdo, 'mkt_pillars');
$canEditVoice = $hasVoice && can('conhecimento', PERM_EDIT);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'voice') {
    csrf_check();
    if (!$canEditVoice) { http_response_code(403); exit('Sem permissão.'); }
    require_once __DIR__ . '/inc/marketing.php';
    $v = trim(mb_substr((string)($_POST['voice'] ?? ''), 0, 8000));
    if ($v === '') {
        flash('A voz não pode ficar vazia: a IA precisa dela.', 'err');
    } else {
        mkt_setting_set($pdo, 'mkt_voice', $v);
        mkt_setting_set($pdo, 'mkt_voice_meta', (string)$user['id'] . '|' . now_php());
        flash('Voz da topper. guardada. A IA do Estúdio passa a usá-la já.');
    }
    redirect('/admin/conhecimento.php?s=voz');
}

render_header('Conhecimento', 'conhecimento', '', [KNOW_TABS[$tab]]);
render_flashes();
?>
<h1>Centro de Conhecimento</h1>
<p class="sub">Processos, templates e guias da equipa.</p>

<div class="tabs" role="tablist" aria-label="Separadores">
  <?php foreach (KNOW_TABS as $k => $label): ?>
    <a href="/admin/conhecimento.php?s=<?= e($k) ?>" class="<?= $tab === $k ? 'on' : '' ?>" role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'navegacao'): ?>
<p class="muted" style="max-width:760px">Atalhos de teclado e gestos que funcionam na plataforma. Os atalhos de uma só tecla
  não atuam enquanto se escreve num campo.</p>
<div class="tablewrap keys">
<table>
  <thead><tr><th>Tecla ou gesto</th><th>O que faz</th><th class="hide-sm">Onde</th></tr></thead>
  <tbody>
  <?php foreach (SHORTCUTS as [$keys, $what, $where]): ?>
    <tr>
      <td><?php foreach (preg_split('/\s+(ou|\/)\s+/u', $keys, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part): ?><?= $i % 2 ? ' <span class="muted">' . e($part) . '</span> ' : '<span class="kbd">' . e($part) . '</span>' ?><?php endforeach; ?></td>
      <td><?= e($what) ?></td>
      <td class="hide-sm muted"><?= e($where) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php elseif (!$hasVoice): ?>
<p class="muted">A voz da topper. aparece depois de a base de dados chegar ao passo 18 (Marketing).</p>
<?php else:
    require_once __DIR__ . '/inc/marketing.php';
    $meta = explode('|', mkt_setting($pdo, 'mkt_voice_meta'), 2);
    $who = ($meta[0] ?? '') !== '' ? (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)$meta[0])->fetchColumn() : ''; ?>
<p class="muted" style="max-width:760px">Como a topper. fala nas redes: quem somos, o tom, o que dizemos e o que evitamos. A IA do Estúdio
  (Marketing) lê este texto em todos os pedidos, antes de escrever qualquer versão.</p>
<form method="post" class="panel" style="max-width:860px">
  <?= csrf_field() ?><input type="hidden" name="do" value="voice">
  <div class="field wide"><label for="voice">Voz da topper.</label>
    <textarea id="voice" name="voice" rows="14" maxlength="8000"<?= $canEditVoice ? '' : ' readonly' ?>><?= e(mkt_voice($pdo)) ?></textarea>
    <span class="muted" style="font-size:12px"><?= $who !== '' ? 'Mudada por ' . e($who) . ' · ' . e(lisbon_fmt(strtotime((string)($meta[1] ?? 'now')), 'd/m/Y H:i')) : 'Texto de partida da plataforma.' ?></span></div>
  <?php if ($canEditVoice): ?><div class="actions"><button class="btn" type="submit">Guardar</button></div><?php endif; ?>
</form>
<?php endif; ?>
<?php render_footer();
