<?php
// Coastline (INNOVATE Advantage) production mirror — pull-only, full-snapshot sync.
//
// Source: coastline-server's AgentEdge-facing bulk export,
//   GET /public/agentedge/production/aggregates
//   GET /public/agentedge/production/closed-sides
// (see innovate-advantage-server CLAUDE.md, "Coach Dashboard bulk production
// export"). Both are hard-scoped server-side to Coastline's own team_members —
// this file has no way to request a different agent set.
//
// Reuses the same 'crm_base' / 'crm_token' config already used to reach
// coastline-server elsewhere in this app (api/listing_intel.php,
// backfill_agent_identity.php) — no separate Coastline credentials here.
//
// SNAPSHOT SEMANTICS: coastline_agent_production_stats / coastline_closed_sides
// are mirrors of Coastline's current state, not append-only history. Every
// sync fetches the COMPLETE remote dataset first, validates it, and only then
// atomically replaces both local tables in one transaction — see
// coastline_sync_snapshot(). A failed, partial, or suspiciously-small fetch
// never touches the existing good mirror (see coastline_sanity_check()).
//
// This file mirrors Coastline's canonical_agent_id values verbatim. It does
// NOT join against innovate_roster and does NOT decide whether a roster agent
// is matched/unmatched — that interpretation belongs to coach_production.php
// (not yet built). "No row here for an agent" must never be read as
// "unmatched" by any later caller — it may simply mean a validly-matched
// agent with zero qualifying Coastline production.

const COASTLINE_CLOSED_SIDES_WINDOW_MONTHS = 25; // current 12mo + prior 12mo + 1mo buffer, matches coastline-server's own default
const COASTLINE_SANITY_MIN_RATIO = 0.5; // refuse to overwrite the mirror if a fresh fetch returns less than this fraction of the existing row count

// date - N calendar months, matching Postgres's `date - interval 'N months'`
// (day-of-month held constant, clamped to the target month's last day) — the
// exact same arithmetic coastline-server's own closed-sides endpoint uses
// (_subtract_calendar_months in main.py), so our default window lines up with
// its own. NOT a 30-day-per-month approximation, which drifts over 25 months.
function coastline_subtract_calendar_months(\DateTimeImmutable $d, int $months): \DateTimeImmutable {
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

// One page of a coastline-server /public/agentedge/production/* call.
// Mirrors lib/darwin.php's darwin_request() shape (timeout, ignore_errors,
// HTTP-code extraction) but never includes the built URL (which carries the
// token in its query string) in any thrown message or log line.
function coastline_request(string $path, array $params = []): array {
    $c     = cfg();
    $base  = rtrim($c['crm_base'] ?? '', '/');
    $token = $c['crm_token'] ?? '';
    if ($base === '' || $token === '') {
        throw new \RuntimeException('Coastline crm_base/crm_token not configured in config.php');
    }

    $query = http_build_query(array_merge(['token' => $token], $params));
    $url = $base . $path . '?' . $query;

    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => 30,
        'header'        => "Accept: application/json\r\n",
        'ignore_errors' => true,
    ]]);
    $raw  = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (!empty($http_response_header[0])) {
        preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) && ($code = (int)$m[1]);
    }
    if ($code !== 200) {
        throw new \RuntimeException("Coastline {$path} HTTP {$code}: " . substr($raw ?: '(no response)', 0, 300));
    }
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        throw new \RuntimeException("Coastline {$path}: could not parse response as JSON");
    }
    return $data;
}

// Pages through a coastline-server bulk endpoint via limit/offset until
// has_more=false (PHASE 1 — fetch everything before touching the DB).
// $itemsKey is the response's row-array key ('agents' or 'sides').
function coastline_fetch_all_pages(string $path, array $extraParams, string $itemsKey, int $pageSize): array {
    $all = [];
    $offset = 0;
    $pages = 0;
    while (true) {
        $data = coastline_request($path, array_merge($extraParams, ['limit' => $pageSize, 'offset' => $offset]));
        $pages++;
        if (!isset($data[$itemsKey]) || !is_array($data[$itemsKey])) {
            throw new \RuntimeException("Coastline {$path}: unexpected response shape (missing '{$itemsKey}')");
        }
        $items = $data[$itemsKey];
        $all = array_merge($all, $items);
        $hasMore = $data['has_more'] ?? false;
        if (!$hasMore || count($items) === 0) break;
        $offset += $pageSize;
        if ($pages > 1000) throw new \RuntimeException("Coastline {$path}: exceeded 1000 pages — pagination likely stuck");
    }
    return [$all, $pages];
}

// ── PHASE 2: validate before touching SQLite ────────────────────────────────

function coastline_validate_aggregates(array $rows): void {
    if (count($rows) === 0) {
        throw new \RuntimeException('aggregates payload is empty — refusing to treat as a valid sync');
    }
    $seen = [];
    foreach ($rows as $r) {
        $id = (string)($r['canonical_agent_id'] ?? '');
        if ($id === '') throw new \RuntimeException('aggregates: row missing canonical_agent_id');
        if (isset($seen[$id])) throw new \RuntimeException("aggregates: duplicate canonical_agent_id in payload ({$id})");
        $seen[$id] = true;
    }
}

