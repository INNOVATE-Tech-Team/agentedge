<?php
ini_set("display_errors", 0);
// Admin CRUD for INNOVATE University: categories, courses, lessons, quiz questions, file uploads.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/uni_templates.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }
if (!is_admin()) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$db      = local_db();
$uniDir  = __DIR__ . '/../data/uni/';

// Large video uploads over slow connections can exceed the default 30s
// max_execution_time; raise it immediately here rather than waiting on
// api/.user.ini's cache TTL. upload_max_filesize/post_max_size/max_input_time
// are PHP_INI_PERDIR and can only come from .user.ini (see api/.user.ini).
set_time_limit(0);
ini_set('max_execution_time', '0');

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
        // upload_max_filesize/post_max_size are set in api/.user.ini
        $old = $db->prepare("SELECT file_key FROM uni_lessons WHERE id=?");
        $old->execute([$lessonId]); $oldKey = $old->fetchColumn();
        if ($oldKey && file_exists($uniDir . $oldKey)) @unlink($uniDir . $oldKey);
        if (!move_uploaded_file($file['tmp_name'], $dest)) { http_response_code(500); echo json_encode(['error'=>'save failed']); exit; }
        $db->prepare("UPDATE uni_lessons SET file_key=? WHERE id=?")->execute([$key, $lessonId]);
        echo json_encode(['ok'=>true,'key'=>$key]); exit;
    }

    if ($action === 'upload_lesson_attachment') {
        $lessonId = (int)($_POST['lesson_id'] ?? 0);
        if (!$lessonId) { http_response_code(400); echo json_encode(['error'=>'lesson_id required']); exit; }
        if (!move_uploaded_file($file['tmp_name'], $dest)) { http_response_code(500); echo json_encode(['error'=>'save failed']); exit; }
        $mo = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM uni_lesson_files WHERE lesson_id=?"); $mo->execute([$lessonId]);
        $nextOrd = ((int)$mo->fetchColumn()) + 10;
        $db->prepare("INSERT INTO uni_lesson_files (lesson_id,file_key,original_name,sort_ord) VALUES (?,?,?,?)")
           ->execute([$lessonId, $key, $file['name'], $nextOrd]);
        echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId(),'key'=>$key,'name'=>$file['name']]); exit;
    }

    if ($action === 'upload_content_image') {
        if (!in_array($file['type'], ['image/jpeg','image/png','image/gif','image/webp'])) {
            http_response_code(400); echo json_encode(['error'=>'image files only (jpeg/png/gif/webp)']); exit;
        }
        if (!move_uploaded_file($file['tmp_name'], $dest)) { http_response_code(500); echo json_encode(['error'=>'save failed']); exit; }
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

// Folders and top-level (unfoldered) lessons share one sort_ord sequence per
// course so the Lessons tab can render them interleaved instead of always
// placing folders first — this returns the next value in that shared sequence.
function next_toplevel_sort_ord(PDO $db, int $courseId): int {
    $mo = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM (
        SELECT sort_ord FROM uni_folders WHERE course_id=?
        UNION ALL
        SELECT sort_ord FROM uni_lessons WHERE course_id=? AND folder_id IS NULL
    )");
    $mo->execute([$courseId, $courseId]);
    return ((int)$mo->fetchColumn()) + 10;
}

// ── Folders ───────────────────────────────────────────────────────────────
if ($action === 'list_folders') {
    $courseId = (int)($in['course_id'] ?? 0);
    if (!$courseId) { http_response_code(400); echo json_encode(['error'=>'course_id required']); exit; }
    $s = $db->prepare("SELECT * FROM uni_folders WHERE course_id=? ORDER BY sort_ord,id");
    $s->execute([$courseId]);
    echo json_encode(['ok'=>true,'folders'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'create_folder') {
    $courseId    = (int)($in['course_id'] ?? 0);
    $title       = trim($in['title'] ?? '');
    $code        = strtoupper(trim($in['code'] ?? ''));
    $description = trim($in['description'] ?? '');
    if (!$courseId || !$title) { http_response_code(400); echo json_encode(['error'=>'course_id and title required']); exit; }
    $nextOrd = next_toplevel_sort_ord($db, $courseId);
    $db->prepare("INSERT INTO uni_folders (course_id,title,code,description,sort_ord) VALUES (?,?,?,?,?)")->execute([$courseId, $title, $code, $description, $nextOrd]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_folder') {
    $id          = (int)($in['id'] ?? 0);
    $title       = trim($in['title'] ?? '');
    $code        = strtoupper(trim($in['code'] ?? ''));
    $description = trim($in['description'] ?? '');
    if (!$id || !$title) { http_response_code(400); echo json_encode(['error'=>'id and title required']); exit; }
    $db->prepare("UPDATE uni_folders SET title=?,code=?,description=? WHERE id=?")->execute([$title, $code, $description, $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_folder') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $cq = $db->prepare("SELECT course_id FROM uni_folders WHERE id=?"); $cq->execute([$id]);
    $courseId = (int)$cq->fetchColumn();
    tombstone_if_template_derived($db, 'uni_folders', $id, 'folder');
    // Orphaned lessons become top-level — append them to the end of the shared
    // top-level sequence rather than leaving their old intra-folder sort_ord,
    // which has no meaning outside the folder they just left.
    $orphans = $db->prepare("SELECT id FROM uni_lessons WHERE folder_id=? ORDER BY sort_ord,id"); $orphans->execute([$id]);
    $orphanIds = $orphans->fetchAll(PDO::FETCH_COLUMN);
    $upd = $db->prepare("UPDATE uni_lessons SET folder_id=NULL, sort_ord=? WHERE id=?");
    foreach ($orphanIds as $lid) {
        $upd->execute([next_toplevel_sort_ord($db, $courseId), $lid]);
    }
    $db->prepare("DELETE FROM uni_folders WHERE id=?")->execute([$id]);
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
    $layoutStyle = in_array($in['layout_style'] ?? '', ['standard', 'on_demand_hero'], true) ? $in['layout_style'] : 'standard';
    $estMinutes = max(0, (int)($in['overview_estimated_minutes'] ?? 0));
    $db->prepare("INSERT INTO uni_courses (category_id,title,description,is_required,sort_ord,published,created_by,invite_only,state_filter,role_filter,layout_style,overview_estimated_minutes) VALUES (?,?,?,?,?,0,?,?,?,?,?,?)")
       ->execute([$catId, $title, trim($in['description'] ?? ''), (int)($in['is_required'] ?? 0), (int)($in['sort_ord'] ?? 0), $me['email'], 0, '[]', '[]', $layoutStyle, $estMinutes]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_course') {
    // Every column below is preserve-if-absent (array_key_exists against the existing row),
    // not a blind overwrite -- callers that only send a subset of fields (e.g. the Settings tab
    // sending just its own sequencing/quiz/cert fields, or togglePublish() sending only
    // {id, published}) must not blank out title/description/etc. Fixes a real, live data-loss
    // bug: togglePublish() on admin_university.php has always sent a partial payload, and the
    // previous blind-overwrite UPDATE silently wiped title/description/category/sort_ord on
    // every publish/unpublish click.
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $existingStmt = $db->prepare(
        "SELECT category_id,title,description,is_required,sort_ord,published,invite_only,state_filter,role_filter,
                layout_style,overview_estimated_minutes,sequencing_mode,quiz_pass_score,quiz_retake_policy,
                quiz_max_attempts,quiz_block_on_fail,cert_enabled,cert_expiry_months,cert_design
         FROM uni_courses WHERE id=?"
    );
    $existingStmt->execute([$id]);
    $ex = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$ex) { http_response_code(404); echo json_encode(['error'=>'course not found']); exit; }
    $catId       = array_key_exists('category_id', $in) ? (!empty($in['category_id']) ? (int)$in['category_id'] : null) : $ex['category_id'];
    $title       = array_key_exists('title', $in) ? trim($in['title']) : $ex['title'];
    $description = array_key_exists('description', $in) ? trim($in['description']) : $ex['description'];
    $isRequired  = array_key_exists('is_required', $in) ? (int)$in['is_required'] : (int)$ex['is_required'];
    $sortOrd     = array_key_exists('sort_ord', $in) ? (int)$in['sort_ord'] : (int)$ex['sort_ord'];
    $published   = array_key_exists('published', $in) ? (int)$in['published'] : (int)$ex['published'];
    $inviteOnly  = array_key_exists('invite_only', $in) ? (int)$in['invite_only'] : (int)$ex['invite_only'];
    $stateFilter = array_key_exists('state_filter', $in) ? json_encode(array_values(array_filter((array)$in['state_filter'], 'strlen'))) : $ex['state_filter'];
    $roleFilter  = array_key_exists('role_filter', $in) ? json_encode(array_values(array_filter((array)$in['role_filter'], 'strlen'))) : $ex['role_filter'];
    $layoutStyle = array_key_exists('layout_style', $in)
        ? (in_array($in['layout_style'], ['standard', 'on_demand_hero'], true) ? $in['layout_style'] : $ex['layout_style'])
        : $ex['layout_style'];
    $estMinutes  = array_key_exists('overview_estimated_minutes', $in) ? max(0, (int)$in['overview_estimated_minutes']) : (int)$ex['overview_estimated_minutes'];
    // Sequencing/Quiz/Certificate Settings -- previously accepted by this action's
    // whitelist but never actually written to the UPDATE below, so the admin
    // Settings card silently no-op'd while still showing "Settings saved".
    $sequencingMode   = array_key_exists('sequencing_mode', $in)
        ? (in_array($in['sequencing_mode'], ['free', 'in_order'], true) ? $in['sequencing_mode'] : $ex['sequencing_mode'])
        : $ex['sequencing_mode'];
    $quizPassScore    = array_key_exists('quiz_pass_score', $in) ? max(0, min(100, (int)$in['quiz_pass_score'])) : (int)$ex['quiz_pass_score'];
    $quizRetakePolicy = array_key_exists('quiz_retake_policy', $in)
        ? (in_array($in['quiz_retake_policy'], ['unlimited', 'limited'], true) ? $in['quiz_retake_policy'] : $ex['quiz_retake_policy'])
        : $ex['quiz_retake_policy'];
    $quizMaxAttempts  = array_key_exists('quiz_max_attempts', $in) ? max(0, (int)$in['quiz_max_attempts']) : (int)$ex['quiz_max_attempts'];
    $quizBlockOnFail  = array_key_exists('quiz_block_on_fail', $in) ? (int)(bool)$in['quiz_block_on_fail'] : (int)$ex['quiz_block_on_fail'];
    $certEnabled      = array_key_exists('cert_enabled', $in) ? (int)(bool)$in['cert_enabled'] : (int)$ex['cert_enabled'];
    $certExpiryMonths = array_key_exists('cert_expiry_months', $in) ? max(0, (int)$in['cert_expiry_months']) : (int)$ex['cert_expiry_months'];
    $certDesign       = array_key_exists('cert_design', $in) ? trim((string)$in['cert_design']) ?: $ex['cert_design'] : $ex['cert_design'];
    $db->prepare(
        "UPDATE uni_courses SET category_id=?,title=?,description=?,is_required=?,sort_ord=?,published=?,invite_only=?,
                state_filter=?,role_filter=?,layout_style=?,overview_estimated_minutes=?,sequencing_mode=?,
                quiz_pass_score=?,quiz_retake_policy=?,quiz_max_attempts=?,quiz_block_on_fail=?,cert_enabled=?,
                cert_expiry_months=?,cert_design=? WHERE id=?"
    )->execute([
        $catId, $title, $description, $isRequired, $sortOrd, $published, $inviteOnly, $stateFilter, $roleFilter,
        $layoutStyle, $estMinutes, $sequencingMode, $quizPassScore, $quizRetakePolicy, $quizMaxAttempts,
        $quizBlockOnFail, $certEnabled, $certExpiryMonths, $certDesign, $id,
    ]);
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
    // Cascade: attachments/questions/answers/progress/uploads → certs → lessons → folders → course
    $lids = $db->prepare("SELECT id FROM uni_lessons WHERE course_id=?"); $lids->execute([$id]);
    foreach ($lids->fetchAll(PDO::FETCH_COLUMN) as $lid) {
        $af = $db->prepare("SELECT file_key FROM uni_lesson_files WHERE lesson_id=?"); $af->execute([$lid]);
        foreach ($af->fetchAll(PDO::FETCH_COLUMN) as $fk) { if (file_exists($uniDir . $fk)) @unlink($uniDir . $fk); }
        $uf = $db->prepare("SELECT file_key FROM uni_learner_uploads WHERE lesson_id=?"); $uf->execute([$lid]);
        foreach ($uf->fetchAll(PDO::FETCH_COLUMN) as $fk) { if (file_exists($uniDir . $fk)) @unlink($uniDir . $fk); }
        $db->prepare("DELETE FROM uni_lesson_files WHERE lesson_id=?")->execute([$lid]);
        $db->prepare("DELETE FROM uni_questions WHERE lesson_id=?")->execute([$lid]);
        $db->prepare("DELETE FROM uni_quiz_answers WHERE lesson_id=?")->execute([$lid]);
        $db->prepare("DELETE FROM uni_progress WHERE lesson_id=?")->execute([$lid]);
        $db->prepare("DELETE FROM uni_learner_uploads WHERE lesson_id=?")->execute([$lid]);
        $db->prepare("DELETE FROM uni_feedback_answers WHERE response_id IN (SELECT id FROM uni_feedback_responses WHERE lesson_id=?)")->execute([$lid]);
        $db->prepare("DELETE FROM uni_feedback_responses WHERE lesson_id=?")->execute([$lid]);
        $db->prepare("DELETE FROM uni_feedback_questions WHERE lesson_id=?")->execute([$lid]);
    }
    $db->prepare("DELETE FROM uni_lessons WHERE course_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_folders WHERE course_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_certs WHERE course_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_courses WHERE id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_course_invites WHERE course_id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Course Invites ────────────────────────────────────────────────────────
if ($action === 'list_invites') {
    $cid = (int)($in['course_id'] ?? 0);
    if (!$cid) { echo json_encode(['ok'=>false,'error'=>'course_id required']); exit; }
    $s = $db->prepare("SELECT * FROM uni_course_invites WHERE course_id=? ORDER BY invited_at DESC");
    $s->execute([$cid]);
    echo json_encode(['ok'=>true,'invites'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'add_invite') {
    $cid        = (int)($in['course_id'] ?? 0);
    $agentEmail = strtolower(trim($in['agent_email'] ?? ''));
    if (!$cid || !$agentEmail) { echo json_encode(['ok'=>false,'error'=>'missing fields']); exit; }
    $db->prepare("INSERT OR IGNORE INTO uni_course_invites (course_id,agent_email,invited_by) VALUES (?,?,?)")
       ->execute([$cid, $agentEmail, $me['email']]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'remove_invite') {
    $cid        = (int)($in['course_id'] ?? 0);
    $agentEmail = strtolower(trim($in['agent_email'] ?? ''));
    if (!$cid || !$agentEmail) { echo json_encode(['ok'=>false,'error'=>'missing fields']); exit; }
    $db->prepare("DELETE FROM uni_course_invites WHERE course_id=? AND LOWER(agent_email)=?")->execute([$cid, $agentEmail]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'search_agents') {
    $q = trim($in['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(['ok'=>true,'agents'=>[]]); exit; }
    $like = '%' . $q . '%';
    $rows = db_query("SELECT email, firstname, lastname FROM tblstaff WHERE (CONCAT(firstname,' ',lastname) LIKE ? OR email LIKE ?) AND active=1 LIMIT 15", [$like, $like]);
    $agents = array_map(fn($r) => ['email'=>strtolower($r['email']), 'name'=>trim($r['firstname'].' '.$r['lastname'])], $rows);
    echo json_encode(['ok'=>true,'agents'=>$agents]); exit;
}

// ── Lessons ───────────────────────────────────────────────────────────────
if ($action === 'list_lessons') {
    $courseId = (int)($in['course_id'] ?? 0);
    if (!$courseId) { http_response_code(400); echo json_encode(['error'=>'course_id required']); exit; }
    $s = $db->prepare("SELECT *, (SELECT COUNT(*) FROM uni_questions WHERE lesson_id=uni_lessons.id) as question_count,
                       (SELECT COUNT(*) FROM uni_lesson_files WHERE lesson_id=uni_lessons.id) as attachment_count,
                       (SELECT COUNT(*) FROM uni_feedback_questions WHERE lesson_id=uni_lessons.id) as feedback_question_count
                       FROM uni_lessons WHERE course_id=? ORDER BY sort_ord,id");
    $s->execute([$courseId]);
    echo json_encode(['ok'=>true,'lessons'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'create_lesson') {
    $courseId = (int)($in['course_id'] ?? 0);
    $title    = trim($in['title'] ?? '');
    if (!$courseId || !$title) { http_response_code(400); echo json_encode(['error'=>'course_id and title required']); exit; }
    $tags = array_values(array_filter(array_map('trim', (array)($in['tags'] ?? [])), 'strlen'));
    if (!$tags) { http_response_code(400); echo json_encode(['error'=>'at least one tag is required']); exit; }
    $learningObjective = trim($in['learning_objective'] ?? '');
    if (!$learningObjective) { http_response_code(400); echo json_encode(['error'=>'learning objective is required']); exit; }
    $difficulty = in_array($in['difficulty'] ?? '', ['beginner','intermediate','advanced']) ? $in['difficulty'] : 'beginner';
    $relatedLessons = array_values(array_unique(array_map('intval', (array)($in['related_lessons'] ?? []))));
    $type = in_array($in['type'] ?? '', ['video','doc','quiz','placeholder','upload','feedback']) ? $in['type'] : 'video';
    $folderId = !empty($in['folder_id']) ? (int)$in['folder_id'] : null;
    if ($folderId) {
        $mo = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM uni_lessons WHERE course_id=?"); $mo->execute([$courseId]);
        $nextOrd = ((int)$mo->fetchColumn()) + 10;
    } else {
        $nextOrd = next_toplevel_sort_ord($db, $courseId);
    }
    $db->prepare("INSERT INTO uni_lessons (course_id,folder_id,title,sort_ord,type,embed_url,content_html,duration_sec,tags,learning_objective,difficulty,related_lessons) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$courseId, $folderId, $title, $nextOrd, $type, trim($in['embed_url'] ?? ''), trim($in['content_html'] ?? ''), (int)($in['duration_sec'] ?? 0), json_encode($tags), $learningObjective, $difficulty, json_encode($relatedLessons)]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_lesson') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $tags = array_values(array_filter(array_map('trim', (array)($in['tags'] ?? [])), 'strlen'));
    if (!$tags) { http_response_code(400); echo json_encode(['error'=>'at least one tag is required']); exit; }
    $learningObjective = trim($in['learning_objective'] ?? '');
    if (!$learningObjective) { http_response_code(400); echo json_encode(['error'=>'learning objective is required']); exit; }
    $difficulty = in_array($in['difficulty'] ?? '', ['beginner','intermediate','advanced']) ? $in['difficulty'] : 'beginner';
    $relatedLessons = array_values(array_unique(array_map('intval', (array)($in['related_lessons'] ?? []))));
    $folderId = !empty($in['folder_id']) ? (int)$in['folder_id'] : null;
    // sort_ord is intentionally not settable here — it's owned by the create
    // actions and the reorder_lessons/reorder_toplevel drag-and-drop actions.
    // update_lesson used to overwrite it from $in['sort_ord'], but the editor
    // never sends that field, so every edit silently reset the lesson's
    // position to the front (undoing drag-and-drop order on unrelated saves).
    $db->prepare("UPDATE uni_lessons SET title=?,folder_id=?,embed_url=?,content_html=?,duration_sec=?,tags=?,learning_objective=?,difficulty=?,related_lessons=? WHERE id=?")
       ->execute([trim($in['title'] ?? ''), $folderId, trim($in['embed_url'] ?? ''), trim($in['content_html'] ?? ''), (int)($in['duration_sec'] ?? 0), json_encode($tags), $learningObjective, $difficulty, json_encode($relatedLessons), $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'search_lessons') {
    $q = trim($in['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode(['ok'=>true,'lessons'=>[]]); exit; }
    $excludeId = (int)($in['exclude_id'] ?? 0);
    $s = $db->prepare("SELECT l.id, l.title, c.title as course_title
                        FROM uni_lessons l LEFT JOIN uni_courses c ON c.id=l.course_id
                        WHERE l.title LIKE ? AND l.id != ?
                        ORDER BY l.title LIMIT 20");
    $s->execute(['%'.$q.'%', $excludeId]);
    echo json_encode(['ok'=>true,'lessons'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'lessons_by_ids') {
    $ids = array_values(array_unique(array_map('intval', (array)($in['ids'] ?? []))));
    if (!$ids) { echo json_encode(['ok'=>true,'lessons'=>[]]); exit; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $s = $db->prepare("SELECT id, title FROM uni_lessons WHERE id IN ($placeholders)");
    $s->execute($ids);
    echo json_encode(['ok'=>true,'lessons'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'publish_lesson') {
    // Flips a Fathom-ingested draft (or any pending_review lesson) live —
    // see lib/fathom.php / api/fathom_webhook.php for how it got here.
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $db->prepare("UPDATE uni_lessons SET pending_review=0 WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'move_lesson') {
    // Reassigns a lesson to a different course — mainly for triaging
    // Fathom-ingested recordings that all land in one holding course
    // (see lib/fathom.php) before someone sorts them into the right class.
    $id       = (int)($in['id'] ?? 0);
    $courseId = (int)($in['course_id'] ?? 0);
    if (!$id || !$courseId) { http_response_code(400); echo json_encode(['error'=>'id and course_id required']); exit; }
    $chk = $db->prepare("SELECT id FROM uni_courses WHERE id=?"); $chk->execute([$courseId]);
    if (!$chk->fetchColumn()) { http_response_code(400); echo json_encode(['error'=>'target course not found']); exit; }
    $nextOrd = next_toplevel_sort_ord($db, $courseId);
    $db->prepare("UPDATE uni_lessons SET course_id=?, folder_id=NULL, sort_ord=? WHERE id=?")
       ->execute([$courseId, $nextOrd, $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_lesson') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    tombstone_if_template_derived($db, 'uni_lessons', $id, 'lesson');
    $fkQ = $db->prepare("SELECT file_key FROM uni_lessons WHERE id=?"); $fkQ->execute([$id]);
    $fk  = $fkQ->fetchColumn();
    if ($fk && file_exists($uniDir . $fk)) @unlink($uniDir . $fk);
    $af = $db->prepare("SELECT file_key FROM uni_lesson_files WHERE lesson_id=?"); $af->execute([$id]);
    foreach ($af->fetchAll(PDO::FETCH_COLUMN) as $afk) { if (file_exists($uniDir . $afk)) @unlink($uniDir . $afk); }
    $uf = $db->prepare("SELECT file_key FROM uni_learner_uploads WHERE lesson_id=?"); $uf->execute([$id]);
    foreach ($uf->fetchAll(PDO::FETCH_COLUMN) as $ufk) { if (file_exists($uniDir . $ufk)) @unlink($uniDir . $ufk); }
    $db->prepare("DELETE FROM uni_lesson_files WHERE lesson_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_questions WHERE lesson_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_quiz_answers WHERE lesson_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_progress WHERE lesson_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_learner_uploads WHERE lesson_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_feedback_answers WHERE response_id IN (SELECT id FROM uni_feedback_responses WHERE lesson_id=?)")->execute([$id]);
    $db->prepare("DELETE FROM uni_feedback_responses WHERE lesson_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_feedback_questions WHERE lesson_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_lessons WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'reorder_lessons') {
    // order: array of lesson ids (legacy, flat) OR array of {id, folder_id} objects (folder-aware)
    $order = $in['order'] ?? [];
    if (!is_array($order)) { http_response_code(400); echo json_encode(['error'=>'order array required']); exit; }
    $upd = $db->prepare("UPDATE uni_lessons SET sort_ord=?,folder_id=? WHERE id=?");
    foreach ($order as $i => $item) {
        if (is_array($item)) {
            $lessonId = (int)($item['id'] ?? 0);
            $folderId = !empty($item['folder_id']) ? (int)$item['folder_id'] : null;
        } else {
            $lessonId = (int)$item;
            $folderId = null;
        }
        if ($lessonId) $upd->execute([($i + 1) * 10, $folderId, $lessonId]);
    }
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'reorder_toplevel') {
    // order: array of {type:'folder'|'lesson', id} — the full top-level
    // sequence (folders + unfoldered lessons) in the desired display order.
    // Assigns one shared, interleaved sort_ord across both tables so folders
    // and top-level lessons render in a single controllable sequence instead
    // of folders always coming first (see admin_university_course.php).
    $order = $in['order'] ?? [];
    if (!is_array($order)) { http_response_code(400); echo json_encode(['error'=>'order array required']); exit; }
    $updFolder = $db->prepare("UPDATE uni_folders SET sort_ord=? WHERE id=?");
    $updLesson = $db->prepare("UPDATE uni_lessons SET sort_ord=?, folder_id=NULL WHERE id=?");
    foreach ($order as $i => $item) {
        if (!is_array($item)) continue;
        $id = (int)($item['id'] ?? 0);
        if (!$id) continue;
        $ord = ($i + 1) * 10;
        if (($item['type'] ?? '') === 'folder') $updFolder->execute([$ord, $id]);
        else $updLesson->execute([$ord, $id]);
    }
    echo json_encode(['ok'=>true]); exit;
}

// ── Lesson attachments ─────────────────────────────────────────────────────
if ($action === 'list_lesson_attachments') {
    $lessonId = (int)($in['lesson_id'] ?? 0);
    if (!$lessonId) { http_response_code(400); echo json_encode(['error'=>'lesson_id required']); exit; }
    $s = $db->prepare("SELECT * FROM uni_lesson_files WHERE lesson_id=? ORDER BY sort_ord,id");
    $s->execute([$lessonId]);
    echo json_encode(['ok'=>true,'files'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'delete_lesson_attachment') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $fkQ = $db->prepare("SELECT file_key FROM uni_lesson_files WHERE id=?"); $fkQ->execute([$id]);
    $fk  = $fkQ->fetchColumn();
    if ($fk && file_exists($uniDir . $fk)) @unlink($uniDir . $fk);
    $db->prepare("DELETE FROM uni_lesson_files WHERE id=?")->execute([$id]);
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
    $qtype    = in_array($in['qtype'] ?? '', ['single','multiple','text']) ? $in['qtype'] : 'single';
    $correctIdx = array_values(array_map('intval', is_array($in['correct_indexes'] ?? null) ? $in['correct_indexes'] : [(int)($in['correct_index'] ?? 0)]));
    if (!$lessonId || !$question) { http_response_code(400); echo json_encode(['error'=>'lesson_id and question required']); exit; }
    if ($qtype !== 'text' && (!is_array($options) || count($options) < 2)) {
        http_response_code(400); echo json_encode(['error'=>'at least 2 options required']); exit;
    }
    if ($qtype === 'text') { $options = []; $correctIdx = []; }
    $mo = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM uni_questions WHERE lesson_id=?"); $mo->execute([$lessonId]);
    $nextOrd = ((int)$mo->fetchColumn()) + 10;
    $db->prepare("INSERT INTO uni_questions (lesson_id,question,options,correct_index,correct_indexes,qtype,sort_ord) VALUES (?,?,?,?,?,?,?)")
       ->execute([$lessonId, $question, json_encode(array_values($options)), $correctIdx[0] ?? 0, json_encode($correctIdx), $qtype, $nextOrd]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_question') {
    $id       = (int)($in['id'] ?? 0);
    $question = trim($in['question'] ?? '');
    $options  = $in['options'] ?? [];
    $qtype    = in_array($in['qtype'] ?? '', ['single','multiple','text']) ? $in['qtype'] : 'single';
    $correctIdx = array_values(array_map('intval', is_array($in['correct_indexes'] ?? null) ? $in['correct_indexes'] : [(int)($in['correct_index'] ?? 0)]));
    if (!$id || !$question) { http_response_code(400); echo json_encode(['error'=>'id and question required']); exit; }
    if ($qtype !== 'text' && (!is_array($options) || count($options) < 2)) {
        http_response_code(400); echo json_encode(['error'=>'at least 2 options required']); exit;
    }
    if ($qtype === 'text') { $options = []; $correctIdx = []; }
    $db->prepare("UPDATE uni_questions SET question=?,options=?,correct_index=?,correct_indexes=?,qtype=?,sort_ord=? WHERE id=?")
       ->execute([$question, json_encode(array_values($options)), $correctIdx[0] ?? 0, json_encode($correctIdx), $qtype, (int)($in['sort_ord'] ?? 0), $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_question') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    tombstone_if_template_derived($db, 'uni_questions', $id, 'question');
    $db->prepare("DELETE FROM uni_questions WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Feedback Questions ───────────────────────────────────────────────────────
// Deliberately separate from Quiz Questions above -- no correct_index/scoring
// semantics here, and a different config shape per qtype. Not template-aware
// (no tombstone call): Feedback lessons are blocked from "Save as Template"
// entirely -- see snapshot_course_as_template() in lib/uni_templates.php.
const UNI_FEEDBACK_QTYPES = ['rating_5', 'scale_10', 'short_text', 'long_text', 'date'];

if ($action === 'list_feedback_questions') {
    $lessonId = (int)($in['lesson_id'] ?? 0);
    if (!$lessonId) { http_response_code(400); echo json_encode(['error'=>'lesson_id required']); exit; }
    $s = $db->prepare("SELECT * FROM uni_feedback_questions WHERE lesson_id=? ORDER BY sort_ord,id");
    $s->execute([$lessonId]);
    echo json_encode(['ok'=>true,'questions'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'create_feedback_question') {
    $lessonId = (int)($in['lesson_id'] ?? 0);
    $question = trim($in['question'] ?? '');
    $qtype    = in_array($in['qtype'] ?? '', UNI_FEEDBACK_QTYPES, true) ? $in['qtype'] : null;
    if (!$lessonId || !$question || !$qtype) { http_response_code(400); echo json_encode(['error'=>'lesson_id, question, and a valid qtype are required']); exit; }
    $config = feedback_question_config_for_save($qtype, $in['config'] ?? []);
    $mo = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM uni_feedback_questions WHERE lesson_id=?"); $mo->execute([$lessonId]);
    $nextOrd = ((int)$mo->fetchColumn()) + 10;
    $db->prepare("INSERT INTO uni_feedback_questions (lesson_id,section_label,is_intro_field,qtype,question,config,sort_ord) VALUES (?,?,?,?,?,?,?)")
       ->execute([$lessonId, trim($in['section_label'] ?? ''), !empty($in['is_intro_field']) ? 1 : 0, $qtype, $question, json_encode($config), $nextOrd]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_feedback_question') {
    // sort_ord is preserve-if-absent, not defaulted to 0 -- a caller that only means to
    // change the question text/type (like the admin editor does) must not silently reset
    // this question's position. Same preserve-if-absent discipline as update_course.
    $id       = (int)($in['id'] ?? 0);
    $question = trim($in['question'] ?? '');
    $qtype    = in_array($in['qtype'] ?? '', UNI_FEEDBACK_QTYPES, true) ? $in['qtype'] : null;
    if (!$id || !$question || !$qtype) { http_response_code(400); echo json_encode(['error'=>'id, question, and a valid qtype are required']); exit; }
    $existing = $db->prepare("SELECT sort_ord FROM uni_feedback_questions WHERE id=?"); $existing->execute([$id]);
    $existingSortOrd = $existing->fetchColumn();
    if ($existingSortOrd === false) { http_response_code(404); echo json_encode(['error'=>'question not found']); exit; }
    $sortOrd = array_key_exists('sort_ord', $in) ? (int)$in['sort_ord'] : (int)$existingSortOrd;
    $config = feedback_question_config_for_save($qtype, $in['config'] ?? []);
    $db->prepare("UPDATE uni_feedback_questions SET section_label=?,is_intro_field=?,qtype=?,question=?,config=?,sort_ord=? WHERE id=?")
       ->execute([trim($in['section_label'] ?? ''), !empty($in['is_intro_field']) ? 1 : 0, $qtype, $question, json_encode($config), $sortOrd, $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_feedback_question') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $db->prepare("DELETE FROM uni_feedback_answers WHERE question_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_feedback_questions WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'reorder_feedback_questions') {
    $order = array_values(array_unique(array_map('intval', (array)($in['order'] ?? []))));
    $upd = $db->prepare("UPDATE uni_feedback_questions SET sort_ord=? WHERE id=?");
    foreach ($order as $i => $qid) { $upd->execute([($i + 1) * 10, $qid]); }
    echo json_encode(['ok'=>true]); exit;
}

// Whitelists the config JSON down to the keys that qtype actually uses, so an
// admin can't smuggle arbitrary fields in. Absent/malformed keys just fall
// back to "off"/blank rather than erroring -- the builder always sends a
// complete object, but this endpoint doesn't have to trust that it did.
function feedback_question_config_for_save(string $qtype, $rawConfig): array {
    $c = is_array($rawConfig) ? $rawConfig : [];
    if ($qtype === 'rating_5') {
        $labels = is_array($c['labels'] ?? null) ? $c['labels'] : [];
        $cleanLabels = [];
        for ($i = 1; $i <= 5; $i++) { $cleanLabels[(string)$i] = trim((string)($labels[(string)$i] ?? '')); }
        return [
            'labels'   => $cleanLabels,
            'allow_na' => !empty($c['allow_na']),
            'na_label' => trim((string)($c['na_label'] ?? '')) ?: 'N/A',
        ];
    }
    if ($qtype === 'scale_10') {
        return [
            'low_label'  => trim((string)($c['low_label'] ?? '')),
            'high_label' => trim((string)($c['high_label'] ?? '')),
        ];
    }
    $prefill = in_array($c['prefill'] ?? '', ['agent_name', 'agent_email'], true) ? $c['prefill'] : '';
    if ($qtype === 'date') { return ['prefill' => $prefill]; } // no placeholder -- not meaningful on a date input
    // short_text / long_text
    return ['prefill' => $prefill, 'placeholder' => trim((string)($c['placeholder'] ?? ''))];
}

// Tombstones a deliberately-deleted, template-derived folder/lesson/question
// so apply_template_update() never resurrects it — see diff_template_structure()
// in lib/uni_templates.php. No-op for anything not derived from a template.
function tombstone_if_template_derived(PDO $db, string $table, int $id, string $itemType): void {
    if ($itemType === 'folder') {
        $row = $db->prepare("SELECT course_id, template_item_id FROM uni_folders WHERE id=?");
    } elseif ($itemType === 'lesson') {
        $row = $db->prepare("SELECT course_id, template_item_id FROM uni_lessons WHERE id=?");
    } else {
        $row = $db->prepare("SELECT l.course_id as course_id, q.template_item_id as template_item_id FROM uni_questions q JOIN uni_lessons l ON l.id=q.lesson_id WHERE q.id=?");
    }
    $row->execute([$id]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    if (!$r || empty($r['template_item_id'])) return;
    $db->prepare("INSERT INTO uni_template_removed_items (course_id,template_item_id,item_type) VALUES (?,?,?)")
       ->execute([$r['course_id'], $r['template_item_id'], $itemType]);
}

// ── On-Demand Course Templates ─────────────────────────────────────────────
if ($action === 'list_templates') {
    // Read-only; needed by the "+ New Course" picker, which any is_admin()
    // user can reach — template *editing* is gated separately and more
    // tightly (can_edit_uni_templates()) in api/uni_template_action.php.
    $rows = $db->query("SELECT id,name,description,sequencing_mode,cert_enabled,cert_expiry_months FROM uni_templates WHERE archived=0 ORDER BY name")
               ->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'templates'=>$rows]); exit;
}
if ($action === 'create_course_from_template') {
    $templateId = (int)($in['template_id'] ?? 0);
    $title      = trim($in['title'] ?? '');
    if (!$templateId || !$title) { http_response_code(400); echo json_encode(['error'=>'template_id and title required']); exit; }
    $catId = !empty($in['category_id']) ? (int)$in['category_id'] : null;
    try {
        $courseId = instantiate_course_from_template($db, $templateId, $title, $catId, $me['email']);
        echo json_encode(['ok'=>true,'id'=>$courseId]); exit;
    } catch (\InvalidArgumentException $e) {
        http_response_code(400); echo json_encode(['error'=>$e->getMessage()]); exit;
    }
}
if ($action === 'preview_template_update') {
    $courseId = (int)($in['course_id'] ?? 0);
    if (!$courseId) { http_response_code(400); echo json_encode(['error'=>'course_id required']); exit; }
    try {
        echo json_encode(['ok'=>true] + preview_template_update($db, $courseId)); exit;
    } catch (\InvalidArgumentException $e) {
        http_response_code(400); echo json_encode(['error'=>$e->getMessage()]); exit;
    }
}
if ($action === 'apply_template_update') {
    // Only ever reachable after the admin has seen preview_template_update's
    // diff and confirmed it — the confirm dialog is enforced client-side by
    // only wiring this call to the modal's Apply button.
    $courseId = (int)($in['course_id'] ?? 0);
    if (!$courseId) { http_response_code(400); echo json_encode(['error'=>'course_id required']); exit; }
    try {
        echo json_encode(['ok'=>true] + apply_template_update($db, $courseId)); exit;
    } catch (\InvalidArgumentException $e) {
        http_response_code(400); echo json_encode(['error'=>$e->getMessage()]); exit;
    }
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
