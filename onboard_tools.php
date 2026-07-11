<?php
// The ordered list of provisioning steps every new agent goes through.
// is_auto=true means AgentEdge can provision this automatically via API
// (only 'fub' and 'constellation1' have real handlers, in api/onboard_action.php).
// Manual steps require an admin to check them off.
// Backed by step_defs (see local_db.php) — editable on admin_step_notify.php.
require_once __DIR__ . '/local_db.php';

function onboard_tools(): array {
    $rows = local_db()->query(
        "SELECT step_key AS `key`, label, is_auto, note
         FROM step_defs WHERE process='onboard' ORDER BY sort_ord, id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['is_auto'] = (bool)$r['is_auto']; }
    return $rows;
}
