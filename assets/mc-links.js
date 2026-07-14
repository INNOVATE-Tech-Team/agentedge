// Injects market-center-specific resource links into the #mc-resources
// sidebar placeholder, and the agent's own personal favorite links into
// #my-links. Silently no-ops if a section has nothing to show.
(function () {
  function slugify(s) {
    return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  }

  function escHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ── My Resources (admin-managed, per market center) ─────────────────────
  var mcContainer = document.getElementById('mc-resources');
  if (mcContainer) {
    fetch('api/profile.php')
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var mc = d && d.profile && d.profile.marketCenter ? d.profile.marketCenter : '';
        if (!mc) return null;
        return fetch('api/mc_links.php?mc=' + encodeURIComponent(slugify(mc)));
      })
      .then(function (r) { return r ? r.json() : null; })
      .then(function (links) {
        if (!links || !links.length) return;
        var label = document.createElement('div');
        label.className = 'sb-section';
        label.textContent = 'My Resources';
        mcContainer.appendChild(label);
        links.forEach(function (link) {
          var a = document.createElement('a');
          a.className = 'sb-item';
          a.href = link.url;
          a.target = '_blank';
          a.rel = 'noopener noreferrer';
          a.innerHTML = escHtml(link.label) + ' <span class="sb-ext">↗</span>';
          mcContainer.appendChild(a);
        });
        mcContainer.hidden = false;
      })
      .catch(function () { /* MC links are optional — fail silently */ });
  }

  // ── My Links (agent's own favorites) ─────────────────────────────────────
  var myContainer = document.getElementById('my-links');
  if (myContainer) {
    function renderMyLinks(links) {
      myContainer.innerHTML = '';
      var label = document.createElement('div');
      label.className = 'sb-section';
      label.textContent = 'My Links';
      myContainer.appendChild(label);

      links.forEach(function (link) {
        var row = document.createElement('div');
        row.className = 'sb-fav-row';
        var a = document.createElement('a');
        a.className = 'sb-item sb-fav-link';
        a.href = link.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.innerHTML = escHtml(link.label) + ' <span class="sb-ext">↗</span>';
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'sb-fav-del';
        del.title = 'Remove';
        del.textContent = '×';
        del.addEventListener('click', function () {
          fetch('api/favorite_links.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: link.id })
          }).then(loadMyLinks);
        });
        row.appendChild(a);
        row.appendChild(del);
        myContainer.appendChild(row);
      });

      var addRow = document.createElement('div');
      addRow.className = 'sb-fav-add';
      addRow.innerHTML =
        '<input type="text" class="sb-fav-input" placeholder="Label">' +
        '<input type="text" class="sb-fav-input" placeholder="https://...">' +
        '<button type="button" class="sb-fav-add-btn">+ Add link</button>';
      var labelInput = addRow.querySelector('input:nth-of-type(1)');
      var urlInput   = addRow.querySelector('input:nth-of-type(2)');
      addRow.querySelector('.sb-fav-add-btn').addEventListener('click', function () {
        var labelVal = labelInput.value.trim();
        var urlVal   = urlInput.value.trim();
        if (!labelVal || !urlVal) return;
        fetch('api/favorite_links.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'add', label: labelVal, url: urlVal })
        }).then(loadMyLinks);
      });
      myContainer.appendChild(addRow);

      myContainer.hidden = false;
    }

    function loadMyLinks() {
      fetch('api/favorite_links.php')
        .then(function (r) { return r.json(); })
        .then(function (d) { renderMyLinks((d && d.links) || []); })
        .catch(function () { /* favorites are optional — fail silently */ });
    }

    loadMyLinks();
  }
})();
