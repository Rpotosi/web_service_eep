<?php
declare(strict_types=1);

/**
 * SiredClient — 2030 edition
 * - Token cache (evita pedir token en cada request)
 * - Reintentos con backoff (429/5xx)
 * - Ocp-Apim-Subscription-Key por header y query (anti-redirect que pierde headers)
 * - Helpers apiGet/apiPostMultipart para nuevas consultas del manual
 * - Mensajes de error claros y bitácora sin exponer secretos
 */
final class SiredClient
{
    private const RETRY_STATUS  = [429, 500, 502, 503, 504];
    private const MAX_RETRIES   = 3;
    private const BACKOFF_START = 0.6; // segundos

    private string $tokenUrl;
    private string $clientId;
    private string $clientSecret;
    private string $scope;
    private string $subscriptionKey;
    private string $apiBase;
    private string $storageDir;
    private int    $timeout;
    private bool   $verifySSL;
    private string $userAgent;

    public function __construct(array $cfg)
    {
        $this->tokenUrl       = (string)$cfg['token_url'];
        $this->clientId       = (string)$cfg['client_id'];
        $this->clientSecret   = (string)$cfg['client_secret'];
        $this->scope          = (string)$cfg['scope'];
        $this->subscriptionKey= (string)$cfg['subscription_key'];
        $this->apiBase        = rtrim((string)$cfg['api_base'], '/');
        $this->storageDir     = (string)$cfg['storage_dir'];
        $this->timeout        = (int)($cfg['http_timeout'] ?? 120);
        $this->verifySSL      = (bool)($cfg['verify_ssl'] ?? true);
        $this->userAgent      = ($cfg['user_agent'] ?? 'SiredClient/2030 (+php)');

        if ($this->tokenUrl === '' || $this->clientId === '' || $this->clientSecret === '' || $this->scope === '') {
            throw new \InvalidArgumentException('Config token incompleta: token_url, client_id, client_secret, scope');
        }
        if ($this->subscriptionKey === '') {
            throw new \InvalidArgumentException('Falta subscription_key (APIM)');
        }
        if (!is_dir($this->storageDir) && !@mkdir($this->storageDir, 0775, true)) {
            throw new \RuntimeException("No se pudo crear storage_dir: {$this->storageDir}");
        }
    }

    // ───────────────────────────────── helpers base ─────────────────────────────────

    private function tokenCachePath(): string
    {
        return rtrim($this->storageDir, '/\\') . '/token_cache.json';
    }

    private function buildUrl(string $path, array $query = []): string
    {
        $query = array_merge($query, [
            // duplicamos la key en query como “plan B” ante redirects sin headers
            'subscription-key' => $this->subscriptionKey,
        ]);
        $qs = http_build_query($query);
        return $this->apiBase . '/' . ltrim($path, '/') . ($qs ? "?{$qs}" : '');
    }

    private function baseHeaders(array $extra = [], string $accept = 'application/json'): array
    {
        return array_merge([
            "Accept: {$accept}",
            "Ocp-Apim-Subscription-Key: {$this->subscriptionKey}",
            "User-Agent: {$this->userAgent}",
        ], $extra);
    }

