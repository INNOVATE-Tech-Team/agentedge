<?php
// Google Search Console service-account auth + Search Analytics query helper.
// Same no-Composer openssl+file_get_contents pattern as lib/google_calendar.php.

function gsc_access_token(string $key_file): ?string {
    static $cache = [];
    if (!empty($cache['token']) && $cache['expires'] > time() + 60) {
        return $cache['token'];
    }

    if (!file_exists($key_file)) return null;
    $key = json_decode(file_get_contents($key_file), true);
    if (!isset($key['private_key'], $key['client_email'])) return null;

    $now     = time();
    $header  = _gsc_b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = _gsc_b64u(json_encode([
        'iss'   => $key['client_email'],
        'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $sig_input = "$header.$payload";
    $pkey = openssl_pkey_get_private($key['private_key']);
    if (!$pkey) return null;
    openssl_sign($sig_input, $sig, $pkey, 'SHA256');
    $jwt = $sig_input . '.' . _gsc_b64u($sig);

    $resp = @file_get_contents('https://oauth2.googleapis.com/token', false,
        stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            'ignore_errors' => true,
        ]])
    );
    if (!$resp) return null;
    $d = json_decode($resp, true);
    if (empty($d['access_token'])) return null;

    $cache = ['token' => $d['access_token'], 'expires' => $now + (int)($d['expires_in'] ?? 3600)];
    return $cache['token'];
}

// Returns ['rows' => [['query'=>..,'clicks'=>..,'impressions'=>..,'ctr'=>..,'position'=>..], ...]]
// or ['error' => 'message'] on failure — caller decides how to render either shape.
function gsc_top_queries(string $site_url, string $token, string $start_date, string $end_date, int $limit = 10): array {
    $url = 'https://www.googleapis.com/webmasters/v3/sites/' . urlencode($site_url) . '/searchAnalytics/query';
    $resp = @file_get_contents($url, false, stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer $token\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
        'content'       => json_encode([
            'startDate'  => $start_date,
            'endDate'    => $end_date,
            'dimensions' => ['query'],
            'rowLimit'   => $limit,
        ]),
        'ignore_errors' => true,
    ]]));
    if (!$resp) return ['error' => 'No response from Search Console API (network/auth failure).'];
    $d = json_decode($resp, true);
    if (isset($d['error'])) {
        return ['error' => $d['error']['message'] ?? 'Search Console API returned an error.'];
    }
    $rows = [];
    foreach (($d['rows'] ?? []) as $r) {
        $rows[] = [
            'query'       => $r['keys'][0] ?? '',
            'clicks'      => (int)($r['clicks'] ?? 0),
            'impressions' => (int)($r['impressions'] ?? 0),
            'ctr'         => (float)($r['ctr'] ?? 0),
            'position'    => (float)($r['position'] ?? 0),
        ];
    }
    return ['rows' => $rows];
}

function _gsc_b64u(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// File-cached wrapper — Search Console data itself only refreshes every 1-3 days,
// so there's no reason to re-authenticate/query on every dashboard page load.
function gsc_top_queries_cached(string $key_file, string $site_url, string $start_date, string $end_date, int $limit, string $cache_dir, int $ttl_seconds = 21600): array {
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
    $cacheFile = rtrim($cache_dir, '/') . '/gsc_' . md5($site_url . $start_date . $end_date . $limit) . '.json';

    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        $cachedTtl = $cached['ttl'] ?? $ttl_seconds;
        if (is_array($cached) && ($cached['fetched_at'] ?? 0) > time() - $cachedTtl) {
            return $cached['result'];
        }
    }

    $token = gsc_access_token($key_file);
    if (!$token) {
        $result = ['error' => 'Could not authenticate with Google (check the service account key + Search Console access grant).'];
    } else {
        $result = gsc_top_queries($site_url, $token, $start_date, $end_date, $limit);
    }

    // Cache errors too, but briefly (5 min), so a misconfiguration doesn't hammer the API on every page load.
    $ttlToUse = isset($result['error']) ? 300 : $ttl_seconds;
    @file_put_contents($cacheFile, json_encode(['fetched_at' => time(), 'ttl' => $ttlToUse, 'result' => $result]));
    return $result;
}
