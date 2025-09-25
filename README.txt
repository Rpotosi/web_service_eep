PHP + XAMPP: SIRED Uploader
=============================

Estructura
  /public/index.php    -> Formulario web para el equipo (sube ZIP, consulta estado y descarga log)
  /lib/SiredClient.php -> Cliente SIRED (token, upload, consulta, log)
  /config.php          -> Configuración
  /storage/            -> Bitácoras y logs
  /cli/send_daily.php  -> Script CLI (para Programador de Tareas)

Requisitos
  - XAMPP (PHP 8.x)
  - Extensión cURL habilitada (php.ini -> extension=curl)
  - Permisos de escritura en /storage

Instalación rápida (Windows)
  1) Copia la carpeta 'php_sired' en: C:\xampp\htdocs\
  2) Edita C:\xampp\htdocs\php_sired\config.php y coloca:
     - client_id, client_secret, subscription_key
  3) Abre: http://localhost/php_sired/public/

Variables de entorno (opcional)
  setx SIRED_CLIENT_ID "xxx"
  setx SIRED_CLIENT_SECRET "yyy"
  setx SIRED_SUBSCRIPTION_KEY "zzz"

Uso CLI
  php C:\xampp\htdocs\php_sired\cli\send_daily.php 2025-09-21 Diario EEPD PEIM C:\ruta\reporte.zip noti@empresa.com --wait --timeout=300 --poll=5

Programador de Tareas (idea)
  Acción: C:\xampp\php\php.exe
  Argumentos: C:\xampp\htdocs\php_sired\cli\send_daily.php {YYYY-MM-DD} Diario EEPD PEIM C:\carpeta\reporte_{YYYYMMDD}.zip --wait --timeout=300
  Carpeta Inicio: C:\xampp\htdocs\php_sired\cli
