<?php
// Extra Market Center access for event creation, granted by a Super Admin
// after a leader/BIC asks for it on mc_events.php. A grant is one row per
// (email, mc_slug); mc_slug '*' means the entire company.
// Grants only widen where the person can post/manage MC events -- they do not
// change the person's role or any other permission.
require_once __DIR__ . '/../local_db.php';

function mc_access_ensure_tables(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS mc_event_requests (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        email         TEXT NOT NULL,
        name          TEXT NOT NULL DEFAULT '',
        scope         TEXT NOT NULL,              -- 'mcs' | 'company'
        mc_slugs      TEXT NOT NULL DEFAULT '',   -- CSV, scope='mcs'
        status        TEXT NOT NULL DEFAULT 'pending',  -- pending|approved|denied
        decided_by    TEXT NOT NULL DEFAULT '',
        decided_at    TEXT,
        created_at    TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS mc_event_grants (
        email      TEXT NOT NULL,
        mc_slug    TEXT NOT NULL,
        granted_by TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        PRIMARY KEY (email, mc_slug)
    )");
}

// Slugs granted to $email (expanded to every enabled MC for a company grant).
function mc_access_granted_slugs(PDO $db, string $email): array {
    mc_access_ensure_tables($db);
    $st = $db->prepare("SELECT mc_slug FROM mc_event_grants WHERE LOWER(email)=LOWER(?)");
    $st->execute([$email]);
    $slugs = $st->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('*', $slugs, true)) {
        return $db->query("SELECT slug FROM market_centers WHERE enabled=1")->fetchAll(PDO::FETCH_COLUMN);
    }
    return $slugs;
}

function mc_access_has_company_grant(PDO $db, string $email): bool {
    mc_access_ensure_tables($db);
    $st = $db->prepare("SELECT 1 FROM mc_event_grants WHERE LOWER(email)=LOWER(?) AND mc_slug='*'");
    $st->execute([$email]);
    return (bool)$st->fetchColumn();
}

// my_mc_slugs() plus any approved grants.
function mc_access_effective_slugs(PDO $db, string $email, array $baseSlugs): array {
    return array_values(array_unique(array_merge($baseSlugs, mc_access_granted_slugs($db, $email))));
}

// Market Centers in the same state(s) as the person's own, that they can't
// already post to.
function mc_access_requestable(PDO $db, array $effectiveSlugs): array {
    $base = array_values($effectiveSlugs);
    if (!$base) return [];
    $ph = implode(',', array_fill(0, count($base), '?'));
    $st = $db->prepare("SELECT DISTINCT state_code FROM market_centers WHERE slug IN ($ph) AND COALESCE(state_code,'') != ''");
    $st->execute($base);
    $states = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$states) return [];
    $sph = implode(',', array_fill(0, count($states), '?'));
    $st = $db->prepare("SELECT slug, name, state_code FROM market_centers WHERE enabled=1 AND state_code IN ($sph) ORDER BY name");
    $st->execute($states);
    return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($r) => !in_array($r['slug'], $base, true)));
}

// MC Leaders and BICs (primary or secondary role) can post events to ANY
// Market Center -- their own, their whole state, or the entire company --
// without a grant (Darren, 2026-11-10). Posting only: editing/deleting an
// event still needs every one of its MCs (mc_access_effective_slugs) or to
// be its creator, see api/mc_events_action.php. Outside partners who only
// have mc_slugs assigned (can_manage_mc_events() without a leader role)
// still go through the request/grant flow above.
function mc_access_can_post_anywhere(): bool {
    return is_admin() || is_mc_leader() || is_bic() || my_secondary_mc_slugs() !== [];
}

// Slugs the signed-in person may POST new events to.
function mc_access_post_slugs(PDO $db, array $effectiveSlugs): array {
    if (!mc_access_can_post_anywhere()) return $effectiveSlugs;
    return $db->query("SELECT slug FROM market_centers WHERE enabled=1")->fetchAll(PDO::FETCH_COLUMN);
}
