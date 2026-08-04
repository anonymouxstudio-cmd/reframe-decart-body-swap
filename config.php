<?php
/**
 * =============================================================================
 *  DECART AI — REAL-TIME FULL BODY SWAP
 *  Backend configuration & API proxy
 * =============================================================================
 *
 *  WHAT THIS FILE ACTUALLY DOES (please read before wiring up your key)
 * -----------------------------------------------------------------------------
 *  Decart's realtime models (Lucy 2.5 / Lucy Virtual Try-On, etc.) stream
 *  video over WebRTC directly between the browser and Decart's edge network
 *  using the official @decartai/sdk. There is no "send one JPEG frame, get one
 *  JPEG frame back" HTTP endpoint for the realtime models — a PHP relay cannot
 *  sit in the middle of a WebRTC media stream (that's the whole point of
 *  WebRTC: the video never touches your server).
 *
 *  So this backend does the two things a server genuinely SHOULD do, and only
 *  those things:
 *
 *    1. Mint short-lived CLIENT TOKENS from your permanent secret API key.
 *       The browser never sees your real key — it only ever sees a token
 *       that expires in a few minutes. This is Decart's documented pattern
 *       for browser/mobile clients.
 *
 *    2. Receive, validate, and store the REFERENCE IMAGE the user uploads,
 *       so the frontend can hand it straight to the SDK's realtime session
 *       (`realtimeClient.set({ image, prompt })`).
 *
 *  Everything else in this file (rate limiting, temp-file cleanup, upload
 *  validation, structured JSON errors) is standard hardening for a small
 *  public-facing PHP endpoint.
 *
 *  Token minting hits Decart's confirmed REST endpoint directly:
 *  POST https://api.decart.ai/v1/client/tokens
 *  Auth is via the `x-api-key` header (NOT `Authorization: Bearer`) — this
 *  is the one thing that differs from most REST APIs, so if you ever change
 *  decart_request() for other calls, don't accidentally "fix" this back to
 *  Bearer auth. Response shape: { apiKey, expiresAt, permissions?, constraints? }.
 *  Source: https://docs.platform.decart.ai/api-reference/create-client-token
 * =============================================================================
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak PHP errors/API keys into output

// -----------------------------------------------------------------------------
// 1. CONFIGURATION — edit this block only
// -----------------------------------------------------------------------------

// Prefer an environment variable (set this in Render's dashboard) so the real
// key is never committed to your repo. Falls back to the inline value below
// only if the env var isn't set — handy for quick local testing.
const DECART_API_KEY   = '';   // ⚠️ leave blank if using DECART_API_KEY env var on Render
function decart_api_key(): string {
    $env = getenv('DECART_API_KEY');
    return ($env !== false && $env !== '') ? $env : DECART_API_KEY;
}
const DECART_BASE_URL  = 'https://api.decart.ai';       // Decart API base URL
const DECART_MODEL_ID  = 'lucy-2.5';                    // default realtime model
const TOKEN_ENDPOINT_PATH = '/v1/client/tokens';        // confirmed against Decart's OpenAPI spec
const TOKEN_TTL_SECONDS   = 300;                        // 5 minute client token lifetime

const REQUEST_TIMEOUT_SECONDS = 15;
const MAX_UPLOAD_BYTES        = 8 * 1024 * 1024;        // 8 MB reference image cap
const ALLOWED_MIME_TYPES      = ['image/jpeg', 'image/png', 'image/webp'];
const ALLOWED_EXTENSIONS      = ['jpg', 'jpeg', 'png', 'webp'];

const TEMP_DIR           = __DIR__ . '/tmp_references';
const TEMP_FILE_MAX_AGE  = 3600;   // garbage-collect reference images after 1 hour

const RATE_LIMIT_DIR      = __DIR__ . '/tmp_ratelimit';
const RATE_LIMIT_WINDOW   = 60;    // seconds
const RATE_LIMIT_MAX_HITS = 30;    // requests per window per client per action

// -----------------------------------------------------------------------------
// 2. BOOTSTRAP
// -----------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS: same-origin by default. If you host index.html elsewhere, set an
// explicit origin here — never use '*' once a real API key is involved.
// header('Access-Control-Allow-Origin: https://your-domain.com');

foreach ([TEMP_DIR, RATE_LIMIT_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function json_out(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $httpCode = 400, string $code = 'error'): void
{
    json_out(['ok' => false, 'error' => $code, 'message' => $message], $httpCode);
}

function client_fingerprint(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return substr(preg_replace('/[^a-zA-Z0-9_.:]/', '_', explode(',', $ip)[0]), 0, 64);
}

/**
 * Very small file-based sliding-window rate limiter. No database required —
 * appropriate for the "minimal project" constraint. Swap for Redis/APCu in
 * a high-traffic deployment.
 */
function enforce_rate_limit(string $action): void
{
    $key  = client_fingerprint() . '_' . $action;
    $file = RATE_LIMIT_DIR . '/' . $key . '.json';

    $now  = time();
    $hits = [];

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return; // fail open rather than break the app if disk is unavailable
    }

    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $hits = $decoded;
        }
    }

    // drop hits outside the current window
    $hits = array_values(array_filter($hits, fn($t) => ($now - $t) < RATE_LIMIT_WINDOW));

    if (count($hits) >= RATE_LIMIT_MAX_HITS) {
        flock($fp, LOCK_UN);
        fclose($fp);
        json_error('Rate limit exceeded. Please slow down.', 429, 'rate_limited');
    }

    $hits[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($hits));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/** Removes reference-image temp files older than TEMP_FILE_MAX_AGE. */
