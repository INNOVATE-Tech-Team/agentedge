// Photo manager shared by My Profile, the intake form and the admin agent
// profile. Two separate areas:
//
//   1. Headshot: the square crop shown on the website and in the app.
//      "Upload headshot" uploads a photo and goes straight to framing it;
//      "Adjust crop" reopens the saved framing.
//   2. Marketing photos: full, uncropped photos (more torso, wider shots)
//      that staff download for marketing graphics. Never cropped; any one
//      of them can also be made the headshot.
//
// Every upload is stored as an original in agent_intake_files (the
// headshot's source photo included, so its uncropped version is always
// available); the headshot crop is a separate file (lib/headshot_select.php).
//
//   HeadshotManager.mount(containerEl, { email: 'agent@x.com' })
//
// `email` is only needed when staff manage another agent's photos. Needs
// assets/headshot-crop.js loaded first. Talks to api/intake.php
// (upload / set_headshot / delete_file / headshot / headshot_crop).
(function () {
  var MAX_PHOTOS = 10;
  var ORIG_MAX = 3000;

  function injectStyles() {
    if (document.getElementById('hsm-styles')) return;
    var s = document.createElement('style');
    s.id = 'hsm-styles';
    s.textContent =
      '.hsm-section{border:1px solid var(--border,#e3e3e3);border-radius:10px;padding:16px;margin-bottom:16px;background:#fff}' +
      '.hsm-h{font-size:14px;font-weight:800;margin:0 0 2px}' +
      '.hsm-sub{font-size:12px;color:#777;line-height:1.45;margin:0 0 14px}' +
      '.hsm-head{display:flex;gap:18px;align-items:center;flex-wrap:wrap}' +
      '.hsm-head-img{width:128px;height:128px;border-radius:10px;object-fit:cover;background:#eee;flex-shrink:0}' +
      '.hsm-head-empty{width:128px;height:128px;border-radius:10px;border:1px dashed #c9c9c9;display:flex;align-items:center;justify-content:center;font-size:11px;color:#999;text-align:center;flex-shrink:0;padding:8px}' +
      '.hsm-head-side{display:flex;flex-direction:column;gap:8px;align-items:flex-start}' +
      '.hsm-grid{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px}' +
      '.hsm-grid:empty{display:none}' +
      '.hsm-card{width:140px;border:1px solid var(--border,#e3e3e3);border-radius:8px;overflow:hidden;background:#fff}' +
      '.hsm-photo{position:relative;height:128px;background:#f2f2f2;display:flex;align-items:center;justify-content:center}' +
      '.hsm-photo img{max-width:100%;max-height:100%;object-fit:contain}' +
      '.hsm-badge{position:absolute;left:6px;top:6px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;border-radius:999px;padding:2px 8px;background:#82C112;color:#fff}' +
      '.hsm-badge.low{left:auto;right:6px;background:#b26a00}' +
      '.hsm-actions{display:flex;flex-direction:column;gap:4px;padding:8px}' +
      '.hsm-btn{font-size:12px;font-weight:700;border-radius:6px;padding:6px 10px;border:1px solid #ccc;background:#fff;color:#444;cursor:pointer;text-align:center;text-decoration:none;display:block}' +
      '.hsm-btn:hover{border-color:#82C112;color:#5b8e0d}' +
      '.hsm-row{display:flex;gap:4px}.hsm-row .hsm-btn{flex:1;min-width:0;padding:6px 2px}' +
      '.hsm-btn.danger:hover{border-color:#c00;color:#c00}' +
      '.hsm-upload{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#f0f5e8;border:1px dashed #82C112;border-radius:7px;font-size:13px;font-weight:700;color:#5b8e0d;cursor:pointer}' +
      '.hsm-upload:hover{background:#e4f0d8}' +
      '.hsm-upload.primary{background:#82C112;border:1px solid #82C112;color:#fff}' +
      '.hsm-upload.primary:hover{background:#6fa60f}' +
      '.hsm-upload.disabled{opacity:.5;cursor:not-allowed}' +
      '.hsm-upload input{display:none}' +
      '.hsm-note{font-size:11px;color:var(--faint,#999);margin-top:6px}' +
      '.hsm-empty{font-size:12px;color:#999;font-style:italic;margin:0 0 12px}' +
      '.hsm-msg{font-size:12px;color:#777;margin-top:8px;min-height:16px}' +
      '.hsm-msg.err{color:#c00}';
    document.head.appendChild(s);
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function uploadButton(label, multiple, primary) {
    var lbl = el('label', 'hsm-upload' + (primary ? ' primary' : ''));
    var input = document.createElement('input');
    input.type = 'file'; input.accept = 'image/*'; input.multiple = !!multiple;
    lbl.appendChild(el('span', null, label));
    lbl.appendChild(input);
    return { label: lbl, input: input };
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

    // ── Layout ──
    root.innerHTML = '';
    var headSec = el('div', 'hsm-section');
    headSec.appendChild(el('div', 'hsm-h', 'Headshot'));
    headSec.appendChild(el('div', 'hsm-sub', 'The square photo shown on the website and in the app. Upload a photo and frame your head and shoulders in the square.'));
    var headBody = el('div', 'hsm-head');
    headSec.appendChild(headBody);
    var headUpload = uploadButton('Upload headshot', false, true);
    var headMsg = el('div', 'hsm-msg');
    headSec.appendChild(headMsg);

    var photoSec = el('div', 'hsm-section');
    photoSec.appendChild(el('div', 'hsm-h', 'Marketing photos'));
    photoSec.appendChild(el('div', 'hsm-sub', 'Full, uncropped photos for marketing graphics (more torso, wider shots). These are kept exactly as uploaded and the marketing team can download them.'));
    var grid = el('div', 'hsm-grid');
    var empty = el('div', 'hsm-empty', 'No marketing photos yet.');
    photoSec.appendChild(empty);
    photoSec.appendChild(grid);
    var photoUpload = uploadButton('+ Upload photos', true, false);
    photoSec.appendChild(photoUpload.label);
    var note = el('div', 'hsm-note');
    photoSec.appendChild(note);
    var photoMsg = el('div', 'hsm-msg');
    photoSec.appendChild(photoMsg);

    root.appendChild(headSec);
    root.appendChild(photoSec);

    function sayTo(box, text, isErr) { box.textContent = text || ''; box.classList.toggle('err', !!isErr); }
    function flashTo(box, text) { sayTo(box, text); setTimeout(function () { if (box.textContent === text) sayTo(box, ''); }, 3000); }

    // ── Render ──
    function render() {
      var hs = state.headshot;
      var full = state.photos.length >= MAX_PHOTOS;

      headBody.innerHTML = '';
      if (hs) {
        var img = el('img', 'hsm-head-img'); img.src = cropUrl(); img.alt = 'Current headshot';
        headBody.appendChild(img);
      } else {
        headBody.appendChild(el('div', 'hsm-head-empty', 'No headshot yet'));
      }
      var side = el('div', 'hsm-head-side');
      side.appendChild(headUpload.label);
      if (hs) {
        var adjust = el('button', 'hsm-btn', 'Adjust crop');
        adjust.type = 'button';
        adjust.addEventListener('click', function () { chooseHeadshot(hs.source_key); });
        side.appendChild(adjust);
        var dlSrc = el('a', 'hsm-btn', 'Download original');
        dlSrc.href = photoUrl(hs.source_key) + '&dl=1';
        side.appendChild(dlSrc);
      }
      headBody.appendChild(side);
      headUpload.label.classList.toggle('disabled', full);
      headUpload.input.disabled = full;

      grid.innerHTML = '';
      state.photos.forEach(function (p) {
        var isHs = hs && hs.source_key === p.file_key;
        var card = el('div', 'hsm-card');
        var box = el('div', 'hsm-photo');
        var img = el('img'); img.src = photoUrl(p.file_key); img.alt = p.orig_name || 'Photo'; img.loading = 'lazy';
        box.appendChild(img);
        if (isHs) {
          var used = el('span', 'hsm-badge', 'Headshot');
          used.title = 'Your headshot is cropped from this photo';
          box.appendChild(used);
        }
        // The grid loads 128px thumbnails, so the real size comes from the API.
        if (p.width && Math.min(p.width, p.height) < HeadshotCrop.MIN_SIDE) {
          var low = el('span', 'hsm-badge low', 'Low res');
          low.title = p.width + '×' + p.height + ' pixels. A larger photo will look sharper.';
          box.appendChild(low);
        }
        card.appendChild(box);

        var actions = el('div', 'hsm-actions');
        var row = el('div', 'hsm-row');
        var dl = el('a', 'hsm-btn', 'Download'); dl.href = photoUrl(p.file_key) + '&dl=1';
        var del = el('button', 'hsm-btn danger', 'Delete'); del.type = 'button';
        del.addEventListener('click', function () { deletePhoto(p.file_key, isHs); });
        row.appendChild(dl); row.appendChild(del);
        actions.appendChild(row);
        if (!isHs) {
          var use = el('button', 'hsm-btn', 'Use as headshot'); use.type = 'button';
          use.addEventListener('click', function () { chooseHeadshot(p.file_key); });
          actions.appendChild(use);
        }
        card.appendChild(actions);
        grid.appendChild(card);
      });
      empty.style.display = state.photos.length ? 'none' : '';

      photoUpload.label.classList.toggle('disabled', full);
      photoUpload.input.disabled = full;
      note.textContent = full
        ? 'You have reached the ' + MAX_PHOTOS + '-photo limit. Delete one to upload another.'
        : 'Up to ' + MAX_PHOTOS + ' photos in total (headshot included), at least 400×400 pixels, max 10 MB each.';
    }

    // ── Data ──
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

    function uploadOne(file) {
      return HeadshotCrop.checkFile(file)
        .then(function () { return prepareUpload(file); })
        .then(function (prepared) {
          var fd = new FormData();
          fd.append('headshot', prepared, prepared.name || file.name);
          return post('upload', fd);
        });
    }

    // Frame one stored photo as the headshot (reopening the saved framing
    // when it's already the headshot's source).
    function chooseHeadshot(key) {
      var hs = state.headshot;
      var rect = hs && hs.source_key === key ? hs.crop_rect : null;
      sayTo(headMsg, '');
      return HeadshotCrop.crop(fullUrl(key), rect).then(function (result) {
        sayTo(headMsg, 'Saving headshot…');
        var fd = new FormData();
        fd.append('key', key);
        fd.append('crop', result.blob, 'headshot.jpg');
        fd.append('rect', JSON.stringify(result.rect));
        return post('set_headshot', fd);
      }).then(function () {
        return load().then(function () { flashTo(headMsg, 'Headshot saved. The website picks it up overnight.'); });
      }).catch(function (err) { sayTo(headMsg, HeadshotCrop.errorText(err), true); });
    }

    function deletePhoto(key, isHs) {
      if (!confirm(isHs ? 'Delete this photo? Your headshot is cropped from it, so the headshot will be removed too.' : 'Delete this photo?')) return;
      sayTo(photoMsg, 'Deleting…');
      fetch('api/intake.php?action=delete_file', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: key }),
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) throw HeadshotCrop.userError(res.error || 'Delete failed.');
        return load().then(function () { flashTo(photoMsg, 'Deleted.'); });
      }).catch(function (err) { sayTo(photoMsg, HeadshotCrop.errorText(err), true); });
    }

    // Headshot upload: store the original, then go straight to framing it.
    headUpload.input.addEventListener('change', function () {
      var file = (headUpload.input.files || [])[0];
      headUpload.input.value = '';
      if (!file) return;
      sayTo(headMsg, 'Uploading…');
      uploadOne(file)
        .then(function (res) { return load().then(function () { sayTo(headMsg, ''); return chooseHeadshot(res.file_key); }); })
        .catch(function (err) { sayTo(headMsg, HeadshotCrop.errorText(err), true); });
    });

    // Marketing photos: uploaded one at a time, never cropped. A too-small
    // or oversized file is skipped with a message without stopping the rest.
    photoUpload.input.addEventListener('change', function () {
      var files = Array.from(photoUpload.input.files || []);
      photoUpload.input.value = '';
      if (!files.length) return;
      var room = MAX_PHOTOS - state.photos.length;
      var skipped = [];
      if (files.length > room) {
        skipped.push((files.length - room) + ' photo(s) over the ' + MAX_PHOTOS + '-photo limit.');
        files = files.slice(0, room);
      }
      var done = 0;
      var chain = Promise.resolve();
      files.forEach(function (file, i) {
        chain = chain.then(function () {
          sayTo(photoMsg, 'Uploading ' + (i + 1) + ' of ' + files.length + '…');
          return uploadOne(file)
            .then(function () { done++; })
            .catch(function (err) { skipped.push(HeadshotCrop.errorText(err) || file.name); });
        });
      });
      chain.then(load).then(function () {
        if (skipped.length) sayTo(photoMsg, (done ? done + ' uploaded. ' : '') + 'Not uploaded: ' + skipped.join(' '), true);
        else flashTo(photoMsg, done === 1 ? 'Photo uploaded.' : done + ' photos uploaded.');
      }).catch(function () { sayTo(photoMsg, 'Network error.', true); });
    });

    load().catch(function () { sayTo(photoMsg, 'Could not load photos.', true); });
    return { reload: load };
  }

  window.HeadshotManager = { mount: mount };
})();
