<?php
// Coach Dashboard production calculation layer — Step 4.
//
// Reads ONLY AgentEdge's local SQLite mirror (innovate_roster,
// coastline_agent_production_stats, coastline_closed_sides). No HTTP calls,
// no writes, no Perfex, no Pending, no GCI. lib/coastline.php owns fetching
// and mirroring; this file owns turning the mirrored rows into the numbers
// the Coach Dashboard needs. Keep HTTP/JSON response concerns (auth,
// request parsing, echo/json_encode) entirely out of this file — every
// function here returns a plain PHP array.
//
// IDENTITY: production identity is innovate_roster.canonical_agent_id +
// innovate_roster.id_match_status = 'matched'. Never fuzzy/name/phone/
// approximate-email matching — see backoffice_roster.php's lookupProd() for
// the anti-pattern this deliberately avoids. A roster row can carry a
// non-null canonical_agent_id while id_match_status is 'partial'/'conflict'
// (a candidate recorded for audit, not a confirmed match) — both fields must
// be checked together, never canonical_agent_id alone.
//
// THREE PRODUCTION STATES (see coach_production_status()):
//   matched_with_production — matched identity, Coastline has a stats row
//   matched_no_production   — matched identity, Coastline has NO stats row
//                              (a real, legitimate zero — not "unavailable")
//   unmatched                — identity not reliably matched (partial/
//                              conflict/unmatched) — production is null,
//                              never zero, never name-matched as a fallback
//   mirror_unavailable       — the local mirror itself is empty/uninitialized,
//                              so an absent stats row cannot be trusted as a
//                              real zero yet (see coach_mirror_health())
//
// CALENDAR-MONTH SEMANTICS: mirrors coastline-server's own
// _subtract_calendar_months (day-of-month held constant, clamped to the
// target month's last day) — the same arithmetic already verified against
// Coastline's own volume_12mo/volume_prior_12mo cutoffs in
// lib/coastline.php. Deliberately duplicated here (not required from
// lib/coastline.php) to keep this file dependency-free of the HTTP-sync
// file; NOT a 30-day-per-month approximation, which drifts.

