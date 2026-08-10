<?php
// Shared helpers for the Launch Schedule / Launch Coaching roster
// (launch_roster table) — used by launch_schedule.php, launch_coaching.php,
// and api/launch_roster_action.php.
//
// "Deals completed" toward the 3-transaction graduation mark can come from
// three places, in priority order:
//   1. deals_override    — a coach-typed number that always wins (a quick
//                           manual correction, no per-deal detail).
//   2. launch_roster_deals — individually logged transactions (date + note).
//                           This is the durable source: Darwin's synced
//                           figure is a YTD count that resets every January,
//                           so an agent who started LAUNCH in one calendar
//                           year and finishes in the next would silently
//                           lose progress if Darwin were the only source.
//                           Log each closed deal here as it happens and the
//                           count survives indefinitely.
//   3. Darwin's ytd_transaction_count — a live fallback for a linked agent
//      with nothing logged yet (fine for a cohort that starts and finishes
//      inside one calendar year, which is most of them).

function launch_roster_effective_deals(array $row): ?int {
    if ($row['deals_override'] !== null) return (int)$row['deals_override'];
    if (isset($row['logged_deals']) && (int)$row['logged_deals'] > 0) return (int)$row['logged_deals'];
    if (isset($row['darwin_deals']) && $row['darwin_agent_person_id']) return (int)round((float)$row['darwin_deals']);
    return null;
}

// Full roster with each row's logged-deal count and linked Darwin deal count joined in.
function launch_roster_fetch_all(PDO $db, string $orderBy = 'start_date, agent_name'): array {
    $rows = $db->query("
        SELECT lr.*, dsv.ytd_transaction_count AS darwin_deals, dsv.agent_name AS darwin_agent_name,
               (SELECT COUNT(*) FROM launch_roster_deals lrd WHERE lrd.roster_id = lr.id) AS logged_deals
        FROM launch_roster lr
        LEFT JOIN darwin_sales_volume dsv ON dsv.agent_person_id = lr.darwin_agent_person_id
        ORDER BY {$orderBy}
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['effective_deals'] = launch_roster_effective_deals($r);
    }
    unset($r);
    return $rows;
}

