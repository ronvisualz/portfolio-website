<?php
/**
 * topper. admin — passo 17 da base de dados: ficha central do negócio (§15)
 *
 * A etapa de produção enquanto o funil não tiver etapas de produção próprias
 * (Pré-produção · Produção · Pós-produção · Entrega), e o que a checklist de
 * fecho regista: entregas feitas, enviado para o portfólio, testemunho pedido.
 * Tudo o resto a ficha lê das outras abas pelo ID. Como os outros passos,
 * verifica antes de mudar e pode correr as vezes que forem.
 */

if (!defined('TOPPER_ADMIN')) { http_response_code(403); exit; }

require_once __DIR__ . '/migrate_platform.php';   // db_add_column

function migrate_deal_file(PDO $pdo): void {
    db_add_column($pdo, 'deals', 'prod_step',      'VARCHAR(12) NULL', 'TEXT NULL');
    db_add_column($pdo, 'deals', 'delivered_at',   'DATETIME NULL',    'TEXT NULL');
    db_add_column($pdo, 'deals', 'portfolio_at',   'DATETIME NULL',    'TEXT NULL');
    db_add_column($pdo, 'deals', 'testimonial_at', 'DATETIME NULL',    'TEXT NULL');

    // Os que já estavam concluídos foram entregues nessa altura: a checklist não os
    // assinala por isso (continuam a contar custos, pagamento e avaliações).
    $pdo->exec("UPDATE deals SET delivered_at = done_at WHERE phase = 'done' AND delivered_at IS NULL AND done_at IS NOT NULL");
}
