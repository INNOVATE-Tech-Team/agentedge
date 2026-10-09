<?php
// Shared logic for Company Email — used by api/company_email_action.php (the
// web endpoint) and cron/process_email_queue.php (the scheduled-send worker),
// so recipient resolution/merge-vars/signature building stay in one place.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/launch_roster.php';

// Pull the full agent roster — the authoritative "every active agent, with
// their Market Center" list (agent_roles/notification_prefs only cover agents
// who've logged into AgentEdge or been explicitly assigned a role, a much
// smaller set). Sourced from AgentEdge's own local innovate_roster table —
// the same table api/roster.php's admin Roster page queries — NOT the remote
// bold360.vip/coastline-server CRM API this function used to call.
//
// Changed 2026-07-19 after a real incident: a Company Email sent to 5 Market
// Centers reached only 3 recipients instead of 50+. The remote roster's agent
// Market Center assignments were badly out of date (0-1 agents mapped per
// office for 4 of the 5, 142/394 agents with no Market Center at all), while
// innovate_roster — kept current for the admin Roster page — had the correct
// 54 agents across those same five offices. Function name/shape (array of
// email/fullName/marketCenter/phone) kept as-is since every caller only ever
// used those fields; 'brokerage' is no longer available (not a column here)
// so {{brokerage}} now renders blank rather than a stale/wrong value.
function ce_fetch_crm_roster(): array {
    $rows = local_db()->query(
        "SELECT agent_name, market_center, email, phone FROM innovate_roster WHERE active=1"
    )->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'email'        => $r['email'] ?? '',
            'fullName'     => $r['agent_name'] ?? '',
            'marketCenter' => $r['market_center'] ?? '',
            'phone'        => $r['phone'] ?? '',
        ];
    }
    return $out;
}

// LAUNCH Schedule classes (launch_roster.start_date groups) available for
// narrowing the "LAUNCH Agents" audience to one specific class instead of
// every LAUNCH agent company-wide — e.g. reminders for just the agents
// starting the August 10 class. A class more than 10 weeks past its start
// date is dropped from the list — it's over, not worth cluttering the picker.
function ce_launch_classes(): array {
    $db = local_db();
    $cutoff = (new DateTime('now'))->modify('-10 weeks')->format('Y-m-d');
    $st = $db->prepare(
        "SELECT start_date, COUNT(*) AS cnt FROM launch_roster
         WHERE start_date != '' AND start_date >= ? AND status != 'dropped'
         GROUP BY start_date ORDER BY start_date"
    );
    $st->execute([$cutoff]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ts   = strtotime($r['start_date']);
        $cnt  = (int)$r['cnt'];
        $out[] = [
            'date'  => $r['start_date'],
            'label' => ($ts ? date('F j, Y', $ts) : $r['start_date']) . ' (' . $cnt . ' agent' . ($cnt === 1 ? '' : 's') . ')',
            'count' => $cnt,
        ];
    }
    return $out;
}

