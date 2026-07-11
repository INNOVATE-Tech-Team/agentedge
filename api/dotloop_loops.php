<?php
// Returns the signed-in agent's dotloop loops as JSON.
// If not yet connected, returns { connected: false }.
// Automatically refreshes the access token when it's near expiry.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$row = db_one(
    "SELECT access_token, refresh_token, expires_at, dotloop_profile_id FROM agentedge_dotloop_tokens WHERE staffid = ?",
    [(string)$agent['id']]
);

if (!$row) {
    echo json_encode(['connected' => false]);
    exit;
}

$token = $row['access_token'];

// Refresh if within 60 seconds of expiry.
if ($row['expires_at'] && (int)$row['expires_at'] < time() + 60) {
    $token = dotloop_refresh($row['refresh_token'], (int)$agent['id']);
    if (!$token) {
        echo json_encode(['connected' => false, 'expired' => true]);
        exit;
    }
}

$profile_id = $row['dotloop_profile_id'] ?? null;

$loops = dotloop_fetch_all($token, $profile_id);

// If we got a 401, try one refresh and retry.
if ($loops === null) {
    $token = dotloop_refresh($row['refresh_token'], (int)$agent['id']);
    if (!$token) {
        echo json_encode(['connected' => false, 'expired' => true]);
        exit;
    }
    $loops = dotloop_fetch_all($token, $profile_id);
}

echo json_encode([
    'connected'  => true,
    'profile_id' => $profile_id,
    'loops'      => $loops ?? [],
]);

// ---------------------------------------------------------------------------

function dotloop_fetch_all(string $token, ?string $profile_id): ?array {
    $all    = [];
    $page   = 1;
    $limit  = 50;

    // Use the profile-scoped endpoint when we have a profile_id; it returns
    // that agent's transactions reliably. Fall back to /me/loop only if
    // profile_id was never stored (shouldn't happen after re-connecting).
    $base = $profile_id
        ? "https://api-gateway.dotloop.com/public/v2/profile/{$profile_id}/loop"
        : 'https://api-gateway.dotloop.com/public/v2/me/loop';

    do {
        $url = $base . '?' . http_build_query([
            'sort'      => 'updated',
            'direction' => 'desc',
            'limit'     => $limit,
            'page'      => $page,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
        ]);
        $resp     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401) return null;
        if ($httpCode !== 200) break;

        $data = json_decode($resp, true);
        $rows = $data['data'] ?? [];
        foreach ($rows as $l) {
            $all[] = normalize_loop($l);
        }

        $total   = (int)($data['total_records'] ?? 0);
        $fetched = $page * $limit;
        $page++;
    } while ($fetched < $total && count($rows) === $limit);

    return $all;
}

function normalize_loop(array $l): array {
    // Build a readable address from available fields.
    $parts = array_filter([
        $l['street_name'] ?? ($l['name'] ?? ''),
        $l['city']        ?? '',
        $l['state']       ?? '',
    ]);
    $address = implode(', ', $parts) ?: ($l['name'] ?? '');

    $price = $l['overview']['purchase_price']
          ?? $l['overview']['list_price']
          ?? $l['purchase_price']
          ?? null;

    return [
        'id'           => $l['id'],
        'name'         => $l['name'] ?? '',
        'address'      => $address,
        'status'       => strtoupper($l['status'] ?? 'ACTIVE'),
        'closing_date' => $l['closing_date'] ?? null,
        'price'        => $price ? (float)$price : null,
    ];
}

function dotloop_refresh(string $refresh_token, int $staffid): ?string {
    $c  = cfg();
    $ch = curl_init('https://auth.dotloop.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $c['dotloop_client_id'],
            'client_secret' => $c['dotloop_client_secret'],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $tok = json_decode($resp, true);
    if (empty($tok['access_token'])) return null;

    $expires_at    = isset($tok['expires_in']) ? (string)(time() + (int)$tok['expires_in']) : null;
    $refresh_token = $tok['refresh_token'] ?? $refresh_token;

    db_exec(
        "UPDATE agentedge_dotloop_tokens
         SET access_token = ?, refresh_token = ?, expires_at = ?, updated_at = NOW()
         WHERE staffid = ?",
        [$tok['access_token'], $refresh_token, $expires_at, (string)$staffid]
    );

    return $tok['access_token'];
}
