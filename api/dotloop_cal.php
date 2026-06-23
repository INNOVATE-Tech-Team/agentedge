<?php
// Returns DotLoop transaction dates for the signed-in agent as calendar events.
// Surfaces: closeDate (Closed), targetDate (Under Contract), and the agent's
// license renewal date from agent_extra (stored as MM-DD, shown every year).
//
// Response format matches api/events.php: { events: [{ date, title, scope, description }] }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
require __DIR__ . '/../lib/dotloop.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
[$year, $mon] = array_map('intval', explode('-', $month));

$email = $agent['email'] ?? '';
$events = [];

// ── License renewal date ──────────────────────────────────────────────────────
$extra = local_db()->prepare("SELECT birthday, hire_date, license_renewal FROM agent_extra WHERE email = ?");
$extra->execute([$email]);
$ex = $extra->fetch(PDO::FETCH_ASSOC);

if ($ex) {
    // License renewal — recurs annually on MM-DD
    if (!empty($ex['license_renewal']) && preg_match('/^(\d{2})-(\d{2})$/', $ex['license_renewal'], $m)) {
        if ((int)$m[1] === $mon) {
            $events[] = [
                'date'        => sprintf('%04d-%02d-%02d', $year, (int)$m[1], (int)$m[2]),
                'title'       => 'License Renewal',
                'scope'       => 'dotloop',
                'description' => 'Real estate license renewal date.',
            ];
        }
    }
    // Birthday — shown on personal tab (scope: personal)
    if (!empty($ex['birthday']) && preg_match('/^(\d{2})-(\d{2})$/', $ex['birthday'], $m)) {
        if ((int)$m[1] === $mon) {
            $events[] = [
                'date'  => sprintf('%04d-%02d-%02d', $year, (int)$m[1], (int)$m[2]),
                'title' => 'My Birthday',
                'scope' => 'personal',
            ];
        }
    }
}

// ── DotLoop transaction dates ─────────────────────────────────────────────────
if (!dotloop_is_connected($email)) {
    echo json_encode(['events' => $events, 'connected' => false]);
    exit;
}

$tok = dotloop_get_tokens($email);
$profileId = $tok['profile_id'] ?? null;
if (!$profileId) {
    echo json_encode(['events' => $events, 'connected' => false]);
    exit;
}

// Fetch loops — DotLoop paginates; grab up to 3 pages (150 loops) to be practical.
$allLoops = [];
for ($page = 1; $page <= 3; $page++) {
    $res = dotloop_api($email, 'GET',
        "/profile/{$profileId}/loop?status=ACTIVE,UNDER_CONTRACT,CLOSED,SOLD&p={$page}&ppp=50"
    );
    if (!$res['ok']) break;
    $data = $res['data']['data'] ?? [];
    if (empty($data)) break;
    $allLoops = array_merge($allLoops, $data);
    if (count($data) < 50) break;
}

foreach ($allLoops as $loop) {
    $name = $loop['name'] ?? 'Transaction';

    // Closed / settlement date
    if (!empty($loop['closeDate'])) {
        $d = substr($loop['closeDate'], 0, 10);
        if (substr($d, 0, 7) === $month) {
            $events[] = [
                'date'  => $d,
                'title' => 'Closed: ' . $name,
                'scope' => 'dotloop',
            ];
        }
    }

    // Under contract / target date
    if (!empty($loop['targetDate'])) {
        $d = substr($loop['targetDate'], 0, 10);
        if (substr($d, 0, 7) === $month) {
            $label = in_array($loop['status'] ?? '', ['UNDER_CONTRACT', 'UNDER CONTRACT'])
                ? 'Under Contract: ' : 'Target Date: ';
            $events[] = [
                'date'  => $d,
                'title' => $label . $name,
                'scope' => 'dotloop',
            ];
        }
    }
}

usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

echo json_encode(['events' => $events, 'connected' => true]);
