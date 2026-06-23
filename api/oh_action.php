<?php
// Open House Portal — action handler. All mutating operations go through here.
// Returns JSON. Requires a logged-in session.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

header('Content-Type: application/json');

$agent = current_agent();
if (!$agent) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$action = trim($_POST['action'] ?? '');
$db     = local_db();
$email  = strtolower(trim($agent['email']));
$name   = $agent['name'] ?? '';

function oh_pref(PDO $db, string $key, string $default = ''): string {
    $s = $db->prepare("SELECT value FROM oh_prefs WHERE key=?");
    $s->execute([$key]);
    $r = $s->fetchColumn();
    return $r !== false ? $r : $default;
}

switch ($action) {

    // ── REQUEST A SLOT ────────────────────────────────────────────────────────
    case 'request_slot': {
        $slot_id = (int)($_POST['slot_id'] ?? 0);
        if (!$slot_id) { echo json_encode(['error' => 'Missing slot_id']); exit; }

        // Get slot + listing info
        $slot = $db->prepare("SELECT s.*, l.listing_agent_email, l.visible
                               FROM oh_slots s
                               JOIN oh_listings l ON l.id = s.listing_id
                               WHERE s.id = ?");
        $slot->execute([$slot_id]);
        $slotRow = $slot->fetch(PDO::FETCH_ASSOC);
        if (!$slotRow) { echo json_encode(['error' => 'Slot not found']); exit; }
        if (!$slotRow['visible']) { echo json_encode(['error' => 'Listing not visible']); exit; }
        if (strtolower($slotRow['listing_agent_email']) === $email) {
            echo json_encode(['error' => 'You cannot request your own listing']);
            exit;
        }

        // Check if agent already has a non-cancelled request for this slot
        $dup = $db->prepare("SELECT id FROM oh_requests WHERE slot_id=? AND agent_email=? AND status != 'cancelled'");
        $dup->execute([$slot_id, $email]);
        if ($dup->fetch()) {
            echo json_encode(['error' => 'You already have a request for this slot']);
            exit;
        }

        // Check max_per_slot unless allow_overlap is on
        $allowOverlap = oh_pref($db, 'allow_overlap', '0');
        if ($allowOverlap !== '1') {
            $maxPerSlot = (int)oh_pref($db, 'max_per_slot', '1');
            if ($maxPerSlot < 1) $maxPerSlot = 1;
            $countS = $db->prepare("SELECT COUNT(*) FROM oh_requests WHERE slot_id=? AND status='approved'");
            $countS->execute([$slot_id]);
            $approvedCount = (int)$countS->fetchColumn();
            if ($approvedCount >= $maxPerSlot) {
                echo json_encode(['error' => 'This slot is already full']);
                exit;
            }
        }

        $ins = $db->prepare("INSERT INTO oh_requests (slot_id, listing_id, agent_email, agent_name, status)
                              VALUES (?, ?, ?, ?, 'pending')");
        $ins->execute([$slot_id, $slotRow['listing_id'], $email, $name]);
        $newId = (int)$db->lastInsertId();
        echo json_encode(['ok' => true, 'request_id' => $newId]);
        break;
    }

    // ── CANCEL A REQUEST ──────────────────────────────────────────────────────
    case 'cancel_request': {
        $request_id = (int)($_POST['request_id'] ?? 0);
        if (!$request_id) { echo json_encode(['error' => 'Missing request_id']); exit; }

        $req = $db->prepare("SELECT * FROM oh_requests WHERE id=?");
        $req->execute([$request_id]);
        $row = $req->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error' => 'Request not found']); exit; }
        if (strtolower($row['agent_email']) !== $email) {
            echo json_encode(['error' => 'Not your request']);
            exit;
        }
        if ($row['status'] !== 'pending') {
            echo json_encode(['error' => 'Only pending requests can be cancelled']);
            exit;
        }

        $db->prepare("UPDATE oh_requests SET status='cancelled' WHERE id=?")->execute([$request_id]);
        echo json_encode(['ok' => true]);
        break;
    }

    // ── RESPOND TO A REQUEST (approve/decline) ────────────────────────────────
    case 'respond': {
        $request_id = (int)($_POST['request_id'] ?? 0);
        $decision   = trim($_POST['decision'] ?? '');
        $reason     = trim($_POST['reason'] ?? '');
        if (!$request_id || !in_array($decision, ['approve', 'decline'], true)) {
            echo json_encode(['error' => 'Invalid parameters']);
            exit;
        }

        $req = $db->prepare("SELECT r.*, l.listing_agent_email FROM oh_requests r JOIN oh_listings l ON l.id=r.listing_id WHERE r.id=?");
        $req->execute([$request_id]);
        $row = $req->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error' => 'Request not found']); exit; }

        $isOwner = strtolower($row['listing_agent_email']) === $email;
        if (!$isOwner && !is_admin()) {
            echo json_encode(['error' => 'Not authorized']);
            exit;
        }

        $newStatus = $decision === 'approve' ? 'approved' : 'declined';
        $db->prepare("UPDATE oh_requests SET status=?, reason=? WHERE id=?")->execute([$newStatus, $reason, $request_id]);
        echo json_encode(['ok' => true, 'status' => $newStatus]);
        break;
    }

    // ── TOGGLE LISTING VISIBILITY ─────────────────────────────────────────────
    case 'toggle_visible': {
        $listing_id = (int)($_POST['listing_id'] ?? 0);
        if (!$listing_id) { echo json_encode(['error' => 'Missing listing_id']); exit; }

        $lst = $db->prepare("SELECT * FROM oh_listings WHERE id=?");
        $lst->execute([$listing_id]);
        $row = $lst->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error' => 'Listing not found']); exit; }

        $isOwner = strtolower($row['listing_agent_email']) === $email;
        if (!$isOwner && !is_admin()) {
            echo json_encode(['error' => 'Not authorized']);
            exit;
        }

        $newVal = $row['visible'] ? 0 : 1;
        $db->prepare("UPDATE oh_listings SET visible=? WHERE id=?")->execute([$newVal, $listing_id]);
        echo json_encode(['ok' => true, 'visible' => $newVal]);
        break;
    }

    // ── DELETE A LISTING ──────────────────────────────────────────────────────
    case 'delete_listing': {
        $listing_id = (int)($_POST['listing_id'] ?? 0);
        if (!$listing_id) { echo json_encode(['error' => 'Missing listing_id']); exit; }

        $lst = $db->prepare("SELECT * FROM oh_listings WHERE id=?");
        $lst->execute([$listing_id]);
        $row = $lst->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['error' => 'Listing not found']); exit; }

        $isOwner = strtolower($row['listing_agent_email']) === $email;
        if (!$isOwner && !is_admin()) {
            echo json_encode(['error' => 'Not authorized']);
            exit;
        }

        // Block delete if any approved requests exist
        $chk = $db->prepare("SELECT COUNT(*) FROM oh_requests WHERE listing_id=? AND status='approved'");
        $chk->execute([$listing_id]);
        if ((int)$chk->fetchColumn() > 0) {
            echo json_encode(['error' => 'Cannot delete: listing has approved open house requests']);
            exit;
        }

        // Delete requests, slots, then listing
        $db->prepare("DELETE FROM oh_requests WHERE listing_id=?")->execute([$listing_id]);
        $db->prepare("DELETE FROM oh_slots WHERE listing_id=?")->execute([$listing_id]);
        $db->prepare("DELETE FROM oh_listings WHERE id=?")->execute([$listing_id]);
        echo json_encode(['ok' => true]);
        break;
    }

    // ── SAVE PREFS (admin only) ───────────────────────────────────────────────
    case 'save_prefs': {
        if (!is_admin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Admin only']);
            exit;
        }
        $allowOverlap = (int)($_POST['allow_overlap'] ?? 0) ? '1' : '0';
        $maxPerSlot   = max(1, min(20, (int)($_POST['max_per_slot'] ?? 1)));

        $ups = $db->prepare("INSERT INTO oh_prefs (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
        $ups->execute(['allow_overlap', $allowOverlap]);
        $ups->execute(['max_per_slot', (string)$maxPerSlot]);
        echo json_encode(['ok' => true]);
        break;
    }

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
