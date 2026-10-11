<?php
if (defined('AGENTEDGE_NAV_LOADED')) return;
define('AGENTEDGE_NAV_LOADED', true);
// The agent menu, in one place. Edit this list to change the sidebar
// everywhere. `external => true` marks an SSO link out to another system.
// `adminOnly => true` hides the item from non-admins (super/retention admin).
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/feature_flags.php';

function nav_items(): array {
    // External links come from the DB (editable by super_admin in admin_links.php).
    $extLinks = nav_ext_links_all();
    $ext = array_map(function($r) {
        $item = [
            'key'         => $r['key'],
            'label'       => $r['label'],
            'href'        => $r['url'],
            'group_label' => $r['group_label'] ?? '',
        ];
        // Internal pages — not external SSO links
        if ($r['key'] === 'openhouse') {
            $item['href'] = 'openhouse.php';
        } elseif ($r['key'] === 'transactions') {
            $item['href'] = 'dotloop.php';
        } elseif ($r['key'] === 'tickets') {
            $item['href'] = 'tickets.php';
        } elseif ($r['key'] === 'suggestions') {
            $item['href'] = 'suggestions.php';
            $item['leaderOnly'] = true;
        } elseif ($r['key'] === 'recruiting_playbook') {
            $item['href'] = 'docs.php?folder=1';
            $item['leaderOnly'] = true;
        } else {
            $item['external'] = true;
        }
        return $item;
    }, $extLinks);

    // Core pages — sorted by nav_core_order if set
    $coreMap = [
        'roster'           => ['key' => 'roster',           'label' => 'Agent Roster',        'href' => 'roster.php'],
        'associate_paperwork' => ['key' => 'associate_paperwork', 'label' => 'Associate Paperwork', 'href' => 'associate_paperwork.php'],
        'calendar'         => ['key' => 'calendar',         'label' => 'Company Calendar',    'href' => 'calendar.php'],
        // 'event_planner' hidden from the sidebar for now — page still exists at event_planner.php.
        // Admin Work OS (V0) — admin-only collapsible, rendered above the
        // personalized Assets section below. 'adminOnly' is enough to hide it
        // from non-admins: the generic adminOnly filter in render_sidebar()
        // runs before the sentinel is special-cased, same as any other item.
        '__admin_os__'     => ['key' => '__admin_os__',     'label' => 'Admin OS',             'href' => '', 'adminOnly' => true],
        '__assets__'       => ['key' => '__assets__',       'label' => '',                    'href' => ''],
        'who_does_what'    => ['key' => 'who_does_what',    'label' => 'Who Does What',        'href' => 'who_does_what.php'],
        'industry_events'  => ['key' => 'industry_events',  'label' => 'Industry Events',     'href' => 'industry_events.php'],
        'university'       => ['key' => 'university',       'label' => 'INNOVATE University', 'href' => 'university.php'],
        'leaderboard'      => ['key' => 'leaderboard',      'label' => 'LAUNCH Leaderboard',  'href' => 'leaderboard.php', 'launchLeaderboardOnly' => true],
    ];
    try {
        $orderedKeys = local_db()->query("SELECT key FROM nav_core_order ORDER BY sort_ord")->fetchAll(PDO::FETCH_COLUMN);
        $core = [];
        foreach ($orderedKeys as $k) { if (isset($coreMap[$k])) $core[] = $coreMap[$k]; }
        foreach ($coreMap as $k => $item) { if (!in_array($k, $orderedKeys)) $core[] = $item; }
    } catch (\Exception $e) {
        $core = array_values($coreMap);
    }

    // mc_leader/bic now see the Back Office section directly (department-filtered
    // in render_sidebar()), so no separate Agent Communications shortcut is needed here.
    return array_merge($core, $ext, [
        // Coach Dashboard — collapsible dropdown (Dashboard / Discussions /
        // Resources), same sentinel-injection pattern as '__admin_os__'
        // below. Discussions and Resources already existed as pages but had
        // no nav entry at all (same missing-nav-entry issue Referral
        // Network/Listing Intel had above) -- this is the first time either
        // is reachable without typing the URL directly.
        ['key' => '__coach_dashboard__', 'label' => 'Coach Dashboard', 'href' => '', 'launchCoachOnly' => true],
        ['key' => 'crm',               'label' => 'INNOVATE Advantage', 'href' => 'https://advantage.innovateonline.com', 'external' => true, 'adminOnly' => true],
        ['key' => 'settings_signature','label' => 'My Email Signature', 'href' => 'settings_signature.php','group_label' => 'My Account', 'staffOnly' => true],
        // Same login as this app (same innovate.users account/password) - just a
        // different tool. Lets every agent find their way to the site CMS, which
        // previously had no discovery path at all.
        // 'agent_website_cms' (not 'agent_website') -- that key is already used by the
        // INNOVATE Systems > Agent Website link (nav_ext_links DB row), and go.php's
        // key lookup takes the last-registered item for a given key, so reusing it here
        // silently hijacked that link's destination.
        ['key' => 'agent_website_cms', 'label' => 'My Agent Website',    'href' => 'https://website.innovateonline.com/admin/login', 'external' => true, 'group_label' => 'My Resources'],
        // Only for admins, team leaders, and agents who are on a team —
        // team_dashboard.php itself shows the right view for a leader vs. a
        // plain member. Not shown to agents with no team at all.
        ['key' => 'team_dashboard',    'label' => 'Team Dashboard',      'href' => 'team_dashboard.php',    'group_label' => 'My Resources', 'teamOnly' => true],
    ]);
}