// Resolves one LAUNCH Schedule class to real, emailable recipients.
// launch_roster rarely has agent_email filled in directly, so this reuses
// the same Agent Roster (innovate_roster) name-matching chain
// launch_schedule.php itself uses to find each agent's real email — an
// agent with no discoverable email anywhere is silently skipped (no way to
// reach them by email at all), same as a bounced/missing-email roster row
// would be for any other audience.
function ce_resolve_launch_class(string $startDate): array {
    $db = local_db();
    $st = $db->prepare("SELECT agent_name, agent_email FROM launch_roster WHERE start_date=? AND status != 'dropped'");
    $st->execute([$startDate]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    $directory = launch_roster_build_directory($db);
    $out = [];
    foreach ($rows as $r) {
        $email = strtolower(trim($r['agent_email'] ?? ''));
        if ($email === '') {
            $nameLower  = strtolower(trim($r['agent_name']));
            $candidates = $directory['rosterByName'][$nameLower] ?? null;
            if ($candidates === null) {
                $fl = lr_first_last_key($nameLower);
                $candidates = ($fl !== null ? $directory['rosterByFirstLast'][$fl] ?? null : null) ?? [];
            }
            if ($candidates) $email = strtolower(trim($candidates[0]['email'] ?? ''));
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $out[] = ['email' => $email, 'name' => $r['agent_name']];
    }
    return $out;
}

// MC Leaders and/or BICs — pulled from market_centers (bic_email/mc_leader_email
// are assigned per-MC via the roster/MC admin screens, so this is complete
// regardless of whether that person has ever logged into AgentEdge).
// $types picks which column(s) to include: 'mc_leader', 'bic', or both — used
// both for the modern single-type 'mc_leader'/'bic' audiences and the legacy
// combined 'leaders' audience.
function ce_resolve_leaders(PDO $db, array $types): array {
    $selects = [];
    if (in_array('bic', $types, true))       $selects[] = "SELECT bic_email AS email FROM market_centers WHERE bic_email != ''";
    if (in_array('mc_leader', $types, true)) $selects[] = "SELECT mc_leader_email AS email FROM market_centers WHERE mc_leader_email != ''";
    if (!$selects) return [];
    $rows = $db->query(implode(' UNION ', $selects))->fetchAll(PDO::FETCH_COLUMN);

    $optOut = $db->query("SELECT email FROM notification_prefs WHERE notify_email=0")->fetchAll(PDO::FETCH_COLUMN);
    $optOutSet = array_flip(array_map(fn($e) => strtolower(trim($e)), $optOut));

    $nameByEmail = [];
    foreach (ce_fetch_crm_roster() as $a) {
        $e = strtolower(trim($a['email'] ?? ''));
        if ($e) $nameByEmail[$e] = $a['fullName'] ?? '';
    }

    $out = [];
    foreach ($rows as $email) {
        $email = strtolower(trim($email));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($optOutSet[$email])) continue;
        $name = $nameByEmail[$email] ?? '';
        if ($name === '') {
            $localPart = strstr($email, '@', true) ?: $email;
            $name = ucwords(str_replace(['.', '_'], ' ', $localPart));
        }
        $out[$email] = ['email' => $email, 'name' => $name];
    }
    return array_values($out);
}

// Every enabled team's leader(s) (team_leaders joined to teams — a disabled
// team's leader isn't pulled in, same enabled-team gate fetch_perms() uses to
// grant the team_leader role in roles.php; a team can have more than one
// leader) UNIONed with anyone carrying the standalone agent_extra
// is_team_leader_tag (set from an agent's profile — see agent_profile.php).
// The tag is deliberately independent of teams/team_leaders: it doesn't
// affect is_team_leader()/My Team access, it only adds someone to this one
// Company Email audience for people who lead a team without a "Team" record.
function ce_resolve_team_leaders(PDO $db): array {
    $rows = $db->query(
        "SELECT DISTINCT tl.agent_email AS email FROM team_leaders tl
         JOIN teams t ON t.id = tl.team_id
         WHERE t.enabled = 1
         UNION
         SELECT email FROM agent_extra WHERE is_team_leader_tag = 1"
    )->fetchAll(PDO::FETCH_COLUMN);

    $optOut = $db->query("SELECT email FROM notification_prefs WHERE notify_email=0")->fetchAll(PDO::FETCH_COLUMN);
    $optOutSet = array_flip(array_map(fn($e) => strtolower(trim($e)), $optOut));

    $nameByEmail = [];
    foreach (ce_fetch_crm_roster() as $a) {
        $e = strtolower(trim($a['email'] ?? ''));
        if ($e) $nameByEmail[$e] = $a['fullName'] ?? '';
    }

    $out = [];
    foreach ($rows as $email) {
        $email = strtolower(trim($email));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($optOutSet[$email])) continue;
        $name = $nameByEmail[$email] ?? '';
        if ($name === '') {
            $localPart = strstr($email, '@', true) ?: $email;
            $name = ucwords(str_replace(['.', '_'], ' ', $localPart));
        }
        $out[$email] = ['email' => $email, 'name' => $name];
    }
    return array_values($out);
}

// Recipients across one or more audiences, as [['email'=>..,'name'=>..], ...],
// deduped by email (a person matching more than one selected audience is only
// emailed once). Each audience is resolved independently by
// ce_resolve_single_audience() and the results are unioned, then enriched with
// the extra fields the {{merge_var}} system supports (see ce_enrich_recipients).
function ce_resolve_recipients(array $audiences, array $mcSlugs, array $targetEmails = [], array $leaderTypes = ['mc_leader', 'bic'], string $launchClassDate = ''): array {
    $merged = [];
    foreach ($audiences as $audience) {
        foreach (ce_resolve_single_audience($audience, $mcSlugs, $targetEmails, $leaderTypes, $launchClassDate) as $r) {
            $email = strtolower(trim($r['email'] ?? ''));
            if ($email === '') continue;
            // Keep the first non-empty name seen for a given email across audiences.
            if (!isset($merged[$email]) || ($merged[$email]['name'] === '' && ($r['name'] ?? '') !== '')) {
                $merged[$email] = ['email' => $email, 'name' => $r['name'] ?? ''];
            }
        }
    }
    return ce_enrich_recipients(array_values($merged));
}

