<?php
/**
 * Darwin Cloud custom API — nightly sync (cap progress, revenue share, sales volume)
 *
 * Run via crontab:
 *   0 4 * * * /usr/bin/php /home/ec2-user/agentedge/cron/sync_darwin.php >> /home/ec2-user/agentedge/cron/sync_darwin.log 2>&1
 *
 * Geo-restriction: Darwin's endpoints are US-IP-only — this must run from the
 * Lightsail box (Virginia) or another US-based host, never a dev machine on a
 * non-US connection.
 *
 * First run per table does a full pull (base filter); subsequent runs pull only
 * rows changed since the max watermark already stored (capStatusModifyDate /
 * revShareModifyDate / volumeModifyDate) — see lib/darwin.php for the upsert logic.
 */

define('AGENTEDGE_CRON', true);
chdir(dirname(__DIR__));
require_once 'db.php';
require_once 'local_db.php';
require_once 'lib/darwin.php';

$now = date('Y-m-d H:i:s');
echo "[{$now}] Darwin sync starting\n";

local_db(); // ensure darwin_* tables exist before lib/darwin.php queries them

try {
    $result = darwin_sync_all();
    foreach ($result as $dataset => $r) {
        $mode = $r['incremental'] ? 'incremental' : 'full';
        echo "  {$dataset}: {$r['synced']} rows synced ({$mode})\n";
    }
} catch (\Throwable $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

$done = date('Y-m-d H:i:s');
echo "[{$done}] Darwin sync done\n";
