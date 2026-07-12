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
function dv(string $val): string {
    if ($val === '' || $val === null) return '<span class="dg-value empty">—</span>';
    return '<span class="dg-value">' . h($val) . '</span>';
}
function dvBool($val): string {
    return '<span class="dg-value">' . ($val ? 'Yes' : 'No') . '</span>';
}

$intakeAgents = local_db()->query(
    "SELECT i.email, i.full_name, i.phone, i.license_number, i.license_state,
            i.license_exp, i.nar_number, i.mls_board, i.mls_id, i.office_location,
            i.birthday, i.mailing_address, i.spouse_name, i.emergency_name, i.emergency_phone,
            i.bio, i.tshirt_size, i.is_military, i.first_responder, i.is_teacher,
            i.phone_last4, i.referring_agent, i.languages,
            i.personal_email, i.commissions_email,
            i.address_line1, i.address_line2, i.city, i.state, i.zip, i.country,
            i.drivers_license, i.gender,
            i.website, i.additional_websites, i.facebook, i.linkedin, i.skype,
            i.specialty, i.career_start, i.prior_occupation, i.prior_affiliation,
            i.full_time, i.show_on_internet,
            i.corporation_start, i.corporation_end,
            i.personal_tax_id_enc, i.corporate_tax_id_enc,
            i.submitted, i.submitted_at, i.updated_at,
            e.hire_date, e.license_renewal,
            ar.role,
            aa.tax_1099_type, aa.gets_1099, aa.terminated_date, aa.agent_team, aa.coached_by, aa.managed_by,
            aa.recruit_source_email
     FROM agent_intake i
     LEFT JOIN agent_extra e ON e.email = i.email
     LEFT JOIN agent_roles ar ON ar.email = i.email
     LEFT JOIN agent_admin aa ON aa.email = i.email
     ORDER BY i.submitted DESC, i.updated_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$launchCoaches = local_db()->query(
    "SELECT ar.email, COALESCE(i.full_name, ar.email) AS full_name
     FROM agent_roles ar
     LEFT JOIN agent_intake i ON i.email = ar.email
     WHERE ar.role = 'launch_coach'
     ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC);

$additionalLicensesByEmail = [];
foreach (local_db()->query(
    "SELECT agent_email, license_number, license_state, license_exp FROM agent_intake_licenses ORDER BY agent_email, id"
)->fetchAll(PDO::FETCH_ASSOC) as $lic) {
    $additionalLicensesByEmail[strtolower($lic['agent_email'])][] = $lic;
}

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

// Most recently uploaded headshot per agent, used as their displayed photo.
$hsLatest = [];
foreach (local_db()->query(
    "SELECT agent_email, file_key FROM agent_intake_files
     WHERE id IN (SELECT MAX(id) FROM agent_intake_files GROUP BY agent_email)"
)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $hsLatest[strtolower($r['agent_email'])] = $r['file_key'];
}

function bo_avatar_html(string $name, ?string $headshotKey, string $sizeClass): string {
    if ($headshotKey) {
        return '<img class="' . $sizeClass . '-img" src="api/intake.php?action=headshot&key=' . urlencode($headshotKey) . '" alt="">';
    }
    $initials = '';
    foreach (preg_split('/\s+/', trim($name ?: '?')) as $part) { if ($part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
    return '<span class="' . $sizeClass . '-fallback">' . htmlspecialchars(mb_substr($initials ?: '?', 0, 2)) . '</span>';
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
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
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
.row-avatar-img{width:24px;height:24px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:8px;border:1px solid var(--border)}
.row-avatar-fallback{width:24px;height:24px;border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-size:10px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;vertical-align:middle;margin-right:8px}
.detail-avatar-img{width:52px;height:52px;border-radius:50%;object-fit:cover;border:1px solid var(--border)}
.detail-avatar-fallback{width:52px;height:52px;border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-size:18px;font-weight:800;display:flex;align-items:center;justify-content:center}
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
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:1000}
.modal-box{background:#fff;border-radius:10px;max-width:860px;width:94%;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.25)}
.modal-header{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-header h3{margin:0;font-size:15px}
.modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:var(--faint);line-height:1;padding:2px 6px}
.modal-body{padding:20px 22px;overflow-y:auto;flex:1}
.modal-footer{padding:14px 22px;border-top:1px solid var(--border);display:flex;gap:10px;align-items:center}
.em-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;margin-bottom:6px}
.em-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--faint);margin-bottom:3px}
.em-field input,.em-field select,.em-field textarea{width:100%;padding:7px 9px;border:1px solid var(--border);border-radius:6px;font-size:13px;box-sizing:border-box;font-family:inherit}
.em-field textarea{min-height:70px;resize:vertical}
.em-section{grid-column:1/-1;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);margin:14px 0 4px;padding-top:10px;border-top:1px solid var(--border)}
.em-section:first-child{margin-top:0;padding-top:0;border-top:none}
.em-full{grid-column:1/-1}
.em-check label{display:flex;align-items:center;gap:6px;font-size:12px;text-transform:none;font-weight:600;color:var(--ink)}
.em-check input{width:auto!important}
.ag-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.table-wrap{overflow-x:auto}
.no-results{padding:32px;text-align:center;color:var(--faint);font-size:13px}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('backoffice_agents', $agent); ?>
<div class="content">
  <div class="content-top">
    <div>
      <div class="bo-eyebrow">Back Office</div>
      <div class="content-title">Agent Profiles</div>
    </div>
  </div>
  <div class="wrap">

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
?>
          <tr class="data-row" id="<?= $rowId ?>" data-tab="<?= $tabAttr ?>"
              data-search="<?= h(strtolower($a['full_name'] . ' ' . $a['email'] . ' ' . $a['office_location'])) ?>">
            <td><button class="expand-btn" aria-label="Expand" onclick="toggleDetail('<?= $detailId ?>',this)">&#9658;</button></td>
            <td><?= bo_avatar_html($a['full_name'], $hsLatest[$emailLower] ?? null, 'row-avatar') ?><strong><?= h($a['full_name'] ?: '—') ?></strong></td>
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
                <?php $extraLicenses = $additionalLicensesByEmail[$emailLower] ?? []; ?>
                <?php if ($extraLicenses): ?>
                <div class="dg-field" style="grid-column:1/-1">
                  <span class="dg-label">Additional Licenses</span>
                  <span class="dg-value">
                    <?php foreach ($extraLicenses as $lic): ?>
                      <?= h(trim($lic['license_number'] . ' — ' . $lic['license_state'] . ' ' . ($lic['license_exp'] ? '(exp. ' . $lic['license_exp'] . ')' : ''))) ?><br>
                    <?php endforeach; ?>
                  </span>
                </div>
                <?php endif; ?>

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

                <div class="dg-section">Bio &amp; Marketing</div>
                <div class="dg-field"><span class="dg-label">Referring Agent</span><?= dv($a['referring_agent']) ?></div>
                <div class="dg-field dg-bio" style="grid-column:1/-1"><span class="dg-label">Bio</span>
                  <?php if (!empty($a['bio'])): ?>
                    <div class="dg-value" style="white-space:pre-wrap;font-size:12px;line-height:1.55;max-height:140px;overflow-y:auto"><?= h($a['bio']) ?></div>
                  <?php else: ?>
                    <span class="dg-value empty">—</span>
                  <?php endif; ?>
                </div>

                <div class="dg-section">Photo</div>
                <div class="dg-field" style="grid-column:1/-1;flex-direction:row;align-items:center;gap:10px">
                  <?= bo_avatar_html($a['full_name'], $hsLatest[$emailLower] ?? null, 'detail-avatar') ?>
                  <?php if ($hs > 0): ?>
                    <span class="dg-value"><?= $hs ?> headshot<?= $hs !== 1 ? 's' : '' ?> on file — <a href="intake.php" target="_blank" style="color:var(--green-d)">view in intake form</a></span>
                  <?php else: ?>
                    <span class="dg-value empty">No headshot uploaded yet</span>
                  <?php endif; ?>
                </div>

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
                  <select id="admin-coached-<?= $idx ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
                    <option value="">— none —</option>
                    <?php foreach ($launchCoaches as $lc): ?>
                      <option value="<?= h($lc['email']) ?>" <?= ($a['coached_by'] ?? '') === $lc['email'] ? 'selected' : '' ?>><?= h($lc['full_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="dg-field">
                  <span class="dg-label">Managed By</span>
                  <input type="text" id="admin-managed-<?= $idx ?>" value="<?= h($a['managed_by'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
                </div>
                <div class="dg-field">
                  <span class="dg-label">Recruit Source</span>
                  <select id="admin-recruitsrc-<?= $idx ?>" class="rs-select" data-current="<?= h($a['recruit_source_email'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
                    <option value="">— none —</option>
                  </select>
                </div>
                <div class="dg-field" style="grid-column:1/-1">
                  <button type="button" class="btn-detail-link" onclick="saveAdminFields('<?= h($a['email']) ?>', <?= $idx ?>)">Save Staff-Managed Fields</button>
                  <span id="admin-save-msg-<?= $idx ?>" style="font-size:11px;color:var(--faint);margin-left:8px"></span>
                </div>

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
                  <select id="admin-coached-<?= $idx ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
                    <option value="">— none —</option>
                    <?php foreach ($launchCoaches as $lc): ?>
                      <option value="<?= h($lc['email']) ?>" <?= ($a['coached_by'] ?? '') === $lc['email'] ? 'selected' : '' ?>><?= h($lc['full_name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="dg-field">
                  <span class="dg-label">Managed By</span>
                  <input type="text" id="admin-managed-<?= $idx ?>" value="<?= h($a['managed_by'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
                </div>
                <div class="dg-field">
                  <span class="dg-label">Recruit Source</span>
                  <select id="admin-recruitsrc-<?= $idx ?>" class="rs-select" data-current="<?= h($a['recruit_source_email'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
                    <option value="">— none —</option>
                  </select>
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
                  <button type="button" class="btn-detail-link" onclick="openEditModal('<?= h($a['email']) ?>', '<?= h($a['full_name'] ?: $a['email']) ?>')">Edit Profile →</button>
                  <a href="agent_profile.php?email=<?= h($a['email']) ?>" class="btn-detail-link">View Full Profile →</a>
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
</div>
</div>

<div class="modal-overlay" id="editModalOverlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Edit Profile — <span id="em-agent-name"></span></h3>
      <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
    </div>
    <div class="modal-body">

      <div class="em-grid">
        <div class="em-section">Contact Information</div>
        <div class="em-field"><label>Full Name</label><input id="em-full_name"></div>
        <div class="em-field"><label>Phone</label><input id="em-phone"></div>
        <div class="em-field"><label>Personal Email</label><input id="em-personal_email" type="email"></div>
        <div class="em-field"><label>Commissions Email</label><input id="em-commissions_email" type="email"></div>
        <div class="em-field"><label>Phone Last 4 (payroll)</label><input id="em-phone_last4" maxlength="4"></div>

        <div class="em-section">Address</div>
        <div class="em-field em-full"><label>Address Line 1</label><input id="em-address_line1"></div>
        <div class="em-field em-full"><label>Address Line 2</label><input id="em-address_line2"></div>
        <div class="em-field"><label>City</label><input id="em-city"></div>
        <div class="em-field"><label>State</label><input id="em-state"></div>
        <div class="em-field"><label>Zip</label><input id="em-zip"></div>
        <div class="em-field"><label>Country</label><input id="em-country"></div>

        <div class="em-section">License &amp; Certifications</div>
        <div class="em-field"><label>License Number</label><input id="em-license_number"></div>
        <div class="em-field"><label>License State</label><input id="em-license_state"></div>
        <div class="em-field"><label>License Expiration</label><input id="em-license_exp" type="date"></div>
        <div class="em-field"><label>NAR Number</label><input id="em-nar_number"></div>
        <div class="em-field"><label>Hire Date</label><input id="em-hire_date" type="date"></div>
        <div class="em-field"><label>License Renewal (MM-DD)</label><input id="em-license_renewal" placeholder="03-31" maxlength="5"></div>

        <div class="em-section">MLS Information</div>
        <div class="em-field"><label>MLS Board</label><input id="em-mls_board"></div>
        <div class="em-field"><label>MLS ID</label><input id="em-mls_id"></div>

        <div class="em-section">INNOVATE Office</div>
        <div class="em-field em-full"><label>Office Location</label><input id="em-office_location"></div>

        <div class="em-section">Professional Background</div>
        <div class="em-field">
          <label>Specialty</label>
          <select id="em-specialty">
            <option value=""></option>
            <option value="Residential">Residential</option>
            <option value="Commercial">Commercial</option>
            <option value="Luxury">Luxury</option>
            <option value="Land/Farm">Land/Farm</option>
            <option value="New Construction">New Construction</option>
            <option value="Property Management">Property Management</option>
            <option value="Relocation">Relocation</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="em-field"><label>Career Start</label><input id="em-career_start" type="date"></div>
        <div class="em-field"><label>Prior Occupation</label><input id="em-prior_occupation"></div>
        <div class="em-field"><label>Prior Affiliation</label><input id="em-prior_affiliation"></div>
        <div class="em-field em-check"><label><input type="checkbox" id="em-full_time"> Full-Time Agent</label></div>
        <div class="em-field em-check"><label><input type="checkbox" id="em-show_on_internet"> Show on Website</label></div>

        <div class="em-section">Business Entity &amp; Tax IDs</div>
        <div class="em-field"><label>Personal Tax ID / SSN <span id="em-personal-tax-hint" style="text-transform:none;font-weight:400"></span></label><input id="em-personal_tax_id" placeholder="Leave blank to keep existing"></div>
        <div class="em-field"><label>Corporate Tax ID / EIN <span id="em-corporate-tax-hint" style="text-transform:none;font-weight:400"></span></label><input id="em-corporate_tax_id" placeholder="Leave blank to keep existing"></div>
        <div class="em-field"><label>Corporation Start</label><input id="em-corporation_start" type="date"></div>
        <div class="em-field"><label>Corporation End</label><input id="em-corporation_end" type="date"></div>

        <div class="em-section">Personal Information</div>
        <div class="em-field"><label>Birthday</label><input id="em-birthday" type="date"></div>
        <div class="em-field"><label>Spouse / SO Name</label><input id="em-spouse_name"></div>
        <div class="em-field">
          <label>Gender</label>
          <select id="em-gender">
            <option value=""></option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Prefer not to say">Prefer not to say</option>
          </select>
        </div>
        <div class="em-field"><label>Driver's License #</label><input id="em-drivers_license"></div>
        <div class="em-field">
          <label>T-Shirt Size</label>
          <select id="em-tshirt_size">
            <option value=""></option>
            <option value="XS">XS</option><option value="S">S</option><option value="M">M</option>
            <option value="L">L</option><option value="XL">XL</option><option value="2XL">2XL</option><option value="3XL">3XL</option>
          </select>
        </div>
        <div class="em-field"><label>Military</label><input id="em-is_military" placeholder="veteran / active / blank"></div>
        <div class="em-field"><label>First Responder</label><input id="em-first_responder" placeholder="e.g. paramedic, or blank"></div>
        <div class="em-field"><label>Teacher</label><input id="em-is_teacher" placeholder="no / current / former"></div>
        <div class="em-field"><label>Languages</label><input id="em-languages"></div>

        <div class="em-section">Emergency Contact</div>
        <div class="em-field"><label>Emergency Contact Name</label><input id="em-emergency_name"></div>
        <div class="em-field"><label>Emergency Contact Phone</label><input id="em-emergency_phone"></div>

        <div class="em-section">Online Presence</div>
        <div class="em-field"><label>Website</label><input id="em-website"></div>
        <div class="em-field"><label>Additional Websites</label><input id="em-additional_websites"></div>
        <div class="em-field"><label>Facebook</label><input id="em-facebook"></div>
        <div class="em-field"><label>LinkedIn</label><input id="em-linkedin"></div>
        <div class="em-field"><label>Skype</label><input id="em-skype"></div>

        <div class="em-section">Bio &amp; Marketing</div>
        <div class="em-field"><label>Referring Agent</label><input id="em-referring_agent"></div>
        <div class="em-field em-full"><label>Bio</label><textarea id="em-bio" style="min-height:110px"></textarea></div>
      </div>

    </div>
    <div class="modal-footer">
      <button type="button" class="btn-save" id="em-save-btn" onclick="saveEditModal()">Save Changes</button>
      <button type="button" class="btn-detail-link" onclick="closeEditModal()">Cancel</button>
      <span id="em-save-msg" style="font-size:12px;color:var(--faint)"></span>
    </div>
  </div>
</div>

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
    if (!span.dataset.maskedHtml) span.dataset.maskedHtml = span.innerHTML;
    var btn = span.querySelector('button');
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    fetch('api/tax_id_reveal.php?email=' + encodeURIComponent(email) + '&field=' + field, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) {
          span.textContent = (res.value || '(none on file)') + ' ';
          var hideBtn = document.createElement('button');
          hideBtn.type = 'button';
          hideBtn.className = 'btn-detail-link';
          hideBtn.style.padding = '2px 8px';
          hideBtn.style.fontSize = '10px';
          hideBtn.textContent = 'Hide';
          hideBtn.onclick = function () { span.innerHTML = span.dataset.maskedHtml; };
          span.appendChild(hideBtn);
        } else {
          span.textContent = 'Error: ' + (res.error || 'reveal failed');
        }
      })
      .catch(function () { span.textContent = 'Network error.'; });
  };

  // Recruit Source — populate every row's dropdown from the live agent
  // roster (fetched once, not per-row) so it always reflects who's currently
  // active rather than a static list baked into the page.
  fetch('api/roster.php', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var agents = (d.agents || []).filter(function (a) { return a.email; })
        .sort(function (a, b) { return a.name.localeCompare(b.name); });
      var opts = '<option value="">— none —</option>' + agents.map(function (a) {
        return '<option value="' + a.email.toLowerCase() + '">' + (a.name.replace(/[&<>"]/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        })) + '</option>';
      }).join('');
      document.querySelectorAll('.rs-select').forEach(function (sel) {
        sel.innerHTML = opts;
        sel.value = (sel.dataset.current || '').toLowerCase();
      });
    })
    .catch(function () {});

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
      managed_by: document.getElementById('admin-managed-' + idx).value,
      recruit_source_email: document.getElementById('admin-recruitsrc-' + idx).value
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

  var EM_FIELDS = ['full_name','phone','personal_email','commissions_email','phone_last4',
    'address_line1','address_line2','city','state','zip','country',
    'license_number','license_state','license_exp','nar_number',
    'mls_board','mls_id','office_location',
    'specialty','career_start','prior_occupation','prior_affiliation',
    'corporation_start','corporation_end',
    'birthday','spouse_name','gender','drivers_license','tshirt_size',
    'is_military','first_responder','is_teacher','languages',
    'emergency_name','emergency_phone',
    'website','additional_websites','facebook','linkedin','skype',
    'referring_agent','bio'];
  var EM_CHECK_FIELDS = ['full_time', 'show_on_internet'];
  var emCurrentEmail = null;
  // agent_extra's MM-DD birthday (calendar reminder) is a different field from
  // agent_intake's full-date birthday shown in this modal — round-trip it
  // untouched so saving the modal never blanks it out.
  var emExtraBirthday = '';

  window.openEditModal = function (email, name) {
    emCurrentEmail = email;
    document.getElementById('em-agent-name').textContent = name;
    document.getElementById('em-save-msg').textContent = 'Loading…';
    document.getElementById('editModalOverlay').style.display = 'flex';

    Promise.all([
      fetch('api/intake.php?email=' + encodeURIComponent(email), { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
      fetch('api/agent_extra.php?email=' + encodeURIComponent(email), { credentials: 'same-origin' }).then(function (r) { return r.json(); })
    ]).then(function (results) {
      var intake = results[0].intake || {};
      var extra = results[1] || {};
      emExtraBirthday = extra.birthday || '';

      EM_FIELDS.forEach(function (key) {
        var node = document.getElementById('em-' + key);
        if (node) node.value = intake[key] || '';
      });
      EM_CHECK_FIELDS.forEach(function (key) {
        var node = document.getElementById('em-' + key);
        if (node) node.checked = intake[key] === undefined ? true : Number(intake[key]) === 1;
      });
      document.getElementById('em-hire_date').value = extra.hire_date || '';
      document.getElementById('em-license_renewal').value = extra.license_renewal || '';
      document.getElementById('em-personal_tax_id').value = '';
      document.getElementById('em-corporate_tax_id').value = '';
      document.getElementById('em-personal-tax-hint').textContent = intake.personal_tax_id_last4 ? '(on file, ending in ' + intake.personal_tax_id_last4 + ')' : '(none on file)';
      document.getElementById('em-corporate-tax-hint').textContent = intake.corporate_tax_id_last4 ? '(on file, ending in ' + intake.corporate_tax_id_last4 + ')' : '(none on file)';
      document.getElementById('em-save-msg').textContent = '';
    }).catch(function () {
      document.getElementById('em-save-msg').textContent = 'Failed to load agent data.';
    });
  };

  window.closeEditModal = function () {
    document.getElementById('editModalOverlay').style.display = 'none';
    emCurrentEmail = null;
  };

  window.saveEditModal = function () {
    if (!emCurrentEmail) return;
    var msg = document.getElementById('em-save-msg');
    var btn = document.getElementById('em-save-btn');
    btn.disabled = true;
    msg.textContent = 'Saving…';

    var payload = { email: emCurrentEmail };
    EM_FIELDS.forEach(function (key) {
      var node = document.getElementById('em-' + key);
      if (node) payload[key] = node.value;
    });
    EM_CHECK_FIELDS.forEach(function (key) {
      var node = document.getElementById('em-' + key);
      if (node) payload[key] = node.checked;
    });
    payload.personal_tax_id = document.getElementById('em-personal_tax_id').value;
    payload.corporate_tax_id = document.getElementById('em-corporate_tax_id').value;

    var extraPayload = {
      email: emCurrentEmail,
      birthday: emExtraBirthday,
      hire_date: document.getElementById('em-hire_date').value,
      license_renewal: document.getElementById('em-license_renewal').value
    };

    Promise.all([
      fetch('api/intake.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(function (r) { return r.json(); }),
      fetch('api/agent_extra.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(extraPayload)
      }).then(function (r) { return r.json(); })
    ]).then(function (results) {
      btn.disabled = false;
      var intakeRes = results[0], extraRes = results[1];
      if (intakeRes.ok && extraRes.ok) {
        msg.textContent = 'Saved ✓ Reloading…';
        setTimeout(function () { location.reload(); }, 600);
      } else {
        msg.textContent = intakeRes.error || extraRes.error || 'Save failed.';
      }
    }).catch(function () {
      btn.disabled = false;
      msg.textContent = 'Network error.';
    });
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