// Adds the extra fields available as {{merge_var}} tokens (market center,
// brokerage, phone, license #/state, office) to each resolved recipient —
// market_center/brokerage/phone come from the CRM roster, license/office come
// from AgentEdge's own agent_intake (roster has no license data). Missing
// data on either side just leaves the field blank, same as name already does.
function ce_enrich_recipients(array $recipients): array {
    if (!$recipients) return $recipients;
    $db = local_db();

    // An agent can have more than one active innovate_roster row (multiple
    // Market Centers) — collect all of them per email rather than letting
    // the last one iterated silently win.
    $rosterByEmail = [];
    foreach (ce_fetch_crm_roster() as $a) {
        $e = strtolower(trim($a['email'] ?? ''));
        if ($e) $rosterByEmail[$e][] = $a;
    }

    $emails = array_column($recipients, 'email');
    $intakeByEmail = [];
    if ($emails) {
        $placeholders = implode(',', array_fill(0, count($emails), '?'));
        $stmt = $db->prepare("SELECT email, phone, license_number, license_state, office_location FROM agent_intake WHERE email IN ($placeholders)");
        $stmt->execute($emails);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $intakeByEmail[$row['email']] = $row;
    }

    foreach ($recipients as &$r) {
        $rosRows = $rosterByEmail[$r['email']] ?? [];
        $ros     = $rosRows[0] ?? [];
        $intk    = $intakeByEmail[$r['email']] ?? [];
        // Join every Market Center this agent has an active roster row for,
        // rather than picking whichever row happened to be first — a 2-MC
        // agent's {{market_center}} merge field should show both, not one
        // arbitrary one.
        $mcNames = array_filter(array_unique(array_map(function ($a) {
            $mc = $a['marketCenter'] ?? '';
            if ($mc === '' && !empty($a['marketCenters'])) $mc = $a['marketCenters'][0]['name'] ?? '';
            return $mc;
        }, $rosRows)));
        $r['market_center']  = implode(' / ', $mcNames);
        $r['brokerage']      = $ros['brokerage'] ?? '';
        $r['phone']          = ($ros['phone'] ?? '') ?: ($intk['phone'] ?? '');
        $r['license_number'] = $intk['license_number'] ?? '';
        $r['license_state']  = $intk['license_state'] ?? '';
        $r['office']         = $intk['office_location'] ?? '';
    }
    unset($r);
    return $recipients;
}

