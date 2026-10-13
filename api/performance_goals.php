<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/performance_client.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

// Phase 2A activity-only pilot. Advantage already restricts goals to
// dials/connects/texts_sent/emails_sent only -- a leader's goal on an
// appointments/signed/closed metric is never returned here at all.
$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }

$result = performance_api_get('goals', $agent['email']);
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
