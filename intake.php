<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/local_db.php';
$agent = require_login();
$myEmail = htmlspecialchars($agent['email'] ?? '', ENT_QUOTES);
$myName  = htmlspecialchars($agent['name']  ?? '', ENT_QUOTES);

$intakeMarketCenters = local_db()
    ->query("SELECT name, state_code FROM market_centers WHERE enabled=1 ORDER BY state_code, sort_ord, name")
    ->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Onboarding Intake Form — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
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

    .office-checklist { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; max-height: 260px; overflow-y: auto; border: 1px solid var(--border); border-radius: 7px; padding: 10px 12px; }
    @media (max-width: 520px) { .office-checklist { grid-template-columns: 1fr; } }
    .office-checklist label { display: flex; align-items: center; gap: 8px; font-size: 13px; padding: 3px 0; text-transform: none; font-weight: 400; }
    .office-checklist input[type=checkbox] { width: auto; margin: 0; }
    .office-checklist.invalid { border-color: #e53935; }

    .license-row { display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 0 10px; align-items: end; margin-bottom: 10px; }
    @media (max-width: 520px) { .license-row { grid-template-columns: 1fr; } }
    .license-row .field { margin-bottom: 0; }
    .btn-remove-license { border: 1px solid var(--border); background: #fff; color: #888; border-radius: 7px; padding: 9px 12px; font-size: 13px; cursor: pointer; height: fit-content; }
    .btn-remove-license:hover { border-color: #e53935; color: #e53935; }
    .btn-add-license { border: 1px dashed #82C112; background: #f0f5e8; color: #5b8e0d; border-radius: 7px; padding: 8px 14px; font-size: 13px; font-weight: 700; cursor: pointer; margin-top: 4px; }
    .btn-add-license:hover { background: #e4f0d8; }
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
              <div class="field"><label>Personal Email <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-personal_email" type="email"></div>
              <div class="field"><label>Commissions Email <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-commissions_email" type="email"></div>
            </div>

            <div class="form-grid">
              <div class="section-h">Address</div>

              <div class="field full"><label>Address Line 1</label><input id="f-address_line1" type="text" required></div>
              <div class="field full"><label>Address Line 2 <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-address_line2" type="text"></div>
              <div class="field"><label>City</label><input id="f-city" type="text" required></div>
              <div class="field"><label>State</label><input id="f-state" type="text" placeholder="e.g. SC" required></div>
              <div class="field"><label>Zip/Postal Code</label><input id="f-zip" type="text" required></div>
              <div class="field"><label>Country</label><input id="f-country" type="text" value="United States"></div>
            </div>

            <div class="form-grid">
              <div class="section-h">License &amp; Certifications</div>

              <div class="field"><label>Real Estate License Number</label><input id="f-license_number" type="text" required></div>
              <div class="field"><label>License State</label><input id="f-license_state" type="text" placeholder="e.g. SC, NC" required></div>
              <div class="field"><label>License Expiration Date</label><input id="f-license_exp" type="date" required></div>
              <div class="field"><label>NAR Number</label><input id="f-nar_number" type="text" required></div>
              <div class="field full">
                <div id="additional-licenses"></div>
                <button type="button" class="btn-add-license" id="btn-add-license">+ Add Another License</button>
              </div>
            </div>

            <div class="form-grid">
              <div class="section-h">MLS Information</div>

              <div class="field"><label>MLS Board Name</label><input id="f-mls_board" type="text" required></div>
              <div class="field"><label>MLS ID Number</label><input id="f-mls_id" type="text" required></div>
            </div>

            <div class="form-grid">
              <div class="section-h">INNOVATE Office</div>

              <div class="field full">
                <label>Which INNOVATE office(s) are you joining? <span style="font-weight:400;color:var(--faint)">(check all that apply)</span></label>
                <div class="office-checklist" id="office-checklist">
                  <?php foreach ($intakeMarketCenters as $mc): ?>
                  <label>
                    <input type="checkbox" name="office_locations" value="<?= htmlspecialchars($mc['name'], ENT_QUOTES) ?>">
                    <?= htmlspecialchars($mc['name'], ENT_QUOTES) ?><?= $mc['state_code'] ? ' (' . htmlspecialchars($mc['state_code'], ENT_QUOTES) . ')' : '' ?>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <div class="form-grid">
              <div class="section-h">Professional Background <span style="font-weight:400;color:var(--faint)">(optional)</span></div>

              <div class="field">
                <label>Specialty <span style="font-weight:400;color:var(--faint)">(optional)</span></label>
                <select id="f-specialty">
                  <option value=""></option>
                  <option value="Residential">Residential</option>
                  <option value="Commercial">Commercial</option>
                  <option value="Luxury">Luxury</option>
                  <option value="Land/Farm">Land/Farm</option>
                  <option value="New Construction">New Construction</option>
                  <option value="Property Management">Property Management</option>
                  <option value="Relocation">Relocation</option>
                  <option value="Other">Other</option>
                </select>
              </div>
              <div class="field"><label>Career Start Date <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-career_start" type="date"></div>
              <div class="field"><label>Prior Occupation <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-prior_occupation" type="text" placeholder="What did you do before real estate?"></div>
              <div class="field"><label>Prior Affiliation <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-prior_affiliation" type="text" placeholder="Previous brokerage, if any"></div>
              <div class="field"><label><input type="checkbox" id="f-full_time" checked style="width:auto;display:inline-block;margin-right:6px;vertical-align:middle">Full-Time Agent</label></div>
              <div class="field"><label><input type="checkbox" id="f-show_on_internet" checked style="width:auto;display:inline-block;margin-right:6px;vertical-align:middle">Show my profile on the company website</label></div>
            </div>

            <div class="form-grid">
              <div class="section-h">Business Entity &amp; Tax Info <span style="font-weight:400;color:var(--faint)">(optional)</span></div>

              <div class="field"><label>Personal Tax ID / SSN <span style="font-weight:400;color:var(--faint)" id="personal-tax-id-hint">(optional, encrypted)</span></label><input id="f-personal_tax_id" type="text" placeholder="For payroll — stored encrypted"></div>
              <div class="field"><label>Corporate Tax ID / EIN <span style="font-weight:400;color:var(--faint)" id="corporate-tax-id-hint">(optional, if you operate as an LLC/S-Corp)</span></label><input id="f-corporate_tax_id" type="text" placeholder="Stored encrypted"></div>
              <div class="field"><label>Corporation Start Date <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-corporation_start" type="date"></div>
              <div class="field"><label>Corporation End Date <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-corporation_end" type="date"></div>
            </div>

            <div class="form-grid">
              <div class="section-h">Personal Information</div>

              <div class="field"><label>Spouse or Significant Other's Name <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-spouse_name" type="text"></div>
              <div class="field">
                <label>Gender <span style="font-weight:400;color:var(--faint)">(optional)</span></label>
                <select id="f-gender">
                  <option value=""></option>
                  <option value="Male">Male</option>
                  <option value="Female">Female</option>
                  <option value="Prefer not to say">Prefer not to say</option>
                </select>
              </div>
              <div class="field"><label>Driver's License # <span style="font-weight:400;color:var(--faint)">(optional)</span></label><input id="f-drivers_license" type="text"></div>
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
              <div class="section-h">Online Presence <span style="font-weight:400;color:var(--faint)">(optional)</span></div>

              <div class="field"><label>Website</label><input id="f-website" type="text" placeholder="https://"></div>
              <div class="field"><label>Additional Websites</label><input id="f-additional_websites" type="text" placeholder="https://"></div>
              <div class="field"><label>Facebook</label><input id="f-facebook" type="text" placeholder="https://facebook.com/..."></div>
              <div class="field"><label>LinkedIn</label><input id="f-linkedin" type="text" placeholder="https://linkedin.com/in/..."></div>
              <div class="field"><label>Instagram</label><input id="f-instagram" type="text" placeholder="https://instagram.com/..."></div>
              <div class="field"><label>Skype</label><input id="f-skype" type="text" placeholder="Skype username"></div>
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

              <div class="field"><label>Which agent was the reason you decided to join INNOVATE?</label><input id="f-referring_agent" type="text" required placeholder="Enter N/A if it was not a specific agent"></div>
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
    const REQUIRED_IDS = ['full_name','phone','license_number','nar_number','mls_board','birthday','address_line1','city','state','zip','emergency_name','emergency_phone','bio','referring_agent'];
    const TOTAL = REQUIRED_IDS.length + 1; // +1 for the office checklist

    function el(id) { return document.getElementById(id); }

    function officeChecked() {
      return document.querySelectorAll('#office-checklist input:checked').length > 0;
    }

    function calcProgress() {
      let done = 0;
      REQUIRED_IDS.forEach(function(id) {
        const node = el('f-' + id);
        if (node && node.value && node.value.trim() !== '') done++;
      });
      if (officeChecked()) done++;
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
      const map = ['full_name','phone','birthday','license_number','license_state','license_exp','nar_number','mls_board','mls_id','spouse_name','emergency_name','emergency_phone','bio','tshirt_size','is_military','first_responder','is_teacher','phone_last4','referring_agent','languages','personal_email','commissions_email','address_line1','address_line2','city','state','zip','country','drivers_license','gender','website','additional_websites','facebook','linkedin','skype','instagram','specialty','career_start','prior_occupation','prior_affiliation','corporation_start','corporation_end'];
      map.forEach(function(key) {
        const node = el('f-' + key);
        if (node && intake[key] !== undefined && intake[key] !== null) {
          node.value = intake[key];
        }
      });

      // Checkboxes: only override the HTML default (checked) once we actually
      // have a saved value — a brand-new agent has no row yet, so the default stands.
      ['full_time', 'show_on_internet'].forEach(function(key) {
        const node = el('f-' + key);
        if (node && intake[key] !== undefined && intake[key] !== null) {
          node.checked = Number(intake[key]) === 1;
        }
      });

      // Office checklist is stored as a single comma-joined string; re-check
      // whichever boxes match a previously-saved office name.
      if (intake.office_location) {
        const saved = intake.office_location.split(',').map(function(s) { return s.trim(); });
        document.querySelectorAll('#office-checklist input').forEach(function(node) {
          if (saved.indexOf(node.value) !== -1) node.checked = true;
        });
      }

      // Tax IDs are never sent back in full — just a last-4 hint so the agent
      // knows one is already on file and only needs to type a new one to replace it.
      if (intake.personal_tax_id_last4) {
        el('personal-tax-id-hint').textContent = '(on file, ending in ' + intake.personal_tax_id_last4 + ' — leave blank to keep it)';
      }
      if (intake.corporate_tax_id_last4) {
        el('corporate-tax-id-hint').textContent = '(on file, ending in ' + intake.corporate_tax_id_last4 + ' — leave blank to keep it)';
      }
    }

    function renderAdditionalLicenses(list) {
      const container = el('additional-licenses');
      container.innerHTML = '';
      (list || []).forEach(function(lic) { addLicenseRow(lic); });
    }

    function addLicenseRow(lic) {
      lic = lic || {};
      const row = document.createElement('div');
      row.className = 'license-row';
      row.innerHTML =
        '<div class="field"><label>Real Estate License #</label><input type="text" class="al-number"></div>' +
        '<div class="field"><label>License State</label><input type="text" class="al-state" placeholder="e.g. SC, NC"></div>' +
        '<div class="field"><label>License Expiration Date</label><input type="date" class="al-exp"></div>' +
        '<button type="button" class="btn-remove-license">Remove</button>';
      row.querySelector('.al-number').value = lic.license_number || '';
      row.querySelector('.al-state').value  = lic.license_state  || '';
      row.querySelector('.al-exp').value    = lic.license_exp    || '';
      row.querySelector('.btn-remove-license').addEventListener('click', function() { row.remove(); });
      el('additional-licenses').appendChild(row);
    }

    function collectAdditionalLicenses() {
      const out = [];
      document.querySelectorAll('#additional-licenses .license-row').forEach(function(row) {
        const number = row.querySelector('.al-number').value.trim();
        const state  = row.querySelector('.al-state').value.trim();
        const exp    = row.querySelector('.al-exp').value.trim();
        if (number || state || exp) out.push({ license_number: number, license_state: state, license_exp: exp });
      });
      return out;
    }

    el('btn-add-license').addEventListener('click', function() { addLicenseRow(); });

    document.querySelectorAll('#office-checklist input').forEach(function(node) {
      node.addEventListener('change', function() {
        el('office-checklist').classList.toggle('invalid', !officeChecked());
        updateProgress();
      });
    });

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

      if (!officeChecked()) {
        el('office-checklist').classList.add('invalid');
        el('office-checklist').scrollIntoView({ behavior: 'smooth', block: 'center' });
        el('form-msg').textContent = 'Please select at least one office.';
        return;
      }

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
        office_location: Array.from(document.querySelectorAll('#office-checklist input:checked')).map(function(n) { return n.value; }).join(', '),
        additional_licenses: collectAdditionalLicenses(),
        birthday:        el('f-birthday').value,
        spouse_name:     el('f-spouse_name').value,
        gender:          el('f-gender').value,
        drivers_license: el('f-drivers_license').value,
        address_line1:   el('f-address_line1').value,
        address_line2:   el('f-address_line2').value,
        city:            el('f-city').value,
        state:           el('f-state').value,
        zip:             el('f-zip').value,
        country:         el('f-country').value,
        emergency_name:  el('f-emergency_name').value,
        emergency_phone: el('f-emergency_phone').value,
        bio:             el('f-bio').value,
        tshirt_size:     el('f-tshirt_size').value,
        is_military:     el('f-is_military').value,
        first_responder: el('f-first_responder').value,
        is_teacher:      el('f-is_teacher').value,
        phone_last4:     el('f-phone_last4').value,
        referring_agent: el('f-referring_agent').value,
        languages:       el('f-languages').value,
        personal_email:      el('f-personal_email').value,
        commissions_email:   el('f-commissions_email').value,
        website:             el('f-website').value,
        additional_websites: el('f-additional_websites').value,
        facebook:            el('f-facebook').value,
        linkedin:            el('f-linkedin').value,
        instagram:           el('f-instagram').value,
        skype:               el('f-skype').value,
        specialty:           el('f-specialty').value,
        career_start:        el('f-career_start').value,
        prior_occupation:    el('f-prior_occupation').value,
        prior_affiliation:   el('f-prior_affiliation').value,
        full_time:           el('f-full_time').checked,
        show_on_internet:    el('f-show_on_internet').checked,
        personal_tax_id:     el('f-personal_tax_id').value,
        corporate_tax_id:    el('f-corporate_tax_id').value,
        corporation_start:   el('f-corporation_start').value,
        corporation_end:     el('f-corporation_end').value
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
        renderAdditionalLicenses(data.additional_licenses);
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
