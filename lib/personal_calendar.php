<?php
// Shared ICS fetch + parse helpers for an agent's personal calendar
// (agent_extra.personal_cal_url — the existing per-agent ICS connection
// already used by calendar.php's "My Calendar" tab / api/personal_cal.php.
// No Google OAuth, no stored tokens: the agent pastes in their own "secret
// address" ICS URL and it is fetched live, same as before).
//
// Two consumers:
//  - api/personal_cal.php: month-grid view (date-only, no recurrence
//    expansion) — reuses only the generic fetch/split helpers below so its
//    existing behavior is unchanged.
//  - admin_work_os.php: "My Schedule" today panel (time-aware, with
//    minimal recurrence expansion).
//
// Deliberately minimal recurrence support (RFC 5545 subset): FREQ=DAILY and
// FREQ=WEEKLY only, with INTERVAL / BYDAY / UNTIL / COUNT. No monthly/yearly
// ordinal rules, no BYSETPOS, no WKST other than the RFC default (Monday).
// An unsupported RRULE is skipped for that one event only — it never
// throws and never breaks the rest of the schedule.

function pcal_fetch_ics(string $url, int $timeoutSeconds): ?string {
    $ctx = stream_context_create(['http' => [
        'timeout'       => $timeoutSeconds,
        'ignore_errors' => true,
        'user_agent'    => 'AgentEdge-CalSync/1.0',
    ]]);
    $ics = @file_get_contents($url, false, $ctx);
    return ($ics === false || $ics === '') ? null : $ics;
}

// Unfolds RFC 5545 line continuations and splits into raw VEVENT blocks.
// Identical to api/personal_cal.php's previous inline logic.
function pcal_split_vevents(string $ics): array {
    $ics = preg_replace("/\r\n[ \t]/", '', $ics);
    $ics = preg_replace("/\n[ \t]/", '', $ics);
    preg_match_all('/BEGIN:VEVENT(.+?)END:VEVENT/s', $ics, $m);
    return $m[1];
}

// Parses every occurrence of a "NAME(;params):value[,value...]" property
// line for $propName within a raw VEVENT block, honoring three cases per
// Agent Edge's timezone rules: a trailing "Z" (UTC, converted to
// $appTz), an explicit TZID parameter (respected, then converted to
// $appTz), or a bare/floating value (interpreted directly in $appTz —
// Agent Edge's existing America/New_York convention, per db.php).
function pcal_parse_dt_property(string $block, string $propName, DateTimeZone $appTz): array {
    $out = [];
    $pattern = '/^' . preg_quote($propName, '/') . '(;[^:\r\n]*)?:([^\r\n]+)/m';
    if (!preg_match_all($pattern, $block, $matches, PREG_SET_ORDER)) return $out;

    foreach ($matches as $m) {
        $params    = $m[1] ?? '';
        $rawValues = explode(',', trim($m[2]));

        $tzid = null;
        if (preg_match('/TZID=([^;:]+)/', $params, $tm)) $tzid = trim($tm[1]);
        $isDateValue = (bool)preg_match('/VALUE=DATE(?!-TIME)/', $params);

        foreach ($rawValues as $raw) {
            $raw = trim($raw);
            if ($raw === '') continue;
            $parsed = pcal_parse_dt_value($raw, $tzid, $isDateValue, $appTz);
            if ($parsed !== null) $out[] = $parsed;
        }
    }
    return $out;
}

