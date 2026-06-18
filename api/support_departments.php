<?php
// Proxy: returns the list of intranet support departments so AgentEdge can
// populate the "Get Support" modal's department picker.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$c     = cfg();
$base  = rtrim($c['intranet_ticket_url'] ?? '', '/');
$token = $c['intranet_ticket_token'] ?? '';

if (!$base || !$token) {
    echo json_encode(['departments' => []]);
    exit;
}

$ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'timeout'       => 8,
    'header'        => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
    'ignore_errors' => true,
]]);
$raw = @file_get_contents("$base/api/tickets/departments", false, $ctx);
$d   = $raw !== false ? json_decode($raw, true) : null;

echo json_encode(['departments' => is_array($d['departments'] ?? null) ? $d['departments'] : []]);
