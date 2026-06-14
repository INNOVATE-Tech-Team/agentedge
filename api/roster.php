<?php
// Agent roster — a directory of agents. Demo mode returns sample agents;
// real mode reads active agents from tblstaff joined to tblre_transaction_agents.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
header('Content-Type: application/json');
if (!current_agent()) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

if (!empty(cfg()['demo'])) {
    echo json_encode(['agents' => [
        ['name' => 'Jordan Avery',  'email' => 'jordan@innovateonline.com', 'phone' => '(843) 555-0142', 'location' => 'Myrtle Beach, SC', 'languages' => 'English, Spanish'],
        ['name' => 'Sam Rivera',    'email' => 'sam@innovateonline.com',    'phone' => '(843) 555-0190', 'location' => 'Conway, SC',       'languages' => 'English'],
        ['name' => 'Taylor Brooks', 'email' => 'taylor@innovateonline.com', 'phone' => '(910) 555-0177', 'location' => 'Wilmington, NC',   'languages' => 'English'],
        ['name' => 'Casey Lin',     'email' => 'casey@innovateonline.com',  'phone' => '(843) 555-0118', 'location' => 'Charleston, SC',   'languages' => 'English, Mandarin'],
        ['name' => 'Morgan Patel',  'email' => 'morgan@innovateonline.com', 'phone' => '(843) 555-0203', 'location' => 'Myrtle Beach, SC', 'languages' => 'English, Hindi'],
        ['name' => 'Drew Sullivan', 'email' => 'drew@innovateonline.com',   'phone' => '(803) 555-0166', 'location' => 'Columbia, SC',     'languages' => 'English'],
    ]]);
    exit;
}

$out = ['agents' => []];
try {
    $rows = db_query(
        "SELECT s.firstname, s.lastname, s.email, s.phonenumber, a.city, a.state_province
         FROM tblstaff s
         JOIN tblre_transaction_agents a ON a.staffid = s.staffid
         WHERE s.active = 1
         ORDER BY s.firstname, s.lastname"
    );
    foreach ($rows as $r) {
        $loc = trim(trim(($r['city'] ?? '') . ', ' . ($r['state_province'] ?? '')), ', ');
        $out['agents'][] = [
            'name'      => trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? '')) ?: 'Agent',
            'email'     => $r['email'] ?? '',
            'phone'     => $r['phonenumber'] ?? '',
            'location'  => $loc,
            'languages' => '', // mapped from a languages lookup table later
        ];
    }
} catch (Throwable $e) {
    $out['error'] = 'query failed: ' . $e->getMessage();
}
echo json_encode($out);
