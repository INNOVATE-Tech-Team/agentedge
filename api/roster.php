<?php
// Agent roster — pulled live from the bold360.vip CRM public directory
// (/public/directory/agents). Works from anywhere over HTTPS, so no database
// or firewall dependency. Falls back to sample data if the CRM is unreachable
// and we're in demo mode.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
header('Content-Type: application/json');
if (!current_agent()) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$c = cfg();
$url = $c['crm_roster_url'] ?? 'https://bold360.vip/api/public/directory/agents';

$ROLE_LABELS = [
    'mc_leader'       => 'Market Center Leader',
    'broker_in_charge'=> 'Broker In Charge',
    'recruiter'       => 'Agent',
    'agent'           => 'Agent',
    'retention_admin' => 'Admin',
    'super_admin'     => 'Admin',
];

function fetch_json(string $url): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "Accept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

$data = fetch_json($url);

if ($data !== null) {
    $agents = [];
    foreach ($data as $a) {
        $mcs = array_map(fn($m) => $m['name'] ?? '', $a['marketCenters'] ?? []);
        $mcs = array_values(array_filter($mcs));
        $agents[] = [
            'name'         => $a['fullName'] ?? ($a['email'] ?? 'Agent'),
            'email'        => $a['email'] ?? '',
            'role'         => $ROLE_LABELS[$a['role'] ?? ''] ?? ucfirst(str_replace('_', ' ', $a['role'] ?? '')),
            'marketCenter' => implode(', ', $mcs),
            'photo'        => $a['photoUrl'] ?? null,
        ];
    }
    echo json_encode(['agents' => $agents, 'source' => 'crm']);
    exit;
}

// CRM unreachable — sample data so the preview still renders.
if (!empty($c['demo'])) {
    echo json_encode(['agents' => [
        ['name' => 'Jordan Avery',  'email' => 'jordan@innovateonline.com', 'role' => 'Agent',                'marketCenter' => 'Myrtle Beach', 'photo' => null],
        ['name' => 'Sam Rivera',    'email' => 'sam@innovateonline.com',    'role' => 'Market Center Leader', 'marketCenter' => 'Conway',       'photo' => null],
        ['name' => 'Taylor Brooks', 'email' => 'taylor@innovateonline.com', 'role' => 'Agent',                'marketCenter' => 'Wilmington',   'photo' => null],
    ], 'source' => 'sample']);
    exit;
}

echo json_encode(['agents' => [], 'error' => 'Could not reach the CRM directory.']);
