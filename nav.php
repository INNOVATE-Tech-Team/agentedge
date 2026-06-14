<?php
// The agent menu, in one place. Edit this list to change the sidebar
// everywhere. `external => true` marks an SSO link out to another system.
// `adminOnly => true` hides the item from non-admins (super/retention admin).
require_once __DIR__ . '/roles.php';

function nav_items(): array {
    return [
        ['key' => 'dashboard',    'label' => 'Dashboard',          'href' => 'index.php'],
        ['key' => 'roster',       'label' => 'Agent Roster',       'href' => 'roster.php'],
        ['key' => 'onboarding',   'label' => 'Onboarding',         'href' => 'onboarding.php', 'adminOnly' => true],
        ['key' => 'transactions', 'label' => 'My Transactions',    'href' => '#'],
        ['key' => 'commissions',  'label' => 'Commissions & Cap',  'href' => '#'],
        ['key' => 'network',      'label' => 'My Network',         'href' => '#'],
        ['key' => 'training',     'label' => 'Training',           'href' => '#'],
        ['key' => 'marketing',    'label' => 'Marketing & Social', 'href' => '#'],
        ['key' => 'openhouse',    'label' => 'Open House Pool',    'href' => '#'],
        ['key' => 'kb',           'label' => 'Knowledge Base',     'href' => '#'],
        ['key' => 'support',      'label' => 'Support',            'href' => '#'],
        ['key' => 'profile',      'label' => 'My Profile',         'href' => 'profile.php'],
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
}
