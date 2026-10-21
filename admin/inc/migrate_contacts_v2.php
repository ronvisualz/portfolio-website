<?php
/**
 * topper. admin — passo 15 da base de dados: aba Contactos v2 (§13)
 *
 * Pessoa e empresa (cargo, redes sociais, morada, aniversário, vários
 * telefones), estado Ativo/Inativo, histórico do contacto, lista de
 * serviços, linhas de serviço com preço e histórico, avaliações por
 * trabalho, relações acompanhadas, «Trazido por» nas leads e negócios e
 * tarefas ligadas a um contacto. Como os outros passos, verifica antes de
 * mudar e pode correr as vezes que forem; os contactos atuais passam para
 * o modelo novo uma vez.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_create, db_add_column
require_once __DIR__ . '/migrate_leads_v2.php';   // db_column_type
require_once __DIR__ . '/contacts.php';

function migrate_contacts_v2(PDO $pdo): void {
    $lite = db_is_sqlite($pdo);

    // ── contactos: o que falta à pessoa e à empresa ─────────
    db_add_column($pdo, 'contacts', 'role',      "VARCHAR(120) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'contacts', 'links',     'TEXT NULL', 'TEXT NULL');
    db_add_column($pdo, 'contacts', 'address',   "VARCHAR(255) NOT NULL DEFAULT ''", "TEXT NOT NULL DEFAULT ''");
    db_add_column($pdo, 'contacts', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1', 'INTEGER NOT NULL DEFAULT 1');
    db_add_column($pdo, 'contacts', 'birthday',  'DATE NULL', 'TEXT NULL');
    if (!$lite && db_column_type($pdo, 'contacts', 'phone') === 'varchar(40)') {
        $pdo->exec("ALTER TABLE contacts MODIFY phone VARCHAR(255) NOT NULL DEFAULT ''");   // vários telefones
    }

    // ── «Trazido por» e tarefas de um contacto ──────────────
    db_add_column($pdo, 'deals', 'brought_by_id', 'INT UNSIGNED NULL', 'INTEGER NULL');
    if (db_has_table($pdo, 'tasks')) db_add_column($pdo, 'tasks', 'contact_id', 'INT UNSIGNED NULL', 'INTEGER NULL');

    // ── histórico do contacto (o que não é interação nem negócio) ──
    db_create($pdo, 'contact_history', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contact_id INT UNSIGNED NOT NULL,
        event      VARCHAR(20)  NOT NULL,
        note       VARCHAR(255) NOT NULL DEFAULT '',
        user_id    INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_ch_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
        INDEX idx_contact (contact_id, created_at)");

    // ── serviços (lista comum, Definições) e preços ─────────
    db_create($pdo, 'services', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(80)  NOT NULL,
        position   INT          NOT NULL DEFAULT 0,
        is_active  TINYINT(1)   NOT NULL DEFAULT 1,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP");
    if ((int)$pdo->query('SELECT COUNT(*) FROM services')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO services (name, position) VALUES (?, ?)');
        foreach (SERVICES_DEFAULT as $i => $n) $ins->execute([$n, $i + 1]);
    }
    db_create($pdo, 'contact_services', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contact_id INT UNSIGNED NOT NULL,
        service_id INT UNSIGNED NULL,
        label      VARCHAR(160) NOT NULL DEFAULT '',
        unit       VARCHAR(16)  NULL,
        price      DECIMAL(12,2) NULL,
        vat        TINYINT(1)   NOT NULL DEFAULT 1,
        notes      TEXT         NULL,
        valid_from DATE         NULL,
        ended_at   DATE         NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_cs_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
        INDEX idx_contact (contact_id, ended_at),
        INDEX idx_service (service_id, ended_at)");

    // ── avaliação por trabalho (uma por custo pago) ─────────
    db_create($pdo, 'contact_ratings', "
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        contact_id INT UNSIGNED NOT NULL,
        cost_id    INT UNSIGNED NULL,
        deal_id    INT UNSIGNED NULL,
        stars      TINYINT      NOT NULL,
        note       VARCHAR(255) NOT NULL DEFAULT '',
        user_id    INT UNSIGNED NULL,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_cr_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
        UNIQUE KEY uq_cost (cost_id),
        INDEX idx_contact (contact_id)");

    // ── relações acompanhadas ───────────────────────────────
    db_create($pdo, 'relations', "
        contact_id      INT UNSIGNED NOT NULL PRIMARY KEY,
        formality       VARCHAR(12)  NOT NULL DEFAULT 'cordial',
        potential       TINYINT      NOT NULL DEFAULT 3,
        business        VARCHAR(12)  NOT NULL DEFAULT 'medio',
        level           CHAR(1)      NOT NULL DEFAULT 'B',
        owner_id        INT UNSIGNED NULL,
        cadence_days    SMALLINT     NULL,
        next_at         DATE         NULL,
        last_touch_at   DATETIME     NULL,
        last_touch_prev DATETIME     NULL,
        touches         INT          NOT NULL DEFAULT 0,
        paused_until    DATE         NULL,
        reviewed_at     DATE         NULL,
        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_rel_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
        INDEX idx_next (next_at)");

    // ── cadências por nível (Definições, só admins) ─────────
    $set = $lite
        ? $pdo->prepare('INSERT OR IGNORE INTO settings (skey, svalue) VALUES (?, ?)')
        : $pdo->prepare('INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)');
    foreach (REL_CADENCE_DEFAULTS as $lvl => $days) $set->execute(['rel_cadence_' . strtolower($lvl), (string)$days]);

    contacts_v2_convert($pdo);
}

/** Os serviços da lista que um texto livre antigo refere («Animação 3D e 2D, VFX…»). */
function contacts_v2_guess_services(string $text): array {
    $t = mb_strtolower($text);
    $out = [];
    if (preg_match('/anima|animation/u', $t)) {
        if (preg_match('/\b3d\b/', $t)) $out[] = '3D Animation';
        if (preg_match('/\b2d\b/', $t)) $out[] = '2D Animation';
    }
    if (str_contains($t, 'vfx')) $out[] = 'VFX';
    if (str_contains($t, 'cgi')) $out[] = 'CGI';
    if (preg_match('/edi[çc][ãa]o|editing|montagem/u', $t)) $out[] = 'Edição';
    if (str_contains($t, 'compositing') || str_contains($t, 'composição')) $out[] = 'Compositing';
    return array_values(array_unique($out));
}

