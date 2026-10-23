<?php
/**
 * topper. admin — histórico (§11.9, §12.7)
 *
 * Leads, negócios e contactos têm uma cronologia: o que se registou à
 * mão («Registar nota» / «Registar interação»: chamada, email, reunião,
 * nota) junto com o que o sistema guarda sozinho em deal_history (criada,
 * atribuída, estado, tarefas, chamadas, arquivo, conversão, etapas…).
 * Cada tipo tem ícone e cor próprios, e o nome do tipo aparece na dica do
 * ícone. Cores sempre a partir dos tokens dos dois modos (classes .c-*).
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/reach.php';
require_once __DIR__ . '/ui.php';

const INTERACTION_KINDS = [
    'call'    => ['Chamada', 'phone'],
    'email'   => ['Email', 'mail'],
    'meeting' => ['Reunião', 'meeting'],
    'note'    => ['Nota', 'bubble'],
];

/** Tipo de entrada → [nome (dica), ícone, cor]. Tipos novos seguem a mesma regra. */
const HISTORY_TYPES = [
    'create'     => ['Lead criada', 'star', 'neutral'],
    'assign'     => ['Atribuída / reatribuída', 'user-arrow', 'sky'],
    'state'      => ['Mudança de estado', 'arrows', 'neutral'],
    'call_ok'    => ['Chamada atendida', 'phone', 'blue'],
    'call_miss'  => ['Chamada não atendida', 'phone-off', 'orange'],
    'email'      => ['Email', 'mail', 'blue'],
    'meeting'    => ['Reunião', 'meeting', 'blue'],
    'task_new'   => ['Tarefa criada', 'box', 'neutral'],
    'task_done'  => ['Tarefa concluída', 'tarefas', 'green'],
    'note'       => ['Nota', 'bubble', 'note'],
    'archive'    => ['Arquivada', 'archive', 'red'],
    'reactivate' => ['Reativada', 'refresh', 'sky'],
    'release'    => ['Saiu da quarentena', 'check', 'green'],
    'xsell'      => ['Crosselling', 'merge', 'yellow'],
    'convert'    => ['Convertida em negócio', 'arrow', 'yellow'],
    'late'       => ['Prazo em atraso', 'clock', 'red'],
    'stage'      => ['Mudou de etapa', 'arrows', 'neutral'],
    'funnel'     => ['Mudou de funil', 'arrows', 'neutral'],
    'lost'       => ['Perdido', 'x', 'red'],
    'reopen'     => ['Reaberto', 'refresh', 'sky'],
    'import'     => ['Importado da folha', 'file', 'neutral'],
    'brought'    => ['Trazido por', 'user-arrow', 'yellow'],
    // Contactos (§13)
    'c_create'   => ['Contacto criado', 'star', 'neutral'],
    'c_edit'     => ['Dados alterados', 'pencil', 'neutral'],
    'c_company'  => ['Mudou de empresa', 'user-arrow', 'sky'],
    'c_price'    => ['Serviços e preços', 'euro', 'neutral'],
    'c_rating'   => ['Avaliado', 'star', 'green'],
    'c_active'   => ['Ativo / Inativo', 'power', 'sky'],
    'c_merge'    => ['Juntado com um duplicado', 'merge', 'neutral'],
    'c_import'   => ['Importado', 'upload', 'neutral'],
    'c_source'   => ['Trouxe um negócio', 'arrow', 'yellow'],
    'rel_start'  => ['Relação acompanhada', 'heart', 'sky'],
    'rel_edit'   => ['Relação alterada', 'heart', 'neutral'],
    'rel_touch'  => ['Follow-up de relação', 'heart', 'sky'],
    'rel_miss'   => ['Não atendeu', 'phone-off', 'orange'],
    'rel_meet'   => ['Reunião marcada', 'meeting', 'blue'],
    'rel_lead'   => ['Gerou uma lead', 'arrow', 'yellow'],
    'rel_pause'  => ['Relação pausada', 'pause', 'neutral'],
    'rel_resume' => ['Relação retomada', 'refresh', 'sky'],
    'rel_level'  => ['Nível revisto', 'arrows', 'neutral'],
    'rel_stop'   => ['Deixou de ser acompanhada', 'x', 'neutral'],
    // Negócios — ficha central (§15)
    'step'        => ['Etapa de produção', 'arrows', 'sky'],
    'delivered'   => ['Entregas feitas', 'check', 'green'],
    'portfolio'   => ['Enviado para o portfólio', 'star', 'yellow'],
    'testimonial' => ['Testemunho pedido', 'bubble', 'blue'],
    'cost'        => ['Custo registado', 'euro', 'orange'],
    'disclose'    => ['Pode ser divulgado?', 'marketing', 'sky'],
    // Orçamentação (§14)
    'b_create'   => ['Folha criada', 'orcamentacao', 'neutral'],
    'b_line'     => ['Linha de custo', 'euro', 'neutral'],
    'b_version'  => ['Nova versão', 'file', 'sky'],
    'b_accept'   => ['Versão aceite', 'check', 'yellow'],
    'b_margin'   => ['Margem alterada', 'arrows', 'neutral'],
    'b_model'    => ['Modelo aplicado', 'checklist', 'sky'],
    'b_pdf'      => ['Proposta gerada', 'file', 'blue'],
    'b_close'    => ['Resultado fechado', 'won', 'green'],
    'b_reopen'   => ['Folha reaberta', 'refresh', 'sky'],
    'b_send'     => ['Proposta enviada', 'send', 'blue'],
    'b_cc'       => ['Pré-criativo ligado', 'criativo', 'yellow'],
    // Pipeline, funis e automações (§19)
    'auto'       => ['Tarefas automáticas', 'cog', 'neutral'],
    'gate_force' => ['Passagem forçada', 'alert', 'red'],
    'xray'       => ['X-Ray', 'checklist', 'sky'],
    'proposal'   => ['Proposta enviada', 'send', 'blue'],
    'client'     => ['Resposta do cliente', 'user', 'yellow'],
    'billing'    => ['Faturação', 'euro', 'green'],
    'roles'      => ['Responsáveis', 'user-arrow', 'sky'],
    'dates'      => ['Datas do negócio', 'calendar', 'neutral'],
    'round'      => ['Ronda de revisão', 'refresh', 'sky'],
    'rounds'     => ['Rondas esgotadas', 'alert', 'orange'],
    // Ficheiros (§10.15)
    'file'       => ['Ficheiro anexado', 'drive', 'sky'],
    'folder'     => ['Pasta no Drive', 'drive', 'neutral'],
    // Marketing (§16)
    'm_create'   => ['Ideia criada', 'bulb', 'neutral'],
    'm_edit'     => ['Dados alterados', 'pencil', 'neutral'],
    'm_triage'   => ['Triagem', 'arrows', 'sky'],
    'm_stage'    => ['Mudou de etapa', 'arrows', 'neutral'],
    'm_version'  => ['Texto de uma rede', 'pencil', 'neutral'],
    'm_ai'       => ['Proposta da IA usada', 'sparkle', 'sky'],
    'm_approve'  => ['Aprovada', 'check', 'green'],
    'm_changes'  => ['Alterações pedidas', 'bubble', 'orange'],
    'm_schedule' => ['Agendada', 'calendar', 'blue'],
    'm_publish'  => ['Publicada', 'won', 'yellow'],
    'm_metrics'  => ['Métricas', 'trend', 'sky'],
    'm_shoot'    => ['Dia de gravação', 'camera', 'neutral'],
    'm_blocked'  => ['Bloqueada: divulgação', 'alert', 'red'],
    // Equipa (§17)
    'u_create'   => ['Conta criada', 'star', 'neutral'],
    'u_invite'   => ['Convite enviado', 'mail', 'blue'],
    'u_edit'     => ['Dados alterados', 'pencil', 'neutral'],
    'u_role'     => ['Papel alterado', 'arrows', 'sky'],
    'u_off'      => ['Conta desativada', 'power', 'red'],
    'u_on'       => ['Conta reativada', 'refresh', 'sky'],
    'u_absence'  => ['Férias e ausências', 'calendar', 'orange'],
    'u_check'    => ['Entrada e saída', 'checklist', 'green'],
    'u_reassign' => ['Trabalho reatribuído', 'user-arrow', 'yellow'],
];

