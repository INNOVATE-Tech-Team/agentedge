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
$address = trim($body['address'] ?? '');
if ($address === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'address is required']); exit; }
$radiusTierIndex = isset($body['radius_tier_index']) && $body['radius_tier_index'] !== null ? (int)$body['radius_tier_index'] : null;

$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$qs    = http_build_query(['token' => $token, 'email' => $agent['email']]);
$url   = $base . '/public/agentedge/buyback/prep-packet?' . $qs;

$payload = json_encode(['address' => $address, 'radius_tier_index' => $radiusTierIndex]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    // Real Claude-generated packets measured at ~40-45s server-side even
    // with a comp match (confirmed live 2026-08-09) — 45s left ~0 margin
    // for the extra Caddy/network hop, so real requests were timing out
    // even when the server-side call succeeded shortly after.
    CURLOPT_TIMEOUT        => 90,
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
