<?php
// DotLoop API helper library.
// All DotLoop HTTP calls flow through here. Pages and API endpoints must never
// call file_get_contents / curl against DotLoop directly.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';

const DOTLOOP_API_BASE  = 'https://api-gateway.dotloop.com/public/v2';
const DOTLOOP_TOKEN_URL = 'https://auth.dotloop.com/oauth/token';

// ── Token storage ─────────────────────────────────────────────────────────────

function dotloop_get_tokens(string $email): ?array {
    $s = local_db()->prepare(
        "SELECT agent_email, profile_id, access_token, refresh_token, expires_at
         FROM dotloop_tokens WHERE agent_email = ?"
    );
    $s->execute([$email]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function dotloop_save_tokens(string $email, array $data): void {
    // $data must contain: access_token, refresh_token, expires_in (or expires_at), profile_id
    $expiresAt = isset($data['expires_at'])
        ? (int)$data['expires_at']
        : time() + (int)($data['expires_in'] ?? 3600);

    local_db()->prepare(
        "INSERT OR REPLACE INTO dotloop_tokens
             (agent_email, profile_id, access_token, refresh_token, expires_at)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([
        $email,
        $data['profile_id']    ?? null,
        $data['access_token']  ?? null,
        $data['refresh_token'] ?? null,
        $expiresAt,
    ]);
}

// ── Token refresh ─────────────────────────────────────────────────────────────

/**
 * Use the stored refresh_token to obtain a new access_token.
 * Saves the updated tokens and returns the new access_token, or null on failure.
 */
function dotloop_refresh_token(string $email): ?string {
    $row = dotloop_get_tokens($email);
    if (!$row || empty($row['refresh_token'])) return null;

    $c      = cfg();
    $clientId     = $c['dotloop_client_id']     ?? '';
    $clientSecret = $c['dotloop_client_secret'] ?? '';
    if ($clientId === '' || $clientSecret === '') return null;

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 15,
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content'       => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $row['refresh_token'],
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]),
        'ignore_errors' => true,
    ]]);

    $raw = @file_get_contents(DOTLOOP_TOKEN_URL, false, $ctx);
    if ($raw === false) return null;

    $d = json_decode($raw, true);
    if (empty($d['access_token'])) return null;

    // Preserve profile_id from existing row; refresh_token may or may not be rotated
    $d['profile_id']    = $row['profile_id'];
    $d['refresh_token'] = $d['refresh_token'] ?? $row['refresh_token'];
    dotloop_save_tokens($email, $d);

    return $d['access_token'];
}

/**
 * Return a valid access_token for $email.
 * Auto-refreshes if the stored token expires within 60 seconds.
 * Returns null if the agent is not connected or the refresh fails.
 */
function dotloop_token(string $email): ?string {
    $row = dotloop_get_tokens($email);
    if (!$row || empty($row['access_token'])) return null;

    if ((int)$row['expires_at'] > time() + 60) {
        return $row['access_token'];
    }

    return dotloop_refresh_token($email);
}

// ── Is connected? ─────────────────────────────────────────────────────────────

function dotloop_is_connected(string $email): bool {
    $row = dotloop_get_tokens($email);
    return $row !== null && !empty($row['access_token']);
}

// ── Generic API call ──────────────────────────────────────────────────────────

/**
 * Make an authenticated request to the DotLoop API.
 *
 * @param string      $email  Agent's email (used to look up / refresh token)
 * @param string      $method GET | POST | PATCH
 * @param string      $path   e.g. '/profile/me'  (no base URL)
 * @param array|null  $body   JSON-encoded body for POST/PATCH
 *
 * @return array  ['ok' => true, 'data' => mixed]
 *             or ['ok' => false, 'error' => string, 'status' => int]
 */
function dotloop_api(string $email, string $method, string $path, ?array $body = null): array {
    $token = dotloop_token($email);
    if ($token === null) {
        return ['ok' => false, 'error' => 'Not connected to DotLoop', 'status' => 401];
    }

    $result = _dotloop_request($token, $method, $path, $body);

    // On 401, try a single token refresh and retry once
    if (!$result['ok'] && ($result['status'] ?? 0) === 401) {
        $token = dotloop_refresh_token($email);
        if ($token === null) {
            return ['ok' => false, 'error' => 'DotLoop token expired — please reconnect', 'status' => 401];
        }
        $result = _dotloop_request($token, $method, $path, $body);
    }

    return $result;
}

