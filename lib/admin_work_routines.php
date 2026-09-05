<?php
// Admin Work OS Routines — shared vocabulary, formatting/normalization
// helpers, and (V1D-B) the routine -> admin_work_items generation engine.

const ADMIN_WORK_ROUTINE_AREAS = ['general', 'opening', 'people', 'office', 'closing'];
const ADMIN_WORK_ROUTINE_AREA_LABELS = [
    'general' => 'General',
    'opening' => 'Opening',
    'people'  => 'People',
    'office'  => 'Office',
    'closing' => 'Closing',
];

// Verbose wording for the Routines form's Workflow Area select only -- the
// short labels above stay in every other display (collapsed routine tile
// summary, admin_work_os.php's routine attribution line), which have no
// room for a two-part explanation without turning into clutter.
const ADMIN_WORK_ROUTINE_AREA_OPTION_LABELS = [
    'general' => 'General — Regular Routine',
    'opening' => 'Opening — Morning Check',
    'people'  => 'People — People Workflow',
    'office'  => 'Office — Office Work',
    'closing' => 'Closing — Before You Leave',
];

// Deliberately a separate vocabulary from ADMIN_WORK_CATEGORIES
// (lib/admin_work_items.php) -- Routine Area and Category are never
// conflated, even though both are "categorize this" fields.
const ADMIN_WORK_ROUTINE_FREQUENCIES = ['daily', 'weekdays', 'weekly', 'biweekly', 'custom_weekdays', 'monthly', 'semiannual', 'annual'];
const ADMIN_WORK_ROUTINE_FREQUENCY_LABELS = [
    'daily'           => 'Daily',
    'weekdays'        => 'Every Weekday',
    'weekly'          => 'Weekly',
    'biweekly'        => 'Every Other Week',
    'custom_weekdays' => 'Custom Weekdays',
    'monthly'         => 'Monthly',
    'semiannual'      => 'Twice Yearly',
    'annual'          => 'Annually',
];

const ADMIN_WORK_ROUTINE_WEEKDAY_NAMES = [
    1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
    5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
];
const ADMIN_WORK_ROUTINE_MONTH_NAMES = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
    7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

function awos_routine_area_label(string $area): string {
    return ADMIN_WORK_ROUTINE_AREA_LABELS[$area] ?? ucfirst($area);
}
function awos_routine_area_option_label(string $area): string {
    return ADMIN_WORK_ROUTINE_AREA_OPTION_LABELS[$area] ?? awos_routine_area_label($area);
}
function awos_routine_frequency_label(string $freq): string {
    return ADMIN_WORK_ROUTINE_FREQUENCY_LABELS[$freq] ?? ucfirst($freq);
}

function awos_ordinal(int $n): string {
    if ($n % 100 >= 11 && $n % 100 <= 13) return $n . 'th';
    switch ($n % 10) {
        case 1: return $n . 'st';
        case 2: return $n . 'nd';
        case 3: return $n . 'rd';
        default: return $n . 'th';
    }
}

// Which schedule columns matter for a given frequency -- used both to know
// which fields to require/validate and to clear the ones that don't apply.
// Server-side source of truth for the "clear stale hidden values" rule --
// the UI showing/hiding fields is a courtesy, not the enforcement.
function awos_routine_schedule_fields(string $frequency): array {
    switch ($frequency) {
        case 'weekly':          return ['schedule_weekday'];
        case 'biweekly':        return ['schedule_weekday', 'schedule_anchor_date'];
        case 'custom_weekdays': return ['schedule_weekdays'];
        case 'monthly':         return ['schedule_day_of_month'];
        case 'semiannual':
        case 'annual':          return ['schedule_day_of_month', 'schedule_month'];
        case 'daily':
        case 'weekdays':
        default:                return [];
    }
}

// Validates and canonicalizes a set of ISO weekday ints (1=Mon..7=Sun) for
// custom_weekdays: dedupes, sorts into a fixed canonical order, and rejects
// anything out of range or empty. Returns the JSON string to store, or null
// if the input is invalid/empty -- caller treats null as a validation error.
function awos_canonical_weekdays(array $weekdays): ?string {
    $clean = [];
    foreach ($weekdays as $w) {
        $n = filter_var($w, FILTER_VALIDATE_INT);
        if ($n === false || $n < 1 || $n > 7) return null;
        $clean[$n] = true; // dedupe
    }
    if (empty($clean)) return null;
    $days = array_keys($clean);
    sort($days, SORT_NUMERIC);
    return json_encode(array_values($days));
}

// Decodes a schedule_weekdays JSON column back into a plain sorted int array.
// Tolerant of null/malformed storage (returns []) -- callers should never
// crash on a row written before this column existed or by a future format.
function awos_decode_weekdays(?string $json): array {
    if ($json === null || $json === '') return [];
    $days = json_decode($json, true);
    if (!is_array($days)) return [];
    $clean = array_values(array_unique(array_map('intval', $days)));
    sort($clean, SORT_NUMERIC);
    return $clean;
}

