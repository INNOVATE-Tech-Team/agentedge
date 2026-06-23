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
                <div style="font-size:11px;color:#aaa;margin-top:4px">US numbers only. Standard message rates apply.</div>
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
  </script>
</body>
</html>