/** Internal: execute one HTTP call to the DotLoop API. */
function _dotloop_request(string $token, string $method, string $path, ?array $body): array {
    $url     = DOTLOOP_API_BASE . $path;
    $headers = "Authorization: Bearer {$token}\r\nAccept: application/json\r\n";

    $opts = [
        'method'        => strtoupper($method),
        'timeout'       => 20,
        'header'        => $headers,
        'ignore_errors' => true,
    ];

    if ($body !== null) {
        $opts['header'] .= "Content-Type: application/json\r\n";
        $opts['content'] = json_encode($body);
    }

    $ctx = stream_context_create(['http' => $opts]);
    $raw = @file_get_contents($url, false, $ctx);

    // Parse HTTP status from $http_response_header
    $status = 200;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int)$m[1];
            }
        }
    }

    if ($raw === false) {
        return ['ok' => false, 'error' => 'API request failed (network error)', 'status' => 0];
    }

    if ($status >= 400) {
        $errBody = json_decode($raw, true);
        $errMsg  = $errBody['message'] ?? $errBody['error'] ?? "HTTP {$status}";
        return ['ok' => false, 'error' => $errMsg, 'status' => $status];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'Invalid JSON response from DotLoop', 'status' => $status];
    }

    return ['ok' => true, 'data' => $data];
}

// ── Folder helpers ────────────────────────────────────────────────────────────

/**
 * Return all folders for a loop.
 * ['ok' => true, 'data' => [['id'=>..,'name'=>..], ...]]
 */
function dotloop_get_folders(string $email, string $profileId, string $loopId): array {
    $result = dotloop_api($email, 'GET', "/profile/{$profileId}/loop/{$loopId}/folder");
    if (!$result['ok']) return $result;
    $folders = $result['data']['data'] ?? [];
    return ['ok' => true, 'data' => $folders];
}

/**
 * Pick the best folder id for a document type ('hud' or 'check').
 * Falls back to the first available folder if no keyword match.
 */
function dotloop_pick_folder(array $folders, string $type): ?string {
    if (empty($folders)) return null;
    $keywords = $type === 'hud'
        ? ['settlement', 'hud', 'closing', 'document']
        : ['earnest', 'check', 'deposit', 'document'];
    foreach ($keywords as $kw) {
        foreach ($folders as $f) {
            if (stripos($f['name'] ?? '', $kw) !== false) return (string)$f['id'];
        }
    }
    return (string)($folders[0]['id'] ?? '');
}

/**
 * Upload a file to a DotLoop loop folder.
 * $filePath   — absolute path to the temp/stored file
 * $fileName   — original filename (shown in DotLoop)
 * $mimeType   — e.g. 'application/pdf', 'image/jpeg'
 *
 * Returns ['ok' => true, 'data' => [...]] or ['ok' => false, 'error' => ...]
 */
function dotloop_upload_document(
    string $email,
    string $profileId,
    string $loopId,
    string $folderId,
    string $filePath,
    string $fileName,
    string $mimeType
): array {
    $token = dotloop_token($email);
    if ($token === null) {
        return ['ok' => false, 'error' => 'Not connected to DotLoop', 'status' => 401];
    }

    $fileData = @file_get_contents($filePath);
    if ($fileData === false) {
        return ['ok' => false, 'error' => 'Could not read file for upload', 'status' => 0];
    }

    $boundary = '----AgentEdgeBoundary' . bin2hex(random_bytes(8));
    $body  = "--{$boundary}\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"" . addslashes($fileName) . "\"\r\n";
    $body .= "Content-Type: {$mimeType}\r\n\r\n";
    $body .= $fileData;
    $body .= "\r\n--{$boundary}--\r\n";

    $url = DOTLOOP_API_BASE . "/profile/{$profileId}/loop/{$loopId}/folder/{$folderId}/document";
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 60,
        'header'        => "Authorization: Bearer {$token}\r\n"
                         . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
                         . "Accept: application/json\r\n",
        'content'       => $body,
        'ignore_errors' => true,
    ]]);

    $raw    = @file_get_contents($url, false, $ctx);
    $status = 200;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
        }
    }

    // On 401, refresh and retry once
    if ($status === 401) {
        $token = dotloop_refresh_token($email);
        if ($token === null) {
            return ['ok' => false, 'error' => 'DotLoop token expired — please reconnect', 'status' => 401];
        }
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 60,
            'header'        => "Authorization: Bearer {$token}\r\n"
                             . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
                             . "Accept: application/json\r\n",
            'content'       => $body,
            'ignore_errors' => true,
        ]]);
        $raw    = @file_get_contents($url, false, $ctx);
        $status = 200;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
            }
        }
    }

    if ($raw === false) return ['ok' => false, 'error' => 'Upload request failed (network error)', 'status' => 0];

    if ($status >= 400) {
        $errBody = json_decode($raw, true);
        $errMsg  = $errBody['message'] ?? $errBody['error'] ?? "HTTP {$status}";
        return ['ok' => false, 'error' => $errMsg, 'status' => $status];
    }

    $data = json_decode($raw, true);
    return ['ok' => true, 'data' => is_array($data) ? $data : []];
}
