<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/gsc.php';
$agent = require_login();
if (!is_admin()) { http_response_code(403); exit('Forbidden'); }

$range = $_GET['range'] ?? '30';
$validRanges = ['7' => 7, '30' => 30, '90' => 90, 'all' => null];
if (!array_key_exists($range, $validRanges)) $range = '30';
$rangeDays = $validRanges[$range];

// Google Search Console — separate from the log-based stats above. Search Console
// data lags ~2-3 days behind real time and only covers actual Google organic search
// (not referrers from anywhere else), so this is queried/cached independently.
$GSC_KEY_FILE  = __DIR__ . '/join-analytics/secrets/gsc-join-site.json';
$GSC_SITE_URL  = 'https://join.innovateonline.com/';
$GSC_CACHE_DIR = __DIR__ . '/join-analytics/cache';
$gscEnd       = time();
$gscEndDate   = date('Y-m-d', $gscEnd - (3 * 86400));
$gscLookback  = $rangeDays !== null ? $rangeDays : 480; // ~16 months for "all" — Search Console's retention limit
$gscStartDate = date('Y-m-d', $gscEnd - ((3 + $gscLookback) * 86400));
$gscResult = file_exists($GSC_KEY_FILE)
    ? gsc_top_queries_cached($GSC_KEY_FILE, $GSC_SITE_URL, $gscStartDate, $gscEndDate, 10, $GSC_CACHE_DIR)
    : ['error' => 'Service account key not installed yet.'];

$BOT_PATTERNS = '/bot|spider|crawl|curl|wget|python-requests|python-urllib|facebookexternalhit|slackbot|discordbot|ahrefsbot|semrushbot|mj12bot|dotbot|uptimerobot|pingdom|go-http-client|okhttp|headlesschrome/i';
$ASSET_EXT = ['png','jpg','jpeg','gif','svg','ico','css','js','woff','woff2','ttf','map','webp','mp4','mov'];

$logDir  = __DIR__ . '/join-analytics/logs';
$logFiles = glob($logDir . '/access.log*');
sort($logFiles);

$now       = time();
$cutoff    = $rangeDays !== null ? $now - ($rangeDays * 86400) : 0;
$todayKey  = date('Y-m-d', $now);

$dailyCounts   = [];   // date => page-view count (bots excluded)
$topPages      = [];   // uri => count (in range)
$topReferrers  = [];   // host => count (in range)
$topCampaigns  = [];   // "source · campaign" => count (in range, only for tagged links)
$ipsInRange    = [];   // ip => true (in range, page views only)
$totalPageViewsAllTime = 0;
$totalPageViewsRange   = 0;
$botExcluded   = 0;
$todayViews    = 0;

if ($logFiles) {
    foreach ($logFiles as $f) {
        $fh = @fopen($f, 'r');
        if (!$fh) continue;
        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (!$row || empty($row['request'])) continue;
            $req    = $row['request'];
            $status = (int)($row['status'] ?? 0);
            if ($status < 200 || $status >= 400) continue; // skip 404s/errors from "views"
            $fullUri = $req['uri'] ?? '/';
            $uri = strtok($fullUri, '?');
            $ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
            if (in_array($ext, $ASSET_EXT, true)) continue; // only count page hits, not asset fetches

            $ua = $req['headers']['User-Agent'][0] ?? '';
            if ($ua === '' || preg_match($BOT_PATTERNS, $ua)) { $botExcluded++; continue; }

            $ts = (float)($row['ts'] ?? 0);
            $totalPageViewsAllTime++;
            $day = date('Y-m-d', (int)$ts);
            if ($day === $todayKey) $todayViews++;

            if ($ts >= $cutoff) {
                $totalPageViewsRange++;
                $dailyCounts[$day] = ($dailyCounts[$day] ?? 0) + 1;
                $topPages[$uri] = ($topPages[$uri] ?? 0) + 1;
                $ip = $req['client_ip'] ?? ($req['remote_ip'] ?? '');
                if ($ip !== '') $ipsInRange[$ip] = true;

                $ref = $req['headers']['Referer'][0] ?? '';
                if ($ref !== '') {
                    $refHost = parse_url($ref, PHP_URL_HOST) ?: $ref;
                    if (stripos($refHost, 'join.innovateonline.com') === false) {
                        $topReferrers[$refHost] = ($topReferrers[$refHost] ?? 0) + 1;
                    }
                }

                // UTM-tagged links (utm_source/utm_campaign) — the only way to attribute
                // traffic to a specific post/campaign, since Google/Facebook referrers
                // only reveal the domain, never the search query or the specific post.
                $qs = parse_url($fullUri, PHP_URL_QUERY);
                if ($qs) {
                    parse_str($qs, $qparams);
                    $utmSource   = trim($qparams['utm_source'] ?? '');
                    $utmCampaign = trim($qparams['utm_campaign'] ?? '');
                    if ($utmSource !== '' || $utmCampaign !== '') {
                        $key = ($utmSource !== '' ? $utmSource : '(unknown source)') . ' · ' . ($utmCampaign !== '' ? $utmCampaign : '(unknown campaign)');
                        $topCampaigns[$key] = ($topCampaigns[$key] ?? 0) + 1;
                    }
                }
            }
        }
        fclose($fh);
    }
}