// Recipients for a single audience, as [['email'=>..,'name'=>..], ...]. 'admin'
// stays on the local agent_roles table (small, curated) — everyone with that
// role is opted-in by default, same as 'all'/'mc' below, since notification_prefs
// rows only exist for agents who've visited notification settings (nobody, in
// practice). 'all'/'mc'/'person' pull from the CRM roster so reach/names are
// accurate, then honor any local email opt-out from notification_prefs.
// $mcSlugs may hold more than one slug when $audience === 'mc' (union of every
// agent in any of the selected Market Centers).
// 'mc_leader'/'bic' are the modern, independently-selectable audiences; the
// legacy combined 'leaders' audience (+ $leaderTypes) is still resolved here
// so any scheduled_emails row written before this split still sends correctly.
function ce_resolve_single_audience(string $audience, array $mcSlugs, array $targetEmails = [], array $leaderTypes = ['mc_leader', 'bic'], string $launchClassDate = ''): array {
    $db = local_db();

    if ($audience === 'mc_leader' || $audience === 'bic') {
        return ce_resolve_leaders($db, [$audience]);
    }

    if ($audience === 'leaders') {
        return ce_resolve_leaders($db, $leaderTypes);
    }

    if ($audience === 'team_leader') {
        return ce_resolve_team_leaders($db);
    }

    if ($audience === 'admin') {
        $rows   = $db->query("SELECT email FROM agent_roles WHERE role IN ('super_admin','staff')")->fetchAll(PDO::FETCH_COLUMN);
        $optOut = $db->query("SELECT email FROM notification_prefs WHERE notify_email=0")->fetchAll(PDO::FETCH_COLUMN);
        $optOutSet = array_flip(array_map(fn($e) => strtolower(trim($e)), $optOut));
        $out = [];
        foreach ($rows as $email) {
            $email = strtolower(trim($email));
            if (!$email || isset($optOutSet[$email])) continue;
            $localPart = strstr($email, '@', true) ?: $email;
            $out[] = ['email' => $email, 'name' => ucwords(str_replace(['.', '_'], ' ', $localPart))];
        }
        return $out;
    }

    // Everyone currently active in a LAUNCH cohort — the cohort_members table
    // (not a role) is the authoritative "who's in LAUNCH right now" signal.
    // A $launchClassDate narrows this to just one Launch Schedule class
    // instead of every LAUNCH agent company-wide (see ce_resolve_launch_class).
    if ($audience === 'launch_agents') {
        if ($launchClassDate !== '') {
            return ce_resolve_launch_class($launchClassDate);
        }
        $rows = $db->query(
            "SELECT DISTINCT cm.agent_email AS email FROM cohort_members cm
             JOIN cohorts c ON c.id = cm.cohort_id
             WHERE cm.status='active' AND c.program='launch'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $optOut = $db->query("SELECT email FROM notification_prefs WHERE notify_email=0")->fetchAll(PDO::FETCH_COLUMN);
        $optOutSet = array_flip(array_map(fn($e) => strtolower(trim($e)), $optOut));

        $nameByEmail = [];
        foreach (ce_fetch_crm_roster() as $a) {
            $e = strtolower(trim($a['email'] ?? ''));
            if ($e) $nameByEmail[$e] = $a['fullName'] ?? '';
        }

        $out = [];
        foreach ($rows as $email) {
            $email = strtolower(trim($email));
            if (!$email || isset($optOutSet[$email])) continue;
            $name = $nameByEmail[$email] ?? '';
            if ($name === '') {
                $localPart = strstr($email, '@', true) ?: $email;
                $name = ucwords(str_replace(['.', '_'], ' ', $localPart));
            }
            $out[$email] = ['email' => $email, 'name' => $name];
        }
        return array_values($out);
    }

    // Launch Coaches + the Director of Coaching — a role, not a cohort
    // membership, so this stays on agent_roles like 'admin' above.
    if ($audience === 'launch_coaches') {
        // Effective role (primary OR extra_roles_json) via the shared helper in
        // roles.php -- these roles are normally held as an extra role, which a
        // primary-role-only query never sees. launch_facilitator is not included.
        $rows = list_launch_coaches($db);
        $optOut = $db->query("SELECT email FROM notification_prefs WHERE notify_email=0")->fetchAll(PDO::FETCH_COLUMN);
        $optOutSet = array_flip(array_map(fn($e) => strtolower(trim($e)), $optOut));
        $out = [];
        foreach ($rows as $coach) {
            $email = strtolower(trim($coach['email']));
            if (!$email || isset($optOutSet[$email]) || isset($out[$email])) continue;
            // The helper falls back to the email itself when there's no intake name.
            $fullName = trim($coach['full_name'] ?? '');
            $localPart = strstr($email, '@', true) ?: $email;
            $name = ($fullName !== '' && strtolower($fullName) !== $email) ? $fullName : ucwords(str_replace(['.', '_'], ' ', $localPart));
            $out[$email] = ['email' => $email, 'name' => $name];
        }
        return array_values($out);
    }

    $roster = ce_fetch_crm_roster();

    if ($audience === 'person') {
        $out = [];
        foreach ($targetEmails as $targetEmail) {
            $targetEmail = strtolower(trim($targetEmail));
            if ($targetEmail === '') continue;
            $name = '';
            foreach ($roster as $a) {
                if (strtolower(trim($a['email'] ?? '')) === $targetEmail) { $name = $a['fullName'] ?? ''; break; }
            }
            $out[] = ['email' => $targetEmail, 'name' => $name];
        }
        return $out;
    }

    $names = [];
    foreach ($roster as $a) {
        $email = strtolower(trim($a['email'] ?? ''));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

        if ($audience === 'mc') {
            $mc = $a['marketCenter'] ?? '';
            if ($mc === '' && !empty($a['marketCenters'])) $mc = $a['marketCenters'][0]['name'] ?? '';
            if (!$mc || !in_array(slugify_mc($mc), $mcSlugs, true)) continue;
        }
        $names[$email] = $a['fullName'] ?? '';
    }

    // "Entire Company" must reach admin/staff too — agent_roles' super_admin/
    // staff rows are frequently non-agents (office/support staff, admins with
    // no license), so they can be entirely absent from the CRM roster loop above.
    if ($audience === 'all') {
        $adminRows = $db->query("SELECT email FROM agent_roles WHERE role IN ('super_admin','staff')")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($adminRows as $email) {
            $email = strtolower(trim($email));
            if (!$email || isset($names[$email])) continue;
            $localPart = strstr($email, '@', true) ?: $email;
            $names[$email] = ucwords(str_replace(['.', '_'], ' ', $localPart));
        }
    }

    $optOut = $db->query("SELECT email FROM notification_prefs WHERE notify_email=0")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($optOut as $o) unset($names[strtolower(trim($o))]);

    $out = [];
    foreach ($names as $email => $name) $out[] = ['email' => $email, 'name' => $name];
    return $out;
}

// Validates one or more selected audiences + scope permission. Returns an
// error string, or null if every audience checks out. Requires the caller to
// be signed in (uses is_admin()/my_mc_slugs() from roles.php).
// 'person' has no Market Center scoping — anyone with Company Email access
// (admin/staff/mc_leader/bic) can email any address(es), in or out of the roster.
// 'mc_leader'/'bic' are the modern, independently-selectable audiences;
// 'leaders' (+ $leaderTypes) is kept valid only so old, not-yet-sent
// scheduled_emails rows still validate/resolve — new sends never write it.
function ce_validate_audience(array $audiences, array $mcSlugs, array $targetEmails, array $leaderTypes = []): ?string {
    if (!$audiences) return 'Pick at least one audience';

    $validKeys = ['all', 'admin', 'mc', 'person', 'leaders', 'mc_leader', 'bic', 'team_leader', 'launch_agents', 'launch_coaches'];
    foreach ($audiences as $audience) {
        if (!in_array($audience, $validKeys, true)) return 'Invalid audience';

        if ($audience === 'mc') {
            if (!$mcSlugs) return 'Pick at least one Market Center';
            if (!is_admin()) {
                $allowed = my_mc_slugs();
                foreach ($mcSlugs as $slug) {
                    if (!in_array($slug, $allowed, true)) return 'You can only email a Market Center you lead';
                }
            }
        } elseif ($audience === 'person') {
            if (!$targetEmails) return 'Add at least one recipient';
            foreach ($targetEmails as $e) {
                if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return 'One or more recipient emails are invalid';
            }
        } elseif ($audience === 'leaders') {
            if (!is_admin()) return 'Forbidden';
            if (!array_intersect($leaderTypes, ['mc_leader', 'bic'])) return 'Pick Market Center Leaders, BICs, or both';
        } elseif (in_array($audience, ['mc_leader', 'bic'], true)) {
            if (!is_admin() && !is_mc_leader() && !is_bic()) return 'Forbidden';
        } elseif ($audience === 'team_leader') {
            if (!is_admin()) return 'Forbidden';
        } elseif (in_array($audience, ['launch_agents', 'launch_coaches'], true)) {
            if (!can_manage_cohorts()) return 'Forbidden';
        } elseif (!is_admin()) {
            return 'Forbidden';
        }
    }
    return null;
}

// Logs one row per recipient onto agent_comms_log so the send shows up on each
// recipient's own record (agent_profile.php's Communications tab), not just in
// the sender's mail client. Called from both the immediate-send path
// (api/company_email_action.php) and the scheduled-send worker
// (cron/process_email_queue.php) so neither path can log to only one of them.
function ce_log_to_agent_records(array $recipients, string $subject, string $bodyHtml, string $senderEmail, int $companyEmailId): void {
    $db = local_db();
    $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($bodyHtml)));
    if (strlen($snippet) > 240) $snippet = substr($snippet, 0, 240) . '…';

    $ins = $db->prepare(
        "INSERT INTO agent_comms_log (agent_email, sender_email, subject, snippet, source_type, source_id, sent_at)
         VALUES (?, ?, ?, ?, 'company_email', ?, datetime('now'))"
    );
    foreach ($recipients as $r) {
        $email = strtolower(trim($r['email'] ?? ''));
        if ($email === '') continue;
        $ins->execute([$email, $senderEmail, $subject, $snippet, $companyEmailId]);
    }
}

