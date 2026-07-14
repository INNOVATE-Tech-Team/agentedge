<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$db  = local_db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_dept') {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? '')));
        $name = trim($_POST['name'] ?? '');
        $ord  = (int)($_POST['sort_ord'] ?? 0);
        if ($slug && $name) {
            $db->prepare("INSERT OR IGNORE INTO support_departments (slug,name,sort_ord) VALUES (?,?,?)")
               ->execute([$slug, $name, $ord]);
            $msg = "Department \"$name\" added.";
        }
    } elseif ($action === 'del_dept') {
        $slug = $_POST['slug'] ?? '';
        $db->prepare("DELETE FROM support_departments WHERE slug=?")->execute([$slug]);
        $db->prepare("DELETE FROM support_department_staff WHERE dept_slug=?")->execute([$slug]);
        $msg = "Department removed.";
    } elseif ($action === 'assign_staff') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $slugs = $_POST['dept_slugs'] ?? [];
        if ($email) {
            $db->prepare("DELETE FROM support_department_staff WHERE email=?")->execute([$email]);
            $ins = $db->prepare("INSERT OR IGNORE INTO support_department_staff (dept_slug,email) VALUES (?,?)");
            foreach ($slugs as $s) $ins->execute([$s, $email]);
            $msg = "Routing updated for $email.";
        }
    } elseif ($action === 'revoke_staff') {
        $email = $_POST['email'] ?? '';
        $db->prepare("DELETE FROM support_department_staff WHERE email=?")->execute([$email]);
        $msg = "Routing removed for $email.";
    } elseif ($action === 'add_reply') {
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body'] ?? '');
        $ord   = (int)($_POST['sort_ord'] ?? 0);
        if ($title && $body) {
            $db->prepare("INSERT INTO support_canned_replies (title,body,sort_ord) VALUES (?,?,?)")->execute([$title, $body, $ord]);
            $msg = "Canned reply \"$title\" added.";
        }
    } elseif ($action === 'del_reply') {
        $db->prepare("DELETE FROM support_canned_replies WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        $msg = "Canned reply removed.";
    } elseif ($action === 'add_kb') {
        $title = trim($_POST['title'] ?? '');
        $url   = trim($_POST['url'] ?? '');
        $ord   = (int)($_POST['sort_ord'] ?? 0);
        if ($title && $url) {
            $db->prepare("INSERT INTO support_kb_links (title,url,sort_ord) VALUES (?,?,?)")->execute([$title, $url, $ord]);
            $msg = "KB link \"$title\" added.";
        }
    } elseif ($action === 'del_kb') {
        $db->prepare("DELETE FROM support_kb_links WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        $msg = "KB link removed.";
    } elseif ($action === 'add_issue_type') {
        $name = trim($_POST['name'] ?? '');
        $ord  = (int)($_POST['sort_ord'] ?? 0);
        if ($name) {
            $db->prepare("INSERT OR IGNORE INTO support_issue_types (name,sort_ord) VALUES (?,?)")->execute([$name, $ord]);
            $msg = "Issue type \"$name\" added.";
        }
    } elseif ($action === 'del_issue_type') {
        $db->prepare("DELETE FROM support_issue_types WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        $msg = "Issue type removed.";
    }
}

$depts = $db->query("SELECT * FROM support_departments ORDER BY sort_ord, name")->fetchAll(PDO::FETCH_ASSOC);

$staffAssignments = [];
foreach ($db->query("SELECT email, dept_slug FROM support_department_staff ORDER BY email")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $staffAssignments[$r['email']][] = $r['dept_slug'];
}

$replies    = $db->query("SELECT * FROM support_canned_replies ORDER BY sort_ord, title")->fetchAll(PDO::FETCH_ASSOC);
$kbLinks    = $db->query("SELECT * FROM support_kb_links ORDER BY sort_ord, title")->fetchAll(PDO::FETCH_ASSOC);
$issueTypes = $db->query("SELECT * FROM support_issue_types ORDER BY sort_ord, name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ticket Departments — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .vd-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px}
    .vd-card h3{margin:0 0 4px;font-size:15px;font-weight:700}
    .vd-card .vd-sub{margin:0 0 14px;font-size:12px;color:#888}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th{text-align:left;padding:6px 10px;border-bottom:2px solid #e5e7eb;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#888}
    td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top}
    tr:hover td{background:#f9faf8}
    .form-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
    .form-row input,.form-row select,.form-row textarea{padding:6px 10px;border:1px solid #ccc;border-radius:5px;font-size:13px;font-family:inherit}
    .form-row textarea{width:320px;min-height:60px;resize:vertical}
    .btn{padding:7px 14px;border-radius:5px;border:1px solid #ccc;background:#fff;cursor:pointer;font-size:13px}
    .btn-green{background:#82C112;border-color:#5b8e0d;color:#fff;font-weight:600}
    .btn-danger{border-color:#e74c3c;color:#e74c3c}
    .btn-danger:hover{background:#e74c3c;color:#fff}
    .msg{background:#f0fde8;border:1px solid #82C112;color:#3a6b00;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:13px}
    .dept-badge{display:inline-block;background:#e8f5d0;color:#3a6b00;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:600;margin:2px}
    label.fl{font-size:11px;font-weight:700;display:block;margin-bottom:3px}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_support_depts', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Ticket Departments</div>
      <div class="content-hello">Departments, staff routing, canned replies &amp; KB links for Support Tickets</div>
    </header>
    <main class="wrap">
      <?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

      <!-- Departments -->
      <div class="vd-card">
        <h3>Departments</h3>
        <p class="vd-sub">Shown to agents when they open a new ticket. Ticket routing below no longer controls email notifications — those always go to super admins only.</p>
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
                <form method="post" style="display:inline" onsubmit="return confirm('Remove this department? Tickets already using it keep the old slug.')">
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
            <div><label class="fl">Slug (lowercase, hyphens)</label>
              <input name="slug" required placeholder="e.g. finance"></div>
            <div><label class="fl">Name</label>
              <input name="name" required placeholder="Finance"></div>
            <div><label class="fl">Sort order</label>
              <input name="sort_ord" type="number" value="0" style="width:70px"></div>
            <button class="btn btn-green" type="submit">Add Department</button>
          </div>
        </form>
      </div>

      <!-- Issue types -->
      <div class="vd-card">
        <h3>Issue Types</h3>
        <p class="vd-sub">The second dropdown on the New Ticket form (Department &gt; Issue Type &gt; Details).</p>
        <?php if ($issueTypes): ?>
        <table>
          <thead><tr><th>Name</th><th>Sort</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($issueTypes as $it): ?>
            <tr>
              <td><?= htmlspecialchars($it['name']) ?></td>
              <td><?= (int)$it['sort_ord'] ?></td>
              <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Remove this issue type?')">
                  <input type="hidden" name="action" value="del_issue_type">
                  <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                  <button class="btn btn-danger" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?><p style="color:#aaa;font-size:13px">No issue types yet.</p><?php endif; ?>

        <form method="post" style="margin-top:16px">
          <input type="hidden" name="action" value="add_issue_type">
          <div class="form-row">
            <div><label class="fl">Name</label>
              <input name="name" required placeholder="e.g. Dotloop" style="width:220px"></div>
            <div><label class="fl">Sort order</label>
              <input name="sort_ord" type="number" value="0" style="width:70px"></div>
            <button class="btn btn-green" type="submit">Add Issue Type</button>
          </div>
        </form>
      </div>

      <!-- Staff routing -->
      <div class="vd-card">
        <h3>Route Staff to Departments</h3>
        <p class="vd-sub">This assignment is used for display purposes only. New ticket / reply emails always go to all super admins, regardless of routing below.</p>
        <form method="post">
          <input type="hidden" name="action" value="assign_staff">
          <div class="form-row">
            <div><label class="fl">Staff email</label>
              <input name="email" type="email" required placeholder="staff@innovateonline.com" style="width:240px"></div>
            <div><label class="fl">Departments (hold Ctrl for multiple)</label>
              <select name="dept_slugs[]" multiple size="<?= max(3, count($depts)) ?>" style="min-width:200px">
                <?php foreach ($depts as $d): ?>
                  <option value="<?= htmlspecialchars($d['slug']) ?>"><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-green" type="submit" style="align-self:flex-end">Save Routing</button>
          </div>
        </form>

        <?php if ($staffAssignments): ?>
        <table style="margin-top:16px">
          <thead><tr><th>Email</th><th>Departments</th><th></th></tr></thead>
          <tbody>
          <?php
          $deptNames = array_column($depts, 'name', 'slug');
          foreach ($staffAssignments as $em => $slugs): ?>
            <tr>
              <td><?= htmlspecialchars($em) ?></td>
              <td><?php foreach ($slugs as $s): ?><span class="dept-badge"><?= htmlspecialchars($deptNames[$s] ?? $s) ?></span><?php endforeach; ?></td>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="revoke_staff">
                  <input type="hidden" name="email" value="<?= htmlspecialchars($em) ?>">
                  <button class="btn btn-danger" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?><p style="color:#aaa;font-size:13px;margin-top:12px">No staff routed yet. Every ticket notifies all super admins regardless.</p><?php endif; ?>
      </div>

      <!-- Predefined replies -->
      <div class="vd-card">
        <h3>Predefined Replies</h3>
        <p class="vd-sub">Quick responses to FAQs — insertable from the reply box on a ticket.</p>
        <?php if ($replies): ?>
        <table>
          <thead><tr><th>Title</th><th>Body</th><th>Sort</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($replies as $r): ?>
            <tr>
              <td><strong><?= htmlspecialchars($r['title']) ?></strong></td>
              <td style="max-width:420px;white-space:pre-wrap"><?= htmlspecialchars($r['body']) ?></td>
              <td><?= (int)$r['sort_ord'] ?></td>
              <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Remove this canned reply?')">
                  <input type="hidden" name="action" value="del_reply">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-danger" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?><p style="color:#aaa;font-size:13px">No canned replies yet.</p><?php endif; ?>

        <form method="post" style="margin-top:16px">
          <input type="hidden" name="action" value="add_reply">
          <div class="form-row">
            <div><label class="fl">Title</label>
              <input name="title" required placeholder="e.g. Password reset steps" style="width:220px"></div>
            <div><label class="fl">Reply text</label>
              <textarea name="body" required placeholder="The full text to insert into the reply box…"></textarea></div>
            <div><label class="fl">Sort order</label>
              <input name="sort_ord" type="number" value="0" style="width:70px"></div>
            <button class="btn btn-green" type="submit">Add Reply</button>
          </div>
        </form>
      </div>

      <!-- KB links -->
      <div class="vd-card">
        <h3>Knowledge Base Links</h3>
        <p class="vd-sub">Links to KB articles for agent resources — insertable from the reply box on a ticket.</p>
        <?php if ($kbLinks): ?>
        <table>
          <thead><tr><th>Title</th><th>URL</th><th>Sort</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($kbLinks as $k): ?>
            <tr>
              <td><strong><?= htmlspecialchars($k['title']) ?></strong></td>
              <td><a href="<?= htmlspecialchars($k['url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($k['url']) ?></a></td>
              <td><?= (int)$k['sort_ord'] ?></td>
              <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Remove this KB link?')">
                  <input type="hidden" name="action" value="del_kb">
                  <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                  <button class="btn btn-danger" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?><p style="color:#aaa;font-size:13px">No KB links yet.</p><?php endif; ?>

        <form method="post" style="margin-top:16px">
          <input type="hidden" name="action" value="add_kb">
          <div class="form-row">
            <div><label class="fl">Title</label>
              <input name="title" required placeholder="e.g. How to reset MLS password" style="width:220px"></div>
            <div><label class="fl">URL</label>
              <input name="url" type="url" required placeholder="https://kb.innovateonline.com/…" style="width:320px"></div>
            <div><label class="fl">Sort order</label>
              <input name="sort_ord" type="number" value="0" style="width:70px"></div>
            <button class="btn btn-green" type="submit">Add Link</button>
          </div>
        </form>
      </div>

    </main>
  </div>
</div>
</body>
</html>
