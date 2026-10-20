<?php
/**
 * O assistente do Drive (Claude, da Anthropic).
 * Copie para ai_config.php NO SERVIDOR e preencha. ai_config.php não vai para o Git.
 *
 * api_key          console.anthropic.com › Settings › API Keys › Create Key (começa por «sk-ant-»).
 *                  Em Billing, carregue algum crédito; o assistente nunca gasta mais do que o limite abaixo.
 * monthly_cap_eur  limite mensal (em €; o gasto é uma estimativa e conta-se como se 1 USD fosse 1 €,
 *                  por isso fica sempre abaixo disto). Chegando lá, o assistente para até ao dia 1.
 * model            opcional; por omissão claude-haiku-5-5 (o mais barato)
 *
 * A chave fica só no servidor: nunca vai para o browser nem para o Git.
 */
return [
    'api_key'         => 'COLE_AQUI_A_CHAVE_DA_API',
    'monthly_cap_eur' => 10,
    // 'model'        => 'claude-haiku-5-5',
    // 'base_url'     => 'https://api.anthropic.com',   // só para testes
];