// Mints (and persists) a per-email one-click unsubscribe link for
// {{unsubscribe_url}}. Stored random token (same pattern as
// agent_extra.cal_token) rather than a signed URL, so no new secret is
// needed in config.php. Landing on unsubscribe.php sets
// notification_prefs.notify_email=0, which every Company Email audience
// already checks before sending (see ce_resolve_single_audience()) — no
// separate suppression list needed.
function ce_unsubscribe_url(PDO $db, string $host, string $email): string {
    $email = strtolower(trim($email));
    if ($email === '') return '';
    $stmt = $db->prepare("SELECT unsub_token FROM notification_prefs WHERE email=?");
    $stmt->execute([$email]);
    $token = $stmt->fetchColumn();
    if (!$token) {
        $token = bin2hex(random_bytes(16));
        $db->prepare(
            "INSERT INTO notification_prefs (email, unsub_token) VALUES (?, ?)
             ON CONFLICT(email) DO UPDATE SET
                 unsub_token = CASE WHEN notification_prefs.unsub_token = ''
                                    THEN excluded.unsub_token ELSE notification_prefs.unsub_token END"
        )->execute([$email, $token]);
        $stmt->execute([$email]);
        $token = $stmt->fetchColumn() ?: $token;
    }
    return 'https://' . $host . '/unsubscribe.php?email=' . urlencode($email) . '&t=' . urlencode($token);
}

// {{merge_var}} substitution, personalized per recipient, with an optional
// {{token|fallback text}} syntax — falls back to that text (or, for tokens
// with a sensible built-in default like first_name, to that default) when
// the token has no value for this recipient. A token actually written with
// an explicit fallback always wins over the built-in one. $recipient is one
// row from ce_resolve_recipients() (email/name plus the ce_enrich_recipients
// fields).
function ce_apply_merge_vars(PDO $db, string $host, string $html, array $recipient): string {
    $name  = trim($recipient['name'] ?? '');
    $parts = $name !== '' ? preg_split('/\s+/', $name) : [];
    $first = $parts[0] ?? '';
    $last  = count($parts) > 1 ? end($parts) : '';

    $vars = [
        'first_name'      => $first,
        'last_name'       => $last,
        'full_name'       => $name,
        'email'           => $recipient['email'] ?? '',
        'market_center'   => $recipient['market_center']  ?? '',
        'brokerage'       => $recipient['brokerage']      ?? '',
        'phone'           => $recipient['phone']          ?? '',
        'license_number'  => $recipient['license_number'] ?? '',
        'license_state'   => $recipient['license_state']  ?? '',
        'office'          => $recipient['office']         ?? '',
        'company_name'    => 'INNOVATE Real Estate',
        'company_address' => '3103 S Hwy 17 Bus, Unit F, Murrells Inlet, SC 29576',
        'unsubscribe_url' => ce_unsubscribe_url($db, $host, $recipient['email'] ?? ''),
    ];
    $builtinFallback = ['first_name' => 'there'];

    return preg_replace_callback(
        '/\{\{\s*([a-z_]+)(?:\s*\|\s*([^}]*))?\s*\}\}/i',
        function ($m) use ($vars, $builtinFallback) {
            $key      = strtolower($m[1]);
            $fallback = array_key_exists(2, $m) ? trim($m[2]) : ($builtinFallback[$key] ?? '');
            $value    = $vars[$key] ?? '';
            return htmlspecialchars($value !== '' ? $value : $fallback, ENT_QUOTES);
        },
        $html
    );
}

