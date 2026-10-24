<?php
/**
 * topper. admin — o negócio no Pipeline (§19.8)
 *
 * O painel do negócio, igual ao da lead (§12.5) e mais completo: à esquerda o resumo, os
 * responsáveis (comercial e execução), as datas e os atalhos (X-Ray, folha de orçamento,
 * proposta, ficha na aba Negócios, pasta no Drive); à direita a barra de etapas com os
 * requisitos de cada gate (✓/✗), a proposta e a resposta do cliente, a faturação, as rondas,
 * Nova ação, Foco e Notas & Histórico. Os formulários gravam em deal_flow.php, que volta
 * a verificar tudo no servidor.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/pipeline_flow.php';
require_once __DIR__ . '/interactions.php';
require_once __DIR__ . '/reach.php';
require_once __DIR__ . '/inline.php';
require_once __DIR__ . '/lead_view.php';   // deal_people_link
require_once __DIR__ . '/budget.php';

/** Quem pode mudar o negócio. */
function flow_can_edit(PDO $pdo, array $deal): bool {
    return record_reach($pdo, $deal, PERM_EDIT);
}

/** O responsável comercial (ou um admin): valida o X-Ray e a resposta do cliente. */
function flow_is_commercial(array $deal): bool {
    $u = current_user();
    return $u && ((int)$deal['owner_id'] === (int)$u['id'] || is_admin());
}

/** Faturas e pagamentos: o comercial, quem edita o Financeiro, ou um admin. */
function flow_can_bill(PDO $pdo, array $deal): bool {
    return flow_can_edit($pdo, $deal) && (flow_is_commercial($deal) || can('financeiro', PERM_EDIT));
}

/** Um formulário para deal_flow.php (POST com CSRF, o negócio e para onde voltar). */
function flow_form(string $do, array $deal, string $back, string $inner, array $o = []): string {
    return '<form method="post" action="/admin/deal_flow.php"' . (!empty($o['file']) ? ' enctype="multipart/form-data"' : '') . ' class="' . e($o['class'] ?? 'fl-form') . '">'
         . csrf_field() . '<input type="hidden" name="do" value="' . e($do) . '"><input type="hidden" name="id" value="' . (int)$deal['id'] . '">'
         . '<input type="hidden" name="back" value="' . e($back) . '" data-back-here>' . $inner . '</form>';
}

/** ✓ / ✗ de um requisito. */
function flow_req_html(array $i, ?array $deal = null, string $back = ''): string {
    $task = !empty($i['task']) ? ' <a class="lnk small" href="/admin/calendar.php?edit=' . (int)$i['task'] . '">tarefa</a>' : '';
    // Uma tarefa obrigatória por fazer conclui-se aqui mesmo (as de fatura/pagamento/X-Ray pedem o documento).
    if ($deal && !$i['ok'] && !empty($i['task']) && ($t = task_by_id($GLOBALS['pdo'], (int)$i['task'])) && flow_task_needs_doc($t) === '') {
        $task .= ' ' . flow_form('task_done', $deal, $back, '<input type="hidden" name="task" value="' . (int)$i['task'] . '"><button class="lnk small" type="submit">' . icon('check') . ' feita</button>', ['class' => 'inl']);
    }
    // Um requisito recomendado (ex.: pré-criativo, §23.3) aparece mas nunca trava.
    $done = !empty($i['soft']) ? !empty($i['done']) : $i['ok'];
    return '<li class="' . ($done ? 'ok' : (!empty($i['soft']) ? 'soft' : 'no')) . '"><span class="fl-ck" aria-hidden="true">' . ($done ? icon('check') : '') . '</span>'
         . '<span><span class="sr-only">' . ($done ? 'Cumprido: ' : (!empty($i['soft']) ? 'Recomendado: ' : 'Em falta: ')) . '</span>' . e($i['label']) . $task
         . (!$done && ($i['hint'] ?? '') !== '' ? '<small>' . e($i['hint']) . '</small>' : '') . '</span></li>';
}

