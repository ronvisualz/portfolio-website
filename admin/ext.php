<?php
/**
 * topper. admin — descarregar a extensão «Guardar na topper.» (Chrome, Edge, Brave, Arc) em ZIP,
 * já com o endereço desta plataforma (§26.2). Não leva token nem dados da conta: a pessoa liga-a
 * com o seu token pessoal (Perfil › Ligações).
 */
require_once __DIR__ . '/inc/bootstrap.php';
$user = require_login();
require_perm('criativo', PERM_EDIT);
if (!class_exists('ZipArchive')) { http_response_code(500); exit('O servidor não consegue fazer o ZIP (falta a extensão zip do PHP).'); }

$dir = __DIR__ . '/ext/chrome';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$server = preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host) ? ($https ? 'https://' : 'http://') . $host : 'https://topperhub.xyz';
$tmp = tempnam(sys_get_temp_dir(), 'tpext');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = 'guardar-na-topper/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
    if (basename($rel) === 'config.js') $zip->addFromString($rel, "// O endereço da plataforma (preenchido ao descarregar).\nself.TOPPER_SERVER = " . json_encode($server, JSON_UNESCAPED_SLASHES) . ";\n");
    else $zip->addFile($f->getPathname(), $rel);
}
$zip->close();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="guardar-na-topper.zip"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
