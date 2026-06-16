<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/roles.php';
require __DIR__ . '/local_db.php';
require __DIR__ . '/oh_subnav.php';
require __DIR__ . '/nav.php';

$agent = require_login();
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$db      = local_db();
$myEmail = strtolower(trim($agent['email']));
$admin   = is_admin();

// Parse ?month=YYYY-MM
$monthParam = trim($_GET['month'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}
[$year, $month] = array_map('intval', explode('-', $monthParam));
if ($month < 1 || $month > 12) { $month = (int)date('m'); $year = (int)date('Y'); }

// Prev / next month
$prevMonth = date('Y-m', mktime(0,0,0,$month-1,1,$year));
$nextMonth = date('Y-m', mktime(0,0,0,$month+1,1,$year));
$monthLabel = date('F Y', mktime(0,0,0,$month,1,$year));

// Calendar grid boundaries
$firstDay = mktime(0,0,0,$month,1,$year);
$lastDay  = mktime(0,0,0,$month+1,0,$year);
$startDow = (int)date('w', $firstDay); // 0=Sun
$totalDays= (int)date('t', $firstDay);
$today    = date('Y-m-d');

// Range for querying: cover the full 6-week grid
$gridStart = date('Y-m-d', mktime(0,0,0,$month,1-$startDow,$year));
$gridEnd   = date('Y-m-d', mktime(0,0,0,$month,$totalDays+(41-($startDow+$totalDays-1)%7),$year));

// ── Events I'm doing: approved requests I made ──────────────────────────────
$doingQ = $db->prepare("
    SELECT s.slot_date, s.start_time, s.end_time,
           l.address, l.city, l.listing_agent_name
    FROM oh_requests r
    JOIN oh_slots    s ON s.id = r.slot_id
    JOIN oh_listings l ON l.id = r.listing_id
    WHERE r.agent_email=? AND r.status='approved'
      AND s.slot_date BETWEEN ? AND ?
    ORDER BY s.slot_date, s.start_time
");
$doingQ->execute([$myEmail, $gridStart, $gridEnd]);
$doingEvents = [];
foreach ($doingQ->fetchAll(PDO::FETCH_ASSOC) as $ev) {
    $doingEvents[$ev['slot_date']][] = $ev;
}

// ── My listing slots ─────────────────────────────────────────────────────────
$listingQ = $db->prepare("
    SELECT s.slot_date, s.start_time, s.end_time,
           l.address, l.city
    FROM oh_slots    s
    JOIN oh_listings l ON l.id = s.listing_id
    WHERE LOWER(l.listing_agent_email)=?
      AND s.slot_date BETWEEN ? AND ?
    ORDER BY s.slot_date, s.start_time
");
$listingQ->execute([$myEmail, $gridStart, $gridEnd]);
$listingEvents = [];
foreach ($listingQ->fetchAll(PDO::FETCH_ASSOC) as $ev) {
    $listingEvents[$ev['slot_date']][] = $ev;
}

// Build 6-week grid (42 cells starting from $gridStart)
$cells = [];
for ($i = 0; $i < 42; $i++) {
    $cellDate = date('Y-m-d', mktime(0,0,0,$month,1-$startDow+$i,$year));
    $cells[]  = $cellDate;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Calendar — Open House — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">
  <?php render_sidebar('openhouse', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Open House Calendar</div>
    </header>
    <main class="wrap">
      <?php render_oh_subnav('calendar', $admin); ?>

      <!-- Month nav -->
      <div class="cal-toolbar" style="margin-bottom:16px">
        <div class="cal-nav">
          <a href="?month=<?= h($prevMonth) ?>" class="btn-cal-nav">&lsaquo; Prev</a>
          <div class="cal-month-label"><?= h($monthLabel) ?></div>
          <a href="?month=<?= h($nextMonth) ?>" class="btn-cal-nav">Next &rsaquo;</a>
        </div>
        <div style="display:flex;gap:10px;font-size:12px;align-items:center">
          <span style="display:inline-flex;align-items:center;gap:5px">
            <span style="display:inline-block;width:12px;height:12px;background:#eef5e8;border-radius:2px"></span>
            Approved open houses I'm doing
          </span>
          <span style="display:inline-flex;align-items:center;gap:5px">
            <span style="display:inline-block;width:12px;height:12px;background:#e0edf8;border-radius:2px"></span>
            My listing slots
          </span>
        </div>
      </div>

      <!-- Day headers -->
      <div class="cal-day-names">
        <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dn): ?>
          <div class="cal-day-name"><?= $dn ?></div>
        <?php endforeach; ?>
      </div>

      <!-- Calendar grid -->
      <div class="oh-cal-grid">
        <?php foreach ($cells as $cellDate):
          $cellMonth  = (int)substr($cellDate,5,2);
          $isOtherMonth = $cellMonth !== $month;
          $isToday    = $cellDate === $today;
          $dayNum     = (int)substr($cellDate,8,2);

          $doCls  = 'oh-cal-cell' . ($isOtherMonth ? ' other-month' : '') . ($isToday ? ' today' : '');
          $dayEvents  = $doingEvents[$cellDate]   ?? [];
          $lstEvents  = $listingEvents[$cellDate] ?? [];
        ?>
        <div class="<?= $doCls ?>">
          <div class="cal-cell-num"><?= $dayNum ?></div>
          <?php foreach ($dayEvents as $ev):
            $fs = date('g:ia', strtotime($ev['start_time']));
          ?>
            <div class="oh-cal-ev mine-doing" title="Doing: <?= h($ev['address'].', '.$ev['city']) ?>">
              <?= h($fs) ?> <?= h($ev['address']) ?>
            </div>
          <?php endforeach; ?>
          <?php foreach ($lstEvents as $ev):
            $fs = date('g:ia', strtotime($ev['start_time']));
          ?>
            <div class="oh-cal-ev mine-listing" title="My listing: <?= h($ev['address'].', '.$ev['city']) ?>">
              <?= h($fs) ?> <?= h($ev['address']) ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Legend / summary list -->
      <?php
      $allDoing   = array_merge(...array_values($doingEvents ?: [[]]));
      $allListing = array_merge(...array_values($listingEvents ?: [[]]));
      if (!empty($allDoing) || !empty($allListing)):
      ?>
      <div class="card" style="margin-top:20px">
        <h2 style="font-size:14px;font-weight:800;margin:0 0 14px">This month's open houses</h2>
        <?php if (!empty($allDoing)): ?>
          <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#888;margin-bottom:8px">Approved — I'm doing</div>
          <?php foreach ($allDoing as $ev):
            $fd  = date('M j, Y', strtotime($ev['slot_date']));
            $fs  = date('g:i A', strtotime($ev['start_time']));
            $fe  = date('g:i A', strtotime($ev['end_time']));
          ?>
          <div style="padding:8px 0;border-bottom:1px solid #f0f0f0;font-size:13px">
            <span style="display:inline-block;width:8px;height:8px;background:#82C112;border-radius:2px;margin-right:6px"></span>
            <strong><?= h($ev['address']) ?>, <?= h($ev['city']) ?></strong>
            <span style="color:#888;font-size:12px;margin-left:8px"><?= h($fd) ?> &middot; <?= h($fs) ?>–<?= h($fe) ?></span>
            <?php if (!empty($ev['listing_agent_name'])): ?>
              <span style="color:#aaa;font-size:11px;margin-left:6px">Listed by <?= h($ev['listing_agent_name']) ?></span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($allListing)): ?>
          <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#888;margin:16px 0 8px">My listing slots</div>
          <?php foreach ($allListing as $ev):
            $fd = date('M j, Y', strtotime($ev['slot_date']));
            $fs = date('g:i A', strtotime($ev['start_time']));
            $fe = date('g:i A', strtotime($ev['end_time']));
          ?>
          <div style="padding:8px 0;border-bottom:1px solid #f0f0f0;font-size:13px">
            <span style="display:inline-block;width:8px;height:8px;background:#2c9cc9;border-radius:2px;margin-right:6px"></span>
            <strong><?= h($ev['address']) ?>, <?= h($ev['city']) ?></strong>
            <span style="color:#888;font-size:12px;margin-left:8px"><?= h($fd) ?> &middot; <?= h($fs) ?>–<?= h($fe) ?></span>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>
