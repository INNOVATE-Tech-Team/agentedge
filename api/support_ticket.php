<?php
// Proxy: creates a support ticket in the intranet ticket system on behalf of
// the signed-in agent. POST body: { title, departmentSlug, body }
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'POST only']); exit;
}

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];

$title    = trim($in['title']          ?? '');
$bodyText = trim($in['body']           ?? '');
$deptSlug = trim($in['departmentSlug'] ?? '');

if (!$title || !$bodyText || !$deptSlug) {
    http_response_code(400);
    echo json_encode(['error' => 'title, body, and departmentSlug are required']);
    exit;
}

$c     = cfg();
$base  = rtrim($c['intranet_ticket_url'] ?? '', '/');
$token = $c['intranet_ticket_token'] ?? '';

if (!$base || !$token) {
    http_response_code(503);
    echo json_encode(['error' => 'Support tickets are not configured yet.']);
    exit;
}

$payload = json_encode([
    'title'          => $title,
    'body'           => $bodyText,
    'departmentSlug' => $deptSlug,
    'submitterEmail' => $agent['email'],
]);

$ctx = stream_context_create(['http' => [
    'method'        => 'POST',
    'timeout'       => 12,
    'header'        => "Authorization: Bearer $token\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
    'content'       => $payload,
    'ignore_errors' => true,
]]);
$raw  = @file_get_contents("$base/api/tickets/submit", false, $ctx);
$data = $raw !== false ? json_decode($raw, true) : null;

// Parse HTTP status from response headers
$status = 200;
foreach ($http_response_header ?? [] as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1];
}

if ($status >= 400 || !is_array($data) || empty($data['ok'])) {
    http_response_code(502);
    echo json_encode(['error' => $data['error'] ?? 'Could not create ticket.']);
    exit;
}

echo json_encode(['ok' => true, 'ticketId' => $data['ticketId'] ?? null]);
