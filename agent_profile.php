<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/crypto.php';
require_once __DIR__ . '/lib/agent_profile.php';

$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) { header('Location: index.php'); exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function dv($val): string {
    if ($val === '' || $val === null) return '<span class="dg-value empty">—</span>';
    return '<span class="dg-value">' . h($val) . '</span>';
}
function dvBool($val): string {
    return '<span class="dg-value">' . ($val ? 'Yes' : 'No') . '</span>';
}

$targetEmail = strtolower(trim($_GET['email'] ?? ''));

$TABS = [
    'profile'        => 'Agent Profile',
    'permission'     => 'Agent Permission',
    'documents'      => 'Documents',
    'commission'     => 'Commission Plan & Fees',
    'billing'        => 'Agent Billing',
    'billing_log'    => 'Billing Log',
    'commission_log' => 'Commission Log',
    'notes'          => 'Notes',
    'checklist'      => 'Task Checklist',
    'other_income'   => 'Other Income',
    'network'        => 'Network Tree',
    'history'        => 'History',
];
$tab = $_GET['tab'] ?? 'profile';
if (!isset($TABS[$tab])) $tab = 'profile';

$canEditPermissions = is_super_admin();

$profileData = $targetEmail !== '' ? load_agent_profile($targetEmail) : null;
$extraLicenses = $targetEmail !== '' ? load_agent_additional_licenses($targetEmail) : [];
$headshotCount = $targetEmail !== '' ? load_agent_headshot_count($targetEmail) : 0;
$headshotKey   = $targetEmail !== '' ? load_agent_latest_headshot($targetEmail) : null;
$queueStatus   = $targetEmail !== '' ? load_agent_queue_status($targetEmail) : ['onboarding' => null, 'offboarding' => null];

$notes = [];
if ($targetEmail !== '') {
    $nst = local_db()->prepare("SELECT id, note, created_by, created_at FROM agent_notes WHERE email=? ORDER BY created_at DESC, id DESC");
    $nst->execute([$targetEmail]);
    $notes = $nst->fetchAll(PDO::FETCH_ASSOC);
}

$displayName = $profileData['full_name'] ?? $targetEmail;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($displayName ?: 'Agent Profile') ?> — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/app.css">
<style>
.bo-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--faint)}
.ap-tabs{display:flex;gap:0;border-bottom:2px solid #E6E7E8;margin-bottom:20px;flex-wrap:wrap}
.ap-tab-btn{padding:9px 16px;border:none;background:none;font-size:13px;font-weight:700;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;color:#666;white-space:nowrap}
.ap-tab-btn.active{color:#000;border-bottom-color:#82C112}
.ap-tab-pane{display:none}.ap-tab-pane.active{display:block}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px}
.dg-section{grid-column:1/-1;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--faint);margin-top:12px;padding-top:10px;border-top:1px solid var(--border)}
.dg-section:first-child{margin-top:0;padding-top:0;border-top:none}
.dg-field{display:flex;flex-direction:column;gap:2px}
.dg-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--faint)}
.dg-value{font-size:12px;color:var(--ink)}
.dg-value.empty{color:var(--faint);font-style:italic}
.dg-bio{grid-column:1/-1}
.dg-bio .dg-value{white-space:pre-wrap;font-size:12px;line-height:1.55;max-height:140px;overflow-y:auto}
.detail-actions{grid-column:1/-1;margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.btn-detail-link{font-size:11px;font-weight:700;padding:5px 12px;border-radius:5px;border:1px solid var(--border);background:#fff;color:var(--ink);text-decoration:none;white-space:nowrap;cursor:pointer}
.btn-detail-link:hover{border-color:var(--green);color:#5b8e0d;background:#f0f8e8}
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
.stub-pane{padding:40px;text-align:center;color:var(--faint);font-size:13px}
.ap-header-row{display:flex;align-items:center;gap:14px}
.ap-avatar-img{width:52px;height:52px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1px solid var(--border)}
.ap-avatar-fallback{width:52px;height:52px;border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-size:18px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.doc-list{display:flex;flex-direction:column;gap:8px;margin-top:16px}
.doc-card{display:flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:8px;padding:10px 14px;background:#fafbfa}
.doc-icon{font-size:18px;flex-shrink:0}
.doc-info{flex:1;min-width:0}
.doc-name{font-size:13px;font-weight:700;color:var(--ink)}
.doc-meta{font-size:11px;color:var(--faint)}
.doc-actions{display:flex;gap:6px;flex-shrink:0}
.upload-row{display:flex;gap:8px;align-items:center;margin-bottom:6px}
.notes-list{display:flex;flex-direction:column;gap:10px;margin-top:16px}
.note-card{border:1px solid var(--border);border-radius:8px;padding:10px 14px;background:#fafbfa}
.note-meta{font-size:11px;color:var(--faint);margin-bottom:4px}
.note-body{font-size:13px;white-space:pre-wrap}
.checklist-line{padding:10px 0;border-bottom:1px solid var(--border);font-size:13px}
.checklist-line:last-child{border-bottom:none}
.field-select{padding:7px 10px;font-size:13px;border:1px solid #ccc;border-radius:4px;background:white;width:100%}
.mc-checks{display:flex;flex-wrap:wrap;gap:8px 16px;margin-top:10px}
.mc-check{display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer}
.mc-check input{accent-color:#82C112}
.mc-led-section{display:none}.mc-led-section.visible{display:block}
.field-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#666;margin-bottom:4px}
.form-grid-perm{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;align-items:start;max-width:760px}

/* Network Tree tab (adapted from network.php) */
.root-card{display:flex;align-items:center;gap:14px;padding:14px 18px;background:#f9fdf5;border:2px solid #82C112;border-radius:10px;margin-bottom:20px}
.root-avatar{width:44px;height:44px;border-radius:50%;background:#82C112;color:#000;font-size:14px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.root-info{flex:1;min-width:0}
.root-name{font-size:15px;font-weight:800;color:#111}
.root-email{font-size:12px;color:#666}
.root-chips{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;flex-shrink:0}
.line-summary{display:flex;gap:0;border:1px solid #e6e7e8;border-radius:8px;overflow:hidden;margin-bottom:20px}
.line-pill{flex:1;text-align:center;padding:9px 6px;font-size:12px;cursor:pointer;border-right:1px solid #e6e7e8;background:white;transition:background 100ms}
.line-pill:last-child{border-right:none}
.line-pill:hover{background:#f9fdf5}
.line-pill.zero{color:#ccc;cursor:default}
.line-pill .lp-count{font-size:16px;font-weight:800;color:#111;display:block}
.line-pill.zero .lp-count{color:#ddd}
.line-pill .lp-label{font-size:10px;text-transform:uppercase;letter-spacing:.06em;color:#888;display:block;margin-top:1px}
.level-section{margin-bottom:16px}
.level-header{display:flex;align-items:baseline;gap:8px;margin-bottom:8px}
.level-badge{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;padding:3px 8px;border-radius:10px;color:#fff}
.badge-1{background:#82C112}.badge-2{background:#5b8e0d}.badge-3{background:#4a7a0a}.badge-4{background:#3a6307}.badge-5{background:#2b4d04}
.level-title{font-size:13px;font-weight:700;color:#333}
.agent-strip{display:flex;gap:8px;overflow-x:auto;padding-bottom:8px;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
.ag-card{flex-shrink:0;scroll-snap-align:start;display:flex;flex-direction:column;align-items:center;gap:3px;padding:10px 10px 8px;background:white;border:1.5px solid #e6e7e8;border-radius:8px;cursor:pointer;min-width:108px;max-width:130px;text-align:center;transition:border-color 100ms,box-shadow 100ms;position:relative}
.ag-card:hover{border-color:#c3dfa8;background:#fafff5}
.ag-card.selected{border-color:#82C112;background:#f9fdf5;box-shadow:0 2px 8px rgba(130,193,18,.2)}
.ag-card.no-kids{opacity:.7;cursor:default}
.ag-avatar{width:34px;height:34px;border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ag-card.selected .ag-avatar{background:#82C112;color:#000}
.ag-name{font-size:11px;font-weight:700;color:#222;line-height:1.2;word-break:break-word}
.ag-vol{font-size:13px;font-weight:800;color:#2d7a00;margin-top:2px}
.ag-vol.zero{color:#ccc;font-weight:500}
.ag-deals{font-size:10px;color:#888}
.ag-deals span{color:#555;font-weight:700}
.ag-divider{width:70%;height:1px;background:#f0f0f0;margin:3px 0}
.ag-count{font-size:10px;padding:1px 6px;border-radius:8px;background:#fff4e0;color:#a06000;font-weight:700}
.ag-no-rec{font-size:10px;color:#ccc}
.level-divider{height:2px;background:linear-gradient(to right,#82C112 0%,#e6e7e8 60%);border-radius:1px;margin:4px 0 14px}
.empty-prompt{padding:28px;text-align:center;color:#bbb;font-size:13px;border:1px dashed #e0e0e0;border-radius:8px}
.loading-msg{padding:28px;text-align:center;color:#888;font-size:13px}
.error-msg{padding:14px 18px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-top:8px}
.sponsor-card{display:flex;align-items:center;gap:12px;padding:10px 16px;background:#f5f5f5;border:1px solid #e0e0e0;border-radius:8px;margin-bottom:8px}
.sponsor-avatar{width:34px;height:34px;border-radius:50%;background:#ddd;color:#555;font-size:12px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sponsor-info{flex:1;min-width:0}
.sponsor-label{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#999;margin-bottom:1px}
.sponsor-name{font-size:13px;font-weight:700;color:#333}
.sponsor-email{font-size:11px;color:#999}
.sponsor-connector{text-align:center;font-size:14px;color:#ccc;line-height:1;margin-bottom:4px;margin-top:-4px}
.chip{font-size:10px;padding:2px 6px;border-radius:8px;font-weight:700;white-space:nowrap}
.chip-rec{background:#fff4e0;color:#a06000}
</style>
</head>
<body>
<div class="layout">
<?php render_sidebar('backoffice_agents', $agent); ?>
<div class="content">
  <div class="content-top">
    <div class="ap-header-row">
      <?php if ($headshotKey): ?>
        <img class="ap-avatar-img" src="api/intake.php?action=headshot&key=<?= urlencode($headshotKey) ?>" alt="">
      <?php else: ?>
        <div class="ap-avatar-fallback"><?php
          $initials = '';
          foreach (preg_split('/\s+/', trim($displayName ?: '?')) as $part) { if ($part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
          echo h(mb_substr($initials ?: '?', 0, 2));
        ?></div>
      <?php endif; ?>
      <div>
        <div class="bo-eyebrow">Agent</div>
        <div class="content-title"><?= h($displayName ?: 'Agent Profile') ?></div>
      </div>
    </div>
    <a href="backoffice_agents.php" class="btn-detail-link">← Back to Agent Profiles</a>
  </div>
  <div class="wrap">

<?php if ($targetEmail === ''): ?>
    <div class="card" style="padding:30px;text-align:center;color:var(--faint)">No agent specified.</div>
<?php else: ?>

    <div class="ap-tabs">
      <?php foreach ($TABS as $key => $label): ?>
        <button class="ap-tab-btn<?= $tab === $key ? ' active' : '' ?>" data-tab="<?= h($key) ?>" onclick="switchApTab('<?= h($key) ?>')"><?= h($label) ?></button>
      <?php endforeach; ?>
    </div>

    <!-- ── AGENT PROFILE ─────────────────────────────────────────────────── -->
    <div id="ap-tab-profile" class="ap-tab-pane<?= $tab === 'profile' ? ' active' : '' ?>">
      <div class="card" style="padding:20px 24px">
      <?php if (!$profileData): ?>
        <div class="stub-pane">No intake form on file for this agent yet.</div>
      <?php else: $a = $profileData; ?>
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
              <span class="dg-value tax-id-mask" id="ptax">•••••<?= h($personalLast4) ?>
                <button type="button" class="btn-detail-link" style="padding:2px 8px;font-size:10px" onclick="revealTaxId('personal','ptax')">Reveal</button>
              </span>
            <?php else: ?>
              <span class="dg-value empty">—</span>
            <?php endif; ?>
          </div>
          <div class="dg-field">
            <span class="dg-label">Corporate Tax ID (EIN)</span>
            <?php if ($corporateLast4 !== ''): ?>
              <span class="dg-value tax-id-mask" id="ctax">•••••<?= h($corporateLast4) ?>
                <button type="button" class="btn-detail-link" style="padding:2px 8px;font-size:10px" onclick="revealTaxId('corporate','ctax')">Reveal</button>
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

          <?php if ($headshotCount > 0): ?>
          <div class="dg-section">Headshots</div>
          <div class="dg-field" style="grid-column:1/-1">
            <span class="dg-value"><?= $headshotCount ?> headshot<?= $headshotCount !== 1 ? 's' : '' ?> on file — <a href="intake.php" target="_blank" style="color:var(--green-d)">view in intake form</a></span>
          </div>
          <?php endif; ?>

          <div class="dg-section">Staff-Managed <span style="font-weight:400;text-transform:none;letter-spacing:0">(not visible to the agent)</span></div>
          <div class="dg-field">
            <span class="dg-label">1099 Type</span>
            <select id="admin-1099type" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
              <option value="">— none —</option>
              <?php foreach (['1099-NEC', '1099-MISC', 'W-2', 'N/A'] as $opt): ?>
                <option value="<?= h($opt) ?>" <?= ($a['tax_1099_type'] ?? '') === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="dg-field">
            <span class="dg-label">Gets 1099?</span>
            <label style="font-size:12px"><input type="checkbox" id="admin-gets1099" style="width:auto;vertical-align:middle;margin-right:6px" <?= !empty($a['gets_1099']) || $a['gets_1099'] === null ? 'checked' : '' ?>> Yes</label>
          </div>
          <div class="dg-field">
            <span class="dg-label">Terminated Date</span>
            <input type="date" id="admin-terminated" value="<?= h($a['terminated_date'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
          </div>
          <div class="dg-field">
            <span class="dg-label">Agent Team</span>
            <input type="text" id="admin-team" value="<?= h($a['agent_team'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
          </div>
          <div class="dg-field">
            <span class="dg-label">Coached By</span>
            <input type="text" id="admin-coached" value="<?= h($a['coached_by'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
          </div>
          <div class="dg-field">
            <span class="dg-label">Managed By</span>
            <input type="text" id="admin-managed" value="<?= h($a['managed_by'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
          </div>
          <div class="dg-field">
            <span class="dg-label">Recruit Source</span>
            <select id="admin-recruitsrc" class="rs-select" data-current="<?= h($a['recruit_source_email'] ?? '') ?>" style="font-size:12px;padding:4px 6px;border:1px solid var(--border);border-radius:5px">
              <option value="">— none —</option>
            </select>
          </div>
          <div class="dg-field" style="grid-column:1/-1">
            <button type="button" class="btn-detail-link" onclick="saveAdminFields()">Save Staff-Managed Fields</button>
            <span id="admin-save-msg" style="font-size:11px;color:var(--faint);margin-left:8px"></span>
          </div>

          <div class="detail-actions">
            <?php if (!empty($a['submitted'])): ?>
              <span style="font-size:11px;color:var(--faint)">Submitted <?= h($a['submitted_at'] ? date('M j, Y', strtotime($a['submitted_at'])) : '—') ?></span>
            <?php else: ?>
              <span style="font-size:11px;color:var(--faint)">Last updated <?= h($a['updated_at'] ? date('M j, Y', strtotime($a['updated_at'])) : '—') ?></span>
            <?php endif; ?>
            <a href="onboarding.php" target="_blank" class="btn-detail-link">Onboarding Steps →</a>
            <button type="button" class="btn-detail-link" onclick="openEditModal()">Edit Profile →</button>
          </div>

        </div>
      <?php endif; ?>
      </div>
    </div>

    <!-- ── AGENT PERMISSION ──────────────────────────────────────────────── -->
    <div id="ap-tab-permission" class="ap-tab-pane<?= $tab === 'permission' ? ' active' : '' ?>">
      <div class="card" style="padding:20px 24px">
        <?php if (!$canEditPermissions): ?>
          <div class="stub-pane">Super Admin access required.</div>
        <?php else: ?>
          <div id="permission-wrap"><div class="stub-pane">Loading…</div></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── DOCUMENTS ──────────────────────────────────────────────────────── -->
    <div id="ap-tab-documents" class="ap-tab-pane<?= $tab === 'documents' ? ' active' : '' ?>">
      <div class="card" style="padding:20px 24px">
        <div class="upload-row">
          <input type="file" id="doc-upload-file">
          <button type="button" class="btn-detail-link" onclick="uploadDocument()">Upload</button>
          <span id="doc-upload-msg" style="font-size:11px;color:var(--faint)"></span>
        </div>
        <div class="doc-list" id="doc-list"><div class="stub-pane">Loading…</div></div>
      </div>
    </div>

    <!-- ── COMMISSION PLAN & FEES (stub) ─────────────────────────────────── -->
    <div id="ap-tab-commission" class="ap-tab-pane<?= $tab === 'commission' ? ' active' : '' ?>">
      <div class="card"><div class="stub-pane">This section is pending Darwin API integration.</div></div>
    </div>

    <!-- ── AGENT BILLING (stub) ──────────────────────────────────────────── -->
    <div id="ap-tab-billing" class="ap-tab-pane<?= $tab === 'billing' ? ' active' : '' ?>">
      <div class="card"><div class="stub-pane">This section is pending Darwin API integration.</div></div>
    </div>

    <!-- ── BILLING LOG (stub) ─────────────────────────────────────────────── -->
    <div id="ap-tab-billing_log" class="ap-tab-pane<?= $tab === 'billing_log' ? ' active' : '' ?>">
      <div class="card"><div class="stub-pane">This section is pending Darwin API integration.</div></div>
    </div>

    <!-- ── COMMISSION LOG (stub) ─────────────────────────────────────────── -->
    <div id="ap-tab-commission_log" class="ap-tab-pane<?= $tab === 'commission_log' ? ' active' : '' ?>">
      <div class="card"><div class="stub-pane">This section is pending Darwin API integration.</div></div>
    </div>

    <!-- ── NOTES ──────────────────────────────────────────────────────────── -->
    <div id="ap-tab-notes" class="ap-tab-pane<?= $tab === 'notes' ? ' active' : '' ?>">
      <div class="card" style="padding:20px 24px">
        <textarea id="new-note-text" placeholder="Add a note about this agent…" style="width:100%;min-height:80px;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:inherit;box-sizing:border-box"></textarea>
        <div style="margin-top:8px">
          <button type="button" class="btn-detail-link" onclick="addNote()">Add Note</button>
          <span id="note-save-msg" style="font-size:11px;color:var(--faint);margin-left:8px"></span>
        </div>
        <div class="notes-list" id="notes-list">
          <?php if (!$notes): ?>
            <div class="stub-pane" style="padding:20px">No notes yet.</div>
          <?php else: foreach ($notes as $n): ?>
            <div class="note-card">
              <div class="note-meta"><?= h($n['created_by']) ?> — <?= h(date('M j, Y g:ia', strtotime($n['created_at']))) ?></div>
              <div class="note-body"><?= h($n['note']) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- ── TASK CHECKLIST ────────────────────────────────────────────────── -->
    <div id="ap-tab-checklist" class="ap-tab-pane<?= $tab === 'checklist' ? ' active' : '' ?>">
      <div class="card" style="padding:20px 24px">
        <div class="checklist-line">
          <strong>Onboarding:</strong>
          <?php if ($queueStatus['onboarding']): $ob = $queueStatus['onboarding']; ?>
            <?= h(ucfirst($ob['status'])) ?> — added <?= h(date('M j, Y', strtotime($ob['added_at']))) ?>
            — <a href="onboarding.php?open=<?= (int)$ob['id'] ?>" target="_blank" class="btn-detail-link" style="margin-left:4px">View Checklist →</a>
          <?php else: ?>
            <span class="dg-value empty">No onboarding record on file.</span>
          <?php endif; ?>
        </div>
        <div class="checklist-line">
          <strong>Offboarding:</strong>
          <?php if ($queueStatus['offboarding']): $off = $queueStatus['offboarding']; ?>
            <?= h(ucfirst($off['status'])) ?> — added <?= h(date('M j, Y', strtotime($off['added_at']))) ?>
            — <a href="offboarding.php?open=<?= (int)$off['id'] ?>" target="_blank" class="btn-detail-link" style="margin-left:4px">View Checklist →</a>
          <?php else: ?>
            <span class="dg-value empty">No offboarding record on file.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ── OTHER INCOME (stub) ───────────────────────────────────────────── -->
    <div id="ap-tab-other_income" class="ap-tab-pane<?= $tab === 'other_income' ? ' active' : '' ?>">
      <div class="card"><div class="stub-pane">This section is pending Darwin API integration.</div></div>
    </div>

    <!-- ── NETWORK TREE ───────────────────────────────────────────────────── -->
    <div id="ap-tab-network" class="ap-tab-pane<?= $tab === 'network' ? ' active' : '' ?>">
      <div class="card" style="padding:20px 24px">
        <div id="tree-wrap"><div class="stub-pane">Open this tab to load the network tree.</div></div>
      </div>
    </div>

    <!-- ── HISTORY (stub) ────────────────────────────────────────────────── -->
    <div id="ap-tab-history" class="ap-tab-pane<?= $tab === 'history' ? ' active' : '' ?>">
      <div class="card"><div class="stub-pane">This section is pending audit-log infrastructure.</div></div>
    </div>

    <!-- ── EDIT PROFILE MODAL ────────────────────────────────────────────── -->
    <div class="modal-overlay" id="editModalOverlay" style="display:none">
      <div class="modal-box">
        <div class="modal-header">
          <h3>Edit Profile — <span id="em-agent-name"><?= h($displayName) ?></span></h3>
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

<?php endif; ?>

  </div>
</div>
</div>

<script>
const PROFILE_EMAIL = <?= json_encode($targetEmail) ?>;
const CAN_EDIT_PERMISSIONS = <?= json_encode($canEditPermissions) ?>;
let networkLoaded = false;
let permissionLoaded = false;
let documentsLoaded = false;

window.switchApTab = function (t) {
  document.querySelectorAll('.ap-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === t));
  document.querySelectorAll('.ap-tab-pane').forEach(p => p.classList.toggle('active', p.id === 'ap-tab-' + t));
  history.replaceState(null, '', 'agent_profile.php?email=' + encodeURIComponent(PROFILE_EMAIL) + '&tab=' + t);
  if (t === 'network' && !networkLoaded) { networkLoaded = true; loadNetworkTree(); }
  if (t === 'permission' && CAN_EDIT_PERMISSIONS && !permissionLoaded) { permissionLoaded = true; loadPermissionTab(); }
  if (t === 'documents' && !documentsLoaded) { documentsLoaded = true; loadDocuments(); }
};

// ── Documents tab ────────────────────────────────────────────────────────────
function fmtBytes(n) {
  n = parseInt(n || 0, 10);
  if (n >= 1024 * 1024) return (n / (1024 * 1024)).toFixed(1) + ' MB';
  if (n >= 1024) return Math.round(n / 1024) + ' KB';
  return n + ' B';
}

window.loadDocuments = function () {
  var list = document.getElementById('doc-list');
  list.innerHTML = '<div class="stub-pane">Loading…</div>';
  fetch('api/agent_documents.php?email=' + encodeURIComponent(PROFILE_EMAIL), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (!res.ok) { list.innerHTML = '<div class="stub-pane">' + (res.error || 'Failed to load documents.') + '</div>'; return; }
      var docs = res.documents || [];
      if (!docs.length) { list.innerHTML = '<div class="stub-pane">No documents on file.</div>'; return; }
      list.innerHTML = docs.map(function (d) {
        var srcLabel = d.source === 'pandadoc' ? 'Signed via PandaDoc' : 'Uploaded by ' + (d.uploaded_by || 'admin');
        return '<div class="doc-card">' +
          '<span class="doc-icon">📄</span>' +
          '<div class="doc-info">' +
            '<div class="doc-name">' + esc(d.name) + '</div>' +
            '<div class="doc-meta">' + esc(srcLabel) + ' · ' + esc(fmtBytes(d.size_bytes)) + ' · ' + esc(d.created_at) + '</div>' +
          '</div>' +
          '<div class="doc-actions">' +
            '<a class="btn-detail-link" href="api/agent_documents.php?action=download&key=' + encodeURIComponent(d.storage_key) + '" target="_blank">View</a>' +
            '<button type="button" class="btn-detail-link" onclick="deleteDocument(\'' + d.storage_key + '\', this)">Delete</button>' +
          '</div>' +
        '</div>';
      }).join('');
    })
    .catch(function () { list.innerHTML = '<div class="stub-pane">Network error.</div>'; });
};

window.uploadDocument = function () {
  var fileInput = document.getElementById('doc-upload-file');
  var msg = document.getElementById('doc-upload-msg');
  var file = fileInput.files[0];
  if (!file) { msg.textContent = 'Choose a file first.'; return; }
  var fd = new FormData();
  fd.append('email', PROFILE_EMAIL);
  fd.append('file', file);
  msg.textContent = 'Uploading…';
  fetch('api/agent_documents.php?action=upload', { method: 'POST', credentials: 'same-origin', body: fd })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (!res.ok) { msg.textContent = res.error || 'Upload failed.'; return; }
      msg.textContent = 'Uploaded ✓';
      fileInput.value = '';
      setTimeout(function () { msg.textContent = ''; }, 3000);
      loadDocuments();
    })
    .catch(function () { msg.textContent = 'Network error.'; });
};

window.deleteDocument = function (key, btn) {
  if (!confirm('Delete this document?')) return;
  btn.disabled = true;
  fetch('api/agent_documents.php?action=delete', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ key: key })
  })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (res.ok) { loadDocuments(); } else { btn.disabled = false; alert(res.error || 'Delete failed.'); }
    })
    .catch(function () { btn.disabled = false; alert('Network error.'); });
};

// ── Tax ID reveal ────────────────────────────────────────────────────────────
window.revealTaxId = function (field, spanId) {
  var span = document.getElementById(spanId);
  if (!span) return;
  if (!span.dataset.maskedHtml) span.dataset.maskedHtml = span.innerHTML;
  var btn = span.querySelector('button');
  if (btn) { btn.disabled = true; btn.textContent = '…'; }
  fetch('api/tax_id_reveal.php?email=' + encodeURIComponent(PROFILE_EMAIL) + '&field=' + field, { credentials: 'same-origin' })
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

// Recruit Source dropdown — populated once from the live agent roster.
var recruitSrcSelect = document.getElementById('admin-recruitsrc');
if (recruitSrcSelect) {
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
      recruitSrcSelect.innerHTML = opts;
      recruitSrcSelect.value = (recruitSrcSelect.dataset.current || '').toLowerCase();
    })
    .catch(function () {});
}

window.saveAdminFields = function () {
  var msg = document.getElementById('admin-save-msg');
  msg.textContent = 'Saving…';
  var payload = {
    email: PROFILE_EMAIL,
    tax_1099_type: document.getElementById('admin-1099type').value,
    gets_1099: document.getElementById('admin-gets1099').checked,
    terminated_date: document.getElementById('admin-terminated').value,
    agent_team: document.getElementById('admin-team').value,
    coached_by: document.getElementById('admin-coached').value,
    managed_by: document.getElementById('admin-managed').value,
    recruit_source_email: document.getElementById('admin-recruitsrc').value
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

// ── Edit Profile modal ──────────────────────────────────────────────────────
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
var emExtraBirthday = '';

window.openEditModal = function () {
  document.getElementById('em-save-msg').textContent = 'Loading…';
  document.getElementById('editModalOverlay').style.display = 'flex';

  Promise.all([
    fetch('api/intake.php?email=' + encodeURIComponent(PROFILE_EMAIL), { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
    fetch('api/agent_extra.php?email=' + encodeURIComponent(PROFILE_EMAIL), { credentials: 'same-origin' }).then(function (r) { return r.json(); })
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
};

window.saveEditModal = function () {
  var msg = document.getElementById('em-save-msg');
  var btn = document.getElementById('em-save-btn');
  btn.disabled = true;
  msg.textContent = 'Saving…';

  var payload = { email: PROFILE_EMAIL };
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
    email: PROFILE_EMAIL,
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

// ── Notes ────────────────────────────────────────────────────────────────────
window.addNote = function () {
  var textEl = document.getElementById('new-note-text');
  var msg = document.getElementById('note-save-msg');
  var note = textEl.value.trim();
  if (!note) return;
  msg.textContent = 'Saving…';
  fetch('api/agent_notes.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: PROFILE_EMAIL, note: note })
  })
    .then(function (r) { return r.json(); })
    .then(function (res) {
      if (!res.ok) { msg.textContent = res.error || 'Save failed.'; return; }
      var list = document.getElementById('notes-list');
      var emptyMsg = list.querySelector('.stub-pane');
      if (emptyMsg) emptyMsg.remove();
      var card = document.createElement('div');
      card.className = 'note-card';
      var meta = document.createElement('div');
      meta.className = 'note-meta';
      meta.textContent = res.created_by + ' — just now';
      var body = document.createElement('div');
      body.className = 'note-body';
      body.textContent = note;
      card.appendChild(meta);
      card.appendChild(body);
      list.insertBefore(card, list.firstChild);
      textEl.value = '';
      msg.textContent = 'Saved ✓';
      setTimeout(function () { msg.textContent = ''; }, 3000);
    })
    .catch(function () { msg.textContent = 'Network error.'; });
};

// ── Network Tree (adapted from network.php, rooted at PROFILE_EMAIL) ───────
const LINE_NAMES = ['','1st Line','2nd Line','3rd Line','4th Line','5th Line'];
let ROOT = null;
let path = [null, null, null, null, null];

function esc(s){ return String(s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function initials(n){ const p=n.trim().split(/\s+/); return p.length>=2?(p[0][0]+p[p.length-1][0]).toUpperCase():p[0].slice(0,2).toUpperCase(); }
function fmtMoney(n){ if(!n)return null; if(n>=1000000)return '$'+(n/1000000).toFixed(1).replace(/\.0$/,'')+' M'; if(n>=1000)return '$'+Math.round(n/1000)+'k'; return '$'+Math.round(n); }

function countByLevel(node, depth) {
  const counts = [0,0,0,0,0];
  function walk(n, d) {
    if (!n.children) return;
    n.children.forEach(c => {
      if (d >= 1 && d <= 5) counts[d-1]++;
      if (d < 5) walk(c, d+1);
    });
  }
  walk(node, depth);
  return counts;
}

function selectAgent(level, agent) {
  path[level-1] = agent;
  for (let i = level; i < 5; i++) path[i] = null;
  renderLevels();
}

function jumpToLevel(level) {
  let parent = ROOT;
  for (let l = 1; l < level; l++) {
    const kids = parent && parent.children || [];
    if (!kids.length) return;
    let next = path[l-1] && kids.includes(path[l-1]) ? path[l-1] : null;
    if (!next) next = kids.find(k => (k.children||[]).length > 0) || kids[0];
    path[l-1] = next;
    parent = next;
  }
  for (let i = level; i < 5; i++) path[i] = null;
  renderLevels();
}

function renderLevels() {
  const wrap = document.getElementById('levels-wrap');
  if (!wrap) return;
  wrap.innerHTML = '';

  for (let lvl = 1; lvl <= 5; lvl++) {
    const parent = lvl === 1 ? ROOT : path[lvl-2];
    if (!parent) break;

    const kids = parent.children || [];
    if (kids.length === 0 && lvl > 1) {
      const msg = document.createElement('div');
      msg.className = 'empty-prompt';
      msg.style.marginTop = '8px';
      msg.textContent = (parent.name||'This agent') + ' has no recruits yet.';
      wrap.appendChild(msg);
      break;
    }
    if (kids.length === 0) break;

    const section = document.createElement('div');
    section.className = 'level-section';

    if (lvl > 1) {
      const div = document.createElement('div');
      div.className = 'level-divider';
      section.appendChild(div);
    }

    const hdr = document.createElement('div');
    hdr.className = 'level-header';
    hdr.innerHTML = `
      <span class="level-badge badge-${lvl}">${LINE_NAMES[lvl]}</span>
      <span class="level-title">${esc(kids.length)} recruit${kids.length===1?'':'s'}${lvl>1?' of '+esc(parent.name):''}</span>`;
    section.appendChild(hdr);

    const strip = document.createElement('div');
    strip.className = 'agent-strip';
    const selectedAtThisLevel = path[lvl-1];

    kids.forEach(kid => {
      const hasKids = (kid.children||[]).length > 0;
      const isClickable = hasKids && lvl < 5;
      const isSelected = selectedAtThisLevel && selectedAtThisLevel === kid;
      const vol = fmtMoney(kid.volume);
      const deals = kid.deals || 0;
      const card = document.createElement('div');
      card.className = 'ag-card' + (isSelected ? ' selected' : '') + (!isClickable ? ' no-kids' : '');
      card.innerHTML = `
        <div class="ag-avatar">${esc(initials(kid.name))}</div>
        <div class="ag-name">${esc(kid.name)}</div>
        <div class="ag-vol${vol ? '' : ' zero'}">${vol || '—'}</div>
        <div class="ag-deals"><span>${deals}</span> deal${deals===1?'':'s'}</div>
        <div class="ag-divider"></div>
        ${hasKids ? `<div class="ag-count">${kid.children.length} recruit${kid.children.length===1?'':'s'}</div>` : '<div class="ag-no-rec">no recruits</div>'}`;
      if (isClickable) card.addEventListener('click', () => selectAgent(lvl, kid));
      strip.appendChild(card);
    });

    section.appendChild(strip);
    wrap.appendChild(section);

    if (!path[lvl-1]) break;
  }
}

function renderTree(tree, totalCount, sponsor) {
  const wrap = document.getElementById('tree-wrap');
  wrap.innerHTML = '';

  if (!tree) {
    wrap.innerHTML = '<div class="empty-prompt">No network data on file yet.</div>';
    return;
  }

  ROOT = tree;
  path = [null, null, null, null, null];

  if (sponsor) {
    const sponsorEl = document.createElement('div');
    const sVol = fmtMoney(sponsor.volume);
    sponsorEl.className = 'sponsor-card';
    sponsorEl.innerHTML = `
      <div class="sponsor-avatar">${esc(initials(sponsor.name))}</div>
      <div class="sponsor-info">
        <div class="sponsor-label">↑ Sponsored by</div>
        <div class="sponsor-name">${esc(sponsor.name)}</div>
        <div class="sponsor-email">${esc(sponsor.email||'')}${sVol ? ' · ' + sVol : ''}</div>
      </div>`;
    wrap.appendChild(sponsorEl);
    const connector = document.createElement('div');
    connector.className = 'sponsor-connector';
    connector.textContent = '│';
    wrap.appendChild(connector);
  }

  const vol = fmtMoney(tree.volume);
  const rootDeals = tree.deals || 0;
  const root = document.createElement('div');
  root.className = 'root-card';
  root.innerHTML = `
    <div class="root-avatar">${esc(initials(tree.name))}</div>
    <div class="root-info">
      <div class="root-name">${esc(tree.name)}</div>
      <div class="root-email">${esc(tree.email||'')}</div>
      <div style="margin-top:4px;display:flex;align-items:baseline;gap:10px">
        <span style="font-size:16px;font-weight:800;color:${vol?'#2d7a00':'#ccc'}">${vol||'—'}</span>
        <span style="font-size:11px;color:#888"><b style="color:#555">${rootDeals}</b> deal${rootDeals===1?'':'s'}</span>
      </div>
    </div>
    <div class="root-chips">
      <span class="chip chip-rec">${totalCount} in network</span>
    </div>`;
  wrap.appendChild(root);

  const lineCounts = countByLevel(tree, 1);
  const bar = document.createElement('div');
  bar.className = 'line-summary';
  LINE_NAMES.slice(1).forEach((name, i) => {
    const n = lineCounts[i];
    const pill = document.createElement('div');
    pill.className = 'line-pill' + (n === 0 ? ' zero' : '');
    pill.innerHTML = `<span class="lp-count">${n}</span><span class="lp-label">${name}</span>`;
    if (n > 0) pill.addEventListener('click', () => jumpToLevel(i + 1));
    bar.appendChild(pill);
  });
  wrap.appendChild(bar);

  const lvlWrap = document.createElement('div');
  lvlWrap.id = 'levels-wrap';
  wrap.appendChild(lvlWrap);

  renderLevels();
}

window.loadNetworkTree = function () {
  const wrap = document.getElementById('tree-wrap');
  wrap.innerHTML = '<div class="loading-msg">Loading network…</div>';
  fetch('api/network_tree.php?email=' + encodeURIComponent(PROFILE_EMAIL), { credentials: 'same-origin' })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(d => {
      if (d.error) { wrap.innerHTML = '<div class="error-msg">' + esc(d.error) + '</div>'; return; }
      renderTree(d.tree, d.totalCount||0, d.sponsor||null);
    })
    .catch(() => { wrap.innerHTML = '<div class="error-msg">Could not load network data.</div>'; });
};

// ── Agent Permission tab ────────────────────────────────────────────────────
const LEADER_ROLES = ['bic','mc_leader'];
const STAFF_ROLES  = ['super_admin','staff','mc_leader','bic','recruiter'];

window.loadPermissionTab = function () {
  const wrap = document.getElementById('permission-wrap');
  wrap.innerHTML = '<div class="stub-pane">Loading…</div>';
  fetch('api/agent_roles.php?email=' + encodeURIComponent(PROFILE_EMAIL), { credentials: 'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (!d.ok) { wrap.innerHTML = '<div class="stub-pane">' + esc(d.error || 'Failed to load.') + '</div>'; return; }
      renderPermissionForm(d);
    })
    .catch(() => { wrap.innerHTML = '<div class="stub-pane">Network error.</div>'; });
};

function renderPermissionForm(d) {
  const wrap = document.getElementById('permission-wrap');
  const roleOpts = Object.keys(d.role_labels).map(k =>
    `<option value="${esc(k)}"${d.role===k?' selected':''}>${esc(d.role_labels[k])}</option>`).join('');
  const mcOpts = Object.keys(d.mc_opts).map(slug =>
    `<option value="${esc(slug)}"${d.own_mc_slug===slug?' selected':''}>${esc(d.mc_opts[slug])}</option>`).join('');
  const bicOpts = d.bic_list.map(b =>
    `<option value="${esc(b.email)}"${d.bic_email===b.email?' selected':''}>${esc(b.name)} (${esc(b.email)})</option>`).join('');
  const mcChecks = Object.keys(d.mc_opts).map(slug =>
    `<label class="mc-check"><input type="checkbox" value="${esc(slug)}"${d.mc_slugs.includes(slug)?' checked':''}> ${esc(d.mc_opts[slug])}</label>`).join('');
  const bicRowHidden = STAFF_ROLES.includes(d.role);
  const mcLedVisible = LEADER_ROLES.includes(d.role);

  wrap.innerHTML = `
    <div class="form-grid-perm">
      <div>
        <div class="field-label">Role</div>
        <select id="perm-role" class="field-select">${roleOpts}</select>
      </div>
      <div>
        <div class="field-label">Their Market Center</div>
        <select id="perm-own-mc" class="field-select"><option value="">— not set —</option>${mcOpts}</select>
      </div>
      <div id="perm-bic-row" style="${bicRowHidden?'display:none':''}">
        <div class="field-label">Assigned BIC</div>
        <select id="perm-bic-email" class="field-select"><option value="">— not set —</option>${bicOpts}</select>
      </div>
    </div>
    <div id="perm-mc-led" class="mc-led-section${mcLedVisible?' visible':''}" style="margin-top:12px">
      <div class="field-label">Market Centers They Lead</div>
      <div class="mc-checks" id="perm-mc-checks">${mcChecks}</div>
    </div>
    <div style="margin-top:16px">
      <button type="button" class="btn-detail-link" onclick="savePermission()">Save</button>
      <button type="button" class="btn-detail-link" onclick="removePermission()">Clear Role / Placement</button>
      <span id="perm-save-msg" style="font-size:11px;color:var(--faint);margin-left:8px"></span>
    </div>`;

  document.getElementById('perm-role').addEventListener('change', function () {
    const role = this.value;
    document.getElementById('perm-mc-led').classList.toggle('visible', LEADER_ROLES.includes(role));
    document.getElementById('perm-bic-row').style.display = STAFF_ROLES.includes(role) ? 'none' : '';
  });
}

window.savePermission = function () {
  const msg = document.getElementById('perm-save-msg');
  msg.textContent = 'Saving…';
  const mcSlugs = Array.from(document.querySelectorAll('#perm-mc-checks input:checked')).map(el => el.value);
  const bicEl = document.getElementById('perm-bic-email');
  fetch('api/agent_roles.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      email: PROFILE_EMAIL,
      role: document.getElementById('perm-role').value,
      own_mc_slug: document.getElementById('perm-own-mc').value,
      bic_email: bicEl ? bicEl.value : '',
      mc_slugs: mcSlugs
    })
  })
    .then(r => r.json())
    .then(res => { msg.textContent = res.ok ? 'Saved ✓' : (res.error || 'Save failed.'); })
    .catch(() => { msg.textContent = 'Network error.'; });
};

window.removePermission = function () {
  if (!confirm('Clear all role/placement settings for this agent?')) return;
  const msg = document.getElementById('perm-save-msg');
  msg.textContent = 'Removing…';
  fetch('api/agent_roles.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: PROFILE_EMAIL, action: 'remove' })
  })
    .then(r => r.json())
    .then(res => {
      if (res.ok) { permissionLoaded = false; loadPermissionTab(); }
      else msg.textContent = res.error || 'Failed.';
    })
    .catch(() => { msg.textContent = 'Network error.'; });
};

// Load the active tab's lazy data if the page loaded directly on that tab.
if (document.getElementById('ap-tab-network') && document.getElementById('ap-tab-network').classList.contains('active')) {
  networkLoaded = true; loadNetworkTree();
}
if (CAN_EDIT_PERMISSIONS && document.getElementById('ap-tab-permission') && document.getElementById('ap-tab-permission').classList.contains('active')) {
  permissionLoaded = true; loadPermissionTab();
}
</script>
</body>
</html>
