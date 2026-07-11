<?php
// Admin CRUD for INNOVATE University: categories, courses, lessons, quiz questions, file uploads.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$db      = local_db();
$uniDir  = __DIR__ . '/../data/uni/';

// ── File uploads (multipart POST) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['file'])) {
    $action = $_POST['action'] ?? '';
    $file   = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400); echo json_encode(['error'=>'upload error ' . $file['error']]); exit;
    }
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $key  = uniqid('', true) . ($ext ? ".$ext" : '');
    $dest = $uniDir . $key;

    if ($action === 'upload_thumbnail') {
        $courseId = (int)($_POST['course_id'] ?? 0);
        if (!$courseId) { http_response_code(400); echo json_encode(['error'=>'course_id required']); exit; }
        if ($file['size'] > 10 * 1024 * 1024) { http_response_code(400); echo json_encode(['error'=>'max 10 MB for thumbnails']); exit; }
        if (!in_array($file['type'], ['image/jpeg','image/png','image/gif','image/webp'])) {
            http_response_code(400); echo json_encode(['error'=>'image files only (jpeg/png/gif/webp)']); exit;
        }
        $old = $db->prepare("SELECT thumb_key FROM uni_courses WHERE id=?");
        $old->execute([$courseId]); $oldKey = $old->fetchColumn();
        if ($oldKey && file_exists($uniDir . $oldKey)) @unlink($uniDir . $oldKey);
        if (!move_uploaded_file($file['tmp_name'], $dest)) { http_response_code(500); echo json_encode(['error'=>'save failed']); exit; }
        $db->prepare("UPDATE uni_courses SET thumb_key=? WHERE id=?")->execute([$key, $courseId]);
        echo json_encode(['ok'=>true,'key'=>$key]); exit;
    }

    if ($action === 'upload_lesson_file') {
        $lessonId = (int)($_POST['lesson_id'] ?? 0);
        if (!$lessonId) { http_response_code(400); echo json_encode(['error'=>'lesson_id required']); exit; }
        // Note: actual PHP upload limit is set by upload_max_filesize in php.ini
        $old = $db->prepare("SELECT file_key FROM uni_lessons WHERE id=?");
        $old->execute([$lessonId]); $oldKey = $old->fetchColumn();
        if ($oldKey && file_exists($uniDir . $oldKey)) @unlink($uniDir . $oldKey);
        if (!move_uploaded_file($file['tmp_name'], $dest)) { http_response_code(500); echo json_encode(['error'=>'save failed']); exit; }
        $db->prepare("UPDATE uni_lessons SET file_key=? WHERE id=?")->execute([$key, $lessonId]);
        echo json_encode(['ok'=>true,'key'=>$key]); exit;
    }

    http_response_code(400); echo json_encode(['error'=>'unknown upload action']); exit;
}

// ── JSON body actions ─────────────────────────────────────────────────────
$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';

