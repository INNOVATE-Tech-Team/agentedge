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
  const IS_ADMIN    = window.IS_ADMIN === true;
  const notesLoaded = new Set();   // queue ids whose notes have already been fetched
  const intakeLoaded = new Set();  // queue ids whose intake form has already been fetched
  const intakeOpenIds = new Set(); // queue ids whose intake panel is currently expanded

  // Tool key → definition map
  const TOOL_MAP = {};
  TOOLS.forEach(t => { TOOL_MAP[t.key] = t; });

  // Two-stage layout. Which stage a step belongs to comes from the server
  // (onboard_steps.stage); these lists only fix the display order inside each
  // stage (Coach/LAUNCH lead Stage 2). Unknown/legacy keys sort after these.
  const STAGE_ORDER = {
    1: ['agentedge', 'doc_signing', 'mls'],
    2: ['coach', 'launch', 'fub', 'realscout', 'constellation1', 'dotloop', 'listingstoleads', 'maxa', 'training'],
  };
  // Badge colours per derived state (the state itself and its label come from
  // the server's onboard_queue_state() — never recomputed here).
  const STATE_BADGE = {
    initial_setup_pending:  ['#FFF4DC', '#8a5a00'],
    initial_setup_complete: ['#E6F0FB', '#1f4f8a'],
    onboarding_complete:    ['#eef5e8', '#3a6b1a'],
    cancelled:              ['#f0f0f0', '#888'],
  };

  function stageSteps(steps, stage) {
    const order = STAGE_ORDER[stage];
    const rank = k => { const i = order.indexOf(k); return i < 0 ? 999 : i; };
    return steps
      .filter(st => (parseInt(st.stage, 10) || 2) === stage)
      .sort((a, b) => (rank(a.tool_key) - rank(b.tool_key)) || (a.id - b.id));
  }

  function stageHeaderHtml(n, title) {
    return `<div style="margin:16px 0 4px;padding-bottom:6px;border-bottom:2px solid #E6E7E8;display:flex;align-items:baseline;gap:8px">
      <span style="font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#82C112">Stage ${n}</span>
      <span style="font-size:14px;font-weight:800">${esc(title)}</span></div>`;
  }

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

  // ── Add-agent form submit ──────────────────────────────────────────────────
  const addForm = document.getElementById('ob-add-form');
  if (addForm) {
    addForm.addEventListener('submit', e => {
      e.preventDefault();
      const btn  = document.getElementById('ob-add-btn');
      const name = document.getElementById('ob-name')?.value.trim();
      const email= document.getElementById('ob-email')?.value.trim();
      const mc   = document.getElementById('ob-mc')?.value.trim();
      if (!name || !email) { setMsg('ob-add-msg','Name and email are required.',false); return; }
      if (!mc) { setMsg('ob-add-msg','Market Center is required.',false); return; }

      btn.disabled = true;
      setMsg('ob-add-msg','Adding…',true);

      post('api/onboard_action.php?action=add_to_queue', {
        agent_name:    name,
        agent_email:   email,
        market_center: mc,
        state_code:    document.getElementById('ob-state')?.value,
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
    // Blanking the container to "Loading…" collapses the page height and
    // clamps window scroll to top while the fetch is in flight — restore it
    // once real content is back so a save mid-list doesn't snap you to top.
    const scrollY = window.scrollY;
    container.innerHTML = '<div class="ob-empty">Loading…</div>';

    fetch('api/onboard_action.php?action=list_queue&filter=' + encodeURIComponent(currentFilter), {
      credentials: 'same-origin',
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { container.innerHTML = `<div class="ob-empty">Error: ${esc(d.error)}</div>`; return; }
        renderQueue(container, d.queue || []);
        window.scrollTo(0, scrollY);
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

    // Restore expanded state — the whole container was just rebuilt, so any
    // previously-loaded notes list is gone too; force a re-fetch for each.
    expandedIds.forEach(id => {
      const cl = container.querySelector(`.ob-checklist[data-qid="${id}"]`);
      if (cl) cl.classList.add('open');
      loadNotes(id, true);
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
    const editable   = IS_ADMIN && entry.status === 'active';
    const stage1Done = !!entry.stage1_completed_at;
    const rd         = entry.stage1 || null;   // Stage 1 readiness (active entries only), from the server

    const dots = steps.map(s => stepDotHtml(s)).join('');

    const mcList = entry.market_centers || [];

    const metaParts = [];
    if (mcList.length) metaParts.push(esc(mcList.map(m => m.market_center).join(', ')));
    else if (entry.market_center) metaParts.push(esc(entry.market_center));
    if (entry.start_date)    metaParts.push('Starts ' + esc(entry.start_date));
    if (entry.role && entry.role !== 'agent') metaParts.push(esc(entry.role.replace(/_/g,' ')));
    const meta = metaParts.join(' · ');

    const badgeCol = STATE_BADGE[entry.state] || ['#f0f0f0', '#888'];
    const statusBadge = `<span style="font-size:11px;font-weight:800;padding:2px 8px;border-radius:10px;background:${badgeCol[0]};color:${badgeCol[1]}">${esc(entry.state_label || entry.status)}</span>`;

    const stepNote = (s) => (entry.status === 'active' && !stage1Done && (s.tool_key === 'doc_signing' || s.tool_key === 'mls'))
      ? 'Required for initial setup (done or skipped)' : '';
    const stepsHtml1 = stageSteps(steps, 1).map(s => renderStep(entry.id, s, entry.status, stepNote(s))).join('');
    const stepsHtml2 = stageSteps(steps, 2).map(s => renderStep(entry.id, s, entry.status)).join('');

    const stateOptions = STATES.map(s =>
      `<option value="${s}"${entry.state_code === s ? ' selected' : ''}>${s}</option>`
    ).join('');
    const stateSelectHtml = editable ? `
      <select class="ob-state-select" onchange="setQueueState(${entry.id}, this)" title="License state (required to complete initial setup)">
        <option value="">State…</option>
        ${stateOptions}
      </select>` : '';

    // Multi-MC: a chip per assigned Market Center (with a remove "×" when
    // editable) plus an "add another" select filtered to exclude MCs already
    // assigned. Falls back to the old scalar market_center for any row not
    // yet backed by onboard_queue_mcs.
    const assignedNames = mcList.length ? mcList.map(m => m.market_center) : (entry.market_center ? [entry.market_center] : []);
    const mcChips = assignedNames.map(name => `
      <span class="ob-mc-chip" style="display:inline-flex;align-items:center;gap:4px;background:#eef5e8;color:#3a6b1a;border-radius:10px;padding:2px 8px;font-size:12px;margin:2px 4px 2px 0">
        ${esc(name)}
        ${editable ? `<button type="button" onclick="removeQueueMarketCenter(${entry.id}, '${esc(name).replace(/'/g, "\\'")}', this)" title="Remove" style="border:none;background:none;color:#3a6b1a;cursor:pointer;font-weight:700;padding:0;line-height:1">&times;</button>` : ''}
      </span>`).join('');
    const addMcOptions = MC_OPTS.filter(m => !assignedNames.includes(m.name)).map(m =>
      `<option value="${esc(m.name)}" data-state="${esc(m.state_code || '')}">${esc((m.state_code ? m.state_code + ' - ' : '') + m.name)}</option>`
    ).join('');
    const addMcSelectHtml = editable ? `
      <select class="ob-state-select" onchange="addQueueMarketCenter(${entry.id}, this)" title="Add a Market Center">
        <option value="">+ Add Market Center…</option>
        ${addMcOptions}
      </select>` : '';

    // The "skip" override below reuses mark_intake_submitted (api/onboard_action.php)
    // regardless of whether an intake was ever sent -- previously it only appeared
    // after "Send Intake" had been clicked at least once, which left no path to
    // clear the completion gate for agents whose info was typed in manually and
    // were never going to be sent a real intake form at all.
    const intakeStatusHtml = entry.intake_submitted
      ? `<button class="ob-btn-sm ob-btn-done" onclick="toggleIntake(${entry.id})">${intakeOpenIds.has(entry.id) ? 'Hide' : 'View'} Intake Form</button>`
      : entry.intake_sent_at
        ? `<span style="font-size:11px;color:#888;margin-right:8px">Intake sent ${esc(entry.intake_sent_at)}</span><button class="ob-btn-sm ob-btn-undo" onclick="sendIntake(${entry.id}, this)">Resend Intake</button><button class="ob-btn-sm ob-btn-done" style="margin-left:4px" onclick="markIntakeSubmitted(${entry.id}, this)">Mark Submitted</button>`
        : `<button class="ob-btn-sm ob-btn-undo" onclick="sendIntake(${entry.id}, this)">Send Intake</button><button class="ob-btn-sm ob-btn-done" style="margin-left:4px" onclick="markIntakeSubmitted(${entry.id}, this, true)">Skip — Entered Manually</button>`;

    // Derived Stage 1 requirements: met/missing comes from the server's
    // readiness payload (same function the completion action enforces).
    const reqMet = key => !!((rd && rd.items || []).find(i => i.key === key) || {}).met;
    function readinessRow(key, label, detail, controls) {
      const met = reqMet(key);
      const icon = met
        ? '<span class="ob-step-icon" style="background:#82C112;color:#fff">✓</span>'
        : '<span class="ob-step-icon" style="background:#E8A93A;color:#fff">!</span>';
      return `
      <div class="ob-step" id="ready-${entry.id}-${key}">
        ${icon}
        <div style="flex:1;min-width:0">
          <span class="ob-step-label">${esc(label)}</span>
          <span class="ob-step-note"> · ${met ? '' : '<strong style="color:#8a5a00">Missing</strong> — '}${detail}</span>
        </div>
        <div class="ob-step-actions" style="flex-wrap:wrap;justify-content:flex-end;align-items:center">${controls}</div>
      </div>`;
    }
    const readinessHtml = rd ? `
          ${readinessRow('intake', 'Contact / Intake', entry.intake_submitted ? 'Intake form submitted' : 'Intake form not submitted', editable ? intakeStatusHtml : '')}
          ${readinessRow('license_state', 'License State', entry.state_code ? esc(entry.state_code) : 'No license state set', stateSelectHtml)}
          ${readinessRow('market_centers', 'Market Center(s)', assignedNames.length ? esc(assignedNames.length + ' assigned') : 'None assigned', editable ? (mcChips + addMcSelectHtml) : '')}` : '';

    let stage1Action = '';
    if (stage1Done) {
      stage1Action = `<span style="font-size:12px;color:#3a6b1a;font-weight:700">✓ Initial setup completed ${esc(entry.stage1_completed_at)}${entry.stage1_completed_by ? ' by ' + esc(entry.stage1_completed_by) : ''}</span>`;
    } else if (editable) {
      stage1Action = `<button class="ob-btn-sm ob-btn-done" data-ready="${rd && rd.ready ? '1' : '0'}" data-missing="${esc(rd ? rd.missing.join(', ') : '')}"
                onclick="completeInitialSetup(${entry.id}, this)">Complete Initial Setup</button>`
        + (rd && !rd.ready ? `<span style="font-size:11px;color:#8a5a00;margin-left:8px">Outstanding: ${esc(rd.missing.join(', '))}</span>` : '');
    }
    const stage1ActionRow = stage1Action ? `<div style="padding:10px 0 4px">${stage1Action}</div>` : '';

    // Complete Onboarding needs Stage 1 done AND every Stage 2 item Done/Skipped.
    // The server (onboard_stage2_readiness) enforces this; the button state is
    // just a convenience.
    const rd2 = entry.stage2 || null;
    let stage2Action = '';
    if (editable) {
      const dis = 'disabled style="opacity:.5;cursor:not-allowed"';
      if (!stage1Done) {
        stage2Action = `<button class="ob-btn-sm ob-btn-done" ${dis} title="Complete Initial Setup first">Complete Onboarding</button><span style="font-size:11px;color:#888;margin-left:8px">Available after Initial Setup is complete</span>`;
      } else if (rd2 && !rd2.ready) {
        stage2Action = `<button class="ob-btn-sm ob-btn-done" ${dis} title="Resolve every Stage 2 item first">Complete Onboarding</button><span style="font-size:11px;color:#8a5a00;margin-left:8px">Outstanding (Done or Skip each): ${esc(rd2.missing.join(', '))}</span>`;
      } else {
        stage2Action = `<button class="ob-btn-sm ob-btn-done" onclick="completeOnboarding(${entry.id}, this)">Complete Onboarding</button>`;
      }
    }
    const stage2ActionRow = stage2Action ? `<div style="padding:10px 0 4px">${stage2Action}</div>` : '';

    const footerHtml = editable ? `
      <div class="ob-footer">
        <a class="ob-btn-sm ob-btn-done" style="text-decoration:none;display:inline-block" href="agent_profile.php?email=${encodeURIComponent(entry.agent_email)}" target="_blank">Edit Profile →</a>
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
            <div class="ob-agent-meta">${esc(entry.agent_email)}${entry.agent_phone ? ' · ' + esc(entry.agent_phone) : ''}${meta ? ' · ' + meta : ''}</div>
          </div>
          <div style="display:flex;align-items:center;gap:14px;flex-shrink:0">
            <div class="ob-dots">${dots}</div>
            <div class="ob-progress">
              <div class="ob-progress-bar"><div class="ob-progress-fill" style="width:${pct}%"></div></div>
              <span>${done}/${total}</span>
            </div>
            <span class="ob-toggle-arrow" style="font-size:12px;color:#888;margin-left:4px">${isOpen ? '▲' : '▼'}</span>
          </div>
        </div>
        <div class="ob-checklist${isOpen ? ' open' : ''}" data-qid="${entry.id}">
          ${stageHeaderHtml(1, 'Initial Setup / Paperwork')}
          ${readinessHtml}
          ${stepsHtml1 || '<div style="padding:12px 0;color:#aaa;font-size:13px">No steps found.</div>'}
          ${stage1ActionRow}
          ${stageHeaderHtml(2, 'Accounts, Marketing, Coaching & Training')}
          ${stepsHtml2 || '<div style="padding:12px 0;color:#aaa;font-size:13px">No steps found.</div>'}
          ${stage2ActionRow}
          ${entry.intake_submitted ? `
          <div class="ob-intake" id="ob-intake-${entry.id}" data-email="${esc(entry.agent_email)}" style="display:${intakeOpenIds.has(entry.id) ? 'block' : 'none'};margin:10px 0;padding:12px;border:1px solid #E6E7E8;border-radius:8px;background:#fafbfa">
            <div id="ob-intake-body-${entry.id}" style="font-size:12px;color:#aaa">Loading intake form…</div>
          </div>` : ''}
          <div class="ob-notes" id="ob-notes-${entry.id}" data-email="${esc(entry.agent_email)}">
            <div class="ob-notes-list" id="ob-notes-list-${entry.id}" style="font-size:12px;color:#aaa">Loading notes…</div>
            <div style="display:flex;gap:8px;margin-top:8px">
              <textarea id="ob-notes-input-${entry.id}" placeholder="Add a note (admin/BIC/ML only — not visible to the agent)…" rows="1"
                        oninput="this.style.height='auto';this.style.height=this.scrollHeight+'px';"
                        style="flex:1;padding:6px 8px;border:1px solid #E6E7E8;border-radius:6px;font-size:12px;font-family:inherit;resize:none;overflow:hidden;max-height:200px"></textarea>
              <button class="ob-btn-sm ob-btn-done" onclick="addOnboardNote(${entry.id})">Add Note</button>
            </div>
          </div>
          ${footerHtml}
        </div>
      </div>`;
  }

  function renderStep(queueId, step, queueStatus, extraNote) {
    const toolDef  = TOOL_MAP[step.tool_key] || {};
    const note     = [toolDef.note || '', extraNote || ''].filter(Boolean).join(' · ');
    const isAuto   = parseInt(step.is_auto, 10) === 1;
    const disabled = queueStatus !== 'active';

    let actionsHtml = '';
    if (!disabled && IS_ADMIN) {
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
      loadNotes(queueId);
    } else {
      expandedIds.delete(queueId);
    }
    // Flip the arrow
    const arrow = head?.querySelector('.ob-toggle-arrow');
    if (arrow) arrow.textContent = open ? '▲' : '▼';
  };

  // ── Notes (admin/BIC/ML only — enforced server-side by api/agent_notes.php,
  // never surfaced to the agent since this whole page is staff-only) ─────────
  function renderNotes(queueId, notes) {
    const list = document.getElementById('ob-notes-list-' + queueId);
    if (!list) return;
    if (!notes.length) { list.innerHTML = '<div style="color:#aaa">No notes yet.</div>'; return; }
    list.innerHTML = notes.map(n => `
      <div class="ob-note" style="padding:6px 0;border-bottom:1px solid #F0F0F0">
        <div style="white-space:pre-wrap">${esc(n.note)}</div>
        <div style="font-size:11px;color:#aaa;margin-top:2px">${esc(n.created_by)} · ${esc(n.created_at)}</div>
      </div>`).join('');
  }

  function loadNotes(queueId, force) {
    if (notesLoaded.has(queueId) && !force) return;
    const wrap = document.getElementById('ob-notes-' + queueId);
    const email = wrap?.dataset.email;
    if (!email) return;
    fetch('api/agent_notes.php?email=' + encodeURIComponent(email), { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        notesLoaded.add(queueId);
        if (d.ok) renderNotes(queueId, d.notes || []);
      })
      .catch(() => {});
  }

  // ── Intake form (read-only, admin only — needed to create the agent's
  // accounts before onboarding is marked complete) ────────────────────────────
  const INTAKE_FIELD_SECTIONS = [
    { title: 'Contact', fields: [
      ['full_name', 'Full Name'], ['personal_email', 'Personal Email'], ['commissions_email', 'Commissions Email'],
      ['phone', 'Phone'], ['phone_last4', 'Phone Last 4 (payroll)'], ['birthday', 'Birthday'],
    ]},
    { title: 'Address', fields: [
      ['address_line1', 'Address Line 1'], ['address_line2', 'Address Line 2'], ['city', 'City'],
      ['state', 'State'], ['zip', 'Zip'], ['country', 'Country'],
    ]},
    { title: 'License & MLS', fields: [
      ['license_number', 'License Number'], ['license_state', 'License State'], ['license_exp', 'License Expiration'],
      ['nar_number', 'NAR Number'], ['mls_board', 'MLS Board'], ['mls_id', 'MLS ID'],
    ]},
    { title: 'Business & Tax', fields: [
      ['personal_tax_id_last4', 'Personal Tax ID (last 4)'], ['corporate_tax_id_last4', 'Corporate Tax ID (last 4)'],
    ]},
    { title: 'Emergency Contact', fields: [
      ['emergency_name', 'Emergency Contact Name'], ['emergency_phone', 'Emergency Contact Phone'],
    ]},
    { title: 'Personal', fields: [
      ['drivers_license', "Driver's License #"], ['tshirt_size', 'T-Shirt Size'], ['languages', 'Languages'],
    ]},
  ];

  function ivField(label, value) {
    const v = (value ?? '').toString().trim();
    return `<div style="display:flex;flex-direction:column;gap:2px">
      <span style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:var(--ink)">${esc(label)}</span>
      <span style="font-size:12.5px;color:${v ? 'var(--muted)' : 'var(--faint)'};${v ? '' : 'font-style:italic'}">${v ? esc(v) : '—'}</span>
    </div>`;
  }

  function renderIntakeGrid(queueId, intake) {
    const body = document.getElementById('ob-intake-body-' + queueId);
    if (!body) return;
    if (!intake) { body.innerHTML = '<div style="color:#aaa">No intake form on file.</div>'; return; }

    const sections = INTAKE_FIELD_SECTIONS.map(sec => `
      <div style="margin-bottom:10px">
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--faint);margin-bottom:6px">${esc(sec.title)}</div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px 16px">
          ${sec.fields.map(([key, label]) => ivField(label, intake[key])).join('')}
        </div>
      </div>`).join('');

    const licenses = (intake.additional_licenses || []);
    const licensesHtml = licenses.length ? `
      <div style="margin-bottom:10px">
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--faint);margin-bottom:6px">Additional Licensed States</div>
        <div style="font-size:12.5px;color:var(--muted)">${licenses.map(l => esc([l.license_number, l.license_state, l.license_exp ? '(exp. ' + l.license_exp + ')' : ''].filter(Boolean).join(' — '))).join('<br>')}</div>
      </div>` : '';

    const bioHtml = intake.bio ? `
      <div>
        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--faint);margin-bottom:6px">Bio</div>
        <div style="font-size:12.5px;color:var(--muted);white-space:pre-wrap;max-height:120px;overflow-y:auto">${esc(intake.bio)}</div>
      </div>` : '';

    body.innerHTML = sections + licensesHtml + bioHtml;
  }

  function loadIntake(queueId, force) {
    if (intakeLoaded.has(queueId) && !force) return;
    const wrap = document.getElementById('ob-intake-' + queueId);
    const email = wrap?.dataset.email;
    if (!email) return;
    fetch('api/onboard_action.php?action=get_intake&email=' + encodeURIComponent(email), { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        intakeLoaded.add(queueId);
        if (d.ok) renderIntakeGrid(queueId, d.intake);
        else { const body = document.getElementById('ob-intake-body-' + queueId); if (body) body.textContent = d.error || 'Failed to load intake form.'; }
      })
      .catch(() => {});
  }

  window.toggleIntake = function (queueId) {
    const wrap = document.getElementById('ob-intake-' + queueId);
    if (!wrap) return;
    const open = wrap.style.display !== 'block';
    wrap.style.display = open ? 'block' : 'none';
    if (open) { intakeOpenIds.add(queueId); loadIntake(queueId); } else { intakeOpenIds.delete(queueId); }
    const btn = document.querySelector(`#ob-row-${queueId} .ob-footer button[onclick="toggleIntake(${queueId})"]`);
    if (btn) btn.textContent = (open ? 'Hide' : 'View') + ' Intake Form';
  };

  window.addOnboardNote = function (queueId) {
    const input = document.getElementById('ob-notes-input-' + queueId);
    const wrap  = document.getElementById('ob-notes-' + queueId);
    const email = wrap?.dataset.email;
    const note  = (input?.value || '').trim();
    if (!note || !email) return;
    post('api/agent_notes.php', { email, note })
      .then(d => {
        if (d.ok) { input.value = ''; input.style.height = 'auto'; loadNotes(queueId, true); }
        else { alert(d.error || 'Could not save note.'); }
      })
      .catch(() => { alert('Network error saving note.'); });
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

  // ── Set license state on a queue entry ─────────────────────────────────────
  window.setQueueState = function (queueId, select) {
    const state = select.value;
    if (!state) return;
    select.disabled = true;
    post('api/onboard_action.php?action=set_state', { queue_id: queueId, state_code: state })
      .then(d => {
        select.disabled = false;
        if (!d.ok) { alert(d.error || 'Could not set state.'); return; }
        const btn = document.querySelector(`#ob-row-${queueId} .ob-btn-done`);
        if (btn) btn.dataset.hasState = '1';
      })
      .catch(() => { select.disabled = false; });
  };

  // ── Add / remove a Market Center on a queue entry ──────────────────────────
  // An agent can be queued into more than one Market Center at once (e.g.
  // licensed/working in bordering states) — these are additive, not an
  // overwrite of a single value.
  window.addQueueMarketCenter = function (queueId, select) {
    const mc = select.value;
    if (!mc) return;
    const opt   = select.options[select.selectedIndex];
    const state = opt?.dataset.state || '';
    select.disabled = true;
    post('api/onboard_action.php?action=add_market_center', { queue_id: queueId, market_center: mc, state_code: state })
      .then(d => {
        if (!d.ok) { select.disabled = false; alert(d.error || 'Could not add Market Center.'); return; }
        loadQueue();
      })
      .catch(() => { select.disabled = false; });
  };

  window.removeQueueMarketCenter = function (queueId, marketCenter, btn) {
    if (!confirm(`Remove ${marketCenter} from this agent's queue entry?`)) return;
    btn.disabled = true;
    post('api/onboard_action.php?action=remove_market_center', { queue_id: queueId, market_center: marketCenter })
      .then(d => {
        if (!d.ok) { btn.disabled = false; alert(d.error || 'Could not remove Market Center.'); return; }
        loadQueue();
      })
      .catch(() => { btn.disabled = false; });
  };

  // ── Complete / Cancel queue entry ──────────────────────────────────────────
  // Stage 1: server enforces the same readiness gates (onboard_stage1_readiness);
  // the data-ready/data-missing attributes just save a round trip.
  window.completeInitialSetup = function (queueId, btn) {
    if (btn.dataset.ready !== '1') {
      alert('Initial setup can\'t be completed yet. Still outstanding: ' + (btn.dataset.missing || 'see the checklist') + '.');
      return;
    }
    if (!confirm('Complete initial setup for this agent?\n\nThis makes sure they\'re on the roster, sends their welcome email, notifies their Market Center leaders, and schedules the 10-day check-in text. It can only be done once.')) return;
    btn.disabled = true;
    post('api/onboard_action.php?action=complete_initial_setup', { queue_id: queueId })
      .then(d => {
        if (d.ok) { loadQueue(); }
        else { btn.disabled = false; alert(d.error || 'Error'); }
      })
      .catch(() => { btn.disabled = false; });
  };

  // Stage 2 close-out: only flips the entry to Completed — no emails or tasks.
  window.completeOnboarding = function (queueId, btn) {
    if (!confirm('Mark this agent\'s onboarding as complete? This closes out the whole onboarding process; no further emails are sent.')) return;
    btn.disabled = true;
    post('api/onboard_action.php?action=complete_onboarding', { queue_id: queueId })
      .then(d => {
        if (d.ok) { loadQueue(); }
        else { btn.disabled = false; alert(d.error || 'Error'); }
      })
      .catch(() => { btn.disabled = false; });
  };

  window.sendIntake = function (queueId, btn) {
    if (!confirm('Send the intake form link to this agent by email?')) return;
    btn.disabled = true;
    post('api/onboard_action.php?action=send_intake', { queue_id: queueId })
      .then(d => {
        if (d.ok) { loadQueue(); }
        else { btn.disabled = false; alert(d.error || 'Error'); }
      })
      .catch(() => { btn.disabled = false; });
  };

  window.markIntakeSubmitted = function (queueId, btn, manual) {
    const msg = manual
      ? 'Skip the intake form for this agent? Use this only when you\'ve already entered their info manually and they don\'t need to fill one out themselves. This will notify staff.'
      : 'Mark this agent\'s intake form as submitted? This will notify staff.';
    if (!confirm(msg)) return;
    btn.disabled = true;
    post('api/onboard_action.php?action=mark_intake_submitted', { queue_id: queueId })
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
