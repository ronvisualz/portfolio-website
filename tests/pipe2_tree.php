<?php
/**
 * Pipeline v2 — árvores de código de outro commit, para provar reversões («o código revertido contra a base de agora»).
 *
 *   pipe2_tree($commit, $overlay, $testFiles, $tag)  monta, numa pasta p2t_<pid>_<tag> do sys_get_temp_dir():
 *       · o admin/ de $commit (git archive);
 *       · ligações simbólicas para a configuração local ignorada pelo Git (admin/**.php fora do Git: credenciais e
 *         ligações da bancada, que não se copiam);
 *       · $overlay = [caminho => commit]: esses ficheiros vêm desse commit (git show), por cima;
 *       · $testFiles = [caminho, …]: copiados da cópia de trabalho (a base de testes de agora).
 *     A pasta apaga-se no fim do processo que a criou (e as que ficaram de processos que já não existem, na 1.ª chamada).
 *   pipe2_tree_php($dir, $script, $args)  como pipe2_sub, mas na árvore: php <$dir>/<$script> <args>, a partir de
 *     $dir; ['code', 'out', 'err', 'json'].
 *   pipe2_tree_links($dir)  os caminhos reais da configuração ligada (para distinguir os ficheiros carregados que não
 *     são da árvore).
 *
 * Não depende da plataforma (só do git e do tar): pode incluir-se antes ou depois de tests/boot.php.
 */

$GLOBALS['PIPE2_TREES'] = $GLOBALS['PIPE2_TREES'] ?? [];   // pasta => [ligações]

/** Apaga uma árvore deste helper (só dentro do sys_get_temp_dir(), com o prefixo p2t_). */
function pipe2_tree_rm(string $dir): void {
    $tmp = rtrim(sys_get_temp_dir(), '/') . '/p2t_';
    if (!str_starts_with($dir, $tmp) || !is_dir($dir) || is_link($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (is_link($p) || !$f->isDir()) unlink($p);
        else rmdir($p);
    }
    rmdir($dir);
}

/** Corre um comando da shell; [código, stdout, stderr]. */
function pipe2_tree_sh(string $cmd): array {
    $p = proc_open(['/bin/sh', '-c', $cmd], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) throw new RuntimeException('Não foi possível correr: ' . $cmd);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}

function pipe2_tree_mkdir(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar ' . $dir);
}

/** Monta a árvore (ver o cabeçalho) e devolve a pasta. */
function pipe2_tree(string $commit, array $overlay = [], array $testFiles = [], string $tag = 'tree'): string {
    static $swept = false;
    if (!preg_match('/^\w+$/', $tag)) throw new InvalidArgumentException('pipe2_tree: etiqueta inválida ' . $tag);
    $root = dirname(__DIR__);
    $tmp = rtrim(sys_get_temp_dir(), '/');
    if (!$swept) {
        $swept = true;
        foreach (glob($tmp . '/p2t_*', GLOB_ONLYDIR) ?: [] as $old) {
            if (preg_match('#/p2t_(\d+)_\w+$#', $old, $m) && (int)$m[1] !== getmypid() && !file_exists('/proc/' . $m[1])) pipe2_tree_rm($old);
        }
        register_shutdown_function(static function (): void {
            foreach (array_keys($GLOBALS['PIPE2_TREES']) as $d) pipe2_tree_rm($d);
        });
    }
    $dir = $tmp . '/p2t_' . getmypid() . '_' . $tag;
    pipe2_tree_rm($dir);
    pipe2_tree_mkdir($dir);
    $GLOBALS['PIPE2_TREES'][$dir] = [];
    $git = 'git -C ' . escapeshellarg($root);

    [$code, , $err] = pipe2_tree_sh($git . ' archive ' . escapeshellarg($commit) . ' admin | tar -x -C ' . escapeshellarg($dir));
    if ($code !== 0) throw new RuntimeException("git archive $commit falhou: " . substr($err, 0, 500));

    // A configuração local (ignorada pelo Git): ligações simbólicas, para não copiar credenciais.
    [$code, $out, $err] = pipe2_tree_sh($git . ' ls-files --others --ignored --exclude-standard --directory admin');
    if ($code !== 0) throw new RuntimeException('git ls-files falhou: ' . substr($err, 0, 500));
    foreach (preg_split('/\R/', trim($out)) as $rel) {
        if (!preg_match('#^admin/[\w/]+\.php$#', $rel) || file_exists($dir . '/' . $rel)) continue;
        pipe2_tree_mkdir(dirname($dir . '/' . $rel));
        if (!symlink($root . '/' . $rel, $dir . '/' . $rel)) throw new RuntimeException('Não foi possível ligar ' . $rel);
        $GLOBALS['PIPE2_TREES'][$dir][] = realpath($root . '/' . $rel);
    }

    foreach ($overlay as $rel => $from) {
        if (!preg_match('#^[\w./-]+$#', (string)$rel) || str_contains((string)$rel, '..')) throw new InvalidArgumentException('pipe2_tree: caminho inválido ' . $rel);
        $dst = $dir . '/' . $rel;
        pipe2_tree_mkdir(dirname($dst));
        if (is_link($dst)) unlink($dst);
        [$code, , $err] = pipe2_tree_sh($git . ' show ' . escapeshellarg($from . ':' . $rel) . ' > ' . escapeshellarg($dst));
        if ($code !== 0) throw new RuntimeException("git show $from:$rel falhou: " . substr($err, 0, 500));
    }

    foreach ($testFiles as $rel) {
        if (!preg_match('#^[\w./-]+$#', (string)$rel) || str_contains((string)$rel, '..')) throw new InvalidArgumentException('pipe2_tree: caminho inválido ' . $rel);
        pipe2_tree_mkdir(dirname($dir . '/' . $rel));
        if (!copy($root . '/' . $rel, $dir . '/' . $rel)) throw new RuntimeException('Não foi possível copiar ' . $rel);
    }
    return $dir;
}

/** Os caminhos reais da configuração ligada nesta árvore. */
function pipe2_tree_links(string $dir): array {
    return $GLOBALS['PIPE2_TREES'][$dir] ?? [];
}

/** php <$dir>/<$script> <args>, a partir de $dir: ['code', 'out', 'err', 'json']. */
function pipe2_tree_php(string $dir, string $script, array $args): array {
    if (!preg_match('#^[\w./-]+\.php$#', $script) || str_contains($script, '..') || !is_file($dir . '/' . $script)) {
        throw new InvalidArgumentException('pipe2_tree_php: script inválido ' . $script);
    }
    $outF = tempnam(sys_get_temp_dir(), 'p2to');
    $errF = tempnam(sys_get_temp_dir(), 'p2te');
    $p = proc_open(array_merge([PHP_BINARY, $dir . '/' . $script], array_map('strval', $args)),
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outF, 'w'], 2 => ['file', $errF, 'w']], $pipes, $dir);
    if (!is_resource($p)) throw new RuntimeException('Não foi possível lançar ' . $script . ' em ' . $dir);
    $code = proc_close($p);
    $out = (string)file_get_contents($outF);
    $err = (string)file_get_contents($errF);
    @unlink($outF);
    @unlink($errF);
    return ['code' => $code, 'out' => $out, 'err' => $err, 'json' => json_decode($out, true)];
}
