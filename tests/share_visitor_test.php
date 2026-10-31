<?php
// §32.2: quem tem um link «Pode editar» carrega, cria pastas, renomeia e substitui versões — nunca apaga, nunca sai da
// pasta partilhada. Tudo verificado no servidor (partilha.php, sem sessão), registado e avisado ao dono.
require __DIR__ . '/boot.php';
require __DIR__ . '/fixtures.php';
require_once __DIR__ . '/../admin/inc/drive_share.php';

$dir = 'Empresa/Teste32/visita-' . bin2hex(random_bytes(3)) . '/';
$mailsBefore = substr_count((string)@file_get_contents('/tmp/claude-0/-home-user-portfolio-website/07a7b6fb-2708-535b-b71f-5de7fa1226a8/scratchpad/server.log'), 'email para ' . (string)$pdo->query('SELECT email FROM users WHERE id = 1')->fetchColumn());
tests_put($dir . 'a.txt', 'versao 1');
$api = fn(array $b) => tests_http('POST', '/admin/drive_api.php', $b, 1);
$mk = fn(string $perm) => $api(['do' => 'share_create', 'key' => $dir, 'hours' => 24, 'perm' => $perm])['json']['share'];

/** O visitante: lê o token do formulário da página e faz POST em JSON (sem sessão). */
function visit(string $url, array $body): array {
    static $ft = [];
    $page = $ft[$url] ??= tests_http('GET', $url, [], 0)['body'];
    $tok = preg_match('/data-ft="([^"]+)"/', $page, $m) ? $m[1] : '';
    return tests_http('POST', $url, $body + ['name' => 'Ana Visitante'], 0, false, ['X-Share-Form: ' . $tok]);
}
$put = function (string $url, string $body): int {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream']]);
    curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    return $code;
};

// «Só ver»: nada de escrever
$view = $mk('view');
$path = parse_url($view['url'], PHP_URL_PATH) . '?' . parse_url($view['url'], PHP_URL_QUERY);
t_eq(visit($path, ['a' => 'sign', 'rel' => 'x.txt', 'size' => 5])['code'], 403, '«Só ver» não deixa carregar');

// «Pode editar»
$edit = $mk('edit');
$P = parse_url($edit['url'], PHP_URL_PATH) . '?' . parse_url($edit['url'], PHP_URL_QUERY);
$s = visit($P, ['a' => 'sign', 'rel' => 'novo.txt', 'size' => 5]);
t_eq($s['code'], 200, 'pede para carregar');
t_eq($put((string)($s['json']['url'] ?? ''), 'hello'), 200, 'o browser envia direto para o armazenamento');
$d = visit($P, ['a' => 'done', 'rel' => 'novo.txt']);
t_eq($d['code'], 200, 'confirma o envio');
t_eq(r2_get($dir . 'novo.txt'), 'hello', 'o ficheiro ficou na pasta, com o conteúdo enviado');
$log = $pdo->query('SELECT action, visitor, ip_part FROM drive_share_log WHERE share_id = ' . (int)$edit['id'] . ' ORDER BY id')->fetchAll();
t_eq($log[0]['action'] ?? '', 'upload', 'fica registado o envio');
t_eq($log[0]['visitor'] ?? '', 'Ana Visitante', 'com o nome do visitante');
t_true(($log[0]['ip_part'] ?? '') !== '' && !preg_match('/^\d+\.\d+\.\d+\.\d+$/', $log[0]['ip_part']), 'e o IP parcial (' . ($log[0]['ip_part'] ?? '') . ')');

// o mesmo nome sem «Substituir» → outro nome
$s2 = visit($P, ['a' => 'sign', 'rel' => 'novo.txt', 'size' => 5]);
t_eq($s2['json']['rel'] ?? '', 'novo (2).txt', 'nome repetido sem substituir: «novo (2).txt»');

