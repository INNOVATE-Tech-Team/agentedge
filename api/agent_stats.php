<?php
// Admin endpoint: returns dashboard stats for any agent by email.
// Calls the Perfex bridge with action=dashboard_by_email.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }
if (!is_leader()) { http_response_code(403); echo json_encode(['error'=>'leaders only']); exit; }

$email = trim($_GET['email'] ?? '');
if ($email === '') { http_response_code(400); echo json_encode(['error'=>'email required']); exit; }

$c      = cfg();
$bridge = $c['auth_bridge_url'] ?? '';
$btoken = $c['auth_bridge_token'] ?? '';
if ($bridge === '' || $btoken === '') {
    http_response_code(500); echo json_encode(['error'=>'bridge not configured']); exit;
}

$opts = ['http' => [
    'method'        => 'POST',
    'timeout'       => 15,
    'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
    'content'       => json_encode(['token'=>$btoken,'action'=>'dashboard_by_email','email'=>$email]),
    'ignore_errors' => true,
]];
$raw = @file_get_contents($bridge, false, stream_context_create($opts));
$d   = $raw === false ? null : json_decode($raw, true);

if (!is_array($d) || empty($d['ok'])) {
    echo json_encode(['hasData'=>false,
        'tiles'=>['volume'=>0,'closedDeals'=>0,'residual'=>0,'recruits'=>0],
        'cap'=>null,'network'=>[]]);
    exit;
}
echo json_encode([
    'hasData' => !empty($d['hasData']),
    'tiles'   => $d['tiles'] ?? ['volume'=>0,'closedDeals'=>0,'residual'=>0,'recruits'=>0],
    'cap'     => $d['cap'] ?? null,
    'network' => $d['network'] ?? [],
]);
