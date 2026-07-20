<?php
if (defined('AGENTEDGE_NAV_LOADED')) return;
define('AGENTEDGE_NAV_LOADED', true);
// The agent menu, in one place. Edit this list to change the sidebar
// everywhere. `external => true` marks an SSO link out to another system.
// `adminOnly => true` hides the item from non-admins (super/retention admin).
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';

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
        'calendar'         => ['key' => 'calendar',         'label' => 'Company Calendar',    'href' => 'calendar.php'],
        // 'event_planner' hidden from the sidebar for now — page still exists at event_planner.php.
        '__assets__'       => ['key' => '__assets__',       'label' => '',                    'href' => ''],
        'industry_events'  => ['key' => 'industry_events',  'label' => 'Industry Events',     'href' => 'industry_events.php'],
        'university'       => ['key' => 'university',       'label' => 'INNOVATE University', 'href' => 'university.php'],
        'leaderboard'      => ['key' => 'leaderboard',      'label' => 'LAUNCH Leaderboard',  'href' => 'leaderboard.php'],
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
        ['key' => 'coach_dashboard', 'label' => 'Coach Dashboard', 'href' => 'coach_dashboard.php', 'launchCoachOnly' => true],
        ['key' => 'crm', 'label' => 'INNOVATE Advantage', 'href' => 'https://advantage.innovateonline.com', 'external' => true, 'adminOnly' => true],
    ]);
}

