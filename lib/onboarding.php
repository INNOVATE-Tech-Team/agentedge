<?php
// Shared onboarding-queue write path, used by the admin "Add to Queue" UI
// (api/onboard_action.php) and the token-gated external intake
// (api/onboard_push.php) so both sources of a new agent go through the same
// insert + step-seeding + notification logic.

require_once __DIR__ . '/../onboard_tools.php';

const ONBOARD_VALID_STATES = ['FL','GA','SC','NC','TN','VA','MD','DE','NJ','PA','OH','MA','RI','NH'];

// Queue a new agent for onboarding, or update an already-queued active entry
// for the same email instead of creating a duplicate (an agent can be
// touched more than once before onboarding completes — e.g. a Market Center
// reassignment in the CRM re-sends the same push).
//
// Returns ['id' => int, 'wasNew' => bool].
function queue_onboarding_agent(
    PDO $pdo,
    string $email,
    string $name,
    string $marketCenter,
    string $stateCode,
    ?string $canonicalAgentId,
    string $addedBy,
    string $startDate = '',
    string $sponsor = '',
    string $role = 'agent',
    string $notes = ''
): array {
    $email = trim($email);
    $name  = trim($name);

    $existing = $pdo->prepare(
        "SELECT id FROM onboard_queue WHERE agent_email = ? AND status = 'active' LIMIT 1"
    );
    $existing->execute([$email]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $queueId = (int)$row['id'];
        $pdo->prepare(
            "UPDATE onboard_queue
                SET agent_name = ?, market_center = ?, state_code = ?, canonical_agent_id = ?
              WHERE id = ?"
        )->execute([$name, trim($marketCenter), trim($stateCode) ?: null, $canonicalAgentId, $queueId]);
        return ['id' => $queueId, 'wasNew' => false];
    }

    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare(
        "INSERT INTO onboard_queue
            (agent_email, agent_name, market_center, start_date, sponsor, role, added_by, added_at, notes, state_code, canonical_agent_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );
    $ins->execute([
        $email, $name, trim($marketCenter), trim($startDate), trim($sponsor),
        trim($role) ?: 'agent', $addedBy, $now, trim($notes),
        trim($stateCode) ?: null, $canonicalAgentId,
    ]);
    $queueId = (int)$pdo->lastInsertId();

    $stepIns = $pdo->prepare(
        "INSERT OR IGNORE INTO onboard_steps
            (queue_id, tool_key, tool_label, is_auto, status, done_by, done_at)
         VALUES (?,?,?,?,?,?,?)"
    );
    foreach (onboard_tools() as $t) {
        $isDone = $t['key'] === 'agentedge';
        $stepIns->execute([
            $queueId, $t['key'], $t['label'], $t['is_auto'] ? 1 : 0,
            $isDone ? 'done' : 'pending',
            $isDone ? $addedBy : null,
            $isDone ? $now : null,
        ]);
    }

    try {
        require_once __DIR__ . '/notifications.php';
        notify_onboard_added($name, $email, trim($marketCenter), trim($startDate), trim($sponsor), trim($role) ?: 'agent', $addedBy);
    } catch (\Throwable $e) {}

    return ['id' => $queueId, 'wasNew' => true];
}
