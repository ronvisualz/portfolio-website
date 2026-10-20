<?php
/**
 * topper. admin — a pasta de cada negócio no Drive
 *
 * «Projetos/<ID>_<NOME>/», encontrada pelo ID: mudar o nome do negócio não
 * a parte. Uma pasta já ligada (deals.drive_folder — por exemplo uma pasta
 * antiga NN_NOME_PROJECT ligada na migração) ganha sempre. Usa só funções
 * do Drive (inc/drive.php, inc/r2.php), sem mexer nele.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/drive.php';

/** Cria a pasta se faltar e guarda a ligação. Devolve o prefixo. */
function drive_deal_folder(PDO $pdo, array $deal): string {
    $code = (string)$deal['code'];
    if ($code === '') throw new RuntimeException('Este registo ainda não tem ID.');
    $p = (string)$deal['drive_folder'];
    if ($p === '') {
        foreach (r2_list('Projetos/')['folders'] ?? [] as $f) {
            $leaf = substr(rtrim($f, '/'), strlen('Projetos/'));
            if (str_starts_with($leaf, $code . '_') || $leaf === $code) { $p = 'Projetos/' . $leaf . '/'; break; }
        }
    }
    if ($p === '') $p = 'Projetos/' . $code . '_' . drive_slug((string)$deal['name']) . '/';
    if (!drive_folder_exists($p)) {
        r2_mkdir_p($p);
        drive_catalog_invalidate();
    }
    if ($p !== (string)$deal['drive_folder']) {
        $pdo->prepare('UPDATE deals SET drive_folder = ? WHERE id = ?')->execute([$p, $deal['id']]);
    }
    return $p;
}