// Items that appear under the personalized "[FirstName]'s Assets" collapsible.
// Add more entries here to grow the section.
function agent_assets_items(): array {
    return [
        ['key' => 'network',            'label' => 'My Network',              'href' => 'network.php'],
        ['key' => 'profile',            'label' => 'My Profile',              'href' => 'profile.php'],
        ['key' => 'commission_submit',  'label' => 'Submit Commission Check', 'href' => 'commission_submit.php'],
        ['key' => 'my_activity',        'label' => 'My Weekly Activity',      'href' => 'my_activity.php'],
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
        ['key'=>'vault',                     'label'=>'The Vault',           'href'=>'vault.php',                     'standalone'=>true],
        ['key'=>'backoffice_agents',         'label'=>'Agent Profiles',      'href'=>'backoffice_agents.php',         'dept'=>'Operations', 'leaderVisible'=>true],
        ['key'=>'onboarding',                'label'=>'Onboarding Queue',    'href'=>'onboarding.php',                'dept'=>'Operations'],
        ['key'=>'offboarding',               'label'=>'Offboarding Queue',   'href'=>'offboarding.php',               'dept'=>'Operations'],
        ['key'=>'admin_step_notify',         'label'=>'Step Notifications',  'href'=>'admin_step_notify.php',         'dept'=>'Operations'],
        ['key'=>'intake',                    'label'=>'Intake Form',         'href'=>'intake.php',                    'dept'=>'Operations'],
        ['key'=>'backoffice_roster',         'label'=>'Agent Roster',        'href'=>'backoffice_roster.php',         'dept'=>'Operations', 'leaderVisible'=>true],
        ['key'=>'recruit_prospects',         'label'=>'Recruiting Prospects','href'=>'backoffice_prospects.php',      'dept'=>'Operations', 'superOnly'=>true],
        ['key'=>'backoffice_state_rosters',  'label'=>'State Rosters',       'href'=>'backoffice_state_rosters.php',  'dept'=>'Operations'],
        ['key'=>'backoffice_roster_changes', 'label'=>'Roster Changes',      'href'=>'backoffice_roster_changes.php', 'dept'=>'Operations'],
        ['key'=>'admin_import',              'label'=>'Import Agents',       'href'=>'admin_import.php',              'dept'=>'Operations'],
        // ── Broker Files ────────────────────────────────────────────────────────
        ['key'=>'bo_docs',                   'label'=>'Documents',           'href'=>'backoffice_docs.php',           'dept'=>'Broker Files'],
        ['key'=>'bo_mls',                    'label'=>'MLS',                 'href'=>'backoffice_mls.php',            'dept'=>'Broker Files'],
        ['key'=>'admin_vault_depts',         'label'=>'Vault Departments',   'href'=>'admin_vault_depts.php',         'dept'=>'Broker Files', 'superOnly'=>true],
        // ── Agent Communications ─────────────────────────────────────────────────
        ['key'=>'bo_announcements',          'label'=>'Announcements',       'href'=>'backoffice_announcements.php',  'dept'=>'Agent Communications'],
        ['key'=>'bo_company_email',          'label'=>'Company Email',       'href'=>'backoffice_email.php',          'dept'=>'Agent Communications'],
        // ── Events ──────────────────────────────────────────────────────────────
        ['key'=>'bo_industry_events',        'label'=>'Industry Events',     'href'=>'backoffice_industry_events.php','dept'=>'Events'],
        ['key'=>'bo_event_rsvps',            'label'=>'Event RSVPs',         'href'=>'backoffice_event_rsvps.php',    'dept'=>'Events'],
        ['key'=>'press_release',             'label'=>'Press Release',       'href'=>'press_release.php',             'dept'=>'Events'],
        // ── Agent Development ───────────────────────────────────────────────────
        ['key'=>'admin_university',          'label'=>'University',          'href'=>'admin_university.php',          'dept'=>'Agent Development'],
        ['key'=>'bo_workflows',              'label'=>'Workflows',           'href'=>'backoffice_workflows.php',      'dept'=>'Agent Development'],
        ['key'=>'launch_cohorts',            'label'=>'LAUNCH Cohorts',      'href'=>'launch_cohorts.php',            'dept'=>'Agent Development'],
        // ── Finance ─────────────────────────────────────────────────────────────
        ['key'=>'finance_budget',            'label'=>'Department Budget',   'href'=>'finance_budget.php',            'dept'=>'Finance'],
        ['key'=>'finance_statements',        'label'=>'Statement Scanner',   'href'=>'finance_statements.php',        'dept'=>'Finance'],
        ['key'=>'listing_intel_billing',     'label'=>'Listing Intel Billing','href'=>'backoffice_listing_intel_billing.php','dept'=>'Finance'],
        ['key'=>'finance_exchange_readiness','label'=>'Exchange Readiness',  'href'=>'finance_exchange_readiness.php','dept'=>'Finance', 'superOnly'=>true],
        ['key'=>'bo_commission_checks',      'label'=>'Commission Checks',   'href'=>'backoffice_commission_checks.php', 'dept'=>'Finance'],
        // ── Technology ──────────────────────────────────────────────────────────
        ['key'=>'bo_login_report',           'label'=>'Login Report',        'href'=>'backoffice_login_report.php',   'dept'=>'Technology'],
        ['key'=>'admin_agent_login',         'label'=>'Agent Login Access',  'href'=>'admin_agent_login.php',         'dept'=>'Technology'],
        ['key'=>'bo_tickets',                'label'=>'Tickets',             'href'=>'backoffice_tickets.php',        'dept'=>'Technology'],
        ['key'=>'admin_support_depts',       'label'=>'Ticket Departments',  'href'=>'admin_support_depts.php',       'dept'=>'Technology'],
        ['key'=>'admin_roles',               'label'=>'Role Assignments',    'href'=>'admin_roles.php',               'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'admin_links',               'label'=>'Link Settings',       'href'=>'admin_links.php',               'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'admin_backoffice',          'label'=>'Menu Builder',        'href'=>'admin_backoffice.php',          'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'admin_dotloop_tokens',      'label'=>'DotLoop Tokens',      'href'=>'admin_dotloop_tokens.php',      'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'admin_pandadoc_templates',  'label'=>'PandaDoc Templates',  'href'=>'admin_pandadoc_templates.php',  'dept'=>'Technology', 'superOnly'=>true],
    ];
    foreach (backoffice_items_all() as $r) {
        $item = ['key'=>'bo_'.$r['id'], 'label'=>$r['label'], 'href'=>$r['url'], 'dept'=>($r['department'] ?? 'Operations')];
        if ($r['is_ext']) $item['external'] = true;
        $items[] = $item;
    }
    return $items;
}

