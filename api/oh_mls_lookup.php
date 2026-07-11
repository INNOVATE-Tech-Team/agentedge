<?php
// Open House Portal — MLS lookup via Trestle API (OAuth2 client credentials).
// GET ?mls=XXXXX  → JSON with property details or {"error":"..."}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/trestle.php';

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


$token = trestle_token($clientId, $secret);
if ($token === '') {
    echo json_encode(['error' => 'Could not authenticate with Trestle — check your client credentials.']);
    exit;
}

// ── Query Trestle for the listing ─────────────────────────────────────────────
// Search by ListingId (the public MLS number) across all entitled feeds.
$filter = '$filter=' . rawurlencode("ListingId eq '" . str_replace("'", "''", $mls) . "'");
$select = '$select=' . rawurlencode('ListingId,UnparsedAddress,City,StateOrProvince,PostalCode,PropertyType,ListPrice,ListAgentEmail,ListAgentFullName');
$expand = '$expand=Media';
$url    = "https://api.cotality.com/trestle/odata/Property?{$filter}&{$select}&{$expand}&\$top=1";

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

$imageUrl = trestle_first_photo($p['Media'] ?? []);
$propType = trestle_normalize_type($p['PropertyType'] ?? '');

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