arsort($topPages);
arsort($topReferrers);
arsort($topCampaigns);
ksort($dailyCounts);
$topPages     = array_slice($topPages, 0, 10, true);
$topReferrers = array_slice($topReferrers, 0, 10, true);
$topCampaigns = array_slice($topCampaigns, 0, 10, true);
$uniqueVisitors = count($ipsInRange);

// Fill in zero-days so the trend chart has no gaps (cap chart to last 90 days even on "all")
$chartDays = $rangeDays !== null ? min($rangeDays, 90) : 90;
$chartData = [];
for ($i = $chartDays - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', $now - ($i * 86400));
    $chartData[$d] = $dailyCounts[$d] ?? 0;
}
$maxDay = max(1, max($chartData));
$maxPageCount = max(1, $topPages ? max($topPages) : 1);
$maxRefCount  = max(1, $topReferrers ? max($topReferrers) : 1);
$maxCampaignCount = max(1, $topCampaigns ? max($topCampaigns) : 1);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
$rangeLabels = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'all' => 'All time'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Join Site Analytics — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .js-hero{background:linear-gradient(135deg,#1a1a1a 0%,#2d3a1e 100%);border-radius:12px;padding:24px 28px;color:white;margin-bottom:20px}
    .js-hero-title{font-size:20px;font-weight:900;margin:0 0 4px}
    .js-hero-sub{font-size:12px;color:rgba(255,255,255,.65);margin:0}
    .js-filters{display:flex;gap:8px;margin-bottom:20px}
    .js-filter{padding:7px 14px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;color:#555;background:#fff;border:1px solid #e0e0e0}
    .js-filter:hover{border-color:#82C112}
    .js-filter.active{background:#82C112;border-color:#82C112;color:#000}
    .js-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:24px}
    .js-tile{background:#fff;border:1px solid #e5e5e5;border-radius:10px;padding:16px 18px}
    .js-tile-label{font-size:11px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
    .js-tile-value{font-size:26px;font-weight:900;color:#111}
    .js-card{background:#fff;border:1px solid #e5e5e5;border-radius:12px;padding:20px 22px;margin-bottom:20px}
    .js-card-title{font-size:14px;font-weight:800;color:#111;margin:0 0 16px}
    .js-chart{display:flex;align-items:flex-end;gap:2px;height:160px;border-bottom:1px solid #eee;padding-bottom:2px;position:relative}
    .js-bar-wrap{flex:1;height:100%;display:flex;align-items:flex-end;position:relative}
    .js-bar{width:100%;max-width:24px;margin:0 auto;background:#82C112;border-radius:4px 4px 0 0;min-height:2px;cursor:pointer;transition:background .1s}
    .js-bar:hover{background:#5b8e0d}
    .js-tooltip{position:absolute;bottom:100%;left:50%;transform:translateX(-50%);margin-bottom:6px;background:#111;color:#fff;font-size:11px;font-weight:700;padding:4px 8px;border-radius:4px;white-space:nowrap;display:none;pointer-events:none;z-index:5}
    .js-bar-wrap:hover .js-tooltip{display:block}
    .js-row{display:flex;align-items:center;gap:10px;margin-bottom:10px;font-size:12px}
    .js-row:last-child{margin-bottom:0}
    .js-row-label{flex:0 0 220px;color:#333;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .js-row-bar-wrap{flex:1;background:#f2f2f2;border-radius:4px;height:16px;position:relative}
    .js-row-bar{height:100%;background:#82C112;border-radius:4px;min-width:2px}
    .js-row-count{flex:0 0 40px;text-align:right;font-weight:800;color:#111}
    .js-empty{color:#aaa;font-size:13px;padding:20px 0;text-align:center}
    .js-note{font-size:11px;color:#999;margin-top:8px}
    .js-gsc-table{width:100%;border-collapse:collapse;font-size:12px}
    .js-gsc-table th{text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#999;padding:0 10px 8px 0;border-bottom:1px solid #eee}
    .js-gsc-table td{padding:8px 10px 8px 0;border-bottom:1px solid #f5f5f5}
    .js-gsc-table td.js-num{text-align:right;font-weight:700;color:#111;white-space:nowrap}
    .js-gsc-table tr:last-child td{border-bottom:none}
    .js-two-col{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
    @media(max-width:1100px){.js-two-col{grid-template-columns:1fr 1fr}}
    @media(max-width:700px){.js-two-col{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_join_stats', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Join Site Analytics</div>
    </header>
    <main class="wrap">

      <div class="js-hero">
        <div class="js-hero-title">join.innovateonline.com Activity</div>
        <p class="js-hero-sub">Self-hosted, from Caddy access logs — no third-party tracking. Bot/monitoring traffic (<?= $botExcluded ?> requests) excluded automatically.</p>
      </div>

      <div class="js-filters">
        <?php foreach ($rangeLabels as $key => $label): ?>
        <a class="js-filter<?= $key === $range ? ' active' : '' ?>" href="?range=<?= h($key) ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>

      <div class="js-tiles">
        <div class="js-tile"><div class="js-tile-label">Page Views (<?= h($rangeLabels[$range]) ?>)</div><div class="js-tile-value"><?= number_format($totalPageViewsRange) ?></div></div>
        <div class="js-tile"><div class="js-tile-label">Unique Visitors (<?= h($rangeLabels[$range]) ?>)</div><div class="js-tile-value"><?= number_format($uniqueVisitors) ?></div></div>
        <div class="js-tile"><div class="js-tile-label">Views Today</div><div class="js-tile-value"><?= number_format($todayViews) ?></div></div>
        <div class="js-tile"><div class="js-tile-label">All-Time Page Views</div><div class="js-tile-value"><?= number_format($totalPageViewsAllTime) ?></div></div>
      </div>

      <div class="js-card">
        <div class="js-card-title">Daily Page Views — last <?= $chartDays ?> days</div>
        <?php if (array_sum($chartData) === 0): ?>
        <div class="js-empty">No traffic recorded yet in this window.</div>
        <?php else: ?>
        <div class="js-chart">
          <?php foreach ($chartData as $d => $cnt):
            $pct = max(2, round($cnt / $maxDay * 100));
          ?>
          <div class="js-bar-wrap">
            <div class="js-tooltip"><?= h(date('M j', strtotime($d))) ?>: <?= number_format($cnt) ?></div>
            <div class="js-bar" style="height:<?= $pct ?>%"></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="js-note">Hover a bar for the exact date and count.</div>
        <?php endif; ?>
      </div>

      <div class="js-two-col">
        <div class="js-card">
          <div class="js-card-title">Top Pages</div>
          <?php if (!$topPages): ?>
          <div class="js-empty">No page views in this window.</div>
          <?php else: foreach ($topPages as $uri => $cnt): $pct = max(3, round($cnt / $maxPageCount * 100)); ?>
          <div class="js-row">
            <div class="js-row-label" title="<?= h($uri) ?>"><?= h($uri) ?></div>
            <div class="js-row-bar-wrap"><div class="js-row-bar" style="width:<?= $pct ?>%"></div></div>
            <div class="js-row-count"><?= number_format($cnt) ?></div>
          </div>
          <?php endforeach; endif; ?>
        </div>

        <div class="js-card">
          <div class="js-card-title">Top Referrers</div>
          <?php if (!$topReferrers): ?>
          <div class="js-empty">No external referrers recorded in this window.</div>
          <?php else: foreach ($topReferrers as $host => $cnt): $pct = max(3, round($cnt / $maxRefCount * 100)); ?>
          <div class="js-row">
            <div class="js-row-label" title="<?= h($host) ?>"><?= h($host) ?></div>
            <div class="js-row-bar-wrap"><div class="js-row-bar" style="width:<?= $pct ?>%"></div></div>
            <div class="js-row-count"><?= number_format($cnt) ?></div>
          </div>
          <?php endforeach; endif; ?>
        </div>

        <div class="js-card">
          <div class="js-card-title">Campaigns (UTM-tagged links)</div>
          <?php if (!$topCampaigns): ?>
          <div class="js-empty">No tagged links clicked in this window.</div>
          <?php else: foreach ($topCampaigns as $label => $cnt): $pct = max(3, round($cnt / $maxCampaignCount * 100)); ?>
          <div class="js-row">
            <div class="js-row-label" title="<?= h($label) ?>"><?= h($label) ?></div>
            <div class="js-row-bar-wrap"><div class="js-row-bar" style="width:<?= $pct ?>%"></div></div>
            <div class="js-row-count"><?= number_format($cnt) ?></div>
          </div>
          <?php endforeach; endif; ?>
          <div class="js-note">Google/Facebook referrers only show the domain, never the search query or specific post. Tag any link you share with <code>?utm_source=facebook&amp;utm_campaign=name</code> to see it broken out here.</div>
        </div>
      </div>

      <div class="js-card">
        <div class="js-card-title">Top Search Queries (Google Search Console)</div>
        <?php if (!empty($gscResult['error'])): ?>
        <div class="js-empty"><?= h($gscResult['error']) ?></div>
        <?php elseif (empty($gscResult['rows'])): ?>
        <div class="js-empty">No search query data yet for this window — Search Console usually takes a few days after verification to start reporting.</div>
        <?php else: ?>
        <table class="js-gsc-table">
          <thead><tr><th>Query</th><th class="js-num">Clicks</th><th class="js-num">Impressions</th><th class="js-num">Avg. Position</th></tr></thead>
          <tbody>
          <?php foreach ($gscResult['rows'] as $r): ?>
          <tr>
            <td><?= h($r['query']) ?></td>
            <td class="js-num"><?= number_format($r['clicks']) ?></td>
            <td class="js-num"><?= number_format($r['impressions']) ?></td>
            <td class="js-num"><?= number_format($r['position'], 1) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
        <div class="js-note">From Google Search Console, not the log data above — this is aggregate site-wide search performance (~2-3 day lag), not tied to individual visitor sessions like the cards above.</div>
      </div>

    </main>
  </div>
</div>
</body>
</html>
