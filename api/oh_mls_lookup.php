<?php
// Open House Portal — MLS lookup via Trestle API (OAuth2 client credentials).
// GET ?mls=XXXXX  → JSON with property details or {"error":"..."}
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit; }

$mls = trim($_GET['mls'] ?? '');
if ($mls === '') { echo json_encode(['error' => 'Missing mls parameter']); exit; }

$c        = cfg();
$clientId = $c['trestle_client_id']     ?? '';
$secret   = $c['trestle_client_secret'] ?? '';
if ($clientId === '' || $secret === '') {
    echo json_encode(['error' => 'not configured']);
    exit;
}

// ── Get / refresh OAuth2 access token ────────────────────────────────────────
function trestle_token(string $clientId, string $secret): string {
    $db      = local_db();
    $now     = time();

    // Check cached token (valid for at least 60 more seconds)
    $tokRow  = $db->query("SELECT value FROM oh_prefs WHERE key='trestle_token'")->fetch(PDO::FETCH_ASSOC);
    $expRow  = $db->query("SELECT value FROM oh_prefs WHERE key='trestle_token_expires'")->fetch(PDO::FETCH_ASSOC);
    if ($tokRow && $expRow && (int)$expRow['value'] > $now + 60) {
        return $tokRow['value'];
    }

    // Fetch new token
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 12,
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content'       => http_build_query([
            'client_id'     => $clientId,
            'client_secret' => $secret,
            'grant_type'    => 'client_credentials',
            'scope'         => 'api',
        ]),
        'ignore_errors' => true,
    ]]);

    $raw = @file_get_contents('https://api.cotality.com/trestle/oidc/connect/token', false, $ctx);
    if ($raw === false) return '';

    $d = json_decode($raw, true);
    $token   = $d['access_token'] ?? '';
    $expires = $now + (int)($d['expires_in'] ?? 3600);

    if ($token) {
        $db->prepare("INSERT OR REPLACE INTO oh_prefs (key,value) VALUES ('trestle_token',?)")->execute([$token]);
        $db->prepare("INSERT OR REPLACE INTO oh_prefs (key,value) VALUES ('trestle_token_expires',?)")->execute([$expires]);
    }

    return $token;
}

$token = trestle_token($clientId, $secret);
if ($token === '') {
    echo json_encode(['error' => 'Could not authenticate with Trestle — check your client credentials.']);
    exit;
}

// ── Query Trestle for the listing ─────────────────────────────────────────────
// Search by ListingId (the public MLS number) across all entitled feeds.
$filter = '$filter=' . rawurlencode("ListingId eq '" . str_replace("'", "''", $mls) . "'");
$select = '$select=' . rawurlencode('ListingId,UnparsedAddress,City,StateOrProvince,PostalCode,PropertyType,ListPrice,ListAgentEmail,ListAgentFullName,Media');
$url    = "https://api.cotality.com/trestle/odata/Property?{$filter}&{$select}&\$top=1";

$ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'timeout'       => 15,
    'header'        => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
    'ignore_errors' => true,
]]);

$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) { echo json_encode(['error' => 'API request failed']); exit; }

$data = json_decode($raw, true);
if (!is_array($data)) { echo json_encode(['error' => 'Invalid API response']); exit; }

$values = $data['value'] ?? [];
if (empty($values)) { echo json_encode(['error' => 'not found']); exit; }

$p = $values[0];

// First media image
$imageUrl = '';
$media = $p['Media'] ?? [];
if (is_array($media) && !empty($media)) {
    $imageUrl = $media[0]['MediaURL'] ?? '';
}

// Normalize property type to our dropdown values
$typeMap = [
    'Residential'  => 'Residential',
    'Condo'        => 'Condo',
    'Condominium'  => 'Condo',
    'Townhouse'    => 'Townhouse',
    'Land'         => 'Land',
    'Commercial'   => 'Commercial',
];
$propType = $typeMap[$p['PropertyType'] ?? ''] ?? 'Residential';

echo json_encode([
    'ok'                  => true,
    'address'             => $p['UnparsedAddress']   ?? '',
    'city'                => $p['City']              ?? '',
    'state'               => $p['StateOrProvince']   ?? '',
    'zip'                 => $p['PostalCode']         ?? '',
    'property_type'       => $propType,
    'list_price'          => (int)($p['ListPrice']   ?? 0),
    'listing_agent_email' => $p['ListAgentEmail']    ?? '',
    'listing_agent_name'  => $p['ListAgentFullName'] ?? '',
    'image_url'           => $imageUrl,
]);
