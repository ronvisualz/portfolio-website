<?php
/**
 * topper. admin — exportação para Excel e cópias de segurança
 *
 * export_sheets(): as folhas do «Exportar para Excel» (Negócios, Custos,
 *   Leads, Contactos), já com as contas feitas. Serve o contabilista.
 * backup_to_r2(): cópia completa da base em SQL comprimido, guardada no R2
 *   em .topper/backups/ (a pasta de sistema, que o Drive não mostra).
 *   Ficam os últimos BACKUP_KEEP. Corre todas as noites pelo cron.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/r2.php';

const BACKUP_PREFIX = '.topper/backups/';
const BACKUP_KEEP   = 30;

/** As folhas do Excel, no formato de xlsx_build(). */
function export_sheets(PDO $pdo): array {
    $rate = vat_rate($pdo);
    $phase = ['lead' => 'Lead', 'archived' => 'Lead arquivada', 'pipeline' => 'Pipeline', 'production' => 'Em produção', 'done' => 'Concluído', 'lost' => 'Perdido'];
    $month = static fn($m): string => $m ? month_name((int)$m) : '';
    $vat = ['none' => 'Sem IVA', 'plus' => 'Acresce IVA', 'incl' => 'IVA incluído'];

    $neg = [];
    foreach ($pdo->query(
        "SELECT d.*, c.name AS contact_name, f.name AS funnel_name, ofn.name AS origin_funnel_name, s.name AS stage_name, u.name AS owner_name
           FROM deals d LEFT JOIN contacts c ON c.id = d.contact_id LEFT JOIN funnels f ON f.id = d.funnel_id
           LEFT JOIN funnels ofn ON ofn.id = d.origin_funnel_id LEFT JOIN funnel_stages s ON s.id = d.stage_id
           LEFT JOIN users u ON u.id = d.owner_id
          WHERE d.code IS NOT NULL ORDER BY d.code"
    ) as $d) {
        $m = deal_money($d, deal_costs($pdo, (int)$d['id'], $rate), $rate);
        $neg[] = [$d['code'], $d['year'] ? (int)$d['year'] : null, $month($d['month']), $d['name'], $d['contact_name'] ?? '',
                  $d['funnel_name'] ?? '', $d['origin_funnel_name'] ?? '', $d['stage_name'] ?? '', $phase[$d['phase']] ?? $d['phase'],
                  $d['job_type'], $m['budget_net'], $m['budget_gross'], $m['expenses'], $m['profit'], $m['vat_out'], $m['vat_in'],
                  (int)$d['is_paid'] === 1, (int)$d['is_international'] === 1, (int)$d['has_vat'] === 1, $d['owner_name'] ?? '',
                  $d['phase'] === 'lost' ? $d['lost_reason'] : '', (string)$d['notes']];
    }

    $cst = [];
    foreach ($pdo->query('SELECT c.*, d.code, d.name AS deal_name FROM costs c LEFT JOIN deals d ON d.id = c.deal_id
                           ORDER BY c.year, c.month, d.code, c.id') as $c) {
        $net = cost_net($c, $rate); $v = cost_vat($c, $rate);
        $cst[] = [$c['deal_id'] ? $c['code'] : 'Empresa', $c['deal_id'] ? $c['deal_name'] : '', $c['year'] ? (int)$c['year'] : null,
                  $month($c['month']), $c['name'], $c['role'], (float)$c['amount'], $vat[$c['vat_mode']] ?? $c['vat_mode'],
                  $net, $v, round($net + $v, 2), (int)$c['is_closed'] === 1, (string)$c['notes']];
    }

    $state = ['novo' => 'Novo', 'sem_resposta' => 'Sem resposta', 'follow_up' => 'Follow-Up'];   // §12.4
    $leads = [];
    foreach ($pdo->query("SELECT d.*, u.name AS owner_name FROM deals d LEFT JOIN users u ON u.id = d.owner_id
                           WHERE d.phase IN ('lead','archived') ORDER BY d.created_at, d.id") as $l) {
        $leads[] = [$l['name'], (string)($l['company'] ?? ''), (string)($l['person'] ?? ''), $phase[$l['phase']], $l['phase'] === 'lead' ? ($state[$l['lead_state']] ?? $l['lead_state']) : '',
                    $l['types'], $l['service'], $l['priority'], $l['email'], $l['phone'], (string)$l['links'], $l['origin'],
                    $l['campaign'], $l['owner_name'] ?? 'Por atribuir', (string)($l['last_contact_at'] ?? $l['last_touch'] ?? ''), $l['archived_reason'], (string)$l['notes']];
    }

    $contacts = [];
    foreach ($pdo->query('SELECT * FROM contacts ORDER BY name') as $c) {
        $types = array_keys(array_filter(['Cliente' => $c['is_client'], 'Fornecedor' => $c['is_supplier'],
                                          'Parceiro' => $c['is_partner'], 'Freelancer' => $c['is_freelancer']], fn($v) => (int)$v));
        $contacts[] = [$c['name'], implode(', ', $types), $c['category'], $c['service'], $c['contact_person'], $c['email'], $c['phone'],
                       $c['nif'], $c['website'], $c['rate'], $c['rating'] ? str_repeat('★', (int)$c['rating']) : '', (string)$c['notes']];
    }

    return [
        ['name' => 'Negócios',
         'head' => ['ID', 'Ano', 'Mês', 'Nome', 'Cliente', 'Funil', 'Funil de origem', 'Etapa', 'Fase', 'Tipo', 'Budget (s/ IVA)', 'Budget (c/ IVA)',
                    'Gastos', 'Lucro', 'IVA liquidado', 'IVA suportado', 'Pago', 'Internacional', 'Fatura com IVA', 'Responsável', 'Motivo (perdido)', 'Notas'],
         'types' => ['s', 'n', 's', 's', 's', 's', 's', 's', 's', 's', 'm', 'm', 'm', 'm', 'm', 'm', 'b', 'b', 'b', 's', 's', 's'],
         'widths' => [12, 7, 11, 28, 22, 18, 18, 14, 13, 16, 15, 15, 13, 13, 13, 13, 7, 12, 13, 18, 26, 36],
         'rows' => $neg],
        ['name' => 'Custos',
         'head' => ['ID negócio', 'Negócio', 'Ano', 'Mês', 'Nome', 'Função', 'Valor', 'Tipo de IVA', 'Valor s/ IVA', 'IVA', 'Total', 'Fechado / pago', 'Notas'],
         'types' => ['s', 's', 'n', 's', 's', 's', 'm', 's', 'm', 'm', 'm', 'b', 's'],
         'widths' => [12, 24, 7, 11, 22, 22, 13, 13, 14, 12, 13, 14, 36],
         'rows' => $cst],
        ['name' => 'Leads',
         'head' => ['Nome', 'Empresa', 'Pessoa de contacto', 'Fase', 'Estado', 'Tipo', 'Serviço', 'Prioridade', 'Email', 'Telefone(s)', 'Redes sociais', 'Origem', 'Campanha', 'Responsável', 'Último contacto', 'Motivo (arquivo)', 'Notas'],
         'types' => array_fill(0, 17, 's'),
         'widths' => [26, 24, 22, 14, 13, 20, 14, 11, 26, 16, 36, 9, 16, 18, 16, 16, 36],
         'rows' => $leads],
        ['name' => 'Contactos',
         'head' => ['Nome', 'Tipo', 'Categoria', 'Serviço', 'Pessoa de contacto', 'Email', 'Telefone', 'NIF', 'Site / redes', 'Tarifa', 'Avaliação', 'Notas'],
         'types' => array_fill(0, 12, 's'),
         'widths' => [24, 18, 14, 30, 20, 26, 16, 12, 30, 20, 10, 36],
         'rows' => $contacts],
    ];
}

/** A base inteira em SQL (estrutura e dados). Só MySQL. */
function db_dump(PDO $pdo): string {
    $sql = "-- topper. admin · cópia de segurança · " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $t = str_replace('`', '', (string)$t);
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $sql .= "DROP TABLE IF EXISTS `$t`;\n$create;\n";
        $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM);
        $batch = [];
        while ($row = $st->fetch()) {
            $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
            if (count($batch) === 200) { $sql .= "INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n"; $batch = []; }
        }
        if ($batch) $sql .= "INSERT INTO `$t` VALUES\n" . implode(",\n", $batch) . ";\n";
        $sql .= "\n";
    }
    return $sql . "SET FOREIGN_KEY_CHECKS = 1;\n";
}

/** Guarda a cópia no R2 e apaga as mais antigas. Devolve a chave criada. */
function backup_to_r2(PDO $pdo): string {
    if (!r2_config()) throw new RuntimeException('O R2 não está configurado.');
    $key = BACKUP_PREFIX . 'topper-admin-' . date('Y-m-d-His') . '.sql.gz';
    r2_put($key, gzencode(db_dump($pdo), 9), 'application/gzip');

    $old = array_filter(r2_list(BACKUP_PREFIX)['files'] ?? [], fn($f) => str_ends_with((string)($f['key'] ?? ''), '.sql.gz'));
    usort($old, fn($a, $b) => strcmp($b['key'], $a['key']));
    foreach (array_slice($old, BACKUP_KEEP) as $f) r2_delete($f['key']);
    return $key;
}
