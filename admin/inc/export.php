<?php
/**
 * topper. admin — exportação para a folha e backups
 *
 * export_to_sheet(): reescreve os separadores «Espelho · …» da folha com o
 *   que está no MySQL (consulta e contabilista). Precisa da ação «mirror»
 *   do sheets/Code.gs publicada. Edições na folha deixam de contar.
 * backup_to_r2(): cópia completa da base em SQL comprimido, guardada no
 *   R2 em Empresa/Backups/. Guarda os últimos BACKUP_KEEP dias.
 * Correm à noite pelo cron (inc/cron_run.php) ou pelo botão em Migração.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/sheet.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/r2.php';

const BACKUP_PREFIX = 'Empresa/Backups/';
const BACKUP_KEEP   = 30;

function yesno($v): string { return (int)$v ? 'Sim' : 'Não'; }

/** Os separadores do espelho: nome => [cabeçalho, linhas]. */
function export_tabs(PDO $pdo): array {
    $rate = vat_rate($pdo);
    $phase = ['lead' => 'Lead', 'archived' => 'Lead arquivada', 'pipeline' => 'Pipeline', 'production' => 'Em produção', 'done' => 'Concluído', 'lost' => 'Perdido'];

    $deals = $pdo->query(
        "SELECT d.*, c.name AS contact_name, f.name AS funnel_name, of.name AS origin_funnel_name, s.name AS stage_name, u.name AS owner_name
           FROM deals d LEFT JOIN contacts c ON c.id = d.contact_id LEFT JOIN funnels f ON f.id = d.funnel_id
           LEFT JOIN funnels of ON of.id = d.origin_funnel_id
           LEFT JOIN funnel_stages s ON s.id = d.stage_id LEFT JOIN users u ON u.id = d.owner_id
          WHERE d.code IS NOT NULL ORDER BY d.code"
    )->fetchAll();
    $neg = [];
    foreach ($deals as $d) {
        $m = deal_money($d, deal_costs($pdo, (int)$d['id'], $rate), $rate);
        $neg[] = [$d['code'], $d['year'], $d['month'] ? month_name((int)$d['month']) : '', $d['name'], $d['contact_name'] ?? '',
                  $d['funnel_name'] ?? '', $d['origin_funnel_name'] ?? '', $d['stage_name'] ?? '', $phase[$d['phase']] ?? $d['phase'], $d['job_type'],
                  $m['budget_net'], $m['budget_gross'], $m['expenses'], $m['profit'], $m['vat_out'], $m['vat_in'],
                  yesno($d['is_paid']), yesno($d['is_international']), yesno($d['has_vat']), $d['owner_name'] ?? '',
                  $d['phase'] === 'lost' ? $d['lost_reason'] : '', $d['notes'] ?? ''];
    }

    $costs = $pdo->query('SELECT c.*, d.code, d.name AS deal_name FROM costs c LEFT JOIN deals d ON d.id = c.deal_id
                           ORDER BY c.year, c.month, d.code, c.id')->fetchAll();
    $cst = [];
    foreach ($costs as $c) {
        $net = cost_net($c, $rate); $vat = cost_vat($c, $rate);
        $cst[] = [$c['deal_id'] ? $c['code'] : 'Empresa', $c['deal_id'] ? $c['deal_name'] : '', $c['year'], $c['month'] ? month_name((int)$c['month']) : '',
                  $c['name'], $c['role'], (float)$c['amount'], VAT_MODES[$c['vat_mode']] ?? $c['vat_mode'], $net, $vat, round($net + $vat, 2),
                  yesno($c['is_closed']), $c['notes'] ?? ''];
    }

    $leads = [];
    foreach ($pdo->query("SELECT d.*, u.name AS owner_name FROM deals d LEFT JOIN users u ON u.id = d.owner_id
                           WHERE d.phase IN ('lead','archived') ORDER BY d.created_at") as $l) {
        $leads[] = [$l['name'], $phase[$l['phase']], $l['phase'] === 'lead' ? (LEAD_STATE_LABELS[$l['lead_state']] ?? $l['lead_state']) : '',
                    $l['types'], $l['service'], $l['priority'], $l['email'], $l['phone'], $l['links'], $l['origin'], $l['campaign'],
                    $l['owner_name'] ?? 'Por atribuir', $l['last_touch'] ?? '', $l['archived_reason'], $l['notes'] ?? ''];
    }

    $contacts = [];
    foreach ($pdo->query('SELECT * FROM contacts ORDER BY name') as $c) {
        $types = array_keys(array_filter(['Cliente' => $c['is_client'], 'Fornecedor' => $c['is_supplier'], 'Parceiro' => $c['is_partner'], 'Freelancer' => $c['is_freelancer']], fn($v) => (int)$v));
        $contacts[] = [$c['name'], implode(', ', $types), $c['category'], $c['service'], $c['contact_person'], $c['email'], $c['phone'],
                       $c['nif'], $c['website'], $c['rate'], $c['rating'] ? str_repeat('★', (int)$c['rating']) : '', $c['notes'] ?? ''];
    }

    return [
        'Espelho · Negócios' => [['ID', 'Ano', 'Mês', 'Nome', 'Cliente', 'Funil', 'Funil de origem', 'Etapa', 'Fase', 'Tipo', 'Budget (s/ IVA)', 'Budget (c/ IVA)',
                                  'Gastos', 'Lucro', 'IVA liquidado', 'IVA suportado', 'Pago', 'Internacional', 'Fatura com IVA', 'Responsável', 'Motivo (perdido)', 'Notas'], $neg],
        'Espelho · Custos'   => [['ID negócio', 'Negócio', 'Ano', 'Mês', 'Nome', 'Função', 'Valor', 'Tipo de IVA', 'Valor s/ IVA', 'IVA', 'Total', 'Fechado / pago', 'Notas'], $cst],
        'Espelho · Leads'    => [['Nome', 'Fase', 'Estado', 'Tipo', 'Serviço', 'Prioridade', 'Email', 'Telefone', 'Contactos', 'Origem', 'Campanha', 'Responsável', 'Último contacto', 'Motivo (arquivo)', 'Notas'], $leads],
        'Espelho · Contactos'=> [['Nome', 'Tipo', 'Categoria', 'Serviço', 'Pessoa de contacto', 'Email', 'Telefone', 'NIF', 'Site / redes', 'Tarifa', 'Avaliação', 'Notas'], $contacts],
    ];
}

const LEAD_STATE_LABELS = ['novo' => 'Novo', 'contactado' => 'Contactado', 'em_conversa' => 'Em conversa'];

/** Escreve o espelho na folha. Devolve [separador => linhas]. */
function export_to_sheet(PDO $pdo): array {
    $out = [];
    foreach (export_tabs($pdo) as $tab => [$header, $rows]) {
        $r = sheet_call('POST', ['action' => 'mirror', 'tab' => $tab, 'header' => $header, 'rows' => $rows], 60);
        $out[$tab] = (int)($r['rows'] ?? count($rows));
    }
    $pdo->prepare(db_is_sqlite($pdo)
        ? "INSERT OR REPLACE INTO settings (skey, svalue) VALUES ('last_export_at', ?)"
        : "INSERT INTO settings (skey, svalue) VALUES ('last_export_at', ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)"
    )->execute([date('Y-m-d H:i')]);
    return $out;
}

/** A base inteira em SQL (estrutura e dados). Só MySQL. */
function db_dump(PDO $pdo): string {
    $sql = "-- topper. admin · cópia de segurança · " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_NUM)[1];
        $sql .= "DROP TABLE IF EXISTS `$t`;\n$create;\n";
        $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $t) . '`', PDO::FETCH_NUM);
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
    $gz = gzencode(db_dump($pdo), 9);
    r2_expect(r2_request('PUT', $key, [], $gz, ['content-type' => 'application/gzip'], 120), 'guardar a cópia de segurança');

    $old = array_filter(r2_list(BACKUP_PREFIX)['files'] ?? [], fn($f) => str_ends_with((string)($f['key'] ?? ''), '.sql.gz'));
    usort($old, fn($a, $b) => strcmp($b['key'], $a['key']));
    foreach (array_slice($old, BACKUP_KEEP) as $f) r2_delete($f['key']);
    return $key;
}
