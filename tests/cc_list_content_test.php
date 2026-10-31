<?php
// Listas (§33.1): o servidor guarda o nível dos sub-itens (0–2) e aceita itens vazios a meio.   php tests/cc_list_content_test.php
require __DIR__ . '/boot.php';
require_once __DIR__ . '/../admin/inc/cc.php';
$c = cc_card_content('list', ['title' => 'L', 'items' => [['t' => 'a', 'l' => 1], ['t' => '', 'l' => 0], ['t' => 'b', 'l' => 7, 'd' => 1], ['t' => 'c', 'l' => -3]]]);
t_eq(array_column($c['items'], 'l'), [1, 0, 2, 0], 'o nível fica entre 0 e 2');
t_eq(array_column($c['items'], 't'), ['a', '', 'b', 'c'], 'itens vazios a meio ficam');
t_eq($c['items'][2]['d'], true, 'feito mantém-se');
t_done();
