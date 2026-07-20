<?php
// Shared innovate_roster write path, used by the Backoffice Roster "Add"
// action (api/roster_agent.php) and by onboarding completion
// (api/onboard_action.php's complete_onboarding) so both routes into the
// roster share the same insert/reactivate + audit-log logic.

const ROSTER_VALID_STATES = ['FL','GA','SC','NC','TN','VA','MD','DE','NJ','PA','OH','MA','RI','NH'];

// Normalizes free-text Market Center input against the canonical
// market_centers list (case-insensitive, trimmed) — returns the canonical
// name if matched, or '' if not. Used everywhere a Market Center gets written
// (onboarding queue, direct roster writes, the set_market_center action) so
// an unrecognized value (typo, stale/renamed office, blank) can never
// silently create a mismatched/duplicate innovate_roster row — it just stays
// blank until a human picks a real one. Added 2026-07-19 after a real
// incident: onboarding's free-text Market Center field let bad data ride
// straight through to innovate_roster with zero validation.
function normalize_market_center(PDO $pdo, string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $stmt = $pdo->prepare("SELECT name FROM market_centers WHERE enabled=1 AND LOWER(name) = LOWER(?) LIMIT 1");
    $stmt->execute([$raw]);
    return (string)($stmt->fetchColumn() ?: '');
}

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

// Soft-remove a roster row on offboarding — the reverse of
// add_or_reactivate_roster_agent(). Matches the same way: canonical_agent_id
// first, falling back to name + market_center for legacy rows.
// Returns ['id' => int, 'found' => bool].
function remove_roster_agent(
    PDO $pdo,
    string $name,
    string $marketCenter,
    ?string $canonicalAgentId,
    string $removedBy
): array {
    $name = trim($name);
    $mc   = trim($marketCenter);

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
    if (!$existing) return ['id' => 0, 'found' => false];

    $id = (int)$existing['id'];
    $pdo->prepare(
        "UPDATE innovate_roster SET active = 0, removed_at = datetime('now'), removed_by = ? WHERE id = ?"
    )->execute([$removedBy, $id]);

    $pdo->prepare("INSERT INTO roster_changes (agent_name,state_code,market_center,license_exp,action,changed_by) VALUES (?,?,?,?,?,?)")
        ->execute([$existing['agent_name'], $existing['state_code'], $existing['market_center'], $existing['license_exp'], 'removed', $removedBy]);

    return ['id' => $id, 'found' => true];
}

// Propagate identity fields (name/email/phone) from one edited innovate_roster row
// to every other active row for the same agent in other states. state_code,
// market_center, and license_exp are intentionally left alone — they're
// legitimately different per state (e.g. a license is issued per state).
//
// Matches siblings the same way add_or_reactivate_roster_agent() does: prefer an
// exact canonical_agent_id match (agents onboarded through Add-to-Team carry this);
// fall back to a case-insensitive match on the row's name *before* this edit, scoped
// to other rows that also have no canonical_agent_id (legacy/manually-added agents).
function sync_roster_identity(
    PDO $pdo,
    int $editedId,
    string $oldName,
    ?string $canonicalAgentId,
    string $newName,
    string $email,
    string $phone,
    string $changedBy
): void {
    if ($canonicalAgentId) {
        $siblings = $pdo->prepare(
            "SELECT id, state_code FROM innovate_roster
              WHERE id != ? AND active = 1 AND canonical_agent_id = ?"
        );
        $siblings->execute([$editedId, $canonicalAgentId]);
    } else {
        $oldName = trim($oldName);
        if ($oldName === '') return;
        $siblings = $pdo->prepare(
            "SELECT id, state_code FROM innovate_roster
              WHERE id != ? AND active = 1 AND canonical_agent_id IS NULL
                AND LOWER(agent_name) = LOWER(?)"
        );
        $siblings->execute([$editedId, $oldName]);
    }

    $rows = $siblings->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return;

    $update = $pdo->prepare("UPDATE innovate_roster SET agent_name = ?, email = ?, phone = ? WHERE id = ?");
    $log    = $pdo->prepare(
        "INSERT INTO roster_changes (agent_name,state_code,market_center,license_exp,action,changed_by)
         SELECT agent_name, state_code, market_center, license_exp, 'synced', ? FROM innovate_roster WHERE id = ?"
    );
    foreach ($rows as $row) {
        $update->execute([$newName, $email, $phone, $row['id']]);
        $log->execute([$changedBy, $row['id']]);
    }
}
