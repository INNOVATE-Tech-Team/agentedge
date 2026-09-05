<?php
// Agent extra fields: birthday (MM-DD), hire_date (YYYY-MM-DD), license_renewal (MM-DD).
// GET  → returns the signed-in agent's extra fields (admin: pass ?email= for another agent).
// POST → saves them (admin: pass body.email to save for another agent).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$myEmail = strtolower(trim($agent['email'] ?? ''));
$isAdmin = is_admin();
$email   = $myEmail;

// Nothing below writes to $_SESSION. Close it now so this doesn't sit behind
// PHP's default per-session file lock while agent_team_suggestion.php /
// intake.php (fired concurrently by the Edit Profile modal) hold it.
session_write_close();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['email'])) {
        $requested = strtolower(trim($_GET['email']));
        if (!$isAdmin && $requested !== $myEmail) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
        $email = $requested;
    }
    $stmt = local_db()->prepare("SELECT birthday, hire_date, license_renewal, alt_email, dotloop_alt_email, is_team_leader_tag FROM agent_extra WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $birthday = $row['birthday'] ?? '';
    // agent_extra.birthday (MM-DD) is an explicit override; fall back to
    // deriving MM-DD from agent_intake's full DOB when nobody's set one here,
    // so a birthday already captured on the Intake Form doesn't look blank.
    if ($birthday === '') {
        $intakeBday = local_db()->prepare("SELECT birthday FROM agent_intake WHERE email = ?");
        $intakeBday->execute([$email]);
        $full = $intakeBday->fetchColumn();
        if ($full && preg_match('/^\d{4}-(\d{2}-\d{2})$/', $full, $m)) $birthday = $m[1];
    }

    echo json_encode([
        'birthday'        => $birthday,
        'hire_date'       => $row['hire_date']        ?? '',
        'license_renewal' => $row['license_renewal']  ?? '',
        'alt_email'       => $row['alt_email']         ?? '',
        'dotloop_alt_email' => $row['dotloop_alt_email'] ?? '',
        'is_team_leader_tag' => (bool)($row['is_team_leader_tag'] ?? 0),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'GET or POST only']); exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];

if ($isAdmin && !empty($in['email'])) {
    $email = strtolower(trim($in['email']));
}

// Validate and sanitize each field
$birthday        = trim($in['birthday']        ?? '');
$hire_date       = trim($in['hire_date']       ?? '');
$license_renewal = trim($in['license_renewal'] ?? '');
$alt_email       = strtolower(trim($in['alt_email'] ?? ''));
$dotloop_alt_email = strtolower(trim($in['dotloop_alt_email'] ?? ''));

if ($birthday        !== '' && !preg_match('/^\d{2}-\d{2}$/', $birthday))
    { http_response_code(400); echo json_encode(['error' => 'birthday must be MM-DD']); exit; }
if ($hire_date       !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire_date))
    { http_response_code(400); echo json_encode(['error' => 'hire_date must be YYYY-MM-DD']); exit; }
if ($license_renewal !== '' && !preg_match('/^\d{2}-\d{2}$/', $license_renewal))
    { http_response_code(400); echo json_encode(['error' => 'license_renewal must be MM-DD']); exit; }
if ($alt_email !== '' && !filter_var($alt_email, FILTER_VALIDATE_EMAIL))
    { http_response_code(400); echo json_encode(['error' => 'alt_email must be a valid email address']); exit; }
if ($dotloop_alt_email !== '' && !filter_var($dotloop_alt_email, FILTER_VALIDATE_EMAIL))
    { http_response_code(400); echo json_encode(['error' => 'dotloop_alt_email must be a valid email address']); exit; }

// Admin-only, and only applied when the caller actually sent it — a
// self-service save of birthday/hire_date (no is_team_leader_tag key at
// all) must never silently clear an existing tag.
$tagStmt = local_db()->prepare("SELECT is_team_leader_tag FROM agent_extra WHERE email = ?");
$tagStmt->execute([$email]);
$existingTag = (int)($tagStmt->fetchColumn() ?: 0);
$is_team_leader_tag = ($isAdmin && array_key_exists('is_team_leader_tag', $in))
    ? (!empty($in['is_team_leader_tag']) ? 1 : 0)
    : $existingTag;

local_db()->prepare(
    "INSERT INTO agent_extra (email, birthday, hire_date, license_renewal, alt_email, dotloop_alt_email, is_team_leader_tag, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
     ON CONFLICT(email) DO UPDATE SET
         birthday            = excluded.birthday,
         hire_date           = excluded.hire_date,
         license_renewal     = excluded.license_renewal,
         alt_email           = excluded.alt_email,
         dotloop_alt_email   = excluded.dotloop_alt_email,
         is_team_leader_tag  = excluded.is_team_leader_tag,
         updated_at          = excluded.updated_at"
)->execute([$email, $birthday, $hire_date, $license_renewal, $alt_email, $dotloop_alt_email, $is_team_leader_tag]);

echo json_encode(['ok' => true]);
