<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$db = local_db();

// ── Summary stats ─────────────────────────────────────────────────────────────
// 7d/30d are rolling windows (a fixed duration back from "now"), so UTC vs ET
// doesn't matter for them. "Today" is a calendar-day boundary, so it needs to
// be computed against Eastern midnight, not UTC midnight.
[$todayStartUtc, $todayEndUtc] = et_day_bounds_utc(0);
$totalLogins   = (int)$db->query("SELECT COUNT(*) FROM login_events")->fetchColumn();
$uniqueUsers   = (int)$db->query("SELECT COUNT(DISTINCT email) FROM login_events")->fetchColumn();
$logins7d      = (int)$db->query("SELECT COUNT(*) FROM login_events WHERE logged_in_at >= datetime('now','-7 days')")->fetchColumn();
$unique7d      = (int)$db->query("SELECT COUNT(DISTINCT email) FROM login_events WHERE logged_in_at >= datetime('now','-7 days')")->fetchColumn();
$logins30d     = (int)$db->query("SELECT COUNT(*) FROM login_events WHERE logged_in_at >= datetime('now','-30 days')")->fetchColumn();
$unique30d     = (int)$db->query("SELECT COUNT(DISTINCT email) FROM login_events WHERE logged_in_at >= datetime('now','-30 days')")->fetchColumn();
$loginsTodayStmt = $db->prepare("SELECT COUNT(*) FROM login_events WHERE logged_in_at >= ? AND logged_in_at < ?");
$loginsTodayStmt->execute([$todayStartUtc, $todayEndUtc]);
$loginsToday   = (int)$loginsTodayStmt->fetchColumn();

