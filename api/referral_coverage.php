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
    "SELECT mls_name, counties, cities, townships, zips FROM mls_referral_coverage ORDER BY mls_name"
)->fetchAll(PDO::FETCH_ASSOC);

$out = array_map(function($r) {
    return [
        'mlsName'   => $r['mls_name'],
        'counties'  => split_list($r['counties']),
        'cities'    => split_list($r['cities']),
        'townships' => split_list($r['townships']),
        'zips'      => split_list($r['zips']),
    ];
}, $rows);

echo json_encode(['coverage' => $out]);