// date - N calendar months. See lib/coastline.php's coastline_subtract_
// calendar_months() for the verified original; kept in sync deliberately.
function coach_subtract_calendar_months(\DateTimeImmutable $d, int $months): \DateTimeImmutable {
    $year  = (int)$d->format('Y');
    $month = (int)$d->format('n');
    $day   = (int)$d->format('j');

    $monthIndex = $month - 1 - $months;
    $year  += (int)floor($monthIndex / 12);
    $rem    = $monthIndex % 12;
    if ($rem < 0) { $rem += 12; }
    $month  = $rem + 1;

    $lastDay = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    $day = min($day, $lastDay);
    return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

// ── Mirror health ────────────────────────────────────────────────────────────

// Distinguishes "the mirror is healthy and an absent row is a real zero"
// from "the mirror hasn't been synced yet, so we don't actually know." No
// arbitrary freshness cutoff (e.g. "must be < 24h old") is applied yet —
// deliberately, per Step 4 scope — this only checks whether the tables have
// ever been populated at all.
//
// Step 4.1: coverage MUST be judged against the date range the sync actually
// REQUESTED from Coastline (coastline_sync_meta.source_window_*), never
// against MIN/MAX(close_date) of whatever rows happened to come back. A lull
// in real closings near the end of the requested window does not mean that
// tail of the window is missing data, and MIN(close_date) is never
// guaranteed to equal the requested window start either. MIN/MAX(close_date)
// is still returned, under 'observed_data', purely as an informational
// diagnostic — it must never feed a completeness decision.
function coach_mirror_health(\PDO $db): array {
    $aggRows = (int)$db->query("SELECT COUNT(*) FROM coastline_agent_production_stats")->fetchColumn();
    $csRows  = (int)$db->query("SELECT COUNT(*) FROM coastline_closed_sides")->fetchColumn();
    $observedRange = $db->query("SELECT MIN(close_date) AS mn, MAX(close_date) AS mx FROM coastline_closed_sides")->fetch(\PDO::FETCH_ASSOC);
    $meta = $db->query("SELECT source_window_start, source_window_end, synced_at
        FROM coastline_sync_meta WHERE id = 1")->fetch(\PDO::FETCH_ASSOC);

    // Fall back to the aggregate table's own synced_at only if a successful
    // sync has never populated coastline_sync_meta yet (e.g. mirror rows from
    // before this metadata table existed) — coverage stays null either way,
    // deliberately, until a real sync populates the source window.
    $syncedAt = $meta['synced_at'] ?? ($db->query("SELECT MAX(synced_at) FROM coastline_agent_production_stats")->fetchColumn() ?: null);

    return [
        'mirror_status' => [
            'healthy'             => $aggRows > 0 && $csRows > 0,
            'synced_at'           => $syncedAt,
            'aggregate_rows'      => $aggRows,
            'closed_side_rows'    => $csRows,
        ],
        'coverage' => [
            'available_start' => $meta['source_window_start'] ?? null,
            'available_end'   => $meta['source_window_end'] ?? null,
        ],
        'observed_data' => [
            'earliest_close_date' => $observedRange['mn'] ?? null,
            'latest_close_date'   => $observedRange['mx'] ?? null,
        ],
    ];
}

// Whether a requested [start, end] date range is fully inside what the local
// mirror actually covers. Uses coverage.available_start/available_end (the
// persisted Coastline source window) — NEVER observed_data's MIN/MAX(close_date)
// — so a real lull in closings near "today" does not get misreported as
// missing coverage. Never silently clamps — callers must surface
// complete=false rather than presenting a partial total as a full one. A
// period requesting dates the sync never asked Coastline for (before the
// source window started, or a window that's never been synced at all) also
// correctly comes back incomplete.
// $mirrorHealth: pass an already-computed coach_mirror_health() result to
// avoid re-querying it (e.g. once per HTTP request, reused across several
// helper calls) — omit it and this computes its own, so the function stays
// usable standalone/in isolation (tests, CLI, etc.).
function coach_check_coverage(\PDO $db, string $start, string $end, ?array $mirrorHealth = null): array {
    $health = $mirrorHealth ?? coach_mirror_health($db);
    $availStart = $health['coverage']['available_start'];
    $availEnd   = $health['coverage']['available_end'];
    $complete = $health['mirror_status']['healthy'] && $availStart !== null && $availEnd !== null
        && $start >= $availStart && $end <= $availEnd;

    return [
        'complete'         => $complete,
        'requested_start'  => $start,
        'requested_end'    => $end,
        'available_start'  => $availStart,
        'available_end'    => $availEnd,
    ];
}

// ── Period normalization ─────────────────────────────────────────────────────

// $type: 'ltm' | 'ytd' | 'year' | 'custom'. $opts: ['year'=>int] for 'year',
// ['start'=>'Y-m-d','end'=>'Y-m-d'] for 'custom'. Returns ['type','start','end'].
function coach_normalize_period(string $type, array $opts = [], ?\DateTimeImmutable $today = null): array {
    $today = $today ?? new \DateTimeImmutable('today');

    switch ($type) {
        case 'ltm':
            $start = coach_subtract_calendar_months($today, 12)->format('Y-m-d');
            $end   = $today->format('Y-m-d');
            break;
        case 'ytd':
            $start = $today->format('Y-01-01');
            $end   = $today->format('Y-m-d');
            break;
        case 'year':
            $year = (int)($opts['year'] ?? $today->format('Y'));
            $start = sprintf('%04d-01-01', $year);
            $end   = sprintf('%04d-12-31', $year);
            break;
        case 'custom':
            $start = (string)($opts['start'] ?? '');
            $end   = (string)($opts['end'] ?? '');
            if ($start === '' || $end === '') {
                throw new \InvalidArgumentException("custom period requires 'start' and 'end'");
            }
            if ($start > $end) {
                throw new \InvalidArgumentException("custom period start ({$start}) is after end ({$end})");
            }
            break;
        default:
            throw new \InvalidArgumentException("unknown period type '{$type}'");
    }

    return ['type' => $type, 'start' => $start, 'end' => $end];
}

// ── Identity / roster ────────────────────────────────────────────────────────

function coach_roster_row(\PDO $db, int $rosterId): ?array {
    $stmt = $db->prepare("SELECT id, agent_name, email, active, canonical_agent_id, id_match_status
        FROM innovate_roster WHERE id = ?");
    $stmt->execute([$rosterId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Never true unless BOTH a non-empty canonical_agent_id AND
// id_match_status='matched' are present — a roster row can carry a
// candidate canonical_agent_id while sitting at 'partial'/'conflict', which
// must NOT be treated as matched.
function coach_is_matched(array $rosterRow): bool {
    return $rosterRow['id_match_status'] === 'matched'
        && !empty($rosterRow['canonical_agent_id']);
}

function coach_agent_aggregate_row(\PDO $db, string $canonicalId): ?array {
    $stmt = $db->prepare("SELECT * FROM coastline_agent_production_stats WHERE canonical_agent_id = ?");
    $stmt->execute([$canonicalId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

// One of: matched_with_production | matched_no_production | unmatched | mirror_unavailable
function coach_production_status(array $rosterRow, bool $hasAggregateRow, array $mirrorHealth): string {
    if (!coach_is_matched($rosterRow)) return 'unmatched';
    if ($hasAggregateRow) return 'matched_with_production';
    if (!$mirrorHealth['mirror_status']['healthy']) return 'mirror_unavailable';
    return 'matched_no_production';
}

// ── Closed-side metrics over an arbitrary date range ────────────────────────

// transactions = COUNT DISTINCT (mls_source, listing_key) — one deal, however
// many credited roles the agent has on it. sides = COUNT of credited role
// rows (Coastline's own full-credit-per-role convention, preserved as-is —
// never deduplicated, so this always agrees with Coastline). average_sale_price
// = volume / sides (verified against Coastline: for Alysia Stern,
// volume_12mo 24,064,838 / sides_12mo 59 = 407,878.61..., matching Coastline's
// own avg_sale_price_12mo of 407878.6101694915 exactly) — i.e. the mean
// close_price across credited-role rows, the same denominator Coastline uses,
// not a per-transaction average.
function coach_agent_period_closed(\PDO $db, string $canonicalId, string $start, string $end): array {
    $stmt = $db->prepare("
        SELECT
            COUNT(*) AS sides,
            COUNT(DISTINCT mls_source || char(31) || listing_key) AS transactions,
            SUM(close_price) AS volume
        FROM coastline_closed_sides
        WHERE canonical_agent_id = ? AND close_date BETWEEN ? AND ?
    ");
    $stmt->execute([$canonicalId, $start, $end]);
    $r = $stmt->fetch(\PDO::FETCH_ASSOC);

    $sides = (int)($r['sides'] ?? 0);
    $volume = $r['volume'] !== null ? (float)$r['volume'] : 0.0;
    $avg = $sides > 0 ? $volume / $sides : null;

    return [
        'transactions'        => (int)($r['transactions'] ?? 0),
        'sides'               => $sides,
        'volume'              => $volume,
        'average_sale_price'  => $avg,
    ];
}

// Transaction count within Coastline's own LTM cutoff — supplements the
// canonical volume_12mo/sides_12mo fields (which have no transaction-count
// equivalent) without recomputing volume/sides locally, so AgentEdge never
// disagrees with Coastline on those two.
function coach_agent_ltm_transactions(\PDO $db, string $canonicalId, ?\DateTimeImmutable $today = null): int {
    $today = $today ?? new \DateTimeImmutable('today');
    $start = coach_subtract_calendar_months($today, 12)->format('Y-m-d');
    $end   = $today->format('Y-m-d');
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT mls_source || char(31) || listing_key)
        FROM coastline_closed_sides WHERE canonical_agent_id = ? AND close_date BETWEEN ? AND ?
    ");
    $stmt->execute([$canonicalId, $start, $end]);
    return (int)$stmt->fetchColumn();
}

// ── Selected-agent production (single-agent detail) ─────────────────────────

// Main per-agent entry point. $periodType/$periodOpts per coach_normalize_period().
// Active listings are ALWAYS current-state (from the aggregate row directly),
// regardless of the selected period, per spec. $mirrorHealth: pass an
// already-computed coach_mirror_health() result to avoid re-querying it when
// this is one of several helper calls in the same request — omit it and this
// computes its own, so the function stays usable standalone.
function coach_selected_agent_production(\PDO $db, int $rosterId, string $periodType, array $periodOpts = [], ?\DateTimeImmutable $today = null, ?array $mirrorHealth = null): array {
    $roster = coach_roster_row($db, $rosterId);
    if (!$roster) {
        throw new \InvalidArgumentException("no roster row with id {$rosterId}");
    }
    $health = $mirrorHealth ?? coach_mirror_health($db);
    $matched = coach_is_matched($roster);
    $canonicalId = $matched ? $roster['canonical_agent_id'] : null;
    $agg = $canonicalId ? coach_agent_aggregate_row($db, $canonicalId) : null;
    $status = coach_production_status($roster, $agg !== null, $health);

    $period = coach_normalize_period($periodType, $periodOpts, $today);
    $coverage = coach_check_coverage($db, $period['start'], $period['end'], $health);

    $result = [
        'roster_id'          => $roster['id'],
        'agent_name'         => $roster['agent_name'],
        'canonical_agent_id' => $roster['canonical_agent_id'],
        'identity_status'    => $roster['id_match_status'],
        'production_status'  => $status,
        'mirror_health'      => $health,
        'period'             => $period,
        'coverage'           => $coverage,
        'closed'             => null,
        'active_listings'    => null,
        'ltm_extra'          => null,
    ];

    if ($status === 'unmatched' || $status === 'mirror_unavailable') {
        return $result; // closed/active_listings/ltm_extra stay null — never zero, never guessed
    }

    if ($status === 'matched_no_production') {
        // A real, legitimate zero — not "unavailable".
        $result['closed'] = ['transactions' => 0, 'sides' => 0, 'volume' => 0.0, 'average_sale_price' => null];
        $result['active_listings'] = ['count' => 0, 'volume' => 0.0];
        if ($periodType === 'ltm') {
            $result['ltm_extra'] = [
                'list_side_12mo' => 0, 'buy_side_12mo' => 0,
                'volume_prior_12mo' => 0.0, 'sides_prior_12mo' => 0, 'trend_pct_12mo' => null, 'last_close_date' => null,
            ];
        }
        return $result;
    }

    // matched_with_production
    if ($periodType === 'ltm') {
        // Canonical fields straight from Coastline's own aggregate — never recomputed.
        $result['closed'] = [
            'transactions'       => coach_agent_ltm_transactions($db, $canonicalId, $today),
            'sides'              => (int)$agg['sides_12mo'],
            'volume'             => (float)$agg['volume_12mo'],
            'average_sale_price' => $agg['avg_sale_price_12mo'] !== null ? (float)$agg['avg_sale_price_12mo'] : null,
        ];
        $result['ltm_extra'] = [
            'list_side_12mo'     => (int)$agg['list_side_12mo'],
            'buy_side_12mo'      => (int)$agg['buy_side_12mo'],
            'volume_prior_12mo'  => (float)$agg['volume_prior_12mo'],
            'sides_prior_12mo'   => (int)$agg['sides_prior_12mo'],
            'trend_pct_12mo'     => $agg['trend_pct_12mo'] !== null ? (float)$agg['trend_pct_12mo'] : null,
            'last_close_date'    => $agg['last_close_date'],
        ];
    } else {
        $result['closed'] = coach_agent_period_closed($db, $canonicalId, $period['start'], $period['end']);
    }

    $result['active_listings'] = [
        'count'  => (int)$agg['active_listing_count'],
        'volume' => (float)$agg['active_listing_volume'],
    ];

    return $result;
}

// ── MTD comparison ───────────────────────────────────────────────────────────

// current MTD: first of this month -> today. prior-year MTD: first of the
// same month last year -> the equivalent calendar day last year (computed as
// today minus exactly 12 calendar months, so Feb 29 -> Feb 28 in a
// non-leap prior year, matching coach_subtract_calendar_months' own clamp —
// never a raw "same day number" construction that could throw on Feb 30/31).
// $mirrorHealth: pass an already-computed coach_mirror_health() result to
// avoid re-querying it when composing this with other helper calls in the
// same request — omit it and this computes its own.
function coach_mtd_comparison(\PDO $db, int $rosterId, ?\DateTimeImmutable $today = null, ?array $mirrorHealth = null): array {
    $today = $today ?? new \DateTimeImmutable('today');
    $roster = coach_roster_row($db, $rosterId);
    if (!$roster) {
        throw new \InvalidArgumentException("no roster row with id {$rosterId}");
    }
    $health = $mirrorHealth ?? coach_mirror_health($db);
    $matched = coach_is_matched($roster);
    $canonicalId = $matched ? $roster['canonical_agent_id'] : null;
    $agg = $canonicalId ? coach_agent_aggregate_row($db, $canonicalId) : null;
    $status = coach_production_status($roster, $agg !== null, $health);

    $currentStart = $today->format('Y-m-01');
    $currentEnd   = $today->format('Y-m-d');
    $priorEnd     = coach_subtract_calendar_months($today, 12); // same month last year, equivalent day
    $priorStart   = $priorEnd->format('Y-m-01');
    $priorEndStr  = $priorEnd->format('Y-m-d');

    $result = [
        'roster_id'          => $roster['id'],
        'agent_name'         => $roster['agent_name'],
        'canonical_agent_id' => $roster['canonical_agent_id'],
        'identity_status'    => $roster['id_match_status'],
        'production_status'  => $status,
        'current'            => ['start' => $currentStart, 'end' => $currentEnd, 'transactions' => null, 'sides' => null, 'volume' => null, 'average_sale_price' => null],
        'prior_year'         => ['start' => $priorStart, 'end' => $priorEndStr, 'transactions' => null, 'sides' => null, 'volume' => null, 'average_sale_price' => null],
    ];

    if ($status === 'unmatched' || $status === 'mirror_unavailable') {
        return $result;
    }
    if ($status === 'matched_no_production') {
        $zero = ['transactions' => 0, 'sides' => 0, 'volume' => 0.0, 'average_sale_price' => null];
        $result['current']    = array_merge(['start' => $currentStart, 'end' => $currentEnd], $zero);
        $result['prior_year'] = array_merge(['start' => $priorStart, 'end' => $priorEndStr], $zero);
        return $result;
    }

    $result['current']    = array_merge(['start' => $currentStart, 'end' => $currentEnd], coach_agent_period_closed($db, $canonicalId, $currentStart, $currentEnd));
    $result['prior_year'] = array_merge(['start' => $priorStart, 'end' => $priorEndStr], coach_agent_period_closed($db, $canonicalId, $priorStart, $priorEndStr));
    return $result;
}

// ── Bulk roster production (LTM grid — the Coach Dashboard's Agent Row) ────

// Exactly 2 SQL queries of its own regardless of roster size (plus whatever
// coach_mirror_health() costs if not supplied): one for the active roster,
// one for ALL aggregate rows, joined in PHP. No query is issued inside the
// per-agent loop below — verified by inspection (grep for "->query(" /
// "->prepare(" inside this function finds only the two calls before the loop).
// $mirrorHealth: pass an already-computed coach_mirror_health() result to
// avoid re-querying it when composing this with other helper calls in the
// same request — omit it and this computes its own.
function coach_bulk_roster_production(\PDO $db, ?array $mirrorHealth = null): array {
    $health = $mirrorHealth ?? coach_mirror_health($db);

    $roster = $db->query("SELECT id, agent_name, email, canonical_agent_id, id_match_status
        FROM innovate_roster WHERE active = 1")->fetchAll(\PDO::FETCH_ASSOC);

    $aggByAgent = [];
    foreach ($db->query("SELECT * FROM coastline_agent_production_stats") as $row) {
        $aggByAgent[$row['canonical_agent_id']] = $row;
    }

    $out = [];
    foreach ($roster as $r) {
        $matched = coach_is_matched($r);
        $canonicalId = $matched ? $r['canonical_agent_id'] : null;
        $agg = $canonicalId !== null ? ($aggByAgent[$canonicalId] ?? null) : null;
        $status = coach_production_status($r, $agg !== null, $health);

        $row = [
            'roster_id'          => $r['id'],
            'agent_name'         => $r['agent_name'],
            'email'              => strtolower(trim((string)$r['email'])),
            'canonical_agent_id' => $r['canonical_agent_id'],
            'identity_status'    => $r['id_match_status'],
            'production_status'  => $status,
            'ltm_volume'         => null,
            'ltm_sides'          => null,
            'list_sides'         => null,
            'buy_sides'          => null,
            'trend_pct'          => null,
            'last_close_date'    => null,
        ];

        if ($status === 'matched_with_production') {
            $row['ltm_volume']      = (float)$agg['volume_12mo'];
            $row['ltm_sides']       = (int)$agg['sides_12mo'];
            $row['list_sides']      = (int)$agg['list_side_12mo'];
            $row['buy_sides']       = (int)$agg['buy_side_12mo'];
            $row['trend_pct']       = $agg['trend_pct_12mo'] !== null ? (float)$agg['trend_pct_12mo'] : null;
            $row['last_close_date'] = $agg['last_close_date'];
        } elseif ($status === 'matched_no_production') {
            $row['ltm_volume']  = 0.0;
            $row['ltm_sides']   = 0;
            $row['list_sides']  = 0;
            $row['buy_sides']   = 0;
            $row['trend_pct']   = null; // undefined, not zero
            $row['last_close_date'] = null;
        }
        // unmatched / mirror_unavailable: all production fields stay null

        $out[] = $row;
    }

    // Reconciliation metadata: explains, without exposing any IDs, why
    // company-wide totals (coach_company_*) will never equal the sum of
    // these rows — some canonical agents with real Coastline production
    // simply aren't reconciled to an AgentEdge roster row yet.
    $statusCounts = ['matched_with_production' => 0, 'matched_no_production' => 0, 'unmatched' => 0, 'mirror_unavailable' => 0];
    foreach ($out as $r) { $statusCounts[$r['production_status']] = ($statusCounts[$r['production_status']] ?? 0) + 1; }

    return [
        'mirror_health'         => $health,
        'agents'                => $out,
        'active_roster_count'   => count($out),
        'matched_roster_count'  => $statusCounts['matched_with_production'] + $statusCounts['matched_no_production'],
        'unmatched_roster_count'=> $statusCounts['unmatched'] + $statusCounts['mirror_unavailable'],
    ];
}

// ── Chart: Closed Volume by Month ────────────────────────────────────────────

// current_12mo: the 12 calendar months ending with the current month.
// prior_12mo: the SAME 12 calendar months, exactly one year earlier — so
// bucket i in each array is the same month-of-year, enabling a direct
// year-over-year paired comparison (this is the "prior-year comparison"
// reading of the spec, not a plain second-half-of-24-months split). Zero
// months are included explicitly; ordering is oldest -> newest in both arrays.
function coach_agent_volume_chart(\PDO $db, int $rosterId, ?\DateTimeImmutable $today = null): array {
    $today = $today ?? new \DateTimeImmutable('today');
    $roster = coach_roster_row($db, $rosterId);
    if (!$roster) {
        throw new \InvalidArgumentException("no roster row with id {$rosterId}");
    }
    $matched = coach_is_matched($roster);

    $months = [];      // 24 [year,month] pairs, oldest first: index 0..11 = prior, 12..23 = current
    for ($i = 23; $i >= 0; $i--) {
        $d = coach_subtract_calendar_months($today, $i);
        $months[] = ['y' => (int)$d->format('Y'), 'm' => (int)$d->format('n')];
    }

    $result = ['roster_id' => $roster['id'], 'identity_status' => $roster['id_match_status'], 'current_12mo' => [], 'prior_12mo' => []];

    $volByYm = [];
    if ($matched) {
        $canonicalId = $roster['canonical_agent_id'];
        $rangeStart = sprintf('%04d-%02d-01', $months[0]['y'], $months[0]['m']);
        $stmt = $db->prepare("
            SELECT strftime('%Y-%m', close_date) AS ym, SUM(close_price) AS vol
            FROM coastline_closed_sides
            WHERE canonical_agent_id = ? AND close_date >= ?
            GROUP BY ym
        ");
        $stmt->execute([$canonicalId, $rangeStart]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $volByYm[$r['ym']] = (float)$r['vol'];
        }
    }

    foreach ($months as $i => $ym) {
        $key = sprintf('%04d-%02d', $ym['y'], $ym['m']);
        $bucket = ['month' => $key, 'volume' => $volByYm[$key] ?? 0.0];
        if ($i < 12) $result['prior_12mo'][] = $bucket;
        else          $result['current_12mo'][] = $bucket;
    }

    return $result;
}

// ── Company-wide ("All Agents") aggregation — Step 5 ────────────────────────
//
// IMPORTANT, PERMANENT DESIGN NOTE: company-wide production is NOT the sum
// of every agent's full-credit production row, and never will be. Coastline
// credits EVERY named role (list/co_list/buy/co_buy) with the FULL
// transaction price — correct for an individual agent's own production, but
// summing that across agents would double- (or quadruple-) count any
// transaction where more than one of our own agents holds a credited role
// (a co-listed deal, or an in-house buy/sell). Company-wide numbers instead
// deduplicate by the deal itself — (mls_source, listing_key) — counting each
// real transaction and its close_price exactly once no matter how many of
// our agents were on it. "sides" is the deliberate exception: it stays a raw
// count of credited roles (may exceed the transaction count) since "how many
// sides did the brokerage get credit for" is a different, legitimate
// question from "how many deals closed." company_summary will therefore
// never equal sum(agents[].ltm_volume) etc. — that is by design, not a
// reconciliation bug to fix later.

// Company-wide closed metrics for a date range. average_sale_price here is
// volume / transactions (NOT / sides, unlike the per-agent formula) since
// volume is already deduplicated to one price per deal.
function coach_company_period_closed(\PDO $db, string $start, string $end): array {
    $stmt = $db->prepare("
        SELECT COUNT(*) AS sides,
               COUNT(DISTINCT mls_source || char(31) || listing_key) AS transactions,
               (SELECT SUM(price) FROM (
                   SELECT MIN(close_price) AS price
                   FROM coastline_closed_sides
                   WHERE close_date BETWEEN ? AND ?
                   GROUP BY mls_source, listing_key
               )) AS volume
        FROM coastline_closed_sides
        WHERE close_date BETWEEN ? AND ?
    ");
    $stmt->execute([$start, $end, $start, $end]);
    $r = $stmt->fetch(\PDO::FETCH_ASSOC);

    $transactions = (int)($r['transactions'] ?? 0);
    $volume = $r['volume'] !== null ? (float)$r['volume'] : 0.0;

    return [
        'transactions'       => $transactions,
        'sides'              => (int)($r['sides'] ?? 0),
        'volume'             => $volume,
        'average_sale_price' => $transactions > 0 ? $volume / $transactions : null,
    ];
}

// Company-wide MTD: same current-month-to-date vs. same-period-last-year
// comparison as coach_mtd_comparison(), using the dedup'd metrics above.
function coach_company_mtd_comparison(\PDO $db, ?\DateTimeImmutable $today = null): array {
    $today = $today ?? new \DateTimeImmutable('today');
    $currentStart = $today->format('Y-m-01');
    $currentEnd   = $today->format('Y-m-d');
    $priorEnd     = coach_subtract_calendar_months($today, 12);
    $priorStart   = $priorEnd->format('Y-m-01');
    $priorEndStr  = $priorEnd->format('Y-m-d');

    return [
        'current'    => array_merge(['start' => $currentStart, 'end' => $currentEnd], coach_company_period_closed($db, $currentStart, $currentEnd)),
        'prior_year' => array_merge(['start' => $priorStart, 'end' => $priorEndStr], coach_company_period_closed($db, $priorStart, $priorEndStr)),
    ];
}

// Company-wide Closed Volume by Month — same year-over-year paired-bucket
// layout as coach_agent_volume_chart(), same (mls_source, listing_key)
// dedup as coach_company_period_closed(). One query total.
function coach_company_volume_chart(\PDO $db, ?\DateTimeImmutable $today = null): array {
    $today = $today ?? new \DateTimeImmutable('today');
    $months = [];
    for ($i = 23; $i >= 0; $i--) {
        $d = coach_subtract_calendar_months($today, $i);
        $months[] = ['y' => (int)$d->format('Y'), 'm' => (int)$d->format('n')];
    }
    $rangeStart = sprintf('%04d-%02d-01', $months[0]['y'], $months[0]['m']);

    $stmt = $db->prepare("
        SELECT strftime('%Y-%m', close_date) AS ym, SUM(price) AS vol
        FROM (
            SELECT mls_source, listing_key, MIN(close_date) AS close_date, MIN(close_price) AS price
            FROM coastline_closed_sides
            WHERE close_date >= ?
            GROUP BY mls_source, listing_key
        )
        GROUP BY ym
    ");
    $stmt->execute([$rangeStart]);
    $volByYm = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) { $volByYm[$r['ym']] = (float)$r['vol']; }

    $result = ['current_12mo' => [], 'prior_12mo' => []];
    foreach ($months as $i => $ym) {
        $key = sprintf('%04d-%02d', $ym['y'], $ym['m']);
        $bucket = ['month' => $key, 'volume' => $volByYm[$key] ?? 0.0];
        if ($i < 12) $result['prior_12mo'][] = $bucket;
        else          $result['current_12mo'][] = $bucket;
    }
    return $result;
}
