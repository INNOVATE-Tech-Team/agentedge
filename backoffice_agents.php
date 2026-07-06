<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/crypto.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) { header('Location: index.php'); exit; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

$intakeAgents = local_db()->query(
    "SELECT i.email, i.full_name, i.phone, i.license_number, i.license_state,
            i.license_exp, i.nar_number, i.mls_board, i.mls_id, i.office_location,
            i.birthday, i.mailing_address, i.spouse_name, i.emergency_name, i.emergency_phone,
            i.bio, i.tshirt_size, i.is_military, i.first_responder, i.is_teacher,
            i.phone_last4, i.referring_agent, i.languages,
            i.personal_email, i.commissions_email,
            i.address_line1, i.address_line2, i.city, i.state, i.zip, i.country,
            i.drivers_license, i.gender,
            i.website, i.additional_websites, i.facebook, i.linkedin, i.skype, i.email_signature,
            i.specialty, i.career_start, i.prior_occupation, i.prior_affiliation,
            i.full_time, i.show_on_internet,
            i.corporation_start, i.corporation_end,
            i.personal_tax_id_enc, i.corporate_tax_id_enc,
            i.submitted, i.submitted_at, i.updated_at,
            e.hire_date, e.license_renewal,
            ar.role,
            aa.tax_1099_type, aa.gets_1099, aa.terminated_date, aa.agent_team, aa.coached_by, aa.managed_by
     FROM agent_intake i
     LEFT JOIN agent_extra e ON e.email = i.email
     LEFT JOIN agent_roles ar ON ar.email = i.email
     LEFT JOIN agent_admin aa ON aa.email = i.email
     ORDER BY i.submitted DESC, i.updated_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$pendingAgents = local_db()->query(
    "SELECT q.agent_email as email, q.agent_name as full_name, q.market_center as office_location,
            q.start_date, q.role, q.sponsor as referring_agent, q.status, q.added_at
     FROM onboard_queue q
     WHERE q.status = 'active'
       AND LOWER(q.agent_email) NOT IN (SELECT LOWER(email) FROM agent_intake)
     ORDER BY q.added_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$hsCount = [];
foreach (local_db()->query("SELECT agent_email, COUNT(*) as cnt FROM agent_intake_files GROUP BY agent_email")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $hsCount[strtolower($r['agent_email'])] = (int)$r['cnt'];
}

$submittedCount = count(array_filter($intakeAgents, fn($a) => !empty($a['submitted'])));
$draftCount = count($intakeAgents) - $submittedCount;
$totalWithForms = count($intakeAgents);
$pendingCount = count($pendingAgents);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Agent Profiles — AgentEdge</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
.rs-tile{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px 18px;min-width:110px}
.rs-tile .rs-num{font-size:26px;font-weight:800;line-height:1.1}
.rs-tile .rs-lbl{font-size:11px;color:var(--faint);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-top:2px}
.rs-tile.green .rs-num{color:var(--green-d)}
.rs-tile.amber .rs-num{color:#c87800}
.roster-summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
.ag-search{padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;background:#fafafa;width:280px;box-sizing:border-box}
.ag-search:focus{outline:2px solid var(--green);border-color:var(--green)}
.ag-tabs{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.ag-tab{padding:6px 14px;border:1px solid var(--border);background:#fff;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;color:var(--muted)}
.ag-tab.active{background:var(--green);border-color:var(--green);color:#111}
.ag-table{width:100%;border-collapse:collapse;font-size:13px}
.ag-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);padding:8px 14px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
.ag-table td{padding:9px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.ag-table tr.data-row:hover td{background:#fafbfa}
.ag-table tr.data-row.expanded td{background:#f4fbec}
.expand-btn{background:none;border:none;cursor:pointer;color:var(--faint);font-size:13px;padding:2px 6px;border-radius:4px;transition:transform .18s}
.expand-btn.open{transform:rotate(90deg)}
.st-badge{display:inline-block;font-size:10px;font-weight:800;padding:2px 8px;border-radius:4px;letter-spacing:.03em;white-space:nowrap}
.st-submitted{background:#e8f5e9;color:#2e7d32}
.st-draft{background:#fff3e0;color:#c87800}
.st-pending{background:#f0f0f0;color:#888}
.detail-row td{padding:14px 18px;background:#f8fdf4!important;border-bottom:2px solid var(--border)}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px}
.detail-grid.full{grid-template-columns:1fr}
.dg-section{grid-column:1/-1;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--faint);margin-top:12px;padding-top:10px;border-top:1px solid var(--border)}
.dg-section:first-child{margin-top:0;padding-top:0;border-top:none}
.dg-field{display:flex;flex-direction:column;gap:2px}
.dg-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
.dg-value{font-size:12px;color:var(--ink)}
.dg-value.empty{color:var(--faint);font-style:italic}
.dg-bio{grid-column:1/-1}
.dg-bio .dg-value{white-space:pre-wrap;font-size:12px;line-height:1.55;max-height:140px;overflow-y:auto}
.detail-actions{grid-column:1/-1;margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.btn-detail-link{font-size:11px;font-weight:700;padding:5px 12px;border-radius:5px;border:1px solid var(--border);background:#fff;color:var(--ink);text-decoration:none;white-space:nowrap}
.btn-detail-link:hover{border-color:var(--green);color:#5b8e0d;background:#f0f8e8}
.detail-meta{font-size:11px;color:var(--faint);margin-left:auto}
.bio-preview{max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted)}
.ag-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.table-wrap{overflow-x:auto}
.no-results{padding:32px;text-align:center;color:var(--faint);font-size:13px}
</style>
</head>
<body>
<?php render_sidebar('backoffice_agents', $agent); ?>
<main class="main-content">
  <div class="content-inner">

    <p class="bo-eyebrow">Back Office</p>
    <h1 class="content-title">Agent Profiles</h1>

    <div class="roster-summary">
      <div class="rs-tile">
        <div class="rs-num"><?= $totalWithForms ?></div>
        <div class="rs-lbl">With Forms</div>
      </div>
      <div class="rs-tile green">
        <div class="rs-num"><?= $submittedCount ?></div>
        <div class="rs-lbl">Submitted</div>
      </div>
      <div class="rs-tile amber">
        <div class="rs-num"><?= $draftCount ?></div>
        <div class="rs-lbl">Draft</div>
      </div>
      <div class="rs-tile">
        <div class="rs-num"><?= $pendingCount ?></div>
        <div class="rs-lbl">Pending</div>
      </div>
    </div>

    <div class="ag-toolbar">
      <input type="text" class="ag-search" id="agSearch" placeholder="Search name, email, office…" autocomplete="off">
    </div>

    <div class="ag-tabs">
      <button class="ag-tab active" data-tab="all">All</button>
      <button class="ag-tab" data-tab="submitted">Submitted</button>
      <button class="ag-tab" data-tab="draft">Draft</button>
      <button class="ag-tab" data-tab="pending">Pending</button>
    </div>

    <div class="table-wrap">
      <table class="ag-table" id="agTable">
        <thead>
          <tr>
            <th style="width:32px"></th>
            <th>Name</th>
            <th>Email</th>
            <th>Office</th>
            <th>Phone</th>
            <th>Status</th>
            <th>Updated</th>
          </tr>
        </thead>
        <tbody id="agBody">

<?php foreach ($intakeAgents as $idx => $a):
  $isSubmitted = !empty($a['submitted']);
  $statusClass = $isSubmitted ? 'st-submitted' : 'st-draft';
  $statusLabel = $isSubmitted ? 'Submitted' : 'Draft';
  $tabAttr = $isSubmitted ? 'submitted' : 'draft';
  $updatedRaw = $a['submitted_at'] ?? $a['updated_at'] ?? '';
  $updated = $updatedRaw ? date('M j, Y', strtotime($updatedRaw)) : '—';
  $rowId = 'row-' . $idx;
  $detailId = 'detail-' . $idx;
  $emailLower = strtolower($a['email']);
  $hs = $hsCount[$emailLower] ?? 0;

  function dv(string $val): string {
      if ($val === '' || $val === null) return '<span class="dg-value empty">—</span>';
      return '<span class="dg-value">' . h($val) . '</span>';
  }
  function dvBool($val): string {
      return '<span class="dg-value">' . ($val ? 'Yes' : 'No') . '</span>';
  }
?>
          <tr class="data-row" id="<?= $rowId ?>" data-tab="<?= $tabAttr ?>"
              data-search="<?= h(strtolower($a['full_name'] . ' ' . $a['email'] . ' ' . $a['office_location'])) ?>">
            <td><button class="expand-btn" aria-label="Expand" onclick="toggleDetail('<?= $detailId ?>',this)">&#9658;</button></td>
            <td><strong><?= h($a['full_name'] ?: '—') ?></strong></td>
            <td><?= h($a['email']) ?></td>
            <td><?= h($a['office_location'] ?: '—') ?></td>
            <td><?= h($a['phone'] ?: '—') ?></td>
            <td><span class="st-badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
            <td><?= h($updated) ?></td>
          </tr>
          <tr class="detail-row" id="<?= $detailId ?>" style="display:none" data-tab="<?= $tabAttr ?>">
            <td colspan="7">
              <div class="detail-grid">

                <div class="dg-section">Contact</div>
                <div class="dg-field"><span class="dg-label">Full Name</span><?= dv($a['full_name']) ?></div>
                <div class="dg-field"><span class="dg-label">Email</span><?= dv($a['email']) ?></div>
                <div class="dg-field"><span class="dg-label">Personal Email</span><?= dv($a['personal_email'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Commissions Email</span><?= dv($a['commissions_email'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Phone</span><?= dv($a['phone']) ?></div>
                <div class="dg-field"><span class="dg-label">Birthday</span><?= dv($a['birthday']) ?></div>
                <?php
                  $addrParts = array_filter([$a['address_line1'] ?? '', $a['address_line2'] ?? '']);
                  $cityStZip = array_filter([$a['city'] ?? '', $a['state'] ?? '', $a['zip'] ?? '']);
                  $structuredAddr = trim(implode(', ', $addrParts) . ($addrParts && $cityStZip ? ', ' : '') . implode(', ', $cityStZip) . (!empty($a['country']) ? ', ' . $a['country'] : ''));
                  $addrDisplay = $structuredAddr !== '' ? $structuredAddr : ($a['mailing_address'] ?? '');
                ?>
                <div class="dg-field" style="grid-column:1/-1"><span class="dg-label">Address</span><?= dv($addrDisplay) ?></div>
                <div class="dg-field"><span class="dg-label">Spouse / SO</span><?= dv($a['spouse_name']) ?></div>
                <div class="dg-field"><span class="dg-label">Gender</span><?= dv($a['gender'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Driver's License #</span><?= dv($a['drivers_license'] ?? '') ?></div>

                <div class="dg-section">Professional Background</div>
                <div class="dg-field"><span class="dg-label">Specialty</span><?= dv($a['specialty'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Career Start</span><?= dv($a['career_start'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Prior Occupation</span><?= dv($a['prior_occupation'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Prior Affiliation</span><?= dv($a['prior_affiliation'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Full-Time</span><?= dvBool($a['full_time'] ?? 1) ?></div>
                <div class="dg-field"><span class="dg-label">Show on Website</span><?= dvBool($a['show_on_internet'] ?? 1) ?></div>

                <div class="dg-section">Business Entity &amp; Tax IDs</div>
                <div class="dg-field"><span class="dg-label">Corporation Start</span><?= dv($a['corporation_start'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Corporation End</span><?= dv($a['corporation_end'] ?? '') ?></div>
                <?php
                  $personalLast4  = tax_id_last4($a['personal_tax_id_enc'] ?? '');
                  $corporateLast4 = tax_id_last4($a['corporate_tax_id_enc'] ?? '');
                ?>
                <div class="dg-field">
                  <span class="dg-label">Personal Tax ID</span>
                  <?php if ($personalLast4 !== ''): ?>
                    <span class="dg-value tax-id-mask" id="ptax-<?= $idx ?>">•••••<?= h($personalLast4) ?>
                      <button type="button" class="btn-detail-link" style="padding:2px 8px;font-size:10px" onclick="revealTaxId('<?= h($a['email']) ?>','personal','ptax-<?= $idx ?>')">Reveal</button>
                    </span>
                  <?php else: ?>
                    <span class="dg-value empty">—</span>
                  <?php endif; ?>
                </div>
                <div class="dg-field">
                  <span class="dg-label">Corporate Tax ID (EIN)</span>
                  <?php if ($corporateLast4 !== ''): ?>
                    <span class="dg-value tax-id-mask" id="ctax-<?= $idx ?>">•••••<?= h($corporateLast4) ?>
                      <button type="button" class="btn-detail-link" style="padding:2px 8px;font-size:10px" onclick="revealTaxId('<?= h($a['email']) ?>','corporate','ctax-<?= $idx ?>')">Reveal</button>
                    </span>
                  <?php else: ?>
                    <span class="dg-value empty">—</span>
                  <?php endif; ?>
                </div>

                <div class="dg-section">License &amp; Certs</div>
                <div class="dg-field"><span class="dg-label">License Number</span><?= dv($a['license_number']) ?></div>
                <div class="dg-field"><span class="dg-label">License State</span><?= dv($a['license_state']) ?></div>
                <div class="dg-field"><span class="dg-label">License Expiration</span><?= dv($a['license_exp']) ?></div>
                <div class="dg-field"><span class="dg-label">NAR Number</span><?= dv($a['nar_number']) ?></div>
                <div class="dg-field"><span class="dg-label">Hire Date</span><?= dv($a['hire_date'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">License Renewal</span><?= dv($a['license_renewal'] ?? '') ?></div>

                <div class="dg-section">MLS</div>
                <div class="dg-field"><span class="dg-label">MLS Board</span><?= dv($a['mls_board']) ?></div>
                <div class="dg-field"><span class="dg-label">MLS ID</span><?= dv($a['mls_id']) ?></div>

                <div class="dg-section">Personal</div>
                <div class="dg-field"><span class="dg-label">T-Shirt Size</span><?= dv($a['tshirt_size']) ?></div>
                <div class="dg-field"><span class="dg-label">Military</span><?= dvBool($a['is_military']) ?></div>
                <div class="dg-field"><span class="dg-label">First Responder</span><?= dvBool($a['first_responder']) ?></div>
                <div class="dg-field"><span class="dg-label">Teacher</span><?= dvBool($a['is_teacher']) ?></div>
                <div class="dg-field"><span class="dg-label">Languages</span><?= dv($a['languages']) ?></div>
                <div class="dg-field"><span class="dg-label">Phone Last 4</span><?= dv($a['phone_last4']) ?></div>

                <div class="dg-section">Emergency Contact</div>
                <div class="dg-field"><span class="dg-label">Name</span><?= dv($a['emergency_name']) ?></div>
                <div class="dg-field"><span class="dg-label">Phone</span><?= dv($a['emergency_phone']) ?></div>

                <div class="dg-section">Online Presence</div>
                <div class="dg-field"><span class="dg-label">Website</span><?= dv($a['website'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Additional Websites</span><?= dv($a['additional_websites'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Facebook</span><?= dv($a['facebook'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">LinkedIn</span><?= dv($a['linkedin'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Skype</span><?= dv($a['skype'] ?? '') ?></div>
                <div class="dg-field" style="grid-column:1/-1"><span class="dg-label">Email Signature</span><?= dv($a['email_signature'] ?? '') ?></div>

                <div class="dg-section">Bio &amp; Marketing</div>
                <div class="dg-field"><span class="dg-label">Referring Agent</span><?= dv($a['referring_agent']) ?></div>
                <div class="dg-field dg-bio" style="grid-column:1/-1"><span class="dg-label">Bio</span>
                  <?php if (!empty($a['bio'])): ?>
                    <div class="dg-value" style="white-space:pre-wrap;font-size:12px;line-height:1.55;max-height:140px;overflow-y:auto"><?= h($a['bio']) ?></div>
                  <?php else: ?>
                    <span class="dg-value empty">—</span>
                  <?php endif; ?>
                </div>

                <?php if ($hs > 0): ?>
                <div class="dg-section">Headshots</div>
                <div class="dg-field" style="grid-column:1/-1">
                  <span class="dg-value"><?= $hs ?> headshot<?= $hs !== 1 ? 's' : '' ?> on file — <a href="intake.php" target="_blank" style="color:var(--green-d)">view in intake form</a></span>
                </div>
                <?php endif; ?>

                <div class="dg-section">Staff-Managed <span style="font-weight:400;text-transform:none;letter-spacing:0">(not visible to the agent)</span></div>
                <div class="dg-field">
                  <span class="dg-label">1099 Type</span>
                  <select id="admin-1099type-<?= $idx ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
                    <option value="">— none —</option>
                    <?php foreach (['1099-NEC', '1099-MISC', 'W-2', 'N/A'] as $opt): ?>
                      <option value="<?= h($opt) ?>" <?= ($a['tax_1099_type'] ?? '') === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="dg-field">
                  <span class="dg-label">Gets 1099?</span>
                  <label style="font-size:12px"><input type="checkbox" id="admin-gets1099-<?= $idx ?>" style="width:auto;vertical-align:middle;margin-right:6px" <?= !empty($a['gets_1099']) || $a['gets_1099'] === null ? 'checked' : '' ?>> Yes</label>
                </div>
                <div class="dg-field">
                  <span class="dg-label">Terminated Date</span>
                  <input type="date" id="admin-terminated-<?= $idx ?>" value="<?= h($a['terminated_date'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
                </div>
                <div class="dg-field">
                  <span class="dg-label">Agent Team</span>
                  <input type="text" id="admin-team-<?= $idx ?>" value="<?= h($a['agent_team'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
                </div>
                <div class="dg-field">
                  <span class="dg-label">Coached By</span>
                  <input type="text" id="admin-coached-<?= $idx ?>" value="<?= h($a['coached_by'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
                </div>
                <div class="dg-field">
                  <span class="dg-label">Managed By</span>
                  <input type="text" id="admin-managed-<?= $idx ?>" value="<?= h($a['managed_by'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
                </div>
                <div class="dg-field" style="grid-column:1/-1">
                  <button type="button" class="btn-detail-link" onclick="saveAdminFields('<?= h($a['email']) ?>', <?= $idx ?>)">Save Staff-Managed Fields</button>
                  <span id="admin-save-msg-<?= $idx ?>" style="font-size:11px;color:var(--faint);margin-left:8px"></span>
                </div>

                <div class="detail-actions">
                  <?php if ($isSubmitted): ?>
                    <span style="font-size:11px;color:var(--faint)">Submitted <?= h($a['submitted_at'] ? date('M j, Y', strtotime($a['submitted_at'])) : '—') ?></span>
                  <?php else: ?>
                    <span style="font-size:11px;color:var(--faint)">Last updated <?= h($updated) ?></span>
                  <?php endif; ?>
                  <a href="onboarding.php" target="_blank" class="btn-detail-link">Onboarding Steps →</a>
                  <a href="intake.php" target="_blank" class="btn-detail-link">View Intake Form →</a>
                </div>

              </div>
            </td>
          </tr>
<?php endforeach; ?>

<?php foreach ($pendingAgents as $idx => $p):
  $addedRaw = $p['added_at'] ?? '';
  $added = $addedRaw ? date('M j, Y', strtotime($addedRaw)) : '—';
  $rowId = 'prow-' . $idx;
  $detailId = 'pdetail-' . $idx;
?>
          <tr class="data-row" id="<?= $rowId ?>" data-tab="pending"
              data-search="<?= h(strtolower($p['full_name'] . ' ' . $p['email'] . ' ' . $p['office_location'])) ?>">
            <td><button class="expand-btn" aria-label="Expand" onclick="toggleDetail('<?= $detailId ?>',this)">&#9658;</button></td>
            <td><strong><?= h($p['full_name'] ?: '—') ?></strong></td>
            <td><?= h($p['email']) ?></td>
            <td><?= h($p['office_location'] ?: '—') ?></td>
            <td><span class="dg-value empty" style="font-size:12px">—</span></td>
            <td><span class="st-badge st-pending">Pending</span></td>
            <td><?= h($added) ?></td>
          </tr>
          <tr class="detail-row" id="<?= $detailId ?>" style="display:none" data-tab="pending">
            <td colspan="7">
              <div class="detail-grid">
                <div class="dg-section">Queue Info</div>
                <div class="dg-field"><span class="dg-label">Name</span><?= dv($p['full_name']) ?></div>
                <div class="dg-field"><span class="dg-label">Email</span><?= dv($p['email']) ?></div>
                <div class="dg-field"><span class="dg-label">Office / Market Center</span><?= dv($p['office_location']) ?></div>
                <div class="dg-field"><span class="dg-label">Role</span><?= dv($p['role'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Start Date</span><?= dv($p['start_date'] ?? '') ?></div>
                <div class="dg-field"><span class="dg-label">Sponsor</span><?= dv($p['referring_agent']) ?></div>
                <div class="dg-field"><span class="dg-label">Added</span><?= dv($added) ?></div>
                <div class="dg-field" style="grid-column:1/-1">
                  <span class="dg-label">Intake Form</span>
                  <span class="dg-value empty">No intake form submitted yet</span>
                </div>
                <div class="detail-actions">
                  <a href="onboarding.php" target="_blank" class="btn-detail-link">Onboarding Steps →</a>
                </div>
              </div>
            </td>
          </tr>
<?php endforeach; ?>

        </tbody>
      </table>
      <div class="no-results" id="noResults" style="display:none">No agents match your search.</div>
    </div>

  </div>
</main>

<script>
(function () {
  var searchEl = document.getElementById('agSearch');
  var tabs = document.querySelectorAll('.ag-tab');
  var activeTab = 'all';

  function applyFilters() {
    var q = searchEl.value.toLowerCase().trim();
    var dataRows = document.querySelectorAll('#agBody tr.data-row');
    var visible = 0;

    dataRows.forEach(function (row) {
      var tab = row.dataset.tab;
      var search = row.dataset.search || '';
      var detailId = null;
      var btn = row.querySelector('.expand-btn');
      if (btn) {
        var onclick = btn.getAttribute('onclick') || '';
        var m = onclick.match(/'([^']+)'/);
        if (m) detailId = m[1];
      }
      var detailRow = detailId ? document.getElementById(detailId) : null;

      var tabMatch = (activeTab === 'all') || (tab === activeTab);
      var searchMatch = (q === '') || (search.indexOf(q) !== -1);
      var show = tabMatch && searchMatch;

      row.style.display = show ? '' : 'none';
      if (detailRow) {
        if (!show) {
          detailRow.style.display = 'none';
          row.classList.remove('expanded');
          if (btn) btn.classList.remove('open');
        } else if (detailRow.dataset.open === '1') {
          detailRow.style.display = '';
        }
      }
      if (show) visible++;
    });

    document.getElementById('noResults').style.display = visible === 0 ? '' : 'none';
  }

  searchEl.addEventListener('input', applyFilters);

  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
      tabs.forEach(function (t) { t.classList.remove('active'); });
      tab.classList.add('active');
      activeTab = tab.dataset.tab;
      applyFilters();
    });
  });

  window.revealTaxId = function (email, field, spanId) {
    var span = document.getElementById(spanId);
    if (!span) return;
    var btn = span.querySelector('button');
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    fetch('api/tax_id_reveal.php?email=' + encodeURIComponent(email) + '&field=' + field, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          span.textContent = res.value || '(none on file)';
        } else {
          span.textContent = 'Error: ' + (res.error || 'reveal failed');
        }
      })
      .catch(function () { span.textContent = 'Network error.'; });
  };

  window.saveAdminFields = function (email, idx) {
    var msg = document.getElementById('admin-save-msg-' + idx);
    msg.textContent = 'Saving…';
    var payload = {
      email: email,
      tax_1099_type: document.getElementById('admin-1099type-' + idx).value,
      gets_1099: document.getElementById('admin-gets1099-' + idx).checked,
      terminated_date: document.getElementById('admin-terminated-' + idx).value,
      agent_team: document.getElementById('admin-team-' + idx).value,
      coached_by: document.getElementById('admin-coached-' + idx).value,
      managed_by: document.getElementById('admin-managed-' + idx).value
    };
    fetch('api/agent_admin.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        msg.textContent = res.ok ? 'Saved ✓' : (res.error || 'Save failed.');
        if (res.ok) setTimeout(function () { msg.textContent = ''; }, 3000);
      })
      .catch(function () { msg.textContent = 'Network error.'; });
  };

  window.toggleDetail = function (detailId, btn) {
    var detailRow = document.getElementById(detailId);
    if (!detailRow) return;
    var dataRow = btn.closest('tr');
    var isOpen = detailRow.style.display !== 'none';
    if (isOpen) {
      detailRow.style.display = 'none';
      detailRow.dataset.open = '0';
      btn.classList.remove('open');
      if (dataRow) dataRow.classList.remove('expanded');
    } else {
      detailRow.style.display = '';
      detailRow.dataset.open = '1';
      btn.classList.add('open');
      if (dataRow) dataRow.classList.add('expanded');
    }
  };
}());
</script>
</body>
</html>
