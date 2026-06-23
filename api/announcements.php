<?php
// GET  — returns active announcements scoped to the signed-in agent's role/MC/BIC.
// POST — create/update/delete/pin. Access depends on role.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require __DIR__ . '/../lib/notifications.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }

// ── GET ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $db = local_db();

    if (is_admin()) {
        // Admins see every active announcement.
        $rows = $db->query(
            "SELECT id,title,body,author,audience,target_mc_slug,target_bic_email,pinned,created_at,expires_at
             FROM announcements
             WHERE (expires_at IS NULL OR expires_at >= datetime('now'))
             ORDER BY pinned DESC, created_at DESC
             LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Build a set of OR conditions for what this agent is allowed to see.
        $conds  = ["audience='all'"];
        $params = [];

        // Agent's own MC (set by admin in agent_roles).
        $ownMc = my_own_mc_slug();
        if ($ownMc !== '') {
            $conds[]  = "(audience='mc' AND target_mc_slug=?)";
            $params[] = $ownMc;
        }

        // MC leader / BIC also see announcements targeted to any MC they lead.
        foreach (my_mc_slugs() as $slug) {
            if ($slug !== $ownMc) { // avoid duplicate if own_mc_slug matches a led slug
                $conds[]  = "(audience='mc' AND target_mc_slug=?)";
                $params[] = $slug;
            }
        }

        // Announcements from the BIC assigned to this agent.
        $bicEmail = my_bic_email();
        if ($bicEmail !== '') {
            $conds[]  = "(audience='bic' AND target_bic_email=?)";
            $params[] = $bicEmail;
        }

        // BICs see their own announcements in the panel too.
        if (is_bic()) {
            $conds[]  = "(audience='bic' AND target_bic_email=?)";
            $params[] = $me['email'];
        }

        $where = '(' . implode(' OR ', $conds) . ')';
        $stmt  = $db->prepare(
            "SELECT id,title,body,author,audience,target_mc_slug,target_bic_email,pinned,created_at,expires_at
             FROM announcements
             WHERE (expires_at IS NULL OR expires_at >= datetime('now'))
               AND $where
             ORDER BY pinned DESC, created_at DESC
             LIMIT 20"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['ok'=>true,'items'=>$rows]);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if (!can_post_announcements()) {
    http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
}

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? 'create';

if ($action === 'create' || $action === 'update') {
    $title = trim($in['title'] ?? '');
    $body  = trim($in['body']  ?? '');
    if (!$title || !$body) {
        http_response_code(400); echo json_encode(['error'=>'title and body required']); exit;
    }
    $pinned  = empty($in['pinned'])    ? 0 : 1;
    $expires = !empty($in['expires_at']) ? $in['expires_at'] : null;

    // Determine audience + targets based on role.
    $audience        = '';
    $targetMcSlug    = '';
    $targetBicEmail  = '';

    if (is_admin()) {
        $audience = in_array($in['audience'] ?? '', ['all','admin','mc']) ? $in['audience'] : 'all';
        if ($audience === 'mc') {
            $targetMcSlug = trim($in['target_mc_slug'] ?? '');
            if ($targetMcSlug === '') {
                http_response_code(400); echo json_encode(['error'=>'target_mc_slug required for mc audience']); exit;
            }
        }
    } elseif (is_mc_leader()) {
        $audience     = 'mc';
        $targetMcSlug = trim($in['target_mc_slug'] ?? '');
        if (!in_array($targetMcSlug, my_mc_slugs(), true)) {
            http_response_code(403); echo json_encode(['error'=>'not your market center']); exit;
        }
    } elseif (is_bic()) {
        $audience       = 'bic';
        $targetBicEmail = $me['email'];
    }

    if ($audience === '') {
        http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
    }

    if ($action === 'update') {
        $id = (int)($in['id'] ?? 0);
        // Verify the author owns this announcement (unless admin).
        if (!is_admin()) {
            $row = local_db()->prepare("SELECT author FROM announcements WHERE id=?");
            $row->execute([$id]);
            $existing = $row->fetch(PDO::FETCH_ASSOC);
            if (!$existing || $existing['author'] !== $me['email']) {
                http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
            }
        }
        local_db()->prepare(
            "UPDATE announcements SET title=?,body=?,audience=?,target_mc_slug=?,target_bic_email=?,pinned=?,expires_at=? WHERE id=?"
        )->execute([$title,$body,$audience,$targetMcSlug,$targetBicEmail,$pinned,$expires,$id]);
    } else {
        local_db()->prepare(
            "INSERT INTO announcements (title,body,author,audience,target_mc_slug,target_bic_email,pinned,expires_at) VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$title,$body,$me['email'],$audience,$targetMcSlug,$targetBicEmail,$pinned,$expires]);
        $in['id'] = local_db()->lastInsertId();

        // Queue notifications for the new announcement, then send after response.
        $queued = queue_announcement_notifications(
            (int)$in['id'], $title, $body, $audience, $targetMcSlug, $targetBicEmail
        );
        echo json_encode(['ok'=>true,'id'=>(int)$in['id'],'notified'=>$queued]);
        dispatch_notification_queue();
        exit;
    }
    echo json_encode(['ok'=>true,'id'=>(int)$in['id']]);
    exit;
}

if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if (!is_admin()) {
        $row = local_db()->prepare("SELECT author FROM announcements WHERE id=?");
        $row->execute([$id]);
        $existing = $row->fetch(PDO::FETCH_ASSOC);
        if (!$existing || $existing['author'] !== $me['email']) {
            http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
        }
    }
    local_db()->prepare("DELETE FROM announcements WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'pin') {
    if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
    $id  = (int)($in['id'] ?? 0);
    $val = empty($in['pinned']) ? 0 : 1;
    local_db()->prepare("UPDATE announcements SET pinned=? WHERE id=?")->execute([$val,$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
