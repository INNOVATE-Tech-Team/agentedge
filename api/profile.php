<?php
// My profile — load & save the logged-in agent's own record from local SQLite.
// innovate_roster stores name/email/phone; agent_extra stores social links + dates.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$myEmail = strtolower(trim($me['email'] ?? ''));
$db      = local_db();

// Find the agent's roster row by their stored email
function find_roster_row(object $db, string $email): ?array {
    if ($email === '') return null;
    $s = $db->prepare("SELECT * FROM innovate_roster WHERE LOWER(TRIM(email))=? AND active=1 LIMIT 1");
    $s->execute([$email]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Fetch or init agent_extra row
function get_extra(object $db, string $email): array {
    $s = $db->prepare("SELECT * FROM agent_extra WHERE email=? LIMIT 1");
    $s->execute([$email]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}

$SOCIAL_KEYS = ['facebook','instagram','linkedin','twitter','youtube','tiktok','website','blog'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // ── Load ──────────────────────────────────────────────────────────────────
    $row   = find_roster_row($db, $myEmail);
    $extra = get_extra($db, $myEmail);

    // Fallback: if no roster row by email, try tblstaff for display name/phone
    $fallbackName  = $me['name']  ?? '';
    $fallbackPhone = '';
    if (!$row) {
        $staff = db_one("SELECT firstname, lastname, phonenumber FROM tblstaff WHERE email=? LIMIT 1", [$myEmail]);
        if ($staff) {
            $fallbackName  = trim($staff['firstname'] . ' ' . $staff['lastname']);
            $fallbackPhone = $staff['phonenumber'] ?? '';
        }
    }

    $social = json_decode($extra['social_json'] ?? '{}', true) ?: [];

    echo json_encode([
        'matched'  => true,
        'editable' => true,
        'profile'  => [
            'fullName'     => $row ? $row['agent_name']    : $fallbackName,
            'email'        => $row ? $row['email']         : $myEmail,
            'phone'        => $row ? $row['phone']         : $fallbackPhone,
            'marketCenter' => $row ? $row['market_center'] : '',
            'brokerage'    => 'INNOVATE Real Estate',
            'social'       => (object)array_intersect_key($social, array_flip($SOCIAL_KEYS)),
        ],
    ]);
    exit;
}

// ── Save ──────────────────────────────────────────────────────────────────────
$in    = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim($in['email'] ?? $myEmail));
$phone = trim($in['phone'] ?? '');
$name  = trim($in['fullName'] ?? '');

// Update innovate_roster rows for this agent (may have multiple state entries)
$row = find_roster_row($db, $myEmail);
if ($row) {
    $db->prepare(
        "UPDATE innovate_roster SET email=?, phone=?" . ($name !== '' ? ", agent_name=?" : "") . " WHERE id=?"
    )->execute($name !== ''
        ? [$email, $phone, $name, $row['id']]
        : [$email, $phone, $row['id']]
    );
}

// Save social links into agent_extra.social_json
$social = [];
foreach ($SOCIAL_KEYS as $k) {
    $v = trim($in[$k] ?? '');
    if ($v !== '') $social[$k] = $v;
}
$db->prepare(
    "INSERT INTO agent_extra (email, social_json, updated_at)
     VALUES (?, ?, datetime('now'))
     ON CONFLICT(email) DO UPDATE SET social_json=excluded.social_json, updated_at=excluded.updated_at"
)->execute([$myEmail, json_encode($social)]);

echo json_encode(['ok' => true]);