// "Monday, Wednesday & Friday" / "Tuesday & Thursday" -- human joining with
// an Oxford-style final "&", used only in the routine card summary.
function awos_join_weekday_names(array $weekdays): string {
    $names = array_map(fn($w) => ADMIN_WORK_ROUTINE_WEEKDAY_NAMES[$w] ?? '?', $weekdays);
    $count = count($names);
    if ($count === 0) return '';
    if ($count === 1) return $names[0];
    $last = array_pop($names);
    return implode(', ', $names) . ' & ' . $last;
}

// Forces every schedule column not relevant to $frequency to null, so a
// frequency change (e.g. weekly -> daily) can never leave a stale hidden
// schedule_weekday sitting in storage.
function awos_normalize_routine_schedule(string $frequency, $weekday, $dayOfMonth, $month, $weekdaysJson, $anchorDate): array {
    $relevant = awos_routine_schedule_fields($frequency);
    return [
        in_array('schedule_weekday', $relevant, true) ? $weekday : null,
        in_array('schedule_day_of_month', $relevant, true) ? $dayOfMonth : null,
        in_array('schedule_month', $relevant, true) ? $month : null,
        in_array('schedule_weekdays', $relevant, true) ? $weekdaysJson : null,
        in_array('schedule_anchor_date', $relevant, true) ? $anchorDate : null,
    ];
}

// Human-readable summary shown on the routine's own card -- "configuring a
// responsibility," not reading raw schedule columns back.
function awos_routine_schedule_summary(array $r): string {
    $freq = $r['frequency'];
    switch ($freq) {
        case 'daily':
            return 'Every day';
        case 'weekdays':
            return 'Every weekday';
        case 'weekly':
            $wd = (int)($r['schedule_weekday'] ?? 0);
            return 'Every ' . (ADMIN_WORK_ROUTINE_WEEKDAY_NAMES[$wd] ?? '?');
        case 'biweekly':
            $wd = (int)($r['schedule_weekday'] ?? 0);
            $wdName = ADMIN_WORK_ROUTINE_WEEKDAY_NAMES[$wd] ?? '?';
            $anchor = (string)($r['schedule_anchor_date'] ?? '');
            $anchorLabel = '?';
            if ($anchor !== '') {
                $ts = strtotime($anchor . ' 00:00:00');
                if ($ts !== false) $anchorLabel = date('M j', $ts);
            }
            return "Every other {$wdName} starting {$anchorLabel}";
        case 'custom_weekdays':
            $days = awos_decode_weekdays($r['schedule_weekdays'] ?? null);
            return 'Every ' . awos_join_weekday_names($days);
        case 'monthly':
            $d = (int)($r['schedule_day_of_month'] ?? 0);
            $suffix = $d >= 29 ? ' (or the month\'s last day, for shorter months)' : '';
            return 'Monthly on the ' . awos_ordinal($d) . $suffix;
        case 'semiannual':
            $d = (int)($r['schedule_day_of_month'] ?? 0);
            $m1 = (int)($r['schedule_month'] ?? 0);
            $m2 = (($m1 + 6 - 1) % 12) + 1;
            $name1 = ADMIN_WORK_ROUTINE_MONTH_NAMES[$m1] ?? '?';
            $name2 = ADMIN_WORK_ROUTINE_MONTH_NAMES[$m2] ?? '?';
            return "Twice yearly on " . substr($name1, 0, 3) . " {$d} and " . substr($name2, 0, 3) . " {$d}";
        case 'annual':
            $d = (int)($r['schedule_day_of_month'] ?? 0);
            $m = (int)($r['schedule_month'] ?? 0);
            return 'Every year on ' . (ADMIN_WORK_ROUTINE_MONTH_NAMES[$m] ?? '?') . " {$d}";
        default:
            return awos_routine_frequency_label($freq);
    }
}

// ── V1D-B: generation ────────────────────────────────────────────────────────

// Given a target day that may not exist in a specific month (e.g. 31 in
// February), returns the actual YYYY-MM-DD to use -- the target day, or the
// month's real last day if shorter. Shared by monthly/semiannual/annual.
function awos_routine_effective_date(int $year, int $month, int $day): string {
    $lastDay = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
    return sprintf('%04d-%02d-%02d', $year, $month, min($day, $lastDay));
}

