<?php
/**
 * As integrações das reuniões da aba Equipa (§17.7). Copie para meet_config.php NO SERVIDOR
 * e preencha. meet_config.php não vai para o Git; as credenciais nunca vão para o browser.
 * Enquanto uma integração não estiver preenchida, quem aceita a reunião cola o link à mão.
 *
 * google   Google Meet pela API do Google Calendar (OAuth de uma conta da topper.):
 *          console.cloud.google.com › APIs › Google Calendar API (ativar) › Credenciais ›
 *          ID de cliente OAuth (aplicação Web). O refresh_token obtém-se uma vez com o scope
 *          https://www.googleapis.com/auth/calendar.events (ex.: OAuth 2.0 Playground).
 *          A reunião fica no calendário calendar_id dessa conta, com os dois como convidados.
 *          Desde a §31.4 é a conta usada para criar o Meet quando quem marca não tem o seu Google Calendar ligado
 *          (Perfil › Ligações). O cliente (client_id/secret) pode ser o mesmo de auth_config.php.
 * discord  O link de convite do servidor/canal de voz da topper. (https://discord.gg/…).
 */
return [
    'google' => [
        'client_id'     => 'COLE_AQUI',
        'client_secret' => 'COLE_AQUI',
        'refresh_token' => 'COLE_AQUI',
        'calendar_id'   => 'primary',
    ],
    'discord' => [
        'invite_url' => '',
    ],
];
