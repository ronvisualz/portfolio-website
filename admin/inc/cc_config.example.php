<?php
/**
 * Centro Criativo — IA de qualidade máxima (serviço premium).
 * Copie para cc_config.php NO SERVIDOR e preencha. cc_config.php não vai para o Git.
 * As chaves ficam só no servidor: nunca vão para o browser.
 *
 * anthropic_key     console.anthropic.com › Settings › API Keys. Vazio = usa a chave do ai_config.php.
 * model             o modelo Claude mais capaz (análise, ângulos, páginas, tratamentos).
 * fallback_model    usado só se a conta não tiver acesso ao modelo principal.
 * effort            low · medium · high · xhigh · max (qualidade × tempo × custo).
 * voyage_key        dash.voyageai.com › API Keys: embeddings multimodais (imagem + texto) para
 *                   «Mais como este» e «Referências para o brief». Sem chave, a semelhança usa
 *                   etiquetas, facetas e paleta.
 * embed_model       o modelo de embeddings multimodais.
 * embed_usd_mtok    preço do modelo de embeddings (USD por milhão de tokens), para o gasto.
 * monthly_cap_eur   limite mensal da IA do Centro Criativo (conta à parte do assistente do Drive).
 * auto_analyze      analisar sozinho cada referência nova (true) ou só quando se pede (false).
 * transcribe_*      transcrição dos vídeos (por ligar: escolher o serviço e pôr a chave).
 */
return [
    'anthropic_key'   => '',
    'model'           => 'claude-fable-5-1',
    'fallback_model'  => 'claude-opus-5-5',
    'effort'          => 'high',
    'voyage_key'      => 'COLE_AQUI_A_CHAVE_DA_VOYAGE',
    'embed_model'     => 'voyage-multimodal-3',
    'embed_usd_mtok'  => 0.12,
    'monthly_cap_eur' => 60,
    'auto_analyze'    => true,
    // 'transcribe_provider' => '',
    // 'transcribe_key'      => '',
];