// Frequency-aware due check for one calendar day. $today is a plain
// YYYY-MM-DD (Eastern, per db.php's app-wide convention) -- the caller
// decides what "today" means; this function only judges whether $routine's
// schedule matches that specific date.
function routine_is_due_today(array $routine, string $today): bool {
    [$y, $m, $d] = array_map('intval', explode('-', $today));
    $isoWeekday = (int)date('N', strtotime($today . ' 00:00:00'));

    switch ($routine['frequency']) {
        case 'daily':
            return true;
        case 'weekdays':
            return $isoWeekday >= 1 && $isoWeekday <= 5;
        case 'weekly':
            return $isoWeekday === (int)$routine['schedule_weekday'];
        case 'biweekly':
            if ($isoWeekday !== (int)$routine['schedule_weekday']) return false;
            $anchor = (string)($routine['schedule_anchor_date'] ?? '');
            if ($anchor === '') return false;
            $anchorDt = new \DateTime($anchor . ' 00:00:00');
            $todayDt  = new \DateTime($today . ' 00:00:00');
            $diff = $anchorDt->diff($todayDt);
            // schedule_anchor_date is the first eligible occurrence -- a
            // biweekly routine must never be due before it. ->invert===1
            // means $today is before $anchorDt, which is always ineligible
            // regardless of day-count. Only once today is on or after the
            // anchor do we test the 14-day cycle via explicit day-count math
            // off that fixed anchor date -- never server week-number parity,
            // which can silently shift across year boundaries.
            if ($diff->invert === 1) return false;
            return ((int)$diff->days % 14) === 0;
        case 'custom_weekdays':
            $days = awos_decode_weekdays($routine['schedule_weekdays'] ?? null);
            return in_array($isoWeekday, $days, true);
        case 'monthly':
            return $today === awos_routine_effective_date($y, $m, (int)$routine['schedule_day_of_month']);
        case 'semiannual':
            $day    = (int)$routine['schedule_day_of_month'];
            $month1 = (int)$routine['schedule_month'];
            $month2 = (($month1 + 6 - 1) % 12) + 1; // same formula as awos_routine_schedule_summary()
            return $today === awos_routine_effective_date($y, $month1, $day)
                || $today === awos_routine_effective_date($y, $month2, $day);
        case 'annual':
            return $today === awos_routine_effective_date($y, (int)$routine['schedule_month'], (int)$routine['schedule_day_of_month']);
        default:
            return false;
    }
}

// Generates today's due, not-yet-generated work items for exactly one
// owner's enabled routines. Called once from admin_work_os.php, before its
// dashboard queries, so newly-created items are reflected in the same page
// load. Never called from admin_work_routines.php or anywhere else --
// generation has exactly one trigger point.
//
// No backfill: only ever evaluates $today against each routine's schedule.
// A day the owner never loaded the dashboard is simply never generated,
// today or later -- there is nothing here that looks backward.
function generate_due_routine_items(PDO $db, string $ownerEmail, string $today): void {
    $stmt = $db->prepare("SELECT * FROM admin_work_routines WHERE LOWER(owner_email)=? AND enabled=1");
    $stmt->execute([$ownerEmail]);
    $routines = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($routines as $routine) {
        if (!routine_is_due_today($routine, $today)) continue;

        // Fast-path skip -- avoids opening a transaction on the common case
        // of a second-or-later dashboard load the same day. Not itself the
        // duplicate guard (see below) -- just an optimization.
        $existsStmt = $db->prepare(
            "SELECT 1 FROM admin_work_routine_occurrences WHERE routine_id=? AND occurrence_key=?"
        );
        $existsStmt->execute([$routine['id'], $today]);
        if ($existsStmt->fetchColumn()) continue;

        $description = ($routine['description'] !== null && trim((string)$routine['description']) !== '')
            ? $routine['description'] : null;

        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO admin_work_items
                    (title, description, owner_email, created_by_email, category, status,
                     due_date, source_type, source_label, waiting_on, follow_up_date, completed_at)
                 VALUES (?, ?, ?, ?, ?, 'next', ?, 'recurring', ?, NULL, NULL, NULL)"
            )->execute([
                $routine['title'], $description, $ownerEmail, $ownerEmail, $routine['category'],
                $today, 'Routine: ' . $routine['title'],
            ]);
            $workItemId = (int)$db->lastInsertId();

            try {
                // routine_area_snapshot (V1F-A-1) is written here, once, in
                // this same transaction -- never recomputed from the routine
                // template afterward (see local_db.php's schema comment).
                // routine_id/occurrence_key -- the duplicate-prevention
                // primary key -- are unchanged from before this column existed.
                $db->prepare(
                    "INSERT INTO admin_work_routine_occurrences (routine_id, occurrence_key, work_item_id, routine_area_snapshot) VALUES (?, ?, ?, ?)"
                )->execute([$routine['id'], $today, $workItemId, $routine['routine_area']]);
            } catch (\PDOException $e) {
                // This is the ONLY statement in this transaction with a
                // uniqueness constraint -- the (routine_id, occurrence_key)
                // primary key on admin_work_routine_occurrences -- so
                // catching it at exactly this call site, not via a broad
                // catch around the whole transaction, is what lets us know
                // structurally (not by guessing from a message string) that
                // a 23000 here can only be that constraint: another
                // concurrent request already generated this exact
                // occurrence between our fast-path check above and now.
                // Roll back everything, including the work item we just
                // speculatively inserted, so no orphan duplicate remains.
                if ($e->getCode() === '23000') {
                    $db->rollBack();
                    continue;
                }
                throw $e; // some other integrity error at this exact statement -- do not swallow
            }

            $db->prepare(
                "INSERT INTO admin_work_item_events (item_id, event_type, detail, actor_email) VALUES (?, 'created', ?, ?)"
            )->execute([$workItemId, 'Generated from routine: ' . $routine['title'], $ownerEmail]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            // Not swallowed: propagates like any other unexpected DB failure
            // on this page (admin_work_os.php has no broader try/catch of
            // its own either -- a failed query elsewhere on this page would
            // surface the same way).
            throw $e;
        }
    }
}
