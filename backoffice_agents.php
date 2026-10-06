<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/crypto.php';
$agent = require_login();
$perms = current_perms();
$isAdmin  = !empty($perms['isAdmin']);
$isLeader = $isAdmin || is_mc_leader() || is_bic();
if (!$isLeader) { header('Location: index.php'); exit; }
// mc_leader/bic get a view scoped to the Market Center(s) they lead, with the
// Tax ID reveal / Staff-Managed section / Edit Profile actions hidden — every
// edit control below stays admin-only.
$myMcSlugs = $isAdmin ? null : my_mc_slugs();

// Email -> list of MC slugs this agent is on the active roster under (an
// agent can have rows in more than one state/MC). Used below to scope the
// three agent lists to the leader's own Market Center(s).
$rosterMcSlugsByEmail = [];
if ($myMcSlugs !== null) {
    foreach (local_db()->query("SELECT email, market_center FROM innovate_roster WHERE active=1 AND email != ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rosterMcSlugsByEmail[strtolower(trim($r['email']))][] = slugify_mc($r['market_center'] ?: '');
    }
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function dv(string $val): string {
    if ($val === '' || $val === null) return '<span class="dg-value empty">—</span>';
    return '<span class="dg-value">' . h($val) . '</span>';
}
function dvBool($val): string {
    return '<span class="dg-value">' . ($val ? 'Yes' : 'No') . '</span>';
}
function lastNameFirst(string $full): string {
    $full = trim($full);
    if ($full === '') return '';
    $parts = preg_split('/\s+/', $full);
    if (count($parts) < 2) return $full;
    $last = array_pop($parts);
    return $last . ', ' . implode(' ', $parts);
}

// agent_admin is joined in PHP rather than SQL. Its email column isn't fully
// normalized (a handful of legacy rows are mixed-case), so the join used to
// be `ON LOWER(aa.email) = i.email` — correct, but LOWER() on the joined
// column stops SQLite from using agent_admin's own PRIMARY KEY index, forcing
// a full scan of agent_admin for every single agent_intake row. Fetching
// agent_admin once and matching by lowercased email in PHP keeps the exact
// same (case-insensitive) matching behavior — including the row-multiplying
// LEFT JOIN semantics for the few emails that currently have more than one
// agent_admin row on file, a pre-existing data duplication this isn't
// attempting to resolve — while turning an O(agents × admin rows) scan into
// one O(admin rows) pass plus O(1) lookups.
$agentAdminByEmail = [];
foreach (local_db()->query(
    "SELECT email, tax_1099_type, gets_1099, terminated_date, agent_team, coached_by, managed_by, recruit_source_email
     FROM agent_admin ORDER BY email"
)->fetchAll(PDO::FETCH_ASSOC) as $admRow) {
    $agentAdminByEmail[strtolower(trim($admRow['email']))][] = $admRow;
}
$noAdminMatch = ['tax_1099_type' => null, 'gets_1099' => null, 'terminated_date' => null, 'agent_team' => null, 'coached_by' => null, 'managed_by' => null, 'recruit_source_email' => null];

$intakeAgentsBase = local_db()->query(
    "SELECT i.email, i.full_name, i.phone, i.license_number, i.license_state,
            i.license_exp, i.nar_number, i.mls_board, i.mls_id, i.office_location,
            i.birthday, i.mailing_address, i.spouse_name, i.emergency_name, i.emergency_phone,
            i.bio, i.tshirt_size, i.is_military, i.first_responder, i.is_teacher,
            i.phone_last4, i.referring_agent, i.languages,
            i.personal_email, i.commissions_email,
            i.address_line1, i.address_line2, i.city, i.state, i.zip, i.country,
            i.drivers_license, i.gender,
            i.website, i.additional_websites, i.facebook, i.linkedin, i.skype, i.instagram,
            i.twitter, i.youtube, i.tiktok, i.blog,
            i.specialty, i.career_start, i.prior_occupation, i.prior_affiliation,
            i.full_time, i.show_on_internet,
            i.corporation_start, i.corporation_end,
            i.personal_tax_id_enc, i.corporate_tax_id_enc,
            i.submitted, i.submitted_at, i.updated_at,
            e.hire_date, e.license_renewal,
            ar.role
     FROM agent_intake i
     LEFT JOIN agent_extra e ON e.email = i.email
     LEFT JOIN agent_roles ar ON ar.email = i.email
     LEFT JOIN agent_admin aa ON aa.email = i.email
     ORDER BY i.full_name"
)->fetchAll(PDO::FETCH_ASSOC);

$intakeAgents = [];
foreach ($intakeAgentsBase as $baseRow) {
    $matches = $agentAdminByEmail[strtolower(trim($baseRow['email']))] ?? [$noAdminMatch];
    foreach ($matches as $admMatch) {
        $intakeAgents[] = array_merge($baseRow, [
            'tax_1099_type'        => $admMatch['tax_1099_type'] ?? null,
            'gets_1099'            => $admMatch['gets_1099'] ?? null,
            'terminated_date'      => $admMatch['terminated_date'] ?? null,
            'agent_team'           => $admMatch['agent_team'] ?? null,
            'coached_by'           => $admMatch['coached_by'] ?? null,
            'managed_by'           => $admMatch['managed_by'] ?? null,
            'recruit_source_email' => $admMatch['recruit_source_email'] ?? null,
        ]);
    }
}
// Drop anyone currently offboarding or already offboarded — terminated_date
// is stamped the moment an agent enters the Offboarding Queue (and cleared
// only if that offboarding is cancelled), so this page stays focused on
// current agents instead of showing departed ones as stale Draft/Submitted rows.
$intakeAgents = array_values(array_filter($intakeAgents, fn($a) => empty($a['terminated_date'])));
if ($myMcSlugs !== null) {
    $intakeAgents = array_values(array_filter($intakeAgents, function($a) use ($rosterMcSlugsByEmail, $myMcSlugs) {
        $email = strtolower(trim($a['email'] ?? ''));
        $slugs = $rosterMcSlugsByEmail[$email] ?? [];
        if (!$slugs && !empty($a['office_location'])) $slugs = [slugify_mc($a['office_location'])];
        return (bool)array_intersect($slugs, $myMcSlugs);
    }));
}
usort($intakeAgents, fn($x, $y) => strcasecmp(lastNameFirst($x['full_name'] ?? ''), lastNameFirst($y['full_name'] ?? '')));

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
            q.agent_phone as phone, q.start_date, q.role, q.sponsor as referring_agent, q.status, q.added_at,
            COALESCE(r.retention_notes, '') as retention_notes
     FROM onboard_queue q
     LEFT JOIN innovate_roster r ON LOWER(r.email) = LOWER(q.agent_email)
     WHERE q.status = 'active'
       AND LOWER(q.agent_email) NOT IN (SELECT LOWER(email) FROM agent_intake)
     ORDER BY q.added_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);
if ($myMcSlugs !== null) {
    $pendingAgents = array_values(array_filter($pendingAgents, fn($p) => in_array(slugify_mc($p['office_location'] ?? ''), $myMcSlugs, true)));
}
// Already tracked in the Pending queue above — exclude from Missing below so
// an agent who's mid-onboarding (queued, no intake yet) doesn't also show up
// as "no profile" under a second name-matched roster row. Matched the same
// two ways (email, then name) as $missingAgents itself.
$pendingEmails = [];
$pendingNames  = [];
foreach ($pendingAgents as $p) {
    if ($p['email'])     $pendingEmails[strtolower(trim($p['email']))]    = true;
    if ($p['full_name']) $pendingNames[strtolower(trim($p['full_name']))] = true;
}

// Active roster agents with no agent_intake row at all — never started a
// profile (as opposed to $pendingAgents, which is mid-onboarding). Matched
// by email when the roster row has one, falling back to an exact name match
// since many older/manually-added roster rows have no email on file.
$missingAgents = [];

$rosterRows = local_db()->query(
    "SELECT agent_name, email, state_code, market_center, COALESCE(retention_notes, '') as retention_notes FROM innovate_roster WHERE active=1"
)->fetchAll(PDO::FETCH_ASSOC);

$byRosterName = [];
$rosterNotesByEmail = [];
foreach ($rosterRows as $r) {
    $key = strtolower(trim($r['agent_name']));
    if ($key === '') continue;
    if (!isset($byRosterName[$key])) {
        $byRosterName[$key] = ['agent_name' => $r['agent_name'], 'email' => $r['email'], 'states' => [], 'mcs' => []];
    }
    if ($r['email'] && !$byRosterName[$key]['email']) $byRosterName[$key]['email'] = $r['email'];
    if ($r['state_code'])    $byRosterName[$key]['states'][] = $r['state_code'];
    if ($r['market_center']) $byRosterName[$key]['mcs'][]    = $r['market_center'];
    if (!empty($r['retention_notes'])) $rosterNotesByEmail[strtolower(trim($r['email'] ?? ''))] = $r['retention_notes'];
}

$intakeEmails = [];
$intakeNames  = [];
foreach ($intakeAgents as $ia) {
    if ($ia['email'])     $intakeEmails[strtolower(trim($ia['email']))]     = true;
    if ($ia['full_name']) $intakeNames[strtolower(trim($ia['full_name']))]  = true;
}

foreach ($byRosterName as $key => $r) {
    $email = strtolower(trim($r['email'] ?? ''));
    $hasByEmail = $email !== '' && (isset($intakeEmails[$email]) || isset($pendingEmails[$email]));
    $hasByName  = isset($intakeNames[$key]) || isset($pendingNames[$key]);
    if (!$hasByEmail && !$hasByName) {
        $missingAgents[] = [
            'full_name'         => $r['agent_name'],
            'email'             => $r['email'] ?: '',
            'office_location'   => implode(', ', array_unique($r['mcs'])),
            'state_code'        => implode(', ', array_unique($r['states'])),
            'retention_notes'   => $rosterNotesByEmail[strtolower(trim($r['email'] ?? ''))] ?? '',
        ];
    }
}
usort($missingAgents, fn($x, $y) => strcasecmp($x['full_name'], $y['full_name']));
if ($myMcSlugs !== null) {
    $missingAgents = array_values(array_filter($missingAgents, function($m) use ($myMcSlugs) {
        foreach (explode(', ', $m['office_location'] ?? '') as $mcName) {
            if (in_array(slugify_mc($mcName), $myMcSlugs, true)) return true;
        }
        return false;
    }));
}

// Most recently uploaded headshot per agent, used as their displayed photo
// in the always-visible collapsed row. (The full per-agent headshot list
// used to also be preloaded here for the detail card's Photo section, but
// that card is now fetched on demand — see api/agent_detail.php — so only
// this summary-row avatar lookup is still needed on initial page load.)
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
$missingCount = count($missingAgents);
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
.dg-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--ink)}
.dg-value{font-size:12.5px;color:var(--muted)}
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
.hs-thumb .hs-del{position:absolute;top:-6px;right:-6px;background:rgba(0,0,0,.6);color:#fff;border:0;border-radius:50%;width:20px;height:20px;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;padding:0}
.hs-thumb .hs-del:hover{background:rgba(200,0,0,.85)}
.hs-thumb .hs-dl{position:absolute;bottom:-6px;left:-6px;background:rgba(0,0,0,.6);color:#fff;border:0;border-radius:50%;width:20px;height:20px;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;text-decoration:none}
.hs-thumb .hs-dl:hover{background:rgba(0,110,0,.85)}
.hs-upload-label{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#f0f5e8;border:1px dashed #82C112;border-radius:6px;font-size:11px;font-weight:700;color:#5b8e0d;cursor:pointer}
.hs-upload-label:hover{background:#e4f0d8}
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
      <div class="rs-tile amber">
        <div class="rs-num"><?= $missingCount ?></div>
        <div class="rs-lbl">No Profile</div>
      </div>
    </div>

    <div class="ag-toolbar">
      <input type="text" class="ag-search" id="agSearch" placeholder="Search name, email, office…" autocomplete="off">
      <?php if ($isAdmin): ?>
      <button type="button" class="btn-detail-link" id="bulk-send-btn" onclick="sendBulkCompletionLinks()" style="margin-left:auto">Email Everyone With Missing Info</button>
      <?php endif; ?>
    </div>

    <div class="ag-tabs">
      <button class="ag-tab active" data-tab="all">All</button>
      <button class="ag-tab" data-tab="submitted">Submitted</button>
      <button class="ag-tab" data-tab="draft">Draft</button>
      <button class="ag-tab" data-tab="pending">Pending</button>
      <button class="ag-tab" data-tab="missing">No Profile</button>
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
  $updated = $updatedRaw ? fmt_dt_et($updatedRaw, 'M j, Y') : '—';
  $rowId = 'row-' . $idx;
  $detailId = 'detail-' . $idx;
  $emailLower = strtolower($a['email']);
?>
          <tr class="data-row" id="<?= $rowId ?>" data-tab="<?= $tabAttr ?>"
              data-search="<?= h(strtolower($a['full_name'] . ' ' . $a['email'] . ' ' . $a['office_location'])) ?>">
            <td><button class="expand-btn" aria-label="Expand" onclick="toggleDetail('<?= $detailId ?>',this,'<?= h($a['email']) ?>')">&#9658;</button></td>
            <td><?= bo_avatar_html($a['full_name'], $hsLatest[$emailLower] ?? null, 'row-avatar') ?><strong><?= h($a['full_name'] ? lastNameFirst($a['full_name']) : '—') ?></strong></td>
            <td><?= h($a['email']) ?></td>
            <td><?= h($a['office_location'] ?: '—') ?></td>
            <td><?= h($a['phone'] ?: '—') ?></td>
            <td><?php if (!$isSubmitted): ?><span class="st-badge <?= $statusClass ?>"><?= $statusLabel ?></span><?php endif; ?></td>
            <td><?= h($updated) ?></td>
          </tr>
          <?php /* Detail panel content is fetched on demand by toggleDetail() from
                    api/agent_detail.php the first time this row is expanded — see
                    that file for the query/markup this used to inline here for
                    every agent on every page load. */ ?>
          <tr class="detail-row" id="<?= $detailId ?>" style="display:none" data-tab="<?= $tabAttr ?>">
            <td colspan="7"></td>
          </tr>
<?php endforeach; ?>

<?php foreach ($pendingAgents as $idx => $p):
  $addedRaw = $p['added_at'] ?? '';
  $added = $addedRaw ? fmt_dt_et($addedRaw, 'M j, Y') : '—';
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
                <?php if (!empty($p['retention_notes'])): ?>
                <div class="dg-field" style="grid-column:1/-1">
                  <span class="dg-label">Retention Notes</span>
                  <span class="dg-value" style="white-space:pre-line"><?= dv($p['retention_notes']) ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-actions">
                  <?php if ($isAdmin): ?>
                  <a href="onboarding.php" target="_blank" class="btn-detail-link">Onboarding Steps →</a>
                  <button type="button" class="btn-detail-link" onclick="openEditModal('<?= h($p['email']) ?>', '<?= h($p['full_name'] ?: $p['email']) ?>', { office_location: '<?= h($p['office_location']) ?>', phone: '<?= h($p['phone'] ?? '') ?>' })">Edit Profile →</button>
                  <a href="agent_profile.php?email=<?= h($p['email']) ?>" class="btn-detail-link">View Full Profile →</a>
                  <?php endif; ?>
                </div>
              </div>
            </td>
          </tr>
<?php endforeach; ?>

<?php foreach ($missingAgents as $idx => $m):
  $rowId = 'mrow-' . $idx;
  $detailId = 'mdetail-' . $idx;
?>
          <tr class="data-row" id="<?= $rowId ?>" data-tab="missing"
              data-search="<?= h(strtolower($m['full_name'] . ' ' . $m['email'] . ' ' . $m['office_location'])) ?>">
            <td><button class="expand-btn" aria-label="Expand" onclick="toggleDetail('<?= $detailId ?>',this)">&#9658;</button></td>
            <td><strong><?= h($m['full_name'] ?: '—') ?></strong></td>
            <td><?= h($m['email'] ?: '—') ?></td>
            <td><?= h($m['office_location'] ?: '—') ?></td>
            <td><span class="dg-value empty" style="font-size:12px">—</span></td>
            <td><span class="st-badge" style="background:#fff3e0;color:#c87800">No Profile</span></td>
            <td><span class="dg-value empty" style="font-size:12px">—</span></td>
          </tr>
          <tr class="detail-row" id="<?= $detailId ?>" style="display:none" data-tab="missing">
            <td colspan="7">
              <div class="detail-grid">
                <div class="dg-section">Roster Info</div>
                <div class="dg-field"><span class="dg-label">Name</span><?= dv($m['full_name']) ?></div>
                <div class="dg-field"><span class="dg-label">Email on File</span><?= dv($m['email']) ?></div>
                <div class="dg-field"><span class="dg-label">Market Center(s)</span><?= dv($m['office_location']) ?></div>
                <div class="dg-field"><span class="dg-label">State(s)</span><?= dv($m['state_code']) ?></div>
                <div class="dg-field" style="grid-column:1/-1">
                  <span class="dg-label">Intake Form</span>
                  <span class="dg-value empty">No profile started — never submitted an intake form</span>
                </div>
                <?php if (!empty($m['retention_notes'])): ?>
                <div class="dg-field" style="grid-column:1/-1">
                  <span class="dg-label">Retention Notes</span>
                  <span class="dg-value" style="white-space:pre-line"><?= dv($m['retention_notes']) ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-actions">
                  <?php if ($isAdmin): ?>
                  <button type="button" class="btn-detail-link" onclick="createMissingProfile('<?= h($m['email']) ?>', '<?= h($m['full_name']) ?>', '<?= h($m['office_location']) ?>')">Create Profile →</button>
                  <?php endif; ?>
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
        <div class="em-field"><label>Alternate Email (Darwin match)</label><input id="em-alt_email" type="email" placeholder="if different from the roster email"></div>
        <div class="em-field"><label>Phone Last 4 (payroll)</label><input id="em-phone_last4" maxlength="4"></div>

        <div class="em-section">Team Status (from Darwin)</div>
        <div class="em-field em-full" id="em-team-status">
          <div style="font-size:12px;color:var(--faint)">Loading…</div>
        </div>

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
        <div class="em-field em-full">
          <label>Additional Licensed States</label>
          <div id="em-additional-licenses"></div>
          <button type="button" class="btn-add-license" id="em-btn-add-license">+ Add Another License</button>
        </div>

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
        <div class="em-field"><label>Instagram</label><input id="em-instagram"></div>
        <div class="em-field"><label>Skype</label><input id="em-skype"></div>
        <div class="em-field"><label>Twitter / X</label><input id="em-twitter"></div>
        <div class="em-field"><label>YouTube</label><input id="em-youtube"></div>
        <div class="em-field"><label>TikTok</label><input id="em-tiktok"></div>
        <div class="em-field"><label>Blog</label><input id="em-blog"></div>

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

<div class="modal-overlay" id="mergeModalOverlay" style="display:none">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Merge Duplicate — <span id="mg-agent-name"></span></h3>
      <button type="button" class="modal-close" onclick="closeMergeModal()">&times;</button>
    </div>
    <div class="modal-body">

      <div id="mg-step-pick">
        <p style="font-size:12px;color:var(--faint);margin-top:0">
          Find the other record for the same person. Everything below is a preview — nothing changes until you confirm.
        </p>
        <div class="em-field em-full">
          <label>Duplicate record</label>
          <input type="text" id="mg-search" placeholder="Search by name or email…" autocomplete="off">
          <div id="mg-search-results" style="border:1px solid var(--border);border-radius:6px;margin-top:4px;max-height:180px;overflow:auto;display:none"></div>
        </div>
      </div>

      <div id="mg-step-preview" style="display:none">
        <div class="em-field em-full" style="display:flex;gap:10px;align-items:flex-start">
          <label style="min-width:0;flex:1;border:1px solid var(--border);border-radius:6px;padding:8px;cursor:pointer">
            <input type="radio" name="mg-survivor" id="mg-radio-a" value="a" checked>
            <strong id="mg-a-name"></strong>
            <div id="mg-a-detail" style="font-size:11px;color:var(--faint);margin-top:4px"></div>
          </label>
          <label style="min-width:0;flex:1;border:1px solid var(--border);border-radius:6px;padding:8px;cursor:pointer">
            <input type="radio" name="mg-survivor" id="mg-radio-b" value="b">
            <strong id="mg-b-name"></strong>
            <div id="mg-b-detail" style="font-size:11px;color:var(--faint);margin-top:4px"></div>
          </label>
        </div>
        <p style="font-size:11px;color:var(--faint)">The record on the left starts selected to keep. Pick whichever should survive — its data wins wherever both sides have a value; blank fields get filled in from the other side.</p>

        <div class="em-field em-full">
          <label>What will happen</label>
          <div id="mg-table-preview" style="font-size:12px;border:1px solid var(--border);border-radius:6px;padding:8px;max-height:200px;overflow:auto"></div>
        </div>

        <div class="em-field em-full">
          <label>Type the surviving email to confirm</label>
          <input type="text" id="mg-confirm-email" placeholder="">
        </div>
      </div>

      <div id="mg-msg" style="font-size:12px;margin-top:8px"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn-save" id="mg-merge-btn" style="display:none" onclick="submitMerge()">Merge</button>
      <button type="button" class="btn-detail-link" onclick="closeMergeModal()">Cancel</button>
    </div>
  </div>
</div>

<script src="assets/language_options.js"></script>
<script>
(function () {
  initLanguageChecklist('em-languages-checks', 'em-languages');
  var MLS_OPTIONS = <?= json_encode($mlsOptions) ?>;
  var MERGE_AGENTS = <?= json_encode(array_map(fn($a) => ['email' => $a['email'], 'full_name' => $a['full_name']], $intakeAgents)) ?>;
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

  window.sendCompletionLink = function (email, btnId) {
    var btn = document.getElementById(btnId);
    var orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Sending…';
    fetch('api/send_profile_completion.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'single', email: email })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        btn.textContent = d.ok ? 'Sent ✓' : orig;
        if (!d.ok) alert(d.error || 'Could not send.');
        if (d.ok) setTimeout(function () { btn.textContent = orig; }, 3000);
      })
      .catch(function () { btn.disabled = false; btn.textContent = orig; alert('Network error.'); });
  };

  window.sendBulkCompletionLinks = function () {
    if (!confirm('Email every active agent who is currently missing required profile info? Each gets their own personal link.')) return;
    var btn = document.getElementById('bulk-send-btn');
    var orig = btn.textContent;
    btn.disabled = true; btn.textContent = 'Sending…';
    fetch('api/send_profile_completion.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'bulk_incomplete' })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false; btn.textContent = orig;
        if (!d.ok) { alert(d.error || 'Could not send.'); return; }
        alert('Sent to ' + d.sent + ' agent' + (d.sent === 1 ? '' : 's') + '.');
      })
      .catch(function () { btn.disabled = false; btn.textContent = orig; alert('Network error.'); });
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

  window.uploadHeadshot = function (email, idx, inputEl) {
    var file = inputEl.files[0];
    if (!file) return;
    var msg = document.getElementById('hs-msg-' + idx);
    if (file.size > 10 * 1024 * 1024) { msg.textContent = 'File exceeds 10 MB limit.'; return; }
    msg.textContent = 'Uploading…';
    var fd = new FormData();
    fd.append('headshot', file);
    fd.append('email', email);
    fetch('api/intake.php?action=upload', { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) { location.reload(); }
        else { msg.textContent = res.error || 'Upload failed.'; }
      })
      .catch(function () { msg.textContent = 'Network error.'; });
    inputEl.value = '';
  };

  window.deleteHeadshot = function (key) {
    if (!confirm('Delete this headshot?')) return;
    fetch('api/intake.php?action=delete_file', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: key })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.ok) { location.reload(); }
        else { alert(res.error || 'Delete failed.'); }
      })
      .catch(function () { alert('Network error.'); });
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
    'website','additional_websites','facebook','linkedin','skype','instagram',
    'twitter','youtube','tiktok','blog',
    'referring_agent','bio'];
  var EM_CHECK_FIELDS = ['full_time', 'show_on_internet'];
  var emCurrentEmail = null;
  // agent_extra's MM-DD birthday (calendar reminder) is a different field from
  // agent_intake's full-date birthday shown in this modal — round-trip it
  // untouched so saving the modal never blanks it out.
  var emExtraBirthday = '';
  // Guards against saving before the async load below finishes (or after it
  // fails) — without this, Save was clickable the instant the modal opened,
  // so a slow/failed load let a mostly-blank payload blindly overwrite a
  // fully-populated profile (see api/intake.php's matching circuit-breaker).
  var emLoaded = false;

  // Additional (non-primary) state licenses — see agent_intake_licenses.
  // Rendered as repeatable state/number/expiration rows; api/intake.php
  // rewrites the whole set on save from whatever rows exist at submit time.
  function emAddLicenseRow(lic) {
    lic = lic || {};
    var row = document.createElement('div');
    row.className = 'license-row';
    row.innerHTML =
      '<div class="em-field"><label>Real Estate License #</label><input type="text" class="em-al-number"></div>' +
      '<div class="em-field"><label>License State</label><input type="text" class="em-al-state" placeholder="e.g. SC, NC"></div>' +
      '<div class="em-field"><label>License Expiration Date</label><input type="date" class="em-al-exp"></div>' +
      '<button type="button" class="btn-remove-license">Remove</button>';
    row.querySelector('.em-al-number').value = lic.license_number || '';
    row.querySelector('.em-al-state').value = lic.license_state || '';
    row.querySelector('.em-al-exp').value = lic.license_exp || '';
    row.querySelector('.btn-remove-license').addEventListener('click', function () { row.remove(); });
    document.getElementById('em-additional-licenses').appendChild(row);
  }

  function emRenderAdditionalLicenses(list) {
    var container = document.getElementById('em-additional-licenses');
    container.innerHTML = '';
    (list || []).forEach(function (lic) { emAddLicenseRow(lic); });
  }

  function emCollectAdditionalLicenses() {
    var out = [];
    document.querySelectorAll('#em-additional-licenses .license-row').forEach(function (row) {
      var number = row.querySelector('.em-al-number').value.trim();
      var state = row.querySelector('.em-al-state').value.trim();
      var exp = row.querySelector('.em-al-exp').value.trim();
      if (number || state || exp) out.push({ license_number: number, license_state: state, license_exp: exp });
    });
    return out;
  }

  var emBtnAddLicense = document.getElementById('em-btn-add-license');
  if (emBtnAddLicense) emBtnAddLicense.addEventListener('click', function () { emAddLicenseRow(); });

  // Roster agents with no agent_intake row yet often have no email on file
  // either (older/manually-added rows). Rather than asking the admin to
  // retype everything, try a CRM lookup by name first (same search_crm
  // action already used by the onboarding/offboarding add-agent forms) to
  // pull email/phone/market center — only fall back to a manual prompt if
  // the CRM has no match.
  function askForEmail(name, marketCenter) {
    var entered = (prompt('No CRM match found. Enter ' + name + '’s email to create their profile:') || '').trim();
    if (!entered) return;
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(entered)) {
      alert('That doesn’t look like a valid email address.');
      return;
    }
    openEditModal(entered, name, { office_location: marketCenter || '' });
  }

  window.createMissingProfile = function (email, name, marketCenter) {
    if (email) {
      openEditModal(email, name, { office_location: marketCenter || '' });
      return;
    }

    fetch('api/onboard_action.php?action=search_crm&q=' + encodeURIComponent(name), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var results = (d.ok && d.results) ? d.results : [];
        var match = results.find(function (r) { return (r.name || '').toLowerCase() === name.toLowerCase(); }) || results[0];
        if (match && match.email) {
          openEditModal(match.email, name, { phone: match.phone || '', office_location: match.marketCenter || marketCenter || '' });
        } else {
          askForEmail(name, marketCenter);
        }
      })
      .catch(function () { askForEmail(name, marketCenter); });
  };

  function h(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // Renders the Darwin-derived team role suggestion into the modal. Runs in
  // parallel with the intake/agent_extra Promise.all below, not chained to
  // it — a slow/failed Darwin lookup should never block the rest of the
  // profile from loading.
  function loadTeamStatus(email, name) {
    var box = document.getElementById('em-team-status');
    box.innerHTML = '<div style="font-size:12px;color:var(--faint)">Loading…</div>';

    fetch('api/agent_team_suggestion.php?email=' + encodeURIComponent(email), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { box.innerHTML = '<div style="font-size:12px;color:var(--faint)">' + h(d.error || 'Unavailable') + '</div>'; return; }
        box.innerHTML = renderTeamStatus(d);
        var createBtn = document.getElementById('em-create-team-btn');
        if (createBtn) createBtn.onclick = function () { createTeamFromSuggestion(email, name); };
      })
      .catch(function () {
        box.innerHTML = '<div style="font-size:12px;color:var(--faint)">Could not load team status.</div>';
      });
  }

  function renderTeamStatus(d) {
    var teamsLink = '<a href="teams.php" class="btn-detail-link" style="display:inline-block;margin-top:6px">Manage Teams →</a>';

    if (d.isLeaderOf) {
      return '<div style="font-size:13px">✓ Already leading team <strong>' + h(d.isLeaderOf.name) + '</strong></div>' + teamsLink;
    }
    if (d.isMemberOf) {
      return '<div style="font-size:13px">✓ Already a member of <strong>' + h(d.isMemberOf.name) + '</strong></div>' + teamsLink;
    }

    var s = d.suggestion;
    if (!s) {
      return '<div style="font-size:12px;color:var(--faint)">No team-type Darwin commission plan detected.</div>';
    }

    var planLine = '<div style="font-size:12px;color:var(--faint)">Darwin plan: ' + h(s.plan) + '</div>';

    if (s.role === 'leader') {
      var confNote = s.confidence === 'low' ? ' (unrecognized plan name — please verify)' : '';
      return '<div style="font-size:13px">Darwin suggests <strong>Team Leader</strong>' + h(confNote) + '</div>' + planLine +
        '<button type="button" class="btn-save" id="em-create-team-btn" style="margin-top:6px;padding:4px 10px;font-size:12px">Create Team</button>';
    }
    if (s.role === 'member') {
      return '<div style="font-size:13px">Darwin suggests <strong>Team Member</strong></div>' + planLine +
        '<div style="font-size:12px;color:var(--faint);margin-top:4px">Assign to their leader’s team on the Teams page.</div>' + teamsLink;
    }
    if (s.role === 'spouse_team') {
      return '<div style="font-size:13px">Darwin plan is a <strong>Spouse Team</strong> (' + h(s.detail || '') + ')</div>' + planLine +
        '<div style="font-size:12px;color:var(--faint);margin-top:4px">Doesn’t map cleanly to leader/member — review manually.</div>' + teamsLink;
    }
    return planLine;
  }

  window.createTeamFromSuggestion = function (email, name) {
    if (!confirm('Create a new team led by ' + name + '?')) return;
    fetch('api/team_action.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save', name: name + '’s Team', leader_email: email })
    }).then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
      .then(function (res) {
        if (res.status === 403) {
          document.getElementById('em-team-status').innerHTML =
            '<div style="font-size:12px;color:#c00">Only a super admin can create teams. Ask for elevated access.</div>';
          return;
        }
        if (!res.body.ok) {
          document.getElementById('em-team-status').innerHTML =
            '<div style="font-size:12px;color:#c00">' + h(res.body.error || 'Failed to create team.') + '</div>';
          return;
        }
        loadTeamStatus(email, name);
      })
      .catch(function () {
        document.getElementById('em-team-status').innerHTML =
          '<div style="font-size:12px;color:#c00">Network error creating team.</div>';
      });
  };

  window.openEditModal = function (email, name, prefill) {
    emCurrentEmail = email;
    emLoaded = false;
    document.getElementById('em-save-btn').disabled = true;
    document.getElementById('em-agent-name').textContent = name;
    document.getElementById('em-save-msg').textContent = 'Loading…';
    document.getElementById('editModalOverlay').style.display = 'flex';

    loadTeamStatus(email, name);

    Promise.all([
      fetch('api/intake.php?email=' + encodeURIComponent(email), { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
      fetch('api/agent_extra.php?email=' + encodeURIComponent(email), { credentials: 'same-origin' }).then(function (r) { return r.json(); })
    ]).then(function (results) {
      var intake = results[0].intake || {};
      var extra = results[1] || {};
      emExtraBirthday = extra.birthday || '';

      EM_FIELDS.forEach(function (key) {
        var node = document.getElementById('em-' + key);
        if (node) node.value = intake[key] || (prefill && prefill[key]) || '';
      });
      EM_CHECK_FIELDS.forEach(function (key) {
        var node = document.getElementById('em-' + key);
        if (node) node.checked = intake[key] === undefined ? true : Number(intake[key]) === 1;
      });
      document.getElementById('em-hire_date').value = extra.hire_date || '';
      document.getElementById('em-license_renewal').value = extra.license_renewal || '';
      document.getElementById('em-alt_email').value = extra.alt_email || '';
      document.getElementById('em-personal_tax_id').value = '';
      document.getElementById('em-corporate_tax_id').value = '';
      document.getElementById('em-personal-tax-hint').textContent = intake.personal_tax_id_last4 ? '(on file, ending in ' + intake.personal_tax_id_last4 + ')' : '(none on file)';
      document.getElementById('em-corporate-tax-hint').textContent = intake.corporate_tax_id_last4 ? '(on file, ending in ' + intake.corporate_tax_id_last4 + ')' : '(none on file)';
      emRenderAdditionalLicenses(results[0].additional_licenses);
      document.getElementById('em-save-msg').textContent = '';
      emLoaded = true;
      document.getElementById('em-save-btn').disabled = false;
    }).catch(function () {
      document.getElementById('em-save-msg').textContent = 'Failed to load agent data — cannot save until this loads. Close and try again.';
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
    if (!emLoaded) { msg.textContent = 'Still loading this agent\'s data — please wait before saving.'; return; }
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
    payload.additional_licenses = emCollectAdditionalLicenses();

    var extraPayload = {
      email: emCurrentEmail,
      birthday: emExtraBirthday,
      hire_date: document.getElementById('em-hire_date').value,
      license_renewal: document.getElementById('em-license_renewal').value,
      alt_email: document.getElementById('em-alt_email').value
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

  window.toggleDetail = function (detailId, btn, email) {
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
      var m = /^detail-(\d+)$/.exec(detailId);
      if (!m) return;
      // Pending/missing-profile rows render their (small, cheap) detail panel
      // inline already and never pass an email — nothing to fetch for those,
      // just load notes as before. Intake rows pass their email and their
      // panel content lives entirely in api/agent_detail.php, fetched here
      // the first time the row opens; loadAgentNotes runs only after that
      // markup (which is where the notes widget itself lives) is in the DOM.
      if (email) {
        loadAgentDetail(detailId, m[1], email).then(function () { loadAgentNotes(m[1]); });
      } else {
        loadAgentNotes(m[1]);
      }
    }
  };

  // ── Lazy-loaded agent detail panel (intake rows only) ───────────────────
  var detailLoadedIdx = {};

  window.loadAgentDetail = function (detailId, idx, email, force) {
    if (detailLoadedIdx[idx] && !force) return Promise.resolve();
    var detailRow = document.getElementById(detailId);
    var td = detailRow && detailRow.querySelector('td');
    if (!td) return Promise.resolve();
    td.innerHTML = '<div style="padding:20px;color:var(--faint);font-size:12px">Loading…</div>';
    return fetch('api/agent_detail.php?email=' + encodeURIComponent(email) + '&idx=' + encodeURIComponent(idx), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
      .then(function (res) {
        if (res.status !== 200 || !res.body.ok) throw new Error(res.body.error || 'load failed');
        td.innerHTML = res.body.html;
        detailLoadedIdx[idx] = true;
      })
      .catch(function () {
        td.innerHTML = '';
        var msg = document.createElement('div');
        msg.style.cssText = 'padding:20px;color:#b3261e;font-size:12px';
        msg.textContent = 'Could not load this agent’s details. ';
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn-detail-link';
        retry.textContent = 'Retry';
        retry.onclick = function () { loadAgentDetail(detailId, idx, email, true); };
        msg.appendChild(retry);
        td.appendChild(msg);
      });
  };

  // ── Notes (admin/BIC/ML only — enforced server-side by api/agent_notes.php,
  // never surfaced to the agent since this whole page is staff-only) ─────────
  var notesLoadedIdx = {};

  function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  window.loadAgentNotes = function (idx, force) {
    if (notesLoadedIdx[idx] && !force) return;
    var wrap = document.getElementById('bo-notes-' + idx);
    var email = wrap && wrap.dataset.email;
    if (!email) return;
    fetch('api/agent_notes.php?email=' + encodeURIComponent(email), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        notesLoadedIdx[idx] = true;
        var list = document.getElementById('bo-notes-list-' + idx);
        if (!list) return;
        if (!d.ok) { list.innerHTML = '<span style="color:var(--faint)">' + (d.error || 'Could not load notes.') + '</span>'; return; }
        var notes = d.notes || [];
        if (!notes.length) { list.innerHTML = '<span style="color:var(--faint)">No notes yet.</span>'; return; }
        list.innerHTML = notes.map(function (n) {
          return '<div style="padding:6px 0;border-bottom:1px solid var(--border)">' +
            '<div style="white-space:pre-wrap;color:var(--ink)">' + escHtml(n.note) + '</div>' +
            '<div style="font-size:11px;color:var(--faint);margin-top:2px">' + escHtml(n.created_by) + ' · ' + escHtml(n.created_at) + '</div>' +
            '</div>';
        }).join('');
      })
      .catch(function () {});
  };

  window.addAgentNote = function (idx) {
    var wrap = document.getElementById('bo-notes-' + idx);
    var input = document.getElementById('bo-notes-input-' + idx);
    var email = wrap && wrap.dataset.email;
    var note = (input && input.value || '').trim();
    if (!email || !note) return;
    fetch('api/agent_notes.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email, note: note })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) { input.value = ''; input.style.height = 'auto'; loadAgentNotes(idx, true); }
        else { alert(d.error || 'Could not save note.'); }
      })
      .catch(function () { alert('Network error saving note.'); });
  };

  // ── Merge Duplicate ────────────────────────────────────────────────────
  var mgA = null; // { email, name } — the row the modal was opened from
  var mgPreview = null; // last GET response: { a, b, tables }

  window.openMergeModal = function (email, name) {
    mgA = { email: email, name: name };
    mgPreview = null;
    document.getElementById('mg-agent-name').textContent = name;
    document.getElementById('mg-search').value = '';
    document.getElementById('mg-search-results').style.display = 'none';
    document.getElementById('mg-step-pick').style.display = '';
    document.getElementById('mg-step-preview').style.display = 'none';
    document.getElementById('mg-merge-btn').style.display = 'none';
    document.getElementById('mg-msg').textContent = '';
    document.getElementById('mergeModalOverlay').style.display = 'flex';
    setTimeout(function () { document.getElementById('mg-search').focus(); }, 0);
  };

  window.closeMergeModal = function () {
    document.getElementById('mergeModalOverlay').style.display = 'none';
  };

  document.getElementById('mg-search').addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    var box = document.getElementById('mg-search-results');
    if (q.length < 2) { box.style.display = 'none'; box.innerHTML = ''; return; }
    var matches = MERGE_AGENTS.filter(function (ag) {
      // Exact-string compare, not case-insensitive: two intake rows can share the
      // same email in different case (the bug this tool cleans up after), and
      // each side needs to be able to find the other in this search.
      return ag.email !== mgA.email &&
        ((ag.full_name || '').toLowerCase().indexOf(q) !== -1 || ag.email.toLowerCase().indexOf(q) !== -1);
    }).slice(0, 8);
    if (!matches.length) {
      box.style.display = 'block';
      box.innerHTML = '<div style="padding:6px 8px;color:var(--faint);font-size:12px">No matches</div>';
      return;
    }
    box.style.display = 'block';
    box.innerHTML = matches.map(function (ag, i) {
      return '<div class="mg-result" data-i="' + i + '" style="padding:6px 8px;cursor:pointer;font-size:12px;border-bottom:1px solid var(--border)">' +
        '<strong>' + escHtml(ag.full_name || ag.email) + '</strong> — ' + escHtml(ag.email) + '</div>';
    }).join('');
    Array.prototype.forEach.call(box.querySelectorAll('.mg-result'), function (el) {
      el.addEventListener('click', function () { loadMergePreview(matches[+el.dataset.i]); });
      el.addEventListener('mouseenter', function () { el.style.background = 'var(--hover, #f2f2f2)'; });
      el.addEventListener('mouseleave', function () { el.style.background = ''; });
    });
  });

  function intakeDetailLine(row) {
    var bits = [];
    bits.push(row.submitted ? 'Submitted' : 'Draft');
    if (row.license_number) bits.push('Lic #' + row.license_number);
    if (row.phone) bits.push(row.phone);
    bits.push('Updated ' + (row.updated_at || '—'));
    return bits.join(' · ');
  }

  function loadMergePreview(other) {
    document.getElementById('mg-msg').textContent = 'Loading…';
    fetch('api/admin_merge_agents.php?a=' + encodeURIComponent(mgA.email) + '&b=' + encodeURIComponent(other.email), { credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
      .then(function (res) {
        document.getElementById('mg-msg').textContent = '';
        if (res.status !== 200 || !res.body.ok) {
          document.getElementById('mg-msg').textContent = res.body.error || 'Could not load preview.';
          return;
        }
        mgPreview = res.body;
        document.getElementById('mg-step-pick').style.display = 'none';
        document.getElementById('mg-step-preview').style.display = '';
        document.getElementById('mg-merge-btn').style.display = '';
        document.getElementById('mg-a-name').textContent = mgPreview.a.full_name || mgPreview.a.email;
        document.getElementById('mg-a-detail').textContent = mgPreview.a.email + ' — ' + intakeDetailLine(mgPreview.a);
        document.getElementById('mg-b-name').textContent = mgPreview.b.full_name || mgPreview.b.email;
        document.getElementById('mg-b-detail').textContent = mgPreview.b.email + ' — ' + intakeDetailLine(mgPreview.b);
        document.getElementById('mg-radio-a').checked = true;
        renderMergeTablePreview();
        updateMergeConfirmPlaceholder();
      })
      .catch(function () {
        document.getElementById('mg-msg').textContent = 'Network error loading preview.';
      });
  }

  function renderMergeTablePreview() {
    var el = document.getElementById('mg-table-preview');
    if (!mgPreview.tables.length) {
      el.innerHTML = '<span style="color:var(--faint)">No linked records on either side — just the duplicate profile itself will be removed.</span>';
      return;
    }
    var survivorIsA = document.getElementById('mg-radio-a').checked;
    el.innerHTML = mgPreview.tables.map(function (t) {
      var survCount = survivorIsA ? t.count_a : t.count_b;
      var loseCount = survivorIsA ? t.count_b : t.count_a;
      var line = t.table + ': ';
      if (t.conflict) {
        line += '<span style="color:#b45309">both sides have a row — the surviving side\'s is kept, the other side\'s (' + loseCount + ') is discarded</span>';
      } else if (loseCount > 0) {
        line += loseCount + ' row(s) move over';
      } else {
        line += 'nothing to move';
      }
      return '<div style="padding:2px 0">' + line + '</div>';
    }).join('');
  }

  function updateMergeConfirmPlaceholder() {
    var survivorIsA = document.getElementById('mg-radio-a').checked;
    var survivorEmail = survivorIsA ? mgPreview.a.email : mgPreview.b.email;
    document.getElementById('mg-confirm-email').placeholder = survivorEmail;
    document.getElementById('mg-confirm-email').value = '';
  }

  document.getElementById('mg-radio-a').addEventListener('change', function () { renderMergeTablePreview(); updateMergeConfirmPlaceholder(); });
  document.getElementById('mg-radio-b').addEventListener('change', function () { renderMergeTablePreview(); updateMergeConfirmPlaceholder(); });

  window.submitMerge = function () {
    if (!mgPreview) return;
    var survivorIsA = document.getElementById('mg-radio-a').checked;
    var survivor = survivorIsA ? mgPreview.a.email : mgPreview.b.email;
    var duplicate = survivorIsA ? mgPreview.b.email : mgPreview.a.email;
    var typed = document.getElementById('mg-confirm-email').value.trim().toLowerCase();
    if (typed !== survivor.toLowerCase()) {
      document.getElementById('mg-msg').textContent = 'Type the surviving email exactly to confirm.';
      return;
    }
    var btn = document.getElementById('mg-merge-btn');
    btn.disabled = true;
    document.getElementById('mg-msg').textContent = 'Merging…';
    fetch('api/admin_merge_agents.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ survivor: survivor, duplicate: duplicate })
    })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, body: d }; }); })
      .then(function (res) {
        btn.disabled = false;
        if (res.status !== 200 || !res.body.ok) {
          document.getElementById('mg-msg').textContent = res.body.error || 'Merge failed.';
          return;
        }
        document.getElementById('mg-msg').style.color = 'green';
        document.getElementById('mg-msg').textContent = 'Merged. Reloading…';
        setTimeout(function () { location.reload(); }, 700);
      })
      .catch(function () {
        btn.disabled = false;
        document.getElementById('mg-msg').textContent = 'Network error merging.';
      });
  };
}());
</script>
</body>
</html>
