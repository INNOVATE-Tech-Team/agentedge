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
$personId = isset($body['person_id']) ? (int)$body['person_id'] : 0;
if ($personId <= 0) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'person_id is required']); exit; }

$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$qs    = http_build_query(['token' => $token, 'email' => $agent['email']]);
$url   = $base . '/public/agentedge/buyback/automate/generate?' . $qs;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode(['person_id' => $personId]),
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