// Builds the sender's signature block (photo + name + title + phone + links),
// falling back to a plain text sign-off if nothing's configured.
// $host is needed explicitly (rather than $_SERVER['HTTP_HOST']) since the
// cron worker has no HTTP request context.
function ce_signature_html(string $email, string $displayName, string $host): string {
    $db = local_db();

    $sigStmt = $db->prepare("SELECT title, phone, calendar_url, website_url, use_custom, custom_html FROM email_signatures WHERE email=?");
    $sigStmt->execute([$email]);
    $s = $sigStmt->fetch(PDO::FETCH_ASSOC) ?: ['title' => '', 'phone' => '', 'calendar_url' => '', 'website_url' => '', 'use_custom' => 0, 'custom_html' => ''];

    // Custom mode fully replaces the auto-built signature (photo/phone/links
    // below) — the sender wrote their own HTML in the rich-text signature editor.
    if (!empty($s['use_custom']) && trim($s['custom_html'] ?? '') !== '') {
        return '<div style="margin-top:24px;border-top:1px solid #ddd;padding-top:14px">' . $s['custom_html'] . '</div>';
    }

    if ($s['phone'] === '') {
        $p = $db->prepare("SELECT phone FROM agent_intake WHERE email=?");
        $p->execute([$email]);
        $s['phone'] = $p->fetchColumn() ?: '';
    }

    // The headshot lives behind api/intake.php's auth-gated endpoint (viewer must
    // be that agent or an admin) — no good for email recipients with no session
    // at all. Copy it into the public email-image store instead, under a stable
    // per-email key so repeat sends reuse the same file and refresh on new uploads.
    $photoUrl = '';
    $f = $db->prepare("SELECT file_key FROM agent_intake_files WHERE agent_email=? ORDER BY uploaded_at DESC LIMIT 1");
    $f->execute([$email]);
    $fileKey = $f->fetchColumn();
    if ($fileKey) {
        $c        = cfg();
        $baseDir  = $c['local_db_dir'] ?? (__DIR__ . '/../data');
        $srcPath  = $baseDir . '/headshots/' . $fileKey;
        $ext      = pathinfo($fileKey, PATHINFO_EXTENSION) ?: 'jpg';
        $pubKey   = md5('sig:' . $email) . '.' . preg_replace('/[^a-z]/', '', strtolower($ext));
        $imgDir   = $baseDir . '/email_images';
        if (!is_dir($imgDir)) @mkdir($imgDir, 0755, true);
        if (is_file($srcPath) && @copy($srcPath, $imgDir . '/' . $pubKey)) {
            $photoUrl = 'https://' . $host . '/api/email_image.php?key=' . urlencode($pubKey);
        }
    }

    if (!$s['title'] && !$s['phone'] && !$s['calendar_url'] && !$s['website_url'] && !$photoUrl) {
        return '<p style="color:#888;font-size:13px;margin-top:20px">— ' . htmlspecialchars($displayName, ENT_QUOTES) . '<br>INNOVATE Real Estate</p>';
    }

    $photoImg = $photoUrl
        ? '<img src="' . htmlspecialchars($photoUrl, ENT_QUOTES) . '" width="64" height="64" style="border-radius:50%;object-fit:cover;display:block" alt="">'
        : '';

    $lines = [];
    if ($s['title']) $lines[] = '<div style="font-size:13px;color:#555">' . htmlspecialchars($s['title'], ENT_QUOTES) . '</div>';
    if ($s['phone']) $lines[] = '<div style="font-size:13px;color:#555;margin-top:4px">' . htmlspecialchars($s['phone'], ENT_QUOTES) . '</div>';
    $links = [];
    if ($s['calendar_url']) $links[] = '<a href="' . htmlspecialchars($s['calendar_url'], ENT_QUOTES) . '" style="color:#5b8e0d;text-decoration:underline">Schedule a meeting</a>';
    if ($s['website_url'])  $links[] = '<a href="' . htmlspecialchars($s['website_url'], ENT_QUOTES) . '" style="color:#5b8e0d;text-decoration:underline">Visit our website</a>';
    if ($links) $lines[] = '<div style="font-size:13px;margin-top:6px">' . implode(' &nbsp;|&nbsp; ', $links) . '</div>';

    return '<table cellpadding="0" cellspacing="0" style="margin-top:24px;border-top:1px solid #ddd;padding-top:14px"><tr>'
         . ($photoImg ? '<td style="padding-right:14px;vertical-align:top">' . $photoImg . '</td>' : '')
         . '<td style="vertical-align:top"><div style="font-size:15px;font-weight:700;color:#111">' . htmlspecialchars($displayName, ENT_QUOTES) . '</div>'
         . implode('', $lines) . '</td></tr></table>';
}

