<?php
// On-demand expanded detail panel for one agent on backoffice_agents.php's
// "With Forms" (intake) roster group. That page used to render this whole
// card — tax IDs, licenses, MLS memberships, headshots, staff-managed fields —
// inline for every agent on every load, hidden behind display:none until
// expanded. For a roster of hundreds of agents that meant hundreds of full
// detail cards (each with its own DB-driven maps) built and shipped on every
// page load for content almost never looked at. This endpoint renders exactly
// that same card (markup, IDs, inline JS handlers unchanged) for a single
// agent, fetched only when backoffice_agents.php's toggleDetail() first opens
// that agent's row — same lazy pattern already used there for notes.
//
// GET ?email=...&idx=N
//   email — required, identifies the agent (query is scoped to this row only)
//   idx   — cosmetic only: namespaces element IDs (ptax-N, hs-grid-N, ...) so
//           two different agents' panels can be open at once without ID
//           collisions. Not used for data access.
//
// Access mirrors backoffice_agents.php exactly: admin/mc_leader/bic only, and
// (like that page's own $intakeAgents filtering) mc_leader/bic are scoped to
// agents inside their own Market Center(s) — this endpoint re-checks that
// scope per request so it can't be used to pull a different leader's agents
// just by changing the email param.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';
require_once __DIR__ . '/../lib/crypto.php';

header('Content-Type: application/json');

$agentSession = current_agent();
if (!$agentSession) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'not signed in']); exit; }

$isAdmin  = is_admin();
$isLeader = $isAdmin || is_mc_leader() || is_bic();
if (!$isLeader) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'admin/leader only']); exit; }

$email = strtolower(trim($_GET['email'] ?? ''));
if ($email === '' || !str_contains($email, '@') || preg_match('/\s/', $email)) {
    http_response_code(400); echo json_encode(['ok' => false, 'error' => 'valid email required']); exit;
}
$idx = max(0, (int)($_GET['idx'] ?? 0));

$pdo = local_db();

$stmt = $pdo->prepare(
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
     WHERE i.email = ?"
);
$stmt->execute([$email]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$a) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not found']); exit; }

// agent_admin is matched case-insensitively (a handful of rows predate email
// normalization there) — same as the main page's join, but done as a single
// scoped lookup instead of a table-scan join. A few emails have more than one
// agent_admin row on file (a pre-existing data duplication, not introduced
// here) — e.g. one untouched row plus a later one that set terminated_date.
// The main page's own listing filters out whichever of an agent's duplicate
// rows is terminated and keeps showing the other, so an agent can still be
// visible in the roster even though one of their agent_admin rows says
// terminated. To match what's actually on screen, prefer a non-terminated
// match over a terminated one rather than just the most recently updated;
// only fall through to a terminated match (and then get excluded below,
// same as the page) when every match on file is terminated.
$admStmt = $pdo->prepare(
    "SELECT tax_1099_type, gets_1099, terminated_date, agent_team, coached_by, managed_by, recruit_source_email
     FROM agent_admin WHERE LOWER(email) = ? ORDER BY updated_at DESC"
);
$admStmt->execute([$email]);
$admMatches = $admStmt->fetchAll(PDO::FETCH_ASSOC);
$adm = null;
foreach ($admMatches as $m) { if (empty($m['terminated_date'])) { $adm = $m; break; } }
if ($adm === null) $adm = $admMatches[0] ?? [];
foreach (['tax_1099_type', 'gets_1099', 'terminated_date', 'agent_team', 'coached_by', 'managed_by', 'recruit_source_email'] as $k) {
    $a[$k] = $adm[$k] ?? null;
}

// Terminated/offboarded agents are filtered out of the page's own roster
// listing — never serve their detail panel even if a stale idx/email is
// replayed after that happened.
if (!empty($a['terminated_date'])) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not found']); exit; }

if (!$isAdmin) {
    $slugs = [];
    $rst = $pdo->prepare("SELECT market_center FROM innovate_roster WHERE LOWER(TRIM(email))=? AND active=1");
    $rst->execute([$email]);
    foreach ($rst->fetchAll(PDO::FETCH_COLUMN) as $mc) $slugs[] = slugify_mc($mc ?: '');
    if (!$slugs && !empty($a['office_location'])) $slugs[] = slugify_mc($a['office_location']);
    $slugs = array_filter($slugs);
    if (!array_intersect($slugs, my_mc_slugs())) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'not in your Market Center']); exit;
    }
}

$launchCoaches = $pdo->query(
    "SELECT ar.email, COALESCE(i.full_name, ar.email) AS full_name
     FROM agent_roles ar
     LEFT JOIN agent_intake i ON i.email = ar.email
     WHERE ar.role = 'launch_coach'
     ORDER BY full_name"
)->fetchAll(PDO::FETCH_ASSOC);

