<?php

require __DIR__ . '/../vendor/autoload.php';


$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');


// ───────────────────────────────────────────────────────────
// index.php — PRG + CSRF + fixes
// ───────────────────────────────────────────────────────────
session_start();

$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
  die('Falta config.php');
}
$config = require $configPath;
require __DIR__ . '/../lib/SiredClient.php';

// CSRF: genera si no existe
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
}

// util para redirigir a esta misma página (PRG)
function self_redirect() {
  $uri = $_SERVER['REQUEST_URI'];
  header("Location: " . $uri);
  exit;
}

$errors = [];
$result = null;

// ── GET para reconsultar estado y bajar log cuando el timeout fue corto ──
if (isset($_GET['check']) && $_GET['check'] !== '') {
  try {
    $solId = preg_replace('/[^a-f0-9-]/i','', $_GET['check']); // sanitiza
    if ($solId) {
      $client = new SiredClient($config);
      $token  = $client->getToken();
      $status = $client->getSolicitud($token, $solId);
      $log    = $client->downloadLog($token, $solId); // null si aún no está

      $_SESSION['result'] = [
        'ok'          => true,
        'upload'      => ['SolicitudId' => $solId],
        'solicitudId' => $solId,
        'status'      => $status,
        'logPath'     => $log ?: null,
        'runFile'     => null,
      ];
    }
  } catch (Throwable $e) {
    $_SESSION['errors'] = [$e->getMessage()];
  }
  self_redirect();
}

// ───────────────────────────────────────────────────────────
// POST (procesa subida y luego REDIRIGE SIEMPRE)
// ───────────────────────────────────────────────────────────
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // CSRF check
    $csrfPost = isset($_POST['csrf']) ? $_POST['csrf'] : '';
    $csrfSess = isset($_SESSION['csrf']) ? $_SESSION['csrf'] : '';
    if (!function_exists('hash_equals')) {
      // compat PHP<5.6
      function hash_equals($a,$b){ if(strlen($a)!==strlen($b))return false; $r=0; for($i=0;$i<strlen($a);$i++){$r|=ord($a[$i])^ord($b[$i]);} return $r===0; }
    }
    if (!hash_equals($csrfSess, $csrfPost)) {
      $_SESSION['errors'] = ["CSRF inválido. Refresca la página e inténtalo de nuevo."];
      self_redirect();
    }

    // Inputs (sin ?? para compat)
    $fecha   = isset($_POST['fecha'])   ? trim($_POST['fecha'])   : '';
    $tipo    = isset($_POST['tipo'])    ? trim($_POST['tipo'])    : '';
    $agente  = isset($_POST['agente'])  ? trim($_POST['agente'])  : '';
    $mercado = isset($_POST['mercado']) ? trim($_POST['mercado']) : '';
    $correo  = isset($_POST['correo'])  ? trim($_POST['correo'])  : '';

    // Esperar resultado: solo true si vale "1"
    $esperar = (isset($_POST['esperar']) && $_POST['esperar'] === '1');

    $timeout = max(30, (int)(isset($_POST['timeout']) ? $_POST['timeout'] : 300));
    $pollSec = max(3,  (int)(isset($_POST['poll'])    ? $_POST['poll']    : 5));

    // Validaciones mínimas
    if ($fecha === '' || $tipo === '' || $agente === '' || $mercado === '') {
      $errors[] = "Campos obligatorios: Fecha, Tipo, Agente, Mercado.";
    }
    if (!isset($_FILES['zip']) || !isset($_FILES['zip']['error']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
      $errors[] = "Debe adjuntar el archivo .zip.";
    }

    if (!empty($errors)) {
      $_SESSION['errors'] = $errors;
      self_redirect();
    }

    // Guardar ZIP en storage
if (!isset($_FILES['zip']['tmp_name']) || !is_uploaded_file($_FILES['zip']['tmp_name'])) {
  throw new RuntimeException('No se recibió un archivo ZIP válido.');
}

$zipTmp  = $_FILES['zip']['tmp_name'];
$zipName = isset($_FILES['zip']['name']) ? basename($_FILES['zip']['name']) : 'archivo.zip';

// Sanitiza nombre (permite letras, números, _, ., -)
$safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $zipName);
if ($safe === '' || $safe === null) {
  $safe = 'archivo.zip';
}

