<?php
ini_set("display_errors", 0);
// Admin CRUD for On-Demand Course Templates — mirrors api/uni_action.php's
// shape but gated more tightly (can_edit_uni_templates(), not is_admin()),
// since a bad template default affects every future course created from it.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../roles.php';
require_once __DIR__ . '/../lib/uni_templates.php';
header('Content-Type: application/json');

$me = current_agent();
if (!$me) { http_response_code(401); echo json_encode(['error'=>'not signed in']); exit; }
if (!can_edit_uni_templates()) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$db = local_db();
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';

// ── Templates ───────────────────────────────────────────────────────────
if ($action === 'list_templates') {
    $rows = $db->query(
        "SELECT t.*, (SELECT COUNT(*) FROM uni_courses WHERE template_id=t.id) as course_count
         FROM uni_templates t ORDER BY t.archived, t.name"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'templates'=>$rows]); exit;
}
if ($action === 'get_template') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $s = $db->prepare("SELECT * FROM uni_templates WHERE id=?"); $s->execute([$id]);
    $tpl = $s->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) { http_response_code(404); echo json_encode(['error'=>'not found']); exit; }
    echo json_encode(['ok'=>true,'template'=>$tpl]); exit;
}
if ($action === 'create_template') {
    $name = trim($in['name'] ?? '');
    if (!$name) { http_response_code(400); echo json_encode(['error'=>'name required']); exit; }
    $db->prepare(
        "INSERT INTO uni_templates (name,description,created_by) VALUES (?,?,?)"
    )->execute([$name, trim($in['description'] ?? ''), $me['email']]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_template') {
    $id = (int)($in['id'] ?? 0);
    $name = trim($in['name'] ?? '');
    if (!$id || !$name) { http_response_code(400); echo json_encode(['error'=>'id and name required']); exit; }
    $sequencingMode = in_array($in['sequencing_mode'] ?? '', ['free','in_order'], true) ? $in['sequencing_mode'] : 'free';
    $retakePolicy   = in_array($in['quiz_retake_policy'] ?? '', ['unlimited','limited'], true) ? $in['quiz_retake_policy'] : 'unlimited';
    $db->prepare(
        "UPDATE uni_templates SET name=?,description=?,sequencing_mode=?,quiz_pass_score=?,quiz_retake_policy=?,quiz_max_attempts=?,
         quiz_block_on_fail=?,quiz_question_count_hint=?,cert_enabled=?,cert_expiry_months=?,cert_design=?,layout_style=?,
         overview_audience=?,overview_outcome=?,overview_estimated_minutes=?,updated_at=datetime('now') WHERE id=?"
    )->execute([
        $name, trim($in['description'] ?? ''), $sequencingMode, (int)($in['quiz_pass_score'] ?? 70), $retakePolicy,
        (int)($in['quiz_max_attempts'] ?? 0), (int)($in['quiz_block_on_fail'] ?? 0), (int)($in['quiz_question_count_hint'] ?? 0),
        (int)($in['cert_enabled'] ?? 1), (int)($in['cert_expiry_months'] ?? 0), trim($in['cert_design'] ?? 'default'), trim($in['layout_style'] ?? 'standard'),
        trim($in['overview_audience'] ?? ''), trim($in['overview_outcome'] ?? ''), (int)($in['overview_estimated_minutes'] ?? 0), $id,
    ]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'archive_template') {
    // Archive, not hard-delete — courses reference template_id for provenance.
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $db->prepare("UPDATE uni_templates SET archived=? WHERE id=?")->execute([(int)($in['archived'] ?? 1), $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'snapshot_course_as_template') {
    $courseId = (int)($in['course_id'] ?? 0);
    $name = trim($in['name'] ?? '');
    if (!$courseId || !$name) { http_response_code(400); echo json_encode(['error'=>'course_id and name required']); exit; }
    try {
        $templateId = snapshot_course_as_template($db, $courseId, $name, trim($in['description'] ?? ''), $me['email']);
        echo json_encode(['ok'=>true,'id'=>$templateId]); exit;
    } catch (\InvalidArgumentException $e) {
        http_response_code(400); echo json_encode(['error'=>$e->getMessage()]); exit;
    }
}

// ── Template folders ────────────────────────────────────────────────────
if ($action === 'list_template_folders') {
    $templateId = (int)($in['template_id'] ?? 0);
    if (!$templateId) { http_response_code(400); echo json_encode(['error'=>'template_id required']); exit; }
    $s = $db->prepare("SELECT * FROM uni_template_folders WHERE template_id=? ORDER BY sort_ord,id");
    $s->execute([$templateId]);
    echo json_encode(['ok'=>true,'folders'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'create_template_folder') {
    $templateId = (int)($in['template_id'] ?? 0);
    $title = trim($in['title'] ?? '');
    if (!$templateId || !$title) { http_response_code(400); echo json_encode(['error'=>'template_id and title required']); exit; }
    $mo = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM uni_template_folders WHERE template_id=?"); $mo->execute([$templateId]);
    $nextOrd = ((int)$mo->fetchColumn()) + 10;
    $db->prepare("INSERT INTO uni_template_folders (template_id,title,code,description,sort_ord) VALUES (?,?,?,?,?)")
       ->execute([$templateId, $title, trim($in['code'] ?? ''), trim($in['description'] ?? ''), $nextOrd]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_template_folder') {
    $id = (int)($in['id'] ?? 0);
    $title = trim($in['title'] ?? '');
    if (!$id || !$title) { http_response_code(400); echo json_encode(['error'=>'id and title required']); exit; }
    $db->prepare("UPDATE uni_template_folders SET title=?,code=?,description=? WHERE id=?")
       ->execute([$title, trim($in['code'] ?? ''), trim($in['description'] ?? ''), $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_template_folder') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $db->prepare("UPDATE uni_template_lessons SET folder_id=NULL WHERE folder_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_template_folders WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'reorder_template_folders') {
    $order = $in['order'] ?? [];
    if (!is_array($order)) { http_response_code(400); echo json_encode(['error'=>'order array required']); exit; }
    $upd = $db->prepare("UPDATE uni_template_folders SET sort_ord=? WHERE id=?");
    foreach ($order as $i => $id) $upd->execute([($i + 1) * 10, (int)$id]);
    echo json_encode(['ok'=>true]); exit;
}

// ── Template lessons ────────────────────────────────────────────────────
if ($action === 'list_template_lessons') {
    $templateId = (int)($in['template_id'] ?? 0);
    if (!$templateId) { http_response_code(400); echo json_encode(['error'=>'template_id required']); exit; }
    $s = $db->prepare(
        "SELECT *, (SELECT COUNT(*) FROM uni_template_questions WHERE template_lesson_id=uni_template_lessons.id) as question_count
         FROM uni_template_lessons WHERE template_id=? ORDER BY sort_ord,id"
    );
    $s->execute([$templateId]);
    echo json_encode(['ok'=>true,'lessons'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'create_template_lesson') {
    $templateId = (int)($in['template_id'] ?? 0);
    $title = trim($in['title'] ?? '');
    if (!$templateId || !$title) { http_response_code(400); echo json_encode(['error'=>'template_id and title required']); exit; }
    $type = in_array($in['type'] ?? '', ['video','doc','quiz','placeholder','upload'], true) ? $in['type'] : 'video';
    $sectionKind = in_array($in['section_kind'] ?? '', ['video','doc','quiz','complete'], true) ? $in['section_kind'] : null;
    $folderId = !empty($in['folder_id']) ? (int)$in['folder_id'] : null;
    $mo = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM uni_template_lessons WHERE template_id=?"); $mo->execute([$templateId]);
    $nextOrd = ((int)$mo->fetchColumn()) + 10;
    $db->prepare(
        "INSERT INTO uni_template_lessons (template_id,folder_id,title,sort_ord,type,section_kind,embed_url,content_html,duration_sec,tags,learning_objective,difficulty)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([$templateId, $folderId, $title, $nextOrd, $type, $sectionKind, trim($in['embed_url'] ?? ''), trim($in['content_html'] ?? ''),
                (int)($in['duration_sec'] ?? 0), json_encode((array)($in['tags'] ?? [])), trim($in['learning_objective'] ?? ''),
                in_array($in['difficulty'] ?? '', ['beginner','intermediate','advanced'], true) ? $in['difficulty'] : 'beginner']);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_template_lesson') {
    $id = (int)($in['id'] ?? 0);
    $title = trim($in['title'] ?? '');
    if (!$id || !$title) { http_response_code(400); echo json_encode(['error'=>'id and title required']); exit; }
    $type = in_array($in['type'] ?? '', ['video','doc','quiz','placeholder','upload'], true) ? $in['type'] : 'video';
    $sectionKind = in_array($in['section_kind'] ?? '', ['video','doc','quiz','complete'], true) ? $in['section_kind'] : null;
    $folderId = !empty($in['folder_id']) ? (int)$in['folder_id'] : null;
    $db->prepare(
        "UPDATE uni_template_lessons SET title=?,folder_id=?,type=?,section_kind=?,embed_url=?,content_html=?,duration_sec=?,tags=?,learning_objective=?,difficulty=? WHERE id=?"
    )->execute([$title, $folderId, $type, $sectionKind, trim($in['embed_url'] ?? ''), trim($in['content_html'] ?? ''),
                (int)($in['duration_sec'] ?? 0), json_encode((array)($in['tags'] ?? [])), trim($in['learning_objective'] ?? ''),
                in_array($in['difficulty'] ?? '', ['beginner','intermediate','advanced'], true) ? $in['difficulty'] : 'beginner', $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_template_lesson') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $db->prepare("DELETE FROM uni_template_questions WHERE template_lesson_id=?")->execute([$id]);
    $db->prepare("DELETE FROM uni_template_lessons WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'reorder_template_lessons') {
    $order = $in['order'] ?? [];
    if (!is_array($order)) { http_response_code(400); echo json_encode(['error'=>'order array required']); exit; }
    $upd = $db->prepare("UPDATE uni_template_lessons SET sort_ord=?,folder_id=? WHERE id=?");
    foreach ($order as $i => $item) {
        $lessonId = (int)($item['id'] ?? 0);
        $folderId = !empty($item['folder_id']) ? (int)$item['folder_id'] : null;
        if ($lessonId) $upd->execute([($i + 1) * 10, $folderId, $lessonId]);
    }
    echo json_encode(['ok'=>true]); exit;
}

// ── Template questions ──────────────────────────────────────────────────
if ($action === 'list_template_questions') {
    $lessonId = (int)($in['template_lesson_id'] ?? 0);
    if (!$lessonId) { http_response_code(400); echo json_encode(['error'=>'template_lesson_id required']); exit; }
    $s = $db->prepare("SELECT * FROM uni_template_questions WHERE template_lesson_id=? ORDER BY sort_ord,id");
    $s->execute([$lessonId]);
    echo json_encode(['ok'=>true,'questions'=>$s->fetchAll(PDO::FETCH_ASSOC)]); exit;
}
if ($action === 'create_template_question') {
    $lessonId = (int)($in['template_lesson_id'] ?? 0);
    $question = trim($in['question'] ?? '');
    $options  = $in['options'] ?? [];
    $qtype    = in_array($in['qtype'] ?? '', ['single','multiple','text'], true) ? $in['qtype'] : 'single';
    $correctIdx = array_values(array_map('intval', is_array($in['correct_indexes'] ?? null) ? $in['correct_indexes'] : [(int)($in['correct_index'] ?? 0)]));
    if (!$lessonId || !$question) { http_response_code(400); echo json_encode(['error'=>'template_lesson_id and question required']); exit; }
    if ($qtype !== 'text' && (!is_array($options) || count($options) < 2)) {
        http_response_code(400); echo json_encode(['error'=>'at least 2 options required']); exit;
    }
    if ($qtype === 'text') { $options = []; $correctIdx = []; }
    $mo = $db->prepare("SELECT COALESCE(MAX(sort_ord),0) FROM uni_template_questions WHERE template_lesson_id=?"); $mo->execute([$lessonId]);
    $nextOrd = ((int)$mo->fetchColumn()) + 10;
    $db->prepare(
        "INSERT INTO uni_template_questions (template_lesson_id,question,options,correct_index,correct_indexes,qtype,sort_ord) VALUES (?,?,?,?,?,?,?)"
    )->execute([$lessonId, $question, json_encode(array_values($options)), $correctIdx[0] ?? 0, json_encode($correctIdx), $qtype, $nextOrd]);
    echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]); exit;
}
if ($action === 'update_template_question') {
    $id = (int)($in['id'] ?? 0);
    $question = trim($in['question'] ?? '');
    $options  = $in['options'] ?? [];
    $qtype    = in_array($in['qtype'] ?? '', ['single','multiple','text'], true) ? $in['qtype'] : 'single';
    $correctIdx = array_values(array_map('intval', is_array($in['correct_indexes'] ?? null) ? $in['correct_indexes'] : [(int)($in['correct_index'] ?? 0)]));
    if (!$id || !$question) { http_response_code(400); echo json_encode(['error'=>'id and question required']); exit; }
    if ($qtype !== 'text' && (!is_array($options) || count($options) < 2)) {
        http_response_code(400); echo json_encode(['error'=>'at least 2 options required']); exit;
    }
    if ($qtype === 'text') { $options = []; $correctIdx = []; }
    $db->prepare(
        "UPDATE uni_template_questions SET question=?,options=?,correct_index=?,correct_indexes=?,qtype=? WHERE id=?"
    )->execute([$question, json_encode(array_values($options)), $correctIdx[0] ?? 0, json_encode($correctIdx), $qtype, $id]);
    echo json_encode(['ok'=>true]); exit;
}
if ($action === 'delete_template_question') {
    $id = (int)($in['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error'=>'id required']); exit; }
    $db->prepare("DELETE FROM uni_template_questions WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]); exit;
}

http_response_code(400);
echo json_encode(['error'=>'unknown action']);