// Items that appear under the "Coach Dashboard" collapsible. Add more
// entries here as the Coach Dashboard area grows.
function coach_dashboard_nav_items(): array {
    return [
        ['key' => 'coach_dashboard',   'label' => 'Dashboard',    'href' => 'coach_dashboard.php'],
        ['key' => 'coach_discussions', 'label' => 'Discussions',  'href' => 'coach_discussions.php'],
        ['key' => 'coach_resources',   'label' => 'Resources',    'href' => 'coach_resources.php'],
    ];
}

// Items that appear under the admin-only "Admin OS" collapsible. Add more
// entries here as Admin Work OS grows — mirrors agent_assets_items() below.
function admin_os_nav_items(): array {
    return [
        ['key' => 'admin_work_os', 'label' => 'Work Dashboard', 'href' => 'admin_work_os.php'],
        ['key' => 'admin_work_routines', 'label' => 'Routines', 'href' => 'admin_work_routines.php'],
    ];
}

// Items that appear under the personalized "[FirstName]'s Assets" collapsible.
// Add more entries here to grow the section.
function agent_assets_items(): array {
    return [
        ['key' => 'network',            'label' => 'My Network',              'href' => 'network.php'],
        // Referral Network: existed and worked (cross-market partner tracking
        // + company-wide requests) but had no menu entry -- same
        // missing-nav-entry bug as Listing Intel below. Added once already
        // on 2026-08-10, then lost when nav.php got overwritten by an older
        // copy during later Market Center work. Re-added 2026-09-03+ -- if
        // this goes missing again, check whether whatever edits nav.php is
        // starting from a stale local backup.
        ['key' => 'referral_network',   'label' => 'Referral Network',        'href' => 'referral_network.php'],
        ['key' => 'profile',            'label' => 'My Profile',              'href' => 'profile.php'],
        // Phase 2A activity-only performance pilot (dials/connects/streak/
        // goal pacing) — the page itself shows a plain "not turned on for
        // you yet" state for agents outside the configured pilot team,
        // same per-agent-book category as buyback.php/listing_intel.php.
        ['key' => 'my_performance',      'label' => 'My Performance',          'href' => 'my_performance.php'],
        // Buy Back Your Time: every producing agent's own book of business,
        // not a backoffice/admin tool -- lives here, not in
        // backoffice_nav_items(), same as profile.php/network.php above.
        ['key' => 'buyback',            'label' => 'Buy Back Your Time',      'href' => 'buyback.php'],
        // My AI Agents: deploy your own AI agent (Sphere Nurture, ...) on your
        // own FUB book -- coastline-server /public/agentedge/ai/*. Same
        // own-book gate as buyback (can_use_buyback). Added 2026-09-30.
        ['key' => 'ai_agents',          'label' => 'My AI Agents',            'href' => 'ai_agents.php'],
    ];
}

