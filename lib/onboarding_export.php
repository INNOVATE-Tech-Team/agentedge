<?php
// Each agent's onboarding state, for the exports coastline-server's nightly
// agent-site sync reads (api/agent_directory_export.php, api/roster_export.php;
// innovate-advantage-server #275, David, Nov 11, 2026): a new agent's own
// website goes live only once their AgentEdge onboarding is fully finished,
// Stage 2 included. That is onboard_queue.status = 'completed', which
// "Complete Onboarding" (api/onboard_action.php) sets only after Stage 1 and
// every Stage 2 step are Done or Skipped; onboard_queue_state() calls it
// 'onboarding_complete'.
//
// A file of its own, not an addition to lib/onboarding.php: the live box's
// copy of that file is newer than main's, so deploying this must not replace
// it. This only calls onboard_queue_state(), which both copies define the same
// way. Reads only.
require_once __DIR__ . '/onboarding.php';

// The address part of an email, lowercased ('' when there is none), so a
// display-name form ("Pat Agent <pat@example.com>") still matches.
function onboarding_export_email(?string $raw): string {
    return preg_match('/[^\s<>"]+@[^\s<>"]+/', (string)$raw, $m) ? strtolower($m[0]) : '';
}

// email => onboard_queue_state() of the agent's onboarding entry: the active
// entry if they have one, otherwise the most recent (the same pick as
// reopen_for_agent). Agents with no entry are absent.
function onboarding_states_by_email(PDO $pdo): array {
    $states = [];
    $rows = $pdo->query("SELECT * FROM onboard_queue ORDER BY (status = 'active'), id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $email = onboarding_export_email($row['agent_email'] ?? '');
        if ($email !== '') $states[$email] = onboard_queue_state($row);   // later rows win: active last, then newest
    }
    return $states;
}

// Adds `onboarding_state` to every export row (keyed by its `email`):
//   onboarding_complete      Stage 1 and Stage 2 finished (Complete Onboarding)
//   initial_setup_complete   Stage 1 done, Stage 2 not finished
//   initial_setup_pending    Stage 1 not finished
//   cancelled                onboarding cancelled
//   unknown                  an entry with any other status
//   none                     no onboarding entry for this email
// If the queue can't be read the rows go out without the key, which the
// website reads as "not finished yet" (no new page), never as finished.
function add_onboarding_states(PDO $pdo, array $rows): array {
    try {
        $states = onboarding_states_by_email($pdo);
    } catch (\Throwable $e) {
        error_log('onboarding_states_by_email failed: ' . $e->getMessage());
        return $rows;
    }
    foreach ($rows as &$r) {
        $r['onboarding_state'] = $states[onboarding_export_email($r['email'] ?? '')] ?? 'none';
    }
    unset($r);
    return $rows;
}
