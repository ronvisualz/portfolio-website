<?php
/** topper. admin — endereço antigo do Toppernote (ex-Centro Criativo, §33.0): redireciona para toppernote.php com a mesma pergunta. */
$q = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: /admin/toppernote.php' . ($q !== '' ? '?' . $q : ''), true, 301);
exit;
