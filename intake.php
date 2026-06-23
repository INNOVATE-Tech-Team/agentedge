<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
$agent = require_login();
$myEmail = htmlspecialchars($agent['email'] ?? '', ENT_QUOTES);
$myName  = htmlspecialchars($agent['name']  ?? '', ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Onboarding Intake Form — AgentEdge</title>
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .intake-progress { display:flex; align-items:center; gap:12px; margin-bottom:18px; }
    .intake-progress-bar { flex:1; height:8px; background:#e8e8e8; border-radius:4px; overflow:hidden; }
    .intake-progress-fill { height:100%; background:#82C112; border-radius:4px; transition:width .3s; }
    .intake-progress-text { font-size:12px; color:var(--faint); white-space:nowrap; font-weight:600; }
    .intake-submitted-badge { display:inline-flex; align-items:center; gap:6px; background:#e8f5e9; color:#2e7d32; font-size:12px; font-weight:700; padding:5px 12px; border-radius:6px; }
    .hs-grid { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:12px; }
    .hs-thumb { position:relative; width:120px; height:120px; border-radius:8px; overflow:hidden; border:1px solid var(--border); }
    .hs-thumb img { width:100%; height:100%; object-fit:cover; }
    .hs-del { position:absolute; top:4px; right:4px; background:rgba(0,0,0,.55); color:#fff; border:0; border-radius:50%; width:22px; height:22px; font-size:12px; cursor:pointer; display:flex; align-items:center; justify-content:center; line-height:1; }
    .hs-del:hover { background:rgba(200,0,0,.8); }
    .hs-upload-label { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; background:#f0f5e8; border:1px dashed #82C112; border-radius:7px; font-size:13px; font-weight:700; color:#5b8e0d; cursor:pointer; }
    .hs-upload-label:hover { background:#e4f0d8; }
    .hs-upload-label.disabled { opacity:.5; cursor:not-allowed; }
    #hs-file { display:none; }
    .hs-note { font-size:11px; color:var(--faint); margin-top:6px; }
    .hs-msg { font-size:12px; color:var(--faint); margin-top:6px; height:16px; }
  </style>
</head>
<body>
  <div class="layout">
    <?php render_sidebar('intake', $agent); ?>
    <div class="content">
      <header class="content-top">
        <div class="content-title">Onboarding Intake Form</div>
        <div class="content-hello">Complete all sections — this information will be used for your profile and marketing materials</div>
      </header>
      <main class="wrap">
        <section class="card">
          <div id="intake-note" class="banner" hidden></div>
          <p class="form-sub">All fields are required unless marked optional.</p>

          <div id="progress-wrap" class="intake-progress">
            <div class="intake-progress-bar"><div class="intake-progress-fill" id="progress-fill" style="width:0%"></div></div>
            <div class="intake-progress-text" id="progress-text">0 of 11 required fields completed</div>
          </div>
          <div id="submitted-badge" hidden></div>

          <form id="intake-form">

            <div class="form-grid">
              <div class="section-h">Contact Information</div>

              <div class="field"><label>Full Name</label><input id="f-full_name" type="text" required></div>
              <div class="field"><label>Business Email</label><input id="f-email" type="email" value="<?= $myEmail ?>" disabled readonly></div>
              <div class="field"><label>Phone Number</label><input id="f-phone" type="tel" required></div>
              <div class="field"><label>Birthday</label><input id="f-birthday" type="date" required></div>
              <div class="field full"><label>Complete Mailing Address</label><textarea id="f-mailing_address" rows="3" required></textarea></div>
            </div>

            <div class="form-grid">
              <div class="section-h">License &amp; Certifications</div>

              <div class="field"><label>Real Estate License Number</label><input id="f-license_number" type="text" required></div>
              <div class="field"><label>License State</label><input id="f-license_state" type="text" placeholder="e.g. SC, NC" required></div>
              <div class="field"><label>License Expiration Date</label><input id="f-license_exp" type="date" required></div>
              <div class="field"><label>NAR Number</label><input id="f-nar_number" type="text" required></div>
            </div>

            <div class="form-grid">
              <div class="section-h">MLS Information</div>

              <div class="field"><label>MLS Board Name</label><input id="f-mls_board" type="text" required></div>
              <div class="field"><label>MLS ID Number</label><input id="f-mls_id" type="text" required></div>
            </div>

            <div class="form-grid">
              <div class="section-h">INNOVATE Office</div>

              <div class="field full"><label>Which INNOVATE office are you joining?</label><input id="f-office_location" type="text" placeholder="e.g. Myrtle Beach, Conway, Hilton Head" required></div>
            </div>

            <div class="form-grid">
              <div class="section-h">Personal Information</div>

              <div class="field"><label>Spouse or Significant Other's Name <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-spouse_name" type="text"></div>
              <div class="field">
                <label>T-Shirt Size</label>
                <select id="f-tshirt_size" required>
                  <option value=""></option>
                  <option value="XS">XS</option>
                  <option value="S">S</option>
                  <option value="M">M</option>
                  <option value="L">L</option>
                  <option value="XL">XL</option>
                  <option value="2XL">2XL</option>
                  <option value="3XL">3XL</option>
                </select>
              </div>
              <div class="field">
                <label>Military</label>
                <select id="f-is_military" required>
                  <option value="">Not applicable</option>
                  <option value="veteran">Veteran</option>
                  <option value="active">Active Duty</option>
                </select>
              </div>
              <div class="field"><label>First Responder or Medical Professional <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-first_responder" type="text" placeholder="Specify type (paramedic, nurse, police, etc.) or leave blank"></div>
              <div class="field">
                <label>Teacher</label>
                <select id="f-is_teacher" required>
                  <option value="no">No</option>
                  <option value="current">Yes – Currently a teacher</option>
                  <option value="former">Yes – Former teacher</option>
                </select>
              </div>
              <div class="field"><label>What languages do you speak?</label><input id="f-languages" type="text" placeholder="e.g. English, Spanish" required></div>
            </div>

            <div class="form-grid">
              <div class="section-h">Emergency Contact</div>

              <div class="field"><label>Emergency Contact Name</label><input id="f-emergency_name" type="text" required></div>
              <div class="field"><label>Emergency Contact Phone</label><input id="f-emergency_phone" type="tel" required></div>
            </div>

            <div class="form-grid">
              <div class="section-h">Bio &amp; Marketing</div>

              <div class="field full"><label>Agent Bio</label><textarea id="f-bio" rows="8" placeholder="Your real estate agent bio will appear on our website. Tip: go to ChatGPT, share some facts about yourself, and have it write a bio for you." required></textarea></div>

              <div class="field full">
                <label>Headshots</label>
                <div class="hs-grid" id="hs-grid"></div>
                <label class="hs-upload-label" id="hs-upload-label" for="hs-file">
                  <span>&#43; Upload Headshot</span>
                </label>
                <input type="file" id="hs-file" accept="image/*">
                <div class="hs-note">Upload up to 5 photos. Max 10 MB per file. Images only.</div>
                <div class="hs-msg" id="hs-msg"></div>
              </div>

              <div class="field"><label>What agent is the reason you decided to join INNOVATE? <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-referring_agent" type="text"></div>
              <div class="field"><label>Last 4 digits of your phone number</label><input id="f-phone_last4" type="text" maxlength="4" pattern="[0-9]{4}" placeholder="e.g. 1234" required></div>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn-save" id="save-btn">Save changes</button>
              <span class="form-msg" id="form-msg"></span>
            </div>

          </form>
        </section>
      </main>
    </div>
  </div>

  <script>
  (function () {
    const REQUIRED_IDS = ['full_name','phone','license_number','nar_number','mls_board','office_location','birthday','mailing_address','emergency_name','emergency_phone','bio'];
    const TOTAL = REQUIRED_IDS.length;

    function el(id) { return document.getElementById(id); }

    function calcProgress() {
      let done = 0;
      REQUIRED_IDS.forEach(function(id) {
        const node = el('f-' + id);
        if (node && node.value && node.value.trim() !== '') done++;
      });
      return done;
    }

    function updateProgress() {
      const done = calcProgress();
      const pct = Math.round((done / TOTAL) * 100);
      el('progress-fill').style.width = pct + '%';
      el('progress-text').textContent = done + ' of ' + TOTAL + ' required fields completed';
    }

    function setFields(intake) {
      if (!intake) return;
      const map = ['full_name','phone','birthday','mailing_address','license_number','license_state','license_exp','nar_number','mls_board','mls_id','office_location','spouse_name','emergency_name','emergency_phone','bio','tshirt_size','is_military','first_responder','is_teacher','phone_last4','referring_agent','languages'];
      map.forEach(function(key) {
        const node = el('f-' + key);
        if (node && intake[key] !== undefined && intake[key] !== null) {
          node.value = intake[key];
        }
      });
    }

    function renderHeadshots(list) {
      const grid = el('hs-grid');
      grid.innerHTML = '';
      (list || []).forEach(function(key) { addThumb(key); });
      syncUploadState(list ? list.length : 0);
    }

    function addThumb(key) {
      const grid = el('hs-grid');
      const wrap = document.createElement('div');
      wrap.className = 'hs-thumb';
      wrap.dataset.key = key;
      const img = document.createElement('img');
      img.src = 'api/intake.php?action=headshot&key=' + encodeURIComponent(key);
      img.alt = 'Headshot';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'hs-del';
      btn.textContent = '✕';
      btn.addEventListener('click', function() { deleteHeadshot(key, wrap); });
      wrap.appendChild(img);
      wrap.appendChild(btn);
      grid.appendChild(wrap);
    }

    function syncUploadState(count) {
      const lbl = el('hs-upload-label');
      const inp = el('hs-file');
      if (count >= 5) {
        lbl.classList.add('disabled');
        inp.disabled = true;
      } else {
        lbl.classList.remove('disabled');
        inp.disabled = false;
      }
    }

    function hsCount() { return el('hs-grid').querySelectorAll('.hs-thumb').length; }

    function deleteHeadshot(key, wrap) {
      el('hs-msg').textContent = 'Deleting…';
      fetch('api/intake.php?action=delete_file', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: key })
      }).then(function(r) { return r.json(); }).then(function(res) {
        if (res.ok) {
          wrap.remove();
          syncUploadState(hsCount());
          el('hs-msg').textContent = 'Deleted.';
          setTimeout(function() { el('hs-msg').textContent = ''; }, 2000);
        } else {
          el('hs-msg').textContent = res.error || 'Delete failed.';
        }
      }).catch(function() { el('hs-msg').textContent = 'Network error.'; });
    }

    el('hs-file').addEventListener('change', function() {
      const file = this.files[0];
      if (!file) return;
      if (hsCount() >= 5) { el('hs-msg').textContent = 'Maximum 5 headshots reached.'; return; }
      if (file.size > 10 * 1024 * 1024) { el('hs-msg').textContent = 'File exceeds 10 MB limit.'; return; }

      el('hs-msg').textContent = 'Uploading…';
      const fd = new FormData();
      fd.append('headshot', file);

      fetch('api/intake.php?action=upload', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd
      }).then(function(r) { return r.json(); }).then(function(res) {
        if (res.ok && res.key) {
          addThumb(res.key);
          syncUploadState(hsCount());
          el('hs-msg').textContent = 'Uploaded.';
          setTimeout(function() { el('hs-msg').textContent = ''; }, 2000);
        } else {
          el('hs-msg').textContent = res.error || 'Upload failed.';
        }
      }).catch(function() { el('hs-msg').textContent = 'Network error.'; });

      this.value = '';
    });

    document.querySelectorAll('#intake-form input, #intake-form textarea, #intake-form select').forEach(function(node) {
      node.addEventListener('input', updateProgress);
      node.addEventListener('change', updateProgress);
    });

    el('intake-form').addEventListener('submit', function(e) {
      e.preventDefault();
      const btn = el('save-btn');
      const msg = el('form-msg');
      btn.disabled = true;
      msg.textContent = 'Saving…';

      const payload = {
        full_name:       el('f-full_name').value,
        phone:           el('f-phone').value,
        license_number:  el('f-license_number').value,
        license_state:   el('f-license_state').value,
        license_exp:     el('f-license_exp').value,
        nar_number:      el('f-nar_number').value,
        mls_board:       el('f-mls_board').value,
        mls_id:          el('f-mls_id').value,
        office_location: el('f-office_location').value,
        birthday:        el('f-birthday').value,
        mailing_address: el('f-mailing_address').value,
        spouse_name:     el('f-spouse_name').value,
        emergency_name:  el('f-emergency_name').value,
        emergency_phone: el('f-emergency_phone').value,
        bio:             el('f-bio').value,
        tshirt_size:     el('f-tshirt_size').value,
        is_military:     el('f-is_military').value,
        first_responder: el('f-first_responder').value,
        is_teacher:      el('f-is_teacher').value,
        phone_last4:     el('f-phone_last4').value,
        referring_agent: el('f-referring_agent').value,
        languages:       el('f-languages').value
      };

      fetch('api/intake.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(function(r) { return r.json(); }).then(function(res) {
        btn.disabled = false;
        if (res.ok) {
          msg.textContent = 'Saved ✓';
          updateProgress();
          if (res.submitted_at) {
            showSubmittedBadge(res.submitted_at);
          }
          setTimeout(function() { msg.textContent = ''; }, 3000);
        } else {
          msg.textContent = res.error || 'Save failed.';
        }
      }).catch(function() {
        btn.disabled = false;
        msg.textContent = 'Network error.';
      });
    });

    function showSubmittedBadge(dateStr) {
      const badge = el('submitted-badge');
      const d = dateStr ? new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
      badge.innerHTML = '<span class="intake-submitted-badge">&#10003; Submitted' + (d ? ' on ' + d : '') + '</span>';
      badge.hidden = false;
      el('progress-wrap').hidden = true;
    }

    fetch('api/intake.php', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        setFields(data.intake);
        renderHeadshots(data.headshots);
        if (data.intake && data.intake.submitted_at) {
          showSubmittedBadge(data.intake.submitted_at);
        } else {
          updateProgress();
        }
      })
      .catch(function() { updateProgress(); });
  })();
  </script>
</body>
</html>
