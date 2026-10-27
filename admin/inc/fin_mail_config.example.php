<?php
/**
 * As caixas de email do Financeiro (leitura das faturas e envio dos pedidos ao contabilista).
 * Copie para fin_mail_config.php NO SERVIDOR e preencha. fin_mail_config.php não vai para o Git.
 *
 * boxes        as caixas existentes do Hostinger (Hostinger › Emails), cada uma com a sua palavra-passe.
 *              Não é preciso criar uma caixa nova. Quais são lidas escolhe-se em Definições › Integrações ›
 *              Email; a primeira dessa lista envia os pedidos ao contabilista. As palavras-passe ficam só
 *              aqui, no servidor: nunca no código, na base de dados nem no chat.
 * imap_*       leitura a cada 5 minutos pelo cron — Hostinger: imap.hostinger.com, porta 993, SSL.
 *              Só leitura: nada é marcado como lido, movido ou apagado.
 * smtp_*       envio — Hostinger: smtp.hostinger.com, porta 465, SSL.
 * from_name    o nome que aparece como remetente.
 *
 * Desde a §31.1 (por defeito) nada disto precisa de palavras-passe: os emails chegam pela Cloudflare e saem
 * por um serviço de envio. A própria plataforma escreve aqui, a partir de Definições › Integrações › Email:
 * api             o serviço de envio: ['provider' => 'resend' | 'brevo', 'key' => 'a chave API']
 * inbound_secret  o segredo partilhado com o Worker do email (o mesmo vai como secret INBOUND_SECRET)
 * Com isto, «boxes» só é preciso no modo IMAP ou no envio por SMTP.
 */
return [
    'boxes' => [
        'info@topperdigital.xyz' => 'COLE_AQUI_A_PALAVRA_PASSE',
        // 'outra@topperdigital.xyz' => 'COLE_AQUI_A_PALAVRA_PASSE',
    ],
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => 465,
    'smtp_tls'  => 'ssl',
    'imap_host' => 'imap.hostinger.com',
    'imap_port' => 993,
    'imap_tls'  => 'ssl',
    'from_name' => 'topper. Financeiro',
    // 'api' => ['provider' => 'resend', 'key' => 'COLE_AQUI'],
    // 'inbound_secret' => 'gerado em Definições › Integrações › Email',
];
