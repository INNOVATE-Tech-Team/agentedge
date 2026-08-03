<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Profile — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    #hs-file{display:none}
    .ap-avatar-img{border-radius:50%;object-fit:cover;flex-shrink:0;border:1px solid var(--border)}
    .ap-avatar-fallback{border-radius:50%;background:#e8f5d0;color:#5b8e0d;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .hs-upload-label{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#f0f5e8;border:1px dashed #82C112;border-radius:7px;font-size:13px;font-weight:700;color:#5b8e0d;cursor:pointer}
    .hs-upload-label:hover{background:#e4f0d8}
    .hs-upload-label.disabled{opacity:.5;cursor:not-allowed}
    .hs-note{font-size:11px;color:var(--faint);margin-top:6px}
    .hs-msg{font-size:12px;color:var(--faint);margin-top:6px;min-height:16px}
  </style>
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
        <section class="card" style="margin-bottom:20px">
          <h2 style="margin:0 0 4px;font-size:15px;font-weight:800">Profile Photo</h2>
          <p class="form-sub" style="margin:0 0 14px">Shown on your agent profile. Uploading a new photo replaces the current one.</p>
          <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
            <img id="hs-avatar-img" class="ap-avatar-img" style="width:72px;height:72px;display:none" alt="">
            <div id="hs-avatar-fallback" class="ap-avatar-fallback" style="width:72px;height:72px;font-size:22px">?</div>
            <div>
              <label class="hs-upload-label" id="hs-upload-label" for="hs-file"><span>&#43; Upload / Replace Photo</span></label>
              <input type="file" id="hs-file" accept="image/*">
              <div class="hs-note">JPG or PNG, up to 10 MB.</div>
              <div class="hs-msg" id="hs-msg"></div>
            </div>
          </div>
        </section>

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

              <div class="section-h">Important Dates</div>
              <div class="field"><label>Birthday <small style="font-weight:400;color:#999">(MM-DD, shown on BIC calendar)</small></label><input id="f-birthday" type="text" placeholder="06-15" maxlength="5" pattern="\d{2}-\d{2}"></div>
              <div class="field"><label>Start Date <small style="font-weight:400;color:#999">(YYYY-MM-DD, for work anniversary)</small></label><input id="f-hire_date" type="date"></div>
              <div class="field"><label>License Renewal <small style="font-weight:400;color:#999">(MM-DD, annual reminder)</small></label><input id="f-license_renewal" type="text" placeholder="03-31" maxlength="5" pattern="\d{2}-\d{2}"></div>

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

        <section class="card" style="margin-top:20px">
          <h2 style="margin:0 0 4px;font-size:15px;font-weight:800">Password</h2>
          <p class="form-sub" style="margin:0 0 18px">Change the password you use to sign in to AgentEdge.</p>
          <div id="pw-msg" class="banner" hidden></div>

          <div style="display:flex;flex-direction:column;gap:12px;max-width:340px">
            <div class="field"><label>Current Password</label><input type="password" id="pw-current" autocomplete="current-password"></div>
            <div class="field"><label>New Password</label><input type="password" id="pw-new" autocomplete="new-password"></div>
            <div class="field"><label>Confirm New Password</label><input type="password" id="pw-confirm" autocomplete="new-password"></div>
          </div>

          <div style="margin-top:18px">
            <button class="btn-save" id="pw-save" onclick="savePassword()">Change Password</button>
            <span id="pw-status" style="font-size:12px;color:#888;margin-left:10px"></span>
          </div>
        </section>

        <section class="card" style="margin-top:20px">
          <h2 style="margin:0 0 4px;font-size:15px;font-weight:800">Notification Preferences</h2>
          <p class="form-sub" style="margin:0 0 18px">Choose how you want to be notified when new announcements are posted.</p>
          <div id="notif-msg" class="banner" hidden></div>

          <div style="display:flex;flex-direction:column;gap:16px;max-width:420px">
            <label style="display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer">
              <div>
                <div style="font-size:13px;font-weight:700">Email notifications</div>
                <div style="font-size:12px;color:#888">Announcements sent to your login email</div>
              </div>
              <input type="checkbox" id="notif-email" style="width:18px;height:18px;accent-color:#82C112;cursor:pointer">
            </label>

            <div>
              <label style="display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer">
                <div>
                  <div style="font-size:13px;font-weight:700">Text (SMS) notifications</div>
                  <div style="font-size:12px;color:#888">Short announcement alerts to your mobile</div>
                </div>
                <input type="checkbox" id="notif-sms" style="width:18px;height:18px;accent-color:#82C112;cursor:pointer" onchange="togglePhoneField()">
              </label>
              <div id="phone-field" style="margin-top:10px;display:none">
                <input type="tel" id="notif-phone" placeholder="(843) 555-1234"
                  style="padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;width:100%;box-sizing:border-box">
                <div style="font-size:11px;color:#aaa;margin-top:4px">US numbers only. Msg &amp; data rates may apply. Reply STOP to opt out, HELP for help. See our <a href="privacy.php#sms" target="_blank" style="color:#82C112">SMS terms</a>.</div>
              </div>
            </div>
          </div>

          <div style="margin-top:18px">
            <button class="btn-save" id="notif-save" onclick="saveNotifPrefs()">Save preferences</button>
            <span id="notif-status" style="font-size:12px;color:#888;margin-left:10px"></span>
          </div>
        </section>
      </main>
    </div>
  </div>
  <script src="assets/profile.js"></script>
  <script>
  // ── Notification preferences ────────────────────────────────────────────────
  (function(){
    fetch('api/notify_prefs.php',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{
      document.getElementById('notif-email').checked = !!d.notify_email;
      document.getElementById('notif-sms').checked   = !!d.notify_sms;
      if(d.sms_phone) document.getElementById('notif-phone').value = d.sms_phone;
      togglePhoneField();
    }).catch(()=>{});
  })();

  // ── Password change ─────────────────────────────────────────────────────────
  function savePassword(){
    const current = document.getElementById('pw-current').value;
    const next    = document.getElementById('pw-new').value;
    const confirm = document.getElementById('pw-confirm').value;
    const btn = document.getElementById('pw-save');
    const status = document.getElementById('pw-status');
    const banner = document.getElementById('pw-msg');
    banner.hidden = true;

    if(!current || !next || !confirm){ status.textContent='Please fill in all fields.'; status.style.color='#c00'; return; }
    if(next !== confirm){ status.textContent="New passwords don't match."; status.style.color='#c00'; return; }
    if(next.length < 8){ status.textContent='New password must be at least 8 characters.'; status.style.color='#c00'; return; }

    btn.disabled = true;
    status.textContent = 'Saving…';
    status.style.color = '#888';
    fetch('api/change_password.php', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({current_password: current, new_password: next, confirm_password: confirm}),
    }).then(r=>r.json()).then(d=>{
      btn.disabled = false;
      if(d.ok){
        status.textContent = 'Saved!'; status.style.color='#5b8e0d';
        document.getElementById('pw-current').value = '';
        document.getElementById('pw-new').value = '';
        document.getElementById('pw-confirm').value = '';
        setTimeout(()=>status.textContent='',3000);
      } else {
        status.textContent = d.error || 'Error saving.'; status.style.color='#c00';
      }
    }).catch(()=>{ btn.disabled=false; status.textContent='Network error.'; status.style.color='#c00'; });
  }

  function togglePhoneField(){
    const show = document.getElementById('notif-sms').checked;
    document.getElementById('phone-field').style.display = show ? '' : 'none';
  }

  function saveNotifPrefs(){
    const emailOn = document.getElementById('notif-email').checked;
    const smsOn   = document.getElementById('notif-sms').checked;
    const phone   = document.getElementById('notif-phone').value.trim();
    if(smsOn && !phone){ alert('Please enter a phone number to enable SMS notifications.'); return; }
    const btn = document.getElementById('notif-save');
    const msg = document.getElementById('notif-status');
    btn.disabled = true;
    msg.textContent = 'Saving…';
    fetch('api/notify_prefs.php',{
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({notify_email: emailOn?1:0, notify_sms: smsOn?1:0, sms_phone: phone}),
    }).then(r=>r.json()).then(d=>{
      btn.disabled = false;
      if(d.ok){ msg.textContent='Saved!'; msg.style.color='#5b8e0d'; setTimeout(()=>msg.textContent='',3000); }
      else { msg.textContent = d.error||'Error saving.'; msg.style.color='#c00'; }
    }).catch(()=>{ btn.disabled=false; msg.textContent='Network error.'; msg.style.color='#c00'; });
  }

  // ── Profile photo ────────────────────────────────────────────────────────────
  (function () {
    var img      = document.getElementById('hs-avatar-img');
    var fallback = document.getElementById('hs-avatar-fallback');
    var input    = document.getElementById('hs-file');
    var label    = document.getElementById('hs-upload-label');
    var status   = document.getElementById('hs-msg');
    var oldKeys  = [];

    function showKey(key) {
      if (key) {
        img.src = 'api/intake.php?action=headshot&key=' + encodeURIComponent(key);
        img.style.display = '';
        fallback.style.display = 'none';
      } else {
        img.style.display = 'none';
        fallback.style.display = '';
      }
    }

    fetch('api/intake.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var list = d.headshots || [];
      oldKeys = list.map(function (h) { return h.file_key; });
      showKey(oldKeys.length ? oldKeys[oldKeys.length - 1] : null);
    }).catch(function () {});

    input.addEventListener('change', function () {
      var file = this.files[0];
      if (!file) return;
      if (file.size > 10 * 1024 * 1024) { status.textContent = 'File exceeds 10 MB limit.'; this.value = ''; return; }

      input.disabled = true;
      label.classList.add('disabled');
      status.textContent = 'Uploading…';
      var fd = new FormData();
      fd.append('headshot', file);

      fetch('api/intake.php?action=upload', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          input.disabled = false;
          label.classList.remove('disabled');
          if (!res.ok || !res.file_key) { status.textContent = res.error || 'Upload failed.'; return; }
          var toRemove = oldKeys;
          oldKeys = [res.file_key];
          showKey(res.file_key);
          status.textContent = 'Saved.';
          setTimeout(function () { status.textContent = ''; }, 2000);
          toRemove.forEach(function (key) {
            fetch('api/intake.php?action=delete_file', {
              method: 'POST', credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ key: key }),
            }).catch(function () {});
          });
        })
        .catch(function () { input.disabled = false; label.classList.remove('disabled'); status.textContent = 'Network error.'; });

      this.value = '';
    });
  })();
  </script>
</body>
</html>
