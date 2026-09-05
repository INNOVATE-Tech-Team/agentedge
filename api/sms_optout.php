<?php
// Headless sync endpoint: coastline-server calls this whenever a phone number
// replies STOP/START to a text sent through the shared Twilio Messaging
// Service, so AgentEdge's own notify_sms toggle stays consistent with what
// Twilio is actually delivering (Twilio's own Advanced Opt-Out already blocks
// delivery immediately regardless of this call — this only keeps the local
// UI/DB truthful). Bearer-token auth, no user session involved.
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

// Apache/mod_php commonly strips the Authorization header from $_SERVER
// unless CGIPassAuth is explicitly enabled — check every place it might
// actually show up rather than assume $_SERVER['HTTP_AUTHORIZATION'] exists.
function _incoming_auth_header(): string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return $_SERVER['HTTP_AUTHORIZATION'];
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        foreach ($h as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) return $v;
        }
    }
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        foreach ($h as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) return $v;
        }
    }
    return '';
}

$c = cfg();
$token = $c['sms_optout_token'] ?? '';
$authHeader = _incoming_auth_header();
if (!$token || !hash_equals('Bearer ' . $token, $authHeader)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$phone  = preg_replace('/\D/', '', $in['phone'] ?? '');
$action = $in['action'] ?? '';
if (strlen($phone) < 10 || !in_array($action, ['stop', 'start'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'phone and action (stop|start) are required']);
    exit;
}
$last10 = substr($phone, -10);

$db  = local_db();
$rows = $db->query("SELECT email, sms_phone FROM notification_prefs WHERE sms_phone <> ''")->fetchAll(PDO::FETCH_ASSOC);
$upd  = $db->prepare("UPDATE notification_prefs SET notify_sms = ? WHERE email = ?");
$updated = 0;
foreach ($rows as $r) {
    $rowDigits = preg_replace('/\D/', '', $r['sms_phone']);
    if (substr($rowDigits, -10) === $last10) {
        $upd->execute([$action === 'stop' ? 0 : 1, $r['email']]);
        $updated++;
    }
}
echo json_encode(['ok' => true, 'updated' => $updated]);