// Items that live under the Back Office collapsible (admin only).
// Each item carries a 'dept' key so render_sidebar() can group them.
function backoffice_nav_items(bool $superAdmin): array {
    $items = [
        // ── Operations ──────────────────────────────────────────────────────────
        // 'leaderVisible' items are the only Operations items mc_leader/bic see
        // (render_sidebar() filters to these for that role) — the pages
        // themselves scope their data to the leader's own Market Center(s).
        // Each Back Office tab gets its own scoped Vault link (?dept=slug)
        // straight into just that department's folders — see vault.php's
        // $scopedDept handling, added 2026-10-11 per Darren ("each tab will
        // have its own vault instead of it all being under HR Vault").
        // Not leaderVisible: vault.php itself is strictly admin-tier-gated
        // (redirects anyone who isn't $admin), so showing this to a plain
        // MC leader/BIC would just be a dead link.
        ['key'=>'vault_operations',          'label'=>'Vault',               'href'=>'vault.php?dept=operations',     'dept'=>'Operations'],
        ['key'=>'backoffice_agents',         'label'=>'Agent Profiles',      'href'=>'backoffice_agents.php',         'dept'=>'Operations', 'leaderVisible'=>true],
        ['key'=>'onboarding',                'label'=>'Onboarding Queue',    'href'=>'onboarding.php',                'dept'=>'Operations', 'leaderVisible'=>true],
        ['key'=>'offboarding',               'label'=>'Offboarding Queue',   'href'=>'offboarding.php',               'dept'=>'Operations'],
        ['key'=>'admin_step_notify',         'label'=>'Step Notifications',  'href'=>'admin_step_notify.php',         'dept'=>'Operations'],
        ["key"=>"admin_notification_triggers", "label"=>"Notification Triggers", "href"=>"admin_notification_triggers.php", "dept"=>"Operations"],
        ['key'=>'intake',                    'label'=>'Intake Form',         'href'=>'intake.php',                    'dept'=>'Operations'],
        ['key'=>'backoffice_roster',         'label'=>'Agent Roster',        'href'=>'backoffice_roster.php',         'dept'=>'Operations', 'leaderVisible'=>true],
        ['key'=>'bo_office_closings',        'label'=>'Office Closings',     'href'=>'backoffice_office_closings.php','dept'=>'Operations', 'leaderVisible'=>true],
        // Company-wide cross-MC list — intentionally admin-only, no 'leaderVisible'
        // (see project_mc_leader_bic_operations_access memory).
        ['key'=>'backoffice_production_ranking', 'label'=>'Production Ranking', 'href'=>'backoffice_production_ranking.php', 'dept'=>'Operations'],
        ['key'=>'backoffice_state_rosters',  'label'=>'State Rosters',       'href'=>'backoffice_state_rosters.php',  'dept'=>'Operations'],
        ['key'=>'backoffice_roster_changes', 'label'=>'Roster Changes',      'href'=>'backoffice_roster_changes.php', 'dept'=>'Operations'],
        ['key'=>'admin_import',              'label'=>'Import Agents',       'href'=>'admin_import.php',              'dept'=>'Operations'],
        ['key'=>'conference_rooms',          'label'=>'Conference Rooms',    'href'=>'admin_conference_rooms.php',    'dept'=>'Operations'],
        // ── Broker Files ────────────────────────────────────────────────────────
        ['key'=>'bo_docs',                   'label'=>'Documents',           'href'=>'backoffice_docs.php',           'dept'=>'Broker Files'],
        ['key'=>'bo_mls',                    'label'=>'MLS Integrations',    'href'=>'backoffice_mls.php',            'dept'=>'Broker Files'],
        ['key'=>'bo_state_reports',          'label'=>'State Annual Reports','href'=>'backoffice_state_reports.php', 'dept'=>'Broker Files'],
        ['key'=>'bo_licensing',              'label'=>'Licensing and Renewals','href'=>'backoffice_licensing.php',   'dept'=>'Broker Files'],
        ['key'=>'vault',                     'label'=>'The Vault',           'href'=>'vault.php?dept=brokerage',      'dept'=>'Broker Files'],
        // Second nav entry into the SAME vault.php — gives the Human
        // Resources vault dept (Darren + Michele only, see vault_user_depts;
        // whole department restricted 2026-10-11 per Darren) a home in the
        // Human Resources sidebar section instead of only being reachable
        // via Broker Files > The Vault. Human Resources is already in
        // $leaderHiddenDepts below, so this link itself stays admin-tier-only
        // same as Finance/Technology; the actual restricted content is still
        // gated inside vault.php regardless of nav visibility.
        ['key'=>'vault_hr',                  'label'=>'HR Vault',            'href'=>'vault.php?dept=hr',             'dept'=>'Human Resources'],
        ['key'=>'admin_vault_depts',         'label'=>'Vault Departments',   'href'=>'admin_vault_depts.php',         'dept'=>'Broker Files', 'superOnly'=>true],
        // ── Agent Communications ─────────────────────────────────────────────────
        // 'mc_events' existed and worked (own API, own_mc_slugs+mc_slugs-scoped
        // permission checks, feeds the Company Calendar's Market Center tab via
        // mc_events_cal.php) but had no menu entry anywhere -- same orphaned-page
        // gap as listing_intel.php (see agent_assets_items() above). Confirmed
        // 2026-09-03 chasing a real MC Leader's "North Wales calendar" ticket.
        ['key'=>'vault_comms',               'label'=>'Vault',               'href'=>'vault.php?dept=marketing',      'dept'=>'Agent Communications'],
        ['key'=>'mc_events',                 'label'=>'Market Center Events', 'href'=>'mc_events.php',                'dept'=>'Agent Communications'],
        ['key'=>'bo_announcements',          'label'=>'Announcements',       'href'=>'backoffice_announcements.php',  'dept'=>'Agent Communications'],
        ['key'=>'bo_company_email',          'label'=>'Company Email',       'href'=>'backoffice_email.php',          'dept'=>'Agent Communications'],
        ['key'=>'bo_company_text',           'label'=>'Company Text',        'href'=>'backoffice_texts.php',          'dept'=>'Agent Communications'],
        ['key'=>'bo_agent_tags',             'label'=>'Agent Tags',          'href'=>'backoffice_agent_tags.php',     'dept'=>'Agent Communications'],
        ['key'=>'bo_google_audit',           'label'=>'Google Business Audit','href'=>'backoffice_google_audit.php',  'dept'=>'Agent Communications'],
        ['key'=>'bo_sage',                   'label'=>'Sage: Google & Reviews','href'=>'backoffice_sage.php',        'dept'=>'Agent Communications'],
        ['key'=>'bo_zillow_reviews',         'label'=>'Zillow Review Requests','href'=>'backoffice_zillow_reviews.php', 'dept'=>'Agent Communications'],
        // ── Events ──────────────────────────────────────────────────────────────
        ['key'=>'vault_events',              'label'=>'Vault',               'href'=>'vault.php?dept=events',         'dept'=>'Events'],
        ['key'=>'bo_create_event',           'label'=>'Create Event',        'href'=>'backoffice_create_event.php',   'dept'=>'Events', 'leaderVisible'=>true],
        ['key'=>'bo_industry_events',        'label'=>'Industry Events',     'href'=>'backoffice_industry_events.php','dept'=>'Events'],
        ['key'=>'bo_event_rsvps',            'label'=>'Event RSVPs',         'href'=>'backoffice_event_rsvps.php',    'dept'=>'Events'],
        // ── Agent Development ───────────────────────────────────────────────────
        ['key'=>'vault_agentdev',            'label'=>'Vault',               'href'=>'vault.php?dept=agent-development', 'dept'=>'Agent Development'],
        ['key'=>'admin_university',          'label'=>'University',          'href'=>'admin_university.php',          'dept'=>'Agent Development'],
        ['key'=>'admin_university_templates','label'=>'On Demand',           'href'=>'admin_university_templates.php','dept'=>'Agent Development', 'superOnly'=>true],
        ['key'=>'launch_curriculum',         'label'=>'LAUNCH Curriculum',   'href'=>'launch_curriculum.php',         'dept'=>'Agent Development'],
        ['key'=>'launch_schedule',           'label'=>'Launch Schedule',     'href'=>'launch_schedule.php',           'dept'=>'Agent Development'],
        ['key'=>'launch_coaching',           'label'=>'Launch Coaching',     'href'=>'launch_coaching.php',           'dept'=>'Agent Development'],
        // ── Finance ─────────────────────────────────────────────────────────────
        // Untagged (no financeChecklistItem/annualReportsItem/mlsCostsItem/
        // mlsapItem): shows for a true super_admin (Darren/Michele, who see
        // the whole Finance dept untagged-and-all) but not for the 'admin'
        // role or any of the narrower named-allowlist bypass viewers, since
        // the Vault's own 'finance' dept grants are a separate, narrower
        // list than any of those four — matches vault.php's own gating.
        ['key'=>'vault_finance',             'label'=>'Vault',               'href'=>'vault.php?dept=finance',        'dept'=>'Finance'],
        ['key'=>'finance_exchange_readiness','label'=>'Exchange Readiness',  'href'=>'finance_exchange_readiness.php','dept'=>'Finance', 'superOnly'=>true],
        ['key'=>'finance_checklists',        'label'=>'Accounting Checklists','href'=>'finance_checklists.php',        'dept'=>'Finance', 'financeChecklistItem'=>true],
        ['key'=>'bo_annual_reports',         'label'=>'State Annual Reports', 'href'=>'backoffice_annual_reports.php', 'dept'=>'Finance', 'annualReportsItem'=>true],
        // bo_mls_costs retired from nav 2026-10-07 — superseded by MLS &
        // Boards Payables below. The page/table stay in place (Atlas's
        // Python agent still writes mls_cost_items.last_paid directly), just
        // not linked here anymore so Darren/Michele use the new one instead.
        ['key'=>'bo_mlsap',                  'label'=>'MLS & Boards Payables', 'href'=>'backoffice_mlsap.php',         'dept'=>'Finance', 'mlsapItem'=>true],
        ['key'=>'bo_commissions',            'label'=>'Commission Report',   'href'=>'backoffice_commissions.php',    'dept'=>'Finance', 'financeChecklistItem'=>true],
        ['key'=>'bo_atlas',                  'label'=>'Atlas (MLS monitor)',  'href'=>'backoffice_atlas.php',          'dept'=>'Finance', 'mlsCostsItem'=>true],
        ['key'=>'bo_insurance_quotes',       'label'=>'Insurance Quote Requests','href'=>'backoffice_insurance_quotes.php', 'dept'=>'Finance'],
        // ── Technology ──────────────────────────────────────────────────────────
        ['key'=>'vault_technology',          'label'=>'Vault',               'href'=>'vault.php?dept=technology',     'dept'=>'Technology'],
        ['key'=>'bo_login_report',           'label'=>'Login Report',        'href'=>'backoffice_login_report.php',   'dept'=>'Technology'],
        ['key'=>'bo_sync_health',            'label'=>'Sync Health',         'href'=>'backoffice_sync_health.php',    'dept'=>'Technology'],
        ['key'=>'bo_join_stats',             'label'=>'Join Site Analytics', 'href'=>'backoffice_join_stats.php',     'dept'=>'Technology'],
        ['key'=>'admin_agent_login',         'label'=>'Agent Login Access',  'href'=>'admin_agent_login.php',         'dept'=>'Technology'],
        ['key'=>'bo_tickets',                'label'=>'Tickets',             'href'=>'backoffice_tickets.php',        'dept'=>'Technology'],
        ['key'=>'admin_support_depts',       'label'=>'Ticket Departments',  'href'=>'admin_support_depts.php',       'dept'=>'Technology'],
        ['key'=>'admin_who_does_what',       'label'=>'Who Does What',       'href'=>'admin_who_does_what.php',       'dept'=>'Technology'],
        ['key'=>'bo_referral',               'label'=>'Referral',            'href'=>'backoffice_referral.php',       'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'admin_roles',               'label'=>'Role Assignments',    'href'=>'admin_roles.php',               'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'teams',                     'label'=>'Teams',               'href'=>'teams.php',                     'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'admin_links',               'label'=>'Link Settings',       'href'=>'admin_links.php',               'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'admin_backoffice',          'label'=>'Menu Builder',        'href'=>'admin_backoffice.php',          'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'admin_dotloop_tokens',      'label'=>'DotLoop Tokens',      'href'=>'admin_dotloop_tokens.php',      'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'admin_pandadoc_templates',  'label'=>'PandaDoc Templates',  'href'=>'admin_pandadoc_templates.php',  'dept'=>'Technology', 'superOnly'=>true],
        // Controlled per-staff rollout of internal feature flags (currently
        // just Admin Work OS) — see lib/feature_flags.php and
        // staff_feature_flags in local_db.php. super_admin-only, same as
        // every other Technology settings surface above.
        ['key'=>'staff_feature_access',      'label'=>'Staff Feature Access','href'=>'staff_feature_access.php',      'dept'=>'Technology', 'superOnly'=>true],
        // ── Legal ───────────────────────────────────────────────────────────────
        // New tab added 2026-10-11 per Darren, for the restricted "Legal"
        // Vault department (court docs etc. — darren@/michele@ only, same as
        // Human Resources). Legal is in $leaderHiddenDepts below, same tier
        // as Finance/Human Resources/Technology.
        ['key'=>'vault_legal',               'label'=>'Vault',               'href'=>'vault.php?dept=legal',          'dept'=>'Legal'],
    ];
    foreach (backoffice_items_all() as $r) {
        $item = ['key'=>'bo_'.$r['id'], 'label'=>$r['label'], 'href'=>$r['url'], 'dept'=>($r['department'] ?? 'Operations')];
        if ($r['is_ext']) $item['external'] = true;
        $items[] = $item;
    }
    return $items;
}