/** A barra de etapas e os requisitos para sair da atual (com «Avançar» e, para admins, «Forçar»). */
function flow_stages_html(PDO $pdo, array $deal, string $back): string {
    if (!$deal['funnel_id'] || !$deal['stage_id']) return '';
    $order = flow_order($pdo, (int)$deal['funnel_id']);
    $i = flow_index($order, (int)$deal['stage_id']);
    if ($i === null) return '';
    $cur = $order[$i];
    $active = in_array($deal['phase'], ['pipeline', 'production'], true);
    if (!$active || !isset($order[$i + 1])) return '';
    $next = $order[$i + 1];
    $h = '<section class="lv-blk fl-stages"><h4>Para avançar <span class="h-sub">' . (!empty($deal['_service']) ? e($deal['_service']) . ': ' : '') . e($cur['name']) . ' → ' . e($next['name']) . '</span></h4>';
    $items = deal_gate($pdo, $deal, $cur);
    $missing = array_filter($items, fn($x) => !$x['ok']);
    $h .= '<div class="fl-gate">';
    $canDo = flow_can_edit($pdo, $deal);
    // Uma frente de crosselling (§22): os formulários levam a frente; as sincronizadas não concluem sozinhas.
    $line = !empty($deal['_line']) ? '<input type="hidden" name="line" value="' . (int)$deal['_line'] . '">' : '';
    $lineDone = $line !== '' && ($deal['sync_mode'] ?? '') === 'independent';
    $h .= $items ? '<ul class="fl-req">' . implode('', array_map(fn($x) => flow_req_html($x, $canDo ? $deal : null, $back), $items)) . '</ul>' : '<p class="muted small">Sem requisitos nesta etapa.</p>';
    if (flow_can_edit($pdo, $deal)) {
        $go = '<input type="hidden" name="stage_id" value="' . (int)$next['id'] . '">' . $line;
        if ($cur['role'] === 'feedback') {
            $h .= '<p class="muted small">Passa a Kickoff ao <b>validar</b> a aceitação do cliente (em «Proposta»). A recusa validada passa a Perdido.</p>';
        } elseif ($cur['role'] === 'proposal') {
            $h .= '<p class="muted small">Passa a «' . e($next['name']) . '» ao <b>enviar</b> a proposta ao cliente.</p>';
        } elseif ($next['kind'] === 'done' && !$lineDone) {
            $h .= '<p class="muted small">' . ($line !== '' ? 'Entrega conjunta: esta frente conclui com o negócio, na checklist de fecho.' : 'Conclui-se na ficha do negócio, com a checklist de fecho.') . '</p>';
        } else {
            $h .= flow_form('move', $deal, $back, $go . '<button class="btn sm" type="submit"' . ($missing ? ' disabled aria-disabled="true"' : '') . '>' . icon('arrow') . ' Avançar para ' . e($next['name']) . '</button>', ['class' => 'fl-go']);
        }
        if ($missing && is_admin() && ($line === '' || $next['kind'] !== 'done' || $lineDone)) {
            $target = $cur['role'] === 'feedback' || $next['kind'] === 'done' ? $next : $next;
            $h .= '<details class="fl-force"><summary>Forçar passagem (admins)</summary>'
                . flow_form('move', $deal, $back, '<input type="hidden" name="stage_id" value="' . (int)$target['id'] . '">' . $line
                  . '<label class="sr-only" for="ff-' . (int)$deal['id'] . '">Motivo</label>'
                  . '<textarea id="ff-' . (int)$deal['id'] . '" name="force" rows="2" maxlength="200" required placeholder="Motivo (fica no histórico do negócio)"></textarea>'
                  . '<button class="btn danger sm" type="submit">' . icon('alert') . ' Forçar para ' . e($target['name']) . '</button>')
                . '</details>';
        }
    }
    return $h . '</div></section>';
}

/**
 * As etapas do funil numa linha (no topo azul do painel): feitas ✓, a atual em destaque
 * («Etapa atual»), as seguintes apagadas. Comercial e produção separadas pelo Kickoff.
 */
function flow_stepper_html(PDO $pdo, array $deal, array $o = []): string {
    if (!$deal['funnel_id'] || !$deal['stage_id']) return '';
    $order = flow_order($pdo, (int)$deal['funnel_id']);
    $i = flow_index($order, (int)$deal['stage_id']);
    if ($i === null) return '';
    $lost = $deal['phase'] === 'lost';
    $h = '';
    foreach ($order as $k => $s) {
        $st = $deal['phase'] === 'done' || $k < $i ? 'done' : ($k === $i ? ($lost ? 'lost' : 'cur') : 'next');
        $tip = $s['name'] . ' · ' . ['done' => 'feita', 'cur' => 'etapa atual', 'next' => 'por fazer', 'lost' => 'perdido aqui'][$st];
        $inner = '<span class="dot" aria-hidden="true">' . ($st === 'done' ? icon('check') : '') . '</span>'
               . '<span class="nm">' . e($s['name']) . '</span>'
               . ($st === 'cur' ? '<small>Etapa atual</small>' : ($st === 'lost' ? '<small>Perdido</small>' : ''));
        // Na ficha (aba Negócios): as etapas de produção carregam-se para mudar de etapa (com os gates)
        // e «Concluído» abre a checklist de fecho.
        if (!empty($o['steps']) && $st !== 'cur') {
            if ($s['kind'] === 'production') {
                $inner = '<button class="fl-sb" name="step" value="s' . (int)$s['id'] . '" data-tip="' . e(($deal['phase'] === 'done' ? 'Reabrir em ' : 'Passar a ') . $s['name']) . '">' . $inner . '</button>';
                $tip = '';
            } elseif ($s['kind'] === 'done' && !empty($o['close'])) {
                $inner = '<button type="button" class="fl-sb" data-dmdl="#mdl-close" data-tip="Concluir: abre a checklist de fecho">' . $inner . '</button>';
                $tip = '';
            }
        }
        $h .= '<li class="' . $st . ' z-' . e($s['kind']) . '"' . ($st === 'cur' || $st === 'lost' ? ' aria-current="step"' : '') . ($tip !== '' ? ' data-tip="' . e($tip) . '"' : '') . '>' . $inner . '</li>';
    }
    $funnel = (string)$pdo->query('SELECT name FROM funnels WHERE id = ' . (int)$deal['funnel_id'])->fetchColumn();
    // Crosselling (§22): os serviços no topo; depois do Kickoff, onde está cada frente.
    require_once __DIR__ . '/xsell.php';
    $xs = empty($deal['_line']) ? xsell_label($pdo, $deal) : '';
    if ($xs !== '') {
        $funnel = $xs;
        if (in_array($deal['phase'], ['production', 'done'], true)) {
            require_once __DIR__ . '/xsell_view.php';
            return '<div class="fl-steps"><span class="fl-fn">Crosselling · ' . e(XSELL_SYNC[$deal['sync_mode']] ?? '') . '</span><p class="xs-sum small">' . e(xsell_lanes_short($pdo, $deal)) . '</p></div>';
        }
    }
    return '<div class="fl-steps"><span class="fl-fn">' . e($funnel) . ' · etapa ' . ($i + 1) . ' de ' . count($order) . '</span>'
         . '<ol class="fl-step" aria-label="Etapas do funil ' . e($funnel) . '">' . $h . '</ol></div>';
}