// ── Per-user breakdown ────────────────────────────────────────────────────────
$perUser = $db->query("
    SELECT
        email,
        MAX(name) AS name,
        MAX(logged_in_at) AS last_login,
        COUNT(*) AS total_logins,
        SUM(CASE WHEN logged_in_at >= datetime('now','-7 days')  THEN 1 ELSE 0 END) AS logins_7d,
        SUM(CASE WHEN logged_in_at >= datetime('now','-30 days') THEN 1 ELSE 0 END) AS logins_30d,
        GROUP_CONCAT(DISTINCT method) AS methods
    FROM login_events
    GROUP BY email
    ORDER BY last_login DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Recent feed ───────────────────────────────────────────────────────────────
$recent = $db->query("
    SELECT email, name, method, ip, user_agent, logged_in_at
    FROM login_events
    ORDER BY logged_in_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// ── Daily trend (last 30 days) ────────────────────────────────────────────────
// SQLite has no IANA timezone support, so day buckets are computed here in PHP
// against Eastern calendar days rather than via SQL date(logged_in_at) (UTC).
// Tracks unique users per day (not raw login count) — a heavy re-logger
// shouldn't make a day look busier than it actually was.
$trendRaw = $db->query("
    SELECT logged_in_at, email
    FROM login_events
    WHERE logged_in_at >= datetime('now','-31 days')
")->fetchAll(PDO::FETCH_ASSOC);

$trendSets = []; // 'Y-m-d' (Eastern) => set of emails (as array keys)
$etZone = new DateTimeZone('America/New_York');
foreach ($trendRaw as $row) {
    $ts = strtotime($row['logged_in_at'] . ' UTC');
    if ($ts === false) continue;
    $d = (new DateTime('@' . $ts))->setTimezone($etZone);
    $day = $d->format('Y-m-d');
    $trendSets[$day][strtolower(trim($row['email']))] = true;
}
$trendMap = array_map('count', $trendSets); // 'Y-m-d' => unique login count
$trend = !empty($trendMap);

// ── Most Used Pages (last 30 days) ──────────────────────────────────────────
// 'internal' rows are logged on every page load via nav.php's render_sidebar();
// 'external' rows are logged by go.php when an agent clicks a link out to
// another system (Advantage, MLS portals, etc.) — those navigate off-domain,
// so they can't be captured by a normal AgentEdge page load. Together these
// answer "are people actually using AgentEdge's own tools, or mostly just
// launching out to other systems from here."
// Super admins and darren@darrenwoodard.com (a personal/test login, not a
// real agent) are excluded — their dev/admin poking around the app would
// otherwise skew this toward "most-clicked-while-building-it" rather than
// real agent usage.
$excludedEmails = $db->query("SELECT email FROM agent_roles WHERE role='super_admin'")->fetchAll(PDO::FETCH_COLUMN);
$excludedEmails[] = 'darren@darrenwoodard.com';
$excludedEmails = array_values(array_unique(array_map('strtolower', $excludedEmails)));
$excludedPlaceholders = implode(',', array_fill(0, count($excludedEmails), '?'));

$pageViews = $db->prepare("
    SELECT page_key, page_type, COUNT(*) AS views, COUNT(DISTINCT email) AS uniq
    FROM page_views
    WHERE viewed_at >= datetime('now','-30 days') AND email NOT IN ($excludedPlaceholders)
    GROUP BY page_key, page_type
    ORDER BY views DESC
    LIMIT 25
");
$pageViews->execute($excludedEmails);
$pageViews = $pageViews->fetchAll(PDO::FETCH_ASSOC);
$navLabels = nav_all_items_by_key();
foreach ($pageViews as &$pv) {
    $pv['label'] = $navLabels[$pv['page_key']]['label'] ?? $pv['page_key'];
}
unset($pv);

$viewSplit = $db->prepare("
    SELECT page_type, COUNT(*) AS c
    FROM page_views
    WHERE viewed_at >= datetime('now','-30 days') AND email NOT IN ($excludedPlaceholders)
    GROUP BY page_type
");
$viewSplit->execute($excludedEmails);
$viewSplit = $viewSplit->fetchAll(PDO::FETCH_KEY_PAIR);
$internalViews = (int)($viewSplit['internal'] ?? 0);
$externalViews = (int)($viewSplit['external'] ?? 0);
$totalViews    = $internalViews + $externalViews;
$internalPct   = $totalViews > 0 ? round($internalViews / $totalViews * 100) : 0;

function fmt_dt(?string $dt): string {
    return fmt_dt_et($dt);
}
function method_badge(string $methods): string {
    $out = '';
    if (str_contains($methods, 'google'))   $out .= '<span class="lr-badge lr-g">Google</span>';
    if (str_contains($methods, 'password')) $out .= '<span class="lr-badge lr-p">Password</span>';
    return $out;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login Report — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .lr-tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px}
    .lr-tile{background:#fff;border-radius:10px;border:1px solid #eee;padding:14px 18px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
    .lr-tile-val{font-size:28px;font-weight:800;color:#111;line-height:1}
    .lr-tile-sub{font-size:11px;color:#888;margin-top:3px}
    .lr-tile-label{font-size:12px;font-weight:700;color:#555;margin-bottom:6px}
    .lr-section{background:#fff;border-radius:10px;border:1px solid #eee;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:22px;overflow:hidden}
    .lr-section-head{padding:14px 18px;border-bottom:1px solid #f0f0f0;font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
    .lr-section-head span{font-size:11px;font-weight:600;color:#999;margin-left:auto}
    .lr-table{width:100%;border-collapse:collapse}
    .lr-table th{padding:9px 14px;text-align:left;font-size:11px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #f0f0f0;white-space:nowrap}
    .lr-table td{padding:9px 14px;font-size:13px;color:#333;border-bottom:1px solid #fafafa;vertical-align:middle}
    .lr-table tr:last-child td{border-bottom:0}
    .lr-table tr:hover td{background:#fafcf7}
    .lr-name{font-weight:600;color:#111}
    .lr-email{font-size:11px;color:#888}
    .lr-badge{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;margin-right:3px}
    .lr-g{background:#e8f5e9;color:#2e7d32}
    .lr-p{background:#e3f2fd;color:#1565c0}
    .lr-zero{color:#ccc}
    .lr-chart-wrap{padding:16px 18px}
    .lr-bars{display:flex;align-items:flex-end;gap:3px;height:80px}
    .lr-bar-col{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:2px;height:100%}
    .lr-bar-track{width:100%;height:64px;display:flex;align-items:flex-end}
    .lr-bar{width:100%;background:#82C112;border-radius:2px 2px 0 0;min-height:2px;transition:opacity .2s}
    .lr-bar:hover{opacity:.75}
    .lr-bar-lbl{font-size:8px;color:#bbb;white-space:nowrap;transform:rotate(-45deg);transform-origin:top right;margin-top:4px}
    .lr-empty{padding:28px;text-align:center;color:#bbb;font-size:13px;font-style:italic}
    .lr-ip{font-size:11px;color:#aaa;font-family:monospace}
    .lr-int{background:#e8f5e9;color:#2e7d32}
    .lr-ext{background:#fff3e0;color:#e65100}
    .lr-split-bar-wrap{padding:14px 18px;display:flex;align-items:center;gap:12px}
    .lr-split-bar{flex:1;height:10px;border-radius:5px;background:#fff3e0;overflow:hidden;display:flex}
    .lr-split-bar-internal{height:100%;background:#82C112}
    .lr-split-lbl{font-size:12px;color:#555;white-space:nowrap}
    @media(max-width:800px){.lr-tiles{grid-template-columns:repeat(2,1fr)}}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_login_report', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Login Report</div>
    </header>
    <main class="wrap">

      <!-- Summary tiles -->
      <div class="lr-tiles">
        <div class="lr-tile">
          <div class="lr-tile-label">Total Logins</div>
          <div class="lr-tile-val"><?= number_format($totalLogins) ?></div>
          <div class="lr-tile-sub">all time</div>
        </div>
        <div class="lr-tile">
          <div class="lr-tile-label">Unique Users</div>
          <div class="lr-tile-val"><?= number_format($uniqueUsers) ?></div>
          <div class="lr-tile-sub"><?= $unique7d ?> active last 7 days</div>
        </div>
        <div class="lr-tile">
          <div class="lr-tile-label">Last 30 Days</div>
          <div class="lr-tile-val"><?= number_format($logins30d) ?></div>
          <div class="lr-tile-sub"><?= $unique30d ?> unique users</div>
        </div>
        <div class="lr-tile">
          <div class="lr-tile-label">Today</div>
          <div class="lr-tile-val"><?= number_format($loginsToday) ?></div>
          <div class="lr-tile-sub"><?= $logins7d ?> in last 7 days</div>
        </div>
      </div>

      <!-- 30-day trend chart -->
      <div class="lr-section">
        <div class="lr-section-head">Daily Unique Logins — Last 30 Days</div>
        <?php if ($trend): ?>
        <div class="lr-chart-wrap">
          <?php
            $days = [];
            for ($i = 29; $i >= 0; $i--) {
                $day = date('Y-m-d', strtotime("-{$i} days"));
                $days[] = ['day' => $day, 'logins' => $trendMap[$day] ?? 0];
            }
            $maxLogins = max(array_column($days, 'logins') ?: [1]);
          ?>
          <div class="lr-bars">
            <?php foreach ($days as $d): ?>
              <?php $pct = $maxLogins > 0 ? round($d['logins'] / $maxLogins * 100) : 0; ?>
              <div class="lr-bar-col" title="<?= htmlspecialchars($d['day']) ?>: <?= $d['logins'] ?> unique login(s)">
                <div class="lr-bar-track">
                  <div class="lr-bar" style="height:<?= max($pct, $d['logins'] > 0 ? 3 : 0) ?>%"></div>
                </div>
                <?php if ($d['day'] === date('Y-m-d') || date('j', strtotime($d['day'])) == 1): ?>
                  <div class="lr-bar-lbl"><?= date('M j', strtotime($d['day'])) ?></div>
                <?php else: ?>
                  <div class="lr-bar-lbl">&nbsp;</div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php else: ?>
          <div class="lr-empty">No login data yet. Logins will appear here once agents sign in.</div>
        <?php endif; ?>
      </div>

      <!-- Most used pages -->
      <div class="lr-section">
        <div class="lr-section-head">
          Most Used Pages
          <span>last 30 days</span>
        </div>
        <?php if ($totalViews > 0): ?>
        <div class="lr-split-bar-wrap">
          <div class="lr-split-lbl"><?= $internalPct ?>% AgentEdge pages</div>
          <div class="lr-split-bar"><div class="lr-split-bar-internal" style="width:<?= $internalPct ?>%"></div></div>
          <div class="lr-split-lbl"><?= 100 - $internalPct ?>% external launches</div>
        </div>
        <table class="lr-table">
          <thead>
            <tr>
              <th>Page</th>
              <th>Type</th>
              <th>Views</th>
              <th>Unique Users</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pageViews as $pv): ?>
            <tr>
              <td class="lr-name"><?= htmlspecialchars($pv['label']) ?></td>
              <td>
                <?php if ($pv['page_type'] === 'external'): ?>
                  <span class="lr-badge lr-ext">External ↗</span>
                <?php else: ?>
                  <span class="lr-badge lr-int">AgentEdge</span>
                <?php endif; ?>
              </td>
              <td><strong><?= number_format($pv['views']) ?></strong></td>
              <td><?= number_format($pv['uniq']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="lr-empty">No page-view data yet — this starts collecting from today forward, navigation before this feature was added wasn't tracked.</div>
        <?php endif; ?>
      </div>

      <!-- Per-user table -->
      <div class="lr-section">
        <div class="lr-section-head">
          Agent Activity
          <span><?= count($perUser) ?> user<?= count($perUser) !== 1 ? 's' : '' ?></span>
        </div>
        <?php if ($perUser): ?>
        <table class="lr-table">
          <thead>
            <tr>
              <th>Agent</th>
              <th>Last Login</th>
              <th>Total</th>
              <th>7 Days</th>
              <th>30 Days</th>
              <th>Method</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($perUser as $u): ?>
            <tr>
              <td>
                <div class="lr-name"><?= htmlspecialchars($u['name'] ?: $u['email']) ?></div>
                <div class="lr-email"><?= htmlspecialchars($u['email']) ?></div>
              </td>
              <td><?= htmlspecialchars(fmt_dt($u['last_login'])) ?></td>
              <td><strong><?= $u['total_logins'] ?></strong></td>
              <td><?= $u['logins_7d'] > 0 ? $u['logins_7d'] : '<span class="lr-zero">—</span>' ?></td>
              <td><?= $u['logins_30d'] > 0 ? $u['logins_30d'] : '<span class="lr-zero">—</span>' ?></td>
              <td><?= method_badge($u['methods']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="lr-empty">No logins recorded yet.</div>
        <?php endif; ?>
      </div>

      <!-- Recent activity feed -->
      <div class="lr-section">
        <div class="lr-section-head">
          Recent Logins
          <span>last 100</span>
        </div>
        <?php if ($recent): ?>
        <table class="lr-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Agent</th>
              <th>Method</th>
              <th>IP</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
              <td style="white-space:nowrap"><?= htmlspecialchars(fmt_dt($r['logged_in_at'])) ?></td>
              <td>
                <div class="lr-name"><?= htmlspecialchars($r['name'] ?: $r['email']) ?></div>
                <div class="lr-email"><?= htmlspecialchars($r['email']) ?></div>
              </td>
              <td><?= method_badge($r['method']) ?></td>
              <td><span class="lr-ip"><?= htmlspecialchars($r['ip']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
          <div class="lr-empty">No logins recorded yet.</div>
        <?php endif; ?>
      </div>

    </main>
  </div>
</div>
</body>
</html>
