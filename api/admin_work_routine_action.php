<?php
// Admin Work OS Routines mutations: create, update, toggle (enable/disable).
// V1D-A only -- no generation happens here or anywhere yet. Every write is
// owner-scoped to the authenticated session; owner_email is never accepted
// from the client on any action, and there is no super_admin bypass.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/admin_work_items.php';
require_once __DIR__ . '/../lib/admin_work_routines.php';
require_once __DIR__ . '/../lib/feature_flags.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me || !is_admin()) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }
if (!feature_enabled_for_current_user('admin_work_os')) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];

if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($in['csrf'] ?? ''))) {
    http_response_code(403); echo json_encode(['error' => 'invalid csrf token']); exit;
}

$myEmail = strtolower(trim($me['email'] ?? ''));
$db      = local_db();
$action  = $in['action'] ?? '';

function load_owned_routine(PDO $db, int $id, string $ownerEmail): ?array {
    $stmt = $db->prepare("SELECT * FROM admin_work_routines WHERE id = ? AND LOWER(owner_email) = LOWER(?)");
    $stmt->execute([$id, $ownerEmail]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Validates + normalizes the shared fields for create/update. Returns an
// array of validated values, or writes a 400 response and returns null.
function validate_routine_input(array $in): ?array {
    $title = trim($in['title'] ?? '');
    if ($title === '') { http_response_code(400); echo json_encode(['error' => 'title required']); return null; }

    $description = trim((string)($in['description'] ?? ''));
    $description = $description === '' ? null : $description;

    $category = trim($in['category'] ?? '');
    if (!in_array($category, ADMIN_WORK_CATEGORIES, true)) {
        http_response_code(400); echo json_encode(['error' => 'invalid category']); return null;
    }

    $routineArea = trim($in['routine_area'] ?? 'general');
    if (!in_array($routineArea, ADMIN_WORK_ROUTINE_AREAS, true)) {
        http_response_code(400); echo json_encode(['error' => 'invalid routine_area']); return null;
    }

    $frequency = trim($in['frequency'] ?? '');
    if (!in_array($frequency, ADMIN_WORK_ROUTINE_FREQUENCIES, true)) {
        http_response_code(400); echo json_encode(['error' => 'invalid frequency']); return null;
    }

    $relevant = awos_routine_schedule_fields($frequency);

    $weekday = null;
    if (in_array('schedule_weekday', $relevant, true)) {
        $weekday = filter_var($in['schedule_weekday'] ?? null, FILTER_VALIDATE_INT);
        if ($weekday === false || $weekday === null || $weekday < 1 || $weekday > 7) {
            http_response_code(400); echo json_encode(['error' => 'schedule_weekday required (1-7) for this frequency']); return null;
        }
    }

    $dayOfMonth = null;
    if (in_array('schedule_day_of_month', $relevant, true)) {
        $dayOfMonth = filter_var($in['schedule_day_of_month'] ?? null, FILTER_VALIDATE_INT);
        if ($dayOfMonth === false || $dayOfMonth === null || $dayOfMonth < 1 || $dayOfMonth > 31) {
            http_response_code(400); echo json_encode(['error' => 'schedule_day_of_month required (1-31) for this frequency']); return null;
        }
    }

    $month = null;
    if (in_array('schedule_month', $relevant, true)) {
        $month = filter_var($in['schedule_month'] ?? null, FILTER_VALIDATE_INT);
        if ($month === false || $month === null || $month < 1 || $month > 12) {
            http_response_code(400); echo json_encode(['error' => 'schedule_month required (1-12) for this frequency']); return null;
        }
    }

    $weekdaysJson = null;
    if (in_array('schedule_weekdays', $relevant, true)) {
        $rawWeekdays = is_array($in['schedule_weekdays'] ?? null) ? $in['schedule_weekdays'] : [];
        $weekdaysJson = awos_canonical_weekdays($rawWeekdays);
        if ($weekdaysJson === null) {
            http_response_code(400); echo json_encode(['error' => 'schedule_weekdays requires at least one valid weekday (1-7) for this frequency']); return null;
        }
    }

    $anchorDate = null;
    if (in_array('schedule_anchor_date', $relevant, true)) {
        $anchorDate = trim((string)($in['schedule_anchor_date'] ?? ''));
        if ($anchorDate === '' || !awos_valid_date($anchorDate)) {
            http_response_code(400); echo json_encode(['error' => 'valid schedule_anchor_date (YYYY-MM-DD) required for this frequency']); return null;
        }
        // Cross-field: the anchor must actually fall on the selected weekday,
        // otherwise "every other Wednesday starting <a Tuesday>" is nonsense.
        $anchorIso = (int)date('N', strtotime($anchorDate . ' 00:00:00'));
        if ($weekday !== null && $anchorIso !== $weekday) {
            http_response_code(400); echo json_encode(['error' => 'schedule_anchor_date must fall on the selected schedule_weekday']); return null;
        }
    }

    // Server-side normalization -- the UI hides irrelevant fields, but this
    // is the actual enforcement: anything not relevant to $frequency is
    // forced to null here regardless of what the client sent.
    [$weekday, $dayOfMonth, $month, $weekdaysJson, $anchorDate] =
        awos_normalize_routine_schedule($frequency, $weekday, $dayOfMonth, $month, $weekdaysJson, $anchorDate);

    return [
        'title' => $title, 'description' => $description, 'category' => $category,
        'routine_area' => $routineArea, 'frequency' => $frequency,
        'schedule_weekday' => $weekday, 'schedule_day_of_month' => $dayOfMonth, 'schedule_month' => $month,
        'schedule_weekdays' => $weekdaysJson, 'schedule_anchor_date' => $anchorDate,
        'enabled' => !empty($in['enabled']) ? 1 : 0,
    ];
}

// ── Create ───────────────────────────────────────────────────────────────────
if ($action === 'create') {
    $v = validate_routine_input($in);
    if ($v === null) exit;

    $db->prepare(
        "INSERT INTO admin_work_routines
            (owner_email, title, description, category, routine_area, frequency,
             schedule_weekday, schedule_day_of_month, schedule_month, schedule_weekdays, schedule_anchor_date, enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $myEmail, $v['title'], $v['description'], $v['category'], $v['routine_area'], $v['frequency'],
        $v['schedule_weekday'], $v['schedule_day_of_month'], $v['schedule_month'],
        $v['schedule_weekdays'], $v['schedule_anchor_date'], $v['enabled'],
    ]);
    echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
    exit;
}

// ── Update ───────────────────────────────────────────────────────────────────
if ($action === 'update') {
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
    $row = load_owned_routine($db, $id, $myEmail);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

    $v = validate_routine_input($in);
    if ($v === null) exit;

    $db->prepare(
        "UPDATE admin_work_routines SET
            title=?, description=?, category=?, routine_area=?, frequency=?,
            schedule_weekday=?, schedule_day_of_month=?, schedule_month=?,
            schedule_weekdays=?, schedule_anchor_date=?, enabled=?,
            updated_at=datetime('now')
         WHERE id=?"
    )->execute([
        $v['title'], $v['description'], $v['category'], $v['routine_area'], $v['frequency'],
        $v['schedule_weekday'], $v['schedule_day_of_month'], $v['schedule_month'],
        $v['schedule_weekdays'], $v['schedule_anchor_date'], $v['enabled'],
        $id,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Toggle (enable/disable) ──────────────────────────────────────────────────
if ($action === 'toggle') {
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
    $row = load_owned_routine($db, $id, $myEmail);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'not found']); exit; }

    $enabled = !empty($in['enabled']) ? 1 : 0;
    $db->prepare("UPDATE admin_work_routines SET enabled=?, updated_at=datetime('now') WHERE id=?")
       ->execute([$enabled, $id]);
    echo json_encode(['ok' => true, 'enabled' => (bool)$enabled]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown action']);
