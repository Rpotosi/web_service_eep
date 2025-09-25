<?php
// index.php — PRG + CSRF + fixes
// ───────────────────────────────────────────────────────────
session_start();

$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    die('Falta config.php');
}
$config = require $configPath;
// Asegúrate de que SiredClient.php exista en la ruta correcta
require __DIR__ . '/../lib/SiredClient.php'; 

// CSRF: genera si no existe
if (empty($_SESSION['csrf'])) {
    // Generación segura de token CSRF
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

/**
 * ───────────────────────────────────────────────────────────
 * GET ?check=<SolicitudId>[&ajax=1] → consultar estado / intentar bajar log
 * - Si &ajax=1: responde JSON (para autopolling)
 * - Si no: guarda en sesión y redirige (PRG)
 * ───────────────────────────────────────────────────────────
 */
if (isset($_GET['check']) && $_GET['check'] !== '') {
    $isAjax = isset($_GET['ajax']);
    if ($isAjax) header('Content-Type: application/json; charset=utf-8');

    try {
        $solId = preg_replace('/[^a-f0-9-]/i','', (string)$_GET['check']); // sanitiza
        if ($solId) {
            $client = new SiredClient($config);
            $token  = $client->getToken();
            $status = $client->getSolicitud($token, $solId);
            $log    = $client->downloadLog($token, $solId); // null si aún no está

            if ($isAjax) {
                echo json_encode([
                    'ok' => true,
                    'solicitudId' => $solId,
                    'status' => $status,
                    // Se extrae el estado para el JS
                    'state' => (is_array($status) && isset($status['Estado'])) ? $status['Estado'] : null, 
                    'logPath' => $log ?: null,
                    'logFile' => $log ? basename($log) : null,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $_SESSION['result'] = [
                'ok' => true,
                'upload' => ['SolicitudId' => $solId],
                'solicitudId' => $solId,
                'status' => $status,
                'logPath' => $log ?: null,
                'runFile' => null,
            ];
        }
    } catch (Throwable $e) {
        if ($isAjax) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $_SESSION['errors'] = [$e->getMessage()];
    }
    if (!$isAjax) self_redirect();
}

/**
 * ───────────────────────────────────────────────────────────
 * POST (procesa subida y luego REDIRIGE SIEMPRE)
 * ───────────────────────────────────────────────────────────
 */
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF check
        $csrfPost = $_POST['csrf'] ?? ''; // Usando ?? para compatibilidad (asumiendo PHP 7+)
        $csrfSess = $_SESSION['csrf'] ?? '';
        if (!function_exists('hash_equals')) {
            // compat PHP<5.6
            function hash_equals($a,$b){ if(strlen($a)!==strlen($b))return false; $r=0; for($i=0;$i<strlen($a);$i++){$r|=ord($a[$i])^ord($b[$i]);} return $r===0; }
        }
        if (!hash_equals($csrfSess, $csrfPost)) {
            $_SESSION['errors'] = ["CSRF inválido. Refresca la página e inténtalo de nuevo."];
            self_redirect();
        }

        // Inputs (usando ?? para limpieza)
        $fecha  = trim($_POST['fecha'] ?? '');
        $tipo   = trim($_POST['tipo'] ?? '');
        $agente = trim($_POST['agente'] ?? '');
        $mercado = trim($_POST['mercado'] ?? '');
        $correo = trim($_POST['correo'] ?? '');

        // Esperar resultado: solo true si vale "1"
        $esperar = (isset($_POST['esperar']) && $_POST['esperar'] === '1');

        $timeout = max(30, (int)($_POST['timeout'] ?? 300));
        $pollSec = max(3,  (int)($_POST['poll'] ?? 5));

        // Validaciones mínimas
        if ($fecha === '' || $tipo === '' || $agente === '' || $mercado === '') {
            $errors[] = "Campos obligatorios: Fecha, Tipo, Agente, Mercado.";
        }
        if (!isset($_FILES['zip']) || !isset($_FILES['zip']['error']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Debe adjuntar el archivo .zip.";
        }
        // Validación de correo electrónico
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "El formato del correo electrónico es inválido.";
        }

        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            self_redirect();
        }

        // Guardar ZIP en storage
        if (!isset($_FILES['zip']['tmp_name']) || !is_uploaded_file($_FILES['zip']['tmp_name'])) {
            throw new RuntimeException('No se recibió un archivo ZIP válido.');
        }

        $zipTmp = $_FILES['zip']['tmp_name'];
        $zipName = $_FILES['zip']['name'] ?? 'archivo.zip';

        // Sanitiza nombre (permite letras, números, _, ., -)
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($zipName));
        if ($safe === '' || $safe === null) $safe = 'archivo.zip';

        $storageDir = rtrim($config['storage_dir'], '/\\');
        if (!is_dir($storageDir)) {
            // Mejor evitar el @ y dejar que la excepción se lance
            if (!mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
                throw new RuntimeException('No se pudo crear el directorio de storage: ' . $storageDir);
            }
        }

        // Ruta final con timestamp para evitar choques
        $zipPath = $storageDir . '/' . date('Ymd_His') . '_' . $safe;

        // Mueve el archivo
        if (!move_uploaded_file($zipTmp, $zipPath)) {
            throw new RuntimeException('No se pudo mover el ZIP a storage.');
        }

        // Flujo SIRED
        $client = new SiredClient($config);
        $token  = $client->getToken();
        $upload = $client->uploadZip($token, $zipPath, $fecha, $tipo, $agente, $mercado, $correo);

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

        $status = null;
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
            'FechaEjecucion' => date('c'),
            'Parametros'   => array(
                'FechaDeCarga' => $fecha,
                'TipoCarga'  => $tipo,
                'Agente'     => $agente,
                'Mercado'    => $mercado,
                'Correo'     => $correo,
                'Zip'      => $zipPath
            ),
            'UploadRespuesta' => $upload,
            'SolicitudId'  => $solId,
            'ConsultaEstado' => $status,
            'LogPath'    => $logPath
        );
        $runFile = $client->saveRun($run);

        // Resultado final → sesión → redirect (PRG)
        $_SESSION['result'] = array(
            'ok' => true,
            'upload' => $upload,
            'solicitudId' => $solId,
            'status' => $status,
            'logPath' => $logPath,
            'runFile' => $runFile
        );

        self_redirect();

    } catch (Throwable $e) {
        $_SESSION['errors'] = array($e->getMessage());
        self_redirect();
    }
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
            --bg: #f6f8fb;
            --card:#ffffff;
            --muted:#5c6470;
            --text:#0e1525;
            --accent:#9dbc2b;
            --accent-2:#7ea122;
            --danger-bg:#2a0f13; --danger:#ff6b6b; --danger-br:#5c1d22;
            --ok-bg:#10251a; --ok:#34d399; --ok-br:#1d3b2b;
            --border:#e7ecf3;
            --input:#f4f7fb;
            --shadow:0 10px 30px rgba(14,21,37,.08);
            --radius:16px;
        }
        @media (prefers-color-scheme: dark){
            :root{
                --bg:#191919; --card:#1F1F1F; --text:#EDEDED; --muted:#A9A9A9;
                --border:#2A2A2A; --input:#222; --shadow:0 10px 30px rgba(0,0,0,.40);
            }
        }
        *{box-sizing:border-box}
        body{
            margin:0; background:
                radial-gradient(1100px 700px at 0% -20%, rgba(157,188,43,.06), transparent 60%),
                linear-gradient(180deg, rgba(0,0,0,.02), rgba(0,0,0,0)),
                var(--bg);
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, "Helvetica Neue", Arial;
            color: var(--text);
            line-height: 1.4;
            padding: 40px 16px 56px;
        }
        .container{max-width: 980px; margin: 0 auto;}
        .header{ display:flex; align-items:center; gap:12px; margin: 0 0 18px; }
        .badge{
            display:inline-flex; align-items:center; gap:8px;
            background: linear-gradient(135deg, rgba(157,188,43,.10), rgba(157,188,43,.06));
            border:1px solid var(--border); color: var(--muted);
            padding:6px 10px; border-radius:999px; font-size:12px; letter-spacing:.2px;
        }
        .app{
            background: linear-gradient(180deg, rgba(157,188,43,.03), rgba(255,255,255,0));
            border: 1px solid var(--border); box-shadow: var(--shadow);
            border-radius: var(--radius); overflow: clip;
        }
        .app__header{
            padding: 18px 20px; display:flex; justify-content:space-between; align-items:center;
            background: linear-gradient(180deg, rgba(157,188,43,.08), rgba(157,188,43,0));
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
        .input:focus, .select:focus{ border-color: var(--accent); box-shadow: 0 0 0 3px rgba(157,188,43,.22); }
        .input:read-only { background: var(--border); color: var(--muted); }
        .hint{ font-size:12px; color: var(--muted); }

        .drop{ border:1.5px dashed var(--border); background: linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,0));
            border-radius:12px; padding:18px; display:grid; gap:6px; place-items:center; text-align:center;
            transition: border-color .18s, background .18s; }
        .drop.is-drag{ border-color: var(--accent); background: rgba(157,188,43,.08); }
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
            background:#fff;
            border: 1px solid var(--border);
            border-radius: 12px; padding: 12px; overflow:auto; white-space:pre-wrap; word-break: break-word;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; font-size: 12.5px;
        }

        /* ==== Preloader ==== */
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
            <img src="../img/logo.webp" alt="Logo EEP S.A E.S.P" style="max-height:60px; width:auto; display:block;"> <br>
            <span class="badge" style="padding:0.5em 1em; border-radius:12px; font-weight:bold; font-size:1rem; white-space:nowrap;">
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
                    </div>
                </div>

                <div class="actions">
                    <input type="hidden" name="esperar" value="1">
                    <input type="hidden" name="timeout" value="300">
                    <input type="hidden" name="poll" value="5">

                    <button class="btn" type="submit" id="submitBtn">Enviar a SIRED XM</button>
                    <span class="hint">Se generará bitácora y, si aplica, se descargará el log.</span>
                </div>
            </form>
        </div>

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

                    <h3 style="margin:18px 0 8px">Estado</h3>
                    <div id="statusJson" class="json">
                        <?= htmlspecialchars(json_encode($result['status'] ?: ['info'=>'Consultando…'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>
                    </div>

                    <div id="logContainer">
                        <?php if (!empty($result['logPath'])): ?>
                            <?php $logPath = (string)$result['logPath']; // USAR RUTA COMPLETA ?>
                            <div style="margin:10px 0 0">
                                <p><strong>Log guardado en:</strong>
                                    <code><?= htmlspecialchars($logPath, ENT_QUOTES, 'UTF-8') ?></code>
                                </p>
                            <div style="margin-top:8px; display:flex; gap:.6rem; flex-wrap:wrap;">
                                <a class="btn" id="btnViewLog"
                                    data-file="<?= htmlspecialchars($logPath, ENT_QUOTES, 'UTF-8') ?>"
                                    href="server_log.php?path=<?= urlencode($logPath) ?>" role="button">Ver log</a>
                            </div>

                                <div id="logPanel" class="json" style="display:none; margin-top:12px; white-space:pre-wrap;"></div>
                            </div>
                        <?php else: ?>
                            <p class="hint" id="initial-poll-hint" style="margin-top:10px">Aún no hay log disponible. Se consultará automáticamente…</p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($result['solicitudId'])): ?>
                        <div id="autoPoll"
                                     data-solicitud="<?= htmlspecialchars((string)$result['solicitudId'], ENT_QUOTES, 'UTF-8') ?>"
                                     data-haslog="<?= !empty($result['logPath']) ? '1' : '0' ?>"></div>
                    <?php endif; ?>

                    <?php if (!empty($result['runFile'])): ?>
                        <p style="margin:6px 0 0">Bitácora: <code><?= htmlspecialchars((string)$result['runFile'], ENT_QUOTES, 'UTF-8') ?></code></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

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

    // ==== Preloader Functions ==== 
    var pre = document.getElementById('preloader');
    var preBar = document.getElementById('preBar');
    var prePct = document.getElementById('prePct');
    var preTimer = null;

    function startPreloader(){
        if(!pre) return;
        pre.style.display = 'flex';
        document.body.setAttribute('aria-busy','true');
        document.body.style.overflow = 'hidden';

        var duration = 8000; 
        var start = performance.now();

        preTimer = setInterval(function(){
            var elapsed = performance.now() - start;
            var pct = Math.min(97, Math.round((elapsed / duration) * 97));
            preBar.style.width = pct + '%';
            prePct.textContent = pct + '%';

            if (elapsed >= duration) {
                clearInterval(preTimer);
                preTimer = null;
            }
        }, 80);

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

    function stopPreloader(){
        if(!pre) return;
        
        // Detiene el temporizador de animación
        if(preTimer){ clearInterval(preTimer); preTimer = null; }
        
        // Muestra el 100% final y oculta
        if(preBar && prePct){
            preBar.style.width = '100%';
            prePct.textContent = '100%';
        }
        
        pre.style.display = 'none';
        document.body.removeAttribute('aria-busy');
        document.body.style.overflow = ''; // Restaura el scroll
    }
    
    // Feedback al enviar (INICIA PRELOADER)
    var form = document.querySelector('form[method="post"]');
    if (form && submitBtn) {
        form.addEventListener('submit', function () {
            startPreloader();  // iniciar preloader
            submitBtn.textContent = 'Enviando…';
            submitBtn.setAttribute('disabled', 'disabled');
            submitBtn.style.opacity = '.8';
        });
    }


    // === Ver/Ocultar log inline (Ajuste para usar 'path' en lugar de 'name') ===
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
        const filePath = btn.getAttribute('data-file'); // Obtenemos la ruta completa
        if (!filePath) return;

        btn.textContent = 'Cargando…';
        
        // <<< CORRECCIÓN APLICADA AQUÍ >>>
        // El script server_log.php espera el parámetro 'path', no 'name'
        fetch('server_log.php?path=' + encodeURIComponent(filePath) + '&raw=1', { cache: 'no-store' })
            .then(r => {
                if (!r.ok) throw new Error('No se pudo abrir el log. (HTTP Status: ' + r.status + ')');
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

    // ==== Auto-polling de estado y log ==== 
    (function(){
        var marker = document.getElementById('autoPoll');
        if (!marker) return;

        var solicitudId = marker.dataset.solicitud || "";
        var yaTieneLog = marker.dataset.haslog === '1';
        
        // Si no hay SolicitudId o ya tenemos el log, no iniciamos el polling
        if (!solicitudId || yaTieneLog) return; 

        var tries = 0, maxTries = 60; // ~5 min con every=5s
        var every = 5000;
        var timer = null;

        var statusBox = document.getElementById('statusJson');
        var logBox  = document.getElementById('logContainer');

        function escapeHtml(s){
            return String(s)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }

        function mountLogUI(logPath){
            var logPathEscaped = escapeHtml(logPath);
            var html = ''
                + '<div style="margin:10px 0 0">'
                + ' <p><strong>Log guardado en:</strong> <code>' + logPathEscaped + '</code></p>'
                + ' <div style="margin-top:8px; display:flex; gap:.6rem; flex-wrap:wrap;">'
                // Aquí se sigue usando el enlace normal con 'path'
                + '  <a class="btn" id="btnViewLog" data-file="' + logPathEscaped + '" href="server_log.php?path=' + encodeURIComponent(logPath) + '" role="button">Ver log</a>'
                + ' </div>'
                + ' <div id="logPanel" class="json" style="display:none; margin-top:12px; white-space:pre-wrap;"></div>'
                + '</div>';
            logBox.innerHTML = html;
        }

        function showValidationAlert(type, message) {
            const container = statusBox.closest('.app__body');
            // Elimina alertas previas para no duplicar
            document.querySelectorAll('.app + .notice').forEach(el => el.remove()); 
            
            let alertHtml = '';
            if (type === 'ok') {
                alertHtml = '<div style="margin-top:12px" class="notice notice--ok">✅ ' + message + '</div>';
            } else if (type === 'error') {
                alertHtml = '<div style="margin-top:12px" class="notice notice--error">⚠️ ' + message + '</div>';
            }
            // Inserta la alerta después del contenedor principal (.app)
            container.closest('.app').insertAdjacentHTML('afterend', alertHtml); 
        }

        function tick(){
            tries++;
            fetch('?check=' + encodeURIComponent(solicitudId) + '&ajax=1', { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        // 1. Actualiza el estado JSON
                        var jsonStatus = JSON.stringify(data.status || { info: 'Estado no disponible' }, null, 4);
                        statusBox.innerHTML = escapeHtml(jsonStatus);

                        var estado = data.state || '';
                        var isFinal = ['Exitosa', 'Fallido', 'Reemplazado'].includes(estado);

                        // 2. Si es estado final o ya tiene log, detiene el polling
                        if (isFinal || data.logPath) {
                            clearInterval(timer);
                            timer = null;
                            
                            // Detener el Preloader al finalizar (éxito/fallo/reemplazo)
                            stopPreloader(); 
                            
                            // Mostrar alerta de validación
                            if (estado === 'Exitosa') {
                                showValidationAlert('ok', 'La solicitud ha finalizado con éxito.');
                            } else if (estado === 'Fallido') {
                                showValidationAlert('error', 'La solicitud ha fallado. Revisa el log para más detalles.');
                            } else if (estado === 'Reemplazado') {
                                showValidationAlert('ok', 'La solicitud ha sido reemplazada.');
                            }

                            if (data.logPath && !yaTieneLog) {
                                // 3. Monta la UI del log si ya está disponible
                                mountLogUI(data.logPath);
                                yaTieneLog = true;
                            }
                        } else if (tries >= maxTries) {
                            clearInterval(timer);
                            timer = null;
                            
                            // Detener el Preloader por Timeout
                            stopPreloader(); 
                            
                            showValidationAlert('error', 'Tiempo de espera agotado. El proceso sigue en SIRED.');
                            // Mensaje de tiempo de espera agotado en el JSON de estado
                            statusBox.innerHTML = escapeHtml(
                                JSON.stringify({ error: 'Tiempo de espera agotado. Consulta manual si es necesario.' }, null, 4)
                            );
                        }
                    } else {
                        clearInterval(timer);
                        timer = null;
                        
                        // Detener el Preloader por error de consulta
                        stopPreloader(); 
                        
                        showValidationAlert('error', 'Error al consultar estado: ' + (data.error || 'Error desconocido'));
                        statusBox.innerHTML = escapeHtml(
                            JSON.stringify({ error: data.error || 'Error desconocido al consultar estado' }, null, 4)
                        );
                    }
                })
                .catch(err => {
                    clearInterval(timer);
                    timer = null;
                    
                    // Detener el Preloader por error de conexión
                    stopPreloader(); 
                    
                    showValidationAlert('error', 'Error de conexión: No se pudo conectar con el servidor.');
                    statusBox.innerHTML = escapeHtml(
                        JSON.stringify({ error: 'Error de conexión: ' + err.message }, null, 4)
                    );
                });
        }

        timer = setInterval(tick, every);
        
        // Ocultar el mensaje de espera inicial si existe y se inicia el polling
        var initialHint = document.getElementById('initial-poll-hint');
        if (initialHint) {
             initialHint.style.display = 'none';
        }
        tick(); // Primera ejecución inmediata
    })();
})();
</script>

</body>
</html>