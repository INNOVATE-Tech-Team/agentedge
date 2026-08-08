<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/agent_profile.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }
if (!can_send_hot_deals()) { echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit; }

$body       = json_decode(file_get_contents('php://input'), true) ?: [];
$listingKey = trim($body['listing_key'] ?? '');
$personIds  = $body['person_ids'] ?? [];
// The CRM side already refuses an empty/unapproved list — this check just
// avoids a wasted round-trip for the obvious case.
if ($listingKey === '' || !is_array($personIds) || empty($personIds)) {
    echo json_encode(['ok' => false, 'error' => 'listing_key and person_ids are required']);
    exit;
}

$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$url   = $base . '/admin/hot-deal-alerts/send' . ($token ? '?token=' . urlencode($token) : '');

$rationale = trim($body['rationale'] ?? '');

// Signature block on the CRM side needs more than current_agent() carries
// in session (just id/email/name/photo) — phone lives in the richer
// agent_intake profile, not every agent has filled it in, so this is
// best-effort and the email falls back to a generic signature if empty.
$profile = load_agent_profile($agent['email'] ?? '') ?: [];
$sender  = [
    'name'  => $agent['name']  ?? '',
    'email' => $agent['email'] ?? '',
    'phone' => $profile['phone'] ?? '',
];

$payload = json_encode([
    'listing_key' => $listingKey,
    'person_ids'  => array_values(array_map('intval', $personIds)),
    'rationale'   => $rationale !== '' ? $rationale : null,
    'sender'      => $sender,
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 60,
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
