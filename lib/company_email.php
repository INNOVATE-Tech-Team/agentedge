<?php
// Shared logic for Company Email — used by api/company_email_action.php (the
// web endpoint) and cron/process_email_queue.php (the scheduled-send worker),
// so recipient resolution/merge-vars/signature building stay in one place.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../roles.php';

// Pull the full agent roster from the CRM — this is the authoritative "every
// agent" list (agent_roles/notification_prefs only cover agents who've logged
// into AgentEdge or been explicitly assigned a role, which is a much smaller set).
function ce_fetch_crm_roster(): array {
    $c     = cfg();
    $base  = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
    $token = $c['crm_token'] ?? '';
    $url   = $base . '/public/retention-roster' . ($token ? '?token=' . urlencode($token) : '');
    $ctx   = stream_context_create(['http' => ['timeout' => 15, 'header' => "Accept: application/json\r\n"]]);
    $raw   = @file_get_contents($url, false, $ctx);
    return ($raw !== false) ? (json_decode($raw, true) ?? []) : [];
}

// Recipients for a given audience, as [['email'=>..,'name'=>..], ...]. 'admin'
// stays on the local agent_roles table (small, curated) — everyone with that
// role is opted-in by default, same as 'all'/'mc' below, since notification_prefs
// rows only exist for agents who've visited notification settings (nobody, in
// practice). 'all'/'mc'/'person' pull from the CRM roster so reach/names are
// accurate, then honor any local email opt-out from notification_prefs.
function ce_resolve_recipients(string $audience, string $mcSlug, string $targetEmail = ''): array {
    $db = local_db();

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

    // MC Leaders & BICs — pulled from market_centers (bic_email/mc_leader_email
    // are assigned per-MC via the roster/MC admin screens, so this is complete
    // regardless of whether that person has ever logged into AgentEdge).
    if ($audience === 'leaders') {
        $rows = $db->query(
            "SELECT bic_email AS email FROM market_centers WHERE bic_email != ''
             UNION
             SELECT mc_leader_email AS email FROM market_centers WHERE mc_leader_email != ''"
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
            if (!$mc || slugify_mc($mc) !== $mcSlug) continue;
        }
        $names[$email] = $a['fullName'] ?? '';
    }

    $optOut = $db->query("SELECT email FROM notification_prefs WHERE notify_email=0")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($optOut as $o) unset($names[strtolower(trim($o))]);

    $out = [];
    foreach ($names as $email => $name) $out[] = ['email' => $email, 'name' => $name];
    return $out;
}

// Validates audience + scope permission. Returns an error string, or null if OK.
// Requires the caller to be signed in (uses is_admin()/my_mc_slugs() from roles.php).
// 'person' has no Market Center scoping — anyone with Company Email access
// (admin/staff/mc_leader/bic) can 1:1 email any address, in or out of the roster.
function ce_validate_audience(string $audience, string $mcSlug, string $targetEmail): ?string {
    if (!in_array($audience, ['all', 'admin', 'mc', 'person', 'leaders'], true)) return 'Invalid audience';

    if ($audience === 'mc') {
        if (!$mcSlug) return 'Market Center required';
        if (!is_admin() && !in_array($mcSlug, my_mc_slugs(), true)) return 'You can only email a Market Center you lead';
    } elseif ($audience === 'person') {
        if (!$targetEmail || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL)) return 'A valid recipient email is required';
    } elseif (!is_admin()) {
        return 'Forbidden';
    }
    return null;
}

// {{first_name}} / {{full_name}} substitution, personalized per recipient.
function ce_apply_merge_vars(string $html, string $recipientName): string {
    $recipientName = trim($recipientName);
    $full  = $recipientName !== '' ? $recipientName : 'there';
    $first = $recipientName !== '' ? preg_split('/\s+/', $recipientName)[0] : 'there';
    return str_replace(
        ['{{first_name}}', '{{full_name}}'],
        [htmlspecialchars($first, ENT_QUOTES), htmlspecialchars($full, ENT_QUOTES)],
        $html
    );
}

// Builds the sender's signature block (photo + name + title + phone + links),
// falling back to a plain text sign-off if nothing's configured.
// $host is needed explicitly (rather than $_SERVER['HTTP_HOST']) since the
// cron worker has no HTTP request context.
function ce_signature_html(string $email, string $displayName, string $host): string {
    $db = local_db();

    $sigStmt = $db->prepare("SELECT title, phone, calendar_url, website_url FROM email_signatures WHERE email=?");
    $sigStmt->execute([$email]);
    $s = $sigStmt->fetch(PDO::FETCH_ASSOC) ?: ['title' => '', 'phone' => '', 'calendar_url' => '', 'website_url' => ''];

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