$licStmt = $pdo->prepare("SELECT license_number, license_state, license_exp FROM agent_intake_licenses WHERE LOWER(agent_email) = ? ORDER BY id");
$licStmt->execute([$email]);
$extraLicenses = $licStmt->fetchAll(PDO::FETCH_ASSOC);

$mlsStmt = $pdo->prepare("SELECT mls_association, mls_number FROM agent_mls_memberships WHERE LOWER(agent_email) = ? ORDER BY id");
$mlsStmt->execute([$email]);
$mlsMemberships = $mlsStmt->fetchAll(PDO::FETCH_ASSOC);

$hsStmt = $pdo->prepare("SELECT id, file_key, orig_name FROM agent_intake_files WHERE LOWER(agent_email) = ? ORDER BY uploaded_at");
$hsStmt->execute([$email]);
$hsAll = $hsStmt->fetchAll(PDO::FETCH_ASSOC);
$hs = count($hsAll);
$hsLatestKey = null; $maxId = -1;
foreach ($hsAll as $hsRow) { if ((int)$hsRow['id'] > $maxId) { $maxId = (int)$hsRow['id']; $hsLatestKey = $hsRow['file_key']; } }

$isSubmitted = !empty($a['submitted']);
$updatedRaw = $a['submitted_at'] ?? $a['updated_at'] ?? '';
$updated = $updatedRaw ? fmt_dt_et($updatedRaw, 'M j, Y') : '—';
$emailLower = strtolower($a['email']);

