<?php
/**
 * topper. admin — Financeiro: peças da página (§28)
 *
 * Formulários (lançamento, pagamento, custo fixo, ficha de salário, simulador) e painéis (documento,
 * resultado do simulador) usados por financeiro.php, fin_api.php e pela ficha do colaborador (Equipa).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/fin.php';

/** O estado em chip. */
function fin_chip(string $st): string {
    $tone = ['requested' => 'info', 'issued' => 'wait', 'partial' => 'wait', 'paid' => 'ok', 'archived' => 'ok', 'void' => '', 'review' => 'info', 'to_pay' => 'wait', 'dispute' => 'no', 'legacy' => 'wait'][$st] ?? '';
    return '<span class="chip ' . $tone . '">' . e(FIN_ST[$st] ?? ($st === 'legacy' ? 'Sem fatura' : $st)) . '</span>';
}

/** O URL da página com estes parâmetros. */
function fin_url(array $q): string {
    return '/admin/financeiro.php?' . http_build_query(array_filter($q, fn($v) => $v !== null && $v !== '' && $v !== 0));
}

/** As opções de negócio (código e nome; os mais recentes primeiro). */
function fin_deal_options(PDO $pdo, ?int $sel): string {
    static $rows = null;
    $rows ??= $pdo->query("SELECT id, code, name FROM deals WHERE code IS NOT NULL AND phase IN ('pipeline','production','done') ORDER BY id DESC LIMIT 400")->fetchAll();
    $h = '<option value="">— escolher —</option>';
    foreach ($rows as $d) $h .= '<option value="' . (int)$d['id'] . '"' . ((int)$sel === (int)$d['id'] ? ' selected' : '') . '>' . e($d['code'] . ' · ' . shorten($d['name'], 48)) . '</option>';
    return $h;
}

/** As frentes (crosselling) de cada negócio, para o select dependente. */
function fin_line_options(PDO $pdo, ?int $deal, ?int $sel): string {
    $h = '<option value="">Negócio inteiro</option>';
    if (!$deal || !db_has_table($pdo, 'deal_lines')) return $h;
    $st = $pdo->prepare('SELECT l.id, f.name FROM deal_lines l JOIN funnels f ON f.id = l.funnel_id WHERE l.deal_id = ? ORDER BY l.is_main DESC, l.position');
    $st->execute([$deal]);
    foreach ($st->fetchAll() as $l) $h .= '<option value="' . (int)$l['id'] . '"' . ((int)$sel === (int)$l['id'] ? ' selected' : '') . '>' . e($l['name']) . '</option>';
    return $h;
}

function fin_opts(array $map, $sel): string {
    $h = '';
    foreach ($map as $k => $l) $h .= '<option value="' . e((string)$k) . '"' . ((string)$k === (string)$sel ? ' selected' : '') . '>' . e($l) . '</option>';
    return $h;
}

/** Os quatro números do topo: saldo disponível · por receber (atraso a vermelho) · a pagar 30 dias · runway útil. */
function fin_kpis(PDO $pdo): string {
    require_once __DIR__ . '/fin_cash.php';
    $rw = fin_runway($pdo);
    $cash = $rw['cash'];
    $recv = fin_receivables($pdo);
    $tot = 0.0; $late = 0.0; $nLate = 0;
    foreach ($recv as $r) { $tot += $r['gross']; if ($r['age'] > 0) { $late += $r['gross']; $nLate++; } }
    $until = date('Y-m-d', strtotime(today_lisbon() . ' +30 days'));
    $pay = 0.0; $nPay = 0;
    foreach (fin_payables($pdo) as $d) if ($d['status'] !== 'review' && $d['due_date'] && $d['due_date'] <= $until) { $pay += $d['left']; $nPay++; }
    $alert = fin_p($pdo, 'runway_alert', 6);
    $useful = $rw['useful'];
    $h = '<div class="stats fin-kpis">';
    $h .= '<a class="stat" href="' . e(fin_url(['tab' => 'tesouraria'])) . '"><div class="k">Saldo disponível</div><div class="v' . ($cash['available'] < 0 ? ' neg' : '') . '">' . ($cash['available'] < 0 ? '−' : '') . eur(abs($cash['available'])) . '</div>'
        . '<div class="n">' . ($cash['n_accounts'] ? 'contas ' . eur($cash['accounts']) . ' − compromissos até ao fim do mês ' . eur($cash['commit']) . ($cash['reserved'] > 0 ? ' − reservas dos sócios ' . eur($cash['reserved']) : '') . ($cash['stale'] ? ' · ' . $cash['stale'] . ($cash['stale'] === 1 ? ' saldo por atualizar' : ' saldos por atualizar') : '') : 'Sem contas: junte-as em Tesouraria') . '</div></a>';
    $h .= '<a class="stat' . ($nLate ? ' risk' : '') . '" href="' . e(fin_url(['tab' => 'receber'])) . '"><div class="k">Por receber</div><div class="v">' . eur($tot) . '</div>'
        . '<div class="n">' . ($nLate ? '<span class="neg">' . eur($late) . ' em atraso (' . $nLate . ')</span>' : count($recv) . ' em aberto · nada em atraso') . '</div></a>';
    $h .= '<a class="stat" href="' . e(fin_url(['tab' => 'pagar', 'v' => 'month'])) . '"><div class="k">A pagar · 30 dias</div><div class="v">' . eur($pay) . '</div><div class="n">' . $nPay . ($nPay === 1 ? ' pagamento' : ' pagamentos') . ' até ' . fin_date_pt($until) . '</div></a>';
    $cls = $useful === null ? '' : ($useful < 0 ? ' neg' : ($useful < $alert ? ' warn' : ''));
    $h .= '<a class="stat hero' . ($useful !== null && $useful < 0 ? ' neg' : '') . '" href="' . e(fin_url(['tab' => 'geral'])) . '#runway"><div class="k">Runway útil</div><div class="v' . $cls . '">'
        . ($useful === null ? '—' : fin_num($useful, 1) . ' meses') . '</div><div class="n">' . ($useful === null ? 'sem custos fixos registados' : ($useful > 0 ? 'até ' . fin_date_pt($rw['date']) . ' · depois a reserva de ' . fin_num($rw['reserve_months'], 0) . ' meses' : 'dentro da reserva de emergência')) . '</div></a>';
    return $h . '</div>';
}

/**
 * O formulário de um lançamento (novo ou a editar). $dir: in|out. $v: valores (do lançamento, do pedido, de omissão).
 * Com JavaScript: largar o PDF lê-o com a IA e preenche (fica tudo por confirmar); sem JavaScript, preenche-se à mão.
 */
