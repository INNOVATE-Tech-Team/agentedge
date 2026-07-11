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
// $localRows (unfiltered) is kept so agents with no CRM match can still be
// unioned into the feed below — innovate_roster has no email column, so
// name is the only join key we have against the CRM's fullName field.
$localMC   = [];
$localRows = [];
try {
    $localRows = local_db()->query(
        "SELECT agent_name, state_code, market_center FROM innovate_roster WHERE active=1"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($localRows as $r) {
        $key = strtolower(trim($r['agent_name']));
        $mc  = trim($r['market_center']);
        $st  = trim($r['state_code']);
        if ($mc !== '') $localMC[$key] = mc_label($mc, $st);
    }
} catch (\Exception $e) {}

$data = fetch_json($url);

if ($data !== null) {
    $agents = [];
    $seenNames = [];
    foreach ($data as $a) {
        $name = trim($a['fullName'] ?? ($a['email'] ?? 'Agent'));
        $key  = strtolower($name);
        if (isset($seenNames[$key])) continue; // CRM feed occasionally returns dup records for the same agent
        $seenNames[$key] = true;
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

    // Agents that exist in the local roster (e.g. added via Back Office →
    // Agent Roster → "+ Add Agent") but have no matching CRM record yet —
    // still show them here so they're not silently invisible to the team
    // until someone remembers to also add them in the CRM.
    foreach ($localRows as $r) {
        $name = trim($r['agent_name']);
        $key  = strtolower($name);
        if ($name === '' || isset($seenNames[$key])) continue;
        $seenNames[$key] = true;
        $mc = trim($r['market_center']);
        $st = trim($r['state_code']);
        $agents[] = [
            'id'           => null,
            'name'         => $name,
            'marketCenter' => $mc !== '' ? mc_label($mc, $st) : '',
            'brokerage'    => 'INNOVATE Real Estate',
            'email'        => '',
            'phone'        => '',
            'social'       => new stdClass(),
            'localOnly'    => true,
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
