// Photo manager shared by My Profile, the intake form and the admin agent
// profile: upload up to 5 photos (kept uncropped, for marketing use), pick
// one as the headshot and frame its square crop, adjust or switch later,
// download or delete photos.
//
//   HeadshotManager.mount(containerEl, { email: 'agent@x.com' })
//
// `email` is only needed when staff manage another agent's photos. Needs
// assets/headshot-crop.js loaded first. Talks to api/intake.php
// (upload / set_headshot / delete_file / headshot / headshot_crop).
(function () {
  var MAX_PHOTOS = 5;
  var ORIG_MAX = 3000;

  function injectStyles() {
    if (document.getElementById('hsm-styles')) return;
    var s = document.createElement('style');
    s.id = 'hsm-styles';
    s.textContent =
      '.hsm-current{display:flex;gap:16px;align-items:center;padding:14px;border:1px solid var(--border,#e3e3e3);border-radius:10px;background:#fafcf7;margin-bottom:16px}' +
      '.hsm-current-img{width:96px;height:96px;border-radius:8px;object-fit:cover;background:#eee;flex-shrink:0}' +
      '.hsm-current-empty{width:96px;height:96px;border-radius:8px;border:1px dashed #c9c9c9;display:flex;align-items:center;justify-content:center;font-size:11px;color:#999;text-align:center;flex-shrink:0}' +
      '.hsm-current-title{font-size:13px;font-weight:800;margin-bottom:3px}' +
      '.hsm-current-text{font-size:12px;color:#777;line-height:1.45}' +
      '.hsm-grid{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px}' +
      '.hsm-card{width:140px;border:1px solid var(--border,#e3e3e3);border-radius:8px;overflow:hidden;background:#fff}' +
      '.hsm-card.is-headshot{border-color:#82C112;box-shadow:0 0 0 2px rgba(130,193,18,.35)}' +
      '.hsm-photo{position:relative;height:128px;background:#f2f2f2;display:flex;align-items:center;justify-content:center}' +
      '.hsm-photo img{max-width:100%;max-height:100%;object-fit:contain}' +
      '.hsm-badge{position:absolute;left:6px;top:6px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;border-radius:999px;padding:2px 8px;background:#82C112;color:#fff}' +
      '.hsm-badge.low{left:auto;right:6px;background:#b26a00}' +
      '.hsm-actions{display:flex;flex-direction:column;gap:4px;padding:8px}' +
      '.hsm-btn{font-size:12px;font-weight:700;border-radius:6px;padding:6px 8px;border:1px solid #ccc;background:#fff;color:#444;cursor:pointer;text-align:center;text-decoration:none;display:block}' +
      '.hsm-btn:hover{border-color:#82C112;color:#5b8e0d}' +
      '.hsm-btn.primary{background:#82C112;border-color:#82C112;color:#fff}' +
      '.hsm-btn.primary:hover{background:#6fa60f;color:#fff}' +
      '.hsm-row{display:flex;gap:4px}.hsm-row .hsm-btn{flex:1;min-width:0;padding:6px 2px}' +
      '.hsm-btn.danger:hover{border-color:#c00;color:#c00}' +
      '.hsm-upload{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#f0f5e8;border:1px dashed #82C112;border-radius:7px;font-size:13px;font-weight:700;color:#5b8e0d;cursor:pointer}' +
      '.hsm-upload:hover{background:#e4f0d8}' +
      '.hsm-upload.disabled{opacity:.5;cursor:not-allowed}' +
      '.hsm-upload input{display:none}' +
      '.hsm-note{font-size:11px;color:var(--faint,#999);margin-top:6px}' +
      '.hsm-msg{font-size:12px;color:#777;margin-top:6px;min-height:16px}' +
      '.hsm-msg.err{color:#c00}';
    document.head.appendChild(s);
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  // Scales a photo to at most ORIG_MAX on its long side and re-encodes it in
  // the browser before upload. Drawing to a canvas applies the phone's
  // rotation (which the server's image library would ignore) and drops
  // metadata like GPS location. PNGs stay PNG so transparent cutouts survive.
  function prepareUpload(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var W = img.naturalWidth, H = img.naturalHeight;
        var scale = Math.min(1, ORIG_MAX / Math.max(W, H));
        var isPng = file.type === 'image/png';
        if (isPng && scale === 1) { URL.revokeObjectURL(url); resolve(file); return; }
        var c = document.createElement('canvas');
        c.width = Math.round(W * scale); c.height = Math.round(H * scale);
        var ctx = c.getContext('2d');
        if (!isPng) { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); }
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        var name = file.name.replace(/\.[^.]+$/, '') + (isPng ? '.png' : '.jpg');
        c.toBlob(function (blob) {
          resolve(blob ? new File([blob], name, { type: blob.type }) : file);
        }, isPng ? 'image/png' : 'image/jpeg', 0.92);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  function mount(root, opts) {
    opts = opts || {};
    injectStyles();
    var email = opts.email || '';
    var state = { photos: [], headshot: null };
    var bust = Date.now();

    // Thumbnails (128px) for the grid; the original for cropping/downloading.
    function photoUrl(key) { return 'api/intake.php?action=headshot&key=' + encodeURIComponent(key); }
    function fullUrl(key) { return photoUrl(key) + '&full=1'; }
    function cropUrl() {
      return 'api/intake.php?action=headshot_crop' + (email ? '&email=' + encodeURIComponent(email) : '') + '&v=' + bust;
    }

    root.innerHTML = '';
    var current = el('div', 'hsm-current');
    var grid = el('div', 'hsm-grid');
    var upload = el('label', 'hsm-upload');
    var input = document.createElement('input');
    input.type = 'file'; input.accept = 'image/*'; input.multiple = true;
    upload.appendChild(el('span', null, '+ Upload photos'));
    upload.appendChild(input);
    var note = el('div', 'hsm-note', 'Up to ' + MAX_PHOTOS + ' photos, at least 400×400 pixels, max 10 MB each. ' +
      'Photos are kept as uploaded; only the one you choose as your headshot is cropped to a square.');
    var msg = el('div', 'hsm-msg');
    root.appendChild(current); root.appendChild(grid); root.appendChild(upload); root.appendChild(note); root.appendChild(msg);

    function say(text, isErr) { msg.textContent = text || ''; msg.classList.toggle('err', !!isErr); }
    function flash(text) { say(text); setTimeout(function () { if (msg.textContent === text) say(''); }, 2500); }

    function render() {
      var hs = state.headshot;
      current.innerHTML = '';
      if (hs) {
        var img = el('img', 'hsm-current-img'); img.src = cropUrl(); img.alt = 'Current headshot';
        current.appendChild(img);
      } else {
        current.appendChild(el('div', 'hsm-current-empty', 'No headshot chosen'));
      }
      var text = el('div');
      text.appendChild(el('div', 'hsm-current-title', 'Website & app headshot'));
      text.appendChild(el('div', 'hsm-current-text', hs
        ? 'This square crop is what shows on the website and in the app. Use "Adjust crop" or pick a different photo below to change it.'
        : (state.photos.length
          ? 'Choose one of your photos below with "Use as headshot" and frame it as a square.'
          : 'Upload a photo, then frame it as a square for the website and app.')));
      current.appendChild(text);

      grid.innerHTML = '';
      state.photos.forEach(function (p) {
        var isHs = hs && hs.source_key === p.file_key;
        var card = el('div', 'hsm-card' + (isHs ? ' is-headshot' : ''));
        var box = el('div', 'hsm-photo');
        var img = el('img'); img.src = photoUrl(p.file_key); img.alt = p.orig_name || 'Photo'; img.loading = 'lazy';
        box.appendChild(img);
        // The grid loads 128px thumbnails, so the real size comes from the API.
        if (p.width && Math.min(p.width, p.height) < HeadshotCrop.MIN_SIDE) {
          var low = el('span', 'hsm-badge low', 'Low res');
          low.title = p.width + '×' + p.height + ' pixels. A larger photo will look sharper.';
          box.appendChild(low);
        }
        if (isHs) box.appendChild(el('span', 'hsm-badge', 'Headshot'));
        card.appendChild(box);

        var actions = el('div', 'hsm-actions');
        var choose = el('button', 'hsm-btn' + (isHs ? '' : ' primary'), isHs ? 'Adjust crop' : 'Use as headshot');
        choose.type = 'button';
        choose.addEventListener('click', function () { chooseHeadshot(p.file_key); });
        actions.appendChild(choose);
        var row = el('div', 'hsm-row');
        var dl = el('a', 'hsm-btn', 'Download'); dl.href = photoUrl(p.file_key) + '&dl=1';
        var del = el('button', 'hsm-btn danger', 'Delete'); del.type = 'button';
        del.addEventListener('click', function () { deletePhoto(p.file_key, isHs); });
        row.appendChild(dl); row.appendChild(del);
        actions.appendChild(row);
        card.appendChild(actions);
        grid.appendChild(card);
      });

      var full = state.photos.length >= MAX_PHOTOS;
      upload.classList.toggle('disabled', full);
      input.disabled = full;
    }

    function load() {
      return fetch('api/intake.php' + (email ? '?email=' + encodeURIComponent(email) : ''), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          state.photos = d.headshots || [];
          state.headshot = d.headshot || null;
          bust = Date.now();
          render();
        });
    }

    function post(action, fd) {
      if (email) fd.append('email', email);
      return fetch('api/intake.php?action=' + action, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (!res.ok) throw HeadshotCrop.userError(res.error || 'Something went wrong.');
          return res;
        });
    }

    // keepMsg: leave an upload summary (e.g. a skipped photo) on screen.
    function chooseHeadshot(key, keepMsg) {
      var hs = state.headshot;
      var rect = hs && hs.source_key === key ? hs.crop_rect : null;
      if (!keepMsg) say('');
      return HeadshotCrop.crop(fullUrl(key), rect).then(function (result) {
        say('Saving headshot…');
        var fd = new FormData();
        fd.append('key', key);
        fd.append('crop', result.blob, 'headshot.jpg');
        fd.append('rect', JSON.stringify(result.rect));
        return post('set_headshot', fd);
      }).then(function () {
        return load().then(function () { flash('Headshot saved. The website picks it up overnight.'); });
      }).catch(function (err) { say(HeadshotCrop.errorText(err), true); });
    }

    function deletePhoto(key, isHs) {
      if (!confirm(isHs ? 'Delete this photo? It is your current headshot, so you will need to choose another.' : 'Delete this photo?')) return;
      say('Deleting…');
      fetch('api/intake.php?action=delete_file', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: key }),
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) throw HeadshotCrop.userError(res.error || 'Delete failed.');
        return load().then(function () { flash('Deleted.'); });
      }).catch(function (err) { say(HeadshotCrop.errorText(err), true); });
    }

    // Uploads run one at a time; a too-small or oversized file is skipped
    // with a message without stopping the rest.
    input.addEventListener('change', function () {
      var files = Array.from(input.files || []);
      input.value = '';
      var room = MAX_PHOTOS - state.photos.length;
      if (!files.length) return;
      var skipped = [];
      if (files.length > room) {
        skipped.push((files.length - room) + ' photo(s) over the ' + MAX_PHOTOS + '-photo limit');
        files = files.slice(0, room);
      }
      var hadHeadshot = !!state.headshot;
      var firstNewKey = null;
      var chain = Promise.resolve();
      files.forEach(function (file, i) {
        chain = chain.then(function () {
          say('Uploading ' + (i + 1) + ' of ' + files.length + '…');
          return HeadshotCrop.checkFile(file)
            .then(function () { return prepareUpload(file); })
            .then(function (prepared) {
              var fd = new FormData();
              fd.append('headshot', prepared, prepared.name || file.name);
              return post('upload', fd);
            })
            .then(function (res) { if (!firstNewKey) firstNewKey = res.file_key; })
            .catch(function (err) { skipped.push(HeadshotCrop.errorText(err) || file.name); });
        });
      });
      chain.then(load).then(function () {
        if (skipped.length) say('Some photos were not uploaded: ' + skipped.join(' '), true);
        else flash(files.length === 1 ? 'Photo uploaded.' : files.length + ' photos uploaded.');
        // First photo for someone with no headshot yet: go straight to framing it.
        if (!hadHeadshot && firstNewKey) chooseHeadshot(firstNewKey, skipped.length > 0);
      }).catch(function () { say('Network error.', true); });
    });

    load().catch(function () { say('Could not load photos.', true); });
    return { reload: load };
  }

  window.HeadshotManager = { mount: mount };
})();
