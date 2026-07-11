<?php
// Agent roster — pulled from innovate_roster (MC-assigned agents in agentedge.db)
// joined with tblstaff (Perfex) for email/phone. Falls back to full tblstaff
// active list if innovate_roster is empty.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
if (!current_agent()) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$c = cfg();
$base  = rtrim($c['crm_base'] ?? '', '/');
$token = $c['crm_token'] ?? '';

// ------------------------------------------------------------------
// Try external CRM first (if crm_base is set)
// ------------------------------------------------------------------
function fetch_json(string $url): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

if ($base !== '') {
    $url = $base . '/public/retention-roster';
    if ($token !== '') $url .= '?token=' . urlencode($token);
    $data = fetch_json($url);

    if ($data !== null) {
        // Build name→MC map from local innovate_roster. Also build a
        // first+last-word fallback index (e.g. "ron hyman" from "Ronald
        // Mark Hyman") for agents whose CRM name is a nickname/short form
        // of the sheet's legal name -- omitted when ambiguous (two sheet
        // entries share the same first+last), same safeguard already used
        // by the CRM-unreachable tblstaff path below.
        $localMC = [];
        $localMCFirstLast = [];
        $flCount = [];
        try {
            $rows = local_db()->query(
                "SELECT agent_name, state_code, market_center FROM innovate_roster WHERE active=1 AND market_center != ''"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $key = strtolower(trim($r['agent_name']));
                $mc  = trim($r['market_center']);
                $st  = trim($r['state_code']);
                $label = mc_label($mc, $st);
                $localMC[$key] = $label;
                $parts = explode(' ', $key);
                if (count($parts) >= 2) {
                    $fl = $parts[0] . ' ' . end($parts);
                    $flCount[$fl] = ($flCount[$fl] ?? 0) + 1;
                    $localMCFirstLast[$fl] = $label;
                }
            }
            foreach ($flCount as $fl => $cnt) {
                if ($cnt > 1) unset($localMCFirstLast[$fl]);
            }
        } catch (Exception $e) {}

        $agents = [];
        $seenNames = [];
        foreach ($data as $a) {
            $name = trim($a['fullName'] ?? ($a['email'] ?? 'Agent'));
            $nameLow = strtolower($name);
            if (isset($seenNames[$nameLow])) continue; // CRM feed occasionally returns dup records for the same agent
            $seenNames[$nameLow] = true;
            $mc = $localMC[$nameLow] ?? null;
            if ($mc === null) {
                $parts = explode(' ', $nameLow);
                if (count($parts) >= 2) {
                    $fl = $parts[0] . ' ' . end($parts);
                    $mc = $localMCFirstLast[$fl] ?? null;
                }
            }
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
}

// ------------------------------------------------------------------
// CRM unreachable — build from innovate_roster (SQLite) joined to
// tblstaff (Perfex) by name for email + phone.
// ------------------------------------------------------------------

// 1. Load all active agents from innovate_roster (includes stored email/phone)
$rosterRows = [];
try {
    $rosterRows = local_db()->query(
        "SELECT agent_name, state_code, market_center, email, phone FROM innovate_roster WHERE active=1"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Build market_centers canonical name lookup: lowercase(name) => "STATE - Name"
// So that if innovate_roster.market_center matches a market_centers.name (case-insensitive),
// we use the canonical label. This ensures admin renames/additions propagate automatically.
$mcCanonical = [];
try {
    $mcMasterRows = local_db()->query(
        "SELECT name, state_code FROM market_centers WHERE enabled=1"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mcMasterRows as $m) {
        $n = trim($m['name']);
        $s = trim($m['state_code']);
        $mcCanonical[strtolower($n)] = $s ? "$s - $n" : $n;
    }
} catch (Exception $e) {}

// 2. Load all active tblstaff agents, keyed by lowercased full name.
//    Also build a first+last index for agents whose roster name includes middle names.
$staffByName      = [];
$staffByFirstLast = [];  // first-word + last-word only; omitted when ambiguous
$staffAll = db_query(
    "SELECT staffid, email, firstname, lastname, phonenumber FROM tblstaff WHERE active=1",
    []
);
$firstLastCount = [];
foreach ($staffAll as $s) {
    $full = strtolower(trim($s['firstname'] . ' ' . $s['lastname']));
    $staffByName[$full] = $s;
    // Build first+last key (e.g. "jennifer young" from "jennifer rose young" in roster)
    $parts = explode(' ', $full);
    if (count($parts) >= 2) {
        $fl = $parts[0] . ' ' . end($parts);
        $firstLastCount[$fl] = ($firstLastCount[$fl] ?? 0) + 1;
        $staffByFirstLast[$fl] = $s;
    }
}
// Remove ambiguous first+last keys (two different people share same first+last)
foreach ($firstLastCount as $fl => $cnt) {
    if ($cnt > 1) unset($staffByFirstLast[$fl]);
}

// 3. Join roster to staff — exact match first, then first+last fallback
$agents = [];
$seenIds = [];

foreach ($rosterRows as $r) {
    $nameLow = strtolower(trim($r['agent_name']));
    $mc      = trim($r['market_center']);
    $st      = trim($r['state_code']);
    // Use canonical label from market_centers if name matches; otherwise build from roster fields
    $label   = $mcCanonical[strtolower($mc)] ?? mc_label($mc, $st);

    // Prefer email/phone stored directly in innovate_roster (set via backoffice edit)
    $storedEmail = trim($r['email'] ?? '');
    $storedPhone = trim($r['phone'] ?? '');

    // Always resolve the matching Perfex staff record (even when email/phone are
    // already stored on the roster row) so its staffid is known for de-duplication
    // against the "unmatched tblstaff agents" pass below.
    $staff = $staffByName[$nameLow] ?? null;
    if ($staff === null) {
        $parts = explode(' ', $nameLow);
        if (count($parts) >= 3) {
            $fl    = $parts[0] . ' ' . end($parts);
            $staff = $staffByFirstLast[$fl] ?? null;
        }
    }

    $id = $staff ? (int)$staff['staffid'] : null;
    if ($id && isset($seenIds[$id])) continue;
    if ($id) $seenIds[$id] = true;

    $agents[] = [
        'id'           => $id,
        'name'         => trim($r['agent_name']),
        'marketCenter' => $label,
        'brokerage'    => 'INNOVATE Real Estate',
        'email'        => $storedEmail !== '' ? $storedEmail : ($staff['email'] ?? ''),
        'phone'        => $storedPhone !== '' ? $storedPhone : ($staff['phonenumber'] ?? ''),
        'social'       => new stdClass(),
    ];
}

// 4. Also add any active tblstaff agents not in innovate_roster (unassigned MC)
foreach ($staffAll as $s) {
    if (isset($seenIds[(int)$s['staffid']])) continue;
    $agents[] = [
        'id'           => (int)$s['staffid'],
        'name'         => trim($s['firstname'] . ' ' . $s['lastname']),
        'marketCenter' => '',
        'brokerage'    => 'INNOVATE Real Estate',
        'email'        => $s['email'],
        'phone'        => $s['phonenumber'] ?? '',
        'social'       => new stdClass(),
    ];
}

// 5. Attach social links from agent_extra keyed by email
try {
    $extraRows = local_db()->query(
        "SELECT email, social_json FROM agent_extra WHERE social_json != '' AND social_json != '{}'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $socialByEmail = [];
    foreach ($extraRows as $ex) {
        $decoded = json_decode($ex['social_json'], true);
        if (is_array($decoded) && !empty($decoded)) {
            $socialByEmail[strtolower(trim($ex['email']))] = (object)$decoded;
        }
    }
    foreach ($agents as &$a) {
        $key = strtolower(trim($a['email'] ?? ''));
        if ($key !== '' && isset($socialByEmail[$key])) {
            $a['social'] = $socialByEmail[$key];
        }
    }
    unset($a);
} catch (Exception $e) {}

echo json_encode(['agents' => $agents, 'count' => count($agents), 'source' => 'perfex_db']);
