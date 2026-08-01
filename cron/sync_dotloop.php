<?php
/**
 * DotLoop company-wide loop sync (shared admin connection).
 *
 * Run via crontab:
 *   0 3 * * * /usr/bin/php /home/ec2-user/agentedge/cron/sync_dotloop.php >> /home/ec2-user/agentedge/cron/sync_dotloop.log 2>&1
 *
 * Pulls loops for ACTIVE_LISTING, UNDER_CONTRACT, SOLD, WITHDRAWN deal stages
 * (bounded to the last 2 years by default) plus each loop's participants, into
 * the local dotloop_loops / dotloop_loop_participants cache. "My Transactions"
 * reads from that cache instead of calling DotLoop live per page view.
 *
 * The first run is slow (thousands of loops, one participant API call each,
 * throttled) — this is expected. Run it once manually before relying on the
 * cron schedule, ideally left running in the background rather than watched.
 */

define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
require_once 'lib/dotloop.php';

local_db(); // ensure dotloop_loops / dotloop_loop_participants tables exist

$now = date('Y-m-d H:i:s');
echo "[{$now}] DotLoop sync starting\n";

try {
    $result = dotloop_sync_company_loops();
    if (!$result['ok']) {
        echo "  ERROR: " . $result['error'] . "\n";
        exit(1);
    }
    foreach ($result['stages'] as $stage => $count) {
        echo "  {$stage}: {$count} loops synced\n";
    }
    echo "  total: {$result['total_loops']} loops\n";
    if (!empty($result['errors'])) {
        echo "  errors encountered:\n";
        foreach ($result['errors'] as $e) echo "    - {$e}\n";
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] DotLoop sync finished\n";
