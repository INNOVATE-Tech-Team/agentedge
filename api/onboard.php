<?php
// Onboarding API — lists market centers + agents (for the sponsor picker) and
// creates a new agent in the bold360.vip CRM. Admin only. In preview/demo mode
// the create is simulated (no write) so the production CRM isn't polluted.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');

if (!current_agent()) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }
if (!is_admin())      { http_response_code(403); echo json_encode(['error' => 'admin only']); exit; }

$c     = cfg();
$base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token = $c['crm_token'] ?? '';

function fetch_arr(string $url): array {
    $ctx = stream_context_create(['http' => ['timeout' => 12, 'header' => "Accept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    $d = $raw === false ? null : json_decode($raw, true);
    return is_array($d) ? $d : [];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Market centers (public) + agents for the sponsor picker.
    $mcs = fetch_arr($base . '/public/directory/market-centers');
    $marketCenters = array_map(fn($m) => ['id' => $m['id'] ?? '', 'name' => $m['name'] ?? ''], $mcs);

    $rosterUrl = $base . '/public/retention-roster';
    if ($token !== '') $rosterUrl .= '?token=' . urlencode($token);
    $roster = fetch_arr($rosterUrl);
    $agents = [];
    foreach ($roster as $a) {
        if (!empty($a['id'])) $agents[] = ['id' => $a['id'], 'name' => $a['fullName'] ?? ''];
    }
    echo json_encode(['marketCenters' => $marketCenters, 'agents' => $agents]);
    exit;
}

// ---- Create -----------------------------------------------------------------
$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (trim($in['full_name'] ?? '') === '') {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Full name is required.']); exit;
}
if ($token === '') {
    echo json_encode(['ok' => false, 'error' => 'Onboarding isn\'t configured yet (no CRM token).']); exit;
}
if (!writes_enabled()) {
    echo json_encode(['ok' => false, 'demo' => true,
        'error' => 'Preview mode — the agent was NOT created (so we don\'t add test records to the live CRM). This works for real once real logins are on.']);
    exit;
}

$payload = [
    'full_name'        => $in['full_name'],
    'email'            => $in['email'] ?? null,
    'phone'            => $in['phone'] ?? null,
    'market_center_id' => $in['market_center_id'] ?? null,
    'sponsor_id'       => $in['sponsor_id'] ?? null,
    'start_date'       => $in['start_date'] ?? null,
    'role'             => $in['role'] ?? null,
    'notes'            => $in['notes'] ?? null,
];
$opts = ['http' => [
    'method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
    'header' => "Accept: application/json\r\nContent-Type: application/json\r\n",
    'content' => json_encode($payload),
]];
$url = $base . '/public/onboard-agent?token=' . urlencode($token);
$raw = @file_get_contents($url, false, stream_context_create($opts));
$d = $raw === false ? null : json_decode($raw, true);
if (is_array($d) && !empty($d['ok'])) {
    echo json_encode(['ok' => true, 'id' => $d['id'] ?? null, 'name' => $d['fullName'] ?? '']);
} else {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $d['detail'] ?? 'The CRM rejected the request.']);
}