$storageDir = rtrim($config['storage_dir'], '/\\');
if (!is_dir($storageDir)) {
  if (!@mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
    throw new RuntimeException('No se pudo crear el directorio de storage: ' . $storageDir);
  }
}

// Ruta final con timestamp para evitar choques
$zipPath = $storageDir . '/' . date('Ymd_His') . '_' . $safe;

// Mueve el archivo
if (!@move_uploaded_file($zipTmp, $zipPath)) {
  throw new RuntimeException('No se pudo mover el ZIP a storage.');
}

    // Flujo SIRED
    $client  = new SiredClient($config);
    $token   = $client->getToken();
    $upload  = $client->uploadZip($token, $zipPath, $fecha, $tipo, $agente, $mercado, $correo);

    // === EXTRAER SolicitudId cuando viene embebido en "respuesta" (texto) ===
      $solId = null;
      if (is_array($upload)) {
          if (isset($upload['SolicitudId'])) {
              $solId = $upload['SolicitudId'];
          } elseif (isset($upload['Id'])) {
              $solId = $upload['Id'];
          } elseif (isset($upload['respuesta']) && is_string($upload['respuesta'])) {
              if (preg_match('/[0-9a-fA-F-]{36}/', $upload['respuesta'], $m)) {
                  $solId = $m[0];
              }
          }
      }

    $status  = null;
    $logPath = null;

    if ($esperar && $solId) {
      $deadline = time() + $timeout;
      do {
        sleep($pollSec);
        $status = $client->getSolicitud($token, (string)$solId);
        $estado = is_array($status) && isset($status['Estado']) ? $status['Estado'] : '';
        if (in_array($estado, array('Exitosa','Fallido','Reemplazado'), true)) {
          break;
        }
      } while (time() < $deadline);
      $logPath = $client->downloadLog($token, (string)$solId);
    } elseif ($solId) {
      $logPath = $client->downloadLog($token, (string)$solId);
    }

    // Bitácora
    $run = array(
      'FechaEjecucion'  => date('c'),
      'Parametros'      => array(
        'FechaDeCarga' => $fecha,
        'TipoCarga'    => $tipo,
        'Agente'       => $agente,
        'Mercado'      => $mercado,
        'Correo'       => $correo,
        'Zip'          => $zipPath
      ),
      'UploadRespuesta' => $upload,
      'SolicitudId'     => $solId,
      'ConsultaEstado'  => $status,
      'LogPath'         => $logPath
    );
    $runFile = $client->saveRun($run);

    // Resultado final → sesión → redirect (PRG)
    $_SESSION['result'] = array(
      'ok'          => true,
      'upload'      => $upload,
      'solicitudId' => $solId,
      'status'      => $status,
      'logPath'     => $logPath,
      'runFile'     => $runFile
    );

    self_redirect();

  } catch (Throwable $e) {
    $_SESSION['errors'] = array($e->getMessage());
    self_redirect();
  }
}

// ── GET ?check=<SolicitudId>  → consulta estado y baja el log si ya existe
if (isset($_GET['check']) && $_GET['check'] !== '') {
    try {
        $solId  = (string)$_GET['check'];
        $client = new SiredClient($config);

        // 1) token
        $token  = $client->getToken();

        // 2) consultar estado
        $status = $client->getSolicitud($token, $solId);

        // 3) intentar descargar log (si el endpoint ya lo tiene listo)
        $logPath = $client->downloadLog($token, $solId); // string|NULL

        // 4) guardar en sesión y redirigir (PRG)
        $_SESSION['result'] = [
            'ok'          => true,
            'upload'      => ['SolicitudId' => $solId],
            'solicitudId' => $solId,
            'status'      => $status,
            'logPath'     => $logPath,
            'runFile'     => null,
        ];
    } catch (Throwable $e) {
        $_SESSION['errors'] = [$e->getMessage()];
    }
    self_redirect();
}