// The individually logged deals for one roster row, most recent first.
function launch_roster_deals_for(PDO $db, int $rosterId): array {
    $st = $db->prepare("SELECT * FROM launch_roster_deals WHERE roster_id=? ORDER BY deal_date DESC, id DESC");
    $st->execute([$rosterId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Flips any 'active' or 'confirmed' roster row with >=3 effective deals to
// 'graduated' — confirmed is just a pre-class invoicing checkpoint, not a
// pause, so deal-based graduation still applies. ('on_hold' is deliberately
// excluded — that status means progress is paused.) Cheap enough to run on
// every page load — only touches rows that have a deals_override, a linked
// Darwin agent, or at least one logged deal.
function launch_roster_recalc_graduation(PDO $db): void {
    $rows = $db->query("
        SELECT id, deals_override, darwin_agent_person_id,
               (SELECT COUNT(*) FROM launch_roster_deals lrd WHERE lrd.roster_id = lr.id) AS logged_deals
        FROM launch_roster lr
        WHERE status IN ('active', 'confirmed') AND (
            deals_override IS NOT NULL
            OR darwin_agent_person_id IS NOT NULL
            OR EXISTS (SELECT 1 FROM launch_roster_deals lrd WHERE lrd.roster_id = lr.id)
        )
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return;

    $volSt = $db->prepare("SELECT ytd_transaction_count FROM darwin_sales_volume WHERE agent_person_id=?");
    $upd   = $db->prepare("UPDATE launch_roster SET status='graduated', graduated_at=date('now'), updated_at=datetime('now') WHERE id=?");
    foreach ($rows as $r) {
        $deals = $r['deals_override'] !== null ? (int)$r['deals_override'] : null;
        if ($deals === null && (int)$r['logged_deals'] > 0) $deals = (int)$r['logged_deals'];
        if ($deals === null && $r['darwin_agent_person_id']) {
            $volSt->execute([$r['darwin_agent_person_id']]);
            $v = $volSt->fetchColumn();
            $deals = $v !== false ? (int)round((float)$v) : null;
        }
        if ($deals !== null && $deals >= 3) $upd->execute([$r['id']]);
    }
}

// Same "STATE|lowercased raw name" -> canonical display name aliasing as
// api/roster.php's MC_DISPLAY_ALIASES (kept as a separate copy, not shared,
// since api/roster.php is a live-traffic endpoint this feature shouldn't
// risk touching) — e.g. Back Office has consolidated "Professional Drive"
// under "Myrtle Beach", but innovate_roster's raw market_center still says
// the old name for rows that predate the rename.
const LR_MC_DISPLAY_ALIASES = [
    'SC|professional drive' => 'Myrtle Beach',
];

function lr_first_last_key(string $nameLower): ?string {
    $parts = explode(' ', $nameLower);
    return count($parts) >= 2 ? $parts[0] . ' ' . end($parts) : null;
}

// Builds a "first + last name" fallback index from a byName map, dropping
// any key that collides between two different people so an ambiguous
// nickname/missing-middle-name never silently matches the wrong agent.
function lr_build_first_last_index(array $byName): array {
    $index = [];
    $counts = [];
    foreach ($byName as $key => $row) {
        $fl = lr_first_last_key($key);
        if ($fl === null) continue;
        $counts[$fl] = ($counts[$fl] ?? 0) + 1;
        $index[$fl]  = $row;
    }
    foreach ($counts as $fl => $cnt) {
        if ($cnt > 1) unset($index[$fl]);
    }
    return $index;
}

function lr_lookup_by_name(array $byName, array $byFirstLast, string $nameLower): ?array {
    if (isset($byName[$nameLower])) return $byName[$nameLower];
    $fl = lr_first_last_key($nameLower);
    return ($fl !== null && isset($byFirstLast[$fl])) ? $byFirstLast[$fl] : null;
}

// Same idea as lr_build_first_last_index(), but for a byName map whose
// values are already a LIST of rows per exact name (innovate_roster's
// rosterByName, one row per state an agent is licensed in) rather than a
// single row. Needed because a launch_roster entry is often typed as just
// "First Last" while the real roster carries a middle name (e.g. "Fernanda
// Azevedo" vs. the roster's "Fernanda Silva Azevedo") — an exact-name match
// misses that, but reducing both sides to first+last still lines them up.
function lr_build_first_last_index_multi(array $byNameLists): array {
    $index = [];
    $counts = [];
    foreach ($byNameLists as $key => $rows) {
        $fl = lr_first_last_key($key);
        if ($fl === null) continue;
        $counts[$fl] = ($counts[$fl] ?? 0) + 1;
        $index[$fl]  = $rows;
    }
    foreach ($counts as $fl => $cnt) {
        if ($cnt > 1) unset($index[$fl]);
    }
    return $index;
}

function lr_mc_display(array $mcCanonical, string $rawMc, string $state): string {
    $rawMc = trim($rawMc);
    if ($rawMc === '') return '';
    $aliasKey = strtoupper(trim($state)) . '|' . strtolower($rawMc);
    $name     = LR_MC_DISPLAY_ALIASES[$aliasKey] ?? $rawMc;
    return $mcCanonical[strtolower($name)] ?? mc_label($name, $state);
}

// Builds the full contact-enrichment picture for matching a launch_roster
// agent to the real Agent Roster (agents.innovateonline.com/roster.php) —
// same priority chain that page uses: phone/MC stored directly on the
// innovate_roster row, then Perfex (tblstaff) by name, then the external
// CRM feed as a last resort. Built once per page load.
function launch_roster_build_directory(PDO $db): array {
    $rosterByName = [];
    foreach ($db->query("SELECT agent_name, state_code, market_center, email, phone FROM innovate_roster WHERE active=1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rosterByName[strtolower(trim($r['agent_name']))][] = $r;
    }
    $rosterByFirstLast = lr_build_first_last_index_multi($rosterByName);

    // Perfex (tblstaff) — via db_query_safe() (not db_query()/db(), which hard-exit()s
    // the whole request on connection failure) so a Perfex outage degrades this
    // feature to "no phone" instead of taking down Launch Schedule entirely.
    $staffByName = [];
    if (function_exists('db_query_safe')) {
        foreach (db_query_safe("SELECT staffid, email, firstname, lastname, phonenumber FROM tblstaff WHERE active=1", []) as $s) {
            $full = strtolower(trim($s['firstname'] . ' ' . $s['lastname']));
            if ($full !== '') $staffByName[$full] = $s;
        }
    }
    $staffByFirstLast = lr_build_first_last_index($staffByName);

    // External CRM feed — only consulted as a last resort, same as api/roster.php.
    $crmByName = [];
    $c     = function_exists('cfg') ? cfg() : [];
    $base  = rtrim($c['crm_base'] ?? '', '/');
    $token = $c['crm_token'] ?? '';
    if ($base !== '') {
        $url = $base . '/public/retention-roster';
        if ($token !== '') $url .= '?token=' . urlencode($token);
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]);
        $raw = @file_get_contents($url, false, $ctx);
        $crmData = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($crmData)) {
            foreach ($crmData as $a) {
                $name = trim($a['fullName'] ?? ($a['email'] ?? ''));
                if ($name === '') continue;
                $key = strtolower($name);
                if (!isset($crmByName[$key])) $crmByName[$key] = $a;
            }
        }
    }
    $crmByFirstLast = lr_build_first_last_index($crmByName);

    $mcCanonical = [];
    try {
        foreach ($db->query("SELECT name, state_code FROM market_centers WHERE enabled=1")->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $n = trim($m['name']);
            $s = trim($m['state_code']);
            if ($n !== '') $mcCanonical[strtolower($n)] = mc_label($n, $s);
        }
    } catch (\Exception $e) {}

    return compact('rosterByName', 'rosterByFirstLast', 'staffByName', 'staffByFirstLast', 'crmByName', 'crmByFirstLast', 'mcCanonical');
}

// Resolves a launch_roster agent to their real Market Center + phone,
// preferring a roster row in the same state when an agent has more than
// one (licensed in multiple states). Falls back to unmatched (caller
// decides what to show, e.g. the launch_roster row's free-text office).
function launch_roster_resolve_agent(array $directory, string $name, string $state): array {
    $nameLower  = strtolower(trim($name));
    $candidates = $directory['rosterByName'][$nameLower] ?? null;
    if ($candidates === null) {
        $fl = lr_first_last_key($nameLower);
        $candidates = ($fl !== null ? $directory['rosterByFirstLast'][$fl] ?? null : null) ?? [];
    }
    if (!$candidates) return ['market_center' => '', 'phone' => '', 'matched' => false];

    $stateUp = strtoupper(trim($state));
    $row = $candidates[0];
    foreach ($candidates as $c) {
        if ($stateUp !== '' && strtoupper($c['state_code']) === $stateUp) { $row = $c; break; }
    }

    // Gated on phone specifically (this function never returns email), not on
    // email being blank — an agent can easily have one field stored directly
    // on the roster row but not the other (e.g. email on file, phone blank),
    // and gating on the wrong field means the Perfex/CRM fallback never even
    // gets attempted even though it has the phone number this call needs.
    $phone = trim($row['phone'] ?? '');
    if ($phone === '') {
        $staff = lr_lookup_by_name($directory['staffByName'], $directory['staffByFirstLast'], $nameLower);
        $crm   = $staff === null ? lr_lookup_by_name($directory['crmByName'], $directory['crmByFirstLast'], $nameLower) : null;
        $phone = $staff['phonenumber'] ?? ($crm['phone'] ?? '');
    }

    return [
        'market_center' => lr_mc_display($directory['mcCanonical'], $row['market_center'] ?? '', $row['state_code'] ?? ''),
        'phone'         => $phone,
        'matched'       => true,
    ];
}

// Candidate Darwin agents for the "link to Darwin" search box, by name.
function launch_roster_darwin_search(PDO $db, string $q): array {
    $q = trim($q);
    if ($q === '') return [];
    $st = $db->prepare("
        SELECT agent_person_id, agent_name, agent_email, office_name
        FROM darwin_cap_progress
        WHERE agent_name LIKE ?
        ORDER BY agent_name
        LIMIT 15
    ");
    $st->execute(['%' . $q . '%']);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Auto-links every not-yet-linked roster row to Darwin by name, so coaches
// don't have to manually search/link each agent one by one. Only ever
// auto-links an UNAMBIGUOUS match (exact name, or first+last fallback,
// each with the standard "two different people collapse to the same key ->
// drop it" ambiguity guard) — anything uncertain is left for the manual
// search on Launch Coaching. Cheap and idempotent (only ever sets
// darwin_agent_person_id on rows that currently have none), so it's safe
// to run on every Launch Coaching page load.
function launch_roster_auto_link_darwin(PDO $db): int {
    $byName = [];
    $counts = [];
    foreach ($db->query("SELECT agent_person_id, agent_name FROM darwin_cap_progress WHERE is_active_agent=1")->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $key = strtolower(trim($d['agent_name']));
        if ($key === '') continue;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        $byName[$key] = $d;
    }
    foreach ($counts as $key => $cnt) {
        if ($cnt > 1) unset($byName[$key]); // two different Darwin agents share this name — ambiguous, skip
    }
    $byFirstLast = lr_build_first_last_index($byName);

    $unlinked = $db->query("SELECT id, agent_name FROM launch_roster WHERE darwin_agent_person_id IS NULL AND status != 'dropped'")->fetchAll(PDO::FETCH_ASSOC);
    if (!$unlinked) return 0;

    $upd = $db->prepare("UPDATE launch_roster SET darwin_agent_person_id=?, updated_at=datetime('now') WHERE id=?");
    $linked = 0;
    foreach ($unlinked as $r) {
        $match = lr_lookup_by_name($byName, $byFirstLast, strtolower(trim($r['agent_name'])));
        if ($match) {
            $upd->execute([(int)$match['agent_person_id'], $r['id']]);
            $linked++;
        }
    }
    if ($linked) launch_roster_recalc_graduation($db);
    return $linked;
}
