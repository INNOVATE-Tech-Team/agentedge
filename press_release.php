<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$perms = current_perms();
if (empty($perms['isAdmin'])) { header('Location: index.php'); exit; }

$agentName  = htmlspecialchars($agent['name']  ?? '');
$agentEmail = htmlspecialchars($agent['email'] ?? '');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// ── Handle contact CRUD ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) die('Invalid CSRF token.');

    $db     = local_db();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_contact') {
        $category = trim($_POST['category'] ?? '');
        $outlet   = trim($_POST['outlet'] ?? '');
        $beat     = trim($_POST['beat'] ?? '');
        $how      = trim($_POST['how'] ?? '');
        $note     = trim($_POST['note'] ?? '');
        $state    = strtoupper(trim($_POST['state'] ?? ''));
        if ($category && $outlet) {
            $s = $db->prepare("SELECT COUNT(*), COALESCE(MAX(sort_ord),0) FROM press_contacts WHERE category=?");
            $s->execute([$category]);
            [$catCount, $catMax] = $s->fetch(PDO::FETCH_NUM);
            $ord = $catCount > 0
                ? $catMax + 10
                : (int)$db->query("SELECT COALESCE(MAX(sort_ord),0)+100 FROM press_contacts")->fetchColumn();
            $db->prepare("INSERT INTO press_contacts (category,outlet,beat,how,note,sort_ord,state) VALUES (?,?,?,?,?,?,?)")
               ->execute([$category,$outlet,$beat,$how,$note,$ord,$state]);
        }
    } elseif ($action === 'update_contact') {
        $id       = (int)($_POST['id'] ?? 0);
        $category = trim($_POST['category'] ?? '');
        $outlet   = trim($_POST['outlet'] ?? '');
        $beat     = trim($_POST['beat'] ?? '');
        $how      = trim($_POST['how'] ?? '');
        $note     = trim($_POST['note'] ?? '');
        $state    = strtoupper(trim($_POST['state'] ?? ''));
        if ($id && $category && $outlet) {
            $db->prepare("UPDATE press_contacts SET category=?,outlet=?,beat=?,how=?,note=?,state=? WHERE id=?")
               ->execute([$category,$outlet,$beat,$how,$note,$state,$id]);
        }
    } elseif ($action === 'delete_contact') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $db->prepare("DELETE FROM press_contacts WHERE id=?")->execute([$id]);
    }

    header('Location: press_release.php?tab=contacts');
    exit;
}

$contactRows = local_db()->query("SELECT * FROM press_contacts ORDER BY sort_ord, id")->fetchAll(PDO::FETCH_ASSOC);
$contactsByCategory = [];
foreach ($contactRows as $row) { $contactsByCategory[$row['category']][] = $row; }
$contactStates = array_values(array_unique(array_filter(array_column($contactRows, 'state'))));
sort($contactStates);