/** A proposta e a resposta do cliente (§19.4). */
function flow_proposal_html(PDO $pdo, array $deal, string $back): string {
    if (!db_has_table($pdo, 'proposals') || !$deal['stage_id']) return '';
    $stage = stage_get($pdo, (int)$deal['stage_id']);
    $p = proposal_latest($pdo, (int)$deal['id']);
    $inCommercial = $deal['phase'] === 'pipeline' && $stage && in_array($stage['role'], ['proposal', 'feedback'], true);
    if (!$p && !$inCommercial) return '';
    $edit = flow_can_edit($pdo, $deal);
    $h = '<section class="lv-blk fl-prop" id="proposta"><h4>Proposta <span class="h-sub">opções, envio e resposta do cliente</span></h4>';
    [$sheet, $v] = proposal_version($pdo, $deal);
    if ($p) {
        $sent = '<p class="small">Enviada a <b>' . e($p['sent_to']) . '</b> em ' . e(date('d/m/Y H:i', strtotime((string)$p['sent_at'])))
              . ' · válida até ' . e(date('d/m/Y', strtotime((string)$p['expires_on'])))
              . ' <a class="lnk" href="/admin/orcamento_pdf.php?v=' . (int)$p['version_id'] . '&amp;ver=1" target="_blank" rel="noopener">PDF ' . icon('external') . '</a></p>';
        $h .= $sent;
        if (in_array($p['status'], ['answered', 'contact'], true) && $p['decision'] !== '') {
            $h .= '<div class="fl-answer ' . ($p['decision'] === 'accept' ? 'acc' : 'ref') . '"><b>Resposta do cliente: ' . e(proposal_answer_label($p)) . '</b>'
                . '<small>' . e($p['signer_name']) . ' · ' . e(date('d/m/Y H:i', strtotime((string)$p['answered_at']))) . ((int)$p['answers'] > 1 ? ' · mudou a resposta ' . ((int)$p['answers'] - 1) . '×' : '')
                . ($p['status'] === 'contact' ? ' · em contacto com o cliente' : '') . '</small>'
                . '<p class="muted small">Nada avança sozinho: confirme e valide. Um clique por engano resolve-se com «Contactar cliente».</p>';
            if ($edit && flow_is_commercial($deal)) {
                $h .= '<div class="actions">' . flow_form('prop_validate', $deal, $back, '<button class="btn sm" type="submit">' . icon('check') . ' Validar</button>', ['class' => 'inl'])
                    . ($p['status'] === 'answered' ? flow_form('prop_contact', $deal, $back, '<button class="btn ghost sm" type="submit">' . icon('phone') . ' Contactar cliente</button>', ['class' => 'inl']) : '')
                    . '</div>';
            } else {
                $h .= '<p class="muted small">Quem valida: o responsável comercial ou um admin.</p>';
            }
            $h .= '</div>';
        } elseif ($p['status'] === 'validated') {
            $h .= '<p class="fl-answer ' . ($p['decision'] === 'accept' ? 'acc' : 'ref') . '"><b>' . ($p['decision'] === 'accept' ? 'Aceitação validada' : 'Recusa validada') . '</b>'
                . '<small>' . e(proposal_answer_label($p)) . ' · ' . e($p['signer_name']) . ' · validada por ' . e(flow_user_name($pdo, $p['validated_by']) ?: '—') . ' em ' . e(date('d/m/Y', strtotime((string)$p['validated_at']))) . '</small></p>';
        } elseif ($p['status'] === 'sent') {
            $h .= '<p class="muted small">A aguardar a resposta do cliente. O cliente responde numa página segura: abrir o link não altera nada.</p>';
        }
    }
    // Enviar (ou reenviar com uma versão nova)
    if ($edit && $inCommercial && (!$p || $p['status'] !== 'validated')) {
        $reqs = proposal_requirements($pdo, $deal);
        $missing = count(array_filter($reqs, fn($r) => !$r['ok']));
        $h .= '<div class="fl-send"><ul class="fl-req">' . implode('', array_map('flow_req_html', $reqs)) . '</ul>'
            . '<div class="actions"><button type="button" class="btn sm" data-new="#mdl-psend">' . icon('send') . ' ' . ($p ? 'Reenviar a proposta' : 'Pré-visualizar e enviar') . ($v ? ' · v' . (int)$v['num'] : '') . '</button>'
            . ($sheet && can('orcamentacao') ? '<a class="lnk small" href="/admin/orcamento.php?id=' . (int)$deal['id'] . '#proposta">' . icon('orcamentacao') . ' Folha de orçamento</a>' : '') . '</div>'
            . ($missing ? '<p class="muted small">Falta' . ($missing > 1 ? 'm' : '') . ' ' . $missing . ' requisito' . ($missing > 1 ? 's' : '') . ' para poder enviar.</p>' : '')
            . '</div>';
    }
    return $h . '</section>';
}

