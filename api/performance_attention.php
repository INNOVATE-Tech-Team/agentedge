<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/performance_client.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

// Phase 2A activity-only pilot. Reasons are limited to 'inactive' and
// 'below_goal' on activity metrics -- stuck-deal reasons never appear
// (Advantage's endpoint has no fub_deals dependency at all here).
$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }

$result = performance_api_get('needs-attention', $agent['email']);
if (!$result['inPilot']) {
    echo json_encode(['ok' => true, 'inPilot' => false]);
    exit;
}
if (!$result['ok']) {
    http_response_code($result['status'] ?: 502);
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}
echo json_encode(['ok' => true, 'inPilot' => true] + $result['data']);