function fin_doc_form(PDO $pdo, string $dir, array $v, string $back, bool $edit): string {
    $cats = fin_categories($pdo);
    $id = (int)($v['id'] ?? 0);
    $review = ($v['status'] ?? '') === 'review';
    $whB = fin_p($pdo, 'wh_cat_b', 23); $whR = fin_p($pdo, 'wh_rent', 25);
    $rates = array_unique([fin_p($pdo, 'vat_normal', 23), fin_p($pdo, 'vat_intermediate', 13), fin_p($pdo, 'vat_reduced', 6), 0.0]);
    $rateOpts = '';
    foreach ($rates as $r) $rateOpts .= '<option value="' . e((string)$r) . '"' . ((float)($v['vat_rate'] ?? fin_p($pdo, 'vat_normal', 23)) == $r ? ' selected' : '') . '>' . e(fin_num($r)) . '%</option>';
    if (!in_array((float)($v['vat_rate'] ?? 23), $rates, false)) $rateOpts .= '<option value="' . e((string)$v['vat_rate']) . '" selected>' . e(fin_num((float)$v['vat_rate'])) . '%</option>';
    $num = fn($k) => isset($v[$k]) && $v[$k] !== '' && $v[$k] !== null ? e(number_format((float)$v[$k], 2, ',', '')) : '';
    $deal = (int)($v['deal_id'] ?? 0);
    $scope = (string)($v['scope'] ?? ($deal ? 'deal' : 'company'));
    $h = '<form method="post" enctype="multipart/form-data" class="fin-form" data-fin-doc data-dir="' . e($dir) . '" data-wh-b="' . e((string)$whB) . '" data-wh-r="' . e((string)$whR) . '">'
       . csrf_field() . '<input type="hidden" name="do" value="doc_save"><input type="hidden" name="direction" value="' . e($dir) . '"><input type="hidden" name="back" value="' . e($back) . '">'
       . '<input type="hidden" name="tmp" value="">' . ($id ? '<input type="hidden" name="id" value="' . $id . '">' : '');
    if (!$id || ($v['pdf_key'] ?? '') === '') {
        $h .= '<div class="fin-drop" tabindex="0"><input type="file" name="file" id="fd-file-' . $dir . '" accept="application/pdf,image/jpeg,image/png">'
            . '<label for="fd-file-' . $dir . '">' . icon('upload') . ' <b>Largue aqui o PDF da fatura</b> ou clique para escolher</label>'
            . '<p class="small muted fin-ai-note" aria-live="polite">' . (fin_ai_ready_hint() ? 'A IA lê o número, as datas, o NIF e os valores e preenche; confirme antes de guardar.' : 'Preencha os campos (a IA não está ligada neste servidor).') . '</p></div>';
    } else {
        $h .= '<p class="small">PDF: <a class="lnk" href="' . e(fin_file_href('doc', $id)) . '" target="_blank" rel="noopener">' . e($v['pdf_name']) . '</a></p>';
    }
    if ($review) $h .= '<div class="flash">Chegou por email e foi lido (QR fiscal, XML ou IA): confirme os dados e guarde para passar a «Por pagar».</div>';
    $h .= '<div class="grid">'
        . '<div class="field"><label for="fd-type">Documento</label><select id="fd-type" name="doc_type">' . fin_opts($dir === 'in' ? array_intersect_key(FIN_TYPES, array_flip(['invoice', 'receipt', 'credit', 'other'])) : array_intersect_key(FIN_TYPES, array_flip(['invoice', 'receipt', 'credit', 'expense', 'tax', 'other'])), $v['doc_type'] ?? 'invoice') . '</select></div>'
        . '<fieldset class="field fin-scope"><legend>Âmbito</legend><label class="check"><input type="radio" name="scope" value="company"' . ($scope === 'company' ? ' checked' : '') . '> Empresa (interno)</label> '
        . '<label class="check"><input type="radio" name="scope" value="deal"' . ($scope === 'deal' ? ' checked' : '') . '> Negócio</label></fieldset>'
        . '<div class="field fin-deal"' . ($scope === 'deal' ? '' : ' hidden') . '><label for="fd-deal">Negócio</label><select id="fd-deal" name="deal_id">' . fin_deal_options($pdo, $deal ?: null) . '</select></div>'
        . '<div class="field fin-deal"' . ($scope === 'deal' && $deal ? '' : ' hidden') . '><label for="fd-line">Frente</label><select id="fd-line" name="line_id">' . fin_line_options($pdo, $deal ?: null, (int)($v['line_id'] ?? 0) ?: null) . '</select></div>'
        . '<div class="field"><label for="fd-pk">' . ($dir === 'in' ? 'Cliente é' : 'Fornecedor é') . '</label><select id="fd-pk" name="party_kind">' . fin_opts($dir === 'in' ? ['client' => 'Cliente', 'other' => 'Outro'] : array_diff_key(FIN_PARTY, ['client' => 1]), $v['party_kind'] ?? ($dir === 'in' ? 'client' : 'supplier')) . '</select></div>'
        . '<div class="field"><label for="fd-pn">' . ($dir === 'in' ? 'Cliente' : 'Fornecedor') . '</label><input type="text" id="fd-pn" name="party_name" maxlength="190" value="' . e((string)($v['party_name'] ?? '')) . '" required></div>'
        . '<div class="field"><label for="fd-nif">NIF</label><input type="text" id="fd-nif" name="party_nif" maxlength="20" inputmode="numeric" value="' . e((string)($v['party_nif'] ?? '')) . '"></div>';
    if ($dir === 'in') {
        $h .= '<div class="field"><label for="fd-pe">Email do cliente</label><input type="email" id="fd-pe" name="party_email" maxlength="190" value="' . e((string)($v['party_email'] ?? '')) . '"></div>';
    }
    $h .= '<div class="field"><label for="fd-cat">Categoria</label><select id="fd-cat" name="category"' . ($dir === 'out' ? ' required' : '') . '><option value="">—</option>' . fin_opts(array_combine($cats, $cats), $v['category'] ?? '') . '</select></div>'
        . ($dir === 'out' && db_has_column($pdo, 'fin_docs', 'acq_channel')
            ? '<div class="field"><label for="fd-acq">Custo de aquisição</label><select id="fd-acq" name="acq_channel"><option value="">Não</option>' . fin_opts(LEAD_ORIGIN, $v['acq_channel'] ?? '') . '</select></div>'
            . '<div class="field"><label for="fd-acqs">Campanha / fonte paga</label><input type="text" id="fd-acqs" name="acq_source" maxlength="160" value="' . e((string)($v['acq_source'] ?? '')) . '" placeholder="Ex.: Meta outono"></div>' : '')
        . '<div class="field"><label for="fd-num">Número</label><input type="text" id="fd-num" name="number" maxlength="60" value="' . e((string)($v['number'] ?? '')) . '" placeholder="FT 2026/142"></div>'
        . '<div class="field wide"><label for="fd-desc">Descrição</label><input type="text" id="fd-desc" name="description" maxlength="500" value="' . e((string)($v['description'] ?? '')) . '"></div>'
        . '<div class="field"><label for="fd-iss">Emissão</label><input type="date" id="fd-iss" name="issue_date" value="' . e((string)($v['issue_date'] ?? today_lisbon())) . '"></div>'
        . '<div class="field"><label for="fd-due">Vencimento</label><input type="date" id="fd-due" name="due_date" value="' . e((string)($v['due_date'] ?? '')) . '"></div>'
        . '<div class="field"><label for="fd-base">Base (s/ IVA)</label><input type="text" id="fd-base" name="base" inputmode="decimal" value="' . $num('base') . '" required></div>'
        . '<div class="field"><label for="fd-rate">Taxa de IVA</label><select id="fd-rate" name="vat_rate">' . $rateOpts . '</select></div>'
        . '<div class="field"><label for="fd-vat">IVA</label><input type="text" id="fd-vat" name="vat" inputmode="decimal" value="' . $num('vat') . '"></div>'
        . '<div class="field"><label for="fd-tot">Total</label><input type="text" id="fd-tot" name="total" inputmode="decimal" value="' . $num('total') . '"></div>';
    if ($dir === 'out') {
        $h .= '<div class="field"><label for="fd-whr">Retenção na fonte</label><select id="fd-whr" name="wh_rate">' . fin_opts(['0' => 'Sem retenção', (string)$whB => fin_num($whB) . '% (freelancer)', (string)$whR => fin_num($whR) . '% (renda)'], (string)(float)($v['wh_rate'] ?? 0)) . '</select></div>'
            . '<div class="field"><label for="fd-wh">Retenção (€)</label><input type="text" id="fd-wh" name="withholding" inputmode="decimal" value="' . $num('withholding') . '"></div>'
            . '<div class="field"><input type="hidden" name="vat_deductible" value="0"><label class="check" style="margin-top:1.6rem"><input type="checkbox" name="vat_deductible" value="1"' . ((int)($v['vat_deductible'] ?? 1) ? ' checked' : '') . '> IVA dedutível</label></div>'
            . '<div class="field fin-wh-note wide" aria-live="polite"></div>'
            . '<div class="field"><label class="check" style="margin-top:1.6rem"><input type="checkbox" name="wh_exempt_save" value="1"> Freelancer com dispensa de retenção (guardar na ficha)</label></div>';
    } else {
        $h .= '<div class="field"><label for="fd-vn">Menção de isenção</label><input type="text" id="fd-vn" name="vat_note" maxlength="190" value="' . e((string)($v['vat_note'] ?? '')) . '" placeholder="' . e(fin_ps($pdo, 'vat_exempt_note')) . '"></div>'
            . '<div class="field"><label for="fd-inst">Prestação</label><input type="text" id="fd-inst" name="installment" maxlength="60" value="' . e((string)($v['installment'] ?? '')) . '" placeholder="1.ª de 2 — 50%"></div>';
    }
    $h .= '<div class="field"><label for="fd-cur">Moeda</label><input type="text" id="fd-cur" name="currency" maxlength="3" value="' . e((string)($v['currency'] ?? 'EUR')) . '" style="max-width:6em"></div>'
        . '<div class="field wide"><label for="fd-notes">Notas</label><input type="text" id="fd-notes" name="notes" maxlength="2000" value="' . e((string)($v['notes'] ?? '')) . '"></div>'
        . '</div><div class="fin-dup flash err" hidden></div>'
        . '<div class="actions"><button class="btn" type="submit">' . ($review ? 'Confirmar' : ($id ? 'Guardar' : 'Lançar')) . '</button> <a class="btn ghost" href="' . e($back) . '">Cancelar</a></div></form>';
    return $h;
}