// Best-effort page-view log — never fatal, since a logging failure shouldn't
// take down the page it's trying to measure.
function nav_log_page_view(string $key, string $type, string $email): void {
    if ($key === '') return;
    try {
        local_db()->prepare("INSERT INTO page_views (email,page_key,page_type) VALUES (?,?,?)")
            ->execute([strtolower(trim($email)), $key, $type]);
    } catch (\Throwable $e) {}
}

// Every nav item, from every source, key => label. Used to resolve page_views
// rows to a human-readable label at report time (backoffice_login_report.php)
// without duplicating the label everywhere a view gets logged.
function nav_all_items_by_key(): array {
    $map = [];
    foreach (nav_items() as $it) $map[$it['key']] = $it;
    foreach (backoffice_nav_items(true) as $it) $map[$it['key']] = $it;
    foreach (agent_assets_items() as $it) $map[$it['key']] = $it;
    foreach (admin_os_nav_items() as $it) $map[$it['key']] = $it;
    return $map;
}

// Resolves an external nav item by key for go.php's tracked redirect — the
// URL always comes from this pre-registered lookup, never from a query
// string, so go.php can't be used as an open redirect.
function nav_resolve_external(string $key): ?array {
    $it = nav_all_items_by_key()[$key] ?? null;
    if (!$it || empty($it['external']) || empty($it['href'])) return null;
    return ['url' => $it['href'], 'label' => $it['label'] ?? $key];
}