function coastline_validate_closed_sides(array $rows): void {
    if (count($rows) === 0) {
        throw new \RuntimeException('closed-sides payload is empty — refusing to treat as a valid sync');
    }
    $validRoles = ['list', 'co_list', 'buy', 'co_buy'];
    $validSides = ['list', 'buy'];
    $seen = [];
    foreach ($rows as $r) {
        $id = (string)($r['canonical_agent_id'] ?? '');
        if ($id === '') throw new \RuntimeException('closed-sides: row missing canonical_agent_id');

        $mls  = (string)($r['mls_source'] ?? '');
        $lk   = (string)($r['listing_key'] ?? '');
        $role = (string)($r['role'] ?? '');
        if ($mls === '' || $lk === '' || $role === '') {
            throw new \RuntimeException('closed-sides: row missing part of the (mls_source, listing_key, role) idempotency key');
        }
        $key = $mls . "\x1f" . $lk . "\x1f" . $role;
        if (isset($seen[$key])) {
            throw new \RuntimeException("closed-sides: duplicate (mls_source, listing_key, role) in payload ({$mls}/{$lk}/{$role})");
        }
        $seen[$key] = true;

        if (!in_array($role, $validRoles, true)) {
            throw new \RuntimeException("closed-sides: invalid role value '{$role}'");
        }
        $side = (string)($r['side'] ?? '');
        if (!in_array($side, $validSides, true)) {
            throw new \RuntimeException("closed-sides: invalid side value '{$side}'");
        }
        $cd = (string)($r['close_date'] ?? '');
        $dt = \DateTime::createFromFormat('Y-m-d', $cd);
        if (!$dt || $dt->format('Y-m-d') !== $cd) {
            throw new \RuntimeException("closed-sides: unparsable close_date '{$cd}'");
        }
    }
}

// Refuse to overwrite a good mirror with a malformed/partial response. Ratio-
// based (not a hardcoded row count, since real production changes daily) —
// only triggers when the existing mirror was non-empty to begin with, so the
// very first sync (mirror currently empty) is never blocked by this.
function coastline_sanity_check(\PDO $db, int $newAggCount, int $newCsCount): void {
    $priorAgg = (int)$db->query("SELECT COUNT(*) FROM coastline_agent_production_stats")->fetchColumn();
    $priorCs  = (int)$db->query("SELECT COUNT(*) FROM coastline_closed_sides")->fetchColumn();

    if ($priorAgg > 0 && $newAggCount < $priorAgg * COASTLINE_SANITY_MIN_RATIO) {
        throw new \RuntimeException(
            "aggregates sanity check failed: new fetch has {$newAggCount} row(s), existing mirror has {$priorAgg} — " .
            "below the " . (COASTLINE_SANITY_MIN_RATIO * 100) . "% threshold; refusing to overwrite"
        );
    }
    if ($priorCs > 0 && $newCsCount < $priorCs * COASTLINE_SANITY_MIN_RATIO) {
        throw new \RuntimeException(
            "closed-sides sanity check failed: new fetch has {$newCsCount} row(s), existing mirror has {$priorCs} — " .
            "below the " . (COASTLINE_SANITY_MIN_RATIO * 100) . "% threshold; refusing to overwrite"
        );
    }
}

