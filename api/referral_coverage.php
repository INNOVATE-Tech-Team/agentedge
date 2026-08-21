<?php
// Read-only feed of MLS referral coverage areas for the Agent Roster's
// referral-location filter (assets/roster.js). Any signed-in agent can read
// this -- it's just county/city/zip lists, not sensitive like mls_memberships.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../local_db.php';
header('Content-Type: application/json');

if (!current_agent()) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

// Splits a comma/newline/semicolon-separated free-text field into a clean,
// deduped list of trimmed values.
function split_list(string $s): array {
    $parts = preg_split('/[,;\n\r]+/', $s);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
    }
    return $out;
}

$rows = local_db()->query(
    "SELECT mls_name, agent_label, counties, cities, townships, zips, states, communities, market_centers FROM mls_referral_coverage ORDER BY mls_name"
)->fetchAll(PDO::FETCH_ASSOC);

$out = array_map(function($r) {
    return [
        'mlsName'      => $r['mls_name'],
        // The name an agent actually picks on their own profile -- several
        // rows can share one (e.g. Bright PA/NJ/DE/VA all "Bright MLS"), so
        // matching for the roster's referral filter goes through this, not
        // mlsName, with marketCenters (below) narrowing to the right row.
        'agentLabel'   => $r['agent_label'] !== '' ? $r['agent_label'] : $r['mls_name'],
        'counties'     => split_list($r['counties']),
        'cities'       => split_list($r['cities']),
        'townships'    => split_list($r['townships']),
        'zips'         => split_list($r['zips']),
        'states'       => split_list($r['states']),
        'communities'  => split_list($r['communities']),
        // Empty means this row applies regardless of the agent's market
        // center; a non-empty list restricts it to just those offices.
        'marketCenters' => split_list($r['market_centers']),
    ];
}, $rows);

echo json_encode(['coverage' => $out]);
