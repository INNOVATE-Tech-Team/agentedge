<?php
// Strategic Intent (Attract & Empower) — public 5-year planning exercise.
// No login required; open link shared with BICs, Recruiters, and Market Center Leaders.
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Strategic Intent — INNOVATE</title>
  <style>
    * { box-sizing:border-box; }
    body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#f5f6f8; color:#222; }
    .wrap { max-width:720px; margin:0 auto; padding:40px 20px 80px; }
    .brand { font-size:13px; font-weight:800; color:#5b8e0d; letter-spacing:.04em; margin-bottom:6px; }
    h1 { font-size:26px; margin:0 0 8px; }
    .intro { font-size:14px; color:#555; line-height:1.6; margin-bottom:28px; }
    .card { background:#fff; border-radius:10px; border:1px solid #e5e7eb; padding:24px; margin-bottom:18px; }
    .section-title { font-size:15px; font-weight:800; color:#222; margin:0 0 4px; }
    .section-sub { font-size:12px; color:#888; margin:0 0 16px; line-height:1.5; }
    .field { margin-bottom:16px; }
    .field:last-child { margin-bottom:0; }
    .field label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#888; margin-bottom:5px; }
    .field input, .field select, .field textarea {
      width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:6px; font-size:14px; font-family:inherit; background:#fff;
    }
    .field textarea { resize:vertical; min-height:70px; line-height:1.5; }
    .card > textarea {
      width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:6px; font-size:14px; font-family:inherit;
      resize:vertical; min-height:90px; line-height:1.5;
    }
    .card > textarea:focus { outline:2px solid #82C112; border-color:#82C112; }
    .prompt-select {
      display:block; width:100%; margin-bottom:10px; padding:8px 10px; font-size:12.5px; font-family:inherit;
      color:#5b8e0d; background:#f6faf1; border:1px solid #cfe3bb; border-radius:6px; cursor:pointer;
    }
    .prompt-select:focus { outline:2px solid #82C112; border-color:#82C112; }
    .field input:focus, .field select:focus, .field textarea:focus { outline:2px solid #82C112; border-color:#82C112; }
    .row2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .row3 { display:grid; grid-template-columns:1fr 2fr; gap:14px; }
    .hp-field { position:absolute; left:-9999px; top:-9999px; }
    .milestone-card, .project-row { border:1px solid #e5e7eb; border-radius:8px; padding:16px; margin-bottom:12px; background:#fafbfc; position:relative; }
    .milestone-head { font-size:12px; font-weight:800; color:#5b8e0d; text-transform:uppercase; letter-spacing:.05em; margin-bottom:10px; }
    .remove-btn { position:absolute; top:12px; right:12px; background:none; border:none; color:#b91c1c; font-size:12px; font-weight:700; cursor:pointer; }
    .add-btn { display:inline-block; padding:8px 14px; background:#eef5e8; color:#5b8e0d; border:1px dashed #82C112; border-radius:6px; font-size:12px; font-weight:800; cursor:pointer; }
    .add-btn:hover { background:#e2f0d8; }
    .project-row { display:flex; gap:10px; align-items:center; padding:10px 16px; }
    .project-row input { flex:1; }
    .project-row .remove-btn { position:static; flex-shrink:0; }
    .btn-primary { width:100%; padding:14px; background:#82C112; color:#000; border:none; border-radius:6px; font-weight:800; font-size:15px; cursor:pointer; margin-top:8px; }
    .btn-primary:hover { background:#5b8e0d; color:#fff; }
    .btn-primary:disabled { opacity:.6; cursor:default; }
    .msg { padding:14px; border-radius:6px; font-size:13px; margin-bottom:16px; }
    .msg-error { background:#fef2f2; color:#b91c1c; }
    .msg-success { background:#f0fdf4; color:#15803d; }
    .success-card { text-align:center; padding:48px 24px; }
    .success-card h2 { margin:0 0 8px; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="brand">INNOVATE · Attract &amp; Empower</div>
    <h1>Strategic Intent</h1>
    <div class="intro">Whether you're a BIC, Recruiter, or Market Center Leader — take a few minutes to map out where you want to be with INNOVATE over the next several years. Be specific. This is your plan, not a quiz.</div>

    <div id="success" style="display:none">
      <div class="card success-card">
        <h2>Thank you.</h2>
        <p style="color:#555;font-size:14px">Your Strategic Intent has been submitted. Bring it with you to Attract &amp; Empower.</p>
      </div>
    </div>

    <form id="si-form">
      <div id="form-msg"></div>

      <div class="card">
        <div class="section-title">Who You Are</div>
        <div class="field"><label>Full Name *</label><input type="text" id="f-name" required></div>
        <div class="row2">
          <div class="field"><label>Email</label><input type="email" id="f-email"></div>
          <div class="field"><label>Market Center</label><input type="text" id="f-mc"></div>
        </div>
        <div class="field">
          <label>Your Role *</label>
          <select id="f-role" required>
            <option value="">Select one…</option>
            <option value="bic">BIC</option>
            <option value="recruiter">Recruiter</option>
            <option value="mcl">Market Center Leader</option>
          </select>
        </div>
      </div>

      <div class="card">
        <div class="section-title">Ultimate Strategic Intent</div>
        <div class="section-sub" id="vision-hint">Describe your ultimate vision — position, agent count, revenue, whatever's relevant to your role — over your timeframe.</div>
        <select class="prompt-select" data-target="f-vision">
          <option value="">Need a prompt? Pick one to add it below…</option>
          <option value="How many agents do you need to add this year?">How many agents do you need to add this year?</option>
          <option value="How many did you actually recruit last year?">How many did you actually recruit last year?</option>
          <option value="What's the monthly and weekly version of this goal?">What's the monthly and weekly version of this goal?</option>
        </select>
        <textarea id="f-vision" style="min-height:120px"></textarea>
      </div>

      <div class="card">
        <div class="section-title">Personal Reasons</div>
        <div class="section-sub">Why does this matter to you personally? (Financial reward, professional growth, personal fulfillment…)</div>
        <textarea id="f-personal"></textarea>
      </div>

      <div class="card">
        <div class="section-title">Timeframe</div>
        <div class="row3">
          <div class="field"><label>Years</label><input type="number" id="f-years" min="1" max="50" value="5"></div>
          <div class="field"><label>Why is that timeframe important?</label><input type="text" id="f-years-why"></div>
        </div>
      </div>

      <div class="card">
        <div class="section-title">Milestones</div>
        <div class="section-sub" id="milestone-hint">Break your timeframe into milestones. Include the metrics that matter for your role in each one.</div>
        <div id="milestones"></div>
        <button type="button" class="add-btn" id="add-milestone">+ Add Milestone</button>
      </div>

      <div class="card">
        <div class="section-title">Hurdles &amp; Risks</div>
        <div class="section-sub">What could get in the way of hitting this plan?</div>
        <textarea id="f-hurdles"></textarea>
      </div>

      <div class="card">
        <div class="section-title">Gaps</div>
        <div class="section-sub">What do you lack today that you must compensate for? (Talent, capital, systems, capacity…)</div>
        <select class="prompt-select" data-target="f-gaps">
          <option value="">Need a prompt? Pick one to add it below…</option>
          <option value="Where do you lose people — getting the meeting, or closing it?">Where do you lose people — getting the meeting, or closing it?</option>
          <option value="What's the one thing you need from Innovate to achieve this goal?">What's the one thing you need from Innovate to achieve this goal?</option>
          <option value="Which single driver, if you moved it 20%, changes the outcome most?">Which single driver, if you moved it 20%, changes the outcome most?</option>
          <option value="What's the most likely reason you'll miss — and what's your plan to change it now?">What's the most likely reason you'll miss — and what's your plan to change it now?</option>
        </select>
        <textarea id="f-gaps"></textarea>
      </div>

      <div class="card">
        <div class="section-title">Projects</div>
        <div class="section-sub">The key initiatives you need to run to get there.</div>
        <div id="projects"></div>
        <button type="button" class="add-btn" id="add-project">+ Add Project</button>
      </div>

      <div class="card">
        <div class="section-title">Reinforcement Plan</div>
        <div class="section-sub">Training, systems, financial reserves, KPIs — what locks this plan in?</div>
        <textarea id="f-reinforcement"></textarea>
      </div>

      <div class="card">
        <div class="section-title">Accountability</div>
        <div class="section-sub">How will you stay accountable to this plan? (Coaching org, check-ins, tools…)</div>
        <select class="prompt-select" data-target="f-accountability">
          <option value="">Need a prompt? Pick one to add it below…</option>
          <option value="How will you know each week whether you're ahead or behind — and where does that number live?">How will you know each week whether you're ahead or behind — and where does that number live?</option>
          <option value="What's your weekly number, and who's holding you to it?">What's your weekly number, and who's holding you to it?</option>
          <option value="Who else sees this number, and what happens when you're behind?">Who else sees this number, and what happens when you're behind?</option>
        </select>
        <textarea id="f-accountability"></textarea>
      </div>

      <input type="text" id="f-hp" class="hp-field" tabindex="-1" autocomplete="off">
      <button type="submit" class="btn-primary" id="submit-btn">Submit Strategic Intent</button>
    </form>
  </div>

  <script>
  (function () {
    var HINTS = {
      bic: {
        vision: 'e.g. how many agents you\'ll oversee, the compliance/risk posture of your market center, leadership you\'ll have developed underneath you.',
        milestone: 'For each milestone, think agent count under supervision, compliance systems in place, leadership bench strength.'
      },
      recruiter: {
        vision: 'e.g. agents recruited per year and cumulatively, your ideal agent profile, the pipeline/systems behind it.',
        milestone: 'For each milestone, think recruits closed, pipeline size, retention rate, sourcing channels built.'
      },
      mcl: {
        vision: 'e.g. production/GCI, agent count, staff, culture and reputation of your market center.',
        milestone: 'For each milestone, think agent count, staff, sales volume, GCI, net profit, culture/brand milestones.'
      }
    };
    var DEFAULT_VISION_HINT = 'Describe your ultimate vision — position, agent count, revenue, whatever\'s relevant to your role — over your timeframe.';
    var DEFAULT_MILESTONE_HINT = 'Break your timeframe into milestones. Include the metrics that matter for your role in each one.';

    Array.prototype.forEach.call(document.querySelectorAll('.prompt-select'), function (sel) {
      sel.addEventListener('change', function () {
        var question = sel.value;
        if (!question) return;
        var target = document.getElementById(sel.getAttribute('data-target'));
        target.value = target.value.trim() ? target.value.replace(/\s+$/, '') + '\n\n' + question + '\n' : question + '\n';
        sel.selectedIndex = 0;
        target.focus();
        target.setSelectionRange(target.value.length, target.value.length);
      });
    });

    document.getElementById('f-role').addEventListener('change', function (e) {
      var h = HINTS[e.target.value];
      document.getElementById('vision-hint').textContent = h ? h.vision : DEFAULT_VISION_HINT;
      document.getElementById('milestone-hint').textContent = h ? h.milestone : DEFAULT_MILESTONE_HINT;
    });

    var milestonesEl = document.getElementById('milestones');
    var milestoneCount = 0;
    function addMilestone() {
      milestoneCount++;
      var n = milestoneCount;
      var div = document.createElement('div');
      div.className = 'milestone-card';
      div.dataset.idx = n;
      div.innerHTML =
        '<button type="button" class="remove-btn" data-remove>Remove</button>' +
        '<div class="milestone-head">Milestone ' + n + '</div>' +
        '<div class="row3">' +
          '<div class="field"><label>Year</label><input type="text" class="m-year" placeholder="e.g. Dec 31, 2027"></div>' +
          '<div class="field"><label>Milestone Name</label><input type="text" class="m-name" placeholder="e.g. Regional Player"></div>' +
        '</div>' +
        '<div class="field"><label>Key Metrics</label><textarea class="m-metrics" placeholder="# of agents, staff, sales volume, GCI, net profit…"></textarea></div>' +
        '<div class="field"><label>Marketing / Initiatives</label><textarea class="m-marketing"></textarea></div>' +
        '<div class="field"><label>Systems &amp; Operations</label><textarea class="m-systems"></textarea></div>' +
        '<div class="field"><label>Accomplishments &amp; Reputation</label><textarea class="m-accomplishments"></textarea></div>';
      milestonesEl.appendChild(div);
      div.querySelector('[data-remove]').addEventListener('click', function () { div.remove(); });
    }
    document.getElementById('add-milestone').addEventListener('click', addMilestone);
    addMilestone();

    var projectsEl = document.getElementById('projects');
    function addProject() {
      var div = document.createElement('div');
      div.className = 'project-row';
      div.innerHTML = '<input type="text" class="p-name" placeholder="e.g. Agent Accelerator Program">' +
        '<button type="button" class="remove-btn" data-remove>Remove</button>';
      projectsEl.appendChild(div);
      div.querySelector('[data-remove]').addEventListener('click', function () { div.remove(); });
    }
    document.getElementById('add-project').addEventListener('click', addProject);
    addProject(); addProject(); addProject();

    document.getElementById('si-form').addEventListener('submit', function (e) {
      e.preventDefault();
      var role = document.getElementById('f-role').value;
      var name = document.getElementById('f-name').value.trim();
      var msgEl = document.getElementById('form-msg');
      if (!name) { msgEl.innerHTML = '<div class="msg msg-error">Name is required.</div>'; return; }
      if (!role) { msgEl.innerHTML = '<div class="msg msg-error">Please select your role.</div>'; return; }

      var milestones = Array.prototype.map.call(milestonesEl.querySelectorAll('.milestone-card'), function (card) {
        return {
          year: card.querySelector('.m-year').value.trim(),
          name: card.querySelector('.m-name').value.trim(),
          metrics: card.querySelector('.m-metrics').value.trim(),
          marketing: card.querySelector('.m-marketing').value.trim(),
          systems: card.querySelector('.m-systems').value.trim(),
          accomplishments: card.querySelector('.m-accomplishments').value.trim()
        };
      });
      var projects = Array.prototype.map.call(projectsEl.querySelectorAll('.p-name'), function (input) {
        return input.value.trim();
      }).filter(Boolean);

      var btn = document.getElementById('submit-btn');
      btn.disabled = true;
      btn.textContent = 'Submitting…';

      fetch('api/strategic_intent_submit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: name,
          email: document.getElementById('f-email').value.trim(),
          market_center: document.getElementById('f-mc').value.trim(),
          role: role,
          vision: document.getElementById('f-vision').value.trim(),
          personal_reasons: document.getElementById('f-personal').value.trim(),
          timeframe_years: parseInt(document.getElementById('f-years').value, 10) || 5,
          timeframe_why: document.getElementById('f-years-why').value.trim(),
          milestones: milestones,
          hurdles: document.getElementById('f-hurdles').value.trim(),
          gaps: document.getElementById('f-gaps').value.trim(),
          projects: projects,
          reinforcement: document.getElementById('f-reinforcement').value.trim(),
          accountability: document.getElementById('f-accountability').value.trim(),
          hp: document.getElementById('f-hp').value
        })
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.ok) {
          document.getElementById('si-form').style.display = 'none';
          document.getElementById('success').style.display = 'block';
          window.scrollTo(0, 0);
        } else {
          btn.disabled = false;
          btn.textContent = 'Submit Strategic Intent';
          msgEl.innerHTML = '<div class="msg msg-error">' + (d.error || 'Something went wrong.').replace(/[&<>"]/g, function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]);}) + '</div>';
        }
      }).catch(function () {
        btn.disabled = false;
        btn.textContent = 'Submit Strategic Intent';
        msgEl.innerHTML = '<div class="msg msg-error">Network error — please try again.</div>';
      });
    });
  })();
  </script>
</body>
</html>
