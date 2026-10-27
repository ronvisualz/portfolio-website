<?php
/**
 * O login rápido e o calendário (Entrar com Google, Google Calendar de cada pessoa). Normalmente é a própria
 * plataforma que escreve este ficheiro (Definições › Integrações › Google). À mão: copie para auth_config.php NO
 * SERVIDOR e preencha. auth_config.php não vai para o Git; as credenciais nunca vão para o browser.
 *
 * google   console.cloud.google.com › APIs e serviços › Credenciais › ID de cliente OAuth (aplicação Web), com o
 *          URI de redirecionamento https://topperhub.xyz/admin/google_auth.php e a Google Calendar API ativa.
 *          Scopes do ecrã de consentimento: openid, email, calendar.events, calendar.freebusy.
 *          Sem este ficheiro, usa-se o cliente de inc/meet_config.php (o mesmo cliente serve para tudo).
 */
return [
    'google' => [
        'client_id'     => 'COLE_AQUI',
        'client_secret' => 'COLE_AQUI',
    ],
];