function render_sidebar(string $current, array $agent): void {
    $perms = current_perms();
    $admin = !empty($perms['isAdmin']);
    $demo  = !empty(cfg()['demo']);

    // Masquerade banner — shown when a super_admin is logged in as another agent.
    if (function_exists('is_masquerading') && is_masquerading()) {
        $orig = original_admin();
        $viewing = htmlspecialchars($agent['name'] ?: $agent['email']);
        echo '<div class="masq-bar">Viewing as <strong>' . $viewing . '</strong> &mdash; '
           . '<button class="masq-back" onclick="stopMasquerade()">Back to Admin</button></div>';
    }

    echo '<aside class="sidebar"><a class="sb-brand" href="index.php" style="display:block;text-decoration:none;color:inherit"><span class="brand">INNOVATE</span> <span class="brand-edge">AgentEdge</span></a><nav class="sb-nav">';
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

        // Sentinel — inject the personalized assets collapsible inline.
        if ($it['key'] === '__assets__') {
            echo '<button class="sb-links-toggle" data-group="my-assets" onclick="toggleSbLinks(this)" aria-expanded="false">'
               . $assetsLabel . ' <span class="sb-links-arrow">&#9660;</span></button>';
            echo '<div class="sb-links-sub" hidden>';
            foreach (agent_assets_items() as $ai) {
                $act = $ai['key'] === $current ? ' sb-active' : '';
                echo '<a class="sb-item' . $act . '" href="' . htmlspecialchars($ai['href']) . '">'
                   . htmlspecialchars($ai['label']) . '</a>';
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
            echo '<a class="sb-item' . $active . '" href="' . htmlspecialchars($it['href']) . '"' . $ext . '>' . htmlspecialchars($it['label']) . $arrow . $badge . '</a>';
        }
        echo '</div>';
    }

    // Back Office section — admins see everything; mc_leader/bic see it too, but with
    // Finance, Human Resources, and Technology departments hidden entirely, and
    // Operations filtered down to just the 'leaderVisible' items (Agent Profiles /
    // Agent Roster — both scope their own data to the leader's MC). Launch coaches
    // (the other non-admin group that reaches this block) see no Operations items.
    $showBackOffice = $admin || is_mc_leader() || is_bic() || is_launch_coach();
    $leaderHiddenDepts = ['Finance', 'Human Resources', 'Technology'];
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
        $deptOrder  = ['Operations','Finance','Broker Files','Agent Communications','Events','Agent Development','Technology','Human Resources'];
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
            $act = $it['key'] === $current ? ' sb-active' : '';
            $xt  = !empty($it['external']) ? ' target="_blank" rel="noopener"' : '';
            $arr = !empty($it['external']) ? ' <span class="sb-ext">↗</span>' : '';
            echo '<a class="sb-item sb-depth-2' . $act . '" href="' . htmlspecialchars($it['href']) . '"' . $xt . '>'
               . htmlspecialchars($it['label']) . $arr . '</a>';
        }
        foreach ($deptOrder as $deptName) {
            if (!$admin && in_array($deptName, $leaderHiddenDepts, true)) continue;
            if (!$admin && $deptName === 'Operations' && !$isMcLeaderOrBic) continue;
            $dItems  = $byDept[$deptName] ?? [];
            $visible = array_values(array_filter($dItems, fn($it) => empty($it['superOnly']) || $superAdmin));
            if (!$admin && $deptName === 'Operations') {
                $visible = array_values(array_filter($visible, fn($it) => !empty($it['leaderVisible'])));
            }
            echo '<button class="sb-dept-toggle" data-group="dept-' . htmlspecialchars($deptName) . '" onclick="toggleSbLinks(this)" aria-expanded="false">'
               . htmlspecialchars($deptName) . ' <span class="sb-links-arrow">&#9660;</span></button>';
            echo '<div class="sb-links-sub" hidden>';
            if (empty($visible)) {
                echo '<span class="sb-dept-empty">No items</span>';
            }
            foreach ($visible as $it) {
                $act = $it['key'] === $current ? ' sb-active' : '';
                $xt  = !empty($it['external']) ? ' target="_blank" rel="noopener"' : '';
                $arr = !empty($it['external']) ? ' <span class="sb-ext">↗</span>' : '';
                echo '<a class="sb-item sb-depth-2' . $act . '" href="' . htmlspecialchars($it['href']) . '"' . $xt . '>'
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
    echo '<button class="sb-support" onclick="openSupportModal()">Get Support</button>';
    echo '<a class="sb-signout" href="logout.php">Sign out</a></div></aside>';
    echo '<script src="assets/mc-links.js"></script>';
    echo '<script src="assets/global.js"></script>';
    if (function_exists('is_masquerading') && is_masquerading()) {
        echo '<script>document.body.classList.add("masquerading")</script>';
    }
}
