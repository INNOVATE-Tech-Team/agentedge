<?php
// Outbound ICS calendar feed — serves company + training events as iCal.
// No session required. Access via ?token=<agent_cal_token>
// External calendar apps (Google, Apple, Outlook) subscribe to this URL.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/google_calendar.php';

$token = trim($_GET['token'] ?? '');
if ($token === '') { http_response_code(403); exit('Access denied'); }

// Look up agent by token
$db  = local_db();
$row = $db->prepare("SELECT email FROM agent_extra WHERE cal_token=? AND cal_token!=''");
$row->execute([$token]);
$r   = $row->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(403); exit('Invalid token'); }

// Date range: 2 months back to 8 months forward
$now       = time();
$startDate = date('Y-m-d', strtotime('-2 months', $now));
$endDate   = date('Y-m-d', strtotime('+8 months', $now));

$allEvents = [];

// --- Company events from intranet (one call per month) ---
$c    = cfg();
$iUrl = rtrim($c['intranet_events_url'] ?? '', '/');
$iTok = $c['intranet_events_token']     ?? '';

if ($iUrl && $iTok) {
    $cur = mktime(0, 0, 0, (int)date('n', strtotime($startDate)), 1, (int)date('Y', strtotime($startDate)));
    $end = mktime(0, 0, 0, (int)date('n', strtotime($endDate)),   1, (int)date('Y', strtotime($endDate)));
    while ($cur <= $end) {
        $mon = date('Y-m', $cur);
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'timeout'       => 8,
            'header'        => "Authorization: Bearer $iTok\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents("$iUrl?month=" . urlencode($mon), false, $ctx);
        $d   = $raw !== false ? json_decode($raw, true) : null;
        foreach ($d['events'] ?? [] as $ev) {
            if (!empty($ev['date'])) {
                $allEvents[] = [
                    'title'       => $ev['title']       ?? '',
                    'description' => $ev['description'] ?? '',
                    'location'    => $ev['location']    ?? '',
                    'date'        => $ev['date'],
                    'is_all_day'  => true,
                    'start_dt'    => $ev['date'],
                    'end_dt'      => null,
                    'uid'         => 'company-' . md5($ev['date'] . ($ev['title'] ?? '')),
                ];
            }
        }
        $cur = strtotime('+1 month', $cur);
    }
}

// --- Training events from Google Calendar ---
$keyFile = $c['gcal_key_file']    ?? (__DIR__ . '/../agentedge-calendar-key.json');
$calId   = $c['gcal_calendar_id'] ?? 'training@innovateonline.com';
$gToken  = gcal_access_token($keyFile);
if ($gToken) {
    $items = gcal_events($calId, $gToken, $startDate . 'T00:00:00Z', $endDate . 'T23:59:59Z');
    foreach ($items as $item) {
        if (($item['status'] ?? '') === 'cancelled') continue;
        $isAllDay = isset($item['start']['date']);
        $startDt  = $item['start']['date'] ?? ($item['start']['dateTime'] ?? '');
        $endDt    = $item['end']['date']   ?? ($item['end']['dateTime']   ?? '');
        $date     = substr($startDt, 0, 10);
        if (!$date) continue;
        $allEvents[] = [
            'title'       => $item['summary']     ?? 'Training Event',
            'description' => isset($item['description']) ? strip_tags($item['description']) : '',
            'location'    => $item['location']    ?? '',
            'date'        => $date,
            'is_all_day'  => $isAllDay,
            'start_dt'    => $startDt,
            'end_dt'      => $endDt,
            'uid'         => 'training-' . ($item['id'] ?? md5($startDt . ($item['summary'] ?? ''))),
        ];
    }
}

// Sort by date
usort($allEvents, fn($a, $b) => strcmp($a['date'], $b['date']));

// --- ICS helpers ---
function ics_escape(string $s): string {
    return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s);
}
function ics_date(string $ymd): string {
    return str_replace('-', '', substr($ymd, 0, 10));
}
function ics_datetime(string $dt): string {
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/', $dt, $m)) {
        return sprintf('%04d%02d%02dT%02d%02d%02dZ', $m[1],$m[2],$m[3],$m[4],$m[5],$m[6]);
    }
    return str_replace(['-', ':'], '', $dt);
}

// --- Output ICS ---
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="innovate-company-calendar.ics"');
header('Cache-Control: max-age=1800'); // calendar apps may cache; 30 min is fine

$host = $_SERVER['HTTP_HOST'] ?? 'agentedge.innovateonline.com';

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//INNOVATE AgentEdge//Company Calendar//EN\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
echo "X-WR-CALNAME:INNOVATE Company Calendar\r\n";
echo "X-WR-CALDESC:Company and training events from AgentEdge\r\n";

foreach ($allEvents as $idx => $ev) {
    $title    = $ev['title']    ?? '';
    $desc     = $ev['description'] ?? '';
    $location = $ev['location'] ?? '';
    $uid      = ($ev['uid'] ?? ('ev-' . $idx . '-' . md5($title . ($ev['date'] ?? '')))) . '@' . $host;
    $isAllDay = (bool)($ev['is_all_day'] ?? true);
    $startDt  = $ev['start_dt'] ?? $ev['date'];
    $endDt    = $ev['end_dt'];

    echo "BEGIN:VEVENT\r\n";
    echo "UID:" . ics_escape($uid) . "\r\n";

    if ($isAllDay) {
        $dtStart = ics_date($startDt);
        // DTEND for all-day is exclusive (next day). If end not set or equals start, add 1 day.
        if ($endDt && $endDt > $ev['date']) {
            $dtEnd = ics_date($endDt);
        } else {
            $dtEnd = date('Ymd', strtotime($ev['date'] . ' +1 day'));
        }
        echo "DTSTART;VALUE=DATE:$dtStart\r\n";
        echo "DTEND;VALUE=DATE:$dtEnd\r\n";
    } else {
        echo "DTSTART:" . ics_datetime($startDt) . "\r\n";
        echo "DTEND:"   . ics_datetime($endDt ?: $startDt) . "\r\n";
    }

    echo "SUMMARY:"     . ics_escape($title) . "\r\n";
    if ($desc)     echo "DESCRIPTION:" . ics_escape($desc)     . "\r\n";
    if ($location) echo "LOCATION:"    . ics_escape($location) . "\r\n";
    echo "END:VEVENT\r\n";
}

echo "END:VCALENDAR\r\n";
