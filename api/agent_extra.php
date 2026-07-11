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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_GET['email'])) {
        $requested = strtolower(trim($_GET['email']));
        if (!$isAdmin && $requested !== $myEmail) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
        $email = $requested;
    }
    $stmt = local_db()->prepare("SELECT birthday, hire_date, license_renewal FROM agent_extra WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'birthday'        => $row['birthday']        ?? '',
        'hire_date'       => $row['hire_date']        ?? '',
        'license_renewal' => $row['license_renewal']  ?? '',
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

if ($birthday        !== '' && !preg_match('/^\d{2}-\d{2}$/', $birthday))
    { http_response_code(400); echo json_encode(['error' => 'birthday must be MM-DD']); exit; }
if ($hire_date       !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hire_date))
    { http_response_code(400); echo json_encode(['error' => 'hire_date must be YYYY-MM-DD']); exit; }
if ($license_renewal !== '' && !preg_match('/^\d{2}-\d{2}$/', $license_renewal))
    { http_response_code(400); echo json_encode(['error' => 'license_renewal must be MM-DD']); exit; }

local_db()->prepare(
    "INSERT INTO agent_extra (email, birthday, hire_date, license_renewal, updated_at)
     VALUES (?, ?, ?, ?, datetime('now'))
     ON CONFLICT(email) DO UPDATE SET
         birthday        = excluded.birthday,
         hire_date       = excluded.hire_date,
         license_renewal = excluded.license_renewal,
         updated_at      = excluded.updated_at"
)->execute([$email, $birthday, $hire_date, $license_renewal]);

echo json_encode(['ok' => true]);
