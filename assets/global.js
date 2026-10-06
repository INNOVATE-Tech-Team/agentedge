// Loaded on every AgentEdge page via nav.php.
// Contains functions that must be available globally (support modal, masquerade, sidebar).

// ── Sidebar Links submenu ─────────────────────────────────────────────────────

function toggleSbLinks(btn) {
  const sub = btn.nextElementSibling;
  const open = btn.getAttribute('aria-expanded') === 'true';
  btn.setAttribute('aria-expanded', String(!open));
  sub.hidden = open;
  if (open && btn.classList.contains('sb-links-toggle')) {
    const nav = btn.closest('.sb-nav');
    if (nav) nav.scrollTop = 0;
  }
  try { sessionStorage.setItem('ae_links_' + (btn.dataset.group || ''), String(!open)); } catch(e) {}
}

(function() {
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.sb-links-toggle, .sb-dept-toggle').forEach(function(btn) {
      const sub = btn.nextElementSibling;
      if (!sub) return;
      try {
        if (sessionStorage.getItem('ae_links_' + (btn.dataset.group || '')) === 'true') {
          btn.setAttribute('aria-expanded', 'true');
          sub.hidden = false;
        }
      } catch(e) {}
    });

    // Restore sidebar scroll position across the full-page navigations that
    // every menu link triggers — without this, each click snaps back to top.
    const nav = document.querySelector('.sb-nav');
    if (nav) {
      try {
        const saved = sessionStorage.getItem('ae_nav_scroll');
        if (saved !== null) nav.scrollTop = parseInt(saved, 10) || 0;
      } catch(e) {}
      nav.addEventListener('click', function(e) {
        if (e.target.closest('a, button')) {
          try { sessionStorage.setItem('ae_nav_scroll', String(nav.scrollTop)); } catch(err) {}
        }
      });
    }
  });
})();

// ── Mobile sidebar toggle ─────────────────────────────────────────────────────

function toggleSidebar() {
  const sidebar = document.querySelector('.sidebar');
  if (!sidebar) return;
  const open = sidebar.classList.toggle('sidebar-open');
  document.getElementById('sb-overlay')?.remove();
  if (open) {
    const overlay = document.createElement('div');
    overlay.id = 'sb-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:99;background:rgba(0,0,0,.45)';
    overlay.addEventListener('click', toggleSidebar);
    document.body.appendChild(overlay);
  }
}

// ── Masquerade stop ───────────────────────────────────────────────────────────

function stopMasquerade() {
  fetch('api/masquerade.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'stop' }),
  })
    .then(r => r.json())
    .then(d => { location.href = d.redirect || 'index.php'; })
    .catch(() => location.reload());
}

// ── Get Support modal ─────────────────────────────────────────────────────────

let _supportOverlay = null;

function openSupportModal() {
  if (!_supportOverlay) {
    _supportOverlay = document.createElement('div');
    _supportOverlay.className = 'support-overlay';
    _supportOverlay.innerHTML = `
      <div class="support-modal" role="dialog" aria-modal="true">
        <button class="support-close" onclick="closeSupportModal()" aria-label="Close">&times;</button>
        <h3>Get Support</h3>
        <p class="support-sub">Submit a request — the right team will respond in the ticket thread at everythinginnovate.com.</p>
        <div class="support-field">
          <label for="sup-title">Title</label>
          <input id="sup-title" type="text" placeholder="e.g., MLS access not working" maxlength="200">
        </div>
        <div class="support-field">
          <label for="sup-dept">Route to department</label>
          <select id="sup-dept"><option value="">— loading… —</option></select>
        </div>
        <div class="support-field">
          <label for="sup-body">Describe the issue</label>
          <textarea id="sup-body" placeholder="What's happening? When did it start?" maxlength="4000"></textarea>
        </div>
        <div class="support-field">
          <label for="sup-file">Attach a screenshot or document (optional)</label>
          <input id="sup-file" type="file" multiple onchange="onPickSupportFiles(this)">
          <div class="support-files" id="sup-files-preview"></div>
        </div>
        <div class="support-actions">
          <button class="support-submit" id="sup-submit" onclick="submitSupportTicket()">Submit ticket</button>
          <button class="support-cancel" onclick="closeSupportModal()">Cancel</button>
          <span class="support-msg" id="sup-msg"></span>
        </div>
      </div>`;
    _supportOverlay.addEventListener('click', e => { if (e.target === _supportOverlay) closeSupportModal(); });
    document.body.appendChild(_supportOverlay);

    // Load departments once
    fetch('api/support_departments.php', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        const sel = document.getElementById('sup-dept');
        sel.innerHTML = '<option value="">— pick a department —</option>';
        (d.departments || []).forEach(dept => {
          const opt = document.createElement('option');
          opt.value = dept.slug; opt.textContent = dept.name;
          sel.appendChild(opt);
        });
        if (sel.options.length <= 1) sel.innerHTML = '<option value="">No departments configured yet</option>';
      })
      .catch(() => {
        const sel = document.getElementById('sup-dept');
        if (sel) sel.innerHTML = '<option value="">Could not load departments</option>';
      });
  }

  _supportOverlay.classList.add('open');
  const msg = document.getElementById('sup-msg');
  if (msg) msg.textContent = '';
  const ti = document.getElementById('sup-title'); if (ti) ti.value = '';
  const bo = document.getElementById('sup-body');  if (bo) bo.value = '';
  const sb = document.getElementById('sup-submit'); if (sb) sb.disabled = false;
  _supportPendingFiles = [];
  renderSupportFiles();
}

