<?php
/**
 * A caixa das faturas do Financeiro (pedidos ao contabilista, leitura das faturas e recibos).
 * Copie para fin_mail_config.php NO SERVIDOR e preencha. fin_mail_config.php não vai para o Git.
 *
 * user / pass   o endereço completo da caixa (criada no Hostinger › Emails, ex.: faturas@topperdigital.xyz)
 *               e a palavra-passe dela. Ficam só aqui, no servidor: nunca no código, na base de dados nem no chat.
 * smtp_*        envio — Hostinger: smtp.hostinger.com, porta 465, SSL.
 * imap_*        leitura a cada 5 minutos pelo cron — Hostinger: imap.hostinger.com, porta 993, SSL.
 *               As mensagens tratadas passam para a pasta «Processadas»; nada é apagado.
 * from_name     o nome que aparece como remetente.
 */
return [
    'user'      => 'faturas@topperdigital.xyz',
    'pass'      => 'COLE_AQUI_A_PALAVRA_PASSE',
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => 465,
    'smtp_tls'  => 'ssl',
    'imap_host' => 'imap.hostinger.com',
    'imap_port' => 993,
    'imap_tls'  => 'ssl',
    'from_name' => 'topper. Financeiro',
];
