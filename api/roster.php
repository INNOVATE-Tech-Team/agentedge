<?php
// Agent roster — sourced from the local innovate_roster table, the exact
// same rows backoffice_roster.php reads, so market center assignment and
// grouping here are guaranteed to match the back office. The bold360.vip CRM
// feed is used only to enrich contact info (email/phone/social/brokerage)
// via a best-effort name match — it is deliberately NOT used to determine
// market center, because its naming doesn't track the locally-curated
// market_centers list (e.g. it may call an office "Professional Drive" where
// the back office has consolidated it under "Myrtle Beach").
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

// Known market-center display aliases — same physical office, different name
// in the data (e.g. a legacy/street-address name that predates a rename in
// the back office's market_centers list). Keyed by "STATE|lowercased raw
// name" -> the canonical name to display here. This only affects what
// agents see on this page; the underlying market_centers/innovate_roster
// rows are left alone, since Back Office may still track them as separate
// records for BIC/retention purposes. Extend as more duplicates turn up.
const MC_DISPLAY_ALIASES = [
    'SC|professional drive' => 'Myrtle Beach',
];
function mc_display_name(string $name, string $state): string {
    $key = strtoupper(trim($state)) . '|' . strtolower(trim($name));
    return MC_DISPLAY_ALIASES[$key] ?? $name;
}

// Best-effort contact-info lookup from the CRM feed, keyed by lowercased
// full name. Agents with no match here just show without email/phone/
// social/brokerage until their CRM record catches up — that's the "LOCAL"
// badge roster.js renders when localOnly is true.
$crmByName = [];
$data = fetch_json($url);
if (is_array($data)) {
    foreach ($data as $a) {
        $name = trim($a['fullName'] ?? ($a['email'] ?? ''));
        if ($name === '') continue;
        $key = strtolower($name);
        if (isset($crmByName[$key])) continue; // CRM feed occasionally returns dup records for the same agent
        $crmByName[$key] = $a;
    }
}

$agents = [];
try {
    $rows = local_db()->query(
        "SELECT agent_name, state_code, market_center FROM innovate_roster WHERE active=1"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Local dev preview: when there's no real roster data yet (e.g. a fresh
    // dev database) and demo mode is on, show sample agents instead of an
    // empty page. Scoped to "no local rows" now that population comes from
    // innovate_roster rather than CRM reachability.
    if (!$rows && !empty($c['demo'])) {
        echo json_encode(['agents' => [
            ['id' => null, 'name' => 'Jordan Avery',  'marketCenter' => 'SC - Myrtle Beach', 'brokerage' => 'INNOVATE Real Estate', 'email' => 'jordan@innovateonline.com', 'phone' => '(843) 555-0142', 'social' => ['facebook' => 'https://facebook.com/', 'instagram' => 'https://instagram.com/'], 'localOnly' => false],
            ['id' => null, 'name' => 'Sam Rivera',    'marketCenter' => 'SC - Conway',       'brokerage' => 'INNOVATE Real Estate', 'email' => 'sam@innovateonline.com',    'phone' => '(843) 555-0187', 'social' => ['linkedin' => 'https://linkedin.com/'], 'localOnly' => false],
            ['id' => null, 'name' => 'Taylor Brooks', 'marketCenter' => 'NC - Wilmington',   'brokerage' => 'INNOVATE Real Estate', 'email' => 'taylor@innovateonline.com', 'phone' => '', 'social' => new stdClass(), 'localOnly' => false],
        ], 'count' => 3, 'source' => 'sample']);
        exit;
    }

    // One entry per local row (not deduped by name) — an agent licensed in
    // multiple states gets one row per state here, same as backoffice_roster.php,
    // so they show up under every market center group they're actually assigned to.
    foreach ($rows as $r) {
        $name = trim($r['agent_name']);
        if ($name === '') continue;
        $st  = trim($r['state_code']);
        $mc  = trim($r['market_center']);
        $crm = $crmByName[strtolower($name)] ?? null;

        $agents[] = [
            'id'           => $crm['id'] ?? null,
            'name'         => $name,
            'marketCenter' => $mc !== '' ? mc_label(mc_display_name($mc, $st), $st) : '',
            'brokerage'    => $crm['brokerage'] ?? 'INNOVATE Real Estate',
            'email'        => $crm['email'] ?? '',
            'phone'        => $crm['phone'] ?? '',
            'social'       => $crm['social'] ?? new stdClass(),
            'localOnly'    => $crm === null,
        ];
    }

    echo json_encode(['agents' => $agents, 'count' => count($agents), 'source' => 'local']);
} catch (\Exception $e) {
    echo json_encode(['agents' => [], 'error' => 'Could not reach the local roster.']);
}
