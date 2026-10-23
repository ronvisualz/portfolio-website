<?php
/**
 * topper. admin — a pasta de cada negócio no Drive
 *
 * «Projetos/<ID>_<NOME>/» (e «Leads/…», «Arquivo/…» conforme a fase), encontrada pelo ID:
 * mudar o nome do negócio não a parte. Uma pasta já ligada (deals.drive_folder — por
 * exemplo uma pasta antiga NN_NOME_PROJECT ligada na migração) ganha sempre. O registo
 * de ficheiros (inc/files.php) decide onde fica e muda-a de sítio com o registo.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/files.php';

/** Cria a pasta se faltar e guarda a ligação. Devolve o prefixo. */
function drive_deal_folder(PDO $pdo, array $deal): string {
    if (!r2_config()) throw new RuntimeException('O Drive não está ligado neste servidor.');
    $p = files_folder($pdo, $deal);
    if ($p === null) throw new RuntimeException('Este registo não foi encontrado.');
    return $p;
}
