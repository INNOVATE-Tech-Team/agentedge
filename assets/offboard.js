// AgentEdge — Offboarding Dashboard
// Handles: queue rendering, add-agent form, CRM search autocomplete,
// mark-done / deprovision actions, tab switching, expand/collapse.

(function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────────────
  let currentFilter = 'active';
  let expandedIds   = new Set();
  const TOOLS       = window.OFFBOARD_TOOLS || [];

  const TOOL_MAP = {};
  TOOLS.forEach(t => { TOOL_MAP[t.key] = t; });

  const REASON_LABELS = {
    voluntary:   'Voluntary Resignation',
    termination: 'Termination',
    transfer:    'Transfer to Another Brokerage',
    other:       'Other',
  };

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
    btn.textContent = open ? '— Close' : '+ Start Offboarding';
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
      setTimeout(hideResults, 200);
    });
  }

  function hideResults() {
    if (crmResults) { crmResults.style.display = 'none'; crmResults.innerHTML = ''; }
  }

  function fetchCRM(q) {
    fetch('api/offboard_action.php?action=search_crm&q=' + encodeURIComponent(q), {
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
      const btn   = document.getElementById('ob-add-btn');
      const name  = document.getElementById('ob-name')?.value.trim();
      const email = document.getElementById('ob-email')?.value.trim();
      if (!name || !email) { setMsg('ob-add-msg','Name and email are required.',false); return; }

      btn.disabled = true;
      setMsg('ob-add-msg','Adding…',true);

      post('api/offboard_action.php?action=add_to_queue', {
        agent_name:    name,
        agent_email:   email,
        market_center: document.getElementById('ob-mc')?.value.trim(),
        last_day:      document.getElementById('ob-last-day')?.value,
        reason:        document.getElementById('ob-reason')?.value,
        reason_notes:  document.getElementById('ob-reason-notes')?.value.trim(),
        book_of_biz_to: document.getElementById('ob-book-of-biz')?.value.trim(),
      })
        .then(d => {
          btn.disabled = false;
          if (d.ok) {
            setMsg('ob-add-msg', name + ' added to offboarding queue.', true);
            addForm.reset();
            expandedIds.add(d.id);
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

    fetch('api/offboard_action.php?action=list_queue&filter=' + encodeURIComponent(currentFilter), {
      credentials: 'same-origin',
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { container.innerHTML = `<div class="ob-empty">Error: ${esc(d.error)}</div>`; return; }
        renderQueue(container, d.queue || []);
      })
      .catch(() => {
        container.innerHTML = '<div class="ob-empty">Could not load queue.</div>';
      });
  }

  // ── Queue renderer ─────────────────────────────────────────────────────────
  function renderQueue(container, queue) {
    if (!queue.length) {
      const msgs = {
        active:    'No active offboarding agents.',
        completed: 'No completed offboarding records.',
        all:       'The offboarding queue is empty.',
      };
      container.innerHTML = `<div class="ob-empty">${esc(msgs[currentFilter] || 'Nothing here.')}</div>`;
      return;
    }

    container.innerHTML = queue.map(entry => renderEntry(entry)).join('');

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
      failed:  'ob-dot-failed',
      skipped: 'ob-dot-skipped',
    }[step.status] || 'ob-dot-pending';
    return `<span class="ob-dot ${dotClass}" title="${esc(label + ': ' + step.status)}"></span>`;
  }

  function stepIconHtml(status) {
    const cfg = {
      done:    { bg:'#82C112', color:'#fff', char:'✓' },
      pending: { bg:'#E6E7E8', color:'#888', char:'○' },
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
    if (entry.last_day)      metaParts.push('Last day ' + esc(entry.last_day));
    if (entry.reason)        metaParts.push(esc(REASON_LABELS[entry.reason] || entry.reason));
    const meta = metaParts.join(' · ');

    const statusBadge = entry.status !== 'active'
      ? `<span style="font-size:11px;font-weight:800;padding:2px 8px;border-radius:10px;background:${entry.status==='completed'?'#eef5e8':'#f0f0f0'};color:${entry.status==='completed'?'#3a6b1a':'#888'}">${esc(entry.status)}</span>`
      : '';

    // Departure reason detail strip (shown in the expanded checklist header)
    const reasonDetail = (entry.reason_notes || entry.book_of_biz_to) ? `
      <div style="padding:10px 14px 0;font-size:12px;color:#888;border-bottom:1px solid #f0f0f0;margin-bottom:6px">
        ${entry.reason_notes  ? `<span><strong>Notes:</strong> ${esc(entry.reason_notes)}</span>` : ''}
        ${entry.book_of_biz_to ? `<span style="margin-left:12px"><strong>Book of biz to:</strong> ${esc(entry.book_of_biz_to)}</span>` : ''}
      </div>` : '';

    const stepsHtml = steps.map(s => renderStep(entry.id, s, entry.status, entry.agent_email)).join('');

    const footerHtml = entry.status === 'active' ? `
      <div class="ob-footer">
        <button class="ob-btn-sm ob-btn-done" onclick="completeOffboarding(${entry.id}, this)">Complete Offboarding</button>
        <button class="ob-btn-sm ob-btn-undo" onclick="cancelOffboarding(${entry.id}, this)">Cancel / Remove</button>
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
          ${reasonDetail}
          ${stepsHtml || '<div style="padding:12px 0;color:#aaa;font-size:13px">No steps found.</div>'}
          ${footerHtml}
        </div>
      </div>`;
  }

  function renderStep(queueId, step, queueStatus, agentEmail) {
    const toolDef    = TOOL_MAP[step.tool_key] || {};
    const note       = toolDef.note || '';
    const isAuto     = parseInt(step.is_auto, 10) === 1;
    const disabled   = queueStatus !== 'active';
    const isExitIntv = step.tool_key === 'exit_interview';

    let actionsHtml = '';
    if (!disabled) {
      if (step.status === 'done') {
        actionsHtml = `<button class="ob-btn-sm ob-btn-undo" onclick="markStep(${queueId},'${esc(step.tool_key)}','pending',this)">Undo</button>`;
        if (isExitIntv) {
          actionsHtml += ` <button class="ob-btn-sm" onclick="toggleExitInterview('${esc(agentEmail)}',${queueId})">View Responses</button>`;
        }
      } else if (step.status === 'skipped') {
        actionsHtml = `<button class="ob-btn-sm ob-btn-undo" onclick="markStep(${queueId},'${esc(step.tool_key)}','pending',this)">Unskip</button>`;
      } else {
        actionsHtml = `<button class="ob-btn-sm ob-btn-done" onclick="markStep(${queueId},'${esc(step.tool_key)}','done',this)">Mark Done</button>
                       <button class="ob-btn-sm ob-btn-undo" onclick="markStep(${queueId},'${esc(step.tool_key)}','skipped',this)">Skip</button>`;
        if (isAuto) {
          actionsHtml += ` <button class="ob-btn-sm ob-btn-provision" id="prov-${queueId}-${esc(step.tool_key)}"
                            onclick="provisionStep(${queueId},'${esc(step.tool_key)}',this)">Deprovision Now</button>`;
        }
        if (isExitIntv) {
          actionsHtml += ` <button class="ob-btn-sm ob-btn-provision" onclick="sendExitInterview(${queueId},this)">Send Exit Interview</button>`;
        }
      }
    }

    const errorNote = step.error_msg
      ? `<span style="font-size:11px;color:#C0392B;margin-left:6px" title="${esc(step.error_msg)}">⚠ ${esc(step.error_msg)}</span>`
      : '';

    const doneInfo = step.done_at
      ? `<span style="font-size:11px;color:#aaa"> · ${esc(step.done_by || '')} ${esc(step.done_at)}</span>`
      : '';

    const exitDetailHtml = isExitIntv
      ? `<div class="ob-exit-detail" id="ei-detail-${queueId}" style="display:none;flex-basis:100%;font-size:12px;color:#555;background:#fafafa;border-radius:6px;padding:10px 12px;margin-top:6px"></div>`
      : '';

    return `
      <div class="ob-step" id="step-${queueId}-${step.tool_key}" style="flex-wrap:wrap">
        ${stepIconHtml(step.status)}
        <div style="flex:1;min-width:0">
          <span class="ob-step-label">${esc(step.tool_label)}</span>
          ${note ? `<span class="ob-step-note"> · ${esc(note)}</span>` : ''}
          ${errorNote}
          ${doneInfo}
        </div>
        <div class="ob-step-actions">${actionsHtml}</div>
        ${exitDetailHtml}
      </div>`;
  }

  // ── Exit interview: send link / view submitted responses ──────────────────
  window.sendExitInterview = function (queueId, btn) {
    btn.disabled = true;
    const origText = btn.textContent;
    btn.textContent = 'Sending…';

    post('api/offboard_action.php?action=send_exit_interview', { queue_id: queueId })
      .then(d => {
        btn.disabled = false;
        btn.textContent = d.ok ? 'Sent ✓' : origText;
        if (!d.ok) alert('Error: ' + (d.error || 'Could not send exit interview.'));
        else setTimeout(() => { btn.textContent = origText; }, 3000);
      })
      .catch(() => { btn.disabled = false; btn.textContent = origText; });
  };

  const EI_RECOMMEND_LABELS = { yes: 'Yes', maybe: 'Maybe', no: 'No' };

  window.toggleExitInterview = function (agentEmail, queueId) {
    const box = document.getElementById(`ei-detail-${queueId}`);
    if (!box) return;
    if (box.style.display !== 'none') { box.style.display = 'none'; return; }

    box.style.display = 'block';
    box.textContent = 'Loading…';

    fetch('api/exit_interview.php?email=' + encodeURIComponent(agentEmail), { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        const ei = (d.ok && d.exit_interview) ? d.exit_interview : null;
        if (!ei || !ei.submitted) { box.innerHTML = 'No exit interview on file.'; return; }
        const rows = [
          ['Satisfaction', ei.satisfaction_rating ? ei.satisfaction_rating + ' / 5' : '—'],
          ['Management feedback', ei.feedback_management || '—'],
          ['Support feedback', ei.feedback_support || '—'],
          ['Training feedback', ei.feedback_training || '—'],
          ['Next destination', ei.next_destination || '—'],
          ['Would recommend', EI_RECOMMEND_LABELS[ei.would_recommend] || '—'],
          ['Suggestions', ei.suggestions || '—'],
        ];
        box.innerHTML = rows.map(([label, val]) =>
          `<div style="margin-bottom:4px"><strong>${esc(label)}:</strong> ${esc(val)}</div>`
        ).join('');
      })
      .catch(() => { box.textContent = 'Could not load exit interview.'; });
  };

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
    const arrow = head?.querySelector('span:last-child');
    if (arrow) arrow.textContent = open ? '▲' : '▼';
  };

  // ── Mark step done/pending/skipped ─────────────────────────────────────────
  window.markStep = function (queueId, toolKey, status, btn) {
    btn.disabled = true;
    const origText = btn.textContent;
    btn.textContent = '…';

    post('api/offboard_action.php?action=mark_done', { queue_id: queueId, tool_key: toolKey, status })
      .then(d => {
        if (d.ok) {
          loadQueue();
        } else {
          btn.disabled    = false;
          btn.textContent = origText;
          alert('Error: ' + (d.error || 'Could not update step.'));
        }
      })
      .catch(() => { btn.disabled = false; btn.textContent = origText; });
  };

  // ── Deprovision step via API ───────────────────────────────────────────────
  window.provisionStep = function (queueId, toolKey, btn) {
    btn.disabled    = true;
    const origText  = btn.textContent;
    btn.textContent = '⏳';
    btn.style.opacity = '0.7';

    post('api/offboard_action.php?action=provision', { queue_id: queueId, tool_key: toolKey })
      .then(d => {
        if (d.ok) {
          loadQueue();
        } else {
          btn.disabled    = false;
          btn.textContent = origText;
          btn.style.opacity = '1';
          alert('Deprovision failed: ' + (d.error || 'Unknown error'));
        }
      })
      .catch(() => {
        btn.disabled    = false;
        btn.textContent = origText;
        btn.style.opacity = '1';
      });
  };

  // ── Complete / Cancel queue entry ──────────────────────────────────────────
  window.completeOffboarding = function (queueId, btn) {
    if (!confirm('Mark this agent\'s offboarding as complete?')) return;
    btn.disabled = true;
    post('api/offboard_action.php?action=complete_offboarding', { queue_id: queueId })
      .then(d => {
        if (d.ok) { loadQueue(); }
        else { btn.disabled = false; alert(d.error || 'Error'); }
      })
      .catch(() => { btn.disabled = false; });
  };

  window.cancelOffboarding = function (queueId, btn) {
    if (!confirm('Remove this agent from the offboarding queue?')) return;
    btn.disabled = true;
    post('api/offboard_action.php?action=cancel_offboarding', { queue_id: queueId })
      .then(d => {
        if (d.ok) { loadQueue(); }
        else { btn.disabled = false; alert(d.error || 'Error'); }
      })
      .catch(() => { btn.disabled = false; });
  };

  // ── Init ───────────────────────────────────────────────────────────────────
  function initQueue() {
    // Pre-expand an entry passed via ?open= (e.g. from agent_profile.php's Task Checklist tab)
    if (window.OFFBOARD_OPEN_ID) expandedIds.add(window.OFFBOARD_OPEN_ID);
    loadQueue();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initQueue);
  } else {
    initQueue();
  }

})();
