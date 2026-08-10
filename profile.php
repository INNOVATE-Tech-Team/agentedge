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
    .hs-grid { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:12px; }
    .hs-thumb { position:relative; width:110px; height:110px; border-radius:8px; overflow:hidden; border:1px solid var(--border); }
    .hs-thumb img { width:100%; height:100%; object-fit:cover; }
    .hs-del { position:absolute; top:4px; right:4px; background:rgba(0,0,0,.55); color:#fff; border:0; border-radius:50%; width:22px; height:22px; font-size:12px; cursor:pointer; display:flex; align-items:center; justify-content:center; line-height:1; }
    .hs-del:hover { background:rgba(200,0,0,.8); }
    .hs-upload-label { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; background:#f0f5e8; border:1px dashed #82C112; border-radius:7px; font-size:13px; font-weight:700; color:#5b8e0d; cursor:pointer; }
    .hs-upload-label:hover { background:#e4f0d8; }
    .hs-upload-label.disabled { opacity:.5; cursor:not-allowed; }
    #hs-file { display:none; }
    .hs-note { font-size:11px; color:var(--faint); margin-top:6px; }
    .hs-msg { font-size:12px; color:var(--faint); margin-top:6px; min-height:16px; }
    .btn-ghost { background:white; border:1px solid #ccc; color:#555; padding:8px 14px; border-radius:6px; cursor:pointer; font-size:13px; }
    .btn-ghost:hover { border-color:#82C112; color:#5b8e0d; }
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
        <section class="card">
          <div id="profile-note" class="banner" hidden></div>

          <div id="gbp-candidate-banner" hidden style="border:1px solid #c3dfa8;background:#f9fdf5;border-radius:10px;padding:16px 18px;margin-bottom:18px">
            <div style="font-weight:800;font-size:13px;margin-bottom:6px">We found a Google Business listing that might be yours</div>
            <div id="gbp-candidate-details" style="font-size:13px;color:#444;margin-bottom:12px"></div>
            <div style="display:flex;gap:8px">
              <button type="button" class="btn-save" onclick="confirmCandidate()">Yes, that's me — use it</button>
              <button type="button" class="btn-ghost" onclick="dismissCandidate()">No, not me</button>
            </div>
            <div id="gbp-candidate-msg" style="font-size:12px;color:#888;margin-top:8px"></div>
          </div>

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

              <div class="section-h">Google Business Profile</div>
              <div class="field full">
                <label>Google Place ID</label>
                <input id="f-googlePlaceId" type="text" placeholder="ChIJ...">
                <div class="hs-note">Find yours at <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank" rel="noopener">Google's Place ID Finder</a> — search your business name, copy the Place ID. Used to check your listing's status and to link clients straight to your review page.</div>
              </div>
              <div class="field full">
                <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer">
                  <input type="checkbox" id="f-reviewRequestsOptIn" style="width:16px;height:16px;accent-color:#82C112;cursor:pointer">
                  Send automatic Google review requests to my clients when a transaction closes
                </label>
                <div class="hs-note">When a dotloop transaction of yours closes, a draft review-request email is prepared for staff to review and send — nothing goes out automatically without this checked, and every send is still approved by staff before it reaches your client.</div>
              </div>

              <div class="section-h">Zillow</div>
              <div class="field full">
                <label>Zillow Review Link</label>
                <input id="f-zillowReviewLink" type="url" placeholder="https://www.zillow.com/reviews/write/...">
                <div class="hs-note">Log into Zillow, go to your profile, and copy your direct "leave a review" link — it looks like zillow.com/reviews/write/[code]. Zillow doesn't offer a lookup tool for this like Google's Place ID, so it has to be copied from your own account.</div>
              </div>
              <div class="field full">
                <label style="display:flex;align-items:center;gap:8px;font-weight:400;cursor:pointer">
                  <input type="checkbox" id="f-zillowReviewRequestsOptIn" style="width:16px;height:16px;accent-color:#82C112;cursor:pointer">
                  Send automatic Zillow review requests to my clients when a transaction closes
                </label>
                <div class="hs-note">Same approval flow as Google above — nothing goes out automatically without this checked and a link on file, and every send is still approved by staff before it reaches your client.</div>
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn-save" id="save-btn" disabled>Save changes</button>
              <span class="form-msg" id="form-msg"></span>
            </div>
          </form>
        </section>

        <section class="card" style="margin-top:20px">
          <h2 style="margin:0 0 4px;font-size:15px;font-weight:800">Profile Photo</h2>
          <p class="form-sub" style="margin:0 0 18px">Shown on the agent roster and your profile across INNOVATE.</p>
          <div class="hs-grid" id="hs-grid"></div>
          <label class="hs-upload-label" id="hs-upload-label" for="hs-file">
            <span>&#43; Upload Photo</span>
          </label>
          <input type="file" id="hs-file" accept="image/*">
          <div class="hs-note">Upload up to 5 photos. Max 10 MB per file. Images only.</div>
          <div class="hs-msg" id="hs-msg"></div>
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
                  <div style="font-size:12px;color:#888">Optional — short announcement alerts to your mobile. Not required to use AgentEdge.</div>
                </div>
                <input type="checkbox" id="notif-sms" style="width:18px;height:18px;accent-color:#82C112;cursor:pointer" onchange="togglePhoneField()">
              </label>
              <div id="phone-field" style="margin-top:10px;display:none">
                <input type="tel" id="notif-phone" placeholder="(843) 555-1234"
                  style="padding:8px 10px;border:1px solid #ccc;border-radius:6px;font-size:13px;width:100%;box-sizing:border-box">
                <div style="font-size:11px;color:#aaa;margin-top:6px">By checking this box and providing your number, you agree to receive recurring automated SMS announcement alerts from INNOVATE Real Estate. This is entirely optional and is not a condition of using AgentEdge or any other service. US numbers only. Msg &amp; data rates may apply. Reply STOP to opt out, HELP for help. See our <a href="privacy.php#sms" target="_blank" style="color:#82C112">SMS terms</a>.</div>
              </div>
            </div>
          </div>

          <div style="margin-top:18px">
            <button class="btn-save" id="notif-save" onclick="saveNotifPrefs()">Save preferences</button>
            <span id="notif-status" style="font-size:12px;color:#888;margin-left:10px"></span>
          </div>
        </section>

        <?php if (can_use_buyback()): ?>
        <section class="card" style="margin-top:20px">
          <h2 style="margin:0 0 4px;font-size:15px;font-weight:800">Buy Back Your Time</h2>
          <p class="form-sub" style="margin:0 0 18px">One-time setup so the Database Audit and Equity Review tools know which Follow Up Boss contacts are yours.</p>
          <div id="bb-msg" class="banner" hidden></div>
          <div class="field" style="max-width:360px">
            <label>Which Follow Up Boss user are you?</label>
            <input type="text" id="bb-fub-user" list="bb-fub-user-options" placeholder="Loading…" autocomplete="off">
            <datalist id="bb-fub-user-options"></datalist>
          </div>
          <div style="margin-top:14px">
            <button class="btn-save" id="bb-save" onclick="saveBuybackIdentity()">Save</button>
            <span id="bb-status" style="font-size:12px;color:#888;margin-left:10px"></span>
          </div>

          <div class="field" style="max-width:420px;margin-top:22px">
            <label>Which MLS/board(s) are you a member of?</label>
            <p class="form-sub" style="margin:2px 0 10px">Scopes Appointment Prep and Equity Review's comps to boards you actually have data rights to. Check as many as apply.</p>
            <div id="mls-boards"><div style="font-size:12px;color:#888">Loading…</div></div>
          </div>
          <div style="margin-top:14px">
            <button class="btn-save" id="mls-save" onclick="saveMlsMemberships()">Save</button>
            <span id="mls-status" style="font-size:12px;color:#888;margin-left:10px"></span>
          </div>
        </section>
        <?php endif; ?>
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

  // ── Profile photo ────────────────────────────────────────────────────────────
  (function(){
    const grid = document.getElementById('hs-grid');
    const msg  = document.getElementById('hs-msg');
    const lbl  = document.getElementById('hs-upload-label');
    const inp  = document.getElementById('hs-file');

    function hsCount(){ return grid.querySelectorAll('.hs-thumb').length; }
    function syncUploadState(){
      const full = hsCount() >= 5;
      lbl.classList.toggle('disabled', full);
      inp.disabled = full;
    }
    function addThumb(fileKey){
      const wrap = document.createElement('div');
      wrap.className = 'hs-thumb';
      wrap.dataset.key = fileKey;
      const img = document.createElement('img');
      img.src = 'api/intake.php?action=headshot&key=' + encodeURIComponent(fileKey);
      img.alt = 'Profile photo';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'hs-del';
      btn.textContent = '✕';
      btn.addEventListener('click', function(){ deletePhoto(fileKey, wrap); });
      wrap.appendChild(img);
      wrap.appendChild(btn);
      grid.appendChild(wrap);
    }
    function deletePhoto(fileKey, wrap){
      msg.textContent = 'Deleting…';
      fetch('api/intake.php?action=delete_file', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: fileKey }),
      }).then(r => r.json()).then(res => {
        if (res.ok) {
          wrap.remove();
          syncUploadState();
          msg.textContent = 'Deleted.';
          setTimeout(() => msg.textContent = '', 2000);
        } else {
          msg.textContent = res.error || 'Delete failed.';
        }
      }).catch(() => { msg.textContent = 'Network error.'; });
    }

    inp.addEventListener('change', function(){
      const file = this.files[0];
      this.value = '';
      if (!file) return;
      if (hsCount() >= 5) { msg.textContent = 'Maximum 5 photos reached.'; return; }
      if (file.size > 10 * 1024 * 1024) { msg.textContent = 'File exceeds 10 MB limit.'; return; }

      msg.textContent = 'Uploading…';
      const fd = new FormData();
      fd.append('headshot', file);
      fetch('api/intake.php?action=upload', {
        method: 'POST', credentials: 'same-origin', body: fd,
      }).then(r => r.json()).then(res => {
        if (res.ok && res.file_key) {
          addThumb(res.file_key);
          syncUploadState();
          msg.textContent = 'Uploaded.';
          setTimeout(() => msg.textContent = '', 2000);
        } else {
          msg.textContent = res.error || 'Upload failed.';
        }
      }).catch(() => { msg.textContent = 'Network error.'; });
    });

    fetch('api/intake.php', { credentials: 'same-origin' }).then(r => r.json()).then(data => {
      (data.headshots || []).forEach(h => addThumb(h.file_key));
      syncUploadState();
    }).catch(() => {});
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

  // ── Buy Back Your Time: FUB identity picker ────────────────────────────────
  // A plain <select> stopped being usable once the shared FUB account grew
  // past a "tens of names" roster (many individual agents + teams) — this
  // is a type-to-search <input>+<datalist> instead, backed by a label->id
  // map since a datalist option's value (what fills the input) can't also
  // carry the numeric FUB user id the way a <select> option's value could.
  let bbFubUserMap = new Map();
  function bbEscapeAttr(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  (function(){
    const inp = document.getElementById('bb-fub-user');
    if (!inp) return; // section hidden for this role
    const dl = document.getElementById('bb-fub-user-options');
    fetch('api/buyback_fub_users.php', { credentials: 'same-origin' }).then(r => r.json()).then(d => {
      if (!d.ok && d.ok !== undefined) { inp.placeholder = d.error || 'Could not load'; return; }
      const users = d.users || [];
      let currentLabel = '';
      dl.innerHTML = users.map(u => {
        const label = (u.name || u.email || ('FUB user #' + u.id)) + (u.email ? ' (' + u.email + ')' : '');
        bbFubUserMap.set(label, u.id);
        if (d.currentFubUserId === u.id) currentLabel = label;
        return '<option value="' + bbEscapeAttr(label) + '">';
      }).join('');
      inp.placeholder = 'Type to search your name…';
      if (currentLabel) inp.value = currentLabel;
    }).catch(() => { inp.placeholder = 'Network error'; });
  })();

  function saveBuybackIdentity(){
    const inp = document.getElementById('bb-fub-user');
    const id = bbFubUserMap.get(inp.value.trim());
    const btn = document.getElementById('bb-save');
    const status = document.getElementById('bb-status');
    if (!id) { status.textContent = 'Choose your name from the list first.'; status.style.color = '#c00'; return; }
    btn.disabled = true;
    status.textContent = 'Saving…';
    status.style.color = '#888';
    fetch('api/buyback_fub_identity.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ fub_user_id: id }),
    }).then(r => r.json()).then(d => {
      btn.disabled = false;
      if (d.ok) { status.textContent = 'Saved!'; status.style.color = '#5b8e0d'; setTimeout(() => status.textContent = '', 3000); }
      else { status.textContent = d.error || 'Error saving.'; status.style.color = '#c00'; }
    }).catch(() => { btn.disabled = false; status.textContent = 'Network error.'; status.style.color = '#c00'; });
  }

  // ── Buy Back Your Time: MLS membership picker ───────────────────────────────
  (function(){
    const el = document.getElementById('mls-boards');
    if (!el) return; // section hidden for this role
    fetch('api/mls_memberships.php', { credentials: 'same-origin' }).then(r => r.json()).then(d => {
      if (!d.ok && d.ok !== undefined) { el.innerHTML = '<div style="font-size:12px;color:#c00">' + bbEscapeAttr(d.error || 'Could not load') + '</div>'; return; }
      const boards  = d.boards || [];
      const current = new Set(d.current || []);
      el.innerHTML = boards.map(b =>
        '<label style="display:flex;align-items:center;gap:8px;padding:4px 0;font-size:13px;cursor:pointer">' +
        '<input type="checkbox" class="mls-board-cb" value="' + bbEscapeAttr(b.value) + '"' + (current.has(b.value) ? ' checked' : '') + '>' +
        bbEscapeAttr(b.label) + '</label>'
      ).join('');
    }).catch(() => { el.innerHTML = '<div style="font-size:12px;color:#c00">Network error</div>'; });
  })();

  function saveMlsMemberships(){
    const checked = Array.from(document.querySelectorAll('.mls-board-cb:checked')).map(cb => cb.value);
    const btn = document.getElementById('mls-save');
    const status = document.getElementById('mls-status');
    btn.disabled = true;
    status.textContent = 'Saving…';
    status.style.color = '#888';
    fetch('api/mls_memberships_save.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ originating_system_names: checked }),
    }).then(r => r.json()).then(d => {
      btn.disabled = false;
      if (d.ok) { status.textContent = 'Saved!'; status.style.color = '#5b8e0d'; setTimeout(() => status.textContent = '', 3000); }
      else { status.textContent = d.error || 'Error saving.'; status.style.color = '#c00'; }
    }).catch(() => { btn.disabled = false; status.textContent = 'Network error.'; status.style.color = '#c00'; });
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
  </script>
</body>
</html>
