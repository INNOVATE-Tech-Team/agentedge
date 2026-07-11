<?php
// Proxies the agent's stored personal ICS calendar URL, parses VEVENT blocks,
// and returns events in the same format as api/events.php (for calendar.js).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$email = $agent['email'] ?? '';

// Save URL if POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $url  = trim($body['url'] ?? '');
    if ($url !== '') {
        if (!preg_match('#^https://#i', $url)) {
            echo json_encode(['ok'=>false,'error'=>'URL must start with https://']); exit;
        }
        // A regular "view my calendar" link (e.g. calendar.google.com/calendar/u/1/r)
        // returns 200 with an HTML login page, not feed data — saving it "succeeds"
        // but silently never produces events. Probe the URL and require an actual
        // ICS feed before accepting it, so a wrong link fails loudly instead.
        $probeCtx = stream_context_create(['http'=>[
            'timeout' => 8, 'ignore_errors' => true, 'user_agent' => 'AgentEdge-CalSync/1.0',
        ]]);
        $probe = @file_get_contents($url, false, $probeCtx);
        if ($probe === false || stripos($probe, 'BEGIN:VCALENDAR') === false) {
            echo json_encode(['ok'=>false,'error'=>'That link doesn\'t look like a calendar feed. Use the "Secret address in iCal format" from your calendar settings — not the regular calendar page link.']);
            exit;
        }
    }
    try {
        $db = local_db();
        $db->prepare("INSERT INTO agent_extra (email, personal_cal_url, updated_at)
                      VALUES (?, ?, datetime('now'))
                      ON CONFLICT(email) DO UPDATE SET personal_cal_url=excluded.personal_cal_url, updated_at=excluded.updated_at")
           ->execute([$email, $url]);
        echo json_encode(['ok'=>true]); exit;
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>'Save failed']); exit;
    }
}

// GET — fetch and parse
$row = local_db()->prepare("SELECT personal_cal_url FROM agent_extra WHERE email=?");
$row->execute([$email]);
$r   = $row->fetch(PDO::FETCH_ASSOC);
$url = $r['personal_cal_url'] ?? '';

if ($url === '') {
    echo json_encode(['events'=>[],'has_url'=>false]); exit;
}

$ctx = stream_context_create(['http'=>[
    'timeout'       => 10,
    'ignore_errors' => true,
    'user_agent'    => 'AgentEdge-CalSync/1.0',
]]);
$ics = @file_get_contents($url, false, $ctx);

if ($ics === false || $ics === '') {
    echo json_encode(['events'=>[],'has_url'=>true,'error'=>'Could not fetch calendar. Check the URL and make sure it is public or use the secret ICS address.']);
    exit;
}

// Unfold RFC 5545 line continuations
$ics = preg_replace("/\r\n[ \t]/", '', $ics);
$ics = preg_replace("/\n[ \t]/", '', $ics);

$events = [];
preg_match_all('/BEGIN:VEVENT(.+?)END:VEVENT/s', $ics, $matches);

[$y, $m] = array_map('intval', explode('-', $month));

foreach ($matches[1] as $block) {
    // DTSTART — handle date-only (YYYYMMDD) and datetime (YYYYMMDDTHHmmss)
    $date = null;
    if (preg_match('/^DTSTART(?:;[^:]*)?:(\d{8})/m', $block, $mm)) {
        $ds   = $mm[1];
        $date = substr($ds,0,4).'-'.substr($ds,4,2).'-'.substr($ds,6,2);
    } elseif (preg_match('/^DTSTART(?:;[^:]*)?:(\d{4}-\d{2}-\d{2})/m', $block, $mm)) {
        $date = $mm[1];
    }
    if (!$date || substr($date, 0, 7) !== $month) continue;

    // SUMMARY
    $title = '';
    if (preg_match('/^SUMMARY:(.+)/m', $block, $mm)) {
        $title = trim(str_replace(['\,','\\n'], [',',' '], $mm[1]));
    }
    if ($title === '') continue;

    // DESCRIPTION (optional)
    $desc = '';
    if (preg_match('/^DESCRIPTION:(.+)/m', $block, $mm)) {
        $desc = trim(str_replace(['\,','\\n'], [',',' '], $mm[1]));
    }

    $events[] = [
        'date'        => $date,
        'title'       => $title,
        'description' => $desc,
        'scope'       => 'personal',
    ];
}

usort($events, fn($a,$b) => strcmp($a['date'], $b['date']));
echo json_encode(['events'=>$events,'has_url'=>true]);
