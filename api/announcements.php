<?php
// GET  — returns active announcements scoped to the signed-in agent's role/MC/BIC.
//         ?action=image&key=... serves an announcement image (auth required).
// POST — create/update/delete/pin. Accepts JSON or multipart/form-data (for image uploads).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require __DIR__ . '/../lib/notifications.php';

$me = current_agent();
if (!$me) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['error'=>'not signed in']); exit; }

// ── Image serving ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'image') {
    $key = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $_GET['key'] ?? '');
    if (!$key) { http_response_code(404); exit; }
    $cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    $dataDir = $cfgDir ?: (dirname(__DIR__) . '/data');
    $path    = $dataDir . '/announcement_images/' . $key;
    if (!file_exists($path)) { http_response_code(404); exit; }
    $mime = mime_content_type($path) ?: 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

header('Content-Type: application/json');

// ── GET — list announcements ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $db = local_db();

    if (is_admin()) {
        $rows = $db->query(
            "SELECT id,title,body,author,audience,target_mc_slug,target_bic_email,pinned,created_at,expires_at,image_key,image_position,image_size
             FROM announcements
             WHERE (expires_at IS NULL OR expires_at >= datetime('now'))
             ORDER BY pinned DESC, created_at DESC
             LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $conds  = ["audience='all'"];
        $params = [];

        $ownMc = my_own_mc_slug();
        if ($ownMc !== '') {
            $conds[]  = "(audience='mc' AND target_mc_slug=?)";
            $params[] = $ownMc;
        }

        foreach (my_mc_slugs() as $slug) {
            if ($slug !== $ownMc) {
                $conds[]  = "(audience='mc' AND target_mc_slug=?)";
                $params[] = $slug;
            }
        }

        $bicEmail = my_bic_email();
        if ($bicEmail !== '') {
            $conds[]  = "(audience='bic' AND target_bic_email=?)";
            $params[] = $bicEmail;
        }

        if (is_bic()) {
            $conds[]  = "(audience='bic' AND target_bic_email=?)";
            $params[] = $me['email'];
        }

        $where = '(' . implode(' OR ', $conds) . ')';
        $stmt  = $db->prepare(
            "SELECT id,title,body,author,audience,target_mc_slug,target_bic_email,pinned,created_at,expires_at,image_key,image_position,image_size
             FROM announcements
             WHERE (expires_at IS NULL OR expires_at >= datetime('now'))
               AND $where
             ORDER BY pinned DESC, created_at DESC
             LIMIT 20"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['ok'=>true,'items'=>$rows]);
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if (!can_post_announcements()) {
    http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
}

// Accept JSON or multipart/form-data (required when uploading an image).
$isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$in = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?: []);

$action = $in['action'] ?? 'create';

// ── Body sanitizer ────────────────────────────────────────────────────────────
function sanitize_body(string $html): string {
    $clean = strip_tags($html, '<b><strong><i><em><u><br><p><h2><h3><ul><ol><li><a>');
    // Sanitize <a> tags — only allow safe href (http/https/mailto), strip all other attributes
    $clean = preg_replace_callback('/<a([^>]*)>/i', function($m) {
        if (preg_match('/href\s*=\s*["\']([^"\']*)["\']/', $m[1], $hm)) {
            $href = trim($hm[1]);
            if (preg_match('/^(https?:\/\/|mailto:)/i', $href)) {
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" target="_blank" rel="noopener noreferrer">';
            }
        }
        return '<a>';
    }, $clean);
    return $clean;
}

// ── Image helpers ─────────────────────────────────────────────────────────────
function ann_img_dir(): string {
    $cfgDir  = function_exists('cfg') ? (cfg()['local_db_dir'] ?? null) : null;
    $dataDir = $cfgDir ?: (dirname(__DIR__) . '/data');
    $dir     = $dataDir . '/announcement_images';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function save_announcement_image(): string {
    if (empty($_FILES['image']['tmp_name'])) return '';
    $file    = $_FILES['image'];
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mime    = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) return '';
    if ($file['size'] > 8 * 1024 * 1024) return '';
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime] ?? 'jpg';
    $key = bin2hex(random_bytes(16)) . '.' . $ext;
    move_uploaded_file($file['tmp_name'], ann_img_dir() . '/' . $key);
    return $key;
}

function delete_announcement_image(string $key): void {
    if ($key === '') return;
    $path = ann_img_dir() . '/' . $key;
    if (file_exists($path)) @unlink($path);
}

// ── create / update ───────────────────────────────────────────────────────────
if ($action === 'create' || $action === 'update') {
    $title = trim($in['title'] ?? '');
    $body  = sanitize_body(trim($in['body']  ?? ''));
    if (!$title || !$body) {
        http_response_code(400); echo json_encode(['error'=>'title and body required']); exit;
    }
    $pinned       = empty($in['pinned'])      ? 0 : 1;
    $expires      = !empty($in['expires_at']) ? $in['expires_at'] : null;
    $imagePosition = in_array($in['image_position'] ?? '', ['left','center','right']) ? $in['image_position'] : 'center';
    $imageSize     = in_array($in['image_size'] ?? '', ['compact','standard','large']) ? $in['image_size'] : 'standard';

    $audience       = '';
    $targetMcSlug   = '';
    $targetBicEmail = '';

    if (is_admin()) {
        $audience = in_array($in['audience'] ?? '', ['all','admin','mc']) ? $in['audience'] : 'all';
        if ($audience === 'mc') {
            $targetMcSlug = trim($in['target_mc_slug'] ?? '');
            if ($targetMcSlug === '') {
                http_response_code(400); echo json_encode(['error'=>'target_mc_slug required for mc audience']); exit;
            }
        }
    } elseif (is_mc_leader()) {
        $audience     = 'mc';
        $targetMcSlug = trim($in['target_mc_slug'] ?? '');
        if (!in_array($targetMcSlug, my_mc_slugs(), true)) {
            http_response_code(403); echo json_encode(['error'=>'not your market center']); exit;
        }
    } elseif (is_bic()) {
        $audience       = 'bic';
        $targetBicEmail = $me['email'];
    }

    if ($audience === '') {
        http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
    }

    if ($action === 'update') {
        $id = (int)($in['id'] ?? 0);

        if (!is_admin()) {
            $chk = local_db()->prepare("SELECT author FROM announcements WHERE id=?");
            $chk->execute([$id]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$existing || $existing['author'] !== $me['email']) {
                http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
            }
        }

        $cur = local_db()->prepare("SELECT image_key FROM announcements WHERE id=?");
        $cur->execute([$id]);
        $curRow  = $cur->fetch(PDO::FETCH_ASSOC);
        $oldKey  = $curRow['image_key'] ?? '';

        if (!empty($in['remove_image'])) {
            delete_announcement_image($oldKey);
            $imageKey = '';
        } elseif (!empty($_FILES['image']['tmp_name'])) {
            delete_announcement_image($oldKey);
            $imageKey = save_announcement_image();
        } else {
            $imageKey = $oldKey;
        }

        local_db()->prepare(
            "UPDATE announcements SET title=?,body=?,audience=?,target_mc_slug=?,target_bic_email=?,pinned=?,expires_at=?,image_key=?,image_position=?,image_size=? WHERE id=?"
        )->execute([$title,$body,$audience,$targetMcSlug,$targetBicEmail,$pinned,$expires,$imageKey,$imagePosition,$imageSize,$id]);

        echo json_encode(['ok'=>true,'id'=>$id]);
        exit;
    }

    // create
    $imageKey = save_announcement_image();
    local_db()->prepare(
        "INSERT INTO announcements (title,body,author,audience,target_mc_slug,target_bic_email,pinned,expires_at,image_key,image_position,image_size) VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([$title,$body,$me['email'],$audience,$targetMcSlug,$targetBicEmail,$pinned,$expires,$imageKey,$imagePosition,$imageSize]);
    $newId = local_db()->lastInsertId();

    $queued = queue_announcement_notifications(
        (int)$newId, $title, $body, $audience, $targetMcSlug, $targetBicEmail
    );
    echo json_encode(['ok'=>true,'id'=>(int)$newId,'notified'=>$queued]);
    dispatch_notification_queue();
    exit;
}

// ── delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($in['id'] ?? 0);
    if (!is_admin()) {
        $chk = local_db()->prepare("SELECT author FROM announcements WHERE id=?");
        $chk->execute([$id]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$existing || $existing['author'] !== $me['email']) {
            http_response_code(403); echo json_encode(['error'=>'forbidden']); exit;
        }
    }
    $cur = local_db()->prepare("SELECT image_key FROM announcements WHERE id=?");
    $cur->execute([$id]);
    $curRow = $cur->fetch(PDO::FETCH_ASSOC);
    delete_announcement_image($curRow['image_key'] ?? '');

    local_db()->prepare("DELETE FROM announcements WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── pin ───────────────────────────────────────────────────────────────────────
if ($action === 'pin') {
    if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
    $id  = (int)($in['id'] ?? 0);
    $val = empty($in['pinned']) ? 0 : 1;
    local_db()->prepare("UPDATE announcements SET pinned=? WHERE id=?")->execute([$val,$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
