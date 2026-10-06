<?php
// Technology → Staff Feature Access — super_admin-only control panel for the
// generic staff_feature_flags table (see local_db.php / lib/feature_flags.php).
// Lists staff + super_admin identities only (never plain agents/recruiters/
// bic/mc_leader/etc.) with a per-feature ON/OFF toggle. Adding a future
// internal feature flag means adding its key to STAFF_FEATURE_KEYS in
// lib/feature_flags.php and a column to $FEATURES below — never a new page.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/lib/feature_flags.php';

$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

// Features this page can manage — currently just admin_work_os, but the
// table/helper are already generic; a future feature is a new row here,
// never a new table or column.
$FEATURES = [
    'admin_work_os' => 'Admin Work OS',
];

$db = local_db();

// Staff + super_admin identities only — same agent_roles source admin_roles.php
// uses, filtered to just the two admin-eligible roles (see roles.php's
// default_perms(): isAdmin is true only for these two).
$staffRows = $db->query(
    "SELECT email, role FROM agent_roles WHERE role IN ('super_admin','staff') ORDER BY role DESC, email"
)->fetchAll(PDO::FETCH_ASSOC);

// Names from the local roster, same fallback-to-email convention as
// admin_roles.php's $rosterByEmail.
$nameByEmail = [];
try {
    foreach ($db->query(
        "SELECT DISTINCT agent_name, email FROM innovate_roster WHERE email != ''"
    )->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $e = strtolower(trim($r['email']));
        if ($e && !isset($nameByEmail[$e])) $nameByEmail[$e] = trim($r['agent_name']);
    }
} catch (\Throwable $e) {}

// Existing flag rows, keyed by email|feature_key, for every staff row above.
$flagsByEmail = [];
if ($staffRows) {
    $emails = array_values(array_unique(array_map(fn($r) => strtolower($r['email']), $staffRows)));
    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $stmt = $db->prepare(
        "SELECT user_email, feature_key, enabled FROM staff_feature_flags WHERE user_email IN ($placeholders)"
    );
    $stmt->execute($emails);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $flagsByEmail[strtolower($r['user_email'])][$r['feature_key']] = (int)$r['enabled'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Staff Feature Access — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .sfa-table{width:100%;border-collapse:collapse;font-size:13px}
    .sfa-table th{text-align:left;padding:9px 12px;background:#f5f5f5;border-bottom:2px solid #e0e0e0;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#555}
    .sfa-table td{padding:9px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
    .sfa-table tr:last-child td{border-bottom:none}
    .sfa-email{color:#888;font-size:12px}
    .role-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
    .role-staff{background:#e8f0ff;color:#2255cc}
    .role-super_admin{background:#000;color:#82C112}
    .sfa-always-on{font-size:12px;font-weight:700;color:#5b8e0d;background:#eef5e8;padding:5px 12px;border-radius:4px;display:inline-block}
    .sfa-toggle{position:relative;display:inline-block;width:40px;height:22px;vertical-align:middle}
    .sfa-toggle input{opacity:0;width:0;height:0}
    .sfa-toggle-slider{position:absolute;cursor:pointer;inset:0;background:#ccc;border-radius:22px;transition:.15s}
    .sfa-toggle-slider:before{content:"";position:absolute;height:16px;width:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.15s}
    .sfa-toggle input:checked + .sfa-toggle-slider{background:#82C112}
    .sfa-toggle input:checked + .sfa-toggle-slider:before{transform:translateX(18px)}
    .sfa-toggle input:disabled + .sfa-toggle-slider{opacity:.5;cursor:default}
    .sfa-err{padding:10px 14px;background:#fff0f0;border:1px solid #f5c6c6;border-radius:6px;color:#c00;font-size:13px;margin-bottom:16px;display:none}
    .sfa-sub{font-size:12px;color:#888;margin-bottom:16px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('staff_feature_access', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Staff Feature Access</div>
    </header>
    <main class="wrap">
      <div class="sfa-err" id="sfa-err"></div>
      <div class="sfa-sub">Controls per-account access to internal feature flags.</div>

      <table class="sfa-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <?php foreach ($FEATURES as $label): ?>
            <th><?= htmlspecialchars($label) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staffRows as $r):
            $email = strtolower(trim($r['email']));
            $name  = $nameByEmail[$email] ?? $email;
            $isSuper = $r['role'] === 'super_admin';
          ?>
          <tr>
            <td><?= htmlspecialchars($name) ?></td>
            <td class="sfa-email">
              <?= htmlspecialchars($email) ?>
              <span class="role-badge role-<?= htmlspecialchars($r['role']) ?>"><?= $isSuper ? 'Super Admin' : 'Staff' ?></span>
            </td>
            <?php foreach ($FEATURES as $key => $label):
              $enabled = (bool)($flagsByEmail[$email][$key] ?? false);
            ?>
            <td>
              <label class="sfa-toggle">
                <input type="checkbox" data-email="<?= htmlspecialchars($email) ?>" data-feature="<?= htmlspecialchars($key) ?>" <?= $enabled ? 'checked' : '' ?>>
                <span class="sfa-toggle-slider"></span>
              </label>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
          <?php if (!$staffRows): ?>
          <tr><td colspan="<?= 2 + count($FEATURES) ?>" class="sfa-email">No staff or super admin accounts found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </main>
  </div>
</div>
<script>
document.querySelectorAll('.sfa-toggle input[type=checkbox]').forEach(cb => {
  cb.addEventListener('change', () => {
    const errBox = document.getElementById('sfa-err');
    errBox.style.display = 'none';
    const email   = cb.dataset.email;
    const feature = cb.dataset.feature;
    const enabled = cb.checked;
    cb.disabled = true;
    fetch('api/staff_feature_action.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action: 'set_flag', target_email: email, feature_key: feature, enabled: enabled, csrf: window.AE_CSRF || ''})
    }).then(r => r.json()).then(d => {
      if (!d || !d.ok) {
        cb.checked = !enabled;
        errBox.textContent = (d && d.error) || 'Could not save that change.';
        errBox.style.display = 'block';
      }
    }).catch(() => {
      cb.checked = !enabled;
      errBox.textContent = 'Network error — please try again.';
      errBox.style.display = 'block';
    }).finally(() => { cb.disabled = false; });
  });
});
</script>
</body>
</html>