function closeSupportModal() {
  if (_supportOverlay) _supportOverlay.classList.remove('open');
}

let _supportPendingFiles = [];

function onPickSupportFiles(input) {
  _supportPendingFiles = _supportPendingFiles.concat(Array.from(input.files));
  input.value = '';
  renderSupportFiles();
}

function removeSupportFile(idx) {
  _supportPendingFiles.splice(idx, 1);
  renderSupportFiles();
}

function renderSupportFiles() {
  const el = document.getElementById('sup-files-preview');
  if (!el) return;
  el.innerHTML = _supportPendingFiles.map((f, i) =>
    `<span class="file-chip">${f.name.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]))} `
    + `<button type="button" class="file-x" onclick="removeSupportFile(${i})" title="Remove">&times;</button></span>`
  ).join('');
}

function uploadSupportFile(messageId, file) {
  const fd = new FormData();
  fd.append('file', file);
  fd.append('message_id', messageId);
  fd.append('csrf', window.AE_CSRF || '');
  return fetch('api/ticket_file_action.php', { method: 'POST', credentials: 'same-origin', body: fd })
    .then(r => r.json());
}

function uploadSupportFilesSequential(messageId, files) {
  return files.reduce((p, file) => p.then(() => uploadSupportFile(messageId, file)), Promise.resolve());
}

function submitSupportTicket() {
  const title    = (document.getElementById('sup-title')?.value || '').trim();
  const deptSlug = document.getElementById('sup-dept')?.value || '';
  const body     = (document.getElementById('sup-body')?.value  || '').trim();
  const msg      = document.getElementById('sup-msg');
  const btn      = document.getElementById('sup-submit');

  if (!title)    { msg.textContent = 'Please enter a title.';       msg.className = 'support-msg err'; return; }
  if (!deptSlug) { msg.textContent = 'Please select a department.'; msg.className = 'support-msg err'; return; }
  if (!body)     { msg.textContent = 'Please describe the issue.';  msg.className = 'support-msg err'; return; }

  btn.disabled = true;
  msg.textContent = 'Submitting…'; msg.className = 'support-msg ok';

  fetch('api/support_ticket.php', {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ title, departmentSlug: deptSlug, body }),
  })
    .then(r => r.json())
    .then(d => {
      if (d.ok) {
        const pending = _supportPendingFiles.slice();
        (pending.length ? uploadSupportFilesSequential(d.messageId, pending) : Promise.resolve())
          .then(() => {
            msg.textContent = 'Ticket submitted! The team will follow up at everythinginnovate.com/tickets.';
            msg.className = 'support-msg ok';
            setTimeout(closeSupportModal, 3000);
          });
      } else {
        msg.textContent = d.error || 'Submit failed — please try again.';
        msg.className = 'support-msg err';
        btn.disabled = false;
      }
    })
    .catch(() => {
      msg.textContent = 'Network error — please try again.';
      msg.className = 'support-msg err';
      btn.disabled = false;
    });
}


