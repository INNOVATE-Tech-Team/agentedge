<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nav.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/local_db.php';
require_once __DIR__ . '/lib/s3.php';
$agent = require_login();
$perms = current_perms();
$admin = !empty($perms['isAdmin']);
$superAdmin = !empty($perms['isSuperAdmin']);
$email = $agent['email'] ?? '';

// Departments this user can see. super_admin/admin sees all; others see only assigned.
$db = local_db();
$allDepts = $db->query("SELECT * FROM vault_depts ORDER BY sort_ord, name")->fetchAll(PDO::FETCH_ASSOC);

if ($admin) {
    $userDeptSlugs = array_column($allDepts, 'slug');
} else {
    $rows = $db->prepare("SELECT dept_slug FROM vault_user_depts WHERE email=?");
    $rows->execute([$email]);
    $userDeptSlugs = $rows->fetchAll(PDO::FETCH_COLUMN);
}

// Root folders visible to this user.
function vault_visible_root_folders(bool $admin, array $userDeptSlugs): array {
    $db = local_db();
    $all = $db->query(
        "SELECT * FROM vault_folders WHERE parent_id IS NULL OR parent_id='' ORDER BY sort_ord, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_filter($all, function($f) use ($admin, $userDeptSlugs) {
        if ($f['visibility'] === 'public') return true;
        if ($f['visibility'] === 'dept')   return $admin || in_array($f['dept_slug'], $userDeptSlugs);
        return $admin; // 'admin'
    }));
}

$rootFolders = vault_visible_root_folders($admin, $userDeptSlugs);

