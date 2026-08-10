<?php
// Shared onboarding-queue write path, used by the admin "Add to Queue" UI
// (api/onboard_action.php) and the token-gated external intake
// (api/onboard_push.php) so both sources of a new agent go through the same
// insert + step-seeding + notification logic.

require_once __DIR__ . '/../onboard_tools.php';
require_once __DIR__ . '/roster.php';

const ONBOARD_VALID_STATES = ['FL','GA','SC','NC','TN','VA','MD','DE','NJ','PA','OH','MA','RI','NH'];

// Additively records each (market_center, state_code) pair for a queue entry
// in onboard_queue_mcs — INSERT OR IGNORE so calling this again (e.g. a CRM
// re-push) never drops a Market Center added since. $normalized entries must
// already be normalize_market_center()-resolved (non-blank).
function onboard_queue_add_mcs(PDO $pdo, int $queueId, array $normalized): void {
    if (!$normalized) return;
    $countSt = $pdo->prepare("SELECT COUNT(*) FROM onboard_queue_mcs WHERE queue_id=?");
    $countSt->execute([$queueId]);
    $hadAny = (int)$countSt->fetchColumn() > 0;

    $ins = $pdo->prepare(
        "INSERT OR IGNORE INTO onboard_queue_mcs (queue_id, market_center, state_code, is_primary) VALUES (?,?,?,?)"
    );
    foreach ($normalized as $i => $mc) {
        $ins->execute([$queueId, $mc['market_center'], $mc['state_code'], (!$hadAny && $i === 0) ? 1 : 0]);
    }
}

// Queue a new agent for onboarding, or update an already-queued active entry
// for the same email instead of creating a duplicate (an agent can be
// touched more than once before onboarding completes — e.g. a Market Center
// reassignment in the CRM re-sends the same push).
//
// $marketCenters is a list of ['market_center' => string, 'state_code' => string]
// pairs — an agent can be queued into more than one Market Center at once
// (e.g. licensed/working in bordering states). Each pair is normalized
// against the canonical market_centers list and added to onboard_queue_mcs
// (additively — INSERT OR IGNORE — so a re-push never drops a Market Center
// staff already added by hand via the queue card UI). onboard_queue's own
// market_center/state_code columns are kept as a "primary" mirror, always
// set from $marketCenters[0] of THIS call, matching the old single-value
// overwrite behavior exactly for any reader that only knows about one MC.
//
// Returns ['id' => int, 'wasNew' => bool].
function queue_onboarding_agent(
    PDO $pdo,
    string $email,
    string $name,
    array $marketCenters,
    ?string $canonicalAgentId,
    string $addedBy,
    string $startDate = '',
    string $sponsor = '',
    string $role = 'agent',
    string $notes = '',
    string $addedByName = '',
    string $phone = ''
): array {
    $email = trim($email);
    $name  = trim($name);
    $phone = trim($phone);

    // Normalize every pair; an unrecognized Market Center (typo, stale office
    // name) lands as blank and gets dropped, same "blank until a human fixes
    // it" policy the old single-value field always used.
    $normalized = [];
    foreach ($marketCenters as $entry) {
        $mc = normalize_market_center($pdo, (string)($entry['market_center'] ?? ''));
        if ($mc === '') continue;
        $normalized[] = ['market_center' => $mc, 'state_code' => strtoupper(trim((string)($entry['state_code'] ?? '')))];
    }
    $primary      = $normalized[0] ?? ['market_center' => '', 'state_code' => ''];
    $marketCenter = $primary['market_center'];
    $stateCode    = $primary['state_code'];

    $existing = $pdo->prepare(
        "SELECT id FROM onboard_queue WHERE agent_email = ? AND status = 'active' LIMIT 1"
    );
    $existing->execute([$email]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $queueId = (int)$row['id'];
        $pdo->prepare(
            "UPDATE onboard_queue
                SET agent_name = ?, market_center = ?, state_code = ?, canonical_agent_id = ?,
                    agent_phone = CASE WHEN ? != '' THEN ? ELSE agent_phone END
              WHERE id = ?"
        )->execute([$name, $marketCenter, $stateCode ?: null, $canonicalAgentId, $phone, $phone, $queueId]);
        onboard_queue_add_mcs($pdo, $queueId, $normalized);
        return ['id' => $queueId, 'wasNew' => false];
    }

    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare(
        "INSERT INTO onboard_queue
            (agent_email, agent_name, market_center, start_date, sponsor, role, added_by, added_at, notes, state_code, canonical_agent_id, agent_phone)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $ins->execute([
        $email, $name, $marketCenter, trim($startDate), trim($sponsor),
        trim($role) ?: 'agent', $addedBy, $now, trim($notes),
        $stateCode ?: null, $canonicalAgentId, $phone,
    ]);
    $queueId = (int)$pdo->lastInsertId();
    onboard_queue_add_mcs($pdo, $queueId, $normalized);

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
        // $addedBy is usually the acting admin's own email, but the external
        // intake webhook (api/onboard_push.php) can pass a non-email label —
        // only use it as a From address when it's actually a real address.
        $fromEmail = filter_var($addedBy, FILTER_VALIDATE_EMAIL) ? $addedBy : '';
        notify_onboard_added($name, $email, trim($marketCenter), trim($startDate), trim($sponsor), trim($role) ?: 'agent', $addedBy, $addedByName);
        $stepList = array_filter(onboard_tools(), fn($t) => $t['key'] !== 'agentedge');
        notify_step_assignees_on_create('onboard', $name, $email, $stepList, $fromEmail, $addedByName);
        maybe_notify_next_actionable_step($pdo, 'onboard', $queueId, $fromEmail, $addedByName);

        // Skip when this agent already has a submitted intake on file — covers
        // the public-intake-form path (api/intake_public.php), which calls
        // this function AFTER the agent has already filled it out, so
        // requesting it again would be pointless noise.
        $intakeCheck = $pdo->prepare("SELECT submitted FROM agent_intake WHERE email = ?");
        $intakeCheck->execute([$email]);
        if (!$intakeCheck->fetchColumn()) {
            notify_intake_request($name, $email, $queueId);
            $pdo->prepare("UPDATE onboard_queue SET intake_sent_at = ? WHERE id = ?")->execute([$now, $queueId]);
        }
    } catch (\Throwable $e) {}

    return ['id' => $queueId, 'wasNew' => true];
}
