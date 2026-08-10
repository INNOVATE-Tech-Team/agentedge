<?php
ini_set('display_errors', 0);
// Floating Help Widget: shortcut list (super_admin managed) + access-filtered
// lesson search over uni_lessons/uni_courses metadata (title/objective/tags).
// Access rules mirror university.php's course-visibility filter so search
// results never leak a lesson an agent couldn't otherwise open.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../local_db.php';

$agent = require_login();
$db    = local_db();

$helpDir = __DIR__ . '/../data/help/';
if (!is_dir($helpDir)) @mkdir($helpDir, 0755, true);

// ── Serve an uploaded shortcut icon: ?icon=KEY ──────────────────────────────
if (!empty($_GET['icon'])) {
    $path = $helpDir . basename($_GET['icon']);
    if (!file_exists($path)) { http_response_code(404); exit; }
    $mime = mime_content_type($path) ?: 'image/png';
    header("Content-Type: $mime");
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ── Icon upload (multipart POST, super_admin only) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['file'])) {
    header('Content-Type: application/json');
    if (!is_super_admin()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'upload error '.$file['error']]); exit; }
    if ($file['size'] > 2 * 1024 * 1024) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'max 2 MB']); exit; }
    if (!in_array($file['type'], ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'], true)) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'image files only (jpeg/png/gif/webp/svg)']); exit;
    }
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $key  = uniqid('', true) . ($ext ? ".$ext" : '');
    if (!move_uploaded_file($file['tmp_name'], $helpDir . $key)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'save failed']); exit; }
    echo json_encode(['ok'=>true,'icon'=>'img:'.$key]); exit;
}

header('Content-Type: application/json');
$in     = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $in['action'] ?? '';

// ── Shortcuts visible to the widget (any logged-in agent) ───────────────────
if ($action === 'widget_shortcuts') {
    $rows = $db->query("SELECT id,label,icon,url,is_ext FROM help_shortcuts WHERE visible=1 ORDER BY sort_ord,id")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'shortcuts'=>$rows]); exit;
}