// Group root folders: public ones first, then by dept
$grouped = ['__public' => []];
foreach ($allDepts as $d) $grouped[$d['slug']] = [];
foreach ($rootFolders as $f) {
    $key = ($f['visibility'] === 'dept' && $f['dept_slug']) ? $f['dept_slug'] : '__public';
    if (!isset($grouped[$key])) $grouped[$key] = [];
    $grouped[$key][] = $f;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>The Vault — AgentEdge</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/app.css">
  <style>
    .vault-layout{display:flex;gap:0;height:calc(100vh - 64px);overflow:hidden}
    .vault-tree{width:280px;min-width:220px;border-right:1px solid #e5e7eb;overflow-y:auto;padding:12px 0;flex-shrink:0;background:#fafafa}
    .vault-main{flex:1;overflow-y:auto;padding:24px}
    .vault-section{margin-bottom:4px}
    .vault-section-hdr{display:flex;align-items:center;gap:6px;padding:6px 14px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#888;cursor:pointer;user-select:none;background:none;border:none;width:100%;text-align:left}
    .vault-section-hdr:hover{color:#333}
    .vault-section-hdr .vs-arrow{margin-left:auto;font-size:9px;transition:transform .2s}
    .vault-section-hdr.collapsed .vs-arrow{transform:rotate(-90deg)}
    .vault-section-body{padding-bottom:4px}
    .vault-section-body.hidden{display:none}
    .vault-folder-item{display:flex;align-items:center;gap:6px;padding:5px 14px 5px 22px;font-size:13px;color:#333;cursor:pointer;border-radius:0;transition:background .1s}
    .vault-folder-item:hover{background:#f0f7e8}
    .vault-folder-item.active{background:#e8f5d0;color:#3a6b00;font-weight:600}
    .vault-folder-item .vf-icon{font-size:14px;opacity:.8}
    .vault-folder-item .vf-child{padding-left:14px}
    .vault-breadcrumb{display:flex;align-items:center;gap:6px;margin-bottom:18px;font-size:13px;flex-wrap:wrap}
    .vault-breadcrumb a{color:#5b8e0d;text-decoration:none}.vault-breadcrumb a:hover{text-decoration:underline}
    .vault-breadcrumb span{color:#999}
    .vault-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:18px;flex-wrap:wrap}
    .vault-toolbar h2{margin:0;font-size:18px;font-weight:700;flex:1}
    .vault-empty{text-align:center;padding:60px 20px;color:#aaa;font-size:14px}
    .vault-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:24px}
    .vault-folder-card{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 12px;cursor:pointer;transition:box-shadow .15s,border-color .15s;display:flex;flex-direction:column;gap:6px}
    .vault-folder-card:hover{box-shadow:0 2px 8px rgba(0,0,0,.08);border-color:#b6dca8}
    .vault-folder-card .vfc-icon{font-size:28px}
    .vault-folder-card .vfc-name{font-size:13px;font-weight:600;color:#222;word-break:break-word}
    .vault-files-table{width:100%;border-collapse:collapse}
    .vault-files-table th{text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#888;padding:6px 10px;border-bottom:2px solid #e5e7eb}
    .vault-files-table td{padding:9px 10px;font-size:13px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
    .vault-files-table tr:hover td{background:#f9fbf6}
    .vf-dl{color:#5b8e0d;text-decoration:none;font-weight:600}.vf-dl:hover{text-decoration:underline}
    .vf-mime{font-size:11px;color:#aaa}
    .vf-size{color:#777;font-size:12px;white-space:nowrap}
    .vf-date{color:#aaa;font-size:11px;white-space:nowrap}
    .btn-sm{padding:5px 10px;font-size:12px;border-radius:5px;border:1px solid #ccc;background:#fff;cursor:pointer}
    .btn-green{background:#82C112;border-color:#5b8e0d;color:#fff;font-weight:600}
    .btn-green:hover{background:#5b8e0d}
    .btn-danger{border-color:#e74c3c;color:#e74c3c}
    .btn-danger:hover{background:#e74c3c;color:#fff}
    .vault-upload-zone{border:2px dashed #c8e6a8;border-radius:8px;padding:24px;text-align:center;color:#888;font-size:13px;margin-bottom:16px;transition:border-color .2s,background .2s;cursor:pointer}
    .vault-upload-zone.drag{border-color:#82C112;background:#f6ffe8}
    .vault-upload-zone input[type=file]{display:none}
    .vault-progress{display:none;margin-top:8px}
    .vault-progress progress{width:100%;height:6px}
    #vault-toast{position:fixed;bottom:20px;right:20px;background:#222;color:#fff;padding:10px 16px;border-radius:6px;font-size:13px;display:none;z-index:9999}
    @media(max-width:640px){.vault-layout{flex-direction:column;height:auto}.vault-tree{width:100%;height:auto;border-right:none;border-bottom:1px solid #e5e7eb}.vault-main{padding:16px}}
  </style>
</head>
<body>
<div class="layout">
  <?php render_sidebar('vault', $agent); ?>
  <div class="content">
    <header class="content-top">
      <div class="content-title">The Vault</div>
      <div class="content-hello">Documents &amp; Resources</div>
    </header>
    <div class="vault-layout">

      <!-- Left tree -->
      <div class="vault-tree" id="vault-tree">
        <?php
        $deptNames = ['__public' => 'Public'];
        foreach ($allDepts as $d) $deptNames[$d['slug']] = $d['name'];

        foreach ($grouped as $groupKey => $folders) {
            if (empty($folders) && $groupKey !== '__public') continue;
            $label = htmlspecialchars($deptNames[$groupKey] ?? ucfirst($groupKey));
            echo '<div class="vault-section">';
            echo '<button class="vault-section-hdr" onclick="toggleVaultSection(this)">'
               . $label . ' <span class="vs-arrow">&#9660;</span></button>';
            echo '<div class="vault-section-body">';
            if (empty($folders)) {
                echo '<div style="padding:4px 14px 4px 22px;font-size:12px;color:#bbb;font-style:italic">No folders</div>';
            } else {
                foreach ($folders as $f) {
                    $fid  = htmlspecialchars($f['id']);
                    $name = htmlspecialchars($f['name']);
                    echo '<div class="vault-folder-item" data-id="' . $fid . '" onclick="loadFolder(this,\'' . $fid . '\',\'' . addslashes($name) . '\',[])">
                        <span class="vf-icon">📁</span> ' . $name . '</div>';
                }
            }
            echo '</div></div>';
        }
        ?>
      </div>

      <!-- Right panel -->
      <div class="vault-main" id="vault-main">
        <div class="vault-empty">
          <div style="font-size:40px;margin-bottom:12px">🗄️</div>
          <div style="font-size:15px;font-weight:600;color:#555;margin-bottom:6px">The Vault</div>
          <div>Select a folder from the left to browse files.</div>
        </div>
      </div>

    </div><!-- /vault-layout -->
  </div>
</div>
<div id="vault-toast"></div>

<script>
const IS_ADMIN = <?= $admin ? 'true' : 'false' ?>;
let currentFolderId = null;
let breadcrumb = [];

function toggleVaultSection(btn) {
    btn.classList.toggle('collapsed');
    btn.nextElementSibling.classList.toggle('hidden');
}

function loadFolder(el, folderId, name, crumb) {
    document.querySelectorAll('.vault-folder-item').forEach(x => x.classList.remove('active'));
    if (el) el.classList.add('active');
    currentFolderId = folderId;
    breadcrumb = crumb.concat([{id: folderId, name}]);
    document.getElementById('vault-main').innerHTML = '<div style="padding:40px;color:#aaa;text-align:center">Loading…</div>';
    fetch('api/vault_browse.php?folder_id=' + encodeURIComponent(folderId))
        .then(r => r.json())
        .then(data => renderFolder(data, name))
        .catch(() => vaultToast('Error loading folder'));
}

function renderFolder(data, name) {
    const admin = IS_ADMIN;
    let html = '';

    // Breadcrumb
    html += '<div class="vault-breadcrumb">';
    html += '<a href="#" onclick="resetVault();return false">The Vault</a>';
    for (let i = 0; i < breadcrumb.length; i++) {
        const b = breadcrumb[i];
        html += ' <span>›</span> ';
        if (i < breadcrumb.length - 1) {
            html += '<a href="#" onclick="loadFolder(null,\'' + b.id + '\',\'' + b.name.replace(/'/g,"\\'")+  '\','+JSON.stringify(breadcrumb.slice(0,i))+');return false">'
                 + escH(b.name) + '</a>';
        } else {
            html += '<strong>' + escH(b.name) + '</strong>';
        }
    }
    html += '</div>';

    // Toolbar
    html += '<div class="vault-toolbar">';
    html += '<h2>' + escH(name) + '</h2>';
    if (admin) {
        html += '<button class="btn-sm" onclick="showCreateFolder()">+ New Folder</button>';
        html += '<button class="btn-sm btn-green" onclick="showUpload()">⬆ Upload File</button>';
    }
    html += '</div>';

    // Upload zone (hidden by default)
    if (admin) {
        html += '<div id="upload-zone" class="vault-upload-zone" style="display:none" onclick="document.getElementById(\'upload-input\').click()" '
             + 'ondragover="event.preventDefault();this.classList.add(\'drag\')" '
             + 'ondragleave="this.classList.remove(\'drag\')" '
             + 'ondrop="handleDrop(event)">'
             + '📎 Click or drag files here to upload'
             + '<input type="file" id="upload-input" multiple onchange="handleFiles(this.files)">'
             + '<div class="vault-progress" id="upload-progress"><progress id="upload-bar" max="100" value="0"></progress><div id="upload-label" style="font-size:11px;margin-top:4px;color:#777"></div></div>'
             + '</div>';
    }

    // Sub-folders
    if (data.folders && data.folders.length) {
        html += '<div class="vault-grid">';
        for (const f of data.folders) {
            const crumbJson = JSON.stringify(breadcrumb);
            html += '<div class="vault-folder-card" onclick="loadFolder(null,\'' + f.id + '\',\'' + f.name.replace(/'/g,"\\'") + '\',' + crumbJson + ')">'
                  + '<div class="vfc-icon">📁</div>'
                  + '<div class="vfc-name">' + escH(f.name) + '</div>';
            if (admin) html += '<div style="margin-top:auto;padding-top:6px"><button class="btn-sm btn-danger" onclick="event.stopPropagation();deleteFolder(\'' + f.id + '\',\'' + f.name.replace(/'/g,"\\'") + '\')">Delete</button></div>';
            html += '</div>';
        }
        html += '</div>';
    }

    // Files table
    if (data.files && data.files.length) {
        html += '<table class="vault-files-table"><thead><tr>'
             + '<th>File</th><th>Type</th><th>Size</th><th>Uploaded</th>'
             + (admin ? '<th></th>' : '') + '</tr></thead><tbody>';
        for (const f of data.files) {
            const icon = fileIcon(f.mime_type);
            html += '<tr>'
                 + '<td><a class="vf-dl" href="#" onclick="downloadFile(\'' + f.id + '\',\'' + f.name.replace(/'/g,"\\'") + '\');return false">' + icon + ' ' + escH(f.name) + '</a></td>'
                 + '<td><span class="vf-mime">' + escH(mimeLabel(f.mime_type)) + '</span></td>'
                 + '<td><span class="vf-size">' + escH(f.size_fmt) + '</span></td>'
                 + '<td><span class="vf-date">' + escH(f.created_at.substring(0,10)) + '</span></td>';
            if (admin) html += '<td><button class="btn-sm btn-danger" onclick="deleteFile(\'' + f.id + '\',\'' + f.name.replace(/'/g,"\\'") + '\')">Delete</button></td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
    }

    if ((!data.folders || !data.folders.length) && (!data.files || !data.files.length)) {
        html += '<div class="vault-empty">This folder is empty.' + (admin ? '<br><br><button class="btn-sm btn-green" onclick="showUpload()">⬆ Upload a file</button>' : '') + '</div>';
    }

    document.getElementById('vault-main').innerHTML = html;
}

function resetVault() {
    currentFolderId = null;
    breadcrumb = [];
    document.querySelectorAll('.vault-folder-item').forEach(x => x.classList.remove('active'));
    document.getElementById('vault-main').innerHTML = '<div class="vault-empty"><div style="font-size:40px;margin-bottom:12px">🗄️</div><div style="font-size:15px;font-weight:600;color:#555;margin-bottom:6px">The Vault</div><div>Select a folder from the left to browse files.</div></div>';
}

function showUpload() {
    const z = document.getElementById('upload-zone');
    if (z) { z.style.display = z.style.display === 'none' ? 'block' : 'none'; }
}

function showCreateFolder() {
    const name = prompt('Folder name:');
    if (!name || !name.trim()) return;
    fetch('api/vault_folder_create.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({parent_id: currentFolderId, name: name.trim()})
    }).then(r => r.json()).then(d => {
        if (d.ok) { vaultToast('Folder created'); loadFolder(null, currentFolderId, breadcrumb[breadcrumb.length-1]?.name ?? '', breadcrumb.slice(0,-1)); }
        else vaultToast('Error: ' + (d.error || 'unknown'));
    });
}

function handleDrop(e) {
    e.preventDefault();
    document.getElementById('upload-zone').classList.remove('drag');
    handleFiles(e.dataTransfer.files);
}

function handleFiles(files) {
    if (!files.length || !currentFolderId) return;
    const zone = document.getElementById('upload-zone');
    const bar  = document.getElementById('upload-bar');
    const lbl  = document.getElementById('upload-label');
    const prog = document.getElementById('upload-progress');
    prog.style.display = 'block';
    let done = 0;
    const total = files.length;
    const uploadNext = (i) => {
        if (i >= total) {
            vaultToast('Upload complete');
            prog.style.display = 'none';
            loadFolder(null, currentFolderId, breadcrumb[breadcrumb.length-1]?.name ?? '', breadcrumb.slice(0,-1));
            return;
        }
        const file = files[i];
        const fd = new FormData();
        fd.append('folder_id', currentFolderId);
        fd.append('file', file);
        lbl.textContent = `Uploading ${file.name} (${i+1}/${total})…`;
        bar.value = Math.round((i / total) * 100);
        fetch('api/vault_upload.php', {method:'POST', body: fd})
            .then(r => r.json())
            .then(d => {
                if (!d.ok) vaultToast('Upload failed: ' + file.name);
                uploadNext(i + 1);
            })
            .catch(() => { vaultToast('Upload error: ' + file.name); uploadNext(i+1); });
    };
    uploadNext(0);
}

function downloadFile(fileId, name) {
    fetch('api/vault_download.php?file_id=' + encodeURIComponent(fileId))
        .then(r => r.json())
        .then(d => {
            if (d.url) { const a = document.createElement('a'); a.href = d.url; a.download = name; a.click(); }
            else vaultToast('Error: ' + (d.error || 'unknown'));
        });
}

function deleteFile(fileId, name) {
    if (!confirm('Delete "' + name + '"? This cannot be undone.')) return;
    fetch('api/vault_file_delete.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({file_id:fileId})})
        .then(r => r.json()).then(d => {
            if (d.ok) { vaultToast('Deleted'); loadFolder(null, currentFolderId, breadcrumb[breadcrumb.length-1]?.name ?? '', breadcrumb.slice(0,-1)); }
            else vaultToast('Error: ' + (d.error || 'unknown'));
        });
}

function deleteFolder(folderId, name) {
    if (!confirm('Delete folder "' + name + '" and ALL its contents? This cannot be undone.')) return;
    fetch('api/vault_folder_delete.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({folder_id:folderId})})
        .then(r => r.json()).then(d => {
            if (d.ok) { vaultToast('Folder deleted'); loadFolder(null, currentFolderId, breadcrumb[breadcrumb.length-1]?.name ?? '', breadcrumb.slice(0,-1)); }
            else vaultToast('Error: ' + (d.error || 'unknown'));
        });
}

function vaultToast(msg) {
    const t = document.getElementById('vault-toast');
    t.textContent = msg; t.style.display = 'block';
    clearTimeout(t._to); t._to = setTimeout(() => t.style.display='none', 3500);
}

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fileIcon(mime) {
    if (!mime) return '📄';
    if (mime.includes('pdf'))   return '📕';
    if (mime.includes('word') || mime.includes('document')) return '📝';
    if (mime.includes('sheet') || mime.includes('excel'))   return '📊';
    if (mime.includes('presentation') || mime.includes('powerpoint')) return '📈';
    if (mime.includes('image')) return '🖼️';
    if (mime.includes('video')) return '🎬';
    if (mime.includes('audio')) return '🎵';
    if (mime.includes('zip') || mime.includes('compressed')) return '🗜️';
    return '📄';
}

function mimeLabel(mime) {
    if (!mime) return '';
    const m = {
        'application/pdf': 'PDF',
        'application/msword': 'Word',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'Word',
        'application/vnd.ms-excel': 'Excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'Excel',
        'application/vnd.ms-powerpoint': 'PowerPoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PowerPoint',
        'text/plain': 'Text', 'text/csv': 'CSV',
        'image/png': 'PNG', 'image/jpeg': 'JPEG', 'image/gif': 'GIF', 'image/webp': 'WebP',
        'video/mp4': 'MP4', 'audio/mpeg': 'MP3',
        'application/zip': 'ZIP',
    };
    return m[mime] || mime.split('/').pop();
}
</script>
</body>
</html>