/** Há IA ligada? (sem a carregar só para o aviso) */
function fin_ai_ready_hint(): bool {
    return is_file(__DIR__ . '/ai_config.php');
}

/** O formulário de pagamento (parcial ou total), sempre com comprovativo. */
function fin_pay_form(array $d, string $back): string {
    $left = fin_doc_left($d);
    return '<form method="post" enctype="multipart/form-data" class="fin-pay">' . csrf_field() . '<input type="hidden" name="do" value="pay_add"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
        . '<div class="grid"><div class="field"><label for="py-a">Valor ' . ($d['direction'] === 'in' ? 'recebido' : 'pago') . '</label><input type="text" id="py-a" name="amount" inputmode="decimal" value="' . e(number_format($left, 2, ',', '')) . '" required></div>'
        . '<div class="field"><label for="py-d">Data</label><input type="date" id="py-d" name="paid_on" value="' . e(today_lisbon()) . '" max="' . e(today_lisbon()) . '" required></div>'
        . '<div class="field"><label for="py-m">Meio</label><select id="py-m" name="method">' . fin_opts(FIN_METHODS, 'transfer') . '</select></div>'
        . '<div class="field wide"><label for="py-f">Comprovativo (PDF ou imagem)</label><input type="file" id="py-f" name="proof" accept="application/pdf,image/jpeg,image/png" required></div></div>'
        . '<p class="small muted">Sem comprovativo não passa a «Paga». Também pode conciliar com o extrato em Tesouraria — conta como comprovativo.</p>'
        . '<div class="actions"><button class="btn sm" type="submit">' . icon('check') . ' Registar pagamento</button></div></form>';
}

