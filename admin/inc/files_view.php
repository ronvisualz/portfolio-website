<?php
/**
 * topper. admin — os ficheiros de um negócio ou lead, para as fichas e os painéis (§10.15)
 *
 * Tudo o que está na pasta do registo no Drive (também o que foi posto lá à mão) e o que o
 * registo de ficheiros lhe liga, agrupado por secção (Propostas, Faturação, Criativo…), com a
 * origem, a data e «Mostrar no Drive». Quem edita o registo anexa daqui (o ficheiro vai direto
 * para a pasta certa). A faturação só aparece a quem vê o Financeiro.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/files.php';

/** O link que abre um item da lista (pelo registo, ou pela chave dentro da pasta do negócio). */
function files_item_href(array $d, array $it, string $a = 'open'): string {
    return $it['id'] ? '/admin/files_api.php?a=' . $a . '&f=' . (int)$it['id']
                     : '/admin/files_api.php?a=' . $a . '&d=' . (int)$d['id'] . '&k=' . rawurlencode($it['key']);
}

/** Números para o cartão da ficha: [total, [rótulo da secção => n], último item]. */
function files_counts(array $groups): array {
    $total = 0; $by = []; $last = null;
    foreach ($groups as $s => $list) {
        $total += count($list);
        $by[FILES_SECTIONS[$s][1] ?? 'Outros'] = count($list);
        foreach ($list as $it) if (!$last || $it['modified'] > $last['modified']) $last = $it;
    }
    return [$total, $by, $last];
}

/**
 * A lista. $o: edit (pode anexar), limit (por secção; 0 = todos), groups (já calculados).
 */
function files_list_html(PDO $pdo, array $d, array $o = []): string {
    if (!files_on()) return '<p class="muted">O Drive não está ligado neste servidor.</p>';
    $groups = $o['groups'] ?? files_for_deal($pdo, $d);
    $limit = (int)($o['limit'] ?? 0);
    $lead = in_array($d['phase'], ['lead', 'archived'], true);
    $drive = can('drive');
    $h = '<div class="fls" data-files="deal:' . (int)$d['id'] . '">';
    if (($m = files_moving($pdo, (int)$d['id'])) !== null) {
        $h .= $m['status'] === 'error'
            ? '<p class="fls-move err">' . icon('alert') . ' A pasta não conseguiu mudar para <span class="mono">' . e($m['to_prefix']) . '</span>. Volta a tentar sozinha; se continuar, fale com um administrador.</p>'
            : '<p class="fls-move">' . icon('drive') . ' A pasta está a mudar para <span class="mono">' . e($m['to_prefix']) . '</span>' . ((int)$m['moved'] ? ' (' . (int)$m['moved'] . ' ficheiros já passaram)' : '') . '. Os ficheiros continuam a abrir daqui.</p>';
    }
    if (!empty($o['edit'])) {
        $opts = '';
        if (!$lead) {
            foreach (FILES_SECTIONS as $k => [$dir, $label]) {
                if ($k === 'lead' || ($k === 'faturacao' && !can('financeiro', PERM_EDIT))) continue;
                $opts .= '<option value="' . e($k) . '"' . ($k === '' ? ' selected' : '') . '>' . e($k === '' ? 'Outros (na pasta)' : $label) . '</option>';
            }
        }
        $h .= '<div class="fls-add">' . ($opts ? '<label class="sr" for="fls-sec-' . (int)$d['id'] . '">Pasta</label><select id="fls-sec-' . (int)$d['id'] . '" data-attach-sec>' . $opts . '</select>' : '')
            . '<button type="button" class="btn ghost sm" data-attach="deal:' . (int)$d['id'] . '">' . icon('upload') . ' Anexar ficheiro</button></div>';
    }
    if (!$groups) {
        $h .= '<p class="muted fls-empty">Ainda não há ficheiros. O que entra ou é gerado (propostas, faturas, criativo, anexos) fica aqui e na pasta do Drive.</p>';
    }
    foreach ($groups as $s => $list) {
        $h .= '<section class="fls-sec"><h5>' . e(FILES_SECTIONS[$s][1] ?? 'Outros') . ' <span class="mono">' . count($list) . '</span></h5><ul class="fls-list">';
        foreach ($limit ? array_slice($list, 0, $limit) : $list as $it) {
            $meta = array_filter([
                FILES_ORIGINS[$it['origin']] ?? '',
                $it['label'] !== '' && $it['label'] !== $it['name'] ? $it['label'] : '',
                $it['modified'] ? date('d/m/Y', $it['modified']) : '',
                $it['size'] ? drive_size((int)$it['size']) : '',
                $it['by'] !== '' ? 'por ' . $it['by'] : '',
            ]);
            $gone = ($it['state'] ?? 'ok') === 'trash' ? ' <span class="chip red">no Lixo</span>' : (($it['state'] ?? 'ok') === 'missing' ? ' <span class="chip">não encontrado</span>' : '');
            $h .= '<li><span class="fls-t"><a class="fls-name" href="' . e(files_item_href($d, $it)) . '" target="_blank" rel="noopener" data-tip="Abrir">' . e($it['name']) . '</a>' . $gone . '</span>'
                . '<small class="muted">' . e(implode(' · ', $meta)) . '</small>'
                . ($drive ? '<a class="lnk fls-show" href="' . e(files_item_href($d, $it, 'show')) . '">' . icon('drive') . ' Mostrar no Drive</a>' : '') . '</li>';
        }
        if ($limit && count($list) > $limit) $h .= '<li class="muted small">+ ' . (count($list) - $limit) . ' na pasta</li>';
        $h .= '</ul></section>';
    }
    $folder = (string)(files_deal_row($pdo, (int)$d['id'])['drive_folder'] ?? '');
    $h .= '<p class="fls-foot"><span class="mono small muted">' . e($folder !== '' ? $folder : files_place($d) . files_leaf($d) . '/ (criada com o primeiro ficheiro)') . '</span>'
        . ($drive && $folder !== '' ? ' <a class="btn ghost sm" href="' . e(drive_page($folder)) . '">' . icon('drive') . ' Abrir a pasta</a>' : '') . '</p>';
    return $h . '</div>';
}

