<?php
/**
 * Ligação à folha Google (Apps Script). Copie para sheet_config.php no
 * servidor e preencha. sheet_config.php nunca vai para o git.
 *
 *   url       Implementar > Gerir implementações > URL da aplicação web (termina em /exec)
 *   token     o mesmo valor que TOKEN no Code.gs (uma palavra-passe longa e aleatória)
 *   sheet_url o endereço da folha, para o link «Abrir folha»
 */
return [
    'url'       => 'https://script.google.com/macros/s/…/exec',
    'token'     => '…',
    'sheet_url' => 'https://docs.google.com/spreadsheets/d/1ANl0YVMQ4gDziF9BuMp3Btrd_1QCzW_B1gPNDAWo2mY/edit',
];
