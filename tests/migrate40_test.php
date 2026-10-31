<?php
// Migração 40 (§32): colunas novas das partilhas e as tabelas de movimentos, versões e registo das partilhas.
require __DIR__ . '/boot.php';
require_once __DIR__ . '/../admin/inc/migrate_files.php';

migrate_drive_40($pdo);
migrate_drive_40($pdo);   // idempotente

foreach (['perm', 'created_by', 'inc_private'] as $c) t_true(db_has_column($pdo, 'drive_shares', $c), "drive_shares.$c existe");
foreach (['drive_moves', 'drive_versions', 'drive_share_log'] as $t) t_true(db_has_table($pdo, $t), "tabela $t existe");
t_eq((string)$pdo->query("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drive_shares' AND COLUMN_NAME = 'perm'")->fetchColumn(), "'view'", 'perm por defeito é view');
t_done();
