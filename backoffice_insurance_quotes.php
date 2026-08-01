<?php
// Pending loops where DotLoop's native "Insurance Quote Request" Detail field
// is Yes — see lib/dotloop.php's dotloop_extract_detail_field()/
// dotloop_sync_company_loops() for where property_address/mls_number/
// closing_date/purchase_price and participant phone numbers are captured.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/dotloop.php';

$agent = require_login();
if (!is_admin()) { http_response_code(403); exit('Forbidden'); }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$db = local_db();
$rows = $db->query(
    "SELECT loop_id, name, dl_updated, loop_url, property_address, mls_number, closing_date, purchase_price
     FROM dotloop_loops
     WHERE insurance_quote_requested = 'Yes' AND deal_stage = 'UNDER_CONTRACT'
     ORDER BY dl_updated DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$clientsByLoop = [];
$agentsByLoop  = [];
if ($rows) {
    $loopIds      = array_column($rows, 'loop_id');
    $placeholders = implode(',', array_fill(0, count($loopIds), '?'));

    // Buyer side only — this report is for whoever is about to own the
    // property, not the seller they're buying it from.
    $stmt = $db->prepare(
        "SELECT loop_id, name, email, phone FROM dotloop_loop_participants
         WHERE loop_id IN ({$placeholders}) AND role LIKE '%buyer%'"
    );
    $stmt->execute($loopIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $clientsByLoop[$p['loop_id']][] = $p;
    }

    // DotLoop's role label alone doesn't distinguish "our agent" from a
    // co-op agent at another brokerage — both can show up as e.g.
    // LISTING_AGENT/BUYER_AGENT. Cross-reference against tblstaff (AgentEdge's
    // own staff table) instead: a real INNOVATE agent's email will be in
    // there, a co-op agent's won't.
    $stmt = $db->prepare(
        "SELECT loop_id, name, email FROM dotloop_loop_participants
         WHERE loop_id IN ({$placeholders}) AND role LIKE '%agent%' AND email != ''"
    );
    $stmt->execute($loopIds);
    $agentCandidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($agentCandidates) {
        $emails = array_values(array_unique(array_column($agentCandidates, 'email')));
        $ph     = implode(',', array_fill(0, count($emails), '?'));
        $staffRows = db_query_safe("SELECT email FROM tblstaff WHERE email IN ({$ph})", $emails);
        $innovateEmails = array_flip(array_map(fn($r) => strtolower(trim($r['email'])), $staffRows));

        foreach ($agentCandidates as $a) {
            if (isset($innovateEmails[strtolower(trim($a['email']))])) {
                $agentsByLoop[$a['loop_id']][] = $a;
            }
        }
    }
}

function fmt_date(mixed $val): string {
    if (!$val) return '—';
    $ts = strtotime((string)$val);
    return $ts ? date('M j, Y', $ts) : h((string)$val);
}
function fmt_price(string $val): string {
    if ($val === '') return '—';
    $num = (float)str_replace([',', '$'], '', $val);
    return $num > 0 ? '$' . number_format($num) : h($val);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Insurance Quote Requests — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .iq-hero{background:linear-gradient(135deg,#1a1a1a 0%,#2d3a1e 100%);border-radius:12px;padding:24px 28px;color:white;margin-bottom:20px}
    .iq-hero-title{font-size:20px;font-weight:900;margin:0 0 4px}
    .iq-hero-sub{font-size:12px;color:rgba(255,255,255,.65);margin:0}
    .iq-table-wrap{overflow-x:auto;border-radius:12px;border:1px solid #e5e5e5;max-width:100%}
    .iq-table{width:100%;min-width:1100px;border-collapse:collapse;font-size:13px;background:#fff}
    .iq-table th{text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#999;padding:10px 14px;border-bottom:1px solid #eee;background:#fafafa;white-space:nowrap}
    .iq-table td{padding:12px 14px;border-bottom:1px solid #f5f5f5;vertical-align:top}
    .iq-table td.iq-nowrap{white-space:nowrap}
    .iq-table tr:last-child td{border-bottom:none}
    .iq-person{display:block;font-size:12px;font-weight:700;color:#111}
    .iq-sub{display:block;color:#888;font-size:11px;font-weight:400}
    .iq-empty{color:#aaa;font-size:13px;padding:40px 0;text-align:center}
    .iq-link{color:#82C112;font-weight:700;font-size:12px;text-decoration:none}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('bo_insurance_quotes', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Insurance Quote Requests</div>
    </header>
    <main class="wrap">

      <div class="iq-hero">
        <div class="iq-hero-title">Carolina Property Insurance Leads — Pending</div>
        <p class="iq-hero-sub">Pending (under contract) transactions that answered "Yes" to DotLoop's Insurance Quote Request field. A new "Yes" auto-emails <?= h(dotloop_insurance_notify_email()) ?>.</p>
      </div>

      <?php if (!$rows): ?>
      <div class="iq-empty">No pending transactions currently flagged for an insurance quote.</div>
      <?php else: ?>
      <div class="iq-table-wrap">
      <table class="iq-table">
        <thead>
          <tr>
            <th>Client</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Address</th>
            <th>MLS #</th>
            <th>Referring Agent</th>
            <th>Closing Date</th>
            <th>Purchase Price</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $clients = $clientsByLoop[$r['loop_id']] ?? [];
            $agents  = $agentsByLoop[$r['loop_id']] ?? [];
            $address = $r['property_address'] ?: ($r['name'] ?: 'Unnamed Loop');
          ?>
          <tr>
            <td class="iq-nowrap">
              <?php if (!$clients): ?>
                <span style="color:#aaa;">—</span>
              <?php else: foreach ($clients as $c): ?>
                <span class="iq-person"><?= h($c['name'] ?: '(no name)') ?></span>
              <?php endforeach; endif; ?>
            </td>
            <td class="iq-nowrap">
              <?php if (!$clients): ?>—<?php else: foreach ($clients as $c): ?>
                <span class="iq-sub"><?= h($c['phone'] ?: '—') ?></span>
              <?php endforeach; endif; ?>
            </td>
            <td>
              <?php if (!$clients): ?>—<?php else: foreach ($clients as $c): ?>
                <span class="iq-sub"><?= h($c['email'] ?: '—') ?></span>
              <?php endforeach; endif; ?>
            </td>
            <td><?= h($address) ?></td>
            <td class="iq-nowrap"><?= h($r['mls_number'] ?: '—') ?></td>
            <td>
              <?php if (!$agents): ?>
                <span style="color:#aaa;">—</span>
              <?php else: foreach ($agents as $a): ?>
                <span class="iq-person"><?= h($a['name'] ?: $a['email']) ?></span>
              <?php endforeach; endif; ?>
            </td>
            <td class="iq-nowrap"><?= fmt_date($r['closing_date']) ?></td>
            <td class="iq-nowrap"><?= fmt_price((string)$r['purchase_price']) ?></td>
            <td class="iq-nowrap"><?php if ($r['loop_url']): ?><a class="iq-link" href="<?= h($r['loop_url']) ?>" target="_blank" rel="noopener">View</a><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>

    </main>
  </div>
</div>
</body>
</html>
