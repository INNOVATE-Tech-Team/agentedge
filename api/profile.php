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
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/notifications.php';
require_once __DIR__ . '/../lib/google_business.php';
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

function get_pending_candidate(object $db, string $email): ?array {
    $s = $db->prepare("SELECT * FROM google_place_candidates WHERE email=? AND status='pending' LIMIT 1");
    $s->execute([$email]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

$SOCIAL_KEYS = ['facebook','instagram','linkedin','twitter','youtube','tiktok','website','blog'];

// ── Candidate confirm/dismiss — short-circuits before the general save flow ──
// This is how a discovered Google Business listing (see
// google_place_candidate_discover_all() in lib/google_business.php) actually
// becomes the agent's real google_place_id: they have to look at it and say
// yes themselves. Confirming also sets review_requests_opt_in=1 in the same
// step, since there's nothing to send review requests for without a place
// ID anyway — the agent can still uncheck the opt-in box afterward without
// losing the place ID.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actionIn = json_decode(file_get_contents('php://input'), true) ?: [];
    $action   = $actionIn['action'] ?? '';

    if ($action === 'confirm_candidate' || $action === 'dismiss_candidate') {
        $candidate = get_pending_candidate($db, $myEmail);
        if (!$candidate) { echo json_encode(['error' => 'No pending candidate found.']); exit; }

        if ($action === 'confirm_candidate') {
            $db->prepare(
                "INSERT INTO agent_intake (email, google_place_id, review_requests_opt_in, updated_at)
                 VALUES (?, ?, 1, datetime('now'))
                 ON CONFLICT(email) DO UPDATE SET
                    google_place_id = excluded.google_place_id,
                    review_requests_opt_in = 1,
                    updated_at = excluded.updated_at"
            )->execute([$myEmail, $candidate['place_id']]);
            $db->prepare("UPDATE google_place_candidates SET status='confirmed', decided_at=datetime('now') WHERE email=?")
               ->execute([$myEmail]);
            notify_profile_changed($myEmail, $myEmail, ['Google Place ID' => ['', $candidate['place_id']], 'Review Requests Opt-In' => ['0', '1']]);
        } else {
            $db->prepare("UPDATE google_place_candidates SET status='dismissed', decided_at=datetime('now') WHERE email=?")
               ->execute([$myEmail]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // ── Load ──────────────────────────────────────────────────────────────────
    $intake    = get_intake($db, $myEmail);
    $row       = find_roster_row($db, $myEmail);
    $candidate = get_pending_candidate($db, $myEmail);

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

    // An agent can belong to more than one market center (e.g. licensed in
    // bordering states) — own_mc_slugs already carries every assignment
    // (see roles.php), unlike innovate_roster's one-row-per-office layout.
    // Fall back to slugifying the legacy single marketCenter value for
    // agents who don't yet have an agent_roles row.
    $mcSlugs = my_own_mc_slugs();
    if (!$mcSlugs) {
        $fallbackMc = $row ? $row['market_center'] : ($intake['office_location'] ?? '');
        if ($fallbackMc !== '') $mcSlugs = [slugify_mc($fallbackMc)];
    }

    echo json_encode([
        'matched'  => true,
        'editable' => true,
        'profile'  => [
            'fullName'            => $fullName,
            'email'               => $row ? $row['email'] : $myEmail,
            'phone'               => $phone,
            'marketCenter'        => $row ? $row['market_center'] : ($intake['office_location'] ?? ''),
            'marketCenters'       => $mcSlugs,
            'brokerage'           => 'INNOVATE Real Estate',
            'social'              => (object)$social,
            'googlePlaceId'       => $intake['google_place_id'] ?? '',
            'reviewRequestsOptIn' => !empty($intake['review_requests_opt_in']),
            'zillowReviewLink'          => $intake['zillow_review_link'] ?? '',
            'zillowReviewRequestsOptIn' => !empty($intake['zillow_review_requests_opt_in']),
        ],
        'candidate' => $candidate ? [
            'name'      => $candidate['candidate_name'],
            'rating'    => $candidate['rating'],
            'reviews'   => (int)$candidate['review_count'],
            'address'   => $candidate['formatted_addr'],
        ] : null,
    ]);
    exit;
}

// ── Save ──────────────────────────────────────────────────────────────────────
$in       = json_decode(file_get_contents('php://input'), true) ?: [];
$email    = strtolower(trim($in['email'] ?? $myEmail));
$phone    = trim($in['phone'] ?? '');
$name     = trim($in['fullName'] ?? '');
$placeId  = trim($in['googlePlaceId'] ?? '');
$reviewOptIn = !empty($in['reviewRequestsOptIn']) ? 1 : 0;
$zillowLink = trim($in['zillowReviewLink'] ?? '');
$zillowOptIn = !empty($in['zillowReviewRequestsOptIn']) ? 1 : 0;

// Snapshot "before" values for the change-notification email, using the same
// fallback chain the GET path shows the agent (agent_intake first, then roster).
$beforeIntake  = get_intake($db, $myEmail);
$beforeRoster  = find_roster_row($db, $myEmail);
$beforeName    = $beforeIntake['full_name'] ?: ($beforeRoster['agent_name'] ?? '');
$beforePhone   = $beforeIntake['phone']     ?: ($beforeRoster['phone']      ?? '');
$beforeSocial  = [];
foreach ($SOCIAL_KEYS as $k) $beforeSocial[$k] = $beforeIntake[$k] ?? '';
$beforePlaceId = $beforeIntake['google_place_id'] ?? '';
$beforeOptIn   = !empty($beforeIntake['review_requests_opt_in']);
$beforeZillowLink  = $beforeIntake['zillow_review_link'] ?? '';
$beforeZillowOptIn = !empty($beforeIntake['zillow_review_requests_opt_in']);

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
        (email, full_name, phone, facebook, instagram, linkedin, twitter, youtube, tiktok, website, blog, google_place_id, review_requests_opt_in, zillow_review_link, zillow_review_requests_opt_in, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
     ON CONFLICT(email) DO UPDATE SET
        full_name              = CASE WHEN excluded.full_name <> '' THEN excluded.full_name ELSE agent_intake.full_name END,
        phone                  = excluded.phone,
        facebook               = excluded.facebook,
        instagram              = excluded.instagram,
        linkedin               = excluded.linkedin,
        twitter                = excluded.twitter,
        youtube                = excluded.youtube,
        tiktok                 = excluded.tiktok,
        website                = excluded.website,
        blog                   = excluded.blog,
        google_place_id        = excluded.google_place_id,
        review_requests_opt_in = excluded.review_requests_opt_in,
        zillow_review_link            = excluded.zillow_review_link,
        zillow_review_requests_opt_in = excluded.zillow_review_requests_opt_in,
        updated_at             = excluded.updated_at"
)->execute([
    $myEmail, $name, $phone,
    $social['facebook'], $social['instagram'], $social['linkedin'], $social['twitter'],
    $social['youtube'], $social['tiktok'], $social['website'], $social['blog'],
    $placeId, $reviewOptIn, $zillowLink, $zillowOptIn,
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
if ($placeId !== $beforePlaceId) $changes['Google Place ID'] = [$beforePlaceId, $placeId];
if ($reviewOptIn !== (int)$beforeOptIn) $changes['Review Requests Opt-In'] = [(int)$beforeOptIn, $reviewOptIn];
if ($zillowLink !== $beforeZillowLink) $changes['Zillow Review Link'] = [$beforeZillowLink, $zillowLink];
if ($zillowOptIn !== (int)$beforeZillowOptIn) $changes['Zillow Review Requests Opt-In'] = [(int)$beforeZillowOptIn, $zillowOptIn];
notify_profile_changed($effectiveName ?: $myEmail, $myEmail, $changes);

echo json_encode(['ok' => true]);