/** O painel de um lançamento: valores, ficheiros, pagamentos e as ações possíveis. */
function fin_doc_panel(PDO $pdo, array $d, bool $canEdit, string $back): string {
    $pays = fin_payments($pdo, (int)$d['id']);
    $in = $d['direction'] === 'in';
    $h = '<article class="fin-doc" aria-label="' . e(fin_doc_label($d)) . '">'
       . '<header><div><div class="k mono">' . ($in ? 'RECEITA' : 'DESPESA') . ' · ' . e(mb_strtoupper(FIN_TYPES[$d['doc_type']] ?? '')) . '</div><h3>' . e(fin_doc_label($d)) . '</h3>'
       . '<div class="muted small">' . e($d['party_name']) . ($d['party_nif'] !== '' ? ' · NIF ' . e($d['party_nif']) : '') . '</div></div>' . fin_chip((string)$d['status']) . '</header>';
    $h .= '<dl class="fin-dl">'
        . '<dt>Âmbito</dt><dd>' . ($d['scope'] === 'deal' ? '<a class="lnk" href="/admin/negocio.php?id=' . (int)$d['deal_id'] . '">' . e($d['deal_code'] . ' · ' . $d['deal_name']) . '</a>' : 'Empresa (interno)') . '</dd>'
        . ($d['category'] !== '' ? '<dt>Categoria</dt><dd>' . e($d['category']) . '</dd>' : '')
        . ($d['description'] !== '' ? '<dt>Descrição</dt><dd>' . e($d['description']) . '</dd>' : '')
        . '<dt>Emissão</dt><dd>' . fin_date_pt($d['issue_date']) . '</dd><dt>Vencimento</dt><dd>' . fin_date_pt($d['due_date']) . (($od = fin_overdue_days($d)) > 0 && in_array($d['status'], FIN_OPEN, true) ? ' <span class="neg">· ' . $od . ' dias de atraso</span>' : '') . '</dd>'
        . '<dt>Base</dt><dd class="num">' . eur((float)$d['base']) . '</dd>'
        . '<dt>IVA ' . e(fin_num((float)$d['vat_rate'])) . '%</dt><dd class="num">' . eur((float)$d['vat']) . (!$in && !(int)$d['vat_deductible'] ? ' <span class="muted small">não dedutível</span>' : '') . ($d['vat_note'] !== '' ? ' <span class="muted small">' . e($d['vat_note']) . '</span>' : '') . '</dd>'
        . '<dt>Total</dt><dd class="num b">' . eur((float)$d['total']) . ($d['currency'] !== 'EUR' ? ' ' . e($d['currency']) : '') . '</dd>';
    if ((float)$d['withholding'] > 0) {
        $h .= '<dt>Retenção ' . e(fin_num((float)$d['wh_rate'])) . '%</dt><dd class="num">' . eur((float)$d['withholding']) . '</dd>';
        if (!$in) $h .= '<dt>Pagar a ' . e(FIN_PARTY[$d['party_kind']] === 'Senhorio' ? 'senhorio' : 'quem faturou') . '</dt><dd class="num b">' . eur(fin_doc_due($d)) . '</dd><dt>Entregar ao Estado</dt><dd class="num">' . eur((float)$d['withholding']) . ' <span class="muted small">até dia ' . (int)fin_p($pdo, 'cal_irs_pay', 20) . ' do mês seguinte ao pagamento</span></dd>';
    }
    $h .= '<dt>' . ($in ? 'Recebido' : 'Pago') . '</dt><dd class="num">' . eur((float)$d['paid_amount']) . ' · falta <b>' . eur(fin_doc_left($d)) . '</b></dd>';
    if ($d['installment'] !== '') $h .= '<dt>Prestação</dt><dd>' . e($d['installment']) . '</dd>';
    if ($d['req_code'] !== '') $h .= '<dt>Pedido</dt><dd class="mono">' . e($d['req_code']) . ($d['req_sent_at'] ? ' · ' . e(date('d/m H:i', strtotime((string)$d['req_sent_at']))) : '') . '</dd>';
    if ($d['client_sent_at']) $h .= '<dt>Enviada ao cliente</dt><dd>' . e(date('d/m/Y H:i', strtotime((string)$d['client_sent_at']))) . '</dd>';
    $h .= '</dl>';
    $files = [];
    if ($d['pdf_key'] !== '') $files[] = '<a class="lnk" href="' . e(fin_file_href('doc', (int)$d['id'])) . '" target="_blank" rel="noopener">' . icon('file') . ' ' . e($d['pdf_name'] ?: 'Documento') . '</a>';
    if ($d['receipt_key'] !== '') $files[] = '<a class="lnk" href="' . e(fin_file_href('doc', (int)$d['id']) . '&k=receipt') . '" target="_blank" rel="noopener">' . icon('file') . ' Recibo</a>';
    if ($files) $h .= '<p class="fin-files">' . implode(' ', $files) . '</p>';
    if ($d['notes'] !== '') $h .= '<p class="small muted" style="white-space:pre-line">' . e((string)$d['notes']) . '</p>';
    if ($pays) {
        $h .= '<h4>Pagamentos</h4><ul class="fin-pays">';
        foreach ($pays as $p) {
            $h .= '<li><span class="mono">' . fin_date_pt($p['paid_on']) . '</span> <b>' . eur((float)$p['amount']) . '</b> · ' . e(FIN_METHODS[$p['method']] ?? $p['method'])
                . ($p['bank_tx_id'] ? ' · <span class="chip info">conciliado</span>' : '') . ($p['proof_key'] !== '' ? ' · <a class="lnk" href="' . e(fin_file_href('pay', (int)$p['id'])) . '" target="_blank" rel="noopener">comprovativo</a>' : '')
                . ($canEdit ? ' <form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="do" value="pay_del"><input type="hidden" name="pay" value="' . (int)$p['id'] . '"><input type="hidden" name="back" value="' . e($back) . '"><button class="btn ghost sm" type="submit" aria-label="Anular este pagamento">Anular</button></form>' : '')
                . '</li>';
        }
        $h .= '</ul>';
    }
    if (!$canEdit) return $h . '</article>';
    $act = [];
    if (in_array($d['status'], ['issued', 'partial', 'to_pay', 'dispute'], true) && fin_doc_left($d) > 0) {
        $h .= '<details class="fin-act" ' . ($d['status'] === 'partial' ? 'open' : '') . '><summary>' . ($in ? 'Registar recebimento' : 'Registar pagamento') . '</summary>' . fin_pay_form($d, $back) . '</details>';
    }
    if ($in && $d['status'] === 'issued' && $d['pdf_key'] !== '') {
        require_once __DIR__ . '/fin_mail.php';
        $dr = fin_client_mail_draft($pdo, $d);
        $h .= '<details class="fin-act"' . ($d['ready_at'] && !$d['client_sent_at'] ? ' open' : '') . '><summary>' . ($d['client_sent_at'] ? 'Reenviar ao cliente' : 'Enviar ao cliente') . '</summary>'
            . (fin_mail_config() ? '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="client_send"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
              . fin_mail_as_select($pdo) . '<div class="field"><label>Para</label><input type="email" name="to" value="' . e($dr['to']) . '" required></div><div class="field"><label>Assunto</label><input type="text" name="subject" value="' . e($dr['subject']) . '" maxlength="200"></div>'
              . '<div class="field"><label>Mensagem (o PDF segue em anexo)</label><textarea name="body" rows="8">' . e($dr['body']) . '</textarea></div><div class="actions"><button class="btn sm" type="submit">' . icon('send') . ' Enviar</button></div></form>'
              : '<p class="small muted">Não há de onde enviar: falta a palavra-passe do seu email (Perfil › Email de envio) ou do email geral (Definições › Integrações › Email).</p>') . '</details>';
    }
    if ($in && in_array($d['status'], ['issued', 'partial'], true) && fin_overdue_days($d) > -3) {
        $h .= '<details class="fin-act"' . ($d['reminder_draft'] ? ' open' : '') . '><summary>Lembrete ao cliente</summary>';
        if ($d['reminder_draft']) {
            $r = json_decode((string)$d['reminder_draft'], true) ?: ['subject' => '', 'body' => ''];
            $h .= '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="reminder_send"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
                . fin_mail_as_select($pdo) . '<p class="small muted">' . (!empty($r['ai']) ? 'Rascunho da IA (tom pelo atraso).' : 'Rascunho.') . ' Reveja: só sai quando carregar em «Aprovar e enviar».</p>'
                . '<div class="field"><label>Para</label><input type="email" name="to" value="' . e((string)$d['party_email']) . '" required></div><div class="field"><label>Assunto</label><input type="text" name="subject" value="' . e((string)$r['subject']) . '"></div>'
                . '<div class="field"><label>Mensagem</label><textarea name="body" rows="8">' . e((string)$r['body']) . '</textarea></div><div class="actions"><button class="btn sm" type="submit">Aprovar e enviar</button></div></form>';
        } else {
            $h .= '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="reminder_draft"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><input type="hidden" name="back" value="' . e($back) . '"><button class="btn ghost sm" type="submit">Escrever rascunho</button></form>';
        }
        $h .= '</details>';
    }
    if (in_array($d['status'], ['paid', 'partial'], true) && $d['receipt_key'] === '') {
        $h .= '<details class="fin-act"><summary>Recibo</summary>'
            . '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="do" value="receipt_add"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><input type="hidden" name="back" value="' . e($back) . '">'
            . '<div class="field"><label>Juntar o recibo (PDF)</label><input type="file" name="receipt" accept="application/pdf" required></div><div class="actions"><button class="btn ghost sm" type="submit">Juntar</button></div></form>'
            . ($in ? '<form method="post">' . csrf_field() . '<input type="hidden" name="do" value="receipt_request"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><input type="hidden" name="back" value="' . e($back) . '"><button class="btn ghost sm" type="submit">Pedir recibo ao contabilista</button></form>' : '')
            . '</details>';
    }
    $btn = fn(string $to, string $label, string $cls = 'ghost') => '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="do" value="doc_status"><input type="hidden" name="id" value="' . (int)$d['id'] . '"><input type="hidden" name="to" value="' . $to . '"><input type="hidden" name="back" value="' . e($back) . '">'
        . ($to === 'void' ? '<input type="text" name="note" placeholder="Motivo (ex.: nota de crédito NC 2026/3)" maxlength="200" aria-label="Motivo"> ' : '') . '<button class="btn ' . $cls . ' sm" type="submit">' . e($label) . '</button></form>';
    $act[] = '<a class="btn ghost sm" href="' . e(fin_url(['tab' => $in ? 'receber' : 'pagar', 'edit' => (int)$d['id']])) . '">Editar</a>';
    if (!$in && in_array($d['status'], ['to_pay', 'partial'], true)) $act[] = $btn('dispute', 'Em disputa');
    if (!$in && $d['status'] === 'dispute') $act[] = $btn('to_pay', 'Voltar a «Por pagar»');
    if ($in && $d['status'] === 'void') $act[] = $btn('issued', 'Voltar a «Emitida»');
    if ($d['status'] !== 'void' && (float)$d['paid_amount'] <= 0) $act[] = '<details class="fin-void"><summary class="btn ghost sm">Anular…</summary>' . $btn('void', $in ? 'Anular a fatura' : 'Anular', 'danger') . '</details>';
    return $h . '<div class="doc-actions">' . implode(' ', $act) . '</div></article>';
}