// ───────────────────────────────────────────────────────────
// GET (después del redirect): mostrar y limpiar sesión
// ───────────────────────────────────────────────────────────
if (!empty($_SESSION['result'])) {
  $result = $_SESSION['result'];
  unset($_SESSION['result']);
}
if (!empty($_SESSION['errors'])) {
  $errors = $_SESSION['errors'];
  unset($_SESSION['errors']);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>SIRED Uploader (EEP) · Panel</title>
  <style>
    :root{
      color-scheme: light dark;
      --bg: #0b1020;
      --card: #11182b;
      --muted: #9aa3b2;
      --text: #e9edf3;
      --accent: #4f8cff;
      --accent-2: #7dd3fc;
      --danger-bg:#2a0f13; --danger:#ff6b6b; --danger-br:#5c1d22;
      --ok-bg:#10251a; --ok:#34d399; --ok-br:#1d3b2b;
      --border:#23314d;
      --input:#16213a;
      --shadow: 0 10px 30px rgba(0,0,0,.35);
      --radius: 16px;
    }
    @media (prefers-color-scheme: light){
      :root{
        --bg:#f6f8fb; --card:#ffffff; --text:#0e1525; --muted:#5c6470;
        --border:#e7ecf3; --input:#f4f7fb; --shadow:0 10px 30px rgba(14,21,37,.08);
      }
    }
    *{box-sizing:border-box}
    body{
      margin:0; background: radial-gradient(1200px 800px at 10% -10%, rgba(79,140,255,.18), transparent 60%),
                  radial-gradient(1200px 800px at 110% 10%, rgba(125,211,252,.18), transparent 60%),
                  var(--bg);
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji","Segoe UI Emoji";
      color: var(--text);
      line-height: 1.4;
      padding: 40px 16px 56px;
    }
    .container{max-width: 980px; margin: 0 auto;}
    .header{ display:flex; align-items:center; gap:12px; margin: 0 0 18px; }
    .badge{
      display:inline-flex; align-items:center; gap:8px;
      background: linear-gradient(135deg, rgba(79,140,255,.14), rgba(125,211,252,.12));
      border:1px solid var(--border); color: var(--muted);
      padding:6px 10px; border-radius:999px; font-size:12px; letter-spacing:.2px;
    }
    .app{
      background: linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,0));
      border: 1px solid var(--border); box-shadow: var(--shadow);
      border-radius: var(--radius); overflow: clip;
    }
    .app__header{
      padding: 18px 20px; display:flex; justify-content:space-between; align-items:center;
      background: linear-gradient(180deg, rgba(79,140,255,.08), rgba(79,140,255,0));
      border-bottom: 1px solid var(--border);
    }
    .app__title{font-size:20px; font-weight:700; letter-spacing:.2px}
    .app__subtitle{color:var(--muted); font-size:13px}
    .app__body{ padding: 18px; }
    .grid{ display:grid; gap:14px; grid-template-columns: repeat(12, 1fr); }
    .col-6{ grid-column: span 6; } .col-4{ grid-column: span 4; } .col-12{ grid-column: span 12; }
    @media (max-width: 840px){ .col-6,.col-4{ grid-column: span 12; } }

    .field{ display:flex; flex-direction:column; gap:8px; }
    .label{ font-size:13px; color:var(--muted); }
    .input, .select{
      width:100%; border:1px solid var(--border); background: var(--input);
      color:var(--text); border-radius:12px; padding:12px 12px; font-size:14px;
      outline: none; transition: border-color .18s, box-shadow .18s, transform .02s;
    }
    .input:focus, .select:focus{ border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79,140,255,.18); }
    .hint{ font-size:12px; color: var(--muted); }

    .drop{ border:1.5px dashed var(--border); background: linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,0));
      border-radius:12px; padding:18px; display:grid; gap:6px; place-items:center; text-align:center;
      transition: border-color .18s, background .18s; }
    .drop.is-drag{ border-color: var(--accent); background: rgba(79,140,255,.07); }
    .drop__title{ font-weight:600; }
    .drop__meta{ font-size:12px; color:var(--muted); }
    .drop input[type=file]{ display:none; }
    .drop .btn-secondary{
      border:1px solid var(--border); background: var(--card); color: var(--text);
      padding:8px 12px; border-radius:10px; cursor:pointer;
    }
    .actions{ display:flex; align-items:center; gap:10px; padding: 10px 18px 18px; border-top: 1px solid var(--border); }
    .btn{
      appearance:none; border:0; cursor:pointer;
      background: linear-gradient(180deg, #9dbc2b, #9dbc2b);
      color:white; padding:12px 16px; border-radius:12px; font-weight:700; letter-spacing:.2px;
      box-shadow: 0 10px 20px rgba(79,140,255,.28), inset 0 -1px 0 rgba(0,0,0,.2);
      transition: transform .06s ease, box-shadow .2s ease;
    }
    .btn:hover{ transform: translateY(-1px); box-shadow: 0 14px 28px rgba(79,140,255,.35), inset 0 -1px 0 rgba(0,0,0,.2); }
    .btn:active{ transform: translateY(0); box-shadow: 0 10px 20px rgba(79,140,255,.28), inset 0 -1px 0 rgba(0,0,0,.25); }

    .notice{ padding:10px 12px; border-radius:12px; font-size:14px; }
    .notice--error{ background: var(--danger-bg); border:1px solid var(--danger-br); color: var(--danger); }
    .notice--ok{ background: var(--ok-bg); border:1px solid var(--ok-br); color: white }

    .json{
      background:white;
     /* background: rgba(17,24,39,.6);*/
      border: 1px solid var(--border);
      border-radius: 12px; padding: 12px; overflow:auto; white-space:pre-wrap; word-break: break-word;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-size: 12.5px;
    }
    .footer-hint{ color: var(--muted); font-size: 12.5px; margin-top: 14px; }

/** inicia los estilos para responsive**/
    .header {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 1rem;
  margin: 1rem 0;
  flex-wrap: wrap; /* permite que se acomode en columnas si no cabe */
  text-align: center;
}

.header .logo {
  max-height: 60px;
  width: auto;
  display: block;
}

.header .badge {
  background: #f0f0f0;
  color: #333;
  padding: 0.5em 1em;
  border-radius: 12px;
  font-weight: bold;
  font-size: 1rem;
  white-space: nowrap;
}

/* Responsive: en móviles, logo arriba y badge abajo */
@media (max-width: 600px) {
  .header {
    flex-direction: column;
    gap: 0.5rem;
  }
  .header .badge {
    font-size: 0.9rem;
    white-space: normal; /* permite que el texto salte de línea si es necesario */
  }
}

/** Finaliza los estilos para responsive**/

/* ===== EEP Theme overrides (logo-based) ===== */
:root{
  /* Paleta del logo */
  --eep-green: #9DBC2B;        /* anillo verde */
  --eep-green-700:#7EA122;
  --eep-green-900:#5D7C17;
  --eep-charcoal:#4E4E4E;      /* texto PUTUMAYO */
  --eep-charcoal-900:#2F2F2F;  /* fondos oscuros */
  --eep-silver:#D9D9D9;        /* esfera gris clara */
  --eep-gray-100:#F4F4F4;      /* fondo claro */
  --eep-gray-200:#ECECEC;
  --eep-gray-300:#E2E2E2;

  /* Mapea tus variables existentes al nuevo tema */
  --bg: var(--eep-gray-100);
  --card:#FFFFFF;
  --text: var(--eep-charcoal-900);
  --muted:#6B6B6B;
  --border: var(--eep-gray-300);
  --input:#FAFAFA;
  --accent: var(--eep-green);      /* antes azul */
  --accent-2: var(--eep-green-700);

  --danger-bg:#2a0f13; --danger:#ff6b6b; --danger-br:#5c1d22;
  --ok-bg:#10251a; --ok:#34d399; --ok-br:#1d3b2b;

  --shadow: 0 10px 30px rgba(0,0,0,.08);
  --radius:16px;
}

/* Modo oscuro acorde al logo (carbón + verde) */
@media (prefers-color-scheme: dark){
  :root{
    --bg: #191919;
    --card:#1F1F1F;
    --text:#EDEDED;
    --muted:#A9A9A9;
    --border:#2A2A2A;
    --input:#222;
    --shadow: 0 10px 30px rgba(0,0,0,.40);
  }
}

/* Fondo sin azules, con viñeta sutil gris/verde */
body{
  background:
    radial-gradient(1100px 700px at 0% -20%, rgba(157,188,43,.06), transparent 60%),
    linear-gradient(180deg, rgba(0,0,0,.02), rgba(0,0,0,0)),
    var(--bg);
}

/* Encabezados y chips */
.badge{
  background: linear-gradient(135deg, rgba(157,188,43,.10), rgba(157,188,43,.06));
  border:1px solid var(--border);
  color: var(--eep-charcoal-900);
}

/* Tarjeta app con guiño verde */
.app{
  background: linear-gradient(180deg, rgba(157,188,43,.03), rgba(255,255,255,0));
  border:1px solid var(--border);
}
.app__header{
  background: linear-gradient(180deg, rgba(157,188,43,.08), rgba(157,188,43,0));
  border-bottom:1px solid var(--border);
}
.app__title{ color: var(--eep-charcoal-900); }
.app__subtitle{ color: var(--muted); }

/* Inputs y focus ring verdes */
.input,.select{
  background: var(--input);
  border:1px solid var(--border);
  color: var(--text);
}
.input:focus,.select:focus{
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(157,188,43,.22);
}

/* Zona de drop con énfasis en verde */
.drop{ border-color: var(--border); }
.drop.is-drag{
  border-color: var(--accent);
  background: rgba(157,188,43,.08);
}

/* Botón primario en verde EEP (sin cambiar tu markup) */
.btn{
  background: linear-gradient(180deg, var(--eep-green), var(--eep-green));
  color: #fff;
  box-shadow:
    0 10px 20px rgba(157,188,43,.28),
    inset 0 -1px 0 rgba(0,0,0,.2);
}
.btn:hover{
  transform: translateY(-1px);
  box-shadow:
    0 14px 28px rgba(157,188,43,.35),
    inset 0 -1px 0 rgba(0,0,0,.2);
}
.btn:active{
  transform: translateY(0);
  box-shadow:
    0 10px 20px rgba(157,188,43,.28),
    inset 0 -1px 0 rgba(0,0,0,.25);
}

/* Cajas JSON y notices más neutras */
.json{
  background:#fff;
  border:1px solid var(--border);
  color: var(--eep-charcoal-900);
}

/* Header responsive (quita los inline styles del HTML si quieres) */
.header{
  display:flex; justify-content:center; align-items:center;
  gap:1rem; margin:1rem 0; flex-wrap:wrap; text-align:center;
}
.header .logo{ max-height:60px; width:auto; display:block; }
.header .badge{ font-weight:700; }

/* Pequeños detalles */
.hint{ color: var(--muted); }
.label{ color: var(--muted); }
.actions{ border-top:1px solid var(--border); }

/** preloader */

/* ==== Preloader (EEP theme) ==== */
#preloader{
  position: fixed; inset: 0; display: none;
  align-items: center; justify-content: center;
  background: rgba(0,0,0,.55); backdrop-filter: blur(2px);
  z-index: 9999;
}
.preloader-card{
  width: min(480px, 92vw);
  background: var(--card); color: var(--text);
  border: 1px solid var(--border); border-radius: var(--radius);
  box-shadow: var(--shadow); padding: 20px; text-align: center;
}
.preloader-title{ font-weight: 800; margin-bottom: 6px; }
.preloader-sub{ color: var(--muted); font-size: 13px; margin-bottom: 12px; }

