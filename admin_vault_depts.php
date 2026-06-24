<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isSuperAdmin'])) {
    header('Location: index.php'); exit;
}

$db  = local_db();
$msg = '';

// Handle POST actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_dept') {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? '')));
        $name = trim($_POST['name'] ?? '');
        $ord  = (int)($_POST['sort_ord'] ?? 0);
        if ($slug && $name) {
            try {
                $db->prepare("INSERT OR IGNORE INTO vault_depts (slug,name,sort_ord) VALUES (?,?,?)")
                   ->execute([$slug, $name, $ord]);
                $msg = "Department \"$name\" added.";
            } catch (\Exception $e) { $msg = 'Error: ' . $e->getMessage(); }
        }
    } elseif ($action === 'del_dept') {
        $slug = $_POST['slug'] ?? '';
        $db->prepare("DELETE FROM vault_depts WHERE slug=?")->execute([$slug]);
        $db->prepare("DELETE FROM vault_user_depts WHERE dept_slug=?")->execute([$slug]);
        $msg = "Department removed.";
    } elseif ($action === 'assign') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $slugs = $_POST['dept_slugs'] ?? [];
        if ($email) {
            $db->prepare("DELETE FROM vault_user_depts WHERE email=?")->execute([$email]);
            $ins = $db->prepare("INSERT OR IGNORE INTO vault_user_depts (email,dept_slug) VALUES (?,?)");
            foreach ($slugs as $s) $ins->execute([$email, $s]);
            $msg = "Access updated for $email.";
        }
    } elseif ($action === 'revoke') {
        $email = $_POST['email'] ?? '';
        $db->prepare("DELETE FROM vault_user_depts WHERE email=?")->execute([$email]);
        $msg = "Access revoked for $email.";
    }
}

$depts = $db->query("SELECT * FROM vault_depts ORDER BY sort_ord, name")->fetchAll(PDO::FETCH_ASSOC);

// All assigned users: email → [dept_slugs]
$assignments = [];
foreach ($db->query("SELECT email, dept_slug FROM vault_user_depts ORDER BY email")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $assignments[$r['email']][] = $r['dept_slug'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Vault Departments — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .vd-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px}
    .vd-card h3{margin:0 0 14px;font-size:15px;font-weight:700}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th{text-align:left;padding:6px 10px;border-bottom:2px solid #e5e7eb;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#888}
    td{padding:8px 10px;border-bottom:1px solid #f3f4f6}
    tr:hover td{background:#f9faf8}
    .form-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
    .form-row input,.form-row select{padding:6px 10px;border:1px solid #ccc;border-radius:5px;font-size:13px}
    .btn{padding:7px 14px;border-radius:5px;border:1px solid #ccc;background:#fff;cursor:pointer;font-size:13px}
    .btn-green{background:#82C112;border-color:#5b8e0d;color:#fff;font-weight:600}
    .btn-danger{border-color:#e74c3c;color:#e74c3c}
    .btn-danger:hover{background:#e74c3c;color:#fff}
    .msg{background:#f0fde8;border:1px solid #82C112;color:#3a6b00;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:13px}
    .dept-badge{display:inline-block;background:#e8f5d0;color:#3a6b00;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:600;margin:2px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_vault_depts', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Vault Departments</div>
      <div class="content-hello">Manage dept access for The Vault</div>
    </header>
    <main class="wrap">
      <?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

      <!-- Department list + add -->
      <div class="vd-card">
        <h3>Departments</h3>
        <?php if ($depts): ?>
        <table>
          <thead><tr><th>Slug</th><th>Name</th><th>Sort</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($depts as $d): ?>
            <tr>
              <td><code><?= htmlspecialchars($d['slug']) ?></code></td>
              <td><?= htmlspecialchars($d['name']) ?></td>
              <td><?= (int)$d['sort_ord'] ?></td>
              <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Remove this department and revoke all its access?')">
                  <input type="hidden" name="action" value="del_dept">
                  <input type="hidden" name="slug" value="<?= htmlspecialchars($d['slug']) ?>">
                  <button class="btn btn-danger" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?><p style="color:#aaa;font-size:13px">No departments yet.</p><?php endif; ?>

        <form method="post" style="margin-top:16px">
          <input type="hidden" name="action" value="add_dept">
          <div class="form-row">
            <div><label style="font-size:11px;font-weight:700;display:block;margin-bottom:3px">Slug (lowercase, hyphens)</label>
              <input name="slug" required placeholder="e.g. finance"></div>
            <div><label style="font-size:11px;font-weight:700;display:block;margin-bottom:3px">Name</label>
              <input name="name" required placeholder="Finance"></div>
            <div><label style="font-size:11px;font-weight:700;display:block;margin-bottom:3px">Sort order</label>
              <input name="sort_ord" type="number" value="0" style="width:70px"></div>
            <button class="btn btn-green" type="submit">Add Department</button>
          </div>
        </form>
      </div>

      <!-- Assign user to departments -->
      <div class="vd-card">
        <h3>Assign User to Departments</h3>
        <p style="font-size:12px;color:#888;margin:0 0 12px">Users assigned to a department can see that department's folders in The Vault. Admins/super_admin always see everything.</p>
        <form method="post">
          <input type="hidden" name="action" value="assign">
          <div class="form-row">
            <div><label style="font-size:11px;font-weight:700;display:block;margin-bottom:3px">Email</label>
              <input name="email" type="email" required placeholder="user@innovateonline.com" style="width:240px"></div>
            <div><label style="font-size:11px;font-weight:700;display:block;margin-bottom:3px">Departments (hold Ctrl for multiple)</label>
              <select name="dept_slugs[]" multiple size="<?= max(3, count($depts)) ?>" style="min-width:200px">
                <?php foreach ($depts as $d): ?>
                  <option value="<?= htmlspecialchars($d['slug']) ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-green" type="submit" style="align-self:flex-end">Save Access</button>
          </div>
        </form>
      </div>

      <!-- Current assignments -->
      <div class="vd-card">
        <h3>Current Assignments</h3>
        <?php if ($assignments): ?>
        <table>
          <thead><tr><th>Email</th><th>Departments</th><th></th></tr></thead>
          <tbody>
          <?php
          $deptNames = array_column($depts, 'name', 'slug');
          foreach ($assignments as $em => $slugs): ?>
            <tr>
              <td><?= htmlspecialchars($em) ?></td>
              <td><?php foreach ($slugs as $s): ?><span class="dept-badge"><?= htmlspecialchars($deptNames[$s] ?? $s) ?></span><?php endforeach; ?></td>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="revoke">
                  <input type="hidden" name="email" value="<?= htmlspecialchars($em) ?>">
                  <button class="btn btn-danger" type="submit">Revoke All</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?><p style="color:#aaa;font-size:13px">No assignments yet.</p><?php endif; ?>
      </div>

    </main>
  </div>
</div>
</body>
</html>
