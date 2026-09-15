<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/nav.php';

// V1: super-admin-only, same gate as coach_dashboard.php. This is a
// structural page shell only — no discussion data model exists yet
// (see build report). Do not wire this to a real table without a
// separate data-model pass.
$agent = require_login();
if (!is_super_admin()) { header('Location: index.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Coach Discussions — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <link rel="stylesheet" href="assets/coach-dashboard.css">
</head>
<body>
<div class="layout coachdash">
  <?php render_sidebar('coach_discussions', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div>
        <div class="cd-eyebrow">Coach Dashboard</div>
        <div class="content-title cd-hero-title">Discussions</div>
        <div class="content-sub" style="max-width:660px">Ask questions, share what is working, and get answers from the coaching team. Every post carries a category so past conversations stay findable — coming soon.</div>
      </div>
    </header>
    <main class="cd-page">
      <div style="display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:20px;align-items:start">
        <div style="display:flex;flex-direction:column;gap:18px">

          <div class="cd-card cd-composer">
            <div class="cd-eyebrow" style="color:var(--cd-ink);margin-bottom:14px">Start a discussion</div>
            <div class="cd-composer-row">
              <input class="cd-input" disabled placeholder="What do you want to ask the group?">
              <select class="cd-select-field" disabled>
                <option>Category…</option>
              </select>
            </div>
            <textarea class="cd-textarea" disabled placeholder="Add detail, context, or a screenshot… (discussions aren't wired to a data source yet)"></textarea>
            <div class="cd-composer-actions">
              <button type="button" class="cd-btn-primary" disabled>Post to Discussions</button>
              <span class="cd-composer-meta">Attach files &middot; Notify my coach</span>
            </div>
          </div>

          <div class="cd-filterbar">
            <span class="cd-filter-label">Filter</span>
            <span class="cd-chip active">All</span>
          </div>

          <div class="cd-card cd-empty">
            <div class="cd-empty-icon">&#128172;</div>
            Discussions aren't built yet — this page is a placeholder shell.<br>
            The data model for threads, replies, and categories will be defined in a follow-up pass.
          </div>
        </div>

        <aside style="display:flex;flex-direction:column;gap:20px">
          <section class="cd-card cd-card-pad">
            <div class="cd-sidebar-title">Browse by category</div>
            <div class="cd-empty" style="padding:20px 0">No categories configured yet.</div>
          </section>
          <section class="cd-card cd-card-pad">
            <div class="cd-sidebar-title">Unanswered</div>
            <div class="cd-unanswered-count empty" style="color:var(--cd-faint)">—</div>
            <div class="cd-unanswered-cap">threads waiting on a coach reply</div>
          </section>
          <section class="cd-card cd-card-pad">
            <div class="cd-sidebar-title">Most active this week</div>
            <div class="cd-empty" style="padding:10px 0">No activity yet.</div>
          </section>
        </aside>
      </div>
    </main>
  </div>
</div>
</body>
</html>