// Parses one raw DATE or DATE-TIME value. Returns
// ['datetime' => DateTime|null, 'date_only' => 'Y-m-d', 'all_day' => bool]
// or null if the value doesn't match any recognized ICS date/datetime form.
function pcal_parse_dt_value(string $raw, ?string $tzid, bool $isDateValue, DateTimeZone $appTz): ?array {
    // All-day: bare 8-digit date, or an explicit VALUE=DATE parameter.
    if ($isDateValue || preg_match('/^\d{8}$/', $raw)) {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})/', $raw, $mm)) return null;
        if (!checkdate((int)$mm[2], (int)$mm[3], (int)$mm[1])) return null;
        $ymd = "{$mm[1]}-{$mm[2]}-{$mm[3]}";
        return ['datetime' => null, 'date_only' => $ymd, 'all_day' => true];
    }

    // UTC ("Z" suffix): parse as UTC, then convert to the app timezone.
    if (preg_match('/^(\d{8})T(\d{6})Z$/', $raw, $mm)) {
        $dt = DateTime::createFromFormat('Ymd\THis', $mm[1] . 'T' . $mm[2], new DateTimeZone('UTC'));
        if (!$dt) return null;
        $dt->setTimezone($appTz);
        return ['datetime' => $dt, 'date_only' => $dt->format('Y-m-d'), 'all_day' => false];
    }

    // Explicit TZID: interpret in that zone, then convert to the app
    // timezone so all recurrence math and rendering share one clock.
    if ($tzid !== null && preg_match('/^(\d{8})T(\d{6})$/', $raw, $mm)) {
        try { $srcTz = new DateTimeZone($tzid); } catch (\Throwable $e) { $srcTz = $appTz; }
        $dt = DateTime::createFromFormat('Ymd\THis', $mm[1] . 'T' . $mm[2], $srcTz);
        if (!$dt) return null;
        $dt->setTimezone($appTz);
        return ['datetime' => $dt, 'date_only' => $dt->format('Y-m-d'), 'all_day' => false];
    }

    // Floating/bare datetime: interpret directly in the app's own
    // convention (America/New_York), per the V1E timezone rules.
    if (preg_match('/^(\d{8})T(\d{6})$/', $raw, $mm)) {
        $dt = DateTime::createFromFormat('Ymd\THis', $mm[1] . 'T' . $mm[2], $appTz);
        if (!$dt) return null;
        return ['datetime' => $dt, 'date_only' => $dt->format('Y-m-d'), 'all_day' => false];
    }

    return null; // unrecognized value shape
}

// Parses one raw VEVENT block into a structured, timezone-normalized
// event. Returns null if it has no usable DTSTART or SUMMARY (mirrors
// api/personal_cal.php's existing "skip if no title" behavior).
function pcal_parse_vevent(string $block, DateTimeZone $appTz): ?array {
    $dtstartMatches = pcal_parse_dt_property($block, 'DTSTART', $appTz);
    if (empty($dtstartMatches)) return null;
    $dtstart = $dtstartMatches[0];

    $title = '';
    if (preg_match('/^SUMMARY:(.+)/m', $block, $mm)) {
        $title = trim(str_replace(['\,', '\;', '\\n', '\\N'], [',', ';', ' ', ' '], $mm[1]));
    }
    if ($title === '') return null;

    $location = '';
    if (preg_match('/^LOCATION:(.+)/m', $block, $mm)) {
        $location = trim(str_replace(['\,', '\;', '\\n', '\\N'], [',', ';', ' ', ' '], $mm[1]));
    }

    $dtendMatches = pcal_parse_dt_property($block, 'DTEND', $appTz);
    $dtend = $dtendMatches[0] ?? null;

    $uid = '';
    if (preg_match('/^UID:(.+)/m', $block, $mm)) $uid = trim($mm[1]);

    $recurrenceId = null;
    $ridMatches = pcal_parse_dt_property($block, 'RECURRENCE-ID', $appTz);
    if (!empty($ridMatches)) $recurrenceId = $ridMatches[0]['date_only'];

    $rrule = null;
    if (preg_match('/^RRULE:(.+)/m', $block, $mm)) $rrule = trim($mm[1]);

    $exdates = [];
    foreach (pcal_parse_dt_property($block, 'EXDATE', $appTz) as $ex) {
        $exdates[] = $ex['date_only'];
    }

    $cancelled = false;
    if (preg_match('/^STATUS:(.+)/m', $block, $mm) && strtoupper(trim($mm[1])) === 'CANCELLED') {
        $cancelled = true;
    }

    return [
        'uid'           => $uid,
        'title'         => $title,
        'location'      => $location,
        'all_day'       => $dtstart['all_day'],
        'start_date'    => $dtstart['date_only'],
        'start_time'    => $dtstart['all_day'] ? null : $dtstart['datetime']->format('H:i'),
        'start_label'   => $dtstart['all_day'] ? null : $dtstart['datetime']->format('g:i A'),
        'end_date'      => $dtend['date_only'] ?? null,
        'end_time'      => ($dtend && !$dtend['all_day']) ? $dtend['datetime']->format('H:i') : null,
        'end_label'     => ($dtend && !$dtend['all_day']) ? $dtend['datetime']->format('g:i A') : null,
        'rrule'         => $rrule,
        'exdates'       => $exdates,
        'recurrence_id' => $recurrenceId,
        'cancelled'     => $cancelled,
    ];
}

