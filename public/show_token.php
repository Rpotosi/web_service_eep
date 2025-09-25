<?php
session_start();
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/SiredClient.php';

$client = new SiredClient($config);
$meta   = $client->getTokenMeta(false); // false = sin máscara

$len = strlen($meta['access_token'] ?? '');
$obt = !empty($meta['obtained_at']) ? date('c', (int)$meta['obtained_at']) : 'N/D';
$exp = !empty($meta['expires_at'])  ? date('c', (int)$meta['expires_at']) : 'N/D';

header('Content-Type: text/plain; charset=utf-8');
echo "TOKEN_TYPE: ".($meta['token_type'] ?? 'Bearer')."\n";
echo "LENGTH    : $len\n";
echo "OBTAINED  : $obt\n";
echo "EXPIRES   : $exp\n\n";
echo $meta['access_token'] ?? 'NO_TOKEN';
