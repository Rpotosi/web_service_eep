<?php
// Uso (CLI):
//   php send_daily.php 2025-09-21 Diario EEPD PEIM C:\ruta\reporte.zip notificaciones@empresa.com --wait --timeout=300 --poll=5
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/SiredClient.php';

$args = $argv;
array_shift($args);

if (count($args) < 5) {
    fwrite(STDERR, "Uso: php send_daily.php <fecha> <tipo> <agente> <mercado> <zip> [correo] [--wait] [--timeout=300] [--poll=5]\n");
    exit(1);
}

[$fecha, $tipo, $agente, $mercado, $zip] = array_slice($args, 0, 5);
$correo = $args[5] ?? null;

$wait = in_array('--wait', $args, true);
$timeout = 300;
$poll = 5;
foreach ($args as $a) {
    if (preg_match('/^--timeout=(\d+)/', $a, $m)) $timeout = (int)$m[1];
    if (preg_match('/^--poll=(\d+)/', $a, $m)) $poll = (int)$m[1];
}

try {
    $client = new SiredClient($config);
    $token  = $client->getToken();
    $up     = $client->uploadZip($token, $zip, $fecha, $tipo, $agente, $mercado, $correo);
    $solId  = $up['SolicitudId'] ?? ($up['Id'] ?? null);

    $status = null;
    if ($wait && $solId) {
        $deadline = time() + $timeout;
        do {
            sleep($poll);
            $status = $client->getSolicitud($token, $solId);
            $estado = $status['Estado'] ?? '';
            fwrite(STDOUT, "Estado: {$estado}\n");
            if (in_array($estado, ['Exitosa','Fallido','Reemplazado'], true)) break;
        } while (time() < $deadline);
        $client->downloadLog($token, $solId);
    } elseif ($solId) {
        $client->downloadLog($token, $solId);
    }

    $run = [
        'FechaEjecucion' => date('c'),
        'Parametros' => compact('fecha','tipo','agente','mercado','correo','zip'),
        'UploadRespuesta' => $up,
        'SolicitudId' => $solId,
        'ConsultaEstado' => $status
    ];
    $file = $client->saveRun($run);
    echo "OK. Bitácora: $file\n";

} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: ".$e->getMessage()."\n");
    exit(2);
}
