<?php
// Public event registration page — no AgentEdge login required. Reachable only
// via the unguessable per-event token; see api/ep_public_register.php for the
// data endpoint and its access checks.
$token = trim($_GET['t'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Event Registration — INNOVATE</title>
  <style>
    * { box-sizing:border-box; }
    body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#f5f6f8; color:#222; }
    .wrap { max-width:520px; margin:0 auto; padding:40px 20px; }
    .brand { font-size:13px; font-weight:800; color:#5b8e0d; letter-spacing:.04em; margin-bottom:18px; }
    .card { background:#fff; border-radius:10px; border:1px solid #e5e7eb; padding:28px; overflow:hidden; }
    .hero { height:220px; margin:-28px -28px 20px; background-size:cover; background-position:center; }
    h1 { font-size:22px; margin:0 0 8px; }
    .meta { font-size:13px; color:#555; line-height:1.7; margin-bottom:16px; }
    .desc { font-size:13px; color:#444; line-height:1.6; margin-bottom:20px; }
    .agenda-title { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#444; margin:0 0 10px; }
    .agenda-item { border-left:3px solid #82C112; padding:6px 12px; margin-bottom:8px; background:#fafffa; border-radius:0 6px 6px 0; }
    .agenda-time { font-size:11px; font-weight:800; color:#5b8e0d; }
    .agenda-name { font-size:13px; font-weight:700; color:#222; }
    .agenda-meta { font-size:11px; color:#888; margin-top:2px; }
    .agenda-grid { display:grid; gap:8px; margin-bottom:8px; }
    .agenda-col-header { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:#888; text-align:center; margin-bottom:2px; }
    .agenda-cell { border-left:3px solid #82C112; padding:6px 10px; background:#fafffa; border-radius:0 6px 6px 0; min-height:36px; }
    .agenda-cell.empty { border-left-color:#e5e7eb; background:#fafafa; color:#ccc; font-size:11px; display:flex; align-items:center; justify-content:center; }
    .agenda-slot-time { font-size:11px; font-weight:800; color:#5b8e0d; margin-bottom:4px; }
    .stay-card { border-left:3px solid #3b82f6; padding:10px 12px; margin-bottom:16px; background:#f5f9ff; border-radius:0 6px 6px 0; }
    .stay-name { font-size:13px; font-weight:700; color:#222; }
    .stay-book { display:inline-block; margin-top:6px; font-size:12px; font-weight:700; color:#1d4ed8; }
    .rec-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:10px; margin-bottom:16px; }
    .rec-card { border:1px solid #e5e7eb; border-radius:8px; padding:10px; }
    .rec-badge { display:inline-block; padding:2px 7px; border-radius:3px; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; margin-bottom:5px; }
    .rec-badge-food       { background:#fffbeb; color:#92400e; }
    .rec-badge-attraction { background:#eef5e8; color:#5b8e0d; }
    .rec-badge-nightlife  { background:#f5f3ff; color:#5b21b6; }
    .rec-badge-shopping   { background:#eff6ff; color:#1d4ed8; }
    .rec-badge-other      { background:#f3f4f6; color:#374151; }
    .rec-name { font-size:12px; font-weight:700; color:#222; }
    .rec-desc { font-size:11px; color:#666; margin-top:2px; }
    .field { margin-bottom:14px; }
    .field label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#888; margin-bottom:4px; }
    .field input { width:100%; padding:10px 12px; border:1px solid #ccc; border-radius:6px; font-size:14px; }
    .field input:focus { outline:2px solid #82C112; border-color:#82C112; }
    .hp-field { position:absolute; left:-9999px; top:-9999px; }
    .btn-primary { width:100%; padding:12px; background:#82C112; color:#000; border:none; border-radius:6px; font-weight:800; font-size:14px; cursor:pointer; }
    .btn-primary:hover { background:#5b8e0d; color:#fff; }
    .btn-primary:disabled { opacity:.6; cursor:default; }
    .msg { padding:14px; border-radius:6px; font-size:13px; margin-bottom:16px; }
    .msg-error { background:#fef2f2; color:#b91c1c; }
    .msg-success { background:#f0fdf4; color:#15803d; }
    .loading { text-align:center; color:#999; padding:30px 0; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="brand">INNOVATE · AgentEdge</div>
    <div class="card" id="card"><div class="loading">Loading event…</div></div>
  </div>

  <script>
  (function () {
    var TOKEN = <?= json_encode($token) ?>;
    var card = document.getElementById('card');

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]);}); }
    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function parseDate(s) { var p = s.split('-'); return new Date(+p[0], +p[1]-1, +p[2]); }
    function formatRange(start, end) {
      var s = parseDate(start);
      var out = MONTHS[s.getMonth()] + ' ' + s.getDate() + ', ' + s.getFullYear();
      if (end && end !== start) { var e = parseDate(end); out += ' – ' + MONTHS[e.getMonth()] + ' ' + e.getDate() + ', ' + e.getFullYear(); }
      return out;
    }

    if (!TOKEN) {
      card.innerHTML = '<div class="msg msg-error">This registration link is invalid.</div>';
      return;
    }

    fetch('api/ep_public_register.php?t=' + encodeURIComponent(TOKEN))
      .then(function (r) { if (!r.ok) throw new Error('not found'); return r.json(); })
      .then(function (d) { renderEvent(d.event); })
      .catch(function () {
        card.innerHTML = '<div class="msg msg-error">This event could not be found. The link may be incorrect or the event may no longer be available.</div>';
      });

    function fmtTime(t) {
      if (!t) return '';
      var p = t.split(':'); var h = +p[0]; var ap = h >= 12 ? 'PM' : 'AM'; var h12 = h % 12 || 12;
      return h12 + ':' + p[1] + ' ' + ap;
    }

    function renderAgenda(sessions) {
      if (!sessions || !sessions.length) return '';
      var tracks = [];
      sessions.forEach(function (s) { if (s.track && tracks.indexOf(s.track) === -1) tracks.push(s.track); });

      var slotOrder = [], slots = {};
      sessions.forEach(function (s) {
        var key = s.session_date + '|' + s.start_time + '|' + s.end_time;
        if (!slots[key]) { slots[key] = []; slotOrder.push(key); }
        slots[key].push(s);
      });

      var items = '';
      slotOrder.forEach(function (key) {
        var group = slots[key];
        var timeLabel = (fmtTime(group[0].start_time) + (group[0].end_time ? ' – ' + fmtTime(group[0].end_time) : '')) || group[0].session_date;
        var tracked = group.filter(function (s) { return s.track; });
        var plain   = group.filter(function (s) { return !s.track; });

        if (tracked.length) {
          var byTrack = {};
          tracked.forEach(function (s) { byTrack[s.track] = s; });
          items += '<div class="agenda-slot-time">' + esc(timeLabel) + '</div>'
            + '<div class="agenda-grid" style="grid-template-columns:repeat(' + tracks.length + ',1fr)">';
          tracks.forEach(function (tr) { items += '<div class="agenda-col-header">' + esc(tr) + '</div>'; });
          tracks.forEach(function (tr) {
            var s = byTrack[tr];
            if (!s) { items += '<div class="agenda-cell empty">—</div>'; return; }
            var meta = [s.room, s.speaker].filter(Boolean).join(' · ');
            items += '<div class="agenda-cell"><div class="agenda-name" style="font-size:12px">' + esc(s.title) + '</div>'
              + (meta ? '<div class="agenda-meta">' + esc(meta) + '</div>' : '') + '</div>';
          });
          items += '</div>';
        }
        plain.forEach(function (s) {
          var meta = [s.room, s.speaker].filter(Boolean).join(' · ');
          items += '<div class="agenda-item">'
            + '<div class="agenda-time">' + esc(timeLabel) + '</div>'
            + '<div class="agenda-name">' + esc(s.title) + '</div>'
            + (meta ? '<div class="agenda-meta">' + esc(meta) + '</div>' : '')
            + '</div>';
        });
      });
      return '<div class="agenda-title">Agenda</div>' + items;
    }

    function renderRoomBlock(rb) {
      if (!rb) return '';
      var meta = [rb.rate, rb.code ? 'Code: ' + rb.code : '', rb.cutoff ? 'Book by ' + rb.cutoff : ''].filter(Boolean).join(' · ');
      return '<div class="stay-card">'
        + '<div class="agenda-title" style="margin-bottom:6px">Where to Stay</div>'
        + '<div class="stay-name">' + esc(rb.hotel) + '</div>'
        + (meta ? '<div class="agenda-meta">' + esc(meta) + '</div>' : '')
        + (rb.url ? '<a class="stay-book" href="' + esc(rb.url) + '" target="_blank" rel="noopener">Book Now ↗</a>' : '')
        + '</div>';
    }

    var REC_LABELS = { food: 'Food & Dining', attraction: 'Attraction', nightlife: 'Nightlife', shopping: 'Shopping', other: 'Other' };
    function renderRecs(recs) {
      if (!recs || !recs.length) return '';
      var cards = recs.map(function (r) {
        return '<div class="rec-card">'
          + '<span class="rec-badge rec-badge-' + esc(r.category) + '">' + esc(REC_LABELS[r.category] || r.category) + '</span>'
          + '<div class="rec-name">' + esc(r.name) + '</div>'
          + (r.description ? '<div class="rec-desc">' + esc(r.description) + '</div>' : '')
          + (r.url ? '<div style="margin-top:4px"><a href="' + esc(r.url) + '" target="_blank" rel="noopener" style="font-size:11px;font-weight:700;color:#5b8e0d">Visit ↗</a></div>' : '')
          + '</div>';
      }).join('');
      return '<div class="agenda-title">Things to Do Nearby</div><div class="rec-grid">' + cards + '</div>';
    }

    function renderEvent(ev) {
      var hero = ev.image_url ? '<div class="hero" style="background-image:url(\'' + esc(ev.image_url) + '\')"></div>' : '';

      if (ev.status === 'cancelled') {
        card.innerHTML = hero + '<h1>' + esc(ev.title || 'Event') + '</h1><div class="msg msg-error">This event has been cancelled.</div>';
        return;
      }
      var full = ev.capacity != null && ev.registered >= ev.capacity;
      var html = hero + '<h1>' + esc(ev.title) + '</h1>'
        + '<div class="meta">' + esc(formatRange(ev.start_date, ev.end_date)) + (ev.start_time ? ' · ' + esc(ev.start_time) : '') + '<br>' + esc(ev.location || '') + '</div>'
        + (ev.description ? '<div class="desc">' + esc(ev.description).replace(/\n/g,'<br>') + '</div>' : '')
        + renderRoomBlock(ev.room_block)
        + renderRecs(ev.recommendations)
        + renderAgenda(ev.sessions);

      if (full) {
        html += '<div class="msg msg-error">Sorry, this event is at capacity.</div>';
        card.innerHTML = html;
        return;
      }

      html += '<div id="reg-msg"></div>'
        + '<form id="reg-form">'
        + '<div class="field"><label>Full Name *</label><input type="text" id="f-name" required></div>'
        + '<div class="field"><label>Email *</label><input type="email" id="f-email" required></div>'
        + '<div class="field"><label>Phone</label><input type="tel" id="f-phone"></div>'
        + '<div class="field"><label>Additional Guests</label><input type="number" id="f-guests" min="0" max="10" value="0"></div>'
        + '<input type="text" id="f-hp" class="hp-field" tabindex="-1" autocomplete="off">'
        + '<button type="submit" class="btn-primary" id="submit-btn">Register</button>'
        + '</form>';
      card.innerHTML = html;

      document.getElementById('reg-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('submit-btn');
        btn.disabled = true;
        btn.textContent = 'Registering…';
        fetch('api/ep_public_register.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            token: TOKEN,
            name: document.getElementById('f-name').value.trim(),
            email: document.getElementById('f-email').value.trim(),
            phone: document.getElementById('f-phone').value.trim(),
            guest_count: parseInt(document.getElementById('f-guests').value, 10) || 0,
            hp: document.getElementById('f-hp').value,
          }),
        }).then(function (r) { return r.json(); }).then(function (d) {
          if (d.ok) {
            card.innerHTML = hero + '<h1>' + esc(ev.title) + '</h1><div class="msg msg-success">You\'re registered! We look forward to seeing you.</div>';
          } else {
            btn.disabled = false;
            btn.textContent = 'Register';
            document.getElementById('reg-msg').innerHTML = '<div class="msg msg-error">' + esc(d.error || 'Something went wrong.') + '</div>';
          }
        }).catch(function () {
          btn.disabled = false;
          btn.textContent = 'Register';
          document.getElementById('reg-msg').innerHTML = '<div class="msg msg-error">Network error — please try again.</div>';
        });
      });
    }
  })();
  </script>
</body>
</html>
