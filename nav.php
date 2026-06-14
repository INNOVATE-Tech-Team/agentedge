<?php
// The agent menu, in one place. Edit this list to change the sidebar
// everywhere. `external => true` marks an SSO link out to another system.
function nav_items(): array {
    return [
        ['key' => 'dashboard',    'label' => 'Dashboard',          'href' => 'index.php'],
        ['key' => 'roster',       'label' => 'Agent Roster',       'href' => 'roster.php'],
        ['key' => 'transactions', 'label' => 'My Transactions',    'href' => '#'],
        ['key' => 'commissions',  'label' => 'Commissions & Cap',  'href' => '#'],
        ['key' => 'network',      'label' => 'My Network',         'href' => '#'],
        ['key' => 'training',     'label' => 'Training',           'href' => '#'],
        ['key' => 'marketing',    'label' => 'Marketing & Social', 'href' => '#'],
        ['key' => 'openhouse',    'label' => 'Open House Pool',    'href' => '#'],
        ['key' => 'kb',           'label' => 'Knowledge Base',     'href' => '#'],
        ['key' => 'support',      'label' => 'Support',            'href' => '#'],
        ['key' => 'profile',      'label' => 'My Profile',         'href' => '#'],
    ];
}

function render_sidebar(string $current, array $agent): void {
    echo '<aside class="sidebar"><div class="sb-brand"><span class="brand">INNOVATE</span> <span class="brand-edge">AgentEdge</span></div><nav class="sb-nav">';
    foreach (nav_items() as $it) {
        $active = $it['key'] === $current ? ' sb-active' : '';
        $ext = !empty($it['external']) ? ' target="_blank" rel="noopener"' : '';
        $arrow = !empty($it['external']) ? ' <span class="sb-ext">↗</span>' : '';
        echo '<a class="sb-item' . $active . '" href="' . htmlspecialchars($it['href']) . '"' . $ext . '>' . htmlspecialchars($it['label']) . $arrow . '</a>';
    }
    $who = htmlspecialchars($agent['name'] ?: $agent['email']);
    echo '</nav><div class="sb-foot"><div class="sb-who">' . $who . '</div><a class="sb-signout" href="logout.php">Sign out</a></div></aside>';
}
