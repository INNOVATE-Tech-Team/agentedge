<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/nav.php';

$agent = require_login();

$ALLOWED = ['darren@darrenwoodard.com', 'darren@innovateonline.com', 'michele@innovateonline.com'];
if (!in_array(strtolower($agent['email']), $ALLOWED, true)) {
    header('Location: index.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Feb 2026 Reconciliation Report — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
<style>
.recon-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--faint)}
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;
  color:var(--green-d);text-decoration:none;margin-bottom:18px}
.back-link:hover{text-decoration:underline}

/* Stat band */
.stat-band{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
@media(max-width:700px){.stat-band{grid-template-columns:repeat(2,1fr)}}
.stat-chip{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 16px}
.stat-chip .sc-val{font-size:22px;font-weight:800}
.stat-chip .sc-lbl{font-size:11px;color:var(--faint);margin-top:2px;text-transform:uppercase;letter-spacing:.05em}
.stat-chip.s-red .sc-val{color:var(--red)}
.stat-chip.s-green .sc-val{color:var(--green-d)}
.stat-chip.s-amber .sc-val{color:var(--amber)}

/* Tabs */
.tab-bar{display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:16px;overflow-x:auto}
.tab-btn{padding:9px 18px;border:0;background:none;font-size:13px;font-weight:700;
  color:var(--faint);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap}
.tab-btn:hover{color:var(--ink)}
.tab-btn.active{color:var(--green-d);border-bottom-color:var(--green)}
.tab-badge{display:inline-flex;align-items:center;justify-content:center;
  min-width:18px;height:18px;border-radius:9px;font-size:10px;font-weight:800;
  padding:0 5px;margin-left:6px}
.tab-badge.red{background:#fdecea;color:var(--red)}
.tab-badge.green{background:#eaf5e2;color:var(--green-d)}
.tab-badge.amber{background:#fdf5e0;color:var(--amber)}
.tab-panel{display:none}.tab-panel.active{display:block}

/* Issue cards */
.issue-card{border-radius:10px;padding:14px 16px;margin-bottom:10px;border:1px solid transparent;
  display:grid;grid-template-columns:1fr auto;gap:12px;align-items:flex-start}
.issue-card.critical{background:#fef2f2;border-color:#fecaca;border-left:4px solid var(--red)}
.issue-card.warn{background:#fffbeb;border-color:#fde68a;border-left:4px solid var(--amber)}
.issue-card.review{background:#eff6ff;border-color:#bfdbfe;border-left:4px solid #3b82f6}
.ic-title{font-size:13px;font-weight:700;margin-bottom:4px}
.ic-body{font-size:12px;color:var(--muted);line-height:1.5}
.ic-tag{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;
  padding:2px 8px;border-radius:4px;white-space:nowrap}
.tag-red{background:#fecaca;color:var(--red)}
.tag-amber{background:#fde68a;color:#92400e}
.tag-blue{background:#bfdbfe;color:#1e40af}

/* Transaction table */
.tx-table{width:100%;border-collapse:collapse;font-size:12px}
.tx-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;
  color:var(--faint);border-bottom:1px solid var(--border);padding:6px 10px;text-align:left}
.tx-table td{padding:8px 10px;border-bottom:1px solid #f2f2f2;vertical-align:middle}
.tx-table tr:last-child td{border-bottom:none}
.tx-table tr:hover td{background:#fafbfa}
.tx-table tr.done-row td{opacity:.5;text-decoration:line-through}
.tx-check{width:20px;height:20px;accent-color:var(--green);cursor:pointer}
.tx-amt{font-family:monospace;font-weight:700;white-space:nowrap}
.tx-amt.out{color:var(--red)}.tx-amt.in{color:var(--green-d)}
.tx-date{font-family:monospace;font-size:11px;color:var(--faint);white-space:nowrap}

/* Balance panel */
.bal-panel{background:#fff;border:1px solid var(--border);border-radius:10px;padding:20px}
.bal-row{display:flex;justify-content:space-between;align-items:center;
  padding:10px 0;border-bottom:1px solid #f2f2f2;font-size:14px}
.bal-row:last-child{border-bottom:none}
.bal-val{font-family:monospace;font-weight:700}
.bal-lbl{color:var(--muted)}

/* Steps */
.step-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px}
.step-item{display:grid;grid-template-columns:32px 1fr;gap:10px;align-items:flex-start;
  background:#fff;border:1px solid var(--border);border-radius:8px;padding:12px;
  cursor:pointer;user-select:none;-webkit-user-select:none}
.step-item.done{background:#f5fbf0;border-color:#c6e9a0}
.step-num{width:28px;height:28px;border-radius:50%;background:#111;color:#fff;
  font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.step-item.done .step-num{background:var(--green-d)}
.step-title{font-size:13px;font-weight:700;line-height:1.3}
.step-item.done .step-title{text-decoration:line-through;color:var(--faint)}
.step-sub{font-size:12px;color:var(--faint);margin-top:3px;line-height:1.5}
.darwin-path{display:inline-flex;align-items:center;background:#111;border-radius:4px;
  padding:4px 10px;margin:6px 0 2px;flex-wrap:wrap}
.darwin-path .seg{font-family:monospace;font-size:11px;color:#93c5fd;font-weight:700}
.darwin-path .arr{font-size:10px;color:rgba(255,255,255,.25);padding:0 5px}
.enter-box{display:flex;align-items:center;gap:8px;background:#ebf5ff;border:1px solid #bbd6f5;
  border-radius:4px;padding:7px 12px;margin:5px 0 2px}
.enter-box .el{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
  color:#0369a1;flex-shrink:0;min-width:130px}
.enter-box .ev{font-family:monospace;font-size:13px;font-weight:700}

.progress-bar{height:5px;background:var(--border);border-radius:3px;margin:10px 0}
.progress-fill{height:100%;background:var(--green);border-radius:3px;transition:width .3s}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('reconciliation', $agent); ?>
<div class="content">

<div class="content-top">
  <div>
    <div class="recon-eyebrow">Back Office › Bank Reconciliation</div>
    <div class="content-title">February 2026 — Analysis Report</div>
  </div>
  <div style="font-size:12px;color:var(--faint)">CCNB Account xxxxxx8788 · Period: 1/31–2/27/2026</div>
</div>

<div class="wrap">
  <a href="reconciliation.php" class="back-link">← Back to Reconciliation</a>

  <!-- Stat band -->
  <div class="stat-band">
    <div class="stat-chip s-red">
      <div class="sc-val">13</div>
      <div class="sc-lbl">Add to Darwin</div>
    </div>
    <div class="stat-chip s-amber">
      <div class="sc-val">3</div>
      <div class="sc-lbl">Needs Review</div>
    </div>
    <div class="stat-chip">
      <div class="sc-val">1</div>
      <div class="sc-lbl">Outstanding</div>
    </div>
    <div class="stat-chip s-green">
      <div class="sc-val" id="clearDone">0</div>
      <div class="sc-lbl">Cleared So Far</div>
    </div>
  </div>

  <!-- Account summary -->
  <div class="card" style="margin-bottom:16px">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0;text-align:center">
      <div style="padding:4px 0;border-right:1px solid var(--border)">
        <div style="font-size:11px;color:var(--faint);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">Beginning Balance</div>
        <div style="font-size:18px;font-weight:800;font-family:monospace">$144,295.56</div>
      </div>
      <div style="padding:4px 0;border-right:1px solid var(--border)">
        <div style="font-size:11px;color:var(--faint);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">Ending Balance</div>
        <div style="font-size:18px;font-weight:800;font-family:monospace">$167,118.95</div>
      </div>
      <div style="padding:4px 0">
        <div style="font-size:11px;color:var(--faint);text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px">Statement Date</div>
        <div style="font-size:18px;font-weight:800;font-family:monospace">02/27/2026</div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="card" style="padding:0;overflow:hidden">
    <div class="tab-bar" style="padding:0 16px">
      <button class="tab-btn active" onclick="showTab('tabAction',this)">
        Action Required <span class="tab-badge red">13</span>
      </button>
      <button class="tab-btn" onclick="showTab('tabReview',this)">
        Needs Review <span class="tab-badge" style="background:#dbeafe;color:#1e40af">3</span>
      </button>
      <button class="tab-btn" onclick="showTab('tabClear',this)">
        Clear in Darwin <span class="tab-badge green" id="badgeClear">50</span>
      </button>
      <button class="tab-btn" onclick="showTab('tabBalance',this)">Balance Check</button>
      <button class="tab-btn" onclick="showTab('tabWalk',this)">Walkthrough</button>
    </div>

    <div style="padding:16px">

      <!-- ACTION REQUIRED -->
      <div class="tab-panel active" id="tabAction">
        <p style="font-size:13px;color:var(--faint);margin:0 0 14px">These hit the bank in February but have no Darwin entry. Add each one to Darwin before opening the reconciliation screen. Check them off as you go.</p>

        <!-- Critical: duplicate wires -->
        <div class="issue-card critical" id="ac1" onclick="toggleIssue('ac1')">
          <div>
            <div class="ic-title">⚠ Verify Wires 95013083 &amp; 95013085 before recording — both $14,625.00 on 2/26</div>
            <div class="ic-body">Two wires, same amount, same day, consecutive IDs. Call CCNB (843-839-2265) to confirm these are two separate legitimate closings — not one wire processed twice. Only add to Darwin after confirming.</div>
          </div>
          <span class="ic-tag tag-red">Critical</span>
        </div>

        <!-- Critical: ACH return -->
        <div class="issue-card critical" id="ac2" onclick="toggleIssue('ac2')">
          <div>
            <div class="ic-title">ACH Return — Sacks, Joe &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--green-d)">+$2,668.47</span> &nbsp;(2/26)</div>
            <div class="ic-body">A payment originally sent to Sacks was returned by the bank on 2/26. Decide: re-collect, or write it off. Void or reverse the original AP check in Darwin, then follow up with Sacks.</div>
          </div>
          <span class="ic-tag tag-red">Action</span>
        </div>

        <!-- Incoming wires unrecorded -->
        <div class="issue-card warn" id="ac3" onclick="toggleIssue('ac3')">
          <div>
            <div class="ic-title">Add Incoming Wire 94782532 &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--green-d)">+$20,295.00</span> &nbsp;(2/19)</div>
            <div class="ic-body">No Darwin deposit matches this wire. Check DotLoop/email for a closing around 2/17–2/19. Once identified, add as a deposit under that transaction.</div>
          </div>
          <span class="ic-tag tag-amber">Add Deposit</span>
        </div>

        <div class="issue-card warn" id="ac4" onclick="toggleIssue('ac4')">
          <div>
            <div class="ic-title">Add Incoming Wire 94819677 &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--green-d)">+$18,497.50</span> &nbsp;(2/20)</div>
            <div class="ic-body">No Darwin match. Check closings from 2/18–2/20.</div>
          </div>
          <span class="ic-tag tag-amber">Add Deposit</span>
        </div>

        <div class="issue-card warn" id="ac5" onclick="toggleIssue('ac5')">
          <div>
            <div class="ic-title">Add Incoming Wire 94831363 &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--green-d)">+$3,982.50</span> &nbsp;(2/20)</div>
            <div class="ic-body">No Darwin match. Could be referral fee, earnest money, or partial closing deposit. Check closings from 2/18–2/20.</div>
          </div>
          <span class="ic-tag tag-amber">Add Deposit</span>
        </div>

        <div class="issue-card warn" id="ac6" onclick="toggleIssue('ac6')">
          <div>
            <div class="ic-title">BCBS Premium Refund &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--green-d)">+$715.61</span> &nbsp;(2/19)</div>
            <div class="ic-body">Insurance refund posted to bank 2/19. Add as income via Accounting → Journal Entry, credit Insurance Income.</div>
          </div>
          <span class="ic-tag tag-amber">Add Journal</span>
        </div>

        <div class="issue-card warn" id="ac7" onclick="toggleIssue('ac7')">
          <div>
            <div class="ic-title">Add Real Estate Digi Cash expense &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--red)">−$7,436.06</span> &nbsp;(2/18)</div>
            <div class="ic-body">Ref: RED113963. Marketing or TC vendor. Identify and record under the correct expense category.</div>
          </div>
          <span class="ic-tag tag-amber">Add Expense</span>
        </div>

        <div class="issue-card warn" id="ac8" onclick="toggleIssue('ac8')">
          <div>
            <div class="ic-title">Add "Kroger" online bill pay &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--red)">−$3,732.58</span> &nbsp;(2/20)</div>
            <div class="ic-body">This is NOT a grocery store — it came through CCNB's bill pay system (same channel as Firethorne and Pawleys Parish). Log in to CCNB Online Banking → Bill Pay → Payment History on 2/20 to find the real payee name, then record in Darwin.</div>
          </div>
          <span class="ic-tag tag-amber">Identify &amp; Add</span>
        </div>

        <div class="issue-card warn" id="ac9" onclick="toggleIssue('ac9')">
          <div>
            <div class="ic-title">Principal Life insurance &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--red)">−$1,100.37</span> &nbsp;(2/20)</div>
            <div class="ic-body">Two auto-debits: Group Life $1,044.24 + Dental $56.13. Record both as separate expense entries under Benefits / Insurance.</div>
          </div>
          <span class="ic-tag tag-amber">Add Expense</span>
        </div>

        <div class="issue-card warn" id="ac10" onclick="toggleIssue('ac10')">
          <div>
            <div class="ic-title">HCJ Inc. &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--red)">−$600.00</span> &nbsp;(2/20)</div>
            <div class="ic-body">Online bill pay vendor. Identify (possibly cleaning, maintenance, or office) and record under the correct account.</div>
          </div>
          <span class="ic-tag tag-amber">Add Expense</span>
        </div>

        <div class="issue-card warn" id="ac11" onclick="toggleIssue('ac11')">
          <div>
            <div class="ic-title">Capital One Sparks Visa partial payment &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--red)">−$5,000.00</span> &nbsp;(2/23)</div>
            <div class="ic-body">Darwin has the 2/27 full-balance payment ($24,656.32) but not this earlier $5,000 partial. Add as a credit card payment dated 2/23.</div>
          </div>
          <span class="ic-tag tag-amber">Add Payment</span>
        </div>

        <div class="issue-card warn" id="ac12" onclick="toggleIssue('ac12')">
          <div>
            <div class="ic-title">Transfer to account 8025052158 &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--red)">−$20,000.00</span> &nbsp;(2/23)</div>
            <div class="ic-body">Bank shows a second transfer to this account on 2/23 (Darwin already has the 2/17 transfer of $25,000). Add the 2/23 transfer.</div>
          </div>
          <span class="ic-tag tag-amber">Add Transfer</span>
        </div>

        <div class="issue-card warn" id="ac13" onclick="toggleIssue('ac13')">
          <div>
            <div class="ic-title">Wires 95013083 &amp; 95013085 — add after confirming with CCNB &nbsp;<span style="font-family:monospace;font-weight:700;color:var(--green-d)">+$14,625.00 each</span> &nbsp;(2/26)</div>
            <div class="ic-body">Only add these AFTER calling CCNB to confirm both are legitimate separate wires (see first item above). If confirmed, add two separate $14,625 deposits dated 2/26.</div>
          </div>
          <span class="ic-tag tag-amber">Add After Verify</span>
        </div>
      </div>

      <!-- NEEDS REVIEW -->
      <div class="tab-panel" id="tabReview">
        <p style="font-size:13px;color:var(--faint);margin:0 0 14px">These Darwin entries need to be investigated or corrected before the reconciliation will balance.</p>

        <div class="issue-card review">
          <div>
            <div class="ic-title">JournalId:11 — "Unclear deposit for $8,400" (Darwin's own label)</div>
            <div class="ic-body">Darwin already has this entry (2/17), split into four lines: $10 + $485 + $1,260 + $6,645. No bank transaction matching the full amount was found. Find what original deposit this represents, reclassify it to the correct transaction, and update the description so it matches the bank.</div>
          </div>
          <span class="ic-tag tag-blue">Investigate</span>
        </div>

        <div class="issue-card review">
          <div>
            <div class="ic-title">Deposit #37 — date mismatch &nbsp;<span style="font-family:monospace">$17,575.00</span></div>
            <div class="ic-body">Darwin records this deposit on 2/25 but the bank received it as an incoming wire on 2/20. Update the date in Darwin to 2/20 so it reconciles correctly to the bank statement.</div>
          </div>
          <span class="ic-tag tag-blue">Fix Date in Darwin</span>
        </div>

        <div class="issue-card review">
          <div>
            <div class="ic-title">6303 Wildwood Trail — two AP Checks for $11,124.50 each (Checks #16 &amp; #17)</div>
            <div class="ic-body">Same payee (Mark Loomis), same address, same amount. If this is one closing with two disbursements (e.g., buyer and seller both receiving funds), both are correct. If it's a data entry duplicate, delete one. Check the transaction in Darwin to confirm.</div>
          </div>
          <span class="ic-tag tag-blue">Verify in Darwin</span>
        </div>
      </div>

      <!-- CLEAR IN DARWIN -->
      <div class="tab-panel" id="tabClear">
        <p style="font-size:13px;color:var(--faint);margin:0 0 10px">Find each of these in Darwin's uncleared list and check it off. Your progress is saved automatically.</p>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;flex-wrap:wrap">
          <div style="font-size:12px;color:var(--faint)" id="clearProgress">0 of 50 marked cleared</div>
          <div style="width:160px"><div class="progress-bar"><div class="progress-fill" id="clearFill" style="width:0%"></div></div></div>
        </div>
        <div style="overflow-x:auto"><table class="tx-table">
          <thead><tr>
            <th style="width:34px">Done</th><th>Date</th><th>Description</th><th>Amount</th><th>Darwin Ref</th>
          </tr></thead>
          <tbody id="clearBody">
          </tbody>
        </table></div>
      </div>

      <!-- BALANCE CHECK -->
      <div class="tab-panel" id="tabBalance">
        <div class="bal-panel">
          <div class="bal-row"><span class="bal-lbl">Statement Beginning Balance</span><span class="bal-val">$144,295.56</span></div>
          <div class="bal-row"><span class="bal-lbl">Total Credits (all deposits in)</span><span class="bal-val" style="color:var(--green-d)">+$1,301,867.19</span></div>
          <div class="bal-row"><span class="bal-lbl">Total Debits (all payments out)</span><span class="bal-val" style="color:var(--red)">−$1,279,043.80</span></div>
          <div class="bal-row"><span class="bal-lbl">Statement Ending Balance</span><span class="bal-val">$167,118.95</span></div>
          <div class="bal-row" style="border-top:2px solid var(--border);padding-top:14px;margin-top:4px">
            <span class="bal-lbl">Outstanding check (AP #1476, Kris Fuller — not cleared)</span>
            <span class="bal-val" style="color:var(--amber)">−$34.88</span>
          </div>
          <div class="bal-row">
            <span class="bal-lbl" style="font-weight:700">Reconciliation difference after outstanding (target: $0.00)</span>
            <span class="bal-val" style="font-size:18px;color:var(--green-d)">✓ $0.00</span>
          </div>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;margin-top:12px;font-size:13px;color:#166534">
          ✓ When all 13 missing entries are added to Darwin and Kris Fuller's check remains outstanding, the reconciliation should balance to exactly $0.00.
        </div>
      </div>

      <!-- WALKTHROUGH -->
      <div class="tab-panel" id="tabWalk">
        <p style="font-size:13px;color:var(--faint);margin:0 0 14px">Step-by-step for February 2026. Check each step off as you complete it.</p>
        <ul class="step-list">
          <li class="step-item" id="ws1" onclick="toggleStep('ws1')">
            <div class="step-num" data-n="1">1</div>
            <div>
              <div class="step-title">Call CCNB to verify the duplicate $14,625 wires (95013083 &amp; 95013085)</div>
              <div class="step-sub">843-839-2265. Confirm they are two separate legitimate wire transfers before recording either one. If it's a duplicate, have them reverse one immediately.</div>
            </div>
          </li>
          <li class="step-item" id="ws2" onclick="toggleStep('ws2')">
            <div class="step-num" data-n="2">2</div>
            <div>
              <div class="step-title">Add all 13 missing entries to Darwin</div>
              <div class="step-sub">Work through the Action Required tab. Add each unrecorded item before opening the reconciliation screen. The 5 unidentified wires — check DotLoop for matching closings by amount.</div>
            </div>
          </li>
          <li class="step-item" id="ws3" onclick="toggleStep('ws3')">
            <div class="step-num" data-n="3">3</div>
            <div>
              <div class="step-title">Fix the $8,400 "Unclear deposit" (JournalId:11)</div>
              <div class="step-sub">Find the original deposit this journal represents and reclassify the four lines ($10 + $485 + $1,260 + $6,645) to the correct transaction. Update the description.</div>
            </div>
          </li>
          <li class="step-item" id="ws4" onclick="toggleStep('ws4')">
            <div class="step-num" data-n="4">4</div>
            <div>
              <div class="step-title">Update Deposit #37 date from 2/25 to 2/20</div>
              <div class="step-sub">The $17,575 deposit shows 2/25 in Darwin but the wire arrived 2/20 on the bank statement. Open the deposit in Darwin and correct the date.</div>
            </div>
          </li>
          <li class="step-item" id="ws5" onclick="toggleStep('ws5')">
            <div class="step-num" data-n="5">5</div>
            <div>
              <div class="step-title">Confirm the Wildwood Trail duplicate (AP Checks #16 &amp; #17)</div>
              <div class="step-sub">Open both checks in Darwin. If both are real (two parties in one closing), leave both. If it's a duplicate entry, delete one before reconciling.</div>
            </div>
          </li>
          <li class="step-item" id="ws6" onclick="toggleStep('ws6')">
            <div class="step-num" data-n="6">6</div>
            <div>
              <div class="step-title">Open Bank Reconciliation in Darwin</div>
              <div class="darwin-path"><span class="seg">Banking</span><span class="arr">›</span><span class="seg">Bank Accounts</span><span class="arr">›</span><span class="seg">Select Operating Account</span><span class="arr">›</span><span class="seg">Reconcile</span></div>
            </div>
          </li>
          <li class="step-item" id="ws7" onclick="toggleStep('ws7')">
            <div class="step-num" data-n="7">7</div>
            <div>
              <div class="step-title">Enter statement date and ending balance</div>
              <div class="enter-box"><span class="el">Statement Date</span><span class="ev">02/27/2026</span></div>
              <div class="enter-box"><span class="el">Ending Balance</span><span class="ev">$167,118.95</span></div>
              <div class="enter-box"><span class="el">Beginning Balance</span><span class="ev">$144,295.56</span></div>
            </div>
          </li>
          <li class="step-item" id="ws8" onclick="toggleStep('ws8')">
            <div class="step-num" data-n="8">8</div>
            <div>
              <div class="step-title">Mark all 50 matched transactions as cleared</div>
              <div class="step-sub">Use the "Clear in Darwin" tab to track your progress as you work through Darwin's uncleared list.</div>
            </div>
          </li>
          <li class="step-item" id="ws9" onclick="toggleStep('ws9')">
            <div class="step-num" data-n="9">9</div>
            <div>
              <div class="step-title">Leave AP Check #1476 (Kris Fuller, $34.88) unchecked</div>
              <div class="step-sub">This check was written 2/17 but has not cleared the bank. Leave it outstanding — Darwin will carry it to the March statement.</div>
            </div>
          </li>
          <li class="step-item" id="ws10" onclick="toggleStep('ws10')">
            <div class="step-num" data-n="10">10</div>
            <div>
              <div class="step-title">Verify the difference shows $0.00</div>
              <div class="step-sub">Darwin's running difference should read exactly zero. If not, a missing entry or wrong amount is the usual cause — check the Action Required list.</div>
            </div>
          </li>
          <li class="step-item" id="ws11" onclick="toggleStep('ws11')">
            <div class="step-num" data-n="11">11</div>
            <div>
              <div class="step-title">Click Post / Finish to complete the reconciliation</div>
              <div class="darwin-path"><span class="seg">Reconciliation screen</span><span class="arr">›</span><span class="seg">Post Reconciliation</span></div>
            </div>
          </li>
          <li class="step-item" id="ws12" onclick="toggleStep('ws12')">
            <div class="step-num" data-n="12">12</div>
            <div>
              <div class="step-title">Save and file the reconciliation report</div>
              <div class="step-sub">Export as PDF and file it with the February CCNB bank statement.</div>
              <div class="darwin-path"><span class="seg">Reports</span><span class="arr">›</span><span class="seg">Bank Reconciliation</span><span class="arr">›</span><span class="seg">Feb 2026</span><span class="arr">›</span><span class="seg">Print / PDF</span></div>
            </div>
          </li>
        </ul>
      </div>

    </div><!-- /tab content -->
  </div><!-- /card -->
</div><!-- /wrap -->
</div><!-- /content -->
</div><!-- /layout -->

<script>
const CHECKED = JSON.parse(localStorage.getItem('recon-feb2026') || '{}');

// ── Confirmed matches: Darwin entries that appeared in the CCNB statement ──
const CLEAR_ROWS = [
  {date:'2/17',desc:'City of Myrtle Beach — Water & Sewer',amt:425.07,out:true,ref:'Journal'},
  {date:'2/17',desc:'Santee Cooper Utility',amt:1634.09,out:true,ref:'Journal'},
  {date:'2/17',desc:'The Hartford Insurance',amt:129.61,out:true,ref:'Journal'},
  {date:'2/17',desc:'Canva (subscription)',amt:12.95,out:true,ref:'Journal'},
  {date:'2/17',desc:'Kroger POS debit',amt:29.35,out:true,ref:'Journal'},
  {date:'2/17',desc:'Walmart.com POS debit',amt:45.23,out:true,ref:'Journal'},
  {date:'2/17',desc:'BCAR / IL REALTOR ASSOCIA',amt:101.66,out:true,ref:'Journal'},
  {date:'2/17',desc:'Transfer to savings account 8025052158',amt:25000.00,out:true,ref:'Journal J295'},
  {date:'2/18',desc:'Pitney Bowes Leasing',amt:178.50,out:true,ref:'Journal'},
  {date:'2/18',desc:'Boyd Real Estate Team LLC',amt:4336.46,out:true,ref:'AP Check VV10298'},
  {date:'2/18',desc:'Shayne Steiner Real Estate LLC',amt:4634.65,out:true,ref:'AP Check VV10301'},
  {date:'2/18',desc:'Brooks Realty Group LLC',amt:3172.05,out:true,ref:'AP Check VV10303'},
  {date:'2/18',desc:'Eric Shenberger',amt:1182.00,out:true,ref:'AP Check VV10293'},
  {date:'2/18',desc:'Jorgiane Silva',amt:420.00,out:true,ref:'AP Check 17543'},
  {date:'2/18',desc:'1625 S Ocean Blvd #1402 — Sandy Bishop',amt:15725.00,out:false,ref:'Deposit'},
  {date:'2/18',desc:'Low-balance auto-transfer from operating',amt:20000.00,out:false,ref:'Deposit J295'},
  {date:'2/19',desc:'Intuit Transaction Fee',amt:1.00,out:true,ref:'Journal'},
  {date:'2/19',desc:'997 Flat Top Road — Daniel Murphy',amt:1400.00,out:false,ref:'Deposit'},
  {date:'2/19',desc:'635 Coral Bells — Shelley Monahan',amt:16035.45,out:false,ref:'Deposit'},
  {date:'2/19',desc:'2463 Hunters Trail — Larisa Esmat',amt:10275.00,out:false,ref:'Deposit'},
  {date:'2/19',desc:'629 Cordova Court, Salisbury NC',amt:5107.50,out:false,ref:'Deposit'},
  {date:'2/19',desc:'2363 68th Ave S, St. Petersburg FL',amt:250.00,out:false,ref:'Deposit'},
  {date:'2/19',desc:'5400 Little River Neck Rd — Jade Iglesias',amt:3450.00,out:false,ref:'Deposit'},
  {date:'2/19',desc:'807 Saint Charles Dr, Tarpon Springs FL',amt:1052.50,out:false,ref:'Deposit'},
  {date:'2/19',desc:'1314 N Ocean Blvd #704 — Sean Brooks',amt:3600.00,out:false,ref:'Deposit'},
  {date:'2/19',desc:'331 Emery Oak Dr — Yvonne Guthrie',amt:11700.00,out:false,ref:'Deposit'},
  {date:'2/19',desc:'572 Swaying Palm Ct — Noah Livingston',amt:5812.50,out:false,ref:'Deposit'},
  {date:'2/19',desc:'6303 Wildwood Trail — Mark Loomis',amt:23420.00,out:false,ref:'Deposit'},
  {date:'2/20',desc:'BCBS SC ICHRA Settlement',amt:3600.00,out:true,ref:'Journal'},
  {date:'2/20',desc:'Firethorne Properties LLC (bill pay)',amt:4175.00,out:true,ref:'Journal'},
  {date:'2/20',desc:'Pawleys Parish LLC (bill pay)',amt:2938.00,out:true,ref:'Journal'},
  {date:'2/20',desc:'D&P Shine Inc. — 635 Coral Bells',amt:3407.53,out:true,ref:'AP Check 17291'},
  {date:'2/20',desc:'1625 S Ocean Blvd #1002 (wire 2/20, date corrected from 2/25)',amt:17575.00,out:false,ref:'Deposit #37'},
  {date:'2/24',desc:'Pee Dee Office Solutions',amt:167.14,out:true,ref:'AP Check 17289'},
  {date:'2/24',desc:'Peace Sotheby\'s International',amt:2186.88,out:true,ref:'AP Check 17290'},
  {date:'2/24',desc:'1100 Mary Read Dr, North Myrtle Beach',amt:20900.00,out:false,ref:'Deposit'},
  {date:'2/24',desc:'651 Summer, Conway — Derek Kouche',amt:4700.00,out:false,ref:'Deposit'},
  {date:'2/24',desc:'2881 Dessert Rose St, Little River',amt:7500.00,out:false,ref:'Deposit'},
  {date:'2/24',desc:'1103 Tibetan St, Conway — Derek Kouche',amt:6309.08,out:false,ref:'Deposit'},
  {date:'2/25',desc:'CCAR / CCMLS (Realtor Association)',amt:272.00,out:true,ref:'Journal'},
  {date:'2/25',desc:'133 Coyatee Circle, Loudon TN',amt:49000.00,out:false,ref:'Deposit'},
  {date:'2/25',desc:'919 N Waccamaw Dr — Krista Knight',amt:25625.00,out:false,ref:'Deposit'},
  {date:'2/25',desc:'920 N Waccamaw Dr, Murrells Inlet',amt:7200.00,out:false,ref:'Deposit'},
  {date:'2/25',desc:'2488 Goldfinch Dr, Myrtle Beach',amt:13975.00,out:false,ref:'Deposit'},
  {date:'2/25',desc:'4032 Viola Loop, Myrtle Beach',amt:14397.90,out:false,ref:'Deposit'},
  {date:'2/26',desc:'Domino\'s Pizza',amt:75.88,out:true,ref:'Journal'},
  {date:'2/26',desc:'New Account Opening Balance',amt:100.00,out:true,ref:'Journal'},
  {date:'2/26',desc:'Payroll — AccuChex Transfer (J414)',amt:10539.62,out:true,ref:'Journal J414'},
  {date:'2/26',desc:'Casterline Real Estate Inc.',amt:573.00,out:true,ref:'AP Check VV10294'},
  {date:'2/26',desc:'128 Harvest Gold Dr, Conway',amt:9800.00,out:false,ref:'Deposit'},
  {date:'2/26',desc:'3979 Landisville Rd, Doylestown PA — Amanda Moeser',amt:18745.00,out:false,ref:'Deposit'},
  {date:'2/26',desc:'210 75th Ave N #4083 — Kris Fuller',amt:5045.00,out:false,ref:'Deposit'},
  {date:'2/26',desc:'TBD Bellaire Dr, Nichols — Jade Iglesias',amt:2100.00,out:false,ref:'Deposit'},
  {date:'2/26',desc:'2090 Cross Gate Blvd — Derek MacLeod',amt:4100.00,out:false,ref:'Deposit'},
  {date:'2/26',desc:'TBD Hickory Dr — Alison Pavy',amt:10000.00,out:false,ref:'Deposit'},
  {date:'2/26',desc:'Rachel Haselden — 8 commission checks × $350',amt:2800.00,out:true,ref:'AP Checks'},
  {date:'2/27',desc:'Capital One Sparks Visa (full balance)',amt:24656.32,out:true,ref:'Journal'},
  {date:'2/27',desc:'CCNB Cash Management Fees',amt:60.00,out:true,ref:'Journal'},
  {date:'2/27',desc:'Hyfin / Clearent Electronic Fee',amt:61.23,out:true,ref:'Journal'},
  {date:'2/27',desc:'CCNB Remote Deposit Lease',amt:50.00,out:true,ref:'Journal'},
  {date:'2/27',desc:'Payroll — AccuChex (J415 + J416)',amt:3114.42,out:true,ref:'Journal'},
  {date:'2/27',desc:'Sean Michael Collins',amt:2531.25,out:true,ref:'AP Check VV10284'},
  {date:'2/27',desc:'Adriana Vidal',amt:3064.99,out:true,ref:'AP Check VV10287'},
  {date:'2/27',desc:'New Millennium Re Team',amt:1950.00,out:true,ref:'AP Check 17286'},
  {date:'2/27',desc:'2208 Tidewatch Way — Corey Richardson',amt:8600.00,out:false,ref:'Deposit'},
  {date:'2/27',desc:'866 Farmers Passage Loop — Lisa Smith',amt:8870.00,out:false,ref:'Deposit'},
  {date:'2/27',desc:'301 N 3rd St, Telford PA',amt:14349.00,out:false,ref:'Deposit'},
  {date:'2/27',desc:'1362 Speedway St — Larisa Esmat',amt:5875.00,out:false,ref:'Deposit'},
  {date:'2/27',desc:'4502 Mallard St — Noah Livingston',amt:2650.00,out:false,ref:'Deposit'},
  {date:'2/27',desc:'2408 Heritage Loop — Eddie Boyd',amt:7600.00,out:false,ref:'Deposit'},
  {date:'2/27',desc:'TBD Loop Rd, Loris — Tonya Allen',amt:1620.00,out:false,ref:'Deposit'},
  {date:'2/27',desc:'4655 Wild Iris Dr — Rebecca Lewis',amt:6766.50,out:false,ref:'Deposit'},
  {date:'2/27',desc:'1853 Willowcress Ln — Eddie Boyd',amt:13725.00,out:false,ref:'Deposit'},
  {date:'2/27',desc:'920 N Waccamaw 2-301 — Jeffrey Casterline',amt:4785.00,out:false,ref:'Deposit'},
  {date:'2/27',desc:'627 Carolina Farms Blvd — Andrew Bennett',amt:11062.50,out:false,ref:'Deposit'},
];

function fmt(n) { return n.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function buildClearTable() {
  const tbody = document.getElementById('clearBody');
  let html = '';
  CLEAR_ROWS.forEach((r, i) => {
    const key = 'clr-' + i;
    const done = !!CHECKED[key];
    const ac = r.out ? 'out' : 'in';
    const sign = r.out ? '−' : '+';
    html += `<tr class="${done ? 'done-row' : ''}" id="row-${i}">
      <td style="text-align:center"><input type="checkbox" class="tx-check" id="chk-${i}" ${done?'checked':''} onchange="toggleClear(${i})"></td>
      <td class="tx-date">${esc(r.date)}</td>
      <td style="font-size:12px">${esc(r.desc)}</td>
      <td class="tx-amt ${ac}">${sign}$${fmt(r.amt)}</td>
      <td style="font-size:11px;color:var(--faint)">${esc(r.ref)}</td>
    </tr>`;
  });
  tbody.innerHTML = html;
  updateClearProgress();
}

function toggleClear(i) {
  const key = 'clr-' + i;
  CHECKED[key] = document.getElementById('chk-'+i).checked;
  localStorage.setItem('recon-feb2026', JSON.stringify(CHECKED));
  const row = document.getElementById('row-'+i);
  CHECKED[key] ? row.classList.add('done-row') : row.classList.remove('done-row');
  updateClearProgress();
}

function updateClearProgress() {
  const done = CLEAR_ROWS.filter((_,i) => CHECKED['clr-'+i]).length;
  const total = CLEAR_ROWS.length;
  const pct = Math.round((done/total)*100);
  document.getElementById('clearProgress').textContent = done + ' of ' + total + ' marked cleared';
  document.getElementById('clearFill').style.width = pct + '%';
  document.getElementById('clearDone').textContent = done;
  document.getElementById('badgeClear').textContent = total;
}

function toggleIssue(id) {
  const el = document.getElementById(id);
  const key = 'iss-' + id;
  CHECKED[key] = !CHECKED[key];
  localStorage.setItem('recon-feb2026', JSON.stringify(CHECKED));
  if (CHECKED[key]) {
    el.style.opacity = '0.4';
    el.style.textDecoration = 'line-through';
  } else {
    el.style.opacity = '';
    el.style.textDecoration = '';
  }
}

function toggleStep(id) {
  const el = document.getElementById(id);
  el.classList.toggle('done');
  const numEl = el.querySelector('.step-num');
  const key = 'ws-' + id;
  CHECKED[key] = el.classList.contains('done');
  localStorage.setItem('recon-feb2026', JSON.stringify(CHECKED));
  numEl.textContent = el.classList.contains('done') ? '✓' : (numEl.dataset.n || '');
}

function showTab(panelId, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById(panelId).classList.add('active');
  btn.classList.add('active');
}

// Restore state
window.addEventListener('DOMContentLoaded', function() {
  buildClearTable();

  // Restore issue cards
  for (let i = 1; i <= 13; i++) {
    if (CHECKED['iss-ac'+i]) {
      const el = document.getElementById('ac'+i);
      if (el) { el.style.opacity='0.4'; el.style.textDecoration='line-through'; }
    }
  }

  // Restore walkthrough steps
  for (let i = 1; i <= 12; i++) {
    if (CHECKED['ws-ws'+i]) {
      const el = document.getElementById('ws'+i);
      if (el) {
        el.classList.add('done');
        const numEl = el.querySelector('.step-num');
        if (numEl) numEl.textContent = '✓';
      }
    }
  }
});
</script>
</body>
</html>