// ── Categories ────────────────────────────────────────────────────────────
if ($action === 'list_categories') {
    $cats = $db->query("SELECT *, (SELECT COUNT(*) FROM uni_courses WHERE category_id=uni_categories.id) as course_count FROM uni_categories ORDER BY sort_ord,id")
               ->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'categories'=>$cats]); exit;
}
if ($action === 'create_category') {
    $name = trim($in['name'] ?? '');
    if (!$name) { http_response_code(400); echo json_encode(['error'=>'name required']); exit; }
    $db->prepare("INSERT INTO uni_categories (name,icon,sort_ord) VALUES (?,?,?)")
       ->execute([$name, trim($in['icon'] ?? '📚'), (int)($in['sort_ord'] ?? 0)]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_category') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $db->prepare("UPDATE uni_categories SET name=?,icon=?,sort_ord=? WHERE id=?")
       ->execute([trim($in['name'] ?? ''), trim($in['icon'] ?? '📚'), (int)($in['sort_ord'] ?? 0), $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_category') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $db->prepare("UPDATE uni_courses SET category_id=NULL WHERE category_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_categories WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Courses ───────────────────────────────────────────────────────────────
if ($action === 'list_courses') {
    $catId = !empty($in['category_id']) ? (int)$in['category_id'] : null;
    $sql   = "SELECT c.*, COALESCE(cat.name,'Uncategorized') as cat_name, COALESCE(cat.icon,'📚') as cat_icon,
              (SELECT COUNT(*) FROM uni_lessons WHERE course_id=c.id) as lesson_count,
              (SELECT COUNT(*) FROM uni_certs WHERE course_id=c.id) as cert_count
              FROM uni_courses c LEFT JOIN uni_categories cat ON cat.id=c.category_id";
    if ($catId) {
        $s = $db->prepare($sql . " WHERE c.category_id=? ORDER BY c.sort_ord,c.id");
        $s->execute([$catId]);
    } else {
        $s = $db->query($sql . " ORDER BY c.sort_ord,c.id");
    }
    echo json_encode(['ok'=>true,'courses'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'create_course') {
    $title = trim($in['title'] ?? '');
    if (!$title) { http_response_code(400); echo json_encode(['error'=>'title required']); exit; }
    $catId = !empty($in['category_id']) ? (int)$in['category_id'] : null;
    $db->prepare("INSERT INTO uni_courses (category_id,title,description,is_required,sort_ord,published,created_by) VALUES (?,?,?,?,?,0,?)")
       ->execute([$catId, $title, trim($in['description'] ?? ''), (int)($in['is_required'] ?? 0), (int)($in['sort_ord'] ?? 0), $me['email']]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_course') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $catId = !empty($in['category_id']) ? (int)$in['category_id'] : null;
    $db->prepare("UPDATE uni_courses SET category_id=?,title=?,description=?,is_required=?,sort_ord=?,published=? WHERE id=?")
       ->execute([$catId, trim($in['title'] ?? ''), trim($in['description'] ?? ''), (int)($in['is_required'] ?? 0), (int)($in['sort_ord'] ?? 0), (int)($in['published'] ?? 0), $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_course') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    // Delete thumbnail file
    $th = $db->prepare("SELECT thumb_key FROM uni_courses WHERE id=?"); $th->execute([$id]);
    $thKey = $th->fetchColumn();
    if ($thKey && file_exists($uniDir . $thKey)) @unlink($uniDir . $thKey);
    // Delete lesson files
    $les = $db->prepare("SELECT file_key FROM uni_lessons WHERE course_id=? AND file_key!=''"); $les->execute([$id]);
    foreach ($les->fetchAll(PDO::FETCH_COLUMN) as $fk) { if (file_exists($uniDir . $fk)) @unlink($uniDir . $fk); }
    // Cascade: questions → progress → certs → lessons → course
    $lids = $db->prepare("SELECT id FROM uni_lessons WHERE course_id=?"); $lids->execute([$id]);
    foreach ($lids->fetchAll(PDO::FETCH_COLUMN) as $lid) {
        $db->prepare("DELETE FROM uni_questions WHERE lesson_id=?")->execute([$lid]);
        $db->prepare("DELETE FROM uni_progress WHERE lesson_id=?")->execute([$lid]);
    }
    $db->prepare("DELETE FROM uni_lessons WHERE course_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_certs WHERE course_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_courses WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Lessons ───────────────────────────────────────────────────────────────
if ($action === 'list_lessons') {
    $courseId = (int)($in['course_id'] ?? 0);
    if (!$courseId) { http_response_code(400); echo json_encode(['error'=>'course_id required']); exit; }
    $s = $db->prepare("SELECT *, (SELECT COUNT(*) FROM uni_questions WHERE lesson_id=uni_lessons.id) as question_count FROM uni_lessons WHERE course_id=? ORDER BY sort_ord,id");
    $s->execute([$courseId]);
    echo json_encode(['ok'=>true,'lessons'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'create_lesson') {
    $courseId = (int)($in['course_id'] ?? 0);
    $title    = trim($in['title'] ?? '');
    if (!$courseId || !$title) { http_response_code(400); echo json_encode(['error'=>'course_id and title required']); exit; }
    $type = in_array($in['type'] ?? '', ['video','doc','quiz']) ? $in['type'] : 'video';
    $mo   = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM uni_lessons WHERE course_id=?"); $mo->execute([$courseId]);
    $nextOrd = ((int)$mo->fetchColumn()) + 10;
    $db->prepare("INSERT INTO uni_lessons (course_id,title,sort_ord,type,embed_url,content_html,duration_sec) VALUES (?,?,?,?,?,?,?)")
       ->execute([$courseId, $title, $nextOrd, $type, trim($in['embed_url'] ?? ''), trim($in['content_html'] ?? ''), (int)($in['duration_sec'] ?? 0)]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_lesson') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $db->prepare("UPDATE uni_lessons SET title=?,sort_ord=?,embed_url=?,content_html=?,duration_sec=? WHERE id=?")
       ->execute([trim($in['title'] ?? ''), (int)($in['sort_ord'] ?? 0), trim($in['embed_url'] ?? ''), trim($in['content_html'] ?? ''), (int)($in['duration_sec'] ?? 0), $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_lesson') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $fkQ = $db->prepare("SELECT file_key FROM uni_lessons WHERE id=?"); $fkQ->execute([$id]);
    $fk  = $fkQ->fetchColumn();
    if ($fk && file_exists($uniDir . $fk)) @unlink($uniDir . $fk);
    $db->prepare("DELETE FROM uni_questions WHERE lesson_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_progress WHERE lesson_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_lessons WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'reorder_lessons') {
    $order = $in['order'] ?? [];
    if (!is_array($order)) { http_response_code(400); echo json_encode(['error'=>'order array required']); exit; }
    $upd = $db->prepare("UPDATE uni_lessons SET sort_ord=? WHERE id=?");
    foreach ($order as $i => $lessonId) $upd->execute([($i + 1) * 10, (int)$lessonId]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Quiz Questions ─────────────────────────────────────────────────────────
if ($action === 'list_questions') {
    $lessonId = (int)($in['lesson_id'] ?? 0);
    if (!$lessonId) { http_response_code(400); echo json_encode(['error'=>'lesson_id required']); exit; }
    $s = $db->prepare("SELECT * FROM uni_questions WHERE lesson_id=? ORDER BY sort_ord,id");
    $s->execute([$lessonId]);
    echo json_encode(['ok'=>true,'questions'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'create_question') {
    $lessonId = (int)($in['lesson_id'] ?? 0);
    $question = trim($in['question'] ?? '');
    $options  = $in['options'] ?? [];
    if (!$lessonId || !$question || !is_array($options) || count($options) < 2) {
        http_response_code(400); echo json_encode(['error'=>'lesson_id, question, and at least 2 options required']); exit;
    }
    $mo = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM uni_questions WHERE lesson_id=?"); $mo->execute([$lessonId]);
    $nextOrd = ((int)$mo->fetchColumn()) + 10;
    $db->prepare("INSERT INTO uni_questions (lesson_id,question,options,correct_index,sort_ord) VALUES (?,?,?,?,?)")
       ->execute([$lessonId, $question, json_encode(array_values($options)), (int)($in['correct_index'] ?? 0), $nextOrd]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_question') {
    $id      = (int)($in['id'] ?? 0);
    $question = trim($in['question'] ?? '');
    $options  = $in['options'] ?? [];
    if (!$id || !$question || !is_array($options) || count($options) < 2) {
        http_response_code(400); echo json_encode(['error'=>'id, question, and 2+ options required']); exit;
    }
    $db->prepare("UPDATE uni_questions SET question=?,options=?,correct_index=?,sort_ord=? WHERE id=?")
       ->execute([$question, json_encode(array_values($options)), (int)($in['correct_index'] ?? 0), (int)($in['sort_ord'] ?? 0), $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_question') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $db->prepare("DELETE FROM uni_questions WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Stats (for admin dashboard) ────────────────────────────────────────────
if ($action === 'course_stats') {
    $courseId = (int)($in['course_id'] ?? 0);
    if (!$courseId) { http_response_code(400); echo json_encode(['error'=>'course_id required']); exit; }
    $ts = $db->prepare("SELECT COUNT(*) FROM uni_lessons WHERE course_id=?"); $ts->execute([$courseId]);
    $cs = $db->prepare("SELECT COUNT(*) FROM uni_certs WHERE course_id=?"); $cs->execute([$courseId]);
    echo json_encode(['ok'=>true,'total_lessons'=>(int)$ts->fetchColumn(),'cert_count'=>(int)$cs->fetchColumn()]); exit;
}

http_response_code(400);
echo json_encode(['error'=>'unknown action']);