    private function curlExec(callable $setup): array
    {
        $ch = curl_init();
        $setup($ch);

        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL error: {$err}");
        }
        curl_close($ch);
        return [$status, (string)$resp];
    }

    /** cURL con reintentos (para 429/5xx). $fn recibe ($retryIndex) y debe devolver [$status,$resp]. */
    private function withRetries(callable $fn): array
    {
        $attempt = 0;
        $backoff = self::BACKOFF_START;

        do {
            [$status, $resp] = $fn($attempt);

            if (!in_array($status, self::RETRY_STATUS, true)) {
                return [$status, $resp];
            }

            // Retry header (si viene)
            $retryAfter = 0.0;
            if (is_string($resp) && preg_match('/Retry-After:\s*(\d+)/i', $resp, $m)) {
                $retryAfter = (float)$m[1];
            }
            $sleep = max($retryAfter, $backoff);
            usleep((int)round($sleep * 1_000_000));
            $backoff *= 1.7;
        } while (++$attempt < self::MAX_RETRIES);

        return [$status, $resp];
    }

    // ───────────────────────────────── HTTP “low level” ─────────────────────────────────

    private function postForm(string $url, array $fields, array $headers = []): array
    {
        return $this->curlExec(function ($ch) use ($url, $fields, $headers) {
            $hdrs = array_merge($headers, [
                'Content-Type: application/x-www-form-urlencoded',
            ]);
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($fields),
                CURLOPT_HTTPHEADER     => $hdrs,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
                CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
                CURLOPT_USERAGENT      => $this->userAgent,
            ]);
        });
    }

    private function get(string $url, array $headers = []): array
    {
        return $this->curlExec(function ($ch) use ($url, $headers) {
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
                CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
                CURLOPT_USERAGENT      => $this->userAgent,
            ]);
        });
    }

    private function postMultipart(string $url, array $fields, array $headers = []): array
    {
        return $this->curlExec(function ($ch) use ($url, $fields, $headers) {
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $fields,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
                CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
                CURLOPT_USERAGENT      => $this->userAgent,
            ]);
        });
    }

    // ───────────────────────────────── Token (con cache) ─────────────────────────────────

    /** Pide un token nuevo al IdP y lo cachea con expires_at */
    public function getFreshToken(): array
    {
        $fields = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => $this->scope,
            'grant_type'    => 'client_credentials',
        ];

        [$status, $resp] = $this->withRetries(function () use ($fields) {
            return $this->postForm($this->tokenUrl, $fields, ['Accept: application/json']);
        });

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("Token HTTP {$status}: {$resp}");
        }

        $json = json_decode($resp, true);
        if (!is_array($json) || empty($json['access_token'])) {
            throw new \RuntimeException("Respuesta token inválida: {$resp}");
        }

        $ttl               = (int)($json['expires_in'] ?? 3600);
        $json['obtained_at'] = time();
        $json['expires_at']  = time() + max(60, $ttl - 30); // margen

        @file_put_contents($this->tokenCachePath(), json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $json;
    }

    /** Devuelve token desde cache si no expiró; si no, renueva */
    public function getToken(): string
    {
        $cacheFile = $this->tokenCachePath();
        if (is_file($cacheFile)) {
            $data = json_decode((string)file_get_contents($cacheFile), true) ?: [];
            if (!empty($data['access_token']) && !empty($data['expires_at']) && time() < (int)$data['expires_at']) {
                return (string)$data['access_token'];
            }
        }
        $fresh = $this->getFreshToken();
        return (string)$fresh['access_token'];
    }

    /** Metadata del token (útil para debug) */
    public function getTokenMeta(bool $mask = true): array
    {
        $meta = is_file($this->tokenCachePath())
            ? json_decode((string)file_get_contents($this->tokenCachePath()), true) ?: []
            : [];
        if (empty($meta['access_token'])) {
            $meta = $this->getFreshToken();
        }
        $tok = (string)($meta['access_token'] ?? '');
        $meta['access_token_masked'] = $mask && $tok !== '' ? (substr($tok, 0, 16) . '...' . substr($tok, -16)) : $tok;
        return $meta;
    }

    // ──────────────────────────────── API genérico (para futuras consultas) ────────────────────────────────

    /** GET genérico de la API SIRED */
    public function apiGet(string $path, array $query = [], ?string $token = null, string $accept = 'application/json'): array
    {
        $token   = $token ?: $this->getToken();
        $url     = $this->buildUrl($path, $query);
        $headers = $this->baseHeaders(["Authorization: Bearer {$token}"], $accept);

        [$status, $resp] = $this->withRetries(function () use ($url, $headers) {
            return $this->get($url, $headers);
        });

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("GET {$path} HTTP {$status}: {$resp}");
        }
        $json = json_decode($resp, true);
        return $json !== null ? $json : ['raw' => $resp];
    }

    /** POST multipart genérico (por si agregas más endpoints con archivos) */
    public function apiPostMultipart(string $path, array $query, array $fields, ?string $token = null, string $accept = 'application/json, text/plain'): array
    {
        $token   = $token ?: $this->getToken();
        $url     = $this->buildUrl($path, $query);
        $headers = $this->baseHeaders(["Authorization: Bearer {$token}"], $accept);

        [$status, $resp] = $this->withRetries(function (int $i) use ($url, $fields, $headers) {
            return $this->postMultipart($url, $fields, $headers);
        });

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException("POST {$path} HTTP {$status}: {$resp}");
        }
        $json = json_decode($resp, true);
        return $json !== null ? $json : ['raw' => $resp];
    }

    // ──────────────────────────────── Operaciones del manual ────────────────────────────────

    /** Sube ZIP (evento) */
    public function uploadZip(
        string $token,
        string $zipPath,
        string $fechaDeCarga,
        string $tipoCarga,
        string $agente,
        string $mercado,
        ?string $correo = null
    ): array {
        if (!is_file($zipPath)) {
            throw new \RuntimeException("No existe el ZIP: {$zipPath}");
        }

        $query = array_filter([
            'FechaDeCarga' => $fechaDeCarga,
            'TipoCarga'    => $tipoCarga,
            'Agente'       => strtoupper($agente),
            'Mercado'      => strtoupper($mercado),
            'Correo'       => $correo,
        ], static fn($v) => $v !== null && $v !== '');

        $cfile = new \CURLFile($zipPath, 'application/zip', basename($zipPath));
        $post  = ['Archivo' => $cfile];

        // usa helper genérico (aporta reintentos + headers base + key en query)
        $resp = $this->apiPostMultipart('reportes-operadores-red/api/v1/evento', $query, $post, $token, 'text/plain, application/json');

        // Puede venir texto simple; intenta extraer GUID si no hay JSON “formal”
        if (!isset($resp['SolicitudId']) && isset($resp['raw']) && is_string($resp['raw'])) {
            if (preg_match('/[0-9a-fA-F-]{36}/', $resp['raw'], $m)) {
                $resp['SolicitudId'] = $m[0];
            }
        }
        return $resp;
    }

    /** Consulta por SolicitudId */
    public function getSolicitud(string $token, string $solicitudId): array
    {
        return $this->apiGet(
            'reportes-operadores-red/api/v1/evento/consultar-solicitud',
            ['solicitudId' => $solicitudId],
            $token,
            'application/json'
        );
    }

   
    /** Descarga log (txt) y lo guarda en storage */
    
    public function downloadLog(string $token, string $solicitudId, ?string $destPath = null) : ?string {
    $url = "{$this->apiBase}/reportes-operadores-red/api/v1/evento/descargar-log?solicitudId=" . urlencode($solicitudId);
    $headers = [
        "Authorization: Bearer {$token}",
        "Ocp-Apim-Subscription-Key: {$this->subscriptionKey}",
        "Accept: text/plain",
    ];
    [$status, $resp] = $this->get($url, $headers);

    if ($status >= 200 && $status < 300) {
        $logsDir = $this->storageDir . "/logs";
        if (!is_dir($logsDir)) { @mkdir($logsDir, 0775, true); }
        if ($destPath === null) {
            $destPath = $logsDir . "/log_" . $solicitudId . ".txt";
        }
        file_put_contents($destPath, $resp);
        return $destPath;
    }

    // Si aún no hay log, retorna null (p.ej. 404/409).
    return null;
}

