<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// ── MC options from CRM roster ─────────────────────────────────────────────
$c      = cfg();
$base   = rtrim($c['crm_base'] ?? 'https://bold360.vip/api', '/');
$token  = $c['crm_token'] ?? '';
$url    = $base . '/public/retention-roster' . ($token ? '?token=' . urlencode($token) : '');
$ctx    = stream_context_create(['http' => ['timeout' => 12, 'header' => "Accept: application/json\r\n"]]);
$raw    = @file_get_contents($url, false, $ctx);
$roster = ($raw !== false) ? (json_decode($raw, true) ?? []) : [];

$mc_opts = [];
foreach ($roster as $a) {
    $mc   = $a['marketCenter'] ?? '';
    if ($mc === '' && !empty($a['marketCenters'])) $mc = $a['marketCenters'][0]['name'] ?? '';
    $slug = slugify_mc($mc);
    if ($mc && $slug && !isset($mc_opts[$slug])) $mc_opts[$slug] = $mc;
}
ksort($mc_opts);

// ── CSV parser ─────────────────────────────────────────────────────────────
function parse_csv_file(string $path): array {
    $rows = [];
    if (($fh = fopen($path, 'r')) === false) return [];
    // Strip UTF-8 BOM if present
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") rewind($fh);

    $header = null;
    while (($cols = fgetcsv($fh, 1000)) !== false) {
        if (!$header) {
            $header = array_map(fn($h) => strtolower(trim($h)), $cols);
            continue;
        }
        if (count(array_filter($cols)) === 0) continue;
        $row = array_combine(
            $header,
            array_pad(array_slice($cols, 0, count($header)), count($header), '')
        );
        $email = trim($row['email'] ?? ($row['email address'] ?? ($row['e-mail'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $first = trim($row['first name'] ?? ($row['firstname'] ?? ($row['first'] ?? '')));
        $last  = trim($row['last name']  ?? ($row['lastname']  ?? ($row['last']  ?? '')));
        $name  = trim($row['name'] ?? ($row['full name'] ?? ($row['agent name'] ?? trim("$first $last"))));
        $phone = trim($row['phone'] ?? ($row['phone number'] ?? ($row['mobile'] ?? ($row['cell'] ?? ($row['telephone'] ?? '')))));
        $rows[] = ['name' => $name, 'email' => strtolower($email), 'phone' => $phone];
    }
    fclose($fh);
    return $rows;
}

// ── Handle POST ────────────────────────────────────────────────────────────
$flash       = null;
$preview     = null;
$mc_selected = '';
$raw_rows    = '[]';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $flash = ['err', 'Session expired. Please try again.'];
    } else {
        $action      = $_POST['action'] ?? 'preview';
        $mc_selected = trim($_POST['mc'] ?? '');

        if ($action === 'preview') {
            if (empty($_FILES['csvfile']) || $_FILES['csvfile']['error'] !== UPLOAD_ERR_OK) {
                $flash = ['err', 'No file uploaded. Please choose a CSV file.'];
            } else {
                $preview  = parse_csv_file($_FILES['csvfile']['tmp_name']);
                $raw_rows = json_encode($preview);
                if (empty($preview)) {
                    $flash   = ['err', 'No valid rows found. Make sure the CSV has <strong>Email</strong> and <strong>Name</strong> columns.'];
                    $preview = null;
                }
            }
        } elseif ($action === 'import') {
            $rows = json_decode($_POST['rows'] ?? '[]', true);
            $mc   = trim($_POST['mc'] ?? '');
            if (!is_array($rows)) $rows = [];

            $db      = local_db();
            $mcJson  = $mc ? json_encode([$mc]) : '[]';
            $imported = 0;
            $skipped  = 0;

            $ins  = $db->prepare("INSERT OR REPLACE INTO imported_agents (name, email, phone, mc_slug, imported_by, imported_at) VALUES (?,?,?,?,?,datetime('now'))");
            $chk  = $db->prepare("SELECT role FROM agent_roles WHERE email=?");
            $role = $db->prepare("INSERT OR REPLACE INTO agent_roles (email, role, mc_slugs, updated_by, updated_at) VALUES (?,?,?,?,datetime('now'))");

            foreach ($rows as $r) {
                $email = strtolower(trim($r['email'] ?? ''));
                $name  = trim($r['name'] ?? '');
                if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }
                $ins->execute([$name ?: $email, $email, trim($r['phone'] ?? ''), $mc, $agent['email']]);
                $chk->execute([$email]);
                $existing = $chk->fetchColumn();
                if (!$existing || $existing === 'agent') {
                    $role->execute([$email, 'agent', $mcJson, $agent['email']]);
                }
                $imported++;
            }
            $msg   = "Successfully imported <strong>$imported</strong> agent" . ($imported !== 1 ? 's' : '');
            if ($skipped) $msg .= " ($skipped row" . ($skipped !== 1 ? 's' : '') . " skipped — invalid email)";
            $flash = ['ok', $msg . '.'];
        }
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Import Agents — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .import-card{max-width:780px}
    /* Upload zone */
    .drop-zone{border:2px dashed #ccc;border-radius:8px;padding:32px;text-align:center;cursor:pointer;transition:border-color 120ms,background 120ms;position:relative}
    .drop-zone:hover,.drop-zone.drag{border-color:#82C112;background:#f9fdf5}
    .drop-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
    .drop-icon{font-size:28px;margin-bottom:8px}
    .drop-label{font-size:14px;font-weight:700;color:#333}
    .drop-sub{font-size:12px;color:#888;margin-top:4px}
    .drop-chosen{font-size:12px;color:#82C112;font-weight:700;margin-top:6px}
    /* MC select */
    .field-label{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#666;margin-bottom:5px}
    .field-select{width:100%;padding:9px 10px;font-size:13px;border:1px solid #ccc;border-radius:6px;background:white;margin-bottom:16px}
    .field-select:focus{outline:none;border-color:#82C112}
    /* Buttons */
    .btn-primary{padding:10px 24px;background:#82C112;border:none;border-radius:6px;font-size:13px;font-weight:800;color:#000;cursor:pointer}
    .btn-primary:hover{background:#6ea30f}
    .btn-ghost{padding:10px 18px;background:white;border:1px solid #ccc;border-radius:6px;font-size:13px;color:#555;cursor:pointer;margin-right:8px}
    .btn-ghost:hover{background:#f5f5f5}
    /* Preview table */
    .preview-header{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:10px}
    .preview-title{font-size:14px;font-weight:800;color:#111}
    .preview-count{font-size:12px;color:#82C112;font-weight:700}
    .tbl-wrap{overflow-x:auto;border:1px solid #e8e8e8;border-radius:6px;margin-bottom:16px}
    table{width:100%;border-collapse:collapse;font-size:12px}
    th{background:#f5f5f5;padding:8px 12px;text-align:left;font-weight:800;text-transform:uppercase;font-size:10px;letter-spacing:.06em;color:#666;border-bottom:1px solid #e8e8e8}
    td{padding:8px 12px;border-bottom:1px solid #f0f0f0;color:#333}
    tr:last-child td{border-bottom:none}
    .td-email{color:#555}
    .td-phone{color:#777}
    td.empty{color:#ccc;font-style:italic}
    /* Flash */
    .flash{padding:12px 16px;border-radius:6px;font-size:13px;margin-bottom:16px}
    .flash.ok{background:#f0f9e4;border:1px solid #c3dfa8;color:#3a6b1a}
    .flash.err{background:#fff0f0;border:1px solid #f5c6c6;color:#c00}
    /* CSV hint */
    .csv-hint{background:#f8f8f8;border:1px solid #e8e8e8;border-radius:6px;padding:12px 16px;font-size:12px;color:#666;margin-bottom:18px}
    .csv-hint code{background:#eee;padding:1px 4px;border-radius:3px;font-family:monospace;font-size:11px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_import', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Import Agents</div>
      <a href="roster.php" style="font-size:12px;color:#888;text-decoration:none">&larr; Back to Roster</a>
    </header>
    <main class="wrap">
      <div class="card import-card" style="padding:24px">

        <?php if ($flash): ?>
          <div class="flash <?= $flash[0] ?>"><?= $flash[1] ?></div>
        <?php endif; ?>

        <?php if (!$preview): ?>
        <!-- ── Step 1: Upload + select MC ──────────────────────────────── -->
        <div class="csv-hint">
          <strong>Accepted columns</strong> (header row required, column order doesn&rsquo;t matter):<br>
          <code>Name</code> or <code>First Name</code> + <code>Last Name</code> &nbsp;&bull;&nbsp;
          <code>Email</code> &nbsp;&bull;&nbsp;
          <code>Phone</code> <em>(optional)</em>
        </div>

        <form method="post" enctype="multipart/form-data" id="upload-form">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="preview">

          <div class="field-label">CSV File</div>
          <div class="drop-zone" id="drop-zone">
            <input type="file" name="csvfile" id="csv-file" accept=".csv,text/csv" required>
            <div class="drop-icon">📄</div>
            <div class="drop-label">Click to choose a CSV file</div>
            <div class="drop-sub">or drag and drop here</div>
            <div class="drop-chosen" id="chosen-name" hidden></div>
          </div>

          <div style="margin-top:20px"></div>

          <div class="field-label">Market Center <span style="font-weight:400;color:#aaa;font-size:10px;text-transform:none">(optional — assign later)</span></div>
          <select name="mc" class="field-select">
            <option value="">— Unassigned (assign later) —</option>
            <?php foreach ($mc_opts as $slug => $label): ?>
              <option value="<?= h($slug) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>

          <button type="submit" class="btn-primary">Preview Import &rarr;</button>
        </form>

        <?php else: ?>
        <!-- ── Step 2: Preview table + confirm ───────────────────────────── -->
        <div class="preview-header">
          <span class="preview-title">Preview</span>
          <span class="preview-count"><?= count($preview) ?> agent<?= count($preview) !== 1 ? 's' : '' ?> ready to import</span>
        </div>

        <div class="tbl-wrap">
          <table>
            <thead>
              <tr><th>#</th><th>Name</th><th>Email</th><th>Phone</th></tr>
            </thead>
            <tbody>
              <?php foreach ($preview as $i => $row): ?>
              <tr>
                <td style="color:#aaa;width:30px"><?= $i + 1 ?></td>
                <td><?= $row['name'] !== '' ? h($row['name']) : '<span class="empty">—</span>' ?></td>
                <td class="td-email"><?= h($row['email']) ?></td>
                <td class="td-phone"><?= $row['phone'] !== '' ? h($row['phone']) : '<span class="empty">—</span>' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="import">
          <input type="hidden" name="rows" value="<?= h($raw_rows) ?>">

          <div class="field-label">Market Center <span style="font-weight:400;color:#aaa;font-size:10px;text-transform:none">(optional — assign later)</span></div>
          <select name="mc" class="field-select">
            <option value="">— Unassigned (assign later) —</option>
            <?php foreach ($mc_opts as $slug => $label): ?>
              <option value="<?= h($slug) ?>"<?= $mc_selected === $slug ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>

          <a href="admin_import.php" class="btn-ghost" style="display:inline-block;text-decoration:none">&#8592; Start Over</a>
          <button type="submit" class="btn-primary">Confirm Import</button>
        </form>
        <?php endif; ?>

      </div>
    </main>
  </div>
</div>
<script>
// File picker label
const fileInput = document.getElementById('csv-file');
const chosenName = document.getElementById('chosen-name');
const dropZone = document.getElementById('drop-zone');

fileInput?.addEventListener('change', () => {
  const f = fileInput.files[0];
  if (f) { chosenName.textContent = f.name; chosenName.hidden = false; }
});

// Drag visual feedback
dropZone?.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag'); });
dropZone?.addEventListener('dragleave', () => dropZone.classList.remove('drag'));
dropZone?.addEventListener('drop', e => {
  e.preventDefault();
  dropZone.classList.remove('drag');
  if (e.dataTransfer.files.length) {
    fileInput.files = e.dataTransfer.files;
    chosenName.textContent = e.dataTransfer.files[0].name;
    chosenName.hidden = false;
  }
});
</script>
</body>
</html>
