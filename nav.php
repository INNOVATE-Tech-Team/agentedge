<?php
// The agent menu, in one place. Edit this list to change the sidebar
// everywhere. `external => true` marks an SSO link out to another system.
// `adminOnly => true` hides the item from non-admins (super/retention admin).
require_once __DIR__ . '/roles.php';

function nav_items(): array {
    return [
        // ── AgentEdge pages ───────────────────────────────────────────────────
        ['key' => 'dashboard',    'label' => 'Dashboard',          'href' => 'index.php'],
        ['key' => 'roster',       'label' => 'Agent Roster',       'href' => 'roster.php'],
        ['key' => 'onboarding',   'label' => 'Onboarding',         'href' => 'onboarding.php', 'adminOnly' => true],
        ['key' => 'calendar',     'label' => 'Company Calendar',   'href' => 'calendar.php'],
        ['key' => 'profile',      'label' => 'My Profile',         'href' => 'profile.php'],
        // ── External tools (fill in real URLs in href) ────────────────────────
        // MC-specific links (MLS, state resources, etc.) are injected by
        // mc-links.js → edit mc_links.php to configure per market center.
        ['key' => 'transactions', 'label' => 'Transactions',       'href' => '#',                          'external' => true],
        ['key' => 'maxa',         'label' => 'MAXA Marketing',     'href' => 'https://app.maxa.io',        'external' => true],
        ['key' => 'swag',         'label' => 'Swag Shop',          'href' => '#',                          'external' => true],
        ['key' => 'openhouse',    'label' => 'Open House Pool',    'href' => '#',                          'external' => true],
        ['key' => 'support',      'label' => 'Agent Support',      'href' => '#',                          'external' => true],
        ['key' => 'kb',           'label' => 'Knowledge Base',     'href' => '#',                          'external' => true],
    ];
}

function render_sidebar(string $current, array $agent): void {
    $perms = current_perms();
    $admin = !empty($perms['isAdmin']);
    $demo  = !empty(cfg()['demo']);
    echo '<aside class="sidebar"><div class="sb-brand"><span class="brand">INNOVATE</span> <span class="brand-edge">AgentEdge</span></div><nav class="sb-nav">';
    foreach (nav_items() as $it) {
        if (!empty($it['adminOnly']) && !$admin) continue;
        $active = $it['key'] === $current ? ' sb-active' : '';
        $ext = !empty($it['external']) ? ' target="_blank" rel="noopener"' : '';
        $arrow = !empty($it['external']) ? ' <span class="sb-ext">↗</span>' : '';
        $badge = !empty($it['adminOnly']) ? ' <span class="sb-admin">Admin</span>' : '';
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
