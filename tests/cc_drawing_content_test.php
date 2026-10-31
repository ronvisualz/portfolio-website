<?php
// Desenhos (§33.3): o servidor guarda a caixa original (vw, vh) e a opção de espessura fixa.   php tests/cc_drawing_content_test.php
require __DIR__ . '/boot.php';
require_once __DIR__ . '/../admin/inc/cc.php';
$c = cc_card_content('drawing', ['paths' => [['c' => '#111111', 'w' => 4, 'pts' => [0, 0, 10, 10]]], 'vw' => 320, 'vh' => 200.5, 'fx' => 1]);
t_eq([$c['vw'] ?? null, $c['vh'] ?? null], [320.0, 200.5], 'vw e vh ficam');
t_eq($c['fx'] ?? null, true, 'espessura fixa fica');
$c = cc_card_content('drawing', ['paths' => [], 'vw' => -5, 'vh' => 99999]);
t_eq([$c['vw'] ?? null, $c['vh'] ?? null, $c['fx'] ?? null], [0.0, 6000.0, false], 'valores fora do intervalo são corrigidos (0 = sem caixa)');
t_done();
