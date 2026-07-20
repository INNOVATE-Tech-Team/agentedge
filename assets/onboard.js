// AgentEdge — Onboarding Dashboard
// Handles: queue rendering, add-agent form, CRM search autocomplete,
// mark-done / provision actions, tab switching, expand/collapse.

(function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────────────
  let currentFilter = 'active';
  let expandedIds   = new Set();   // queue ids whose checklist is open
  const TOOLS       = window.ONBOARD_TOOLS || [];
  const STATES      = ['FL','GA','SC','NC','TN','VA','MD','DE','NJ','PA','OH','MA','RI','NH'];
  const MC_OPTS     = window.ONBOARD_MC_OPTS || [];

  // Tool key → definition map
  const TOOL_MAP = {};
  TOOLS.forEach(t => { TOOL_MAP[t.key] = t; });

  // ── Helpers ────────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s ?? '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;');
  }

  function setMsg(id, text, ok) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
    el.className   = 'form-msg ' + (ok ? 'ok' : 'err');
  }

  function post(url, data) {
    return fetch(url, {
      method:      'POST',
      credentials: 'same-origin',
      headers:     { 'Content-Type': 'application/json' },
      body:        JSON.stringify(data),
    }).then(r => r.json());
  }

  // ── Add-panel toggle ───────────────────────────────────────────────────────
  window.toggleAddPanel = function () {
    const panel = document.getElementById('ob-add-panel');
    const open  = panel.classList.toggle('open');
    const btn   = document.getElementById('btn-add-agent');
    btn.textContent = open ? '— Close' : '+ Add Agent';
    if (open) panel.querySelector('input')?.focus();
  };

  // ── Tab switching ──────────────────────────────────────────────────────────
  window.switchTab = function (el, filter) {
    document.querySelectorAll('.ob-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    currentFilter = filter;
    expandedIds.clear();
    loadQueue();
  };

  // ── CRM search autocomplete ────────────────────────────────────────────────
  let crmTimer = null;
  const crmInput   = document.getElementById('crm-search');
  const crmResults = document.getElementById('crm-results');

  if (crmInput) {
    crmInput.addEventListener('input', () => {
      clearTimeout(crmTimer);
      const q = crmInput.value.trim();
      if (q.length < 2) { hideResults(); return; }
      crmTimer = setTimeout(() => fetchCRM(q), 300);
    });

    crmInput.addEventListener('blur', () => {
      // Slight delay so click on result fires first
      setTimeout(hideResults, 200);
    });
  }

  function hideResults() {
    if (crmResults) { crmResults.style.display = 'none'; crmResults.innerHTML = ''; }
  }

  function fetchCRM(q) {
    fetch('api/onboard_action.php?action=search_crm&q=' + encodeURIComponent(q), {
      credentials: 'same-origin',
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok || !d.results?.length) { hideResults(); return; }
        crmResults.innerHTML = d.results.map(r =>
          `<div class="crm-result-item" data-name="${esc(r.name)}" data-email="${esc(r.email)}"
                data-mc="${esc(r.marketCenter)}" data-phone="${esc(r.phone)}">
             <strong>${esc(r.name)}</strong> <span style="color:#888">${esc(r.email)}</span>
             ${r.marketCenter ? `<span style="color:#aaa;font-size:11px;margin-left:6px">${esc(r.marketCenter)}</span>` : ''}
           </div>`
        ).join('');
        crmResults.style.display = 'block';

        crmResults.querySelectorAll('.crm-result-item').forEach(item => {
          item.addEventListener('mousedown', () => {
            // Fill form fields
            setValue('ob-name',  item.dataset.name);
            setValue('ob-email', item.dataset.email);
            setValue('ob-mc',    item.dataset.mc);
            crmInput.value = '';
            hideResults();
            document.getElementById('ob-name')?.focus();
          });
        });
      })
      .catch(() => hideResults());
  }

  function setValue(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val || '';
  }

  // ── Sponsor / Recruited By autocomplete ────────────────────────────────────
  // Reuses the same CRM/roster search as "Search CRM Roster" above, but just
  // fills the sponsor field with the picked name instead of the whole form.
  let sponsorTimer = null;
  const sponsorInput   = document.getElementById('ob-sponsor');
  const sponsorResults = document.getElementById('sponsor-results');

  if (sponsorInput) {
    sponsorInput.addEventListener('input', () => {
      clearTimeout(sponsorTimer);
      const q = sponsorInput.value.trim();
      if (q.length < 2) { hideSponsorResults(); return; }
      sponsorTimer = setTimeout(() => fetchSponsor(q), 300);
    });

    sponsorInput.addEventListener('blur', () => {
      setTimeout(hideSponsorResults, 200);
    });
  }

  function hideSponsorResults() {
    if (sponsorResults) { sponsorResults.style.display = 'none'; sponsorResults.innerHTML = ''; }
  }

  function fetchSponsor(q) {
    fetch('api/onboard_action.php?action=search_crm&q=' + encodeURIComponent(q), {
      credentials: 'same-origin',
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok || !d.results?.length) { hideSponsorResults(); return; }
        sponsorResults.innerHTML = d.results.map(r =>
          `<div class="crm-result-item" data-name="${esc(r.name)}">
             <strong>${esc(r.name)}</strong>
             ${r.marketCenter ? `<span style="color:#aaa;font-size:11px;margin-left:6px">${esc(r.marketCenter)}</span>` : ''}
           </div>`
        ).join('');
        sponsorResults.style.display = 'block';

        sponsorResults.querySelectorAll('.crm-result-item').forEach(item => {
          item.addEventListener('mousedown', () => {
            sponsorInput.value = item.dataset.name;
            hideSponsorResults();
          });
        });
      })
      .catch(() => hideSponsorResults());
  }

  // ── Add-panel: filter Market Center by checked License State(s) ────────────
  window.onAddStateChange = function () {
    const checked = Array.from(document.querySelectorAll('.ob-state-check:checked')).map(cb => cb.value);
    const mcSelect = document.getElementById('ob-mc');
    if (!mcSelect) return;
    const current  = mcSelect.value;
    const filtered = checked.length ? MC_OPTS.filter(m => checked.includes(m.state_code)) : MC_OPTS;
    mcSelect.innerHTML = '<option value="">Select Market Center…</option>' +
      filtered.map(m => `<option value="${esc(m.name)}">${esc((m.state_code ? m.state_code + ' - ' : '') + m.name)}</option>`).join('');
    if (filtered.some(m => m.name === current)) mcSelect.value = current;
  };

  // ── Add-agent form submit ──────────────────────────────────────────────────
  const addForm = document.getElementById('ob-add-form');
  if (addForm) {
    addForm.addEventListener('submit', e => {
      e.preventDefault();
      const btn    = document.getElementById('ob-add-btn');
      const name   = document.getElementById('ob-name')?.value.trim();
      const email  = document.getElementById('ob-email')?.value.trim();
      const mc     = document.getElementById('ob-mc')?.value.trim();
      const states = Array.from(document.querySelectorAll('.ob-state-check:checked')).map(cb => cb.value);
      if (!name || !email) { setMsg('ob-add-msg','Name and email are required.',false); return; }
      if (!mc) { setMsg('ob-add-msg','Market Center is required.',false); return; }

      btn.disabled = true;
      setMsg('ob-add-msg','Adding…',true);

      post('api/onboard_action.php?action=add_to_queue', {
        agent_name:    name,
        agent_email:   email,
        market_center: mc,
        state_code:    states.join(','),
        role:          document.getElementById('ob-role')?.value,
        start_date:    document.getElementById('ob-start')?.value,
        sponsor:       document.getElementById('ob-sponsor')?.value.trim(),
        notes:         document.getElementById('ob-notes')?.value.trim(),
      })
        .then(d => {
          btn.disabled = false;
          if (d.ok) {
            setMsg('ob-add-msg', name + ' added to queue.', true);
            addForm.reset();
            onAddStateChange(); // resync Market Center options now states are unchecked
            // Expand the newly added entry after reload
            expandedIds.add(d.id);
            // Switch to active tab and reload
            currentFilter = 'active';
            document.querySelectorAll('.ob-tab').forEach(t => {
              t.classList.toggle('active', t.dataset.filter === 'active');
            });
            loadQueue();
          } else {
            setMsg('ob-add-msg', d.error || 'Could not add agent.', false);
          }
        })
        .catch(() => { btn.disabled = false; setMsg('ob-add-msg','Request failed.',false); });
    });
  }

  // ── Queue loader ───────────────────────────────────────────────────────────
  function loadQueue() {
    const container = document.getElementById('ob-queue');
    if (!container) return;
    container.innerHTML = '<div class="ob-empty">Loading…</div>';

    fetch('api/onboard_action.php?action=list_queue&filter=' + encodeURIComponent(currentFilter), {
      credentials: 'same-origin',
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { container.innerHTML = `<div class="ob-empty">Error: ${esc(d.error)}</div>`; return; }
        renderQueue(container, d.queue || []);
      })
      .catch(err => {
        container.innerHTML = '<div class="ob-empty">Could not load queue.</div>';
      });
  }

  // ── Queue renderer ─────────────────────────────────────────────────────────
  function renderQueue(container, queue) {
    if (!queue.length) {
      const msgs = {
        active:    'No active onboarding agents.',
        completed: 'No completed onboarding records.',
        all:       'The onboarding queue is empty.',
      };
      container.innerHTML = `<div class="ob-empty">${esc(msgs[currentFilter] || 'Nothing here.')}</div>`;
      return;
    }

    container.innerHTML = queue.map(entry => renderEntry(entry)).join('');

    // Restore expanded state
    expandedIds.forEach(id => {
      const cl = container.querySelector(`.ob-checklist[data-qid="${id}"]`);
      if (cl) cl.classList.add('open');
    });
  }

  function stepDotHtml(step) {
    const toolDef  = TOOL_MAP[step.tool_key] || {};
    const label    = toolDef.label || step.tool_label || step.tool_key;
    const dotClass = {
      done:    'ob-dot-done',
      pending: 'ob-dot-pending',
      sent:    'ob-dot-sent',
      failed:  'ob-dot-failed',
      skipped: 'ob-dot-skipped',
    }[step.status] || 'ob-dot-pending';
    const title = `${label}: ${step.status}`;
    return `<span class="ob-dot ${dotClass}" title="${esc(title)}"></span>`;
  }

  function stepIconHtml(status) {
    const cfg = {
      done:    { bg:'#82C112', color:'#fff', char:'✓' },
      pending: { bg:'#E6E7E8', color:'#888', char:'○' },
      sent:    { bg:'#E8A93A', color:'#fff', char:'✉' },
      failed:  { bg:'#C0392B', color:'#fff', char:'✕' },
      skipped: { bg:'#bbb',    color:'#fff', char:'—' },
    }[status] || { bg:'#E6E7E8', color:'#888', char:'○' };
    return `<span class="ob-step-icon" style="background:${cfg.bg};color:${cfg.color}">${cfg.char}</span>`;
  }

  function renderEntry(entry) {
    const done    = parseInt(entry.done_count  || 0, 10);
    const total   = parseInt(entry.total_count || 0, 10);
    const pct     = total > 0 ? Math.round((done / total) * 100) : 0;
    const steps   = entry.steps || [];
    const isOpen  = expandedIds.has(entry.id);

    const dots = steps.map(s => stepDotHtml(s)).join('');

    const metaParts = [];
    if (entry.market_center) metaParts.push(esc(entry.market_center));
    if (entry.start_date)    metaParts.push('Starts ' + esc(entry.start_date));
    if (entry.role && entry.role !== 'agent') metaParts.push(esc(entry.role.replace(/_/g,' ')));
    const meta = metaParts.join(' · ');

    const statusBadge = entry.status !== 'active'
      ? `<span style="font-size:11px;font-weight:800;padding:2px 8px;border-radius:10px;background:${entry.status==='completed'?'#eef5e8':'#f0f0f0'};color:${entry.status==='completed'?'#3a6b1a':'#888'}">${esc(entry.status)}</span>`
      : '';

    const stepsHtml = steps.map(s => renderStep(entry.id, s, entry.status)).join('');

    // An agent can be licensed in more than one state, so state_code is a
    // comma-separated list — rendered as checkboxes instead of a single select.
    const selectedStates = (entry.state_code || '').split(',').map(s => s.trim()).filter(Boolean);
    const stateChecksHtml = STATES.map(s => `
      <label style="display:inline-flex;align-items:center;gap:3px;font-size:11px;background:#f0f0f0;padding:2px 7px;border-radius:10px;cursor:pointer;margin:0 4px 4px 0">
        <input type="checkbox" value="${s}" style="margin:0" ${selectedStates.includes(s) ? 'checked' : ''}
               onchange="onStateCheckboxChange(${entry.id})">${s}
      </label>`).join('');
    const stateSelectHtml = entry.status === 'active'
      ? `<span id="ob-states-${entry.id}">${stateChecksHtml}</span>`
      : (selectedStates.join(', ') || '');

    // Market Center options are filtered to whichever states are checked
    // above (falls back to the full list until at least one is checked).
    const filteredMcOpts = selectedStates.length
      ? MC_OPTS.filter(m => selectedStates.includes(m.state_code))
      : MC_OPTS;
    const mcOptions = filteredMcOpts.map(m =>
      `<option value="${esc(m.name)}"${entry.market_center === m.name ? ' selected' : ''}>${esc((m.state_code ? m.state_code + ' - ' : '') + m.name)}</option>`
    ).join('');
    // Keep the currently-saved Market Center selectable even if a state edit
    // just filtered it out of the list — don't silently orphan existing data.
    const currentMcOrphaned = entry.market_center && !filteredMcOpts.some(m => m.name === entry.market_center);
    const orphanedOption = currentMcOrphaned ? `<option value="${esc(entry.market_center)}" selected>${esc(entry.market_center)}</option>` : '';
    const mcSelectHtml = entry.status === 'active' ? `
      <select class="ob-state-select" onchange="setQueueMarketCenter(${entry.id}, this)" title="Market Center (required to complete onboarding)">
        <option value="">Market Center…</option>
        ${orphanedOption}
        ${mcOptions}
      </select>` : (entry.market_center ? esc(entry.market_center) : '');

    const footerHtml = entry.status === 'active' ? `
      <div class="ob-footer">
        <button class="ob-btn-sm ob-btn-done" data-has-state="${entry.state_code ? '1' : '0'}" data-has-mc="${entry.market_center ? '1' : '0'}"
                onclick="completeOnboarding(${entry.id}, this)">Mark Complete</button>
        <button class="ob-btn-sm ob-btn-undo" onclick="cancelOnboarding(${entry.id}, this)">Cancel / Remove</button>
      </div>` : '';

    return `
      <div class="ob-agent-row" id="ob-row-${entry.id}">
        <div class="ob-agent-head" onclick="toggleChecklist(${entry.id})">
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              <span class="ob-agent-name">${esc(entry.agent_name)}</span>
              ${statusBadge}
            </div>
            <div class="ob-agent-meta">${esc(entry.agent_email)}${meta ? ' · ' + meta : ''}</div>
          </div>
          <div style="display:flex;align-items:center;gap:14px;flex-shrink:0">
            <div class="ob-dots">${dots}</div>
            <div class="ob-progress">
              <div class="ob-progress-bar"><div class="ob-progress-fill" style="width:${pct}%"></div></div>
              <span>${done}/${total}</span>
            </div>
            <span style="font-size:12px;color:#888;margin-left:4px">${isOpen ? '▲' : '▼'}</span>
          </div>
        </div>
        <div class="ob-checklist${isOpen ? ' open' : ''}" data-qid="${entry.id}">
          ${entry.status === 'active' ? `<div class="ob-state-row" style="padding:4px 0 12px;font-size:12px;color:#888;display:flex;gap:16px;flex-wrap:wrap">
            <span>License state (required to complete): ${stateSelectHtml}</span>
            <span>Market Center (required to complete): ${mcSelectHtml}</span>
          </div>` : ''}
          ${stepsHtml || '<div style="padding:12px 0;color:#aaa;font-size:13px">No steps found.</div>'}
          ${footerHtml}
        </div>
      </div>`;
  }

  function renderStep(queueId, step, queueStatus) {
    const toolDef  = TOOL_MAP[step.tool_key] || {};
    const note     = toolDef.note || '';
    const isAuto   = parseInt(step.is_auto, 10) === 1;
    const disabled = queueStatus !== 'active';

    let actionsHtml = '';
    if (!disabled) {
      if (step.status === 'done') {
        actionsHtml = `<button class="ob-btn-sm ob-btn-undo" onclick="markStep(${queueId},'${esc(step.tool_key)}','pending',this)">Undo</button>`;
      } else if (step.status === 'skipped') {
        actionsHtml = `<button class="ob-btn-sm ob-btn-undo" onclick="markStep(${queueId},'${esc(step.tool_key)}','pending',this)">Unskip</button>`;
      } else if (step.status === 'sent') {
        // Awaiting the agent's signature — PandaDoc's webhook flips this to
        // Done automatically; these are just manual overrides.
        actionsHtml = `<button class="ob-btn-sm ob-btn-done" onclick="markStep(${queueId},'${esc(step.tool_key)}','done',this)">Mark Signed</button>
                       <button class="ob-btn-sm ob-btn-undo" onclick="markStep(${queueId},'${esc(step.tool_key)}','pending',this)">Undo</button>`;
      } else {
        // pending or failed
        actionsHtml = `<button class="ob-btn-sm ob-btn-done" onclick="markStep(${queueId},'${esc(step.tool_key)}','done',this)">Mark Done</button>
                       <button class="ob-btn-sm ob-btn-undo" onclick="markStep(${queueId},'${esc(step.tool_key)}','skipped',this)">Skip</button>`;
        if (isAuto) {
          actionsHtml += ` <button class="ob-btn-sm ob-btn-provision" id="prov-${queueId}-${esc(step.tool_key)}"
                            onclick="provisionStep(${queueId},'${esc(step.tool_key)}',this)">Provision Now</button>`;
        }
      }
    }

    const errorNote = step.error_msg
      ? `<span style="font-size:11px;color:#C0392B;margin-left:6px" title="${esc(step.error_msg)}">⚠ ${esc(step.error_msg)}</span>`
      : '';

    const doneInfo = step.done_at
      ? `<span style="font-size:11px;color:#aaa"> · ${esc(step.done_by || '')} ${esc(step.done_at)}</span>`
      : '';

    return `
      <div class="ob-step" id="step-${queueId}-${step.tool_key}">
        ${stepIconHtml(step.status)}
        <div style="flex:1;min-width:0">
          <span class="ob-step-label">${esc(step.tool_label)}</span>
          ${note ? `<span class="ob-step-note"> · ${esc(note)}</span>` : ''}
          ${errorNote}
          ${doneInfo}
        </div>
        <div class="ob-step-actions">${actionsHtml}</div>
      </div>`;
  }

  // ── Toggle checklist ───────────────────────────────────────────────────────
  window.toggleChecklist = function (queueId) {
    const cl   = document.querySelector(`.ob-checklist[data-qid="${queueId}"]`);
    const head = cl?.previousElementSibling;
    if (!cl) return;
    const open = cl.classList.toggle('open');
    if (open) {
      expandedIds.add(queueId);
    } else {
      expandedIds.delete(queueId);
    }
    // Flip the arrow
    const arrow = head?.querySelector('span:last-child');
    if (arrow) arrow.textContent = open ? '▲' : '▼';
  };

  // ── Mark step done/pending/skipped ─────────────────────────────────────────
  window.markStep = function (queueId, toolKey, status, btn) {
    btn.disabled = true;
    const origText = btn.textContent;
    btn.textContent = '…';

    post('api/onboard_action.php?action=mark_done', { queue_id: queueId, tool_key: toolKey, status })
      .then(d => {
        if (d.ok) {
          // Reload the queue to reflect changes
          loadQueue();
        } else {
          btn.disabled  = false;
          btn.textContent = origText;
          alert('Error: ' + (d.error || 'Could not update step.'));
        }
      })
      .catch(() => { btn.disabled = false; btn.textContent = origText; });
  };

  // ── Provision step via API ─────────────────────────────────────────────────
  window.provisionStep = function (queueId, toolKey, btn) {
    btn.disabled    = true;
    const origText  = btn.textContent;
    btn.textContent = '⏳';
    btn.style.opacity = '0.7';

    post('api/onboard_action.php?action=provision', { queue_id: queueId, tool_key: toolKey })
      .then(d => {
        if (d.ok) {
          loadQueue();
        } else {
          btn.disabled    = false;
          btn.textContent = origText;
          btn.style.opacity = '1';
          alert('Provision failed: ' + (d.error || 'Unknown error'));
        }
      })
      .catch(() => {
        btn.disabled    = false;
        btn.textContent = origText;
        btn.style.opacity = '1';
      });
  };

  // ── Set license state(s) on a queue entry ──────────────────────────────────
  // Reloads the whole queue on success (rather than patching in place) since
  // the Market Center dropdown's options depend on which states are checked.
  window.onStateCheckboxChange = function (queueId) {
    const container = document.getElementById(`ob-states-${queueId}`);
    if (!container) return;
    const boxes  = Array.from(container.querySelectorAll('input[type=checkbox]'));
    const states = boxes.filter(cb => cb.checked).map(cb => cb.value);
    boxes.forEach(cb => cb.disabled = true);
    post('api/onboard_action.php?action=set_state', { queue_id: queueId, state_codes: states })
      .then(d => {
        if (!d.ok) alert(d.error || 'Could not set state.');
        loadQueue();
      })
      .catch(() => { boxes.forEach(cb => cb.disabled = false); });
  };

  // ── Set Market Center on a queue entry ─────────────────────────────────────
  window.setQueueMarketCenter = function (queueId, select) {
    const mc = select.value;
    if (!mc) return;
    select.disabled = true;
    post('api/onboard_action.php?action=set_market_center', { queue_id: queueId, market_center: mc })
      .then(d => {
        select.disabled = false;
        if (!d.ok) { alert(d.error || 'Could not set Market Center.'); return; }
        const btn = document.querySelector(`#ob-row-${queueId} .ob-btn-done`);
        if (btn) btn.dataset.hasMc = '1';
      })
      .catch(() => { select.disabled = false; });
  };

  // ── Complete / Cancel queue entry ──────────────────────────────────────────
  window.completeOnboarding = function (queueId, btn) {
    const hasState = btn.dataset.hasState === '1';
    const hasMc    = btn.dataset.hasMc === '1';
    if (!hasState) { alert('Set a license state for this agent first — it\'s required to add them to the Backoffice Roster.'); return; }
    if (!hasMc) { alert('Set a Market Center for this agent first — it\'s required to add them to the Backoffice Roster.'); return; }
    if (!confirm('Mark this agent\'s onboarding as complete?')) return;
    btn.disabled = true;
    post('api/onboard_action.php?action=complete_onboarding', { queue_id: queueId })
      .then(d => {
        if (d.ok) { loadQueue(); }
        else { btn.disabled = false; alert(d.error || 'Error'); }
      })
      .catch(() => { btn.disabled = false; });
  };

  window.cancelOnboarding = function (queueId, btn) {
    if (!confirm('Remove this agent from the onboarding queue?')) return;
    btn.disabled = true;
    post('api/onboard_action.php?action=cancel_onboarding', { queue_id: queueId })
      .then(d => {
        if (d.ok) { loadQueue(); }
        else { btn.disabled = false; alert(d.error || 'Error'); }
      })
      .catch(() => { btn.disabled = false; });
  };

  // ── Init ───────────────────────────────────────────────────────────────────
  function initQueue() {
    // Pre-expand an entry passed via ?open= (e.g. from Advantage CRM redirect)
    if (window.ONBOARD_OPEN_ID) expandedIds.add(window.ONBOARD_OPEN_ID);
    loadQueue();
  }

  // Run on DOMContentLoaded
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initQueue);
  } else {
    initQueue();
  }

})();
