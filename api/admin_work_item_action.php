<?php
// Admin Work OS mutations: create (Quick Capture), update (title/description/
// category/due_date, plus waiting_on/follow_up_date while status='waiting'),
// status (ordinary status change + Mark Done/Reopen).
// Every write here is owner-scoped to the authenticated session -- there is
// no super_admin bypass, and owner_email/created_by_email are never accepted
// from the client on any action.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/admin_work_items.php';
require_once __DIR__ . '/../lib/feature_flags.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me || !is_admin()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
if (!feature_enabled_for_current_user('admin_work_os')) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];

// Explicit CSRF check (not just same-origin fetch) -- matches the
// admin_conference_rooms.php/admin_links.php/ticket_file_action.php
// convention, not the unprotected support_ticket.php/wf_action.php one.
if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($in['csrf'] ?? ''))) {
    http_response_code(403); echo json_encode(['error' => 'invalid csrf token']); exit;
}

$myEmail = strtolower(trim($me['email'] ?? ''));
$db      = local_db();
$action  = $in['action'] ?? '';

// One row per meaningful change -- same shape as support_ticket_events /
// log_ticket_event(), local to this file per that same precedent.
function log_work_item_event(PDO $db, int $itemId, string $type, string $detail, string $actorEmail): void {
    $db->prepare("INSERT INTO admin_work_item_events (item_id, event_type, detail, actor_email) VALUES (?, ?, ?, ?)")
       ->execute([$itemId, $type, $detail, $actorEmail]);
}

// Keeps a long free-typed value out of a single-line event detail without
// truncating the actual stored value.
function awi_ellipsis(string $s, int $max = 100): string {
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
}

