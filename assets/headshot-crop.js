// Square headshot cropper, used by the photo manager (headshot-manager.js).
//
// HeadshotCrop.crop(src, initialRect) opens a modal where the user drags
// and zooms a photo inside a square frame, and resolves with
// { blob, rect }: a square JPEG of the framed area, plus the frame in
// source pixels ({ x, y, size }) so the same framing can be reopened later
// for adjusting. Rejects with Error('cancelled') if dismissed.
//
// HeadshotCrop.checkFile(file) resolves if an upload is big enough, or
// rejects with a user-facing message. Photos are uploaded uncropped (staff
// use the originals for marketing); only the chosen headshot is cropped.
//
// Never upscales: output is the framed area's real pixel size, capped at
// OUTPUT_MAX, and zoom is limited so the frame never drops below MIN_SIDE
// source pixels (api/intake.php enforces the same minimum server-side).
(function () {
  var MIN_SIDE = 400;
  var OUTPUT_MAX = 800;

  function userError(message) {
    var e = new Error(message);
    e.hscUser = true;
    return e;
  }

  function injectStyles() {
    if (document.getElementById('hsc-styles')) return;
    var s = document.createElement('style');
    s.id = 'hsc-styles';
    s.textContent =
      '.hsc-back{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:16px}' +
      '.hsc-box{background:#fff;border-radius:12px;padding:20px;width:100%;max-width:400px;box-shadow:0 10px 40px rgba(0,0,0,.3);font-family:inherit}' +
      '.hsc-title{font-size:15px;font-weight:800;margin:0 0 4px}' +
      '.hsc-sub{font-size:12px;color:#777;margin:0 0 14px}' +
      '.hsc-view{position:relative;width:100%;aspect-ratio:1/1;overflow:hidden;border-radius:8px;background:#eee;touch-action:none;cursor:grab;user-select:none}' +
      '.hsc-view.dragging{cursor:grabbing}' +
      '.hsc-view img{position:absolute;left:0;top:0;transform-origin:0 0;max-width:none;pointer-events:none}' +
      '.hsc-grid{position:absolute;inset:0;pointer-events:none;background:' +
        'linear-gradient(to right,transparent 33.2%,rgba(255,255,255,.5) 33.3%,transparent 33.5%,transparent 66.5%,rgba(255,255,255,.5) 66.6%,transparent 66.8%),' +
        'linear-gradient(to bottom,transparent 33.2%,rgba(255,255,255,.5) 33.3%,transparent 33.5%,transparent 66.5%,rgba(255,255,255,.5) 66.6%,transparent 66.8%)}' +
      '.hsc-zoom{display:flex;align-items:center;gap:10px;margin:14px 0 4px;font-size:12px;color:#777}' +
      '.hsc-zoom input{flex:1;accent-color:#82C112}' +
      '.hsc-warn{font-size:12px;color:#b26a00;min-height:16px;margin:6px 0 0}' +
      '.hsc-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}' +
      '.hsc-btn{border-radius:6px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;border:1px solid #ccc;background:#fff;color:#555}' +
      '.hsc-btn.primary{background:#82C112;border-color:#82C112;color:#fff}';
    document.head.appendChild(s);
  }

  function loadImage(src) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { reject(userError('Could not read that image. Please choose a JPEG or PNG photo.')); };
      img.src = src;
    });
  }

  function tooSmall(W, H) {
    return userError('This photo is only ' + W + '×' + H + ' pixels. Please choose a photo at least ' +
      MIN_SIDE + '×' + MIN_SIDE + ' (any recent phone photo works).');
  }

  function checkFile(file) {
    if (!file || !/^image\//.test(file.type)) return Promise.reject(userError('Please choose an image file.'));
    if (file.size > 10 * 1024 * 1024) return Promise.reject(userError('"' + file.name + '" is over the 10 MB limit.'));
    var url = URL.createObjectURL(file);
    return loadImage(url).then(function (img) {
      URL.revokeObjectURL(url);
      if (Math.min(img.naturalWidth, img.naturalHeight) < MIN_SIDE) throw tooSmall(img.naturalWidth, img.naturalHeight);
    }, function (e) { URL.revokeObjectURL(url); throw e; });
  }

  function crop(src, initialRect) {
    injectStyles();
    return loadImage(src).then(function (img) {
      var W = img.naturalWidth, H = img.naturalHeight;
      if (Math.min(W, H) < MIN_SIDE) throw tooSmall(W, H);
      return openModal(img, W, H, initialRect || null);
    });
  }

  function openModal(img, W, H, initialRect) {
    return new Promise(function (resolve, reject) {
      var back = document.createElement('div');
      back.className = 'hsc-back';
      back.innerHTML =
        '<div class="hsc-box" role="dialog" aria-modal="true" aria-label="Crop your headshot">' +
          '<div class="hsc-title">Frame your headshot</div>' +
          '<div class="hsc-sub">Drag to position and zoom so your head and shoulders fill the square. Your original photo is kept as-is.</div>' +
          '<div class="hsc-view"><div class="hsc-grid"></div></div>' +
          '<div class="hsc-zoom"><span>Zoom</span><input type="range" min="0" max="1000" value="0"></div>' +
          '<div class="hsc-warn"></div>' +
          '<div class="hsc-actions"><button type="button" class="hsc-btn" data-act="cancel">Cancel</button>' +
          '<button type="button" class="hsc-btn primary" data-act="save">Use as headshot</button></div>' +
        '</div>';
      var view = back.querySelector('.hsc-view');
      var slider = back.querySelector('input[type=range]');
      var warn = back.querySelector('.hsc-warn');
      view.insertBefore(img, view.firstChild);
      document.body.appendChild(back);
      var prevOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';

      // State in "view" CSS pixels: s = CSS px per source px, (x, y) = the
      // image's top-left corner relative to the square view.
      var V = view.clientWidth;
      var minS = V / Math.min(W, H);          // image just covers the square
      var maxS = V / MIN_SIDE;                // frame never smaller than MIN_SIDE source px
      var s, x, y;
      if (initialRect && initialRect.size > 0) {
        // Reopen with the framing saved last time.
        s = Math.min(maxS, Math.max(minS, V / initialRect.size));
        x = -initialRect.x * s;
        y = -initialRect.y * s;
      } else {
        // Default framing: centered horizontally, and toward the top on
        // portrait photos, where a headshot's face usually sits.
        s = minS;
        x = (V - W * s) / 2;
        y = H > W ? -(H * s - V) * 0.2 : (V - H * s) / 2;
      }

      if (maxS <= minS * 1.001) slider.disabled = true;
      if (Math.min(W, H) < 600) warn.textContent = 'This photo is on the small side. A higher-resolution photo will look sharper.';

      function sliderFor(scale) {
        return String(maxS > minS ? Math.round(1000 * Math.log(scale / minS) / Math.log(maxS / minS)) : 0);
      }
      function clamp() {
        x = Math.min(0, Math.max(V - W * s, x));
        y = Math.min(0, Math.max(V - H * s, y));
      }
      function render() {
        clamp();
        img.style.transform = 'translate(' + x + 'px,' + y + 'px) scale(' + s + ')';
      }
      // Zoom around a point in view coordinates so what's under the
      // cursor/finger (or the center, for the slider) stays put.
      function zoomTo(nextS, cx, cy) {
        nextS = Math.min(maxS, Math.max(minS, nextS));
        x = cx - (cx - x) * (nextS / s);
        y = cy - (cy - y) * (nextS / s);
        s = nextS;
        slider.value = sliderFor(s);
        render();
      }
      slider.value = sliderFor(s);
      slider.addEventListener('input', function () {
        var t = Number(slider.value) / 1000;
        zoomTo(minS * Math.pow(maxS / minS, t), V / 2, V / 2);
      });
      view.addEventListener('wheel', function (e) {
        e.preventDefault();
        var r = view.getBoundingClientRect();
        zoomTo(s * Math.exp(-e.deltaY * 0.0015), e.clientX - r.left, e.clientY - r.top);
      }, { passive: false });

      // Pointer drag, plus two-finger pinch on touch screens.
      var pointers = new Map();
      var pinchDist = 0;
      view.addEventListener('pointerdown', function (e) {
        view.setPointerCapture(e.pointerId);
        pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        view.classList.add('dragging');
        if (pointers.size === 2) pinchDist = dist();
      });
      view.addEventListener('pointermove', function (e) {
        var p = pointers.get(e.pointerId);
        if (!p) return;
        if (pointers.size === 1) {
          x += e.clientX - p.x;
          y += e.clientY - p.y;
          p.x = e.clientX; p.y = e.clientY;
          render();
        } else if (pointers.size === 2) {
          p.x = e.clientX; p.y = e.clientY;
          var d = dist();
          if (pinchDist > 0) {
            var r = view.getBoundingClientRect();
            var pts = Array.from(pointers.values());
            zoomTo(s * d / pinchDist, (pts[0].x + pts[1].x) / 2 - r.left, (pts[0].y + pts[1].y) / 2 - r.top);
          }
          pinchDist = d;
        }
      });
      function endPointer(e) {
        pointers.delete(e.pointerId);
        pinchDist = pointers.size === 2 ? dist() : 0;
        if (!pointers.size) view.classList.remove('dragging');
      }
      view.addEventListener('pointerup', endPointer);
      view.addEventListener('pointercancel', endPointer);
      function dist() {
        var pts = Array.from(pointers.values());
        return Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
      }

      function close() {
        document.removeEventListener('keydown', onKey);
        document.body.style.overflow = prevOverflow;
        back.remove();
      }
      function cancel() { close(); reject(new Error('cancelled')); }
      function onKey(e) { if (e.key === 'Escape') cancel(); }
      document.addEventListener('keydown', onKey);
      back.addEventListener('click', function (e) { if (e.target === back) cancel(); });
      back.querySelector('[data-act=cancel]').addEventListener('click', cancel);
      back.querySelector('[data-act=save]').addEventListener('click', function () {
        var side = V / s;                       // frame size in source pixels
        var rect = { x: Math.round(-x / s), y: Math.round(-y / s), size: Math.round(side) };
        var out = Math.min(OUTPUT_MAX, rect.size);
        var canvas = document.createElement('canvas');
        canvas.width = canvas.height = out;
        var ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';                 // flatten PNG transparency
        ctx.fillRect(0, 0, out, out);
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, -x / s, -y / s, side, side, 0, 0, out, out);
        canvas.toBlob(function (blob) {
          close();
          if (blob) resolve({ blob: blob, rect: rect });
          else reject(userError('Could not process the photo. Please try another one.'));
        }, 'image/jpeg', 0.9);
      });

      render();
    });
  }

  // Message for a failed photo action: blank on cancel, the cropper's own
  // explanation for its errors, and a generic one for anything else
  // (fetch failures, a non-JSON response).
  function errorText(err) {
    if (err && err.message === 'cancelled') return '';
    return err && err.hscUser ? err.message : 'Network error.';
  }

  window.HeadshotCrop = { crop: crop, checkFile: checkFile, errorText: errorText, userError: userError, MIN_SIDE: MIN_SIDE };
})();