/** Pode enviar a proposta daqui? [sim?, porque não]. A mesma regra no painel, na ficha e na folha de orçamento. */
function flow_can_send(PDO $pdo, array $deal): array {
    if (!db_has_table($pdo, 'proposals') || !$deal['stage_id'] || $deal['phase'] !== 'pipeline') return [false, 'Só um negócio no Pipeline envia propostas.'];
    if (!flow_can_edit($pdo, $deal)) return [false, 'Sem permissão para mudar este negócio.'];
    $stage = stage_get($pdo, (int)$deal['stage_id']);
    if (!$stage || !in_array($stage['role'], ['proposal', 'feedback'], true)) return [false, 'O negócio está em «' . ($stage['name'] ?? '—') . '»: a proposta envia-se a partir da etapa Proposta.'];
    $p = proposal_latest($pdo, (int)$deal['id']);
    if ($p && $p['status'] === 'validated') return [false, 'A resposta do cliente já foi validada.'];
    return [true, ''];
}

/**
 * «Enviar a proposta ao cliente» (§19.4): pré-visualização antes de enviar — requisitos,
 * para quem, mensagem opcional, o email tal como o cliente o recebe, as opções e o PDF.
 */
function flow_send_modal_html(PDO $pdo, array $deal, string $back): string {
    [$ok] = flow_can_send($pdo, $deal);
    if (!$ok) return '';
    require_once __DIR__ . '/budget.php';
    [$sheet, $v] = proposal_version($pdo, $deal);
    $reqs = proposal_requirements($pdo, $deal);
    $ready = $v && !array_filter($reqs, fn($r) => !$r['ok']);
    $p = proposal_latest($pdo, (int)$deal['id']);
    $email = trim((string)$deal['email']);
    if ($email === '' && $deal['contact_id']) $email = (string)$pdo->query('SELECT email FROM contacts WHERE id = ' . (int)$deal['contact_id'])->fetchColumn();
    $id = (int)$deal['id'];
    $prev = '';
    if ($v) {
        $content = proposal_public_content($pdo, $deal, $v);
        $m = proposal_email($pdo, $deal, $v, $content, (int)current_user()['id']);
        $opts = '';
        foreach ($content['options'] as $o) {
            $opts .= '<li><b>' . e($o['label']) . '</b><span class="mono">' . e(eur((float)$o['price'])) . ' <small>s/ IVA</small></span><span class="mono muted">' . e(eur((float)$o['total'])) . ' c/ IVA</span></li>';
        }
        $prev = '<div class="ps-mail" aria-label="Pré-visualização do email">'
              . '<div class="ps-hd"><span>Para</span><b data-psend-to>' . e($email !== '' ? $email : '—') . '</b><span>Assunto</span><b>' . e($m['subject']) . '</b></div>'
              . '<div class="ps-body"><p>' . e($m['hello']) . '</p><p>' . e($m['intro']) . '</p><p class="ps-note" data-psend-note hidden></p>'
              . '<p class="ps-cta"><span class="ps-btn">Ver e responder à proposta</span></p><p class="ps-small">' . e($m['valid']) . '</p><p class="ps-sign">' . nl2br(e($m['sign'])) . '</p></div></div>'
              . '<div class="ps-opts"><h5>No link, o cliente vê</h5><ul>' . $opts . '</ul>'
              . '<p class="small"><a class="lnk" href="/admin/orcamento_pdf.php?v=' . (int)$v['id'] . '&amp;ver=1" target="_blank" rel="noopener">' . icon('file') . ' Ver o PDF da proposta ' . icon('external') . '</a></p></div>';
    }
    return '<div class="mdl" id="mdl-psend" role="dialog" aria-modal="true" aria-labelledby="mdl-psend-t" hidden>
  <div class="mdl-back" data-mdl-close></div>
  <form class="mdl-card wide ps" method="post" action="/admin/deal_flow.php" autocomplete="off">
    ' . csrf_field() . '<input type="hidden" name="do" value="prop_send"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="back" value="' . e($back) . '" data-back-here>
    <h3 id="mdl-psend-t">' . icon('send') . ' ' . ($p ? 'Reenviar' : 'Enviar') . ' a proposta · ' . e((string)$deal['code']) . ($v ? ' · v' . (int)$v['num'] : '') . '</h3>
    <div class="ps-cols">
      <div class="ps-l">
        <ul class="fl-req">' . implode('', array_map('flow_req_html', $reqs)) . '</ul>
        <div class="field"><label for="ps-to-' . $id . '">Email do cliente</label><input type="email" id="ps-to-' . $id . '" name="email" required maxlength="190" value="' . e($email) . '" placeholder="cliente@empresa.pt" data-psend-email></div>
        <div class="field"><label for="ps-msg-' . $id . '">Mensagem <span class="muted">(opcional: entra no email)</span></label><textarea id="ps-msg-' . $id . '" name="note" rows="4" maxlength="2000" placeholder="Ex.: Como falámos na reunião, juntámos uma segunda opção com…" data-psend-msg></textarea></div>
        <p class="muted small">O cliente responde numa página segura; abrir o link não altera nada.' . ($p ? ' <b>O link enviado antes deixa de funcionar.</b>' : '') . ' Enviar passa o negócio para «Aguardar feedback».</p>
      </div>
      <div class="ps-r">' . ($prev !== '' ? $prev : '<p class="muted">Ainda não há versão do orçamento.</p>') . '</div>
    </div>
    <div class="actions"><button type="button" class="btn ghost" data-mdl-close>Cancelar</button><span class="spacer"></span>'
      . ($ready ? '' : '<span class="muted small">Cumpra os requisitos para enviar.</span>')
      . '<button class="btn" type="submit"' . ($ready ? '' : ' disabled aria-disabled="true"') . '>' . icon('send') . ' Enviar proposta</button></div>
  </form>
</div>';
}

