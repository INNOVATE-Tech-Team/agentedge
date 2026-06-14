<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/nav.php';
$agent = require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Profile — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
  <div class="layout">
    <?php render_sidebar('profile', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div class="content-title">My Profile</div>
        <div class="content-hello">Keep your contact info and social links current</div>
      </header>
      <main class="wrap">
        <section class="card">
          <div id="profile-note" class="banner" hidden></div>
          <p class="form-sub">This is the information shown to your office and across INNOVATE.
            Market Center and brokerage are managed by your office.</p>

          <form id="profile-form">
            <div class="form-grid">
              <div class="field"><label>Full Name</label><input id="f-fullName" type="text"></div>
              <div class="field"><label>Email</label><input id="f-email" type="email"></div>
              <div class="field"><label>Phone</label><input id="f-phone" type="tel"></div>
              <div class="field"><label>Market Center</label><input id="f-marketCenter" type="text" disabled></div>
              <div class="field full"><label>Brokerage</label><input id="f-brokerage" type="text" disabled></div>

              <div class="section-h">Social Media</div>
              <div class="field"><label>Facebook</label><input id="f-facebook" type="url" placeholder="https://facebook.com/…"></div>
              <div class="field"><label>Instagram</label><input id="f-instagram" type="url" placeholder="https://instagram.com/…"></div>
              <div class="field"><label>LinkedIn</label><input id="f-linkedin" type="url" placeholder="https://linkedin.com/in/…"></div>
              <div class="field"><label>Twitter / X</label><input id="f-twitter" type="url" placeholder="https://x.com/…"></div>
              <div class="field"><label>YouTube</label><input id="f-youtube" type="url" placeholder="https://youtube.com/@…"></div>
              <div class="field"><label>TikTok</label><input id="f-tiktok" type="url" placeholder="https://tiktok.com/@…"></div>
              <div class="field"><label>Website</label><input id="f-website" type="url" placeholder="https://…"></div>
              <div class="field"><label>Blog</label><input id="f-blog" type="url" placeholder="https://…"></div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn-save" id="save-btn" disabled>Save changes</button>
              <span class="form-msg" id="form-msg"></span>
            </div>
          </form>
        </section>
      </main>
    </div>
  </div>
  <script src="assets/profile.js"></script>
</body>
</html>