.progress-track{
  height: 12px; border-radius: 999px; overflow: hidden;
  background: var(--input); border: 1px solid var(--border);
}
.progress-bar{
  height: 100%; width: 0%;
  background: linear-gradient(90deg, var(--accent), var(--accent-2));
  transition: width .18s ease;
}
.percent{ margin-top: 8px; font-weight: 700; }



  </style>
</head>
<body>
  <div id="preloader" aria-hidden="true">
  <div class="preloader-card" role="status" aria-live="polite">
    <div class="preloader-title">Enviando a SIRED XM…</div>
    <div class="preloader-sub">No cierres esta ventana.</div>
    <div class="progress-track" aria-label="Progreso de carga">
      <div class="progress-bar" id="preBar"></div>
    </div>
    <div class="percent" id="prePct">0%</div>
  </div>
</div>

  <div class="container">
   <div class="header" style="display:flex; justify-content:center; align-items:center; gap:1rem; margin:1rem 0;">
  <img src="../img/logo.webp" alt="Logo de la empresa" style="max-height:60px; width:auto; display:block;"> <br>
  <span class="badge" style="background:#f0f0f0; color:#333; padding:0.5em 1em; border-radius:12px; font-weight:bold; font-size:1rem; white-space:nowrap;">
    ⚡ SIRED · Carga de eventos ⚡
  </span>
