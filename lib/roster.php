<?php
// Shared innovate_roster write path, used by the Backoffice Roster "Add"
// action (api/roster_agent.php) and by onboarding completion
// (api/onboard_action.php's complete_onboarding) so both routes into the
// roster share the same insert/reactivate + audit-log logic.

const ROSTER_VALID_STATES = ['FL','GA','SC','NC','TN','VA','MD','DE','NJ','PA','OH','MA','RI','NH'];

// Add a new agent to innovate_roster, or reactivate an existing (soft-removed)
// row instead of inserting a duplicate. Matches first by canonical_agent_id
// (exact — agents that came through onboarding carry this), falling back to
// name + market_center (legacy rows added manually with no canonical id).
//
// Returns ['id' => int, 'reactivated' => bool].
function add_or_reactivate_roster_agent(
    PDO $pdo,
    string $name,
    string $stateCode,
    string $marketCenter,
    string $licenseExp,
    ?string $canonicalAgentId,
    string $addedBy
): array {
    $name  = trim($name);
    $state = strtoupper(trim($stateCode));
    $mc    = trim($marketCenter);
    $exp   = trim($licenseExp);
    if ($exp && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) $exp = '';

    $existing = null;
    if ($canonicalAgentId) {
        $st = $pdo->prepare("SELECT * FROM innovate_roster WHERE canonical_agent_id = ? LIMIT 1");
        $st->execute([$canonicalAgentId]);
        $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$existing) {
        $st = $pdo->prepare(
            "SELECT * FROM innovate_roster WHERE LOWER(agent_name) = LOWER(?) AND LOWER(market_center) = LOWER(?) LIMIT 1"
        );
        $st->execute([$name, $mc]);
        $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($existing) {
        $id = (int)$existing['id'];
        $pdo->prepare(
            "UPDATE innovate_roster
                SET agent_name = ?, state_code = ?, market_center = ?, license_exp = ?,
                    canonical_agent_id = COALESCE(?, canonical_agent_id),
                    active = 1, removed_at = '', removed_by = ''
              WHERE id = ?"
        )->execute([$name, $state, $mc, $exp, $canonicalAgentId, $id]);

        $pdo->prepare("INSERT INTO roster_changes (agent_name,state_code,market_center,license_exp,action,changed_by) VALUES (?,?,?,?,?,?)")
            ->execute([$name, $state, $mc, $exp, $existing['active'] ? 'updated' : 'restored', $addedBy]);

        return ['id' => $id, 'reactivated' => !$existing['active']];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO innovate_roster (agent_name,state_code,market_center,license_exp,active,added_at,added_by,canonical_agent_id)
         VALUES (?,?,?,?,1,datetime('now'),?,?)"
    );
    $stmt->execute([$name, $state, $mc, $exp, $addedBy, $canonicalAgentId]);
    $id = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO roster_changes (agent_name,state_code,market_center,license_exp,action,changed_by) VALUES (?,?,?,?,?,?)")
        ->execute([$name, $state, $mc, $exp, 'added', $addedBy]);

    return ['id' => $id, 'reactivated' => false];
}
