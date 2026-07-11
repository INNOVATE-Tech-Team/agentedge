<?php
// Agent roster — sourced from the local innovate_roster table, the exact
// same rows backoffice_roster.php reads, so market center assignment and
// grouping here are guaranteed to match the back office. Contact info
// (email/phone/social) is enrichment only, layered from whichever of these
// is available, in order: stored directly on the roster row, Perfex
// (tblstaff), then the optional external CRM feed if crm_base is configured.
// None of those enrichment sources ever override market center — the CRM's
// naming in particular doesn't track the locally-curated market_centers list
// (e.g. it may call an office "Professional Drive" where Back Office has
// consolidated it under "Myrtle Beach"), so trusting it for MC assignment is
// exactly what caused this file's agents to disagree with Back Office.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');
if (!current_agent()) { http_response_code(401); echo json_encode(['error' => 'not signed in']); exit; }

$c     = cfg();
$base  = rtrim($c['crm_base'] ?? '', '/');
$token = $c['crm_token'] ?? '';

function fetch_json(string $url): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

// Builds a "first word + last word" key from a lowercased full name (e.g.
// "ron hyman" from "ronald mark hyman"), used to fuzzy-match an agent's
// legal/roster name against a shorter nickname or a name missing a middle
// name elsewhere. $counts tracks collisions so an ambiguous key (two
// different people reducing to the same first+last) gets dropped rather
// than silently matching the wrong person.
function first_last_key(string $nameLower): ?string {
    $parts = explode(' ', $nameLower);
    return count($parts) >= 2 ? $parts[0] . ' ' . end($parts) : null;
}
function build_first_last_index(array $byName): array {
    $index = [];
    $counts = [];
    foreach ($byName as $key => $row) {
        $fl = first_last_key($key);
        if ($fl === null) continue;
        $counts[$fl] = ($counts[$fl] ?? 0) + 1;
        $index[$fl]  = $row;
    }
    foreach ($counts as $fl => $cnt) {
        if ($cnt > 1) unset($index[$fl]);
    }
    return $index;
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

// market_centers canonical-name lookup: lowercase(name) -> "ST - Name". Falls
// back to formatting the roster's own market_center/state_code when a row's
// raw MC name doesn't (yet) match a master-list entry — e.g. a brand new MC
// or a typo that hasn't been cleaned up.
$mcCanonical = [];
try {
    foreach (local_db()->query("SELECT name, state_code FROM market_centers WHERE enabled=1")->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $n = trim($m['name']);
        $s = trim($m['state_code']);
        if ($n !== '') $mcCanonical[strtolower($n)] = mc_label($n, $s);
    }
} catch (\Exception $e) {}

function mc_display(array $mcCanonical, string $rawMc, string $state): string {
    $rawMc = trim($rawMc);
    if ($rawMc === '') return '';
    $aliasKey = strtoupper(trim($state)) . '|' . strtolower($rawMc);
    $name     = MC_DISPLAY_ALIASES[$aliasKey] ?? $rawMc;
    return $mcCanonical[strtolower($name)] ?? mc_label($name, $state);
}

// ------------------------------------------------------------------
// Contact-info enrichment sources, keyed by lowercased full name.
// ------------------------------------------------------------------

// Perfex (tblstaff) — the company's real staff directory.
$staffByName = [];
try {
    foreach (db_query("SELECT staffid, email, firstname, lastname, phonenumber FROM tblstaff WHERE active=1", []) as $s) {
        $full = strtolower(trim($s['firstname'] . ' ' . $s['lastname']));
        if ($full !== '') $staffByName[$full] = $s;
    }
} catch (\Exception $e) {}
$staffByFirstLast = build_first_last_index($staffByName);

// External CRM feed — only consulted if crm_base is configured; purely a
// contact-info fallback here, never a market-center or population source.
$crmByName = [];
if ($base !== '') {
    $url = $base . '/public/retention-roster';
    if ($token !== '') $url .= '?token=' . urlencode($token);
    $crmData = fetch_json($url);
    if (is_array($crmData)) {
        foreach ($crmData as $a) {
            $name = trim($a['fullName'] ?? ($a['email'] ?? ''));
            if ($name === '') continue;
            $key = strtolower($name);
            if (!isset($crmByName[$key])) $crmByName[$key] = $a; // first record wins on dup
        }
    }
}
$crmByFirstLast = build_first_last_index($crmByName);

// Social links overlay, keyed by lowercased email.
$socialByEmail = [];
try {
    foreach (local_db()->query("SELECT email, social_json FROM agent_extra WHERE social_json != '' AND social_json != '{}'")->fetchAll(PDO::FETCH_ASSOC) as $ex) {
        $decoded = json_decode($ex['social_json'], true);
        if (is_array($decoded) && !empty($decoded)) {
            $socialByEmail[strtolower(trim($ex['email']))] = (object)$decoded;
        }
    }
} catch (\Exception $e) {}

// Looks up $byName first by exact name, then by first+last fallback.
function lookup_by_name(array $byName, array $byFirstLast, string $nameLower): ?array {
    if (isset($byName[$nameLower])) return $byName[$nameLower];
    $fl = first_last_key($nameLower);
    return ($fl !== null && isset($byFirstLast[$fl])) ? $byFirstLast[$fl] : null;
}

// ------------------------------------------------------------------
// Population — one entry per active innovate_roster row (not deduped by
// name), so an agent licensed in multiple states shows up under every
// market center they're actually assigned to, matching how
// backoffice_roster.php groups its own state pages.
// ------------------------------------------------------------------
$agents = [];
try {
    $rows = local_db()->query(
        "SELECT agent_name, state_code, market_center, email, phone FROM innovate_roster WHERE active=1"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $name = trim($r['agent_name']);
        if ($name === '') continue;
        $nameLower = strtolower($name);

        $storedEmail = trim($r['email'] ?? '');
        $storedPhone = trim($r['phone'] ?? '');

        $staff = lookup_by_name($staffByName, $staffByFirstLast, $nameLower);
        $crm   = $storedEmail === '' && $staff === null ? lookup_by_name($crmByName, $crmByFirstLast, $nameLower) : null;

        $email = $storedEmail !== '' ? $storedEmail : ($staff['email'] ?? ($crm['email'] ?? ''));
        $phone = $storedPhone !== '' ? $storedPhone : ($staff['phonenumber'] ?? ($crm['phone'] ?? ''));

        $emailKey = strtolower(trim($email));
        $social   = $emailKey !== '' ? ($socialByEmail[$emailKey] ?? new stdClass()) : new stdClass();

        $agents[] = [
            'id'           => $staff['staffid'] ?? $crm['id'] ?? null,
            'name'         => $name,
            'marketCenter' => mc_display($mcCanonical, $r['market_center'] ?? '', $r['state_code'] ?? ''),
            'brokerage'    => 'INNOVATE Real Estate',
            'email'        => $email,
            'phone'        => $phone,
            'social'       => $social,
            'localOnly'    => $email === '' && $phone === '',
        ];
    }

    echo json_encode(['agents' => $agents, 'count' => count($agents), 'source' => 'local']);
} catch (\Exception $e) {
    echo json_encode(['agents' => [], 'error' => 'Could not reach the local roster.']);
}
