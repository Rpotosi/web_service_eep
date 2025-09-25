<?php
// serve_log.php
$config = require __DIR__ . '/../config.php';

$baseDir = rtrim($config['storage_dir'], '/\\'); // p. ej. /var/www/app/storage
$logsDir = $baseDir; // o "$baseDir/logs" si guardas allí

$name = isset($_GET['name']) ? $_GET['name'] : '';
if (!preg_match('/^[A-Za-z0-9._-]+$/', $name)) {
  http_response_code(400); exit('Nombre inválido');
}

$file = $logsDir . '/' . $name;
if (!is_file($file)) {
  http_response_code(404); exit('Archivo no encontrado');
}

// Mostrar como texto plano (inline) cuando raw=1
if (isset($_GET['raw'])) {
  header('Content-Type: text/plain; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');
  readfile($file);
  exit;
}

// (Opcional) mantener descarga si algún día vuelves a habilitarla:
// if (!empty($_GET['download'])) {
//   header('Content-Type: application/octet-stream');
//   header('Content-Length: ' . filesize($file));
//   header('Content-Disposition: attachment; filename="'.basename($file).'"');
//   readfile($file);
//   exit;
// }

// Por defecto, también inline
header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
readfile($file);
