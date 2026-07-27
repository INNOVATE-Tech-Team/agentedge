<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');
$agent = require_login();
if (!is_super_admin()) { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$in     = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $in['action'] ?? '';
$db     = local_db();

function team_slugify(string $s): string {
    return preg_replace('/^-|-$/', '', preg_replace('/[^a-z0-9]+/', '-', strtolower($s)));
}

if ($action === 'save') {
    $id          = (int)($in['id'] ?? 0);
    $name        = trim($in['name'] ?? '');
    $leaderEmail = strtolower(trim($in['leader_email'] ?? ''));
    $ord         = (int)($in['sort_ord'] ?? 0);

    if (!$name) { echo json_encode(['ok'=>false,'error'=>'Name is required']); exit; }
    $slugBase = team_slugify($name);
    if ($slugBase === '') { echo json_encode(['ok'=>false,'error'=>'Name must contain letters or numbers']); exit; }

    try {
        if ($id) {
            // Slug is a permanent identifier, not derived from name — keep it
            // stable across renames (same principle as market_centers.slug).
            $stmt = $db->prepare("SELECT slug FROM teams WHERE id=?");
            $stmt->execute([$id]);
            $slug = $stmt->fetchColumn();
            if ($slug === false) { echo json_encode(['ok'=>false,'error'=>'Team not found']); exit; }
            $db->prepare("UPDATE teams SET name=?, leader_email=?, sort_ord=? WHERE id=?")
               ->execute([$name, $leaderEmail, $ord, $id]);
        } else {
            $slug   = $slugBase;
            $suffix = 1;
            $chk = $db->prepare("SELECT COUNT(*) FROM teams WHERE slug=?");
            while (true) {
                $chk->execute([$slug]);
                if ((int)$chk->fetchColumn() === 0) break;
                $suffix++;
                $slug = $slugBase . '-' . $suffix;
            }
            $db->prepare("INSERT INTO teams (name, slug, leader_email, sort_ord, enabled) VALUES (?, ?, ?, ?, 1)")
               ->execute([$name, $slug, $leaderEmail, $ord]);
            $id = (int)$db->lastInsertId();
        }
        // Push to Advantage (coastline-server) so a team created or edited
        // here resolves/creates its leader's Advantage account and Recruiting
        // Outreach team-scoping right away — best-effort, never blocks the
        // local save if unreachable. Complements the nightly bulk pull
        // (/admin/sync-agentedge), same pattern mc_action.php uses for MCs.
        try {
            $c       = cfg();
            $pushUrl = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/')
                . '/public/agentedge/team?token=' . urlencode($c['crm_token'] ?? '');
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'timeout'       => 8,
                'header'        => "Content-Type: application/json\r\n",
                'content'       => json_encode([
                    'agentedge_team_id' => $id, 'name' => $name,
                    'leader_email'      => $leaderEmail, 'enabled' => true,
                ]),
                'ignore_errors' => true,
            ]]);
            @file_get_contents($pushUrl, false, $ctx);
        } catch (\Throwable $e) {}

        echo json_encode(['ok'=>true, 'id'=>$id, 'slug'=>$slug, 'name'=>$name, 'leader_email'=>$leaderEmail, 'sort_ord'=>$ord]);
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Id required']); exit; }
    $db->prepare("DELETE FROM teams WHERE id=?")->execute([$id]);
    $db->prepare("DELETE FROM team_members WHERE team_id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'toggle') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'Id required']); exit; }
    $db->prepare("UPDATE teams SET enabled = CASE WHEN enabled=1 THEN 0 ELSE 1 END WHERE id=?")->execute([$id]);
    $stmt = $db->prepare("SELECT name, leader_email, enabled FROM teams WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Push the new enabled state to Advantage too — disabling a team should
    // actively revoke its leader's Recruiting Outreach team-scoping, not
    // just wait for the next nightly sync.
    try {
        $c       = cfg();
        $pushUrl = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/')
            . '/public/agentedge/team?token=' . urlencode($c['crm_token'] ?? '');
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'timeout'       => 8,
            'header'        => "Content-Type: application/json\r\n",
            'content'       => json_encode([
                'agentedge_team_id' => $id, 'name' => $row['name'] ?? '',
                'leader_email'      => $row['leader_email'] ?? '',
                'enabled'           => (bool)($row['enabled'] ?? 0),
            ]),
            'ignore_errors' => true,
        ]]);
        @file_get_contents($pushUrl, false, $ctx);
    } catch (\Throwable $e) {}

    echo json_encode(['ok'=>true, 'enabled'=>(int)($row['enabled'] ?? 0)]);
    exit;
}

if ($action === 'add_member') {
    $teamId = (int)($in['team_id'] ?? 0);
    $email  = strtolower(trim($in['agent_email'] ?? ''));
    if (!$teamId || !$email) { echo json_encode(['ok'=>false,'error'=>'team_id and agent_email required']); exit; }
    // agent_email is the PK on team_members — this silently moves the agent
    // off any prior team (one team per agent).
    $db->prepare(
        "INSERT INTO team_members (agent_email, team_id) VALUES (?, ?)
         ON CONFLICT(agent_email) DO UPDATE SET team_id=excluded.team_id, added_at=datetime('now')"
    )->execute([$email, $teamId]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'remove_member') {
    $email = strtolower(trim($in['agent_email'] ?? ''));
    if (!$email) { echo json_encode(['ok'=>false,'error'=>'agent_email required']); exit; }
    $db->prepare("DELETE FROM team_members WHERE agent_email=?")->execute([$email]);
    echo json_encode(['ok'=>true]);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