// ── Recipient-level reporting (drill-down behind the history stats strip) ────
// Read-only. One definition of every stat, shared by the dashboard counts
// (ce_stat_counts) and the drill-down lists (ce_stat_rows) so the two cannot
// drift apart. Every stat is a set of DISTINCT recipient emails (lower-cased).
//
// SendGrid retries can deliver the same event more than once and
// company_email_events has no unique key, so event rows are collapsed on
// (recipient, event, url, occurred_at) before anything is counted or listed.
// Raw rows are never modified.
//
//   sent         notification_queue email rows with status 'sent'
//   delivered    'delivered' events
//   open         'open' events
//   not_opened   sent recipients with no 'open' event ("No recorded open")
//   click        'click' events
//   bounce       'bounce' events
//   dropped      'dropped' events
const CE_RECIPIENT_STATS = ['sent', 'delivered', 'open', 'not_opened', 'click', 'bounce', 'dropped'];

// Per email id: 'tracked' (has events), 'none_yet' (sent after the webhook went
// live but no events recorded -- a genuine zero), or 'unavailable' (sent before
// the first webhook event ever recorded, so zero means "not tracked", not "none").
// Also reports whether per-recipient send records exist ('sent' stat available).
function ce_tracking_status(PDO $db, array $emails): array {
    $webhookStart = $db->query("SELECT MIN(occurred_at) FROM company_email_events")->fetchColumn() ?: null;
    $out = [];
    if (!$emails) return $out;
    $ids = array_map(fn($e) => (int)$e['id'], $emails);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $ev = [];
    $s = $db->prepare("SELECT company_email_id, COUNT(*) FROM company_email_events WHERE company_email_id IN ($ph) GROUP BY company_email_id");
    $s->execute($ids);
    foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) $ev[(int)$r[0]] = (int)$r[1];
    $q = [];
    $s = $db->prepare("SELECT company_email_id, COUNT(*) FROM notification_queue WHERE channel='email' AND company_email_id IN ($ph) GROUP BY company_email_id");
    $s->execute($ids);
    foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) $q[(int)$r[0]] = (int)$r[1];

    foreach ($emails as $e) {
        $id = (int)$e['id'];
        if (!empty($ev[$id]))                                            $t = 'tracked';
        elseif ($webhookStart === null || $e['sent_at'] < $webhookStart) $t = 'unavailable';
        else                                                             $t = 'none_yet';
        $out[$id] = ['tracking' => $t, 'send_records' => !empty($q[$id])];
    }
    return $out;
}

// Distinct-recipient counts for every stat, for a batch of email ids.
// Returns [id => [stat => int]].
function ce_stat_counts(PDO $db, array $ids): array {
    $out = [];
    if (!$ids) return $out;
    $ids = array_map('intval', $ids);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach ($ids as $id) $out[$id] = array_fill_keys(CE_RECIPIENT_STATS, 0);

    $s = $db->prepare(
        "SELECT company_email_id, COUNT(DISTINCT LOWER(recipient)) FROM notification_queue
         WHERE channel='email' AND status='sent' AND company_email_id IN ($ph) GROUP BY company_email_id"
    );
    $s->execute($ids);
    foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) $out[(int)$r[0]]['sent'] = (int)$r[1];

    $map = ['delivered'=>'delivered', 'open'=>'open', 'click'=>'click', 'bounce'=>'bounce', 'dropped'=>'dropped'];
    $s = $db->prepare(
        "SELECT company_email_id, event, COUNT(DISTINCT recipient) FROM company_email_events
         WHERE company_email_id IN ($ph) GROUP BY company_email_id, event"
    );
    $s->execute($ids);
    foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) {
        if (isset($map[$r[1]])) $out[(int)$r[0]][$map[$r[1]]] = (int)$r[2];
    }

    // No recorded open = sent recipients minus those with any open event.
    // Done as a set difference here rather than a correlated NOT EXISTS: SQLite
    // plans that as a scan of every 'sent' queue row (~0.7s per dashboard load).
    $s = $db->prepare(
        "SELECT DISTINCT company_email_id, LOWER(recipient) FROM notification_queue
         WHERE channel='email' AND status='sent' AND company_email_id IN ($ph)"
    );
    $s->execute($ids);
    $sentSet = [];
    foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) $sentSet[(int)$r[0]][$r[1]] = true;
    $s = $db->prepare("SELECT DISTINCT company_email_id, recipient FROM company_email_events WHERE event='open' AND company_email_id IN ($ph)");
    $s->execute($ids);
    foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) unset($sentSet[(int)$r[0]][$r[1]]);
    foreach ($sentSet as $id => $set) $out[$id]['not_opened'] = count($set);
    return $out;
}