function gc_temp_references(): void
{
    foreach (glob(TEMP_DIR . '/*') ?: [] as $path) {
        if (is_file($path) && (time() - filemtime($path)) > TEMP_FILE_MAX_AGE) {
            @unlink($path);
        }
    }
}

/** cURL helper with sane timeouts and JSON handling. */
function decart_request(string $method, string $path, array $body = null, array $extraHeaders = []): array
{
    $apiKey = decart_api_key();
    if ($apiKey === '') {
        json_error('Server is not configured yet — set the DECART_API_KEY environment variable (or fill in config.php).', 500, 'not_configured');
    }

    $ch = curl_init(rtrim(DECART_BASE_URL, '/') . $path);
    $headers = array_merge([
        'x-api-key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $extraHeaders);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => REQUEST_TIMEOUT_SECONDS,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        json_error("Could not reach Decart API: $curlErr", 502, 'upstream_unreachable');
    }

    $decoded = json_decode($response, true);
    return ['status' => $httpCode, 'body' => is_array($decoded) ? $decoded : ['raw' => $response]];
}

// -----------------------------------------------------------------------------
// 3. ROUTER
// -----------------------------------------------------------------------------

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // -------------------------------------------------------------------
    // GET /config.php?action=status
    // Tells the frontend whether the backend is configured, without ever
    // exposing the actual key.
    // -------------------------------------------------------------------
    case 'status':
        json_out([
            'ok'          => true,
            'configured'  => decart_api_key() !== '',
            'model'       => DECART_MODEL_ID,
            'baseUrl'     => DECART_BASE_URL,
            'maxUploadMb' => round(MAX_UPLOAD_BYTES / 1024 / 1024, 1),
            'allowedTypes'=> ALLOWED_EXTENSIONS,
        ]);
        break;

    // -------------------------------------------------------------------
    // POST /config.php?action=token
    // Mints a short-lived client token the frontend can hand to
    // createDecartClient({ apiKey: token }) instead of the real secret key.
    // -------------------------------------------------------------------
    case 'token':
        enforce_rate_limit('token');

        $model = $_POST['model'] ?? DECART_MODEL_ID;
        $model = preg_replace('/[^a-z0-9\-\.]/i', '', (string)$model);

        $result = decart_request('POST', TOKEN_ENDPOINT_PATH, [
            'expiresIn'     => TOKEN_TTL_SECONDS,
            'allowedModels' => [$model],
        ]);

        if ($result['status'] < 200 || $result['status'] >= 300) {
            json_error(
                'Token request rejected by Decart (HTTP ' . $result['status'] . '). ' .
                'Double-check DECART_API_KEY and TOKEN_ENDPOINT_PATH in config.php.',
                502,
                'token_mint_failed'
            );
        }

        json_out([
            'ok'        => true,
            'apiKey'    => $result['body']['apiKey'] ?? null,
            'expiresAt' => $result['body']['expiresAt'] ?? null,
            'expiresIn' => TOKEN_TTL_SECONDS,
            'model'     => $model,
        ]);
        break;

    // -------------------------------------------------------------------
    // POST /config.php?action=upload_reference   (multipart/form-data, field "image")
    // Validates and stores the reference image; returns a URL the frontend
    // can fetch and hand to the SDK's realtime session.
    // -------------------------------------------------------------------
    case 'upload_reference':
        enforce_rate_limit('upload_reference');
        gc_temp_references();

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            json_error('No valid image uploaded.', 400, 'upload_failed');
        }

        $file = $_FILES['image'];

        if ($file['size'] > MAX_UPLOAD_BYTES) {
            json_error('Image exceeds the ' . round(MAX_UPLOAD_BYTES / 1024 / 1024, 1) . 'MB limit.', 413, 'file_too_large');
        }

        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
            json_error('Unsupported image type: ' . htmlspecialchars($mimeType), 415, 'invalid_type');
        }

        $ext = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => 'bin',
        };

        // Reject anything that isn't actually a decodable image, even if the
        // MIME type looked fine (defends against polyglot files).
        if (@getimagesize($file['tmp_name']) === false) {
            json_error('File is not a valid image.', 415, 'invalid_image');
        }

        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = TEMP_DIR . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            json_error('Could not store uploaded image.', 500, 'storage_failed');
        }

        json_out([
            'ok'       => true,
            'filename' => $safeName,
            'url'      => 'config.php?action=get_reference&file=' . urlencode($safeName),
            'sizeKb'   => round(filesize($destPath) / 1024, 1),
            'mimeType' => $mimeType,
        ]);
        break;

    // -------------------------------------------------------------------
    // GET /config.php?action=get_reference&file=xxx
    // Serves back a previously uploaded reference image.
    // -------------------------------------------------------------------
    case 'get_reference':
        $file = basename((string)($_GET['file'] ?? ''));
        $path = TEMP_DIR . '/' . $file;

        if ($file === '' || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $file) || !is_file($path)) {
            json_error('Reference image not found.', 404, 'not_found');
        }

        $mime = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'jpg'   => 'image/jpeg',
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            default => 'application/octet-stream',
        };

        header('Content-Type: ' . $mime);
        header('Cache-Control: private, max-age=3600');
        readfile($path);
        exit;

    // -------------------------------------------------------------------
    // POST /config.php?action=remove_reference
    // -------------------------------------------------------------------
    case 'remove_reference':
        $file = basename((string)($_POST['file'] ?? ''));
        $path = TEMP_DIR . '/' . $file;
        if ($file !== '' && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $file) && is_file($path)) {
            @unlink($path);
        }
        json_out(['ok' => true]);
        break;

    default:
        json_error('Unknown action.', 404, 'unknown_action');
}
