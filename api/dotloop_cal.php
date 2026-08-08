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
// Reads the shared-sync cache (dotloop_loops / dotloop_loop_participants) built
// by dotloop_sync_company_loops() — see lib/dotloop.php. Per-agent DotLoop
// connections don't exist anymore (an individual agent's own profile always
// returns 0 loops on DotLoop's side, confirmed live); every agent's closings
// are filtered from the one shared admin connection by participant email
// instead, the same way dotloop.php's "My Transactions" page works.
$lastSync = local_db()->query("SELECT value FROM dotloop_sync_state WHERE key = 'last_full_sync'")->fetchColumn();
if (!$lastSync) {
    echo json_encode(['events' => $events, 'connected' => false]);
    exit;
}

$emailGroup   = dotloop_email_group($email);
$placeholders = implode(',', array_fill(0, count($emailGroup), '?'));
$stmt = local_db()->prepare(
    "SELECT name, deal_stage, closing_date FROM dotloop_loops dl
     WHERE closing_date != '' AND EXISTS (
         SELECT 1 FROM dotloop_loop_participants p WHERE p.loop_id = dl.loop_id AND p.email IN ({$placeholders})
     )"
);
$stmt->execute($emailGroup);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $loop) {
    $ts = strtotime($loop['closing_date']);
    if ($ts === false) continue;
    $d = date('Y-m-d', $ts);
    if (substr($d, 0, 7) !== $month) continue;

    $isSold = $loop['deal_stage'] === 'SOLD';
    $events[] = [
        'date'  => $d,
        'title' => ($isSold ? 'Closed: ' : 'Under Contract: ') . ($loop['name'] ?: 'Transaction'),
        'scope' => 'dotloop',
        'type'  => $isSold ? 'closing' : 'under_contract',
    ];
}

usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));

echo json_encode(['events' => $events, 'connected' => true]);
