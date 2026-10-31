<?php
// Permissões da Drive no servidor: quem só pode ver não muda nada (envios, pastas, mover, copiar, Lixo, apagar,
// partilhar); a leitura continua. Links: share_list esconde a faturação a quem não tem o Financeiro, e share_revoke
// só para quem criou o link (ou um admin), com a mesma regra da faturação.
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';

$dir = 'Empresa/Teste32/perm-' . bin2hex(random_bytes(3)) . '/';
$f = tests_put($dir . 'a.txt', 'a');
$api = fn(array $b, int $uid = 1) => tests_http('POST', '/admin/drive_api.php', $b, $uid);
$viewer = 3;
$own = $api(['do' => 'share_create', 'key' => $f, 'hours' => 24])['json']['share'] ?? [];

$pdo->prepare("DELETE FROM permissions WHERE role = '' AND user_id = ? AND tab = 'drive'")->execute([$viewer]);
$pdo->prepare("INSERT INTO permissions (role, user_id, tab, level, scope) VALUES ('', ?, 'drive', 1, NULL)")->execute([$viewer]);
try {
    $writes = [
        ['do' => 'sign', 'prefix' => $dir, 'name' => 'x.txt', 'size' => 1, 'type' => 'text/plain'],
        ['do' => 'mp_sign', 'prefix' => $dir, 'name' => 'x.bin', 'size' => 1],
        ['do' => 'upload_done', 'key' => $dir . 'x.txt'],
        ['do' => 'mkdir', 'prefix' => $dir, 'name' => 'Nova'],
        ['do' => 'move', 'from' => $f, 'to' => $dir . 'b.txt', 'on_conflict' => 'error', 'instant' => true],
        ['do' => 'copy', 'from' => $f, 'to' => $dir . 'c.txt', 'on_conflict' => 'error'],
        ['do' => 'rename', 'key' => $f, 'name' => 'd.txt'],
        ['do' => 'trash', 'key' => $f],
        ['do' => 'delete', 'key' => $f],
        ['do' => 'restore', 'id' => 1],
        ['do' => 'purge', 'id' => 1],
        ['do' => 'purge_all'],
        ['do' => 'move_undo', 'batch' => str_repeat('a', 16)],
        ['do' => 'share_create', 'key' => $f, 'hours' => 24],
        ['do' => 'share_quick', 'key' => $f],
        ['do' => 'share_update', 'id' => (int)($own['id'] ?? 0), 'hours' => 48],
        ['do' => 'share_revoke', 'id' => (int)($own['id'] ?? 0)],
    ];
    foreach ($writes as $w) {
        $r = $api($w, $viewer);
        t_eq($r['code'], 403, 'só ver: «' . $w['do'] . '» → 403 (' . ($r['json']['error'] ?? '') . ')');
    }
    t_true(r2_head($f) !== null && r2_head($dir . 'b.txt') === null, 'nada mudou no armazenamento');
    foreach ([['do' => 'catalog'], ['do' => 'url', 'key' => $f], ['do' => 'info', 'key' => $f], ['do' => 'share_list'], ['do' => 'fav', 'key' => $f, 'on' => true]] as $rd)
        t_eq($api($rd, $viewer)['code'], 200, 'só ver: «' . $rd['do'] . '» continua a funcionar');
} finally {
    $pdo->prepare("DELETE FROM permissions WHERE role = '' AND user_id = ? AND tab = 'drive'")->execute([$viewer]);
    $api(['do' => 'fav', 'key' => $f, 'on' => false], $viewer);
}

// links: faturação e dono
$member = (int)$pdo->query("SELECT id FROM users WHERE is_active = 1 AND role NOT IN ('admin','superadmin') ORDER BY id LIMIT 1")->fetchColumn();
$bill = tests_put('Empresa/Financeiro/2026/10/perm-' . bin2hex(random_bytes(2)) . '.pdf', '%PDF-1.4', 'application/pdf');
$bs = $api(['do' => 'share_create', 'key' => $bill, 'hours' => 24])['json']['share'] ?? [];
if ($member) {
    $items = $api(['do' => 'share_list'], $member)['json']['items'] ?? [];
    t_true(!array_filter($items, fn($s) => ($s['id'] ?? 0) === ($bs['id'] ?? -1)), 'share_list: sem Financeiro não vê os links de faturação');
    t_true((bool)array_filter($items, fn($s) => ($s['id'] ?? 0) === ($own['id'] ?? -1)), 'share_list: os outros links continuam na lista');
    t_eq($api(['do' => 'share_revoke', 'id' => (int)($bs['id'] ?? 0)], $member)['code'], 403, 'share_revoke: sem Financeiro não revoga um link de faturação');
    t_eq($api(['do' => 'share_revoke', 'id' => (int)($own['id'] ?? 0)], $member)['code'], 403, 'share_revoke: não revoga um link de outra pessoa');
    $mine = $api(['do' => 'share_create', 'key' => $f, 'hours' => 24], $member)['json']['share'] ?? [];
    t_eq($api(['do' => 'share_revoke', 'id' => (int)($mine['id'] ?? 0)], $member)['code'], 200, 'share_revoke: revoga o seu próprio link');
}
t_true((bool)array_filter($api(['do' => 'share_list'])['json']['items'] ?? [], fn($s) => ($s['id'] ?? 0) === ($bs['id'] ?? -1)), 'share_list: com Financeiro vê o link de faturação');
t_eq($api(['do' => 'share_revoke', 'id' => (int)($bs['id'] ?? 0)])['code'], 200, 'share_revoke: um admin com Financeiro revoga');
t_eq($api(['do' => 'share_revoke', 'id' => (int)($own['id'] ?? 0)])['code'], 200, 'share_revoke: quem criou revoga');
r2_delete($bill); r2_delete($f); drive_catalog_invalidate();
t_done();
