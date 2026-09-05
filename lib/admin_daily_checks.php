<?php
// Admin Work OS — Daily Check (V1F-A). One shared data flow for Morning
// Check / Before You Leave so every caller (index.php's manual preview now,
// the automatic popup later) reads today's opening/closing routine work,
// My Schedule, and the follow-up count the exact same way -- no second
// implementation of routine generation or calendar parsing anywhere here.

// Builds everything one Daily Check render needs for $ownerEmail on $today.
// $type is 'morning' or 'closing' -- anything else is treated as 'morning'
// by the caller-facing area mapping below, so callers must validate $type
// against ['morning','closing'] themselves before calling this (index.php
// does, via its ?daily_check= query param).
// $includeSchedule=false skips the calendar fetch entirely (still 'schedule'
// => null either way for closing) -- for callers that only need
// total/completed, e.g. api/admin_daily_check_action.php's sync_completion,
// which would otherwise re-fetch the calendar on every single checkbox click.
function daily_check_data(PDO $db, string $ownerEmail, string $today, string $type, bool $includeSchedule = true): array {
    // Ensure today's routine-generated work exists before querying it.
    // Same reusable, idempotent, concurrency-safe call as admin_work_os.php's
    // own trigger point (see that file's comment) -- calling it from this
    // second site is safe because admin_work_routine_occurrences' composite
    // primary key is the authoritative duplicate guard, not "only one caller".
    generate_due_routine_items($db, $ownerEmail, $today);

    $area = $type === 'closing' ? 'closing' : 'opening';

    // routine_area_snapshot (V1F-A-1), NEVER routines.routine_area -- an
    // edit to the routine template after today's occurrence was generated
    // must not move it between Morning Check and Before You Leave.
    // Deliberately includes BOTH done and unfinished items (deleted_at
    // excluded) -- an already-completed item must show up checked, not
    // vanish; unfinished-only filtering is a later concern (auto-open
    // eligibility), not this data flow.
    $itemStmt = $db->prepare(
        "SELECT wi.id, wi.title, wi.status
         FROM admin_work_routine_occurrences ro
         JOIN admin_work_items wi ON wi.id = ro.work_item_id
         WHERE ro.occurrence_key = ?
           AND ro.routine_area_snapshot = ?
           AND LOWER(wi.owner_email) = ?
           AND wi.deleted_at IS NULL
         ORDER BY wi.created_at ASC"
    );
    $itemStmt->execute([$today, $area, strtolower(trim($ownerEmail))]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $completed = 0;
    foreach ($items as $it) { if ($it['status'] === 'done') $completed++; }
    $total = count($items);

    $result = [
        'items'          => $items,
        'total'          => $total,
        'completed'      => $completed,
        'unfinished'     => $total - $completed,
        'schedule'       => null,
        'followup_count' => 0,
    ];

    // Morning only -- exactly one calendar fetch per Daily Check request,
    // reusing V1E-A's pcal_get_today_schedule() untouched. Closing never
    // fetches the calendar at all (no What's On Today section).
    if ($type !== 'closing' && $includeSchedule) {
        $result['schedule'] = pcal_get_today_schedule($ownerEmail, $today);
    }

    // Follow-up count -- same shape as admin_work_os.php's Follow Ups
    // query (status=waiting, follow_up_date arrived/passed, owner-scoped,
    // deleted excluded), count-only so the popup stays calm/quick; full
    // detail stays exclusively in Admin OS.
    $fuStmt = $db->prepare(
        "SELECT COUNT(*) FROM admin_work_items
         WHERE LOWER(owner_email) = ? AND status = 'waiting' AND deleted_at IS NULL
           AND follow_up_date IS NOT NULL AND follow_up_date != '' AND follow_up_date <= ?"
    );
    $fuStmt->execute([strtolower(trim($ownerEmail)), $today]);
    $result['followup_count'] = (int)$fuStmt->fetchColumn();

    return $result;
}

// V1F-A-5 — whether $type's Daily Check should AUTOMATICALLY open right now.
// Pure function of already-computed state: $data from daily_check_data()
// (empty/unfinished counts) and $stateRow (admin_daily_checks' dismissed_at/
// completed_at, or null values if no row exists yet for today). Weekday/
// time-of-day come from the server clock only (America/New_York, db.php's
// app-wide default tz) -- never the client. Manual ?daily_check= opens are a
// completely separate path (index.php) that never calls this: an explicit
// request always opens regardless of what this returns.
//
// $nowTs defaults to the real request time (time(), Eastern per db.php's
// app-wide default tz) -- every production call site leaves it unset. It
// exists purely so this function's time-boundary branches (weekend, 5am/1pm,
// 4:30pm) can be exercised deterministically from a test/dev script with an
// explicit timestamp instead of waiting for the wall clock. It is never
// read from a request (no query param, no header, nothing client-supplied
// reaches it) -- there is no live "fake clock" feature here.
function daily_check_auto_eligible(string $type, array $data, array $stateRow, ?int $nowTs = null): bool {
    if (!empty($stateRow['dismissed_at'])) return false;
    if (!empty($stateRow['completed_at'])) return false;
    if ($data['total'] <= 0) return false;      // nothing applicable today
    if ($data['unfinished'] <= 0) return false;  // already all done (belt-and-suspenders -- completed_at above should already cover this)

    $nowTs = $nowTs ?? time();
    $dow = (int)date('N', $nowTs); // 1=Mon .. 7=Sun
    if ($dow > 5) return false;

    $hm = date('H:i', $nowTs); // zero-padded 24h, safe to string-compare within a single day
    if ($type === 'closing') {
        return $hm >= '16:30';
    }
    return $hm >= '05:00' && $hm < '13:00';
}
