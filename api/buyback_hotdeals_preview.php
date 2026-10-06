<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/crm_email_assertion.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!can_use_buyback()) { echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

// Release the session file lock before the long CRM call below (now up to
// 180s for a large book) -- PHP's default session handler otherwise holds
// an exclusive lock for the whole request, which would freeze any other
// tab/request from this same agent until this one finishes. Nothing past
// this point reads or writes $_SESSION.
session_write_close();

$body = json_decode(file_get_contents('php://input'), true) ?: [];

$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$qs    = http_build_query(['token' => $token, 'email' => crm_signed_email($agent['email'])]);
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
    // The CRM side now sweeps FUB events scoped to this agent's own leads,
    // sequentially paced to respect FUB's 10-req/10s rate limit on that
    // resource -- a large book (confirmed live: ~1,300 leads took 30s, one
    // agent's book took 93s) can legitimately take well over the old 45s
    // budget. 180s covers a very large book with margin.
    CURLOPT_TIMEOUT        => 180,
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
