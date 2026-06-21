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
        'roster'     => ['key' => 'roster',     'label' => 'Agent Roster',     'href' => 'roster.php'],
        'network'    => ['key' => 'network',    'label' => 'My Network',       'href' => 'network.php'],
        'onboarding' => ['key' => 'onboarding', 'label' => 'Onboarding',       'href' => 'onboarding.php', 'adminOnly' => true],
        'calendar'   => ['key' => 'calendar',   'label' => 'Company Calendar', 'href' => 'calendar.php'],
        'profile'    => ['key' => 'profile',    'label' => 'My Profile',       'href' => 'profile.php'],
        'hud_submit' => ['key' => 'hud_submit', 'label' => 'Submit HUD & Check', 'href' => 'hud_submit.php'],
        'docs'       => ['key' => 'docs',       'label' => 'Resources',             'href' => 'docs.php'],
        'university' => ['key' => 'university', 'label' => 'INNOVATE University',  'href' => 'university.php'],
        'tickets'    => ['key' => 'tickets',    'label' => 'My Tickets',           'href' => 'tickets.php'],
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
        // ── Super admin only ──────────────────────────────────────────────────
        ['key' => 'admin_roles',  'label' => 'Role Assignments', 'href' => 'admin_roles.php',  'superOnly' => true],
        ['key' => 'admin_import','label' => 'Import Agents',   'href' => 'admin_import.php', 'adminOnly' => true],
        ['key' => 'admin_links', 'label' => 'Link Settings',   'href' => 'admin_links.php',  'superOnly' => true],
    ]);
}

// Items that live under the Back Office collapsible (admin only).
function backoffice_nav_items(bool $superAdmin): array {
    $items = [
        ['key' => 'bo_announcements', 'label' => 'Announcements',   'href' => 'backoffice_announcements.php'],
        ['key' => 'bo_tickets',       'label' => 'Tickets',          'href' => 'backoffice_tickets.php'],
        ['key' => 'bo_docs',          'label' => 'Documents',        'href' => 'backoffice_docs.php'],
        ['key' => 'bo_workflows',     'label' => 'Workflows',        'href' => 'backoffice_workflows.php'],
        ['key' => 'admin_university', 'label' => 'University',       'href' => 'admin_university.php'],
        ['key' => 'backoffice_state_rosters',  'label' => 'State Rosters',   'href' => 'backoffice_state_rosters.php'],
        ['key' => 'backoffice_roster',         'label' => 'Agent Roster',   'href' => 'backoffice_roster.php'],
        ['key' => 'backoffice_roster_changes', 'label' => 'Roster Changes', 'href' => 'backoffice_roster_changes.php'],
    ];
    foreach (backoffice_items_all() as $r) {
        $item = ['key' => 'bo_' . $r['id'], 'label' => $r['label'], 'href' => $r['url']];
        if ($r['is_ext']) $item['external'] = true;
        $items[] = $item;
    }
    $items[] = ['key' => 'admin_market_centers', 'label' => 'Market Centers', 'href' => 'admin_market_centers.php', 'superOnly' => true];
    $items[] = ['key' => 'admin_backoffice', 'label' => 'Menu Builder', 'href' => 'admin_backoffice.php', 'superOnly' => true];
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

    // Back Office section — admin+ only. Collapsible, same pattern as external link groups.
    if ($admin) {
        $boItems = backoffice_nav_items($superAdmin);
        echo '<button class="sb-links-toggle" data-group="Back Office" onclick="toggleSbLinks(this)" aria-expanded="true">'
           . 'Back Office <span class="sb-links-arrow">&#9660;</span></button>';
        echo '<div class="sb-links-sub">';
        foreach ($boItems as $it) {
            if (!empty($it['superOnly']) && !$superAdmin) continue;
            $act = $it['key'] === $current ? ' sb-active' : '';
            $xt  = !empty($it['external']) ? ' target="_blank" rel="noopener"' : '';
            $arr = !empty($it['external']) ? ' <span class="sb-ext">↗</span>' : '';
            echo '<a class="sb-item' . $act . '" href="' . htmlspecialchars($it['href']) . '"' . $xt . '>'
               . htmlspecialchars($it['label']) . $arr . '</a>';
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