// ── simulador ───────────────────────────────────────────────

/** Os campos do simulador para um tipo. */
function fin_sim_form(PDO $pdo, string $kind, array $in): string {
    $f = fn(string $k, string $label, string $type = 'text', array $o = []) => '<div class="field' . (!empty($o['wide']) ? ' wide' : '') . '"><label for="sim-' . $k . '">' . e($label) . '</label>'
        . ($type === 'select' ? '<select id="sim-' . $k . '" name="' . $k . '">' . fin_opts($o['opts'], $in[$k] ?? '') . '</select>'
          : ($type === 'check' ? '<input type="hidden" name="' . $k . '" value="0"><label class="check"><input type="checkbox" id="sim-' . $k . '" name="' . $k . '" value="1"' . ((float)($in[$k] ?? 0) ? ' checked' : '') . '> ' . e($o['text'] ?? 'Sim') . '</label>'
          : '<input type="' . ($type === 'date' ? 'date' : 'text') . '" id="sim-' . $k . '" name="' . $k . '"' . ($type === 'num' ? ' inputmode="decimal"' : '') . ' value="' . e($type === 'num' && ($in[$k] ?? '') !== '' ? str_replace('.', ',', (string)$in[$k]) : (string)($in[$k] ?? '')) . '"' . (!empty($o['ph']) ? ' placeholder="' . e($o['ph']) . '"' : '') . '>'))
        . (!empty($o['hint']) ? '<small class="muted">' . e($o['hint']) . '</small>' : '') . '</div>';
    $h = '<div class="grid">' . $f('name', 'Nome do cenário', 'text', ['ph' => FIN_SIM_KINDS[$kind] . ' — …']);
    switch ($kind) {
        case 'hire':
            $h .= $f('gross', 'Salário bruto mensal (€)', 'num') . $f('meal', 'Subsídio de refeição (€/dia)', 'num') . $f('meal_type', 'Refeição em', 'select', ['opts' => ['card' => 'Cartão', 'cash' => 'Dinheiro']])
                . $f('start', 'Data de início', 'date') . $f('equipment', 'Equipamento inicial (€ s/ IVA)', 'num') . $f('equipment_type', 'Tipo de equipamento', 'select', ['opts' => array_intersect_key(FIN_INVEST_TYPES, array_flip(['it', 'av', 'furniture']))]);
            break;
        case 'office':
            $h .= $f('rent', 'Renda mensal (€)', 'num') . $f('deposit_months', 'Caução (meses)', 'num') . $f('expenses', 'Despesas fixas por mês (€ s/ IVA)', 'num', ['hint' => 'eletricidade, água, internet, condomínio, limpeza, seguro'])
                . $f('works', 'Obras e mobiliário (€ s/ IVA)', 'num') . $f('landlord_vat', 'IVA na renda', 'check', ['text' => 'O senhorio renunciou à isenção (renda com IVA)'])
                . $f('landlord_wh', 'Retenção na renda', 'check', ['text' => 'Senhorio com contabilidade organizada / empresa']) . $f('start', 'Data de início', 'date');
            break;
        case 'invest':
            $h .= $f('type', 'O quê', 'select', ['opts' => FIN_INVEST_TYPES]) . $f('mode', 'Pagamento', 'select', ['opts' => ['once' => 'De uma vez', 'monthly' => 'Mensal']]) . $f('value', 'Valor (€ s/ IVA)', 'num')
                . $f('vat_deductible', 'IVA', 'check', ['text' => 'IVA dedutível']) . $f('months', 'Duração (meses, se mensal)', 'num');
            break;
        case 'car':
            $h .= $f('car_type', 'Tipo', 'select', ['opts' => ['passenger' => 'Ligeiro de passageiros', 'commercial' => 'Comercial']]) . $f('motor', 'Motorização', 'select', ['opts' => FIN_CAR_MOTORS])
                . $f('price', 'Preço sem IVA (€)', 'num') . $f('buy', 'Aquisição', 'select', ['opts' => FIN_CAR_BUY]) . $f('down', 'Entrada (crédito, €)', 'num') . $f('credit_rate', 'Taxa do crédito (%/ano)', 'num')
                . $f('credit_months', 'Prazo do crédito (meses)', 'num') . $f('renting', 'Renda do renting (€/mês s/ IVA)', 'num') . $f('km', 'Km por ano', 'num')
                . $f('consumption', 'Consumo por 100 km (L ou kWh)', 'num', ['ph' => 'por omissão']) . $f('energy_price', 'Preço por L ou kWh (c/ IVA)', 'num', ['ph' => 'por omissão'])
                . $f('insurance', 'Seguro por ano (€)', 'num') . $f('maintenance', 'Manutenção por ano (€)', 'num') . $f('tolls', 'Portagens e estacionamento por ano (€)', 'num')
                . $f('phev_ok', 'Plug-in', 'check', ['text' => 'Cumpre os requisitos das taxas reduzidas']);
            break;
    }
    return $h . '</div>';
}

