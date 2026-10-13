<?php
/**
 * Coastline (INNOVATE Advantage) production mirror — full-snapshot sync.
 *
 * Manual use only for now — NOT yet on the crontab (Step 3 of the Coach
 * Dashboard production build; scheduling is a deliberately separate step):
 *   php cron/sync_coastline.php --dry-run   # fetch + validate only, no writes
 *   php cron/sync_coastline.php             # real sync
 *
 * Mirrors coastline-server's GET /public/agentedge/production/aggregates and
 * .../closed-sides into two local tables (coastline_agent_production_stats,
 * coastline_closed_sides — see local_db.php). These are full-replace mirrors
 * of Coastline's current state, not append-only history: every run fetches
 * the COMPLETE remote dataset first, validates it, and only then atomically
 * replaces both local tables in one SQLite transaction — see
 * coastline_sync_snapshot() in lib/coastline.php. A failed, partial, or
 * suspiciously-small fetch never touches the existing good mirror.
 *
 * closed-sides window: last ~25 calendar months (current 12mo + prior 12mo +
 * 1mo buffer), explicit since/until computed the same way coastline-server's
 * own endpoint computes its default, rather than relying silently on that
 * default.
 *
 * This sync does NOT touch innovate_roster and does NOT decide whether an
 * agent is matched — it only mirrors whatever canonical_agent_id rows
 * Coastline returns. Identity interpretation belongs to coach_production.php
 * (not yet built).
 */

define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
require_once 'lib/coastline.php';

$dryRun = in_array('--dry-run', $argv, true);

local_db(); // ensure coastline_* mirror tables exist

$now = date('Y-m-d H:i:s');
echo "[{$now}] Coastline production sync starting" . ($dryRun ? " (DRY RUN)" : "") . "\n";

try {
    $r = coastline_sync_snapshot($dryRun);
    echo "  source window: {$r['since']} .. {$r['until']}\n";
    echo "  aggregates: {$r['aggregate_pages']} page(s) fetched, {$r['aggregate_rows']} row(s)\n";
    echo "  closed-sides: {$r['closed_side_pages']} page(s) fetched, {$r['closed_side_rows']} row(s), " .
         "{$r['closed_side_agents']} distinct agent(s), {$r['mls_sources']} MLS source(s)\n";
    echo "  closed-sides date range observed: {$r['earliest_close_date']} .. {$r['latest_close_date']}\n";
    if ($dryRun) {
        echo "  DRY RUN — no local writes performed\n";
    } else {
        echo "  local rows written: {$r['local_rows_written']} (synced_at {$r['synced_at']})\n";
    }
    echo "  elapsed: {$r['elapsed_sec']}s\n";
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    echo "  existing local mirror left untouched\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Coastline production sync done\n";
