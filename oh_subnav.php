<?php
function render_oh_subnav(string $current, bool $isAdmin): void {
    $items = [
        ['key'=>'pool',     'label'=>'Available Pool',  'href'=>'openhouse.php'],
        ['key'=>'mine',     'label'=>'My Listings',     'href'=>'openhouse_mine.php'],
        ['key'=>'requests', 'label'=>'My Requests',     'href'=>'openhouse_requests.php'],
        ['key'=>'calendar', 'label'=>'Calendar',        'href'=>'openhouse_calendar.php'],
    ];
    if ($isAdmin) $items[] = ['key'=>'prefs','label'=>'Preferences','href'=>'openhouse_prefs.php'];
    echo '<nav class="oh-subnav">';
    foreach ($items as $it) {
        $cls = $it['key']===$current ? ' oh-sub-active' : '';
        echo '<a class="oh-sub-item'.$cls.'" href="'.htmlspecialchars($it['href']).'">'.htmlspecialchars($it['label']).'</a>';
    }
    echo '</nav>';
}
