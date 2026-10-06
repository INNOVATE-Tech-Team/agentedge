<?php
// Closings Tracker — commission calculation engine + lead-source
// normalization. This is the one place GCI/payouts get computed (see the
// `closings` table comment in local_db.php) — every view (list rows,
// rollups, exports) must call closing_calc() rather than re-deriving these
// numbers itself, so a fix here fixes every view at once.
if (defined('AGENTEDGE_CLOSINGS_LOADED')) return;
define('AGENTEDGE_CLOSINGS_LOADED', true);
require_once __DIR__ . '/fub.php'; // fub_system_headers()

const CLOSING_STATUS_LABELS = [
    'pending'      => 'Pending',
    'closed'       => 'Closed',
    'fell_through' => 'Fell Through',
];

function closing_status_label(string $status): string {
    return CLOSING_STATUS_LABELS[$status] ?? ucfirst($status);
}

// Canonical lead-source taxonomy — collapses the free-text spelling/casing
// drift seen in the legacy per-agent Closings sheet (e.g. "Referal Exchange"
// vs "Referral Exchange", "past client" vs "Past Client") into one enum
// every team shares, rather than each team inventing its own list.
const LEAD_SOURCE_LABELS = [
    'soi'               => 'SOI',
    'past_client'       => 'Past Client',
    'referral'          => 'Referral',
    'outside_referral'  => 'Outside Referral',
    'referral_exchange' => 'Referral Exchange',
    'ideal_agent'       => 'Ideal Agent',
    'agent_pronto'      => 'Agent Pronto',
    'dwt'               => 'DWT',
    'website'           => 'Website',
    'sign_call'         => 'Sign Call',
    'open_house'        => 'Open House',
    'unknown'           => 'Unknown',
];

// Maps a raw free-text value (typed manually, or pulled from FUB's `source`
// field) to a canonical slug. Matches loosely (case/spacing-insensitive,
// substring) since neither agents nor FUB agree on exact spelling.
function normalize_lead_source(?string $raw): string {
    $s = strtolower(trim((string)$raw));
    if ($s === '') return 'unknown';
    $s = trim(preg_replace('/[^a-z0-9]+/', ' ', $s));

    static $patterns = [
        'referral exchange' => 'referral_exchange',
        'referal exchange'  => 'referral_exchange',
        'outside referral'  => 'outside_referral',
        'ideal agent'       => 'ideal_agent',
        'agent pronto'      => 'agent_pronto',
        'past client'       => 'past_client',
        'sign call'         => 'sign_call',
        'open house'        => 'open_house',
        'sphere'            => 'soi',
        'soi'               => 'soi',
        'dwt'               => 'dwt',
        'website'           => 'website',
        'referral'          => 'referral',
    ];
    foreach ($patterns as $needle => $slug) {
        if (str_contains($s, $needle)) return $slug;
    }
    return 'unknown';
}

// Resolves the effective commission/DWT defaults for one agent on one team:
// an agent-specific row wins, else the team-wide default row
// (agent_email=''), else zero. A per-closing value can still override both —
// commission varies deal to deal even under one default.
function get_split_rule(PDO $db, int $teamId, string $agentEmail): array {
    $agentEmail = strtolower(trim($agentEmail));
    $stmt = $db->prepare("SELECT default_commission_rate, default_dwt_percent FROM team_split_rules WHERE team_id=? AND agent_email=?");
    $stmt->execute([$teamId, $agentEmail]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $stmt->execute([$teamId, '']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return [
        'default_commission_rate' => (float)($row['default_commission_rate'] ?? 0),
        'default_dwt_percent'     => (float)($row['default_dwt_percent'] ?? 0),
    ];
}

// The one canonical commission calculation (Closings Tracker spec §4).
// commission_rate / dwt_percent / outgoing_referral_percent are fractions
// (0.03, not 3).
function closing_calc(array $row): array {
    $price  = (float)($row['sale_price'] ?? 0);
    $rate   = (float)($row['commission_rate'] ?? 0);
    $bonus  = (float)($row['bonus_amount'] ?? 0);
    $refPct = (float)($row['outgoing_referral_percent'] ?? 0);
    $dwtPct = (float)($row['dwt_percent'] ?? 0);

    $gci              = ($price * $rate) + $bonus;
    $outgoingReferral = $gci * $refPct;
    $netGci           = $gci - $outgoingReferral;
    $teamMemberPayout = $netGci * (1 - $dwtPct);
    $dwtPayout        = $netGci * $dwtPct;

    return [
        'gci'                      => $gci,
        'outgoing_referral_amount' => $outgoingReferral,
        'net_gci'                  => $netGci,
        'team_member_payout'       => $teamMemberPayout,
        'dwt_payout'               => $dwtPayout,
    ];
}

// Follow Up Boss lead-source lookup by client email (spec §6.1 — one-way FUB
// -> AgentEdge, no write-back). Returns ['id'=>..., 'source'=>...] for the
// first matching Person, or null if FUB isn't configured, the lookup fails,
// or no contact matches.
function fub_lookup_person(string $email): ?array {
    $email = trim($email);
    if ($email === '') return null;
    $c      = cfg();
    $apiKey = $c['fub_api_key'] ?? '';
    if ($apiKey === '') return null;

    $auth = base64_encode($apiKey . ':');
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => 10,
        'header'        => "Authorization: Basic {$auth}\r\nAccept: application/json\r\n" . fub_system_headers($c),
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents('https://api.followupboss.com/v1/people?email=' . urlencode($email), false, $ctx);
    if ($raw === false) return null;
    $d = json_decode($raw, true);
    if (!is_array($d) || empty($d['people'][0])) return null;

    $person = $d['people'][0];
    return [
        'id'     => (string)($person['id'] ?? ''),
        'source' => (string)($person['source'] ?? ''),
    ];
}