// Signed day count from $fromYmd to $toYmd (b - a), same DateTime::diff() +
// ->invert technique already established for admin_work_routines.php's
// biweekly anchor-floor logic.
function pcal_days_between(string $fromYmd, string $toYmd): int {
    $diff = (new DateTime($fromYmd))->diff(new DateTime($toYmd));
    $days = (int)$diff->days;
    return $diff->invert === 1 ? -$days : $days;
}

function pcal_parse_rrule(string $raw): array {
    $out = [];
    foreach (explode(';', $raw) as $pair) {
        if (strpos($pair, '=') === false) continue;
        [$k, $v] = explode('=', $pair, 2);
        $out[strtoupper(trim($k))] = trim($v);
    }
    return $out;
}

// Returns a normalized list of two-letter weekday codes, or null if any
// token is ordinal-qualified (e.g. "2MO", used for monthly-style rules)
// or otherwise not one of the seven plain weekday codes -- deliberately
// unsupported per V1E's minimal-recurrence scope.
function pcal_parse_byday(string $raw): ?array {
    $codes = [];
    foreach (explode(',', $raw) as $tok) {
        $tok = strtoupper(trim($tok));
        if (!preg_match('/^(MO|TU|WE|TH|FR|SA|SU)$/', $tok)) return null;
        $codes[] = $tok;
    }
    return $codes ?: null;
}

const PCAL_ISO_WEEKDAY_CODES = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];

