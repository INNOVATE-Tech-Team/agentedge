import io

path = "launch_schedule.php"
with io.open(path, "r", encoding="utf-8") as f:
    content = f.read()

# 1. PHP: build a deduped, sorted list of roster names+state+MC for the client-side typeahead.
anchor1_old = """    return $ts ? date('F j, Y', $ts) : $d;
}
?>
<!doctype html>"""
anchor1_new = """    return $ts ? date('F j, Y', $ts) : $d;
}

$rosterNames = [];
$rosterSeen  = [];
foreach ($directory['rosterByName'] as $rowsForName) {
    foreach ($rowsForName as $rr) {
        $nm = trim($rr['agent_name'] ?? '');
        if ($nm === '') continue;
        $st  = strtoupper(trim($rr['state_code'] ?? ''));
        $key = strtolower($nm) . '|' . $st;
        if (isset($rosterSeen[$key])) continue;
        $rosterSeen[$key] = true;
        $rosterNames[] = [
            'name'  => $nm,
            'state' => $st,
            'mc'    => lr_mc_display($directory['mcCanonical'], $rr['market_center'] ?? '', $st),
        ];
    }
}
usort($rosterNames, fn($a, $b) => strcasecmp($a['name'], $b['name']));
$rosterNamesJson = json_encode($rosterNames, JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!doctype html>"""
assert content.count(anchor1_old) == 1, "anchor1 not found exactly once"
content = content.replace(anchor1_old, anchor1_new, 1)

# 2. CSS: dropdown styling, inserted right before </style>.
anchor2_old = """    .lc-intro{color:var(--faint);font-size:13px;margin-bottom:20px;max-width:80ch}
  </style>"""
anchor2_new = """    .lc-intro{color:var(--faint);font-size:13px;margin-bottom:20px;max-width:80ch}
    .ac-dropdown{position:absolute;top:100%;left:0;right:0;z-index:50;background:#fff;border:1px solid var(--border);border-radius:6px;box-shadow:0 6px 16px rgba(0,0,0,.12);max-height:230px;overflow-y:auto;margin-top:2px}
    .ac-item{padding:6px 10px;font-size:13px;cursor:pointer}
    .ac-item:hover,.ac-item.active{background:#eef5e8}
  </style>"""
assert content.count(anchor2_old) == 1, "anchor2 not found exactly once"
content = content.replace(anchor2_old, anchor2_new, 1)

# 3. JS: the typeahead widget + wiring, inserted right before </script>.
anchor3_old = """    setTimeout(() => location.reload(), 600);
  });
}
</script>
</body>
</html>"""
anchor3_new = """    setTimeout(() => location.reload(), 600);
  });
}

const ROSTER_NAMES = <?= $rosterNamesJson ?: '[]' ?>;

function attachAgentAutocomplete(input, stateInput, officeInput) {
  if (!input) return;
  const parent = input.parentElement;
  if (getComputedStyle(parent).position === 'static') parent.style.position = 'relative';
  const box = document.createElement('div');
  box.className = 'ac-dropdown';
  box.style.display = 'none';
  parent.appendChild(box);
  let items = [];
  let activeIdx = -1;

  function render(matches) {
    items = matches;
    activeIdx = -1;
    if (!matches.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
    box.innerHTML = matches.map((m, i) => {
      const loc = [m.mc, m.state].filter(Boolean).join(', ');
      return `<div class="ac-item" data-idx="${i}">${esc(m.name)}${loc ? ` <span style="color:var(--faint);font-size:11.5px">— ${esc(loc)}</span>` : ''}</div>`;
    }).join('');
    box.style.display = 'block';
  }

  function highlight() {
    [...box.children].forEach((c, i) => c.classList.toggle('active', i === activeIdx));
  }

  function select(m) {
    input.value = m.name;
    if (stateInput && !stateInput.value.trim()) stateInput.value = m.state || '';
    if (officeInput && !officeInput.value.trim()) officeInput.value = m.mc || '';
    box.style.display = 'none';
  }

  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    if (q.length < 2) { box.style.display = 'none'; return; }
    render(ROSTER_NAMES.filter(m => m.name.toLowerCase().includes(q)).slice(0, 8));
  });

  input.addEventListener('keydown', (e) => {
    if (box.style.display === 'none') return;
    if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, items.length - 1); highlight(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); highlight(); }
    else if (e.key === 'Enter') { if (activeIdx >= 0) { e.preventDefault(); select(items[activeIdx]); } }
    else if (e.key === 'Escape') { box.style.display = 'none'; }
  });

  box.addEventListener('mousedown', (e) => {
    const item = e.target.closest('.ac-item');
    if (!item) return;
    e.preventDefault();
    select(items[+item.dataset.idx]);
  });

  document.addEventListener('click', (e) => {
    if (e.target !== input && !box.contains(e.target)) box.style.display = 'none';
  });
}

attachAgentAutocomplete(document.getElementById('new-group-name'), document.getElementById('new-group-state'), document.getElementById('new-group-office'));
document.querySelectorAll('.quick-add').forEach(wrap => {
  attachAgentAutocomplete(wrap.querySelector('.add-name'), wrap.querySelector('.add-state'), wrap.querySelector('.add-office'));
});
</script>
</body>
</html>"""
assert content.count(anchor3_old) == 1, "anchor3 not found exactly once"
content = content.replace(anchor3_old, anchor3_new, 1)

with io.open(path, "w", encoding="utf-8") as f:
    f.write(content)

print("Patched OK")