// ── Lesson search (any logged-in agent) — same access rules as university.php ─
if ($action === 'search') {
    $q = trim($in['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode(['ok'=>true,'results'=>[]]); exit; }

    $email       = $agent['email'];
    $isAdminUser = is_admin();
    $agentRoles  = my_roles();

    $agentStateCode = null;
    $aiRow = $db->prepare(
        "SELECT mc.state_code FROM agent_intake ai
         LEFT JOIN market_centers mc ON mc.slug=ai.office_location OR LOWER(mc.name)=LOWER(ai.office_location)
         WHERE LOWER(ai.email)=? LIMIT 1"
    );
    $aiRow->execute([strtolower($email)]);
    $aiResult = $aiRow->fetch(PDO::FETCH_ASSOC);
    if ($aiResult) $agentStateCode = $aiResult['state_code'] ?? null;

    $like = '%' . $q . '%';
    $stmt = $db->prepare(
        "SELECT l.id, l.title, l.learning_objective, l.tags, l.difficulty, l.related_lessons, l.course_id,
                c.invite_only, c.state_filter, c.role_filter
         FROM uni_lessons l JOIN uni_courses c ON c.id=l.course_id
         WHERE c.published=1 AND (l.title LIKE ? OR l.learning_objective LIKE ? OR l.tags LIKE ? OR l.difficulty LIKE ?)
         ORDER BY l.title LIMIT 40"
    );
    $stmt->execute([$like, $like, $like, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $visible = $isAdminUser ? $rows : array_values(array_filter($rows, function($l) use ($db, $email, $agentStateCode, $agentRoles) {
        if (!empty($l['invite_only'])) {
            $inv = $db->prepare("SELECT 1 FROM uni_course_invites WHERE course_id=? AND LOWER(agent_email)=?");
            $inv->execute([$l['course_id'], strtolower($email)]);
            if (!$inv->fetchColumn()) return false;
        }
        $sf = json_decode($l['state_filter'] ?? '[]', true);
        if (!empty($sf) && (!$agentStateCode || !in_array($agentStateCode, $sf, true))) return false;
        $rf = json_decode($l['role_filter'] ?? '[]', true);
        if (!empty($rf) && !array_intersect($agentRoles, $rf)) return false;
        return true;
    }));

    $visible = array_slice($visible, 0, 15);

    $results = array_map(function($l) use ($db) {
        $relIds = json_decode($l['related_lessons'] ?? '[]', true);
        $relIds = is_array($relIds) ? $relIds : [];
        $relIds = array_slice(array_values(array_filter(array_map('intval', $relIds), fn($id) => $id !== (int)$l['id'])), 0, 3);
        $related = [];
        if ($relIds) {
            $ph = implode(',', array_fill(0, count($relIds), '?'));
            $rs = $db->prepare("SELECT id,title FROM uni_lessons WHERE id IN ($ph)");
            $rs->execute($relIds);
            foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $related[] = ['id'=>(int)$r['id'], 'title'=>$r['title'], 'link'=>'university_lesson.php?id='.(int)$r['id']];
            }
        }
        return [
            'id'         => (int)$l['id'],
            'title'      => $l['title'],
            'objective'  => $l['learning_objective'],
            'tags'       => json_decode($l['tags'] ?? '[]', true) ?: [],
            'difficulty' => $l['difficulty'] ?: 'beginner',
            'link'       => 'university_lesson.php?id=' . (int)$l['id'],
            'related'    => $related,
        ];
    }, $visible);

    echo json_encode(['ok'=>true,'results'=>$results]); exit;
}

// ── Everything below requires super_admin ───────────────────────────────────
if (!is_super_admin()) { echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

if ($action === 'admin_list_shortcuts') {
    $rows = $db->query("SELECT * FROM help_shortcuts ORDER BY sort_ord,id")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'shortcuts'=>$rows]); exit;
}

if ($action === 'save_shortcut') {
    $id      = (int)($in['id'] ?? 0);
    $label   = trim($in['label'] ?? '');
    $icon    = trim($in['icon'] ?? '') ?: '🔗';
    $url     = trim($in['url'] ?? '');
    $visible = !empty($in['visible']) ? 1 : 0;
    $isExt   = !empty($in['is_ext']) ? 1 : 0;
    if ($label === '' || $url === '') { echo json_encode(['ok'=>false,'error'=>'Label and URL are required']); exit; }
    if ($id) {
        $db->prepare("UPDATE help_shortcuts SET label=?,icon=?,url=?,visible=?,is_ext=? WHERE id=?")
           ->execute([$label, $icon, $url, $visible, $isExt, $id]);
    } else {
        $nextOrd = (int)$db->query("SELECT COALESCE(MAX(sort_ord),0) FROM help_shortcuts")->fetchColumn() + 10;
        $db->prepare("INSERT INTO help_shortcuts (label,icon,url,visible,is_ext,sort_ord) VALUES (?,?,?,?,?,?)")
           ->execute([$label, $icon, $url, $visible, $isExt, $nextOrd]);
        $id = (int)$db->lastInsertId();
    }
    echo json_encode(['ok'=>true,'id'=>$id]); exit;
}

if ($action === 'toggle_shortcut_visible') {
    $id      = (int)($in['id'] ?? 0);
    $visible = !empty($in['visible']) ? 1 : 0;
    if ($id) $db->prepare("UPDATE help_shortcuts SET visible=? WHERE id=?")->execute([$visible, $id]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'delete_shortcut') {
    $id = (int)($in['id'] ?? 0);
    if ($id) $db->prepare("DELETE FROM help_shortcuts WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

if ($action === 'reorder_shortcuts') {
    $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? []))));
    $st  = $db->prepare("UPDATE help_shortcuts SET sort_ord=? WHERE id=?");
    foreach ($ids as $i => $id) { if ($id > 0) $st->execute([($i + 1) * 10, $id]); }
    echo json_encode(['ok'=>true]); exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action']);
