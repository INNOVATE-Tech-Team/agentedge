// How-To library search box, injected into every page's sidebar footer by
// render_sidebar() (nav.php). Same debounce/dropdown/keyboard-nav shape as
// network.php's agent search (api/agent_search.php) -- kept consistent
// rather than inventing a new interaction pattern.
(function () {
  const box = document.getElementById('sb-search-box');
  const input = document.getElementById('sb-search-input');
  const results = document.getElementById('sb-search-results');
  if (!box || !input || !results) return;

  let timer = null;
  let active = -1;

  window.toggleHowtoSearch = function () {
    box.hidden = !box.hidden;
    if (!box.hidden) input.focus();
    else closeResults();
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }

  function closeResults() {
    results.hidden = true;
    results.innerHTML = '';
    active = -1;
  }

  function render(items) {
    if (!items.length) { closeResults(); return; }
    // Snippets come from api/howto_search.php's FTS5 snippet() call with
    // <mark></mark> already inserted server-side around matched terms --
    // safe to drop in as-is since the label/href fields (the only other
    // dynamic values here) are still escaped individually below.
    results.innerHTML = items.map((r, i) =>
      `<a class="sb-search-item" href="howto.php?key=${encodeURIComponent(r.pageKey)}" data-idx="${i}">` +
      `<div class="sb-search-item-label">${esc(r.label)}</div>` +
      `<div class="sb-search-item-snippet">${r.snippet}</div></a>`
    ).join('');
    results.hidden = false;
    active = -1;
  }

  function doSearch(q) {
    if (q.length < 2) { closeResults(); return; }
    fetch('api/howto_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
      .then((r) => r.json())
      .then((d) => render(d.results || []))
      .catch(() => closeResults());
  }

  input.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => doSearch(input.value.trim()), 250);
  });

  input.addEventListener('keydown', (e) => {
    const items = [...results.querySelectorAll('.sb-search-item')];
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      active = Math.min(active + 1, items.length - 1);
      items.forEach((el, i) => el.classList.toggle('active', i === active));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      active = Math.max(active - 1, -1);
      items.forEach((el, i) => el.classList.toggle('active', i === active));
    } else if (e.key === 'Enter') {
      if (active >= 0 && items[active]) {
        e.preventDefault();
        window.location.href = items[active].getAttribute('href');
      }
    } else if (e.key === 'Escape') {
      closeResults();
      box.hidden = true;
    }
  });

  input.addEventListener('blur', () => setTimeout(closeResults, 150));

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.sb-search-wrap')) { box.hidden = true; closeResults(); }
  });
})();
