<?php
// Temporary diagnostic endpoint — remove after debugging is complete.
//
// Usage (as yourself):
//   https://agentedge.innovateonline.com/api/dotloop_debug.php
//
// Usage (to inspect any agent, as admin):
//   https://agentedge.innovateonline.com/api/dotloop_debug.php?staffid=42&secret=YOUR_DEBUG_SECRET
//
// Set debug_secret in config.php to enable the staffid override.
//
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$viewer = current_agent();
if (!$viewer) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$target_staffid = (int)$viewer['id'];

// Allow checking another agent if a matching debug_secret is passed.
if (isset($_GET['staffid'])) {
    $c = cfg();
    $secret = $c['debug_secret'] ?? null;
    if (!$secret || ($_GET['secret'] ?? '') !== $secret) {
        http_response_code(403);
        echo json_encode(['error' => 'staffid override requires ?secret= matching debug_secret in config.php']);
        exit;
    }
    $target_staffid = (int)$_GET['staffid'];
}

$row = db_one(
    "SELECT access_token, refresh_token, expires_at, dotloop_profile_id
     FROM agentedge_dotloop_tokens WHERE staffid = ?",
    [(string)$target_staffid]
);
if (!$row) {
    echo json_encode(['error' => 'no token stored for this staffid — agent has not connected dotloop yet', 'staffid' => $target_staffid]);
    exit;
}

$token = $row['access_token'];
$out   = [
    'staffid'            => $target_staffid,
    'stored_profile_id'  => $row['dotloop_profile_id'],
    'token_expires_at'   => $row['expires_at'],
    'token_expires_in_s' => $row['expires_at'] ? ((int)$row['expires_at'] - time()) : null,
    'steps'              => [],
];

$api = 'https://api-gateway.dotloop.com/public/v2';

// Step 1: GET /v2/me — confirms the token works and reveals profile IDs.
[$body, $http] = dl_get("$api/me", $token);
$out['steps'][] = [
    'label'       => 'GET /v2/me',
    'http_status' => $http,
    'parsed'      => json_decode($body, true),
];
$me = json_decode($body, true);

// Step 2: GET /v2/me/loop — fallback endpoint (may return 0 results for partner tokens).
[$body2, $http2] = dl_get(
    "$api/me/loop?" . http_build_query([
        'sort' => 'updated', 'direction' => 'desc', 'limit' => 5, 'page' => 1,
    ]),
    $token
);
$out['steps'][] = [
    'label'       => 'GET /v2/me/loop',
    'http_status' => $http2,
    'parsed'      => json_decode($body2, true),
];

// Step 3: try /v2/profile/{id}/loop for each profile returned by /v2/me.
$profiles = $me['profiles'] ?? ($me['data']['profiles'] ?? []);
if (!empty($profiles)) {
    foreach (array_slice($profiles, 0, 3) as $idx => $profile) {
        $pid = $profile['id'] ?? null;
        if (!$pid) continue;
        [$body3, $http3] = dl_get(
            "$api/profile/" . (int)$pid . '/loop?' . http_build_query([
                'sort' => 'updated', 'direction' => 'desc', 'limit' => 5, 'page' => 1,
            ]),
            $token
        );
        $out['steps'][] = [
            'label'       => "GET /v2/profile/{$pid}/loop (profile index {$idx})",
            'profile_id'  => $pid,
            'http_status' => $http3,
            'parsed'      => json_decode($body3, true),
        ];
    }
} else {
    $out['steps'][] = [
        'label'  => '/v2/profile/{id}/loop — skipped',
        'reason' => '/v2/me returned no profiles array; see step 1 parsed output',
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// ---------------------------------------------------------------------------

function dl_get(string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$body, $http];
}
