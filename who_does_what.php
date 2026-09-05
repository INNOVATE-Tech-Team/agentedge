<?php
// Agent-facing team directory — task-first routing ("who handles commission
// questions?"). Content is entirely managed in Back Office → Technology →
// Who Does What (admin_who_does_what.php / table team_directory); this page
// only renders it. See lib/who_does_what.php for the shared data helpers.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/who_does_what.php';

$agent = require_login();
if (!wdw_is_available_to_current_user()) { header('Location: index.php'); exit; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

$rows = team_directory_list_active();
$people = array_map(function (array $r) {
    $groups = wdw_groups_decode($r['group_label']);
    return [
        'name'       => $r['name'],
        'title'      => $r['title'],
        'groups'     => $groups,
        'tags'       => wdw_tags_decode($r['tags']),
        'handles'    => $r['handles'],
        'email'      => $r['email'],
        'phone'      => $r['phone'],
        'bookingUrl' => $r['booking_url'],
        'photoUrl'   => wdw_photo_url($r),
        'initials'   => wdw_initials($r['name']),
        'accent'     => wdw_accent_class($groups[0] ?? ''),
    ];
}, $rows);

// WDW_PUBLIC_TAGS is the full set of tags *eligible* for a public quick-filter
// button; a tag only actually gets a button when some active person currently
// carries it, so agents never see a filter guaranteed to return zero results.
// Recomputed from live directory data on every request, so assigning/removing
// the tag in Back Office flips a button's visibility automatically.
$activeTags = [];
foreach ($people as $p) {
    foreach ($p['tags'] as $t) $activeTags[$t] = true;
}
$visiblePublicTags = array_values(array_filter(WDW_PUBLIC_TAGS, function ($t) use ($activeTags) {
    return isset($activeTags[$t]);
}));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Who Does What — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .wdw-wrap { max-width: 980px; margin: 0 auto; }
    .wdw-header { max-width: 700px; margin: 0 auto 32px; text-align: center; }
    .wdw-eyebrow {
      font-size: 11px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase;
      color: var(--green-d); margin-bottom: 12px;
    }
    .wdw-h1 { margin: 0 0 20px; font-size: 34px; font-weight: 800; letter-spacing: -.02em; color: var(--ink); line-height: 1.1; }

    .wdw-groups { display: flex; justify-content: center; gap: 10px; margin-bottom: 22px; flex-wrap: wrap; }
    .wdw-group-btn {
      font: inherit; font-weight: 700; font-size: 13px; padding: 12px 20px; border-radius: 9px;
      cursor: pointer; background: var(--bg); color: var(--ink); border: 1px solid var(--border);
    }
    .wdw-group-btn:hover { background: var(--green); border-color: var(--green); color: #111; }
    .wdw-group-btn[aria-pressed="true"] { background: var(--ink); color: #fff; border-color: var(--ink); }
    .wdw-group-btn:focus-visible { outline: 2px solid var(--green-d); outline-offset: 2px; }

    .wdw-search {
      display: flex; align-items: center; gap: 12px; background: #fff; border: 2px solid var(--ink);
      border-radius: 12px; padding: 14px 18px; box-shadow: 0 2px 0 rgba(17,17,17,.9);
    }
    .wdw-search svg { flex: none; color: var(--ink); }
    .wdw-search input {
      flex: 1; border: 0; outline: 0; background: transparent; font: inherit; font-size: 16px;
      font-weight: 500; color: var(--ink); padding: 2px 0;
    }
    .wdw-search:focus-within { box-shadow: 0 2px 0 rgba(17,17,17,.9), 0 0 0 3px rgba(130,193,18,.3); }

    .wdw-tags { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
    .wdw-tag-btn {
      font: inherit; font-weight: 600; font-size: 12px; padding: 9px 14px; border-radius: 999px;
      cursor: pointer; background: var(--bg); color: var(--ink); border: 1px solid var(--border);
    }
    .wdw-tag-btn[aria-pressed="true"] { background: var(--ink); color: #fff; border-color: var(--ink); }
    .wdw-tag-btn:focus-visible { outline: 2px solid var(--green-d); outline-offset: 2px; }

    .wdw-results-head { display: flex; align-items: baseline; gap: 8px; margin: 36px 0 16px; flex-wrap: wrap; }
    .wdw-count { font-size: 12px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; color: var(--faint); }
    .wdw-context { font-size: 12px; color: var(--faint); }

    .wdw-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    @media (max-width: 780px) { .wdw-grid { grid-template-columns: 1fr; } }

    .wdw-card {
      background: var(--bg); border: 1px solid var(--border); border-left: 4px solid var(--border);
      border-radius: 12px; padding: 18px; display: flex; gap: 16px;
    }
    .wdw-accent-leadership { border-left-color: var(--green); }
    .wdw-accent-brokers    { border-left-color: var(--green-d); }
    .wdw-accent-admins     { border-left-color: #c7cac2; }

    .wdw-photo { width: 76px; height: 90px; flex: none; border-radius: 9px; overflow: hidden; }
    .wdw-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .wdw-photo-fallback {
      width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
      background: #dff0c4; color: var(--green-d); font-weight: 800; font-size: 20px;
    }

    .wdw-body { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
    .wdw-name { margin: 0; font-size: 17px; font-weight: 700; color: var(--ink); line-height: 1.2; }
    .wdw-meta { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--green-d); }
    .wdw-handles { margin: 3px 0 0; font-size: 13px; line-height: 1.5; color: var(--muted); }

    .wdw-contact { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 9px; }
    .wdw-pill {
      font-size: 11.5px; font-weight: 700; padding: 7px 9px; border-radius: 6px; text-decoration: none;
      display: inline-flex; align-items: center; gap: 5px;
    }
    .wdw-pill-email { background: #eef5e8; color: var(--green-d); }
    .wdw-pill-email:hover { background: #dfeecd; color: var(--green-d); }
    .wdw-pill-phone, .wdw-pill-book { background: #eceeea; color: var(--ink); }
    .wdw-pill-phone:hover, .wdw-pill-book:hover { background: #e0e2dd; color: var(--ink); }

    .wdw-empty {
      border: 1px dashed var(--border); border-radius: 12px; padding: 48px 24px; text-align: center;
    }
    .wdw-empty p:first-child { margin: 0 0 6px; font-size: 16px; font-weight: 700; color: var(--ink); }
    .wdw-empty p:last-child { margin: 0; font-size: 13px; color: var(--faint); }

    .wdw-sr-only {
      position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden;
      clip: rect(0,0,0,0); white-space: nowrap; border: 0;
    }

    @media (max-width: 760px) {
      .wdw-h1 { font-size: 26px; }
      .wdw-groups { gap: 8px; }
      .wdw-group-btn { flex: 1 1 auto; padding: 10px 12px; font-size: 12.5px; }
    }
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('who_does_what', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">Who Does What</div>
    </header>
    <main class="wrap">
      <div class="wdw-wrap">

        <div class="wdw-header">
          <div class="wdw-eyebrow">Who does what</div>
          <h1 class="wdw-h1">What do you need help with?</h1>

          <div class="wdw-groups" id="wdw-groups" role="group" aria-label="Filter by group">
            <?php foreach (WDW_GROUPS as $g): ?>
              <button type="button" class="wdw-group-btn" data-group="<?= h($g) ?>" aria-pressed="false"><?= h($g) ?></button>
            <?php endforeach; ?>
          </div>

          <div class="wdw-search">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="M16 16l4.5 4.5"></path></svg>
            <label for="wdw-search-input" class="wdw-sr-only">Search people or tasks</label>
            <input type="text" id="wdw-search-input" placeholder='Try &quot;commission&quot;, &quot;license transfer&quot;, or a name' autocomplete="off">
          </div>

          <div class="wdw-tags" id="wdw-tags" role="group" aria-label="Filter by task">
            <?php foreach ($visiblePublicTags as $t): ?>
              <button type="button" class="wdw-tag-btn" data-tag="<?= h($t) ?>" aria-pressed="false"><?= h($t) ?></button>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="wdw-results-head">
          <span class="wdw-count" id="wdw-count"></span>
          <span class="wdw-context" id="wdw-context"></span>
        </div>
        <div aria-live="polite" class="wdw-sr-only" id="wdw-announce"></div>

        <div class="wdw-grid" id="wdw-grid"></div>
        <div class="wdw-empty" id="wdw-empty" style="display:none">
          <p>No one matches that yet.</p>
          <p>Try a broader word, or start with Leadership, Admins, or Brokers above.</p>
        </div>

      </div>
    </main>
  </div>
</div>
<script src="assets/app.js"></script>
<script>
(function () {
  var PEOPLE = <?= json_encode($people, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
  var state = { query: '', tag: null, group: null };

  var ICON_CAL = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"></rect><path d="M8 3v4M16 3v4M3.5 10.5h17"></path></svg>';

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function matches(p) {
    if (state.group && p.groups.indexOf(state.group) === -1) return false;
    if (state.tag && p.tags.indexOf(state.tag) === -1) return false;
    if (!state.query) return true;
    var haystack = (p.name + ' ' + p.title + ' ' + p.groups.join(' ') + ' ' + p.handles + ' ' + p.tags.join(' ')).toLowerCase();
    return haystack.indexOf(state.query) !== -1;
  }

  function renderCard(p) {
    var photo = p.photoUrl
      ? '<img src="' + esc(p.photoUrl) + '" alt="">'
      : '<div class="wdw-photo-fallback" aria-hidden="true">' + esc(p.initials) + '</div>';
    var bookPill = p.bookingUrl
      ? '<a class="wdw-pill wdw-pill-book" href="' + esc(p.bookingUrl) + '" target="_blank" rel="noopener">' + ICON_CAL + 'Book time</a>'
      : '';
    var emailPill = p.email
      ? '<a class="wdw-pill wdw-pill-email" href="mailto:' + esc(p.email) + '">' + esc(p.email) + '</a>'
      : '';
    var phonePill = p.phone
      ? '<a class="wdw-pill wdw-pill-phone" href="tel:' + esc(p.phone.replace(/[^\d+]/g, '')) + '">' + esc(p.phone) + '</a>'
      : '';
    return '<div class="wdw-card ' + esc(p.accent) + '">'
      + '<div class="wdw-photo">' + photo + '</div>'
      + '<div class="wdw-body">'
      + '<h3 class="wdw-name">' + esc(p.name) + '</h3>'
      + '<div class="wdw-meta">' + esc(p.title) + ' · ' + esc(p.groups.join(' · ')) + '</div>'
      + '<p class="wdw-handles">' + esc(p.handles) + '</p>'
      + '<div class="wdw-contact">' + emailPill + phonePill + bookPill + '</div>'
      + '</div>'
      + '</div>';
  }

  function render() {
    var results = PEOPLE.filter(matches);
    var filtered = !!(state.query || state.tag || state.group);
    var grid = document.getElementById('wdw-grid');
    var empty = document.getElementById('wdw-empty');

    grid.style.display = results.length ? '' : 'none';
    empty.style.display = results.length ? 'none' : '';
    grid.innerHTML = results.map(renderCard).join('');

    var countLabel = results.length === 1
      ? '1 match'
      : (filtered ? results.length + ' matches' : 'The whole team · ' + results.length + ' people');
    var bits = [];
    if (state.query) bits.push('for "' + state.query + '"');
    if (state.tag) bits.push('in ' + state.tag);
    if (state.group) bits.push('in ' + state.group);

    document.getElementById('wdw-count').textContent = countLabel;
    document.getElementById('wdw-context').textContent = bits.join(' ');
    document.getElementById('wdw-announce').textContent = countLabel + (bits.length ? ' ' + bits.join(' ') : '');
  }

  document.getElementById('wdw-search-input').addEventListener('input', function (e) {
    state.query = e.target.value.trim().toLowerCase();
    render();
  });

  document.getElementById('wdw-groups').addEventListener('click', function (e) {
    var btn = e.target.closest('.wdw-group-btn');
    if (!btn) return;
    var g = btn.dataset.group;
    state.group = state.group === g ? null : g;
    state.tag = null;
    document.querySelectorAll('.wdw-group-btn').forEach(function (b) {
      b.setAttribute('aria-pressed', b.dataset.group === state.group ? 'true' : 'false');
    });
    document.querySelectorAll('.wdw-tag-btn').forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
    render();
  });

  document.getElementById('wdw-tags').addEventListener('click', function (e) {
    var btn = e.target.closest('.wdw-tag-btn');
    if (!btn) return;
    var t = btn.dataset.tag;
    state.tag = state.tag === t ? null : t;
    document.querySelectorAll('.wdw-tag-btn').forEach(function (b) {
      b.setAttribute('aria-pressed', b.dataset.tag === state.tag ? 'true' : 'false');
    });
    render();
  });

  render();
})();
</script>
</body>
</html>