/** O resultado do simulador (também devolvido pela API para atualizar enquanto se escreve). */
function fin_sim_result_html(PDO $pdo, string $kind, array $r): string {
    $rw = fn(?float $m) => $m === null ? '—' : fin_num($m, 1) . ' meses';
    $h = '<div class="stats fin-sim-kpis">'
       . '<div class="stat hero"><div class="k">Custo mensal real</div><div class="v">' . eur0($r['monthly']) . '</div><div class="n">' . ($r['cash_monthly'] != $r['monthly'] ? 'sai da conta ' . eur0($r['cash_monthly']) . '/mês' : 'por mês') . '</div></div>'
       . '<div class="stat"><div class="k">Custo anual</div><div class="v">' . eur0($r['annual']) . '</div><div class="n">poupança de IRC ' . eur0($r['irc_saving']) . ' · líquido ' . eur0($r['net_annual']) . '</div></div>'
       . '<div class="stat"><div class="k">De uma vez</div><div class="v">' . eur0($r['oneoff']) . '</div><div class="n">sai do saldo já</div></div>'
       . '<div class="stat' . ($r['runway_after'] !== null && $r['runway_after'] < fin_p($pdo, 'runway_alert', 6) ? ' risk' : '') . '"><div class="k">Runway útil</div><div class="v">' . e($rw($r['runway_after'])) . '</div><div class="n">antes: ' . e($rw($r['runway_before'])) . ($r['reserve_after'] ? ' · entra na reserva em ' . e(month_year((int)substr($r['reserve_after'], 5, 2), (int)substr($r['reserve_after'], 0, 4))) : '') . '</div></div>'
       . '<div class="stat"><div class="k">Receita extra para manter</div><div class="v">' . eur0($r['extra_revenue']) . '</div><div class="n">por mês · margem média ' . fin_num($r['margin'] * 100, 0) . '% (' . e($r['margin_from']) . ')</div></div>'
       . '</div>';
    if ($r['lines']) {
        $h .= '<div class="tablewrap"><table><tbody>';
        foreach ($r['lines'] as [$a, $v, $n]) $h .= '<tr><td>' . e($a) . '</td><td class="num">' . eur((float)$v) . '</td><td class="muted small">' . e((string)$n) . '</td></tr>';
        $h .= '</tbody></table></div>';
    }
    if (!empty($r['compare'])) {
        $h .= '<h3>Comparar motorizações</h3><div class="tablewrap"><table><thead><tr><th>Motorização</th><th class="num">Custo mensal</th><th class="num" data-tip="Tributação autónoma por ano">TA/ano</th><th class="num">IRC poupado/ano</th><th class="num">4 anos</th><th class="num">4 anos − IRC</th></tr></thead><tbody>';
        $best = min(array_column($r['compare'], 'four_net'));
        foreach ($r['compare'] as $m => $c) $h .= '<tr' . ($c['four_net'] == $best ? ' class="best"' : '') . '><td>' . e($c['label']) . ($c['four_net'] == $best ? ' <span class="chip ok">mais barato</span>' : '') . '</td><td class="num">' . eur0($c['monthly']) . '</td><td class="num">' . eur0($c['ta']) . '</td><td class="num">' . eur0($c['saving']) . '</td><td class="num">' . eur0($c['four']) . '</td><td class="num b">' . eur0($c['four_net']) . '</td></tr>';
        $h .= '</tbody></table></div><p class="small muted">As outras motorizações usam consumos típicos; ajuste o consumo para a escolhida.</p>';
    }
    foreach ($r['notes'] as $n) $h .= '<p class="small muted">· ' . e($n) . '</p>';
    $h .= '<div class="flash fin-warn">Estimativa com as regras de ' . fin_rules_year($pdo) . '. Confirma com o contabilista antes de decidir.</div>';
    return $h;
}

// ── ficha de salário (também na ficha do colaborador, em Equipa) ──

function fin_emp_form(PDO $pdo, int $uid, ?array $e, string $back): string {
    require_once __DIR__ . '/fin_pay.php';
    $e = $e ?? ['base_salary' => '', 'meal_value' => fin_p($pdo, 'meal_card_exempt', 10.46), 'meal_type' => 'card', 'meal_days' => 22, 'irs_status' => 'single', 'dependents' => 0, 'dep_disabled' => 0,
                'disability' => 0, 'residence' => 'continente', 'duodecimos' => 0, 'iban' => '', 'nif' => '', 'niss' => '', 'contract_type' => '', 'start_date' => '', 'active' => 1];
    $n = fn($k) => $e[$k] === '' || $e[$k] === null ? '' : e(number_format((float)$e[$k], 2, ',', ''));
    return '<form method="post" action="/admin/financeiro.php" class="fin-emp">' . csrf_field() . '<input type="hidden" name="do" value="emp_save"><input type="hidden" name="uid" value="' . $uid . '"><input type="hidden" name="back" value="' . e($back) . '">'
        . '<div class="grid">'
        . '<div class="field"><label for="em-b">Vencimento base bruto (€/mês)</label><input type="text" id="em-b" name="base_salary" inputmode="decimal" value="' . $n('base_salary') . '"></div>'
        . '<div class="field"><label for="em-mv">Subsídio de refeição (€/dia)</label><input type="text" id="em-mv" name="meal_value" inputmode="decimal" value="' . $n('meal_value') . '"></div>'
        . '<div class="field"><label for="em-mt">Refeição em</label><select id="em-mt" name="meal_type">' . fin_opts(['card' => 'Cartão (isento até ' . fin_num(fin_p($pdo, 'meal_card_exempt', 10.46)) . ' €/dia)', 'cash' => 'Dinheiro (isento até ' . fin_num(fin_p($pdo, 'meal_cash_exempt', 6.15)) . ' €/dia)'], $e['meal_type']) . '</select></div>'
        . '<div class="field"><label for="em-md">Dias de refeição por mês</label><input type="number" id="em-md" name="meal_days" min="0" max="31" value="' . (int)$e['meal_days'] . '"></div>'
        . '<div class="field"><label for="em-is">Situação para IRS</label><select id="em-is" name="irs_status">' . fin_opts(FIN_IRS_STATUS, $e['irs_status']) . '</select></div>'
        . '<div class="field"><label for="em-dp">Dependentes</label><input type="number" id="em-dp" name="dependents" min="0" max="20" value="' . (int)$e['dependents'] . '"></div>'
        . '<div class="field"><label for="em-dd">Dependentes com incapacidade ≥ 60%</label><input type="number" id="em-dd" name="dep_disabled" min="0" max="20" value="' . (int)$e['dep_disabled'] . '"></div>'
        . '<div class="field"><label class="check" style="margin-top:1.6rem"><input type="checkbox" name="disability" value="1"' . ((int)$e['disability'] ? ' checked' : '') . '> Pessoa com deficiência (≥ 60%)</label></div>'
        . '<div class="field"><label for="em-r">Residência</label><select id="em-r" name="residence">' . fin_opts(FIN_RESIDENCE, $e['residence']) . '</select></div>'
        . '<div class="field"><label class="check" style="margin-top:1.6rem"><input type="checkbox" name="duodecimos" value="1"' . ((int)$e['duodecimos'] ? ' checked' : '') . '> Subsídios em duodécimos</label></div>'
        . '<div class="field"><label for="em-nif">NIF</label><input type="text" id="em-nif" name="nif" inputmode="numeric" maxlength="9" value="' . e((string)$e['nif']) . '" autocomplete="off"></div>'
        . '<div class="field"><label for="em-niss">NISS</label><input type="text" id="em-niss" name="niss" inputmode="numeric" maxlength="11" value="' . e((string)$e['niss']) . '" autocomplete="off"></div>'
        . '<div class="field"><label for="em-iban">IBAN</label><input type="text" id="em-iban" name="iban" maxlength="40" value="' . e((string)$e['iban']) . '" autocomplete="off"></div>'
        . '<div class="field"><label for="em-ct">Contrato</label><select id="em-ct" name="contract_type">' . fin_opts(FIN_CONTRACTS, $e['contract_type']) . '</select></div>'
        . '<div class="field"><label for="em-sd">Data de entrada</label><input type="date" id="em-sd" name="start_date" value="' . e((string)$e['start_date']) . '"></div>'
        . '<div class="field"><input type="hidden" name="active" value="0"><label class="check" style="margin-top:1.6rem"><input type="checkbox" name="active" value="1"' . ((int)$e['active'] ? ' checked' : '') . '> Processar salário</label></div>'
        . '</div><div class="actions"><button class="btn sm" type="submit">Guardar ficha de salário</button></div></form>';
}

