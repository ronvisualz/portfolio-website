<?php
/**
 * Cloudflare R2 (o separador «Drive»).
 * Copie para r2_config.php NO SERVIDOR e preencha. r2_config.php não vai para o Git.
 *
 * account_id         Cloudflare › R2 Object Storage › «Account ID» (32 caracteres hexadecimais)
 * access_key_id      R2 › Manage API tokens › Create API token (Object Read & Write, só este bucket)
 * secret_access_key  idem (só aparece uma vez)
 * bucket             o nome do bucket (ex.: topper-drive)
 *
 * Política CORS do bucket (Settings › CORS Policy):
 * [{"AllowedOrigins":["https://topperhub.xyz","https://topperdigital.xyz"],"AllowedMethods":["GET","PUT","POST","DELETE","HEAD"],
 *   "AllowedHeaders":["*"],"ExposeHeaders":["ETag","Content-Range"],"MaxAgeSeconds":3600}]
 */
return [
    'account_id'        => 'COLE_AQUI_O_ACCOUNT_ID',
    'access_key_id'     => 'COLE_AQUI_A_ACCESS_KEY_ID',
    'secret_access_key' => 'COLE_AQUI_A_SECRET_ACCESS_KEY',
    'bucket'            => 'topper-drive',
    // 'endpoint' => 'https://<account_id>.r2.cloudflarestorage.com',  // opcional; só para testes
];