// Espera hasta que la solicitud quede en estado final o venza el plazo
public function waitForEstado(string $token, string $solicitudId, int $timeoutSec = 180, int $pollSec = 5): ?array {
    $deadline = time() + $timeoutSec;
    $finales = ['Exitosa','Fallido','Reemplazado'];
    $ultimo = null;
    do {
        $ultimo = $this->getSolicitud($token, $solicitudId);
        $estado = isset($ultimo['Estado']) ? (string)$ultimo['Estado'] : '';
        if (in_array($estado, $finales, true)) {
            return $ultimo; // terminó
        }
        sleep($pollSec);
    } while (time() < $deadline);
    return $ultimo; // devolvemos lo último conocido (p.ej. "Procesando")
}

// Reintenta descargar el log durante cierto tiempo (por si XM lo publica segundos después)
public function tryDownloadLogWithRetry(string $token, string $solicitudId, int $timeoutSec = 90, int $pollSec = 5): ?string {
    $deadline = time() + $timeoutSec;
    do {
        $path = $this->downloadLog($token, $solicitudId);
        if (is_string($path) && $path !== '') {
            return $path;
        }
        sleep($pollSec);
    } while (time() < $deadline);
    return null;
}





    // ──────────────────────────────── Bitácora ────────────────────────────────

    public function saveRun(array $data): string
    {
        // Evita filtrar secretos por accidente
        if (isset($data['token'])) {
            $tok = (string)$data['token'];
            $data['token_masked'] = substr($tok, 0, 12) . '...' . substr($tok, -12);
            unset($data['token']);
        }
        $fname = rtrim($this->storageDir, '/\\') . '/run_' . date('Ymd_His') . '.json';
        file_put_contents($fname, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $fname;
    }
}
