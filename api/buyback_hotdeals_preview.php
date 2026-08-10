<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!can_use_buyback()) { echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?: [];

$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$qs    = http_build_query(['token' => $token, 'email' => $agent['email']]);
$url   = $base . '/public/agentedge/buyback/hotdeals/preview?' . $qs;

// Same field coercion as the admin Hot Deals preview proxy (api/hot_deals_preview.php)
// — the CRM side (Pydantic) expects real ints/null, not empty strings.
$payload = json_encode([
    'city'              => trim($body['city'] ?? ''),
    'property_sub_type' => trim($body['property_sub_type'] ?? '') ?: 'Condominium',
    'min_beds'          => isset($body['min_beds'])  && $body['min_beds']  !== '' ? (int)$body['min_beds']  : null,
    'max_beds'          => isset($body['max_beds'])  && $body['max_beds']  !== '' ? (int)$body['max_beds']  : null,
    'min_baths'         => isset($body['min_baths']) && $body['min_baths'] !== '' ? (int)$body['min_baths'] : null,
    'max_baths'         => isset($body['max_baths']) && $body['max_baths'] !== '' ? (int)$body['max_baths'] : null,
    'min_price'         => isset($body['min_price']) && $body['min_price'] !== '' ? (int)$body['min_price'] : null,
    'max_price'         => isset($body['max_price']) && $body['max_price'] !== '' ? (int)$body['max_price'] : null,
    'frontage'          => (!empty($body['frontage'])) ? $body['frontage'] : null,
    'limit'             => isset($body['limit']) ? (int)$body['limit'] : 5,
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 45,
]);
$resp   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Could not reach the CRM: ' . $err]);
    exit;
}
http_response_code($status ?: 502);
echo $resp;
