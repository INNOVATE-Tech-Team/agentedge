<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Complete Your Profile — INNOVATE AgentEdge</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f5f6; min-height: 100vh; }

    .brand-header { background: #111; padding: 16px 24px; display: flex; flex-direction: column; align-items: center; }
    .brand-header-inner { max-width: 560px; width: 100%; display: flex; align-items: center; gap: 14px; }
    .brand-logo-mark { display: flex; gap: 4px; }
    .brand-logo-mark span { display: block; width: 9px; border-radius: 2px; }
    .brand-logo-mark span:nth-child(1) { height: 20px; background: #82C112; }
    .brand-logo-mark span:nth-child(2) { height: 14px; background: #5b8e0d; align-self: flex-end; }
    .brand-logo-mark span:nth-child(3) { height: 17px; background: #82C112; align-self: flex-end; }
    .brand-text { color: #fff; }
    .brand-name { font-size: 17px; font-weight: 700; letter-spacing: .02em; }
    .brand-tagline { font-size: 12px; color: #aaa; margin-top: 2px; }

    .page-wrap { max-width: 560px; margin: 32px auto 60px; padding: 0 16px; }
    .card { background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 2px 12px rgba(0,0,0,.07); }

    h1 { font-size: 19px; font-weight: 800; color: #111; margin-bottom: 6px; }
    .sub { font-size: 13px; color: #666; margin-bottom: 22px; line-height: 1.5; }

    .field { margin-bottom: 16px; }
    .field label { display: block; font-size: 12px; font-weight: 700; color: #333; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .03em; }
    .field input, .field textarea {
      width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 7px;
      font-size: 14px; font-family: inherit; background: #fafafa;
    }
    .field input:focus, .field textarea:focus { outline: 2px solid #82C112; border-color: #82C112; background: #fff; }
    .field textarea { min-height: 90px; resize: vertical; }

    .err { font-size: 13px; color: #c0392b; margin-bottom: 14px; display: none; }
    .btn-save {
      width: 100%; padding: 12px; background: #82C112; color: #111; border: none;
      border-radius: 8px; font-size: 14px; font-weight: 800; cursor: pointer;
    }
    .btn-save:hover { background: #6da00f; }
    .btn-save:disabled { opacity: .6; cursor: default; }

    .state-msg { text-align: center; padding: 20px 0; color: #666; font-size: 14px; }
    .state-msg.done { color: #3a6b1a; font-weight: 700; }
  </style>
</head>
<body>
  <div class="brand-header">
    <div class="brand-header-inner">
      <div class="brand-logo-mark"><span></span><span></span><span></span></div>
      <div class="brand-text">
        <div class="brand-name">INNOVATE AgentEdge</div>
        <div class="brand-tagline">Complete Your Profile</div>
      </div>
    </div>
  </div>

  <div class="page-wrap">
    <section class="card" id="card">
      <div class="state-msg" id="loading">Loading…</div>
    </section>
  </div>

  <script>
    const token = new URLSearchParams(location.search).get('token') || '';
    const card  = document.getElementById('card');

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    const INPUT_TYPE = {
      birthday: 'date',
      phone: 'tel',
      emergency_phone: 'tel',
      bio: 'textarea',
    };

    function render(name, missing) {
      if (!missing.length) {
        card.innerHTML = '<div class="state-msg done">✓ Your profile is already complete' + (name ? ', ' + esc(name.split(' ')[0]) : '') + '! Nothing left to fill in.</div>';
        return;
      }
      const greeting = name ? esc(name.split(' ')[0]) + ', a' : 'A';
      card.innerHTML = `
        <h1>${greeting} few things are missing from your profile</h1>
        <div class="sub">Fill in the ${missing.length} item${missing.length === 1 ? '' : 's'} below — this only shows what's still needed, everything else on file stays as-is.</div>
        <div class="err" id="err"></div>
        <form id="form">
          ${missing.map(f => `
            <div class="field">
              <label for="f-${esc(f.key)}">${esc(f.label)}</label>
              ${INPUT_TYPE[f.key] === 'textarea'
                ? `<textarea id="f-${esc(f.key)}" name="${esc(f.key)}"></textarea>`
                : `<input id="f-${esc(f.key)}" name="${esc(f.key)}" type="${INPUT_TYPE[f.key] || 'text'}">`}
            </div>
          `).join('')}
          <button type="submit" class="btn-save" id="save-btn">Save</button>
        </form>
      `;
      document.getElementById('form').addEventListener('submit', onSubmit);
    }

    function onSubmit(e) {
      e.preventDefault();
      const btn = document.getElementById('save-btn');
      const err = document.getElementById('err');
      err.style.display = 'none';
      btn.disabled = true; btn.textContent = 'Saving…';

      const fields = {};
      new FormData(e.target).forEach((v, k) => { fields[k] = v; });

      fetch('api/complete_profile_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token, fields }),
      })
        .then(r => r.json())
        .then(d => {
          if (!d.ok) {
            err.textContent = d.error || 'Something went wrong.';
            err.style.display = '';
            btn.disabled = false; btn.textContent = 'Save';
            return;
          }
          card.innerHTML = '<div class="state-msg done">✓ Thanks — your profile is all set.</div>';
        })
        .catch(() => {
          err.textContent = 'Network error. Please try again.';
          err.style.display = '';
          btn.disabled = false; btn.textContent = 'Save';
        });
    }

    if (!token) {
      card.innerHTML = '<div class="state-msg">This link is missing its token.</div>';
    } else {
      fetch('api/complete_profile_action.php?token=' + encodeURIComponent(token))
        .then(r => r.json())
        .then(d => {
          if (!d.ok) {
            card.innerHTML = '<div class="state-msg">' + esc(d.error || 'This link is invalid.') + '</div>';
            return;
          }
          render(d.name, d.missing || []);
        })
        .catch(() => {
          card.innerHTML = '<div class="state-msg">Could not load this form. Please try again later.</div>';
        });
    }
  </script>
</body>
</html>
