<?php
// Shared logic for Company Email — used by api/company_email_action.php (the
// web endpoint) and cron/process_email_queue.php (the scheduled-send worker),
// so recipient resolution/merge-vars/signature building stay in one place.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../roles.php';

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

// Recipients across one or more audiences, as [['email'=>..,'name'=>..], ...],
// deduped by email (a person matching more than one selected audience is only
// emailed once). Each audience is resolved independently by
// ce_resolve_single_audience() and the results are unioned, then enriched with
// the extra fields the {{merge_var}} system supports (see ce_enrich_recipients).
function ce_resolve_recipients(array $audiences, array $mcSlugs, string $targetEmail = '', array $leaderTypes = ['mc_leader', 'bic']): array {
    $merged = [];
    foreach ($audiences as $audience) {
        foreach (ce_resolve_single_audience($audience, $mcSlugs, $targetEmail, $leaderTypes) as $r) {
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

    $rosterByEmail = [];
    foreach (ce_fetch_crm_roster() as $a) {
        $e = strtolower(trim($a['email'] ?? ''));
        if ($e) $rosterByEmail[$e] = $a;
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
        $ros  = $rosterByEmail[$r['email']] ?? [];
        $intk = $intakeByEmail[$r['email']] ?? [];
        $mc = $ros['marketCenter'] ?? '';
        if ($mc === '' && !empty($ros['marketCenters'])) $mc = $ros['marketCenters'][0]['name'] ?? '';
        $r['market_center']  = $mc;
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
function ce_resolve_single_audience(string $audience, array $mcSlugs, string $targetEmail = '', array $leaderTypes = ['mc_leader', 'bic']): array {
    $db = local_db();

    if ($audience === 'mc_leader' || $audience === 'bic') {
        return ce_resolve_leaders($db, [$audience]);
    }

    if ($audience === 'leaders') {
        return ce_resolve_leaders($db, $leaderTypes);
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
    if ($audience === 'launch_agents') {
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
        $rows = $db->query("SELECT email FROM agent_roles WHERE role IN ('launch_coach','director_of_coaching')")->fetchAll(PDO::FETCH_COLUMN);
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

    $roster = ce_fetch_crm_roster();

    if ($audience === 'person') {
        foreach ($roster as $a) {
            if (strtolower(trim($a['email'] ?? '')) === $targetEmail) {
                return [['email' => $targetEmail, 'name' => $a['fullName'] ?? '']];
            }
        }
        return [['email' => $targetEmail, 'name' => '']];
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
// (admin/staff/mc_leader/bic) can 1:1 email any address, in or out of the roster.
// 'mc_leader'/'bic' are the modern, independently-selectable audiences;
// 'leaders' (+ $leaderTypes) is kept valid only so old, not-yet-sent
// scheduled_emails rows still validate/resolve — new sends never write it.
function ce_validate_audience(array $audiences, array $mcSlugs, string $targetEmail, array $leaderTypes = []): ?string {
    if (!$audiences) return 'Pick at least one audience';

    $validKeys = ['all', 'admin', 'mc', 'person', 'leaders', 'mc_leader', 'bic', 'launch_agents', 'launch_coaches'];
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
            if (!$targetEmail || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) return 'A valid recipient email is required';
        } elseif ($audience === 'leaders') {
            if (!is_admin()) return 'Forbidden';
            if (!array_intersect($leaderTypes, ['mc_leader', 'bic'])) return 'Pick Market Center Leaders, BICs, or both';
        } elseif (in_array($audience, ['mc_leader', 'bic'], true)) {
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

// {{merge_var}} substitution, personalized per recipient. $recipient is one
// row from ce_resolve_recipients() (email/name plus the ce_enrich_recipients
// fields) — any field missing on a given recipient just renders blank.
function ce_apply_merge_vars(string $html, array $recipient): string {
    $name  = trim($recipient['name'] ?? '');
    $full  = $name !== '' ? $name : 'there';
    $first = $name !== '' ? preg_split('/\s+/', $name)[0] : 'there';

    $vars = [
        '{{first_name}}'     => $first,
        '{{full_name}}'      => $full,
        '{{market_center}}'  => $recipient['market_center']  ?? '',
        '{{brokerage}}'      => $recipient['brokerage']      ?? '',
        '{{phone}}'          => $recipient['phone']          ?? '',
        '{{license_number}}' => $recipient['license_number'] ?? '',
        '{{license_state}}'  => $recipient['license_state']  ?? '',
        '{{office}}'         => $recipient['office']         ?? '',
    ];
    return str_replace(
        array_keys($vars),
        array_map(fn($v) => htmlspecialchars($v, ENT_QUOTES), array_values($vars)),
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
