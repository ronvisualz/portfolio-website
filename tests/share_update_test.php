<?php
// §32.2: «Só ver» / «Pode editar» ao criar; mudar a permissão depois sem mudar o link; editar exige poder editar a Drive.
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';

$dir = 'Empresa/Teste32/perm-' . bin2hex(random_bytes(3)) . '/';
tests_put($dir . 'a.txt');
$api = fn(array $b, int $uid = 1) => tests_http('POST', '/admin/drive_api.php', $b, $uid);

$c = $api(['do' => 'share_create', 'key' => $dir, 'hours' => 24, 'perm' => 'edit']);
t_eq($c['code'], 200, 'cria uma partilha «Pode editar»');
t_eq($c['json']['share']['perm'] ?? '', 'edit', 'a partilha fica com perm=edit');
$id = (int)($c['json']['share']['id'] ?? 0);
$url = (string)($c['json']['share']['url'] ?? '');

$u = $api(['do' => 'share_update', 'id' => $id, 'perm' => 'view']);
t_eq($u['code'], 200, 'muda a permissão');
t_eq($u['json']['share']['perm'] ?? '', 'view', 'passa a «Só ver»');
t_eq($u['json']['share']['url'] ?? '', $url, 'o link não muda');
t_eq((string)$pdo->query('SELECT created_by FROM drive_shares WHERE id = ' . $id)->fetchColumn(), '1', 'fica registado quem criou');

// quem só pode ver a Drive não cria nem passa a «Pode editar»
$pdo->prepare("INSERT INTO permissions (role, user_id, tab, level, scope) VALUES ('', 3, 'drive', 1, NULL)")->execute();
try {
    t_eq($api(['do' => 'share_create', 'key' => $dir, 'hours' => 24, 'perm' => 'edit'], 3)['code'], 403, 'só ver a Drive: não cria «Pode editar»');
    t_eq($api(['do' => 'share_update', 'id' => $id, 'perm' => 'edit'], 3)['code'], 403, 'só ver a Drive: não passa a «Pode editar»');
} finally {
    $pdo->prepare("DELETE FROM permissions WHERE role = '' AND user_id = 3 AND tab = 'drive'")->execute();
}
t_eq($api(['do' => 'share_update', 'id' => $id, 'perm' => 'tudo'])['code'], 400, 'permissão inválida → 400');

// revisão de segurança: quem não criou a partilha (e não é admin) não a altera; faturação só com o Financeiro
$member = (int)$pdo->query("SELECT id FROM users WHERE is_active = 1 AND role NOT IN ('admin','superadmin') ORDER BY id LIMIT 1")->fetchColumn();
$bill = tests_put('Empresa/Financeiro/2026/10/upd-' . bin2hex(random_bytes(2)) . '.pdf', '%PDF-1.4', 'application/pdf');
$b = $api(['do' => 'share_create', 'key' => $bill, 'hours' => 24, 'password' => 'segredo123']);
$bid = (int)($b['json']['share']['id'] ?? 0);
if ($member) {
    $x = $api(['do' => 'share_update', 'id' => $bid, 'clear_password' => true], $member);
    t_eq($x['code'], 403, 'sem Financeiro não altera um link de faturação');
    t_true(!str_contains((string)$x['body'], 'partilha.php?t='), 'e não recebe o link');
    t_true((bool)$pdo->query('SELECT pw_hash IS NOT NULL FROM drive_shares WHERE id = ' . $bid)->fetchColumn(), 'a password continua lá');
    t_eq($api(['do' => 'share_update', 'id' => $id, 'hours' => 168], $member)['code'], 403, 'não altera um link criado por outra pessoa');
    $own = $api(['do' => 'share_create', 'key' => $dir . 'a.txt', 'hours' => 24], $member);
    t_eq($api(['do' => 'share_update', 'id' => (int)($own['json']['share']['id'] ?? 0), 'hours' => 48], $member)['code'], 200, 'altera o seu próprio link');
}
t_eq($api(['do' => 'share_update', 'id' => $bid, 'hours' => 48])['code'], 200, 'um admin com Financeiro altera');
$pdo->exec('UPDATE drive_shares SET revoked = 1 WHERE id = ' . $bid);
r2_delete($bill);
r2_delete($dir . 'a.txt'); drive_catalog_invalidate();
t_done();
