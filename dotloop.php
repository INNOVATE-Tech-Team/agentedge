<?php
// DotLoop Transactions — main page.
// Reads from the local synced cache (dotloop_loops / dotloop_loop_participants)
// instead of calling DotLoop live per page view — see lib/dotloop.php's
// dotloop_sync_company_loops() and cron/sync_dotloop.php. Every agent sees
// the same shared connection's data, filtered to loops where their own email
// matches a participant record, so no per-agent DotLoop connect is needed.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/dotloop.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$agent = require_login();
$email = strtolower(trim($agent['email']));

$db = local_db();

$sharedTokens = dotloop_get_tokens(dotloop_shared_email());
$profileId    = $sharedTokens['profile_id'] ?? '';

$lastSync = $db->query("SELECT value FROM dotloop_sync_state WHERE key = 'last_full_sync'")->fetchColumn();

// ── Status filter tabs ──────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'ACTIVE';
$validStatuses = ['ACTIVE', 'PENDING', 'CLOSED', 'CANCELLED', 'ALL'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'ACTIVE';

// Tab keys map to DotLoop's real transaction_status deal-stage values
// (confirmed via live testing — these do not match the loop's own simple
// ARCHIVED/SOLD/WITHDRAWN status field, which is a separate concept).
$dotloopStatusMap = [
    'ACTIVE'    => 'ACTIVE_LISTING',
    'PENDING'   => 'UNDER_CONTRACT',
    'CLOSED'    => 'SOLD',
    'CANCELLED' => 'WITHDRAWN',
];

$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// EXISTS (not JOIN) so a loop with more than one of this agent's grouped
// emails as a participant still counts/lists exactly once.
$emailGroup   = dotloop_email_group($email);
$placeholders = implode(',', array_fill(0, count($emailGroup), '?'));
// Exclude loops the agent/admin archived in DotLoop directly — this is the
// loop's own status field, a separate concept from the deal_stage tabs above.
$where  = "UPPER(dl.status) != 'ARCHIVED' AND EXISTS (SELECT 1 FROM dotloop_loop_participants p WHERE p.loop_id = dl.loop_id AND p.email IN ({$placeholders}))";
$params = $emailGroup;
if ($statusFilter !== 'ALL' && isset($dotloopStatusMap[$statusFilter])) {
    $where   .= " AND dl.deal_stage = ?";
    $params[] = $dotloopStatusMap[$statusFilter];
}

$countStmt = $db->prepare(
    "SELECT COUNT(*) FROM dotloop_loops dl WHERE {$where}"
);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = $db->prepare(
    "SELECT dl.* FROM dotloop_loops dl
     WHERE {$where}
     ORDER BY dl.dl_updated DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$listStmt->execute($params);
$loops = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$loopMeta = ['total' => $total, 'hasMore' => ($offset + $perPage) < $total];

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt_date(mixed $val): string {
    if (!$val) return '—';
    $ts = strtotime((string)$val);
    return $ts ? date('M j, Y', $ts) : h((string)$val);
}
function status_class(string $s): string {
    return 'dl-status dl-status-' . htmlspecialchars(strtoupper($s), ENT_QUOTES);
}

$tabs = [
    'ACTIVE'    => 'Active',
    'PENDING'   => 'Pending',
    'CLOSED'    => 'Closed',
    'CANCELLED' => 'Cancelled',
    'ALL'       => 'All',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transactions — AgentEdge</title>
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
<?php render_sidebar('dotloop', $agent); ?>
<main class="main-content">

<?php if (!$lastSync): ?>
  <div class="dl-connect-cta">
    <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-bottom:16px;">
      <rect width="48" height="48" rx="10" fill="#f0f7e6"/>
      <path d="M24 14 L34 24 L24 34 L14 24 Z" stroke="#82C112" stroke-width="2.5" fill="none"/>
      <circle cx="24" cy="24" r="4" fill="#82C112"/>
    </svg>
    <h2>DotLoop Sync Not Run Yet</h2>
    <p>Transactions haven't been synced from DotLoop yet. Run <code>cron/sync_dotloop.php</code> once to populate this page.</p>
  </div>

<?php else: ?>
  <!-- ── Page header ──────────────────────────────────────────────────────── -->
  <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <h1 class="page-title">My Transactions</h1>
  </div>

  <!-- ── Status filter tabs ───────────────────────────────────────────────── -->
  <div class="dl-tabs">
    <?php foreach ($tabs as $key => $label): ?>
    <button
      class="dl-tab<?= $statusFilter === $key ? ' active' : '' ?>"
      onclick="location.href='dotloop.php?status=<?= h($key) ?>'">
      <?= h($label) ?>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- ── Loop list ────────────────────────────────────────────────────────── -->
  <?php if (empty($loops)): ?>
  <div style="text-align:center;padding:60px 20px;color:#aaa;font-size:14px;">
    No <?= strtolower(h($statusFilter === 'ALL' ? '' : $statusFilter . ' ')) ?>transactions found.
  </div>
  <?php else: ?>
  <div id="dl-loops">
  <?php foreach ($loops as $loop):
    $loopId   = (string)($loop['loop_id'] ?? '');
    $loopName = $loop['name'] ?: 'Unnamed Loop';
    $status   = strtoupper($loop['status'] ?: 'ACTIVE');
    if ($status === 'NO_STATUS' || $status === '') $status = 'ACTIVE';
  ?>
  <div class="dl-loop-row" id="loop-<?= h($loopId) ?>">
    <div class="dl-loop-head">
      <div style="flex:1;min-width:0;">
        <div class="dl-loop-name"><?= h($loopName) ?></div>
        <div class="dl-loop-meta">
          <span>Updated <?= fmt_date($loop['dl_updated'] ?? null) ?></span>
        </div>
      </div>
      <span class="<?= status_class($status) ?>"><?= h(ucfirst(strtolower($status))) ?></span>
      <div class="dl-loop-actions">
        <button class="dl-btn dl-btn-edit" onclick="togglePanel('edit-<?= h($loopId) ?>')">Edit Details</button>
        <button class="dl-btn dl-btn-docs" onclick="loadDocs('<?= h($loopId) ?>', '<?= h($profileId) ?>', '<?= h(addslashes((string)($loop['loop_url'] ?? ''))) ?>')">View Documents</button>
      </div>
    </div>

    <!-- Edit Details panel -->
    <div class="dl-panel" id="edit-<?= h($loopId) ?>">
      <form onsubmit="saveDetail(event, '<?= h($loopId) ?>', '<?= h($profileId) ?>')" style="max-width:640px;">
        <div class="dl-form-row">
          <div class="dl-field">
            <label>Closing Date</label>
            <input type="date" name="closing_date">
          </div>
          <div class="dl-field">
            <label>Purchase Price</label>
            <input type="number" name="purchase_price" step="0.01" min="0" placeholder="e.g. 350000">
          </div>
        </div>
        <div class="dl-form-row">
          <div class="dl-field">
            <label>Listing Commission $</label>
            <input type="number" name="listing_commission" step="0.01" min="0" placeholder="e.g. 5250">
          </div>
          <div class="dl-field">
            <label>Selling Commission $</label>
            <input type="number" name="selling_commission" step="0.01" min="0" placeholder="e.g. 5250">
          </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
          <button type="submit" style="padding:8px 18px;background:#82C112;color:white;border:none;border-radius:6px;font-weight:700;font-size:13px;cursor:pointer;">Save Changes</button>
          <button type="button" onclick="togglePanel('edit-<?= h($loopId) ?>')" style="padding:8px 14px;background:white;border:1px solid #ccc;border-radius:6px;font-size:13px;cursor:pointer;">Cancel</button>
          <span class="dl-save-msg" id="msg-<?= h($loopId) ?>" style="font-size:12px;"></span>
        </div>
      </form>
    </div>

    <!-- Documents panel -->
    <div class="dl-panel" id="docs-<?= h($loopId) ?>">
      <div id="docs-inner-<?= h($loopId) ?>" style="min-height:40px;">
        <span style="color:#aaa;font-size:13px;">Loading folders…</span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>

  <?php if ($loopMeta['hasMore']): ?>
  <div style="text-align:center;margin-top:20px;">
    <a href="dotloop.php?status=<?= h($statusFilter) ?>&pg=<?= $page + 1 ?>"
       style="display:inline-block;padding:10px 24px;border:1px solid #ccc;border-radius:8px;font-size:13px;font-weight:700;color:#333;text-decoration:none;">
      Load More
    </a>
  </div>
  <?php endif; ?>
  <?php endif; // end $loops not empty ?>

<?php endif; // end lastSync ?>

</main>
</div>

<script>
// ── Panel toggles ──────────────────────────────────────────────────────────────
function togglePanel(id) {
  var p = document.getElementById(id);
  if (!p) return;
  p.classList.toggle('open');
}

// ── Save loop detail ───────────────────────────────────────────────────────────
function saveDetail(e, loopId, profileId) {
  e.preventDefault();
  var form = e.target;
  var msg  = document.getElementById('msg-' + loopId);
  msg.textContent = 'Saving…';
  msg.style.color = '#888';

  var payload = {
    loop_id:             loopId,
    profile_id:          profileId,
    closing_date:        form.closing_date.value        || null,
    purchase_price:      form.purchase_price.value      ? parseFloat(form.purchase_price.value)      : null,
    listing_commission:  form.listing_commission.value  ? parseFloat(form.listing_commission.value)  : null,
    selling_commission:  form.selling_commission.value  ? parseFloat(form.selling_commission.value)  : null,
  };

  fetch('api/dotloop_action.php?action=update_loop_detail', {
    method:  'POST',
    headers: {'Content-Type': 'application/json'},
    body:    JSON.stringify(payload),
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    if (d.ok) {
      msg.textContent = 'Saved!';
      msg.style.color = '#3a6b1a';
      setTimeout(function() {
        msg.textContent = '';
        var panel = document.getElementById('edit-' + loopId);
        if (panel) panel.classList.remove('open');
      }, 1500);
    } else {
      msg.textContent = 'Error: ' + (d.error || 'Save failed');
      msg.style.color = '#c0392b';
    }
  })
  .catch(function() {
    msg.textContent = 'Request failed. Try again.';
    msg.style.color = '#c0392b';
  });
}

// ── Load documents ─────────────────────────────────────────────────────────────
function loadDocs(loopId, profileId, loopUrl) {
  var panel = document.getElementById('docs-' + loopId);
  if (!panel) return;

  // Toggle: if already open and loaded, just close
  if (panel.classList.contains('open')) {
    panel.classList.remove('open');
    return;
  }

  panel.classList.add('open');
  var inner = document.getElementById('docs-inner-' + loopId);

  // If already loaded, just show
  if (inner.dataset.loaded) return;
  inner.dataset.loaded = '1';
  inner.innerHTML = '<span style="color:#aaa;font-size:13px;">Loading folders…</span>';

  fetch('api/dotloop_action.php?action=get_folders', {
    method:  'POST',
    headers: {'Content-Type': 'application/json'},
    body:    JSON.stringify({loop_id: loopId, profile_id: profileId}),
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    if (!d.ok) {
      inner.innerHTML = '<span style="color:#c0392b;font-size:13px;">Error: ' + escHtml(d.error || 'Failed') + '</span>';
      return;
    }
    var folders = d.folders || [];

    // DotLoop's API has no way to fetch a document's actual content or a
    // download link (confirmed against their own public API docs) — viewing
    // or downloading the real file only works by opening the loop in DotLoop
    // itself, where whoever's logged in has real access to it.
    var html = '';
    if (loopUrl) {
      html += '<div style="margin-bottom:12px;">'
            + '<a href="' + escAttr(loopUrl) + '" target="_blank" rel="noopener" '
            + 'style="font-size:12px;font-weight:700;color:#82C112;text-decoration:none;">View this transaction in DotLoop →</a>'
            + '</div>';
    }

    html += '<div style="margin-bottom:14px;">'
          + '<button type="button" class="dl-btn" style="font-size:12px;padding:6px 12px;" '
          + 'onclick="toggleUploadForm(\'' + escAttr(loopId) + '\')">+ Upload Document</button>'
          + '<div id="upload-form-' + loopId + '" style="display:none;margin-top:10px;padding:12px;background:#fafafa;border-radius:8px;">'
          + '<select id="upload-folder-' + loopId + '" style="width:100%;margin-bottom:8px;padding:6px;border:1px solid #ccc;border-radius:4px;font-size:13px;">'
          + folders.map(function(f) { return '<option value="' + escAttr(String(f.id)) + '">' + escHtml(f.name || ('Folder ' + f.id)) + '</option>'; }).join('')
          + '</select>'
          + '<input type="file" id="upload-file-' + loopId + '" style="width:100%;margin-bottom:8px;font-size:13px;">'
          + '<div style="display:flex;gap:8px;align-items:center;">'
          + '<button type="button" class="dl-btn dl-btn-edit" style="font-size:12px;" onclick="uploadDocument(\'' + escAttr(loopId) + '\',\'' + escAttr(profileId) + '\')">Upload</button>'
          + '<span id="upload-msg-' + loopId + '" style="font-size:12px;"></span>'
          + '</div>'
          + '</div>'
          + '</div>';

    if (!folders.length) {
      html += '<span style="color:#aaa;font-size:13px;">No folders found.</span>';
      inner.innerHTML = html;
      return;
    }
    html += '<div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#aaa;margin-bottom:8px;">Folders</div>';
    folders.forEach(function(f) {
      var fid   = f.id;
      var fname = f.name || ('Folder ' + fid);
      html += '<div class="dl-folder" id="folder-' + loopId + '-' + fid + '" '
            + 'onclick="loadFolderDocs(\'' + escAttr(loopId) + '\',\'' + escAttr(profileId) + '\',\'' + escAttr(String(fid)) + '\',this)">'
            + '▶ ' + escHtml(fname)
            + '<div class="dl-folder-docs" id="fdocs-' + loopId + '-' + fid + '" style="margin-top:6px;display:none;"></div>'
            + '</div>';
    });
    inner.innerHTML = html;
  })
  .catch(function() {
    inner.innerHTML = '<span style="color:#c0392b;font-size:13px;">Request failed.</span>';
  });
}

function toggleUploadForm(loopId) {
  var form = document.getElementById('upload-form-' + loopId);
  if (form) form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function uploadDocument(loopId, profileId) {
  var folderSel = document.getElementById('upload-folder-' + loopId);
  var fileInput = document.getElementById('upload-file-' + loopId);
  var msg       = document.getElementById('upload-msg-' + loopId);
  var folderId  = folderSel ? folderSel.value : '';
  var file      = fileInput && fileInput.files[0];

  if (!file) { msg.textContent = 'Choose a file first.'; msg.style.color = '#c0392b'; return; }

  msg.textContent = 'Uploading…';
  msg.style.color = '#888';

  var formData = new FormData();
  formData.append('loop_id', loopId);
  formData.append('profile_id', profileId);
  formData.append('folder_id', folderId);
  formData.append('file', file);

  fetch('api/dotloop_action.php?action=upload_document', {
    method: 'POST',
    body:   formData,
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    if (d.ok) {
      msg.textContent = 'Uploaded ✓';
      msg.style.color = '#3a6b1a';
      fileInput.value = '';
      // Force a fresh folder-docs load next time that folder is opened.
      var docsDiv = document.getElementById('fdocs-' + loopId + '-' + folderId);
      if (docsDiv) delete docsDiv.dataset.loaded;
    } else {
      msg.textContent = 'Error: ' + (d.error || 'Upload failed');
      msg.style.color = '#c0392b';
    }
  })
  .catch(function() {
    msg.textContent = 'Request failed.';
    msg.style.color = '#c0392b';
  });
}

function loadFolderDocs(loopId, profileId, folderId, folderEl) {
  var docsDiv = document.getElementById('fdocs-' + loopId + '-' + folderId);
  if (!docsDiv) return;

  // Toggle
  if (docsDiv.style.display !== 'none') {
    docsDiv.style.display = 'none';
    folderEl.firstChild.textContent = '▶ ' + folderEl.firstChild.textContent.replace(/^[▶▼] /, '');
    return;
  }

  docsDiv.style.display = 'block';

  // Already loaded
  if (docsDiv.dataset.loaded) return;
  docsDiv.dataset.loaded = '1';
  docsDiv.innerHTML = '<span style="color:#aaa;font-size:12px;padding:4px 12px;display:block;">Loading documents…</span>';

  fetch('api/dotloop_action.php?action=get_documents', {
    method:  'POST',
    headers: {'Content-Type': 'application/json'},
    body:    JSON.stringify({loop_id: loopId, profile_id: profileId, folder_id: folderId}),
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    if (!d.ok) {
      docsDiv.innerHTML = '<span style="color:#c0392b;font-size:12px;padding:4px 12px;display:block;">Error: ' + escHtml(d.error || 'Failed') + '</span>';
      return;
    }
    var docs = d.documents || [];
    if (!docs.length) {
      docsDiv.innerHTML = '<span style="color:#aaa;font-size:12px;padding:4px 12px;display:block;">No documents.</span>';
      return;
    }
    var html = '';
    docs.forEach(function(doc) {
      var name = doc.name || doc.filename || ('Document ' + doc.id);
      var link = doc.downloadLink || doc.download_link || '';
      html += '<div class="dl-doc">'
            + '<span class="dl-doc-name">' + escHtml(name) + '</span>';
      if (link) {
        html += '<a href="' + escAttr(link) + '" target="_blank" rel="noopener" '
              + 'style="font-size:11px;font-weight:700;color:#82C112;text-decoration:none;white-space:nowrap;">View / Download</a>';
      }
      html += '</div>';
    });
    docsDiv.innerHTML = html;
  })
  .catch(function() {
    docsDiv.innerHTML = '<span style="color:#c0392b;font-size:12px;padding:4px 12px;display:block;">Request failed.</span>';
  });
}

// ── Escaping helpers ──────────────────────────────────────────────────────────
function escHtml(s) {
  var d = document.createElement('div');
  d.appendChild(document.createTextNode(String(s)));
  return d.innerHTML;
}
function escAttr(s) {
  return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
</html>
