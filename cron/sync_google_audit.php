<?php
/**
 * Google Business Profile audit sync.
 *
 * Run via crontab:
 *   0 4 * * * /usr/bin/php /home/ec2-user/agentedge/cron/sync_google_audit.php >> /home/ec2-user/agentedge/cron/sync_google_audit.log 2>&1
 *
 * Refreshes google_business_audit for every agent with a Google Place ID on
 * file (agent_intake.google_place_id, self-entered on the agent's profile
 * page) via the Places API. See backoffice_google_audit.php for the dashboard
 * this feeds, and lib/google_business.php for the sync logic itself.
 */

define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
require_once 'lib/google_business.php';

local_db(); // ensure google_business_audit table exists

$now = date('Y-m-d H:i:s');
echo "[{$now}] Google Business audit sync starting\n";

try {
    $result = google_business_sync_all();
    echo "  checked: {$result['checked']} agent(s) with a Place ID on file\n";
    if (!empty($result['errors'])) {
        echo "  errors encountered:\n";
        foreach ($result['errors'] as $e) echo "    - {$e}\n";
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Google Business audit sync finished\n";