/** O resumo da ficha (custo anual, avisos) para mostrar junto do formulário. */
function fin_emp_summary(PDO $pdo, ?array $e): string {
    if (!$e) return '<p class="small muted">Sem ficha de salário.</p>';
    require_once __DIR__ . '/fin_pay.php';
    $a = fin_employee_annual($pdo, $e);
    $h = '<p class="small">Custo anual para a empresa: <b>' . eur0($a['annual']) . '</b> <span class="muted">(14 × bruto × ' . fin_num(1 + fin_p($pdo, 'ss_employer', 23.75) / 100, 4) . ' + refeição ' . eur0($a['meal']) . ($a['insurance'] > 0 ? ' + seguro e FCT ' . eur0($a['insurance']) : '') . ') · ' . eur0($a['monthly']) . '/mês</span></p>';
    foreach (fin_employee_warnings($pdo, $e) as $w) $h .= '<p class="small neg">' . e($w) . '</p>';
    return $h;
}

// ── «Por confirmar · emails» (§28.16) ──────────────────────

/** Os emails por confirmar de um lado: 'out' (A pagar), 'in' (A receber) ou 'all'. Os indecisos aparecem nos dois. */
function fin_inbox_rows(PDO $pdo, string $side): array {
    if (!fin_ready($pdo)) return [];
    $hasDir = db_has_column($pdo, 'fin_mail', 'direction');
    $where = "status = 'review'" . ($hasDir && $side !== 'all' ? " AND direction IN ('" . ($side === 'in' ? 'in' : 'out') . "', '')" : '');
    return $pdo->query("SELECT * FROM fin_mail WHERE $where ORDER BY id DESC LIMIT 30")->fetchAll();
}

/** Quantos emails por confirmar em cada separador (o indeciso conta nos dois). */
function fin_inbox_counts(PDO $pdo): array {
    $c = ['in' => 0, 'out' => 0, 'all' => 0];
    if (!fin_ready($pdo)) return $c;
    $hasDir = db_has_column($pdo, 'fin_mail', 'direction');
    foreach ($pdo->query("SELECT " . ($hasDir ? 'direction' : "''") . " AS d, COUNT(*) AS n FROM fin_mail WHERE status = 'review' GROUP BY d")->fetchAll() as $r) {
        $c['all'] += (int)$r['n'];
        if ($r['d'] !== 'in') $c['out'] += (int)$r['n'];
        if ($r['d'] !== 'out') $c['in'] += (int)$r['n'];
    }
    return $c;
}

/**
 * O painel «Por confirmar · emails»: cada cartão com remetente, caixa, data, assunto, o texto do email, o anexo
 * (miniatura + «Abrir PDF» num painel lateral), os valores lidos, o motivo, a sugestão (com o ícone para trocar
 * A pagar ⇄ A receber) e as ações. $side: 'out' | 'in' | 'all'.
 */
function fin_inbox_html(PDO $pdo, string $side, bool $canEdit, bool $compact = false): string {
    $rows = fin_inbox_rows($pdo, $side);
    if (!$rows) return '';
    $h = '<section class="panel fin-inbox" aria-labelledby="h-inb-' . $side . '"><div class="fin-inbox-h"><h3 id="h-inb-' . $side . '">' . icon('mail') . ' Por confirmar · emails <span class="nb">' . count($rows) . '</span></h3>'
       . '<p class="small muted">' . ($side === 'out' ? 'Despesas que chegaram por email (e as que não sei de que lado são).' : ($side === 'in' ? 'Faturas emitidas pela empresa que chegaram por email (e as que não sei de que lado são).' : 'Documentos que chegaram por email e esperam por si.')) . '</p></div>';
    foreach ($rows as $m) $h .= fin_inbox_card($pdo, $m, $canEdit, $compact);
    return $h . '</section>' . fin_pdf_panel_html();
}