// substituir guarda a versão anterior
$r = visit($P, ['a' => 'sign', 'rel' => 'a.txt', 'size' => 8, 'replace' => true]);
t_eq($r['code'], 200, 'pede para substituir');
$ver = $pdo->query("SELECT ver_key FROM drive_versions WHERE obj_key = " . $pdo->quote($dir . 'a.txt') . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
t_true($ver && r2_get((string)$ver) === 'versao 1', 'a versão anterior fica no histórico');
$put((string)($r['json']['url'] ?? ''), 'versao 2');
visit($P, ['a' => 'done', 'rel' => 'a.txt', 'replaced' => true]);
t_eq(r2_get($dir . 'a.txt'), 'versao 2', 'o ficheiro passa a ser a versão nova');

// renomear para um nome que existe → 409; nunca sobrescreve
t_eq(visit($P, ['a' => 'rename', 'rel' => 'a.txt', 'to' => 'novo.txt'])['code'], 409, 'renomear para um nome existente → 409');
t_eq(visit($P, ['a' => 'rename', 'rel' => 'a.txt', 'to' => 'b.txt'])['code'], 200, 'renomear');
t_true(r2_head($dir . 'b.txt') !== null && r2_head($dir . 'a.txt') === null, 'o ficheiro tem o nome novo');

// criar subpasta
t_eq(visit($P, ['a' => 'mkdir', 'rel' => 'Fotos'])['code'], 200, 'criar subpasta');
t_true(drive_folder_exists($dir . 'Fotos/'), 'a subpasta existe');
tests_put($dir . 'Fotos/um.jpg', 'jpg');
t_eq(visit($P, ['a' => 'rename', 'rel' => 'Fotos/', 'to' => 'Imagens'])['code'], 200, 'renomear uma pasta');
t_true(drive_folder_exists($dir . 'Imagens/') && r2_get($dir . 'Imagens/um.jpg') === 'jpg' && !drive_folder_exists($dir . 'Fotos/'), 'a pasta e o que tem dentro passam ao nome novo');

// nunca apagar, nunca sair da pasta
foreach (['delete', 'purge', 'trash', 'move', 'restore'] as $a) t_eq(visit($P, ['a' => $a, 'rel' => 'b.txt'])['code'], 400, "ação «{$a}» não existe → 400");
t_true(r2_head($dir . 'b.txt') !== null, 'nada foi apagado');
foreach (['../x.txt', '%2e%2e/x.txt', '/x.txt', '.topper/x.txt', 'sub//x.txt', 'a\\b.txt', "x\0.txt", ''] as $bad)
    t_eq(visit($P, ['a' => 'sign', 'rel' => $bad, 'size' => 5])['code'], 400, 'caminho inválido «' . addcslashes($bad, "\0") . '» → 400');
t_eq(visit($P, ['a' => 'rename', 'rel' => 'b.txt', 'to' => '../fora.txt'])['code'], 400, 'renomear para fora → 400');

// limite de tamanho
t_eq(visit($P, ['a' => 'sign', 'rel' => 'grande.bin', 'size' => 6 * 1024 * 1024 * 1024])['code'], 413, 'acima do limite → 413');
// sem nome do visitante
t_eq(tests_http('POST', $P, ['a' => 'mkdir', 'rel' => 'X'], 0, false, ['X-Share-Form: ' . (preg_match('/data-ft="([^"]+)"/', tests_http('GET', $P, [], 0)['body'], $m) ? $m[1] : '')])['code'], 400, 'sem nome do visitante → 400');
// sem o token do formulário
t_eq(tests_http('POST', $P, ['a' => 'mkdir', 'rel' => 'Y', 'name' => 'Ana'], 0)['code'], 403, 'sem o token do formulário → 403');

// o dono é avisado: nas notificações e por email (o primeiro de cada meia hora; na bancada o email fica no registo)
$owner = (string)$pdo->query('SELECT email FROM users WHERE id = 1')->fetchColumn();
$slog = (string)@file_get_contents('/tmp/claude-0/-home-user-portfolio-website/07a7b6fb-2708-535b-b71f-5de7fa1226a8/scratchpad/server.log');
t_true(substr_count($slog, 'email para ' . $owner) > $mailsBefore && str_contains($slog, 'Ana Visitante'), 'o dono recebe um email');
$al = drive_share_alerts($pdo, 1);
t_true((bool)array_filter($al, fn($x) => str_contains($x['title'], 'Ana Visitante')), 'o dono da partilha recebe o aviso');

// revogado a meio → 410
$api(['do' => 'share_revoke', 'id' => (int)$edit['id']]);
t_eq(visit($P, ['a' => 'sign', 'rel' => 'tarde.txt', 'size' => 5])['code'], 410, 'link revogado → 410');

foreach (r2_list_all($dir, 5) as $o) r2_delete($o['key']);
drive_catalog_invalidate();
t_done();
