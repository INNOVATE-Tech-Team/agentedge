<?php
// Shared data helpers for the "Who Does What" team directory — read by the
// public who_does_what.php page and by the Back Office editor
// (admin_who_does_what.php / api/who_does_what_action.php), so the two never
// drift apart on what a row means or how tags/groups are validated.
if (defined('AGENTEDGE_WDW_LOADED')) return;
define('AGENTEDGE_WDW_LOADED', true);

// Single source of truth for who may currently reach Who Does What --
// referenced by both who_does_what.php's page gate and the Help Center
// shortcut's visibility (api/help_action.php), so a future company-wide
// rollout is a one-line change here instead of updating two places that
// could drift out of sync.
function wdw_is_available_to_current_user(): bool {
    return true;
}

const WDW_GROUPS = ['Leadership', 'Admins', 'Brokers'];
// A person can belong to more than one group (e.g. someone who is both
// leadership and a functioning broker) -- group_label stores this the same
// way tags stores multiple task tags: a comma-separated list, validated
// against WDW_GROUPS. A single value like "Leadership" is already a valid
// one-element list, so this needed no schema change and no data migration --
// every pre-existing single-group record just works unchanged.
// Task/help categories -- "what kind of help do you need?", not job titles.
// Leadership here is deliberately also a WDW_GROUPS value: the group answers
// "which part of the team?", this tag answers "I need leadership/management
// help" -- two different questions, kept separate on purpose. Money is kept
// alongside the more specific Commission because real recorded
// responsibilities (AP/AR, 1099s) are genuinely broader-than-commission
// finance work, not just commission questions. Likewise Accounting is kept
// alongside Money for the same AP/AR/1099 reason. Training is distinct from
// Onboarding (ongoing/topic training vs. new-agent setup specifically), and
// Agent Development is distinct from both (broader ongoing coaching/mentorship,
// not tied to a specific topic or to onboarding). Licensing is distinct from
// Compliance: a Broker-In-Charge's state license sponsorship/transfer/renewal
// role is a different real question from company-wide contract/Dotloop review.
const WDW_TAGS   = ['Onboarding', 'Training', 'Agent Development', 'Support', 'Leadership', 'Commission', 'Money', 'Accounting', 'Transactions', 'Compliance', 'Licensing', 'Contracts', 'Marketing', 'Tech'];
// Curated subset shown as quick-filter pills on the public page -- the full
// WDW_TAGS list stays the Back Office editor's complete vocabulary and the
// complete set of values search matches against; this only trims which ones
// get a top-level button, so agents see ~8 broad categories instead of all
// 14 internal distinctions. Leadership is deliberately excluded here even
// though it's a valid tag: it already has its own WDW_GROUPS filter, so a
// second "Leadership" button in the task row would be redundant clutter.
const WDW_PUBLIC_TAGS = ['Onboarding', 'Training', 'Commission', 'Transactions', 'Compliance', 'Licensing', 'Marketing', 'Tech'];

function wdw_tags_decode(string $csv): array {
    $out = [];
    foreach (explode(',', $csv) as $t) {
        $t = trim($t);
        if ($t !== '' && in_array($t, WDW_TAGS, true)) $out[] = $t;
    }
    return $out;
}

function wdw_tags_encode(array $tags): string {
    return implode(',', array_values(array_intersect(WDW_TAGS, $tags)));
}

function wdw_groups_decode(string $csv): array {
    $out = [];
    foreach (explode(',', $csv) as $g) {
        $g = trim($g);
        if ($g !== '' && in_array($g, WDW_GROUPS, true)) $out[] = $g;
    }
    return $out;
}

function wdw_groups_encode(array $groups): string {
    return implode(',', array_values(array_intersect(WDW_GROUPS, $groups)));
}

// CSS class suffix for the card's left accent border — see .wdw-accent-* in
// who_does_what.php. Falls back to the Admins (neutral) accent for any
// group value Back Office hasn't mapped yet, so a typo never renders broken.
function wdw_accent_class(string $group): string {
    switch ($group) {
        case 'Leadership': return 'wdw-accent-leadership';
        case 'Brokers':    return 'wdw-accent-brokers';
        default:            return 'wdw-accent-admins';
    }
}

function wdw_photo_url(array $row): ?string {
    $key = $row['photo_key'] ?? '';
    return $key !== '' ? 'api/who_does_what_action.php?action=photo&key=' . urlencode($key) : null;
}

function wdw_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $parts = array_filter($parts);
    if (!$parts) return '?';
    $first = mb_substr(reset($parts), 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

// Rows for the public page — active only, in curated display order.
function team_directory_list_active(): array {
    return local_db()->query(
        "SELECT * FROM team_directory WHERE enabled = 1 ORDER BY sort_ord, name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Rows for the Back Office editor — everything, active or not.
function team_directory_list_all(): array {
    return local_db()->query(
        "SELECT * FROM team_directory ORDER BY sort_ord, name"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// email → {name, phone} for known agents/staff, used purely as an
// autofill convenience in the Back Office "Add person" form so whoever
// maintains the directory isn't retyping a name/phone AgentEdge already
// has on file. Never joined at render time — see the schema comment in
// local_db.php for why the directory keeps its own copy.
function wdw_agent_lookup(): array {
    $rows = local_db()->query(
        "SELECT ar.email AS email, ai.full_name AS name, ai.phone AS phone
         FROM agent_roles ar
         LEFT JOIN agent_intake ai ON ai.email = ar.email
         ORDER BY ai.full_name"
    )->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $email = strtolower(trim($r['email']));
        if ($email === '') continue;
        $out[$email] = ['name' => $r['name'] ?? '', 'phone' => $r['phone'] ?? ''];
    }
    return $out;
}