$activeTab = ($_GET['tab'] ?? '') === 'contacts' ? 'contacts' : 'builder';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Press Release Studio — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .wrap { max-width: none; padding: 20px 28px 40px; }

    .pr-tabs { display: flex; gap: 8px; margin-bottom: 20px; }
    .pr-tab  { padding: 7px 18px; border-radius: 6px; border: 1px solid #ddd; background: #fff; font-size: 12px; font-weight: 700; cursor: pointer; color: #888; }
    .pr-tab.active { background: #82C112; color: #000; border-color: #82C112; }

    /* ── Builder split ── */
    .pr-split {
      display: grid;
      grid-template-columns: 390px 1fr;
      border: 1px solid #e4e4e4;
      border-radius: 10px;
      overflow: hidden;
      height: calc(100vh - 195px);
      min-height: 520px;
    }
    .pr-form {
      background: #fff;
      border-right: 1px solid #e4e4e4;
      overflow-y: auto;
      padding: 20px 22px 36px;
    }
    .pr-preview-pane {
      background: #F8F7F5;
      overflow-y: auto;
      padding: 36px 52px;
    }

    /* ── Form ── */
    .pr-sec { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .17em; color: #82C112; padding-bottom: 8px; border-bottom: 1px solid #e6f5cc; margin: 18px 0 14px; }
    .pr-sec:first-child { margin-top: 0; }
    .pr-f { margin-bottom: 11px; }
    .pr-f label { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #aaa; margin-bottom: 4px; }
    .pr-f input, .pr-f textarea {
      width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px;
      font-size: 13px; box-sizing: border-box; font-family: inherit; color: #222;
    }
    .pr-f input:focus, .pr-f textarea:focus { outline: 2px solid #82C112; border-color: #82C112; }
    .pr-f textarea { min-height: 68px; resize: vertical; line-height: 1.5; }
    .pr-g2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .fact-row { display: flex; align-items: center; gap: 8px; margin-bottom: 7px; }
    .fact-bullet { color: #82C112; font-size: 15px; flex-shrink: 0; line-height: 1; }
    .pr-btns { display: flex; gap: 8px; margin-top: 8px; }
    .btn-pr-primary { flex: 1; padding: 10px; border-radius: 6px; border: none; cursor: pointer; background: #82C112; color: #000; font-size: 12px; font-weight: 800; }
    .btn-pr-primary:hover { background: #5b8e0d; color: #fff; }
    .btn-pr-sec { padding: 10px 14px; border-radius: 6px; cursor: pointer; background: #f4f4f4; color: #555; border: 1px solid #ddd; font-size: 12px; font-weight: 700; }
    .btn-pr-sec:hover { background: #e8e8e8; }

    /* ── PR Document Preview ── */
    .pr-doc { max-width: 600px; margin: 0 auto; font-family: Georgia, serif; color: #111; }
    .pr-doc-top { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2.5px solid #111; padding-bottom: 16px; margin-bottom: 20px; }
    .pr-org-name { font-family: -apple-system, sans-serif; font-weight: 800; font-size: 16px; letter-spacing: -.02em; }
    .pr-org-sub  { font-family: monospace; font-size: 8px; letter-spacing: .15em; text-transform: uppercase; color: #aaa; margin-top: 2px; }
    .pr-flag     { text-align: right; }
    .pr-imm      { font-family: monospace; font-size: 9px; letter-spacing: .13em; text-transform: uppercase; color: #82C112; font-weight: 700; }
    .pr-dstr     { font-size: 11.5px; color: #999; margin-top: 4px; }
    .pr-cb  { background: #EFEDE9; border-left: 3px solid #82C112; padding: 10px 14px; margin-bottom: 22px; font-family: -apple-system, sans-serif; }
    .pr-cb-lbl  { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: .12em; color: #aaa; margin-bottom: 5px; }
    .pr-cb-name { font-size: 12px; font-weight: 700; color: #111; }
    .pr-cb-info { font-family: monospace; font-size: 11px; color: #777; margin-top: 2px; }
    .pr-hl  { font-size: 26px; font-weight: 700; line-height: 1.25; margin: 0 0 10px; }
    .pr-sl  { font-size: 15px; font-style: italic; color: #555; margin: 0 0 20px; line-height: 1.45; }
    .pr-dl  { font-family: -apple-system, sans-serif; font-size: 12px; font-weight: 700; margin-bottom: 12px; }
    .pr-body p { font-size: 14.5px; line-height: 1.78; color: #1a1a1a; margin: 0 0 14px; }
    .pr-ph  { color: #ccc; font-style: italic; }
    .pr-ul  { list-style: none; padding: 0; margin: 14px 0; }
    .pr-ul li { font-size: 14.5px; line-height: 1.65; color: #1a1a1a; padding: 7px 0 7px 20px; border-bottom: 1px solid #E0DDD8; position: relative; }
    .pr-ul li:first-child { border-top: 1px solid #E0DDD8; }
    .pr-ul li::before { content: '▸'; color: #82C112; position: absolute; left: 0; top: 9px; font-size: 11px; }
    .pr-qb  { border-left: 4px solid #82C112; padding: 12px 20px; background: #F2F0EC; margin: 22px 0; }
    .pr-qt  { font-size: 16px; font-style: italic; line-height: 1.65; margin: 0 0 10px; }
    .pr-qa  { font-family: -apple-system, sans-serif; font-size: 11px; font-weight: 700; color: #777; text-transform: uppercase; letter-spacing: .05em; }
    .pr-bp  { margin-top: 28px; padding-top: 16px; border-top: 1px solid #ccc; }
    .pr-bpl { font-family: monospace; font-size: 8px; letter-spacing: .13em; text-transform: uppercase; color: #ccc; margin-bottom: 7px; }
    .pr-bp p { font-family: -apple-system, sans-serif; font-size: 11.5px; line-height: 1.68; color: #999; }
    .pr-end { text-align: center; margin-top: 26px; font-family: monospace; font-size: 13px; color: #ccc; letter-spacing: .1em; }

    /* ── Contacts tab ── */
    .ct-note { font-size: 13px; color: #777; margin-bottom: 22px; line-height: 1.65; max-width: 600px; }
    .ct-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .ct-cat  { background: #fff; border: 1px solid #e8e8e8; border-radius: 8px; padding: 18px; }
    .ct-ch   { font-size: 9px; font-weight: 800; color: #82C112; text-transform: uppercase; letter-spacing: .17em; padding-bottom: 10px; border-bottom: 1px solid #f2f2f2; margin-bottom: 12px; }
    .ct-e    { padding-bottom: 11px; margin-bottom: 11px; border-bottom: 1px solid #f5f5f5; }
    .ct-e:last-child { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
    .ct-out  { font-size: 13px; font-weight: 700; color: #222; margin-bottom: 2px; }
    .ct-beat { font-size: 11px; color: #aaa; margin-bottom: 4px; }
    .ct-how  { font-family: monospace; font-size: 10.5px; color: #5b8e0d; }
    .ct-note2{ font-size: 10px; color: #bbb; font-style: italic; margin-top: 3px; line-height: 1.5; }
    .ct-out  { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
    .ct-actions { display: flex; gap: 8px; flex-shrink: 0; }
    .ct-actions a, .ct-del { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; cursor: pointer; text-decoration: none; }
    .ct-actions a { color: #999; }
    .ct-actions a:hover { color: #5b8e0d; }
    .ct-del { color: #c0392b; background: none; border: none; padding: 0; font-family: inherit; }
    .ct-del:hover { text-decoration: underline; }
    .ct-edit-form { padding: 11px 0; margin-bottom: 11px; border-bottom: 1px solid #f5f5f5; }
    .ct-edit-form input, .ct-edit-form textarea { width: 100%; padding: 6px 8px; margin-bottom: 6px; border: 1px solid #ddd; border-radius: 5px; font-size: 12px; box-sizing: border-box; font-family: inherit; }
    .ct-edit-form textarea { min-height: 44px; resize: vertical; }
    .ct-edit-btns { display: flex; gap: 6px; }
    .ct-edit-btns button { padding: 6px 12px; font-size: 11px; }
    .ct-add { max-width: 640px; margin-top: 28px; background: #fff; border: 1px solid #e8e8e8; border-radius: 8px; padding: 18px; }
    .ct-add-h { font-size: 9px; font-weight: 800; color: #82C112; text-transform: uppercase; letter-spacing: .17em; padding-bottom: 10px; border-bottom: 1px solid #f2f2f2; margin-bottom: 14px; }
    .ct-add-form { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .ct-add-form input { padding: 8px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; box-sizing: border-box; font-family: inherit; }
    .ct-add-form button { grid-column: 1 / -1; justify-self: start; }

    @media (max-width: 960px) {
      .pr-split { grid-template-columns: 1fr; }
      .pr-preview-pane { display: none; }
      .ct-grid { grid-template-columns: 1fr; }
      .ct-add-form { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('press_release', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Press Release Studio</div>
    </header>
    <main class="wrap">

      <div class="pr-tabs">
        <button class="pr-tab<?= $activeTab === 'builder'  ? ' active' : '' ?>" onclick="prShowTab('builder',this)">Builder</button>
        <button class="pr-tab<?= $activeTab === 'contacts' ? ' active' : '' ?>" onclick="prShowTab('contacts',this)">Who to Pitch</button>
      </div>

      <!-- ══ BUILDER ══ -->
      <div id="pr-builder" style="<?= $activeTab === 'contacts' ? 'display:none' : '' ?>">
        <div class="pr-split">

          <!-- Form -->
          <div class="pr-form">

            <div class="pr-sec">Release Details</div>
            <div class="pr-g2">
              <div class="pr-f"><label>Date</label><input type="date" id="prDate"></div>
              <div class="pr-f"><label>City, State</label><input type="text" id="prCity" value="Myrtle Beach, SC"></div>
            </div>
            <div class="pr-f"><label>Headline</label><input type="text" id="prHl" placeholder="INNOVATE Expands Into Raleigh Market"></div>
            <div class="pr-f">
              <label>Subheadline <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#ccc">(optional)</span></label>
              <input type="text" id="prSl" placeholder="Brokerage now serves 15 states with 300+ agents">
            </div>

            <div class="pr-sec">Body</div>
            <div class="pr-f"><label>Opening Paragraph</label><textarea id="prOpen" placeholder="What happened, where, and why it matters."></textarea></div>
            <div class="pr-f">
              <label>Key Facts <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#ccc">(up to 4)</span></label>
              <div class="fact-row"><span class="fact-bullet">▸</span><input type="text" class="pr-fact" placeholder="300+ agents across 15 Market Centers"></div>
              <div class="fact-row"><span class="fact-bullet">▸</span><input type="text" class="pr-fact" placeholder="Operating in 14 states"></div>
              <div class="fact-row"><span class="fact-bullet">▸</span><input type="text" class="pr-fact" placeholder=""></div>
              <div class="fact-row"><span class="fact-bullet">▸</span><input type="text" class="pr-fact" placeholder=""></div>
            </div>
            <div class="pr-f"><label>Additional Context</label><textarea id="prBody" placeholder="Background, market context, what this means for agents and clients…"></textarea></div>

            <div class="pr-sec">Quote</div>
            <div class="pr-f"><label>Quote Text</label><textarea id="prQt" placeholder="We're building something agents have never seen before…" style="min-height:80px"></textarea></div>
            <div class="pr-g2">
              <div class="pr-f"><label>Name</label><input type="text" id="prQn" value="Darren Woodard"></div>
              <div class="pr-f"><label>Title</label><input type="text" id="prQtl" value="CEO, INNOVATE"></div>
            </div>
            <div class="pr-f"><label>Closing / Call to Action</label><textarea id="prClose" placeholder="Agents interested in INNOVATE can visit growwithinnovate.com…"></textarea></div>

            <div class="pr-sec">Media Contact</div>
            <div class="pr-f"><label>Contact Name</label><input type="text" id="prCn" value="<?= $agentName ?>"></div>
            <div class="pr-g2">
              <div class="pr-f"><label>Email</label><input type="email" id="prCe" value="<?= $agentEmail ?>"></div>
              <div class="pr-f"><label>Phone</label><input type="tel" id="prCp" placeholder="(843) 000-0000"></div>
            </div>

            <div class="pr-btns">
              <button class="btn-pr-primary" id="prCopyBtn" onclick="prCopy()">Copy Release</button>
              <button class="btn-pr-sec" onclick="prDownload()">Download .txt</button>
            </div>
          </div><!-- /form -->

          <!-- Live preview -->
          <div class="pr-preview-pane">
            <div class="pr-doc" id="prPreview"></div>
          </div>

        </div><!-- /split -->
      </div><!-- /builder -->

      <!-- ══ CONTACTS ══ -->
      <div id="pr-contacts" style="<?= $activeTab === 'contacts' ? '' : 'display:none' ?>">
        <p class="ct-note">Reporters, editors, and wire services most likely to cover INNOVATE news — organized by beat. Lead with the outlet that fits your story type; don't blast all of them at once.</p>

        <?php if ($contactStates): ?>
        <div class="pr-tabs" id="ct-state-tabs">
          <button class="pr-tab active" data-state="" onclick="ctFilterState('',this)">All States</button>
<?php foreach ($contactStates as $st): ?>
          <button class="pr-tab" data-state="<?= h($st) ?>" onclick="ctFilterState('<?= h($st) ?>',this)"><?= h($st) ?></button>
<?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="ct-grid">
<?php foreach ($contactsByCategory as $category => $entries): ?>
          <div class="ct-cat">
            <div class="ct-ch"><?= h($category) ?></div>
<?php foreach ($entries as $c): $cid = (int)$c['id']; ?>
            <div class="ct-e" id="ct-view-<?= $cid ?>" data-state="<?= h($c['state']) ?>">
              <div class="ct-out">
                <span><?= h($c['outlet']) ?></span>
                <div class="ct-actions">
                  <a onclick="ctToggle(<?= $cid ?>)">Edit</a>
                  <form method="post" style="display:inline" onsubmit="return confirm('Delete this contact?');">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="delete_contact">
                    <input type="hidden" name="id" value="<?= $cid ?>">
                    <button type="submit" class="ct-del">Delete</button>
                  </form>
                </div>
              </div>
<?php if ($c['beat'] !== ''): ?>              <div class="ct-beat"><?= h($c['beat']) ?></div>
<?php endif; ?>
<?php if ($c['how'] !== ''): ?>              <div class="ct-how"><?= h($c['how']) ?></div>
<?php endif; ?>
<?php if ($c['note'] !== ''): ?>              <div class="ct-note2"><?= h($c['note']) ?></div>
<?php endif; ?>
            </div>
            <form class="ct-edit-form" id="ct-edit-<?= $cid ?>" method="post" style="display:none" data-state="<?= h($c['state']) ?>">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="action" value="update_contact">
              <input type="hidden" name="id" value="<?= $cid ?>">
              <input type="text" name="category" value="<?= h($c['category']) ?>" placeholder="Category" required>
              <input type="text" name="outlet" value="<?= h($c['outlet']) ?>" placeholder="Outlet" required>
              <input type="text" name="beat" value="<?= h($c['beat']) ?>" placeholder="Beat">
              <input type="text" name="how" value="<?= h($c['how']) ?>" placeholder="Email / how to pitch">
              <input type="text" name="state" value="<?= h($c['state']) ?>" placeholder="State (optional, e.g. PA) — blank = national" list="ct-states">
              <textarea name="note" placeholder="Note"><?= h($c['note']) ?></textarea>
              <div class="ct-edit-btns">
                <button type="submit" class="btn-pr-primary">Save</button>
                <button type="button" class="btn-pr-sec" onclick="ctToggle(<?= $cid ?>)">Cancel</button>
              </div>
            </form>
<?php endforeach; ?>
          </div>
<?php endforeach; ?>
        </div><!-- /ct-grid -->

        <div class="ct-add">
          <div class="ct-add-h">Add a Contact</div>
          <form class="ct-add-form" method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="add_contact">
            <input type="text" name="category" list="ct-categories" placeholder="Category" required>
            <datalist id="ct-categories">
<?php foreach (array_keys($contactsByCategory) as $cat): ?>              <option value="<?= h($cat) ?>">
<?php endforeach; ?>
            </datalist>
            <input type="text" name="outlet" placeholder="Outlet name" required>
            <input type="text" name="beat" placeholder="Beat">
            <input type="text" name="how" placeholder="Email / how to pitch">
            <input type="text" name="state" placeholder="State (optional, e.g. PA) — blank = national" list="ct-states">
            <datalist id="ct-states">
<?php foreach ($contactStates as $st): ?>              <option value="<?= h($st) ?>">
<?php endforeach; ?>
            </datalist>
            <input type="text" name="note" placeholder="Note (optional)">
            <button type="submit" class="btn-pr-primary">Add Contact</button>
          </form>
        </div>
      </div><!-- /contacts -->

    </main>
  </div>
</div>

<script>
const BOILERPLATE = 'INNOVATE is a full-service real estate brokerage operating across 14 states with more than 300 agents and 15 Market Centers. Built on the belief that agents deserve better technology, deeper support, and a genuine path to financial independence, INNOVATE combines a proprietary CRM and lead platform with hands-on mentorship and a competitive compensation model. Headquartered in Myrtle Beach, SC, INNOVATE serves buyers and sellers from the Carolinas to Texas. Learn more at growwithinnovate.com.';

const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];

function fmtDate(d) {
  if (!d) return '';
  const p = d.split('-');
  return MONTHS[parseInt(p[1])-1] + ' ' + parseInt(p[2]) + ', ' + p[0];
}

function g(id) { return (document.getElementById(id)||{}).value || ''; }

function buildPlainText() {
  const facts = [...document.querySelectorAll('.pr-fact')].map(i=>i.value.trim()).filter(Boolean);
  const lines = [];
  lines.push('FOR IMMEDIATE RELEASE');
  lines.push(fmtDate(g('prDate')) || fmtDate(new Date().toISOString().slice(0,10)));
  lines.push('');
  const cn=g('prCn'), ce=g('prCe'), cp=g('prCp');
  if (cn||ce||cp) {
    lines.push('MEDIA CONTACT');
    if (cn) lines.push(cn); if (ce) lines.push(ce); if (cp) lines.push(cp);
    lines.push('');
  }
  lines.push((g('prHl')||'YOUR HEADLINE HERE').toUpperCase());
  if (g('prSl')) lines.push(g('prSl'));
  lines.push('');
  lines.push((g('prCity')||'Myrtle Beach, SC').toUpperCase() + ' —');
  if (g('prOpen')) lines.push(g('prOpen'));
  lines.push('');
  if (facts.length) { facts.forEach(f=>lines.push('  * '+f)); lines.push(''); }
  if (g('prQt')) {
    lines.push('"' + g('prQt') + '"');
    const attr=[g('prQn'),g('prQtl')].filter(Boolean).join(', ');
    if (attr) lines.push('-- '+attr);
    lines.push('');
  }
  if (g('prBody'))  { lines.push(g('prBody'));  lines.push(''); }
  if (g('prClose')) { lines.push(g('prClose')); lines.push(''); }
  lines.push('ABOUT INNOVATE');
  lines.push(BOILERPLATE);
  lines.push(''); lines.push('###');
  return lines.join('\n');
}

function renderPreview() {
  const hl   = g('prHl');
  const sl   = g('prSl');
  const city = (g('prCity')||'Myrtle Beach, SC').toUpperCase();
  const date = fmtDate(g('prDate')) || fmtDate(new Date().toISOString().slice(0,10));
  const open = g('prOpen');
  const body = g('prBody');
  const close= g('prClose');
  const qt   = g('prQt');
  const qn   = g('prQn');
  const qtl  = g('prQtl');
  const cn   = g('prCn');
  const ce   = g('prCe');
  const cp   = g('prCp');
  const facts = [...document.querySelectorAll('.pr-fact')].map(i=>i.value.trim()).filter(Boolean);

  const esc = s => s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  let html = `
    <div class="pr-doc-top">
      <div>
        <div class="pr-org-name">INNOVATE</div>
        <div class="pr-org-sub">Real Estate Brokerage</div>
      </div>
      <div class="pr-flag">
        <div class="pr-imm">For Immediate Release</div>
        <div class="pr-dstr">${esc(date)}</div>
      </div>
    </div>`;

  if (cn||ce||cp) {
    html += `<div class="pr-cb"><div class="pr-cb-lbl">Media Contact</div>`;
    if (cn) html += `<div class="pr-cb-name">${esc(cn)}</div>`;
    const info = [ce,cp].filter(Boolean).map(esc).join('  ·  ');
    if (info) html += `<div class="pr-cb-info">${info}</div>`;
    html += `</div>`;
  }

  html += `<h1 class="pr-hl">${hl ? esc(hl) : '<span class="pr-ph">Your headline will appear here…</span>'}</h1>`;
  if (sl) html += `<p class="pr-sl">${esc(sl)}</p>`;
  html += `<p class="pr-dl">${esc(city)} —</p>`;

  html += '<div class="pr-body">';
  if (open) {
    html += `<p>${esc(open)}</p>`;
  } else {
    html += `<p class="pr-ph">Opening paragraph will appear here…</p>`;
  }

  if (facts.length) {
    html += '<ul class="pr-ul">';
    facts.forEach(f => html += `<li>${esc(f)}</li>`);
    html += '</ul>';
  }

  if (qt) {
    const attr = [qn,qtl].filter(Boolean).map(esc).join(', ');
    html += `<div class="pr-qb"><p class="pr-qt">&ldquo;${esc(qt)}&rdquo;</p>${attr ? `<div class="pr-qa">${attr}</div>` : ''}</div>`;
  }

  if (body)  html += `<p>${esc(body)}</p>`;
  if (close) html += `<p>${esc(close)}</p>`;
  html += '</div>';

  html += `<div class="pr-bp"><div class="pr-bpl">About INNOVATE</div><p>${esc(BOILERPLATE)}</p></div>`;
  html += `<div class="pr-end">###</div>`;

  document.getElementById('prPreview').innerHTML = html;
}

function prCopy() {
  navigator.clipboard.writeText(buildPlainText()).then(() => {
    const btn = document.getElementById('prCopyBtn');
    btn.textContent = '✓ Copied!';
    setTimeout(() => btn.textContent = 'Copy Release', 2200);
  }).catch(() => {});
}

function prDownload() {
  const slug = (g('prHl')||'press-release').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,50);
  const blob = new Blob([buildPlainText()], {type:'text/plain'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'INNOVATE-' + slug + '.txt';
  a.click();
}

function prShowTab(tab, btn) {
  document.getElementById('pr-builder').style.display  = tab === 'builder'  ? '' : 'none';
  document.getElementById('pr-contacts').style.display = tab === 'contacts' ? '' : 'none';
  document.querySelectorAll('.pr-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

function ctToggle(id) {
  const view = document.getElementById('ct-view-' + id);
  const edit = document.getElementById('ct-edit-' + id);
  const editing = edit.style.display !== 'none';
  view.style.display = editing ? '' : 'none';
  edit.style.display = editing ? 'none' : '';
}

// Filters contact cards by state. A specific state shows that state's contacts
// plus national ones (blank state) — a national outlet is relevant everywhere.
// "All States" shows everything, including every state-specific contact.
function ctFilterState(state, btn) {
  document.querySelectorAll('#ct-state-tabs .pr-tab').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');

  const matches = el => state === '' || (el.dataset.state || '') === '' || el.dataset.state === state;

  document.querySelectorAll('.ct-e').forEach(el => { el.style.display = matches(el) ? '' : 'none'; });
  document.querySelectorAll('.ct-edit-form').forEach(el => { if (!matches(el)) el.style.display = 'none'; });

  document.querySelectorAll('.ct-cat').forEach(col => {
    const anyVisible = [...col.querySelectorAll('.ct-e')].some(e => e.style.display !== 'none');
    col.style.display = anyVisible ? '' : 'none';
  });
}

// Set today's date and wire live preview
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('prDate').value = new Date().toISOString().slice(0,10);
  document.querySelectorAll('#pr-builder input, #pr-builder textarea').forEach(el => {
    el.addEventListener('input', renderPreview);
  });
  renderPreview();
});
</script>
</body>
</html>
