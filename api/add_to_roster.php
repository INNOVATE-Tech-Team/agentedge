<?php
// Called by Advantage CRM (advantage.innovateonline.com) to immediately add
// an agent to AgentEdge's active roster and give them a login.
//
// Unlike onboard_push.php, this always writes to innovate_roster regardless
// of whether state_code is present — the agent appears in roster_export.php
// (and therefore the Advantage Retention Roster) as soon as they're added,
// not after onboarding completes.
//
// Auth: crm_token from config.php (same token as roster_export.php).
// Method: POST, JSON body.
//
// Required: name, email
// Optional: canonical_agent_id, state_code, market_center, phone, added_by, license_exp,
//           is_on_internal_team, team_leader_name, special_considerations
//
// Response: { ok: true, id: <innovate_roster.id>, reactivated: bool }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/roster.php';

header('Content-Type: application/json');

function _atr_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    _atr_out(['ok'=>false,'error'=>'POST required'], 405);
}

$raw  = file_get_contents('php://input');
$body = $raw ? json_decode($raw, true) ?? [] : [];

$c     = cfg();
$token = $c['crm_token'] ?? '';
$given = trim($body['token'] ?? $_SERVER['HTTP_X_AGENTEDGE_TOKEN'] ?? '');

if ($token === '' || $given === '') {
    _atr_out(['ok'=>false,'error'=>'missing token'], 401);
}
if (!hash_equals($token, $given)) {
    _atr_out(['ok'=>false,'error'=>'invalid token'], 403);
}

$name    = trim($body['name'] ?? '');
$email   = strtolower(trim($body['email'] ?? ''));
$caid    = isset($body['canonical_agent_id']) && $body['canonical_agent_id'] !== ''
           ? (string)$body['canonical_agent_id'] : null;
$state   = trim($body['state_code'] ?? '');
$mc      = trim($body['market_center'] ?? '');
$phone   = trim($body['phone'] ?? '');
$addedBy = trim($body['added_by'] ?? 'advantage-crm');
$licExp  = trim($body['license_exp'] ?? '');
$specialConsiderations = trim($body['special_considerations'] ?? '');
$isOnTeam   = array_key_exists('is_on_internal_team', $body) ? (bool)$body['is_on_internal_team'] : null;
$teamLeader = trim($body['team_leader_name'] ?? '');

if ($name === '' || $email === '') {
    _atr_out(['ok'=>false,'error'=>'name and email required'], 400);
}

$pdo = local_db();

$result = add_or_reactivate_roster_agent(
    $pdo, $name, $state, $mc, $licExp, $caid, $addedBy, $email, $phone
);

// Set up login — INSERT OR IGNORE preserves any password the agent or admin
// has already set (e.g. on re-add after an offboard).
$defaultPw = trim($c['default_agent_password'] ?? '');
if ($defaultPw !== '') {
    $pdo->prepare(
        "INSERT OR IGNORE INTO agent_passwords (email, password_hash, updated_at)
         VALUES (?, ?, datetime('now'))"
    )->execute([$email, password_hash($defaultPw, PASSWORD_BCRYPT)]);
}

// Set up role — INSERT OR IGNORE so we never downgrade an existing role.
$pdo->prepare(
    "INSERT OR IGNORE INTO agent_roles (email, role) VALUES (?, 'agent')"
)->execute([$email]);

// Store retention context in innovate_roster.retention_notes.
$noteParts = [];
if ($isOnTeam !== null) {
    $teamLine = $isOnTeam ? 'On a team' : '';
    if ($teamLine !== '' && $teamLeader !== '') $teamLine .= ' — Leader: ' . $teamLeader;
    if ($teamLine !== '') $noteParts[] = $teamLine;
} elseif ($teamLeader !== '') {
    $noteParts[] = 'Team Leader: ' . $teamLeader;
}
if ($specialConsiderations !== '') $noteParts[] = $specialConsiderations;
if ($noteParts) {
    $pdo->prepare("UPDATE innovate_roster SET retention_notes = ? WHERE id = ?")
        ->execute([implode("\n", $noteParts), $result['id']]);
}

_atr_out(['ok'=>true, 'id'=>$result['id'], 'reactivated'=>$result['reactivated']]);
