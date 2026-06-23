<?php
// Agent roster — pulled live from the bold360.vip CRM retention roster.
// Market Center column is overlaid from the local innovate_roster table
// (maintained in backoffice_roster.php) so MC assignments stay authoritative.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../auth.php';
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

// Build a name→MC map from the local roster (the authoritative source).
// Key is lowercased agent name; value is "ST - MC Name" or just "MC Name".
$localMC = [];
try {
    $rows = local_db()->query(
        "SELECT agent_name, state_code, market_center FROM innovate_roster WHERE active=1 AND market_center != ''"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $key = strtolower(trim($r['agent_name']));
        $mc  = trim($r['market_center']);
        $st  = trim($r['state_code']);
        $localMC[$key] = $st ? "$st - $mc" : $mc;
    }
} catch (\Exception $e) {}

$data = fetch_json($url);

if ($data !== null) {
    $agents = [];
    foreach ($data as $a) {
        $name = trim($a['fullName'] ?? ($a['email'] ?? 'Agent'));
        // Prefer local MC assignment; fall back to CRM field
        $mc = $localMC[strtolower($name)] ?? null;
        if ($mc === null) {
            $mc = $a['marketCenter'] ?? '';
            if ($mc === '' && !empty($a['marketCenters'])) {
                $mc = implode(', ', array_filter(array_map(fn($m) => $m['name'] ?? '', $a['marketCenters'])));
            }
        }
        $agents[] = [
            'id'           => $a['id'] ?? null,
            'name'         => $name,
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
