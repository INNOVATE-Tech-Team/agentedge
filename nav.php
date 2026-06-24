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
        } else {
            $item['external'] = true;
        }
        return $item;
    }, $extLinks);

    // Core pages — sorted by nav_core_order if set
    $coreMap = [
        'dashboard'  => ['key' => 'dashboard',  'label' => 'Dashboard',        'href' => 'index.php'],
        'roster'         => ['key' => 'roster',         'label' => 'Agent Roster',     'href' => 'roster.php'],
        'network'    => ['key' => 'network',    'label' => 'My Network',       'href' => 'network.php'],
        'calendar'          => ['key' => 'calendar',          'label' => 'Company Calendar', 'href' => 'calendar.php'],
        'industry_events'   => ['key' => 'industry_events',   'label' => 'Industry Events',   'href' => 'industry_events.php'],
        'profile'    => ['key' => 'profile',    'label' => 'My Profile',       'href' => 'profile.php'],
        'hud_submit' => ['key' => 'hud_submit', 'label' => 'Submit HUD & Check', 'href' => 'hud_submit.php'],
        'university' => ['key' => 'university', 'label' => 'INNOVATE University',  'href' => 'university.php'],
        'tickets'        => ['key' => 'tickets',        'label' => 'My Tickets',       'href' => 'tickets.php'],
        'listing_intel'  => ['key' => 'listing_intel',  'label' => 'Listing Intel',    'href' => 'listing_intel.php'],
        'marketing'      => ['key' => 'marketing',      'label' => 'Marketing Studio', 'href' => 'sso_marketing.php', 'external' => true],
    ];
    try {
        $orderedKeys = local_db()->query("SELECT key FROM nav_core_order ORDER BY sort_ord")->fetchAll(PDO::FETCH_COLUMN);
        $core = [];
        foreach ($orderedKeys as $k) { if (isset($coreMap[$k])) $core[] = $coreMap[$k]; }
        foreach ($coreMap as $k => $item) { if (!in_array($k, $orderedKeys)) $core[] = $item; }
    } catch (\Exception $e) {
        $core = array_values($coreMap);
    }

    return array_merge($core, $ext, [
        ['key' => 'crm', 'label' => 'INNOVATE Advantage', 'href' => 'https://advantage.innovateonline.com', 'external' => true, 'adminOnly' => true],
    ]);
}

