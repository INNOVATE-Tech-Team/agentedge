<?php
/**
 * Google Business Profile candidate discovery.
 *
 * Run via crontab (weekly is plenty — the roster doesn't churn fast enough
 * to need this nightly):
 *   0 5 * * 0 /usr/bin/php /home/ec2-user/agentedge/cron/discover_google_place_candidates.php >> /home/ec2-user/agentedge/cron/discover_google_place_candidates.log 2>&1
 *
 * For every active agent without a google_place_id on file and without an
 * already-decided candidate, runs a Places API Text Search and stages the
 * best plausible match in google_place_candidates for the agent to confirm
 * on their own profile page — never writes google_place_id directly. See
 * google_place_candidate_discover_all() in lib/google_business.php.
 */

define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
require_once 'lib/google_business.php';

local_db(); // ensure google_place_candidates table exists

$now = date('Y-m-d H:i:s');
echo "[{$now}] Google Place candidate discovery starting\n";

try {
    $result = google_place_candidate_discover_all();
    echo "  checked: {$result['checked']} agent(s) without a Place ID on file\n";
    echo "  candidates found: {$result['candidates_found']}\n";
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Google Place candidate discovery finished\n";
