<?php
// My profile — load & save the logged-in agent's own record.
// agent_intake is the source of truth for name/phone/social links (same table
// the Intake Form and the back-office Edit Profile modal read/write), so an
// agent's self-service edit here now shows up in both places. innovate_roster
// and agent_extra.social_json are dual-written so the existing Advantage/
// coastline-server CRM roster sync and the roster social-icon overlay
// (api/roster.php) keep working unchanged.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/notifications.php';
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

function get_intake(object $db, string $email): array {
    $s = $db->prepare("SELECT * FROM agent_intake WHERE email=? LIMIT 1");
    $s->execute([$email]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}

$SOCIAL_KEYS = ['facebook','instagram','linkedin','twitter','youtube','tiktok','website','blog'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // ── Load ──────────────────────────────────────────────────────────────────
    $intake = get_intake($db, $myEmail);
    $row    = find_roster_row($db, $myEmail);

    // Name/phone: agent_intake first (source of truth), falling back to
    // innovate_roster / tblstaff for an agent who hasn't touched their intake
    // form yet.
    $fullName = $intake['full_name'] ?? '';
    $phone    = $intake['phone']     ?? '';
    if ($fullName === '' && $row) $fullName = $row['agent_name'];
    if ($phone === '' && $row)    $phone    = $row['phone'];
    if ($fullName === '') {
        $staff = db_one("SELECT firstname, lastname, phonenumber FROM tblstaff WHERE email=? LIMIT 1", [$myEmail]);
        if ($staff) {
            $fullName = trim($staff['firstname'] . ' ' . $staff['lastname']);
            if ($phone === '') $phone = $staff['phonenumber'] ?? '';
        }
    }

    $social = [];
    foreach ($SOCIAL_KEYS as $k) $social[$k] = $intake[$k] ?? '';

    echo json_encode([
        'matched'  => true,
        'editable' => true,
        'profile'  => [
            'fullName'     => $fullName,
            'email'        => $row ? $row['email'] : $myEmail,
            'phone'        => $phone,
            'marketCenter' => $row ? $row['market_center'] : ($intake['office_location'] ?? ''),
            'brokerage'    => 'INNOVATE Real Estate',
            'social'       => (object)$social,
        ],
    ]);
    exit;
}

// ── Save ──────────────────────────────────────────────────────────────────────
$in    = json_decode(file_get_contents('php://input'), true) ?: [];
$email = strtolower(trim($in['email'] ?? $myEmail));
$phone = trim($in['phone'] ?? '');
$name  = trim($in['fullName'] ?? '');

// Snapshot "before" values for the change-notification email, using the same
// fallback chain the GET path shows the agent (agent_intake first, then roster).
$beforeIntake  = get_intake($db, $myEmail);
$beforeRoster  = find_roster_row($db, $myEmail);
$beforeName    = $beforeIntake['full_name'] ?: ($beforeRoster['agent_name'] ?? '');
$beforePhone   = $beforeIntake['phone']     ?: ($beforeRoster['phone']      ?? '');
$beforeSocial  = [];
foreach ($SOCIAL_KEYS as $k) $beforeSocial[$k] = $beforeIntake[$k] ?? '';

// Dual-write: keep innovate_roster in sync (Advantage/coastline-server's CRM
// roster export/sync reads this table directly, not agent_intake).
$row = find_roster_row($db, $myEmail);
if ($row) {
    $db->prepare(
        "UPDATE innovate_roster SET email=?, phone=?" . ($name !== '' ? ", agent_name=?" : "") . " WHERE id=?"
    )->execute($name !== ''
        ? [$email, $phone, $name, $row['id']]
        : [$email, $phone, $row['id']]
    );
}

$social = [];
foreach ($SOCIAL_KEYS as $k) $social[$k] = trim($in[$k] ?? '');

// agent_intake is the source of truth — upsert only the fields this page owns
// (full_name/phone/socials), leaving every other intake-form field untouched.
// full_name is preserve-if-blank (like the Intake Form's tax-id fields) so an
// agent can't accidentally blank their name; phone/socials round-trip as-is,
// matching this page's prior behavior against innovate_roster/agent_extra.
$db->prepare(
    "INSERT INTO agent_intake
        (email, full_name, phone, facebook, instagram, linkedin, twitter, youtube, tiktok, website, blog, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
     ON CONFLICT(email) DO UPDATE SET
        full_name  = CASE WHEN excluded.full_name <> '' THEN excluded.full_name ELSE agent_intake.full_name END,
        phone      = excluded.phone,
        facebook   = excluded.facebook,
        instagram  = excluded.instagram,
        linkedin   = excluded.linkedin,
        twitter    = excluded.twitter,
        youtube    = excluded.youtube,
        tiktok     = excluded.tiktok,
        website    = excluded.website,
        blog       = excluded.blog,
        updated_at = excluded.updated_at"
)->execute([
    $myEmail, $name, $phone,
    $social['facebook'], $social['instagram'], $social['linkedin'], $social['twitter'],
    $social['youtube'], $social['tiktok'], $social['website'], $social['blog'],
]);

// Dual-write: agent_extra.social_json still backs the roster social-icon
// overlay (api/roster.php) — keep it current rather than reworking that too.
$db->prepare(
    "INSERT INTO agent_extra (email, social_json, updated_at)
     VALUES (?, ?, datetime('now'))
     ON CONFLICT(email) DO UPDATE SET social_json=excluded.social_json, updated_at=excluded.updated_at"
)->execute([$myEmail, json_encode(array_filter($social, fn($v) => $v !== ''))]);

// Heads-up email to Whitney whenever an agent edits their own profile here —
// full_name uses the same preserve-if-blank effective value actually stored
// above, so a blank submission (which doesn't change the name) isn't reported
// as a change.
$effectiveName = $name !== '' ? $name : $beforeName;
$changes = [];
if ($effectiveName !== $beforeName) $changes['Full Name'] = [$beforeName, $effectiveName];
if ($phone !== $beforePhone)        $changes['Phone']     = [$beforePhone, $phone];
$socialLabels = [
    'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn',
    'twitter' => 'Twitter/X', 'youtube' => 'YouTube', 'tiktok' => 'TikTok',
    'website' => 'Website', 'blog' => 'Blog',
];
foreach ($SOCIAL_KEYS as $k) {
    if ($social[$k] !== $beforeSocial[$k]) $changes[$socialLabels[$k]] = [$beforeSocial[$k], $social[$k]];
}
notify_profile_changed($effectiveName ?: $myEmail, $myEmail, $changes);

echo json_encode(['ok' => true]);
