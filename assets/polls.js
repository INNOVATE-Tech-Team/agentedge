/* Polls runner — port of the spend-100 prototype's UI and drag-and-drop,
   driven by poll data (window.POLL_CFG, rendered by poll.php) instead of
   hard-coded categories. Storage and identity are server-side only:
   this file never sends a name and never sees anyone else's answers. */
(function () {
  'use strict';
  var CFG = window.POLL_CFG;
  var root = document.getElementById('poll-root');
  var stage = document.getElementById('poll-stage');
  if (!CFG || !root) return;

  var steps = CFG.steps;
  var S = {
    i: 0,
    alloc: {},    // stepKey -> {optKey: n}
    picks: {},    // stepKey -> {optKey: true}
    phase: CFG.voted ? 'done' : 'step',
    saving: false,
    error: '',
    mine: CFG.myAnswers || null,   // saved answers once submitted
    agg: null
  };
  steps.forEach(function (st) {
    if (st.type === 'allocate') { S.alloc[st.key] = {}; st.options.forEach(function (o) { S.alloc[st.key][o.key] = 0; }); }
    else S.picks[st.key] = {};
  });

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function cur() { return steps[S.i]; }
  function used(st) { var a = S.alloc[st.key], n = 0; for (var k in a) n += a[k]; return n; }
  function left(st) { return st.bills - used(st); }
  function currentAnswers() {
    var out = {};
    steps.forEach(function (st) {
      if (st.type === 'allocate') out[st.key] = { alloc: Object.assign({}, S.alloc[st.key]) };
      else out[st.key] = { picks: st.options.map(function (o) { return o.key; }).filter(function (k) { return S.picks[st.key][k]; }) };
    });
    return out;
  }

  /* ---------- views ---------- */
  function banner() {
    return CFG.preview ? '<div class="poll-preview-banner">Preview only, not saving. ' + esc(CFG.previewWhy || '') + '</div>' : '';
  }
  function topbar(activeIdx) {
    var pills = steps.map(function (st, i) {
      return '<span class="' + (i === activeIdx ? 'on' : '') + '">' + (i + 1) + ' · ' + esc(st.pill || st.title) + '</span>';
    }).join('');
    return '<div class="poll-top"><div class="poll-brand"><i></i>' + esc(CFG.header) + '</div>' +
      (activeIdx >= 0 ? '<div class="poll-steps">' + pills + '</div>' : '') + '<div class="poll-sp"></div></div>';
  }
  function actionBtn(st, last) {
    if (!last) return '<button class="poll-btn" data-act="next" ' + (st.type === 'allocate' && left(st) ? 'disabled' : '') + '>Next →</button>';
    var disabled = (st.type === 'allocate' && left(st)) || S.saving;
    return '<button class="poll-btn" data-act="submit" ' + (disabled ? 'disabled' : '') + '>' + (S.saving ? 'Sending…' : 'Submit') + '</button>';
  }
  function bills(n, from, alt) {
    var h = '';
    for (var k = 0; k < n; k++) h += '<img class="poll-bill" data-from="' + esc(from) + '" draggable="false" src="' + esc(CFG.billUrl) + '" alt="' + esc(alt) + '">';
    return h;
  }
  function vAllocate(st, last) {
    var l = left(st), val = st.value;
    var cards = st.options.map(function (o) {
      var n = S.alloc[st.key][o.key];
      return '<section class="poll-card ' + (n ? 'has' : '') + '" data-zone="' + esc(o.key) + '">' +
        '<div class="poll-ch"><h3>' + esc(o.label) + '</h3><span class="poll-amt">$' + (n * val) + '</span></div>' +
        '<div class="poll-pile">' + bills(n, o.key, '$' + val + ' on ' + o.label) + '</div>' +
        '<div class="poll-adj"><button data-act="minus" data-o="' + esc(o.key) + '" aria-label="Take $' + val + ' off ' + esc(o.label) + '" ' + (n ? '' : 'disabled') + '>− $' + val + '</button>' +
        '<button data-act="plus" data-o="' + esc(o.key) + '" aria-label="Add $' + val + ' to ' + esc(o.label) + '" ' + (l ? '' : 'disabled') + '>+ $' + val + '</button></div></section>';
    }).join('');
    var wallet = '<aside class="poll-wallet" data-zone="wallet"><div class="poll-wl-h">Your $' + (st.bills * val) + '</div>' +
      '<div class="poll-wl-s">' + l + ' of ' + st.bills + ' bills left</div><div class="poll-stack">' + bills(l, 'wallet', 'Innovate Dollar $' + val + ' bill') + '</div>' +
      (l === 0 ? '<div class="poll-empty">All spent! Drag a bill back here to take it off a box.</div>' : '') + '</aside>';
    return topbar(S.i) + errBox() +
      '<div class="poll-top" style="margin-top:-6px">' + (S.i > 0 ? '<button class="poll-btn ghost" data-act="back">← Back</button>' : '') +
      '<div class="poll-chip">' + (l ? '$' + (l * val) + ' left to spend' : 'All $' + (st.bills * val) + ' spent ✓') + '</div><div class="poll-sp"></div>' + actionBtn(st, last) + '</div>' +
      '<p class="poll-eyebrow">' + esc(CFG.eyebrow) + '</p><h1 class="poll-h1">' + esc(st.title) + '</h1><p class="poll-lede">' + esc(st.body) + '</p>' +
      '<div class="poll-main-grid"><div class="poll-cards">' + cards + '</div>' + wallet + '</div>' +
      '<p class="poll-hint">Tip: you can also use the “+ $' + val + '” and “− $' + val + '” buttons on each box.</p>';
  }
  function vMulti(st, last) {
    var opts = st.options.map(function (o) {
      var on = !!S.picks[st.key][o.key];
      return '<button class="poll-opt ' + (on ? 'on' : '') + '" role="checkbox" aria-checked="' + on + '" data-act="pick" data-o="' + esc(o.key) + '"><span class="box">✓</span><h3>' + esc(o.label) + '</h3><p>' + esc(o.description) + '</p></button>';
    }).join('');
    return topbar(S.i) + errBox() +
      '<div class="poll-top" style="margin-top:-6px">' + (S.i > 0 ? '<button class="poll-btn ghost" data-act="back">← Back</button>' : '') + '<div class="poll-sp"></div>' + actionBtn(st, last) + '</div>' +
      '<p class="poll-eyebrow">What happens next</p><h1 class="poll-h1">' + esc(st.title) + '</h1><p class="poll-lede">' + esc(st.body) + '</p>' +
      '<div class="poll-opts">' + opts + '</div>' +
      (st.notice ? '<div class="poll-notice">' + esc(st.notice) + '</div>' : '') + actionBtn(st, last) +
      '<p class="poll-foot">' + esc(CFG.footer) + '</p>';
  }
  function errBox() { return S.error ? '<div class="poll-error" role="alert">' + esc(S.error) + '</div>' : ''; }

  function answersText(ans) {
    var lines = [CFG.me], picks = [];
    steps.forEach(function (st) {
      var a = ans[st.key] || {}, label = {};
      st.options.forEach(function (o) { label[o.key] = o.label; });
      if (st.type === 'allocate') {
        Object.keys(a.alloc || {}).filter(function (k) { return a.alloc[k] > 0; })
          .sort(function (x, y) { return a.alloc[y] - a.alloc[x]; })
          .forEach(function (k) { lines.push('$' + (a.alloc[k] * st.value) + ' on ' + label[k]); });
      } else (a.picks || []).forEach(function (k) { picks.push(label[k]); });
    });
    lines.push('Next steps: ' + (picks.length ? picks.join(', ') : 'none picked'));
    return lines.join('\n');
  }
  function chartHTML() {
    if (CFG.preview) return '';
    if (!S.agg) return '<p class="poll-hint">Loading everyone’s results…</p>';
    var a = S.agg, h = '';
    a.steps.forEach(function (st) {
      var isAlloc = st.type === 'allocate';
      var vals = st.options.map(function (o) { return isAlloc ? o.dollars : o.people; });
      var max = Math.max(isAlloc ? 20 : 1, Math.max.apply(null, vals));
      if (isAlloc) h += '<p class="poll-eyebrow" style="margin:6px 0 8px">Where everyone’s $ is going · ' + a.voters + ' ' + (a.voters === 1 ? 'person' : 'people') + ' so far</p>';
      else h += '<p class="poll-eyebrow" style="margin:18px 0 8px">' + esc(st.title) + '</p>';
      h += st.options.map(function (o, i) {
        return '<div class="poll-trow"><div>' + esc(o.label) + '</div><div class="poll-tbar"><b style="width:' + (vals[i] / max * 100) + '%"></b></div><div class="poll-tval">' + (isAlloc ? '$' + o.dollars : o.people) + '</div></div>';
      }).join('');
    });
    return h;
  }
  function vDone() {
    var ans = S.mine || currentAnswers();
    var first = (CFG.me || '').split(' ')[0];
    return topbar(-1) + banner() + '<p class="poll-eyebrow">' + esc(CFG.thanksEyebrow) + '</p><h1 class="poll-h1">Thank you' + (first ? ', ' + esc(first) : '') + '!</h1>' +
      '<div class="poll-panel">' +
      (CFG.preview ? '<div class="poll-note">Preview only. Nothing was saved. This is what an agent sees after submitting.</div>' : '<div class="poll-note">Got it. Your votes are in and locked. ✓</div>') +
      '<pre class="poll-sum">' + esc(answersText(ans)) + '</pre><div id="poll-chart">' + chartHTML() + '</div>' +
      '<p class="poll-foot" style="margin-top:18px">' + esc(CFG.footer) + '</p>' +
      '<p style="margin-top:14px"><a href="index.php" class="poll-btn ghost sm" style="text-decoration:none;display:inline-block">← Back to Agent Edge</a></p></div>';
  }

  function theme() {
    if (S.phase === 'done') return '';
    return cur().type === 'allocate' ? 'theme-green' : 'theme-black';
  }
  function render() {
    var st = cur(), h;
    if (S.phase === 'done') h = vDone();
    else {
      var last = S.i === steps.length - 1;
      h = banner() + (st.type === 'allocate' ? vAllocate(st, last) : vMulti(st, last));
    }
    stage.className = 'poll-stage ' + theme();
    root.innerHTML = h;
  }

  /* ---------- results polling (after submit) ---------- */
  var timer = null;
  function loadAgg() {
    fetch('api/polls_action.php?action=aggregate&slug=' + encodeURIComponent(CFG.slug), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        S.agg = d.results;
        var el = document.getElementById('poll-chart');
        if (el) el.innerHTML = chartHTML();
      }).catch(function () {});
  }
  function startAgg() { if (CFG.preview || timer) return; loadAgg(); timer = setInterval(loadAgg, 8000); }

  /* ---------- drag and drop (pointer events: mouse + touch) ---------- */
  var drag = null;
  function zoneAt(e) { var el = document.elementFromPoint(e.clientX, e.clientY); return el ? el.closest('[data-zone]') : null; }
  function hilite(z) { root.querySelectorAll('[data-zone]').forEach(function (n) { n.classList.toggle('over', n === z); }); }
  function place(e) {
    if (!drag) return;
    drag.g.style.left = (e.clientX - drag.w / 2) + 'px';
    drag.g.style.top = (e.clientY - drag.w * 0.19) + 'px';
    hilite(zoneAt(e));
  }
  root.addEventListener('pointerdown', function (e) {
    var b = e.target.closest && e.target.closest('.poll-bill');
    if (!b || S.phase !== 'step' || cur().type !== 'allocate') return;
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    e.preventDefault();
    var w = Math.min(b.getBoundingClientRect().width, 190);
    var g = document.createElement('img');
    g.src = CFG.billUrl; g.className = 'poll-ghost'; g.style.width = w + 'px'; g.alt = '';
    document.body.appendChild(g);
    drag = { from: b.dataset.from, g: g, w: w };
    b.classList.add('lift');
    place(e);
  });
  document.addEventListener('pointermove', function (e) { if (drag) { e.preventDefault(); place(e); } }, { passive: false });
  function endDrag(e, cancel) {
    if (!drag) return;
    var z = cancel ? null : zoneAt(e), from = drag.from, st = cur();
    drag.g.remove(); drag = null;
    if (z && root.contains(z)) {
      var to = z.dataset.zone;
      if (to !== from) {
        if (from !== 'wallet') S.alloc[st.key][from]--;
        if (to !== 'wallet') S.alloc[st.key][to]++;
      }
    }
    render();
  }
  document.addEventListener('pointerup', function (e) { endDrag(e, false); });
  document.addEventListener('pointercancel', function (e) { endDrag(e, true); });

  /* ---------- actions ---------- */
  function submit() {
    var st = cur();
    if (S.saving || (st.type === 'allocate' && left(st))) return;
    S.error = '';
    if (CFG.preview) { S.mine = currentAnswers(); S.phase = 'done'; render(); window.scrollTo(0, 0); return; }
    S.saving = true; render();
    fetch('api/polls_action.php', {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'submit', csrf: window.AE_CSRF || '', slug: CFG.slug, answers: currentAnswers() })
    }).then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
      .then(function (x) {
        S.saving = false;
        if (x.d.ok) { S.mine = currentAnswers(); S.phase = 'done'; render(); startAgg(); window.scrollTo(0, 0); return; }
        if (x.d.code === 'already_voted') { window.location.reload(); return; }
        S.error = x.d.error || 'Could not save your answers. Please try again.';
        render();
      }).catch(function () { S.saving = false; S.error = 'Network problem. Your answers were not sent. Please try again.'; render(); });
  }
  root.addEventListener('click', function (e) {
    var t = e.target.closest('[data-act]'); if (!t) return;
    var a = t.dataset.act, st = cur();
    if (a === 'plus') { if (left(st) > 0) { S.alloc[st.key][t.dataset.o]++; render(); } }
    else if (a === 'minus') { if (S.alloc[st.key][t.dataset.o] > 0) { S.alloc[st.key][t.dataset.o]--; render(); } }
    else if (a === 'next') { if (!(st.type === 'allocate' && left(st))) { S.i++; S.error = ''; render(); window.scrollTo(0, 0); } }
    else if (a === 'back') { if (S.i > 0) { S.i--; S.error = ''; render(); } }
    else if (a === 'pick') { S.picks[st.key][t.dataset.o] = !S.picks[st.key][t.dataset.o]; render(); }
    else if (a === 'submit') submit();
  });

  render();
  if (S.phase === 'done') startAgg();
})();
