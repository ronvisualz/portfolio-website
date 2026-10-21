<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ui.php';
require_once __DIR__ . '/inc/budget_pdf.php';
$user = require_login();
require_page('orcamentacao');

// A proposta em PDF de uma versão, para descarregar (a cópia no Drive faz-se
// em «Gerar proposta», ui_api.php?a=budget_pdf). Só preço, prazos e condições:
// quem não vê o Financeiro recebe exatamente o mesmo ficheiro.

$v = budget_version_get($pdo, (int)($_GET['v'] ?? 0));
$sheet = $v ? budget_sheet_by_id($pdo, (int)$v['sheet_id']) : null;
$deal = $sheet ? budget_deal_row($pdo, (int)$sheet['deal_id']) : null;
if (!$deal) {
    http_response_code(404);
    exit('Proposta não encontrada.');
}
$bytes = budget_pdf_build($pdo, $deal, $v);
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['ver']) ? 'inline' : 'attachment') . '; filename="' . budget_pdf_name($deal, $v) . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
