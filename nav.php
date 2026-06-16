<?php
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
            'key'   => $r['key'],
            'label' => $r['label'],
            'href'  => $r['url'],
        ];
        // Open House Pool is an internal page, not external SSO
        if ($r['key'] === 'openhouse') {
            $item['href'] = 'openhouse.php';
        } else {
            $item['external'] = true;
        }
        return $item;
    }, $extLinks);

    return array_merge([
        // ── AgentEdge pages ───────────────────────────────────────────────────
        ['key' => 'dashboard',   'label' => 'Dashboard',        'href' => 'index.php'],
        ['key' => 'roster',      'label' => 'Agent Roster',     'href' => 'roster.php'],
        ['key' => 'onboarding',  'label' => 'Onboarding',       'href' => 'onboarding.php', 'adminOnly' => true],
        ['key' => 'calendar',    'label' => 'Company Calendar', 'href' => 'calendar.php'],
        ['key' => 'profile',     'label' => 'My Profile',       'href' => 'profile.php'],
    ], $ext, [
        // ── Super admin only ──────────────────────────────────────────────────
        ['key' => 'admin_links', 'label' => 'Link Settings',    'href' => 'admin_links.php', 'superOnly' => true],
    ]);
}

function render_sidebar(string $current, array $agent): void {
    $perms = current_perms();
    $admin = !empty($perms['isAdmin']);
    $demo  = !empty(cfg()['demo']);
    echo '<aside class="sidebar"><div class="sb-brand"><span class="brand">INNOVATE</span> <span class="brand-edge">AgentEdge</span></div><nav class="sb-nav">';
    $superAdmin = !empty($perms['isSuperAdmin']);
    foreach (nav_items() as $it) {
        if (!empty($it['adminOnly']) && !$admin) continue;
        if (!empty($it['superOnly']) && !$superAdmin) continue;
        $active = $it['key'] === $current ? ' sb-active' : '';
        $ext    = !empty($it['external']) ? ' target="_blank" rel="noopener"' : '';
        $arrow  = !empty($it['external']) ? ' <span class="sb-ext">↗</span>' : '';
        $badge  = !empty($it['adminOnly']) ? ' <span class="sb-admin">Admin</span>' : '';
        echo '<a class="sb-item' . $active . '" href="' . htmlspecialchars($it['href']) . '"' . $ext . '>' . htmlspecialchars($it['label']) . $arrow . $badge . '</a>';
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
    echo '<a class="sb-signout" href="logout.php">Sign out</a></div></aside>';
    echo '<script src="assets/mc-links.js"></script>';
}
