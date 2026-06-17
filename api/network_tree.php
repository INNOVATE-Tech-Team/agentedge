<?php
// Returns the full network tree for a given agent email.
// Leaders can query any email; agents can only query their own.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
require __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$email = trim($_GET['email'] ?? '');

// Non-leaders can only see their own tree
if (!is_leader()) $email = $me['email'];
if ($email === '') $email = $me['email'];

$c      = cfg();
$bridge = $c['auth_bridge_url'] ?? '';
$btoken = $c['auth_bridge_token'] ?? '';
if ($bridge === '' || $btoken === '') {
    http_response_code(500); echo json_encode(['error'=>'bridge not configured']); exit;
}

$opts = ['http' => [
    'method'        => 'POST',
    'timeout'       => 20,
    'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
    'content'       => json_encode(['token'=>$btoken,'action'=>'network_tree','email'=>$email]),
    'ignore_errors' => true,
]];
$raw = @file_get_contents($bridge, false, stream_context_create($opts));
$d   = $raw === false ? null : json_decode($raw, true);

if (!is_array($d) || empty($d['ok'])) {
    echo json_encode(['tree'=>null,'totalCount'=>0,'error'=>'bridge error']);
    exit;
}
echo json_encode(['tree'=>$d['tree'],'totalCount'=>$d['totalCount']??0]);
