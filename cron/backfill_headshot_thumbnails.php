<?php
// One-time (but safe to re-run) backfill: generates the roster thumbnail
// for every existing headshot that doesn't have one yet. New uploads get
// their thumbnail generated automatically going forward (see
// api/intake.php's upload handler), and any miss also self-heals lazily on
// first roster request — this script just avoids everyone's first roster
// load after deploy paying that lazy-generation cost one at a time.
//
// Never touches the original files in data/headshots/ — only reads them.
//
// Run once via:
//   docker exec agentedge php /var/www/html/cron/backfill_headshot_thumbnails.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

// A handful of the existing originals are large enough (multi-thousand-px,
// several MB) that GD's decoded in-memory bitmap exceeds the default 128M
// CLI limit. Scoped to this one-time backfill script only — doesn't touch
// the web-facing memory_limit.
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/headshot_thumb.php';

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD extension not loaded — nothing to do.\n");
    exit(1);
}

$cfgDir  = null;
if (file_exists(__DIR__ . '/../config.php')) {
    $cfg = require __DIR__ . '/../config.php';
    $cfgDir = $cfg['local_db_dir'] ?? null;
}
$dataDir = $cfgDir ?: (__DIR__ . '/../data');
$hsDir   = $dataDir . '/headshots';

$db   = local_db();
$rows = $db->query("SELECT file_key FROM agent_intake_files ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

$total = count($rows);
$made  = 0;
$skipped = 0;
$failed = [];

foreach ($rows as $i => $fileKey) {
    $sourcePath = $hsDir . '/' . basename($fileKey);
    $thumbPath  = headshot_thumb_path($fileKey);

    if (is_file($thumbPath) && filemtime($thumbPath) >= @filemtime($sourcePath)) {
        $skipped++;
        continue;
    }
    if (!is_file($sourcePath)) {
        $failed[] = "$fileKey (original missing)";
        continue;
    }
    if (generate_headshot_thumbnail($sourcePath, $fileKey)) {
        $made++;
    } else {
        $failed[] = $fileKey;
    }

    if (($i + 1) % 50 === 0) {
        fwrite(STDOUT, ($i + 1) . "/$total processed...\n");
    }
}

fwrite(STDOUT, "Done. $total total, $made generated, $skipped already up to date, " . count($failed) . " failed.\n");
if ($failed) {
    fwrite(STDOUT, "Failed (will fall back to serving the original for these):\n");
    foreach ($failed as $f) fwrite(STDOUT, "  - $f\n");
}
