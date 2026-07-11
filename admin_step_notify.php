<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/onboard_tools.php';
require_once __DIR__ . '/offboard_tools.php';
$agent = require_login();
if (!is_admin()) { header('Location: index.php'); exit; }

$db  = local_db();
$msg = '';

// step_id format: "onboard:tool_key" or "offboard:tool_key"
function step_catalog(): array {
    $steps = [];
    foreach (onboard_tools() as $t)  { $steps[] = ['id'=>"onboard:{$t['key']}",  'process'=>'onboard',  'key'=>$t['key'], 'label'=>$t['label'], 'group'=>'Onboarding']; }
    foreach (offboard_tools() as $t) { $steps[] = ['id'=>"offboard:{$t['key']}", 'process'=>'offboard', 'key'=>$t['key'], 'label'=>$t['label'], 'group'=>'Offboarding']; }
    return $steps;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_step') {
        $process = $_POST['process'] ?? '';
        $key     = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['step_key'] ?? '')));
        $label   = trim($_POST['label'] ?? '');
        $note    = trim($_POST['note'] ?? '');
        $ord     = (int)($_POST['sort_ord'] ?? 0);

        if (!in_array($process, ['onboard','offboard'], true)) {
            $msg = 'Invalid process.';
        } elseif ($key === '' || $label === '') {
            $msg = 'Key and label are required.';
        } else {
            $exists = $db->prepare("SELECT 1 FROM step_defs WHERE process=? AND step_key=?");
            $exists->execute([$process, $key]);
            if ($exists->fetch()) {
                $msg = "A step with key \"$key\" already exists for " . ($process === 'onboard' ? 'onboarding' : 'offboarding') . ".";
            } else {
                $db->prepare(
                    "INSERT INTO step_defs (process, step_key, label, note, is_auto, sort_ord) VALUES (?,?,?,?,0,?)"
                )->execute([$process, $key, $label, $note, $ord]);
                $msg = "Step \"$label\" added.";
            }
        }
    } elseif ($action === 'edit_step') {
        $id    = (int)($_POST['id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $note  = trim($_POST['note'] ?? '');
        $ord   = (int)($_POST['sort_ord'] ?? 0);
        if ($id && $label !== '') {
            $db->prepare("UPDATE step_defs SET label=?, note=?, sort_ord=? WHERE id=?")->execute([$label, $note, $ord, $id]);
            $msg = 'Step updated.';
        } else {
            $msg = 'Label is required.';
        }
    } elseif ($action === 'delete_step') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = $db->prepare("SELECT process, step_key, label FROM step_defs WHERE id=?");
        $row->execute([$id]);
        $sd = $row->fetch(PDO::FETCH_ASSOC);
        if ($sd) {
            $db->prepare("DELETE FROM step_defs WHERE id=?")->execute([$id]);
            $db->prepare("DELETE FROM step_notify_staff WHERE process=? AND step_key=?")->execute([$sd['process'], $sd['step_key']]);
            $msg = "Step \"{$sd['label']}\" deleted. Cases already in the queue keep it — only future cases stop getting it.";
        }
    } elseif ($action === 'assign_staff') {
        $email   = strtolower(trim($_POST['email'] ?? ''));
        $stepIds = $_POST['step_ids'] ?? [];
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $db->prepare("DELETE FROM step_notify_staff WHERE email=?")->execute([$email]);
            $ins = $db->prepare("INSERT OR IGNORE INTO step_notify_staff (process, step_key, email) VALUES (?,?,?)");
            foreach ($stepIds as $sid) {
                [$process, $key] = array_pad(explode(':', $sid, 2), 2, '');
                if ($process && $key) $ins->execute([$process, $key, $email]);
            }
            $msg = "Step notifications updated for $email.";
        } else {
            $msg = "Enter a valid staff email.";
        }
    } elseif ($action === 'revoke_staff') {
        $email = $_POST['email'] ?? '';
        $db->prepare("DELETE FROM step_notify_staff WHERE email=?")->execute([$email]);
        $msg = "Step notifications removed for $email.";
    }
}

$stepDefs = $db->query("SELECT * FROM step_defs ORDER BY process, sort_ord, id")->fetchAll(PDO::FETCH_ASSOC);
$stepDefsByProcess = ['onboard'=>[], 'offboard'=>[]];
foreach ($stepDefs as $sd) { $stepDefsByProcess[$sd['process']][] = $sd; }

