<?php
// Returns the agent's ACTIVE + PENDING DotLoop loops for the transaction picker.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/dotloop.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { echo json_encode(['ok' => false, 'error' => 'Not logged in']); exit; }

$email = $agent['email'];

if (!dotloop_is_connected($email)) {
    echo json_encode(['ok' => false, 'error' => 'not_connected']);
    exit;
}

$tokens    = dotloop_get_tokens($email);
$profileId = $tokens['profile_id'] ?? '';
if ($profileId === '') {
    echo json_encode(['ok' => false, 'error' => 'No DotLoop profile — please reconnect']);
    exit;
}

$path   = "/profile/{$profileId}/loop?" . http_build_query([
    'pg'     => 1,
    'pgsize' => 100,
    'status' => 'ACTIVE,PENDING',
]);
$result = dotloop_api($email, 'GET', $path);

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Could not load loops']);
    exit;
}

$loops = $result['data']['data'] ?? [];
$out   = [];
foreach ($loops as $l) {
    $out[] = [
        'id'      => $l['id'],
        'name'    => $l['name'] ?? 'Untitled loop',
        'status'  => $l['status'] ?? '',
        'address' => $l['streetName'] ?? '',
    ];
}

echo json_encode(['ok' => true, 'loops' => $out]);