// UNTIL may be a bare date or a UTC/floating datetime -- only the calendar
// date matters for this minimal, day-level implementation.
function pcal_parse_rrule_until(string $raw): ?string {
    if (preg_match('/^(\d{4})(\d{2})(\d{2})T\d{6}Z?$/', $raw, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
    return null;
}

// Day-level cycle test shared by the plain-weekly case, the BYDAY case,
// and COUNT enumeration: does $dayYmd fall on the FREQ=WEEKLY/INTERVAL/
// BYDAY cycle anchored at $startYmd, with no COUNT/UNTIL applied yet.
// Weeks are Monday-start (WKST is not supported -- documented limitation).
function pcal_weekly_cycle_matches(string $startYmd, string $dayYmd, int $interval, ?array $byday): bool {
    if ($dayYmd < $startYmd) return false;

    if ($byday === null) {
        $daysDiff = pcal_days_between($startYmd, $dayYmd);
        if ($daysDiff % 7 !== 0) return false;
        return (intdiv($daysDiff, 7) % $interval) === 0;
    }

    $dayCode = PCAL_ISO_WEEKDAY_CODES[(int)(new DateTime($dayYmd))->format('N')];
    if (!in_array($dayCode, $byday, true)) return false;

    $startIso = (int)(new DateTime($startYmd))->format('N');
    $dayIso   = (int)(new DateTime($dayYmd))->format('N');
    $mondayOfStartWeek = (new DateTime($startYmd))->modify('-' . ($startIso - 1) . ' days')->format('Y-m-d');
    $mondayOfDayWeek   = (new DateTime($dayYmd))->modify('-' . ($dayIso - 1) . ' days')->format('Y-m-d');
    $weekDaysDiff = pcal_days_between($mondayOfStartWeek, $mondayOfDayWeek);
    if ($weekDaysDiff < 0 || $weekDaysDiff % 7 !== 0) return false;
    return (intdiv($weekDaysDiff, 7) % $interval) === 0;
}

// Tri-state: true (due today), false (not due today), null (this RRULE
// uses a construct outside V1E's minimal support -- caller must skip this
// event gracefully rather than guess).
function pcal_rrule_occurs_on(array $vevent, string $today): ?bool {
    $start = $vevent['start_date'];
    if ($today < $start) return false; // never generate before DTSTART

    $rr   = pcal_parse_rrule((string)$vevent['rrule']);
    $freq = $rr['FREQ'] ?? '';
    if ($freq !== 'DAILY' && $freq !== 'WEEKLY') return null;

    $interval = 1;
    if (isset($rr['INTERVAL'])) {
        if (!ctype_digit($rr['INTERVAL']) || (int)$rr['INTERVAL'] < 1) return null;
        $interval = (int)$rr['INTERVAL'];
    }

    if (isset($rr['UNTIL'])) {
        $until = pcal_parse_rrule_until($rr['UNTIL']);
        if ($until === null) return null;
        if ($today > $until) return false;
    }

    $count = null;
    if (isset($rr['COUNT'])) {
        if (!ctype_digit($rr['COUNT']) || (int)$rr['COUNT'] < 1) return null;
        $count = (int)$rr['COUNT'];
    }

    if ($freq === 'DAILY') {
        $daysDiff = pcal_days_between($start, $today);
        if ($daysDiff % $interval !== 0) return false;
        if ($count !== null && intdiv($daysDiff, $interval) >= $count) return false;
        return true;
    }

    // WEEKLY
    $byday = null;
    if (isset($rr['BYDAY'])) {
        $byday = pcal_parse_byday($rr['BYDAY']);
        if ($byday === null) return null; // ordinal-qualified/malformed -> unsupported
    }

    if (!pcal_weekly_cycle_matches($start, $today, $interval, $byday)) return false;

    if ($count !== null) {
        // COUNT needs an occurrence index. A brute-force day walk from
        // DTSTART is the simplest correct approach for BYDAY (multiple
        // weekdays per cycle) and stays cheap -- personal-calendar
        // recurrences are bounded to ordinary human timeframes. Capped
        // defensively against a pathological multi-year-old anchor.
        $span = pcal_days_between($start, $today);
        if ($span > 3660) return null; // ~10 years -- treat as unsupported rather than guess
        $index = 0;
        $cursor = $start;
        while ($cursor < $today) {
            if (pcal_weekly_cycle_matches($start, $cursor, $interval, $byday)) $index++;
            $cursor = (new DateTime($cursor))->modify('+1 day')->format('Y-m-d');
        }
        if ($index >= $count) return false;
    }

    return true;
}

// Top-level entry point for "My Schedule". Always returns a safe,
// structured result -- never throws, never exposes the feed URL, raw
// upstream content, or a parser exception. $email must be session-derived
// by the caller (never client-supplied).
//
// Returns ['state' => 'not_connected'|'error'|'ok', 'events' => [...]].
// Each event (state=ok only) has: title, location, all_day, start_time
// ('H:i' or null), start_label ('g:i A' or null), end_time, end_label.
function pcal_get_today_schedule(string $email, string $today): array {
    try {
        $db   = local_db();
        $stmt = $db->prepare("SELECT personal_cal_url FROM agent_extra WHERE email=?");
        $stmt->execute([$email]);
        $url = (string)($stmt->fetchColumn() ?: '');
    } catch (\Throwable $e) {
        return ['state' => 'error', 'events' => []];
    }

    if ($url === '') return ['state' => 'not_connected', 'events' => []];

    try {
        $ics = pcal_fetch_ics($url, 3);
        if ($ics === null) return ['state' => 'error', 'events' => []];

        $appTz  = new DateTimeZone('America/New_York');
        $blocks = pcal_split_vevents($ics);

        $vevents = [];
        foreach ($blocks as $block) {
            try {
                $v = pcal_parse_vevent($block, $appTz);
            } catch (\Throwable $e) {
                $v = null; // one malformed VEVENT must never break the rest
            }
            if ($v !== null) $vevents[] = $v;
        }

        // UID -> set of RECURRENCE-ID dates that have an override VEVENT,
        // so a recurring master's own expansion can suppress those dates
        // (a moved or cancelled single occurrence must not also show at
        // its original computed slot).
        $overriddenDates = [];
        foreach ($vevents as $v) {
            if ($v['recurrence_id'] !== null && $v['uid'] !== '') {
                $overriddenDates[$v['uid']][$v['recurrence_id']] = true;
            }
        }

        $todayEvents = [];
        foreach ($vevents as $v) {
            if ($v['cancelled']) continue;

            if ($v['recurrence_id'] !== null) {
                // An override instance -- its own DTSTART is already the
                // new time, so treat it like any plain single event.
                if ($v['start_date'] === $today) $todayEvents[] = $v;
                continue;
            }

            if ($v['rrule'] === null) {
                if ($v['start_date'] === $today) $todayEvents[] = $v;
                continue;
            }

            // Recurring master.
            if (in_array($today, $v['exdates'], true)) continue;
            if (!empty($overriddenDates[$v['uid']][$today])) continue;

            $matches = pcal_rrule_occurs_on($v, $today);
            if ($matches === true) $todayEvents[] = $v;
            // false -> not due today; null -> unsupported RRULE, skip this
            // one event gracefully -- never throws, never breaks the rest.
        }

        usort($todayEvents, function ($a, $b) {
            if ($a['all_day'] !== $b['all_day']) return $a['all_day'] ? -1 : 1;
            return strcmp($a['start_time'] ?? '00:00', $b['start_time'] ?? '00:00');
        });

        return ['state' => 'ok', 'events' => $todayEvents];
    } catch (\Throwable $e) {
        return ['state' => 'error', 'events' => []];
    }
}
