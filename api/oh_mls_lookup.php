<?php
// Open House Portal — MLS lookup via Trestle API.
// GET ?mls=XXXXX  → JSON with property details or {"error":"..."}
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$mls = trim($_GET['mls'] ?? '');
if ($mls === '') {
    echo json_encode(['error' => 'Missing mls parameter']);
    exit;
}

$c       = cfg();
$apiKey  = $c['trestle_api_key'] ?? '';
if ($apiKey === '') {
    echo json_encode(['error' => 'not configured']);
    exit;
}

$filter  = "ListingId eq '" . addslashes($mls) . "'";
$select  = 'ListingId,UnparsedAddress,City,StateOrProvince,PostalCode,PropertyType,ListPrice,ListAgentEmail,ListAgentFullName,Media';
$url     = 'https://api.trestle.corelogic.com/platform/odata/v2/Property'
         . '?$filter=' . rawurlencode($filter)
         . '&$select=' . rawurlencode($select);

$ctx = stream_context_create([
    'http' => [
        'method'         => 'GET',
        'timeout'        => 15,
        'header'         => "Authorization: Bearer {$apiKey}\r\nAccept: application/json\r\n",
        'ignore_errors'  => true,
    ],
]);

$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) {
    echo json_encode(['error' => 'API request failed']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['error' => 'Invalid API response']);
    exit;
}

$values = $data['value'] ?? [];
if (empty($values)) {
    echo json_encode(['error' => 'not found']);
    exit;
}

$p = $values[0];

// Grab first media image
$imageUrl = '';
$media = $p['Media'] ?? [];
if (is_array($media) && !empty($media)) {
    $imageUrl = $media[0]['MediaURL'] ?? '';
}

// Map PropertyType to our dropdown values
$rawType = $p['PropertyType'] ?? '';
$typeMap  = [
    'Residential' => 'Residential',
    'Condo'       => 'Condo',
    'Condominium' => 'Condo',
    'Townhouse'   => 'Townhouse',
    'Land'        => 'Land',
    'Commercial'  => 'Commercial',
];
$propType = $typeMap[$rawType] ?? 'Residential';

echo json_encode([
    'ok'                   => true,
    'address'              => $p['UnparsedAddress']  ?? '',
    'city'                 => $p['City']             ?? '',
    'state'                => $p['StateOrProvince']  ?? '',
    'zip'                  => $p['PostalCode']        ?? '',
    'property_type'        => $propType,
    'list_price'           => (int)($p['ListPrice']  ?? 0),
    'listing_agent_email'  => $p['ListAgentEmail']   ?? '',
    'listing_agent_name'   => $p['ListAgentFullName'] ?? '',
    'image_url'            => $imageUrl,
]);