// ── Help Widget ──────────────────────────────────────────────────────────────
// Floating "?" button + Help Center panel, loaded on every page via nav.php.
// Button position persists per-browser in localStorage; the panel loads
// shortcuts (super_admin-managed, see admin_help_widget.php) and does a
// live lesson search (api/help_action.php: widget_shortcuts / search).
(function() {
  const POS_KEY = 'help_widget_pos';
  let btn, panel, root;
  let shortcutsLoaded = false;
  let shortcuts = [];
  let quickAddEnabled = false;
  let searchTimer = null;
  let dragged = false;

  function safeArea() {
    const margin = 10;
    const sidebar = document.querySelector('.sidebar');
    const sidebarW = (sidebar && window.innerWidth > 760) ? sidebar.getBoundingClientRect().width : 0;
    const masqBar = document.querySelector('.masq-bar');
    const topY = masqBar ? masqBar.getBoundingClientRect().height + margin : margin;
    return {
      minX: sidebarW + margin,
      minY: topY,
      maxX: window.innerWidth - margin,
      maxY: window.innerHeight - margin,
    };
  }

  function clampToSafeArea(x, y, w, h) {
    const a = safeArea();
    x = Math.min(Math.max(x, a.minX), Math.max(a.minX, a.maxX - w));
    y = Math.min(Math.max(y, a.minY), Math.max(a.minY, a.maxY - h));
    return { x, y };
  }

  function loadPos() {
    try { return JSON.parse(localStorage.getItem(POS_KEY) || 'null'); } catch (e) { return null; }
  }
  function savePos(x, y) {
    try { localStorage.setItem(POS_KEY, JSON.stringify({ x, y })); } catch (e) {}
  }

  function applyPos() {
    const rect = btn.getBoundingClientRect();
    const saved = loadPos();
    const x = saved ? saved.x : window.innerWidth - rect.width - 24;
    const y = saved ? saved.y : window.innerHeight - rect.height - 24;
    const c = clampToSafeArea(x, y, rect.width, rect.height);
    btn.style.left = c.x + 'px';
    btn.style.top = c.y + 'px';
    btn.style.right = 'auto';
    btn.style.bottom = 'auto';
  }

  function buildWidget() {
    root = document.createElement('div');
    root.id = 'help-widget-root';

    btn = document.createElement('button');
    btn.id = 'help-widget-btn';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Help');
    btn.textContent = '?';

    panel = document.createElement('div');
    panel.id = 'help-widget-panel';
    panel.innerHTML = `
      <div class="hw-head">
        <h3>Help Center</h3>
        <button type="button" class="hw-close" aria-label="Close">&times;</button>
      </div>
      <input type="text" class="hw-search" placeholder="Search lessons, topics, tags…" autocomplete="off">
      <div class="hw-body">
        <div class="hw-shortcuts"></div>
        <div class="hw-quickadd" style="display:none">
          <div class="hw-quickadd-eyebrow">Admin OS Quick Add</div>
          <div class="hw-quickadd-row">
            <input type="text" class="hw-quickadd-input" placeholder="What do you need to remember or do?" autocomplete="off">
            <button type="button" class="hw-quickadd-btn">Add</button>
          </div>
          <span class="hw-quickadd-msg"></span>
        </div>
        <div class="hw-results" style="display:none"></div>
      </div>
    `;

    root.appendChild(panel);
    root.appendChild(btn);
    document.body.appendChild(root);

    applyPos();
    window.addEventListener('resize', applyPos);
    initDrag();

    btn.addEventListener('click', () => { if (!dragged) toggleHelpPanel(); });
    panel.querySelector('.hw-close').addEventListener('click', closeHelpPanel);
    panel.querySelector('.hw-search').addEventListener('input', onHelpSearch);
    panel.querySelector('.hw-shortcuts').addEventListener('click', onShortcutClick);
    panel.querySelector('.hw-quickadd-btn').addEventListener('click', quickAddCapture);
    panel.querySelector('.hw-quickadd-input').addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); quickAddCapture(); }
    });

    document.addEventListener('click', e => {
      if (!panel.classList.contains('open')) return;
      if (panel.contains(e.target) || btn.contains(e.target)) return;
      closeHelpPanel();
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && panel.classList.contains('open')) closeHelpPanel();
    });
  }

  function initDrag() {
    let startX, startY, origX, origY, moved;

    function onDown(clientX, clientY) {
      const rect = btn.getBoundingClientRect();
      startX = clientX; startY = clientY;
      origX = rect.left; origY = rect.top;
      moved = false;
      dragged = false;
    }
    function onMove(clientX, clientY) {
      const dx = clientX - startX, dy = clientY - startY;
      if (Math.abs(dx) > 4 || Math.abs(dy) > 4) moved = true;
      if (!moved) return;
      dragged = true;
      const rect = btn.getBoundingClientRect();
      const c = clampToSafeArea(origX + dx, origY + dy, rect.width, rect.height);
      btn.style.left = c.x + 'px';
      btn.style.top = c.y + 'px';
      btn.style.right = 'auto';
      btn.style.bottom = 'auto';
    }
    function onUp() {
      if (moved) {
        const rect = btn.getBoundingClientRect();
        savePos(rect.left, rect.top);
      }
      document.removeEventListener('mousemove', mouseMoveH);
      document.removeEventListener('mouseup', mouseUpH);
      document.removeEventListener('touchmove', touchMoveH);
      document.removeEventListener('touchend', touchUpH);
    }
    function mouseMoveH(e) { onMove(e.clientX, e.clientY); }
    function mouseUpH() { onUp(); }
    function touchMoveH(e) { if (e.touches[0]) onMove(e.touches[0].clientX, e.touches[0].clientY); }
    function touchUpH() { onUp(); }

    btn.addEventListener('mousedown', e => {
      onDown(e.clientX, e.clientY);
      document.addEventListener('mousemove', mouseMoveH);
      document.addEventListener('mouseup', mouseUpH);
    });
    btn.addEventListener('touchstart', e => {
      if (e.touches[0]) onDown(e.touches[0].clientX, e.touches[0].clientY);
      document.addEventListener('touchmove', touchMoveH, { passive: true });
      document.addEventListener('touchend', touchUpH);
    }, { passive: true });
  }

  function toggleHelpPanel() { panel.classList.contains('open') ? closeHelpPanel() : openHelpPanel(); }

  function openHelpPanel() {
    panel.classList.add('open');
    positionPanel();
    const search = panel.querySelector('.hw-search');
    search.value = '';
    search.focus();
    showShortcuts();
    if (!shortcutsLoaded) loadShortcuts();
  }

  function closeHelpPanel() { panel.classList.remove('open'); }

  function positionPanel() {
    const rect = btn.getBoundingClientRect();
    const panelW = 360, panelH = Math.min(480, window.innerHeight - 40);
    let left = rect.left;
    let top = rect.top - panelH - 12;
    if (top < 10) top = rect.bottom + 12;
    if (left + panelW > window.innerWidth - 10) left = window.innerWidth - panelW - 10;
    if (left < 10) left = 10;
    panel.style.left = left + 'px';
    panel.style.top = top + 'px';
  }

  function helpApi(body) {
    return fetch('api/help_action.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    }).then(r => r.json());
  }

  function loadShortcuts() {
    shortcutsLoaded = true;
    helpApi({ action: 'widget_shortcuts' }).then(d => {
      shortcuts = (d.ok && d.shortcuts) || [];
      quickAddEnabled = !!(d.ok && d.quick_add);
      showShortcuts();
    });
  }

  function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }

  function renderShortcuts() {
    if (!shortcuts.length) return '<div class="hw-empty">No shortcuts configured yet.</div>';
    return shortcuts.map(s => {
      const icon = String(s.icon || '').startsWith('img:')
        ? `<img class="hw-shortcut-icon-img" src="api/help_action.php?icon=${encodeURIComponent(s.icon.slice(4))}" alt="">`
        : `<span class="hw-shortcut-icon">${escHtml(s.icon || '🔗')}</span>`;
      const url = String(s.url || '');
      const inner = `${icon}<span class="hw-shortcut-label">${escHtml(s.label)}</span><span class="hw-shortcut-arrow">&rsaquo;</span>`;
      if (url.startsWith('action:')) {
        const name = url.slice('action:'.length);
        return `<button type="button" class="hw-shortcut-row" data-action="${escHtml(name)}">${inner}</button>`;
      }
      const ext = (parseInt(s.is_ext) ? ' target="_blank" rel="noopener"' : '');
      return `<a class="hw-shortcut-row" href="${escHtml(url)}"${ext}>${inner}</a>`;
    }).join('');
  }

  function onShortcutClick(e) {
    const el = e.target.closest('[data-action]');
    if (!el) return;
    e.preventDefault();
    const name = el.getAttribute('data-action');
    if (name === 'get_support') {
      closeHelpPanel();
      openSupportModal();
    }
  }

  function showShortcuts() {
    panel.querySelector('.hw-shortcuts').style.display = '';
    panel.querySelector('.hw-quickadd').style.display = quickAddEnabled ? '' : 'none';
    panel.querySelector('.hw-results').style.display = 'none';
    panel.querySelector('.hw-shortcuts').innerHTML = renderShortcuts();
  }

  function quickAddCapture() {
    const input = panel.querySelector('.hw-quickadd-input');
    const btn   = panel.querySelector('.hw-quickadd-btn');
    const msg   = panel.querySelector('.hw-quickadd-msg');
    const title = input.value.trim();
    if (!title) { input.focus(); return; }

    btn.disabled = true;
    msg.textContent = 'Adding…'; msg.className = 'hw-quickadd-msg';

    fetch('api/admin_work_item_action.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'create', title: title, csrf: window.AE_CSRF || '' }),
    })
      .then(r => r.json())
      .then(d => {
        btn.disabled = false;
        if (d.ok) {
          input.value = '';
          msg.textContent = 'Added to Inbox'; msg.className = 'hw-quickadd-msg ok';
        } else {
          msg.textContent = d.error || 'Could not add — please try again.'; msg.className = 'hw-quickadd-msg err';
        }
        input.focus();
      })
      .catch(() => {
        btn.disabled = false;
        msg.textContent = 'Network error — please try again.'; msg.className = 'hw-quickadd-msg err';
        input.focus();
      });
  }

  function difficultyBadge(d) {
    const key = d || 'beginner';
    const label = key.replace(/^\w/, c => c.toUpperCase());
    return `<span class="hw-diff hw-diff-${escHtml(key)}">${escHtml(label)}</span>`;
  }

  function renderAgents(agents) {
    if (!agents.length) return '';
    const rows = agents.map(a => {
      const loc = [a.market_center, a.state_code].filter(Boolean).join(', ');
      const phone = a.phone
        ? `<a class="hw-agent-phone" href="tel:${escHtml(a.phone.replace(/[^\d+]/g, ''))}">${escHtml(a.phone)}</a>`
        : '<span class="hw-agent-phone hw-agent-phone-none">No phone on file</span>';
      return `
        <div class="hw-agent-row">
          <div class="hw-agent-main">
            <span class="hw-agent-name">${escHtml(a.name)}</span>
            ${loc ? `<span class="hw-agent-loc">${escHtml(loc)}</span>` : ''}
          </div>
          ${phone}
        </div>`;
    }).join('');
    return `<div class="hw-agents"><div class="hw-agents-head">Agent Roster</div>${rows}</div>`;
  }

  function renderResults(results, agents) {
    const agentsHtml = renderAgents(agents || []);
    if (!results.length) {
      return agentsHtml + '<div class="hw-empty">No lessons matched — try a different word, or check a shortcut below.</div>';
    }
    const lessonsHtml = results.map(r => {
      const related = (r.related || []).slice(0, 3).map(rel =>
        `<a class="hw-related-chip" href="${escHtml(rel.link)}">${escHtml(rel.title)}</a>`
      ).join('');
      return `
        <a class="hw-result-row" href="${escHtml(r.link)}">
          <div class="hw-result-top">
            <span class="hw-result-title">${escHtml(r.title)}</span>
            ${difficultyBadge(r.difficulty)}
          </div>
          ${r.objective ? `<div class="hw-result-obj">${escHtml(r.objective)}</div>` : ''}
          ${related ? `<div class="hw-related-list">${related}</div>` : ''}
        </a>`;
    }).join('');
    return agentsHtml + lessonsHtml;
  }

  function onHelpSearch(e) {
    const q = e.target.value.trim();
    clearTimeout(searchTimer);
    if (!q) { showShortcuts(); return; }
    searchTimer = setTimeout(() => {
      helpApi({ action: 'search', q }).then(d => {
        const resultsEl = panel.querySelector('.hw-results');
        panel.querySelector('.hw-shortcuts').style.display = 'none';
        panel.querySelector('.hw-quickadd').style.display = 'none';
        resultsEl.style.display = '';
        resultsEl.innerHTML = renderResults((d.ok && d.results) || [], d.ok && d.agents);
      });
    }, 300);
  }

  document.addEventListener('DOMContentLoaded', buildWidget);
})();