</div>


    <div class="app">
      <div class="app__header">
        <div>
          <div class="app__title">EEP S.A E.S.P</div>
          <div class="app__subtitle">Token → Carga ZIP → Estado → Log</div>
        </div>
      </div>

      <form method="post" enctype="multipart/form-data" novalidate>
        <?php if (!empty($_SESSION['csrf'])): ?>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>

        <div class="app__body">
          <div class="grid">
            <div class="col-4">
              <div class="field">
                <label class="label">Fecha de carga</label>
                <input class="input" name="fecha" type="date" required>
                <div class="hint">Formato: AAAA-MM-DD</div>
              </div>
            </div>

            <div class="col-4">
              <div class="field">
                <label class="label">Tipo de carga</label>
                <select class="select" name="tipo" required>
                  <option value="Diario">Diario</option>
                  <option value="Mensual">Mensual</option>
                  <option value="AltoImpacto">AltoImpacto</option>
                </select>
              </div>
            </div>

            <div class="col-4">
              <div class="field">
                <label class="label">Agente (código ASIC)</label>
                <select class="select" name="agente" required>
                  <option value="EPTD">EPTD</option>
                </select>
                <div class="hint">Usa la lista para evitar errores de tipeo.</div>
              </div>
            </div>

            <div class="col-6">
              <div class="field">
                <label class="label">Mercado</label>
                  <input class="input" name="mercado" value="PUTM" readonly>
              </div>
            </div>


            <div class="col-6">
              <div class="field">
                <label class="label">Correo (opcional)</label>
                <input class="input" name="correo" placeholder="centro.control@energiaputumayo.com">
              </div>
            </div>

            <div class="col-12">
              <div class="field">
                <label class="label">Archivo .zip</label>
                <label class="drop" id="drop">
                  <div class="drop__title">Arrastra y suelta aquí tu ZIP</div>
                  <div class="drop__meta">o haz clic para seleccionar · máx. 20MB</div>
                  <input id="zip" name="zip" type="file" accept=".zip" required>
                  <button type="button" class="btn-secondary" id="pick">Elegir archivo</button>
                  <div class="hint" id="fileHint">Ningún archivo seleccionado</div>
                </label>
              </div>
            </div>
