<?php
// Strategic Intent (Attract & Empower) — PUBLIC submission endpoint. No login required.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/notifications.php';

header('Content-Type: application/json');

$db = local_db();

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Honeypot — a real visitor never fills this hidden field. Report success without
// writing anything so a bot can't tell its submission was dropped.
if (trim($body['hp'] ?? '') !== '') { echo json_encode(['ok' => true]); exit; }

$name  = trim(mb_substr($body['name'] ?? '', 0, 200));
$email = strtolower(trim(mb_substr($body['email'] ?? '', 0, 200)));
$role  = trim($body['role'] ?? '');

if ($name === '') { echo json_encode(['ok' => false, 'error' => 'Name is required.']); exit; }
if (!in_array($role, ['bic', 'recruiter', 'mcl'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Please select your role.']); exit;
}

$milestones = $body['milestones'] ?? [];
$projects   = $body['projects'] ?? [];
if (!is_array($milestones)) $milestones = [];
if (!is_array($projects)) $projects = [];

$milestonesClean = array_map(function ($m) {
    return [
        'year'          => trim(mb_substr((string)($m['year'] ?? ''), 0, 20)),
        'name'          => trim(mb_substr((string)($m['name'] ?? ''), 0, 300)),
        'metrics'       => trim(mb_substr((string)($m['metrics'] ?? ''), 0, 2000)),
        'marketing'     => trim(mb_substr((string)($m['marketing'] ?? ''), 0, 2000)),
        'systems'       => trim(mb_substr((string)($m['systems'] ?? ''), 0, 2000)),
        'accomplishments' => trim(mb_substr((string)($m['accomplishments'] ?? ''), 0, 2000)),
    ];
}, array_slice($milestones, 0, 20));

$projectsClean = array_values(array_filter(array_map(function ($p) {
    return trim(mb_substr((string)$p, 0, 500));
}, array_slice($projects, 0, 20)), fn($p) => $p !== ''));

$marketCenter    = trim(mb_substr($body['market_center'] ?? '', 0, 200));
$vision          = trim(mb_substr($body['vision'] ?? '', 0, 5000));
$personalReasons = trim(mb_substr($body['personal_reasons'] ?? '', 0, 2000));
$timeframeYears  = max(1, min(50, (int)($body['timeframe_years'] ?? 5)));
$timeframeWhy    = trim(mb_substr($body['timeframe_why'] ?? '', 0, 2000));
$hurdles         = trim(mb_substr($body['hurdles'] ?? '', 0, 3000));
$gaps            = trim(mb_substr($body['gaps'] ?? '', 0, 3000));
$reinforcement   = trim(mb_substr($body['reinforcement'] ?? '', 0, 3000));
$accountability  = trim(mb_substr($body['accountability'] ?? '', 0, 2000));

$db->prepare(
    "INSERT INTO strategic_intent_responses
     (name, email, market_center, role, vision, personal_reasons, timeframe_years, timeframe_why, milestones, hurdles, gaps, projects, reinforcement, accountability)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
)->execute([
    $name, $email, $marketCenter, $role, $vision, $personalReasons,
    $timeframeYears, $timeframeWhy, json_encode($milestonesClean),
    $hurdles, $gaps, json_encode($projectsClean), $reinforcement, $accountability,
]);

// ── Email notification ────────────────────────────────────────────────────
$roleLabel = ['bic' => 'BIC', 'recruiter' => 'Recruiter', 'mcl' => 'Market Center Leader'][$role] ?? $role;

$milestoneLines = [];
foreach ($milestonesClean as $i => $m) {
    if ($m['name'] === '' && $m['metrics'] === '' && $m['year'] === '') continue;
    $milestoneLines[] = "  Milestone " . ($i + 1) . " — " . ($m['year'] ?: '—') . ($m['name'] ? ': ' . $m['name'] : '')
        . ($m['metrics'] ? "\n    Metrics: " . $m['metrics'] : '')
        . ($m['marketing'] ? "\n    Marketing: " . $m['marketing'] : '')
        . ($m['systems'] ? "\n    Systems: " . $m['systems'] : '')
        . ($m['accomplishments'] ? "\n    Accomplishments: " . $m['accomplishments'] : '');
}

$subject = "Strategic Intent Submission — {$name} ({$roleLabel})";
$emailBody = implode("\n\n", array_filter([
    "A new Strategic Intent submission came in from Attract & Empower.",
    "Name: {$name}\nEmail: " . ($email ?: '—') . "\nMarket Center: " . ($marketCenter ?: '—') . "\nRole: {$roleLabel}",
    "Ultimate Strategic Intent:\n{$vision}",
    $personalReasons ? "Personal Reasons:\n{$personalReasons}" : '',
    "Timeframe: {$timeframeYears} years" . ($timeframeWhy ? "\nWhy: {$timeframeWhy}" : ''),
    $milestoneLines ? "Milestones:\n" . implode("\n\n", $milestoneLines) : '',
    $hurdles ? "Hurdles & Risks:\n{$hurdles}" : '',
    $gaps ? "Gaps:\n{$gaps}" : '',
    $projectsClean ? "Projects:\n  " . implode("\n  ", $projectsClean) : '',
    $reinforcement ? "Reinforcement Plan:\n{$reinforcement}" : '',
    $accountability ? "Accountability:\n{$accountability}" : '',
]));

$c = cfg();
send_email_sendgrid('darren@innovateonline.com', $subject, $emailBody, $c);

echo json_encode(['ok' => true]);