// Best-effort display names for a list of lower-cased emails. Emails with no
// match are simply absent from the result (callers fall back to the address).
function ce_recipient_names(PDO $db, array $emails): array {
    $names = [];
    $emails = array_values(array_unique($emails));
    foreach (array_chunk($emails, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        foreach ([
            "SELECT LOWER(email), full_name,  ''            FROM agent_intake     WHERE LOWER(email) IN ($ph)",
            "SELECT LOWER(email), agent_name, market_center FROM innovate_roster  WHERE LOWER(email) IN ($ph) ORDER BY active ASC",
            "SELECT LOWER(email), name,       ''            FROM imported_agents  WHERE LOWER(email) IN ($ph)",
        ] as $sql) {
            try {
                $s = $db->prepare($sql);
                $s->execute($chunk);
                foreach ($s->fetchAll(PDO::FETCH_NUM) as $r) {
                    $n = trim((string)$r[1]);
                    if (!isset($names[$r[0]])) $names[$r[0]] = ['name' => '', 'mc' => ''];
                    if ($names[$r[0]]['name'] === '' && $n !== '') $names[$r[0]]['name'] = $n;
                    if ($names[$r[0]]['mc'] === '' && trim((string)$r[2]) !== '') $names[$r[0]]['mc'] = trim((string)$r[2]);
                }
            } catch (\Throwable $e) { /* optional source missing: fall back to email */ }
        }
    }
    return $names;
}

// SendGrid bounce reasons embed the VERP return path
// ("... <bounces+123-abcd-user=domain@em9101...>: ..."); strip that noise.
function ce_clean_reason(string $r): string {
    $r = preg_replace('/<bounces\+[^>]*>:?\s*/i', '', $r);
    return trim(preg_replace('/\s+/', ' ', $r));
}

// The recipient list behind one stat for one email. Row count always equals
// ce_stat_counts()[id][stat]. Returns a list of rows keyed for the UI/CSV.
function ce_stat_rows(PDO $db, int $id, string $stat): array {
    if (!in_array($stat, CE_RECIPIENT_STATS, true)) return [];
    $rows = [];

    // Deduplicated events for this email: one row per (recipient, event, url, time).
    $ev = $db->prepare(
        "SELECT recipient, event, url, MAX(reason) AS reason, occurred_at
         FROM company_email_events WHERE company_email_id=?
         GROUP BY recipient, event, url, occurred_at ORDER BY occurred_at"
    );
    $ev->execute([$id]);
    $byRecipient = [];
    foreach ($ev->fetchAll(PDO::FETCH_ASSOC) as $e) $byRecipient[$e['recipient']][$e['event']][] = $e;

    $sent = [];
    $q = $db->prepare(
        "SELECT LOWER(recipient) AS r, MIN(sent_at) AS sent_at FROM notification_queue
         WHERE channel='email' AND status='sent' AND company_email_id=? GROUP BY LOWER(recipient)"
    );
    $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $sent[$r['r']] = $r['sent_at'];

    $deliveryOf = function (string $r) use ($byRecipient): string {
        $e = $byRecipient[$r] ?? [];
        if (!empty($e['bounce']))    return 'Bounced';
        if (!empty($e['dropped']))   return 'Dropped';
        if (!empty($e['delivered'])) return 'Delivered';
        return 'No delivery event';
    };

    if ($stat === 'sent' || $stat === 'not_opened') {
        foreach ($sent as $r => $at) {
            if ($stat === 'not_opened' && !empty($byRecipient[$r]['open'])) continue;
            $rows[] = ['email' => $r, 'time' => $at, 'delivery' => $deliveryOf($r)];
        }
    } else {
        foreach ($byRecipient as $r => $events) {
            if (empty($events[$stat])) continue;
            $list = $events[$stat];
            $row = ['email' => $r, 'time' => $list[0]['occurred_at']];
            if ($stat === 'open') {
                $row['first_open'] = $list[0]['occurred_at'];
                $row['last_open']  = end($list)['occurred_at'];
                $row['open_count'] = count($list);
            } elseif ($stat === 'click') {
                $row['click_count'] = count($list);
                $row['clicks'] = array_map(fn($c) => ['url' => $c['url'], 'time' => $c['occurred_at']], $list);
            } elseif ($stat === 'bounce' || $stat === 'dropped') {
                $reasons = array_values(array_unique(array_filter(array_map(fn($c) => ce_clean_reason((string)$c['reason']), $list))));
                $row['reason'] = $reasons ? implode(' | ', $reasons) : 'No reason provided';
            }
            $rows[] = $row;
        }
    }

    $names = ce_recipient_names($db, array_column($rows, 'email'));
    foreach ($rows as &$row) {
        $row['name'] = $names[$row['email']]['name'] ?? '';
        $row['mc']   = $names[$row['email']]['mc']   ?? '';
    }
    unset($row);
    usort($rows, fn($a, $b) => strcasecmp($a['name'] !== '' ? $a['name'] : $a['email'], $b['name'] !== '' ? $b['name'] : $b['email']));
    return $rows;
}
