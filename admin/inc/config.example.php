<?php
/**
 * Database credentials. Fill these in from hPanel → Databases → MySQL.
 *
 * This file sits in inc/, which .htaccess blocks from being served over
 * the web — so even if PHP ever stopped executing, these values would not
 * be downloadable.
 */

// Base de dados local de testes (só quando o site corre em localhost).
// Estes dados são descartáveis: nunca tocam no servidor real.
$db = (defined('IS_LOCAL') && IS_LOCAL)
    ? [
        'db_host' => 'CHANGE_ME',
        'db_name' => 'CHANGE_ME',
        'db_user' => 'CHANGE_ME',
        'db_pass' => 'CHANGE_ME',
      ]
    : [
        'db_host' => 'CHANGE_ME',
        'db_name' => 'CHANGE_ME',
        'db_user' => 'CHANGE_ME',
        'db_pass' => 'CHANGE_ME',
      ];

return $db + [
    // Minutes of inactivity before the session is dropped.
    'idle_timeout_minutes' => 60,

    // Hard ceiling on a session regardless of activity.
    'absolute_timeout_hours' => 12,

    // Failed logins allowed per email (and per IP) within the window.
    'max_attempts' => 6,
    'attempt_window_minutes' => 15,
];