// Same small escaping/formatting helpers backoffice_agents.php defines for
// itself (this codebase's established per-page pattern rather than a shared
// include — see h()/dv()/dvBool() in every other *.php page here).
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function dv(string $val): string {
    if ($val === '' || $val === null) return '<span class="dg-value empty">—</span>';
    return '<span class="dg-value">' . h($val) . '</span>';
}
function dvBool($val): string {
    return '<span class="dg-value">' . ($val ? 'Yes' : 'No') . '</span>';
}
function bo_avatar_html(string $name, ?string $headshotKey, string $sizeClass): string {
    if ($headshotKey) {
        return '<img class="' . $sizeClass . '-img" src="api/intake.php?action=headshot&key=' . urlencode($headshotKey) . '&thumb=1" alt="" loading="lazy" decoding="async">';
    }
    $initials = '';
    foreach (preg_split('/\s+/', trim($name ?: '?')) as $part) { if ($part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
    return '<span class="' . $sizeClass . '-fallback">' . htmlspecialchars(mb_substr($initials ?: '?', 0, 2)) . '</span>';
}

ob_start();
?>
<div class="detail-grid">

  <div class="dg-section">Contact</div>
  <div class="dg-field"><span class="dg-label">Full Name</span><?= dv($a['full_name']) ?></div>
  <div class="dg-field"><span class="dg-label">Email</span><?= dv($a['email']) ?></div>
  <div class="dg-field"><span class="dg-label">Personal Email</span><?= dv($a['personal_email'] ?? '') ?></div>
  <div class="dg-field"><span class="dg-label">Commissions Email</span><?= dv($a['commissions_email'] ?? '') ?></div>
  <div class="dg-field"><span class="dg-label">Phone</span><?= dv($a['phone']) ?></div>
  <div class="dg-field"><span class="dg-label">Birthday</span><?= dv($a['birthday'] ? date('M j', strtotime($a['birthday'])) : '') ?></div>
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
  <?php if ($isAdmin): ?>
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
  <?php endif; ?>

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
  <div class="dg-field" style="grid-column:1/-1">
    <span class="dg-label">MLS / Association Memberships</span>
    <?php if ($mlsMemberships): ?>
      <span class="dg-value">
        <?php foreach ($mlsMemberships as $mem): ?>
          <?= h(trim($mem['mls_association'] . ($mem['mls_number'] !== '' ? ' — #' . $mem['mls_number'] : ''))) ?><br>
        <?php endforeach; ?>
      </span>
    <?php else: ?>
      <?= dv('') ?>
    <?php endif; ?>
  </div>

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
  <div class="dg-field"><span class="dg-label">Instagram</span><?= dv($a['instagram'] ?? '') ?></div>
  <div class="dg-field"><span class="dg-label">Skype</span><?= dv($a['skype'] ?? '') ?></div>
  <div class="dg-field"><span class="dg-label">Twitter / X</span><?= dv($a['twitter'] ?? '') ?></div>
  <div class="dg-field"><span class="dg-label">YouTube</span><?= dv($a['youtube'] ?? '') ?></div>
  <div class="dg-field"><span class="dg-label">TikTok</span><?= dv($a['tiktok'] ?? '') ?></div>
  <div class="dg-field"><span class="dg-label">Blog</span><?= dv($a['blog'] ?? '') ?></div>

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
  <div class="dg-field" style="grid-column:1/-1">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <?= bo_avatar_html($a['full_name'], $hsLatestKey, 'detail-avatar') ?>
      <div class="hs-grid" id="hs-grid-<?= $idx ?>" style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach ($hsAll as $hsFile): ?>
          <div class="hs-thumb" style="position:relative;width:70px;height:70px">
            <a href="api/intake.php?action=headshot&key=<?= urlencode($hsFile['file_key']) ?>" target="_blank" title="<?= h($hsFile['orig_name']) ?>">
              <img src="api/intake.php?action=headshot&key=<?= urlencode($hsFile['file_key']) ?>" alt="<?= h($hsFile['orig_name']) ?>" style="width:70px;height:70px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">
            </a>
            <a class="hs-dl" href="api/intake.php?action=headshot&key=<?= urlencode($hsFile['file_key']) ?>&dl=1" download="<?= h($hsFile['orig_name'] ?: 'headshot.jpg') ?>" title="Download">&#8681;</a>
            <?php if ($isAdmin): ?>
            <button type="button" class="hs-del" onclick="deleteHeadshot('<?= h($hsFile['file_key']) ?>')" title="Delete">✕</button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($hs === 0): ?>
          <span class="dg-value empty">No headshot uploaded yet</span>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($isAdmin): ?>
    <div style="margin-top:8px">
      <label class="hs-upload-label" for="hs-file-<?= $idx ?>">&#43; Upload Headshot</label>
      <input type="file" id="hs-file-<?= $idx ?>" accept="image/*" style="display:none" onchange="uploadHeadshot('<?= h($a['email']) ?>', <?= $idx ?>, this)">
      <span style="font-size:11px;color:var(--faint);margin-left:8px" id="hs-msg-<?= $idx ?>"></span>
    </div>
    <?php endif; ?>
  </div>

  <div class="dg-section" style="color:#a06000;font-weight:800">Notes <span style="font-weight:600;text-transform:none;letter-spacing:0">(admin/BIC/ML only — not visible to the agent)</span></div>
  <div class="dg-field" style="grid-column:1/-1" id="bo-notes-<?= $idx ?>" data-email="<?= h($a['email']) ?>">
    <div class="bo-notes-list" id="bo-notes-list-<?= $idx ?>" style="font-size:12px;color:var(--faint)">Loading notes…</div>
    <div style="display:flex;gap:8px;margin-top:8px">
      <textarea id="bo-notes-input-<?= $idx ?>" placeholder="Add a note…" rows="1"
                oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px';"
                style="flex:1;padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:12px;font-family:inherit;resize:none;overflow:hidden;max-height:200px"></textarea>
      <button type="button" class="btn-detail-link" onclick="addAgentNote(<?= $idx ?>)">Add Note</button>
    </div>
  </div>

  <?php if ($isAdmin): ?>
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
  <?php endif; ?>

  <?php if (!empty($a['retention_notes'])): ?>
  <div class="dg-section">Advantage Notes</div>
  <div class="dg-field" style="grid-column:1/-1">
    <span class="dg-label">Retention Notes</span>
    <span class="dg-value" style="white-space:pre-line"><?= dv($a['retention_notes']) ?></span>
  </div>
  <?php endif; ?>
  <div class="detail-actions">
    <?php if ($isSubmitted): ?>
      <span style="font-size:11px;color:var(--faint)">Submitted <?= h($a['submitted_at'] ? fmt_dt_et($a['submitted_at'], 'M j, Y') : '—') ?></span>
    <?php else: ?>
      <span style="font-size:11px;color:var(--faint)">Last updated <?= h($updated) ?></span>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
    <a href="onboarding.php" target="_blank" class="btn-detail-link">Onboarding Steps →</a>
    <?php endif; ?>
    <a href="intake.php" target="_blank" class="btn-detail-link">View Intake Form →</a>
    <?php if ($isAdmin): ?>
    <button type="button" class="btn-detail-link" onclick="openEditModal('<?= h($a['email']) ?>', '<?= h($a['full_name'] ?: $a['email']) ?>')">Edit Profile →</button>
    <a href="agent_profile.php?email=<?= h($a['email']) ?>" class="btn-detail-link">View Full Profile →</a>
    <button type="button" class="btn-detail-link" id="send-link-<?= $idx ?>" onclick="sendCompletionLink('<?= h($a['email']) ?>', 'send-link-<?= $idx ?>')">Send Completion Link →</button>
    <button type="button" class="btn-detail-link" onclick="openMergeModal('<?= h($a['email']) ?>', '<?= h($a['full_name'] ?: $a['email']) ?>')">Merge Duplicate →</button>
    <?php endif; ?>
  </div>

</div>
<?php
$html = ob_get_clean();
echo json_encode(['ok' => true, 'html' => $html]);