function render_sidebar(string $current, array $agent): void {
    nav_log_page_view($current, 'internal', $agent['email'] ?? '');
    $perms = current_perms();
    $admin = !empty($perms['isAdmin']);
    $demo  = !empty(cfg()['demo']);

    // Restricted seat (e.g. a lender/vendor partner given calendar-only
    // access, see auth.php's require_login()) — a minimal sidebar with just
    // the one allowed page and sign-out, not the full nav tree they can't
    // use anyway (every link but one would just bounce them back here).
    $restrictedTo = $perms['restricted_to'] ?? '';
    if ($restrictedTo !== '') {
        $label = ucfirst($restrictedTo);
        echo '<aside class="sidebar"><div class="sb-top"><span class="sb-brand"><span class="brand">INNOVATE</span> <span class="brand-edge">AgentEdge</span></span></div><nav class="sb-nav">'
           . '<a class="sb-item sb-active" href="' . htmlspecialchars($restrictedTo) . '.php">' . htmlspecialchars($label) . '</a>'
           . '</nav><div class="sb-bottom">'
           . '<a class="sb-signout" href="logout.php">Sign out</a></div></aside>';
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
        echo '<script>window.AE_CSRF = ' . json_encode($_SESSION['csrf']) . ';</script>';
        return;
    }

    // Masquerade banner — shown when a super_admin is logged in as another agent.
    if (function_exists('is_masquerading') && is_masquerading()) {
        $orig = original_admin();
        $viewing = htmlspecialchars($agent['name'] ?: $agent['email']);
        echo '<div class="masq-bar">Viewing as <strong>' . $viewing . '</strong> &mdash; '
           . '<button class="masq-back" onclick="stopMasquerade()">Back to Admin</button></div>';
    }

    echo '<aside class="sidebar"><div class="sb-top"><a class="sb-brand" href="index.php" style="text-decoration:none;color:inherit"><span class="brand">INNOVATE</span> <span class="brand-edge">AgentEdge</span></a><button class="sb-toggle" onclick="toggleSidebar()" aria-label="Open navigation">&#9776;</button></div><nav class="sb-nav">';
    $superAdmin = !empty($perms['isSuperAdmin']);
    // Build personalized label once for use in the loop.
    $nameParts = preg_split('/\s+/', trim($agent['name'] ?? ''));
    $firstName = $nameParts[0] ?? '';
    if ($firstName === '' || strpos($firstName, '@') !== false) {
        $firstName = ucfirst(strtolower(explode('@', $agent['email'] ?? 'My')[0]));
    }
    $assetsLabel = htmlspecialchars($firstName) . "'s Assets";

    // Bucket items by group_label (in first-seen order) rather than emitting
    // a new collapsible every time the label changes — core pages have no
    // group_label of their own and external links may be interleaved with
    // custom sub-menus, so a simple "did the label change" check would open
    // and close the same group multiple times.
    $groups     = [];
    $groupOrder = [];
    foreach (nav_items() as $it) {
        if (!empty($it['adminOnly']) && !$admin) continue;
        if (!empty($it['superOnly']) && !$superAdmin) continue;
        if (!empty($it['leaderOnly']) && !can_post_announcements() && !is_recruiter()) continue;
        if (!empty($it['launchCoachOnly']) && !is_launch_coach() && !$admin) continue;
        if (!empty($it['staffOnly']) && in_array(my_role(), ['agent', 'launch_agent'], true)) continue;
        if (!empty($it['teamOnly']) && !$admin && !is_team_leader() && my_own_team_id() === null) continue;
        if (!empty($it['launchLeaderboardOnly']) && !can_view_launch_leaderboard()) continue;

        // Sentinel — inject the Coach Dashboard collapsible inline. Reaching
        // this point already means the launchCoachOnly filter above passed
        // (launch coach or admin).
        if ($it['key'] === '__coach_dashboard__') {
            echo '<button class="sb-links-toggle" data-group="coach-dashboard" onclick="toggleSbLinks(this)" aria-expanded="false">'
               . 'Coach Dashboard <span class="sb-links-arrow">&#9660;</span></button>';
            echo '<div class="sb-links-sub" hidden>';
            foreach (coach_dashboard_nav_items() as $ci) {
                $act = $ci['key'] === $current ? ' sb-active' : '';
                echo '<a class="sb-item' . $act . '" href="' . htmlspecialchars($ci['href']) . '">'
                   . htmlspecialchars($ci['label']) . '</a>';
            }
            echo '</div>';
            continue;
        }

        // Sentinel — inject the admin-only Admin OS collapsible inline, above
        // the personalized Assets section below. Reaching this point already
        // means $admin is true (the generic adminOnly filter above skipped
        // this item entirely otherwise); also requires the admin_work_os
        // Staff Feature Access flag (super_admin always passes) — a staff
        // member with the feature OFF gets no Admin OS nav section at all,
        // same as a non-admin.
        if ($it['key'] === '__admin_os__') {
            if (!feature_enabled_for_current_user('admin_work_os')) continue;
            echo '<button class="sb-links-toggle" data-group="admin-os" onclick="toggleSbLinks(this)" aria-expanded="false">'
               . 'Admin OS <span class="sb-links-arrow">&#9660;</span></button>';
            echo '<div class="sb-links-sub" hidden>';
            foreach (admin_os_nav_items() as $ai) {
                $act = $ai['key'] === $current ? ' sb-active' : '';
                echo '<a class="sb-item' . $act . '" href="' . htmlspecialchars($ai['href']) . '">'
                   . htmlspecialchars($ai['label']) . '</a>';
            }
            echo '</div>';
            continue;
        }

        // Sentinel — inject the personalized assets collapsible inline.
        if ($it['key'] === '__assets__') {
            echo '<button class="sb-links-toggle" data-group="my-assets" onclick="toggleSbLinks(this)" aria-expanded="false">'
               . $assetsLabel . ' <span class="sb-links-arrow">&#9660;</span></button>';
            echo '<div class="sb-links-sub" hidden>';
            foreach (agent_assets_items() as $ai) {
                // 'buyback' is the one item here without its own client
                // book for some roles (recruiting/coaching staff) -- hide
                // rather than show a tool with nothing to act on.
                if (in_array($ai['key'], ['buyback', 'ai_agents'], true) && !can_use_buyback()) continue;
                $act = $ai['key'] === $current ? ' sb-active' : '';
                // AI agents item is personalized like the section itself ("Darren's Agents").
                $label = $ai['key'] === 'ai_agents' ? $firstName . "'s Agents" : $ai['label'];
                echo '<a class="sb-item' . $act . '" href="' . htmlspecialchars($ai['href']) . '">'
                   . htmlspecialchars($label) . '</a>';
            }
            echo '</div>';
            continue;
        }

        // Core pages and any external link without its own configured
        // sub-menu fold into one "Company" group instead of sitting bare
        // at the top level — fewer top-level items to scan.
        $gl = ($it['group_label'] ?? '') !== '' ? $it['group_label'] : 'Company';
        if (!isset($groups[$gl])) { $groups[$gl] = []; $groupOrder[] = $gl; }
        $groups[$gl][] = $it;
    }
    foreach ($groupOrder as $gl) {
        $sg = htmlspecialchars($gl);
        echo '<button class="sb-links-toggle" data-group="' . $sg . '" onclick="toggleSbLinks(this)" aria-expanded="false">'
           . $sg . ' <span class="sb-links-arrow">&#9660;</span></button>';
        echo '<div class="sb-links-sub" hidden>';
        foreach ($groups[$gl] as $it) {
            $active = $it['key'] === $current ? ' sb-active' : '';
            $ext    = !empty($it['external']) ? ' target="_blank" rel="noopener"' : '';
            $arrow  = !empty($it['external']) ? ' <span class="sb-ext">↗</span>' : '';
            $badge  = !empty($it['adminOnly']) ? ' <span class="sb-admin">Admin</span>' : '';
            $href   = !empty($it['external']) ? ('go.php?key=' . urlencode($it['key'])) : $it['href'];
            echo '<a class="sb-item' . $active . '" href="' . htmlspecialchars($href) . '"' . $ext . '>' . htmlspecialchars($it['label']) . $arrow . $badge . '</a>';
        }
        echo '</div>';
    }

    // Back Office section — admins see everything; mc_leader/bic see it too, but with
    // Finance, Human Resources, and Technology departments hidden entirely, and
    // Operations filtered down to just the 'leaderVisible' items (Agent Profiles /
    // Agent Roster / Onboarding Queue — all three scope their own data to the
    // leader's MC). Launch coaches (the other non-admin group that reaches this
    // block) see no Operations items.
    // Finance Accounting Checklists is scoped to 3 named staff (see roles.php),
    // narrower than any role tier — so it needs its own bypass into a section
    // (Finance) that's otherwise hidden from non-admins entirely.
    $financeChecklistAccess = can_access_finance_checklists();
    // State Annual Reports is the same shape of bypass as Finance Accounting
    // Checklists just above — its own named allowlist (see
    // ANNUAL_REPORTS_EMAILS in roles.php), not a role tier.
    $annualReportsAccess = can_access_annual_reports();
    // MLS & Board Costs is the same shape of bypass as State Annual Reports
    // just above — its own named allowlist (MLS_COSTS_EMAILS in roles.php).
    $mlsCostsAccess = can_access_mls_costs();
    // MLS & Boards Payables is the same shape of bypass again (MLSAP_EMAILS
    // in roles.php) — supersedes MLS & Board Costs for day-to-day use, but
    // that page/access flag stay as-is since Atlas is also gated on it.
    $mlsapAccess = can_access_mlsap();
    $showBackOffice = $admin || is_mc_leader() || is_bic() || is_launch_coach() || $financeChecklistAccess || $annualReportsAccess || $mlsCostsAccess || $mlsapAccess || is_team_leader();
    $leaderHiddenDepts = ['Finance', 'Human Resources', 'Technology', 'Legal'];
    $isMcLeaderOrBic = is_mc_leader() || is_bic();
    if ($showBackOffice) {
        static $boStylesEmitted = false;
        if (!$boStylesEmitted) {
            $boStylesEmitted = true;
            echo '<style>'
               . '.sb-dept-toggle{all:unset;display:flex;align-items:center;gap:4px;width:100%;'
               . 'padding:5px 12px 3px 16px;font-size:10px;font-weight:800;text-transform:uppercase;'
               . 'letter-spacing:.07em;color:var(--faint,#999);cursor:pointer;box-sizing:border-box}'
               . '.sb-dept-toggle .sb-links-arrow{margin-left:auto;font-size:8px;transition:transform .2s}'
               . '.sb-depth-2{padding-left:26px!important}'
               . '.sb-dept-empty{display:block;font-size:11px;color:var(--faint,#bbb);'
               . 'padding:3px 12px 3px 26px;font-style:italic}'
               . '</style>';
        }
        $boItems    = backoffice_nav_items($superAdmin);
        $standalone = array_values(array_filter($boItems, fn($it) => !empty($it['standalone'])));
        $deptItems  = array_values(array_filter($boItems, fn($it) => empty($it['standalone'])));
        $deptOrder  = ['Operations','Finance','Broker Files','Agent Communications','Events','Agent Development','Technology','Human Resources','Legal'];
        $byDept     = array_fill_keys($deptOrder, []);
        foreach ($deptItems as $it) {
            $d = $it['dept'] ?? 'Operations';
            if (!array_key_exists($d, $byDept)) $byDept[$d] = [];
            $byDept[$d][] = $it;
        }
        $activeDept = '';
        foreach ($byDept as $dn => $ditems) {
            foreach ($ditems as $it) {
                if ($it['key'] === $current) { $activeDept = $dn; break 2; }
            }
        }
        echo '<button class="sb-links-toggle" data-group="Back Office" onclick="toggleSbLinks(this)" aria-expanded="false">'
           . 'Back Office <span class="sb-links-arrow">&#9660;</span></button>';
        echo '<div class="sb-links-sub" hidden>';
        foreach ($standalone as $it) {
            if (!empty($it['superOnly']) && !$superAdmin) continue;
            $act  = $it['key'] === $current ? ' sb-active' : '';
            $xt   = !empty($it['external']) ? ' target="_blank" rel="noopener"' : '';
            $arr  = !empty($it['external']) ? ' <span class="sb-ext">↗</span>' : '';
            $href = !empty($it['external']) ? ('go.php?key=' . urlencode($it['key'])) : $it['href'];
            echo '<a class="sb-item sb-depth-2' . $act . '" href="' . htmlspecialchars($href) . '"' . $xt . '>'
               . htmlspecialchars($it['label']) . $arr . '</a>';
        }
        foreach ($deptOrder as $deptName) {
            $financeChecklistBypass = $financeChecklistAccess && $deptName === 'Finance';
            $annualReportsBypass = $annualReportsAccess && $deptName === 'Finance';
            $mlsCostsBypass = $mlsCostsAccess && $deptName === 'Finance';
            $mlsapBypass = $mlsapAccess && $deptName === 'Finance';
            if (!$admin && in_array($deptName, $leaderHiddenDepts, true) && !$financeChecklistBypass && !$annualReportsBypass && !$mlsCostsBypass && !$mlsapBypass) continue;
            // Finance stays hidden from the 'admin' role too (is_admin() but
            // not is_super_admin(), split off 2026-10-08) — unlike HR/
            // Technology just above, which 'admin' sees in full like any
            // other admin-tier role.
            if ($deptName === 'Finance' && !$superAdmin && !$financeChecklistBypass && !$annualReportsBypass && !$mlsCostsBypass && !$mlsapBypass) continue;
            if (!$admin && $deptName === 'Operations' && !$isMcLeaderOrBic) continue;
            $dItems  = $byDept[$deptName] ?? [];
            $visible = array_values(array_filter($dItems, fn($it) =>
                (empty($it['superOnly']) || $superAdmin) && (empty($it['teamLeaderOnly']) || is_team_leader())
                // 'bicOrAdminOnly' — for items narrower than their department's
                // default visibility (e.g. Agent Communications is otherwise
                // visible to mc_leader too, matching every other item there,
                // but a specific feature may need admin/bic only for now).
                && (empty($it['bicOrAdminOnly']) || $admin || is_bic())
            ));
            if (!$admin && $deptName === 'Operations') {
                $visible = array_values(array_filter($visible, fn($it) => !empty($it['leaderVisible'])));
            }
            if (!$admin && ($financeChecklistBypass || $annualReportsBypass || $mlsCostsBypass || $mlsapBypass)) {
                $visible = array_values(array_filter($visible, fn($it) =>
                    ($financeChecklistBypass && !empty($it['financeChecklistItem'])) ||
                    ($annualReportsBypass && !empty($it['annualReportsItem'])) ||
                    ($mlsCostsBypass && !empty($it['mlsCostsItem'])) ||
                    ($mlsapBypass && !empty($it['mlsapItem']))
                ));
            }
            if ($deptName === 'Technology') {
                usort($visible, fn($a, $b) => strcasecmp($a['label'], $b['label']));
            }
            echo '<button class="sb-dept-toggle" data-group="dept-' . htmlspecialchars($deptName) . '" onclick="toggleSbLinks(this)" aria-expanded="false">'
               . htmlspecialchars($deptName) . ' <span class="sb-links-arrow">&#9660;</span></button>';
            echo '<div class="sb-links-sub" hidden>';
            if (empty($visible)) {
                echo '<span class="sb-dept-empty">No items</span>';
            }
            foreach ($visible as $it) {
                $act  = $it['key'] === $current ? ' sb-active' : '';
                $xt   = !empty($it['external']) ? ' target="_blank" rel="noopener"' : '';
                $arr  = !empty($it['external']) ? ' <span class="sb-ext">↗</span>' : '';
                $href = !empty($it['external']) ? ('go.php?key=' . urlencode($it['key'])) : $it['href'];
                echo '<a class="sb-item sb-depth-2' . $act . '" href="' . htmlspecialchars($href) . '"' . $xt . '>'
                   . htmlspecialchars($it['label']) . $arr . '</a>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    // MC-specific links injected here by mc-links.js
    echo '<div id="mc-resources" hidden></div>';
    // Agent's own favorite links injected here by mc-links.js
    echo '<div id="my-links" hidden></div>';
    $who = htmlspecialchars($agent['name'] ?: $agent['email']);
    $role = role_label($perms['role'] ?? 'agent');
    echo '</nav><div class="sb-foot"><div class="sb-who">' . $who . '<span class="sb-role">' . htmlspecialchars($role) . '</span></div>';
    if ($demo) {
        // Preview-only role switcher.
        echo '<select class="sb-roleswitch" onchange="location.search=\'?role=\'+this.value">';
        foreach (ROLE_LABELS as $k => $lbl) {
            $sel = ($perms['role'] ?? 'agent') === $k ? ' selected' : '';
            echo '<option value="' . $k . '"' . $sel . '>' . htmlspecialchars($lbl) . '</option>';
        }
        echo '</select>';
    }
    // How-To library search -- a single injection point here puts it on
    // every one of the ~85 pages that already call render_sidebar(), with
    // no per-page edits. See assets/howto_search.js + api/howto_search.php.
    echo '<div class="sb-search-wrap">'
       . '<button class="sb-support" onclick="toggleHowtoSearch()">? How do I...</button>'
       . '<div class="sb-search-box" id="sb-search-box" hidden>'
       . '<input type="text" id="sb-search-input" class="sb-search-input" placeholder="Start typing..." autocomplete="off">'
       . '<div class="sb-search-results" id="sb-search-results" hidden></div>'
       . '</div></div>';
    echo '<button class="sb-support" onclick="openSupportModal()">Get Support</button>';
    echo '<a class="sb-signout" href="logout.php">Sign out</a></div></aside>';
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    echo '<script>window.AE_CSRF = ' . json_encode($_SESSION['csrf']) . ';</script>';
    echo '<script src="assets/mc-links.js"></script>';
    echo '<script src="assets/global.js"></script>';
    echo '<script src="assets/howto_search.js"></script>';
    if (function_exists('is_masquerading') && is_masquerading()) {
        echo '<script>document.body.classList.add("masquerading")</script>';
    }
}