// Loads a work item, scoped to the current owner only -- no super_admin
// bypass. A non-owned or nonexistent id gets the same 404 either way, so a
// crafted id never distinguishes "not yours" from "doesn't exist". A
// soft-deleted row (V1E-B) is excluded here too -- one gate makes every
// action below (update/status/delete) treat a deleted item as gone, with
// no separate deleted_at check needed at each call site.
function load_owned_item(PDO $db, int $id, string $ownerEmail): ?array {
    $stmt = $db->prepare("SELECT * FROM admin_work_items WHERE id = ? AND LOWER(owner_email) = LOWER(?) AND deleted_at IS NULL");
    $stmt->execute([$id, $ownerEmail]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Quick Capture ────────────────────────────────────────────────────────────
if ($action === 'create') {
    $title = trim($in['title'] ?? '');
    if ($title === '') { http_response_code(400); echo json_encode(['error' => 'title required']); exit; }

    $db->beginTransaction();
    try {
        $db->prepare(
            "INSERT INTO admin_work_items
                (title, description, owner_email, created_by_email, category, status,
                 due_date, source_type, source_label, waiting_on, follow_up_date, completed_at)
             VALUES (?, NULL, ?, ?, 'admin', 'inbox', NULL, 'manual', '', NULL, NULL, NULL)"
        )->execute([$title, $myEmail, $myEmail]);
        $id = (int)$db->lastInsertId();
        log_work_item_event($db, $id, 'created', 'Created via Quick Capture', $myEmail);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        http_response_code(500); echo json_encode(['error' => 'save failed']); exit;
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

// ── Edit (title/description/category/due_date) ──────────────────────────────
if ($action === 'update') {
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
    $row = load_owned_item($db, $id, $myEmail);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

    $newTitle = trim($in['title'] ?? '');
    if ($newTitle === '') { http_response_code(400); echo json_encode(['error' => 'title required']); exit; }

    $newDescription = trim((string)($in['description'] ?? ''));
    $newDescription = $newDescription === '' ? null : $newDescription;

    $newCategory = trim($in['category'] ?? '');
    if (!in_array($newCategory, ADMIN_WORK_CATEGORIES, true)) {
        http_response_code(400); echo json_encode(['error' => 'invalid category']); exit;
    }

    $newDueDate = awos_normalize_date($in['due_date'] ?? null);
    if (!awos_valid_date($newDueDate)) {
        http_response_code(400); echo json_encode(['error' => 'invalid due_date']); exit;
    }

    $oldDescription = $row['description'] !== null && trim((string)$row['description']) !== '' ? $row['description'] : null;
    $oldDueDate     = awos_normalize_date($row['due_date']);

    $sets   = [];
    $params = [];
    $events = [];

    if ($newTitle !== $row['title']) {
        $sets[] = 'title = ?'; $params[] = $newTitle;
        $events[] = ['title_changed', 'Title: "' . awi_ellipsis($row['title']) . '" -> "' . awi_ellipsis($newTitle) . '"'];
    }
    if ($newDescription !== $oldDescription) {
        $sets[] = 'description = ?'; $params[] = $newDescription;
        // Never copy the actual description body into history -- just note that it changed.
        $events[] = ['description_changed', 'Description updated'];
    }
    if ($newCategory !== $row['category']) {
        $sets[] = 'category = ?'; $params[] = $newCategory;
        $events[] = ['category_changed', 'Category: ' . awos_category_label($row['category']) . ' -> ' . awos_category_label($newCategory)];
    }
    if ($newDueDate !== $oldDueDate) {
        $sets[] = 'due_date = ?'; $params[] = $newDueDate;
        $events[] = ['due_date_changed', 'Due date: ' . ($oldDueDate ?? '(none)') . ' -> ' . ($newDueDate ?? '(none)')];
    }

    // waiting_on / follow_up_date are only ever accepted while the task is
    // actually Waiting -- gated on the row's real status, not on whether the
    // client happened to submit them, so a crafted request against a
    // non-waiting task can't set "waiting" context that could resurface
    // confusingly if it re-enters Waiting later. Each key is also checked
    // independently for presence, so omitting one never wipes it out.
    if ($row['status'] === 'waiting') {
        if (array_key_exists('waiting_on', $in)) {
            $newWaitingOn = trim((string)$in['waiting_on']);
            $newWaitingOn = $newWaitingOn === '' ? null : $newWaitingOn;
            $oldWaitingOn = trim((string)($row['waiting_on'] ?? ''));
            $oldWaitingOn = $oldWaitingOn === '' ? null : $oldWaitingOn;
            if ($newWaitingOn !== $oldWaitingOn) {
                $sets[] = 'waiting_on = ?'; $params[] = $newWaitingOn;
                $events[] = ['waiting_on_changed', 'Waiting on: ' . ($oldWaitingOn !== null ? '"' . awi_ellipsis($oldWaitingOn) . '"' : '(none)')
                    . ' -> ' . ($newWaitingOn !== null ? '"' . awi_ellipsis($newWaitingOn) . '"' : '(none)')];
            }
        }
        if (array_key_exists('follow_up_date', $in)) {
            $newFollowUp = awos_normalize_date($in['follow_up_date']);
            if (!awos_valid_date($newFollowUp)) {
                http_response_code(400); echo json_encode(['error' => 'invalid follow_up_date']); exit;
            }
            $oldFollowUp = awos_normalize_date($row['follow_up_date']);
            if ($newFollowUp !== $oldFollowUp) {
                $sets[] = 'follow_up_date = ?'; $params[] = $newFollowUp;
                $events[] = ['follow_up_date_changed', 'Follow up: ' . ($oldFollowUp ?? '(none)') . ' -> ' . ($newFollowUp ?? '(none)')];
            }
        }
    }

    if (empty($sets)) { echo json_encode(['ok' => true, 'changed' => false]); exit; }

    $sets[]   = "updated_at = datetime('now')";
    $params[] = $id;

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE admin_work_items SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        foreach ($events as [$type, $detail]) {
            log_work_item_event($db, $id, $type, $detail, $myEmail);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        http_response_code(500); echo json_encode(['error' => 'save failed']); exit;
    }
    echo json_encode(['ok' => true, 'changed' => true]);
    exit;
}

// ── Status change (ordinary + Mark Done + Reopen) ────────────────────────────
if ($action === 'status') {
    $id        = (int)($in['id'] ?? 0);
    $newStatus = trim($in['status'] ?? '');
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
    if (!in_array($newStatus, ADMIN_WORK_STATUSES, true)) {
        http_response_code(400); echo json_encode(['error' => 'invalid status']); exit;
    }
    $row = load_owned_item($db, $id, $myEmail);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

    $oldStatus = $row['status'];
    if ($oldStatus === $newStatus) { echo json_encode(['ok' => true, 'changed' => false]); exit; }

    $db->beginTransaction();
    try {
        if ($oldStatus !== 'done' && $newStatus === 'done') {
            // waiting_on/follow_up_date are deliberately NOT touched here --
            // retained as stored data (never shown live again once Done, since
            // the detail page only renders those fields while status='waiting'),
            // just optionally mentioned in this event for context.
            $db->prepare("UPDATE admin_work_items SET status='done', completed_at=datetime('now'), updated_at=datetime('now') WHERE id=?")
               ->execute([$id]);
            $priorWaitingOn = trim((string)($row['waiting_on'] ?? ''));
            $detail = ($oldStatus === 'waiting' && $priorWaitingOn !== '')
                ? 'Marked done (was waiting on: "' . awi_ellipsis($priorWaitingOn) . '")'
                : 'Marked done';
            log_work_item_event($db, $id, 'completed', $detail, $myEmail);
        } elseif ($oldStatus === 'done' && $newStatus !== 'done') {
            // Reopening always clears any retained waiting_on/follow_up_date
            // (harmless no-op if they were already empty) -- a done task's
            // waiting context was preserved only for the completion record,
            // not so it could quietly resurface if this task is later moved
            // back into Waiting for an unrelated reason.
            $hadWaitingContext = trim((string)($row['waiting_on'] ?? '')) !== ''
                || trim((string)($row['follow_up_date'] ?? '')) !== '';
            $db->prepare("UPDATE admin_work_items SET status=?, completed_at=NULL, waiting_on=NULL, follow_up_date=NULL, updated_at=datetime('now') WHERE id=?")
               ->execute([$newStatus, $id]);
            $detail = 'Reopened to ' . awos_status_label($newStatus);
            if ($hadWaitingContext) $detail .= '; prior waiting context cleared';
            log_work_item_event($db, $id, 'reopened', $detail, $myEmail);
        } elseif ($oldStatus === 'waiting' && $newStatus !== 'waiting') {
            // Leaving Waiting for Inbox/Next -- clear the fields so stale
            // "why is this blocked" context can't resurface confusingly if
            // the task re-enters Waiting later for an unrelated reason.
            $priorWaitingOn  = trim((string)($row['waiting_on'] ?? ''));
            $priorFollowUp   = trim((string)($row['follow_up_date'] ?? ''));
            $db->prepare("UPDATE admin_work_items SET status=?, waiting_on=NULL, follow_up_date=NULL, updated_at=datetime('now') WHERE id=?")
               ->execute([$newStatus, $id]);
            log_work_item_event($db, $id, 'status_changed', 'Status: ' . awos_status_label($oldStatus) . ' -> ' . awos_status_label($newStatus), $myEmail);
            // Only log the clear if there was actually something to clear --
            // no redundant event for a task that was Waiting with both fields
            // already blank.
            if ($priorWaitingOn !== '' || $priorFollowUp !== '') {
                $clearedDetail = 'Left Waiting';
                if ($priorWaitingOn !== '') $clearedDetail .= ' — cleared: "' . awi_ellipsis($priorWaitingOn) . '"';
                log_work_item_event($db, $id, 'waiting_cleared', $clearedDetail, $myEmail);
            }
        } else {
            $db->prepare("UPDATE admin_work_items SET status=?, updated_at=datetime('now') WHERE id=?")
               ->execute([$newStatus, $id]);
            log_work_item_event($db, $id, 'status_changed', 'Status: ' . awos_status_label($oldStatus) . ' -> ' . awos_status_label($newStatus), $myEmail);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        http_response_code(500); echo json_encode(['error' => 'save failed']); exit;
    }
    echo json_encode(['ok' => true, 'changed' => true, 'status' => $newStatus]);
    exit;
}

// ── Delete (soft) ─────────────────────────────────────────────────────────
// Never a real DELETE -- sets deleted_at only. If the item was generated
// from a routine, its admin_work_routine_occurrences row, the routine
// itself, and all admin_work_item_events history are deliberately left
// untouched (see lib/admin_work_routines.php's generate_due_routine_items():
// the occurrence row is what stops a deleted-today occurrence from being
// regenerated on refresh -- deleting it here would defeat that).
if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
    $row = load_owned_item($db, $id, $myEmail);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

    $db->beginTransaction();
    try {
        // "AND deleted_at IS NULL" here (not just the load above) is the
        // actual concurrency guard -- two simultaneous delete requests can
        // both pass load_owned_item() before either commits. rowCount()
        // tells us which one, if either, actually flipped the row, so
        // exactly one 'deleted' event is ever logged no matter how the two
        // requests interleave.
        $upd = $db->prepare("UPDATE admin_work_items SET deleted_at = datetime('now'), updated_at = datetime('now') WHERE id = ? AND deleted_at IS NULL");
        $upd->execute([$id]);
        if ($upd->rowCount() === 0) {
            // Lost the race -- another request already deleted this item
            // between our load and this UPDATE. End state is what the
            // caller wanted either way, so this is a success, not an error.
            $db->rollBack();
            echo json_encode(['ok' => true, 'changed' => false]);
            exit;
        }
        log_work_item_event($db, $id, 'deleted', 'Task deleted', $myEmail);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        http_response_code(500); echo json_encode(['error' => 'save failed']); exit;
    }
    echo json_encode(['ok' => true, 'changed' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);