/** Faturação (§19.6 E): as tarefas que pedem documento e os documentos submetidos. */
function flow_billing_html(PDO $pdo, array $deal, string $back): string {
    if (!db_has_table($pdo, 'deal_billing') || !flow_can_bill($pdo, $deal)) return '';
    $st = $pdo->prepare("SELECT * FROM tasks WHERE deal_id = ? AND doc_kind IN ('invoice','payment') AND status <> 'done' ORDER BY due_date IS NULL, due_date, id");
    $st->execute([(int)$deal['id']]);
    $open = $st->fetchAll();
    $docs = billing_list($pdo, (int)$deal['id']);
    if (!$open && !$docs) return '';
    $vat = vat_rate_flow($pdo);
    $h = '<section class="lv-blk fl-bill" id="faturacao"><h4>Faturação <span class="h-sub">a topper. ainda não emite faturas aqui</span></h4>';
    // Financeiro (§28.14): o pedido ao contabilista, se houver um à espera, e o atalho para pedir
    $req = '';
    if (db_has_table($pdo, 'fin_docs')) {
        $rq = $pdo->prepare("SELECT req_code, req_sent_at FROM fin_docs WHERE deal_id = ? AND direction = 'in' AND status = 'requested' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
        $rq->execute([(int)$deal['id']]);
        if ($r = $rq->fetch()) $h .= '<p class="small"><span class="chip info">Fatura pedida</span> ao contabilista (' . e($r['req_code']) . ($r['req_sent_at'] ? ', ' . e(date('d/m H:i', strtotime((string)$r['req_sent_at']))) : '') . '): quando a fatura chegar à caixa, regista-se sozinha e esta tarefa fica feita.</p>';
        if (can('financeiro', PERM_EDIT)) $req = '<p class="small"><a class="lnk" href="/admin/financeiro.php?tab=receber&amp;request=1&amp;deal=' . (int)$deal['id'] . '">' . icon('send') . ' Pedir a fatura ao contabilista</a> <span class="muted">— ou submeta aqui o PDF, se já a tiver.</span></p>';
    }
    foreach ($open as $t) {
        $id = (int)$t['id'];
        $due = $t['due_date'] ? ' · até ' . date('d/m', strtotime($t['due_date'])) : '';
        if ($t['doc_kind'] === 'invoice') {
            $h .= '<details class="fl-doc"><summary>' . icon('file') . ' ' . e($t['title']) . '<small>' . e($due) . ' · submeta o PDF da fatura emitida</small></summary>' . $req
                . flow_form('invoice', $deal, $back, '<input type="hidden" name="task" value="' . $id . '"><div class="fl-grid">'
                    . '<label>Número<input type="text" name="number" required maxlength="60" placeholder="FT 2026/123"></label>'
                    . '<label>Data<input type="date" name="doc_date" required value="' . e(today_lisbon()) . '"></label>'
                    . '<label>Valor s/ IVA (€)<input type="text" name="net" required inputmode="decimal" data-net="' . e((string)$vat) . '"></label>'
                    . '<label>IVA (€)<input type="text" name="vat" inputmode="decimal" data-vat></label>'
                    . '<label>Total (€)<input type="text" name="total" inputmode="decimal" data-total></label>'
                    . '<label class="wide">PDF da fatura<input type="file" name="file" accept="application/pdf" required></label></div>'
                    . '<button class="btn sm" type="submit">' . icon('upload') . ' Submeter fatura</button>', ['file' => true])
                . '</details>';
        } else {
            $h .= '<details class="fl-doc"><summary>' . icon('euro') . ' ' . e($t['title']) . '<small>' . e($due) . ' · indique a data de receção</small></summary>'
                . flow_form('payment', $deal, $back, '<input type="hidden" name="task" value="' . $id . '"><div class="fl-grid">'
                    . '<label>Recebido a<input type="date" name="doc_date" required max="' . e(today_lisbon()) . '" value="' . e(today_lisbon()) . '"></label>'
                    . '<label>Valor recebido (€, opcional)<input type="text" name="total" inputmode="decimal"></label>'
                    . '<label class="wide">Comprovativo (PDF, opcional)<input type="file" name="file" accept="application/pdf"></label>'
                    . '<label class="wide">Nota<input type="text" name="note" maxlength="255"></label></div>'
                    . '<button class="btn sm" type="submit">' . icon('check') . ' Validar pagamento</button>', ['file' => true])
                . '</details>';
        }
    }
    if ($docs) {
        $h .= '<ul class="fl-docs">';
        foreach ($docs as $b) {
            $file = $b['file_key'] !== '' ? ' <a class="lnk" href="/admin/deal_flow.php?bill=' . (int)$b['id'] . '">PDF</a>' : '';
            $h .= '<li>' . ($b['kind'] === 'invoice'
                    ? '<b>Fatura ' . e($b['number']) . '</b> · ' . e(date('d/m/Y', strtotime((string)$b['doc_date']))) . ' · ' . e(eur((float)$b['net'])) . ' + IVA ' . e(eur((float)$b['vat'])) . ' = <b>' . e(eur((float)$b['total'])) . '</b>'
                    : '<b>Pagamento recebido</b> a ' . e(date('d/m/Y', strtotime((string)$b['doc_date']))) . ((float)$b['total'] > 0 ? ' · ' . e(eur((float)$b['total'])) : '') . ($b['note'] !== '' ? ' · ' . e($b['note']) : ''))
                . $file . ' <small class="muted">' . e($b['who'] ?? '') . '</small></li>';
        }
        $h .= '</ul>';
    }
    return $h . '</section>';
}

function vat_rate_flow(PDO $pdo): float {
    return (float)setting($pdo, 'vat_rate', 23);
}

/** As rondas de revisão da etapa atual (se a etapa as conta). */
function flow_rounds_html(PDO $pdo, array $deal, string $back): string {
    if (!$deal['stage_id'] || $deal['phase'] !== 'production') return '';
    $s = stage_get($pdo, (int)$deal['stage_id']);
    if (!$s || $s['rounds_included'] === null) return '';
    $n = rounds_count($pdo, (int)$deal['id'], (int)$s['id']);
    $inc = (int)$s['rounds_included'];
    $h = '<section class="lv-blk fl-rounds"><h4>Rondas de revisão <span class="h-sub">' . e($s['name']) . '</span></h4>'
       . '<p class="fl-rc' . ($n > $inc ? ' over' : '') . '"><b class="mono">' . $n . '</b> de <b class="mono">' . $inc . '</b> incluídas'
       . ($n > $inc ? ' · <span>acima do incluído: pedido de alteração orçamentado</span>' : '') . '</p>';
    if (flow_can_edit($pdo, $deal)) {
        $h .= flow_form('round', $deal, $back, (!empty($deal['_line']) ? '<input type="hidden" name="line" value="' . (int)$deal['_line'] . '">' : '') . '<input type="text" name="note" maxlength="200" placeholder="O que pediu o cliente (opcional)" aria-label="Nota da ronda">'
            . '<button class="btn ghost sm" type="submit">' . icon('plus') . ' Registar ronda</button>', ['class' => 'fl-inline']);
    }
    return $h . '<p class="muted small">Uma ronda = um conjunto único e consolidado de comentários do cliente sobre uma versão.</p></section>';
}

/** Foco: as tarefas abertas do negócio (as obrigatórias marcadas). */
function flow_focus_html(PDO $pdo, array $deal, string $back): string {
    $st = $pdo->prepare("SELECT t.*, tm.name AS who FROM tasks t LEFT JOIN team_members tm ON tm.id = t.member_id
                          WHERE t.deal_id = ? AND t.status <> 'done' AND t.private = 0 ORDER BY t.is_gate DESC, t.due_date IS NULL, t.due_date, t.id LIMIT 40");
    $st->execute([(int)$deal['id']]);
    $rows = $st->fetchAll();
    $edit = flow_can_edit($pdo, $deal);
    $h = '<section class="lv-blk fl-focus"><h4>Foco <span class="mono">' . count($rows) . '</span></h4>';
    if (!$rows) return $h . '<p class="muted small">Sem tarefas abertas.</p></section>';
    $h .= '<ul class="focus">';
    $today = today_lisbon();
    foreach ($rows as $t) {
        [$kl, $ki] = TASK_TYPES[$t['kind']] ?? TASK_TYPES['task'];
        $late = $t['due_date'] && $t['due_date'] < $today;
        $btn = '';
        if ($edit) {
            $btn = flow_task_needs_doc($t) !== ''
                ? '<span class="chip" data-tip="' . e(flow_task_needs_doc($t)) . '" tabindex="0">pede documento</span>'
                : flow_form('task_done', $deal, $back, '<input type="hidden" name="task" value="' . (int)$t['id'] . '"><button class="btn ghost sm" type="submit" aria-label="Concluir «' . e($t['title']) . '»">' . icon('check') . '</button>', ['class' => 'inl']);
        }
        $h .= '<li><span class="k ty-' . e($t['kind']) . '" data-tip="' . e($kl) . '" aria-label="' . e($kl) . '" role="img" tabindex="0">' . icon($ki) . '</span>'
            . '<span class="t"><a href="/admin/calendar.php?edit=' . (int)$t['id'] . '">' . e($t['title']) . '</a>'
            . ((int)$t['is_gate'] ? ' <span class="chip req" data-tip="Obrigatória para sair da etapa">obrigatória</span>' : '') . '</span>'
            . '<small class="when' . ($late ? ' late' : '') . '">' . e(($t['due_date'] ? date('d/m', strtotime($t['due_date'])) : 'sem prazo') . ' · ' . ($t['who'] ?: 'por atribuir')) . '</small>' . $btn . '</li>';
    }
    return $h . '</ul></section>';
}

/** Nova tarefa: o mesmo formulário da aba Tarefas (§18.7), já ligado a este negócio. */
function flow_new_action_html(PDO $pdo, array $deal, string $back): string {
    if (!flow_can_edit($pdo, $deal) || !can('tarefas', PERM_EDIT)) return '';
    $types = '';
    foreach (['call', 'meeting', 'email', 'followup', 'document', 'quote', 'task'] as $k) {
        [$l, $i] = TASK_TYPES[$k];
        $types .= '<button type="button" class="tyb ty-' . $k . '" data-new="#mdl-task" data-kind="' . $k . '" data-tip="' . e($l) . '" aria-label="Nova tarefa: ' . e($l) . '"><span>' . icon($i) . '</span></button>';
    }
    return '<section class="lv-blk fl-new"><h4>Nova tarefa <span class="h-sub">ligada a ' . e((string)$deal['code']) . '</span></h4>'
         . '<div class="fl-newrow"><button type="button" class="btn" data-new="#mdl-task">' . icon('plus') . ' Nova tarefa</button>'
         . '<div class="tyrow mini" role="group" aria-label="Começar por um tipo">' . $types . '</div></div>'
         . '<p class="muted small">O mesmo formulário da aba Tarefas: tipo, quando, duração, Ocupado/Livre, responsável, reunião (Meet, Discord ou presencial), participantes, checklist e anexos.</p></section>';
}

/** O formulário «+ Nova tarefa» da aba Tarefas, com o negócio já escolhido; volta para aqui. */
function flow_task_modal_html(PDO $pdo, array $deal, string $back): string {
    if (!flow_can_edit($pdo, $deal) || !can('tarefas', PERM_EDIT)) return '';
    require_once __DIR__ . '/tasks_view.php';
    $members = team_members($pdo);
    $visible = task_visible_members($pdo, $members);
    $assignable = array_filter($members, fn($m, $mid) => task_can_assign($pdo, (int)$mid, $members, $visible), ARRAY_FILTER_USE_BOTH);
    $me = (int)current_user()['id'];
    $myMember = member_for_user($pdo, $me);
    $participants = [];
    foreach ($members as $m) if ($m['user_id'] !== null && (int)$m['user_id'] !== $me) $participants[(int)$m['user_id']] = $m['name'];
    $html = task_modal_html($pdo, null, $assignable, isset($members[$myMember]) ? $myMember : null, $participants, $back, false, ['deal_id' => (int)$deal['id']]);
    // No painel ao lado, gravar volta à página de onde se abriu, com o painel aberto (app.js).
    return preg_replace('~(<input type="hidden" name="back" value="[^"]*")~', '$1 data-back-here', $html, 1);
}

/** Responsáveis (comercial e execução: só admins mudam) e datas do negócio: [linhas do resumo, formulário]. */
function flow_people_html(PDO $pdo, array $deal, string $back): array {
    [$c, $ex] = deal_roles($deal);
    $kv = '<span>Comercial</span><div>' . e(flow_user_name($pdo, $c) ?: 'Por atribuir') . '</div>'
        . '<span>Execução</span><div>' . e(flow_user_name($pdo, $ex) ?: 'Por atribuir') . '</div>'
        . '<span>Entrega</span><div>' . ($deal['delivery_date'] ? e(date('d/m/Y', strtotime((string)$deal['delivery_date']))) : '<span class="muted">—</span>') . '</div>'
        . '<span>Rodagem</span><div>' . ($deal['shoot_date'] ? e(date('d/m/Y', strtotime((string)$deal['shoot_date']))) : '<span class="muted">—</span>') . '</div>';
    if (!flow_can_edit($pdo, $deal)) return [$kv, ''];
    $form = '';
    if (is_admin()) {
        $sel = function (string $name, ?int $cur) use ($pdo) {
            $o = '<option value="">Por atribuir</option>';
            foreach ($pdo->query("SELECT id, name FROM users WHERE is_active = 1 AND role <> 'superadmin' ORDER BY name")->fetchAll() as $u) {
                $o .= '<option value="' . (int)$u['id'] . '"' . ($cur === (int)$u['id'] ? ' selected' : '') . '>' . e($u['name']) . '</option>';
            }
            return '<select name="' . $name . '">' . $o . '</select>';
        };
        $form .= '<label>Responsável comercial' . $sel('owner_id', $c) . '</label><label>Responsável de execução' . $sel('exec_owner_id', $ex) . '</label>';
    }
    $form .= '<label>Data de entrega<input type="date" name="delivery_date" value="' . e((string)$deal['delivery_date']) . '"></label>'
           . '<label>Data de rodagem / gravação<input type="date" name="shoot_date" value="' . e((string)$deal['shoot_date']) . '"></label>';
    return [$kv, '<details class="fl-edit"><summary>' . icon('pencil') . ' ' . (is_admin() ? 'Responsáveis e datas' : 'Datas') . '</summary>'
         . flow_form('people', $deal, $back, '<div class="fl-grid">' . $form . '</div><button class="btn sm" type="submit">Guardar</button>'
             . '<p class="muted small">As tarefas automáticas usam os dois responsáveis; os prazos «antes da entrega/rodagem» acompanham as datas.</p>')
         . '</details>'];
}

/** O painel completo (lado a lado, como o da lead). */
function flow_panel_html(PDO $pdo, array $d, string $back): string {
    $id = (int)$d['id'];
    $edit = flow_can_edit($pdo, $d);
    $stage = $d['stage_id'] ? stage_get($pdo, (int)$d['stage_id']) : null;
    $phase = ['pipeline' => 'Fase comercial', 'production' => 'Em produção', 'done' => 'Concluído', 'lost' => 'Perdido'][$d['phase']] ?? $d['phase'];
    $client = flow_client_name($pdo, $d);
    $x = xray_data($d);
    $score = xray_score($x);

    // Atalhos (§19.8)
    $pdfV = null;
    if (can('orcamentacao') && ($sh = budget_sheet_get($pdo, $id))) {
        foreach (budget_history($pdo, (int)$sh['id']) as $hh) if ($hh['event'] === 'b_pdf') { $vs = budget_versions($pdo, (int)$sh['id']); $pdfV = end($vs); break; }
    }
    $prodOk = in_array($d['phase'], ['production', 'done'], true) && can('negocios');
    $folder = trim((string)$d['drive_folder']);
    $acts = '<div class="lv-acts fl-short">'
          . '<a class="btn ghost sm" href="/admin/xray.php?id=' . $id . '">' . icon('checklist') . ' X-Ray' . (!empty($d['xray_validated_at']) ? ' ✓' : '') . '</a>'
          . (can('orcamentacao') ? '<a class="btn ghost sm" href="/admin/orcamento.php?id=' . $id . '">' . icon('orcamentacao') . ' Orçamento</a>' : '')
          . ($pdfV ? '<a class="btn ghost sm" href="/admin/orcamento_pdf.php?v=' . (int)$pdfV['id'] . '&amp;ver=1" target="_blank" rel="noopener" data-tip="Abre o PDF num separador novo">' . icon('file') . ' Proposta</a>' : '<span class="btn ghost sm off" aria-disabled="true" data-tip="Gere o PDF na folha de orçamento">' . icon('file') . ' Proposta</span>')
          . ($prodOk ? '<a class="btn ghost sm" href="/admin/negocio.php?id=' . $id . '">' . icon('negocios') . ' Ficha do negócio</a>' : '<span class="btn ghost sm off" aria-disabled="true" data-tip="A partir do Kickoff">' . icon('negocios') . ' Ficha do negócio</span>')
          . (can('drive') ? '<a class="btn ghost sm" href="/admin/drive.php' . ($folder !== '' ? '?p=' . rawurlencode($folder) : '?p=' . rawurlencode('Projetos/')) . '">' . icon('drive') . ' Drive</a>' : '')
          . '</div>';

    $owner = flow_user_name($pdo, $d['owner_id']);
    $head = '<header class="lv-head fl-top"><div class="id mono">' . e((string)$d['code']) . '</div><h2 class="lv-name">' . e($d['name']) . '</h2>'
          . ($client !== '' && $client !== $d['name'] ? '<div class="lv-co">' . e($client) . '</div>' : '')
          . '<div class="lv-chips"><span class="chip">' . e($phase) . '</span>'
          . ($score !== null ? ' <span class="chip" data-tip="Qualificação do X-Ray (orçamento, autoridade, necessidade, prazo)">' . $score . '/12</span>' : '') . '</div>'
          . flow_stepper_html($pdo, $d) . '</header>';
    [$peopleKv, $peopleForm] = flow_people_html($pdo, $d, $back);
    $kv = '<span>Cliente</span><div>' . deal_people_link($pdo, $d['contact_id']) . '</div>'
        . '<span>Valor</span><div>' . ($d['value_estimate'] !== null ? e(eur0((float)$d['value_estimate'])) : '<span class="muted">sem valor</span>') . '</div>'
        . $peopleKv;
    $foot = '';
    if ($edit && $d['phase'] === 'pipeline') {
        $reasons = '';
        foreach (setting_lines($pdo, 'lost_reasons', LOST_REASONS) as $r) $reasons .= '<option>' . e($r) . '</option>';
        $foot = '<details class="fl-lost"><summary>' . icon('x') . ' Marcar como perdido</summary>'
              . flow_form('lost', $d, $back, '<select name="reason" required aria-label="Motivo"><option value="">Motivo…</option>' . $reasons . '</select>'
                  . '<input type="text" name="reason_other" maxlength="200" placeholder="Detalhe (opcional)" aria-label="Detalhe"><button class="btn danger sm" type="submit">Perdido</button>', ['class' => 'fl-inline'])
              . '</details>';
    }
    $left = '<div class="lv-l">' . $acts . '<div class="lv-read"><div class="kv">' . $kv . '</div>' . $peopleForm
          . '<section><h4>Notas</h4>' . inline_field('deal', $id, 'notes', e((string)$d['notes']), ['edit' => $edit, 'type' => 'textarea', 'label' => 'Editar notas', 'value' => (string)$d['notes']]) . '</section>'
          . flow_files_html($pdo, $d, $edit)
          . ($foot !== '' ? '<div class="lv-foot">' . $foot . '</div>' : '') . '</div></div>';
    // Crosselling (§22): «Serviços» e, a partir do Kickoff, as frentes no lugar das etapas do negócio.
    require_once __DIR__ . '/xsell_view.php';
    $lanes = xsell_is_multi($pdo, $d) && in_array($d['phase'], ['production', 'done'], true);
    $right = '<div class="lv-r">' . ($lanes ? '' : flow_stages_html($pdo, $d, $back)) . xsell_services_html($pdo, $d, $back) . flow_new_action_html($pdo, $d, $back) . flow_proposal_html($pdo, $d, $back)
           . flow_billing_html($pdo, $d, $back) . ($lanes ? '' : flow_rounds_html($pdo, $d, $back)) . flow_focus_html($pdo, $d, $back)
           . '<section class="lv-blk lv-hist"><h4>Notas &amp; Histórico</h4>' . history_block($pdo, 'deal', $id, $edit, $back, '', ['note']) . '</section></div>';
    return '<div class="lv lv-panel fl" data-deal="' . $id . '">' . $head . $left . $right . '</div>' . flow_task_modal_html($pdo, $d, $back) . flow_send_modal_html($pdo, $d, $back);
}

/** Os ficheiros do negócio no painel (§10.15): propostas, faturação, criativo, anexos — com «Anexar ficheiro». */
function flow_files_html(PDO $pdo, array $d, bool $edit): string {
    require_once __DIR__ . '/files_view.php';
    if (!files_ready($pdo) || !files_on()) return '';
    return '<section class="lv-files"><h4>Ficheiros</h4>' . files_list_html($pdo, $d, ['edit' => $edit, 'limit' => 5]) . '</section>';
}