<!---
            <div class="col-4">
              <div class="field">
                <label class="label">Esperar resultado</label>
                <select class="select" name="esperar">
                  <option value="0">No</option>
                  <option value="1">Sí</option>
                </select>
              </div>
            </div>

            <div class="col-4">
              <div class="field">
                <label class="label">Timeout (seg)</label>
                <input class="input" name="timeout" type="number" min="30" value="300" inputmode="numeric">
              </div>
            </div>

            <div class="col-4">
              <div class="field">
                <label class="label">Polling (seg)</label>
                <input class="input" name="poll" type="number" min="3" value="5" inputmode="numeric">
              </div>
            </div>
  ---->          
          </div>
        </div>

        <div class="actions">
          <button class="btn" type="submit" id="submitBtn">Enviar a SIRED XM</button>
         <span class="hint">Se generará bitácora y, si aplica, se descargará el log.</span>
        </div>
      </form>
    </div>

<!-- Mensajes -->
<?php if (!empty($errors)): foreach ($errors as $e): ?>
  <div style="margin-top:12px" class="notice notice--error">⚠️ <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; endif; ?>

<?php if (!empty($result) && !empty($result['ok'])): ?>
  <div style="margin-top:12px" class="notice notice--ok">✅ Envío realizado.</div>

  <div class="app" style="margin-top:10px">
    <div class="app__body">
      <h3 style="margin:0 0 8px">Respuesta de carga</h3>
      <div class="json"><?= htmlspecialchars(json_encode($result['upload'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></div>

      <?php if (!empty($result['solicitudId'])): ?>
        <p style="margin:10px 0 0"><strong>SolicitudId:</strong>
          <?= htmlspecialchars((string)$result['solicitudId'], ENT_QUOTES, 'UTF-8') ?>
        </p>
      <?php endif; ?>

      <?php if (!empty($result['status'])): ?>
        <h3 style="margin:18px 0 8px">Estado</h3>
        <div class="json"><?= htmlspecialchars(json_encode($result['status'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if (!empty($result['logPath'])): ?>
      <?php $logFile = basename((string)$result['logPath']); ?>
      <div style="margin:10px 0 0">
        <p><strong>Log guardado en:</strong>
          <code><?= htmlspecialchars((string)$result['logPath'], ENT_QUOTES, 'UTF-8') ?></code>
        </p>

        <div style="margin-top:8px; display:flex; gap:.6rem; flex-wrap:wrap;">
          <a class="btn" id="btnViewLog"
            data-file="<?= htmlspecialchars($logFile, ENT_QUOTES, 'UTF-8') ?>"
            href="#"
            role="button">Ver log</a>
        </div>

        <!-- Panel donde se mostrará el log -->
        <div id="logPanel" class="json" style="display:none; margin-top:12px; white-space:pre-wrap;"></div>
      </div>
    <?php endif; ?>


      <?php if (!empty($result['runFile'])): ?>
        <p style="margin:6px 0 0">Bitácora: <code><?= htmlspecialchars((string)$result['runFile'], ENT_QUOTES, 'UTF-8') ?></code></p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>



   <!-- <p class="footer-hint">Tip: usa el <strong>select de Agente</strong> para evitar errores de permisos. Si ves 403, solicita habilitación del agente en SIRED.</p>-->
  </div>
<br>  
<div style="text-align:center; margin-top:1rem;">
  <p class="footer-hint">Desarrollado Por: Ing.Richard Potosi</p>
</div>

<script>
(function () {
  // Elementos
  var fileInput = document.getElementById('zip');
  var hint      = document.getElementById('fileHint');
  var pick      = document.getElementById('pick');
  var drop      = document.getElementById('drop');
  var submitBtn = document.getElementById('submitBtn');

  // Formatea y pinta el nombre/tamaño
  function updateHintFromFile(file) {
    if (!file) { hint.textContent = 'Ningún archivo seleccionado'; return; }
    var mb = (file.size / 1024 / 1024).toFixed(2);
    hint.textContent = file.name + ' · ' + mb + ' MB';
  }

  // Click en "Elegir archivo"
  if (pick && fileInput) {
    pick.addEventListener('click', function () { fileInput.click(); });
  }

  // Cambio por selector de archivos
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      var f = fileInput.files && fileInput.files[0];
      updateHintFromFile(f);
    });
  }

  // Drag & drop
  if (drop && fileInput) {
    ['dragenter', 'dragover'].forEach(function (evt) {
      drop.addEventListener(evt, function (e) {
        e.preventDefault(); e.stopPropagation();
        drop.classList.add('is-drag');
        if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
      });
    });
    ['dragleave', 'drop'].forEach(function (evt) {
      drop.addEventListener(evt, function (e) {
        e.preventDefault(); e.stopPropagation();
        drop.classList.remove('is-drag');
      });
    });
    drop.addEventListener('drop', function (e) {
      var dt = e.dataTransfer;
      if (!dt || !dt.files || !dt.files.length) return;
      fileInput.files = dt.files;
      updateHintFromFile(dt.files[0]);
    });
  }

  // Feedback al enviar
  var form = document.querySelector('form[method="post"]');
  if (form && submitBtn) {
    form.addEventListener('submit', function () {
      startPreloader();                 // overlay + progreso
      submitBtn.textContent = 'Enviando…';
      submitBtn.setAttribute('disabled', 'disabled');
      submitBtn.style.opacity = '.8';
    });
  }

  // ==== Preloader ====
  var pre = document.getElementById('preloader');
  var preBar = document.getElementById('preBar');
  var prePct = document.getElementById('prePct');
  var preTimer = null;

 function startPreloader(){
  if(!pre) return;
  pre.style.display = 'flex';
  document.body.setAttribute('aria-busy','true');
  document.body.style.overflow = 'hidden';

  var duration = 8000; // duración en milisegundos (5s)
  var start = performance.now();

  preTimer = setInterval(function(){
    var elapsed = performance.now() - start;
    var pct = Math.min(97, Math.round((elapsed / duration) * 97));
    preBar.style.width = pct + '%';
    prePct.textContent = pct + '%';

    // si pasa el tiempo, mantenlo en 97% hasta que termine el redirect
    if (elapsed >= duration) {
      clearInterval(preTimer);
      preTimer = null;
    }
  }, 80);

  // Cuando la página se vaya a descargar, fuerza 100%
  var finish = function(){
    if(preTimer){ clearInterval(preTimer); preTimer = null; }
    if(preBar && prePct){
      preBar.style.width = '100%';
      prePct.textContent = '100%';
    }
  };
  window.addEventListener('pagehide', finish, {once:true});
  window.addEventListener('beforeunload', finish, {once:true});
}


// === Ver/Ocultar log inline ===
document.addEventListener('click', function (e) {
  const btn = e.target.closest('#btnViewLog, #btnHideLog');
  if (!btn) return;

  e.preventDefault();
  const panel = document.getElementById('logPanel');

  // Ocultar
  if (btn.id === 'btnHideLog') {
    panel.style.display = 'none';
    panel.textContent = '';
    btn.textContent = 'Ver log';
    btn.id = 'btnViewLog';
    return;
  }

  // Cargar y mostrar
  const name = btn.getAttribute('data-file');
  if (!name) return;

  btn.textContent = 'Cargando…';
  fetch('serve_log.php?name=' + encodeURIComponent(name) + '&raw=1', { cache: 'no-store' })
    .then(r => {
      if (!r.ok) throw new Error('No se pudo abrir el log.');
      return r.text();
    })
    .then(txt => {
      panel.textContent = txt;
      panel.style.display = 'block';
      btn.textContent = 'Ocultar log';
      btn.id = 'btnHideLog';
    })
    .catch(err => {
      alert(err.message);
      btn.textContent = 'Ver log';
    });
});


})();  // <<— IMPORTANTE: cierra la IIFE
</script>




</body>
</html>
