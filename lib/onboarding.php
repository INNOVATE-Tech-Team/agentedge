<?php
// Shared onboarding-queue write path, used by the admin "Add to Queue" UI
// (api/onboard_action.php) and the token-gated external intake
// (api/onboard_push.php) so both sources of a new agent go through the same
// insert + step-seeding + notification logic.

require_once __DIR__ . '/../onboard_tools.php';
require_once __DIR__ . '/roster.php';

const ONBOARD_VALID_STATES = ['FL','GA','SC','NC','TN','VA','MD','DE','NJ','PA','OH','MA','RI','NH'];

// Derived onboarding state — the ONE place that maps onboard_queue.status +
// stage1_completed_at to a lifecycle state. Callers pass a full onboard_queue
// row (SELECT * / q.*, so stage1_completed_at is present) and must not
// re-derive this themselves. status='completed' always wins, so rows that
// finished before the Stage 1 columns existed (NULL stamp) are still
// "Onboarding Complete".
const ONBOARD_STATE_SETUP_PENDING  = 'initial_setup_pending';
const ONBOARD_STATE_IN_PROGRESS    = 'initial_setup_complete';
const ONBOARD_STATE_COMPLETE       = 'onboarding_complete';
const ONBOARD_STATE_CANCELLED      = 'cancelled';

function onboard_queue_state(array $queueRow): string {
    $status = (string)($queueRow['status'] ?? '');
    if ($status === 'completed') return ONBOARD_STATE_COMPLETE;
    if ($status === 'cancelled') return ONBOARD_STATE_CANCELLED;
    if ($status === 'active') {
        return trim((string)($queueRow['stage1_completed_at'] ?? '')) === ''
            ? ONBOARD_STATE_SETUP_PENDING
            : ONBOARD_STATE_IN_PROGRESS;
    }
    return 'unknown';
}

function onboard_queue_state_label(string $state): string {
    return [
        ONBOARD_STATE_SETUP_PENDING => 'Initial Setup Pending',
        ONBOARD_STATE_IN_PROGRESS   => 'Initial Setup Complete / Onboarding In Progress',
        ONBOARD_STATE_COMPLETE      => 'Onboarding Complete',
        ONBOARD_STATE_CANCELLED     => 'Cancelled',
    ][$state] ?? 'Unknown';
}

// Stage 1 (Initial Setup / Paperwork) readiness — the ONE definition of what
// "Complete Initial Setup" requires, shared by the list_queue UI payload and
// the complete_initial_setup action so they can't disagree.
//   Derived requirements (existing data, no checklist rows):
//     intake (agent_intake.submitted), license_state (valid state on the
//     queue entry), market_centers (>= 1 onboard_queue_mcs row).
//   Checklist steps that must be done or skipped: doc_signing, mls.
//     A queue entry with no row for one of them (older entries pre-date the
//     'mls' step) can't act on it, so it isn't counted as missing.
//   NOT required (yet): the 'agentedge' step — it's auto-marked done at queue
//     time and doesn't reflect real login activation.
function onboard_stage1_readiness(PDO $pdo, array $queueRow): array {
    $qid   = (int)($queueRow['id'] ?? 0);
    $items = [];

    $in = $pdo->prepare("SELECT submitted FROM agent_intake WHERE LOWER(email)=LOWER(?)");
    $in->execute([(string)($queueRow['agent_email'] ?? '')]);
    $items[] = ['key' => 'intake', 'label' => 'Contact / Intake', 'type' => 'requirement',
                'met' => (int)$in->fetchColumn() === 1];

    $state = strtoupper(trim((string)($queueRow['state_code'] ?? '')));
    $items[] = ['key' => 'license_state', 'label' => 'License State', 'type' => 'requirement',
                'met' => in_array($state, ROSTER_VALID_STATES, true)];

    $mc = $pdo->prepare("SELECT COUNT(*) FROM onboard_queue_mcs WHERE queue_id=?");
    $mc->execute([$qid]);
    $items[] = ['key' => 'market_centers', 'label' => 'Market Center(s)', 'type' => 'requirement',
                'met' => (int)$mc->fetchColumn() > 0];

    $stepSt = $pdo->prepare("SELECT status FROM onboard_steps WHERE queue_id=? AND tool_key=?");
    foreach (['doc_signing' => 'Document Signing', 'mls' => 'MLS Access'] as $key => $label) {
        $stepSt->execute([$qid, $key]);
        $status = $stepSt->fetchColumn();
        $items[] = ['key' => $key, 'label' => $label, 'type' => 'step',
                    'met' => $status === false || in_array($status, ['done', 'skipped'], true)];
    }

    $missing = [];
    foreach ($items as $it) { if (!$it['met']) $missing[] = $it['label']; }
    return ['ready' => !$missing, 'items' => $items, 'missing' => $missing];
}

// Legacy 'email_setup' and 'intranet' rows stay in the database for history
// but are hidden from the Stage 1/Stage 2 UI (list_queue omits them) and are
// not in the list below, so the Stage 2 gate ignores them.
// The canonical Stage 2 checklist, in display order. Complete Onboarding
// requires every one of these to be explicitly done or skipped.
const ONBOARD_STAGE2_REQUIRED = ['coach', 'launch', 'fub', 'realscout', 'constellation1', 'dotloop', 'listingstoleads', 'maxa', 'training'];

// Stage 2 readiness — the ONE definition of what "Complete Onboarding"
// requires beyond Stage 1, shared by the list_queue payload and the
// complete_onboarding action. Any canonical step that is pending, sent,
// failed or otherwise unresolved is outstanding. A canonical step with NO row
// on the entry is a data/configuration problem, not "complete": it is
// reported as outstanding ("step missing") so it can't be silently bypassed.
function onboard_stage2_readiness(PDO $pdo, array $queueRow): array {
    $qid = (int)($queueRow['id'] ?? 0);
    $defLabels = [];
    foreach (onboard_tools() as $t) { $defLabels[$t['key']] = $t['label']; }

    $st = $pdo->prepare("SELECT tool_key, tool_label, status FROM onboard_steps WHERE queue_id=?");
    $st->execute([$qid]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $rows[$r['tool_key']] = $r; }

    $items = []; $missing = [];
    foreach (ONBOARD_STAGE2_REQUIRED as $key) {
        $row    = $rows[$key] ?? null;
        $label  = $row['tool_label'] ?? ($defLabels[$key] ?? $key);
        $status = $row ? (string)$row['status'] : 'missing';
        $met    = $row !== null && in_array($status, ['done', 'skipped'], true);
        $items[] = ['key' => $key, 'label' => $label, 'status' => $status, 'met' => $met];
        if (!$met) $missing[] = $row ? "{$label} ({$status})" : "{$label} (step missing from this entry)";
    }
    return ['ready' => !$missing, 'items' => $items, 'missing' => $missing];
}

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
            (queue_id, tool_key, tool_label, is_auto, stage, status, done_by, done_at)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    foreach (onboard_tools() as $t) {
        $isDone = $t['key'] === 'agentedge';
        $stepIns->execute([
            $queueId, $t['key'], $t['label'], $t['is_auto'] ? 1 : 0, $t['stage'],
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
