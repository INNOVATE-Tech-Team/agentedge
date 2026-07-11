<?php
require_once 'db.php';
require_once 'auth.php';
require_once 'local_db.php';
require_once 'nav.php';

$agent = require_login();
require_admin();

$db         = local_db();
$cfg        = cfg();
$costPerRec = (float)($cfg['listing_intel_cost_per_rec'] ?? 0.10);
$hasCompanyKey = !empty(trim($cfg['propstream_api_key'] ?? ''));

// ── Period selector ───────────────────────────────────────────────────────────
$availPeriods = $db->query("SELECT DISTINCT period FROM listing_intel_usage ORDER BY period DESC")->fetchAll(PDO::FETCH_COLUMN);
$period = $_GET['period'] ?? ($availPeriods[0] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');

// ── Load usage for selected period ───────────────────────────────────────────
$rows = $db->prepare("
    SELECT agent_email,
           SUM(records_pulled)  AS total_pulled,
           COUNT(*)             AS sync_count,
           MAX(synced_at)       AS last_sync
    FROM listing_intel_usage
    WHERE period=?
    GROUP BY agent_email
    ORDER BY total_pulled DESC
")->execute([$period]) ? null : null; // need fetch
$stmt = $db->prepare("
    SELECT agent_email,
           SUM(records_pulled)  AS total_pulled,
           COUNT(*)             AS sync_count,
           MAX(synced_at)       AS last_sync
    FROM listing_intel_usage
    WHERE period=?
    GROUP BY agent_email
    ORDER BY total_pulled DESC
");
$stmt->execute([$period]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grandTotal   = array_sum(array_column($rows, 'total_pulled'));
$grandCharges = round($grandTotal * $costPerRec, 2);

// ── Agent name lookup from Perfex ─────────────────────────────────────────────
$agentNames = [];
try {
    $perfex = pdo_ro();
    $names  = $perfex->query("SELECT email, CONCAT(firstname,' ',lastname) AS fullname FROM tblstaff")->fetchAll(PDO::FETCH_KEY_PAIR);
    $agentNames = $names;
} catch (\Exception $e) {}

// ── CSV export ────────────────────────────────────────────────────────────────
if (isset($_GET['export_csv'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="listing-intel-billing-' . $period . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Agent Email','Agent Name','Records Pulled','Sync Count','Last Sync','Charge ($)']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['agent_email'],
            $agentNames[$r['agent_email']] ?? '',
            $r['total_pulled'],
            $r['sync_count'],
            $r['last_sync'],
            number_format($r['total_pulled'] * $costPerRec, 2),
        ]);
    }
    fclose($out);
    exit;
}

$periodLabel = date('F Y', strtotime($period . '-01'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Listing Intel Billing — AgentEdge</title>
<link rel="stylesheet" href="assets/style.css">
<style>
.bo-header{display:flex;align-items:center;gap:16px;margin-bottom:24px;flex-wrap:wrap}
.bo-header h1{margin:0;font-size:22px;font-weight:700}
.bo-meta{font-size:13px;color:var(--faint,#888)}
.period-form{display:flex;align-items:center;gap:8px;margin-left:auto}
.period-form select{padding:6px 10px;border:1px solid #ddd;border-radius:6px;font-size:13px}
.period-form button{padding:6px 14px;background:var(--primary,#1e6fff);color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px}
.summary-cards{display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap}
.sum-card{background:#fff;border:1px solid #e4e7ec;border-radius:10px;padding:18px 24px;flex:1;min-width:160px}
.sum-card .sc-val{font-size:28px;font-weight:700;color:var(--primary,#1e6fff)}
.sum-card .sc-lbl{font-size:12px;color:var(--faint,#888);margin-top:4px;text-transform:uppercase;letter-spacing:.04em}
.provider-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600}
.badge-propstream{background:#e8f5e9;color:#2e7d32}
.badge-batchdata{background:#e3f2fd;color:#1565c0}
.badge-none{background:#fafafa;color:#999;border:1px solid #e4e7ec}
.billing-table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.billing-table thead th{background:#f5f7fa;text-align:left;padding:10px 14px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#555;border-bottom:1px solid #e4e7ec}
.billing-table tbody td{padding:10px 14px;font-size:14px;border-bottom:1px solid #f0f2f5}
.billing-table tbody tr:last-child td{border-bottom:none}
.billing-table tbody tr:hover{background:#fafbff}
.charge-cell{font-weight:700;color:#1a7c3e}
.tfoot-row td{background:#f5f7fa;font-weight:700;border-top:2px solid #d0d4db}
.empty-state{text-align:center;padding:60px 24px;color:#aaa;font-size:15px}
.export-btn{padding:7px 16px;border:1px solid #ddd;border-radius:7px;background:#fff;font-size:13px;cursor:pointer;text-decoration:none;color:#333}
.export-btn:hover{background:#f5f7fa}
.notice-banner{padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:20px}
.notice-warn{background:#fff8e1;border:1px solid #ffe082;color:#6d4c00}
.notice-ok{background:#e8f5e9;border:1px solid #a5d6a7;color:#1b5e20}
.usage-bar-wrap{width:100px;background:#eee;border-radius:4px;height:6px;display:inline-block;vertical-align:middle}
.usage-bar{height:6px;border-radius:4px;background:var(--primary,#1e6fff)}
</style>
</head>
<body>
<?php render_sidebar('listing_intel_billing', $agent); ?>
<main class="main-content">
  <div class="bo-header">
    <h1>Listing Intel Billing</h1>
    <span class="bo-meta"><?= htmlspecialchars($periodLabel) ?></span>
    <form class="period-form" method="get">
      <select name="period">
        <?php foreach ($availPeriods as $p): ?>
          <option value="<?= htmlspecialchars($p) ?>"<?= $p===$period?' selected':'' ?>><?= htmlspecialchars(date('F Y',strtotime($p.'-01'))) ?></option>
        <?php endforeach; ?>
        <?php if (!in_array(date('Y-m'),$availPeriods)): ?>
          <option value="<?= date('Y-m') ?>"<?= date('Y-m')===$period?' selected':'' ?>><?= date('F Y') ?></option>
        <?php endif; ?>
      </select>
      <button type="submit">View</button>
    </form>
    <?php if ($rows): ?>
      <a class="export-btn" href="?period=<?= urlencode($period) ?>&export_csv=1">Export CSV</a>
    <?php endif; ?>
  </div>

  <?php if ($hasCompanyKey): ?>
    <div class="notice-banner notice-ok">PropStream company account active — usage tracked per agent. Rate: $<?= number_format($costPerRec,2) ?>/record.</div>
  <?php else: ?>
    <div class="notice-banner notice-warn">No PropStream company key configured. Agents using their own BatchData keys are not billed through AgentEdge. Set <code>propstream_api_key</code> in config.php to enable centralized billing.</div>
  <?php endif; ?>

  <div class="summary-cards">
    <div class="sum-card">
      <div class="sc-val"><?= number_format($grandTotal) ?></div>
      <div class="sc-lbl">Records Pulled</div>
    </div>
    <div class="sum-card">
      <div class="sc-val">$<?= number_format($grandCharges,2) ?></div>
      <div class="sc-lbl">Total Charges</div>
    </div>
    <div class="sum-card">
      <div class="sc-val"><?= count($rows) ?></div>
      <div class="sc-lbl">Active Agents</div>
    </div>
    <div class="sum-card">
      <div class="sc-val">$<?= number_format($costPerRec,2) ?></div>
      <div class="sc-lbl">Rate / Record</div>
    </div>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty-state">No usage recorded for <?= htmlspecialchars($periodLabel) ?>.</div>
  <?php else: ?>
    <table class="billing-table">
      <thead>
        <tr>
          <th>Agent</th>
          <th>Email</th>
          <th>Records Pulled</th>
          <th>Syncs</th>
          <th>Last Sync</th>
          <th>Charge</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
          $charge = round($r['total_pulled'] * $costPerRec, 2);
          $pct    = $grandTotal > 0 ? round($r['total_pulled'] / $grandTotal * 100) : 0;
          $name   = htmlspecialchars($agentNames[$r['agent_email']] ?? '');
          $email  = htmlspecialchars($r['agent_email']);
        ?>
        <tr>
          <td><?= $name ?: '<span style="color:#aaa">—</span>' ?></td>
          <td style="color:#666;font-size:13px"><?= $email ?></td>
          <td>
            <?= number_format($r['total_pulled']) ?>
            <span class="usage-bar-wrap" title="<?= $pct ?>% of total">
              <span class="usage-bar" style="width:<?= $pct ?>%"></span>
            </span>
          </td>
          <td style="color:#888"><?= (int)$r['sync_count'] ?></td>
          <td style="color:#888;font-size:12px"><?= htmlspecialchars(substr($r['last_sync'],0,16)) ?></td>
          <td class="charge-cell">$<?= number_format($charge,2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="tfoot-row">
          <td colspan="2">Totals</td>
          <td><?= number_format($grandTotal) ?></td>
          <td><?= array_sum(array_column($rows,'sync_count')) ?></td>
          <td></td>
          <td class="charge-cell">$<?= number_format($grandCharges,2) ?></td>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>
</main>
</body>
</html>