// ── PHASE 3/4: atomic local replacement ─────────────────────────────────────
// Both tables (plus the sync metadata row) are replaced inside a single
// transaction so a sync is never left half-applied (aggregates refreshed but
// closed-sides stale, metadata describing a window that wasn't actually
// written, etc.) — any failure rolls back the whole thing and leaves the
// prior successful mirror (and its metadata) fully intact. $sourceWindowStart/
// $sourceWindowEnd are the EXACT since/until values sent to Coastline's
// closed-sides endpoint for this run — never derived from the fetched rows.
function coastline_write_snapshot(\PDO $db, array $aggRows, array $csRows, string $syncedAt, string $sourceWindowStart, string $sourceWindowEnd): void {
    $db->beginTransaction();
    try {
        $db->exec("DELETE FROM coastline_agent_production_stats");
        $db->exec("DELETE FROM coastline_closed_sides");

        $stmtAgg = $db->prepare("INSERT INTO coastline_agent_production_stats
            (canonical_agent_id, agent_name, is_active, volume_12mo, sides_12mo, list_side_12mo, buy_side_12mo,
             avg_sale_price_12mo, volume_24mo, sides_24mo, volume_prior_12mo, sides_prior_12mo, trend_pct_12mo,
             active_listing_count, active_listing_volume, last_close_date, synced_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($aggRows as $r) {
            $stmtAgg->execute([
                (string)$r['canonical_agent_id'],
                $r['agent_name'] ?? null,
                array_key_exists('is_active', $r) && $r['is_active'] !== null ? (int)(bool)$r['is_active'] : null,
                $r['volume_12mo'] ?? null,
                $r['sides_12mo'] ?? null,
                $r['list_side_12mo'] ?? null,
                $r['buy_side_12mo'] ?? null,
                $r['avg_sale_price_12mo'] ?? null,
                $r['volume_24mo'] ?? null,
                $r['sides_24mo'] ?? null,
                $r['volume_prior_12mo'] ?? null,
                $r['sides_prior_12mo'] ?? null,
                $r['trend_pct_12mo'] ?? null,
                $r['active_listing_count'] ?? null,
                $r['active_listing_volume'] ?? null,
                $r['last_close_date'] ?? null,
                $syncedAt,
            ]);
        }

        $stmtCs = $db->prepare("INSERT INTO coastline_closed_sides
            (mls_source, listing_key, role, canonical_agent_id, side, close_price, close_date,
             list_price, property_type, property_sub_type, listing_id, synced_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($csRows as $r) {
            $stmtCs->execute([
                (string)$r['mls_source'],
                (string)$r['listing_key'],
                (string)$r['role'],
                (string)$r['canonical_agent_id'],
                (string)$r['side'],
                $r['close_price'] ?? null,
                (string)$r['close_date'],
                $r['list_price'] ?? null,
                $r['property_type'] ?? null,
                $r['property_sub_type'] ?? null,
                $r['listing_id'] ?? null,
                $syncedAt,
            ]);
        }

        // Source-window metadata for coverage decisions — see local_db.php's
        // coastline_sync_meta comment. Single row (id=1), upserted in the
        // same transaction as the mirror tables above.
        $stmtMeta = $db->prepare("INSERT INTO coastline_sync_meta
            (id, source_window_start, source_window_end, synced_at, aggregate_rows, closed_side_rows)
            VALUES (1, ?, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET
                source_window_start=excluded.source_window_start,
                source_window_end=excluded.source_window_end,
                synced_at=excluded.synced_at,
                aggregate_rows=excluded.aggregate_rows,
                closed_side_rows=excluded.closed_side_rows");
        $stmtMeta->execute([$sourceWindowStart, $sourceWindowEnd, $syncedAt, count($aggRows), count($csRows)]);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

// Orchestrates the full snapshot sync: fetch everything (PHASE 1), validate
// (PHASE 2), and — unless $dryRun — atomically replace the local mirror
// (PHASE 3/4). Returns a summary array for the caller to log; the summary's
// counts are computed from the exact payload used for the write, so it can
// be used directly as "source totals for this run" when reconciling.
function coastline_sync_snapshot(bool $dryRun = false): array {
    $start = microtime(true);
    $db = local_db();

    $until = new \DateTimeImmutable('today');
    $since = coastline_subtract_calendar_months($until, COASTLINE_CLOSED_SIDES_WINDOW_MONTHS);
    $sinceStr = $since->format('Y-m-d');
    $untilStr = $until->format('Y-m-d');

    // PHASE 1
    [$aggRows, $aggPages] = coastline_fetch_all_pages('/public/agentedge/production/aggregates', [], 'agents', 500);
    [$csRows, $csPages]   = coastline_fetch_all_pages('/public/agentedge/production/closed-sides', ['since' => $sinceStr, 'until' => $untilStr], 'sides', 2000);

    // PHASE 2
    coastline_validate_aggregates($aggRows);
    coastline_validate_closed_sides($csRows);
    coastline_sanity_check($db, count($aggRows), count($csRows));

    $csAgentIds = array_unique(array_column($csRows, 'canonical_agent_id'));
    $mlsSources = array_unique(array_column($csRows, 'mls_source'));
    $closeDates = array_column($csRows, 'close_date');
    sort($closeDates);

    $summary = [
        'dry_run'             => $dryRun,
        'since'               => $sinceStr,
        'until'               => $untilStr,
        'aggregate_pages'     => $aggPages,
        'aggregate_rows'      => count($aggRows),
        'closed_side_pages'   => $csPages,
        'closed_side_rows'    => count($csRows),
        'closed_side_agents'  => count($csAgentIds),
        'mls_sources'         => count($mlsSources),
        'earliest_close_date' => $closeDates[0] ?? null,
        'latest_close_date'   => $closeDates[count($closeDates) - 1] ?? null,
        'local_rows_written'  => 0,
        'synced_at'           => null,
    ];

    // PHASE 3/4
    if (!$dryRun) {
        $syncedAt = date('Y-m-d H:i:s');
        coastline_write_snapshot($db, $aggRows, $csRows, $syncedAt, $sinceStr, $untilStr);
        $summary['local_rows_written'] = count($aggRows) + count($csRows);
        $summary['synced_at'] = $syncedAt;
    }

    $summary['elapsed_sec'] = round(microtime(true) - $start, 2);
    return $summary;
}
