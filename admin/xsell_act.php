<?php
/**
 * topper. admin — ações das oportunidades de crosselling (§22.6), na ficha do contacto:
 *   opp_add   contact funnel   regista o serviço como oportunidade (à mão)
 *   opp_deal  opp              cria o negócio (crosselling posterior, ligado ao anterior)
 *   opp_drop  opp              descarta a oportunidade
 * Só POST com CSRF; tudo verificado aqui (Pipeline: editar).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/xsell.php';
require_once __DIR__ . '/inc/undo.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/admin/contactos.php');
csrf_check();
$back = (string)($_POST['back'] ?? '');
if (!preg_match('~^/admin/[a-z_]+\.php(\?[^#\s]*)?(#[A-Za-z0-9_-]*)?$~', $back)) $back = '/admin/contactos.php';
try {
    if (!can('pipeline', PERM_EDIT)) throw new RuntimeException('Sem permissão: as oportunidades são do Pipeline.');
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'opp_add') {
        $cid = (int)($_POST['contact'] ?? 0);
        $f = funnel_get($pdo, (int)($_POST['funnel'] ?? 0));
        if (!$f || !(int)$f['is_active'] || !$pdo->query('SELECT COUNT(*) FROM contacts WHERE id = ' . $cid)->fetchColumn()) throw new RuntimeException('Oportunidade inválida.');
        $id = xsell_opp_add($pdo, $cid, null, (int)$f['id'], 'manual');
        flash($id ? 'Oportunidade registada: ' . $f['name'] . '.' : 'Já havia uma oportunidade aberta para ' . $f['name'] . '.');
    } elseif ($do === 'opp_deal' || $do === 'opp_drop') {
        $o = xsell_opp_get($pdo, (int)($_POST['opp'] ?? 0));
        if (!$o) throw new RuntimeException('Oportunidade não encontrada.');
        if ($do === 'opp_drop') {
            if ($o['status'] !== 'open') throw new RuntimeException('Esta oportunidade já não está aberta.');
            $pdo->prepare("UPDATE xsell_opps SET status = 'dropped', closed_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $o['id']]);
            flash('Oportunidade descartada: ' . $o['funnel_name'] . '.');
        } else {
            [$id, $code] = xsell_opp_to_deal($pdo, $o, (int)$user['id']);
            flash('Negócio ' . $code . ' criado (crosselling: ' . $o['funnel_name'] . '), em X-Ray.');
            redirect('/admin/pipeline.php?deal=' . $id . '#negocio');
        }
    } else {
        throw new RuntimeException('Pedido inválido.');
    }
} catch (RuntimeException $e) {
    flash($e->getMessage(), 'err');
}
redirect($back);
