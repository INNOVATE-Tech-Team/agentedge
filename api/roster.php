<?php
// Agent roster — pulled live from the bold360.vip CRM retention roster
// (/public/retention-roster). Works from anywhere over HTTPS, so no database
// or firewall dependency. Email/phone/socials come through only when a valid
// crm_token is configured. Falls back to sample data in demo mode.
require __DIR__ . '/../db.php';
require __DIR__ . '/../auth.php';
header('Content-Type: application/json');
if (!current_agent()) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$c = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';
$url   = $c['crm_roster_url'] ?? ($base . '/public/retention-roster');
if ($token !== '') { $url .= (strpos($url, '?') === false ? '?' : '&') . 'token=' . urlencode($token); }

function fetch_json(string $url): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "Accept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

$data = fetch_json($url);

if ($data !== null) {
    $agents = [];
    foreach ($data as $a) {
        $mc = $a['marketCenter'] ?? '';
        if ($mc === '' && !empty($a['marketCenters'])) {
            $mc = implode(', ', array_filter(array_map(fn($m) => $m['name'] ?? '', $a['marketCenters'])));
        }
        $agents[] = [
            'id'           => $a['id'] ?? null,
            'name'         => $a['fullName'] ?? ($a['email'] ?? 'Agent'),
            'marketCenter' => $mc,
            'brokerage'    => $a['brokerage'] ?? '',
            'email'        => $a['email'] ?? '',
            'phone'        => $a['phone'] ?? '',
            'social'       => $a['social'] ?? new stdClass(),
        ];
    }
    echo json_encode(['agents' => $agents, 'count' => count($agents), 'source' => 'crm']);
    exit;
}

// CRM unreachable — sample data so the preview still renders.
if (!empty($c['demo'])) {
    echo json_encode(['agents' => [
        ['id' => null, 'name' => 'Jordan Avery',  'marketCenter' => 'Myrtle Beach', 'brokerage' => 'INNOVATE Real Estate', 'email' => 'jordan@innovateonline.com', 'phone' => '(843) 555-0142', 'social' => ['facebook' => 'https://facebook.com/', 'instagram' => 'https://instagram.com/']],
        ['id' => null, 'name' => 'Sam Rivera',    'marketCenter' => 'Conway',       'brokerage' => 'INNOVATE Real Estate', 'email' => 'sam@innovateonline.com',    'phone' => '(843) 555-0187', 'social' => ['linkedin' => 'https://linkedin.com/']],
        ['id' => null, 'name' => 'Taylor Brooks', 'marketCenter' => 'Wilmington',   'brokerage' => 'INNOVATE Real Estate', 'email' => 'taylor@innovateonline.com', 'phone' => '', 'social' => new stdClass()],
    ], 'source' => 'sample']);
    exit;
}

echo json_encode(['agents' => [], 'error' => 'Could not reach the CRM roster.']);