// Items that live under the Back Office collapsible (admin only).
// Each item carries a 'dept' key so render_sidebar() can group them.
function backoffice_nav_items(bool $superAdmin): array {
    $items = [
        // ── Operations ──────────────────────────────────────────────────────────
        ['key'=>'vault',                     'label'=>'The Vault',           'href'=>'vault.php',                     'standalone'=>true],
        ['key'=>'backoffice_agents',         'label'=>'Agent Profiles',      'href'=>'backoffice_agents.php',         'dept'=>'Operations'],
        ['key'=>'onboarding',                'label'=>'Onboarding Queue',    'href'=>'onboarding.php',                'dept'=>'Operations'],
        ['key'=>'intake',                    'label'=>'Intake Form',         'href'=>'intake.php',                    'dept'=>'Operations'],
        ['key'=>'backoffice_roster',         'label'=>'Agent Roster',        'href'=>'backoffice_roster.php',         'dept'=>'Operations'],
        ['key'=>'backoffice_state_rosters',  'label'=>'State Rosters',       'href'=>'backoffice_state_rosters.php',  'dept'=>'Operations'],
        ['key'=>'backoffice_roster_changes', 'label'=>'Roster Changes',      'href'=>'backoffice_roster_changes.php', 'dept'=>'Operations'],
        ['key'=>'admin_import',              'label'=>'Import Agents',       'href'=>'admin_import.php',              'dept'=>'Operations'],
        // ── Broker Files ────────────────────────────────────────────────────────
        ['key'=>'bo_docs',                   'label'=>'Documents',           'href'=>'backoffice_docs.php',           'dept'=>'Broker Files'],
        ['key'=>'bo_mls',                    'label'=>'MLS Integrations',    'href'=>'backoffice_mls.php',            'dept'=>'Broker Files'],
        ['key'=>'admin_vault_depts',         'label'=>'Vault Departments',   'href'=>'admin_vault_depts.php',         'dept'=>'Broker Files', 'superOnly'=>true],
        // ── Events ──────────────────────────────────────────────────────────────
        ['key'=>'bo_announcements',          'label'=>'Announcements',       'href'=>'backoffice_announcements.php',  'dept'=>'Events'],
        ['key'=>'bo_industry_events',        'label'=>'Industry Events',     'href'=>'backoffice_industry_events.php','dept'=>'Events'],
        ['key'=>'press_release',             'label'=>'Press Release',       'href'=>'press_release.php',             'dept'=>'Events'],
        // ── Agent Development ───────────────────────────────────────────────────
        ['key'=>'admin_university',          'label'=>'University',          'href'=>'admin_university.php',          'dept'=>'Agent Development'],
        ['key'=>'bo_workflows',              'label'=>'Workflows',           'href'=>'backoffice_workflows.php',      'dept'=>'Agent Development'],
        // ── Finance ─────────────────────────────────────────────────────────────
        ['key'=>'finance_budget',            'label'=>'Department Budget',   'href'=>'finance_budget.php',            'dept'=>'Finance'],
        ['key'=>'finance_statements',        'label'=>'Statement Scanner',   'href'=>'finance_statements.php',        'dept'=>'Finance'],
        // ── Technology ──────────────────────────────────────────────────────────
        ['key'=>'bo_tickets',                'label'=>'Tickets',             'href'=>'backoffice_tickets.php',        'dept'=>'Technology'],
        ['key'=>'admin_roles',               'label'=>'Role Assignments',    'href'=>'admin_roles.php',               'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'admin_links',               'label'=>'Link Settings',       'href'=>'admin_links.php',               'dept'=>'Technology', 'superOnly'=>true],
        ['key'=>'admin_backoffice',          'label'=>'Menu Builder',        'href'=>'admin_backoffice.php',          'dept'=>'Technology', 'superOnly'=>true],
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

    echo '<aside class="sidebar"><div class="sb-brand"><span class="brand">INNOVATE</span> <span class="brand-edge">AgentEdge</span></div><nav class="sb-nav">';
    $superAdmin   = !empty($perms['isSuperAdmin']);
    $currentGroup = '';
    foreach (nav_items() as $it) {
        if (!empty($it['adminOnly']) && !$admin) continue;
        if (!empty($it['superOnly']) && !$superAdmin) continue;
        $gl = $it['group_label'] ?? '';
        if ($gl !== $currentGroup) {
            if ($currentGroup !== '') echo '</div>';
            $currentGroup = $gl;
            if ($gl !== '') {
                $sg = htmlspecialchars($gl);
                echo '<button class="sb-links-toggle" data-group="' . $sg . '" onclick="toggleSbLinks(this)" aria-expanded="true">'
                   . $sg . ' <span class="sb-links-arrow">&#9660;</span></button>';
                echo '<div class="sb-links-sub">';
            }
        }
        $active = $it['key'] === $current ? ' sb-active' : '';
        $ext    = !empty($it['external']) ? ' target="_blank" rel="noopener"' : '';
        $arrow  = !empty($it['external']) ? ' <span class="sb-ext">↗</span>' : '';
        $badge  = !empty($it['adminOnly']) ? ' <span class="sb-admin">Admin</span>' : '';
        echo '<a class="sb-item' . $active . '" href="' . htmlspecialchars($it['href']) . '"' . $ext . '>' . htmlspecialchars($it['label']) . $arrow . $badge . '</a>';
    }
    if ($currentGroup !== '') echo '</div>';

    // Back Office section — admin+ only. Collapsible with department sub-groups.
    if ($admin) {
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
        $deptOrder  = ['Operations','Finance','Broker Files','Events','Agent Development','Technology','Human Resources'];
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
        echo '<button class="sb-links-toggle" data-group="Back Office" onclick="toggleSbLinks(this)" aria-expanded="true">'
           . 'Back Office <span class="sb-links-arrow">&#9660;</span></button>';
        echo '<div class="sb-links-sub">';
        foreach ($standalone as $it) {
            if (!empty($it['superOnly']) && !$superAdmin) continue;
            $act = $it['key'] === $current ? ' sb-active' : '';
            $xt  = !empty($it['external']) ? ' target="_blank" rel="noopener"' : '';
            $arr = !empty($it['external']) ? ' <span class="sb-ext">↗</span>' : '';
            echo '<a class="sb-item sb-depth-2' . $act . '" href="' . htmlspecialchars($it['href']) . '"' . $xt . '>'
               . htmlspecialchars($it['label']) . $arr . '</a>';
        }
        foreach ($deptOrder as $deptName) {
            $dItems  = $byDept[$deptName] ?? [];
            $visible = array_values(array_filter($dItems, fn($it) => empty($it['superOnly']) || $superAdmin));
            echo '<button class="sb-dept-toggle" onclick="toggleSbLinks(this)" aria-expanded="true">'
               . htmlspecialchars($deptName) . ' <span class="sb-links-arrow">&#9660;</span></button>';
            echo '<div class="sb-links-sub">';
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
