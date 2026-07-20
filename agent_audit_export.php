<?php
// One-shot CSV audit: active roster agents missing a start date, a birthdate,
// and/or a submitted agent profile — for Whitney's roster audit. No dashboard
// UI, just hit this URL as an admin and it streams the CSV directly.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_login();
require_admin_page();

// Active roster — same name-dedup logic as backoffice_agents.php's $byRosterName,
// since innovate_roster rows are per state/MC and many older rows have no email.
$rosterRows = local_db()->query(
    "SELECT agent_name, email, state_code, market_center FROM innovate_roster WHERE active=1"
)->fetchAll(PDO::FETCH_ASSOC);

$byRosterName = [];
foreach ($rosterRows as $r) {
    $key = strtolower(trim($r['agent_name']));
    if ($key === '') continue;
    if (!isset($byRosterName[$key])) {
        $byRosterName[$key] = ['agent_name' => $r['agent_name'], 'email' => $r['email'], 'states' => [], 'mcs' => []];
    }
    if ($r['email'] && !$byRosterName[$key]['email']) $byRosterName[$key]['email'] = $r['email'];
    if ($r['state_code'])    $byRosterName[$key]['states'][] = $r['state_code'];
    if ($r['market_center']) $byRosterName[$key]['mcs'][]    = $r['market_center'];
}

// Profile (agent_intake) + start date / birthday (agent_extra), keyed both ways.
$intakeRows = local_db()->query(
    "SELECT i.email, i.full_name, i.birthday AS intake_birthday, i.submitted,
            e.hire_date, e.birthday AS extra_birthday
     FROM agent_intake i
     LEFT JOIN agent_extra e ON e.email = i.email"
)->fetchAll(PDO::FETCH_ASSOC);

$intakeByEmail = [];
$intakeByName  = [];
foreach ($intakeRows as $r) {
    if ($r['email'])     $intakeByEmail[strtolower(trim($r['email']))]    = $r;
    if ($r['full_name']) $intakeByName[strtolower(trim($r['full_name']))] = $r;
}

// agent_extra rows for roster agents with no agent_intake row at all — an
// admin can set start date / birthday via the "Manage" modal before the agent
// ever submits their own intake form.
$extraByEmail = [];
foreach (local_db()->query("SELECT email, hire_date, birthday FROM agent_extra")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $extraByEmail[strtolower(trim($r['email']))] = $r;
}

$out_rows = [];
foreach ($byRosterName as $key => $r) {
    $email = strtolower(trim($r['email'] ?? ''));
    $match = ($email !== '' && isset($intakeByEmail[$email])) ? $intakeByEmail[$email]
           : ($intakeByName[$key] ?? null);

    if ($match) {
        $profileStatus = !empty($match['submitted']) ? 'Submitted' : 'Draft';
        $hireDate = trim($match['hire_date'] ?? '');
        $hasBirthday = trim($match['extra_birthday'] ?? '') !== '' || trim($match['intake_birthday'] ?? '') !== '';
    } else {
        $profileStatus = 'Missing';
        $extra = $extraByEmail[$email] ?? null;
        $hireDate = trim($extra['hire_date'] ?? '');
        $hasBirthday = trim($extra['birthday'] ?? '') !== '';
    }

    $missingStart    = $hireDate === '';
    $missingBirthday = !$hasBirthday;
    $missingProfile  = $profileStatus !== 'Submitted';

    if (!$missingStart && !$missingBirthday && !$missingProfile) continue; // nothing to flag

    $out_rows[] = [
        'name'             => $r['agent_name'],
        'email'            => $r['email'] ?: '',
        'market_center'    => implode(', ', array_unique($r['mcs'])),
        'state'            => implode(', ', array_unique($r['states'])),
        'missing_start'    => $missingStart ? 'Yes' : '',
        'missing_birthday' => $missingBirthday ? 'Yes' : '',
        'profile_status'   => $profileStatus,
    ];
}
usort($out_rows, fn($a, $b) => strcasecmp($a['name'], $b['name']));

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="agent-audit-' . date('Y-m-d') . '.csv"');
$fh = fopen('php://output', 'w');
fputcsv($fh, ['Name', 'Email', 'Market Center', 'State', 'Missing Start Date', 'Missing Birthdate', 'Profile Status']);
foreach ($out_rows as $row) {
    fputcsv($fh, [
        $row['name'], $row['email'], $row['market_center'], $row['state'],
        $row['missing_start'], $row['missing_birthday'], $row['profile_status'],
    ]);
}
exit;
