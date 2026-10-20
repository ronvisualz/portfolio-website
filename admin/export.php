<?php
/**
 * topper. admin — «Exportar para Excel»: os dados da empresa num .xlsx.
 *
 * Cinco folhas com as mesmas colunas da antiga folha Google (Trabalhos,
 * Equipa, Custos empresa, Leads, Fornecedores), já com as contas feitas.
 * Serve para o contabilista e como cópia de segurança.
 */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/data.php';
require __DIR__ . '/inc/xlsx.php';
$user = require_login();
session_write_close();

$d = data_all();
$vatLabel = ['none' => 'Sem IVA', 'plus' => 'Acresce IVA', 'incl' => 'IVA incluído'];
$month = static fn(?int $m): string => ($m && isset(MONTHS_PT[$m])) ? MONTHS_PT[$m] : '';

// pela ordem de entrada, como estavam na folha
$byPos = static function (array $list): array {
    usort($list, static fn($a, $b) => [$a['position'], $a['id']] <=> [$b['position'], $b['id']]);
    return $list;
};
$jobs = $byPos($d['jobs']);
$order = [];
foreach ($jobs as $i => $j) $order[$j['id']] = $i;
$crew = $d['crew'];
usort($crew, static fn($a, $b) => [$order[$a['job_id']] ?? 0, $a['id']] <=> [$order[$b['job_id']] ?? 0, $b['id']]);

$sheets = [
    [
        'name'   => 'Trabalhos',
        'head'   => ['Ano', 'Mês', 'Nome', 'Tipo', 'Budget (s/ IVA)', 'Internacional', 'Fatura com IVA', 'Gastos manuais', 'Pago', 'Notas', 'Budget (c/ IVA)', 'Gastos', 'Lucro'],
        'types'  => ['n', 's', 's', 's', 'm', 'b', 'b', 'm', 'b', 's', 'm', 'm', 'm'],
        'widths' => [7, 11, 28, 18, 15, 13, 14, 15, 7, 36, 15, 13, 13],
        'rows'   => array_map(static fn($j) => [
            $j['year'] ?: null, $month($j['start_month']), $j['name'], $j['type_label'], $j['budget_net'], $j['is_international'],
            (bool)$j['has_vat'], $j['expenses_mode'] === 'manual' ? $j['expenses_manual'] : null, $j['is_paid'], $j['notes'],
            $j['budget_gross'], $j['expenses'], $j['profit'],
        ], $jobs),
    ],
    [
        'name'   => 'Equipa',
        'head'   => ['Trabalho', 'Pessoa', 'Função', 'Valor', 'Tipo de IVA', 'Fechado', 'Contacto', 'Notas', 'Valor s/ IVA', 'IVA', 'Total'],
        'types'  => ['s', 's', 's', 'm', 's', 'b', 's', 's', 'm', 'm', 'm'],
        'widths' => [24, 20, 24, 13, 13, 9, 26, 40, 14, 12, 13],
        'rows'   => array_map(static fn($c) => [
            $c['job'], $c['person'], $c['role'], $c['fee'], $vatLabel[$c['vat_mode']] ?? '', $c['is_confirmed'], $c['contact'], $c['notes'],
            $c['net'], $c['vat'], $c['total'],
        ], $crew),
    ],
    [
        'name'   => 'Custos empresa',
        'head'   => ['Ano', 'Mês', 'Nome', 'Valor', 'Tipo de IVA', 'Pago', 'Notas', 'Valor s/ IVA', 'IVA', 'Total'],
        'types'  => ['n', 's', 's', 'm', 's', 'b', 's', 'm', 'm', 'm'],
        'widths' => [7, 11, 26, 13, 13, 7, 30, 14, 12, 13],
        'rows'   => array_map(static fn($c) => [
            $c['year'] ?: null, $month($c['start_month']), $c['name'], $c['amount'], $vatLabel[$c['vat_mode']] ?? '', $c['is_paid'], $c['notes'],
            $c['net'], $c['vat'], $c['total'],
        ], $byPos($d['company'])),
    ],
    [
        'name'   => 'Leads',
        'head'   => ['Nome', 'Tipo', 'Contactos (redes / site)', 'Telefone', 'Fase', 'Serviço', 'Prioridade', 'Detalhe', 'Porquê abordar', 'Email', 'Notas', 'Último toque'],
        'types'  => array_fill(0, 12, 's'),
        'widths' => [26, 20, 40, 22, 16, 14, 11, 30, 50, 30, 30, 13],
        'rows'   => array_map(static fn($l) => [
            $l['name'], implode(', ', $l['types']), implode(' | ', $l['contacts']), $l['phone'], $l['stage_label'], $l['service'],
            $l['priority'], $l['detail'], $l['why'], $l['email'], $l['notes'], $l['last_touch'],
        ], $d['leads']),
    ],
    [
        'name'   => 'Fornecedores',
        'head'   => ['Nome', 'Categoria', 'Serviço', 'Pessoa de contacto', 'Email', 'Telefone', 'Site / redes', 'NIF', 'Tarifa', 'Avaliação', 'Último trabalho', 'Notas'],
        'types'  => array_fill(0, 12, 's'),
        'widths' => [22, 14, 36, 20, 26, 16, 30, 12, 50, 10, 20, 40],
        'rows'   => array_map(static fn($s) => [
            $s['name'], $s['category'], $s['service'], $s['contact'], $s['email'], $s['phone'], implode(' | ', $s['links']), $s['nif'],
            $s['rate'], $s['rating'], $s['last_job'], $s['notes'],
        ], $d['suppliers']),
    ],
];

try {
    $file = xlsx_build($sheets);
} catch (Throwable $e) {
    error_log('export: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Não foi possível criar o ficheiro Excel.');
}
$name = 'TOPPER DIGITAL ' . data_clock(time(), 'Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . filesize($file));
readfile($file);
@unlink($file);