/** Um cartão do «Por confirmar · emails». */
function fin_inbox_card(PDO $pdo, array $m, bool $canEdit, bool $compact): string {
    $id = (int)$m['id'];
    $ai = json_decode((string)$m['ai'], true) ?: [];
    $att = json_decode((string)$m['att'], true) ?: [];
    $sug = json_decode((string)$m['suggestions'], true) ?: [];
    $dir = (string)($m['direction'] ?? '');
    $csrf = csrf_field();
    $post = fn(string $do, string $label, string $cls = 'btn ghost sm', array $extra = []) => '<form method="post" class="inl">' . $csrf . '<input type="hidden" name="do" value="' . $do . '"><input type="hidden" name="mail" value="' . $id . '">'
        . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">', array_keys($extra), $extra)) . '<button class="' . $cls . '" type="submit">' . $label . '</button></form>';
    // a sugestão e o ícone discreto para a trocar (vira regra para o mesmo emitente)
    $dirChip = $dir === 'out' ? '<span class="chip wait">Despesa · A pagar</span>' : ($dir === 'in' ? '<span class="chip info">Fatura emitida · A receber</span>' : '<span class="chip">Despesa ou receita?</span>');
    $swap = '';
    if ($canEdit) {
        $to = $dir === 'out' ? 'in' : 'out';
        $tip = $dir === '' ? 'Marcar como despesa (A pagar)' : ($to === 'in' ? 'Passar para A receber (fatura emitida)' : 'Passar para A pagar (despesa)');
        $swap = '<form method="post" class="inl">' . $csrf . '<input type="hidden" name="do" value="mail_dir"><input type="hidden" name="mail" value="' . $id . '"><input type="hidden" name="dir" value="' . $to . '">'
              . '<button type="submit" class="ibtn fin-swap" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '">' . icon('arrows') . '</button></form>';
        if ($dir === '') $swap .= '<form method="post" class="inl">' . $csrf . '<input type="hidden" name="do" value="mail_dir"><input type="hidden" name="mail" value="' . $id . '"><input type="hidden" name="dir" value="in"><button type="submit" class="btn ghost xs">É receita</button></form>';
    }
    // o anexo: miniatura (a primeira página) e «Abrir PDF» no painel lateral
    $prev = '';
    foreach ($att as $i => $a) {
        if (($a['key'] ?? '') === '') { $prev .= '<div class="fmc-thumb none small muted">O anexo não ficou guardado' . (!empty($a['error']) ? ': ' . e($a['error']) : '') . '</div>'; continue; }
        $src = fin_file_href('mail', $id) . '&n=' . $i;
        $isPdf = ($a['mime'] ?? '') === 'application/pdf';
        $prev .= '<div class="fmc-att"><button type="button" class="fmc-thumb" data-pdf-open="' . e($src) . '" data-pdf-title="' . e((string)$a['name']) . '" aria-label="Abrir ' . e((string)$a['name']) . '">'
               . ($isPdf ? '<iframe src="' . e($src) . '#toolbar=0&navpanes=0&scrollbar=0&view=FitH" title="Pré-visualização de ' . e((string)$a['name']) . '" tabindex="-1" loading="lazy"></iframe>'
                         : '<img src="' . e($src) . '" alt="" loading="lazy">') . '</button>'
               . '<button type="button" class="btn sm fmc-open" data-pdf-open="' . e($src) . '" data-pdf-title="' . e((string)$a['name']) . '">' . icon('eye') . ' Abrir ' . ($isPdf ? 'PDF' : 'anexo') . '</button>'
               . '<span class="small muted fmc-fn">' . e(shorten((string)$a['name'], 48)) . '</span></div>';
        break;
    }
    // os valores lidos
    $vals = '';
    if ($ai) {
        $src = ['qr' => 'QR fiscal', 'xml' => 'XML', 'ai' => 'IA', 'text' => 'texto do PDF'][$ai['source'] ?? ''] ?? 'IA';
        $nif = fn($n) => $n !== '' ? ' <span class="mono">' . e($n) . '</span>' : '';
        $row = fn(string $k, string $v) => $v !== '' ? '<dt>' . e($k) . '</dt><dd>' . $v . '</dd>' : '';
        $vals = '<dl class="fmc-vals">'
              . $row('Emitente', e((string)($ai['issuer_name'] ?? '') ?: '—') . $nif((string)($ai['issuer_nif'] ?? '')))
              . $row('Adquirente', e((string)($ai['client_name'] ?? '') ?: '—') . $nif((string)($ai['client_nif'] ?? '')))
              . $row('Nº', e((string)($ai['number'] ?? '')))
              . $row('Data', ($ai['issue_date'] ?? '') !== '' ? fin_date_pt((string)$ai['issue_date']) : '')
              . $row('Total', (float)($ai['total'] ?? 0) > 0 ? '<b>' . eur((float)$ai['total']) . '</b>' : '')
              . $row('IVA', (float)($ai['vat'] ?? 0) > 0 ? eur((float)$ai['vat']) . ((float)($ai['vat_rate'] ?? 0) > 0 ? ' (' . fin_num((float)$ai['vat_rate'], 0) . '%)' : '') : '')
              . $row('Lido de', e($src) . (($ai['source'] ?? '') === 'ai' ? ' · confiança ' . round((float)($ai['confidence'] ?? 0) * 100) . '%' : '') . (!empty($ai['atcud']) ? ' · ATCUD <span class="mono">' . e((string)$ai['atcud']) . '</span>' : ''))
              . '</dl>';
    }
    $body = trim((string)($m['body_text'] ?? ''));
    $text = $body !== '' ? '<details class="fmc-text"' . ($compact ? '' : ' open') . '><summary class="small">Texto do email</summary><p>' . nl2br(e($body)) . (mb_strlen($body) >= 1000 ? '…' : '') . '</p></details>' : '';
    // as ações: a principal segue a sugestão
    $act = '';
    if ($canEdit) {
        if ($sug) {
            $act .= '<form method="post" class="inl">' . $csrf . '<input type="hidden" name="do" value="mail_confirm"><input type="hidden" name="mail" value="' . $id . '">'
                  . '<select name="doc" aria-label="Pedido">' . implode('', array_map(fn($s) => '<option value="' . (int)$s['id'] . '">' . e($s['label'] . ' (' . $s['why'] . ')') . '</option>', $sug)) . '</select>'
                  . '<button class="btn sm" type="submit">Ligar a este pedido</button></form>';
        }
        $act .= $post('mail_new', 'Confirmar como despesa', $dir === 'out' && !$sug ? 'btn sm' : 'btn ghost sm', ['dir' => 'out'])
              . $post('mail_new', 'Confirmar como fatura emitida', $dir === 'in' && !$sug ? 'btn sm' : 'btn ghost sm', ['dir' => 'in'])
              . $post('mail_reread', icon('refresh') . ' Ler outra vez', 'btn ghost sm')
              . '<details class="fmc-ign"><summary class="btn ghost sm">Pôr de parte</summary><form method="post" class="inl">' . $csrf . '<input type="hidden" name="do" value="mail_ignore"><input type="hidden" name="mail" value="' . $id . '">'
              . '<label class="sr" for="ign-' . $id . '">Motivo</label><input type="text" id="ign-' . $id . '" name="why" maxlength="200" required placeholder="Motivo (ex.: não é da empresa, repetido)"><button class="btn sm" type="submit">Pôr de parte</button></form></details>';
    }
    return '<article class="fmc" id="fm-' . $id . '">'
         . ($prev !== '' ? '<div class="fmc-prev">' . $prev . '</div>' : '')
         . '<div class="fmc-main"><header class="fmc-h"><div><b class="fmc-subj">' . e((string)$m['subject'] ?: '(sem assunto)') . '</b>'
         . '<div class="small muted">de <b>' . e((string)$m['from_addr']) . '</b>' . (($m['box'] ?? '') !== '' ? ' · em ' . e((string)$m['box']) : '') . ($m['received_at'] ? ' · ' . e(date('d/m/Y H:i', strtotime((string)$m['received_at']))) : '') . '</div></div>'
         . '<div class="fmc-dir">' . $dirChip . $swap . '</div></header>'
         . '<p class="fmc-why small">' . icon('alert') . ' ' . e((string)$m['note']) . '</p>'
         . $vals . $text
         . ($act !== '' ? '<div class="fin-mail-act">' . $act . '</div>' : '') . '</div></article>';
}

/** O painel lateral para ver o PDF (um por página). */
function fin_pdf_panel_html(): string {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<div class="fin-pdfp" data-pdf-panel hidden role="dialog" aria-modal="true" aria-labelledby="fin-pdfp-t"><div class="fin-pdfp-back" data-pdf-close></div>'
         . '<aside class="fin-pdfp-box"><header><b id="fin-pdfp-t"></b><a class="btn ghost sm" data-pdf-new target="_blank" rel="noopener">' . icon('external') . ' Abrir noutro separador</a>'
         . '<button type="button" class="ibtn" data-pdf-close aria-label="Fechar" data-tip="Fechar (Esc)">' . icon('x') . '</button></header><iframe title="Documento" data-pdf-frame></iframe></aside></div>';
}

/** O ícone discreto para passar um lançamento entre A pagar e A receber (com «Anular»). */
function fin_flip_btn(array $d): string {
    if ((float)$d['paid_amount'] > 0 || $d['payroll_id'] || $d['fixed_id'] || $d['billing_id'] || $d['deleted_at']) return '';
    $tip = $d['direction'] === 'out' ? 'Não é despesa: passar para A receber' : 'Não é receita: passar para A pagar';
    return '<form method="post" class="inl fin-flip">' . csrf_field() . '<input type="hidden" name="do" value="doc_flip"><input type="hidden" name="id" value="' . (int)$d['id'] . '">'
         . '<button type="submit" class="ibtn fin-swap" data-tip="' . e($tip) . '" aria-label="' . e($tip) . '">' . icon('arrows') . '</button></form>';
}