/**
 * Os contactos atuais passam ao modelo novo (uma vez): cada um é Empresa; com «Pessoa de
 * contacto», cria-se a Pessoa ligada — e quando a pessoa de contacto é o próprio contacto
 * (o mesmo nome), é uma pessoa: freelancer, sem empresa. O texto livre da «Tarifa» vai para
 * as notas das linhas de serviço (por rever); a avaliação antiga entra como a primeira.
 */
function contacts_v2_convert(PDO $pdo): void {
    $st = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
    $st->execute(['contacts_v2_converted']);
    if ($st->fetchColumn()) return;
    $services = $pdo->query('SELECT name, id FROM services')->fetchAll(PDO::FETCH_KEY_PAIR);
    $today = date('Y-m-d');
    $pdo->beginTransaction();
    try {
        $insP = $pdo->prepare("INSERT INTO contacts (kind, name, company_id, email, phone, is_client, is_supplier, is_partner, is_freelancer, is_active, created_by, notes)
                               VALUES ('person', ?, ?, ?, ?, ?, ?, ?, 0, 1, ?, '')");
        $insL = $pdo->prepare('INSERT INTO contact_services (contact_id, service_id, label, unit, price, vat, notes, valid_from) VALUES (?,?,?,NULL,NULL,1,?,?)');
        $insR = $pdo->prepare("INSERT INTO contact_ratings (contact_id, cost_id, deal_id, stars, note) VALUES (?, NULL, NULL, ?, 'Avaliação anterior à v2')");
        // No histórico como «Sistema» (a migração pode correr no pedido de alguém com sessão).
        $log = $pdo->prepare("INSERT INTO contact_history (contact_id, event, note, user_id) VALUES (?, 'c_create', ?, NULL)");
        foreach ($pdo->query('SELECT * FROM contacts ORDER BY id')->fetchAll() as $c) {
            $id = (int)$c['id'];
            // Site / redes → redes sociais (um link por linha).
            if (trim((string)$c['website']) !== '' && trim((string)$c['links']) === '') {
                $links = array_values(array_filter(array_map('trim', preg_split('/\s*[|\n,]\s*/', (string)$c['website'])), 'strlen'));
                $pdo->prepare('UPDATE contacts SET links = ? WHERE id = ?')->execute([implode("\n", $links), $id]);
            }
            $cp = trim((string)$c['contact_person']);
            if ($c['kind'] === 'company' && $cp !== '') {
                if (mb_strtolower($cp) === mb_strtolower(trim((string)$c['name']))) {
                    // É uma pessoa (freelancer quando presta serviços).
                    $prov = (int)$c['is_supplier'] === 1 || (int)$c['is_freelancer'] === 1;
                    $pdo->prepare("UPDATE contacts SET kind = 'person', is_freelancer = ?, is_supplier = ? WHERE id = ?")
                        ->execute([$prov ? 1 : 0, 0, $id]);
                    $c['kind'] = 'person'; $c['is_freelancer'] = $prov ? 1 : 0; $c['is_supplier'] = 0;
                    $log->execute([$id, 'Passou a pessoa' . ($prov ? ' (freelancer)' : '') . ' na migração para os Contactos v2']);
                } else {
                    $ex = $pdo->prepare("SELECT id FROM contacts WHERE kind = 'person' AND company_id = ? AND LOWER(TRIM(name)) = ?");
                    $ex->execute([$id, mb_strtolower($cp)]);
                    if (!$ex->fetchColumn()) {
                        $insP->execute([mb_substr($cp, 0, 160), $id, (string)$c['email'], (string)$c['phone'],
                                        (int)$c['is_client'], (int)$c['is_supplier'], (int)$c['is_partner'], $c['created_by']]);
                        $log->execute([(int)$pdo->lastInsertId(), 'Pessoa de contacto de «' . $c['name'] . '» (migração)']);
                    }
                }
            }
            // Serviços e preços dos fornecedores e freelancers: a tarifa antiga fica nas notas, por rever.
            if (((int)$c['is_supplier'] === 1 || (int)$c['is_freelancer'] === 1) && (trim((string)$c['rate']) !== '' || trim((string)$c['service']) !== '')) {
                $note = trim((string)$c['rate']) !== '' ? 'Tarifa por rever: ' . trim((string)$c['rate']) : 'Preço por rever (migração).';
                $found = contacts_v2_guess_services($c['service'] . ' ' . $c['rate'] . ' ' . $c['category']);
                if ($found) foreach ($found as $s) $insL->execute([$id, $services[$s] ?? null, '', $note, $today]);
                else $insL->execute([$id, null, mb_substr(trim((string)$c['service']) ?: (string)$c['category'] ?: 'Serviço', 0, 160), $note, $today]);
            }
            if ($c['rating'] !== null && (int)$c['rating'] >= 1) $insR->execute([$id, (int)$c['rating']]);
        }
        $pdo->prepare(db_is_sqlite($pdo) ? 'INSERT OR REPLACE INTO settings (skey, svalue) VALUES (?, ?)' : 'INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
            ->execute(['contacts_v2_converted', date('c')]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