/** O ícone de uma entrada: cor do tipo e o nome do tipo como dica e nome acessível. */
function history_icon(string $type): string {
    [$label, $ico, $color] = HISTORY_TYPES[$type] ?? ['Entrada', 'clock', 'neutral'];
    return '<span class="hi c-' . $color . '" role="img" aria-label="' . e($label) . '" data-tip="' . e($label) . '" tabindex="0">' . icon($ico) . '</span>';
}

/**
 * Regista uma interação. $dealId ou $contactId (pelo menos um).
 * Numa lead ou num negócio, conta como contacto: o «último contacto» avança
 * (as notas não contam). Numa lead em Follow-Up, uma chamada, email ou
 * reunião registada é um follow-up: o prazo recomeça (§12.4).
 */
function interaction_add(PDO $pdo, ?int $dealId, ?int $contactId, string $kind, string $body, string $date): int {
    $kind = isset(INTERACTION_KINDS[$kind]) ? $kind : 'note';
    $body = trim(mb_substr($body, 0, 4000));
    if ($body === '') throw new RuntimeException('Escreva o que aconteceu.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > today_lisbon()) $date = today_lisbon();
    $pdo->prepare('INSERT INTO interactions (deal_id, contact_id, kind, body, happened_at, user_id) VALUES (?,?,?,?,?,?)')
        ->execute([$dealId, $contactId, $kind, $body, $date . ' ' . date('H:i:s'), (int)current_user()['id']]);
    $id = (int)$pdo->lastInsertId();
    if ($dealId && $kind !== 'note') {
        $pdo->prepare('UPDATE deals SET last_touch = ? WHERE id = ? AND (last_touch IS NULL OR last_touch < ?)')
            ->execute([$date, $dealId, $date]);
        $pdo->prepare('UPDATE deals SET last_contact_at = ? WHERE id = ?')->execute([now_php(), $dealId]);
        $pdo->prepare("UPDATE deals SET state_since = ? WHERE id = ? AND phase = 'lead' AND lead_state = 'follow_up'")->execute([now_php(), $dealId]);
    }
    return $id;
}

/** Texto de uma linha de deal_history. */
function history_text(array $h): string {
    $parts = [];
    if ($h['event'] === 'funnel') {
        $parts[] = ($h['from_funnel'] ?? '?') . ' › ' . ($h['from_stage'] ?? '?') . ' → ' . ($h['to_funnel'] ?? '?') . ' › ' . ($h['to_stage'] ?? '?');
    } elseif ($h['from_stage'] || $h['to_stage']) {
        // A etapa de uma frente de crosselling (§22): o serviço à frente.
        $svc = isset($h['deal_funnel']) && $h['to_funnel_id'] && (int)$h['to_funnel_id'] !== (int)$h['deal_funnel'] && $h['event'] === 'stage' ? $h['to_funnel'] . ': ' : '';
        $parts[] = $svc . trim(($h['from_stage'] ?? '') . ' → ' . ($h['to_stage'] ?? ''), ' →');
    }
    if ((string)$h['note'] !== '') $parts[] = $h['note'];
    return implode(' · ', $parts);
}

/**
 * A cronologia de um registo: 'deal' (lead ou negócio) ou 'contact'.
 * Cada linha: when, type, title, text, who, sys. A visibilidade do registo
 * verifica-se antes de chamar; nos contactos, só entram os negócios que
 * a pessoa pode ver.
 */
function timeline(PDO $pdo, string $type, int $id, int $limit = 80): array {
    $out = [];
    $dealIds = [];
    if ($type === 'deal') {
        $dealIds = [$id];
        $st = $pdo->prepare('SELECT i.*, u.name AS who FROM interactions i LEFT JOIN users u ON u.id = i.user_id WHERE i.deal_id = ?');
        $st->execute([$id]);
    } else {
        $st = $pdo->prepare('SELECT d.* FROM deals d WHERE d.contact_id = ? OR d.company_id = ? OR d.person_id = ? OR d.id IN (SELECT deal_id FROM deal_contacts WHERE contact_id = ?)');
        $st->execute([$id, $id, $id, $id]);
        foreach ($st->fetchAll() as $d) if (record_reach($pdo, $d)) $dealIds[] = (int)$d['id'];
        $in = $dealIds ? ' OR i.deal_id IN (' . implode(',', $dealIds) . ')' : '';
        $st = $pdo->prepare("SELECT i.*, u.name AS who, d.code, d.name AS deal_name FROM interactions i LEFT JOIN users u ON u.id = i.user_id
                               LEFT JOIN deals d ON d.id = i.deal_id WHERE i.contact_id = ?$in");
        $st->execute([$id]);
    }
    $kindType = ['call' => 'call_ok', 'email' => 'email', 'meeting' => 'meeting', 'note' => 'note'];
    foreach ($st->fetchAll() as $i) {
        $t = $kindType[$i['kind']] ?? 'note';
        $ref = !empty($i['code']) ? $i['code'] : (!empty($i['deal_name']) ? $i['deal_name'] : '');
        $out[] = ['when' => $i['happened_at'], 'type' => $t, 'title' => INTERACTION_KINDS[$i['kind']][0] . ($ref !== '' ? ' · ' . $ref : ''),
                  'text' => $i['body'], 'who' => $i['who'] ?? '—', 'sys' => false];
    }
    if ($dealIds) {
        $only = $type === 'contact' ? " AND h.event IN ('create','convert','lost','reopen','archive')" : '';
        $hist = $pdo->query(
            'SELECT h.*, u.name AS who, d.code, d.name AS deal_name, d.funnel_id AS deal_funnel, fs.name AS from_stage, ts.name AS to_stage, ff.name AS from_funnel, tf.name AS to_funnel
               FROM deal_history h
               JOIN deals d ON d.id = h.deal_id
               LEFT JOIN users u ON u.id = h.user_id
               LEFT JOIN funnel_stages fs ON fs.id = h.from_stage_id
               LEFT JOIN funnel_stages ts ON ts.id = h.to_stage_id
               LEFT JOIN funnels ff ON ff.id = h.from_funnel_id
               LEFT JOIN funnels tf ON tf.id = h.to_funnel_id
              WHERE h.deal_id IN (' . implode(',', $dealIds) . ")$only"
        )->fetchAll();
        $hasCreate = [];
        foreach ($hist as $h) {
            if ($h['event'] === 'create') $hasCreate[(int)$h['deal_id']] = true;
            $ref = $type === 'contact' ? ($h['code'] ?: $h['deal_name']) : '';
            $out[] = ['when' => $h['created_at'], 'type' => isset(HISTORY_TYPES[$h['event']]) ? $h['event'] : 'state',
                      'title' => (HISTORY_TYPES[$h['event']][0] ?? $h['event']) . ($ref ? ' · ' . $ref : ''),
                      'text' => history_text($h), 'who' => $h['who'] ?? 'Sistema', 'sys' => true];
        }
        // Registos anteriores à v2 não têm a entrada «criada»: vem da data de criação.
        // E o prazo em atraso aparece na hora em que o prazo acabou (§12.7).
        $rows = $pdo->query('SELECT d.*, u.name AS creator FROM deals d LEFT JOIN users u ON u.id = d.created_by WHERE d.id IN (' . implode(',', $dealIds) . ')')->fetchAll();
        $hours = lead_deadline_hours($pdo);
        foreach ($rows as $d) {
            $ref = $type === 'contact' ? ($d['code'] ?: lead_display_name($d)) : '';
            if (!isset($hasCreate[(int)$d['id']]) && ($type === 'deal' || $d['code'] === null)) {
                $out[] = ['when' => $d['created_at'], 'type' => 'create', 'title' => 'Lead criada' . ($ref ? ' · ' . $ref : ''),
                          'text' => LEAD_ORIGIN[$d['origin']] ?? '', 'who' => $d['creator'] ?? 'Sistema', 'sys' => true];
            }
            if ($type === 'deal' && ($c = lead_clock($d, $hours)) && $c['level'] === 'late') {
                $out[] = ['when' => date('Y-m-d H:i:s', $c['due']), 'type' => 'late', 'title' => 'Prazo em atraso',
                          'text' => (LEAD_STATE[$d['lead_state']] ?? '') . ' · ' . $c['label'], 'who' => 'Sistema', 'sys' => true];
            }
        }
    }
    // O que acontece ao próprio contacto (§13): criado, preços, avaliações, relação, tarefas…
    if ($type === 'contact' && db_has_table($pdo, 'contact_history')) {
        $st = $pdo->prepare('SELECT h.*, u.name AS who FROM contact_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.contact_id = ?');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $h) {
            $out[] = ['when' => $h['created_at'], 'type' => isset(HISTORY_TYPES[$h['event']]) ? $h['event'] : 'state',
                      'title' => HISTORY_TYPES[$h['event']][0] ?? $h['event'], 'text' => (string)$h['note'], 'who' => $h['who'] ?? 'Sistema', 'sys' => true];
        }
    }
    usort($out, fn($a, $b) => strcmp((string)$b['when'], (string)$a['when']));
    return array_slice($out, 0, $limit);
}

/** «hoje 10:12», «ontem 16:40», «20 out». */
function history_when(string $when): string {
    $ts = strtotime($when);
    $d = lisbon_fmt($ts, 'Y-m-d');
    $diff = (int)(new DateTimeImmutable($d))->diff(new DateTimeImmutable(today_lisbon()))->format('%r%a');
    if ($diff === 0) return 'hoje ' . lisbon_fmt($ts, 'H:i');
    if ($diff === 1) return 'ontem ' . lisbon_fmt($ts, 'H:i');
    return (int)lisbon_fmt($ts, 'j') . ' ' . mb_strtolower(mb_substr(month_name((int)lisbon_fmt($ts, 'n')), 0, 3)) . ' ' . lisbon_fmt($ts, 'H:i');
}

/** A cronologia em HTML: ícone com cor e dica do tipo, data, hora e autor em cada entrada. */
function timeline_html(array $items): string {
    if (!$items) return '<p class="muted" style="margin:0">Ainda sem histórico.</p>';
    $h = '<ol class="tline">';
    foreach ($items as $i) {
        $h .= '<li class="' . ($i['sys'] ? 'sys' : '') . '">' . history_icon($i['type'])
            . '<div><div class="meta">' . e(history_when((string)$i['when'])) . ' · ' . e($i['who']) . ' · ' . e($i['title']) . '</div>'
            . ($i['text'] !== '' ? '<div class="what">' . e($i['text']) . '</div>' : '') . '</div></li>';
    }
    return $h . '</ol>';
}

/** O formulário «Registar interação» (funciona com e sem JavaScript). $kinds: limitar os tipos. */
function log_form_html(string $type, int $id, string $back, array $kinds = []): string {
    $opts = '';
    foreach (INTERACTION_KINDS as $k => [$label]) {
        if ($kinds && !in_array($k, $kinds, true)) continue;
        $opts .= '<option value="' . $k . '">' . e($label) . '</option>';
    }
    $only = $kinds && count($kinds) === 1;
    return '<form class="log-form' . ($only ? ' one' : '') . '" method="post" action="/admin/ui_api.php" data-log>'
         . csrf_field() . '<input type="hidden" name="a" value="interaction">'
         . '<input type="hidden" name="' . ($type === 'contact' ? 'contact_id' : 'deal_id') . '" value="' . $id . '">'
         . '<input type="hidden" name="back" value="' . e($back) . '">'
         . ($only ? '<input type="hidden" name="kind" value="' . e($kinds[0]) . '"><input type="hidden" name="date" value="' . e(today_lisbon()) . '">'
                  : '<select name="kind" aria-label="Tipo de interação">' . $opts . '</select>'
                  . '<input type="date" name="date" value="' . e(today_lisbon()) . '" max="' . e(today_lisbon()) . '" aria-label="Quando">')
         . '<textarea name="body" required maxlength="4000" placeholder="' . ($only ? 'Escreva a nota…' : 'O que aconteceu? (ex.: ligou a pedir orçamento para março)') . '" aria-label="O que aconteceu"></textarea>'
         . '<div class="actions"><button class="btn sm" type="submit">' . icon('plus') . ($only ? ' Registar nota' : ' Registar interação') . '</button></div>'
         . '</form>';
}

/** Bloco completo: título, formulário (se pode editar) e cronologia. */
function history_block(PDO $pdo, string $type, int $id, bool $canLog, string $back, string $heading = 'Histórico', array $kinds = []): string {
    return '<section class="hist" data-hist="' . e($type) . ':' . $id . '">'
         . ($heading !== '' ? '<h2 id="historico">' . e($heading) . '</h2>' : '')
         . ($canLog ? log_form_html($type, $id, $back, $kinds) : '')
         . '<div class="hist-list">' . timeline_html(timeline($pdo, $type, $id)) . '</div></section>';
}
