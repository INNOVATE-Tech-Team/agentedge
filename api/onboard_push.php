<?php
// AgentEdge — External onboarding intake endpoint.
// Called by Advantage CRM when an agent is added to the team.
// Auth: JSON body must include 'token' matching 'permissions_token' in config.php.
// Returns: { ok, id, queue_url } — frontend can redirect to queue_url to view the entry.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../onboard_tools.php';
require_once __DIR__ . '/../lib/onboarding.php';
require_once __DIR__ . '/../lib/roster.php';

header('Content-Type: application/json');

function json_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok'=>false,'error'=>'POST required'], 405);
}

$raw  = file_get_contents('php://input');
$body = $raw ? json_decode($raw, true) ?? [] : [];

// Token validation — constant-time compare to prevent timing attacks
$c        = cfg();
$expected = trim($c['permissions_token'] ?? '');
$provided = trim($body['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? '');

if ($expected === '' || !hash_equals($expected, $provided)) {
    json_out(['ok'=>false,'error'=>'Unauthorized'], 403);
}

$name  = trim($body['name']  ?? $body['agent_name']  ?? '');
$email = trim($body['email'] ?? $body['agent_email'] ?? '');
if ($name === '' || $email === '') {
    json_out(['ok'=>false,'error'=>'name and email are required']);
}

$pdo  = local_db();
$base = rtrim($c['agentedge_base_url'] ?? 'https://agentedge.innovateonline.com', '/');

$mc                = trim($body['market_center']       ?? '');
$role              = trim($body['role']                ?? 'agent');
$sponsor           = trim($body['sponsor']              ?? '');
$start             = trim($body['start_date']           ?? '');
$notes             = trim($body['notes']                ?? '');
$addedBy           = trim($body['added_by']             ?? 'advantage-crm');
$stateCode         = trim($body['state_code']           ?? '');
$licenseExp        = trim($body['license_exp']          ?? '');
$canonicalAgentId  = isset($body['canonical_agent_id']) ? (string)$body['canonical_agent_id'] : null;

$result   = queue_onboarding_agent($pdo, $email, $name, $mc, $stateCode, $canonicalAgentId, $addedBy, $start, $sponsor, $role, $notes);
$queueId  = $result['id'];
$queueUrl = $base . '/onboarding.php?open=' . $queueId;

// Auto-add to innovate_roster if state_code was provided at intake time —
// otherwise this happens later, when onboarding is marked complete
// (onboard_action.php's complete_onboarding), which requires a state by then.
if ($stateCode !== '') {
    add_or_reactivate_roster_agent($pdo, $name, $stateCode, $mc, $licenseExp, $canonicalAgentId, $addedBy);
}

echo json_encode(['ok'=>true,'id'=>$queueId,'queue_url'=>$queueUrl,'already_queued'=>!$result['wasNew']]);
if ($result['wasNew']) {
    try {
        require_once __DIR__ . '/../lib/notifications.php';
        dispatch_notification_queue();
    } catch (\Throwable $e) {}
}
exit;
