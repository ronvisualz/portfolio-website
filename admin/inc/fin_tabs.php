<?php
/**
 * topper. admin — Financeiro: o conteúdo de cada separador (incluído por financeiro.php, que já viu o acesso).
 * Variáveis: $pdo, $tab, $year, $years, $rate, $canEdit, $canManage, $uid, $form, $user.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin_cash.php';

// ═══ Visão geral ═══════════════════════════════════════════
if ($tab === 'geral'):
    $rw = fin_runway($pdo);
    $cf = fin_cashflow12($pdo);
    $recv = fin_receivables($pdo);
    $parts = [];
    foreach (FIN_AGING as $k => $l) $parts[$k] = ['label' => $l, 'value' => 0.0, 'n' => 0, 'url' => fin_url(['tab' => 'receber', 'aging' => $k])];
    foreach ($recv as $r) { $parts[$r['bucket']]['value'] += $r['gross']; $parts[$r['bucket']]['n']++; }
    $fx = $rw['fixed'];
    $tr = fin_trend($pdo);
    $N = $rw['reserve_months'];
?>
<div class="fin-grid">
  <section class="flow fin-runway" id="runway" aria-labelledby="h-rw">
    <h3 id="h-rw">Runway</h3>
    <div class="rw-big"><b><?= $rw['useful'] === null ? '—' : e(fin_num($rw['useful'], 1)) ?></b> <span><?= $rw['useful'] === null ? '' : 'meses úteis' ?></span>
      <?php if ($rw['useful'] !== null): ?><span class="muted small"><?= $rw['useful'] > 0 ? 'até ' . fin_date_pt($rw['date']) : 'já dentro da reserva' ?> · total <?= e(fin_num((float)$rw['total'], 1)) ?> meses</span><?php endif; ?></div>
    <?= chart_runway($rw['useful'], $N, $rw['total'], fin_date_pt($rw['date']), 'Runway: ' . ($rw['useful'] === null ? 'sem dados' : fin_num($rw['useful'], 1) . ' meses úteis e reserva de ' . fin_num($N, 0) . ' meses')) ?>
    <div class="rw-read">
      <div><div class="k">Conservador</div><div class="v"><?= $rw['useful'] === null ? '—' : e(fin_num($rw['useful'], 1)) . ' meses' ?></div><div class="n">sem receita, só custos fixos de <?= eur0($rw['burn']) ?>/mês</div></div>
      <div><div class="k">Com tendência</div>
        <?php if ($rw['sustainable']): ?><div class="v ok">Sustentável</div><div class="n">o saldo cresce <?= eur0($rw['growth']) ?>/mês (receita média de 6 meses <?= eur0($tr['avg6']) ?>)</div>
        <?php else: ?><div class="v"><?= $rw['trend_useful'] === null ? '—' : e(fin_num($rw['trend_useful'], 1)) . ' meses' ?></div><div class="n">custos fixos − receita média recebida (<?= eur0($tr['avg6']) ?>/mês)<?= $tr['cmgr6'] !== null ? ', a ' . ($tr['cmgr6'] >= 0 ? '+' : '') . fin_num($tr['cmgr6'] * 100, 1) . '%/mês' : '' ?></div><?php endif; ?></div>
    </div>
    <p class="small muted">Reserva de emergência: <?= e(fin_num($N, 0)) ?> meses de custos fixos (<?= eur0($rw['reserve']) ?>)<?= is_admin() ? ' · <a class="lnk" href="/admin/definicoes.php?t=financeiro">mudar</a>' : '' ?>. Saldo disponível <?= eur0($rw['available']) ?>.</p>
  </section>
  <figure class="flow" aria-labelledby="h-cf">
    <h3 id="h-cf">Fluxo de caixa · 12 meses</h3>
    <div class="lg"><span><i class="box" style="background:var(--c-real)"></i>Entradas</span><span><i class="box" style="background:var(--c-goal)"></i>Saídas</span><span><i style="background:var(--fg)"></i>Saldo</span><span><i class="dash"></i>Previsão</span></div>
    <?= chart_cashflow($cf, 'Fluxo de caixa: 6 meses passados e 6 de previsão') ?>
  </figure>
  <section class="flow" aria-labelledby="h-ag">
    <h3 id="h-ag">Por receber por antiguidade</h3>
    <?= chart_aging_stack($parts, 'Por receber por antiguidade') ?>
  </section>
  <section class="flow" aria-labelledby="h-fx">
    <h3 id="h-fx">Custos fixos por categoria · <?= eur0($fx['total']) ?>/mês</h3>
    <?= $fx['by_cat'] ? chart_hbars($fx['by_cat']) : '<p class="muted small">Sem custos fixos. <a class="lnk" href="' . e(fin_url(['tab' => 'fixos'])) . '">Registar</a>.</p>' ?>
  </section>
  <figure class="flow fin-wide" aria-labelledby="h-tr">
    <h3 id="h-tr">Receita com tendência</h3>
    <div class="lg"><span><i class="box" style="background:var(--c-real)"></i>Recebido (s/ IVA)</span><span><i style="background:var(--c-goal)"></i>Média de 3 meses</span><span><i class="dash"></i>Projeção realista</span><span><i class="box band"></i>Pessimista — otimista</span></div>
    <?= chart_revenue_trend($tr['months'], $tr['received'], $tr['ma3'], $tr['proj'], 'Receita recebida por mês, média móvel e projeção', 1040) ?>
    <p class="small muted">Crescimento médio mensal: 6 meses <?= $tr['cmgr6'] === null ? '—' : e(fin_num($tr['cmgr6'] * 100, 1)) . '%' ?> · 12 meses <?= $tr['cmgr12'] === null ? '—' : e(fin_num($tr['cmgr12'] * 100, 1)) . '%' ?> ·
      Pipeline: ganhos por faturar <b><?= eur0($tr['pipeline']['won']) ?></b> (<?= (int)$tr['pipeline']['won_n'] ?>, certos) · propostas abertas × probabilidade <b><?= eur0($tr['pipeline']['probable']) ?></b> (<?= (int)$tr['pipeline']['probable_n'] ?>, prováveis)</p>
  </figure>
</div>

<?php
    // os negócios do ano (o separador «Negócios» de antes) e a faturação contra a meta
    $deals = year_deals($pdo, $year, $rate);
    $t = finance_year($pdo, $year, $rate);
    $rev = array_fill(1, 12, 0.0);
    foreach ($deals as $d) if ($d['month']) $rev[(int)$d['month']] += $d['m']['budget_net'];
    $goal = (float)setting($pdo, 'meta_anual_' . $year, 0);
    $cy = (int)date('Y');
    $upto = $year < $cy ? 12 : ($year === $cy ? (int)date('n') : 1);
    $done = array_sum(array_slice($rev, 0, $upto, true));
    $finDeals = array_flip($pdo->query("SELECT DISTINCT deal_id FROM fin_docs WHERE direction = 'in' AND deal_id IS NOT NULL AND deleted_at IS NULL AND status <> 'void'")->fetchAll(PDO::FETCH_COLUMN));
?>
<h2 id="faturacao">Negócios e faturação de <?= $year ?></h2>
<form class="bar" method="get"><input type="hidden" name="tab" value="geral">
  <select name="year" onchange="this.form.submit()" aria-label="Ano"><?php foreach ($years as $y): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select>
  <noscript><button class="btn sm">Ver</button></noscript>
  <span class="muted small">Faturação s/ IVA <b><?= eur0($t['revenue']) ?></b> · gastos <?= eur0($t['costs']) ?> · lucro antes de impostos <b class="<?= $t['profit'] < 0 ? 'neg' : '' ?>"><?= eur0($t['profit']) ?></b><?= $goal > 0 ? ' · meta ' . eur0($goal) . ' (' . round($done / $goal * 100) . '%)' : '' ?></span>
</form>
<div class="flow-grid">
  <figure class="flow" style="margin:0"><h3>Faturação por mês</h3>
    <div class="lg"><span><i class="box" style="background:var(--c-real)"></i>Faturação s/ IVA</span><?php if ($goal > 0): ?><span><i style="background:var(--c-goal)"></i>Meta do mês (<?= eur0($goal / 12) ?>)</span><?php endif; ?></div>
    <?= chart_months($rev, $goal / 12, $year === $cy ? (int)date('n') : 0, 'Faturação por mês em ' . $year) ?></figure>
  <figure class="flow" style="margin:0"><h3>Acumulado contra a meta</h3>
    <div class="lg"><span><i style="background:var(--c-real)"></i>Acumulado</span><?php if ($goal > 0): ?><span><i style="background:var(--c-goal)"></i>Ritmo da meta</span><?php endif; ?></div>
    <?= chart_cumulative($rev, $goal, $upto, 'Faturação acumulada em ' . $year . ' contra a meta anual') ?></figure>
</div>
<?php if ($canManage): ?>
<form class="bar" method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="do" value="goal"><input type="hidden" name="year" value="<?= $year ?>">
  <label for="goal" class="muted small">Meta anual de faturação para <?= $year ?> (€, s/ IVA)</label>
  <input type="text" id="goal" name="goal" inputmode="decimal" value="<?= $goal > 0 ? e(number_format($goal, 0, ',', '')) : '' ?>" style="flex:0 0 140px"><button class="btn sm ghost" type="submit">Guardar meta</button></form>
<?php endif; ?>
<?php $pg = paginate($deals, 'pt'); ?>
<div class="tablewrap" style="margin-top:12px"><table>
  <thead><tr><th>ID</th><th>Negócio</th><th>Mês</th><th class="num">s/ IVA</th><th class="num">Gastos</th><th class="num">Lucro</th><th>Recebido</th></tr></thead>
  <tbody>
  <?php if (!$deals): ?><tr><td colspan="7" class="empty">Sem negócios em <?= $year ?>.</td></tr><?php endif; ?>
  <?php foreach ($pg['rows'] as $d): $m = $d['m']; $hasFin = isset($finDeals[$d['id']]); ?>
    <tr><td class="nowrap"><a class="lnk id" href="/admin/negocio.php?id=<?= (int)$d['id'] ?>#financeiro"><?= e($d['code']) ?></a></td>
      <td><span class="b"><?= e($d['name']) ?></span><?= $d['job_type'] ? '<div class="muted" style="font-size:0.62rem">' . e($d['job_type']) . '</div>' : '' ?></td>
      <td class="nowrap"><?= e($d['month'] ? month_name((int)$d['month']) : '—') ?></td>
      <td class="num"><?= eur($m['budget_net']) ?></td><td class="num"><?= eur($m['expenses']) ?></td><td class="num b"><?= eur($m['profit']) ?></td>
      <td><?php if ($hasFin): ?><a class="lnk" href="<?= e(fin_url(['tab' => 'receber', 'q' => $d['code'], 'v' => 'all'])) ?>"><?= (int)$d['is_paid'] ? 'Pago' : 'Ver faturas' ?></a>
        <?php elseif ($canEdit): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_paid"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="back" value="<?= e(fin_url(['tab' => 'geral', 'year' => $year]) . '#faturacao') ?>">
          <label class="check" data-tip="Negócio sem faturas no Financeiro: o «pago» de antes"><input type="checkbox" onchange="this.form.submit()" <?= (int)$d['is_paid'] ? 'checked' : '' ?>> Pago</label></form>
        <?php else: ?><?= (int)$d['is_paid'] ? 'Pago' : 'Por pagar' ?><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?= page_nav($pg) ?>

<?php
// ═══ A receber ═════════════════════════════════════════════
elseif ($tab === 'receber'):
    $docId = (int)($_GET['doc'] ?? 0);
    $editId = (int)($_GET['edit'] ?? 0);
    $new = !empty($_GET['new']);
    $req = !empty($_GET['request']);
    $dealQ = (int)($_GET['deal'] ?? 0);
    $v = (string)($_GET['v'] ?? 'open');
    $ag = isset(FIN_AGING[$_GET['aging'] ?? '']) ? (string)$_GET['aging'] : '';
    $q = trim((string)($_GET['q'] ?? ''));
    $self = fin_url(['tab' => 'receber', 'v' => $v !== 'open' ? $v : null, 'aging' => $ag, 'q' => $q]);
    $views = ['open' => 'Em aberto', 'requested' => 'Pedidas', 'late' => 'Em atraso', 'paid' => 'Pagas', 'void' => 'Anuladas', 'all' => 'Todas'];
    // os emails por confirmar (faturas do contabilista que não liguei sozinho)
    $mails = $pdo->query("SELECT * FROM fin_mail WHERE status = 'review' ORDER BY id DESC LIMIT 20")->fetchAll();
    $recv = fin_receivables($pdo);
    $parts = [];
    foreach (FIN_AGING as $k => $l) $parts[$k] = ['label' => $l, 'value' => 0.0, 'n' => 0, 'url' => fin_url(['tab' => 'receber', 'aging' => $ag === $k ? null : $k]), 'on' => $ag === $k];
    foreach ($recv as $r) { $parts[$r['bucket']]['value'] += $r['gross']; $parts[$r['bucket']]['n']++; }
?>
<?php if ($mails && $canEdit): ?>
<section class="panel fin-inbox" aria-labelledby="h-inb"><h3 id="h-inb">Por confirmar · emails</h3>
  <?php foreach ($mails as $m): $sug = json_decode((string)$m['suggestions'], true) ?: []; $ai = json_decode((string)$m['ai'], true) ?: []; $att = json_decode((string)$m['att'], true) ?: []; ?>
  <div class="fin-mail">
    <div><b><?= e($m['subject']) ?></b> <span class="muted small">de <?= e($m['from_addr']) ?><?= ($m['box'] ?? '') !== '' ? ' · em ' . e($m['box']) : '' ?> · <?= e($m['received_at'] ? date('d/m H:i', strtotime((string)$m['received_at'])) : '') ?></span>
      <?php foreach ($att as $i => $a): ?> · <a class="lnk" href="<?= e(fin_file_href('mail', (int)$m['id']) . '&n=' . $i) ?>" target="_blank" rel="noopener"><?= e($a['name']) ?></a><?php endforeach; ?>
      <?php if ($ai): $src = ['qr' => 'Lido do QR fiscal', 'xml' => 'Lido do XML'][$ai['source'] ?? ''] ?? 'A IA leu'; ?><div class="small muted"><?= e($src) ?>: <?= e(trim(($ai['number'] ?? '') . ' · emitente NIF ' . (($ai['issuer_nif'] ?? '') ?: '—') . ' → adquirente NIF ' . (($ai['client_nif'] ?? '') ?: '—') . ' · ' . eur((float)($ai['total'] ?? 0)) . ' · ' . fin_date_pt($ai['issue_date'] ?? ''))) ?><?= ($ai['source'] ?? '') === 'ai' || !isset($ai['source']) ? ' (confiança ' . round((float)($ai['confidence'] ?? 0) * 100) . '%)' : '' ?><?= !empty($ai['atcud']) ? ' · ATCUD ' . e($ai['atcud']) : '' ?></div><?php endif; ?>
      <div class="small"><?= e($m['note']) ?></div></div>
    <div class="fin-mail-act">
      <?php if ($sug): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="mail_confirm"><input type="hidden" name="mail" value="<?= (int)$m['id'] ?>">
        <select name="doc" aria-label="Pedido"><?php foreach ($sug as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['label'] . ' (' . $s['why'] . ')') ?></option><?php endforeach; ?></select>
        <button class="btn sm" type="submit">Ligar a este pedido</button></form><?php endif; ?>
      <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="mail_new"><input type="hidden" name="mail" value="<?= (int)$m['id'] ?>"><input type="hidden" name="dir" value="in"><button class="btn ghost sm" type="submit">Lançar como fatura emitida</button></form>
      <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="mail_new"><input type="hidden" name="mail" value="<?= (int)$m['id'] ?>"><input type="hidden" name="dir" value="out"><button class="btn ghost sm" type="submit">É de um fornecedor</button></form>
      <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="mail_ignore"><input type="hidden" name="mail" value="<?= (int)$m['id'] ?>"><button class="btn ghost sm" type="submit">Pôr de parte</button></form>
    </div>
  </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (($new || $editId) && $canEdit):
    $d = $editId ? fin_doc($pdo, $editId) : null;
    $vals = $form && ($form['direction'] ?? '') === 'in' ? $form : ($d ?? ['direction' => 'in', 'vat_rate' => $rate, 'issue_date' => today_lisbon(), 'due_date' => date('Y-m-d', strtotime(today_lisbon() . ' +' . (int)setting($pdo, 'fin_terms_days', 30) . ' days'))]);
    if ($dealQ && !$d && ($bd = billing_deal($pdo, $dealQ))) $vals = array_merge(['scope' => 'deal', 'deal_id' => $dealQ, 'party_name' => (string)$bd['contact_name'], 'party_nif' => (string)$bd['contact_nif'], 'party_email' => (string)$bd['contact_email'], 'description' => (string)$bd['name'], 'vat_rate' => (int)$bd['has_vat'] ? $rate : 0], $vals);
?>
<section class="panel"><h2><?= $d ? 'Editar ' . e(fin_doc_label($d)) : 'Lançar fatura emitida' ?></h2>
  <p class="small muted">A fatura sai do programa de faturação certificado (ou do contabilista): aqui regista-se com o PDF. Se for de um negócio com a tarefa «Emitir fatura», a tarefa fica feita.</p>
  <?= fin_doc_form($pdo, 'in', $vals, fin_url(['tab' => 'receber']), (bool)$d) ?></section>
<?php elseif ($req && $canEdit):
    require_once __DIR__ . '/fin_mail.php';
    $deal = $dealQ ? (billing_deal($pdo, $dealQ) ?: null) : null;
    $vals = $form ?? fin_request_defaults($pdo, $deal);
    $cfg = fin_mail_config(); $acc = fin_accountant_email($pdo);
?>
<section class="panel"><h2>Pedir fatura ao contabilista</h2>
  <?php if (!$cfg || $acc === ''): ?><div class="flash err">Para pedir faturas por email falta <?= !$cfg ? 'a palavra-passe do seu email (Perfil › Email de envio) ou do email geral (Definições › Integrações › Email)' : '' ?><?= !$cfg && $acc === '' ? ' e ' : '' ?><?= $acc === '' ? 'o email do contabilista em Definições › Financeiro' : '' ?>.</div><?php endif; ?>
  <p class="small muted">Sai de <?= e($cfg['user'] ?? 'o seu email ou do geral') ?>, com o seu nome, para <?= e($acc ?: 'o contabilista') ?> com o código no assunto. A resposta com o PDF é lida sozinha (pelo código ou pelo QR fiscal) e liga-se a este pedido.</p>
  <form method="post" class="fin-form" data-fin-req><?= csrf_field() ?><input type="hidden" name="do" value="request_invoice"><input type="hidden" name="back" value="<?= e(fin_url(['tab' => 'receber'])) ?>">
    <?= fin_mail_as_select($pdo) ?>
    <div class="grid">
      <fieldset class="field fin-scope"><legend>Âmbito</legend><label class="check"><input type="radio" name="scope" value="deal"<?= ($vals['scope'] ?? '') === 'deal' ? ' checked' : '' ?>> Negócio</label> <label class="check"><input type="radio" name="scope" value="company"<?= ($vals['scope'] ?? '') !== 'deal' ? ' checked' : '' ?>> Empresa</label></fieldset>
      <div class="field fin-deal"<?= ($vals['scope'] ?? '') === 'deal' ? '' : ' hidden' ?>><label for="rq-deal">Negócio</label><select id="rq-deal" name="deal_id" data-reload="request"><?= fin_deal_options($pdo, (int)($vals['deal_id'] ?? 0) ?: null) ?></select></div>
      <div class="field"><label for="rq-pn">Cliente</label><input type="text" id="rq-pn" name="party_name" required maxlength="190" value="<?= e((string)$vals['party_name']) ?>"></div>
      <div class="field"><label for="rq-nif">NIF</label><input type="text" id="rq-nif" name="party_nif" maxlength="20" value="<?= e((string)$vals['party_nif']) ?>"><label class="check small"><input type="checkbox" name="no_nif" value="1"> Consumidor final / sem NIF</label></div>
      <div class="field wide"><label for="rq-ad">Morada</label><input type="text" id="rq-ad" name="party_address" maxlength="300" value="<?= e((string)$vals['party_address']) ?>"></div>
      <div class="field"><label for="rq-em">Email do cliente</label><input type="email" id="rq-em" name="party_email" maxlength="190" value="<?= e((string)$vals['party_email']) ?>"></div>
      <div class="field wide"><label for="rq-ds">Descrição do serviço</label><input type="text" id="rq-ds" name="description" required maxlength="500" value="<?= e((string)$vals['description']) ?>"></div>
      <div class="field"><label for="rq-b">Valor sem IVA (€)</label><input type="text" id="rq-b" name="base" required inputmode="decimal" value="<?= $vals['base'] !== '' ? e(number_format((float)$vals['base'], 2, ',', '')) : '' ?>"></div>
      <div class="field"><label for="rq-r">Taxa de IVA</label><select id="rq-r" name="vat_rate"><?= fin_opts([(string)fin_p($pdo, 'vat_normal', 23) => fin_num(fin_p($pdo, 'vat_normal', 23)) . '%', (string)fin_p($pdo, 'vat_intermediate', 13) => fin_num(fin_p($pdo, 'vat_intermediate', 13)) . '%', (string)fin_p($pdo, 'vat_reduced', 6) => fin_num(fin_p($pdo, 'vat_reduced', 6)) . '%', '0' => 'Isento / autoliquidação'], (string)(float)$vals['vat_rate']) ?></select></div>
      <div class="field wide"><label for="rq-vn">Menção de isenção (se sem IVA)</label><input type="text" id="rq-vn" name="vat_note" maxlength="190" value="<?= e((string)$vals['vat_note']) ?>" placeholder="<?= e(fin_ps($pdo, 'vat_exempt_note')) ?>"></div>
      <div class="field"><label for="rq-in">Prestação</label><input type="text" id="rq-in" name="installment" maxlength="60" value="<?= e((string)$vals['installment']) ?>" placeholder="1.ª de 2 — 50%"></div>
      <div class="field"><label for="rq-due">Vencimento</label><input type="date" id="rq-due" name="due_date" value="<?= e((string)$vals['due_date']) ?>"></div>
      <div class="field wide"><label for="rq-no">Notas para o contabilista</label><input type="text" id="rq-no" name="notes" maxlength="2000" value="<?= e((string)($vals['notes'] ?? '')) ?>"></div>
    </div>
    <div class="actions"><button class="btn" type="submit"<?= !$cfg || $acc === '' ? ' disabled' : '' ?>><?= icon('send') ?> Enviar pedido</button> <a class="btn ghost" href="<?= e(fin_url(['tab' => 'receber'])) ?>">Cancelar</a></div>
  </form></section>
<?php endif; ?>

<?= chart_aging_stack($parts, 'Por receber por antiguidade (clique para filtrar)') ?>
<form class="bar fin-bar" method="get"><input type="hidden" name="tab" value="receber"><?php if ($ag): ?><input type="hidden" name="aging" value="<?= e($ag) ?>"><?php endif; ?>
  <?= views_bar(array_map(fn($l) => $l, $views), $v, fn($k) => fin_url(['tab' => 'receber', 'v' => $k !== 'open' ? $k : null, 'q' => $q])) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Procurar nº, cliente, NIF, negócio" aria-label="Procurar"><input type="hidden" name="v" value="<?= e($v) ?>">
</form>
<?php
    $st = ['open' => ['requested', 'issued', 'partial'], 'requested' => ['requested'], 'late' => ['issued', 'partial'], 'paid' => ['paid', 'archived'], 'void' => ['void'], 'all' => []][$v] ?? ['issued', 'partial'];
    $rows = fin_docs($pdo, ['dir' => 'in', 'st' => $st, 'q' => $q, 'order' => 'COALESCE(f.due_date, f.issue_date) ' . ($v === 'paid' || $v === 'all' ? 'DESC' : 'ASC') . ', f.id DESC']);
    $rows = array_values(array_filter($rows, function ($d) use ($v, $ag) {
        $od = max(0, fin_overdue_days($d));
        if ($v === 'late' && $od <= 0) return false;
        if ($ag !== '' && (!in_array($d['status'], ['issued', 'partial'], true) || fin_aging($od) !== $ag)) return false;
        return true;
    }));
    $legacy = in_array($v, ['open', 'late', 'all'], true) ? array_values(array_filter($recv, fn($r) => $r['legacy'] && ($ag === '' || $r['bucket'] === $ag) && ($v !== 'late' || $r['age'] > 0) && ($q === '' || stripos($r['code'] . ' ' . $r['name'], $q) !== false))) : [];
    $doc = $docId ? fin_doc($pdo, $docId) : null;
    $pg = paginate($rows, 'pr');
?>
<div class="fin-split<?= ($doc && $doc['direction'] === 'in') || ($dealQ && !$req && !$new) ? ' has-doc' : '' ?>">
  <div class="tablewrap"><table class="fin-t">
    <thead><tr><th>Nº</th><th>Cliente</th><th class="hide-sm">Negócio</th><th class="hide-sm">Vencimento</th><th class="num">Total</th><th class="num">Em falta</th><th>Estado</th></tr></thead>
    <tbody>
    <?php if (!$rows && !$legacy): ?><tr><td colspan="7" class="empty">Nada nesta vista.</td></tr><?php endif; ?>
    <?php foreach ($pg['rows'] as $d): $od = fin_overdue_days($d); $open = in_array($d['status'], ['issued', 'partial'], true); ?>
      <tr class="<?= $docId === (int)$d['id'] ? 'sel' : '' ?>">
        <td class="nowrap"><a class="lnk id" href="<?= e(fin_url(['tab' => 'receber', 'v' => $v !== 'open' ? $v : null, 'aging' => $ag, 'q' => $q, 'doc' => (int)$d['id']])) ?>"><?= e(fin_doc_label($d)) ?></a></td>
        <td><?= e(shorten((string)$d['party_name'], 34)) ?></td>
        <td class="hide-sm nowrap"><?= $d['deal_code'] ? e($d['deal_code']) : '<span class="muted">Empresa</span>' ?></td>
        <td class="hide-sm nowrap<?= $open && $od > 0 ? ' neg' : '' ?>"><?= fin_date_pt($d['due_date']) ?><?= $open && $od > 0 ? ' · ' . $od . ' d' : '' ?></td>
        <td class="num"><?= eur((float)$d['total']) ?></td><td class="num b"><?= in_array($d['status'], ['void'], true) ? '—' : eur(fin_doc_left($d)) ?></td>
        <td><?= fin_chip((string)$d['status']) ?><?= (int)$d['needs_review'] ? ' <span class="chip info" data-tip="Os valores lidos não batem com o pedido: confirme">!</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($legacy as $r): ?>
      <tr class="<?= (int)($_GET['deal'] ?? 0) === $r['id'] && !$req && !$new ? 'sel' : '' ?>"><td class="nowrap"><a class="lnk id" href="<?= e($r['url']) ?>"><?= e($r['code']) ?></a></td><td><?= e(shorten((string)$r['contact_name'], 34)) ?></td>
        <td class="hide-sm"><?= e(shorten($r['name'], 28)) ?></td><td class="hide-sm nowrap<?= $r['age'] > 0 ? ' neg' : '' ?>"><?= fin_date_pt($r['ref']) ?><?= $r['age'] > 0 ? ' · ' . $r['age'] . ' d' : '' ?></td>
        <td class="num"><?= eur($r['gross']) ?></td><td class="num b"><?= eur($r['gross']) ?></td><td><?= fin_chip('legacy') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <div class="doc-wrap">
  <?php if ($doc && $doc['direction'] === 'in'): ?>
    <?= fin_doc_panel($pdo, $doc, $canEdit, fin_url(['tab' => 'receber', 'doc' => (int)$doc['id']])) ?>
  <?php elseif ($dealQ && !$req && !$new && ($bd = billing_deal($pdo, $dealQ))): ?>
    <?= billing_doc($pdo, $bd, $rate) ?>
    <div class="doc-actions"><?php if ($canEdit): ?>
      <a class="btn sm" href="<?= e(fin_url(['tab' => 'receber', 'request' => 1, 'deal' => $dealQ])) ?>"><?= icon('send') ?> Pedir fatura</a>
      <a class="btn ghost sm" href="<?= e(fin_url(['tab' => 'receber', 'new' => 1, 'deal' => $dealQ])) ?>">Lançar a fatura emitida</a><?php endif; ?>
      <a class="btn ghost sm" href="/admin/documento.php?id=<?= $dealQ ?>" target="_blank" rel="noopener"><?= icon('external') ?> Resumo para imprimir</a></div>
    <p class="small muted">Negócio de antes das faturas: lance a fatura (ou peça-a) para seguir o circuito normal até «Paga».</p>
  <?php else: ?>
    <p class="muted small fin-hint">Escolha uma fatura para ver os pagamentos, enviar ao cliente, registar o comprovativo ou pedir o recibo.</p>
  <?php endif; ?>
  </div>
</div>
<?= page_nav($pg) ?>

<?php
// ═══ A pagar ═══════════════════════════════════════════════
elseif ($tab === 'pagar'):
    $docId = (int)($_GET['doc'] ?? 0);
    $editId = (int)($_GET['edit'] ?? 0);
    $new = !empty($_GET['new']);
    $v = (string)($_GET['v'] ?? 'open');
    $q = trim((string)($_GET['q'] ?? ''));
    $nRev = (int)$pdo->query("SELECT COUNT(*) FROM fin_docs WHERE direction = 'out' AND status = 'review' AND deleted_at IS NULL")->fetchColumn();
    $views = ['open' => 'Em aberto', 'week' => 'A pagar esta semana', 'late' => 'Vencidas', 'month' => 'Próximos 30 dias', 'review' => 'Por confirmar' . ($nRev ? ' · ' . $nRev : ''), 'dispute' => 'Em disputa', 'paid' => 'Pagas', 'all' => 'Todas'];
?>
<?php if (($new || $editId) && $canEdit):
    $d = $editId ? fin_doc($pdo, $editId) : null;
    $vals = $form && ($form['direction'] ?? '') === 'out' ? $form : ($d ?? ['direction' => 'out', 'vat_rate' => fin_p($pdo, 'vat_normal', 23), 'issue_date' => today_lisbon(), 'vat_deductible' => 1]);
    if ($d && $d['direction'] === 'in') { $d = null; }
?>
<section class="panel"><h2><?= $d ? ($d['status'] === 'review' ? 'Confirmar ' : 'Editar ') . e(fin_doc_label($d)) : 'Lançar despesa ou fatura recebida' ?></h2>
  <p class="small muted">Pagamentos a fornecedores e freelancers têm sempre âmbito: Empresa (interno) ou o negócio — e, se for de um negócio, entram nos custos reais dele.</p>
  <?= fin_doc_form($pdo, 'out', $vals, fin_url(['tab' => 'pagar']), (bool)$d) ?></section>
<?php endif; ?>
<form class="bar fin-bar" method="get"><input type="hidden" name="tab" value="pagar">
  <?= views_bar($views, $v, fn($k) => fin_url(['tab' => 'pagar', 'v' => $k !== 'open' ? $k : null, 'q' => $q])) ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Procurar fornecedor, nº, NIF, negócio" aria-label="Procurar"><input type="hidden" name="v" value="<?= e($v) ?>">
</form>
<?php
    $today = today_lisbon();
    $st = ['open' => ['to_pay', 'partial', 'dispute'], 'week' => ['to_pay', 'partial'], 'late' => ['to_pay', 'partial'], 'month' => ['to_pay', 'partial'], 'review' => ['review'], 'dispute' => ['dispute'], 'paid' => ['paid', 'archived'], 'all' => []][$v] ?? ['to_pay', 'partial'];
    $rows = fin_docs($pdo, ['dir' => 'out', 'st' => $st, 'q' => $q, 'order' => 'COALESCE(f.due_date, f.issue_date) ' . (in_array($v, ['paid', 'all'], true) ? 'DESC' : 'ASC') . ', f.id DESC']);
    $weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($today)));
    $rows = array_values(array_filter($rows, function ($d) use ($v, $today, $weekEnd) {
        if ($v === 'week') return $d['due_date'] && $d['due_date'] >= $today && $d['due_date'] <= $weekEnd;
        if ($v === 'late') return $d['due_date'] && $d['due_date'] < $today;
        if ($v === 'month') return $d['due_date'] && $d['due_date'] <= date('Y-m-d', strtotime($today . ' +30 days'));
        return true;
    }));
    $sum = array_sum(array_map(fn($d) => in_array($d['status'], ['void'], true) ? 0 : fin_doc_left($d), $rows));
    $doc = $docId ? fin_doc($pdo, $docId) : null;
    $pg = paginate($rows, 'pp');
?>
<p class="small muted"><?= count($rows) ?> nesta vista · em falta <b><?= eur($sum) ?></b></p>
<div class="fin-split<?= $doc && $doc['direction'] === 'out' ? ' has-doc' : '' ?>">
  <div class="tablewrap"><table class="fin-t">
    <thead><tr><th>Fornecedor</th><th class="hide-sm hide-md">Nº</th><th class="hide-sm hide-md">Categoria</th><th>Âmbito</th><th class="hide-sm">Vencimento</th><th class="num">Total</th><th class="num">Em falta</th><th>Estado</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty">Nada nesta vista.</td></tr><?php endif; ?>
    <?php foreach ($pg['rows'] as $d): $od = $d['due_date'] ? fin_overdue_days($d) : 0; $open = in_array($d['status'], ['to_pay', 'partial'], true); ?>
      <tr class="<?= $docId === (int)$d['id'] ? 'sel' : '' ?>">
        <td><a class="lnk" href="<?= e(fin_url(['tab' => 'pagar', 'v' => $v !== 'open' ? $v : null, 'q' => $q, 'doc' => (int)$d['id']])) ?>"><?= e(shorten((string)$d['party_name'] ?: $d['description'], 36)) ?></a>
          <?= $d['party_kind'] === 'freelancer' ? ' <span class="chip">freelancer</span>' : '' ?><?= $d['payroll_id'] ? ' <span class="chip">salários</span>' : '' ?><?= $d['fixed_id'] ? ' <span class="chip">fixo</span>' : '' ?></td>
        <td class="hide-sm hide-md nowrap"><?= e($d['number'] ?: '—') ?></td><td class="hide-sm hide-md"><?= e($d['category']) ?></td>
        <td class="nowrap"><?= $d['deal_code'] ? '<a class="lnk" href="/admin/negocio.php?id=' . (int)$d['deal_id'] . '">' . e($d['deal_code']) . '</a>' : '<span class="muted">Empresa</span>' ?></td>
        <td class="hide-sm nowrap<?= $open && $od > 0 ? ' neg' : '' ?>"><?= fin_date_pt($d['due_date']) ?></td>
        <td class="num"><?= eur((float)$d['total']) ?></td><td class="num b"><?= $d['status'] === 'void' ? '—' : eur(fin_doc_left($d)) ?></td>
        <td><?= fin_chip((string)$d['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <div class="doc-wrap">
  <?php if ($doc && $doc['direction'] === 'out'): ?>
    <?php if ($doc['status'] === 'review' && $canEdit): ?><div class="flash info">Por confirmar: <a href="<?= e(fin_url(['tab' => 'pagar', 'edit' => (int)$doc['id']])) ?>">rever os dados</a> antes de entrar nos pagamentos.</div><?php endif; ?>
    <?= fin_doc_panel($pdo, $doc, $canEdit, fin_url(['tab' => 'pagar', 'doc' => (int)$doc['id']])) ?>
    <?php if ($canManage): ?><form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="doc_del"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><button class="btn ghost sm" type="submit">Apagar o lançamento</button></form><?php endif; ?>
  <?php else: ?><p class="muted small fin-hint">Escolha um lançamento para registar o pagamento (com comprovativo), pôr em disputa ou juntar o recibo.</p><?php endif; ?>
  </div>
</div>
<?= page_nav($pg) ?>

<?php
// ═══ Custos fixos ══════════════════════════════════════════
elseif ($tab === 'fixos'):
    require_once __DIR__ . '/fin_pay.php';
    $fx = fin_fixed_total($pdo);
    $list = fin_fixed_list($pdo);
    $editId = (int)($_GET['edit'] ?? 0);
    $ef = null; foreach ($list as $f) if ((int)$f['id'] === $editId) $ef = $f;
    $changes = fin_fixed_changes($pdo);
    $cats = fin_categories($pdo);
?>
<div class="fin-grid">
  <section class="flow"><h3>Total mensal · <?= eur0($fx['total']) ?></h3>
    <p class="small muted">Custos fixos <?= eur0($fx['fixed']) ?>/mês + salários (custo da empresa) <?= eur0($fx['salaries']) ?>/mês. É a base do runway.</p>
    <?= $fx['by_cat'] ? chart_hbars($fx['by_cat']) : '' ?></section>
  <section class="flow"><h3>Mudanças de mês para mês</h3>
    <?php if (!$changes): ?><p class="muted small">Sem mudanças nos últimos 6 meses.</p><?php endif; ?>
    <ul class="fin-changes"><?php foreach ($changes as $c): ?><li class="<?= $c['delta'] > 0 ? 'up' : 'down' ?>"><?= e($c['text']) ?></li><?php endforeach; ?></ul></section>
</div>
<?php if ($canEdit): ?><div class="bar fin-bar"><button type="button" class="btn sm" data-new="#mdl-fixo" aria-keyshortcuts="N"><?= icon('plus') ?> Novo custo fixo <span class="kbd">N</span></button></div><?php endif; ?>
<div class="tablewrap"><table class="fin-t">
  <thead><tr><th>Custo</th><th>Categoria</th><th class="hide-sm">Fornecedor</th><th class="num">Valor</th><th class="hide-sm">Periodicidade</th><th class="num">Por mês</th><th class="hide-sm">Início · fim</th><th></th></tr></thead>
  <tbody>
  <?php if (!$list && !$fx['salaries']): ?><tr><td colspan="8" class="empty">Sem custos fixos.</td></tr><?php endif; ?>
  <?php foreach ($list as $f): ?>
    <tr class="<?= (int)$f['active'] ? '' : 'off' ?><?= $editId === (int)$f['id'] ? ' pending' : '' ?>"><td class="b"><?= e($f['name']) ?></td><td><?= e($f['category']) ?></td><td class="hide-sm"><?= e($f['party_name']) ?></td>
      <td class="num"><?= eur((float)$f['amount']) ?><?= (float)$f['vat_rate'] > 0 ? '<div class="muted small">+ IVA ' . e(fin_num((float)$f['vat_rate'])) . '%' . ((int)$f['vat_deductible'] ? '' : ' (não dedutível)') . '</div>' : '' ?></td>
      <td class="hide-sm"><?= e(FIN_PERIODS[(int)$f['period_months']] ?? '') ?> · dia <?= (int)$f['due_day'] ?></td><td class="num b"><?= eur(fin_fixed_monthly($f)) ?></td>
      <td class="hide-sm nowrap"><?= fin_date_pt($f['start_date']) ?><?= $f['end_date'] ? ' → ' . fin_date_pt($f['end_date']) : '' ?></td>
      <td class="num nowrap"><?php if ($canEdit): ?><a class="lnk" href="<?= e(fin_url(['tab' => 'fixos', 'edit' => (int)$f['id']])) ?>">Editar</a>
        <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="fixed_toggle"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn ghost sm" type="submit"><?= (int)$f['active'] ? 'Desligar' : 'Ligar' ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php foreach (fin_employees($pdo) as $e): if (!(int)$e['active']) continue; $a = fin_employee_annual($pdo, $e); ?>
    <tr class="auto"><td class="b">Salário · <?= e($e['name']) ?></td><td>Salários</td><td class="hide-sm muted">automático</td><td class="num"><?= eur((float)$e['base_salary']) ?><div class="muted small">bruto</div></td>
      <td class="hide-sm">14 meses ÷ 12</td><td class="num b"><?= eur($a['monthly']) ?></td><td class="hide-sm"><?= fin_date_pt($e['start_date']) ?></td><td class="num"><a class="lnk" href="<?= e(fin_url(['tab' => 'salarios', 'emp' => (int)$e['user_id']])) ?>">Ficha</a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php if ($canEdit): $fv = $ef ?? ['name' => '', 'category' => 'Software', 'party_name' => '', 'party_nif' => '', 'amount' => '', 'vat_rate' => fin_p($pdo, 'vat_normal', 23), 'vat_deductible' => 1, 'wh_rate' => 0, 'period_months' => 1, 'due_day' => 1, 'start_date' => date('Y-m-01'), 'end_date' => '', 'pay_method' => 'transfer', 'active' => 1, 'notes' => '']; ?>
<div class="mdl<?= $ef ? ' open' : '' ?>" id="mdl-fixo" role="dialog" aria-modal="true" aria-labelledby="mdl-fixo-t"<?= $ef ? '' : ' hidden' ?> data-clear="edit">
  <div class="mdl-back" data-mdl-close></div>
<form method="post" class="mdl-card wide fin-form"><?= csrf_field() ?><input type="hidden" name="do" value="fixed_save"><?= $ef ? '<input type="hidden" name="id" value="' . (int)$ef['id'] . '">' : '' ?>
  <h3 id="mdl-fixo-t"><?= icon('plus') ?> <?= $ef ? 'Editar custo fixo · ' . e($ef['name']) : 'Novo custo fixo' ?></h3>
  <div class="grid">
    <div class="field"><label for="fx-n">Custo</label><input type="text" id="fx-n" name="name" required maxlength="160" value="<?= e((string)$fv['name']) ?>" placeholder="Adobe Creative Cloud"></div>
    <div class="field"><label for="fx-c">Categoria</label><select id="fx-c" name="category"><?= fin_opts(array_combine($cats, $cats), $fv['category']) ?></select></div>
    <div class="field"><label for="fx-p">Fornecedor</label><input type="text" id="fx-p" name="party_name" maxlength="190" value="<?= e((string)$fv['party_name']) ?>"></div>
    <div class="field"><label for="fx-nif">NIF</label><input type="text" id="fx-nif" name="party_nif" maxlength="20" value="<?= e((string)$fv['party_nif']) ?>"></div>
    <div class="field"><label for="fx-a">Valor s/ IVA (€)</label><input type="text" id="fx-a" name="amount" required inputmode="decimal" value="<?= $fv['amount'] !== '' ? e(number_format((float)$fv['amount'], 2, ',', '')) : '' ?>"></div>
    <div class="field"><label for="fx-r">IVA</label><select id="fx-r" name="vat_rate"><?= fin_opts([(string)fin_p($pdo, 'vat_normal', 23) => fin_num(fin_p($pdo, 'vat_normal', 23)) . '%', (string)fin_p($pdo, 'vat_intermediate', 13) => fin_num(fin_p($pdo, 'vat_intermediate', 13)) . '%', (string)fin_p($pdo, 'vat_reduced', 6) => fin_num(fin_p($pdo, 'vat_reduced', 6)) . '%', '0' => 'Sem IVA'], (string)(float)$fv['vat_rate']) ?></select></div>
    <div class="field"><label class="check" style="margin-top:1.6rem"><input type="checkbox" name="vat_deductible" value="1"<?= (int)$fv['vat_deductible'] ? ' checked' : '' ?>> IVA dedutível</label></div>
    <div class="field"><label for="fx-wh">Retenção na fonte</label><select id="fx-wh" name="wh_rate"><?= fin_opts(['0' => 'Sem retenção', (string)fin_p($pdo, 'wh_rent', 25) => fin_num(fin_p($pdo, 'wh_rent', 25)) . '% (renda)', (string)fin_p($pdo, 'wh_cat_b', 23) => fin_num(fin_p($pdo, 'wh_cat_b', 23)) . '% (freelancer)'], (string)(float)$fv['wh_rate']) ?></select></div>
    <div class="field"><label for="fx-pe">Periodicidade</label><select id="fx-pe" name="period_months"><?= fin_opts(FIN_PERIODS, (int)$fv['period_months']) ?></select></div>
    <div class="field"><label for="fx-d">Dia do vencimento</label><input type="number" id="fx-d" name="due_day" min="1" max="28" value="<?= (int)$fv['due_day'] ?>"></div>
    <div class="field"><label for="fx-s">Início</label><input type="date" id="fx-s" name="start_date" required value="<?= e((string)$fv['start_date']) ?>"></div>
    <div class="field"><label for="fx-e">Fim (opcional)</label><input type="date" id="fx-e" name="end_date" value="<?= e((string)$fv['end_date']) ?>"></div>
    <div class="field"><label for="fx-m">Pagamento</label><select id="fx-m" name="pay_method"><?= fin_opts(FIN_METHODS, $fv['pay_method']) ?></select></div>
    <div class="field wide"><label for="fx-no">Notas</label><input type="text" id="fx-no" name="notes" maxlength="500" value="<?= e((string)$fv['notes']) ?>"></div>
  </div>
  <p class="small muted">Âmbito: sempre Empresa (interno). Gera o lançamento «Por pagar» em cada vencimento (junte o PDF quando chegar).</p>
  <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit"><?= $ef ? 'Guardar' : 'Adicionar' ?></button></div></form>
</div>
<?php endif; ?>

<?php
// ═══ Salários ══════════════════════════════════════════════
elseif ($tab === 'salarios'):
    require_once __DIR__ . '/fin_pay.php';
    $ym = (string)($_GET['ym'] ?? date('Y-m', strtotime(today_lisbon())));
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $mm)) $ym = date('Y-m');
    [$py, $pm] = [(int)substr($ym, 0, 4), (int)substr($ym, 5, 2)];
    $emps = fin_employees($pdo);
    $have = array_column($emps, null, 'user_id');
    $issued = [];
    foreach (fin_payroll_list($pdo, $py, $pm) as $p) if (!$p['cancelled_at']) $issued[(int)$p['user_id']] = $p;
    $others = $pdo->query("SELECT id, name, role FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
    $empId = (int)($_GET['emp'] ?? 0);
    $prev = (int)($_GET['prev'] ?? 0);
    $months = [];
    for ($i = -12; $i <= 2; $i++) { $d = date('Y-m', strtotime(date('Y-m-01') . " $i month")); $months[$d] = month_year((int)substr($d, 5, 2), (int)substr($d, 0, 4)); }
?>
<form class="bar" method="get"><input type="hidden" name="tab" value="salarios">
  <label for="sal-ym" class="muted small">Mês</label><select id="sal-ym" name="ym" onchange="this.form.submit()"><?= fin_opts(array_reverse($months, true), $ym) ?></select>
  <noscript><button class="btn sm">Ver</button></noscript>
  <a class="btn ghost sm" href="<?= e('/admin/fin_file.php?export=payroll&ym=' . $ym) ?>"><?= icon('download') ?> Resumo para o contabilista (CSV)</a>
</form>
<?php if ($empId && $canEdit && ($eu = array_values(array_filter($others, fn($o) => (int)$o['id'] === $empId))[0] ?? null)): ?>
<section class="panel" id="ficha"><h2>Ficha de salário · <?= e($eu['name']) ?></h2>
  <?= fin_emp_summary($pdo, $have[$empId] ?? null) ?>
  <?= fin_emp_form($pdo, $empId, $have[$empId] ?? null, fin_url(['tab' => 'salarios', 'ym' => $ym, 'emp' => $empId]) . '#ficha') ?></section>
<?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="payroll_issue"><input type="hidden" name="ym" value="<?= e($ym) ?>"><input type="hidden" name="back" value="<?= e(fin_url(['tab' => 'salarios', 'ym' => $ym])) ?>">
<div class="tablewrap"><table class="fin-t">
  <thead><tr><th><span class="sr">Escolher</span></th><th>Colaborador</th><th class="num">Bruto</th><th class="num hide-sm">IRS</th><th class="num hide-sm">SS (11%)</th><th class="num">Líquido</th><th class="num">Custo empresa</th><th>Estado</th><th></th></tr></thead>
  <tbody>
  <?php if (!$emps): ?><tr><td colspan="9" class="empty">Ninguém tem ainda ficha de salário. Crie-a abaixo (ou na ficha do colaborador, em Equipa).</td></tr><?php endif; ?>
  <?php foreach ($emps as $e): $p = $issued[(int)$e['user_id']] ?? null;
        try { $c = $p ? null : fin_payroll_calc($pdo, $e, $py, $pm); $cerr = ''; } catch (RuntimeException $ex) { $c = null; $cerr = $ex->getMessage(); }
        $w = fin_employee_warnings($pdo, $e); ?>
    <tr class="<?= (int)$e['active'] ? '' : 'off' ?>">
      <td><?php if (!$p && (int)$e['active'] && $canEdit && $c): ?><input type="checkbox" name="uids[]" value="<?= (int)$e['user_id'] ?>" aria-label="Emitir para <?= e($e['name']) ?>"<?= !$w ? ' checked' : '' ?>><?php endif; ?></td>
      <td><a class="lnk b" href="<?= e(fin_url(['tab' => 'salarios', 'ym' => $ym, 'emp' => (int)$e['user_id']])) ?>#ficha"><?= e($e['name']) ?></a><?= $w ? '<div class="small neg">' . e(implode(' ', $w)) . '</div>' : '' ?><?= $cerr ? '<div class="small muted">' . e($cerr) . '</div>' : '' ?></td>
      <?php $src = $p ?: ($c ? ['gross' => $c['gross'], 'irs' => $c['irs'], 'ss_employee' => $c['ss_employee'], 'net' => $c['net'], 'company_cost' => $c['company_cost']] : null); ?>
      <td class="num"><?= $src ? eur((float)$src['gross']) : '—' ?></td><td class="num hide-sm"><?= $src ? eur((float)$src['irs']) : '—' ?></td><td class="num hide-sm"><?= $src ? eur((float)$src['ss_employee']) : '—' ?></td>
      <td class="num b"><?= $src ? eur((float)$src['net']) : '—' ?></td><td class="num"><?= $src ? eur((float)$src['company_cost']) : '—' ?></td>
      <td><?= $p ? '<span class="chip ok">Emitido</span>' . ($p['emailed_at'] ? ' <span class="chip info" data-tip="Enviado por email">✉</span>' : '') : '<span class="chip wait">Por emitir</span>' ?></td>
      <td class="num nowrap"><?php if ($p): ?><a class="lnk" href="<?= e(fin_file_href('payslip', (int)$p['id'])) ?>" target="_blank" rel="noopener">Recibo</a>
        <?php elseif ($c): ?><a class="lnk" href="<?= e(fin_url(['tab' => 'salarios', 'ym' => $ym, 'prev' => (int)$e['user_id']])) ?>#calc">Ver cálculo</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php if ($canEdit && $emps): ?><div class="actions"><button class="btn" type="submit">Emitir <?= e(month_year($pm, $py)) ?> para os escolhidos</button>
  <span class="small muted">Gera o recibo em PDF (enviado à pessoa e no Perfil dela) e os lançamentos: líquido, IRS retido e Segurança Social.</span></div><?php endif; ?>
</form>
<?php if ($issued && $canEdit): ?>
<details class="panel"><summary>Anular um processamento de <?= e(month_year($pm, $py)) ?></summary>
  <?php foreach ($issued as $p): ?><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="payroll_cancel"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="back" value="<?= e(fin_url(['tab' => 'salarios', 'ym' => $ym])) ?>">
    <button class="btn ghost sm" type="submit">Anular · <?= e($p['name']) ?></button></form> <?php endforeach; ?></details>
<?php endif; ?>
<?php if ($prev && isset($have[$prev])): try { $c = fin_payroll_calc($pdo, $have[$prev], $py, $pm); } catch (RuntimeException $ex) { $c = null; } if ($c): ?>
<section class="panel" id="calc"><h2>Cálculo · <?= e($have[$prev]['name']) ?> · <?= e(month_year($pm, $py)) ?></h2>
  <div class="tablewrap"><table class="fin-calc"><thead><tr><th></th><th class="num">Colaborador</th><th class="num">Empresa</th></tr></thead><tbody>
    <?php foreach ($c['lines'] as [$a, $val, $n]): ?><tr><td><?= e($a) ?><?= $n ? ' <span class="muted small">' . e($n) . '</span>' : '' ?></td><td class="num"><?= eur((float)$val) ?></td><td class="num"><?= eur((float)$val) ?></td></tr><?php endforeach; ?>
    <tr><td>Segurança Social <span class="muted small">sobre <?= eur($c['ss_base']) ?></span></td><td class="num">−<?= eur($c['ss_employee']) ?> <span class="muted small">(<?= e(fin_num($c['rates']['ss_employee'])) ?>%)</span></td><td class="num">+<?= eur($c['ss_employer']) ?> <span class="muted small">(TSU <?= e(fin_num($c['rates']['ss_employer'])) ?>%)</span></td></tr>
    <?php foreach ($c['irs_lines'] as [$a, $base, $calc]): ?><tr><td>IRS retido · <?= e($a) ?> <span class="muted small">tabela <?= e($c['table']) ?> (<?= (int)$c['irs_year'] ?>) · <?= eur((float)$base) ?> × <?= e(fin_num((float)$calc['rate'])) ?>%<?= (float)$calc['deduct'] ? ' − ' . eur((float)$calc['deduct']) : '' ?><?= (float)$calc['dep'] ? ' − ' . eur((float)$calc['dep']) . ' (dependentes)' : '' ?></span></td><td class="num">−<?= eur((float)$calc['value']) ?></td><td></td></tr><?php endforeach; ?>
    <tr><td>Seguro de acidentes de trabalho e FCT</td><td></td><td class="num">+<?= eur($c['insurance'] + $c['fct']) ?> <span class="muted small">(<?= e(fin_num($c['rates']['ins'])) ?>% + <?= e(fin_num($c['rates']['fct'])) ?>%)</span></td></tr>
    <tr class="tot"><td><b>Total</b></td><td class="num"><b><?= eur($c['net']) ?></b> <span class="muted small">líquido a receber</span></td><td class="num"><b><?= eur($c['company_cost']) ?></b> <span class="muted small">custo total</span></td></tr>
  </tbody></table></div>
  <?php foreach ($c['warnings'] as $w): ?><p class="small neg"><?= e($w) ?></p><?php endforeach; ?>
  <p class="small muted">Taxas e tabelas em Definições › Financeiro (com a fonte legal). Confirme o processamento com o contabilista.</p></section>
<?php endif; endif; ?>
<?php if ($canEdit): $without = array_filter($others, fn($o) => !isset($have[(int)$o['id']])); if ($without): ?>
<form class="bar" method="get"><input type="hidden" name="tab" value="salarios"><input type="hidden" name="ym" value="<?= e($ym) ?>">
  <label for="sal-new" class="muted small">Criar ficha de salário para</label><select id="sal-new" name="emp"><?php foreach ($without as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?></select>
  <button class="btn ghost sm" type="submit">Abrir ficha</button></form>
<?php endif; endif; ?>

<?php
// ═══ Tesouraria ════════════════════════════════════════════
elseif ($tab === 'tesouraria'):
    $cash = fin_cash($pdo);
    $accs = fin_accounts($pdo);
    $editAcc = (int)($_GET['acc'] ?? 0);
    $ea = null; foreach ($accs as $a) if ((int)$a['id'] === $editAcc) $ea = $a;
    $open = fin_txs($pdo, 'open');
    $fc = fin_forecast($pdo, (int)($_GET['h'] ?? 6) === 12 ? 12 : 6);
    $cats = fin_categories($pdo);
    $isAdmin = role_is_admin((string)$user['role']);
    $week = fin_balance_week($pdo);
    $people = $pdo->query('SELECT id, name, role, is_partner FROM users WHERE is_active = 1 ORDER BY is_partner DESC, name')->fetchAll();
    $names = array_column($people, 'name', 'id');
    $finPeople = array_filter($people, fn($u) => (perm_grid($pdo, $u)['financeiro']['level'] ?? PERM_NONE) >= PERM_VIEW);
    $defOwner = fin_cash_owner_id($pdo);
    $myAccs = array_values(array_filter($accs, fn($a) => (int)$a['active'] && fin_can_balance($pdo, $a, $user)));
?>
<div class="stats">
  <div class="stat"><div class="k">Nas contas</div><div class="v"><?= eur0($cash['accounts']) ?></div><div class="n"><?= $cash['n_accounts'] ?> <?= $cash['n_accounts'] === 1 ? 'conta' : 'contas' ?><?= $cash['stale'] ? ' · <span class="stale">' . $cash['stale'] . ' por atualizar</span>' : ($cash['balance_date'] ? ' · saldos de ' . fin_date_pt($cash['balance_date']) : '') ?></div></div>
  <div class="stat"><div class="k">Compromissos até ao fim do mês</div><div class="v"><?= eur0($cash['commit']) ?></div><div class="n">faturas <?= eur0($cash['parts']['payables']) ?> · salários, IRS e SS <?= eur0($cash['parts']['payroll']) ?> · IVA <?= eur0($cash['parts']['vat']) ?></div></div>
  <div class="stat"><div class="k">Reservado aos sócios</div><div class="v"><?= eur0($cash['reserved']) ?></div><div class="n">não conta no disponível nem no runway</div></div>
  <div class="stat hero<?= $cash['available'] < 0 ? ' neg' : '' ?>"><div class="k">Saldo disponível</div><div class="v"><?= eur0($cash['available']) ?></div><div class="n">contas − compromissos − reservas</div></div>
</div>
<div class="bar fin-bar fin-hbar">
  <h2>Contas</h2>
  <?php if ($canEdit): ?><span class="spacer"></span>
  <button type="button" class="btn sm" data-new="#mdl-acc" aria-keyshortcuts="N"><?= icon('plus') ?> Nova conta <span class="kbd">N</span></button>
  <?php if ($accs): ?><button type="button" class="btn ghost sm" data-new="#mdl-import"><?= icon('upload') ?> Importar extrato</button><?php endif; ?><?php endif; ?>
</div>
<p class="small muted">Os saldos atualizam-se uma vez por semana, à <?= e(FIN_WEEKDAYS[$week['day']]) ?>: <?= $defOwner ? e($names[$defOwner] ?? '—') : 'o responsável financeiro' ?> recebe a tarefa (cada conta pode ter um responsável próprio). Dia e responsável em Definições › Financeiro.</p>
<div class="tablewrap"><table class="fin-t fin-acc"><thead><tr><th>Conta</th><th class="hide-sm">IBAN</th><th class="num">Saldo</th><th>Atualizado</th><th class="hide-sm">Responsável</th><th class="num hide-sm">Por conciliar</th><th></th></tr></thead><tbody>
  <?php if (!$accs): ?><tr><td colspan="7" class="empty">Sem contas. Junte o banco, os cartões e a caixa.</td></tr><?php endif; ?>
  <?php foreach ($accs as $a): $age = fin_balance_age($a); $st = (int)$a['active'] && fin_balance_stale($a); $own = fin_cash_owner_id($pdo, $a); ?>
  <tr class="<?= (int)$a['active'] ? '' : 'off' ?>"><td class="b"><?= e($a['name']) ?> <span class="chip"><?= e(FIN_ACC_KINDS[$a['kind']] ?? '') ?></span><?= (int)$a['active'] ? '' : ' <span class="chip">inativa</span>' ?></td><td class="hide-sm mono small"><?= e($a['iban']) ?></td>
    <td class="num b<?= (float)$a['balance'] < 0 ? ' neg' : '' ?>"><?= $a['balance_date'] ? eur((float)$a['balance']) : '—' ?></td>
    <td class="nowrap"><?= $a['balance_date'] ? '<span class="hide-sm">' . fin_date_pt($a['balance_date']) . ' </span>' : '' ?><span class="chip<?= $st ? ' wait' : '' ?>"><?= e(fin_age_label($age)) ?></span></td>
    <td class="hide-sm small"><?= $own ? e($names[$own] ?? '—') : '—' ?><?= $a['owner_id'] ? '' : ' <span class="muted">(geral)</span>' ?></td>
    <td class="num hide-sm"><?= (int)$a['open_tx'] ?: '—' ?></td>
    <td class="num nowrap"><?php if ((int)$a['active'] && fin_can_balance($pdo, $a, $user)): ?><button type="button" class="btn <?= $st ? '' : 'ghost ' ?>sm" data-new="#mdl-bal-<?= (int)$a['id'] ?>">Atualizar<span class="hide-sm"> saldo</span></button><?php endif; ?>
      <?php if ($canEdit): ?><a class="lnk" href="<?= e(fin_url(['tab' => 'tesouraria', 'acc' => (int)$a['id']])) ?>">Editar</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
</tbody></table></div>
<?php foreach ($myAccs as $a): $log = fin_balance_log($pdo, (int)$a['id']); ?>
<div class="mdl" id="mdl-bal-<?= (int)$a['id'] ?>" role="dialog" aria-modal="true" aria-labelledby="mdl-bal-<?= (int)$a['id'] ?>-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form method="post" class="mdl-card fin-form"><?= csrf_field() ?><input type="hidden" name="do" value="acc_balance"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
    <h3 id="mdl-bal-<?= (int)$a['id'] ?>-t">Atualizar saldo · <?= e($a['name']) ?></h3>
    <p class="small muted">O saldo que o banco mostra agora (ou no dia indicado). <?= $a['balance_date'] ? 'Último: ' . eur((float)$a['balance']) . ' em ' . fin_date_pt($a['balance_date']) . '.' : 'Ainda sem saldo.' ?></p>
    <div class="grid"><div class="field"><label for="bl-v-<?= (int)$a['id'] ?>">Saldo (€)</label><input type="text" id="bl-v-<?= (int)$a['id'] ?>" name="balance" inputmode="decimal" required placeholder="0,00"></div>
      <div class="field"><label for="bl-d-<?= (int)$a['id'] ?>">Saldo em</label><input type="date" id="bl-d-<?= (int)$a['id'] ?>" name="balance_date" value="<?= e(today_lisbon()) ?>" max="<?= e(today_lisbon()) ?>" required></div></div>
    <?php if ($log): ?><details class="small"><summary>Últimas atualizações</summary><ul class="fin-log"><?php foreach ($log as $l): ?><li><?= fin_date_pt($l['balance_date']) ?> · <b><?= eur((float)$l['balance']) ?></b> <span class="muted">· <?= e((string)($l['who'] ?? '—')) ?>, <?= e(fin_date_pt(substr((string)$l['at'], 0, 10))) ?></span></li><?php endforeach; ?></ul></details><?php endif; ?>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">Guardar saldo</button></div>
  </form>
</div>
<?php endforeach; ?>
<?php if ($canEdit): $av = $ea ?? ['name' => '', 'kind' => 'bank', 'iban' => '', 'active' => 1, 'owner_id' => null]; ?>
<div class="mdl<?= $ea ? ' open' : '' ?>" id="mdl-acc" role="dialog" aria-modal="true" aria-labelledby="mdl-acc-t"<?= $ea ? '' : ' hidden' ?> data-clear="acc">
  <div class="mdl-back" data-mdl-close></div>
  <form method="post" class="mdl-card fin-form"><?= csrf_field() ?><input type="hidden" name="do" value="acc_save"><?= $ea ? '<input type="hidden" name="id" value="' . (int)$ea['id'] . '">' : '' ?>
    <h3 id="mdl-acc-t"><?= $ea ? 'Editar conta · ' . e($ea['name']) : 'Nova conta' ?></h3>
    <div class="grid"><div class="field wide"><label for="ac-n">Nome</label><input type="text" id="ac-n" name="name" required maxlength="120" value="<?= e((string)$av['name']) ?>" placeholder="Banco · conta à ordem"></div>
      <div class="field"><label for="ac-k">Tipo</label><select id="ac-k" name="kind"><?= fin_opts(FIN_ACC_KINDS, $av['kind']) ?></select></div>
      <div class="field"><label for="ac-i">IBAN</label><input type="text" id="ac-i" name="iban" maxlength="40" value="<?= e((string)$av['iban']) ?>"></div>
      <?php if (!$ea): ?>
      <div class="field"><label for="ac-b">Saldo inicial (€)</label><input type="text" id="ac-b" name="balance" inputmode="decimal" placeholder="Opcional"></div>
      <div class="field"><label for="ac-d">Saldo em</label><input type="date" id="ac-d" name="balance_date" value="<?= e(today_lisbon()) ?>" max="<?= e(today_lisbon()) ?>"></div>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
      <div class="field wide"><label for="ac-o">Responsável pelo saldo</label><select id="ac-o" name="owner_id"><option value="">O geral (<?= e($names[(int)setting($pdo, 'fin_cash_owner', 0)] ?? 'responsável financeiro') ?>)</option>
        <?php foreach ($finPeople as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$av['owner_id'] === (int)$u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
        <p class="small muted">Recebe a tarefa semanal desta conta em vez do responsável geral. Só quem tem acesso ao Financeiro.</p></div>
      <?php endif; ?>
      <div class="field"><input type="hidden" name="active" value="0"><label class="check"><input type="checkbox" name="active" value="1"<?= (int)$av['active'] ? ' checked' : '' ?>> Ativa</label></div></div>
    <?php if ($ea): ?><p class="small muted">O saldo muda-se em «Atualizar saldo» (fica registado com a data e quem o atualizou).</p><?php endif; ?>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit"><?= $ea ? 'Guardar' : 'Criar conta' ?></button></div>
  </form>
</div>
<?php if ($accs): ?>
<div class="mdl" id="mdl-import" role="dialog" aria-modal="true" aria-labelledby="mdl-import-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form method="post" enctype="multipart/form-data" class="mdl-card fin-form"><?= csrf_field() ?><input type="hidden" name="do" value="import">
    <h3 id="mdl-import-t">Importar extrato</h3>
    <div class="field"><label for="im-a">Conta</label><select id="im-a" name="account"><?php foreach ($accs as $a) if ((int)$a['active']): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option><?php endif; ?></select></div>
    <div class="field"><label for="im-f">Ficheiro CSV ou OFX do banco</label><input type="file" id="im-f" name="statement" accept=".csv,.ofx,.qfx,.txt,text/csv" required></div>
    <p class="small muted">Os movimentos que já estavam não se repetem. Se o extrato trouxer o saldo, a conta fica com ele (conta como atualização da semana).</p>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit"><?= icon('upload') ?> Importar</button></div>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>

<h2 id="reservas">Reservas dos sócios</h2>
<?php if (!$isAdmin): ?>
<p class="small">Reservado aos sócios: <b><?= eur($cash['reserved']) ?></b> <span class="muted">— parte da faturação de alguns negócios guardada para os sócios. Não conta no saldo disponível nem no runway. O detalhe só o veem superadmin e admins.</span></p>
<?php else:
    $resB = fin_res_balances($pdo);
    $rules = fin_res_rules($pdo);
    $pays = fin_res_payouts($pdo, 30);
?>
<div class="bar fin-bar fin-hbar"><p class="small muted">Parte da faturação de um negócio guardada para um sócio: entra como custo do negócio (margem), acumula à medida que o cliente paga e sai do saldo disponível e do runway até ser levantada.</p>
  <span class="spacer"></span><button type="button" class="btn sm" data-new="#mdl-res"><?= icon('plus') ?> Nova reserva</button>
  <?php if ($resB): ?><button type="button" class="btn ghost sm" data-new="#mdl-payout">Registar levantamento</button><?php endif; ?></div>
<div class="tablewrap"><table class="fin-t"><thead><tr><th>Sócio</th><th class="num">Previsto</th><th class="num">Reservado</th><th class="num">Levantado</th><th class="num">Por levantar</th></tr></thead><tbody>
  <?php if (!$resB): ?><tr><td colspan="5" class="empty">Sem reservas.</td></tr><?php endif; ?>
  <?php foreach ($resB as $b): ?><tr><td class="b"><?= e($b['name']) ?> <span class="muted small">· <?= (int)$b['rules'] ?> <?= (int)$b['rules'] === 1 ? 'negócio' : 'negócios' ?></span></td><td class="num"><?= eur($b['planned']) ?></td><td class="num"><?= eur($b['accrued']) ?></td><td class="num"><?= eur($b['paid']) ?></td><td class="num b"><?= eur(max(0, $b['balance'])) ?></td></tr><?php endforeach; ?>
  <?php if (count($resB) > 1): ?><tr class="tot"><td>Total</td><td class="num"><?= eur(array_sum(array_column($resB, 'planned'))) ?></td><td class="num"><?= eur(array_sum(array_column($resB, 'accrued'))) ?></td><td class="num"><?= eur(array_sum(array_column($resB, 'paid'))) ?></td><td class="num b"><?= eur($cash['reserved']) ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($rules): ?>
<h3>Por negócio</h3>
<div class="tablewrap"><table class="fin-t"><thead><tr><th>Negócio</th><th>Sócio</th><th class="hide-sm">Regra</th><th class="num">Previsto</th><th class="num">Reservado</th><th></th></tr></thead><tbody>
  <?php foreach ($rules as $r): ?><tr><td><a class="lnk" href="/admin/negocio.php?id=<?= (int)$r['deal_id'] ?>#custos"><?= e($r['deal_code'] . ' · ' . shorten((string)$r['deal_name'], 40)) ?></a><?= $r['note'] !== '' ? '<div class="muted small">' . e($r['note']) . '</div>' : '' ?></td><td><?= e((string)$r['user_name']) ?></td>
    <td class="hide-sm small"><?= e(fin_res_rule_label($r)) ?></td><td class="num"><?= eur($r['planned']) ?></td><td class="num"><?= eur($r['accrued']) ?></td>
    <td class="num"><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="res_rule_del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn ghost sm" type="submit">Apagar</button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php if ($pays): ?>
<h3>Levantamentos</h3>
<div class="tablewrap"><table class="fin-t"><thead><tr><th>Data</th><th>Sócio</th><th class="num">Valor</th><th class="hide-sm">Conta</th><th>Comprovativo</th><th></th></tr></thead><tbody>
  <?php foreach ($pays as $p): ?><tr><td><?= fin_date_pt($p['paid_on']) ?></td><td><?= e((string)$p['user_name']) ?><?= $p['note'] !== '' ? '<div class="muted small">' . e($p['note']) . '</div>' : '' ?></td><td class="num b"><?= eur((float)$p['amount']) ?></td><td class="hide-sm"><?= e((string)($p['account_name'] ?? '—')) ?></td>
    <td><?= $p['proof_key'] !== '' ? '<a class="lnk" href="' . e(fin_file_href('payout', (int)$p['id'])) . '" target="_blank" rel="noopener">' . e(shorten((string)$p['proof_name'], 30)) . '</a>' : '—' ?></td>
    <td class="num"><form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="payout_del"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn ghost sm" type="submit">Anular</button></form></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<div class="mdl" id="mdl-res" role="dialog" aria-modal="true" aria-labelledby="mdl-res-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form method="post" class="mdl-card fin-form"><?= csrf_field() ?><input type="hidden" name="do" value="res_rule">
    <h3 id="mdl-res-t">Nova reserva</h3>
    <div class="grid"><div class="field wide"><label for="rs-d">Negócio</label><select id="rs-d" name="deal_id" required><?= fin_deal_options($pdo, null) ?></select></div>
      <div class="field wide"><label for="rs-u">Sócio</label><select id="rs-u" name="user_id" required><option value="">— escolher —</option><?php foreach ($people as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="rs-m">Como</label><select id="rs-m" name="mode"><?= fin_opts(FIN_RES_MODES, 'pct') ?></select></div>
      <div class="field"><label for="rs-v">Valor (% ou €)</label><input type="text" id="rs-v" name="value" inputmode="decimal" required placeholder="20"></div>
      <div class="field wide"><label for="rs-n">Nota</label><input type="text" id="rs-n" name="note" maxlength="200" placeholder="Opcional · ex.: salário da execução"></div></div>
    <p class="small muted">Em %: guarda essa parte de cada pagamento do cliente (sem IVA). Valor fixo: acumula na proporção do que o cliente já pagou do budget. Uma segunda reserva do mesmo sócio no mesmo negócio substitui a primeira.</p>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">Criar reserva</button></div>
  </form>
</div>
<?php if ($resB): ?>
<div class="mdl" id="mdl-payout" role="dialog" aria-modal="true" aria-labelledby="mdl-payout-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form method="post" enctype="multipart/form-data" class="mdl-card fin-form"><?= csrf_field() ?><input type="hidden" name="do" value="payout_add">
    <h3 id="mdl-payout-t">Registar levantamento</h3>
    <div class="grid"><div class="field wide"><label for="po-u">Sócio</label><select id="po-u" name="user_id" required><?php foreach ($resB as $b): ?><option value="<?= (int)$b['user_id'] ?>"><?= e($b['name']) ?> · por levantar <?= e(eur(max(0, $b['balance']))) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="po-a">Valor (€)</label><input type="text" id="po-a" name="amount" inputmode="decimal" required></div>
      <div class="field"><label for="po-d">Data</label><input type="date" id="po-d" name="paid_on" value="<?= e(today_lisbon()) ?>" max="<?= e(today_lisbon()) ?>" required></div>
      <div class="field"><label for="po-c">Da conta</label><select id="po-c" name="account_id"><option value="">—</option><?php foreach ($accs as $a) if ((int)$a['active']): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option><?php endif; ?></select></div>
      <div class="field wide"><label for="po-f">Comprovativo (PDF ou imagem)</label><input type="file" id="po-f" name="proof" accept="application/pdf,image/jpeg,image/png" required></div>
      <div class="field wide"><label for="po-n">Nota</label><input type="text" id="po-n" name="note" maxlength="255"></div></div>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span><button class="btn" type="submit">Registar</button></div>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>
<h2 id="conciliar">Conciliação <?= $open ? '· ' . count($open) . ' por conciliar' : '' ?></h2>
<?php if (!$open): ?><p class="muted small">Nada por conciliar.</p><?php endif; ?>
<div class="fin-tx">
<?php foreach (array_slice($open, 0, 60) as $t): $sug = fin_tx_suggest($pdo, $t, 3); ?>
  <div class="tx">
    <div class="tx-l"><span class="mono small"><?= fin_date_pt($t['tx_date']) ?></span> <b class="<?= (float)$t['amount'] < 0 ? 'neg' : 'pos' ?>"><?= ((float)$t['amount'] > 0 ? '+' : '−') . eur(abs((float)$t['amount'])) ?></b>
      <div class="small"><?= e($t['description']) ?> <span class="muted">· <?= e($t['account']) ?></span></div></div>
    <?php if ($canEdit): ?><div class="tx-a">
      <?php foreach ($sug as $s): $d = $s['doc']; ?>
        <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="tx_match"><input type="hidden" name="tx" value="<?= (int)$t['id'] ?>"><input type="hidden" name="doc" value="<?= (int)$d['id'] ?>">
          <button class="btn <?= $s['score'] >= 70 ? '' : 'ghost ' ?>sm" type="submit" data-tip="<?= e($s['why'] . ' · ' . $s['score'] . '%') ?>">Conciliar com <?= e(shorten(fin_doc_label($d) . ' · ' . $d['party_name'], 40)) ?> (<?= eur($d['left']) ?>)</button></form>
      <?php endforeach; ?>
      <details class="tx-more"><summary class="btn ghost sm">Outro…</summary>
        <form method="post" class="fin-form"><?= csrf_field() ?><input type="hidden" name="do" value="tx_match"><input type="hidden" name="tx" value="<?= (int)$t['id'] ?>">
          <label class="small">Lançamento em aberto <select name="doc"><?php foreach (fin_docs($pdo, ['dir' => (float)$t['amount'] > 0 ? 'in' : 'out', 'st' => ['issued', 'partial', 'to_pay', 'dispute'], 'limit' => 200]) as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e(shorten(fin_doc_label($d) . ' · ' . $d['party_name'] . ' · ' . eur(fin_doc_left($d)), 70)) ?></option><?php endforeach; ?></select></label>
          <button class="btn ghost sm" type="submit">Conciliar</button></form>
        <form method="post" class="fin-form"><?= csrf_field() ?><input type="hidden" name="do" value="tx_new"><input type="hidden" name="tx" value="<?= (int)$t['id'] ?>">
          <p class="small">Criar o lançamento (já pago) a partir deste movimento:</p>
          <div class="grid"><div class="field"><label>Categoria</label><select name="category"><?= fin_opts(array_combine($cats, $cats), 'Outros') ?></select></div>
            <div class="field"><label>IVA incluído</label><select name="vat_rate"><?= fin_opts(['0' => 'Sem IVA', (string)fin_p($pdo, 'vat_normal', 23) => fin_num(fin_p($pdo, 'vat_normal', 23)) . '%'], '0') ?></select></div>
            <div class="field"><label>Âmbito</label><select name="scope"><option value="company">Empresa</option><option value="deal">Negócio</option></select></div>
            <div class="field"><label>Negócio</label><select name="deal_id"><?= fin_deal_options($pdo, null) ?></select></div>
            <div class="field wide"><label>Descrição</label><input type="text" name="description" value="<?= e($t['description']) ?>"></div></div>
          <button class="btn ghost sm" type="submit">Criar e conciliar</button></form>
        <form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="do" value="tx_ignore"><input type="hidden" name="tx" value="<?= (int)$t['id'] ?>"><button class="btn ghost sm" type="submit">Pôr de parte (transferência entre contas, etc.)</button></form>
      </details></div><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<h2>Previsão de tesouraria</h2>
<p class="small muted">Saldo das contas + recebimentos esperados (faturas emitidas pelo vencimento, negócios ganhos por faturar) − custos fixos − pagamentos previstos.<?= $cash['reserved'] > 0 ? ' Inclui ' . eur0($cash['reserved']) . ' reservados aos sócios.' : '' ?> <a class="lnk" href="<?= e(fin_url(['tab' => 'tesouraria', 'h' => count($fc) === 12 ? 6 : 12])) ?>">Ver <?= count($fc) === 12 ? '6' : '12' ?> meses</a></p>
<div class="tablewrap"><table class="fin-t"><thead><tr><th>Mês</th><th class="num">Entradas</th><th class="num">Saídas</th><th class="num">Saldo no fim do mês</th></tr></thead><tbody>
  <?php foreach ($fc as $k => $r): ?><tr><td><?= e(month_year((int)substr($k, 5, 2), (int)substr($k, 0, 4))) ?></td><td class="num"><?= eur0($r['in']) ?></td><td class="num"><?= eur0($r['out']) ?></td><td class="num b<?= $r['balance'] < 0 ? ' neg' : '' ?>"><?= eur0($r['balance']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>

<?php
// ═══ Simulador ═════════════════════════════════════════════
elseif ($tab === 'simulador'):
    require_once __DIR__ . '/fin_sim.php';
    $kind = isset(FIN_SIM_KINDS[$_GET['kind'] ?? '']) ? (string)$_GET['kind'] : 'hire';
    $sc = (int)($_GET['sc'] ?? 0);
    $scen = $sc ? fin_scenario($pdo, $sc) : null;
    if ($scen) $kind = (string)$scen['kind'];
    $in = $scen ? fin_sim_inputs($kind, json_decode((string)$scen['inputs'], true) ?: []) : fin_sim_inputs($kind, $_SESSION['fin_sim'][$kind] ?? []);
    $res = fin_sim($pdo, $kind, $in);
    $saved = fin_scenarios($pdo);
    $cmp = array_values(array_filter(array_map('intval', (array)($_GET['cmp'] ?? []))));
?>
<nav class="views fin-kinds" aria-label="Tipo de decisão"><?php foreach (FIN_SIM_KINDS as $k => $l): ?><a href="<?= e(fin_url(['tab' => 'simulador', 'kind' => $k])) ?>" class="<?= $kind === $k ? 'on' : '' ?>"><?= e($l) ?></a><?php endforeach; ?></nav>
<div class="fin-sim">
  <form method="post" action="<?= e(fin_url(['tab' => 'simulador', 'kind' => $kind])) ?>" class="fin-form" data-fin-sim><?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($kind) ?>">
    <h2>E se… <?= e(mb_strtolower(FIN_SIM_KINDS[$kind])) ?>?</h2>
    <?= fin_sim_form($pdo, $kind, $in) ?>
    <div class="actions"><button class="btn" type="submit" name="do" value="sim_calc">Calcular</button>
      <?php if ($canEdit): ?><button class="btn ghost" type="submit" name="do" value="sim_save">Guardar cenário</button><?php endif; ?></div>
  </form>
  <div class="fin-sim-res" aria-live="polite"><?= fin_sim_result_html($pdo, $kind, $res) ?></div>
</div>
<?php if ($saved): ?>
<h2>Cenários guardados</h2>
<?php if ($canEdit) foreach ($saved as $s): ?><form id="sa-<?= (int)$s['id'] ?>" method="post" action="<?= e(fin_url(['tab' => 'simulador'])) ?>" hidden><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"></form><?php endforeach; ?>
<form method="get"><input type="hidden" name="tab" value="simulador">
<div class="tablewrap"><table class="fin-t"><thead><tr><th><span class="sr">Comparar</span></th><th>Cenário</th><th>Tipo</th><th class="num">Custo mensal</th><th class="num">Runway útil depois</th><th class="hide-sm">Por</th><th></th></tr></thead><tbody>
  <?php foreach ($saved as $s): $r = json_decode((string)$s['result'], true) ?: []; ?>
  <tr><td><input type="checkbox" name="cmp[]" value="<?= (int)$s['id'] ?>"<?= in_array((int)$s['id'], $cmp, true) ? ' checked' : '' ?> aria-label="Comparar <?= e($s['name']) ?>"></td>
    <td><a class="lnk b" href="<?= e(fin_url(['tab' => 'simulador', 'sc' => (int)$s['id']])) ?>"><?= e($s['name']) ?></a><?= $s['applied_at'] ? ' <span class="chip ok">aplicado</span>' : '' ?></td>
    <td><?= e(FIN_SIM_KINDS[$s['kind']] ?? $s['kind']) ?></td><td class="num"><?= eur0((float)($r['monthly'] ?? 0)) ?></td>
    <td class="num"><?= isset($r['runway_after']) && $r['runway_after'] !== null ? e(fin_num((float)$r['runway_after'], 1)) . ' meses' : '—' ?></td><td class="hide-sm small"><?= e((string)$s['who']) ?></td>
    <td class="num nowrap"><?php if ($canEdit): ?>
      <?php if (!$s['applied_at']): ?><button class="btn ghost sm" type="submit" form="sa-<?= (int)$s['id'] ?>" name="do" value="sim_apply">Aplicar</button><?php endif; ?>
      <button class="btn ghost sm" type="submit" form="sa-<?= (int)$s['id'] ?>" name="do" value="sim_del">Apagar</button><?php endif; ?></td></tr>
  <?php endforeach; ?>
</tbody></table></div>
<div class="actions"><button class="btn ghost sm" type="submit">Comparar os escolhidos</button></div>
</form>
<?php endif; ?>
<?php if (count($cmp) >= 2):
    $rows = array_values(array_filter(array_map(fn($id) => fin_scenario($pdo, $id), $cmp)));
    $res = array_map(fn($s) => fin_sim($pdo, (string)$s['kind'], fin_sim_inputs((string)$s['kind'], json_decode((string)$s['inputs'], true) ?: [])), $rows);
    $line = fn(string $label, callable $f) => '<tr><td>' . e($label) . '</td>' . implode('', array_map(fn($r) => '<td class="num">' . $f($r) . '</td>', $res)) . '</tr>'; ?>
<h2>Lado a lado</h2>
<div class="tablewrap"><table class="fin-t fin-cmp"><thead><tr><th></th><?php foreach ($rows as $s): ?><th class="num"><?= e($s['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?= $line('Custo mensal real', fn($r) => eur0($r['monthly'])) ?><?= $line('Custo anual', fn($r) => eur0($r['annual'])) ?><?= $line('De uma vez', fn($r) => eur0($r['oneoff'])) ?>
  <?= $line('Poupança de IRC/ano', fn($r) => eur0($r['irc_saving'])) ?><?= $line('Runway útil depois', fn($r) => $r['runway_after'] === null ? '—' : e(fin_num($r['runway_after'], 1)) . ' meses') ?>
  <?= $line('Entra na reserva', fn($r) => $r['reserve_after'] ? e(month_year((int)substr($r['reserve_after'], 5, 2), (int)substr($r['reserve_after'], 0, 4))) : '—') ?><?= $line('Receita extra por mês', fn($r) => eur0($r['extra_revenue'])) ?>
</tbody></table></div>
<?php endif; ?>

<?php
// ═══ Impostos ══════════════════════════════════════════════
else:
    require_once __DIR__ . '/fin_tax.php';
    $vy = (int)($_GET['vy'] ?? date('Y'));
    $per = fin_vat_periods($pdo, $vy);
    $obl = fin_obligations($pdo, today_lisbon(), 90);
    $done = array_flip($pdo->query('SELECT okey FROM fin_done')->fetchAll(PDO::FETCH_COLUMN));
    $ym = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
?>
<h2>IVA · <?= (int)fin_p($pdo, 'vat_period', 3) === 1 ? 'mensal' : 'trimestral' ?></h2>
<form class="bar" method="get"><input type="hidden" name="tab" value="impostos"><select name="vy" onchange="this.form.submit()" aria-label="Ano"><?php foreach (range((int)date('Y') + 0, (int)date('Y') - 3) as $y): ?><option <?= $y === $vy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select><noscript><button class="btn sm">Ver</button></noscript></form>
<div class="tablewrap"><table class="fin-t"><thead><tr><th>Período</th><th class="num">Liquidado</th><th class="num">Dedutível</th><th class="num">Saldo</th><th>Declaração</th><th>Pagamento</th></tr></thead><tbody>
  <?php foreach ($per as $p): ?>
  <tr><td><details><summary><?= e($p['label']) ?></summary>
      <?php if ($p['rows']): ?><ul class="small fin-vat-rows"><?php foreach ($p['rows'] as $r): ?><li><?= fin_date_pt($r['date']) ?> · <?= $r['doc'] ? '<a class="lnk" href="' . e(fin_url(['tab' => $r['out'] != 0 ? 'receber' : 'pagar', 'doc' => $r['doc']])) . '">' . e($r['label']) . '</a>' : e($r['label']) ?> · <?= $r['out'] != 0 ? 'liquidado ' . eur($r['out']) : 'dedutível ' . eur($r['in']) ?></li><?php endforeach; ?></ul><?php else: ?><p class="small muted">Sem movimentos com IVA.</p><?php endif; ?></details></td>
    <td class="num"><?= eur($p['out']) ?></td><td class="num"><?= eur($p['in']) ?></td><td class="num b<?= $p['balance'] < 0 ? ' pos' : '' ?>"><?= eur(abs($p['balance'])) ?> <span class="muted small"><?= $p['balance'] >= 0 ? 'a entregar' : 'a recuperar' ?></span></td>
    <td><?= fin_date_pt($p['decl']) ?></td><td><?= fin_date_pt($p['pay']) ?></td></tr>
  <?php endforeach; ?>
</tbody></table></div>
<div class="flash">Estimativa a partir dos lançamentos — a declaração periódica confirma-se sempre com o contabilista.</div>
<h2>Calendário fiscal · próximos 90 dias</h2>
<?php if (!$obl): ?><p class="muted small">Nada nos próximos 90 dias.</p><?php endif; ?>
<ul class="fin-cal">
  <?php foreach ($obl as $o): $days = (int)round((strtotime($o['date']) - strtotime(today_lisbon())) / 86400); ?>
  <li class="<?= $days <= 7 ? 'soon' : '' ?>"><span class="d mono"><?= fin_date_pt($o['date']) ?></span><span class="t"><?= e($o['name']) ?></span>
    <span class="a"><?= $o['amount'] > 0 ? eur($o['amount']) : '' ?></span><span class="s small muted"><?= $days === 0 ? 'hoje' : ($days === 1 ? 'amanhã' : 'daqui a ' . $days . ' dias') ?><?= isset($done[$o['key']]) ? ' · tarefa criada' : '' ?></span></li>
  <?php endforeach; ?>
</ul>
<p class="small muted">Cada obrigação dá uma tarefa ao responsável financeiro 10 dias antes. Datas e obrigações em Definições › Financeiro.</p>
<h2>Exportação para o contabilista</h2>
<form class="bar" method="get" action="/admin/fin_file.php"><label for="ex-ym" class="muted small">Mês</label><input type="month" id="ex-ym" name="ym" value="<?= e($ym) ?>" required>
  <button class="btn sm" type="submit" name="export" value="zip"><?= icon('download') ?> ZIP com PDFs e CSV</button>
  <button class="btn ghost sm" type="submit" name="export" value="docs">Só o CSV dos lançamentos</button>
  <button class="btn ghost sm" type="submit" name="export" value="payroll">Salários (CSV)</button></form>
<?php endif;
