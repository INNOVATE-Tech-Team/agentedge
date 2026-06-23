<?php
// Proxy to the intranet events API. Auth-gated — must be a signed-in agent.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$dept  = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_GET['dept'] ?? '')));

$c     = cfg();
$url   = rtrim($c['intranet_events_url'] ?? '', '/');
$token = $c['intranet_events_token'] ?? '';

if (!$url || !$token) {
    echo json_encode(['events' => []]);
    exit;
}

$qs  = 'month=' . urlencode($month);
if ($dept !== '') $qs .= '&dept=' . urlencode($dept);

$ctx = stream_context_create(['http' => [
    'method'        => 'GET',
    'timeout'       => 10,
    'header'        => "Authorization: Bearer $token\r\nAccept: application/json\r\n",
    'ignore_errors' => true,
]]);
$raw = @file_get_contents("$url?$qs", false, $ctx);
$d   = $raw !== false ? json_decode($raw, true) : null;

echo json_encode(['events' => is_array($d['events'] ?? null) ? $d['events'] : []]);
