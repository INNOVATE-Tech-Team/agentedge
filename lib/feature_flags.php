<?php
// Staff Feature Access — one generic per-staff feature-flag table
// (staff_feature_flags, see local_db.php) backing every internal feature
// flag the app needs, present and future. Adding a new gated feature is a
// new feature_key value read through feature_enabled_for_current_user()
// below, never a new column or table.
if (defined('AGENTEDGE_FEATURE_FLAGS_LOADED')) return;
define('AGENTEDGE_FEATURE_FLAGS_LOADED', true);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

// Feature keys this mechanism is allowed to gate/toggle. Checked here (read
// path) and in api/staff_feature_action.php (write path) so an unlisted key
// can never be queried or set through either path.
const STAFF_FEATURE_KEYS = ['admin_work_os'];

// Role eligibility (is_admin(): staff or super_admin) is checked before any
// flag lookup — no role is ever eligible without it, and no role bypasses
// the lookup once eligible. Both staff and super_admin look up the flag row
// for the current session's email; missing row or enabled=0 -> false,
// enabled=1 -> true. Every other role (plain agent, recruiter, bic,
// mc_leader, etc.) -> always false, regardless of any flag row that may
// exist for their email.
function feature_enabled_for_current_user(string $featureKey): bool {
    if (!is_admin()) return false;
    if (!in_array($featureKey, STAFF_FEATURE_KEYS, true)) return false;

    $agent = current_agent();
    $email = strtolower(trim($agent['email'] ?? ''));
    if ($email === '') return false;

    try {
        $stmt = local_db()->prepare(
            "SELECT enabled FROM staff_feature_flags WHERE user_email = ? AND feature_key = ?"
        );
        $stmt->execute([$email, $featureKey]);
        $val = $stmt->fetchColumn();
        return $val !== false && (int)$val === 1;
    } catch (\Throwable $e) {
        return false;
    }
}
