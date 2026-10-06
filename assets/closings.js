// Closings Tracker — modal open/close, live commission preview, and save/
// delete against api/closings_action.php. The preview mirrors closing_calc()
// in lib/closings.php for immediate feedback only — the server always
// recomputes and is the source of truth for what actually gets stored/shown.
(function () {
  var overlay = document.getElementById('cl-overlay');
  var form    = document.getElementById('cl-form');
  var errBox  = document.getElementById('cl-err');
  if (!overlay || !form) return;

  var FIELDS = [
    'id', 'property_address', 'agent_email', 'status', 'client_names', 'client_email',
    'sale_price', 'lead_source', 'contract_date', 'closing_date_est', 'closing_date_actual',
    'commission_rate', 'bonus_amount', 'dwt_percent', 'outgoing_referral_percent',
  ];

  function el(id) { return document.getElementById('f_' + id) || document.getElementById(id); }

  window.openClosingModal = function (row) {
    errBox.style.display = 'none';
    form.reset();
    el('id').value = '';
    if (row) {
      document.getElementById('cl-modal-title').textContent = 'Edit Closing';
      FIELDS.forEach(function (f) {
        var input = el(f);
        if (!input) return;
        if (f === 'commission_rate' || f === 'dwt_percent' || f === 'outgoing_referral_percent') {
          input.value = row[f] ? (parseFloat(row[f]) * 100) : 0;
        } else if (row[f] !== undefined && row[f] !== null) {
          input.value = row[f];
        }
      });
    } else {
      document.getElementById('cl-modal-title').textContent = 'Add Closing';
    }
    updatePreview();
    overlay.classList.add('show');
  };

  window.closeClosingModal = function () {
    overlay.classList.remove('show');
  };

  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) closeClosingModal();
  });

  function fmtMoney(n) {
    return '$' + Math.round(n).toLocaleString();
  }

  function updatePreview() {
    var price  = parseFloat(el('sale_price').value) || 0;
    var rate   = (parseFloat(el('commission_rate').value) || 0) / 100;
    var bonus  = parseFloat(el('bonus_amount').value) || 0;
    var refPct = (parseFloat(el('outgoing_referral_percent').value) || 0) / 100;
    var dwtPct = (parseFloat(el('dwt_percent').value) || 0) / 100;

    var gci  = (price * rate) + bonus;
    var ref  = gci * refPct;
    var net  = gci - ref;
    var team = net * (1 - dwtPct);
    var dwt  = net * dwtPct;

    document.getElementById('pv_gci').textContent  = fmtMoney(gci);
    document.getElementById('pv_ref').textContent  = fmtMoney(ref);
    document.getElementById('pv_team').textContent = fmtMoney(team);
    document.getElementById('pv_dwt').textContent  = fmtMoney(dwt);
  }

  ['sale_price', 'commission_rate', 'bonus_amount', 'dwt_percent', 'outgoing_referral_percent']
    .forEach(function (f) { var i = el(f); if (i) i.addEventListener('input', updatePreview); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var payload = { action: 'save' };
    FIELDS.forEach(function (f) {
      var input = el(f);
      if (!input) return;
      if (f === 'commission_rate' || f === 'dwt_percent' || f === 'outgoing_referral_percent') {
        payload[f] = (parseFloat(input.value) || 0) / 100;
      } else {
        payload[f] = input.value;
      }
    });
    if (payload.id) payload.id = parseInt(payload.id, 10);

    var btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    fetch('api/closings_action.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        if (d.ok) {
          location.reload();
        } else {
          errBox.textContent = d.error || 'Could not save this closing.';
          errBox.style.display = 'block';
        }
      })
      .catch(function () {
        btn.disabled = false;
        errBox.textContent = 'Network error — please try again.';
        errBox.style.display = 'block';
      });
  });

  window.deleteClosing = function (id) {
    if (!confirm('Delete this closing? This cannot be undone.')) return;
    fetch('api/closings_action.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', id: id }),
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.ok) location.reload();
        else alert(d.error || 'Could not delete this closing.');
      })
      .catch(function () { alert('Network error — please try again.'); });
  };

  var splitForm = document.getElementById('split-form');
  if (splitForm) {
    splitForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var teamId = document.getElementById('split_team_id').value;
      fetch('api/closings_action.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'save_split_rule',
          team_id: teamId,
          agent_email: '',
          default_commission_rate: (parseFloat(document.getElementById('split_commission').value) || 0) / 100,
          default_dwt_percent: (parseFloat(document.getElementById('split_dwt').value) || 0) / 100,
        }),
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.ok) {
            var saved = document.getElementById('split-saved');
            saved.style.display = 'inline';
            setTimeout(function () { saved.style.display = 'none'; }, 2000);
          } else {
            alert(d.error || 'Could not save split defaults.');
          }
        })
        .catch(function () { alert('Network error — please try again.'); });
    });
  }
}());
