<?php
ob_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/performance_client.php';
ini_set('display_errors', '0');
ob_clean();
header('Content-Type: application/json');

// Phase 2A activity-only pilot -- dials/connects/texts/emails only, no
// deal-based ranking. Advantage resolves the caller's own pilot team and
// returns that team's roster; this endpoint never accepts a team id from
// the client.
$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }

$period = ($_GET['period'] ?? 'week') === 'month' ? 'month' : 'week';

$result = performance_api_get('leaderboard', $agent['email'], ['period' => $period]);
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
