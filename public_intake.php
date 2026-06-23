<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/local_db.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Onboarding Intake Form — INNOVATE AgentEdge</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f5f6; min-height: 100vh; }

    /* Brand header */
    .brand-header { background: #111; padding: 16px 24px; display: flex; flex-direction: column; align-items: center; }
    .brand-header-inner { max-width: 700px; width: 100%; display: flex; align-items: center; gap: 14px; }
    .brand-logo-mark { display: flex; gap: 4px; }
    .brand-logo-mark span { display: block; width: 9px; border-radius: 2px; }
    .brand-logo-mark span:nth-child(1) { height: 20px; background: #82C112; }
    .brand-logo-mark span:nth-child(2) { height: 14px; background: #5b8e0d; align-self: flex-end; }
    .brand-logo-mark span:nth-child(3) { height: 17px; background: #82C112; align-self: flex-end; }
    .brand-text { color: #fff; }
    .brand-name { font-size: 17px; font-weight: 700; letter-spacing: .02em; }
    .brand-tagline { font-size: 12px; color: #aaa; margin-top: 2px; }

    /* Page layout */
    .page-wrap { max-width: 700px; margin: 32px auto 60px; padding: 0 16px; }

    /* Card */
    .card { background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 2px 12px rgba(0,0,0,.07); }

    /* Progress bar */
    .intake-progress { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
    .intake-progress-bar { flex: 1; height: 8px; background: #e8e8e8; border-radius: 4px; overflow: hidden; }
    .intake-progress-fill { height: 100%; background: #82C112; border-radius: 4px; transition: width .3s; }
    .intake-progress-text { font-size: 12px; color: #888; white-space: nowrap; font-weight: 600; }

    /* Section headers */
    .form-section-h { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: #82C112; margin: 22px 0 12px; padding-bottom: 6px; border-bottom: 1px solid #f0f0f0; }
    .form-section-h:first-child { margin-top: 0; }

    /* Fields */
    .field { margin-bottom: 14px; }
    .field label { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #555; margin-bottom: 4px; font-weight: 600; }
    .field label .opt { font-weight: 400; color: #aaa; text-transform: none; letter-spacing: 0; }
    input[type=text], input[type=email], input[type=tel], input[type=date], select, textarea {
      display: block; width: 100%; padding: 9px 12px; border: 1px solid #ddd; border-radius: 7px;
      font-size: 14px; font-family: inherit; color: #222; background: #fff; outline: none;
      transition: border-color .15s;
    }
    input:focus, select:focus, textarea:focus { border-color: #82C112; }
    input.invalid, select.invalid, textarea.invalid { border-color: #e53935; }
    textarea { min-height: 80px; resize: vertical; }

    /* Two-column grid for wider screens */
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
    .form-grid .field.full { grid-column: 1 / -1; }
    @media (max-width: 520px) { .form-grid { grid-template-columns: 1fr; } }

    /* Submit */
    .form-actions { margin-top: 24px; }
    .btn-submit { display: block; width: 100%; padding: 13px; background: #82C112; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; letter-spacing: .02em; transition: background .15s; }
    .btn-submit:hover:not(:disabled) { background: #5b8e0d; }
    .btn-submit:disabled { opacity: .6; cursor: not-allowed; }
    .form-error { margin-top: 10px; font-size: 13px; color: #c62828; min-height: 18px; }

    /* Spinner */
    .spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: spin .7s linear infinite; vertical-align: middle; margin-right: 6px; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* Success card */
    .success-card { text-align: center; padding: 40px 20px; display: none; }
    .success-icon { width: 60px; height: 60px; background: #e8f5e9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
    .success-icon svg { width: 32px; height: 32px; }
    .success-title { font-size: 22px; font-weight: 700; color: #222; margin-bottom: 10px; }
    .success-body { font-size: 15px; color: #555; line-height: 1.6; }
    .success-body strong { color: #82C112; }
  </style>
</head>
<body>

<div class="brand-header">
  <div class="brand-header-inner">
    <div class="brand-logo-mark">
      <span></span><span></span><span></span>
    </div>
    <div class="brand-text">
      <div class="brand-name">INNOVATE AgentEdge</div>
      <div class="brand-tagline">Onboarding Intake Form</div>
    </div>
  </div>
</div>

<div class="page-wrap">
  <div class="card" id="form-card">

    <div class="intake-progress">
      <div class="intake-progress-bar"><div class="intake-progress-fill" id="progress-fill" style="width:0%"></div></div>
      <div class="intake-progress-text" id="progress-text">0 of 12 required fields completed</div>
    </div>

    <form id="intake-form" novalidate>

      <!-- Contact Information -->
      <div class="form-section-h">Contact Information</div>
      <div class="form-grid">
        <div class="field">
          <label>Email Address</label>
          <input type="email" id="f-email" name="email" required placeholder="you@example.com">
        </div>
        <div class="field">
          <label>Full Name</label>
          <input type="text" id="f-full_name" name="full_name" required placeholder="First Last">
        </div>
        <div class="field">
          <label>Phone Number</label>
          <input type="tel" id="f-phone" name="phone" required placeholder="(843) 555-0100">
        </div>
        <div class="field">
          <label>Last 4 digits of SS# <span class="opt">(for payroll)</span></label>
          <input type="text" id="f-phone_last4" name="phone_last4" maxlength="4" pattern="[0-9]{4}" placeholder="e.g. 1234">
        </div>
      </div>

      <!-- License & Certifications -->
      <div class="form-section-h">License &amp; Certifications</div>
      <div class="form-grid">
        <div class="field">
          <label>Real Estate License #</label>
          <input type="text" id="f-license_number" name="license_number" required>
        </div>
        <div class="field">
          <label>License State</label>
          <input type="text" id="f-license_state" name="license_state" placeholder="e.g. SC, NC">
        </div>
        <div class="field">
          <label>License Expiration Date</label>
          <input type="date" id="f-license_exp" name="license_exp">
        </div>
        <div class="field">
          <label>NAR Member #</label>
          <input type="text" id="f-nar_number" name="nar_number" required>
        </div>
      </div>

      <!-- MLS Information -->
      <div class="form-section-h">MLS Information</div>
      <div class="form-grid">
        <div class="field">
          <label>MLS Board</label>
          <select id="f-mls_board" name="mls_board" required>
            <option value="">— Select —</option>
            <option value="CCAR">CCAR</option>
            <option value="Columbia MLS">Columbia MLS</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="field">
          <label>MLS ID #</label>
          <input type="text" id="f-mls_id" name="mls_id" placeholder="Your MLS member ID">
        </div>
      </div>

      <!-- INNOVATE Office -->
      <div class="form-section-h">INNOVATE Office</div>
      <div class="form-grid">
        <div class="field full">
          <label>Office Location</label>
          <select id="f-office_location" name="office_location" required>
            <option value="">— Select your office —</option>
            <option value="Myrtle Beach">Myrtle Beach</option>
            <option value="Surfside Beach">Surfside Beach</option>
            <option value="Pawleys Island">Pawleys Island</option>
            <option value="North Myrtle Beach">North Myrtle Beach</option>
            <option value="Conway">Conway</option>
            <option value="Little River">Little River</option>
            <option value="Longs/Loris">Longs/Loris</option>
            <option value="Columbia">Columbia</option>
            <option value="Lexington">Lexington</option>
            <option value="Chapin/Newberry">Chapin/Newberry</option>
            <option value="Florence">Florence</option>
            <option value="Sumter">Sumter</option>
            <option value="Augusta">Augusta</option>
            <option value="Charlotte">Charlotte</option>
            <option value="Wilmington">Wilmington</option>
            <option value="Greenville">Greenville</option>
            <option value="Remote">Remote</option>
          </select>
        </div>
      </div>

      <!-- Personal Information -->
      <div class="form-section-h">Personal Information</div>
      <div class="form-grid">
        <div class="field">
          <label>Birthday</label>
          <input type="date" id="f-birthday" name="birthday" required>
        </div>
        <div class="field">
          <label>Spouse/Partner Name <span class="opt">(optional)</span></label>
          <input type="text" id="f-spouse_name" name="spouse_name" placeholder="Optional">
        </div>
        <div class="field full">
          <label>Mailing Address</label>
          <textarea id="f-mailing_address" name="mailing_address" rows="3" required placeholder="Street, City, State, ZIP"></textarea>
        </div>
        <div class="field">
          <label>T-Shirt Size</label>
          <select id="f-tshirt_size" name="tshirt_size">
            <option value="">— Select —</option>
            <option value="S">S</option>
            <option value="M">M</option>
            <option value="L">L</option>
            <option value="XL">XL</option>
            <option value="2XL">2XL</option>
            <option value="3XL">3XL</option>
          </select>
        </div>
        <div class="field">
          <label>Are you a military veteran?</label>
          <select id="f-is_military" name="is_military">
            <option value="">— Select —</option>
            <option value="yes">Yes</option>
            <option value="no">No</option>
          </select>
        </div>
        <div class="field">
          <label>Are you a first responder?</label>
          <select id="f-first_responder" name="first_responder">
            <option value="">— Select —</option>
            <option value="yes">Yes</option>
            <option value="no">No</option>
          </select>
        </div>
        <div class="field">
          <label>Are you a teacher or educator?</label>
          <select id="f-is_teacher" name="is_teacher">
            <option value="">— Select —</option>
            <option value="yes">Yes</option>
            <option value="no">No</option>
          </select>
        </div>
        <div class="field">
          <label>Referring Agent <span class="opt">(optional)</span></label>
          <input type="text" id="f-referring_agent" name="referring_agent" placeholder="Who referred you?">
        </div>
        <div class="field">
          <label>Languages Spoken <span class="opt">(optional)</span></label>
          <input type="text" id="f-languages" name="languages" placeholder="e.g. English, Spanish">
        </div>
      </div>

      <!-- Emergency Contact -->
      <div class="form-section-h">Emergency Contact</div>
      <div class="form-grid">
        <div class="field">
          <label>Emergency Contact Name</label>
          <input type="text" id="f-emergency_name" name="emergency_name" required>
        </div>
        <div class="field">
          <label>Emergency Contact Phone</label>
          <input type="tel" id="f-emergency_phone" name="emergency_phone" required placeholder="(843) 555-0100">
        </div>
      </div>

      <!-- Bio & Marketing -->
      <div class="form-section-h">Bio &amp; Marketing</div>
      <div class="field">
        <label>Bio</label>
        <textarea id="f-bio" name="bio" rows="6" required placeholder="Tell us about yourself — this will appear on your agent profile"></textarea>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn-submit" id="submit-btn">Submit Intake Form</button>
        <div class="form-error" id="form-error"></div>
      </div>

    </form>

    <!-- Success state (hidden until submission) -->
    <div class="success-card" id="success-card">
      <div class="success-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="#82C112" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
      </div>
      <div class="success-title" id="success-title">Thank You!</div>
      <div class="success-body">Your intake form has been received.<br><strong>The INNOVATE operations team will be in touch shortly</strong> to complete your onboarding.</div>
    </div>

  </div>
</div>

<script>
(function () {
  var REQUIRED_IDS = ['email','full_name','phone','license_number','nar_number','mls_board','office_location','birthday','mailing_address','emergency_name','emergency_phone','bio'];
  var TOTAL = REQUIRED_IDS.length;

  function el(id) { return document.getElementById(id); }

  function calcProgress() {
    var done = 0;
    REQUIRED_IDS.forEach(function(id) {
      var node = el('f-' + id);
      if (node && node.value && node.value.trim() !== '') done++;
    });
    return done;
  }

  function updateProgress() {
    var done = calcProgress();
    var pct = Math.round((done / TOTAL) * 100);
    el('progress-fill').style.width = pct + '%';
    el('progress-text').textContent = done + ' of ' + TOTAL + ' required fields completed';
  }

  // Client-side validation: mark required fields red on blur if empty
  REQUIRED_IDS.forEach(function(id) {
    var node = el('f-' + id);
    if (!node) return;
    node.addEventListener('blur', function() {
      if (node.value.trim() === '') {
        node.classList.add('invalid');
      } else {
        node.classList.remove('invalid');
      }
    });
    node.addEventListener('input', function() {
      if (node.value.trim() !== '') node.classList.remove('invalid');
      updateProgress();
    });
    node.addEventListener('change', function() {
      if (node.value.trim() !== '') node.classList.remove('invalid');
      updateProgress();
    });
  });

  // Wire up non-required fields to update progress too
  document.querySelectorAll('#intake-form input, #intake-form select, #intake-form textarea').forEach(function(node) {
    node.addEventListener('input', updateProgress);
    node.addEventListener('change', updateProgress);
  });

  el('intake-form').addEventListener('submit', function(e) {
    e.preventDefault();

    // Validate required fields
    var missing = [];
    REQUIRED_IDS.forEach(function(id) {
      var node = el('f-' + id);
      if (!node || node.value.trim() === '') {
        if (node) node.classList.add('invalid');
        missing.push(id);
      }
    });
    if (missing.length > 0) {
      el('form-error').textContent = 'Please fill in all required fields before submitting.';
      // Scroll to first invalid field
      var first = document.querySelector('.invalid');
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    // Validate email format
    var emailNode = el('f-email');
    var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRe.test(emailNode.value.trim())) {
      emailNode.classList.add('invalid');
      el('form-error').textContent = 'Please enter a valid email address.';
      emailNode.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }

    var btn = el('submit-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Submitting…';
    el('form-error').textContent = '';

    var payload = {
      email:           el('f-email').value.trim(),
      full_name:       el('f-full_name').value.trim(),
      phone:           el('f-phone').value.trim(),
      phone_last4:     el('f-phone_last4').value.trim(),
      license_number:  el('f-license_number').value.trim(),
      license_state:   el('f-license_state').value.trim(),
      license_exp:     el('f-license_exp').value.trim(),
      nar_number:      el('f-nar_number').value.trim(),
      mls_board:       el('f-mls_board').value.trim(),
      mls_id:          el('f-mls_id').value.trim(),
      office_location: el('f-office_location').value.trim(),
      birthday:        el('f-birthday').value.trim(),
      mailing_address: el('f-mailing_address').value.trim(),
      spouse_name:     el('f-spouse_name').value.trim(),
      tshirt_size:     el('f-tshirt_size').value.trim(),
      is_military:     el('f-is_military').value.trim(),
      first_responder: el('f-first_responder').value.trim(),
      is_teacher:      el('f-is_teacher').value.trim(),
      referring_agent: el('f-referring_agent').value.trim(),
      languages:       el('f-languages').value.trim(),
      emergency_name:  el('f-emergency_name').value.trim(),
      emergency_phone: el('f-emergency_phone').value.trim(),
      bio:             el('f-bio').value.trim()
    };

    fetch('api/intake_public.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.ok === true) {
        // Hide form, show thank-you
        el('intake-form').style.display = 'none';
        el('progress-fill').parentElement.parentElement.style.display = 'none';
        var nameVal = payload.full_name || 'Agent';
        el('success-title').textContent = 'Thank You, ' + nameVal + '!';
        el('success-card').style.display = 'block';
      } else {
        btn.disabled = false;
        btn.textContent = 'Submit Intake Form';
        el('form-error').textContent = d.error || 'Submission failed. Please try again.';
      }
    })
    .catch(function() {
      btn.disabled = false;
      btn.textContent = 'Submit Intake Form';
      el('form-error').textContent = 'Network error. Please check your connection and try again.';
    });
  });

  updateProgress();
})();
</script>

</body>
</html>
