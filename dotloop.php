<?php
// DotLoop Transactions — main page.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/dotloop.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

$agent = require_login();
$email = $agent['email'];

$connected = dotloop_is_connected($email);
$tokens    = $connected ? dotloop_get_tokens($email) : null;
$profileId = $tokens['profile_id'] ?? '';

// ── Fetch loops server-side if connected ──────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'ACTIVE';
$validStatuses = ['ACTIVE', 'PENDING', 'CLOSED', 'CANCELLED', 'ARCHIVE', 'ALL'];
if (!in_array($statusFilter, $validStatuses, true)) $statusFilter = 'ACTIVE';

$page     = max(1, (int)($_GET['pg'] ?? 1));
$loops    = [];
$loopMeta = ['total' => 0, 'hasMore' => false];
$apiError = '';

if ($connected && $profileId !== '') {
    $statusParam = ($statusFilter === 'ALL') ? 'ACTIVE,PENDING,CLOSED,CANCELLED,ARCHIVE' : $statusFilter;
    $path = "/profile/{$profileId}/loop?" . http_build_query([
        'pg'      => $page,
        'pgsize'  => 50,
        'status'  => $statusParam,
    ]);
    $result = dotloop_api($email, 'GET', $path);

    if ($result['ok']) {
        $raw   = $result['data'];
        $loops = $raw['data'] ?? [];
        $meta  = $raw['meta'] ?? [];
        $loopMeta['total']   = (int)($meta['total'] ?? count($loops));
        $loopMeta['hasMore'] = count($loops) === 50 && ($page * 50) < $loopMeta['total'];
    } elseif (($result['status'] ?? 0) === 401) {
        $apiError = '401';
    } else {
        $apiError = $result['error'] ?? 'Could not load transactions from DotLoop.';
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt_price(mixed $val): string {
    if ($val === null || $val === '' || $val === 0) return '—';
    return '$' . number_format((float)$val, 0);
}
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

<?php if (!$connected): ?>
  <div class="dl-connect-cta">
    <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-bottom:16px;">
      <rect width="48" height="48" rx="10" fill="#f0f7e6"/>
      <path d="M24 14 L34 24 L24 34 L14 24 Z" stroke="#82C112" stroke-width="2.5" fill="none"/>
      <circle cx="24" cy="24" r="4" fill="#82C112"/>
    </svg>
    <h2>Connect DotLoop</h2>
    <p>Link your DotLoop account to view transaction loops, track closing dates and commissions, and access documents — all inside AgentEdge.</p>
    <a href="dotloop_connect.php" style="display:inline-block;padding:12px 28px;background:#82C112;color:white;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;">Connect DotLoop Account</a>
  </div>

<?php elseif ($apiError === '401'): ?>
  <div class="dl-connect-cta">
    <h2>DotLoop Session Expired</h2>
    <p>Your DotLoop connection has expired or been revoked. Reconnect to continue viewing your transactions.</p>
    <a href="dotloop_connect.php" style="display:inline-block;padding:12px 24px;background:#82C112;color:white;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;">Reconnect DotLoop</a>
  </div>

<?php else: ?>
  <!-- ── Page header ──────────────────────────────────────────────────────── -->
  <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <h1 class="page-title">My Transactions</h1>
    <a href="dotloop_connect.php" style="font-size:12px;color:#666;text-decoration:none;border:1px solid #ddd;border-radius:6px;padding:6px 12px;">
      Manage DotLoop Connection
    </a>
  </div>

  <?php if ($connected && !empty($_GET['connected'])): ?>
  <div style="margin-bottom:16px;padding:10px 16px;background:#eef5e8;border:1px solid #c3e09a;border-radius:8px;font-size:13px;color:#3a6b1a;font-weight:600;">
    DotLoop connected successfully!
  </div>
  <?php endif; ?>

  <?php if ($apiError && $apiError !== '401'): ?>
  <div style="margin-bottom:16px;padding:12px 16px;background:#fff3f3;border:1px solid #ffb3b3;border-radius:8px;font-size:13px;color:#c0392b;">
    <?= h($apiError) ?>
  </div>
  <?php endif; ?>

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
    $loopId    = (string)($loop['id'] ?? '');
    $loopName  = $loop['name']   ?? 'Unnamed Loop';
    $status    = strtoupper($loop['status'] ?? 'ACTIVE');
    if ($status === 'NO_STATUS' || $status === '') $status = 'ACTIVE';
    $detail    = $loop['detail'] ?? [];
    $closingRaw    = $detail['closing_date']                 ?? ($loop['closing_date'] ?? null);
    $priceRaw      = $detail['purchase_price']               ?? ($loop['purchase_price'] ?? null);
    $listCommRaw   = $detail['listing_commission_amount']    ?? null;
    $sellCommRaw   = $detail['selling_commission_amount']    ?? null;
    $docCount      = (int)($loop['document_count'] ?? 0);
  ?>
  <div class="dl-loop-row" id="loop-<?= h($loopId) ?>">
    <div class="dl-loop-head">
      <div style="flex:1;min-width:0;">
        <div class="dl-loop-name"><?= h($loopName) ?></div>
        <div class="dl-loop-meta">
          <span><?= fmt_date($closingRaw) ?></span>
          <span><?= fmt_price($priceRaw) ?></span>
          <?php if ($listCommRaw !== null): ?><span>List comm: <?= fmt_price($listCommRaw) ?></span><?php endif; ?>
          <?php if ($sellCommRaw !== null): ?><span>Sell comm: <?= fmt_price($sellCommRaw) ?></span><?php endif; ?>
        </div>
      </div>
      <span class="<?= status_class($status) ?>"><?= h(ucfirst(strtolower($status))) ?></span>
      <?php if ($docCount > 0): ?>
      <span style="font-size:11px;background:#f0f0f0;border-radius:10px;padding:2px 8px;color:#555;white-space:nowrap;"><?= $docCount ?> doc<?= $docCount !== 1 ? 's' : '' ?></span>
      <?php endif; ?>
      <div class="dl-loop-actions">
        <button class="dl-btn dl-btn-edit" onclick="togglePanel('edit-<?= h($loopId) ?>')">Edit Details</button>
        <button class="dl-btn dl-btn-docs" onclick="loadDocs('<?= h($loopId) ?>', '<?= h($profileId) ?>')">View Documents</button>
      </div>
    </div>

    <!-- Edit Details panel -->
    <div class="dl-panel" id="edit-<?= h($loopId) ?>">
      <form onsubmit="saveDetail(event, '<?= h($loopId) ?>', '<?= h($profileId) ?>')" style="max-width:640px;">
        <div class="dl-form-row">
          <div class="dl-field">
            <label>Closing Date</label>
            <input type="date" name="closing_date" value="<?= h($closingRaw ?? '') ?>">
          </div>
          <div class="dl-field">
            <label>Purchase Price</label>
            <input type="number" name="purchase_price" step="0.01" min="0" placeholder="e.g. 350000" value="<?= h($priceRaw ?? '') ?>">
          </div>
        </div>
        <div class="dl-form-row">
          <div class="dl-field">
            <label>Listing Commission $</label>
            <input type="number" name="listing_commission" step="0.01" min="0" placeholder="e.g. 5250" value="<?= h($listCommRaw ?? '') ?>">
          </div>
          <div class="dl-field">
            <label>Selling Commission $</label>
            <input type="number" name="selling_commission" step="0.01" min="0" placeholder="e.g. 5250" value="<?= h($sellCommRaw ?? '') ?>">
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

<?php endif; // end connected ?>

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
function loadDocs(loopId, profileId) {
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
    if (!folders.length) {
      inner.innerHTML = '<span style="color:#aaa;font-size:13px;">No folders found.</span>';
      return;
    }
    var html = '<div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#aaa;margin-bottom:8px;">Folders</div>';
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