/** Os ficheiros de uma peça de marketing: a pasta da peça (e o que o registo lhe liga), com «Anexar ficheiro». */
function files_piece_html(PDO $pdo, array $p, bool $edit): string {
    if (!files_on()) return '';
    $folder = (string)$p['drive_folder'];
    $items = [];
    if ($folder !== '') {
        try {
            $cat = drive_catalog_cached();
            $objs = $cat ? array_filter($cat['objects'], fn($o) => strncmp($o['key'], $folder, strlen($folder)) === 0) : r2_list_all($folder, 10);
            foreach ($objs as $o) if (substr($o['key'], -1) !== '/') $items[$o['key']] = ['key' => $o['key'], 'id' => 0, 'modified' => (int)$o['modified'], 'size' => (int)$o['size'], 'origin' => 'drive'];
        } catch (Throwable $e) { error_log('files_piece_html: ' . $e->getMessage()); }
    }
    if (files_ready($pdo)) {
        $st = $pdo->prepare("SELECT * FROM files WHERE mkt_piece_id = ? AND status <> 'gone'");
        $st->execute([(int)$p['id']]);
        foreach ($st->fetchAll() as $r) $items[(string)$r['obj_key']] = ['key' => (string)$r['obj_key'], 'id' => (int)$r['id'], 'modified' => (int)strtotime((string)$r['created_at']), 'size' => (int)$r['size'], 'origin' => (string)$r['origin']] + ($items[(string)$r['obj_key']] ?? []);
    }
    usort($items, fn($a, $b) => $b['modified'] <=> $a['modified']);
    $drive = can('drive');
    $h = '<div class="fls">';
    if ($edit) $h .= '<div class="fls-add"><button type="button" class="btn ghost sm" data-attach="piece:' . (int)$p['id'] . '">' . icon('upload') . ' Anexar ficheiro</button></div>';
    if (!$items) $h .= '<p class="muted fls-empty">Os ficheiros da peça (brutos, versões, capas) ficam na pasta dela no Drive' . ($p['deal_id'] ? ', dentro da pasta do negócio' : '') . '.</p>';
    else {
        $h .= '<ul class="fls-list">';
        foreach (array_slice($items, 0, 30) as $it) {
            $open = $it['id'] ? files_open_href($it['id']) : ($drive ? files_drive_href($it['key']) : '');
            $h .= '<li>' . ($open !== '' ? '<a class="fls-name" href="' . e($open) . '" target="_blank" rel="noopener">' . e(drive_base($it['key'])) . '</a>' : '<span class="fls-name">' . e(drive_base($it['key'])) . '</span>')
                . '<small class="muted">' . e(implode(' · ', array_filter([$it['modified'] ? date('d/m/Y', $it['modified']) : '', $it['size'] ? drive_size($it['size']) : '']))) . '</small>'
                . ($drive ? '<a class="lnk fls-show" href="' . e(files_drive_href($it['key'])) . '">' . icon('drive') . ' Mostrar no Drive</a>' : '') . '</li>';
        }
        $h .= '</ul>';
    }
    return $h . '</div>';
}