$steps        = step_catalog();
$stepLabels   = array_column($steps, 'label', 'id');

$staffAssignments = [];
foreach ($db->query("SELECT process, step_key, email FROM step_notify_staff ORDER BY email")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $staffAssignments[$r['email']][] = "{$r['process']}:{$r['step_key']}";
}

$byStep = [];
foreach ($db->query("SELECT process, step_key, email FROM step_notify_staff ORDER BY email")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $byStep["{$r['process']}:{$r['step_key']}"][] = $r['email'];
}

// Staff/admin users to pick from — the same pool that can log in and act on these queues.
$staffList = $db->query("SELECT email FROM agent_roles WHERE role IN ('super_admin','staff') ORDER BY email")->fetchAll(PDO::FETCH_COLUMN);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Step Notifications — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .vd-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px}
    .vd-card h3{margin:0 0 4px;font-size:15px;font-weight:700}
    .vd-card h4{margin:18px 0 8px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#888}
    .vd-card .vd-sub{margin:0 0 14px;font-size:12px;color:#888}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th{text-align:left;padding:6px 10px;border-bottom:2px solid #e5e7eb;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#888}
    td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top}
    tr:hover td{background:#f9faf8}
    .form-row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end}
    .form-row select,.form-row input{padding:6px 10px;border:1px solid #ccc;border-radius:5px;font-size:13px;font-family:inherit}
    .btn{padding:7px 14px;border-radius:5px;border:1px solid #ccc;background:#fff;cursor:pointer;font-size:13px}
    .btn-green{background:#82C112;border-color:#5b8e0d;color:#fff;font-weight:600}
    .btn-danger{border-color:#e74c3c;color:#e74c3c}
    .btn-danger:hover{background:#e74c3c;color:#fff}
    .msg{background:#f0fde8;border:1px solid #82C112;color:#3a6b00;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:13px}
    .step-badge{display:inline-block;background:#e8f5d0;color:#3a6b00;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:600;margin:2px}
    .auto-badge{display:inline-block;background:#eef2ff;color:#3949ab;border-radius:4px;padding:2px 7px;font-size:11px;font-weight:600}
    label.fl{font-size:11px;font-weight:700;display:block;margin-bottom:3px}
    .step-row{display:flex;gap:8px;align-items:center;padding:8px 4px;border-bottom:1px solid #f3f4f6;flex-wrap:wrap}
    .step-row form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .step-row .edit-form{flex:1;min-width:0}
    .step-row input[name=sort_ord]{width:50px}
    .step-row input[name=label]{flex:1;min-width:150px}
    .step-row input[name=note]{flex:2;min-width:200px}
    .step-row code{min-width:120px;color:#666}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('admin_step_notify', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Step Notifications</div>
      <div class="content-hello">Manage onboarding &amp; offboarding steps and who gets emailed for each</div>
    </header>
    <main class="wrap">
      <?php if ($msg): ?><div class="msg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

      <div class="vd-card">
        <h3>Manage Steps</h3>
        <p class="vd-sub">
          The checklist agents go through during onboarding/offboarding. Editing here only affects
          <strong>future</strong> cases — agents already in a queue keep the steps they started with.
          Steps marked <span class="auto-badge">Auto via API</span> are wired to real provisioning code and can't be added freely — new steps are always manual checklist items.
        </p>

        <?php foreach (['onboard'=>'Onboarding', 'offboard'=>'Offboarding'] as $proc => $groupLabel): ?>
          <h4><?= $groupLabel ?></h4>
          <?php foreach ($stepDefsByProcess[$proc] as $sd): ?>
            <div class="step-row">
              <form class="edit-form" method="post">
                <input type="hidden" name="action" value="edit_step">
                <input type="hidden" name="id" value="<?= (int)$sd['id'] ?>">
                <input type="number" name="sort_ord" value="<?= (int)$sd['sort_ord'] ?>" title="Sort order">
                <code><?= htmlspecialchars($sd['step_key']) ?></code>
                <input name="label" value="<?= htmlspecialchars($sd['label']) ?>" required placeholder="Label">
                <input name="note" value="<?= htmlspecialchars($sd['note']) ?>" placeholder="Note (optional)">
                <?php if ($sd['is_auto']): ?><span class="auto-badge">Auto via API</span><?php endif; ?>
                <button class="btn" type="submit">Save</button>
              </form>
              <form method="post" onsubmit="return confirm('Delete this step? Cases already in the queue keep it — only future cases stop getting it.')">
                <input type="hidden" name="action" value="delete_step">
                <input type="hidden" name="id" value="<?= (int)$sd['id'] ?>">
                <button class="btn btn-danger" type="submit">Delete</button>
              </form>
            </div>
          <?php endforeach; ?>
          <?php if (!$stepDefsByProcess[$proc]): ?><p style="color:#aaa;font-size:13px">No steps defined.</p><?php endif; ?>
        <?php endforeach; ?>

        <form method="post" style="margin-top:16px">
          <input type="hidden" name="action" value="add_step">
          <div class="form-row">
            <div><label class="fl">Process</label>
              <select name="process" required>
                <option value="onboard">Onboarding</option>
                <option value="offboard">Offboarding</option>
              </select>
            </div>
            <div><label class="fl">Key (lowercase, no spaces)</label>
              <input name="step_key" required placeholder="e.g. background_check" style="width:180px"></div>
            <div><label class="fl">Label</label>
              <input name="label" required placeholder="e.g. Background Check" style="width:200px"></div>
            <div><label class="fl">Note</label>
              <input name="note" placeholder="Optional helper text" style="width:240px"></div>
            <div><label class="fl">Order</label>
              <input name="sort_ord" type="number" value="0" style="width:70px"></div>
            <button class="btn btn-green" type="submit">Add Step</button>
          </div>
        </form>
      </div>

      <div class="vd-card">
        <h3>Assign Staff to Steps</h3>
        <p class="vd-sub">
          Selected staff get an email when a case is created (listing their assigned steps),
          and again the moment each step becomes next up in the queue.
          <?php if (!$staffList): ?><br><strong>No staff/super_admin users found in Role Assignments yet.</strong><?php endif; ?>
        </p>
        <form method="post">
          <input type="hidden" name="action" value="assign_staff">
          <div class="form-row">
            <div><label class="fl">Staff member</label>
              <select name="email" required style="min-width:220px">
                <option value="">Select staff…</option>
                <?php foreach ($staffList as $em): ?>
                  <option value="<?= htmlspecialchars($em) ?>"><?= htmlspecialchars($em) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div><label class="fl">Steps (hold Ctrl for multiple)</label>
              <select name="step_ids[]" multiple size="12" style="min-width:280px">
                <?php $curGroup = null; foreach ($steps as $s): ?>
                  <?php if ($s['group'] !== $curGroup): if ($curGroup !== null) echo '</optgroup>'; echo '<optgroup label="'.htmlspecialchars($s['group']).'">'; $curGroup = $s['group']; endif; ?>
                  <option value="<?= htmlspecialchars($s['id']) ?>"><?= htmlspecialchars($s['label']) ?></option>
                <?php endforeach; if ($curGroup !== null) echo '</optgroup>'; ?>
              </select>
            </div>
            <button class="btn btn-green" type="submit" style="align-self:flex-end">Save</button>
          </div>
        </form>

        <?php if ($staffAssignments): ?>
        <table style="margin-top:16px">
          <thead><tr><th>Staff</th><th>Notified For</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($staffAssignments as $em => $ids): ?>
            <tr>
              <td><?= htmlspecialchars($em) ?></td>
              <td><?php foreach ($ids as $id): ?><span class="step-badge"><?= htmlspecialchars($stepLabels[$id] ?? $id) ?></span><?php endforeach; ?></td>
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
        <?php else: ?><p style="color:#aaa;font-size:13px;margin-top:12px">No step notifications configured yet — nobody gets emailed per step.</p><?php endif; ?>
      </div>

      <div class="vd-card">
        <h3>By Step</h3>
        <p class="vd-sub">Who's currently notified for each step, for quick auditing.</p>
        <table>
          <thead><tr><th>Process</th><th>Step</th><th>Notified Staff</th></tr></thead>
          <tbody>
          <?php foreach ($steps as $s): ?>
            <tr>
              <td><?= $s['group'] ?></td>
              <td><?= htmlspecialchars($s['label']) ?></td>
              <td>
                <?php if (!empty($byStep[$s['id']])): ?>
                  <?php foreach ($byStep[$s['id']] as $em): ?><span class="step-badge"><?= htmlspecialchars($em) ?></span><?php endforeach; ?>
                <?php else: ?><span style="color:#aaa">— none —</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </main>
  </div>
</div>
</body>
</html>
