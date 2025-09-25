<?php
// server_log.php

// Esperamos 'path' que contiene la ruta de disco ABSOLUTA.
if (isset($_GET['path'])) {
    // La ruta completa viene por la URL, decodificamos por si tiene espacios/barras.
    $logPath = urldecode($_GET['path']); 

    // Verifica si el archivo existe usando la ruta ABSOLUTA del sistema de archivos (D:\xampp\.../storage/logs/...)
    if (file_exists($logPath)) {
        // Lee el archivo de log
        $logContent = file_get_contents($logPath);

        // Establece los encabezados correctos para mostrarlo como texto plano
        header('Content-Type: text/plain');
        echo $logContent;
    } else {
        // Log no encontrado. Podría ser un error de permisos o la ruta está mal
        echo "Error: Log no encontrado. Verifique la ruta del archivo y los permisos de lectura del servidor Apache. Ruta enviada: " . htmlspecialchars($logPath);
    }
} else {
    echo "Error: No se proporcionó una ruta de archivo ('path' no definido).";
}
?>