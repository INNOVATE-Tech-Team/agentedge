<?php
// BIC / leader calendar feed — returns all agents' birthdays and work
// anniversaries for the requested month as BIC-scoped calendar events.
// Only accessible to BIC, MC leaders, and admin roles.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

// Accessible to any leader role (bic, mc_leader, staff, super_admin)
if (!is_leader()) {
    echo json_encode(['events' => []]);
    exit;
}

// Admin/staff still see every market center (matches announcements.php's
// scoping convention); BIC/MC leaders only see the market center(s) they lead.
$mcFilterSlugs = null;
if (!is_admin()) {
    $mcFilterSlugs = my_mc_slugs();
    if (empty($mcFilterSlugs)) {
        echo json_encode(['events' => []]);
        exit;
    }
}

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
[$year, $mon] = array_map('intval', explode('-', $month));

$mcByEmail = [];
if ($mcFilterSlugs !== null) {
    $rosterRows = local_db()->query("SELECT email, market_center FROM innovate_roster WHERE market_center != ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rosterRows as $r) {
        $mcByEmail[strtolower(trim($r['email']))] = slugify_mc($r['market_center']);
    }
}

$extraRows = local_db()->query("SELECT email, birthday, hire_date FROM agent_extra")->fetchAll(PDO::FETCH_ASSOC);
$rowsByEmail = [];
foreach ($extraRows as $r) {
    $rowsByEmail[strtolower(trim($r['email']))] = ['birthday' => $r['birthday'] ?: '', 'hire_date' => $r['hire_date'] ?: ''];
}

// agent_extra's birthday (MM-DD) is an explicit override; most agents only ever
// gave their full DOB via the Intake Form (agent_intake.birthday), so fall back
// to deriving MM-DD from that when agent_extra has nothing on file — otherwise
// this calendar silently skips anyone who never separately re-entered it here.
$intakeRows = local_db()->query("SELECT email, birthday FROM agent_intake WHERE birthday != ''")->fetchAll(PDO::FETCH_ASSOC);
foreach ($intakeRows as $r) {
    $email = strtolower(trim($r['email']));
    if (!isset($rowsByEmail[$email])) $rowsByEmail[$email] = ['birthday' => '', 'hire_date' => ''];
    if ($rowsByEmail[$email]['birthday'] === '' && preg_match('/^\d{4}-(\d{2}-\d{2})$/', $r['birthday'], $m)) {
        $rowsByEmail[$email]['birthday'] = $m[1];
    }
}

$events = [];

foreach ($rowsByEmail as $email => $r) {
    if ($mcFilterSlugs !== null) {
        $slug = $mcByEmail[$email] ?? null;
        if ($slug === null || !in_array($slug, $mcFilterSlugs, true)) continue;
    }
    $r['email'] = $email;
    // Birthday — stored as MM-DD, recurs annually
    if (!empty($r['birthday']) && preg_match('/^(\d{2})-(\d{2})$/', $r['birthday'], $m)) {
        if ((int)$m[1] === $mon) {
            $events[] = [
                'date'        => sprintf('%04d-%02d-%02d', $year, (int)$m[1], (int)$m[2]),
                'title'       => '🎂 Birthday: ' . agent_display_name($r['email']),
                'scope'       => 'bic',
                'description' => $r['email'],
            ];
        }
    }

    // Work anniversary — stored as YYYY-MM-DD, show each year on MM-DD
    if (!empty($r['hire_date']) && preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $r['hire_date'], $m)) {
        if ((int)$m[1] === $mon) {
            $hireYear = (int)substr($r['hire_date'], 0, 4);
            $years    = $year - $hireYear;
            if ($years > 0) {
                $events[] = [
                    'date'        => sprintf('%04d-%02d-%02d', $year, (int)$m[1], (int)$m[2]),
                    'title'       => '🎉 ' . $years . '-Year Anniversary: ' . agent_display_name($r['email']),
                    'scope'       => 'bic',
                    'description' => $r['email'],
                ];
            }
        }
    }
}

usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

echo json_encode(['events' => $events]);

function agent_display_name(string $email): string {
    // Extract readable name from email — e.g. john.doe@example.com → John Doe
    $local = explode('@', $email)[0];
    return implode(' ', array_map('ucfirst', preg_split('/[._-]+/', $local)));
}
