<?php
// The ordered list of deprovisioning steps every departing agent goes through.
// is_auto=true means AgentEdge can deprovision this automatically via API
// ('fub', 'constellation1', and 'agentedge' have real handlers, in api/offboard_action.php).
// Manual steps require an admin to check them off.
// Backed by step_defs (see local_db.php) — editable on admin_step_notify.php.
require_once __DIR__ . '/local_db.php';

function offboard_tools(): array {
    $rows = local_db()->query(
        "SELECT step_key AS `key`, label, is_auto, note
         FROM step_defs WHERE process='offboard' ORDER BY sort_ord, id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['is_auto'] = (bool)$r['is_auto']; }
    return $rows;
}
