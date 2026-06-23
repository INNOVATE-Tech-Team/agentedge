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

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
[$year, $mon] = array_map('intval', explode('-', $month));

$rows = local_db()
    ->query("SELECT email, birthday, hire_date FROM agent_extra WHERE birthday != '' OR hire_date != ''")
    ->fetchAll(PDO::FETCH_ASSOC);

$events = [];

foreach ($rows as $r) {
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
