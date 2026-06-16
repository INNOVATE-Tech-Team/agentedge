// Injects market-center-specific resource links into the #mc-resources
// sidebar placeholder. Silently no-ops if the agent has no matching MC entry.
(function () {
  var container = document.getElementById('mc-resources');
  if (!container) return;

  function slugify(s) {
    return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  }

  function escHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

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
      container.appendChild(label);
      links.forEach(function (link) {
        var a = document.createElement('a');
        a.className = 'sb-item';
        a.href = link.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.innerHTML = escHtml(link.label) + ' <span class="sb-ext">↗</span>';
        container.appendChild(a);
      });
      container.hidden = false;
    })
    .catch(function () { /* MC links are optional — fail silently */ });
})();
