<?php
// Fully deactivates an agent's AgentEdge access on offboarding.
// Deletes the local login credential — agent_passwords has no active flag,
// so a row existing there IS the account being active for anyone already
// migrated to local auth (the primary path for most agents today). Also
// flips innovate_roster.active=0, and best-effort deactivates the agent's
// Perfex tblstaff row via the bridge for anyone still on that fallback path.
require_once __DIR__ . '/roster.php';

function deactivate_agentedge_account(PDO $pdo, string $email, string $name, string $marketCenter, string $doneBy): array {
    $email = strtolower(trim($email));

    $pdo->prepare("DELETE FROM agent_passwords WHERE email = ?")->execute([$email]);

    $roster = remove_roster_agent($pdo, $name, $marketCenter, null, $doneBy);

    $bridgeOk = null;
    try {
        $c = cfg();
        if (!empty($c['auth_bridge_url'])) {
            $r = bridge_request('deactivate_agent', ['email' => $email]);
            $bridgeOk = $r['ok'] ?? false;
        }
    } catch (\Throwable $e) {}

    $notes = [];
    if (!$roster['found']) $notes[] = 'no matching roster row found';
    if ($bridgeOk === false) $notes[] = 'Perfex bridge deactivation failed';

    return ['ok' => true, 'note' => $notes ? implode('; ', $notes) : null];
}
